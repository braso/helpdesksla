<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Response;
use App\Services\Auth\AuthService;
use Throwable;

final class LogoutController
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    /**
     * POST /api/v1/auth/logout
     *
     * Rota protegida — requer AuthMiddleware antes de chegar aqui.
     * O token Bearer já foi validado pelo middleware; só precisamos revogá-lo.
     *
     * Logout é sempre HTTP 200, mesmo se o token já havia sido revogado:
     * o estado desejado (token inativo) já foi atingido.
     */
    public function store(): never
    {
        $rawToken = $this->extractBearerToken();

        // Defensivo: o middleware já garantiu que o token existe e é válido.
        // Esta checagem protege contra uso direto do controller sem middleware.
        if ($rawToken === null) {
            Response::error('Token não fornecido.', statusCode: 401);
        }

        try {
            $this->authService->logout($rawToken);

            Response::success(data: null, message: 'Logout realizado com sucesso.');

        } catch (Throwable $e) {
            error_log(sprintf('[%s] %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
            Response::error('Ocorreu um erro interno.', statusCode: 500);
        }
    }

    private function extractBearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token !== '' ? $token : null;
    }
}
