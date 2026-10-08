<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Exceptions\ValidationException;
use App\Http\AuthContext;
use App\Http\Response;
use App\Services\Notification\EmailTemplateService;
use App\Services\Notification\NotificationService;
use DomainException;
use Throwable;

/**
 * Modelos de e-mail editáveis em texto rico. Somente administradores.
 *
 * Rotas:
 *   GET    /api/v1/settings/email-templates               — lista com variáveis disponíveis
 *   PUT    /api/v1/settings/email-templates/{key}         — salva personalização
 *   DELETE /api/v1/settings/email-templates/{key}         — restaura o texto padrão
 *   POST   /api/v1/settings/email-templates/{key}/preview — pré-visualiza rascunho (não salva)
 *   POST   /api/v1/settings/email-templates/{key}/test    — envia o rascunho com dados de exemplo para o admin
 */
final class EmailTemplateController
{
    public function __construct(
        private readonly EmailTemplateService $templates,
        private readonly NotificationService  $notificationService,
    ) {}

    public function index(): never
    {
        Response::success(data: $this->templates->all());
    }

    public function update(string $key): never
    {
        $this->handle(fn() => Response::success(
            data: $this->templates->save($key, $this->body(), AuthContext::user()->id),
            message: 'Modelo salvo. Os próximos e-mails já usam o novo texto.',
        ));
    }

    public function reset(string $key): never
    {
        $this->handle(fn() => Response::success(data: $this->templates->reset($key), message: 'Texto padrão restaurado.'));
    }

    public function preview(string $key): never
    {
        $this->handle(fn() => Response::success(data: $this->templates->preview($key, $this->body())));
    }

    public function test(string $key): never
    {
        $this->handle(function () use ($key): never {
            $user = AuthContext::user();
            $mail = $this->templates->preview($key, $this->body());
            $id = $this->notificationService->queueEmail(
                $user->id, $user->email, $user->name, 'template.test',
                '[Teste] ' . $mail['subject'], $mail['html'], ['template' => $key],
            );
            if ($id === null) {
                Response::error('Não foi possível colocar o e-mail de teste na fila.', statusCode: 500);
            }
            Response::success(data: ['to' => $user->email], message: "E-mail de teste enviado para {$user->email}, com dados de exemplo.");
        });
    }

    private function handle(callable $fn): never
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            Response::error('Revise os campos destacados.', $e->errors(), 422);
        } catch (DomainException $e) {
            Response::error($e->getMessage(), statusCode: 404);
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Erro ao processar o modelo de e-mail.', statusCode: 500);
        }
        exit;
    }

    private function body(): array
    {
        return (array) (json_decode((string) file_get_contents('php://input'), true) ?? []);
    }
}
