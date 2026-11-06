# Withdrawal & QuickBooks Invoicing System — Phase 2 (Separated Frontend/Backend)

## Folder structure

```text
withdrawal-qb/
├── index.php
├── frontend/
│   ├── assets/              # CSS and JavaScript
│   ├── includes/            # Shared visual layout only
│   ├── login.php
│   ├── dashboard.php
│   ├── withdrawal_form.php
│   ├── withdrawal_print.php
│   ├── withdrawals.php
│   ├── invoice_preparation.php
│   ├── products_sites.php
│   ├── settings.php
│   └── quickbooks_status.php
└── backend/
    ├── config/app.php       # Application bootstrap/configuration
    ├── core/                # Database, auth and shared business helpers
    ├── controllers/         # POST actions (save withdrawal/invoice, logout)
    ├── api/                 # JSON endpoints
    ├── exports/              # CSV export endpoint
    └── database/schema.sql  # MySQL schema
```

## Install in XAMPP

1. Extract this folder as `C:\xampp\htdocs\withdrawal-qb`.
2. Start Apache and MySQL.
3. Import `backend/database/schema.sql` in phpMyAdmin. It creates `withdrawal_qb` and example master records. Replace example clients/products/sites with the real company data before production.
4. Confirm database credentials in `backend/core/database.php` or set `WQ_DB_HOST`, `WQ_DB_NAME`, `WQ_DB_USER`, and `WQ_DB_PASS`.
5. If you install under a different folder, set `WQ_BASE_URL` to that URL prefix (for example `/my-folder`). Default is `/withdrawal-qb`.
6. Open `http://localhost/withdrawal-qb/frontend/setup.php` and create the first administrator account. Delete `frontend/setup.php` after initial setup.
7. Sign in at `http://localhost/withdrawal-qb/frontend/login.php`.

### Upgrade an existing database

Back up `withdrawal_qb` first. For an existing database, apply `20261015_invoice_number.sql` if it has not already been applied, then follow `backend/database/migrations/20261015_product_site_permissions.sql` in phpMyAdmin with `withdrawal_qb` selected. Apply `20261019_whole_number_quantities.sql` after reviewing its preflight behavior. It stops without changing quantity data if fractional historical quantities exist; reconcile those rows against authoritative records before rerunning. Apply `20261019_invoice_memo.sql` once to add the nullable invoice memo field. Apply `20261019_sales_order_preparation.sql` once to add separate local Sales Order draft tables. Review and apply `20261026_sales_order_optional_tax.sql` after the Sales Order tables exist; it changes only the local Sales Order `tax_code` column from `ENUM('Non','Tax') NOT NULL` to `ENUM('Non','Tax') NULL`, preserving the allowed labels and existing values while allowing an intentionally blank choice. It does not rewrite historical empty-string enum values. Test it against a disposable database first and verify the target schema before applying it. Do not import `schema.sql` over an existing database.

The product/site migration is intentionally staged:

1. Run the `ALTER`/`UPDATE` statements through creation of `employee_permissions`. Existing `item_code` and `description` are copied to the QB mapping fields; `product_name` is copied to the initial HT description because it is the existing withdrawal display text. The legacy columns and all product/site/transaction IDs remain intact.
2. Review the duplicate-code queries and the incomplete-mapping report. Resolve QB conflicts against the real item list. Supply each HT Code from the authoritative HT master list; the migration never manufactures one. Products without an HT Code cannot be selected for new withdrawals. Populate descriptions/mappings and set products active only when the required fields are confirmed.
3. Deploy the updated PHP files. Admins can complete legacy mappings in Products & Sites. Admins can grant Employee access in Settings using **Manage Products & Sites**.
4. After confirming the running code no longer reads `products.default_site_id`, inspect `SHOW CREATE TABLE products`, then manually run the commented FK and column drop statements at the end of the migration. Invoice line `site_id` is preserved. Only after reviewing/fixing duplicates, add the two commented unique indexes. Existing DB collation is case-insensitive in the supplied schema.

The migration file includes the review queries and commented final DDL. Keep a copy of the pre-migration backup until the new withdrawal, invoice and employee permission paths are verified in the local database.

## Phase 2 scope

- Local MySQL records for withdrawals and invoice preparation.
- Employee login, role checks, CSRF protection and activity log.
- Withdrawal create/edit/search/register and print view.
- Invoice draft separated from the source withdrawal; supports invoice-only additional lines.
- Product/site/client setup and CSV export.
- QuickBooks status view and queue schema.

## Important scope boundary

QuickBooks invoice posting is implemented through the Admin-only, signed bridge workflow, but is disabled by default and is not ready merely because the software is installed. A send requires both PHP and bridge write gates, a verified company fingerprint, current approved customer/item/tax/site mappings, and a passing invoice preflight. The bridge supports signed health checks, bounded read-only qbXML diagnostics, validation-only preflight, and a single InvoiceAdd attempt; it does not enumerate master lists or edit/delete transactions. Health reports bridge/SDK registration and process presence only, not the open company file. A diagnostic or successful preflight is not authorization to post. Unknown InvoiceAdd outcomes are terminal and must be reconciled manually; they are never retried automatically. Do not enable either write gate until the intended company and exact invoice have been reviewed and the user explicitly approves that invoice.

Sales Order Preparation is a separate local draft workflow. It uses the additive `20261019_sales_order_preparation.sql` migration and does not modify invoice records or submit anything to QuickBooks. U/M is displayed from Product Master and enforced by the save controller; users cannot override it. SITE is required, while LOT NUMBER and EXPIRATION DATE may be blank. CLASS, STATUS, FULFILLED QUANTITY, and TAX are retained only as local preparation values pending verified company-file mapping. A blank Sales Order TAX is saved as SQL `NULL`; databases created with a non-null enum version of this column must apply the reviewed `20261026_sales_order_optional_tax.sql` migration first.

Invoice drafts retain local site, lot, item code, product identity/description, unit, price, amount, expiration, and local tax choice. These values are not proof of QuickBooks mappings. In particular, SITE, LOT NUMBER, ITEM CODE, U/M, EXPIRATION DATE, and the local `Non`/`Tax` values need company-file field/configuration decisions and qbXML verification before posting. `products.item_code` and `products.qb_code` are aliases for the same business product code; Product Master writes both together and blocks edits to conflicting stored values unless a manager explicitly confirms the shared code. Invoice and Sales Order saves also reject conflicting product-code fields. `Exempt` is not a supported preparation choice: invoice storage is `ENUM('Non','Tax')`, and neither preparation UI offers `Exempt`. Existing Sales Order rows containing that legacy string are retained but blocked by export validation; no data is rewritten. Customer and product `qb_list_id` values must also be populated from verified QuickBooks records; staff do not enter ListIDs. Posting remains disabled until the intended company, all required mappings, and live transaction behavior have been explicitly verified.

### Safe export foundation and signed bridge (2026-10-26)

`20261023_typed_qb_exports.sql` creates the typed export queue, attempts, and initial reference-verification table without changing legacy `qb_jobs`. `20261023_typed_qb_exports_hardening.sql` additively adds `company_file_fingerprint`, `diagnostic_request_id`, and `evidence_hmac`, and creates `qb_export_events`; it preserves existing rows and is safe to rerun on MariaDB versions supporting `ADD COLUMN IF NOT EXISTS`. Check the target schema first. Test changes on a disposable database before any operational deployment. A read-only schema inspection of the currently configured `withdrawal_qb` database on 2026-10-26 found the three hardening columns and `qb_export_events` present, along with the Phase 3A tables and legacy `qb_jobs`. This work did not apply a migration, so the time/source of that schema change is unknown. Do not assume this local snapshot describes another deployment.

The current source for `20261026_sales_order_optional_tax.sql` changes the Sales Order TAX column to nullable `ENUM('Non','Tax')`. Older draft versions used nullable text; inspect the target column and every stored TAX value before applying the current migration, because narrowing text to an enum can reject or coerce values outside the two supported labels. Do not rewrite historical TAX values automatically.

The Admin-only Export Preview page can save an eligible immutable snapshot as `pending`; a separate CSRF-protected Admin confirmation can submit a pending invoice through the signed bridge only when both write flags and every company/source/mapping preflight pass. Previewing and saving do not send a transaction. An export can be claimed once, and an ambiguous result is terminal `unknown`; it must be reconciled manually and is never retried automatically. Sales Orders remain local-only. The legacy `qb_jobs` workflow is unchanged. An Admin-only exact-name lookup and explicit customer/item verification interface exists, but no customer or item has been verified against the intended company file. Tax remains blocked until its mapping and tax calculation are verified. Site destination, U/M, Lot Number, Expiration Date, and related transaction fields are unresolved and block export.

`backend/quickbooks-bridge/Program.cs` is a Windows .NET Framework x64 bridge using the registered `QBXMLRP2.RequestProcessor` COM component. It binds to `127.0.0.1:8765`, supports signed `health`, bounded read-only `diagnose`, validation-only `validate`, and a separately gated invoice-only `submit`. Diagnostics send bounded ListID-filtered queries; InvoiceAdd checks the current session identity and verified references immediately before a single write. Health does not open a company session. The v2 company fingerprint HMAC binds the canonical SDK-reported file path to QuickBooks `CompanyName` and `LegalCompanyName`; it does not prove that replacement contents at the same path are unchanged, so an administrator must independently confirm the open company.

The PHP client uses `WQ_QB_BRIDGE_URL` (exactly `http://127.0.0.1:8765`, no path/query), and the bridge and PHP processes must receive the same random `WQ_QB_BRIDGE_SECRET` of at least 32 UTF-8 bytes. `WQ_QB_VERIFICATION_SECRET` is a separate secret used by PHP to sign human-approved mapping evidence; it must not be reused as the bridge secret. `WQ_QB_COMPANY_FINGERPRINT` is independently checked by PHP and the bridge. `WQ_QB_INVOICE_WRITE_ENABLED` is forcibly set to `0` by the local launchers. These values are loaded from process environments; PHP and C# do not decrypt the local config themselves.

#### Per-user protected XAMPP configuration

The Windows PowerShell scripts under `backend\quickbooks-bridge` provide a repeatable local setup. They store a stable, randomly generated bridge secret and verification secret in a DPAPI CurrentUser-encrypted file under `%LOCALAPPDATA%\MFC-Operations\QuickBooksBridge\local-config.bin`, with an ACL restricted to the current Windows user. The encrypted file is outside the project. Secrets are never printed, passed as command-line arguments, or written into Apache configuration. The same interactive account must run the setup, bridge, XAMPP Control Panel, and Apache; DPAPI CurrentUser intentionally prevents another service account from loading this file. Local administrators and same-user processes remain within the Windows account's trust boundary.

Run once in a PowerShell window as the interactive account that owns QuickBooks and XAMPP:

```powershell
Set-Location C:\xampp\htdocs\MFC-Operations-main\backend\quickbooks-bridge
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\configure-local.ps1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\build.ps1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\status.ps1
```

`configure-local.ps1` does not rotate existing keys on restart. The PHP runtime receives the values only when Apache is newly launched from the configured XAMPP Control Panel process. The wrapper refuses to stop or replace existing processes. Stop Apache normally and close the Control Panel, then launch it with:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\start-apache-with-qb-config.ps1
```

Start Apache using the Control Panel's non-service start action. Do not run Apache as a Windows service or under another account with this CurrentUser configuration. The wrapper will refuse if an Apache service is registered. It does not start QuickBooks or edit company files. If Apache is already running, it cannot receive new environment values; use the normal XAMPP stop/close procedure before running the wrapper.

#### Read-only company identity and connection check

Open QuickBooks Desktop manually as its built-in Admin and visually confirm that the title bar identifies `Testing QB.qbw`. Then run:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\verify-company.ps1
```

This explicitly invokes the existing read-only `--identify-current-company` SDK operation. The SDK returns company/legal names and a candidate path-bound fingerprint, but intentionally does not return the company filename or path; the filename must be confirmed in the QuickBooks UI. Do not save the candidate unless the company is confirmed. To save it after reviewing the SDK-reported names, rerun with `-SaveFingerprint` and type the explicit confirmation requested by the script:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\verify-company.ps1 -SaveFingerprint
```

If the SDK reports `SDK_COM_80040418`, the built-in QuickBooks Admin must authorize first-time access through QuickBooks' normal authorization flow. Stop and resolve that prompt; do not bypass it. The script does not change QuickBooks authorization or company data. After fingerprint confirmation, restart the bridge with `start.ps1` and Apache through the wrapper so both processes load the same expected fingerprint.

Start the bridge in its own interactive PowerShell window:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\start.ps1
```

It decrypts settings for the current Windows user, requires a valid expected fingerprint and executable, rejects an existing port-8765 listener without terminating any process, warns if QuickBooks is not running, binds only to `127.0.0.1:8765`, and forces invoice writes off. Stop it with Ctrl+C. `status.ps1` reports local executable/listener/process/configuration state without performing an SDK request; SDK authorization and fingerprint matching remain “not tested” until the explicit company check succeeds.

Finally, sign in as Admin to the QuickBooks Export page (`/frontend/quickbooks_exports.php`). Its PHP-runtime readiness table verifies the actual Apache environment, signed bridge health, QuickBooks process status, SDK identity result, fingerprint match, and write settings. Successful signed health proves authenticated PHP-to-bridge connectivity, but not company authorization; the separate read-only company identity check is required. Writes must remain disabled and invoice posting is not ready during setup.

Request HMAC-SHA256 input is `timestamp + LF + operation + LF + requestId + LF + lowercase-hex SHA-256(raw-body)`; timestamps are 10-digit Unix seconds with 30-second maximum skew and request IDs are GUID nonces. The bridge rejects replays using its LocalAppData replay cache. Response HMAC-SHA256 input is `timestamp + LF + response + LF + requestId + LF + HTTP-status + LF + lowercase-hex SHA-256(raw-response-body)`. PHP authenticates response bytes and timestamp, does not follow redirects, ignores proxies, and applies request/response limits and timeouts. Neither endpoint exposes a credential or signature in application responses.

### Starting the bridge and establishing expected company identity

Run QuickBooks Desktop interactively as the authorized Windows user and open the intended company file. Configure and build the bridge as described in **Per-user protected XAMPP configuration** above:

```powershell
Set-Location C:\xampp\htdocs\MFC-Operations-main\backend\quickbooks-bridge
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\build.ps1
```

Start it in the same interactive user session after verifying and saving the company fingerprint:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\start.ps1
```

The console must say the listener is ready; this indicates only that the loopback HTTP listener started. It does not indicate that a company session or intended company is available. The bridge listens only at `127.0.0.1:8765`; verify with `Get-NetTCPConnection -State Listen -LocalPort 8765`. A port/URL ACL conflict is reported without stopping another process. Stop the manually launched bridge with Ctrl+C in its own console; do not terminate unrelated processes. Do not install it as a Windows service: SDK access may require the interactive user session and QuickBooks authorization prompt.

Use `verify-company.ps1` above for the explicit candidate-identity check and optional fingerprint save. The read-only SDK response returns company names and a candidate HMAC fingerprint, not the company filename or path. The v2 fingerprint binds the canonical SDK-reported path to QuickBooks company/legal names; it does not prove that replacement contents at the same path are unchanged. Re-confirm the intended company in the QuickBooks UI and do not treat identity alone as business approval.

### Admin name-based customer and product verification

The Admin-only QuickBooks Export Preview now supports explicit name lookups; opening the page does not start an SDK company session. Select a local customer or product and request a lookup. The signed bridge query runs only after the expected company fingerprint is configured and the bridge independently verifies the open company. It uses a qbXML `NameFilter` with `MatchCriterion=Equal`, caps returned candidates at 50 (requesting one extra to detect overflow), and never enumerates the master list.

Customer search uses the exact local customer name as QuickBooks `Name`; results show QuickBooks `Name`, `FullName`, and `CompanyName` separately. `CompanyName` is returned for administrator context but is not a QuickBooks query filter. If the intended customer is discoverable only by a different `CompanyName`, this bounded lookup cannot find it; do not claim that `Name` proves `CompanyName`.

Product lookup uses bounded exact QuickBooks `NameFilter`/`FullName` searches based on the local product name and configured invoice description. These results are diagnostic only: the original QuickBooks source field or import process for local `Item Code` has not been established. QBXML 16.0 exposes item `Name`, `FullName`, `SalesDesc`, and `ManufacturerPartNumber`, but no generic `SKU` field or SKU query filter. `ManufacturerPartNumber` is not assumed to be the Item Code, and invoice-line `Desc` is not assumed to equal item `SalesDesc`. Even one exact active item-name candidate cannot be saved as item verification until an authoritative local-to-QuickBooks Item Code relationship is established. The lookup does not enumerate unrestricted item lists.

For customer verification, the lookup returns the internal `ListID` from QuickBooks and does not expose it as a manual input. The server repeats the exact name lookup and performs a second exact-ListID read-only diagnostic; it saves customer evidence only if company identity, name, explicit active status, and internal ID still match. Item results never receive a verify action and a crafted item verification POST is rejected. Missing/unknown active status, missing ID, no candidate, more than 50 candidates, or company mismatch fails closed. Customer comparisons are exact and are not fuzzy-normalized. Customer evidence does not approve tax, site, U/M, Lot Number, Expiration Date, or transaction field mappings and does not enable posting.

The existing product/site migration `20261015_product_site_permissions.sql` copies legacy `products.item_code` into `products.qb_code` and legacy `products.description` into `products.qb_description`; product CRUD, invoice save, and Sales Order save now consistently treat Item Code and QB Code as the same local business value. A read-only check of the configured database on 2026-10-26 found 94 products: 75 nonblank code pairs matched exactly, 18 pairs were both NULL, and one pair was both blank. There were no null/blank asymmetries, conflicts, raw differences, or duplicates in either code column. The schema has a unique index on `qb_code`, but not on legacy `item_code`. This confirms local consistency, not which QuickBooks item field or import/synchronization process originally supplied the code. Item verification therefore remains unresolved. Preserve current product values and any existing signed evidence; do not infer or write a ListID.

`products.qb_list_id` is an internal application value, not entered or displayed to staff. Signed reference verification writes it; export eligibility checks it against signed evidence; and Sales Order drafts snapshot it into `sales_order_items.qb_item_list_id`. The configured database has no populated product ListIDs and no ListID index/constraint, but the column is still used by these workflows. Keep it until a reviewed replacement updates the dependencies and preserves existing snapshots; no column-removal migration is proposed or applied.

Use the Admin Export page to verify signed bridge health from the actual Apache/PHP runtime. A separate CLI PHP process has different environment state unless launched with the protected settings; a successful CLI request does not verify Apache. `health()` checks bridge/SDK registration and process presence only. `diagnose()` opens a session, compares the actual session's fingerprint to the independently configured expected fingerprint, and only then queries supplied ListIDs. The authenticated result is not mapping approval; customer, item, tax, and site references still require explicit administrator review. The bridge does not enumerate company records. For company-session failures, check QuickBooks is open in the same interactive Windows session and respond to any authorization prompt; if the SDK reports `SDK_COM_80040418`, the built-in QuickBooks Admin must authorize first-time access through QuickBooks' normal authorization flow. Stop diagnostics and resolve such failures rather than assuming access.

`WQ_QB_VERIFICATION_SECRET` is separate from the bridge HMAC secret and is for future signed human-approved mapping evidence. It is not used to establish company identity. No diagnostic endpoint or UI automatically approves customer/item/tax/site mappings.

No customer, item, tax-code/tax-item, or inventory-site mapping has been approved against the intended company file. The bridge does not enumerate candidates; a server-side caller must provide exact candidate ListIDs (up to 50 per diagnostic). Site, U/M, Lot Number, Expiration Date, tax-code/item behavior and rounding still need company-specific field/configuration verification. Diagnostics never approve mappings. Do not enable posting or add transaction-writing qbXML operations in this phase.

The Admin Export page's connection check and bounded diagnostic forms are the supported application-side read-only checks. Do not paste secrets into command history or source files. A direct server-side/CLI diagnostic must use an exact authoritative candidate ListID and the protected settings loaded into that process; it does not search by customer name or verify the Apache runtime.

Validation commands (run from the project root):

```powershell
C:\xampp\php\php.exe tests\quickbooks_exports_test.php
C:\xampp\php\php.exe tests\quickbooks_exports_mariadb_test.php
C:\xampp\php\php.exe tests\sales_order_workflow_mariadb_test.php
C:\xampp\php\php.exe tests\quickbooks_bridge_http_test.php
C:\xampp\php\php.exe tests\quickbooks_bridge_server_test.php
node tests\client_history_ui_test.js
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { C:\xampp\php\php.exe -l $_.FullName }
```

The MariaDB test creates and removes a uniquely named disposable test database. The HTTP test uses a mock bridge. The server test compiles and exercises the local C# bridge protocol, replay rejection, and self-tests; passing these tests does not prove access to an authorized QuickBooks company file.

## Print

Open a saved withdrawal and use **Print form**. The print page hides its own Print/Close controls and prints only the form content. The print template is an initial browser-print layout based on the known Excel fields; compare it with the Excel print preview and adjust page size/margins/column widths before production rollout.
