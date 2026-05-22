import asyncio
import logging

from fastapi import FastAPI
from pymongo import MongoClient

from .config import Config
from .client import build_client
from .lib.admin_client import AdminClient
from .lib.webhook_sender import WebhookSender
from .lib.green_mapper import GreenApiMapper
from .lib.media_store import MediaStore
from .lib.message_store import MessageStore
from .runtime import Runtime
from .routes import mount_routes

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(name)s %(message)s",
)
log = logging.getLogger("tg-instance")


app = FastAPI(title="tg-instance", version="0.1.0")


@app.get("/health")
async def health():
    return {"ok": True}


@app.on_event("startup")
async def startup() -> None:
    cfg = Config.from_env()
    log.setLevel(getattr(logging, cfg.log_level.upper(), logging.INFO))
    log.info("tg-instance starting id=%s ipv6=%s", cfg.id_instance, cfg.ipv6_addr)

    mongo = MongoClient(cfg.mongo_url, serverSelectionTimeoutMS=10000)
    db = mongo[cfg.mongo_db]
    db.command("ping")

    admin_client = AdminClient(cfg.admin_url, cfg.admin_token, cfg.id_instance)
    admin_config = await admin_client.get_config()

    webhook_url = (admin_config or {}).get("webhookUrl") or cfg.webhook_url
    webhook_secret = (admin_config or {}).get("webhookSecret") or None
    settings = (admin_config or {}).get("settings") or {}

    media_store = MediaStore(cfg, log.getChild("media"))
    message_store = MessageStore(db, cfg.id_instance)
    message_store.ensure_indexes()

    webhook_sender = WebhookSender(
        db=db,
        id_instance=cfg.id_instance,
        get_webhook_url=lambda: webhook_url,
        get_webhook_secret=lambda: webhook_secret,
        logger=log.getChild("webhook"),
    )
    await webhook_sender.start()

    mapper = GreenApiMapper(
        id_instance=cfg.id_instance,
        api_token=cfg.api_token,
        media_base_url=cfg.media_base_url,
    )

    runtime = Runtime(
        cfg=cfg,
        log=log,
        db=db,
        admin_client=admin_client,
        admin_config={
            "webhookUrl": webhook_url,
            "webhookSecret": webhook_secret,
            "settings": settings,
        },
        webhook_sender=webhook_sender,
        mapper=mapper,
        media_store=media_store,
        message_store=message_store,
    )
    app.state.runtime = runtime

    # Telethon client
    client = await build_client(runtime)
    runtime.client = client

    # Mount Green-API routes
    mount_routes(app, runtime)

    # Start client in background — auth flow + event loop
    runtime.client_task = asyncio.create_task(runtime.run_client_loop())


@app.on_event("shutdown")
async def shutdown() -> None:
    runtime: Runtime = app.state.runtime
    log.info("tg-instance shutdown")
    if runtime.webhook_sender:
        await runtime.webhook_sender.stop()
    if runtime.client and runtime.client.is_connected():
        await runtime.client.disconnect()
    if runtime.client_task:
        runtime.client_task.cancel()
