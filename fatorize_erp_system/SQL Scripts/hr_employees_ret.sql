
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
(1, 'محمود المسلم', 'مدير مبيعات', 'sales', '0993236518', 'mahmodmoslem8@gmail.com', '2026-01-01', 'monthly', 700.00, 1, 1089, 1090, '6d44fa3043746da35823614cbb80dede', '', 9, 18, 9, 18, 9, 18, 9, 18, NULL, NULL, 9, 18, 9, 18, NULL, 1.5, 'active', 1, '2026-08-23 12:27:08', '2026-08-23 12:34:15'),
(2, 'احمد كردي', 'بائع', 'sales', '0984330510', '', '2026-08-01', 'weekly', 9000.00, 2, 1095, 1096, '', '', 9, 20, 9, 20, 9, 20, 9, 20, NULL, NULL, 9, 20, 9, 20, NULL, 1.5, 'active', 1, '2026-08-23 12:30:40', '2026-08-23 12:33:56');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `hr_employees_ret`
--
ALTER TABLE `hr_employees_ret`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_department` (`department`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_currency_id` (`currency_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `hr_employees_ret`
--
ALTER TABLE `hr_employees_ret`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `hr_employees_ret`
--
ALTER TABLE `hr_employees_ret`
  ADD CONSTRAINT `fk_currency_hr_employees` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
