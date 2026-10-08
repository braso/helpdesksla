<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sla;

use App\Core\Database\Connection;
use App\Http\AuthContext;
use App\Http\Response;
use App\Services\Notification\NotificationService;
use Throwable;

/**
 * Gerencia perfis de alerta de SLA e notificações de usuários.
 *
 * Rotas:
 *   GET    /api/v1/sla-alerts              — lista perfis (admin)
 *   POST   /api/v1/sla-alerts              — cria perfil (admin)
 *   PATCH  /api/v1/sla-alerts/{id}         — atualiza perfil (admin)
 *   DELETE /api/v1/sla-alerts/{id}         — remove perfil (admin)
 *   GET    /api/v1/sla-alerts/logs         — logs de notificações (admin)
 *   GET    /api/v1/notifications           — notificações do usuário autenticado
 *   PATCH  /api/v1/notifications/{id}/read — marca lida
 *   POST   /api/v1/notifications/read-all  — marca todas lidas
 */
final class SlaAlertController
{
    public function __construct(
        private readonly Connection           $connection,
        private readonly NotificationService  $notificationService,
    ) {}

    // ── Perfis de alerta ──────────────────────────────────────────────────────

    public function index(): never
    {
        try {
            $pdo  = $this->connection->pdo();
            $rows = $pdo->query(
                "SELECT sap.*,
                        r.name  AS role_name,
                        r.slug  AS role_slug,
                        er.name AS escalation_role_name
                   FROM sla_alert_profiles sap
                   JOIN roles r ON r.id = sap.role_id
                   LEFT JOIN roles er ON er.id = sap.escalation_role_id
                  ORDER BY sap.id ASC"
            )->fetchAll();

            Response::success(data: ['items' => array_map($this->formatProfile(...), $rows)]);
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Erro ao listar perfis de alerta.', statusCode: 500);
        }
    }

    public function store(): never
    {
        $body = $this->parseBody();

        $name      = trim((string) ($body['name'] ?? ''));
        $roleId    = isset($body['role_id'])    ? (int) $body['role_id']    : 0;
        $alertType = $body['alert_type'] ?? 'breached';

        if ($name === '' || $roleId === 0) {
            Response::error('name e role_id são obrigatórios.', statusCode: 422);
        }

        if (!in_array($alertType, ['warning', 'breached', 'critical'], true)) {
            Response::error('alert_type inválido.', statusCode: 422);
        }

        try {
            $pdo = $this->connection->pdo();
            $pdo->prepare(
                "INSERT INTO sla_alert_profiles
                    (name, role_id, alert_type, warning_threshold_minutes,
                     notify_email, notify_internal,
                     escalation_delay_minutes, escalation_role_id, is_active)
                 VALUES
                    (:name, :rid, :atype, :warn,
                     :email, :internal,
                     :esc_delay, :esc_role, 1)"
            )->execute([
                ':name'     => $name,
                ':rid'      => $roleId,
                ':atype'    => $alertType,
                ':warn'     => isset($body['warning_threshold_minutes']) ? (int) $body['warning_threshold_minutes'] : null,
                ':email'    => isset($body['notify_email'])    ? (int)(bool)$body['notify_email']    : 1,
                ':internal' => isset($body['notify_internal']) ? (int)(bool)$body['notify_internal'] : 1,
                ':esc_delay'=> isset($body['escalation_delay_minutes']) ? (int) $body['escalation_delay_minutes'] : null,
                ':esc_role' => isset($body['escalation_role_id']) && $body['escalation_role_id'] ? (int)$body['escalation_role_id'] : null,
            ]);

            $id = (int) $pdo->lastInsertId();
            Response::success(data: ['id' => $id], message: 'Perfil criado.', statusCode: 201);
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Erro ao criar perfil.', statusCode: 500);
        }
    }

    public function update(int $id): never
    {
        $body    = $this->parseBody();
        $allowed = ['name', 'role_id', 'alert_type', 'warning_threshold_minutes',
                    'notify_email', 'notify_internal', 'escalation_delay_minutes',
                    'escalation_role_id', 'is_active'];

        $sets   = [];
        $params = [':id' => $id];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $sets[]           = "`{$field}` = :{$field}";
            $params[":{$field}"] = $body[$field];
        }

        if (empty($sets)) {
            Response::error('Nenhum campo enviado.', statusCode: 422);
        }

        try {
            $this->connection->pdo()
                ->prepare('UPDATE sla_alert_profiles SET ' . implode(', ', $sets) . ' WHERE id = :id')
                ->execute($params);

            Response::success(data: null, message: 'Perfil atualizado.');
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Erro ao atualizar perfil.', statusCode: 500);
        }
    }

    public function destroy(int $id): never
    {
        try {
            $this->connection->pdo()
                ->prepare('DELETE FROM sla_alert_profiles WHERE id = :id')
                ->execute([':id' => $id]);

            Response::success(data: null, message: 'Perfil removido.');
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Erro ao remover perfil.', statusCode: 500);
        }
    }

    // ── Logs de notificações ──────────────────────────────────────────────────

    public function logs(): never
    {
        try {
            $pdo  = $this->connection->pdo();
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $per  = min(100, max(10, (int) ($_GET['per_page'] ?? 30)));
            $off  = ($page - 1) * $per;

            $total = (int) $pdo->query(
                "SELECT COUNT(*) FROM sla_notification_logs"
            )->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT snl.*,
                        t.ticket_number,
                        t.subject AS ticket_subject,
                        u.name    AS notified_user_name,
                        u.email   AS notified_user_email,
                        r.name    AS notified_role_name
                   FROM sla_notification_logs snl
                   JOIN tickets t ON t.id = snl.ticket_id
                   JOIN users   u ON u.id = snl.notified_user_id
                   JOIN roles   r ON r.id = snl.notified_role_id
                  ORDER BY snl.created_at DESC
                  LIMIT :lim OFFSET :off"
            );
            $stmt->bindValue(':lim', $per, \PDO::PARAM_INT);
            $stmt->bindValue(':off', $off, \PDO::PARAM_INT);
            $stmt->execute();

            Response::success(data: [
                'items'     => $stmt->fetchAll(),
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $per,
                'last_page' => max(1, (int) ceil($total / $per)),
            ]);
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Erro ao listar logs.', statusCode: 500);
        }
    }

    // ── Notificações do usuário autenticado ───────────────────────────────────

    public function notifications(): never
    {
        try {
            $limit  = min(50, max(5, (int) ($_GET['limit'] ?? 20)));
            $result = $this->notificationService->getForUser(AuthContext::userId(), $limit);
            Response::success(data: $result);
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Erro ao listar notificações.', statusCode: 500);
        }
    }

    public function markRead(int $id): never
    {
        $this->notificationService->markRead($id, AuthContext::userId());
        Response::success(data: null, message: 'Notificação marcada como lida.');
    }

    public function markAllRead(): never
    {
        $this->notificationService->markAllRead(AuthContext::userId());
        Response::success(data: null, message: 'Todas as notificações marcadas como lidas.');
    }

    // ── Teste de envio de e-mail ──────────────────────────────────────────────

    /**
     * Envia um e-mail de teste com a configuração SMTP salva (banco, com fallback para o .env).
     * O resultado do teste (enviado ou recusado, com o motivo) volta com HTTP 200 e `sent`:
     * uma recusa do servidor é um resultado esperado do teste, não um erro da API.
     */
    public function testEmail(): never
    {
        $body = $this->parseBody();
        $to   = trim((string) ($body['to'] ?? ''));

        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Response::error('Informe um endereço de e-mail válido.', statusCode: 422);
        }

        $smtp = $this->notificationService->smtpSummary();
        if ($smtp['host'] === '') {
            Response::success(data: ['sent' => false, 'to' => $to, 'error' => 'Nenhum servidor SMTP configurado. Preencha e salve a configuração acima.'], message: 'SMTP não configurado.');
        }

        $user = AuthContext::user();
        $html = NotificationService::buildTestEmailBody(
            senderName: $user->name ?? 'Sistema',
            toEmail:    $to,
            smtpHost:   $smtp['host'],
            smtpPort:   (string) $smtp['port'],
        );

        try {
            $sent  = $this->notificationService->notifyEmail($to, $to, '[Helpdesk] Teste de configuração SMTP', $html);
            $error = $sent ? null : 'O servidor SMTP não confirmou o envio.';
        } catch (Throwable $e) {
            $sent  = false;
            $error = $e->getMessage();
            error_log("[testEmail] {$error}");
        }

        $log = $this->notificationService->lastSmtpLog();
        if (!$sent && $error !== null && str_contains($error, 'Não foi possível conectar')) {
            $log = array_merge($log, $this->probePorts($smtp['host'], (int) $smtp['port'], $log ? end($log)['t'] : 0));
        }

        Response::success(
            data:    [
                'sent'   => $sent,
                'to'     => $to,
                'server' => "{$smtp['host']}:{$smtp['port']}",
                'error'  => $error ? $this->explainSmtp($error) : null,
                'log'    => $log,
            ],
            message: $sent ? 'E-mail de teste enviado com sucesso.' : 'O envio de teste falhou.',
        );
    }

    /**
     * Quando a conexão falha, testa as outras portas SMTP comuns (só abre e fecha o TCP,
     * em paralelo, até 4 s) para mostrar qual porta o servidor/rede realmente aceitam.
     *
     * @return list<array{t:int, dir:string, text:string}>
     */
    private function probePorts(string $host, int $failedPort, int $t): array
    {
        $ports   = array_values(array_diff([465, 587, 25, 2525], [$failedPort]));
        $log     = [['t' => $t, 'dir' => 'info', 'text' => 'Testando outras portas SMTP em ' . $host . ': ' . implode(', ', $ports) . '…']];
        $pending = [];
        foreach ($ports as $p) {
            $s = @stream_socket_client("tcp://{$host}:{$p}", $no, $str, 4, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
            if ($s !== false) {
                $pending[$p] = $s;
            }
        }
        $open     = [];
        $deadline = microtime(true) + 4;
        while ($pending && ($left = $deadline - microtime(true)) > 0) {
            $r = null; $w = array_values($pending); $e = null;
            if (!@stream_select($r, $w, $e, 0, (int) ($left * 1_000_000)) || !$w) {
                break;
            }
            foreach ($w as $s) {
                $p = array_search($s, $pending, true);
                // conexão assíncrona "gravável" mas sem par = recusada
                if (stream_socket_get_name($s, true) !== false) {
                    $open[] = $p;
                }
                fclose($s);
                unset($pending[$p]);
            }
        }
        foreach ($pending as $s) {
            fclose($s);
        }
        sort($open);
        foreach ($ports as $p) {
            $log[] = in_array($p, $open, true)
                ? ['t' => $t, 'dir' => 'ok',    'text' => "Porta {$p}: aberta" . ($p === 465 ? ' (use criptografia SSL)' : ($p === 587 ? ' (use criptografia TLS)' : ''))]
                : ['t' => $t, 'dir' => 'error', 'text' => "Porta {$p}: sem resposta ou recusada"];
        }
        if (!$open) {
            $log[] = ['t' => $t, 'dir' => 'warn', 'text' => 'Nenhuma porta respondeu: provável bloqueio de saída na rede/firewall ou endereço do servidor errado.'];
        }
        return $log;
    }

    /** Traduz respostas SMTP comuns em orientação prática. */
    private function explainSmtp(string $error): string
    {
        return match (true) {
            str_contains($error, '535')                => "{$error} — usuário ou senha incorretos. Confira a senha da conta de e-mail.",
            str_contains($error, '435') || str_contains($error, '454') => "{$error} — o servidor recusou a autenticação temporariamente (conta bloqueada, IP não liberado no provedor ou excesso de tentativas). Confira a conta no painel do provedor e tente de novo em alguns minutos.",
            str_contains($error, '550') && str_contains($error, 'destinatário') => "{$error} — o endereço de destino não existe ou não aceita mensagens.",
            str_contains($error, 'getaddrinfo')        => "{$error} — o nome do servidor não foi encontrado. Confira o endereço do SMTP.",
            str_contains($error, 'timed out') || str_contains($error, 'Tempo esgotado') => "{$error} — o servidor não respondeu. Confira porta e criptografia (465 = SSL, 587 = TLS) ou se o firewall libera a saída.",
            default => $error,
        };
    }

    // ── Roles disponíveis para seleção ────────────────────────────────────────

    public function roles(): never
    {
        try {
            $rows = $this->connection->pdo()->query(
                "SELECT id, name, slug FROM roles ORDER BY name ASC"
            )->fetchAll();

            Response::success(data: ['items' => $rows]);
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Erro ao listar papéis.', statusCode: 500);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function formatProfile(array $r): array
    {
        return [
            'id'                        => (int)  $r['id'],
            'name'                      => $r['name'],
            'role_id'                   => (int)  $r['role_id'],
            'role_name'                 => $r['role_name'],
            'role_slug'                 => $r['role_slug'],
            'alert_type'                => $r['alert_type'],
            'warning_threshold_minutes' => $r['warning_threshold_minutes'] !== null ? (int)$r['warning_threshold_minutes'] : null,
            'notify_email'              => (bool) $r['notify_email'],
            'notify_internal'           => (bool) $r['notify_internal'],
            'escalation_delay_minutes'  => $r['escalation_delay_minutes'] !== null ? (int)$r['escalation_delay_minutes'] : null,
            'escalation_role_id'        => $r['escalation_role_id']       !== null ? (int)$r['escalation_role_id']       : null,
            'escalation_role_name'      => $r['escalation_role_name'],
            'is_active'                 => (bool) $r['is_active'],
        ];
    }

    private function parseBody(): array
    {
        return (array) (json_decode(file_get_contents('php://input') ?: '{}', true) ?? []);
    }
}
