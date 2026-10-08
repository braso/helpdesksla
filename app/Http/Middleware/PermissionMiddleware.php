<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\AuthContext;
use App\Http\Response;

/**
 * Guard de autorização baseado em permission granular.
 *
 * Verifica se o usuário autenticado possui a permission específica informada.
 * Mais preciso que RoleMiddleware: independe do papel, verifica a capacidade exata.
 *
 * Uso no roteador:
 *   (new PermissionMiddleware('tickets.create'))->handle();
 *   (new PermissionMiddleware('reports.export'))->handle();
 *
 * Permissions disponíveis (formato: module.action):
 *   tickets.{view|create|edit|delete|assign|close}
 *   users.{view|create|edit|delete}
 *   reports.{view|export}
 *   kb.{view|create|edit|delete}
 *   admin.{settings|automations|sla|teams|roles}
 */
final class PermissionMiddleware
{
    public function __construct(
        private readonly string $requiredPermission,
    ) {}

    public function handle(): void
    {
        if (!AuthContext::hasPermission($this->requiredPermission)) {
            Response::error(
                message:    "Acesso negado. Permissão necessária: {$this->requiredPermission}",
                statusCode: 403
            );
        }
    }
}
