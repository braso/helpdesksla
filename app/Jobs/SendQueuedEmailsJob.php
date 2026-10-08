<?php

declare(strict_types=1);

/**
 * Entrega os e-mails da fila de saída (email_outbox): avisos de chamado, respostas,
 * recuperação de senha e alertas de SLA. Falhas são tentadas de novo
 * (1, 5, 15 e 60 min; desiste na 5ª tentativa e registra o erro).
 *
 * Agendado pelo serviço "scheduler" do docker-compose a cada minuto
 * (.docker/cron/crontab). Também pode rodar à mão:
 *   docker compose exec php php app/Jobs/SendQueuedEmailsJob.php
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$envFile = dirname(__DIR__, 2) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

$stamp = static fn(): string => gmdate('Y-m-d H:i:s') . ' UTC';

// Evita duas execuções simultâneas (um SMTP lento não se sobrepõe à próxima rodada).
$lock = fopen(sys_get_temp_dir() . '/helpdesk-send-emails.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    printf("[%s] Envio de e-mails já em andamento — pulando esta execução.\n", $stamp());
    exit(0);
}

$config     = require dirname(__DIR__, 2) . '/config/database.php';
$connection = \App\Core\Database\Connection::fromConfig($config);
$notifier   = new \App\Services\Notification\NotificationService($connection);

try {
    $r = $notifier->processOutbox();
} catch (Throwable $e) {
    fprintf(STDERR, "[%s] Falha ao processar a fila de e-mails: %s\n", $stamp(), $e->getMessage());
    exit(1);
}

// Silencioso quando não há nada a fazer (roda a cada minuto).
if ($r['sent'] + $r['retry'] + $r['failed'] > 0) {
    printf(
        "[%s] Fila de e-mails — enviados: %d | nova tentativa agendada: %d | falha definitiva: %d\n",
        $stamp(), $r['sent'], $r['retry'], $r['failed']
    );
}
