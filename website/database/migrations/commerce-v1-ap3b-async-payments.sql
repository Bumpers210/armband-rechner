ALTER TABLE payments
    ADD COLUMN payment_method_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL
        AFTER stripe_payment_intent_id;

ALTER TABLE payments
    DROP CHECK chk_payment_status,
    ADD CONSTRAINT chk_payment_status CHECK (status IN
        ('created', 'pending', 'processing', 'succeeded', 'failed', 'canceled', 'manual_review')),
    ADD CONSTRAINT chk_payment_method_type CHECK (payment_method_type IS NULL OR payment_method_type IN
        ('card', 'paypal', 'klarna', 'sepa_debit'));
