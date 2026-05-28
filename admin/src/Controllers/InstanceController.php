<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Controllers;

use Iqteco\WaAdmin\Services\AuthService;
use Iqteco\WaAdmin\Services\Csrf;
use Iqteco\WaAdmin\Services\InstanceManager;
use Iqteco\WaAdmin\Services\IpPoolManager;
use Iqteco\WaAdmin\Services\Logger;
use Iqteco\WaAdmin\Services\MongoClient;
use Iqteco\WaAdmin\Services\NftablesManager;
use Iqteco\WaAdmin\Services\NginxMapManager;
use Iqteco\WaAdmin\Services\PodmanRunner;
use Iqteco\WaAdmin\Services\View;

final class InstanceController
{
    public function __construct(private readonly array $config) {}

    private function manager(): InstanceManager
    {
        $log = new Logger('instance');
        return new InstanceManager(
            $this->config,
            $log,
            new IpPoolManager($this->config, $log),
            new PodmanRunner($this->config, $log),
            new NginxMapManager($this->config, $log),
            new NftablesManager($this->config, $log),
        );
    }

    public function createForm(array $params): void
    {
        (new AuthService($this->config))->requireAuth();
        View::renderLayout('instance_new', [
            'default_webhook_url' => $this->config['webhook']['default_url'] ?? '',
            'error' => $_SESSION['instance_error'] ?? null,
        ]);
        unset($_SESSION['instance_error']);
    }

    public function create(array $params): void
    {
        (new AuthService($this->config))->requireAuth();
        Csrf::requireValid();

        $type = in_array($_POST['type'] ?? null, ['whatsapp', 'telegram'], true) ? $_POST['type'] : 'whatsapp';
        $authMethod = $type === 'telegram'
            ? ($_POST['tg_auth_method'] ?? 'tg_qr')
            : ($_POST['auth_method'] ?? 'qr');

        try {
            $created = $this->manager()->create([
                'type' => $type,
                'authMethod' => $authMethod,
                'webhookUrl' => trim((string)($_POST['webhook_url'] ?? '')),
                'ownerId' => $_SESSION['user_id'] ?? '',
                'tgPhoneNumber' => trim((string)($_POST['tg_phone'] ?? '')),
            ]);
            header('Location: /instances/' . $created['idInstance']);
        } catch (\Throwable $e) {
            $_SESSION['instance_error'] = $e->getMessage();
            header('Location: /instances/new');
        }
    }

    public function show(array $params): void
    {
        (new AuthService($this->config))->requireAuth();
        $instance = $this->manager()->find((string)$params['id']);
        if (!$instance) {
            http_response_code(404);
            echo 'Instance not found';
            return;
        }
        View::renderLayout('instance_show', ['instance' => $instance]);
    }

    public function reboot(array $params): void
    {
        (new AuthService($this->config))->requireAuth();
        Csrf::requireValid();
        $this->manager()->reboot((string)$params['id']);
        header('Location: /instances/' . $params['id']);
    }

    public function logout(array $params): void
    {
        (new AuthService($this->config))->requireAuth();
        Csrf::requireValid();
        $this->manager()->logout((string)$params['id']);
        header('Location: /instances/' . $params['id']);
    }

    public function delete(array $params): void
    {
        (new AuthService($this->config))->requireAuth();
        Csrf::requireValid();
        $banned = !empty($_POST['banned']);
        $this->manager()->delete((string)$params['id'], $banned);
        header('Location: /dashboard');
    }
}
