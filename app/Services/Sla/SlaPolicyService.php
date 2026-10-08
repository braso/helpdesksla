<?php

declare(strict_types=1);

namespace App\Services\Sla;

use App\Core\Database\Connection;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

/**
 * Políticas (planos) de SLA e o vínculo com as empresas.
 *
 * Uma política pode atender várias empresas. Empresa sem política vinculada usa a
 * política padrão (is_default = 1), que sempre existe e não pode ser excluída.
 * Mudanças valem para chamados abertos a partir de agora; prazos já calculados
 * não são alterados.
 */
final class SlaPolicyService
{
    public const PRIORITIES = ['critical', 'high', 'medium', 'low'];
    private const FRT_MAX = 65535;     // smallint unsigned
    private const RT_MAX  = 525600;    // 1 ano

    public function __construct(private readonly Connection $connection) {}

    /** @return list<array<string, mixed>> com `organizations` [{id, name}] de cada política */
    public function list(): array
    {
        $pdo = $this->connection->pdo();
        $rows = $pdo->query('SELECT * FROM sla_policies ORDER BY is_default DESC, name ASC')->fetchAll();
        $orgs = $pdo->query('SELECT id, name, sla_policy_id FROM organizations WHERE deleted_at IS NULL ORDER BY name')->fetchAll();
        $default = null;
        foreach ($rows as $r) {
            if ((int) $r['is_default'] === 1) {
                $default = (int) $r['id'];
            }
        }
        $byPolicy = [];
        foreach ($orgs as $o) {
            $pid = $o['sla_policy_id'] !== null ? (int) $o['sla_policy_id'] : $default;
            $byPolicy[$pid][] = ['id' => (int) $o['id'], 'name' => $o['name'], 'explicit' => $o['sla_policy_id'] !== null];
        }
        return array_map(fn(array $r) => $this->present($r, $byPolicy[(int) $r['id']] ?? []), $rows);
    }

    /** @return list<array{id:int, name:string, sla_policy_id:?int}> */
    public function organizations(): array
    {
        return array_map(static fn($o) => ['id' => (int) $o['id'], 'name' => $o['name'], 'sla_policy_id' => $o['sla_policy_id'] !== null ? (int) $o['sla_policy_id'] : null],
            $this->connection->pdo()->query('SELECT id, name, sla_policy_id FROM organizations WHERE deleted_at IS NULL ORDER BY name')->fetchAll());
    }

    /** @param array<string, mixed> $d */
    public function create(array $d): array
    {
        $v = $this->validate($d);
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            $hasDefault = (bool) $pdo->query('SELECT COUNT(*) FROM sla_policies WHERE is_default = 1')->fetchColumn();
            $makeDefault = !$hasDefault || !empty($d['is_default']);
            if ($makeDefault) {
                $pdo->exec('UPDATE sla_policies SET is_default = 0 WHERE is_default = 1');
            }
            $cols = array_keys($v);
            $stmt = $pdo->prepare('INSERT INTO sla_policies (' . implode(', ', $cols) . ', is_default, created_at, updated_at)
                                   VALUES (:' . implode(', :', $cols) . ', :is_default, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
            $stmt->execute($this->bind($v) + [':is_default' => $makeDefault ? 1 : 0]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        if (isset($d['organization_ids'])) {
            $this->assign($id, (array) $d['organization_ids']);
        }
        return $this->find($id);
    }

    /** @param array<string, mixed> $d */
    public function update(int $id, array $d): array
    {
        $this->row($id);
        $v = $this->validate($d);
        $set = implode(', ', array_map(static fn($c) => "{$c} = :{$c}", array_keys($v)));
        $this->connection->pdo()->prepare("UPDATE sla_policies SET {$set}, updated_at = UTC_TIMESTAMP() WHERE id = :id")
            ->execute($this->bind($v) + [':id' => $id]);
        if (isset($d['organization_ids'])) {
            $this->assign($id, (array) $d['organization_ids']);
        }
        return $this->find($id);
    }

    public function setDefault(int $id): array
    {
        $row = $this->row($id);
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->exec('UPDATE sla_policies SET is_default = 0 WHERE is_default = 1');
            $pdo->prepare('UPDATE sla_policies SET is_default = 1, updated_at = UTC_TIMESTAMP() WHERE id = :id')->execute([':id' => $id]);
            // Empresas vinculadas explicitamente a ela passam a "usar o padrão" (mesmo resultado, vínculo mais simples).
            $pdo->prepare('UPDATE organizations SET sla_policy_id = NULL WHERE sla_policy_id = :id')->execute([':id' => (int) $row['id']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $this->find($id);
    }

    /** Exclui a política; as empresas que a usavam passam para a padrão. @return int empresas movidas */
    public function delete(int $id): int
    {
        $row = $this->row($id);
        if ((int) $row['is_default'] === 1) {
            throw new ValidationException(['policy' => ['A política padrão não pode ser excluída. Defina outra como padrão antes.']], 'A política padrão não pode ser excluída.');
        }
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            $s = $pdo->prepare('UPDATE organizations SET sla_policy_id = NULL WHERE sla_policy_id = :id');
            $s->execute([':id' => $id]);
            $moved = $s->rowCount();
            $pdo->prepare('DELETE FROM sla_policies WHERE id = :id')->execute([':id' => $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $moved;
    }

    /**
     * Define exatamente quais empresas usam esta política. As que estavam nela e saíram da
     * lista voltam para a padrão. Na política padrão, as listadas apenas perdem o vínculo próprio.
     * @param list<int|string> $orgIds
     */
    public function assign(int $policyId, array $orgIds): void
    {
        $row = $this->row($policyId);
        $ids = array_values(array_unique(array_filter(array_map('intval', $orgIds), static fn($i) => $i > 0)));
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            if ((int) $row['is_default'] === 1) {
                // Empresas listadas passam a usar o padrão (sem vínculo próprio).
                foreach ($ids as $oid) {
                    $pdo->prepare('UPDATE organizations SET sla_policy_id = NULL WHERE id = :o')->execute([':o' => $oid]);
                }
            } else {
                $pdo->prepare('UPDATE organizations SET sla_policy_id = NULL WHERE sla_policy_id = :p')->execute([':p' => $policyId]);
                foreach ($ids as $oid) {
                    $pdo->prepare('UPDATE organizations SET sla_policy_id = :p WHERE id = :o AND deleted_at IS NULL')->execute([':p' => $policyId, ':o' => $oid]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Vincula uma empresa a uma política (null = padrão). */
    public function assignOrganization(int $orgId, ?int $policyId): void
    {
        if ($policyId !== null) {
            $row = $this->row($policyId);
            if ((int) $row['is_default'] === 1) {
                $policyId = null;
            }
        }
        $s = $this->connection->pdo()->prepare('UPDATE organizations SET sla_policy_id = :p WHERE id = :o AND deleted_at IS NULL');
        $s->execute([':p' => $policyId, ':o' => $orgId]);
        if ($s->rowCount() === 0 && !$this->connection->pdo()->query('SELECT 1 FROM organizations WHERE id = ' . $orgId . ' AND deleted_at IS NULL')->fetchColumn()) {
            throw new NotFoundException('Empresa não encontrada.');
        }
    }

    public function find(int $id): array
    {
        foreach ($this->list() as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }
        throw new NotFoundException('Política de SLA não encontrada.');
    }

    // ── Internos ──────────────────────────────────────────────────────────────

    private function row(int $id): array
    {
        $s = $this->connection->pdo()->prepare('SELECT * FROM sla_policies WHERE id = :id');
        $s->execute([':id' => $id]);
        return $s->fetch() ?: throw new NotFoundException('Política de SLA não encontrada.');
    }

    /** @return array<string, int|string|null> colunas validadas */
    private function validate(array $d): array
    {
        $e = [];
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            $e['name'][] = 'Dê um nome à política.';
        } elseif (mb_strlen($name) > 100) {
            $e['name'][] = 'O nome pode ter no máximo 100 caracteres.';
        }
        $desc = trim((string) ($d['description'] ?? ''));
        if (mb_strlen($desc) > 500) {
            $e['description'][] = 'A descrição pode ter no máximo 500 caracteres.';
        }
        $out = ['name' => $name, 'description' => $desc !== '' ? $desc : null, 'business_hours_only' => !empty($d['business_hours_only']) ? 1 : 0];
        $label = ['critical' => 'crítica', 'high' => 'alta', 'medium' => 'média', 'low' => 'baixa'];
        foreach (self::PRIORITIES as $p) {
            $frt = filter_var($d["frt_{$p}"] ?? null, FILTER_VALIDATE_INT);
            $rt  = filter_var($d["rt_{$p}"] ?? null, FILTER_VALIDATE_INT);
            if ($frt === false || $frt < 1 || $frt > self::FRT_MAX) {
                $e["frt_{$p}"][] = "1ª resposta da prioridade {$label[$p]}: informe de 1 a " . self::FRT_MAX . ' minutos.';
            }
            if ($rt === false || $rt < 1 || $rt > self::RT_MAX) {
                $e["rt_{$p}"][] = "Resolução da prioridade {$label[$p]}: informe de 1 a " . self::RT_MAX . ' minutos.';
            }
            if ($frt !== false && $rt !== false && $frt > $rt) {
                $e["rt_{$p}"][] = "Prioridade {$label[$p]}: a resolução não pode ser mais rápida que a 1ª resposta.";
            }
            $out["frt_{$p}"] = (int) $frt;
            $out["rt_{$p}"]  = (int) $rt;
        }
        if ($e) {
            throw new ValidationException($e, 'Confira os campos destacados.');
        }
        return $out;
    }

    private function bind(array $v): array
    {
        $b = [];
        foreach ($v as $k => $val) {
            $b[":{$k}"] = $val;
        }
        return $b;
    }

    private function present(array $r, array $orgs): array
    {
        $out = ['id' => (int) $r['id'], 'name' => $r['name'], 'description' => $r['description'], 'is_default' => (int) $r['is_default'] === 1,
                'business_hours_only' => (int) $r['business_hours_only'] === 1, 'updated_at' => $r['updated_at'], 'organizations' => $orgs];
        foreach (self::PRIORITIES as $p) {
            $out["frt_{$p}"] = (int) $r["frt_{$p}"];
            $out["rt_{$p}"]  = (int) $r["rt_{$p}"];
        }
        return $out;
    }
}
