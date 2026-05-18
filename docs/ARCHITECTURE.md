# Architecture

## Обзор

iqteco-whatsapp-platform — self-hosted замена green-api.com. Цели:

1. **Изоляция риска** — каждый WhatsApp аккаунт в своём Podman-контейнере с выделенным IPv6.
2. **Совместимость** — публичный REST API 100% совместим с Green API (`/waInstance{id}/{method}/{token}`), миграция = смена `apiUrl` в конфиге клиента.
3. **Stateless контейнеры** — Chromium-сессии хранятся в MongoDB GridFS (RemoteAuth), любой контейнер можно убить и пересоздать.
4. **Минимум latency для webhook'ов** — контейнер шлёт webhook клиенту напрямую (push only), админка только хранит URL.

## Components

### Node.js instance (per WhatsApp account)

- Base image: `node:18-bullseye-slim` + chromium + ffmpeg
- Run as non-root (uid 10001), `--read-only` root FS + tmpfs `/tmp`
- Port `8080` HTTP, no public exposure (только через nginx)
- IPv6 из подсети `2a01:4f8:221:2d8d::/64`, фиксированный per instance
- Сессия: RemoteAuth → MongoDB GridFS bucket `wa_sessions`
- Регистрируется в админке при старте (POST `/admin/api/instances/{id}/register`)
- Heartbeat каждые 30 сек
- Webhook'и шлёт напрямую клиенту (URL получается из админки при старте)
- Состояния (Green API): `authorized`, `notAuthorized`, `starting`, `sleepMode`, `yellowCard`

### PHP admin (admin.wa.iqteco.com)

- PHP 8.1 + Composer, без фреймворка (стиль существующего проекта iqteco)
- MongoDB driver `mongodb/mongodb` v1.x
- Управление инстансами: create/reboot/logout/delete (через `podman` + `sudo` allowlist)
- IPv6 pool с атомарным allocate/release
- REST API для контейнеров (auth: `X-Admin-Token` shared secret)
- UI: dashboard, инстанс с live-QR, traffic monitoring

### MongoDB

Коллекции:
- `instances` — основная таблица инстансов
- `ip_pool` — выделенные/свободные IPv6
- `wa_sessions` (GridFS) — Chromium сессии RemoteAuth
- `webhook_log` — журнал отправленных webhook'ов (TTL 30 дней)
- `webhook_outbox` — очередь pending webhook'ов с retry
- `traffic` — счётчики byte-in/byte-out per instance
- `users` — пользователи админки
- `_counters` — atomic counter для генерации `idInstance`

### nginx

- `api.wa.iqteco.com` — reverse proxy с map'ом `idInstance → [ipv6]:8080`
- `admin.wa.iqteco.com` — PHP-FPM 8.1 в /var/www/admin.wa.iqteco.com
- Wildcard TLS `*.wa.iqteco.com` (DNS-01 challenge)

### systemd

- `wa-instance@{idInstance}.service` — шаблон, EnvironmentFile `/etc/wa/instance-{id}.env`
- `wa-traffic-poller.timer` — каждую минуту парсит `nft -j list counters`

## Data flow

### Создание инстанса
```
Admin UI → InstanceManager.create()
  → IpPoolManager.allocate()                          [MongoDB findOneAndUpdate]
  → _counters.findOneAndUpdate({idInstance}, $inc)    [генерация id]
  → instances.insertOne                                [state=auth_needed]
  → PodmanRunner.run(...)                              [sudo podman run -d ...]
  → регенерация /etc/nginx/wa-instances.map + reload   [sudo nginx -s reload]
  → ожидание /api/instances/{id}/register от контейнера (timeout 60с)
```

### Старт контейнера
```
podman run → app.js
  → MongoClient.connect()
  → GET /admin/api/instances/{id}/config              [webhookUrl, settings]
  → Express :8080 (auth middleware: проверка id+token)
  → new RemoteAuth({ store: new MongoStore(gridFS) })
  → client.initialize()
  → POST /admin/api/instances/{id}/register
  → Heartbeat.start (every 30s)
  → event handlers: qr → admin POST /qr; message → WebhookSender; ack → status webhook
```

### Отправка сообщения (клиент → инстанс)
```
Bitrix24 → POST https://api.wa.iqteco.com/waInstance{id}/sendMessage/{token}
  → nginx → lookup wa-instances.map → [ipv6]:8080
  → routes/sendMessage.js
    → auth.js (compare id+token с env)
    → client.sendMessage(chatId, body)
    → respond Green API JSON
```

### Webhook (инстанс → клиент)
```
client.on('message', ...) → events/onMessage.js
  → GreenApiMapper.toIncomingMessageReceived(msg) → JSON
  → WebhookSender.enqueue({type, payload})
    → POST webhookUrl (timeout 10s, retry 5x exp backoff)
  → success: webhook_log[status=sent]
  → fail: webhook_outbox.upsert (retry from disk after restart)
```

## Security boundaries

| Граница | Mechanism |
|---|---|
| Public → API | TLS + apiTokenInstance (constant-time compare в Node) |
| Public → Admin UI | TLS + session cookie + CSRF |
| Container → Admin REST | TLS + `X-Admin-Token` shared secret |
| PHP → Podman | sudo allowlist (специфические команды) |
| PHP → MongoDB | dedicated user `wa_admin` |
| Container → MongoDB | dedicated user `wa_instance` |
| Inter-container | podman network isolation (`--opt isolate=false` для гейтвея только) |

## Limits

- **Hetzner traffic** (Robot): 100MB/h, 500MB/d, 2GB/мес на IPv6
- **Memory**: 1GB per контейнер (`--memory=1g`)
- **shm**: 1GB (`--shm-size=1g`) для Chromium
- **GridFS**: 16MB лимит на чанк (стандарт), сессия ~20-60MB → несколько чанков
- **Webhook retry**: 5 попыток 1с/5с/30с/2м/10м

## Open questions (Phase 0 verification)

См. [план](../../.claude/plans/green-synthetic-crayon.md) раздел "Открытые вопросы".
