<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Connection;
use App\Models\Ticket;
use App\Repositories\Contracts\TicketRepositoryInterface;
use PDOException;
use RuntimeException;

final class TicketRepository implements TicketRepositoryInterface
{
    /**
     * SELECT base com nomes de solicitante, responsável e empresa, mais a prévia
     * da última resposta pública — a interface mostra pessoas, não IDs.
     */
    private const SELECT_WITH_NAMES = <<<SQL
        SELECT t.*,
               ru.name AS requester_name,
               au.name AS agent_name,
               o.name  AS organization_name,
               (SELECT LEFT(r.body, 160) FROM ticket_replies r
                 WHERE r.ticket_id = t.id AND r.deleted_at IS NULL AND r.is_private = 0
                 ORDER BY r.id DESC LIMIT 1) AS last_reply_preview
          FROM tickets t
          LEFT JOIN users ru         ON ru.id = t.requester_id
          LEFT JOIN users au         ON au.id = t.assigned_agent_id
          LEFT JOIN organizations o  ON o.id  = t.organization_id
        SQL;

    public function __construct(
        private readonly Connection $connection
    ) {}

    /**
     * Cria um ticket em uma transação de duas etapas:
     *
     *  1. INSERT com placeholder único (evita violar NOT NULL + UNIQUE antes de termos o ID).
     *  2. UPDATE com o número formatado (TKT-YYYY-NNNNNN), gerado a partir do auto-increment ID.
     *
     * A atomicidade garante que nenhum registro fique com o placeholder visível
     * em caso de falha entre os dois statements.
     */
    public function create(array $data): int
    {
        $pdo = $this->connection->pdo();

        try {
            $pdo->beginTransaction();

            // Placeholder único para satisfazer NOT NULL + UNIQUE durante a janela de transação.
            // Em produção de alto volume, substituir por uma tabela de sequências dedicada
            // com SELECT ... FOR UPDATE, eliminando qualquer chance (já remota) de colisão.
            $placeholder = 'TMP-' . strtoupper(bin2hex(random_bytes(6)));

            $sql = <<<SQL
                INSERT INTO tickets (
                    uuid, ticket_number, subject, description,
                    requester_id, assigned_agent_id, team_id,
                    organization_id, category_id, sla_policy_id,
                    status, priority, source, type,
                    sla_frt_due_at, sla_rt_due_at,
                    custom_fields, metadata,
                    created_at, updated_at
                ) VALUES (
                    :uuid, :ticket_number, :subject, :description,
                    :requester_id, :assigned_agent_id, :team_id,
                    :organization_id, :category_id, :sla_policy_id,
                    :status, :priority, :source, :type,
                    :sla_frt_due_at, :sla_rt_due_at,
                    :custom_fields, :metadata,
                    :created_at, :updated_at
                )
            SQL;

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':uuid'              => $data['uuid'],
                ':ticket_number'     => $placeholder,
                ':subject'           => $data['subject'],
                ':description'       => $data['description'],
                ':requester_id'      => $data['requester_id'],
                ':assigned_agent_id' => $data['assigned_agent_id'] ?? null,
                ':team_id'           => $data['team_id']           ?? null,
                ':organization_id'   => $data['organization_id']   ?? null,
                ':category_id'       => $data['category_id']       ?? null,
                ':sla_policy_id'     => $data['sla_policy_id']     ?? null,
                ':status'            => $data['status'],
                ':priority'          => $data['priority'],
                ':source'            => $data['source'],
                ':type'              => $data['type'],
                ':sla_frt_due_at'    => $data['sla_frt_due_at']    ?? null,
                ':sla_rt_due_at'     => $data['sla_rt_due_at']     ?? null,
                ':custom_fields'     => isset($data['custom_fields'])
                                            ? json_encode($data['custom_fields'], JSON_THROW_ON_ERROR)
                                            : null,
                ':metadata'          => isset($data['metadata'])
                                            ? json_encode($data['metadata'], JSON_THROW_ON_ERROR)
                                            : null,
                ':created_at'        => $data['created_at'],
                ':updated_at'        => $data['updated_at'],
            ]);

            $id = (int) $pdo->lastInsertId();

            // O número legível só pode ser gerado após obtermos o ID auto-increment.
            $ticketNumber = $this->formatTicketNumber($id, $data['created_at']);

            $pdo->prepare('UPDATE tickets SET ticket_number = :number WHERE id = :id')
                ->execute([':number' => $ticketNumber, ':id' => $id]);

            $pdo->commit();

            return $id;

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException(
                'Falha ao persistir ticket no banco de dados.',
                previous: $e
            );
        }
    }

    public function findById(int $id): ?Ticket
    {
        $stmt = $this->connection->pdo()->prepare(
            self::SELECT_WITH_NAMES . ' WHERE t.id = :id AND t.deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();

        return $row !== false ? Ticket::fromArray($row) : null;
    }

    /**
     * Lista tickets com filtros opcionais e paginação por offset.
     *
     * Filtros suportados: status, priority, assigned_agent_id, requester_id, team_id,
     * organization_id, search, scope (queue|mine|unassigned|done|all).
     * Ordenação: sort (sla|updated|created|priority|number) + dir (asc|desc).
     */
    public function findAll(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        [$whereClause, $params] = $this->buildWhere($filters);
        $pdo = $this->connection->pdo();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tickets t WHERE {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset   = ($page - 1) * $perPage;
        $orderBy  = $this->buildOrderBy($filters['sort'] ?? 'sla', $filters['dir'] ?? 'asc');
        $dataStmt = $pdo->prepare(
            self::SELECT_WITH_NAMES . " WHERE {$whereClause} ORDER BY {$orderBy} LIMIT :limit OFFSET :offset"
        );

        foreach ($params as $key => $value) {
            $dataStmt->bindValue($key, $value);
        }
        $dataStmt->bindValue(':limit',  $perPage, \PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        $dataStmt->execute();

        return [
            'data'      => array_map(
                static fn(array $row) => Ticket::fromArray($row),
                $dataStmt->fetchAll()
            ),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * Conta tickets por escopo (abas da fila) sobre os mesmos filtros-base.
     *
     * @param  string[] $scopes
     * @return array<string, int>
     */
    public function countByScopes(array $baseFilters, array $scopes, ?int $userId): array
    {
        $counts = [];
        foreach ($scopes as $scope) {
            $filters = $baseFilters;
            $filters['scope'] = $scope;
            if ($scope === 'mine') {
                $filters['assigned_agent_id'] = $userId;
            }
            [$whereClause, $params] = $this->buildWhere($filters);
            $stmt = $this->connection->pdo()->prepare("SELECT COUNT(*) FROM tickets t WHERE {$whereClause}");
            $stmt->execute($params);
            $counts[$scope] = (int) $stmt->fetchColumn();
        }
        return $counts;
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function buildWhere(array $filters): array
    {
        $where  = ['t.deleted_at IS NULL'];
        $params = [];

        foreach (['status', 'priority', 'assigned_agent_id', 'requester_id', 'team_id', 'organization_id'] as $col) {
            if (isset($filters[$col])) {
                $where[]           = "t.`{$col}` = :{$col}";
                $params[":{$col}"] = $filters[$col];
            }
        }

        $where[] = match ($filters['scope'] ?? 'all') {
            'queue', 'mine' => "t.status NOT IN ('resolved','closed')",
            'unassigned'    => "t.status NOT IN ('resolved','closed') AND t.assigned_agent_id IS NULL",
            'done'          => "t.status IN ('resolved','closed')",
            default         => '1=1',
        };

        if (!empty($filters['search'])) {
            $where[]           = '(LOWER(t.subject) LIKE :search OR t.ticket_number LIKE :search_num)';
            $params[':search']     = '%' . mb_strtolower((string) $filters['search']) . '%';
            $params[':search_num'] = '%' . strtoupper((string) $filters['search']) . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    /** Colunas de ordenação vêm de um mapa fixo — nunca do input. */
    private function buildOrderBy(string $sort, string $dir): string
    {
        $dir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';

        // Próximo prazo relevante: 1ª resposta enquanto ela não aconteceu, senão resolução.
        $nextDue = "CASE WHEN t.first_response_at IS NULL AND t.sla_frt_due_at IS NOT NULL
                         THEN LEAST(t.sla_frt_due_at, COALESCE(t.sla_rt_due_at, t.sla_frt_due_at))
                         ELSE t.sla_rt_due_at END";

        return match ($sort) {
            'updated'  => "t.updated_at {$dir}, t.id DESC",
            'created'  => "t.created_at {$dir}, t.id DESC",
            'number'   => "t.id {$dir}",
            'priority' => "FIELD(t.priority,'critical','high','medium','low') {$dir}, t.id DESC",
            default    => "(t.status IN ('resolved','closed')) ASC, ({$nextDue}) IS NULL ASC, ({$nextDue}) {$dir}, t.id DESC",
        };
    }

    public function findByUuid(string $uuid): ?Ticket
    {
        $stmt = $this->connection->pdo()->prepare(
            self::SELECT_WITH_NAMES . ' WHERE t.uuid = :uuid AND t.deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([':uuid' => $uuid]);

        $row = $stmt->fetch();

        return $row !== false ? Ticket::fromArray($row) : null;
    }

    /**
     * Atualiza campos específicos de um ticket.
     *
     * Apenas campos do whitelist são aceitos — chaves de $data que não estejam
     * na lista são ignoradas silenciosamente, garantindo que nenhuma coluna
     * arbitrária seja gravada mesmo que dados externos cheguem aqui.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $allowed = [
            'status', 'priority', 'type', 'assigned_agent_id', 'team_id', 'category_id',
            'sla_policy_id', 'sla_frt_due_at', 'sla_rt_due_at',
            'sla_frt_breached', 'sla_rt_breached',
            'first_response_at', 'resolved_at', 'closed_at',
            'rating', 'rating_comment', 'rated_at',
            'ticket_number', 'updated_at',
        ];

        $filtered = array_intersect_key($data, array_flip($allowed));

        if (empty($filtered)) {
            return;
        }

        // Backtick nos nomes de coluna é seguro porque vêm exclusivamente do whitelist acima.
        $setParts = array_map(static fn(string $col) => "`{$col}` = :{$col}", array_keys($filtered));
        $filtered[':id'] = $id;

        $this->connection->pdo()
            ->prepare('UPDATE tickets SET ' . implode(', ', $setParts) . ' WHERE id = :id')
            ->execute($filtered);
    }

    /**
     * Marca breach de SLA em lote — executado pelo CheckSlaBreachesJob a cada 5 minutos.
     * Dois UPDATEs independentes para FRT e RT evitam full-table scan desnecessário.
     *
     * @return array{frt: int, rt: int} Número de tickets marcados em cada categoria
     */
    public function markSlaBreaches(): array
    {
        $pdo = $this->connection->pdo();

        $frtStmt = $pdo->prepare(
            "UPDATE tickets
                SET sla_frt_breached = 1, updated_at = UTC_TIMESTAMP()
              WHERE sla_frt_due_at < UTC_TIMESTAMP()
                AND sla_frt_breached = 0
                AND first_response_at IS NULL
                AND status NOT IN ('resolved','closed')
                AND deleted_at IS NULL"
        );
        $frtStmt->execute();

        $rtStmt = $pdo->prepare(
            "UPDATE tickets
                SET sla_rt_breached = 1, updated_at = UTC_TIMESTAMP()
              WHERE sla_rt_due_at < UTC_TIMESTAMP()
                AND sla_rt_breached = 0
                AND status NOT IN ('resolved','closed')
                AND deleted_at IS NULL"
        );
        $rtStmt->execute();

        return [
            'frt' => $frtStmt->rowCount(),
            'rt'  => $rtStmt->rowCount(),
        ];
    }

    /** Formato: TKT-2024-000001 (máx. 15 chars, dentro do VARCHAR(20) definido no schema). */
    private function formatTicketNumber(int $id, string $createdAt): string
    {
        $year = (int) substr($createdAt, 0, 4);

        return sprintf('TKT-%d-%06d', $year, $id);
    }
}
