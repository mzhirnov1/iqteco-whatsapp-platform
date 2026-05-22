import asyncio
import logging
import mimetypes
import os
import tempfile
import time
import uuid
from typing import Optional

import httpx
from fastapi import FastAPI, Request, Body, HTTPException, UploadFile, File, Form

from ..runtime import Runtime
from ..lib.green_mapper import chat_id_to_tg_id, tg_id_to_chat_id
from ._common import get_runtime, check_auth, require_authorized


log = logging.getLogger("send")


async def _resolve_entity(rt: Runtime, chat_id: str):
    tg_id, is_group = chat_id_to_tg_id(chat_id)
    try:
        return await rt.client.get_entity(tg_id)
    except Exception as e:
        log.warning("get_entity %s failed: %s", chat_id, e)
        raise HTTPException(status_code=400, detail={"error": "entity_not_found", "chatId": chat_id})


def _msg_id(sent_msg) -> str:
    return f"{int(sent_msg.id)}"


def register(app: FastAPI, rt: Runtime) -> None:
    @app.post("/waInstance{id_instance}/sendMessage/{token}")
    async def send_message(id_instance: str, token: str, request: Request,
                            payload: dict = Body(default={})):
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        require_authorized(r)
        chat_id = (payload or {}).get("chatId") or ""
        text = (payload or {}).get("message") or ""
        quoted = (payload or {}).get("quotedMessageId") or None
        entity = await _resolve_entity(r, chat_id)
        reply_to = None
        if quoted:
            try:
                reply_to = int("".join(c for c in str(quoted) if c.isdigit()))
            except Exception:
                reply_to = None
        sent = await r.client.send_message(entity, text, reply_to=reply_to)
        idm = _msg_id(sent)
        r.outgoing_api_ids.add(idm)
        try:
            r.message_store.upsert(
                id_message=idm, chat_id=chat_id,
                sender=r.state.wid or "",
                from_me=True, timestamp=int(time.time()),
                type_message="textMessage", body=text,
            )
        except Exception:
            pass
        return {"idMessage": idm}

    @app.post("/waInstance{id_instance}/sendFileByUrl/{token}")
    async def send_file_by_url(id_instance: str, token: str, request: Request,
                                payload: dict = Body(default={})):
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        require_authorized(r)
        return await _send_file_by_url(r, payload or {}, as_image=False)

    @app.post("/waInstance{id_instance}/sendImageByUrl/{token}")
    async def send_image_by_url(id_instance: str, token: str, request: Request,
                                  payload: dict = Body(default={})):
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        require_authorized(r)
        return await _send_file_by_url(r, payload or {}, as_image=True)

    @app.post("/waInstance{id_instance}/sendFileByUpload/{token}")
    async def send_file_by_upload(id_instance: str, token: str, request: Request,
                                    chatId: str = Form(...),
                                    caption: str = Form(""),
                                    fileName: str = Form(""),
                                    file: UploadFile = File(...)):
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        require_authorized(r)
        entity = await _resolve_entity(r, chatId)
        data = await file.read()
        suffix = ""
        if fileName and "." in fileName:
            suffix = "." + fileName.rsplit(".", 1)[-1]
        with tempfile.NamedTemporaryFile(delete=False, suffix=suffix) as tmp:
            tmp.write(data)
            tmp_path = tmp.name
        try:
            sent = await r.client.send_file(entity, tmp_path, caption=caption or None,
                                            file_name=fileName or None)
        finally:
            try:
                os.unlink(tmp_path)
            except OSError:
                pass
        idm = _msg_id(sent)
        r.outgoing_api_ids.add(idm)
        return {"idMessage": idm}

    @app.post("/waInstance{id_instance}/sendLocation/{token}")
    async def send_location(id_instance: str, token: str, request: Request,
                             payload: dict = Body(default={})):
        from telethon.tl.types import InputMediaGeoPoint, InputGeoPoint
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        require_authorized(r)
        chat_id = (payload or {}).get("chatId") or ""
        lat = float((payload or {}).get("latitude") or 0)
        lon = float((payload or {}).get("longitude") or 0)
        entity = await _resolve_entity(r, chat_id)
        media = InputMediaGeoPoint(geo_point=InputGeoPoint(lat=lat, long=lon))
        sent = await r.client.send_file(entity, media)
        idm = _msg_id(sent)
        r.outgoing_api_ids.add(idm)
        return {"idMessage": idm}

    @app.post("/waInstance{id_instance}/sendContact/{token}")
    async def send_contact(id_instance: str, token: str, request: Request,
                            payload: dict = Body(default={})):
        from telethon.tl.types import InputMediaContact
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        require_authorized(r)
        chat_id = (payload or {}).get("chatId") or ""
        contact = (payload or {}).get("contact") or {}
        phone = str(contact.get("phoneContact") or contact.get("phone") or "")
        first = str(contact.get("firstName") or "")
        last = str(contact.get("lastName") or "")
        entity = await _resolve_entity(r, chat_id)
        media = InputMediaContact(phone_number=phone, first_name=first, last_name=last, vcard="")
        sent = await r.client.send_file(entity, media)
        idm = _msg_id(sent)
        r.outgoing_api_ids.add(idm)
        return {"idMessage": idm}

    @app.post("/waInstance{id_instance}/forwardMessages/{token}")
    async def forward_messages(id_instance: str, token: str, request: Request,
                                payload: dict = Body(default={})):
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        require_authorized(r)
        chat_id = (payload or {}).get("chatId") or ""
        from_chat = (payload or {}).get("chatIdFrom") or chat_id
        msg_ids_raw = (payload or {}).get("messages") or []
        msg_ids = []
        for m in msg_ids_raw:
            digits = "".join(c for c in str(m) if c.isdigit())
            if digits:
                msg_ids.append(int(digits))
        if not msg_ids:
            raise HTTPException(status_code=400, detail={"error": "no_messages"})
        to_entity = await _resolve_entity(r, chat_id)
        from_entity = await _resolve_entity(r, from_chat)
        results = await r.client.forward_messages(to_entity, msg_ids, from_entity)
        if not isinstance(results, list):
            results = [results]
        return {"messages": [{"idMessage": _msg_id(m)} for m in results]}

    @app.post("/waInstance{id_instance}/markChatAsRead/{token}")
    async def mark_chat_as_read(id_instance: str, token: str, request: Request,
                                  payload: dict = Body(default={})):
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        require_authorized(r)
        chat_id = (payload or {}).get("chatId") or ""
        entity = await _resolve_entity(r, chat_id)
        try:
            await r.client.send_read_acknowledge(entity)
        except Exception as e:
            log.warning("mark_chat_as_read failed: %s", e)
        return {"setRead": True}


async def _send_file_by_url(r: Runtime, payload: dict, as_image: bool):
    chat_id = (payload or {}).get("chatId") or ""
    url_field = (payload or {}).get("urlFile") or {}
    if isinstance(url_field, dict):
        url = url_field.get("url") or url_field.get("downloadUrl") or ""
        file_name = url_field.get("fileName") or ""
    else:
        url = str(url_field or "")
        file_name = (payload or {}).get("fileName") or ""
    caption = (payload or {}).get("caption") or ""
    if not url:
        raise HTTPException(status_code=400, detail={"error": "missing_url"})

    entity = await _resolve_entity(r, chat_id)
    async with httpx.AsyncClient(timeout=60) as client:
        resp = await client.get(url)
        resp.raise_for_status()
        data = resp.content
        ctype = resp.headers.get("content-type", "")

    suffix = ""
    if file_name and "." in file_name:
        suffix = "." + file_name.rsplit(".", 1)[-1]
    elif ctype:
        guessed = mimetypes.guess_extension(ctype.split(";")[0]) or ""
        suffix = guessed
    with tempfile.NamedTemporaryFile(delete=False, suffix=suffix) as tmp:
        tmp.write(data)
        tmp_path = tmp.name
    try:
        sent = await r.client.send_file(
            entity, tmp_path,
            caption=caption or None,
            file_name=file_name or os.path.basename(tmp_path),
            force_document=not as_image,
        )
    finally:
        try:
            os.unlink(tmp_path)
        except OSError:
            pass

    idm = _msg_id(sent)
    r.outgoing_api_ids.add(idm)
    return {"idMessage": idm}
