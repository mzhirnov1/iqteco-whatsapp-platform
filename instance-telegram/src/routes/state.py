import asyncio

from fastapi import FastAPI, Request

from ..runtime import Runtime
from ._common import get_runtime, check_auth


def register(app: FastAPI, rt: Runtime) -> None:
    @app.get("/waInstance{id_instance}/getStateInstance/{token}")
    @app.get("/waInstance{id_instance}/getStatusInstance/{token}")
    async def get_state(id_instance: str, token: str, request: Request):
        check_auth(get_runtime(request), id_instance, token)
        r = get_runtime(request)
        # Telethon-derived state
        if not r.client:
            return {"stateInstance": "starting"}
        if not r.client.is_connected():
            return {"stateInstance": "notAuthorized"}
        try:
            authorized = await r.client.is_user_authorized()
        except Exception:
            authorized = r.state.authorized
        return {"stateInstance": "authorized" if authorized else "notAuthorized"}

    @app.get("/waInstance{id_instance}/reboot/{token}")
    async def reboot(id_instance: str, token: str, request: Request):
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        asyncio.create_task(_reboot_client(r))
        return {"isReboot": True}

    @app.get("/waInstance{id_instance}/logout/{token}")
    async def logout(id_instance: str, token: str, request: Request):
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        try:
            if r.client and r.client.is_connected():
                await r.client.log_out()
            r.state.authorized = False
            r.state.last_state = "notAuthorized"
            await r.admin_client.state_change(r.state.last_state, "notAuthorized", "api_logout")
            return {"isLogout": True}
        except Exception as e:
            r.log.error("logout failed: %s", e)
            return {"isLogout": False, "error": str(e)}


async def _reboot_client(r: Runtime) -> None:
    try:
        if r.client and r.client.is_connected():
            await r.client.disconnect()
        await r.client.connect()
        r.log.info("reboot: client reconnected")
    except Exception as e:
        r.log.error("reboot failed: %s", e)
