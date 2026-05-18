<?php
declare(strict_types=1);

/**
 * migrate-from-green-api.php — bulk-миграция клиентов с green-api.com на iqteco.
 *
 * Заглушка для фазы 6. Идея:
 *  - читает CSV (clientId, oldIdInstance, oldApiToken, phoneNumber, webhookUrl)
 *  - создаёт iqteco-инстанс через InstanceManager
 *  - выводит CSV (clientId, newIdInstance, newApiToken, qrUrl)
 *
 * Реализация в фазе 6.
 */

fwrite(STDERR, "not implemented (phase 6)\n");
exit(2);
