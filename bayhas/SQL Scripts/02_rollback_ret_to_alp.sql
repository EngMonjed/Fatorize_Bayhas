-- ============================================================================
-- FATORIZE — Rollback: reverse 01_migrate_alp_to_ret.sql
-- Renames every "_ret" table back to "_alp", and restores the branches row.
--
-- Use this ONLY if something went wrong after running the migration and
-- before any real new data was written under the "ret" names. Once real
-- production data has accumulated under "_ret" tables, do NOT run this —
-- write a fresh forward migration instead.
-- ============================================================================

SELECT id, name, branch_type, table_suffix
FROM branches
WHERE table_suffix NOT IN ('ret');

CREATE TABLE IF NOT EXISTS migration_ret_to_alp_rollback_log (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  old_table   VARCHAR(100),
  new_table   VARCHAR(100),
  status      VARCHAR(20),
  message     VARCHAR(255),
  checked_at  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

DROP TABLE IF EXISTS _rollback_table_map;
CREATE TABLE _rollback_table_map (
  old_name VARCHAR(100) PRIMARY KEY,  -- current "_ret" name
  new_name VARCHAR(100) NOT NULL      -- target "_alp" name
) ENGINE=InnoDB;

INSERT INTO _rollback_table_map (old_name, new_name) VALUES
('products_ret','products_alp'),('product_categories_ret','product_categories_alp'),
('product_sizes_ret','product_sizes_alp'),('product_colors_ret','product_colors_alp'),
('product_variants_ret','product_variants_alp'),('product_suppliers_ret','product_suppliers_alp'),
('warehouses_ret','warehouses_alp'),('warehouse_items_ret','warehouse_items_alp'),
('inventory_movements_ret','inventory_movements_alp'),('inventory_movement_details_ret','inventory_movement_details_alp'),
('customers_ret','customers_alp'),('sales_invoices_ret','sales_invoices_alp'),
('sales_invoice_items_ret','sales_invoice_items_alp'),('sales_returns_ret','sales_returns_alp'),
('sales_return_items_ret','sales_return_items_alp'),('purchases_ret','purchases_alp'),
('purchase_items_ret','purchase_items_alp'),('purchase_returns_ret','purchase_returns_alp'),
('purchase_return_items_ret','purchase_return_items_alp'),
('account_charts_ret','account_charts_alp'),('invoice_account_settings_ret','invoice_account_settings_alp'),
('journal_entries_ret','journal_entries_alp'),('journal_entry_items_ret','journal_entry_items_alp'),
('receipts_ret','receipts_alp'),('receipt_invoices_ret','receipt_invoices_alp'),
('expenses_ret','expenses_alp'),('exchange_rates_ret','exchange_rates_alp'),
('shipping_carriers_ret','shipping_carriers_alp'),
('consumable_items_ret','consumable_items_alp'),('consumable_stock_ret','consumable_stock_alp'),
('consumable_movements_ret','consumable_movements_alp'),('consumable_purchases_ret','consumable_purchases_alp'),
('consumable_purchase_items_ret','consumable_purchase_items_alp'),('consumable_issues_ret','consumable_issues_alp'),
('consumable_issue_items_ret','consumable_issue_items_alp'),('consumable_sales_ret','consumable_sales_alp'),
('consumable_sale_items_ret','consumable_sale_items_alp'),('consumables_ret','consumables_alp'),
('consumable_entries_ret','consumable_entries_alp'),
('hr_employees_ret','hr_employees_alp'),('employees_ret','employees_alp'),
('hr_attendance_ret','hr_attendance_alp'),('attendance_ret','attendance_alp'),
('hr_payroll_ret','hr_payroll_alp'),('payroll_ret','payroll_alp'),
('hr_loans_ret','hr_loans_alp'),('hr_bonuses_ret','hr_bonuses_alp'),
('hr_promotions_ret','hr_promotions_alp'),('public_holidays_ret','public_holidays_alp'),
('raw_materials_ret','raw_materials_alp'),('raw_material_stock_ret','raw_material_stock_alp'),
('production_operations_ret','production_operations_alp'),('production_entries_ret','production_entries_alp'),
('notifications_ret','notifications_alp');

DELIMITER $$
DROP PROCEDURE IF EXISTS rollback_ret_to_alp $$
CREATE PROCEDURE rollback_ret_to_alp()
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE v_old VARCHAR(100);
  DECLARE v_new VARCHAR(100);
  DECLARE v_old_exists INT;
  DECLARE v_new_exists INT;
  DECLARE cur CURSOR FOR SELECT old_name, new_name FROM _rollback_table_map ORDER BY old_name;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

  OPEN cur;
  read_loop: LOOP
    FETCH cur INTO v_old, v_new;
    IF done THEN LEAVE read_loop; END IF;

    SELECT COUNT(*) INTO v_old_exists FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = v_old;
    SELECT COUNT(*) INTO v_new_exists FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = v_new;

    IF v_old_exists = 0 THEN
      INSERT INTO migration_ret_to_alp_rollback_log (old_table,new_table,status,message)
      VALUES (v_old, v_new, 'SKIPPED', 'source _ret table does not exist');
    ELSEIF v_new_exists = 1 THEN
      INSERT INTO migration_ret_to_alp_rollback_log (old_table,new_table,status,message)
      VALUES (v_old, v_new, 'ERROR', 'target _alp table already exists — manual review needed');
    ELSE
      SET @sql_rename = CONCAT('RENAME TABLE `', v_old, '` TO `', v_new, '`');
      PREPARE s1 FROM @sql_rename; EXECUTE s1; DEALLOCATE PREPARE s1;
      INSERT INTO migration_ret_to_alp_rollback_log (old_table,new_table,status,message)
      VALUES (v_old, v_new, 'OK', 'reverted successfully');
    END IF;
  END LOOP;
  CLOSE cur;
END $$
DELIMITER ;

CALL rollback_ret_to_alp();
DROP PROCEDURE rollback_ret_to_alp;
DROP TABLE _rollback_table_map;

UPDATE branches
SET
  table_suffix   = 'alp',
  dashboard_path = REPLACE(dashboard_path, 'retail1/', 'aleppo/'),
  updated_at     = NOW()
WHERE table_suffix = 'ret';
-- NOTE: this does NOT restore the old branch name (e.g. "فرع حلب") —
-- re-set `name`/`name_en` manually if you need the exact original label back.

SELECT * FROM migration_ret_to_alp_rollback_log ORDER BY id;
