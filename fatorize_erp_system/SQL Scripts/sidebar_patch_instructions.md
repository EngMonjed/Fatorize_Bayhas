# تعديل واحد على includes/sidebar.php

## دوّر عن هالسطر بالضبط (جوا دالة reorderAndFilterSidebarMenu):

```php
$isRetailBranch = ($tableSuffix === 'ret');
```

## استبدله بـ:

```php
// ✅ مُصلح: بدل مقارنة قيمة table_suffix الحرفية (تنكسر مع أي فرع
// بيع ثاني بلاحقة مختلفة عن 'ret' تحديداً، مثال 'ret2') — نستخدم
// branch_type الحقيقي المخزَّن بالجلسة (يحتاج select_account.php
// المُحدَّث ليخزّنه أول شي).
$isRetailBranch = (($_SESSION['branch_type'] ?? 'retail') === 'retail');
```

## ملاحظة

بما إنه الدالة أصلاً بتاخد `$tableSuffix` كـ parameter من الاستدعاء
(`reorderAndFilterSidebarMenu($menu, $_SESSION['table_suffix'] ?? '')`)،
هالتعديل ما بيغيّر توقيع الدالة ولا مكان استدعائها — بس بيغيّر شو
بيصير جوّاها. الباقي كله (فلترة قسم الإنتاج، الترتيب) يضل زي ما هو.

---

# إضافة ثانية على includes/sidebar.php — رابط صفحة إنشاء الفرع

دوّر عن:
```php
'admin.branches'    => 'admin/branches.php',
```
وضيف بعدها مباشرة:
```php
'admin.branch_add'  => 'admin/branch_add.php', // ✅ جديد
```
