<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    // Envelope padrão para toda resposta da API: { status, message, data|errors }
    // Permite que clientes sempre esperem o mesmo shape, independente do status HTTP.

    public static function json(mixed $payload, int $statusCode = 200): never
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        // X-Content-Type-Options evita MIME-sniffing em clientes antigos.
        header('X-Content-Type-Options: nosniff');

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        exit;
    }

    /** @param array<string, mixed>|null $data */
    public static function success(mixed $data, string $message = '', int $statusCode = 200): never
    {
        self::json([
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
        ], $statusCode);
    }

    /** @param array<string, string[]> $errors */
    public static function error(string $message, array $errors = [], int $statusCode = 400): never
    {
        $payload = ['status' => 'error', 'message' => $message];

        if (!empty($errors)) {
            $payload['errors'] = $errors;
        }

        self::json($payload, $statusCode);
    }
}
