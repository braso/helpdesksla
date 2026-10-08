<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Connection;
use App\Models\Organization;

final class OrganizationRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    public function findById(int $id): ?Organization
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM organizations WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? Organization::fromArray($row) : null;
    }

    /** @return Organization[] */
    public function findAll(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT * FROM organizations WHERE deleted_at IS NULL ORDER BY name ASC'
        );
        return array_map(
            static fn(array $row) => Organization::fromArray($row),
            $stmt->fetchAll()
        );
    }

    /** Lista pública: apenas id + name (para formulário de cadastro). */
    public function findAllPublic(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT id, name FROM organizations WHERE deleted_at IS NULL AND is_active = 1 ORDER BY name ASC'
        );
        return $stmt->fetchAll();
    }

    /** Retorna a organização primária do usuário, ou null. */
    public function findPrimaryForUser(int $userId): ?Organization
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT o.* FROM organizations o
              JOIN user_organizations uo ON uo.organization_id = o.id
             WHERE uo.user_id = :uid AND o.deleted_at IS NULL
             ORDER BY uo.is_primary DESC, o.id ASC
             LIMIT 1'
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch();
        return $row !== false ? Organization::fromArray($row) : null;
    }

    public function create(array $data): int
    {
        $this->connection->pdo()
            ->prepare(
                'INSERT INTO organizations (uuid, name, domain, is_active, created_at, updated_at)
                 VALUES (:uuid, :name, :domain, 1, :created_at, :updated_at)'
            )
            ->execute([
                ':uuid'       => $data['uuid'],
                ':name'       => $data['name'],
                ':domain'     => $data['domain'] ?? null,
                ':created_at' => $data['created_at'],
                ':updated_at' => $data['updated_at'],
            ]);
        return (int) $this->connection->pdo()->lastInsertId();
    }

    /** Retorna a política de SLA da empresa, ou null se não houver. */
    public function findSlaByOrg(int $orgId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT sp.* FROM sla_policies sp
               JOIN organizations o ON o.sla_policy_id = sp.id
              WHERE o.id = :oid AND o.deleted_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([':oid' => $orgId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** Cria ou atualiza a política de SLA da empresa. */
    public function saveSla(int $orgId, array $d): void
    {
        $pdo = $this->connection->pdo();
        $org = $this->findById($orgId);
        if ($org === null) {
            return;
        }

        $now  = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $name = trim((string) ($d['name'] ?? 'SLA ' . $org->name));

        if ($org->slaPolicyId !== null) {
            $pdo->prepare(
                'UPDATE sla_policies SET
                   name=:name,
                   frt_low=:frt_low, frt_medium=:frt_medium, frt_high=:frt_high, frt_critical=:frt_critical,
                   rt_low=:rt_low, rt_medium=:rt_medium, rt_high=:rt_high, rt_critical=:rt_critical,
                   business_hours_only=:bh, updated_at=:upd
                 WHERE id=:id'
            )->execute([
                ':name'         => $name,
                ':frt_low'      => max(1, (int) ($d['frt_low']      ?? 480)),
                ':frt_medium'   => max(1, (int) ($d['frt_medium']   ?? 240)),
                ':frt_high'     => max(1, (int) ($d['frt_high']     ?? 60)),
                ':frt_critical' => max(1, (int) ($d['frt_critical'] ?? 15)),
                ':rt_low'       => max(1, (int) ($d['rt_low']       ?? 2880)),
                ':rt_medium'    => max(1, (int) ($d['rt_medium']    ?? 1440)),
                ':rt_high'      => max(1, (int) ($d['rt_high']      ?? 480)),
                ':rt_critical'  => max(1, (int) ($d['rt_critical']  ?? 240)),
                ':bh'           => (int) ($d['business_hours_only'] ?? 1),
                ':upd'          => $now,
                ':id'           => $org->slaPolicyId,
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO sla_policies
                   (name, frt_low, frt_medium, frt_high, frt_critical, rt_low, rt_medium, rt_high, rt_critical, business_hours_only, created_at, updated_at)
                 VALUES
                   (:name, :frt_low, :frt_medium, :frt_high, :frt_critical, :rt_low, :rt_medium, :rt_high, :rt_critical, :bh, :crt, :upd)'
            )->execute([
                ':name'         => $name,
                ':frt_low'      => max(1, (int) ($d['frt_low']      ?? 480)),
                ':frt_medium'   => max(1, (int) ($d['frt_medium']   ?? 240)),
                ':frt_high'     => max(1, (int) ($d['frt_high']     ?? 60)),
                ':frt_critical' => max(1, (int) ($d['frt_critical'] ?? 15)),
                ':rt_low'       => max(1, (int) ($d['rt_low']       ?? 2880)),
                ':rt_medium'    => max(1, (int) ($d['rt_medium']    ?? 1440)),
                ':rt_high'      => max(1, (int) ($d['rt_high']      ?? 480)),
                ':rt_critical'  => max(1, (int) ($d['rt_critical']  ?? 240)),
                ':bh'           => (int) ($d['business_hours_only'] ?? 1),
                ':crt'          => $now,
                ':upd'          => $now,
            ]);
            $slaId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE organizations SET sla_policy_id=:sid, updated_at=:upd WHERE id=:id')
                ->execute([':sid' => $slaId, ':upd' => $now, ':id' => $orgId]);
        }
    }

    public function update(int $id, array $data): void
    {
        $allowed = [
            'name', 'trade_name', 'cnpj', 'ie', 'im', 'phone', 'email', 'website',
            'domain', 'is_active', 'notes',
            'address_zip', 'address_street', 'address_number', 'address_complement',
            'address_neighborhood', 'address_city', 'address_state', 'address_country',
        ];

        $setClauses = [];
        $params     = [':id' => $id];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $setClauses[]        = "`{$field}` = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (empty($setClauses)) {
            return;
        }

        $sql = 'UPDATE organizations SET ' . implode(', ', $setClauses) . ', updated_at = UTC_TIMESTAMP() WHERE id = :id';
        $this->connection->pdo()->prepare($sql)->execute($params);
    }

    public function linkUser(int $userId, int $orgId, bool $isPrimary = true): void
    {
        $this->connection->pdo()
            ->prepare(
                'INSERT IGNORE INTO user_organizations (user_id, organization_id, is_primary)
                 VALUES (:uid, :oid, :primary)'
            )
            ->execute([':uid' => $userId, ':oid' => $orgId, ':primary' => (int) $isPrimary]);
    }
}
