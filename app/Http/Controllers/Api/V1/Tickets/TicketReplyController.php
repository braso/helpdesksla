<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tickets;

use App\Exceptions\NotFoundException;
use App\Http\AuthContext;
use App\Http\Response;
use App\Repositories\TicketReplyRepository;
use App\Repositories\TicketRepository;
use App\Services\Ticket\TicketReplyService;
use App\Services\Ticket\TicketService;
use App\Services\Attachment\AttachmentService;
use JsonException;
use Throwable;

final class TicketReplyController
{
    public function __construct(
        private readonly TicketReplyService  $replyService,
        private readonly TicketRepository    $ticketRepository,
        private readonly TicketReplyRepository $replyRepository,
        private readonly TicketService       $ticketService,
        private readonly AttachmentService   $attachmentService,
    ) {}

    /** GET /api/v1/tickets/{id}/replies */
    public function index(int $ticketId): never
    {
        // Valida existência e escopo (cliente só vê tickets da própria empresa).
        // NotFound/Authorization são convertidos em 404/403 pelo Router.
        $this->ticketService->getTicket($ticketId);

        $includePrivate = AuthContext::hasAnyRole(['admin', 'agent', 'supervisor']);
        $replies        = $this->replyRepository->findByTicketId($ticketId, $includePrivate);

        // Anexos agrupados por resposta (os de notas internas só chegam à equipe).
        $byReply = [];
        foreach ($this->attachmentService->findByTicket($ticketId, $includePrivate) as $a) {
            if ($a['reply_id'] !== null) {
                $byReply[(int) $a['reply_id']][] = $a;
            }
        }

        Response::success(data: [
            'ticket_id' => $ticketId,
            'items'     => array_map(static fn($r) => $r->toArray() + ['attachments' => $byReply[$r->id] ?? []], $replies),
            'total'     => count($replies),
        ]);
    }

    /**
     * POST /api/v1/tickets/{id}/replies
     *
     * Body:
     *   body       string  required  Conteúdo da resposta (HTML/Markdown)
     *   type       string  optional  'reply' (padrão) | 'note' (nota interna)
     *   is_private bool    optional  true para notas visíveis apenas a agentes
     *   source     string  optional  'web' (padrão) | 'email' | 'api' | 'whatsapp'
     *
     * Responses:
     *   201  — reply criada
     *   400  — JSON inválido
     *   404  — ticket não encontrado
     *   422  — campos inválidos
     *   403  — ticket fechado
     *   500  — erro interno
     */
    public function store(int $ticketId): never
    {
        // Aceita JSON ou multipart/form-data (quando há anexos).
        $isMultipart = str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data');
        if ($isMultipart) {
            $data = $_POST;
            $data['is_private'] = filter_var($data['is_private'] ?? false, FILTER_VALIDATE_BOOLEAN);
        } else {
            $rawBody = (string) file_get_contents('php://input');
            if ($rawBody === '') {
                Response::error('O corpo da requisição não pode estar vazio.', statusCode: 400);
            }
            try {
                $data = json_decode($rawBody, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                Response::error('JSON inválido: ' . $e->getMessage(), statusCode: 400);
            }
            if (!is_array($data)) {
                Response::error('O corpo da requisição deve ser um objeto JSON.', statusCode: 400);
            }
        }

        $this->ticketService->getTicket($ticketId);

        // Valida TODOS os anexos antes de gravar a resposta (nada fica pela metade).
        $files = [];
        if ($isMultipart && !empty($_FILES['attachments'])) {
            foreach (AttachmentService::normalizeFiles($_FILES['attachments']) as $f) {
                if ((int) $f['error'] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $this->attachmentService->validateUpload($f);
                $files[] = $f;
            }
            if (count($files) > 10) {
                Response::error('Envie no máximo 10 arquivos por resposta.', statusCode: 422);
            }
        }
        $data['_has_attachments'] = $files !== [];

        $reply = $this->replyService->addReply($ticketId, $data);

        $saved = [];
        foreach ($files as $f) {
            $saved[] = $this->attachmentService->processUpload($f, 'reply', $reply->id, AuthContext::userId());
        }

        Response::success(
            data:       $reply->toArray() + ['attachments' => $saved],
            message:    'Resposta adicionada com sucesso.',
            statusCode: 201
        );
    }

}
