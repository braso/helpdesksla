<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Core\Database\Connection;
use App\Http\Response;
use Throwable;

/**
 * Gerencia configurações globais do sistema persistidas no banco.
 *
 * Rotas:
 *   GET   /api/v1/settings/smtp  — retorna config SMTP atual (admin)
 *   PATCH /api/v1/settings/smtp  — salva config SMTP (admin)
 */
final class SettingsController
{
    private const SMTP_KEYS = [
        'mail_host', 'mail_port', 'mail_username', 'mail_password',
        'mail_encryption', 'mail_from_address', 'mail_from_name',
    ];

    public function __construct(private readonly Connection $connection) {}

    public function getSmtp(): never
    {
        try {
            $stored = $this->loadAll();

            // Lê do banco; se ausente, cai no .env
            $cfg = [
                'host'         => $stored['mail_host']         ?? ($_ENV['MAIL_HOST']         ?? ''),
                'port'         => (int) ($stored['mail_port']  ?? ($_ENV['MAIL_PORT']          ?? 587)),
                'username'     => $stored['mail_username']     ?? ($_ENV['MAIL_USERNAME']      ?? ''),
                'encryption'   => $stored['mail_encryption']   ?? ($_ENV['MAIL_ENCRYPTION']    ?? 'tls'),
                'from_address' => $stored['mail_from_address'] ?? ($_ENV['MAIL_FROM_ADDRESS']  ?? ''),
                'from_name'    => $stored['mail_from_name']    ?? ($_ENV['MAIL_FROM_NAME']     ?? 'Helpdesk'),
                'has_password' => isset($stored['mail_password'])
                    ? ($stored['mail_password'] !== '')
                    : (($_ENV['MAIL_PASSWORD'] ?? '') !== ''),
                'source'       => empty($stored) ? 'env' : 'database',
            ];

            Response::success(data: $cfg);
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Erro ao carregar configuração SMTP.', statusCode: 500);
        }
    }

    public function patchSmtp(): never
    {
        $body = (array) (json_decode(file_get_contents('php://input') ?: '{}', true) ?? []);

        $encryption = strtolower(trim((string) ($body['encryption'] ?? 'tls')));
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            Response::error('encryption deve ser tls, ssl ou none.', statusCode: 422);
        }

        $port = (int) ($body['port'] ?? 587);
        if ($port < 1 || $port > 65535) {
            Response::error('Porta inválida.', statusCode: 422);
        }

        try {
            $pdo = $this->connection->pdo();
            $this->ensureTable($pdo);

            $map = [
                'mail_host'         => trim((string) ($body['host']         ?? '')),
                'mail_port'         => (string) $port,
                'mail_username'     => trim((string) ($body['username']     ?? '')),
                'mail_encryption'   => $encryption,
                'mail_from_address' => trim((string) ($body['from_address'] ?? '')),
                'mail_from_name'    => trim((string) ($body['from_name']    ?? 'Helpdesk')),
            ];

            // Senha: só sobrescreve se enviada e não vazia
            $pass = trim((string) ($body['password'] ?? ''));
            if ($pass !== '') {
                $map['mail_password'] = $pass;
            }

            $stmt = $pdo->prepare(
                "INSERT INTO system_settings (`key`, `value`) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = CURRENT_TIMESTAMP"
            );

            foreach ($map as $k => $v) {
                $stmt->execute([':k' => $k, ':v' => $v]);
                // Atualiza $_ENV para que a requisição atual já use o novo valor
                $_ENV[strtoupper($k)] = $v;
            }

            Response::success(data: null, message: 'Configuração SMTP salva com sucesso.');
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Erro ao salvar configuração SMTP.', statusCode: 500);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function loadAll(): array
    {
        $pdo = $this->connection->pdo();
        $this->ensureTable($pdo);

        $in   = implode(',', array_fill(0, count(self::SMTP_KEYS), '?'));
        $stmt = $pdo->prepare("SELECT `key`, `value` FROM system_settings WHERE `key` IN ({$in})");
        $stmt->execute(self::SMTP_KEYS);

        return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR) ?: [];
    }

    private function ensureTable(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS system_settings (
                `key`        VARCHAR(100) NOT NULL,
                `value`      TEXT         NULL,
                created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (`key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
