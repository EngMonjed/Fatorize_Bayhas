<?php
/**
 * config/create_branch_tables.php
 * 
 * تُستدعى عند إنشاء فرع جديد من صفحة branches.php
 * تنشئ الجداول المناسبة بناءً على نوع الفرع
 * 
 * الاستخدام:
 *   require_once 'config/create_branch_tables.php';
 *   $result = createBranchTables($pdo, 'gaz2', 'retail');
 */

/**
 * الجداول المشتركة — تُنشأ لكل فرع بغض النظر عن نوعه
 */
function getSharedTablesSql(string $s): array {
    return [

        // المنتجات
        "CREATE TABLE IF NOT EXISTS `products_{$s}` (
            `id`           INT(11)      NOT NULL AUTO_INCREMENT,
            `model_number` VARCHAR(50)  NOT NULL,
            `name`         VARCHAR(255) NOT NULL,
            `description`  TEXT         DEFAULT NULL,
            `category_id`  INT(11)      DEFAULT NULL,
            `supplier_id`  INT(11)      DEFAULT NULL,
            `packet_size`  INT(11)      DEFAULT NULL,
            `age_type`     ENUM('يوم','شهر','سنة') DEFAULT NULL,
            `age_from`     INT(11)      DEFAULT NULL,
            `age_to`       INT(11)      DEFAULT NULL,
            `age_step`     INT(11)      NOT NULL DEFAULT 1,
            `image_path`   VARCHAR(255) DEFAULT NULL,
            `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
            `notes`        TEXT         DEFAULT NULL,
            `created_by`   INT(11)      DEFAULT NULL,
            `updated_by`   INT(11)      DEFAULT NULL,
            `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`   DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `model_number` (`model_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `product_categories_{$s}` (
            `id`          INT(11)      NOT NULL AUTO_INCREMENT,
            `name`        VARCHAR(100) NOT NULL,
            `parent_id`   INT(11)      DEFAULT NULL,
            `description` TEXT         DEFAULT NULL,
            `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
            `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `product_suppliers_{$s}` (
            `id`                  INT(11)       NOT NULL AUTO_INCREMENT,
            `name`                VARCHAR(255)  NOT NULL,
            `contact_person`      VARCHAR(255)  DEFAULT NULL,
            `phone`               VARCHAR(50)   DEFAULT NULL,
            `email`               VARCHAR(255)  DEFAULT NULL,
            `type`                ENUM('manufacturer','distributor','wholesaler','retailer') DEFAULT 'wholesaler',
            `status`              ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `credit_limit`        DECIMAL(12,2) DEFAULT 0.00,
            `account_id`          INT(11)       DEFAULT NULL,
            `prepaid_account_id`  INT(11)       DEFAULT NULL,
            `notes`               TEXT          DEFAULT NULL,
            `created_by`          INT(11)       DEFAULT NULL,
            `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`          DATETIME      DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `product_sizes_{$s}` (
            `id`                INT(11)       NOT NULL AUTO_INCREMENT,
            `product_id`        INT(11)       NOT NULL,
            `size`              VARCHAR(20)   NOT NULL,
            `sort_order`        INT(11)       NOT NULL DEFAULT 0,
            `selling_price`     DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
            `full_packet_price` DECIMAL(10,4) DEFAULT NULL,
            `is_active`         TINYINT(1)    NOT NULL DEFAULT 1,
            `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`        DATETIME      DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_product_size` (`product_id`,`size`),
            CONSTRAINT `fk_size_product_{$s}` FOREIGN KEY (`product_id`)
                REFERENCES `products_{$s}` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `product_variants_{$s}` (
            `id`               INT(11)       NOT NULL AUTO_INCREMENT,
            `product_id`       INT(11)       NOT NULL,
            `size_id`          INT(11)       NOT NULL,
            `color`            VARCHAR(50)   NOT NULL,
            `barcode`          VARCHAR(100)  DEFAULT NULL,
            `last_cost_price`  DECIMAL(10,4) DEFAULT NULL,
            `last_purchase_at` DATETIME      DEFAULT NULL,
            `is_active`        TINYINT(1)    NOT NULL DEFAULT 1,
            `created_by`       INT(11)       DEFAULT NULL,
            `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       DATETIME      DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_size_color` (`size_id`,`color`),
            UNIQUE KEY `barcode` (`barcode`),
            CONSTRAINT `fk_var_product_{$s}` FOREIGN KEY (`product_id`)
                REFERENCES `products_{$s}` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_var_size_{$s}` FOREIGN KEY (`size_id`)
                REFERENCES `product_sizes_{$s}` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // المستودع
        "CREATE TABLE IF NOT EXISTS `warehouses_{$s}` (
            `id`         INT(11)      NOT NULL AUTO_INCREMENT,
            `code`       VARCHAR(50)  NOT NULL,
            `name`       VARCHAR(100) NOT NULL,
            `address`    TEXT         DEFAULT NULL,
            `manager_id` INT(11)      DEFAULT NULL,
            `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `code` (`code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `warehouse_items_{$s}` (
            `id`               INT(11)       NOT NULL AUTO_INCREMENT,
            `warehouse_id`     INT(11)       NOT NULL,
            `variant_id`       INT(11)       NOT NULL,
            `product_id`       INT(11)       NOT NULL,
            `quantity`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `min_quantity`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `avg_cost_usd`     DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
            `last_movement_at` DATETIME      DEFAULT NULL,
            `status`           ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       DATETIME      DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_wh_variant` (`warehouse_id`,`variant_id`),
            CONSTRAINT `fk_wi_warehouse_{$s}` FOREIGN KEY (`warehouse_id`)
                REFERENCES `warehouses_{$s}` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_wi_variant_{$s}` FOREIGN KEY (`variant_id`)
                REFERENCES `product_variants_{$s}` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_wi_product_{$s}` FOREIGN KEY (`product_id`)
                REFERENCES `products_{$s}` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // العملاء والموردون
        "CREATE TABLE IF NOT EXISTS `customers_{$s}` (
            `id`                 INT(11)      NOT NULL AUTO_INCREMENT,
            `name`               VARCHAR(100) NOT NULL,
            `contact_person`     VARCHAR(255) DEFAULT NULL,
            `type`               ENUM('individual','company') NOT NULL DEFAULT 'individual',
            `phone`              VARCHAR(20)  DEFAULT NULL,
            `email`              VARCHAR(100) DEFAULT NULL,
            `address`            TEXT         DEFAULT NULL,
            `tax_number`         VARCHAR(50)  DEFAULT NULL,
            `status`             ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `shipping_company`   VARCHAR(255) DEFAULT NULL,
            `shipping_code`      VARCHAR(100) DEFAULT NULL,
            `account_id`         INT(11)      DEFAULT NULL,
            `prepaid_account_id` INT(11)      DEFAULT NULL,
            `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`         DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // الموارد البشرية
        "CREATE TABLE IF NOT EXISTS `hr_employees_{$s}` (
            `id`           INT(11)       NOT NULL AUTO_INCREMENT,
            `full_name`    VARCHAR(255)  NOT NULL,
            `position`     VARCHAR(150)  NOT NULL,
            `department`   ENUM('sales','production','admin','logistics','accounting') NOT NULL,
            `phone`        VARCHAR(30)   DEFAULT NULL,
            `email`        VARCHAR(150)  DEFAULT NULL,
            `hire_date`    DATE          NOT NULL,
            `salary_type`  ENUM('monthly','weekly','daily','hourly') NOT NULL DEFAULT 'monthly',
            `basic_salary` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `currency_id`  INT(11)       DEFAULT NULL,
            `bank_account` VARCHAR(100)  DEFAULT NULL,
            `notes`        TEXT          DEFAULT NULL,
            `status`       ENUM('active','inactive','on_leave') NOT NULL DEFAULT 'active',
            `created_by`   INT(11)       DEFAULT NULL,
            `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`   DATETIME      DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `hr_attendance_{$s}` (
            `id`                INT(11)      NOT NULL AUTO_INCREMENT,
            `employee_id`       INT(11)      NOT NULL,
            `attendance_date`   DATE         NOT NULL,
            `check_in`          TIME         DEFAULT NULL,
            `check_out`         TIME         DEFAULT NULL,
            `hours_worked`      DECIMAL(5,2) DEFAULT 0.00,
            `overtime_hours`    DECIMAL(5,2) DEFAULT 0.00,
            `attendance_status` ENUM('present','absent','late','half_day','holiday') NOT NULL DEFAULT 'present',
            `notes`             TEXT         DEFAULT NULL,
            `created_by`        INT(11)      DEFAULT NULL,
            `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_attendance` (`employee_id`,`attendance_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `hr_payroll_{$s}` (
            `id`               INT(11)       NOT NULL AUTO_INCREMENT,
            `employee_id`      INT(11)       NOT NULL,
            `payroll_month`    DATE          NOT NULL,
            `basic_salary`     DECIMAL(12,2) NOT NULL,
            `working_days`     INT(11)       NOT NULL DEFAULT 0,
            `overtime_hours`   DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
            `overtime_amount`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `bonus_total`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `loan_deduction`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `other_deductions` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `net_salary`       DECIMAL(12,2) NOT NULL,
            `currency_id`      INT(11)       NOT NULL,
            `payment_status`   ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
            `payment_date`     DATE          DEFAULT NULL,
            `payment_method`   ENUM('cash','bank_transfer') DEFAULT NULL,
            `notes`            TEXT          DEFAULT NULL,
            `created_by`       INT(11)       DEFAULT NULL,
            `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_payroll` (`employee_id`,`payroll_month`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `hr_salary_payments_{$s}` (
            `id`               INT(11)       NOT NULL AUTO_INCREMENT,
            `employee_id`      INT(11)       NOT NULL,
            `payment_date`     DATE          NOT NULL,
            `period_from`      DATE          NOT NULL,
            `period_to`        DATE          NOT NULL,
            `total_salary`     DECIMAL(12,2) NOT NULL,
            `deductions`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `bonus`            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `net_payable`      DECIMAL(12,2) NOT NULL,
            `currency`         VARCHAR(3)    NOT NULL DEFAULT 'USD',
            `payment_method`   ENUM('cash','bank_transfer') NOT NULL DEFAULT 'cash',
            `journal_entry_id` INT(11)       DEFAULT NULL,
            `notes`            TEXT          DEFAULT NULL,
            `created_by`       INT(11)       DEFAULT NULL,
            `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            CONSTRAINT `fk_salary_employee_{$s}` FOREIGN KEY (`employee_id`)
                REFERENCES `hr_employees_{$s}` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // المستهلكات
        "CREATE TABLE IF NOT EXISTS `consumable_items_{$s}` (
            `id`             INT(11)       NOT NULL AUTO_INCREMENT,
            `name`           VARCHAR(255)  NOT NULL,
            `category`       ENUM('utility','supplies','food','maintenance','other') NOT NULL DEFAULT 'other',
            `unit`           VARCHAR(20)   NOT NULL DEFAULT 'قطعة',
            `estimated_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `notes`          TEXT          DEFAULT NULL,
            `is_active`      TINYINT(1)    NOT NULL DEFAULT 1,
            `created_by`     INT(11)       DEFAULT NULL,
            `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`     DATETIME      DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `consumable_entries_{$s}` (
            `id`               INT(11)       NOT NULL AUTO_INCREMENT,
            `consumable_id`    INT(11)       NOT NULL,
            `entry_date`       DATE          NOT NULL,
            `quantity`         DECIMAL(10,3) DEFAULT NULL,
            `amount_original`  DECIMAL(15,4) NOT NULL,
            `currency`         VARCHAR(3)    NOT NULL DEFAULT 'USD',
            `exchange_rate`    DECIMAL(10,6) NOT NULL DEFAULT 1.000000,
            `amount_usd`       DECIMAL(15,4) NOT NULL,
            `payment_method`   ENUM('cash','bank','card','deferred') NOT NULL DEFAULT 'cash',
            `notes`            TEXT          DEFAULT NULL,
            `created_by`       INT(11)       NOT NULL,
            `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_entry_date` (`entry_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // الفواتير
        "CREATE TABLE IF NOT EXISTS `sales_invoices_{$s}` SELECT * FROM `sales_invoices_alp` WHERE 0",
        "CREATE TABLE IF NOT EXISTS `sales_invoice_items_{$s}` SELECT * FROM `sales_invoice_items_alp` WHERE 0",
        "CREATE TABLE IF NOT EXISTS `purchases_{$s}` SELECT * FROM `purchases_alp` WHERE 0",
        "CREATE TABLE IF NOT EXISTS `purchase_items_{$s}` SELECT * FROM `purchase_items_alp` WHERE 0",
        "CREATE TABLE IF NOT EXISTS `receipts_{$s}` SELECT * FROM `receipts_alp` WHERE 0",
        "CREATE TABLE IF NOT EXISTS `journal_entries_{$s}` SELECT * FROM `journal_entries_alp` WHERE 0",
        "CREATE TABLE IF NOT EXISTS `journal_entry_items_{$s}` SELECT * FROM `journal_entry_items_alp` WHERE 0",
        "CREATE TABLE IF NOT EXISTS `account_charts_{$s}` SELECT * FROM `account_charts_alp` WHERE 0",
        "CREATE TABLE IF NOT EXISTS `exchange_rates_{$s}` SELECT * FROM `exchange_rates_alp` WHERE 0",
        "CREATE TABLE IF NOT EXISTS `notifications_{$s}` SELECT * FROM `notifications_alp` WHERE 0",
        "CREATE TABLE IF NOT EXISTS `inventory_movements_{$s}` SELECT * FROM `inventory_movements_alp` WHERE 0",
        "CREATE TABLE IF NOT EXISTS `inventory_movement_details_{$s}` SELECT * FROM `inventory_movement_details_alp` WHERE 0",

        // مستودع افتراضي
        "INSERT IGNORE INTO `warehouses_{$s}` (code, name) VALUES
            (CONCAT(UPPER('{$s}'), '-WH-01'), 'المستودع الرئيسي')",
    ];
}

/**
 * جداول التصنيع — تُنشأ فقط للفروع factory
 */
function getFactoryTablesSql(string $s): array {
    return [
        "CREATE TABLE IF NOT EXISTS `raw_materials_{$s}` (
            `id`        INT(11)       NOT NULL AUTO_INCREMENT,
            `name`      VARCHAR(150)  NOT NULL,
            `unit`      VARCHAR(30)   NOT NULL DEFAULT 'kg',
            `category`  VARCHAR(100)  DEFAULT NULL,
            `notes`     TEXT          DEFAULT NULL,
            `is_active` TINYINT(1)    NOT NULL DEFAULT 1,
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `raw_material_stock_{$s}` (
            `id`               INT(11)       NOT NULL AUTO_INCREMENT,
            `material_id`      INT(11)       NOT NULL,
            `warehouse_id`     INT(11)       NOT NULL DEFAULT 1,
            `quantity`         DECIMAL(12,3) NOT NULL DEFAULT 0.000,
            `avg_cost_usd`     DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
            `last_movement_at` DATETIME      DEFAULT NULL,
            `updated_at`       DATETIME      DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_material_wh` (`material_id`,`warehouse_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `production_operations_{$s}` (
            `id`                INT(11)       NOT NULL AUTO_INCREMENT,
            `name`              VARCHAR(100)  NOT NULL COMMENT 'خياطة، تطريز، كحت',
            `unit`              VARCHAR(30)   NOT NULL DEFAULT 'piece',
            `default_price_usd` DECIMAL(10,4) DEFAULT NULL,
            `notes`             TEXT          DEFAULT NULL,
            `is_active`         TINYINT(1)    NOT NULL DEFAULT 1,
            `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `production_entries_{$s}` (
            `id`             INT(11)       NOT NULL AUTO_INCREMENT,
            `operation_id`   INT(11)       NOT NULL,
            `entry_date`     DATE          NOT NULL,
            `quantity`       DECIMAL(10,2) NOT NULL,
            `price_per_unit` DECIMAL(10,4) NOT NULL,
            `total_usd`      DECIMAL(12,4) NOT NULL,
            `product_id`     INT(11)       DEFAULT NULL,
            `worker_id`      INT(11)       DEFAULT NULL,
            `notes`          TEXT          DEFAULT NULL,
            `created_by`     INT(11)       NOT NULL,
            `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_entry_date` (`entry_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `manufacturing_bom_{$s}` (
            `id`                INT(11)       NOT NULL AUTO_INCREMENT,
            `product_id`        INT(11)       NOT NULL,
            `component_type`    ENUM('raw_material','consumable','service') NOT NULL DEFAULT 'raw_material',
            `component_id`      INT(11)       NOT NULL,
            `quantity_required` DECIMAL(10,4) NOT NULL,
            `unit_cost`         DECIMAL(10,4) NOT NULL,
            `is_variable`       TINYINT(1)    NOT NULL DEFAULT 0,
            `notes`             TEXT          DEFAULT NULL,
            `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`        DATETIME      DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

/**
 * الدالة الرئيسية — تُستدعى من branches.php عند إنشاء فرع جديد
 */
function createBranchTables(PDO $pdo, string $tableSuffix, string $branchType): array {
    $errors  = [];
    $created = [];
    $s       = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $tableSuffix));

    if (empty($s)) {
        return ['ok' => false, 'error' => 'table_suffix غير صالح'];
    }

    $sqls = getSharedTablesSql($s);
    if ($branchType === 'factory') {
        $sqls = array_merge($sqls, getFactoryTablesSql($s));
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    foreach ($sqls as $sql) {
        try {
            $pdo->exec($sql);
            // استخراج اسم الجدول من SQL للتقرير
            if (preg_match('/TABLE(?:\s+IF NOT EXISTS)?\s+`([^`]+)`/i', $sql, $m)) {
                $created[] = $m[1];
            }
        } catch (PDOException $e) {
            $errors[] = $e->getMessage();
        }
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    return [
        'ok'      => empty($errors),
        'created' => $created,
        'errors'  => $errors,
        'suffix'  => $s,
        'type'    => $branchType,
    ];
}
