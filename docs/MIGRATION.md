# Миграция с green-api.com на iqteco

## Общая стратегия

Существующая интеграция в `/var/www/wa.iqteco.com` уже поддерживает кастомный `apiUrl` в `helpers/GreenApi.php`. Миграция = смена URL для каждого клиента, без изменения кода интеграции.

`idInstance` и `apiToken` генерируются в том же формате (11 цифр + 50 hex), webhook'и совместимы 1-в-1 — `handler.php` обрабатывает их без модификаций.

## Phase 6 plan

### Подготовка
1. Параллельное сосуществование: green-api.com и iqteco работают одновременно
2. Создать в админке iqteco тестовый инстанс
3. Снять с green-api живые webhook'и всех 5 типов (`stateInstanceChanged`, `incomingMessageReceived`, `outgoingMessageReceived`, `outgoingAPIMessageReceived`, `outgoingMessageStatus`) — будут эталоном для `GreenApiMapper`

### Тестовый клиент
1. Создать iqteco-инстанс
2. У клиента в БД: обновить `apiUrl = 'https://api.wa.iqteco.com'`, `idInstance` и `apiTokenInstance` на новые
3. Клиент должен переавторизоваться в WhatsApp на телефоне (новый QR, отлогинит green-api сессию)
4. Наблюдение неделю, сравнить логи `b24_greenapi_multinstance.log` до/после

### Bulk-миграция
- Скрипт `scripts/migrate-from-green-api.php`
- Вход: CSV `clientId,oldIdInstance,oldApiToken,phoneNumber,webhookUrl`
- Выход: CSV `clientId,newIdInstance,newApiToken,qrPngBase64,qrCodeExpiresAt`
- В админке кнопка "Send re-auth email" — рассылает клиенту ссылку на QR (TTL 24ч)

### Rollback plan
- Старая запись клиента в БД сохраняется с `apiUrl=https://api.green-api.com` (deactivated, не удалена)
- При проблеме — апдейт обратно занимает 1 sql query

### Отключение green-api
- Когда все клиенты на iqteco >2 недель без жалоб
- Отписать green-api подписки
- Закрыть тикеты
