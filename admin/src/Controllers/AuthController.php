<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Controllers;

use Iqteco\WaAdmin\Services\MongoClient;

final class AuthController
{
    public function __construct(private readonly array $config) {}

    public function showLogin(array $params): void
    {
        header('Content-Type: text/html; charset=utf-8');
        echo '<form method="post" action="/login">'
            . '<input name="email" placeholder="email" required>'
            . '<input name="password" type="password" required>'
            . '<button type="submit">Login</button>'
            . '</form>';
    }

    public function login(array $params): void
    {
        $email = (string)($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        $users = MongoClient::db($this->config)->selectCollection('users');
        $user = $users->findOne(['email' => $email]);

        if ($user && password_verify($password, $user['passHash'])) {
            $_SESSION['user_id'] = (string)$user['_id'];
            $_SESSION['user_email'] = $email;
            $_SESSION['user_role'] = $user['role'] ?? 'admin';
            header('Location: /dashboard');
            return;
        }

        http_response_code(401);
        echo 'Invalid credentials';
    }

    public function logout(array $params): void
    {
        $_SESSION = [];
        session_destroy();
        header('Location: /login');
    }
}
