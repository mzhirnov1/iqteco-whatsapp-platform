<?php
/**
 * Файл: handler.php
 * Единая точка для обработки вебхуков от Green API, событий от Bitrix24 и платежей от CloudPayments.
 */

ini_set('log_errors', '1');
ini_set('display_errors', '0');
// Ensure logs directory exists
$__logDir = __DIR__ . '/logs';
if (!is_dir($__logDir)) { @mkdir($__logDir, 0775, true); }
ini_set('error_log', $__logDir . '/php_error.log');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

$requestId = uniqid('handler_');
header('X-Request-Id: ' . $requestId);

set_exception_handler(function($e) use ($requestId) {
    error_log("[$requestId] Uncaught Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'uncaught_exception', 'message' => $e->getMessage(), 'requestId' => $requestId], JSON_UNESCAPED_UNICODE);
    }
    exit();
});

require_once 'db.php';
require_once 'helpers/Logger.php';
require_once 'helpers/BxApi.php';
require_once 'helpers/GreenApi.php';
require_once 'helpers/GreenApiPartner.php';
require_once 'helpers/CloudPaymentsApi.php';
require_once 'helpers/StripeApi.php';
require_once 'helpers/Whisper.php';

$appConfig = require_once 'config.php';
$logger = new Logger($requestId);
$logger->log("=== HANDLER.PHP INITIATED ===");

$rawInput = file_get_contents('php://input');
$logger->log("Raw input: " . $rawInput);
$data = json_decode($rawInput, true) ?? $_REQUEST;

$db = Database::getInstance();
// Determine payment provider
// Note: Process both providers' webhooks regardless of default provider.

// --- Stripe webhook ---
try {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $stripeSig = $headers['Stripe-Signature'] ?? ($headers['stripe-signature'] ?? ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''));
    $isStripeEvent = ($data['object'] ?? '') === 'event' && isset($data['type']);

    if ($stripeSig || $isStripeEvent) {
        $logger->log("Stripe webhook detected. type=" . ($data['type'] ?? 'unknown'));
        $secret = (string)($appConfig['stripe_webhook_secret'] ?? '');
        $stripeApi = new StripeApi((string)($appConfig['stripe_secret_key'] ?? ''), $logger);

        if ($secret) {
            $ok = $stripeApi->verifySignature($rawInput, (string)$stripeSig, $secret);
            if (!$ok) {
                $logger->log('Stripe signature verification failed.');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'invalid_signature']);
                exit();
            }
        } else {
            $logger->log('Stripe webhook secret not configured; skipping signature verification.');
        }

        $type = (string)($data['type'] ?? '');
        if ($type === 'checkout.session.completed') {
            $obj = (array)($data['data']['object'] ?? []);
            $metadata = (array)($obj['metadata'] ?? []);
            $memberId = (string)($metadata['memberId'] ?? '');
            $label = (string)($metadata['label'] ?? '');
            $tempId = (string)($metadata['tempId'] ?? '');
            $tariffPlanId = (string)($metadata['tariffPlanId'] ?? '');
            $subscriptionId = (string)($obj['subscription'] ?? '');

            if ($memberId === '' || $label === '' || $tempId === '' || $tariffPlanId === '') {
                throw new Exception('Missing required metadata in Stripe checkout.session.completed');
            }

            $portalConfig = $db->getSettingsByMemberId($memberId);
            if (!$portalConfig) {
                throw new Exception('Portal configuration not found for member_id: ' . $memberId);
            }

            // Detect pay-in-advance: tempId refers to an existing numeric instance,
            // not a placeholder waiting for first-time provisioning.
            $existingInstance = null;
            if (is_numeric($tempId)) {
                foreach (($portalConfig['instances'] ?? []) as $inst) {
                    if ((string)($inst['idInstance'] ?? '') === (string)$tempId && is_numeric($inst['idInstance'] ?? null)) {
                        $existingInstance = (array)$inst;
                        break;
                    }
                }
            }

            // New paidUntil base = max(now, current paidUntil, trialEndsAt) to preserve
            // any remaining paid/trial time when the user pays in advance.
            $baseTs = time();
            if ($existingInstance) {
                foreach (['paidUntil', 'trialEndsAt'] as $fld) {
                    $v = $existingInstance[$fld] ?? null;
                    if ($v instanceof \MongoDB\BSON\UTCDateTime) {
                        $baseTs = max($baseTs, $v->toDateTime()->getTimestamp());
                    }
                }
            }
            $newPaidUntilTs = $baseTs + 30 * 24 * 3600;

            if ($existingInstance) {
                // Pay-in-advance path: extend existing instance, skip Green API provisioning.
                $db->updateInstanceFields($memberId, (int)$tempId, [
                    'paymentStatus' => 'active',
                    'subscriptionId' => $subscriptionId,
                    'paidUntil' => new MongoDB\BSON\UTCDateTime($newPaidUntilTs * 1000),
                    'tariffPlanId' => $tariffPlanId,
                ]);
                $logger->log("Stripe pay-early: instance {$tempId} extended to " . date('Y-m-d H:i', $newPaidUntilTs) . " for member {$memberId}");
                echo json_encode(['ok' => true]);
                exit();
            }

            $partnerApi = new GreenApiPartner($appConfig['partnerToken'], $logger);
            $logger->log("Creating new paid instance (Stripe) for member {$memberId}, label {$label}");

            $path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
            $webhookUrl = 'https://' . $_SERVER['HTTP_HOST'] . $path . '/handler.php';

            $opts = ['name' => "{$memberId}:{$label}", 'webhookUrl' => $webhookUrl];
            $createResult = $partnerApi->createInstance($opts);
            $newInstanceId = $createResult['id'] ?? $createResult['idInstance'] ?? null;
            $newInstanceToken = $createResult['api_token'] ?? $createResult['apiTokenInstance'] ?? null;

            if (!$newInstanceId || !$newInstanceToken) {
                throw new Exception('Failed to create instance in Green API (Stripe). Response: ' . print_r($createResult, true));
            }

            $instanceApi = new GreenApi((int)$newInstanceId, (string)$newInstanceToken, $logger);
            $logger->log("Waiting for instance {$newInstanceId} to become authorized and online before setSettings (Stripe)...");
            $wait = $instanceApi->waitForReady(300, 10, 3, 5);
            $logger->log("waitForReady (Stripe) result for instance {$newInstanceId}: " . json_encode($wait));

            if (!empty($wait['ok'])) {
                $allWebhookSettings = [
                    'webhookUrl' => $webhookUrl,
                    'outgoingWebhook' => 'yes', 'outgoingMessageWebhook' => 'yes', 'outgoingAPIMessageWebhook' => 'yes',
                    'incomingWebhook' => 'yes', 'deviceWebhook' => 'yes', 'stateWebhook' => 'yes', 'pollMessageWebhook' => 'yes',
                    'incomingBlockWebhook' => 'yes', 'incomingCallWebhook' => 'yes', 'editedMessageWebhook' => 'yes', 'deletedMessageWebhook' => 'yes'
                ];
                $instanceApi->setSettings($allWebhookSettings);
            }

            $settings = $instanceApi->getSettings();
            $apiUrl = $settings['urlApi'] ?? 'https://api.green-api.com';

            // Compose localized plan display
            $portalLocale = strtolower((string)($portalConfig['locale'] ?? 'en'));
            if ($portalLocale === 'auto' || $portalLocale === '') { $portalLocale = 'en'; }
            $labelByLocale = [ 'en'=>'Plan:', 'ru'=>'Тариф:', 'de'=>'Tarif:', 'es'=>'Plan:', 'es-ar'=>'Plan:', 'pt-br'=>'Plano:' ];
            $planNameLoc = $db->getTariffPlanLocalizedName($tariffPlanId, $portalLocale);
            $labelLocale = $portalLocale;
            if (preg_match('/\p{Cyrillic}/u', $planNameLoc)) { $labelLocale = 'ru'; }
            $planDisplay = ($labelByLocale[$labelLocale] ?? 'Plan:') . ' ' . $planNameLoc;

            $updateData = [
                'idInstance' => (int)$newInstanceId,
                'apiTokenInstance' => (string)$newInstanceToken,
                'apiUrl' => $apiUrl,
                'state' => $wait['state'] ?? 'starting',
                'webhookUrl' => $webhookUrl,
                'paymentStatus' => 'active',
                'subscriptionId' => $subscriptionId,
                'paidUntil' => new MongoDB\BSON\UTCDateTime($newPaidUntilTs * 1000),
                'tariffPlanId' => $tariffPlanId,
                'planDisplay' => $planDisplay,
            ];
            $db->updateInstanceFields($memberId, $tempId, $updateData);

            $logger->log("Stripe checkout completed: instance {$newInstanceId} activated for member {$memberId}");
            echo json_encode(['ok' => true]);
            exit();
        }

        // For unhandled Stripe events just 200 OK
        $logger->log('Unhandled Stripe event type: ' . $type);
        echo json_encode(['ok' => true]);
        exit();
    }
} catch (Throwable $e) {
    $logger->log('EXCEPTION in Stripe webhook handler: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'stripe_webhook_error', 'message' => $e->getMessage(), 'requestId' => $requestId]);
    exit();
}

// --- CloudPayments webhook ---
if (isset($data['TransactionId']) && isset($data['Status']) && $data['Status'] === 'Completed') {
    $logger->log("CloudPayments 'Pay' webhook detected. TransactionId: " . $data['TransactionId']);

    if ($db->isTransactionProcessed((int)$data['TransactionId'])) {
        $logger->log("Transaction " . $data['TransactionId'] . " has already been processed. Exiting.");
        echo json_encode(['code' => 0]);
        exit();
    }

    try {
        $jsonData = json_decode($data['Data'], true);
        $memberId = $jsonData['memberId'] ?? null;
        $label = $jsonData['label'] ?? null;
        $tempId = $jsonData['tempId'] ?? null;
        $cardToken = $data['Token'] ?? null;
        $subscriptionId = $data['SubscriptionId'] ?? null;
        $tariffPlanId = $jsonData['tariffPlanId'] ?? null;

        if (!$memberId || !$label || !$tempId || !$cardToken || !$tariffPlanId) {
            throw new Exception("Missing required fields in CloudPayments webhook.");
        }

        $portalConfig = $db->getSettingsByMemberId($memberId);
        if (!$portalConfig) {
            throw new Exception("Portal configuration not found for member_id: " . $memberId);
        }

        // Detect pay-in-advance: existing numeric instance, not placeholder.
        $existingInstance = null;
        if (is_numeric($tempId)) {
            foreach (($portalConfig['instances'] ?? []) as $inst) {
                if ((string)($inst['idInstance'] ?? '') === (string)$tempId && is_numeric($inst['idInstance'] ?? null)) {
                    $existingInstance = (array)$inst;
                    break;
                }
            }
        }

        $baseTs = time();
        if ($existingInstance) {
            foreach (['paidUntil', 'trialEndsAt'] as $fld) {
                $v = $existingInstance[$fld] ?? null;
                if ($v instanceof \MongoDB\BSON\UTCDateTime) {
                    $baseTs = max($baseTs, $v->toDateTime()->getTimestamp());
                }
            }
        }
        $newPaidUntilTs = $baseTs + 30 * 24 * 3600;

        if ($existingInstance) {
            $db->updateInstanceFields($memberId, (int)$tempId, [
                'paymentStatus' => 'active',
                'subscriptionId' => $subscriptionId ?: ($existingInstance['subscriptionId'] ?? null),
                'paidUntil' => new MongoDB\BSON\UTCDateTime($newPaidUntilTs * 1000),
                'tariffPlanId' => $tariffPlanId,
            ]);
            $db->logTransaction((int)$data['TransactionId'], $data);
            $logger->log("CloudPayments pay-early: instance {$tempId} extended to " . date('Y-m-d H:i', $newPaidUntilTs) . " for member {$memberId}");
            echo json_encode(['code' => 0]);
            exit();
        }

        $partnerApi = new GreenApiPartner($appConfig['partnerToken'], $logger);
        $cloudPaymentsApi = new CloudPaymentsApi($appConfig['cloudpayments_public_id'], $appConfig['cloudpayments_api_secret'], $logger);

        $logger->log("Creating new paid instance in Green API for member " . $memberId . ", label " . $label);
        $path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        $webhookUrl = 'https://' . $_SERVER['HTTP_HOST'] . $path . '/handler.php';

        $opts = ['name' => "{$memberId}:{$label}", 'webhookUrl' => $webhookUrl];
        $createResult = $partnerApi->createInstance($opts);
        $newInstanceId = $createResult['id'] ?? null;
        $newInstanceToken = $createResult['api_token'] ?? null;

        if (!$newInstanceId || !$newInstanceToken) {
            throw new Exception("Failed to create instance in Green API. Response: " . print_r($createResult, true));
        }

        $finalSubscriptionId = $subscriptionId;
        if (!$finalSubscriptionId) {
            $logger->log("Webhook did not contain SubscriptionId. Creating subscription manually.");
            $tariffPlan = $db->getTariffPlanById($tariffPlanId);
            $subParams = [
                'Token' => $cardToken, 'AccountId' => $memberId,
                'Description' => "Подписка на инстанс WhatsApp '{$label}'",
                'Email' => '', 'Amount' => (float)($tariffPlan['price'] ?? $appConfig['instance_monthly_price']),
                'Currency' => $tariffPlan['currency'] ?? 'RUB', 'RequireConfirmation' => false,
                'StartDate' => date('Y-m-d\TH:i:s'), 'Interval' => 'Month', 'Period' => 1
            ];
            $subResult = $cloudPaymentsApi->createSubscription($subParams);
            if ($subResult['Success']) {
                $finalSubscriptionId = $subResult['Model']['Id'];
                $logger->log("Successfully created subscription: " . $finalSubscriptionId);
            } else {
                $logger->log("CRITICAL: Failed to create CloudPayments subscription. Message: " . ($subResult['Message'] ?? 'Unknown error'));
            }
        }

        $logger->log("Preparing to subscribe webhooks for new paid instance " . $newInstanceId);
        $instanceApi = new GreenApi((int)$newInstanceId, (string)$newInstanceToken, $logger);

        // Ждем готовности инстанса
        $logger->log("Waiting for instance {$newInstanceId} to become authorized and online before setSettings...");
        $wait = $instanceApi->waitForReady(300, 10, 3, 5);
        $logger->log("waitForReady result for instance {$newInstanceId}: " . json_encode($wait));

        if (!empty($wait['ok'])) {
            $logger->log("Setting all webhooks for new paid instance " . $newInstanceId);
            $allWebhookSettings = [
                'webhookUrl' => $webhookUrl,
                'outgoingWebhook' => 'yes', 'outgoingMessageWebhook' => 'yes', 'outgoingAPIMessageWebhook' => 'yes',
                'incomingWebhook' => 'yes', 'deviceWebhook' => 'yes', 'stateWebhook' => 'yes', 'pollMessageWebhook' => 'yes',
                'incomingBlockWebhook' => 'yes', 'incomingCallWebhook' => 'yes', 'editedMessageWebhook' => 'yes', 'deletedMessageWebhook' => 'yes'
            ];
            $instanceApi->setSettings($allWebhookSettings);
        } else {
            $logger->log("Instance {$newInstanceId} is not ready yet (state={$wait['state']} status={$wait['status']}). Skipping webhook subscription for now.");
        }

        $settings = $instanceApi->getSettings();
        $apiUrl = $settings['urlApi'] ?? 'https://api.green-api.com';

        // Compose localized plan display
        $portalLocale = strtolower((string)($portalConfig['locale'] ?? 'en'));
        if ($portalLocale === 'auto' || $portalLocale === '') {
            $portalLocale = 'en';
        }
        $labelByLocale = [ 'en'=>'Plan:', 'ru'=>'Тариф:', 'de'=>'Tarif:', 'es'=>'Plan:', 'es-ar'=>'Plan:', 'pt-br'=>'Plano:' ];
        $planNameLoc = $db->getTariffPlanLocalizedName($tariffPlanId, $portalLocale);
        $planDisplay = ($labelByLocale[$portalLocale] ?? 'Plan:') . ' ' . $planNameLoc;

        $updateData = [
            'idInstance' => (int)$newInstanceId,
            'apiTokenInstance' => (string)$newInstanceToken,
            'apiUrl' => $apiUrl,
            'state' => $wait['state'] ?? 'starting',
            'webhookUrl' => $webhookUrl,
            'paymentStatus' => 'active',
            'subscriptionId' => $finalSubscriptionId,
            'paidUntil' => new MongoDB\BSON\UTCDateTime($newPaidUntilTs * 1000),
            'tariffPlanId' => $tariffPlanId,
            'planDisplay' => $planDisplay,
        ];
        $db->updateInstanceFields($memberId, $tempId, $updateData);

        $db->logTransaction((int)$data['TransactionId'], $data);
        $logger->log("Successfully processed transaction " . $data['TransactionId'] . ". Instance " . $newInstanceId . " is active.");

    } catch (Exception $e) {
        $logger->log("EXCEPTION in CloudPayments webhook handler: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage(), 'requestId' => $requestId]);
        exit();
    }

    echo json_encode(['code' => 0]);
    exit();
}

// --- Stripe webhook ---
if (isset($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
    $logger->log('Stripe webhook detected');
    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $secret = (string)($appConfig['stripe_webhook_secret'] ?? '');
    $stripeApi = new StripeApi((string)$appConfig['stripe_secret_key'], $logger);
    if ($secret && !$stripeApi->verifySignature($rawInput, $sigHeader, $secret)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_signature']);
        exit();
    }

    $event = $data;
    $eventId = $event['id'] ?? null;
    if ($eventId && $db->isEventProcessed((string)$eventId)) {
        $logger->log('Stripe event already processed: ' . $eventId);
        echo json_encode(['ok' => true]);
        exit();
    }

    try {
        if (($event['type'] ?? '') === 'checkout.session.completed') {
            $obj = $event['data']['object'] ?? [];
            $metadata = $obj['metadata'] ?? [];
            $memberId = $metadata['memberId'] ?? null;
            $label = $metadata['label'] ?? null;
            $tempId = $metadata['tempId'] ?? null;
            $tariffPlanId = $metadata['tariffPlanId'] ?? null;
            $subscriptionId = $obj['subscription'] ?? null;

            if (!$memberId || !$label || !$tempId || !$tariffPlanId) {
                $logger->log('Stripe: missing metadata. Skipping.');
                echo json_encode(['ok' => true]);
                exit();
            }

            $portalConfig = $db->getSettingsByMemberId($memberId);
            if (!$portalConfig) {
                throw new Exception('Portal configuration not found for member_id: ' . $memberId);
            }

            $partnerApi = new GreenApiPartner($appConfig['partnerToken'], $logger);
            $path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
            $scheme = 'https';
            $webhookUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $path . '/handler.php';

            $opts = ['name' => "{$memberId}:{$label}", 'webhookUrl' => $webhookUrl];
            $createResult = $partnerApi->createInstance($opts);
            $newInstanceId = $createResult['id'] ?? null;
            $newInstanceToken = $createResult['api_token'] ?? null;
            if (!$newInstanceId || !$newInstanceToken) {
                throw new Exception('Failed to create instance in Green API. Response: ' . print_r($createResult, true));
            }

            $instanceApi = new GreenApi((int)$newInstanceId, (string)$newInstanceToken, $logger);
            $logger->log("Waiting for instance {$newInstanceId} to be ready before setSettings...");
            $wait = $instanceApi->waitForReady(300, 10, 3, 5);
            if (!empty($wait['ok'])) {
                $allWebhookSettings = [
                    'webhookUrl' => $webhookUrl,
                    'outgoingWebhook' => 'yes', 'outgoingMessageWebhook' => 'yes', 'outgoingAPIMessageWebhook' => 'yes',
                    'incomingWebhook' => 'yes', 'deviceWebhook' => 'yes', 'stateWebhook' => 'yes', 'pollMessageWebhook' => 'yes',
                    'incomingBlockWebhook' => 'yes', 'incomingCallWebhook' => 'yes', 'editedMessageWebhook' => 'yes', 'deletedMessageWebhook' => 'yes'
                ];
                $instanceApi->setSettings($allWebhookSettings);
            }

            $settings = $instanceApi->getSettings();
            $apiUrl = $settings['urlApi'] ?? 'https://api.green-api.com';

            // Determine paidUntil from subscription if possible
            $paidUntilTs = time() + 30 * 24 * 3600; // fallback: +30 days
            if ($subscriptionId) {
                $sub = $stripeApi->retrieveSubscription($subscriptionId);
                if (!empty($sub['ok']) && !empty($sub['data']['current_period_end'])) {
                    $paidUntilTs = (int)$sub['data']['current_period_end'];
                }
            }

            $updateData = [
                'idInstance' => (int)$newInstanceId,
                'apiTokenInstance' => (string)$newInstanceToken,
                'apiUrl' => $apiUrl,
                'state' => $wait['state'] ?? 'starting',
                'webhookUrl' => $webhookUrl,
                'paymentStatus' => 'active',
                'subscriptionId' => $subscriptionId,
                'paidUntil' => new MongoDB\BSON\UTCDateTime($paidUntilTs * 1000),
                'tariffPlanId' => $tariffPlanId,
            ];
            $db->updateInstanceFields($memberId, $tempId, $updateData);

            if ($eventId) { $db->logEvent((string)$eventId, $event); }
            echo json_encode(['ok' => true]);
            exit();
        }
    } catch (Throwable $e) {
        $logger->log('EXCEPTION in Stripe webhook handler: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit();
    }

    // Unknown or unhandled event types
    if (!empty($eventId)) { $db->logEvent((string)$eventId, $event); }
    echo json_encode(['ok' => true]);
    exit();
}

// --- Green API webhooks (inbound) ---
if (isset($data['typeWebhook'])) {
    $logger->log("[INBOUND] Received a webhook from Green API of type: " . $data['typeWebhook']);
    $idInstance = $data['instanceData']['idInstance'] ?? null;
    if (!$idInstance) {
        $logger->log("[INBOUND] ERROR: idInstance not found in Green API webhook.");
        exit();
    }

    $portal = $db->getPortalByInstanceId($idInstance);
    if (!$portal) {
        $logger->log("[INBOUND][instance=" . $idInstance . "] ERROR: Portal not found for instance. Ignoring webhook.");
        exit();
    }

    $memberId = $portal['member_id'];
    $portalConnectorId = $portal['connector_id'] ?? 'yodo_wa';
    $instanceData = null;
    foreach ($portal['instances'] as $inst) {
        if ($inst['idInstance'] == $idInstance) {
            $instanceData = (array)$inst;
            break;
        }
    }

    if (!$instanceData) {
        $logger->log("[INBOUND][member=" . $memberId . "][instance=" . $idInstance . "] ERROR: Instance data not found in portal. Ignoring webhook.");
        exit();
    }

    $lineId = $instanceData['selected_line_id'] ?? null;
    $logContext = "[INBOUND][member=" . $memberId . "][instance=" . $idInstance . "][line=" . $lineId . "]";

    switch ($data['typeWebhook']) {
        case 'stateInstanceChanged':
            $newState = $data['stateInstance'] ?? 'unknown';
            $db->updateInstanceFields($memberId, $idInstance, ['state' => $newState]);
            $logger->log($logContext . " OK: Instance state changed to " . $newState . ".");
            break;

        case 'incomingMessageReceived':
            $logger->log($logContext . " Processing incoming message.");
            if (!$lineId) {
                $logger->log($logContext . " ERROR: Instance has no selected open line. Ignoring message.");
                echo json_encode(['ok' => false, 'error' => 'line_not_bound', 'requestId' => $requestId]);
                exit();
            }

            // Idempotency: a replayed webhook (manual or worker retry) must
            // not double-post the same message into Bitrix24 Open Lines.
            $idMessageForDedupe = (string)($data['idMessage'] ?? '');
            if ($idMessageForDedupe !== '') {
                $isNew = $db->rememberProcessedMessage((string)$memberId, $idMessageForDedupe);
                if (!$isNew) {
                    $logger->log($logContext . " DEDUPE: idMessage {$idMessageForDedupe} already processed, skipping.");
                    echo json_encode(['ok' => true, 'dedup' => true, 'requestId' => $requestId]);
                    exit();
                }
            }

            $bxApi = new BxApi($portal, $appConfig, $db, $logger);
            $senderData = $data['senderData'] ?? [];
            $rawChatId = $senderData['chatId'] ?? null;
            if (!$rawChatId) {
                $logger->log($logContext . " ERROR: Webhook missing senderData.chatId.");
                exit();
            }

            // Skip group chats (@g.us) unless the portal has the group_messages Premium feature enabled.
            if (strpos($rawChatId, '@g.us') !== false) {
                $portalFeatures = $db->getPortalFeatures($portal);
                $groupPref = !empty($portal['preferences']['group_messages']);
                if (empty($portalFeatures['group_messages']) || !$groupPref) {
                    $logger->log($logContext . ' [INBOUND] Skipping group message from ' . $rawChatId . ' (group_messages not enabled).');
                    echo json_encode(['ok' => true, 'skipped' => 'group_message']);
                    exit();
                }
            }

            $chatId = preg_replace('/[^\d]/', '', $rawChatId);
            $senderName = $senderData['senderName'] ?? $chatId;

            // Подготовка безопасного имени (ограничение 25 символов по доке B24)
            $rawName = $senderName;
            $sanitized = preg_replace('/[^\p{L} \-\']+/u', ' ', (string)$rawName); // только буквы, пробел, дефис, апостроф
            $sanitized = trim(preg_replace('/\s+/', ' ', $sanitized));
            if ($sanitized === '') { $sanitized = 'WhatsApp User'; }
            $parts = preg_split('/\s+/', $sanitized);
            $firstName = mb_substr($parts[0] ?? 'User', 0, 25, 'UTF-8');
            $lastName = '';
            if (count($parts) > 1) {
                $rest = implode(' ', array_slice($parts, 1));
                $lastName = mb_substr($rest, 0, 25, 'UTF-8');
            }

            $message = ['id' => $data['idMessage'], 'date' => time(), 'text' => null, 'files' => []];
            $messageData = $data['messageData'] ?? [];
            $textMessage = '';

            if (isset($messageData['textMessageData'])) {
                $textMessage = $messageData['textMessageData']['textMessage'] ?? '';
            } elseif (isset($messageData['extendedTextMessageData'])) {
                $textMessage = $messageData['extendedTextMessageData']['text'] ?? '';
            }

            if (isset($messageData['fileMessageData'])) {
                $fileData = $messageData['fileMessageData'];
                if (!empty($fileData['downloadUrl'])) {
                    $message['files'][] = [
                        'url' => $fileData['downloadUrl'],
                        'name' => $fileData['fileName'] ?? basename($fileData['downloadUrl'])
                    ];
                    if (!empty($fileData['caption'])) {
                        $textMessage = $fileData['caption'];
                    }
                }

                // Voice transcription via OpenAI Whisper — opt-in per portal.
                if (
                    (($messageData['typeMessage'] ?? '') === 'audioMessage')
                    && !empty($portal['preferences']['voice_transcription'])
                    && !empty($fileData['downloadUrl'])
                ) {
                    try {
                        $transcript = transcribeAudio(
                            $fileData['downloadUrl'],
                            $fileData['mimeType'] ?? 'audio/ogg',
                            $appConfig,
                            $logger,
                            $logContext
                        );
                        if ($transcript !== '') {
                            $textMessage = trim(($textMessage !== '' ? $textMessage . "\n" : '') . '🎙 ' . $transcript);
                            $logger->log($logContext . ' Whisper OK: ' . mb_strlen($transcript) . ' chars.');
                        } else {
                            $logger->log($logContext . ' Whisper returned empty text.');
                        }
                    } catch (\Throwable $e) {
                        $logger->log($logContext . ' Whisper failed: ' . $e->getMessage());
                    }
                }
            }

            if (isset($messageData['imageMessageData'])) {
                $img = $messageData['imageMessageData'];
                if (!empty($img['downloadUrl'])) {
                    $nameFromUrl = basename(parse_url($img['downloadUrl'], PHP_URL_PATH));
                    $message['files'][] = [
                        'url' => $img['downloadUrl'],
                        'name' => $img['fileName'] ?? ($nameFromUrl ?: 'image.jpg'),
                    ];
                    if (!empty($img['caption'])) {
                        $textMessage = $img['caption'];
                    }
                }
            }

            $message['text'] = $textMessage;

            if (empty($message['text']) && empty($message['files'])) {
                $logger->log($logContext . " INFO: Ignoring empty message from " . $chatId . ".");
                exit();
            }

            $userPayload = [
                'id' => $chatId,
                'name' => $firstName,
                'phone' => '+' . $chatId,
                'skip_phone_validate' => 'Y',
            ];
            if ($lastName !== '') { $userPayload['last_name'] = $lastName; }

            $params = [
                'CONNECTOR' => $portalConnectorId,
                'LINE' => (int)$lineId,
                'MESSAGES' => [[
                    'user' => $userPayload,
                    'message' => $message,
                    'chat' => ['id' => $chatId]
                ]],
            ];

            $logger->log($logContext . " Sending message to B24 imconnector.send.messages. Params: " . json_encode($params, JSON_UNESCAPED_UNICODE));
            $result = $bxApi->callMethod('imconnector.send.messages', $params);
            $logger->log($logContext . " OK: B24 response: " . print_r($result, true));
            break;
    }
    exit();
}

// --- Bitrix24 events (outbound) ---
if (isset($data['event'])) {
    $memberId = $data['auth']['member_id'] ?? null;
    if (!$memberId) {
        $logger->log("[EVENT] ERROR: Received a B24 event without a member_id.");
        exit();
    }

    $portal = $db->getSettingsByMemberId($memberId);
    if (!$portal) {
        $logger->log("[EVENT][member=" . $memberId . "] ERROR: Portal not found for received event.");
        exit();
    }

switch ($data['event']) {
        case 'ONIMCONNECTORMESSAGEADD':
            $lineId = $data['data']['LINE'] ?? null;
            $instance = $db->findInstanceByLineId($memberId, $lineId);
            $idInstance = $instance['idInstance'] ?? 'not_found';
            $logContext = "[OUTBOUND][member=" . $memberId . "][instance=" . $idInstance . "][line=" . $lineId . "]";

            $logger->log($logContext . " Received ONIMCONNECTORMESSAGEADD event from B24.");

            $portalConnectorId = $portal['connector_id'] ?? 'yodo_wa';
            $eventConnectorId = $data['data']['CONNECTOR'] ?? null;

            if ($eventConnectorId !== $portalConnectorId) {
                $logger->log($logContext . " INFO: Ignoring event for a different connector: " . $eventConnectorId);
                exit();
            }

            if (!$instance) {
                $logger->log($logContext . " ERROR: No instance found bound to this line. Cannot send message.");
                exit();
            }

            $greenApi = new GreenApi((int)$instance['idInstance'], $instance['apiTokenInstance'], $logger, $instance['apiUrl'] ?? null);
            $bxApi = new BxApi($portal, $appConfig, $db, $logger);

            // Локальная функция: нормализация BB-кодов Bitrix24 в простой текст (для WhatsApp)
            $bbToPlain = static function (?string $t): string {
                if ($t === null) return '';
                // Сносим префикс [b]Operator:[/b] [br]
                $t = preg_replace('/^\s*\[b\][^\]]+\[\/b\]\s*:?(?:\s*\[br\]\s*)+/ui', '', $t ?? '');
                // Переносы строк
                $t = preg_replace('/\[br\]/i', "\n", $t);
                // URL теги
                $t = preg_replace('/\[url\](.*?)\[\/url\]/i', '$1', $t);
                $t = preg_replace('/\[url=(.*?)\](.*?)\[\/url\]/i', '$2 ($1)', $t);
                // Простые теги форматирования — удаляем
                $t = preg_replace('/\[(\/)?(b|i|u|s)\]/i', '', $t);
                // Очистка лишних пробелов
                $t = preg_replace('/[ \t]+/u', ' ', $t);
                $t = preg_replace('/\n{3,}/', "\n\n", $t);
                return trim($t);
            };

            foreach (($data['data']['MESSAGES'] ?? []) as $msg) {
                $chatId = $msg['chat']['id'] ?? null;
                if (!$chatId) {
                    $logger->log($logContext . " ERROR: Message item is missing chat.id.");
                    continue;
                }

                $sentMessageIds = [];
                $text = $msg['message']['text'] ?? null;
                $text = $bbToPlain($text);
                $files = $msg['message']['files'] ?? [];

                $logger->log($logContext . " Processing message for chat " . $chatId . ". Text: " . ($text ? 'Yes' : 'No') . ", Files: " . count($files));

                if ($text !== '') {
                    $logger->log($logContext . " Sending text message to " . $chatId . ".");
                    $res = $greenApi->sendTextMessage($chatId, $text);
                    $logger->log($logContext . " Green API response for text message: " . print_r($res, true));
                    if (!empty($res['idMessage'])) {
                        $sentMessageIds[] = $res['idMessage'];
                    }
                }

                foreach ($files as $file) {
                    // Bitrix24 может прислать ссылку в ключе 'url' или 'link'
                    $fileUrl = $file['url'] ?? $file['link'] ?? $file['URL'] ?? $file['LINK'] ?? null;
                    if (!$fileUrl) {
                        $logger->log($logContext . " ERROR: File item is missing a URL (no 'url'/'link'). Item: " . print_r($file, true));
                        continue;
                    }
                    $fileName = $file['name'] ?? basename(parse_url($fileUrl, PHP_URL_PATH));
                    $fileType = $file['type'] ?? $file['mime'] ?? '';
                    $isImage = ($fileType === 'image') || (strpos($fileType, 'image/') === 0) || preg_match('/\.(jpe?g|png|gif|webp)$/i', (string)$fileName);

                    $logger->log($logContext . " Sending file to " . $chatId . ". URL: " . $fileUrl . ", name: " . $fileName . ", isImage=" . ($isImage ? 'yes' : 'no'));
                    $res = $isImage
                        ? $greenApi->sendImageByUrl($chatId, $fileUrl, $text)
                        : $greenApi->sendFileByUrl($chatId, $fileUrl, $fileName ?: 'file.bin', $text);
                    $logger->log($logContext . " Green API response for file: " . print_r($res, true));
                    if (!empty($res['idMessage'])) {
                        $sentMessageIds[] = $res['idMessage'];
                    }
                }

                if (!empty($sentMessageIds)) {
                    $deliveryParams = [
                        'CONNECTOR' => $portalConnectorId,
                        'LINE' => (int)$lineId,
                        'MESSAGES' => [[
                            'im' => $msg['im'],
                            'message' => ['id' => $sentMessageIds],
                            'chat' => ['id' => $msg['chat']['id']]
                        ]]
                    ];
                    $logger->log($logContext . " Sending delivery status to B24. Params: " . json_encode($deliveryParams));
                    $deliveryResult = $bxApi->callMethod('imconnector.send.status.delivery', $deliveryParams);
                    $logger->log($logContext . " OK: B24 response for delivery status: " . print_r($deliveryResult, true));
                }
            }
            break;

        case 'ONAPPUNINSTALL':
            $logContext = "[EVENT][member=" . $memberId . "]";
            $logger->log($logContext . " Received ONAPPUNINSTALL event. Settings will be preserved.");
            $db->logUninstall($memberId, $portal);
            $logger->log($logContext . " OK: App uninstalled event processed. Settings were kept.");
            break;
    }
    exit();
}

$logger->log("Handler finished without specific action.");
echo json_encode(['ok' => true]);
