<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Ticket;

/** Disparado pelo TicketService quando um ticket recebe (ou troca de) responsável. */
final readonly class TicketAssignedEvent
{
    public function __construct(
        public Ticket $ticket,
        public ?int   $previousAgentId,
        public int    $actorId,
    ) {}
}
