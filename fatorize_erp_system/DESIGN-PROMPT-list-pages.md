# مواصفة تصميم صفحات القوائم/الجداول — Fatorize
> مستخرجة **حرفياً** من صفحة `sales_index.php` (سجل فواتير المبيعات). استخدمها
> كبرومبت يُلصَق بأي محادثة جديدة، أو كمرجع لتوحيد شكل: مشتريات، مرتجعات،
> موردين، عملاء، مستهلكات... إلخ.

---

## ١) البرومبت المختصر (انسخه كما هو)

```
صمّم صفحة قائمة/جدول بنفس نمط Fatorize التالي بالضبط (لا تبتكر تصميماً جديداً):

الحزمة: PHP + Bootstrap 5.3.2 RTL + Bootstrap Icons 1.11.3 + خط Cairo (400/500/600/700)
+ ملف assets/css/layout.css (يعرّف --section-color لكل قسم). الصفحة dir="rtl" lang="ar".

الهيكل من فوق لتحت داخل .content-body:
 1) تبويبات القسم (nav-tabs) — التبويب الفعّال بلون var(--section-color) وخط سفلي ٢px،
    غير الفعّال رمادي #64748b، حجم .83rem، وزن 600، بلا حدود جانبية.
 2) صف بطاقات إحصائيات (٤ بطاقات col-6 col-md-3، g-3، mb-4).
 3) .tbl-wrap (أبيض، radius 14px، حد #e2e8f0، overflow hidden) يحتوي:
    - .tbl-hdr: عنوان الجدول (أيقونة + .88rem/700) + نموذج فلاتر ms-auto + أزرار CTA.
    - الجدول table.mtbl.
 4) مودالات: radius 16px بلا حد، رأس بتدرّج لوني بلون القسم، نص أبيض.

الألوان: نص #1e293b — ثانوي #64748b — خافت #94a3b8 — حدود #e2e8f0 — خلفيات ناعمة
#f8fafc/#f1f5f9 — hover الصف #f8fff8 — رابط أساسي #1e3a8a. الحالات بألوان
Bootstrap "subtle" (bg-*-subtle text-*).

الأزرار: CTA بخلفية var(--section-color) ونص أبيض radius 9px حجم .82rem وزن 600.
أزرار البحث btn-sm btn-primary radius 8px، والمسح btn-light radius 8px.
أزرار الإجراءات بالصف: مربعات 28×28 radius 7px حد #e2e8f0 خلفية بيضاء أيقونة .8rem،
ولكل نوع لون hover خاص (أخضر تأكيد / أحمر حذف-إلغاء / سماوي عرض).

حقول الفلترة: form-control-sm / form-select-sm بـ border-radius:8px، عروض ثابتة
(بحث 180px، قائمة 120-160px، تاريخ 140px)، قوائم الحالة تُرسل النموذج onchange.

الجدول: خط .82rem، الرأس خلفية #f8fafc لون #64748b حجم .72rem وزن 600 nowrap،
الخلايا padding 8×12 وحد سفلي #f8fafc، الأرقام class="n" (tabular-nums)،
التواريخ والأرقام الصريحة dir=ltr. تلوين السطر كامل حسب الحالة (خلفية + لون خط).
الترتيب/الفلترة بالنقر على رأس العمود (وضعان بزر تبديل) — بوب-أب الفلترة يُرسم
على body بـ position:fixed (وليس داخل th) حتى لا يقصّه overflow.

Toast: أعلى الصفحة وسط، radius 12px، z-index 9999، يختفي بعد 3.2 ثانية.
كل النصوص عربية، وأي رقم/تاريخ/رمز عملة اتجاهه LTR.
```

---

## ٢) المواصفة الكاملة

### ٢.١ الحزمة التقنية (الـ `<head>`)

```html
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="<?= BASE_PATH ?>/assets/css/layout.css" rel="stylesheet">
```

- **الخط:** Cairo (يُعرَّف عالمياً بـ `layout.css`) — الأوزان: 400 عادي، 500، **600 للعناوين الفرعية والرؤوس**، **700 للأرقام المهمة والعناوين**.
- **`--section-color`:** متغيّر CSS معرَّف بـ `layout.css` لكل قسم (المبيعات أخضر). أي عنصر «هوية القسم» (تبويب فعّال، زر CTA) يستخدمه — **لا تكتب لون القسم يدوياً**، حتى ينتقل التصميم لأي قسم بلا تعديل.

### ٢.٢ لوحة الألوان

| الاستخدام | اللون |
|---|---|
| نص أساسي/عناوين | `#1e293b` |
| نص ثانوي / رؤوس أعمدة | `#64748b` |
| نص خافت / فواصل | `#94a3b8` |
| حدود البطاقات | `#e2e8f0` |
| حدود داخلية ناعمة | `#f1f5f9` / `#f8fafc` |
| خلفية رأس الجدول | `#f8fafc` |
| hover الصف | `#f8fff8` |
| رابط أساسي (رقم مستند/اسم) | `#1e3a8a` |
| خلفية أيقونة إحصائية: أزرق/كهرماني/أخضر/أحمر | `#eff6ff` / `#fef3c7` / `#f0fdf4` / `#fee2e2` |
| رأس عمود مفلتَر (تنبيه) | `#fef3c7` |

### ٢.٣ الـCSS الجاهز (انسخه كما هو داخل `<style>`)

```css
.stat-card{background:#fff;border-radius:14px;border:1px solid #e2e8f0;padding:12px 16px;display:flex;align-items:center;gap:10px}
.stat-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.stat-val{font-size:1.2rem;font-weight:700;color:#1e293b;line-height:1}
.stat-lbl{font-size:.7rem;color:#64748b;margin-top:2px}

.tbl-wrap{background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden}
.tbl-hdr{padding:12px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:10px;flex-wrap:wrap}

table.mtbl{width:100%;border-collapse:collapse;font-size:.82rem}
table.mtbl th{background:#f8fafc;padding:8px 12px;font-weight:600;color:#64748b;font-size:.72rem;border-bottom:1px solid #f1f5f9;white-space:nowrap}
table.mtbl td{padding:8px 12px;border-bottom:1px solid #f8fafc;vertical-align:middle}
table.mtbl tr:last-child td{border-bottom:none}
table.mtbl tr:hover td{background:#f8fff8}

.act-btn{width:28px;height:28px;border-radius:7px;border:1px solid #e2e8f0;background:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem;color:#64748b;cursor:pointer;transition:all .12s;text-decoration:none}
.act-btn:hover{background:#f1f5f9}
.act-btn.success-h:hover{background:#dcfce7;color:#16a34a;border-color:#86efac}
.act-btn.danger:hover{background:#fee2e2;color:#dc2626;border-color:#fca5a5}
.act-btn.info-h:hover{background:#e0f2fe;color:#0891b2;border-color:#7dd3fc}

.n{font-variant-numeric:tabular-nums}
.det-row{display:flex;justify-content:space-between;font-size:.8rem;padding:4px 0;border-bottom:1px solid #f8fafc}
.det-row:last-child{border-bottom:none}
.grp-badge{display:inline-flex;align-items:center;border-radius:20px;font-size:.68rem;padding:2px 8px;font-weight:600;border:1px solid}
```

### ٢.٤ التبويبات

```html
<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e2e8f0">
  <li class="nav-item">
    <!-- الفعّال -->
    <a class="nav-link fw-600 active" href="..."
       style="border:none;border-bottom:2px solid var(--section-color);color:var(--section-color);font-size:.83rem;margin-bottom:-2px">
      <i class="bi bi-receipt me-1"></i>فواتير المبيعات</a>
  </li>
  <li class="nav-item">
    <!-- غير الفعّال -->
    <a class="nav-link fw-600" href="..." style="border:none;color:#64748b;font-size:.83rem">
      <i class="bi bi-people me-1"></i>إدارة العملاء</a>
  </li>
</ul>
```
كل تبويب = أيقونة Bootstrap + نص، `me-1` بينهما.

### ٢.٥ بطاقات الإحصائيات

٤ بطاقات: `row g-3 mb-4` وكل واحدة `col-6 col-md-3`. البنية: أيقونة ملوّنة يمين + (رقم كبير + وصف صغير).
ترتيب الألوان المعتمَد: **أزرق (إجمالي العدد) ← كهرماني (معلّق/مسودات) ← أخضر (إجمالي مالي) ← أحمر (مستحق/تحذير)**.
المبالغ المالية داخل `.stat-val` تأخذ class `n` ورمز العملة قبل الرقم.

### ٢.٦ شريط البحث والفلاتر (`.tbl-hdr`)

ترتيب العناصر (RTL من اليمين): **عنوان الجدول** ← `<form class="d-flex gap-2 flex-wrap align-items-center ms-auto">` ← **أزرار CTA**.

| العنصر | المواصفة |
|---|---|
| عنوان الجدول | `<i class="bi bi-… me-1 text-success"></i>` + نص `.88rem` وزن 700 لون `#1e293b` `white-space:nowrap` |
| بحث نصي | `form-control form-control-sm` عرض `180px` `border-radius:8px` |
| قوائم الحالة/العميل | `form-select form-select-sm` عرض `120px` (حالة) / `160px` (عميل/مورد) `border-radius:8px` + `onchange="this.form.submit()"` |
| تاريخ من/إلى | `type="date"` عرض `140px` `border-radius:8px`، وبينهما `—` بلون `#94a3b8` |
| زر بحث | `btn btn-sm btn-primary` `border-radius:8px` + أيقونة `bi-search me-1` |
| زر مسح | `btn btn-sm btn-light` `border-radius:8px` + `bi-x-lg me-1` — **يظهر فقط لو في فلتر مفعَّل** |
| زر CTA رئيسي (جديد/سندات) | `btn btn-sm fw-600` `border-radius:9px` `background:var(--section-color);color:#fff;font-size:.82rem;text-decoration:none;white-space:nowrap` |

### ٢.٧ الجدول

- الرأس: خلفية `#f8fafc`، خط `.72rem`، وزن 600، لون `#64748b`، `nowrap`.
- الخلايا: `padding:8px 12px`، حد سفلي `#f8fafc`، `vertical-align:middle`.
- **أرقام** → `class="n"` (أرقام متساوية العرض). **مبالغ مهمة** → `fw-600`. **متبقّي/مدين > 0** → `text-danger fw-600`. **مدفوع** → `text-success`.
- **تواريخ** → `direction:ltr` (والوقت `YYYY-MM-DD HH:MM`).
- **رقم المستند** → رابط `color:#1e3a8a;text-decoration:none` يفتح مودال التفاصيل.
- **العملة** → `<span class="badge bg-info-subtle text-info" style="font-size:.72rem" dir="ltr">`.
- **عدد البنود** → `<span class="badge bg-secondary-subtle text-secondary">N بند</span>`.
- عمود **الإجراءات** آخر عمود، `text-align:center`، أزرار `.act-btn` داخل `d-flex gap-1 justify-content-center`.
- الصف الفارغ: `colspan` = عدد الأعمدة، أيقونة كبيرة باهتة (`font-size:2rem;opacity:.2`) + نص «لا توجد …».

### ٢.٨ تلوين الصف الكامل حسب الحالة

دالة PHP تُرجع `style` للـ`<tr>` (خلفية + لون خط) — تمييز بصري فوري بلا قراءة عمود الحالة:

| الحالة | خلفية | لون الخط |
|---|---|---|
| مسودة | `#f8fafc` | `#64748b` |
| ملغاة | `#fef2f2` | `#dc2626` |
| مؤكدة + غير مدفوعة | `#eff6ff` | `#1e3a8a` |
| مؤكدة + جزئي | `#fffbeb` | `#92400e` |
| مؤكدة + مدفوعة بالكامل | `#f0fdf4` | `#065f46` |

### ٢.٩ الشارات (Badges)

خرائط PHP ثابتة `label + cls`:
- **الحالة:** مسودة `bg-secondary-subtle text-secondary` · مؤكدة `bg-success-subtle text-success` · ملغاة `bg-danger-subtle text-danger` · مدفوعة `bg-primary-subtle text-primary` — `font-size:.68rem`.
- **الدفع (نص ملوّن بلا خلفية):** غير مدفوعة `text-danger` · جزئي `text-warning` · مدفوعة `text-success` — `font-size:.78rem;font-weight:600`.
- **الكروب/تجميع:** `.grp-badge` بأربع ثلاثيات لون دائرية (خلفية/نص/حد): `#eff6ff/#1e3a8a/#bfdbfe` · `#f0fdf4/#065f46/#bbf7d0` · `#fff7ed/#7c2d12/#fed7aa` · `#f5f3ff/#4c1d95/#ddd6fe`.

### ٢.١٠ الترتيب والفلترة بالنقر على رأس العمود

- شريط صغير فوق الجدول: `النقر على رأس العمود:` + `btn-group btn-group-sm` بزرين `btn-outline-primary` (**ترتيب** `bi-sort-down` / **فلترة** `bi-funnel`) + زر «مسح كل الفلاتر» يظهر فقط عند وجود فلتر. **ضعه داخل حاوية بحشوة (`px-3 pt-2`)** كي لا يلتصق بحد `.tbl-wrap`.
- كل `<th>` قابل للتفاعل: `class="sortable-th" data-col="N" data-type="text|num|date"` و`cursor:pointer`. كل `<td>` يحمل `data-sort="القيمة الخام"` (رقم/ISO date/نص) — الترتيب والفلترة يعتمدان عليه لا على النص المنسَّق. **عمود الإجراءات مستثنى.**
- **ترتيب:** نقرة = تصاعدي، ثانية = تنازلي، سهم ▲/▼ على العمود النشط فقط. الأرقام `parseFloat`، التواريخ `new Date`، النص `localeCompare(…,'ar')`.
- **فلترة:** بوب-أب بتشيك بوكس لكل قيمة فريدة + «تحديد الكل/إلغاء الكل» + «تطبيق/إغلاق». **يُرسم على `document.body` بـ `position:fixed`** (محسوب من `getBoundingClientRect()`) مع `z-index:2000`، `border-radius:8px`، ظل `0 8px 24px rgba(0,0,0,.15)`، `max-height:260px`، `overflow-y:auto`. الرأس المفلتَر يتلوّن `#fef3c7`.
- ⚠ **فخّان معروفان:** (١) لا ترسم البوب-أب داخل `<th>` لأن `.table-responsive` يقصّه. (٢) استخدم `e.stopPropagation()` بنقر الرأس، وإلا مستمع الإغلاق العام على `document` يقفله فور فتحه.

### ٢.١١ المودالات

- `modal-dialog modal-xl` (+`modal-dialog-scrollable` للطويلة) · `modal-content` بـ `border-radius:16px;border:none`.
- الرأس: `modal-header py-3 px-4 border-0` بتدرّج لون القسم `linear-gradient(135deg,#065f46,#16a34a)` (بدّل الزوج بلون القسم)، `border-radius:16px 16px 0 0`.
- عنوان: `modal-title text-white fw-700`، عنوان فرعي `.75rem` أبيض شفاف 70%.
- أزرار الرأس (طباعة/تعديل): خلفية `rgba(255,255,255,.15)` نص أبيض حد `rgba(255,255,255,.3)` `border-radius:8px` `font-size:.76rem` حشوة `4×10` + `btn-close-white`.
- الجسم: `modal-body px-4 py-3`، حالة التحميل: `spinner-border` بلون القسم وسط.

### ٢.١٢ Toast

```js
function toast(msg, type='success'){
  const t=document.createElement('div');
  t.className=`alert alert-${type} shadow`;
  t.style.cssText='position:fixed;top:70px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:240px;text-align:center;font-size:.83rem;padding:.5rem 1.2rem';
  t.innerHTML=`<i class="bi bi-${type==='success'?'check-circle-fill text-success':'exclamation-triangle-fill text-danger'} me-2"></i>${msg}`;
  document.body.appendChild(t); setTimeout(()=>t.remove(),3200);
}
```
النجاح يبدأ بـ ✅ بنص الرسالة، والخطأ `type='danger'`.

### ٢.١٣ قواعد RTL/LTR والأرقام

- الصفحة `dir="rtl"`. **الاستثناءات LTR:** التواريخ، أرقام الهاتف، الموديلات/الباركود، رموز العملات، الأرقام ضمن نصوص عربية → `dir="ltr"` أو `direction:ltr`.
- الأرقام المالية دائماً `class="n"` وبخانتين عشريتين (`number_format(…,2)`).
- رمز العملة **بعد** الرقم بالجدول (`1,250.00 $`) و**قبله** ببطاقات الإحصائيات (`$ 1,250.00`) — التزم بكل نمط بمكانه.

### ٢.١٤ سلّم الأبعاد

| العنصر | radius |
|---|---|
| بطاقات وجداول (`.stat-card`, `.tbl-wrap`) | 14px |
| مودال | 16px |
| toast | 12px |
| زر CTA | 9px |
| حقول وأزرار الفلاتر | 8px |
| أزرار الإجراءات `.act-btn` | 7px |
| أيقونة الإحصائية | 10px |
| شارة كروب | 20px (pill) |

أحجام الخط: عنوان جدول `.88rem` · جسم الجدول `.82rem` · تبويب `.83rem` · رأس جدول `.72rem` · وصف إحصائية `.7rem` · شارة `.68rem` · رقم إحصائية `1.2rem`.

---

## ٣) جدول التكييف لكل قسم

| ما **يتغيّر** حسب القسم | ما **يضل ثابتاً** |
|---|---|
| عنوان الصفحة + أيقونتها | الحزمة، الخط، لوحة الألوان الأساسية |
| تبويبات القسم | هيكل الصفحة (تبويبات ← إحصائيات ← tbl-wrap) |
| ٤ بطاقات الإحصائيات (المعنى/الرقم) | ألوان الأيقونات الأربعة وترتيبها |
| أعمدة الجدول + `data-type` لكل عمود | تنسيق الخلايا والرؤوس والأرقام |
| فلاتر النموذج (عميل ↔ مورد ↔ مستودع…) | أبعاد الحقول وأشكالها |
| خرائط `STATUS_MAP`/`PAY_MAP` وألوان الصف | منطق تلوين الصف الكامل |
| زوج ألوان تدرّج المودال (لون القسم) | بنية المودال وأبعاده |
| نص زر CTA ووجهته | شكل الزر (`--section-color`) |

## ٤) قائمة تحقق قبل التسليم

- [ ] `dir="rtl"` والخط Cairo محمَّل، ولا لون قسم مكتوب يدوياً (كله `var(--section-color)`).
- [ ] كل `<td>` قابل للترتيب فيه `data-sort`، وعدد `<th>` = عدد `<td>` (وفحص `colspan` الصف الفارغ).
- [ ] زر «مسح» لا يظهر إلا مع فلتر فعّال.
- [ ] التواريخ/الأرقام/الرموز LTR، والمبالغ `n` بخانتين عشريتين.
- [ ] بوب-أب الفلترة على `body` مع `stopPropagation`.
- [ ] `node --check` على الجافاسكربت المستخرَج + توازن `{}`/`()`، وتوازن `<div>` بعد أي تعديل HTML.
