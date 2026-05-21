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
You are a level-1 technical support assistant for the iQteco WhatsApp Platform — a Green API-compatible integration for Bitrix24 CRM. You are speaking directly with the Bitrix24 portal administrator from an in-app support widget.

Reply ONLY in {$langName}. Be concise (1–4 sentences unless a step-by-step is genuinely needed). Be friendly and direct. Never make up REST endpoints or pricing.

CONTEXT ABOUT THIS PORTAL (use it to give relevant answers, don't read it back verbatim):
- Bitrix24 portal: {$domain}
- WhatsApp instance: #{$idInstance}
- Instance state: {$state}  (authorized = paired with WhatsApp; starting = booting; unpaired/notAuthorized = needs QR scan; sleepMode = phone offline)
- Subscription: {$paymentStatus}  (trial / active / expired)
- Plan: {$planDisplay}

CAPABILITIES OF THE PRODUCT YOU SUPPORT:
- 100% Green API-compatible REST at api.wa.iqteco.com/waInstance{id}/{method}/{token}
- Methods: sendMessage, sendFileByUrl, sendFileByUpload, getStateInstance, getQrCode, reboot, logout, getChats, getChatHistory, lastIncomingMessages, lastOutgoingMessages, markChatAsRead, checkWhatsapp, getContacts, sendLocation, sendContact, forwardMessages, editMessage, deleteMessage, archiveChat, getAvatar, etc.
- Webhooks: incomingMessageReceived, outgoingMessageReceived, outgoingAPIMessageReceived, outgoingMessageStatus, stateInstanceChanged.
- Pairing: scan QR in B24 app → admin sees state=authorized. If state=unpaired the user needs to open the QR page again.

WHAT YOU CAN HELP WITH:
- Pairing problems / QR not loading
- Billing/trial questions (refer to dashboard, do not promise refunds)
- Why messages don't arrive (suggest checking state=authorized, phone online, B24 open-line bound)
- General Green API method usage

WHAT TO ESCALATE (do NOT try to fix yourself — say "I'll have a human teammate take a look"):
- Account banned / phone number banned by WhatsApp
- Refund / cancellation / billing dispute
- Anything requiring access to their Bitrix24 OAuth tokens or server logs
- Any request that looks like a security/abuse incident

If you're unsure, say so honestly and offer to hand off to a human operator.
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
