<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Services;

final class I18n
{
    private static array $messages = [];
    private static string $locale = 'en';
    private const SUPPORTED = ['ru', 'en'];

    public static function init(array $config): void
    {
        $locale = $_SESSION['locale'] ?? self::detect();
        if (!in_array($locale, self::SUPPORTED, true)) {
            $locale = $config['app']['default_locale'] ?? 'en';
        }
        self::$locale = $locale;
        $path = __DIR__ . "/../I18n/{$locale}.php";
        self::$messages = is_file($path) ? require $path : [];
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    public static function t(string $key, array $params = []): string
    {
        $text = self::$messages[$key] ?? $key;
        if (!$params) return $text;
        foreach ($params as $k => $v) {
            $text = str_replace('{' . $k . '}', (string)$v, $text);
        }
        return $text;
    }

    private static function detect(): string
    {
        $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if (preg_match_all('/([a-z]{2})(?:-[a-z]{2})?\s*(?:;\s*q=([\d.]+))?/i', $accept, $m, PREG_SET_ORDER)) {
            usort($m, fn($a, $b) => (float)($b[2] ?? 1) <=> (float)($a[2] ?? 1));
            foreach ($m as $entry) {
                $lang = strtolower($entry[1]);
                if (in_array($lang, self::SUPPORTED, true)) return $lang;
            }
        }
        return 'en';
    }
}
