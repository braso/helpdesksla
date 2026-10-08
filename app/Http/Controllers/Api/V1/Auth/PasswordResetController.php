<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ValidationException;
use App\Http\Response;
use App\Services\Auth\PasswordResetService;

/**
 * Rotas públicas:
 *   POST /api/v1/auth/forgot-password       { email }
 *   POST /api/v1/auth/reset-password/check  { token }
 *   POST /api/v1/auth/reset-password        { token, password, password_confirmation }
 */
final class PasswordResetController
{
    private const GENERIC = 'Se houver uma conta com este e-mail, enviaremos em instantes um link para criar uma nova senha.';

    public function __construct(private readonly PasswordResetService $service) {}

    public function forgot(): never
    {
        $b = $this->body();
        try {
            $this->service->request((string) ($b['email'] ?? ''), $this->ip(), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        } catch (ValidationException $e) {
            Response::error('Informe um e-mail válido.', errors: $e->errors(), statusCode: 422);
        }
        Response::success(data: null, message: self::GENERIC);
    }

    public function check(): never
    {
        $b = $this->body();
        Response::success(data: ['valid' => $this->service->check((string) ($b['token'] ?? ''))]);
    }

    public function reset(): never
    {
        $b = $this->body();
        $this->service->reset((string) ($b['token'] ?? ''), (string) ($b['password'] ?? ''), (string) ($b['password_confirmation'] ?? ''), $this->ip());
        Response::success(data: null, message: 'Senha alterada. Entre com a nova senha.');
    }

    private function body(): array
    {
        return (array) (json_decode((string) file_get_contents('php://input'), true) ?? []);
    }

    private function ip(): string
    {
        // REMOTE_ADDR vem do nginx (não confiável o X-Forwarded-For enviado pelo cliente).
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
