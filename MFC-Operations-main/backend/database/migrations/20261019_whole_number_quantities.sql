-- Stop safely if legacy rows have fractional quantities. Resolve those values
-- against authoritative records before rerunning; this migration never rounds
-- or otherwise changes historical quantities.
DELIMITER $$

DROP PROCEDURE IF EXISTS migrate_whole_number_quantities$$

CREATE PROCEDURE migrate_whole_number_quantities()
BEGIN
    IF EXISTS (SELECT 1 FROM withdrawal_items WHERE quantity <> TRUNCATE(quantity, 0))
       OR EXISTS (SELECT 1 FROM invoice_items WHERE quantity <> TRUNCATE(quantity, 0)) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Fractional quantities exist. Reconcile them before migrating; no quantity data was changed.';
    END IF;

    ALTER TABLE withdrawal_items
        MODIFY quantity BIGINT UNSIGNED NOT NULL;

    ALTER TABLE invoice_items
        MODIFY quantity BIGINT UNSIGNED NOT NULL;
END$$

CALL migrate_whole_number_quantities()$$
DROP PROCEDURE migrate_whole_number_quantities$$

DELIMITER ;
