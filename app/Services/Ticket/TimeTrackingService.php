<?php

declare(strict_types=1);

namespace App\Services\Ticket;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Http\AuthContext;
use App\Repositories\TimeEntryRepository;
use App\Repositories\Contracts\TicketRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;

final class TimeTrackingService
{
    public function __construct(
        private readonly TicketRepositoryInterface $ticketRepository,
        private readonly TimeEntryRepository       $timeEntryRepository,
    ) {}

    /**
     * Inicia a contagem de tempo para o usuário atual no ticket.
     * Se já houver uma entrada ativa, retorna ela sem criar nova.
     *
     * @throws NotFoundException
     */
    public function start(int $ticketId): array
    {
        $ticket = $this->ticketRepository->findById($ticketId);
        if ($ticket === null) {
            throw new NotFoundException('Ticket');
        }

        if ($ticket->status === 'closed') {
            throw new ValidationException(['ticket' => ['Não é possível registrar tempo em ticket fechado.']]);
        }

        $userId = AuthContext::userId();
        $active = $this->timeEntryRepository->findActive($ticketId, $userId);

        if ($active !== null) {
            return $this->summary($ticketId, $active->toArray());
        }

        $now   = $this->nowUtc();
        $entry = $this->timeEntryRepository->start($ticketId, $userId, $now);

        return $this->summary($ticketId, $entry->toArray());
    }

    /**
     * Para a contagem de tempo ativa do usuário.
     *
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function stop(int $ticketId): array
    {
        $ticket = $this->ticketRepository->findById($ticketId);
        if ($ticket === null) {
            throw new NotFoundException('Ticket');
        }

        $userId = AuthContext::userId();
        $active = $this->timeEntryRepository->findActive($ticketId, $userId);

        if ($active === null) {
            throw new ValidationException(['time' => ['Nenhum temporizador ativo para este ticket.']]);
        }

        $entry = $this->timeEntryRepository->stop($active->id, $this->nowUtc());

        return $this->summary($ticketId, $entry->toArray());
    }

    /** Retorna todas as entradas + total de segundos do ticket. */
    public function getEntries(int $ticketId): array
    {
        if ($this->ticketRepository->findById($ticketId) === null) {
            throw new NotFoundException('Ticket');
        }

        $userId = AuthContext::userId();
        $active = $this->timeEntryRepository->findActive($ticketId, $userId);
        $result = $this->timeEntryRepository->findByTicket($ticketId);

        return [
            'entries'          => array_map(static fn($e) => $e->toArray(), $result['entries']),
            'total_seconds'    => $result['total_seconds'],
            'active_entry'     => $active?->toArray(),
            'active_started_at'=> $active?->startedAt,
        ];
    }

    private function summary(int $ticketId, array $entry): array
    {
        $result = $this->timeEntryRepository->findByTicket($ticketId);
        $entry['total_seconds'] = $result['total_seconds'];
        return $entry;
    }

    private function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
