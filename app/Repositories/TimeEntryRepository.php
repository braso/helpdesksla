<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Connection;
use App\Models\TimeEntry;

final class TimeEntryRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    public function start(int $ticketId, int $userId, string $now): TimeEntry
    {
        $this->connection->pdo()
            ->prepare(
                'INSERT INTO ticket_time_entries (ticket_id, user_id, started_at, created_at)
                 VALUES (:ticket_id, :user_id, :started_at, :created_at)'
            )
            ->execute([
                ':ticket_id'  => $ticketId,
                ':user_id'    => $userId,
                ':started_at' => $now,
                ':created_at' => $now,
            ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        return $this->findById($id);
    }

    public function stop(int $entryId, string $now): TimeEntry
    {
        $this->connection->pdo()
            ->prepare(
                'UPDATE ticket_time_entries
                    SET stopped_at       = :now,
                        duration_seconds = TIMESTAMPDIFF(SECOND, started_at, :now2)
                  WHERE id = :id AND stopped_at IS NULL'
            )
            ->execute([':now' => $now, ':now2' => $now, ':id' => $entryId]);

        return $this->findById($entryId);
    }

    public function findById(int $id): TimeEntry
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM ticket_time_entries WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        return TimeEntry::fromArray($stmt->fetch());
    }

    /** Entrada ativa (sem stopped_at) do usuário no ticket, se existir. */
    public function findActive(int $ticketId, int $userId): ?TimeEntry
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM ticket_time_entries
              WHERE ticket_id = :tid AND user_id = :uid AND stopped_at IS NULL
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':tid' => $ticketId, ':uid' => $userId]);
        $row = $stmt->fetch();
        return $row !== false ? TimeEntry::fromArray($row) : null;
    }

    /**
     * @return array{entries: TimeEntry[], total_seconds: int}
     */
    public function findByTicket(int $ticketId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM ticket_time_entries WHERE ticket_id = :tid ORDER BY started_at ASC'
        );
        $stmt->execute([':tid' => $ticketId]);
        $rows    = $stmt->fetchAll();
        $entries = array_map(static fn($r) => TimeEntry::fromArray($r), $rows);
        $total   = array_sum(array_filter(array_column($rows, 'duration_seconds')));
        return ['entries' => $entries, 'total_seconds' => (int) $total];
    }
}
