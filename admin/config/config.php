<?php
declare(strict_types=1);

return [
    'mongo' => [
        'uri' => getenv('MONGO_URL') ?: 'mongodb://127.0.0.1:27017',
        'database' => getenv('MONGO_DB') ?: 'iqteco_wa',
    ],
    'admin' => [
        'shared_token' => getenv('ADMIN_TOKEN') ?: '',
        'base_url' => getenv('ADMIN_BASE_URL') ?: 'https://admin.wa.iqteco.com',
        'session_lifetime' => 86400,
    ],
    'podman' => [
        'binary' => '/usr/bin/podman',
        'sudo_binary' => '/usr/bin/sudo',
        'image' => getenv('WA_IMAGE') ?: 'ghcr.io/mzhirnov1/iqteco-whatsapp-platform/wa-instance:latest',
        'network' => 'wa-net',
        'name_prefix' => 'wa-',
    ],
    'ip_pool' => [
        'prefix' => getenv('IPV6_PREFIX') ?: '2a01:4f8:221:2d8d::',
        'subnet_bits' => 64,
        'reserved_offset' => 0xa010001,
        'reserved_count' => 255,
    ],
    'nginx' => [
        'map_file' => '/etc/nginx/wa-instances.map',
        'reload_cmd' => '/usr/sbin/nginx -s reload',
    ],
    'webhook' => [
        'default_url' => getenv('DEFAULT_WEBHOOK_URL') ?: '',
        'log_ttl_days' => 30,
    ],
    'traffic' => [
        'hourly_mb' => 100,
        'daily_mb' => 500,
        'monthly_gb' => 2,
        'alert_threshold' => 0.8,
    ],
    'app' => [
        'debug' => getenv('APP_DEBUG') === '1',
        'default_locale' => 'ru',
    ],
];
