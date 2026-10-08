<?php

declare(strict_types=1);

namespace App\Services\Ticket;

use App\Core\EventDispatcher;
use App\Events\TicketAssignedEvent;
use App\Events\TicketStatusChangedEvent;
use App\Events\TicketCreatedEvent;
use App\Exceptions\AuthorizationException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Http\AuthContext;
use App\Models\Ticket;
use App\Repositories\Contracts\TicketRepositoryInterface;
use App\Services\Sla\SlaService;
use App\Support\Validator;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class TicketService
{
    private const ALLOWED_PRIORITIES = ['low', 'medium', 'high', 'critical'];
    private const ALLOWED_SOURCES    = ['web', 'email', 'api', 'whatsapp', 'phone'];
    private const ALLOWED_TYPES      = ['question', 'incident', 'problem', 'task'];

    public function __construct(
        private readonly TicketRepositoryInterface $ticketRepository,
        private readonly SlaService               $slaService,
        private readonly EventDispatcher          $events,
    ) {}

    /**
     * Abre um novo ticket aplicando todas as regras de negócio:
     *   - Validação de campos obrigatórios e enumerações
     *   - Sanitização e normalização dos dados
     *   - Definição de valores padrão do sistema (status, timestamps)
     *   - Cálculo de deadlines de SLA (via SlaService)
     *   - Despacho de TicketCreatedEvent (aciona auditoria + automações)
     *
     * @throws ValidationException
     * @throws RuntimeException
     */
    public function openTicket(array $requestData): Ticket
    {
        $this->validate($requestData);

        // Auto-atribui empresa do usuário logado se não informada explicitamente
        if (empty($requestData['organization_id']) && !AuthContext::hasAnyRole(['admin', 'agent', 'supervisor'])) {
            $orgId = AuthContext::organizationId();
            if ($orgId !== null) {
                $requestData['organization_id'] = $orgId;
            }
        }

        $now  = $this->nowUtc();
        $data = $this->buildTicketData($requestData, $now);

        // Calcula deadlines de SLA e mescla no payload antes de persistir
        $slaData = $this->slaService->calculateForTicket(
            priority:       $data['priority'],
            organizationId: $data['organization_id'],
            createdAt:      $now,
        );
        $data = array_merge($data, $slaData);

        $ticketId = $this->ticketRepository->create($data);
        $ticket   = $this->ticketRepository->findById($ticketId);

        if ($ticket === null) {
            throw new RuntimeException("Ticket #{$ticketId} não encontrado após a criação.");
        }

        $actorId = AuthContext::isAuthenticated() ? AuthContext::userId() : $ticket->requesterId;

        // Listeners de TicketCreatedEvent:
        //   AuditTicketHistory → grava ticket_history
        //   RunAutomations     → avalia e executa automações de 'ticket_created'
        $this->events->dispatch(new TicketCreatedEvent($ticket, $actorId));

        return $ticket;
    }

    /**
     * Lista tickets com filtros, escopo de fila, ordenação e paginação.
     * Clientes e gerentes só veem tickets da própria empresa (ou os próprios, sem empresa).
     * A resposta inclui contagens por escopo para as abas da fila.
     *
     * @return array{data: Ticket[], total: int, page: int, per_page: int, last_page: int, counts: array<string,int>}
     */
    public function listTickets(array $queryParams): array
    {
        $filters  = [];
        $page     = max(1, (int) ($queryParams['page']     ?? 1));
        $perPage  = min(100, max(1, (int) ($queryParams['per_page'] ?? 20)));
        $isStaff  = AuthContext::hasAnyRole(['admin', 'agent', 'supervisor']);

        $allowed = ['status', 'priority', 'assigned_agent_id', 'requester_id', 'team_id'];
        foreach ($allowed as $key) {
            if (!empty($queryParams[$key])) {
                $filters[$key] = $queryParams[$key];
            }
        }

        if (!empty($queryParams['search'])) {
            $filters['search'] = $queryParams['search'];
        }

        // Clientes só veem tickets da sua empresa; sem empresa, só os próprios.
        if (!$isStaff) {
            $orgId = AuthContext::organizationId();
            if ($orgId !== null) {
                $filters['organization_id'] = $orgId;
            } else {
                $filters['requester_id'] = AuthContext::userId();
            }
        } elseif (!empty($queryParams['organization_id'])) {
            $filters['organization_id'] = (int) $queryParams['organization_id'];
        }

        $baseFilters = $filters;
        $scopes      = $isStaff ? ['queue', 'mine', 'unassigned', 'all'] : ['queue', 'done'];
        $scope       = in_array($queryParams['scope'] ?? '', $scopes, true) ? $queryParams['scope'] : 'all';

        $filters['scope'] = $scope;
        if ($scope === 'mine') {
            $filters['assigned_agent_id'] = AuthContext::userId();
        }

        $filters['sort'] = in_array($queryParams['sort'] ?? '', ['sla', 'updated', 'created', 'priority', 'number'], true)
            ? $queryParams['sort'] : 'sla';
        $filters['dir']  = ($queryParams['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $result = $this->ticketRepository->findAll($filters, $page, $perPage);
        $result['counts'] = $this->ticketRepository->countByScopes($baseFilters, $scopes, AuthContext::userId());

        return $result;
    }

    /**
     * Retorna um ticket pelo ID.
     * Solicitantes só podem ver seus próprios tickets.
     *
     * @throws NotFoundException
     * @throws AuthorizationException
     */
    public function getTicket(int $id): Ticket
    {
        $ticket = $this->ticketRepository->findById($id);

        if ($ticket === null) {
            throw new NotFoundException('Ticket');
        }

        if (!AuthContext::hasAnyRole(['admin', 'agent', 'supervisor'])) {
            $userId = AuthContext::userId();
            $orgId  = AuthContext::organizationId();
            $canSee = $ticket->requesterId === $userId
                   || ($orgId !== null && $ticket->organizationId === $orgId);
            if (!$canSee) {
                throw new AuthorizationException('Acesso negado a este ticket.');
            }
        }

        return $ticket;
    }

    /**
     * Atualiza campos de um ticket existente.
     * Campos aceitos: status, priority, assigned_agent_id, team_id, category_id.
     *
     * @throws NotFoundException
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function updateTicket(int $id, array $requestData): Ticket
    {
        $ticket = $this->ticketRepository->findById($id);

        if ($ticket === null) {
            throw new NotFoundException('Ticket');
        }

        $this->validateUpdate($requestData);

        $now     = $this->nowUtc();
        $updates = ['updated_at' => $now];

        if (isset($requestData['status'])) {
            $updates['status'] = $requestData['status'];
            if ($requestData['status'] === 'resolved') {
                $updates['resolved_at'] = $now;
            } elseif ($requestData['status'] === 'closed') {
                $updates['closed_at'] = $now;
            }
        }

        foreach (['priority', 'type', 'assigned_agent_id', 'team_id', 'category_id'] as $field) {
            if (array_key_exists($field, $requestData)) {
                $updates[$field] = $requestData[$field] === '' ? null : $requestData[$field];
            }
        }

        $this->ticketRepository->update($id, $updates);

        $updated = $this->ticketRepository->findById($id);

        if ($updated === null) {
            throw new RuntimeException("Ticket #{$id} não encontrado após atualização.");
        }

        $actorId = AuthContext::isAuthenticated() ? AuthContext::userId() : $ticket->requesterId;

        if (isset($requestData['status']) && $requestData['status'] !== $ticket->status) {
            $this->events->dispatch(new TicketStatusChangedEvent($updated, $ticket->status, $updated->status, $actorId));
        }

        if ($updated->assignedAgentId !== null && $updated->assignedAgentId !== $ticket->assignedAgentId) {
            $this->events->dispatch(new TicketAssignedEvent($updated, $ticket->assignedAgentId, $actorId));
        }

        return $updated;
    }

    /**
     * Fecha o ticket e salva a avaliação do solicitante (1–5 estrelas).
     * Apenas o próprio solicitante pode avaliar; ticket já fechado não pode ser avaliado novamente.
     *
     * @throws NotFoundException
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function rateTicket(int $id, int $rating, string $comment): Ticket
    {
        $ticket = $this->ticketRepository->findById($id);

        if ($ticket === null) {
            throw new NotFoundException('Ticket');
        }

        $userId = AuthContext::userId();
        if ($ticket->requesterId !== $userId) {
            throw new AuthorizationException('Apenas o solicitante pode avaliar o atendimento.');
        }

        if ($ticket->status === 'closed') {
            throw new ValidationException(['rating' => ['Este ticket já foi fechado e avaliado.']]);
        }

        if ($rating < 1 || $rating > 5) {
            throw new ValidationException(['rating' => ['A nota deve ser entre 1 e 5.']]);
        }

        $now = $this->nowUtc();
        $this->ticketRepository->update($id, [
            'status'         => 'closed',
            'closed_at'      => $now,
            'rating'         => $rating,
            'rating_comment' => $comment !== '' ? $comment : null,
            'rated_at'       => $now,
            'updated_at'     => $now,
        ]);

        $updated = $this->ticketRepository->findById($id);
        if ($updated === null) {
            throw new RuntimeException("Ticket #{$id} não encontrado após avaliação.");
        }

        $this->events->dispatch(new TicketStatusChangedEvent($updated, $ticket->status, 'closed', $userId));

        return $updated;
    }

    /** @throws ValidationException */
    private function validateUpdate(array $data): void
    {
        $allowedStatuses = ['open', 'pending', 'in_progress', 'resolved', 'closed'];

        $validator = (new Validator())
            ->enum('status',   $data['status']   ?? null, $allowedStatuses)
            ->enum('priority', $data['priority'] ?? null, self::ALLOWED_PRIORITIES)
            ->enum('type',     $data['type']     ?? null, self::ALLOWED_TYPES)
            ->positiveInteger('assigned_agent_id', $data['assigned_agent_id'] ?? null)
            ->positiveInteger('team_id',           $data['team_id']           ?? null)
            ->positiveInteger('category_id',       $data['category_id']       ?? null);

        if (!$validator->passes()) {
            throw new ValidationException($validator->errors());
        }
    }

    /** @throws ValidationException */
    private function validate(array $data): void
    {
        $validator = (new Validator())
            ->required('subject',      $data['subject']      ?? null)
            ->required('description',  $data['description']  ?? null)
            ->required('requester_id', $data['requester_id'] ?? null)
            ->maxLength('subject', (string) ($data['subject'] ?? ''), 500)
            ->enum('priority', $data['priority'] ?? null, self::ALLOWED_PRIORITIES)
            ->enum('source',   $data['source']   ?? null, self::ALLOWED_SOURCES)
            ->enum('type',     $data['type']     ?? null, self::ALLOWED_TYPES)
            ->positiveInteger('requester_id',     $data['requester_id']     ?? null)
            ->positiveInteger('assigned_agent_id', $data['assigned_agent_id'] ?? null)
            ->positiveInteger('category_id',      $data['category_id']      ?? null)
            ->positiveInteger('organization_id',  $data['organization_id']  ?? null);

        if (!$validator->passes()) {
            throw new ValidationException($validator->errors());
        }
    }

    /** @return array<string, mixed> */
    private function buildTicketData(array $requestData, string $now): array
    {
        return [
            'uuid'               => $this->generateUuid(),
            'subject'            => trim((string) $requestData['subject']),
            'description'        => trim((string) $requestData['description']),
            'requester_id'       => (int) $requestData['requester_id'],
            'assigned_agent_id'  => isset($requestData['assigned_agent_id'])
                                        ? (int) $requestData['assigned_agent_id'] : null,
            'team_id'            => isset($requestData['team_id'])
                                        ? (int) $requestData['team_id'] : null,
            'organization_id'    => isset($requestData['organization_id'])
                                        ? (int) $requestData['organization_id'] : null,
            'category_id'        => isset($requestData['category_id'])
                                        ? (int) $requestData['category_id'] : null,
            'status'             => 'open',     // Status inicial fixo — não delegável ao cliente
            'priority'           => $requestData['priority'] ?? 'medium',
            'source'             => $requestData['source']   ?? 'web',
            'type'               => $requestData['type']     ?? 'question',
            'custom_fields'      => $requestData['custom_fields'] ?? null,
            'metadata'           => $requestData['metadata']      ?? null,
            'created_at'         => $now,
            'updated_at'         => $now,
        ];
    }

    private function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function generateUuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
