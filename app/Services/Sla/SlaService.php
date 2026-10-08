<?php

declare(strict_types=1);

namespace App\Services\Sla;

use App\Repositories\Contracts\SlaPolicyRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;

final class SlaService
{
    public function __construct(
        private readonly SlaPolicyRepositoryInterface $policyRepository,
        private readonly BusinessHoursCalculator      $calculator,
    ) {}

    /**
     * Calcula os deadlines de SLA para um novo ticket.
     *
     * Retorna um array pronto para ser mergeado nos dados do ticket:
     *   ['sla_policy_id', 'sla_frt_due_at', 'sla_rt_due_at']
     *
     * Se não houver política aplicável, retorna os três campos como null
     * (tickets sem SLA são válidos — ex: tickets internos de equipe).
     *
     * @return array{sla_policy_id: ?int, sla_frt_due_at: ?string, sla_rt_due_at: ?string}
     */
    public function calculateForTicket(string $priority, ?int $organizationId, string $createdAt): array
    {
        $policy = $this->policyRepository->findForTicket($organizationId);

        if ($policy === null) {
            return ['sla_policy_id' => null, 'sla_frt_due_at' => null, 'sla_rt_due_at' => null];
        }

        $start = new DateTimeImmutable($createdAt, new DateTimeZone('UTC'));

        $frtMinutes = $policy->frtMinutesFor($priority);
        $rtMinutes  = $policy->rtMinutesFor($priority);

        if ($policy->businessHoursOnly) {
            $frtDue = $this->calculator->addWorkingMinutes($start, $frtMinutes);
            $rtDue  = $this->calculator->addWorkingMinutes($start, $rtMinutes);
        } else {
            $frtDue = $start->modify("+{$frtMinutes} minutes");
            $rtDue  = $start->modify("+{$rtMinutes} minutes");
        }

        return [
            'sla_policy_id'  => $policy->id,
            'sla_frt_due_at' => $frtDue->format('Y-m-d H:i:s'),
            'sla_rt_due_at'  => $rtDue->format('Y-m-d H:i:s'),
        ];
    }
}
