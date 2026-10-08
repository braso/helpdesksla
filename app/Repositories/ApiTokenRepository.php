<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Connection;

/**
 * Gerencia tokens JWT persistidos na tabela api_tokens.
 *
 * Persistir o hash do token permite revogação explícita (logout),
 * algo que JWTs puramente stateless não suportam.
 * O token real nunca é armazenado — apenas seu SHA-256.
 */
final class ApiTokenRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    /**
     * Persiste o hash do token recém-emitido.
     *
     * @param int    $userId    ID do usuário autenticado
     * @param string $rawToken  Token JWT em texto claro (será hasheado aqui)
     * @param int    $ttl       Tempo de vida em segundos
     */
    public function store(int $userId, string $rawToken, int $ttl): void
    {
        $this->connection->pdo()
            ->prepare(
                'INSERT INTO api_tokens (user_id, name, token_hash, abilities, expires_at, created_at)
                 VALUES (:user_id, :name, :token_hash, :abilities, DATE_ADD(UTC_TIMESTAMP(), INTERVAL :ttl SECOND), UTC_TIMESTAMP())'
            )
            ->execute([
                ':user_id'    => $userId,
                ':name'       => 'session',
                ':token_hash' => $this->hash($rawToken),
                ':abilities'  => json_encode(['read', 'write']),
                ':ttl'        => $ttl,
            ]);
    }

    /**
     * Verifica se o token ainda existe na base e não expirou.
     * Um token ausente foi explicitamente revogado via logout.
     */
    public function isValid(string $rawToken): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT 1 FROM api_tokens
              WHERE token_hash = :hash
                AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
              LIMIT 1'
        );
        $stmt->execute([':hash' => $this->hash($rawToken)]);

        return $stmt->fetchColumn() !== false;
    }

    /** Revoga o token — equivale ao logout. */
    public function revoke(string $rawToken): void
    {
        $this->connection->pdo()
            ->prepare('DELETE FROM api_tokens WHERE token_hash = :hash')
            ->execute([':hash' => $this->hash($rawToken)]);
    }

    /** Atualiza last_used_at para auditoria de uso. */
    public function touch(string $rawToken): void
    {
        $this->connection->pdo()
            ->prepare(
                'UPDATE api_tokens SET last_used_at = UTC_TIMESTAMP() WHERE token_hash = :hash'
            )
            ->execute([':hash' => $this->hash($rawToken)]);
    }

    private function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}
