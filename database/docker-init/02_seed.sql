-- Helpdesk BRASO — dados iniciais (papéis, permissões, SLA padrão, configurações e admin).
-- Executado automaticamente pelo MySQL na primeira subida (volume vazio).
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ── Papéis ──────────────────────────────────────────────────────────────────
INSERT IGNORE INTO roles (name, slug, description, is_system) VALUES
  ('Administrador', 'admin',      'Acesso total',                                   1),
  ('Agente',        'agent',      'Atende chamados',                                1),
  ('Supervisor',    'supervisor', 'Atende chamados e acompanha SLA e relatórios',   1),
  ('Gerente',       'manager',    'Abre chamados e vê relatórios da própria empresa', 1),
  ('Cliente',       'client',     'Abre e acompanha chamados da própria empresa',   1);

-- ── Permissões ──────────────────────────────────────────────────────────────
INSERT IGNORE INTO permissions (module, action, slug, description) VALUES
  ('tickets','view','tickets.view','Visualizar chamados'),
  ('tickets','create','tickets.create','Abrir chamado'),
  ('tickets','edit','tickets.edit','Editar chamados'),
  ('tickets','delete','tickets.delete','Excluir chamados'),
  ('tickets','assign','tickets.assign','Atribuir chamados'),
  ('tickets','close','tickets.close','Fechar chamados'),
  ('users','view','users.view','Visualizar usuários'),
  ('users','create','users.create','Criar usuários'),
  ('users','edit','users.edit','Editar usuários'),
  ('users','delete','users.delete','Excluir usuários'),
  ('reports','view','reports.view','Visualizar relatórios'),
  ('reports','export','reports.export','Exportar relatórios'),
  ('report','export','report:export','Exportar relatórios em CSV, PDF e TXT'),
  ('kb','view','kb.view','Visualizar base de conhecimento'),
  ('kb','create','kb.create','Criar artigos'),
  ('kb','edit','kb.edit','Editar artigos'),
  ('kb','delete','kb.delete','Excluir artigos'),
  ('admin','settings','admin.settings','Configurações do sistema'),
  ('admin','automations','admin.automations','Gerenciar automações'),
  ('admin','sla','admin.sla','Gerenciar políticas de SLA'),
  ('admin','teams','admin.teams','Gerenciar equipes'),
  ('admin','roles','admin.roles','Gerenciar papéis e permissões');

-- ── Papel ↔ permissão ───────────────────────────────────────────────────────
INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.slug = 'admin';
INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN
    ('tickets.view','tickets.create','tickets.edit','tickets.assign','tickets.close','kb.view','kb.create','kb.edit','reports.view','users.view')
  WHERE r.slug = 'agent';
INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN
    ('tickets.view','tickets.create','tickets.edit','tickets.assign','tickets.close','kb.view','kb.create','kb.edit','reports.view','reports.export','report:export','users.view')
  WHERE r.slug = 'supervisor';
INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN ('tickets.view','tickets.create','reports.view')
  WHERE r.slug = 'manager';
INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN ('tickets.view','tickets.create','kb.view')
  WHERE r.slug = 'client';

-- ── SLA padrão (usado por empresas sem contrato próprio) ────────────────────
-- Média de mercado para suporte de TI 8x5 (1 dia útil = 540 min). Ver migration 007.
INSERT INTO sla_policies (name, description, is_default, frt_low, frt_medium, frt_high, frt_critical, rt_low, rt_medium, rt_high, rt_critical, business_hours_only)
SELECT 'SLA padrão (mercado 8x5)', 'Média de mercado para suporte de TI em horário comercial. Aplicado a empresas sem contrato próprio.', 1, 540, 240, 60, 30, 2700, 1080, 540, 240, 1
WHERE NOT EXISTS (SELECT 1 FROM sla_policies WHERE is_default = 1);

-- ── Perfis de alerta de SLA ─────────────────────────────────────────────────
INSERT INTO sla_alert_profiles (name, role_id, alert_type, warning_threshold_minutes, notify_email, notify_internal, escalation_delay_minutes, escalation_role_id)
SELECT 'Aviso 1h antes — Supervisor', r.id, 'warning', 60, 1, 1, NULL, NULL FROM roles r
 WHERE r.slug = 'supervisor' AND NOT EXISTS (SELECT 1 FROM sla_alert_profiles);
INSERT INTO sla_alert_profiles (name, role_id, alert_type, warning_threshold_minutes, notify_email, notify_internal, escalation_delay_minutes, escalation_role_id)
SELECT 'Prazo vencido — Administrador', r.id, 'breached', NULL, 1, 1, 240, r.id FROM roles r
 WHERE r.slug = 'admin' AND (SELECT COUNT(*) FROM sla_alert_profiles) < 2;

-- ── Configurações ───────────────────────────────────────────────────────────
INSERT INTO system_settings (`key`, `value`) VALUES
  ('attachment_max_size_mb',   '10'),
  ('attachment_allowed_types', 'pdf,doc,docx,xls,xlsx,png,jpg,jpeg,gif,zip,txt')
ON DUPLICATE KEY UPDATE `key` = `key`;

-- ── Administrador inicial ───────────────────────────────────────────────────
-- E-mail: admin@helpdesk.com · Senha: password  (TROQUE após o primeiro acesso)
INSERT INTO users (uuid, name, first_name, last_name, email, password, is_active)
SELECT '00000000-0000-4000-8000-000000000001', 'Administrador', 'Administrador', '', 'admin@helpdesk.com',
       '$2y$10$Dvf9i4T7GfHNCxU61e1Dbe8YPO3EWZI2EaOEL9Br1hj3Ezuj9QVxK', 1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'admin@helpdesk.com');
INSERT IGNORE INTO user_roles (user_id, role_id)
  SELECT u.id, r.id FROM users u JOIN roles r ON r.slug = 'admin' WHERE u.email = 'admin@helpdesk.com';
