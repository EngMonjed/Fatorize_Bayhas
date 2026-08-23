-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Aug 23, 2026 at 01:42 PM
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

--
-- Dumping data for table `account_charts_ret`
--

INSERT INTO `account_charts_ret` (`id`, `code`, `name`, `description`, `parent_id`, `account_type`, `cash_flow_category`, `balance`, `base_balance`, `currency_id`, `exchange_rate`, `level`, `is_active`, `is_locked`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, '1', 'الأصول', NULL, NULL, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 1, 1, 1, NULL, NULL, '2026-06-24 12:50:38', NULL),
(2, '2', 'الالتزامات', NULL, NULL, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 1, 1, 1, NULL, NULL, '2026-06-24 12:50:38', NULL),
(3, '3', 'حقوق الملكية', NULL, NULL, 'equity', 'financing', 0.00, 0.00, 1, 1.0000, 1, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(4, '4', 'الإيرادات', NULL, NULL, 'revenue', 'operating', 0.00, 0.00, 1, 1.0000, 1, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(5, '5', 'المصاريف', NULL, NULL, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 1, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(10, '1.1', 'الأصول المتداولة', NULL, 1, 'asset', 'operating', 0.00, 0.00, 1, 1.0000, 2, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(11, '1.2', 'الأصول الثابتة', NULL, 1, 'asset', 'investing', 0.00, 0.00, 1, 1.0000, 2, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(20, '2.1', 'الالتزامات المتداولة', NULL, 2, 'liability', 'operating', 0.00, 0.00, 1, 1.0000, 2, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(21, '2.2', 'الالتزامات طويلة الأمد', NULL, 2, 'liability', 'financing', 0.00, 0.00, 1, 1.0000, 2, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(30, '3.1', 'رأس المال', NULL, 3, 'equity', 'financing', 0.00, 0.00, 1, 1.0000, 2, 1, 0, NULL, 1, '2026-06-24 12:50:38', '2026-08-06 15:14:32'),
(31, '3.2', 'الأرباح المبقاة', NULL, 3, 'equity', 'financing', 0.00, 0.00, 1, 1.0000, 2, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(32, '3.3', 'أرباح السنة الحالية', NULL, 3, 'equity', 'financing', 0.00, 0.00, 1, 1.0000, 2, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(40, '4.1', 'إيرادات المبيعات', NULL, 4, 'revenue', 'operating', 0.00, 0.00, 1, 1.0000, 2, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-08-13 13:38:23'),
(41, '4.2', 'إيرادات أخرى', NULL, 4, 'revenue', 'operating', 0.00, 0.00, 1, 1.0000, 2, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(50, '5.1', 'تكلفة المبيعات', NULL, 5, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 2, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(51, '5.2', 'مصاريف التشغيل', NULL, 5, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 2, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(52, '5.3', 'مصاريف الموظفين', NULL, 5, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 2, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(53, '5.4', 'مصاريف إدارية وعمومية', NULL, 5, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 2, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(100, '1.1.1', 'النقدية والصناديق', NULL, 10, 'asset', 'excluded', 0.00, 0.00, 1, 1.0000, 3, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(101, '1.1.2', 'البنوك', NULL, 10, 'asset', 'excluded', 0.00, 0.00, 1, 1.0000, 3, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(102, '1.1.3', 'ذمم العملاء', NULL, 10, 'asset', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-08-13 13:38:23'),
(103, '1.1.4', 'سلف الموظفين', NULL, 10, 'asset', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(104, '1.1.5', 'المخزون', NULL, 10, 'asset', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-08-13 13:38:23'),
(105, '1.1.6', 'مخزون المستهلكات', NULL, 10, 'asset', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-08-06 15:14:57'),
(200, '2.1.1', 'ذمم الموردين', NULL, 20, 'liability', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-08-06 15:14:58'),
(201, '2.1.2', 'مستحقات الموظفين', NULL, 20, 'liability', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(202, '2.1.3', 'ضرائب مستحقة', NULL, 20, 'liability', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(500, '5.1.1', 'تكلفة البضاعة المباعة', NULL, 50, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-08-13 13:38:23'),
(510, '5.2.1', 'مصاريف المستهلكات', NULL, 51, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-08-06 15:14:55'),
(511, '5.2.2', 'إيجار المحل', NULL, 51, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 15:20:58'),
(512, '5.2.3', 'كهرباء وماء', NULL, 51, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-08-06 15:14:59'),
(513, '5.2.4', 'صيانة وإصلاح', NULL, 51, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(514, '5.2.5', 'شحن ونقل', NULL, 51, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-08-13 13:38:23'),
(520, '5.3.1', 'رواتب وأجور', NULL, 52, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(521, '5.3.2', 'مكافآت وحوافز', NULL, 52, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(530, '5.4.1', 'مصاريف إدارية عامة', NULL, 53, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(531, '5.4.2', 'قرطاسية ومكتبية', NULL, 53, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(532, '5.4.3', 'فروقات أسعار صرف', NULL, 53, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(1001, '1.1.1.001', 'صندوق دولار أمريكي', NULL, 100, 'asset', 'excluded', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, 1, '2026-06-24 12:50:38', '2026-08-13 13:38:23'),
(1011, '1.1.2.001', 'بنك دولار أمريكي', NULL, 101, 'asset', 'excluded', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
(1017, '1.1.7', 'دفعات مقدمة للموردين', NULL, 10, 'asset', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-06-28 10:01:44', '2026-07-30 14:36:38'),
(1020, '1.1.8', 'ضريبة مشتريات قابلة للاسترداد', '', 10, 'asset', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, 1, NULL, '2026-07-19 12:37:03', '2026-07-30 14:36:38'),
(1021, '4.2.1', 'خصم مشتريات تجاري مكتسب', NULL, 41, 'revenue', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-07-23 09:38:50', '2026-07-30 14:36:38'),
(1022, '4.2.2', 'إيراد خصم تعجيل الدفع', NULL, 41, 'revenue', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-07-23 09:38:50', '2026-07-30 14:36:38'),
(1028, '2.1.4', 'ضريبة مبيعات مستحقة', 'sales_tax_payable', 20, 'liability', 'operating', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-07-27 11:21:50', '2026-07-30 14:36:38'),
(1029, '5.2.6', 'خصومات مبيعات ممنوحة', 'sales_discount_given', 51, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-07-27 11:21:50', '2026-07-30 14:36:38'),
(1030, '5.2.7', 'خصم تعجيل استلام من العملاء المبيعات', 'settlement_discount_expense', 51, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-07-27 11:21:51', '2026-07-30 14:36:38'),
(1031, '2.1.5', 'الدفعات المقدمة من العملاء', '', 20, 'liability', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, 1, 1, '2026-07-28 10:13:32', '2026-07-30 14:36:38'),
(1032, '4.2.3', 'أرباح فروقات الصرف', '', 41, 'revenue', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, 1, NULL, '2026-07-30 03:21:02', '2026-07-30 14:36:38'),
(1066, '3900', 'رصيد افتتاحي', NULL, 3, 'equity', 'none', 0.00, 0.00, 1, 1.0000, 2, 1, 0, NULL, NULL, '2026-08-13 13:04:31', '2026-08-13 13:10:38'),
(1067, '1.1.1.002', 'صندوق ليرة سورية', NULL, 100, 'asset', 'none', 0.00, 0.00, 2, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:32:32', NULL),
(1068, '1.1.2.002', 'بنك ليرة سورية', NULL, 101, 'asset', 'none', 0.00, 0.00, 2, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:32:32', NULL),
(1069, '2.1.1.001', 'ذمم البيهس', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:33:06', NULL),
(1070, '1.1.7.001', 'دفعات مقدمة — البيهس', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:33:06', NULL),
(1071, '2.1.1.002', 'ذمم توب مان', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:33:27', NULL),
(1072, '1.1.7.002', 'دفعات مقدمة — توب مان', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:33:27', NULL),
(1073, '2.1.1.003', 'ذمم خضرو', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:33:45', NULL),
(1074, '1.1.7.003', 'دفعات مقدمة — خضرو', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:33:45', NULL),
(1075, '2.1.1.004', 'ذمم اوسكار', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:34:03', NULL),
(1076, '1.1.7.004', 'دفعات مقدمة — اوسكار', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:34:03', NULL),
(1077, '2.1.1.005', 'ذمم بلاتين', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:34:22', NULL),
(1078, '1.1.7.005', 'دفعات مقدمة — بلاتين', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:34:22', NULL),
(1079, '2.1.1.006', 'ذمم أبو زلام', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:34:38', NULL),
(1080, '1.1.7.006', 'دفعات مقدمة — أبو زلام', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:34:38', NULL),
(1081, '2.1.1.007', 'ذمم عمار قنبور', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:34:50', NULL),
(1082, '1.1.7.007', 'دفعات مقدمة — عمار قنبور', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:34:50', NULL),
(1083, '2.1.1.008', 'ذمم B LOVE', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:35:18', NULL),
(1084, '1.1.7.008', 'دفعات مقدمة — B LOVE', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:35:18', NULL),
(1085, '2.1.1.009', 'ذمم كربون', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:35:34', NULL),
(1086, '1.1.7.009', 'دفعات مقدمة — كربون', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:35:34', NULL),
(1087, '2.1.1.010', 'ذمم كريم بدر', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:35:48', NULL),
(1088, '1.1.7.010', 'دفعات مقدمة — كريم بدر', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:35:48', NULL),
(1089, '2.1.2.001', 'مستحقات محمود المسلم', NULL, 201, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:40:16', NULL),
(1090, '1.1.4.001', 'سلف محمود المسلم', NULL, 103, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:40:16', NULL),
(1091, '2.1.2.002', 'مستحقات احمد كردي', NULL, 201, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:41:29', NULL),
(1092, '1.1.4.002', 'سلف احمد كردي', NULL, 103, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 13:41:29', NULL);

--
-- Indexes for dumped tables
--

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
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `account_charts_ret`
--
ALTER TABLE `account_charts_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1093;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
