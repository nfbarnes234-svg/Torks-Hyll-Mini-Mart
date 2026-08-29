# Product import

Torks & Hyll accepts CSV and XLSX files from **Import data** (Manager only).

The first row must contain headers. The importer maps these fields:

| System field | Recommended header |
| --- | --- |
| SKU | `sku` |
| Barcode | `barcode` |
| Name | `name` |
| Category | `category` |
| Purchase price | `purchase_price` |
| Selling price | `selling_price` |
| Stock | `stock` |
| Minimum stock | `min_stock` |
| Unit | `unit` |

After upload, review the first 20 rows, select the mapping, run validation, and choose whether existing SKUs should be updated or skipped. Products are upserted by SKU. Every imported file is retained under `storage/imports/`; do not make that directory public.

The supplied `products.csv` is an import-ready export from the owner. It contains 169 product rows, blank category values, and a repeated barcode that is intentionally allowed because SKUs are the unique product key.

## InfinityFree setup

1. Create a MySQL database in the InfinityFree control panel and note the exact host, database name, username, and password.
2. Import `schema.sql`, then `seed.sql`, then `seed_products.sql`, using phpMyAdmin. If the database user cannot create databases, remove the first two lines of `schema.sql` and select the database in phpMyAdmin first.
3. Copy `config/env.example.php` to `config/env.php`, fill in those four credentials, and upload the full `htdocs` contents into the hosting account's `/htdocs`.
4. Change both demo passwords immediately after the first login. Demo accounts are for local setup only: `manager@torkshyll.local` / `password`, `cashier@torkshyll.local` / `password`.
## Torks & Hyll v2 notes
- Import now uses a dry-run review step. Rows with validation errors must be fixed before the import can be applied.
- Imported stock changes are recorded in `stock_movements` with type `import`.
- Existing installations should run `migration_v2.sql` once.
- Manager stock override is disabled by default and can be enabled in Settings.
