import datetime as dt
from typing import Any, Optional


class MessageStore:
    """
    Per-instance Mongo collection caching outgoing/incoming messages.
    Schema kept compatible with instance/src/lib/MessageStore.js
    so admin tools that read both types use a single shape.
    """

    def __init__(self, db: Any, id_instance: str, ttl_days: int = 30) -> None:
        self.db = db
        self.id_instance = str(id_instance)
        self.collection = db[f"messages_{self.id_instance}"]
        self.ttl_days = ttl_days

    def ensure_indexes(self) -> None:
        try:
            self.collection.create_index([("idMessage", 1)], unique=True)
            self.collection.create_index([("chatId", 1), ("timestamp", -1)])
            if self.ttl_days > 0:
                self.collection.create_index(
                    [("createdAt", 1)],
                    expireAfterSeconds=self.ttl_days * 86400,
                )
        except Exception:
            pass

    def upsert(self, *, id_message: str, chat_id: str, sender: str,
               from_me: bool, timestamp: int, type_message: str,
               body: str = "", file_url: str = "", file_name: str = "",
               mime_type: str = "", extra: Optional[dict] = None) -> None:
        doc = {
            "idMessage": id_message,
            "chatId": chat_id,
            "sender": sender,
            "fromMe": from_me,
            "timestamp": timestamp,
            "typeMessage": type_message,
            "body": body,
            "fileUrl": file_url,
            "fileName": file_name,
            "mimeType": mime_type,
            "createdAt": dt.datetime.now(dt.timezone.utc),
        }
        if extra:
            doc.update(extra)
        self.collection.update_one({"idMessage": id_message}, {"$set": doc}, upsert=True)

    def find_by_id(self, id_message: str) -> Optional[dict]:
        return self.collection.find_one({"idMessage": id_message})

    def list_by_chat(self, chat_id: str, limit: int = 100, from_me: Optional[bool] = None) -> list[dict]:
        q: dict[str, Any] = {"chatId": chat_id}
        if from_me is not None:
            q["fromMe"] = from_me
        return list(self.collection.find(q).sort("timestamp", -1).limit(limit))
