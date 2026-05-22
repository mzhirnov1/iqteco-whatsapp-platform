import logging
from typing import Any

from fastapi import FastAPI, Request, Body, HTTPException

from ..runtime import Runtime
from ..lib.green_mapper import chat_id_to_tg_id, tg_id_to_chat_id
from ._common import get_runtime, check_auth, require_authorized


log = logging.getLogger("chats")


def _classify_entity(entity: Any) -> tuple[bool, str, str]:
    """Return (is_group, name, kind) for a Telethon entity."""
    cls = type(entity).__name__
    is_group = cls in ("Chat", "ChatForbidden", "Channel", "ChannelForbidden")
    name = ""
    if hasattr(entity, "title") and getattr(entity, "title"):
        name = entity.title
    else:
        first = getattr(entity, "first_name", "") or ""
        last = getattr(entity, "last_name", "") or ""
        name = (first + " " + last).strip() or getattr(entity, "username", "") or ""
    kind = "group" if is_group else "user"
    return is_group, name, kind


def register(app: FastAPI, rt: Runtime) -> None:
    @app.get("/waInstance{id_instance}/getChats/{token}")
    async def get_chats(id_instance: str, token: str, request: Request):
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        require_authorized(r)
        results = []
        async for dialog in r.client.iter_dialogs(limit=200):
            ent = dialog.entity
            is_group, name, _ = _classify_entity(ent)
            chat_id = tg_id_to_chat_id(ent, is_group=is_group)
            results.append({
                "id": chat_id,
                "name": name,
                "type": "group" if is_group else "user",
                "archive": bool(dialog.archived),
                "lastMessageDate": int(dialog.date.timestamp()) if dialog.date else 0,
                "unreadCount": dialog.unread_count or 0,
            })
        return results

    @app.post("/waInstance{id_instance}/getChatHistory/{token}")
    async def get_chat_history(id_instance: str, token: str, request: Request,
                                 payload: dict = Body(default={})):
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        require_authorized(r)
        chat_id = (payload or {}).get("chatId") or ""
        count = int((payload or {}).get("count") or 100)
        tg_id, is_group = chat_id_to_tg_id(chat_id)
        try:
            entity = await r.client.get_entity(tg_id)
        except Exception as e:
            raise HTTPException(status_code=400, detail={"error": "entity_not_found", "chatId": chat_id, "msg": str(e)})

        results = []
        async for msg in r.client.iter_messages(entity, limit=count):
            sender_id = getattr(msg.sender_id, "user_id", msg.sender_id) if msg.sender_id else None
            sender = tg_id_to_chat_id(sender_id, is_group=False) if sender_id else ""
            from_me = bool(msg.out)
            text = msg.message or ""
            type_message = "textMessage"
            if msg.photo is not None:
                type_message = "imageMessage"
            elif msg.document is not None:
                type_message = "documentMessage"
            results.append({
                "idMessage": str(msg.id),
                "timestamp": int(msg.date.timestamp()) if msg.date else 0,
                "typeMessage": type_message,
                "type": "outgoing" if from_me else "incoming",
                "chatId": chat_id,
                "senderId": sender,
                "textMessage": text,
            })
        return results

    @app.get("/waInstance{id_instance}/lastIncomingMessages/{token}")
    async def last_incoming(id_instance: str, token: str, request: Request, minutes: int = 1440):
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        return _last_messages(r, minutes=minutes, from_me=False)

    @app.get("/waInstance{id_instance}/lastOutgoingMessages/{token}")
    async def last_outgoing(id_instance: str, token: str, request: Request, minutes: int = 1440):
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        return _last_messages(r, minutes=minutes, from_me=True)


def _last_messages(r: Runtime, *, minutes: int, from_me: bool) -> list[dict]:
    import time
    cutoff = int(time.time()) - max(1, minutes) * 60
    msgs = list(r.message_store.collection.find({
        "fromMe": from_me,
        "timestamp": {"$gte": cutoff},
    }).sort("timestamp", -1).limit(200))
    out = []
    for m in msgs:
        out.append({
            "idMessage": m.get("idMessage"),
            "timestamp": m.get("timestamp"),
            "typeMessage": m.get("typeMessage"),
            "chatId": m.get("chatId"),
            "senderId": m.get("sender"),
            "textMessage": m.get("body", ""),
        })
    return out
