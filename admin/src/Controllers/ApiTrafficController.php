<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Controllers;

final class ApiTrafficController
{
    public function __construct(private readonly array $config) {}

    public function report(array $params): void
    {
        http_response_code(501);
        echo json_encode(['error' => 'not implemented (phase 5)']);
    }
}
