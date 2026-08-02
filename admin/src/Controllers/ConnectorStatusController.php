<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Controllers;

use Iqteco\WaAdmin\Services\AuthService;
use Iqteco\WaAdmin\Services\View;

/**
 * Status of the Bitrix24 connector (wa.iqteco.com).
 *
 * It lives on another host with its own database, so we cannot query it here —
 * the connector computes the summary itself and we render what it returns.
 * A fetch failure is shown as such rather than as empty numbers, so a broken
 * link is never mistaken for "no installs".
 */
final class ConnectorStatusController
{
    public function __construct(private readonly array $config) {}

    public function index(array $params): void
    {
        (new AuthService($this->config))->requireAuth();

        $url = (string)($this->config['connector']['status_url'] ?? '');
        $token = (string)($this->config['connector']['status_token'] ?? '');

        $status = null;
        $error = null;

        if ($url === '' || $token === '') {
            $error = 'Not configured: set CONNECTOR_STATUS_URL and CONNECTOR_STATUS_TOKEN.';
        } else {
            [$status, $error] = $this->fetch($url, $token);
        }

        View::render('connector_status', [
            'status' => $status,
            'error' => $error,
            'sourceUrl' => $url,
        ]);
    }

    /** @return array{0: ?array, 1: ?string} */
    private function fetch(string $url, string $token): array
    {
        $ch = curl_init($url . (str_contains($url, '?') ? '&' : '?') . 'token=' . rawurlencode($token));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 7,
        ]);
        $body = curl_exec($ch);
        $errNo = curl_errno($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0) {
            return [null, 'Connector unreachable (curl error ' . $errNo . ')'];
        }
        if ($httpCode === 404) {
            // The endpoint answers 404 when the token is wrong or unset — the
            // two cases are indistinguishable by design, so say both.
            return [null, 'Connector returned 404: status endpoint disabled or token mismatch'];
        }
        if ($httpCode !== 200) {
            return [null, 'Connector returned HTTP ' . $httpCode];
        }

        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            return [null, 'Connector returned malformed JSON'];
        }
        return [$data, null];
    }
}
