<?php

declare(strict_types=1);

return [
    // Chave HMAC-SHA256 para assinar JWTs.
    // Deve ter no mínimo 32 bytes aleatórios. Rotacionar via variável de ambiente.
    'jwt_secret' => $_ENV['JWT_SECRET'] ?? '',

    // Tempo de vida do token de acesso em segundos (padrão: 1 hora).
    'jwt_ttl'    => (int) ($_ENV['JWT_TTL'] ?? 3600),
];
