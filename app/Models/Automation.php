<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Representa uma automação com suas condições e ações já hidratadas.
 * A carga lazy das relações (conditions, actions) é responsabilidade
 * do AutomationRepository — o model só transporta o dado.
 */
final readonly class Automation
{
    /**
     * @param array<array{field: string, operator: string, value: ?string}> $conditions
     * @param array<array{action_type: string, parameters: array<string,mixed>, sort_order: int}> $actions
     */
    public function __construct(
        public int    $id,
        public string $name,
        public string $triggerEvent,
        public string $matchType,    // 'all' | 'any'
        public bool   $isActive,
        public int    $runCount,
        public array  $conditions,
        public array  $actions,
    ) {}

    public static function fromArray(array $row, array $conditions = [], array $actions = []): self
    {
        return new self(
            id:           (int)  $row['id'],
            name:                $row['name'],
            triggerEvent:        $row['trigger_event'],
            matchType:           $row['match_type'],
            isActive:    (bool)  $row['is_active'],
            runCount:    (int)   $row['run_count'],
            conditions:          $conditions,
            actions:             $actions,
        );
    }
}
