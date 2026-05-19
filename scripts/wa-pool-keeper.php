#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * wa-pool-keeper.php — keeps the InstancePool topped up.
 * Run by systemd timer wa-pool-keeper.timer (every minute).
 */

// Universal autoload: works both when run from /root/whatsapp-platform/scripts/
// (repo) and from /var/www/admin.wa.iqteco.com/scripts/ (deployed).
foreach ([__DIR__ . '/../admin/vendor/autoload.php', __DIR__ . '/../vendor/autoload.php'] as $p) {
    if (is_file($p)) { require_once $p; break; }
}

use Iqteco\WaAdmin\Services\InstanceManager;
use Iqteco\WaAdmin\Services\InstancePool;
use Iqteco\WaAdmin\Services\IpPoolManager;
use Iqteco\WaAdmin\Services\Logger;
use Iqteco\WaAdmin\Services\NftablesManager;
use Iqteco\WaAdmin\Services\NginxMapManager;
use Iqteco\WaAdmin\Services\PodmanRunner;

$configFile = is_file(__DIR__ . '/../admin/config/config.php')
    ? __DIR__ . '/../admin/config/config.php'
    : __DIR__ . '/../config/config.php';
$config = require $configFile;
$logger = new Logger('pool-keeper', '/var/log/wa/pool-keeper.log');

try {
    $manager = new InstanceManager(
        $config, $logger,
        new IpPoolManager($config, $logger),
        new PodmanRunner($config, $logger),
        new NginxMapManager($config, $logger),
        new NftablesManager($config, $logger),
    );
    $pool = new InstancePool($config, $logger, $manager);

    $stats = $pool->stats();
    $created = $pool->keepWarm();
    $logger->info('pool-keeper tick', ['created' => $created] + $stats);
} catch (\Throwable $e) {
    $logger->error('pool-keeper exception', ['err' => $e->getMessage()]);
    exit(2);
}
