<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Ai;

use App\Exceptions\NotFoundException;
use App\Http\AuthContext;
use App\Http\Response;
use App\Services\Ai\AiAssistantService;
use App\Services\Ai\AiException;
use App\Services\Ai\GeminiClient;
use App\Services\Ai\AiSettings;
use Throwable;

/**
 * Rotas:
 *   GET   /api/v1/settings/ai          — configuração (admin; sem a chave)
 *   PATCH /api/v1/settings/ai          — salva configuração (admin)
 *   POST  /api/v1/settings/ai/test     — testa a conexão (admin)
 *   GET   /api/v1/ai/status            — o que está disponível (equipe)
 *   POST  /api/v1/ai/triage            — chamados mais urgentes (equipe)
 *   POST  /api/v1/tickets/{id}/ai/tips — dicas para resolver (equipe)
 *   POST  /api/v1/tickets/{id}/ai/similar — soluções de chamados semelhantes (equipe)
 */
final class AiController
{
    public function __construct(
        private readonly AiSettings         $settings,
        private readonly AiAssistantService $assistant,
        private readonly GeminiClient       $gemini,
    ) {}

    public function getSettings(): never
    {
        Response::success(data: $this->settings->publicView());
    }

    public function saveSettings(): never
    {
        $body = (array) (json_decode((string) file_get_contents('php://input'), true) ?? []);
        try {
            $this->settings->save($body);
            Response::success(data: $this->settings->publicView(), message: 'Configuração de IA salva.');
        } catch (AiException $e) {
            Response::error($e->getMessage(), statusCode: 422);
        }
    }

    /** Resultado do teste sempre com HTTP 200 e `ok`: falha de chave/saldo é resposta esperada do teste. */
    public function test(): never
    {
        try {
            Response::success(data: $this->assistant->ping(), message: 'Conexão com ' . $this->settings->label() . ' funcionando.');
        } catch (AiException $e) {
            $c = $this->settings->all();
            Response::success(data: ['ok' => false, 'message' => $e->getMessage(), 'model' => $c['model'], 'provider' => $c['provider'], 'provider_label' => $this->settings->label()], message: 'Falha no teste.');
        }
    }

    /** Modelos do Gemini disponíveis para a chave salva (preenche a lista do cartão). */
    public function geminiModels(): never
    {
        try {
            Response::success(data: $this->gemini->listModels());
        } catch (AiException $e) {
            Response::error($e->getMessage(), statusCode: 422);
        }
    }

    public function status(): never
    {
        $v = $this->settings->publicView();
        $staff = AuthContext::hasAnyRole(['admin', 'agent', 'supervisor']);
        $reportsRole = $staff || AuthContext::hasRole('manager');
        $out = ['ready' => $v['ready'], 'deflect' => $v['ready'] && $v['deflect'], 'reports' => $v['ready'] && $v['reports'] && $reportsRole, 'satisfaction' => $v['ready'] && $v['satisfaction'] && $reportsRole];
        if ($staff) {
            foreach (['triage', 'tips', 'similar', 'classify', 'summary', 'assignee'] as $f) {
                $out[$f] = $v['ready'] && $v[$f];
            }
            $out['model'] = $v['model'];
            $out['provider'] = $v['provider'];
            $out['provider_label'] = $v['provider_label'];
        }
        Response::success(data: $out);
    }

    public function summary(int $id): never
    {
        $this->run(fn() => $this->assistant->summary($id));
    }

    public function deflect(): never
    {
        $b = $this->body();
        $staff = AuthContext::hasAnyRole(['admin', 'agent', 'supervisor']);
        $this->run(fn() => $this->assistant->deflect((string) ($b['subject'] ?? ''), (string) ($b['description'] ?? ''), $staff));
    }

    public function assignee(int $id): never
    {
        $this->run(fn() => $this->assistant->suggestAssignee($id));
    }

    public function classification(int $id): never
    {
        $this->run(fn() => ['suggestion' => $this->assistant->storedClassification($id)]);
    }

    public function resolveClassification(int $id): never
    {
        $b = $this->body();
        $this->run(function () use ($id, $b) {
            $this->assistant->resolveClassification($id, (string) ($b['outcome'] ?? 'dismissed'));
            return ['suggestion' => $this->assistant->storedClassification($id)];
        });
    }

    public function reclassify(int $id): never
    {
        $this->run(fn() => ['suggestion' => $this->assistant->classifyTicket($id)]);
    }

    public function orgReport(): never
    {
        $b = $this->body();
        $orgId = $this->scopedOrg($b['organization_id'] ?? null, required: true);
        $this->run(fn() => $this->assistant->orgMonthlyReport($orgId, (string) ($b['month'] ?? date('Y-m', strtotime('first day of last month')))));
    }

    public function satisfaction(): never
    {
        $b = $this->body();
        $orgId = $this->scopedOrg($b['organization_id'] ?? null, required: false);
        $this->run(fn() => $this->assistant->satisfaction($orgId, (int) ($b['days'] ?? 90)));
    }

    /** Gerente só enxerga a própria empresa; a equipe escolhe livremente. */
    private function scopedOrg(mixed $requested, bool $required): ?int
    {
        if (!AuthContext::hasAnyRole(['admin', 'agent', 'supervisor'])) {
            $own = AuthContext::organizationId();
            if ($own === null) {
                Response::error('Seu usuário não está vinculado a uma empresa.', statusCode: 403);
            }
            return $own;
        }
        $id = $requested !== null && $requested !== '' ? (int) $requested : null;
        if ($required && !$id) {
            Response::error('Selecione a empresa.', statusCode: 422);
        }
        return $id;
    }

    private function body(): array
    {
        return (array) (json_decode((string) file_get_contents('php://input'), true) ?? []);
    }

    public function triage(): never
    {
        $this->run(fn() => $this->assistant->triage());
    }

    public function tips(int $id): never
    {
        $this->run(fn() => $this->assistant->tips($id));
    }

    public function similar(int $id): never
    {
        $this->run(fn() => $this->assistant->similar($id));
    }

    private function run(callable $fn, string $message = ''): never
    {
        @set_time_limit(200);
        try {
            Response::success(data: $fn(), message: $message);
        } catch (NotFoundException $e) {
            Response::error($e->getMessage(), statusCode: 404);
        } catch (AiException $e) {
            Response::error($e->getMessage(), statusCode: in_array($e->getCode(), [409, 422], true) ? $e->getCode() : 502);
        } catch (Throwable $e) {
            error_log('[AiController] ' . $e);
            Response::error('Erro inesperado ao consultar a IA.', statusCode: 500);
        }
    }
}
