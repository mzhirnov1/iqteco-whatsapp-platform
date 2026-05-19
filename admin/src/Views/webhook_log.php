<?php
use Iqteco\WaAdmin\Services\Csrf;
use Iqteco\WaAdmin\Services\I18n;
use Iqteco\WaAdmin\Services\View;

$badge = function (?string $s): string {
    return match ($s) {
        'sent' => '<span class="badge badge-ok">sent</span>',
        'failed' => '<span class="badge badge-error">failed</span>',
        'pending' => '<span class="badge badge-warn">pending</span>',
        'skipped' => '<span class="badge badge-neutral">skipped</span>',
        null => '<span class="badge badge-neutral">—</span>',
        default => '<span class="badge">' . htmlspecialchars($s) . '</span>',
    };
};
?>
<h1><?= View::e(I18n::t('webhook_log.title', ['id' => $idInstance])) ?></h1>

<p><a href="/instances/<?= View::e($idInstance) ?>">← <?= View::e(I18n::t('webhook_log.back')) ?></a></p>

<?php
$pendingCount = $pendingCount ?? 0;
$failedCount = $failedCount ?? 0;
if ($pendingCount > 0 || $failedCount > 0):
    $retriedJust = isset($_GET['retried']) ? (int)$_GET['retried'] : null;
?>
<div class="webhook-queue-bar">
    <?php if ($retriedJust !== null): ?>
        <div class="alert alert-info">✓ Re-queued <?= $retriedJust ?> failed webhook(s)</div>
    <?php endif; ?>
    <span class="muted">Outbox:</span>
    <span class="badge badge-warn"><?= $pendingCount ?> pending</span>
    <span class="badge badge-error"><?= $failedCount ?> failed</span>
    <?php if ($failedCount > 0): ?>
        <form method="post" action="/instances/<?= View::e($idInstance) ?>/webhooks/retry-all-failed"
              onsubmit="return confirm('<?= View::e(I18n::t('webhook_log.confirm_retry_all', ['n' => $failedCount])) ?>')"
              class="inline-form">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-primary btn-small">🔁 <?= View::e(I18n::t('webhook_log.retry_all_failed', ['n' => $failedCount])) ?></button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<form method="get" class="filter-form">
    <label>
        <span>type</span>
        <select name="type">
            <option value=""><?= View::e(I18n::t('webhook_log.all')) ?></option>
            <?php foreach ($allTypes as $t): ?>
                <option value="<?= View::e($t) ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= View::e($t) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span>status</span>
        <select name="status">
            <option value=""><?= View::e(I18n::t('webhook_log.all')) ?></option>
            <?php foreach ($allStatuses as $s): ?>
                <option value="<?= View::e($s) ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= View::e((string)$s) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="submit" class="btn"><?= View::e(I18n::t('webhook_log.apply')) ?></button>
</form>

<p class="muted"><?= View::e(I18n::t('webhook_log.total', ['n' => $total])) ?></p>

<table class="instances-table">
    <thead>
        <tr>
            <th>time</th>
            <th>type</th>
            <th>status</th>
            <th>http</th>
            <th>attempts</th>
            <th><?= View::e(I18n::t('webhook_log.actions')) ?></th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($items)): ?>
        <tr><td colspan="6" class="empty"><?= View::e(I18n::t('webhook_log.empty')) ?></td></tr>
    <?php else: foreach ($items as $log):
        $logId = (string)$log['_id'];
        $sentAt = isset($log['sentAt']) ? date('Y-m-d H:i:s', $log['sentAt']->toDateTime()->getTimestamp()) : '—';
    ?>
        <tr>
            <td><?= View::e($sentAt) ?></td>
            <td><?= View::e((string)($log['type'] ?? '—')) ?></td>
            <td><?= $badge($log['status'] ?? null) ?></td>
            <td><?= View::e((string)($log['httpCode'] ?? '')) ?></td>
            <td><?= View::e((string)($log['attempts'] ?? '')) ?></td>
            <td class="actions-cell">
                <a href="/instances/<?= View::e($idInstance) ?>/webhooks/<?= View::e($logId) ?>" target="_blank">payload</a>
                <form method="post" action="/instances/<?= View::e($idInstance) ?>/webhooks/<?= View::e($logId) ?>/retry" class="inline-form">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn-link"><?= View::e(I18n::t('webhook_log.retry')) ?></button>
                </form>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>

<?php if ($totalPages > 1): ?>
    <nav class="pagination">
        <?php
        $base = '/instances/' . $idInstance . '/webhooks?type=' . urlencode($filterType) . '&status=' . urlencode($filterStatus);
        for ($p = 1; $p <= $totalPages; $p++): ?>
            <?php if ($p === $page): ?>
                <strong><?= $p ?></strong>
            <?php else: ?>
                <a href="<?= View::e($base) ?>&page=<?= $p ?>"><?= $p ?></a>
            <?php endif; ?>
        <?php endfor; ?>
    </nav>
<?php endif; ?>
