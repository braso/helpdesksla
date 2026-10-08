<?php

declare(strict_types=1);

namespace App\Services\Ai;

/** Encaminha cada pedido ao provedor ativo nas Configurações gerais (DeepSeek ou Gemini). */
final class AiProviderClient implements AiClient
{
    /** @param array<string, AiClient> $clients */
    public function __construct(
        private readonly AiSettings $settings,
        private readonly array      $clients,
    ) {}

    public function json(array $messages, int $maxTokens = 1500, ?string $model = null): array
    {
        $p = $this->settings->all()['provider'];
        $client = $this->clients[$p] ?? throw new AiException('Provedor de IA não disponível.');
        return $client->json($messages, $maxTokens, $model);
    }
}
