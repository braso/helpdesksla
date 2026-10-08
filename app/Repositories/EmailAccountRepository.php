<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Connection;
use App\Models\EmailAccount;

final class EmailAccountRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    public function findById(int $id): ?EmailAccount
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM email_accounts WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? EmailAccount::fromArray($row) : null;
    }

    /** @return EmailAccount[] */
    public function findAll(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT ea.*, o.name AS org_name
               FROM email_accounts ea
               JOIN organizations o ON o.id = ea.organization_id
              WHERE ea.deleted_at IS NULL
              ORDER BY ea.name ASC'
        );
        return array_map(static fn($r) => EmailAccount::fromArray($r), $stmt->fetchAll());
    }

    /** @return EmailAccount[] */
    public function findActive(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT * FROM email_accounts
              WHERE deleted_at IS NULL AND is_active = 1
              ORDER BY id ASC'
        );
        return array_map(static fn($r) => EmailAccount::fromArray($r), $stmt->fetchAll());
    }

    public function create(array $data): int
    {
        $this->connection->pdo()->prepare(
            'INSERT INTO email_accounts
               (uuid, organization_id, name, host, port, protocol, encryption,
                username, password, mail_folder, default_priority, auto_create_user,
                is_active, created_at, updated_at)
             VALUES
               (:uuid, :org_id, :name, :host, :port, :protocol, :encryption,
                :username, :password, :folder, :priority, :auto, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        )->execute([
            ':uuid'     => $data['uuid'],
            ':org_id'   => $data['organization_id'],
            ':name'     => $data['name'],
            ':host'     => $data['host'],
            ':port'     => $data['port'],
            ':protocol' => $data['protocol'],
            ':encryption' => $data['encryption'],
            ':username' => $data['username'],
            ':password' => $data['password'],
            ':folder'   => $data['mail_folder'] ?? 'INBOX',
            ':priority' => $data['default_priority'] ?? 'medium',
            ':auto'     => (int) ($data['auto_create_user'] ?? true),
        ]);
        return (int) $this->connection->pdo()->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $allowed = ['name','host','port','protocol','encryption','username','password',
                    'mail_folder','default_priority','auto_create_user','is_active'];
        $sets = []; $params = [':id' => $id];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $sets[]          = "`{$col}` = :{$col}";
                $params[":{$col}"] = $data[$col];
            }
        }
        if (empty($sets)) return;
        $sets[] = 'updated_at = UTC_TIMESTAMP()';
        $this->connection->pdo()->prepare(
            'UPDATE email_accounts SET ' . implode(', ', $sets) . ' WHERE id = :id'
        )->execute($params);
    }

    public function delete(int $id): void
    {
        $this->connection->pdo()->prepare(
            'UPDATE email_accounts SET deleted_at = UTC_TIMESTAMP() WHERE id = :id'
        )->execute([':id' => $id]);
    }

    public function touchFetched(int $id, ?string $error = null): void
    {
        $this->connection->pdo()->prepare(
            'UPDATE email_accounts
                SET last_fetched_at = UTC_TIMESTAMP(), last_error = :err, updated_at = UTC_TIMESTAMP()
              WHERE id = :id'
        )->execute([':id' => $id, ':err' => $error]);
    }
}
