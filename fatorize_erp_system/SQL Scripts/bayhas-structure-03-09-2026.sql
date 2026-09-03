CREATE TABLE `account_charts_alpfac` (
  `id` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `account_type` enum('asset','liability','equity','revenue','expense') NOT NULL,
  `cash_flow_category` enum('operating','investing','financing','excluded','none') NOT NULL DEFAULT 'none' COMMENT 'تصنيف صريح لتقرير التدفقات النقدية — مخزَّن، مش مُستنتَج من الكود',
  `balance` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'الرصيد بعملة الحساب',
  `base_balance` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'الرصيد بعملة الفرع الأساسية',
  `currency_id` int(11) NOT NULL DEFAULT 1 COMMENT 'مرجع جدول currencies',
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `level` tinyint(4) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_locked` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'حساب نظامي لا يُحذف',
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='شجرة الحسابات';

-- --------------------------------------------------------

--
-- Table structure for table `account_charts_ret`
--

CREATE TABLE `account_charts_ret` (
  `id` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `account_type` enum('asset','liability','equity','revenue','expense') NOT NULL,
  `cash_flow_category` enum('operating','investing','financing','excluded','none') NOT NULL DEFAULT 'none' COMMENT 'تصنيف صريح لتقرير التدفقات النقدية — مخزَّن، مش مُستنتَج من الكود',
  `balance` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'الرصيد بعملة الحساب',
  `base_balance` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'الرصيد بعملة الفرع الأساسية',
  `currency_id` int(11) NOT NULL DEFAULT 1 COMMENT 'مرجع جدول currencies',
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `level` tinyint(4) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_locked` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'حساب نظامي لا يُحذف',
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='شجرة الحسابات';

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `name_en` varchar(100) DEFAULT NULL COMMENT 'الاسم بالإنجليزية',
  `tenant_slogan` varchar(255) DEFAULT NULL COMMENT 'سلوغن الشركة — يظهر بترويسة الطباعة',
  `branch_type` enum('retail','factory') NOT NULL DEFAULT 'retail',
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'Syria',
  `tax_number` varchar(50) DEFAULT NULL COMMENT 'الرقم الضريبي للفرع',
  `commercial_registration_number` varchar(50) DEFAULT NULL COMMENT 'رقم السجل الصناعي/التجاري للفرع',
  `factory_branch_id` int(11) DEFAULT NULL COMMENT 'معرف فرع المعمل/المصدر المرتبط بهذا الفرع — لإنشاء الطلبيات الداخلية',
  `base_currency` varchar(3) NOT NULL DEFAULT 'USD' COMMENT 'العملة الوظيفية للفرع (Functional Currency) — تُختار عند إنشاء الفرع وتتجمّد بعدها. منفصلة تماماً عن عملة التقارير الموحّدة على مستوى الشركة (fatorize_master.tenants.reporting_currency_id)',
  `local_currency` varchar(3) NOT NULL DEFAULT 'SYP' COMMENT 'العملة المحلية للمعاملات اليومية',
  `pricing_method` enum('fixed','cost_plus','market') NOT NULL DEFAULT 'cost_plus' COMMENT 'طريقة التسعير: ثابت | تكلفة+هامش | سعر السوق',
  `default_margin_pct` decimal(5,2) NOT NULL DEFAULT 20.00 COMMENT 'هامش الربح الافتراضي %',
  `costing_method` enum('last_cost','weighted_average') NOT NULL DEFAULT 'last_cost',
  `tax_rate_default` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'نسبة الضريبة الافتراضية %',
  `tax_input_recoverable` enum('non_recoverable','recoverable') NOT NULL DEFAULT 'non_recoverable',
  `allow_negative_stock` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'السماح بالمخزون السالب',
  `notify_low_stock` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'إشعار عند انخفاض المخزون',
  `notify_new_invoice` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'إشعار عند إنشاء فاتورة جديدة',
  `notify_internal_order` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'إشعار عند وصول طلبية داخلية من فرع آخر',
  `notify_email` varchar(200) DEFAULT NULL COMMENT 'بريد استقبال الإشعارات',
  `invoice_prefix` varchar(10) NOT NULL DEFAULT 'INV' COMMENT 'بادئة رقم الفاتورة مثل: ALP, IST',
  `invoice_counter` int(11) NOT NULL DEFAULT 0 COMMENT 'عداد الفواتير الحالي',
  `fiscal_year_start` tinyint(4) NOT NULL DEFAULT 1 COMMENT 'شهر بداية السنة المالية (1=يناير)',
  `week_start_day` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'يوم بداية الأسبوع: 0=أحد، 1=إثنين، 2=ثلاثاء، 3=أربعاء، 4=خميس، 5=جمعة، 6=سبت',
  `default_payment_terms` int(11) NOT NULL DEFAULT 30 COMMENT 'أيام الدفع الافتراضية للعملاء',
  `code` varchar(20) DEFAULT NULL,
  `table_suffix` varchar(10) DEFAULT '' COMMENT 'alp=حلب | ist=استنبول | gaz=عنتاب | lab=معمل',
  `dashboard_path` varchar(255) NOT NULL COMMENT 'مسار داشبورد الفرع بعد تسجيل الدخول',
  `icon` varchar(60) DEFAULT 'bi-building' COMMENT 'Bootstrap Icons class',
  `color` varchar(20) DEFAULT '#3b82f6',
  `sort_order` int(11) DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `base_currency_id` int(11) DEFAULT NULL,
  `local_currency_id` int(11) DEFAULT NULL,
  `default_purchase_tax_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `opening_balance_locked_at` datetime DEFAULT NULL COMMENT 'NULL = الأرصدة الافتتاحية لسا مفتوحة للتعديل، وإلا وقت القفل النهائي',
  `opening_balance_locked_by` int(11) DEFAULT NULL COMMENT 'المستخدم يلي قفلها نهائياً'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الفروع — كل فرع له table_suffix خاص به';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_categories_alpfac`
--

CREATE TABLE `consumable_categories_alpfac` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `icon` varchar(50) NOT NULL DEFAULT 'bi-tag',
  `color` varchar(20) NOT NULL DEFAULT '#64748b',
  `bg_color` varchar(20) NOT NULL DEFAULT '#f1f5f9',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='فئات المستهلكات';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_categories_ret`
--

CREATE TABLE `consumable_categories_ret` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `icon` varchar(50) NOT NULL DEFAULT 'bi-tag',
  `color` varchar(20) NOT NULL DEFAULT '#64748b',
  `bg_color` varchar(20) NOT NULL DEFAULT '#f1f5f9',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='فئات المستهلكات';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_departments_alpfac`
--

CREATE TABLE `consumable_departments_alpfac` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أقسام الجهات المستلمة للمستهلكات';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_departments_ret`
--

CREATE TABLE `consumable_departments_ret` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أقسام الجهات المستلمة للمستهلكات';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_issues_alpfac`
--

CREATE TABLE `consumable_issues_alpfac` (
  `id` int(11) NOT NULL,
  `issue_no` varchar(30) NOT NULL COMMENT 'ISS-2024-0001',
  `warehouse_id` int(11) NOT NULL COMMENT 'المستودع المصدر',
  `department` varchar(100) DEFAULT NULL COMMENT 'القسم/الجهة المستلمة',
  `department_id` int(11) DEFAULT NULL,
  `issue_date` date NOT NULL,
  `status` enum('draft','confirmed','cancelled') NOT NULL DEFAULT 'draft',
  `journal_entry_id` int(11) DEFAULT NULL,
  `is_posted` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أوامر الصرف الداخلي للمستهلكات';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_issues_ret`
--

CREATE TABLE `consumable_issues_ret` (
  `id` int(11) NOT NULL,
  `issue_no` varchar(30) NOT NULL COMMENT 'ISS-2024-0001',
  `warehouse_id` int(11) NOT NULL COMMENT 'المستودع المصدر',
  `department` varchar(100) DEFAULT NULL COMMENT 'القسم/الجهة المستلمة',
  `department_id` int(11) DEFAULT NULL,
  `issue_date` date NOT NULL,
  `status` enum('draft','confirmed','cancelled') NOT NULL DEFAULT 'draft',
  `journal_entry_id` int(11) DEFAULT NULL,
  `is_posted` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أوامر الصرف الداخلي للمستهلكات';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_issue_items_alpfac`
--

CREATE TABLE `consumable_issue_items_alpfac` (
  `id` int(11) NOT NULL,
  `issue_id` int(11) NOT NULL COMMENT 'FK → consumable_issues_alp',
  `item_id` int(11) NOT NULL COMMENT 'FK → consumable_items_alp',
  `packaging_id` int(11) DEFAULT NULL,
  `packaging_qty` decimal(14,4) DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `returned_qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `unit_cost_base` decimal(12,4) DEFAULT 0.0000 COMMENT 'تكلفة الوحدة بعملة التقارير الموحّدة للشركة',
  `total_cost_base` decimal(15,4) DEFAULT 0.0000 COMMENT 'إجمالي التكلفة بعملة التقارير الموحّدة للشركة',
  `movement_id` int(11) DEFAULT NULL COMMENT 'FK → consumable_movements_alp',
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تفاصيل أوامر الصرف الداخلي';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_issue_items_ret`
--

CREATE TABLE `consumable_issue_items_ret` (
  `id` int(11) NOT NULL,
  `issue_id` int(11) NOT NULL COMMENT 'FK → consumable_issues_alp',
  `item_id` int(11) NOT NULL COMMENT 'FK → consumable_items_alp',
  `packaging_id` int(11) DEFAULT NULL,
  `packaging_qty` decimal(14,4) DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `returned_qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `unit_cost_base` decimal(12,4) DEFAULT 0.0000 COMMENT 'تكلفة الوحدة بعملة التقارير الموحّدة للشركة',
  `total_cost_base` decimal(15,4) DEFAULT 0.0000 COMMENT 'إجمالي التكلفة بعملة التقارير الموحّدة للشركة',
  `movement_id` int(11) DEFAULT NULL COMMENT 'FK → consumable_movements_alp',
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تفاصيل أوامر الصرف الداخلي';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_items_alpfac`
--

CREATE TABLE `consumable_items_alpfac` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL COMMENT 'قهوة، ماء، قرطاسية، كهربا',
  `category` enum('utility','supplies','food','maintenance','other') NOT NULL DEFAULT 'other',
  `category_id` int(11) DEFAULT NULL,
  `unit` varchar(20) NOT NULL DEFAULT 'قطعة',
  `unit_id` int(11) DEFAULT NULL,
  `estimated_cost` decimal(10,8) NOT NULL DEFAULT 0.00000000 COMMENT 'تكلفة تقديرية للمقارنة',
  `last_purchase_price_base` decimal(15,4) DEFAULT NULL COMMENT 'آخر سعر شراء بعملة التقارير الموحّدة للشركة',
  `last_purchase_date` date DEFAULT NULL,
  `currency_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='المستهلكات';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_items_ret`
--

CREATE TABLE `consumable_items_ret` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL COMMENT 'قهوة، ماء، قرطاسية، كهربا',
  `category` enum('utility','supplies','food','maintenance','other') NOT NULL DEFAULT 'other',
  `category_id` int(11) DEFAULT NULL,
  `unit` varchar(20) NOT NULL DEFAULT 'قطعة',
  `unit_id` int(11) DEFAULT NULL,
  `estimated_cost` decimal(10,8) NOT NULL DEFAULT 0.00000000 COMMENT 'تكلفة تقديرية للمقارنة',
  `last_purchase_price_base` decimal(15,4) DEFAULT NULL COMMENT 'آخر سعر شراء بعملة التقارير الموحّدة للشركة',
  `last_purchase_date` date DEFAULT NULL,
  `currency_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='المستهلكات';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_item_packagings_alpfac`
--

CREATE TABLE `consumable_item_packagings_alpfac` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `qty_per_package` decimal(14,4) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='عبوات المستهلكات (عوامل التحويل)';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_item_packagings_ret`
--

CREATE TABLE `consumable_item_packagings_ret` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `qty_per_package` decimal(14,4) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='عبوات المستهلكات (عوامل التحويل)';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_movements_alpfac`
--

CREATE TABLE `consumable_movements_alpfac` (
  `id` int(11) NOT NULL,
  `movement_no` varchar(30) NOT NULL COMMENT 'رقم الحركة: MOV-2024-0001',
  `item_id` int(11) NOT NULL COMMENT 'FK → consumable_items_alp',
  `warehouse_id` int(11) NOT NULL COMMENT 'FK → warehouses_alp',
  `movement_type` enum('receive','issue','return_in','return_out','transfer','adjust','waste','opening') NOT NULL,
  `direction` enum('in','out') NOT NULL COMMENT 'داخل أو خارج المخزون',
  `quantity` decimal(12,3) NOT NULL COMMENT 'الكمية الموجبة دائماً',
  `unit_cost_base` decimal(12,4) DEFAULT 0.0000 COMMENT 'تكلفة الوحدة بعملة التقارير الموحّدة للشركة',
  `total_cost_base` decimal(15,4) DEFAULT 0.0000 COMMENT 'إجمالي التكلفة بعملة التقارير الموحّدة للشركة',
  `qty_before` decimal(12,3) DEFAULT 0.000 COMMENT 'الرصيد قبل الحركة',
  `qty_after` decimal(12,3) DEFAULT 0.000 COMMENT 'الرصيد بعد الحركة',
  `reference_type` enum('purchase','sale','issue','transfer','inventory','manual','consumable_return','opening_balance') NOT NULL,
  `reference_id` int(11) DEFAULT NULL COMMENT 'id المصدر',
  `to_warehouse_id` int(11) DEFAULT NULL COMMENT 'للنقل: المستودع المستهدف',
  `journal_entry_id` int(11) DEFAULT NULL COMMENT 'FK → journal_entries',
  `is_posted` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'هل رُحّل المحاسبياً؟',
  `movement_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='حركات مخزون المستهلكات — مستقلة عن الفواتير';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_movements_ret`
--

CREATE TABLE `consumable_movements_ret` (
  `id` int(11) NOT NULL,
  `movement_no` varchar(30) NOT NULL COMMENT 'رقم الحركة: MOV-2024-0001',
  `item_id` int(11) NOT NULL COMMENT 'FK → consumable_items_alp',
  `warehouse_id` int(11) NOT NULL COMMENT 'FK → warehouses_alp',
  `movement_type` enum('receive','issue','return_in','return_out','transfer','adjust','waste','opening') NOT NULL,
  `direction` enum('in','out') NOT NULL COMMENT 'داخل أو خارج المخزون',
  `quantity` decimal(12,3) NOT NULL COMMENT 'الكمية الموجبة دائماً',
  `unit_cost_base` decimal(12,4) DEFAULT 0.0000 COMMENT 'تكلفة الوحدة بعملة التقارير الموحّدة للشركة',
  `total_cost_base` decimal(15,4) DEFAULT 0.0000 COMMENT 'إجمالي التكلفة بعملة التقارير الموحّدة للشركة',
  `qty_before` decimal(12,3) DEFAULT 0.000 COMMENT 'الرصيد قبل الحركة',
  `qty_after` decimal(12,3) DEFAULT 0.000 COMMENT 'الرصيد بعد الحركة',
  `reference_type` enum('purchase','sale','issue','transfer','inventory','manual','consumable_return','opening_balance') NOT NULL,
  `reference_id` int(11) DEFAULT NULL COMMENT 'id المصدر',
  `to_warehouse_id` int(11) DEFAULT NULL COMMENT 'للنقل: المستودع المستهدف',
  `journal_entry_id` int(11) DEFAULT NULL COMMENT 'FK → journal_entries',
  `is_posted` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'هل رُحّل المحاسبياً؟',
  `movement_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='حركات مخزون المستهلكات — مستقلة عن الفواتير';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_purchases_alpfac`
--

CREATE TABLE `consumable_purchases_alpfac` (
  `id` int(11) NOT NULL,
  `invoice_no` varchar(50) NOT NULL COMMENT 'رقم الفاتورة الداخلي: PUR-2024-0001',
  `supplier_ref` varchar(100) DEFAULT NULL COMMENT 'رقم فاتورة المورد',
  `supplier_id` int(11) DEFAULT NULL COMMENT 'FK → product_suppliers_alp',
  `warehouse_id` int(11) NOT NULL COMMENT 'المستودع المستلِم',
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL COMMENT 'تاريخ الاستحقاق',
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `exchange_rate` decimal(14,6) NOT NULL DEFAULT 1.000000,
  `subtotal_orig` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `subtotal_base` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `discount_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `discount_base` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `discount_orig` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `tax_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `tax_base` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `tax_orig` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `total_base` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `total_orig` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `paid_base` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `paid_orig` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `balance_base` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `balance_orig` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `status` enum('draft','confirmed','partial','paid','cancelled') NOT NULL DEFAULT 'draft',
  `payment_method` enum('cash','bank','card','deferred') DEFAULT 'deferred',
  `journal_entry_id` int(11) DEFAULT NULL,
  `is_posted` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='فواتير شراء المستهلكات — رأس الفاتورة';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_purchases_ret`
--

CREATE TABLE `consumable_purchases_ret` (
  `id` int(11) NOT NULL,
  `invoice_no` varchar(50) NOT NULL COMMENT 'رقم الفاتورة الداخلي: PUR-2024-0001',
  `supplier_ref` varchar(100) DEFAULT NULL COMMENT 'رقم فاتورة المورد',
  `supplier_id` int(11) DEFAULT NULL COMMENT 'FK → product_suppliers_alp',
  `warehouse_id` int(11) NOT NULL COMMENT 'المستودع المستلِم',
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL COMMENT 'تاريخ الاستحقاق',
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `exchange_rate` decimal(14,6) NOT NULL DEFAULT 1.000000,
  `subtotal_orig` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `subtotal_base` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `discount_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `discount_base` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `discount_orig` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `tax_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `tax_base` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `tax_orig` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `total_base` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `total_orig` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `paid_base` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `paid_orig` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `balance_base` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `balance_orig` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `status` enum('draft','confirmed','partial','paid','cancelled') NOT NULL DEFAULT 'draft',
  `payment_method` enum('cash','bank','card','deferred') DEFAULT 'deferred',
  `journal_entry_id` int(11) DEFAULT NULL,
  `is_posted` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='فواتير شراء المستهلكات — رأس الفاتورة';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_purchase_items_alpfac`
--

CREATE TABLE `consumable_purchase_items_alpfac` (
  `id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL COMMENT 'FK → consumable_purchases_alp',
  `item_id` int(11) NOT NULL COMMENT 'FK → consumable_items_alp',
  `packaging_id` int(11) DEFAULT NULL,
  `packaging_qty` decimal(14,4) DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_price_orig` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `unit_price_base` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `total_orig` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `discount_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `total_base` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `movement_id` int(11) DEFAULT NULL COMMENT 'FK → consumable_movements_alp (حركة الاستلام)',
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تفاصيل فواتير شراء المستهلكات';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_purchase_items_ret`
--

CREATE TABLE `consumable_purchase_items_ret` (
  `id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL COMMENT 'FK → consumable_purchases_alp',
  `item_id` int(11) NOT NULL COMMENT 'FK → consumable_items_alp',
  `packaging_id` int(11) DEFAULT NULL,
  `packaging_qty` decimal(14,4) DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_price_orig` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `unit_price_base` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `total_orig` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `discount_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `total_base` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `movement_id` int(11) DEFAULT NULL COMMENT 'FK → consumable_movements_alp (حركة الاستلام)',
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تفاصيل فواتير شراء المستهلكات';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_returns_alpfac`
--

CREATE TABLE `consumable_returns_alpfac` (
  `id` int(11) NOT NULL,
  `return_no` varchar(30) NOT NULL,
  `issue_id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL,
  `return_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `journal_entry_id` int(11) DEFAULT NULL,
  `is_posted` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مرتجعات فواتير شراء المستهلكات';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_returns_ret`
--

CREATE TABLE `consumable_returns_ret` (
  `id` int(11) NOT NULL,
  `return_no` varchar(30) NOT NULL,
  `issue_id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL,
  `return_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `journal_entry_id` int(11) DEFAULT NULL,
  `is_posted` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مرتجعات فواتير شراء المستهلكات';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_return_items_alpfac`
--

CREATE TABLE `consumable_return_items_alpfac` (
  `id` int(11) NOT NULL,
  `return_id` int(11) NOT NULL,
  `issue_item_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_cost_base` decimal(12,4) NOT NULL,
  `total_cost_base` decimal(15,4) NOT NULL,
  `movement_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بنود مرتجعات فواتير شراء المستهلكات';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_return_items_ret`
--

CREATE TABLE `consumable_return_items_ret` (
  `id` int(11) NOT NULL,
  `return_id` int(11) NOT NULL,
  `issue_item_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_cost_base` decimal(12,4) NOT NULL,
  `total_cost_base` decimal(15,4) NOT NULL,
  `movement_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بنود مرتجعات فواتير شراء المستهلكات';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_stock_alpfac`
--

CREATE TABLE `consumable_stock_alpfac` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL COMMENT 'FK → consumable_items_alp',
  `warehouse_id` int(11) NOT NULL COMMENT 'FK → warehouses_alp',
  `quantity` decimal(12,3) NOT NULL DEFAULT 0.000 COMMENT 'الرصيد الحالي',
  `min_quantity` decimal(12,3) NOT NULL DEFAULT 0.000 COMMENT 'حد التنبيه',
  `avg_cost_base` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT 'متوسط التكلفة (Weighted Average) بعملة التقارير الموحّدة للشركة',
  `last_movement` datetime DEFAULT NULL COMMENT 'تاريخ آخر حركة',
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أرصدة المستهلكات لكل مستودع';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_stock_ret`
--

CREATE TABLE `consumable_stock_ret` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL COMMENT 'FK → consumable_items_alp',
  `warehouse_id` int(11) NOT NULL COMMENT 'FK → warehouses_alp',
  `quantity` decimal(12,3) NOT NULL DEFAULT 0.000 COMMENT 'الرصيد الحالي',
  `min_quantity` decimal(12,3) NOT NULL DEFAULT 0.000 COMMENT 'حد التنبيه',
  `avg_cost_base` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT 'متوسط التكلفة (Weighted Average) بعملة التقارير الموحّدة للشركة',
  `last_movement` datetime DEFAULT NULL COMMENT 'تاريخ آخر حركة',
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أرصدة المستهلكات لكل مستودع';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_transfers_alpfac`
--

CREATE TABLE `consumable_transfers_alpfac` (
  `id` int(11) NOT NULL,
  `transfer_no` varchar(30) NOT NULL,
  `from_warehouse_id` int(11) NOT NULL,
  `to_warehouse_id` int(11) NOT NULL,
  `transfer_date` date NOT NULL,
  `status` enum('draft','confirmed','cancelled') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `consumable_transfers_ret`
--

CREATE TABLE `consumable_transfers_ret` (
  `id` int(11) NOT NULL,
  `transfer_no` varchar(30) NOT NULL,
  `from_warehouse_id` int(11) NOT NULL,
  `to_warehouse_id` int(11) NOT NULL,
  `transfer_date` date NOT NULL,
  `status` enum('draft','confirmed','cancelled') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `consumable_transfer_items_alpfac`
--

CREATE TABLE `consumable_transfer_items_alpfac` (
  `id` int(11) NOT NULL,
  `transfer_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `packaging_id` int(11) DEFAULT NULL,
  `packaging_qty` decimal(14,4) DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_cost_base` decimal(12,4) NOT NULL,
  `movement_out_id` int(11) DEFAULT NULL,
  `movement_in_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `consumable_transfer_items_ret`
--

CREATE TABLE `consumable_transfer_items_ret` (
  `id` int(11) NOT NULL,
  `transfer_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `packaging_id` int(11) DEFAULT NULL,
  `packaging_qty` decimal(14,4) DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_cost_base` decimal(12,4) NOT NULL,
  `movement_out_id` int(11) DEFAULT NULL,
  `movement_in_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `consumable_units_alpfac`
--

CREATE TABLE `consumable_units_alpfac` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='واحدات قياس المستهلكات';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_units_ret`
--

CREATE TABLE `consumable_units_ret` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='واحدات قياس المستهلكات';

-- --------------------------------------------------------

--
-- Table structure for table `currencies`
--

CREATE TABLE `currencies` (
  `id` int(11) NOT NULL,
  `code` varchar(3) NOT NULL,
  `name` varchar(50) NOT NULL,
  `symbol` varchar(10) DEFAULT NULL,
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000 COMMENT 'سعر الصرف إلى العملة الوظيفية الأساسية للفرع (وليس بالضرورة الدولار)',
  `cash_account_id` int(11) DEFAULT NULL COMMENT 'رابط عرض إضافي — يشاور على account_charts.id بفرع محدَّد، المصدر الحقيقي يضل invoice_account_settings',
  `bank_account_id` int(11) DEFAULT NULL COMMENT 'رابط عرض إضافي — يشاور على account_charts.id بفرع محدَّد، المصدر الحقيقي يضل invoice_account_settings',
  `is_base` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = هذه هي العملة الوظيفية الأساسية للفرع (المحدَّدة فعلياً بـ branches.base_currency، وليست بالضرورة الدولار)',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `currency_branch_links`
--

CREATE TABLE `currency_branch_links` (
  `id` int(11) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `cash_account_id` int(11) DEFAULT NULL COMMENT 'يشاور على account_charts_{TS} تبع هذا الفرع تحديداً',
  `bank_account_id` int(11) DEFAULT NULL COMMENT 'يشاور على account_charts_{TS} تبع هذا الفرع تحديداً',
  `linked_by` int(11) DEFAULT NULL COMMENT 'المستخدم يلي فعّل/ربط هذه العملة بهذا الفرع',
  `linked_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customers_alpfac`
--

CREATE TABLE `customers_alpfac` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `type` enum('wholesale','retail','super_wholesale') NOT NULL DEFAULT 'retail',
  `pricing_pattern_id` int(11) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `tax_number` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `shipping_company` varchar(255) DEFAULT NULL,
  `shipping_code` varchar(100) DEFAULT NULL,
  `credit_limit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL COMMENT 'حساب الذمم في شجرة الحسابات',
  `prepaid_account_id` int(11) DEFAULT NULL COMMENT 'حساب الدفعات المقدمة',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `branch_relation` enum('internal','external') NOT NULL DEFAULT 'external' COMMENT 'هل العميل فرع داخلي بالشركة أو عميل خارجي عادي'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customers_ret`
--

CREATE TABLE `customers_ret` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `type` enum('wholesale','retail','super_wholesale') NOT NULL DEFAULT 'retail',
  `pricing_pattern_id` int(11) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `tax_number` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `shipping_company` varchar(255) DEFAULT NULL,
  `shipping_code` varchar(100) DEFAULT NULL,
  `credit_limit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL COMMENT 'حساب الذمم في شجرة الحسابات',
  `prepaid_account_id` int(11) DEFAULT NULL COMMENT 'حساب الدفعات المقدمة',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `branch_relation` enum('internal','external') NOT NULL DEFAULT 'external' COMMENT 'هل العميل فرع داخلي بالشركة أو عميل خارجي عادي'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exchange_rates_alpfac`
--

CREATE TABLE `exchange_rates_alpfac` (
  `id` int(11) NOT NULL,
  `currency_from` varchar(3) NOT NULL,
  `currency_to` varchar(3) NOT NULL DEFAULT 'USD',
  `rate` decimal(14,6) NOT NULL DEFAULT 1.000000,
  `rate_date` date NOT NULL,
  `source` varchar(50) NOT NULL DEFAULT 'manual',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exchange_rates_ret`
--

CREATE TABLE `exchange_rates_ret` (
  `id` int(11) NOT NULL,
  `currency_from` varchar(3) NOT NULL,
  `currency_to` varchar(3) NOT NULL DEFAULT 'USD',
  `rate` decimal(14,6) NOT NULL DEFAULT 1.000000,
  `rate_date` date NOT NULL,
  `source` varchar(50) NOT NULL DEFAULT 'manual',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expenses_alpfac`
--

CREATE TABLE `expenses_alpfac` (
  `id` int(11) NOT NULL,
  `expense_account_id` int(11) NOT NULL,
  `cash_account_id` int(11) NOT NULL,
  `amount_original` decimal(15,4) NOT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `exchange_rate` decimal(14,6) NOT NULL DEFAULT 1.000000,
  `amount_base` decimal(15,4) NOT NULL COMMENT 'المبلغ بعملة التقارير الموحّدة للشركة',
  `description` text DEFAULT NULL,
  `expense_date` date NOT NULL,
  `journal_entry_id` int(11) DEFAULT NULL,
  `status` enum('active','cancelled') NOT NULL DEFAULT 'active',
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expenses_ret`
--

CREATE TABLE `expenses_ret` (
  `id` int(11) NOT NULL,
  `expense_account_id` int(11) NOT NULL,
  `cash_account_id` int(11) NOT NULL,
  `amount_original` decimal(15,4) NOT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `exchange_rate` decimal(14,6) NOT NULL DEFAULT 1.000000,
  `amount_base` decimal(15,4) NOT NULL COMMENT 'المبلغ بعملة التقارير الموحّدة للشركة',
  `description` text DEFAULT NULL,
  `expense_date` date NOT NULL,
  `journal_entry_id` int(11) DEFAULT NULL,
  `status` enum('active','cancelled') NOT NULL DEFAULT 'active',
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hr_attendance_alpfac`
--

CREATE TABLE `hr_attendance_alpfac` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `check_in` time DEFAULT NULL,
  `check_out` time DEFAULT NULL,
  `hours_worked` decimal(5,2) DEFAULT 0.00,
  `overtime_hours` decimal(5,2) DEFAULT 0.00,
  `attendance_status` enum('present','absent','late','half_day','holiday') NOT NULL DEFAULT 'present',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hr_attendance_ret`
--

CREATE TABLE `hr_attendance_ret` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `check_in` time DEFAULT NULL,
  `check_out` time DEFAULT NULL,
  `hours_worked` decimal(5,2) DEFAULT 0.00,
  `overtime_hours` decimal(5,2) DEFAULT 0.00,
  `attendance_status` enum('present','absent','late','half_day','holiday') NOT NULL DEFAULT 'present',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hr_bonuses_alpfac`
--

CREATE TABLE `hr_bonuses_alpfac` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `bonus_date` date NOT NULL,
  `bonus_type` enum('performance','holiday','commission','transport','housing','other') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','cancelled') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hr_bonuses_ret`
--

CREATE TABLE `hr_bonuses_ret` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `bonus_date` date NOT NULL,
  `bonus_type` enum('performance','holiday','commission','transport','housing','other') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','cancelled') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hr_employees_alpfac`
--

CREATE TABLE `hr_employees_alpfac` (
  `id` int(11) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `position` varchar(150) NOT NULL,
  `department` enum('sales','production','admin','logistics','accounting') NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `hire_date` date NOT NULL,
  `salary_type` enum('monthly','weekly','daily','hourly') NOT NULL DEFAULT 'monthly',
  `basic_salary` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency_id` int(11) DEFAULT NULL,
  `payable_account_id` int(11) DEFAULT NULL,
  `loan_account_id` int(11) DEFAULT NULL,
  `bank_account` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `monday_from` tinyint(4) DEFAULT 8 COMMENT 'ساعة بداية الإثنين  — NULL = عطلة',
  `monday_to` tinyint(4) DEFAULT 18,
  `tuesday_from` tinyint(4) DEFAULT 8,
  `tuesday_to` tinyint(4) DEFAULT 18,
  `wednesday_from` tinyint(4) DEFAULT 8,
  `wednesday_to` tinyint(4) DEFAULT 18,
  `thursday_from` tinyint(4) DEFAULT 8,
  `thursday_to` tinyint(4) DEFAULT 18,
  `friday_from` tinyint(4) DEFAULT NULL,
  `friday_to` tinyint(4) DEFAULT NULL,
  `saturday_from` tinyint(4) DEFAULT 8,
  `saturday_to` tinyint(4) DEFAULT 18,
  `sunday_from` tinyint(4) DEFAULT NULL,
  `sunday_to` tinyint(4) DEFAULT NULL,
  `work_schedule` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'جدول الدوام الأسبوعي: {monday:{on:true,from:8,to:18},...}',
  `overtime_multiplier` decimal(3,1) NOT NULL DEFAULT 1.5 COMMENT 'معامل الأوفرتايم: 1.5 = ساعة ونص، 2.0 = ضعف الساعة',
  `status` enum('active','inactive','on_leave') NOT NULL DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `hr_employees_ret`
--

CREATE TABLE `hr_employees_ret` (
  `id` int(11) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `position` varchar(150) NOT NULL,
  `department` enum('sales','production','admin','logistics','accounting') NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `hire_date` date NOT NULL,
  `salary_type` enum('monthly','weekly','daily','hourly') NOT NULL DEFAULT 'monthly',
  `basic_salary` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency_id` int(11) DEFAULT NULL,
  `payable_account_id` int(11) DEFAULT NULL,
  `loan_account_id` int(11) DEFAULT NULL,
  `bank_account` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `monday_from` tinyint(4) DEFAULT 8 COMMENT 'ساعة بداية الإثنين  — NULL = عطلة',
  `monday_to` tinyint(4) DEFAULT 18,
  `tuesday_from` tinyint(4) DEFAULT 8,
  `tuesday_to` tinyint(4) DEFAULT 18,
  `wednesday_from` tinyint(4) DEFAULT 8,
  `wednesday_to` tinyint(4) DEFAULT 18,
  `thursday_from` tinyint(4) DEFAULT 8,
  `thursday_to` tinyint(4) DEFAULT 18,
  `friday_from` tinyint(4) DEFAULT NULL,
  `friday_to` tinyint(4) DEFAULT NULL,
  `saturday_from` tinyint(4) DEFAULT 8,
  `saturday_to` tinyint(4) DEFAULT 18,
  `sunday_from` tinyint(4) DEFAULT NULL,
  `sunday_to` tinyint(4) DEFAULT NULL,
  `work_schedule` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'جدول الدوام الأسبوعي: {monday:{on:true,from:8,to:18},...}',
  `overtime_multiplier` decimal(3,1) NOT NULL DEFAULT 1.5 COMMENT 'معامل الأوفرتايم: 1.5 = ساعة ونص، 2.0 = ضعف الساعة',
  `status` enum('active','inactive','on_leave') NOT NULL DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `hr_loans_alpfac`
--

CREATE TABLE `hr_loans_alpfac` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `loan_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `installments` int(11) NOT NULL DEFAULT 1 COMMENT 'عدد الأقساط',
  `paid_installments` int(11) NOT NULL DEFAULT 0,
  `paid_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `monthly_deduction` decimal(12,2) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('active','completed','cancelled') NOT NULL DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `journal_entry_id` int(11) DEFAULT NULL,
  `cancel_entry_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hr_loans_ret`
--

CREATE TABLE `hr_loans_ret` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `loan_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `installments` int(11) NOT NULL DEFAULT 1 COMMENT 'عدد الأقساط',
  `paid_installments` int(11) NOT NULL DEFAULT 0,
  `paid_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `monthly_deduction` decimal(12,2) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('active','completed','cancelled') NOT NULL DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `journal_entry_id` int(11) DEFAULT NULL,
  `cancel_entry_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hr_payroll_alpfac`
--

CREATE TABLE `hr_payroll_alpfac` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `payroll_month` date NOT NULL COMMENT 'شهر الراتب (اول يوم في الشهر)',
  `week_number` tinyint(1) DEFAULT 0 COMMENT '0=شهري، 1..4=أسبوع',
  `period_from` date DEFAULT NULL,
  `period_to` date DEFAULT NULL,
  `basic_salary` decimal(12,2) NOT NULL,
  `working_days` int(11) NOT NULL DEFAULT 0,
  `working_hours` decimal(6,2) NOT NULL DEFAULT 0.00,
  `overtime_hours` decimal(6,2) NOT NULL DEFAULT 0.00,
  `overtime_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `bonus_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `loan_deduction` decimal(12,2) NOT NULL DEFAULT 0.00,
  `other_deductions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_salary` decimal(12,2) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `payment_status` enum('pending','paid','cancelled','accrued') NOT NULL DEFAULT 'pending',
  `payment_date` date DEFAULT NULL,
  `payment_method` enum('cash','bank_transfer') DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `payment_entry_id` int(11) DEFAULT NULL,
  `cash_account_id` int(11) DEFAULT NULL,
  `exchange_rate` decimal(15,6) NOT NULL DEFAULT 1.000000,
  `accrual_entry_id` int(11) DEFAULT NULL,
  `cancel_accrual_entry_id` int(11) DEFAULT NULL,
  `cancel_payment_entry_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hr_payroll_loan_allocations_alpfac`
--

CREATE TABLE `hr_payroll_loan_allocations_alpfac` (
  `id` int(11) NOT NULL,
  `payroll_id` int(11) NOT NULL,
  `loan_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `reversed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hr_payroll_loan_allocations_ret`
--

CREATE TABLE `hr_payroll_loan_allocations_ret` (
  `id` int(11) NOT NULL,
  `payroll_id` int(11) NOT NULL,
  `loan_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `reversed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hr_payroll_ret`
--

CREATE TABLE `hr_payroll_ret` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `payroll_month` date NOT NULL COMMENT 'شهر الراتب (اول يوم في الشهر)',
  `week_number` tinyint(1) DEFAULT 0 COMMENT '0=شهري، 1..4=أسبوع',
  `period_from` date DEFAULT NULL,
  `period_to` date DEFAULT NULL,
  `basic_salary` decimal(12,2) NOT NULL,
  `working_days` int(11) NOT NULL DEFAULT 0,
  `working_hours` decimal(6,2) NOT NULL DEFAULT 0.00,
  `overtime_hours` decimal(6,2) NOT NULL DEFAULT 0.00,
  `overtime_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `bonus_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `loan_deduction` decimal(12,2) NOT NULL DEFAULT 0.00,
  `other_deductions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_salary` decimal(12,2) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `payment_status` enum('pending','paid','cancelled','accrued') NOT NULL DEFAULT 'pending',
  `payment_date` date DEFAULT NULL,
  `payment_method` enum('cash','bank_transfer') DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `payment_entry_id` int(11) DEFAULT NULL,
  `cash_account_id` int(11) DEFAULT NULL,
  `exchange_rate` decimal(15,6) NOT NULL DEFAULT 1.000000,
  `accrual_entry_id` int(11) DEFAULT NULL,
  `cancel_accrual_entry_id` int(11) DEFAULT NULL,
  `cancel_payment_entry_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hr_promotions_alpfac`
--

CREATE TABLE `hr_promotions_alpfac` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `promotion_date` date NOT NULL,
  `old_position` varchar(150) NOT NULL,
  `new_position` varchar(150) NOT NULL,
  `old_salary` decimal(12,2) NOT NULL,
  `new_salary` decimal(12,2) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `reason` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hr_promotions_ret`
--

CREATE TABLE `hr_promotions_ret` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `promotion_date` date NOT NULL,
  `old_position` varchar(150) NOT NULL,
  `new_position` varchar(150) NOT NULL,
  `old_salary` decimal(12,2) NOT NULL,
  `new_salary` decimal(12,2) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `reason` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `internal_orders`
--

CREATE TABLE `internal_orders` (
  `id` int(11) NOT NULL,
  `order_number` varchar(50) NOT NULL COMMENT 'ALP-ORD-0001',
  `from_branch_id` int(11) NOT NULL COMMENT 'الفرع الطالب (حلب)',
  `to_branch_id` int(11) NOT NULL COMMENT 'الفرع المورد (معمل حلب)',
  `order_date` date NOT NULL,
  `required_date` date DEFAULT NULL COMMENT 'تاريخ التسليم المطلوب',
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `status` enum('draft','sent','reviewing','approved','partially_approved','rejected','converted','cancelled') NOT NULL DEFAULT 'draft',
  `purchase_id` int(11) DEFAULT NULL COMMENT 'ID في purchases_alp بعد التحويل',
  `responded_by` int(11) DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  `response_notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الطلبات الداخلية بين الفروع';

-- --------------------------------------------------------

--
-- Table structure for table `internal_order_items`
--

CREATE TABLE `internal_order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `model_number` varchar(50) DEFAULT NULL,
  `size` varchar(20) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `quantity_requested` decimal(10,2) NOT NULL,
  `quantity_approved` decimal(10,2) DEFAULT NULL COMMENT 'الكمية المعتمدة من المعمل',
  `unit_price` decimal(10,4) DEFAULT NULL COMMENT 'سعر الوحدة بالعملة المحددة',
  `unit_price_base` decimal(10,4) DEFAULT NULL,
  `total_price` decimal(12,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('pending','approved','partially_approved','rejected','unavailable') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بنود الطلبات الداخلية';

-- --------------------------------------------------------

--
-- Table structure for table `inventory_movements_alpfac`
--

CREATE TABLE `inventory_movements_alpfac` (
  `id` int(11) NOT NULL,
  `movement_number` varchar(50) NOT NULL,
  `movement_type` enum('in','out','adjustment','transfer','opening') NOT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `items_count` int(11) NOT NULL DEFAULT 0,
  `total_quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_value_base` decimal(12,2) DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_movements_ret`
--

CREATE TABLE `inventory_movements_ret` (
  `id` int(11) NOT NULL,
  `movement_number` varchar(50) NOT NULL,
  `movement_type` enum('in','out','adjustment','transfer','opening') NOT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `items_count` int(11) NOT NULL DEFAULT 0,
  `total_quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_value_base` decimal(12,2) DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_movement_details_alpfac`
--

CREATE TABLE `inventory_movement_details_alpfac` (
  `id` int(11) NOT NULL,
  `movement_id` int(11) NOT NULL,
  `variant_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,4) DEFAULT NULL,
  `cost_price` decimal(10,4) NOT NULL DEFAULT 0.0000 COMMENT 'سعر التكلفة بعملة التقارير الموحّدة للشركة',
  `total_value` decimal(12,2) DEFAULT NULL,
  `balance_before` decimal(10,2) DEFAULT NULL,
  `balance_after` decimal(10,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_movement_details_ret`
--

CREATE TABLE `inventory_movement_details_ret` (
  `id` int(11) NOT NULL,
  `movement_id` int(11) NOT NULL,
  `variant_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,4) DEFAULT NULL,
  `cost_price` decimal(10,4) NOT NULL DEFAULT 0.0000 COMMENT 'سعر التكلفة بعملة التقارير الموحّدة للشركة',
  `total_value` decimal(12,2) DEFAULT NULL,
  `balance_before` decimal(10,2) DEFAULT NULL,
  `balance_after` decimal(10,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoice_account_settings_alpfac`
--

CREATE TABLE `invoice_account_settings_alpfac` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `account_id` int(11) NOT NULL,
  `account_code` varchar(50) DEFAULT NULL,
  `account_name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoice_account_settings_ret`
--

CREATE TABLE `invoice_account_settings_ret` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `account_id` int(11) NOT NULL,
  `account_code` varchar(50) DEFAULT NULL,
  `account_name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journal_entries_alpfac`
--

CREATE TABLE `journal_entries_alpfac` (
  `id` int(11) NOT NULL,
  `entry_number` varchar(50) NOT NULL,
  `entry_date` date NOT NULL,
  `description` text DEFAULT NULL,
  `currency_id` int(11) NOT NULL,
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `total_debit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_credit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `posted_by` int(11) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journal_entries_ret`
--

CREATE TABLE `journal_entries_ret` (
  `id` int(11) NOT NULL,
  `entry_number` varchar(50) NOT NULL,
  `entry_date` date NOT NULL,
  `description` text DEFAULT NULL,
  `currency_id` int(11) NOT NULL,
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `total_debit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_credit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `posted_by` int(11) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journal_entry_items_alpfac`
--

CREATE TABLE `journal_entry_items_alpfac` (
  `id` int(11) NOT NULL,
  `journal_entry_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `debit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `original_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `base_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `currency_id` int(11) NOT NULL,
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journal_entry_items_ret`
--

CREATE TABLE `journal_entry_items_ret` (
  `id` int(11) NOT NULL,
  `journal_entry_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `debit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `original_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `base_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `currency_id` int(11) NOT NULL,
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `manufacturing_bom_alpfac`
--

CREATE TABLE `manufacturing_bom_alpfac` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `component_type` enum('raw_material','consumable','service') NOT NULL DEFAULT 'raw_material',
  `component_id` int(11) NOT NULL,
  `quantity_required` decimal(10,4) NOT NULL,
  `unit_cost` decimal(10,4) NOT NULL,
  `is_variable` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migration_alp_to_ret_log`
--

CREATE TABLE `migration_alp_to_ret_log` (
  `id` int(11) NOT NULL,
  `old_table` varchar(100) DEFAULT NULL,
  `new_table` varchar(100) DEFAULT NULL,
  `rows_before` bigint(20) DEFAULT NULL,
  `rows_after` bigint(20) DEFAULT NULL,
  `status` varchar(20) DEFAULT NULL,
  `message` varchar(255) DEFAULT NULL,
  `checked_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `modules`
--

CREATE TABLE `modules` (
  `id` int(11) NOT NULL,
  `key` varchar(50) NOT NULL COMMENT 'مفتاح فريد: sales.invoices',
  `parent_key` varchar(50) DEFAULT NULL COMMENT 'مفتاح القسم الأب: sales',
  `label` varchar(100) NOT NULL COMMENT 'الاسم للعرض',
  `icon` varchar(60) DEFAULT 'bi-circle',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `theme_color` varchar(7) DEFAULT '#3b82f6' COMMENT 'لون القسم (hex) — يُستخدم فقط على الصفوف الأب (parent_key IS NULL). يُدار من صفحة الإعدادات.'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أقسام النظام وتدرجها';

-- --------------------------------------------------------

--
-- Table structure for table `notifications_alpfac`
--

CREATE TABLE `notifications_alpfac` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `icon` varchar(60) NOT NULL DEFAULT 'bi-bell',
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications_ret`
--

CREATE TABLE `notifications_ret` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `icon` varchar(60) NOT NULL DEFAULT 'bi-bell',
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pricing_patterns_alpfac`
--

CREATE TABLE `pricing_patterns_alpfac` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pricing_patterns_ret`
--

CREATE TABLE `pricing_patterns_ret` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `production_entries_alpfac`
--

CREATE TABLE `production_entries_alpfac` (
  `id` int(11) NOT NULL,
  `operation_id` int(11) NOT NULL,
  `entry_date` date NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `price_per_unit` decimal(10,4) NOT NULL,
  `total_base` decimal(12,4) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `worker_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `production_operations_alpfac`
--

CREATE TABLE `production_operations_alpfac` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL COMMENT 'خياطة، تطريز، كحت',
  `unit` varchar(30) NOT NULL DEFAULT 'piece',
  `default_price_base` decimal(10,4) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products_alpfac`
--

CREATE TABLE `products_alpfac` (
  `id` int(11) NOT NULL,
  `model_number` varchar(50) NOT NULL COMMENT 'رقم الموديل — الكود الرئيسي',
  `name` varchar(255) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `fabric_type` varchar(100) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الموديلات الأب — بيانات مشتركة';

-- --------------------------------------------------------

--
-- Table structure for table `products_ret`
--

CREATE TABLE `products_ret` (
  `id` int(11) NOT NULL,
  `model_number` varchar(50) NOT NULL COMMENT 'رقم الموديل — الكود الرئيسي',
  `name` varchar(255) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `fabric_type` varchar(100) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الموديلات الأب — بيانات مشتركة';

-- --------------------------------------------------------

--
-- Table structure for table `product_categories_alpfac`
--

CREATE TABLE `product_categories_alpfac` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_categories_ret`
--

CREATE TABLE `product_categories_ret` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_colors_alpfac`
--

CREATE TABLE `product_colors_alpfac` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `hex_code` varchar(10) NOT NULL DEFAULT '#000000',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_colors_ret`
--

CREATE TABLE `product_colors_ret` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `hex_code` varchar(10) NOT NULL DEFAULT '#000000',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_sizes_alpfac`
--

CREATE TABLE `product_sizes_alpfac` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `size` varchar(20) NOT NULL COMMENT 'القياس: 6,8,10,S,M,XL...',
  `age_type` varchar(10) NOT NULL DEFAULT 'سنة',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `selling_price` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `cost_price` decimal(14,4) DEFAULT NULL,
  `base_currency_id` int(11) DEFAULT NULL COMMENT 'عملة الفرع الأساسية وقت تسجيل السعر',
  `currency_id` int(11) DEFAULT NULL COMMENT 'العملة المختارة عند إدخال السعر لأول مرة',
  `exchange_rate` decimal(15,6) NOT NULL DEFAULT 1.000000 COMMENT 'سعر الصرف بين عملة الفرع والعملة المختارة وقت التسجيل',
  `margin_pct` decimal(5,2) DEFAULT NULL,
  `packet_qty` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `group_key` varchar(50) DEFAULT NULL COMMENT 'مفتاح الكروب الحقيقي — نفس القيمة لكل مقاسات نفس الكروب. مصدر الحقيقة الوحيد لتجميع الكروبات بأي مكان بالنظام (فاتورة بيع/شراء، طباعة باركود، استيراد).'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قياسات كل موديل — سعر مستقل لكل قياس';

-- --------------------------------------------------------

--
-- Table structure for table `product_sizes_ret`
--

CREATE TABLE `product_sizes_ret` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `size` varchar(20) NOT NULL COMMENT 'القياس: 6,8,10,S,M,XL...',
  `age_type` varchar(10) NOT NULL DEFAULT 'سنة',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `selling_price` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `cost_price` decimal(14,4) DEFAULT NULL,
  `base_currency_id` int(11) DEFAULT NULL COMMENT 'عملة الفرع الأساسية وقت تسجيل السعر',
  `currency_id` int(11) DEFAULT NULL COMMENT 'العملة المختارة عند إدخال السعر لأول مرة',
  `exchange_rate` decimal(15,6) NOT NULL DEFAULT 1.000000 COMMENT 'سعر الصرف بين عملة الفرع والعملة المختارة وقت التسجيل',
  `margin_pct` decimal(5,2) DEFAULT NULL,
  `packet_qty` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `group_key` varchar(50) DEFAULT NULL COMMENT 'مفتاح الكروب الحقيقي — نفس القيمة لكل مقاسات نفس الكروب. مصدر الحقيقة الوحيد لتجميع الكروبات بأي مكان بالنظام (فاتورة بيع/شراء، طباعة باركود، استيراد).'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قياسات كل موديل — سعر مستقل لكل قياس';

-- --------------------------------------------------------

--
-- Table structure for table `product_suppliers_alpfac`
--

CREATE TABLE `product_suppliers_alpfac` (
  `id` int(11) NOT NULL,
  `account_id` int(11) DEFAULT NULL COMMENT 'حساب ذمة المورد',
  `prepaid_account_id` int(11) DEFAULT NULL COMMENT 'حساب الدفعات المقدمة للمورد',
  `name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `tax_number` varchar(50) DEFAULT NULL,
  `type` enum('manufacturer','distributor','wholesaler','retailer') DEFAULT 'wholesaler',
  `supplier_type` enum('product','consumable','both') NOT NULL DEFAULT 'product',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `credit_limit` decimal(12,2) DEFAULT 0.00,
  `discount_percentage` decimal(5,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_suppliers_ret`
--

CREATE TABLE `product_suppliers_ret` (
  `id` int(11) NOT NULL,
  `account_id` int(11) DEFAULT NULL COMMENT 'حساب ذمة المورد',
  `prepaid_account_id` int(11) DEFAULT NULL COMMENT 'حساب الدفعات المقدمة للمورد',
  `name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `tax_number` varchar(50) DEFAULT NULL,
  `type` enum('manufacturer','distributor','wholesaler','retailer') DEFAULT 'wholesaler',
  `supplier_type` enum('product','consumable','both') NOT NULL DEFAULT 'product',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `credit_limit` decimal(12,2) DEFAULT 0.00,
  `discount_percentage` decimal(5,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_variants_alpfac`
--

CREATE TABLE `product_variants_alpfac` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `size_id` int(11) NOT NULL,
  `color_id` int(11) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL COMMENT 'باركود فريد لكل قياس+لون',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='كل قياس+لون = سطر — السعر مورث من product_sizes_alp';

-- --------------------------------------------------------

--
-- Table structure for table `product_variants_ret`
--

CREATE TABLE `product_variants_ret` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `size_id` int(11) NOT NULL,
  `color_id` int(11) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL COMMENT 'باركود فريد لكل قياس+لون',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='كل قياس+لون = سطر — السعر مورث من product_sizes_alp';

-- --------------------------------------------------------

--
-- Table structure for table `public_holidays_alpfac`
--

CREATE TABLE `public_holidays_alpfac` (
  `id` int(11) NOT NULL,
  `holiday_date` date NOT NULL,
  `name` varchar(150) NOT NULL COMMENT 'عيد الفطر، عيد الميلاد...',
  `description` text DEFAULT NULL,
  `is_recurring` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = تتكرر كل سنة (نفس الشهر واليوم)',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='العطل الرسمية';

-- --------------------------------------------------------

--
-- Table structure for table `public_holidays_ret`
--

CREATE TABLE `public_holidays_ret` (
  `id` int(11) NOT NULL,
  `holiday_date` date NOT NULL,
  `name` varchar(150) NOT NULL COMMENT 'عيد الفطر، عيد الميلاد...',
  `description` text DEFAULT NULL,
  `is_recurring` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = تتكرر كل سنة (نفس الشهر واليوم)',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='العطل الرسمية';

-- --------------------------------------------------------

--
-- Table structure for table `purchases_alpfac`
--

CREATE TABLE `purchases_alpfac` (
  `id` int(11) NOT NULL,
  `purchase_number` varchar(50) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `purchase_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `settlement_discount_pct` decimal(5,2) DEFAULT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'بعملة الفاتورة',
  `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `final_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `final_amount_base_currency` decimal(15,4) DEFAULT NULL COMMENT 'الإجمالي بعملة الفرع',
  `paid_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `balance_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `invoice_currency_id` int(11) DEFAULT NULL,
  `base_currency_id` int(11) DEFAULT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `payment_status` enum('pending','partial','paid') NOT NULL DEFAULT 'pending',
  `payment_method` enum('cash','bank_transfer','check','credit_card') DEFAULT 'cash',
  `journal_entry_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('draft','confirmed','cancelled') NOT NULL DEFAULT 'draft',
  `user_id` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchases_ret`
--

CREATE TABLE `purchases_ret` (
  `id` int(11) NOT NULL,
  `purchase_number` varchar(50) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `purchase_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `settlement_discount_pct` decimal(5,2) DEFAULT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'بعملة الفاتورة',
  `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `final_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `final_amount_base_currency` decimal(15,4) DEFAULT NULL COMMENT 'الإجمالي بعملة الفرع',
  `paid_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `balance_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `invoice_currency_id` int(11) DEFAULT NULL,
  `base_currency_id` int(11) DEFAULT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `payment_status` enum('pending','partial','paid') NOT NULL DEFAULT 'pending',
  `payment_method` enum('cash','bank_transfer','check','credit_card') DEFAULT 'cash',
  `journal_entry_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('draft','confirmed','cancelled') NOT NULL DEFAULT 'draft',
  `user_id` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_items_alpfac`
--

CREATE TABLE `purchase_items_alpfac` (
  `id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,4) NOT NULL COMMENT 'بعملة فاتورة الشراء',
  `unit_price_base_currency` decimal(10,4) DEFAULT NULL COMMENT 'بعملة الفرع الأساسية= unit_price / exchange_rate',
  `total_price` decimal(12,2) NOT NULL,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `discount_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_items_ret`
--

CREATE TABLE `purchase_items_ret` (
  `id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,4) NOT NULL COMMENT 'بعملة فاتورة الشراء',
  `unit_price_base_currency` decimal(10,4) DEFAULT NULL COMMENT 'بعملة الفرع الأساسية= unit_price / exchange_rate',
  `total_price` decimal(12,2) NOT NULL,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `discount_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_payments_alpfac`
--

CREATE TABLE `purchase_payments_alpfac` (
  `id` int(11) NOT NULL,
  `payment_number` varchar(30) NOT NULL,
  `payment_date` date NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `supplier_name` varchar(150) DEFAULT NULL,
  `amount` decimal(18,4) NOT NULL,
  `currency` varchar(10) NOT NULL,
  `exchange_rate` decimal(18,6) NOT NULL DEFAULT 1.000000,
  `amount_base` decimal(18,4) NOT NULL,
  `payment_method` varchar(20) NOT NULL DEFAULT 'cash',
  `cash_account_id` int(11) DEFAULT NULL,
  `debit_account_id` int(11) DEFAULT NULL COMMENT 'للدفعة العامة بدون مورد — الحساب المدين المختار يدوياً',
  `notes` varchar(500) DEFAULT NULL,
  `status` enum('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `journal_entry_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_payments_ret`
--

CREATE TABLE `purchase_payments_ret` (
  `id` int(11) NOT NULL,
  `payment_number` varchar(30) NOT NULL,
  `payment_date` date NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `supplier_name` varchar(150) DEFAULT NULL,
  `amount` decimal(18,4) NOT NULL,
  `currency` varchar(10) NOT NULL,
  `exchange_rate` decimal(18,6) NOT NULL DEFAULT 1.000000,
  `amount_base` decimal(18,4) NOT NULL,
  `payment_method` varchar(20) NOT NULL DEFAULT 'cash',
  `cash_account_id` int(11) DEFAULT NULL,
  `debit_account_id` int(11) DEFAULT NULL COMMENT 'للدفعة العامة بدون مورد — الحساب المدين المختار يدوياً',
  `notes` varchar(500) DEFAULT NULL,
  `status` enum('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `journal_entry_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_payment_invoices_alpfac`
--

CREATE TABLE `purchase_payment_invoices_alpfac` (
  `id` int(11) NOT NULL,
  `payment_id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `allocated_amount` decimal(18,4) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_payment_invoices_ret`
--

CREATE TABLE `purchase_payment_invoices_ret` (
  `id` int(11) NOT NULL,
  `payment_id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `allocated_amount` decimal(18,4) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_returns_alpfac`
--

CREATE TABLE `purchase_returns_alpfac` (
  `id` int(11) NOT NULL,
  `return_number` varchar(50) DEFAULT NULL,
  `purchase_id` int(11) NOT NULL,
  `purchase_number` varchar(50) DEFAULT NULL,
  `supplier_id` int(11) NOT NULL,
  `supplier_name` varchar(255) DEFAULT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `return_date` date NOT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `return_amount` decimal(15,2) DEFAULT NULL,
  `discount_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `return_currency_id` int(11) DEFAULT NULL,
  `base_currency_id` int(11) DEFAULT NULL,
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `payment_handling` enum('not_paid','partial','paid_full') NOT NULL DEFAULT 'not_paid',
  `refund_account_id` int(11) DEFAULT NULL,
  `target_account_type` enum('cash','supplier','advance') DEFAULT NULL,
  `journal_entry_id` int(11) DEFAULT NULL,
  `status` enum('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `return_reason` varchar(255) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_returns_ret`
--

CREATE TABLE `purchase_returns_ret` (
  `id` int(11) NOT NULL,
  `return_number` varchar(50) DEFAULT NULL,
  `purchase_id` int(11) NOT NULL,
  `purchase_number` varchar(50) DEFAULT NULL,
  `supplier_id` int(11) NOT NULL,
  `supplier_name` varchar(255) DEFAULT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `return_date` date NOT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `return_amount` decimal(15,2) DEFAULT NULL,
  `discount_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `return_currency_id` int(11) DEFAULT NULL,
  `base_currency_id` int(11) DEFAULT NULL,
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `payment_handling` enum('not_paid','partial','paid_full') NOT NULL DEFAULT 'not_paid',
  `refund_account_id` int(11) DEFAULT NULL,
  `target_account_type` enum('cash','supplier','advance') DEFAULT NULL,
  `journal_entry_id` int(11) DEFAULT NULL,
  `status` enum('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `return_reason` varchar(255) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_return_items_alpfac`
--

CREATE TABLE `purchase_return_items_alpfac` (
  `id` int(11) NOT NULL,
  `return_id` int(11) NOT NULL,
  `purchase_item_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `quantity_returned` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,4) NOT NULL,
  `total_price` decimal(12,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_return_items_ret`
--

CREATE TABLE `purchase_return_items_ret` (
  `id` int(11) NOT NULL,
  `return_id` int(11) NOT NULL,
  `purchase_item_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `quantity_returned` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,4) NOT NULL,
  `total_price` decimal(12,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `raw_materials_alpfac`
--

CREATE TABLE `raw_materials_alpfac` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `unit` varchar(30) NOT NULL DEFAULT 'kg',
  `category` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `raw_material_stock_alpfac`
--

CREATE TABLE `raw_material_stock_alpfac` (
  `id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL DEFAULT 1,
  `quantity` decimal(12,3) NOT NULL DEFAULT 0.000,
  `avg_cost_base` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `last_movement_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `receipts_alpfac`
--

CREATE TABLE `receipts_alpfac` (
  `id` int(11) NOT NULL,
  `receipt_number` varchar(50) NOT NULL,
  `receipt_date` date NOT NULL,
  `customer_id` int(11) NOT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `amount` decimal(15,4) NOT NULL COMMENT 'المبلغ بعملة القبض',
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `amount_base` decimal(15,4) NOT NULL COMMENT 'المبلغ بعملة التقارير الموحّدة للشركة',
  `payment_method` enum('cash','bank','card','check') NOT NULL DEFAULT 'cash',
  `cash_account_id` int(11) DEFAULT NULL,
  `journal_entry_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `created_by` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سندات القبض — مستقلة عن الفواتير';

-- --------------------------------------------------------

--
-- Table structure for table `receipts_ret`
--

CREATE TABLE `receipts_ret` (
  `id` int(11) NOT NULL,
  `receipt_number` varchar(50) NOT NULL,
  `receipt_date` date NOT NULL,
  `customer_id` int(11) NOT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `amount` decimal(15,4) NOT NULL COMMENT 'المبلغ بعملة القبض',
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `amount_base` decimal(15,4) NOT NULL COMMENT 'المبلغ بعملة التقارير الموحّدة للشركة',
  `payment_method` enum('cash','bank','card','check') NOT NULL DEFAULT 'cash',
  `cash_account_id` int(11) DEFAULT NULL,
  `journal_entry_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `created_by` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سندات القبض — مستقلة عن الفواتير';

-- --------------------------------------------------------

--
-- Table structure for table `receipt_invoices_alpfac`
--

CREATE TABLE `receipt_invoices_alpfac` (
  `id` int(11) NOT NULL,
  `receipt_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `allocated_amount` decimal(15,4) NOT NULL COMMENT 'المبلغ المُوزَّع بعملة التقارير الموحّدة للشركة',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='توزيع سند القبض على فواتير متعددة';

-- --------------------------------------------------------

--
-- Table structure for table `receipt_invoices_ret`
--

CREATE TABLE `receipt_invoices_ret` (
  `id` int(11) NOT NULL,
  `receipt_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `allocated_amount` decimal(15,4) NOT NULL COMMENT 'المبلغ المُوزَّع بعملة التقارير الموحّدة للشركة',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='توزيع سند القبض على فواتير متعددة';

-- --------------------------------------------------------

--
-- Table structure for table `sales_invoices_alpfac`
--

CREATE TABLE `sales_invoices_alpfac` (
  `id` int(11) NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `settlement_discount_pct` decimal(5,2) DEFAULT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `final_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `final_amount_base_currency` decimal(15,4) DEFAULT NULL,
  `paid_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `balance_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `invoice_currency_id` int(11) DEFAULT NULL,
  `base_currency_id` int(11) DEFAULT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `payment_status` enum('pending','partial','paid') NOT NULL DEFAULT 'pending',
  `payment_method` enum('cash','bank_transfer','check','credit_card') DEFAULT 'cash',
  `journal_entry_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('draft','confirmed','received','cancelled') NOT NULL DEFAULT 'draft',
  `user_id` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_invoices_ret`
--

CREATE TABLE `sales_invoices_ret` (
  `id` int(11) NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `settlement_discount_pct` decimal(5,2) DEFAULT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(15,5) NOT NULL DEFAULT 0.00000,
  `final_amount` decimal(15,5) NOT NULL DEFAULT 0.00000,
  `final_amount_base_currency` decimal(15,4) DEFAULT NULL,
  `paid_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `balance_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `invoice_currency_id` int(11) DEFAULT NULL,
  `base_currency_id` int(11) DEFAULT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `payment_status` enum('pending','partial','paid') NOT NULL DEFAULT 'pending',
  `payment_method` enum('cash','bank_transfer','check','credit_card') DEFAULT 'cash',
  `journal_entry_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('draft','confirmed','received','cancelled') NOT NULL DEFAULT 'draft',
  `user_id` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_invoice_items_alpfac`
--

CREATE TABLE `sales_invoice_items_alpfac` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,4) NOT NULL,
  `unit_price_base_currency` decimal(10,4) DEFAULT NULL,
  `total_price` decimal(12,2) NOT NULL,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `discount_percentage` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_invoice_items_ret`
--

CREATE TABLE `sales_invoice_items_ret` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,4) NOT NULL,
  `unit_price_base_currency` decimal(10,4) DEFAULT NULL,
  `total_price` decimal(12,2) NOT NULL,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `discount_percentage` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_returns_alpfac`
--

CREATE TABLE `sales_returns_alpfac` (
  `id` int(11) NOT NULL,
  `return_number` varchar(50) DEFAULT NULL,
  `invoice_id` int(11) NOT NULL,
  `invoice_number` varchar(50) DEFAULT NULL,
  `customer_id` int(11) NOT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `return_date` date NOT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `return_amount` decimal(15,2) DEFAULT NULL,
  `discount_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `return_currency_id` int(11) DEFAULT NULL,
  `base_currency_id` int(11) DEFAULT NULL,
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `payment_handling` enum('not_paid','partial','paid_full') NOT NULL DEFAULT 'not_paid',
  `target_account_type` enum('cash','receivable','prepaid') DEFAULT NULL,
  `refund_account_id` int(11) DEFAULT NULL,
  `journal_entry_id` int(11) DEFAULT NULL,
  `status` enum('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `return_reason` varchar(255) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_returns_ret`
--

CREATE TABLE `sales_returns_ret` (
  `id` int(11) NOT NULL,
  `return_number` varchar(50) DEFAULT NULL,
  `invoice_id` int(11) NOT NULL,
  `invoice_number` varchar(50) DEFAULT NULL,
  `customer_id` int(11) NOT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `return_date` date NOT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `return_amount` decimal(15,2) DEFAULT NULL,
  `discount_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `return_currency_id` int(11) DEFAULT NULL,
  `base_currency_id` int(11) DEFAULT NULL,
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `payment_handling` enum('not_paid','partial','paid_full') NOT NULL DEFAULT 'not_paid',
  `target_account_type` enum('cash','receivable','prepaid') DEFAULT NULL,
  `refund_account_id` int(11) DEFAULT NULL,
  `journal_entry_id` int(11) DEFAULT NULL,
  `status` enum('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `return_reason` varchar(255) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_return_items_alpfac`
--

CREATE TABLE `sales_return_items_alpfac` (
  `id` int(11) NOT NULL,
  `return_id` int(11) NOT NULL,
  `invoice_item_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `quantity_returned` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,4) NOT NULL,
  `total_price` decimal(12,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_return_items_ret`
--

CREATE TABLE `sales_return_items_ret` (
  `id` int(11) NOT NULL,
  `return_id` int(11) NOT NULL,
  `invoice_item_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `quantity_returned` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,4) NOT NULL,
  `total_price` decimal(12,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shipping_carriers`
--

CREATE TABLE `shipping_carriers` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `mobile` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `tax_number` varchar(50) DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  `payable_account_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT '0000-00-00 00:00:00' ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tax_types_alpfac`
--

CREATE TABLE `tax_types_alpfac` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `tax_scope` enum('sales','purchase','withholding') NOT NULL,
  `calc_type` enum('percentage','fixed') NOT NULL DEFAULT 'percentage',
  `rate_value` decimal(10,4) NOT NULL DEFAULT 0.0000 COMMENT 'نسبة مئوية (مثلاً 16.0000) أو مبلغ ثابت حسب calc_type',
  `account_id` int(11) DEFAULT NULL COMMENT 'الحساب المحاسبي المرتبط — عادة من إعدادات الربط، بس يُسمح بتخصيص حساب مختلف لكل نوع ضريبة',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tax_types_ret`
--

CREATE TABLE `tax_types_ret` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `tax_scope` enum('sales','purchase','withholding') NOT NULL,
  `calc_type` enum('percentage','fixed') NOT NULL DEFAULT 'percentage',
  `rate_value` decimal(10,4) NOT NULL DEFAULT 0.0000 COMMENT 'نسبة مئوية (مثلاً 16.0000) أو مبلغ ثابت حسب calc_type',
  `account_id` int(11) DEFAULT NULL COMMENT 'الحساب المحاسبي المرتبط — عادة من إعدادات الربط، بس يُسمح بتخصيص حساب مختلف لكل نوع ضريبة',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `full_name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL COMMENT 'bcrypt hash',
  `role` enum('admin','accountant','sales','purchases','warehouse','user') NOT NULL DEFAULT 'user',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='المستخدمون';

-- --------------------------------------------------------

--
-- Table structure for table `user_activities`
--

CREATE TABLE `user_activities` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `activity_type` varchar(50) NOT NULL COMMENT 'login|logout|create|update|delete',
  `description` text NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل نشاط المستخدمين';

-- --------------------------------------------------------

--
-- Table structure for table `user_branches`
--

CREATE TABLE `user_branches` (
  `user_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='صلاحيات المستخدم على الفروع';

-- --------------------------------------------------------

--
-- Table structure for table `user_permissions`
--

CREATE TABLE `user_permissions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `module_key` varchar(50) NOT NULL COMMENT 'مفتاح القسم مثل: sales.invoices',
  `can_view` tinyint(1) NOT NULL DEFAULT 0,
  `can_create` tinyint(1) NOT NULL DEFAULT 0,
  `can_edit` tinyint(1) NOT NULL DEFAULT 0,
  `can_delete` tinyint(1) NOT NULL DEFAULT 0,
  `can_confirm` tinyint(1) NOT NULL DEFAULT 0,
  `can_print` tinyint(1) NOT NULL DEFAULT 0,
  `can_export` tinyint(1) NOT NULL DEFAULT 0,
  `granted_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='صلاحيات كل مستخدم في كل فرع على كل قسم';

-- --------------------------------------------------------

--
-- Table structure for table `user_tab_order`
--

CREATE TABLE `user_tab_order` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `page_group` varchar(50) NOT NULL COMMENT 'مثلاً: finance — لو انضافت مجموعات تبويبات تانية بالمستقبل (مبيعات/مشتريات) بتستخدم نفس الجدول بقيمة مختلفة',
  `tab_order` text NOT NULL COMMENT 'JSON array بترتيب أسماء الملفات، مثلاً ["accounts.php","journal.php",...]',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `warehouses_alpfac`
--

CREATE TABLE `warehouses_alpfac` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `warehouse_type` enum('products','consumables','raw_materials') NOT NULL DEFAULT 'products',
  `address` text DEFAULT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `warehouses_ret`
--

CREATE TABLE `warehouses_ret` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `warehouse_type` enum('products','consumables','raw_materials') NOT NULL DEFAULT 'products',
  `address` text DEFAULT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `warehouse_items_alpfac`
--

CREATE TABLE `warehouse_items_alpfac` (
  `id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL,
  `variant_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL COMMENT 'الموديل الأب — للفلترة السريعة',
  `quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `current_cost` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `min_quantity` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'حد التنبيه',
  `last_movement_at` datetime DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مخزون كل Variant في كل مستودع';

-- --------------------------------------------------------

--
-- Table structure for table `warehouse_items_ret`
--

CREATE TABLE `warehouse_items_ret` (
  `id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL,
  `variant_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL COMMENT 'الموديل الأب — للفلترة السريعة',
  `quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `current_cost` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `min_quantity` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'حد التنبيه',
  `last_movement_at` datetime DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مخزون كل Variant في كل مستودع';

--
-- Indexes for dumped tables
--

--
-- Indexes for table `account_charts_alpfac`
--
ALTER TABLE `account_charts_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_parent_id` (`parent_id`),
  ADD KEY `idx_account_type` (`account_type`),
  ADD KEY `idx_code` (`code`);

--
-- Indexes for table `account_charts_ret`
--
ALTER TABLE `account_charts_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_parent_id` (`parent_id`),
  ADD KEY `idx_account_type` (`account_type`),
  ADD KEY `idx_code` (`code`);

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_table_suffix` (`table_suffix`),
  ADD KEY `idx_factory_branch` (`factory_branch_id`),
  ADD KEY `idx_branch_type` (`branch_type`),
  ADD KEY `fk_branches_base_currency` (`base_currency_id`),
  ADD KEY `fk_branches_local_currency` (`local_currency_id`);

--
-- Indexes for table `consumable_categories_alpfac`
--
ALTER TABLE `consumable_categories_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_name` (`name`);

--
-- Indexes for table `consumable_categories_ret`
--
ALTER TABLE `consumable_categories_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_name` (`name`);

--
-- Indexes for table `consumable_departments_alpfac`
--
ALTER TABLE `consumable_departments_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_name` (`name`);

--
-- Indexes for table `consumable_departments_ret`
--
ALTER TABLE `consumable_departments_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_name` (`name`);

--
-- Indexes for table `consumable_issues_alpfac`
--
ALTER TABLE `consumable_issues_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_issue_no` (`issue_no`),
  ADD KEY `idx_warehouse_id` (`warehouse_id`),
  ADD KEY `idx_date` (`issue_date`),
  ADD KEY `fk_ci_department_ret` (`department_id`);

--
-- Indexes for table `consumable_issues_ret`
--
ALTER TABLE `consumable_issues_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_issue_no` (`issue_no`),
  ADD KEY `idx_warehouse_id` (`warehouse_id`),
  ADD KEY `idx_date` (`issue_date`),
  ADD KEY `fk_ci_department_ret` (`department_id`);

--
-- Indexes for table `consumable_issue_items_alpfac`
--
ALTER TABLE `consumable_issue_items_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_issue_id` (`issue_id`),
  ADD KEY `idx_item_id` (`item_id`),
  ADD KEY `fk_cii_movement` (`movement_id`),
  ADD KEY `fk_cii_packaging_ret` (`packaging_id`);

--
-- Indexes for table `consumable_issue_items_ret`
--
ALTER TABLE `consumable_issue_items_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_issue_id` (`issue_id`),
  ADD KEY `idx_item_id` (`item_id`),
  ADD KEY `fk_cii_movement` (`movement_id`),
  ADD KEY `fk_cii_packaging_ret` (`packaging_id`);

--
-- Indexes for table `consumable_items_alpfac`
--
ALTER TABLE `consumable_items_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ci_currency` (`currency_id`),
  ADD KEY `fk_consumable_items_category_ret` (`category_id`),
  ADD KEY `fk_consumable_items_unit_ret` (`unit_id`);

--
-- Indexes for table `consumable_items_ret`
--
ALTER TABLE `consumable_items_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ci_currency` (`currency_id`),
  ADD KEY `fk_consumable_items_category_ret` (`category_id`),
  ADD KEY `fk_consumable_items_unit_ret` (`unit_id`);

--
-- Indexes for table `consumable_item_packagings_alpfac`
--
ALTER TABLE `consumable_item_packagings_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_item_pkg` (`item_id`,`name`);

--
-- Indexes for table `consumable_item_packagings_ret`
--
ALTER TABLE `consumable_item_packagings_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_item_pkg` (`item_id`,`name`);

--
-- Indexes for table `consumable_movements_alpfac`
--
ALTER TABLE `consumable_movements_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_movement_no` (`movement_no`),
  ADD KEY `idx_item_id` (`item_id`),
  ADD KEY `idx_warehouse_id` (`warehouse_id`),
  ADD KEY `idx_type` (`movement_type`),
  ADD KEY `idx_date` (`movement_date`),
  ADD KEY `idx_reference` (`reference_type`,`reference_id`),
  ADD KEY `fk_cm_to_warehouse` (`to_warehouse_id`);

--
-- Indexes for table `consumable_movements_ret`
--
ALTER TABLE `consumable_movements_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_movement_no` (`movement_no`),
  ADD KEY `idx_item_id` (`item_id`),
  ADD KEY `idx_warehouse_id` (`warehouse_id`),
  ADD KEY `idx_type` (`movement_type`),
  ADD KEY `idx_date` (`movement_date`),
  ADD KEY `idx_reference` (`reference_type`,`reference_id`),
  ADD KEY `fk_cm_to_warehouse` (`to_warehouse_id`);

--
-- Indexes for table `consumable_purchases_alpfac`
--
ALTER TABLE `consumable_purchases_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_invoice_no` (`invoice_no`),
  ADD KEY `idx_supplier_id` (`supplier_id`),
  ADD KEY `idx_warehouse_id` (`warehouse_id`),
  ADD KEY `idx_date` (`invoice_date`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `consumable_purchases_ret`
--
ALTER TABLE `consumable_purchases_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_invoice_no` (`invoice_no`),
  ADD KEY `idx_supplier_id` (`supplier_id`),
  ADD KEY `idx_warehouse_id` (`warehouse_id`),
  ADD KEY `idx_date` (`invoice_date`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `consumable_purchase_items_alpfac`
--
ALTER TABLE `consumable_purchase_items_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_purchase_id` (`purchase_id`),
  ADD KEY `idx_item_id` (`item_id`),
  ADD KEY `idx_movement_id` (`movement_id`),
  ADD KEY `fk_cpi_packaging_ret` (`packaging_id`);

--
-- Indexes for table `consumable_purchase_items_ret`
--
ALTER TABLE `consumable_purchase_items_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_purchase_id` (`purchase_id`),
  ADD KEY `idx_item_id` (`item_id`),
  ADD KEY `idx_movement_id` (`movement_id`),
  ADD KEY `fk_cpi_packaging_ret` (`packaging_id`);

--
-- Indexes for table `consumable_returns_alpfac`
--
ALTER TABLE `consumable_returns_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_cr_issue_ret` (`issue_id`);

--
-- Indexes for table `consumable_returns_ret`
--
ALTER TABLE `consumable_returns_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_cr_issue_ret` (`issue_id`);

--
-- Indexes for table `consumable_return_items_alpfac`
--
ALTER TABLE `consumable_return_items_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_cri_return_ret` (`return_id`),
  ADD KEY `fk_cri_issue_item_ret` (`issue_item_id`);

--
-- Indexes for table `consumable_return_items_ret`
--
ALTER TABLE `consumable_return_items_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_cri_return_ret` (`return_id`),
  ADD KEY `fk_cri_issue_item_ret` (`issue_item_id`);

--
-- Indexes for table `consumable_stock_alpfac`
--
ALTER TABLE `consumable_stock_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_item_warehouse` (`item_id`,`warehouse_id`),
  ADD KEY `idx_item_id` (`item_id`),
  ADD KEY `idx_warehouse_id` (`warehouse_id`);

--
-- Indexes for table `consumable_stock_ret`
--
ALTER TABLE `consumable_stock_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_item_warehouse` (`item_id`,`warehouse_id`),
  ADD KEY `idx_item_id` (`item_id`),
  ADD KEY `idx_warehouse_id` (`warehouse_id`);

--
-- Indexes for table `consumable_transfers_alpfac`
--
ALTER TABLE `consumable_transfers_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ctr_from_wh` (`from_warehouse_id`),
  ADD KEY `fk_ctr_to_wh` (`to_warehouse_id`);

--
-- Indexes for table `consumable_transfers_ret`
--
ALTER TABLE `consumable_transfers_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ctr_from_wh` (`from_warehouse_id`),
  ADD KEY `fk_ctr_to_wh` (`to_warehouse_id`);

--
-- Indexes for table `consumable_transfer_items_alpfac`
--
ALTER TABLE `consumable_transfer_items_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ctri_transfer` (`transfer_id`);

--
-- Indexes for table `consumable_transfer_items_ret`
--
ALTER TABLE `consumable_transfer_items_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ctri_transfer` (`transfer_id`);

--
-- Indexes for table `consumable_units_alpfac`
--
ALTER TABLE `consumable_units_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_name` (`name`);

--
-- Indexes for table `consumable_units_ret`
--
ALTER TABLE `consumable_units_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_name` (`name`);

--
-- Indexes for table `currencies`
--
ALTER TABLE `currencies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `currency_branch_links`
--
ALTER TABLE `currency_branch_links`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_currency_branch` (`currency_id`,`branch_id`),
  ADD KEY `idx_branch` (`branch_id`),
  ADD KEY `idx_currency` (`currency_id`);

--
-- Indexes for table `customers_alpfac`
--
ALTER TABLE `customers_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_account_id` (`account_id`),
  ADD KEY `fk_customers_pricing_pattern_alpfac` (`pricing_pattern_id`);

--
-- Indexes for table `customers_ret`
--
ALTER TABLE `customers_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_account_id` (`account_id`),
  ADD KEY `fk_customers_pricing_pattern` (`pricing_pattern_id`);

--
-- Indexes for table `exchange_rates_alpfac`
--
ALTER TABLE `exchange_rates_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_currency_date` (`currency_from`,`currency_to`,`rate_date`),
  ADD KEY `idx_rate_date` (`rate_date`);

--
-- Indexes for table `exchange_rates_ret`
--
ALTER TABLE `exchange_rates_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_currency_date` (`currency_from`,`currency_to`,`rate_date`),
  ADD KEY `idx_rate_date` (`rate_date`);

--
-- Indexes for table `expenses_alpfac`
--
ALTER TABLE `expenses_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_expense_date` (`expense_date`),
  ADD KEY `idx_expense_acct` (`expense_account_id`);

--
-- Indexes for table `expenses_ret`
--
ALTER TABLE `expenses_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_expense_date` (`expense_date`),
  ADD KEY `idx_expense_acct` (`expense_account_id`);

--
-- Indexes for table `hr_attendance_alpfac`
--
ALTER TABLE `hr_attendance_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`employee_id`,`attendance_date`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_date` (`attendance_date`);

--
-- Indexes for table `hr_attendance_ret`
--
ALTER TABLE `hr_attendance_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`employee_id`,`attendance_date`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_date` (`attendance_date`);

--
-- Indexes for table `hr_bonuses_alpfac`
--
ALTER TABLE `hr_bonuses_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_date` (`bonus_date`);

--
-- Indexes for table `hr_bonuses_ret`
--
ALTER TABLE `hr_bonuses_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_date` (`bonus_date`);

--
-- Indexes for table `hr_employees_alpfac`
--
ALTER TABLE `hr_employees_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_department` (`department`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_currency_id` (`currency_id`);

--
-- Indexes for table `hr_employees_ret`
--
ALTER TABLE `hr_employees_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_department` (`department`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_currency_id` (`currency_id`);

--
-- Indexes for table `hr_loans_alpfac`
--
ALTER TABLE `hr_loans_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `hr_loans_ret`
--
ALTER TABLE `hr_loans_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `hr_payroll_alpfac`
--
ALTER TABLE `hr_payroll_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_emp_period` (`employee_id`,`period_from`),
  ADD KEY `idx_month` (`payroll_month`),
  ADD KEY `idx_status` (`payment_status`);

--
-- Indexes for table `hr_payroll_loan_allocations_alpfac`
--
ALTER TABLE `hr_payroll_loan_allocations_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payroll` (`payroll_id`),
  ADD KEY `idx_loan` (`loan_id`);

--
-- Indexes for table `hr_payroll_loan_allocations_ret`
--
ALTER TABLE `hr_payroll_loan_allocations_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payroll` (`payroll_id`),
  ADD KEY `idx_loan` (`loan_id`);

--
-- Indexes for table `hr_payroll_ret`
--
ALTER TABLE `hr_payroll_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_emp_period` (`employee_id`,`period_from`),
  ADD KEY `idx_month` (`payroll_month`),
  ADD KEY `idx_status` (`payment_status`);

--
-- Indexes for table `hr_promotions_alpfac`
--
ALTER TABLE `hr_promotions_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_employee` (`employee_id`);

--
-- Indexes for table `hr_promotions_ret`
--
ALTER TABLE `hr_promotions_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_employee` (`employee_id`);

--
-- Indexes for table `internal_orders`
--
ALTER TABLE `internal_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_order_number` (`order_number`),
  ADD KEY `idx_from_branch` (`from_branch_id`),
  ADD KEY `idx_to_branch` (`to_branch_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `internal_order_items`
--
ALTER TABLE `internal_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order` (`order_id`);

--
-- Indexes for table `inventory_movements_alpfac`
--
ALTER TABLE `inventory_movements_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `movement_number` (`movement_number`),
  ADD KEY `idx_movement_type` (`movement_type`),
  ADD KEY `idx_reference` (`reference_type`,`reference_id`),
  ADD KEY `idx_warehouse_id` (`warehouse_id`);

--
-- Indexes for table `inventory_movements_ret`
--
ALTER TABLE `inventory_movements_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `movement_number` (`movement_number`),
  ADD KEY `idx_movement_type` (`movement_type`),
  ADD KEY `idx_reference` (`reference_type`,`reference_id`),
  ADD KEY `idx_warehouse_id` (`warehouse_id`);

--
-- Indexes for table `inventory_movement_details_alpfac`
--
ALTER TABLE `inventory_movement_details_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_movement_id` (`movement_id`),
  ADD KEY `idx_variant_id` (`variant_id`);

--
-- Indexes for table `inventory_movement_details_ret`
--
ALTER TABLE `inventory_movement_details_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_movement_id` (`movement_id`),
  ADD KEY `idx_variant_id` (`variant_id`);

--
-- Indexes for table `invoice_account_settings_alpfac`
--
ALTER TABLE `invoice_account_settings_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `invoice_account_settings_ret`
--
ALTER TABLE `invoice_account_settings_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `journal_entries_alpfac`
--
ALTER TABLE `journal_entries_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `entry_number` (`entry_number`),
  ADD KEY `idx_entry_date` (`entry_date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_reference` (`reference_type`,`reference_id`),
  ADD KEY `fk_journal_entries_ret_currency` (`currency_id`);

--
-- Indexes for table `journal_entries_ret`
--
ALTER TABLE `journal_entries_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `entry_number` (`entry_number`),
  ADD KEY `idx_entry_date` (`entry_date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_reference` (`reference_type`,`reference_id`),
  ADD KEY `fk_journal_entries_ret_currency` (`currency_id`);

--
-- Indexes for table `journal_entry_items_alpfac`
--
ALTER TABLE `journal_entry_items_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_journal_entry_id` (`journal_entry_id`),
  ADD KEY `idx_account_id` (`account_id`),
  ADD KEY `fk_ji_currency_id` (`currency_id`);

--
-- Indexes for table `journal_entry_items_ret`
--
ALTER TABLE `journal_entry_items_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_journal_entry_id` (`journal_entry_id`),
  ADD KEY `idx_account_id` (`account_id`),
  ADD KEY `fk_ji_currency_id` (`currency_id`);

--
-- Indexes for table `manufacturing_bom_alpfac`
--
ALTER TABLE `manufacturing_bom_alpfac`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `migration_alp_to_ret_log`
--
ALTER TABLE `migration_alp_to_ret_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `modules`
--
ALTER TABLE `modules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `key` (`key`),
  ADD KEY `idx_parent_key` (`parent_key`),
  ADD KEY `idx_key` (`key`);

--
-- Indexes for table `notifications_alpfac`
--
ALTER TABLE `notifications_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_is_read` (`is_read`);

--
-- Indexes for table `notifications_ret`
--
ALTER TABLE `notifications_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_is_read` (`is_read`);

--
-- Indexes for table `pricing_patterns_alpfac`
--
ALTER TABLE `pricing_patterns_alpfac`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pricing_patterns_ret`
--
ALTER TABLE `pricing_patterns_ret`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `production_entries_alpfac`
--
ALTER TABLE `production_entries_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_entry_date` (`entry_date`);

--
-- Indexes for table `production_operations_alpfac`
--
ALTER TABLE `production_operations_alpfac`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `products_alpfac`
--
ALTER TABLE `products_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `model_number` (`model_number`),
  ADD KEY `idx_model_number` (`model_number`),
  ADD KEY `idx_category_id` (`category_id`),
  ADD KEY `idx_supplier_id` (`supplier_id`),
  ADD KEY `idx_is_active` (`is_active`);

--
-- Indexes for table `products_ret`
--
ALTER TABLE `products_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `model_number` (`model_number`),
  ADD KEY `idx_model_number` (`model_number`),
  ADD KEY `idx_category_id` (`category_id`),
  ADD KEY `idx_supplier_id` (`supplier_id`),
  ADD KEY `idx_is_active` (`is_active`);

--
-- Indexes for table `product_categories_alpfac`
--
ALTER TABLE `product_categories_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `idx_parent_id` (`parent_id`);

--
-- Indexes for table `product_categories_ret`
--
ALTER TABLE `product_categories_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `idx_parent_id` (`parent_id`);

--
-- Indexes for table `product_colors_alpfac`
--
ALTER TABLE `product_colors_alpfac`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `product_colors_ret`
--
ALTER TABLE `product_colors_ret`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `product_sizes_alpfac`
--
ALTER TABLE `product_sizes_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_product_size_group` (`product_id`,`size`,`age_type`,`group_key`),
  ADD KEY `idx_product_id` (`product_id`);

--
-- Indexes for table `product_sizes_ret`
--
ALTER TABLE `product_sizes_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_product_size_group` (`product_id`,`size`,`age_type`,`group_key`),
  ADD KEY `idx_product_id` (`product_id`);

--
-- Indexes for table `product_suppliers_alpfac`
--
ALTER TABLE `product_suppliers_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `product_suppliers_ret`
--
ALTER TABLE `product_suppliers_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `product_variants_alpfac`
--
ALTER TABLE `product_variants_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_size_color` (`size_id`,`color_id`),
  ADD KEY `idx_product_id` (`product_id`),
  ADD KEY `idx_barcode` (`barcode`);

--
-- Indexes for table `product_variants_ret`
--
ALTER TABLE `product_variants_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_size_color` (`size_id`,`color_id`),
  ADD KEY `idx_product_id` (`product_id`),
  ADD KEY `idx_barcode` (`barcode`);

--
-- Indexes for table `public_holidays_alpfac`
--
ALTER TABLE `public_holidays_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_date` (`holiday_date`),
  ADD KEY `idx_recurring` (`is_recurring`);

--
-- Indexes for table `public_holidays_ret`
--
ALTER TABLE `public_holidays_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_date` (`holiday_date`),
  ADD KEY `idx_recurring` (`is_recurring`);

--
-- Indexes for table `purchases_alpfac`
--
ALTER TABLE `purchases_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `purchase_number` (`purchase_number`),
  ADD KEY `idx_supplier_id` (`supplier_id`),
  ADD KEY `idx_purchase_date` (`purchase_date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_journal_entry` (`journal_entry_id`),
  ADD KEY `fk_purchases_alp_created_by` (`created_by`),
  ADD KEY `fk_purchases_alp_invoice_currency` (`invoice_currency_id`),
  ADD KEY `fk_purchases_alp_base_currency` (`base_currency_id`),
  ADD KEY `fk_purchases_alp_warehouse` (`warehouse_id`);

--
-- Indexes for table `purchases_ret`
--
ALTER TABLE `purchases_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `purchase_number` (`purchase_number`),
  ADD KEY `idx_supplier_id` (`supplier_id`),
  ADD KEY `idx_purchase_date` (`purchase_date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_journal_entry` (`journal_entry_id`),
  ADD KEY `fk_purchases_alp_created_by` (`created_by`),
  ADD KEY `fk_purchases_alp_invoice_currency` (`invoice_currency_id`),
  ADD KEY `fk_purchases_alp_base_currency` (`base_currency_id`),
  ADD KEY `fk_purchases_alp_warehouse` (`warehouse_id`);

--
-- Indexes for table `purchase_items_alpfac`
--
ALTER TABLE `purchase_items_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_purchase_id` (`purchase_id`),
  ADD KEY `idx_product_id` (`product_id`),
  ADD KEY `idx_variant_id` (`variant_id`),
  ADD KEY `fk_purchase_items_alp_created_by` (`created_by`),
  ADD KEY `fk_purchase_items_alp_updated_by` (`updated_by`);

--
-- Indexes for table `purchase_items_ret`
--
ALTER TABLE `purchase_items_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_purchase_id` (`purchase_id`),
  ADD KEY `idx_product_id` (`product_id`),
  ADD KEY `idx_variant_id` (`variant_id`),
  ADD KEY `fk_purchase_items_alp_created_by` (`created_by`),
  ADD KEY `fk_purchase_items_alp_updated_by` (`updated_by`);

--
-- Indexes for table `purchase_payments_alpfac`
--
ALTER TABLE `purchase_payments_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `payment_number` (`payment_number`),
  ADD KEY `idx_supplier` (`supplier_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_date` (`payment_date`);

--
-- Indexes for table `purchase_payments_ret`
--
ALTER TABLE `purchase_payments_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `payment_number` (`payment_number`),
  ADD KEY `idx_supplier` (`supplier_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_date` (`payment_date`);

--
-- Indexes for table `purchase_payment_invoices_alpfac`
--
ALTER TABLE `purchase_payment_invoices_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payment` (`payment_id`),
  ADD KEY `idx_purchase` (`purchase_id`);

--
-- Indexes for table `purchase_payment_invoices_ret`
--
ALTER TABLE `purchase_payment_invoices_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payment` (`payment_id`),
  ADD KEY `idx_purchase` (`purchase_id`);

--
-- Indexes for table `purchase_returns_alpfac`
--
ALTER TABLE `purchase_returns_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `return_number` (`return_number`),
  ADD KEY `idx_purchase_id` (`purchase_id`),
  ADD KEY `idx_supplier_id` (`supplier_id`),
  ADD KEY `fk_pr_currency` (`return_currency_id`),
  ADD KEY `fk_pr_base_currency` (`base_currency_id`),
  ADD KEY `fk_pr_warehouse` (`warehouse_id`);

--
-- Indexes for table `purchase_returns_ret`
--
ALTER TABLE `purchase_returns_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `return_number` (`return_number`),
  ADD KEY `idx_purchase_id` (`purchase_id`),
  ADD KEY `idx_supplier_id` (`supplier_id`),
  ADD KEY `fk_pr_currency` (`return_currency_id`),
  ADD KEY `fk_pr_base_currency` (`base_currency_id`),
  ADD KEY `fk_pr_warehouse` (`warehouse_id`);

--
-- Indexes for table `purchase_return_items_alpfac`
--
ALTER TABLE `purchase_return_items_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_return_id` (`return_id`),
  ADD KEY `idx_purchase_item_id` (`purchase_item_id`);

--
-- Indexes for table `purchase_return_items_ret`
--
ALTER TABLE `purchase_return_items_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_return_id` (`return_id`),
  ADD KEY `idx_purchase_item_id` (`purchase_item_id`);

--
-- Indexes for table `raw_materials_alpfac`
--
ALTER TABLE `raw_materials_alpfac`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `raw_material_stock_alpfac`
--
ALTER TABLE `raw_material_stock_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_material_wh` (`material_id`,`warehouse_id`);

--
-- Indexes for table `receipts_alpfac`
--
ALTER TABLE `receipts_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `receipt_number` (`receipt_number`),
  ADD KEY `idx_customer_id` (`customer_id`),
  ADD KEY `idx_receipt_date` (`receipt_date`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `receipts_ret`
--
ALTER TABLE `receipts_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `receipt_number` (`receipt_number`),
  ADD KEY `idx_customer_id` (`customer_id`),
  ADD KEY `idx_receipt_date` (`receipt_date`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `receipt_invoices_alpfac`
--
ALTER TABLE `receipt_invoices_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_receipt_invoice` (`receipt_id`,`invoice_id`),
  ADD KEY `idx_invoice_id` (`invoice_id`);

--
-- Indexes for table `receipt_invoices_ret`
--
ALTER TABLE `receipt_invoices_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_receipt_invoice` (`receipt_id`,`invoice_id`),
  ADD KEY `idx_invoice_id` (`invoice_id`);

--
-- Indexes for table `sales_invoices_alpfac`
--
ALTER TABLE `sales_invoices_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sales_invoices_ret_number` (`invoice_number`),
  ADD KEY `idx_sales_invoices_ret_customer` (`customer_id`),
  ADD KEY `idx_sales_invoices_ret_created_by` (`created_by`),
  ADD KEY `idx_sales_invoices_ret_date` (`invoice_date`),
  ADD KEY `idx_sales_invoices_ret_inv_currency` (`invoice_currency_id`),
  ADD KEY `idx_sales_invoices_ret_base_currency` (`base_currency_id`),
  ADD KEY `idx_sales_invoices_ret_warehouse` (`warehouse_id`),
  ADD KEY `idx_sales_invoices_ret_je` (`journal_entry_id`),
  ADD KEY `idx_sales_invoices_ret_status` (`status`);

--
-- Indexes for table `sales_invoices_ret`
--
ALTER TABLE `sales_invoices_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sales_invoices_ret_number` (`invoice_number`),
  ADD KEY `idx_sales_invoices_ret_customer` (`customer_id`),
  ADD KEY `idx_sales_invoices_ret_created_by` (`created_by`),
  ADD KEY `idx_sales_invoices_ret_date` (`invoice_date`),
  ADD KEY `idx_sales_invoices_ret_inv_currency` (`invoice_currency_id`),
  ADD KEY `idx_sales_invoices_ret_base_currency` (`base_currency_id`),
  ADD KEY `idx_sales_invoices_ret_warehouse` (`warehouse_id`),
  ADD KEY `idx_sales_invoices_ret_je` (`journal_entry_id`),
  ADD KEY `idx_sales_invoices_ret_status` (`status`);

--
-- Indexes for table `sales_invoice_items_alpfac`
--
ALTER TABLE `sales_invoice_items_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sales_invoice_items_ret_invoice` (`invoice_id`),
  ADD KEY `idx_sales_invoice_items_ret_product` (`product_id`),
  ADD KEY `idx_sales_invoice_items_ret_variant` (`variant_id`),
  ADD KEY `idx_sales_invoice_items_ret_created_by` (`created_by`),
  ADD KEY `idx_sales_invoice_items_ret_updated_by` (`updated_by`);

--
-- Indexes for table `sales_invoice_items_ret`
--
ALTER TABLE `sales_invoice_items_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sales_invoice_items_ret_invoice` (`invoice_id`),
  ADD KEY `idx_sales_invoice_items_ret_product` (`product_id`),
  ADD KEY `idx_sales_invoice_items_ret_variant` (`variant_id`),
  ADD KEY `idx_sales_invoice_items_ret_created_by` (`created_by`),
  ADD KEY `idx_sales_invoice_items_ret_updated_by` (`updated_by`);

--
-- Indexes for table `sales_returns_alpfac`
--
ALTER TABLE `sales_returns_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `return_number` (`return_number`),
  ADD KEY `idx_sr_invoice_id` (`invoice_id`),
  ADD KEY `idx_sr_customer_id` (`customer_id`),
  ADD KEY `fk_sr_currency` (`return_currency_id`),
  ADD KEY `fk_sr_base_currency` (`base_currency_id`),
  ADD KEY `fk_sr_warehouse` (`warehouse_id`);

--
-- Indexes for table `sales_returns_ret`
--
ALTER TABLE `sales_returns_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `return_number` (`return_number`),
  ADD KEY `idx_sr_invoice_id` (`invoice_id`),
  ADD KEY `idx_sr_customer_id` (`customer_id`),
  ADD KEY `fk_sr_currency` (`return_currency_id`),
  ADD KEY `fk_sr_base_currency` (`base_currency_id`),
  ADD KEY `fk_sr_warehouse` (`warehouse_id`);

--
-- Indexes for table `sales_return_items_alpfac`
--
ALTER TABLE `sales_return_items_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_return_id` (`return_id`),
  ADD KEY `idx_invoice_item` (`invoice_item_id`);

--
-- Indexes for table `sales_return_items_ret`
--
ALTER TABLE `sales_return_items_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_return_id` (`return_id`),
  ADD KEY `idx_invoice_item` (`invoice_item_id`);

--
-- Indexes for table `shipping_carriers`
--
ALTER TABLE `shipping_carriers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tax_types_alpfac`
--
ALTER TABLE `tax_types_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_scope` (`tax_scope`),
  ADD KEY `idx_active` (`is_active`);

--
-- Indexes for table `tax_types_ret`
--
ALTER TABLE `tax_types_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_scope` (`tax_scope`),
  ADD KEY `idx_active` (`is_active`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_is_active` (`is_active`);

--
-- Indexes for table `user_activities`
--
ALTER TABLE `user_activities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_branch_id` (`branch_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `user_branches`
--
ALTER TABLE `user_branches`
  ADD PRIMARY KEY (`user_id`,`branch_id`),
  ADD KEY `fk_ub_branch` (`branch_id`);

--
-- Indexes for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_user_branch_module` (`user_id`,`branch_id`,`module_key`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_branch_id` (`branch_id`);

--
-- Indexes for table `user_tab_order`
--
ALTER TABLE `user_tab_order`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_group` (`user_id`,`page_group`);

--
-- Indexes for table `warehouses_alpfac`
--
ALTER TABLE `warehouses_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `warehouses_ret`
--
ALTER TABLE `warehouses_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `warehouse_items_alpfac`
--
ALTER TABLE `warehouse_items_alpfac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_wh_variant` (`warehouse_id`,`variant_id`),
  ADD KEY `idx_variant_id` (`variant_id`),
  ADD KEY `idx_product_id` (`product_id`);

--
-- Indexes for table `warehouse_items_ret`
--
ALTER TABLE `warehouse_items_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_wh_variant` (`warehouse_id`,`variant_id`),
  ADD KEY `idx_variant_id` (`variant_id`),
  ADD KEY `idx_product_id` (`product_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `account_charts_alpfac`
--
ALTER TABLE `account_charts_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `account_charts_ret`
--
ALTER TABLE `account_charts_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_categories_alpfac`
--
ALTER TABLE `consumable_categories_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_categories_ret`
--
ALTER TABLE `consumable_categories_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_departments_alpfac`
--
ALTER TABLE `consumable_departments_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_departments_ret`
--
ALTER TABLE `consumable_departments_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_issues_alpfac`
--
ALTER TABLE `consumable_issues_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_issues_ret`
--
ALTER TABLE `consumable_issues_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_issue_items_alpfac`
--
ALTER TABLE `consumable_issue_items_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_issue_items_ret`
--
ALTER TABLE `consumable_issue_items_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_items_alpfac`
--
ALTER TABLE `consumable_items_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_items_ret`
--
ALTER TABLE `consumable_items_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_item_packagings_alpfac`
--
ALTER TABLE `consumable_item_packagings_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_item_packagings_ret`
--
ALTER TABLE `consumable_item_packagings_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_movements_alpfac`
--
ALTER TABLE `consumable_movements_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_movements_ret`
--
ALTER TABLE `consumable_movements_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_purchases_alpfac`
--
ALTER TABLE `consumable_purchases_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_purchases_ret`
--
ALTER TABLE `consumable_purchases_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_purchase_items_alpfac`
--
ALTER TABLE `consumable_purchase_items_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_purchase_items_ret`
--
ALTER TABLE `consumable_purchase_items_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_returns_alpfac`
--
ALTER TABLE `consumable_returns_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_returns_ret`
--
ALTER TABLE `consumable_returns_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_return_items_alpfac`
--
ALTER TABLE `consumable_return_items_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_return_items_ret`
--
ALTER TABLE `consumable_return_items_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_stock_alpfac`
--
ALTER TABLE `consumable_stock_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_stock_ret`
--
ALTER TABLE `consumable_stock_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_transfers_alpfac`
--
ALTER TABLE `consumable_transfers_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_transfers_ret`
--
ALTER TABLE `consumable_transfers_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_transfer_items_alpfac`
--
ALTER TABLE `consumable_transfer_items_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_transfer_items_ret`
--
ALTER TABLE `consumable_transfer_items_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_units_alpfac`
--
ALTER TABLE `consumable_units_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_units_ret`
--
ALTER TABLE `consumable_units_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `currencies`
--
ALTER TABLE `currencies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `currency_branch_links`
--
ALTER TABLE `currency_branch_links`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers_alpfac`
--
ALTER TABLE `customers_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers_ret`
--
ALTER TABLE `customers_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exchange_rates_alpfac`
--
ALTER TABLE `exchange_rates_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exchange_rates_ret`
--
ALTER TABLE `exchange_rates_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expenses_alpfac`
--
ALTER TABLE `expenses_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expenses_ret`
--
ALTER TABLE `expenses_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_attendance_alpfac`
--
ALTER TABLE `hr_attendance_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_attendance_ret`
--
ALTER TABLE `hr_attendance_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_bonuses_alpfac`
--
ALTER TABLE `hr_bonuses_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_bonuses_ret`
--
ALTER TABLE `hr_bonuses_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_employees_alpfac`
--
ALTER TABLE `hr_employees_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_employees_ret`
--
ALTER TABLE `hr_employees_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_loans_alpfac`
--
ALTER TABLE `hr_loans_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_loans_ret`
--
ALTER TABLE `hr_loans_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_payroll_alpfac`
--
ALTER TABLE `hr_payroll_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_payroll_loan_allocations_alpfac`
--
ALTER TABLE `hr_payroll_loan_allocations_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_payroll_loan_allocations_ret`
--
ALTER TABLE `hr_payroll_loan_allocations_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_payroll_ret`
--
ALTER TABLE `hr_payroll_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_promotions_alpfac`
--
ALTER TABLE `hr_promotions_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_promotions_ret`
--
ALTER TABLE `hr_promotions_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `internal_orders`
--
ALTER TABLE `internal_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `internal_order_items`
--
ALTER TABLE `internal_order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_movements_alpfac`
--
ALTER TABLE `inventory_movements_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_movements_ret`
--
ALTER TABLE `inventory_movements_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_movement_details_alpfac`
--
ALTER TABLE `inventory_movement_details_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_movement_details_ret`
--
ALTER TABLE `inventory_movement_details_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_account_settings_alpfac`
--
ALTER TABLE `invoice_account_settings_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_account_settings_ret`
--
ALTER TABLE `invoice_account_settings_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_entries_alpfac`
--
ALTER TABLE `journal_entries_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_entries_ret`
--
ALTER TABLE `journal_entries_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_entry_items_alpfac`
--
ALTER TABLE `journal_entry_items_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_entry_items_ret`
--
ALTER TABLE `journal_entry_items_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `manufacturing_bom_alpfac`
--
ALTER TABLE `manufacturing_bom_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `migration_alp_to_ret_log`
--
ALTER TABLE `migration_alp_to_ret_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `modules`
--
ALTER TABLE `modules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications_alpfac`
--
ALTER TABLE `notifications_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications_ret`
--
ALTER TABLE `notifications_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pricing_patterns_alpfac`
--
ALTER TABLE `pricing_patterns_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pricing_patterns_ret`
--
ALTER TABLE `pricing_patterns_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_entries_alpfac`
--
ALTER TABLE `production_entries_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_operations_alpfac`
--
ALTER TABLE `production_operations_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products_alpfac`
--
ALTER TABLE `products_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products_ret`
--
ALTER TABLE `products_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_categories_alpfac`
--
ALTER TABLE `product_categories_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_categories_ret`
--
ALTER TABLE `product_categories_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_colors_alpfac`
--
ALTER TABLE `product_colors_alpfac`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_colors_ret`
--
ALTER TABLE `product_colors_ret`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_sizes_alpfac`
--
ALTER TABLE `product_sizes_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_sizes_ret`
--
ALTER TABLE `product_sizes_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_suppliers_alpfac`
--
ALTER TABLE `product_suppliers_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_suppliers_ret`
--
ALTER TABLE `product_suppliers_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_variants_alpfac`
--
ALTER TABLE `product_variants_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_variants_ret`
--
ALTER TABLE `product_variants_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `public_holidays_alpfac`
--
ALTER TABLE `public_holidays_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `public_holidays_ret`
--
ALTER TABLE `public_holidays_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchases_alpfac`
--
ALTER TABLE `purchases_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchases_ret`
--
ALTER TABLE `purchases_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_items_alpfac`
--
ALTER TABLE `purchase_items_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_items_ret`
--
ALTER TABLE `purchase_items_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_payments_alpfac`
--
ALTER TABLE `purchase_payments_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_payments_ret`
--
ALTER TABLE `purchase_payments_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_payment_invoices_alpfac`
--
ALTER TABLE `purchase_payment_invoices_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_payment_invoices_ret`
--
ALTER TABLE `purchase_payment_invoices_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_returns_alpfac`
--
ALTER TABLE `purchase_returns_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_returns_ret`
--
ALTER TABLE `purchase_returns_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_return_items_alpfac`
--
ALTER TABLE `purchase_return_items_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_return_items_ret`
--
ALTER TABLE `purchase_return_items_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `raw_materials_alpfac`
--
ALTER TABLE `raw_materials_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `raw_material_stock_alpfac`
--
ALTER TABLE `raw_material_stock_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `receipts_alpfac`
--
ALTER TABLE `receipts_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `receipts_ret`
--
ALTER TABLE `receipts_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `receipt_invoices_alpfac`
--
ALTER TABLE `receipt_invoices_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `receipt_invoices_ret`
--
ALTER TABLE `receipt_invoices_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_invoices_alpfac`
--
ALTER TABLE `sales_invoices_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_invoices_ret`
--
ALTER TABLE `sales_invoices_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_invoice_items_alpfac`
--
ALTER TABLE `sales_invoice_items_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_invoice_items_ret`
--
ALTER TABLE `sales_invoice_items_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_returns_alpfac`
--
ALTER TABLE `sales_returns_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_returns_ret`
--
ALTER TABLE `sales_returns_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_return_items_alpfac`
--
ALTER TABLE `sales_return_items_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_return_items_ret`
--
ALTER TABLE `sales_return_items_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shipping_carriers`
--
ALTER TABLE `shipping_carriers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tax_types_alpfac`
--
ALTER TABLE `tax_types_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tax_types_ret`
--
ALTER TABLE `tax_types_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_activities`
--
ALTER TABLE `user_activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_permissions`
--
ALTER TABLE `user_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_tab_order`
--
ALTER TABLE `user_tab_order`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `warehouses_alpfac`
--
ALTER TABLE `warehouses_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `warehouses_ret`
--
ALTER TABLE `warehouses_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `warehouse_items_alpfac`
--
ALTER TABLE `warehouse_items_alpfac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `warehouse_items_ret`
--
ALTER TABLE `warehouse_items_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `branches`
--
ALTER TABLE `branches`
  ADD CONSTRAINT `fk_branches_base_currency` FOREIGN KEY (`base_currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `fk_branches_local_currency` FOREIGN KEY (`local_currency_id`) REFERENCES `currencies` (`id`);

--
-- Constraints for table `consumable_issues_ret`
--
ALTER TABLE `consumable_issues_ret`
  ADD CONSTRAINT `fk_ci_department_ret` FOREIGN KEY (`department_id`) REFERENCES `consumable_departments_ret` (`id`),
  ADD CONSTRAINT `fk_ci_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_ret` (`id`);

--
-- Constraints for table `consumable_issue_items_ret`
--
ALTER TABLE `consumable_issue_items_ret`
  ADD CONSTRAINT `fk_cii_issue` FOREIGN KEY (`issue_id`) REFERENCES `consumable_issues_ret` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cii_item` FOREIGN KEY (`item_id`) REFERENCES `consumable_items_ret` (`id`),
  ADD CONSTRAINT `fk_cii_movement` FOREIGN KEY (`movement_id`) REFERENCES `consumable_movements_ret` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cii_packaging_ret` FOREIGN KEY (`packaging_id`) REFERENCES `consumable_item_packagings_ret` (`id`);

--
-- Constraints for table `consumable_items_ret`
--
ALTER TABLE `consumable_items_ret`
  ADD CONSTRAINT `fk_ci_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_consumable_items_category_ret` FOREIGN KEY (`category_id`) REFERENCES `consumable_categories_ret` (`id`),
  ADD CONSTRAINT `fk_consumable_items_unit_ret` FOREIGN KEY (`unit_id`) REFERENCES `consumable_units_ret` (`id`);

--
-- Constraints for table `consumable_item_packagings_ret`
--
ALTER TABLE `consumable_item_packagings_ret`
  ADD CONSTRAINT `fk_ci_packagings_item` FOREIGN KEY (`item_id`) REFERENCES `consumable_items_ret` (`id`);

--
-- Constraints for table `consumable_movements_ret`
--
ALTER TABLE `consumable_movements_ret`
  ADD CONSTRAINT `fk_cm_item` FOREIGN KEY (`item_id`) REFERENCES `consumable_items_ret` (`id`),
  ADD CONSTRAINT `fk_cm_to_warehouse` FOREIGN KEY (`to_warehouse_id`) REFERENCES `warehouses_ret` (`id`),
  ADD CONSTRAINT `fk_cm_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_ret` (`id`);

--
-- Constraints for table `consumable_purchases_ret`
--
ALTER TABLE `consumable_purchases_ret`
  ADD CONSTRAINT `fk_cp_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `product_suppliers_ret` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cp_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_ret` (`id`);

--
-- Constraints for table `consumable_purchase_items_ret`
--
ALTER TABLE `consumable_purchase_items_ret`
  ADD CONSTRAINT `fk_cpi_item` FOREIGN KEY (`item_id`) REFERENCES `consumable_items_ret` (`id`),
  ADD CONSTRAINT `fk_cpi_movement` FOREIGN KEY (`movement_id`) REFERENCES `consumable_movements_ret` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cpi_packaging_ret` FOREIGN KEY (`packaging_id`) REFERENCES `consumable_item_packagings_ret` (`id`),
  ADD CONSTRAINT `fk_cpi_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `consumable_purchases_ret` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `consumable_returns_ret`
--
ALTER TABLE `consumable_returns_ret`
  ADD CONSTRAINT `fk_cr_issue_ret` FOREIGN KEY (`issue_id`) REFERENCES `consumable_issues_ret` (`id`);

--
-- Constraints for table `consumable_return_items_ret`
--
ALTER TABLE `consumable_return_items_ret`
  ADD CONSTRAINT `fk_cri_issue_item_ret` FOREIGN KEY (`issue_item_id`) REFERENCES `consumable_issue_items_ret` (`id`),
  ADD CONSTRAINT `fk_cri_return_ret` FOREIGN KEY (`return_id`) REFERENCES `consumable_returns_ret` (`id`);

--
-- Constraints for table `consumable_stock_ret`
--
ALTER TABLE `consumable_stock_ret`
  ADD CONSTRAINT `fk_cs_item` FOREIGN KEY (`item_id`) REFERENCES `consumable_items_ret` (`id`),
  ADD CONSTRAINT `fk_cs_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_ret` (`id`);

--
-- Constraints for table `consumable_transfers_ret`
--
ALTER TABLE `consumable_transfers_ret`
  ADD CONSTRAINT `fk_ctr_from_wh` FOREIGN KEY (`from_warehouse_id`) REFERENCES `warehouses_ret` (`id`),
  ADD CONSTRAINT `fk_ctr_to_wh` FOREIGN KEY (`to_warehouse_id`) REFERENCES `warehouses_ret` (`id`);

--
-- Constraints for table `consumable_transfer_items_ret`
--
ALTER TABLE `consumable_transfer_items_ret`
  ADD CONSTRAINT `fk_ctri_transfer` FOREIGN KEY (`transfer_id`) REFERENCES `consumable_transfers_ret` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `customers_alpfac`
--
ALTER TABLE `customers_alpfac`
  ADD CONSTRAINT `fk_customers_pricing_pattern_alpfac` FOREIGN KEY (`pricing_pattern_id`) REFERENCES `pricing_patterns_alpfac` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `customers_ret`
--
ALTER TABLE `customers_ret`
  ADD CONSTRAINT `fk_customers_pricing_pattern` FOREIGN KEY (`pricing_pattern_id`) REFERENCES `pricing_patterns_ret` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `hr_employees_ret`
--
ALTER TABLE `hr_employees_ret`
  ADD CONSTRAINT `fk_currency_hr_employees` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`);

--
-- Constraints for table `internal_order_items`
--
ALTER TABLE `internal_order_items`
  ADD CONSTRAINT `fk_ioi_order` FOREIGN KEY (`order_id`) REFERENCES `internal_orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inventory_movement_details_ret`
--
ALTER TABLE `inventory_movement_details_ret`
  ADD CONSTRAINT `fk_imd_movement` FOREIGN KEY (`movement_id`) REFERENCES `inventory_movements_ret` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `journal_entries_ret`
--
ALTER TABLE `journal_entries_ret`
  ADD CONSTRAINT `fk_je_currency_id` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `fk_journal_entries_ret_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`);

--
-- Constraints for table `journal_entry_items_ret`
--
ALTER TABLE `journal_entry_items_ret`
  ADD CONSTRAINT `fk_jei_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries_ret` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ji_currency_id` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`);

--
-- Constraints for table `products_ret`
--
ALTER TABLE `products_ret`
  ADD CONSTRAINT `fk_prod_category` FOREIGN KEY (`category_id`) REFERENCES `product_categories_ret` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_prod_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `product_suppliers_ret` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `product_sizes_ret`
--
ALTER TABLE `product_sizes_ret`
  ADD CONSTRAINT `fk_size_product` FOREIGN KEY (`product_id`) REFERENCES `products_ret` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_variants_ret`
--
ALTER TABLE `product_variants_ret`
  ADD CONSTRAINT `fk_var_product` FOREIGN KEY (`product_id`) REFERENCES `products_ret` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_var_size` FOREIGN KEY (`size_id`) REFERENCES `product_sizes_ret` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchases_ret`
--
ALTER TABLE `purchases_ret`
  ADD CONSTRAINT `fk_purchases_alp_base_currency` FOREIGN KEY (`base_currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `fk_purchases_alp_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_purchases_alp_invoice_currency` FOREIGN KEY (`invoice_currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `fk_purchases_alp_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_ret` (`id`);

--
-- Constraints for table `purchase_items_ret`
--
ALTER TABLE `purchase_items_ret`
  ADD CONSTRAINT `fk_pi_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases_ret` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_purchase_items_alp_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_purchase_items_alp_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `purchase_payment_invoices_ret`
--
ALTER TABLE `purchase_payment_invoices_ret`
  ADD CONSTRAINT `fk_ppi_payment_ret` FOREIGN KEY (`payment_id`) REFERENCES `purchase_payments_ret` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchase_returns_ret`
--
ALTER TABLE `purchase_returns_ret`
  ADD CONSTRAINT `fk_pr_base_currency` FOREIGN KEY (`base_currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `fk_pr_currency` FOREIGN KEY (`return_currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `fk_pr_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases_ret` (`id`),
  ADD CONSTRAINT `fk_pr_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `product_suppliers_ret` (`id`),
  ADD CONSTRAINT `fk_pr_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_ret` (`id`);

--
-- Constraints for table `purchase_return_items_ret`
--
ALTER TABLE `purchase_return_items_ret`
  ADD CONSTRAINT `fk_pri_return` FOREIGN KEY (`return_id`) REFERENCES `purchase_returns_ret` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `receipt_invoices_ret`
--
ALTER TABLE `receipt_invoices_ret`
  ADD CONSTRAINT `fk_ri_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices_ret` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ri_receipt` FOREIGN KEY (`receipt_id`) REFERENCES `receipts_ret` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sales_invoices_ret`
--
ALTER TABLE `sales_invoices_ret`
  ADD CONSTRAINT `fk_sales_invoices_ret_base_currency` FOREIGN KEY (`base_currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `fk_sales_invoices_ret_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers_ret` (`id`),
  ADD CONSTRAINT `fk_sales_invoices_ret_inv_currency` FOREIGN KEY (`invoice_currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `fk_sales_invoices_ret_je` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries_ret` (`id`),
  ADD CONSTRAINT `fk_sales_invoices_ret_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_ret` (`id`);

--
-- Constraints for table `sales_invoice_items_ret`
--
ALTER TABLE `sales_invoice_items_ret`
  ADD CONSTRAINT `fk_sales_invoice_items_ret_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices_ret` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sales_invoice_items_ret_product` FOREIGN KEY (`product_id`) REFERENCES `products_ret` (`id`),
  ADD CONSTRAINT `fk_sales_invoice_items_ret_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants_ret` (`id`);

--
-- Constraints for table `sales_returns_ret`
--
ALTER TABLE `sales_returns_ret`
  ADD CONSTRAINT `fk_sr_base_currency` FOREIGN KEY (`base_currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `fk_sr_currency` FOREIGN KEY (`return_currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `fk_sr_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers_ret` (`id`),
  ADD CONSTRAINT `fk_sr_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices_ret` (`id`),
  ADD CONSTRAINT `fk_sr_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_ret` (`id`);

--
-- Constraints for table `sales_return_items_ret`
--
ALTER TABLE `sales_return_items_ret`
  ADD CONSTRAINT `fk_sri_return` FOREIGN KEY (`return_id`) REFERENCES `sales_returns_ret` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_branches`
--
ALTER TABLE `user_branches`
  ADD CONSTRAINT `fk_ub_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ub_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD CONSTRAINT `fk_up_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_up_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_tab_order`
--
ALTER TABLE `user_tab_order`
  ADD CONSTRAINT `fk_uto_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `warehouse_items_ret`
--
ALTER TABLE `warehouse_items_ret`
  ADD CONSTRAINT `fk_wi_product` FOREIGN KEY (`product_id`) REFERENCES `products_ret` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_wi_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants_ret` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_wi_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_ret` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
