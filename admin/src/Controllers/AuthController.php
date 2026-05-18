<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Controllers;

use Iqteco\WaAdmin\Services\AuthService;
use Iqteco\WaAdmin\Services\Csrf;
use Iqteco\WaAdmin\Services\View;

final class AuthController
{
    public function __construct(private readonly array $config) {}

    public function showLogin(array $params): void
    {
        if (!empty($_SESSION['user_id'])) {
            header('Location: /dashboard');
            return;
        }
        View::renderLayout('login', ['error' => $_SESSION['login_error'] ?? null]);
        unset($_SESSION['login_error']);
    }

    public function login(array $params): void
    {
        Csrf::requireValid();
        $auth = new AuthService($this->config);
        $email = (string)($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $user = $auth->attempt($email, $password);
        if (!$user) {
            $_SESSION['login_error'] = 'invalid_credentials';
            header('Location: /login');
            return;
        }
        $auth->login($user);
        header('Location: /dashboard');
    }

    public function logout(array $params): void
    {
        Csrf::requireValid();
        (new AuthService($this->config))->logout();
        header('Location: /login');
    }
}
