<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Connection;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;

final class UserRepository implements UserRepositoryInterface
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    public function findById(int $id): ?User
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();

        return $row !== false ? User::fromArray($row) : null;
    }

    public function findByEmail(string $email): ?User
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM users WHERE email = :email AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([':email' => $email]);

        $row = $stmt->fetch();

        return $row !== false ? User::fromArray($row) : null;
    }

    public function touchLogin(int $userId, string $ipAddress): void
    {
        $this->connection->pdo()
            ->prepare(
                'UPDATE users
                    SET last_login_at = UTC_TIMESTAMP(), last_login_ip = :ip
                  WHERE id = :id'
            )
            ->execute([':ip' => $ipAddress, ':id' => $userId]);
    }

    public function findAllWithOrg(array $filters = []): array
    {
        $where  = ['u.deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['organization_id'])) {
            $where[]                   = 'uo.organization_id = :org_id';
            $params[':org_id']         = (int) $filters['organization_id'];
        }

        if (!empty($filters['search'])) {
            $where[]            = '(LOWER(u.name) LIKE :s OR LOWER(u.email) LIKE :s)';
            $params[':s']       = '%' . mb_strtolower((string) $filters['search']) . '%';
        }

        $whereClause = implode(' AND ', $where);

        $sql = "SELECT u.id, u.uuid, u.first_name, u.last_name, u.name, u.email,
                       u.is_active, u.created_at,
                       o.id   AS org_id,
                       o.name AS org_name,
                       r.slug AS role_slug,
                       r.name AS role_name
                  FROM users u
                  LEFT JOIN user_organizations uo ON uo.user_id = u.id AND uo.is_primary = 1
                  LEFT JOIN organizations o        ON o.id = uo.organization_id AND o.deleted_at IS NULL
                  LEFT JOIN user_roles ur           ON ur.user_id = u.id
                  LEFT JOIN roles r                 ON r.id = ur.role_id
                 WHERE {$whereClause}
                 ORDER BY u.created_at DESC";

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function findWithDetails(int $id): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT u.id, u.uuid, u.first_name, u.last_name, u.name, u.email,
                    u.phone, u.phone_mobile, u.position, u.department,
                    u.is_active, u.created_at, u.updated_at,
                    o.id   AS org_id,
                    o.name AS org_name,
                    r.id   AS role_id,
                    r.slug AS role_slug,
                    r.name AS role_name
               FROM users u
               LEFT JOIN user_organizations uo ON uo.user_id = u.id AND uo.is_primary = 1
               LEFT JOIN organizations o        ON o.id = uo.organization_id AND o.deleted_at IS NULL
               LEFT JOIN user_roles ur           ON ur.user_id = u.id
               LEFT JOIN roles r                 ON r.id = ur.role_id
              WHERE u.id = :id AND u.deleted_at IS NULL
              LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function update(int $id, array $data): void
    {
        $allowed = ['first_name', 'last_name', 'name', 'email', 'phone', 'phone_mobile',
                    'position', 'department', 'is_active', 'password'];

        $setClauses = [];
        $params     = [':id' => $id];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $setClauses[]      = "`{$field}` = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (empty($setClauses)) {
            return;
        }

        $sql = 'UPDATE users SET ' . implode(', ', $setClauses) . ', updated_at = UTC_TIMESTAMP() WHERE id = :id';
        $this->connection->pdo()->prepare($sql)->execute($params);
    }

    public function updateRole(int $userId, string $roleSlug): void
    {
        $pdo  = $this->connection->pdo();
        $stmt = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
        $stmt->execute([':slug' => $roleSlug]);
        $role = $stmt->fetch();

        if ($role === false) {
            return;
        }

        $pdo->prepare('DELETE FROM user_roles WHERE user_id = :uid')->execute([':uid' => $userId]);
        $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:uid, :rid)')
            ->execute([':uid' => $userId, ':rid' => (int) $role['id']]);
    }

    public function updatePrimaryOrg(int $userId, ?int $orgId): void
    {
        $pdo = $this->connection->pdo();
        $pdo->prepare('DELETE FROM user_organizations WHERE user_id = :uid')->execute([':uid' => $userId]);

        if ($orgId !== null) {
            $pdo->prepare('INSERT INTO user_organizations (user_id, organization_id, is_primary) VALUES (:uid, :oid, 1)')
                ->execute([':uid' => $userId, ':oid' => $orgId]);
        }
    }

    public function getUserRole(int $userId): ?string
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT r.slug FROM roles r
              JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = :uid
             LIMIT 1'
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch();
        return $row !== false ? $row['slug'] : null;
    }

    public function countActiveAdmins(int $excludeUserId = 0): int
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT COUNT(*) FROM users u
              JOIN user_roles ur ON ur.user_id = u.id
              JOIN roles r       ON r.id = ur.role_id
             WHERE r.slug = 'admin' AND u.is_active = 1 AND u.deleted_at IS NULL AND u.id != :excl"
        );
        $stmt->execute([':excl' => $excludeUserId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Equipe ativa que atende chamados (admin, agente, supervisor).
     *
     * @return User[]
     */
    public function findActiveStaff(): array
    {
        $stmt = $this->connection->pdo()->query(
            "SELECT DISTINCT u.* FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r       ON r.id = ur.role_id
              WHERE r.slug IN ('admin','agent','supervisor')
                AND u.is_active = 1 AND u.deleted_at IS NULL
              ORDER BY u.id"
        );
        return array_map(static fn(array $row) => User::fromArray($row), $stmt->fetchAll());
    }

    public function softDelete(int $userId): void
    {
        $this->connection->pdo()
            ->prepare('UPDATE users SET deleted_at = UTC_TIMESTAMP() WHERE id = :id')
            ->execute([':id' => $userId]);
    }
}
