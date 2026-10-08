<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ReplyAddedEvent;
use App\Events\TicketCreatedEvent;
use App\Events\TicketStatusChangedEvent;
use App\Repositories\TicketHistoryRepository;

/**
 * Escuta todos os eventos de ticket e grava o audit log em ticket_history.
 *
 * Registrado no EventDispatcher durante o bootstrap da aplicação.
 * Não lança exceções — falha silenciosa em auditoria é preferível
 * a deixar uma reply de suporte falhar por causa de um log.
 */
final class AuditTicketHistory
{
    public function __construct(
        private readonly TicketHistoryRepository $historyRepository,
    ) {}

    public function onTicketCreated(TicketCreatedEvent $event): void
    {
        $this->historyRepository->log([
            'ticket_id' => $event->ticket->id,
            'actor_id'  => $event->actorId,
            'event'     => 'created',
            'new_value' => [
                'status'   => $event->ticket->status,
                'priority' => $event->ticket->priority,
            ],
        ]);
    }

    public function onReplyAdded(ReplyAddedEvent $event): void
    {
        $this->historyRepository->log([
            'ticket_id' => $event->ticket->id,
            'actor_id'  => $event->actorId,
            'event'     => $event->reply->isPrivate ? 'note_added' : 'reply_added',
            'new_value' => ['reply_id' => $event->reply->id, 'type' => $event->reply->type],
        ]);
    }

    public function onStatusChanged(TicketStatusChangedEvent $event): void
    {
        $this->historyRepository->log([
            'ticket_id' => $event->ticket->id,
            'actor_id'  => $event->actorId,
            'event'     => 'status_changed',
            'old_value' => ['status' => $event->oldStatus],
            'new_value' => ['status' => $event->newStatus],
        ]);
    }
}
