#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * wa-recover.php <idInstance> [<idInstance> ...]
 * Recreate (stop -> rm -> run, preserving the stored static IPv6 + env) the
 * given instances via the platform's own InstanceManager::reboot — the ONLY
 * correct way to restart these containers. A raw `podman restart`/`start` does
 * NOT re-apply --ip6, leaving the container off the network (nginx 502).
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
$logger = new Logger('recover', '/var/log/wa/recover.log');

$manager = new InstanceManager(
    $config, $logger,
    new IpPoolManager($config, $logger),
    new PodmanRunner($config, $logger),
    new NginxMapManager($config, $logger),
    new NftablesManager($config, $logger),
);

$ids = array_slice($argv, 1);
if (!$ids) { fwrite(STDERR, "usage: wa-recover.php <id> [<id> ...]\n"); exit(1); }

foreach ($ids as $id) {
    try {
        $ok = $manager->reboot($id);
        fwrite(STDOUT, "$id reboot=" . ($ok ? 'OK' : 'FAIL') . "\n");
    } catch (\Throwable $e) {
        fwrite(STDOUT, "$id reboot=EXCEPTION " . $e->getMessage() . "\n");
    }
}
