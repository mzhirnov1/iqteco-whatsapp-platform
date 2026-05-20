<?php
declare(strict_types=1);

// Load .env if available
$envPath = dirname(__DIR__);
if (is_file($envPath . '/.env') && class_exists('\\Dotenv\\Dotenv')) {
    \Dotenv\Dotenv::createImmutable($envPath)->safeLoad();
}

if (!function_exists('wa_env')) {
    function wa_env(string $name, ?string $default = null): ?string {
        $v = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        if ($v !== null) return (string)$v;
        $g = getenv($name);
        return $g !== false ? $g : $default;
    }
}

return [
    'mongo' => [
        'uri' => wa_env('MONGO_URL') ?: 'mongodb://127.0.0.1:27017',
        'database' => wa_env('MONGO_DB') ?: 'iqteco_wa',
    ],
    'admin' => [
        'shared_token' => wa_env('ADMIN_TOKEN') ?: '',
        'base_url' => wa_env('ADMIN_BASE_URL') ?: 'https://admin.wa.iqteco.com',
        'session_lifetime' => 86400,
    ],
    'api' => [
        'base_url' => wa_env('API_BASE_URL') ?: 'https://api.wa.iqteco.com',
    ],
    's3' => [
        'endpoint'   => wa_env('S3_ENDPOINT')   ?: 'https://s3.eu-west-2.wasabisys.com',
        'region'     => wa_env('S3_REGION')     ?: 'eu-west-2',
        'bucket'     => wa_env('S3_BUCKET')     ?: 'wa.iqteco.com',
        'access_key' => wa_env('S3_ACCESS_KEY') ?: '',
        'secret_key' => wa_env('S3_SECRET_KEY') ?: '',
        'key_prefix' => wa_env('S3_KEY_PREFIX') ?: 'media/',
    ],
    'podman' => [
        'binary' => '/usr/bin/podman',
        'sudo_binary' => '/usr/bin/sudo',
        'image' => wa_env('WA_IMAGE') ?: 'ghcr.io/mzhirnov1/iqteco-whatsapp-platform/wa-instance:latest',
        'network' => 'wa-net',
        'name_prefix' => 'wa-',
    ],
    'ip_pool' => [
        'prefix' => wa_env('IPV6_PREFIX') ?: '2a01:4f8:221:2d8d:c0a8::',
        'subnet_bits' => 80,
        'reserved_offset' => 1,
        'reserved_count' => 255,
    ],
    'nginx' => [
        'map_file' => '/etc/nginx/wa-instances.map',
        'reload_cmd' => '/usr/sbin/nginx -s reload',
    ],
    'webhook' => [
        'default_url' => wa_env('DEFAULT_WEBHOOK_URL') ?: '',
        'log_ttl_days' => 30,
    ],
    'traffic' => [
        'hourly_mb' => 100,
        'daily_mb' => 500,
        'monthly_gb' => 2,
        'alert_threshold' => 0.8,
    ],
    'app' => [
        'debug' => wa_env('APP_DEBUG') === '1',
        'default_locale' => 'ru',
    ],
];
