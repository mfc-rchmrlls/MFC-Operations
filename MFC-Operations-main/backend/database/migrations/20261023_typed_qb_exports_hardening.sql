-- Safe additive hardening for installations that already applied
-- 20261023_typed_qb_exports.sql. Existing verification rows are preserved;
-- rows without signed company-file evidence fail closed in application code.
-- IF NOT EXISTS allows safe reruns after checking for a partially applied migration.

ALTER TABLE qb_export_reference_verifications
    ADD COLUMN IF NOT EXISTS company_file_fingerprint CHAR(64) NULL AFTER verified_by,
    ADD COLUMN IF NOT EXISTS diagnostic_request_id CHAR(36) NULL AFTER company_file_fingerprint,
    ADD COLUMN IF NOT EXISTS evidence_hmac CHAR(64) NULL AFTER diagnostic_request_id;

CREATE TABLE IF NOT EXISTS qb_export_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    export_id CHAR(36) NOT NULL,
    event_type ENUM('created', 'claimed', 'transitioned') NOT NULL,
    from_status ENUM('pending', 'processing', 'succeeded', 'rejected', 'unknown') NULL,
    to_status ENUM('pending', 'processing', 'succeeded', 'rejected', 'unknown') NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    request_id CHAR(36) NULL,
    diagnostic_code VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_qb_export_event_time (export_id, created_at),
    CONSTRAINT fk_qb_export_event_export
        FOREIGN KEY (export_id) REFERENCES qb_exports(export_id) ON DELETE CASCADE,
    CONSTRAINT fk_qb_export_event_employee
        FOREIGN KEY (actor_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB;
