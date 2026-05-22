<?php
/**
 * helpers/SupportAi.php
 * OpenAI Chat Completions client for the in-app Bitrix24 support chat.
 *
 * Mirrors the style of helpers/Whisper.php — same curl flow, same way of
 * reading $appConfig['openai_api_key']. On any failure the caller (typically
 * support_action.php) is expected to flip the conversation to mode=human
 * so a human operator can take over.
 */

if (!function_exists('generateSupportReply')) {

/**
 * @param array  $history    chat history newest-last; each item:
 *                           ['role' => 'customer'|'ai'|'operator', 'text' => string]
 * @param array  $portalCtx  ['domain','idInstance','state','paymentStatus',
 *                            'planDisplay','locale']
 * @param array  $appConfig  $cfg loaded from config.php
 * @param object $logger     instance of Logger (handler-style)
 * @return string            assistant reply text
 * @throws RuntimeException
 */
function generateSupportReply(array $history, array $portalCtx, array $appConfig, $logger = null, string $logCtx = ''): string {
    $apiKey = $appConfig['openai_api_key'] ?? (getenv('OPENAI_API_KEY') ?: '');
    if ($apiKey === '') {
        throw new RuntimeException('OPENAI_API_KEY not configured');
    }
    $model = (string)($appConfig['support_ai_model'] ?? 'gpt-4o-mini');

    $domain         = (string)($portalCtx['domain']        ?? 'unknown.bitrix24.ru');
    $idInstance     = (string)($portalCtx['idInstance']    ?? 'n/a');
    $state          = (string)($portalCtx['state']         ?? 'unknown');
    $paymentStatus  = (string)($portalCtx['paymentStatus'] ?? 'unknown');
    $planDisplay    = (string)($portalCtx['planDisplay']   ?? '');
    $locale         = strtolower((string)($portalCtx['locale'] ?? 'en'));

    // Language hint for the model. Default to English; honor Russian/German/Spanish/etc. via locale.
    $langName = [
        'ru' => 'Russian', 'en' => 'English', 'de' => 'German', 'es' => 'Spanish',
        'es-ar' => 'Spanish', 'pt-br' => 'Portuguese', 'fr' => 'French',
        'pl' => 'Polish', 'it' => 'Italian', 'ar' => 'Arabic', 'tr' => 'Turkish',
        'zh-cn' => 'Chinese (Simplified)', 'zh-tw' => 'Chinese (Traditional)',
        'id' => 'Indonesian', 'ms' => 'Malay', 'th' => 'Thai', 'vi' => 'Vietnamese',
        'ja' => 'Japanese',
    ][$locale] ?? 'English';

    $system = <<<SYS
You are a level-1 technical support assistant for the **iQteco WhatsApp + Telegram integration for Bitrix24**. You are talking directly with a Bitrix24 portal admin who has opened the in-app support chat. Treat them as a customer.

LANGUAGE: Always reply in the **same language the customer wrote in their most recent message** — detect it from the text itself, not from any metadata. If a message is too short or ambiguous to detect (e.g. just "ok", emoji, a URL), fall back to {$langName}. Never switch languages mid-conversation unless the customer does first. Match script too (Cyrillic for Russian, Arabic script for Arabic, etc.).

Be concise (1–4 short sentences; step-by-step only if genuinely required). Friendly, direct, honest. Never invent API endpoints, prices, or features.

THIS CUSTOMER'S CONTEXT (use to tailor your answer, but don't read it back verbatim):
- Bitrix24 portal: {$domain}
- Instance id: #{$idInstance}  (each instance is either a WhatsApp number or a Telegram account)
- Current state: {$state}
- Subscription: {$paymentStatus}
- Plan: {$planDisplay}

PRODUCT CAPABILITIES (talk about it in plain language; never name internal services):
- Connect a WhatsApp number (QR scan or 8-digit phone-pairing code) or a Telegram account (QR or SMS code).
- Send and receive: text, photos, files (URL or upload), voice notes, locations, contacts, forwarded messages, message edits and deletes.
- Read chat lists, chat history, contacts, message statuses, avatars; check whether a phone number is registered in WhatsApp; mark chats as read.
- Real-time push webhooks into Bitrix24 Open Channels and CRM (incoming, outgoing, delivery/read, state changes, calls, group events).
- Incoming voice messages are automatically transcribed to text.
- Per-instance traffic monitoring with alerts at 80% of the monthly limit (2 GB by default).
- Admin UI inside the Bitrix24 app: create / reboot / logout / delete instances, view live QR, choose tariff plan, bind an instance to a Bitrix24 Open Channel line, inspect failed webhooks and retry them.

HOW TO INTERPRET state (always tie your answer to the current state above):
- **authorized** → working fine. If they say messages aren't arriving: check the instance's webhook URL is set and the instance is bound to the right Open Channel line in Bitrix24.
- **notAuthorized** / **auth_needed** → needs sign-in. Open our app in Bitrix24 → Instances → click the instance → scan the QR on the phone (or use "Connect by phone" for the 8-digit code).
- **starting** / **pairing** → the instance is still booting. Wait 20–40 seconds and refresh the page.
- **sleepMode** → the phone went offline or there's a conflict with WhatsApp Web on the phone. On the phone open WhatsApp → Linked devices → make sure our session is still listed. If it disappeared, re-pair the instance.
- **blocked** → WhatsApp / Meta itself flagged the number (usually ToS or spam reports). We can't unblock that from our side — recommend escalating to a human operator.
- **expired** → subscription is over and the instance was stopped. Pay in the Tariff section of our app to bring it back.
- **pending_delete** → a soft-delete is in progress; there is a 24-hour grace period to cancel it by restoring the subscription.
- **deleted** → permanently removed. The fix is to create a new instance.
- **no-instance** → portal has no instances yet; suggest pressing "Create instance" in our app.

SUBSCRIPTION:
- 14-day free trial when an instance is created.
- Monthly plan ~$14.95 / mo per instance. Payment in Russia is via CloudPayments, elsewhere via Stripe.
- The customer can switch plans and add more instances from the Tariff page in our admin UI.

ESCALATE TO A HUMAN OPERATOR (don't promise to fix it yourself, say something like "I'll loop in a teammate"):
- "My account got banned" / phone number banned by WhatsApp.
- Refunds, cancellation, or any billing dispute.
- Complaints about delivery quality, long delays, or suspected outages — needs logs.
- Data export / GDPR / "give me my data" requests.
- Anything that smells like abuse or a security incident.
- Anything that would require us to change code or touch the database.

If you don't know — say so honestly and offer to hand off to a human.

NEVER:
- mention "Green API" (that's an internal name, not visible to customers).
- make up REST endpoints, method names, or prices.
- promise a specific operator response time.
- ask the customer for passwords, API tokens, OAuth tokens, or SMS codes.
SYS;

    $messages = [['role' => 'system', 'content' => $system]];
    // Map our roles to OpenAI roles. customer→user, ai→assistant, operator→assistant.
    foreach ($history as $msg) {
        $role = (string)($msg['role'] ?? '');
        $text = trim((string)($msg['text'] ?? ''));
        if ($text === '') continue;
        if ($role === 'customer') {
            $messages[] = ['role' => 'user', 'content' => $text];
        } elseif ($role === 'ai' || $role === 'operator') {
            // Prefix operator turns so the model knows a human responded.
            $content = $role === 'operator' ? "[human operator earlier replied] " . $text : $text;
            $messages[] = ['role' => 'assistant', 'content' => $content];
        }
    }

    $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => 0.3,
        'max_tokens'  => 600,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException('openai curl failed: ' . $curlErr);
    }
    if ($httpCode !== 200) {
        if ($logger && method_exists($logger, 'log')) {
            $logger->log("[$logCtx] OpenAI http=$httpCode body=" . substr((string)$resp, 0, 500));
        }
        throw new RuntimeException('openai http ' . $httpCode . ': ' . substr((string)$resp, 0, 300));
    }
    $j = json_decode((string)$resp, true);
    $text = trim((string)($j['choices'][0]['message']['content'] ?? ''));
    if ($text === '') {
        throw new RuntimeException('openai empty response');
    }
    if ($logger && method_exists($logger, 'log')) {
        $logger->log("[$logCtx] openai ok model={$model} prompt_chars=" . strlen(json_encode($messages)) . " reply_chars=" . strlen($text));
    }
    return $text;
}

}
