<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Services;

final class AuthService
{
    public function __construct(private readonly array $config) {}

    public function attempt(string $email, string $password): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '' || $password === '') return null;

        $users = MongoClient::db($this->config)->selectCollection('users');
        $user = $users->findOne(['email' => $email]);

        if (!$user || !password_verify($password, (string)$user['passHash'])) {
            return null;
        }

        $users->updateOne(
            ['_id' => $user['_id']],
            ['$set' => ['lastLogin' => new \MongoDB\BSON\UTCDateTime()]]
        );

        return [
            'id' => (string)$user['_id'],
            'email' => $email,
            'role' => $user['role'] ?? 'admin',
            'locale' => $user['locale'] ?? null,
        ];
    }

    public function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        if (!empty($user['locale'])) {
            $_SESSION['locale'] = $user['locale'];
        }
        $_SESSION['login_time'] = time();
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public function user(): ?array
    {
        if (empty($_SESSION['user_id'])) return null;
        return [
            'id' => $_SESSION['user_id'],
            'email' => $_SESSION['user_email'] ?? '',
            'role' => $_SESSION['user_role'] ?? 'admin',
        ];
    }

    public function requireAuth(): void
    {
        if (!$this->user()) {
            header('Location: /login');
            exit;
        }
    }
}
