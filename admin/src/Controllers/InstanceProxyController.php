<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Controllers;

use Iqteco\WaAdmin\Services\AuthService;
use Iqteco\WaAdmin\Services\MongoClient;

/**
 * Прокси для WhatsApp Web UI: транслирует session-auth запросы из браузера
 * в Green-API HTTP контейнера (apiToken-auth). Ходит прямо к [ipv6]:8080
 * внутри сервера, минуя Cloudflare/nginx.
 *
 * - GET: forward query-string, JSON ответ
 * - POST: forward тело (JSON или multipart) с правильным Content-Type
 * - Whitelist методов чтобы случайно не разрешить опасные операции
 */
final class InstanceProxyController
{
    private const ALLOWED_METHODS = [
        // state / auth
        'getStateInstance', 'getSettings', 'setSettings', 'reboot', 'logout',
        'getQrCode', 'getAuthorizationCode',
        // sending
        'sendMessage', 'sendFileByUrl', 'sendImageByUrl', 'sendFileByUpload',
        'sendLocation', 'sendContact', 'forwardMessages', 'markChatAsRead',
        // queries
        'checkWhatsapp', 'getContacts', 'getChats', 'getContactInfo', 'getAvatar',
        'getChatHistory', 'lastIncomingMessages', 'lastOutgoingMessages',
        // edit / delete
        'editMessage', 'deleteMessage', 'archiveChat', 'unarchiveChat',
    ];

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

        $method = (string)($params['method'] ?? '');
        if (!preg_match('/^[a-zA-Z]+$/', $method) || !in_array($method, self::ALLOWED_METHODS, true)) {
            $this->respond(400, ['error' => 'method_not_allowed', 'method' => $method]);
            return;
        }

        $url = sprintf(
            'http://[%s]:8080/waInstance%s/%s/%s',
            $instance['ipv6'],
            $instance['idInstance'],
            $method,
            $instance['apiToken']
        );

        // Forward query-string for GET requests (e.g. ?phoneNumber=..., ?minutes=2)
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_SERVER['QUERY_STRING'])) {
            $url .= '?' . $_SERVER['QUERY_STRING'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? 'application/json';
            $isMultipart = str_starts_with($contentType, 'multipart/form-data');

            curl_setopt($ch, CURLOPT_POST, true);

            if ($isMultipart && !empty($_FILES)) {
                // Reconstruct multipart with PHP CURLFile (sendFileByUpload)
                $postFields = [];
                foreach ($_POST as $k => $v) $postFields[$k] = $v;
                foreach ($_FILES as $field => $file) {
                    if (is_array($file['name']) || empty($file['tmp_name'])) continue;
                    $postFields[$field] = new \CURLFile(
                        $file['tmp_name'],
                        $file['type'] ?: 'application/octet-stream',
                        $file['name'] ?: 'upload.bin'
                    );
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
                // Don't set Content-Type — curl handles multipart boundary
            } else {
                $body = file_get_contents('php://input') ?: '{}';
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        } else {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        }

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/json';
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            $this->respond(502, ['error' => 'container_unreachable', 'message' => $err]);
            return;
        }

        http_response_code($code ?: 200);
        header('Content-Type: ' . $contentType);
        echo $resp;
    }

    /**
     * Стрим media-файлов по messageId: проксирует
     * GET /waInstance{id}/media/{token}/{messageId} на контейнер,
     * сохраняя Content-Type/Length из ответа (для <img src=...>).
     */
    public function media(array $params): void
    {
        (new AuthService($this->config))->requireAuth();

        $instance = MongoClient::db($this->config)->selectCollection('instances')->findOne(
            ['idInstance' => (string)$params['id']]
        );
        if (!$instance || empty($instance['ipv6']) || empty($instance['apiToken'])) {
            $this->respond(404, ['error' => 'instance_not_found']);
            return;
        }

        $messageId = (string)($params['messageId'] ?? '');
        if ($messageId === '') {
            $this->respond(400, ['error' => 'messageId required']);
            return;
        }

        $url = sprintf(
            'http://[%s]:8080/waInstance%s/media/%s/%s',
            $instance['ipv6'],
            $instance['idInstance'],
            $instance['apiToken'],
            rawurlencode($messageId),
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream';
        curl_close($ch);

        if ($resp === false) {
            $this->respond(502, ['error' => 'container_unreachable']);
            return;
        }
        $body = substr($resp, $headerSize);
        http_response_code($code ?: 200);
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . strlen($body));
        echo $body;
    }

    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($body);
    }
}
