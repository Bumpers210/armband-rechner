-- Carmaja V1 Commerce schema, MySQL 8 / InnoDB.
-- Forward-only migrations must record their identifier in schema_migrations.
-- No table in this file is a replacement for the authoritative product source.

CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    applied_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (migration_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS legal_bundles (
    legal_bundle_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    terms_version VARCHAR(100) NOT NULL,
    privacy_version VARCHAR(100) NOT NULL,
    withdrawal_version VARCHAR(100) NOT NULL,
    shipping_version VARCHAR(100) NOT NULL,
    merchant_version VARCHAR(100) NOT NULL,
    bundle_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(20) NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (legal_bundle_id),
    CONSTRAINT chk_legal_bundle_status
        CHECK (status IN ('draft', 'approved', 'retired'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS commerce_products (
    product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    product_version BIGINT UNSIGNED NOT NULL,
    source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    materials JSON NOT NULL,
    metal_elements JSON NOT NULL,
    bracelet_size VARCHAR(100) NOT NULL,
    care_instructions TEXT NOT NULL,
    images JSON NOT NULL,
    price_minor INT UNSIGNED NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sales_enabled TINYINT(1) NOT NULL,
    sales_model ENUM('unique', 'collection') NOT NULL DEFAULT 'unique',
    synchronized_at TIMESTAMP(6) NOT NULL,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (product_id),
    CONSTRAINT chk_product_price CHECK (price_minor > 0),
    CONSTRAINT chk_product_currency CHECK (currency = 'eur'),
    CONSTRAINT chk_product_sales_enabled CHECK (sales_enabled IN (0, 1)),
    UNIQUE KEY uq_product_version_hash (product_id, product_version, source_hash)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS commerce_inventory (
    product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    on_hand TINYINT UNSIGNED NOT NULL,
    inventory_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (product_id),
    CONSTRAINT fk_inventory_product FOREIGN KEY (product_id)
        REFERENCES commerce_products (product_id),
    CONSTRAINT chk_inventory_binary CHECK (on_hand IN (0, 1))
) ENGINE=InnoDB;

-- Legacy inventory remains available for historical unique-product records.
-- Collection products never receive or read an inventory row.

CREATE TABLE IF NOT EXISTS product_projection_operations (
    operation_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action ENUM('publish', 'archive', 'restore') NOT NULL,
    result JSON NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (operation_id),
    KEY idx_projection_product (product_id, created_at),
    CONSTRAINT fk_projection_product FOREIGN KEY (product_id)
        REFERENCES commerce_products (product_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_adjustments (
    adjustment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target_on_hand TINYINT UNSIGNED NOT NULL,
    previous_on_hand TINYINT UNSIGNED NOT NULL,
    inventory_version BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    correlation_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (adjustment_id),
    UNIQUE KEY uq_inventory_adjustment_idempotency (idempotency_key),
    KEY idx_inventory_adjustment_product (product_id, created_at),
    CONSTRAINT fk_adjustment_inventory FOREIGN KEY (product_id)
        REFERENCES commerce_inventory (product_id),
    CONSTRAINT chk_adjustment_target CHECK (target_on_hand IN (0, 1)),
    CONSTRAINT chk_adjustment_previous CHECK (previous_on_hand IN (0, 1)),
    CONSTRAINT chk_adjustment_reason CHECK (reason IN
        ('activate_new_unique', 'shop_sale', 'mark_unsellable', 'release_return'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS checkout_sagas (
    checkout_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    product_version BIGINT UNSIGNED NOT NULL,
    source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    price_minor INT UNSIGNED NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    shipping_snapshot JSON NOT NULL,
    legal_bundle_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sales_model ENUM('unique', 'collection') NOT NULL DEFAULT 'unique',
    state VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    failure_code VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
    expires_at DATETIME(6) NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (checkout_id),
    UNIQUE KEY uq_checkout_idempotency (idempotency_key),
    KEY idx_checkout_due (state, expires_at),
    CONSTRAINT fk_checkout_product FOREIGN KEY (product_id)
        REFERENCES commerce_products (product_id),
    CONSTRAINT fk_checkout_legal FOREIGN KEY (legal_bundle_id)
        REFERENCES legal_bundles (legal_bundle_id),
    CONSTRAINT chk_checkout_amount CHECK (price_minor > 0 AND currency = 'eur'),
    CONSTRAINT chk_checkout_state CHECK (state IN
        ('creating', 'created', 'stripe_open', 'payment_pending', 'completed',
         'failed', 'expired', 'manual_review', 'canceled'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservations (
    reservation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    checkout_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    quantity TINYINT UNSIGNED NOT NULL DEFAULT 1,
    state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    blocks_stock TINYINT(1) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    stripe_session_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    converted_at DATETIME(6) NULL,
    released_at DATETIME(6) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (reservation_id),
    UNIQUE KEY uq_reservation_checkout (checkout_id),
    KEY idx_reservation_product_state (product_id, state, expires_at),
    CONSTRAINT fk_reservation_checkout FOREIGN KEY (checkout_id)
        REFERENCES checkout_sagas (checkout_id),
    CONSTRAINT fk_reservation_product FOREIGN KEY (product_id)
        REFERENCES commerce_products (product_id),
    CONSTRAINT chk_reservation_quantity CHECK (quantity = 1),
    CONSTRAINT chk_reservation_state CHECK (state IN
        ('creating', 'active', 'expired', 'released', 'converted', 'manual_review')),
    CONSTRAINT chk_reservation_block CHECK (blocks_stock IN (0, 1))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
    payment_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    checkout_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    stripe_checkout_session_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    stripe_payment_intent_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    payment_method_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    amount_minor INT UNSIGNED NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    verification_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    refund_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'none',
    dispute_status VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'none',
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (payment_id),
    UNIQUE KEY uq_payment_checkout (checkout_id),
    UNIQUE KEY uq_payment_session (stripe_checkout_session_id),
    KEY idx_payment_order (order_id),
    CONSTRAINT fk_payment_checkout FOREIGN KEY (checkout_id)
        REFERENCES checkout_sagas (checkout_id),
    CONSTRAINT chk_payment_amount CHECK (amount_minor >= 50 AND currency = 'eur'),
    CONSTRAINT chk_payment_status CHECK (status IN
        ('created', 'pending', 'processing', 'succeeded', 'failed', 'canceled', 'manual_review')),
    CONSTRAINT chk_payment_method_type CHECK (payment_method_type IS NULL OR payment_method_type IN
        ('card', 'paypal', 'klarna', 'sepa_debit')),
    CONSTRAINT chk_payment_verification CHECK (verification_status IN
        ('unverified', 'verified', 'mismatch', 'manual_review')),
    CONSTRAINT chk_payment_refund CHECK (refund_status IN
        ('none', 'pending', 'succeeded', 'failed', 'manual_review'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_sequences (
    sequence_name VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    next_value BIGINT UNSIGNED NOT NULL,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (sequence_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
    order_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_number VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    checkout_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payment_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    customer_email VARCHAR(320) NOT NULL,
    customer_name VARCHAR(200) NOT NULL,
    shipping_address JSON NOT NULL,
    billing_address JSON NULL,
    product_snapshot JSON NOT NULL,
    shipping_snapshot JSON NOT NULL,
    legal_bundle_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    confirmed_at DATETIME(6) NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (order_id),
    UNIQUE KEY uq_order_number (order_number),
    UNIQUE KEY uq_order_checkout (checkout_id),
    UNIQUE KEY uq_order_payment (payment_id),
    CONSTRAINT fk_order_checkout FOREIGN KEY (checkout_id)
        REFERENCES checkout_sagas (checkout_id),
    CONSTRAINT fk_order_payment FOREIGN KEY (payment_id)
        REFERENCES payments (payment_id),
    CONSTRAINT fk_order_legal FOREIGN KEY (legal_bundle_id)
        REFERENCES legal_bundles (legal_bundle_id),
    CONSTRAINT chk_order_status CHECK (status IN ('confirmed', 'canceled'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
    order_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    position_no TINYINT UNSIGNED NOT NULL,
    product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    quantity TINYINT UNSIGNED NOT NULL,
    price_minor INT UNSIGNED NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    product_snapshot JSON NOT NULL,
    PRIMARY KEY (order_id, position_no),
    CONSTRAINT fk_item_order FOREIGN KEY (order_id)
        REFERENCES orders (order_id),
    CONSTRAINT chk_item_single CHECK (position_no = 1 AND quantity = 1),
    CONSTRAINT chk_item_amount CHECK (price_minor > 0 AND currency = 'eur')
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shipments (
    shipment_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    shipping_method_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tracking_number VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    shipped_at DATETIME(6) NULL,
    delivered_at DATETIME(6) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (shipment_id),
    UNIQUE KEY uq_shipment_order (order_id),
    CONSTRAINT fk_shipment_order FOREIGN KEY (order_id)
        REFERENCES orders (order_id),
    CONSTRAINT chk_shipment_status CHECK (status IN
        ('not_ready', 'ready', 'on_hold', 'shipped', 'delivery_issue', 'returned'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS refunds (
    refund_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payment_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    stripe_refund_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    amount_minor INT UNSIGNED NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (refund_id),
    UNIQUE KEY uq_refund_stripe (stripe_refund_id),
    KEY idx_refund_payment (payment_id),
    CONSTRAINT fk_refund_payment FOREIGN KEY (payment_id)
        REFERENCES payments (payment_id),
    CONSTRAINT chk_refund_status CHECK (status IN
        ('pending', 'succeeded', 'failed', 'manual_review')),
    CONSTRAINT chk_refund_amount CHECK (amount_minor >= 50 AND currency = 'eur')
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS disputes (
    dispute_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payment_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    stripe_dispute_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    stripe_status VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    last_event_at DATETIME(6) NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (dispute_id),
    UNIQUE KEY uq_dispute_stripe (stripe_dispute_id),
    KEY idx_dispute_payment (payment_id),
    CONSTRAINT fk_dispute_payment FOREIGN KEY (payment_id)
        REFERENCES payments (payment_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS webhook_inbox (
    inbox_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    stripe_event_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_type VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    stripe_object_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    livemode TINYINT(1) NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_ciphertext MEDIUMBLOB NULL,
    payload_key_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    received_at DATETIME(6) NOT NULL,
    status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) NOT NULL,
    lease_until DATETIME(6) NULL,
    last_error VARCHAR(500) NULL,
    processed_at DATETIME(6) NULL,
    PRIMARY KEY (inbox_id),
    UNIQUE KEY uq_webhook_event (stripe_event_id),
    KEY idx_webhook_due (status, next_attempt_at, lease_until),
    CONSTRAINT chk_webhook_status CHECK (status IN
        ('queued', 'processing', 'processed', 'manual_review', 'failed'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mail_outbox (
    mail_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    dedupe_key VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    message_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    recipient VARCHAR(320) NOT NULL,
    payload JSON NOT NULL,
    status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) NOT NULL,
    lease_until DATETIME(6) NULL,
    brevo_message_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    last_error VARCHAR(500) NULL,
    sent_at DATETIME(6) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (mail_id),
    UNIQUE KEY uq_mail_dedupe (dedupe_key),
    KEY idx_mail_due (status, next_attempt_at, lease_until),
    CONSTRAINT chk_mail_status CHECK (status IN
        ('queued', 'processing', 'sent', 'delivery_unknown', 'manual_review', 'failed'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stripe_metadata_outbox (
    metadata_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    dedupe_key VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payment_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    stripe_payment_intent_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    metadata_payload JSON NOT NULL,
    status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) NOT NULL,
    lease_until DATETIME(6) NULL,
    last_error VARCHAR(500) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (metadata_id),
    UNIQUE KEY uq_metadata_dedupe (dedupe_key),
    KEY idx_metadata_due (status, next_attempt_at, lease_until),
    CONSTRAINT fk_metadata_payment FOREIGN KEY (payment_id)
        REFERENCES payments (payment_id),
    CONSTRAINT chk_metadata_status CHECK (status IN
        ('queued', 'processing', 'sent', 'manual_review', 'failed'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS review_cases (
    review_case_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    details JSON NOT NULL,
    opened_at DATETIME(6) NOT NULL,
    resolved_at DATETIME(6) NULL,
    resolved_by VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (review_case_id),
    KEY idx_review_subject (subject_type, subject_id),
    KEY idx_review_status (status, opened_at),
    CONSTRAINT chk_review_status CHECK (status IN
        ('open', 'investigating', 'resolved', 'closed'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS withdrawal_requests (
    withdrawal_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    match_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    submitted_content JSON NOT NULL,
    received_at DATETIME(6) NULL,
    confirmed_at DATETIME(6) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (withdrawal_id),
    KEY idx_withdrawal_order (order_id),
    CONSTRAINT chk_withdrawal_match CHECK (match_status IN
        ('unmatched', 'matched', 'manual_review')),
    CONSTRAINT chk_withdrawal_state CHECK (state IN
        ('awaiting_confirmation', 'submitted', 'reviewed', 'closed'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS restocks (
    restock_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    audit_correlation_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    completed_at DATETIME(6) NULL,
    PRIMARY KEY (restock_id),
    UNIQUE KEY uq_restock_order (order_id),
    CONSTRAINT chk_restock_state CHECK (state IN
        ('requested', 'verified', 'completed', 'manual_review', 'rejected')),
    CONSTRAINT chk_restock_reason CHECK (reason = 'release_return')
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS worker_leases (
    worker_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    lease_token CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    lease_until DATETIME(6) NULL,
    last_started_at DATETIME(6) NULL,
    last_finished_at DATETIME(6) NULL,
    last_success_at DATETIME(6) NULL,
    last_error VARCHAR(500) NULL,
    PRIMARY KEY (worker_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS backup_runs (
    backup_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_label VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    dump_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    structure_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    content_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    restored_at DATETIME(6) NULL,
    CONSTRAINT chk_backup_status CHECK (status IN
        ('created', 'verified', 'failed', 'manual_review')),
    PRIMARY KEY (backup_id)
) ENGINE=InnoDB;

INSERT INTO order_sequences (sequence_name, next_value)
VALUES ('carmaja-v1', 1)
ON DUPLICATE KEY UPDATE sequence_name = VALUES(sequence_name);
