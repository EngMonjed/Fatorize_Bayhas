-- ============================================================
-- FATORIZE — Master / Control Database Schema
-- ============================================================
-- WHERE TO RUN THIS: create a NEW, separate database on Hostinger
-- dedicated to this (e.g. "u987540206_master" or "fatorize_master").
-- This is NOT a tenant's database — it's the small central registry
-- that tells the app which subdomain belongs to which tenant, and
-- where that tenant's own database lives.
--
-- Every tenant (paying customer / company) still gets their OWN full
-- copy of the existing 63-table application schema
-- (u987540206_bayhas.sql) in their own separate database — that part
-- of the architecture does not change at all.
-- ============================================================

CREATE TABLE IF NOT EXISTS `tenants` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_name`  VARCHAR(150) NOT NULL COMMENT 'اسم الشركة/المصنع الظاهر بالواجهة',
  `subdomain`     VARCHAR(63)  NOT NULL COMMENT 'الجزء الأول من الرابط، مثال: bayhas → bayhas.fatorize.com',
  `tenant_type`   ENUM('factory','shop','both') NOT NULL DEFAULT 'shop'
                  COMMENT 'يحدد أي لوحة تحكم افتراضية تُعرض: تصنيع/مبيعات/الاثنين',

  -- بيانات الاتصال بقاعدة بيانات هذا العميل تحديداً
  `db_host`       VARCHAR(100) NOT NULL DEFAULT 'localhost',
  `db_name`       VARCHAR(64)  NOT NULL,
  `db_user`       VARCHAR(64)  NOT NULL,
  `db_pass_enc`   VARCHAR(255) NOT NULL COMMENT 'كلمة مرور قاعدة بيانات العميل، مُشفّرة (AES-256-CBC) — لا تُخزَّن كنص صريح',

  -- حالة الاشتراك/الحساب
  `status`        ENUM('trial','active','suspended','cancelled') NOT NULL DEFAULT 'trial',
  `plan`          VARCHAR(50) NOT NULL DEFAULT 'basic' COMMENT 'خطة الاشتراك — أساس لنظام فوترة لاحق',
  `trial_ends_at` DATE NULL,

  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_subdomain` (`subdomain`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='سجل مركزي لكل الشركات المشتركة بالنظام (SaaS tenant registry)';

-- ============================================================
-- مثال توضيحي فقط — لا تُشغّل هذا السطر مباشرة؛ استخدم أداة إضافة
-- عميل جديد (سكربت PHP لاحق) لأنها هي من ستُشفّر كلمة المرور فعلياً.
-- تركته هنا فقط ليوضح شكل البيانات المتوقعة:
--
-- INSERT INTO tenants (company_name, subdomain, tenant_type, db_host, db_name, db_user, db_pass_enc, status, plan)
-- VALUES ('Bayhas', 'bayhas', 'both', 'localhost', 'u987540206_bayhas', 'u987540206_bayhas', '<ENCRYPTED_VALUE_HERE>', 'active', 'basic');
-- ============================================================
