-- ============================================================
-- Migration 002: SLA Alerts, Export Audit, Notifications
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── Perfis de alerta de SLA ──────────────────────────────────
CREATE TABLE IF NOT EXISTS sla_alert_profiles (
    id                        BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    name                      VARCHAR(150)     NOT NULL,
    role_id                   BIGINT UNSIGNED  NOT NULL,
    alert_type                ENUM('warning','breached','critical') NOT NULL DEFAULT 'breached',
    warning_threshold_minutes INT UNSIGNED         NULL COMMENT 'Minutos antes do vencimento para alertar',
    notify_email              TINYINT(1)       NOT NULL DEFAULT 1,
    notify_internal           TINYINT(1)       NOT NULL DEFAULT 1,
    escalation_delay_minutes  INT UNSIGNED         NULL COMMENT 'Minutos após violação para escalonar',
    escalation_role_id        BIGINT UNSIGNED      NULL,
    is_active                 TINYINT(1)       NOT NULL DEFAULT 1,
    created_at                TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sap_role   (role_id),
    KEY idx_sap_active (is_active),
    CONSTRAINT fk_sap_role     FOREIGN KEY (role_id)            REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_sap_esc_role FOREIGN KEY (escalation_role_id) REFERENCES roles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Log de notificações de SLA ───────────────────────────────
CREATE TABLE IF NOT EXISTS sla_notification_logs (
    id               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    ticket_id        BIGINT UNSIGNED  NOT NULL,
    alert_profile_id BIGINT UNSIGNED  NOT NULL,
    alert_type       ENUM('warning','breached','critical') NOT NULL,
    notified_role_id BIGINT UNSIGNED  NOT NULL,
    notified_user_id BIGINT UNSIGNED  NOT NULL,
    channel          ENUM('mail','database') NOT NULL,
    status           ENUM('sent','failed','pending') NOT NULL DEFAULT 'pending',
    error_message    TEXT                 NULL,
    sent_at          TIMESTAMP            NULL,
    created_at       TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_snl_ticket  (ticket_id, alert_type),
    KEY idx_snl_user    (notified_user_id),
    KEY idx_snl_created (created_at),
    CONSTRAINT fk_snl_ticket  FOREIGN KEY (ticket_id)        REFERENCES tickets(id)            ON DELETE CASCADE,
    CONSTRAINT fk_snl_profile FOREIGN KEY (alert_profile_id) REFERENCES sla_alert_profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_snl_user    FOREIGN KEY (notified_user_id) REFERENCES users(id)              ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Auditoria de exportações de relatório ────────────────────
CREATE TABLE IF NOT EXISTS report_export_logs (
    id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED  NOT NULL,
    report_type     ENUM('overview','agents','organizations','sla') NOT NULL,
    format          ENUM('csv','pdf','txt') NOT NULL,
    filters         JSON                 NULL,
    file_size_bytes INT UNSIGNED         NULL,
    duration_ms     INT UNSIGNED         NULL,
    status          ENUM('success','failed') NOT NULL DEFAULT 'success',
    created_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rel_user    (user_id),
    KEY idx_rel_type    (report_type, format),
    KEY idx_rel_created (created_at),
    CONSTRAINT fk_rel_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Permissão de exportação ──────────────────────────────────
INSERT IGNORE INTO permissions (module, action, slug, description)
VALUES ('report', 'export', 'report:export', 'Exportar relatórios em CSV, PDF e TXT');

-- Conceder a admin e supervisor
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug = 'report:export'
WHERE r.slug IN ('admin', 'supervisor');

-- ── Perfis de alerta padrão (se roles existirem) ────────────
INSERT IGNORE INTO sla_alert_profiles (name, role_id, alert_type, warning_threshold_minutes, notify_email, notify_internal, escalation_delay_minutes, escalation_role_id)
SELECT
    'Aviso 1h antes — Supervisor' AS name,
    r.id                          AS role_id,
    'warning'                     AS alert_type,
    60                            AS warning_threshold_minutes,
    1                             AS notify_email,
    1                             AS notify_internal,
    NULL                          AS escalation_delay_minutes,
    NULL                          AS escalation_role_id
FROM roles r WHERE r.slug = 'supervisor' LIMIT 1;

INSERT IGNORE INTO sla_alert_profiles (name, role_id, alert_type, warning_threshold_minutes, notify_email, notify_internal, escalation_delay_minutes, escalation_role_id)
SELECT
    'SLA Violado — Admin'         AS name,
    r.id                          AS role_id,
    'breached'                    AS alert_type,
    NULL                          AS warning_threshold_minutes,
    1                             AS notify_email,
    1                             AS notify_internal,
    240                           AS escalation_delay_minutes,
    r.id                          AS escalation_role_id
FROM roles r WHERE r.slug = 'admin' LIMIT 1;

SET FOREIGN_KEY_CHECKS = 1;
