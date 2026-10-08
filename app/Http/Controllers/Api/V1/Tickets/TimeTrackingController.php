<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tickets;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Http\Response;
use App\Services\Ticket\TimeTrackingService;
use Throwable;

final class TimeTrackingController
{
    public function __construct(
        private readonly TimeTrackingService $timeService,
    ) {}

    /** POST /api/v1/tickets/{id}/time/start */
    public function start(int $ticketId): never
    {
        try {
            $result = $this->timeService->start($ticketId);
            Response::success(data: $result, message: 'Temporizador iniciado.');
        } catch (NotFoundException $e) {
            Response::error($e->getMessage(), statusCode: 404);
        } catch (ValidationException $e) {
            Response::error($e->getMessage(), errors: $e->errors(), statusCode: 422);
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Ocorreu um erro interno.', statusCode: 500);
        }
    }

    /** POST /api/v1/tickets/{id}/time/stop */
    public function stop(int $ticketId): never
    {
        try {
            $result = $this->timeService->stop($ticketId);
            Response::success(data: $result, message: 'Temporizador parado.');
        } catch (NotFoundException $e) {
            Response::error($e->getMessage(), statusCode: 404);
        } catch (ValidationException $e) {
            Response::error($e->getMessage(), errors: $e->errors(), statusCode: 422);
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Ocorreu um erro interno.', statusCode: 500);
        }
    }

    /** GET /api/v1/tickets/{id}/time */
    public function index(int $ticketId): never
    {
        try {
            $result = $this->timeService->getEntries($ticketId);
            Response::success(data: $result);
        } catch (NotFoundException $e) {
            Response::error($e->getMessage(), statusCode: 404);
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Ocorreu um erro interno.', statusCode: 500);
        }
    }
}
