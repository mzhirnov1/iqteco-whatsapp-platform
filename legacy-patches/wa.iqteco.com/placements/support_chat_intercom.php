<?php
/**
 * placements/support_chat_intercom.php
 * Intercom Messenger-styled support chat for the Bitrix24 DEFAULT placement.
 *
 * Same backend as support_chat.php (support_action.php, support_chats Mongo
 * collection). Visual language follows Intercom Messenger v6:
 *  - Gradient navy/blue header
 *  - Stacked operator team avatars + greeting + "We typically reply…"
 *  - Portal info card on top of the conversation area (white card, shadow)
 *  - Message cards (avatar + name + bubble), composer with attach + send
 *  - Quick-reply chips for first-time visitors
 *  - "Powered by iQteco" footer line
 */

if (!is_dir(__DIR__ . '/../logs')) { @mkdir(__DIR__ . '/../logs', 0775, true); }
ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('error_log', __DIR__ . '/../logs/php_error.log');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../db.php';

$memberId = (string)($_POST['member_id'] ?? $_POST['AUTH_MEMBER_ID'] ?? $_GET['member_id'] ?? '');
$domain   = (string)($_POST['DOMAIN']    ?? $_POST['AUTH_DOMAIN']    ?? $_GET['domain']    ?? '');
$bxLang   = strtolower((string)($_POST['LANG'] ?? $_GET['LANG'] ?? 'en'));

$portal = null;
try {
    $db = Database::getInstance();
    if ($memberId !== '') $portal = $db->getSettingsByMemberId($memberId);
    if (!$portal && $domain !== '') $portal = $db->getSettingsByDomain($domain);
} catch (Throwable $e) {
    $portal = null;
}

$primary = null;
if ($portal && !empty($portal['instances'])) {
    $insts = $portal['instances'];
    if ($insts instanceof \MongoDB\Model\BSONArray) $insts = iterator_to_array($insts);
    foreach ($insts as $i) {
        $i = (array)$i;
        if (!is_numeric($i['idInstance'] ?? null)) continue;
        if (($i['paymentStatus'] ?? null) === 'deleted') continue;
        $primary = $i; break;
    }
}

$ctx = [
    'domain'        => (string)($portal['domain']        ?? $domain),
    'idInstance'    => (string)($primary['idInstance']   ?? ''),
    'state'         => (string)($primary['state']        ?? ($primary ? 'unknown' : 'no-instance')),
    'paymentStatus' => (string)($primary['paymentStatus']?? ($primary ? 'unknown' : 'none')),
    'planDisplay'   => (string)($primary['planDisplay']  ?? ''),
    'memberId'      => $memberId,
];

$isRu = in_array($bxLang, ['ru','ua','by','kz'], true);
$T = function (string $key) use ($isRu): string {
    $en = [
        'brand'        => 'iQteco Support',
        'greeting'     => "Hi there 👋",
        'subgreeting'  => "Ask anything about your WhatsApp + Bitrix24 integration.",
        'reply_time'   => "We typically reply in a few minutes",
        'online'       => 'AI assistant online',
        'placeholder'  => 'Write a reply…',
        'send'         => 'Send',
        'attach'       => 'Attach',
        'quick_qr'     => 'How do I scan the QR?',
        'quick_state'  => 'Why is the state not authorized?',
        'quick_billing'=> 'Billing & trial',
        'quick_api'    => 'How do I call the API?',
        'instance'     => 'Instance',
        'portal'       => 'Portal',
        'plan'         => 'Plan',
        'human_mode_hint' => 'A human operator joined this conversation.',
        'rate_limited' => 'Too many messages — please slow down a bit.',
        'send_error'   => "We couldn't send that. Please try again.",
        'thinking'     => 'Assistant is typing…',
        'powered_by'   => 'Powered by iQteco',
        'home'         => 'Home',
        'messages'     => 'Messages',
        'help'         => 'Help',
        'start_conversation' => 'Start a conversation',
        'recent'       => 'Recent conversation',
        'no_conv'      => "We haven't talked yet. Send your first message to begin.",
        'tab_messages_empty' => 'Your message thread will appear here.',
        'tab_help'     => 'Helpful articles',
        'help_topic_1' => 'Pairing the WhatsApp number',
        'help_topic_2' => 'Why messages don\'t arrive',
        'help_topic_3' => 'Billing & subscription',
        'help_topic_4' => 'Green API method reference',
    ];
    $ru = [
        'brand'        => 'iQteco Поддержка',
        'greeting'     => 'Здравствуйте 👋',
        'subgreeting'  => 'Спросите что угодно про интеграцию WhatsApp + Bitrix24.',
        'reply_time'   => 'Обычно отвечаем за пару минут',
        'online'       => 'AI-ассистент онлайн',
        'placeholder'  => 'Напишите ответ…',
        'send'         => 'Отправить',
        'attach'       => 'Прикрепить',
        'quick_qr'     => 'Как просканировать QR?',
        'quick_state'  => 'Почему state не authorized?',
        'quick_billing'=> 'Оплата и пробный период',
        'quick_api'    => 'Как вызвать API?',
        'instance'     => 'Инстанс',
        'portal'       => 'Портал',
        'plan'         => 'Тариф',
        'human_mode_hint' => 'К диалогу подключился оператор.',
        'rate_limited' => 'Слишком много сообщений — подождите немного.',
        'send_error'   => 'Не удалось отправить. Попробуйте ещё раз.',
        'thinking'     => 'Ассистент печатает…',
        'powered_by'   => 'Powered by iQteco',
        'home'         => 'Главная',
        'messages'     => 'Диалог',
        'help'         => 'Справка',
        'start_conversation' => 'Начать диалог',
        'recent'       => 'Недавний диалог',
        'no_conv'      => 'Мы ещё не общались. Напишите первое сообщение.',
        'tab_messages_empty' => 'Здесь появится ваш диалог.',
        'tab_help'     => 'Полезные статьи',
        'help_topic_1' => 'Сканирование QR для WhatsApp',
        'help_topic_2' => 'Почему сообщения не приходят',
        'help_topic_3' => 'Оплата и подписка',
        'help_topic_4' => 'Справочник методов Green API',
    ];
    $dict = $isRu ? $ru : $en;
    return $dict[$key] ?? $key;
};

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
$stateClass = preg_replace('/[^a-z0-9_-]/i', '', $ctx['state']);
?><!doctype html>
<html lang="<?= h($isRu ? 'ru' : 'en') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($T('brand')) ?></title>
    <script src="//api.bitrix24.com/api/v1/"></script>
    <style>
        :root {
            --ic-brand-1: #1f4dd6;
            --ic-brand-2: #284cf0;
            --ic-brand-3: #4f7cff;
            --ic-brand-grad: linear-gradient(160deg, #142b6e 0%, #1f4dd6 45%, #4f7cff 100%);
            --ic-text: #0b1325;
            --ic-text-muted: #6e7c8a;
            --ic-bg: #f6f8fb;
            --ic-card: #ffffff;
            --ic-border: #e5e9f0;
            --ic-user-bubble: #2c5cff;
            --ic-user-bubble-text: #ffffff;
            --ic-shadow-card: 0 10px 30px rgba(13, 32, 92, 0.08), 0 1px 0 rgba(13, 32, 92, 0.04);
            --ic-shadow-bubble: 0 2px 6px rgba(13, 32, 92, 0.06);
            --ic-radius-lg: 18px;
            --ic-radius-md: 14px;
            --ic-radius-sm: 10px;
            --ic-state-ok: #16a34a;
            --ic-state-warn: #d97706;
            --ic-state-bad: #dc2626;
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Inter", "Segoe UI", Roboto, system-ui, sans-serif;
            background: var(--ic-bg);
            color: var(--ic-text);
            font-size: 14px;
            -webkit-font-smoothing: antialiased;
        }
        .messenger {
            display: flex;
            flex-direction: column;
            height: 100vh;
            min-height: 520px;
            max-width: 460px;
            margin: 0 auto;
            background: var(--ic-card);
            box-shadow: var(--ic-shadow-card);
            position: relative;
            overflow: hidden;
        }

        /* ----- Header ----- */
        .ic-header {
            background: var(--ic-brand-grad);
            color: #fff;
            padding: 22px 22px 28px;
            position: relative;
        }
        .ic-header .brand {
            display: flex; align-items: center; gap: 10px;
            font-size: 13px; opacity: .9; letter-spacing: 0.02em;
            margin-bottom: 14px;
        }
        .ic-header .brand .dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: #4ade80; box-shadow: 0 0 0 3px rgba(74,222,128,0.25);
        }
        .ic-team {
            display: flex; align-items: center; margin-bottom: 14px;
        }
        .ic-team .ava {
            width: 36px; height: 36px; border-radius: 50%;
            background: rgba(255,255,255,0.18);
            border: 2px solid #fff;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 13px;
            margin-left: -10px;
            color: #fff;
        }
        .ic-team .ava:first-child { margin-left: 0; }
        .ic-team .ava.bot { background: linear-gradient(135deg, #ff8a4c, #ff5577); }
        .ic-team .ava.op1 { background: linear-gradient(135deg, #34d399, #22c55e); }
        .ic-team .ava.op2 { background: linear-gradient(135deg, #a78bfa, #6366f1); }
        .ic-headline {
            font-size: 22px; font-weight: 700; letter-spacing: -0.02em;
            line-height: 1.25; margin-bottom: 6px;
        }
        .ic-subheadline {
            font-size: 14px; opacity: 0.92; line-height: 1.4;
            margin-bottom: 14px;
        }
        .ic-reply-pill {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(255,255,255,0.16);
            padding: 5px 10px; border-radius: 999px;
            font-size: 12px; font-weight: 500;
        }
        .ic-reply-pill::before {
            content: ''; width: 6px; height: 6px; border-radius: 50%;
            background: #4ade80;
        }

        /* ----- Info card (portal context) ----- */
        .ic-info-card {
            margin: -16px 16px 0;
            background: #fff;
            border-radius: var(--ic-radius-md);
            padding: 12px 14px;
            box-shadow: var(--ic-shadow-card);
            font-size: 13px;
            position: relative;
            z-index: 2;
        }
        .ic-info-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .ic-info-label { color: var(--ic-text-muted); font-size: 12px; }
        .ic-info-value { font-weight: 600; }
        .ic-info-sep { color: #c5cdd9; }
        .ic-pill {
            display: inline-block;
            padding: 2px 8px; border-radius: 999px;
            font-size: 11px; font-weight: 600;
        }
        .ic-pill.state-authorized    { background: #dcfce7; color: #14532d; }
        .ic-pill.state-starting      { background: #fef3c7; color: #78350f; }
        .ic-pill.state-unpaired,
        .ic-pill.state-notAuthorized { background: #fee2e2; color: #7f1d1d; }
        .ic-pill.state-sleepMode     { background: #e2e8f0; color: #1e293b; }
        .ic-pill.state-noinstance,
        .ic-pill.state-unknown,
        .ic-pill.state-deleted       { background: #e2e8f0; color: #1e293b; }
        .ic-pill.pay-trial   { background: #dbeafe; color: #1e40af; }
        .ic-pill.pay-active  { background: #dcfce7; color: #14532d; }
        .ic-pill.pay-expired { background: #fee2e2; color: #7f1d1d; }
        .ic-pill.pay-none    { background: #e2e8f0; color: #1e293b; }

        /* ----- Conversation ----- */
        .ic-conv {
            flex: 1; overflow-y: auto;
            padding: 18px 16px 12px;
            display: flex; flex-direction: column; gap: 10px;
            background: var(--ic-bg);
        }
        .ic-conv-empty {
            text-align: center; padding: 30px 16px;
            color: var(--ic-text-muted); font-size: 13px;
        }
        .ic-quick {
            display: flex; flex-wrap: wrap; gap: 8px;
            padding: 0 16px 12px;
            background: var(--ic-bg);
        }
        .ic-quick button {
            background: #fff;
            border: 1px solid var(--ic-border);
            color: var(--ic-brand-2);
            padding: 8px 14px;
            border-radius: 999px;
            font-size: 13px; font-weight: 500;
            cursor: pointer;
            transition: background .12s, border-color .12s;
        }
        .ic-quick button:hover {
            background: #eef2ff;
            border-color: #cdd9ff;
        }
        .ic-quick.hidden { display: none; }

        /* ----- Messages ----- */
        .msg-row { display: flex; gap: 8px; align-items: flex-end; }
        .msg-row.from-customer { justify-content: flex-end; }
        .msg-row .ava-small {
            width: 28px; height: 28px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 12px; color: #fff;
            flex-shrink: 0;
        }
        .msg-row.from-ai       .ava-small { background: linear-gradient(135deg, #ff8a4c, #ff5577); }
        .msg-row.from-operator .ava-small { background: linear-gradient(135deg, #6366f1, #a78bfa); }

        .msg-bubble {
            max-width: 78%;
            padding: 10px 14px;
            border-radius: var(--ic-radius-lg);
            line-height: 1.42;
            box-shadow: var(--ic-shadow-bubble);
            word-wrap: break-word;
            white-space: pre-wrap;
        }
        .msg-row.from-customer .msg-bubble {
            background: var(--ic-user-bubble);
            color: var(--ic-user-bubble-text);
            border-bottom-right-radius: 4px;
        }
        .msg-row.from-ai .msg-bubble,
        .msg-row.from-operator .msg-bubble {
            background: #fff;
            color: var(--ic-text);
            border-bottom-left-radius: 4px;
        }
        .msg-author {
            font-size: 11px; color: var(--ic-text-muted);
            margin-bottom: 4px; font-weight: 600;
        }
        .msg-time {
            font-size: 10px; color: var(--ic-text-muted);
            margin-top: 4px;
        }
        .msg-row.from-customer .msg-time { color: rgba(255,255,255,0.7); text-align: right; }

        /* ----- Banner ----- */
        .ic-banner {
            background: #dbeafe; color: #1e3a8a;
            padding: 8px 16px; font-size: 12px; text-align: center;
            border-top: 1px solid #bfdbfe;
            border-bottom: 1px solid #bfdbfe;
        }
        .ic-banner[hidden] { display: none; }

        /* ----- Typing indicator ----- */
        .ic-typing {
            display: flex; gap: 8px; align-items: center;
            padding: 6px 16px;
            color: var(--ic-text-muted); font-size: 12px;
        }
        .ic-typing[hidden] { display: none; }
        .ic-typing .dots {
            display: inline-flex; gap: 3px;
        }
        .ic-typing .dots span {
            width: 6px; height: 6px; border-radius: 50%;
            background: #b3bccb;
            animation: ic-bounce 1.2s infinite ease-in-out;
        }
        .ic-typing .dots span:nth-child(2) { animation-delay: 0.15s; }
        .ic-typing .dots span:nth-child(3) { animation-delay: 0.3s; }
        @keyframes ic-bounce {
            0%, 80%, 100% { transform: scale(0.7); opacity: 0.5; }
            40%           { transform: scale(1);   opacity: 1;   }
        }

        /* ----- Composer ----- */
        .ic-composer {
            background: #fff;
            border-top: 1px solid var(--ic-border);
            padding: 10px 12px 12px;
            display: flex; align-items: flex-end; gap: 8px;
        }
        .ic-composer textarea {
            flex: 1;
            border: 1px solid var(--ic-border);
            border-radius: 22px;
            padding: 10px 14px;
            font: inherit; resize: none;
            max-height: 140px; line-height: 1.4;
            background: #f6f8fb;
            outline: none;
            transition: border-color .12s, background .12s;
        }
        .ic-composer textarea:focus {
            border-color: #c2d0ff;
            background: #fff;
        }
        .ic-composer .send-btn {
            width: 38px; height: 38px;
            border-radius: 50%;
            background: var(--ic-brand-2);
            color: #fff;
            border: none; cursor: pointer;
            font-size: 16px;
            display: flex; align-items: center; justify-content: center;
            transition: background .12s, opacity .12s;
        }
        .ic-composer .send-btn:hover { background: var(--ic-brand-1); }
        .ic-composer .send-btn:disabled { background: #c2d0ff; cursor: not-allowed; }
        .ic-composer .attach-btn {
            width: 38px; height: 38px;
            border-radius: 50%;
            background: transparent;
            color: var(--ic-text-muted);
            border: none; cursor: pointer;
            font-size: 18px;
            display: flex; align-items: center; justify-content: center;
        }
        .ic-composer .attach-btn:hover { background: #f1f4f9; color: var(--ic-brand-2); }
        .ic-err { color: #dc2626; font-size: 12px; padding: 0 16px 8px; }
        .ic-err[hidden] { display: none; }

        /* ----- Footer / Powered ----- */
        .ic-powered {
            text-align: center;
            font-size: 11px; color: var(--ic-text-muted);
            padding: 6px 12px 10px;
            background: #fff;
            border-top: 1px solid var(--ic-border);
        }
        .ic-powered a { color: var(--ic-brand-2); text-decoration: none; font-weight: 600; }

        /* ----- Tabs ----- */
        .ic-tabs {
            display: grid; grid-template-columns: 1fr 1fr 1fr;
            background: #fff;
            border-top: 1px solid var(--ic-border);
        }
        .ic-tabs button {
            background: transparent; border: none;
            padding: 10px 4px 12px;
            font-size: 12px; color: var(--ic-text-muted);
            cursor: pointer;
            display: flex; flex-direction: column; align-items: center; gap: 4px;
            border-top: 2px solid transparent;
        }
        .ic-tabs button.active {
            color: var(--ic-brand-2);
            border-top-color: var(--ic-brand-2);
            font-weight: 600;
        }
        .ic-tabs button .ic-icon { font-size: 16px; }

        /* ----- Tab views ----- */
        .ic-tab-view { display: flex; flex-direction: column; flex: 1; min-height: 0; }
        .ic-tab-view[hidden] { display: none; }

        .ic-home-articles {
            padding: 12px 16px 16px;
            display: flex; flex-direction: column; gap: 8px;
        }
        .ic-home-articles .article {
            background: #fff;
            border: 1px solid var(--ic-border);
            border-radius: var(--ic-radius-md);
            padding: 12px 14px;
            display: flex; align-items: center; gap: 10px;
            cursor: pointer;
            transition: transform .12s, box-shadow .12s;
        }
        .ic-home-articles .article:hover {
            transform: translateY(-1px);
            box-shadow: var(--ic-shadow-bubble);
        }
        .ic-home-articles .article .icon {
            width: 32px; height: 32px; border-radius: 8px;
            background: #eef2ff; color: var(--ic-brand-2);
            display: flex; align-items: center; justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        .ic-home-articles .article .title { font-weight: 600; font-size: 13px; }

        .ic-cta {
            margin: 8px 16px 14px;
            background: #fff;
            border: 1px solid var(--ic-border);
            border-radius: var(--ic-radius-md);
            padding: 14px;
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px;
        }
        .ic-cta .title { font-weight: 600; font-size: 14px; }
        .ic-cta .sub   { font-size: 12px; color: var(--ic-text-muted); margin-top: 2px; }
        .ic-cta button {
            background: var(--ic-brand-2); color: #fff;
            border: none; border-radius: 8px;
            padding: 8px 12px; font-weight: 600; font-size: 12px;
            cursor: pointer;
            flex-shrink: 0;
        }
        .ic-cta button:hover { background: var(--ic-brand-1); }
    </style>
</head>
<body>
<div class="messenger">

    <!-- HOME tab -->
    <div class="ic-tab-view" id="tab-home">
        <header class="ic-header">
            <div class="brand">
                <span class="dot"></span>
                <span><?= h($T('brand')) ?></span>
            </div>
            <div class="ic-team" aria-hidden="true">
                <div class="ava bot">AI</div>
                <div class="ava op1">MZ</div>
                <div class="ava op2">SP</div>
            </div>
            <div class="ic-headline"><?= h($T('greeting')) ?></div>
            <div class="ic-subheadline"><?= h($T('subgreeting')) ?></div>
            <div class="ic-reply-pill"><?= h($T('reply_time')) ?></div>
        </header>

        <div class="ic-info-card">
            <div class="ic-info-row">
                <span class="ic-info-label"><?= h($T('portal')) ?>:</span>
                <span class="ic-info-value"><?= h($ctx['domain'] ?: '—') ?></span>
                <?php if ($ctx['idInstance'] !== ''): ?>
                    <span class="ic-info-sep">·</span>
                    <span class="ic-info-label"><?= h($T('instance')) ?></span>
                    <span class="ic-info-value">#<?= h($ctx['idInstance']) ?></span>
                <?php endif; ?>
                <span class="ic-pill state-<?= h($stateClass) ?>" id="ic-state-pill"><?= h($ctx['state']) ?></span>
                <?php if ($ctx['paymentStatus'] !== '' && $ctx['paymentStatus'] !== 'unknown'): ?>
                    <span class="ic-pill pay-<?= h($ctx['paymentStatus']) ?>"><?= h($ctx['paymentStatus']) ?></span>
                <?php endif; ?>
                <?php if ($ctx['planDisplay'] !== ''): ?>
                    <span class="ic-info-sep">·</span>
                    <span class="ic-info-label"><?= h($T('plan')) ?>:</span>
                    <span class="ic-info-value"><?= h($ctx['planDisplay']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="ic-cta">
            <div>
                <div class="title"><?= h($T('start_conversation')) ?></div>
                <div class="sub"><?= h($T('reply_time')) ?></div>
            </div>
            <button type="button" data-go="messages"><?= h($T('messages')) ?> →</button>
        </div>

        <div class="ic-home-articles">
            <div class="article" data-quick="<?= h($T('quick_qr')) ?>">
                <div class="icon">📱</div>
                <div class="title"><?= h($T('help_topic_1')) ?></div>
            </div>
            <div class="article" data-quick="<?= h($T('quick_state')) ?>">
                <div class="icon">📡</div>
                <div class="title"><?= h($T('help_topic_2')) ?></div>
            </div>
            <div class="article" data-quick="<?= h($T('quick_billing')) ?>">
                <div class="icon">💳</div>
                <div class="title"><?= h($T('help_topic_3')) ?></div>
            </div>
            <div class="article" data-quick="<?= h($T('quick_api')) ?>">
                <div class="icon">⚙️</div>
                <div class="title"><?= h($T('help_topic_4')) ?></div>
            </div>
        </div>
    </div>

    <!-- MESSAGES tab -->
    <div class="ic-tab-view" id="tab-messages" hidden>
        <header class="ic-header" style="padding-bottom: 18px;">
            <div class="brand">
                <span class="dot"></span>
                <span><?= h($T('brand')) ?></span>
            </div>
            <div class="ic-team" aria-hidden="true">
                <div class="ava bot">AI</div>
                <div class="ava op1">MZ</div>
            </div>
            <div class="ic-headline" style="font-size:18px;"><?= h($T('online')) ?></div>
            <div class="ic-reply-pill"><?= h($T('reply_time')) ?></div>
        </header>

        <div class="ic-info-card">
            <div class="ic-info-row">
                <span class="ic-info-value"><?= h($ctx['domain'] ?: '—') ?></span>
                <?php if ($ctx['idInstance'] !== ''): ?>
                    <span class="ic-info-sep">·</span>
                    <span>#<?= h($ctx['idInstance']) ?></span>
                <?php endif; ?>
                <span class="ic-pill state-<?= h($stateClass) ?>" id="ic-state-pill-2"><?= h($ctx['state']) ?></span>
            </div>
        </div>

        <div class="ic-banner" id="ic-banner" hidden><?= h($T('human_mode_hint')) ?></div>

        <div class="ic-conv" id="ic-conv">
            <div class="ic-conv-empty" id="ic-conv-empty"><?= h($T('no_conv')) ?></div>
        </div>

        <div class="ic-quick" id="ic-quick">
            <button type="button" data-quick="<?= h($T('quick_qr')) ?>"><?= h($T('quick_qr')) ?></button>
            <button type="button" data-quick="<?= h($T('quick_state')) ?>"><?= h($T('quick_state')) ?></button>
            <button type="button" data-quick="<?= h($T('quick_billing')) ?>"><?= h($T('quick_billing')) ?></button>
        </div>

        <div class="ic-typing" id="ic-typing" hidden>
            <span><?= h($T('thinking')) ?></span>
            <span class="dots"><span></span><span></span><span></span></span>
        </div>

        <div class="ic-err" id="ic-err" hidden></div>

        <div class="ic-composer">
            <button class="attach-btn" type="button" title="<?= h($T('attach')) ?>">📎</button>
            <textarea id="ic-text" rows="1" placeholder="<?= h($T('placeholder')) ?>"></textarea>
            <button class="send-btn" id="ic-send" type="button" title="<?= h($T('send')) ?>">→</button>
        </div>

        <div class="ic-powered"><?= h($T('powered_by')) ?></div>
    </div>

    <!-- HELP tab -->
    <div class="ic-tab-view" id="tab-help" hidden>
        <header class="ic-header" style="padding-bottom: 20px;">
            <div class="ic-headline" style="font-size:20px;"><?= h($T('tab_help')) ?></div>
        </header>
        <div class="ic-home-articles">
            <div class="article" data-quick="<?= h($T('quick_qr')) ?>">
                <div class="icon">📱</div>
                <div class="title"><?= h($T('help_topic_1')) ?></div>
            </div>
            <div class="article" data-quick="<?= h($T('quick_state')) ?>">
                <div class="icon">📡</div>
                <div class="title"><?= h($T('help_topic_2')) ?></div>
            </div>
            <div class="article" data-quick="<?= h($T('quick_billing')) ?>">
                <div class="icon">💳</div>
                <div class="title"><?= h($T('help_topic_3')) ?></div>
            </div>
            <div class="article" data-quick="<?= h($T('quick_api')) ?>">
                <div class="icon">⚙️</div>
                <div class="title"><?= h($T('help_topic_4')) ?></div>
            </div>
        </div>
        <div class="ic-powered"><?= h($T('powered_by')) ?></div>
    </div>

    <!-- Bottom navigation -->
    <div class="ic-tabs">
        <button data-tab="home"     class="active"><span class="ic-icon">🏠</span><?= h($T('home')) ?></button>
        <button data-tab="messages"><span class="ic-icon">💬</span><?= h($T('messages')) ?></button>
        <button data-tab="help"><span class="ic-icon">📚</span><?= h($T('help')) ?></button>
    </div>
</div>

<script>
(function () {
    const MEMBER_ID = <?= json_encode($ctx['memberId'], JSON_UNESCAPED_UNICODE) ?>;
    const ACTION_URL = '/support_action.php';
    const POLL_MS = 3000;
    const T = {
        rateLimited: <?= json_encode($T('rate_limited'), JSON_UNESCAPED_UNICODE) ?>,
        sendError:   <?= json_encode($T('send_error'),   JSON_UNESCAPED_UNICODE) ?>,
    };

    // Tab switching
    const tabs = document.querySelectorAll('.ic-tabs button');
    const views = {
        home:     document.getElementById('tab-home'),
        messages: document.getElementById('tab-messages'),
        help:     document.getElementById('tab-help'),
    };
    function showTab(name) {
        Object.entries(views).forEach(([k, el]) => el.hidden = (k !== name));
        tabs.forEach(b => b.classList.toggle('active', b.dataset.tab === name));
    }
    tabs.forEach(b => b.addEventListener('click', () => showTab(b.dataset.tab)));

    // CTA "Start a conversation" → messages tab
    document.querySelectorAll('[data-go="messages"]').forEach(el => {
        el.addEventListener('click', () => showTab('messages'));
    });

    if (!MEMBER_ID) {
        // No portal context — disable composer.
        const send = document.getElementById('ic-send');
        const ta   = document.getElementById('ic-text');
        if (send) send.disabled = true;
        if (ta)   ta.disabled   = true;
        return;
    }

    const $conv = document.getElementById('ic-conv');
    const $convEmpty = document.getElementById('ic-conv-empty');
    const $send = document.getElementById('ic-send');
    const $text = document.getElementById('ic-text');
    const $err  = document.getElementById('ic-err');
    const $banner = document.getElementById('ic-banner');
    const $typing = document.getElementById('ic-typing');
    const $quick  = document.getElementById('ic-quick');

    let seenIds = new Set();
    let inflight = false;
    let lastMode = 'ai';

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({
            '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'
        })[c]);
    }
    function fmtTime(ms) {
        if (!ms) return '';
        const d = new Date(ms);
        const pad = n => String(n).padStart(2,'0');
        return pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function renderMsg(m) {
        if (seenIds.has(m.id)) return;
        seenIds.add(m.id);
        if ($convEmpty) $convEmpty.remove();
        const row = document.createElement('div');
        const role = m.role || 'customer';
        row.className = 'msg-row from-' + role;
        let avaHtml = '';
        let author  = '';
        if (role === 'ai')       { avaHtml = '<div class="ava-small">AI</div>'; author = '🤖 Assistant'; }
        if (role === 'operator') {
            const init = m.operator_email ? m.operator_email.split('@')[0].slice(0,2).toUpperCase() : 'OP';
            avaHtml = '<div class="ava-small">' + escapeHtml(init) + '</div>';
            author = m.operator_email ? ('👤 ' + m.operator_email.split('@')[0]) : '👤 Support';
        }
        const bubbleInner = (author ? '<div class="msg-author">' + escapeHtml(author) + '</div>' : '')
            + '<div>' + escapeHtml(m.text) + '</div>'
            + '<div class="msg-time">' + escapeHtml(fmtTime(m.ts)) + '</div>';
        if (role === 'customer') {
            row.innerHTML = '<div class="msg-bubble">' + bubbleInner + '</div>';
        } else {
            row.innerHTML = avaHtml + '<div class="msg-bubble">' + bubbleInner + '</div>';
        }
        $conv.appendChild(row);
    }
    function scrollDown() { $conv.scrollTop = $conv.scrollHeight; }
    function setMode(mode) {
        lastMode = mode;
        $banner.hidden = (mode !== 'human');
    }
    function showErr(msg) {
        $err.textContent = msg; $err.hidden = false;
        setTimeout(() => { $err.hidden = true; }, 4000);
    }
    function hideQuickIfHasMsgs() {
        if (seenIds.size > 0) $quick.classList.add('hidden');
    }

    async function call(params) {
        const body = new URLSearchParams();
        Object.keys(params).forEach(k => body.append(k, params[k]));
        const r = await fetch(ACTION_URL, { method: 'POST', body, credentials: 'omit' });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) {
            const e = new Error(j.error || ('http ' + r.status));
            e.status = r.status; e.body = j; throw e;
        }
        return j;
    }

    async function poll() {
        if (inflight) return;
        inflight = true;
        try {
            const j = await call({ act: 'poll_customer', member_id: MEMBER_ID });
            (j.messages || []).forEach(renderMsg);
            scrollDown();
            setMode(j.mode || 'ai');
            hideQuickIfHasMsgs();
            if (j.portal) {
                const stateRaw = j.portal.state || 'unknown';
                ['ic-state-pill','ic-state-pill-2'].forEach(id => {
                    const el = document.getElementById(id);
                    if (el) {
                        el.textContent = stateRaw;
                        el.className = 'ic-pill state-' + stateRaw.replace(/[^a-z0-9_-]/ig,'');
                    }
                });
            }
        } catch (e) { /* silent */ }
        finally { inflight = false; }
    }

    async function send(text) {
        text = (text || '').trim();
        if (!text) return;
        $send.disabled = true;
        $typing.hidden = false;
        const tempId = 'tmp-' + Date.now();
        renderMsg({ id: tempId, role: 'customer', text, ts: Date.now() });
        scrollDown();
        $text.value = '';
        $text.style.height = 'auto';
        hideQuickIfHasMsgs();
        try {
            const j = await call({ act: 'send_customer', member_id: MEMBER_ID, text });
            setMode(j.mode || 'ai');
            await poll();
        } catch (e) {
            if (e.status === 429) showErr(T.rateLimited);
            else showErr(T.sendError);
        } finally {
            $send.disabled = false;
            $typing.hidden = true;
        }
    }

    $send.addEventListener('click', () => send($text.value));
    $text.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send($text.value); }
    });
    $text.addEventListener('input', () => {
        $text.style.height = 'auto';
        $text.style.height = Math.min(140, $text.scrollHeight) + 'px';
    });

    // Quick-reply chips (in both Home/Help tabs and Messages tab)
    document.querySelectorAll('[data-quick]').forEach(el => {
        el.addEventListener('click', () => {
            showTab('messages');
            send(el.dataset.quick);
        });
    });

    // Kick things off
    poll();
    setInterval(poll, POLL_MS);

    if (window.BX24) { try { BX24.init(function () {}); } catch (e) {} }
})();
</script>
</body>
</html>
