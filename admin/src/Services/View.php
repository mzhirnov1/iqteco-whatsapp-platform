<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Services;

final class View
{
    private static string $viewPath = '';

    public static function setPath(string $path): void
    {
        self::$viewPath = rtrim($path, '/');
    }

    public static function render(string $template, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        $file = self::$viewPath . '/' . $template . '.php';
        if (!is_file($file)) {
            http_response_code(500);
            echo "View not found: {$template}";
            return;
        }
        include $file;
    }

    public static function renderLayout(string $template, array $data = [], string $layout = 'layout'): void
    {
        ob_start();
        self::render($template, $data);
        $content = ob_get_clean();
        $data['content'] = $content;
        self::render($layout, $data);
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}
