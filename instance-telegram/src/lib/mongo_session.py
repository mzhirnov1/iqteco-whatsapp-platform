import io
import logging
import os
import tempfile
import threading
import time
from typing import Any, Optional

from telethon.sessions import SQLiteSession
from pymongo.errors import PyMongoError
import gridfs


SESSION_FILE_PREFIX = "tg-session-"
BUCKET_NAME = "tg_sessions"
REVISIONS_TO_KEEP = 3
SAVE_DEBOUNCE_SECONDS = 30


class MongoSession(SQLiteSession):
    """
    Telethon SQLiteSession that loads/saves the on-disk .session file
    from MongoDB GridFS bucket `tg_sessions`.

    Concept mirrors instance/src/lib/MongoStore.js for whatsapp-web.js:
    the underlying library writes a single binary file; we shuttle it
    to/from GridFS on startup, on demand, and on shutdown.
    """

    def __init__(self, *, db: Any, id_instance: str, logger: logging.Logger) -> None:
        self._mongo_db = db
        self._id_instance = id_instance
        self._log = logger
        self._bucket = gridfs.GridFSBucket(db, bucket_name=BUCKET_NAME)
        self._file_id = SESSION_FILE_PREFIX + id_instance

        self._tmpdir = tempfile.mkdtemp(prefix="tg-session-")
        self._session_path = os.path.join(self._tmpdir, f"{id_instance}.session")

        self._last_save_at = 0.0
        self._save_lock = threading.Lock()

        super().__init__(self._session_path)

    def load(self) -> None:
        """Restore session file from GridFS if present (called before TelegramClient connect)."""
        try:
            cursor = self._bucket.find({"filename": self._file_id}).sort("uploadDate", -1).limit(1)
            for f in cursor:
                buf = io.BytesIO()
                self._bucket.download_to_stream(f._id, buf)
                with open(self._session_path, "wb") as out:
                    out.write(buf.getvalue())
                self._log.info("loaded tg session from GridFS size=%d", len(buf.getvalue()))
                return
            self._log.info("no tg session in GridFS — fresh start")
        except PyMongoError as e:
            self._log.warning("mongo session load failed: %s", e)

    def save(self) -> None:
        super().save()
        now = time.time()
        if now - self._last_save_at < SAVE_DEBOUNCE_SECONDS:
            return
        self._last_save_at = now
        try:
            self._upload_to_gridfs()
        except Exception as e:
            self._log.warning("mongo session save failed: %s", e)

    def force_save(self) -> None:
        super().save()
        try:
            self._upload_to_gridfs()
        except Exception as e:
            self._log.warning("mongo session force-save failed: %s", e)

    def _upload_to_gridfs(self) -> None:
        if not os.path.exists(self._session_path):
            return
        with self._save_lock:
            with open(self._session_path, "rb") as src:
                blob = src.read()
            if not blob:
                return
            self._bucket.upload_from_stream(
                self._file_id,
                io.BytesIO(blob),
                metadata={
                    "session": self._file_id,
                    "idInstance": self._id_instance,
                    "size": len(blob),
                    "savedAt": int(time.time() * 1000),
                },
            )
            self._cleanup_old_revisions()
            self._log.debug("saved tg session to GridFS size=%d", len(blob))

    def _cleanup_old_revisions(self) -> None:
        try:
            cursor = self._bucket.find({"filename": self._file_id}).sort("uploadDate", -1)
            files = list(cursor)
            for stale in files[REVISIONS_TO_KEEP:]:
                self._bucket.delete(stale._id)
        except PyMongoError as e:
            self._log.warning("mongo session cleanup failed: %s", e)
