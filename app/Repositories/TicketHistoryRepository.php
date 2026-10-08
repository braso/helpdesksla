<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Connection;

final class TicketHistoryRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    /**
     * Registra uma entrada no audit log do ticket.
     *
     * @param array{
     *   ticket_id: int,
     *   actor_id:  ?int,
     *   event:     string,
     *   old_value: ?array,
     *   new_value: ?array,
     *   notes?:    string,
     * } $data
     */
    public function log(array $data): void
    {
        $this->connection->pdo()
            ->prepare(
                'INSERT INTO ticket_history
                    (ticket_id, actor_id, event, old_value, new_value, notes, ip_address, created_at)
                 VALUES
                    (:ticket_id, :actor_id, :event, :old_value, :new_value, :notes, :ip, UTC_TIMESTAMP())'
            )
            ->execute([
                ':ticket_id' => $data['ticket_id'],
                ':actor_id'  => $data['actor_id'] ?? null,
                ':event'     => $data['event'],
                ':old_value' => isset($data['old_value'])
                    ? json_encode($data['old_value'], JSON_THROW_ON_ERROR)
                    : null,
                ':new_value' => isset($data['new_value'])
                    ? json_encode($data['new_value'], JSON_THROW_ON_ERROR)
                    : null,
                ':notes'     => $data['notes'] ?? null,
                ':ip'        => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
    }
}
