-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Aug 23, 2026 at 09:29 PM
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
(1033, '3900', 'رصيد افتتاحي', NULL, 3, 'equity', 'none', 0.00, 0.00, 1, 1.0000, 2, 1, 0, NULL, NULL, '2026-08-13 13:04:31', '2026-08-13 13:10:38'),
(1034, '1.1.1.002', 'صندوق ليرة سورية', NULL, 100, 'asset', 'none', 0.00, 0.00, 2, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:25:08', NULL),
(1035, '1.1.2.002', 'بنك ليرة سورية', NULL, 101, 'asset', 'none', 0.00, 0.00, 2, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:25:08', NULL),
(1036, '2.1.2.001', 'مستحقات محمود المسلم', NULL, 201, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:25:54', NULL),
(1037, '1.1.4.001', 'سلف محمود المسلم', NULL, 103, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:25:54', NULL),
(1038, '2.1.2.002', 'مستحقات احمد كردي', NULL, 201, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:26:29', NULL),
(1039, '1.1.4.002', 'سلف احمد كردي', NULL, 103, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:26:29', NULL),
(1040, '2.1.1.001', 'ذمم البيهس', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:26:56', NULL),
(1041, '1.1.7.001', 'دفعات مقدمة — البيهس', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:26:56', NULL),
(1042, '2.1.1.002', 'ذمم توب مان', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:27:19', NULL),
(1043, '1.1.7.002', 'دفعات مقدمة — توب مان', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:27:19', NULL),
(1044, '2.1.1.003', 'ذمم خضرو', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:27:34', NULL),
(1045, '1.1.7.003', 'دفعات مقدمة — خضرو', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:27:34', NULL),
(1046, '2.1.1.004', 'ذمم اوسكار', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:27:48', NULL),
(1047, '1.1.7.004', 'دفعات مقدمة — اوسكار', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:27:48', NULL),
(1048, '2.1.1.005', 'ذمم بلاتين', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:28:07', NULL),
(1049, '1.1.7.005', 'دفعات مقدمة — بلاتين', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:28:07', NULL),
(1050, '2.1.1.006', 'ذمم أبو زلام', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:28:27', NULL),
(1051, '1.1.7.006', 'دفعات مقدمة — أبو زلام', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:28:27', NULL),
(1052, '2.1.1.007', 'ذمم عمار قنبور', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:28:48', NULL),
(1053, '1.1.7.007', 'دفعات مقدمة — عمار قنبور', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:28:48', NULL),
(1054, '2.1.1.008', 'ذمم B LOVE', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:29:04', NULL),
(1055, '1.1.7.008', 'دفعات مقدمة — B LOVE', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:29:04', NULL),
(1056, '2.1.1.009', 'ذمم كربون', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:29:19', NULL),
(1057, '1.1.7.009', 'دفعات مقدمة — كربون', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:29:19', NULL),
(1058, '2.1.1.010', 'ذمم كريم بدر', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:29:34', NULL),
(1059, '1.1.7.010', 'دفعات مقدمة — كريم بدر', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:29:34', NULL),
(1060, '2.1.1.011', 'ذمم نور صباغ', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:29:48', NULL),
(1061, '1.1.7.011', 'دفعات مقدمة — نور صباغ', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-23 14:29:48', NULL);

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

--
-- Dumping data for table `branches`
--

INSERT INTO `branches` (`id`, `name`, `name_en`, `tenant_slogan`, `branch_type`, `phone`, `email`, `address`, `city`, `country`, `tax_number`, `commercial_registration_number`, `factory_branch_id`, `base_currency`, `local_currency`, `pricing_method`, `default_margin_pct`, `costing_method`, `tax_rate_default`, `tax_input_recoverable`, `allow_negative_stock`, `notify_low_stock`, `notify_new_invoice`, `notify_internal_order`, `notify_email`, `invoice_prefix`, `invoice_counter`, `fiscal_year_start`, `week_start_day`, `default_payment_terms`, `code`, `table_suffix`, `dashboard_path`, `icon`, `color`, `sort_order`, `created_by`, `updated_by`, `status`, `created_at`, `updated_at`, `base_currency_id`, `local_currency_id`, `default_purchase_tax_pct`, `opening_balance_locked_at`, `opening_balance_locked_by`) VALUES
(1, 'فرع السبع بحرات', 'Retail Branch', 'لصناعة وتجارة ألبسة الأطفال الجاهزة', 'retail', '+963992326518', NULL, 'دوار السبع بحرات - باتجاه الجامع الكبير', 'حلب', 'Syria', '123456', '#############', NULL, 'USD', 'SYP', 'cost_plus', 10.00, 'last_cost', 0.00, 'non_recoverable', 0, 1, 1, 1, NULL, 'ret', 0, 1, 6, 30, 'ret', 'ret', 'retail1/modules/dashboard.php', 'bi-shop-window', '#f59e0b', 1, NULL, 1, 'active', '2026-05-20 11:51:25', '2026-08-22 17:22:16', 1, 4, 0.00, NULL, NULL);

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

--
-- Dumping data for table `consumable_categories_ret`
--

INSERT INTO `consumable_categories_ret` (`id`, `name`, `icon`, `color`, `bg_color`, `is_active`, `sort_order`, `created_by`, `created_at`) VALUES
(1, 'مواد غذائية', 'bi-lightning-charge', '#0891b2', '#e0f7fa', 1, 10, NULL, '2026-07-18 00:47:54'),
(2, 'قرطاسية', 'bi-pencil', '#7c3aed', '#f3e8ff', 1, 20, NULL, '2026-07-18 00:47:54'),
(3, 'منظفات', 'bi-cup-hot', '#d97706', '#fef3c7', 1, 30, NULL, '2026-07-18 00:47:54'),
(4, 'صيانة', 'bi-tools', '#dc2626', '#fee2e2', 1, 40, NULL, '2026-07-18 00:47:54');

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
-- Table structure for table `consumable_units_ret`
--

CREATE TABLE `consumable_units_ret` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='واحدات قياس المستهلكات';

--
-- Dumping data for table `consumable_units_ret`
--

INSERT INTO `consumable_units_ret` (`id`, `name`, `is_active`, `created_by`, `created_at`) VALUES
(1, 'قطعة', 1, NULL, '2026-07-18 01:22:48'),
(2, 'كيلوغرام', 1, NULL, '2026-07-18 01:22:48'),
(3, 'غرام', 1, NULL, '2026-07-18 01:22:48'),
(4, 'لتر', 1, NULL, '2026-07-18 01:22:48'),
(5, 'مليلتر', 1, NULL, '2026-07-18 01:22:48'),
(6, 'متر', 1, NULL, '2026-07-18 01:22:48'),
(7, 'علبة', 1, NULL, '2026-07-18 01:22:48'),
(8, 'كيس', 1, NULL, '2026-07-18 01:22:48'),
(9, 'فاتورة', 1, NULL, '2026-07-18 01:22:48'),
(10, 'صندوق', 1, NULL, '2026-07-18 01:22:48'),
(11, 'كونة', 1, NULL, '2026-07-18 01:31:34'),
(12, 'ورقة', 1, 1, '2026-07-18 09:21:09');

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

--
-- Dumping data for table `currencies`
--

INSERT INTO `currencies` (`id`, `code`, `name`, `symbol`, `exchange_rate`, `cash_account_id`, `bank_account_id`, `is_base`, `status`, `updated_at`) VALUES
(1, 'USD', 'دولار أمريكي', '$', 1.0000, NULL, NULL, 1, 'active', NULL),
(2, 'SYP', 'ليرة سورية', 'ل.س', 133.0000, 1034, 1035, 0, 'active', '2026-08-23 14:25:08');

-- --------------------------------------------------------

--
-- Table structure for table `customers_ret`
--

CREATE TABLE `customers_ret` (
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

--
-- Dumping data for table `hr_employees_ret`
--

INSERT INTO `hr_employees_ret` (`id`, `full_name`, `position`, `department`, `phone`, `email`, `hire_date`, `salary_type`, `basic_salary`, `currency_id`, `payable_account_id`, `loan_account_id`, `bank_account`, `notes`, `monday_from`, `monday_to`, `tuesday_from`, `tuesday_to`, `wednesday_from`, `wednesday_to`, `thursday_from`, `thursday_to`, `friday_from`, `friday_to`, `saturday_from`, `saturday_to`, `sunday_from`, `sunday_to`, `work_schedule`, `overtime_multiplier`, `status`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'محمود المسلم', 'مدير مبيعات', 'sales', '', '', '2026-01-01', 'monthly', 700.00, 1, 1036, 1037, '6d44fa3043746da35823614cbb80dede', '', 9, 20, 9, 20, 9, 20, 9, 20, NULL, NULL, 9, 20, 9, 20, NULL, 1.5, 'active', 1, '2026-08-23 14:25:54', NULL),
(2, 'احمد كردي', 'بائع', 'sales', '', '', '2026-08-01', 'weekly', 9000.00, 2, 1038, 1039, '', '', 9, 20, 9, 20, 9, 20, 9, 20, NULL, NULL, 9, 20, 9, 20, NULL, 1.5, 'active', 1, '2026-08-23 14:26:29', NULL);

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
  `accrual_entry_id` int(11) DEFAULT NULL
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



INSERT INTO `invoice_account_settings_ret` (`id`, `setting_key`, `account_id`, `account_code`, `account_name`, `description`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'sales_revenue', 40, '4.1', 'إيرادات المبيعات', 'إيرادات فواتير البيع', NULL, '2026-06-24 12:50:38', '2026-07-30 03:21:34'),
(2, 'customer_receivable', 102, '1.1.3', 'ذمم العملاء', 'ذمم العملاء', NULL, '2026-06-24 12:50:38', '2026-07-30 03:21:34'),
(3, 'cogs', 500, '5.1.1', 'تكلفة البضاعة المباعة', 'تكلفة البضاعة المباعة', NULL, '2026-06-24 12:50:38', '2026-07-30 03:21:34'),
(4, 'finished_inventory', 104, '1.1.5', 'المخزون', 'مخزون المنتجات النهائية', NULL, '2026-06-24 12:50:38', '2026-07-30 03:21:34'),
(5, 'supplier_payable', 200, '2.1.1', 'ذمم الموردين', 'ذمم موردي المنتجات', NULL, '2026-06-24 12:50:38', '2026-07-30 03:21:34'),
(6, 'consumable_expense', 510, '5.2.1', 'مصاريف المستهلكات', 'مصاريف المستهلكات', NULL, '2026-06-24 12:50:38', '2026-07-30 03:21:34'),
(7, 'consumable_inventory', 105, '1.1.6', 'مخزون المستهلكات', 'مخزون المستهلكات', NULL, '2026-06-24 12:50:38', '2026-07-30 03:21:34'),
(8, 'consumable_supplier', 200, '2.1.1', 'ذمم الموردين', 'ذمم موردي المستهلكات', NULL, '2026-06-24 12:50:38', '2026-07-30 03:21:34'),
(9, 'salary_expense', 520, '5.3.1', 'رواتب وأجور', 'مصاريف الرواتب', NULL, '2026-06-24 12:50:38', '2026-07-30 03:21:34'),
(10, 'salary_payable', 201, '2.1.2', 'مستحقات الموظفين', 'مستحقات الموظفين', NULL, '2026-06-24 12:50:38', '2026-07-30 03:21:34'),
(11, 'employee_advance', 103, '1.1.4', 'سلف الموظفين', 'سلف الموظفين', NULL, '2026-06-24 12:50:38', '2026-07-30 03:21:34'),
(12, 'cash_usd', 1001, '1.1.1.001', 'صندوق دولار أمريكي', 'الصندوق الرئيسي USD', NULL, '2026-06-24 12:50:38', '2026-07-30 03:21:34'),
(13, 'bank_usd', 1011, '1.1.2.001', 'بنك دولار أمريكي', 'البنك الرئيسي USD', NULL, '2026-06-24 12:50:38', '2026-07-30 03:21:34'),
(14, 'forex_gain_loss', 532, '5.4.3', 'فروقات أسعار صرف', 'فروقات أسعار الصرف', NULL, '2026-06-24 12:50:38', NULL),
(15, 'shipping_payable', 200, '2.1.1', 'ذمم الموردين', 'shipping_payable', 1, '2026-06-28 10:02:05', '2026-07-30 03:21:34'),
(16, 'shipping_advance', 1017, '1.1.7', 'دفعات مقدمة للموردين', 'shipping_advance', 1, '2026-06-28 10:02:05', '2026-07-30 03:21:34'),
(17, 'shipping_expense', 514, '5.2.5', 'شحن ونقل', 'shipping_expense', 1, '2026-06-28 10:02:05', '2026-07-30 03:21:34'),
(18, 'tax_input_recoverable', 1020, '1.1.8', 'ضريبة مشتريات قابلة للاسترداد', 'tax_input_recoverable', 1, '2026-07-19 12:39:50', '2026-07-30 03:21:34'),
(19, 'purchase_discount', 1021, '4.2.1', 'خصم مشتريات تجاري مكتسب', 'خصم مشتريات تجاري مكتسب', 1, '2026-07-23 13:32:49', NULL),
(20, 'settlement_discount_income', 1022, '4.2.2', 'إيراد خصم تعجيل الدفع', 'إيراد خصم تعجيل الدفع', 1, '2026-07-23 13:32:49', NULL),
(21, 'sales_tax_payable', 1028, '2.1.4', 'ضريبة مبيعات مستحقة', 'ضريبة مبيعات مستحقة على العملاء', 1, '2026-07-27 11:21:50', '2026-07-30 03:21:34'),
(22, 'sales_discount_given', 1029, '5.2.6', 'خصومات مبيعات ممنوحة', 'خصومات ممنوحة على فواتير البيع', 1, '2026-07-27 11:21:51', '2026-07-30 03:21:34'),
(23, 'settlement_discount_expense', 1030, '5.2.7', 'خصم تعجيل استلام من العملاء المبيعات', 'خصم تعجيل استلام من العملاء المبيعات', 1, '2026-07-27 11:21:51', '2026-07-30 03:21:34'),
(24, 'customer_advance', 1031, '2.1.5', 'الدفعات المقدمة من العملاء', 'customer_advance', 1, '2026-07-28 10:14:36', '2026-07-30 03:21:34'),
(25, 'fx_gain', 1032, '4.2.3', 'أرباح فروقات الصرف', 'أرباح فروقات الصرف', 1, '2026-07-30 03:21:34', NULL),
(26, 'fx_loss', 532, '5.4.3', 'فروقات أسعار صرف', 'خسائر فروقات الصرف', 1, '2026-07-30 03:21:34', NULL),
(27, 'cash_syp', 1034, '1.1.1.002', 'صندوق ليرة سورية', 'صندوق SYP', NULL, '2026-08-23 14:25:08', NULL),
(28, 'bank_syp', 1035, '1.1.2.002', 'بنك ليرة سورية', 'بنك SYP', NULL, '2026-08-23 14:25:08', NULL);

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

--
-- Dumping data for table `modules`
--

INSERT INTO `modules` (`id`, `key`, `parent_key`, `label`, `icon`, `sort_order`, `is_active`, `theme_color`) VALUES
(1, 'sales', NULL, 'المبيعات', 'bi-bag', 25, 1, '#aa0909'),
(2, 'sales.invoices', 'sales', 'مبيعات المنتجات', 'bi-receipt', 30, 1, '#3b82f6'),
(4, 'sales.returns', 'sales', 'مرتجعات المبيعات', 'bi-arrow-return-right', 44, 1, '#3b82f6'),
(5, 'purchases', NULL, 'المشتريات', 'bi-cart3', 30, 1, '#1fab0d'),
(6, 'purchases.invoices', 'purchases', 'مشتريات المنتجات', 'bi-receipt', 6, 1, '#ec0909'),
(7, 'purchases.returns', 'purchases', 'مرتجعات المشتريات', 'bi-arrow-return-left', 8, 1, '#ec0909'),
(8, 'inventory', NULL, 'المخزون', 'bi-box-seam', 1, 1, '#0b1c98'),
(9, 'inventory.products', 'inventory', 'المنتجات', 'bi-tags', 2, 1, '#3b82f6'),
(10, 'inventory.warehouse', 'inventory', 'المستودع', 'bi-building', 3, 1, '#3b82f6'),
(11, 'inventory.movements', 'inventory', 'حركات المخزون', 'bi-arrow-left-right', 4, 1, '#3b82f6'),
(12, 'finance', NULL, 'المالية', 'bi-bank', 50, 1, '#0d94d3'),
(13, 'finance.accounts', 'finance', 'شجرة الحسابات', 'bi-diagram-3', 41, 1, '#3b82f6'),
(14, 'finance.journal', 'finance', 'القيود المحاسبية', 'bi-journal-bookmark', 42, 1, '#3b82f6'),
(15, 'finance.receipts', 'finance', 'سندات القبض', 'bi-cash-stack', 43, 1, '#3b82f6'),
(16, 'finance.expenses', 'expenses', 'إدارة المصاريف', 'bi-wallet2', 66, 1, '#3b82f6'),
(17, 'finance.reports', 'finance', 'التقارير المالية', 'bi-bar-chart-line', 450, 1, '#3b82f6'),
(19, 'crm.customers', 'sales', 'إدارة العملاء', 'bi-person-lines-fill', 220, 1, '#3b82f6'),
(20, 'crm.customers.statement', 'crm', 'كشف حساب العميل', 'bi-file-text', 52, 1, '#3b82f6'),
(22, 'crm.suppliers.statement', 'crm', 'كشف حساب المورد', 'bi-file-text', 54, 1, '#3b82f6'),
(23, 'admin', NULL, 'الإدارة', 'bi-shield-check', 90, 1, '#fa00e5'),
(24, 'admin.users', 'admin', 'المستخدمون', 'bi-person-gear', 91, 1, '#3b82f6'),
(25, 'admin.permissions', 'admin', 'الصلاحيات', 'bi-key', 92, 1, '#3b82f6'),
(26, 'admin.settings', 'admin', 'الإعدادات', 'bi-gear', 93, 1, '#3b82f6'),
(27, 'admin.branches', 'admin', 'الفروع', 'bi-building-check', 94, 1, '#3b82f6'),
(30, 'hr', NULL, 'الموارد البشرية', 'bi-people', 70, 1, '#00eeff'),
(31, 'hr.employees', 'hr', 'الموظفون', 'bi-person-badge', 56, 1, '#3b82f6'),
(32, 'hr.attendance', 'hr', 'الحضور والانصراف', 'bi-calendar-check', 57, 1, '#3b82f6'),
(33, 'hr.payroll', 'hr', 'الرواتب والأجور', 'bi-cash-stack', 58, 1, '#3b82f6'),
(34, 'hr.reports', 'hr', 'تقارير الموارد البشرية', 'bi-bar-chart', 59, 1, '#3b82f6'),
(35, 'expenses', NULL, 'المصاريف والمستهلكات', 'bi-wallet2', 60, 1, '#f09124'),
(36, 'inventory.consumables', 'expenses', 'المستهلكات', 'bi-cup-hot', 61, 1, '#3b82f6'),
(37, 'expenses.consumable_entries', 'expenses', 'حركة المستهلكات', 'bi-arrow-left-right', 65, 1, '#3b82f6'),
(38, 'production', NULL, 'الإنتاج', 'bi-gear-wide-connected', 80, 1, '#45484f'),
(39, 'production.raw_materials', 'production', 'المواد الأولية', 'bi-boxes', 81, 1, '#3b82f6'),
(40, 'production.operations', 'production', 'عمليات الإنتاج', 'bi-tools', 82, 1, '#3b82f6'),
(41, 'production.entries', 'production', 'سجل الإنتاج', 'bi-clipboard-data', 83, 1, '#3b82f6'),
(53, 'hr.holidays', 'hr', 'العطل الرسمية', 'bi-calendar-x', 74, 1, '#3b82f6'),
(54, 'inventory.internal_orders', 'inventory', 'الطلبات الداخلية', 'bi bi-signpost-split', 5, 1, '#3b82f6'),
(60, 'purchases.suppliers', 'purchases', 'إدارة الموردين', 'bi-person-lines-fill', 7, 1, '#ec0909'),
(64, 'finance.account_settings', 'finance', 'إعدادات الربط المحاسبي', 'bi-gear', 45, 1, '#3b82f6'),
(65, 'finance.currencies', 'finance', 'إدارة العملات', 'bi-currency-exchange', 46, 1, '#3b82f6'),
(66, 'finance.shipping_carriers', 'finance', 'شركات الشحن', 'bi-truck', 47, 1, '#3b82f6'),
(67, 'purchases.consumable_purchases', 'expenses', 'مشتريات المستهلكات', 'bi-cart-plus', 62, 1, '#ec0909'),
(68, 'expenses.consumable_issues', 'expenses', 'أمر صرف استهلاكي', 'bi-box-arrow-up', 63, 1, '#3b82f6'),
(69, 'expenses.warehouse', 'expenses', 'مستودعات المستهلكات', 'bi-building', 64, 1, '#3b82f6'),
(70, 'finance.payments', 'finance', 'سندات الدفع', 'bi-cash-coin', 440, 1, '#3b82f6'),
(71, 'expenses.consumable_transfers', 'expenses', 'مناقلة بين المستودعات', 'bi-signpost-split', 660, 1, '#3b82f6'),
(72, 'purchases.reports', 'purchases', 'تقارير المشتريات', 'bi-bar-chart', 10, 1, '#ec0909'),
(73, 'purchases.orders', 'purchases', 'أوامر الشراء / طلبات عروض الأسعار', 'bi-file-earmark-text', 9, 1, '#ec0909'),
(74, 'sales.reports', 'sales', 'تقارير المبيعات', 'bi-bar-chart', 67, 1, '#3b82f6'),
(75, 'sales.orders', 'sales', 'أوامر البيع / عروض الأسعار', 'bi-file-earmark-text', 443, 1, '#3b82f6'),
(78, 'finance.treasury', 'finance', 'الصندوق', 'bi-safe', 15, 1, '#3b82f6'),
(79, 'finance.taxes', 'finance', 'الضرائب والرسوم', 'bi-receipt-cutoff', 20, 1, '#3b82f6'),
(81, 'admin.section_colors', 'admin', 'ألوان الأقسام', 'bi-palette', 95, 1, '#3b82f6'),
(82, 'inventory.reports', 'inventory', 'تقارير المنتجات', 'bi-bar-chart-line', 35, 1, '#3b82f6'),
(83, 'admin.opening_balances', 'admin', 'الأرصدة الافتتاحية', 'bi-clock-history', 96, 1, '#3b82f6'),
(84, 'admin.branch_add', 'admin', 'إنشاء فرع جديد', 'bi-building-add', 97, 1, '#3b82f6'),
(85, 'inventory.import_products', 'inventory', 'استيراد المنتجات', 'bi-upload', 50, 1, '#3b82f6');

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

--
-- Dumping data for table `product_categories_ret`
--

INSERT INTO `product_categories_ret` (`id`, `name`, `parent_id`, `description`, `is_active`, `created_at`) VALUES
(1, 'طقم صبياني', 1, 'طقم قطعتين / طقم ثلاث قطع / توينز', 1, '2026-06-11 11:44:44'),
(2, 'بنطلون صبياني', 1, 'بنطلون صبياني', 1, '2026-06-11 11:44:44'),
(3, 'بيجاما صبياني', 1, 'بيجاما صبياني', 1, '2026-06-11 11:44:44'),
(4, 'بلوزة صبياني', 1, 'بلوزة صبياني', 1, '2026-06-11 11:44:44'),
(5, 'طقم بناتي', 2, 'طقم قطعتين / طقم ثلاث قطع / توينز', 1, '2026-06-11 11:44:44'),
(6, 'بنطلون بناتي', 2, 'بنطلون بناتي', 1, '2026-06-11 11:44:44'),
(7, 'بيجاما بناتي', 2, 'بيجاما بناتي', 1, '2026-06-11 11:44:44'),
(8, 'بلوزة بناتي', 2, 'بلوزة بناتي', 1, '2026-06-11 11:44:44'),
(9, 'فستان بناتي', 2, 'طقم قطعتين / طقم ثلاث قطع / توينز', 1, '2026-06-11 11:44:44');

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

--
-- Dumping data for table `product_colors_ret`
--

INSERT INTO `product_colors_ret` (`id`, `name`, `hex_code`, `is_active`, `created_at`) VALUES
(1, 'أبيض', '#FFFFFF', 1, '2026-06-14 08:59:05'),
(2, 'أخضر', '#22C55E', 1, '2026-06-14 08:59:05'),
(3, 'أخضر مائي', '#2DD4BF', 1, '2026-06-14 08:59:05'),
(4, 'أخضر متوسط', '#16A34A', 1, '2026-06-14 08:59:05'),
(5, 'أزرق', '#3B82F6', 1, '2026-06-14 08:59:05'),
(6, 'أزرق ثلجي', '#BFDBFE', 1, '2026-06-14 08:59:05'),
(7, 'أزرق داكن', '#1E3A8A', 1, '2026-06-14 08:59:05'),
(8, 'أزرق فاتح', '#93C5FD', 1, '2026-06-14 08:59:05'),
(9, 'أزرق وسط', '#2563EB', 1, '2026-06-14 08:59:05'),
(10, 'أسود', '#1A1A1A', 1, '2026-06-14 08:59:05'),
(11, 'أصفر', '#FACC15', 1, '2026-06-14 08:59:05'),
(12, 'أورانج', '#F97316', 1, '2026-06-14 08:59:05'),
(13, 'بترولي', '#0F4C5C', 1, '2026-06-14 08:59:05'),
(14, 'بني', '#92400E', 1, '2026-06-14 08:59:05'),
(15, 'بني غامق', '#5C3317', 1, '2026-06-14 08:59:05'),
(16, 'بني فاتح', '#B7794B', 1, '2026-06-14 08:59:05'),
(17, 'بيج', '#D6C2A5', 1, '2026-06-14 08:59:05'),
(18, 'بيج فاتح', '#D4B896', 1, '2026-06-14 08:59:05'),
(19, 'ثلجي', '#EAF4FF', 1, '2026-06-14 08:59:05'),
(20, 'خمري', '#7F1D1D', 1, '2026-06-14 08:59:05'),
(21, 'ديرتي', '#8B7355', 1, '2026-06-14 08:59:05'),
(22, 'ذهبي', '#D4AF37', 1, '2026-06-14 08:59:05'),
(23, 'رمادي', '#6B7280', 1, '2026-06-14 08:59:05'),
(24, 'زهري', '#EC4899', 1, '2026-06-14 08:59:05'),
(25, 'زيتي', '#4D7C0F', 1, '2026-06-14 08:59:05'),
(26, 'سكري', '#FFF4D6', 1, '2026-06-14 08:59:05'),
(27, 'سمني', '#B89B72', 1, '2026-06-14 08:59:05'),
(28, 'شوكو', '#5C3317', 1, '2026-06-14 08:59:05'),
(29, 'فستقي', '#A3E635', 1, '2026-06-14 08:59:05'),
(30, 'فضي', '#A8A8A8', 1, '2026-06-14 08:59:05'),
(31, 'قرميدي', '#B45309', 1, '2026-06-14 08:59:05'),
(32, 'كحلي', '#172554', 1, '2026-06-14 08:59:05'),
(33, 'كرزي', '#991B1B', 1, '2026-06-14 08:59:05'),
(34, 'مشمشمي', '#FDBA74', 1, '2026-06-14 08:59:05'),
(35, 'موف', '#8B5CF6', 1, '2026-06-14 08:59:05');

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
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قياسات كل موديل — سعر مستقل لكل قياس';

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

--
-- Dumping data for table `product_suppliers_ret`
--

INSERT INTO `product_suppliers_ret` (`id`, `account_id`, `prepaid_account_id`, `name`, `contact_person`, `phone`, `email`, `address`, `tax_number`, `type`, `supplier_type`, `status`, `credit_limit`, `discount_percentage`, `notes`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 1040, 1041, 'البيهس', 'علي حاج خلف', '0997665243', '', 'حلب المواصلات القديمة', '', 'manufacturer', 'product', 'active', 0.00, 0.00, '', 1, NULL, '2026-08-23 14:26:56', NULL),
(2, 1042, 1043, 'توب مان', 'سامر اغيورلي', '933214852', '', 'حلب الفيض', '', 'manufacturer', 'product', 'active', 0.00, 0.00, '', 1, NULL, '2026-08-23 14:27:19', NULL),
(3, 1044, 1045, 'خضرو', 'عبدالله خضرو', '+905392244222', '', 'حلب  رعاية الشباب', '', 'manufacturer', 'product', 'active', 0.00, 0.00, '', 1, NULL, '2026-08-23 14:27:34', NULL),
(4, 1046, 1047, 'اوسكار', 'اوسكار', '988143707', '', 'دمشق الحريقة', '', 'manufacturer', 'product', 'active', 0.00, 0.00, '', 1, NULL, '2026-08-23 14:27:48', NULL),
(5, 1048, 1049, 'بلاتين', 'محمد عبد الكافي', '958749377', '', 'حلب العرقوب', '', 'manufacturer', 'product', 'active', 0.00, 0.00, '', 1, NULL, '2026-08-23 14:28:07', NULL),
(6, 1050, 1051, 'أبو زلام', 'صبحي أبو زلام', '955777147', '', 'حلب  رعاية الشباب', '', 'manufacturer', 'product', 'active', 0.00, 0.00, '', 1, NULL, '2026-08-23 14:28:27', NULL),
(7, 1052, 1053, 'عمار قنبور', 'عمار قنبور', '955718764', '', 'حلب  رعاية الشباب', '', 'wholesaler', 'product', 'active', 0.00, 0.00, '', 1, NULL, '2026-08-23 14:28:48', NULL),
(8, 1054, 1055, 'B LOVE', 'يامن قاظان', '966501701', '', 'حلب العرقوب', '', 'manufacturer', 'product', 'active', 0.00, 0.00, '', 1, NULL, '2026-08-23 14:29:04', NULL),
(9, 1056, 1057, 'كربون', 'ياسر العويد', '944663993', '', 'حلب العرقوب', '', 'manufacturer', 'product', 'active', 0.00, 0.00, '', 1, NULL, '2026-08-23 14:29:19', NULL),
(10, 1058, 1059, 'كريم بدر', 'كريم بدر', '932505740', '', 'حلب الجابرية', '', 'manufacturer', 'product', 'active', 0.00, 0.00, '', 1, NULL, '2026-08-23 14:29:34', NULL),
(11, 1060, 1061, 'نور صباغ', 'نور صباغ', '944790985', '', 'حلب  رعاية الشباب', '', 'wholesaler', 'product', 'active', 0.00, 0.00, '', 1, NULL, '2026-08-23 14:29:48', NULL);

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
  `discount_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
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

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `full_name`, `email`, `password`, `role`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES
(1, 'admin', 'مدير النظام-مهندس منجد', 'admin@fatorize.com', '$2y$10$.5AOpMJu2MAYJlz3RTQnVONC.nMgIKfyN51QtmGWbD3cSR.DwCw12', 'admin', 1, NULL, '2026-05-20 11:57:15', '2026-05-20 12:12:27');

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
(26, 1, 1, 'sales.returns', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
(27, 1, 1, 'hr', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-21 10:53:38', NULL),
(28, 1, 1, 'hr.employees', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-21 10:53:38', NULL),
(29, 1, 1, 'hr.attendance', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-21 10:53:38', NULL),
(30, 1, 1, 'hr.payroll', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-21 10:53:38', NULL),
(31, 1, 1, 'hr.reports', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-21 10:53:38', NULL),
(32, 1, 1, 'expenses', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-21 10:53:38', NULL),
(33, 1, 1, 'inventory.consumables', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-21 10:53:38', '2026-06-21 06:01:22'),
(34, 1, 1, 'expenses.consumable_entries', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-21 10:53:38', NULL),
(35, 1, 1, 'hr.holidays', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-08 13:00:47', NULL),
(36, 1, 1, 'inventory.internal_orders', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-11 05:52:48', NULL),
(37, 1, 1, 'sales.customers', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-17 12:44:53', NULL),
(38, 1, 1, 'purchases.suppliers', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-17 12:44:53', NULL),
(39, 1, 1, 'inventory.consumable_issues', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-21 05:53:36', NULL),
(40, 1, 1, 'inventory.consumable_purchases', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-21 06:17:59', NULL),
(41, 1, 1, 'finance.account_settings', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-24 12:04:33', NULL),
(42, 1, 1, 'finance.currencies', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-24 12:24:15', NULL),
(43, 1, 1, 'finance.shipping_carriers', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-28 09:39:11', NULL),
(44, 1, 1, 'inventory.reports', 1, 0, 0, 0, 0, 1, 1, NULL, '2026-08-05 08:30:39', NULL);

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

--
-- Dumping data for table `warehouses_ret`
--

INSERT INTO `warehouses_ret` (`id`, `code`, `name`, `warehouse_type`, `address`, `manager_id`, `is_active`, `created_at`) VALUES
(1, 'RET-WH-01', 'مستودع المنتجات الرئيسي', 'products', 'حلب السبع بحرات', NULL, 1, '2026-08-23 13:17:03');

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
-- Indexes for table `consumable_categories_ret`
--
ALTER TABLE `consumable_categories_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_name` (`name`);

--
-- Indexes for table `consumable_departments_ret`
--
ALTER TABLE `consumable_departments_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_name` (`name`);

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
-- Indexes for table `consumable_issue_items_ret`
--
ALTER TABLE `consumable_issue_items_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_issue_id` (`issue_id`),
  ADD KEY `idx_item_id` (`item_id`),
  ADD KEY `fk_cii_movement` (`movement_id`),
  ADD KEY `fk_cii_packaging_ret` (`packaging_id`);

--
-- Indexes for table `consumable_items_ret`
--
ALTER TABLE `consumable_items_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ci_currency` (`currency_id`),
  ADD KEY `fk_consumable_items_category_ret` (`category_id`),
  ADD KEY `fk_consumable_items_unit_ret` (`unit_id`);

--
-- Indexes for table `consumable_item_packagings_ret`
--
ALTER TABLE `consumable_item_packagings_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_item_pkg` (`item_id`,`name`);

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
-- Indexes for table `consumable_purchase_items_ret`
--
ALTER TABLE `consumable_purchase_items_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_purchase_id` (`purchase_id`),
  ADD KEY `idx_item_id` (`item_id`),
  ADD KEY `idx_movement_id` (`movement_id`),
  ADD KEY `fk_cpi_packaging_ret` (`packaging_id`);

--
-- Indexes for table `consumable_returns_ret`
--
ALTER TABLE `consumable_returns_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_cr_issue_ret` (`issue_id`);

--
-- Indexes for table `consumable_return_items_ret`
--
ALTER TABLE `consumable_return_items_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_cri_return_ret` (`return_id`),
  ADD KEY `fk_cri_issue_item_ret` (`issue_item_id`);

--
-- Indexes for table `consumable_stock_ret`
--
ALTER TABLE `consumable_stock_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_item_warehouse` (`item_id`,`warehouse_id`),
  ADD KEY `idx_item_id` (`item_id`),
  ADD KEY `idx_warehouse_id` (`warehouse_id`);

--
-- Indexes for table `consumable_transfers_ret`
--
ALTER TABLE `consumable_transfers_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ctr_from_wh` (`from_warehouse_id`),
  ADD KEY `fk_ctr_to_wh` (`to_warehouse_id`);

--
-- Indexes for table `consumable_transfer_items_ret`
--
ALTER TABLE `consumable_transfer_items_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ctri_transfer` (`transfer_id`);

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
-- Indexes for table `customers_ret`
--
ALTER TABLE `customers_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_account_id` (`account_id`);

--
-- Indexes for table `exchange_rates_ret`
--
ALTER TABLE `exchange_rates_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_currency_date` (`currency_from`,`currency_to`,`rate_date`),
  ADD KEY `idx_rate_date` (`rate_date`);

--
-- Indexes for table `expenses_ret`
--
ALTER TABLE `expenses_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_expense_date` (`expense_date`),
  ADD KEY `idx_expense_acct` (`expense_account_id`);

--
-- Indexes for table `hr_attendance_ret`
--
ALTER TABLE `hr_attendance_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`employee_id`,`attendance_date`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_date` (`attendance_date`);

--
-- Indexes for table `hr_bonuses_ret`
--
ALTER TABLE `hr_bonuses_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_date` (`bonus_date`);

--
-- Indexes for table `hr_employees_ret`
--
ALTER TABLE `hr_employees_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_department` (`department`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_currency_id` (`currency_id`);

--
-- Indexes for table `hr_loans_ret`
--
ALTER TABLE `hr_loans_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `hr_payroll_ret`
--
ALTER TABLE `hr_payroll_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_emp_period` (`employee_id`,`period_from`),
  ADD KEY `idx_month` (`payroll_month`),
  ADD KEY `idx_status` (`payment_status`);

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
-- Indexes for table `inventory_movements_ret`
--
ALTER TABLE `inventory_movements_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `movement_number` (`movement_number`),
  ADD KEY `idx_movement_type` (`movement_type`),
  ADD KEY `idx_reference` (`reference_type`,`reference_id`),
  ADD KEY `idx_warehouse_id` (`warehouse_id`);

--
-- Indexes for table `inventory_movement_details_ret`
--
ALTER TABLE `inventory_movement_details_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_movement_id` (`movement_id`),
  ADD KEY `idx_variant_id` (`variant_id`);

--
-- Indexes for table `invoice_account_settings_ret`
--
ALTER TABLE `invoice_account_settings_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

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
-- Indexes for table `journal_entry_items_ret`
--
ALTER TABLE `journal_entry_items_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_journal_entry_id` (`journal_entry_id`),
  ADD KEY `idx_account_id` (`account_id`),
  ADD KEY `fk_ji_currency_id` (`currency_id`);

--
-- Indexes for table `modules`
--
ALTER TABLE `modules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `key` (`key`),
  ADD KEY `idx_parent_key` (`parent_key`),
  ADD KEY `idx_key` (`key`);

--
-- Indexes for table `notifications_ret`
--
ALTER TABLE `notifications_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_is_read` (`is_read`);

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
-- Indexes for table `product_categories_ret`
--
ALTER TABLE `product_categories_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `idx_parent_id` (`parent_id`);

--
-- Indexes for table `product_colors_ret`
--
ALTER TABLE `product_colors_ret`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `product_sizes_ret`
--
ALTER TABLE `product_sizes_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_product_size` (`product_id`,`size`,`age_type`),
  ADD KEY `idx_product_id` (`product_id`);

--
-- Indexes for table `product_suppliers_ret`
--
ALTER TABLE `product_suppliers_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `product_variants_ret`
--
ALTER TABLE `product_variants_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_size_color` (`size_id`,`color_id`),
  ADD KEY `idx_product_id` (`product_id`),
  ADD KEY `idx_barcode` (`barcode`);

--
-- Indexes for table `public_holidays_ret`
--
ALTER TABLE `public_holidays_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_date` (`holiday_date`),
  ADD KEY `idx_recurring` (`is_recurring`);

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
-- Indexes for table `purchase_payments_ret`
--
ALTER TABLE `purchase_payments_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `payment_number` (`payment_number`),
  ADD KEY `idx_supplier` (`supplier_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_date` (`payment_date`);

--
-- Indexes for table `purchase_payment_invoices_ret`
--
ALTER TABLE `purchase_payment_invoices_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payment` (`payment_id`),
  ADD KEY `idx_purchase` (`purchase_id`);

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
-- Indexes for table `purchase_return_items_ret`
--
ALTER TABLE `purchase_return_items_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_return_id` (`return_id`),
  ADD KEY `idx_purchase_item_id` (`purchase_item_id`);

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
-- Indexes for table `receipt_invoices_ret`
--
ALTER TABLE `receipt_invoices_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_receipt_invoice` (`receipt_id`,`invoice_id`),
  ADD KEY `idx_invoice_id` (`invoice_id`);

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
-- Indexes for table `warehouses_ret`
--
ALTER TABLE `warehouses_ret`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

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
-- AUTO_INCREMENT for table `account_charts_ret`
--
ALTER TABLE `account_charts_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1062;

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `consumable_categories_ret`
--
ALTER TABLE `consumable_categories_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `consumable_departments_ret`
--
ALTER TABLE `consumable_departments_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_issues_ret`
--
ALTER TABLE `consumable_issues_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_issue_items_ret`
--
ALTER TABLE `consumable_issue_items_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_items_ret`
--
ALTER TABLE `consumable_items_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_item_packagings_ret`
--
ALTER TABLE `consumable_item_packagings_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_movements_ret`
--
ALTER TABLE `consumable_movements_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_purchases_ret`
--
ALTER TABLE `consumable_purchases_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_purchase_items_ret`
--
ALTER TABLE `consumable_purchase_items_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_returns_ret`
--
ALTER TABLE `consumable_returns_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_return_items_ret`
--
ALTER TABLE `consumable_return_items_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_stock_ret`
--
ALTER TABLE `consumable_stock_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_transfers_ret`
--
ALTER TABLE `consumable_transfers_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_transfer_items_ret`
--
ALTER TABLE `consumable_transfer_items_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumable_units_ret`
--
ALTER TABLE `consumable_units_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `currencies`
--
ALTER TABLE `currencies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `customers_ret`
--
ALTER TABLE `customers_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exchange_rates_ret`
--
ALTER TABLE `exchange_rates_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expenses_ret`
--
ALTER TABLE `expenses_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_attendance_ret`
--
ALTER TABLE `hr_attendance_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_bonuses_ret`
--
ALTER TABLE `hr_bonuses_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_employees_ret`
--
ALTER TABLE `hr_employees_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_loans_ret`
--
ALTER TABLE `hr_loans_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_payroll_ret`
--
ALTER TABLE `hr_payroll_ret`
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
-- AUTO_INCREMENT for table `inventory_movements_ret`
--
ALTER TABLE `inventory_movements_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1287;

--
-- AUTO_INCREMENT for table `inventory_movement_details_ret`
--
ALTER TABLE `inventory_movement_details_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1287;

--
-- AUTO_INCREMENT for table `invoice_account_settings_ret`
--
ALTER TABLE `invoice_account_settings_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `journal_entries_ret`
--
ALTER TABLE `journal_entries_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_entry_items_ret`
--
ALTER TABLE `journal_entry_items_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `modules`
--
ALTER TABLE `modules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=86;

--
-- AUTO_INCREMENT for table `notifications_ret`
--
ALTER TABLE `notifications_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products_ret`
--
ALTER TABLE `products_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=96;

--
-- AUTO_INCREMENT for table `product_categories_ret`
--
ALTER TABLE `product_categories_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `product_colors_ret`
--
ALTER TABLE `product_colors_ret`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `product_sizes_ret`
--
ALTER TABLE `product_sizes_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=687;

--
-- AUTO_INCREMENT for table `product_suppliers_ret`
--
ALTER TABLE `product_suppliers_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `product_variants_ret`
--
ALTER TABLE `product_variants_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1337;

--
-- AUTO_INCREMENT for table `public_holidays_ret`
--
ALTER TABLE `public_holidays_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchases_ret`
--
ALTER TABLE `purchases_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_items_ret`
--
ALTER TABLE `purchase_items_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_payments_ret`
--
ALTER TABLE `purchase_payments_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_payment_invoices_ret`
--
ALTER TABLE `purchase_payment_invoices_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_returns_ret`
--
ALTER TABLE `purchase_returns_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_return_items_ret`
--
ALTER TABLE `purchase_return_items_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `receipts_ret`
--
ALTER TABLE `receipts_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `receipt_invoices_ret`
--
ALTER TABLE `receipt_invoices_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_invoices_ret`
--
ALTER TABLE `sales_invoices_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_invoice_items_ret`
--
ALTER TABLE `sales_invoice_items_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_returns_ret`
--
ALTER TABLE `sales_returns_ret`
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
-- AUTO_INCREMENT for table `tax_types_ret`
--
ALTER TABLE `tax_types_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `user_activities`
--
ALTER TABLE `user_activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_permissions`
--
ALTER TABLE `user_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `user_tab_order`
--
ALTER TABLE `user_tab_order`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `warehouses_ret`
--
ALTER TABLE `warehouses_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `warehouse_items_ret`
--
ALTER TABLE `warehouse_items_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1337;

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
