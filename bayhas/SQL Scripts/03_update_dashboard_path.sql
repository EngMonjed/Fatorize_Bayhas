-- ============================================================================
-- FATORIZE — يحدّث branches.dashboard_path بعد رينيم مجلد الجذر
-- من bayhas إلى fatorize_erp_system
--
-- شغّله بعد ما تعمل mv bayhas fatorize_erp_system على السيرفر فعلياً،
-- وبعد ما تضيف BASE_PATH لملف config/database.php.
--
-- ملاحظة: القيمة المخزّنة هلق (بعد ترحيل alp→ret) متوقع تكون شكلها:
--   /bayhas/retail1/modules/dashboard.php
-- وبعد هالسكريبت رح تصير:
--   /fatorize_erp_system/retail1/modules/dashboard.php
-- ============================================================================

-- معاينة قبل التحديث (تأكد الأسطر يلي رح تتأثر منطقية)
SELECT id, name, dashboard_path
FROM branches
WHERE dashboard_path LIKE '/bayhas/%';

-- التحديث
UPDATE branches
SET dashboard_path = REPLACE(dashboard_path, '/bayhas/', '/fatorize_erp_system/'),
    updated_at = NOW()
WHERE dashboard_path LIKE '/bayhas/%';

-- تحقق بعد التحديث
SELECT id, name, dashboard_path
FROM branches;

-- ----------------------------------------------------------------------------
-- ملاحظة للمستقبل: لما تنتقل فعلياً لنشر SaaS بساب-دومين لكل عميل
-- (tenant1.fatorize.com إلخ)، BASE_PATH بالكود بيصير '' (فاضي)، وهون
-- بترجع تشغّل نفس النمط بس بـ REPLACE('/fatorize_erp_system/', '')
-- ----------------------------------------------------------------------------
