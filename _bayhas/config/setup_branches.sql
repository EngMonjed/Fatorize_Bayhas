-- ================================================================
-- جداول الفروع والمستخدمين في قاعدة البيانات الرئيسية
-- ================================================================

CREATE TABLE IF NOT EXISTS `branches` (
    `id`             INT(11)      NOT NULL AUTO_INCREMENT,
    `name`           VARCHAR(100) NOT NULL,
    `code`           VARCHAR(50)  UNIQUE DEFAULT NULL,
    `table_suffix`   VARCHAR(20)  DEFAULT '' COMMENT 'alp, ist, gaz ...',
    `dashboard_path` VARCHAR(255) NOT NULL COMMENT 'مسار الداشبورد عند الاختيار',
    `icon`           VARCHAR(50)  DEFAULT NULL COMMENT 'bootstrap-icons class',
    `color`          VARCHAR(20)  DEFAULT '#3b82f6',
    `sort_order`     INT(11)      DEFAULT 0,
    `status`         ENUM('active','inactive') DEFAULT 'active',
    `created_at`     DATETIME     DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
    `id`          INT(11)      NOT NULL AUTO_INCREMENT,    `username`    VARCHAR(50)  NOT NULL UNIQUE,
    `full_name`   VARCHAR(255) DEFAULT NULL,
    `name`        VARCHAR(100) NOT NULL,
    `email`       VARCHAR(255) DEFAULT NULL,
    `password`    VARCHAR(255) NOT NULL COMMENT 'bcrypt',
    `role`        ENUM('user','admin','accountant','sales','purchases','warehouse') DEFAULT 'user',
    `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_branches` (
    `user_id`   INT(11) NOT NULL,
    `branch_id` INT(11) NOT NULL,
    PRIMARY KEY (`user_id`, `branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_activities` (
    `id`            INT(11)     NOT NULL AUTO_INCREMENT,
    `user_id`       INT(11)     NOT NULL,
    `activity_type` VARCHAR(50) NOT NULL,
    `type`          VARCHAR(50) NOT NULL DEFAULT 'auth',
    `description`   TEXT        NOT NULL,
    `created_at`    DATETIME    DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_user_id`   (`user_id`),
    INDEX `idx_created_at`(`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── بيانات تجريبية ──

INSERT IGNORE INTO `branches` (id, name, code, table_suffix, dashboard_path, icon, color, sort_order) VALUES
(1, 'فرع حلب',    'ALP', 'alp', 'aleppo/modules/dashboard.php',  'bi-shop-window', '#f59e0b', 1),
(2, 'فرع استنبول','IST', 'ist', 'istanbul/modules/dashboard.php', 'bi-buildings',   '#ef4444', 2),
(3, 'فرع عنتاب',  'GAZ', 'gaz', 'gaziantep/modules/dashboard.php','bi-shop',        '#10b981', 3),
(4, 'معمل عنتاب', 'LAB', 'lab', 'lab/modules/dashboard.php',      'bi-gear-fill',   '#10b981', 4);

-- مستخدم admin افتراضي (password: Admin@2024)
INSERT IGNORE INTO `users` (id, username, full_name, name, password, role)
VALUES (1, 'admin', 'مدير النظام', 'Admin',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');

INSERT IGNORE INTO `user_branches` (user_id, branch_id) VALUES (1,1),(1,2),(1,3),(1,4);
