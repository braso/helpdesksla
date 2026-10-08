<?php

declare(strict_types=1);

namespace App\Services\Sla;

use App\Core\Database\Connection;
use App\Services\Notification\EmailTemplateService;
use App\Services\Notification\NotificationService;
use Throwable;

/**
 * Motor de alertas de SLA.
 *
 * Lógica:
 *  1. Busca perfis de alerta ativos (sla_alert_profiles).
 *  2. Para perfis do tipo "warning": encontra tickets cujo SLA está dentro
 *     do limiar de aviso e que ainda não receberam esta notificação.
 *  3. Para perfis do tipo "breached/critical": encontra tickets com SLA violado
 *     e ainda abertos que ainda não receberam notificação desta rodada.
 *  4. Para escalonamento: verifica tickets violados há mais de escalation_delay_minutes
 *     e notifica o perfil de escalonamento (se ainda não notificado).
 *  5. Para cada ticket × usuário × canal: insere em sla_notification_logs e envia.
 */
final class SlaAlertService
{
    public function __construct(
        private readonly Connection           $connection,
        private readonly NotificationService  $notificationService,
        private readonly EmailTemplateService $templates,
    ) {}

    /**
     * Ponto de entrada principal — chamado pelo CheckSlaBreachesJob.
     * Retorna contagem de notificações enviadas.
     */
    public function checkAndNotify(): int
    {
        $pdo      = $this->connection->pdo();
        $profiles = $this->loadActiveProfiles($pdo);

        if (empty($profiles)) {
            return 0;
        }

        $total = 0;

        foreach ($profiles as $profile) {
            if ($profile['alert_type'] === 'warning' && $profile['warning_threshold_minutes'] !== null) {
                $total += $this->processWarning($pdo, $profile);
            } else {
                $total += $this->processBreached($pdo, $profile);
            }

            // Escalonamento
            if ($profile['escalation_delay_minutes'] !== null && $profile['escalation_role_id'] !== null) {
                $total += $this->processEscalation($pdo, $profile);
            }
        }

        return $total;
    }

    // ─── Alerta de aviso (pré-vencimento) ────────────────────────────────────

    private function processWarning(\PDO $pdo, array $profile): int
    {
        $threshold = (int) $profile['warning_threshold_minutes'];

        // Tickets cujo SLA vence nos próximos $threshold minutos, ainda abertos
        // e que ainda não receberam o alerta deste perfil
        $stmt = $pdo->prepare(
            "SELECT t.id, t.ticket_number, t.subject, t.priority,
                    t.sla_rt_due_at,
                    TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), t.sla_rt_due_at) AS minutes_left,
                    COALESCE(o.name, 'Sem empresa') AS org_name
               FROM tickets t
               LEFT JOIN organizations o ON o.id = t.organization_id
              WHERE t.deleted_at IS NULL
                AND t.sla_rt_breached = 0
                AND t.sla_rt_due_at IS NOT NULL
                AND t.sla_rt_due_at > UTC_TIMESTAMP()
                AND t.sla_rt_due_at <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL :min MINUTE)
                AND t.status NOT IN ('resolved','closed')
                AND NOT EXISTS (
                    SELECT 1 FROM sla_notification_logs snl
                     WHERE snl.ticket_id        = t.id
                       AND snl.alert_profile_id = :pid
                       AND snl.alert_type       = 'warning'
                       AND snl.status IN ('sent','pending')
                )"
        );
        $stmt->execute([':min' => $threshold, ':pid' => $profile['id']]);

        return $this->notifyUsersForProfile($pdo, $profile, 'warning', $stmt->fetchAll());
    }

    // ─── Alerta de violação ───────────────────────────────────────────────────

    private function processBreached(\PDO $pdo, array $profile): int
    {
        $stmt = $pdo->prepare(
            "SELECT t.id, t.ticket_number, t.subject, t.priority,
                    t.sla_rt_due_at,
                    TIMESTAMPDIFF(MINUTE, t.sla_rt_due_at, UTC_TIMESTAMP()) AS minutes_overdue,
                    COALESCE(o.name, 'Sem empresa') AS org_name
               FROM tickets t
               LEFT JOIN organizations o ON o.id = t.organization_id
              WHERE t.deleted_at IS NULL
                AND t.sla_rt_breached = 1
                AND t.status NOT IN ('resolved','closed')
                AND NOT EXISTS (
                    SELECT 1 FROM sla_notification_logs snl
                     WHERE snl.ticket_id        = t.id
                       AND snl.alert_profile_id = :pid
                       AND snl.alert_type       = :atype
                       AND snl.status IN ('sent','pending')
                )"
        );
        $stmt->execute([':pid' => $profile['id'], ':atype' => $profile['alert_type']]);

        return $this->notifyUsersForProfile($pdo, $profile, $profile['alert_type'], $stmt->fetchAll());
    }

    // ─── Escalonamento ────────────────────────────────────────────────────────

    private function processEscalation(\PDO $pdo, array $profile): int
    {
        $delayMin = (int) $profile['escalation_delay_minutes'];
        $escRoleId = (int) $profile['escalation_role_id'];

        $stmt = $pdo->prepare(
            "SELECT t.id, t.ticket_number, t.subject, t.priority,
                    t.sla_rt_due_at,
                    TIMESTAMPDIFF(MINUTE, t.sla_rt_due_at, UTC_TIMESTAMP()) AS minutes_overdue,
                    COALESCE(o.name, 'Sem empresa') AS org_name
               FROM tickets t
               LEFT JOIN organizations o ON o.id = t.organization_id
              WHERE t.deleted_at IS NULL
                AND t.sla_rt_breached = 1
                AND t.status NOT IN ('resolved','closed')
                AND TIMESTAMPDIFF(MINUTE, t.sla_rt_due_at, UTC_TIMESTAMP()) >= :delay
                AND NOT EXISTS (
                    SELECT 1 FROM sla_notification_logs snl
                     WHERE snl.ticket_id        = t.id
                       AND snl.alert_profile_id = :pid
                       AND snl.alert_type       = 'critical'
                       AND snl.notified_role_id = :esc_role
                       AND snl.status IN ('sent','pending')
                )"
        );
        $stmt->execute([
            ':delay'    => $delayMin,
            ':pid'      => $profile['id'],
            ':esc_role' => $escRoleId,
        ]);

        $escalationProfile = $profile;
        $escalationProfile['role_id'] = $escRoleId;

        return $this->notifyUsersForProfile($pdo, $escalationProfile, 'critical', $stmt->fetchAll());
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function notifyUsersForProfile(
        \PDO   $pdo,
        array  $profile,
        string $alertType,
        array  $tickets,
    ): int {
        if (empty($tickets)) {
            return 0;
        }

        $users = $this->loadUsersForRole($pdo, (int) $profile['role_id']);
        if (empty($users)) {
            return 0;
        }

        $count = 0;

        foreach ($tickets as $ticket) {
            foreach ($users as $user) {
                // Canal interno
                if ((bool) $profile['notify_internal']) {
                    $count += (int) $this->sendInternalNotification(
                        $pdo, $profile, $alertType, $ticket, $user
                    );
                }

                // Canal e-mail
                if ((bool) $profile['notify_email']) {
                    $count += (int) $this->sendEmailNotification(
                        $pdo, $profile, $alertType, $ticket, $user
                    );
                }
            }
        }

        return $count;
    }

    private function sendInternalNotification(
        \PDO   $pdo,
        array  $profile,
        string $alertType,
        array  $ticket,
        array  $user,
    ): bool {
        $logId = $this->insertLog($pdo, $profile, $alertType, $ticket, $user, 'database');

        try {
            $elapsed = $alertType === 'warning'
                ? ($ticket['minutes_left'] . ' min restantes')
                : ($ticket['minutes_overdue'] . ' min excedido');

            $this->notificationService->notifyInternal(
                (int) $user['id'],
                "sla.{$alertType}",
                [
                    'ticket_id'     => $ticket['id'],
                    'ticket_number' => $ticket['ticket_number'],
                    'subject'       => $ticket['subject'],
                    'org_name'      => $ticket['org_name'],
                    'sla_rt_due_at' => $ticket['sla_rt_due_at'],
                    'elapsed'       => $elapsed,
                    'alert_type'    => $alertType,
                ]
            );

            $this->markLogSent($pdo, $logId);
            return true;
        } catch (Throwable $e) {
            $this->markLogFailed($pdo, $logId, $e->getMessage());
            return false;
        }
    }

    private function sendEmailNotification(
        \PDO   $pdo,
        array  $profile,
        string $alertType,
        array  $ticket,
        array  $user,
    ): bool {
        $logId = $this->insertLog($pdo, $profile, $alertType, $ticket, $user, 'mail');

        try {
            $elapsed = $alertType === 'warning'
                ? ($ticket['minutes_left'] . ' min restantes')
                : ($ticket['minutes_overdue'] . ' min excedido');

            $deadline = isset($ticket['sla_rt_due_at'])
                ? (new \DateTimeImmutable($ticket['sla_rt_due_at'], new \DateTimeZone('UTC')))
                    ->setTimezone(new \DateTimeZone($_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo'))
                    ->format('d/m/Y \à\s H:i')
                : null;

            $mail = $this->templates->render('sla.' . $alertType, [
                'usuario.nome'       => $user['name'],
                'usuario.email'      => $user['email'],
                'chamado.numero'     => $ticket['ticket_number'],
                'chamado.assunto'    => $ticket['subject'],
                'chamado.empresa'    => $ticket['org_name'],
                'chamado.prioridade' => ['low' => 'Baixa', 'medium' => 'Média', 'high' => 'Alta', 'critical' => 'Crítica'][$ticket['priority'] ?? ''] ?? null,
                'chamado.link'       => $this->templates->ticketUrl((int) $ticket['id']),
                'sla.tipo'           => ['warning' => 'Prazo de SLA perto de vencer', 'breached' => 'SLA violado', 'critical' => 'SLA crítico — escalonamento'][$alertType] ?? 'Alerta de SLA',
                'sla.prazo'          => $deadline,
                'sla.tempo'          => $elapsed,
            ], [
                'Chamado'     => $ticket['ticket_number'],
                'Assunto'     => $ticket['subject'],
                'Cliente'     => $ticket['org_name'],
                'Data limite' => $deadline,
                $alertType === 'warning' ? 'Tempo restante' : 'Tempo excedido' => $elapsed,
            ]);
            $subject = $mail['subject'];
            $html    = $mail['html'];

            // Entregue pela fila de saída; o status do log é atualizado na entrega.
            $queued = $this->notificationService->queueEmail(
                (int) $user['id'],
                $user['email'],
                $user['name'],
                'sla.' . $alertType,
                $subject,
                $html,
                ['ticket_id' => (int) $ticket['id'], 'ticket_number' => $ticket['ticket_number'], 'sla_log_id' => $logId],
            );

            if ($queued !== null) {
                return true;
            }

            $this->markLogFailed($pdo, $logId, 'Falha ao colocar o e-mail na fila de saída.');
            return false;
        } catch (Throwable $e) {
            $this->markLogFailed($pdo, $logId, $e->getMessage());
            return false;
        }
    }

    private function loadActiveProfiles(\PDO $pdo): array
    {
        return $pdo->query(
            "SELECT * FROM sla_alert_profiles WHERE is_active = 1 ORDER BY id ASC"
        )->fetchAll();
    }

    private function loadUsersForRole(\PDO $pdo, int $roleId): array
    {
        $stmt = $pdo->prepare(
            "SELECT u.id, u.name, u.email
               FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
              WHERE ur.role_id = :rid AND u.is_active = 1 AND u.deleted_at IS NULL"
        );
        $stmt->execute([':rid' => $roleId]);
        return $stmt->fetchAll();
    }

    private function insertLog(\PDO $pdo, array $profile, string $alertType, array $ticket, array $user, string $channel): int
    {
        $pdo->prepare(
            "INSERT INTO sla_notification_logs
                (ticket_id, alert_profile_id, alert_type, notified_role_id, notified_user_id, channel, status)
             VALUES
                (:tid, :pid, :atype, :rid, :uid, :ch, 'pending')"
        )->execute([
            ':tid'   => $ticket['id'],
            ':pid'   => $profile['id'],
            ':atype' => $alertType,
            ':rid'   => $profile['role_id'],
            ':uid'   => $user['id'],
            ':ch'    => $channel,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function markLogSent(\PDO $pdo, int $logId): void
    {
        $pdo->prepare(
            "UPDATE sla_notification_logs SET status='sent', sent_at=UTC_TIMESTAMP() WHERE id=:id"
        )->execute([':id' => $logId]);
    }

    private function markLogFailed(\PDO $pdo, int $logId, string $error): void
    {
        $pdo->prepare(
            "UPDATE sla_notification_logs SET status='failed', error_message=:err WHERE id=:id"
        )->execute([':err' => substr($error, 0, 500), ':id' => $logId]);
    }
}
