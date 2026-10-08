-- Migration 009: modelos de e-mail editáveis (Configurações → Modelos de e-mail).
-- Guarda só as personalizações; o texto padrão de cada modelo está em
-- app/Services/Notification/EmailTemplateService.php. Apagar a linha restaura o padrão.
CREATE TABLE IF NOT EXISTS email_templates (
    template_key VARCHAR(64)     NOT NULL,
    subject      VARCHAR(255)    NOT NULL,
    body_html    MEDIUMTEXT      NOT NULL,
    cta_label    VARCHAR(60)     NOT NULL DEFAULT '',
    footer       VARCHAR(500)    NOT NULL DEFAULT '',
    updated_by   BIGINT UNSIGNED     NULL,
    created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (template_key),
    CONSTRAINT fk_et_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
