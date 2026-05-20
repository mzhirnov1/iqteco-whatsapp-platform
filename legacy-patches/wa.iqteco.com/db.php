<?php
// Файл: db.php (расширенная версия, совместимая с вашим кодом)
require_once __DIR__ . '/vendor/autoload.php';

class Database {
    private static $instance = null;
    private $mongoClient;
    private $db;
    private $collection; // portals

    private function __construct() {
        $config = require __DIR__ . '/config.php';
        $connectionString = $config['mongodb']['uri'];
        $dbName = $config['mongodb']['database'];
        $collectionName = "portals";

        try {
            $this->mongoClient = new MongoDB\Client($connectionString);
            $this->db = $this->mongoClient->selectDatabase($dbName);
            $this->collection = $this->db->selectCollection($collectionName);
        } catch (Exception $e) {
            error_log("Критическая ошибка: Не удалось подключиться к MongoDB. Сообщение от драйвера: " . $e->getMessage());
            throw $e;
        }
    }

    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    /* ==================== БАЗОВЫЕ ОПЕРАЦИИ ПО ПОРТАЛАМ ==================== */

    public function savePortalSettings($memberId, $settings) {
        $settings['member_id'] = (string)$memberId;
        return $this->collection->updateOne(
            ['member_id' => (string)$memberId],
            ['$set' => $settings],
            ['upsert' => true]
        );
    }

    public function getSettingsByMemberId($memberId) {
        $document = $this->collection->findOne(['member_id' => (string)$memberId]);
        return $document ? (array)$document : null;
    }

    public function getSettingsByDomain(string $domain) {
        $domain = strtolower($domain);
        $document = $this->collection->findOne(['domain' => $domain]);
        if (!$document) {
            // Fallback: sometimes Bitrix24 domains can be in uppercase or with trailing spaces
            $document = $this->collection->findOne(['domain' => ['$regex' => '^' . preg_quote($domain, '/') . '$', '$options' => 'i']]);
        }
        return $document ? (array)$document : null;
    }

    // Совместимость: старый метод, если вдруг где-то вызывался
    public function getSettingsByGreenApiInstance($idInstance) {
        $idStr = (string)$idInstance;
        $idNum = is_numeric($idInstance) ? (int)$idInstance : null;

        $filter = [
            'instances.idInstance' => $idNum !== null ? $idNum : $idStr
        ];
        // Пытаемся обеими типами
        $doc = $this->collection->findOne($filter) ?: $this->collection->findOne([
            'instances.idInstance' => $idNum === null ? (int)$idStr : $idStr
        ]);
        return $doc ? (array)$doc : null;
    }

    public function deletePortalSettings($memberId) {
        return $this->collection->deleteOne(['member_id' => (string)$memberId]);
    }

    public function getAllPortals() {
        $cursor = $this->collection->find();
        $result = [];
        foreach ($cursor as $document) {
            $result[] = (array)$document;
        }
        return $result;
    }

    public function updatePortalFields($memberId, $fieldsToUpdate) {
        return $this->collection->updateOne(
            ['member_id' => (string)$memberId],
            ['$set' => $fieldsToUpdate]
        );
    }

    /* ==================== OAUTH REFRESH LOCK + CAS ==================== */

    /**
     * Try to acquire a single-flight refresh lock for a portal.
     * @return bool true if this caller now holds the lock; false otherwise.
     */
    public function acquireRefreshLock(string $memberId, int $ttlSec = 15): bool {
        $now = time();
        $expiresAt = $now + $ttlSec;
        $res = $this->collection->updateOne(
            [
                'member_id' => $memberId,
                '$or' => [
                    ['refresh_lock_until' => ['$exists' => false]],
                    ['refresh_lock_until' => null],
                    ['refresh_lock_until' => ['$lt' => $now]],
                ],
            ],
            ['$set' => ['refresh_lock_until' => $expiresAt]]
        );
        return $res->getModifiedCount() === 1;
    }

    public function releaseRefreshLock(string $memberId): void {
        $this->collection->updateOne(
            ['member_id' => $memberId],
            ['$unset' => ['refresh_lock_until' => '']]
        );
    }

    /**
     * Compare-and-swap save of new tokens. Returns true iff the document was
     * updated; false means another process already replaced refresh_token
     * (caller should reload and use those fresh tokens).
     */
    public function casUpdateTokens(string $memberId, string $oldRefreshToken, array $newFields): bool {
        $newFields['last_successful_refresh_at'] = new MongoDB\BSON\UTCDateTime();
        $newFields['consecutive_refresh_failures'] = 0;
        $newFields['needs_relink'] = false;
        $newFields['last_refresh_error'] = null;
        $res = $this->collection->updateOne(
            ['member_id' => $memberId, 'refresh_token' => $oldRefreshToken],
            ['$set' => $newFields, '$unset' => ['refresh_lock_until' => '']]
        );
        return $res->getModifiedCount() === 1;
    }

    public function markNeedsRelink(string $memberId, string $error): void {
        $this->collection->updateOne(
            ['member_id' => $memberId],
            [
                '$set' => [
                    'needs_relink' => true,
                    'last_refresh_error' => $error,
                    'last_refresh_failed_at' => new MongoDB\BSON\UTCDateTime(),
                ],
                '$inc' => ['consecutive_refresh_failures' => 1],
                '$unset' => ['refresh_lock_until' => ''],
            ]
        );
    }

    /**
     * Portals that need attention from an admin (broken OAuth state).
     */
    public function portalsNeedingRelink(int $minFailures = 3): array {
        $cursor = $this->collection->find([
            '$or' => [
                ['needs_relink' => true],
                ['consecutive_refresh_failures' => ['$gte' => $minFailures]],
            ],
        ], ['projection' => ['member_id' => 1, 'domain' => 1, 'last_refresh_error' => 1,
            'last_refresh_failed_at' => 1, 'consecutive_refresh_failures' => 1, 'needs_relink' => 1]]);
        $out = [];
        foreach ($cursor as $doc) $out[] = (array)$doc;
        return $out;
    }

    /* ==================== HANDLER MESSAGE DEDUPE ==================== */

    public function processedMessagesCollection() {
        return $this->db->selectCollection('processed_messages');
    }

    /**
     * Atomically remember that we processed idMessage for memberId.
     * Returns true if newly inserted (proceed with processing),
     * false if it was already there (skip — dedupe).
     */
    public function rememberProcessedMessage(string $memberId, string $idMessage): bool {
        try {
            $this->processedMessagesCollection()->insertOne([
                'memberId' => $memberId,
                'idMessage' => $idMessage,
                'savedAt' => new MongoDB\BSON\UTCDateTime(),
            ]);
            return true;
        } catch (MongoDB\Driver\Exception\BulkWriteException $e) {
            // Unique index violation = already processed
            if (strpos($e->getMessage(), 'E11000') !== false) return false;
            throw $e;
        }
    }

    /* ==================== ИНСТАНСЫ (instances[]) ==================== */

    public function getInstancesForPortal($memberId) {
        $portal = $this->getSettingsByMemberId($memberId);
        return $portal['instances'] ?? [];
    }

    public function addInstanceToPortal($memberId, array $instanceData) {
        return $this->collection->updateOne(
            ['member_id' => (string)$memberId],
            ['$push' => ['instances' => $instanceData]]
        );
    }

    public function updateInstanceFields($memberId, $idInstance, array $fields) {
        // Пытаемся обновить по числовому id, затем по строковому
        $idNum = is_numeric($idInstance) ? (int)$idInstance : null;
        $idStr = (string)$idInstance;

        $set = [];
        foreach ($fields as $k => $v) {
            $set["instances.$.$k"] = $v;
        }

        $res = $this->collection->updateOne(
            ['member_id' => (string)$memberId, 'instances.idInstance' => $idNum !== null ? $idNum : $idStr],
            ['$set' => $set]
        );
        if ($res->getModifiedCount() === 0) {
            $res = $this->collection->updateOne(
                ['member_id' => (string)$memberId, 'instances.idInstance' => $idNum === null ? (int)$idStr : $idStr],
                ['$set' => $set]
            );
        }
        return $res;
    }

    public function removeInstanceFromPortal($memberId, $idInstance) {
        // Удаляем по обоим типам id
        $idNum = is_numeric($idInstance) ? (int)$idInstance : null;
        $idStr = (string)$idInstance;

        $res = $this->collection->updateOne(
            ['member_id' => (string)$memberId],
            ['$pull' => ['instances' => ['idInstance' => $idNum !== null ? $idNum : $idStr]]]
        );
        if ($res->getModifiedCount() === 0) {
            $res = $this->collection->updateOne(
                ['member_id' => (string)$memberId],
                ['$pull' => ['instances' => ['idInstance' => $idNum === null ? (int)$idStr : $idStr]]]
            );
        }
        return $res;
    }

    public function bindInstanceToLine(string $memberId, $idInstance, ?string $lineId)
    {
        // Шаг 1: Сначала снимаем эту линию со всех инстансов портала, чтобы избежать дублей.
        if (!empty($lineId)) {
            $this->collection->updateMany(
                ['member_id' => $memberId, 'instances.selected_line_id' => (string)$lineId],
                ['$unset' => ['instances.$[elem].selected_line_id' => ""]],
                ['arrayFilters' => [['elem.selected_line_id' => (string)$lineId]]]
            );
        }

        // Шаг 2: Теперь привязываем линию к конкретному инстансу (или отвязываем, если lineId пуст).
        $idNum = is_numeric($idInstance) ? (int)$idInstance : null;
        $idStr = (string)$idInstance;
        $targetId = $idNum !== null ? $idNum : $idStr;

        $updateOp = (empty($lineId))
            ? ['$unset' => ['instances.$.selected_line_id' => ""]]
            : ['$set' => ['instances.$.selected_line_id' => (string)$lineId]];

        $res = $this->collection->updateOne(
            ['member_id' => (string)$memberId, 'instances.idInstance' => $targetId],
            $updateOp
        );

        // Fallback для случая несоответствия типов int/string
        if ($res->getModifiedCount() === 0) {
            $fallbackId = is_int($targetId) ? (string)$targetId : (int)$targetId;
            $res = $this->collection->updateOne(
                ['member_id' => (string)$memberId, 'instances.idInstance' => $fallbackId],
                $updateOp
            );
        }

        return $res;
    }

    public function findInstanceByLineId(string $memberId, $lineId): ?array
    {
        $portal = $this->getSettingsByMemberId($memberId);
        if (!$portal || !isset($portal['instances'])) {
            return null;
        }

        $instances = $portal['instances'];
        if ($instances instanceof \MongoDB\Model\BSONArray) {
            $instances = iterator_to_array($instances);
        }

        foreach ($instances as $instance) {
            $instance = (array)$instance;
            if (isset($instance['selected_line_id']) && (string)$instance['selected_line_id'] === (string)$lineId) {
                return $instance;
            }
        }

        return null;
    }

    public function getPortalByInstanceId($idInstance) {
        $idNum = is_numeric($idInstance) ? (int)$idInstance : null;
        $idStr = (string)$idInstance;

        $doc = $this->collection->findOne(['instances.idInstance' => $idNum !== null ? $idNum : $idStr]);
        if (!$doc) {
            $doc = $this->collection->findOne(['instances.idInstance' => $idNum === null ? (int)$idStr : $idStr]);
        }
        return $doc ? (array)$doc : null;
    }

    /* ==================== ПУЛ ПРЕДСОЗДАННЫХ ИНСТАНСОВ ==================== */

    private function poolCollection() {
        return $this->db->selectCollection('instance_pool');
    }

    /**
     * Atomically claim one ready spare from the pool and mark it as claimed.
     * Returns the pool document (with creds) or null if pool is empty.
     */
    public function claimPoolInstance(string $memberId): ?array {
        $res = $this->poolCollection()->findOneAndUpdate(
            ['status' => 'ready'],
            ['$set' => [
                'status'     => 'claimed',
                'claimedBy'  => $memberId,
                'claimedAt'  => new MongoDB\BSON\UTCDateTime(),
            ]],
            ['returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
        );
        return $res ? (array)$res : null;
    }

    public function countReadyPoolInstances(): int {
        return (int)$this->poolCollection()->countDocuments(['status' => 'ready']);
    }

    public function addPoolInstance(array $data): void {
        $data['createdAt'] = new MongoDB\BSON\UTCDateTime();
        $this->poolCollection()->insertOne($data);
    }

    public function removePoolInstanceById(int $idInstance): int {
        $res = $this->poolCollection()->deleteMany(['idInstance' => $idInstance]);
        return (int)$res->getDeletedCount();
    }

    /* ==================== ТАРИФЫ ==================== */

    private function tariffsCollection() {
        return $this->db->selectCollection('tariff_plans');
    }

    public function getAllTariffPlans() {
        $col = $this->tariffsCollection();
        $cursor = $col->find([], ['sort' => ['name' => 1]]);
        $out = [];
        $defaultsById = [];
        foreach ($this->defaultTariffs() as $def) { $defaultsById[$def['planId']] = $def; }
        foreach ($cursor as $d) {
            $doc = (array)$d;
            $pid = (string)($doc['planId'] ?? '');
            if ($pid !== '' && !empty($defaultsById[$pid]['names'])) {
                $existing = (empty($doc['names']) || !is_array($doc['names'])) ? [] : (array)$doc['names'];
                $missing  = array_diff_key($defaultsById[$pid]['names'], $existing);
                if ($missing) {
                    $merged = array_replace($existing, $missing);
                    $doc['names'] = $merged;
                    // Ленивая миграция: дозаписываем недостающие локали, не затирая уже существующие
                    try { $col->updateOne(['planId' => $pid], ['$set' => ['names' => $merged]]); } catch (Throwable $e) {}
                }
            }
            $out[] = $doc;
        }
        // Если пусто — вернём «встроенные» дефолты
        if (!$out) {
            $out = $this->defaultTariffs();
        }
        return $out;
    }

    public function getTariffPlanById($planId) {
        $col = $this->tariffsCollection();
        $doc = $col->findOne(['planId' => (string)$planId]);
        if ($doc) {
            $arr = (array)$doc;
            foreach ($this->defaultTariffs() as $def) {
                if ($def['planId'] === (string)$planId && !empty($def['names'])) {
                    $existing = (empty($arr['names']) || !is_array($arr['names'])) ? [] : (array)$arr['names'];
                    $missing  = array_diff_key($def['names'], $existing);
                    if ($missing) {
                        $merged = array_replace($existing, $missing);
                        $arr['names'] = $merged;
                        try { $col->updateOne(['planId' => (string)$planId], ['$set' => ['names' => $merged]]); } catch (Throwable $e) {}
                    }
                    break;
                }
            }
            return $arr;
        }

        // fallback к дефолтным, если в БД нет
        foreach ($this->defaultTariffs() as $t) {
            if ($t['planId'] === (string)$planId) return $t;
        }
        return null;
    }

    private function defaultTariffs() {
        // Соответствует default_tariff_plan_id -> 'standard_monthly'
        return [
            [
                'planId'   => 'standard_monthly',
                'name'     => 'Стандарт (месячный)',
                'names'    => [
                    'ru'    => 'Стандарт (месячный)',
                    'en'    => 'Standard (monthly)',
                    'de'    => 'Standard (monatlich)',
                    'es'    => 'Estándar (mensual)',
                    'es-ar' => 'Estándar (mensual)',
                    'pt-br' => 'Padrão (mensal)',
                    'fr'    => 'Standard (mensuel)',
                    'pl'    => 'Standard (miesięczny)',
                    'it'    => 'Standard (mensile)',
                    'ar'    => 'قياسي (شهري)',
                    'tr'    => 'Standart (aylık)',
                    'zh-cn' => '标准（月度）',
                    'zh-tw' => '標準（月繳）',
                    'id'    => 'Standar (bulanan)',
                    'ms'    => 'Standard (bulanan)',
                    'th'    => 'มาตรฐาน (รายเดือน)',
                    'vi'    => 'Tiêu chuẩn (hàng tháng)',
                    'ja'    => 'スタンダード（月額）',
                ],
                'price'    => 14.95,
                'currency' => 'USD',
                'features' => [
                    'after_hours_reply' => true,
                    'group_messages'    => true,
                ],
            ],
        ];
    }

    /**
     * Aggregate the feature flags of every non-expired instance on a portal.
     * A feature counts as enabled if any active instance's tariff includes it.
     */
    public function getPortalFeatures($portalConfig): array {
        $aggregated = [];
        foreach ((array)($portalConfig['instances'] ?? []) as $inst) {
            $inst = (array)$inst;
            if (!is_numeric($inst['idInstance'] ?? null)) continue;
            $status = (string)($inst['paymentStatus'] ?? '');
            if ($status === 'expired' || $status === 'deleted') continue;
            $plan = $this->getTariffPlanById((string)($inst['tariffPlanId'] ?? ''));
            if (!$plan) continue;
            foreach ((array)($plan['features'] ?? []) as $k => $v) {
                if (!empty($v)) $aggregated[$k] = true;
            }
        }
        return $aggregated;
    }

    public function getTariffPlanLocalizedName(string $planId, string $locale = 'en'): string
    {
        $doc = $this->getTariffPlanById($planId);
        if (!$doc) return 'Unknown plan';
        $doc = (array)$doc;
        $names = (array)($doc['names'] ?? []);
        $locale = strtolower($locale);
        if (isset($names[$locale]) && $names[$locale]) return (string)$names[$locale];
        // Fallbacks
        foreach (['en','ru'] as $fb) {
            if (!empty($names[$fb])) return (string)$names[$fb];
        }
        return (string)($doc['name'] ?? 'Unknown plan');
    }

    /* ==================== ТРАНЗАКЦИИ (CloudPayments) ==================== */

    private function transactionsCollection() {
        return $this->db->selectCollection('transactions');
    }

    /* ==================== СОБЫТИЯ (Stripe и др.) ==================== */

    private function eventsCollection() {
        return $this->db->selectCollection('events');
    }

    public function isEventProcessed(string $eventId): bool {
        $col = $this->eventsCollection();
        $doc = $col->findOne(['event_id' => (string)$eventId]);
        return (bool)$doc;
    }

    public function logEvent(string $eventId, array $rawData) {
        $col = $this->eventsCollection();
        return $col->insertOne([
            'event_id' => (string)$eventId,
            'created_at' => new MongoDB\BSON\UTCDateTime(),
            'raw' => $rawData,
        ]);
    }

    public function isTransactionProcessed(int $transactionId): bool {
        $col = $this->transactionsCollection();
        $doc = $col->findOne(['transaction_id' => $transactionId]);
        return (bool)$doc;
    }

    public function logTransaction(int $transactionId, array $rawData) {
        $col = $this->transactionsCollection();
        return $col->insertOne([
            'transaction_id' => $transactionId,
            'created_at'     => new MongoDB\BSON\UTCDateTime(),
            'raw'            => $rawData
        ]);
    }

    /* ==================== МИГРАЦИИ/ИНДЕКСЫ/СИДИНГ ==================== */

    public function runMigration() {
        // Индексы
        try {
            $this->collection->createIndex(['member_id' => 1], ['unique' => true]);
            // Индекс по домену портала для быстрого поиска по URL (неуникальный на случай исторических дублей)
            $this->collection->createIndex(['domain' => 1]);
            $this->transactionsCollection()->createIndex(['transaction_id' => 1], ['unique' => true]);
            $this->tariffsCollection()->createIndex(['planId' => 1], ['unique' => true]);
        } catch (Throwable $e) { /* ignore */ }

        // Сидинг тарифов
        if ($this->tariffsCollection()->countDocuments([]) === 0) {
            try {
                $this->tariffsCollection()->insertMany($this->defaultTariffs());
            } catch (Throwable $e) { /* ignore */ }
        }

        // Миграция: добавить connector_id порталам, у которых его нет
        $defaultConnectorId = 'yodo_wa';
        $this->collection->updateMany(
            ['connector_id' => ['$exists' => false]],
            ['$set' => ['connector_id' => $defaultConnectorId]]
        );

        // Миграция: Разрулить дубликаты привязок линий
        $portalsCursor = $this->collection->find([]);
        foreach ($portalsCursor as $portal) {
            $portal = (array)$portal;
            if (empty($portal['instances'])) continue;

            $memberId = $portal['member_id'];
            $instances = iterator_to_array($portal['instances']);

            $lineBindings = []; // [line_id => [instance_id1, instance_id2]]
            foreach ($instances as $instance) {
                $instance = (array)$instance;
                if (!empty($instance['selected_line_id'])) {
                    $lineBindings[(string)$instance['selected_line_id']][] = $instance['idInstance'];
                }
            }

            foreach ($lineBindings as $lineId => $boundInstanceIds) {
                if (count($boundInstanceIds) > 1) {
                    array_shift($boundInstanceIds); // Оставляем первый, остальных удаляем
                    foreach ($boundInstanceIds as $instanceIdToClear) {
                        $this->collection->updateOne(
                            ['member_id' => $memberId, 'instances.idInstance' => $instanceIdToClear],
                            ['$unset' => ['instances.$.selected_line_id' => '']]
                        );
                    }
                }
            }
        }
    }

    /* ==================== ЛОГИРОВАНИЕ ДЕИНСТАЛЛЯЦИИ ==================== */

    public function logUninstall($memberId, $uninstalledData) {
        if (empty($memberId) || empty($uninstalledData)) return;
        try {
            $historyCollection = $this->db->selectCollection('uninstall_history');
            $historyCollection->insertOne([
                'member_id'     => (string)$memberId,
                'uninstalled_at'=> new MongoDB\BSON\UTCDateTime(),
                'original_data' => $uninstalledData
            ]);
        } catch (Exception $e) {
            // можно добавить error_log при желании
        }
    }

    public function countRealInstances(string $memberId): int {
        $instances = $this->getInstancesForPortal($memberId) ?? [];
        if ($instances instanceof \MongoDB\Model\BSONArray) {
            $instances = iterator_to_array($instances);
        }
        $real = array_filter($instances, fn($i) =>
            isset($i['idInstance']) && is_numeric($i['idInstance']) && (($i['paymentStatus'] ?? null) !== 'deleted')
        );
        return count($real);
    }

    public function pruneTempInstancesAtomic(string $memberId, int $olderThanMinutes = 60): void {
        $cut = new MongoDB\BSON\UTCDateTime( (time() - $olderThanMinutes*60) * 1000 );
        $this->collection->updateOne(
            ['member_id' => (string)$memberId],
            ['$pull' => [
                'instances' => [
                    'idInstance'    => ['$regex' => '^temp_'],
                    'paymentStatus' => 'pending_payment',
                    'createdAt'     => ['$lt' => $cut],
                ]
            ]]
        );
    }

    public function tryAcquireLock(string $memberId, string $key): bool {
        $res = $this->collection->updateOne(
            ['member_id' => (string)$memberId, '$or' => [ [$key => ['$exists' => false]], [$key => false] ]],
            ['$set' => [ $key => true ]]
        );
        return $res->getModifiedCount() === 1;
    }

    public function releaseLock(string $memberId, string $key): void {
        $this->collection->updateOne(
            ['member_id' => (string)$memberId],
            ['$set' => [ $key => false, $key . '_ts' => new \MongoDB\BSON\UTCDateTime() ]]
        );
    }

    /**
     * Пытается захватить блокировку с TTL. Если блокировка устарела, перехватываем её.
     */
    public function tryAcquireLockWithTTL(string $memberId, string $key, int $ttlSeconds = 300): bool
    {
        $cutoff = new \MongoDB\BSON\UTCDateTime( (time() - max(1,$ttlSeconds)) * 1000 );
        $res = $this->collection->updateOne(
            [
                'member_id' => (string)$memberId,
                '$or' => [
                    // Нет блокировки — можно захватить
                    [$key => ['$exists' => false]],
                    [$key => false],
                    // Блокировка устарела — можно перехватить
                    [$key . '_ts' => ['$lt' => $cutoff]],
                    // Легаси: есть флаг, но нет таймстампа — считаем устаревшей
                    [$key . '_ts' => ['$exists' => false]],
                ]
            ],
            ['$set' => [ $key => true, $key . '_ts' => new \MongoDB\BSON\UTCDateTime() ]]
        );
        return $res->getModifiedCount() === 1;
    }

    /* ==================== ПРОСРОЧКИ (ПОИСК ДЛЯ CRON) ==================== */
    /**
     * Возвращает массив просроченных инстансов для обработки cron.php.
     * Просроченным считается:
     *  - trial с истёкшим trialEndsAt
     *  - active с истёкшим paidUntil
     * Исключаются placeholder-инстансы (idInstance начинается с "temp_"),
     * а также уже удалённые/помеченные как expired.
     *
     * Формат элемента результата:
     *  [ 'portal' => <array portal>, 'instance' => <array instance> ]
     */
    public function findExpiredInstances(): array {
        $now = new \DateTimeImmutable('now');
        $result = [];

        $cursor = $this->collection->find([
            'instances' => ['$exists' => true, '$ne' => []],
        ]);

        foreach ($cursor as $portalDoc) {
            $portal = (array)$portalDoc;
            $instances = $portal['instances'] ?? [];
            if ($instances instanceof \MongoDB\Model\BSONArray) {
                $instances = iterator_to_array($instances);
            }

            foreach ($instances as $inst) {
                $inst = (array)$inst;

                // Пропускаем placeholder'ы и уже удалённые/просроченные
                $idInstance = $inst['idInstance'] ?? null;
                if (!is_numeric($idInstance)) { continue; }
                $paymentStatus = $inst['paymentStatus'] ?? null;
                if ($paymentStatus === 'deleted' || $paymentStatus === 'expired') { continue; }
                if (($inst['state'] ?? null) === 'deleted') { continue; }

                $isExpired = false;
                if ($paymentStatus === 'trial' && isset($inst['trialEndsAt']) && $inst['trialEndsAt'] instanceof \MongoDB\BSON\UTCDateTime) {
                    $trialEnds = $inst['trialEndsAt']->toDateTime();
                    if ($trialEnds < $now) { $isExpired = true; }
                }
                if ($paymentStatus === 'active' && isset($inst['paidUntil']) && $inst['paidUntil'] instanceof \MongoDB\BSON\UTCDateTime) {
                    $paidUntil = $inst['paidUntil']->toDateTime();
                    if ($paidUntil < $now) { $isExpired = true; }
                }

                if ($isExpired) {
                    $result[] = [ 'portal' => $portal, 'instance' => $inst ];
                }
            }
        }

        return $result;
    }
}
