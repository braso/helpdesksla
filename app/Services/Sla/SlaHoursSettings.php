<?php

declare(strict_types=1);

namespace App\Services\Sla;

use App\Core\Database\Connection;
use App\Exceptions\ValidationException;
use DateTimeZone;

/**
 * Horário comercial usado no cálculo de SLA (políticas com "só horário comercial").
 * Fica em system_settings; o que não estiver salvo vem de config/sla.php (.env).
 */
final class SlaHoursSettings
{
    private const KEYS = ['sla_work_start', 'sla_work_end', 'sla_work_days', 'sla_timezone'];

    public function __construct(
        private readonly Connection $connection,
        /** @var array{work_start_hour:int, work_end_hour:int, work_days:list<int>, timezone:string} */
        private readonly array      $fallback,
    ) {}

    /** @return array{work_start_hour:int, work_end_hour:int, work_days:list<int>, timezone:string} */
    public function effective(): array
    {
        try {
            $in   = implode(',', array_fill(0, count(self::KEYS), '?'));
            $stmt = $this->connection->pdo()->prepare("SELECT `key`, `value` FROM system_settings WHERE `key` IN ({$in})");
            $stmt->execute(self::KEYS);
            $r = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR) ?: [];
        } catch (\Throwable) {
            $r = [];
        }
        $c = $this->fallback;
        if (isset($r['sla_work_start']) && $r['sla_work_start'] !== '') {
            $c['work_start_hour'] = (int) $r['sla_work_start'];
        }
        if (isset($r['sla_work_end']) && $r['sla_work_end'] !== '') {
            $c['work_end_hour'] = (int) $r['sla_work_end'];
        }
        if (!empty($r['sla_work_days'])) {
            $days = array_values(array_unique(array_filter(array_map('intval', explode(',', $r['sla_work_days'])), fn($d) => $d >= 1 && $d <= 7)));
            if ($days) {
                sort($days);
                $c['work_days'] = $days;
            }
        }
        if (!empty($r['sla_timezone']) && in_array($r['sla_timezone'], DateTimeZone::listIdentifiers(), true)) {
            $c['timezone'] = $r['sla_timezone'];
        }
        // Configuração inconsistente salva à mão: volta ao padrão em vez de travar o cálculo.
        if ($c['work_start_hour'] < 0 || $c['work_end_hour'] > 24 || $c['work_start_hour'] >= $c['work_end_hour']) {
            $c['work_start_hour'] = $this->fallback['work_start_hour'];
            $c['work_end_hour']   = $this->fallback['work_end_hour'];
        }
        return $c;
    }

    /** @param array<string, mixed> $d work_start_hour, work_end_hour, work_days[], timezone */
    public function save(array $d): array
    {
        $start = (int) ($d['work_start_hour'] ?? -1);
        $end   = (int) ($d['work_end_hour'] ?? -1);
        $days  = array_values(array_unique(array_map('intval', (array) ($d['work_days'] ?? []))));
        $tz    = (string) ($d['timezone'] ?? '');
        $errors = [];
        if ($start < 0 || $start > 23) {
            $errors['work_start_hour'][] = 'Início do expediente inválido.';
        }
        if ($end < 1 || $end > 24) {
            $errors['work_end_hour'][] = 'Fim do expediente inválido.';
        }
        if (!$errors && $start >= $end) {
            $errors['work_end_hour'][] = 'O fim do expediente deve ser depois do início.';
        }
        if (!$days || array_diff($days, [1, 2, 3, 4, 5, 6, 7])) {
            $errors['work_days'][] = 'Escolha pelo menos um dia útil.';
        }
        if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'][] = 'Fuso horário inválido.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        sort($days);
        $stmt = $this->connection->pdo()->prepare(
            "INSERT INTO system_settings (`key`, `value`) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = CURRENT_TIMESTAMP"
        );
        foreach (['sla_work_start' => $start, 'sla_work_end' => $end, 'sla_work_days' => implode(',', $days), 'sla_timezone' => $tz] as $k => $v) {
            $stmt->execute([':k' => $k, ':v' => (string) $v]);
        }
        return $this->effective();
    }
}
