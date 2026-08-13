-- Dumping structure for table bayhas_local.account_charts_ret
CREATE TABLE IF NOT EXISTS `account_charts_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `parent_id` int DEFAULT NULL,
  `account_type` enum('asset','liability','equity','revenue','expense') COLLATE utf8mb4_unicode_ci NOT NULL,
  `cash_flow_category` enum('operating','investing','financing','excluded','none') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none' COMMENT 'تصنيف صريح لتقرير التدفقات النقدية — مخزَّن، مش مُستنتَج من الكود',
  `balance` decimal(15,2) NOT NULL DEFAULT '0.00' COMMENT 'الرصيد بعملة الحساب',
  `base_balance` decimal(15,2) NOT NULL DEFAULT '0.00' COMMENT 'الرصيد بعملة الفرع الأساسية',
  `currency_id` int NOT NULL DEFAULT '1' COMMENT 'مرجع جدول currencies',
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT '1.0000',
  `level` tinyint NOT NULL DEFAULT '1',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_locked` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'حساب نظامي لا يُحذف',
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_account_type` (`account_type`),
  KEY `idx_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='شجرة الحسابات';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.branches
CREATE TABLE IF NOT EXISTS `branches` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_en` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'الاسم بالإنجليزية',
  `tenant_slogan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'سلوغن الشركة — يظهر بترويسة الطباعة',
  `branch_type` enum('retail','factory') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'retail',
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'Syria',
  `tax_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'الرقم الضريبي للفرع',
  `commercial_registration_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'رقم السجل الصناعي/التجاري للفرع',
  `factory_branch_id` int DEFAULT NULL COMMENT 'معرف فرع المعمل/المصدر المرتبط بهذا الفرع — لإنشاء الطلبيات الداخلية',
  `base_currency` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD' COMMENT 'العملة الوظيفية للفرع (Functional Currency) — تُختار عند إنشاء الفرع وتتجمّد بعدها. منفصلة تماماً عن عملة التقارير الموحّدة على مستوى الشركة (fatorize_master.tenants.reporting_currency_id)',
  `local_currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'SYP' COMMENT 'العملة المحلية للمعاملات اليومية',
  `pricing_method` enum('fixed','cost_plus','market') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cost_plus' COMMENT 'طريقة التسعير: ثابت | تكلفة+هامش | سعر السوق',
  `default_margin_pct` decimal(5,2) NOT NULL DEFAULT '20.00' COMMENT 'هامش الربح الافتراضي %',
  `costing_method` enum('last_cost','weighted_average') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'last_cost',
  `tax_rate_default` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT 'نسبة الضريبة الافتراضية %',
  `tax_input_recoverable` enum('non_recoverable','recoverable') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'non_recoverable',
  `allow_negative_stock` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'السماح بالمخزون السالب',
  `notify_low_stock` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'إشعار عند انخفاض المخزون',
  `low_stock_threshold` int NOT NULL DEFAULT '5' COMMENT 'حد المخزون المنخفض (كمية)',
  `notify_new_invoice` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'إشعار عند إنشاء فاتورة جديدة',
  `notify_internal_order` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'إشعار عند وصول طلبية داخلية من فرع آخر',
  `notify_email` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'بريد استقبال الإشعارات',
  `invoice_prefix` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'INV' COMMENT 'بادئة رقم الفاتورة مثل: ALP, IST',
  `invoice_counter` int NOT NULL DEFAULT '0' COMMENT 'عداد الفواتير الحالي',
  `fiscal_year_start` tinyint NOT NULL DEFAULT '1' COMMENT 'شهر بداية السنة المالية (1=يناير)',
  `week_start_day` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'يوم بداية الأسبوع: 0=أحد، 1=إثنين، 2=ثلاثاء، 3=أربعاء، 4=خميس، 5=جمعة، 6=سبت',
  `default_payment_terms` int NOT NULL DEFAULT '30' COMMENT 'أيام الدفع الافتراضية للعملاء',
  `code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `table_suffix` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT 'alp=حلب | ist=استنبول | gaz=عنتاب | lab=معمل',
  `dashboard_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'مسار داشبورد الفرع بعد تسجيل الدخول',
  `icon` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT 'bi-building' COMMENT 'Bootstrap Icons class',
  `color` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT '#3b82f6',
  `sort_order` int DEFAULT '0',
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `base_currency_id` int DEFAULT NULL,
  `local_currency_id` int DEFAULT NULL,
  `default_purchase_tax_pct` decimal(5,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_status` (`status`),
  KEY `idx_table_suffix` (`table_suffix`),
  KEY `idx_factory_branch` (`factory_branch_id`),
  KEY `idx_branch_type` (`branch_type`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الفروع — كل فرع له table_suffix خاص به';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.consumable_categories_ret
CREATE TABLE IF NOT EXISTS `consumable_categories_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `icon` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bi-tag',
  `color` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#64748b',
  `bg_color` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#f1f5f9',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='فئات المستهلكات';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.consumable_departments_ret
CREATE TABLE IF NOT EXISTS `consumable_departments_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أقسام الجهات المستلمة للمستهلكات';

-- Data exporting was unselected.
CREATE TABLE IF NOT EXISTS `warehouses_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `warehouse_type` enum('products','consumables','raw_materials') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'products',
  `address` text COLLATE utf8mb4_unicode_ci,
  `manager_id` int DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping structure for table bayhas_local.consumable_issues_ret
CREATE TABLE IF NOT EXISTS `consumable_issues_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `issue_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ISS-2024-0001',
  `warehouse_id` int NOT NULL COMMENT 'المستودع المصدر',
  `department` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'القسم/الجهة المستلمة',
  `department_id` int DEFAULT NULL,
  `issue_date` date NOT NULL,
  `status` enum('draft','confirmed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `journal_entry_id` int DEFAULT NULL,
  `is_posted` tinyint(1) NOT NULL DEFAULT '0',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_issue_no` (`issue_no`),
  KEY `idx_warehouse_id` (`warehouse_id`),
  KEY `idx_date` (`issue_date`),
  KEY `fk_ci_department_ret` (`department_id`),
  CONSTRAINT `fk_ci_department_ret` FOREIGN KEY (`department_id`) REFERENCES `consumable_departments_ret` (`id`),
  CONSTRAINT `fk_ci_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_ret` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أوامر الصرف الداخلي للمستهلكات';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.consumable_issue_items_ret
CREATE TABLE IF NOT EXISTS `consumable_issue_items_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `issue_id` int NOT NULL COMMENT 'FK → consumable_issues_alp',
  `item_id` int NOT NULL COMMENT 'FK → consumable_items_alp',
  `packaging_id` int DEFAULT NULL,
  `packaging_qty` decimal(14,4) DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `returned_qty` decimal(12,3) NOT NULL DEFAULT '0.000',
  `unit_cost_base` decimal(12,4) DEFAULT '0.0000' COMMENT 'تكلفة الوحدة بعملة التقارير الموحّدة للشركة',
  `total_cost_base` decimal(15,4) DEFAULT '0.0000' COMMENT 'إجمالي التكلفة بعملة التقارير الموحّدة للشركة',
  `movement_id` int DEFAULT NULL COMMENT 'FK → consumable_movements_alp',
  `notes` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `idx_issue_id` (`issue_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `fk_cii_movement` (`movement_id`),
  KEY `fk_cii_packaging_ret` (`packaging_id`),
  CONSTRAINT `fk_cii_issue` FOREIGN KEY (`issue_id`) REFERENCES `consumable_issues_ret` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cii_item` FOREIGN KEY (`item_id`) REFERENCES `consumable_items_ret` (`id`),
  CONSTRAINT `fk_cii_movement` FOREIGN KEY (`movement_id`) REFERENCES `consumable_movements_ret` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cii_packaging_ret` FOREIGN KEY (`packaging_id`) REFERENCES `consumable_item_packagings_ret` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تفاصيل أوامر الصرف الداخلي';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.consumable_items_ret
CREATE TABLE IF NOT EXISTS `consumable_items_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'قهوة، ماء، قرطاسية، كهربا',
  `category` enum('utility','supplies','food','maintenance','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other',
  `category_id` int DEFAULT NULL,
  `unit` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'قطعة',
  `unit_id` int DEFAULT NULL,
  `estimated_cost` decimal(10,8) NOT NULL DEFAULT '0.00000000' COMMENT 'تكلفة تقديرية للمقارنة',
  `last_purchase_price_base` decimal(15,4) DEFAULT NULL COMMENT 'آخر سعر شراء بعملة التقارير الموحّدة للشركة',
  `last_purchase_date` date DEFAULT NULL,
  `currency_id` int DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_ci_currency` (`currency_id`),
  KEY `fk_consumable_items_category_ret` (`category_id`),
  KEY `fk_consumable_items_unit_ret` (`unit_id`),
  CONSTRAINT `fk_ci_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_consumable_items_category_ret` FOREIGN KEY (`category_id`) REFERENCES `consumable_categories_ret` (`id`),
  CONSTRAINT `fk_consumable_items_unit_ret` FOREIGN KEY (`unit_id`) REFERENCES `consumable_units_ret` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='المستهلكات';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.consumable_item_packagings_ret
CREATE TABLE IF NOT EXISTS `consumable_item_packagings_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` int NOT NULL,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `qty_per_package` decimal(14,4) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_item_pkg` (`item_id`,`name`),
  CONSTRAINT `fk_ci_packagings_item` FOREIGN KEY (`item_id`) REFERENCES `consumable_items_ret` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='عبوات المستهلكات (عوامل التحويل)';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.consumable_movements_ret
CREATE TABLE IF NOT EXISTS `consumable_movements_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `movement_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'رقم الحركة: MOV-2024-0001',
  `item_id` int NOT NULL COMMENT 'FK → consumable_items_alp',
  `warehouse_id` int NOT NULL COMMENT 'FK → warehouses_alp',
  `movement_type` enum('receive','issue','return_in','return_out','transfer','adjust','waste') COLLATE utf8mb4_unicode_ci NOT NULL,
  `direction` enum('in','out') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'داخل أو خارج المخزون',
  `quantity` decimal(12,3) NOT NULL COMMENT 'الكمية الموجبة دائماً',
  `unit_cost_base` decimal(12,4) DEFAULT '0.0000' COMMENT 'تكلفة الوحدة بعملة التقارير الموحّدة للشركة',
  `total_cost_base` decimal(15,4) DEFAULT '0.0000' COMMENT 'إجمالي التكلفة بعملة التقارير الموحّدة للشركة',
  `qty_before` decimal(12,3) DEFAULT '0.000' COMMENT 'الرصيد قبل الحركة',
  `qty_after` decimal(12,3) DEFAULT '0.000' COMMENT 'الرصيد بعد الحركة',
  `reference_type` enum('purchase','sale','issue','transfer','inventory','manual','consumable_return') COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_id` int DEFAULT NULL COMMENT 'id المصدر',
  `to_warehouse_id` int DEFAULT NULL COMMENT 'للنقل: المستودع المستهدف',
  `journal_entry_id` int DEFAULT NULL COMMENT 'FK → journal_entries',
  `is_posted` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'هل رُحّل المحاسبياً؟',
  `movement_date` date NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_movement_no` (`movement_no`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_warehouse_id` (`warehouse_id`),
  KEY `idx_type` (`movement_type`),
  KEY `idx_date` (`movement_date`),
  KEY `idx_reference` (`reference_type`,`reference_id`),
  KEY `fk_cm_to_warehouse` (`to_warehouse_id`),
  CONSTRAINT `fk_cm_item` FOREIGN KEY (`item_id`) REFERENCES `consumable_items_ret` (`id`),
  CONSTRAINT `fk_cm_to_warehouse` FOREIGN KEY (`to_warehouse_id`) REFERENCES `warehouses_ret` (`id`),
  CONSTRAINT `fk_cm_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_ret` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='حركات مخزون المستهلكات — مستقلة عن الفواتير';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.consumable_purchases_ret
CREATE TABLE IF NOT EXISTS `consumable_purchases_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'رقم الفاتورة الداخلي: PUR-2024-0001',
  `supplier_ref` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'رقم فاتورة المورد',
  `supplier_id` int DEFAULT NULL COMMENT 'FK → product_suppliers_alp',
  `warehouse_id` int NOT NULL COMMENT 'المستودع المستلِم',
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL COMMENT 'تاريخ الاستحقاق',
  `currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `exchange_rate` decimal(14,6) NOT NULL DEFAULT '1.000000',
  `subtotal_orig` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `subtotal_base` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `discount_pct` decimal(5,2) NOT NULL DEFAULT '0.00',
  `discount_base` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `discount_orig` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `tax_pct` decimal(5,2) NOT NULL DEFAULT '0.00',
  `tax_base` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `tax_orig` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `total_base` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `total_orig` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `paid_base` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `paid_orig` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `balance_base` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `balance_orig` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `status` enum('draft','confirmed','partial','paid','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `payment_method` enum('cash','bank','card','deferred') COLLATE utf8mb4_unicode_ci DEFAULT 'deferred',
  `journal_entry_id` int DEFAULT NULL,
  `is_posted` tinyint(1) NOT NULL DEFAULT '0',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int NOT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_invoice_no` (`invoice_no`),
  KEY `idx_supplier_id` (`supplier_id`),
  KEY `idx_warehouse_id` (`warehouse_id`),
  KEY `idx_date` (`invoice_date`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_cp_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `product_suppliers_ret` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cp_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_ret` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='فواتير شراء المستهلكات — رأس الفاتورة';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.consumable_purchase_items_ret
CREATE TABLE IF NOT EXISTS `consumable_purchase_items_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `purchase_id` int NOT NULL COMMENT 'FK → consumable_purchases_alp',
  `item_id` int NOT NULL COMMENT 'FK → consumable_items_alp',
  `packaging_id` int DEFAULT NULL,
  `packaging_qty` decimal(14,4) DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_price_orig` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `unit_price_base` decimal(12,4) NOT NULL DEFAULT '0.0000',
  `total_orig` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `discount_pct` decimal(5,2) NOT NULL DEFAULT '0.00',
  `total_base` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `movement_id` int DEFAULT NULL COMMENT 'FK → consumable_movements_alp (حركة الاستلام)',
  `notes` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `idx_purchase_id` (`purchase_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_movement_id` (`movement_id`),
  KEY `fk_cpi_packaging_ret` (`packaging_id`),
  CONSTRAINT `fk_cpi_item` FOREIGN KEY (`item_id`) REFERENCES `consumable_items_ret` (`id`),
  CONSTRAINT `fk_cpi_movement` FOREIGN KEY (`movement_id`) REFERENCES `consumable_movements_ret` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cpi_packaging_ret` FOREIGN KEY (`packaging_id`) REFERENCES `consumable_item_packagings_ret` (`id`),
  CONSTRAINT `fk_cpi_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `consumable_purchases_ret` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تفاصيل فواتير شراء المستهلكات';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.consumable_returns_ret
CREATE TABLE IF NOT EXISTS `consumable_returns_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `return_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `issue_id` int NOT NULL,
  `warehouse_id` int NOT NULL,
  `return_date` date NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `journal_entry_id` int DEFAULT NULL,
  `is_posted` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_cr_issue_ret` (`issue_id`),
  CONSTRAINT `fk_cr_issue_ret` FOREIGN KEY (`issue_id`) REFERENCES `consumable_issues_ret` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مرتجعات فواتير شراء المستهلكات';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.consumable_return_items_ret
CREATE TABLE IF NOT EXISTS `consumable_return_items_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `return_id` int NOT NULL,
  `issue_item_id` int NOT NULL,
  `item_id` int NOT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_cost_base` decimal(12,4) NOT NULL,
  `total_cost_base` decimal(15,4) NOT NULL,
  `movement_id` int DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `fk_cri_return_ret` (`return_id`),
  KEY `fk_cri_issue_item_ret` (`issue_item_id`),
  CONSTRAINT `fk_cri_issue_item_ret` FOREIGN KEY (`issue_item_id`) REFERENCES `consumable_issue_items_ret` (`id`),
  CONSTRAINT `fk_cri_return_ret` FOREIGN KEY (`return_id`) REFERENCES `consumable_returns_ret` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بنود مرتجعات فواتير شراء المستهلكات';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.consumable_stock_ret
CREATE TABLE IF NOT EXISTS `consumable_stock_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` int NOT NULL COMMENT 'FK → consumable_items_alp',
  `warehouse_id` int NOT NULL COMMENT 'FK → warehouses_alp',
  `quantity` decimal(12,3) NOT NULL DEFAULT '0.000' COMMENT 'الرصيد الحالي',
  `min_quantity` decimal(12,3) NOT NULL DEFAULT '0.000' COMMENT 'حد التنبيه',
  `avg_cost_base` decimal(12,4) NOT NULL DEFAULT '0.0000' COMMENT 'متوسط التكلفة (Weighted Average) بعملة التقارير الموحّدة للشركة',
  `last_movement` datetime DEFAULT NULL COMMENT 'تاريخ آخر حركة',
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_item_warehouse` (`item_id`,`warehouse_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_warehouse_id` (`warehouse_id`),
  CONSTRAINT `fk_cs_item` FOREIGN KEY (`item_id`) REFERENCES `consumable_items_ret` (`id`),
  CONSTRAINT `fk_cs_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_ret` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أرصدة المستهلكات لكل مستودع';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.consumable_transfers_ret
CREATE TABLE IF NOT EXISTS `consumable_transfers_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `transfer_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_warehouse_id` int NOT NULL,
  `to_warehouse_id` int NOT NULL,
  `transfer_date` date NOT NULL,
  `status` enum('draft','confirmed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_ctr_from_wh` (`from_warehouse_id`),
  KEY `fk_ctr_to_wh` (`to_warehouse_id`),
  CONSTRAINT `fk_ctr_from_wh` FOREIGN KEY (`from_warehouse_id`) REFERENCES `warehouses_ret` (`id`),
  CONSTRAINT `fk_ctr_to_wh` FOREIGN KEY (`to_warehouse_id`) REFERENCES `warehouses_ret` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.consumable_transfer_items_ret
CREATE TABLE IF NOT EXISTS `consumable_transfer_items_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `transfer_id` int NOT NULL,
  `item_id` int NOT NULL,
  `packaging_id` int DEFAULT NULL,
  `packaging_qty` decimal(14,4) DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_cost_base` decimal(12,4) NOT NULL,
  `movement_out_id` int DEFAULT NULL,
  `movement_in_id` int DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `fk_ctri_transfer` (`transfer_id`),
  CONSTRAINT `fk_ctri_transfer` FOREIGN KEY (`transfer_id`) REFERENCES `consumable_transfers_ret` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.consumable_units_ret
CREATE TABLE IF NOT EXISTS `consumable_units_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='واحدات قياس المستهلكات';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.currencies
CREATE TABLE IF NOT EXISTS `currencies` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `symbol` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT '1.0000' COMMENT 'سعر الصرف إلى العملة الوظيفية الأساسية للفرع (وليس بالضرورة الدولار)',
  `is_base` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = هذه هي العملة الوظيفية الأساسية للفرع (المحدَّدة فعلياً بـ branches.base_currency، وليست بالضرورة الدولار)',
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.customers_ret
CREATE TABLE IF NOT EXISTS `customers_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_person` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` enum('individual','company') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'individual',
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `tax_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `shipping_company` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shipping_code` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `credit_limit` decimal(15,2) NOT NULL DEFAULT '0.00',
  `discount_percentage` decimal(5,2) NOT NULL DEFAULT '0.00',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `account_id` int DEFAULT NULL COMMENT 'حساب الذمم في شجرة الحسابات',
  `prepaid_account_id` int DEFAULT NULL COMMENT 'حساب الدفعات المقدمة',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `branch_relation` enum('internal','external') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'external' COMMENT 'هل العميل فرع داخلي بالشركة أو عميل خارجي عادي',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_account_id` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.exchange_rates_ret
CREATE TABLE IF NOT EXISTS `exchange_rates_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `currency_from` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `currency_to` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `rate` decimal(14,6) NOT NULL DEFAULT '1.000000',
  `rate_date` date NOT NULL,
  `source` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_currency_date` (`currency_from`,`currency_to`,`rate_date`),
  KEY `idx_rate_date` (`rate_date`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.expenses_ret
CREATE TABLE IF NOT EXISTS `expenses_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `expense_account_id` int NOT NULL,
  `cash_account_id` int NOT NULL,
  `amount_original` decimal(15,4) NOT NULL,
  `currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `exchange_rate` decimal(14,6) NOT NULL DEFAULT '1.000000',
  `amount_base` decimal(15,4) NOT NULL COMMENT 'المبلغ بعملة التقارير الموحّدة للشركة',
  `description` text COLLATE utf8mb4_unicode_ci,
  `expense_date` date NOT NULL,
  `journal_entry_id` int DEFAULT NULL,
  `status` enum('active','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_expense_date` (`expense_date`),
  KEY `idx_expense_acct` (`expense_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.hr_attendance_ret
CREATE TABLE IF NOT EXISTS `hr_attendance_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `attendance_date` date NOT NULL,
  `check_in` time DEFAULT NULL,
  `check_out` time DEFAULT NULL,
  `hours_worked` decimal(5,2) DEFAULT '0.00',
  `overtime_hours` decimal(5,2) DEFAULT '0.00',
  `attendance_status` enum('present','absent','late','half_day','holiday') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'present',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_attendance` (`employee_id`,`attendance_date`),
  KEY `idx_employee` (`employee_id`),
  KEY `idx_date` (`attendance_date`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.hr_bonuses_ret
CREATE TABLE IF NOT EXISTS `hr_bonuses_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `bonus_date` date NOT NULL,
  `bonus_type` enum('performance','holiday','commission','transport','housing','other') COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency_id` int NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_employee` (`employee_id`),
  KEY `idx_date` (`bonus_date`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.hr_employees_ret
CREATE TABLE IF NOT EXISTS `hr_employees_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `full_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `position` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `department` enum('sales','production','admin','logistics','accounting') COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hire_date` date NOT NULL,
  `salary_type` enum('monthly','weekly','daily','hourly') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `basic_salary` decimal(12,2) NOT NULL DEFAULT '0.00',
  `currency_id` int DEFAULT NULL,
  `bank_account` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `monday_from` tinyint DEFAULT '8' COMMENT 'ساعة بداية الإثنين  — NULL = عطلة',
  `monday_to` tinyint DEFAULT '18',
  `tuesday_from` tinyint DEFAULT '8',
  `tuesday_to` tinyint DEFAULT '18',
  `wednesday_from` tinyint DEFAULT '8',
  `wednesday_to` tinyint DEFAULT '18',
  `thursday_from` tinyint DEFAULT '8',
  `thursday_to` tinyint DEFAULT '18',
  `friday_from` tinyint DEFAULT NULL,
  `friday_to` tinyint DEFAULT NULL,
  `saturday_from` tinyint DEFAULT '8',
  `saturday_to` tinyint DEFAULT '18',
  `sunday_from` tinyint DEFAULT NULL,
  `sunday_to` tinyint DEFAULT NULL,
  `work_schedule` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin COMMENT 'جدول الدوام الأسبوعي: {monday:{on:true,from:8,to:18},...}',
  `overtime_multiplier` decimal(3,1) NOT NULL DEFAULT '1.5' COMMENT 'معامل الأوفرتايم: 1.5 = ساعة ونص، 2.0 = ضعف الساعة',
  `status` enum('active','inactive','on_leave') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_department` (`department`),
  KEY `idx_status` (`status`),
  KEY `idx_currency_id` (`currency_id`),
  CONSTRAINT `fk_currency_hr_employees` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `hr_employees_ret_chk_1` CHECK (json_valid(`work_schedule`))
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.hr_loans_ret
CREATE TABLE IF NOT EXISTS `hr_loans_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `loan_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency_id` int NOT NULL,
  `installments` int NOT NULL DEFAULT '1' COMMENT 'عدد الأقساط',
  `paid_installments` int NOT NULL DEFAULT '0',
  `monthly_deduction` decimal(12,2) NOT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `status` enum('active','completed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_employee` (`employee_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.hr_payroll_ret
CREATE TABLE IF NOT EXISTS `hr_payroll_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `payroll_month` date NOT NULL COMMENT 'شهر الراتب (اول يوم في الشهر)',
  `week_number` tinyint(1) DEFAULT '0' COMMENT '0=شهري، 1..4=أسبوع',
  `period_from` date DEFAULT NULL,
  `period_to` date DEFAULT NULL,
  `basic_salary` decimal(12,2) NOT NULL,
  `working_days` int NOT NULL DEFAULT '0',
  `working_hours` decimal(6,2) NOT NULL DEFAULT '0.00',
  `overtime_hours` decimal(6,2) NOT NULL DEFAULT '0.00',
  `overtime_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `bonus_total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `loan_deduction` decimal(12,2) NOT NULL DEFAULT '0.00',
  `other_deductions` decimal(12,2) NOT NULL DEFAULT '0.00',
  `net_salary` decimal(12,2) NOT NULL,
  `currency_id` int NOT NULL,
  `payment_status` enum('pending','paid','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `payment_date` date DEFAULT NULL,
  `payment_method` enum('cash','bank_transfer') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `journal_entry_id` int DEFAULT NULL,
  `cash_account_id` int DEFAULT NULL,
  `exchange_rate` decimal(15,6) NOT NULL DEFAULT '1.000000',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_emp_period` (`employee_id`,`period_from`),
  KEY `idx_month` (`payroll_month`),
  KEY `idx_status` (`payment_status`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.hr_promotions_ret
CREATE TABLE IF NOT EXISTS `hr_promotions_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `promotion_date` date NOT NULL,
  `old_position` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `new_position` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `old_salary` decimal(12,2) NOT NULL,
  `new_salary` decimal(12,2) NOT NULL,
  `currency_id` int NOT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_employee` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.internal_orders
CREATE TABLE IF NOT EXISTS `internal_orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ALP-ORD-0001',
  `from_branch_id` int NOT NULL COMMENT 'الفرع الطالب (حلب)',
  `to_branch_id` int NOT NULL COMMENT 'الفرع المورد (معمل حلب)',
  `order_date` date NOT NULL,
  `required_date` date DEFAULT NULL COMMENT 'تاريخ التسليم المطلوب',
  `currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT '1.0000',
  `total_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` enum('draft','sent','reviewing','approved','partially_approved','rejected','converted','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `purchase_id` int DEFAULT NULL COMMENT 'ID في purchases_alp بعد التحويل',
  `responded_by` int DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  `response_notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int NOT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_number` (`order_number`),
  KEY `idx_from_branch` (`from_branch_id`),
  KEY `idx_to_branch` (`to_branch_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الطلبات الداخلية بين الفروع';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.internal_order_items
CREATE TABLE IF NOT EXISTS `internal_order_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `variant_id` int DEFAULT NULL,
  `product_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `color` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `barcode` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity_requested` decimal(10,2) NOT NULL,
  `quantity_approved` decimal(10,2) DEFAULT NULL COMMENT 'الكمية المعتمدة من المعمل',
  `unit_price` decimal(10,4) DEFAULT NULL COMMENT 'سعر الوحدة بالعملة المحددة',
  `unit_price_base` decimal(10,4) DEFAULT NULL,
  `total_price` decimal(12,2) DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','approved','partially_approved','rejected','unavailable') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  CONSTRAINT `fk_ioi_order` FOREIGN KEY (`order_id`) REFERENCES `internal_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بنود الطلبات الداخلية';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.inventory_movements_ret
CREATE TABLE IF NOT EXISTS `inventory_movements_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `movement_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `movement_type` enum('in','out','adjustment','transfer') COLLATE utf8mb4_unicode_ci NOT NULL,
  `warehouse_id` int DEFAULT NULL,
  `items_count` int NOT NULL DEFAULT '0',
  `total_quantity` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total_value_base` decimal(12,2) DEFAULT NULL,
  `reference_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_id` int DEFAULT NULL,
  `reference_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `movement_number` (`movement_number`),
  KEY `idx_movement_type` (`movement_type`),
  KEY `idx_reference` (`reference_type`,`reference_id`),
  KEY `idx_warehouse_id` (`warehouse_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.inventory_movement_details_ret
CREATE TABLE IF NOT EXISTS `inventory_movement_details_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `movement_id` int NOT NULL,
  `variant_id` int NOT NULL,
  `product_id` int NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,4) DEFAULT NULL,
  `cost_price` decimal(10,4) NOT NULL DEFAULT '0.0000' COMMENT 'سعر التكلفة بعملة التقارير الموحّدة للشركة',
  `total_value` decimal(12,2) DEFAULT NULL,
  `balance_before` decimal(10,2) DEFAULT NULL,
  `balance_after` decimal(10,2) DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_movement_id` (`movement_id`),
  KEY `idx_variant_id` (`variant_id`),
  CONSTRAINT `fk_imd_movement` FOREIGN KEY (`movement_id`) REFERENCES `inventory_movements_ret` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.invoice_account_settings_ret
CREATE TABLE IF NOT EXISTS `invoice_account_settings_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` int NOT NULL,
  `account_code` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.journal_entries_ret
CREATE TABLE IF NOT EXISTS `journal_entries_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `entry_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_date` date NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `currency_id` int NOT NULL,
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT '1.0000',
  `total_debit` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_credit` decimal(15,2) NOT NULL DEFAULT '0.00',
  `status` enum('draft','posted','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `reference_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_id` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `posted_by` int DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `entry_number` (`entry_number`),
  KEY `idx_entry_date` (`entry_date`),
  KEY `idx_status` (`status`),
  KEY `idx_reference` (`reference_type`,`reference_id`),
  KEY `fk_journal_entries_ret_currency` (`currency_id`),
  CONSTRAINT `fk_je_currency_id` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `fk_journal_entries_ret_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.journal_entry_items_ret
CREATE TABLE IF NOT EXISTS `journal_entry_items_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `journal_entry_id` int NOT NULL,
  `account_id` int NOT NULL,
  `debit` decimal(15,2) NOT NULL DEFAULT '0.00',
  `credit` decimal(15,2) NOT NULL DEFAULT '0.00',
  `original_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `base_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `description` text COLLATE utf8mb4_unicode_ci,
  `currency_id` int NOT NULL,
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT '1.0000',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_journal_entry_id` (`journal_entry_id`),
  KEY `idx_account_id` (`account_id`),
  KEY `fk_ji_currency_id` (`currency_id`),
  CONSTRAINT `fk_jei_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries_ret` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ji_currency_id` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.migration_alp_to_ret_log
CREATE TABLE IF NOT EXISTS `migration_alp_to_ret_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `old_table` varchar(100) DEFAULT NULL,
  `new_table` varchar(100) DEFAULT NULL,
  `rows_before` bigint DEFAULT NULL,
  `rows_after` bigint DEFAULT NULL,
  `status` varchar(20) DEFAULT NULL,
  `message` varchar(255) DEFAULT NULL,
  `checked_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.modules
CREATE TABLE IF NOT EXISTS `modules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'مفتاح فريد: sales.invoices',
  `parent_key` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'مفتاح القسم الأب: sales',
  `label` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'الاسم للعرض',
  `icon` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT 'bi-circle',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `theme_color` varchar(7) COLLATE utf8mb4_unicode_ci DEFAULT '#3b82f6' COMMENT 'لون القسم (hex) — يُستخدم فقط على الصفوف الأب (parent_key IS NULL). يُدار من صفحة الإعدادات.',
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`),
  KEY `idx_parent_key` (`parent_key`),
  KEY `idx_key` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أقسام النظام وتدرجها';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.notifications_ret
CREATE TABLE IF NOT EXISTS `notifications_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `icon` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bi-bell',
  `link` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_is_read` (`is_read`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.production_entries_ret
CREATE TABLE IF NOT EXISTS `production_entries_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `operation_id` int NOT NULL,
  `entry_date` date NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `price_per_unit` decimal(10,4) NOT NULL,
  `total_base` decimal(12,4) NOT NULL,
  `product_id` int DEFAULT NULL COMMENT 'الموديل المرتبط',
  `worker_id` int DEFAULT NULL COMMENT 'العامل',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_operation_id` (`operation_id`),
  KEY `idx_entry_date` (`entry_date`),
  CONSTRAINT `fk_pe_operation` FOREIGN KEY (`operation_id`) REFERENCES `production_operations_ret` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.production_operations_ret
CREATE TABLE IF NOT EXISTS `production_operations_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'خياطة، تطريز، كحت',
  `unit` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'piece' COMMENT 'قطعة، دزينة، كغ',
  `default_price_base` decimal(10,4) DEFAULT NULL COMMENT 'السعر الافتراضي للوحدة بعملة التقارير الموحّدة للشركة',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.products_ret
CREATE TABLE IF NOT EXISTS `products_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `model_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'رقم الموديل — الكود الرئيسي',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` int DEFAULT NULL,
  `fabric_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supplier_id` int DEFAULT NULL,
  `image_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `model_number` (`model_number`),
  KEY `idx_model_number` (`model_number`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_supplier_id` (`supplier_id`),
  KEY `idx_is_active` (`is_active`),
  CONSTRAINT `fk_prod_category` FOREIGN KEY (`category_id`) REFERENCES `product_categories_ret` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_prod_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `product_suppliers_ret` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الموديلات الأب — بيانات مشتركة';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.product_categories_ret
CREATE TABLE IF NOT EXISTS `product_categories_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `parent_id` int DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `idx_parent_id` (`parent_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.product_colors_ret
CREATE TABLE IF NOT EXISTS `product_colors_ret` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hex_code` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#000000',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.product_sizes_ret
CREATE TABLE IF NOT EXISTS `product_sizes_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `size` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'القياس: 6,8,10,S,M,XL...',
  `age_type` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'سنة',
  `sort_order` int NOT NULL DEFAULT '0',
  `selling_price` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `cost_price` decimal(14,4) DEFAULT NULL,
  `base_currency_id` int DEFAULT NULL COMMENT 'عملة الفرع الأساسية وقت تسجيل السعر',
  `currency_id` int DEFAULT NULL COMMENT 'العملة المختارة عند إدخال السعر لأول مرة',
  `exchange_rate` decimal(15,6) NOT NULL DEFAULT '1.000000' COMMENT 'سعر الصرف بين عملة الفرع والعملة المختارة وقت التسجيل',
  `margin_pct` decimal(5,2) DEFAULT NULL,
  `packet_qty` int DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `updated_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_product_size` (`product_id`,`size`,`age_type`),
  KEY `idx_product_id` (`product_id`),
  CONSTRAINT `fk_size_product` FOREIGN KEY (`product_id`) REFERENCES `products_ret` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قياسات كل موديل — سعر مستقل لكل قياس';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.product_suppliers_ret
CREATE TABLE IF NOT EXISTS `product_suppliers_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int DEFAULT NULL COMMENT 'حساب ذمة المورد',
  `prepaid_account_id` int DEFAULT NULL COMMENT 'حساب الدفعات المقدمة للمورد',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_person` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `tax_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` enum('manufacturer','distributor','wholesaler','retailer') COLLATE utf8mb4_unicode_ci DEFAULT 'wholesaler',
  `supplier_type` enum('product','consumable','both') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'product',
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `credit_limit` decimal(12,2) DEFAULT '0.00',
  `discount_percentage` decimal(5,2) DEFAULT '0.00',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.product_variants_ret
CREATE TABLE IF NOT EXISTS `product_variants_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `size_id` int NOT NULL,
  `color_id` int DEFAULT NULL,
  `barcode` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'باركود فريد لكل قياس+لون',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_size_color` (`size_id`,`color_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_barcode` (`barcode`),
  CONSTRAINT `fk_var_product` FOREIGN KEY (`product_id`) REFERENCES `products_ret` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_var_size` FOREIGN KEY (`size_id`) REFERENCES `product_sizes_ret` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='كل قياس+لون = سطر — السعر مورث من product_sizes_alp';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.public_holidays_ret
CREATE TABLE IF NOT EXISTS `public_holidays_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `holiday_date` date NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'عيد الفطر، عيد الميلاد...',
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_recurring` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = تتكرر كل سنة (نفس الشهر واليوم)',
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_date` (`holiday_date`),
  KEY `idx_recurring` (`is_recurring`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='العطل الرسمية';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.purchases_ret
CREATE TABLE IF NOT EXISTS `purchases_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `purchase_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `supplier_id` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `purchase_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `settlement_discount_pct` decimal(5,2) DEFAULT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT '0.00' COMMENT 'بعملة الفاتورة',
  `tax_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `final_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `final_amount_base_currency` decimal(15,4) DEFAULT NULL COMMENT 'الإجمالي بعملة الفرع',
  `paid_amount` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `balance_amount` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `invoice_currency_id` int DEFAULT NULL,
  `base_currency_id` int DEFAULT NULL,
  `warehouse_id` int DEFAULT NULL,
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT '1.0000',
  `payment_status` enum('pending','partial','paid') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `payment_method` enum('cash','bank_transfer','check','credit_card') COLLATE utf8mb4_unicode_ci DEFAULT 'cash',
  `journal_entry_id` int DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` enum('draft','confirmed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `user_id` int NOT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_number` (`purchase_number`),
  KEY `idx_supplier_id` (`supplier_id`),
  KEY `idx_purchase_date` (`purchase_date`),
  KEY `idx_status` (`status`),
  KEY `idx_journal_entry` (`journal_entry_id`),
  KEY `fk_purchases_alp_created_by` (`created_by`),
  KEY `fk_purchases_alp_invoice_currency` (`invoice_currency_id`),
  KEY `fk_purchases_alp_base_currency` (`base_currency_id`),
  KEY `fk_purchases_alp_warehouse` (`warehouse_id`),
  CONSTRAINT `fk_purchases_alp_base_currency` FOREIGN KEY (`base_currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `fk_purchases_alp_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_purchases_alp_invoice_currency` FOREIGN KEY (`invoice_currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `fk_purchases_alp_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_ret` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.purchase_items_ret
CREATE TABLE IF NOT EXISTS `purchase_items_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `purchase_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `variant_id` int DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,4) NOT NULL COMMENT 'بعملة فاتورة الشراء',
  `unit_price_base_currency` decimal(10,4) DEFAULT NULL COMMENT 'بعملة الفرع الأساسية= unit_price / exchange_rate',
  `total_price` decimal(12,2) NOT NULL,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `discount_percentage` decimal(5,2) NOT NULL DEFAULT '0.00',
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_purchase_id` (`purchase_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_variant_id` (`variant_id`),
  KEY `fk_purchase_items_alp_created_by` (`created_by`),
  KEY `fk_purchase_items_alp_updated_by` (`updated_by`),
  CONSTRAINT `fk_pi_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases_ret` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_purchase_items_alp_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_purchase_items_alp_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.purchase_payments_ret
CREATE TABLE IF NOT EXISTS `purchase_payments_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `payment_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_date` date NOT NULL,
  `supplier_id` int DEFAULT NULL,
  `supplier_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(18,4) NOT NULL,
  `currency` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `exchange_rate` decimal(18,6) NOT NULL DEFAULT '1.000000',
  `amount_base` decimal(18,4) NOT NULL,
  `payment_method` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `cash_account_id` int DEFAULT NULL,
  `debit_account_id` int DEFAULT NULL COMMENT 'للدفعة العامة بدون مورد — الحساب المدين المختار يدوياً',
  `notes` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('draft','posted','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `journal_entry_id` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_number` (`payment_number`),
  KEY `idx_supplier` (`supplier_id`),
  KEY `idx_status` (`status`),
  KEY `idx_date` (`payment_date`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.purchase_payment_invoices_ret
CREATE TABLE IF NOT EXISTS `purchase_payment_invoices_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `payment_id` int NOT NULL,
  `purchase_id` int NOT NULL,
  `allocated_amount` decimal(18,4) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_payment` (`payment_id`),
  KEY `idx_purchase` (`purchase_id`),
  CONSTRAINT `fk_ppi_payment_ret` FOREIGN KEY (`payment_id`) REFERENCES `purchase_payments_ret` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.purchase_returns_ret
CREATE TABLE IF NOT EXISTS `purchase_returns_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `return_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purchase_id` int NOT NULL,
  `purchase_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supplier_id` int NOT NULL,
  `supplier_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `warehouse_id` int DEFAULT NULL,
  `return_date` date NOT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `return_amount` decimal(15,2) DEFAULT NULL,
  `discount_percentage` decimal(5,2) NOT NULL DEFAULT '0.00',
  `return_currency_id` int DEFAULT NULL,
  `base_currency_id` int DEFAULT NULL,
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT '1.0000',
  `payment_handling` enum('not_paid','partial','paid_full') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_paid',
  `refund_account_id` int DEFAULT NULL,
  `target_account_type` enum('cash','supplier','advance') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `journal_entry_id` int DEFAULT NULL,
  `status` enum('draft','posted','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `return_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `return_number` (`return_number`),
  KEY `idx_purchase_id` (`purchase_id`),
  KEY `idx_supplier_id` (`supplier_id`),
  KEY `fk_pr_currency` (`return_currency_id`),
  KEY `fk_pr_base_currency` (`base_currency_id`),
  KEY `fk_pr_warehouse` (`warehouse_id`),
  CONSTRAINT `fk_pr_base_currency` FOREIGN KEY (`base_currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `fk_pr_currency` FOREIGN KEY (`return_currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `fk_pr_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases_ret` (`id`),
  CONSTRAINT `fk_pr_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `product_suppliers_ret` (`id`),
  CONSTRAINT `fk_pr_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_ret` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.purchase_return_items_ret
CREATE TABLE IF NOT EXISTS `purchase_return_items_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `return_id` int NOT NULL,
  `purchase_item_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `variant_id` int DEFAULT NULL,
  `product_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity_returned` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,4) NOT NULL,
  `total_price` decimal(12,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_return_id` (`return_id`),
  KEY `idx_purchase_item_id` (`purchase_item_id`),
  CONSTRAINT `fk_pri_return` FOREIGN KEY (`return_id`) REFERENCES `purchase_returns_ret` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.receipts_ret
CREATE TABLE IF NOT EXISTS `receipts_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `receipt_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `receipt_date` date NOT NULL,
  `customer_id` int NOT NULL,
  `customer_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(15,4) NOT NULL COMMENT 'المبلغ بعملة القبض',
  `currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT '1.0000',
  `amount_base` decimal(15,4) NOT NULL COMMENT 'المبلغ بعملة التقارير الموحّدة للشركة',
  `payment_method` enum('cash','bank','card','check') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `cash_account_id` int DEFAULT NULL,
  `journal_entry_id` int DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` enum('draft','posted','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_by` int NOT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `receipt_number` (`receipt_number`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_receipt_date` (`receipt_date`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سندات القبض — مستقلة عن الفواتير';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.receipt_invoices_ret
CREATE TABLE IF NOT EXISTS `receipt_invoices_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `receipt_id` int NOT NULL,
  `invoice_id` int NOT NULL,
  `allocated_amount` decimal(15,4) NOT NULL COMMENT 'المبلغ المُوزَّع بعملة التقارير الموحّدة للشركة',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_receipt_invoice` (`receipt_id`,`invoice_id`),
  KEY `idx_invoice_id` (`invoice_id`),
  CONSTRAINT `fk_ri_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices_ret` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ri_receipt` FOREIGN KEY (`receipt_id`) REFERENCES `receipts_ret` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='توزيع سند القبض على فواتير متعددة';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.sales_invoices_ret
CREATE TABLE IF NOT EXISTS `sales_invoices_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `settlement_discount_pct` decimal(5,2) DEFAULT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `final_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `final_amount_base_currency` decimal(15,4) DEFAULT NULL,
  `paid_amount` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `balance_amount` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `invoice_currency_id` int DEFAULT NULL,
  `base_currency_id` int DEFAULT NULL,
  `warehouse_id` int DEFAULT NULL,
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT '1.0000',
  `payment_status` enum('pending','partial','paid') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `payment_method` enum('cash','bank_transfer','check','credit_card') COLLATE utf8mb4_unicode_ci DEFAULT 'cash',
  `journal_entry_id` int DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` enum('draft','confirmed','received','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `user_id` int NOT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sales_invoices_ret_number` (`invoice_number`),
  KEY `idx_sales_invoices_ret_customer` (`customer_id`),
  KEY `idx_sales_invoices_ret_created_by` (`created_by`),
  KEY `idx_sales_invoices_ret_date` (`invoice_date`),
  KEY `idx_sales_invoices_ret_inv_currency` (`invoice_currency_id`),
  KEY `idx_sales_invoices_ret_base_currency` (`base_currency_id`),
  KEY `idx_sales_invoices_ret_warehouse` (`warehouse_id`),
  KEY `idx_sales_invoices_ret_je` (`journal_entry_id`),
  KEY `idx_sales_invoices_ret_status` (`status`),
  CONSTRAINT `fk_sales_invoices_ret_base_currency` FOREIGN KEY (`base_currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `fk_sales_invoices_ret_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers_ret` (`id`),
  CONSTRAINT `fk_sales_invoices_ret_inv_currency` FOREIGN KEY (`invoice_currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `fk_sales_invoices_ret_je` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries_ret` (`id`),
  CONSTRAINT `fk_sales_invoices_ret_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_ret` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.sales_invoice_items_ret
CREATE TABLE IF NOT EXISTS `sales_invoice_items_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `variant_id` int DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,4) NOT NULL,
  `unit_price_base_currency` decimal(10,4) DEFAULT NULL,
  `total_price` decimal(12,2) NOT NULL,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `discount_percentage` decimal(5,2) NOT NULL DEFAULT '0.00',
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sales_invoice_items_ret_invoice` (`invoice_id`),
  KEY `idx_sales_invoice_items_ret_product` (`product_id`),
  KEY `idx_sales_invoice_items_ret_variant` (`variant_id`),
  KEY `idx_sales_invoice_items_ret_created_by` (`created_by`),
  KEY `idx_sales_invoice_items_ret_updated_by` (`updated_by`),
  CONSTRAINT `fk_sales_invoice_items_ret_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices_ret` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sales_invoice_items_ret_product` FOREIGN KEY (`product_id`) REFERENCES `products_ret` (`id`),
  CONSTRAINT `fk_sales_invoice_items_ret_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants_ret` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.sales_returns_ret
CREATE TABLE IF NOT EXISTS `sales_returns_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `return_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invoice_id` int NOT NULL,
  `invoice_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_id` int NOT NULL,
  `customer_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `warehouse_id` int DEFAULT NULL,
  `return_date` date NOT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `return_amount` decimal(15,2) DEFAULT NULL,
  `discount_percentage` decimal(5,2) NOT NULL DEFAULT '0.00',
  `return_currency_id` int DEFAULT NULL,
  `base_currency_id` int DEFAULT NULL,
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT '1.0000',
  `payment_handling` enum('not_paid','partial','paid_refund_cash','paid_credit_customer') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_paid',
  `refund_account_id` int DEFAULT NULL,
  `journal_entry_id` int DEFAULT NULL,
  `status` enum('draft','posted','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `return_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `return_number` (`return_number`),
  KEY `idx_sr_invoice_id` (`invoice_id`),
  KEY `idx_sr_customer_id` (`customer_id`),
  KEY `fk_sr_currency` (`return_currency_id`),
  KEY `fk_sr_base_currency` (`base_currency_id`),
  KEY `fk_sr_warehouse` (`warehouse_id`),
  CONSTRAINT `fk_sr_base_currency` FOREIGN KEY (`base_currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `fk_sr_currency` FOREIGN KEY (`return_currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `fk_sr_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers_ret` (`id`),
  CONSTRAINT `fk_sr_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices_ret` (`id`),
  CONSTRAINT `fk_sr_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_ret` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.sales_return_items_ret
CREATE TABLE IF NOT EXISTS `sales_return_items_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `return_id` int NOT NULL,
  `invoice_item_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `variant_id` int DEFAULT NULL,
  `product_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity_returned` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,4) NOT NULL,
  `total_price` decimal(12,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_return_id` (`return_id`),
  KEY `idx_invoice_item` (`invoice_item_id`),
  CONSTRAINT `fk_sri_return` FOREIGN KEY (`return_id`) REFERENCES `sales_returns_ret` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.shipping_carriers
CREATE TABLE IF NOT EXISTS `shipping_carriers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_person` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_id` int DEFAULT NULL,
  `payable_account_id` int DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT '0000-00-00 00:00:00' ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.tax_types_ret
CREATE TABLE IF NOT EXISTS `tax_types_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tax_scope` enum('sales','purchase','withholding') COLLATE utf8mb4_unicode_ci NOT NULL,
  `calc_type` enum('percentage','fixed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'percentage',
  `rate_value` decimal(10,4) NOT NULL DEFAULT '0.0000' COMMENT 'نسبة مئوية (مثلاً 16.0000) أو مبلغ ثابت حسب calc_type',
  `account_id` int DEFAULT NULL COMMENT 'الحساب المحاسبي المرتبط — عادة من إعدادات الربط، بس يُسمح بتخصيص حساب مختلف لكل نوع ضريبة',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_scope` (`tax_scope`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.users
CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'bcrypt hash',
  `role` enum('admin','accountant','sales','purchases','warehouse','user') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_username` (`username`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='المستخدمون';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.user_activities
CREATE TABLE IF NOT EXISTS `user_activities` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `branch_id` int DEFAULT NULL,
  `activity_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'login|logout|create|update|delete',
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_branch_id` (`branch_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل نشاط المستخدمين';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.user_branches
CREATE TABLE IF NOT EXISTS `user_branches` (
  `user_id` int NOT NULL,
  `branch_id` int NOT NULL,
  PRIMARY KEY (`user_id`,`branch_id`),
  KEY `fk_ub_branch` (`branch_id`),
  CONSTRAINT `fk_ub_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ub_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='صلاحيات المستخدم على الفروع';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.user_permissions
CREATE TABLE IF NOT EXISTS `user_permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `module_key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'مفتاح القسم مثل: sales.invoices',
  `can_view` tinyint(1) NOT NULL DEFAULT '0',
  `can_create` tinyint(1) NOT NULL DEFAULT '0',
  `can_edit` tinyint(1) NOT NULL DEFAULT '0',
  `can_delete` tinyint(1) NOT NULL DEFAULT '0',
  `can_confirm` tinyint(1) NOT NULL DEFAULT '0',
  `can_print` tinyint(1) NOT NULL DEFAULT '0',
  `can_export` tinyint(1) NOT NULL DEFAULT '0',
  `granted_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_branch_module` (`user_id`,`branch_id`,`module_key`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_branch_id` (`branch_id`),
  CONSTRAINT `fk_up_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_up_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='صلاحيات كل مستخدم في كل فرع على كل قسم';

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.user_tab_order
CREATE TABLE IF NOT EXISTS `user_tab_order` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `page_group` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'مثلاً: finance — لو انضافت مجموعات تبويبات تانية بالمستقبل (مبيعات/مشتريات) بتستخدم نفس الجدول بقيمة مختلفة',
  `tab_order` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'JSON array بترتيب أسماء الملفات، مثلاً ["accounts.php","journal.php",...]',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_group` (`user_id`,`page_group`),
  CONSTRAINT `fk_uto_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.warehouses_ret

-- Data exporting was unselected.

-- Dumping structure for table bayhas_local.warehouse_items_ret
CREATE TABLE IF NOT EXISTS `warehouse_items_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `warehouse_id` int NOT NULL,
  `variant_id` int NOT NULL,
  `product_id` int NOT NULL COMMENT 'الموديل الأب — للفلترة السريعة',
  `quantity` decimal(10,2) NOT NULL DEFAULT '0.00',
  `current_cost` decimal(12,4) NOT NULL DEFAULT '0.0000',
  `min_quantity` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'حد التنبيه',
  `last_movement_at` datetime DEFAULT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_wh_variant` (`warehouse_id`,`variant_id`),
  KEY `idx_variant_id` (`variant_id`),
  KEY `idx_product_id` (`product_id`),
  CONSTRAINT `fk_wi_product` FOREIGN KEY (`product_id`) REFERENCES `products_ret` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wi_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants_ret` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wi_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_ret` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مخزون كل Variant في كل مستودع';

