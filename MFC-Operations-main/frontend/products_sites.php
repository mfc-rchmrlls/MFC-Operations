<?php
$page_title = 'Products & Sites';
require_once __DIR__ . '/../backend/config/app.php';
require_login();
$pdo = db();
$manager = can_manage_products_sites($pdo);
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_products_sites_manager();
    verify_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (in_array($action, ['save_product', 'delete_product'], true)) {
            if ($action === 'delete_product') {
                if (!$id) throw new RuntimeException('Invalid product.');
                $q = $pdo->prepare('SELECT (SELECT COUNT(*) FROM withdrawal_items WHERE product_id=p.id)+(SELECT COUNT(*) FROM invoice_items WHERE product_id=p.id) FROM products p WHERE id=?');
                $q->execute([$id]);
                $n = $q->fetchColumn();
                if ($n === false) throw new RuntimeException('Product not found.');
                if ((int)$n) throw new RuntimeException('This product has historical transactions and cannot be deleted. Deactivate it instead.');
                $pdo->prepare('DELETE FROM products WHERE id=?')->execute([$id]);
                log_activity($pdo, 'DELETE', 'product', $id, 'Permanently deleted unused product');
            } else {
                $name = trim((string)($_POST['product_name'] ?? ''));
                $ht = trim((string)($_POST['ht_code'] ?? ''));
                $qb = trim((string)($_POST['qb_code'] ?? ''));
                $hd = trim((string)($_POST['ht_description'] ?? ''));
                $qd = trim((string)($_POST['qb_description'] ?? ''));
                $um = trim((string)($_POST['u_m'] ?? ''));
                $price = trim((string)($_POST['default_price'] ?? ''));
                if ($price !== '') {
                    $validPrice = preg_match('/^\d+(?:\.\d{1,2})?$/D', $price) || preg_match('/^\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?$/D', $price);
                    if (!$validPrice) throw new RuntimeException('Price must be a non-negative amount with valid comma grouping and up to two decimal places.');
                    $price = str_replace(',', '', $price);
                    if (strlen(explode('.', $price, 2)[0]) > 10) throw new RuntimeException('Price exceeds the supported amount.');
                }
                $kg = trim((string)($_POST['kg_per_box'] ?? ''));
                $active = ($_POST['active'] ?? '1') === '1' ? 1 : 0;
                if (!$id && (!$name || !$ht || !$qb || !$hd || !$qd)) throw new RuntimeException('New products need a name, both codes, and both descriptions.');
                if ($name === '' || mb_strlen($name) > 255 || mb_strlen($ht) > 100 || mb_strlen($qb) > 100 || mb_strlen($hd) > 255 || mb_strlen($qd) > 255 || mb_strlen($um) > 40) throw new RuntimeException('A product field is missing or too long.');
                if ($active && (!$ht || !$qb || !$hd || !$qd)) throw new RuntimeException('Active products require both codes and descriptions.');
                if (($price !== '' && (!is_numeric($price) || (float)$price < 0)) || ($kg !== '' && (!is_numeric($kg) || (float)$kg < 0))) throw new RuntimeException('Price and KG/Box must be non-negative numbers.');
                $q = $pdo->prepare(
                    'SELECT id FROM products
                     WHERE (LOWER(TRIM(product_name))=LOWER(TRIM(?))
                         OR LOWER(TRIM(ht_code))=LOWER(TRIM(?))
                         OR LOWER(TRIM(qb_code))=LOWER(TRIM(?))
                         OR LOWER(TRIM(item_code))=LOWER(TRIM(?)))
                       AND id<>? LIMIT 1'
                );
                $q->execute([$name, $ht, $qb, $qb, $id ?: 0]);
                if ($q->fetchColumn()) throw new RuntimeException('That product name or code is already in use.');
                if ($id) {
                    $q = $pdo->prepare('SELECT active,item_code,qb_code FROM products WHERE id=?');
                    $q->execute([$id]);
                    $previous = $q->fetch();
                    if (!$previous) throw new RuntimeException('Product not found.');
                    if (!product_codes_match($previous['item_code'], $previous['qb_code'])
                        && ($_POST['confirm_code_sync'] ?? null) !== '1') {
                        throw new RuntimeException('This product has conflicting Item Code and QB Code values. Review both values, enter the confirmed shared code, and explicitly confirm synchronization before saving. No product data was changed.');
                    }
                    $activeBeforeUpdate = $previous['active'];
                    $pdo->prepare('UPDATE products SET product_name=?,ht_code=?,qb_code=?,ht_description=?,qb_description=?,item_code=?,description=?,u_m=?,default_price=?,kg_per_box=?,active=? WHERE id=?')->execute([$name, $ht ?: null, $qb ?: null, $hd ?: null, $qd ?: null, $qb ?: null, $qd ?: null, $um ?: null, $price === '' ? null : $price, $kg === '' ? null : $kg, $active, $id]);
                    $actionLog = (int)$activeBeforeUpdate === $active ? 'UPDATE' : ($active ? 'ACTIVATE' : 'DEACTIVATE');
                    log_activity($pdo, $actionLog, 'product', $id, 'Updated product ' . $name);
                } else {
                    $pdo->prepare('INSERT INTO products(product_name,ht_code,qb_code,ht_description,qb_description,item_code,description,u_m,default_price,kg_per_box,active) VALUES(?,?,?,?,?,?,?,?,?,?,1)')->execute([$name, $ht, $qb, $hd, $qd, $qb, $qd, $um ?: null, $price === '' ? null : $price, $kg === '' ? null : $kg]);
                    $id = (int)$pdo->lastInsertId();
                    log_activity($pdo, 'CREATE', 'product', $id, 'Created product ' . $name);
                }
            }
        } elseif (in_array($action, ['save_site', 'delete_site'], true)) {
            if ($action === 'delete_site') {
                if (!$id) throw new RuntimeException('Invalid site.');
                $q = $pdo->prepare('SELECT (SELECT COUNT(*) FROM invoice_items WHERE site_id=s.id) FROM sites s WHERE id=?');
                $q->execute([$id]);
                $n = $q->fetchColumn();
                if ($n === false) throw new RuntimeException('Site not found.');
                if ((int)$n) throw new RuntimeException('This site is used on invoices and cannot be deleted. Deactivate it instead.');
                $pdo->prepare('DELETE FROM sites WHERE id=?')->execute([$id]);
                log_activity($pdo, 'DELETE', 'site', $id, 'Permanently deleted unused site');
            } else {
                $name = trim((string)($_POST['site_name'] ?? ''));
                $active = ($_POST['active'] ?? '1') === '1' ? 1 : 0;
                if ($name === '' || mb_strlen($name) > 120) throw new RuntimeException('Enter a site name of 120 characters or fewer.');
                $q = $pdo->prepare('SELECT id FROM sites WHERE LOWER(TRIM(site_name))=LOWER(TRIM(?)) AND id<>?');
                $q->execute([$name, $id ?: 0]);
                if ($q->fetchColumn()) throw new RuntimeException('That site name is already in use.');
                if ($id) {
                    $q = $pdo->prepare('SELECT active FROM sites WHERE id=?');
                    $q->execute([$id]);
                    $previous = $q->fetchColumn();
                    if ($previous === false) throw new RuntimeException('Site not found.');
                    $pdo->prepare('UPDATE sites SET site_name=?,active=? WHERE id=?')->execute([$name, $active, $id]);
                    $actionLog = (int)$previous === $active ? 'UPDATE' : ($active ? 'ACTIVATE' : 'DEACTIVATE');
                    log_activity($pdo, $actionLog, 'site', $id, 'Updated site ' . $name);
                } else {
                    $pdo->prepare('INSERT INTO sites(site_name,active) VALUES(?,1)')->execute([$name]);
                    $id = (int)$pdo->lastInsertId();
                    log_activity($pdo, 'CREATE', 'site', $id, 'Created site ' . $name);
                }
            }
        } else throw new RuntimeException('Unsupported action.');
        flash('success', 'Changes saved.');
        header('Location: products_sites.php');
        exit;
    } catch (Throwable $e) {
        $err = $e instanceof PDOException ? 'Could not save. Check for duplicate values or database migration status.' : $e->getMessage();
    }
}
$products = $manager ? $pdo->query('SELECT p.*,(SELECT COUNT(*) FROM withdrawal_items wi WHERE wi.product_id=p.id) withdrawal_refs,(SELECT COUNT(*) FROM invoice_items ii WHERE ii.product_id=p.id) invoice_refs FROM products p ORDER BY p.product_name')->fetchAll() : get_products($pdo);
$sites = $manager ? $pdo->query('SELECT s.*,(SELECT COUNT(*) FROM invoice_items ii WHERE ii.site_id=s.id) invoice_refs FROM sites s ORDER BY s.site_name')->fetchAll() : get_sites($pdo);
$allProductCodeConflicts = $manager
    ? array_filter($products, static fn($p) => !product_codes_match($p['item_code'] ?? null, $p['qb_code'] ?? null))
    : [];
$allProductCount = count($products);
$allSiteCount = count($sites);
$search = trim((string)($_GET['q'] ?? ''));
$productStatus = $manager ? (string)($_GET['status'] ?? 'all') : 'active';
if (!in_array($productStatus, ['all', 'active', 'inactive'], true)) $productStatus = 'all';
if ($productStatus === 'active') $products = array_values(array_filter($products, fn($p) => (int)$p['active'] === 1));
elseif ($productStatus === 'inactive') $products = array_values(array_filter($products, fn($p) => (int)$p['active'] !== 1));
if ($search !== '') $products = array_values(array_filter($products, fn($p) => stripos(implode(' ', [$p['product_name'], $p['ht_code'], $p['item_code'], $p['qb_code'], $p['ht_description'], $p['qb_description']]), $search) !== false));
$sortKey = (string)($_GET['sort'] ?? 'product_name');
$sortKey = in_array($sortKey, ['product_name', 'ht_code', 'qb_code', 'ht_description', 'qb_description'], true) ? $sortKey : 'product_name';
usort($products, fn($a, $b) => strcasecmp((string)($a[$sortKey] ?? ''), (string)($b[$sortKey] ?? '')));
$siteSearch = trim((string)($_GET['site_q'] ?? ''));
$siteStatus = $manager ? (string)($_GET['site_status'] ?? 'all') : 'active';
if (!in_array($siteStatus, ['all', 'active', 'inactive'], true)) $siteStatus = 'all';
if ($siteStatus === 'active') $sites = array_values(array_filter($sites, fn($s) => (int)$s['active'] === 1));
elseif ($siteStatus === 'inactive') $sites = array_values(array_filter($sites, fn($s) => (int)$s['active'] !== 1));
if ($siteSearch !== '') $sites = array_values(array_filter($sites, fn($s) => stripos($s['site_name'], $siteSearch) !== false));
$postedProduct = $err !== '' && in_array($_POST['action'] ?? '', ['save_product'], true) ? $_POST : [];
$postedSite = $err !== '' && in_array($_POST['action'] ?? '', ['save_site'], true) ? $_POST : [];
$postedProductCodeConflict = false;
if ($postedProduct && filter_var($postedProduct['id'] ?? null, FILTER_VALIDATE_INT)) {
    foreach ($products as $product) {
        if ((int)$product['id'] === (int)$postedProduct['id']) {
            $postedProductCodeConflict = !product_codes_match($product['item_code'] ?? null, $product['qb_code'] ?? null);
            break;
        }
    }
}
require __DIR__ . '/includes/layout_start.php'; ?>
<div class="page-intro catalog-intro">
    <div>
        <!-- <h2>Products &amp; Sites</h2> -->
        <p>Manage product master data and site records.</p>
    </div>
    <?php if ($manager): ?><button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#productModal" data-product-create><i class="bi bi-plus-lg" aria-hidden="true"></i> Add Product</button><?php endif; ?>
</div>
<?php if ($err): ?><div class="alert alert-danger" role="alert"><?= e($err) ?></div><?php endif; ?>
<?php
$reviewProducts = array_filter($products, static fn($p) => trim((string)($p['ht_code'] ?? '')) === '' || trim((string)($p['qb_code'] ?? '')) === '' || trim((string)($p['ht_description'] ?? '')) === '' || trim((string)($p['qb_description'] ?? '')) === '');
if ($manager && $reviewProducts): ?><div class="alert alert-warning" role="status"><strong>Mapping review:</strong> <?= count($reviewProducts) ?> displayed product(s) need code or description mappings. Complete HT Codes from the authoritative HT list before using these products in new withdrawals.</div><?php endif; ?>
<?php if ($manager && $allProductCodeConflicts): ?><div class="alert alert-danger" role="alert"><strong>Product code conflicts:</strong> <?= count($allProductCodeConflicts) ?> product(s) have different legacy Item Code and QB Code values. Search either code, review each product, and explicitly confirm the shared code before saving changes.</div><?php endif; ?>

<section class="panel catalog-panel mb-4" aria-labelledby="productMasterTitle">
    <div class="catalog-heading">
        <div>
            <h3 id="productMasterTitle">Product Master</h3>
            <p><?= count($products) ?> shown<?php if ($manager): ?> of <?= $allProductCount ?> products<?php else: ?> active products<?php endif; ?></p>
        </div>
    </div>
    <form method="get" class="catalog-toolbar">
        <div class="catalog-search"><i class="bi bi-search" aria-hidden="true"></i><label class="visually-hidden" for="productSearch">Search products</label><input id="productSearch" class="form-control" name="q" value="<?= e($search) ?>" placeholder="Search product name, HT code, Item Code / QB Code, or description"><button class="btn btn-outline-primary" type="submit">Search</button></div>
        <?php if ($manager): ?><label class="visually-hidden" for="productStatus">Product status</label><select id="productStatus" class="form-select catalog-filter" name="status">
                <option value="all" <?= $productStatus === 'all' ? 'selected' : '' ?>>All statuses</option>
                <option value="active" <?= $productStatus === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $productStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select><?php endif; ?>
        <label class="visually-hidden" for="productSort">Sort products</label><select id="productSort" class="form-select catalog-filter" name="sort"><?php foreach (['product_name' => 'Product Name', 'ht_code' => 'HT Code', 'qb_code' => 'Item Code / QB Code', 'ht_description' => 'HT Description', 'qb_description' => 'QB Description'] as $key => $label): ?><option value="<?= e($key) ?>" <?= $sortKey === $key ? 'selected' : '' ?>>Sort: <?= e($label) ?></option><?php endforeach; ?></select>
        <a class="btn btn-light catalog-clear" href="products_sites.php" aria-label="Clear product search and filters">Clear</a>
    </form>
    <div class="table-responsive catalog-table-wrap" tabindex="0" aria-label="Product master table">
        <table class="table catalog-table">
            <thead>
                <tr><?php foreach (['Product Name', 'HT Code', 'Item Code / QB Code', 'HT Description', 'QB Description', 'U/M', 'Price (PHP)', 'KG/Box'] as $heading): ?><th scope="col"><?= e($heading) ?></th><?php endforeach; ?><?php if ($manager): ?><th scope="col">Status</th>
                        <th scope="col">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $p):
                    $inactive = (int)$p['active'] !== 1;
                    $missing = trim((string)($p['ht_code'] ?? '')) === '' || trim((string)($p['qb_code'] ?? '')) === '' || trim((string)($p['ht_description'] ?? '')) === '' || trim((string)($p['qb_description'] ?? '')) === '';
                    $deleteBlocked = ((int)($p['withdrawal_refs'] ?? 0) + (int)($p['invoice_refs'] ?? 0)) > 0;
                ?>
                    <tr>
                        <td class="catalog-name"><?= e($p['product_name']) ?></td>
                        <td class="catalog-code"><?= e($p['ht_code'] ?? '') ?><?php if (empty($p['ht_code'])): ?><span class="catalog-missing">Missing</span><?php endif; ?></td>
                        <td class="catalog-code"><?= e($p['qb_code'] ?? '') ?><?php if (empty($p['qb_code'])): ?><span class="catalog-missing">Missing</span><?php endif; ?></td>
                        <td><span class="catalog-description" title="<?= e($p['ht_description'] ?? '') ?>" aria-label="HT Description: <?= e($p['ht_description'] ?? 'Not set') ?>"><?= e($p['ht_description'] ?? '—') ?></span></td>
                        <td><span class="catalog-description" title="<?= e($p['qb_description'] ?? '') ?>" aria-label="QB Description: <?= e($p['qb_description'] ?? 'Not set') ?>"><?= e($p['qb_description'] ?? '—') ?></span></td>
                        <td><?= e($p['u_m'] ?? '—') ?></td>
                        <td class="catalog-number"><?= $p['default_price'] !== null ? '₱' . number_format((float)$p['default_price'], 2, '.', ',') : '—' ?></td>
                        <td class="catalog-number"><?= $p['kg_per_box'] !== null ? number_format((float)$p['kg_per_box'], 3) : '—' ?></td>
                        <?php if ($manager): ?>
                            <td><span class="status-pill <?= $inactive ? 'status-muted' : 'status-success' ?>"><?= $inactive ? 'Inactive' : 'Active' ?></span><?php if ($missing): ?><span class="catalog-missing-note">Mapping incomplete</span><?php endif; ?></td>
                            <td>
                                <div class="catalog-actions">
                                    <button class="btn btn-light btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#productModal" data-product-edit data-id="<?= (int)$p['id'] ?>" data-name="<?= e($p['product_name']) ?>" data-ht="<?= e($p['ht_code'] ?? '') ?>" data-qb="<?= e($p['qb_code'] ?? '') ?>" data-item-code="<?= e($p['item_code'] ?? '') ?>" data-code-conflict="<?= product_codes_match($p['item_code'] ?? null, $p['qb_code'] ?? null) ? '0' : '1' ?>" data-ht-description="<?= e($p['ht_description'] ?? '') ?>" data-qb-description="<?= e($p['qb_description'] ?? '') ?>" data-um="<?= e($p['u_m'] ?? '') ?>" data-price="<?= e($p['default_price'] ?? '') ?>" data-kg="<?= e($p['kg_per_box'] ?? '') ?>" data-active="<?= (int)$p['active'] ?>"><i class="bi bi-pencil" aria-hidden="true"></i><span>Edit</span></button>
                                    <?php if ($deleteBlocked): ?><span class="catalog-delete-note" title="Referenced by <?= $p['withdrawal_refs'] ?> withdrawal line(s) and <?= $p['invoice_refs'] ?> invoice line(s)">In use · deactivate to retain history</span><?php else: ?><form method="post" data-confirm-submit="This permanently deletes <?= e($p['product_name']) ?>. This action cannot be undone." data-confirm-title="Delete product?" data-confirm-button="Delete product"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_product"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-light btn-sm catalog-delete" type="submit" aria-label="Delete <?= e($p['product_name']) ?>"><i class="bi bi-trash3" aria-hidden="true"></i></button></form><?php endif; ?>
                                </div>
                            </td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$products): ?><tr>
                        <td colspan="<?= $manager ? '10' : '8' ?>">
                            <div class="catalog-empty"><strong><?= $search !== '' ? 'No products found.' : ($productStatus === 'inactive' ? 'No inactive products.' : ($manager ? 'No product records yet.' : 'No active products yet.')) ?></strong><span><?= $search !== '' ? 'Try another search or clear the filters.' : 'Product records will appear here when available.' ?></span><?php if ($manager && $search === '' && $productStatus !== 'inactive'): ?><button class="btn btn-primary btn-sm mt-2" type="button" data-bs-toggle="modal" data-bs-target="#productModal" data-product-create>Add Product</button><?php endif; ?></div>
                        </td>
                    </tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel catalog-panel" aria-labelledby="sitesTitle">
    <div class="catalog-heading catalog-heading-sites">
        <div>
            <h3 id="sitesTitle">Sites</h3>
            <p><?= count($sites) ?> shown<?php if ($manager): ?> of <?= $allSiteCount ?> sites<?php else: ?> active sites<?php endif; ?></p>
        </div><?php if ($manager): ?><button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#siteModal" data-site-create><i class="bi bi-plus-lg" aria-hidden="true"></i> Add Site</button><?php endif; ?>
    </div>
    <form method="get" class="catalog-toolbar catalog-toolbar-sites">
        <div class="catalog-search"><i class="bi bi-search" aria-hidden="true"></i><label class="visually-hidden" for="siteSearch">Search sites</label><input id="siteSearch" class="form-control" name="site_q" value="<?= e($siteSearch) ?>" placeholder="Search sites"><button class="btn btn-outline-primary" type="submit">Search</button></div>
        <?php if ($manager): ?><label class="visually-hidden" for="siteStatus">Site status</label><select id="siteStatus" class="form-select catalog-filter" name="site_status">
                <option value="all" <?= $siteStatus === 'all' ? 'selected' : '' ?>>All statuses</option>
                <option value="active" <?= $siteStatus === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $siteStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select><?php endif; ?>
        <a class="btn btn-light catalog-clear" href="products_sites.php" aria-label="Clear site search and filters">Clear</a>
    </form>
    <div class="table-responsive catalog-table-wrap" tabindex="0" aria-label="Sites table">
        <table class="table catalog-sites-table">
            <thead>
                <tr>
                    <th scope="col">Site Name</th>
                    <th scope="col">Status</th><?php if ($manager): ?><th scope="col">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sites as $s): $siteInactive = (int)$s['active'] !== 1;
                    $siteUsed = (int)($s['invoice_refs'] ?? 0) > 0; ?><tr>
                        <td class="catalog-name"><?= e($s['site_name']) ?></td>
                        <td><span class="status-pill <?= $siteInactive ? 'status-muted' : 'status-success' ?>"><?= $siteInactive ? 'Inactive' : 'Active' ?></span></td>
                        <?php if ($manager): ?><td>
                                <div class="catalog-actions"><button class="btn btn-light btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#siteModal" data-site-edit data-id="<?= (int)$s['id'] ?>" data-name="<?= e($s['site_name']) ?>" data-active="<?= (int)$s['active'] ?>"><i class="bi bi-pencil" aria-hidden="true"></i><span>Edit</span></button><?php if ($siteUsed): ?><span class="catalog-delete-note" title="Referenced by <?= $s['invoice_refs'] ?> invoice line(s)">In use · deactivate to retain history</span><?php else: ?><form method="post" data-confirm-submit="This permanently deletes <?= e($s['site_name']) ?>." data-confirm-title="Delete site?" data-confirm-button="Delete site"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_site"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn-light btn-sm catalog-delete" type="submit" aria-label="Delete <?= e($s['site_name']) ?>"><i class="bi bi-trash3" aria-hidden="true"></i></button></form><?php endif; ?></div>
                            </td><?php endif; ?>
                    </tr><?php endforeach; ?>
                <?php if (!$sites): ?><tr>
                        <td colspan="<?= $manager ? '3' : '2' ?>">
                            <div class="catalog-empty"><strong><?= $siteSearch !== '' ? 'No sites found.' : ($siteStatus === 'inactive' ? 'No inactive sites.' : ($manager ? 'No site records yet.' : 'No active sites yet.')) ?></strong><span><?= $siteSearch !== '' ? 'Try another search or clear the status filter.' : 'Site records will appear here when available.' ?></span><?php if ($manager && $siteSearch === ''): ?><button class="btn btn-outline-primary btn-sm mt-2" type="button" data-bs-toggle="modal" data-bs-target="#siteModal" data-site-create>Add Site</button><?php endif; ?></div>
                        </td>
                    </tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($manager): ?>
    <div class="modal fade" id="productModal" tabindex="-1" aria-labelledby="productModalTitle" aria-hidden="true" data-open-on-error="<?= !empty($postedProduct) ? '1' : '0' ?>">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content catalog-modal">
                <form method="post" id="productEditor"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_product"><input type="hidden" name="id" value="<?= e($postedProduct['id'] ?? '') ?>">
                    <div class="modal-header">
                        <div>
                            <h3 class="modal-title" id="productModalTitle">Add Product</h3>
                            <p class="mb-0">Product identity and transaction descriptions.</p>
                        </div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <fieldset class="catalog-fieldset">
                            <legend>Product identity</legend>
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label" for="productName">Product Name</label><input class="form-control" id="productName" name="product_name" maxlength="255" required value="<?= e($postedProduct['product_name'] ?? '') ?>"></div>
                                <div class="col-md-4"><label class="form-label" for="productHt">HT Code</label><input class="form-control" id="productHt" name="ht_code" maxlength="100" data-product-required value="<?= e($postedProduct['ht_code'] ?? '') ?>">
                                    <div class="form-text">Withdrawal identifier from your HT list.</div>
                                </div>
                                <div class="col-md-4"><label class="form-label" for="productQb">Item Code / QB Code</label><input class="form-control" id="productQb" name="qb_code" maxlength="100" value="<?= e($postedProduct['qb_code'] ?? '') ?>">
                                    <div class="form-text">One business product code used by both fields; separate from the internal QuickBooks ListID.</div>
                                </div>
                            </div>
                        </fieldset>
                        <div class="alert alert-warning mt-3 <?= $postedProductCodeConflict ? '' : 'd-none' ?>" id="productCodeConflictWarning" role="alert">
                            <p id="productCodeConflictMessage" class="mb-2">The stored Item Code and QB Code differ. Enter the confirmed shared product code above and check the confirmation to update both fields.</p>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="confirmProductCodeSync" name="confirm_code_sync" value="1" <?= ($postedProduct['confirm_code_sync'] ?? '') === '1' ? 'checked' : '' ?> <?= $postedProductCodeConflict ? 'required' : '' ?>>
                                <label class="form-check-label" for="confirmProductCodeSync">I confirmed the shared product code and authorize applying it to both fields.</label>
                            </div>
                        </div>
                        <fieldset class="catalog-fieldset">
                            <legend>Descriptions</legend>
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label" for="productHtDescription">HT Description</label><input class="form-control" id="productHtDescription" name="ht_description" maxlength="255" data-product-required value="<?= e($postedProduct['ht_description'] ?? '') ?>"></div>
                                <div class="col-md-6"><label class="form-label" for="productQbDescription">QB Description</label><input class="form-control" id="productQbDescription" name="qb_description" maxlength="255" value="<?= e($postedProduct['qb_description'] ?? '') ?>"></div>
                            </div>
                        </fieldset>
                        <fieldset class="catalog-fieldset mb-0">
                            <legend>Product details</legend>
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label" for="productUm">U/M</label><input class="form-control" id="productUm" name="u_m" maxlength="40" value="<?= e($postedProduct['u_m'] ?? '') ?>" placeholder="e.g. BOX"></div>
                                <div class="col-md-4"><label class="form-label" for="productPrice">Price</label>
                                    <div class="input-group"><span class="input-group-text">&#8369;</span><input class="form-control currency-input" id="productPrice" name="default_price" type="text" inputmode="decimal" value="<?= e($postedProduct['default_price'] ?? '') ?>"></div>
                                </div>
                                <div class="col-md-4"><label class="form-label" for="productKg">KG/Box</label><input class="form-control" id="productKg" name="kg_per_box" type="number" min="0" step="0.001" value="<?= e($postedProduct['kg_per_box'] ?? '') ?>"></div>
                                <div class="col-md-5"><label class="form-label" for="productActive">Status</label><select class="form-select" id="productActive" name="active">
                                        <option value="1" <?= ($postedProduct['active'] ?? '1') === '1' ? 'selected' : '' ?>>Active</option>
                                        <option value="0" <?= ($postedProduct['active'] ?? '') === '0' ? 'selected' : '' ?>>Inactive</option>
                                    </select></div>
                            </div>
                        </fieldset>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save Product</button></div>
                </form>
            </div>
        </div>
    </div>
    <div class="modal fade" id="siteModal" tabindex="-1" aria-labelledby="siteModalTitle" aria-hidden="true" data-open-on-error="<?= !empty($postedSite) ? '1' : '0' ?>">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content catalog-modal">
                <form method="post" id="siteEditor"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_site"><input type="hidden" name="id" value="<?= e($postedSite['id'] ?? '') ?>">
                    <div class="modal-header">
                        <div>
                            <h3 class="modal-title" id="siteModalTitle">Add Site</h3>
                            <p class="mb-0">Site choices are selected on invoice lines.</p>
                        </div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body"><label class="form-label" for="siteName">Site Name</label><input class="form-control" id="siteName" name="site_name" maxlength="120" required value="<?= e($postedSite['site_name'] ?? '') ?>">
                        <div class="mt-3"><label class="form-label" for="siteActive">Status</label><select class="form-select" id="siteActive" name="active">
                                <option value="1" <?= ($postedSite['active'] ?? '1') === '1' ? 'selected' : '' ?>>Active</option>
                                <option value="0" <?= ($postedSite['active'] ?? '') === '0' ? 'selected' : '' ?>>Inactive</option>
                            </select></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save Site</button></div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const productModal = document.getElementById('productModal');
        const siteModal = document.getElementById('siteModal');
        const productForm = document.getElementById('productEditor');
        const siteForm = document.getElementById('siteEditor');

        function fillProduct(data, editing) {
            if (!productForm) return;
            productForm.reset();
            productForm.elements.id.value = editing ? data.id : '';
            productForm.elements.product_name.value = editing ? data.name : '';
            productForm.elements.ht_code.value = editing ? data.ht : '';
            productForm.elements.qb_code.value = editing ? data.qb : '';
            const codeConflict = editing && data.codeConflict === '1';
            const conflictWarning = document.getElementById('productCodeConflictWarning');
            const conflictMessage = document.getElementById('productCodeConflictMessage');
            const conflictConfirmation = productForm.elements.confirm_code_sync;
            conflictWarning?.classList.toggle('d-none', !codeConflict);
            if (conflictConfirmation) {
                conflictConfirmation.required = codeConflict;
                conflictConfirmation.checked = false;
            }
            if (codeConflict && conflictMessage) {
                conflictMessage.textContent = `Stored Item Code "${data.itemCode}" differs from QB Code "${data.qb}". Enter the confirmed shared product code above and explicitly authorize applying it to both fields.`;
            }
            productForm.elements.ht_description.value = editing ? data.htDescription : '';
            productForm.elements.qb_description.value = editing ? data.qbDescription : '';
            productForm.elements.u_m.value = editing ? data.um : '';
            productForm.elements.default_price.value = editing ? data.price : '';
            window.formatCurrencyInput(productForm.elements.default_price);
            productForm.elements.kg_per_box.value = editing ? data.kg : '';
            productForm.elements.active.value = editing ? data.active : '1';
            productModal.querySelector('#productModalTitle').textContent = editing ? 'Edit Product' : 'Add Product';
            productForm.querySelectorAll('[data-product-required]').forEach(input => input.required = !editing || productForm.elements.active.value === '1');
        }
        productModal?.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            if (button?.hasAttribute('data-product-edit')) fillProduct({
                id: button.dataset.id,
                name: button.dataset.name,
                ht: button.dataset.ht,
                qb: button.dataset.qb,
                itemCode: button.dataset.itemCode,
                codeConflict: button.dataset.codeConflict,
                htDescription: button.dataset.htDescription,
                qbDescription: button.dataset.qbDescription,
                um: button.dataset.um,
                price: button.dataset.price,
                kg: button.dataset.kg,
                active: button.dataset.active
            }, true);
            else if (button?.hasAttribute('data-product-create')) fillProduct({}, false);
        });
        productForm?.querySelectorAll('[data-product-required]').forEach(input => input.required = productForm.elements.active.value === '1');
        productForm?.elements.active?.addEventListener('change', () => productForm.querySelectorAll('[data-product-required]').forEach(input => input.required = productForm.elements.active.value === '1'));
        siteModal?.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            siteForm.reset();
            const editing = button?.hasAttribute('data-site-edit');
            siteForm.elements.id.value = editing ? button.dataset.id : '';
            siteForm.elements.site_name.value = editing ? button.dataset.name : '';
            siteForm.elements.active.value = editing ? button.dataset.active : '1';
            siteModal.querySelector('#siteModalTitle').textContent = editing ? 'Edit Site' : 'Add Site';
        });
        if (productModal?.dataset.openOnError === '1') bootstrap.Modal.getOrCreateInstance(productModal).show();
        if (siteModal?.dataset.openOnError === '1') bootstrap.Modal.getOrCreateInstance(siteModal).show();
        [
            [productModal, productForm],
            [siteModal, siteForm]
        ].forEach(([modal, form]) => {
            if (!modal || !form) return;
            let dirty = false,
                saving = false,
                confirming = false;
            modal.addEventListener('show.bs.modal', () => {
                dirty = false;
                saving = false;
            });
            form.addEventListener('input', () => {
                dirty = true;
            });
            form.addEventListener('change', () => {
                dirty = true;
            });
            form.addEventListener('submit', () => {
                saving = true;
            });
            modal.addEventListener('hide.bs.modal', async event => {
                if (!dirty || saving) return;
                if (confirming) {
                    event.preventDefault();
                    return;
                }
                event.preventDefault();
                confirming = true;
                const choice = await Swal.fire({
                    title: 'Discard unsaved changes?',
                    text: 'Your changes in this form have not been saved.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Discard changes',
                    cancelButtonText: 'Keep editing',
                    confirmButtonColor: '#2563eb',
                    reverseButtons: true
                });
                confirming = false;
                if (choice.isConfirmed) {
                    dirty = false;
                    bootstrap.Modal.getOrCreateInstance(modal).hide();
                }
            });
        });
        document.querySelectorAll('#productEditor,#siteEditor').forEach(form => form.addEventListener('submit', function() {
            const button = form.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = true;
                button.textContent = 'Saving…';
            }
        }));
    });
</script><?php require __DIR__ . '/includes/footer.php'; ?>
