<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TicketCreatedEvent;
use App\Http\AuthContext;
use App\Services\Ai\AiAssistantService;
use Throwable;

/**
 * Pede à IA uma sugestão de tipo e prioridade para chamados abertos por clientes
 * (web ou e-mail). Roda depois que a resposta HTTP foi entregue; a equipe vê a
 * sugestão no chamado e decide se aplica.
 */
final class AiTicketClassifier
{
    public function __construct(private readonly AiAssistantService $assistant) {}

    public function onTicketCreated(TicketCreatedEvent $event): void
    {
        if (AuthContext::hasAnyRole(['admin', 'agent', 'supervisor'])) {
            return; // a equipe já define tipo e prioridade ao registrar
        }
        $id = $event->ticket->id;
        register_shutdown_function(function () use ($id): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            ignore_user_abort(true);
            set_time_limit(120);
            try {
                $this->assistant->classifyTicket($id);
            } catch (Throwable $e) {
                error_log('[AiTicketClassifier] ' . $e->getMessage());
            }
        });
    }
}
