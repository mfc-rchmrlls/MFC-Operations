-- Safe additive migration: existing invoices remain NULL and are not assigned numbers.
-- Run once against withdrawal_qb using phpMyAdmin or the MySQL client.
ALTER TABLE invoices
    ADD COLUMN invoice_number VARCHAR(100) NULL AFTER customer_id,
    ADD UNIQUE KEY uq_invoices_invoice_number (invoice_number);

