<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Connection;

final class AttachmentRepository
{
    public function __construct(private readonly Connection $connection) {}

    public function create(array $data): int
    {
        $pdo  = $this->connection->pdo();
        $stmt = $pdo->prepare(
            "INSERT INTO attachments
                (uuid, attachable_type, attachable_id, uploader_id,
                 original_name, stored_name, mime_type, size_bytes, storage_driver, storage_path)
             VALUES
                (:uuid, :attachable_type, :attachable_id, :uploader_id,
                 :original_name, :stored_name, :mime_type, :size_bytes, :storage_driver, :storage_path)"
        );
        $stmt->execute($data);
        return (int) $pdo->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT * FROM attachments WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByAttachable(string $type, int $id): array
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT * FROM attachments
             WHERE attachable_type = :type AND attachable_id = :id
             ORDER BY created_at ASC"
        );
        $stmt->execute([':type' => $type, ':id' => $id]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function delete(int $id): void
    {
        $this->connection->pdo()
            ->prepare("DELETE FROM attachments WHERE id = :id")
            ->execute([':id' => $id]);
    }
}
