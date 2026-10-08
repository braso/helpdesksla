<?php

declare(strict_types=1);

namespace App\Services\Report;

use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

/**
 * Gera exportações de relatórios em CSV, PDF e TXT tabulado.
 *
 * Cada método recebe as colunas e linhas já formatadas e produz
 * o conteúdo binário do arquivo, com os headers HTTP corretos.
 */
final class ReportExportService
{
    /** Emite CSV e termina a execução com download. */
    public function streamCsv(
        string $reportName,
        array  $columns,
        array  $rows,
        string $separator = ';'
    ): never {
        $filename = $this->filename($reportName, 'csv');

        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');

        // BOM para compatibilidade com Excel
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, $columns, $separator);
        foreach ($rows as $row) {
            fputcsv($out, $row, $separator);
        }

        fclose($out);
        exit;
    }

    /** Emite PDF profissional e termina a execução com download. */
    public function streamPdf(
        string $reportName,
        string $generatedBy,
        array  $columns,
        array  $rows,
        string $orientation = 'landscape'
    ): never {
        $filename = $this->filename($reportName, 'pdf');
        $html     = $this->buildPdfHtml($reportName, $generatedBy, $columns, $rows);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'sans-serif');
        $options->set('dpi', 96);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();

        $pdf  = $dompdf->output();
        $size = strlen($pdf);

        header('Content-Type: application/pdf');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header("Content-Length: {$size}");
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Content-Type-Options: nosniff');

        echo $pdf;
        exit;
    }

    /** Emite TXT tabulado e termina a execução com download. */
    public function streamTxt(
        string $reportName,
        array  $columns,
        array  $rows,
        bool   $includeHeader = true
    ): never {
        $filename = $this->filename($reportName, 'txt');

        header('Content-Type: text/plain; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header('Cache-Control: no-cache, no-store, must-revalidate');

        if ($includeHeader) {
            echo implode("\t", $columns) . "\r\n";
        }

        foreach ($rows as $row) {
            echo implode("\t", array_map(
                static fn($v) => str_replace(["\t", "\r", "\n"], ' ', (string) $v),
                $row
            )) . "\r\n";
        }

        exit;
    }

    // ─── HTML template para o PDF ────────────────────────────────────────────

    private function buildPdfHtml(
        string $reportName,
        string $generatedBy,
        array  $columns,
        array  $rows
    ): string {
        $now      = date('d/m/Y H:i:s');
        $totalRows = count($rows);

        $headerCells = implode('', array_map(
            static fn(string $c) => "<th>" . htmlspecialchars($c, ENT_QUOTES) . "</th>",
            $columns
        ));

        $bodyRows = '';
        foreach ($rows as $i => $row) {
            $cls = $i % 2 === 0 ? 'even' : 'odd';
            $cells = implode('', array_map(
                static fn($v) => "<td>" . htmlspecialchars((string) $v, ENT_QUOTES) . "</td>",
                $row
            ));
            $bodyRows .= "<tr class=\"{$cls}\">{$cells}</tr>\n";
        }

        return <<<HTML
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head>
        <meta charset="UTF-8">
        <style>
          * { box-sizing: border-box; margin: 0; padding: 0; }
          body { font-family: Arial, sans-serif; font-size: 10px; color: #1e293b; }
          .header { background: #4f46e5; color: #fff; padding: 16px 20px; margin-bottom: 16px; }
          .header h1 { font-size: 16px; font-weight: 700; margin-bottom: 4px; }
          .header .meta { font-size: 9px; opacity: .85; }
          .meta-row { display: flex; gap: 20px; margin-bottom: 12px; padding: 0 4px; font-size: 9px; color: #64748b; }
          table { width: 100%; border-collapse: collapse; }
          th { background: #4f46e5; color: #fff; padding: 7px 8px; text-align: left; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
          td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; font-size: 9.5px; }
          tr.even td { background: #f8fafc; }
          tr.odd td  { background: #fff; }
          .footer { position: fixed; bottom: 0; left: 0; right: 0; text-align: center; font-size: 8px; color: #94a3b8; padding: 6px; border-top: 1px solid #e2e8f0; }
          .total { margin-top: 10px; text-align: right; font-size: 9px; color: #64748b; padding-right: 4px; }
        </style>
        </head>
        <body>
          <div class="header">
            <h1>Helpdesk — {$reportName}</h1>
            <div class="meta">Gerado em {$now} por {$generatedBy}</div>
          </div>
          <table>
            <thead><tr>{$headerCells}</tr></thead>
            <tbody>{$bodyRows}</tbody>
          </table>
          <div class="total">{$totalRows} registro(s)</div>
          <div class="footer">Helpdesk — {$reportName} — Pág. <span class="pageNumber"></span> de <span class="pageCount"></span></div>
        </body>
        </html>
        HTML;
    }

    private function filename(string $reportName, string $ext): string
    {
        $safe = preg_replace('/[^a-z0-9]+/', '-', strtolower($reportName));
        return "relatorio-{$safe}-" . date('Y-m-d') . ".{$ext}";
    }

    // ─── Formatadores de dados por tipo de relatório ─────────────────────────

    /** Transforma a resposta de /reports/overview em colunas+linhas exportáveis. */
    public static function overviewToRows(array $d): array
    {
        $columns = ['Métrica', 'Valor', 'Observação'];
        $st      = $d['by_status'] ?? [];

        $rows = [
            ['Total de Tickets',           $d['total'] ?? 0,                ''],
            ['Abertos',                    $st['open'] ?? 0,                ''],
            ['Em Andamento',               $st['in_progress'] ?? 0,         ''],
            ['Pendentes',                  $st['pending'] ?? 0,             ''],
            ['Resolvidos',                 $st['resolved'] ?? 0,            ''],
            ['Fechados',                   $st['closed'] ?? 0,              ''],
            ['Abertos (7 dias)',           $d['opened_7d'] ?? 0,            'últimos 7 dias'],
            ['Resolvidos (7 dias)',        $d['resolved_7d'] ?? 0,          'últimos 7 dias'],
            ['Abertos (30 dias)',          $d['opened_30d'] ?? 0,           'últimos 30 dias'],
            ['Resolvidos (30 dias)',       $d['resolved_30d'] ?? 0,         'últimos 30 dias'],
            ['SLA Cumprido (%)',           isset($d['sla_compliance']) ? $d['sla_compliance'].'%' : '—', ''],
            ['SLA Violados',              $d['sla_breached'] ?? 0,          ''],
            ['Tempo Médio Resolução',      isset($d['avg_resolution_sec']) ? self::fmtSec((int)$d['avg_resolution_sec']) : '—', ''],
            ['Tempo Médio 1ª Resposta',   isset($d['avg_frt_sec'])        ? self::fmtSec((int)$d['avg_frt_sec'])        : '—', ''],
        ];

        foreach ($d['by_priority'] ?? [] as $p) {
            $rows[] = [
                "Prioridade {$p['priority']} — Total",    $p['total'],    ''];
            $rows[] = [
                "Prioridade {$p['priority']} — Violados", $p['breached'], ''];
        }

        return [$columns, $rows];
    }

    public static function agentsToRows(array $d): array
    {
        $columns = [
            'ID', 'Nome', 'E-mail', 'Papel',
            'Tickets Atribuídos', 'Resolvidos', 'SLA Violados',
            'Tempo Médio Resolução', 'Tempo Médio 1ª Resposta',
            'Total Registrado', 'Sessões',
        ];

        $rows = array_map(static fn(array $r) => [
            $r['id'],
            $r['name'],
            $r['email'],
            $r['role'],
            $r['assigned_total'],
            $r['resolved_total'],
            $r['sla_breached'],
            isset($r['avg_resolution_sec']) ? self::fmtSec((int)$r['avg_resolution_sec']) : '—',
            isset($r['avg_frt_sec'])        ? self::fmtSec((int)$r['avg_frt_sec'])        : '—',
            self::fmtSec((int)($r['total_tracked_sec'] ?? 0)),
            $r['total_sessions'] ?? 0,
        ], $d['items'] ?? []);

        return [$columns, $rows];
    }

    public static function organizationsToRows(array $d): array
    {
        $columns = [
            'ID', 'Empresa', 'Domínio',
            'Total Tickets', 'Resolvidos', 'Abertos', 'Em Andamento',
            'SLA Violados', 'Tempo Médio Resolução', 'Usuários',
        ];

        $rows = array_map(static fn(array $r) => [
            $r['id'],
            $r['name'],
            $r['domain'] ?? '—',
            $r['total_tickets'],
            $r['resolved'],
            $r['open_tickets'],
            $r['in_progress'],
            $r['sla_breached'],
            isset($r['avg_resolution_sec']) ? self::fmtSec((int)$r['avg_resolution_sec']) : '—',
            $r['user_count'],
        ], $d['items'] ?? []);

        return [$columns, $rows];
    }

    public static function slaToRows(array $d): array
    {
        $columns = [
            'Seção', 'Prioridade / Ticket', 'Detalhes',
            'Total', 'FRT Violados', 'RT Violados',
            'Média FRT (min)', 'Média RT (min)', 'Minutos Restantes / Atrasado',
        ];

        $rows = [];

        foreach ($d['by_priority'] ?? [] as $p) {
            $rows[] = [
                'Por Prioridade',
                ucfirst($p['priority']),
                '',
                $p['total'],
                $p['frt_breached'],
                $p['rt_breached'],
                $p['avg_frt_min'] ?? '—',
                $p['avg_rt_min']  ?? '—',
                '',
            ];
        }

        foreach ($d['expiring_soon'] ?? [] as $t) {
            $rows[] = [
                'Vencendo em 24h',
                $t['ticket_number'],
                $t['subject'],
                '',
                '',
                '',
                '',
                '',
                $t['minutes_left'] . ' min restantes',
            ];
        }

        foreach ($d['breached_open'] ?? [] as $t) {
            $rows[] = [
                'Violados Abertos',
                $t['ticket_number'],
                $t['subject'],
                '',
                '',
                '',
                '',
                '',
                $t['minutes_overdue'] . ' min atrasado',
            ];
        }

        return [$columns, $rows];
    }

    private static function fmtSec(int $sec): string
    {
        if ($sec < 60)   return "{$sec}s";
        if ($sec < 3600) return round($sec / 60) . 'min';
        $h = floor($sec / 3600);
        $m = round(($sec % 3600) / 60);
        return $m > 0 ? "{$h}h {$m}min" : "{$h}h";
    }
}
