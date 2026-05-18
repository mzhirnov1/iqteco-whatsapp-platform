<?php
use Iqteco\WaAdmin\Services\I18n;
use Iqteco\WaAdmin\Services\View;

$stateBadge = function (?string $state): string {
    return match ($state) {
        'authorized' => '<span class="badge badge-ok">authorized</span>',
        'auth_needed', 'notAuthorized' => '<span class="badge badge-warn">' . htmlspecialchars((string)$state) . '</span>',
        'starting', 'pairing' => '<span class="badge badge-neutral">' . htmlspecialchars((string)$state) . '</span>',
        'sleepMode', 'blocked' => '<span class="badge badge-error">' . htmlspecialchars((string)$state) . '</span>',
        null => '<span class="badge badge-neutral">unknown</span>',
        default => '<span class="badge">' . htmlspecialchars($state) . '</span>',
    };
};

$relTime = function ($utc): string {
    if (!$utc) return '—';
    $ts = $utc instanceof \MongoDB\BSON\UTCDateTime ? $utc->toDateTime()->getTimestamp() : (int)$utc;
    $diff = time() - $ts;
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return date('Y-m-d H:i', $ts);
};
?>
<h1><?= View::e(I18n::t('dashboard.title')) ?></h1>

<div class="dashboard-stats">
    <div class="stat-card">
        <div class="stat-label"><?= View::e(I18n::t('dashboard.instances')) ?></div>
        <div class="stat-value"><?= count($instances) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">IPv6 pool (free)</div>
        <div class="stat-value"><?= (int)$stats['free'] ?> / <?= (int)$stats['total'] ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Assigned</div>
        <div class="stat-value"><?= (int)$stats['assigned'] ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Quarantine</div>
        <div class="stat-value"><?= (int)$stats['quarantine'] ?></div>
    </div>
</div>

<table class="instances-table">
    <thead>
        <tr>
            <th>idInstance</th>
            <th><?= View::e(I18n::t('dashboard.state')) ?></th>
            <th><?= View::e(I18n::t('dashboard.phone')) ?></th>
            <th>IPv6</th>
            <th><?= View::e(I18n::t('dashboard.last_seen')) ?></th>
            <th><?= View::e(I18n::t('dashboard.actions')) ?></th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($instances)): ?>
        <tr><td colspan="6" class="empty"><?= View::e(I18n::t('dashboard.empty')) ?> <a href="/instances/new"><?= View::e(I18n::t('dashboard.create_first')) ?></a></td></tr>
    <?php else: foreach ($instances as $i): ?>
        <tr>
            <td><a href="/instances/<?= View::e((string)$i['idInstance']) ?>"><?= View::e((string)$i['idInstance']) ?></a></td>
            <td><?= $stateBadge($i['state'] ?? null) ?></td>
            <td><?= View::e((string)($i['phoneNumber'] ?? '—')) ?></td>
            <td class="ipv6"><?= View::e((string)($i['ipv6'] ?? '—')) ?></td>
            <td><?= View::e($relTime($i['lastSeen'] ?? null)) ?></td>
            <td><a href="/instances/<?= View::e((string)$i['idInstance']) ?>"><?= View::e(I18n::t('dashboard.view')) ?></a></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
