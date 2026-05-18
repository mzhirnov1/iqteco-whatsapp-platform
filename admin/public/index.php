<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Iqteco\WaAdmin\Router;

$config = require __DIR__ . '/../config/config.php';
$routes = require __DIR__ . '/../config/routes.php';

if (!empty($config['app']['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

session_set_cookie_params([
    'lifetime' => $config['admin']['session_lifetime'],
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

$router = new Router($routes, $config);
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
