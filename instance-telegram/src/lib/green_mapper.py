import time
from typing import Any, Optional, Union
from urllib.parse import quote


def tg_id_to_chat_id(entity_or_id: Any, is_group: bool = False) -> str:
    """
    Convert Telethon entity/peer ID to Green-API chatId.

    Legacy /var/www/wa.iqteco.com/handler.php strips chatId to digits only
    (preg_replace('/[^\\d]/', '', $rawChatId)) — so any numeric prefix works
    as long as the digits part is unique per user/chat.

    Convention:
      - User (private chat):  "{user_id}@c.us"
      - Group/megagroup/channel: "{abs(chat_id) without -100 prefix}@g.us"
    """
    if hasattr(entity_or_id, "id"):
        raw_id = entity_or_id.id
        # Detect group/channel via type if available
        if not is_group:
            cls = type(entity_or_id).__name__
            is_group = cls in ("Chat", "ChatForbidden", "Channel", "ChannelForbidden")
    else:
        raw_id = int(entity_or_id)

    if is_group:
        digits = abs(int(raw_id))
        # Telethon channel/megagroup ids may be negative or marked with -100 prefix
        s = str(digits)
        if s.startswith("100"):
            s = s[3:]
        return f"{s}@g.us"
    return f"{int(raw_id)}@c.us"


def chat_id_to_tg_id(chat_id: str) -> tuple[int, bool]:
    """
    Parse a Green-API chatId back to (telegram_id, is_group).
    Accepts:
      - "12345@c.us" or "12345" (digits → user)
      - "12345@g.us" → group/channel; returns negative TL-style id (-100 prefix)
    """
    if not chat_id:
        raise ValueError("empty chatId")
    s = chat_id.strip()
    is_group = "@g.us" in s
    digits = "".join(ch for ch in s if ch.isdigit())
    if not digits:
        raise ValueError(f"no digits in chatId: {chat_id!r}")
    n = int(digits)
    if is_group:
        # Reverse the @g.us encoding: prepend "100" if not present, then negate.
        # Telegram channel/megagroup TL-format: -100<channel_id>
        n_str = str(n)
        if not n_str.startswith("100"):
            n_str = "100" + n_str
        return -int(n_str), True
    return n, False


def _now() -> int:
    return int(time.time())


class GreenApiMapper:
    """
    Build Green-API webhook payloads from Telethon objects.
    Format matches instance/src/lib/GreenApiMapper.js so the same webhook_outbox
    schema is consumed by legacy handler.php without modification.
    """

    def __init__(self, id_instance: str, api_token: str, media_base_url: str = "") -> None:
        try:
            self.id_instance = int(id_instance)
        except (TypeError, ValueError):
            self.id_instance = id_instance
        self.api_token = str(api_token or "")
        self.media_base_url = (media_base_url or "").rstrip("/")
        self._wid: Optional[str] = None

    def set_wid(self, wid: Optional[str]) -> None:
        self._wid = wid

    def _instance_data(self) -> dict:
        return {
            "idInstance": self.id_instance,
            "wid": self._wid,
            "typeInstance": "telegram",
        }

    def _download_url(self, message_id: str, ext: str) -> str:
        if not self.media_base_url or not message_id:
            return ""
        encoded = quote(message_id, safe="")
        suffix = f".{ext}" if ext else ""
        return f"{self.media_base_url}/waInstance{self.id_instance}/media/{self.api_token}/{encoded}{suffix}"

    def to_state_instance_changed(self, state_instance: str) -> dict:
        return {
            "typeWebhook": "stateInstanceChanged",
            "instanceData": self._instance_data(),
            "timestamp": _now(),
            "stateInstance": state_instance,
        }

    def to_incoming_message(self, *, msg_id: str, chat_id: str, sender: str,
                             sender_name: str, chat_name: str, timestamp: int,
                             message_data: dict) -> dict:
        return {
            "typeWebhook": "incomingMessageReceived",
            "instanceData": self._instance_data(),
            "timestamp": timestamp or _now(),
            "idMessage": msg_id,
            "senderData": {
                "chatId": chat_id,
                "sender": sender,
                "senderName": sender_name,
                "chatName": chat_name,
            },
            "messageData": message_data,
        }

    def to_outgoing_message(self, *, msg_id: str, chat_id: str, sender: str,
                             timestamp: int, message_data: dict,
                             type_webhook: str = "outgoingMessageReceived") -> dict:
        return {
            "typeWebhook": type_webhook,
            "instanceData": self._instance_data(),
            "timestamp": timestamp or _now(),
            "idMessage": msg_id,
            "senderData": {
                "chatId": chat_id,
                "sender": sender,
                "senderName": "",
            },
            "messageData": message_data,
        }

    def to_outgoing_status(self, *, msg_id: str, chat_id: str, status: str,
                            send_by_api: bool) -> dict:
        return {
            "typeWebhook": "outgoingMessageStatus",
            "instanceData": self._instance_data(),
            "timestamp": _now(),
            "idMessage": msg_id,
            "status": status,
            "chatId": chat_id,
            "sendByApi": send_by_api,
        }

    def to_edited_message(self, *, msg_id: str, chat_id: str, sender: str,
                          sender_name: str, message_data: dict) -> dict:
        return {
            "typeWebhook": "editedMessageReceived",
            "instanceData": self._instance_data(),
            "timestamp": _now(),
            "idMessage": msg_id,
            "senderData": {
                "chatId": chat_id,
                "sender": sender,
                "senderName": sender_name,
            },
            "messageData": message_data,
        }

    def to_deleted_message(self, *, msg_id: str, chat_id: str, sender: str) -> dict:
        return {
            "typeWebhook": "deletedMessageReceived",
            "instanceData": self._instance_data(),
            "timestamp": _now(),
            "idMessage": msg_id,
            "senderData": {"chatId": chat_id, "sender": sender},
        }

    def text_message_data(self, text: str) -> dict:
        return {"typeMessage": "textMessage", "textMessageData": {"textMessage": text or ""}}

    def file_message_data(self, *, msg_id: str, caption: str, file_name: str,
                          mime_type: str, type_message: str = "documentMessage") -> dict:
        ext = ""
        if file_name and "." in file_name:
            ext = file_name.rsplit(".", 1)[-1].lower()
        fields = {
            "downloadUrl": self._download_url(msg_id, ext),
            "caption": caption or "",
            "fileName": file_name or "",
            "mimeType": mime_type or "",
        }
        out = {"typeMessage": type_message, "fileMessageData": fields}
        if type_message == "imageMessage":
            out["imageMessageData"] = fields
        return out

    def location_message_data(self, *, name: str, address: str,
                               latitude: Optional[float], longitude: Optional[float]) -> dict:
        return {
            "typeMessage": "locationMessage",
            "locationMessageData": {
                "nameLocation": name or "",
                "address": address or "",
                "latitude": latitude,
                "longitude": longitude,
            },
        }

    def contact_message_data(self, *, display_name: str, vcard: str) -> dict:
        return {
            "typeMessage": "contactMessage",
            "contactMessageData": {"displayName": display_name or "", "vcard": vcard or ""},
        }
