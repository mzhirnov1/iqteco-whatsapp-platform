# CLAUDE.md — рабочие конвенции для агента

## Контекст проекта

Платформа iqteco-whatsapp-platform = замена green-api.com для интеграции с
Bitrix24, которая существует в legacy-проекте `ssh root@wa.iqteco.com` →
`/var/www/wa.iqteco.com` (PHP + MongoDB). Наша платформа предоставляет
**100% совместимый Green API REST** (`api.wa.iqteco.com/waInstance{id}/{method}/{token}`),
чтобы клиентский код в `/var/www/wa.iqteco.com/handler.php`,
`action.php`, `helpers/GreenApi.php` мог быть подключён сменой `apiUrl`
без изменений в коде интеграции.

## Test Chat / WhatsApp Web UI цель

**`https://admin.wa.iqteco.com/instances/{idInstance}/chat`** — это
не самоцель и не финальный продукт. Это **отладочная панель**, в которой
мы должны:

1. Проверять что **каждый Green API метод**, который использует
   `/var/www/wa.iqteco.com/`, работает в нашем контейнере 1-в-1:
   `sendMessage`, `sendFileByUrl`, `sendImageByUrl`, `sendFileByUpload`,
   `getStateInstance`, `getSettings`, `setSettings`, `reboot`, `logout`,
   `getQrCode`, `getAuthorizationCode`, `checkWhatsapp`, `getContacts`,
   `getChats`, `getChatHistory`, `lastIncomingMessages`,
   `lastOutgoingMessages`, `markChatAsRead`, `getAvatar`, `getContactInfo`,
   `forwardMessages`, `editMessage`, `deleteMessage`, `archiveChat`/`unarchiveChat`,
   `sendLocation`, `sendContact`, `receiveNotification`/`deleteNotification` (push-only stub).

2. Проверять что **webhook'и** (`incomingMessageReceived`,
   `outgoingMessageReceived`, `outgoingAPIMessageReceived`,
   `outgoingMessageStatus`, `stateInstanceChanged`,
   `editedMessageReceived`, `deletedMessageReceived`,
   `incomingCall`, `groupJoin/Leave/Update`, `contactChanged`,
   `pollUpdate`, `deviceInfo`) приходят в нужном JSON-формате,
   совместимом с `/var/www/wa.iqteco.com/handler.php` обработчиком.

3. Воспроизводить кейсы, которые обрабатывает Bitrix24-интеграция
   (CRM-открытые линии, отправка из B24 → WA, приём из WA → B24).

### Когда дорабатываем chat UI — всегда помним:

- **Эта страница нужна чтобы убедиться, что наш Green API-эндпоинт
  работает как у green-api.com.** Любая фича в UI должна быть тестом
  одного или нескольких REST методов.
- Не оптимизируем UI отдельно от API. Если в UI обнаружен баг
  отображения — сначала смотрим **что вернул контейнерный
  `/waInstance{id}/{method}/{token}` напрямую** (curl с IPv6 контейнера).
  Часто проблема в API, не в UI.
- При расширениях UI добавляем покрытие **именно тех методов**,
  которые есть в `/var/www/wa.iqteco.com/helpers/GreenApi.php`
  (см. результат explore-агента в плане `green-synthetic-crayon.md`,
  раздел "Phase 7: WhatsApp Web-like UI"). Не делаем сверх — лучше
  потратить время на покрытие реальных кейсов.
- Цель: после прохождения всех методов через chat UI смело
  переключаем `apiUrl` тестового клиента в `/var/www/wa.iqteco.com/`
  на `https://api.wa.iqteco.com` — и **никаких изменений в legacy
  коде не требуется**.

## Где что лежит

- Локальный репо: `/root/whatsapp-platform/` (git remote
  `github.com:mzhirnov1/iqteco-whatsapp-platform.git`, main)
- Production deploy на этом же сервере (188.40.111.207):
  - `/var/www/admin.wa.iqteco.com/` — PHP админка (nginx + PHP-FPM 8.3)
  - `localhost/wa-instance:latest` — Podman образ контейнера инстанса
  - `wa-{idInstance}` — запущенные контейнеры в podman network `wa-net`
- Legacy (read-only reference): `ssh root@wa.iqteco.com:/var/www/wa.iqteco.com/`

## Deploy-цикл

1. Edit в `/root/whatsapp-platform/`
2. `cp` PHP/CSS/JS в `/var/www/admin.wa.iqteco.com/` (без rebuild для PHP)
3. Для JS контейнера: `podman build -t wa-instance:latest -f instance/Containerfile instance/`
4. Restart инстанса: `podman stop wa-{id} && podman rm wa-{id}` →
   повторный `InstanceManager.run()` (RemoteAuth восстановит сессию из GridFS)
5. `git commit` + `git push origin main`

## API контракт — `docs/openapi.yaml`

Машинно-читаемая спека OpenAPI 3.1, **source of truth для HTTP-контракта**.
Покрывает все четыре поверхности: Green API (`waInstance/*`, 32 метода),
исходящие webhook'и (15 типов), Partner API (4 метода) и
Admin/Container API (8 эндпоинтов). Поддерживает оба типа инстансов —
WhatsApp (`instance/`) и Telegram (`instance-telegram/`) — surface
у них общий.

При изменении любого REST-эндпоинта или формата webhook payload'а
**сначала правим `docs/openapi.yaml`**, потом код. После правок
обязательно прогоняем lint:

```bash
npx -y @redocly/cli@latest lint docs/openapi.yaml
```

`docs/API.md` — narrative-обзор, не источник истины. При расхождении
спека выигрывает. Импорт в Postman: `File → Import → docs/openapi.yaml`.

## Verify первичный

```bash
# Прямой контейнерный API (минуя nginx) — самая быстрая диагностика
TOKEN=$(mongosh "mongodb://127.0.0.1:27017/iqteco_wa" --quiet \
    --eval 'print(db.instances.findOne({idInstance:"1101000001"}).apiToken)' | tail -1)
curl -sS "http://[2a01:4f8:221:2d8d:c0a8::3]:8080/waInstance1101000001/getStateInstance/$TOKEN"

# Через публичный api.wa.iqteco.com (как ходит Bitrix24)
curl -sS "https://api.wa.iqteco.com/waInstance1101000001/getStateInstance/$TOKEN"

# Логи контейнера
podman logs --tail 50 wa-1101000001
```
