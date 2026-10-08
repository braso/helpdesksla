<?php

declare(strict_types=1);

use App\Core\Router;

// Errors go to the log, never to the HTTP response body
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// ─── Headers globais ─────────────────────────────────────────────────────────

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

// ─── Aplicação (configuração, serviços, controllers) ─────────────────────────

require dirname(__DIR__) . '/bootstrap/app.php';

// ─── Roteamento ───────────────────────────────────────────────────────────────

$router = new Router();

$registerRoutes = require dirname(__DIR__) . '/routes/api.php';
$registerRoutes($router, $auth, $loginController, $logoutController, $meController,
    $registerController, $orgController, $ticketController, $replyController, $timeController,
    $userController, $reportController, $emailController, $slaAlertController, $settingsController,
    $attachmentController, $attachmentSettingsController, $aiController, $passwordResetController,
    $slaPolicyController, $emailTemplateController);

$router->dispatch(
    method: $_SERVER['REQUEST_METHOD'],
    uri:    strtok($_SERVER['REQUEST_URI'], '?'),
);
