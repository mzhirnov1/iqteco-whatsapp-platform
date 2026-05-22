<?php
use Iqteco\WaAdmin\Services\Csrf;
use Iqteco\WaAdmin\Services\I18n;
use Iqteco\WaAdmin\Services\View;

$id = (string)$instance['idInstance'];
$state = $instance['state'] ?? 'unknown';
$lastSeen = $instance['lastSeen'] ?? null;
$lastSeenStr = $lastSeen instanceof \MongoDB\BSON\UTCDateTime
    ? date('Y-m-d H:i:s', $lastSeen->toDateTime()->getTimestamp())
    : '—';
?>
<?php $instanceType = (string)($instance['type'] ?? 'whatsapp'); ?>
<h1>
    <?= View::e(I18n::t('instance.show.title', ['id' => $id])) ?>
    <span class="badge badge-type-<?= View::e($instanceType) ?>"><?= $instanceType === 'telegram' ? 'Telegram' : 'WhatsApp' ?></span>
</h1>

<nav class="instance-subnav">
    <a class="btn btn-primary btn-lg" href="/instances/<?= View::e($id) ?>/chat"><?= $instanceType === 'telegram' ? '💬 Open Telegram chat' : '💬 Open WhatsApp Web' ?></a>
    <a class="btn" href="/instances/<?= View::e($id) ?>/webhooks">📋 Webhook log</a>
    <span class="badge badge-state-<?= View::e((string)$state) ?>"><?= View::e((string)$state) ?></span>
    <?php if (!empty($instance['ipv6'])): ?>
        <span class="muted">IPv6: <code><?= View::e((string)$instance['ipv6']) ?></code></span>
    <?php endif; ?>
</nav>

<div class="instance-grid">
    <div class="card">
        <h2><?= View::e(I18n::t('instance.show.info')) ?></h2>
        <?php
        $owner = (string)($instance['ownerId'] ?? '');
        $ownerDisplay = $owner === '__pool__' ? '🟡 warm pool (готов к выдаче)' : ($owner === '' ? '—' : $owner);
        ?>
        <dl>
            <dt>idInstance</dt><dd><?= View::e($id) ?></dd>
            <dt>state</dt><dd id="instance-state"><?= View::e($state) ?></dd>
            <dt>phone</dt><dd><?= View::e((string)($instance['phoneNumber'] ?? '—')) ?></dd>
            <dt>owner / portal</dt><dd><code><?= View::e($ownerDisplay) ?></code></dd>
            <dt>IPv6</dt><dd class="ipv6"><?= View::e((string)($instance['ipv6'] ?? '—')) ?></dd>
            <dt><?= View::e(I18n::t('instance.show.last_seen')) ?></dt><dd><?= View::e($lastSeenStr) ?></dd>
            <dt>webhookUrl</dt><dd><?= View::e((string)($instance['webhookUrl'] ?? '—')) ?></dd>
            <dt>auth method</dt><dd><?= View::e((string)($instance['authMethod'] ?? 'qr')) ?></dd>
        </dl>
    </div>

    <div class="card">
        <h2 id="qr-title"><?= View::e(I18n::t('instance.show.qr_title')) ?></h2>
        <div id="qr-container" class="qr-container">
            <div id="qr-loading"><?= View::e(I18n::t('instance.show.qr_waiting')) ?></div>
        </div>
        <p id="pairing-code" class="pairing-code" style="display:none;"></p>
        <div id="tg-code-form" class="tg-auth-form" style="display:none;">
            <label>
                <span><?= View::e(I18n::t('instance.show.tg_code_label') ?: 'Code from Telegram') ?></span>
                <input type="text" id="tg-code-input" inputmode="numeric" autocomplete="one-time-code" placeholder="12345">
            </label>
            <button type="button" id="tg-code-submit" class="btn btn-primary"><?= View::e(I18n::t('instance.show.tg_code_submit') ?: 'Submit code') ?></button>
            <small id="tg-code-status"></small>
        </div>
        <div id="tg-2fa-form" class="tg-auth-form" style="display:none;">
            <label>
                <span><?= View::e(I18n::t('instance.show.tg_2fa_label') ?: 'Two-factor password') ?></span>
                <input type="password" id="tg-2fa-input" autocomplete="current-password">
            </label>
            <button type="button" id="tg-2fa-submit" class="btn btn-primary"><?= View::e(I18n::t('instance.show.tg_2fa_submit') ?: 'Submit password') ?></button>
            <small id="tg-2fa-status"></small>
        </div>
        <small id="qr-status"></small>
    </div>
</div>

<div class="card">
    <h2><?= View::e(I18n::t('instance.show.traffic')) ?></h2>
    <div id="traffic-bars">
        <div class="traffic-row"><span class="traffic-label">Hour</span><div class="traffic-bar"><div id="bar-hour" class="traffic-fill"></div></div><span id="val-hour" class="traffic-val">—</span></div>
        <div class="traffic-row"><span class="traffic-label">Day</span><div class="traffic-bar"><div id="bar-day" class="traffic-fill"></div></div><span id="val-day" class="traffic-val">—</span></div>
        <div class="traffic-row"><span class="traffic-label">Month</span><div class="traffic-bar"><div id="bar-month" class="traffic-fill"></div></div><span id="val-month" class="traffic-val">—</span></div>
    </div>
</div>

<div class="card">
    <h2>
        <?= View::e(I18n::t('instance.show.logs')) ?>
        <button type="button" id="logs-refresh" class="btn btn-small"><?= View::e(I18n::t('instance.show.logs_refresh')) ?></button>
    </h2>
    <pre id="logs-output" class="logs-output">—</pre>
</div>

<div class="card">
    <h2><?= View::e(I18n::t('instance.show.actions')) ?></h2>
    <div class="actions">
        <form method="post" action="/instances/<?= View::e($id) ?>/reboot">
            <?= Csrf::field() ?>
            <button type="submit" class="btn"><?= View::e(I18n::t('instance.show.reboot')) ?></button>
        </form>
        <form method="post" action="/instances/<?= View::e($id) ?>/logout">
            <?= Csrf::field() ?>
            <button type="submit" class="btn"><?= View::e(I18n::t('instance.show.logout')) ?></button>
        </form>
        <form method="post" action="/instances/<?= View::e($id) ?>/delete" onsubmit="return confirm('<?= View::e(I18n::t('instance.show.delete_confirm')) ?>')">
            <?= Csrf::field() ?>
            <label class="inline"><input type="checkbox" name="banned" value="1"> <?= View::e(I18n::t('instance.show.banned_label')) ?></label>
            <button type="submit" class="btn btn-danger"><?= View::e(I18n::t('instance.show.delete')) ?></button>
        </form>
    </div>
</div>

<script>
(function () {
    const id = <?= json_encode($id) ?>;
    const stateEl = document.getElementById('instance-state');
    const titleEl = document.getElementById('qr-title');
    const qrCont = document.getElementById('qr-container');
    const pairEl = document.getElementById('pairing-code');
    const statusEl = document.getElementById('qr-status');

    async function poll() {
        try {
            const res = await fetch('/api/instances/' + id + '/qr-poll', { credentials: 'same-origin' });
            if (!res.ok) {
                statusEl.textContent = 'poll error: ' + res.status;
                return;
            }
            const data = await res.json();
            if (data.state) stateEl.textContent = data.state;

            if (data.state === 'authorized') {
                qrCont.innerHTML = '<div class="qr-authorized"><?= View::e(I18n::t('instance.show.authorized')) ?></div>';
                pairEl.style.display = 'none';
                statusEl.textContent = '';
                return; // stop polling
            }

            const tgCodeForm = document.getElementById('tg-code-form');
            const tg2faForm = document.getElementById('tg-2fa-form');

            const kind = data.kind || 'qr';
            const showCode = kind === 'tg_phone_code';
            const show2fa = kind === 'tg_2fa_password';
            tgCodeForm.style.display = showCode ? 'block' : 'none';
            tg2faForm.style.display = show2fa ? 'block' : 'none';

            if (showCode) {
                qrCont.innerHTML = '';
                pairEl.style.display = 'none';
                titleEl.textContent = 'Введите код из Telegram';
            } else if (show2fa) {
                qrCont.innerHTML = '';
                pairEl.style.display = 'none';
                titleEl.textContent = 'Введите пароль 2FA';
            } else if (data.qr) {
                if (kind === 'pairing_code') {
                    qrCont.innerHTML = '';
                    pairEl.style.display = 'block';
                    pairEl.textContent = data.qr;
                    titleEl.textContent = '<?= View::e(I18n::t('instance.show.pairing_code_title')) ?>';
                } else {
                    pairEl.style.display = 'none';
                    // PNG already includes the "data:image/png;base64," prefix when produced by tg-instance;
                    // wa-instance sends raw base64 — accept both.
                    const src = data.qr.startsWith('data:') ? data.qr : ('data:image/png;base64,' + data.qr);
                    qrCont.innerHTML = '<img src="' + src + '" alt="qr" class="qr-img">';
                    titleEl.textContent = kind === 'tg_qr' ? 'Telegram QR' : '<?= View::e(I18n::t('instance.show.qr_title')) ?>';
                }
                if (data.expiresAt) {
                    const expIn = data.expiresAt - Math.floor(Date.now() / 1000);
                    statusEl.textContent = expIn > 0 ? ('refresh in ' + expIn + 's') : 'expired';
                }
            } else {
                qrCont.innerHTML = '<div class="qr-loading"><?= View::e(I18n::t('instance.show.qr_waiting')) ?></div>';
            }
        } catch (e) {
            statusEl.textContent = 'poll exception: ' + e.message;
        } finally {
            setTimeout(poll, 3000);
        }
    }
    poll();
})();

(function () {
    const id = <?= json_encode($id) ?>;
    const fmt = (b) => {
        if (b < 1024) return b + ' B';
        if (b < 1024*1024) return (b/1024).toFixed(1) + ' KB';
        if (b < 1024*1024*1024) return (b/1024/1024).toFixed(1) + ' MB';
        return (b/1024/1024/1024).toFixed(2) + ' GB';
    };
    async function refreshTraffic() {
        try {
            const res = await fetch('/api/instances/' + id + '/traffic', { credentials: 'same-origin' });
            if (!res.ok) return;
            const d = await res.json();
            const limits = d.limits || {};
            for (const k of ['hour', 'day', 'month']) {
                const used = (d[k]?.bytesIn || 0) + (d[k]?.bytesOut || 0);
                const lim = limits[k] || 1;
                const pct = Math.min(100, (used / lim) * 100);
                const bar = document.getElementById('bar-' + k);
                const val = document.getElementById('val-' + k);
                if (bar) {
                    bar.style.width = pct + '%';
                    bar.classList.remove('over-80', 'over-100');
                    if (pct >= 100) bar.classList.add('over-100');
                    else if (pct >= 80) bar.classList.add('over-80');
                }
                if (val) val.textContent = fmt(used) + ' / ' + fmt(lim) + ' (' + pct.toFixed(0) + '%)';
            }
        } catch (e) { /* ignore */ }
        setTimeout(refreshTraffic, 30000);
    }
    refreshTraffic();
})();

(function () {
    const id = <?= json_encode($id) ?>;
    const codeBtn = document.getElementById('tg-code-submit');
    const codeInput = document.getElementById('tg-code-input');
    const codeStatus = document.getElementById('tg-code-status');
    const pwBtn = document.getElementById('tg-2fa-submit');
    const pwInput = document.getElementById('tg-2fa-input');
    const pwStatus = document.getElementById('tg-2fa-status');

    async function submit(body, statusEl, btnEl) {
        if (!statusEl || !btnEl) return;
        btnEl.disabled = true;
        statusEl.textContent = 'sending…';
        try {
            const res = await fetch('/api/instances/' + id + '/tg-auth-submit', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            const txt = await res.text();
            statusEl.textContent = (res.ok ? 'OK: ' : 'error ' + res.status + ': ') + txt;
        } catch (e) {
            statusEl.textContent = 'exception: ' + e.message;
        } finally {
            btnEl.disabled = false;
        }
    }

    if (codeBtn) codeBtn.addEventListener('click', function () {
        const v = (codeInput.value || '').trim();
        if (!v) { codeStatus.textContent = 'empty'; return; }
        submit({ code: v }, codeStatus, codeBtn);
    });
    if (pwBtn) pwBtn.addEventListener('click', function () {
        const v = pwInput.value || '';
        if (!v) { pwStatus.textContent = 'empty'; return; }
        submit({ password: v }, pwStatus, pwBtn);
    });
})();

(function () {
    const id = <?= json_encode($id) ?>;
    const out = document.getElementById('logs-output');
    const btn = document.getElementById('logs-refresh');
    async function load() {
        out.textContent = 'loading…';
        try {
            const res = await fetch('/api/instances/' + id + '/logs?tail=200', { credentials: 'same-origin' });
            if (!res.ok) { out.textContent = 'error: ' + res.status; return; }
            const d = await res.json();
            out.textContent = d.logs || '(empty)';
        } catch (e) {
            out.textContent = 'exception: ' + e.message;
        }
    }
    btn.addEventListener('click', load);
    load();
})();
</script>
