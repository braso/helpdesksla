<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\AuthenticationException;
use RuntimeException;

/**
 * Implementação manual de JWT HS256 (RFC 7519).
 *
 * Escolha intencional: sem dependência externa para este core crítico de segurança.
 * Em projetos com requisitos avançados (RS256, JWKS, key rotation), use lcobucci/jwt.
 *
 * Garantias de segurança desta implementação:
 *   - Algoritmo fixo em HS256 — o cabeçalho "alg" do token nunca é lido/confiado
 *   - Comparação de assinatura com hash_equals (resistente a timing attacks)
 *   - UUID v4 como JTI (JWT ID) para unicidade de cada token emitido
 */
final class JwtService
{
    private const HEADER = '{"alg":"HS256","typ":"JWT"}';

    public function __construct(
        private readonly string $secret,
        private readonly int    $ttl,
    ) {
        if (strlen($this->secret) < 32) {
            throw new RuntimeException('JWT_SECRET deve ter no mínimo 32 caracteres.');
        }
    }

    /**
     * Emite um token JWT assinado com os claims fornecidos.
     *
     * Claims reservados adicionados automaticamente:
     *   jti — ID único do token (usado para revogação por JTI se necessário)
     *   iat — Issued At (Unix timestamp UTC)
     *   exp — Expiration Time (iat + ttl)
     *
     * @param  array<string, mixed> $claims
     */
    public function issue(array $claims): string
    {
        $now     = time();
        $payload = array_merge($claims, [
            'jti' => $this->generateJti(),
            'iat' => $now,
            'exp' => $now + $this->ttl,
        ]);

        $header  = $this->base64UrlEncode(self::HEADER);
        $body    = $this->base64UrlEncode((string) json_encode($payload, JSON_THROW_ON_ERROR));
        $sig     = $this->sign("{$header}.{$body}");

        return "{$header}.{$body}.{$sig}";
    }

    /**
     * Valida estrutura, assinatura e expiração do token.
     * Lança AuthenticationException em qualquer falha — sem vazar detalhes ao cliente.
     *
     * @return array<string, mixed> Payload decodificado
     * @throws AuthenticationException
     */
    public function validate(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new AuthenticationException('Token inválido.');
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;

        // Valida assinatura ANTES de deserializar o payload — evita processar dados não confiáveis.
        $expectedSig = $this->sign("{$headerB64}.{$payloadB64}");
        if (!hash_equals($expectedSig, $sigB64)) {
            throw new AuthenticationException('Token inválido.');
        }

        $payload = json_decode(
            $this->base64UrlDecode($payloadB64),
            associative: true,
            flags: JSON_THROW_ON_ERROR
        );

        if (!is_array($payload) || empty($payload['exp'])) {
            throw new AuthenticationException('Token inválido.');
        }

        if ((int) $payload['exp'] < time()) {
            throw new AuthenticationException('Token expirado.');
        }

        return $payload;
    }

    /** Extrai o JTI sem validar assinatura — para uso em blacklists sem revalidação completa. */
    public function extractJti(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        try {
            $payload = json_decode($this->base64UrlDecode($parts[1]), associative: true);
            return is_array($payload) ? ($payload['jti'] ?? null) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function getTtl(): int
    {
        return $this->ttl;
    }

    private function sign(string $data): string
    {
        return $this->base64UrlEncode(
            hash_hmac('sha256', $data, $this->secret, binary: true)
        );
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        // Restaura padding Base64 padrão antes de decodificar.
        $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=');
        return (string) base64_decode($padded, strict: true);
    }

    private function generateJti(): string
    {
        return bin2hex(random_bytes(16));
    }
}
