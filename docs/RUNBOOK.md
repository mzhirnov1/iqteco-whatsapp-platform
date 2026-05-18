# Runbook

Оперативная информация для администраторов wa.iqteco.com.

## Подключение

```bash
ssh root@wa.iqteco.com
```

Пути:
- Репозиторий (deploy): `/var/www/admin.wa.iqteco.com/` (PHP app)
- Логи контейнеров: `podman logs wa-{idInstance}` или `journalctl -u wa-instance@{id}`
- Логи nginx: `/var/log/nginx/{api,admin}.wa.iqteco.com.access.log`
- Логи admin: `/var/log/wa/admin.log`
- MongoDB: `mongosh "mongodb://wa_admin@127.0.0.1:27017/iqteco_wa"`

## Сценарии

### Создание нового инстанса
1. Зайти на `https://admin.wa.iqteco.com/dashboard` (логин)
2. "Create instance" → выбрать auth (QR или pairing code), заполнить webhookUrl
3. Дождаться QR / кода, аутентифицироваться в WhatsApp на телефоне
4. State должен перейти в `authorized` в течение минуты

### Инстанс перешёл в `disconnected`
1. Проверить `podman ps -a --filter label=wa-instance={id}` — контейнер живой?
2. `podman logs --tail 200 wa-{id}` — есть `auth_failure`?
3. Если сессия отозвана — re-QR через админку
4. Если контейнер мёртв — `systemctl start wa-instance@{id}`

### IPv6 заблокирован WhatsApp
1. В админке инстансу проставить `state=banned`
2. IPv6 в `ip_pool` переводится в `quarantine` (30 дней)
3. Создать новый инстанс — он получит другой IPv6
4. (Опционально) попробовать через 30 дней

### Полная перезагрузка хоста
1. `systemctl stop wa-instance@*` — остановит все
2. `reboot`
3. После запуска: `systemctl start wa-instance@{id1} wa-instance@{id2} ...`
4. Восстановление сессий из GridFS происходит автоматически (RemoteAuth)

### Восстановление после потери MongoDB
1. Восстановить дамп из бэкапа (`/var/backup/mongo-*.gz`)
2. Перезапустить все контейнеры (`systemctl restart wa-instance@*`)
3. Сессии должны восстановиться (зависит от свежести бэкапа)

## Troubleshooting

### "Permission denied (publickey)" к GHCR
- Проверить что `GITHUB_TOKEN` секрет имеет `read:packages` scope
- На хосте: `podman login ghcr.io -u USERNAME -p TOKEN`

### IPv6 контейнера не пингуется снаружи
```bash
# Проверить routing
ip -6 route show
# Если /64 bridged а не routed — запустить:
bash deploy/scripts/ipv6-ndp-init.sh
```

### Chromium не стартует в контейнере
- `--shm-size=1g` обязателен (по умолчанию 64MB мало)
- `--security-opt seccomp=unconfined` если есть `ENOSYS` на newer kernels
- Проверить лог: `podman logs wa-{id} 2>&1 | grep -i chrome`

### `getStateInstance` возвращает `starting` бесконечно
- WhatsApp Web мог обновить версию (выходит ~раз в 2 недели)
- Update `whatsapp-web.js` в `instance/package.json`:
  ```
  cd instance && npm update whatsapp-web.js
  ```
- Rebuild и push образа

## Бэкапы

```bash
# MongoDB полный дамп (cron daily)
mongodump --uri="mongodb://wa_admin:PWD@127.0.0.1/iqteco_wa" --gzip --archive=/var/backup/mongo-$(date +%F).gz

# GridFS sessions (отдельно для удобства)
php scripts/session-export.php --out=/var/backup/wa-sessions
```

## Алерты

- Traffic >80% suite ловится в `traffic_warning` бейдже на инстансе. См. `wa-traffic-poller.service`.
- `lastSeen` старше 5 минут — контейнер мёртв, проверить `systemctl status wa-instance@{id}`.

## Связаться

- Существующая Bitrix-интеграция: `/var/www/wa.iqteco.com` (не трогать без необходимости)
- Логи интеграции: `/var/www/wa.iqteco.com/logs/b24_greenapi_multinstance.log`
