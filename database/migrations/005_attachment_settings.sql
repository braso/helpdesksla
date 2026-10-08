-- Migration 005: configurações padrão para anexos
-- Insere as chaves de configuração de anexos em system_settings com valores padrão.
-- Execute após 003_system_settings.sql

INSERT INTO system_settings (`key`, `value`) VALUES
    ('attachment_max_size_mb',    '10'),
    ('attachment_allowed_types',  'pdf,doc,docx,xls,xlsx,png,jpg,jpeg,gif,zip,txt')
ON DUPLICATE KEY UPDATE updated_at = updated_at;
