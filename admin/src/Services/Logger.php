<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Services;

final class Logger
{
    private const SENSITIVE_KEYS = ['apiToken', 'apiTokenInstance', 'adminToken', 'webhookSecret', 'password', 'passHash'];

    public function __construct(
        private readonly string $context = 'admin',
        private readonly string $file = '/var/log/wa/admin.log',
    ) {}

    public function info(string $msg, array $data = []): void { $this->write('INFO', $msg, $data); }
    public function warn(string $msg, array $data = []): void { $this->write('WARN', $msg, $data); }
    public function error(string $msg, array $data = []): void { $this->write('ERROR', $msg, $data); }
    public function debug(string $msg, array $data = []): void { $this->write('DEBUG', $msg, $data); }

    private function write(string $level, string $msg, array $data): void
    {
        $line = sprintf(
            "[%s] [%s] [%s] %s%s\n",
            date('Y-m-d H:i:s'),
            $level,
            $this->context,
            $msg,
            $data ? ' ' . json_encode($this->mask($data), JSON_UNESCAPED_UNICODE) : ''
        );
        @file_put_contents($this->file, $line, FILE_APPEND);
        if (PHP_SAPI === 'cli') fwrite(STDERR, $line);
    }

    private function mask(array $data): array
    {
        array_walk_recursive($data, function (&$v, $k) {
            if (in_array($k, self::SENSITIVE_KEYS, true) && is_string($v) && $v !== '') {
                $v = strlen($v) <= 8 ? '***' : substr($v, 0, 4) . '***' . substr($v, -2);
            }
        });
        return $data;
    }
}
