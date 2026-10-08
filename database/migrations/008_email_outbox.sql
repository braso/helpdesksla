-- Migration 008: fila de saída de e-mails.
-- Todo e-mail do sistema (chamados, respostas, recuperação de senha, alertas de SLA)
-- entra aqui como 'pending' e é entregue pelo job app/Jobs/SendQueuedEmailsJob.php
-- (cron a cada minuto), com novas tentativas automáticas em caso de falha.
CREATE TABLE IF NOT EXISTS email_outbox (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      BIGINT UNSIGNED     NULL,
    to_email     VARCHAR(191)    NOT NULL,
    to_name      VARCHAR(191)    NOT NULL DEFAULT '',
    type         VARCHAR(64)     NOT NULL,
    subject      VARCHAR(255)    NOT NULL,
    html_body    MEDIUMTEXT      NOT NULL,
    context      JSON                NULL,
    status       ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error   TEXT                NULL,
    available_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at    TIMESTAMP           NULL,
    sent_at      TIMESTAMP           NULL,
    created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_eo_status_available (status, available_at),
    KEY idx_eo_user (user_id),
    CONSTRAINT fk_eo_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
