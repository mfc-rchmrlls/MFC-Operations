CREATE DATABASE IF NOT EXISTS withdrawal_qb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE withdrawal_qb;
CREATE TABLE IF NOT EXISTS employees (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, full_name VARCHAR(150) NOT NULL, username VARCHAR(80) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL, role ENUM('Admin','Employee') NOT NULL DEFAULT 'Employee', active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS clients (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, client_name VARCHAR(180) NOT NULL UNIQUE, qb_list_id VARCHAR(100) NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS sites (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, site_name VARCHAR(120) NOT NULL UNIQUE, active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS employee_permissions (
 employee_id BIGINT UNSIGNED NOT NULL, permission_code VARCHAR(80) NOT NULL, granted_by BIGINT UNSIGNED NULL,
 granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(employee_id,permission_code),
 CONSTRAINT fk_ep_employee FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE CASCADE,
 CONSTRAINT fk_ep_granted_by FOREIGN KEY(granted_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS products (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_name VARCHAR(255) NOT NULL UNIQUE,
 ht_code VARCHAR(100) NULL, qb_code VARCHAR(100) NULL, ht_description VARCHAR(255) NULL, qb_description VARCHAR(255) NULL,
 item_code VARCHAR(100) NULL, description VARCHAR(255) NULL, u_m VARCHAR(40) NULL,
 default_price DECIMAL(12,2) NULL, kg_per_box DECIMAL(12,3) NULL, qb_list_id VARCHAR(100) NULL, active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_products_ht_code(ht_code), UNIQUE KEY uq_products_qb_code(qb_code)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS withdrawals (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, withdrawal_no VARCHAR(40) NOT NULL UNIQUE, saved_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 time_received TIME NULL, pick_up_time TIME NULL, client_id BIGINT UNSIGNED NOT NULL, form_date DATE NOT NULL, po_no VARCHAR(100) NULL,
 internal_note TEXT NULL, status ENUM('Draft','Ready for Invoicing','Invoice Preparation','Invoiced','Cancelled') NOT NULL DEFAULT 'Draft',
 created_by BIGINT UNSIGNED NULL, updated_by BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_withdrawals_date(form_date), INDEX idx_withdrawals_status(status),
 CONSTRAINT fk_withdrawals_client FOREIGN KEY(client_id) REFERENCES clients(id), CONSTRAINT fk_withdrawals_created FOREIGN KEY(created_by) REFERENCES employees(id) ON DELETE SET NULL,
 CONSTRAINT fk_withdrawals_updated FOREIGN KEY(updated_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS withdrawal_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, withdrawal_id BIGINT UNSIGNED NOT NULL, line_no SMALLINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NULL,
 product_name VARCHAR(255) NOT NULL, ht_code VARCHAR(100) NULL, ht_description VARCHAR(255) NULL, quantity BIGINT UNSIGNED NOT NULL, remarks TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_withdrawal_line(withdrawal_id,line_no), INDEX idx_wi_product(product_id),
 CONSTRAINT fk_wi_withdrawal FOREIGN KEY(withdrawal_id) REFERENCES withdrawals(id) ON DELETE CASCADE,
 CONSTRAINT fk_wi_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS invoices (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, withdrawal_id BIGINT UNSIGNED NOT NULL UNIQUE, customer_id BIGINT UNSIGNED NOT NULL, invoice_number VARCHAR(100) NULL, invoice_date DATE NOT NULL,
 po_no VARCHAR(100) NULL, memo TEXT NULL, status ENUM('Draft','Ready','Queued','Processing','Invoiced','Error','Uncertain Result') NOT NULL DEFAULT 'Draft',
 qb_invoice_no VARCHAR(100) NULL, qb_txn_id VARCHAR(100) NULL, submitted_by BIGINT UNSIGNED NULL, submitted_at DATETIME NULL,
 created_by BIGINT UNSIGNED NULL, updated_by BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_invoices_invoice_number(invoice_number),
 CONSTRAINT fk_invoice_withdrawal FOREIGN KEY(withdrawal_id) REFERENCES withdrawals(id), CONSTRAINT fk_invoice_customer FOREIGN KEY(customer_id) REFERENCES clients(id),
 CONSTRAINT fk_invoice_submitted FOREIGN KEY(submitted_by) REFERENCES employees(id) ON DELETE SET NULL, CONSTRAINT fk_invoice_created FOREIGN KEY(created_by) REFERENCES employees(id) ON DELETE SET NULL,
 CONSTRAINT fk_invoice_updated FOREIGN KEY(updated_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS invoice_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, invoice_id BIGINT UNSIGNED NOT NULL, withdrawal_item_id BIGINT UNSIGNED NULL, source_type ENUM('WITHDRAWAL','ADDITIONAL') NOT NULL DEFAULT 'WITHDRAWAL',
 product_id BIGINT UNSIGNED NULL, site_id BIGINT UNSIGNED NULL, product_name VARCHAR(255) NOT NULL, quantity BIGINT UNSIGNED NOT NULL,
 lot_number VARCHAR(100) NULL, item_code VARCHAR(100) NULL, description VARCHAR(255) NULL, u_m VARCHAR(40) NULL, price_each DECIMAL(12,2) NULL DEFAULT NULL,
 amount DECIMAL(14,2) NULL DEFAULT NULL, price_source ENUM('mfc_entered','quickbooks_pending','quickbooks_verified','explicit_zero','unresolved') NOT NULL DEFAULT 'unresolved',
 tax_code VARCHAR(40) NOT NULL DEFAULT 'Non', expiration_date DATE NULL,
 created_by BIGINT UNSIGNED NULL, updated_by BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_invoice_items_invoice(invoice_id), CONSTRAINT fk_ii_invoice FOREIGN KEY(invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
 CONSTRAINT fk_ii_wi FOREIGN KEY(withdrawal_item_id) REFERENCES withdrawal_items(id) ON DELETE SET NULL,
 CONSTRAINT fk_ii_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE SET NULL, CONSTRAINT fk_ii_site FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE SET NULL,
 CONSTRAINT fk_ii_created FOREIGN KEY(created_by) REFERENCES employees(id) ON DELETE SET NULL, CONSTRAINT fk_ii_updated FOREIGN KEY(updated_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS qb_jobs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, invoice_id BIGINT UNSIGNED NOT NULL, request_key CHAR(36) NOT NULL UNIQUE,
 status ENUM('Queued','Processing','Invoiced','Error','Uncertain Result') NOT NULL DEFAULT 'Queued', request_xml MEDIUMTEXT NULL, response_xml MEDIUMTEXT NULL,
 error_message TEXT NULL, qb_invoice_no VARCHAR(100) NULL, qb_txn_id VARCHAR(100) NULL, attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 created_by BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_qb_job_invoice(invoice_id), CONSTRAINT fk_job_invoice FOREIGN KEY(invoice_id) REFERENCES invoices(id), CONSTRAINT fk_job_employee FOREIGN KEY(created_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS activity_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, employee_id BIGINT UNSIGNED NULL, action VARCHAR(80) NOT NULL, entity_type VARCHAR(80) NOT NULL, entity_id BIGINT UNSIGNED NULL,
 details TEXT NULL, ip_address VARCHAR(45) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_activity_created(created_at),
 CONSTRAINT fk_activity_employee FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO sites(site_name) VALUES ('Main Warehouse'),('Branch A'),('Branch B');
INSERT IGNORE INTO clients(client_name) VALUES ('Sample Client A'),('Sample Client B'),('Sample Client C');
INSERT IGNORE INTO products(product_name,item_code,description,qb_code,qb_description,u_m,default_price,active)
VALUES ('CHICKEN POPPERS','CP-001','Chicken Poppers','CP-001','Chicken Poppers','BOX',125.00,0),
('GRILLED TENDER CHUNKS','GTC-002','Grilled Tender Chunks','GTC-002','Grilled Tender Chunks','BOX',210.00,0),
('CHICKEN NUGGETS','CN-003','Chicken Nuggets','CN-003','Chicken Nuggets','BOX',180.00,0),
('BEEF PATTY','BP-004','Beef Patty','BP-004','Beef Patty','BOX',250.00,0);
