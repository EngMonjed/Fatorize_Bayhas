-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Jul 14, 2026 at 09:43 AM
-- Server version: 11.8.8-MariaDB-log
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
  `base_balance` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'الرصيد بعملة الفرع الأساسية',
  `currency_id` int(11) NOT NULL DEFAULT 1 COMMENT 'مرجع جدول currencies',
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
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `base_currency_id` int(11) DEFAULT NULL,
  `local_currency_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الفروع — كل فرع له table_suffix خاص به';

--
-- Dumping data for table `branches`
--

INSERT INTO `branches` (`id`, `name`, `name_en`, `branch_type`, `phone`, `email`, `address`, `city`, `country`, `tax_number`, `factory_branch_id`, `base_currency`, `local_currency`, `pricing_method`, `default_margin_pct`, `tax_rate_default`, `allow_negative_stock`, `notify_low_stock`, `low_stock_threshold`, `notify_new_invoice`, `notify_internal_order`, `notify_email`, `invoice_prefix`, `invoice_counter`, `fiscal_year_start`, `week_start_day`, `default_payment_terms`, `code`, `table_suffix`, `dashboard_path`, `icon`, `color`, `sort_order`, `created_by`, `updated_by`, `status`, `created_at`, `updated_at`, `base_currency_id`, `local_currency_id`) VALUES
(1, 'الفرع الرئيسي', 'Main Branch', 'retail', NULL, NULL, NULL, NULL, 'Syria', NULL, NULL, 'USD', 'USD', 'cost_plus', 20.00, 0.00, 0, 1, 5, 1, 1, NULL, 'INV', 0, 1, 1, 30, NULL, 'alp', 'aleppo/modules/dashboard.php', 'bi-building', '#3b82f6', 0, 1, NULL, 'active', NOW(), NULL, NULL, NULL);
-- ⚠ table_suffix='alp' مقصود: dashboard.php حالياً بيتحقق من 'alp' حرفياً
-- (قيد موثّق بالـREADME تحت Known Limitations). غيّر باقي الحقول
-- (name, invoice_prefix...) للشركة الفعلية بعد الاستيراد مباشرة.

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
  `last_purchase_price_usd` decimal(15,4) DEFAULT NULL,
  `last_purchase_date` date DEFAULT NULL,
  `currency_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='المستهلكات — فرع حلب';


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
  `subtotal_orig` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `subtotal_usd` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `discount_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `discount_usd` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `discount_orig` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `tax_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `tax_usd` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `tax_orig` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `total_usd` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `total_orig` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `paid_usd` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `paid_orig` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `balance_usd` decimal(15,4) NOT NULL DEFAULT 0.0000,
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
-- Table structure for table `consumable_purchase_items_alp`
--

CREATE TABLE `consumable_purchase_items_alp` (
  `id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL COMMENT 'FK → consumable_purchases_alp',
  `item_id` int(11) NOT NULL COMMENT 'FK → consumable_items_alp',
  `quantity` decimal(12,3) NOT NULL,
  `unit_price_orig` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `unit_price_usd` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `total_orig` decimal(15,4) NOT NULL DEFAULT 0.0000,
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
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `journal_entry_id` int(11) DEFAULT NULL,
  `cash_account_id` int(11) DEFAULT NULL,
  `exchange_rate` decimal(15,6) NOT NULL DEFAULT 1.000000
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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
(36, 'inventory.consumables', 'inventory', 'المستهلكات', 'bi-cup-hot', 35, 1),
(37, 'expenses.consumable_entries', 'expenses', 'سجل الاستهلاك', 'bi-journal-text', 62, 1),
(38, 'production', NULL, 'الإنتاج', 'bi-gear-wide-connected', 80, 1),
(39, 'production.raw_materials', 'production', 'المواد الأولية', 'bi-boxes', 81, 1),
(40, 'production.operations', 'production', 'عمليات الإنتاج', 'bi-tools', 82, 1),
(41, 'production.entries', 'production', 'سجل الإنتاج', 'bi-clipboard-data', 83, 1),
(53, 'hr.holidays', 'hr', 'العطل الرسمية', 'bi-calendar-x', 74, 1),
(54, 'inventory.internal_orders', 'inventory', 'الطلبات الداخلية', 'bi-arrow-left-right', 31, 1),
(58, 'sales.customers', 'sales', 'إدارة العملاء', 'bi-people', 10, 1),
(60, 'purchases.suppliers', 'purchases', 'إدارة الموردين', 'bi-truck', 20, 1),
(64, 'finance.account_settings', 'finance', 'إعدادات الربط المحاسبي', 'bi-gear', 45, 1),
(65, 'finance.currencies', 'finance', 'إدارة العملات', 'bi-currency-exchange', 46, 1),
(66, 'finance.shipping_carriers', 'finance', 'شركات الشحن', 'bi-truck', 47, 1);

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قياسات كل موديل — سعر مستقل لكل قياس';


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
  `final_amount_usd` decimal(15,4) DEFAULT NULL COMMENT 'الإجمالي بعملة الفرع',
  `paid_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `balance_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
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
  `unit_price_usd` decimal(10,4) DEFAULT NULL COMMENT 'بعملة الفرع الأساسية= unit_price / exchange_rate',
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
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
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
(1, 'admin', 'مدير الحساب', 'admin@example.com', '$2y$10$REPLACE.THIS.WITH.A.REAL.BCRYPT.HASH.BEFORE.FIRST.LOGIN', 'admin', 1, NULL, NOW(), NULL);
-- ⚠ استبدل الهاش فوراً قبل أول استخدام فعلي:
-- php -r "echo password_hash('كلمة_مرورك', PASSWORD_DEFAULT);"

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
(1, 1);

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
-- Indexes for table `shipping_carriers`
--
ALTER TABLE `shipping_carriers`
  ADD PRIMARY KEY (`id`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1020;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `consumable_movements_alp`
--
ALTER TABLE `consumable_movements_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `consumable_purchases_alp`
--
ALTER TABLE `consumable_purchases_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `consumable_purchase_items_alp`
--
ALTER TABLE `consumable_purchase_items_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `currencies`
--
ALTER TABLE `currencies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `customers_alp`
--
ALTER TABLE `customers_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=202;

--
-- AUTO_INCREMENT for table `hr_bonuses_alp`
--
ALTER TABLE `hr_bonuses_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_employees_alp`
--
ALTER TABLE `hr_employees_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `hr_loans_alp`
--
ALTER TABLE `hr_loans_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_payroll_alp`
--
ALTER TABLE `hr_payroll_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=73;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `inventory_movement_details_alp`
--
ALTER TABLE `inventory_movement_details_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `invoice_account_settings_alp`
--
ALTER TABLE `invoice_account_settings_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `journal_entries_alp`
--
ALTER TABLE `journal_entries_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `journal_entry_items_alp`
--
ALTER TABLE `journal_entry_items_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=67;

--
-- AUTO_INCREMENT for table `modules`
--
ALTER TABLE `modules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=67;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=68;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=841;

--
-- AUTO_INCREMENT for table `product_suppliers_alp`
--
ALTER TABLE `product_suppliers_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `product_variants_alp`
--
ALTER TABLE `product_variants_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2018;

--
-- AUTO_INCREMENT for table `public_holidays_alp`
--
ALTER TABLE `public_holidays_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `purchases_alp`
--
ALTER TABLE `purchases_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `purchase_items_alp`
--
ALTER TABLE `purchase_items_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sales_invoice_items_alp`
--
ALTER TABLE `sales_invoice_items_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

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
-- AUTO_INCREMENT for table `shipping_carriers`
--
ALTER TABLE `shipping_carriers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=78;

--
-- AUTO_INCREMENT for table `warehouses_alp`
--
ALTER TABLE `warehouses_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `warehouse_items_alp`
--
ALTER TABLE `warehouse_items_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1799;

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
