<?php
/**
 * Файл: helpers/BxApi.php
 * Класс для работы с REST API Битрикс24.
 */

class BxApi {
    private $portalConfig;
    private $appConfig;
    private $db;
    private $logger;

    public function __construct($portalConfig, $appConfig, $db, $logger) {
        $this->portalConfig = $portalConfig;
        $this->appConfig = $appConfig;
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Выполняет вызов метода REST API Битрикс24 с автоматическим обновлением токена.
     * @param string $method Метод API.
     * @param array $params Параметры метода.
     * @return array|null
     */
    public function callMethod($method, $params = []) {
        // Проверяем, не истек ли токен
        if (isset($this->portalConfig['token_expires']) && time() > $this->portalConfig['token_expires']) {
            $this->logger->log("Access token expired, attempting to refresh.");
            if (!$this->ensureValidAccessToken()) {
                $this->logger->log("FATAL: Failed to refresh access token. Halting API call.");
                return ['error' => 'token_refresh_failed', 'error_description' => 'Failed to refresh access token'];
            }
        }

        $endpoint = rtrim($this->portalConfig['client_endpoint'] ?? '', '/');
        $url = $endpoint . '/' . $method . '.json';
        $params['auth'] = $this->portalConfig['access_token'] ?? '';

        $this->logger->log("Calling B24 method '$method' at URL: $url");
        $result = $this->executeRequest($url, $params);

        // If B24 says the token expired mid-request, refresh once and retry.
        if (isset($result['error']) && in_array($result['error'], ['expired_token', 'INVALID_TOKEN', 'invalid_token'], true)) {
            $this->logger->log("B24 reported {$result['error']}, forcing single refresh + retry");
            if ($this->ensureValidAccessToken(true)) {
                $params['auth'] = $this->portalConfig['access_token'];
                $result = $this->executeRequest($url, $params);
            }
        }

        if (isset($result['error'])) {
            $errorDescription = $result['error_description'] ?? print_r($result, true);
            $this->logger->log("B24 API Error for method '$method'. Description: " . $errorDescription);
        }
        return $result;
    }

    /**
     * Получает список открытых линий.
     * @return array|null
     */
    public function getOpenLines() {
        // Пробуем «новый» метод, если ошибка — фоллбек на альтернативный
        $res = $this->callMethod('imopenlines.config.list.get');
        if (isset($res['result'])) return $res;
        return $this->callMethod('imopenlines.config.list');
    }

    /**
     * Single-flight refresh:
     *  1. Acquire MongoDB lock on the portal (TTL 15s);
     *  2. Reload portal from DB — if another process already refreshed,
     *     adopt the fresh tokens and skip the network call;
     *  3. Otherwise call oauth.bitrix.info/oauth/token/ and CAS-save the
     *     new tokens (filter by old refresh_token → if someone replaced
     *     it concurrently, our update is a no-op and we re-read);
     *  4. On invalid_grant: reload, compare refresh_token. If unchanged
     *     mark the portal as needs_relink + bump failure counter; if it
     *     changed, use the fresh tokens (a peer refreshed first).
     *
     * @param bool $forceEvenIfFresh skip the "is access_token still fresh"
     *   shortcut (used on expired_token error mid-request).
     */
    public function ensureValidAccessToken(bool $forceEvenIfFresh = false): bool {
        if (empty($this->appConfig['client_id']) || empty($this->appConfig['client_secret'])) {
            $this->logger->log("Cannot refresh: client_id/client_secret missing");
            return false;
        }
        if (empty($this->portalConfig['member_id'])) {
            $this->logger->log("Cannot refresh: member_id missing on portal config");
            return false;
        }
        $memberId = (string)$this->portalConfig['member_id'];

        // Try to acquire the refresh lock; if someone else holds it, poll the
        // DB for up to 15 s for fresh tokens to land, then continue with them.
        $lockTtl = 15;
        $waitedFor = 0;
        while (!$this->db->acquireRefreshLock($memberId, $lockTtl)) {
            if ($waitedFor >= $lockTtl) {
                $this->logger->log("Refresh lock wait timeout; falling back to direct refresh attempt");
                break;
            }
            usleep(300_000); // 0.3 s
            $waitedFor += 1;
            $fresh = $this->db->getSettingsByMemberId($memberId);
            if ($fresh && !empty($fresh['access_token']) && (int)($fresh['token_expires'] ?? 0) > time() + 10) {
                $this->adoptPortal($fresh);
                $this->logger->log("Adopted refreshed tokens from another process (after wait {$waitedFor}s)");
                return true;
            }
        }

        try {
            // Re-read portal under lock — maybe another process refreshed
            // between our initial expires check and lock acquisition.
            $fresh = $this->db->getSettingsByMemberId($memberId);
            if ($fresh && !$forceEvenIfFresh
                && !empty($fresh['access_token'])
                && (int)($fresh['token_expires'] ?? 0) > time() + 10
                && $fresh['refresh_token'] !== ($this->portalConfig['refresh_token'] ?? null)
            ) {
                $this->adoptPortal($fresh);
                $this->logger->log("Re-read found fresh access_token from concurrent refresh; using it");
                return true;
            }
            if ($fresh) {
                // Use the latest refresh_token from DB, not the in-memory one
                // we were constructed with — it may be stale.
                $this->portalConfig['refresh_token'] = $fresh['refresh_token'] ?? $this->portalConfig['refresh_token'];
                $this->portalConfig['client_endpoint'] = $fresh['client_endpoint'] ?? $this->portalConfig['client_endpoint'];
                $this->portalConfig['server_endpoint'] = $fresh['server_endpoint'] ?? $this->portalConfig['server_endpoint'];
            }
            $oldRefresh = (string)($this->portalConfig['refresh_token'] ?? '');
            if ($oldRefresh === '') {
                $this->logger->log("Refresh aborted: refresh_token empty in DB");
                $this->db->markNeedsRelink($memberId, 'refresh_token_empty');
                return false;
            }

            $url = ($this->portalConfig['server_endpoint'] ?? 'https://oauth.bitrix.info/oauth/token/')
                . '?grant_type=refresh_token'
                . '&client_id=' . urlencode($this->appConfig['client_id'])
                . '&client_secret=' . urlencode($this->appConfig['client_secret'])
                . '&refresh_token=' . urlencode($oldRefresh);

            $this->logger->log("Refreshing token (single-flight)...");
            $response = $this->executeRequest($url, null, 'GET');

            if (!empty($response['access_token']) && !empty($response['refresh_token'])) {
                $newTokens = [
                    'access_token'  => $response['access_token'],
                    'refresh_token' => $response['refresh_token'],
                    'token_expires' => time() + (int)($response['expires_in'] ?? 3600),
                ];
                $applied = $this->db->casUpdateTokens($memberId, $oldRefresh, $newTokens);
                if (!$applied) {
                    // A concurrent successful refresh already replaced
                    // refresh_token in DB; adopt that one and discard ours.
                    $fresh2 = $this->db->getSettingsByMemberId($memberId);
                    if ($fresh2 && !empty($fresh2['access_token'])) {
                        $this->adoptPortal($fresh2);
                        $this->logger->log("CAS no-op (peer refreshed); adopted DB tokens");
                        return true;
                    }
                    $this->logger->log("CAS no-op but DB has no fresh tokens; aborting");
                    return false;
                }
                $this->portalConfig['access_token']  = $newTokens['access_token'];
                $this->portalConfig['refresh_token'] = $newTokens['refresh_token'];
                $this->portalConfig['token_expires'] = $newTokens['token_expires'];
                $this->logger->log("Refresh OK; new access_token prefix=" . substr($newTokens['access_token'], 0, 8));
                return true;
            }

            // invalid_grant or other failure
            $err = is_array($response) ? ($response['error'] ?? 'unknown') : 'no_response';
            $this->logger->log("Refresh failed: " . print_r($response, true));

            if ($err === 'invalid_grant') {
                // CRITICAL CHECK: maybe a concurrent peer already refreshed
                // (rotating refresh_token from under us). Reload and compare.
                $fresh2 = $this->db->getSettingsByMemberId($memberId);
                if ($fresh2 && ($fresh2['refresh_token'] ?? '') !== $oldRefresh) {
                    $this->adoptPortal($fresh2);
                    $this->logger->log("invalid_grant but peer rotated token; adopted DB tokens");
                    return true;
                }
                $this->db->markNeedsRelink($memberId, 'invalid_grant');
                $this->logger->log("invalid_grant with stable refresh_token → portal needs re-link");
            } else {
                $this->db->markNeedsRelink($memberId, (string)$err);
            }
            return false;
        } finally {
            $this->db->releaseRefreshLock($memberId);
        }
    }

    private function adoptPortal(array $fresh): void {
        $this->portalConfig['access_token']  = $fresh['access_token'];
        $this->portalConfig['refresh_token'] = $fresh['refresh_token'];
        $this->portalConfig['token_expires'] = (int)$fresh['token_expires'];
    }

    /**
     * Выполняет cURL-запрос.
     */
    private function executeRequest($url, $postData = null, $method = 'POST') {
        $ch = curl_init();
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        } else { // GET
            curl_setopt($ch, CURLOPT_URL, $url);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $rawResponse = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->log("B24 API Request cURL Error: " . $error);
            return ['error' => 'curl_error', 'error_description' => $error];
        }

        $this->logger->log("Raw response from B24: " . $rawResponse);
        $decodedResponse = json_decode($rawResponse, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->log("Failed to decode JSON response. Error: " . json_last_error_msg());
            return ['error' => 'json_decode_failed', 'error_description' => 'Could not decode JSON from response.'];
        }
        return $decodedResponse;
    }
}

