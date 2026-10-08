<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Core\Database\Connection;
use App\Exceptions\NotFoundException;

/**
 * Assistente de IA para a equipe de suporte:
 *  - triage():  ordena os chamados abertos mais urgentes, com o motivo;
 *  - tips():    sugere diagnóstico, passos e um rascunho de resposta para um chamado;
 *  - similar(): encontra chamados já resolvidos com o mesmo problema e resume a solução aplicada.
 *
 * Só dados necessários são enviados ao provedor (assunto, descrição e respostas, truncados).
 */
final class AiAssistantService
{
    private const SYSTEM = 'Você é um assistente sênior de service desk de TI de uma empresa brasileira (BRASO), que atende várias empresas clientes com contratos de SLA. '
        . 'Responda sempre em português do Brasil, de forma objetiva e prática, e SEMPRE no formato JSON pedido, sem texto fora do JSON. '
        . 'Não invente fatos que não estejam nos dados; quando faltar informação, diga o que perguntar.';

    public function __construct(
        private readonly Connection     $connection,
        private readonly AiClient       $client,
        private readonly AiSettings     $settings,
    ) {}

    /** Teste rápido de conexão/chave. */
    public function ping(): array
    {
        $r = $this->client->json([
            ['role' => 'system', 'content' => 'Responda apenas em JSON.'],
            ['role' => 'user', 'content' => 'Responda {"ok": true, "mensagem": "<uma saudação curta em português>"}'],
        ], 60);
        $c = $this->settings->all();
        return ['ok' => (bool) ($r['ok'] ?? false), 'message' => (string) ($r['mensagem'] ?? 'Conexão bem-sucedida.'), 'model' => $c['model'], 'provider' => $c['provider'], 'provider_label' => $this->settings->label()];
    }

    // ── Triagem ───────────────────────────────────────────────────────────────

    /** @return array{items: list<array>, summary: string, analyzed: int} */
    public function triage(int $limit = 8): array
    {
        $this->assertFeature('triage');
        $rows = $this->connection->pdo()->query(
            "SELECT t.id, t.ticket_number, t.subject, LEFT(t.description, 400) AS description, t.priority, t.status, t.type,
                    t.created_at, t.updated_at, t.sla_frt_due_at, t.sla_rt_due_at, t.first_response_at,
                    t.sla_frt_breached, t.sla_rt_breached, t.assigned_agent_id,
                    o.name AS org, au.name AS agent,
                    (SELECT COUNT(*) FROM ticket_replies r WHERE r.ticket_id = t.id AND r.deleted_at IS NULL) AS replies,
                    (SELECT LEFT(r.body, 200) FROM ticket_replies r WHERE r.ticket_id = t.id AND r.deleted_at IS NULL AND r.is_private = 0 ORDER BY r.id DESC LIMIT 1) AS last_reply,
                    (SELECT r.author_id = t.requester_id FROM ticket_replies r WHERE r.ticket_id = t.id AND r.deleted_at IS NULL AND r.is_private = 0 ORDER BY r.id DESC LIMIT 1) AS waiting_us
               FROM tickets t
               LEFT JOIN organizations o ON o.id = t.organization_id
               LEFT JOIN users au ON au.id = t.assigned_agent_id
              WHERE t.deleted_at IS NULL AND t.status NOT IN ('resolved','closed')
              ORDER BY COALESCE(LEAST(t.sla_frt_due_at, t.sla_rt_due_at), t.sla_rt_due_at, '9999-12-31') ASC, t.created_at ASC
              LIMIT 40"
        )->fetchAll();

        if (!$rows) {
            return ['items' => [], 'summary' => 'Não há chamados em aberto.', 'analyzed' => 0];
        }

        $now = time();
        $payload = array_map(function (array $t) use ($now) {
            $due = $t['first_response_at'] ? $t['sla_rt_due_at'] : ($t['sla_frt_due_at'] ?: $t['sla_rt_due_at']);
            return [
                'id' => (int) $t['id'], 'numero' => $t['ticket_number'], 'assunto' => $t['subject'],
                'descricao' => $this->clean($t['description']), 'prioridade' => $t['priority'], 'status' => $t['status'], 'tipo' => $t['type'],
                'empresa' => $t['org'], 'responsavel' => $t['agent'] ?: null,
                'aberto_ha_horas' => round(($now - strtotime($t['created_at'] . ' UTC')) / 3600, 1),
                'minutos_ate_prazo' => $due ? (int) round((strtotime($due . ' UTC') - $now) / 60) : null,
                'prazo_vencido' => (bool) ($t['sla_frt_breached'] || $t['sla_rt_breached'] || ($due && strtotime($due . ' UTC') < $now)),
                'aguardando_primeira_resposta' => $t['first_response_at'] === null,
                'cliente_aguardando_retorno' => (bool) $t['waiting_us'],
                'respostas' => (int) $t['replies'],
                'ultima_resposta' => $this->clean($t['last_reply']),
            ];
        }, $rows);

        $r = $this->client->json([
            ['role' => 'system', 'content' => self::SYSTEM],
            ['role' => 'user', 'content' =>
                "Analise os chamados em aberto abaixo e escolha os {$limit} que a equipe deve atacar primeiro. "
                . 'Pese: risco ou vencimento de SLA (minutos_ate_prazo negativo = vencido), impacto no negócio descrito no texto '
                . '(sistema parado, muitos usuários afetados, financeiro, produção, segurança), prioridade, tempo de espera, '
                . 'cliente aguardando retorno e falta de responsável. Seja específico no motivo (cite o que no chamado indica urgência).'
                . "\nResponda no JSON: {\"itens\":[{\"id\":<id>,\"urgencia\":<1-5>,\"motivo\":\"<1 frase>\",\"acao\":\"<próximo passo em 1 frase>\"}],\"resumo\":\"<1-2 frases sobre a fila>\"}"
                . "\nChamados:\n" . json_encode($payload, JSON_UNESCAPED_UNICODE)],
        ], 1800);

        $byId = array_column($payload, null, 'id');
        $items = [];
        foreach (($r['itens'] ?? []) as $it) {
            $id = (int) ($it['id'] ?? 0);
            if (!isset($byId[$id]) || isset($items[$id])) {
                continue;
            }
            $items[$id] = [
                'ticket_id' => $id, 'ticket_number' => $byId[$id]['numero'], 'subject' => $byId[$id]['assunto'],
                'organization' => $byId[$id]['empresa'], 'priority' => $byId[$id]['prioridade'],
                'urgency' => max(1, min(5, (int) ($it['urgencia'] ?? 3))),
                'reason' => (string) ($it['motivo'] ?? ''), 'action' => (string) ($it['acao'] ?? ''),
            ];
            if (count($items) >= $limit) {
                break;
            }
        }
        return ['items' => array_values($items), 'summary' => (string) ($r['resumo'] ?? ''), 'analyzed' => count($payload)];
    }

    // ── Dicas para o técnico ─────────────────────────────────────────────────

    public function tips(int $ticketId): array
    {
        $this->assertFeature('tips');
        $t = $this->ticket($ticketId);
        $r = $this->client->json([
            ['role' => 'system', 'content' => self::SYSTEM],
            ['role' => 'user', 'content' =>
                'Ajude o técnico a resolver o chamado abaixo. Dê hipóteses de causa, passos de diagnóstico e solução em ordem '
                . '(do mais provável e rápido ao mais trabalhoso), perguntas que faltam ao cliente e um rascunho de resposta '
                . 'cordial e claro para o cliente (sem prometer prazos que não estão nos dados).'
                . "\nResponda no JSON: {\"resumo\":\"<o problema em 1 frase>\",\"causas_provaveis\":[\"...\"],\"passos\":[\"...\"],\"perguntas_ao_cliente\":[\"...\"],\"cuidados\":[\"<riscos ou o que não fazer>\"],\"rascunho_resposta\":\"...\"}"
                . "\nChamado:\n" . json_encode($t, JSON_UNESCAPED_UNICODE)],
        ], 1800);

        return [
            'summary'   => (string) ($r['resumo'] ?? ''),
            'causes'    => $this->strList($r['causas_provaveis'] ?? []),
            'steps'     => $this->strList($r['passos'] ?? []),
            'questions' => $this->strList($r['perguntas_ao_cliente'] ?? []),
            'cautions'  => $this->strList($r['cuidados'] ?? []),
            'reply_draft' => (string) ($r['rascunho_resposta'] ?? ''),
        ];
    }

    // ── Chamados semelhantes já resolvidos ───────────────────────────────────

    public function similar(int $ticketId): array
    {
        $this->assertFeature('similar');
        $t = $this->ticket($ticketId);
        $candidates = $this->candidates($ticketId, $t['assunto'] . ' ' . $t['descricao']);
        if (!$candidates) {
            return ['matches' => [], 'suggested_reply' => '', 'searched' => 0, 'note' => 'Nenhum chamado resolvido com palavras parecidas foi encontrado.'];
        }

        $r = $this->client->json([
            ['role' => 'system', 'content' => self::SYSTEM],
            ['role' => 'user', 'content' =>
                'Compare o CHAMADO ATUAL com os CHAMADOS RESOLVIDOS. Selecione apenas os que tratam do mesmo problema ou de problema '
                . 'muito parecido (ignore os que só compartilham palavras). Para cada um, resuma a solução que foi aplicada, com base '
                . 'nas respostas da equipe. Depois, sugira uma resposta ao cliente do chamado atual aproveitando a solução conhecida '
                . '(vazio se nenhum servir).'
                . "\nResponda no JSON: {\"semelhantes\":[{\"id\":<id>,\"similaridade\":<0-100>,\"por_que\":\"<1 frase>\",\"solucao\":\"<passos que resolveram>\"}],\"resposta_sugerida\":\"...\"}"
                . "\nCHAMADO ATUAL:\n" . json_encode($t, JSON_UNESCAPED_UNICODE)
                . "\nCHAMADOS RESOLVIDOS:\n" . json_encode(array_values($candidates), JSON_UNESCAPED_UNICODE)],
        ], 2000);

        $matches = [];
        foreach (($r['semelhantes'] ?? []) as $m) {
            $id = (int) ($m['id'] ?? 0);
            if (!isset($candidates[$id]) || (int) ($m['similaridade'] ?? 0) < 40) {
                continue;
            }
            $c = $candidates[$id];
            $matches[] = [
                'ticket_id' => $id, 'ticket_number' => $c['numero'], 'subject' => $c['assunto'], 'organization' => $c['empresa'],
                'resolved_at' => $c['resolvido_em'], 'similarity' => max(0, min(100, (int) $m['similaridade'])),
                'why' => (string) ($m['por_que'] ?? ''), 'solution' => (string) ($m['solucao'] ?? ''),
            ];
        }
        usort($matches, static fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return ['matches' => array_slice($matches, 0, 5), 'suggested_reply' => $matches ? (string) ($r['resposta_sugerida'] ?? '') : '', 'searched' => count($candidates)];
    }

    // ── 1. Classificação automática ao abrir ──────────────────────────────────

    /** Gera e grava em tickets.metadata.ai_classification a sugestão de tipo e prioridade. */
    public function classifyTicket(int $ticketId): ?array
    {
        if (!$this->featureOn('classify')) {
            return null;
        }
        $t = $this->ticket($ticketId);
        $r = $this->client->json([
            ['role' => 'system', 'content' => self::SYSTEM],
            ['role' => 'user', 'content' =>
                'Classifique o chamado abaixo. Tipo: question (dúvida), incident (algo parou de funcionar), problem (falha recorrente '
                . 'ou causa raiz a investigar), task (pedido de serviço: acesso, instalação, compra). Prioridade: low (sem impacto, pode esperar), '
                . 'medium (atrapalha, mas há contorno), high (pessoa ou setor impedido de trabalhar), critical (empresa ou serviço essencial parado, '
                . 'risco financeiro, legal ou de segurança). Baseie-se no impacto descrito, não no tom.'
                . "\nResponda no JSON: {\"tipo\":\"question|incident|problem|task\",\"prioridade\":\"low|medium|high|critical\",\"motivo\":\"<1 frase>\",\"confianca\":<0-100>}"
                . "\nChamado:\n" . json_encode($t, JSON_UNESCAPED_UNICODE)],
        ], 400);

        $type = in_array($r['tipo'] ?? '', ['question', 'incident', 'problem', 'task'], true) ? $r['tipo'] : null;
        $prio = in_array($r['prioridade'] ?? '', ['low', 'medium', 'high', 'critical'], true) ? $r['prioridade'] : null;
        if (!$type && !$prio) {
            return null;
        }
        $suggestion = [
            'type' => $type, 'priority' => $prio, 'reason' => (string) ($r['motivo'] ?? ''),
            'confidence' => max(0, min(100, (int) ($r['confianca'] ?? 0))), 'at' => gmdate('Y-m-d H:i:s'),
            'original' => ['type' => $t['tipo'], 'priority' => $t['prioridade']],
        ];
        $this->connection->pdo()->prepare(
            "UPDATE tickets SET metadata = JSON_SET(COALESCE(metadata, JSON_OBJECT()), '$.ai_classification', CAST(:j AS JSON)) WHERE id = :id"
        )->execute([':j' => json_encode($suggestion, JSON_UNESCAPED_UNICODE), ':id' => $ticketId]);
        return $suggestion;
    }

    /** Sugestão de classificação gravada, para exibir à equipe. */
    public function storedClassification(int $ticketId): ?array
    {
        $s = $this->connection->pdo()->prepare("SELECT JSON_EXTRACT(metadata, '$.ai_classification') FROM tickets WHERE id = :id");
        $s->execute([':id' => $ticketId]);
        $v = $s->fetchColumn();
        return $v ? (json_decode((string) $v, true) ?: null) : null;
    }

    /** Marca a sugestão como aplicada ou descartada (não volta a aparecer). */
    public function resolveClassification(int $ticketId, string $outcome): void
    {
        $this->connection->pdo()->prepare(
            "UPDATE tickets SET metadata = JSON_SET(metadata, '$.ai_classification.outcome', :o) WHERE id = :id AND JSON_EXTRACT(metadata, '$.ai_classification') IS NOT NULL"
        )->execute([':o' => $outcome === 'applied' ? 'applied' : 'dismissed', ':id' => $ticketId]);
    }

    // ── 3. Resumo do chamado ─────────────────────────────────────────────────

    public function summary(int $ticketId): array
    {
        $this->assertFeature('summary');
        $t = $this->ticket($ticketId);
        $r = $this->client->json([
            ['role' => 'system', 'content' => self::SYSTEM],
            ['role' => 'user', 'content' =>
                'Resuma o chamado para um técnico que vai assumi-lo agora, sem ter lido a conversa. Seja curto e concreto.'
                . "\nResponda no JSON: {\"resumo\":\"<até 3 frases: problema, situação atual, o que falta>\",\"ja_tentado\":[\"...\"],\"pendente\":[\"<o que falta fazer ou responder>\"],\"aguardando\":\"equipe|cliente|ninguem\",\"humor_cliente\":\"tranquilo|preocupado|insatisfeito|irritado\"}"
                . "\nChamado:\n" . json_encode($t, JSON_UNESCAPED_UNICODE)],
        ], 900);
        return [
            'summary' => (string) ($r['resumo'] ?? ''),
            'tried'   => $this->strList($r['ja_tentado'] ?? []),
            'pending' => $this->strList($r['pendente'] ?? []),
            'waiting_on' => in_array($r['aguardando'] ?? '', ['equipe', 'cliente', 'ninguem'], true) ? $r['aguardando'] : null,
            'mood'    => in_array($r['humor_cliente'] ?? '', ['tranquilo', 'preocupado', 'insatisfeito', 'irritado'], true) ? $r['humor_cliente'] : null,
            'replies' => count($t['conversa']),
        ];
    }

    // ── 6. Autoatendimento antes de abrir o chamado ──────────────────────────

    /**
     * Sugere uma solução ao cliente antes de abrir o chamado, aprendendo com chamados resolvidos.
     * Para clientes, NUNCA expõe dados de outras empresas: só devolve passos genéricos.
     */
    public function deflect(string $subject, string $description, bool $forStaff): array
    {
        $this->assertFeature('deflect');
        $subject = mb_substr(trim($subject), 0, 300);
        $description = mb_substr(trim($description), 0, 2000);
        if (mb_strlen($subject . $description) < 15) {
            throw new AiException('Descreva um pouco mais o problema para buscar soluções.', 422);
        }
        $candidates = $this->candidates(0, $subject . ' ' . $description);
        $known = array_map(static fn($c) => ['problema' => $c['assunto'] . ' — ' . $c['descricao'], 'solucao_da_equipe' => $c['respostas_da_equipe']], array_values($candidates));

        $r = $this->client->json([
            ['role' => 'system', 'content' => self::SYSTEM],
            ['role' => 'user', 'content' =>
                'Um usuário vai abrir um chamado. Se o problema puder ser resolvido pelo próprio usuário com passos simples e seguros '
                . '(sem acesso de administrador, sem risco de perder dados), sugira os passos. Use as SOLUÇÕES CONHECIDAS quando forem do mesmo problema. '
                . 'NUNCA mencione nomes de pessoas, empresas, números de chamado ou qualquer dado das soluções conhecidas; escreva passos genéricos. '
                . 'Se o problema exigir a equipe (sistema fora do ar, permissão, equipamento quebrado, segurança), diga que é melhor abrir o chamado.'
                . "\nResponda no JSON: {\"pode_resolver_sozinho\":true|false,\"titulo\":\"<1 frase>\",\"passos\":[\"...\"],\"observacao\":\"<quando abrir o chamado mesmo assim>\",\"baseado_em_casos\":<quantas soluções conhecidas usou>}"
                . "\nPROBLEMA DO USUÁRIO:\n" . json_encode(['assunto' => $subject, 'descricao' => $description], JSON_UNESCAPED_UNICODE)
                . "\nSOLUÇÕES CONHECIDAS:\n" . json_encode($known, JSON_UNESCAPED_UNICODE)],
        ], 900);

        return [
            'self_service' => (bool) ($r['pode_resolver_sozinho'] ?? false),
            'title'  => (string) ($r['titulo'] ?? ''),
            'steps'  => $this->strList($r['passos'] ?? []),
            'note'   => (string) ($r['observacao'] ?? ''),
            'based_on_cases' => min(count($known), max(0, (int) ($r['baseado_em_casos'] ?? 0))),
            'similar_tickets' => $forStaff ? array_map(static fn($c) => ['ticket_id' => $c['id'], 'ticket_number' => $c['numero'], 'subject' => $c['assunto']], array_values(array_slice($candidates, 0, 3))) : [],
        ];
    }

    // ── 8. Relatório executivo mensal por empresa ─────────────────────────────

    public function orgMonthlyReport(int $orgId, string $month): array
    {
        $this->assertFeature('reports');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new AiException('Mês inválido. Use o formato AAAA-MM.', 422);
        }
        $pdo = $this->connection->pdo();
        $o = $pdo->prepare('SELECT id, name, trade_name FROM organizations WHERE id = :id AND deleted_at IS NULL');
        $o->execute([':id' => $orgId]);
        $org = $o->fetch();
        if (!$org) {
            throw new NotFoundException('Empresa');
        }
        $from = $month . '-01 00:00:00';
        $to   = date('Y-m-d H:i:s', strtotime($from . ' +1 month'));
        $p    = [':o' => $orgId, ':f' => $from, ':t' => $to];

        $m = $pdo->prepare(
            "SELECT COUNT(*) total,
                    SUM(status IN ('resolved','closed')) resolvidos,
                    SUM(status NOT IN ('resolved','closed')) em_aberto,
                    SUM(priority='critical') criticos, SUM(priority='high') altos,
                    SUM(sla_frt_breached=1) violou_1a_resposta, SUM(sla_rt_breached=1) violou_resolucao,
                    ROUND(AVG(CASE WHEN first_response_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, first_response_at) END)) media_min_1a_resposta,
                    ROUND(AVG(CASE WHEN resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, resolved_at) END)) media_min_resolucao,
                    ROUND(AVG(rating),2) nota_media, COUNT(rating) avaliacoes
               FROM tickets WHERE organization_id = :o AND deleted_at IS NULL AND created_at >= :f AND created_at < :t"
        );
        $m->execute($p);
        $metrics = array_map(static fn($v) => $v === null ? null : (is_numeric($v) ? $v + 0 : $v), $m->fetch() ?: []);

        $prev = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE organization_id = :o AND deleted_at IS NULL AND created_at >= :f AND created_at < :t");
        $prev->execute([':o' => $orgId, ':f' => date('Y-m-d H:i:s', strtotime($from . ' -1 month')), ':t' => $from]);
        $metrics['total_mes_anterior'] = (int) $prev->fetchColumn();

        $tk = $pdo->prepare(
            "SELECT subject, type, priority, status, rating, LEFT(rating_comment, 200) comentario
               FROM tickets WHERE organization_id = :o AND deleted_at IS NULL AND created_at >= :f AND created_at < :t
              ORDER BY FIELD(priority,'critical','high','medium','low'), id DESC LIMIT 60"
        );
        $tk->execute($p);
        $tickets = $tk->fetchAll();
        $tm = $pdo->prepare(
            "SELECT COALESCE(SUM(e.duration_seconds),0) FROM ticket_time_entries e JOIN tickets t ON t.id = e.ticket_id
              WHERE t.organization_id = :o AND e.started_at >= :f AND e.started_at < :t"
        );
        $tm->execute($p);
        $metrics['horas_trabalhadas'] = round(((int) $tm->fetchColumn()) / 3600, 1);

        if ((int) $metrics['total'] === 0) {
            return ['organization' => $org['name'], 'month' => $month, 'metrics' => $metrics, 'title' => 'Sem chamados no período',
                    'summary' => "Nenhum chamado foi aberto por {$org['name']} neste mês.", 'highlights' => [], 'problems' => [], 'recommendations' => []];
        }

        $r = $this->client->json([
            ['role' => 'system', 'content' => self::SYSTEM],
            ['role' => 'user', 'content' =>
                "Escreva um relatório executivo mensal de suporte para o cliente {$org['name']}, referente a {$month}, para ser enviado ao gestor do cliente. "
                . 'Use só os números fornecidos (não invente). Tom profissional, claro e honesto: destaque conquistas e também atrasos de SLA. '
                . 'Agrupe os chamados em temas recorrentes e recomende ações práticas (treinamento, troca de equipamento, melhoria de processo).'
                . "\nResponda no JSON: {\"titulo\":\"...\",\"resumo\":\"<1 parágrafo>\",\"destaques\":[\"...\"],\"temas_recorrentes\":[{\"tema\":\"...\",\"chamados\":<n>,\"comentario\":\"...\"}],\"pontos_de_atencao\":[\"...\"],\"recomendacoes\":[\"...\"]}"
                . "\nINDICADORES (tempos em minutos):\n" . json_encode($metrics, JSON_UNESCAPED_UNICODE)
                . "\nCHAMADOS DO MÊS:\n" . json_encode($tickets, JSON_UNESCAPED_UNICODE)],
        ], 1800);

        return [
            'organization' => $org['name'], 'month' => $month, 'metrics' => $metrics,
            'title'   => (string) ($r['titulo'] ?? "Relatório de suporte — {$org['name']}"),
            'summary' => (string) ($r['resumo'] ?? ''),
            'highlights' => $this->strList($r['destaques'] ?? []),
            'themes'  => array_values(array_filter(array_map(static fn($x) => is_array($x) ? ['theme' => (string) ($x['tema'] ?? ''), 'count' => (int) ($x['chamados'] ?? 0), 'comment' => (string) ($x['comentario'] ?? '')] : null, $r['temas_recorrentes'] ?? []))),
            'problems' => $this->strList($r['pontos_de_atencao'] ?? []),
            'recommendations' => $this->strList($r['recomendacoes'] ?? []),
        ];
    }

    // ── 9. Análise de satisfação ─────────────────────────────────────────────

    public function satisfaction(?int $orgId, int $days): array
    {
        $this->assertFeature('satisfaction');
        $days = max(7, min(365, $days));
        $where = 't.deleted_at IS NULL AND t.rating IS NOT NULL AND t.rated_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $days . ' DAY)';
        $p = [];
        if ($orgId !== null) {
            $where .= ' AND t.organization_id = :o';
            $p[':o'] = $orgId;
        }
        $s = $this->connection->pdo()->prepare(
            "SELECT t.rating, LEFT(t.rating_comment, 300) comentario, t.subject, t.priority, t.type,
                    TIMESTAMPDIFF(MINUTE, t.created_at, COALESCE(t.resolved_at, t.closed_at)) minutos_ate_resolver,
                    t.sla_rt_breached violou_prazo, o.name empresa, au.name tecnico
               FROM tickets t LEFT JOIN organizations o ON o.id = t.organization_id LEFT JOIN users au ON au.id = t.assigned_agent_id
              WHERE {$where} ORDER BY t.rating ASC, t.rated_at DESC LIMIT 120"
        );
        $s->execute($p);
        $rows = $s->fetchAll();
        $n = count($rows);
        $dist = array_fill(1, 5, 0);
        foreach ($rows as $r) {
            $dist[(int) $r['rating']]++;
        }
        $avg = $n ? round(array_sum(array_map(static fn($r) => (int) $r['rating'], $rows)) / $n, 2) : null;
        $base = ['count' => $n, 'average' => $avg, 'distribution' => $dist, 'days' => $days];
        if ($n === 0) {
            return $base + ['summary' => 'Nenhuma avaliação no período.', 'positives' => [], 'negatives' => [], 'causes' => [], 'actions' => []];
        }
        if ($orgId !== null) {
            foreach ($rows as &$r) { unset($r['empresa']); }
            unset($r);
        }
        $r = $this->client->json([
            ['role' => 'system', 'content' => self::SYSTEM],
            ['role' => 'user', 'content' =>
                "Analise as avaliações de atendimento dos últimos {$days} dias (nota 1 a 5). Identifique o que agrada, o que desagrada e as causas "
                . 'prováveis das notas baixas (demora, falta de retorno, problema não resolvido, tom), cruzando nota com prazo, prioridade e técnico. '
                . 'Proponha ações concretas para a equipe. Não exponha nomes de clientes; técnicos podem ser citados para orientação interna.'
                . "\nResponda no JSON: {\"resumo\":\"<1 parágrafo>\",\"pontos_positivos\":[\"...\"],\"pontos_negativos\":[\"...\"],\"causas_notas_baixas\":[\"...\"],\"acoes\":[\"...\"]}"
                . "\nRESUMO NUMÉRICO:\n" . json_encode($base, JSON_UNESCAPED_UNICODE)
                . "\nAVALIAÇÕES:\n" . json_encode($rows, JSON_UNESCAPED_UNICODE)],
        ], 1500);
        return $base + [
            'summary'   => (string) ($r['resumo'] ?? ''),
            'positives' => $this->strList($r['pontos_positivos'] ?? []),
            'negatives' => $this->strList($r['pontos_negativos'] ?? []),
            'causes'    => $this->strList($r['causas_notas_baixas'] ?? []),
            'actions'   => $this->strList($r['acoes'] ?? []),
        ];
    }

    // ── 10. Sugerir o melhor técnico ─────────────────────────────────────────

    public function suggestAssignee(int $ticketId): array
    {
        $this->assertFeature('assignee');
        $t = $this->ticket($ticketId);
        $pdo = $this->connection->pdo();
        $staff = $pdo->query(
            "SELECT DISTINCT u.id, u.name FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
              WHERE r.slug IN ('admin','agent','supervisor') AND u.is_active = 1 AND u.deleted_at IS NULL"
        )->fetchAll();
        if (!$staff) {
            return ['suggestions' => [], 'note' => 'Não há técnicos ativos.'];
        }
        $load = $pdo->query(
            "SELECT assigned_agent_id id, COUNT(*) abertos, SUM(priority IN ('high','critical')) urgentes
               FROM tickets WHERE deleted_at IS NULL AND status NOT IN ('resolved','closed') AND assigned_agent_id IS NOT NULL GROUP BY assigned_agent_id"
        )->fetchAll(\PDO::FETCH_UNIQUE);
        $perf = $pdo->query(
            "SELECT assigned_agent_id id, COUNT(*) resolvidos_90d, ROUND(AVG(rating),2) nota_media,
                    ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at))) media_min_resolucao
               FROM tickets WHERE deleted_at IS NULL AND status IN ('resolved','closed') AND assigned_agent_id IS NOT NULL
                AND COALESCE(resolved_at, closed_at) >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY) GROUP BY assigned_agent_id"
        )->fetchAll(\PDO::FETCH_UNIQUE);

        // Experiência em chamados parecidos: quem resolveu os candidatos semelhantes.
        $similar = $this->candidates($ticketId, $t['assunto'] . ' ' . $t['descricao']);
        $exp = [];
        if ($similar) {
            $in = implode(',', array_map('intval', array_keys($similar)));
            foreach ($pdo->query("SELECT assigned_agent_id id, subject FROM tickets WHERE id IN ({$in}) AND assigned_agent_id IS NOT NULL") as $row) {
                $exp[(int) $row['id']][] = $row['subject'];
            }
        }
        $people = array_map(static fn($u) => [
            'id' => (int) $u['id'], 'nome' => $u['name'],
            'abertos_agora' => (int) ($load[$u['id']]['abertos'] ?? 0), 'urgentes_agora' => (int) ($load[$u['id']]['urgentes'] ?? 0),
            'resolvidos_90d' => (int) ($perf[$u['id']]['resolvidos_90d'] ?? 0), 'nota_media' => $perf[$u['id']]['nota_media'] ?? null,
            'media_min_resolucao' => $perf[$u['id']]['media_min_resolucao'] ?? null,
            'resolveu_parecidos' => $exp[(int) $u['id']] ?? [],
        ], $staff);

        $r = $this->client->json([
            ['role' => 'system', 'content' => self::SYSTEM],
            ['role' => 'user', 'content' =>
                'Sugira até 3 técnicos para o chamado, em ordem. Pese experiência em chamados parecidos (mais importante), carga atual '
                . '(evite sobrecarregar quem já tem muitos urgentes), qualidade (nota média) e velocidade. Explique em 1 frase cada escolha.'
                . "\nResponda no JSON: {\"sugestoes\":[{\"id\":<id do técnico>,\"motivo\":\"...\"}]}"
                . "\nCHAMADO:\n" . json_encode(['assunto' => $t['assunto'], 'descricao' => $t['descricao'], 'prioridade' => $t['prioridade'], 'empresa' => $t['empresa']], JSON_UNESCAPED_UNICODE)
                . "\nTÉCNICOS:\n" . json_encode($people, JSON_UNESCAPED_UNICODE)],
        ], 700);

        $byId = array_column($people, null, 'id');
        $out = [];
        foreach (($r['sugestoes'] ?? []) as $sug) {
            $id = (int) ($sug['id'] ?? 0);
            if (!isset($byId[$id]) || isset($out[$id])) {
                continue;
            }
            $pp = $byId[$id];
            $out[$id] = ['user_id' => $id, 'name' => $pp['nome'], 'reason' => (string) ($sug['motivo'] ?? ''),
                         'open_now' => $pp['abertos_agora'], 'similar_solved' => count($pp['resolveu_parecidos']), 'rating' => $pp['nota_media']];
            if (count($out) >= 3) {
                break;
            }
        }
        return ['suggestions' => array_values($out)];
    }

    private function featureOn(string $feature): bool
    {
        $c = $this->settings->all();
        return $c['enabled'] && $c['api_key'] !== '' && !empty($c[$feature]);
    }

    // ── Dados ─────────────────────────────────────────────────────────────────

    private function ticket(int $id): array
    {
        $pdo = $this->connection->pdo();
        $s = $pdo->prepare(
            "SELECT t.*, o.name AS org, ru.name AS requester FROM tickets t
               LEFT JOIN organizations o ON o.id = t.organization_id
               LEFT JOIN users ru ON ru.id = t.requester_id
              WHERE t.id = :id AND t.deleted_at IS NULL"
        );
        $s->execute([':id' => $id]);
        $t = $s->fetch();
        if (!$t) {
            throw new NotFoundException('Ticket');
        }
        $s = $pdo->prepare(
            "SELECT r.body, r.is_private, r.created_at, (r.author_id = :req) AS from_requester
               FROM ticket_replies r WHERE r.ticket_id = :id AND r.deleted_at IS NULL ORDER BY r.id DESC LIMIT 20"
        );
        $s->execute([':id' => $id, ':req' => $t['requester_id']]);
        $replies = array_reverse(array_map(fn($r) => [
            'de' => $r['from_requester'] ? 'cliente' : ($r['is_private'] ? 'equipe (nota interna)' : 'equipe'),
            'texto' => $this->clean(mb_substr((string) $r['body'], 0, 1200)),
        ], $s->fetchAll()));

        return [
            'id' => (int) $t['id'], 'numero' => $t['ticket_number'], 'assunto' => $t['subject'],
            'descricao' => $this->clean(mb_substr((string) $t['description'], 0, 3000)),
            'prioridade' => $t['priority'], 'status' => $t['status'], 'tipo' => $t['type'], 'origem' => $t['source'],
            'empresa' => $t['org'], 'conversa' => $replies,
        ];
    }

    /** Pré-seleção barata por palavras-chave entre chamados resolvidos/fechados. */
    private function candidates(int $excludeId, string $text): array
    {
        $words = $this->keywords($text);
        if (!$words) {
            return [];
        }
        $score = []; $params = [':ex' => $excludeId];
        foreach ($words as $i => $w) {
            $score[] = "(LOWER(t.subject) LIKE :s{$i}) * 3 + (LOWER(t.description) LIKE :d{$i})";
            $params[":s{$i}"] = $params[":d{$i}"] = '%' . $w . '%';
        }
        $scoreSql = implode(' + ', $score);
        $s = $this->connection->pdo()->prepare(
            "SELECT t.id, t.ticket_number, t.subject, LEFT(t.description, 600) AS description, t.resolved_at, t.closed_at, o.name AS org,
                    ({$scoreSql}) AS score
               FROM tickets t LEFT JOIN organizations o ON o.id = t.organization_id
              WHERE t.deleted_at IS NULL AND t.status IN ('resolved','closed') AND t.id <> :ex
             HAVING score >= 3
              ORDER BY score DESC, t.id DESC LIMIT 12"
        );
        $s->execute($params);
        $rows = $s->fetchAll();
        if (!$rows) {
            return [];
        }

        $ids = array_column($rows, 'id');
        $in  = implode(',', array_map('intval', $ids));
        $replies = [];
        foreach ($this->connection->pdo()->query(
            "SELECT r.ticket_id, r.body, r.is_private FROM ticket_replies r
               JOIN tickets t ON t.id = r.ticket_id
              WHERE r.ticket_id IN ({$in}) AND r.deleted_at IS NULL AND r.author_id <> t.requester_id
              ORDER BY r.id DESC"
        ) as $r) {
            if (count($replies[$r['ticket_id']] ?? []) < 3) {
                $replies[$r['ticket_id']][] = ($r['is_private'] ? '[nota interna] ' : '') . $this->clean(mb_substr((string) $r['body'], 0, 700));
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = [
                'id' => (int) $r['id'], 'numero' => $r['ticket_number'], 'assunto' => $r['subject'],
                'descricao' => $this->clean($r['description']), 'empresa' => $r['org'],
                'resolvido_em' => $r['resolved_at'] ?: $r['closed_at'],
                'respostas_da_equipe' => array_reverse($replies[$r['id']] ?? []),
            ];
        }
        return $out;
    }

    /** @return string[] */
    private function keywords(string $text): array
    {
        static $stop = ['para','com','sem','que','não','nao','uma','um','por','mais','como','está','esta','este','essa','esse','isso','meu','minha','nos','das','dos','ao','aos','sua','seu','foi','ser','ter','tem','quando','onde','pois','muito','também','tambem','mesmo','favor','olá','ola','bom','dia','tarde','noite','obrigado','obrigada','preciso','problema','erro','chamado','ainda','hoje','todos','todo','toda','após','apos','antes','depois','sobre','entre','pelo','pela','consigo','consegue'];
        $text = mb_strtolower(preg_replace('/\[signature_\d+\]/', ' ', $text) ?? $text);
        preg_match_all('/[\p{L}\p{N}]{4,}/u', $text, $m);
        $freq = array_count_values(array_filter($m[0], static fn($w) => !in_array($w, $stop, true)));
        arsort($freq);
        return array_slice(array_keys($freq), 0, 8);
    }

    private function assertFeature(string $feature): void
    {
        $c = $this->settings->all();
        if (!$c['enabled'] || $c['api_key'] === '') {
            throw new AiException('A integração com IA está desativada. Um administrador pode ativá-la em Configurações gerais.', 409);
        }
        if (!$c[$feature]) {
            throw new AiException('Este recurso de IA está desativado nas Configurações gerais.', 409);
        }
    }

    private function clean(?string $s): string
    {
        $s = (string) $s;
        if (str_contains($s, '<')) {
            $s = \App\Support\HtmlSanitizer::toText($s);   // respostas em texto rico
        }
        return trim(preg_replace(['/\[signature_\d+\]/', '/\s+/'], ['', ' '], $s) ?? '');
    }

    /** @return string[] */
    private function strList(mixed $v): array
    {
        return array_values(array_filter(array_map(static fn($x) => is_string($x) ? trim($x) : '', is_array($v) ? $v : []), static fn($x) => $x !== ''));
    }
}
