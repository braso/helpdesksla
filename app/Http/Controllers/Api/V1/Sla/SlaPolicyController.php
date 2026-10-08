<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sla;

use App\Http\Response;
use App\Services\Sla\SlaHoursSettings;
use App\Services\Sla\SlaPolicyService;

/**
 * Tela "Políticas de SLA": planos, empresas vinculadas e horário comercial.
 * Erros de validação/404 sobem como exceção e o Router devolve 422/404.
 */
final class SlaPolicyController
{
    public function __construct(
        private readonly SlaPolicyService $policies,
        private readonly SlaHoursSettings $hours,
    ) {}

    public function index(): never
    {
        Response::success(data: [
            'policies'      => $this->policies->list(),
            'organizations' => $this->policies->organizations(),
            'hours'         => $this->hours->effective(),
        ]);
    }

    public function store(): never
    {
        Response::success(data: $this->policies->create($this->body()), message: 'Política de SLA criada.', statusCode: 201);
    }

    public function update(int $id): never
    {
        Response::success(data: $this->policies->update($id, $this->body()), message: 'Política de SLA salva.');
    }

    public function destroy(int $id): never
    {
        $moved = $this->policies->delete($id);
        Response::success(data: ['moved_organizations' => $moved],
            message: $moved ? "Política excluída. {$moved} empresa(s) passaram a usar a política padrão." : 'Política excluída.');
    }

    public function makeDefault(int $id): never
    {
        Response::success(data: $this->policies->setDefault($id), message: 'Política definida como padrão.');
    }

    public function assignOrganization(int $orgId): never
    {
        $b = $this->body();
        $pid = isset($b['sla_policy_id']) && $b['sla_policy_id'] !== null && $b['sla_policy_id'] !== '' ? (int) $b['sla_policy_id'] : null;
        $this->policies->assignOrganization($orgId, $pid);
        Response::success(data: null, message: 'SLA da empresa atualizado.');
    }

    public function saveHours(): never
    {
        Response::success(data: $this->hours->save($this->body()), message: 'Horário comercial salvo.');
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $raw = (string) file_get_contents('php://input');
        $d = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($d)) {
            Response::error('JSON inválido.', statusCode: 400);
        }
        return $d;
    }
}
