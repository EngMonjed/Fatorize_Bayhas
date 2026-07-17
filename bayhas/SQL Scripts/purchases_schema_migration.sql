-- ============================================================
-- purchases_{TS} / purchase_items_{TS} schema migration (v4 — supersedes
-- v2/v3; nothing from earlier versions was ever run against a live
-- database, so this replaces them outright rather than layering on top).
--
-- Written against the real, confirmed CREATE TABLE for `purchase_items_alp`,
-- `product_variants_alp`, and `products_alp` (supplied directly). The join
-- path (variant_id -> product_variants_alp -> products_alp, plus
-- size_id/color_id -> product_sizes_alp/product_colors_alp) is now
-- confirmed, including product_sizes_alp/product_colors_alp (also
-- supplied directly) — the label-column question from earlier drafts is
-- resolved (see 2a).
--
-- ⚠ BACKUP THE DATABASE BEFORE RUNNING THIS.
-- ⚠ Run against `purchases_alp` / `purchase_items_alp` first (the only
--   branch with live data). `config/create_branch_tables.php` (the DDL
--   generator for provisioning new branches) needs the same changes
--   before the next branch is provisioned — not available to update here.
-- ⚠ DO NOT RUN THIS until the write-path files are updated to match:
--   `invoice_new.php`, `invoice_edit.php`, `api/confirm_purchase_invoice.php`.
--   None of them have been supplied/reviewed yet. The moment this runs,
--   any INSERT/UPDATE still using the old column names will fail.
-- ============================================================


-- ============================================================
-- PART 1 — purchases_alp (invoice header)
-- ============================================================

-- 1a) created_by — audit column
ALTER TABLE `purchases_alp`
  ADD COLUMN `created_by` INT NULL AFTER `supplier_id`;
ALTER TABLE `purchases_alp`
  ADD CONSTRAINT `fk_purchases_alp_created_by`
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`);

-- 1b) currency (text) -> invoice_currency_id (FK to currencies.id)
--     = the currency the invoice was actually recorded/entered in.
ALTER TABLE `purchases_alp`
  ADD COLUMN `invoice_currency_id` INT NULL AFTER `currency`;

UPDATE `purchases_alp` p
JOIN `currencies` c ON c.code = p.currency
SET p.invoice_currency_id = c.id;

-- Sanity check — should return 0 rows before adding the FK below.
-- Any row returned here has a `currency` value with no match in
-- `currencies` (typo / retired code) and needs manual fixing first.
SELECT id, purchase_number, currency
FROM `purchases_alp`
WHERE currency IS NOT NULL AND invoice_currency_id IS NULL;

ALTER TABLE `purchases_alp`
  ADD CONSTRAINT `fk_purchases_alp_invoice_currency`
  FOREIGN KEY (`invoice_currency_id`) REFERENCES `currencies`(`id`);

-- Old `currency` text column is intentionally NOT dropped yet — keep it
-- until invoice_new.php/invoice_edit.php/confirm_purchase_invoice.php are
-- confirmed to write invoice_currency_id instead. Once confirmed:
--   ALTER TABLE `purchases_alp` DROP COLUMN `currency`;

-- 1c) base_currency_id (NEW) — snapshot of the branch's own base
--     currency at the time the invoice was recorded. This is what
--     final_amount_base_currency (below) is actually denominated in.
ALTER TABLE `purchases_alp`
  ADD COLUMN `base_currency_id` INT NULL AFTER `invoice_currency_id`;

-- Backfill existing rows from the branch's CURRENT base currency.
-- ⚠ VERIFY FIRST: this assumes `branches.base_currency` already stores a
-- currency_id (an INT FK into `currencies`). Run this first to check:
--     SHOW CREATE TABLE `branches`;
-- If `branches.base_currency` is actually a text code (e.g. 'USD') instead
-- of an id, change the join below to: ON c2.code = b.base_currency
UPDATE `purchases_alp` p
JOIN `branches` b ON b.table_suffix = 'alp'
SET p.base_currency_id = b.base_currency
WHERE p.base_currency_id IS NULL;

ALTER TABLE `purchases_alp`
  ADD CONSTRAINT `fk_purchases_alp_base_currency`
  FOREIGN KEY (`base_currency_id`) REFERENCES `currencies`(`id`);

-- 1d) warehouse_id (NEW) — the single receiving warehouse for the whole
--     invoice. Since purchase_items_alp.warehouse_id is being dropped
--     (Part 2 below), this becomes the one source of truth for where
--     confirmed stock gets added. Nullable: a draft invoice may not have
--     a warehouse chosen yet if that's still picked at confirm time
--     rather than at creation — confirm with invoice_new.php's actual flow.
ALTER TABLE `purchases_alp`
  ADD COLUMN `warehouse_id` INT NULL AFTER `base_currency_id`;
ALTER TABLE `purchases_alp`
  ADD CONSTRAINT `fk_purchases_alp_warehouse`
  FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_alp`(`id`);

-- 1e) final_amount_usd -> final_amount_base_currency
-- MySQL 8.0.8+ / MariaDB 10.5.2+:
ALTER TABLE `purchases_alp`
  RENAME COLUMN `final_amount_usd` TO `final_amount_base_currency`;
-- Older servers: run `SHOW COLUMNS FROM purchases_alp LIKE 'final_amount_usd';`
-- to get the exact type, then:
--   ALTER TABLE `purchases_alp`
--     CHANGE COLUMN `final_amount_usd` `final_amount_base_currency` DECIMAL(14,2) NOT NULL DEFAULT 0;


-- ============================================================
-- PART 2 — purchase_items_alp (invoice line items)
-- ============================================================
-- Confirmed against the real CREATE TABLE:
--   id, purchase_id, product_id, variant_id, product_name, model_number,
--   size, color, barcode, quantity, unit_price, unit_price_usd,
--   total_price, tax_percentage, tax_amount, discount_amount,
--   warehouse_id, description, created_at

-- 2a) Drop columns no longer needed at the line-item level.
--     product_id and variant_id are BOTH kept (not touched) — confirmed
--     intent: product_id for product-level reporting regardless of
--     size/color, variant_id for size/color-specific reporting. Display
--     data (product_name, model_number, size, color, barcode) is now
--     resolved live via a join instead of duplicated per row, confirmed
--     against the real product_sizes_alp/product_colors_alp DDL:
--       purchase_items_alp.product_id  -> products_alp.name / model_number
--       purchase_items_alp.variant_id  -> product_variants_alp.barcode
--       product_variants_alp.size_id   -> product_sizes_alp.size
--       product_variants_alp.color_id  -> product_colors_alp.name
--     (note: sizes use a `size` column, colors use `name` — different
--     conventions between the two tables; purchases/index.php's queries
--     already use the correct one for each.)
ALTER TABLE `purchase_items_alp`
  DROP COLUMN `tax_percentage`,
  DROP COLUMN `tax_amount`,
  DROP COLUMN `product_name`,
  DROP COLUMN `model_number`,
  DROP COLUMN `size`,
  DROP COLUMN `color`,
  DROP COLUMN `barcode`,
  DROP COLUMN `warehouse_id`,
  DROP COLUMN `description`;

-- 2b) unit_price_usd -> unit_price_base_currency
-- Real declared type confirmed: DECIMAL(10,4) NULL DEFAULT NULL
-- MySQL 8.0.8+ / MariaDB 10.5.2+:
ALTER TABLE `purchase_items_alp`
  RENAME COLUMN `unit_price_usd` TO `unit_price_base_currency`;
-- Older servers (no RENAME COLUMN support), use instead:
--   ALTER TABLE `purchase_items_alp`
--     CHANGE COLUMN `unit_price_usd` `unit_price_base_currency` DECIMAL(10,4) NULL DEFAULT NULL;

-- 2c) New columns
ALTER TABLE `purchase_items_alp`
  ADD COLUMN `discount_percentage` DECIMAL(5,2) NOT NULL DEFAULT 0,
  ADD COLUMN `created_by` INT NULL,
  ADD COLUMN `updated_by` INT NULL,
  ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE `purchase_items_alp`
  ADD CONSTRAINT `fk_purchase_items_alp_created_by`
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`);
ALTER TABLE `purchase_items_alp`
  ADD CONSTRAINT `fk_purchase_items_alp_updated_by`
  FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`);

-- 2d) Optional, NOT included above (not requested — flagging as a
--     suggestion only): `purchase_items_alp.product_id`/`variant_id` have
--     indexes (idx_product_id, idx_variant_id) but no FK constraints
--     toward products_alp/product_variants_alp — unlike every other
--     relationship in this migration. Worth adding for referential
--     integrity if desired:
--   ALTER TABLE `purchase_items_alp`
--     ADD CONSTRAINT `fk_pi_product` FOREIGN KEY (`product_id`) REFERENCES `products_alp`(`id`),
--     ADD CONSTRAINT `fk_pi_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants_alp`(`id`);
