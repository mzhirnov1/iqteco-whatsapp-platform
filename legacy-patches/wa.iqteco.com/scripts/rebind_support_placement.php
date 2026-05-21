<?php
/**
 * scripts/rebind_support_placement.php
 *
 * One-shot: walk every installed Bitrix24 portal in the `portals` collection
 * and bind PLACEMENT=DEFAULT to /placements/support_chat_intercom.php so
 * existing portals get the Support tab without reinstalling the app.
 *
 * Idempotent: Bitrix24 returns ERROR_PLACEMENT_HANDLER_ALREADY_BINDED if the
 * exact HANDLER URL is already bound — we treat that as success and continue.
 * For portals that have a different DEFAULT handler bound, B24 errors with
 * ERROR_PLACEMENT_ALREADY_BINDED — we log + skip (operator can investigate).
 *
 * Skips portals whose OAuth tokens are flagged needs_relink (they can't be
 * called anyway).
 *
 * Usage:
 *   php /var/www/wa.iqteco.com/scripts/rebind_support_placement.php          # all
 *   php /var/www/wa.iqteco.com/scripts/rebind_support_placement.php --dry    # report only
 *   php /var/www/wa.iqteco.com/scripts/rebind_support_placement.php MEMBER_ID  # single
 */

declare(strict_types=1);

$appConfig = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/Logger.php';
require_once __DIR__ . '/../helpers/BxApi.php';

$logger = new Logger('rebind_' . uniqid());
$db = Database::getInstance();

$dryRun = false;
$onlyMember = null;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry' || $a === '--dry-run') { $dryRun = true; continue; }
    if ($a !== '') $onlyMember = $a;
}

$placementUrl = 'https://wa.iqteco.com/placements/support_chat_intercom.php';
$payload = [
    'PLACEMENT'   => 'DEFAULT',
    'HANDLER'     => $placementUrl,
    'TITLE'       => 'Support',
    'DESCRIPTION' => 'Ask our AI assistant or a human operator',
    'LANG_ALL'    => [
        'en' => ['TITLE' => 'Support',   'DESCRIPTION' => 'Ask our AI assistant or a human operator'],
        'ru' => ['TITLE' => 'Поддержка', 'DESCRIPTION' => 'Спросите AI-ассистента или живого оператора'],
    ],
];

$portals = $onlyMember
    ? array_filter([$db->getSettingsByMemberId($onlyMember)])
    : $db->getAllPortals();

$ok = $skip = $fail = $alreadyBound = 0;
$failList = [];

foreach ($portals as $portal) {
    $portal = (array)$portal;
    $memberId = (string)($portal['member_id'] ?? '');
    if ($memberId === '') { $skip++; continue; }
    if (!empty($portal['needs_relink'])) {
        $logger->log("skip member=$memberId reason=needs_relink");
        $skip++; continue;
    }
    if (empty($portal['access_token']) && empty($portal['refresh_token'])) {
        $logger->log("skip member=$memberId reason=no_tokens");
        $skip++; continue;
    }
    if ($dryRun) {
        $logger->log("DRY would bind member=$memberId domain=" . ($portal['domain'] ?? ''));
        $ok++; continue;
    }

    try {
        $bx = new BxApi($portal, $appConfig, $db, $logger);
        $res = $bx->callMethod('placement.bind', $payload);
        $resStr = is_array($res) ? json_encode($res, JSON_UNESCAPED_UNICODE) : (string)$res;
        if (stripos($resStr, 'ERROR_PLACEMENT_HANDLER_ALREADY_BINDED') !== false) {
            $alreadyBound++;
            $logger->log("noop member=$memberId already_bound_same_handler");
        } elseif (stripos($resStr, 'ERROR_PLACEMENT_ALREADY_BINDED') !== false) {
            $failList[] = [$memberId, 'different_handler_already_bound', $resStr];
            $fail++;
            $logger->log("warn member=$memberId different handler already bound: " . substr($resStr, 0, 200));
        } elseif (stripos($resStr, 'error') !== false) {
            $failList[] = [$memberId, 'b24_error', $resStr];
            $fail++;
            $logger->log("fail member=$memberId b24: " . substr($resStr, 0, 200));
        } else {
            $ok++;
            $logger->log("ok member=$memberId");
        }
    } catch (Throwable $e) {
        $failList[] = [$memberId, 'exception', $e->getMessage()];
        $fail++;
        $logger->log("exception member=$memberId: " . $e->getMessage());
    }

    // Tiny breather between calls so we don't hammer B24 OAuth refresh.
    usleep(120 * 1000);
}

echo "rebind_support_placement summary:\n";
echo "  bound (new):       $ok\n";
echo "  already same:      $alreadyBound\n";
echo "  skipped:           $skip\n";
echo "  failed:            $fail\n";
if (!empty($failList)) {
    echo "  failures:\n";
    foreach ($failList as [$m, $kind, $msg]) {
        echo "    - $m [$kind] " . substr($msg, 0, 200) . "\n";
    }
}
echo $dryRun ? "(dry-run; no API calls)\n" : "done.\n";
