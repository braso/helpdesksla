<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Models\Automation;
use App\Models\Ticket;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Avalia se as condições de uma automação são satisfeitas pelo ticket atual.
 *
 * Cada condição testa um campo do ticket contra um valor com um operador.
 * match_type='all' exige que TODAS passem (AND).
 * match_type='any' exige que pelo menos UMA passe (OR).
 */
final class ConditionEvaluator
{
    /**
     * @param Automation $automation Automação com condições já hidratadas
     * @param Ticket     $ticket     Ticket no estado atual (pós-evento)
     */
    public function evaluate(Automation $automation, Ticket $ticket): bool
    {
        if (empty($automation->conditions)) {
            return true; // Automação sem condições sempre executa
        }

        $results = array_map(
            fn(array $condition) => $this->evaluateOne($condition, $ticket),
            $automation->conditions
        );

        return $automation->matchType === 'all'
            ? !in_array(false, $results, strict: true)   // AND — nenhum falso
            : in_array(true,  $results, strict: true);   // OR  — ao menos um verdadeiro
    }

    private function evaluateOne(array $condition, Ticket $ticket): bool
    {
        $ticketValue = $this->extractField($ticket, $condition['field']);
        $operator    = $condition['operator'];
        $testValue   = $condition['value'] ?? null;

        return match ($operator) {
            'equals'       => $ticketValue === $testValue,
            'not_equals'   => $ticketValue !== $testValue,
            'contains'     => str_contains((string) $ticketValue, (string) $testValue),
            'not_contains' => !str_contains((string) $ticketValue, (string) $testValue),
            'greater_than' => is_numeric($ticketValue) && (float) $ticketValue > (float) $testValue,
            'less_than'    => is_numeric($ticketValue) && (float) $ticketValue < (float) $testValue,
            'is_set'       => $ticketValue !== null && $ticketValue !== '',
            'is_not_set'   => $ticketValue === null  || $ticketValue === '',
            default        => false,
        };
    }

    /**
     * Extrai o valor de um campo do ticket para comparação.
     * Campos temporais retornam horas decorridas (float) para comparações ">", "<".
     */
    private function extractField(Ticket $ticket, string $field): mixed
    {
        return match ($field) {
            'status'              => $ticket->status,
            'priority'            => $ticket->priority,
            'source'              => $ticket->source,
            'type'                => $ticket->type,
            'category_id'         => (string) $ticket->categoryId,
            'assigned_agent_id'   => (string) $ticket->assignedAgentId,
            'team_id'             => (string) $ticket->teamId,
            'organization_id'     => (string) $ticket->organizationId,
            // Campos temporais: retornam horas decorridas para operadores >, <
            'hours_since_created' => $this->hoursSince($ticket->createdAt),
            'hours_since_updated' => $this->hoursSince($ticket->updatedAt),
            'hours_since_replied' => $ticket->firstResponseAt
                ? $this->hoursSince($ticket->firstResponseAt)
                : null,
            default => null,
        };
    }

    private function hoursSince(string $datetime): float
    {
        $then = new DateTimeImmutable($datetime, new DateTimeZone('UTC'));
        $now  = new DateTimeImmutable('now',     new DateTimeZone('UTC'));

        return ($now->getTimestamp() - $then->getTimestamp()) / 3600.0;
    }
}
