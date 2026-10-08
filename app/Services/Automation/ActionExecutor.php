<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Models\Automation;
use App\Models\Ticket;
use App\Repositories\TicketHistoryRepository;
use App\Repositories\TicketReplyRepository;
use App\Repositories\TicketRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Executa as ações de uma automação sobre um ticket.
 *
 * Cada action_type tem seu próprio handler. Ações desconhecidas ou não
 * implementadas são ignoradas silenciosamente para não quebrar o pipeline.
 *
 * Ações assíncronas (fire_webhook, send_email) são marcadas com TODO —
 * na v1 serão enfileiradas via Jobs quando o Queue Worker for implementado.
 */
final class ActionExecutor
{
    public function __construct(
        private readonly TicketRepository        $ticketRepository,
        private readonly TicketReplyRepository   $replyRepository,
        private readonly TicketHistoryRepository $historyRepository,
    ) {}

    public function execute(Automation $automation, Ticket $ticket): void
    {
        foreach ($automation->actions as $action) {
            $this->dispatch($action['action_type'], $action['parameters'] ?? [], $ticket, $automation->id);
        }
    }

    private function dispatch(string $type, array $params, Ticket $ticket, int $automationId): void
    {
        match ($type) {
            'set_status'  => $this->setStatus($ticket, $params['status']    ?? 'open'),
            'set_priority' => $this->setPriority($ticket, $params['priority'] ?? 'medium'),
            'assign_agent' => $this->assignAgent($ticket, (int) ($params['agent_id'] ?? 0)),
            'assign_team'  => $this->assignTeam($ticket,  (int) ($params['team_id']  ?? 0)),
            'close_ticket' => $this->closeTicket($ticket),
            'add_private_note' => $this->addSystemNote($ticket, (string) ($params['message'] ?? '')),

            // TODO: Implementar quando TagRepository estiver disponível
            'add_tag', 'remove_tag' => null,

            // TODO: Enfileirar WebhookJob / EmailJob quando Queue Worker for implementado
            'fire_webhook', 'send_email' => null,

            default => null,
        };

        $this->logAutomationTrigger($ticket->id, $automationId, $type);
    }

    private function setStatus(Ticket $ticket, string $status): void
    {
        if ($ticket->status === $status) {
            return;
        }

        $updates = ['status' => $status];

        if ($status === 'resolved') {
            $updates['resolved_at'] = $this->nowUtc();
        } elseif ($status === 'closed') {
            $updates['closed_at'] = $this->nowUtc();
        }

        $this->ticketRepository->update($ticket->id, $updates);
    }

    private function setPriority(Ticket $ticket, string $priority): void
    {
        $this->ticketRepository->update($ticket->id, ['priority' => $priority]);
    }

    private function assignAgent(Ticket $ticket, int $agentId): void
    {
        if ($agentId <= 0) {
            return;
        }

        $this->ticketRepository->update($ticket->id, [
            'assigned_agent_id' => $agentId,
            'updated_at'        => $this->nowUtc(),
        ]);
    }

    private function assignTeam(Ticket $ticket, int $teamId): void
    {
        if ($teamId <= 0) {
            return;
        }

        $this->ticketRepository->update($ticket->id, [
            'team_id'    => $teamId,
            'updated_at' => $this->nowUtc(),
        ]);
    }

    private function closeTicket(Ticket $ticket): void
    {
        if (in_array($ticket->status, ['resolved', 'closed'], strict: true)) {
            return;
        }

        $this->ticketRepository->update($ticket->id, [
            'status'     => 'closed',
            'closed_at'  => $this->nowUtc(),
            'updated_at' => $this->nowUtc(),
        ]);
    }

    private function addSystemNote(Ticket $ticket, string $body): void
    {
        if ($body === '') {
            return;
        }

        $now = $this->nowUtc();

        $this->replyRepository->create([
            'uuid'       => $this->generateUuid(),
            'ticket_id'  => $ticket->id,
            'author_id'  => 1, // Convenção: user_id=1 representa o sistema
            'body'       => $body,
            'type'       => 'system',
            'is_private' => true,
            'source'     => 'api',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function logAutomationTrigger(int $ticketId, int $automationId, string $actionType): void
    {
        $this->historyRepository->log([
            'ticket_id' => $ticketId,
            'actor_id'  => null, // Ação do sistema
            'event'     => 'automation_triggered',
            'new_value' => ['automation_id' => $automationId, 'action' => $actionType],
        ]);
    }

    private function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function generateUuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
