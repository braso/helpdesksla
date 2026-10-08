<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reports;

use App\Core\Database\Connection;
use App\Http\AuthContext;
use App\Http\Response;
use App\Services\Report\ReportExportService;
use Throwable;

final class ReportController
{
    public function __construct(
        private readonly Connection           $connection,
        private readonly ReportExportService  $exportService = new ReportExportService(),
    ) {}

    /** GET /api/v1/reports/overview */
    public function overview(): never
    {
        try {
            $pdo    = $this->connection->pdo();
            $orgId  = $this->managerOrgId();
            $where  = $orgId !== null ? 'deleted_at IS NULL AND organization_id = :oid' : 'deleted_at IS NULL';
            $bind   = $orgId !== null ? [':oid' => $orgId] : [];

            $byStatus = $pdo->prepare("SELECT status, COUNT(*) AS total FROM tickets WHERE {$where} GROUP BY status");
            $byStatus->execute($bind);

            $statusMap  = [];
            $grandTotal = 0;
            foreach ($byStatus->fetchAll() as $row) {
                $statusMap[$row['status']] = (int) $row['total'];
                $grandTotal += (int) $row['total'];
            }

            $metrics = $pdo->prepare(
                "SELECT
                   COUNT(*) AS total,
                   SUM(sla_rt_breached = 1)  AS sla_breached,
                   SUM(sla_rt_breached = 0 AND sla_rt_due_at IS NOT NULL) AS sla_ok,
                   AVG(CASE WHEN resolved_at IS NOT NULL
                            THEN TIMESTAMPDIFF(SECOND, created_at, resolved_at) END) AS avg_resolution_sec,
                   AVG(CASE WHEN first_response_at IS NOT NULL
                            THEN TIMESTAMPDIFF(SECOND, created_at, first_response_at) END) AS avg_frt_sec,
                   SUM(created_at  >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7  DAY)) AS opened_7d,
                   SUM(resolved_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7  DAY)) AS resolved_7d,
                   SUM(created_at  >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)) AS opened_30d,
                   SUM(resolved_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)) AS resolved_30d
                 FROM tickets WHERE {$where}"
            );
            $metrics->execute($bind);
            $m = $metrics->fetch();

            $byPriority = $pdo->prepare(
                "SELECT priority,
                        COUNT(*) AS total,
                        SUM(sla_rt_breached = 1)             AS breached,
                        SUM(status IN ('resolved','closed'))  AS resolved,
                        AVG(CASE WHEN resolved_at IS NOT NULL
                                 THEN TIMESTAMPDIFF(SECOND, created_at, resolved_at) END) AS avg_sec
                   FROM tickets WHERE {$where}
                  GROUP BY priority
                  ORDER BY FIELD(priority,'critical','high','medium','low')"
            );
            $byPriority->execute($bind);

            $trend = $pdo->prepare(
                "SELECT DATE(created_at) AS day,
                        COUNT(*) AS opened,
                        SUM(resolved_at IS NOT NULL AND DATE(resolved_at) = DATE(created_at)) AS resolved
                   FROM tickets
                  WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
                    AND {$where}
                  GROUP BY DATE(created_at)
                  ORDER BY day ASC"
            );
            $trend->execute($bind);

            $slaTotal      = (int) $m['sla_breached'] + (int) $m['sla_ok'];
            $slaCompliance = $slaTotal > 0 ? round(100 * (int) $m['sla_ok'] / $slaTotal, 1) : null;

            Response::success(data: [
                'total'              => $grandTotal,
                'by_status'          => $statusMap,
                'sla_breached'       => (int) $m['sla_breached'],
                'sla_ok'             => (int) $m['sla_ok'],
                'sla_compliance'     => $slaCompliance,
                'avg_resolution_sec' => $m['avg_resolution_sec'] ? (int) $m['avg_resolution_sec'] : null,
                'avg_frt_sec'        => $m['avg_frt_sec']        ? (int) $m['avg_frt_sec']        : null,
                'opened_7d'          => (int) $m['opened_7d'],
                'resolved_7d'        => (int) $m['resolved_7d'],
                'opened_30d'         => (int) $m['opened_30d'],
                'resolved_30d'       => (int) $m['resolved_30d'],
                'by_priority'        => array_map(fn($r) => [
                    'priority' => $r['priority'],
                    'total'    => (int) $r['total'],
                    'breached' => (int) $r['breached'],
                    'resolved' => (int) $r['resolved'],
                    'avg_sec'  => $r['avg_sec'] ? (int) $r['avg_sec'] : null,
                ], $byPriority->fetchAll()),
                'trend_14d'          => $this->fillDailyGaps($trend->fetchAll(), 14),
            ]);
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Erro ao gerar relatório.', statusCode: 500);
        }
    }

    /** GET /api/v1/reports/agents — apenas admin/agente */
    public function agents(): never
    {
        if ($this->managerOrgId() !== null) {
            Response::error('Acesso negado.', statusCode: 403);
        }

        try {
            $pdo  = $this->connection->pdo();

            $rows = $pdo->query(
                "SELECT u.id,
                        u.first_name, u.last_name, u.name, u.email,
                        r.slug AS role_slug,
                        COUNT(DISTINCT t.id)                                            AS assigned_total,
                        SUM(t.status IN ('resolved','closed'))                          AS resolved_total,
                        SUM(t.sla_rt_breached = 1)                                      AS sla_breached,
                        AVG(CASE WHEN t.resolved_at IS NOT NULL
                                 THEN TIMESTAMPDIFF(SECOND, t.created_at, t.resolved_at) END) AS avg_resolution_sec,
                        AVG(CASE WHEN t.first_response_at IS NOT NULL
                                 THEN TIMESTAMPDIFF(SECOND, t.created_at, t.first_response_at) END) AS avg_frt_sec,
                        COALESCE(SUM(tte.duration_seconds), 0)                          AS total_tracked_sec,
                        COUNT(DISTINCT tte.id)                                          AS total_sessions
                   FROM users u
                   JOIN user_roles ur ON ur.user_id = u.id
                   JOIN roles r       ON r.id = ur.role_id AND r.slug IN ('admin','agent','supervisor')
                   LEFT JOIN tickets t ON t.assigned_agent_id = u.id AND t.deleted_at IS NULL
                   LEFT JOIN ticket_time_entries tte ON tte.user_id = u.id
                  WHERE u.deleted_at IS NULL
                  GROUP BY u.id, r.slug
                  ORDER BY resolved_total DESC, assigned_total DESC"
            )->fetchAll();

            Response::success(data: [
                'items' => array_map(fn($r) => [
                    'id'                => (int) $r['id'],
                    'name'              => trim($r['first_name'] . ' ' . $r['last_name']) ?: $r['name'],
                    'email'             => $r['email'],
                    'role'              => $r['role_slug'],
                    'assigned_total'    => (int) $r['assigned_total'],
                    'resolved_total'    => (int) $r['resolved_total'],
                    'sla_breached'      => (int) $r['sla_breached'],
                    'avg_resolution_sec'=> $r['avg_resolution_sec'] ? (int) $r['avg_resolution_sec'] : null,
                    'avg_frt_sec'       => $r['avg_frt_sec']        ? (int) $r['avg_frt_sec']        : null,
                    'total_tracked_sec' => (int) $r['total_tracked_sec'],
                    'total_sessions'    => (int) $r['total_sessions'],
                ], $rows),
            ]);
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Erro ao gerar relatório de agentes.', statusCode: 500);
        }
    }

    /** GET /api/v1/reports/organizations */
    public function organizations(): never
    {
        try {
            $pdo   = $this->connection->pdo();
            $orgId = $this->managerOrgId();
            $extra = $orgId !== null ? 'AND o.id = :oid' : '';
            $bind  = $orgId !== null ? [':oid' => $orgId] : [];

            $stmt = $pdo->prepare(
                "SELECT o.id, o.name, o.domain,
                        COUNT(DISTINCT t.id)                                AS total_tickets,
                        SUM(t.status IN ('resolved','closed'))              AS resolved,
                        SUM(t.status = 'open')                              AS open_tickets,
                        SUM(t.status = 'in_progress')                       AS in_progress,
                        SUM(t.sla_rt_breached = 1)                          AS sla_breached,
                        AVG(CASE WHEN t.resolved_at IS NOT NULL
                                 THEN TIMESTAMPDIFF(SECOND, t.created_at, t.resolved_at) END) AS avg_resolution_sec,
                        COUNT(DISTINCT uo.user_id)                          AS user_count
                   FROM organizations o
                   LEFT JOIN tickets t ON t.organization_id = o.id AND t.deleted_at IS NULL
                   LEFT JOIN user_organizations uo ON uo.organization_id = o.id
                  WHERE o.deleted_at IS NULL {$extra}
                  GROUP BY o.id
                  ORDER BY total_tickets DESC, o.name ASC"
            );
            $stmt->execute($bind);

            Response::success(data: [
                'items' => array_map(fn($r) => [
                    'id'                 => (int) $r['id'],
                    'name'               => $r['name'],
                    'domain'             => $r['domain'],
                    'total_tickets'      => (int) $r['total_tickets'],
                    'resolved'           => (int) $r['resolved'],
                    'open_tickets'       => (int) $r['open_tickets'],
                    'in_progress'        => (int) $r['in_progress'],
                    'sla_breached'       => (int) $r['sla_breached'],
                    'avg_resolution_sec' => $r['avg_resolution_sec'] ? (int) $r['avg_resolution_sec'] : null,
                    'user_count'         => (int) $r['user_count'],
                ], $stmt->fetchAll()),
            ]);
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Erro ao gerar relatório de empresas.', statusCode: 500);
        }
    }

    /** GET /api/v1/reports/sla */
    public function sla(): never
    {
        try {
            $pdo   = $this->connection->pdo();
            $orgId = $this->managerOrgId();
            $where = $orgId !== null ? 'deleted_at IS NULL AND organization_id = :oid' : 'deleted_at IS NULL';
            $bind  = $orgId !== null ? [':oid' => $orgId] : [];

            $byPriority = $pdo->prepare(
                "SELECT priority,
                        COUNT(*) AS total,
                        SUM(sla_frt_due_at IS NOT NULL)  AS with_frt_sla,
                        SUM(sla_frt_breached = 1)         AS frt_breached,
                        SUM(sla_rt_due_at IS NOT NULL)   AS with_rt_sla,
                        SUM(sla_rt_breached = 1)          AS rt_breached,
                        AVG(CASE WHEN first_response_at IS NOT NULL AND sla_frt_due_at IS NOT NULL
                                 THEN TIMESTAMPDIFF(MINUTE, created_at, first_response_at) END) AS avg_frt_min,
                        AVG(CASE WHEN resolved_at IS NOT NULL AND sla_rt_due_at IS NOT NULL
                                 THEN TIMESTAMPDIFF(MINUTE, created_at, resolved_at) END) AS avg_rt_min
                   FROM tickets WHERE {$where}
                  GROUP BY priority
                  ORDER BY FIELD(priority,'critical','high','medium','low')"
            );
            $byPriority->execute($bind);

            $expWhere = $orgId !== null
                ? 'AND t.deleted_at IS NULL AND t.organization_id = :oid AND t.sla_rt_due_at IS NOT NULL AND t.sla_rt_due_at > UTC_TIMESTAMP() AND t.sla_rt_due_at <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR) AND t.status NOT IN (\'resolved\',\'closed\')'
                : 'AND t.deleted_at IS NULL AND t.sla_rt_due_at IS NOT NULL AND t.sla_rt_due_at > UTC_TIMESTAMP() AND t.sla_rt_due_at <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR) AND t.status NOT IN (\'resolved\',\'closed\')';

            $expiring = $pdo->prepare(
                "SELECT t.id, t.ticket_number, t.subject, t.priority, t.status,
                        t.sla_rt_due_at,
                        TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), t.sla_rt_due_at) AS minutes_left,
                        o.name AS org_name
                   FROM tickets t
                   LEFT JOIN organizations o ON o.id = t.organization_id
                  WHERE 1=1 {$expWhere}
                  ORDER BY t.sla_rt_due_at ASC LIMIT 20"
            );
            $expiring->execute($bind);

            $brWhere = $orgId !== null
                ? 'AND t.deleted_at IS NULL AND t.organization_id = :oid AND t.sla_rt_breached = 1 AND t.status NOT IN (\'resolved\',\'closed\')'
                : 'AND t.deleted_at IS NULL AND t.sla_rt_breached = 1 AND t.status NOT IN (\'resolved\',\'closed\')';

            $breached = $pdo->prepare(
                "SELECT t.id, t.ticket_number, t.subject, t.priority, t.status,
                        t.sla_rt_due_at,
                        TIMESTAMPDIFF(MINUTE, t.sla_rt_due_at, UTC_TIMESTAMP()) AS minutes_overdue,
                        o.name AS org_name
                   FROM tickets t
                   LEFT JOIN organizations o ON o.id = t.organization_id
                  WHERE 1=1 {$brWhere}
                  ORDER BY t.sla_rt_due_at ASC LIMIT 20"
            );
            $breached->execute($bind);

            Response::success(data: [
                'by_priority'   => array_map(fn($r) => [
                    'priority'     => $r['priority'],
                    'total'        => (int) $r['total'],
                    'frt_breached' => (int) $r['frt_breached'],
                    'rt_breached'  => (int) $r['rt_breached'],
                    'with_rt_sla'  => (int) $r['with_rt_sla'],
                    'avg_frt_min'  => $r['avg_frt_min'] ? round((float) $r['avg_frt_min'], 1) : null,
                    'avg_rt_min'   => $r['avg_rt_min']  ? round((float) $r['avg_rt_min'],  1) : null,
                ], $byPriority->fetchAll()),
                'expiring_soon' => array_map(fn($r) => [
                    'id'            => (int) $r['id'],
                    'ticket_number' => $r['ticket_number'],
                    'subject'       => $r['subject'],
                    'priority'      => $r['priority'],
                    'status'        => $r['status'],
                    'sla_rt_due_at' => $r['sla_rt_due_at'],
                    'minutes_left'  => (int) $r['minutes_left'],
                    'org_name'      => $r['org_name'],
                ], $expiring->fetchAll()),
                'breached_open' => array_map(fn($r) => [
                    'id'              => (int) $r['id'],
                    'ticket_number'   => $r['ticket_number'],
                    'subject'         => $r['subject'],
                    'priority'        => $r['priority'],
                    'status'          => $r['status'],
                    'sla_rt_due_at'   => $r['sla_rt_due_at'],
                    'minutes_overdue' => (int) $r['minutes_overdue'],
                    'org_name'        => $r['org_name'],
                ], $breached->fetchAll()),
            ]);
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Erro ao gerar relatório de SLA.', statusCode: 500);
        }
    }

    // ── Export ───────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/reports/{type}/export?format=csv|pdf|txt
     * Requer permissão report:export.
     */
    public function export(string $type): never
    {
        if (!AuthContext::hasPermission('report:export')) {
            Response::error('Sem permissão para exportar relatórios.', statusCode: 403);
        }

        $format = strtolower(trim($_GET['format'] ?? 'csv'));
        if (!in_array($format, ['csv', 'pdf', 'txt'], true)) {
            Response::error('Formato inválido. Use: csv, pdf ou txt.', statusCode: 422);
        }

        if (!in_array($type, ['overview', 'agents', 'organizations', 'sla'], true)) {
            Response::error('Tipo de relatório inválido.', statusCode: 404);
        }

        $t0    = hrtime(true);
        $pdo   = $this->connection->pdo();
        $orgId = $this->managerOrgId();
        $user  = AuthContext::user();

        try {
            [$data, $reportName] = $this->fetchReportData($type, $pdo, $orgId);
            [$columns, $rows]    = $this->buildExportRows($type, $data);

            $generatedBy = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->name;
            $durationMs  = (int) ((hrtime(true) - $t0) / 1_000_000);

            // Auditoria
            $this->logExport($pdo, AuthContext::userId(), $type, $format, $durationMs);

            match ($format) {
                'pdf' => $this->exportService->streamPdf($reportName, $generatedBy, $columns, $rows),
                'txt' => $this->exportService->streamTxt($reportName, $columns, $rows),
                default => $this->exportService->streamCsv($reportName, $columns, $rows),
            };
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Erro ao gerar exportação.', statusCode: 500);
        }
    }

    private function fetchReportData(string $type, \PDO $pdo, ?int $orgId): array
    {
        return match ($type) {
            'overview'      => [$this->queryOverview($pdo, $orgId),      'Visão Geral'],
            'agents'        => [$this->queryAgents($pdo),                 'Desempenho de Agentes'],
            'organizations' => [$this->queryOrganizations($pdo, $orgId), 'Empresas'],
            'sla'           => [$this->querySla($pdo, $orgId),           'Relatório de SLA'],
        };
    }

    private function buildExportRows(string $type, array $data): array
    {
        return match ($type) {
            'overview'      => ReportExportService::overviewToRows($data),
            'agents'        => ReportExportService::agentsToRows($data),
            'organizations' => ReportExportService::organizationsToRows($data),
            'sla'           => ReportExportService::slaToRows($data),
        };
    }

    private function queryOverview(\PDO $pdo, ?int $orgId): array
    {
        $where = $orgId !== null ? 'deleted_at IS NULL AND organization_id = :oid' : 'deleted_at IS NULL';
        $bind  = $orgId !== null ? [':oid' => $orgId] : [];

        $byStatus = $pdo->prepare("SELECT status, COUNT(*) AS total FROM tickets WHERE {$where} GROUP BY status");
        $byStatus->execute($bind);
        $statusMap = []; $grandTotal = 0;
        foreach ($byStatus->fetchAll() as $row) {
            $statusMap[$row['status']] = (int) $row['total'];
            $grandTotal += (int) $row['total'];
        }

        $m = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(sla_rt_breached=1) AS sla_breached,
                    SUM(sla_rt_breached=0 AND sla_rt_due_at IS NOT NULL) AS sla_ok,
                    AVG(CASE WHEN resolved_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND,created_at,resolved_at) END) AS avg_resolution_sec,
                    AVG(CASE WHEN first_response_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND,created_at,first_response_at) END) AS avg_frt_sec,
                    SUM(created_at >= DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY))  AS opened_7d,
                    SUM(resolved_at >= DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)) AS resolved_7d,
                    SUM(created_at >= DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)) AS opened_30d,
                    SUM(resolved_at >= DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)) AS resolved_30d
               FROM tickets WHERE {$where}"
        );
        $m->execute($bind);
        $metrics = $m->fetch();

        $bp = $pdo->prepare(
            "SELECT priority, COUNT(*) AS total, SUM(sla_rt_breached=1) AS breached,
                    SUM(status IN ('resolved','closed')) AS resolved,
                    AVG(CASE WHEN resolved_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND,created_at,resolved_at) END) AS avg_sec
               FROM tickets WHERE {$where} GROUP BY priority
               ORDER BY FIELD(priority,'critical','high','medium','low')"
        );
        $bp->execute($bind);

        $slaTotal      = (int)$metrics['sla_breached'] + (int)$metrics['sla_ok'];
        $slaCompliance = $slaTotal > 0 ? round(100 * (int)$metrics['sla_ok'] / $slaTotal, 1) : null;

        return [
            'total' => $grandTotal, 'by_status' => $statusMap,
            'sla_breached' => (int)$metrics['sla_breached'], 'sla_ok' => (int)$metrics['sla_ok'],
            'sla_compliance' => $slaCompliance,
            'avg_resolution_sec' => $metrics['avg_resolution_sec'] ? (int)$metrics['avg_resolution_sec'] : null,
            'avg_frt_sec'        => $metrics['avg_frt_sec']        ? (int)$metrics['avg_frt_sec']        : null,
            'opened_7d' => (int)$metrics['opened_7d'],   'resolved_7d' => (int)$metrics['resolved_7d'],
            'opened_30d'=> (int)$metrics['opened_30d'],  'resolved_30d'=> (int)$metrics['resolved_30d'],
            'by_priority' => array_map(fn($r) => [
                'priority' => $r['priority'], 'total' => (int)$r['total'],
                'breached' => (int)$r['breached'], 'resolved' => (int)$r['resolved'],
                'avg_sec'  => $r['avg_sec'] ? (int)$r['avg_sec'] : null,
            ], $bp->fetchAll()),
        ];
    }

    private function queryAgents(\PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT u.id, u.first_name, u.last_name, u.name, u.email, r.slug AS role_slug,
                    COUNT(DISTINCT t.id)                                            AS assigned_total,
                    SUM(t.status IN ('resolved','closed'))                          AS resolved_total,
                    SUM(t.sla_rt_breached=1)                                        AS sla_breached,
                    AVG(CASE WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND,t.created_at,t.resolved_at) END) AS avg_resolution_sec,
                    AVG(CASE WHEN t.first_response_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND,t.created_at,t.first_response_at) END) AS avg_frt_sec,
                    COALESCE(SUM(tte.duration_seconds),0) AS total_tracked_sec,
                    COUNT(DISTINCT tte.id) AS total_sessions
               FROM users u
               JOIN user_roles ur ON ur.user_id=u.id
               JOIN roles r ON r.id=ur.role_id AND r.slug IN ('admin','agent','supervisor')
               LEFT JOIN tickets t ON t.assigned_agent_id=u.id AND t.deleted_at IS NULL
               LEFT JOIN ticket_time_entries tte ON tte.user_id=u.id
              WHERE u.deleted_at IS NULL
              GROUP BY u.id, r.slug ORDER BY resolved_total DESC"
        )->fetchAll();

        return ['items' => array_map(fn($r) => [
            'id' => (int)$r['id'],
            'name' => trim(($r['first_name']??'').' '.($r['last_name']??'')) ?: $r['name'],
            'email' => $r['email'], 'role' => $r['role_slug'],
            'assigned_total' => (int)$r['assigned_total'], 'resolved_total' => (int)$r['resolved_total'],
            'sla_breached'   => (int)$r['sla_breached'],
            'avg_resolution_sec' => $r['avg_resolution_sec'] ? (int)$r['avg_resolution_sec'] : null,
            'avg_frt_sec'        => $r['avg_frt_sec']        ? (int)$r['avg_frt_sec']        : null,
            'total_tracked_sec'  => (int)$r['total_tracked_sec'],
            'total_sessions'     => (int)$r['total_sessions'],
        ], $rows)];
    }

    private function queryOrganizations(\PDO $pdo, ?int $orgId): array
    {
        $extra = $orgId !== null ? 'AND o.id = :oid' : '';
        $bind  = $orgId !== null ? [':oid' => $orgId] : [];
        $stmt  = $pdo->prepare(
            "SELECT o.id, o.name, o.domain,
                    COUNT(DISTINCT t.id) AS total_tickets,
                    SUM(t.status IN ('resolved','closed')) AS resolved,
                    SUM(t.status='open')        AS open_tickets,
                    SUM(t.status='in_progress') AS in_progress,
                    SUM(t.sla_rt_breached=1)    AS sla_breached,
                    AVG(CASE WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND,t.created_at,t.resolved_at) END) AS avg_resolution_sec,
                    COUNT(DISTINCT uo.user_id) AS user_count
               FROM organizations o
               LEFT JOIN tickets t ON t.organization_id=o.id AND t.deleted_at IS NULL
               LEFT JOIN user_organizations uo ON uo.organization_id=o.id
              WHERE o.deleted_at IS NULL {$extra}
              GROUP BY o.id ORDER BY total_tickets DESC"
        );
        $stmt->execute($bind);

        return ['items' => array_map(fn($r) => [
            'id' => (int)$r['id'], 'name' => $r['name'], 'domain' => $r['domain'],
            'total_tickets' => (int)$r['total_tickets'], 'resolved' => (int)$r['resolved'],
            'open_tickets'  => (int)$r['open_tickets'],  'in_progress' => (int)$r['in_progress'],
            'sla_breached'  => (int)$r['sla_breached'],
            'avg_resolution_sec' => $r['avg_resolution_sec'] ? (int)$r['avg_resolution_sec'] : null,
            'user_count' => (int)$r['user_count'],
        ], $stmt->fetchAll())];
    }

    private function querySla(\PDO $pdo, ?int $orgId): array
    {
        $where  = $orgId !== null ? 'deleted_at IS NULL AND organization_id = :oid' : 'deleted_at IS NULL';
        $bind   = $orgId !== null ? [':oid' => $orgId] : [];
        $expW   = $orgId !== null ? "AND t.organization_id=:oid" : '';
        $brW    = $expW;

        $bp = $pdo->prepare(
            "SELECT priority, COUNT(*) AS total,
                    SUM(sla_frt_due_at IS NOT NULL) AS with_frt_sla, SUM(sla_frt_breached=1) AS frt_breached,
                    SUM(sla_rt_due_at  IS NOT NULL) AS with_rt_sla,  SUM(sla_rt_breached=1)  AS rt_breached,
                    AVG(CASE WHEN first_response_at IS NOT NULL AND sla_frt_due_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE,created_at,first_response_at) END) AS avg_frt_min,
                    AVG(CASE WHEN resolved_at IS NOT NULL AND sla_rt_due_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE,created_at,resolved_at) END) AS avg_rt_min
               FROM tickets WHERE {$where} GROUP BY priority ORDER BY FIELD(priority,'critical','high','medium','low')"
        );
        $bp->execute($bind);

        $expStmt = $pdo->prepare(
            "SELECT t.id, t.ticket_number, t.subject, t.priority, t.status, t.sla_rt_due_at,
                    TIMESTAMPDIFF(MINUTE,UTC_TIMESTAMP(),t.sla_rt_due_at) AS minutes_left,
                    COALESCE(o.name,'') AS org_name
               FROM tickets t LEFT JOIN organizations o ON o.id=t.organization_id
              WHERE t.deleted_at IS NULL {$expW}
                AND t.sla_rt_due_at IS NOT NULL AND t.sla_rt_due_at > UTC_TIMESTAMP()
                AND t.sla_rt_due_at <= DATE_ADD(UTC_TIMESTAMP(),INTERVAL 24 HOUR)
                AND t.status NOT IN ('resolved','closed') ORDER BY t.sla_rt_due_at ASC LIMIT 50"
        );
        $expStmt->execute($bind);

        $brStmt = $pdo->prepare(
            "SELECT t.id, t.ticket_number, t.subject, t.priority, t.status, t.sla_rt_due_at,
                    TIMESTAMPDIFF(MINUTE,t.sla_rt_due_at,UTC_TIMESTAMP()) AS minutes_overdue,
                    COALESCE(o.name,'') AS org_name
               FROM tickets t LEFT JOIN organizations o ON o.id=t.organization_id
              WHERE t.deleted_at IS NULL {$brW}
                AND t.sla_rt_breached=1 AND t.status NOT IN ('resolved','closed')
              ORDER BY t.sla_rt_due_at ASC LIMIT 50"
        );
        $brStmt->execute($bind);

        return [
            'by_priority'   => array_map(fn($r) => [
                'priority' => $r['priority'], 'total' => (int)$r['total'],
                'frt_breached' => (int)$r['frt_breached'], 'rt_breached' => (int)$r['rt_breached'],
                'with_rt_sla' => (int)$r['with_rt_sla'],
                'avg_frt_min' => $r['avg_frt_min'] ? round((float)$r['avg_frt_min'],1) : null,
                'avg_rt_min'  => $r['avg_rt_min']  ? round((float)$r['avg_rt_min'],1)  : null,
            ], $bp->fetchAll()),
            'expiring_soon' => $expStmt->fetchAll(),
            'breached_open' => $brStmt->fetchAll(),
        ];
    }

    private function logExport(\PDO $pdo, int $userId, string $type, string $format, int $durationMs): void
    {
        try {
            $pdo->prepare(
                "INSERT INTO report_export_logs (user_id, report_type, format, duration_ms, status)
                 VALUES (:uid, :type, :fmt, :dur, 'success')"
            )->execute([':uid' => $userId, ':type' => $type, ':fmt' => $format, ':dur' => $durationMs]);
        } catch (Throwable) {}
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Retorna o org_id se o usuário é gerente, null caso contrário. */
    private function managerOrgId(): ?int
    {
        if (!AuthContext::hasRole('manager')) {
            return null;
        }
        return AuthContext::organizationId();
    }

    private function fillDailyGaps(array $rows, int $days): array
    {
        $map = [];
        foreach ($rows as $r) {
            $map[$r['day']] = ['opened' => (int) $r['opened'], 'resolved' => (int) $r['resolved']];
        }

        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day      = date('Y-m-d', strtotime("-{$i} days"));
            $result[] = [
                'day'      => $day,
                'opened'   => $map[$day]['opened']   ?? 0,
                'resolved' => $map[$day]['resolved']  ?? 0,
            ];
        }

        return $result;
    }
}
