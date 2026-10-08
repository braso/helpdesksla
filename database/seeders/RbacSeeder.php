<?php

declare(strict_types=1);

/**
 * Seeder de RBAC — popula roles, permissions e role_permissions.
 *
 * Executar uma única vez após rodar as migrations:
 *   php database/seeders/RbacSeeder.php
 *
 * Idempotente: usa INSERT IGNORE para não duplicar dados em re-execuções.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$envFile = dirname(__DIR__, 2) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

$config     = require dirname(__DIR__, 2) . '/config/database.php';
$connection = \App\Core\Database\Connection::fromConfig($config);
$pdo        = $connection->pdo();

// ─── 1. Roles ────────────────────────────────────────────────────────────────

$roles = [
    ['name' => 'Administrador', 'slug' => 'admin',  'is_system' => 1],
    ['name' => 'Agente',        'slug' => 'agent',  'is_system' => 1],
    ['name' => 'Cliente',       'slug' => 'client', 'is_system' => 1],
];

$roleInsert = $pdo->prepare(
    'INSERT IGNORE INTO roles (name, slug, is_system) VALUES (:name, :slug, :is_system)'
);
foreach ($roles as $role) {
    $roleInsert->execute($role);
}

echo "✓ Roles inseridas\n";

// ─── 2. Permissions ──────────────────────────────────────────────────────────

$permissions = [
    // Tickets
    ['module' => 'tickets', 'action' => 'view',   'slug' => 'tickets.view',   'description' => 'Visualizar tickets'],
    ['module' => 'tickets', 'action' => 'create',  'slug' => 'tickets.create', 'description' => 'Abrir novo ticket'],
    ['module' => 'tickets', 'action' => 'edit',    'slug' => 'tickets.edit',   'description' => 'Editar tickets'],
    ['module' => 'tickets', 'action' => 'delete',  'slug' => 'tickets.delete', 'description' => 'Deletar tickets'],
    ['module' => 'tickets', 'action' => 'assign',  'slug' => 'tickets.assign', 'description' => 'Atribuir tickets a agentes'],
    ['module' => 'tickets', 'action' => 'close',   'slug' => 'tickets.close',  'description' => 'Fechar tickets'],
    // Users
    ['module' => 'users',   'action' => 'view',   'slug' => 'users.view',     'description' => 'Visualizar usuários'],
    ['module' => 'users',   'action' => 'create', 'slug' => 'users.create',   'description' => 'Criar usuários'],
    ['module' => 'users',   'action' => 'edit',   'slug' => 'users.edit',     'description' => 'Editar usuários'],
    ['module' => 'users',   'action' => 'delete', 'slug' => 'users.delete',   'description' => 'Deletar usuários'],
    // Reports
    ['module' => 'reports', 'action' => 'view',   'slug' => 'reports.view',   'description' => 'Visualizar relatórios'],
    ['module' => 'reports', 'action' => 'export', 'slug' => 'reports.export', 'description' => 'Exportar relatórios'],
    // Knowledge Base
    ['module' => 'kb',      'action' => 'view',   'slug' => 'kb.view',        'description' => 'Visualizar base de conhecimento'],
    ['module' => 'kb',      'action' => 'create', 'slug' => 'kb.create',      'description' => 'Criar artigos'],
    ['module' => 'kb',      'action' => 'edit',   'slug' => 'kb.edit',        'description' => 'Editar artigos'],
    ['module' => 'kb',      'action' => 'delete', 'slug' => 'kb.delete',      'description' => 'Deletar artigos'],
    // Admin
    ['module' => 'admin',   'action' => 'settings',    'slug' => 'admin.settings',    'description' => 'Configurações do sistema'],
    ['module' => 'admin',   'action' => 'automations',  'slug' => 'admin.automations', 'description' => 'Gerenciar automações'],
    ['module' => 'admin',   'action' => 'sla',          'slug' => 'admin.sla',         'description' => 'Gerenciar políticas de SLA'],
    ['module' => 'admin',   'action' => 'teams',        'slug' => 'admin.teams',       'description' => 'Gerenciar equipes'],
    ['module' => 'admin',   'action' => 'roles',        'slug' => 'admin.roles',       'description' => 'Gerenciar papéis e permissões'],
];

$permInsert = $pdo->prepare(
    'INSERT IGNORE INTO permissions (module, action, slug, description)
     VALUES (:module, :action, :slug, :description)'
);
foreach ($permissions as $perm) {
    $permInsert->execute($perm);
}

echo "✓ Permissions inseridas\n";

// ─── 3. Role <-> Permission mapping ──────────────────────────────────────────

// Busca IDs dinâmicos para evitar hardcode de IDs que variam por ambiente.
$roleIds = [];
foreach ($pdo->query('SELECT id, slug FROM roles') as $row) {
    $roleIds[$row['slug']] = (int) $row['id'];
}

$permIds = [];
foreach ($pdo->query('SELECT id, slug FROM permissions') as $row) {
    $permIds[$row['slug']] = (int) $row['id'];
}

$rolePermissions = [

    // Admin — acesso total
    'admin' => array_keys($permIds),

    // Agent — opera tickets e KB, sem acesso a admin e gestão de usuários
    'agent' => [
        'tickets.view', 'tickets.create', 'tickets.edit',
        'tickets.assign', 'tickets.close',
        'kb.view', 'kb.create', 'kb.edit',
        'reports.view',
        'users.view',
    ],

    // Client — abre e acompanha próprios tickets, lê KB
    'client' => [
        'tickets.view',
        'tickets.create',
        'kb.view',
    ],
];

$rpInsert = $pdo->prepare(
    'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (:role_id, :perm_id)'
);

foreach ($rolePermissions as $roleSlug => $permSlugs) {
    if (!isset($roleIds[$roleSlug])) {
        echo "⚠ Role '{$roleSlug}' não encontrada, pulando.\n";
        continue;
    }

    foreach ($permSlugs as $permSlug) {
        if (!isset($permIds[$permSlug])) {
            echo "⚠ Permission '{$permSlug}' não encontrada, pulando.\n";
            continue;
        }

        $rpInsert->execute([
            ':role_id' => $roleIds[$roleSlug],
            ':perm_id' => $permIds[$permSlug],
        ]);
    }
}

echo "✓ Role<->Permission mapeado\n";
echo "\n✅ RbacSeeder concluído com sucesso.\n";
