<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Connection;
use App\Models\Automation;

final class AutomationRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    /**
     * Retorna todas as automações ativas para um trigger, com conditions e actions hidratadas.
     * Ordenadas por sort_order para garantir execução determinística.
     *
     * @return Automation[]
     */
    public function findActiveByTrigger(string $triggerEvent): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM automations
              WHERE trigger_event = :trigger AND is_active = 1
              ORDER BY sort_order ASC'
        );
        $stmt->execute([':trigger' => $triggerEvent]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            return [];
        }

        $automationIds = array_column($rows, 'id');

        $conditions = $this->loadConditions($automationIds);
        $actions    = $this->loadActions($automationIds);

        return array_map(
            static fn(array $row) => Automation::fromArray(
                $row,
                $conditions[$row['id']] ?? [],
                $actions[$row['id']]    ?? [],
            ),
            $rows
        );
    }

    public function incrementRunCount(int $id): void
    {
        $this->connection->pdo()
            ->prepare('UPDATE automations SET run_count = run_count + 1 WHERE id = :id')
            ->execute([':id' => $id]);
    }

    /**
     * @param  int[]  $automationIds
     * @return array<int, array<array{field: string, operator: string, value: ?string}>>
     */
    private function loadConditions(array $automationIds): array
    {
        $placeholders = implode(',', array_fill(0, count($automationIds), '?'));
        $stmt = $this->connection->pdo()->prepare(
            "SELECT * FROM automation_conditions
              WHERE automation_id IN ({$placeholders})
              ORDER BY id ASC"
        );
        $stmt->execute($automationIds);

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[(int) $row['automation_id']][] = [
                'field'    => $row['field'],
                'operator' => $row['operator'],
                'value'    => $row['value'],
            ];
        }

        return $grouped;
    }

    /**
     * @param  int[]  $automationIds
     * @return array<int, array<array{action_type: string, parameters: array, sort_order: int}>>
     */
    private function loadActions(array $automationIds): array
    {
        $placeholders = implode(',', array_fill(0, count($automationIds), '?'));
        $stmt = $this->connection->pdo()->prepare(
            "SELECT * FROM automation_actions
              WHERE automation_id IN ({$placeholders})
              ORDER BY sort_order ASC"
        );
        $stmt->execute($automationIds);

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[(int) $row['automation_id']][] = [
                'action_type' => $row['action_type'],
                'parameters'  => json_decode($row['parameters'], associative: true) ?? [],
                'sort_order'  => (int) $row['sort_order'],
            ];
        }

        return $grouped;
    }
}
