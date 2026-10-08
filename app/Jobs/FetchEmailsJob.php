<?php

declare(strict_types=1);

/**
 * Busca e-mails de todas as contas ativas e abre chamados (IMAP/POP3).
 *
 * Agendado pelo serviço "scheduler" do docker-compose a cada 5 minutos
 * (.docker/cron/crontab). Também pode rodar à mão:
 *   docker compose exec php php app/Jobs/FetchEmailsJob.php
 *
 * Usa a mesma montagem da API (bootstrap/app.php): chamados criados por e-mail
 * disparam notificações, automações e a classificação por IA.
 */

require dirname(__DIR__, 2) . '/bootstrap/app.php';

$started = microtime(true);
$stamp   = static fn(): string => gmdate('Y-m-d H:i:s') . ' UTC';

// Evita duas buscas simultâneas (uma execução lenta não se sobrepõe à próxima).
$lock = fopen(sys_get_temp_dir() . '/helpdesk-fetch-emails.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    printf("[%s] Busca de e-mails já em andamento — pulando esta execução.\n", $stamp());
    exit(0);
}

try {
    $results = $emailFetcher->fetchAll();
} catch (Throwable $e) {
    fprintf(STDERR, "[%s] Falha na busca de e-mails: %s\n", $stamp(), $e->getMessage());
    exit(1);
}

if (!$results) {
    printf("[%s] Nenhuma conta de e-mail ativa.\n", $stamp());
    exit(0);
}

$created = 0;
foreach ($results as $accountId => $r) {
    $created += (int) $r['tickets_created'];
    printf(
        "[%s] Conta #%d: %d e-mail(s) lido(s), %d chamado(s) criado(s)%s\n",
        $stamp(), $accountId, $r['emails_processed'], $r['tickets_created'],
        $r['errors'] ? ' | erros: ' . implode('; ', array_map(static fn($x) => mb_substr((string) $x, 0, 200), $r['errors'])) : ''
    );
}
printf("[%s] Total: %d chamado(s) criado(s) em %.1fs.\n", $stamp(), $created, microtime(true) - $started);
