from fastapi import FastAPI, Request, Body

from ..runtime import Runtime
from ._common import get_runtime, check_auth


def register(app: FastAPI, rt: Runtime) -> None:
    @app.get("/waInstance{id_instance}/getQrCode/{token}")
    @app.get("/waInstance{id_instance}/qr/{token}")
    async def get_qr(id_instance: str, token: str, request: Request):
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        if r.state.authorized:
            return {"type": "alreadyLogged", "message": "instance already authorized"}
        qr = r.qr_cache
        if not qr.png_base64:
            return {"type": "waiting", "message": "QR is being generated"}
        return {
            "type": "qrCode",
            "message": qr.png_base64,
        }

    @app.get("/waInstance{id_instance}/getAuthorizationCode/{token}")
    async def get_auth_code(id_instance: str, token: str, request: Request):
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        code = r.code_cache.code
        if not code:
            return {"status": False, "message": "code not available"}
        return {"status": True, "code": code}

    @app.post("/waInstance{id_instance}/getAuthorizationCode/{token}")
    async def submit_auth(id_instance: str, token: str, request: Request,
                          payload: dict = Body(default={})):
        """
        Internal overload: admin UI uses this endpoint to submit a phone-code
        or 2FA password coming from the operator's input. Body:
          { "code": "12345" }       — Telegram login code received in app/SMS
          { "password": "..." }     — 2FA password if account has it
          { "phone": "+380..." }    — kicks off send_code_request if not yet started
        """
        from ..events.auth_flow import submit_phone_auth
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        result = await submit_phone_auth(r, payload or {})
        return result
