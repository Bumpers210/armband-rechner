-- Collection availability migration.
-- Existing inventory rows remain untouched for historical evidence. New
-- collection products do not create or consume inventory rows.

SET @carmaja_schema = DATABASE();

SET @carmaja_has_product_sales_model = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @carmaja_schema
      AND TABLE_NAME = 'commerce_products'
      AND COLUMN_NAME = 'sales_model'
);
SET @carmaja_sql = IF(
    @carmaja_has_product_sales_model = 0,
    "ALTER TABLE commerce_products ADD COLUMN sales_model ENUM('unique','collection') NOT NULL DEFAULT 'unique' AFTER sales_enabled",
    'SELECT 1'
);
PREPARE carmaja_statement FROM @carmaja_sql;
EXECUTE carmaja_statement;
DEALLOCATE PREPARE carmaja_statement;

SET @carmaja_has_checkout_sales_model = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @carmaja_schema
      AND TABLE_NAME = 'checkout_sagas'
      AND COLUMN_NAME = 'sales_model'
);
SET @carmaja_sql = IF(
    @carmaja_has_checkout_sales_model = 0,
    "ALTER TABLE checkout_sagas ADD COLUMN sales_model ENUM('unique','collection') NOT NULL DEFAULT 'unique' AFTER legal_bundle_id",
    'SELECT 1'
);
PREPARE carmaja_statement FROM @carmaja_sql;
EXECUTE carmaja_statement;
DEALLOCATE PREPARE carmaja_statement;

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
