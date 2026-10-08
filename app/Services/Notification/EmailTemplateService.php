<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Core\Database\Connection;
use App\Exceptions\ValidationException;
use App\Support\HtmlSanitizer;
use Throwable;

/**
 * Modelos de e-mail editáveis (Configurações → Modelos de e-mail).
 *
 * Cada modelo tem assunto, corpo em texto rico, rótulo do botão e rodapé, com
 * variáveis no formato {{chamado.numero}}. O texto padrão fica neste arquivo e
 * só as personalizações vão para a tabela `email_templates` — "restaurar padrão"
 * apaga a linha.
 *
 * Blocos prontos que podem ser posicionados no corpo:
 *   {{detalhes}}  tabela com os dados do chamado (montada pelo sistema)
 *   {{mensagem}}  a mensagem do chamado / da resposta, em destaque
 *   {{botao}}     botão de ação com o rótulo configurado
 *
 * Segurança: o corpo é limpo pelo HtmlSanitizer (modo modelo) ao salvar e de novo
 * ao enviar; todo valor de variável é escapado; o assunto não aceita quebra de linha.
 */
final class EmailTemplateService
{
    public const BLOCKS = ['detalhes', 'mensagem', 'botao'];

    /** Variáveis disponíveis: chave => [descrição, exemplo usado na pré-visualização] */
    public const VARIABLES = [
        'usuario.nome'             => ['Nome de quem recebe o e-mail', 'Maria Souza'],
        'usuario.email'            => ['E-mail de quem recebe', 'maria@cliente.com.br'],
        'sistema.link'             => ['Endereço do sistema', 'http://localhost:8080/'],
        'autor.nome'               => ['Quem fez a ação (respondeu, atribuiu…)', 'João Lima'],
        'chamado.numero'           => ['Número do chamado', 'TKT-2026-000123'],
        'chamado.assunto'          => ['Assunto do chamado', 'Impressora do financeiro não imprime'],
        'chamado.status'           => ['Situação atual', 'Em andamento'],
        'chamado.prioridade'       => ['Prioridade', 'Alta'],
        'chamado.empresa'          => ['Empresa do solicitante', 'Cliente Exemplo Ltda'],
        'chamado.solicitante'      => ['Quem abriu o chamado', 'Maria Souza'],
        'chamado.responsavel'      => ['Agente responsável', 'João Lima'],
        'chamado.link'             => ['Link para abrir o chamado', 'http://localhost:8080/#/tickets/123'],
        'chamado.prazo_resposta'   => ['Prazo da 1ª resposta (SLA)', '08/10/2026 às 14:00'],
        'chamado.prazo_resolucao'  => ['Prazo de resolução (SLA)', '09/10/2026 às 18:00'],
        'senha.link'               => ['Link para criar a nova senha', 'http://localhost:8080/#/reset-password/exemplo'],
        'senha.validade'           => ['Validade do link', '30 minutos'],
        'senha.ip'                 => ['IP de onde veio o pedido', '200.100.50.25'],
        'senha.data'               => ['Data e hora da troca de senha', '08/10/2026 às 10:15'],
        'sla.tipo'                 => ['Tipo do alerta de SLA', 'SLA violado'],
        'sla.prazo'                => ['Data limite do SLA', '08/10/2026 às 18:00'],
        'sla.tempo'                => ['Tempo restante ou excedido', '45 min excedido'],
        'detalhes'                 => ['Bloco: tabela com os dados do chamado', ''],
        'mensagem'                 => ['Bloco: mensagem do chamado ou da resposta', ''],
        'botao'                    => ['Bloco: botão de ação', ''],
    ];

    private const BASE   = ['usuario.nome', 'usuario.email', 'sistema.link'];
    private const TICKET = ['chamado.numero', 'chamado.assunto', 'chamado.status', 'chamado.prioridade', 'chamado.empresa',
                            'chamado.solicitante', 'chamado.responsavel', 'chamado.link', 'chamado.prazo_resposta', 'chamado.prazo_resolucao'];
    private const TICKET_FOOTER = 'Você recebe este aviso porque participa do chamado {{chamado.numero}}. Responda pelo sistema, no link acima.';

    /** @var array<string, array>|null personalizações gravadas (cache por requisição) */
    private ?array $custom = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly string     $appUrl,
    ) {}

    /** Definições padrão (texto original de cada e-mail). */
    public static function definitions(): array
    {
        $t = [...self::BASE, ...self::TICKET];
        return [
            'ticket.created.requester' => [
                'group' => 'Chamados', 'name' => 'Chamado aberto — para o cliente',
                'description' => 'Confirmação enviada a quem abriu o chamado.',
                'vars' => $t, 'cta_var' => 'chamado.link',
                'subject' => '[{{chamado.numero}}] Recebemos seu chamado: {{chamado.assunto}}',
                'body' => '<h2>Recebemos seu chamado</h2><p>Olá, {{usuario.nome}}. Seu pedido foi registrado e você receberá um e-mail a cada atualização.</p><p>Prazo para a primeira resposta: <strong>{{chamado.prazo_resposta}}</strong>.</p><p>{{mensagem}}</p><p>{{detalhes}}</p><p>{{botao}}</p>',
                'cta_label' => 'Acompanhar chamado', 'footer' => self::TICKET_FOOTER,
            ],
            'ticket.created.staff' => [
                'group' => 'Chamados', 'name' => 'Chamado aberto — para a equipe',
                'description' => 'Aviso de novo chamado para administradores, agentes e supervisores.',
                'vars' => [...$t, 'autor.nome'], 'cta_var' => 'chamado.link',
                'subject' => '[{{chamado.numero}}] Novo chamado: {{chamado.assunto}}',
                'body' => '<h2>Novo chamado na fila</h2><p>{{chamado.solicitante}} ({{chamado.empresa}}) abriu um chamado.</p><p>{{mensagem}}</p><p>{{detalhes}}</p><p>{{botao}}</p>',
                'cta_label' => 'Abrir chamado', 'footer' => self::TICKET_FOOTER,
            ],
            'ticket.reply.requester' => [
                'group' => 'Respostas', 'name' => 'Nova resposta — para o cliente',
                'description' => 'Quando a equipe responde ao chamado do cliente.',
                'vars' => [...$t, 'autor.nome'], 'cta_var' => 'chamado.link',
                'subject' => '[{{chamado.numero}}] Nova resposta: {{chamado.assunto}}',
                'body' => '<h2>Seu chamado tem uma nova resposta</h2><p>Olá, {{usuario.nome}}. {{autor.nome}}, da equipe de suporte, respondeu ao seu chamado.</p><p>{{mensagem}}</p><p>{{detalhes}}</p><p>{{botao}}</p>',
                'cta_label' => 'Ver e responder', 'footer' => self::TICKET_FOOTER,
            ],
            'ticket.reply.staff' => [
                'group' => 'Respostas', 'name' => 'Nova resposta — para a equipe',
                'description' => 'Quando o cliente (ou outro agente) responde: vai ao responsável ou, sem responsável, à equipe toda.',
                'vars' => [...$t, 'autor.nome'], 'cta_var' => 'chamado.link',
                'subject' => '[{{chamado.numero}}] Nova resposta: {{chamado.assunto}}',
                'body' => '<h2>Nova resposta no chamado</h2><p>{{autor.nome}} respondeu ao chamado.</p><p>{{mensagem}}</p><p>{{detalhes}}</p><p>{{botao}}</p>',
                'cta_label' => 'Abrir chamado', 'footer' => self::TICKET_FOOTER,
            ],
            'ticket.note' => [
                'group' => 'Respostas', 'name' => 'Nota interna — para o responsável',
                'description' => 'Nota visível só para a equipe. Nunca vai para o cliente.',
                'vars' => [...$t, 'autor.nome'], 'cta_var' => 'chamado.link',
                'subject' => '[{{chamado.numero}}] Nota interna: {{chamado.assunto}}',
                'body' => '<h2>Nova nota interna</h2><p>{{autor.nome}} registrou uma nota interna (visível só para a equipe).</p><p>{{mensagem}}</p><p>{{detalhes}}</p><p>{{botao}}</p>',
                'cta_label' => 'Abrir chamado', 'footer' => self::TICKET_FOOTER,
            ],
            'ticket.assigned' => [
                'group' => 'Chamados', 'name' => 'Chamado atribuído — para o agente',
                'description' => 'Quando um agente passa a ser o responsável.',
                'vars' => [...$t, 'autor.nome'], 'cta_var' => 'chamado.link',
                'subject' => '[{{chamado.numero}}] Chamado atribuído a você: {{chamado.assunto}}',
                'body' => '<h2>Um chamado foi atribuído a você</h2><p>{{autor.nome}} definiu você como responsável por este chamado.</p><p>{{mensagem}}</p><p>{{detalhes}}</p><p>{{botao}}</p>',
                'cta_label' => 'Abrir chamado', 'footer' => self::TICKET_FOOTER,
            ],
            'ticket.resolved' => [
                'group' => 'Chamados', 'name' => 'Chamado resolvido — para o cliente',
                'description' => 'Quando a equipe marca o chamado como resolvido.',
                'vars' => $t, 'cta_var' => 'chamado.link',
                'subject' => '[{{chamado.numero}}] Seu chamado foi resolvido: {{chamado.assunto}}',
                'body' => '<h2>Seu chamado foi resolvido</h2><p>A equipe concluiu o atendimento. Se estiver tudo certo, confirme e avalie para encerrar. Se o problema continuar, responda pelo sistema e o chamado volta para a equipe.</p><p>{{detalhes}}</p><p>{{botao}}</p>',
                'cta_label' => 'Confirmar e avaliar', 'footer' => self::TICKET_FOOTER,
            ],
            'ticket.closed' => [
                'group' => 'Chamados', 'name' => 'Chamado encerrado — para o cliente',
                'description' => 'Quando o chamado é encerrado.',
                'vars' => $t, 'cta_var' => 'chamado.link',
                'subject' => '[{{chamado.numero}}] Chamado encerrado: {{chamado.assunto}}',
                'body' => '<h2>Seu chamado foi encerrado</h2><p>Este chamado foi encerrado pela equipe. Se precisar de algo mais, abra um novo chamado.</p><p>{{detalhes}}</p><p>{{botao}}</p>',
                'cta_label' => 'Ver chamado', 'footer' => self::TICKET_FOOTER,
            ],
            'auth.password_reset' => [
                'group' => 'Conta', 'name' => 'Recuperação de senha',
                'description' => 'Link para criar uma nova senha. O link precisa estar no e-mail: mantenha {{botao}} ou {{senha.link}}.',
                'vars' => [...self::BASE, 'senha.link', 'senha.validade', 'senha.ip'], 'cta_var' => 'senha.link', 'required' => ['botao', 'senha.link'],
                'subject' => 'Redefinição de senha — BRASO Helpdesk',
                'body' => '<h2>Redefinir sua senha</h2><p>Olá, {{usuario.nome}}. Recebemos um pedido para criar uma nova senha para a sua conta. O link vale por {{senha.validade}} e só pode ser usado uma vez.</p><p>{{botao}}</p><p>Conta: <strong>{{usuario.email}}</strong><br>Pedido feito a partir do IP {{senha.ip}}</p>',
                'cta_label' => 'Criar nova senha',
                'footer' => 'Se você não pediu a troca, ignore este e-mail: sua senha atual continua valendo. Nunca compartilhe este link.',
            ],
            'auth.password_changed' => [
                'group' => 'Conta', 'name' => 'Senha alterada',
                'description' => 'Confirmação enviada depois que a senha é trocada.',
                'vars' => [...self::BASE, 'senha.data', 'senha.ip'], 'cta_var' => 'sistema.link',
                'subject' => 'Sua senha foi alterada — BRASO Helpdesk',
                'body' => '<h2>Sua senha foi alterada</h2><p>Olá, {{usuario.nome}}. A senha da sua conta acabou de ser redefinida e as sessões abertas foram encerradas.</p><p>Conta: <strong>{{usuario.email}}</strong><br>Quando: {{senha.data}}<br>IP: {{senha.ip}}</p><p>{{botao}}</p>',
                'cta_label' => 'Entrar no sistema',
                'footer' => 'Se não foi você, peça uma nova redefinição imediatamente e avise o administrador do sistema.',
            ],
            'sla.warning' => self::slaDefinition('Alerta de SLA — prazo perto de vencer', '[AVISO SLA] Chamado {{chamado.numero}}',
                'O prazo do chamado abaixo está perto de vencer: {{sla.tempo}}.'),
            'sla.breached' => self::slaDefinition('Alerta de SLA — prazo vencido', '[SLA VIOLADO] Chamado {{chamado.numero}}',
                'O prazo do chamado abaixo venceu ({{sla.tempo}}) e precisa de atenção imediata.'),
            'sla.critical' => self::slaDefinition('Alerta de SLA — escalonamento', '[SLA CRÍTICO] Chamado {{chamado.numero}}',
                'O chamado abaixo continua com o prazo vencido ({{sla.tempo}}) e foi escalonado para você.'),
        ];
    }

    private static function slaDefinition(string $name, string $subject, string $intro): array
    {
        return [
            'group' => 'Alertas de SLA', 'name' => $name,
            'description' => 'Enviado pelos perfis de alerta de SLA aos papéis configurados.',
            'vars' => [...self::BASE, 'chamado.numero', 'chamado.assunto', 'chamado.empresa', 'chamado.prioridade', 'chamado.link', 'sla.tipo', 'sla.prazo', 'sla.tempo'],
            'cta_var' => 'chamado.link',
            'subject' => $subject,
            'body' => "<h2>{{sla.tipo}}</h2><p>{$intro}</p><p>{{detalhes}}</p><p>{{botao}}</p>",
            'cta_label' => 'Abrir chamado',
            'footer' => 'Aviso automático do monitoramento de SLA do BRASO Helpdesk.',
        ];
    }

    // ── Consulta e edição ─────────────────────────────────────────────────────

    /** Lista para a tela de configurações. */
    public function all(): array
    {
        $out = [];
        foreach (self::definitions() as $key => $def) {
            $out[] = $this->describe($key, $def);
        }
        return $out;
    }

    public function find(string $key): array
    {
        $def = self::definitions()[$key] ?? null;
        if ($def === null) {
            throw new \DomainException('Modelo de e-mail não encontrado.');
        }
        return $this->describe($key, $def);
    }

    /** @throws ValidationException */
    public function save(string $key, array $data, ?int $userId): array
    {
        $this->find($key);
        $clean = $this->validate($key, $data);
        $this->connection->pdo()->prepare(
            'INSERT INTO email_templates (template_key, subject, body_html, cta_label, footer, updated_by)
             VALUES (:k, :s, :b, :c, :f, :u)
             ON DUPLICATE KEY UPDATE subject = VALUES(subject), body_html = VALUES(body_html),
                 cta_label = VALUES(cta_label), footer = VALUES(footer), updated_by = VALUES(updated_by)'
        )->execute([':k' => $key, ':s' => $clean['subject'], ':b' => $clean['body'], ':c' => $clean['cta_label'], ':f' => $clean['footer'], ':u' => $userId]);
        $this->custom = null;
        return $this->find($key);
    }

    public function reset(string $key): array
    {
        $this->find($key);
        $this->connection->pdo()->prepare('DELETE FROM email_templates WHERE template_key = :k')->execute([':k' => $key]);
        $this->custom = null;
        return $this->find($key);
    }

    /**
     * Pré-visualização de um rascunho (não salvo) com valores de exemplo.
     *
     * @throws ValidationException
     */
    public function preview(string $key, array $draft): array
    {
        $tpl = $this->validate($key, $draft + $this->find($key));
        return $this->renderTemplate($key, $tpl, $this->sampleVars($key), self::sampleDetails($key), self::sampleQuote($key));
    }

    /** Variáveis de exemplo do modelo (usadas na pré-visualização e no envio de teste). */
    public function sampleVars(string $key): array
    {
        $vars = [];
        foreach (self::VARIABLES as $k => [, $sample]) {
            $vars[$k] = $sample;
        }
        $vars['sistema.link'] = $this->baseUrl() . '/';
        $vars['chamado.link'] = $this->ticketUrl(123);
        $vars['senha.link']   = $this->baseUrl() . '/#/reset-password/exemplo';
        $vars['sla.tipo']     = ['sla.warning' => 'Prazo de SLA perto de vencer', 'sla.breached' => 'SLA violado', 'sla.critical' => 'SLA crítico — escalonamento'][$key] ?? 'SLA violado';
        $vars['sla.tempo']    = $key === 'sla.warning' ? '45 min restantes' : '45 min excedido';
        return $vars;
    }

    // ── Renderização ──────────────────────────────────────────────────────────

    /**
     * Monta assunto e HTML finais de um modelo.
     *
     * @param array<string, ?string> $vars     valores das variáveis (texto puro)
     * @param array<string, ?string> $details  linhas do bloco {{detalhes}}
     * @param array{author?: ?string, text?: ?string}|null $quote  bloco {{mensagem}}
     * @return array{subject: string, html: string}
     */
    public function render(string $key, array $vars, array $details = [], ?array $quote = null): array
    {
        $tpl = $this->find($key);
        $vars += ['sistema.link' => $this->baseUrl() . '/'];
        return $this->renderTemplate($key, $tpl, $vars, $details, $quote);
    }

    public function ticketUrl(int $ticketId): string
    {
        return $this->baseUrl() . '/#/tickets/' . $ticketId;
    }

    public function baseUrl(): string
    {
        return rtrim($this->appUrl, '/');
    }

    private function renderTemplate(string $key, array $tpl, array $vars, array $details, ?array $quote): array
    {
        $def = self::definitions()[$key];
        $e   = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        // Variável sem valor aparece como "—" no texto (nunca como {{…}}).
        $val = static fn(string $k): string => ($vars[$k] ?? '') !== '' && $vars[$k] !== null ? (string) $vars[$k] : '—';

        $subject = preg_replace_callback('/\{\{\s*([a-z_.]+)\s*\}\}/', static fn($m) => in_array($m[1], self::BLOCKS, true) ? '' : $val($m[1]), $tpl['subject']) ?? $tpl['subject'];
        $subject = trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ' ', $subject)) ?? $subject);

        $blocks = [
            'detalhes' => self::detailsHtml($details),
            'mensagem' => self::quoteHtml($quote),
            'botao'    => self::buttonHtml($tpl['cta_label'], (string) ($vars[$def['cta_var']] ?? $this->baseUrl() . '/')),
        ];

        // Ordem: limpa → marca os blocos → estiliza o texto rico → troca variáveis → insere os blocos
        // (os blocos já trazem estilo próprio e não podem passar pelo inlineStyles).
        $body = HtmlSanitizer::clean($tpl['body'], true);
        // Bloco sozinho num parágrafo substitui o parágrafo inteiro (tabela dentro de <p> quebra o layout).
        $body = preg_replace('#<p>\s*\{\{\s*(detalhes|mensagem|botao)\s*\}\}\s*</p>#', "\x01$1\x01", $body) ?? $body;
        $body = self::inlineStyles($body);
        $body = preg_replace_callback('/\{\{\s*([a-z_.]+)\s*\}\}/', static fn($m) => isset($blocks[$m[1]]) ? "\x01{$m[1]}\x01" : $e(str_replace("\x01", '', $val($m[1]))), $body) ?? $body;
        $body = preg_replace_callback('/\x01(detalhes|mensagem|botao)\x01/', static fn($m) => $blocks[$m[1]], $body) ?? $body;

        $footer = preg_replace_callback('/\{\{\s*([a-z_.]+)\s*\}\}/', static fn($m) => in_array($m[1], self::BLOCKS, true) ? '' : $val($m[1]), $tpl['footer']) ?? $tpl['footer'];

        return ['subject' => $subject, 'html' => self::layout($subject, $body, (string) ($vars['chamado.numero'] ?? ''), $footer)];
    }

    // ── Internos ──────────────────────────────────────────────────────────────

    private function describe(string $key, array $def): array
    {
        $custom = $this->custom()[$key] ?? null;
        $vars = [];
        foreach ([...$def['vars'], ...self::BLOCKS] as $v) {
            $vars[] = ['key' => $v, 'label' => self::VARIABLES[$v][0] ?? $v, 'block' => in_array($v, self::BLOCKS, true)];
        }
        return [
            'key'         => $key,
            'group'       => $def['group'],
            'name'        => $def['name'],
            'description' => $def['description'],
            'subject'     => $custom['subject']   ?? $def['subject'],
            'body'        => $custom['body_html'] ?? $def['body'],
            'cta_label'   => $custom['cta_label'] ?? $def['cta_label'],
            'footer'      => $custom['footer']    ?? $def['footer'],
            'is_custom'   => $custom !== null,
            'updated_at'  => $custom['updated_at'] ?? null,
            'updated_by'  => $custom['updated_by_name'] ?? null,
            'variables'   => $vars,
        ];
    }

    private function custom(): array
    {
        if ($this->custom === null) {
            $this->custom = [];
            try {
                $rows = $this->connection->pdo()->query(
                    'SELECT t.*, u.name AS updated_by_name FROM email_templates t LEFT JOIN users u ON u.id = t.updated_by'
                )->fetchAll();
                foreach ($rows as $r) {
                    $this->custom[$r['template_key']] = $r;
                }
            } catch (Throwable $e) {
                error_log('[EmailTemplateService] ' . $e->getMessage()); // tabela ausente: usa os padrões
            }
        }
        return $this->custom;
    }

    /** @throws ValidationException */
    private function validate(string $key, array $data): array
    {
        $def     = self::definitions()[$key];
        $subject = trim(str_replace(["\r", "\n"], ' ', (string) ($data['subject'] ?? '')));
        $body    = HtmlSanitizer::clean((string) ($data['body'] ?? ''), true);
        $cta     = trim((string) ($data['cta_label'] ?? ''));
        $footer  = trim(str_replace(["\r", "\n"], ' ', (string) ($data['footer'] ?? '')));

        $errors = [];
        if ($subject === '') {
            $errors['subject'][] = 'Informe o assunto.';
        } elseif (mb_strlen($subject) > 255) {
            $errors['subject'][] = 'O assunto pode ter até 255 caracteres.';
        }
        if (!HtmlSanitizer::hasText(preg_replace('/\{\{\s*(detalhes|mensagem|botao)\s*\}\}/', 'x', $body) ?? $body)) {
            $errors['body'][] = 'Escreva o texto do e-mail.';
        } elseif (strlen($body) > 60000) {
            $errors['body'][] = 'O texto do e-mail está longo demais.';
        }
        if (mb_strlen($cta) > 60) {
            $errors['cta_label'][] = 'O texto do botão pode ter até 60 caracteres.';
        } elseif ($cta === '' && preg_match('/\{\{\s*botao\s*\}\}/', $body)) {
            $errors['cta_label'][] = 'Informe o texto do botão (o corpo usa {{botao}}).';
        }
        if (mb_strlen($footer) > 500) {
            $errors['footer'][] = 'O rodapé pode ter até 500 caracteres.';
        }

        // Variáveis que este modelo não conhece (erro de digitação, ou de outro modelo).
        $allowed = [...$def['vars'], ...self::BLOCKS];
        foreach (['subject' => $subject, 'body' => $body, 'footer' => $footer] as $field => $text) {
            preg_match_all('/\{\{\s*([^}]*?)\s*\}\}/', $text, $m);
            $unknown = array_values(array_unique(array_diff($m[1], $allowed)));
            if ($unknown) {
                $errors[$field][] = 'Variável que não existe neste modelo: ' . implode(', ', array_map(static fn($v) => '{{' . $v . '}}', $unknown)) . '.';
            }
        }
        if (!empty($def['required'])) {
            $uses = array_filter($def['required'], static fn($v) => preg_match('/\{\{\s*' . preg_quote($v, '/') . '\s*\}\}/', $body));
            if (!$uses) {
                $errors['body'][] = 'Este e-mail precisa levar o link: use {{' . implode('}} ou {{', $def['required']) . '}}.';
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return ['subject' => $subject, 'body' => $body, 'cta_label' => $cta, 'footer' => $footer];
    }

    private static function detailsHtml(array $details): string
    {
        $e = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rows = '';
        foreach ($details as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $rows .= '<tr><td style="padding:6px 0;color:#5B7189;font-size:13px;width:38%;vertical-align:top">' . $e((string) $label) . '</td>'
                   . '<td style="padding:6px 0;color:#0F2035;font-size:13px;font-weight:600">' . $e((string) $value) . '</td></tr>';
        }
        return $rows === '' ? '' : '<table width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #DCE4EE;padding-top:8px;margin:0 0 20px">' . $rows . '</table>';
    }

    private static function quoteHtml(?array $quote): string
    {
        $text = trim(preg_replace('/\[signature_\d+\]/', '', (string) ($quote['text'] ?? '')) ?? '');
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) > 800) {
            $text = mb_substr($text, 0, 797) . '…';
        }
        $e = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        return '<table width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 20px"><tr><td style="background:#F4F8FC;border:1px solid #DCE4EE;border-radius:8px;padding:14px 16px">'
             . (!empty($quote['author']) ? '<div style="font-size:12px;font-weight:700;color:#3D5670;margin-bottom:6px">' . $e($quote['author']) . '</div>' : '')
             . '<div style="font-size:14px;line-height:1.6;color:#0F2035">' . nl2br($e($text)) . '</div></td></tr></table>';
    }

    private static function buttonHtml(string $label, string $url): string
    {
        if (trim($label) === '') {
            return '';
        }
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return '<table cellpadding="0" cellspacing="0" style="margin:4px 0 20px"><tr><td style="background:#0070D9;border-radius:6px">'
             . '<a href="' . $e($url) . '" target="_blank" style="display:inline-block;color:#FFFFFF;text-decoration:none;font-size:14px;font-weight:600;padding:11px 20px">' . $e($label) . '</a>'
             . '</td></tr></table>';
    }

    /** Clientes de e-mail ignoram <style>: aplica o estilo direto nas tags do texto rico. */
    private static function inlineStyles(string $html): string
    {
        $styles = [
            'h2'         => 'margin:0 0 10px;font-size:20px;line-height:1.3;color:#0F2035',
            'h3'         => 'margin:0 0 8px;font-size:16px;line-height:1.35;color:#0F2035',
            'p'          => 'margin:0 0 14px;font-size:14px;line-height:1.6;color:#3D5670',
            'ul'         => 'margin:0 0 14px;padding-left:22px;font-size:14px;line-height:1.6;color:#3D5670',
            'ol'         => 'margin:0 0 14px;padding-left:22px;font-size:14px;line-height:1.6;color:#3D5670',
            'blockquote' => 'margin:0 0 14px;padding:2px 0 2px 12px;border-left:3px solid #C9D6E3;color:#5B7189',
            'pre'        => 'margin:0 0 14px;padding:10px 12px;background:#F4F8FC;border-radius:6px;font-size:13px;white-space:pre-wrap',
            'code'       => 'font-family:Consolas,Menlo,monospace;font-size:13px;background:#F4F8FC;padding:1px 4px;border-radius:3px',
        ];
        foreach ($styles as $tag => $css) {
            $html = str_replace("<{$tag}>", "<{$tag} style=\"{$css}\">", $html);
        }
        return str_replace('<a href=', '<a style="color:#0070D9" href=', $html);
    }

    private static function layout(string $title, string $body, string $badge, string $footer): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{$e($title)}</title></head>
<body style="margin:0;padding:0;background:#F4F8FC;font-family:'Segoe UI',Arial,sans-serif;color:#0F2035">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#F4F8FC;padding:32px 12px"><tr><td align="center">
  <table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#FFFFFF;border:1px solid #DCE4EE;border-radius:10px;overflow:hidden">
    <tr><td style="background:#0B1929;padding:16px 24px">
      <span style="display:inline-block;width:18px;height:18px;background:#0070D9;border-radius:4px;vertical-align:middle"></span>
      <span style="font-size:16px;font-weight:800;letter-spacing:-.5px;color:#FFFFFF;vertical-align:middle;margin-left:8px">BRASO</span>
      <span style="font-size:12px;color:#A9BCD0;vertical-align:middle;margin-left:4px">Helpdesk</span>
      <span style="float:right;font-size:12px;color:#A9BCD0;line-height:18px">{$e($badge)}</span>
    </td></tr>
    <tr><td style="padding:28px 24px 8px">{$body}</td></tr>
    <tr><td style="padding:12px 24px 24px;font-size:12px;line-height:1.5;color:#5B7189;border-top:1px solid #EEF2F7">{$e($footer)}</td></tr>
  </table>
</td></tr></table>
</body></html>
HTML;
    }

    private static function sampleDetails(string $key): array
    {
        if (str_starts_with($key, 'auth.')) {
            return [];
        }
        if (str_starts_with($key, 'sla.')) {
            return ['Chamado' => 'TKT-2026-000123', 'Assunto' => 'Impressora do financeiro não imprime', 'Cliente' => 'Cliente Exemplo Ltda', 'Data limite' => '08/10/2026 às 18:00'];
        }
        return ['Chamado' => 'TKT-2026-000123', 'Empresa' => 'Cliente Exemplo Ltda', 'Prioridade' => 'Alta', 'Responsável' => 'João Lima'];
    }

    private static function sampleQuote(string $key): ?array
    {
        return str_starts_with($key, 'ticket.')
            ? ['author' => 'Maria Souza', 'text' => "A impressora do financeiro parou de imprimir desde hoje cedo.\nJá reiniciei, mas continua com erro."]
            : null;
    }
}
