<?php

declare(strict_types=1);

namespace App\Services\Sla;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Adiciona minutos "úteis" a um datetime, pulando finais de semana e
 * horas fora do expediente comercial configurado.
 *
 * Algoritmo:
 *   1. Avança até o próximo momento dentro do horário comercial.
 *   2. Calcula quantos minutos restam até o fim do expediente desse dia.
 *   3. Se os minutos restantes cabem no dia → avança diretamente.
 *   4. Se não cabem → desconta os minutos do dia, pula para o início
 *      do próximo dia útil e repete.
 */
final class BusinessHoursCalculator
{
    private readonly int    $workStartHour;
    private readonly int    $workEndHour;
    private readonly array  $workDays;
    private readonly string $timezone;

    public function __construct(array $config)
    {
        $this->workStartHour = $config['work_start_hour'];
        $this->workEndHour   = $config['work_end_hour'];
        $this->workDays      = $config['work_days'];
        $this->timezone      = $config['timezone'];
    }

    /**
     * @param DateTimeImmutable $start   Momento inicial (qualquer timezone — será convertido)
     * @param int               $minutes Minutos úteis a adicionar
     */
    public function addWorkingMinutes(DateTimeImmutable $start, int $minutes): DateTimeImmutable
    {
        $tz      = new DateTimeZone($this->timezone);
        $current = $start->setTimezone($tz);
        $remaining = $minutes;

        while ($remaining > 0) {
            $current = $this->advanceToWorkingTime($current);

            $endOfDay = $current->setTime($this->workEndHour, 0, 0);
            $minutesUntilEndOfDay = (int) (($endOfDay->getTimestamp() - $current->getTimestamp()) / 60);

            if ($remaining <= $minutesUntilEndOfDay) {
                $current   = $current->modify("+{$remaining} minutes");
                $remaining = 0;
            } else {
                $remaining -= $minutesUntilEndOfDay;
                // Pula para o início do próximo dia (advanceToWorkingTime vai tratar feriados/fins de semana)
                $current = $current->modify('+1 day')->setTime($this->workStartHour, 0, 0);
            }
        }

        return $current->setTimezone(new DateTimeZone('UTC'));
    }

    /** Avança o datetime para o próximo momento dentro do horário comercial. */
    private function advanceToWorkingTime(DateTimeImmutable $dt): DateTimeImmutable
    {
        $current = $dt;

        // Pula fins de semana (e dias não úteis configurados)
        while (!in_array((int) $current->format('N'), $this->workDays, strict: true)) {
            $current = $current->modify('+1 day')->setTime($this->workStartHour, 0, 0);
        }

        $hour = (int) $current->format('H');

        if ($hour < $this->workStartHour) {
            return $current->setTime($this->workStartHour, 0, 0);
        }

        if ($hour >= $this->workEndHour) {
            // Passa para o início do próximo dia e re-verifica (pode ser fim de semana)
            return $this->advanceToWorkingTime(
                $current->modify('+1 day')->setTime($this->workStartHour, 0, 0)
            );
        }

        return $current;
    }
}
