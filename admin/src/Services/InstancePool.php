<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Services;

use MongoDB\BSON\UTCDateTime;

/**
 * Pre-warm WhatsApp instances so that when a client creates one via the
 * Partner API they get a QR-ready container in ~2s instead of waiting
 * 15-30s for podman+Chromium to spin up.
 *
 * State of a pooled instance:
 *   ownerId  = '__pool__'
 *   state    = 'auth_needed'  (container running, waiting for QR scan)
 *
 * Used by:
 *   - cron tick (systemd timer wa-pool-keeper.timer) calls keepWarm()
 *   - InstanceManager::assignFromPool() pulls a warm instance and
 *     rebinds it to a real owner
 */
final class InstancePool
{
    public const OWNER_TAG = '__pool__';

    public function __construct(
        private readonly array $config,
        private readonly Logger $logger,
        private readonly InstanceManager $manager,
    ) {}

    private function targetSize(): int { return (int)(\wa_env('POOL_WARM_TARGET') ?? 2); }

    /**
     * Run by cron: top up the pool so we have at least targetSize() warm.
     * Returns number created this tick.
     */
    public function keepWarm(): int
    {
        // Count anything in the pool that is not yet authorized and not deleted.
        // After container spins up the state goes auth_needed → starting → notAuthorized
        // (the latter is the Green-API mapping for "UNPAIRED" — container is alive,
        // showing QR, just waiting for the user to scan).
        $current = MongoClient::db($this->config)->selectCollection('instances')->countDocuments([
            'ownerId' => self::OWNER_TAG,
            'state' => ['$in' => ['auth_needed', 'starting', 'notAuthorized']],
        ]);
        $target = $this->targetSize();
        $deficit = max(0, $target - $current);
        if ($deficit === 0) return 0;

        $created = 0;
        for ($i = 0; $i < $deficit; $i++) {
            try {
                $r = $this->manager->create([
                    'type' => 'whatsapp',
                    'authMethod' => 'qr',
                    'webhookUrl' => '',
                    'ownerId' => self::OWNER_TAG,
                ]);
                $this->logger->info('InstancePool: warmed up', ['idInstance' => $r['idInstance']]);
                $created++;
            } catch (\Throwable $e) {
                $this->logger->error('InstancePool.keepWarm failed', ['err' => $e->getMessage()]);
                break;
            }
        }
        return $created;
    }

    /**
     * Atomically claim one warm instance. Returns null if pool is empty.
     * Updates ownerId/webhookUrl/createdAt to the real owner.
     */
    public function claim(string $ownerId, string $webhookUrl): ?array
    {
        $coll = MongoClient::db($this->config)->selectCollection('instances');
        $now = new UTCDateTime();

        $result = $coll->findOneAndUpdate(
            [
                'ownerId' => self::OWNER_TAG,
                'state' => ['$in' => ['auth_needed', 'starting', 'notAuthorized']],
                'lastQr' => ['$ne' => null],  // only claim instances that already have a QR ready
            ],
            [
                '$set' => [
                    'ownerId' => $ownerId,
                    'webhookUrl' => $webhookUrl,
                    'claimedAt' => $now,
                ],
            ],
            [
                'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                'sort' => ['createdAt' => 1],  // oldest warm first
            ],
        );
        if (!$result) return null;

        // Push the new webhookUrl to the container (it pulled config at startup)
        $this->notifyContainerConfigChange($result);

        $this->logger->info('InstancePool: claimed', [
            'idInstance' => $result['idInstance'], 'ownerId' => $ownerId,
        ]);
        return [
            'idInstance' => $result['idInstance'],
            'apiToken' => $result['apiToken'],
            'ipv6' => $result['ipv6'],
        ];
    }

    private function notifyContainerConfigChange(array $instance): void
    {
        // Container fetches webhookUrl via GET /admin/api/instances/{id}/config
        // every event. Since Mongo is already updated, the next webhook will
        // use the new URL. No active push needed for MVP.
    }

    public function stats(): array
    {
        $coll = MongoClient::db($this->config)->selectCollection('instances');
        return [
            'warm' => $coll->countDocuments([
                'ownerId' => self::OWNER_TAG,
                'state' => ['$in' => ['auth_needed', 'starting', 'notAuthorized']],
                'lastQr' => ['$ne' => null],
            ]),
            'starting' => $coll->countDocuments([
                'ownerId' => self::OWNER_TAG,
                'state' => ['$in' => ['auth_needed', 'starting']],
                'lastQr' => null,
            ]),
            'target' => $this->targetSize(),
        ];
    }
}
