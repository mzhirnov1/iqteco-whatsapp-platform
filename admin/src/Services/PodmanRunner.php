<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Services;

use Symfony\Component\Process\Process;

final class PodmanRunner
{
    public function __construct(private readonly array $config, private readonly Logger $logger) {}

    private const NAME_PATTERN = '/^wa-\d{6,20}$/';

    /**
     * Запускает контейнер инстанса. Возвращает container id.
     * @throws \RuntimeException
     */
    public function run(array $opts): string
    {
        $name = $this->validateName($opts['name'] ?? '');
        $image = (string)($this->config['podman']['image']);

        $env = $opts['env'] ?? [];
        $args = [
            $this->config['podman']['sudo_binary'],
            $this->config['podman']['binary'],
            'run', '-d',
            '--name', $name,
            '--network', $this->config['podman']['network'],
            '--restart=on-failure:5',
            '--memory=1g',
            '--shm-size=1g',
            '--security-opt', 'seccomp=unconfined',
            '--label', 'wa-instance=' . substr($name, 3),
        ];

        if (!empty($opts['ipv6'])) {
            $args[] = '--ip6';
            $args[] = $this->validateIpv6((string)$opts['ipv6']);
        }

        foreach ($env as $k => $v) {
            $args[] = '-e';
            $args[] = $k . '=' . $v;
        }

        $args[] = $image;

        $proc = new Process($args);
        $proc->setWorkingDirectory('/tmp');
        $proc->setTimeout(60);
        $proc->run();

        if (!$proc->isSuccessful()) {
            $err = $proc->getErrorOutput() ?: $proc->getOutput();
            $this->logger->error('PodmanRunner.run failed', ['name' => $name, 'err' => $err]);
            throw new \RuntimeException('podman run failed: ' . $err);
        }

        $containerId = trim($proc->getOutput());
        $this->logger->info('PodmanRunner.run ok', ['name' => $name, 'id' => substr($containerId, 0, 12)]);
        return $containerId;
    }

    public function stop(string $name, int $timeoutSec = 10): bool
    {
        $name = $this->validateName($name);
        $proc = new Process([
            $this->config['podman']['sudo_binary'],
            $this->config['podman']['binary'],
            'stop', '--time=' . $timeoutSec, $name
        ]);
        $proc->setWorkingDirectory('/tmp');
        $proc->setTimeout($timeoutSec + 10);
        $proc->run();
        return $proc->isSuccessful();
    }

    public function rm(string $name, bool $force = true): bool
    {
        $name = $this->validateName($name);
        $args = [
            $this->config['podman']['sudo_binary'],
            $this->config['podman']['binary'],
            'rm',
        ];
        if ($force) $args[] = '-f';
        $args[] = $name;

        $proc = new Process($args);
        $proc->setWorkingDirectory('/tmp');
        $proc->setTimeout(30);
        $proc->run();
        return $proc->isSuccessful();
    }

    public function restart(string $name): bool
    {
        return $this->stop($name) && $this->run([]) !== '';
    }

    public function logs(string $name, int $tail = 200): string
    {
        $name = $this->validateName($name);
        $proc = new Process([
            $this->config['podman']['sudo_binary'],
            $this->config['podman']['binary'],
            'logs', '--tail=' . $tail, $name
        ]);
        $proc->setWorkingDirectory('/tmp');
        $proc->setTimeout(15);
        $proc->run();
        return $proc->getOutput() . ($proc->getErrorOutput() ? "\n--- stderr ---\n" . $proc->getErrorOutput() : '');
    }

    public function inspect(string $name): ?array
    {
        $name = $this->validateName($name);
        $proc = new Process([
            $this->config['podman']['sudo_binary'],
            $this->config['podman']['binary'],
            'inspect', $name
        ]);
        $proc->setWorkingDirectory('/tmp');
        $proc->setTimeout(10);
        $proc->run();
        if (!$proc->isSuccessful()) return null;
        $data = json_decode($proc->getOutput(), true);
        return is_array($data) && isset($data[0]) ? $data[0] : null;
    }

    public function exists(string $name): bool
    {
        return $this->inspect($name) !== null;
    }

    private function validateName(string $name): string
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException("Invalid container name: {$name}");
        }
        return $name;
    }

    private function validateIpv6(string $ipv6): string
    {
        if (filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new \InvalidArgumentException("Invalid IPv6 address: {$ipv6}");
        }
        return $ipv6;
    }
}
