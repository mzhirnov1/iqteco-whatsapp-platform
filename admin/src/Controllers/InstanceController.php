<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Controllers;

final class InstanceController
{
    public function __construct(private readonly array $config) {}

    public function createForm(array $params): void { http_response_code(501); echo 'not implemented (phase 2)'; }
    public function create(array $params): void { http_response_code(501); echo 'not implemented (phase 2)'; }
    public function show(array $params): void { http_response_code(501); echo 'not implemented (phase 2)'; }
    public function reboot(array $params): void { http_response_code(501); echo 'not implemented (phase 2)'; }
    public function logout(array $params): void { http_response_code(501); echo 'not implemented (phase 2)'; }
    public function delete(array $params): void { http_response_code(501); echo 'not implemented (phase 2)'; }
}
