<?php
use Iqteco\WaAdmin\Services\View;

/** @var ?array $status */
/** @var ?string $error */
/** @var string $sourceUrl */

$num = fn($v) => number_format((int)$v, 0, '.', ' ');

// Funnel steps in order, so each row can be read against the one above it.
$steps = [
    'installed'        => 'Installed the app',
    'trial'            => 'Started the trial',
    'instance_created' => 'Created an instance',
    'provisioned'      => 'Got a real slot (not a payment stub)',
    'authorized'       => 'Linked a WhatsApp number',
    'messaged'         => 'Exchanged real messages',
    'paid'             => 'Paid',
];
?>
<h1>Connector — wa.iqteco.com</h1>

<?php if ($error): ?>
    <div class="alert alert-error">
        <strong>Cannot read connector status.</strong>
        <?= View::e($error) ?>
        <?php if ($sourceUrl): ?><br><small><?= View::e($sourceUrl) ?></small><?php endif; ?>
    </div>
<?php else: ?>

<p><small>Source: <?= View::e($sourceUrl) ?> · generated <?= View::e((string)($status['generatedAt'] ?? '—')) ?></small></p>

<div class="dashboard-stats">
    <div class="stat-card">
        <div class="stat-label">Live portals</div>
        <div class="stat-value"><?= $num($status['live']['total'] ?? 0) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Uninstalled</div>
        <div class="stat-value"><?= $num($status['uninstalled'] ?? 0) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Linked numbers now</div>
        <div class="stat-value"><?= $num($status['live']['with_authorized_instance'] ?? 0) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Payments ever</div>
        <div class="stat-value"><?= $num($status['transactions'] ?? 0) ?></div>
    </div>
</div>

<h2>Install funnel</h2>
<p><small>Counts every portal ever installed, including uninstalled ones (their snapshot is kept on uninstall).</small></p>
<table>
    <thead><tr><th>Step</th><th>Portals</th><th>Of installs</th><th>Kept from previous step</th></tr></thead>
    <tbody>
    <?php
    $funnel = (array)($status['funnel'] ?? []);
    $installed = max(1, (int)($funnel['installed'] ?? 0));
    $prev = null;
    foreach ($steps as $key => $label):
        $v = (int)($funnel[$key] ?? 0);
        $ofAll = round($v * 100 / $installed);
        $ofPrev = ($prev === null || $prev === 0) ? null : round($v * 100 / $prev);
    ?>
        <tr>
            <td><?= View::e($label) ?></td>
            <td><strong><?= $num($v) ?></strong></td>
            <td><?= $ofAll ?>%</td>
            <td><?= $ofPrev === null ? '—' : $ofPrev . '%' ?></td>
        </tr>
    <?php $prev = $v; endforeach; ?>
    </tbody>
</table>

<h2>OAuth health</h2>
<?php $live = (array)($status['live'] ?? []); ?>
<p>
    <?= $num($live['needs_relink'] ?? 0) ?> of <?= $num($live['total'] ?? 0) ?> live portals need a reinstall,
    <?= $num($live['token_expired'] ?? 0) ?> have an expired token.
    A dead refresh token cannot be repaired from our side — only the customer reinstalling the app clears it.
</p>
<?php if (!empty($status['needsRelink'])): ?>
<table>
    <thead><tr><th>Portal</th><th>Failed refreshes</th><th>Token expired</th><th>Last error</th></tr></thead>
    <tbody>
    <?php foreach ((array)$status['needsRelink'] as $row): ?>
        <tr>
            <td><?= View::e((string)($row['domain'] ?? '—')) ?></td>
            <td><?= $num($row['failures'] ?? 0) ?></td>
            <td><?= View::e(substr((string)($row['tokenExpires'] ?? '—'), 0, 10)) ?></td>
            <td><small><?= View::e((string)($row['lastError'] ?? '')) ?></small></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<h2>Instances on live portals</h2>
<?php $st = (array)($status['instanceStates'] ?? []); $pay = (array)($status['paymentStates'] ?? []); ?>
<?php if (!$st): ?>
    <p><small>No instances on live portals.</small></p>
<?php else: ?>
<p>
    <?php foreach ($st as $k => $v): ?>
        <span class="badge <?= $k === 'authorized' ? 'badge-ok' : ($k === 'deleted' ? 'badge-error' : 'badge-warn') ?>"><?= View::e((string)$k) ?>: <?= $num($v) ?></span>
    <?php endforeach; ?>
</p>
<p>
    <?php foreach ($pay as $k => $v): ?>
        <span class="badge badge-neutral"><?= View::e((string)$k) ?>: <?= $num($v) ?></span>
    <?php endforeach; ?>
</p>
<?php endif; ?>

<h2>Activity</h2>
<?php $act = (array)($status['activity'] ?? []); ?>
<p>
    <?= $num($act['dialogSessions'] ?? 0) ?> dialog sessions ·
    <?= $num($act['freePlanChats'] ?? 0) ?> free-plan chats ·
    last message: <strong><?= View::e(substr((string)($act['lastMessageAt'] ?? '—'), 0, 16)) ?></strong>
</p>

<h2>Installs and churn by month</h2>
<table>
    <thead><tr><th>Month</th><th>Installed</th><th>Uninstalled</th><th>Net</th></tr></thead>
    <tbody>
    <?php foreach ((array)($status['byMonth'] ?? []) as $month => $row):
        $in = (int)($row['installed'] ?? 0); $out = (int)($row['uninstalled'] ?? 0); ?>
        <tr>
            <td><?= View::e((string)$month) ?></td>
            <td><?= $num($in) ?></td>
            <td><?= $num($out) ?></td>
            <td><?= ($in - $out) > 0 ? '+' : '' ?><?= $num($in - $out) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<h2>Instance pool</h2>
<p><?= $num($status['pool']['ready'] ?? 0) ?> ready of <?= $num($status['pool']['total'] ?? 0) ?> total.</p>

<?php endif; ?>
