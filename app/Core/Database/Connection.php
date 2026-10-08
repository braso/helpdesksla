<?php

declare(strict_types=1);

namespace App\Core\Database;

use PDO;
use PDOException;
use RuntimeException;

final class Connection
{
    private readonly PDO $pdo;

    public function __construct(
        private readonly string $host,
        private readonly int    $port,
        private readonly string $database,
        private readonly string $username,
        private readonly string $password,
    ) {
        $this->pdo = $this->build();
    }

    private function build(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->host,
            $this->port,
            $this->database
        );

        try {
            return new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Desativa emulação para que parâmetros sejam sempre tratados como dados
                // pelo driver nativo do MySQL, nunca interpolados como SQL.
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
            ]);
        } catch (PDOException) {
            // Suprime a mensagem original do PDO para não vazar DSN/credenciais em logs.
            throw new RuntimeException('Unable to establish database connection.');
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Factory method para construção a partir do array de config (config/database.php).
     * Mantém o construtor limpo para uso direto via DI container.
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            host:     (string) ($config['host']     ?? '127.0.0.1'),
            port:     (int)    ($config['port']     ?? 3306),
            database: (string) ($config['database'] ?? ''),
            username: (string) ($config['username'] ?? ''),
            password: (string) ($config['password'] ?? ''),
        );
    }
}
