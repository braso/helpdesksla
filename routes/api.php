<?php

declare(strict_types=1);

use App\Core\Router;
use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationController;
use App\Http\Controllers\Api\V1\Tickets\TicketController;
use App\Http\Controllers\Api\V1\Tickets\TicketReplyController;
use App\Http\Controllers\Api\V1\Tickets\TimeTrackingController;
use App\Http\Controllers\Api\V1\Users\UserController;
use App\Http\Controllers\Api\V1\Reports\ReportController;
use App\Http\Controllers\Api\V1\Email\EmailAccountController;
use App\Http\Controllers\Api\V1\Sla\SlaAlertController;
use App\Http\Controllers\Api\V1\Sla\SlaPolicyController;
use App\Http\Controllers\Api\V1\Attachments\AttachmentController;
use App\Http\Controllers\Api\V1\Settings\AttachmentSettingsController;
use App\Http\Controllers\Api\V1\Settings\SettingsController;
use App\Http\Controllers\Api\V1\Settings\EmailTemplateController;
use App\Http\Controllers\Api\V1\Ai\AiController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;

return static function (
    Router                       $router,
    AuthMiddleware               $auth,
    LoginController              $loginController,
    LogoutController             $logoutController,
    MeController                 $meController,
    RegisterController           $registerController,
    OrganizationController       $orgController,
    TicketController             $ticketController,
    TicketReplyController        $replyController,
    TimeTrackingController       $timeController,
    UserController               $userController,
    ReportController             $reportController,
    EmailAccountController       $emailController,
    SlaAlertController           $slaAlertController,
    SettingsController           $settingsController,
    AttachmentController         $attachmentController,
    AttachmentSettingsController $attachmentSettingsController,
    AiController                 $aiController,
    PasswordResetController      $passwordResetController,
    SlaPolicyController          $slaPolicyController,
    EmailTemplateController      $emailTemplateController,
): void {

    $router->group('/api/v1', function (Router $router) use (
        $auth,
        $loginController,
        $logoutController,
        $meController,
        $registerController,
        $orgController,
        $ticketController,
        $replyController,
        $timeController,
        $userController,
        $reportController,
        $emailController,
        $slaAlertController,
        $settingsController,
        $attachmentController,
        $attachmentSettingsController,
        $aiController,
        $passwordResetController,
        $slaPolicyController,
        $emailTemplateController,
    ): void {

        // ── Rotas públicas ────────────────────────────────────────────────────
        $router->post('/auth/login',          fn () => $loginController->store());
        $router->post('/auth/register',       fn () => $registerController->store());
        $router->get('/organizations/public', fn () => $orgController->listPublic());
        $router->post('/auth/forgot-password',      fn () => $passwordResetController->forgot());
        $router->post('/auth/reset-password/check', fn () => $passwordResetController->check());
        $router->post('/auth/reset-password',       fn () => $passwordResetController->reset());

        // ── Rotas protegidas ──────────────────────────────────────────────────
        $router->group('', function (Router $router) use (
            $logoutController, $meController,
            $orgController,
            $ticketController, $replyController, $timeController,
            $userController, $reportController, $emailController,
            $slaAlertController, $settingsController,
            $attachmentController, $attachmentSettingsController,
            $aiController,
            $slaPolicyController,
            $emailTemplateController,
        ): void {

            // Auth
            $router->post('/auth/logout', fn () => $logoutController->store());
            $router->get('/auth/me',      fn () => $meController->show());

            // Usuários (admin gerencia; admin/agente/supervisor lista)
            $router->get('/users', fn () => $userController->index())
                   ->middleware(fn () => (new RoleMiddleware(['admin', 'agent', 'supervisor']))->handle());
            $router->post('/users', fn () => $userController->store())
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->get('/users/{id}', fn (string $id) => $userController->show((int) $id))
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->patch('/users/{id}', fn (string $id) => $userController->update((int) $id))
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->delete('/users/{id}', fn (string $id) => $userController->destroy((int) $id))
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());

            // Empresas
            $router->get('/organizations', fn () => $orgController->index())
                   ->middleware(fn () => (new RoleMiddleware(['admin', 'agent', 'supervisor']))->handle());
            $router->post('/organizations', fn () => $orgController->store())
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->get('/organizations/{id}', fn (string $id) => $orgController->show((int) $id))
                   ->middleware(fn () => (new RoleMiddleware(['admin', 'agent', 'supervisor']))->handle());
            $router->patch('/organizations/{id}', fn (string $id) => $orgController->update((int) $id))
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());

            // Tickets
            $router->get('/tickets', fn () => $ticketController->index())
                   ->middleware(fn () => (new PermissionMiddleware('tickets.view'))->handle());
            $router->post('/tickets', fn () => $ticketController->store())
                   ->middleware(fn () => (new PermissionMiddleware('tickets.create'))->handle());
            $router->get('/tickets/{id}', fn (string $id) => $ticketController->show((int) $id))
                   ->middleware(fn () => (new PermissionMiddleware('tickets.view'))->handle());
            $router->patch('/tickets/{id}', fn (string $id) => $ticketController->update((int) $id))
                   ->middleware(fn () => (new PermissionMiddleware('tickets.edit'))->handle());
            $router->post('/tickets/{id}/rate', fn (string $id) => $ticketController->rate((int) $id))
                   ->middleware(fn () => (new PermissionMiddleware('tickets.view'))->handle());

            // Replies
            $router->get('/tickets/{id}/replies', fn (string $id) => $replyController->index((int) $id))
                   ->middleware(fn () => (new PermissionMiddleware('tickets.view'))->handle());
            $router->post('/tickets/{id}/replies', fn (string $id) => $replyController->store((int) $id))
                   ->middleware(fn () => (new PermissionMiddleware('tickets.create'))->handle());

            // Contas de e-mail (admin)
            $router->get('/email-accounts',                       fn () => $emailController->index())
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->post('/email-accounts',                      fn () => $emailController->store())
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->patch('/email-accounts/{id}',  fn (string $id) => $emailController->update((int)$id))
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->delete('/email-accounts/{id}', fn (string $id) => $emailController->destroy((int)$id))
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->post('/email-accounts/{id}/test',  fn (string $id) => $emailController->test((int)$id))
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->post('/email-accounts/{id}/fetch', fn (string $id) => $emailController->fetch((int)$id))
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->post('/email-accounts/fetch-all',  fn () => $emailController->fetchAll())
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());

            // Relatórios — gerente vê apenas sua empresa (filtrado no controller)
            $router->get('/reports/overview',      fn () => $reportController->overview())
                   ->middleware(fn () => (new RoleMiddleware(['admin', 'agent', 'supervisor', 'manager']))->handle());
            $router->get('/reports/agents',        fn () => $reportController->agents())
                   ->middleware(fn () => (new RoleMiddleware(['admin', 'agent', 'supervisor']))->handle());
            $router->get('/reports/organizations', fn () => $reportController->organizations())
                   ->middleware(fn () => (new RoleMiddleware(['admin', 'agent', 'supervisor', 'manager']))->handle());
            $router->get('/reports/sla',           fn () => $reportController->sla())
                   ->middleware(fn () => (new RoleMiddleware(['admin', 'agent', 'supervisor', 'manager']))->handle());

            // Exportação de relatórios (requer permissão report:export)
            $router->get('/reports/{type}/export', fn (string $type) => $reportController->export($type))
                   ->middleware(fn () => (new RoleMiddleware(['admin', 'agent', 'supervisor', 'manager']))->handle());

            // Alertas de SLA (admin)
            // Políticas de SLA (planos, empresas vinculadas, horário comercial)
            $router->get('/sla-policies',                 fn () => $slaPolicyController->index())
                   ->middleware(fn () => (new RoleMiddleware(['admin', 'supervisor']))->handle());
            $router->post('/sla-policies',                fn () => $slaPolicyController->store())
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->put('/sla-policies/hours',           fn () => $slaPolicyController->saveHours())
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->put('/sla-policies/{id}',            fn (string $id) => $slaPolicyController->update((int) $id))
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->delete('/sla-policies/{id}',         fn (string $id) => $slaPolicyController->destroy((int) $id))
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->post('/sla-policies/{id}/default',   fn (string $id) => $slaPolicyController->makeDefault((int) $id))
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->put('/organizations/{id}/sla-policy', fn (string $id) => $slaPolicyController->assignOrganization((int) $id))
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());

            $router->get('/sla-alerts',              fn () => $slaAlertController->index())
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->post('/sla-alerts',             fn () => $slaAlertController->store())
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->patch('/sla-alerts/{id}',       fn (string $id) => $slaAlertController->update((int)$id))
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->delete('/sla-alerts/{id}',      fn (string $id) => $slaAlertController->destroy((int)$id))
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->get('/sla-alerts/logs',         fn () => $slaAlertController->logs())
                   ->middleware(fn () => (new RoleMiddleware(['admin', 'supervisor']))->handle());
            $router->get('/sla-alerts/roles',        fn () => $slaAlertController->roles())
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->post('/sla-alerts/test-email',  fn () => $slaAlertController->testEmail())
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());

            // Anexos de tickets
            $router->get('/tickets/{id}/attachments', fn (string $id) => $attachmentController->index((int) $id))
                   ->middleware(fn () => (new PermissionMiddleware('tickets.view'))->handle());
            $router->get('/attachments/limits', fn () => $attachmentController->limits());
            $router->get('/attachments/{id}/download', fn (string $id) => $attachmentController->download((int) $id))
                   ->middleware(fn () => (new PermissionMiddleware('tickets.view'))->handle());
            $router->delete('/attachments/{id}', fn (string $id) => $attachmentController->destroy((int) $id))
                   ->middleware(fn () => (new PermissionMiddleware('tickets.edit'))->handle());

            // Configurações globais (admin)
            $router->get('/settings/smtp',   fn () => $settingsController->getSmtp())
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->patch('/settings/smtp', fn () => $settingsController->patchSmtp())
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());

            // Modelos de e-mail (somente admin)
            $router->get('/settings/email-templates', fn () => $emailTemplateController->index())
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->put('/settings/email-templates/{key}', fn (string $key) => $emailTemplateController->update($key))
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->delete('/settings/email-templates/{key}', fn (string $key) => $emailTemplateController->reset($key))
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->post('/settings/email-templates/{key}/preview', fn (string $key) => $emailTemplateController->preview($key))
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->post('/settings/email-templates/{key}/test', fn (string $key) => $emailTemplateController->test($key))
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());

            // Configurações de anexos (somente admin)
            $router->get('/settings/attachments',   fn () => $attachmentSettingsController->get())
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->patch('/settings/attachments', fn () => $attachmentSettingsController->patch())
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());

            // Inteligência artificial (DeepSeek ou Google Gemini)
            $router->get('/settings/ai',        fn () => $aiController->getSettings())
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->patch('/settings/ai',      fn () => $aiController->saveSettings())
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->post('/settings/ai/test',  fn () => $aiController->test())
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->get('/settings/ai/gemini/models', fn () => $aiController->geminiModels())
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());
            $router->get('/ai/status',          fn () => $aiController->status());
            $router->post('/ai/triage',         fn () => $aiController->triage())
                   ->middleware(fn () => (new RoleMiddleware(['admin', 'agent', 'supervisor']))->handle());
            $router->post('/tickets/{id}/ai/tips',    fn (string $id) => $aiController->tips((int) $id))
                   ->middleware(fn () => (new RoleMiddleware(['admin', 'agent', 'supervisor']))->handle());
            $router->post('/tickets/{id}/ai/similar', fn (string $id) => $aiController->similar((int) $id))
                   ->middleware(fn () => (new RoleMiddleware(['admin', 'agent', 'supervisor']))->handle());
            $router->post('/tickets/{id}/ai/summary',  fn (string $id) => $aiController->summary((int) $id))
                   ->middleware(fn () => (new RoleMiddleware(['admin', 'agent', 'supervisor']))->handle());
            $router->post('/tickets/{id}/ai/assignee', fn (string $id) => $aiController->assignee((int) $id))
                   ->middleware(fn () => (new RoleMiddleware(['admin', 'agent', 'supervisor']))->handle());
            $router->get('/tickets/{id}/ai/classification',  fn (string $id) => $aiController->classification((int) $id))
                   ->middleware(fn () => (new RoleMiddleware(['admin', 'agent', 'supervisor']))->handle());
            $router->post('/tickets/{id}/ai/classification', fn (string $id) => $aiController->resolveClassification((int) $id))
                   ->middleware(fn () => (new RoleMiddleware(['admin', 'agent', 'supervisor']))->handle());
            $router->post('/tickets/{id}/ai/classify', fn (string $id) => $aiController->reclassify((int) $id))
                   ->middleware(fn () => (new RoleMiddleware(['admin', 'agent', 'supervisor']))->handle());
            $router->post('/ai/deflect', fn () => $aiController->deflect());
            $router->post('/ai/reports/organization', fn () => $aiController->orgReport())
                   ->middleware(fn () => (new RoleMiddleware(['admin', 'agent', 'supervisor', 'manager']))->handle());
            $router->post('/ai/reports/satisfaction', fn () => $aiController->satisfaction())
                   ->middleware(fn () => (new RoleMiddleware(['admin', 'agent', 'supervisor', 'manager']))->handle());

            // Notificações do usuário autenticado
            $router->get('/notifications',                   fn () => $slaAlertController->notifications());
            $router->patch('/notifications/{id}/read',       fn (string $id) => $slaAlertController->markRead((int)$id));
            $router->post('/notifications/read-all',         fn () => $slaAlertController->markAllRead());

            // SLA por empresa
            $router->get('/organizations/{id}/sla', fn (string $id) => $orgController->getSla((int) $id))
                   ->middleware(fn () => (new RoleMiddleware(['admin', 'manager']))->handle());
            $router->put('/organizations/{id}/sla', fn (string $id) => $orgController->saveSla((int) $id))
                   ->middleware(fn () => (new RoleMiddleware(['admin']))->handle());

            // Temporizador
            $router->get('/tickets/{id}/time',        fn (string $id) => $timeController->index((int) $id))
                   ->middleware(fn () => (new PermissionMiddleware('tickets.view'))->handle());
            $router->post('/tickets/{id}/time/start', fn (string $id) => $timeController->start((int) $id))
                   ->middleware(fn () => (new RoleMiddleware(['admin', 'agent', 'supervisor']))->handle());
            $router->post('/tickets/{id}/time/stop',  fn (string $id) => $timeController->stop((int) $id))
                   ->middleware(fn () => (new RoleMiddleware(['admin', 'agent', 'supervisor']))->handle());

        }, fn () => $auth->handle());

    });
};
