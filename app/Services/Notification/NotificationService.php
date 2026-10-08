<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Core\Database\Connection;
use Throwable;

/**
 * Envia notificações internas (banco) e por e-mail.
 *
 * Configuração lida do banco (system_settings), com fallback para .env:
 *   MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD,
 *   MAIL_FROM_ADDRESS, MAIL_FROM_NAME, MAIL_ENCRYPTION
 */
final class NotificationService
{
    /** Cache de config SMTP por requisição. */
    private ?array $smtpConfig = null;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * Cria notificação interna na tabela `notifications`.
     * Retorna o ID inserido ou null em caso de falha.
     */
    public function notifyInternal(
        int    $userId,
        string $type,
        array  $data,
    ): ?int {
        try {
            $pdo = $this->connection->pdo();
            $uuid = sprintf(
                '%08x-%04x-%04x-%04x-%012x',
                random_int(0, 0xffffffff),
                random_int(0, 0xffff),
                random_int(0x4000, 0x4fff),
                random_int(0x8000, 0xbfff),
                random_int(0, 0xffffffffffff)
            );

            $pdo->prepare(
                "INSERT INTO notifications
                    (uuid, notifiable_type, notifiable_id, type, channel, data, sent_at)
                 VALUES
                    (:uuid, 'user', :uid, :type, 'database', :data, UTC_TIMESTAMP())"
            )->execute([
                ':uuid' => $uuid,
                ':uid'  => $userId,
                ':type' => $type,
                ':data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);

            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log("[NotificationService] notifyInternal falhou: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Envia e-mail via SMTP (cliente manual) ou mail() quando não há SMTP configurado.
     * Retorna true se o servidor aceitou a mensagem; lança RuntimeException com o
     * motivo quando a conexão ou alguma etapa do protocolo falha.
     */
    public function notifyEmail(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
    ): bool {
        $cfg         = $this->loadSmtpConfig();
        $fromAddress = $cfg['from_address'];
        $fromName    = $cfg['from_name'];

        if ($cfg['host'] !== '') {
            return $this->sendSmtp($toEmail, $toName, $fromAddress, $fromName, $subject, $htmlBody);
        }

        // Fallback: mail() nativo do PHP. Nome do remetente sem quebras de linha (header injection).
        $safeName = str_replace(["\r", "\n"], '', $fromName);
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: =?UTF-8?B?" . base64_encode($safeName) . "?= <{$fromAddress}>\r\n";
        $headers .= "Reply-To: {$fromAddress}\r\n";

        return mail($toEmail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlBody, $headers);
    }

    // ─── Fila de saída (email_outbox) ─────────────────────────────────────────

    /** Tentativas antes de desistir e espera (min) antes de cada nova tentativa. */
    private const MAX_ATTEMPTS   = 5;
    private const RETRY_MINUTES  = [1, 5, 15, 60];
    /** Envio "preso" em 'sending' há mais que isso (processo morreu) volta para a fila. */
    private const STALE_MINUTES  = 10;

    /** @var list<int> e-mails enfileirados nesta requisição (envio rápido após a resposta) */
    private array $queuedNow = [];

    /**
     * Coloca um e-mail na fila de saída. Quem entrega é o job do cron
     * (app/Jobs/SendQueuedEmailsJob.php, a cada minuto), com novas tentativas.
     * Para não esperar o cron, a requisição também tenta entregar logo depois
     * de responder ao navegador; o que falhar fica na fila.
     *
     * @param array<string, mixed> $context  ex.: ['ticket_id' => 1, 'ticket_number' => 'TKT-…']
     */
    public function queueEmail(
        ?int   $userId,
        string $toEmail,
        string $toName,
        string $type,
        string $subject,
        string $htmlBody,
        array  $context = [],
    ): ?int {
        try {
            $pdo = $this->connection->pdo();
            $pdo->prepare(
                'INSERT INTO email_outbox (user_id, to_email, to_name, type, subject, html_body, context, available_at)
                 VALUES (:uid, :to, :name, :type, :subject, :body, :ctx, UTC_TIMESTAMP())'
            )->execute([
                ':uid'     => $userId,
                ':to'      => $toEmail,
                ':name'    => mb_substr($toName, 0, 191),
                ':type'    => $type,
                ':subject' => mb_substr($subject, 0, 255),
                ':body'    => $htmlBody,
                ':ctx'     => json_encode($context, JSON_UNESCAPED_UNICODE),
            ]);
            $id = (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log("[NotificationService] falha ao enfileirar e-mail para {$toEmail}: {$e->getMessage()}");
            return null;
        }

        if (!$this->queuedNow) {
            register_shutdown_function(function (): void {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                ignore_user_abort(true);
                set_time_limit(0);
                foreach ($this->queuedNow as $queuedId) {
                    $this->deliverQueued($queuedId);
                }
                $this->queuedNow = [];
            });
        }
        $this->queuedNow[] = $id;

        return $id;
    }

    /**
     * Entrega os e-mails pendentes da fila (chamado pelo cron).
     *
     * @return array{sent: int, retry: int, failed: int}
     */
    public function processOutbox(int $limit = 100): array
    {
        $pdo = $this->connection->pdo();
        $pdo->exec(
            "UPDATE email_outbox SET status = 'pending', locked_at = NULL
              WHERE status = 'sending' AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . self::STALE_MINUTES . ' MINUTE)'
        );
        $stmt = $pdo->prepare(
            "SELECT id FROM email_outbox
              WHERE status = 'pending' AND available_at <= UTC_TIMESTAMP()
              ORDER BY id LIMIT :lim"
        );
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $totals = ['sent' => 0, 'retry' => 0, 'failed' => 0];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $result = $this->deliverQueued((int) $id);
            if ($result !== null) {
                $totals[$result]++;
            }
        }
        return $totals;
    }

    /**
     * Tenta entregar um item da fila. Retorna 'sent', 'retry', 'failed' ou null
     * (já entregue ou sendo entregue por outro processo).
     */
    private function deliverQueued(int $id): ?string
    {
        try {
            $pdo = $this->connection->pdo();
            // Reserva atômica: só um processo (requisição ou cron) envia cada e-mail.
            $claim = $pdo->prepare(
                "UPDATE email_outbox SET status = 'sending', locked_at = UTC_TIMESTAMP(), attempts = attempts + 1
                  WHERE id = :id AND status = 'pending' AND available_at <= UTC_TIMESTAMP()"
            );
            $claim->execute([':id' => $id]);
            if ($claim->rowCount() !== 1) {
                return null;
            }
            $s = $pdo->prepare('SELECT * FROM email_outbox WHERE id = :id');
            $s->execute([':id' => $id]);
            $row = $s->fetch();
        } catch (Throwable $e) {
            error_log("[NotificationService] fila de e-mail #{$id}: {$e->getMessage()}");
            return null;
        }

        $error = null;
        try {
            if (!$this->notifyEmail($row['to_email'], $row['to_name'], $row['subject'], $row['html_body'])) {
                $error = 'O servidor de e-mail recusou a mensagem.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $attempts = (int) $row['attempts'];
        $result   = $error === null ? 'sent' : ($attempts >= self::MAX_ATTEMPTS ? 'failed' : 'retry');
        $context  = json_decode((string) $row['context'], true) ?: [];

        try {
            if ($result === 'retry') {
                $wait = self::RETRY_MINUTES[min($attempts - 1, count(self::RETRY_MINUTES) - 1)];
                $pdo->prepare(
                    "UPDATE email_outbox SET status = 'pending', locked_at = NULL, last_error = :err,
                            available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$wait} MINUTE)
                      WHERE id = :id"
                )->execute([':err' => $error, ':id' => $id]);
            } else {
                $pdo->prepare(
                    "UPDATE email_outbox SET status = :st, locked_at = NULL, last_error = :err,
                            sent_at = IF(:ok = 1, UTC_TIMESTAMP(), NULL)
                      WHERE id = :id"
                )->execute([':st' => $result, ':err' => $error, ':ok' => (int) ($result === 'sent'), ':id' => $id]);

                if (isset($context['sla_log_id'])) {
                    $pdo->prepare(
                        "UPDATE sla_notification_logs SET status = :st, error_message = :err,
                                sent_at = IF(:ok = 1, UTC_TIMESTAMP(), NULL)
                          WHERE id = :id"
                    )->execute([':st' => $result, ':err' => $error, ':ok' => (int) ($result === 'sent'), ':id' => (int) $context['sla_log_id']]);
                }
                if ($row['user_id'] !== null) {
                    $this->logMail((int) $row['user_id'], $row['type'], $row['to_email'], $row['subject'], $context, $error);
                }
            }
        } catch (Throwable $e) {
            error_log("[NotificationService] falha ao atualizar fila de e-mail #{$id}: {$e->getMessage()}");
        }

        if ($error !== null) {
            error_log("[NotificationService] e-mail #{$id} para {$row['to_email']} falhou (tentativa {$attempts}): {$error}");
        }
        return $result;
    }

    /** Auditoria do resultado final em `notifications` (canal mail). */
    private function logMail(int $userId, string $type, string $toEmail, string $subject, array $context, ?string $error): void
    {
        unset($context['sla_log_id']);
        $this->connection->pdo()->prepare(
            "INSERT INTO notifications (uuid, notifiable_type, notifiable_id, type, channel, data, read_at, sent_at)
             VALUES (:uuid, 'user', :uid, :type, 'mail', :data, UTC_TIMESTAMP(), :sent)"
        )->execute([
            ':uuid' => $this->uuid(),
            ':uid'  => $userId,
            ':type' => $type,
            ':data' => json_encode($context + ['to' => $toEmail, 'subject' => $subject, 'status' => $error === null ? 'sent' : 'failed', 'error' => $error], JSON_UNESCAPED_UNICODE),
            ':sent' => $error === null ? gmdate('Y-m-d H:i:s') : null,
        ]);
    }

    /**
     * Retorna notificações não lidas de um usuário.
     *
     * @return array{items: list<array>, unread_count: int}
     */
    public function getForUser(int $userId, int $limit = 20): array
    {
        $pdo  = $this->connection->pdo();
        $stmt = $pdo->prepare(
            "SELECT id, uuid, type, channel, data, read_at, sent_at, created_at
               FROM notifications
              WHERE notifiable_type = 'user'
                AND notifiable_id   = :uid
                AND channel         = 'database'
              ORDER BY created_at DESC
              LIMIT :lim"
        );
        $stmt->bindValue(':uid', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit,  \PDO::PARAM_INT);
        $stmt->execute();

        $items = array_map(static function (array $r) {
            $r['data'] = json_decode($r['data'] ?? '{}', true) ?: [];
            return $r;
        }, $stmt->fetchAll());

        $unreadStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM notifications
              WHERE notifiable_type='user' AND notifiable_id=:uid AND channel='database' AND read_at IS NULL"
        );
        $unreadStmt->execute([':uid' => $userId]);
        $unread = (int) $unreadStmt->fetchColumn();

        return ['items' => $items, 'unread_count' => $unread];
    }

    /** Marca notificação como lida. */
    public function markRead(int $notificationId, int $userId): void
    {
        $this->connection->pdo()->prepare(
            "UPDATE notifications SET read_at = UTC_TIMESTAMP()
              WHERE id = :id AND notifiable_type = 'user' AND notifiable_id = :uid AND read_at IS NULL"
        )->execute([':id' => $notificationId, ':uid' => $userId]);
    }

    /** Marca todas as notificações de um usuário como lidas. */
    public function markAllRead(int $userId): void
    {
        $this->connection->pdo()->prepare(
            "UPDATE notifications SET read_at = UTC_TIMESTAMP()
              WHERE notifiable_type = 'user' AND notifiable_id = :uid AND read_at IS NULL"
        )->execute([':uid' => $userId]);
    }

    // ─── SMTP privado ─────────────────────────────────────────────────────────

    private function sendSmtp(
        string $toEmail,
        string $toName,
        string $fromAddress,
        string $fromName,
        string $subject,
        string $htmlBody,
    ): bool {
        $cfg        = $this->loadSmtpConfig();
        $host       = $cfg['host'];
        $port       = $cfg['port'];
        $username   = $cfg['username'];
        $password   = $cfg['password'];
        $encryption = $cfg['encryption'];

        // Porta 465 sempre exige SSL direto, independente da variável MAIL_ENCRYPTION
        $useSmtps = $encryption === 'ssl' || $port === 465;
        $prefix   = $useSmtps ? 'ssl://' : '';

        $socket = @fsockopen("{$prefix}{$host}", $port, $errno, $errstr, 10);
        if ($socket === false) {
            throw new \RuntimeException("Não foi possível conectar ao SMTP {$host}:{$port}: {$errstr} ({$errno})");
        }
        stream_set_timeout($socket, 15);

        // Lê a resposta completa: linhas "250-..." continuam, "250 ..." encerra.
        $read = static function () use ($socket): array {
            $text = '';
            while (true) {
                $line = fgets($socket, 1024);
                if ($line === false) {
                    $meta = stream_get_meta_data($socket);
                    throw new \RuntimeException($meta['timed_out'] ? 'Tempo esgotado aguardando o servidor SMTP.' : 'O servidor SMTP encerrou a conexão.');
                }
                $text .= $line;
                if (strlen($line) < 4 || $line[3] !== '-') {
                    return [(int) substr($line, 0, 3), trim($text)];
                }
            }
        };
        $expect = static function (array $resp, array $codes, string $step): void {
            if (!in_array($resp[0], $codes, true)) {
                throw new \RuntimeException("SMTP recusou {$step}: {$resp[1]}");
            }
        };
        $send = static function (string $cmd) use ($socket, $read): array {
            fwrite($socket, $cmd . "\r\n");
            return $read();
        };

        try {
            $expect($read(), [220], 'a conexão');
            $ehlo = 'EHLO ' . (gethostname() ?: 'helpdesk');
            $expect($send($ehlo), [250], 'o EHLO');

            if (!$useSmtps && $encryption === 'tls') {
                $expect($send('STARTTLS'), [220], 'o STARTTLS');
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('Falha ao negociar TLS com o servidor SMTP.');
                }
                $expect($send($ehlo), [250], 'o EHLO após TLS');
            }

            if ($username !== '') {
                $expect($send('AUTH LOGIN'), [334], 'a autenticação');
                $expect($send(base64_encode($username)), [334], 'o usuário');
                $expect($send(base64_encode($password)), [235], 'a senha (verifique usuário e senha)');
            }

            $expect($send("MAIL FROM:<{$fromAddress}>"), [250], 'o remetente');
            $expect($send("RCPT TO:<{$toEmail}>"), [250, 251], 'o destinatário');
            $expect($send('DATA'), [354], 'o envio');

            $domain   = substr(strrchr($fromAddress, '@') ?: '@helpdesk.local', 1);
            $boundary = 'b' . bin2hex(random_bytes(12));
            $text     = trim(html_entity_decode(strip_tags(preg_replace(['/<(br|\/p|\/tr|\/h[1-6]|\/div|\/li|\/table|\/blockquote)[^>]*>/i', '/<\/td>/i'], ["\n", ' '], $htmlBody) ?? $htmlBody), ENT_QUOTES, 'UTF-8'));
            $text     = preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text) ?? $text;

            $message  = 'Date: ' . date('r') . "\r\n";
            $message .= 'Message-ID: <' . bin2hex(random_bytes(10)) . "@{$domain}>\r\n";
            $message .= 'From: =?UTF-8?B?' . base64_encode($fromName) . "?= <{$fromAddress}>\r\n";
            $message .= 'To: =?UTF-8?B?' . base64_encode($toName) . "?= <{$toEmail}>\r\n";
            $message .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n";
            $message .= "MIME-Version: 1.0\r\n";
            $message .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
            $message .= "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
            $message .= chunk_split(base64_encode($text)) . "\r\n";
            $message .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
            $message .= chunk_split(base64_encode($htmlBody)) . "\r\n";
            $message .= "--{$boundary}--\r\n.";

            $expect($send($message), [250], 'a mensagem');
            try { $send('QUIT'); } catch (\Throwable) {}
            return true;
        } finally {
            fclose($socket);
        }
    }

    private function uuid(): string
    {
        $b    = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** Servidor efetivamente usado (banco, com fallback para o .env), sem a senha. */
    public function smtpSummary(): array
    {
        $c = $this->loadSmtpConfig();
        return ['host' => $c['host'], 'port' => $c['port'], 'encryption' => $c['encryption'], 'from_address' => $c['from_address']];
    }

    // ─── Config SMTP ─────────────────────────────────────────────────────────

    private function loadSmtpConfig(): array
    {
        if ($this->smtpConfig !== null) {
            return $this->smtpConfig;
        }

        $db = [];
        try {
            $stmt = $this->connection->pdo()->prepare(
                "SELECT `key`, `value` FROM system_settings
                  WHERE `key` IN ('mail_host','mail_port','mail_username','mail_password',
                                  'mail_encryption','mail_from_address','mail_from_name')"
            );
            $stmt->execute();
            $db = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Throwable) {
            // tabela ainda não existe — usa somente .env
        }

        $this->smtpConfig = [
            'host'         => $db['mail_host']         ?? ($_ENV['MAIL_HOST']         ?? ''),
            'port'         => (int) ($db['mail_port']  ?? ($_ENV['MAIL_PORT']          ?? 587)),
            'username'     => $db['mail_username']     ?? ($_ENV['MAIL_USERNAME']      ?? ''),
            'password'     => $db['mail_password']     ?? ($_ENV['MAIL_PASSWORD']      ?? ''),
            'encryption'   => strtolower($db['mail_encryption']   ?? ($_ENV['MAIL_ENCRYPTION']   ?? 'tls')),
            'from_address' => $db['mail_from_address'] ?? ($_ENV['MAIL_FROM_ADDRESS']  ?? 'helpdesk@sistema.local'),
            'from_name'    => $db['mail_from_name']    ?? ($_ENV['MAIL_FROM_NAME']     ?? 'Helpdesk'),
        ];

        return $this->smtpConfig;
    }

    // ─── Template de e-mail ───────────────────────────────────────────────────

    public static function buildTestEmailBody(
        string $senderName,
        string $toEmail,
        string $smtpHost,
        string $smtpPort,
    ): string {
        $now = gmdate('d/m/Y H:i:s') . ' UTC';

        return <<<HTML
        <!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"></head>
        <body style="font-family:Arial,sans-serif;background:#f1f5f9;margin:0;padding:32px 16px">
          <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,.08)">
            <div style="background:#6366f1;padding:24px 32px">
              <div style="font-size:20px;font-weight:700;color:#fff">✅ Teste de Configuração SMTP</div>
              <div style="font-size:13px;color:rgba(255,255,255,.85);margin-top:4px">Helpdesk — Alertas de SLA</div>
            </div>
            <div style="padding:32px">
              <p style="margin:0 0 20px;font-size:15px;color:#1e293b">
                Se você está lendo este e-mail, a configuração SMTP está funcionando corretamente.
              </p>
              <table style="width:100%;border-collapse:collapse;font-size:14px">
                <tr><td style="padding:6px 0;color:#64748b;width:40%">Enviado por</td><td style="padding:6px 0;color:#1e293b">{$senderName}</td></tr>
                <tr><td style="padding:6px 0;color:#64748b">Destinatário</td><td style="padding:6px 0;color:#1e293b">{$toEmail}</td></tr>
                <tr><td style="padding:6px 0;color:#64748b">Servidor SMTP</td><td style="padding:6px 0;font-family:monospace;color:#6366f1">{$smtpHost}:{$smtpPort}</td></tr>
                <tr><td style="padding:6px 0;color:#64748b">Data/Hora</td><td style="padding:6px 0;color:#1e293b">{$now}</td></tr>
              </table>
              <div style="margin-top:28px;padding:16px;background:#f0fdf4;border-radius:8px;border-left:4px solid #22c55e">
                <div style="font-size:13px;color:#166534;font-weight:600">Próximo passo</div>
                <div style="font-size:13px;color:#166534;margin-top:4px">
                  Os alertas de SLA serão enviados automaticamente para os usuários conforme os perfis configurados.
                </div>
              </div>
            </div>
          </div>
        </body></html>
        HTML;
    }
}
