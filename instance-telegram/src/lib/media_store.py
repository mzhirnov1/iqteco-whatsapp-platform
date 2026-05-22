import logging
from typing import Optional

import boto3
from botocore.client import Config as BotoConfig

from ..config import Config


class MediaStore:
    """Wasabi S3 upload + metadata. Mirrors instance/src/lib/MediaStore.js semantics."""

    def __init__(self, cfg: Config, logger: Optional[logging.Logger] = None) -> None:
        self.cfg = cfg
        self.log = logger or logging.getLogger("media")
        self._client = None
        if cfg.s3_endpoint and cfg.s3_access_key and cfg.s3_secret_key:
            self._client = boto3.client(
                "s3",
                endpoint_url=cfg.s3_endpoint,
                aws_access_key_id=cfg.s3_access_key,
                aws_secret_access_key=cfg.s3_secret_key,
                region_name=cfg.s3_region or "us-east-1",
                config=BotoConfig(s3={"addressing_style": "path"}),
            )

    def is_ready(self) -> bool:
        return self._client is not None and bool(self.cfg.s3_bucket)

    def key_for(self, message_id: str, ext: str) -> str:
        prefix = (self.cfg.s3_key_prefix or "").rstrip("/")
        suffix = f".{ext}" if ext else ""
        return f"{prefix}/{self.cfg.id_instance}/{message_id}{suffix}".lstrip("/")

    def upload_bytes(self, *, message_id: str, ext: str, mime: str, data: bytes) -> Optional[str]:
        if not self.is_ready():
            return None
        key = self.key_for(message_id, ext)
        try:
            self._client.put_object(
                Bucket=self.cfg.s3_bucket,
                Key=key,
                Body=data,
                ContentType=mime or "application/octet-stream",
            )
            return key
        except Exception as e:
            self.log.warning("s3 put_object failed key=%s err=%s", key, e)
            return None

    def get_bytes(self, *, message_id: str, ext: str) -> Optional[tuple[bytes, str]]:
        if not self.is_ready():
            return None
        key = self.key_for(message_id, ext)
        try:
            obj = self._client.get_object(Bucket=self.cfg.s3_bucket, Key=key)
            mime = obj.get("ContentType") or "application/octet-stream"
            return obj["Body"].read(), mime
        except Exception as e:
            self.log.warning("s3 get_object failed key=%s err=%s", key, e)
            return None
