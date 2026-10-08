SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
    id                        BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    uuid                      CHAR(36)         NOT NULL,
    name                      VARCHAR(150)     NOT NULL,
    email                     VARCHAR(191)     NOT NULL,
    password                  VARCHAR(255)     NOT NULL,
    avatar                    VARCHAR(500)         NULL,
    phone                     VARCHAR(30)          NULL,
    timezone                  VARCHAR(50)      NOT NULL DEFAULT 'UTC',
    locale                    VARCHAR(10)      NOT NULL DEFAULT 'pt_BR',
    is_active                 TINYINT(1)       NOT NULL DEFAULT 1,
    two_factor_secret         VARCHAR(255)         NULL,
    two_factor_recovery_codes TEXT                 NULL,
    email_verified_at         TIMESTAMP            NULL,
    last_login_at             TIMESTAMP            NULL,
    last_login_ip             VARCHAR(45)          NULL,
    remember_token            VARCHAR(100)         NULL,
    created_at                TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at                TIMESTAMP            NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_uuid  (uuid),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_active      (is_active),
    KEY idx_users_deleted     (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS organizations (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid          CHAR(36)        NOT NULL,
    name          VARCHAR(200)    NOT NULL,
    domain        VARCHAR(255)        NULL,
    sla_policy_id BIGINT UNSIGNED     NULL,
    notes         TEXT                NULL,
    is_active     TINYINT(1)      NOT NULL DEFAULT 1,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at    TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_orgs_uuid (uuid),
    KEY idx_orgs_domain     (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_organizations (
    user_id         BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    is_primary      TINYINT(1)      NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, organization_id),
    CONSTRAINT fk_uo_user FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE,
    CONSTRAINT fk_uo_org  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(80)     NOT NULL,
    slug        VARCHAR(80)     NOT NULL,
    description VARCHAR(255)        NULL,
    is_system   TINYINT(1)      NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    module      VARCHAR(60)     NOT NULL,
    action      VARCHAR(60)     NOT NULL,
    slug        VARCHAR(120)    NOT NULL,
    description VARCHAR(255)        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_slug (slug),
    KEY idx_perm_module            (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id       BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id)       REFERENCES roles(id)       ON DELETE CASCADE,
    CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teams (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(100)    NOT NULL,
    description   VARCHAR(500)        NULL,
    lead_agent_id BIGINT UNSIGNED     NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_teams_lead FOREIGN KEY (lead_agent_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS team_members (
    team_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (team_id, user_id),
    CONSTRAINT fk_tm_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_tm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id   BIGINT UNSIGNED     NULL,
    name        VARCHAR(100)    NOT NULL,
    slug        VARCHAR(100)    NOT NULL,
    description VARCHAR(500)        NULL,
    icon        VARCHAR(100)        NULL,
    sort_order  SMALLINT        NOT NULL DEFAULT 0,
    is_active   TINYINT(1)      NOT NULL DEFAULT 1,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_slug (slug),
    CONSTRAINT fk_cat_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tags (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(60)     NOT NULL,
    slug       VARCHAR(60)     NOT NULL,
    color      CHAR(7)         NOT NULL DEFAULT '#6B7280',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tags_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sla_policies (
    id                  BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    name                VARCHAR(100)      NOT NULL,
    description         VARCHAR(500)          NULL,
    is_default          TINYINT(1)        NOT NULL DEFAULT 0,
    frt_low             SMALLINT UNSIGNED NOT NULL DEFAULT 480,
    frt_medium          SMALLINT UNSIGNED NOT NULL DEFAULT 240,
    frt_high            SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    frt_critical        SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    rt_low              INT UNSIGNED      NOT NULL DEFAULT 2880,
    rt_medium           INT UNSIGNED      NOT NULL DEFAULT 1440,
    rt_high             INT UNSIGNED      NOT NULL DEFAULT 480,
    rt_critical         INT UNSIGNED      NOT NULL DEFAULT 240,
    business_hours_only TINYINT(1)        NOT NULL DEFAULT 1,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tickets (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36)        NOT NULL,
    ticket_number       VARCHAR(20)     NOT NULL,
    subject             VARCHAR(500)    NOT NULL,
    description         MEDIUMTEXT      NOT NULL,
    requester_id        BIGINT UNSIGNED NOT NULL,
    assigned_agent_id   BIGINT UNSIGNED     NULL,
    team_id             BIGINT UNSIGNED     NULL,
    organization_id     BIGINT UNSIGNED     NULL,
    category_id         BIGINT UNSIGNED     NULL,
    sla_policy_id       BIGINT UNSIGNED     NULL,
    status              ENUM('open','pending','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
    priority            ENUM('low','medium','high','critical')                   NOT NULL DEFAULT 'medium',
    source              ENUM('web','email','api','whatsapp','phone')             NOT NULL DEFAULT 'web',
    type                ENUM('question','incident','problem','task')             NOT NULL DEFAULT 'question',
    first_response_at   TIMESTAMP NULL,
    resolved_at         TIMESTAMP NULL,
    closed_at           TIMESTAMP NULL,
    rating              TINYINT UNSIGNED NULL,
    rating_comment      TEXT             NULL,
    rated_at            TIMESTAMP        NULL,
    sla_frt_due_at      TIMESTAMP NULL,
    sla_rt_due_at       TIMESTAMP NULL,
    sla_frt_breached    TINYINT(1) NOT NULL DEFAULT 0,
    sla_rt_breached     TINYINT(1) NOT NULL DEFAULT 0,
    last_assigned_at    TIMESTAMP NULL,
    custom_fields       JSON NULL,
    metadata            JSON NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tickets_uuid   (uuid),
    UNIQUE KEY uq_tickets_number (ticket_number),
    KEY idx_tkt_requester        (requester_id),
    KEY idx_tkt_agent            (assigned_agent_id),
    KEY idx_tkt_status           (status),
    KEY idx_tkt_priority         (priority),
    KEY idx_tkt_sla_rt_due       (sla_rt_due_at),
    KEY idx_tkt_created          (created_at),
    KEY idx_tkt_deleted          (deleted_at),
    FULLTEXT KEY ft_tkt_subject  (subject),
    CONSTRAINT fk_tkt_requester FOREIGN KEY (requester_id)      REFERENCES users(id),
    CONSTRAINT fk_tkt_agent     FOREIGN KEY (assigned_agent_id) REFERENCES users(id)         ON DELETE SET NULL,
    CONSTRAINT fk_tkt_team      FOREIGN KEY (team_id)           REFERENCES teams(id)         ON DELETE SET NULL,
    CONSTRAINT fk_tkt_org       FOREIGN KEY (organization_id)   REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_tkt_cat       FOREIGN KEY (category_id)       REFERENCES categories(id)    ON DELETE SET NULL,
    CONSTRAINT fk_tkt_sla       FOREIGN KEY (sla_policy_id)     REFERENCES sla_policies(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_tags (
    ticket_id BIGINT UNSIGNED NOT NULL,
    tag_id    BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (ticket_id, tag_id),
    CONSTRAINT fk_tt_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_tt_tag    FOREIGN KEY (tag_id)    REFERENCES tags(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_replies (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid       CHAR(36)        NOT NULL,
    ticket_id  BIGINT UNSIGNED NOT NULL,
    author_id  BIGINT UNSIGNED NOT NULL,
    body       MEDIUMTEXT      NOT NULL,
    type       ENUM('reply','note','system') NOT NULL DEFAULT 'reply',
    is_private TINYINT(1)      NOT NULL DEFAULT 0,
    source     ENUM('web','email','api','whatsapp') NOT NULL DEFAULT 'web',
    metadata   JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_replies_uuid (uuid),
    KEY idx_rep_ticket         (ticket_id),
    KEY idx_rep_author         (author_id),
    CONSTRAINT fk_rep_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id)  ON DELETE CASCADE,
    CONSTRAINT fk_rep_author FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attachments (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid             CHAR(36)        NOT NULL,
    attachable_type  ENUM('ticket','reply','article') NOT NULL,
    attachable_id    BIGINT UNSIGNED NOT NULL,
    uploader_id      BIGINT UNSIGNED NOT NULL,
    original_name    VARCHAR(255)    NOT NULL,
    stored_name      VARCHAR(255)    NOT NULL,
    mime_type        VARCHAR(127)    NOT NULL,
    size_bytes       BIGINT UNSIGNED NOT NULL,
    storage_driver   ENUM('local','s3') NOT NULL DEFAULT 'local',
    storage_path     VARCHAR(1000)   NOT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attach_uuid (uuid),
    KEY idx_attach_poly       (attachable_type, attachable_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_history (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT UNSIGNED NOT NULL,
    actor_id  BIGINT UNSIGNED     NULL,
    event     ENUM(
        'created','status_changed','priority_changed',
        'assigned','unassigned','reassigned',
        'category_changed','tag_added','tag_removed',
        'reply_added','note_added','attachment_added',
        'sla_frt_breached','sla_rt_breached',
        'automation_triggered','merged','split'
    ) NOT NULL,
    old_value  JSON         NULL,
    new_value  JSON         NULL,
    notes      VARCHAR(500) NULL,
    ip_address VARCHAR(45)  NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_hist_ticket  (ticket_id),
    KEY idx_hist_event   (event),
    KEY idx_hist_created (created_at),
    CONSTRAINT fk_hist_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_hist_actor  FOREIGN KEY (actor_id)  REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automations (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(200)    NOT NULL,
    description   VARCHAR(1000)       NULL,
    trigger_event ENUM('ticket_created','ticket_updated','status_changed','reply_added','time_elapsed','sla_frt_breached','sla_rt_breached') NOT NULL,
    match_type    ENUM('all','any') NOT NULL DEFAULT 'all',
    is_active     TINYINT(1)  NOT NULL DEFAULT 1,
    run_count     INT UNSIGNED NOT NULL DEFAULT 0,
    sort_order    SMALLINT    NOT NULL DEFAULT 0,
    created_by    BIGINT UNSIGNED NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_auto_trigger (trigger_event, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automation_conditions (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    automation_id BIGINT UNSIGNED NOT NULL,
    field         VARCHAR(100)    NOT NULL,
    operator      ENUM('equals','not_equals','contains','not_contains','greater_than','less_than','is_set','is_not_set') NOT NULL,
    value         VARCHAR(500)        NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_ac_auto FOREIGN KEY (automation_id) REFERENCES automations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automation_actions (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    automation_id BIGINT UNSIGNED NOT NULL,
    action_type   ENUM('set_status','set_priority','assign_agent','assign_team','add_tag','remove_tag','send_email','fire_webhook','add_private_note','close_ticket') NOT NULL,
    parameters    JSON     NOT NULL,
    sort_order    SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    CONSTRAINT fk_aa_auto FOREIGN KEY (automation_id) REFERENCES automations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kb_categories (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id   BIGINT UNSIGNED     NULL,
    name        VARCHAR(150)    NOT NULL,
    slug        VARCHAR(150)    NOT NULL,
    description VARCHAR(500)        NULL,
    sort_order  SMALLINT        NOT NULL DEFAULT 0,
    is_public   TINYINT(1)      NOT NULL DEFAULT 1,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_kbc_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kb_articles (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)        NOT NULL,
    kb_category_id BIGINT UNSIGNED NOT NULL,
    author_id      BIGINT UNSIGNED NOT NULL,
    title          VARCHAR(300)    NOT NULL,
    slug           VARCHAR(300)    NOT NULL,
    summary        VARCHAR(1000)       NULL,
    body           LONGTEXT        NOT NULL,
    status         ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    is_featured    TINYINT(1)  NOT NULL DEFAULT 0,
    views_count    INT UNSIGNED NOT NULL DEFAULT 0,
    helpful_yes    INT UNSIGNED NOT NULL DEFAULT 0,
    helpful_no     INT UNSIGNED NOT NULL DEFAULT 0,
    published_at   TIMESTAMP NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at     TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_kba_uuid (uuid),
    UNIQUE KEY uq_kba_slug (slug),
    FULLTEXT KEY ft_kba_search (title, summary, body)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhooks (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name              VARCHAR(100)    NOT NULL,
    url               VARCHAR(2000)   NOT NULL,
    secret            VARCHAR(255)        NULL,
    events            JSON            NOT NULL,
    is_active         TINYINT(1)      NOT NULL DEFAULT 1,
    last_triggered_at TIMESTAMP           NULL,
    failure_count     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_by        BIGINT UNSIGNED     NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_tokens (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      BIGINT UNSIGNED NOT NULL,
    name         VARCHAR(100)    NOT NULL,
    token_hash   VARCHAR(255)    NOT NULL,
    abilities    JSON                NULL,
    last_used_at TIMESTAMP           NULL,
    expires_at   TIMESTAMP           NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_tokens_hash (token_hash),
    KEY idx_api_tokens_user       (user_id),
    CONSTRAINT fk_at_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid             CHAR(36)        NOT NULL,
    notifiable_type  VARCHAR(100)    NOT NULL,
    notifiable_id    BIGINT UNSIGNED NOT NULL,
    type             VARCHAR(200)    NOT NULL,
    channel          ENUM('mail','database','push','sms') NOT NULL DEFAULT 'database',
    data             JSON            NOT NULL,
    read_at          TIMESTAMP           NULL,
    sent_at          TIMESTAMP           NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notif_uuid (uuid),
    KEY idx_notif_notifiable (notifiable_type, notifiable_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    `key`      VARCHAR(100)                              NOT NULL,
    `value`    TEXT                                          NULL,
    `type`     ENUM('string','integer','boolean','json') NOT NULL DEFAULT 'string',
    `group`    VARCHAR(60)                               NOT NULL DEFAULT 'general',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`),
    KEY idx_settings_group (`group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
