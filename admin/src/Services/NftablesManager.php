<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Services;

use Symfony\Component\Process\Process;

final class NftablesManager
{
    public function __construct(private readonly array $config, private readonly Logger $logger) {}

    public function addCounters(string $idInstance, string $ipv6): bool
    {
        $proc = new Process([
            '/usr/bin/sudo', '/usr/local/bin/wa-nft-add-counter', $idInstance, $ipv6,
        ]);
        $proc->setTimeout(10);
        $proc->run();
        if (!$proc->isSuccessful()) {
            $this->logger->warn('NftablesManager.add failed', [
                'idInstance' => $idInstance, 'ipv6' => $ipv6,
                'err' => $proc->getErrorOutput() ?: $proc->getOutput(),
            ]);
            return false;
        }
        return true;
    }

    public function removeCounters(string $idInstance): bool
    {
        $proc = new Process(['/usr/bin/sudo', '/usr/local/bin/wa-nft-del-counter', $idInstance]);
        $proc->setTimeout(10);
        $proc->run();
        if (!$proc->isSuccessful()) {
            $this->logger->warn('NftablesManager.remove failed', [
                'idInstance' => $idInstance,
                'err' => $proc->getErrorOutput() ?: $proc->getOutput(),
            ]);
            return false;
        }
        return true;
    }

    /**
     * @return array<string, array{bytes:int, packets:int}> counter_name → values
     */
    public function listCounters(): array
    {
        $proc = new Process(['/usr/bin/sudo', '/usr/sbin/nft', '-j', 'list', 'table', 'inet', 'wa_traffic']);
        $proc->setTimeout(10);
        $proc->run();
        if (!$proc->isSuccessful()) {
            $this->logger->warn('NftablesManager.list failed', [
                'err' => $proc->getErrorOutput() ?: $proc->getOutput(),
            ]);
            return [];
        }

        $data = json_decode($proc->getOutput(), true);
        if (!is_array($data) || empty($data['nftables'])) return [];

        $out = [];
        foreach ($data['nftables'] as $entry) {
            if (!isset($entry['counter'])) continue;
            $c = $entry['counter'];
            $name = (string)($c['name'] ?? '');
            if ($name === '' || !str_starts_with($name, 'wa-')) continue;
            $out[$name] = [
                'bytes' => (int)($c['bytes'] ?? 0),
                'packets' => (int)($c['packets'] ?? 0),
            ];
        }
        return $out;
    }
}
