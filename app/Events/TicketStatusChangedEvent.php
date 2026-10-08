<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Ticket;

/** Disparado pelo TicketService sempre que o status de um ticket muda. */
final readonly class TicketStatusChangedEvent
{
    public function __construct(
        public Ticket $ticket,
        public string $oldStatus,
        public string $newStatus,
        public int    $actorId,
    ) {}
}
