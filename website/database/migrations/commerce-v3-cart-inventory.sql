-- Forward migration. Checkout creation must be closed during application.
-- Do not infer a quantity for existing collection products.
ALTER TABLE commerce_inventory DROP CHECK chk_inventory_binary,
    MODIFY on_hand INT UNSIGNED NOT NULL,
    ADD quantity_confirmed BOOLEAN NOT NULL DEFAULT FALSE,
    ADD CONSTRAINT chk_inventory_nonnegative CHECK (on_hand >= 0);
ALTER TABLE inventory_adjustments DROP CHECK chk_adjustment_target,
    DROP CHECK chk_adjustment_previous, DROP CHECK chk_adjustment_reason,
    MODIFY target_on_hand INT UNSIGNED NOT NULL,
    MODIFY previous_on_hand INT UNSIGNED NOT NULL,
    ADD CONSTRAINT chk_adjustment_reason CHECK (reason IN
        ('activate_new_unique','shop_sale','mark_unsellable','release_return',
         'initial_count','receive_stock','correction'));
ALTER TABLE commerce_products MODIFY sales_model
    ENUM('unique','collection','stocked_collection') NOT NULL DEFAULT 'unique',
    ADD notice_snapshot JSON NULL,
    ADD public_snapshot JSON NULL;
ALTER TABLE checkout_sagas MODIFY product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    MODIFY product_version BIGINT UNSIGNED NULL,
    MODIFY source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    MODIFY sales_model ENUM('unique','collection','stocked_collection') NOT NULL DEFAULT 'unique',
    ADD checkout_kind ENUM('single','cart') NOT NULL DEFAULT 'single',
    ADD session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD cart_version BIGINT UNSIGNED NULL,
    ADD snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE checkout_sagas ADD legal_bundle_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE reservations DROP INDEX uq_reservation_checkout,
    DROP CHECK chk_reservation_quantity, MODIFY quantity INT UNSIGNED NOT NULL,
    ADD UNIQUE KEY uq_reservation_checkout_product (checkout_id, product_id),
    ADD CONSTRAINT chk_reservation_positive CHECK (quantity > 0);
ALTER TABLE order_items DROP CHECK chk_item_single,
    MODIFY quantity INT UNSIGNED NOT NULL,
    ADD CONSTRAINT chk_item_positive CHECK (position_no BETWEEN 1 AND 100 AND quantity > 0);
ALTER TABLE checkout_tokens MODIFY product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    MODIFY product_version BIGINT UNSIGNED NULL;
ALTER TABLE refunds DROP CHECK chk_refund_amount,
    ADD CONSTRAINT chk_refund_positive CHECK (amount_minor > 0 AND currency = 'eur');
ALTER TABLE payments DROP CHECK chk_payment_refund,
    ADD refunded_minor INT UNSIGNED NOT NULL DEFAULT 0,
    ADD CONSTRAINT chk_payment_refund CHECK (refund_status IN
        ('none','pending','partial','succeeded','failed','manual_review'));
CREATE TABLE shop_carts (
    session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    cart_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    items JSON NOT NULL,
    price_reference JSON NULL,
    updated_at DATETIME(6) NOT NULL,
    FOREIGN KEY (session_hash) REFERENCES shop_sessions(session_hash) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE cart_operations (
    session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (session_hash, idempotency_key)
) ENGINE=InnoDB;
CREATE TABLE cart_quotes (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    cart_version BIGINT UNSIGNED NOT NULL,
    snapshot JSON NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    KEY idx_quote_expiry(expires_at)
) ENGINE=InnoDB;
CREATE TABLE cart_rate_limits (
    bucket_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    minute_slot BIGINT UNSIGNED NOT NULL,
    attempts INT UNSIGNED NOT NULL,
    PRIMARY KEY(bucket_hash, minute_slot)
) ENGINE=InnoDB;
CREATE TABLE checkout_items (
    checkout_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    position_no TINYINT UNSIGNED NOT NULL,
    product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    product_snapshot JSON NOT NULL,
    PRIMARY KEY (checkout_id, position_no),
    UNIQUE KEY uq_checkout_item_product(checkout_id, product_id),
    FOREIGN KEY (checkout_id) REFERENCES checkout_sagas(checkout_id),
    FOREIGN KEY (product_id) REFERENCES commerce_products(product_id),
    CHECK (quantity > 0 AND position_no BETWEEN 1 AND 100)
) ENGINE=InnoDB;
CREATE TABLE inventory_initializations (
    product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    target_on_hand INT UNSIGNED NOT NULL,
    actor_id VARCHAR(100) NOT NULL,
    operation_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    applied_at DATETIME(6) NULL,
    UNIQUE KEY uq_inventory_initial_operation(operation_id)
) ENGINE=InnoDB;
CREATE TABLE withdrawal_items (
    withdrawal_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    position_no TINYINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    PRIMARY KEY(withdrawal_id, position_no),
    FOREIGN KEY(withdrawal_id) REFERENCES withdrawal_requests(withdrawal_id),
    FOREIGN KEY(order_id, position_no) REFERENCES order_items(order_id, position_no),
    CHECK (quantity > 0)
) ENGINE=InnoDB;
-- Existing orders already have order_items(position_no=1, quantity=1).
CREATE TABLE restocked_items (
    operation_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    position_no TINYINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    actor_id VARCHAR(100) NOT NULL,
    checked_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    FOREIGN KEY(order_id, position_no) REFERENCES order_items(order_id, position_no),
    CHECK (quantity > 0)
) ENGINE=InnoDB;
-- Leave every historical snapshot, price and legal reference unchanged.
