<?php

declare(strict_types=1);

namespace App\Services\Ai;

/** Contrato comum aos provedores de IA: mensagens no formato chat, resposta em JSON decodificado. */
interface AiClient
{
    /**
     * @param  array<int, array{role: string, content: string}> $messages  role: system | user | assistant
     * @return array<string, mixed>
     * @throws AiException
     */
    public function json(array $messages, int $maxTokens = 1500, ?string $model = null): array;
}
