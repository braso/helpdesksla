-- Migration 006: recuperação de senha por token de uso único.
-- Guarda apenas o SHA-256 do token; o token em si só existe no e-mail enviado ao usuário.
CREATE TABLE IF NOT EXISTS password_resets (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED     NULL,
    email       VARCHAR(191)    NOT NULL,
    token_hash  CHAR(64)            NULL,
    ip_address  VARCHAR(45)         NULL,
    user_agent  VARCHAR(255)        NULL,
    expires_at  TIMESTAMP           NULL,
    used_at     TIMESTAMP           NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pr_token (token_hash),
    KEY idx_pr_email_created (email, created_at),
    KEY idx_pr_ip_created (ip_address, created_at),
    CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
