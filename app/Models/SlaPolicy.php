<?php

declare(strict_types=1);

namespace App\Models;

final readonly class SlaPolicy
{
    public function __construct(
        public int    $id,
        public string $name,
        public bool   $isDefault,
        public int    $frtLow,
        public int    $frtMedium,
        public int    $frtHigh,
        public int    $frtCritical,
        public int    $rtLow,
        public int    $rtMedium,
        public int    $rtHigh,
        public int    $rtCritical,
        public bool   $businessHoursOnly,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id:                (int)  $row['id'],
            name:                     $row['name'],
            isDefault:         (bool) $row['is_default'],
            frtLow:            (int)  $row['frt_low'],
            frtMedium:         (int)  $row['frt_medium'],
            frtHigh:           (int)  $row['frt_high'],
            frtCritical:       (int)  $row['frt_critical'],
            rtLow:             (int)  $row['rt_low'],
            rtMedium:          (int)  $row['rt_medium'],
            rtHigh:            (int)  $row['rt_high'],
            rtCritical:        (int)  $row['rt_critical'],
            businessHoursOnly: (bool) $row['business_hours_only'],
        );
    }

    /** Retorna minutos de FRT para a prioridade informada. */
    public function frtMinutesFor(string $priority): int
    {
        return match ($priority) {
            'low'      => $this->frtLow,
            'high'     => $this->frtHigh,
            'critical' => $this->frtCritical,
            default    => $this->frtMedium,
        };
    }

    /** Retorna minutos de RT (Resolution Time) para a prioridade informada. */
    public function rtMinutesFor(string $priority): int
    {
        return match ($priority) {
            'low'      => $this->rtLow,
            'high'     => $this->rtHigh,
            'critical' => $this->rtCritical,
            default    => $this->rtMedium,
        };
    }
}
