<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Ticket;
use App\Models\TicketReply;

/** Disparado pelo TicketReplyService após inserção de reply ou nota interna. */
final readonly class ReplyAddedEvent
{
    public function __construct(
        public Ticket      $ticket,
        public TicketReply $reply,
        public int         $actorId,
    ) {}
}
