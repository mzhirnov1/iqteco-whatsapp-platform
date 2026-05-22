import logging

from fastapi import FastAPI, Request, Body

from ..runtime import Runtime
from ..lib.green_mapper import chat_id_to_tg_id
from ._common import get_runtime, check_auth, require_authorized


log = logging.getLogger("edit")


def register(app: FastAPI, rt: Runtime) -> None:
    @app.post("/waInstance{id_instance}/editMessage/{token}")
    async def edit_message(id_instance: str, token: str, request: Request,
                            payload: dict = Body(default={})):
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        require_authorized(r)
        chat_id = (payload or {}).get("chatId") or ""
        msg_id_in = (payload or {}).get("idMessage") or ""
        new_text = (payload or {}).get("message") or ""
        tg_id, _ = chat_id_to_tg_id(chat_id)
        msg_int = int("".join(c for c in str(msg_id_in) if c.isdigit()))
        entity = await r.client.get_entity(tg_id)
        await r.client.edit_message(entity, msg_int, new_text)
        return {"editMessage": True}

    @app.post("/waInstance{id_instance}/deleteMessage/{token}")
    async def delete_message(id_instance: str, token: str, request: Request,
                              payload: dict = Body(default={})):
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        require_authorized(r)
        chat_id = (payload or {}).get("chatId") or ""
        msg_id_in = (payload or {}).get("idMessage") or ""
        only_sender = bool((payload or {}).get("onlySenderDelete") or False)
        tg_id, _ = chat_id_to_tg_id(chat_id)
        msg_int = int("".join(c for c in str(msg_id_in) if c.isdigit()))
        entity = await r.client.get_entity(tg_id)
        await r.client.delete_messages(entity, [msg_int], revoke=not only_sender)
        return {"deleteMessage": True}

    @app.post("/waInstance{id_instance}/archiveChat/{token}")
    async def archive_chat(id_instance: str, token: str, request: Request,
                            payload: dict = Body(default={})):
        return await _toggle_archive(request, id_instance, token, payload, archived=True)

    @app.post("/waInstance{id_instance}/unarchiveChat/{token}")
    async def unarchive_chat(id_instance: str, token: str, request: Request,
                              payload: dict = Body(default={})):
        return await _toggle_archive(request, id_instance, token, payload, archived=False)


async def _toggle_archive(request, id_instance: str, token: str, payload: dict, archived: bool):
    from telethon.tl.functions.folders import EditPeerFoldersRequest
    from telethon.tl.types import InputFolderPeer
    r = get_runtime(request)
    check_auth(r, id_instance, token)
    require_authorized(r)
    chat_id = (payload or {}).get("chatId") or ""
    tg_id, _ = chat_id_to_tg_id(chat_id)
    try:
        peer = await r.client.get_input_entity(tg_id)
        folder_id = 1 if archived else 0
        await r.client(EditPeerFoldersRequest([InputFolderPeer(peer=peer, folder_id=folder_id)]))
        return {"archiveChat" if archived else "unarchiveChat": True}
    except Exception as e:
        log.warning("toggle_archive failed chat=%s err=%s", chat_id, e)
        return {"archiveChat" if archived else "unarchiveChat": False, "error": str(e)}
