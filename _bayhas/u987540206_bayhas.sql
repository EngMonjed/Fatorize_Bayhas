-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Jun 15, 2026 at 07:01 AM
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
-- Table structure for table `consumable_items_alp`
--

CREATE TABLE `consumable_items_alp` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL COMMENT 'قهوة، ماء، قرطاسية، كهربا',
  `category` enum('utility','supplies','food','maintenance','other') NOT NULL DEFAULT 'other',
  `unit` varchar(20) NOT NULL DEFAULT 'قطعة',
  `estimated_cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'تكلفة تقديرية للمقارنة',
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='المستهلكات — فرع حلب';

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
  `description` text DEFAULT NULL,
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

INSERT INTO `products_alp` (`id`, `model_number`, `name`, `description`, `category_id`, `fabric_type`, `supplier_id`, `image_path`, `is_active`, `notes`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(31, '5075', 'بنطلون بوي فريند تطريز عالجيب الخلفي اليمين', NULL, 7, 'سكالا', 1, NULL, 1, '', 1, NULL, '2026-06-15 06:32:33', '2026-06-15 06:41:05'),
(32, '5024', 'بنطلون بوي فريند نجمات مطرزة', NULL, 7, 'سكالا', 1, NULL, 1, '', 1, NULL, '2026-06-15 06:49:46', NULL);

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
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `selling_price` decimal(10,4) NOT NULL DEFAULT 0.0000 COMMENT 'سعر البيع بالدولار USD — مشترك بين كل الألوان',
  `packet_qty` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قياسات كل موديل — سعر مستقل لكل قياس';

--
-- Dumping data for table `product_sizes_alp`
--

INSERT INTO `product_sizes_alp` (`id`, `product_id`, `size`, `sort_order`, `selling_price`, `packet_qty`, `is_active`, `created_at`, `updated_at`) VALUES
(238, 31, '2', 0, 7.8000, 4, 1, '2026-06-15 06:32:33', NULL),
(239, 31, '3', 1, 7.8000, 4, 1, '2026-06-15 06:32:33', NULL),
(240, 31, '4', 2, 7.8000, 4, 1, '2026-06-15 06:32:33', NULL),
(241, 31, '5', 3, 7.8000, 4, 1, '2026-06-15 06:32:33', NULL),
(242, 31, '6', 4, 9.1000, 3, 1, '2026-06-15 06:32:33', NULL),
(243, 31, '8', 5, 9.1000, 3, 1, '2026-06-15 06:32:33', NULL),
(244, 31, '10', 6, 9.1000, 3, 1, '2026-06-15 06:32:33', NULL),
(245, 31, '12', 7, 10.4000, 5, 1, '2026-06-15 06:32:33', NULL),
(246, 31, '13', 8, 10.4000, 5, 1, '2026-06-15 06:32:33', NULL),
(247, 31, '14', 9, 10.4000, 5, 1, '2026-06-15 06:32:33', NULL),
(248, 31, '15', 10, 10.4000, 5, 1, '2026-06-15 06:32:33', NULL),
(249, 31, '16', 11, 10.4000, 5, 1, '2026-06-15 06:32:33', NULL),
(250, 32, '2', 0, 65.0000, 4, 1, '2026-06-15 06:49:46', NULL),
(251, 32, '3', 1, 65.0000, 4, 1, '2026-06-15 06:49:46', NULL),
(252, 32, '4', 2, 65.0000, 4, 1, '2026-06-15 06:49:46', NULL),
(253, 32, '5', 3, 65.0000, 4, 1, '2026-06-15 06:49:46', NULL),
(254, 32, '6', 4, 78.0000, 3, 1, '2026-06-15 06:49:46', NULL),
(255, 32, '8', 5, 78.0000, 3, 1, '2026-06-15 06:49:46', NULL),
(256, 32, '10', 6, 78.0000, 3, 1, '2026-06-15 06:49:46', NULL),
(257, 32, '12', 7, 91.0000, 3, 1, '2026-06-15 06:49:46', NULL),
(258, 32, '13', 8, 91.0000, 3, 1, '2026-06-15 06:49:46', NULL),
(259, 32, '14', 9, 91.0000, 3, 1, '2026-06-15 06:49:46', NULL);

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

INSERT INTO `product_suppliers_alp` (`id`, `account_id`, `prepaid_account_id`, `name`, `contact_person`, `phone`, `email`, `address`, `tax_number`, `type`, `status`, `credit_limit`, `discount_percentage`, `notes`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, NULL, NULL, 'بيهس', 'أبو يوسف', '', NULL, NULL, NULL, 'wholesaler', 'active', 0.00, 0.00, 'يرءؤر', 1, NULL, '2026-06-14 10:06:41', NULL);

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
(364, 31, 238, 4, '5075-G01-C01-S238', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(365, 31, 239, 4, '5075-G01-C01-S239', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(366, 31, 240, 4, '5075-G01-C01-S240', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(367, 31, 241, 4, '5075-G01-C01-S241', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(368, 31, 242, 4, '5075-G02-C01-S242', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(369, 31, 243, 4, '5075-G02-C01-S243', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(370, 31, 244, 4, '5075-G02-C01-S244', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(371, 31, 245, 4, '5075-G03-C01-S245', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(372, 31, 246, 4, '5075-G03-C01-S246', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(373, 31, 247, 4, '5075-G03-C01-S247', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(374, 31, 248, 4, '5075-G03-C01-S248', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(375, 31, 249, 4, '5075-G03-C01-S249', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(376, 31, 238, 14, '5075-G01-C02-S238', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(377, 31, 239, 14, '5075-G01-C02-S239', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(378, 31, 240, 14, '5075-G01-C02-S240', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(379, 31, 241, 14, '5075-G01-C02-S241', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(380, 31, 242, 14, '5075-G02-C02-S242', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(381, 31, 243, 14, '5075-G02-C02-S243', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(382, 31, 244, 14, '5075-G02-C02-S244', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(383, 31, 245, 14, '5075-G03-C02-S245', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(384, 31, 246, 14, '5075-G03-C02-S246', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(385, 31, 247, 14, '5075-G03-C02-S247', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(386, 31, 248, 14, '5075-G03-C02-S248', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(387, 31, 249, 14, '5075-G03-C02-S249', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(388, 31, 238, 13, '5075-G01-C03-S238', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(389, 31, 239, 13, '5075-G01-C03-S239', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(390, 31, 240, 13, '5075-G01-C03-S240', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(391, 31, 241, 13, '5075-G01-C03-S241', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(392, 31, 242, 13, '5075-G02-C03-S242', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(393, 31, 243, 13, '5075-G02-C03-S243', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(394, 31, 244, 13, '5075-G02-C03-S244', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(395, 31, 245, 13, '5075-G03-C03-S245', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(396, 31, 246, 13, '5075-G03-C03-S246', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(397, 31, 247, 13, '5075-G03-C03-S247', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(398, 31, 248, 13, '5075-G03-C03-S248', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(399, 31, 249, 13, '5075-G03-C03-S249', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(400, 31, 238, 1, '5075-G01-C04-S238', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(401, 31, 239, 1, '5075-G01-C04-S239', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(402, 31, 240, 1, '5075-G01-C04-S240', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(403, 31, 241, 1, '5075-G01-C04-S241', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(404, 31, 242, 1, '5075-G02-C04-S242', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(405, 31, 243, 1, '5075-G02-C04-S243', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(406, 31, 244, 1, '5075-G02-C04-S244', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(407, 31, 245, 1, '5075-G03-C04-S245', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(408, 31, 246, 1, '5075-G03-C04-S246', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(409, 31, 247, 1, '5075-G03-C04-S247', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(410, 31, 248, 1, '5075-G03-C04-S248', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(411, 31, 249, 1, '5075-G03-C04-S249', 1, 1, NULL, '2026-06-15 06:32:33', NULL),
(412, 32, 250, 5, '5024-G01-C01-S250', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(413, 32, 251, 5, '5024-G01-C01-S251', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(414, 32, 252, 5, '5024-G01-C01-S252', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(415, 32, 253, 5, '5024-G01-C01-S253', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(416, 32, 254, 5, '5024-G02-C01-S254', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(417, 32, 255, 5, '5024-G02-C01-S255', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(418, 32, 256, 5, '5024-G02-C01-S256', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(419, 32, 257, 5, '5024-G03-C01-S257', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(420, 32, 258, 5, '5024-G03-C01-S258', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(421, 32, 259, 5, '5024-G03-C01-S259', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(422, 32, 250, 10, '5024-G01-C02-S250', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(423, 32, 251, 10, '5024-G01-C02-S251', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(424, 32, 252, 10, '5024-G01-C02-S252', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(425, 32, 253, 10, '5024-G01-C02-S253', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(426, 32, 254, 10, '5024-G02-C02-S254', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(427, 32, 255, 10, '5024-G02-C02-S255', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(428, 32, 256, 10, '5024-G02-C02-S256', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(429, 32, 257, 10, '5024-G03-C02-S257', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(430, 32, 258, 10, '5024-G03-C02-S258', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(431, 32, 259, 10, '5024-G03-C02-S259', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(432, 32, 250, 8, '5024-G01-C03-S250', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(433, 32, 251, 8, '5024-G01-C03-S251', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(434, 32, 252, 8, '5024-G01-C03-S252', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(435, 32, 253, 8, '5024-G01-C03-S253', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(436, 32, 254, 8, '5024-G02-C03-S254', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(437, 32, 255, 8, '5024-G02-C03-S255', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(438, 32, 256, 8, '5024-G02-C03-S256', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(439, 32, 257, 8, '5024-G03-C03-S257', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(440, 32, 258, 8, '5024-G03-C03-S258', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(441, 32, 259, 8, '5024-G03-C03-S259', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(442, 32, 250, 18, '5024-G01-C04-S250', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(443, 32, 251, 18, '5024-G01-C04-S251', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(444, 32, 252, 18, '5024-G01-C04-S252', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(445, 32, 253, 18, '5024-G01-C04-S253', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(446, 32, 254, 18, '5024-G02-C04-S254', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(447, 32, 255, 18, '5024-G02-C04-S255', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(448, 32, 256, 18, '5024-G02-C04-S256', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(449, 32, 257, 18, '5024-G03-C04-S257', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(450, 32, 258, 18, '5024-G03-C04-S258', 1, 1, NULL, '2026-06-15 06:49:46', NULL),
(451, 32, 259, 18, '5024-G03-C04-S259', 1, 1, NULL, '2026-06-15 06:49:46', NULL);

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
  `avg_cost_usd` decimal(10,4) NOT NULL DEFAULT 0.0000 COMMENT 'متوسط التكلفة بالدولار',
  `last_movement_at` datetime DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مخزون كل Variant في كل مستودع';

--
-- Dumping data for table `warehouse_items_alp`
--

INSERT INTO `warehouse_items_alp` (`id`, `warehouse_id`, `variant_id`, `product_id`, `quantity`, `min_quantity`, `avg_cost_usd`, `last_movement_at`, `status`, `created_at`, `updated_at`) VALUES
(19, 1, 364, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(20, 1, 365, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(21, 1, 366, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(22, 1, 367, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(23, 1, 368, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(24, 1, 369, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(25, 1, 370, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(26, 1, 371, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(27, 1, 372, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(28, 1, 373, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(29, 1, 374, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(30, 1, 375, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(31, 1, 376, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(32, 1, 377, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(33, 1, 378, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(34, 1, 379, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(35, 1, 380, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(36, 1, 381, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(37, 1, 382, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(38, 1, 383, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(39, 1, 384, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(40, 1, 385, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(41, 1, 386, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(42, 1, 387, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(43, 1, 388, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(44, 1, 389, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(45, 1, 390, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(46, 1, 391, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(47, 1, 392, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(48, 1, 393, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(49, 1, 394, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(50, 1, 395, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(51, 1, 396, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(52, 1, 397, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(53, 1, 398, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(54, 1, 399, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(55, 1, 400, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(56, 1, 401, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(57, 1, 402, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(58, 1, 403, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(59, 1, 404, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(60, 1, 405, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(61, 1, 406, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(62, 1, 407, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(63, 1, 408, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(64, 1, 409, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(65, 1, 410, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(66, 1, 411, 31, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:32:33', NULL),
(67, 1, 412, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(68, 1, 413, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(69, 1, 414, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(70, 1, 415, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(71, 1, 416, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(72, 1, 417, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(73, 1, 418, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(74, 1, 419, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(75, 1, 420, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(76, 1, 421, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(77, 1, 422, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(78, 1, 423, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(79, 1, 424, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(80, 1, 425, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(81, 1, 426, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(82, 1, 427, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(83, 1, 428, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(84, 1, 429, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(85, 1, 430, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(86, 1, 431, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(87, 1, 432, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(88, 1, 433, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(89, 1, 434, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(90, 1, 435, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(91, 1, 436, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(92, 1, 437, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(93, 1, 438, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(94, 1, 439, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(95, 1, 440, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(96, 1, 441, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(97, 1, 442, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(98, 1, 443, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(99, 1, 444, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(100, 1, 445, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(101, 1, 446, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(102, 1, 447, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(103, 1, 448, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(104, 1, 449, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(105, 1, 450, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL),
(106, 1, 451, 32, 0.00, 0.00, 0.0000, NULL, 'active', '2026-06-15 06:49:46', NULL);

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
-- Indexes for table `consumable_items_alp`
--
ALTER TABLE `consumable_items_alp`
  ADD PRIMARY KEY (`id`);

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
  ADD UNIQUE KEY `idx_product_size` (`product_id`,`size`),
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
-- AUTO_INCREMENT for table `consumable_items_alp`
--
ALTER TABLE `consumable_items_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=260;

--
-- AUTO_INCREMENT for table `product_suppliers_alp`
--
ALTER TABLE `product_suppliers_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `product_variants_alp`
--
ALTER TABLE `product_variants_alp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=452;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=107;

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
