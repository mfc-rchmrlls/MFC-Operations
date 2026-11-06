<?php

require_once __DIR__ . '/../backend/config/app.php';

require_login();

/*
|--------------------------------------------------------------------------
| Load withdrawal
|--------------------------------------------------------------------------
*/

$no = trim((string) ($_GET['withdrawal_no'] ?? ''));

if ($no === '') {
    http_response_code(400);
    exit('Withdrawal number is required.');
}

$pdo = db();

$stmt = $pdo->prepare(
    'SELECT w.*, c.client_name
     FROM withdrawals w
     JOIN clients c ON c.id = w.client_id
     WHERE w.withdrawal_no = ?
     LIMIT 1'
);

$stmt->execute([$no]);
$withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$withdrawal) {
    http_response_code(404);
    exit('Withdrawal not found.');
}

/*
|--------------------------------------------------------------------------
| Load withdrawal items and product information
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    'SELECT
        wi.*,
        p.u_m,
        p.kg_per_box
     FROM withdrawal_items wi
     LEFT JOIN products p ON p.id = wi.product_id
     WHERE wi.withdrawal_id = ?
     ORDER BY wi.line_no'
);

$stmt->execute([(int) $withdrawal['id']]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Formatting helpers
|--------------------------------------------------------------------------
*/

function print_qty($value): string
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '';
    }

    return rtrim(
        rtrim(number_format((float) $value, 3, '.', ''), '0'),
        '.'
    );
}

function print_time(?string $value): string
{
    if (!$value) {
        return '';
    }

    $timestamp = strtotime($value);

    return $timestamp === false
        ? ''
        : date('h:i A', $timestamp);
}

$formDateTimestamp = strtotime((string) ($withdrawal['form_date'] ?? ''));

$displayDate = $formDateTimestamp === false
    ? ''
    : strtoupper(date('F d, Y', $formDateTimestamp));

$logoUrl = APP_BASE_URL . '/frontend/assets/images/company-logo.png';

$itemCount = count($items);

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Withdrawal Form - <?= e($no) ?></title>

    <style>
        /*
        |--------------------------------------------------------------------------
        | Page setup
        |--------------------------------------------------------------------------
        */

        @page {
            size: A4 portrait;
            margin: 9mm 10mm;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            color: #111;
            font-family: Arial, Helvetica, sans-serif;
        }

        body {
            font-size: 9px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        /*
        |--------------------------------------------------------------------------
        | Print action buttons
        |--------------------------------------------------------------------------
        */

        .print-actions {
            max-width: 190mm;
            margin: 0 auto 10px;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .print-actions button {
            padding: 7px 13px;
            border: 1px solid #aab3bd;
            border-radius: 4px;
            background: #fff;
            cursor: pointer;
        }

        /*
        |--------------------------------------------------------------------------
        | Printable sheet
        |--------------------------------------------------------------------------
        */

        .sheet {
            width: 190mm;
            max-width: 100%;
            margin: 0 auto;
        }

        /*
        |--------------------------------------------------------------------------
        | Company header
        |--------------------------------------------------------------------------
        */

        .header-table {
            height: 32mm;
        }

        .header-table td,
        .items-table td,
        .items-table th,
        .footer-table td {
            border: 1px solid #222;
        }

        .company-cell {
            width: 64%;
            padding: 3px 5px;
            vertical-align: middle;
        }

        .company-logo {
            display: block;
            width: 100%;
            max-height: 18mm;
            object-fit: contain;
            object-position: left center;
        }

        .company-details {
            margin-top: -1px;
            padding-left: 76px;
            font-size: 7.2px;
            line-height: 1.25;
        }

        .form-title {
            padding: 3px 4px;
            font-size: 12px;
            font-weight: 700;
            text-align: left;
        }

        .meta-label {
            width: 44%;
        }

        .meta-cell {
            height: 7mm;
            padding: 3px 4px;
            font-size: 9px;
        }

        .meta-label strong {
            font-weight: 400;
        }

        /*
        |--------------------------------------------------------------------------
        | Client and date
        |--------------------------------------------------------------------------
        */

        .client-table {
            margin-top: 0;
        }

        .client-table td {
            border: 1px solid #222;
        }

        .client-label {
            width: 64%;
            height: 6mm;
            padding: 3px 4px;
            font-weight: 700;
        }

        .client-value {
            height: 8mm;
            padding: 3px 4px;
            text-align: center;
        }

        .date-value {
            height: 8mm;
            padding: 3px 4px;
            font-weight: 700;
            text-align: center;
        }

        /*
        |--------------------------------------------------------------------------
        | Spacer and purchase order row
        |--------------------------------------------------------------------------
        */

        .spacer-band td {
            height: 5mm;
        }

        .orange-band {
            background: #f4b183;
        }

        .po-row td,
        .po-row th {
            height: 9mm;
        }

        .po-label-cell {
            width: 5%;
        }

        .po-value {
            color: #f00;
            font-size: 10px;
            font-weight: 700;
            text-align: center;
        }

        /*
        |--------------------------------------------------------------------------
        | Withdrawal items table
        |--------------------------------------------------------------------------
        |
        | Column widths total 100%:
        | Line      5%
        | Product  55%
        | Quantity 10%
        | Total KG 12%
        | Remarks  18%
        |
        */

        .items-table {
            margin-top: 0;
        }

        .items-table .line-col {
            width: 5%;
        }

        .items-table .product-col {
            width: 55%;
        }

        .items-table .qty-col {
            width: 10%;
        }

        .items-table .kg-col {
            width: 12%;
        }

        .items-table .remarks-col {
            width: 18%;
        }

        .items-table th {
            height: 9mm;
            padding: 2px 3px;
            font-size: 8.5px;
            font-weight: 700;
            text-align: center;
        }

        .items-table td {
            height: 5.45mm;
            padding: 1px 3px;
            vertical-align: middle;
            overflow-wrap: anywhere;
        }

        .line-cell {
            text-align: center;
        }

        .product-cell {
            text-align: left;
        }

        /*
         * Narrow numeric columns to fit their headings and values.
         * Keep quantities and weights on a single line.
         */
        .qty-cell,
        .kg-cell {
            padding-right: 2px;
            padding-left: 2px;
            text-align: center;
            white-space: nowrap;
        }

        .remarks-cell {
            color: #f00;
            font-size: 8px;
        }

        .nothing-follows {
            font-weight: 700;
            letter-spacing: 0.1px;
            white-space: nowrap;
        }

        /*
        |--------------------------------------------------------------------------
        | Footer and signatures
        |--------------------------------------------------------------------------
        */

        .footer-table {
            margin-top: 0;
        }

        .footer-table td {
            height: 6.2mm;
            padding: 2px 3px;
        }

        .footer-left {
            width: 64%;
            font-weight: 700;
        }

        .footer-right {
            width: 36%;
        }

        .approved {
            height: 10mm !important;
            font-weight: 700;
        }

        .footer-note {
            border: 0 !important;
            font-style: italic;
            font-weight: 400;
        }

        /*
        |--------------------------------------------------------------------------
        | Screen preview
        |--------------------------------------------------------------------------
        */

        @media screen {
            body {
                padding: 16px;
                background: #edf0f4;
            }

            .sheet {
                padding: 0;
                background: #fff;
                box-shadow: 0 2px 14px #0001;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Print-specific adjustments
        |--------------------------------------------------------------------------
        */

        @media print {
            .print-actions {
                display: none !important;
            }

            .sheet {
                width: 100%;
                max-width: none;
                margin: 0;
                box-shadow: none;
            }

            body {
                padding: 0;
                font-size: 9px;
            }

            .items-table .qty-col {
                width: 10%;
            }

            .items-table .kg-col {
                width: 12%;
            }

            .qty-cell,
            .kg-cell {
                padding-right: 2px;
                padding-left: 2px;
                white-space: nowrap;
            }

            .orange-band {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
        }
    </style>
</head>

<body>

    <div class="print-actions">
        <button type="button" onclick="window.print()">Print</button>
        <button type="button" onclick="window.close()">Close</button>
    </div>

    <main class="sheet">

        <!-- =========================================================
             COMPANY HEADER
        ========================================================== -->

        <table class="header-table" aria-label="Withdrawal header">
            <tr>
                <td class="company-cell" rowspan="4">
                    <img
                        class="company-logo"
                        src="<?= e($logoUrl) ?>"
                        alt="Hightower Storage and Trading Centre, Inc.">


                    <!--<div class="company-details">
                        Address: 502-A Carlos Palanca Sr. St., Quiapo, Manila<br>
                        Tel No.: (02) 5310-1181 | (02) 5310-1182 | (02) 5310-1163<br>
                        Email: htstorage2012@gmail.com
                    </div>-->

                </td>

                <td colspan="2" class="form-title">
                    WITHDRAWAL FORM
                </td>
            </tr>

            <tr>
                <td class="meta-cell meta-label">No.</td>
                <td class="meta-cell">
                    <?= e($withdrawal['withdrawal_no']) ?>
                </td>
            </tr>

            <tr>
                <td class="meta-cell meta-label">Time Received:</td>
                <td class="meta-cell">
                    <?= e(print_time($withdrawal['time_received'] ?? null)) ?>
                </td>
            </tr>

            <tr>
                <td class="meta-cell meta-label">Pick Up Time:</td>
                <td class="meta-cell" style="color:#f00;font-weight:700">
                    <?= e(print_time($withdrawal['pick_up_time'] ?? null)) ?>
                </td>
            </tr>
        </table>

        <!-- =========================================================
             CLIENT AND DATE
        ========================================================== -->

        <table class="client-table" aria-label="Client and date">
            <tr>
                <td class="client-label">CLIENT'S NAME:</td>
                <td style="width:36%"></td>
            </tr>

            <tr>
                <td class="client-value">
                    <?= e($withdrawal['client_name']) ?>
                </td>

                <td class="date-value">
                    <?= e($displayDate) ?>
                </td>
            </tr>
        </table>

        <!-- =========================================================
             WITHDRAWAL ITEMS
        ========================================================== -->

        <table class="items-table" aria-label="Withdrawal items">

            <colgroup>
                <col class="line-col">
                <col class="product-col">
                <col class="qty-col">
                <col class="kg-col">
                <col class="remarks-col">
            </colgroup>

            <tbody>

                <!-- Orange spacer band -->
                <tr class="spacer-band">
                    <td class="orange-band" colspan="2"></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>

                <!-- PO number and quantity heading -->
                <tr class="po-row">
                    <td></td>

                    <td class="po-value">
                        <?= e($withdrawal['po_no'] ?? '') ?>
                    </td>

                    <th scope="col">
                        QUANTITY<br>PER UOM
                    </th>

                    <th scope="col"></th>
                    <th scope="col"></th>
                </tr>

                <!-- Fixed 30 printable item rows -->
                <?php for ($i = 0; $i < 30; $i++): ?>

                    <?php
                    $item = $items[$i] ?? null;

                    $isMarker = (
                        $i === $itemCount &&
                        $itemCount < 30
                    );

                    $qtyText = '';
                    $kgText = '';

                    if ($item !== null) {
                        $qtyText = is_numeric($item['quantity'] ?? null) ? number_format((float) $item['quantity'], 0, '.', ',') : '';

                        $unit = trim((string) ($item['u_m'] ?? ''));

                        if ($unit !== '') {
                            $qtyText .= ' ' . $unit;
                        }

                        $kgPerBox = $item['kg_per_box'] ?? null;
                        $quantity = $item['quantity'] ?? null;

                        if (
                            $kgPerBox !== null &&
                            $kgPerBox !== '' &&
                            is_numeric($kgPerBox) &&
                            is_numeric($quantity)
                        ) {
                            $totalKg = (float) $quantity * (float) $kgPerBox;

                            $kgText = print_qty($totalKg) . ' KGS';
                        }
                    }

                    $remark = $item['remarks'] ?? '';
                    ?>

                    <tr>
                        <td class="line-cell">
                            <?= $i + 1 ?>
                        </td>

                        <td class="product-cell">
                            <?php if ($item !== null): ?>

                                <?= e($item['ht_description'] ?: ($item['product_name'] ?? '')) ?>

                            <?php elseif ($isMarker): ?>

                                <span class="nothing-follows">
                                    *************NOTHING FOLLOWS***************************
                                </span>

                            <?php endif; ?>
                        </td>

                        <td class="qty-cell">
                            <?= e($qtyText) ?>
                        </td>

                        <td class="kg-cell">
                            <?= e($kgText) ?>
                        </td>

                        <td class="remarks-cell">
                            <?= e($remark) ?>
                        </td>
                    </tr>

                <?php endfor; ?>

            </tbody>
        </table>

        <!-- =========================================================
             APPROVAL AND SIGN-OFF
        ========================================================== -->

        <table class="footer-table" aria-label="Approval and sign-off">
            <tr>
                <td class="footer-left">
                    PREPARED/ REQUEST BY:
                </td>

                <td class="footer-right approved">
                    APPROVED BY:
                </td>
            </tr>

            <tr>
                <td class="footer-left"></td>
                <td class="footer-right" rowspan="4"></td>
            </tr>

            <tr>
                <td class="footer-left">
                    <em>FOR HSCTI USE ONLY</em>
                </td>
            </tr>

            <tr>
                <td class="footer-left">
                    PREPARED/ PICKED BY:
                </td>
            </tr>

            <tr>
                <td class="footer-left footer-note">
                    Updated HSCTI Form 2021
                </td>
            </tr>
        </table>

    </main>
</body>

</html>