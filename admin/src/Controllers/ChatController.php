<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Controllers;

use Iqteco\WaAdmin\Services\AuthService;
use Iqteco\WaAdmin\Services\MongoClient;
use Iqteco\WaAdmin\Services\View;

/**
 * WhatsApp Web-like UI: страница /instances/{id}/chat
 * Все методы (text/file/image/upload/location/contact/forward/edit/delete/markRead)
 * вызываются через InstanceProxyController.
 */
final class ChatController
{
    public function __construct(private readonly array $config) {}

    public function show(array $params): void
    {
        (new AuthService($this->config))->requireAuth();

        $instance = MongoClient::db($this->config)->selectCollection('instances')->findOne(
            ['idInstance' => (string)$params['id']],
            ['projection' => [
                'idInstance' => 1, 'apiToken' => 1, 'ipv6' => 1,
                'state' => 1, 'phoneNumber' => 1, 'wid' => 1,
            ]],
        );
        if (!$instance) {
            http_response_code(404);
            echo 'Instance not found';
            return;
        }

        View::renderLayout('web_chat', ['instance' => $instance]);
    }
}
