<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ReplyAddedEvent;
use App\Events\TicketCreatedEvent;
use App\Events\TicketStatusChangedEvent;
use App\Services\Automation\AutomationEngine;

/**
 * Aciona o AutomationEngine em resposta a eventos de ticket.
 *
 * Registrado no EventDispatcher durante o bootstrap.
 * Executado após AuditTicketHistory (ordem de registro define a ordem de execução).
 */
final class RunAutomations
{
    public function __construct(
        private readonly AutomationEngine $engine,
    ) {}

    public function onTicketCreated(TicketCreatedEvent $event): void
    {
        $this->engine->run('ticket_created', $event->ticket);
    }

    public function onReplyAdded(ReplyAddedEvent $event): void
    {
        $this->engine->run('reply_added', $event->ticket);
    }

    public function onStatusChanged(TicketStatusChangedEvent $event): void
    {
        $this->engine->run('status_changed', $event->ticket);
    }
}
