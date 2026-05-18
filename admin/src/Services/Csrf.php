<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Services;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function check(?string $provided): bool
    {
        if (empty($_SESSION['csrf']) || !$provided) return false;
        return hash_equals((string)$_SESSION['csrf'], (string)$provided);
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token()) . '">';
    }

    public static function requireValid(): void
    {
        $provided = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!self::check(is_string($provided) ? $provided : null)) {
            http_response_code(403);
            echo json_encode(['error' => 'csrf_invalid']);
            exit;
        }
    }
}
