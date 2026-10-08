<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\AuthenticationException;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Repositories\ApiTokenRepository;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Support\Validator;

final class AuthService
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly ApiTokenRepository      $tokenRepository,
        private readonly JwtService              $jwtService,
    ) {}

    /**
     * Autentica um usuário por e-mail e senha.
     *
     * Retorna o token JWT e os dados públicos do usuário.
     * Lança ValidationException para campos faltantes e
     * AuthenticationException para credenciais inválidas.
     *
     * @param  array<string, mixed> $requestData
     * @return array{ token: string, expires_in: int, user: array<string, mixed> }
     * @throws ValidationException
     * @throws AuthenticationException
     */
    public function login(array $requestData, string $ipAddress): array
    {
        $this->validateLoginInput($requestData);

        $email    = trim((string) ($requestData['email']    ?? ''));
        $password = (string)       ($requestData['password'] ?? '');

        $user = $this->userRepository->findByEmail($email);

        // A mesma mensagem genérica para usuário inexistente e senha errada
        // impede enumeração de e-mails cadastrados (user enumeration attack).
        if ($user === null || !$user->isActive) {
            throw new AuthenticationException('Credenciais inválidas.');
        }

        if (!password_verify($password, $user->password)) {
            throw new AuthenticationException('Credenciais inválidas.');
        }

        $token = $this->jwtService->issue([
            'sub'  => $user->id,
            'uuid' => $user->uuid,
            'name' => $user->name,
        ]);

        $this->tokenRepository->store($user->id, $token, $this->jwtService->getTtl());
        $this->userRepository->touchLogin($user->id, $ipAddress);

        return [
            'token'      => $token,
            'expires_in' => $this->jwtService->getTtl(),
            'user'       => $user->toPublicArray(),
        ];
    }

    /**
     * Revoga o token ativo — invalida a sessão independentemente do TTL.
     * Logout é sempre bem-sucedido: não lança se o token já foi removido.
     */
    public function logout(string $rawToken): void
    {
        $this->tokenRepository->revoke($rawToken);
    }

    /**
     * Valida um token JWT de requisição entrante.
     * Verifica assinatura, expiração e se o token não foi revogado via logout.
     *
     * @return array<string, mixed> Payload do JWT (sub, uuid, name, iat, exp, jti)
     * @throws AuthenticationException
     */
    public function validateToken(string $rawToken): array
    {
        // Valida assinatura e expiração (verificação stateless).
        $payload = $this->jwtService->validate($rawToken);

        // Verifica se o token ainda existe na base (não foi revogado por logout).
        if (!$this->tokenRepository->isValid($rawToken)) {
            throw new AuthenticationException('Token revogado.');
        }

        // Atualiza last_used_at para auditoria de sessões ativas.
        $this->tokenRepository->touch($rawToken);

        return $payload;
    }

    /** @throws ValidationException */
    private function validateLoginInput(array $data): void
    {
        $validator = (new Validator())
            ->required('email',    $data['email']    ?? null)
            ->required('password', $data['password'] ?? null);

        if (!$validator->passes()) {
            throw new ValidationException($validator->errors());
        }
    }
}
