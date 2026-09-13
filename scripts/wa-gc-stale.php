#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * wa-gc-stale.php — сборщик брошенных инстансов, возвращает IPv6 в пул.
 *
 * Зачем: пул `ip_pool` конечен, а каждая регистрация в CRM забирает адрес
 * (инстанс поднимается заранее, до QR). 97% регистраций QR не сканируют,
 * и адрес оставался занят навсегда — 12.09.2026 пул дошёл до нуля, новым
 * регистрациям номер перестал выделяться. Разбор: 186 из 254 занятых адресов
 * держали инстансы, к которым ни разу не привязали телефон.
 *
 * Правила (только НЕ тёплый пул `__pool__` и только хосты из --hosts):
 *   R1  никогда не спарен (нет phoneNumber/wid) и создан раньше, чем --days назад
 *       → удалить. IPv6 в карантин на 1 час, а не на сутки: этот адрес не был
 *       залогиненным устройством, охлаждать репутацию нечего.
 *   R2  был спарен, но state ∉ {authorized, starting} и lastSeen старше --days
 *       → удалить (сессия после LOGOUT всё равно стёрта resetSession; повторная
 *       привязка идёт новым инстансом). Карантин обычный, 24 ч.
 *   Состояния pending_delete/deleted не трогаем — ими занимается pending-delete.
 *
 * Хосты вебхука вне --hosts (iQSMM, чужие бренды, коннектор Битрикс24) только
 * перечисляются в логе: у их приложений свои записи об инстансах, и удаление
 * без зачистки на той стороне оставит «Подключить» дёргать мёртвый id.
 *
 * Использование:
 *   php scripts/wa-gc-stale.php                      # dry-run
 *   php scripts/wa-gc-stale.php --apply
 *   php scripts/wa-gc-stale.php --apply --days=7 --hosts=crm.iqteco.com
 */

foreach ([__DIR__ . '/../admin/vendor/autoload.php', __DIR__ . '/../vendor/autoload.php'] as $p) {
    if (is_file($p)) { require_once $p; break; }
}

use Iqteco\WaAdmin\Services\InstanceManager;
use Iqteco\WaAdmin\Services\IpPoolManager;
use Iqteco\WaAdmin\Services\Logger;
use Iqteco\WaAdmin\Services\MongoClient;
use Iqteco\WaAdmin\Services\NftablesManager;
use Iqteco\WaAdmin\Services\NginxMapManager;
use Iqteco\WaAdmin\Services\PodmanRunner;
use MongoDB\BSON\UTCDateTime;

$configFile = is_file(__DIR__ . '/../admin/config/config.php')
    ? __DIR__ . '/../admin/config/config.php'
    : __DIR__ . '/../config/config.php';
$config = require $configFile;

$opts  = getopt('', ['apply', 'days::', 'hosts::']);
$apply = isset($opts['apply']);
$days  = max(1, (int)($opts['days'] ?? 7));
$hosts = array_filter(array_map('trim', explode(',', (string)($opts['hosts'] ?? 'crm.iqteco.com'))));

$logger = new Logger('gc-stale', '/var/log/wa/gc-stale.log');
$ipPool = new IpPoolManager($config, $logger);
$manager = new InstanceManager(
    $config, $logger, $ipPool,
    new PodmanRunner($config, $logger),
    new NginxMapManager($config, $logger),
    new NftablesManager($config, $logger),
);

$db = MongoClient::db($config);
$instances = $db->selectCollection('instances');
$pool = $db->selectCollection('ip_pool');

$cutoff = new UTCDateTime((time() - $days * 86400) * 1000);
$cursor = $instances->find([
    'state' => ['$nin' => ['deleted', 'pending_delete', 'authorized', 'starting']],
    'ownerId' => ['$ne' => '__pool__'],
]);

$plan = ['R1' => [], 'R2' => [], 'foreign' => [], 'young' => 0];
foreach ($cursor as $inst) {
    $id = (string)$inst['idInstance'];
    $host = (string)(parse_url((string)($inst['webhookUrl'] ?? ''), PHP_URL_HOST) ?? '');
    $paired = trim((string)($inst['phoneNumber'] ?? '')) !== '' || trim((string)($inst['wid'] ?? '')) !== '';
    $createdAt = $inst['createdAt'] ?? null;
    $lastSeen  = $inst['lastSeen'] ?? null;

    if (!in_array($host, $hosts, true)) {
        $plan['foreign'][$host ?: '(none)'] = ($plan['foreign'][$host ?: '(none)'] ?? 0) + 1;
        continue;
    }
    if (!$paired) {
        if ($createdAt instanceof UTCDateTime && $createdAt > $cutoff) { $plan['young']++; continue; }
        $plan['R1'][] = $id;
        continue;
    }
    $seen = $lastSeen instanceof UTCDateTime ? $lastSeen : $createdAt;
    if ($seen instanceof UTCDateTime && $seen > $cutoff) { $plan['young']++; continue; }
    $plan['R2'][] = $id;
}

$stats = $ipPool->stats();
echo sprintf("[gc-stale] %s days=%d hosts=%s | pool free=%d assigned=%d quarantine=%d total=%d\n",
    $apply ? 'APPLY' : 'DRY-RUN', $days, implode(',', $hosts),
    $stats['free'], $stats['assigned'], $stats['quarantine'], $stats['total']);
echo sprintf("  R1 never paired, older than %dd: %d\n", $days, count($plan['R1']));
echo sprintf("  R2 paired but dead, lastSeen older than %dd: %d\n", $days, count($plan['R2']));
echo sprintf("  kept (younger than %dd): %d\n", $days, $plan['young']);
foreach ($plan['foreign'] as $h => $n) echo "  skipped foreign host {$h}: {$n}\n";

if (!$apply) {
    echo "  dry-run: nothing changed (add --apply)\n";
    exit(0);
}

$done = ['R1' => 0, 'R2' => 0, 'failed' => 0];
foreach (['R1', 'R2'] as $rule) {
    foreach ($plan[$rule] as $id) {
        $inst = $manager->find($id);
        $ipv6 = (string)($inst['ipv6'] ?? '');
        try {
            $ok = $manager->delete($id);
            if (!$ok) { $done['failed']++; continue; }
            $instances->updateOne(['idInstance' => $id], ['$set' => ['deletedReason' => 'gc_stale_' . $rule]]);
            if ($rule === 'R1' && $ipv6 !== '') {
                // Адрес не был устройством WhatsApp — карантин 1 час вместо суток.
                $pool->updateOne(
                    ['ipv6' => $ipv6, 'status' => 'quarantine'],
                    ['$set' => ['reuseAfter' => new UTCDateTime((time() + 3600) * 1000)]]
                );
            }
            $done[$rule]++;
            $logger->info('gc-stale: deleted', ['idInstance' => $id, 'rule' => $rule, 'ipv6' => $ipv6]);
        } catch (\Throwable $e) {
            $done['failed']++;
            $logger->error('gc-stale: delete failed', ['idInstance' => $id, 'err' => $e->getMessage()]);
        }
    }
}
$reclaimed = $ipPool->reclaim();
$stats = $ipPool->stats();
echo sprintf("  deleted R1=%d R2=%d failed=%d | reclaimed now=%d | pool free=%d assigned=%d quarantine=%d\n",
    $done['R1'], $done['R2'], $done['failed'], $reclaimed, $stats['free'], $stats['assigned'], $stats['quarantine']);
$logger->info('gc-stale tick', $done + ['reclaimed' => $reclaimed] + $stats);
