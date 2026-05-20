#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * wa-pending-delete.php — runs hourly via wa-pending-delete.timer.
 * Walks `state='pending_delete' AND markedForDeleteAt <= now` and tears
 * each container down (podman stop+rm, MongoStore.delete, IPv6 quarantine).
 */

foreach ([__DIR__ . '/../admin/vendor/autoload.php', __DIR__ . '/../vendor/autoload.php'] as $p) {
    if (is_file($p)) { require_once $p; break; }
}

use Iqteco\WaAdmin\Services\InstanceManager;
use Iqteco\WaAdmin\Services\IpPoolManager;
use Iqteco\WaAdmin\Services\Logger;
use Iqteco\WaAdmin\Services\NftablesManager;
use Iqteco\WaAdmin\Services\NginxMapManager;
use Iqteco\WaAdmin\Services\PodmanRunner;

$configFile = is_file(__DIR__ . '/../admin/config/config.php')
    ? __DIR__ . '/../admin/config/config.php'
    : __DIR__ . '/../config/config.php';
$config = require $configFile;

$logger = new Logger('pending-delete', '/var/log/wa/pending-delete.log');

try {
    $manager = new InstanceManager(
        $config, $logger,
        new IpPoolManager($config, $logger),
        new PodmanRunner($config, $logger),
        new NginxMapManager($config, $logger),
        new NftablesManager($config, $logger),
    );
    $reaped = $manager->reapPendingDeletes();
    $logger->info('pending-delete tick', ['reaped' => $reaped]);
} catch (\Throwable $e) {
    $logger->error('pending-delete exception', ['err' => $e->getMessage()]);
    exit(2);
}
