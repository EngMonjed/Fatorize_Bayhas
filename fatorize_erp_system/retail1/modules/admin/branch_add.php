<?php
/**
 * admin/branch_add.php — إنشاء فرع جديد
 * المسار: retail1/modules/admin/branch_add.php
 *
 * ⚠ بعكس branches.php (تعديل فرع موجود، العملة الوظيفية مجمّدة فيها)،
 * هالصفحة هي المكان الوحيد بالمشروع يلي العملة الوظيفية قابلة
 * للاختيار — لأنها بتُحدَّد مرة وحدة بس، وقت الإنشاء بالضبط (IAS 21).
 */

session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('admin.branch_add', 'create');
$currentModule = 'admin.branch_add';
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($_POST['_action'] === 'check_suffix') {
            $suffix = strtolower(trim($_POST['table_suffix'] ?? ''));
            $st = $pdo->prepare("SELECT COUNT(*) FROM branches WHERE table_suffix = ?");
            $st->execute([$suffix]);
            echo json_encode(['ok' => true, 'available' => $st->fetchColumn() == 0]);
            exit;
        }

        if ($_POST['_action'] === 'create') {
            $name = trim($_POST['name'] ?? '');
            $branchType = $_POST['branch_type'] ?? '';
            $tableSuffix = strtolower(trim($_POST['table_suffix'] ?? ''));
            $code = strtoupper(trim($_POST['code'] ?? ''));
            $baseCurrencyId = (int) ($_POST['base_currency_id'] ?? 0);
            $localCurrencyId = (int) ($_POST['local_currency_id'] ?? 0);

            if ($name === '') throw new Exception('اسم الفرع مطلوب');
            if (!in_array($branchType, ['retail', 'factory'], true)) throw new Exception('نوع الفرع مطلوب (بيع أو تصنيع)');
            if (!preg_match('/^[a-z0-9]{2,10}$/', $tableSuffix)) throw new Exception('لاحقة الجداول لازم تكون حروف/أرقام إنجليزية صغيرة، ٢-١٠ خانات');
            if ($baseCurrencyId <= 0) throw new Exception('العملة الوظيفية مطلوبة');
            if ($localCurrencyId <= 0) throw new Exception('العملة المحلية مطلوبة');

            $dup = $pdo->prepare("SELECT COUNT(*) FROM branches WHERE table_suffix = ?");
            $dup->execute([$tableSuffix]);
            if ($dup->fetchColumn() > 0) throw new Exception('لاحقة الجداول هاي مستخدمة أصلاً بفرع تاني');

            if ($code !== '') {
                $dupCode = $pdo->prepare("SELECT COUNT(*) FROM branches WHERE code = ?");
                $dupCode->execute([$code]);
                if ($dupCode->fetchColumn() > 0) throw new Exception('كود الفرع هاد مستخدم أصلاً');
            }

            $curStmt = $pdo->prepare("SELECT id, code FROM currencies WHERE id IN (?, ?)");
            $curStmt->execute([$baseCurrencyId, $localCurrencyId]);
            $curMap = [];
            foreach ($curStmt->fetchAll(PDO::FETCH_ASSOC) as $c) $curMap[$c['id']] = $c['code'];
            if (!isset($curMap[$baseCurrencyId]) || !isset($curMap[$localCurrencyId])) {
                throw new Exception('عملة غير صالحة');
            }

            // ⚠ dashboard_path: كل الفروع (بيع أو تصنيع) بتشارك نفس
            // المجلد الفيزيائي retail1/modules/ — التمييز بينهم بـ
            // table_suffix/branch_type بالجلسة، مش بمجلد منفصل لكل فرع
            // (قرار معماري مقصود — راجع نقاش "factory1 مقابل production/").
            $dashboardPath = 'retail1/modules/dashboard.php';

            $pdo->beginTransaction();

            $pdo->prepare("INSERT INTO branches
                (name, name_en, tenant_slogan, branch_type, phone, email, address, city, country,
                 tax_number, commercial_registration_number, factory_branch_id,
                 base_currency, base_currency_id, local_currency, local_currency_id,
                 pricing_method, default_margin_pct, costing_method,
                 tax_rate_default, tax_input_recoverable, default_purchase_tax_pct,
                 allow_negative_stock, notify_low_stock, notify_new_invoice, notify_internal_order, notify_email,
                 invoice_prefix, fiscal_year_start, week_start_day, default_payment_terms,
                 code, table_suffix, dashboard_path, icon, color, sort_order, status, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,
                        ?,?,?,
                        ?,?,?,?,
                        ?,?,?,
                        ?,?,?,
                        ?,?,?,?,?,
                        ?,?,?,?,
                        ?,?,?,?,?,?,'active',?)")
                ->execute([
                    $name,
                    ($_POST['name_en'] ?? '') ?: null,
                    ($_POST['tenant_slogan'] ?? '') ?: null,
                    $branchType,
                    ($_POST['phone'] ?? '') ?: null,
                    ($_POST['email'] ?? '') ?: null,
                    ($_POST['address'] ?? '') ?: null,
                    ($_POST['city'] ?? '') ?: null,
                    ($_POST['country'] ?? '') ?: 'Syria',
                    ($_POST['tax_number'] ?? '') ?: null,
                    ($_POST['commercial_registration_number'] ?? '') ?: null,
                    ($_POST['factory_branch_id'] ?? '') ?: null,
                    $curMap[$baseCurrencyId], $baseCurrencyId,
                    $curMap[$localCurrencyId], $localCurrencyId,
                    ($_POST['pricing_method'] ?? '') ?: 'cost_plus',
                    ($_POST['default_margin_pct'] ?? '') ?: 20,
                    ($_POST['costing_method'] ?? '') ?: 'last_cost',
                    ($_POST['tax_rate_default'] ?? '') ?: 0,
                    ($_POST['tax_input_recoverable'] ?? '') ?: 'non_recoverable',
                    ($_POST['default_purchase_tax_pct'] ?? '') ?: 0,
                    isset($_POST['allow_negative_stock']) ? 1 : 0,
                    isset($_POST['notify_low_stock']) ? 1 : 0,
                    isset($_POST['notify_new_invoice']) ? 1 : 0,
                    isset($_POST['notify_internal_order']) ? 1 : 0,
                    ($_POST['notify_email'] ?? '') ?: null,
                    ($_POST['invoice_prefix'] ?? '') ?: 'INV',
                    ($_POST['fiscal_year_start'] ?? '') ?: 1,
                    ($_POST['week_start_day'] ?? '') ?: 1,
                    ($_POST['default_payment_terms'] ?? '') ?: 30,
                    $code ?: null,
                    $tableSuffix,
                    $dashboardPath,
                    $branchType === 'factory' ? 'bi-gear-fill' : 'bi-shop',
                    $branchType === 'factory' ? '#10b981' : '#3b82f6',
                    (int) $pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM branches")->fetchColumn(),
                    $_SESSION['user_id'],
                ]);
            $newBranchId = (int) $pdo->lastInsertId();

            // ✅ مُصلح: commit الفرع هون مباشرة، قبل أي DDL — أوامر
            // CREATE TABLE بـMySQL بتعمل commit ضمني تلقائي، فلو ضلينا
            // بنفس الـtransaction، أول CREATE TABLE جوا createBranchTables()
            // كانت تقفل الـtransaction بصمت، وبعدها commit() بالآخر يرمي
            // "There is no active transaction". فصل الاثنين هو الحل
            // الصحيح الوحيد — DDL أصلاً مش transactional بـMySQL.
            $pdo->commit();

            // ✅ منح المستخدم رقم ١ (مدير النظام) وصول كامل تلقائياً
            // لأي فرع جديد — ربط بالفرع + كل الصلاحيات السبعة على كل
            // الأقسام الموجودة فعلياً بجدول modules وقت الإنشاء
            try {
                $pdo->prepare("INSERT IGNORE INTO user_branches (user_id, branch_id) VALUES (1, ?)")
                    ->execute([$newBranchId]);

                $allModuleKeys = $pdo->query("SELECT `key` FROM modules WHERE is_active=1")->fetchAll(PDO::FETCH_COLUMN);
                $grantStmt = $pdo->prepare("INSERT IGNORE INTO user_permissions
                    (user_id, branch_id, module_key, can_view, can_create, can_edit, can_delete, can_confirm, can_print, can_export, granted_by, created_at)
                    VALUES (1, ?, ?, 1,1,1,1,1,1,1, ?, NOW())");
                foreach ($allModuleKeys as $moduleKey) {
                    $grantStmt->execute([$newBranchId, $moduleKey, $_SESSION['user_id']]);
                }
            } catch (Throwable $e) {
                // ⚠ ما نوقف إنشاء الفرع لو فشلت هالخطوة — الفرع أصلاً
            // انحفظ صح، بس نسجّل الخطأ لمراجعة يدوية لاحقة
                error_log('branch_add: فشل منح صلاحيات المستخدم 1 للفرع ' . $newBranchId . ': ' . $e->getMessage());
            }

            $tablesResult = null;
            if (!empty($_POST['create_tables_now'])) {
                require_once __DIR__ . '/../../../config/create_branch_tables.php';
                $tablesResult = createBranchTables($pdo, $tableSuffix, $branchType);
            }

            echo json_encode([
                'ok' => true,
                'msg' => 'تم إنشاء الفرع بنجاح، ومُنح المستخدم رقم 1 وصول كامل تلقائياً',
                'branch_id' => $newBranchId,
                'tables' => $tablesResult,
            ]);
        } else {
            echo json_encode(['ok' => false, 'msg' => 'إجراء غير معروف']);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── بيانات الصفحة ────────────────────────────────────────────────
$currencies = $pdo->query("SELECT id, code, name, symbol FROM currencies WHERE status='active' ORDER BY is_base DESC, id")->fetchAll(PDO::FETCH_ASSOC);
$existingBranches = $pdo->query("SELECT id, name, branch_type FROM branches WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$pricingMethods = ['cost_plus' => 'تكلفة + هامش ربح', 'fixed' => 'سعر ثابت', 'market' => 'سعر السوق'];
$costingMethods = ['last_cost' => 'آخر تكلفة', 'weighted_average' => 'متوسط مرجّح'];
$taxHandlingModes = [
    'non_recoverable' => 'غير قابلة للاسترداد — تُضاف لتكلفة المخزون',
    'recoverable' => 'قابلة للاسترداد — تُسجَّل كذمة منفصلة',
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>إنشاء فرع جديد — <?= htmlspecialchars($branchName) ?></title>
<link rel="icon" href="<?= BASE_PATH ?>/assets/images/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="../../assets/css/layout.css" rel="stylesheet">
<style>
.section-label{font-weight:700;font-size:.85rem;color:#475569;margin-bottom:.75rem;display:flex;align-items:center;gap:.4rem}
.form-label-sm{font-size:.8rem;font-weight:600;color:#334155;margin-bottom:.3rem;display:block}
.type-card{border:2px solid #e2e8f0;border-radius:14px;padding:1.25rem;cursor:pointer;text-align:center;transition:all .2s}
.type-card:hover{border-color:#94a3b8}
.type-card.selected{border-color:var(--section-color,#3b82f6);background:color-mix(in srgb, var(--section-color,#3b82f6) 8%, white)}
.type-card i{font-size:1.8rem}
</style>
</head>
<body>
<div class="sb-overlay" id="sbOverlay"></div>
<?php
require_once __DIR__ . '/../../../includes/sidebar.php';
require_once __DIR__ . '/../../../includes/breadcrumb.php';
?>
<header class="topbar">
    <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
    <span class="tb-title"><i class="bi bi-building-add me-1 text-primary"></i>إنشاء فرع جديد</span>
    <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
    <?= renderBreadcrumb() ?>
</header>

<main class="main-content">
<div class="content-body" style="max-width:900px">

    <div class="alert alert-info d-flex align-items-center gap-2" style="border-radius:10px;font-size:.85rem">
        <i class="bi bi-info-circle fs-5"></i>
        <div>العملة الوظيفية تُحدَّد هون فقط، ولا تتغيّر بعد الإنشاء (تُقفل تلقائياً من صفحة "إدارة الفروع"). تأكد من اختيارها صح قبل الحفظ.</div>
    </div>

    <form id="branchForm">
        <div class="section-label"><i class="bi bi-diagram-3"></i> نوع الفرع</div>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="type-card" data-type="retail" onclick="selectType('retail')">
                    <i class="bi bi-shop text-primary"></i>
                    <div class="fw-600 mt-2">فرع بيع</div>
                    <div class="text-muted" style="font-size:.75rem">مبيعات، مخزون منتجات، عملاء</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="type-card" data-type="factory" onclick="selectType('factory')">
                    <i class="bi bi-gear-fill text-success"></i>
                    <div class="fw-600 mt-2">فرع تصنيع</div>
                    <div class="text-muted" style="font-size:.75rem">إنتاج، مواد أولية، خطوط تصنيع</div>
                </div>
            </div>
        </div>
        <input type="hidden" id="f_branch_type" required>

        <div class="section-label"><i class="bi bi-card-text"></i> بيانات أساسية</div>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label-sm">اسم الفرع *</label>
                <input type="text" id="f_name" class="form-control" required>
            </div>
            <div class="col-md-6">
                <label class="form-label-sm">الاسم بالإنجليزية</label>
                <input type="text" id="f_name_en" class="form-control" dir="ltr">
            </div>
            <div class="col-md-6">
                <label class="form-label-sm">كود الفرع (اختياري، حروف كبيرة)</label>
                <input type="text" id="f_code" class="form-control" dir="ltr" style="text-transform:uppercase">
            </div>
            <div class="col-md-6">
                <label class="form-label-sm">
                    لاحقة جداول قاعدة البيانات * <i class="bi bi-question-circle text-muted" title="مثال: ret2, fac1 — حروف/أرقام إنجليزية صغيرة فقط، بدون مسافات"></i>
                </label>
                <input type="text" id="f_table_suffix" class="form-control" dir="ltr" maxlength="10"
                       placeholder="مثال: fac1" onblur="checkSuffix()" required>
                <div id="suffixCheck" style="font-size:.75rem"></div>
            </div>
            <div class="col-md-6">
                <label class="form-label-sm">فرع المعمل المرتبط (اختياري)</label>
                <select id="f_factory_branch_id" class="form-select">
                    <option value="">— بدون ربط —</option>
                    <?php foreach ($existingBranches as $b): ?>
                        <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label-sm">بادئة رقم الفاتورة</label>
                <input type="text" id="f_invoice_prefix" class="form-control" dir="ltr" style="text-transform:uppercase" placeholder="INV">
            </div>
            <div class="col-md-6">
                <label class="form-label-sm">الهاتف</label>
                <input type="text" id="f_phone" class="form-control" dir="ltr">
            </div>
            <div class="col-md-6">
                <label class="form-label-sm">البريد الإلكتروني</label>
                <input type="email" id="f_email" class="form-control" dir="ltr">
            </div>
            <div class="col-12">
                <label class="form-label-sm">العنوان</label>
                <textarea id="f_address" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label-sm">المدينة</label>
                <input type="text" id="f_city" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label-sm">الدولة</label>
                <input type="text" id="f_country" class="form-control" value="Syria">
            </div>
            <div class="col-md-6">
                <label class="form-label-sm">الرقم الضريبي</label>
                <input type="text" id="f_tax_number" class="form-control" dir="ltr">
            </div>
            <div class="col-md-6">
                <label class="form-label-sm">رقم السجل التجاري/الصناعي</label>
                <input type="text" id="f_commercial_registration_number" class="form-control" dir="ltr">
            </div>
        </div>

        <div class="section-label"><i class="bi bi-currency-exchange"></i> العملة والتسعير</div>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label-sm">
                    العملة الوظيفية للفرع * <i class="bi bi-exclamation-triangle text-warning" title="تُقفل بعد الحفظ ولا تتغيّر لاحقاً"></i>
                </label>
                <select id="f_base_currency_id" class="form-select" required>
                    <option value="">اختر...</option>
                    <?php foreach ($currencies as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['code'] . ' — ' . $c['name'] . ' ' . $c['symbol']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label-sm">العملة المحلية (المعاملات اليومية) *</label>
                <select id="f_local_currency_id" class="form-select" required>
                    <option value="">اختر...</option>
                    <?php foreach ($currencies as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['code'] . ' — ' . $c['name'] . ' ' . $c['symbol']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label-sm">طريقة التسعير</label>
                <select id="f_pricing_method" class="form-select">
                    <?php foreach ($pricingMethods as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label-sm">طريقة التكلفة</label>
                <select id="f_costing_method" class="form-select">
                    <?php foreach ($costingMethods as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label-sm">هامش الربح الافتراضي %</label>
                <input type="number" id="f_default_margin_pct" class="form-control" min="0" max="200" step="0.5" value="20" dir="ltr">
            </div>
            <div class="col-md-6">
                <label class="form-label-sm">الضريبة الافتراضية %</label>
                <input type="number" id="f_tax_rate_default" class="form-control" min="0" max="100" step="0.5" value="0" dir="ltr">
            </div>
            <div class="col-md-6">
                <label class="form-label-sm">نسبة ضريبة المشتريات الافتراضية %</label>
                <input type="number" id="f_default_purchase_tax_pct" class="form-control" min="0" max="100" step="0.5" value="0" dir="ltr">
            </div>
            <div class="col-md-6">
                <label class="form-label-sm">معالجة الضريبة محاسبياً</label>
                <select id="f_tax_input_recoverable" class="form-select">
                    <?php foreach ($taxHandlingModes as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="section-label"><i class="bi bi-sliders"></i> إعدادات إضافية</div>
        <div class="row g-2 mb-4">
            <div class="col-md-4"><label class="form-label-sm">شهر بداية السنة المالية</label>
                <input type="number" id="f_fiscal_year_start" class="form-control" min="1" max="12" value="1" dir="ltr"></div>
            <div class="col-md-4"><label class="form-label-sm">يوم بداية الأسبوع (0=أحد)</label>
                <input type="number" id="f_week_start_day" class="form-control" min="0" max="6" value="1" dir="ltr"></div>
            <div class="col-md-4"><label class="form-label-sm">مهلة سداد العملاء الافتراضية (أيام)</label>
                <input type="number" id="f_default_payment_terms" class="form-control" min="0" value="30" dir="ltr"></div>
            <div class="col-md-12 mt-2">
                <label class="sw-wrap"><input type="checkbox" id="f_allow_negative_stock" value="1"><span class="sw-track"></span>
                    <div class="sw-label">السماح بمخزون سالب</div></label>
            </div>
            <div class="col-md-12">
                <label class="sw-wrap"><input type="checkbox" id="f_notify_low_stock" value="1" checked><span class="sw-track"></span>
                    <div class="sw-label">إشعار عند انخفاض المخزون</div></label>
            </div>
            <div class="col-md-12">
                <label class="sw-wrap"><input type="checkbox" id="f_notify_new_invoice" value="1" checked><span class="sw-track"></span>
                    <div class="sw-label">إشعار عند إنشاء فاتورة جديدة</div></label>
            </div>
            <div class="col-md-12">
                <label class="sw-wrap"><input type="checkbox" id="f_notify_internal_order" value="1" checked><span class="sw-track"></span>
                    <div class="sw-label">إشعار عند وصول طلب داخلي</div></label>
            </div>
            <div class="col-md-6">
                <label class="form-label-sm">بريد استقبال الإشعارات (اختياري)</label>
                <input type="email" id="f_notify_email" class="form-control" dir="ltr">
            </div>
        </div>

        <div class="section-label"><i class="bi bi-database-add"></i> تجهيز قاعدة البيانات</div>
        <div class="row g-2 mb-4">
            <div class="col-12">
                <label class="sw-wrap"><input type="checkbox" id="f_create_tables_now" value="1" checked><span class="sw-track"></span>
                    <div class="sw-label">إنشاء كل جداول الفرع فوراً بعد الحفظ (موصى به)</div></label>
                <div class="form-text" style="font-size:.72rem">لو ألغيتها، لازم تنشئ الجداول لاحقاً يدوياً من صفحة "إدارة الفروع"</div>
            </div>
        </div>

        <button type="button" class="btn btn-primary" onclick="createBranch()">
            <span class="spinner-border spinner-border-sm me-1" id="saveSpinner" style="display:none"></span>
            <i class="bi bi-check-lg me-1"></i>إنشاء الفرع
        </button>
    </form>

</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_PATH ?>/assets/js/sidebar.js"></script>
<script>
function selectType(type) {
    document.getElementById('f_branch_type').value = type;
    document.querySelectorAll('.type-card').forEach(c => c.classList.toggle('selected', c.dataset.type === type));
}

const g = id => document.getElementById(id)?.value ?? '';
const gck = id => document.getElementById(id)?.checked ?? false;

function post(data) {
    const fd = new FormData();
    for (const k in data) fd.append(k, data[k]);
    return fetch(location.href, { method: 'POST', body: fd }).then(r => r.json());
}
function toast(msg, type = 'success') {
    const el = document.createElement('div');
    el.className = `alert alert-${type} position-fixed`;
    el.style.cssText = 'bottom:20px;left:20px;z-index:9999;border-radius:10px;font-size:.85rem;min-width:260px';
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 4000);
}

function checkSuffix() {
    const val = g('f_table_suffix').toLowerCase();
    const out = document.getElementById('suffixCheck');
    if (!val) { out.textContent = ''; return; }
    if (!/^[a-z0-9]{2,10}$/.test(val)) {
        out.innerHTML = '<span class="text-danger">حروف/أرقام إنجليزية صغيرة فقط، ٢-١٠ خانات</span>';
        return;
    }
    post({ _action: 'check_suffix', table_suffix: val }).then(d => {
        out.innerHTML = d.available
            ? '<span class="text-success"><i class="bi bi-check-circle"></i> متاحة</span>'
            : '<span class="text-danger"><i class="bi bi-x-circle"></i> مستخدمة أصلاً</span>';
    });
}

function createBranch() {
    const branchType = g('f_branch_type');
    const name = g('f_name').trim();
    if (!branchType) { toast('اختر نوع الفرع (بيع أو تصنيع)', 'danger'); return; }
    if (!name) { toast('اسم الفرع مطلوب', 'danger'); return; }
    if (!g('f_table_suffix') || !g('f_base_currency_id') || !g('f_local_currency_id')) {
        toast('عبّي كل الحقول المطلوبة (*)', 'danger'); return;
    }

    document.getElementById('saveSpinner').style.display = 'inline-block';

    post({
        _action: 'create',
        name, name_en: g('f_name_en'), tenant_slogan: '',
        branch_type: branchType,
        code: g('f_code'),
        table_suffix: g('f_table_suffix'),
        factory_branch_id: g('f_factory_branch_id'),
        invoice_prefix: g('f_invoice_prefix'),
        phone: g('f_phone'), email: g('f_email'), address: g('f_address'), city: g('f_city'),
        country: g('f_country') || 'Syria',
        tax_number: g('f_tax_number'),
        commercial_registration_number: g('f_commercial_registration_number'),
        default_purchase_tax_pct: g('f_default_purchase_tax_pct') || '0',
        notify_email: g('f_notify_email'),
        base_currency_id: g('f_base_currency_id'),
        local_currency_id: g('f_local_currency_id'),
        pricing_method: g('f_pricing_method'),
        costing_method: g('f_costing_method'),
        default_margin_pct: g('f_default_margin_pct'),
        tax_rate_default: g('f_tax_rate_default'),
        tax_input_recoverable: g('f_tax_input_recoverable'),
        fiscal_year_start: g('f_fiscal_year_start'),
        week_start_day: g('f_week_start_day'),
        default_payment_terms: g('f_default_payment_terms'),
        allow_negative_stock: gck('f_allow_negative_stock') ? '1' : '',
        notify_low_stock: gck('f_notify_low_stock') ? '1' : '',
        notify_new_invoice: gck('f_notify_new_invoice') ? '1' : '',
        notify_internal_order: gck('f_notify_internal_order') ? '1' : '',
        create_tables_now: gck('f_create_tables_now') ? '1' : '',
    }).then(d => {
        document.getElementById('saveSpinner').style.display = 'none';
        if (d.ok) {
            toast(d.msg);
            if (d.tables) {
                toast(d.tables.ok ? `تجهيز الجداول: ${d.tables.created.length} جدول أُنشئ` : 'بعض الجداول فشلت، راجع صفحة إدارة الفروع');
            }
            setTimeout(() => { window.location.href = 'branches.php'; }, 1500);
        } else {
            toast(d.msg, 'danger');
        }
    });
}
</script>
</body>
</html>
