-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Aug 23, 2026 at 02:10 PM
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
(1, 1093, 1094, 'البيهس', 'علي حاج خلف', '933214852', '', 'حلب الفيض', '', 'manufacturer', 'product', 'active', 0.00, 0.00, '', 1, NULL, '2026-08-23 14:10:06', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `product_suppliers_ret`
--
ALTER TABLE `product_suppliers_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `product_suppliers_ret`
--
ALTER TABLE `product_suppliers_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
