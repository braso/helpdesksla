<?php

declare(strict_types=1);

/**
 * Configuração de horário comercial para cálculo de SLA.
 * Dias da semana: 1=Segunda, 7=Domingo (ISO-8601, retornado por date('N')).
 */
return [
    'work_start_hour' => (int) ($_ENV['SLA_WORK_START'] ?? 9),   // 09:00
    'work_end_hour'   => (int) ($_ENV['SLA_WORK_END']   ?? 18),  // 18:00
    'work_days'       => [1, 2, 3, 4, 5],                        // Seg–Sex
    'timezone'        => $_ENV['SLA_TIMEZONE'] ?? 'America/Sao_Paulo',
];
