from fastapi import HTTPException, Request

from ..runtime import Runtime


def get_runtime(request: Request) -> Runtime:
    rt: Runtime = request.app.state.rt
    return rt


def check_auth(rt: Runtime, id_instance: str, token: str) -> None:
    if id_instance != rt.cfg.id_instance:
        raise HTTPException(status_code=404, detail={"error": "unknown_instance"})
    if token != rt.cfg.api_token:
        raise HTTPException(status_code=403, detail={"error": "bad_token"})


def require_authorized(rt: Runtime) -> None:
    if not rt.state.authorized:
        raise HTTPException(status_code=466, detail={"error": "not_authorized"})
