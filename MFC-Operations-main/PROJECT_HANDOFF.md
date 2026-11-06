# MFC-Operations — Project Handoff for Codex

> **Purpose:** Give Codex a reliable starting point for future work so it can understand the application's architecture, workflows, conventions, and recent changes without re-reading every file on every task.
>
> **Repository:** https://github.com/mfc-rchmrlls/MFC-Withdrawal-Invoicing
> **Primary branch reviewed:** `main`  
> **Application source folder:** `MFC-Operations-main/`  
> **Last handoff review:** 2026-10-26
>
> **Important:** This document is a project map, not a substitute for checking the specific files involved in a change. The repository is the source of truth. If the code, database, or this document disagree, inspect the current code and schema and update this document as needed. Do not assume static review proves runtime behavior.

---

## 1. Project overview

MFC-Operations is a PHP/MySQL internal operations application for managing withdrawal records and preparing invoice drafts for QuickBooks Desktop Enterprise Solutions 24.0.

The application separates presentation pages from backend operations:

- **Frontend:** PHP pages, shared layout, styles, and browser-side JavaScript.
- **Backend:** application bootstrap/configuration, authentication and shared helpers, POST controllers, JSON APIs, exports, database schema, and migrations.
- **Database:** MySQL, normally run locally through XAMPP during development.
- **QuickBooks:** invoice preparation and typed exports are local preview workflows. The PHP transaction-posting lifecycle and bridge transaction-writing implementation have been removed. The bridge supports signed health, validation, and read-only diagnostics only; no InvoiceAdd or SalesOrderAdd operation is available.

The application should preserve the distinction between the original withdrawal transaction and the invoice draft created from it.

## 2. Repository and source layout

The repository has a project folder nested inside the repository. Most application files are under `MFC-Operations/`, not the repository root.

The documented layout is approximately:

```text
MFC-Operations/
├── index.php
├── frontend/
│   ├── assets/                 # CSS, JavaScript, and frontend assets
│   ├── includes/               # Shared visual layout and navigation
│   ├── login.php
│   ├── setup.php               # Initial administrator setup; remove after use
│   ├── dashboard.php
│   ├── withdrawal_form.php
│   ├── withdrawal_print.php
│   ├── withdrawals.php
│   ├── invoice_preparation.php
│   ├── clients.php             # Client master and invoice history
│   ├── products_sites.php      # Product and site master data
│   ├── settings.php
│   └── quickbooks_status.php   # Status view; not proof of working QB integration
└── backend/
    ├── config/
    │   └── app.php             # Application bootstrap/configuration
    ├── core/                   # Database, authentication, authorization,
    │                           # validation, and shared business helpers
    ├── controllers/            # POST actions (for example save withdrawal/invoice)
    ├── api/                    # JSON endpoints called by frontend JavaScript
    ├── exports/                # CSV export endpoints
    ├── database/
    │   ├── schema.sql          # Initial database schema
    │   └── migrations/         # Controlled changes to existing databases
    └── ...
```

This is a guide to the expected structure, not an exhaustive file listing. Check the actual repository tree before adding a new file or assuming a path.

### Key file references

- [Repository](https://github.com/erichmerilles/MFC-Operations)
- [Application README](https://github.com/erichmerilles/MFC-Operations/blob/main/MFC-Operations/README.md)
- [Withdrawal register](https://github.com/erichmerilles/MFC-Operations/blob/main/MFC-Operations/frontend/withdrawals.php)
- [Withdrawal form](https://github.com/erichmerilles/MFC-Operations/blob/main/MFC-Operations/frontend/withdrawal_form.php)
- [Invoice preparation](https://github.com/erichmerilles/MFC-Operations/blob/main/MFC-Operations/frontend/invoice_preparation.php)
- [Clients page](https://github.com/erichmerilles/MFC-Operations/blob/main/MFC-Operations/frontend/clients.php)
- [Products & Sites page](https://github.com/erichmerilles/MFC-Operations/blob/main/MFC-Operations/frontend/products_sites.php)
- [Shared sidebar](https://github.com/erichmerilles/MFC-Operations/blob/main/MFC-Operations/frontend/includes/sidebar.php)
- [Client invoice history API](https://github.com/erichmerilles/MFC-Operations/blob/main/MFC-Operations/backend/api/client_invoice_history.php)
- [Client invoice detail API](https://github.com/erichmerilles/MFC-Operations/blob/main/MFC-Operations/backend/api/client_invoice_detail.php)
- [Application README's database upgrade instructions](https://github.com/erichmerilles/MFC-Operations/blob/main/MFC-Operations/README.md)

## 3. Main business workflow

### A. Withdrawal workflow

1. An authenticated user opens the Withdrawal Register.
2. The user creates or opens a withdrawal in `frontend/withdrawal_form.php`.
3. The form loads active clients and eligible products from the database.
4. Withdrawal line items are saved to the withdrawal tables by the relevant backend controller.
5. The user can find saved withdrawals in `frontend/withdrawals.php`.
6. A saved withdrawal can be opened, printed, or used as the source for invoice preparation.

A withdrawal is the original operational record. Invoice-only edits must not silently change the withdrawal's original line items or values.

### B. Invoice preparation workflow

1. The user opens Invoice Preparation from a saved withdrawal.
2. `frontend/invoice_preparation.php` locates the withdrawal and its invoice draft.
3. If a draft does not exist, the page creates an invoice header and copies withdrawal lines into `invoice_items`.
4. Invoice lines are stored separately from withdrawal lines. They may contain invoice-specific item mapping, description, unit, price, amount, tax choice, site, and additional invoice-only lines, according to the current schema and UI.
5. The invoice save controller validates the submitted data and saves the draft.
6. The invoice status controls whether it can be edited. Current code treats statuses such as `Invoiced`, `Queued`, `Processing`, and `Uncertain Result` as locked because a submission may have occurred.
7. Live QuickBooks posting is disabled. The package includes a Windows bridge for authenticated health checks and bounded, read-only diagnostics only. A running QuickBooks process is not proof that the intended company file is open or authorized.

Invoice drafts also have a local `invoices.memo` field. `frontend/invoice_preparation.php` loads it with the invoice header and posts edits to `backend/controllers/invoice_save.php`; the controller validates and saves it in the existing invoice update transaction. Existing installations need `20261019_invoice_memo.sql`. This memo is not synchronized to QuickBooks.

Displayed monetary values use PHP peso formatting with two decimals and thousands separators. Editable price fields show grouped values when not focused and accept correctly grouped comma input; database values remain numeric.

The invoice line table in `frontend/invoice_preparation.php` uses fixed pixel widths for compact fields and lets Description take the remaining space, styled in `frontend/assets/css/app.css`. The shared header adds an asset modification-time query to the CSS URL so updated styles are fetched. Product selection displays only `products.qb_description`. Product Master `item_code` and `qb_code` are aliases for the same business product code; invoice and Sales Order lines use that code, not a QuickBooks ListID. Opening a draft does not update saved invoice descriptions.

The updated invoice page passed PHP syntax validation. Automated PHP, MariaDB, bridge HTTP, and compiled Windows-bridge tests are present; passing them does not prove a real company-file connection. The revised layout has not been visually checked in a browser.

**Do not implement automatic QuickBooks posting, enable a send action, or claim successful QB integration unless that is explicitly requested and all integration prerequisites are verified.**

Sales Order Preparation is implemented as a separate local-only workflow in `frontend/sales_order_preparation.php` with saves handled by `backend/controllers/sales_order_save.php`. It uses separate `sales_orders` and `sales_order_items` tables from `20261019_sales_order_preparation.sql`, links each draft to its source withdrawal, and preserves invoice records unchanged. Apply the migration before using the screen. S.O. NO. is manually entered and unique; customer/date/PO default from the withdrawal; ITEM is selected from Product Master and its internal `products.qb_list_id` reference is snapshotted when present; staff do not enter ListIDs. DESCRIPTION defaults to the stored QB product description; SITE is selected from active sites; ORDERED defaults to the whole withdrawal quantity; U/M is displayed read-only and saved from Product Master; RATE defaults from Product Master; AMOUNT is recalculated from ORDERED × RATE. LOT NUMBER, CLASS, STATUS, FULFILLED QUANTITY, EXPIRATION DATE, TAX, and Memo remain optional local values. Blank TAX is saved as `NULL` and requires the reviewed `20261026_sales_order_optional_tax.sql` migration when the target schema still has a non-null enum column. The app has no live QuickBooks Sales Order submission or verified field mapping; do not queue or send these drafts.

### C. Client invoice history workflow (recent update)

1. The authenticated user opens `frontend/clients.php`.
2. Clients are displayed in an accordion, with search and client-list pagination.
3. Opening a client's accordion loads invoice history through `backend/api/client_invoice_history.php`.
4. Invoice history is scoped to the selected client and paginated at 10 invoices per page.
5. Clicking an invoice number / details action opens a Bootstrap modal.
6. The modal requests `backend/api/client_invoice_detail.php` with the selected invoice ID and client ID.
7. The detail API verifies that the invoice belongs to that client, then loads the invoice header and actual invoice rows from `invoice_items`. It does **not** use withdrawal rows as a substitute for the invoice's saved rows.
8. The modal displays the stored invoice lines and a calculated sum of saved line amounts.

## 4. Data concepts and mapping rules

Use the actual current schema and controller validation as the final authority. The following distinctions are important:

- **Client/customer:** A record in `clients`; invoice headers reference a client/customer ID.
- **Withdrawal:** Original operational record, identified by a withdrawal number such as `withdrawal_no`.
- **Withdrawal line:** An item in `withdrawal_items`, linked to a withdrawal and product.
- **Invoice draft/header:** A record in `invoices`, associated with a source withdrawal and customer.
- **Invoice line:** A saved row in `invoice_items`, associated with an invoice. Invoice rows can differ from withdrawal rows.
- **Product:** Master record in `products`.
- **Site:** Master record in `sites`; invoice lines store their selected site through `site_id` where applicable.

### Product identifiers and descriptions

Do not treat the following as interchangeable:

- **HT Code:** Withdrawal-side business code.
- **HT Description:** Withdrawal-side display description.
- **Item Code / QB Code:** The same business product code, stored in the legacy `products.item_code` and current `products.qb_code` columns. New and edited Product Master records write the same value to both fields. Invoice and Sales Order saves reject conflicting stored values; Product Master requires explicit confirmation before resolving a conflict.
- **QB Description:** Invoice-side invoice description.
- **`products.qb_list_id`:** Internal QuickBooks ListID reference, not the business Item Code. It is not entered or displayed to staff; current signed reference verification, export eligibility, and Sales Order draft snapshots consume it. The signed verification table also stores the evidence/ListID, but current code requires the local product mirror to agree. Retain the column until a reviewed replacement updates all these dependencies and preserves existing Sales Order snapshots.

Product/site master logic supports separate withdrawal-side HT mappings and invoice-side QB descriptions. Item Code and QB Code are the confirmed exception: they represent the same business product code. Do not assume HT Code or either description is interchangeable with that code.

### Site rules

- `sites` is the local master table read by Site selectors. The repository has no QuickBooks inventory-site export importer, upload route, import script, or site-synchronization migration. `schema.sql` seeds example sites; `frontend/products_sites.php` provides manual create/edit/activate/deactivate/delete operations. The user-confirmed intended upstream source is QuickBooks' exported inventory-site list, but the operational process that loads that export into the local table is not represented or verified in this source tree. Do not infer or replace local contents based on that export without tracing the deployment's actual import procedure.
- Invoice site selection is associated with each invoice line.
- The intended updated design does not rely on a product-level default site.
- QuickBooks export preflight independently requires a successful company-fingerprinted lookup and an exact active Site `FullName`; a local Site value is not itself proof of a QuickBooks mapping.
- Before changing schema or queries involving sites, search the current code for `default_site_id` and confirm the migration's staged cleanup has actually been completed in the target database.

### Amounts and invoice totals

- The invoice preparation page currently computes its displayed sum from saved `invoice_items.amount` values.
- The client invoice detail API likewise calculates `subtotal` by summing saved line amounts.
- Do not assume this is the final QuickBooks invoice total, tax-inclusive total, or total after all discounts/charges. Confirm the actual business rules and schema before changing the label or calculation.
- If the source of the final total is not explicitly represented, label the value accurately as a subtotal/sum of saved line amounts rather than inventing tax or discount rules.

## 5. Authentication, security, and coding conventions

Follow the patterns already used by nearby files.

- Frontend pages generally load the application through `require_once __DIR__ . '/../backend/config/app.php'` or a shared layout that loads it.
- Authenticated pages use `require_login()`.
- Administrator-only client maintenance uses `require_admin()`.
- Product/site management uses the current authorization helper, including `can_manage_products_sites($pdo)` and `require_products_sites_manager()` where appropriate.
- State-changing POST requests should validate CSRF tokens using the existing `verify_csrf()` pattern.
- Use PDO prepared statements for user-provided values. Never concatenate untrusted input into SQL.
- Validate IDs, page numbers, quantities, prices, dates, and status transitions on the server. Browser validation is not a security boundary.
- Keep API responses JSON and use appropriate HTTP status codes for errors.
- Avoid returning raw exception messages or database details to the browser. Log technical details server-side and return a safe message.
- Escape HTML output using the existing `e()` helper.
- Use the existing activity logging conventions for master-data changes.
- Respect existing role and permission behavior. Hiding a button is not sufficient authorization.
- Keep the current frontend design system, shared layout, sidebar, and Bootstrap conventions. Do not add another CSS/JS framework without a clear need.
- Avoid destructive database changes. Do not delete historical products, sites, clients, withdrawals, invoices, or line items just to make a UI change.
- Do not rename tables or columns, change status values, or alter foreign keys without tracing all reads/writes and preparing a safe migration.

## 6. Recent updates already present in the reviewed `main` branch

These are the changes observed in the repository during the handoff review. Confirm the current branch before relying on them.

### A. Dedicated Clients page

- `frontend/clients.php` provides a dedicated Clients screen.
- The shared sidebar contains a Clients navigation link.
- The page supports searching and paginating the client list.
- Clients are displayed as accordion entries.
- Client maintenance actions are restricted to administrators in the page's server-side POST handling.
- Client data remains in the database; moving the UI out of Products & Sites should not delete client records.

### B. Client invoice history API

`backend/api/client_invoice_history.php`:

- Requires login.
- Validates the client ID and checks that the client exists.
- Returns the selected client's invoices, 10 invoices per page.
- Orders history by invoice date and invoice ID descending.
- Aggregates `invoice_items` by invoice before joining the totals to invoice headers, avoiding duplicate invoice header rows due to line-item joins.
- Returns invoice metadata, item count, total quantity, and the sum of stored line amounts.

### C. Invoice details modal and API

`backend/api/client_invoice_detail.php`:

- Requires login and validates invoice/client IDs.
- Checks that the requested invoice belongs to the requested client.
- Loads the invoice header and its saved `invoice_items` rows.
- Joins the site name where available and returns the stored product code/name, description, quantity, unit, price, amount, and tax code fields supported by the query.
- Orders invoice rows by `invoice_items.id`.
- Calculates `subtotal` as the sum of saved `invoice_items.amount`.

The Clients page uses a Bootstrap modal for invoice detail display. Preserve the use of actual invoice rows; do not replace this with withdrawal rows.

### D. Products & Sites separation and mapping work

`frontend/products_sites.php` focuses on product/site master data rather than being the main client-management screen. The current product logic includes separate HT and QB mapping fields and product/site permission checks.

The README documents a staged migration for product/site permissions and mapping cleanup. **Do not assume every migration step has been run in every local database.** The live database must be checked before relying on schema changes or removing legacy columns.

### E. Known follow-up items from static source review

The following issues were identified in the reviewed source and should be addressed if still present:

1. **Client invoice-history Retry behavior:** The Retry action appeared to click the accordion header. Since the accordion is already open, this can collapse it instead of directly retrying the failed request. Retry should call the history loader directly and keep the accordion open.
2. **Invoice-history pagination failures:** Pagination requests should have error handling. If a request fails, the UI should show a useful error and Retry action rather than remaining stuck on “Loading invoice history…”. Preserve the current page when a request fails.
3. **Invoice detail amount label:** The detail API sums saved line amounts but calls the returned field `subtotal`; the UI may label the displayed amount “Invoice Total.” Confirm the displayed label is financially accurate. Do not invent tax or discount rules.

These were static-review findings, not proof that the live application currently reproduces the issues. Check the current source before modifying.

## 7. Database and migration safety

The application README documents XAMPP setup and database upgrade steps. Important rules:

- Back up the existing MySQL database before any migration.
- Do not import `schema.sql` over an existing production or working database; it is for initial setup.
- Check the current migration files and migration history before running a script twice.
- The README mentions an invoice-number migration and `backend/database/migrations/20261015_product_site_permissions.sql`.
- The product/site migration is staged. It calls for reviewing duplicate codes and incomplete mappings, completing authoritative HT mappings, deploying compatible PHP files, verifying that the code no longer reads `products.default_site_id`, inspecting `SHOW CREATE TABLE products`, and only then applying the commented final DDL/unique indexes where appropriate.
- Do not manufacture HT codes or QuickBooks IDs. They must come from authoritative company data.
- Preserve historical transaction IDs and invoice-line `site_id` values.
- Validate the migration against the actual database version and real company records before running any DDL.
- Withdrawal and invoice line quantities are whole units in the current form/controller logic and initial schema. The `20261019_whole_number_quantities.sql` migration checks for fractional historical quantities and stops before DDL if any exist; resolve those records against authoritative data rather than rounding them automatically. Price and kg-per-box fields remain decimal values.

### QuickBooks export, read-only preflight, and bridge status

- Phase 3A typed export tables and Phase 3B signed reference-verification/audit logic exist in `backend/core/quickbooks_exports.php`, with the PHP client in `backend/core/quickbooks_bridge_client.php`. The Admin preview persists immutable local snapshots. Reference preflight is read-only. Exports remain pending previews; the PHP posting service, claim/attempt transitions, and transaction submit client method are absent.
- The Windows bridge is `backend/quickbooks-bridge/Program.cs`; `build.ps1` and `start.ps1` compile and launch it outside the web root. It binds to IPv4 loopback and supports signed `health`, ListID-filtered `diagnose`, and validation-only operations. Its dispatcher rejects transaction-writing operations, and health reports `transactionWritesSupported=false`. No transaction QBXML builder, `InvoiceAddRq`, or `SalesOrderAddRq` execution path remains. Health checks SDK COM registration and process presence only; diagnostics create and close a RequestProcessor connection/session and issue read-only queries.
- Regression tests exercise read-only health, validation, diagnostics, company fingerprint checks, exact Site FullName checks, failure-stage and sanitized diagnostic handling, HMAC authentication/replay protections, and rejection of former write operations even when the legacy write environment variable is true. No QuickBooks transaction has been sent.
- The Admin QuickBooks Export page includes signed mapping verification, exact FullName read-only checks, and pending-preview review. Customer references require a unique active exact-name candidate. Item references require an explicit Admin selection and confirmation of one bounded active candidate; the exact ListID and company are rechecked before signed mapping evidence is stored. This is a direct local-product-to-QuickBooks-ListID approval, not an Item Code/SKU equivalence. Reference verification never automatically approves an unverified reference. Health status does not establish a company session.
- Existing export snapshots, transaction statuses, attempt history, audit events, Site records, and historical QuickBooks transaction IDs are not rewritten by the read-only workflow. No schema migration was made for this change.
- Validation-only previews retain the existing QBSDK 16.0 field-length checks. Invoice U/M is QuickBooks-managed: local invoice U/M is not a local preflight blocker, and no locally maintained U/M is sent to QuickBooks. Since transaction submission is structurally unavailable, actual QuickBooks quantity interpretation with omitted U/M has not been runtime-tested.
- Authentication uses a shared UTF-8 secret of at least 32 bytes. Request HMAC-SHA256 signs the 10-digit Unix timestamp, operation, GUID request ID, and SHA-256 digest of the exact raw JSON body, separated by LF. The bridge enforces 30 seconds of skew and stores replayed GUIDs in LocalAppData. Response HMAC signs timestamp, literal `response`, request ID, HTTP status, and exact response-body digest. PHP uses IPv4 loopback HTTP only, ignores proxies, disables redirects, and validates signed responses. Request/response limits, timeouts, unknown-field rejection, and sanitized errors are enforced. `backend/quickbooks-bridge/local-config.ps1` loads a stable, DPAPI CurrentUser-encrypted configuration into process environment variables for an interactive bridge and a newly launched XAMPP Control Panel/Apache process. Apache and bridge must run as the same Windows account; do not use a Windows service or another account with this local configuration.
- A diagnostic response must contain a `found` boolean; a found record must also contain a name and explicit boolean `active`. Missing, null, or malformed QuickBooks `IsActive` values are not treated as active, and PHP rejects those diagnostic responses.
- **Configured database snapshot (read-only, 2026-10-27):** `withdrawal_qb` contains `qb_exports`, `qb_export_attempts`, `qb_export_reference_verifications`, and `qb_export_events`; the verification table has `company_file_fingerprint`, `diagnostic_request_id`, and `evidence_hmac`. The expected source/status, attempt, audit, and reference uniqueness indexes are present. No export status rows or legacy `qb_jobs` rows were returned by the read-only counts. This session performed no DDL or data changes; the time/source of the schema change since the earlier audit is unknown. No production migration is authorized by this work. Inspect each deployment before migration and test changes on a disposable database.
- An authorized read-only diagnostic requires an administrator to open the intended company file in QuickBooks Desktop and authorize its normal RequestProcessor prompt if shown. Build with `backend/quickbooks-bridge/build.ps1`, identify with `verify-company.ps1`, save the fingerprint only after explicit confirmation, launch with `start.ps1`, and stop with Ctrl+C; do not use a Windows service or terminate another process for port conflicts. The listener at `127.0.0.1:8765` is not proof of SDK/session access. The identity command explicitly opens/closes a read-only session and returns company names and a candidate fingerprint without exposing the company path or bridge secret. QBSDK 16.0 `CompanyQueryRq` supplies `CompanyName` and `LegalCompanyName`; the v2 HMAC fingerprint binds these names to the canonical SDK-reported path. The identity response does not return the company filename; visually confirm `Testing QB.qbw` in the QuickBooks UI before saving the fingerprint. `health()` reports bridge/SDK/process status only. `diagnose($entity, $listIds)` requires the expected fingerprint, opens a session, re-queries the active company identity immediately before the diagnostic, and queries only supplied ListIDs (up to 50; no enumeration). PHP verifies the signed identity fields and fingerprint. The Admin connection check exposes the active company and last successful diagnostic time for the current browser session. Customer and product mapping evidence is saved only after an Admin verification step and a fresh exact ListID/company check.
- **Bridge setup checklist (read-only):** run `backend/quickbooks-bridge/configure-local.ps1` once as the interactive account to generate stable random 32-byte secrets and store them in a DPAPI CurrentUser-encrypted LocalAppData file with a current-user-only ACL. `status.ps1` reports local executable/listener/process/configuration state without an SDK request. Visually confirm `Testing QB.qbw`, then run `verify-company.ps1`; save its SDK-reported candidate fingerprint only with `-SaveFingerprint` and the explicit confirmation prompt. `start.ps1` loads the protected settings, requires the fingerprint and executable, and rejects an existing port-8765 listener without stopping it. Restart XAMPP normally and close its Control Panel, then run `start-apache-with-qb-config.ps1` and start Apache using the Control Panel's non-service action. The wrapper refuses existing Apache/Control Panel processes or an Apache service; it never terminates processes or starts QuickBooks. The Admin Export page reports actual PHP runtime settings and signed health; successful health proves shared authentication but not company authorization. Verify customer, item, tax, and exact Site FullName behavior against the intended company. QuickBooks manages invoice U/M; the application does not send local U/M, and no transaction is submitted. A changed path or company name changes the v2 fingerprint and invalidates prior company-bound reference evidence; repeat identity confirmation and mapping verification.
- **Local runtime check (2026-10-26):** `QBXMLRP2.RequestProcessor` is registered, but no QuickBooks process or listener on port 8765 was running. The four `WQ_QB_*` settings were unset at Process, User, and Machine scopes. The bridge build and self-tests passed; startup correctly refused to run without its secret. No SDK company session or live PHP-to-bridge authentication was attempted.
- **Local runtime check (2026-10-27):** No QuickBooks process or bridge listener on port 8765 was active. `WQ_QB_BRIDGE_URL`, `WQ_QB_BRIDGE_SECRET`, `WQ_QB_COMPANY_FINGERPRINT`, and `WQ_QB_INVOICE_WRITE_ENABLED` were unset at Process, User, and Machine scopes. QBSDK 16.0 documentation is installed at `C:\Program Files\Intuit\IDN\QBSDK16.0\doc\qbxmlops160.xml`; `CompanyQueryRq` supports requesting `CompanyName`/`LegalCompanyName`. The bridge now includes those company names in its candidate identity and requires them for signed diagnostics. This was verified by local self-tests only; no live SDK session was opened.
- **Name-based verification workflow:** the Admin-only `frontend/quickbooks_exports.php` page can explicitly request bounded customer/item name diagnostics; page load itself does not start a company SDK session. The signed bridge uses exact `NameFilter`/`FullName` queries and rejects results over 50; it does not enumerate the master list. Customer matching uses QuickBooks `Name`, with `FullName` and separate `CompanyName` returned for review; CompanyName is not a query filter. Customer verification requires one unique active candidate. For products, the Admin must manually select and affirm the exact intended active item; the controller rejects missing confirmation, candidates outside the bounded result set, inactive records, or any mismatch on the fresh ListID/company check. The original source field or import process for local `Item Code` remains unproven, so Item Code is neither matched to `ManufacturerPartNumber` nor transmitted in InvoiceAdd.
- **Read-only company configuration diagnostics:** the Admin export page now offers explicit CSRF-protected POST actions for SDK company preferences, invoice custom-field definitions, exact inventory-site `FullName`, item U/M, the configured default tax item, and locally stored tax-code references. These use the existing signed, company-fingerprinted `diagnose` operation; page load and health checks still do not open a QuickBooks session. Inventory-site SDK query filters do not support an exact `NameFilter` criterion, so the diagnostic uses `FullName` equality and does not infer a site mapping from local text. Item U/M diagnostics require a stored QuickBooks item ListID, query that item and its referenced `UnitOfMeasureSet`, and return the configured units and sales default; the local SKU is never used as a substitute.
- The `PreferencesQueryRq` diagnostic returns only selected sales-tax, multi-location inventory, lot-tracking, and expiration preference fields. `DataExtDefQueryRq` is restricted to `AssignToObject=Invoice` and reports definitions only. A transaction-level Invoice definition does not prove that Lot Number or Expiration Date can be represented with the required line-level semantics. Neither query establishes company field mappings, tax calculations, or eligibility to post. Diagnostic results are displayed as escaped session data; the last successful company identity and timestamp are retained only in the current administrator's browser session, and no diagnostic is saved as reference-verification evidence.
- The local verification table's active tax-code ListIDs may be queried against the currently fingerprint-verified company; the result is diagnostic only and does not update local tax mappings. No customer, item, site, U/M, tax, or custom-field mapping is automatically approved by these new actions.
- **Latest environment check at that time:** `WQ_QB_BRIDGE_URL`, `WQ_QB_BRIDGE_SECRET`, `WQ_QB_COMPANY_FINGERPRINT`, and `WQ_QB_INVOICE_WRITE_ENABLED` were unset at Process, User, and Machine scopes. Consequently, no live company diagnostic was attempted. The diagnostics were exercised only through signed PHP mock responses, mocked qbXML query responses, and isolated local bridge protocol tests. No QuickBooks record was created; the InvoiceAdd operation remains separately gated and disabled by default.
- **Item Code source trace:** The user has confirmed that local Item Code and QB Code are the same business value. `20261015_product_site_permissions.sql` copies legacy `products.item_code` into `products.qb_code`; `frontend/products_sites.php` writes the same code to both columns; invoice and Sales Order saves use that shared local product code. This does not establish which QuickBooks item field or import process originally supplied it. A read-only check of the configured database on 2026-10-26 found 94 products: 75 nonblank code pairs matched exactly; 18 pairs were both NULL; one pair was both blank; there were no null/blank asymmetries, conflicts, raw differences, or duplicates in either column. The schema has a unique index on `qb_code`, but not on legacy `item_code`. Installed QBXML 16.0 supports item `Name`, `FullName`, `SalesDesc`, and `ManufacturerPartNumber`, but no generic SKU field or SKU filter. Do not assume `ManufacturerPartNumber` is Item Code, or that item `SalesDesc` is invoice-line `Desc`. InvoiceAdd now uses the verified item ListID directly; the local code is retained in the immutable preview only and omitted from the posting payload.
- **Product ListID storage:** The configured database had no populated or blank `products.qb_list_id` values, no duplicate product ListIDs, and no ListID index/constraint. The column is nevertheless read by typed export validation, populated by successful signed reference verification, and copied into Sales Order line snapshots; the export compares it against signed evidence before using it. It is an internal database value and not a staff input. Keep the column until those flows have a reviewed replacement; no removal migration is proposed or applied.
- Lookup results return internal ListIDs automatically but never accept a browser-entered ListID. Item candidates require explicit Admin selection and confirmation; no candidate is automatically persisted. This workflow has automated mock/self-test coverage only; it has not been run against the intended live company. No customer/item mapping from the actual QuickBooks file is verified by the feature's existence alone.
- No customer, item, tax-code/tax-item, or inventory-site mapping has been approved against the intended company file. Site, U/M, Lot Number, Expiration Date, tax behavior/rounding, and invoice/Sales Order reference-field mappings remain unverified. Do not infer support from local fields or a query response.
- The installed SDK 16.0 `doc/qbxmlops160.xml` schema documents `InvoiceLineAdd` fields `InventorySiteRef`, `UnitOfMeasure`, and `LotNumber` (maximum 40 characters), plus line-level `DataExt`; it also documents `DataExtDefQueryRq` for transaction custom-field definitions. The bridge supports read-only custom-field-definition and item U/M diagnostics, but those results do not prove a usable company mapping. For QuickBooks Desktop, `InvoiceAdd` `RefNumber` is limited to 11 characters by this schema. The preview and posting path reject overlength source values; they are not truncated or rewritten.
- The detailed offline test commands and results are recorded in the Offline implementation verification section above. Those tests do not establish real company-file access.

## 8. Local development and testing

The application README describes an XAMPP setup. Typical checks:

1. Confirm the project is placed in the intended `htdocs` folder and `APP_BASE_URL` / `WQ_BASE_URL` matches the local URL configuration.
2. Confirm database credentials and the database name in the current configuration.
3. Use a disposable/test database or backup before testing migrations.
4. Log in with a test account appropriate to the role being tested.
5. Test both an administrator and a normal employee / permission-limited account.
6. Check PHP syntax for every modified PHP file.
7. Test actual browser flows, not only direct API calls.
8. Inspect browser console/network errors and PHP/server logs.
9. Test empty data, multiple pages, invalid IDs, inactive records, and server/network failures.
10. Test a real saved invoice with multiple invoice rows and confirm the modal rows match `invoice_items` exactly.
11. Verify that invoice-specific edits do not modify the original withdrawal.
12. Do not mark QuickBooks integration as tested unless an authorized read-only RequestProcessor session is established against the intended QuickBooks Enterprise company file. The current bridge does not post transactions.

A static source review alone cannot confirm that the application runs against the user's local PHP version, database schema, data, browser, or QuickBooks environment.

## 9. How Codex should work on this repository

At the beginning of a task, Codex should:

1. Read this handoff file.
2. Confirm the checked-out repository, current branch, and working-tree changes. Do not overwrite user changes.
3. Inspect only the files directly involved in the requested task and their immediate dependencies (shared helpers, API/controller, relevant schema/migration, and calling frontend).
4. Search for all references to the affected tables, columns, functions, routes, and status values before changing them.
5. Verify the real schema and current migrations instead of assuming that the local database matches `schema.sql`.
6. Make the smallest safe change that satisfies the request. Avoid broad refactors and unrelated formatting.
7. Preserve the separated frontend/backend architecture and existing UI conventions.
8. Validate server-side authorization, CSRF, input validation, prepared SQL, output escaping, and error handling.
9. Run available syntax checks/tests. If a browser/database/integration test cannot be run, state that explicitly.
10. Review the diff for accidental changes and report:
    - files changed;
    - behavior changed;
    - schema/migration impact;
    - tests actually run and results;
    - tests not run and why;
    - remaining risks or manual steps.

### Do not do these things

- Do not re-analyze every file unless the requested change genuinely requires a broad audit.
- Do not assume a file's existence means the feature works end-to-end.
- Do not fabricate schema columns, functions, endpoints, business rules, or QuickBooks mappings.
- Do not copy invoice detail from `withdrawal_items` when saved `invoice_items` are required.
- Do not silently alter invoice totals, tax behavior, prices, quantities, or status transitions.
- Do not delete or reset database records to make tests pass.
- Do not enable unverified QuickBooks posting.
- Do not report a test as passed unless it was actually run.

## 10. Suggested task prompt for Codex

Paste this after the specific feature or bug request:

> Read `MFC-Operations/PROJECT_HANDOFF.md` first. Treat it as orientation, not as proof that the current code or database is unchanged. Confirm the current branch and working-tree changes, then inspect only the relevant files and their direct dependencies. Search for all references to any affected tables, columns, routes, helpers, and status values before editing. Preserve the frontend/backend separation, existing business rules, historical records, security checks, and QuickBooks integration boundary. Make the smallest safe change. Run the available syntax checks and targeted tests, and clearly distinguish verified results from anything that could not be tested in this environment. Report the exact files changed, tests run, database impact, and remaining risks. Do not perform unrelated refactoring.

## 11. Offline QA findings (2026-10-26)

- Read-only inspection of the configured `withdrawal_qb` schema found the typed export tables, signed-evidence columns, and `qb_export_events` present; legacy `qb_jobs` is intact. The latest `sales_order_items.tax_code` is `VARCHAR(40) NULL`, while the source migration now targets nullable `ENUM('Non','Tax')`. A grouped read-only query found nine Sales Order TAX values that are empty strings; no other value was returned. This schema likely reflects an older nullable-text draft, but its change history is unknown. The current enum migration narrows the accepted values and must not be applied to this database until the target schema and every stored value have been reviewed. No configured-database migration was applied during this QA.
- Sales Order U/M previously trusted a submitted field even though the documented rule is Product Master automation. The save path now takes U/M from the selected active product, and both preparation screens display it as read-only. Sales Order Site remains required; Lot Number, Expiration Date, and TAX remain optional local values. None of these fields is verified as a QuickBooks mapping.
- Client invoice history retry previously toggled the accordion rather than retrying the request, and page-fetch errors left the panel on “Loading”. Retry now directly reloads the failed page, and pagination errors show a retry control. The detail amount label is “Line Amount Subtotal” because it sums stored line amounts and does not include verified tax calculation.
- Verification results for 2026-10-26 (all exit code 0): `C:\xampp\php\php.exe tests\quickbooks_exports_test.php`; `C:\xampp\php\php.exe tests\quickbooks_exports_mariadb_test.php`; `C:\xampp\php\php.exe tests\sales_order_workflow_mariadb_test.php`; `C:\xampp\php\php.exe tests\quickbooks_bridge_http_test.php`; `C:\xampp\php\php.exe tests\quickbooks_bridge_server_test.php`; and `node tests\client_history_ui_test.js`. The Sales Order test exercises the enum migration against a disposable MariaDB database, confirms preservation of an empty enum sentinel and existing labels, tests nullable-text conversion with `Non`, `Tax`, empty string, and SQL `NULL`, and tests controller saves for blank/Non/Tax Sales Order values plus invoice validation/rollback. Both migration tests create uniquely named disposable databases and remove them on completion; a read-only check after completion found no leftover Sales Order test database. The client-history UI test uses a lightweight DOM fixture to verify retry and failed-pagination behavior.
- `Get-ChildItem -Recurse -Filter *.php | ForEach-Object { C:\xampp\php\php.exe -l $_.FullName }` checked 40 PHP files with zero syntax failures; the updated workflow test was linted again separately after its final test additions. The Windows bridge HTTP test compiled `Program.cs` with the x64 .NET Framework compiler and passed its compiled HTTP protocol tests. A separate temporary x64 compilation and `--self-test` also passed. These tests exercise local signed protocol/rejection paths, not a QuickBooks company session.
- Browser visual testing and full browser end-to-end tests for invoice/withdrawal workflows were not run. Tests use local mocks/fixtures or disposable data; none establishes live QuickBooks compatibility.
- Git executable and `.git` metadata were unavailable at `C:\xampp\htdocs\MFC-Operations-main`, so origin, branch, and worktree status could not be verified.
- No configured-database records or schema were changed. No live QuickBooks SDK company session or company-file QBXML request was made; local bridge HTTP tests exercised health/reporting and rejection paths, and self-tests used mocked CompanyQueryRs data only. No transaction write was used. Customer, item, tax, Site, U/M, Lot Number, and Expiration Date mappings remain unverified against the intended company file; transaction posting remains disabled.

### QuickBooks identity and export hardening (2026-10-27)

- PHP export tests, disposable MariaDB export/migration tests, bridge server compile/self-tests, and localhost signed HTTP tests all passed. PHP syntax checks passed for all five modified PHP files. The MariaDB test did not touch the configured database.
### Offline implementation verification

- `C:\xampp\php\php.exe tests\quickbooks_exports_test.php` passed, including mapping-candidate and field-limit checks.
- `C:\xampp\php\php.exe tests\quickbooks_invoice_posting_test.php` passed all mocked lifecycle scenarios: multi-line success with persisted TxnID, sanitized rejection, timeout/unknown without retry, duplicate and competing claims, company mismatch, missing references, unsupported expiration, tax/site/U/M gates, and both disabled-write gates. The fixture also verifies local Item Code is omitted from the InvoiceAdd payload.
- `C:\xampp\php\php.exe tests\quickbooks_bridge_http_test.php` passed signed localhost transport checks. `C:\xampp\php\php.exe tests\sales_order_workflow_mariadb_test.php` passed against a disposable database, confirming the Sales Order workflow remains unaffected.
- `C:\xampp\php\php.exe tests\quickbooks_bridge_server_test.php` compiled the C# bridge and passed signed localhost protocol tests. A separate x64 build using `C:\Windows\Microsoft.NET\Framework64\v4.0.30319\csc.exe /nologo /target:exe /platform:x64 /reference:System.Web.Extensions.dll /reference:System.Xml.dll /reference:Microsoft.CSharp.dll /out:<temp>\QuickBooksReadOnlyBridge.exe backend\quickbooks-bridge\Program.cs`, followed by `QuickBooksReadOnlyBridge.exe --self-test`, passed InvoiceAdd QBXML generation, mock SDK preflight, response parsing, request-ID correlation, and fail-closed cases. No live QuickBooks process was used.
- `C:\xampp\php\php.exe tests\quickbooks_exports_mariadb_test.php` passed against a generated disposable MariaDB database, including simultaneous claim contention and rollback checks. The configured database was not changed.
- PHP syntax checks passed for the modified controllers, core services, UI, and test files. No schema migration was required or added. These checks are offline software evidence only; no live invoice was created, no production posting was attempted, and no browser session or live company diagnostic was used.
- The Admin Export page now shows PHP-side configuration readiness without exposing secret values, distinguishes signed bridge health from a company session, and reports current local invoice tax-choice counts separately from unverified QuickBooks tax compatibility.

### Local connection readiness check (2026-10-30)

- At initial inspection the Windows QuickBooks process and bridge listener were absent. After the administrator reported opening the intended company, a QuickBooks Enterprise Solutions 24.0 window titled `MET FOOD CORPORATION` was observed. This window title agrees with the reported company name but is not SDK-confirmed identity; the legal name and company fingerprint remain unverified.
- `WQ_QB_BRIDGE_URL`, `WQ_QB_BRIDGE_SECRET`, `WQ_QB_COMPANY_FINGERPRINT`, and `WQ_QB_VERIFICATION_SECRET` remained absent from the inspected Process, User, and Machine environments, and no listener was bound to `127.0.0.1:8765`. An authenticated Admin page independently reported `BRIDGE_NOT_CONFIGURED`, bridge authentication missing/too short, expected fingerprint missing/invalid, verification key missing/too short, and PHP writes disabled. The administrator reports secure configuration, but the live PHP page did not receive it. No secret values were read or displayed; no RequestProcessor call was made.
- The latest bridge source was successfully compiled directly with the .NET Framework x64 compiler into the existing LocalAppData bridge install directory. Invoking `build.ps1` directly was blocked by the current PowerShell execution policy; no policy was changed. The bridge has not been started because its required secret is unavailable in this process.
- Read-only inspection confirmed the required export/reference tables exist and are empty: no exports, attempts, audit events, or active signed customer/item/tax-code evidence. Local master data has 816 active clients, 92 active products, and 7 active sites.
- The configured database has two invoices; both fail local invoice-preview validation. Their blockers are missing customer mappings (2 occurrences), item mappings (8), and tax mappings (8). All eight invoice-line tax selections currently read `Non`; this alone does not prove a matching active QuickBooks sales-tax code or accounting treatment. No invoice or QuickBooks data was changed.
- No RequestProcessor session was established, and no SDK-verified company identity/fingerprint or company-specific customer/item/site/U/M/tax/lot diagnostics were obtained. The administrator must make the protected bridge secret available to the interactive bridge process and verify PHP runtime configuration before read-only SDK diagnostics can proceed.

### Company identity diagnostic error detail (2026-10-30)

- `QuickBooksReadOnlyBridge.exe --identify-current-company` now reports a sanitized COM HRESULT (`SDK_COM_XXXXXXXX`) and failing SDK stage instead of collapsing every exception into `COMPANY_SESSION_UNAVAILABLE`. It also distinguishes QuickBooks `CompanyQueryRs` status codes and malformed/missing identity responses. It never echoes exception text, qbXML status messages, or company paths. Session cleanup failures are reported if identity was otherwise read successfully; cleanup does not mask an earlier failure.
- The identity command opens a RequestProcessor connection, begins a session against the currently open company (`BeginSession` mode 2), gets that session's current-file path only in process, selects a negotiated qbXML version, sends a read-only `CompanyQueryRq` requesting `CompanyName` and `LegalCompanyName`, validates/parses `CompanyQueryRs`, ends the session, closes the connection, and releases the COM object. Path is used only as HMAC fingerprint input and is never returned.
- The actual COM/QBXML cause of the reported `COMPANY_SESSION_UNAVAILABLE` was **not established** in this session: QuickBooks was running, but `WQ_QB_BRIDGE_SECRET` and `WQ_QB_COMPANY_FINGERPRINT` were absent from Process/User/Machine environments, so the identify command could not be invoked through the bridge entry point. The supplied generic result contains no HRESULT and cannot distinguish `BeginSession` failure from prior bridge build/version or runtime/environment mismatch. Required next evidence: run the rebuilt executable from the interactive QuickBooks user's protected environment with the bridge secret available; capture only the new sanitized diagnostic code/message. If it reports a QBXML status, retain the numeric status/severity; if it reports an HRESULT, retain the hex value and stage. Do not send secrets, full exception text, qbXML payloads, or company path.
- Final validation: the .NET Framework x64 compiler built the bridge from `Program.cs`; `QuickBooksReadOnlyBridge.exe --self-test` passed, including nested-COM-HRESULT sanitization, QBXML status-code and malformed-response diagnostics, CompanyQuery request/response parsing, and session-cleanup cases. `C:\xampp\php\php.exe tests\quickbooks_bridge_server_test.php` also passed. No live SDK session or transaction was used.

### Follow-up mapping readiness note

- The user reports that customer and product mappings are business-confirmed as correct. That confirmation is not represented as application verification evidence. The earlier read-only aggregate check found 816 active local customers and 92 active products, with zero populated customer/product QuickBooks ListIDs and no active verification records or signed company-bound evidence. Do not enable posting based on that historical count or user statement alone; run the explicit Admin mapping workflow against the intended company and require fresh evidence.
- Tax code/tax item, inventory Site, U/M, Lot Number, Expiration Date, and transaction reference destinations remain technically unverified. Neither UI values nor successful mocked bridge tests establish QuickBooks field support.
Run the added regression test with `C:\xampp\php\php.exe tests\sales_order_workflow_mariadb_test.php`. Like the export migration test, it creates and removes a uniquely named disposable database.

### QuickBooks live-readiness check (2026-11-03)

- Reviewed the current Admin QuickBooks Export page, post/queue/diagnostic/reference-verification controllers, export and bridge clients, C# bridge, typed-export migrations, and invoice-posting tests. The Admin-confirmed, signed, company-bound InvoiceAdd workflow and its UI are already implemented; no application-code change was needed to expose another posting path. The Send action remains gated by the immutable pending snapshot, current source/mapping validation, authenticated company identity, both write flags, an active Admin, CSRF, exact invoice-reference confirmation, and the one-shot claim. Unknown outcomes remain reconciliation-only.
- A read-only transaction against the configured application database confirmed these tables are present: `qb_export_reference_verifications` (13 columns), `qb_exports` (16), `qb_export_attempts` (10), `qb_export_events` (9), `invoices` (16), `invoice_items` (20), `clients` (5), `products` (16), and `sites` (3). Grouped read-only queries returned no reference-verification rows and no typed-export rows. No schema migration or database data change was made.
- The current PowerShell and PHP CLI environments have no `WQ_QB_BRIDGE_URL`, `WQ_QB_BRIDGE_SECRET`, `WQ_QB_COMPANY_FINGERPRINT`, `WQ_QB_VERIFICATION_SECRET`, or `WQ_QB_INVOICE_WRITE_ENABLED`. QuickBooks Desktop and the bridge listener on `127.0.0.1:8765` were not running; only QuickBooks Database Server Manager / Web Connector processes were present. Therefore the PHP web-process and any interactive bridge-process configuration could not be verified.
- No active QuickBooks company filename or SDK-reported identity/fingerprint was obtained; built-in QuickBooks Admin authorization and read-only RequestProcessor access were not tested. No RequestProcessor call was attempted, so SDK error `0x80040418` was neither observed nor ruled out. No company-specific customer, item, site, U/M, tax, or other invoice mapping is verified. Do not enable either write flag based on this check.
- Offline verification on this date passed (exit code 0): `C:\xampp\php\php.exe tests\quickbooks_exports_test.php`, `tests\quickbooks_exports_mariadb_test.php`, `tests\quickbooks_invoice_posting_test.php`, `tests\quickbooks_bridge_http_test.php`, and `tests\quickbooks_bridge_server_test.php`. The MariaDB test uses its disposable test database; the bridge server test compiles/tests local bridge protocol behavior. PHP syntax checks passed for the Admin export page, post/queue/company-diagnostic/reference-verification controllers, and export/bridge core clients. These tests do not establish live QuickBooks connectivity or posting.
- Git metadata and the Git executable were unavailable in this working folder, so branch and pre-existing worktree modifications could not be confirmed. This handoff update is the only intentional file change for this task.
- Before a live test: have the built-in QuickBooks Admin open `Testing QB.qbw` in QuickBooks Enterprise 24.0 and complete the normal SDK authorization prompt if requested; start the bridge interactively with protected configuration; verify the SDK-reported filename/identity and configured fingerprint; run read-only diagnostics and explicitly approve customer, each item, sites, U/M, tax behavior, and any supplied lot fields. Verify the PHP web runtime and bridge each have the expected configuration and keep both write flags disabled until all preflight checks pass. Then prepare one uniquely referenced invoice and present its exact customer, date/reference, items, quantities, rates, sites, tax selections, and expected totals for explicit user approval before a single InvoiceAdd. Reconcile an uncertain result manually; never retry it automatically.

### Local bridge configuration and startup workflow (2026-11-03)

- Added `backend/quickbooks-bridge/local-config.ps1`, `configure-local.ps1`, `verify-company.ps1`, `status.ps1`, and `start-apache-with-qb-config.ps1`; updated `start.ps1` to load and validate the protected config, require an interactive session and confirmed fingerprint, reject an existing listener, and keep bridge writes disabled. `README.md` documents the supported workflow. The Admin Export page now reports PHP URL and verification-key readiness, authenticated shared-secret health, QuickBooks process status, SDK identity/fingerprint status, and safe write readiness.
- `configure-local.ps1` was run once as `MFC-CAMILLE\MET FOOD CORP`. It generated independent random 32-byte bridge and verification secrets and saved them in `%LOCALAPPDATA%\MFC-Operations\QuickBooksBridge\local-config.bin`, encrypted with DPAPI CurrentUser. The file ACL was verified to contain only the current Windows user with non-inherited FullControl. The secrets are not in the repository, were not printed, and were not written to Apache configuration. Re-running configuration did not change the protected file hash.
- Protected settings use `WQ_QB_BRIDGE_URL=http://127.0.0.1:8765`, shared `WQ_QB_BRIDGE_SECRET`, PHP-only-needed `WQ_QB_VERIFICATION_SECRET`, an intentionally blank `WQ_QB_COMPANY_FINGERPRINT`, and `WQ_QB_INVOICE_WRITE_ENABLED=0`. The bridge and wrapper load values into their child-process environments. Apache must be newly launched from the same interactive account's XAMPP Control Panel using `start-apache-with-qb-config.ps1`; the wrapper refuses to stop/replace existing processes or use an Apache service. Existing Apache/XAMPP processes were observed under the same account and were not stopped or restarted.
- The bridge compiled successfully to the existing LocalAppData install directory, and the installed executable's `--self-test` passed. `status.ps1` reported the executable installed, no bridge listener, protected secrets configured, expected fingerprint absent, and invoice posting not ready. QuickBooks Desktop was detected by process name during the status check, but its window title/current company was not confirmed. No SDK session was invoked; the previously reported `SDK_COM_80040418` remains unresolved.
- Both `start.ps1` and the Apache wrapper were invoked as safe startup checks and refused before launching anything because the company fingerprint is unset. No bridge listener or second XAMPP process was started. Signed PHP-to-bridge health could not be tested: there is no listener, and the already-running Apache process has not been relaunched through the wrapper. The Admin Export page is the runtime check after the required restart.
- PowerShell parser checks passed for all seven bridge scripts. Config decrypt/stability, current-user-only ACL, disabled write flag, and secret-free readiness output checks passed. All five offline QuickBooks suites passed: `quickbooks_exports_test.php`, `quickbooks_exports_mariadb_test.php`, `quickbooks_invoice_posting_test.php`, `quickbooks_bridge_http_test.php`, and `quickbooks_bridge_server_test.php`. PHP lint passed for the changed Export page and relevant PHP client/diagnostic controller. These checks do not prove live SDK access.
- The configured invoice/export schema was rechecked read-only; required tables were present. No schema, application database data, company file, or QuickBooks transaction was changed. Branch/worktree status remains unavailable because Git metadata and executable are absent.
- Administrator steps still required: visually open and confirm `Testing QB.qbw` as the built-in QuickBooks Admin; resolve the normal SDK authorization request (if it reports `0x80040418`, authorization must be granted there); run `verify-company.ps1 -SaveFingerprint` and confirm the SDK company names plus the exact file title; stop Apache normally and close XAMPP Control Panel, then launch it via `start-apache-with-qb-config.ps1`; restart the bridge with `start.ps1`; and use the Admin Export page to verify signed PHP health and the current company identity. Leave both write flags at `0`; no live invoice is authorized or prepared by this setup.

### Read-only live verification follow-up (2026-11-03)

- Git metadata and the Git executable remain unavailable, so the active branch and pre-existing worktree state could not be confirmed.
- With the protected current-user configuration loaded, the PHP CLI completed authenticated signed bridge health successfully. The bridge reported SDK registration and a running QuickBooks process. Both PHP and bridge invoice-write flags reported disabled. This CLI check does not establish that the Apache web process has the same environment; the Admin page redirected to login, so its runtime panel was not independently checked.
- A fresh direct `--identify-current-company` SDK request succeeded. The SDK-reported company and legal name were both `MET FOOD CORPORATION`, and its candidate fingerprint matched the protected configured fingerprint. The SDK response does not return the company filename; `Testing QB.qbw` remains visually confirmed by the administrator, not independently identified by SDK. The read-only CompanyQuery succeeded and `SDK_COM_80040418` was not observed in this check.
- A signed PHP-client `company_preferences` diagnostic returned `QB_SESSION_UNAVAILABLE` on two attempts, even though direct identity succeeded. Company preferences and dependent customer/item/site/U/M/tax diagnostics are therefore not verified through the PHP-to-bridge diagnostic worker. The cause of this separate worker/session failure is unresolved; do not treat successful health or CompanyQuery identity as proof that all read-only diagnostics work.
- The database still has no active signed reference-verification evidence or populated customer/product QuickBooks ListIDs, and no typed exports were found in the prior read-only counts. A bounded local-name check found no active customer, product, or site whose name contains “test”; two existing local invoices remain Draft. No invoice or mapping was created or changed. No local draft was chosen as the test invoice.
- Invoice writing remains disabled in both checked PHP CLI and bridge environments. No InvoiceAdd was attempted, no invoice preflight passed, and no QuickBooks transaction was created. No schema or application data was changed.
- Offline regression run: `quickbooks_exports_test.php`, `quickbooks_exports_mariadb_test.php`, `quickbooks_invoice_posting_test.php`, and `quickbooks_bridge_http_test.php` passed. `quickbooks_bridge_server_test.php` could not complete because it hard-codes port 8765 and reached the already-running live bridge rather than its test instance; its PHP-client HMAC assertion failed. The live listener was not stopped or replaced. The installed bridge `--self-test` passed, and signed PHP health succeeded again afterward, confirming the listener remained available.
- Before a controlled test can proceed, resolve the diagnostic-worker `QB_SESSION_UNAVAILABLE` through the normal interactive QuickBooks/bridge setup; log in to the Admin Export page and verify its live PHP configuration; run the signed company-preference and reference diagnostics; identify and explicitly approve the intended customer, item, site, unit, and tax mappings; then prepare a new uniquely referenced invoice without modifying either existing Draft. Present its exact details and validation results for explicit user approval before enabling either write gate and making one InvoiceAdd attempt. If QuickBooks reports an uncertain outcome, reconcile manually without retrying.

### Diagnostic worker and isolated server-test repair (2026-11-03)

- The original signed `company_preferences` failure was traced to an internal request-contract mismatch: the HTTP layer correctly omits `listIds` for company configuration queries, while the child worker required a nonempty ListID array for every entity. Its `FormatException` was caught by a generic handler and returned as `QB_SESSION_UNAVAILABLE`, hiding that the SDK had not been called. The worker now accepts an empty ListID array only for `company_preferences` and `invoice_custom_fields`; reference and name lookups retain their bounded input rules.
- The worker subprocess protocol now sends explicit UTF-8 bytes and reads UTF-8 input with BOM detection. During the live trace, this moved the response from a sanitized worker JSON-parse failure to the actual SDK `BeginSession` result. Worker failures preserve an allowlisted failure stage and, for COM exceptions only, an `SDK_COM_XXXXXXXX` HRESULT; raw exception text and input are not returned. PHP validates these fields, and the Admin diagnostic result displays the stage and sanitized SDK code.
- The server test no longer uses live port 8765. It obtains an ephemeral loopback port and starts the test bridge with `--test-listen-port`; that instance uses a test-specific singleton mutex while production continues to use the original mutex and default loopback port. The signed HTTP test passed on its isolated port. The existing live listener was not stopped, replaced, or modified.
- A new-source temporary bridge was used for one signed, read-only PHP company-preferences diagnostic with both write flags disabled. Signed health succeeded, but the SDK returned `COMPANY_SESSION_UNAVAILABLE` at `begin_session` with sanitized `SDK_COM_80040414`. A fresh direct identity attempt using the same build returned the same HRESULT. A separate manually launched worker probe exceeded 40 seconds and was terminated by its exact PID; whether that interrupted worker affected subsequent SDK session state is unverified. QuickBooks remained running and its visible title was `MET FOOD CORPORATION - Intuit QuickBooks Enterprise Solutions 24.0`; this does not establish a successful SDK session. The current cause/meaning of `0x80040414` is unresolved and must be addressed through normal interactive QuickBooks/SDK troubleshooting; it was not treated as `0x80040418` or used to bypass authorization. No company preferences, company fingerprint, or mapping evidence was returned by this failed attempt.
- Validation passed: an isolated .NET Framework x64 build and C# `--self-test`; PHP syntax checks for the modified client, controller, export page, and test files; `quickbooks_exports_test.php`, `quickbooks_exports_mariadb_test.php`, `quickbooks_invoice_posting_test.php`, `quickbooks_bridge_http_test.php`, and the dynamic-port `quickbooks_bridge_server_test.php`. The MariaDB test used its disposable database. No application schema/data, QuickBooks company data, or invoices were changed. Both checked write flags remained disabled.
- The running bridge binary was deliberately left untouched; the source fix was built and live-diagnosed from a temporary executable so the listener could remain available. Once the administrator can safely stop the bridge from its owning interactive console, rebuild with `build.ps1`, restart with `start.ps1`, and repeat the signed preferences diagnostic. Do not start customer/item mapping or invoice preflight until that read-only SDK session succeeds. Branch/worktree status remains unavailable because Git metadata and the Git executable are absent.

### Admin QuickBooks Export preview UI cleanup (2026-11-03)

- The Admin export page now shows a compact bridge/company/write-readiness summary followed by one combined Invoice/Sales Order list with source reference, type, customer, date, saved line subtotal, status, and a Review link. Subtotals are displayed as saved line sums; tax and invoice totals remain explicitly unresolved where the application does not calculate them.
- Customer/item reference verification, signed lookup/approval forms, detailed mapping readiness, company/runtime status, and read-only company diagnostics are retained in a collapsed, keyboard-operable Advanced Diagnostics disclosure. Existing POST routes, CSRF fields, role checks, company verification, and posting gates were not changed. Existing diagnostic hash redirects still expand the relevant disclosure.
- Transaction preview keeps multi-line detail, validation status/messages, item and site mapping status, local item-code visibility, U/M, site, lot/expiry, tax, and line subtotal. The saved export review and one-shot Send confirmation remain intact; recent historical export records are still available in a collapsed section. Both write flags remain disabled; no QuickBooks or database writes were performed.
- Page-scoped responsive styles were added for the connection summary, disclosures, and preview table. The authenticated Admin page was rendered and inspected at desktop size; the Advanced Diagnostics disclosure was opened and its reference forms were present. A narrow viewport was also inspected and showed wide data tables in their own horizontal-scroll containers. A post-overflow-guard mobile screenshot could not be confirmed because the browser harness reported desktop CSS viewport metrics after resizing. No automated pixel/visual regression suite exists.
- Validation: PHP syntax check for `frontend/quickbooks_exports.php`; `quickbooks_exports_test.php`, `quickbooks_exports_mariadb_test.php`, `quickbooks_invoice_posting_test.php`, and `quickbooks_bridge_http_test.php` passed. No database schema or application data changed.

### Read-only QuickBooks reference discovery

- Added an Admin-only, CSRF-protected `Scan QuickBooks References` action to Advanced Diagnostics. It requires explicit confirmation that `Testing QB.qbw` is open, obtains a nonblocking MariaDB advisory lock to prevent concurrent scans, and sends a signed `diagnose` request through the existing company-fingerprint-bound bridge. Results live only in the Admin's PHP session; no application mappings or QuickBooks records are written.
- The bridge supports bounded iterator-based Customer and Item queries (50 records per qbXML page, maximum 100 each). Reaching the cap is reported as partial; absent candidates from an incomplete scan are not described as missing or unique. Customer Name/FullName, CompanyName/type, item type, ManufacturerPartNumber, and U/M set references are included; long sales descriptions and prices are intentionally not enumerated.
- Other categories retain safe query scopes: inventory sites are exact FullName searches over at most 20 active local sites; item U/M is queried by at most 20 locally stored product ListIDs; tax codes are queried by at most 20 saved tax-code ListIDs; company preferences are read-only. The installed qbXML schema has no safe iterator support for InventorySite or SalesTaxCode, so those groups are not presented as full-company enumerations. Each category reports complete, partial, or failed with a sanitized code.
- The report compares local customer/product names to discovered candidates and also summarizes local site, saved item U/M, and local/signed tax-code references. It shows ambiguity, missing/partial, inactivity, U/M mismatch, local-reference conflicts, or existing signed-mapping status. It never approves or saves a mapping; the existing exact lookup, Admin selection, signed evidence, CSRF, company binding, and invoice preflight remain required. The advisory lock is transient; no database schema or application rows were changed. Invoice writes were not enabled.
- Files changed for this feature: `backend/quickbooks-bridge/Program.cs`, `backend/core/quickbooks_bridge_client.php`, `backend/controllers/qb_reference_scan.php`, `frontend/quickbooks_exports.php`, and `tests/quickbooks_exports_test.php`. No migration/schema changes were made. Offline C# self-tests cover bounded iterator requests and pagination; PHP tests cover scan signing/response validation and scope limits. These tests do not establish live QuickBooks connectivity.
- Live verification remains blocked by the previously observed `SDK_COM_80040414` at BeginSession. The scan has not been run against the live company; the current company, mappings, preferences, and scan results remain unverified. Keep both invoice-write flags disabled.

### Exact FullName invoice references (2026-11-03)

- Added an invoice-only reference mode that supports QuickBooks FullName alongside the existing signed ListID mapping path. A FullName candidate is used only when the corresponding local ListID is blank; a nonempty but unsigned, stale, or mismatched ListID continues to block fallback. Customer candidates come from the local customer name. Item candidates come from the saved invoice Item Code, falling back only to the current Product Master QB Code when the saved code is blank. Product descriptions/names are not used to infer FullName.
- Candidate values are stored in the existing immutable `snapshot_json` as distinct `customer_full_name` and line `item_full_name` fields; their absence/presence distinguishes the legacy ListID mode from FullName mode. The database already has the required `clients.qb_list_id`, `products.qb_list_id`, `invoice_items.item_code`, and signed reference-verification columns. No migration or database updates were made.
- When a FullName-mode invoice is queued through the Admin controller, a signed read-only company preflight checks the selected invoice's customer, distinct items, tax codes, sites, U/M, and company preferences. It accepts only one active QuickBooks record whose `FullName` exactly equals the candidate; `Name` alone and hierarchy-mismatched names are rejected. This queries only references used by that invoice; the broad reference scan is not required. A failed or unavailable SDK query blocks queue creation.
- The saved invoice still undergoes current preflight before Send is enabled. The Send form additionally requires Admin confirmation of the displayed FullName references and the exact invoice reference. The bridge independently repeats exact FullName lookup, verifies uniqueness/activity and the configured company fingerprint, resolves references to ListIDs, then generates InvoiceAdd XML using those ListIDs. Existing signed mapping approval, company binding, CSRF/role checks, accounting validation, write flags, duplicate protection, and unknown-outcome reconciliation remain in place. No mappings are approved or persisted by FullName lookup.
- Offline checks covered ListID compatibility, candidate-mode snapshots, exact/hierarchical FullName handling, duplicate FullName rejection, active-reference preflight, multi-line invoices, and the existing posting safeguards. The bridge source was compiled to a temporary test executable and its offline self-test passed. The deployed bridge binary was not replaced or restarted.
- No live SDK FullName query or InvoiceAdd was performed. The last confirmed live SDK blocker remains `SDK_COM_80040414` at BeginSession, and the current company-specific FullName results are unknown. Both invoice-write flags must remain disabled until a successful normal SDK session, company fingerprint match, mapping/accounting preflight, and explicit test-invoice approval. Rebuild and restart the bridge under the normal interactive QuickBooks account before using this source change; do not stop or replace a running bridge without administrator coordination.
- Changed files: `backend/core/quickbooks_exports.php`, `backend/core/quickbooks_bridge_client.php`, `backend/quickbooks-bridge/Program.cs`, `backend/controllers/qb_export_queue.php`, `backend/controllers/qb_export_post.php`, `frontend/quickbooks_exports.php`, `tests/quickbooks_exports_test.php`, and `tests/quickbooks_invoice_posting_test.php`.

### QuickBooks-managed invoice defaults and current posting blockers

- New invoice previews use the explicit `quickbooks_managed_defaults` mode; snapshots created before this mode remain `legacy_mapped`. Managed mode omits per-line SalesTaxCodeRef and checks company default tax references, but this is not evidence that QuickBooks applies the intended tax to an InvoiceAdd. The preview continues to mark live tax behavior as unverified. Legacy snapshots still require their explicit signed tax-code mappings.
- Invoice rates and amounts remain the validated local values; no price-level or QuickBooks default-price behavior is inferred. The later `Use MFC invoice rates, including explicit zero` update supersedes the earlier zero-rate blocker: explicitly sourced `mfc_entered` and `explicit_zero` rates (including legacy MFC-entered `0.00`) are supported when the saved amount reconciles. Blank/pending prices and unresolved historical pricing remain blocked. A QuickBooks pricing lookup/default workflow has not been implemented or verified.
- This historical note about invoice U/M preflight is superseded by the 2026-11-06 business-rule update below: QuickBooks manages invoice U/M, and local U/M is neither required nor sent. Exact active inventory Site resolution remains mandatory.
- The exact Item Code / QB Code candidate is treated as a QuickBooks FullName candidate, not as a ListID. Description and product-name fallbacks are prohibited. Existing signed company-bound Admin mapping approval and the posting protections remain unchanged. Invoice writes remain disabled; no QuickBooks transaction or record was written.
- At the time of this read-only check, invoice `LF11651` contained seven zero-rate/amount lines, tax label `Non`, local Site `High Tower Cold Storage`, and no local U/M; its preview reported seven `ZERO_RATE_UNAPPROVED` blockers. The later explicit-zero update superseded that validation behavior without changing saved prices. These local values do not verify the customer, item, Site, U/M, tax, or resulting accounting treatment in QuickBooks.
- The operator's current task context reports that QuickBooks Admin authorization and a direct read-only CompanyQuery succeeded, the identity/fingerprint matched, and the company identity was MET FOOD CORPORATION. This task did not rerun an SDK query. The previously reported signed PHP `company_preferences` result was `QB_SESSION_UNAVAILABLE`; this task did not establish its cause or verify that it is resolved. No fresh preferences, item/customer/site/U/M/tax mapping queries, or InvoiceAdd were performed. Earlier dated QA notes describing missing configuration or SDK errors are historical and must not be used as current connection status.
- Offline changes in this work segment: managed-default mode handling and strict contract validation in `backend/core/quickbooks_exports.php`, `backend/core/quickbooks_bridge_client.php`, and `backend/quickbooks-bridge/Program.cs`; mode propagation in `backend/controllers/qb_export_queue.php`; preview labels/status in `frontend/quickbooks_exports.php`; and regressions in `tests/quickbooks_exports_test.php` and `tests/quickbooks_invoice_posting_test.php`. No schema change or database write was made. Tests cover managed/legacy tax handling, explicit-zero compatibility and pending/unresolved price blockers, exact Item Code/QB Code candidates, unit-set requirements, and existing posting controls.
- Validation passed: PHP syntax checks for all changed PHP files; `tests/quickbooks_exports_test.php`, `tests/quickbooks_exports_mariadb_test.php`, `tests/quickbooks_invoice_posting_test.php`, `tests/quickbooks_bridge_http_test.php`, and `tests/quickbooks_bridge_server_test.php`; and an x64 C# bridge compile plus `--self-test` in a temporary directory. The bridge was not deployed/restarted, the server test used an isolated dynamic port, and no QuickBooks transaction was created. The MariaDB test used its disposable test database; application data/schema were not changed.
- Before any test InvoiceAdd, resolve the PHP `company_preferences` diagnostic, verify current company identity/fingerprint and all exact customer/item/Site/U/M/tax references through the signed read-only bridge, confirm each invoice line's saved price provenance and amount (including explicitly sourced zero rates), inspect the exact invoice preview, and obtain explicit approval for that specific invoice. Blank/pending and unresolved historical prices remain blockers. Do not retry an uncertain InvoiceAdd; reconcile by QuickBooks transaction identity first.

### Invoice price provenance and fail-closed pricing (2026-11-03)

- Invoice creation no longer copies `products.default_price` into new invoice lines. A blank Unit Price is stored as SQL `NULL` with `price_source='quickbooks_pending'`; a positive price entered in the invoice form is stored as `mfc_entered`; explicit `0.00` is stored as `explicit_zero`. Prices and line amounts use integer-cent calculations in the save controller. No blanket free-of-charge approval was added.
- Added `backend/database/migrations/20261103_invoice_price_provenance.sql` and updated `backend/database/schema.sql`: invoice line `price_each` and `amount` may now be NULL, and a bounded source state distinguishes MFC-entered, QuickBooks pending/verified, explicit zero, and unresolved history. At migration time, all eight existing invoice lines were marked `unresolved` while their prices and amounts were preserved. A later read-only check on 2026-11-03 found seven `mfc_entered` lines and one `unresolved` line; those subsequent source-state changes were not made by this task.
- Historical ambiguous prices are not inferred from `0.00` or a stored nonzero amount. The invoice editor displays the stored historical price as unresolved; an Admin must re-enter the price and explicitly check the confirmation control before it can become MFC-entered. The save controller enforces this authorization server-side. Leaving the value blank preserves the original unresolved price and amount. Clearing a tracked price saves a pending blank; new lines stay blank rather than inheriting Product Master defaults.
- At the time this note was written, export validation accepted only an MFC-entered nonzero price; the later `Use MFC invoice rates, including explicit zero` update supersedes that rule. Current validation accepts reconciled `mfc_entered` rates, including legacy `0.00`, and correctly sourced `explicit_zero`; pending/blank, unresolved historical, and unverified QuickBooks-price states remain blocked. The signed PHP/C# payload carries the price source and validated rate/amount.
- The Export Preview shows effective rate, source, and amount. QuickBooks-derived pricing is **not implemented as a verified fallback**: the existing bridge has no targeted effective-price query, and Item SalesPrice alone does not establish a customer's applicable PriceLevel or U/M basis. The local QBSDK 16 schema documents customer PriceLevelRef and standard item sales-price fields, but a safe effective-price candidate cannot be declared without targeted read-only SDK evidence and validation of price-level, currency, quantity, and U/M behavior. No SDK price query or InvoiceAdd was performed.
- Read-only investigation on 2026-11-03 found no exact `LF11651` match in invoice numbers, withdrawal numbers, invoice/withdrawal text references, activity-log details, or `qb_exports` snapshots. The configured CLI database is `withdrawal_qb`; `WQ_DB_*` variables are unset at Process/User/Machine scopes, and `backend/core/database.php` defaults to that database. The active Apache page showed invoice ID 13 and the same customer/date/seven-line transaction returned by CLI; a second local MFC installation also resolves to `withdrawal_qb`. The Apache page does not directly disclose `SELECT DATABASE()`, so its database identity is corroborated by the matching data and shared defaults rather than a dedicated Apache database-name endpoint.
- Invoice ID 13 is currently `LF12000`, for AYIE STORE dated 2026-10-22, with seven lines matching the item codes and quantities in the historical `LF11651` description, local Site `High Tower Cold Storage`, and tax label `Non`. Its current line rates are all `$10.00` with `price_source='mfc_entered'` and subtotal `$650.00`, not the historical zero-priced preview. Activity records contain no `LF11651` reference and no invoice-number history, so a renumbering relationship is plausible but unproven; do not treat ID 13 as `LF11651` or reprice it without new explicit direction. No associated export snapshot exists; `qb_exports` is empty.
- A fresh signed, read-only `company_preferences` diagnostic from the Admin page succeeded on 2026-11-03 at 14:14 +08:00. It reported active and legal company names `MET FOOD CORPORATION`, matched the configured fingerprint, and returned company preferences. `SDK_COM_80040418` was not reproduced. QuickBooks Desktop, Apache, and the bridge were running; PHP and bridge invoice-write settings reported disabled. The SDK identity does not return the `.qbw` filename, and the QuickBooks window title did not show it, so this check does not independently confirm that the currently open file is `Testing QB.qbw`.
- The hybrid price lookup remains unimplemented: the current signed bridge/client has no `invoice_price_lookup` operation, and its submit contract intentionally rejects `quickbooks_verified` without an evidence contract. No customer/item pricing, price-level, currency, or unit-basis query was made. The selected invoice's customer/item mappings still require Admin verification, and the page reports Site, U/M, and tax mapping/calculation blockers. No QuickBooks price or transaction record was queried or changed, no invoice was queued, and no schema/data change was made during this investigation. The running bridge executable was neither replaced nor restarted.
- Validation on 2026-11-03: QuickBooks export, invoice-posting, isolated MariaDB migration, bridge HTTP, isolated bridge-server, and invoice workflow tests passed; relevant PHP syntax checks passed; the x64 bridge compiled in a temporary directory and all offline self-tests passed. These offline tests do not establish live price-level calculations or enable fallback pricing.
- Validation passed: PHP syntax checks; export and posting tests; isolated MariaDB migration tests (including preservation of old zero and nonzero prices and acceptance of blank values); invoice-save workflow tests for MFC-entered, blank/pending, explicit-zero, unresolved-history preservation, rejection without Admin confirmation, and successful explicit Admin confirmation; bridge HTTP/server tests; and isolated x64 bridge compile/self-tests. The live application database migration was verified by hashing all `(id, price_each, amount)` values before/after; eight historical lines retained their values and are marked unresolved. No QuickBooks record was read or written during this pricing task. The installed bridge was not replaced or restarted, and invoice-writing flags were not enabled.

### Use MFC invoice rates, including explicit zero

- Invoice export validation now accepts saved `mfc_entered` rates and `explicit_zero` rates, including legacy MFC-entered `0.00`. It continues to distinguish and block blank/pending prices and unresolved historical pricing. Explicit-zero provenance must still match a zero rate; every accepted saved amount is checked against quantity multiplied by rate. Sales Order zero-rate rules were not changed.
- The protected invoice submit payload carries the saved rate and price source. The bridge accepts a zero rate only when the line amount reconciles to zero and serializes both `<Rate>0.00</Rate>` and `<Amount>0.00</Amount>` in line order. No QuickBooks item/customer price lookup or Price Level is used.
- The invoice editor displays explicit zero as `0.00` with valid status. Existing unresolved stored prices are not rewritten; an Admin may confirm an existing value only for the current invoice revision. The revision is hashed from the invoice header and ordered line data and is verified server-side with the existing Admin role and CSRF protections.
- No migration, schema change, historical price update, QuickBooks write, or write-flag change was made. The bridge source was compiled to a temporary x64 executable and offline self-tests passed; the installed bridge was not replaced or restarted. A real InvoiceAdd and live company-specific mapping/preflight remain unverified. Keep both invoice-write flags disabled.
- Validation passed: PHP syntax checks for all changed PHP files; QuickBooks export preview, invoice posting, invoice workflow, and isolated MariaDB migration tests; signed bridge HTTP tests; compiled bridge server tests using a dynamic isolated port; and isolated x64 C# compilation/self-tests. Coverage includes positive, explicit/legacy zero, blank and unresolved historical pricing; mixed and multiple lines; amount and QBXML Rate serialization; exact FullName references and fingerprint checks; duplicate/no-write gates; historical preservation and revision-bound Admin confirmation; and unknown-outcome protections. These tests are offline/isolated and do not establish live QuickBooks mapping or InvoiceAdd success.

### FullName reference query correction (2026-11-03 follow-up)

- The installed QBSDK 16.0 operation specification lists `StartsWith`, `Contains`, and `EndsWith` as the supported `NameFilter.MatchCriterion` values; it does not support `Equal`. The bridge had emitted `Equal` for the first customer/item name query and stopped on any unsuccessful response before reaching its exact `FullName` query. The query now uses the supported `StartsWith` criterion; PHP still requires an exact FullName match before resolving the reference.
- A nonzero numeric status from a customer, item, or Site name query is now exposed only as `QBXML_QUERY_STATUS_<code>` with the allowlisted `reference_name_query` stage. QuickBooks response text is not returned. Other malformed or status-less failures remain generic and sanitized.
- The latest live read-only diagnostics in this task remained blocked at `begin_session` with `SDK_COM_80040418`; no live lookup has confirmed the repair or established current customer/item/Site mappings. The SDK-reported identity and fingerprint do not independently verify the `.qbw` filename.
- Validation passed: isolated x64 bridge compilation and `--self-test`, `quickbooks_exports_test.php`, `quickbooks_invoice_posting_test.php`, `quickbooks_bridge_http_test.php`, and `quickbooks_bridge_server_test.php`. No database or QuickBooks records changed; no InvoiceAdd was attempted, no invoice-write setting changed, and the installed bridge was not replaced or restarted. Continue to keep both invoice-write flags disabled.

### FullName preflight follow-up (2026-11-04)

- Source review confirmed the earlier QBXML correction is already present: customer/item name queries use the supported `StartsWith` filter, then accept only an exact `FullName`; Site lookups use a bounded exact `InventorySiteQuery`; PHP keeps failed queries distinct from not-found, inactive, or mismatched records. `customerTaxCodeEnabled` is not required for these reference lookups. Only verified disabled or unknown multi-location preferences block Site-dependent invoice checks.
- The user-reported signed company-preferences diagnostic returned `multiLocationAvailable=true`, `multiLocationEnabled=true`, `customerTaxCodeEnabled=null` (unknown), and the configured references `Tax`, `Non`, and `Output Tax`. These values do not verify tax calculation or posting behavior. The exact current-company results for `AYIE STORE`, the seven item candidates, and `High Tower Cold Storage` were not queried in this task.
- Added posting-test cases assert that item not-found/inactive results remain distinct and that different per-item failures each appear in the summary, while shared SDK transport failures still collapse to one root-cause message. Tests passed: `C:\xampp\php\php.exe tests\quickbooks_invoice_posting_test.php`; PHP syntax validation passed for that test.
- Also passed `C:\xampp\php\php.exe tests\quickbooks_exports_test.php`, `C:\xampp\php\php.exe tests\quickbooks_bridge_http_test.php`, `C:\xampp\php\php.exe tests\quickbooks_bridge_server_test.php`, and an isolated x64 compilation followed by `QuickBooksReadOnlyBridge.exe --self-test`. These are offline/mock tests, not live SDK verification.
- No production bridge source change, database/schema change, QuickBooks record change, invoice queue/post, or write-flag change was made. This process had no PHP bridge secret or expected company fingerprint configured. The running bridge was not stopped, restarted, or overwritten; whether its loaded executable contains the current source correction remains unverified. A coordinated deployment and fresh read-only SDK diagnostics are still required before treating live FullName/Site preflight as verified.

### Site provenance and QuickBooks-managed invoice U/M (2026-11-06)

- The user confirmed the intended Site source is QuickBooks' exported inventory-site list. Source inspection found no importer, upload workflow, import script, or migration that consumes that export. The current repository seeds example rows in `backend/database/schema.sql`; subsequent in-application changes are manual CRUD in `frontend/products_sites.php`, and selectors read active local `sites` rows. The deployment-specific process that imports the QuickBooks export into the local table is unknown. No Site selector, local table contents, or historical references were changed.
- QuickBooks manages invoice Unit of Measure. Local invoice U/M no longer blocks preflight, is not added to the submit payload, and is omitted from InvoiceAdd QBXML. Item reference and requested quantity remain present. Pending snapshots remain immutable and retain their original local U/M evidence; no historical snapshot or invoice row was rewritten. Sales Order U/M behavior is unchanged.
- PHP now accepts the allowlisted `reference_name_query` failure stage so signed unavailable Site responses preserve their original diagnostic and stage. Authentication checks remain mandatory; failed Site lookups still block preflight, exact active Site `FullName` checks remain in force, and no reference approval or write gate was bypassed.
- Offline regression tests cover correctly signed and invalidly signed Site-query failures, preserved failure diagnostic/stage, no successful Site verification on query failure, no local U/M preflight blocker, omission of local U/M, and preservation of item identity and quantity. These tests do not establish how a real QuickBooks company interprets quantity when InvoiceAdd omits U/M; runtime behavior remains untested. No QuickBooks transaction was posted and no InvoiceAdd/SalesOrderAdd was executed against QuickBooks.
- Validation passed: `C:\xampp\php\php.exe tests\quickbooks_exports_test.php`, `tests\quickbooks_invoice_posting_test.php`, `tests\quickbooks_bridge_http_test.php`, and `tests\quickbooks_bridge_server_test.php`; PHP syntax checks passed for all modified PHP files. `Program.cs` compiled to a temporary x64 executable and its `--self-test` passed. Tests use mocks/local fixtures; no configured database, QuickBooks session, or transaction was used. No schema or historical data changed, and invoice-write flags were not enabled.

### Inventory Site response parsing (2026-11-06)

- Corrected the read-only `InventorySiteQueryRq` response parser to match QBSDK 16: the query still filters by the requested exact `FullName`, but no longer requests an unsupported top-level `FullName` return element. It requests `Name`, `IsActive`, and `ParentSiteRef`; the bridge reconstructs a top-level FullName from `Name` and a child FullName from `ParentSiteRef.FullName + ":" + Name`. Missing or ambiguous parent/name data is unavailable, and inactive status remains distinct. PHP continues to require exact FullName equality and active status before recording verification.
- Failed Site-name queries now carry bounded QBXML status code, allowlisted severity, sanitized status message, operation, and failure stage. The message sanitizer redacts the queried reference, known company names, filesystem paths, XML markup, and control characters. No raw QBXML response is returned. PHP validates and propagates these fields to read-only diagnostics and preflight error metadata.
- Offline bridge tests cover top-level, child and multilevel Site hierarchy; inactive Sites; missing parent/name; exact and nonmatching FullNames; sanitized QBXML failure details; and fingerprint mismatch blocking. PHP preflight coverage confirms signed diagnostic details survive while failed queries still create no Site verification and remain blocked.
- Validation is offline/mock only. The actual QuickBooks response for the reported request, active company identity/settings, and live behavior of `ParentSiteRef.FullName` still require a coordinated read-only query against the intended company. No Site records or database data changed, no transaction was posted, and no transaction-add request was introduced.
- A follow-up preflight on 2026-11-06 still returned generic `QBXML_QUERY_FAILED` at `reference_name_query`. Local deployment inspection found the installed executable under `%LOCALAPPDATA%\MFC-Operations\QuickBooksBridge` dated 2026-11-04, older than the corrected source; the port-8765 listener reports PID 4. The running bridge binary and actual QBXML response were not verified, and the listener was not restarted or replaced. The invoice preflight display now includes sanitized QBXML operation/status/message fields when returned by the updated bridge. Deploy/restart the bridge through its supported procedure and rerun a read-only Site query before interpreting further generic failure output.

### PHP web fingerprint configuration diagnosis (2026-11-04)

- Confirmed root cause: the existing DPAPI CurrentUser-protected file under `%LOCALAPPDATA%\MFC-Operations\QuickBooksBridge\local-config.bin` contains a valid expected fingerprint, but `status.ps1` only decrypts it in its own PowerShell process. `start.ps1` independently imports the protected settings into the bridge process. PHP's `QuickBooksBridgeClient` correctly reads `WQ_QB_COMPANY_FINGERPRINT` from the Apache/PHP process environment and fails closed if absent or malformed.
- A temporary, loopback-only PHP marker endpoint returned `false` for presence/validity of the PHP bridge URL, bridge secret, expected fingerprint, and verification secret, and `false` for invoice writes enabled. The temporary file was immediately deleted. The protected-config status command reported the fingerprint configured and valid. This confirms process-environment propagation failure rather than a bad or regenerated fingerprint.
- The running Apache process was a child of the XAMPP Control Panel, which had been started from Explorer; Apache was not registered as a Windows service. The supported `start-apache-with-qb-config.ps1` launcher was therefore not used for this Control Panel process. That launcher decrypts the existing settings, injects them only into its own process tree, and starts a fresh Control Panel; it refuses to stop or replace a running instance. Environment variables are not global and restarting only the bridge cannot update an already-running Apache process.
- No PHP source, bridge source, protected setting, database/schema, QuickBooks record, or invoice setting was changed. The application already shows only safe configuration booleans on the Admin Export page. The fix is to stop Apache normally in the XAMPP Control Panel, close the Control Panel, run `powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\xampp\htdocs\MFC-Operations-main\backend\quickbooks-bridge\start-apache-with-qb-config.ps1"` as the same interactive Windows user, then start Apache with the Control Panel's normal non-service Start action. Do not run this launcher while Apache or the Control Panel is open; it intentionally refuses.
- Verification against the still-running Apache confirmed its PHP environment is missing all protected bridge settings. The post-restart PHP status and signed Active company/session diagnostic have not yet been rerun. No QuickBooks SDK request was made by the temporary marker check. Existing automated tests cover missing and malformed fingerprints, configured signed requests, company-fingerprint mismatch, HMAC authentication, and zero-price/export safeguards; they remain offline/mock evidence. Invoice writes remain disabled.

### Transaction-writing capability retired (2026-11-06)

- The current PHP source no longer has `submitInvoice()`, the invoice posting/claim/attempt transition services, or a write request builder. The legacy `qb_export_post.php` endpoint now only redirects with a warning and cannot access the database or bridge. The Admin export and invoice-preparation screens clearly describe preview-only behavior.
- `Program.cs` now dispatches only `health`, `validate`, and `diagnose`; production InvoiceAdd request construction, execution, and response-handling code has been removed. The former `WQ_QB_INVOICE_WRITE_ENABLED` value cannot restore a write route. Health responses declare `transactionWritesSupported=false`, which PHP requires.
- Read-only reference checks keep pending export snapshots unchanged and do not increment attempt counts or add attempt/event rows. Existing transaction statuses, attempt history, audit events, Site records, and historical QuickBooks transaction IDs are retained. No migration or database update was made.
- Offline PHP, bridge HTTP, and compiled bridge tests verify the read-only workflow and rejection of former `submit`, `InvoiceAdd`, and `SalesOrderAdd` operations even when the legacy flag is set. No QuickBooks transaction was sent.
- The source and test executable were not deployed to the existing bridge listener. The earlier inspection found the listener owned by PID 4 and the installed binary older than the corrected source; its current capabilities are not established here. Coordinate a supported deployment/restart and verify the updated binary before claiming the running bridge is structurally read-only. Do not stop or replace the PID 4 listener from this task.
- Runtime quantity interpretation by QuickBooks when a transaction omits local U/M remains untested. No transaction submission endpoint or QBXML writer exists in the current source, so this behavior requires a future, separately authorized runtime test if transaction writing is ever reintroduced.

### Bridge server-test replay-cache isolation correction (2026-11-06)

- Root cause: the server test set PHP's `LOCALAPPDATA`, but the bridge resolves its production replay cache using `.NET Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData)`. The child therefore continued to use the current Windows profile's installed cache.
- Production startup still uses the fixed `%LocalAppData%\MFC-Operations\QuickBooksBridge\replay-cache.txt` path. It is not redirected by an environment variable or request field.
- The test listener now requires an explicit replay-cache path. The bridge accepts it only when the executable and a new `replay-cache.txt` are in the same unique direct child of the system temp directory, the directory name matches `mfc-qb-bridge-<16 hex characters>`, no path component is a reparse point, and the test path cannot overlap the production bridge directory. A path-only test mode confirms the resolved child-process path and exits before opening a cache or starting a listener.
- `quickbooks_bridge_server_test.php` verifies its own expected cache path and the child resolver's output before starting the listener, then checks the listener child again before any HTTP request. Cleanup is limited to the exact test-owned temporary directory and recognized test files. The test no longer tries to redirect .NET through `LOCALAPPDATA`.
- Offline validation passed: C# `--self-test`, the child path-only preflight, `quickbooks_bridge_server_test.php`, `quickbooks_bridge_http_test.php`, `quickbooks_exports_test.php`, and `quickbooks_invoice_posting_test.php`. The server test covered replay rejection, HMAC, timestamps, request validation, and rejection of transaction-writing operations. No production bridge was started, rebuilt, deployed, or contacted.
- The installed replay cache was **not restored**. It remains at the previously recorded post-incident fingerprint (539 bytes; SHA-256 `FB64FEEA9B94F903E3223E8B78C012862EE585CE37CF0CCB4A84535298D59247`) and was unchanged during this correction and its tests. The earlier 49-byte baseline remains unrecoverable from the available evidence; do not overwrite or clear the current cache.
- The current listener check found no TCP listener on port 8765, no matching HTTP.sys request queue or URL reservation, and no bridge process or service. This does not identify the earlier PID 4 listener's former application; no listener or HTTP.sys state was changed.

### Replay-cache integrity and deployment compatibility audit (2026-11-06)

- Read-only inspection found the installed replay cache to be 539 bytes, strict UTF-8 without a BOM, with 11 complete CRLF-delimited records. All records had the expected 36-character GUID, delimiter, and 10-digit expiry structure; no malformed, duplicate, or incomplete records were found. All 11 expiry values were in the past at inspection time. The file hash remained `FB64FEEA9B94F903E3223E8B78C012862EE585CE37CF0CCB4A84535298D59247`; the original 49-byte content remains unrecoverable and was not restored.
- `ReplayCache.TryUse` stores only request GUIDs and Unix expiry seconds. It reads lines after request ID, timestamp, and HMAC validation; retains unexpired entries; detects duplicate IDs; then writes a same-directory temporary file and uses `File.Replace` or `File.Move`. Startup constructs the cache object but does not open it. Malformed contents throw before replacement; the bridge does not silently reset them. No explicit file lock exists; the production singleton and sequential request loop serialize the normal production path.
- The server-test isolation fix prevents ordinary production startup from redirecting the production cache, and the isolated path is checked by the child before listener startup. However, the test-mode switches are present in the production source, path validation is subject to a local filesystem time-of-check/time-of-use race, and the executable is not cryptographically identified as a test build. The cache remains confined by the checked path during normal use, but this mechanism is not a hardened boundary against a same-user process able to replace temporary-directory entries.
- The staged executable predates the replay-cache test-isolation source change. Its metadata is not proof of source equivalence; the installed executable also has no retained build provenance. The replay serialization code itself remains the same in current source, but exact installed-binary compatibility is unverified.
- This audit performed metadata and read-only content inspection only. No tests, bridge executable, database, HTTP request, or QuickBooks query were run. Port 8765 had no TCP listener, HTTP.sys request queue/URL reservation, bridge process, or bridge service at the final check. No code, configuration, executable, cache, database, process, or HTTP.sys setting was changed.
- Deployment remains blocked pending separate review of the test-mode hardening and installed-binary provenance. Preserve the current cache; do not reset it. Since all current entries are expired, the next accepted signed request may atomically replace them with a fresh replay record as part of normal operation.

### Separate bridge production and test builds (2026-11-06)

- `backend/quickbooks-bridge/build.ps1` now creates staging executables only. Its default production output is `%LocalAppData%\MFC-Operations\QuickBooksBridge-Builds\Production`; an explicit `-OutputDirectory` supports a new, empty staging directory. It refuses to write into or through a reparse point overlapping the installed bridge. `start.ps1` and `verify-company.ps1` continue to use only the installed executable; building does not install or deploy it.
- Test compilation requires `-TestBuild` and a unique direct child of the system temp directory. The build directory receives a protected ACL granting FullControl only to the current user and LocalSystem. `BRIDGE_TEST_BUILD` conditionally compiles test-only arguments and self-tests; production compilation retains its fixed replay-cache path and does not contain the test marker or test command-line switches.
- The server test confirms the test executable's cache path before listener startup, runs offline self-tests, refuses production-style test startup, disables diagnostics in the test build, and exercises authenticated loopback requests on a dynamic port. `--preserve-test-build` retains the test executable for inspection; the replay cache is still removed. The default test run removes its unique temporary directory.
- Validation passed: `php tests\quickbooks_bridge_server_test.php --preserve-test-build`, the default `php tests\quickbooks_bridge_server_test.php` (including temporary-directory cleanup), `php tests\quickbooks_bridge_http_test.php`, PHP syntax validation, and PowerShell parser validation for the bridge scripts. The production staging executable was inspected for test-only markers and was **not executed**.
- Staging artifacts from this validation: production executable `C:\Users\MET FOOD CORP\AppData\Local\MFC-Operations\QuickBooksBridge-Builds\Production-Staging-20261106-113321-fec97575\QuickBooksReadOnlyBridge.exe` (SHA-256 `AC3AD9B919338F12851AB36ABE8A52BD9ACDD276EC2A029D8B0281BCE2DCB6D7`); test executable `%TEMP%\mfc-qb-bridge-3cc3772adc880a63\QuickBooksReadOnlyBridge.exe` (SHA-256 `3E0E1374186231DD829AD645BFE6EB826BD654EBB6C655899A4327CC2E0035EB`). The test directory ACL was verified; its replay cache is absent.
- The installed bridge executable, protected configuration, and replay cache remain unchanged at their recorded pre-validation fingerprints (129,536 / 518 / 196 bytes; SHA-256 `B14B25AA986D5E209D06E92279C9152DFBD6DB38EAE242348D4FE248D3C8647F`, `0247451CE6D10E7CE6B6D603991D1F215865649CA3B1AD014FA89E298215A8D1`, and `A1AEA29BB741120AF9F649F33FD0FF705F95F1F337AE723FD7B1A9852D84BADA`, respectively). No database, QuickBooks, listener, transaction, or installed-file operation occurred.
- Residual limitation: ACLs and path checks restrict other identities and ordinary test mistakes but do not prevent a separate process running as the same Windows user from racing temporary-directory path checks. This is not a security boundary against a same-user process. No production deployment or QuickBooks runtime verification was performed.

## 12. Keep this document current

Update this file whenever a significant change affects:

- folder structure or entry points;
- withdrawal or invoice workflow;
- database tables, columns, indexes, or migrations;
- roles and permissions;
- product/site/client mapping rules;
- invoice calculation rules;
- QuickBooks integration status;
- known bugs, test procedures, or deployment steps.

Prefer concise, verified statements. Mark assumptions and untested behavior explicitly.