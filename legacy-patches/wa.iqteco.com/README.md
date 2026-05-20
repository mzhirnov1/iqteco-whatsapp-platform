# Legacy patches deployed to wa.iqteco.com (`/var/www/wa.iqteco.com/`)

These files were patched on the legacy production host as part of Phase 9
(Bitrix24 OAuth resilience). They are kept here as version-controlled
copies — the source of truth is the running file on the host.

## What's in this directory

| File | Change |
|---|---|
| `BxApi.php` | Single-flight refresh + CAS-update + invalid_grant peer-rotation detection. Replaces ad-hoc `refreshAccessToken()` with `ensureValidAccessToken()` that uses the new Mongo lock helpers. Also retries on `expired_token` mid-request. |
| `install.php` | Double-install guard (skip if last install < 30 s ago). Atomic save with reset of `needs_relink`, `consecutive_refresh_failures`, `last_refresh_error`. Log token prefixes. |
| `handler.php` | INBOUND-branch dedupe via `processed_messages` collection so replays are idempotent. |
| `cron.php` | Health-check loop (NOT proactive refresh). Surfaces portals where `consecutive_refresh_failures>=3` or `needs_relink=true`. Optional alert email via `$appConfig['alert_email']`. |
| `db.php` | New helpers: `acquireRefreshLock`, `releaseRefreshLock`, `casUpdateTokens`, `markNeedsRelink`, `portalsNeedingRelink`, `rememberProcessedMessage`. |

## Mongo indexes (created once)

```js
db.processed_messages.createIndex({memberId:1, idMessage:1}, {unique:true});
db.processed_messages.createIndex({savedAt:1}, {expireAfterSeconds:604800});
db.portals.createIndex({refresh_lock_until:1}, {sparse:true});
```

## Deploy

Backup originals first:
```
TS=$(date +%Y%m%d-%H%M%S)
cd /var/www/wa.iqteco.com
for f in helpers/BxApi.php install.php handler.php cron.php db.php; do
  cp "$f" "$f.bak.$TS"
done
```

Then scp the patched files in place.
