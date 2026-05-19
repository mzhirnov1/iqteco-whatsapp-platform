<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Controllers;

use Iqteco\WaAdmin\Services\InstanceManager;
use Iqteco\WaAdmin\Services\IpPoolManager;
use Iqteco\WaAdmin\Services\Logger;
use Iqteco\WaAdmin\Services\MongoClient;
use Iqteco\WaAdmin\Services\NftablesManager;
use Iqteco\WaAdmin\Services\NginxMapManager;
use Iqteco\WaAdmin\Services\PodmanRunner;

/**
 * Green API Partner-compatible endpoint.
 * Allows legacy /var/www/wa.iqteco.com (helpers/GreenApiPartner.php) to
 * create/delete instances on our platform without modifying its consumer
 * code (handler.php, action.php, dashboard.php). Auth: header X-Partner-Token.
 *
 * Contract:
 *   POST /api/partner/createInstance  { name?, webhookUrl? } →
 *       { id: "1101000XXX", api_token: "<50hex>", apiUrl: "https://api.wa.iqteco.com" }
 *   POST /api/partner/deleteInstance/{id}            → { ok: true }
 *   GET  /api/partner/getInstances                    → [ {idInstance, state, ipv6, ...}, ... ]
 */
final class PartnerApiController
{
    public function __construct(private readonly array $config) {}

    private function authenticate(): bool
    {
        $expected = (string)(\wa_env('PARTNER_TOKEN') ?? '');
        if ($expected === '') {
            $this->respond(500, ['error' => 'partner_token_not_configured']);
            return false;
        }
        $provided = (string)($_SERVER['HTTP_X_PARTNER_TOKEN'] ?? $_GET['partnerToken'] ?? '');
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

    private function manager(): InstanceManager
    {
        $log = new Logger('partner-api');
        return new InstanceManager(
            $this->config, $log,
            new IpPoolManager($this->config, $log),
            new PodmanRunner($this->config, $log),
            new NginxMapManager($this->config, $log),
            new NftablesManager($this->config, $log),
        );
    }

    public function createInstance(array $params): void
    {
        if (!$this->authenticate()) return;

        $body = $this->readJson();
        $name = (string)($body['name'] ?? '');
        $webhookUrl = (string)($body['webhookUrl'] ?? '');

        try {
            $r = $this->manager()->createForOwner([
                'authMethod' => 'qr',
                'webhookUrl' => $webhookUrl,
                'ownerId' => $name !== '' ? $name : ('partner-' . bin2hex(random_bytes(4))),
            ]);
            $this->respond(200, [
                'id' => $r['idInstance'],
                'api_token' => $r['apiToken'],
                'apiUrl' => 'https://api.wa.iqteco.com',
                'idInstance' => $r['idInstance'],            // alias for clients that expect this name
                'apiTokenInstance' => $r['apiToken'],
            ]);
        } catch (\Throwable $e) {
            $this->respond(500, ['error' => 'create_failed', 'message' => $e->getMessage()]);
        }
    }

    public function deleteInstance(array $params): void
    {
        if (!$this->authenticate()) return;
        $idInstance = (string)$params['id'];
        try {
            $this->manager()->delete($idInstance);
            $this->respond(200, ['ok' => true, 'idInstance' => $idInstance]);
        } catch (\Throwable $e) {
            $this->respond(500, ['error' => 'delete_failed', 'message' => $e->getMessage()]);
        }
    }

    public function getInstances(array $params): void
    {
        if (!$this->authenticate()) return;
        $cursor = MongoClient::db($this->config)->selectCollection('instances')->find(
            ['state' => ['$ne' => 'deleted']],
            ['projection' => ['idInstance' => 1, 'apiToken' => 1, 'state' => 1, 'ipv6' => 1,
                              'phoneNumber' => 1, 'ownerId' => 1, 'createdAt' => 1],
             'sort' => ['createdAt' => -1], 'limit' => 500],
        );
        $out = [];
        foreach ($cursor as $d) {
            $out[] = [
                'idInstance' => $d['idInstance'],
                'apiTokenInstance' => $d['apiToken'],
                'apiUrl' => 'https://api.wa.iqteco.com',
                'state' => $d['state'] ?? null,
                'ipv6' => $d['ipv6'] ?? null,
                'phoneNumber' => $d['phoneNumber'] ?? null,
                'name' => $d['ownerId'] ?? null,
            ];
        }
        $this->respond(200, $out);
    }

    private function respond(int $status, array|string $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);
    }
}
