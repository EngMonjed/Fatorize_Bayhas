-- ============================================================================
-- FATORIZE — Migration: rename Aleppo branch ("alp") tables to the generic
-- retail-branch suffix ("ret"), and simplify branches.branch_type to just
-- 'retail' / 'factory'.
--
-- Target: MySQL 8.4+ / MariaDB 10.x+ (uses information_schema + dynamic SQL,
-- which both support).
--
-- SAFE TO RE-RUN: every table is checked before touching it — already
-- renamed tables are skipped, missing (never-provisioned) tables are
-- skipped, and any table where BOTH the old and new name exist is flagged
-- as an ERROR instead of silently overwritten.
--
-- WHAT THIS SCRIPT DOES, IN ORDER:
--   1. Creates a permanent log table: migration_alp_to_ret_log
--   2. Renames every "_alp" table to "_ret" (54 known tables from the
--      documented schema — see mapping below), verifying row counts
--      before/after each rename.
--   3. Updates the `branches` row that currently has table_suffix='alp':
--        table_suffix   -> 'ret'
--        name           -> 'فرع البيع 1'   (generic, no place name)
--        name_en        -> 'Retail Branch 1'
--        branch_type    -> 'retail'
--        dashboard_path -> aleppo/ replaced with retail1/
--   4. Restricts the branches.branch_type ENUM to only ('retail','factory')
--      — guarded: it will refuse to run if any branch currently has a
--      value outside that set, so you don't silently corrupt data.
--   5. Prints a full report at the end (per-table + summary by status).
--
-- BEFORE RUNNING: take a fresh backup / mysqldump of the database.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- STEP 0 — sanity pre-check: refuse to run branch_type ENUM restriction
-- later if any branch already has a type outside retail/factory (so we
-- don't lose data silently). This just reports; it does not block step 2/3.
-- ---------------------------------------------------------------------------
SELECT id, name, branch_type, table_suffix
FROM branches
WHERE branch_type NOT IN ('retail','factory');

-- ---------------------------------------------------------------------------
-- STEP 1 — log table
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS migration_alp_to_ret_log (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  old_table   VARCHAR(100),
  new_table   VARCHAR(100),
  rows_before BIGINT NULL,
  rows_after  BIGINT NULL,
  status      VARCHAR(20),
  message     VARCHAR(255),
  checked_at  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- STEP 2 — mapping table (old_alp_name -> new_ret_name), dropped at the end
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS _migration_table_map;
CREATE TABLE _migration_table_map (
  old_name VARCHAR(100) PRIMARY KEY,
  new_name VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

INSERT INTO _migration_table_map (old_name, new_name) VALUES
-- Products & Inventory
('products_alp',                 'products_ret'),
('product_categories_alp',       'product_categories_ret'),
('product_sizes_alp',            'product_sizes_ret'),
('product_colors_alp',           'product_colors_ret'),
('product_variants_alp',         'product_variants_ret'),
('product_suppliers_alp',        'product_suppliers_ret'),
('warehouses_alp',               'warehouses_ret'),
('warehouse_items_alp',          'warehouse_items_ret'),
('inventory_movements_alp',      'inventory_movements_ret'),
('inventory_movement_details_alp','inventory_movement_details_ret'),
-- Sales & Purchases
('customers_alp',                'customers_ret'),
('sales_invoices_alp',           'sales_invoices_ret'),
('sales_invoice_items_alp',      'sales_invoice_items_ret'),
('sales_returns_alp',            'sales_returns_ret'),
('sales_return_items_alp',       'sales_return_items_ret'),
('purchases_alp',                'purchases_ret'),
('purchase_items_alp',           'purchase_items_ret'),
('purchase_returns_alp',         'purchase_returns_ret'),
('purchase_return_items_alp',    'purchase_return_items_ret'),
-- Accounting
('account_charts_alp',           'account_charts_ret'),
('invoice_account_settings_alp', 'invoice_account_settings_ret'),
('journal_entries_alp',          'journal_entries_ret'),
('journal_entry_items_alp',      'journal_entry_items_ret'),
('receipts_alp',                 'receipts_ret'),
('receipt_invoices_alp',         'receipt_invoices_ret'),
('expenses_alp',                 'expenses_ret'),
('exchange_rates_alp',           'exchange_rates_ret'),
('shipping_carriers_alp',        'shipping_carriers_ret'),
-- Consumables (incl. legacy tables — renamed too, staying dead but consistent)
('consumable_items_alp',         'consumable_items_ret'),
('consumable_stock_alp',         'consumable_stock_ret'),
('consumable_movements_alp',     'consumable_movements_ret'),
('consumable_purchases_alp',     'consumable_purchases_ret'),
('consumable_purchase_items_alp','consumable_purchase_items_ret'),
('consumable_issues_alp',        'consumable_issues_ret'),
('consumable_issue_items_alp',   'consumable_issue_items_ret'),
('consumable_sales_alp',         'consumable_sales_ret'),
('consumable_sale_items_alp',    'consumable_sale_items_ret'),
('consumables_alp',              'consumables_ret'),
('consumable_entries_alp',       'consumable_entries_ret'),
-- HR & Payroll (incl. legacy tables)
('hr_employees_alp',             'hr_employees_ret'),
('employees_alp',                'employees_ret'),
('hr_attendance_alp',            'hr_attendance_ret'),
('attendance_alp',               'attendance_ret'),
('hr_payroll_alp',               'hr_payroll_ret'),
('payroll_alp',                  'payroll_ret'),
('hr_loans_alp',                 'hr_loans_ret'),
('hr_bonuses_alp',               'hr_bonuses_ret'),
('hr_promotions_alp',            'hr_promotions_ret'),
('public_holidays_alp',          'public_holidays_ret'),
-- Manufacturing (schema-only, no UI yet, but renamed for consistency)
('raw_materials_alp',            'raw_materials_ret'),
('raw_material_stock_alp',       'raw_material_stock_ret'),
('production_operations_alp',    'production_operations_ret'),
('production_entries_alp',       'production_entries_ret'),
-- Other
('notifications_alp',            'notifications_ret');

-- ---------------------------------------------------------------------------
-- STEP 3 — the migration procedure: check -> rename -> verify -> log
-- ---------------------------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS migrate_alp_to_ret $$
CREATE PROCEDURE migrate_alp_to_ret()
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE v_old VARCHAR(100);
  DECLARE v_new VARCHAR(100);
  DECLARE v_old_exists INT;
  DECLARE v_new_exists INT;
  DECLARE v_rows_before BIGINT DEFAULT NULL;
  DECLARE v_rows_after  BIGINT DEFAULT NULL;

  DECLARE cur CURSOR FOR SELECT old_name, new_name FROM _migration_table_map ORDER BY old_name;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

  OPEN cur;
  read_loop: LOOP
    FETCH cur INTO v_old, v_new;
    IF done THEN
      LEAVE read_loop;
    END IF;

    SELECT COUNT(*) INTO v_old_exists FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = v_old;
    SELECT COUNT(*) INTO v_new_exists FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = v_new;

    IF v_old_exists = 0 AND v_new_exists = 1 THEN
      INSERT INTO migration_alp_to_ret_log (old_table,new_table,status,message)
      VALUES (v_old, v_new, 'SKIPPED', 'already renamed in a previous run');

    ELSEIF v_old_exists = 0 AND v_new_exists = 0 THEN
      INSERT INTO migration_alp_to_ret_log (old_table,new_table,status,message)
      VALUES (v_old, v_new, 'SKIPPED', 'source table does not exist on this DB (never provisioned)');

    ELSEIF v_old_exists = 1 AND v_new_exists = 1 THEN
      INSERT INTO migration_alp_to_ret_log (old_table,new_table,status,message)
      VALUES (v_old, v_new, 'ERROR', 'BOTH old and new table exist — needs manual review, not touched');

    ELSE
      -- capture row count before
      SET @sql_count1 = CONCAT('SELECT COUNT(*) INTO @rb FROM `', v_old, '`');
      PREPARE s1 FROM @sql_count1; EXECUTE s1; DEALLOCATE PREPARE s1;
      SET v_rows_before = @rb;

      -- rename (InnoDB auto-updates FK metadata pointing at this table)
      SET @sql_rename = CONCAT('RENAME TABLE `', v_old, '` TO `', v_new, '`');
      PREPARE s2 FROM @sql_rename; EXECUTE s2; DEALLOCATE PREPARE s2;

      -- capture row count after
      SET @sql_count2 = CONCAT('SELECT COUNT(*) INTO @ra FROM `', v_new, '`');
      PREPARE s3 FROM @sql_count2; EXECUTE s3; DEALLOCATE PREPARE s3;
      SET v_rows_after = @ra;

      IF v_rows_before = v_rows_after THEN
        INSERT INTO migration_alp_to_ret_log (old_table,new_table,rows_before,rows_after,status,message)
        VALUES (v_old, v_new, v_rows_before, v_rows_after, 'OK', 'renamed successfully, row count verified');
      ELSE
        INSERT INTO migration_alp_to_ret_log (old_table,new_table,rows_before,rows_after,status,message)
        VALUES (v_old, v_new, v_rows_before, v_rows_after, 'WARNING', 'renamed but row count mismatch — investigate manually');
      END IF;
    END IF;

  END LOOP;
  CLOSE cur;
END $$
DELIMITER ;

CALL migrate_alp_to_ret();
DROP PROCEDURE migrate_alp_to_ret;
DROP TABLE _migration_table_map;

-- ---------------------------------------------------------------------------
-- STEP 4 — update the branch's own record
-- Adjust the literal strings below (name/name_en) if you want a different
-- generic label than "فرع البيع 1" / "Retail Branch 1".
-- ---------------------------------------------------------------------------
UPDATE branches
SET
  table_suffix   = 'ret',
  name           = 'فرع البيع 1',
  name_en        = 'Retail Branch 1',
  branch_type    = 'retail',
  dashboard_path = REPLACE(dashboard_path, 'aleppo/', 'retail1/'),
  updated_at     = NOW()
WHERE table_suffix = 'alp';

-- ---------------------------------------------------------------------------
-- STEP 5 — restrict branch_type ENUM to retail/factory only
-- Guarded: only runs cleanly if no existing row uses another value.
-- If the STEP 0 pre-check above returned rows, fix/reassign those branches
-- FIRST, then re-run just this ALTER statement manually.
-- ---------------------------------------------------------------------------
ALTER TABLE branches
  MODIFY COLUMN branch_type ENUM('retail','factory') NOT NULL DEFAULT 'retail';

-- ---------------------------------------------------------------------------
-- STEP 6 — report
-- ---------------------------------------------------------------------------
SELECT * FROM migration_alp_to_ret_log ORDER BY id;

SELECT status, COUNT(*) AS table_count
FROM migration_alp_to_ret_log
GROUP BY status;

SELECT id, name, name_en, branch_type, table_suffix, dashboard_path
FROM branches
WHERE table_suffix = 'ret';
