
<?php

//declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_BASE_URL . '/frontend/withdrawals.php');
    exit;
}

verify_csrf();

$pdo = db();

$no = '';

/**
 * Read a scalar POST value safely as a trimmed string.
 */
$readString = static function (array $source, string $key): string {
    $value = $source[$key] ?? '';

    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        throw new RuntimeException('Invalid form submission. Please review the form and try again.');
    }

    return trim((string) $value);
};

/**
 * Validate a calendar date in YYYY-MM-DD format.
 */
$validateDate = static function (string $date): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
        return false;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();

    if ($parsed === false) {
        return false;
    }

    if (
        $errors !== false &&
        (
            $errors['warning_count'] > 0 ||
            $errors['error_count'] > 0
        )
    ) {
        return false;
    }

    return $parsed->format('Y-m-d') === $date;
};

/**
 * Validate a time and return HH:MM:SS for MySQL.
 * Empty input becomes NULL.
 */
$normalizeTime = static function (
    string $value,
    string $fieldLabel
): ?string {
    if ($value === '') {
        return null;
    }

    if (
        !preg_match(
            '/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/D',
            $value,
            $matches
        )
    ) {
        throw new RuntimeException(
            $fieldLabel . ' must be a valid time.'
        );
    }

    $seconds = $matches[3] ?? '00';

    return $matches[1] . ':' . $matches[2] . ':' . $seconds;
};

try {
    /*
    |--------------------------------------------------------------------------
    | Read and validate withdrawal header
    |--------------------------------------------------------------------------
    */

    $no = $readString($_POST, 'withdrawal_no');

    $withdrawalIdRaw = $readString($_POST, 'withdrawal_id');

    $clientIdRaw = $readString($_POST, 'client_id');
    $clientName = $readString($_POST, 'client_name');
    $date = $readString($_POST, 'form_date');

    $receivedRaw = $readString($_POST, 'time_received');
    $pickupRaw = $readString($_POST, 'pick_up_time');

    $po = $readString($_POST, 'po_no');
    $note = $readString($_POST, 'internal_note');

    if ($withdrawalIdRaw === '') {
        $withdrawalId = null;
    } else {
        $withdrawalId = filter_var(
            $withdrawalIdRaw,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($withdrawalId === false) {
            throw new RuntimeException(
                'Invalid withdrawal record. Please reopen the withdrawal and try again.'
            );
        }
    }

    if ($no === '' || mb_strlen($no) > 40) {
        throw new RuntimeException('Withdrawal number is required and must be 40 characters or fewer.');
    }
    if ($withdrawalId === null && !preg_match('/^\d{4}-\d{6,}$/D', $no)) {
        throw new RuntimeException('Enter a withdrawal number in the YYYY-###### format.');
    }

    $clientId = filter_var(
        $clientIdRaw,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (
        $clientId === false ||
        !$validateDate($date)
    ) {
        throw new RuntimeException(
            'Withdrawal number, a valid client, and a valid form date are required.'
        );
    }

    $received = $normalizeTime(
        $receivedRaw,
        'Time Received'
    );

    $pickup = $normalizeTime(
        $pickupRaw,
        'Pick Up Time'
    );

    /*
    |--------------------------------------------------------------------------
    | Validate active client
    |--------------------------------------------------------------------------
    */

    $clientStmt = $pdo->prepare(
        'SELECT id, client_name
         FROM clients
         WHERE id = ?
           AND active = 1
         LIMIT 1'
    );

    $clientStmt->execute([$clientId]);

    $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
    if (!$client || $clientName === '' || !hash_equals((string)$client['client_name'], $clientName)) {
        throw new RuntimeException(
            'Choose an active client from the suggestions. The client name and selection must match.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Validate submitted item rows
    |--------------------------------------------------------------------------
    */

    $postedItems = $_POST['items'] ?? [];

    if (!is_array($postedItems)) {
        throw new RuntimeException(
            'Invalid withdrawal items submission.'
        );
    }

    if (count($postedItems) > 30) {
        throw new RuntimeException(
            'A withdrawal may contain no more than 30 item lines.'
        );
    }

    $valid = [];

    /*
     * Prepare this statement once and reuse it for every item.
     */
    $productStmt = $pdo->prepare(
        'SELECT id, product_name, ht_code, ht_description, active
         FROM products
         WHERE id = ?
         LIMIT 1'
    );

    foreach ($postedItems as $item) {
        if (!is_array($item)) {
            throw new RuntimeException(
                'Invalid item row. Please review the withdrawal items.'
            );
        }

        $searchLabel = $readString($item, 'product_search');
        $productIdRaw = $readString($item, 'product_id');
        $qty = $readString($item, 'quantity');
        $remarks = $readString($item, 'remarks');

        /*
         * Ignore a completely blank row.
         */
        if (
            $searchLabel === '' &&
            $productIdRaw === '' &&
            $qty === '' &&
            $remarks === ''
        ) {
            continue;
        }

        /*
         * Quantity must contain digits only.
         * This rejects decimals, negative numbers and scientific notation.
         */
        if (
            $searchLabel === '' ||
            !filter_var($productIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ||
            $qty === '' ||
            !preg_match('/^\d+$/D', $qty)
        ) {
            throw new RuntimeException(
                'Every item row must have a product and a whole-number quantity greater than zero.'
            );
        }

        /*
         * Remove leading zeros before validating the integer.
         */
        $normalizedQty = ltrim($qty, '0');

        if ($normalizedQty === '') {
            throw new RuntimeException(
                'Quantity must be a whole number greater than zero.'
            );
        }

        $quantity = filter_var(
            $normalizedQty,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($quantity === false) {
            throw new RuntimeException(
                'Enter a valid whole-number quantity greater than zero.'
            );
        }

        /*
         * Confirm the visible name/description label belongs to this active product.
         */
        $productId = (int) $productIdRaw;
        $productStmt->execute([$productId]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);
        if (!$product || (int) $product['active'] !== 1) {
            throw new RuntimeException(
                'The selected product is no longer active. Choose another product.'
            );
        }

        $expectedLabel = withdrawal_product_label(
            (string)$product['product_name'],
            $product['ht_description'] ?? null
        );
        if (trim($searchLabel) !== $expectedLabel) {
            throw new RuntimeException(
                'Choose a product from the suggestions so its name and description match the product catalog.'
            );
        }

        $valid[] = [
            'product' => $product,
            'quantity' => $quantity,
            'remarks' => $remarks
        ];
    }

    if (count($valid) === 0) {
        throw new RuntimeException(
            'Enter at least one product before saving.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Save withdrawal inside a transaction
    |--------------------------------------------------------------------------
    */

    $pdo->beginTransaction();

    $userId = filter_var(
        $_SESSION['user']['id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($userId === false) {
        throw new RuntimeException(
            'Your user session is invalid. Please sign in again.'
        );
    }

    $id = null;
    $action = '';

    if ($withdrawalId !== null) {
        /*
         * identify the record by its database ID, not its posted number alone.
         */
        $find = $pdo->prepare(
            'SELECT id, withdrawal_no, status
             FROM withdrawals
             WHERE id = ?
             FOR UPDATE'
        );

        $find->execute([$withdrawalId]);

        $old = $find->fetch(PDO::FETCH_ASSOC);

        if (!$old) {
            throw new RuntimeException(
                'The withdrawal record no longer exists. Return to the register and reopen it.'
            );
        }

        /*
         * Prevent mismatched ID and withdrawal number submissions.
         */
        if ((string) $old['withdrawal_no'] !== $no) {
            throw new RuntimeException(
                'The withdrawal record does not match this form. Reopen the withdrawal before saving.'
            );
        }

        if ($old['status'] === 'Invoiced') {
            throw new RuntimeException(
                'This withdrawal is already invoiced and cannot be edited from the normal withdrawal form.'
            );
        }

        $id = (int) $old['id'];

        $update = $pdo->prepare(
            "UPDATE withdrawals
             SET time_received = ?,
                 pick_up_time = ?,
                 client_id = ?,
                 form_date = ?,
                 po_no = ?,
                 internal_note = ?,
                 updated_by = ?,
                 status = IF(
                     status = 'Draft',
                     'Ready for Invoicing',
                     status
                 )
             WHERE id = ?"
        );

        $update->execute([
            $received,
            $pickup,
            $clientId,
            $date,
            $po !== '' ? $po : null,
            $note !== '' ? $note : null,
            $userId,
            $id
        ]);

        /*
         * Preserve the existing replacement strategy for item rows.
         * This runs in the same transaction as the header update.
         */
        $deleteItems = $pdo->prepare(
            'DELETE FROM withdrawal_items
             WHERE withdrawal_id = ?'
        );

        $deleteItems->execute([$id]);

        $action = 'UPDATE';
    } else {
        /*
         * New withdrawal:
         */
        $find = $pdo->prepare(
            'SELECT id
             FROM withdrawals
             WHERE withdrawal_no = ?
             FOR UPDATE'
        );

        $find->execute([$no]);

        if ($find->fetch(PDO::FETCH_ASSOC)) {
            throw new RuntimeException(
                'This withdrawal number already exists. Reopen the existing withdrawal instead of creating another one.'
            );
        }

        $insert = $pdo->prepare(
            'INSERT INTO withdrawals (
                withdrawal_no,
                time_received,
                pick_up_time,
                client_id,
                form_date,
                po_no,
                internal_note,
                status,
                created_by,
                updated_by
             ) VALUES (
                ?, ?, ?, ?, ?, ?, ?,
                "Ready for Invoicing",
                ?, ?
             )'
        );

        $insert->execute([
            $no,
            $received,
            $pickup,
            $clientId,
            $date,
            $po !== '' ? $po : null,
            $note !== '' ? $note : null,
            $userId,
            $userId
        ]);

        $id = (int) $pdo->lastInsertId();

        $action = 'CREATE';
    }

    /*
    |--------------------------------------------------------------------------
    | Insert validated item rows
    |--------------------------------------------------------------------------
    */

    $insertItem = $pdo->prepare(
        'INSERT INTO withdrawal_items (
            withdrawal_id,
            line_no,
            product_id,
            product_name,
            ht_code,
            ht_description,
            quantity,
            remarks
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($valid as $index => $item) {
        $insertItem->execute([
            $id,
            $index + 1,
            $item['product']['id'],
            $item['product']['product_name'],
            $item['product']['ht_code'],
            $item['product']['ht_description'],
            $item['quantity'],
            $item['remarks'] !== '' ? $item['remarks'] : null
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Activity log and commit
    |--------------------------------------------------------------------------
    */

    log_activity(
        $pdo,
        $action,
        'withdrawal',
        $id,
        'Saved withdrawal ' . $no .
            ' with ' . count($valid) . ' line(s)'
    );

    $pdo->commit();

    flash(
        'success',
        'Withdrawal ' . $no . ' was saved successfully.'
    );

    header(
        'Location: ' . APP_BASE_URL .
            '/frontend/withdrawal_form.php?withdrawal_no=' .
            rawurlencode($no)
    );

    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($e instanceof PDOException) {
        /*
         * Log the underlying database error for the developer.
         * Do not expose SQL or database details to the user.
         */
        error_log(
            'Withdrawal save failed: ' . $e->getMessage()
        );

        $mysqlErrorCode = (int) ($e->errorInfo[1] ?? 0);

        if ($mysqlErrorCode === 1062) {
            flash(
                'danger',
                'A duplicate value was detected. Check the withdrawal number and try again.'
            );
        } else {
            flash(
                'danger',
                'The withdrawal could not be saved. Check the information and try again.'
            );
        }
    } else {
        flash('danger', $e->getMessage());
    }

    $redirect = APP_BASE_URL .
        '/frontend/withdrawal_form.php';

    if ($no !== '') {
        $redirect .= '?withdrawal_no=' . rawurlencode($no);
    }

    header('Location: ' . $redirect);
    exit;
}
