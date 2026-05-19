<?php
declare(strict_types=1);

/**
 * ip-pool-import.php
 *
 * Заливает диапазон IPv6 в коллекцию ip_pool.
 * Использование:
 *   php scripts/ip-pool-import.php
 *   php scripts/ip-pool-import.php --prefix=2a01:4f8:221:2d8d:: --offset=0xa010001 --count=255
 */

require_once __DIR__ . '/../admin/vendor/autoload.php';

use MongoDB\Client;
use MongoDB\Driver\Exception\BulkWriteException;

$config = require __DIR__ . '/../admin/config/config.php';

$opts = getopt('', ['prefix::', 'offset::', 'count::', 'force']);
$prefix = $opts['prefix'] ?? $config['ip_pool']['prefix'];
$offset = isset($opts['offset']) ? intval($opts['offset'], 0) : $config['ip_pool']['reserved_offset'];
$count = (int)($opts['count'] ?? $config['ip_pool']['reserved_count']);
$force = isset($opts['force']);

echo "[ip-pool-import] prefix=$prefix offset=" . sprintf('0x%x', $offset) . " count=$count\n";

$client = new Client($config['mongo']['uri']);
$pool = $client->selectDatabase($config['mongo']['database'])->selectCollection('ip_pool');

$inserted = 0;
$skipped = 0;

for ($i = 0; $i < $count; $i++) {
    $suffix = $offset + $i;
    // Use IPv6 zero-compression. Prefix may or may not end with "::".
    $sep = str_ends_with($prefix, '::') ? '' : (str_ends_with($prefix, ':') ? ':' : '::');
    $rawIpv6 = sprintf('%s%s%x', $prefix, $sep, $suffix);
    $packed = @inet_pton($rawIpv6);
    if ($packed === false) {
        throw new \RuntimeException("Invalid generated IPv6: $rawIpv6");
    }
    $ipv6 = inet_ntop($packed);

    try {
        $pool->insertOne([
            'ipv6' => $ipv6,
            'status' => 'free',
            'idInstance' => null,
            'allocatedAt' => null,
            'createdAt' => new MongoDB\BSON\UTCDateTime(),
        ]);
        $inserted++;
    } catch (BulkWriteException $e) {
        if (strpos($e->getMessage(), 'duplicate key') !== false) {
            $skipped++;
            continue;
        }
        throw $e;
    }
}

echo "[ip-pool-import] inserted=$inserted skipped=$skipped (duplicates)\n";
echo "[ip-pool-import] total free: " . $pool->countDocuments(['status' => 'free']) . "\n";
