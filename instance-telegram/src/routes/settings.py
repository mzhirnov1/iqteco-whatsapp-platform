from fastapi import FastAPI, Request, Body

from ..runtime import Runtime
from ._common import get_runtime, check_auth


def register(app: FastAPI, rt: Runtime) -> None:
    @app.get("/waInstance{id_instance}/getSettings/{token}")
    async def get_settings(id_instance: str, token: str, request: Request):
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        settings = (r.admin_config or {}).get("settings") or {}
        return {
            "wid": r.state.wid or "",
            "countryInstance": "",
            "typeAccount": "user",
            "webhookUrl": (r.admin_config or {}).get("webhookUrl") or "",
            "webhookUrlToken": "",
            "delaySendMessagesMilliseconds": settings.get("delaySendMessagesMilliseconds", 1000),
            "markIncomingMessagesReaded": settings.get("markIncomingMessagesReaded", "no"),
            "markIncomingMessagesReadedOnReply": "no",
            "outgoingWebhook": settings.get("outgoingWebhook", "yes"),
            "outgoingMessageWebhook": settings.get("outgoingMessageWebhook", "yes"),
            "outgoingAPIMessageWebhook": settings.get("outgoingAPIMessageWebhook", "yes"),
            "incomingWebhook": settings.get("incomingWebhook", "yes"),
            "stateWebhook": settings.get("stateWebhook", "yes"),
            "deviceWebhook": "no",
            "keepOnlineStatus": "no",
        }

    @app.post("/waInstance{id_instance}/setSettings/{token}")
    async def set_settings(id_instance: str, token: str, request: Request,
                            payload: dict = Body(default={})):
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        # Forward to admin so it persists in db.instances.settings + reloads adminConfig.
        result = await r.admin_client._req(
            "POST", f"/instances/{id_instance}/settings", payload or {}
        )
        # Refresh in-memory adminConfig to reflect new settings
        fresh = await r.admin_client.get_config()
        if fresh:
            r.admin_config["webhookUrl"] = fresh.get("webhookUrl") or r.admin_config.get("webhookUrl")
            r.admin_config["webhookSecret"] = fresh.get("webhookSecret") or r.admin_config.get("webhookSecret")
            r.admin_config["settings"] = fresh.get("settings") or r.admin_config.get("settings") or {}
        return {"saveSettings": True, **(result or {})}
