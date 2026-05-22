from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse

from ..runtime import Runtime
from ._common import get_runtime, check_auth


def register(app: FastAPI, rt: Runtime) -> None:
    @app.api_route("/waInstance{id_instance}/{method}/{token}",
                   methods=["GET", "POST", "PUT", "DELETE"])
    async def fallback(id_instance: str, method: str, token: str, request: Request):
        check_auth(get_runtime(request), id_instance, token)
        return JSONResponse(
            status_code=501,
            content={"error": "not_implemented_yet", "method": method},
        )
