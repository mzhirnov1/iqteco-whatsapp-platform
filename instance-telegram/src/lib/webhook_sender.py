import asyncio
import datetime as dt
import hashlib
import hmac
import json
import logging
from typing import Any, Callable, Optional

import httpx


BACKOFF_SECONDS = [
    1, 5, 30, 120, 600,
    1800, 3600,
    3600, 3600, 3600, 3600,
    7200, 7200, 7200,
    14400, 14400,
]
MAX_ATTEMPTS = len(BACKOFF_SECONDS)


def _utcnow() -> dt.datetime:
    return dt.datetime.now(dt.timezone.utc)


class WebhookSender:
    """Mirror of instance/src/lib/WebhookSender.js — same outbox schema, same retry, same HMAC."""

    def __init__(
        self,
        db: Any,
        id_instance: str,
        get_webhook_url: Callable[[], Optional[str]],
        get_webhook_secret: Callable[[], Optional[str]],
        logger: Optional[logging.Logger] = None,
        tick_interval_sec: float = 1.0,
    ) -> None:
        self.outbox = db["webhook_outbox"]
        self.log_coll = db["webhook_log"]
        self.id_instance = str(id_instance)
        self.get_webhook_url = get_webhook_url
        self.get_webhook_secret = get_webhook_secret
        self.logger = logger or logging.getLogger("webhook")
        self.tick_interval = tick_interval_sec
        self._task: Optional[asyncio.Task] = None
        self._stop = asyncio.Event()

    async def start(self) -> None:
        try:
            self.outbox.create_index([("idInstance", 1), ("status", 1), ("nextAttemptAt", 1)])
        except Exception:
            pass
        self._stop.clear()
        self._task = asyncio.create_task(self._loop())

    async def stop(self) -> None:
        self._stop.set()
        if self._task:
            try:
                await asyncio.wait_for(self._task, timeout=5)
            except (asyncio.TimeoutError, asyncio.CancelledError):
                self._task.cancel()

    async def enqueue(self, type_webhook: str, payload: dict) -> Any:
        doc = {
            "idInstance": self.id_instance,
            "typeWebhook": type_webhook,
            "payload": payload,
            "status": "pending",
            "attempts": 0,
            "nextAttemptAt": _utcnow(),
            "createdAt": _utcnow(),
        }
        res = self.outbox.insert_one(doc)
        return res.inserted_id

    async def queue_stats(self) -> dict:
        loop = asyncio.get_running_loop()

        def _sync_stats() -> dict:
            pending = self.outbox.count_documents({"idInstance": self.id_instance, "status": "pending"})
            failed = self.outbox.count_documents({"idInstance": self.id_instance, "status": "failed"})
            last_sent = self.outbox.find_one(
                {"idInstance": self.id_instance, "status": "sent"},
                sort=[("sentAt", -1)],
                projection={"sentAt": 1},
            )
            last_at = None
            if last_sent and last_sent.get("sentAt"):
                last_at = int(last_sent["sentAt"].timestamp())
            return {
                "pending": pending,
                "failed": failed,
                "lastSentAt": last_at,
                "maxAttempts": MAX_ATTEMPTS,
            }
        return await loop.run_in_executor(None, _sync_stats)

    async def _loop(self) -> None:
        while not self._stop.is_set():
            try:
                await self._tick()
            except Exception as e:
                self.logger.error("webhook tick error: %s", e)
            try:
                await asyncio.wait_for(self._stop.wait(), timeout=self.tick_interval)
            except asyncio.TimeoutError:
                pass

    async def _tick(self) -> None:
        now = _utcnow()
        items = list(self.outbox.find({
            "idInstance": self.id_instance,
            "status": "pending",
            "nextAttemptAt": {"$lte": now},
        }).limit(10))
        for item in items:
            await self._send(item)

    async def _send(self, item: dict) -> None:
        url = self.get_webhook_url()
        if not url:
            self.outbox.update_one({"_id": item["_id"]},
                                   {"$set": {"status": "skipped", "updatedAt": _utcnow()}})
            return

        body = json.dumps(item["payload"], ensure_ascii=False, separators=(",", ":"))
        headers = {"Content-Type": "application/json"}
        secret = self.get_webhook_secret()
        if secret:
            sig = hmac.new(secret.encode("utf-8"), body.encode("utf-8"), hashlib.sha256).hexdigest()
            headers["X-Webhook-Signature"] = f"sha256={sig}"

        try:
            async with httpx.AsyncClient(timeout=10.0) as client:
                resp = await client.post(url, content=body, headers=headers)
            if 200 <= resp.status_code < 300:
                self.outbox.update_one({"_id": item["_id"]},
                                       {"$set": {"status": "sent", "sentAt": _utcnow(),
                                                 "httpCode": resp.status_code}})
                try:
                    self.log_coll.insert_one({
                        "idInstance": self.id_instance,
                        "type": item["typeWebhook"],
                        "payload": item["payload"],
                        "sentAt": _utcnow(),
                        "status": "sent",
                        "httpCode": resp.status_code,
                        "attempts": item["attempts"] + 1,
                    })
                except Exception:
                    pass
                return
            raise RuntimeError(f"HTTP {resp.status_code}: {resp.text[:200]}")
        except Exception as e:
            attempts = (item.get("attempts") or 0) + 1
            if attempts >= MAX_ATTEMPTS:
                self.outbox.update_one({"_id": item["_id"]},
                                       {"$set": {"status": "failed", "attempts": attempts,
                                                 "lastError": str(e), "updatedAt": _utcnow()}})
                try:
                    self.log_coll.insert_one({
                        "idInstance": self.id_instance,
                        "type": item["typeWebhook"],
                        "payload": item["payload"],
                        "sentAt": _utcnow(),
                        "status": "failed",
                        "attempts": attempts,
                        "error": str(e),
                    })
                except Exception:
                    pass
                self.logger.error("webhook gave up id=%s type=%s err=%s", item["_id"], item["typeWebhook"], e)
            else:
                delay = BACKOFF_SECONDS[attempts - 1] if attempts - 1 < len(BACKOFF_SECONDS) else 600
                self.outbox.update_one({"_id": item["_id"]},
                                       {"$set": {"attempts": attempts,
                                                 "nextAttemptAt": _utcnow() + dt.timedelta(seconds=delay),
                                                 "lastError": str(e), "updatedAt": _utcnow()}})
                self.logger.warning("webhook retry scheduled id=%s attempts=%d delay=%ds err=%s",
                                    item["_id"], attempts, delay, e)
