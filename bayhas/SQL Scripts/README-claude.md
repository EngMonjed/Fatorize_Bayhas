# FATORIZE — Multi-Branch, Multi-Tenant Financial & Inventory ERP

A native-PHP, no-framework ERP system, originally built for **Bayhas** (a
clothing retail/manufacturing company) and now being generalized into a
**multi-tenant SaaS product**. It covers sales, purchases, inventory,
accounting (chart of accounts, journal entries, receipts), HR/payroll,
consumables, and inter-branch internal orders.

> **Documentation basis:** this document is maintained incrementally across
> our working sessions. Every structural claim below (folder names, table
> names, constants) has been verified directly against the actual PHP/SQL
> files as they exist **right now**, after the July 2026 renaming
> migration described in [Migration Log](#migration-log-july-2026--generic-branchtenant-naming).
> Anything not yet re-verified after that migration is explicitly flagged
> "⚠ not yet re-verified".

---

## Table of Contents

1. [Migration Log (July 2026 — generic branch/tenant naming)](#migration-log-july-2026--generic-branchtenant-naming)
2. [Project Description & Purpose](#project-description--purpose)
3. [Technologies Used](#technologies-used)
4. [Folder / File Structure](#folder--file-structure)
5. [The BASE_PATH Constant](#the-base_path-constant)
6. [Deployment Environments](#deployment-environments)
7. [System Architecture](#system-architecture)
8. [Multi-Branch Model](#multi-branch-model)
9. [Multi-Tenant SaaS Architecture](#multi-tenant-saas-architecture)
10. [Database Overview](#database-overview)
11. [Authentication & Authorization](#authentication--authorization)
12. [Application Workflow](#application-workflow)
13. [Features](#features)
14. [Barcode Module](#barcode-module-generation--scanning)
15. [Purchase Invoice Currency-Mismatch Bug (fixed)](#purchase-invoice-currency-mismatch-bug-fixed)
16. [Consumables Module Findings](#consumables-module-findings)
17. [Security Notes](#security-notes)
18. [Known Limitations](#known-limitations)
19. [Future Improvements](#future-improvements)
20. [Troubleshooting](#troubleshooting)
21. [Development Guidelines](#development-guidelines)

---

## Migration Log (July 2026 — generic branch/tenant naming)

بهالجلسة عملنا سلسلة تعديلات لإزالة أي تسمية مرتبطة بمكان/عميل محدد
(Aleppo, Bayhas) من بنية النظام نفسها، تحضيراً لتحويله لمنتج SaaS عام.
هاي خلاصة كل شي اتغيّر، بالترتيب:

| # | التغيير | من | إلى | الحالة |
|---|---|---|---|---|
| 1 | لاحقة جداول قاعدة البيانات لفرع البيع الأول (٥٤ جدول) | `_alp` | `_ret` | ✅ منفّذ (`01_migrate_alp_to_ret.sql`) |
| 2 | اسم/نوع الفرع الأول بجدول `branches` | "فرع حلب"، `branch_type` متنوع | "فرع البيع ١"، `branch_type='retail'` | ✅ منفّذ |
| 3 | قيم `branches.branch_type` الممكنة | `retail/factory/warehouse/lab/office` | `retail/factory` فقط (ENUM مقيّد بقاعدة البيانات) | ✅ منفّذ |
| 4 | مجلد كود الفرع الأول | `aleppo/` | `retail1/` | ✅ منفّذ (فيزيائياً + بكل مراجع الكود) |
| 5 | مجلد جذر المشروع | `bayhas/` | `fatorize_erp_system/` | ✅ منفّذ **على نسخة اللوكل (Laragon) فقط** — نسخة Hostinger لسا ما انرينمت (انظر [بيئات النشر](#deployment-environments)) |
| 6 | مسارات مطلقة مكتوبة حرفياً بكل الكود (`/bayhas/...`) | سترينغ ثابت متكرر بعشرات الأماكن | ثابت مركزي واحد `BASE_PATH` | ✅ منفّذ (انظر [The BASE_PATH Constant](#the-base_path-constant)) |
| 7 | `config/create_branch_tables.php` — مراجع القالب المرجعي لأي فرع جديد | `SELECT * FROM sales_invoices_alp WHERE 0` وأخواتها (١٢ جدول) | نفس الاستعلامات لكن `_ret` | ✅ منفّذ |
| 8 | اسم قاعدة البيانات الفعلية بـ MySQL | `u987540206_bayhas` | (لم يتغيّر) | 🟡 قرار واعٍ: ما في داعي وظيفي لترينيمها — "الاسم المنطقي" المستقبلي (`tenant1` أو مشابه) رح يصير قيمة بعمود `subdomain` بجدول `tenants` المستقبلي فقط، مو اسم الـ DB الفعلي |
| 9 | محتوى `about.php` التسويقي (قصة بايهاس كعميل حقيقي أول) | — | (لم يتغيّر) | 🟢 مقصود — محتوى تسويقي حقيقي، مش جزء من منطق الـ tenant |

### ملفات الترحيل المُنتجة بهالجلسة (سجل مرجعي)
- `01_migrate_alp_to_ret.sql` — رينيم الـ ٥٤ جدول + تحديث صف الفرع + تقييد enum
- `02_rollback_ret_to_alp.sql` — سكريبت عكس احتياطي (غير مُستخدم)
- `03_update_dashboard_path.sql` — تحديث `branches.dashboard_path` بعد رينيم `aleppo→retail1` ثم `bayhas→fatorize_erp_system`
- `dashboard.php`, `sidebar.php`, `branches.php`, `create_branch_tables.php`, `purchases/index.php` — محدّثين ومرفوعين

### ⚠ نقطة مفتوحة يجب تتبعها
جرد يدوي كامل عبر VS Code ("Find in Files") عن `/bayhas/` تم على **نسخة
اللوكل (Laragon)** وأُعلن الانتهاء منه بالكامل. **نسخة Hostinger
(`F:\Programming\kaylink\FatoRize\bayhas\`) لسا بالحالة القديمة بالكامل**
— لسا فيها `config/database.php` بالنسخة البسيطة (اتصال DB ثابت، بدون
`tenant_resolver.php`)، ولسا مجلداتها `bayhas/` و`aleppo/` وجداولها `_alp`
لسا متل ما كانت. **لا تفترض تطابق البيئتين لحد ما تُعاد نفس خطوات
الترحيل كاملة على نسخة Hostinger.**

---

## Project Description & Purpose

**FATORIZE** هو ERP بُني أصلاً لشركة **بايهاس** (تصنيع/بيع ألبسة)، وعم
يتحوّل حالياً لمنتج **SaaS متعدد المستأجرين (multi-tenant)** يُباع لعدة
شركات مستقلة بقطاع الألبسة بالمنطقة العربية. النموذج المخطط:

- **قاعدة بيانات منفصلة لكل عميل (tenant)** — مو مشتركة.
- **نفس نسخة الكود بالضبط** مشتركة بين كل العملاء (لا يوجد مجلد منفصل
  لكل عميل — التمييز يصير عبر الساب-دومين وتحليل `tenant_resolver.php`).
- **داخل كل قاعدة بيانات عميل**، الموديل القديم (فروع متعددة بلاحقة
  اسم جدول، `_ret` مثلاً) باقٍ زي ما هو — هو المستوى الأدنى غير المتأثر
  بالـ SaaS pivot.
- **نوع الفرع الآن مقيّد بقيمتين فقط:** فرع بيع (`retail`) أو فرع
  تصنيع (`factory`) — لا أسماء جغرافية، لا أنواع تانية (مستودع/مختبر/مكتب
  أُزيلت من الواجهة والـ enum).

كل فرع بيع/تصنيع (بغض النظر عن التسمية القديمة) بيغطي: فوترة بيع/شراء،
مخزون متعدد المستودعات، محاسبة كاملة، HR/رواتب، مستهلكات، طلبات داخلية
بين الفروع، وصلاحيات دقيقة لكل مستخدم/فرع/موديول.

---

## Technologies Used

| Layer | Technology |
|---|---|
| Backend language | PHP (native, procedural, PHP 7.4+/8.x) |
| Database | MySQL/MariaDB via PDO + prepared statements |
| Frontend markup | HTML5, rendered inline by PHP |
| CSS | Custom `layout.css` + Bootstrap 5.3 RTL (CDN) + inline `<style>` blocks؛ + `marketing.css` منفصل للصفحات التسويقية العامة |
| JavaScript | Vanilla JS inline + `attendance_patch.js` |
| Password hashing | `password_hash()`/`password_verify()` (bcrypt) |
| Session | PHP native sessions |
| Data exchange | Server-rendered HTML + fetch/XHR AJAX بنمط موحّد `_action` |

لا فريمورك، لا ORM، لا Composer، لا build step.

---

## Folder / File Structure

> ⚠ التشجير تحت بيعكس نسخة **اللوكل (Laragon)** الحالية فقط، بعد
> الترحيل الكامل. نسخة Hostinger لسا بالبنية القديمة (`bayhas/aleppo/...`)
> — انظر [بيئات النشر](#deployment-environments).

```
fatorize_erp_system/                  (جذر المشروع — كان اسمه bayhas/)
├── login.php
├── logout.php
├── select_account.php
├── reset_password.php                ⚠ لسا موجود، لازم يُحذف قبل أي إنتاج حقيقي
├── index.php                         الصفحة الرئيسية التسويقية (SaaS landing)
├── about.php                         صفحة "من نحن" التسويقية
├── find-my-company.php               بحث عن تينانت بالساب-دومين (SaaS)
│
├── config/
│   ├── database.php                  ⚠ نسختان مختلفتان بين البيئتين — انظر أدناه
│   ├── auth.php
│   ├── create_branch_tables.php      ✅ محدّث: القالب المرجعي بيستنسخ من _ret الآن
│   ├── master_database.php           (SaaS) اتصال قاعدة بيانات المنصة المركزية
│   └── tenant_resolver.php           (SaaS) تحليل الساب-دومين → أي DB يتصل فيها
│
├── includes/
│   ├── sidebar.php                   ✅ محدّث: BASE_PATH + retail1 بدل aleppo
│   └── product_save_helper.php
│
├── assets/
│   ├── css/ (layout.css, marketing.css)
│   └── images/ (logo.png, fatorize.png, bayhas_logo.png — شعار طباعة حقيقي، لم يُمس)
│
├── retail1/                           فرع البيع الأول (كان aleppo/، لاحقة الجداول ret)
│   ├── assets/
│   ├── api/
│   │   ├── confirm_sale_invoice.php
│   │   ├── confirm_purchase_invoice.php
│   │   ├── payroll_api.php
│   │   ├── get_currencies.php
│   │   └── test_api.php
│   └── modules/
│       ├── dashboard.php             ✅ محدّث: يتحقق من table_suffix==='ret'، BASE_PATH
│       ├── sales/
│       ├── purchases/                ✅ index.php محدّث (LOGO_URL بيستخدم BASE_PATH)
│       ├── inventory/
│       │   └── barcode.php
│       ├── accounting/
│       │   └── branches.php ⚠ (تأكد المسار — راجع admin/ أدناه، النسخة الأخيرة محدّثة: retail/factory بس)
│       ├── hr/
│       └── admin/
│           ├── users.php
│           ├── permissions.php
│           └── branches.php          ✅ محدّث: branchTypes = retail/factory فقط
│
└── logs/
    └── php-error.log
```

**فروع تانية (Istanbul, Gaziantep, labs):** schema-only، ما عندها كود
حقيقي بعد — خريطة `sidebar.php::moduleUrl()` لسا محتفظة بمداخل مرجعية
إلها (`ist`, `gaz`, `lab`, `alp_lab`) لحد ما يتبنى هيكل عام حقيقي لفرع
تصنيع/بيع إضافي، بس مو مستخدمة فعلياً حالياً.

---

## The BASE_PATH Constant

أُضيف بهالجلسة كنقطة تحكم مركزية وحيدة لمسار جذر التطبيق، بدل عشرات
المسارات المطلقة المكتوبة حرفياً (`/bayhas/...`) يلي كانت متناثرة بكل
الكود.

```php
// أول سطر بـ config/database.php (بعد التعليق التوضيحي، قبل أي شي تاني)
if (!defined('BASE_PATH')) define('BASE_PATH', '/fatorize_erp_system');
```

**كل مكان بالكود كان فيه مسار مطلق حرفي**، صار يستخدمها بدل السترينغ
الثابت:

```php
// قبل
header('Location: /bayhas/select_account.php');
<img src="/bayhas/assets/images/logo.png">
$base = '/bayhas/' . $branchFolder . '/modules/';

// بعد
header('Location: ' . BASE_PATH . '/select_account.php');
<img src="<?= BASE_PATH ?>/assets/images/logo.png">
$base = BASE_PATH . '/' . $branchFolder . '/modules/';
```

**قيمتها تختلف حسب البيئة والانتشار المستقبلي:**

| السياق | القيمة |
|---|---|
| نسخة اللوكل حالياً (Laragon، `http://localhost/fatorize_erp_system/...`) | `/fatorize_erp_system` |
| نسخة Hostinger حالياً (لسا ما انرينمت، `/bayhas/...`) | `/bayhas` (لسا ما اتحدثت فعلياً) |
| لو استُخدم دومين وهمي محلي (`fatorize.test` بدل `localhost/...`) | `''` (فاضي) |
| النشر الفعلي المستقبلي بساب-دومين لكل عميل (`tenant1.fatorize.com`) | `''` (فاضي) — كل ساب-دومين بيوصل لجذر الكود مباشرة |

**ملفات ما زالت محتاجة تدقيق يدوي على نسخة Hostinger تحديداً** (لأنه
الجرد اليدوي عبر VS Code تم على نسخة اللوكل بس): أي ملف فيه `/bayhas/`
حرفياً — نفس منهجية البحث والاستبدال المستخدمة سابقاً على اللوكل.

---

## Deployment Environments

| | نسخة اللوكل (Laragon) | نسخة Hostinger (إنتاج) |
|---|---|---|
| المسار الفيزيائي | `D:\laragon\www\fatorize_erp_system\` | `F:\Programming\kaylink\FatoRize\bayhas\` ⚠ لسا باسمها القديم |
| رابط الوصول | `http://localhost/fatorize_erp_system/...` | (دومين حقيقي — غير موثّق هون) |
| `config/database.php` | **نسخة SaaS**: `tenant_resolver.php` مفعّل، الاتصال بـ DB يتحدد ديناميكياً من الساب-دومين | **نسخة بسيطة قديمة**: اتصال ثابت مباشر بـ `u987540206_bayhas` عبر `define('DB_NAME', ...)` — ما فيها `tenant_resolver.php` إطلاقاً |
| لاحقة جداول الفرع الأول | `_ret` ✅ | `_alp` ⚠ لسا ما اترحّلت |
| مجلد الفرع الأول | `retail1/` ✅ | `aleppo/` ⚠ لسا |
| مجلد الجذر | `fatorize_erp_system/` ✅ | `bayhas/` ⚠ لسا |
| `BASE_PATH` | معرّف، `/fatorize_erp_system` ✅ | غير موجود إطلاقاً بهالنسخة ⚠ |

**هاد فرق جوهري ومقصود مؤقتاً** — عم نجرّب كل الترحيل والبنية الجديدة
(retail/factory، BASE_PATH، SaaS resolver) على اللوكل أول، وبعدين ننزلها
دفعة وحدة عالـ Hostinger لما نتأكد كل شي شغال صح. **لا تفترض أي سلوك أو
بنية موحّدة بين البيئتين لحد ما يُذكر خلاف ذلك بتحديث لاحق لهالتوثيق.**

---

## System Architecture

نفس البنية الأصلية — كل ملف PHP هو controller + view بنفس الوقت، لا
MVC، لا فصل طبقات. النمط القياسي لأي صفحة موديول:

```php
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';
$pdo = getConnection();
checkLogin($pdo);
requirePermission('module.key', 'view');
$TS = $_SESSION['table_suffix'];       // الآن "ret" لفرع البيع الأول
$table = "products_{$TS}";
```

---

## Multi-Branch Model

| Concept | Detail |
|---|---|
| قاعدة بيانات كل تينانت | واحدة، مشتركة بين كل فروعه |
| عزل البيانات بين الفروع | لاحقة اسم جدول (`table_suffix`) |
| نوع الفرع | `branch_type` ENUM مقيّد الآن لـ `retail`/`factory` فقط |
| مفاتيح الجلسة | `table_suffix`, `branch_id`, `branch_name`, `dashboard_path` |
| توفير فرع جديد | `config/create_branch_tables.php::createBranchTables()` — القالب المرجعي بيستنسخ بنية `_ret` الآن (بعد الإصلاح) |

| الفرع | `table_suffix` | مجلد الكود | الحالة |
|---|---|---|---|
| فرع البيع ١ (كان "حلب") | `ret` | `retail1/` | ✅ الوحيد اللي عنده كود حقيقي شغّال |
| باقي الفروع (Istanbul, Gaziantep, labs) | `ist`/`gaz`/`lab`/`alp_lab` | مرجعية بـ `sidebar.php` فقط | Schema-only، بدون كود |

`dashboard.php` يتحقق حالياً من `table_suffix === 'ret'` بشكل ثابت
(نفس نمط الفحص القديم `=== 'alp'`، بس بالقيمة الجديدة) — **لسا نقطة
ضعف معمارية** لو صار فرع بيع تاني بالمستقبل، لازم يصير الفحص على
`branch_type === 'retail'` بدل قيمة `table_suffix` ثابتة (موثّق أيضاً
بـ [Known Limitations](#known-limitations)).

---

## Multi-Tenant SaaS Architecture

**الحالة: الطبقة التأسيسية مبنية ومفعّلة فعلياً على نسخة اللوكل، غير
منزّلة على نسخة الإنتاج (Hostinger) بعد.**

### القرارات
| القرار | الاختيار |
|---|---|
| عزل البيانات | قاعدة بيانات منفصلة كاملة لكل تينانت |
| تمييز التينانت | ساب-دومين (`tenant1.fatorize.com`) |
| موديل الفروع/الجداول داخل كل تينانت | غير متأثر — نفس الـ `table_suffix` |
| اسم قاعدة البيانات الفعلي (تينانت بايهاس الحالي) | باقٍ `u987540206_bayhas` — الاسم المنطقي المستقبلي (`tenant1` مثلاً) بيصير بعمود `subdomain` بجدول `tenants` بس، مو اسم الـ DB نفسه |

### ما هو موجود فعلياً الآن
- `config/master_database.php` — اتصال قاعدة بيانات المنصة المركزية
- `config/tenant_resolver.php` — `resolveCurrentTenant()`
- `config/database.php` (نسخة اللوكل) — `getConnection()` بيحلّ التينانت أول من الساب-دومين، بعدين يتصل بقاعدته
- `find-my-company.php`, `index.php`, `about.php` — صفحات تسويقية عامة، لا تتصل بقاعدة بيانات تينانت معيّن

### ⚠ لسا غير مفعّل/موثّق بالكامل
- نسخة Hostinger (الإنتاج) لسا على النسخة القديمة single-DB، غير موصولة بـ `tenant_resolver.php` إطلاقاً
- ما تم اختبار تسجيل دخول فعلي عبر ساب-دومين حقيقي بعد (الاختبار الحالي كله عبر `localhost` بدون ساب-دومين)
- `super_admin/add_tenant.php` (موثّق بجلسات سابقة) لم يُعاد التحقق منه بعد هالترحيل

---

## Database Overview

**قاعدة البيانات (تينانت بايهاس الحالي):** `u987540206_bayhas` (الاسم لم
يتغيّر — انظر [Migration Log](#migration-log-july-2026--generic-branchtenant-naming) نقطة ٨).

### جداول عامة (بدون لاحقة) — لم تتأثر بالترحيل
`users`, `user_branches`, `user_permissions`, `user_activities`,
`branches`, `modules`, `currencies`, `shipping_carriers`,
`internal_orders`, `internal_order_items`

### جداول الفرع الأول — لاحقتها الآن `_ret` (كانت `_alp`)
نفس الـ ٥٤ جدول الموثّقة سابقاً (منتجات، مخزون، مبيعات، مشتريات،
محاسبة، مستهلكات، HR، تصنيع، إشعارات) — القائمة الكاملة موجودة بملف
`01_migrate_alp_to_ret.sql` كمرجع دقيق لكل اسم جدول قبل/بعد.

> **الجداول القديمة الميتة** (`employees_alp`→`employees_ret`,
> `attendance_alp`→`attendance_ret`, `payroll_alp`→`payroll_ret`,
> `consumables_alp`→`consumables_ret`, `consumable_entries_alp`→
> `consumable_entries_ret`) **اترحّلت هي كمان** لنفس لاحقة `_ret` رغم
> إنها ميتة (الكود الفعلي بيستخدم النسخ `hr_*`)، حفاظاً على الاتساق —
> بس لسا ميتة وظيفياً، وما زالت مرشّحة للحذف لاحقاً.

---

## Authentication & Authorization

لم يتغيّر أي شي هون بهالجلسة — `config/auth.php` وكل دوال RBAC
(`checkLogin`, `loadUserPermissions`, `can`, `requirePermission`,
`buildSidebarMenu`) زي ما هي تماماً. راجع التوثيق الأصلي لتفاصيل تدفق
تسجيل الدخول.

---

## Application Workflow

لم تتأثر منطقياً بالترحيل — دورة حياة فاتورة البيع والشراء زي ما هي
(تأكيد كامل عبر `api/confirm_sale_invoice.php`/`confirm_purchase_invoice.php`
حصراً). المسارات الفيزيائية بس تغيّرت (`retail1/api/...` بدل
`aleppo/api/...`).

---

## Features

لم يتغيّر أي feature وظيفياً بهالجلسة — الترحيل كان تسمية/بنية فقط، صفر
تغيير بالمنطق التجاري. راجع الجدول الأصلي بالتوثيق السابق لكل الميزات
المفعّلة (فوترة، مخزون، محاسبة، HR، باركود، طلبات داخلية...).

---

## Barcode Module (Generation & Scanning)

لم يتأثر بالترحيل — `inventory/barcode.php` (الآن بمسار
`retail1/modules/inventory/barcode.php`) ونفس منطق `generateFallbackBarcode()`
و`syncProductVariants()` بـ `includes/product_save_helper.php`.

---

## Purchase Invoice Currency-Mismatch Bug (fixed)

نفس الإصلاح الموثّق سابقاً — لم يتأثر بترحيل التسمية. الملف الآن بمسار
`retail1/modules/purchases/invoice_new.php` و`invoice_edit.php` بدل
`aleppo/...`.

---

## Consumables Module Findings

**جلسة فحص المخزون الكامل (يوليو ٢٠٢٦) — تحديثات مهمة:**

1. **بند العزل بين الفروع (`_alp` hardcoding) — ⚠ رجع كبق، وانصلح من جديد.**
   إصلاح جلسة سابقة لـ`consumables.php`/`consumable_purchases.php` (استبدال `_alp`
   الحرفية بـ`{$TS}`) **ما وصل فعلياً لنسخة المشروع الحقيقية** — لما رفع
   المستخدم الملفين من جديد لهالجلسة، طلعوا بنفس الحالة القديمة (بس بقيمة
   `_alp` حرفية، حتى بعد ترحيل بقية النظام لـ`_ret`). **أُعيد الإصلاح
   ورُفع من جديد** — يُنصح بشدة بالتأكد من حفظه بمكان دائم (Git أو نسخة
   احتياطية واضحة) هالمرة، تفادياً لتكرار الضياع.

2. **بند القيد المحاسبي لمشتريات المستهلكات — لسا مفتوح.** لم يُلمس
   بهالجلسة (نفس الفجوة الموثّقة سابقاً).

3. **🆕 بند جديد مؤكّد: `consumable_issues.php`'s `confirm_issue` كانت
   نفس الفجوة بالضبط (بدون قيد محاسبي، رغم `is_posted=1`) — أخطر من
   فجوة المشتريات لأن الحالة كانت "تكذب". ✅ تم إصلاحه بهالجلسة** — أضيف
   قيد محاسبي كامل (مدين `consumable_expense` / دائن `consumable_inventory`،
   بالاعتماد على مفاتيح `invoice_account_settings_{TS}` الموجودة أصلاً).

4. **🆕 `internal_orders.php`'s `convert_to_purchase` — بق حقيقي مؤكّد،
   تم إصلاحه.** كانت تُنشئ فاتورة شراء بحالة `status='confirmed'` مباشرة
   بدون أي تحديث فعلي للمخزون أو ترحيل قيد محاسبي — حالة "كاذبة". ✅
   الآن تُنشأ كمسودة (`draft`) بأمانة، ويكمّلها المستخدم عبر مسار التأكيد
   الموحّد الموجود أصلاً بقائمة فواتير الشراء.

5. **🆕 فكرة ميزة مؤجّلة (مسجّلة، غير مبنية):** استبدال/تجاهل
   `consumable_sales_ret`/`consumable_sale_items_ret` (لا صفحة UI لها،
   ولا حاجة فعلية — الشركة لا "تبيع" مستهلكات لعميل) لصالح ميزة **نقل
   مستهلكات بين الفروع**، بنفس نمط `internal_orders`/`internal_order_items`
   الموجود لمنتجات (`from_branch_id`→`to_branch_id`). ملاحظة تقنية: عمود
   `consumable_movements_{TS}.movement_type` عنده أصلاً قيمة `'transfer'`
   ضمن enum الموجود — البنية جاهزة جزئياً، ناقصها فقط جداول
   `consumable_transfers`/`consumable_transfer_items` (عامة، بنفس نمط
   `internal_orders`) + واجهة. **لم تُبنَ بعد — مؤجّلة حسب قرار صريح**
   لحين الانتهاء من فحص الأقسام الأساسية الحالية أولاً.

الجداول المعنية كلها صارت بلاحقة `_ret` (كانت `_alp`)، بدون أي تغيير
إضافي بالمنطق غير المذكور أعلاه.

---

## Security Notes

| Severity | Issue | الحالة بعد الترحيل |
|---|---|---|
| 🔴 Critical | بيانات اتصال DB حرفية بالكود | لم تتغيّر — لسا موجودة بكلا نسختي `config/database.php` |
| 🔴 Critical | `reset_password.php` بدون مصادقة حقيقية | لم تتغيّر — لسا موجود، لازم يُحذف قبل أي إنتاج |
| 🔴 Critical | `hr/test_db.php` بيطبع `$_SESSION` كامل | لم يُراجع بعد هالترحيل — ⚠ تأكد مساره لسا صحيح تحت `retail1/` |
| 🟠 High | `payroll_api.php` بدون `requirePermission()` | لم تتغيّر |
| 🟠 High | `get_currencies.php` بدون auth | لم تتغيّر |
| 🟡 Medium | لا CSRF tokens بأي مكان | لم تتغيّر |
| 🟡 Medium | `dashboard.php` فحص `table_suffix==='ret'` ثابت — نفس نمط الضعف القديم بس بقيمة جديدة | **جديد بالتوثيق، قديم بالجوهر** — راجع Known Limitations |
| 🟢 جديد بهالجلسة | مسارات مطلقة `/bayhas/` كانت متناثرة بعشرات الأماكن — تم تجميعها بثابت `BASE_PATH` واحد | ✅ مُصلح على اللوكل، ⚠ Hostinger لسا |
| 🟡 Medium | `products.php`: `display_errors=1` مفعّل بالإنتاج | ✅ **مُصلح بهالجلسة** — صار `0` |

---

## Known Limitations

1. **فرع واحد فقط (`retail1/`, لاحقة `ret`) عنده كود شغّال** — باقي
   الفروع (Istanbul, Gaziantep, labs) schema-only بدون صفحات حقيقية.
2. **`dashboard.php` لسا بيفحص قيمة `table_suffix` ثابتة** (`'ret'` بدل
   `'alp'` القديمة) بدل التحقق من `branch_type==='retail'` ديناميكياً —
   لو انضاف فرع بيع تاني بالمستقبل، لازم هالفحص يتعمم أولاً.
3. **الجداول القديمة الميتة** (`employees_ret`, `attendance_ret`,
   `payroll_ret`, `consumables_ret`, `consumable_entries_ret`) لسا
   موجودة (اترحّلت اسمياً بس بقيت ميتة وظيفياً).
4. **نسخة Hostinger (الإنتاج) لسا بالكامل على البنية القديمة** — `bayhas/`،
   `aleppo/`، `_alp`، بدون `BASE_PATH`، بدون `tenant_resolver.php`. أي
   قرار نشر فعلي لازم ينتظر ترحيل كامل مطابق لنسخة اللوكل أولاً.
5. **لا تزال هناك احتمالية لمسارات `/bayhas/` متبقية غير مكتشفة** حتى
   على نسخة اللوكل — الجرد تم يدوياً عبر "Find in Files"، وهاي طريقة
   عرضة لتفويت أنماط غير متوقعة (مثل `$base = '/bayhas/'` يلي فات أول
   مرة). يُنصح بجرد آلي شامل (`grep -rn "bayhas" --include=*.php .`
   بدون أي شرط قبل/بعد) كخطوة تحقق أخيرة.
6. **مسار `admin/branches.php` بالتشجير أعلاه غير مؤكد ١٠٠٪** — ظهر
   بالتوثيق الأصلي تحت `admin/branches.php`، بس المحتوى الفعلي يلي
   عدّلناه أشار لمسار مشابه بقسم `accounting/` بنسخة قديمة من التوثيق؛
   يحتاج تأكيد مباشر من التشجير الفعلي بالسيرفر.
7. **لا CSRF، لا rate limiting، لا اختبارات آلية** — لم تتغيّر بهالجلسة.

---

## Future Improvements

- [ ] ترحيل نسخة Hostinger بالكامل لتطابق نسخة اللوكل (كل خطوات هالجلسة)
- [ ] تعميم فحص `dashboard.php` ليعتمد `branch_type` بدل `table_suffix` ثابت
- [ ] جرد آلي نهائي (`grep -rn "bayhas"`) على كلا البيئتين
- [ ] حذف الجداول الميتة القديمة (`employees_ret` وأخواتها) بعد تأكيد عدم استخدامها
- [ ] بناء وتفعيل باقي طبقة الـ SaaS (تسجيل تينانت جديد، تسجيل دخول فعلي عبر ساب-دومين حقيقي)
- [ ] حذف `reset_password.php`, `hr/test_db.php`, `api/test_api.php` قبل أي إنتاج حقيقي
- [ ] إضافة CSRF tokens
- [ ] **🆕 ميزة نقل مستهلكات بين الفروع** — بدل `consumable_sales_ret` (بلا UI ولا حاجة فعلية). نفس نمط `internal_orders`/`internal_order_items`: جداول عامة جديدة `consumable_transfers`/`consumable_transfer_items` (`from_branch_id`→`to_branch_id`)، تُنتج عند التأكيد حركتين بجدولي `consumable_movements_{TS}` (فرع المصدر وفرع الوجهة) باستخدام قيمة `movement_type='transfer'` الموجودة أصلاً بالـ enum. غير مبنية بعد — مؤجّلة بقرار صريح لحين إنهاء فحص الأقسام الأساسية.
- [ ] مراجعة `consumable_sale_items_ret`/`consumable_sales_ret` لاحقاً: حذف نهائي أم إبقاء schema-only كالجداول الميتة الأخرى (قرار غير مُتخذ بعد)

---

## Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| صفحة بيضاء تماماً | افتح `logs/php-error.log` — `display_errors` مطفي بشكل متعمد بأغلب الملفات |
| "Branch not supported" / إعادة توجيه من الداشبورد | `dashboard.php` بيقبل بس `table_suffix === 'ret'` حالياً |
| روابط/صور مكسورة (404) بعد أي رينيم مستقبلي | دوّر عن `/bayhas/` أو أي مسار مطلق قديم لم يُستبدل بـ `BASE_PATH` |
| سلوك مختلف بين اللوكل و Hostinger | متوقّع حالياً — البيئتين غير متطابقتين بعد، راجع [Deployment Environments](#deployment-environments) |

---

## Development Guidelines

### قاعدة تسمية الجداول
```php
$table = "products_{$TS}";  // استخدم $_SESSION['table_suffix'] دائماً، لا تكتب "_ret" حرفياً بأي مكان
```

### قاعدة المسارات المطلقة
```php
// ممنوع:
header('Location: /fatorize_erp_system/select_account.php');

// صح:
header('Location: ' . BASE_PATH . '/select_account.php');
```

### إضافة صفحة جديدة
نفس الخطوات الموثّقة سابقاً، بس تأكد أي مسار مطلق جديد تكتبه يستخدم
`BASE_PATH` من أول يوم، ما تكتب `/fatorize_erp_system/` أو `/bayhas/`
حرفياً بأي مكان جديد.

---

*هالنسخة من التوثيق محدّثة حتى نهاية جلسة ترحيل التسمية (يوليو ٢٠٢٦).
أي قسم غير مذكور تفصيلياً هون (Features الكاملة، الـ ERD، الجداول
التفصيلية بالأعمدة) لم يتغيّر عن التوثيق الأصلي ولسا صالح كما هو —
هالنسخة بتركّز على توثيق التغييرات البنيوية فقط لتفادي التكرار.*
