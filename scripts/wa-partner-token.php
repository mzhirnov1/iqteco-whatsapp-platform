#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * wa-partner-token.php — manage partner API tokens stored in MongoDB
 * (collection partner_tokens). Multiple active tokens are supported in
 * addition to the single .env PARTNER_TOKEN, which stays valid for
 * backward compatibility.
 *
 * Tokens are stored as sha256 hashes — the raw secret is shown only once,
 * at creation. Authentication lives in
 * admin/src/Controllers/PartnerApiController.php::partnerTokenValid().
 *
 * Usage:
 *   php wa-partner-token.php add [label]       generate + store a token, print it ONCE
 *   php wa-partner-token.php list              list tokens (label, state, dates)
 *   php wa-partner-token.php revoke <label>    revoke every token with that label
 */

// Universal autoload: works both from the repo (scripts/ sibling to admin/)
// and from the deployed copy under /var/www/admin.wa.iqteco.com/scripts/.
foreach ([__DIR__ . '/../admin/vendor/autoload.php', __DIR__ . '/../vendor/autoload.php'] as $p) {
    if (is_file($p)) { require_once $p; break; }
}

use Iqteco\WaAdmin\Services\MongoClient;

$configFile = is_file(__DIR__ . '/../admin/config/config.php')
    ? __DIR__ . '/../admin/config/config.php'
    : __DIR__ . '/../config/config.php';
$config = require $configFile;

$col = MongoClient::db($config)->selectCollection('partner_tokens');

$cmd = $argv[1] ?? 'list';

switch ($cmd) {
    case 'add':
        $label = (string)($argv[2] ?? 'partner');
        $token = bin2hex(random_bytes(32)); // 64 hex chars, same shape as the .env token
        $col->insertOne([
            'tokenHash'  => hash('sha256', $token),
            'label'      => $label,
            'revoked'    => false,
            'createdAt'  => new \MongoDB\BSON\UTCDateTime(),
            'lastUsedAt' => null,
        ]);
        fwrite(STDERR, "Partner token created (label: {$label}). Store it now — it is NOT recoverable:\n");
        echo $token . "\n";
        break;

    case 'list':
        $rows = 0;
        foreach ($col->find([], ['sort' => ['createdAt' => 1]]) as $d) {
            $created = isset($d['createdAt']) && $d['createdAt'] instanceof \MongoDB\BSON\UTCDateTime
                ? $d['createdAt']->toDateTime()->format('Y-m-d H:i') : '-';
            $used = isset($d['lastUsedAt']) && $d['lastUsedAt'] instanceof \MongoDB\BSON\UTCDateTime
                ? $d['lastUsedAt']->toDateTime()->format('Y-m-d H:i') : '-';
            $state = !empty($d['revoked']) ? 'REVOKED' : 'active';
            printf("%-28s %-8s created=%s  lastUsed=%s  hash=%s…\n",
                (string)($d['label'] ?? '-'), $state, $created, $used,
                substr((string)($d['tokenHash'] ?? ''), 0, 12));
            $rows++;
        }
        if ($rows === 0) {
            fwrite(STDERR, "no DB partner tokens (the .env PARTNER_TOKEN still works)\n");
        }
        break;

    case 'revoke':
        $label = (string)($argv[2] ?? '');
        if ($label === '') {
            fwrite(STDERR, "usage: wa-partner-token.php revoke <label>\n");
            exit(1);
        }
        $r = $col->updateMany(
            ['label' => $label],
            ['$set' => ['revoked' => true, 'revokedAt' => new \MongoDB\BSON\UTCDateTime()]]
        );
        echo "revoked {$r->getModifiedCount()} token(s) with label '{$label}'\n";
        break;

    default:
        fwrite(STDERR, "usage: wa-partner-token.php add [label] | list | revoke <label>\n");
        exit(1);
}
