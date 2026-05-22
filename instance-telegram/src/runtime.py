import asyncio
import logging
from dataclasses import dataclass, field
from typing import Any, Optional, Set

from telethon import TelegramClient

from .config import Config


@dataclass
class State:
    authorized: bool = False
    last_state: str = "starting"
    wid: Optional[str] = None  # like "{user_id}@c.us" once authorized


@dataclass
class QrCache:
    qr_url: Optional[str] = None
    png_base64: Optional[str] = None
    expires_at: float = 0.0


@dataclass
class CodeCache:
    code: Optional[str] = None
    expires_at: float = 0.0
    phone_code_hash: Optional[str] = None
    phone: Optional[str] = None


@dataclass
class Runtime:
    cfg: Config
    log: logging.Logger
    db: Any
    admin_client: Any
    admin_config: dict
    webhook_sender: Any
    mapper: Any
    media_store: Any
    message_store: Any

    client: Optional[TelegramClient] = None
    client_task: Optional[asyncio.Task] = None

    state: State = field(default_factory=State)
    qr_cache: QrCache = field(default_factory=QrCache)
    code_cache: CodeCache = field(default_factory=CodeCache)
    outgoing_api_ids: Set[str] = field(default_factory=set)

    async def run_client_loop(self) -> None:
        from .events.auth_flow import run_auth_flow
        try:
            await run_auth_flow(self)
        except asyncio.CancelledError:
            raise
        except Exception as e:
            self.log.exception("client loop crashed: %s", e)
