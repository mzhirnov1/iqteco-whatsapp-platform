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
        $authMethod = in_array($params['authMethod'] ?? 'qr', ['qr', 'pairing_code'], true) ? $params['authMethod'] : 'qr';

        $idInstance = $this->nextIdInstance();
        $apiToken = bin2hex(random_bytes(25)); // 50 hex chars
        $webhookSecret = bin2hex(random_bytes(32)); // 64 hex chars

        $ipv6 = $this->ipPool->allocate($idInstance);
        if ($ipv6 === null) {
            throw new \RuntimeException('IP pool exhausted');
        }

        $containerName = $this->config['podman']['name_prefix'] . $idInstance;

        // Step 1: insert document (state=auth_needed)
        try {
            MongoClient::db($this->config)->selectCollection('instances')->insertOne([
                'idInstance' => $idInstance,
                'apiToken' => $apiToken,
                'webhookUrl' => $webhookUrl,
                'webhookSecret' => $webhookSecret,
                'ownerId' => $ownerId,
                'authMethod' => $authMethod,
                'state' => 'auth_needed',
                'ipv6' => $ipv6,
                'containerName' => $containerName,
                'phoneNumber' => null,
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
                'env' => [
                    'IDINSTANCE' => $idInstance,
                    'API_TOKEN' => $apiToken,
                    'MONGO_URL' => wa_env('CONTAINER_MONGO_URL') ?: $this->config['mongo']['uri'],
                    'ADMIN_URL' => $this->config['admin']['base_url'],
                    'ADMIN_TOKEN' => $this->config['admin']['shared_token'],
                    'WEBHOOK_URL' => $webhookUrl,
                    'IPV6_ADDR' => $ipv6,
                    'LOG_LEVEL' => 'info',
                ],
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
        $this->logger->info('InstanceManager: reboot', ['idInstance' => $idInstance]);
        $this->podman->stop($name);
        try {
            $this->podman->run([
                'name' => $name,
                'ipv6' => $inst['ipv6'],
                'env' => [
                    'IDINSTANCE' => $idInstance,
                    'API_TOKEN' => $inst['apiToken'],
                    'MONGO_URL' => wa_env('CONTAINER_MONGO_URL') ?: $this->config['mongo']['uri'],
                    'ADMIN_URL' => $this->config['admin']['base_url'],
                    'ADMIN_TOKEN' => $this->config['admin']['shared_token'],
                    'WEBHOOK_URL' => $inst['webhookUrl'] ?? '',
                    'IPV6_ADDR' => $inst['ipv6'],
                    'LOG_LEVEL' => 'info',
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('InstanceManager: restart run failed', ['err' => $e->getMessage()]);
            return false;
        }
        return true;
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

    public function delete(string $idInstance, bool $releaseIpBanned = false): bool
    {
        $inst = $this->findOrFail($idInstance);
        $this->logger->info('InstanceManager: delete', ['idInstance' => $idInstance]);

        $this->podman->stop($inst['containerName']);
        $this->podman->rm($inst['containerName']);

        if ($this->nft) {
            $this->nft->removeCounters($idInstance);
        }

        MongoClient::db($this->config)->selectCollection('instances')->updateOne(
            ['idInstance' => $idInstance],
            ['$set' => ['state' => 'deleted', 'deletedAt' => new UTCDateTime(), 'ipv6' => null]]
        );

        $this->ipPool->release($inst['ipv6'], $releaseIpBanned);
        $this->nginx->regenerate();
        return true;
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
