<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Controllers;

use Iqteco\WaAdmin\Services\AuthService;
use Iqteco\WaAdmin\Services\MongoClient;

/**
 * Прокси для test-chat UI: транслирует session-auth запросы из браузера
 * в Green-API HTTP контейнера (apiToken-auth). Не использует Cloudflare/
 * api.wa.iqteco.com — ходим прямо к [ipv6]:8080 внутри сервера.
 */
final class InstanceProxyController
{
    public function __construct(private readonly array $config) {}

    public function proxy(array $params): void
    {
        (new AuthService($this->config))->requireAuth();

        $instance = MongoClient::db($this->config)->selectCollection('instances')->findOne(
            ['idInstance' => (string)$params['id']]
        );
        if (!$instance) {
            $this->respond(404, ['error' => 'instance_not_found']);
            return;
        }
        if (empty($instance['ipv6']) || empty($instance['apiToken'])) {
            $this->respond(400, ['error' => 'instance_not_ready']);
            return;
        }

        $method = $params['method'] ?? '';
        if (!preg_match('/^[a-zA-Z]+$/', $method)) {
            $this->respond(400, ['error' => 'invalid_method']);
            return;
        }

        $body = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = file_get_contents('php://input') ?: '';
        }

        // Talk to container via its public IPv6 (port 8080)
        $url = sprintf(
            'http://[%s]:8080/waInstance%s/%s/%s',
            $instance['ipv6'],
            $instance['idInstance'],
            $method,
            $instance['apiToken']
        );

        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        if ($isPost) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?: '{}');
        }
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            $this->respond(502, ['error' => 'container_unreachable', 'message' => $err]);
            return;
        }

        http_response_code($code ?: 200);
        header('Content-Type: application/json');
        echo $resp;
    }

    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($body);
    }
}
