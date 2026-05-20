#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * wa-replay-webhooks.php — re-deliver webhooks the customer's handler.php
 * accepted (HTTP 200) but failed to actually post into Bitrix24 — for
 * instance because their OAuth refresh_token died and handler.php silently
 * dropped the message after marking it processed on our side.
 *
 * Strategy:
 *   - Pick rows from webhook_outbox by idInstance + time window + status='sent'
 *   - Insert COPIES with status='pending', attempts=0, nextAttemptAt=now
 *   - Container's WebhookSender worker (already running) re-posts them
 *   - handler.php's processed_messages dedupe (legacy fix) makes this safe
 *     to re-run accidentally — duplicates get {"ok":true,"dedup":true}
 *
 * Usage:
 *   php scripts/wa-replay-webhooks.php \
 *      --instance 1101000018 \
 *      --since "2026-05-15 00:00" \
 *      --until "now" \
 *      [--type incomingMessageReceived] \
 *      [--dry-run]
 */

foreach ([__DIR__ . '/../admin/vendor/autoload.php', __DIR__ . '/../vendor/autoload.php'] as $p) {
    if (is_file($p)) { require_once $p; break; }
}

use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;

$opts = getopt('', ['instance:', 'since:', 'until:', 'type:', 'dry-run']);
if (empty($opts['instance']) || empty($opts['since'])) {
    fwrite(STDERR, "Usage: --instance N --since DT [--until DT] [--type T] [--dry-run]\n");
    exit(2);
}
$instance = (string)$opts['instance'];
$since = strtotime((string)$opts['since']);
$until = strtotime((string)($opts['until'] ?? 'now'));
$type  = isset($opts['type']) ? (string)$opts['type'] : 'incomingMessageReceived';
$dryRun = array_key_exists('dry-run', $opts);

if ($since === false || $until === false || $until <= $since) {
    fwrite(STDERR, "Invalid --since / --until\n");
    exit(2);
}

$configFile = is_file(__DIR__ . '/../admin/config/config.php')
    ? __DIR__ . '/../admin/config/config.php'
    : __DIR__ . '/../config/config.php';
$config = require $configFile;

$client = new Client($config['mongo']['uri']);
$db = $client->selectDatabase($config['mongo']['database']);
$outbox = $db->selectCollection('webhook_outbox');

$filter = [
    'idInstance'   => $instance,
    'typeWebhook'  => $type,
    'status'       => 'sent',
    'createdAt'    => [
        '$gte' => new UTCDateTime($since * 1000),
        '$lte' => new UTCDateTime($until * 1000),
    ],
];

$count = $outbox->countDocuments($filter);
fwrite(STDOUT, sprintf(
    "Candidates: %d (instance=%s type=%s window=%s … %s)\n",
    $count, $instance, $type,
    date('c', $since), date('c', $until)
));

if ($count === 0) exit(0);

$sample = $outbox->find($filter, ['limit' => 3, 'sort' => ['createdAt' => 1]]);
fwrite(STDOUT, "Sample (first 3):\n");
foreach ($sample as $doc) {
    $idMessage = $doc['payload']['idMessage'] ?? '(unknown)';
    $when = $doc['createdAt']->toDateTime()->format('c');
    fwrite(STDOUT, "  - {$when}  idMessage={$idMessage}\n");
}

if ($dryRun) {
    fwrite(STDOUT, "DRY RUN — no changes made.\n");
    exit(0);
}

$now = new UTCDateTime();
$replayed = 0;
$skipped = 0;
foreach ($outbox->find($filter) as $doc) {
    $doc = (array)$doc;
    unset($doc['_id'], $doc['sentAt']);
    $doc['status'] = 'pending';
    $doc['attempts'] = 0;
    $doc['nextAttemptAt'] = $now;
    $doc['createdAt'] = $now;
    $doc['replayOf'] = $doc['payload']['idMessage'] ?? null;
    try {
        $outbox->insertOne($doc);
        $replayed++;
    } catch (\Throwable $e) {
        $skipped++;
        fwrite(STDERR, "skip: " . $e->getMessage() . "\n");
    }
}

fwrite(STDOUT, "Replayed: {$replayed}, skipped: {$skipped}\n");
fwrite(STDOUT, "WebhookSender worker in container will pick these up within ~1s each.\n");
