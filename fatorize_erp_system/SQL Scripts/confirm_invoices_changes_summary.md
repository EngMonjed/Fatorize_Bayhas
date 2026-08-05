# ملخص تعديلات ملفات تأكيد الفواتير (شراء/بيع) — لمشاركتها بمحادثتي المشتريات/المبيعات

> هالملف ملخّص تقني بحت، مصمَّم يُقرأ لحاله بدون سياق إضافي — ارفعه
> كامل بمحادثة المشتريات أو المبيعات قبل أي تعديل جديد عليهم، منشان ما
> حدا يشتغل على نسخة قديمة أو يلغي هالتعديلات بالغلط.

---

## ١. تغييرات قاعدة البيانات المطلوبة (لازم تكون منفَّذة قبل الملفين)

```sql
ALTER TABLE `branches`
    ADD COLUMN `costing_method` ENUM('last_cost','weighted_average') NOT NULL DEFAULT 'last_cost'
    AFTER `default_margin_pct`;

ALTER TABLE `warehouse_items_ret`
    ADD COLUMN `current_cost` DECIMAL(12,4) NOT NULL DEFAULT 0.0000
    AFTER `quantity`;
```

بدون هالعمودين، الملفين تحت **ما رح يشتغلوا إطلاقاً** (خطأ "Unknown column").

---

## ٢. `confirm_purchase_invoice.php` — شو تغيّر بالضبط

### أ) بق حقيقي مُصلح: عملة القيمة المخزَّنة بدفتر الحركات

**قبل:** وقت الكتابة لجدول `inventory_movement_details`، عمود
`unit_price` كان يُخزَّن بـ`$item['unit_price']` — **الخام بعملة
الفاتورة**، رغم إنه الكود كان أصلاً بيحسب القيمة الصحيحة بعملة الفرع
(`$unitBase`) ويستخدمها بعمود `cost_price` بس، مش `unit_price`.

**بعد:** عمود `unit_price` بجدول `inventory_movement_details` صار
يُخزَّن بـ`$unitBase` (نفس القيمة المستخدمة بـ`cost_price`) — بعملة
الفرع دايماً، مش بعملة الفاتورة.

**السبب:** `inventory_movements`/`inventory_movement_details` هو دفتر
الحركات الموحّد (يُقرأ منه كل من `movements.php` وصفحة التقارير
الجديدة `reports.php`) — لازم كل أرقامه بعملة الفرع دايماً، بلا استثناء.

### ب) ميزة جديدة: تحديث "التكلفة الحالية" لكل عملية شراء

```php
$costingMethod = 'last_cost'; // أو 'weighted_average' — يُقرأ من branches.costing_method
```

كل عملية شراء مؤكَّدة هلق بتحدّث عمود `warehouse_items.current_cost`
(المضاف بالخطوة ١) تلقائياً — إما آخر سعر شراء فعلي، أو متوسط مرجّح
بالكمية، حسب إعداد الفرع. **هاي القيمة هي يلي بيقرا منها ملف تأكيد
البيع لاحقاً** (راجع القسم ٣-ج تحت) — مو `product_sizes.cost_price`
الثابت القديم.

### ج) ⚠ ملاحظة مهمة — مو تعديل مني، تصحيح لافتراض غلط سابق

كان عندي بنسخة قديمة افتراض خاطئ إنه حالة الفاتورة بعد التأكيد
`status='received'` — **هذا غلط، وتم تصحيحه**. القيمة الصحيحة
والمعتمدة فعلياً بنظامك هي **`status='confirmed'`** (draft → confirmed
→ cancelled). لو أي كود بمحادثة المشتريات بيتحقق من `status='received'`
بمكان تاني، لازم يُصحَّح لـ`'confirmed'` كمان للاتساق.

---

## ٣. `confirm_sale_invoice.php` — شو تغيّر بالضبط

### أ) نفس بق العملة بالضبط (مسار التأكيد الرئيسي)

**قبل:** `'unit_price' => $item['unit_price']` (خام بعملة الفاتورة).
**بعد:** `'unit_price' => (float)($item['unit_price_base_currency'] ?? ($item['unit_price'] / $rate))` (بعملة الفرع).

### ب) نفس الإصلاح بمسار الإلغاء (cancel) — مع إصلاح إضافي

مسار الإلغاء كان فيه نفس بق العملة، **بالإضافة لمتغيّر `$rate` غير
معرَّف إطلاقاً بذاك النطاق** (تمت إضافته). كمان `cost_price` بمسار
الإلغاء صار يُقرأ **من سجل الحركة الأصلي لنفس الفاتورة** (`reference_type='sale' AND reference_id=؟`
بجدول `inventory_movement_details`) — مو قيمة جديدة مُعاد حسابها —
لضمان إنه الإلغاء بيعكس بالضبط نفس المبلغ المرحَّل أصلاً وقت البيع،
مش قيمة قد تكون تغيّرت من وقتها.

### ج) مصدر `cost_price` الجديد وقت البيع (الأهم)

**قبل:** `s.cost_price` من جدول `product_sizes` — رقم ثابت من وقت
تسجيل المنتج أول مرة، **ما بيتحدّث** مع عمليات الشراء اللاحقة.

**بعد:**
```sql
COALESCE(NULLIF(wi.current_cost, 0), s.cost_price) AS cost_price
-- عبر JOIN جديد: LEFT JOIN warehouse_items_{TS} wi ON wi.variant_id=ii.variant_id AND wi.warehouse_id=?
```
يقرا من `warehouse_items.current_cost` (التكلفة الحيّة، محدَّثة تلقائياً
من ملف المشتريات أعلاه) — مع fallback للقيمة الثابتة القديمة **بس**
لو `current_cost` لسا صفر (منتج قديم لم يُشترى عبر النظام الجديد بعد).

---

## ٤. خلاصة سريعة — شو لازم تعرفه محادثة المشتريات/المبيعات

| | شراء | بيع |
|---|---|---|
| بق عملة `unit_price` بدفتر الحركات | ✅ مُصلح | ✅ مُصلح (تأكيد + إلغاء) |
| مصدر `cost_price` تغيّر | لا ينطبق (هو مصدر التحديث نفسه) | ✅ من `warehouse_items.current_cost` بدل `product_sizes.cost_price` |
| ميزة جديدة تعتمد عليها | تحديث `current_cost` بكل شراء | قراءة `current_cost` بكل بيع |
| قيمة enum الصحيحة لحالة الفاتورة | `'confirmed'` (مو `'received'`) | (لا يوجد تغيير مماثل بجدول `sales_invoices` — لم يُفحص) |

**أهم نقطة لأي تعديل مستقبلي على الملفين:** أي INSERT جديد لـ
`inventory_movement_details.unit_price`/`cost_price` **لازم يكون بعملة
الفرع دايماً** — لا تستخدم أي عمود `unit_price` خام من جداول الفواتير
مباشرة بدون تحويل.
