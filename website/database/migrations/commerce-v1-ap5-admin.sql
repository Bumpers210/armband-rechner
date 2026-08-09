-- AP5 forward migration: separate shop-admin account, sessions, audit and Brevo delivery state.
-- Passwords, session tokens and CSRF tokens are never stored in plaintext.

CREATE TABLE IF NOT EXISTS admin_users (
    admin_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    username VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    password_changed_at DATETIME(6) NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (admin_id),
    UNIQUE KEY uq_admin_username (username),
    CONSTRAINT chk_admin_enabled CHECK (enabled IN (0, 1))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_sessions (
    session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    admin_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    csrf_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    revoked_at DATETIME(6) NULL,
    PRIMARY KEY (session_hash),
    KEY idx_admin_session_admin (admin_id, expires_at),
    CONSTRAINT fk_admin_session_user FOREIGN KEY (admin_id)
        REFERENCES admin_users (admin_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_login_attempts (
    attempt_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_started_at DATETIME(6) NOT NULL,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME(6) NULL,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (attempt_key_hash)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_audit_events (
    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    action VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    correlation_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    details JSON NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (audit_id),
    KEY idx_admin_audit_subject (subject_type, subject_id, created_at),
    KEY idx_admin_audit_admin (admin_id, created_at),
    CONSTRAINT fk_admin_audit_user FOREIGN KEY (admin_id)
        REFERENCES admin_users (admin_id)
) ENGINE=InnoDB;

ALTER TABLE mail_outbox
    DROP CHECK chk_mail_status,
    ADD CONSTRAINT chk_mail_status CHECK (status IN
        ('queued', 'processing', 'sent', 'delivery_unknown', 'manual_review', 'failed'));
