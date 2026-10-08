<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Connection;
use App\Models\TicketReply;
use App\Repositories\Contracts\TicketReplyRepositoryInterface;

final class TicketReplyRepository implements TicketReplyRepositoryInterface
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    public function create(array $data): int
    {
        $this->connection->pdo()
            ->prepare(
                'INSERT INTO ticket_replies
                    (uuid, ticket_id, author_id, body, type, is_private, source, metadata, created_at, updated_at)
                 VALUES
                    (:uuid, :ticket_id, :author_id, :body, :type, :is_private, :source, :metadata, :created_at, :updated_at)'
            )
            ->execute([
                ':uuid'       => $data['uuid'],
                ':ticket_id'  => $data['ticket_id'],
                ':author_id'  => $data['author_id'],
                ':body'       => $data['body'],
                ':type'       => $data['type'],
                ':is_private' => (int) $data['is_private'],
                ':source'     => $data['source'],
                ':metadata'   => isset($data['metadata'])
                    ? json_encode($data['metadata'], JSON_THROW_ON_ERROR)
                    : null,
                ':created_at' => $data['created_at'],
                ':updated_at' => $data['updated_at'],
            ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    public function findById(int $id): ?TicketReply
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT r.*, u.name AS author_name FROM ticket_replies r
               LEFT JOIN users u ON u.id = r.author_id
              WHERE r.id = :id AND r.deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();

        return $row !== false ? TicketReply::fromArray($row) : null;
    }

    /**
     * @return TicketReply[]
     */
    public function findByTicketId(int $ticketId, bool $includePrivate = false): array
    {
        $sql = "SELECT r.*, u.name AS author_name,
                       EXISTS (SELECT 1 FROM user_roles ur JOIN roles ro ON ro.id = ur.role_id
                                WHERE ur.user_id = r.author_id
                                  AND ro.slug IN ('admin','agent','supervisor')) AS author_is_staff
                  FROM ticket_replies r
                  LEFT JOIN users u ON u.id = r.author_id
                 WHERE r.ticket_id = :ticket_id
                   AND r.deleted_at IS NULL";

        if (!$includePrivate) {
            $sql .= ' AND r.is_private = 0';
        }

        $sql .= ' ORDER BY r.created_at ASC, r.id ASC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute([':ticket_id' => $ticketId]);

        return array_map(
            static fn(array $row) => TicketReply::fromArray($row),
            $stmt->fetchAll()
        );
    }
}
