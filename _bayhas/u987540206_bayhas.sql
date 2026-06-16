-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Jun 16, 2026 at 07:20 AM
-- Server version: 11.8.6-MariaDB-log
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u987540206_bayhas`
--

-- --------------------------------------------------------

--
-- Table structure for table `account_charts_alp`
--

CREATE TABLE `account_charts_alp` (
  `id` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `account_type` enum('asset','liability','equity','revenue','expense') NOT NULL,
  `balance` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'الرصيد بعملة الحساب',
  `base_balance` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'الرصيد بالدولار USD',
  `currency` enum('USD','TRY','EUR','SYP') NOT NULL DEFAULT 'USD',
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `level` tinyint(3) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_locked` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'حساب نظامي لا يُحذف',
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='شجرة الحسابات — فرع حلب';

-- --------------------------------------------------------

--
-- Table structure for table `attendance_alp`
--

CREATE TABLE `attendance_alp` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `work_date` date NOT NULL,
  `status` enum('present','absent','half_day','on_leave','holiday') NOT NULL DEFAULT 'present',
  `check_in` time DEFAULT NULL,
  `check_out` time DEFAULT NULL,
  `overtime_hours` decimal(4,2) NOT NULL DEFAULT 0.00 COMMENT 'ساعات إضافية',
  `deduction_pct` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'نسبة الخصم %',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `name_en` varchar(100) DEFAULT NULL COMMENT 'الاسم بالإنجليزية',
  `branch_type` enum('retail','factory','warehouse','lab','office') NOT NULL DEFAULT 'retail' COMMENT 'نوع الفرع: محل|معمل|مستودع|مختبر|مكتب',
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'Syria',
  `tax_number` varchar(50) DEFAULT NULL COMMENT 'الرقم الضريبي للفرع',
  `factory_branch_id` int(11) DEFAULT NULL COMMENT 'معرف فرع المعمل/المصدر المرتبط بهذا الفرع — لإنشاء الطلبيات الداخلية',
  `base_currency` varchar(3) NOT NULL DEFAULT 'USD' COMMENT 'العملة الأساسية للفرع (USD دائماً كعملة تقارير)',
  `local_currency` varchar(3) NOT NULL DEFAULT 'SYP' COMMENT 'العملة المحلية للمعاملات اليومية',
  `pricing_method` enum('fixed','cost_plus','market') NOT NULL DEFAULT 'cost_plus' COMMENT 'طريقة التسعير: ثابت | تكلفة+هامش | سعر السوق',
  `default_margin_pct` decimal(5,2) NOT NULL DEFAULT 20.00 COMMENT 'هامش الربح الافتراضي %',
  `tax_rate_default` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'نسبة الضريبة الافتراضية %',
  `allow_negative_stock` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'السماح بالمخزون السالب',
  `notify_low_stock` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'إشعار عند انخفاض المخزون',
  `low_stock_threshold` int(11) NOT NULL DEFAULT 5 COMMENT 'حد المخزون المنخفض (كمية)',
  `notify_new_invoice` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'إشعار عند إنشاء فاتورة جديدة',
  `notify_internal_order` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'إشعار عند وصول طلبية داخلية من فرع آخر',
  `notify_email` varchar(200) DEFAULT NULL COMMENT 'بريد استقبال الإشعارات',
  `invoice_prefix` varchar(10) NOT NULL DEFAULT 'INV' COMMENT 'بادئة رقم الفاتورة مثل: ALP, IST',
  `invoice_counter` int(11) NOT NULL DEFAULT 0 COMMENT 'عداد الفواتير الحالي',
  `fiscal_year_start` tinyint(2) NOT NULL DEFAULT 1 COMMENT 'شهر بداية السنة المالية (1=يناير)',
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
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الفروع — كل فرع له table_suffix خاص به';

--
-- Dumping data for table `branches`
--

INSERT INTO `branches` (`id`, `name`, `name_en`, `branch_type`, `phone`, `email`, `address`, `city`, `country`, `tax_number`, `factory_branch_id`, `base_currency`, `local_currency`, `pricing_method`, `default_margin_pct`, `tax_rate_default`, `allow_negative_stock`, `notify_low_stock`, `low_stock_threshold`, `notify_new_invoice`, `notify_internal_order`, `notify_email`, `invoice_prefix`, `invoice_counter`, `fiscal_year_start`, `week_start_day`, `default_payment_terms`, `code`, `table_suffix`, `dashboard_path`, `icon`, `color`, `sort_order`, `created_by`, `updated_by`, `status`, `created_at`, `updated_at`) VALUES
(1, 'فرع حلب', NULL, 'retail', '+963992326518', NULL, 'حلب - دوار السبع بحرات - باتجاه الجامع الكبير', 'حلب', 'Syria', NULL, 5, 'USD', 'SYP', 'cost_plus', 10.00, 0.00, 0, 1, 5, 1, 1, NULL, 'ALP', 0, 1, 6, 30, 'ALP', 'alp', 'aleppo/modules/dashboard.php', 'bi-shop-window', '#f59e0b', 1, NULL, 1, 'active', '2026-05-20 11:51:25', '2026-06-09 08:16:39'),
(2, 'فرع استنبول', NULL, 'retail', NULL, NULL, NULL, NULL, 'Syria', NULL, NULL, 'USD', 'TRY', 'cost_plus', 20.00, 0.00, 0, 1, 5, 1, 1, NULL, 'IST', 0, 1, 1, 30, 'IST', 'ist', 'istanbul/modules/dashboard.php', 'bi-shop-window', '#ef4444', 2, NULL, 1, 'active', '2026-05-20 11:51:25', '2026-06-09 08:17:44'),
(3, 'فرع عنتاب', NULL, 'retail', NULL, NULL, NULL, NULL, 'Syria', NULL, NULL, 'USD', 'TRY', 'cost_plus', 20.00, 0.00, 0, 1, 5, 1, 1, NULL, 'GAZ', 0, 1, 1, 30, 'GAZ', 'gaz', 'gaziantep/modules/dashboard.php', 'bi-shop-window', '#10b981', 3, NULL, 1, 'active', '2026-05-20 11:51:25', '2026-06-09 08:17:48'),
(4, 'معمل عنتاب', 'antep lab', 'factory', '05359276493', 'bayhasbayhas1981@gmail.com', 'antep', 'carsi', 'Syria', NULL, NULL, 'USD', 'TRY', 'cost_plus', 40.00, 20.00, 0, 1, 5, 1, 1, NULL, 'ANTP_LAB', 0, 1, 1, 30, 'ANTP LAB', 'lab', 'lab/modules/dashboard.php', 'bi-building', '#10b981', 4, NULL, 1, 'active', '2026-05-20 11:51:25', '2026-06-09 08:17:52'),
(5, 'معمل حلب', 'Aleppo Factory', 'factory', '+963985995741', 'bayhasbayhas1981@gmail.com', 'حلب - دوار الجزماتي - جانب صالة الجوهرة', 'حلب', 'Syria', NULL, NULL, 'USD', 'USD', 'cost_plus', 20.00, 0.00, 0, 1, 5, 1, 1, NULL, 'ALP_LAB', 0, 1, 6, 30, 'ALP_LAB', 'alp_lab', 'alep_lab/modules/dashboard.php', 'bi-buildings', '#0428c9', 5, NULL, 1, 'active', '2026-05-20 12:10:21', '2026-06-09 08:17:57');

-- --------------------------------------------------------

--
-- Table structure for table `consumables_alp`
--

CREATE TABLE `consumables_alp` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL COMMENT 'اسم المستهلك: كهرباء، قهوة، إنترنت',
  `category` enum('utility','supplies','food','maintenance','other') NOT NULL DEFAULT 'other',
  `unit` varchar(30) DEFAULT NULL COMMENT 'وحدة القياس: كيلو، لتر، قطعة، فاتورة',
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أنواع المستهلكات — بدون مخزون ثابت';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_entries_alp`
--

CREATE TABLE `consumable_entries_alp` (
  `id` int(11) NOT NULL,
  `consumable_id` int(11) NOT NULL,
  `entry_date` date NOT NULL,
  `quantity` decimal(10,3) DEFAULT NULL COMMENT 'الكمية — NULL للفواتير الثابتة',
  `amount_original` decimal(15,4) NOT NULL COMMENT 'المبلغ بعملة الدفع',
  `currency` varchar(3) NOT NULL DEFAULT 'TRY',
  `exchange_rate` decimal(10,6) NOT NULL DEFAULT 1.000000,
  `amount_usd` decimal(15,4) NOT NULL COMMENT 'المبلغ بالدولار USD',
  `payment_method` enum('cash','bank','card','deferred') NOT NULL DEFAULT 'cash',
  `cash_account_id` int(11) DEFAULT NULL COMMENT 'حساب الصندوق/البنك',
  `expense_account_id` int(11) DEFAULT NULL COMMENT 'حساب المصروف',
  `journal_entry_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجلات الاستهلاك — كل فاتورة = سطر مستقل';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_issues_alp`
--

CREATE TABLE `consumable_issues_alp` (
  `id` int(11) NOT NULL,
  `issue_no` varchar(30) NOT NULL COMMENT 'ISS-2024-0001',
  `warehouse_id` int(11) NOT NULL COMMENT 'المستودع المصدر',
  `department` varchar(100) DEFAULT NULL COMMENT 'القسم/الجهة المستلمة',
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
-- Table structure for table `consumable_issue_items_alp`
--

CREATE TABLE `consumable_issue_items_alp` (
  `id` int(11) NOT NULL,
  `issue_id` int(11) NOT NULL COMMENT 'FK → consumable_issues_alp',
  `item_id` int(11) NOT NULL COMMENT 'FK → consumable_items_alp',
  `quantity` decimal(12,3) NOT NULL,
  `unit_cost_usd` decimal(12,4) DEFAULT 0.0000,
  `total_cost_usd` decimal(15,4) DEFAULT 0.0000,
  `movement_id` int(11) DEFAULT NULL COMMENT 'FK → consumable_movements_alp',
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تفاصيل أوامر الصرف الداخلي';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_items_alp`
--

CREATE TABLE `consumable_items_alp` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL COMMENT 'قهوة، ماء، قرطاسية، كهربا',
  `category` enum('utility','supplies','food','maintenance','other') NOT NULL DEFAULT 'other',
  `unit` varchar(20) NOT NULL DEFAULT 'قطعة',
  `estimated_cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'تكلفة تقديرية للمقارنة',
  `currency_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='المستهلكات — فرع حلب';

--
-- Dumping data for table `consumable_items_alp`
--

INSERT INTO `consumable_items_alp` (`id`, `name`, `category`, `unit`, `estimated_cost`, `currency_id`, `notes`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
(2, 'قهوة', 'food', 'علبة', 2.50, 1, '', 1, 1, '2026-06-15 11:35:23', NULL),
(3, 'لاصق كبير', 'supplies', 'قطعة', 50000.00, 4, '', 1, 1, '2026-06-16 05:24:57', NULL),
(4, 'إبرة لماكينة العنقبة', 'maintenance', 'علية', 175000.00, 4, '', 1, 1, '2026-06-16 05:25:37', NULL),
(5, 'خيط دهبي عيار 27', 'maintenance', 'كونة', 2.00, 1, '', 1, 1, '2026-06-16 05:26:01', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `consumable_movements_alp`
--

CREATE TABLE `consumable_movements_alp` (
  `id` int(11) NOT NULL,
  `movement_no` varchar(30) NOT NULL COMMENT 'رقم الحركة: MOV-2024-0001',
  `item_id` int(11) NOT NULL COMMENT 'FK → consumable_items_alp',
  `warehouse_id` int(11) NOT NULL COMMENT 'FK → warehouses_alp',
  `movement_type` enum('receive','issue','return_in','return_out','transfer','adjust','waste') NOT NULL,
  `direction` enum('in','out') NOT NULL COMMENT 'داخل أو خارج المخزون',
  `quantity` decimal(12,3) NOT NULL COMMENT 'الكمية الموجبة دائماً',
  `unit_cost_usd` decimal(12,4) DEFAULT 0.0000 COMMENT 'تكلفة الوحدة بالدولار',
  `total_cost_usd` decimal(15,4) DEFAULT 0.0000 COMMENT 'إجمالي التكلفة بالدولار',
  `qty_before` decimal(12,3) DEFAULT 0.000 COMMENT 'الرصيد قبل الحركة',
  `qty_after` decimal(12,3) DEFAULT 0.000 COMMENT 'الرصيد بعد الحركة',
  `reference_type` enum('purchase','sale','issue','transfer','inventory','manual') NOT NULL DEFAULT 'manual',
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
-- Table structure for table `consumable_purchases_alp`
--

CREATE TABLE `consumable_purchases_alp` (
  `id` int(11) NOT NULL,
  `invoice_no` varchar(50) NOT NULL COMMENT 'رقم الفاتورة الداخلي: PUR-2024-0001',
  `supplier_ref` varchar(100) DEFAULT NULL COMMENT 'رقم فاتورة المورد',
  `supplier_id` int(11) DEFAULT NULL COMMENT 'FK → product_suppliers_alp',
  `warehouse_id` int(11) NOT NULL COMMENT 'المستودع المستلِم',
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL COMMENT 'تاريخ الاستحقاق',
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `exchange_rate` decimal(10,6) NOT NULL DEFAULT 1.000000,
  `subtotal_usd` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `discount_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `discount_usd` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `tax_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `tax_usd` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `total_usd` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `paid_usd` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `balance_usd` decimal(15,4) NOT NULL DEFAULT 0.0000,
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
-- Table structure for table `consumable_purchase_items_alp`
--

CREATE TABLE `consumable_purchase_items_alp` (
  `id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL COMMENT 'FK → consumable_purchases_alp',
  `item_id` int(11) NOT NULL COMMENT 'FK → consumable_items_alp',
  `quantity` decimal(12,3) NOT NULL,
  `unit_price_usd` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `discount_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `total_usd` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `movement_id` int(11) DEFAULT NULL COMMENT 'FK → consumable_movements_alp (حركة الاستلام)',
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تفاصيل فواتير شراء المستهلكات';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_sales_alp`
--

CREATE TABLE `consumable_sales_alp` (
  `id` int(11) NOT NULL,
  `invoice_no` varchar(50) NOT NULL COMMENT 'SAL-2024-0001',
  `customer_name` varchar(255) DEFAULT NULL,
  `customer_phone` varchar(50) DEFAULT NULL,
  `warehouse_id` int(11) NOT NULL COMMENT 'المستودع المصدر',
  `invoice_date` date NOT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `exchange_rate` decimal(10,6) NOT NULL DEFAULT 1.000000,
  `subtotal_usd` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `discount_usd` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `total_usd` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `paid_usd` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `status` enum('draft','confirmed','paid','cancelled') NOT NULL DEFAULT 'draft',
  `payment_method` enum('cash','bank','card','deferred') DEFAULT 'cash',
  `journal_entry_id` int(11) DEFAULT NULL,
  `is_posted` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='فواتير بيع المستهلكات';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_sale_items_alp`
--

CREATE TABLE `consumable_sale_items_alp` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL COMMENT 'FK → consumable_sales_alp',
  `item_id` int(11) NOT NULL COMMENT 'FK → consumable_items_alp',
  `quantity` decimal(12,3) NOT NULL,
  `unit_price_usd` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `discount_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `total_usd` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `movement_id` int(11) DEFAULT NULL COMMENT 'FK → consumable_movements_alp',
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تفاصيل فواتير بيع المستهلكات';

-- --------------------------------------------------------

--
-- Table structure for table `consumable_stock_alp`
--

CREATE TABLE `consumable_stock_alp` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL COMMENT 'FK → consumable_items_alp',
  `warehouse_id` int(11) NOT NULL COMMENT 'FK → warehouses_alp',
  `quantity` decimal(12,3) NOT NULL DEFAULT 0.000 COMMENT 'الرصيد الحالي',
  `min_quantity` decimal(12,3) NOT NULL DEFAULT 0.000 COMMENT 'حد التنبيه',
  `avg_cost_usd` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT 'متوسط التكلفة (Weighted Average)',
  `last_movement` datetime DEFAULT NULL COMMENT 'تاريخ آخر حركة',
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أرصدة المستهلكات لكل مستودع';

--
-- Dumping data for table `consumable_stock_alp`
--

INSERT INTO `consumable_stock_alp` (`id`, `item_id`, `warehouse_id`, `quantity`, `min_quantity`, `avg_cost_usd`, `last_movement`, `updated_at`) VALUES
(3, 2, 2, 0.000, 2.000, 0.0000, NULL, NULL),
(4, 3, 1, 0.000, 5.000, 0.0000, NULL, NULL),
(5, 4, 2, 10.000, 5.000, 87506.0345, '2026-06-16 07:04:44', '2026-06-16 07:04:44'),
(6, 5, 2, 0.000, 15.000, 0.0000, NULL, NULL),
(7, 3, 2, 0.000, 0.000, 3.4483, '2026-06-16 07:05:17', '2026-06-16 07:05:17');

-- --------------------------------------------------------

--
-- Table structure for table `currencies`
--

CREATE TABLE `currencies` (
  `id` int(11) NOT NULL,
  `code` varchar(3) NOT NULL,
  `name` varchar(50) NOT NULL,
  `symbol` varchar(10) DEFAULT NULL,
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000 COMMENT 'سعر الصرف إلى USD',
  `is_base` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = USD العملة الأساس',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `currencies`
--

INSERT INTO `currencies` (`id`, `code`, `name`, `symbol`, `exchange_rate`, `is_base`, `status`, `updated_at`) VALUES
(1, 'USD', 'دولار أمريكي', '$', 1.0000, 1, 'active', NULL),
(2, 'TRY', 'ليرة تركية', '₺', 32.5000, 0, 'active', NULL),
(3, 'EUR', 'يورو', '€', 0.9200, 0, 'active', NULL),
(4, 'SYP', 'ليرة سورية', 'ل.س', 13000.0000, 0, 'active', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `customers_alp`
--

CREATE TABLE `customers_alp` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `type` enum('individual','company') NOT NULL DEFAULT 'individual',
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `tax_number` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `shipping_company` varchar(255) DEFAULT NULL,
  `shipping_code` varchar(100) DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL COMMENT 'حساب الذمم في شجرة الحسابات',
  `prepaid_account_id` int(11) DEFAULT NULL COMMENT 'حساب الدفعات المقدمة',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employees_alp`
--

CREATE TABLE `employees_alp` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `position` varchar(100) DEFAULT NULL COMMENT 'المسمى الوظيفي',
  `department` enum('sales','warehouse','production','accounting','hr','admin','other') NOT NULL DEFAULT 'other',
  `phone` varchar(30) DEFAULT NULL,
  `national_id` varchar(50) DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL COMMENT 'تاريخ إنهاء الخدمة',
  `salary_type` enum('monthly','weekly','daily','hourly','piecework') NOT NULL DEFAULT 'monthly' COMMENT 'شهري|أسبوعي|يومي|ساعي|بالقطعة',
  `salary_amount` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT 'الراتب بحسب salary_type',
  `salary_currency` varchar(3) NOT NULL DEFAULT 'USD',
  `payment_day` tinyint(2) DEFAULT 1 COMMENT 'يوم صرف الراتب من الشهر (1-28)',
  `status` enum('active','on_leave','terminated') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الموظفون — خاص بكل فرع';

-- --------------------------------------------------------

--
-- Table structure for table `exchange_rates_alp`
--

CREATE TABLE `exchange_rates_alp` (
  `id` int(11) NOT NULL,
  `currency_from` varchar(3) NOT NULL,
  `currency_to` varchar(3) NOT NULL DEFAULT 'USD',
  `rate` decimal(10,6) NOT NULL,
  `rate_date` date NOT NULL,
  `source` varchar(50) NOT NULL DEFAULT 'manual',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expenses_alp`
--

CREATE TABLE `expenses_alp` (
  `id` int(11) NOT NULL,
  `expense_account_id` int(11) NOT NULL,
  `cash_account_id` int(11) NOT NULL,
  `amount_original` decimal(15,4) NOT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `exchange_rate` decimal(10,6) NOT NULL DEFAULT 1.000000,
  `amount_usd` decimal(15,4) NOT NULL,
  `description` text DEFAULT NULL,
  `expense_date` date NOT NULL,
  `journal_entry_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hr_attendance_alp`
--

CREATE TABLE `hr_attendance_alp` (
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

--
-- Dumping data for table `hr_attendance_alp`
--

INSERT INTO `hr_attendance_alp` (`id`, `employee_id`, `attendance_date`, `check_in`, `check_out`, `hours_worked`, `overtime_hours`, `attendance_status`, `notes`, `created_by`, `created_at`) VALUES
(27, 10, '2026-02-01', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-08 12:52:02'),
(28, 10, '2026-02-02', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-08 12:52:04'),
(29, 10, '2026-02-03', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-08 12:52:05'),
(30, 10, '2026-02-04', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-08 12:52:07'),
(31, 10, '2026-02-05', '08:00:00', '18:00:00', 10.00, 0.00, 'present', NULL, 1, '2026-06-08 12:52:09'),
(32, 10, '2026-02-07', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-08 12:52:14'),
(33, 10, '2026-02-08', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-08 12:52:16'),
(34, 10, '2026-02-09', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-08 12:52:18'),
(35, 10, '2026-02-10', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-08 12:52:19'),
(36, 10, '2026-02-11', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-08 12:52:21'),
(37, 10, '2026-02-12', '08:00:00', '18:00:00', 10.00, 0.00, 'present', NULL, 1, '2026-06-08 12:52:23'),
(38, 10, '2026-02-14', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-08 12:52:26'),
(39, 10, '2026-02-15', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-08 12:52:28'),
(40, 10, '2026-02-16', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-08 12:52:30'),
(41, 10, '2026-02-17', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-08 12:52:31'),
(42, 10, '2026-02-18', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-08 12:52:33'),
(43, 10, '2026-02-19', '08:00:00', '18:00:00', 10.00, 0.00, 'present', NULL, 1, '2026-06-08 12:52:35'),
(44, 10, '2026-02-21', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-08 12:52:39'),
(45, 10, '2026-02-22', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-08 12:52:41'),
(46, 10, '2026-02-23', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-08 12:52:43'),
(47, 10, '2026-02-24', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-08 12:52:45'),
(48, 10, '2026-02-25', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-08 12:52:47'),
(49, 10, '2026-02-26', '08:00:00', '18:00:00', 10.00, 0.00, 'present', NULL, 1, '2026-06-08 12:52:50'),
(50, 10, '2026-02-28', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-08 12:52:57'),
(51, 11, '2026-06-01', '09:00:00', '18:00:00', 9.00, 0.00, 'present', NULL, 1, '2026-06-08 13:02:03'),
(52, 10, '2026-06-01', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-08 13:02:04'),
(53, 11, '2026-06-02', '09:00:00', '18:00:00', 9.00, 0.00, 'present', NULL, 1, '2026-06-08 13:02:12'),
(54, 10, '2026-06-02', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-08 13:02:12'),
(55, 12, '2026-05-04', '08:00:00', '18:00:00', 10.00, 0.00, 'present', NULL, 1, '2026-06-09 09:20:57'),
(56, 12, '2026-05-05', '08:00:00', '18:00:00', 10.00, 0.00, 'present', NULL, 1, '2026-06-09 09:21:02'),
(57, 12, '2026-05-06', '08:00:00', '18:00:00', 10.00, 0.00, 'present', NULL, 1, '2026-06-09 09:21:04'),
(58, 12, '2026-05-07', '08:00:00', '18:00:00', 10.00, 0.00, 'present', NULL, 1, '2026-06-09 09:21:07'),
(59, 11, '2026-05-07', '09:00:00', '18:00:00', 9.00, 0.00, 'present', NULL, 1, '2026-06-09 09:32:32'),
(60, 10, '2026-05-07', '08:00:00', '18:00:00', 10.00, 0.00, 'present', NULL, 1, '2026-06-09 09:32:34'),
(61, 12, '2026-05-09', '08:00:00', '18:00:00', 10.00, 0.00, 'present', NULL, 1, '2026-06-09 09:32:38'),
(62, 11, '2026-05-09', '09:00:00', '18:00:00', 9.00, 0.00, 'present', NULL, 1, '2026-06-09 09:32:39'),
(63, 10, '2026-05-09', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-09 09:32:40'),
(64, 12, '2026-05-10', '08:00:00', '18:00:00', 10.00, 0.00, 'present', NULL, 1, '2026-06-09 09:32:42'),
(65, 11, '2026-05-10', '09:00:00', '18:00:00', 9.00, 0.00, 'present', NULL, 1, '2026-06-09 09:32:42'),
(66, 10, '2026-05-10', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-09 09:32:43'),
(67, 12, '2026-05-11', '08:00:00', '18:00:00', 10.00, 0.00, 'present', NULL, 1, '2026-06-09 09:32:46'),
(68, 11, '2026-05-11', '09:00:00', '18:00:00', 9.00, 0.00, 'present', NULL, 1, '2026-06-09 09:32:47'),
(69, 10, '2026-05-11', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-09 09:32:47'),
(70, 12, '2026-05-12', '08:00:00', '18:00:00', 10.00, 0.00, 'present', NULL, 1, '2026-06-09 09:32:49'),
(71, 11, '2026-05-12', '09:00:00', '18:00:00', 9.00, 0.00, 'present', NULL, 1, '2026-06-09 09:32:50'),
(72, 10, '2026-05-12', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-09 09:32:51'),
(73, 12, '2026-05-13', '08:00:00', '18:00:00', 10.00, 0.00, 'present', NULL, 1, '2026-06-09 09:32:52'),
(74, 11, '2026-05-13', '09:00:00', '18:00:00', 9.00, 0.00, 'present', NULL, 1, '2026-06-09 09:32:53'),
(75, 10, '2026-05-13', '08:00:00', '19:00:00', 11.00, 0.00, 'present', NULL, 1, '2026-06-09 09:32:54'),
(76, 12, '2026-05-14', '08:00:00', '18:00:00', 10.00, 0.00, 'present', NULL, 1, '2026-06-09 09:32:56'),
(77, 11, '2026-05-14', '09:00:00', '18:00:00', 9.00, 0.00, 'present', NULL, 1, '2026-06-09 09:32:57'),
(78, 10, '2026-05-14', '08:00:00', '18:00:00', 10.00, 0.00, 'present', NULL, 1, '2026-06-09 09:32:58'),
(79, 12, '2026-05-23', '08:00:00', '18:00:00', 10.00, 0.00, 'present', NULL, 1, '2026-06-09 09:40:25'),
(80, 12, '2026-05-24', '08:00:00', '18:00:00', 10.00, 0.00, 'present', NULL, 1, '2026-06-09 09:40:27'),
(81, 12, '2026-05-25', '08:00:00', '18:00:00', 10.00, 0.00, 'present', NULL, 1, '2026-06-09 09:40:29'),
(82, 12, '2026-05-26', '08:00:00', '18:00:00', 10.00, 0.00, 'present', NULL, 1, '2026-06-09 09:40:32'),
(83, 12, '2026-05-27', '08:00:00', '18:00:00', 10.00, 0.00, 'present', NULL, 1, '2026-06-09 09:40:34'),
(84, 12, '2026-05-28', '08:00:00', '13:00:00', 5.00, 0.00, 'late', 'خروج مبكر 5س', 1, '2026-06-09 09:40:56'),
(85, 12, '2026-05-30', '08:00:00', '18:00:00', 10.00, 0.00, 'present', NULL, 1, '2026-06-09 09:41:42'),
(86, 12, '2026-05-31', '08:00:00', '18:00:00', 10.00, 0.00, 'present', NULL, 1, '2026-06-09 09:41:44');

-- --------------------------------------------------------

--
-- Table structure for table `hr_bonuses_alp`
--

CREATE TABLE `hr_bonuses_alp` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `bonus_date` date NOT NULL,
  `bonus_type` enum('performance','holiday','commission','transport','housing','other') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hr_employees_alp`
--

CREATE TABLE `hr_employees_alp` (
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
  `bank_account` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `monday_from` tinyint(2) DEFAULT 8 COMMENT 'ساعة بداية الإثنين  — NULL = عطلة',
  `monday_to` tinyint(2) DEFAULT 18,
  `tuesday_from` tinyint(2) DEFAULT 8,
  `tuesday_to` tinyint(2) DEFAULT 18,
  `wednesday_from` tinyint(2) DEFAULT 8,
  `wednesday_to` tinyint(2) DEFAULT 18,
  `thursday_from` tinyint(2) DEFAULT 8,
  `thursday_to` tinyint(2) DEFAULT 18,
  `friday_from` tinyint(2) DEFAULT NULL,
  `friday_to` tinyint(2) DEFAULT NULL,
  `saturday_from` tinyint(2) DEFAULT 8,
  `saturday_to` tinyint(2) DEFAULT 18,
  `sunday_from` tinyint(2) DEFAULT NULL,
  `sunday_to` tinyint(2) DEFAULT NULL,
  `work_schedule` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'جدول الدوام الأسبوعي: {monday:{on:true,from:8,to:18},...}' CHECK (json_valid(`work_schedule`)),
  `overtime_multiplier` decimal(3,1) NOT NULL DEFAULT 1.5 COMMENT 'معامل الأوفرتايم: 1.5 = ساعة ونص، 2.0 = ضعف الساعة',
  `status` enum('active','inactive','on_leave') NOT NULL DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `hr_employees_alp`
--

INSERT INTO `hr_employees_alp` (`id`, `full_name`, `position`, `department`, `phone`, `email`, `hire_date`, `salary_type`, `basic_salary`, `currency_id`, `bank_account`, `notes`, `monday_from`, `monday_to`, `tuesday_from`, `tuesday_to`, `wednesday_from`, `wednesday_to`, `thursday_from`, `thursday_to`, `friday_from`, `friday_to`, `saturday_from`, `saturday_to`, `sunday_from`, `sunday_to`, `work_schedule`, `overtime_multiplier`, `status`, `created_by`, `created_at`, `updated_at`) VALUES
(10, 'منجد', 'مدير التسويق الإلكتروني', 'sales', '', '', '2026-01-02', 'weekly', 1150000.00, 4, '', '', 8, 19, 8, 19, 8, 19, 8, 18, NULL, NULL, 8, 19, 8, 19, NULL, 1.5, 'active', 1, '2026-06-08 10:51:30', NULL),
(11, 'محمود المسلم', 'مبيعات', 'sales', '', '', '2026-03-01', 'monthly', 700.00, 1, '', '', 9, 18, 9, 18, 9, 18, 9, 18, NULL, NULL, 9, 18, 9, 18, NULL, 1.5, 'active', 1, '2026-06-08 12:42:04', NULL),
(12, 'أبو يوسف', 'محاسب', 'accounting', '', '', '2026-05-04', 'weekly', 2000000.00, 4, '', '', 8, 18, 8, 18, 8, 18, 8, 18, NULL, NULL, 8, 18, 8, 18, NULL, 1.5, 'active', 1, '2026-06-09 09:18:50', '2026-06-09 09:21:42');

-- --------------------------------------------------------

--
-- Table structure for table `hr_loans_alp`
--

CREATE TABLE `hr_loans_alp` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `loan_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `installments` int(11) NOT NULL DEFAULT 1 COMMENT 'عدد الأقساط',
  `paid_installments` int(11) NOT NULL DEFAULT 0,
  `monthly_deduction` decimal(12,2) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('active','completed','cancelled') NOT NULL DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hr_payroll_alp`
--

CREATE TABLE `hr_payroll_alp` (
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
  `payment_status` enum('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
  `payment_date` date DEFAULT NULL,
  `payment_method` enum('cash','bank_transfer') DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `hr_payroll_alp`
--

INSERT INTO `hr_payroll_alp` (`id`, `employee_id`, `payroll_month`, `week_number`, `period_from`, `period_to`, `basic_salary`, `working_days`, `working_hours`, `overtime_hours`, `overtime_amount`, `bonus_total`, `loan_deduction`, `other_deductions`, `net_salary`, `currency_id`, `payment_status`, `payment_date`, `payment_method`, `notes`, `created_by`, `created_at`) VALUES
(28, 12, '2026-05-01', 1, '2026-05-04', '2026-05-07', 2000000.00, 4, 40.00, 0.00, 0.00, 0.00, 0.00, 0.00, 1333333.33, 4, 'paid', '2026-06-10', 'cash', '', 1, '2026-06-10 13:05:01'),
(29, 12, '2026-05-01', 2, '2026-05-09', '2026-05-14', 2000000.00, 6, 60.00, 0.00, 0.00, 0.00, 0.00, 0.00, 2000000.00, 4, 'paid', '2026-06-10', 'cash', '', 1, '2026-06-10 13:05:10'),
(32, 12, '2026-05-01', 3, '2026-05-16', '2026-05-21', 2000000.00, 0, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 4, 'paid', '2026-06-10', 'cash', '', 1, '2026-06-10 13:05:37'),
(33, 12, '2026-05-01', 4, '2026-05-23', '2026-05-28', 2000000.00, 6, 55.00, 0.00, 0.00, 0.00, 0.00, 0.00, 1833333.33, 4, 'paid', '2026-06-10', 'cash', '', 1, '2026-06-10 13:05:45'),
(34, 11, '2026-03-01', 0, '2026-03-01', '2026-03-31', 700.00, 0, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 1, 'paid', '2026-06-10', 'cash', '', 1, '2026-06-10 13:05:56'),
(35, 11, '2026-04-01', 0, '2026-04-01', '2026-04-30', 700.00, 0, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 1, 'paid', '2026-06-10', 'cash', '', 1, '2026-06-10 13:06:03'),
(36, 11, '2026-05-01', 0, '2026-05-01', '2026-05-31', 700.00, 7, 63.00, 0.00, 0.00, 0.00, 0.00, 0.00, 204.17, 1, 'paid', '2026-06-10', 'cash', '', 1, '2026-06-10 13:06:10'),
(37, 11, '2026-06-01', 0, '2026-06-01', '2026-06-30', 700.00, 0, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 145.83, 1, 'paid', '2026-06-10', 'cash', '', 1, '2026-06-10 13:06:19');

-- --------------------------------------------------------

--
-- Table structure for table `hr_promotions_alp`
--

CREATE TABLE `hr_promotions_alp` (
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
  `unit_price_usd` decimal(10,4) DEFAULT NULL,
  `total_price` decimal(12,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('pending','approved','partially_approved','rejected','unavailable') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بنود الطلبات الداخلية';

-- --------------------------------------------------------

--
-- Table structure for table `inventory_movements_alp`
--

CREATE TABLE `inventory_movements_alp` (
  `id` int(11) NOT NULL,
  `movement_number` varchar(50) NOT NULL,
  `movement_type` enum('in','out','adjustment','transfer') NOT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `items_count` int(11) NOT NULL DEFAULT 0,
  `total_quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_value_usd` decimal(12,2) DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_movement_details_alp`
--

CREATE TABLE `inventory_movement_details_alp` (
  `id` int(11) NOT NULL,
  `movement_id` int(11) NOT NULL,
  `variant_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,4) DEFAULT NULL,
  `cost_price` decimal(10,4) NOT NULL DEFAULT 0.0000 COMMENT 'سعر التكلفة بالدولار USD',
  `total_value` decimal(12,2) DEFAULT NULL,
  `balance_before` decimal(10,2) DEFAULT NULL,
  `balance_after` decimal(10,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoice_account_settings_alp`
--

CREATE TABLE `invoice_account_settings_alp` (
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
-- Table structure for table `journal_entries_alp`
--

CREATE TABLE `journal_entries_alp` (
  `id` int(11) NOT NULL,
  `entry_number` varchar(50) NOT NULL,
  `entry_date` date NOT NULL,
  `description` text DEFAULT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
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
-- Table structure for table `journal_entry_items_alp`
--

CREATE TABLE `journal_entry_items_alp` (
  `id` int(11) NOT NULL,
  `journal_entry_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `debit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `original_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `base_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أقسام النظام وتدرجها';

--
-- Dumping data for table `modules`
--

INSERT INTO `modules` (`id`, `key`, `parent_key`, `label`, `icon`, `sort_order`, `is_active`) VALUES
(1, 'sales', NULL, 'المبيعات', 'bi-bag', 10, 1),
(2, 'sales.invoices', 'sales', 'فواتير البيع', 'bi-receipt', 11, 1),
(3, 'sales.invoices.new', 'sales', 'فاتورة جديدة', 'bi-plus-circle', 12, 1),
(4, 'sales.returns', 'sales', 'مردودات البيع', 'bi-arrow-return-right', 13, 1),
(5, 'purchases', NULL, 'المشتريات', 'bi-cart3', 20, 1),
(6, 'purchases.invoices', 'purchases', 'فواتير الشراء', 'bi-file-earmark', 21, 1),
(7, 'purchases.returns', 'purchases', 'مردودات الشراء', 'bi-arrow-return-left', 22, 1),
(8, 'inventory', NULL, 'المخزون', 'bi-box-seam', 30, 1),
(9, 'inventory.products', 'inventory', 'المنتجات', 'bi-tags', 31, 1),
(10, 'inventory.warehouse', 'inventory', 'المستودع', 'bi-building', 32, 1),
(11, 'inventory.movements', 'inventory', 'حركات المخزون', 'bi-arrow-left-right', 33, 1),
(12, 'finance', NULL, 'المالية', 'bi-bank', 40, 1),
(13, 'finance.accounts', 'finance', 'شجرة الحسابات', 'bi-diagram-3', 41, 1),
(14, 'finance.journal', 'finance', 'القيود المحاسبية', 'bi-journal-bookmark', 42, 1),
(15, 'finance.receipts', 'finance', 'سندات القبض', 'bi-cash-stack', 43, 1),
(16, 'finance.expenses', 'finance', 'المصاريف', 'bi-wallet2', 44, 1),
(17, 'finance.reports', 'finance', 'التقارير المالية', 'bi-bar-chart-line', 45, 1),
(18, 'crm', NULL, 'العملاء والموردون', 'bi-people', 50, 1),
(19, 'crm.customers', 'crm', 'العملاء', 'bi-person-lines-fill', 51, 1),
(20, 'crm.customers.statement', 'crm', 'كشف حساب العميل', 'bi-file-text', 52, 1),
(21, 'crm.suppliers', 'crm', 'الموردون', 'bi-truck', 53, 1),
(22, 'crm.suppliers.statement', 'crm', 'كشف حساب المورد', 'bi-file-text', 54, 1),
(23, 'admin', NULL, 'الإدارة', 'bi-shield-check', 90, 1),
(24, 'admin.users', 'admin', 'المستخدمون', 'bi-person-gear', 91, 1),
(25, 'admin.permissions', 'admin', 'الصلاحيات', 'bi-key', 92, 1),
(26, 'admin.settings', 'admin', 'الإعدادات', 'bi-gear', 93, 1),
(27, 'admin.branches', 'admin', 'الفروع', 'bi-building-check', 94, 1),
(30, 'hr', NULL, 'الموارد البشرية', 'bi-people', 55, 1),
(31, 'hr.employees', 'hr', 'الموظفون', 'bi-person-badge', 56, 1),
(32, 'hr.attendance', 'hr', 'الحضور والانصراف', 'bi-calendar-check', 57, 1),
(33, 'hr.payroll', 'hr', 'الرواتب والأجور', 'bi-cash-stack', 58, 1),
(34, 'hr.reports', 'hr', 'تقارير الموارد البشرية', 'bi-bar-chart', 59, 1),
(35, 'expenses', NULL, 'المصاريف والمستهلكات', 'bi-wallet2', 60, 1),
(36, 'expenses.consumables', 'expenses', 'المستهلكات', 'bi-cup-hot', 61, 1),
(37, 'expenses.consumable_entries', 'expenses', 'سجل الاستهلاك', 'bi-journal-text', 62, 1),
(38, 'production', NULL, 'الإنتاج', 'bi-gear-wide-connected', 80, 1),
(39, 'production.raw_materials', 'production', 'المواد الأولية', 'bi-boxes', 81, 1),
(40, 'production.operations', 'production', 'عمليات الإنتاج', 'bi-tools', 82, 1),
(41, 'production.entries', 'production', 'سجل الإنتاج', 'bi-clipboard-data', 83, 1),
(53, 'hr.holidays', 'hr', 'العطل الرسمية', 'bi-calendar-x', 74, 1),
(54, 'inventory.internal_orders', 'inventory', 'الطلبات الداخلية', 'bi-arrow-left-right', 31, 1);

-- --------------------------------------------------------

--
-- Table structure for table `notifications_alp`
--

CREATE TABLE `notifications_alp` (
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
-- Table structure for table `payroll_alp`
--

CREATE TABLE `payroll_alp` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `period_start` date NOT NULL COMMENT 'بداية فترة الراتب',
  `period_end` date NOT NULL COMMENT 'نهاية فترة الراتب',
  `working_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `absent_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `overtime_hours` decimal(6,2) NOT NULL DEFAULT 0.00,
  `base_salary` decimal(12,4) NOT NULL COMMENT 'الراتب الأساسي',
  `overtime_amount` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `deductions` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `bonuses` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `net_salary` decimal(12,4) NOT NULL COMMENT 'الصافي = base + overtime + bonuses - deductions',
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `exchange_rate` decimal(10,6) NOT NULL DEFAULT 1.000000,
  `net_salary_usd` decimal(12,4) NOT NULL,
  `status` enum('draft','approved','paid') NOT NULL DEFAULT 'draft',
  `paid_at` datetime DEFAULT NULL,
  `payment_method` enum('cash','bank','other') DEFAULT 'cash',
  `cash_account_id` int(11) DEFAULT NULL,
  `journal_entry_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `production_entries_alp`
--

CREATE TABLE `production_entries_alp` (
  `id` int(11) NOT NULL,
  `operation_id` int(11) NOT NULL,
  `entry_date` date NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `price_per_unit` decimal(10,4) NOT NULL,
  `total_usd` decimal(12,4) NOT NULL,
  `product_id` int(11) DEFAULT NULL COMMENT 'الموديل المرتبط',
  `worker_id` int(11) DEFAULT NULL COMMENT 'العامل',
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `production_operations_alp`
--

CREATE TABLE `production_operations_alp` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL COMMENT 'خياطة، تطريز، كحت',
  `unit` varchar(30) NOT NULL DEFAULT 'piece' COMMENT 'قطعة، دزينة، كغ',
  `default_price_usd` decimal(10,4) DEFAULT NULL COMMENT 'السعر الافتراضي للوحدة بالدولار',
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products_alp`
--

CREATE TABLE `products_alp` (
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

--
-- Dumping data for table `products_alp`
--

INSERT INTO `products_alp` (`id`, `model_number`, `name`, `category_id`, `fabric_type`, `supplier_id`, `image_path`, `is_active`, `notes`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(48, '5024', 'بنطلون بوي فريند تطيرز عالجيب الخلفي اليمين', 7, 'سكالا', 1, NULL, 1, 'new', 1, 1, '2026-06-15 09:32:56', '2026-06-15 10:47:48');

-- --------------------------------------------------------

--
-- Table structure for table `product_categories_alp`
--

CREATE TABLE `product_categories_alp` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `product_categories_alp`
--

INSERT INTO `product_categories_alp` (`id`, `name`, `parent_id`, `description`, `is_active`, `created_at`) VALUES
(5, 'بنطلون صبياني', 1, 'بنطلون صبياني', 1, '2026-06-11 11:44:44'),
(7, 'بنطلون بناتي', 2, 'بنطلون بناتي', 1, '2026-06-11 11:44:44'),
(8, 'طقم بناتي', 2, 'طقم قطعتين / طقم ثلاث قطع / توينز', 1, '2026-06-11 11:44:44');

-- --------------------------------------------------------

--
-- Table structure for table `product_colors_alp`
--

CREATE TABLE `product_colors_alp` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `hex_code` varchar(10) NOT NULL DEFAULT '#000000',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `product_colors_alp`
--

INSERT INTO `product_colors_alp` (`id`, `name`, `hex_code`, `is_active`, `created_at`) VALUES
(1, 'أسود', '#1a1a2e', 1, '2026-06-14 08:59:05'),
(2, 'أبيض', '#f5f0e8', 1, '2026-06-14 08:59:05'),
(3, 'رمادي', '#6b7280', 1, '2026-06-14 08:59:05'),
(4, 'أزرق', '#3b82f6', 1, '2026-06-14 08:59:05'),
(5, 'أحمر', '#ef4444', 1, '2026-06-14 08:59:05'),
(6, 'أخضر', '#22c55e', 1, '2026-06-14 08:59:05'),
(7, 'أصفر', '#f59e0b', 1, '2026-06-14 08:59:05'),
(8, 'بني', '#92400e', 1, '2026-06-14 08:59:05'),
(9, 'بيج فاتح', '#d4b896', 1, '2026-06-14 08:59:05'),
(10, 'بنفسجي', '#8b5cf6', 1, '2026-06-14 08:59:05'),
(11, 'زهري', '#ec4899', 1, '2026-06-14 08:59:05'),
(12, 'تركواز', '#0e7490', 1, '2026-06-14 08:59:05'),
(13, 'أزرق فاتح', '#93c5fd', 1, '2026-06-14 08:59:05'),
(14, 'أزرق داكن', '#1e3a8a', 1, '2026-06-14 08:59:05'),
(15, 'رمادي داكن', '#374151', 1, '2026-06-14 08:59:05'),
(16, 'بيج داكن', '#a16207', 1, '2026-06-14 08:59:05'),
(17, 'خاكي', '#65a30d', 1, '2026-06-14 08:59:05'),
(18, 'بودري', '#f9a8d4', 1, '2026-06-14 08:59:05'),
(19, 'دخاني', '#9ca3af', 1, '2026-06-14 08:59:05'),
(20, 'زيتي', '#4d7c0f', 1, '2026-06-14 08:59:05');

-- --------------------------------------------------------

--
-- Table structure for table `product_sizes_alp`
--

CREATE TABLE `product_sizes_alp` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `size` varchar(20) NOT NULL COMMENT 'القياس: 6,8,10,S,M,XL...',
  `age_type` varchar(10) NOT NULL DEFAULT 'سنة',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `selling_price` decimal(10,4) NOT NULL DEFAULT 0.0000 COMMENT 'سعر البيع بالدولار USD — مشترك بين كل الألوان',
  `cost_price` decimal(10,2) DEFAULT NULL,
  `margin_pct` decimal(5,2) DEFAULT NULL,
  `packet_qty` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قياسات كل موديل — سعر مستقل لكل قياس';

--
-- Dumping data for table `product_sizes_alp`
--

INSERT INTO `product_sizes_alp` (`id`, `product_id`, `size`, `age_type`, `sort_order`, `selling_price`, `cost_price`, `margin_pct`, `packet_qty`, `is_active`, `created_at`, `updated_at`) VALUES
(600, 48, '6', 'شهر', 0, 3.9000, 3.00, 30.00, 4, 1, '2026-06-15 10:47:48', NULL),
(601, 48, '12', 'شهر', 1, 3.9000, 3.00, 30.00, 4, 1, '2026-06-15 10:47:48', NULL),
(602, 48, '18', 'شهر', 2, 3.9000, 3.00, 30.00, 4, 1, '2026-06-15 10:47:48', NULL),
(603, 48, '24', 'شهر', 3, 3.9000, 3.00, 30.00, 4, 1, '2026-06-15 10:47:48', NULL),
(604, 48, '2', 'سنة', 4, 5.2000, 4.00, 30.00, 4, 1, '2026-06-15 10:47:48', NULL),
(605, 48, '3', 'سنة', 5, 5.2000, 4.00, 30.00, 4, 1, '2026-06-15 10:47:48', NULL),
(606, 48, '4', 'سنة', 6, 5.2000, 4.00, 30.00, 4, 1, '2026-06-15 10:47:48', NULL),
(607, 48, '5', 'سنة', 7, 5.2000, 4.00, 30.00, 4, 1, '2026-06-15 10:47:48', NULL),
(608, 48, '6', 'سنة', 8, 7.8000, 6.00, 30.00, 3, 1, '2026-06-15 10:47:48', NULL),
(609, 48, '8', 'سنة', 9, 7.8000, 6.00, 30.00, 3, 1, '2026-06-15 10:47:48', NULL),
(610, 48, '10', 'سنة', 10, 7.8000, 6.00, 30.00, 3, 1, '2026-06-15 10:47:48', NULL),
(611, 48, '12', 'سنة', 11, 9.1000, 7.00, 30.00, 7, 1, '2026-06-15 10:47:48', NULL),
(612, 48, '13', 'سنة', 12, 9.1000, 7.00, 30.00, 7, 1, '2026-06-15 10:47:48', NULL),
(613, 48, '14', 'سنة', 13, 9.1000, 7.00, 30.00, 7, 1, '2026-06-15 10:47:48', NULL),
(614, 48, '15', 'سنة', 14, 9.1000, 7.00, 30.00, 7, 1, '2026-06-15 10:47:48', NULL),
(615, 48, '16', 'سنة', 15, 9.1000, 7.00, 30.00, 7, 1, '2026-06-15 10:47:48', NULL),
(616, 48, '17', 'سنة', 16, 9.1000, 7.00, 30.00, 7, 1, '2026-06-15 10:47:48', NULL),
(617, 48, '18', 'سنة', 17, 9.1000, 7.00, 30.00, 7, 1, '2026-06-15 10:47:48', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `product_suppliers_alp`
--

CREATE TABLE `product_suppliers_alp` (
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

--
-- Dumping data for table `product_suppliers_alp`
--

INSERT INTO `product_suppliers_alp` (`id`, `account_id`, `prepaid_account_id`, `name`, `contact_person`, `phone`, `email`, `address`, `tax_number`, `type`, `supplier_type`, `status`, `credit_limit`, `discount_percentage`, `notes`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, NULL, NULL, 'بيهس', 'أبو يوسف', '', NULL, NULL, NULL, 'wholesaler', 'product', 'active', 0.00, 0.00, 'يرءؤر', 1, NULL, '2026-06-14 10:06:41', NULL),
(2, NULL, NULL, 'شركة القلم لبيع المواد اللاصقة', 'محمد القلم', '', NULL, NULL, NULL, 'retailer', 'consumable', 'active', 0.00, 0.00, '', 1, NULL, '2026-06-16 05:23:37', '2026-06-16 05:23:54'),
(3, NULL, NULL, 'شركة البيك للمستلزمات الماكينات', 'محمود بيك', '', NULL, NULL, NULL, 'retailer', 'consumable', 'active', 0.00, 0.00, '', 1, NULL, '2026-06-16 05:26:36', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `product_variants_alp`
--

CREATE TABLE `product_variants_alp` (
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

--
-- Dumping data for table `product_variants_alp`
--

INSERT INTO `product_variants_alp` (`id`, `product_id`, `size_id`, `color_id`, `barcode`, `is_active`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(1124, 48, 600, 4, '5024-G03-C01-S600', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1125, 48, 601, 4, '5024-G04-C01-S601', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1126, 48, 602, 4, '5024-G04-C01-S602', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1127, 48, 603, 4, '5024-G01-C01-S603', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1128, 48, 604, 4, '5024-G02-C01-S604', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1129, 48, 605, 4, '5024-G02-C01-S605', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1130, 48, 606, 4, '5024-G02-C01-S606', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1131, 48, 607, 4, '5024-G02-C01-S607', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1132, 48, 608, 4, '5024-G03-C01-S608', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1133, 48, 609, 4, '5024-G03-C01-S609', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1134, 48, 610, 4, '5024-G03-C01-S610', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1135, 48, 611, 4, '5024-G04-C01-S611', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1136, 48, 612, 4, '5024-G04-C01-S612', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1137, 48, 613, 4, '5024-G04-C01-S613', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1138, 48, 614, 4, '5024-G04-C01-S614', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1139, 48, 615, 4, '5024-G04-C01-S615', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1140, 48, 616, 4, '5024-G04-C01-S616', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1141, 48, 617, 4, '5024-G04-C01-S617', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1142, 48, 600, 14, '5024-G03-C02-S600', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1143, 48, 601, 14, '5024-G04-C02-S601', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1144, 48, 602, 14, '5024-G04-C02-S602', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1145, 48, 603, 14, '5024-G01-C02-S603', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1146, 48, 604, 14, '5024-G02-C02-S604', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1147, 48, 605, 14, '5024-G02-C02-S605', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1148, 48, 606, 14, '5024-G02-C02-S606', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1149, 48, 607, 14, '5024-G02-C02-S607', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1150, 48, 608, 14, '5024-G03-C02-S608', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1151, 48, 609, 14, '5024-G03-C02-S609', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1152, 48, 610, 14, '5024-G03-C02-S610', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1153, 48, 611, 14, '5024-G04-C02-S611', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1154, 48, 612, 14, '5024-G04-C02-S612', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1155, 48, 613, 14, '5024-G04-C02-S613', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1156, 48, 614, 14, '5024-G04-C02-S614', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1157, 48, 615, 14, '5024-G04-C02-S615', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1158, 48, 616, 14, '5024-G04-C02-S616', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1159, 48, 617, 14, '5024-G04-C02-S617', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1160, 48, 600, 13, '5024-G03-C03-S600', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1161, 48, 601, 13, '5024-G04-C03-S601', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1162, 48, 602, 13, '5024-G04-C03-S602', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1163, 48, 603, 13, '5024-G01-C03-S603', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1164, 48, 604, 13, '5024-G02-C03-S604', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1165, 48, 605, 13, '5024-G02-C03-S605', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1166, 48, 606, 13, '5024-G02-C03-S606', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1167, 48, 607, 13, '5024-G02-C03-S607', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1168, 48, 608, 13, '5024-G03-C03-S608', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1169, 48, 609, 13, '5024-G03-C03-S609', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1170, 48, 610, 13, '5024-G03-C03-S610', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1171, 48, 611, 13, '5024-G04-C03-S611', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1172, 48, 612, 13, '5024-G04-C03-S612', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1173, 48, 613, 13, '5024-G04-C03-S613', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1174, 48, 614, 13, '5024-G04-C03-S614', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1175, 48, 615, 13, '5024-G04-C03-S615', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1176, 48, 616, 13, '5024-G04-C03-S616', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1177, 48, 617, 13, '5024-G04-C03-S617', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1178, 48, 600, 1, '5024-G03-C04-S600', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1179, 48, 601, 1, '5024-G04-C04-S601', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1180, 48, 602, 1, '5024-G04-C04-S602', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1181, 48, 603, 1, '5024-G01-C04-S603', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1182, 48, 604, 1, '5024-G02-C04-S604', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1183, 48, 605, 1, '5024-G02-C04-S605', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1184, 48, 606, 1, '5024-G02-C04-S606', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1185, 48, 607, 1, '5024-G02-C04-S607', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1186, 48, 608, 1, '5024-G03-C04-S608', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1187, 48, 609, 1, '5024-G03-C04-S609', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1188, 48, 610, 1, '5024-G03-C04-S610', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1189, 48, 611, 1, '5024-G04-C04-S611', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1190, 48, 612, 1, '5024-G04-C04-S612', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1191, 48, 613, 1, '5024-G04-C04-S613', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1192, 48, 614, 1, '5024-G04-C04-S614', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1193, 48, 615, 1, '5024-G04-C04-S615', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1194, 48, 616, 1, '5024-G04-C04-S616', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1195, 48, 617, 1, '5024-G04-C04-S617', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1196, 48, 600, 10, '5024-G03-C05-S600', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1197, 48, 601, 10, '5024-G04-C05-S601', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1198, 48, 602, 10, '5024-G04-C05-S602', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1199, 48, 603, 10, '5024-G01-C05-S603', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1200, 48, 604, 10, '5024-G02-C05-S604', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1201, 48, 605, 10, '5024-G02-C05-S605', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1202, 48, 606, 10, '5024-G02-C05-S606', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1203, 48, 607, 10, '5024-G02-C05-S607', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1204, 48, 608, 10, '5024-G03-C05-S608', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1205, 48, 609, 10, '5024-G03-C05-S609', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1206, 48, 610, 10, '5024-G03-C05-S610', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1207, 48, 611, 10, '5024-G04-C05-S611', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1208, 48, 612, 10, '5024-G04-C05-S612', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1209, 48, 613, 10, '5024-G04-C05-S613', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1210, 48, 614, 10, '5024-G04-C05-S614', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1211, 48, 615, 10, '5024-G04-C05-S615', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1212, 48, 616, 10, '5024-G04-C05-S616', 1, 1, NULL, '2026-06-15 10:47:48', NULL),
(1213, 48, 617, 10, '5024-G04-C05-S617', 1, 1, NULL, '2026-06-15 10:47:48', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `public_holidays_alp`
--

CREATE TABLE `public_holidays_alp` (
  `id` int(11) NOT NULL,
  `holiday_date` date NOT NULL,
  `name` varchar(150) NOT NULL COMMENT 'عيد الفطر، عيد الميلاد...',
  `description` text DEFAULT NULL,
  `is_recurring` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = تتكرر كل سنة (نفس الشهر واليوم)',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='العطل الرسمية — فرع حلب';

--
-- Dumping data for table `public_holidays_alp`
--

INSERT INTO `public_holidays_alp` (`id`, `holiday_date`, `name`, `description`, `is_recurring`, `created_by`, `created_at`, `updated_at`) VALUES
(1, '2026-06-01', 'عيد الأضحى المبارك', 'عيد الأضحى المبارك', 0, 1, '2026-06-08 13:01:36', NULL),
(2, '2026-06-02', 'عيد الأضحى المبارك', 'عيد الأضحى المبارك', 0, 1, '2026-06-08 13:01:42', NULL),
(3, '2026-06-03', 'عيد الأضحى المبارك', 'عيد الأضحى المبارك', 0, 1, '2026-06-08 13:01:47', NULL),
(4, '2026-06-04', 'عيد الأضحى المبارك', 'عيد الأضحى المبارك', 0, 1, '2026-06-08 13:01:52', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `purchases_alp`
--

CREATE TABLE `purchases_alp` (
  `id` int(11) NOT NULL,
  `purchase_number` varchar(50) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `purchase_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'بعملة الفاتورة',
  `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `final_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `final_amount_usd` decimal(15,4) DEFAULT NULL COMMENT 'الإجمالي بالدولار USD',
  `currency` varchar(3) NOT NULL DEFAULT 'TRY',
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
-- Table structure for table `purchase_items_alp`
--

CREATE TABLE `purchase_items_alp` (
  `id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `model_number` varchar(50) DEFAULT NULL,
  `size` varchar(20) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,4) NOT NULL COMMENT 'بعملة فاتورة الشراء',
  `unit_price_usd` decimal(10,4) DEFAULT NULL COMMENT 'بالدولار = unit_price / exchange_rate',
  `total_price` decimal(12,2) NOT NULL,
  `tax_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `warehouse_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_returns_alp`
--

CREATE TABLE `purchase_returns_alp` (
  `id` int(11) NOT NULL,
  `return_number` varchar(50) DEFAULT NULL,
  `purchase_id` int(11) NOT NULL,
  `purchase_number` varchar(50) DEFAULT NULL,
  `supplier_id` int(11) NOT NULL,
  `supplier_name` varchar(255) DEFAULT NULL,
  `return_date` date NOT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `return_amount` decimal(15,2) DEFAULT NULL,
  `discount_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `payment_handling` enum('not_paid','partial','paid_refund_cash','paid_credit_supplier') NOT NULL DEFAULT 'not_paid',
  `journal_entry_id` int(11) DEFAULT NULL,
  `status` enum('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_return_items_alp`
--

CREATE TABLE `purchase_return_items_alp` (
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
-- Table structure for table `raw_materials_alp`
--

CREATE TABLE `raw_materials_alp` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `unit` varchar(30) NOT NULL DEFAULT 'kg' COMMENT 'كغ، متر، قطعة، لفّة',
  `category` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `raw_material_stock_alp`
--

CREATE TABLE `raw_material_stock_alp` (
  `id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL DEFAULT 1,
  `quantity` decimal(12,3) NOT NULL DEFAULT 0.000,
  `avg_cost_usd` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `last_movement_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `receipts_alp`
--

CREATE TABLE `receipts_alp` (
  `id` int(11) NOT NULL,
  `receipt_number` varchar(50) NOT NULL,
  `receipt_date` date NOT NULL,
  `customer_id` int(11) NOT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `amount` decimal(15,4) NOT NULL COMMENT 'المبلغ بعملة القبض',
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `amount_usd` decimal(15,4) NOT NULL COMMENT 'المبلغ بالدولار',
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
-- Table structure for table `receipt_invoices_alp`
--

CREATE TABLE `receipt_invoices_alp` (
  `id` int(11) NOT NULL,
  `receipt_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `allocated_amount` decimal(15,4) NOT NULL COMMENT 'المبلغ المُوزَّع USD',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='توزيع سند القبض على فواتير متعددة';

-- --------------------------------------------------------

--
-- Table structure for table `sales_invoices_alp`
--

CREATE TABLE `sales_invoices_alp` (
  `id` int(11) NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'USD',
  `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `customer_id` int(11) NOT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `customer_type` enum('internal','external') NOT NULL DEFAULT 'external',
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_percentage` decimal(5,5) NOT NULL DEFAULT 0.00000,
  `discount_amount` decimal(12,5) NOT NULL DEFAULT 0.00000,
  `kdv_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `kdv_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `customs_fees` decimal(10,2) NOT NULL DEFAULT 0.00,
  `stamp_duty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cost_total` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'إجمالي تكلفة البضاعة USD',
  `shipping_carrier_id` int(11) DEFAULT NULL,
  `shipping_cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'أجرة النقلية TRY',
  `shipping_payment_method` enum('cash','card','bank') NOT NULL DEFAULT 'cash',
  `number_of_packages` int(11) NOT NULL DEFAULT 0,
  `shipping_fees` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'أجور الشحن على العميل',
  `payment_method` enum('cash','card','bank','half_cash_half_bank','deferred','check','credit') NOT NULL DEFAULT 'cash',
  `payment_status` enum('pending','partial','paid') NOT NULL DEFAULT 'pending',
  `is_official_invoice` decimal(3,1) NOT NULL DEFAULT 0.0 COMMENT '0=بدون | 0.5=نصف | 1=كاملة',
  `is_export_invoice` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('draft','pending','confirmed','partially_paid','paid','cancelled') NOT NULL DEFAULT 'draft',
  `confirmed_at` datetime DEFAULT NULL,
  `confirmed_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='فواتير البيع — جميع المبالغ بالدولار USD';

-- --------------------------------------------------------

--
-- Table structure for table `sales_invoice_items_alp`
--

CREATE TABLE `sales_invoice_items_alp` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `item_name` varchar(255) NOT NULL,
  `model_number` varchar(50) DEFAULT NULL,
  `size` varchar(20) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,4) NOT NULL COMMENT 'سعر البيع USD',
  `cost_price_usd` decimal(10,4) DEFAULT NULL COMMENT 'تكلفة الوحدة وقت البيع USD',
  `total_price` decimal(12,2) NOT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_returns_alp`
--

CREATE TABLE `sales_returns_alp` (
  `id` int(11) NOT NULL,
  `return_number` varchar(50) DEFAULT NULL,
  `invoice_id` int(11) NOT NULL,
  `invoice_number` varchar(50) DEFAULT NULL,
  `customer_id` int(11) NOT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `return_date` date NOT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `return_amount` decimal(15,2) DEFAULT NULL,
  `discount_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `journal_entry_id` int(11) DEFAULT NULL,
  `status` enum('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_return_items_alp`
--

CREATE TABLE `sales_return_items_alp` (
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
-- Table structure for table `shipping_carriers_alp`
--

CREATE TABLE `shipping_carriers_alp` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `mobile` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `tax_number` varchar(50) DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  `payable_account_id` int(11) DEFAULT NULL COMMENT 'حساب ذمة النقلية',
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
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

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `full_name`, `email`, `password`, `role`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES
(1, 'admin', 'مدير النظام-مهندس منجد', 'admin@fatorize.com', '$2y$10$.5AOpMJu2MAYJlz3RTQnVONC.nMgIKfyN51QtmGWbD3cSR.DwCw12', 'admin', 1, NULL, '2026-05-20 11:57:15', '2026-05-20 12:12:27'),
(2, 'Mahmoud muslem', 'محمود المسلم', 'hostingersite.droplet062@passinbox.com', '$2y$10$BT9bfTt9IghvEVIwL2wlj.tXXvJseaANsDPsQz9piXct5rd17Mnkq', 'sales', 1, NULL, '2026-05-21 05:44:18', '2026-05-21 05:46:44');

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

--
-- Dumping data for table `user_branches`
--

INSERT INTO `user_branches` (`user_id`, `branch_id`) VALUES
(1, 1),
(2, 1),
(1, 2),
(1, 3),
(1, 4),
(1, 5);

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

--
-- Dumping data for table `user_permissions`
--

INSERT INTO `user_permissions` (`id`, `user_id`, `branch_id`, `module_key`, `can_view`, `can_create`, `can_edit`, `can_delete`, `can_confirm`, `can_print`, `can_export`, `granted_by`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'admin', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(2, 1, 1, 'admin.branches', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(3, 1, 1, 'admin.permissions', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(4, 1, 1, 'admin.settings', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(5, 1, 1, 'admin.users', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(6, 1, 1, 'crm', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(7, 1, 1, 'crm.customers', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(8, 1, 1, 'crm.customers.statement', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(9, 1, 1, 'crm.suppliers', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(10, 1, 1, 'crm.suppliers.statement', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(11, 1, 1, 'finance', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(12, 1, 1, 'finance.accounts', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(13, 1, 1, 'finance.expenses', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(14, 1, 1, 'finance.journal', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(15, 1, 1, 'finance.receipts', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(16, 1, 1, 'finance.reports', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(17, 1, 1, 'inventory', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(18, 1, 1, 'inventory.movements', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(19, 1, 1, 'inventory.products', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(20, 1, 1, 'inventory.warehouse', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(21, 1, 1, 'purchases', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(22, 1, 1, 'purchases.invoices', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(23, 1, 1, 'purchases.returns', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(24, 1, 1, 'sales', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(25, 1, 1, 'sales.invoices', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(26, 1, 1, 'sales.invoices.new', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(27, 1, 1, 'sales.returns', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(32, 1, 1, 'hr', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-21 10:53:38', NULL),
(33, 1, 1, 'hr.employees', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-21 10:53:38', NULL),
(34, 1, 1, 'hr.attendance', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-21 10:53:38', NULL),
(35, 1, 1, 'hr.payroll', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-21 10:53:38', NULL),
(36, 1, 1, 'hr.reports', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-21 10:53:38', NULL),
(37, 1, 1, 'expenses', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-21 10:53:38', NULL),
(38, 1, 1, 'expenses.consumables', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-21 10:53:38', NULL),
(39, 1, 1, 'expenses.consumable_entries', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-21 10:53:38', NULL),
(40, 1, 1, 'hr.holidays', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-08 13:00:47', NULL),
(41, 1, 2, 'hr.holidays', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-08 13:00:47', NULL),
(42, 1, 3, 'hr.holidays', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-08 13:00:47', NULL),
(43, 1, 4, 'hr.holidays', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-08 13:00:47', NULL),
(44, 1, 5, 'hr.holidays', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-08 13:00:47', NULL),
(47, 1, 1, 'inventory.internal_orders', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-11 05:52:48', NULL),
(48, 1, 2, 'inventory.internal_orders', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-11 05:52:48', NULL),
(49, 1, 3, 'inventory.internal_orders', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-11 05:52:48', NULL),
(50, 1, 4, 'inventory.internal_orders', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-11 05:52:48', NULL),
(51, 1, 5, 'inventory.internal_orders', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-11 05:52:48', NULL),
(54, 1, 2, 'inventory.products', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-11 11:44:44', NULL),
(55, 1, 3, 'inventory.products', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-11 11:44:44', NULL),
(56, 1, 4, 'inventory.products', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-11 11:44:44', NULL),
(57, 1, 5, 'inventory.products', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-11 11:44:44', NULL),
(58, 1, 2, 'inventory.warehouse', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-11 11:44:44', NULL),
(59, 1, 3, 'inventory.warehouse', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-11 11:44:44', NULL),
(60, 1, 4, 'inventory.warehouse', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-11 11:44:44', NULL),
(61, 1, 5, 'inventory.warehouse', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-11 11:44:44', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `warehouses_alp`
--

CREATE TABLE `warehouses_alp` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `address` text DEFAULT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `warehouses_alp`
--

INSERT INTO `warehouses_alp` (`id`, `code`, `name`, `address`, `manager_id`, `is_active`, `created_at`) VALUES
(1, 'WH-ALP-01', 'المستودع الرئيسي — حلب', NULL, NULL, 1, '2026-05-20 11:51:25'),
(2, 'WH-CONS', 'مستودع المواد الاستهلاكية', NULL, NULL, 1, '2026-06-11 11:44:44');

-- --------------------------------------------------------

--
-- Table structure for table `warehouse_items_alp`
--

CREATE TABLE `warehouse_items_alp` (
  `id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL,
  `variant_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL COMMENT 'الموديل الأب — للفلترة السريعة',
  `quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `min_quantity` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'حد التنبيه',
  `last_movement_at` datetime DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مخزون كل Variant في كل مستودع';

--
-- Dumping data for table `warehouse_items_alp`
--

INSERT INTO `warehouse_items_alp` (`id`, `warehouse_id`, `variant_id`, `product_id`, `quantity`, `min_quantity`, `last_movement_at`, `status`, `created_at`, `updated_at`) VALUES
(779, 1, 1124, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(780, 1, 1125, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(781, 1, 1126, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(782, 1, 1127, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(783, 1, 1128, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(784, 1, 1129, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(785, 1, 1130, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(786, 1, 1131, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(787, 1, 1132, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(788, 1, 1133, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(789, 1, 1134, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(790, 1, 1135, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(791, 1, 1136, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(792, 1, 1137, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(793, 1, 1138, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(794, 1, 1139, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(795, 1, 1140, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(796, 1, 1141, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(797, 1, 1142, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(798, 1, 1143, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(799, 1, 1144, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(800, 1, 1145, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(801, 1, 1146, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(802, 1, 1147, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(803, 1, 1148, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(804, 1, 1149, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(805, 1, 1150, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(806, 1, 1151, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(807, 1, 1152, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(808, 1, 1153, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(809, 1, 1154, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(810, 1, 1155, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(811, 1, 1156, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(812, 1, 1157, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(813, 1, 1158, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(814, 1, 1159, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(815, 1, 1160, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(816, 1, 1161, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(817, 1, 1162, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(818, 1, 1163, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(819, 1, 1164, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(820, 1, 1165, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(821, 1, 1166, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(822, 1, 1167, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(823, 1, 1168, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(824, 1, 1169, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(825, 1, 1170, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(826, 1, 1171, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(827, 1, 1172, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(828, 1, 1173, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(829, 1, 1174, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(830, 1, 1175, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(831, 1, 1176, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(832, 1, 1177, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(833, 1, 1178, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(834, 1, 1179, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(835, 1, 1180, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(836, 1, 1181, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(837, 1, 1182, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(838, 1, 1183, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(839, 1, 1184, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(840, 1, 1185, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(841, 1, 1186, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(842, 1, 1187, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(843, 1, 1188, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(844, 1, 1189, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(845, 1, 1190, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(846, 1, 1191, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(847, 1, 1192, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(848, 1, 1193, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(849, 1, 1194, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(850, 1, 1195, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(851, 1, 1196, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(852, 1, 1197, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(853, 1, 1198, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(854, 1, 1199, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(855, 1, 1200, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(856, 1, 1201, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(857, 1, 1202, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(858, 1, 1203, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(859, 1, 1204, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(860, 1, 1205, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(861, 1, 1206, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(862, 1, 1207, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(863, 1, 1208, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(864, 1, 1209, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(865, 1, 1210, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(866, 1, 1211, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(867, 1, 1212, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL),
(868, 1, 1213, 48, 0.00, 200.00, NULL, 'active', '2026-06-15 10:47:48', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `account_charts_alp`
--
ALTER TABLE `account_charts_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_parent_id` (`parent_id`),
  ADD KEY `idx_account_type` (`account_type`),
  ADD KEY `idx_code` (`code`);

--
-- Indexes for table `attendance_alp`
--
ALTER TABLE `attendance_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_emp_date` (`employee_id`,`work_date`),
  ADD KEY `idx_work_date` (`work_date`);

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_table_suffix` (`table_suffix`),
  ADD KEY `idx_factory_branch` (`factory_branch_id`),
  ADD KEY `idx_branch_type` (`branch_type`);

--
-- Indexes for table `consumables_alp`
--
ALTER TABLE `consumables_alp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_category` (`category`);

--
-- Indexes for table `consumable_entries_alp`
--
ALTER TABLE `consumable_entries_alp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_consumable_id` (`consumable_id`),
  ADD KEY `idx_entry_date` (`entry_date`);

--
-- Indexes for table `consumable_issues_alp`
--
ALTER TABLE `consumable_issues_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_issue_no` (`issue_no`),
  ADD KEY `idx_warehouse_id` (`warehouse_id`),
  ADD KEY `idx_date` (`issue_date`);

--
-- Indexes for table `consumable_issue_items_alp`
--
ALTER TABLE `consumable_issue_items_alp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_issue_id` (`issue_id`),
  ADD KEY `idx_item_id` (`item_id`),
  ADD KEY `fk_cii_movement` (`movement_id`);

--
-- Indexes for table `consumable_items_alp`
--
ALTER TABLE `consumable_items_alp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ci_currency` (`currency_id`);

--
-- Indexes for table `consumable_movements_alp`
--
ALTER TABLE `consumable_movements_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_movement_no` (`movement_no`),
  ADD KEY `idx_item_id` (`item_id`),
  ADD KEY `idx_warehouse_id` (`warehouse_id`),
  ADD KEY `idx_type` (`movement_type`),
  ADD KEY `idx_date` (`movement_date`),
  ADD KEY `idx_reference` (`reference_type`,`reference_id`),
  ADD KEY `fk_cm_to_warehouse` (`to_warehouse_id`);

--
-- Indexes for table `consumable_purchases_alp`
--
ALTER TABLE `consumable_purchases_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_invoice_no` (`invoice_no`),
  ADD KEY `idx_supplier_id` (`supplier_id`),
  ADD KEY `idx_warehouse_id` (`warehouse_id`),
  ADD KEY `idx_date` (`invoice_date`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `consumable_purchase_items_alp`
--
ALTER TABLE `consumable_purchase_items_alp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_purchase_id` (`purchase_id`),
  ADD KEY `idx_item_id` (`item_id`),
  ADD KEY `idx_movement_id` (`movement_id`);

--
-- Indexes for table `consumable_sales_alp`
--
ALTER TABLE `consumable_sales_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_invoice_no` (`invoice_no`),
  ADD KEY `idx_warehouse_id` (`warehouse_id`),
  ADD KEY `idx_date` (`invoice_date`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `consumable_sale_items_alp`
--
ALTER TABLE `consumable_sale_items_alp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sale_id` (`sale_id`),
  ADD KEY `idx_item_id` (`item_id`),
  ADD KEY `idx_movement_id` (`movement_id`);

--
-- Indexes for table `consumable_stock_alp`
--
ALTER TABLE `consumable_stock_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_item_warehouse` (`item_id`,`warehouse_id`),
  ADD KEY `idx_item_id` (`item_id`),
  ADD KEY `idx_warehouse_id` (`warehouse_id`);

--
-- Indexes for table `currencies`
--
ALTER TABLE `currencies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `customers_alp`
--
ALTER TABLE `customers_alp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_account_id` (`account_id`);

--
-- Indexes for table `employees_alp`
--
ALTER TABLE `employees_alp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_department` (`department`);

--
-- Indexes for table `exchange_rates_alp`
--
ALTER TABLE `exchange_rates_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_currency_date` (`currency_from`,`currency_to`,`rate_date`),
  ADD KEY `idx_rate_date` (`rate_date`);

--
-- Indexes for table `expenses_alp`
--
ALTER TABLE `expenses_alp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_expense_date` (`expense_date`),
  ADD KEY `idx_expense_acct` (`expense_account_id`);

--
-- Indexes for table `hr_attendance_alp`
--
ALTER TABLE `hr_attendance_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`employee_id`,`attendance_date`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_date` (`attendance_date`);

--
-- Indexes for table `hr_bonuses_alp`
--
ALTER TABLE `hr_bonuses_alp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_date` (`bonus_date`);

--
-- Indexes for table `hr_employees_alp`
--
ALTER TABLE `hr_employees_alp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_department` (`department`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_currency_id` (`currency_id`);

--
-- Indexes for table `hr_loans_alp`
--
ALTER TABLE `hr_loans_alp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `hr_payroll_alp`
--
ALTER TABLE `hr_payroll_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_emp_period` (`employee_id`,`period_from`),
  ADD KEY `idx_month` (`payroll_month`),
  ADD KEY `idx_status` (`payment_status`);

--
-- Indexes for table `hr_promotions_alp`
--
ALTER TABLE `hr_promotions_alp`
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
-- Indexes for table `inventory_movements_alp`
--
ALTER TABLE `inventory_movements_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `movement_number` (`movement_number`),
  ADD KEY `idx_movement_type` (`movement_type`),
  ADD KEY `idx_reference` (`reference_type`,`reference_id`),
  ADD KEY `idx_warehouse_id` (`warehouse_id`);

--
-- Indexes for table `inventory_movement_details_alp`
--
ALTER TABLE `inventory_movement_details_alp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_movement_id` (`movement_id`),
  ADD KEY `idx_variant_id` (`variant_id`);

--
-- Indexes for table `invoice_account_settings_alp`
--
ALTER TABLE `invoice_account_settings_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `journal_entries_alp`
--
ALTER TABLE `journal_entries_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `entry_number` (`entry_number`),
  ADD KEY `idx_entry_date` (`entry_date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_reference` (`reference_type`,`reference_id`);

--
-- Indexes for table `journal_entry_items_alp`
--
ALTER TABLE `journal_entry_items_alp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_journal_entry_id` (`journal_entry_id`),
  ADD KEY `idx_account_id` (`account_id`);

--
-- Indexes for table `modules`
--
ALTER TABLE `modules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `key` (`key`),
  ADD KEY `idx_parent_key` (`parent_key`),
  ADD KEY `idx_key` (`key`);

--
-- Indexes for table `notifications_alp`
--
ALTER TABLE `notifications_alp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_is_read` (`is_read`);

--
-- Indexes for table `payroll_alp`
--
ALTER TABLE `payroll_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_emp_period` (`employee_id`,`period_start`,`period_end`),
  ADD KEY `idx_period_start` (`period_start`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `production_entries_alp`
--
ALTER TABLE `production_entries_alp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_operation_id` (`operation_id`),
  ADD KEY `idx_entry_date` (`entry_date`);

--
-- Indexes for table `production_operations_alp`
--
ALTER TABLE `production_operations_alp`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `products_alp`
--
ALTER TABLE `products_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `model_number` (`model_number`),
  ADD KEY `idx_model_number` (`model_number`),
  ADD KEY `idx_category_id` (`category_id`),
  ADD KEY `idx_supplier_id` (`supplier_id`),
  ADD KEY `idx_is_active` (`is_active`);

--
-- Indexes for table `product_categories_alp`
--
ALTER TABLE `product_categories_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `idx_parent_id` (`parent_id`);

--
-- Indexes for table `product_colors_alp`
--
ALTER TABLE `product_colors_alp`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `product_sizes_alp`
--
ALTER TABLE `product_sizes_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_product_size` (`product_id`,`size`,`age_type`),
  ADD KEY `idx_product_id` (`product_id`);

--
-- Indexes for table `product_suppliers_alp`
--
ALTER TABLE `product_suppliers_alp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `product_variants_alp`
--
ALTER TABLE `product_variants_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `barcode` (`barcode`),
  ADD UNIQUE KEY `idx_size_color` (`size_id`,`color_id`),
  ADD KEY `idx_product_id` (`product_id`),
  ADD KEY `idx_barcode` (`barcode`);

--
-- Indexes for table `public_holidays_alp`
--
ALTER TABLE `public_holidays_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_date` (`holiday_date`),
  ADD KEY `idx_recurring` (`is_recurring`);

--
-- Indexes for table `purchases_alp`
--
ALTER TABLE `purchases_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `purchase_number` (`purchase_number`),
  ADD KEY `idx_supplier_id` (`supplier_id`),
  ADD KEY `idx_purchase_date` (`purchase_date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_journal_entry` (`journal_entry_id`);

--
-- Indexes for table `purchase_items_alp`
--
ALTER TABLE `purchase_items_alp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_purchase_id` (`purchase_id`),
  ADD KEY `idx_product_id` (`product_id`),
  ADD KEY `idx_variant_id` (`variant_id`),
  ADD KEY `idx_barcode` (`barcode`);

--
-- Indexes for table `purchase_returns_alp`
--
ALTER TABLE `purchase_returns_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `return_number` (`return_number`),
  ADD KEY `idx_purchase_id` (`purchase_id`),
  ADD KEY `idx_supplier_id` (`supplier_id`);

--
-- Indexes for table `purchase_return_items_alp`
--
ALTER TABLE `purchase_return_items_alp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_return_id` (`return_id`),
  ADD KEY `idx_purchase_item_id` (`purchase_item_id`);

--
-- Indexes for table `raw_materials_alp`
--
ALTER TABLE `raw_materials_alp`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `raw_material_stock_alp`
--
ALTER TABLE `raw_material_stock_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_material_wh` (`material_id`,`warehouse_id`);

--
-- Indexes for table `receipts_alp`
--
ALTER TABLE `receipts_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `receipt_number` (`receipt_number`),
  ADD KEY `idx_customer_id` (`customer_id`),
  ADD KEY `idx_receipt_date` (`receipt_date`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `receipt_invoices_alp`
--
ALTER TABLE `receipt_invoices_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_receipt_invoice` (`receipt_id`,`invoice_id`),
  ADD KEY `idx_invoice_id` (`invoice_id`);

--
-- Indexes for table `sales_invoices_alp`
--
ALTER TABLE `sales_invoices_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD UNIQUE KEY `uq_invoice_number` (`invoice_number`),
  ADD KEY `idx_customer_id` (`customer_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_invoice_date` (`invoice_date`),
  ADD KEY `idx_payment_status` (`payment_status`),
  ADD KEY `idx_carrier_id` (`shipping_carrier_id`);

--
-- Indexes for table `sales_invoice_items_alp`
--
ALTER TABLE `sales_invoice_items_alp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_invoice_id` (`invoice_id`),
  ADD KEY `idx_product_id` (`product_id`),
  ADD KEY `idx_variant_id` (`variant_id`),
  ADD KEY `idx_barcode` (`barcode`);

--
-- Indexes for table `sales_returns_alp`
--
ALTER TABLE `sales_returns_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `return_number` (`return_number`),
  ADD KEY `idx_invoice_id` (`invoice_id`),
  ADD KEY `idx_customer_id` (`customer_id`);

--
-- Indexes for table `sales_return_items_alp`
--
ALTER TABLE `sales_return_items_alp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_return_id` (`return_id`),
  ADD KEY `idx_invoice_item` (`invoice_item_id`);

--
-- Indexes for table `shipping_carriers_alp`
--
ALTER TABLE `shipping_carriers_alp`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`);

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
-- Indexes for table `warehouses_alp`
--
ALTER TABLE `warehouses_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `warehouse_items_alp`
--
ALTER TABLE `warehouse_items_alp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_wh_variant` (`warehouse_id`,`variant_id`),
  ADD KEY `idx_variant_id` (`variant_id`),
  ADD KEY `idx_product_id` (`product_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `account_charts_alp`
--
ALTER TABLE `account_charts_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_alp`
--
ALTER TABLE `attendance_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `consumables_alp`
--
ALTER TABLE `consumables_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_entries_alp`
--
ALTER TABLE `consumable_entries_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_issues_alp`
--
ALTER TABLE `consumable_issues_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_issue_items_alp`
--
ALTER TABLE `consumable_issue_items_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_items_alp`
--
ALTER TABLE `consumable_items_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `consumable_movements_alp`
--
ALTER TABLE `consumable_movements_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `consumable_purchases_alp`
--
ALTER TABLE `consumable_purchases_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `consumable_purchase_items_alp`
--
ALTER TABLE `consumable_purchase_items_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `consumable_sales_alp`
--
ALTER TABLE `consumable_sales_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_sale_items_alp`
--
ALTER TABLE `consumable_sale_items_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_stock_alp`
--
ALTER TABLE `consumable_stock_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `currencies`
--
ALTER TABLE `currencies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `customers_alp`
--
ALTER TABLE `customers_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employees_alp`
--
ALTER TABLE `employees_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exchange_rates_alp`
--
ALTER TABLE `exchange_rates_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expenses_alp`
--
ALTER TABLE `expenses_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_attendance_alp`
--
ALTER TABLE `hr_attendance_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=87;

--
-- AUTO_INCREMENT for table `hr_bonuses_alp`
--
ALTER TABLE `hr_bonuses_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_employees_alp`
--
ALTER TABLE `hr_employees_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `hr_loans_alp`
--
ALTER TABLE `hr_loans_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_payroll_alp`
--
ALTER TABLE `hr_payroll_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `hr_promotions_alp`
--
ALTER TABLE `hr_promotions_alp`
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
-- AUTO_INCREMENT for table `inventory_movements_alp`
--
ALTER TABLE `inventory_movements_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_movement_details_alp`
--
ALTER TABLE `inventory_movement_details_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_account_settings_alp`
--
ALTER TABLE `invoice_account_settings_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_entries_alp`
--
ALTER TABLE `journal_entries_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_entry_items_alp`
--
ALTER TABLE `journal_entry_items_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `modules`
--
ALTER TABLE `modules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=58;

--
-- AUTO_INCREMENT for table `notifications_alp`
--
ALTER TABLE `notifications_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll_alp`
--
ALTER TABLE `payroll_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_entries_alp`
--
ALTER TABLE `production_entries_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_operations_alp`
--
ALTER TABLE `production_operations_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products_alp`
--
ALTER TABLE `products_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `product_categories_alp`
--
ALTER TABLE `product_categories_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `product_colors_alp`
--
ALTER TABLE `product_colors_alp`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `product_sizes_alp`
--
ALTER TABLE `product_sizes_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=618;

--
-- AUTO_INCREMENT for table `product_suppliers_alp`
--
ALTER TABLE `product_suppliers_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `product_variants_alp`
--
ALTER TABLE `product_variants_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1214;

--
-- AUTO_INCREMENT for table `public_holidays_alp`
--
ALTER TABLE `public_holidays_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `purchases_alp`
--
ALTER TABLE `purchases_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_items_alp`
--
ALTER TABLE `purchase_items_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_returns_alp`
--
ALTER TABLE `purchase_returns_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_return_items_alp`
--
ALTER TABLE `purchase_return_items_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `raw_materials_alp`
--
ALTER TABLE `raw_materials_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `raw_material_stock_alp`
--
ALTER TABLE `raw_material_stock_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `receipts_alp`
--
ALTER TABLE `receipts_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `receipt_invoices_alp`
--
ALTER TABLE `receipt_invoices_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_invoices_alp`
--
ALTER TABLE `sales_invoices_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_invoice_items_alp`
--
ALTER TABLE `sales_invoice_items_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_returns_alp`
--
ALTER TABLE `sales_returns_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_return_items_alp`
--
ALTER TABLE `sales_return_items_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shipping_carriers_alp`
--
ALTER TABLE `shipping_carriers_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `user_activities`
--
ALTER TABLE `user_activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_permissions`
--
ALTER TABLE `user_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=69;

--
-- AUTO_INCREMENT for table `warehouses_alp`
--
ALTER TABLE `warehouses_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `warehouse_items_alp`
--
ALTER TABLE `warehouse_items_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=869;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance_alp`
--
ALTER TABLE `attendance_alp`
  ADD CONSTRAINT `fk_att_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees_alp` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `consumable_entries_alp`
--
ALTER TABLE `consumable_entries_alp`
  ADD CONSTRAINT `fk_ce_consumable` FOREIGN KEY (`consumable_id`) REFERENCES `consumables_alp` (`id`);

--
-- Constraints for table `consumable_issues_alp`
--
ALTER TABLE `consumable_issues_alp`
  ADD CONSTRAINT `fk_ci_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_alp` (`id`);

--
-- Constraints for table `consumable_issue_items_alp`
--
ALTER TABLE `consumable_issue_items_alp`
  ADD CONSTRAINT `fk_cii_issue` FOREIGN KEY (`issue_id`) REFERENCES `consumable_issues_alp` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cii_item` FOREIGN KEY (`item_id`) REFERENCES `consumable_items_alp` (`id`),
  ADD CONSTRAINT `fk_cii_movement` FOREIGN KEY (`movement_id`) REFERENCES `consumable_movements_alp` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `consumable_items_alp`
--
ALTER TABLE `consumable_items_alp`
  ADD CONSTRAINT `fk_ci_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `consumable_movements_alp`
--
ALTER TABLE `consumable_movements_alp`
  ADD CONSTRAINT `fk_cm_item` FOREIGN KEY (`item_id`) REFERENCES `consumable_items_alp` (`id`),
  ADD CONSTRAINT `fk_cm_to_warehouse` FOREIGN KEY (`to_warehouse_id`) REFERENCES `warehouses_alp` (`id`),
  ADD CONSTRAINT `fk_cm_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_alp` (`id`);

--
-- Constraints for table `consumable_purchases_alp`
--
ALTER TABLE `consumable_purchases_alp`
  ADD CONSTRAINT `fk_cp_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `product_suppliers_alp` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cp_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_alp` (`id`);

--
-- Constraints for table `consumable_purchase_items_alp`
--
ALTER TABLE `consumable_purchase_items_alp`
  ADD CONSTRAINT `fk_cpi_item` FOREIGN KEY (`item_id`) REFERENCES `consumable_items_alp` (`id`),
  ADD CONSTRAINT `fk_cpi_movement` FOREIGN KEY (`movement_id`) REFERENCES `consumable_movements_alp` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cpi_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `consumable_purchases_alp` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `consumable_sales_alp`
--
ALTER TABLE `consumable_sales_alp`
  ADD CONSTRAINT `fk_csa_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_alp` (`id`);

--
-- Constraints for table `consumable_sale_items_alp`
--
ALTER TABLE `consumable_sale_items_alp`
  ADD CONSTRAINT `fk_csi_item` FOREIGN KEY (`item_id`) REFERENCES `consumable_items_alp` (`id`),
  ADD CONSTRAINT `fk_csi_movement` FOREIGN KEY (`movement_id`) REFERENCES `consumable_movements_alp` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_csi_sale` FOREIGN KEY (`sale_id`) REFERENCES `consumable_sales_alp` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `consumable_stock_alp`
--
ALTER TABLE `consumable_stock_alp`
  ADD CONSTRAINT `fk_cs_item` FOREIGN KEY (`item_id`) REFERENCES `consumable_items_alp` (`id`),
  ADD CONSTRAINT `fk_cs_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_alp` (`id`);

--
-- Constraints for table `hr_employees_alp`
--
ALTER TABLE `hr_employees_alp`
  ADD CONSTRAINT `fk_currency_hr_employees` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`);

--
-- Constraints for table `internal_order_items`
--
ALTER TABLE `internal_order_items`
  ADD CONSTRAINT `fk_ioi_order` FOREIGN KEY (`order_id`) REFERENCES `internal_orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inventory_movement_details_alp`
--
ALTER TABLE `inventory_movement_details_alp`
  ADD CONSTRAINT `fk_imd_movement` FOREIGN KEY (`movement_id`) REFERENCES `inventory_movements_alp` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `journal_entry_items_alp`
--
ALTER TABLE `journal_entry_items_alp`
  ADD CONSTRAINT `fk_jei_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries_alp` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payroll_alp`
--
ALTER TABLE `payroll_alp`
  ADD CONSTRAINT `fk_pay_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees_alp` (`id`);

--
-- Constraints for table `production_entries_alp`
--
ALTER TABLE `production_entries_alp`
  ADD CONSTRAINT `fk_pe_operation` FOREIGN KEY (`operation_id`) REFERENCES `production_operations_alp` (`id`);

--
-- Constraints for table `products_alp`
--
ALTER TABLE `products_alp`
  ADD CONSTRAINT `fk_prod_category` FOREIGN KEY (`category_id`) REFERENCES `product_categories_alp` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_prod_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `product_suppliers_alp` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `product_sizes_alp`
--
ALTER TABLE `product_sizes_alp`
  ADD CONSTRAINT `fk_size_product` FOREIGN KEY (`product_id`) REFERENCES `products_alp` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_variants_alp`
--
ALTER TABLE `product_variants_alp`
  ADD CONSTRAINT `fk_var_product` FOREIGN KEY (`product_id`) REFERENCES `products_alp` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_var_size` FOREIGN KEY (`size_id`) REFERENCES `product_sizes_alp` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchase_items_alp`
--
ALTER TABLE `purchase_items_alp`
  ADD CONSTRAINT `fk_pi_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases_alp` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchase_return_items_alp`
--
ALTER TABLE `purchase_return_items_alp`
  ADD CONSTRAINT `fk_pri_return` FOREIGN KEY (`return_id`) REFERENCES `purchase_returns_alp` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `raw_material_stock_alp`
--
ALTER TABLE `raw_material_stock_alp`
  ADD CONSTRAINT `fk_rms_material` FOREIGN KEY (`material_id`) REFERENCES `raw_materials_alp` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `receipt_invoices_alp`
--
ALTER TABLE `receipt_invoices_alp`
  ADD CONSTRAINT `fk_ri_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices_alp` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ri_receipt` FOREIGN KEY (`receipt_id`) REFERENCES `receipts_alp` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sales_invoices_alp`
--
ALTER TABLE `sales_invoices_alp`
  ADD CONSTRAINT `fk_si_carrier` FOREIGN KEY (`shipping_carrier_id`) REFERENCES `shipping_carriers_alp` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_si_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers_alp` (`id`);

--
-- Constraints for table `sales_invoice_items_alp`
--
ALTER TABLE `sales_invoice_items_alp`
  ADD CONSTRAINT `fk_sii_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices_alp` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sales_return_items_alp`
--
ALTER TABLE `sales_return_items_alp`
  ADD CONSTRAINT `fk_sri_return` FOREIGN KEY (`return_id`) REFERENCES `sales_returns_alp` (`id`) ON DELETE CASCADE;

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
-- Constraints for table `warehouse_items_alp`
--
ALTER TABLE `warehouse_items_alp`
  ADD CONSTRAINT `fk_wi_product` FOREIGN KEY (`product_id`) REFERENCES `products_alp` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_wi_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants_alp` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_wi_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses_alp` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
