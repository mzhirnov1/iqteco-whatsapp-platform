<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Controllers;

use Iqteco\WaAdmin\Services\AuthService;
use Iqteco\WaAdmin\Services\Logger;
use Iqteco\WaAdmin\Services\View;

/**
 * Operator-facing support chat at /support.
 *
 * Reads/writes the support_chats collection on the legacy wa.iqteco.com server
 * via /support_action.php using a shared Bearer secret. Two Mongo instances
 * are isolated (admin DB vs legacy DB), so REST is the only bridge.
 */
final class SupportController
{
    public function __construct(private readonly array $config) {}

    private function auth(): array
    {
        $svc = new AuthService($this->config);
        $svc->requireAuth();
        return $svc->user() ?? ['email' => '', 'role' => ''];
    }

    private function jsonAuth(): array
    {
        if (empty($_SESSION['user_id'])) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'unauthorized']);
            exit;
        }
        return [
            'email' => (string)($_SESSION['user_email'] ?? ''),
            'role'  => (string)($_SESSION['user_role'] ?? ''),
        ];
    }

    public function show(array $params): void
    {
        $user = $this->auth();
        $cfg = $this->config['support'] ?? [];
        $ready = !empty($cfg['shared_secret']) && !empty($cfg['legacy_base_url']);
        View::renderLayout('support_chat', [
            'title'    => 'Support — iqteco-wa',
            'user'     => $user,
            'configured' => $ready,
        ]);
    }

    /**
     * Server-to-server call to legacy support_action.php.
     * @param string $act
     * @param array $params
     * @param string $method 'GET'|'POST'
     * @return array{status:int, body:array}
     */
    private function callLegacy(string $act, array $params = [], string $method = 'POST'): array
    {
        $cfg = $this->config['support'] ?? [];
        $base = rtrim((string)($cfg['legacy_base_url'] ?? ''), '/');
        $secret = (string)($cfg['shared_secret'] ?? '');
        if ($base === '' || $secret === '') {
            return ['status' => 500, 'body' => ['error' => 'support_not_configured']];
        }
        $url = $base . '/support_action.php';
        $params['act'] = $act;
        $ch = curl_init();
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $secret],
        ];
        if ($method === 'GET') {
            $opts[CURLOPT_URL] = $url . '?' . http_build_query($params);
        } else {
            $opts[CURLOPT_URL] = $url;
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return ['status' => 502, 'body' => ['error' => 'legacy_unreachable', 'detail' => $err]];
        }
        $body = json_decode((string)$resp, true);
        if (!is_array($body)) $body = ['error' => 'bad_legacy_response', 'raw' => substr((string)$resp, 0, 200)];
        return ['status' => $status, 'body' => $body];
    }

    private function emitJson(array $r): void
    {
        http_response_code($r['status']);
        header('Content-Type: application/json');
        echo json_encode($r['body'], JSON_UNESCAPED_UNICODE);
    }

    public function listChats(array $params): void
    {
        $this->jsonAuth();
        $r = $this->callLegacy('list_chats', [], 'POST');
        $this->emitJson($r);
    }

    public function chatMessages(array $params): void
    {
        $this->jsonAuth();
        $memberId = (string)($params['memberId'] ?? '');
        if ($memberId === '') {
            $this->emitJson(['status' => 400, 'body' => ['error' => 'member_id required']]);
            return;
        }
        $markRead = !empty($_GET['mark_read']) ? '1' : '';
        $r = $this->callLegacy('poll_operator', ['member_id' => $memberId, 'mark_read' => $markRead], 'POST');
        $this->emitJson($r);
    }

    public function sendOperatorMessage(array $params): void
    {
        $user = $this->jsonAuth();
        $memberId = (string)($params['memberId'] ?? '');
        $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $text = trim((string)($body['text'] ?? ($_POST['text'] ?? '')));
        if ($memberId === '' || $text === '') {
            $this->emitJson(['status' => 400, 'body' => ['error' => 'member_id and text required']]);
            return;
        }
        $r = $this->callLegacy('send_operator', [
            'member_id'      => $memberId,
            'text'           => $text,
            'operator_email' => $user['email'],
        ], 'POST');
        $this->emitJson($r);
    }

    public function setMode(array $params): void
    {
        $user = $this->jsonAuth();
        $memberId = (string)($params['memberId'] ?? '');
        $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $mode = (string)($body['mode'] ?? ($_POST['mode'] ?? ''));
        if ($memberId === '' || !in_array($mode, ['ai', 'human'], true)) {
            $this->emitJson(['status' => 400, 'body' => ['error' => 'bad params']]);
            return;
        }
        $r = $this->callLegacy('set_mode', [
            'member_id'      => $memberId,
            'mode'           => $mode,
            'operator_email' => $user['email'],
        ], 'POST');
        $this->emitJson($r);
    }
}
