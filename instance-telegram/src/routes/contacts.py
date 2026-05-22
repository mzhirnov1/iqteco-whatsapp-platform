import base64
import logging
from typing import Any

from fastapi import FastAPI, Request, Body, HTTPException

from ..runtime import Runtime
from ..lib.green_mapper import chat_id_to_tg_id, tg_id_to_chat_id
from ._common import get_runtime, check_auth, require_authorized


log = logging.getLogger("contacts")


def register(app: FastAPI, rt: Runtime) -> None:
    @app.api_route("/waInstance{id_instance}/checkWhatsapp/{token}", methods=["GET", "POST"])
    async def check_whatsapp(id_instance: str, token: str, request: Request,
                              payload: dict = Body(default={})):
        from telethon.tl.functions.contacts import ImportContactsRequest, DeleteContactsRequest
        from telethon.tl.types import InputPhoneContact
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        require_authorized(r)
        phone = (payload or {}).get("phoneNumber") or request.query_params.get("phoneNumber") or ""
        phone = str(phone)
        if not phone:
            raise HTTPException(status_code=400, detail={"error": "missing_phoneNumber"})
        try:
            contact = InputPhoneContact(client_id=0, phone=phone, first_name="probe", last_name="")
            res = await r.client(ImportContactsRequest([contact]))
            exists = bool(res.users)
            # Clean up — don't pollute address book
            if res.users:
                try:
                    await r.client(DeleteContactsRequest(res.users))
                except Exception:
                    pass
            return {"existsWhatsapp": exists}
        except Exception as e:
            log.warning("checkWhatsapp failed phone=%s err=%s", phone, e)
            return {"existsWhatsapp": False}

    @app.get("/waInstance{id_instance}/getContacts/{token}")
    async def get_contacts(id_instance: str, token: str, request: Request):
        from telethon.tl.functions.contacts import GetContactsRequest
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        require_authorized(r)
        try:
            res = await r.client(GetContactsRequest(hash=0))
        except Exception as e:
            log.warning("getContacts failed: %s", e)
            return []
        out = []
        for u in getattr(res, "users", []) or []:
            name = ((u.first_name or "") + " " + (u.last_name or "")).strip() or (u.username or "")
            out.append({
                "id": tg_id_to_chat_id(u.id, is_group=False),
                "name": name,
                "type": "user",
            })
        return out

    @app.api_route("/waInstance{id_instance}/getContactInfo/{token}", methods=["GET", "POST"])
    async def get_contact_info(id_instance: str, token: str, request: Request,
                                 payload: dict = Body(default={})):
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        require_authorized(r)
        chat_id = (payload or {}).get("chatId") or request.query_params.get("chatId") or ""
        if not chat_id:
            raise HTTPException(status_code=400, detail={"error": "missing_chatId"})
        tg_id, is_group = chat_id_to_tg_id(chat_id)
        try:
            entity = await r.client.get_entity(tg_id)
        except Exception as e:
            return {"chatId": chat_id, "exists": False, "error": str(e)}
        name = ""
        if hasattr(entity, "title") and entity.title:
            name = entity.title
        else:
            first = getattr(entity, "first_name", "") or ""
            last = getattr(entity, "last_name", "") or ""
            name = (first + " " + last).strip() or (getattr(entity, "username", "") or "")
        return {
            "chatId": chat_id,
            "exists": True,
            "name": name,
            "type": "group" if is_group else "user",
            "username": getattr(entity, "username", "") or "",
        }

    @app.api_route("/waInstance{id_instance}/getAvatar/{token}", methods=["GET", "POST"])
    async def get_avatar(id_instance: str, token: str, request: Request,
                          payload: dict = Body(default={})):
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        require_authorized(r)
        chat_id = (payload or {}).get("chatId") or request.query_params.get("chatId") or ""
        if not chat_id:
            raise HTTPException(status_code=400, detail={"error": "missing_chatId"})
        tg_id, is_group = chat_id_to_tg_id(chat_id)
        try:
            entity = await r.client.get_entity(tg_id)
            buf = await r.client.download_profile_photo(entity, file=bytes)
            if not buf:
                return {"urlAvatar": "", "available": False}
            b64 = base64.b64encode(buf).decode("ascii")
            return {"urlAvatar": f"data:image/jpeg;base64,{b64}", "available": True}
        except Exception as e:
            log.warning("getAvatar failed chat=%s err=%s", chat_id, e)
            return {"urlAvatar": "", "available": False}
