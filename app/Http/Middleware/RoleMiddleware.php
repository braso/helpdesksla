<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\AuthContext;
use App\Http\Response;

/**
 * Guard de autorização baseado em papel (role).
 *
 * Verifica se o usuário autenticado possui ao menos uma das roles
 * informadas na construção. AuthMiddleware deve ter rodado antes.
 *
 * Uso no roteador:
 *   (new RoleMiddleware(['admin']))->handle();           // apenas admin
 *   (new RoleMiddleware(['admin', 'agent']))->handle();  // admin ou agent
 */
final class RoleMiddleware
{
    /**
     * @param string[] $allowedRoles Slugs das roles permitidas para a rota
     */
    public function __construct(
        private readonly array $allowedRoles,
    ) {}

    public function handle(): void
    {
        if (!AuthContext::hasAnyRole($this->allowedRoles)) {
            Response::error(
                message:    'Acesso negado. Papel insuficiente.',
                statusCode: 403
            );
        }
    }
}
