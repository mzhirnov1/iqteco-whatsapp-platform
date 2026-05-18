# iqteco-whatsapp-platform

Самостоятельная платформа WhatsApp-инстансов с REST API 100% совместимым с Green API.

Каждый WhatsApp-аккаунт работает в отдельном Podman-контейнере на базе [wwebjs/whatsapp-web.js](https://github.com/wwebjs/whatsapp-web.js) с выделенным IPv6 (Hetzner /64). Сессии хранятся в MongoDB GridFS — контейнеры stateless. PHP+MongoDB админка управляет жизненным циклом инстансов.

## Структура

```
instance/   Node.js контейнер с whatsapp-web.js + Green API совместимый HTTP сервер
admin/      PHP 8.1 админка (admin.wa.iqteco.com) + REST API для контейнеров
deploy/     Containerfile, nginx, systemd, bootstrap-скрипты
scripts/    Утилиты (ip-pool-import, миграция, бэкап)
docs/       Документация (ARCHITECTURE, API, RUNBOOK, SECURITY, MIGRATION)
```

## Quick start

```bash
# 1. На хосте wa.iqteco.com (один раз)
sudo bash deploy/scripts/bootstrap.sh
sudo bash deploy/scripts/podman-upgrade.sh
sudo bash deploy/scripts/podman-network-init.sh
mongosh < deploy/scripts/mongo-init.js
php scripts/ip-pool-import.php

# 2. Build образа
sudo bash deploy/scripts/build-image.sh

# 3. Установить админку
cd admin && composer install
sudo cp deploy/nginx/admin.wa.iqteco.com.conf /etc/nginx/sites-enabled/
sudo cp deploy/nginx/api.wa.iqteco.com.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

См. `docs/RUNBOOK.md` для оперативной информации и `docs/ARCHITECTURE.md` для деталей.

## Лицензия

Proprietary. © iqteco.
