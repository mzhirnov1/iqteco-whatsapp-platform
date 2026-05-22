from fastapi import FastAPI, Request, HTTPException
from fastapi.responses import Response

from ..runtime import Runtime
from ._common import get_runtime, check_auth


def register(app: FastAPI, rt: Runtime) -> None:
    @app.get("/waInstance{id_instance}/media/{token}/{message_id}")
    async def media(id_instance: str, token: str, message_id: str, request: Request):
        r = get_runtime(request)
        check_auth(r, id_instance, token)
        ext = ""
        if "." in message_id:
            message_id, ext = message_id.rsplit(".", 1)
        result = r.media_store.get_bytes(message_id=message_id, ext=ext)
        if not result:
            raise HTTPException(status_code=404, detail={"error": "media_not_found"})
        data, mime = result
        return Response(content=data, media_type=mime)
