<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Controllers;

use Iqteco\WaAdmin\Services\AuthService;
use Iqteco\WaAdmin\Services\IpPoolManager;
use Iqteco\WaAdmin\Services\Logger;
use Iqteco\WaAdmin\Services\MongoClient;
use Iqteco\WaAdmin\Services\View;

final class DashboardController
{
    public function __construct(private readonly array $config) {}

    public function index(array $params): void
    {
        (new AuthService($this->config))->requireAuth();

        $db = MongoClient::db($this->config);

        $instances = $db->selectCollection('instances')
            ->find(
                ['state' => ['$ne' => 'deleted']],
                ['sort' => ['createdAt' => -1], 'limit' => 200]
            )
            ->toArray();

        $stats = (new IpPoolManager($this->config, new Logger('dashboard')))->stats();

        // Aggregate webhook outbox status per instance for badges
        $webhookStatsRaw = $db->selectCollection('webhook_outbox')->aggregate([
            ['$match' => ['status' => ['$in' => ['pending', 'failed']]]],
            ['$group' => ['_id' => ['idInstance' => '$idInstance', 'status' => '$status'], 'n' => ['$sum' => 1]]],
        ])->toArray();
        $webhookStats = [];
        foreach ($webhookStatsRaw as $row) {
            $iid = $row['_id']['idInstance'];
            $st = $row['_id']['status'];
            $webhookStats[$iid][$st] = (int)$row['n'];
        }

        // Build {idInstance => {domain, member_id, needs_relink}} map by joining
        // instance.ownerId (format "<member_id>:<label>") with legacy portals.
        $portalsByInstance = $this->resolvePortalsForInstances($instances);

        View::renderLayout('dashboard', [
            'instances' => $instances,
            'stats' => $stats,
            'webhookStats' => $webhookStats,
            'portalsByInstance' => $portalsByInstance,
        ]);
    }

    /**
     * @param iterable $instances
     * @return array<string, array{domain:string, member_id:string, needs_relink:bool}>
     */
    private function resolvePortalsForInstances(iterable $instances): array
    {
        $memberIds = [];
        $iidToMember = [];
        foreach ($instances as $i) {
            $owner = (string)($i['ownerId'] ?? '');
            if ($owner === '' || $owner === '__pool__') continue;
            $mid = strpos($owner, ':') !== false ? substr($owner, 0, strpos($owner, ':')) : $owner;
            if ($mid === '') continue;
            $iidToMember[(string)$i['idInstance']] = $mid;
            $memberIds[$mid] = true;
        }
        if (empty($memberIds)) return [];

        $cfg = $this->config['support'] ?? [];
        $base = rtrim((string)($cfg['legacy_base_url'] ?? ''), '/');
        $secret = (string)($cfg['shared_secret'] ?? '');
        $portalsByMember = [];
        if ($base !== '' && $secret !== '') {
            $ch = curl_init($base . '/support_action.php');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $secret],
                CURLOPT_POSTFIELDS     => http_build_query([
                    'act'        => 'resolve_portals',
                    'member_ids' => implode(',', array_keys($memberIds)),
                ]),
            ]);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($resp !== false && $code === 200) {
                $j = json_decode((string)$resp, true);
                if (is_array($j) && isset($j['portals']) && is_array($j['portals'])) {
                    $portalsByMember = $j['portals'];
                }
            }
        }

        $out = [];
        foreach ($iidToMember as $iid => $mid) {
            $p = $portalsByMember[$mid] ?? null;
            $out[$iid] = [
                'member_id'    => $mid,
                'domain'       => (string)($p['domain'] ?? ''),
                'needs_relink' => (bool)($p['needs_relink'] ?? false),
            ];
        }
        return $out;
    }
}
