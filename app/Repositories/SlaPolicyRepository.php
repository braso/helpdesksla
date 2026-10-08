<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Connection;
use App\Models\SlaPolicy;
use App\Repositories\Contracts\SlaPolicyRepositoryInterface;

final class SlaPolicyRepository implements SlaPolicyRepositoryInterface
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    public function findDefault(): ?SlaPolicy
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM sla_policies WHERE is_default = 1 LIMIT 1'
        );
        $stmt->execute();

        $row = $stmt->fetch();

        return $row !== false ? SlaPolicy::fromArray($row) : null;
    }

    public function findForTicket(?int $organizationId): ?SlaPolicy
    {
        // Organização com política própria tem precedência sobre a padrão do sistema
        if ($organizationId !== null) {
            $stmt = $this->connection->pdo()->prepare(
                'SELECT sp.* FROM sla_policies sp
                 INNER JOIN organizations o ON o.sla_policy_id = sp.id
                 WHERE o.id = :org_id LIMIT 1'
            );
            $stmt->execute([':org_id' => $organizationId]);
            $row = $stmt->fetch();

            if ($row !== false) {
                return SlaPolicy::fromArray($row);
            }
        }

        return $this->findDefault();
    }
}
