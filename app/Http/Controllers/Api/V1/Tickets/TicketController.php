<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tickets;

use App\Exceptions\AuthorizationException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Http\AuthContext;
use App\Http\Response;
use App\Services\Attachment\AttachmentService;
use App\Services\Ticket\TicketService;
use JsonException;
use Throwable;

final class TicketController
{
    public function __construct(
        private readonly TicketService $ticketService,
        private readonly ?AttachmentService $attachmentService = null,
    ) {}

    /** GET /api/v1/tickets */
    public function index(): never
    {
        try {
            $result = $this->ticketService->listTickets($_GET);

            Response::success(
                data: [
                    'items'     => array_map(static fn($t) => $t->toArray(), $result['data']),
                    'total'     => $result['total'],
                    'page'      => $result['page'],
                    'per_page'  => $result['per_page'],
                    'last_page' => $result['last_page'],
                    'counts'    => $result['counts'] ?? [],
                ]
            );
        } catch (Throwable $e) {
            $this->logError($e);
            Response::error('Ocorreu um erro interno.', statusCode: 500);
        }
    }

    /** GET /api/v1/tickets/{id} */
    public function show(int $id): never
    {
        try {
            $ticket = $this->ticketService->getTicket($id);
            Response::success(data: $ticket->toArray());
        } catch (NotFoundException $e) {
            Response::error($e->getMessage(), statusCode: 404);
        } catch (AuthorizationException $e) {
            Response::error($e->getMessage(), statusCode: 403);
        } catch (Throwable $e) {
            $this->logError($e);
            Response::error('Ocorreu um erro interno.', statusCode: 500);
        }
    }

    /** PATCH /api/v1/tickets/{id} */
    public function update(int $id): never
    {
        $rawBody = (string) file_get_contents('php://input');

        if ($rawBody === '') {
            Response::error('O corpo da requisição não pode estar vazio.', statusCode: 400);
        }

        try {
            $requestData = json_decode($rawBody, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Response::error('JSON inválido: ' . $e->getMessage(), statusCode: 400);
        }

        if (!is_array($requestData)) {
            Response::error('O corpo da requisição deve ser um objeto JSON.', statusCode: 400);
        }

        try {
            $ticket = $this->ticketService->updateTicket($id, $requestData);
            Response::success(data: $ticket->toArray(), message: 'Ticket atualizado com sucesso.');
        } catch (NotFoundException $e) {
            Response::error($e->getMessage(), statusCode: 404);
        } catch (ValidationException $e) {
            Response::error($e->getMessage(), errors: $e->errors(), statusCode: 422);
        } catch (AuthorizationException $e) {
            Response::error($e->getMessage(), statusCode: 403);
        } catch (Throwable $e) {
            $this->logError($e);
            Response::error('Ocorreu um erro interno.', statusCode: 500);
        }
    }

    /** POST /api/v1/tickets */
    public function store(): never
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $isMultipart = str_contains($contentType, 'multipart/form-data');

        if ($isMultipart) {
            $requestData = $_POST;
        } else {
            $rawBody = (string) file_get_contents('php://input');

            if ($rawBody === '') {
                Response::error('O corpo da requisição não pode estar vazio.', statusCode: 400);
            }

            try {
                $requestData = json_decode($rawBody, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                Response::error('JSON inválido: ' . $e->getMessage(), statusCode: 400);
            }

            if (!is_array($requestData)) {
                Response::error('O corpo da requisição deve ser um objeto JSON.', statusCode: 400);
            }
        }

        $requestData['requester_id'] ??= AuthContext::user()->id;

        try {
            $ticket      = $this->ticketService->openTicket($requestData);
            $attachments = [];

            if ($isMultipart && $this->attachmentService !== null && !empty($_FILES['attachments'])) {
                $files = AttachmentService::normalizeFiles($_FILES['attachments']);
                foreach ($files as $fileInfo) {
                    if ((int) $fileInfo['error'] === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    $attachments[] = $this->attachmentService->processUpload(
                        $fileInfo,
                        'ticket',
                        $ticket->id,
                        (int) AuthContext::user()->id,
                    );
                }
            }

            $responseData = $ticket->toArray();
            if (!empty($attachments)) {
                $responseData['attachments'] = $attachments;
            }

            Response::success(
                data:       $responseData,
                message:    "Ticket {$ticket->ticketNumber} criado com sucesso.",
                statusCode: 201
            );
        } catch (ValidationException $e) {
            Response::error($e->getMessage(), errors: $e->errors(), statusCode: 422);
        } catch (Throwable $e) {
            $this->logError($e);
            Response::error('Ocorreu um erro interno.', statusCode: 500);
        }
    }

    /** POST /api/v1/tickets/{id}/rate */
    public function rate(int $id): never
    {
        $rawBody = (string) file_get_contents('php://input');
        if ($rawBody === '') {
            Response::error('O corpo da requisição não pode estar vazio.', statusCode: 400);
        }

        try {
            $data = json_decode($rawBody, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Response::error('JSON inválido.', statusCode: 400);
        }

        $rating  = isset($data['rating'])  ? (int) $data['rating']        : 0;
        $comment = isset($data['comment']) ? trim((string) $data['comment']) : '';

        try {
            $ticket = $this->ticketService->rateTicket($id, $rating, $comment);
            Response::success(data: $ticket->toArray(), message: 'Obrigado pela sua avaliação!');
        } catch (NotFoundException $e) {
            Response::error($e->getMessage(), statusCode: 404);
        } catch (ValidationException $e) {
            Response::error($e->getMessage(), errors: $e->errors(), statusCode: 422);
        } catch (AuthorizationException $e) {
            Response::error($e->getMessage(), statusCode: 403);
        } catch (Throwable $e) {
            $this->logError($e);
            Response::error('Ocorreu um erro interno.', statusCode: 500);
        }
    }

    private function logError(Throwable $e): void
    {
        error_log(sprintf('[%s] %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    }
}
