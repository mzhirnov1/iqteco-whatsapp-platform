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
исходящие webhook'и (15 типов), Partner API (5 методов) и
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

## wweb.js: пин 942d236, проглоченный ready и аватары (31.08.2026)

Три поломки одного дня, все — рассинхрон с живой WA Web:

- **Свежие QR-привязки падали с LOGOUT** через минуты после скана (963954240820,
  79991635928 ×2): WA Web делает SPA-навигацию после логина, старый пин 2dc9466
  инжектился повторно (шесть `client authenticated` подряд) — WhatsApp отвечал
  LOGOUT. Лечится апстримом `1780711 prevent duplicate ready events on SPA
  re-injection`; пин поднят до `942d236`. Восстановленные сессии не падали —
  только новые привязки.
- **На RemoteAuth-восстановлении новый пин глотает `ready`**: сессия CONNECTED,
  а `ctx.state.authorized` не взводится — каждый маршрут отвечает 466, и бэкап
  не пишется (`canBackup` смотрит на этот флаг), так что со временем сессия
  обречена. Достройка — в Heartbeat (`onConnectedNotReady`): CONNECTED без
  ready → вызываем onReady сами. В логе это `Heartbeat: CONNECTED but ready
  never fired — invoking onReady by hand`.
- **`client.getProfilePicUrl` бросает минифицированное «r» на любом чате**
  (WA Web убрал WAWebContactProfilePicThumbBridge) — инбокс CRM остался без
  аватаров. Фолбэк в `routes/getAvatar.js` повторяет несмерженный wwebjs PR
  #201880 (ProfilePicThumb: get, затем find); выкинуть после мержа.

Грабли деплоя: `wa-rolling-update.sh --build` делает pre-flight ДО сборки —
упавший pre-flight означает, что образ НЕ пересобрался, хотя `--build` стоял.
Долгую сборку запускать `systemd-run --unit ... podman build ...` (переживает
обрыв ssh), катить затем `wa-recover.php <id>` — сессии восстанавливаются из
GridFS-бэкапа. Полный sha для пина брать из
`api.github.com/repos/wwebjs/whatsapp-web.js/commits/<short>` — дописанный
руками хвост тихо превращается в 404 + фолбэк npm на ssh-clone.

## LID: сломанная сессия — не «номера нет в WhatsApp»

`instance/src/lib/jid.js` резолвит адрес перед отправкой: сначала `getNumberId`,
затем — если не вышло — чат по `<digits>@lid`. Оба вызова раньше глотали
исключение, и ответ был один: `not_on_whatsapp`. Но когда WA Web Store
разваливается (та же поломка, из-за которой падает `getChats`), бросают **все**
запросы — и живой чат объявлялся несуществующим номером, а ответ клиенту
пропадал молча. Так 29.08.2026 потерялся ответ в LID-чат у номера 963954240820.

- Теперь бросок отличается от честного «не нашли»: если ни один lookup не
  ответил, шлём на `@lid` и даём `sendMessage` сообщить настоящую ошибку.
  `not_on_whatsapp` остаётся, только когда оба ответили и ничего не нашли.
- Тест — `instance/test/jid.test.js` (в том числе сессия, у которой всё бросает).
- Номер LID-чата платформа отдаёт потребителям в `senderData.senderPn`
  (`onMessage.js`, кеш на 5000 пар) — читать надо именно его, `chatId` там
  внутренний идентификатор. Массовый резолв — `POST getContactLidAndPhone`
  (до 50 адресов), им же CRM чинит старые диалоги.

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

## Чёрный ящик страницы и Chromium из bookworm (04.09.2026)

Свежие привязки гибнут через 1–6 минут после скана с голым `disconnected: LOGOUT`
(30.08–04.09: 13 из 20 первых привязок, включая тест менеджера с российским номером;
восстановленные сессии не страдают). `LOGOUT` в wweb.js — это `Cmd.logout` самого
WhatsApp Web, то есть решение WhatsApp, а причина видна только внутри страницы.

- `instance/src/lib/PageForensics.js` — кольцевой буфер (консоль страницы, `pageerror`,
  `requestfailed`, навигации main-frame) плюс хуки внутри WA Web через
  `evalOnNewDoc` wweb.js (переживают SPA-навигации): `Socket.state/stream/hasSynced`,
  `Stream.mode/info`, аргументы `Cmd.logout` / `logout_from_bridge`. После
  `authenticated`/`ready` 15 минут снимается скриншот раз в 5 с (держим два последних).
- `onDisconnected` (LOGOUT/UNPAIRED) и `onAuthFailure` зовут `forensics.dump()` **до**
  `resetSession` — потом страницы уже нет. Дамп: в лог (`forensics: dump`, хвост 120
  событий, состояние сокета, `bodyText` страницы) и в Mongo `iqteco_wa.forensics`
  (TTL 7 дней, скриншоты `shots[].jpeg` как Binary). Смотреть:
  `db.forensics.find({idInstance:'…'},{shots:0}).sort({at:-1})`, картинку вытащить
  `mongosh --eval` + `Buffer.from(doc.shots[0].jpeg.buffer)` в файл.
- Env: `FORENSICS_WINDOW_MS` (900000), `FORENSICS_SHOT_INTERVAL_MS` (5000).
- Образ переведён на `node:20-bookworm-slim`: в bullseye chromium заморожен на 120
  (декабрь 2023), а WA Web выпускает сборку каждый день. User-Agent теперь берётся из
  `chromium --version` при старте (`detectChromeMajor` в `client.js`), чтобы заголовок
  не отставал от бинарника, как отставал год.
- Тесты: `cd instance && npm ci && npx vitest run` (`test/PageForensics.test.js`).
