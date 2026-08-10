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
) ENGINE=InnoDB AUTO_INCREMENT=1064 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='شجرة الحسابات';

-- Dumping data for table bayhas_local.account_charts_ret: ~93 rows (approximately)
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
	(40, '4.1', 'إيرادات المبيعات', NULL, 4, 'revenue', 'operating', 0.00, 0.00, 1, 1.0000, 2, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-08-06 15:14:39'),
	(41, '4.2', 'إيرادات أخرى', NULL, 4, 'revenue', 'operating', 0.00, 0.00, 1, 1.0000, 2, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
	(50, '5.1', 'تكلفة المبيعات', NULL, 5, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 2, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
	(51, '5.2', 'مصاريف التشغيل', NULL, 5, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 2, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
	(52, '5.3', 'مصاريف الموظفين', NULL, 5, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 2, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
	(53, '5.4', 'مصاريف إدارية وعمومية', NULL, 5, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 2, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
	(100, '1.1.1', 'النقدية والصناديق', NULL, 10, 'asset', 'excluded', 0.00, 0.00, 1, 1.0000, 3, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
	(101, '1.1.2', 'البنوك', NULL, 10, 'asset', 'excluded', 0.00, 0.00, 1, 1.0000, 3, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
	(102, '1.1.3', 'ذمم العملاء', NULL, 10, 'asset', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-08-06 15:15:05'),
	(103, '1.1.4', 'سلف الموظفين', NULL, 10, 'asset', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
	(104, '1.1.5', 'المخزون', NULL, 10, 'asset', 'operating', 285.00, 285.00, 1, 1.0000, 3, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-08-09 11:33:07'),
	(105, '1.1.6', 'مخزون المستهلكات', NULL, 10, 'asset', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-08-06 15:14:57'),
	(200, '2.1.1', 'ذمم الموردين', NULL, 20, 'liability', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-08-06 15:14:58'),
	(201, '2.1.2', 'مستحقات الموظفين', NULL, 20, 'liability', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
	(202, '2.1.3', 'ضرائب مستحقة', NULL, 20, 'liability', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
	(500, '5.1.1', 'تكلفة البضاعة المباعة', NULL, 50, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-08-06 15:15:02'),
	(510, '5.2.1', 'مصاريف المستهلكات', NULL, 51, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-08-06 15:14:55'),
	(511, '5.2.2', 'إيجار المحل', NULL, 51, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 15:20:58'),
	(512, '5.2.3', 'كهرباء وماء', NULL, 51, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-08-06 15:14:59'),
	(513, '5.2.4', 'صيانة وإصلاح', NULL, 51, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
	(514, '5.2.5', 'شحن ونقل', NULL, 51, 'expense', 'operating', 62.50, 62.50, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-08-08 02:28:06'),
	(520, '5.3.1', 'رواتب وأجور', NULL, 52, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
	(521, '5.3.2', 'مكافآت وحوافز', NULL, 52, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
	(530, '5.4.1', 'مصاريف إدارية عامة', NULL, 53, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
	(531, '5.4.2', 'قرطاسية ومكتبية', NULL, 53, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
	(532, '5.4.3', 'فروقات أسعار صرف', NULL, 53, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 1, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
	(1001, '1.1.1.001', 'صندوق دولار أمريكي', NULL, 100, 'asset', 'excluded', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, 1, '2026-06-24 12:50:38', '2026-08-08 02:09:59'),
	(1002, '1.1.1.002', 'صندوق ليرة سورية', NULL, 100, 'asset', 'excluded', 0.00, 0.00, 4, 1.0000, 4, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-08-08 02:09:55'),
	(1003, '1.1.1.003', 'صندوق ليرة تركية', NULL, 100, 'asset', 'excluded', -3500.00, -73.79, 2, 1.0000, 4, 1, 0, NULL, 1, '2026-06-24 12:50:38', '2026-08-08 02:28:06'),
	(1011, '1.1.2.001', 'بنك دولار أمريكي', NULL, 101, 'asset', 'excluded', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
	(1012, '1.1.2.002', 'بنك ليرة سورية', NULL, 101, 'asset', 'excluded', 0.00, 0.00, 4, 1.0000, 4, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
	(1013, '1.1.2.003', 'بنك ليرة تركية', NULL, 101, 'asset', 'excluded', 0.00, 0.00, 2, 1.0000, 4, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
	(1014, '1.1.2.004', 'بنك يورو', NULL, 101, 'asset', 'excluded', 0.00, 0.00, 3, 1.0000, 4, 1, 0, NULL, NULL, '2026-06-24 12:50:38', '2026-07-30 14:36:38'),
	(1015, '1.1.1.004', 'صندوق ريال سعودي', NULL, 100, 'asset', 'excluded', 0.00, 0.00, 5, 1.0000, 4, 1, 0, NULL, NULL, '2026-06-24 13:59:40', '2026-07-30 14:36:38'),
	(1016, '1.1.2.005', 'بنك ريال سعودي', NULL, 101, 'asset', 'excluded', 0.00, 0.00, 5, 1.0000, 4, 1, 0, NULL, NULL, '2026-06-24 13:59:40', '2026-07-30 14:36:38'),
	(1017, '1.1.7', 'دفعات مقدمة للموردين', NULL, 10, 'asset', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-06-28 10:01:44', '2026-07-30 14:36:38'),
	(1018, '2.1.1.001', 'ذمم نهر العطاء', NULL, 200, 'liability', 'operating', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-06-28 10:02:43', '2026-07-30 14:36:38'),
	(1019, '1.1.7.001', 'دفعات مقدمة — نهر العطاء', NULL, 1017, 'asset', 'operating', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-06-28 10:02:43', '2026-07-30 14:36:38'),
	(1020, '1.1.8', 'ضريبة مشتريات قابلة للاسترداد', '', 10, 'asset', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, 1, NULL, '2026-07-19 12:37:03', '2026-07-30 14:36:38'),
	(1021, '4.2.1', 'خصم مشتريات تجاري مكتسب', NULL, 41, 'revenue', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-07-23 09:38:50', '2026-07-30 14:36:38'),
	(1022, '4.2.2', 'إيراد خصم تعجيل الدفع', NULL, 41, 'revenue', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-07-23 09:38:50', '2026-07-30 14:36:38'),
	(1023, '1.1.1.005', 'صندوق اليورو', '', 100, 'asset', 'excluded', -75.00, -62.50, 3, 1.0000, 4, 1, 0, 1, NULL, '2026-07-23 13:24:55', '2026-08-08 02:28:06'),
	(1024, '2.1.1.002', 'ذمم بيهس', NULL, 200, 'liability', 'operating', -194.00, -194.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-07-23 15:23:04', '2026-08-09 11:33:07'),
	(1025, '1.1.7.002', 'دفعات مقدمة — بيهس', NULL, 1017, 'asset', 'operating', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-07-23 15:23:04', '2026-07-30 14:36:38'),
	(1026, '2.1.1.003', 'ذمم شركة الوسيم للألبسة', NULL, 200, 'liability', 'operating', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-07-23 15:23:04', '2026-08-06 15:14:35'),
	(1027, '1.1.7.003', 'دفعات مقدمة — شركة الوسيم للألبسة', NULL, 1017, 'asset', 'operating', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-07-23 15:23:04', '2026-07-30 14:36:38'),
	(1028, '2.1.4', 'ضريبة مبيعات مستحقة', 'sales_tax_payable', 20, 'liability', 'operating', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-07-27 11:21:50', '2026-07-30 14:36:38'),
	(1029, '5.2.6', 'خصومات مبيعات ممنوحة', 'sales_discount_given', 51, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-07-27 11:21:50', '2026-07-30 14:36:38'),
	(1030, '5.2.7', 'خصم تعجيل استلام من العملاء المبيعات', 'settlement_discount_expense', 51, 'expense', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, NULL, NULL, '2026-07-27 11:21:51', '2026-07-30 14:36:38'),
	(1031, '2.1.5', 'الدفعات المقدمة من العملاء', '', 20, 'liability', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, 1, 1, '2026-07-28 10:13:32', '2026-07-30 14:36:38'),
	(1032, '4.2.3', 'أرباح فروقات الصرف', '', 41, 'revenue', 'operating', 0.00, 0.00, 1, 1.0000, 3, 1, 0, 1, NULL, '2026-07-30 03:21:02', '2026-07-30 14:36:38'),
	(1033, '2.1.1.004', 'ذمم شركة القلم لبيع المواد اللاصقة', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-01 16:33:30', NULL),
	(1034, '1.1.7.004', 'دفعات مقدمة — شركة القلم لبيع المواد اللاصقة', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-01 16:33:30', NULL),
	(1035, '2.1.1.005', 'ذمم شركة الحسن للمواد الغذائيئة', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-01 16:33:49', NULL),
	(1036, '1.1.7.005', 'دفعات مقدمة — شركة الحسن للمواد الغذائيئة', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-01 16:33:49', NULL),
	(1037, '2.1.1.006', 'ذمم شركة البيك للمستلزمات الماكينات', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-01 16:36:19', NULL),
	(1038, '1.1.7.006', 'دفعات مقدمة — شركة البيك للمستلزمات الماكينات', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-01 16:36:19', NULL),
	(1039, '2.1.1.007', 'ذمم مكتبة المجد للقرطاسية', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-01 16:36:46', NULL),
	(1040, '1.1.7.007', 'دفعات مقدمة — مكتبة المجد للقرطاسية', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-01 16:36:46', NULL),
	(1041, '2.1.1.008', 'ذمم شركة القلم لبيع المواد اللاصقة', NULL, 200, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-01 16:48:11', '2026-08-08 02:10:00'),
	(1042, '1.1.7.008', 'دفعات مقدمة — شركة القلم لبيع المواد اللاصقة', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-01 16:48:11', NULL),
	(1043, '1.1.3.001', 'ذمة السنافر', NULL, 102, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-03 09:07:12', NULL),
	(1044, '2.1.5.001', 'دفعات مقدمة — السنافر', NULL, 1031, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-03 09:07:12', NULL),
	(1045, '2.1.1.009', 'ذمم شركة الواقع  لبيع المواد اللاسهتلاكية', NULL, 200, 'liability', 'none', -17.21, -17.21, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-03 09:10:26', '2026-08-08 02:28:06'),
	(1046, '1.1.7.009', 'دفعات مقدمة — شركة الواقع  لبيع المواد اللاسهتلاكية', NULL, 1017, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-03 09:10:26', NULL),
	(1047, '1.1.3.002', 'ذمة cvxcvxv', NULL, 102, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-03 16:33:34', NULL),
	(1048, '2.1.5.002', 'دفعات مقدمة — cvxcvxv', NULL, 1031, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-03 16:33:34', NULL),
	(1049, '1.1.3.003', 'ذمة retetetr', NULL, 102, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-03 16:33:38', NULL),
	(1050, '2.1.5.003', 'دفعات مقدمة — retetetr', NULL, 1031, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-03 16:33:38', NULL),
	(1051, '1.1.3.004', 'ذمة dgdg', NULL, 102, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-03 16:33:42', NULL),
	(1052, '2.1.5.004', 'دفعات مقدمة — dgdg', NULL, 1031, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-03 16:33:42', NULL),
	(1053, '1.1.3.005', 'ذمة 324dvx', NULL, 102, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-03 16:33:47', NULL),
	(1054, '2.1.5.005', 'دفعات مقدمة — 324dvx', NULL, 1031, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-03 16:33:47', NULL),
	(1055, '1.1.3.006', 'ذمة werdcv', NULL, 102, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-03 16:33:52', NULL),
	(1056, '2.1.5.006', 'دفعات مقدمة — werdcv', NULL, 1031, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-03 16:33:52', NULL),
	(1057, '1.1.3.007', 'ذمة 332cxvcxv', NULL, 102, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-03 16:33:56', NULL),
	(1058, '2.1.5.007', 'دفعات مقدمة — 332cxvcxv', NULL, 1031, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-03 16:33:56', NULL),
	(1059, '1.1.3.008', 'ذمة cxcx234', NULL, 102, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-03 16:34:00', NULL),
	(1060, '2.1.5.008', 'دفعات مقدمة — cxcx234', NULL, 1031, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, NULL, NULL, '2026-08-03 16:34:00', NULL),
	(1061, '1.1.3.009', 'ذمة ملبوسات الحسن', '', 102, 'asset', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, 1, NULL, '2026-08-03 17:09:56', NULL),
	(1063, '2.1.5.009', 'دفعات مقدمة — ملبوسات الحسن', '', 1031, 'liability', 'none', 0.00, 0.00, 1, 1.0000, 4, 1, 0, 1, NULL, '2026-08-03 17:12:59', NULL);

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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الفروع — كل فرع له table_suffix خاص به';

-- Dumping data for table bayhas_local.branches: ~1 rows (approximately)
INSERT INTO `branches` (`id`, `name`, `name_en`, `tenant_slogan`, `branch_type`, `phone`, `email`, `address`, `city`, `country`, `tax_number`, `commercial_registration_number`, `factory_branch_id`, `base_currency`, `local_currency`, `pricing_method`, `default_margin_pct`, `costing_method`, `tax_rate_default`, `tax_input_recoverable`, `allow_negative_stock`, `notify_low_stock`, `low_stock_threshold`, `notify_new_invoice`, `notify_internal_order`, `notify_email`, `invoice_prefix`, `invoice_counter`, `fiscal_year_start`, `week_start_day`, `default_payment_terms`, `code`, `table_suffix`, `dashboard_path`, `icon`, `color`, `sort_order`, `created_by`, `updated_by`, `status`, `created_at`, `updated_at`, `base_currency_id`, `local_currency_id`, `default_purchase_tax_pct`) VALUES
	(1, 'فرع البيع 1', 'Retail Branch 1', 'لصناعة وتجارة ألبسة الأطفال الجاهزة', 'retail', '+963992326518', NULL, 'دوار السبع بحرات - باتجاه الجامع الكبير', 'حلب', 'Syria', '123456', '#############', 5, '1', 'SYP', 'cost_plus', 10.00, 'last_cost', 0.00, 'non_recoverable', 0, 1, 5, 1, 1, NULL, 'ALP', 0, 1, 6, 30, 'ALP', 'ret', 'retail1/modules/dashboard.php', 'bi-shop-window', '#f59e0b', 1, NULL, 1, 'active', '2026-05-20 11:51:25', '2026-08-04 12:52:28', 1, 4, 0.00);

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
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='فئات المستهلكات';

-- Dumping data for table bayhas_local.consumable_categories_ret: ~5 rows (approximately)
INSERT INTO `consumable_categories_ret` (`id`, `name`, `icon`, `color`, `bg_color`, `is_active`, `sort_order`, `created_by`, `created_at`) VALUES
	(1, 'مواد غذائية', 'bi-lightning-charge', '#0891b2', '#e0f7fa', 1, 10, NULL, '2026-07-18 00:47:54'),
	(2, 'قرطاسية', 'bi-pencil', '#7c3aed', '#f3e8ff', 1, 20, NULL, '2026-07-18 00:47:54'),
	(3, 'مكتبيات', 'bi-cup-hot', '#d97706', '#fef3c7', 1, 30, NULL, '2026-07-18 00:47:54'),
	(4, 'صيانة', 'bi-tools', '#dc2626', '#fee2e2', 1, 40, NULL, '2026-07-18 00:47:54');

-- Dumping structure for table bayhas_local.consumable_departments_ret
CREATE TABLE IF NOT EXISTS `consumable_departments_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أقسام الجهات المستلمة للمستهلكات';
;

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
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أوامر الصرف الداخلي للمستهلكات';


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
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تفاصيل أوامر الصرف الداخلي';


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
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='المستهلكات';

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
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='عبوات المستهلكات (عوامل التحويل)';


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
) ENGINE=InnoDB AUTO_INCREMENT=62 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='حركات مخزون المستهلكات — مستقلة عن الفواتير';


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
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='فواتير شراء المستهلكات — رأس الفاتورة';

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
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تفاصيل فواتير شراء المستهلكات';

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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مرتجعات فواتير شراء المستهلكات';

-- Dumping data for table bayhas_local.consumable_returns_ret: ~1 rows (approximately)

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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بنود مرتجعات فواتير شراء المستهلكات';

-- Dumping data for table bayhas_local.consumable_return_items_ret: ~0 rows (approximately)

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
) ENGINE=InnoDB AUTO_INCREMENT=60 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أرصدة المستهلكات لكل مستودع';

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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table bayhas_local.consumable_transfers_ret: ~1 rows (approximately)

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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table bayhas_local.consumable_transfer_items_ret: ~0 rows (approximately)

-- Dumping structure for table bayhas_local.consumable_units_ret
CREATE TABLE IF NOT EXISTS `consumable_units_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='واحدات قياس المستهلكات';

-- Dumping data for table bayhas_local.consumable_units_ret: ~11 rows (approximately)
INSERT INTO `consumable_units_ret` (`id`, `name`, `is_active`, `created_by`, `created_at`) VALUES
	(1, 'قطعة', 1, NULL, '2026-07-18 01:22:48'),
	(2, 'كيلوغرام', 1, NULL, '2026-07-18 01:22:48'),
	(3, 'غرام', 1, NULL, '2026-07-18 01:22:48'),
	(4, 'لتر', 1, NULL, '2026-07-18 01:22:48'),
	(5, 'مليلتر', 1, NULL, '2026-07-18 01:22:48'),
	(6, 'متر', 1, NULL, '2026-07-18 01:22:48'),
	(7, 'علبة', 1, NULL, '2026-07-18 01:22:48'),
	(9, 'كيس', 1, NULL, '2026-07-18 01:22:48'),
	(10, 'فاتورة', 1, NULL, '2026-07-18 01:22:48'),
	(11, 'صندوق', 1, NULL, '2026-07-18 01:22:48'),
	(24, 'كونة', 1, NULL, '2026-07-18 01:31:34'),
	(26, 'ورقة', 1, 1, '2026-07-18 09:21:09');

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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table bayhas_local.currencies: ~5 rows (approximately)
INSERT INTO `currencies` (`id`, `code`, `name`, `symbol`, `exchange_rate`, `is_base`, `status`, `updated_at`) VALUES
	(1, 'USD', 'دولار أمريكي', '$', 1.0000, 1, 'active', NULL),
	(2, 'TRY', 'ليرة تركية', '₺', 47.4300, 0, 'active', '2026-07-30 06:44:30'),
	(3, 'EUR', 'يورو', '€', 0.8760, 0, 'active', '2026-07-30 06:44:30'),
	(4, 'SYP', 'ليرة سورية', 'ل.س', 121.9600, 0, 'active', '2026-07-30 06:44:30');

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
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table bayhas_local.exchange_rates_ret: ~0 rows (approximately)

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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping structure for table bayhas_local.fixed_fees_ret
CREATE TABLE IF NOT EXISTS `fixed_fees_ret` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(18,4) NOT NULL,
  `currency_id` int NOT NULL,
  `due_date` date NOT NULL,
  `recurrence` enum('once','monthly','yearly') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'once',
  `status` enum('pending','paid') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `expense_account_id` int DEFAULT NULL COMMENT 'حساب المصروف/الرسم نفسه (مدين وقت التسديد)',
  `cash_account_id` int DEFAULT NULL COMMENT 'حساب الصندوق/البنك يلي انسدد منه (دائن وقت التسديد)',
  `journal_entry_id` int DEFAULT NULL COMMENT 'القيد الناتج عن التسديد، لو انسدد',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_due` (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table bayhas_local.fixed_fees_ret: ~0 rows (approximately)

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
) ENGINE=InnoDB AUTO_INCREMENT=206 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table bayhas_local.hr_bonuses_ret: ~0 rows (approximately)

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
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table bayhas_local.hr_loans_ret: ~0 rows (approximately)

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
) ENGINE=InnoDB AUTO_INCREMENT=73 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table bayhas_local.hr_promotions_ret: ~0 rows (approximately)

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الطلبات الداخلية بين الفروع';

-- Dumping data for table bayhas_local.internal_orders: ~0 rows (approximately)

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بنود الطلبات الداخلية';

-- Dumping data for table bayhas_local.internal_order_items: ~0 rows (approximately)

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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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
) ENGINE=InnoDB AUTO_INCREMENT=161 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB AUTO_INCREMENT=83 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أقسام النظام وتدرجها';

-- Dumping data for table bayhas_local.modules: ~54 rows (approximately)
INSERT INTO `modules` (`id`, `key`, `parent_key`, `label`, `icon`, `sort_order`, `is_active`, `theme_color`) VALUES
	(1, 'sales', NULL, 'المبيعات', 'bi-bag', 25, 1, '#098500'),
	(2, 'sales.invoices', 'sales', 'مبيعات المنتجات', 'bi-receipt', 30, 1, '#3b82f6'),
	(4, 'sales.returns', 'sales', 'مرتجعات المبيعات', 'bi-arrow-return-right', 44, 1, '#3b82f6'),
	(5, 'purchases', NULL, 'المشتريات', 'bi-cart3', 30, 1, '#d10000'),
	(6, 'purchases.invoices', 'purchases', 'مشتريات المنتجات', 'bi-receipt', 6, 1, '#ec0909'),
	(7, 'purchases.returns', 'purchases', 'مرتجعات المشتريات', 'bi-arrow-return-left', 8, 1, '#ec0909'),
	(8, 'inventory', NULL, 'المخزون', 'bi-box-seam', 1, 1, '#0625a2'),
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
	(82, 'inventory.reports', 'inventory', 'تقارير المنتجات', 'bi-bar-chart-line', 35, 1, '#3b82f6');

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table bayhas_local.notifications_ret: ~0 rows (approximately)

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table bayhas_local.production_entries_ret: ~0 rows (approximately)

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table bayhas_local.production_operations_ret: ~0 rows (approximately)

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
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الموديلات الأب — بيانات مشتركة';

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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table bayhas_local.product_categories_ret: ~3 rows (approximately)
INSERT INTO `product_categories_ret` (`id`, `name`, `parent_id`, `description`, `is_active`, `created_at`) VALUES
	(1, 'بنطلون صبياني', 1, 'بنطلون صبياني', 1, '2026-06-11 11:44:44'),
	(2, 'بنطلون بناتي', 2, 'بنطلون بناتي', 1, '2026-06-11 11:44:44'),
	(3, 'طقم بناتي', 2, 'طقم قطعتين / طقم ثلاث قطع / توينز', 1, '2026-06-11 11:44:44'),
  (4, 'طقم صبياني', 1,'طقم قطعتين / طقم ثلاث قطع / توينز', 1, '2026-06-11 11:44:44');

-- Dumping structure for table bayhas_local.product_colors_ret
CREATE TABLE IF NOT EXISTS `product_colors_ret` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hex_code` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#000000',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table bayhas_local.product_colors_ret: ~20 rows (approximately)
INSERT INTO `product_colors_ret` (`id`, `name`, `hex_code`, `is_active`, `created_at`) VALUES
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
) ENGINE=InnoDB AUTO_INCREMENT=185 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قياسات كل موديل — سعر مستقل لكل قياس';



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
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB AUTO_INCREMENT=634 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='كل قياس+لون = سطر — السعر مورث من product_sizes_alp';


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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='العطل الرسمية';


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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table bayhas_local.purchase_payments_ret: ~0 rows (approximately)

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table bayhas_local.purchase_payment_invoices_ret: ~0 rows (approximately)

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
  `payment_handling` enum('not_paid','partial','paid_refund_cash','paid_credit_supplier') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_paid',
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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table bayhas_local.purchase_returns_ret: ~1 rows (approximately)

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
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table bayhas_local.purchase_return_items_ret: ~2 rows (approximately)

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سندات القبض — مستقلة عن الفواتير';

-- Dumping data for table bayhas_local.receipts_ret: ~0 rows (approximately)

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='توزيع سند القبض على فواتير متعددة';

-- Dumping data for table bayhas_local.receipt_invoices_ret: ~0 rows (approximately)

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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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
) ENGINE=InnoDB AUTO_INCREMENT=175 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table bayhas_local.sales_return_items_ret: ~2 rows (approximately)

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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table bayhas_local.tax_types_ret: ~0 rows (approximately)

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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='المستخدمون';

-- Dumping data for table bayhas_local.users: ~4 rows (approximately)
INSERT INTO `users` (`id`, `username`, `full_name`, `email`, `password`, `role`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES
	(1, 'admin', 'مدير النظام-مهندس منجد', 'admin@fatorize.com', '$2y$10$.5AOpMJu2MAYJlz3RTQnVONC.nMgIKfyN51QtmGWbD3cSR.DwCw12', 'admin', 1, NULL, '2026-05-20 11:57:15', '2026-05-20 12:12:27');

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل نشاط المستخدمين';

-- Dumping data for table bayhas_local.user_activities: ~0 rows (approximately)

-- Dumping structure for table bayhas_local.user_branches
CREATE TABLE IF NOT EXISTS `user_branches` (
  `user_id` int NOT NULL,
  `branch_id` int NOT NULL,
  PRIMARY KEY (`user_id`,`branch_id`),
  KEY `fk_ub_branch` (`branch_id`),
  CONSTRAINT `fk_ub_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ub_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='صلاحيات المستخدم على الفروع';

-- Dumping data for table bayhas_local.user_branches: ~4 rows (approximately)
INSERT INTO `user_branches` (`user_id`, `branch_id`) VALUES
	(1, 1);

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
) ENGINE=InnoDB AUTO_INCREMENT=83 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='صلاحيات كل مستخدم في كل فرع على كل قسم';

-- Dumping data for table bayhas_local.user_permissions: ~60 rows (approximately)
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
	(27, 1, 1, 'sales.returns', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-20 12:42:02', NULL),
	(32, 1, 1, 'hr', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-21 10:53:38', NULL),
	(33, 1, 1, 'hr.employees', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-21 10:53:38', NULL),
	(34, 1, 1, 'hr.attendance', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-21 10:53:38', NULL),
	(35, 1, 1, 'hr.payroll', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-21 10:53:38', NULL),
	(36, 1, 1, 'hr.reports', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-21 10:53:38', NULL),
	(37, 1, 1, 'expenses', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-21 10:53:38', NULL),
	(38, 1, 1, 'inventory.consumables', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-21 10:53:38', '2026-06-21 06:01:22'),
	(39, 1, 1, 'expenses.consumable_entries', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-05-21 10:53:38', NULL),
	(40, 1, 1, 'hr.holidays', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-08 13:00:47', NULL),
	(47, 1, 1, 'inventory.internal_orders', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-11 05:52:48', NULL),
	(70, 1, 1, 'sales.customers', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-17 12:44:53', NULL),
	(71, 1, 1, 'purchases.suppliers', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-17 12:44:53', NULL),
	(72, 1, 1, 'inventory.consumable_issues', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-21 05:53:36', NULL),
	(74, 1, 1, 'inventory.consumable_purchases', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-21 06:17:59', NULL),
	(75, 1, 1, 'finance.account_settings', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-24 12:04:33', NULL),
	(76, 1, 1, 'finance.currencies', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-24 12:24:15', NULL),
	(77, 1, 1, 'finance.shipping_carriers', 1, 1, 1, 1, 1, 1, 1, NULL, '2026-06-28 09:39:11', NULL),
	(78, 1, 1, 'inventory.reports', 1, 0, 0, 0, 0, 1, 1, NULL, '2026-08-05 08:30:39', NULL);

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
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table bayhas_local.user_tab_order: ~1 rows (approximately)
INSERT INTO `user_tab_order` (`id`, `user_id`, `page_group`, `tab_order`, `updated_at`) VALUES
	(1, 1, 'finance', '["accounts.php","payments.php","account_settings.php","receipts.php","journal.php","treasury.php","currencies.php","shipping_carriers.php"]', '2026-07-29 11:40:28');

-- Dumping structure for table bayhas_local.warehouses_ret
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
    CONSTRAINT `fk_manager_id` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table bayhas_local.warehouses_ret: ~2 rows (approximately)
INSERT INTO `warehouses_ret` (`id`, `code`, `name`, `warehouse_type`, `address`, `manager_id`, `is_active`, `created_at`) VALUES
	(1, 'WH-01', 'المستودع الرئيسي للمنتجات', 'products', NULL, 12, 1, '2026-05-20 11:51:25'),
	(2, 'WH-CONS', 'مستودع المواد الاستهلاكية', 'consumables', NULL, 12, 1, '2026-06-11 11:44:44');

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
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مخزون كل Variant في كل مستودع';


/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
