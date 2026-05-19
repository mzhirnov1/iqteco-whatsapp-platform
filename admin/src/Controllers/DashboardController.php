<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Controllers;

use Iqteco\WaAdmin\Services\AuthService;
use Iqteco\WaAdmin\Services\IpPoolManager;
use Iqteco\WaAdmin\Services\Logger;
use Iqteco\WaAdmin\Services\MongoClient;
use Iqteco\WaAdmin\Services\View;

final class DashboardController
{
    public function __construct(private readonly array $config) {}

    public function index(array $params): void
    {
        (new AuthService($this->config))->requireAuth();

        $db = MongoClient::db($this->config);

        $instances = $db->selectCollection('instances')
            ->find(
                ['state' => ['$ne' => 'deleted']],
                ['sort' => ['createdAt' => -1], 'limit' => 200]
            )
            ->toArray();

        $stats = (new IpPoolManager($this->config, new Logger('dashboard')))->stats();

        // Aggregate webhook outbox status per instance for badges
        $webhookStatsRaw = $db->selectCollection('webhook_outbox')->aggregate([
            ['$match' => ['status' => ['$in' => ['pending', 'failed']]]],
            ['$group' => ['_id' => ['idInstance' => '$idInstance', 'status' => '$status'], 'n' => ['$sum' => 1]]],
        ])->toArray();
        $webhookStats = [];
        foreach ($webhookStatsRaw as $row) {
            $iid = $row['_id']['idInstance'];
            $st = $row['_id']['status'];
            $webhookStats[$iid][$st] = (int)$row['n'];
        }

        View::renderLayout('dashboard', [
            'instances' => $instances,
            'stats' => $stats,
            'webhookStats' => $webhookStats,
        ]);
    }
}
