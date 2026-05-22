from telethon import TelegramClient

from .lib.mongo_session import MongoSession
from .runtime import Runtime


async def build_client(rt: Runtime) -> TelegramClient:
    session = MongoSession(
        db=rt.db,
        id_instance=rt.cfg.id_instance,
        logger=rt.log.getChild("session"),
    )
    session.load()  # restore session-file from GridFS if exists
    client = TelegramClient(
        session=session,
        api_id=rt.cfg.tg_api_id,
        api_hash=rt.cfg.tg_api_hash,
        device_model="iqteco-whatsapp-platform",
        system_version="1.0",
        app_version="0.1.0",
        lang_code="en",
        system_lang_code="en",
        # No proxy by default; can be added via env
    )
    return client
