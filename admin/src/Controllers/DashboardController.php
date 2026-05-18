<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Controllers;

use Iqteco\WaAdmin\Services\MongoClient;

final class DashboardController
{
    public function __construct(private readonly array $config) {}

    public function index(array $params): void
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: /login');
            return;
        }

        $instances = MongoClient::db($this->config)
            ->selectCollection('instances')
            ->find([], ['sort' => ['createdAt' => -1]])
            ->toArray();

        header('Content-Type: text/html; charset=utf-8');
        echo "<h1>Instances (" . count($instances) . ")</h1>";
        echo '<table border="1" cellpadding="6"><tr><th>id</th><th>state</th><th>phone</th><th>ipv6</th><th>lastSeen</th><th></th></tr>';
        foreach ($instances as $i) {
            $lastSeen = isset($i['lastSeen']) ? date('Y-m-d H:i:s', $i['lastSeen']->toDateTime()->getTimestamp()) : '—';
            echo sprintf(
                '<tr><td><a href="/instances/%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a href="/instances/%s">view</a></td></tr>',
                htmlspecialchars((string)$i['idInstance']),
                htmlspecialchars((string)$i['idInstance']),
                htmlspecialchars((string)($i['state'] ?? '?')),
                htmlspecialchars((string)($i['phoneNumber'] ?? '—')),
                htmlspecialchars((string)($i['ipv6'] ?? '—')),
                htmlspecialchars($lastSeen),
                htmlspecialchars((string)$i['idInstance']),
            );
        }
        echo '</table><p><a href="/instances/new">Create instance</a></p>';
    }
}
