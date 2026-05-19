<?php
declare(strict_types=1);

use Iqteco\WaAdmin\Controllers\ApiInstanceController;
use Iqteco\WaAdmin\Controllers\ApiTrafficController;
use Iqteco\WaAdmin\Controllers\AuthController;
use Iqteco\WaAdmin\Controllers\DashboardController;
use Iqteco\WaAdmin\Controllers\InstanceController;
use Iqteco\WaAdmin\Controllers\SettingsController;

return [
    // UI
    ['GET',  '#^/$#',                                    [DashboardController::class, 'index']],
    ['GET',  '#^/login$#',                               [AuthController::class, 'showLogin']],
    ['POST', '#^/login$#',                               [AuthController::class, 'login']],
    ['POST', '#^/logout$#',                              [AuthController::class, 'logout']],
    ['GET',  '#^/dashboard$#',                           [DashboardController::class, 'index']],
    ['GET',  '#^/instances/new$#',                       [InstanceController::class, 'createForm']],
    ['POST', '#^/instances/new$#',                       [InstanceController::class, 'create']],
    ['GET',  '#^/instances/(?P<id>\d+)$#',               [InstanceController::class, 'show']],
    ['POST', '#^/instances/(?P<id>\d+)/reboot$#',        [InstanceController::class, 'reboot']],
    ['POST', '#^/instances/(?P<id>\d+)/logout$#',        [InstanceController::class, 'logout']],
    ['POST', '#^/instances/(?P<id>\d+)/delete$#',        [InstanceController::class, 'delete']],

    ['GET',  '#^/instances/(?P<id>\d+)/webhooks$#',                          [\Iqteco\WaAdmin\Controllers\WebhookLogController::class, 'index']],
    ['GET',  '#^/instances/(?P<id>\d+)/webhooks/(?P<logId>[a-f0-9]{24})$#',  [\Iqteco\WaAdmin\Controllers\WebhookLogController::class, 'show']],
    ['POST', '#^/instances/(?P<id>\d+)/webhooks/(?P<logId>[a-f0-9]{24})/retry$#', [\Iqteco\WaAdmin\Controllers\WebhookLogController::class, 'retry']],

    ['GET',  '#^/api/instances/(?P<id>\d+)/traffic$#',  [\Iqteco\WaAdmin\Controllers\InstanceApiController::class, 'traffic']],
    ['GET',  '#^/api/instances/(?P<id>\d+)/logs$#',     [\Iqteco\WaAdmin\Controllers\InstanceApiController::class, 'logs']],

    ['GET',  '#^/instances/(?P<id>\d+)/chat$#',                                  [\Iqteco\WaAdmin\Controllers\ChatController::class, 'show']],
    ['GET',  '#^/api/instances/(?P<id>\d+)/proxy/(?P<method>[a-zA-Z]+)$#',       [\Iqteco\WaAdmin\Controllers\InstanceProxyController::class, 'proxy']],
    ['POST', '#^/api/instances/(?P<id>\d+)/proxy/(?P<method>[a-zA-Z]+)$#',       [\Iqteco\WaAdmin\Controllers\InstanceProxyController::class, 'proxy']],

    ['GET',  '#^/settings$#',                            [SettingsController::class, 'index']],

    // REST API for containers (auth: X-Admin-Token)
    ['POST', '#^/api/instances/(?P<id>\d+)/register$#',     [ApiInstanceController::class, 'register']],
    ['POST', '#^/api/instances/(?P<id>\d+)/heartbeat$#',    [ApiInstanceController::class, 'heartbeat']],
    ['POST', '#^/api/instances/(?P<id>\d+)/qr$#',           [ApiInstanceController::class, 'qr']],
    ['GET',  '#^/api/instances/(?P<id>\d+)/qr-poll$#',      [ApiInstanceController::class, 'qrPoll']],
    ['GET',  '#^/api/instances/(?P<id>\d+)/config$#',       [ApiInstanceController::class, 'config']],
    ['POST', '#^/api/instances/(?P<id>\d+)/state-change$#', [ApiInstanceController::class, 'stateChange']],
    ['POST', '#^/api/traffic-report$#',                     [ApiTrafficController::class, 'report']],
];
