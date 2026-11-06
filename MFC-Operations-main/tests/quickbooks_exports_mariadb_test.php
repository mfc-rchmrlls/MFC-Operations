<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/core/quickbooks_exports.php';

$host = getenv('WQ_DB_HOST') ?: '127.0.0.1';
$user = getenv('WQ_DB_USER') ?: 'root';
$password = getenv('WQ_DB_PASS') ?: '';
$dsn = "mysql:host={$host};charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

function maria_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: {$message}\n";
}

function maria_expect_failure(callable $operation, string $sqlState, string $message): void
{
    try {
        $operation();
    } catch (PDOException $error) {
        $actualState = (string)($error->errorInfo[0] ?? $error->getCode());
        if ($actualState !== $sqlState) {
            throw new RuntimeException("FAIL: {$message}; unexpected SQLSTATE {$actualState}");
        }
        echo "PASS: {$message}\n";
        return;
    }
    throw new RuntimeException('FAIL: ' . $message);
}

$admin = new PDO($dsn, $user, $password, $options);
$serverVersion = (string)$admin->query('SELECT VERSION()')->fetchColumn();
if (!preg_match('/^10\.4\.\d+-MariaDB/i', $serverVersion)) {
    throw new RuntimeException('This isolated compatibility test requires the configured MariaDB 10.4 server; found ' . $serverVersion);
}
echo 'MariaDB test server: ' . $serverVersion . PHP_EOL;

$database = 'mfc_qb_export_test_' . bin2hex(random_bytes(6));
if (!preg_match('/^mfc_qb_export_test_[a-f0-9]{12}$/D', $database)) {
    throw new RuntimeException('Generated test database name failed validation.');
}
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$testDsn = "mysql:host={$host};dbname={$database};charset=utf8mb4";
$first = null;
$second = null;

try {
    $first = new PDO($testDsn, $user, $password, $options);
    $first->exec(
        "CREATE TABLE employees (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            role ENUM('Admin','Employee') NOT NULL,
            active TINYINT(1) NOT NULL
        ) ENGINE=InnoDB"
    );
    $first->exec(
        'CREATE TABLE qb_jobs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_id BIGINT UNSIGNED NOT NULL UNIQUE,
            marker VARCHAR(40) NOT NULL
        ) ENGINE=InnoDB'
    );
    $first->exec("INSERT INTO employees(id,role,active) VALUES (1,'Admin',1)");
    $first->exec("INSERT INTO qb_jobs(invoice_id,marker) VALUES (9,'legacy-history')");

    $first->exec(
        'CREATE TABLE invoice_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            price_each DECIMAL(12,2) NOT NULL DEFAULT 0,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB'
    );
    $first->exec("INSERT INTO invoice_items(id,price_each,amount) VALUES (1,0.00,0.00),(2,12.50,25.00)");
    $priceMigrationPath = __DIR__ . '/../backend/database/migrations/20261103_invoice_price_provenance.sql';
    $priceMigration = file_get_contents($priceMigrationPath);
    if (!is_string($priceMigration)) {
        throw new RuntimeException('Could not read the invoice price provenance migration.');
    }
    $priceMigration = preg_replace('/^\s*--.*$/m', '', $priceMigration);
    foreach (preg_split('/;\s*/', $priceMigration, -1, PREG_SPLIT_NO_EMPTY) as $statement) {
        $first->exec(trim($statement));
    }
    $historicalPrices = $first->query('SELECT id,price_each,amount,price_source FROM invoice_items ORDER BY id')->fetchAll();
    maria_assert(
        count($historicalPrices) === 2
            && (float)$historicalPrices[0]['price_each'] === 0.0
            && (float)$historicalPrices[1]['price_each'] === 12.5
            && $historicalPrices[0]['price_source'] === 'unresolved'
            && $historicalPrices[1]['price_source'] === 'unresolved',
        'price provenance migration preserves historical values and classifies their origin as unresolved'
    );
    $first->exec("INSERT INTO invoice_items(price_each,amount,price_source) VALUES (NULL,NULL,'quickbooks_pending')");
    maria_assert(
        $first->query('SELECT price_each IS NULL AND amount IS NULL AND price_source=\'quickbooks_pending\' FROM invoice_items WHERE id=3')->fetchColumn() == 1,
        'invoice schema accepts a blank price without converting it to zero'
    );

    foreach (['20261023_typed_qb_exports.sql'] as $migrationName) {
        $migrationPath = __DIR__ . '/../backend/database/migrations/' . $migrationName;
        $migration = file_get_contents($migrationPath);
        if (!is_string($migration)) {
            throw new RuntimeException('Could not read migration ' . $migrationName . '.');
        }
        $migration = preg_replace('/^\s*--.*$/m', '', $migration);
        foreach (preg_split('/;\s*/', $migration, -1, PREG_SPLIT_NO_EMPTY) as $statement) {
            $first->exec(trim($statement));
        }
    }
    $first->exec(
        "INSERT INTO qb_export_reference_verifications
         (reference_type,local_key,qb_list_id,verified_at,verified_by,active)
         VALUES('customer','client:77','LEGACY-LIST',CURRENT_TIMESTAMP,1,1)"
    );
    $first->exec(
        "INSERT INTO qb_exports(export_id,transaction_type,source_transaction_id,source_revision,snapshot_json,created_by)
         VALUES('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','invoice',99,REPEAT('a',64),'{}',1)"
    );
    $first->exec(
        "INSERT INTO qb_export_attempts(export_id,attempt_number,request_id,outcome,started_at)
         VALUES('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',1,'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb','validation_only',CURRENT_TIMESTAMP)"
    );
    $hardeningPath = __DIR__ . '/../backend/database/migrations/20261023_typed_qb_exports_hardening.sql';
    $hardening = file_get_contents($hardeningPath);
    if (!is_string($hardening)) {
        throw new RuntimeException('Could not read typed export hardening migration.');
    }
    $hardening = preg_replace('/^\s*--.*$/m', '', $hardening);
    foreach (preg_split('/;\s*/', $hardening, -1, PREG_SPLIT_NO_EMPTY) as $statement) {
        $first->exec(trim($statement));
    }
    foreach (preg_split('/;\s*/', $hardening, -1, PREG_SPLIT_NO_EMPTY) as $statement) {
        $first->exec(trim($statement));
    }
    $legacyVerification = $first->query(
        "SELECT active,evidence_hmac FROM qb_export_reference_verifications WHERE local_key='client:77'"
    )->fetch();
    maria_assert((int)$legacyVerification['active'] === 1 && $legacyVerification['evidence_hmac'] === null, 'hardening preserves legacy verification rows without letting them gain signed approval');
    $hardenedColumns = $first->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='qb_export_reference_verifications'
         AND COLUMN_NAME IN ('company_file_fingerprint','diagnostic_request_id','evidence_hmac')"
    )->fetchColumn();
    maria_assert((int)$hardenedColumns === 3, 'hardening adds all signed-evidence columns and can be safely rerun');
    maria_assert(
        (int)$first->query("SELECT COUNT(*) FROM qb_exports WHERE export_id='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'")->fetchColumn() === 1
            && (int)$first->query("SELECT COUNT(*) FROM qb_export_attempts WHERE request_id='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'")->fetchColumn() === 1,
        'hardening preserves existing export and attempt history'
    );

    $expectedTables = ['qb_export_reference_verifications', 'qb_exports', 'qb_export_events', 'qb_export_attempts'];
    foreach ($expectedTables as $table) {
        $engine = $first->query("SHOW TABLE STATUS LIKE '{$table}'")->fetch();
        maria_assert(($engine['Engine'] ?? null) === 'InnoDB', "{$table} is created as InnoDB");
    }

    $indexes = $first->query("SHOW INDEX FROM qb_exports")->fetchAll();
    $indexNames = array_unique(array_column($indexes, 'Key_name'));
    maria_assert(in_array('uq_qb_exports_export_id', $indexNames, true)
        && in_array('uq_qb_exports_source', $indexNames, true), 'export ID and source uniqueness indexes exist');
    $verificationIndexes = $first->query('SHOW INDEX FROM qb_export_reference_verifications')->fetchAll();
    $verificationIndexNames = array_unique(array_column($verificationIndexes, 'Key_name'));
    maria_assert(in_array('uq_qb_reference_verification', $verificationIndexNames, true)
        && in_array('uq_qb_reference_target', $verificationIndexNames, true), 'verification local and QuickBooks references are unique');

    $exports = $first->prepare(
        'INSERT INTO qb_exports(export_id,transaction_type,source_transaction_id,source_revision,snapshot_json,created_by)
         VALUES(?,?,?,?,?,?)'
    );
    $exportId = '11111111-1111-4111-8111-111111111111';
    $exports->execute([$exportId, 'invoice', 101, str_repeat('a', 64), '{}', 1]);
    maria_expect_failure(
        static fn() => $exports->execute(['22222222-2222-4222-8222-222222222222', 'invoice', 101, str_repeat('b', 64), '{}', 1]),
        '23000',
        'duplicate source transaction is rejected by the database'
    );
    maria_expect_failure(
        static fn() => $first->prepare(
            "INSERT INTO qb_export_attempts(export_id,attempt_number,request_id,outcome,started_at)
             VALUES('missing-export',1,'33333333-3333-4333-8333-333333333333','unknown',CURRENT_TIMESTAMP)"
        )->execute(),
        '23000',
        'attempt history enforces its export foreign key'
    );
    maria_expect_failure(
        static fn() => $first->exec(
            "INSERT INTO qb_export_reference_verifications
             (reference_type,local_key,qb_list_id,verified_at,verified_by,company_file_fingerprint,diagnostic_request_id,evidence_hmac)
             VALUES('customer','client:1','LIST-1',CURRENT_TIMESTAMP,999,REPEAT('a',64),'44444444-4444-4444-8444-444444444444',REPEAT('b',64))"
        ),
        '23000',
        'mapping verifier foreign key rejects nonexistent employees'
    );

    $second = new PDO($testDsn, $user, $password, $options);
    $first->beginTransaction();
    $exports->execute([$exportId = '55555555-5555-4555-8555-555555555555', 'invoice', 202, str_repeat('c', 64), '{}', 1]);
    $second->exec('SET innodb_lock_wait_timeout=1');
    $second->beginTransaction();
    $secondInsert = $second->prepare(
        'INSERT INTO qb_exports(export_id,transaction_type,source_transaction_id,source_revision,snapshot_json,created_by)
         VALUES(?,?,?,?,?,?)'
    );
    maria_expect_failure(
        static fn() => $secondInsert->execute(['66666666-6666-4666-8666-666666666666', 'invoice', 202, str_repeat('d', 64), '{}', 1]),
        'HY000',
        'concurrent duplicate waits on the InnoDB unique key rather than inserting a second row'
    );
    if ($second->inTransaction()) {
        $second->rollBack();
    }
    $first->commit();
    maria_expect_failure(
        static fn() => $secondInsert->execute(['66666666-6666-4666-8666-666666666666', 'invoice', 202, str_repeat('d', 64), '{}', 1]),
        '23000',
        'the concurrent loser is rejected after the winner commits'
    );
    maria_assert((int)$first->query('SELECT COUNT(*) FROM qb_exports WHERE source_transaction_id=202')->fetchColumn() === 1, 'concurrent queue creation leaves exactly one export');

    maria_assert(!function_exists('qb_export_claim') && !function_exists('qb_export_transition'),
        'transaction claim and status-transition services are not available');
    maria_assert((string)$first->query("SELECT status FROM qb_exports WHERE export_id='11111111-1111-4111-8111-111111111111'")->fetchColumn() === 'pending',
        'pending preview remains pending without transaction submission');
    maria_assert((int)$first->query("SELECT COUNT(*) FROM qb_export_attempts WHERE export_id='11111111-1111-4111-8111-111111111111'")->fetchColumn() === 0,
        'read-only preview workflow creates no transaction attempt history');

    $legacy = $first->query('SELECT marker FROM qb_jobs WHERE invoice_id=9')->fetchColumn();
    maria_assert($legacy === 'legacy-history', 'legacy qb_jobs schema and history remain unchanged by migration');

    $first->exec('DROP TABLE qb_export_attempts');
    $first->exec('DROP TABLE qb_export_events');
    $first->exec('DROP TABLE qb_exports');
    $first->exec('DROP TABLE qb_export_reference_verifications');
    $remaining = $first->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
         AND TABLE_NAME IN ('qb_export_attempts','qb_export_events','qb_exports','qb_export_reference_verifications')"
    )->fetchColumn();
    maria_assert((int)$remaining === 0, 'documented reverse-dependency rollback removes all four new tables');
    maria_assert((string)$first->query('SELECT marker FROM qb_jobs WHERE invoice_id=9')->fetchColumn() === 'legacy-history', 'rollback leaves legacy qb_jobs data intact');

    echo "All isolated MariaDB migration checks passed.\n";
} finally {
    if ($second instanceof PDO && $second->inTransaction()) {
        $second->rollBack();
    }
    if ($first instanceof PDO && $first->inTransaction()) {
        $first->rollBack();
    }
    $second = null;
    $first = null;
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
