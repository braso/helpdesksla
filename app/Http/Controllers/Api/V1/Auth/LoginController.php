<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\AuthenticationException;
use App\Exceptions\ValidationException;
use App\Http\Response;
use App\Services\Auth\AuthService;
use JsonException;
use Throwable;

final class LoginController
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    /**
     * POST /api/v1/auth/login
     *
     * Body: { "email": "...", "password": "..." }
     *
     * Responses:
     *   200 OK             — autenticado com sucesso
     *   400 Bad Request    — JSON inválido
     *   401 Unauthorized   — credenciais incorretas
     *   422 Unprocessable  — campos obrigatórios ausentes
     *   500 Internal Error — falha inesperada
     */
    public function store(): never
    {
        $rawBody = (string) file_get_contents('php://input');

        if ($rawBody === '') {
            Response::error('O corpo da requisição não pode estar vazio.', statusCode: 400);
        }

        try {
            $data = json_decode($rawBody, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Response::error('JSON inválido: ' . $e->getMessage(), statusCode: 400);
        }

        if (!is_array($data)) {
            Response::error('O corpo da requisição deve ser um objeto JSON.', statusCode: 400);
        }

        // IP para auditoria de login (respeitando proxies confiáveis como Nginx/Load Balancer).
        $ipAddress = $this->resolveClientIp();

        try {
            $result = $this->authService->login($data, $ipAddress);

            Response::success(
                data:       $result,
                message:    'Login realizado com sucesso.',
                statusCode: 200
            );

        } catch (ValidationException $e) {
            Response::error($e->getMessage(), errors: $e->errors(), statusCode: 422);

        } catch (AuthenticationException $e) {
            Response::error($e->getMessage(), statusCode: 401);

        } catch (Throwable $e) {
            error_log(sprintf('[%s] %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
            Response::error('Ocorreu um erro interno. Tente novamente mais tarde.', statusCode: 500);
        }
    }

    private function resolveClientIp(): string
    {
        // X-Forwarded-For só é confiável quando vindo de um proxy controlado (Nginx/ALB).
        // Em produção, restringir ao IP do proxy reverso via configuração do servidor.
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';
    }
}
