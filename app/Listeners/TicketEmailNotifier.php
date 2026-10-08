<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ReplyAddedEvent;
use App\Events\TicketAssignedEvent;
use App\Events\TicketCreatedEvent;
use App\Events\TicketStatusChangedEvent;
use App\Models\Ticket;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\Notification\NotificationService;
use App\Services\Notification\EmailTemplateService;
use App\Support\HtmlSanitizer;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Avisa por e-mail (e no sino do sistema) todos os interessados quando um chamado muda.
 *
 * Regras:
 *  - Abertura: confirmação ao solicitante + aviso à equipe (admin/agente/supervisor).
 *  - Resposta pública: solicitante + responsável; sem responsável, a equipe inteira
 *    recebe a resposta do cliente (ninguém fica sem saber).
 *  - Nota interna: só o responsável (nunca o cliente).
 *  - Atribuição: o agente que recebeu o chamado.
 *  - Resolvido / encerrado: o solicitante.
 *  - Quem fez a ação nunca é notificado.
 *
 * O texto de cada e-mail vem dos modelos editáveis (EmailTemplateService).
 * Os e-mails entram na fila de saída (email_outbox): saem logo depois da resposta
 * HTTP e, se o servidor de e-mail falhar, o cron (SendQueuedEmailsJob) tenta de novo.
 */
final class TicketEmailNotifier
{
    /** @var array<int, ?User> */
    private array $users = [];

    public function __construct(
        private readonly UserRepository      $userRepository,
        private readonly NotificationService  $notificationService,
        private readonly EmailTemplateService $templates,
        private readonly string              $displayTimezone = 'America/Sao_Paulo',
    ) {}

    // ── Eventos ───────────────────────────────────────────────────────────────

    public function onTicketCreated(TicketCreatedEvent $event): void
    {
        $t = $event->ticket;
        $quote = ['text' => $t->description];

        if ($requester = $this->user($t->requesterId)) {
            $this->mail($requester, 'ticket.created', 'ticket.created.requester', $t, $event->actorId,
                ['Chamado' => $t->ticketNumber, 'Assunto' => $t->subject, 'Urgência' => $this->prio($t->priority)], $quote, true);
        }

        foreach ($this->userRepository->findActiveStaff() as $staff) {
            if ($staff->id === $event->actorId || $staff->id === $t->requesterId) {
                continue;
            }
            $this->mail($staff, 'ticket.created', 'ticket.created.staff', $t, $event->actorId, [
                'Chamado' => $t->ticketNumber, 'Empresa' => $t->organizationName, 'Prioridade' => $this->prio($t->priority),
                '1ª resposta até' => $this->fmt($t->slaFrtDueAt), 'Responsável' => $t->agentName ?? 'Sem responsável',
            ], $quote);
        }
    }

    public function onReplyAdded(ReplyAddedEvent $event): void
    {
        $t = $event->ticket;
        $reply = $event->reply;
        $author = $this->user($event->actorId);
        $authorIsRequester = $event->actorId === $t->requesterId;

        if ($reply->isPrivate) {
            // Nota interna: só interessa ao responsável (se não foi ele quem escreveu).
            $ids = $t->assignedAgentId ? [$t->assignedAgentId] : [];
        } else {
            $ids = array_filter([$t->requesterId, $t->assignedAgentId]);
            if (!$t->assignedAgentId && $authorIsRequester) {
                // Sem responsável: a resposta do cliente vai para toda a equipe.
                $ids = array_merge($ids, array_map(static fn(User $u) => $u->id, $this->userRepository->findActiveStaff()));
            }
        }

        $quote = [
            'author' => $author ? $this->name($author) : 'Alguém',
            'text'   => ($reply->metadata['format'] ?? '') === 'html' ? HtmlSanitizer::toText($reply->body) : $reply->body,
        ];
        foreach (array_unique($ids) as $id) {
            if ($id === $event->actorId || !($u = $this->user($id))) {
                continue;
            }
            $toRequester = $id === $t->requesterId;
            $template = $reply->isPrivate ? 'ticket.note' : ($toRequester ? 'ticket.reply.requester' : 'ticket.reply.staff');
            $details = $toRequester
                ? ['Chamado' => $t->ticketNumber, 'Assunto' => $t->subject, 'Situação' => $this->statusClient($t->status)]
                : ['Chamado' => $t->ticketNumber, 'Empresa' => $t->organizationName, 'Status' => $this->status($t->status), 'Responsável' => $t->agentName ?? 'Sem responsável'];
            $this->mail($u, $reply->isPrivate ? 'ticket.note' : 'ticket.reply', $template, $t, $event->actorId, $details, $quote, $toRequester);
        }
    }

    public function onAssigned(TicketAssignedEvent $event): void
    {
        $t = $event->ticket;
        if (!$t->assignedAgentId || $t->assignedAgentId === $event->actorId || !($agent = $this->user($t->assignedAgentId))) {
            return;
        }
        $this->mail($agent, 'ticket.assigned', 'ticket.assigned', $t, $event->actorId, [
            'Chamado' => $t->ticketNumber, 'Empresa' => $t->organizationName, 'Solicitante' => $t->requesterName,
            'Prioridade' => $this->prio($t->priority), 'Status' => $this->status($t->status),
            '1ª resposta até' => $t->firstResponseAt ? null : $this->fmt($t->slaFrtDueAt),
            'Resolução até' => $this->fmt($t->slaRtDueAt),
        ], ['text' => $t->description]);
    }

    public function onStatusChanged(TicketStatusChangedEvent $event): void
    {
        $t = $event->ticket;
        if ($event->actorId === $t->requesterId || !($requester = $this->user($t->requesterId))) {
            return;
        }
        if ($event->newStatus === 'resolved') {
            $this->mail($requester, 'ticket.resolved', 'ticket.resolved', $t, $event->actorId,
                ['Chamado' => $t->ticketNumber, 'Assunto' => $t->subject, 'Atendido por' => $t->agentName], null, true);
        } elseif ($event->newStatus === 'closed') {
            $this->mail($requester, 'ticket.closed', 'ticket.closed', $t, $event->actorId,
                ['Chamado' => $t->ticketNumber, 'Assunto' => $t->subject], null, true);
        }
    }

    // ── Envio ─────────────────────────────────────────────────────────────────

    /**
     * @param array<string, ?string> $details  linhas do bloco {{detalhes}}
     * @param array{author?: ?string, text?: ?string}|null $quote  bloco {{mensagem}}
     */
    private function mail(User $to, string $type, string $template, Ticket $t, ?int $actorId, array $details, ?array $quote = null, bool $toRequester = false): void
    {
        if (!$to->isActive || $to->email === '') {
            return;
        }
        $actor = $actorId ? $this->user($actorId) : null;
        $mail = $this->templates->render($template, [
            'usuario.nome'            => $this->name($to),
            'usuario.email'           => $to->email,
            'autor.nome'              => $actor ? $this->name($actor) : 'A equipe',
            'chamado.numero'          => $t->ticketNumber,
            'chamado.assunto'         => $t->subject,
            'chamado.status'          => $toRequester ? $this->statusClient($t->status) : $this->status($t->status),
            'chamado.prioridade'      => $this->prio($t->priority),
            'chamado.empresa'         => $t->organizationName,
            'chamado.solicitante'     => $t->requesterName,
            'chamado.responsavel'     => $t->agentName ?? 'Sem responsável',
            'chamado.link'            => $this->templates->ticketUrl($t->id),
            'chamado.prazo_resposta'  => $this->fmt($t->slaFrtDueAt) ?? 'a definir pela equipe',
            'chamado.prazo_resolucao' => $this->fmt($t->slaRtDueAt),
        ], $details, $quote);

        $context = ['ticket_id' => $t->id, 'ticket_number' => $t->ticketNumber, 'subject' => $t->subject]
                 + (!empty($quote['author']) ? ['author_name' => $quote['author']] : []);

        // Aviso no sino do sistema (imediato) + e-mail pela fila de saída.
        try { $this->notificationService->notifyInternal($to->id, $type, $context); } catch (Throwable) {}
        $this->notificationService->queueEmail($to->id, $to->email, $this->name($to), $type, $mail['subject'], $mail['html'], $context);
    }

    // ── Ajudantes ─────────────────────────────────────────────────────────────

    private function user(int $id): ?User
    {
        if (!array_key_exists($id, $this->users)) {
            try { $this->users[$id] = $this->userRepository->findById($id); } catch (Throwable) { $this->users[$id] = null; }
        }
        return $this->users[$id];
    }

    private function name(User $u): string
    {
        $full = trim("{$u->firstName} {$u->lastName}");
        return $full !== '' ? $full : $u->name;
    }

    private function fmt(?string $utc): ?string
    {
        if (!$utc) {
            return null;
        }
        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone($this->displayTimezone))->format('d/m/Y \à\s H:i');
        } catch (Throwable) {
            return null;
        }
    }

    private function prio(string $p): string { return ['low' => 'Baixa', 'medium' => 'Média', 'high' => 'Alta', 'critical' => 'Crítica'][$p] ?? $p; }
    private function status(string $s): string { return ['open' => 'Aberto', 'pending' => 'Pendente', 'in_progress' => 'Em andamento', 'resolved' => 'Resolvido', 'closed' => 'Fechado'][$s] ?? $s; }
    private function statusClient(string $s): string { return ['open' => 'Recebido', 'pending' => 'Em atendimento', 'in_progress' => 'Em atendimento', 'resolved' => 'Resolvido', 'closed' => 'Encerrado'][$s] ?? $s; }
}
