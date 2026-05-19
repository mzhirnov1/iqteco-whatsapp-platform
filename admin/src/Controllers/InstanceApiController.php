<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Controllers;

use Iqteco\WaAdmin\Services\AuthService;
use Iqteco\WaAdmin\Services\Logger;
use Iqteco\WaAdmin\Services\PodmanRunner;
use Iqteco\WaAdmin\Services\TrafficCollector;
use Iqteco\WaAdmin\Services\NftablesManager;
use Iqteco\WaAdmin\Services\MongoClient;

/**
 * Browser-facing JSON API для страницы инстанса (session-auth, не shared-token).
 * Используется JavaScript'ом на /instances/{id} для traffic/logs polling.
 */
final class InstanceApiController
{
    public function __construct(private readonly array $config) {}

    private function requireSession(): void
    {
        if (empty($_SESSION['user_id'])) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'unauthorized']);
            exit;
        }
    }

    public function traffic(array $params): void
    {
        $this->requireSession();
        $idInstance = (string)$params['id'];
        $log = new Logger('traffic-api');
        $collector = new TrafficCollector($this->config, $log, new NftablesManager($this->config, $log));
        header('Content-Type: application/json');
        echo json_encode($collector->forInstance($idInstance));
    }

    public function logs(array $params): void
    {
        $this->requireSession();
        $idInstance = (string)$params['id'];
        $tail = max(50, min(1000, (int)($_GET['tail'] ?? 200)));

        $instance = MongoClient::db($this->config)->selectCollection('instances')->findOne(
            ['idInstance' => $idInstance],
            ['projection' => ['containerName' => 1]]
        );
        if (!$instance) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'not_found']);
            return;
        }

        $log = new Logger('logs-api');
        $podman = new PodmanRunner($this->config, $log);
        $output = $podman->logs($instance['containerName'], $tail);

        header('Content-Type: application/json');
        echo json_encode(['logs' => $output]);
    }
}
