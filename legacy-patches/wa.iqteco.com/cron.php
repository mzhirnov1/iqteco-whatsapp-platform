<?php
// Файл: cron.php
// Скрипт для периодического запуска (CRON).
// Находит и деактивирует просроченные инстансы.
//
// Пример записи в crontab (каждые 4 часа):
// 0 */4 * * * /usr/bin/php /var/www/my.yodo.im/bitrix24/cron.php > /dev/null 2>&1

// Устанавливаем рабочую директорию, чтобы require_once работали корректно
chdir(dirname(__FILE__));
// Ensure logs directory for error logs
if (!is_dir(__DIR__ . '/logs')) { @mkdir(__DIR__ . '/logs', 0775, true); }
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/php_error.log');

require_once 'db.php';
require_once 'helpers/Logger.php';
require_once 'helpers/GreenApiPartner.php';
require_once 'helpers/InstancePool.php';

$appConfig = require_once 'config.php';
$requestId = uniqid('cron_');
$logger = new Logger($requestId);
$logger->log("=== CRON.PHP INITIATED ===");

$db = Database::getInstance();
$partnerApi = new GreenApiPartner($appConfig['partnerToken'], $logger);

$expiredInstancesIterator = $db->findExpiredInstances();
$processedCount = 0;

foreach ($expiredInstancesIterator as $item) {
    $portal = $item['portal'];
    $instance = $item['instance'];
    $memberId = $portal['member_id'];
    $idInstance = $instance['idInstance'];

    $logger->log("Processing expired instance {$idInstance} for member {$memberId}. Status: {$instance['paymentStatus']}");

    try {
        // 1. Удаляем инстанс из Green API
        $logger->log("Deleting instance {$idInstance} from Green API...");
        $deleteResult = $partnerApi->deleteInstanceAccount((int)$idInstance);
        if (isset($deleteResult['error'])) {
            // Если есть ошибка, логируем, но не прерываем процесс
            $logger->log("WARNING: Failed to delete instance {$idInstance} from Green API. Response: " . print_r($deleteResult, true));
        }

        // 2. Обновляем статус в нашей БД
        $updateData = [
            'paymentStatus' => 'expired',
            'state' => 'deleted', // Внутренний статус
            'apiTokenInstance' => null, // Обнуляем токен
        ];
        $db->updateInstanceFields($memberId, (int)$idInstance, $updateData);
        $logger->log("Instance {$idInstance} status updated to 'expired' in DB.");
        
        // ВАЖНО: Подписку в CloudPayments не трогаем, согласно ТЗ.
        // Следующий успешный платеж по ней создаст инстанс заново через вебхук.
        
        $processedCount++;

    } catch (Exception $e) {
        $logger->log("EXCEPTION during cron processing for instance {$idInstance}: " . $e->getMessage());
        continue; // Переходим к следующему инстансу
    }
}

$logger->log("Processed {$processedCount} expired instances. Now checking instance pool...");

// Top up the pre-warmed instance pool so the next onboarding can claim in <5s.
try {
    $baseUrl = rtrim((string)($appConfig['app_base_url'] ?? 'https://wa.iqteco.com'), '/');
    $poolCreated = InstancePool::topUp($partnerApi, $db, $logger, $baseUrl . '/handler.php');
    $logger->log("Pool top-up created {$poolCreated} new spare(s).");
} catch (Throwable $e) {
    $logger->log("Pool top-up EXCEPTION: " . $e->getMessage());
}

// Health-check on OAuth state. We do NOT proactively refresh tokens here
// (Bitrix24 docs explicitly discourage that — refresh on-demand at the
// moment of need, with single-flight + CAS, is the right pattern). We only
// surface portals that have been silently broken.
try {
    $brokenPortals = $db->portalsNeedingRelink(3);
    if (!empty($brokenPortals)) {
        $alertEmail = $appConfig['alert_email'] ?? null;
        $alertLines = [];
        foreach ($brokenPortals as $bp) {
            $domain = $bp['domain'] ?? $bp['member_id'];
            $failures = (int)($bp['consecutive_refresh_failures'] ?? 0);
            $err = $bp['last_refresh_error'] ?? 'unknown';
            $when = '';
            if (!empty($bp['last_refresh_failed_at']) && $bp['last_refresh_failed_at'] instanceof MongoDB\BSON\UTCDateTime) {
                $when = ' at ' . $bp['last_refresh_failed_at']->toDateTime()->format('c');
            }
            $line = "BROKEN PORTAL: {$domain} (member_id={$bp['member_id']}), failures={$failures}, error={$err}{$when}";
            $logger->log($line);
            $alertLines[] = $line;
        }
        if ($alertEmail && !empty($alertLines)) {
            $subject = '[wa.iqteco] ' . count($alertLines) . ' Bitrix24 portal(s) need re-link';
            $body = "The following Bitrix24 portals could not refresh their OAuth tokens:\n\n"
                  . implode("\n", $alertLines)
                  . "\n\nAsk the affected customers to re-install the app from their Bitrix24 marketplace.\n";
            @mail($alertEmail, $subject, $body);
            $logger->log("Sent alert email to {$alertEmail}");
        }
    } else {
        $logger->log("Health check: no portals needing re-link.");
    }
} catch (Throwable $e) {
    $logger->log("Health check EXCEPTION: " . $e->getMessage());
}

$logger->log("=== CRON.PHP FINISHED. Processed {$processedCount} expired instances. ===");
echo "Cron job finished. Processed {$processedCount} expired instances, pool top-up completed.\n";
