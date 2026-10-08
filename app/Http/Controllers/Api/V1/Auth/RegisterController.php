<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ValidationException;
use App\Http\Response;
use App\Services\Auth\RegisterService;
use JsonException;
use Throwable;

final class RegisterController
{
    public function __construct(
        private readonly RegisterService $registerService,
    ) {}

    /** POST /api/v1/auth/register */
    public function store(): never
    {
        $rawBody = (string) file_get_contents('php://input');

        if ($rawBody === '') {
            Response::error('O corpo da requisição não pode estar vazio.', statusCode: 400);
        }

        try {
            $data = json_decode($rawBody, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Response::error('JSON inválido: ' . $e->getMessage(), statusCode: 400);
        }

        if (!is_array($data)) {
            Response::error('O corpo da requisição deve ser um objeto JSON.', statusCode: 400);
        }

        try {
            $user = $this->registerService->register($data);
            Response::success(
                data:       $user->toPublicArray(),
                message:    'Cadastro realizado com sucesso.',
                statusCode: 201
            );
        } catch (ValidationException $e) {
            Response::error($e->getMessage(), errors: $e->errors(), statusCode: 422);
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Ocorreu um erro interno.', statusCode: 500);
        }
    }
}
