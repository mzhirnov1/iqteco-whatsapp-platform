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
 * code (handler.php, action.php, dashboard.php).
 *
 * Authentication is identical to api.green-api.com/partner: the partner
 * token goes in the URL path. Header X-Partner-Token also accepted.
 *
 * Contract:
 *   POST /api/partner/createInstance/{token}        body: { name?, webhookUrl?, type?, tgPhoneNumber? }
 *        → { idInstance, apiTokenInstance, id, api_token, apiUrl, type }
 *        type ∈ {"whatsapp" (default), "telegram"}. For telegram tgPhoneNumber
 *        is optional (used by tg_phone_code auth method, otherwise QR).
 *   POST /api/partner/deleteInstanceAccount/{token} body: { idInstance }
 *        → { deleteInstanceAccount: 1 }
 *   GET  /api/partner/getInstances/{token}
 *        → [ {idInstance, apiTokenInstance, apiUrl, state, ...}, ... ]
 */
final class PartnerApiController
{
    public function __construct(private readonly array $config) {}

    private function authenticate(array $params): bool
    {
        $expected = (string)(\wa_env('PARTNER_TOKEN') ?? '');
        if ($expected === '') {
            $this->respond(500, ['error' => 'partner_token_not_configured']);
            return false;
        }
        $provided = (string)($params['token']
            ?? $_SERVER['HTTP_X_PARTNER_TOKEN']
            ?? $_GET['partnerToken']
            ?? '');
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
        if (!$this->authenticate($params)) return;

        $body = $this->readJson();
        $name = (string)($body['name'] ?? '');
        $webhookUrl = (string)($body['webhookUrl'] ?? '');
        $tgPhoneNumber = (string)($body['tgPhoneNumber'] ?? '');

        // Explicit body.type wins; otherwise infer from name suffix
        // (clients like iqsmm.com tag channels as "<userId>:telegram"
        // or "<userId>:whatsapp" without passing type separately).
        $type = in_array($body['type'] ?? null, ['whatsapp', 'telegram'], true)
            ? $body['type']
            : (preg_match('/:(telegram|whatsapp)$/i', $name, $m) ? strtolower($m[1]) : 'whatsapp');

        try {
            $r = $this->manager()->createForOwner([
                'type' => $type,
                'authMethod' => $type === 'telegram'
                    ? ($tgPhoneNumber !== '' ? 'tg_phone_code' : 'tg_qr')
                    : 'qr',
                'webhookUrl' => $webhookUrl,
                'ownerId' => $name !== '' ? $name : ('partner-' . bin2hex(random_bytes(4))),
                'tgPhoneNumber' => $tgPhoneNumber,
            ]);
            $this->respond(200, [
                'id' => $r['idInstance'],
                'api_token' => $r['apiToken'],
                'apiUrl' => 'https://api.wa.iqteco.com',
                'idInstance' => $r['idInstance'],            // alias for clients that expect this name
                'apiTokenInstance' => $r['apiToken'],
                'type' => $type,
            ]);
        } catch (\Throwable $e) {
            $this->respond(500, ['error' => 'create_failed', 'message' => $e->getMessage()]);
        }
    }

    /**
     * GET /api/partner/qrPoll/{token}/{id}
     * Returns the current QR (or null) for the instance — used by partner
     * frontends polling for a fresh QR after createInstance. Format mirrors
     * the admin-side qrPoll so the same client code works for both.
     */
    public function qrPoll(array $params): void
    {
        if (!$this->authenticate($params)) return;
        $idInstance = (string)($params['id'] ?? '');
        if ($idInstance === '') {
            $this->respond(400, ['error' => 'idInstance required']);
            return;
        }
        $instance = MongoClient::db($this->config)->selectCollection('instances')->findOne(
            ['idInstance' => $idInstance],
            ['projection' => [
                'lastQr' => 1, 'lastQrAt' => 1, 'qrKind' => 1,
                'qrExpiresAt' => 1, 'state' => 1, 'type' => 1,
            ]]
        );
        if (!$instance) {
            $this->respond(404, ['error' => 'not_found']);
            return;
        }
        $this->respond(200, [
            'qr' => $instance['lastQr'] ?? null,
            'kind' => $instance['qrKind'] ?? 'qr',
            'type' => $instance['type'] ?? 'whatsapp',
            'state' => $instance['state'] ?? null,
            'qrAt' => isset($instance['lastQrAt']) ? $instance['lastQrAt']->toDateTime()->getTimestamp() : null,
            'expiresAt' => isset($instance['qrExpiresAt']) ? $instance['qrExpiresAt']->toDateTime()->getTimestamp() : null,
        ]);
    }

    /**
     * Returns the current state of the instance plus optional phoneNumber.
     * Matches the field names our consumers expect ({@see VirAlspy/WaPlatformService}).
     *
     * Previously read `instances.state` only — which is set on `on_ready` /
     * `on_disconnect` callbacks but doesn't update after a container loses
     * Telegram auth (session revoked, image rebuild, GridFS-session out of
     * sync with Telethon). Result: consumers saw "authorized" while the
     * actual container had `notAuthorized`.
     *
     * Now we also poll the container's own Green-API `getStateInstance`
     * (short 3s timeout) and treat its answer as authoritative. If the
     * container is unreachable we fall back to the cached DB state so the
     * endpoint stays available during upstream blips. Whenever the live
     * state differs from `instances.state` we write it back so other
     * code paths (qrPoll, getInstances, the admin UI) catch up too.
     */
    public function instanceState(array $params): void
    {
        if (!$this->authenticate($params)) return;
        $idInstance = (string)($params['id'] ?? '');
        if ($idInstance === '') {
            $this->respond(400, ['error' => 'idInstance required']);
            return;
        }
        $instance = MongoClient::db($this->config)->selectCollection('instances')->findOne(
            ['idInstance' => $idInstance],
            ['projection' => [
                'state' => 1, 'type' => 1, 'phoneNumber' => 1, 'ownerId' => 1,
                'ipv6' => 1, 'apiToken' => 1,
            ]]
        );
        if (!$instance) {
            $this->respond(404, ['error' => 'not_found']);
            return;
        }
        $cachedState = $instance['state'] ?? null;
        $state = $cachedState;
        if (!empty($instance['ipv6']) && !empty($instance['apiToken'])) {
            $url = sprintf(
                'http://[%s]:8080/waInstance%s/getStateInstance/%s',
                $instance['ipv6'],
                $idInstance,
                $instance['apiToken']
            );
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);
            $raw = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($raw !== false && $http === 200) {
                $decoded = json_decode((string)$raw, true);
                $live = is_array($decoded) ? ($decoded['stateInstance'] ?? null) : null;
                if (is_string($live) && $live !== '') {
                    $state = $live;
                    if ($live !== $cachedState) {
                        MongoClient::db($this->config)->selectCollection('instances')->updateOne(
                            ['idInstance' => $idInstance],
                            ['$set' => ['state' => $live]]
                        );
                    }
                }
            }
        }
        $this->respond(200, [
            'idInstance' => $idInstance,
            'state' => $state,
            'stateInstance' => $state,
            'type' => $instance['type'] ?? 'whatsapp',
            'phoneNumber' => $instance['phoneNumber'] ?? null,
            'name' => $instance['ownerId'] ?? null,
        ]);
    }

    public function deleteInstance(array $params): void
    {
        if (!$this->authenticate($params)) return;
        $idInstance = (string)($params['id'] ?? '');
        if ($idInstance === '') {
            // Green-API partner sends POST body { idInstance: 12345 }
            $body = $this->readJson();
            $idInstance = (string)($body['idInstance'] ?? '');
        }
        if ($idInstance === '') {
            $this->respond(400, ['error' => 'idInstance required']);
            return;
        }
        try {
            // 24h grace before real teardown; container keeps running so
            // a customer paying within the grace window can return.
            $this->manager()->markForDelete($idInstance);
            $this->respond(200, ['deleteInstanceAccount' => 1, 'idInstance' => $idInstance]);
        } catch (\Throwable $e) {
            $this->respond(500, ['error' => 'delete_failed', 'message' => $e->getMessage()]);
        }
    }

    public function getInstances(array $params): void
    {
        if (!$this->authenticate($params)) return;
        // Hide internal warm pool from partner consumers (legacy GreenApiPartner).
        $cursor = MongoClient::db($this->config)->selectCollection('instances')->find(
            [
                'state' => ['$ne' => 'deleted'],
                'ownerId' => ['$nin' => [\Iqteco\WaAdmin\Services\InstancePool::OWNER_TAG, null]],
            ],
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
