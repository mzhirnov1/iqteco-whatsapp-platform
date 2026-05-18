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
</script>
