<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Connection;
use App\Repositories\Contracts\RoleRepositoryInterface;

final class RoleRepository implements RoleRepositoryInterface
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    /**
     * Carrega roles + permissions do usuário em dois queries indexados.
     * Chamado pelo AuthMiddleware uma única vez por request — resultado
     * cacheado no AuthContext para o restante do ciclo de vida.
     *
     * @return array{ roles: string[], permissions: string[] }
     */
    public function findUserRbac(int $userId): array
    {
        return [
            'roles'       => $this->findRoleSlugsByUserId($userId),
            'permissions' => $this->findPermissionSlugsByUserId($userId),
        ];
    }

    /** @return string[] */
    public function findRoleSlugsByUserId(int $userId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT r.slug
               FROM roles r
         INNER JOIN user_roles ur ON ur.role_id = r.id
              WHERE ur.user_id = :user_id'
        );
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Usa DISTINCT para evitar duplicatas quando o usuário tem múltiplas
     * roles que compartilham a mesma permission.
     *
     * @return string[]
     */
    public function findPermissionSlugsByUserId(int $userId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT DISTINCT p.slug
               FROM permissions p
         INNER JOIN role_permissions rp ON rp.permission_id = p.id
         INNER JOIN user_roles ur       ON ur.role_id = rp.role_id
              WHERE ur.user_id = :user_id'
        );
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}
