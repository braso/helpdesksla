<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Response;
use App\Services\Attachment\AttachmentService;
use Throwable;

/**
 * Gerencia as configurações de upload de anexos.
 * Acesso restrito a administradores.
 *
 * Rotas:
 *   GET   /api/v1/settings/attachments  — retorna configuração atual
 *   PATCH /api/v1/settings/attachments  — salva configuração
 */
final class AttachmentSettingsController
{
    public function __construct(private readonly AttachmentService $attachmentService) {}

    public function get(): never
    {
        try {
            $settings = $this->attachmentService->getSettings();

            Response::success(data: [
                'max_size_mb'   => $settings['max_size_mb'],
                'allowed_types' => $settings['allowed_types'],
                'allowed_types_list' => array_map('trim', explode(',', $settings['allowed_types'])),
            ]);
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Erro ao carregar configurações de anexos.', statusCode: 500);
        }
    }

    public function patch(): never
    {
        $body = (array) (json_decode((string) file_get_contents('php://input'), true) ?? []);

        $maxSizeMb = isset($body['max_size_mb']) ? (int) $body['max_size_mb'] : null;

        if ($maxSizeMb !== null && ($maxSizeMb < 1 || $maxSizeMb > 100)) {
            Response::error('max_size_mb deve estar entre 1 e 100.', statusCode: 422);
        }

        $rawTypes = isset($body['allowed_types']) ? trim((string) $body['allowed_types']) : null;

        if ($rawTypes !== null) {
            // Sanitiza: minúsculo, remove espaços extras, sem pontos nas extensões
            $types = array_filter(
                array_map(
                    static fn(string $t) => ltrim(trim(strtolower($t)), '.'),
                    explode(',', $rawTypes)
                ),
                static fn(string $t) => $t !== ''
            );

            if (empty($types)) {
                Response::error('allowed_types não pode ser vazio.', statusCode: 422);
            }

            $rawTypes = implode(',', $types);
        }

        try {
            $current = $this->attachmentService->getSettings();

            $this->attachmentService->saveSettings(
                $maxSizeMb ?? $current['max_size_mb'],
                $rawTypes  ?? $current['allowed_types'],
            );

            $updated = $this->attachmentService->getSettings();

            Response::success(
                data: [
                    'max_size_mb'        => $updated['max_size_mb'],
                    'allowed_types'      => $updated['allowed_types'],
                    'allowed_types_list' => array_map('trim', explode(',', $updated['allowed_types'])),
                ],
                message: 'Configurações de anexos salvas com sucesso.'
            );
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Erro ao salvar configurações de anexos.', statusCode: 500);
        }
    }
}
