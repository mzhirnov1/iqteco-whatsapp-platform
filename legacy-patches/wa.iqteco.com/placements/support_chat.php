<?php
/**
 * placements/support_chat.php
 * In-app support chat rendered inside the Bitrix24 DEFAULT placement of our app.
 *
 * Flow:
 *   1) B24 POSTs to this page with member_id / DOMAIN / LANG / PLACEMENT_OPTIONS.
 *   2) We render an iframe-only page with WhatsApp-Web styling.
 *   3) JS pushes/polls /support_action.php using member_id from the initial POST.
 *
 * No B24 tokens are stored — we don't call the Bitrix24 REST API from here.
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

// Resolve portal context server-side so the first paint already shows the card.
$portal = null;
try {
    $db = Database::getInstance();
    if ($memberId !== '') $portal = $db->getSettingsByMemberId($memberId);
    if (!$portal && $domain !== '') $portal = $db->getSettingsByDomain($domain);
} catch (Throwable $e) {
    $portal = null;
}

// Pick primary (non-deleted, numeric idInstance) for the card.
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

// Tiny 2-locale dictionary. Russian if locale matches RU/UA/BY, else English.
$isRu = in_array($bxLang, ['ru','ua','by','kz'], true);
$T = function (string $key) use ($isRu): string {
    $en = [
        'title'        => 'Support',
        'greeting'     => 'Hi! Ask a question about your WhatsApp + Bitrix24 integration and our AI will reply in seconds. If something doesn\'t look right, a human operator will jump in.',
        'placeholder'  => 'Type your question…',
        'send'         => 'Send',
        'instance'     => 'Instance',
        'plan'         => 'Plan',
        'state.authorized'     => 'authorized',
        'state.starting'       => 'starting',
        'state.unpaired'       => 'needs QR',
        'state.notAuthorized'  => 'needs QR',
        'state.sleepMode'      => 'phone offline',
        'state.no-instance'    => 'no instance',
        'state.unknown'        => 'unknown',
        'human_mode_hint'      => 'A human operator is helping you in this chat.',
        'rate_limited'         => 'Too many messages — slow down a bit.',
        'send_error'           => 'Failed to send. Please try again.',
        'thinking'             => 'Assistant is typing…',
    ];
    $ru = [
        'title'        => 'Поддержка',
        'greeting'     => 'Здравствуйте! Задайте вопрос по интеграции WhatsApp + Bitrix24 — AI ответит за пару секунд. Если что-то идёт не так, к диалогу подключится оператор.',
        'placeholder'  => 'Введите ваш вопрос…',
        'send'         => 'Отправить',
        'instance'     => 'Инстанс',
        'plan'         => 'Тариф',
        'state.authorized'     => 'подключён',
        'state.starting'       => 'запускается',
        'state.unpaired'       => 'нужен QR',
        'state.notAuthorized'  => 'нужен QR',
        'state.sleepMode'      => 'телефон офлайн',
        'state.no-instance'    => 'нет инстанса',
        'state.unknown'        => 'неизвестно',
        'human_mode_hint'      => 'С вами общается оператор.',
        'rate_limited'         => 'Слишком много сообщений — подождите немного.',
        'send_error'           => 'Не удалось отправить. Попробуйте ещё раз.',
        'thinking'             => 'Ассистент печатает…',
    ];
    $dict = $isRu ? $ru : $en;
    return $dict[$key] ?? $key;
};

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
$stateKey = 'state.' . $ctx['state'];
$stateLabel = $T($stateKey);
$stateClass = preg_replace('/[^a-z0-9_-]/i', '', $ctx['state']);
?><!doctype html>
<html lang="<?= h($isRu ? 'ru' : 'en') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($T('title')) ?></title>
    <script src="//api.bitrix24.com/api/v1/"></script>
    <style>
        :root {
            --bg: #efeae2;
            --panel: #fff;
            --header-bg: #f0f2f5;
            --bubble-customer: #d9fdd3;
            --bubble-ai: #ffffff;
            --bubble-operator: #cfe9ff;
            --accent: #25D366;
            --accent-dark: #128C7E;
            --text: #111b21;
            --text-muted: #667781;
            --border: #e9edef;
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            background: var(--bg); color: var(--text); font-size: 14px;
        }
        .app { display: flex; flex-direction: column; height: 100vh; min-height: 480px; }

        .info-card {
            background: var(--header-bg);
            padding: 10px 16px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
        }
        .info-card .ava {
            width: 40px; height: 40px; border-radius: 50%;
            background: var(--accent-dark); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; flex-shrink: 0;
        }
        .info-card .meta { flex: 1; min-width: 0; }
        .info-card .title { font-weight: 600; font-size: 15px; }
        .info-card .sub {
            font-size: 12px; color: var(--text-muted); margin-top: 2px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .info-card .pill {
            display: inline-block; padding: 2px 8px; border-radius: 10px;
            font-size: 11px; font-weight: 600; margin-left: 4px;
        }
        .pill.state-authorized   { background: #dff7df; color: #128C7E; }
        .pill.state-starting     { background: #fff3cd; color: #856404; }
        .pill.state-unpaired,
        .pill.state-notAuthorized{ background: #f8d7da; color: #721c24; }
        .pill.state-sleepMode    { background: #e2e3e5; color: #383d41; }
        .pill.state-noinstance   { background: #e2e3e5; color: #383d41; }
        .pill.state-unknown      { background: #e2e3e5; color: #383d41; }
        .pill.pay-trial   { background: #cfe2ff; color: #084298; }
        .pill.pay-active  { background: #dff7df; color: #128C7E; }
        .pill.pay-expired { background: #f8d7da; color: #721c24; }
        .pill.pay-none    { background: #e2e3e5; color: #383d41; }

        .messages {
            flex: 1; overflow-y: auto; padding: 16px 18px;
            display: flex; flex-direction: column; gap: 6px;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='60' height='60' opacity='.04'><circle cx='30' cy='30' r='2' fill='%23128C7E'/></svg>");
        }
        .greeting {
            align-self: center; max-width: 80%;
            background: rgba(255,255,255,.75); border: 1px solid var(--border);
            color: var(--text-muted);
            padding: 10px 14px; border-radius: 10px;
            font-size: 13px; text-align: center;
        }
        .bubble {
            max-width: 78%; padding: 8px 12px; border-radius: 10px;
            word-wrap: break-word; white-space: pre-wrap; position: relative;
            box-shadow: 0 1px 0.5px rgba(0,0,0,0.08);
        }
        .bubble.customer { background: var(--bubble-customer); align-self: flex-end; border-bottom-right-radius: 2px; }
        .bubble.ai       { background: var(--bubble-ai);       align-self: flex-start; border-bottom-left-radius: 2px; }
        .bubble.operator { background: var(--bubble-operator); align-self: flex-start; border-bottom-left-radius: 2px; }
        .bubble .author {
            font-size: 11px; font-weight: 600; margin-bottom: 2px;
            color: var(--text-muted);
        }
        .bubble.ai .author       { color: #128C7E; }
        .bubble.operator .author { color: #084298; }
        .bubble .time { font-size: 10px; color: var(--text-muted); text-align: right; margin-top: 2px; }

        .mode-banner {
            background: #cfe2ff; color: #084298; padding: 6px 14px;
            font-size: 12px; text-align: center; border-bottom: 1px solid #b6d4fe;
        }

        .composer {
            background: var(--header-bg); padding: 8px 12px;
            border-top: 1px solid var(--border);
            display: flex; gap: 8px; align-items: flex-end;
        }
        .composer textarea {
            flex: 1; border: 1px solid var(--border); border-radius: 8px;
            padding: 9px 12px; background: #fff; font: inherit; resize: none;
            max-height: 150px; line-height: 1.4;
        }
        .composer textarea:focus { outline: 1px solid var(--accent-dark); }
        .composer button {
            background: var(--accent); color: #fff; border: none; border-radius: 50%;
            width: 42px; height: 42px; font-size: 18px; cursor: pointer;
        }
        .composer button:disabled { background: #9ca3af; cursor: not-allowed; }
        .composer .err { color: #b91c1c; font-size: 12px; margin: 4px 12px 0; }

        .typing { color: var(--text-muted); font-size: 12px; padding: 4px 18px; font-style: italic; }
        .typing[hidden] { display: none; }
    </style>
</head>
<body>
<div class="app">
    <div class="info-card">
        <div class="ava">i</div>
        <div class="meta">
            <div class="title"><?= h($T('title')) ?></div>
            <div class="sub">
                <span><?= h($ctx['domain']) ?></span>
                <?php if ($ctx['idInstance'] !== ''): ?>
                    · <span><?= h($T('instance')) ?> #<?= h($ctx['idInstance']) ?></span>
                <?php endif; ?>
                <span class="pill state-<?= h($stateClass) ?>" id="state-pill"><?= h($stateLabel) ?></span>
                <?php if ($ctx['paymentStatus'] !== '' && $ctx['paymentStatus'] !== 'unknown'): ?>
                    <span class="pill pay-<?= h($ctx['paymentStatus']) ?>"><?= h($ctx['paymentStatus']) ?></span>
                <?php endif; ?>
                <?php if ($ctx['planDisplay'] !== ''): ?>
                    · <span><?= h($T('plan')) ?>: <?= h($ctx['planDisplay']) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="mode-banner" id="mode-banner" hidden><?= h($T('human_mode_hint')) ?></div>

    <div class="messages" id="messages">
        <div class="greeting"><?= h($T('greeting')) ?></div>
    </div>

    <div class="typing" id="typing" hidden><?= h($T('thinking')) ?></div>

    <div class="composer">
        <div style="flex:1">
            <textarea id="text" rows="1" placeholder="<?= h($T('placeholder')) ?>"></textarea>
            <div class="err" id="err" hidden></div>
        </div>
        <button id="send" type="button" title="<?= h($T('send')) ?>">▶</button>
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

    if (!MEMBER_ID) {
        // Without a member_id (e.g. opened outside B24) we can't talk to backend.
        document.getElementById('send').disabled = true;
        document.getElementById('text').disabled = true;
        return;
    }

    const $messages = document.getElementById('messages');
    const $send = document.getElementById('send');
    const $text = document.getElementById('text');
    const $err  = document.getElementById('err');
    const $banner = document.getElementById('mode-banner');
    const $typing = document.getElementById('typing');

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
        const div = document.createElement('div');
        div.className = 'bubble ' + (m.role === 'customer' ? 'customer' : m.role === 'operator' ? 'operator' : 'ai');
        const author = m.role === 'ai' ? '🤖 Assistant' :
                       m.role === 'operator' ? (m.operator_email ? ('👤 ' + m.operator_email.split('@')[0]) : '👤 Support') :
                       null;
        const authorHtml = author ? ('<div class="author">' + escapeHtml(author) + '</div>') : '';
        div.innerHTML = authorHtml +
            '<div class="text">' + escapeHtml(m.text) + '</div>' +
            '<div class="time">' + escapeHtml(fmtTime(m.ts)) + '</div>';
        $messages.appendChild(div);
    }
    function scrollDown() {
        $messages.scrollTop = $messages.scrollHeight;
    }
    function setMode(mode) {
        lastMode = mode;
        $banner.hidden = (mode !== 'human');
    }
    function showErr(msg) {
        $err.textContent = msg; $err.hidden = false;
        setTimeout(() => { $err.hidden = true; }, 4000);
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
            // Refresh card if portal context changed (e.g. instance state).
            if (j.portal) {
                const pill = document.getElementById('state-pill');
                if (pill && j.portal.state) {
                    const stateKey = 'state.' + j.portal.state;
                    // Don't translate dynamically (would need full dict in JS) — show raw state.
                    pill.textContent = j.portal.state;
                    pill.className = 'pill state-' + j.portal.state.replace(/[^a-z0-9_-]/ig,'');
                }
            }
        } catch (e) {
            // Silent poll failure — try again next tick.
        } finally {
            inflight = false;
        }
    }

    async function send() {
        const text = $text.value.trim();
        if (!text) return;
        $send.disabled = true;
        $typing.hidden = false;
        // Optimistic render of customer msg.
        const tempId = 'tmp-' + Date.now();
        renderMsg({ id: tempId, role: 'customer', text, ts: Date.now() });
        scrollDown();
        $text.value = '';
        try {
            const j = await call({ act: 'send_customer', member_id: MEMBER_ID, text });
            setMode(j.mode || 'ai');
            // poll will pick up the persisted versions (with real ids).
            await poll();
        } catch (e) {
            if (e.status === 429) {
                showErr(T.rateLimited);
            } else {
                showErr(T.sendError);
            }
        } finally {
            $send.disabled = false;
            $typing.hidden = true;
        }
    }

    $send.addEventListener('click', send);
    $text.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    });
    $text.addEventListener('input', () => {
        $text.style.height = 'auto';
        $text.style.height = Math.min(150, $text.scrollHeight) + 'px';
    });

    // Initial paint, then polling.
    poll();
    setInterval(poll, POLL_MS);

    // BX24 placement context (we don't strictly need anything from it — member_id is already in PHP-POST).
    if (window.BX24) {
        try { BX24.init(function () { /* noop */ }); } catch (e) {}
    }
})();
</script>
</body>
</html>
