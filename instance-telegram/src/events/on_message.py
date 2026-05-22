import logging
import mimetypes
import os
import tempfile
import time
from typing import Optional

from telethon import events
from telethon.tl.types import (
    Message,
    PeerChannel,
    PeerChat,
    PeerUser,
    MessageMediaPhoto,
    MessageMediaDocument,
    MessageMediaGeo,
    MessageMediaContact,
)

from ..runtime import Runtime
from ..lib.green_mapper import tg_id_to_chat_id


log = logging.getLogger("on_message")


def register_message_handlers(rt: Runtime) -> None:
    client = rt.client

    @client.on(events.NewMessage(incoming=True))
    async def _incoming(event):
        await _emit_message(rt, event.message, is_outgoing=False, is_api=False)

    @client.on(events.NewMessage(outgoing=True))
    async def _outgoing(event):
        idm = str(event.message.id)
        is_api = idm in rt.outgoing_api_ids
        if is_api:
            rt.outgoing_api_ids.discard(idm)
        await _emit_message(rt, event.message, is_outgoing=True, is_api=is_api)

    @client.on(events.MessageEdited())
    async def _edited(event):
        try:
            payload = await _build_payload(rt, event.message, kind="edit")
            if payload:
                await rt.webhook_sender.enqueue("editedMessageReceived", payload)
        except Exception as e:
            log.warning("on edited handler failed: %s", e)

    @client.on(events.MessageDeleted())
    async def _deleted(event):
        # Telethon doesn't always have message content here — we know IDs only.
        for mid in event.deleted_ids or []:
            try:
                cached = rt.message_store.find_by_id(str(mid))
                if not cached:
                    continue
                payload = rt.mapper.to_deleted_message(
                    msg_id=str(mid),
                    chat_id=cached.get("chatId") or "",
                    sender=cached.get("sender") or "",
                )
                await rt.webhook_sender.enqueue("deletedMessageReceived", payload)
            except Exception as e:
                log.warning("on deleted handler failed mid=%s err=%s", mid, e)


async def _emit_message(rt: Runtime, msg: Message, *, is_outgoing: bool, is_api: bool) -> None:
    try:
        payload = await _build_payload(rt, msg, kind="incoming" if not is_outgoing else "outgoing")
        if not payload:
            return
        if is_outgoing:
            type_webhook = "outgoingAPIMessageReceived" if is_api else "outgoingMessageReceived"
            payload["typeWebhook"] = type_webhook
            await rt.webhook_sender.enqueue(type_webhook, payload)
        else:
            await rt.webhook_sender.enqueue("incomingMessageReceived", payload)
    except Exception as e:
        log.exception("emit failed: %s", e)


async def _build_payload(rt: Runtime, msg: Message, *, kind: str) -> Optional[dict]:
    chat = await msg.get_chat()
    is_group = isinstance(msg.peer_id, (PeerChat, PeerChannel))
    chat_id = tg_id_to_chat_id(chat, is_group=is_group)

    sender_entity = None
    sender_id = msg.sender_id
    if sender_id is not None:
        try:
            sender_entity = await msg.get_sender()
        except Exception:
            sender_entity = None
    sender_str = tg_id_to_chat_id(sender_id, is_group=False) if sender_id else (rt.state.wid or "")

    sender_name = ""
    if sender_entity is not None:
        first = getattr(sender_entity, "first_name", "") or ""
        last = getattr(sender_entity, "last_name", "") or ""
        sender_name = (first + " " + last).strip() or (getattr(sender_entity, "username", "") or "")

    chat_name = ""
    if hasattr(chat, "title") and chat.title:
        chat_name = chat.title
    else:
        chat_name = sender_name

    text = msg.message or ""
    timestamp = int(msg.date.timestamp()) if msg.date else int(time.time())
    msg_id = str(msg.id)

    message_data = await _message_data_for(rt, msg, msg_id, text)

    # Cache for /lastIncoming, /lastOutgoing, and for delete handler later.
    try:
        rt.message_store.upsert(
            id_message=msg_id, chat_id=chat_id, sender=sender_str,
            from_me=bool(msg.out), timestamp=timestamp,
            type_message=message_data.get("typeMessage", "textMessage"),
            body=text,
        )
    except Exception:
        pass

    if kind == "edit":
        return rt.mapper.to_edited_message(
            msg_id=msg_id, chat_id=chat_id, sender=sender_str,
            sender_name=sender_name, message_data=message_data,
        )
    if msg.out:
        return rt.mapper.to_outgoing_message(
            msg_id=msg_id, chat_id=chat_id, sender=sender_str,
            timestamp=timestamp, message_data=message_data,
        )
    return rt.mapper.to_incoming_message(
        msg_id=msg_id, chat_id=chat_id, sender=sender_str,
        sender_name=sender_name, chat_name=chat_name,
        timestamp=timestamp, message_data=message_data,
    )


async def _message_data_for(rt: Runtime, msg: Message, msg_id: str, text: str) -> dict:
    media = msg.media
    if media is None:
        return rt.mapper.text_message_data(text)

    if isinstance(media, MessageMediaGeo):
        geo = media.geo
        lat = getattr(geo, "lat", None)
        lon = getattr(geo, "long", None)
        return rt.mapper.location_message_data(name="", address="", latitude=lat, longitude=lon)

    if isinstance(media, MessageMediaContact):
        first = media.first_name or ""
        last = media.last_name or ""
        vcard = media.vcard or ""
        display = (first + " " + last).strip() or media.phone_number or ""
        return rt.mapper.contact_message_data(display_name=display, vcard=vcard)

    if isinstance(media, MessageMediaPhoto):
        return await _emit_file(rt, msg, msg_id, text, kind="image",
                                 default_ext="jpg", default_mime="image/jpeg")

    if isinstance(media, MessageMediaDocument):
        doc = media.document
        mime = getattr(doc, "mime_type", "") or "application/octet-stream"
        file_name = ""
        for attr in getattr(doc, "attributes", []) or []:
            fn = getattr(attr, "file_name", None)
            if fn:
                file_name = fn
                break
        kind = "documentMessage"
        if mime.startswith("image/"):
            kind = "imageMessage"
        elif mime.startswith("video/"):
            kind = "videoMessage"
        elif mime.startswith("audio/"):
            kind = "audioMessage"
        ext = ""
        if file_name and "." in file_name:
            ext = file_name.rsplit(".", 1)[-1]
        if not ext:
            guess = mimetypes.guess_extension(mime) or ""
            ext = guess.lstrip(".") if guess else ""
        return await _store_media_and_payload(
            rt, msg, msg_id, text,
            type_message=kind, file_name=file_name or f"{msg_id}.{ext}" if ext else msg_id,
            mime=mime, ext=ext,
        )

    return rt.mapper.text_message_data(text)


async def _emit_file(rt: Runtime, msg, msg_id: str, text: str, *, kind: str,
                     default_ext: str, default_mime: str) -> dict:
    type_message = "imageMessage" if kind == "image" else "documentMessage"
    file_name = f"{msg_id}.{default_ext}"
    return await _store_media_and_payload(
        rt, msg, msg_id, text,
        type_message=type_message, file_name=file_name,
        mime=default_mime, ext=default_ext,
    )


async def _store_media_and_payload(rt: Runtime, msg, msg_id: str, caption: str, *,
                                     type_message: str, file_name: str, mime: str, ext: str) -> dict:
    # Download to temp, push to S3 so /media endpoint can serve it.
    if rt.media_store.is_ready():
        try:
            with tempfile.NamedTemporaryFile(delete=False) as tmp:
                tmp_path = tmp.name
            await rt.client.download_media(msg, file=tmp_path)
            with open(tmp_path, "rb") as fh:
                data = fh.read()
            os.unlink(tmp_path)
            rt.media_store.upload_bytes(message_id=msg_id, ext=ext, mime=mime, data=data)
        except Exception as e:
            log.warning("download/upload media failed msg=%s err=%s", msg_id, e)
    return rt.mapper.file_message_data(
        msg_id=msg_id, caption=caption, file_name=file_name,
        mime_type=mime, type_message=type_message,
    )
