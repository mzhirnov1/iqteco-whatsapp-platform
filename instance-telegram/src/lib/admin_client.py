import logging
from typing import Any, Optional

import httpx


class AdminClient:
    def __init__(self, base_url: str, admin_token: str, id_instance: str,
                 logger: Optional[logging.Logger] = None) -> None:
        self.base_url = base_url.rstrip("/")
        self.token = admin_token
        self.id_instance = id_instance
        self.log = logger or logging.getLogger("admin-client")

    async def _req(self, method: str, path: str, json: Optional[dict] = None) -> Optional[dict]:
        url = f"{self.base_url}{path}"
        headers = {"Authorization": f"Bearer {self.token}"}
        try:
            async with httpx.AsyncClient(timeout=10.0) as client:
                resp = await client.request(method, url, headers=headers, json=json)
                if resp.status_code >= 400:
                    self.log.warning("admin %s %s -> %d %s", method, path, resp.status_code, resp.text[:200])
                    return None
                if resp.headers.get("content-type", "").startswith("application/json"):
                    return resp.json()
                return {"ok": True}
        except Exception as e:
            self.log.warning("admin %s %s failed: %s", method, path, e)
            return None

    async def get_config(self) -> Optional[dict]:
        return await self._req("GET", f"/instances/{self.id_instance}/config")

    async def send_qr(self, qr: str, expires_at: int, kind: str) -> None:
        await self._req("POST", f"/instances/{self.id_instance}/qr",
                        {"qr": qr, "expiresAt": expires_at, "kind": kind})

    async def heartbeat(self, state: str, wid: Optional[str] = None,
                        phone_number: Optional[str] = None) -> None:
        payload: dict[str, Any] = {"state": state}
        if wid is not None:
            payload["wid"] = wid
        if phone_number is not None:
            payload["phoneNumber"] = phone_number
        await self._req("POST", f"/instances/{self.id_instance}/heartbeat", payload)

    async def state_change(self, from_: str, to: str, reason: Optional[str] = None) -> None:
        await self._req("POST", f"/instances/{self.id_instance}/state-change",
                        {"from": from_, "to": to, "reason": reason})
