# Green API Compatibility Reference

100% совместимый набор endpoint'ов с `green-api.com`. Все URL имеют формат:

```
https://api.wa.iqteco.com/waInstance{idInstance}/{method}/{apiTokenInstance}
```

`idInstance` — 11-значное число (формат Green API), `apiTokenInstance` — 50 hex chars.

## Реализованные методы (Phase 1 MVP)

### Account / State

| Method | HTTP | Описание |
|---|---|---|
| `getStateInstance` | GET | Состояние инстанса. `{stateInstance: authorized\|notAuthorized\|starting\|sleepMode\|yellowCard}` |
| `getQrCode` | GET | Возвращает текущий QR `{type: qrCode, message: <base64 png>}` |
| `getAuthorizationCode` | GET | `?phoneNumber=...` — возвращает 8-значный pairing code |
| `reboot` | GET | Перезагружает Chromium внутри контейнера. `{isReboot: true}` |
| `logout` | GET | Разрывает WhatsApp сессию. `{isLogout: true}` |
| `getSettings` | GET | Текущие настройки инстанса (webhook URL, flags) |
| `setSettings` | POST | Body: подмножество полей settings. Не требует перезагрузки. |

### Sending

| Method | HTTP | Body |
|---|---|---|
| `sendMessage` | POST | `{chatId, message, quotedMessageId?}` |

## Phase 3 (планируется)

| Method | Описание |
|---|---|
| `sendFileByUrl` | `{chatId, urlFile, fileName, caption?}` |
| `sendFileByUpload` | multipart |
| `sendLocation` | `{chatId, latitude, longitude, nameLocation, address}` |
| `sendContact` | `{chatId, contact: {phoneContact, firstName, ...}}` |
| `forwardMessages` | `{chatId, chatIdFrom, messages[]}` |
| `checkWhatsapp` | `{phoneNumber}` → `{existsWhatsapp: bool}` |
| `getContacts` | список контактов |
| `getContactInfo` | `{chatId}` |
| `getChatHistory` | `{chatId, count, idMessage?}` |
| `lastIncomingMessages` | `?minutes=...` |
| `lastOutgoingMessages` | `?minutes=...` |
| `markChatAsRead` | `{chatId, idMessage?}` |
| `getAvatar` | `?chatId=...` |

## Webhook (Phase 1+4)

Контейнер POST'ит JSON по адресу `webhookUrl` (настраивается через `setSettings` или при создании инстанса).

### Phase 1: `incomingMessageReceived`

```json
{
  "typeWebhook": "incomingMessageReceived",
  "instanceData": {
    "idInstance": 1101000001,
    "wid": "79991234567@c.us",
    "typeInstance": "whatsapp"
  },
  "timestamp": 1700000000,
  "idMessage": "...",
  "senderData": {
    "chatId": "79991234567@c.us",
    "sender": "79991234567@c.us",
    "senderName": "..."
  },
  "messageData": {
    "typeMessage": "textMessage",
    "textMessageData": { "textMessage": "..." }
  }
}
```

### Phase 4

- `outgoingMessageReceived` — отправлено с телефона
- `outgoingAPIMessageReceived` — отправлено через `sendMessage`
- `outgoingMessageStatus` — `{status: sent|delivered|read|failed}`
- `stateInstanceChanged` — `{stateInstance: authorized|notAuthorized|...}`

## Receive Notification API (push-only режим)

Для совместимости со старыми клиентами Green API, пытающимися использовать pull:

| Method | Поведение |
|---|---|
| `receiveNotification` | Возвращает `null` (всегда) |
| `deleteNotification` | Возвращает `{result: false}` |

Это сигнализирует клиенту что push настроен и pull не нужен.

## Errors

Стандартные коды:
- `401` — apiTokenInstance не совпадает
- `404` — unknown idInstance
- `429` — rate limit (фаза 5)
- `501` — метод не реализован
- `503` — инстанс не в state=authorized (для send-методов)
