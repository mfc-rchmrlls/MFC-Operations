-- Add typed, preview-first QuickBooks export records without changing qb_jobs.
-- Back up the database before applying. Created for MariaDB 10.4+ / MySQL InnoDB.

CREATE TABLE IF NOT EXISTS qb_export_reference_verifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference_type ENUM('customer', 'item', 'tax_code') NOT NULL,
    local_key VARCHAR(100) NOT NULL,
    qb_list_id VARCHAR(100) NOT NULL,
    qb_full_name VARCHAR(255) NULL,
    verified_at DATETIME NOT NULL,
    verified_by BIGINT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_qb_reference_verification (reference_type, local_key),
    UNIQUE KEY uq_qb_reference_target (reference_type, qb_list_id),
    KEY idx_qb_reference_list_id (reference_type, qb_list_id),
    CONSTRAINT fk_qb_ref_verification_employee
        FOREIGN KEY (verified_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS qb_exports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    export_id CHAR(36) NOT NULL,
    transaction_type ENUM('invoice', 'sales_order') NOT NULL,
    source_transaction_id BIGINT UNSIGNED NOT NULL,
    source_revision CHAR(64) NOT NULL,
    snapshot_json MEDIUMTEXT NOT NULL,
    status ENUM('pending', 'processing', 'succeeded', 'rejected', 'unknown') NOT NULL DEFAULT 'pending',
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    qb_txn_id VARCHAR(100) NULL,
    qb_ref_number VARCHAR(100) NULL,
    claim_token CHAR(36) NULL,
    lease_until DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    UNIQUE KEY uq_qb_exports_export_id (export_id),
    UNIQUE KEY uq_qb_exports_source (transaction_type, source_transaction_id),
    KEY idx_qb_exports_status_created (status, created_at),
    CONSTRAINT fk_qb_exports_employee
        FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS qb_export_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    export_id CHAR(36) NOT NULL,
    attempt_number SMALLINT UNSIGNED NOT NULL,
    request_id CHAR(36) NOT NULL,
    outcome ENUM(
        'validation_only',
        'bridge_unavailable',
        'dispatched',
        'succeeded',
        'rejected',
        'unknown'
    ) NOT NULL,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    diagnostic_code VARCHAR(80) NULL,
    diagnostic_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_qb_export_attempt_number (export_id, attempt_number),
    UNIQUE KEY uq_qb_export_attempt_request (request_id),
    KEY idx_qb_export_attempt_time (export_id, started_at),
    CONSTRAINT fk_qb_export_attempt_export
        FOREIGN KEY (export_id) REFERENCES qb_exports(export_id) ON DELETE CASCADE
) ENGINE=InnoDB;
