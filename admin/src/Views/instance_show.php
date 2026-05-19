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
<h1><?= View::e(I18n::t('instance.show.title', ['id' => $id])) ?></h1>

<div class="instance-grid">
    <div class="card">
        <h2><?= View::e(I18n::t('instance.show.info')) ?></h2>
        <dl>
            <dt>idInstance</dt><dd><?= View::e($id) ?></dd>
            <dt>state</dt><dd id="instance-state"><?= View::e($state) ?></dd>
            <dt>phone</dt><dd><?= View::e((string)($instance['phoneNumber'] ?? '—')) ?></dd>
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
        <a class="btn" href="/instances/<?= View::e($id) ?>/webhooks"><?= View::e(I18n::t('instance.show.webhook_log')) ?></a>
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

            if (data.qr) {
                if (data.kind === 'pairing_code') {
                    qrCont.innerHTML = '';
                    pairEl.style.display = 'block';
                    pairEl.textContent = data.qr;
                    titleEl.textContent = '<?= View::e(I18n::t('instance.show.pairing_code_title')) ?>';
                } else {
                    pairEl.style.display = 'none';
                    qrCont.innerHTML = '<img src="data:image/png;base64,' + data.qr + '" alt="qr" class="qr-img">';
                    titleEl.textContent = '<?= View::e(I18n::t('instance.show.qr_title')) ?>';
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
