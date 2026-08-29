# Torks & Hyll POS — InfinityFree V2

This build fixes the main production-readiness gaps identified in the first review.

## Main fixes
- Cashiers can only open receipts belonging to themselves; managers can open all receipts.
- Cashier dashboard figures and recent/top-product activity are scoped to the logged-in cashier.
- Manager dashboard includes today's product cost and gross profit.
- Manager dashboard includes a 7-day Chart.js revenue chart.
- Dark theme is now the default application theme with gold accents.
- Manager stock override is configurable in Settings and disabled by default.
- Split payments must equal the final total exactly.
- Product stock edits create stock-movement audit records.
- Imported stock changes create `import` stock movements.
- Legacy import now has a dry-run review step before applying changes.
- Category management has its own Manager-only screen.
- POS total updates live when a discount is entered, including VAT.

## Existing database
Run `migration_v2.sql` once before using this build if you already installed V1.

## New database
Use `schema.sql` and `seed.sql` as before. Create `config/env.php` from `config/env.example.php` and enter your InfinityFree MySQL credentials.

## Security reminder
Change/remove demo accounts from `seed.sql` before production use. Never upload `config/env.php` to a public repository.
