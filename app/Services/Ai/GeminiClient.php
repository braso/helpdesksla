<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Cliente da API Google Gemini (generateContent, REST v1beta).
 *
 * Converte as mensagens no formato chat: "system" vira systemInstruction, "assistant" vira
 * o papel "model". Pede JSON com responseMimeType e devolve o objeto já decodificado.
 *
 * Os modelos 2.5 "pensam" antes de responder, e esse raciocínio consome do limite de saída
 * (maxOutputTokens). Por isso o orçamento de raciocínio é limitado e o teto de saída recebe uma
 * folga, para a resposta não vir cortada.
 */
final class GeminiClient implements AiClient
{
    private const THINKING_BUDGET = 1024;
    private bool $migrating = false;

    public function __construct(private readonly AiSettings $settings) {}

    public function json(array $messages, int $maxTokens = 1500, ?string $model = null): array
    {
        $cfg = $this->settings->provider('gemini');
        if ($cfg['api_key'] === '') {
            throw new AiException('A chave de API do Gemini não está configurada.');
        }
        $model ??= $cfg['model'];

        $system = [];
        $contents = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'system') {
                $system[] = $m['content'];
                continue;
            }
            $contents[] = ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $m['content']]]];
        }

        $gen = [
            'responseMimeType' => 'application/json',
            'temperature'      => 0.2,
            'maxOutputTokens'  => $maxTokens + self::THINKING_BUDGET + 512,
        ];
        // 2.5: orçamento em tokens (flash/lite sem raciocínio; pro exige um mínimo).
        // 3.x em diante: nível de raciocínio baixo, para responder rápido e não estourar o limite.
        if (str_starts_with($model, 'gemini-2.5')) {
            $gen['thinkingConfig'] = ['thinkingBudget' => str_contains($model, '-pro') ? self::THINKING_BUDGET : 0];
        } elseif (!str_contains($model, '-latest') && preg_match('/^gemini-[3-9]/', $model)) {
            $gen['thinkingConfig'] = ['thinkingLevel' => 'low'];
        }
        $body = ['contents' => $contents, 'generationConfig' => $gen];
        if ($system) {
            $body['systemInstruction'] = ['parts' => [['text' => implode("\n\n", $system)]]];
        }

        $url = self::apiRoot($cfg['base_url']) . '/models/' . rawurlencode($model) . ':generateContent';
        [$code, $resp, $err] = $this->post($url, $cfg['api_key'], $body, str_contains($model, '-pro') ? 170 : 90);
        // Modelo que não aceita a configuração de raciocínio: tenta de novo sem ela.
        if ($code === 400 && isset($gen['thinkingConfig']) && stripos((string) ($resp['error']['message'] ?? ''), 'think') !== false) {
            unset($body['generationConfig']['thinkingConfig']);
            [$code, $resp, $err] = $this->post($url, $cfg['api_key'], $body, str_contains($model, '-pro') ? 170 : 90);
        }

        if ($code === 0) {
            throw new AiException('Não foi possível falar com o Gemini: ' . ($err ?: 'sem resposta') . '.');
        }
        // Modelo aposentado pelo Google: migra sozinho para o substituto indicado na própria resposta
        // e grava a troca, para o erro não se repetir. Só vale para o modelo salvo e uma vez por pedido.
        if ($code === 404 && !$this->migrating && $model === $cfg['model']
            && stripos((string) ($resp['error']['message'] ?? ''), 'no longer available') !== false
            && preg_match('#use models/(gemini-[a-z0-9.\-]+)#i', (string) $resp['error']['message'], $mm) && $mm[1] !== $model) {
            $this->migrating = true;
            try {
                $out = $this->json($messages, $maxTokens, $mm[1]);
                $this->settings->save(['providers' => ['gemini' => ['model' => $mm[1]]]]);
                error_log("[GeminiClient] modelo {$model} aposentado pelo Google; trocado automaticamente para {$mm[1]}.");
                return $out;
            } finally {
                $this->migrating = false;
            }
        }
        if ($code !== 200) {
            $detail = is_array($resp) ? (string) ($resp['error']['message'] ?? '') : '';
            $status = is_array($resp) ? (string) ($resp['error']['status'] ?? '') : '';
            $badKey = stripos($detail, 'API key') !== false || stripos($detail, 'API_KEY') !== false;
            throw new AiException(match (true) {
                $code === 400 && $badKey, $code === 401 => 'Chave de API do Gemini inválida. Confira a chave nas Configurações gerais.',
                $code === 403 => 'A chave do Gemini não tem permissão para esta API. Ative a "Generative Language API" no projeto da chave.',
                $code === 404 && stripos($detail, 'no longer available') !== false => "O modelo \"{$model}\" não está mais disponível no Gemini"
                    . (preg_match('#use models/([a-z0-9.\-]+)#i', $detail, $mm) ? ". O Google recomenda o {$mm[1]}; escolha-o em Configurações gerais." : '. Escolha outro modelo em Configurações gerais.'),
                $code === 404 => "Modelo \"{$model}\" não encontrado no Gemini. Escolha um modelo da lista ou confira o endereço da API.",
                $code === 429 || $status === 'RESOURCE_EXHAUSTED' => 'Limite de uso do Gemini atingido. Tente de novo em instantes ou confira a cota do projeto.',
                $code >= 500 => 'O Gemini está indisponível no momento. Tente de novo em instantes.',
                default => "O Gemini recusou o pedido (HTTP {$code})" . ($detail ? ": {$detail}" : '.'),
            });
        }

        if (!empty($resp['promptFeedback']['blockReason'])) {
            throw new AiException('O Gemini bloqueou o pedido pelos filtros de segurança (' . $resp['promptFeedback']['blockReason'] . ').');
        }
        $cand = $resp['candidates'][0] ?? null;
        $content = '';
        foreach ($cand['content']['parts'] ?? [] as $part) {
            if (empty($part['thought']) && isset($part['text'])) {
                $content .= $part['text'];
            }
        }
        $finish = (string) ($cand['finishReason'] ?? '');
        if (trim($content) === '') {
            throw new AiException(match ($finish) {
                'SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST' => 'O Gemini não respondeu por causa dos filtros de segurança.',
                'MAX_TOKENS' => 'A resposta do Gemini ficou longa demais e foi cortada. Tente de novo.',
                default => 'O Gemini não devolveu resposta. Tente de novo.',
            });
        }

        $content = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($content)) ?? $content);
        $data = json_decode($content, true);
        if (!is_array($data) && preg_match('/\{.*\}/s', $content, $m)) {
            $data = json_decode($m[0], true);
        }
        if (!is_array($data)) {
            throw new AiException($finish === 'MAX_TOKENS'
                ? 'A resposta do Gemini ficou longa demais e foi cortada. Tente de novo.'
                : 'A IA respondeu em um formato inesperado. Tente de novo.');
        }
        return $data;
    }

    /**
     * Modelos de texto disponíveis para a chave salva (GET /models), sem TTS, imagem, áudio e afins.
     * @return list<array{id: string, label: string}>
     * @throws AiException
     */
    public function listModels(): array
    {
        $cfg = $this->settings->provider('gemini');
        if ($cfg['api_key'] === '') {
            throw new AiException('A chave de API do Gemini não está configurada.');
        }
        $ch = curl_init(self::apiRoot($cfg['base_url']) . '/models?pageSize=1000');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'x-goog-api-key: ' . $cfg['api_key']],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $resp = is_string($raw) ? json_decode($raw, true) : null;
        if ($code !== 200 || !is_array($resp)) {
            throw new AiException($code === 400 || $code === 401 || $code === 403
                ? 'Chave de API do Gemini inválida ou sem acesso à API.'
                : 'Não foi possível consultar os modelos do Gemini.');
        }
        $out = [];
        foreach ($resp['models'] ?? [] as $m) {
            $id = preg_replace('#^models/#', '', (string) ($m['name'] ?? ''));
            if (!preg_match('/^gemini-[a-z0-9][a-z0-9.\-]+$/', $id)
                || !in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true)
                || preg_match('/(tts|image|audio|live|embedding|transcribe|computer-use|robotics|customtools|nano-banana|omni)/', $id)) {
                continue;
            }
            $out[] = ['id' => $id, 'label' => (string) ($m['displayName'] ?? $id)];
        }
        usort($out, static fn($a, $b) => strnatcmp($b['id'], $a['id']));
        return $out;
    }

    /** Aceita o endereço com ou sem /v1beta no fim. */
    private static function apiRoot(string $base): string
    {
        $base = rtrim($base, '/');
        return preg_match('#/v1(beta)?$#', $base) ? $base : $base . '/v1beta';
    }

    /** @return array{0: int, 1: ?array, 2: string} código HTTP (0 = sem conexão), resposta decodificada, erro do curl */
    private function post(string $url, string $key, array $body, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json', 'x-goog-api-key: ' . $key],
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $raw  = curl_exec($ch);
        $code = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $resp = is_string($raw) ? json_decode($raw, true) : null;
        return [$code, is_array($resp) ? $resp : null, $err];
    }
}
