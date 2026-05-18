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

        $instances = MongoClient::db($this->config)
            ->selectCollection('instances')
            ->find(
                ['state' => ['$ne' => 'deleted']],
                ['sort' => ['createdAt' => -1], 'limit' => 200]
            )
            ->toArray();

        $stats = (new IpPoolManager($this->config, new Logger('dashboard')))->stats();

        View::renderLayout('dashboard', [
            'instances' => $instances,
            'stats' => $stats,
        ]);
    }
}
