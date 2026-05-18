<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Services;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Operation\FindOneAndUpdate;

final class IpPoolManager
{
    public function __construct(private readonly array $config, private readonly Logger $logger) {}

    /**
     * Атомарно резервирует свободный IPv6 за инстансом.
     * @return string|null IPv6 адрес или null если пул исчерпан
     */
    public function allocate(string $idInstance): ?string
    {
        $coll = MongoClient::db($this->config)->selectCollection('ip_pool');
        $result = $coll->findOneAndUpdate(
            ['status' => 'free'],
            ['$set' => [
                'status' => 'assigned',
                'idInstance' => $idInstance,
                'allocatedAt' => new UTCDateTime(),
            ]],
            ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
        );

        if (!$result) {
            $this->logger->warn('IpPoolManager: pool exhausted', ['idInstance' => $idInstance]);
            return null;
        }

        $ipv6 = $result['ipv6'];
        $this->logger->info('IpPoolManager: allocated', ['idInstance' => $idInstance, 'ipv6' => $ipv6]);
        return $ipv6;
    }

    /**
     * Освобождает IPv6: переводит в quarantine на 24ч, затем cron вернёт в free.
     * Если $banned=true, quarantine на 30 дней.
     */
    public function release(string $ipv6, bool $banned = false): void
    {
        $coll = MongoClient::db($this->config)->selectCollection('ip_pool');
        $coll->updateOne(
            ['ipv6' => $ipv6],
            ['$set' => [
                'status' => $banned ? 'quarantine_banned' : 'quarantine',
                'idInstance' => null,
                'releasedAt' => new UTCDateTime(),
                'reuseAfter' => new UTCDateTime((time() + ($banned ? 30 * 86400 : 86400)) * 1000),
            ]]
        );
        $this->logger->info('IpPoolManager: released', ['ipv6' => $ipv6, 'banned' => $banned]);
    }

    /**
     * Возвращает в free все IPv6 которые отбыли quarantine.
     * Запускается из cron или wa-traffic-poller.
     */
    public function reclaim(): int
    {
        $coll = MongoClient::db($this->config)->selectCollection('ip_pool');
        $result = $coll->updateMany(
            [
                'status' => ['$in' => ['quarantine', 'quarantine_banned']],
                'reuseAfter' => ['$lte' => new UTCDateTime()],
            ],
            ['$set' => ['status' => 'free', 'reuseAfter' => null]]
        );
        $count = $result->getModifiedCount();
        if ($count > 0) {
            $this->logger->info('IpPoolManager: reclaimed', ['count' => $count]);
        }
        return $count;
    }

    public function stats(): array
    {
        $coll = MongoClient::db($this->config)->selectCollection('ip_pool');
        return [
            'free' => $coll->countDocuments(['status' => 'free']),
            'assigned' => $coll->countDocuments(['status' => 'assigned']),
            'quarantine' => $coll->countDocuments(['status' => ['$in' => ['quarantine', 'quarantine_banned']]]),
            'total' => $coll->countDocuments(),
        ];
    }
}
