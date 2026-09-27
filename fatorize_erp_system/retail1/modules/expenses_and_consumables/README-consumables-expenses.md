# README — قسم المصاريف والمستهلكات (Consumables & Expenses)

> نسخة نهائية توثّق **الحالة الحالية فقط** لهذا القسم بالكامل — البيانات، الباك إند، والفرونت إند. لا تسلسل تاريخي للتعديلات، ولا شرح لأخطاء سابقة.

---

## ١. نظرة عامة

القسم مسؤول عن دورة حياة المواد الاستهلاكية (غير المخصصة للبيع — أدوات مكتبية، مواد تنظيف، مواد تشغيلية...) من الشراء حتى الاستهلاك، بالإضافة لإدارة المصاريف التشغيلية العامة للفرع.

### الصفحات الثمانية

| الصفحة | المسار | الوظيفة |
|---|---|---|
| `consumables.php` | `modules/expenses_and_consumables/` | كتالوج المواد الاستهلاكية (تعريف، فئات، وحدات، عبوات) |
| `consumable_purchases.php` | `modules/expenses_and_consumables/` | فواتير شراء المستهلكات |
| `consumable_issues.php` | `modules/expenses_and_consumables/` | أوامر صرف المستهلكات |
| `consumable_transfers.php` | `modules/expenses_and_consumables/` | مناقلة مستهلكات بين مستودعين |
| `expenses.php` | `modules/expenses_and_consumables/` | إدارة المصاريف التشغيلية العامة |
| `consumable_reports.php` | `modules/expenses_and_consumables/` | تقارير القسم (٣ تقارير فرعية) |
| `warehouse.php` | `modules/inventory/` **(مشترك مع المنتجات)** | إدارة المستودعات — `?type=consumables` |
| `movements.php` | `modules/inventory/` **(مشترك مع المنتجات)** | سجل الحركات — `?tab=consumables` |
| `suppliers.php` | `modules/purchases/` **(مشترك مع موردي المنتجات)** | إدارة الموردين — `?tab=consumables` |

**ملاحظة معمارية:** الصفحات المشتركة (`warehouse.php`, `movements.php`, `suppliers.php`) بقيت بمجلداتها الأصلية عمداً — هي بنية تحتية مشتركة بين أكثر من قسم، مش ملك لقسم واحد.

---

## ٢. نموذج البيانات (الحالة النهائية)

### الكتالوج
```
consumable_categories_{TS}         — فئات المواد (ديناميكية)
consumable_units_{TS}              — وحدات القياس (ديناميكية)
consumable_items_{TS}               — كتالوج المواد
  ├─ category_id → consumable_categories.id
  └─ unit_id → consumable_units.id
consumable_item_packagings_{TS}    — عبوات كل مادة (كرتونة، ماعون...) + qty_per_package
```

### المخزون والحركة
```
consumable_stock_{TS}               — الرصيد الفعلي (item_id + warehouse_id + quantity + avg_cost_base)
consumable_movements_{TS}           — سجل كل حركة (شراء/صرف/مناقلة/إرجاع)
  ├─ movement_type: receive | issue | return_in | return_out | transfer | adjust | waste
  ├─ direction: in | out
  ├─ reference_type: purchase | issue | transfer | consumable_return | ...
  └─ to_warehouse_id (للمناقلات — يوثّق وجهة الحركة الخارجة)
```

### المستندات (رأس + بنود، لكل مستند)
```
consumable_purchases_{TS} + consumable_purchase_items_{TS}     — فواتير الشراء
consumable_issues_{TS} + consumable_issue_items_{TS}           — أوامر الصرف
consumable_transfers_{TS} + consumable_transfer_items_{TS}      — المناقلات
consumable_returns_{TS} + consumable_return_items_{TS}          — الإرجاعات
```

كل بند (`*_items`) عنده: `packaging_id`, `packaging_qty` (اختياري، للعرض/التدقيق) — العمود `quantity` نفسه **دايماً بوحدة المخزون الأساسية**.

### المصاريف
```
expenses_{TS}
  ├─ status: active | cancelled
  ├─ cancelled_at, cancelled_by
  ├─ expense_account_id, cash_account_id → account_charts.id
  └─ currency, exchange_rate, amount_original, amount_base
```

### الموردون (جدول عام مشترك)
```
product_suppliers_{TS}
  └─ supplier_type: product | consumable | both
```
لا يوجد جدول موردين منفصل للمستهلكات — نفس الجدول يخدم النوعين، بالفلترة عبر `supplier_type`.

### الجهات المستلمة
```
consumable_departments_{TS}   — الجهات المستلمة لأوامر الصرف (Cost Center)
```

---

## ٣. الربط المحاسبي

مصدر الحقيقة الوحيد: **`invoice_account_settings_{TS}`** (مفاتيح: `consumable_inventory`, `consumable_supplier`, `consumable_expense`, `tax_input_recoverable` اختياري). لا تخمين بنمط كود الحساب بأي مكان.

### دورة حياة كل مستند

```
مسودة (Draft) ──[تأكيد]──▶ مؤكّد (Confirmed) ──[إلغاء]──▶ ملغى (Cancelled)
   لا أثر فعلي         - رصيد المخزون يتغيّر فعلياً       - قيد عكسي جديد
                        - قيد GL يُنشأ (لو الحسابات        (الأصلي يبقى للأبد)
                          مضبوطة بالربط المحاسبي)         - الرصيد يرجع فعلياً
                        - رصيد شجرة الحسابات يتحدّث فوراً
```

| المستند | القيد عند التأكيد | ملاحظة |
|---|---|---|
| فاتورة شراء | مدين مخزون المستهلكات (أو مقسّم صافي+ضريبة) / دائن ذمم الموردين | |
| أمر صرف | مدين مصروف المستهلكات / دائن مخزون المستهلكات | عكس اتجاه الشراء |
| مناقلة | **بلا أي قيد محاسبي** | القيمة الإجمالية للمخزون ما بتتغيّر |
| إرجاع | مدين مخزون / دائن مصروف (بقيمة الكمية المرتجعة فقط) | مستند مستقل، بسعر التكلفة الأصلي وقت الصرف، لا يعكس القيد الأصلي بالكامل |

**القاعدة الذهبية:** لا يُحذف أي قيد محاسبي مطلقاً بعد إنشائه. الإلغاء = قيد عكسي جديد + تحديث حالة.

### الدالة المشتركة `postAccountBalance()`
موجودة بكل ملف يُنشئ قيوداً (`consumable_purchases.php`, `consumable_issues.php`, `consumable_transfers.php`, `expenses.php`) — بترحّل فعلياً رصيد الحساب بجدول `account_charts` (`balance`/`base_balance`) فور كتابة كل سطر قيد، حسب طبيعة الحساب (مدين/دائن).

---

## ٤. العملة

- **`currency_id`** (FK رقمي لجدول `currencies`) بكل قيود `journal_entries`/`journal_entry_items` — لا عمود نصي.
- **`original_amount`** (بعملة المستند) منفصل عن **`base_amount`** (بعملة الفرع) — لا يُحسب لحظياً من سعر صرف حالي (مبدأ IAS 21، ثبات تاريخي).
- عملة الفرع الأساسية تُقرأ حصراً عبر `branches.base_currency_id` (FK رقمي) — **لا** `branches.base_currency` (نصي).

---

## ٥. الصلاحيات (مفاتيح `modules`)

| المفتاح | الصفحة |
|---|---|
| `inventory.consumables` | `consumables.php` |
| `purchases.consumable_purchases` | `consumable_purchases.php` |
| `expenses.consumable_issues` | `consumable_issues.php` |
| `expenses.consumable_transfers` | `consumable_transfers.php` |
| `finance.expenses` | `expenses.php` |
| `expenses.consumable_reports` | `consumable_reports.php` |
| `expenses.warehouse` | `warehouse.php?type=consumables` |
| `expenses.consumable_entries` | `movements.php?tab=consumables` |
| `expenses.consumable_suppliers` | `suppliers.php?tab=consumables` |

**نمط الصفحات المشتركة:** السياق (`?type=`/`?tab=`) يُحدَّد **قبل** فحص الصلاحية دايماً، ومفتاح الصلاحية نفسه يتغيّر ديناميكياً حسب السياق — لا مفتاح ثابت بغض النظر عن النوع.

---

## ٦. هيكلية الباك إند (نمط موحّد بكل صفحة)

```php
session_start();
require config/database.php + config/auth.php
$pdo = getConnection(); checkLogin($pdo);
[تحديد $tab/$type لو الصفحة مشتركة — قبل الصلاحية]
requirePermission($key, 'view');
$currentModule = $key;
[تعريف أسماء الجداول: $TI, $TST, $TM ... بلاحقة {$TS}]

// AJAX
if POST && isset($_POST['_action']):
    switch/if-elseif على $act
    كل عملية كتابة داخل transaction (beginTransaction/commit/rollBack)
    فحوصات الرصيد الحاسمة تُعاد عند التأكيد (مش وقت الحفظ كمسودة بس)
    exit;

// صفحة عادية (GET): جلب بيانات القوائم/الفلاتر، ثم HTML
```

### دورة حفظ/تأكيد نموذجية
1. `save_*` — يحفظ مسودة فقط، بلا أي أثر فعلي على المخزون/الحسابات.
2. `confirm_*` — الأثر الفعلي الكامل (خصم/إضافة مخزون + قيد GL + `postAccountBalance()`)، مع إعادة فحص الرصيد وقتها (احترازاً من تغيّر الرصيد منذ الحفظ).
3. `cancel_*` — قيد عكسي + تحديث حالة (لا حذف).

---

## ٧. هيكلية الفرونت إند (نمط موحّد بكل صفحة)

### التصميم البصري
- **نظام ألوان ثابت بكل الجداول:** 🔵 أزرق = معرّف/رقم مستند، 🟢 أخضر = مصدر، 🔴 أحمر = هدف/وجهة، 🟡 أصفر = كمية/عدد، ⚫ افتراضي = قيمة مالية.
- **جدول** بكلاس `mtbl` (لا Bootstrap العام).
- **فرز بالنقر على رأس أي عمود** (دالة JS عامة `makeSortable()`/`sortTableByColumn()` مكرّرة بكل ملف — تتعرّف تلقائياً على تاريخ ISO / رقم / نص عربي).
- **مودالات** بحجم `modal-lg`/`modal-xl`، كل حقل بعنوان ثابت فوقه (`field-lbl`).
- **إدراج بند جديد بمستند:** يظهر فوق (جنب زر الإضافة) + تظليل أخضر مؤقت (`newRowHighlight`) + تركيز تلقائي — بارامتر `isNew` (true عند الإضافة الفعلية، false عند استرجاع بنود موجودة بوضع التعديل).
- **قوائم منسدلة ديناميكية دايماً** (فئات، وحدات، عبوات، جهات، عملات) — لا `enum` أو مصفوفة PHP ثابتة.

### شريط التبويبات الموحّد
كل الصفحات الثمانية بتعرض **نفس شريط التبويبات بالضبط** (نفس الترتيب: المواد الاستهلاكية ← فواتير الشراء ← صرف المستهلكات ← مستودعات ← حركة ← مناقلة ← إدارة المصاريف ← موردو المستهلكات ← التقارير) — الصفحات المشتركة (`warehouse.php`, `movements.php`) بتعرضه **شرطياً** بس لما السياق يطابق القسم.

### Breadcrumb
مكوّن مشترك (`includes/breadcrumb.php` → `renderBreadcrumb()`) بيُستدعى بكل صفحة "قسم واحد ثابت"، مبني تلقائياً من `$menu`/`$currentModule`. الصفحات المشتركة (`warehouse.php`, `movements.php`) عندها breadcrumb يدوي مخصص (القسم بيتغيّر ديناميكياً بنفس الصفحة).

### الشريط الجانبي
ملف JS مشترك واحد (`assets/js/sidebar.js`) — بدل تكرار دوال `sbOpen`/`toggleGroup`/منطق `localStorage` بكل صفحة. `isGroupActive()` (بملف `sidebar.php`) بتعتمد **حصراً** على تطابق مفتاح الصفحة الحالية مع مفاتيح أبناء القسم الفعليين (`parent_key` من قاعدة البيانات) — بدون أي فحص احتياطي بمطابقة بادئة نص المفتاح.

---

## ٨. قيود معروفة (بقرار صريح، غير مبنية بعد)

- **آلية الدفع الفعلية** (سند دفع مستقل للموردين) — كشف حساب المورد حالياً يعرض فواتير الشراء فقط، بلا خصم دفعات.
- **جهة الكتابة لأسعار الصرف المؤرخة** — القراءة فقط مبنية.
- **تعميم `postAccountBalance()`/`currency_id`** على باقي وحدات النظام (مبيعات، مشتريات منتجات، رواتب) — مبني بهذا القسم فقط لحد الآن.
- **توزيع فاتورة شراء واحدة على أكثر من مستودع** — غير مدعوم مباشرة؛ الطريقة الصحيحة حالياً: فاتورة لمستودع واحد ثم مناقلة.
- **توحيد نموذج بيانات المخزون** (`consumable_items`/`consumable_stock` منفصلين عن `products`/`warehouse_items`) — بق معماري موثّق، إعادة هيكلة واسعة مؤجّلة.
