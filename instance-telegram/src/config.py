import os
from dataclasses import dataclass


def _env(name: str, default: str = "") -> str:
    v = os.environ.get(name)
    return v if v is not None else default


@dataclass(frozen=True)
class Config:
    id_instance: str
    api_token: str
    mongo_url: str
    mongo_db: str
    admin_url: str
    admin_token: str
    webhook_url: str
    ipv6_addr: str
    media_base_url: str
    s3_endpoint: str
    s3_region: str
    s3_bucket: str
    s3_access_key: str
    s3_secret_key: str
    s3_key_prefix: str
    log_level: str

    tg_api_id: int
    tg_api_hash: str
    tg_phone: str
    tg_auth_method: str

    @staticmethod
    def from_env() -> "Config":
        api_id_raw = _env("TG_API_ID", "0")
        try:
            api_id = int(api_id_raw)
        except ValueError:
            api_id = 0
        return Config(
            id_instance=_env("IDINSTANCE"),
            api_token=_env("API_TOKEN"),
            mongo_url=_env("MONGO_URL", "mongodb://127.0.0.1:27017"),
            mongo_db=_env("MONGO_DB", "iqteco_wa"),
            admin_url=_env("ADMIN_URL", "https://admin.wa.iqteco.com"),
            admin_token=_env("ADMIN_TOKEN"),
            webhook_url=_env("WEBHOOK_URL"),
            ipv6_addr=_env("IPV6_ADDR"),
            media_base_url=_env("MEDIA_BASE_URL", "https://api.wa.iqteco.com"),
            s3_endpoint=_env("S3_ENDPOINT"),
            s3_region=_env("S3_REGION"),
            s3_bucket=_env("S3_BUCKET"),
            s3_access_key=_env("S3_ACCESS_KEY"),
            s3_secret_key=_env("S3_SECRET_KEY"),
            s3_key_prefix=_env("S3_KEY_PREFIX", "media/"),
            log_level=_env("LOG_LEVEL", "info"),
            tg_api_id=api_id,
            tg_api_hash=_env("TG_API_HASH"),
            tg_phone=_env("TG_PHONE"),
            tg_auth_method=_env("TG_AUTH_METHOD", "tg_qr"),
        )
