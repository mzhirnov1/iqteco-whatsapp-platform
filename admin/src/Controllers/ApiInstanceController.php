<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Controllers;

use Iqteco\WaAdmin\Services\MongoClient;
use MongoDB\BSON\UTCDateTime;

final class ApiInstanceController
{
    public function __construct(private readonly array $config) {}

    private function authenticate(): bool
    {
        $expected = (string)$this->config['admin']['shared_token'];
        if ($expected === '') {
            http_response_code(500);
            echo json_encode(['error' => 'admin_token_not_configured']);
            return false;
        }
        $provided = (string)($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');
        if (!hash_equals($expected, $provided)) {
            http_response_code(401);
            echo json_encode(['error' => 'unauthorized']);
            return false;
        }
        return true;
    }

    private function readJson(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public function register(array $params): void
    {
        if (!$this->authenticate()) return;
        header('Content-Type: application/json');
        $body = $this->readJson();
        MongoClient::db($this->config)->selectCollection('instances')->updateOne(
            ['idInstance' => $params['id']],
            ['$set' => [
                'lastSeen' => new UTCDateTime(),
                'pid' => $body['pid'] ?? null,
                'version' => $body['version'] ?? null,
                'ipv6' => $body['ipv6'] ?? null,
                'state' => $body['state'] ?? 'starting',
            ]],
        );
        echo json_encode(['ok' => true]);
    }

    public function heartbeat(array $params): void
    {
        if (!$this->authenticate()) return;
        header('Content-Type: application/json');
        $body = $this->readJson();
        MongoClient::db($this->config)->selectCollection('instances')->updateOne(
            ['idInstance' => $params['id']],
            ['$set' => [
                'lastSeen' => new UTCDateTime(),
                'state' => $body['state'] ?? null,
                'lastEventAt' => isset($body['lastEventAt']) ? new UTCDateTime((int)$body['lastEventAt'] * 1000) : null,
            ]],
        );
        echo json_encode(['ok' => true]);
    }

    public function qr(array $params): void
    {
        if (!$this->authenticate()) return;
        header('Content-Type: application/json');
        $body = $this->readJson();
        MongoClient::db($this->config)->selectCollection('instances')->updateOne(
            ['idInstance' => $params['id']],
            ['$set' => [
                'lastQr' => $body['qr'] ?? null,
                'lastQrAt' => new UTCDateTime(),
            ]],
        );
        echo json_encode(['ok' => true]);
    }

    public function qrPoll(array $params): void
    {
        if (empty($_SESSION['user_id'])) {
            http_response_code(401);
            return;
        }
        header('Content-Type: application/json');
        $instance = MongoClient::db($this->config)->selectCollection('instances')->findOne(
            ['idInstance' => $params['id']],
            ['projection' => ['lastQr' => 1, 'lastQrAt' => 1, 'state' => 1]],
        );
        echo json_encode([
            'qr' => $instance['lastQr'] ?? null,
            'state' => $instance['state'] ?? null,
        ]);
    }

    public function config(array $params): void
    {
        if (!$this->authenticate()) return;
        header('Content-Type: application/json');
        $instance = MongoClient::db($this->config)->selectCollection('instances')->findOne(
            ['idInstance' => $params['id']],
            ['projection' => ['webhookUrl' => 1, 'webhookSecret' => 1, 'settings' => 1]],
        );
        echo json_encode([
            'webhookUrl' => $instance['webhookUrl'] ?? null,
            'webhookSecret' => $instance['webhookSecret'] ?? null,
            'settings' => $instance['settings'] ?? new \stdClass(),
        ]);
    }

    public function stateChange(array $params): void
    {
        if (!$this->authenticate()) return;
        header('Content-Type: application/json');
        $body = $this->readJson();
        MongoClient::db($this->config)->selectCollection('instances')->updateOne(
            ['idInstance' => $params['id']],
            ['$set' => ['state' => $body['to'] ?? null, 'lastSeen' => new UTCDateTime()]],
        );
        echo json_encode(['ok' => true]);
    }
}
