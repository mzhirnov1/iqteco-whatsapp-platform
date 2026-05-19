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

    /**
     * Aggregated chat list for WhatsApp Web UI sidebar.
     * Builds list from getContacts + lastIncoming + lastOutgoing, sorted by recency.
     */
    public function chatList(array $params): void
    {
        $this->requireSession();
        $idInstance = (string)$params['id'];

        $instance = MongoClient::db($this->config)->selectCollection('instances')->findOne(
            ['idInstance' => $idInstance],
            ['projection' => ['ipv6' => 1, 'apiToken' => 1]],
        );
        if (!$instance || empty($instance['ipv6']) || empty($instance['apiToken'])) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'instance_not_ready']);
            return;
        }

        $base = sprintf('http://[%s]:8080/waInstance%s', $instance['ipv6'], $idInstance);
        $tok = $instance['apiToken'];
        $minutes = max(1, min(10080, (int)($_GET['minutes'] ?? 1440)));

        // Fetch in parallel via curl_multi
        $mh = curl_multi_init();
        $urls = [
            'incoming' => "$base/lastIncomingMessages/$tok?minutes=$minutes",
            'outgoing' => "$base/lastOutgoingMessages/$tok?minutes=$minutes",
        ];
        $handles = [];
        foreach ($urls as $key => $u) {
            $ch = curl_init($u);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.5);
        } while ($running > 0);

        $results = [];
        foreach ($handles as $key => $ch) {
            $resp = curl_multi_getcontent($ch);
            $results[$key] = json_decode((string)$resp, true) ?: [];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        // Merge into one map keyed by chatId
        $chats = [];
        foreach ($results['incoming'] as $m) {
            $cid = $m['chatId'] ?? null;
            if (!$cid) continue;
            $ts = (int)($m['timestamp'] ?? 0);
            if (!isset($chats[$cid]) || $ts > ($chats[$cid]['timestamp'] ?? 0)) {
                $chats[$cid] = [
                    'chatId' => $cid,
                    'name' => $m['senderName'] ?? $cid,
                    'lastMessage' => $m['textMessage'] ?? ('[' . ($m['typeMessage'] ?? '') . ']'),
                    'timestamp' => $ts,
                    'direction' => 'incoming',
                ];
            }
        }
        foreach ($results['outgoing'] as $m) {
            $cid = $m['chatId'] ?? null;
            if (!$cid) continue;
            $ts = (int)($m['timestamp'] ?? 0);
            if (!isset($chats[$cid]) || $ts > ($chats[$cid]['timestamp'] ?? 0)) {
                $chats[$cid] = [
                    'chatId' => $cid,
                    'name' => $chats[$cid]['name'] ?? $cid,
                    'lastMessage' => $m['textMessage'] ?? ('[' . ($m['typeMessage'] ?? '') . ']'),
                    'timestamp' => $ts,
                    'direction' => 'outgoing',
                ];
            }
        }

        $list = array_values($chats);
        usort($list, fn($a, $b) => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));

        header('Content-Type: application/json');
        echo json_encode($list);
    }

    /**
     * Avatar URL with simple file-based cache (1h TTL).
     */
    public function avatar(array $params): void
    {
        $this->requireSession();
        $idInstance = (string)$params['id'];
        $chatId = (string)($_GET['chatId'] ?? '');
        if ($chatId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'chatId required']);
            return;
        }

        $cacheDir = '/tmp/wa-admin-avatar-cache';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
        $cacheKey = $idInstance . '-' . md5($chatId);
        $cacheFile = $cacheDir . '/' . $cacheKey . '.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
            header('Content-Type: application/json');
            echo (string)file_get_contents($cacheFile);
            return;
        }

        $instance = MongoClient::db($this->config)->selectCollection('instances')->findOne(
            ['idInstance' => $idInstance],
            ['projection' => ['ipv6' => 1, 'apiToken' => 1]],
        );
        if (!$instance || empty($instance['ipv6'])) {
            http_response_code(404);
            echo json_encode(['error' => 'instance_not_ready']);
            return;
        }

        $url = sprintf(
            'http://[%s]:8080/waInstance%s/getAvatar/%s?chatId=%s',
            $instance['ipv6'], $idInstance, $instance['apiToken'], rawurlencode($chatId),
        );
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $resp = curl_exec($ch);
        curl_close($ch);

        $payload = $resp ?: json_encode(['urlAvatar' => '', 'available' => false]);
        @file_put_contents($cacheFile, $payload);
        header('Content-Type: application/json');
        echo $payload;
    }
}
