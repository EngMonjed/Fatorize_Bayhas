-- --------------------------------------------------------
-- Host:                         127.0.0.1
-- Server version:               8.4.3 - MySQL Community Server - GPL
-- Server OS:                    Win64
-- HeidiSQL Version:             12.8.0.6908
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
-- Dumping structure for table fatorize_master.currencies
CREATE TABLE IF NOT EXISTS `currencies` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'كود ISO 4217، مثال: USD, EUR, SYP',
  `name` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `symbol` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قاموس عملات مصغّر — لدعم reporting_currency_id فقط، بدون أسعار صرف';

-- Dumping data for table fatorize_master.currencies: ~6 rows (approximately)
INSERT INTO `currencies` (`id`, `code`, `name`, `symbol`) VALUES
	(1, 'USD', 'دولار أمريكي', '$'),
	(2, 'EUR', 'يورو', '€'),
	(3, 'SYP', 'ليرة سورية', 'ل.س'),
	(4, 'TRY', 'ليرة تركية', '₺'),
	(5, 'SAR', 'ريال سعودي', 'ر.س'),
	(6, 'AED', 'درهم إماراتي', 'د.إ');

-- Dumping structure for table fatorize_master.purchase_payments_ret
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table fatorize_master.purchase_payments_ret: ~0 rows (approximately)

-- Dumping structure for table fatorize_master.purchase_payment_invoices_ret
CREATE TABLE IF NOT EXISTS `purchase_payment_invoices_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `payment_id` int NOT NULL,
  `purchase_id` int NOT NULL,
  `allocated_amount` decimal(18,4) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_payment` (`payment_id`),
  KEY `idx_purchase` (`purchase_id`),
  CONSTRAINT `fk_ppi_payment_ret` FOREIGN KEY (`payment_id`) REFERENCES `purchase_payments_ret` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table fatorize_master.purchase_payment_invoices_ret: ~0 rows (approximately)

-- Dumping structure for table fatorize_master.tenants
CREATE TABLE IF NOT EXISTS `tenants` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `company_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'اسم الشركة/المصنع الظاهر بالواجهة',
  `subdomain` varchar(63) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'الجزء الأول من الرابط، مثال: bayhas → bayhas.fatorize.com',
  `tenant_type` enum('factory','shop','both') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'shop' COMMENT 'يحدد أي لوحة تحكم افتراضية تُعرض: تصنيع/مبيعات/الاثنين',
  `reporting_currency_id` int unsigned NOT NULL,
  `db_host` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'localhost',
  `db_name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `db_user` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `db_pass_enc` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'كلمة مرور قاعدة بيانات العميل، مُشفّرة (AES-256-CBC) — لا تُخزَّن كنص صريح',
  `status` enum('trial','active','suspended','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'trial',
  `plan` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'basic' COMMENT 'خطة الاشتراك — أساس لنظام فوترة لاحق',
  `trial_ends_at` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_subdomain` (`subdomain`),
  KEY `idx_status` (`status`),
  KEY `fk_tenants_reporting_currency` (`reporting_currency_id`),
  CONSTRAINT `fk_tenants_reporting_currency` FOREIGN KEY (`reporting_currency_id`) REFERENCES `currencies` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل مركزي لكل الشركات المشتركة بالنظام (SaaS tenant registry)';

-- Dumping data for table fatorize_master.tenants: ~1 rows (approximately)
INSERT INTO `tenants` (`id`, `company_name`, `subdomain`, `tenant_type`, `reporting_currency_id`, `db_host`, `db_name`, `db_user`, `db_pass_enc`, `status`, `plan`, `trial_ends_at`, `created_at`, `updated_at`) VALUES
	(4, 'Bayhas', 'bayhas', 'both', 1, 'localhost', 'bayhas_local', 'root', 'AlBir7OI86kQhIA6BmEekI74gNvV5/Hn1Ypo9TA2wYE=', 'active', 'basic', NULL, '2026-07-15 17:12:05', '2026-07-18 16:48:49');

-- Dumping structure for procedure fatorize_master._check_db_before_payments_setup
DELIMITER //
CREATE PROCEDURE `_check_db_before_payments_setup`()
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'modules') = 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'قاعدة البيانات المختارة غلط — ماكو جدول modules هون. اختر قاعدة بيانات الشركة (مش fatorize_master) وأعد المحاولة.';
    END IF;
END//
DELIMITER ;

-- Dumping structure for trigger fatorize_master.trg_tenants_freeze_reporting_currency
SET @OLDTMP_SQL_MODE=@@SQL_MODE, SQL_MODE='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
DELIMITER //
CREATE TRIGGER `trg_tenants_freeze_reporting_currency` BEFORE UPDATE ON `tenants` FOR EACH ROW BEGIN
    IF OLD.reporting_currency_id IS NOT NULL
       AND NEW.reporting_currency_id <> OLD.reporting_currency_id THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'العملة المرجعية مجمّدة بعد إنشاء الشركة — لا يمكن تغييرها';
    END IF;
END//
DELIMITER ;
SET SQL_MODE=@OLDTMP_SQL_MODE;

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
