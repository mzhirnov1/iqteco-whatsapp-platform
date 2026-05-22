<?php
/**
 * support_action.php
 * Single JSON router for the in-app support chat.
 *
 * Customer endpoints (auth: trusted member_id from B24 placement context):
 *   POST act=send_customer  member_id=… text=…    → store msg, call OpenAI, return ai reply
 *   POST act=poll_customer  member_id=… sinceTs=… → return new messages (oldest→newest)
 *
 * Operator endpoints (auth: Bearer support_shared_secret):
 *   GET  act=list_chats                        → all chats with portal info, sorted by activity
 *   POST act=poll_operator  member_id=… sinceTs=… → return new messages + meta
 *   POST act=send_operator  member_id=… text=… operator_email=… → store operator msg
 *   POST act=set_mode       member_id=… mode=ai|human operator_email=… → switch mode
 *
 * All responses are JSON. Errors return non-2xx with {"error":"..."}.
 */

if (!is_dir(__DIR__ . '/logs')) { @mkdir(__DIR__ . '/logs', 0775, true); }
ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('error_log', __DIR__ . '/logs/php_error.log');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/Logger.php';
require_once __DIR__ . '/helpers/SupportAi.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: application/json; charset=utf-8');

$appConfig = require __DIR__ . '/config.php';
$logger = new Logger('support_' . uniqid());
$db = Database::getInstance();

// We need direct access to the Mongo client for the support_chats collection.
// Database doesn't expose it, so we construct our own client (same uri/db as Database).
$mongoUri = $appConfig['mongodb']['uri'] ?? 'mongodb://127.0.0.1:27017';
$mongoDb  = $appConfig['mongodb']['database'] ?? 'b24_app';
try {
    $client = new MongoDB\Client($mongoUri);
    $supportChats = $client->selectDatabase($mongoDb)->selectCollection('support_chats');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'mongo_unavailable']);
    exit;
}

function jerr(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function jok(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function nowMs(): int { return (int)(microtime(true) * 1000); }

function bsonNow(): \MongoDB\BSON\UTCDateTime { return new \MongoDB\BSON\UTCDateTime(); }

function msgId(): string {
    try { return bin2hex(random_bytes(8)); }
    catch (Throwable $e) { return uniqid('m_', true); }
}

/**
 * Pull plain history newest-last from a chat doc, capped to last N.
 * Each result item: ['role'=>'customer'|'ai'|'operator', 'text'=>string, 'ts'=>int(ms)]
 */
function unwrapHistory(?array $chatDoc, int $limit = 40): array {
    if (!$chatDoc || empty($chatDoc['messages'])) return [];
    $msgs = $chatDoc['messages'];
    if ($msgs instanceof \MongoDB\Model\BSONArray) {
        $msgs = iterator_to_array($msgs);
    }
    $out = [];
    foreach ($msgs as $m) {
        $m = (array)$m;
        $ts = isset($m['ts']) && $m['ts'] instanceof \MongoDB\BSON\UTCDateTime
            ? (int)$m['ts']->toDateTime()->format('Uv')
            : 0;
        $out[] = [
            'id'   => (string)($m['id']   ?? ''),
            'role' => (string)($m['role'] ?? ''),
            'text' => (string)($m['text'] ?? ''),
            'ts'   => $ts,
            'operator_email' => (string)($m['operator_email'] ?? ''),
        ];
    }
    if (count($out) > $limit) {
        $out = array_slice($out, -$limit);
    }
    return $out;
}

function portalCtxFor(array $portal): array {
    // Pick the most recently active (or first) instance for context.
    $instances = $portal['instances'] ?? [];
    if ($instances instanceof \MongoDB\Model\BSONArray) {
        $instances = iterator_to_array($instances);
    }
    $primary = null;
    foreach ($instances as $inst) {
        $inst = (array)$inst;
        if (!is_numeric($inst['idInstance'] ?? null)) continue;
        if (($inst['paymentStatus'] ?? null) === 'deleted') continue;
        $primary = $inst; break;
    }
    return [
        'domain'        => (string)($portal['domain']    ?? ''),
        'idInstance'    => (string)($primary['idInstance']    ?? ''),
        'state'         => (string)($primary['state']         ?? 'no-instance'),
        'paymentStatus' => (string)($primary['paymentStatus'] ?? 'none'),
        'planDisplay'   => (string)($primary['planDisplay']   ?? ($primary['tariffPlanId'] ?? '')),
        'locale'        => (string)($portal['locale']    ?? 'en'),
    ];
}

/**
 * Simple per-member_id rate limit: max 10 customer-side calls per 60s.
 * Backed by Mongo so it works across PHP-FPM workers without shared memory.
 */
function customerRateLimitOk(\MongoDB\Collection $col, string $memberId): bool {
    $key = 'rate:customer:' . $memberId;
    $oneMinAgo = new \MongoDB\BSON\UTCDateTime((time() - 60) * 1000);
    $col->updateOne(
        ['member_id' => $memberId],
        ['$pull' => ['_rate_customer' => ['$lt' => $oneMinAgo]]]
    );
    $doc = $col->findOne(['member_id' => $memberId], ['projection' => ['_rate_customer' => 1]]);
    $hits = 0;
    if ($doc && !empty($doc['_rate_customer'])) {
        $arr = $doc['_rate_customer'];
        if ($arr instanceof \MongoDB\Model\BSONArray) $arr = iterator_to_array($arr);
        $hits = count($arr);
    }
    if ($hits >= 10) return false;
    $col->updateOne(
        ['member_id' => $memberId],
        ['$push' => ['_rate_customer' => bsonNow()]],
        ['upsert' => true]
    );
    return true;
}

$act = (string)($_REQUEST['act'] ?? '');
if ($act === '') jerr(400, 'act required');

// =============================================================
// OPERATOR ACTIONS (require Bearer auth)
// =============================================================
$operatorActs = ['list_chats', 'poll_operator', 'send_operator', 'set_mode', 'resolve_portals'];
if (in_array($act, $operatorActs, true)) {
    $expected = (string)($appConfig['support_shared_secret'] ?? '');
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        if (empty($auth) && !empty($h['Authorization'])) $auth = $h['Authorization'];
    }
    if ($expected === '' || !preg_match('/^Bearer\s+(.+)$/', $auth, $m) || !hash_equals($expected, trim($m[1]))) {
        jerr(401, 'bad bearer');
    }

    if ($act === 'list_chats') {
        // All support chats, joined with portal data, sorted by last activity.
        $cursor = $supportChats->find(
            [],
            ['sort' => ['updatedAt' => -1], 'limit' => 500,
             'projection' => ['_rate_customer' => 0]]
        );
        $portalsCol = $client->selectDatabase($mongoDb)->selectCollection('portals');
        $out = [];
        foreach ($cursor as $chat) {
            $chat = (array)$chat;
            $portal = $portalsCol->findOne(['member_id' => (string)$chat['member_id']]);
            $portal = $portal ? (array)$portal : [];
            $ctx = portalCtxFor($portal);
            $lastMsg = '';
            $lastRole = '';
            if (!empty($chat['messages'])) {
                $msgs = $chat['messages'];
                if ($msgs instanceof \MongoDB\Model\BSONArray) $msgs = iterator_to_array($msgs);
                $tail = end($msgs);
                if ($tail) {
                    $tail = (array)$tail;
                    $lastMsg = (string)($tail['text'] ?? '');
                    $lastRole = (string)($tail['role'] ?? '');
                }
            }
            $out[] = [
                'member_id'           => (string)$chat['member_id'],
                'domain'              => $ctx['domain'],
                'idInstance'          => $ctx['idInstance'],
                'state'               => $ctx['state'],
                'paymentStatus'       => $ctx['paymentStatus'],
                'planDisplay'         => $ctx['planDisplay'],
                'locale'              => $ctx['locale'],
                'mode'                => (string)($chat['mode'] ?? 'ai'),
                'unread_for_operator' => (int)($chat['unread_for_operator'] ?? 0),
                'last_message'        => mb_substr($lastMsg, 0, 140),
                'last_role'           => $lastRole,
                'updatedAt'           => isset($chat['updatedAt']) && $chat['updatedAt'] instanceof \MongoDB\BSON\UTCDateTime
                    ? (int)$chat['updatedAt']->toDateTime()->format('Uv') : 0,
            ];
        }
        jok(['chats' => $out]);
    }

    if ($act === 'poll_operator') {
        $memberId = (string)($_REQUEST['member_id'] ?? '');
        if ($memberId === '') jerr(400, 'member_id required');
        $resetUnread = !empty($_REQUEST['mark_read']);
        if ($resetUnread) {
            $supportChats->updateOne(['member_id' => $memberId], ['$set' => ['unread_for_operator' => 0]]);
        }
        $chat = $supportChats->findOne(['member_id' => $memberId], ['projection' => ['_rate_customer' => 0]]);
        $portal = $client->selectDatabase($mongoDb)->selectCollection('portals')->findOne(['member_id' => $memberId]);
        $portal = $portal ? (array)$portal : [];
        $ctx = portalCtxFor($portal);
        $messages = unwrapHistory($chat ? (array)$chat : null, 200);
        jok([
            'member_id' => $memberId,
            'mode'      => (string)(($chat ? (array)$chat : [])['mode'] ?? 'ai'),
            'portal'    => $ctx,
            'messages'  => $messages,
            'updatedAt' => isset($chat['updatedAt']) && $chat['updatedAt'] instanceof \MongoDB\BSON\UTCDateTime
                ? (int)$chat['updatedAt']->toDateTime()->format('Uv') : 0,
        ]);
    }

    if ($act === 'send_operator') {
        $memberId = (string)($_REQUEST['member_id'] ?? '');
        $text = trim((string)($_REQUEST['text'] ?? ''));
        $opEmail = (string)($_REQUEST['operator_email'] ?? '');
        if ($memberId === '' || $text === '') jerr(400, 'member_id and text required');
        if (mb_strlen($text) > 4000) jerr(400, 'text too long');
        $msg = [
            'id'             => msgId(),
            'role'           => 'operator',
            'operator_email' => $opEmail,
            'text'           => $text,
            'ts'             => bsonNow(),
        ];
        $supportChats->updateOne(
            ['member_id' => $memberId],
            [
                '$push' => ['messages' => $msg],
                '$set'  => ['updatedAt' => bsonNow(), 'unread_for_operator' => 0],
                '$setOnInsert' => ['mode' => 'human', 'created_at' => bsonNow()],
            ],
            ['upsert' => true]
        );
        $logger->log("operator msg sent member=$memberId by=$opEmail chars=" . strlen($text));
        jok(['ok' => true, 'id' => $msg['id']]);
    }

    if ($act === 'set_mode') {
        $memberId = (string)($_REQUEST['member_id'] ?? '');
        $mode = (string)($_REQUEST['mode'] ?? '');
        $opEmail = (string)($_REQUEST['operator_email'] ?? '');
        if ($memberId === '' || !in_array($mode, ['ai', 'human'], true)) jerr(400, 'bad params');
        $supportChats->updateOne(
            ['member_id' => $memberId],
            ['$set' => [
                'mode'             => $mode,
                'mode_changed_at'  => bsonNow(),
                'mode_changed_by'  => 'operator:' . $opEmail,
                'updatedAt'        => bsonNow(),
            ],
             '$setOnInsert' => ['created_at' => bsonNow()],
            ],
            ['upsert' => true]
        );
        $logger->log("mode set member=$memberId mode=$mode by=$opEmail");
        jok(['ok' => true, 'mode' => $mode]);
    }

    if ($act === 'resolve_portals') {
        // Bulk-resolve member_id → portal context. Accepts member_ids as comma
        // list or repeated POST/GET fields. Returns { portals: { mid: {...} } }.
        $raw = $_REQUEST['member_ids'] ?? '';
        $list = [];
        if (is_array($raw)) {
            foreach ($raw as $v) if ((string)$v !== '') $list[] = (string)$v;
        } else {
            foreach (preg_split('/[,\s]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY) as $v) $list[] = (string)$v;
        }
        $list = array_values(array_unique($list));
        if (count($list) > 500) jerr(400, 'too many ids');

        $portalsCol = $client->selectDatabase($mongoDb)->selectCollection('portals');
        $out = [];
        if (!empty($list)) {
            $cursor = $portalsCol->find(
                ['member_id' => ['$in' => $list]],
                ['projection' => ['member_id' => 1, 'domain' => 1, 'locale' => 1, 'instances' => 1, 'needs_relink' => 1]]
            );
            foreach ($cursor as $p) {
                $p = (array)$p;
                $ctx = portalCtxFor($p);
                $out[(string)$p['member_id']] = [
                    'domain'        => $ctx['domain'],
                    'locale'        => $ctx['locale'],
                    'needs_relink'  => !empty($p['needs_relink']),
                    'idInstance'    => $ctx['idInstance'],
                    'state'         => $ctx['state'],
                    'paymentStatus' => $ctx['paymentStatus'],
                ];
            }
        }
        jok(['portals' => (object)$out]);
    }
}

// =============================================================
// CUSTOMER ACTIONS (auth: member_id matches an installed portal)
// =============================================================
$customerActs = ['send_customer', 'poll_customer'];
if (!in_array($act, $customerActs, true)) jerr(400, 'unknown act');

$memberId = (string)($_REQUEST['member_id'] ?? '');
if ($memberId === '') jerr(400, 'member_id required');

$portal = $db->getSettingsByMemberId($memberId);
if (!$portal) jerr(403, 'unknown portal');
$portalCtx = portalCtxFor($portal);

if ($act === 'poll_customer') {
    $chat = $supportChats->findOne(['member_id' => $memberId], ['projection' => ['_rate_customer' => 0]]);
    $messages = unwrapHistory($chat ? (array)$chat : null, 200);
    jok([
        'messages' => $messages,
        'mode'     => (string)(($chat ? (array)$chat : [])['mode'] ?? 'ai'),
        'portal'   => $portalCtx,
    ]);
}

// send_customer
$text = trim((string)($_REQUEST['text'] ?? ''));
if ($text === '') jerr(400, 'text required');
if (mb_strlen($text) > 4000) jerr(400, 'text too long');
if (!customerRateLimitOk($supportChats, $memberId)) {
    jerr(429, 'rate_limited');
}

$customerMsg = [
    'id'   => msgId(),
    'role' => 'customer',
    'text' => $text,
    'ts'   => bsonNow(),
];
$supportChats->updateOne(
    ['member_id' => $memberId],
    [
        '$push' => ['messages' => $customerMsg],
        '$set'  => [
            'domain'              => $portalCtx['domain'],
            'updatedAt'           => bsonNow(),
            'last_customer_msg_at'=> bsonNow(),
        ],
        '$inc'  => ['unread_for_operator' => 1],
        '$setOnInsert' => ['mode' => 'ai', 'created_at' => bsonNow()],
    ],
    ['upsert' => true]
);

// Reload current chat to get authoritative mode + recent history.
$chatDoc = $supportChats->findOne(['member_id' => $memberId], ['projection' => ['_rate_customer' => 0]]);
$mode = (string)(($chatDoc ? (array)$chatDoc : [])['mode'] ?? 'ai');

if ($mode !== 'ai') {
    // Operator is in charge — do not call AI; operator UI will see the new message via polling.
    $logger->log("customer msg accepted (mode=human) member=$memberId chars=" . strlen($text));
    jok(['accepted' => true, 'mode' => $mode]);
}

// AI mode: synchronously generate reply (best-effort).
try {
    $history = unwrapHistory($chatDoc ? (array)$chatDoc : null, 30);
    $reply = generateSupportReply($history, $portalCtx, $appConfig, $logger, $memberId);
    $aiMsg = [
        'id'   => msgId(),
        'role' => 'ai',
        'text' => $reply,
        'ts'   => bsonNow(),
    ];
    $supportChats->updateOne(
        ['member_id' => $memberId],
        ['$push' => ['messages' => $aiMsg],
         '$set'  => ['updatedAt' => bsonNow()]]
    );
    $logger->log("ai reply sent member=$memberId chars=" . strlen($reply));
    jok(['accepted' => true, 'mode' => 'ai', 'reply' => $reply, 'id' => $aiMsg['id']]);
} catch (Throwable $e) {
    // Fall back: write a placeholder, flip the chat to human mode so an operator picks it up.
    $logger->log("ai failed → mode=human member=$memberId err=" . $e->getMessage());
    $fallbackText = "Передаю оператору, он скоро ответит. (I'm handing this to a human operator who will reply shortly.)";
    $fallbackMsg = [
        'id'   => msgId(),
        'role' => 'ai',
        'text' => $fallbackText,
        'ts'   => bsonNow(),
        'ai_failure' => substr($e->getMessage(), 0, 200),
    ];
    $supportChats->updateOne(
        ['member_id' => $memberId],
        ['$push' => ['messages' => $fallbackMsg],
         '$set'  => [
            'mode'            => 'human',
            'mode_changed_at' => bsonNow(),
            'mode_changed_by' => 'system:ai_failure',
            'updatedAt'       => bsonNow(),
         ]]
    );
    jok(['accepted' => true, 'mode' => 'human', 'reply' => $fallbackText, 'fallback' => true]);
}
