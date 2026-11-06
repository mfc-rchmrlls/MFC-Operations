<?php

declare(strict_types=1);
function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function current_page(): string
{
    return basename($_SERVER['PHP_SELF'] ?? 'dashboard.php');
}
function nav_active(string $page): string
{
    return current_page() === $page ? 'active' : '';
}
function status_class(string $status): string
{
    $s = strtolower($status);
    if (str_contains($s, 'invoiced') || str_contains($s, 'completed')) return 'status-success';
    if (str_contains($s, 'error') || str_contains($s, 'uncertain')) return 'status-danger';
    if (str_contains($s, 'processing') || str_contains($s, 'queued')) return 'status-info';
    if (str_contains($s, 'ready')) return 'status-teal';
    return 'status-muted';
}
function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf'];
}
function csrf_token_matches(mixed $expected, mixed $provided): bool
{
    return is_string($expected)
        && $expected !== ''
        && is_string($provided)
        && hash_equals($expected, $provided);
}
function verify_csrf(): void
{
    if (!csrf_token_matches($_SESSION['_csrf'] ?? null, $_POST['_csrf'] ?? null)) {
        http_response_code(419);
        exit('Your session token expired. Refresh the page and try again.');
    }
}
function flash(string $type, string $message): void
{
    $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}
function take_flash(): ?array
{
    $f = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);
    return $f;
}
function log_activity(PDO $pdo, string $action, string $entity, ?int $entityId, string $details = ''): void
{
    $stmt = $pdo->prepare('INSERT INTO activity_logs(employee_id,action,entity_type,entity_id,details,ip_address,created_at) VALUES(?,?,?,?,?,?,NOW())');
    $stmt->execute([$_SESSION['user']['id'] ?? null, $action, $entity, $entityId, $details, substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45)]);
}
function next_withdrawal_no(PDO $pdo): string
{
    $prefix = date('Y-');
    $stmt = $pdo->prepare('SELECT withdrawal_no FROM withdrawals WHERE withdrawal_no LIKE ?');
    $stmt->execute([$prefix . '%']);
    $highest = 101000;
    while (($number = $stmt->fetchColumn()) !== false) {
        if (preg_match('/^\d{4}-(\d+)$/D', (string)$number, $matches)) {
            $sequence = (int)$matches[1];
            if ($sequence > $highest) $highest = $sequence;
        }
    }
    $seq = $highest + 1;
    return $prefix . str_pad((string)$seq, 6, '0', STR_PAD_LEFT);
}
function get_sites(PDO $pdo): array
{
    return $pdo->query('SELECT id,site_name,active FROM sites WHERE active=1 ORDER BY site_name')->fetchAll();
}
function get_products(PDO $pdo): array
{
    return $pdo->query('SELECT id,product_name,ht_code,qb_code,ht_description,qb_description,item_code,description,u_m,default_price,kg_per_box,qb_list_id,active FROM products WHERE active=1 ORDER BY product_name')->fetchAll();
}

function product_codes_match(mixed $itemCode, mixed $qbCode): bool
{
    if ($itemCode === null || $qbCode === null) {
        return $itemCode === $qbCode;
    }
    if (!is_string($itemCode) || !is_string($qbCode)) {
        return false;
    }

    $itemCode = trim($itemCode);
    $qbCode = trim($qbCode);
    if ($itemCode === '' || $qbCode === '') {
        return $itemCode === $qbCode;
    }

    return strcasecmp($itemCode, $qbCode) === 0;
}

function invoice_draft_revision(array $invoice, array $lines): string
{
    $headerKeys = [
        'id',
        'withdrawal_id',
        'customer_id',
        'invoice_number',
        'invoice_date',
        'po_no',
        'memo',
        'status',
    ];
    $lineKeys = [
        'id',
        'withdrawal_item_id',
        'source_type',
        'product_id',
        'site_id',
        'product_name',
        'quantity',
        'lot_number',
        'item_code',
        'description',
        'u_m',
        'price_each',
        'amount',
        'price_source',
        'tax_code',
        'expiration_date',
    ];
    $normalize = static function (array $record, array $keys): array {
        $normalized = [];
        foreach ($keys as $key) {
            $normalized[$key] = isset($record[$key]) ? (string)$record[$key] : null;
        }
        return $normalized;
    };
    $normalizedLines = array_map(
        static fn(array $line): array => $normalize($line, $lineKeys),
        array_values($lines)
    );

    return hash('sha256', json_encode(
        [
            'invoice' => $normalize($invoice, $headerKeys),
            'lines' => $normalizedLines,
        ],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ));
}

function withdrawal_product_label(string $name, ?string $description): string
{
    $name = trim($name);
    $label = $name;
    $description = trim((string)$description);
    if ($description !== '' && strcasecmp($name, $description) !== 0) $label .= ' - ' . $description;
    return $label;
}
