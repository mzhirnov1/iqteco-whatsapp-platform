from fastapi import FastAPI, Request

from ..runtime import Runtime
from ._common import get_runtime, check_auth


def register(app: FastAPI, rt: Runtime) -> None:
    # Push-only platform: receive/delete are stubs that always return null.
    @app.get("/waInstance{id_instance}/receiveNotification/{token}")
    async def receive(id_instance: str, token: str, request: Request):
        check_auth(get_runtime(request), id_instance, token)
        return None  # legacy clients accept null when queue empty

    @app.delete("/waInstance{id_instance}/deleteNotification/{token}/{receipt_id}")
    async def delete_notif(id_instance: str, token: str, receipt_id: str, request: Request):
        check_auth(get_runtime(request), id_instance, token)
        return {"result": True}
