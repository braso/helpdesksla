<?php

declare(strict_types=1);

namespace App\Services\Ticket;

use App\Core\EventDispatcher;
use App\Events\ReplyAddedEvent;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Http\AuthContext;
use App\Models\TicketReply;
use App\Repositories\Contracts\TicketReplyRepositoryInterface;
use App\Repositories\TicketRepository;
use App\Support\HtmlSanitizer;
use App\Support\Validator;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class TicketReplyService
{
    private const ALLOWED_TYPES = ['reply', 'note'];

    public function __construct(
        private readonly TicketRepository             $ticketRepository,
        private readonly TicketReplyRepositoryInterface $replyRepository,
        private readonly EventDispatcher              $events,
    ) {}

    /**
     * Adiciona uma resposta ou nota interna a um ticket.
     *
     * Regras de negócio aplicadas:
     *   - Ticket deve existir e não estar fechado
     *   - Primeira resposta não-privada de não-solicitante → registra first_response_at (FRT)
     *   - Resposta de agente em ticket open/pending → muda status para in_progress
     *   - Resposta do solicitante em ticket resolved → reabre como open
     *
     * @throws NotFoundException   se o ticket não existir
     * @throws ValidationException se os dados forem inválidos
     */
    public function addReply(int $ticketId, array $requestData): TicketReply
    {
        $ticket = $this->ticketRepository->findById($ticketId);

        if ($ticket === null) {
            throw new NotFoundException('Ticket');
        }

        if ($ticket->status === 'closed') {
            throw new ValidationException(['ticket' => ['Não é possível responder um ticket fechado.']]);
        }

        // Texto rico: o HTML é limpo no servidor (lista de permissões) antes de gravar.
        $isHtml = ($requestData['format'] ?? 'text') === 'html';
        if ($isHtml) {
            $requestData['body'] = HtmlSanitizer::clean((string) ($requestData['body'] ?? ''));
            if (!HtmlSanitizer::hasText($requestData['body']) && empty($requestData['_has_attachments'])) {
                $requestData['body'] = '';
            }
        }
        if (!empty($requestData['_has_attachments']) && trim(strip_tags((string) ($requestData['body'] ?? ''))) === '') {
            $requestData['body'] = $isHtml ? '<p>(anexo)</p>' : '(anexo)';
        }

        $this->validate($requestData);

        $now       = $this->nowUtc();
        $authorId  = AuthContext::userId();
        $isPrivate = (bool) ($requestData['is_private'] ?? false);
        $type      = $requestData['type'] ?? 'reply';

        // Apenas a equipe pode registrar notas internas ou respostas privadas.
        if (!AuthContext::hasAnyRole(['admin', 'agent', 'supervisor'])) {
            $type      = 'reply';
            $isPrivate = false;
        }

        // Notas internas são sempre privadas independente do campo enviado
        if ($type === 'note') {
            $isPrivate = true;
        }

        $replyData = [
            'uuid'       => $this->generateUuid(),
            'ticket_id'  => $ticketId,
            'author_id'  => $authorId,
            'body'       => trim((string) $requestData['body']),
            'type'       => $type,
            'is_private' => $isPrivate,
            'source'     => $requestData['source'] ?? 'web',
            'metadata'   => $isHtml ? ['format' => 'html'] : null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $replyId = $this->replyRepository->create($replyData);

        // Aplica transições de status e rastreamento de SLA após persistir a reply
        $ticketUpdates = $this->computeTicketUpdates($ticket, $authorId, $isPrivate, $now);

        if (!empty($ticketUpdates)) {
            $this->ticketRepository->update($ticketId, $ticketUpdates);
        }

        $reply = $this->replyRepository->findById($replyId);

        if ($reply === null) {
            throw new RuntimeException("Reply #{$replyId} não encontrada após criação.");
        }

        // Recarrega o ticket com os campos atualizados para o evento
        $updatedTicket = $this->ticketRepository->findById($ticketId) ?? $ticket;

        $this->events->dispatch(new ReplyAddedEvent($updatedTicket, $reply, $authorId));

        return $reply;
    }

    /**
     * Calcula quais campos do ticket devem ser atualizados após a reply.
     *
     * @return array<string, mixed>
     */
    private function computeTicketUpdates(
        \App\Models\Ticket $ticket,
        int   $authorId,
        bool  $isPrivate,
        string $now
    ): array {
        $updates = ['updated_at' => $now];

        if ($isPrivate) {
            return $updates; // Notas privadas não alteram status nem FRT
        }

        $isRequester = ($authorId === $ticket->requesterId);

        // Primeira resposta de não-solicitante → registra FRT para o SLA
        if (!$isRequester && $ticket->firstResponseAt === null) {
            $updates['first_response_at'] = $now;

            if ($ticket->slaFrtDueAt !== null && $now > $ticket->slaFrtDueAt) {
                $updates['sla_frt_breached'] = 1;
            }
        }

        // Transições de status
        if (!$isRequester && in_array($ticket->status, ['open', 'pending'], strict: true)) {
            $updates['status'] = 'in_progress';
        } elseif ($isRequester && $ticket->status === 'resolved') {
            // Cliente respondeu ticket resolvido → reabre para o agente
            $updates['status'] = 'open';
        } elseif ($isRequester && $ticket->status === 'in_progress') {
            // Cliente respondeu → marca como pendente de ação do agente
            $updates['status'] = 'pending';
        }

        return $updates;
    }

    /** @throws ValidationException */
    private function validate(array $data): void
    {
        $validator = (new Validator())
            ->required('body', $data['body'] ?? null)
            ->maxLength('body', (string) ($data['body'] ?? ''), 65535)
            ->enum('type', $data['type'] ?? null, self::ALLOWED_TYPES);

        if (!$validator->passes()) {
            throw new ValidationException($validator->errors());
        }
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
