<?php

declare(strict_types=1);

/**
 * Monta a aplicação (configuração, repositórios, serviços, listeners e controllers).
 * Usado pela API (public/index.php) e pelos jobs de linha de comando (app/Jobs/*),
 * para que um chamado criado por e-mail dispare as mesmas notificações e automações.
 */

// ─── Bootstrap ───────────────────────────────────────────────────────────────

require_once dirname(__DIR__) . '/vendor/autoload.php';

$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

// ─── Imports ─────────────────────────────────────────────────────────────────

use App\Core\Database\Connection;
use App\Core\EventDispatcher;
use App\Events\ReplyAddedEvent;
use App\Events\TicketAssignedEvent;
use App\Events\TicketCreatedEvent;
use App\Events\TicketStatusChangedEvent;
use App\Repositories\ApiTokenRepository;
use App\Repositories\AutomationRepository;
use App\Repositories\RoleRepository;
use App\Repositories\SlaPolicyRepository;
use App\Repositories\TicketHistoryRepository;
use App\Repositories\TicketReplyRepository;
use App\Repositories\TicketRepository;
use App\Repositories\UserRepository;
use App\Services\Auth\AuthService;
use App\Services\Auth\JwtService;
use App\Services\Auth\RegisterService;
use App\Services\Automation\ActionExecutor;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\ConditionEvaluator;
use App\Services\Sla\BusinessHoursCalculator;
use App\Services\Sla\SlaHoursSettings;
use App\Services\Sla\SlaPolicyService;
use App\Http\Controllers\Api\V1\Sla\SlaPolicyController;
use App\Services\Sla\SlaService;
use App\Services\Ticket\TicketReplyService;
use App\Services\Ticket\TicketService;
use App\Services\Ticket\TimeTrackingService;
use App\Http\Middleware\AuthMiddleware;
use App\Listeners\AuditTicketHistory;
use App\Listeners\TicketEmailNotifier;
use App\Listeners\AiTicketClassifier;
use App\Listeners\RunAutomations;
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
use App\Http\Controllers\Api\V1\Attachments\AttachmentController;
use App\Http\Controllers\Api\V1\Settings\AttachmentSettingsController;
use App\Http\Controllers\Api\V1\Settings\SettingsController;
use App\Repositories\AttachmentRepository;
use App\Services\Attachment\AttachmentService;
use App\Services\Notification\NotificationService;
use App\Services\Notification\EmailTemplateService;
use App\Http\Controllers\Api\V1\Settings\EmailTemplateController;
use App\Repositories\EmailAccountRepository;
use App\Repositories\OrganizationRepository;
use App\Repositories\TimeEntryRepository;
use App\Services\Email\EmailFetcherService;
use App\Http\Controllers\Api\V1\Ai\AiController;
use App\Services\Ai\AiAssistantService;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiProviderClient;
use App\Services\Ai\DeepSeekClient;
use App\Services\Ai\GeminiClient;
use App\Support\Crypto;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Services\Auth\PasswordResetService;

// ─── Wiring ──────────────────────────────────────────────────────────────────

$dbConfig   = require dirname(__DIR__) . '/config/database.php';
$authConfig = require dirname(__DIR__) . '/config/auth.php';
$slaConfig  = require dirname(__DIR__) . '/config/sla.php';

$connection = Connection::fromConfig($dbConfig);

// Horário comercial do SLA: o que foi salvo na tela "Políticas de SLA" prevalece sobre o .env.
$slaHours  = new SlaHoursSettings($connection, $slaConfig);
$slaConfig = $slaHours->effective();

// Repositories
$userRepository        = new UserRepository($connection);
$roleRepository        = new RoleRepository($connection);
$apiTokenRepository    = new ApiTokenRepository($connection);
$ticketRepository      = new TicketRepository($connection);
$replyRepository       = new TicketReplyRepository($connection);
$slaPolicyRepository   = new SlaPolicyRepository($connection);
$automationRepository  = new AutomationRepository($connection);
$historyRepository     = new TicketHistoryRepository($connection);
$orgRepository         = new OrganizationRepository($connection);
$timeEntryRepository   = new TimeEntryRepository($connection);
$emailAccountRepo      = new EmailAccountRepository($connection);
$attachmentRepository  = new AttachmentRepository($connection);

// Event Dispatcher
$events = new EventDispatcher();

// Listeners — os que não dependem de serviços tardios
$auditListener      = new AuditTicketHistory($historyRepository);
$automationListener = new RunAutomations(
    new AutomationEngine(
        $automationRepository,
        new ConditionEvaluator(),
        new ActionExecutor($ticketRepository, $replyRepository, $historyRepository),
    )
);

$events->listen(TicketCreatedEvent::class,       fn($e) => $auditListener->onTicketCreated($e));
$events->listen(TicketCreatedEvent::class,       fn($e) => $automationListener->onTicketCreated($e));
$events->listen(ReplyAddedEvent::class,          fn($e) => $auditListener->onReplyAdded($e));
$events->listen(ReplyAddedEvent::class,          fn($e) => $automationListener->onReplyAdded($e));
$events->listen(TicketStatusChangedEvent::class, fn($e) => $auditListener->onStatusChanged($e));

// Services
$jwtService      = new JwtService($authConfig['jwt_secret'], $authConfig['jwt_ttl']);
$authService     = new AuthService($userRepository, $apiTokenRepository, $jwtService);
$registerService = new RegisterService($connection, $userRepository, $orgRepository);
$slaService      = new SlaService($slaPolicyRepository, new BusinessHoursCalculator($slaConfig));
$ticketService   = new TicketService($ticketRepository, $slaService, $events);
$replyService    = new TicketReplyService($ticketRepository, $replyRepository, $events);
$timeService       = new TimeTrackingService($ticketRepository, $timeEntryRepository);
$attachmentService = new AttachmentService($attachmentRepository, $connection);

// Middleware
$auth = new AuthMiddleware($authService, $userRepository, $roleRepository, $orgRepository);

// Controllers
$loginController   = new LoginController($authService);
$logoutController  = new LogoutController($authService);
$meController      = new MeController();
$registerController = new RegisterController($registerService);
$orgController     = new OrganizationController($orgRepository, $connection);
$ticketController  = new TicketController($ticketService, $attachmentService);
$replyController   = new TicketReplyController($replyService, $ticketRepository, $replyRepository, $ticketService, $attachmentService);
$timeController    = new TimeTrackingController($timeService);
$userController      = new UserController($userRepository, $registerService);
$notificationService = new NotificationService($connection);
$appUrl              = $_ENV['APP_URL'] ?? getenv('APP_URL') ?: 'http://localhost:8080';
$emailTemplates      = new EmailTemplateService($connection, $appUrl);
$emailTemplateController = new EmailTemplateController($emailTemplates, $notificationService);
$aiSettings                  = new AiSettings($connection, new Crypto($authConfig['jwt_secret']));
$geminiClient                = new GeminiClient($aiSettings);
$aiClient                    = new AiProviderClient($aiSettings, ['deepseek' => new DeepSeekClient($aiSettings), 'gemini' => $geminiClient]);
$aiAssistant                 = new AiAssistantService($connection, $aiClient, $aiSettings);
$aiController                = new AiController($aiSettings, $aiAssistant, $geminiClient);
$aiClassifier                = new AiTicketClassifier($aiAssistant);
$passwordResetController     = new PasswordResetController(new PasswordResetService(
    $connection, $userRepository, $notificationService, $emailTemplates,
));
$ticketMailer        = new TicketEmailNotifier(
    $userRepository,
    $notificationService,
    $emailTemplates,
    $_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo',
);
// A classificação por IA roda antes dos e-mails (ambos após a resposta HTTP).
$events->listen(TicketCreatedEvent::class,       fn($e) => $aiClassifier->onTicketCreated($e));
$events->listen(TicketCreatedEvent::class,       fn($e) => $ticketMailer->onTicketCreated($e));
$events->listen(ReplyAddedEvent::class,          fn($e) => $ticketMailer->onReplyAdded($e));
$events->listen(TicketAssignedEvent::class,      fn($e) => $ticketMailer->onAssigned($e));
$events->listen(TicketStatusChangedEvent::class, fn($e) => $ticketMailer->onStatusChanged($e));
$reportController    = new ReportController($connection);
$emailFetcher        = new EmailFetcherService($emailAccountRepo, $userRepository, $orgRepository, $ticketService, $connection, $authConfig['jwt_secret']);
$emailController     = new EmailAccountController($emailAccountRepo, $orgRepository, $emailFetcher);
$slaAlertController          = new SlaAlertController($connection, $notificationService);
$settingsController          = new SettingsController($connection);
$slaPolicyController         = new SlaPolicyController(new SlaPolicyService($connection), $slaHours);
$attachmentController        = new AttachmentController($attachmentService, $ticketService);
$attachmentSettingsController = new AttachmentSettingsController($attachmentService);
