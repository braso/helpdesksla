<?php

declare(strict_types=1);

/**
 * Lê configuração do banco exclusivamente de variáveis de ambiente.
 * Nunca hardcode credenciais neste arquivo.
 */
return [
    'host'     => $_ENV['DB_HOST']     ?? '127.0.0.1',
    'port'     => (int) ($_ENV['DB_PORT'] ?? 3306),
    'database' => $_ENV['DB_DATABASE'] ?? 'helpdesk',
    'username' => $_ENV['DB_USERNAME'] ?? 'root',
    'password' => $_ENV['DB_PASSWORD'] ?? '',
];
