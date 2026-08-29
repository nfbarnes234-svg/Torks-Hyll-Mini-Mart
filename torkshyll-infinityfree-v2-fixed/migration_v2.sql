-- Run this once on an existing Torks & Hyll database before using v2.
ALTER TABLE settings ADD COLUMN stock_override_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER vat_rate;
