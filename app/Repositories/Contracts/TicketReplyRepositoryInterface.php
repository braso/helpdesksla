<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\TicketReply;

interface TicketReplyRepositoryInterface
{
    public function create(array $data): int;

    public function findById(int $id): ?TicketReply;

    /**
     * @param  bool $includePrivate false para clientes, true para agentes/admins
     * @return TicketReply[]
     */
    public function findByTicketId(int $ticketId, bool $includePrivate = false): array;
}
