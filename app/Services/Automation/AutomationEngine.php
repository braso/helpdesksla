<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Models\Ticket;
use App\Repositories\AutomationRepository;

/**
 * Orquestrador do sistema de automações.
 *
 * Fluxo por invocação:
 *   1. Carrega automações ativas para o trigger informado (com conditions + actions)
 *   2. Para cada automação: avalia condições via ConditionEvaluator
 *   3. Se condições passam: executa ações via ActionExecutor
 *   4. Incrementa run_count para analytics
 *
 * Invocado pelos Listeners após cada evento de ticket.
 * Não é invocado diretamente pelos Controllers.
 */
final class AutomationEngine
{
    public function __construct(
        private readonly AutomationRepository $automationRepository,
        private readonly ConditionEvaluator   $conditionEvaluator,
        private readonly ActionExecutor       $actionExecutor,
    ) {}

    /**
     * @param string $triggerEvent Um dos valores do ENUM trigger_event da tabela automations
     * @param Ticket $ticket       Estado atual do ticket (pós-evento que disparou o trigger)
     */
    public function run(string $triggerEvent, Ticket $ticket): void
    {
        $automations = $this->automationRepository->findActiveByTrigger($triggerEvent);

        foreach ($automations as $automation) {
            if (!$this->conditionEvaluator->evaluate($automation, $ticket)) {
                continue;
            }

            $this->actionExecutor->execute($automation, $ticket);
            $this->automationRepository->incrementRunCount($automation->id);
        }
    }
}
