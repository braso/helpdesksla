<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Ticket;

/** Disparado pelo TicketService após a criação bem-sucedida de um ticket. */
final readonly class TicketCreatedEvent
{
    public function __construct(
        public Ticket $ticket,
        public int    $actorId,
    ) {}
}
