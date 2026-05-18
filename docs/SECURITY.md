# Security

## Поверхности атаки

1. **Public API (`api.wa.iqteco.com`)** — TLS + проверка `apiTokenInstance` в Node middleware (constant-time compare). Token = 50 hex chars из `random_bytes(25)`.
2. **Admin UI (`admin.wa.iqteco.com`)** — TLS + bcrypt пароли + session cookie `httpOnly+secure+samesite=Strict` + CSRF на POST. 2FA (TOTP) — фаза 5+.
3. **Container ↔ Admin REST** — `X-Admin-Token` shared secret (env `ADMIN_TOKEN`, 64 hex). Constant-time compare.
4. **PHP ↔ podman** — sudo allowlist с регулярками на имена контейнеров (`wa-*` only). См. `deploy/sudoers/wa-admin`.
5. **MongoDB** — auth обязательна. Два пользователя: `wa_admin` (full CRUD) и `wa_instance` (CRUD). Application-level изоляция по `idInstance`.

## Container hardening

- Non-root user uid 10001 (`USER wa` в Containerfile)
- `--memory=1g` — лимит RAM, защита от OOM-эскалации
- `--shm-size=1g` — выделенный shm для Chromium (не shared с хостом)
- (планируется) `--read-only` root FS + tmpfs `/tmp`, `--cap-drop=ALL` + `--security-opt=no-new-privileges`
- Network isolation: только nginx может достучаться до `:8080` контейнера через map

## Секреты

- `.env` файлы НИКОГДА не коммитятся (см. `.gitignore`)
- На хосте секреты в `/etc/wa/instance-{id}.env` (0600, owner root)
- Production secrets через systemd `EnvironmentFile=` (не через CLI args, чтобы не светились в `ps`)
- Логи маскируют `apiToken`, `adminToken`, `webhookSecret` (см. `instance/src/lib/Logger.js`)

## Webhook верификация

- `webhookSecret` (опционально) добавляется в header `X-Webhook-Signature: hmac-sha256(secret, body)`
- Клиент должен проверять подпись (документировать в API.md)

## Дефолтные пароли

`deploy/scripts/mongo-init.js` создаёт `wa_admin` и `wa_instance` с placeholder паролями. **Обязательно** заменить:
```bash
ADMIN_PWD=$(openssl rand -hex 16) INSTANCE_PWD=$(openssl rand -hex 16) \
    mongosh < deploy/scripts/mongo-init.js
```

## Threat model

| Угроза | Митигация |
|---|---|
| Утечка apiToken | Один токен = один инстанс. При компрометации — `logout` + ротация в админке |
| Brute-force admin login | Bcrypt + rate limit (фаза 5: fail2ban или middleware) |
| Подделка webhook'а к клиенту | HMAC `webhookSecret` подпись |
| RCE через PHP-FPM | Disable `eval`, `display_errors=Off`, allow_url_include=Off |
| RCE через podman exec | sudo allowlist не разрешает произвольные команды |
| Подделка регистрации инстанса | `X-Admin-Token` constant-time compare |
| Утечка GridFS-сессии | MongoDB auth + только нужные пользователи; шифрование at-rest опционально |
| Side-channel (общий host kernel) | Контейнеры shared kernel — учитывать. Production-grade isolation = full VM (out of scope) |

## Compliance

- Логи `webhook_log` хранятся 30 дней (TTL индекс)
- Контейнеры не сохраняют сообщения вне webhook handler (in-memory LRU 1000 для `lastIncoming/Outgoing`)
- GridFS сессии содержат WhatsApp auth keys — обращаться как с PII
