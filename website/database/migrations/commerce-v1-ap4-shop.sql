-- AP4 forward migration: anonymous shop sessions and one-time checkout grants.
-- The raw token values never enter the database; only SHA-256 digests are stored.

CREATE TABLE IF NOT EXISTS shop_sessions (
    session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    csrf_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    csrf_expires_at DATETIME(6) NOT NULL,
    live_context_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    live_context_expires_at DATETIME(6) NOT NULL,
    session_expires_at DATETIME(6) NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (session_hash),
    KEY idx_shop_session_expiry (session_expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS checkout_tokens (
    checkout_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    product_version BIGINT UNSIGNED NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    rate_bucket_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ip_bucket_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (checkout_id),
    UNIQUE KEY uq_checkout_token_hash (token_hash),
    KEY idx_checkout_token_expiry (expires_at),
    CONSTRAINT fk_checkout_token_checkout FOREIGN KEY (checkout_id)
        REFERENCES checkout_sagas (checkout_id),
    CONSTRAINT fk_checkout_token_session FOREIGN KEY (session_hash)
        REFERENCES shop_sessions (session_hash)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shop_rate_limits (
    bucket_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_started_at DATETIME(6) NOT NULL,
    counted_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    successful_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (bucket_hash)
) ENGINE=InnoDB;
