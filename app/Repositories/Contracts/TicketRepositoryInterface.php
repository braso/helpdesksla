<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Ticket;

interface TicketRepositoryInterface
{
    /** @param array<string, mixed> $data */
    public function create(array $data): int;

    public function findById(int $id): ?Ticket;

    public function findByUuid(string $uuid): ?Ticket;

    /**
     * Lista tickets com filtros opcionais e paginação.
     *
     * @param  array<string, mixed> $filters  status, priority, assigned_agent_id, requester_id, search
     * @return array{data: Ticket[], total: int, page: int, per_page: int, last_page: int}
     */
    public function findAll(array $filters = [], int $page = 1, int $perPage = 20): array;

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void;

    /**
     * Conta tickets por escopo de fila (queue|mine|unassigned|done|all).
     *
     * @param  string[] $scopes
     * @return array<string, int>
     */
    public function countByScopes(array $baseFilters, array $scopes, ?int $userId): array;
}
