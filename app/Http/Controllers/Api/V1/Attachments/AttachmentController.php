<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attachments;

use App\Exceptions\AuthorizationException;
use App\Exceptions\NotFoundException;
use App\Http\AuthContext;
use App\Http\Response;
use App\Services\Attachment\AttachmentService;
use App\Services\Ticket\TicketService;
use Throwable;

final class AttachmentController
{
    public function __construct(
        private readonly AttachmentService $attachmentService,
        private readonly TicketService     $ticketService,
    ) {}

    /** GET /api/v1/tickets/{id}/attachments */
    public function index(int $ticketId): never
    {
        try {
            $this->ticketService->getTicket($ticketId);
            $attachments = $this->attachmentService->findByTicket($ticketId, AuthContext::hasAnyRole(['admin', 'agent', 'supervisor']));
            Response::success(data: $attachments);
        } catch (NotFoundException $e) {
            Response::error($e->getMessage(), statusCode: 404);
        } catch (AuthorizationException $e) {
            Response::error($e->getMessage(), statusCode: 403);
        } catch (Throwable $e) {
            $this->logError($e);
            Response::error('Erro ao listar anexos.', statusCode: 500);
        }
    }

    /** GET /api/v1/attachments/{id}/download */
    public function download(int $id): never
    {
        try {
            $this->assertCanAccess($this->attachmentService->findById($id));
            $this->attachmentService->serveDownload($id);
        } catch (NotFoundException $e) {
            Response::error($e->getMessage(), statusCode: 404);
        } catch (AuthorizationException $e) {
            Response::error($e->getMessage(), statusCode: 403);
        } catch (Throwable $e) {
            $this->logError($e);
            Response::error('Erro ao baixar anexo.', statusCode: 500);
        }
    }

    /** GET /api/v1/attachments/limits — tamanho e formatos aceitos (qualquer usuário autenticado) */
    public function limits(): never
    {
        Response::success(data: $this->attachmentService->limits());
    }

    /** DELETE /api/v1/attachments/{id} */
    public function destroy(int $id): never
    {
        try {
            $attachment = $this->attachmentService->findById($id);

            $this->assertCanAccess($attachment);

            // Permite deleção pelo próprio uploader, agentes, supervisores e admin
            $user  = AuthContext::user();
            $roles = AuthContext::roles();
            $isPrivileged = count(array_intersect($roles, ['admin', 'agent', 'supervisor'])) > 0;

            if (!$isPrivileged && (int) $attachment['uploader_id'] !== (int) $user->id) {
                Response::error('Sem permissão para excluir este anexo.', statusCode: 403);
            }

            $this->attachmentService->delete($id);
            Response::success(data: null, message: 'Anexo excluído com sucesso.');
        } catch (NotFoundException $e) {
            Response::error($e->getMessage(), statusCode: 404);
        } catch (AuthorizationException $e) {
            Response::error($e->getMessage(), statusCode: 403);
        } catch (Throwable $e) {
            $this->logError($e);
            Response::error('Erro ao excluir anexo.', statusCode: 500);
        }
    }

    /**
     * O anexo herda a visibilidade do ticket ao qual pertence.
     *
     * @throws NotFoundException
     * @throws AuthorizationException
     */
    private function assertCanAccess(?array $attachment): void
    {
        if (!$attachment) {
            throw new NotFoundException('Anexo');
        }
        if ($attachment['attachable_type'] === 'ticket') {
            $this->ticketService->getTicket((int) $attachment['attachable_id']);
        } elseif ($attachment['attachable_type'] === 'reply') {
            $reply = $this->attachmentService->replyOwner((int) $attachment['attachable_id']);
            if ($reply === null) {
                throw new NotFoundException('Anexo');
            }
            $this->ticketService->getTicket((int) $reply['ticket_id']);
            if ($reply['is_private'] && !AuthContext::hasAnyRole(['admin', 'agent', 'supervisor'])) {
                throw new AuthorizationException('Acesso negado a este anexo.');
            }
        }
    }

    private function logError(Throwable $e): void
    {
        error_log(sprintf('[%s] %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    }
}
