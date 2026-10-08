<?php

declare(strict_types=1);

/**
 * Job de verificação de breaches de SLA + disparo de alertas.
 *
 * Cron recomendado (a cada 5 minutos):
 *   * /5 * * * * php /var/www/app/Jobs/CheckSlaBreachesJob.php >> /var/log/sla_breaches.log 2>&1
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

$config     = require dirname(__DIR__, 2) . '/config/database.php';
$connection = \App\Core\Database\Connection::fromConfig($config);

// 1. Marca breaches em lote
$ticketRepository = new \App\Repositories\TicketRepository($connection);
$result = $ticketRepository->markSlaBreaches();

// 2. Dispara alertas de SLA
$notificationService = new \App\Services\Notification\NotificationService($connection);
$emailTemplates      = new \App\Services\Notification\EmailTemplateService($connection, $_ENV['APP_URL'] ?? getenv('APP_URL') ?: 'http://localhost:8080');
$slaAlertService     = new \App\Services\Sla\SlaAlertService($connection, $notificationService, $emailTemplates);
$notified            = $slaAlertService->checkAndNotify();

$timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

printf(
    "[%s] SLA breaches marcados — FRT: %d ticket(s) | RT: %d ticket(s) | Notificações enviadas: %d\n",
    $timestamp,
    $result['frt'],
    $result['rt'],
    $notified
);
