<?php

declare(strict_types=1);
require_once __DIR__ . '/../backend/core/functions.php';

$host = getenv('WQ_DB_HOST') ?: '127.0.0.1';
$user = getenv('WQ_DB_USER') ?: 'root';
$password = getenv('WQ_DB_PASS') ?: '';
$dsn = "mysql:host={$host};charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

function sales_order_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: {$message}\n";
}

function sales_order_run_controller(string $controllerPath, array $payload, string $database, string|false $previousDatabase): void
{
    putenv('WQ_DB_NAME=' . $database);
    $childCode = 'session_start();'
        . '$_SESSION=' . var_export(['user' => ['id' => 1, 'role' => 'Admin'], '_csrf' => $payload['_csrf']], true) . ';'
        . '$_SERVER["REQUEST_METHOD"]="POST";'
        . '$_SERVER["REMOTE_ADDR"]="127.0.0.1";'
        . '$_POST=' . var_export($payload, true) . ';'
        . 'require ' . var_export($controllerPath, true) . ';';
    $process = proc_open(
        [PHP_BINARY, '-r', $childCode],
        [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
        null
    );
    if (!is_resource($process)) {
        if ($previousDatabase === false) {
            putenv('WQ_DB_NAME');
        } else {
            putenv('WQ_DB_NAME=' . $previousDatabase);
        }
        throw new RuntimeException('Could not launch a draft save controller test.');
    }
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    $status = proc_close($process);
    if ($previousDatabase === false) {
        putenv('WQ_DB_NAME');
    } else {
        putenv('WQ_DB_NAME=' . $previousDatabase);
    }
    if ($status !== 0) {
        throw new RuntimeException('Draft save controller failed: ' . trim($output));
    }
}

$admin = new PDO($dsn, $user, $password, $options);
$serverVersion = (string)$admin->query('SELECT VERSION()')->fetchColumn();
if (!preg_match('/^10\.4\.\d+-MariaDB/i', $serverVersion)) {
    throw new RuntimeException('This isolated workflow test requires MariaDB 10.4; found ' . $serverVersion);
}

$database = 'mfc_sales_order_test_' . bin2hex(random_bytes(6));
if (!preg_match('/^mfc_sales_order_test_[a-f0-9]{12}$/D', $database)) {
    throw new RuntimeException('Generated test database name failed validation.');
}
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = null;
$previousDatabase = getenv('WQ_DB_NAME');

try {
    $pdo = new PDO("mysql:host={$host};dbname={$database};charset=utf8mb4", $user, $password, $options);
    $pdo->exec(
        "CREATE TABLE sales_order_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sales_order_id BIGINT UNSIGNED NOT NULL,
            line_no SMALLINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NULL,
            item_name VARCHAR(255) NOT NULL,
            item_code VARCHAR(100) NULL,
            qb_item_list_id VARCHAR(100) NULL,
            site_id BIGINT UNSIGNED NULL,
            description VARCHAR(255) NOT NULL,
            ordered_quantity BIGINT UNSIGNED NOT NULL,
            lot_number VARCHAR(100) NULL,
            u_m VARCHAR(40) NULL,
            rate DECIMAL(12,2) NULL,
            class_name VARCHAR(100) NULL,
            amount DECIMAL(14,2) NOT NULL,
            line_status VARCHAR(40) NULL,
            fulfilled_quantity BIGINT UNSIGNED NULL,
            expiration_date DATE NULL,
            tax_code ENUM('Non','Tax') NOT NULL DEFAULT 'Non',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );
    $pdo->exec(
        "INSERT INTO sales_order_items
         (sales_order_id,line_no,item_name,description,ordered_quantity,amount,tax_code)
         VALUES(1,1,'Legacy item','Legacy description',1,1.00,'Tax')"
    );
    $pdo->exec("SET SESSION sql_mode=''");
    $pdo->exec(
        "INSERT INTO sales_order_items
         (sales_order_id,line_no,item_name,description,ordered_quantity,amount,tax_code)
         VALUES(1,2,'Legacy blank enum','Legacy blank enum',1,1.00,'')"
    );
    $pdo->exec("SET SESSION sql_mode='NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");

    try {
        $pdo->exec(
            "INSERT INTO sales_order_items
             (sales_order_id,line_no,item_name,description,ordered_quantity,amount,tax_code)
             VALUES(1,2,'Optional tax','Optional tax',1,1.00,NULL)"
        );
        throw new RuntimeException('FAIL: the pre-migration schema unexpectedly accepted a blank tax.');
    } catch (PDOException $error) {
        $state = (string)($error->errorInfo[0] ?? $error->getCode());
        if ($state !== '23000') {
            throw new RuntimeException('FAIL: unexpected SQLSTATE while reproducing the pre-migration tax failure: ' . $state);
        }
        echo "PASS: current enum NOT NULL schema rejects the application’s optional blank tax value\n";
    }

    $migrationPath = __DIR__ . '/../backend/database/migrations/20261026_sales_order_optional_tax.sql';
    $migration = file_get_contents($migrationPath);
    if (!is_string($migration)) {
        throw new RuntimeException('Could not read the optional Sales Order tax migration.');
    }
    $migration = preg_replace('/^\s*--.*$/m', '', $migration);
    foreach (preg_split('/;\s*/', $migration, -1, PREG_SPLIT_NO_EMPTY) as $statement) {
        $pdo->exec(trim($statement));
    }

    $column = $pdo->query("SHOW COLUMNS FROM sales_order_items LIKE 'tax_code'")->fetch();
    sales_order_assert(
        strtolower((string)$column['Type']) === "enum('non','tax')" && $column['Null'] === 'YES',
        'migration makes the existing Non/Tax enum nullable without broadening allowed labels'
    );
    sales_order_assert(
        $pdo->query('SELECT tax_code FROM sales_order_items WHERE line_no=1')->fetchColumn() === 'Tax',
        'migration preserves existing Sales Order tax values'
    );
    sales_order_assert(
        $pdo->query('SELECT HEX(tax_code) FROM sales_order_items WHERE line_no=2')->fetchColumn() === '',
        'migration preserves the existing empty-string enum sentinel without rewriting data'
    );
    $pdo->exec(
        "INSERT INTO sales_order_items
         (sales_order_id,line_no,item_name,description,ordered_quantity,amount,tax_code)
         VALUES(1,3,'Optional NULL','Optional NULL',1,1.00,NULL)"
    );
    $pdo->exec(
        "INSERT INTO sales_order_items
         (sales_order_id,line_no,item_name,description,ordered_quantity,amount,tax_code)
         VALUES(1,4,'Tax enum','Tax enum',1,1.00,'Non')"
    );
    sales_order_assert(
        $pdo->query('SELECT tax_code IS NULL FROM sales_order_items WHERE line_no=3')->fetchColumn() == 1,
        'nullable enum accepts an intentionally blank choice as SQL NULL'
    );
    sales_order_assert(
        $pdo->query('SELECT tax_code FROM sales_order_items WHERE line_no=4')->fetchColumn() === 'Non',
        'nullable enum still accepts the documented Non value'
    );
    $pdo->exec(
        "INSERT INTO sales_order_items
         (sales_order_id,line_no,item_name,description,ordered_quantity,amount,tax_code)
         VALUES(1,5,'Tax enum','Tax enum',1,1.00,'Tax')"
    );
    sales_order_assert(
        $pdo->query('SELECT tax_code FROM sales_order_items WHERE line_no=5')->fetchColumn() === 'Tax',
        'nullable enum still accepts the documented Tax value'
    );
    $pdo->exec(
        "CREATE TABLE sales_order_items_text_legacy (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tax_code VARCHAR(40) NULL
        ) ENGINE=InnoDB"
    );
    $pdo->exec("INSERT INTO sales_order_items_text_legacy(tax_code) VALUES('Non'),('Tax'),(''),(NULL)");
    $pdo->exec(
        "ALTER TABLE sales_order_items_text_legacy
         MODIFY COLUMN tax_code ENUM('Non','Tax') NULL DEFAULT NULL"
    );
    sales_order_assert(
        $pdo->query("SELECT COUNT(*) FROM sales_order_items_text_legacy WHERE tax_code IN ('Non','Tax')")->fetchColumn() == 2,
        'nullable-text upgrade preserves the supported Non and Tax labels'
    );
    sales_order_assert(
        $pdo->query("SELECT COUNT(*) FROM sales_order_items_text_legacy WHERE tax_code=''")->fetchColumn() == 1,
        'nullable-text upgrade retains a historical empty-string value'
    );
    sales_order_assert(
        $pdo->query("SELECT COUNT(*) FROM sales_order_items_text_legacy WHERE tax_code IS NULL")->fetchColumn() == 1,
        'nullable-text upgrade preserves SQL NULL values'
    );

    $pdo->exec("CREATE TABLE withdrawals (id BIGINT UNSIGNED PRIMARY KEY, withdrawal_no VARCHAR(40) NOT NULL)");
    $pdo->exec("CREATE TABLE clients (id BIGINT UNSIGNED PRIMARY KEY, client_name VARCHAR(180) NOT NULL, qb_list_id VARCHAR(100) NULL, active TINYINT NOT NULL)");
    $pdo->exec("CREATE TABLE products (id BIGINT UNSIGNED PRIMARY KEY, product_name VARCHAR(255) NOT NULL, qb_code VARCHAR(100) NULL, qb_list_id VARCHAR(100) NULL, qb_description VARCHAR(255) NULL, item_code VARCHAR(100) NULL, description VARCHAR(255) NULL, u_m VARCHAR(40) NULL, active TINYINT NOT NULL)");
    $pdo->exec("CREATE TABLE sites (id BIGINT UNSIGNED PRIMARY KEY, active TINYINT NOT NULL)");
    $pdo->exec(
        "CREATE TABLE sales_orders (
            id BIGINT UNSIGNED PRIMARY KEY,
            withdrawal_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            qb_customer_list_id VARCHAR(100) NULL,
            so_number VARCHAR(100) NULL,
            sales_order_date DATE NOT NULL,
            po_no VARCHAR(100) NULL,
            memo TEXT NULL,
            updated_by BIGINT UNSIGNED NULL
        )"
    );
    $pdo->exec(
        "CREATE TABLE activity_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id BIGINT UNSIGNED NULL,
            action VARCHAR(80) NOT NULL,
            entity_type VARCHAR(80) NOT NULL,
            entity_id BIGINT UNSIGNED NULL,
            details TEXT NULL,
            ip_address VARCHAR(45) NULL,
            created_at DATETIME NOT NULL
        )"
    );
    $pdo->exec(
        "CREATE TABLE withdrawal_items (
            id BIGINT UNSIGNED PRIMARY KEY,
            withdrawal_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NULL
        )"
    );
    $pdo->exec(
        "CREATE TABLE invoices (
            id BIGINT UNSIGNED PRIMARY KEY,
            withdrawal_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            invoice_number VARCHAR(100) NULL,
            invoice_date DATE NOT NULL,
            po_no VARCHAR(100) NULL,
            memo TEXT NULL,
            status VARCHAR(40) NOT NULL,
            updated_by BIGINT UNSIGNED NULL
        )"
    );
    $pdo->exec(
        "CREATE TABLE invoice_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_id BIGINT UNSIGNED NOT NULL,
            withdrawal_item_id BIGINT UNSIGNED NULL,
            source_type ENUM('WITHDRAWAL','ADDITIONAL') NOT NULL,
            product_id BIGINT UNSIGNED NULL,
            site_id BIGINT UNSIGNED NULL,
            product_name VARCHAR(255) NOT NULL,
            quantity BIGINT UNSIGNED NOT NULL,
            lot_number VARCHAR(100) NULL,
            item_code VARCHAR(100) NULL,
            description VARCHAR(255) NULL,
            u_m VARCHAR(40) NULL,
            price_each DECIMAL(12,2) NULL DEFAULT NULL,
            amount DECIMAL(14,2) NULL DEFAULT NULL,
            price_source ENUM('mfc_entered','quickbooks_pending','quickbooks_verified','explicit_zero','unresolved') NOT NULL DEFAULT 'unresolved',
            tax_code ENUM('Non','Tax') NOT NULL DEFAULT 'Non',
            expiration_date DATE NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL
        ) ENGINE=InnoDB"
    );
    $pdo->exec("INSERT INTO withdrawals(id,withdrawal_no) VALUES(1,'2026-000001')");
    $pdo->exec("INSERT INTO clients(id,client_name,qb_list_id,active) VALUES(1,'Test customer','CUSTOMER-LIST',1)");
    $pdo->exec("INSERT INTO products(id,product_name,qb_code,item_code,qb_list_id,qb_description,u_m,active) VALUES(1,'Test product','SKU-1','SKU-1','ITEM-LIST','Test description','BOX',1)");
    $pdo->exec("INSERT INTO sites(id,active) VALUES(1,1)");
    $pdo->exec("INSERT INTO sales_orders(id,withdrawal_id,customer_id,sales_order_date) VALUES(1,1,1,CURRENT_DATE)");
    $pdo->exec("INSERT INTO withdrawal_items(id,withdrawal_id,product_id) VALUES(1,1,1)");
    $pdo->exec("INSERT INTO invoices(id,withdrawal_id,customer_id,invoice_date,status) VALUES(1,1,1,CURRENT_DATE,'Draft')");

    $payload = [
        '_csrf' => 'sales-order-test-csrf',
        'sales_order_id' => '1',
        'withdrawal_no' => '2026-000001',
        'so_number' => 'SO-QA-1',
        'customer_id' => '1',
        'sales_order_date' => date('Y-m-d'),
        'po_no' => '',
        'memo' => '',
        'items' => [[
            'product_id' => '1',
            'description' => 'Test description',
            'site_id' => '1',
            'ordered_quantity' => '2',
            'lot_number' => '',
            'u_m' => 'CLIENT-CONTROLLED',
            'rate' => '12.50',
            'class_name' => '',
            'line_status' => '',
            'fulfilled_quantity' => '',
            'expiration_date' => '',
            'tax_code' => '',
        ]],
    ];
    $controllerPath = realpath(__DIR__ . '/../backend/controllers/sales_order_save.php');
    if (!is_string($controllerPath)) {
        throw new RuntimeException('Sales Order save controller was not found.');
    }
    sales_order_run_controller($controllerPath, $payload, $database, $previousDatabase);

    $saved = $pdo->query(
        'SELECT u_m,tax_code,site_id,lot_number,expiration_date,amount
         FROM sales_order_items WHERE sales_order_id=1'
    )->fetch();
    sales_order_assert($saved['u_m'] === 'BOX', 'controller saves Product Master U/M instead of the submitted value');
    sales_order_assert($saved['tax_code'] === null, 'controller persists an intentionally blank optional tax value');
    sales_order_assert((int)$saved['site_id'] === 1, 'controller retains the required active Site');
    sales_order_assert($saved['lot_number'] === null && $saved['expiration_date'] === null, 'blank Lot Number and Expiration Date remain optional');
    sales_order_assert((float)$saved['amount'] === 25.0, 'controller recalculates line amount from quantity and rate');
    foreach (['Non', 'Tax'] as $tax) {
        $payload['items'][0]['tax_code'] = $tax;
        sales_order_run_controller($controllerPath, $payload, $database, $previousDatabase);
        $savedTax = $pdo->query('SELECT tax_code FROM sales_order_items WHERE sales_order_id=1')->fetchColumn();
        sales_order_assert($savedTax === $tax, 'Sales Order controller accepts and persists ' . $tax . ' TAX');
    }
    $pdo->exec("UPDATE products SET item_code='CONFLICTING-CODE' WHERE id=1");
    sales_order_run_controller($controllerPath, $payload, $database, $previousDatabase);
    $preservedOrderCode = $pdo->query('SELECT item_code FROM sales_order_items WHERE sales_order_id=1')->fetchColumn();
    sales_order_assert($preservedOrderCode === 'SKU-1', 'Sales Order save rejects conflicting Product Master codes without replacing the saved draft line');
    $pdo->exec("UPDATE products SET item_code='SKU-1' WHERE id=1");

    $invoicePayload = [
        '_csrf' => 'invoice-test-csrf',
        'invoice_id' => '1',
        'withdrawal_no' => '2026-000001',
        'invoice_number' => 'INV-QA-1',
        'customer_id' => '1',
        'invoice_date' => date('Y-m-d'),
        'po_no' => '',
        'memo' => '',
        'items' => [[
            'product_id' => '1',
            'quantity' => '3',
            'price_each' => '12.50',
            'item_code' => 'SKU-1',
            'site_id' => '1',
            'tax_code' => 'Non',
            'expiration_date' => '',
            'lot_number' => '',
            'description' => 'Test description',
            'u_m' => 'CLIENT-CONTROLLED',
            'source_type' => 'WITHDRAWAL',
            'withdrawal_item_id' => '1',
        ]],
    ];
    $invoiceControllerPath = realpath(__DIR__ . '/../backend/controllers/invoice_save.php');
    if (!is_string($invoiceControllerPath)) {
        throw new RuntimeException('Invoice save controller was not found.');
    }
    $pdo->exec("UPDATE products SET item_code='CONFLICTING-CODE' WHERE id=1");
    sales_order_run_controller($invoiceControllerPath, $invoicePayload, $database, $previousDatabase);
    sales_order_assert(
        (int)$pdo->query('SELECT COUNT(*) FROM invoice_items WHERE invoice_id=1')->fetchColumn() === 0
            && $pdo->query('SELECT invoice_number FROM invoices WHERE id=1')->fetchColumn() === null,
        'invoice save rejects conflicting Product Master codes without writing invoice records'
    );
    $pdo->exec("UPDATE products SET item_code='SKU-1' WHERE id=1");
    sales_order_run_controller($invoiceControllerPath, $invoicePayload, $database, $previousDatabase);
    $invoice = $pdo->query('SELECT customer_id,invoice_number,status FROM invoices WHERE id=1')->fetch();
    $invoiceLine = $pdo->query('SELECT u_m,site_id,lot_number,expiration_date,tax_code,quantity,price_each,amount,price_source FROM invoice_items WHERE invoice_id=1')->fetch();
    sales_order_assert($invoice['invoice_number'] === 'INV-QA-1' && $invoice['status'] === 'Draft', 'invoice controller persists an editable draft header');
    sales_order_assert($invoiceLine['u_m'] === 'BOX', 'invoice controller uses Product Master U/M instead of submitted U/M');
    sales_order_assert((int)$invoiceLine['site_id'] === 1 && $invoiceLine['tax_code'] === 'Non', 'invoice controller saves a valid required Site and documented TAX value');
    sales_order_assert($invoiceLine['lot_number'] === null && $invoiceLine['expiration_date'] === null, 'invoice controller accepts blank Lot Number and Expiration Date');
    sales_order_assert((int)$invoiceLine['quantity'] === 3 && (float)$invoiceLine['price_each'] === 12.5 && (float)$invoiceLine['amount'] === 37.5, 'invoice controller persists the expected quantity, rate, and recalculated amount');
    sales_order_assert($invoiceLine['price_source'] === 'mfc_entered', 'invoice controller records explicit MFC-entered price provenance');
    $invoicePayload['items'][0]['id'] = (string)$pdo->query('SELECT id FROM invoice_items WHERE invoice_id=1')->fetchColumn();
    $invoicePayload['items'][0]['price_each'] = '';
    sales_order_run_controller($invoiceControllerPath, $invoicePayload, $database, $previousDatabase);
    $pendingPrice = $pdo->query('SELECT price_each,amount,price_source FROM invoice_items WHERE invoice_id=1')->fetch();
    sales_order_assert($pendingPrice['price_each'] === null && $pendingPrice['amount'] === null
        && $pendingPrice['price_source'] === 'quickbooks_pending',
        'clearing a tracked MFC price stores a pending blank instead of converting it to zero');
    $invoicePayload['items'][0]['id'] = (string)$pdo->query('SELECT id FROM invoice_items WHERE invoice_id=1')->fetchColumn();
    $invoicePayload['items'][0]['price_each'] = '0.00';
    sales_order_run_controller($invoiceControllerPath, $invoicePayload, $database, $previousDatabase);
    $explicitZero = $pdo->query('SELECT price_each,amount,price_source FROM invoice_items WHERE invoice_id=1')->fetch();
    sales_order_assert((float)$explicitZero['price_each'] === 0.0 && (float)$explicitZero['amount'] === 0.0
        && $explicitZero['price_source'] === 'explicit_zero',
        'an explicitly entered zero is preserved as distinct from a missing price');
    $invoicePayload['items'][0]['id'] = (string)$pdo->query('SELECT id FROM invoice_items WHERE invoice_id=1')->fetchColumn();
    $invoicePayload['items'][0]['price_each'] = '12.50';
    sales_order_run_controller($invoiceControllerPath, $invoicePayload, $database, $previousDatabase);
    $invoicePayload['items'][0]['id'] = (string)$pdo->query('SELECT id FROM invoice_items WHERE invoice_id=1')->fetchColumn();
    $pdo->exec("UPDATE invoice_items SET price_source='unresolved' WHERE invoice_id=1");
    $invoicePayload['items'][0]['price_each'] = '';
    sales_order_run_controller($invoiceControllerPath, $invoicePayload, $database, $previousDatabase);
    $historical = $pdo->query('SELECT price_each,amount,price_source FROM invoice_items WHERE invoice_id=1')->fetch();
    sales_order_assert((float)$historical['price_each'] === 12.5 && (float)$historical['amount'] === 37.5
        && $historical['price_source'] === 'unresolved',
        'saving an untouched historical line preserves its original price and unresolved state');
    $invoicePayload['items'][0]['id'] = (string)$pdo->query('SELECT id FROM invoice_items WHERE invoice_id=1')->fetchColumn();
    $invoicePayload['items'][0]['price_each'] = '12.50';
    sales_order_run_controller($invoiceControllerPath, $invoicePayload, $database, $previousDatabase);
    sales_order_assert($pdo->query('SELECT price_source FROM invoice_items WHERE invoice_id=1')->fetchColumn() === 'unresolved',
        'historical prices cannot be reclassified without explicit Admin confirmation');
    $invoicePayload['items'][0]['confirm_historical_price'] = 'yes';
    $currentInvoice = $pdo->query(
        'SELECT i.*,w.withdrawal_no FROM invoices i JOIN withdrawals w ON w.id=i.withdrawal_id WHERE i.id=1'
    )->fetch();
    $currentLines = $pdo->query('SELECT * FROM invoice_items WHERE invoice_id=1 ORDER BY id')->fetchAll();
    $invoicePayload['invoice_revision'] = invoice_draft_revision($currentInvoice, $currentLines);
    sales_order_run_controller($invoiceControllerPath, $invoicePayload, $database, $previousDatabase);
    sales_order_assert($pdo->query('SELECT price_source FROM invoice_items WHERE invoice_id=1')->fetchColumn() === 'mfc_entered',
        'Admin confirmation explicitly adopts a re-entered historical price as MFC-entered');
    $invoicePayload['items'][0]['id'] = (string)$pdo->query('SELECT id FROM invoice_items WHERE invoice_id=1')->fetchColumn();
    $pdo->exec("UPDATE invoice_items SET price_each='0.00',amount='0.00',price_source='unresolved' WHERE invoice_id=1");
    $invoicePayload['items'][0]['price_each'] = '0.00';
    unset($invoicePayload['items'][0]['confirm_historical_price'], $invoicePayload['invoice_revision']);
    sales_order_run_controller($invoiceControllerPath, $invoicePayload, $database, $previousDatabase);
    sales_order_assert($pdo->query('SELECT price_source FROM invoice_items WHERE invoice_id=1')->fetchColumn() === 'unresolved'
        && (float)$pdo->query('SELECT price_each FROM invoice_items WHERE invoice_id=1')->fetchColumn() === 0.0,
        'a historical stored zero is not reclassified without explicit Admin confirmation');
    $invoicePayload['items'][0]['confirm_historical_price'] = 'yes';
    $invoicePayload['invoice_revision'] = str_repeat('0', 64);
    sales_order_run_controller($invoiceControllerPath, $invoicePayload, $database, $previousDatabase);
    sales_order_assert($pdo->query('SELECT price_source FROM invoice_items WHERE invoice_id=1')->fetchColumn() === 'unresolved',
        'a stale invoice revision cannot confirm a historical stored zero');
    $currentInvoice = $pdo->query(
        'SELECT i.*,w.withdrawal_no FROM invoices i JOIN withdrawals w ON w.id=i.withdrawal_id WHERE i.id=1'
    )->fetch();
    $currentLines = $pdo->query('SELECT * FROM invoice_items WHERE invoice_id=1 ORDER BY id')->fetchAll();
    $invoicePayload['invoice_revision'] = invoice_draft_revision($currentInvoice, $currentLines);
    sales_order_run_controller($invoiceControllerPath, $invoicePayload, $database, $previousDatabase);
    $confirmedHistoricalZero = $pdo->query('SELECT price_each,amount,price_source FROM invoice_items WHERE invoice_id=1')->fetch();
    sales_order_assert((float)$confirmedHistoricalZero['price_each'] === 0.0
        && (float)$confirmedHistoricalZero['amount'] === 0.0
        && $confirmedHistoricalZero['price_source'] === 'explicit_zero',
        'Admin confirmation for the current invoice revision adopts the unchanged stored zero');
    $invoicePayload['items'][0]['id'] = (string)$pdo->query('SELECT id FROM invoice_items WHERE invoice_id=1')->fetchColumn();
    $invoicePayload['items'][0]['tax_code'] = '';
    sales_order_run_controller($invoiceControllerPath, $invoicePayload, $database, $previousDatabase);
    $unchangedTax = $pdo->query('SELECT tax_code FROM invoice_items WHERE invoice_id=1')->fetchColumn();
    sales_order_assert($unchangedTax === 'Non', 'missing required invoice TAX does not replace the saved draft');
    $invoicePayload['items'][0]['tax_code'] = 'Non';
    $invoicePayload['items'][0]['site_id'] = '999';
    sales_order_run_controller($invoiceControllerPath, $invoicePayload, $database, $previousDatabase);
    $unchangedLine = $pdo->query('SELECT site_id,quantity,amount FROM invoice_items WHERE invoice_id=1')->fetch();
    sales_order_assert(
        (int)$unchangedLine['site_id'] === 1 && (int)$unchangedLine['quantity'] === 3 && (float)$unchangedLine['amount'] === 0.0,
        'invalid inactive/missing invoice Site rolls back without replacing the saved draft'
    );

    echo "All isolated Sales Order workflow checks passed.\n";
} finally {
    if ($previousDatabase === false) {
        putenv('WQ_DB_NAME');
    } else {
        putenv('WQ_DB_NAME=' . $previousDatabase);
    }
    $pdo = null;
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
