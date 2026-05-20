<?php
/**
 * File: install.php
 * Handles application installation and authorization from Bitrix24.
 */
$appConfig = require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/Logger.php';
require_once __DIR__ . '/helpers/BxApi.php';
require_once __DIR__ . '/helpers/I18n.php';

// Route PHP error logs to logs/
if (!is_dir(__DIR__ . '/logs')) { @mkdir(__DIR__ . '/logs', 0775, true); }
ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('error_log', __DIR__ . '/logs/php_error.log');

$requestId = uniqid('install_');
$logger = new Logger($requestId);
$db = Database::getInstance();

// Extract and sanitize authorization data from the request.
$auth = $_REQUEST;
$memberId = htmlspecialchars($auth['member_id']);
$existingPortal = $db->getSettingsByMemberId($memberId);

// Guard against double-install (e.g. user double-clicked the install button
// in Bitrix24, or B24 itself fired install_finish twice). Without this,
// two install.php processes can each get a fresh grant from B24 and the
// race to write — last-write-wins corrupts auth state.
if ($existingPortal && !empty($existingPortal['last_install_at'])) {
    $lastInstallTs = ($existingPortal['last_install_at'] instanceof MongoDB\BSON\UTCDateTime)
        ? (int)($existingPortal['last_install_at']->toDateTime()->format('U'))
        : 0;
    if ($lastInstallTs > 0 && (time() - $lastInstallTs) < 30) {
        $logger->log("DOUBLE-INSTALL guard: last install was " . (time() - $lastInstallTs) . "s ago; treating this request as the duplicate. Old AUTH_ID prefix=" . substr((string)($existingPortal['access_token'] ?? ''), 0, 8) . ", new AUTH_ID prefix=" . substr((string)($auth['AUTH_ID'] ?? ''), 0, 8));
        // Still finish the JS install handshake so Bitrix24 closes the dialog,
        // but do NOT overwrite tokens.
        $redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'] . '?MEMBER_ID=' . urlencode($memberId);
        echo '<!doctype html><html><head><meta charset="utf-8"></head><body>
<script src="//api.bitrix24.com/api/v1/"></script>
<script>BX24.init(function(){ try { BX24.installFinish(); } catch(e) {} location.replace(' . json_encode($redirect) . '); });</script></body></html>';
        exit();
    }
}

$updateData = [
    'member_id'       => $memberId,
    'domain'          => htmlspecialchars($auth['DOMAIN']),
    'access_token'    => htmlspecialchars($auth['AUTH_ID']),
    'refresh_token'   => htmlspecialchars($auth['REFRESH_ID']),
    'token_expires'   => time() + (int)($auth['AUTH_EXPIRES'] ?? 3600),
    'client_endpoint' => 'https://' . htmlspecialchars($auth['DOMAIN']) . '/rest',
    'server_endpoint' => 'https://oauth.bitrix.info/oauth/token/',
    'last_updated'    => new MongoDB\BSON\UTCDateTime(),
    'last_install_at' => new MongoDB\BSON\UTCDateTime(),
    'needs_relink'    => false,
    'consecutive_refresh_failures' => 0,
    'last_refresh_error' => null,
];

if (!$existingPortal) {
    // Новая установка
    $updateData['instances'] = [];
    $updateData['selected_line_id'] = null;
    $updateData['installation_time'] = new MongoDB\BSON\UTCDateTime();
    $updateData['connector_id'] = 'yodo_wa'; // Устанавливаем ID коннектора по умолчанию
} elseif (empty($existingPortal['connector_id'])) {
    // Обновление для старых порталов без ID
    $updateData['connector_id'] = 'yodo_wa';
}

$logger->log('Saving portal settings (AUTH_ID prefix=' . substr((string)$auth['AUTH_ID'], 0, 8) . ', REFRESH_ID prefix=' . substr((string)$auth['REFRESH_ID'], 0, 8) . ', expires_in=' . (int)($auth['AUTH_EXPIRES'] ?? 3600) . ')');
$db->savePortalSettings($memberId, $updateData);
$logger->log('Portal settings saved.');

// Bind the ONAPPUNINSTALL event handler.
$portalConfigForBinding = array_merge((array)($existingPortal ?? []), $updateData);
$bxApi = new BxApi($portalConfigForBinding, $appConfig, $db, $logger);

// Регистрация коннектора в Битрикс24
$logger->log('Registering connector...');
$connectorId = $portalConfigForBinding['connector_id'] ?? 'yodo_wa';
// dirname() returns '/' for a root-level script, so concatenating '/handler.php'
// naively produces '//handler.php'. Strip any trailing slash first.
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$handlerUrl = 'https://' . $_SERVER['HTTP_HOST'] . $basePath . '/handler.php';

// Минимальная регистрация коннектора с обязательными полями из документации
$iconSvgData = 'data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20viewBox%3D%220%200%2010%2010%22%3E%3Crect%20width%3D%2210%22%20height%3D%2210%22%20fill%3D%22%232db742%22/%3E%3C/svg%3E';
$regResult = $bxApi->callMethod('imconnector.register', [
    'ID' => $connectorId,
    'NAME' => 'WhatsApp (Green API)',
    'ICON' => [
        'DATA_IMAGE' => $iconSvgData,
        'COLOR' => '#2db742',
        'SIZE' => '100%',
        'POSITION' => 'center',
    ],
    'ICON_DISABLED' => [
        'DATA_IMAGE' => $iconSvgData,
        'COLOR' => '#c0c0c0',
        'SIZE' => '100%',
        'POSITION' => 'center',
    ],
    'PLACEMENT_HANDLER' => $handlerUrl,
]);
$logger->log('imconnector.register result: ' . print_r($regResult, true));

// imconnector.register resets CONFIGURED/STATUS for all lines.
// Re-activate the connector for every line that already has an instance bound,
// otherwise imconnector.send.messages returns NOT_ACTIVE_LINE after reinstall.
try {
    $existingInstances = $existingPortal['instances'] ?? [];
    foreach ($existingInstances as $inst) {
        $inst = (array)$inst;
        $lineId = $inst['selected_line_id'] ?? null;
        if (!$lineId) continue;
        $logger->log("Re-activating connector {$connectorId} for line {$lineId} after register...");
        $bxApi->callMethod('imconnector.activate', ['CONNECTOR' => $connectorId, 'LINE' => (int)$lineId, 'ACTIVE' => true]);
        $bxApi->callMethod('imconnector.connector.data.set', [
            'CONNECTOR' => $connectorId,
            'LINE'      => (int)$lineId,
            'DATA'      => ['id' => $connectorId . '_line_' . (int)$lineId, 'name' => 'WhatsApp'],
        ]);
        $st = $bxApi->callMethod('imconnector.status', ['CONNECTOR' => $connectorId, 'LINE' => (int)$lineId]);
        $logger->log("imconnector.status for line {$lineId} after reactivation: " . print_r($st, true));
    }
} catch (Throwable $e) {
    $logger->log('WARNING: connector reactivation after register failed: ' . $e->getMessage());
}

// Логируем общий статус коннектора (диагностика)
$status = $bxApi->callMethod('imconnector.status', ['CONNECTOR' => $connectorId]);
$logger->log('imconnector.status after register: ' . print_r($status, true));

// CRM placements: кнопки "WhatsApp: написать" в карточках Контакта/Лида/Сделки
try {
    $placementUrl = 'https://' . $_SERVER['HTTP_HOST'] . $basePath . '/placements/wa_write_first.php';
    // Toolbar button + activity timeline menu + in-timeline block on
    // Contact / Lead / Deal cards. Bitrix24 does not expose an activity
    // timeline-menu placement for Contact — only Lead / Deal / Quote /
    // Smart Invoice — so DETAIL_ACTIVITY is the fallback for contacts.
    $placements = [
        'CRM_CONTACT_DETAIL_TOOLBAR',
        'CRM_LEAD_DETAIL_TOOLBAR',
        'CRM_DEAL_DETAIL_TOOLBAR',
        'CRM_LEAD_ACTIVITY_TIMELINE_MENU',
        'CRM_DEAL_ACTIVITY_TIMELINE_MENU',
        'CRM_CONTACT_DETAIL_ACTIVITY',
        'CRM_LEAD_DETAIL_ACTIVITY',
        'CRM_DEAL_DETAIL_ACTIVITY',
        'CRM_DEAL_DETAIL_TAB',
    ];
    foreach ($placements as $pl) {
        $logger->log('Binding placement ' . $pl . ' to ' . $placementUrl);
        $bindPlacement = $bxApi->callMethod('placement.bind', [
            'PLACEMENT' => $pl,
            'HANDLER' => $placementUrl,
            'TITLE' => 'WhatsApp',
            'DESCRIPTION' => 'Send a WhatsApp message',
            'LANG_ALL' => [
                'en' => ['TITLE' => 'WhatsApp', 'DESCRIPTION' => 'Send a WhatsApp message'],
                'ru' => ['TITLE' => 'WhatsApp', 'DESCRIPTION' => 'Написать в WhatsApp'],
            ],
        ]);
        $logger->log('placement.bind ' . $pl . ' result: ' . print_r($bindPlacement, true));
    }
} catch (Throwable $e) {
    $logger->log('WARNING: placement.bind failed: ' . $e->getMessage());
}

$logger->log('Binding ONAPPUNINSTALL event...');
$bindRes = $bxApi->callMethod('event.bind', ['EVENT' => 'ONAPPUNINSTALL', 'HANDLER' => $handlerUrl]);
$logger->log('event.bind ONAPPUNINSTALL result: ' . print_r($bindRes, true));

// Привязка события ONIMCONNECTORMESSAGEADD для исходящих сообщений
$logger->log('Binding ONIMCONNECTORMESSAGEADD event...');
$bindMsgRes = $bxApi->callMethod('event.bind', ['EVENT' => 'ONIMCONNECTORMESSAGEADD', 'HANDLER' => $handlerUrl]);
$logger->log('event.bind ONIMCONNECTORMESSAGEADD result: ' . print_r($bindMsgRes, true));


// Detect Bitrix24 user's interface language so the app opens in the right
// locale without forcing the admin to switch it manually. user.current
// returns LANGUAGE_ID (user setting) and PERSONAL_LANG (profile preference);
// we try both, mapping Bitrix24 codes to our shipped locale codes.
try {
    $cur = $bxApi->callMethod('user.current');
    $bxLang = (string)(
        ($cur['result']['LANGUAGE_ID'] ?? '')
        ?: ($cur['result']['PERSONAL_LANG'] ?? '')
    );
    if ($bxLang !== '') {
        $mapped = I18n::mapBxLangToLocale($bxLang);
        if ($mapped !== null) {
            $db->updatePortalFields($memberId, ['locale' => $mapped]);
            $logger->log("Detected Bitrix24 user language '{$bxLang}' → portal locale '{$mapped}'");
        } else {
            $logger->log("Bitrix24 user language '{$bxLang}' has no mapping, keeping auto-detection.");
        }
    } else {
        $logger->log('user.current returned no LANGUAGE_ID/PERSONAL_LANG; keeping auto-detection.');
    }
} catch (Throwable $e) {
    $logger->log('WARNING: language auto-detect via user.current failed: ' . $e->getMessage());
}

$logger->log('Running DB migration...');
$db->runMigration();
$logger->log('DB migration finished.');

// Define the redirect URL to the dashboard after installation.
$redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'] . '?MEMBER_ID=' . urlencode($memberId);

// Output the final installation script to run on the client side.
echo '<!doctype html><html><head><meta charset="utf-8"></head><body>
<script src="//api.bitrix24.com/api/v1/"></script>
<script>
BX24.init(function(){
  try { BX24.installFinish(); } catch(e) {}
  location.replace(' . json_encode($redirect) . ');
});
</script></body></html>';
