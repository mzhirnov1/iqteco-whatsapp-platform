#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * wa-traffic-poll.php — запускается каждую минуту через systemd timer
 * (wa-traffic-poller.timer). Опрашивает nftables counters и обновляет
 * MongoDB traffic + проставляет trafficStatus на инстансах.
 *
 * Использование:
 *   /usr/bin/php /var/www/admin.wa.iqteco.com/scripts/wa-traffic-poll.php
 */

require_once __DIR__ . '/../admin/vendor/autoload.php';

use Iqteco\WaAdmin\Services\Logger;
use Iqteco\WaAdmin\Services\NftablesManager;
use Iqteco\WaAdmin\Services\TrafficCollector;

$config = require __DIR__ . '/../admin/config/config.php';
$logger = new Logger('traffic-poll', '/var/log/wa/traffic-poll.log');

try {
    $nft = new NftablesManager($config, $logger);
    $collector = new TrafficCollector($config, $logger, $nft);
    $result = $collector->poll();
    $logger->info('poll ok', $result);
} catch (\Throwable $e) {
    $logger->error('poll exception', ['err' => $e->getMessage()]);
    exit(2);
}
