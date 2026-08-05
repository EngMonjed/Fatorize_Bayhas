تعليمات إضافة حقل "طريقة حساب تكلفة المخزون" لصفحة admin/branches.php
=========================================================================

ما عندي هالملف بهالمحادثة، فهاي تعليمات دقيقة لإضافته يدوياً بنفسك —
مش أكتر من ٣ خطوات بسيطة.

## ١. بفورم إضافة/تعديل الفرع (HTML) — ضيف هالحقل

مكان مناسب: جنب حقل "هامش الربح الافتراضي %" الموجود أصلاً (نفس
القسم المنطقي — إعدادات تسعير/تكلفة الفرع).

```html
<div class="col-md-6">
    <label class="field-lbl">طريقة حساب تكلفة المخزون</label>
    <select name="costing_method" class="form-select form-select-sm">
        <option value="last_cost">آخر سعر شراء (الأبسط)</option>
        <option value="weighted_average">المتوسط المرجّح (أدق)</option>
    </select>
    <div class="field-hint" style="font-size:.7rem;color:#94a3b8;margin-top:4px">
        بتحدّد كيف يُحسب "التكلفة الحالية" لكل منتج تلقائياً بعد كل عملية
        شراء — وهاي القيمة يلي بيُعتمد عليها لحساب الربح وقت البيع.
        تقدر تبدّلها بأي وقت بدون أي مشكلة (راجع الشرح يلي حكيناه —
        التبديل ما بيصفّر أي بيانات).
    </div>
</div>
```

لو الفورم بيعرض قيمة الفرع الحالية وقت التعديل (edit mode)، ضيف
`selected` للخيار المطابق:
```php
<option value="last_cost" <?= ($branch['costing_method'] ?? 'last_cost') === 'last_cost' ? 'selected' : '' ?>>آخر سعر شراء (الأبسط)</option>
<option value="weighted_average" <?= ($branch['costing_method'] ?? '') === 'weighted_average' ? 'selected' : '' ?>>المتوسط المرجّح (أدق)</option>
```

## ٢. بمعالج الحفظ (PHP، `save_branch` أو ما يعادلها) — ضيف العمود

دوّر على الـ`UPDATE`/`INSERT` الخاص بحفظ بيانات الفرع، وضيف
`costing_method` لقائمة الأعمدة المحفوظة:

```php
$costingMethod = $_POST['costing_method'] ?? 'last_cost';
if (!in_array($costingMethod, ['last_cost', 'weighted_average'], true)) {
    $costingMethod = 'last_cost'; // حماية من قيمة غير متوقعة
}
```
وضيفها لقائمة `?,?,?...` والـ`execute([...])` بنفس مكان
`default_margin_pct` تماماً (بما إنه العمود جنبه مباشرة بالجدول).

## ٣. لا تنسى تنفيذ `costing_method_setup.sql` أولاً

قبل ما تجرب أي شي — الملف يضيف العمود لجدول `branches` فعلياً، بدونه
الحفظ رح يفشل بخطأ "Unknown column".
