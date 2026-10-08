<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Cliente mínimo da API DeepSeek (formato compatível com OpenAI: POST /chat/completions).
 * Sempre pede resposta em JSON e devolve o objeto já decodificado.
 */
final class DeepSeekClient implements AiClient
{
    public function __construct(private readonly AiSettings $settings) {}

    /**
     * @param  array<int, array{role: string, content: string}> $messages
     * @return array<string, mixed>
     * @throws AiException
     */
    public function json(array $messages, int $maxTokens = 1500, ?string $model = null): array
    {
        $cfg = $this->settings->provider('deepseek');
        if ($cfg['api_key'] === '') {
            throw new AiException('A chave de API da DeepSeek não está configurada.');
        }
        $model ??= $cfg['model'];

        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => $maxTokens,
            'stream'      => false,
        ];
        // O modelo de raciocínio não aceita response_format nem temperature.
        if ($model === 'deepseek-chat') {
            $body['response_format'] = ['type' => 'json_object'];
            $body['temperature']     = 0.2;
        }

        $ch = curl_init($cfg['base_url'] . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $cfg['api_key']],
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $model === 'deepseek-reasoner' ? 170 : 90,
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new AiException('Não foi possível falar com a DeepSeek: ' . ($err ?: 'sem resposta') . '.');
        }
        $resp = json_decode((string) $raw, true);
        if ($code !== 200) {
            $detail = is_array($resp) ? ($resp['error']['message'] ?? '') : '';
            throw new AiException(match ($code) {
                401     => 'Chave de API inválida. Confira a chave nas Configurações gerais.',
                402     => 'Saldo insuficiente na conta DeepSeek.',
                429     => 'Limite de uso da DeepSeek atingido. Tente de novo em instantes.',
                500, 502, 503 => 'A DeepSeek está indisponível no momento. Tente de novo em instantes.',
                default => "A DeepSeek recusou o pedido (HTTP {$code})" . ($detail ? ": {$detail}" : '.'),
            });
        }

        $content = (string) ($resp['choices'][0]['message']['content'] ?? '');
        // Remove cercas ```json ... ``` caso o modelo as inclua.
        $content = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($content)) ?? $content);
        $data = json_decode($content, true);
        if (!is_array($data)) {
            if (preg_match('/\{.*\}/s', $content, $m)) {
                $data = json_decode($m[0], true);
            }
        }
        if (!is_array($data)) {
            throw new AiException('A IA respondeu em um formato inesperado. Tente de novo.');
        }
        return $data;
    }
}
