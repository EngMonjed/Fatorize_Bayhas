# تعليمات تسجيل قسم الإنتاج بـ `includes/sidebar.php`

⚠ هالملف بملكية محادثة تانية (حسب نمط تقسيم العمل عندك) — ما عدّلته
مباشرة. بس رح تحتاج تضيف يدوياً مطابقة الروابط بدالة `moduleUrl()`
(أو أي دالة مشابهة عندك بـ`sidebar.php` بتحوّل `key` من جدول `modules`
لمسار ملف فعلي)، وإلا الروابط الجديدة رح تطلع `#`.

أضف هالثلاث مطابقات (بنفس نمط باقي الأقسام):

```php
'production.raw_materials' => 'modules/production/raw_materials.php',
'production.operations'    => 'modules/production/operations.php',
'production.entries'       => 'modules/production/production_entries.php',
```

بعد الإضافة، تأكد إن:
1. سكريبت `01_add_production_raw_materials_module.sql` اشتغل (يسجّل
   `production` + الصفحات الثلاث بجدول `modules`).
2. منحت صلاحية `view` (وباقي الصلاحيات المطلوبة) على
   `production.raw_materials` للمستخدمين المعنيين عبر
   `admin/permissions.php`. صلاحية `view` بس على
   `production.operations`/`production.entries` كافية عشان التبويبات
   تبان (الصفحتين لسا بوضع صيانة، ما فيهم إجراءات فعلية).
