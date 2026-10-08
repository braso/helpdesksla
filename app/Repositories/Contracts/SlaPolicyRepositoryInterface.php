<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\SlaPolicy;

interface SlaPolicyRepositoryInterface
{
    public function findDefault(): ?SlaPolicy;

    /** Retorna a política da organização ou, como fallback, a padrão do sistema. */
    public function findForTicket(?int $organizationId): ?SlaPolicy;
}
