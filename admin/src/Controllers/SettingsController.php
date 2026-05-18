<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Controllers;

final class SettingsController
{
    public function __construct(private readonly array $config) {}

    public function index(array $params): void
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: /login');
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>Settings</h1><p>Not implemented (phase 2).</p>';
    }
}
