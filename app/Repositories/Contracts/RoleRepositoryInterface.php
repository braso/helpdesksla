<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

interface RoleRepositoryInterface
{
    /**
     * Retorna todos os slugs de roles e permissions do usuário em uma única chamada.
     *
     * @return array{ roles: string[], permissions: string[] }
     */
    public function findUserRbac(int $userId): array;

    /** @return string[] slugs das roles do usuário */
    public function findRoleSlugsByUserId(int $userId): array;

    /** @return string[] slugs das permissions do usuário (de todas as suas roles) */
    public function findPermissionSlugsByUserId(int $userId): array;
}
