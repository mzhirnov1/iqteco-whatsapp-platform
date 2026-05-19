<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Services;

use MongoDB\BSON\UTCDateTime;

/**
 * Опрашивает nftables counters и обновляет коллекцию traffic.
 * Delta-based: считаем разницу между текущим значением и last_recorded.
 * Если delta < 0 (counter был удалён/обнулён) — используем абсолютное значение.
 */
final class TrafficCollector
{
    public function __construct(
        private readonly array $config,
        private readonly Logger $logger,
        private readonly NftablesManager $nft,
    ) {}

    public function poll(): array
    {
        $counters = $this->nft->listCounters();
        if (empty($counters)) {
            return ['polled' => 0, 'skipped' => 0];
        }

        $db = MongoClient::db($this->config);
        $state = $db->selectCollection('counters_state');
        $traffic = $db->selectCollection('traffic');

        $now = time();
        $hourKey = date('Y-m-d-H', $now);
        $dayKey = date('Y-m-d', $now);
        $monthKey = date('Y-m', $now);

        $polled = 0;
        $perInstance = []; // idInstance → ['in' => bytes, 'out' => bytes] this poll

        foreach ($counters as $name => $values) {
            if (!preg_match('/^wa-(\d+)-(in|out)$/', $name, $m)) continue;
            $idInstance = $m[1];
            $direction = $m[2];
            $current = (int)$values['bytes'];

            $prev = $state->findOne(['name' => $name]);
            $lastValue = (int)($prev['value'] ?? 0);

            $delta = $current - $lastValue;
            if ($delta < 0) $delta = $current; // counter был обнулён

            $state->updateOne(
                ['name' => $name],
                ['$set' => ['value' => $current, 'updatedAt' => new UTCDateTime()]],
                ['upsert' => true],
            );

            if ($delta <= 0) continue;

            $field = $direction === 'in' ? 'bytesIn' : 'bytesOut';
            foreach (['hour' => $hourKey, 'day' => $dayKey, 'month' => $monthKey] as $bucket => $periodKey) {
                $traffic->updateOne(
                    ['idInstance' => $idInstance, 'bucket' => $bucket, 'periodKey' => $periodKey],
                    [
                        '$inc' => [$field => $delta],
                        '$set' => ['updatedAt' => new UTCDateTime()],
                    ],
                    ['upsert' => true],
                );
            }

            $perInstance[$idInstance][$direction] = ($perInstance[$idInstance][$direction] ?? 0) + $delta;
            $polled++;
        }

        // Алерты — проверяем дневной bytesOut
        $alerts = $this->checkAlerts($dayKey, $hourKey, $monthKey);

        return ['polled' => $polled, 'alerts' => $alerts];
    }

    private function checkAlerts(string $dayKey, string $hourKey, string $monthKey): int
    {
        $alertThreshold = (float)$this->config['traffic']['alert_threshold']; // 0.8
        $hourlyMB = (int)$this->config['traffic']['hourly_mb'];
        $dailyMB = (int)$this->config['traffic']['daily_mb'];
        $monthlyGB = (int)$this->config['traffic']['monthly_gb'];

        $hourLimit = $hourlyMB * 1024 * 1024;
        $dayLimit = $dailyMB * 1024 * 1024;
        $monthLimit = $monthlyGB * 1024 * 1024 * 1024;

        $db = MongoClient::db($this->config);
        $traffic = $db->selectCollection('traffic');
        $instances = $db->selectCollection('instances');

        $alerted = 0;

        $cursor = $traffic->find([
            'bucket' => ['$in' => ['hour', 'day', 'month']],
            'periodKey' => ['$in' => [$hourKey, $dayKey, $monthKey]],
        ]);

        $byInstance = []; // idInstance → ['hour' => bytes, 'day' => bytes, 'month' => bytes]
        foreach ($cursor as $row) {
            $byInstance[$row['idInstance']][$row['bucket']] = (int)($row['bytesOut'] ?? 0);
        }

        foreach ($byInstance as $idInstance => $rows) {
            $hour = $rows['hour'] ?? 0;
            $day = $rows['day'] ?? 0;
            $month = $rows['month'] ?? 0;

            $warning = ($hour > $alertThreshold * $hourLimit)
                || ($day > $alertThreshold * $dayLimit)
                || ($month > $alertThreshold * $monthLimit);

            $exceeded = ($hour > $hourLimit) || ($day > $dayLimit) || ($month > $monthLimit);

            $status = $exceeded ? 'exceeded' : ($warning ? 'warning' : 'ok');

            $instances->updateOne(
                ['idInstance' => $idInstance],
                ['$set' => [
                    'trafficStatus' => $status,
                    'trafficBytesHour' => $hour,
                    'trafficBytesDay' => $day,
                    'trafficBytesMonth' => $month,
                    'trafficUpdatedAt' => new UTCDateTime(),
                ]],
            );

            if ($status !== 'ok') $alerted++;
        }

        return $alerted;
    }

    /**
     * Для UI: возвращает текущий трафик инстанса.
     */
    public function forInstance(string $idInstance): array
    {
        $now = time();
        $keys = [
            'hour' => date('Y-m-d-H', $now),
            'day' => date('Y-m-d', $now),
            'month' => date('Y-m', $now),
        ];
        $db = MongoClient::db($this->config);
        $rows = $db->selectCollection('traffic')->find([
            'idInstance' => $idInstance,
            '$or' => [
                ['bucket' => 'hour', 'periodKey' => $keys['hour']],
                ['bucket' => 'day', 'periodKey' => $keys['day']],
                ['bucket' => 'month', 'periodKey' => $keys['month']],
            ],
        ])->toArray();

        $out = [
            'hour' => ['bytesIn' => 0, 'bytesOut' => 0],
            'day' => ['bytesIn' => 0, 'bytesOut' => 0],
            'month' => ['bytesIn' => 0, 'bytesOut' => 0],
        ];
        foreach ($rows as $r) {
            $out[$r['bucket']]['bytesIn'] = (int)($r['bytesIn'] ?? 0);
            $out[$r['bucket']]['bytesOut'] = (int)($r['bytesOut'] ?? 0);
        }

        $out['limits'] = [
            'hour' => (int)$this->config['traffic']['hourly_mb'] * 1024 * 1024,
            'day' => (int)$this->config['traffic']['daily_mb'] * 1024 * 1024,
            'month' => (int)$this->config['traffic']['monthly_gb'] * 1024 * 1024 * 1024,
        ];
        return $out;
    }
}
