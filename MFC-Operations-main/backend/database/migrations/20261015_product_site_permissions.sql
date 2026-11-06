-- MFC Operations product/site migration (MySQL 8 / XAMPP)
-- BACK UP the database first. Run statements in order in phpMyAdmin.
-- Existing item_code/description become the QB-facing values. HT codes are
-- deliberately left NULL because no authoritative HT code list exists here.

ALTER TABLE products
  ADD COLUMN ht_code VARCHAR(100) NULL,
  ADD COLUMN qb_code VARCHAR(100) NULL,
  ADD COLUMN ht_description VARCHAR(255) NULL,
  ADD COLUMN qb_description VARCHAR(255) NULL;

UPDATE products SET qb_code=NULLIF(TRIM(item_code),''),
  qb_description=NULLIF(TRIM(description),''),
  ht_description=NULLIF(TRIM(product_name),'');

ALTER TABLE withdrawal_items
  ADD COLUMN ht_code VARCHAR(100) NULL,
  ADD COLUMN ht_description VARCHAR(255) NULL;

CREATE TABLE employee_permissions (
 employee_id BIGINT UNSIGNED NOT NULL,
 permission_code VARCHAR(80) NOT NULL,
 granted_by BIGINT UNSIGNED NULL,
 granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(employee_id,permission_code),
 CONSTRAINT fk_ep_employee FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE CASCADE,
 CONSTRAINT fk_ep_granted_by FOREIGN KEY(granted_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Review duplicates before creating code indexes; do not choose arbitrary winners.
SELECT 'HT' AS mapping_type, MIN(ht_code) AS mapping, GROUP_CONCAT(id ORDER BY id) AS product_ids
FROM products WHERE ht_code IS NOT NULL AND TRIM(ht_code)<>'' GROUP BY LOWER(TRIM(ht_code)) HAVING COUNT(*)>1;
SELECT 'QB' AS mapping_type, MIN(qb_code) AS mapping, GROUP_CONCAT(id ORDER BY id) AS product_ids
FROM products WHERE qb_code IS NOT NULL AND TRIM(qb_code)<>'' GROUP BY LOWER(TRIM(qb_code)) HAVING COUNT(*)>1;

-- STOP HERE and review/fix any QB conflicts before running the next two statements.
-- ALTER TABLE products ADD UNIQUE KEY uq_products_ht_code(ht_code);
-- ALTER TABLE products ADD UNIQUE KEY uq_products_qb_code(qb_code);

-- After code deployment has removed product-level site reads, remove the FK/column.
-- Verify the actual FK name first with SHOW CREATE TABLE products.
-- ALTER TABLE products DROP FOREIGN KEY fk_products_site;
-- ALTER TABLE products DROP COLUMN default_site_id;

-- Legacy review report: these products need a real HT code and any missing
-- invoice mapping/description reviewed before being enabled for new transactions.
SELECT id, product_name, item_code, description, ht_code, qb_code, ht_description, qb_description,
       active, qb_list_id
FROM products
WHERE active=1 AND (ht_code IS NULL OR qb_code IS NULL OR ht_description IS NULL OR qb_description IS NULL)
ORDER BY product_name;

-- Check references before permanent deletion in the UI; this query is a review aid.
SELECT p.id,p.product_name,
 (SELECT COUNT(*) FROM withdrawal_items wi WHERE wi.product_id=p.id) AS withdrawals,
 (SELECT COUNT(*) FROM invoice_items ii WHERE ii.product_id=p.id) AS invoices
FROM products p ORDER BY p.product_name;
