-- Preserve the existing Non/Tax enum while allowing an intentionally blank local choice.
ALTER TABLE sales_order_items
    MODIFY COLUMN tax_code ENUM('Non','Tax') NULL DEFAULT NULL;
