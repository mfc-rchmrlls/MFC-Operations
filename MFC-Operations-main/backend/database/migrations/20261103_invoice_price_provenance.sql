-- Preserve the distinction between an entered price and an unresolved blank/default.
-- Back up the database before applying. Existing lines are intentionally unresolved.
ALTER TABLE invoice_items
    MODIFY COLUMN price_each DECIMAL(12,2) NULL DEFAULT NULL,
    MODIFY COLUMN amount DECIMAL(14,2) NULL DEFAULT NULL,
    ADD COLUMN price_source ENUM(
        'mfc_entered',
        'quickbooks_pending',
        'quickbooks_verified',
        'explicit_zero',
        'unresolved'
    ) NOT NULL DEFAULT 'unresolved' AFTER amount;
