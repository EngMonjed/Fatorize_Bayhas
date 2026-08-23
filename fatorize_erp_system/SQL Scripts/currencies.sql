-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Aug 23, 2026 at 01:20 PM
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
(2, 'SYP', 'ليرة سورية', 'ل.س', 133.0000, 1067, 1068, 0, 'active', '2026-08-23 13:20:37');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `currencies`
--
ALTER TABLE `currencies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `currencies`
--
ALTER TABLE `currencies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
