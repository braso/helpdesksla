<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Http\AuthContext;
use App\Http\Response;
use App\Repositories\OrganizationRepository;
use App\Core\Database\Connection;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Throwable;

final class OrganizationController
{
    public function __construct(
        private readonly OrganizationRepository $orgRepository,
        private readonly Connection             $connection,
    ) {}

    /** GET /api/v1/organizations/public — sem autenticação */
    public function listPublic(): never
    {
        Response::success(data: ['items' => $this->orgRepository->findAllPublic()]);
    }

    /** GET /api/v1/organizations */
    public function index(): never
    {
        $orgs = $this->orgRepository->findAll();
        Response::success(data: [
            'items' => array_map(static fn($o) => $o->toArray(), $orgs),
            'total' => count($orgs),
        ]);
    }

    /** POST /api/v1/organizations */
    public function store(): never
    {
        $data = $this->parseBody();

        if (empty($data['name'])) {
            Response::error('Validation failed.', errors: ['name' => ['O campo name é obrigatório.']], statusCode: 422);
        }

        try {
            $now  = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $uuid = $this->generateUuid();

            $id  = $this->orgRepository->create([
                'uuid'       => $uuid,
                'name'       => trim((string) $data['name']),
                'domain'     => $data['domain'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $org = $this->orgRepository->findById($id);

            Response::success(data: $org->toArray(), message: 'Empresa cadastrada com sucesso.', statusCode: 201);
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Ocorreu um erro interno.', statusCode: 500);
        }
    }

    /** GET /api/v1/organizations/{id} */
    public function show(int $id): never
    {
        $org = $this->orgRepository->findById($id);
        if ($org === null) {
            Response::error('Empresa não encontrada.', statusCode: 404);
        }
        Response::success(data: $org->toArray());
    }

    /** PATCH /api/v1/organizations/{id} */
    public function update(int $id): never
    {
        $org = $this->orgRepository->findById($id);
        if ($org === null) {
            Response::error('Empresa não encontrada.', statusCode: 404);
        }

        $data = $this->parseBody();

        $name = trim((string) ($data['name'] ?? $org->name));
        if ($name === '') {
            Response::error('Validation failed.', errors: ['name' => ['A razão social é obrigatória.']], statusCode: 422);
        }

        $allowed = [
            'name', 'trade_name', 'cnpj', 'ie', 'im', 'phone', 'email', 'website',
            'domain', 'is_active', 'notes',
            'address_zip', 'address_street', 'address_number', 'address_complement',
            'address_neighborhood', 'address_city', 'address_state', 'address_country',
        ];

        $updateData = ['name' => $name];
        foreach ($allowed as $field) {
            if ($field === 'name') {
                continue;
            }
            if (array_key_exists($field, $data)) {
                if ($field === 'is_active') {
                    $updateData[$field] = (int) (bool) $data[$field];
                } else {
                    $val = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
                    $updateData[$field] = ($val === '' || $val === null) ? null : $val;
                }
            }
        }

        try {
            $this->orgRepository->update($id, $updateData);
            $updated = $this->orgRepository->findById($id);
            Response::success(data: $updated->toArray(), message: 'Empresa atualizada com sucesso.');
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Ocorreu um erro interno.', statusCode: 500);
        }
    }

    /** GET /api/v1/organizations/{id}/sla */
    public function getSla(int $id): never
    {
        $org = $this->orgRepository->findById($id);
        if ($org === null) {
            Response::error('Empresa não encontrada.', statusCode: 404);
        }

        // Gerente só acessa SLA da própria empresa
        if (AuthContext::hasRole('manager') && AuthContext::organizationId() !== $id) {
            Response::error('Acesso negado.', statusCode: 403);
        }

        $sla = $this->orgRepository->findSlaByOrg($id);
        Response::success(data: $sla ?? $this->defaultSla($org->name));
    }

    /** PUT /api/v1/organizations/{id}/sla */
    public function saveSla(int $id): never
    {
        $org = $this->orgRepository->findById($id);
        if ($org === null) {
            Response::error('Empresa não encontrada.', statusCode: 404);
        }

        $data = $this->parseBody();

        try {
            $this->orgRepository->saveSla($id, $data);
            $sla = $this->orgRepository->findSlaByOrg($id);
            Response::success(data: $sla, message: 'SLA atualizado com sucesso.');
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Ocorreu um erro interno.', statusCode: 500);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function parseBody(): array
    {
        $raw = (string) file_get_contents('php://input');
        if ($raw === '') {
            Response::error('Corpo vazio.', statusCode: 400);
        }
        try {
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Response::error('JSON inválido.', statusCode: 400);
        }
        if (!is_array($data)) {
            Response::error('JSON deve ser objeto.', statusCode: 400);
        }
        return $data;
    }

    private function defaultSla(string $orgName): array
    {
        return [
            'id'                  => null,
            'name'                => 'SLA ' . $orgName,
            // Média de mercado 8x5 (mesmos valores da política padrão, migration 007)
            'frt_low'             => 540,
            'frt_medium'          => 240,
            'frt_high'            => 60,
            'frt_critical'        => 30,
            'rt_low'              => 2700,
            'rt_medium'           => 1080,
            'rt_high'             => 540,
            'rt_critical'         => 240,
            'business_hours_only' => 1,
        ];
    }

    private function generateUuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
