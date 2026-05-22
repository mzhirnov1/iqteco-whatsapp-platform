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
            $this->respond(500, ['error' => 'admin_token_not_configured']);
            return false;
        }
        $provided = (string)($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');
        if (!hash_equals($expected, $provided)) {
            $this->respond(401, ['error' => 'unauthorized']);
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

    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($body);
    }

    public function register(array $params): void
    {
        if (!$this->authenticate()) return;
        $body = $this->readJson();
        MongoClient::db($this->config)->selectCollection('instances')->updateOne(
            ['idInstance' => (string)$params['id']],
            ['$set' => [
                'lastSeen' => new UTCDateTime(),
                'pid' => $body['pid'] ?? null,
                'version' => $body['version'] ?? null,
                'ipv6' => $body['ipv6'] ?? null,
                'state' => $body['state'] ?? 'starting',
                'registeredAt' => new UTCDateTime(),
            ]]
        );
        $this->respond(200, ['ok' => true]);
    }

    public function heartbeat(array $params): void
    {
        if (!$this->authenticate()) return;
        $body = $this->readJson();
        $set = ['lastSeen' => new UTCDateTime()];
        if (isset($body['state'])) $set['state'] = $body['state'];
        if (!empty($body['wid'])) {
            $set['wid'] = (string)$body['wid'];
            // derive phoneNumber from wid (digits before @)
            if (preg_match('/^(\d+)@/', (string)$body['wid'], $m)) {
                $set['phoneNumber'] = $m[1];
            }
        }
        if (isset($body['lastEventAt'])) {
            $set['lastEventAt'] = new UTCDateTime((int)$body['lastEventAt'] * 1000);
        }
        MongoClient::db($this->config)->selectCollection('instances')->updateOne(
            ['idInstance' => (string)$params['id']],
            ['$set' => $set]
        );
        $this->respond(200, ['ok' => true]);
    }

    public function qr(array $params): void
    {
        if (!$this->authenticate()) return;
        $body = $this->readJson();
        MongoClient::db($this->config)->selectCollection('instances')->updateOne(
            ['idInstance' => (string)$params['id']],
            ['$set' => [
                'lastQr' => $body['qr'] ?? null,
                'lastQrAt' => new UTCDateTime(),
                'qrKind' => $body['kind'] ?? 'qr',
                'qrExpiresAt' => isset($body['expiresAt']) ? new UTCDateTime((int)$body['expiresAt'] * 1000) : null,
            ]]
        );
        $this->respond(200, ['ok' => true]);
    }

    public function qrPoll(array $params): void
    {
        if (empty($_SESSION['user_id'])) {
            $this->respond(401, ['error' => 'unauthorized']);
            return;
        }
        $instance = MongoClient::db($this->config)->selectCollection('instances')->findOne(
            ['idInstance' => (string)$params['id']],
            ['projection' => [
                'lastQr' => 1, 'lastQrAt' => 1, 'qrKind' => 1,
                'qrExpiresAt' => 1, 'state' => 1,
            ]]
        );
        if (!$instance) {
            $this->respond(404, ['error' => 'not_found']);
            return;
        }
        $this->respond(200, [
            'qr' => $instance['lastQr'] ?? null,
            'kind' => $instance['qrKind'] ?? 'qr',
            'state' => $instance['state'] ?? null,
            'qrAt' => isset($instance['lastQrAt']) ? $instance['lastQrAt']->toDateTime()->getTimestamp() : null,
            'expiresAt' => isset($instance['qrExpiresAt']) ? $instance['qrExpiresAt']->toDateTime()->getTimestamp() : null,
        ]);
    }

    public function config(array $params): void
    {
        if (!$this->authenticate()) return;
        $instance = MongoClient::db($this->config)->selectCollection('instances')->findOne(
            ['idInstance' => (string)$params['id']],
            ['projection' => ['webhookUrl' => 1, 'webhookSecret' => 1, 'settings' => 1]]
        );
        if (!$instance) {
            $this->respond(404, ['error' => 'unknown_instance']);
            return;
        }
        $this->respond(200, [
            'webhookUrl' => $instance['webhookUrl'] ?? null,
            'webhookSecret' => $instance['webhookSecret'] ?? null,
            'settings' => $instance['settings'] ?? new \stdClass(),
        ]);
    }

    public function stateChange(array $params): void
    {
        if (!$this->authenticate()) return;
        $body = $this->readJson();
        MongoClient::db($this->config)->selectCollection('instances')->updateOne(
            ['idInstance' => (string)$params['id']],
            ['$set' => [
                'state' => $body['to'] ?? null,
                'previousState' => $body['from'] ?? null,
                'stateChangedAt' => new UTCDateTime(),
                'stateChangeReason' => $body['reason'] ?? null,
                'lastSeen' => new UTCDateTime(),
            ]]
        );
        $this->respond(200, ['ok' => true]);
    }

    /**
     * Operator-facing endpoint: forwards a Telegram phone-code or 2FA password
     * from the admin UI into the running tg-instance container via its
     * Green-API-shaped POST /waInstance{id}/getAuthorizationCode/{token}.
     */
    public function tgAuthSubmit(array $params): void
    {
        if (empty($_SESSION['user_id'])) {
            $this->respond(401, ['error' => 'unauthorized']);
            return;
        }
        $id = (string)$params['id'];
        $instance = MongoClient::db($this->config)->selectCollection('instances')->findOne(
            ['idInstance' => $id],
            ['projection' => ['type' => 1, 'ipv6' => 1, 'apiToken' => 1]]
        );
        if (!$instance) { $this->respond(404, ['error' => 'not_found']); return; }
        if (($instance['type'] ?? 'whatsapp') !== 'telegram') {
            $this->respond(400, ['error' => 'not_a_telegram_instance']);
            return;
        }
        $ipv6 = (string)($instance['ipv6'] ?? '');
        $token = (string)($instance['apiToken'] ?? '');
        if ($ipv6 === '' || $token === '') {
            $this->respond(500, ['error' => 'instance_not_ready']);
            return;
        }
        $body = $this->readJson();
        $url = sprintf('http://[%s]:8080/waInstance%s/getAuthorizationCode/%s', $ipv6, $id, $token);
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($body, JSON_UNESCAPED_UNICODE),
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            $this->respond(502, ['error' => 'container_unreachable']);
            return;
        }
        $decoded = json_decode($resp, true);
        $this->respond(200, is_array($decoded) ? $decoded : ['raw' => $resp]);
    }
}
