from fastapi import HTTPException, Request

from .config import Config


def make_token_checker(cfg: Config):
    def check(request: Request, id_instance: str, token: str) -> None:
        if id_instance != cfg.id_instance:
            raise HTTPException(status_code=404, detail={"error": "unknown_instance"})
        if token != cfg.api_token:
            raise HTTPException(status_code=403, detail={"error": "bad_token"})
    return check
