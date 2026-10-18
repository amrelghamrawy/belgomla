# V2 Task 06 — Catalog import / export and price-list export

Status: **planned**. Requires V2 Tasks 00, 05 (permission `catalog.manage`). Read `CLAUDE.md`, `rules/*.md`, then this file.

## Goal

1. **Export** the catalog to XLSX/CSV: one row per SKU with product, category path, custom fields, prices, tiers, MOQ and stock.
2. **Import** the same format: create new products, update existing ones matched by SKU code, with a dry-run preview that lists row errors before anything is written.
3. **Price list export**: the storefront price list (BR-P13) downloadable as XLSX. "PDF" stays the browser's print-to-PDF of the existing print view (D83).

This covers season rollover (D19) and onboarding a publisher's list without typing it.

Out of scope: image import (images are uploaded per product; a later option is a ZIP keyed by SKU code), import of customers or orders, scheduled feeds.

## Read before coding

| What      | Where |
| --------- | ----- |
| Rules     | BR-P02, BR-P03 (unique code), BR-P06 (category required, D25), BR-P08/P09 (custom fields, D69), BR-P12, BR-P13, BR-PR01..PR05, BR-PR10/PR11, BR-INV08, BR-U01..U04 |
| Tables    | `07-domain-model.md` §3 catalog, §4 pricing, §6 inventory |
| API       | `08-api-contract.md` dashboard products / pricing, store price list |
| Decisions | D19, D23, D25, D27, D28, D30, D34, D35, D69 |

## Left by V1

- `Catalog\Actions\CreateProduct` / `UpdateProduct` (they call `Pricing\Actions\UpdateSkuPricing` and `Inventory\Actions\RecordOpeningStock`). `Inventory\Actions` adjust through `StockLedger::adjust` (a reason is required; D35 makes "unchanged" a no-op).
- `ProductData`/`SkuData`/`SkuPricingData` are the input DTOs. Validation rules live in `Catalog\Http\Requests\Concerns\ProductPayloadRules` and `Pricing\Concerns\ReadsSkuPricing`. Reuse them through a `Validator` per row, not by copying.
- `Catalog\Queries\FilteredProductsQuery::builder(ProductFilterData)` selects the export set. `PriceListQuery` + `PriceListRowResource` feed the price list.
- There is no spreadsheet library in `composer.json`.

## Deliverables

### 1. Docs

D83 into 06. BR-P12 and BR-P13 are updated (import/export live, the XLSX price list). D19 and D23 are marked resolved. 08 gets the endpoints. Tech §1 gets the new dependency.

### 2. Dependency

`openspout/openspout` (streaming XLSX/CSV read and write, low memory, no ext beyond zip/xml). Pin the exact version. It is wrapped by `Catalog\Services\Spreadsheet` so no other class touches OpenSpout (arch test).

### 3. File format (version 1)

The header row holds Arabic labels with a hidden machine key row, or keys only in CSV:

`sku_code*, product_name*, category_path*` (e.g. `كتب خارجية > أولى إعدادي`), `status, description, selling_unit*, base_unit*, conversion_factor*, cover_price, base_price*, base_price_percent, moq, tier_1_min, tier_1_price, … tier_5_min, tier_5_price, on_hand, field:<key>…` (one column per custom field definition; select fields by option label).

`format_version` sits in the first sheet's name (`products_v1`). Task 13 bumps it.

### 4. Module classes (Catalog)

- `Services\Spreadsheet`: `read(path): iterable<array<string,string>>` and `write(path, headers, iterable rows)`.
- `Services\CatalogRowMapper`: header ↔ keys, category path → id (missing categories are an error, not created), unit names → ids, option labels → ids, tiers → `TierData`.
- `Data\ImportRowResult` (`row, action: create|update|skip, errors[]`).
- Queries:
  - `CatalogExportQuery`: a lazy cursor over the filter with skus, tiers, inventory and field values eager-loaded.
  - `PriceListExportQuery`: reuses `PriceListQuery`, without the cap.
- Actions:
  - `PreviewCatalogImport::handle(UploadedFile): ImportPreview`: validate only. It stores the file under `storage/app/imports/{tenant}/{uuid}` for 24 h and returns counts + the first 200 errors + a token.
  - `RunCatalogImport::handle(token, ?actorId)`: dispatches the job `ImportCatalog` (queue `default`) and creates an `imports` row.
  - Job `ImportCatalog`: per row, in its own transaction, calls `CreateProduct` or `UpdateProduct` + `UpdateSkuPricing`, then `StockLedger::adjust` to the `on_hand` column when it is present (reason "استيراد"). One audit `batch_id` covers the import. Results are written to the `imports` row.
- Schema `imports`: `id, tenant_id, uuid, file_name, status (queued|running|done|failed), total, created, updated, skipped, failed, errors jsonb (capped), batch_id, created_by`, timestamps.
- Export endpoint: streams a download (`StreamedResponse`). Large catalogs go through the job `ExportCatalog` to a temporary signed download URL when the count is above `catalog.export_sync_limit` (default 5,000).

### 5. Endpoints

| Method | Path | Notes |
| ------ | ---- | ----- |
| GET  | `/api/dashboard/products/export?format=xlsx|csv&<filter>` | sync download or `202 {import_id}`-style export job |
| GET  | `/api/dashboard/products/import/template` | an empty template with the tenant's custom field columns |
| POST | `/api/dashboard/products/import/preview` | multipart file → preview + token |
| POST | `/api/dashboard/products/import` | `{token}` → `202 {id}`; `idempotent` |
| GET  | `/api/dashboard/imports/{id}` | progress and errors |
| GET  | `/api/store/price-list/export?format=xlsx&<filter>` | respects `require_login_for_prices` (`403 prices_hidden`) |

All dashboard routes need `permission:catalog.manage`.

### 6. Frontend

- Products page: "تصدير" (current filter) and "استيراد" buttons. The import wizard is: upload → preview table of errors per row (row number, column, message) → confirm → progress (poll `imports/{id}`) → summary.
- Price list page: a "تحميل Excel" button next to print.

## Test list

- `Unit/Services/SpreadsheetTest`: round trip XLSX and CSV, Arabic text, BOM for CSV.
- `Unit/Services/CatalogRowMapperTest`: category path resolution (nested, archived → error), units, select options by label, tiers, unknown column ignored, the field of another section rejected (D69).
- `Unit/Actions/PreviewCatalogImportTest`: dataset of row errors (missing code, duplicate code in the file, base price ≤ 0, tier above base D28, unknown unit, missing category D25, bad number); counts create vs update; nothing written.
- `Unit/Jobs/ImportCatalogTest`: creates and updates; stock adjusted to `on_hand` with reason; unchanged stock no-op (D35); one bad row does not roll back the others; one audit batch; tenant isolation (the code of another tenant never matches).
- `Unit/Queries/CatalogExportQueryTest`: the columns and the filter applied; export → import of the same file = all `update` with no changes.
- `Http/Dashboard/Catalog/ImportExportTest`: endpoints, permission, file size/type validation, an expired token.
- `Http/Store/PriceListExportTest`: prices hidden → 403; the filter applied.
- Arch: only `Spreadsheet` uses OpenSpout.
- Frontend: wizard steps; e2e: export → import the same file → summary shows 0 errors.

## Slices

1. Docs, dependency, `Spreadsheet`, export (dashboard + price list).
2. Mapper + preview.
3. Import job, `imports` table, progress endpoint.
4. Frontend.

## Definition of done

Green suites; curl: export the demo catalog, change one price and one stock in the file, preview, import, verify the audit batch and the stock movement; docs updated.

## Open decisions (proposed)

- **D83** "PDF price list" stays the existing print view with the browser's print-to-PDF. No server-side PDF library (dompdf/wkhtmltopdf) is added. XLSX is the only generated file format, plus CSV for the catalog.
