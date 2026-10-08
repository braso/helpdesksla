<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\AuthenticationException;
use App\Http\AuthContext;
use App\Http\Response;
use App\Repositories\Contracts\RoleRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\OrganizationRepository;
use App\Services\Auth\AuthService;

final class AuthMiddleware
{
    public function __construct(
        private readonly AuthService             $authService,
        private readonly UserRepositoryInterface $userRepository,
        private readonly RoleRepositoryInterface $roleRepository,
        private readonly OrganizationRepository  $orgRepository,
    ) {}

    public function handle(): void
    {
        $rawToken = $this->extractBearerToken();

        if ($rawToken === null) {
            Response::error('Token de autenticação não fornecido.', statusCode: 401);
        }

        try {
            $payload = $this->authService->validateToken($rawToken);
        } catch (AuthenticationException $e) {
            Response::error($e->getMessage(), statusCode: 401);
        }

        $userId = (int) ($payload['sub'] ?? 0);
        $user   = $this->userRepository->findById($userId);

        if ($user === null || !$user->isActive) {
            Response::error('Usuário não encontrado ou inativo.', statusCode: 401);
        }

        $rbac = $this->roleRepository->findUserRbac($userId);
        $org  = $this->orgRepository->findPrimaryForUser($userId);

        AuthContext::set($user);
        AuthContext::setRbac($rbac['roles'], $rbac['permissions']);
        AuthContext::setOrganization($org?->id);
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
