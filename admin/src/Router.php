<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin;

final class Router
{
    /** @param array<int, array{0:string,1:string,2:array{0:class-string,1:string}}> $routes */
    public function __construct(private readonly array $routes, private readonly array $config) {}

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        foreach ($this->routes as [$verb, $pattern, $handler]) {
            if ($verb !== $method) {
                continue;
            }
            if (preg_match($pattern, $path, $matches) !== 1) {
                continue;
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            [$class, $action] = $handler;
            $controller = new $class($this->config);
            $controller->{$action}($params);
            return;
        }

        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'NotFound', 'path' => $path]);
    }
}
