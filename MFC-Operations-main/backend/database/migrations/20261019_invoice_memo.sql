-- Add a nullable memo to invoice headers. Existing invoices remain unchanged.
ALTER TABLE invoices
    ADD COLUMN memo TEXT NULL AFTER po_no;
