<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Services;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Operation\FindOneAndUpdate;

final class InstanceManager
{
    public function __construct(
        private readonly array $config,
        private readonly Logger $logger,
        private readonly IpPoolManager $ipPool,
        private readonly PodmanRunner $podman,
        private readonly NginxMapManager $nginx,
        private readonly ?NftablesManager $nft = null,
    ) {}

    /**
     * Partner-facing factory: tries InstancePool first (instant ~2s QR),
     * falls back to full create() if pool is empty (15-30s).
     * Returns same shape as create(): {idInstance, apiToken, ipv6, containerId}.
     */
    public function createForOwner(array $params): array
    {
        // Avoid claiming a pool slot for pool itself
        $ownerId = (string)($params['ownerId'] ?? '');
        if ($ownerId !== InstancePool::OWNER_TAG) {
            $pool = new InstancePool($this->config, $this->logger, $this);
            $claimed = $pool->claim($ownerId, (string)($params['webhookUrl'] ?? ''));
            if ($claimed) {
                $inst = MongoClient::db($this->config)->selectCollection('instances')
                    ->findOne(['idInstance' => $claimed['idInstance']]);
                return [
                    'idInstance' => $inst['idInstance'],
                    'apiToken' => $inst['apiToken'],
                    'ipv6' => $inst['ipv6'],
                    'containerId' => $inst['containerName'] ?? null,
                    'fromPool' => true,
                ];
            }
        }
        // Fallback — full provisioning
        return $this->create($params) + ['fromPool' => false];
    }

    /**
     * Полный жизненный цикл создания инстанса.
     * @return array{idInstance:string, apiToken:string, ipv6:string, containerId:string}
     */
    public function create(array $params): array
    {
        $ownerId = (string)($params['ownerId'] ?? '');
        $webhookUrl = (string)($params['webhookUrl'] ?? $this->config['webhook']['default_url']);
        $type = in_array($params['type'] ?? 'whatsapp', ['whatsapp', 'telegram'], true) ? $params['type'] : 'whatsapp';
        $authMethod = $type === 'telegram'
            ? (in_array($params['authMethod'] ?? 'tg_qr', ['tg_qr', 'tg_phone_code'], true) ? $params['authMethod'] : 'tg_qr')
            : (in_array($params['authMethod'] ?? 'qr', ['qr', 'pairing_code'], true) ? $params['authMethod'] : 'qr');
        $tgPhoneNumber = $type === 'telegram' ? (string)($params['tgPhoneNumber'] ?? '') : null;

        $idInstance = $this->nextIdInstance();
        $apiToken = bin2hex(random_bytes(25)); // 50 hex chars
        $webhookSecret = bin2hex(random_bytes(32)); // 64 hex chars

        $ipv6 = $this->ipPool->allocate($idInstance);
        if ($ipv6 === null) {
            throw new \RuntimeException('IP pool exhausted');
        }

        $namePrefix = $type === 'telegram'
            ? ($this->config['podman']['name_prefix_telegram'] ?? 'tg-')
            : ($this->config['podman']['name_prefix'] ?? 'wa-');
        $containerName = $namePrefix . $idInstance;

        // Step 1: insert document (state=auth_needed)
        try {
            MongoClient::db($this->config)->selectCollection('instances')->insertOne([
                'idInstance' => $idInstance,
                'type' => $type,
                'apiToken' => $apiToken,
                'webhookUrl' => $webhookUrl,
                'webhookSecret' => $webhookSecret,
                'ownerId' => $ownerId,
                'authMethod' => $authMethod,
                'state' => 'auth_needed',
                'ipv6' => $ipv6,
                'containerName' => $containerName,
                'phoneNumber' => null,
                'tgPhoneNumber' => $tgPhoneNumber,
                'settings' => [
                    'outgoingWebhook' => 'yes',
                    'outgoingMessageWebhook' => 'yes',
                    'outgoingAPIMessageWebhook' => 'yes',
                    'incomingWebhook' => 'yes',
                    'stateWebhook' => 'yes',
                    'markIncomingMessagesReaded' => 'no',
                    'delaySendMessagesMilliseconds' => 1000,
                ],
                'createdAt' => new UTCDateTime(),
                'lastSeen' => null,
                'lastQr' => null,
                'lastQrAt' => null,
                'qrKind' => null,
            ]);
        } catch (\Throwable $e) {
            $this->ipPool->release($ipv6);
            throw $e;
        }

        // Step 2: regenerate nginx map BEFORE container starts (so it can answer immediately)
        try {
            $this->nginx->regenerate();
        } catch (\Throwable $e) {
            $this->logger->warn('InstanceManager: nginx regen failed', ['err' => $e->getMessage()]);
            // not fatal — admin can retry via reload
        }

        // Step 3: podman run
        try {
            $containerId = $this->podman->run([
                'name' => $containerName,
                'ipv6' => $ipv6,
                'image' => $this->imageForType($type),
                'env' => $this->envForType($type, $idInstance, $apiToken, $webhookUrl, $ipv6, $tgPhoneNumber, $authMethod),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('InstanceManager: podman run failed, rolling back', ['err' => $e->getMessage()]);
            MongoClient::db($this->config)->selectCollection('instances')->deleteOne(['idInstance' => $idInstance]);
            $this->ipPool->release($ipv6);
            $this->nginx->regenerate();
            throw $e;
        }

        // Step 4: nftables counters
        if ($this->nft) {
            $this->nft->addCounters($idInstance, $ipv6);
        }

        $this->logger->info('InstanceManager: created', [
            'idInstance' => $idInstance, 'ipv6' => $ipv6, 'containerName' => $containerName,
        ]);

        return [
            'idInstance' => $idInstance,
            'apiToken' => $apiToken,
            'ipv6' => $ipv6,
            'containerId' => $containerId,
        ];
    }

    public function reboot(string $idInstance): bool
    {
        $inst = $this->findOrFail($idInstance);
        $name = $inst['containerName'];
        $type = (string)($inst['type'] ?? 'whatsapp');
        $this->logger->info('InstanceManager: reboot', ['idInstance' => $idInstance, 'type' => $type]);
        $this->podman->stop($name);
        $this->podman->rm($name);
        try {
            $this->podman->run([
                'name' => $name,
                'ipv6' => $inst['ipv6'],
                'image' => $this->imageForType($type),
                'env' => $this->envForType(
                    $type, $idInstance, (string)$inst['apiToken'],
                    (string)($inst['webhookUrl'] ?? ''), (string)$inst['ipv6'],
                    (string)($inst['tgPhoneNumber'] ?? ''), (string)($inst['authMethod'] ?? '')
                ),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('InstanceManager: restart run failed', ['err' => $e->getMessage()]);
            return false;
        }
        return true;
    }

    private function imageForType(string $type): string
    {
        return $type === 'telegram'
            ? (string)($this->config['podman']['image_telegram'] ?? 'localhost/tg-instance:latest')
            : (string)$this->config['podman']['image'];
    }

    /**
     * @return array<string,string>
     */
    private function envForType(
        string $type,
        string $idInstance,
        string $apiToken,
        string $webhookUrl,
        string $ipv6,
        ?string $tgPhoneNumber,
        string $authMethod
    ): array {
        $env = [
            'IDINSTANCE' => $idInstance,
            'API_TOKEN' => $apiToken,
            'MONGO_URL' => wa_env('CONTAINER_MONGO_URL') ?: $this->config['mongo']['uri'],
            'ADMIN_URL' => $this->config['admin']['base_url'],
            'ADMIN_TOKEN' => $this->config['admin']['shared_token'],
            'WEBHOOK_URL' => $webhookUrl,
            'IPV6_ADDR' => $ipv6,
            'MEDIA_BASE_URL' => $this->config['api']['base_url'] ?? 'https://api.wa.iqteco.com',
            'S3_ENDPOINT' => $this->config['s3']['endpoint'] ?? '',
            'S3_REGION' => $this->config['s3']['region'] ?? '',
            'S3_BUCKET' => $this->config['s3']['bucket'] ?? '',
            'S3_ACCESS_KEY' => $this->config['s3']['access_key'] ?? '',
            'S3_SECRET_KEY' => $this->config['s3']['secret_key'] ?? '',
            'S3_KEY_PREFIX' => $this->config['s3']['key_prefix'] ?? 'media/',
            'LOG_LEVEL' => 'info',
        ];
        if ($type === 'telegram') {
            $env['INSTANCE_TYPE'] = 'telegram';
            $env['TG_API_ID'] = (string)($this->config['telegram']['api_id'] ?? '');
            $env['TG_API_HASH'] = (string)($this->config['telegram']['api_hash'] ?? '');
            $env['TG_PHONE'] = (string)$tgPhoneNumber;
            $env['TG_AUTH_METHOD'] = $authMethod;
        }
        return $env;
    }

    public function logout(string $idInstance): bool
    {
        $inst = $this->findOrFail($idInstance);
        // Делегируем контейнеру: вызовем его API endpoint /logout
        $apiUrl = $this->config['admin']['base_url']; // unused — logout via container, not via admin
        $url = sprintf('http://[%s]:8080/waInstance%s/logout/%s', $inst['ipv6'], $idInstance, $inst['apiToken']);
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 10, 'ignore_errors' => true]]);
        $res = @file_get_contents($url, false, $ctx);
        $this->logger->info('InstanceManager: logout', ['idInstance' => $idInstance, 'res' => substr((string)$res, 0, 200)]);
        return $res !== false;
    }

    /**
     * Soft-delete: mark instance for cleanup in 24h.
     * Container keeps running so a returning customer (paid within grace
     * window) doesn't lose the WhatsApp session. Actual stop+rm happens
     * later in executePendingDelete() called by wa-pending-delete.timer.
     * Used by Partner API deleteInstanceAccount (legacy cron path).
     */
    public function markForDelete(string $idInstance, int $graceSeconds = 86400): bool
    {
        $inst = $this->find($idInstance);
        if (!$inst) return false;
        if (($inst['state'] ?? '') === 'deleted') return true;  // already gone

        MongoClient::db($this->config)->selectCollection('instances')->updateOne(
            ['idInstance' => $idInstance],
            ['$set' => [
                'state' => 'pending_delete',
                'markedForDeleteAt' => new UTCDateTime((time() + $graceSeconds) * 1000),
                'markedForDeleteReason' => 'partner_api',
            ]],
        );
        $this->logger->info('InstanceManager: marked for delete', [
            'idInstance' => $idInstance, 'graceSeconds' => $graceSeconds,
        ]);
        return true;
    }

    /**
     * Reverse markForDelete — if the customer pays in time.
     */
    public function unmarkForDelete(string $idInstance): bool
    {
        $r = MongoClient::db($this->config)->selectCollection('instances')->updateOne(
            ['idInstance' => $idInstance, 'state' => 'pending_delete'],
            ['$set' => ['state' => 'authorized'],  // best effort, real state pulled via heartbeat
             '$unset' => ['markedForDeleteAt' => '', 'markedForDeleteReason' => '']],
        );
        return $r->getModifiedCount() > 0;
    }

    /**
     * Hard cleanup: kill container, drop session, release IPv6.
     * Called by:
     *   - wa-pending-delete timer for instances whose grace expired
     *   - delete() (UI immediate path) — sync flow
     */
    public function executePendingDelete(string $idInstance, bool $releaseIpBanned = false): bool
    {
        $inst = $this->find($idInstance);
        if (!$inst) return false;
        if (($inst['state'] ?? '') === 'deleted') return true;

        $name = $inst['containerName'] ?? '';
        $this->logger->info('InstanceManager: executing pending delete', ['idInstance' => $idInstance]);

        if ($name) {
            $this->podman->stop($name);
            $this->podman->rm($name);
        }
        if ($this->nft) $this->nft->removeCounters($idInstance);

        // Drop the session blob from GridFS so a future reuse of
        // the same idInstance won't accidentally restore a stranger's chat.
        $type = (string)($inst['type'] ?? 'whatsapp');
        try {
            $db = MongoClient::db($this->config);
            if ($type === 'telegram') {
                $bucket = $db->selectGridFSBucket(['bucketName' => 'tg_sessions']);
                foreach ($bucket->find(['filename' => 'tg-session-' . $idInstance]) as $file) {
                    $bucket->delete($file['_id']);
                }
            } else {
                $store = new MongoStore(['db' => $db, 'idInstance' => $idInstance]);
                $store->delete(['session' => 'RemoteAuth-' . $idInstance]);
            }
        } catch (\Throwable $e) {
            $this->logger->warn('session drop failed', ['idInstance' => $idInstance, 'type' => $type, 'err' => $e->getMessage()]);
        }

        MongoClient::db($this->config)->selectCollection('instances')->updateOne(
            ['idInstance' => $idInstance],
            ['$set' => ['state' => 'deleted', 'deletedAt' => new UTCDateTime(), 'ipv6' => null]],
        );

        if (!empty($inst['ipv6'])) {
            $this->ipPool->release($inst['ipv6'], $releaseIpBanned);
        }
        $this->nginx->regenerate();
        return true;
    }

    /**
     * Walk all pending_delete docs whose grace expired and tear them down.
     * Returns number of instances cleaned up.
     */
    public function reapPendingDeletes(): int
    {
        $cursor = MongoClient::db($this->config)->selectCollection('instances')->find(
            [
                'state' => 'pending_delete',
                'markedForDeleteAt' => ['$lte' => new UTCDateTime()],
            ],
            ['projection' => ['idInstance' => 1], 'limit' => 100],
        );
        $count = 0;
        foreach ($cursor as $d) {
            if ($this->executePendingDelete((string)$d['idInstance'])) $count++;
        }
        return $count;
    }

    /**
     * Immediate hard delete (used by admin UI). Wraps executePendingDelete.
     */
    public function delete(string $idInstance, bool $releaseIpBanned = false): bool
    {
        return $this->executePendingDelete($idInstance, $releaseIpBanned);
    }

    public function findOrFail(string $idInstance): array
    {
        $inst = MongoClient::db($this->config)->selectCollection('instances')->findOne(['idInstance' => $idInstance]);
        if (!$inst) throw new \RuntimeException("Instance not found: {$idInstance}");
        return (array)$inst;
    }

    public function find(string $idInstance): ?array
    {
        $inst = MongoClient::db($this->config)->selectCollection('instances')->findOne(['idInstance' => $idInstance]);
        return $inst ? (array)$inst : null;
    }

    public function list(array $filter = []): array
    {
        $cursor = MongoClient::db($this->config)->selectCollection('instances')
            ->find($filter, ['sort' => ['createdAt' => -1], 'limit' => 500]);
        return $cursor->toArray();
    }

    private function nextIdInstance(): string
    {
        $counters = MongoClient::db($this->config)->selectCollection('_counters');
        $result = $counters->findOneAndUpdate(
            ['name' => 'idInstance'],
            ['$inc' => ['seq' => 1]],
            ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER, 'upsert' => true]
        );
        $seq = $result['seq'] ?? 1101000001;
        return (string)$seq;
    }
}
