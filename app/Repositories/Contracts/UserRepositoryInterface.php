<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\User;

interface UserRepositoryInterface
{
    public function findById(int $id): ?User;

    public function findByEmail(string $email): ?User;

    /** Atualiza last_login_at e last_login_ip após autenticação bem-sucedida. */
    public function touchLogin(int $userId, string $ipAddress): void;

    /**
     * Lista usuários com informações de empresa e role.
     * Filtros aceitos: organization_id, search (nome/email).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllWithOrg(array $filters = []): array;

    /** Retorna dados completos de um usuário (com role e org). */
    public function findWithDetails(int $id): ?array;

    /** Atualiza campos do usuário. */
    public function update(int $id, array $data): void;

    /** Altera o papel (role) do usuário. */
    public function updateRole(int $userId, string $roleSlug): void;

    /** Atualiza a organização primária do usuário. */
    public function updatePrimaryOrg(int $userId, ?int $orgId): void;

    /** Retorna o slug do papel atual do usuário, ou null se não tiver. */
    public function getUserRole(int $userId): ?string;

    /**
     * Conta administradores ativos, excluindo o usuário informado.
     * Usado para proteger o último admin.
     */
    public function countActiveAdmins(int $excludeUserId = 0): int;

    /** Soft-delete do usuário. */
    public function softDelete(int $userId): void;
}
