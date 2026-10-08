<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Criptografia simétrica (AES-256-CBC) para segredos guardados no banco,
 * como chaves de API. A chave deriva de JWT_SECRET.
 */
final class Crypto
{
    public function __construct(private readonly string $secret) {}

    public function encrypt(string $plain): string
    {
        $iv  = random_bytes(16);
        $enc = openssl_encrypt($plain, 'AES-256-CBC', $this->key(), OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $enc);
    }

    public function decrypt(string $cipher): string
    {
        $raw = base64_decode($cipher, true);
        if ($raw === false || strlen($raw) < 17) {
            return '';
        }
        return openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $this->key(), OPENSSL_RAW_DATA, substr($raw, 0, 16)) ?: '';
    }

    private function key(): string
    {
        return hash('sha256', $this->secret, true);
    }
}
