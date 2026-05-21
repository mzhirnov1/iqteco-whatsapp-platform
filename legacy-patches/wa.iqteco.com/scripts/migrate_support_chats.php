<?php
/**
 * scripts/migrate_support_chats.php
 * One-shot migration: ensures indexes for the support_chats collection.
 *
 * Idempotent — safe to re-run. createIndex with the same key+options is a no-op.
 *
 * Run on the legacy server:
 *   sudo -u www-data php /var/www/wa.iqteco.com/scripts/migrate_support_chats.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$cfg = require __DIR__ . '/../config.php';
$mongoUri = $cfg['mongodb']['uri'] ?? 'mongodb://127.0.0.1:27017';
$mongoDb  = $cfg['mongodb']['database'] ?? 'b24_app';

$client = new MongoDB\Client($mongoUri);
$col = $client->selectDatabase($mongoDb)->selectCollection('support_chats');

$created = [];
try {
    $col->createIndex(['member_id' => 1], ['unique' => true, 'name' => 'member_id_unique']);
    $created[] = 'member_id_unique';
} catch (Throwable $e) {
    fwrite(STDERR, "member_id_unique: " . $e->getMessage() . "\n");
}
try {
    $col->createIndex(['updatedAt' => -1], ['name' => 'updatedAt_desc']);
    $created[] = 'updatedAt_desc';
} catch (Throwable $e) {
    fwrite(STDERR, "updatedAt_desc: " . $e->getMessage() . "\n");
}
try {
    $col->createIndex(['unread_for_operator' => -1, 'updatedAt' => -1], ['name' => 'unread_then_recent']);
    $created[] = 'unread_then_recent';
} catch (Throwable $e) {
    fwrite(STDERR, "unread_then_recent: " . $e->getMessage() . "\n");
}

echo "support_chats indexes ensured: " . implode(', ', $created) . "\n";
echo "Collection has " . $col->countDocuments([]) . " documents.\n";
