<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';
$pdo = getConnection();
checkLogin($pdo);
requirePermission('admin.branches', 'view');
$branchName    = $_SESSION['branch_name'] ?? 'الفرع';
$currentModule = 'admin.branches';
// ── AJAX ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = $_POST['_action'];
    try {
        if ($act === 'get') {
            $row = $pdo->prepare("SELECT * FROM branches WHERE id=?");
            $row->execute([(int)$_POST['id']]);
            $b = $row->fetch();
            if (!$b) throw new Exception('الفرع غير موجود');
            echo json_encode(['ok' => true, 'data' => $b]);
        } elseif ($act === 'save') {
            requirePermission('admin.branches', 'edit');
            $id = (int)$_POST['id'];
            $fields = [
                'name', 'name_en', 'branch_type', 'code',
                'phone', 'email', 'address', 'city', 'country', 'tax_number',
                'factory_branch_id',
                'base_currency', 'local_currency',
                'pricing_method', 'default_margin_pct', 'tax_rate_default',
                'allow_negative_stock',
                'notify_low_stock', 'low_stock_threshold',
                'notify_new_invoice', 'notify_internal_order', 'notify_email',
                'invoice_prefix', 'fiscal_year_start', 'week_start_day', 'default_payment_terms',
                'icon', 'color', 'sort_order', 'status',
                'dashboard_path', 'table_suffix',
            ];

            $set    = [];
            $params = [];
            foreach ($fields as $f) {
                if (!isset($_POST[$f])) continue;
                $val = $_POST[$f] === '' ? null : $_POST[$f];
                // تحويل checkboxes
                if (in_array($f, ['allow_negative_stock','notify_low_stock','notify_new_invoice','notify_internal_order'])) {
                    $val = isset($_POST[$f]) && $_POST[$f] == '1' ? 1 : 0;
                }
                $set[]    = "`$f` = ?";
                $params[] = $val;
            }
            $set[]    = "`updated_by` = ?";
            $params[] = $_SESSION['user_id'];
            $params[] = $id;
            $pdo->prepare("UPDATE branches SET " . implode(', ', $set) . ", updated_at=NOW() WHERE id=?")
                ->execute($params);

            echo json_encode(['ok' => true, 'msg' => 'تم حفظ إعدادات الفرع بنجاح']);
        } elseif ($act === 'create_tables') {
            requirePermission('admin.branches', 'edit');
            $id  = (int)$_POST['id'];
            $row = $pdo->prepare("SELECT table_suffix, branch_type FROM branches WHERE id=?");
            $row->execute([$id]);
            $br  = $row->fetch();
            if (!$br) throw new Exception('الفرع غير موجود');
            require_once __DIR__ . '/../../../../config/create_branch_tables.php';
            $result = createBranchTables($pdo, $br['table_suffix'], $br['branch_type']);
            if ($result['ok']) {
                echo json_encode([
                    'ok'  => true,
                    'msg' => 'تم إنشاء ' . count($result['created']) . ' جدول بنجاح',
                    'tables' => $result['created'],
                ]);
            } else {
                echo json_encode([
                    'ok'     => false,
                    'msg'    => 'بعض الجداول لم تُنشأ',
                    'errors' => $result['errors'],
                ]);
            }
        } elseif ($act === 'toggle_status') {
            requirePermission('admin.branches', 'edit');
            $id  = (int)$_POST['id'];
            $pdo->prepare("UPDATE branches SET status = IF(status='active','inactive','active'), updated_at=NOW() WHERE id=?")
                ->execute([$id]);
            $new = $pdo->prepare("SELECT status FROM branches WHERE id=?");
            $new->execute([$id]);
            echo json_encode(['ok' => true, 'status' => $new->fetchColumn()]);

        } else throw new Exception('إجراء غير معروف');
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}
// ── جلب البيانات ────────────────────────────────────────────────
$branches = $pdo->query("SELECT * FROM branches ORDER BY sort_order, name")->fetchAll();
// جميع الفروع لاختيار فرع المعمل
$allBranchesMap = [];
foreach ($branches as $b) $allBranchesMap[$b['id']] = $b;
$branchTypes = [
    'retail'    => ['محل بيع',   'bi-shop',        '#3b82f6', '#eff6ff'],
    'factory'   => ['معمل/مصنع', 'bi-gear-fill',   '#10b981', '#f0fdf4'],
    'warehouse' => ['مستودع',    'bi-building',     '#f59e0b', '#fffbeb'],
    'lab'       => ['مختبر',     'bi-flask',        '#8b5cf6', '#fdf4ff'],
    'office'    => ['مكتب',      'bi-briefcase',    '#64748b', '#f8fafc'],
];
$currencies = ['USD'=>'دولار $','TRY'=>'ليرة تركية ₺','SYP'=>'ليرة سورية','EUR'=>'يورو €'];
$pricingMethods = [
    'cost_plus' => 'تكلفة + هامش ربح',
    'fixed'     => 'سعر ثابت',
    'market'    => 'سعر السوق',
];
$months = ['','يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>إدارة الفروع — FATORIZE</title>
<link rel="icon" href="/bayhas/assets/images/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="/bayhas/assets/css/layout.css" rel="stylesheet">
<style>
.branch-grid {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 1.25rem;
    align-items: start;
}
@media(max-width:900px){ .branch-grid { grid-template-columns: 1fr; } }
/* بطاقات الفروع في الشريط الجانبي */
.branch-list-card {
    background:#fff; border-radius:14px;
    border:1px solid #e2e8f0; overflow:hidden;
    position:sticky; top:calc(var(--tb-h) + 1rem);
}
.branch-list-header {
    padding:.85rem 1.1rem;
    border-bottom:1px solid #f1f5f9;
    font-size:.88rem; font-weight:700; color:#1e293b;
    display:flex; align-items:center; gap:.5rem;
}
.branch-item {
    display:flex; align-items:center; gap:.75rem;
    padding:.75rem 1.1rem; cursor:pointer;
    border-bottom:1px solid #f8fafc;
    transition:background .15s; border-right:3px solid transparent;
}
.branch-item:last-child { border-bottom:none; }
.branch-item:hover { background:#f8fafc; }
.branch-item.active {
    background:#f0f7ff; border-right-color:#3b82f6;
}
.branch-item.active .bi-name { color:#1e3a8a; font-weight:700; }
.b-icon {
    width:36px; height:36px; border-radius:10px;
    display:flex; align-items:center; justify-content:center;
    font-size:1.1rem; flex-shrink:0;
}
.b-name { font-size:.86rem; font-weight:600; color:#374151; }
.b-type { font-size:.72rem; color:#94a3b8; }
.b-status-dot {
    width:7px; height:7px; border-radius:50%;
    margin-right:auto; flex-shrink:0;
}
/* نموذج التعديل */
.form-card {
    background:#fff; border-radius:14px;
    border:1px solid #e2e8f0; overflow:hidden;
}
.form-card-header {
    padding:1rem 1.5rem;
    border-bottom:1px solid #f1f5f9;
    display:flex; align-items:center; justify-content:space-between;
}
.form-card-title {
    font-size:.95rem; font-weight:700; color:#1e293b;
    display:flex; align-items:center; gap:.6rem;
}
.form-body { padding:1.5rem; }
.section-label {
    font-size:.72rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.06em;
    color:#94a3b8; margin-bottom:.85rem; margin-top:1.5rem;
    display:flex; align-items:center; gap:.4rem;
}
.section-label:first-child { margin-top:0; }
.form-label-sm {
    font-size:.8rem; font-weight:600;
    color:#374151; margin-bottom:.35rem; display:block;
}
.form-control, .form-select {
    border-radius:10px; border:1.5px solid #e2e8f0;
    font-family:'Cairo',sans-serif; font-size:.87rem;
    padding:.5rem .85rem; transition:border-color .15s;
}
.form-control:focus, .form-select:focus {
    border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.1);
}
/* Toggle switch */
.sw-wrap {
    display:flex; align-items:center; gap:.75rem;
    padding:.6rem .85rem; border-radius:10px;
    border:1.5px solid #e2e8f0; background:#f8fafc;
    cursor:pointer; transition:all .15s;
}
.sw-wrap:hover { border-color:#3b82f6; background:#f0f7ff; }
.sw-wrap input { display:none; }
.sw-track {
    width:42px; height:22px; border-radius:11px;
    background:#e2e8f0; position:relative;
    flex-shrink:0; transition:background .2s;
}
.sw-track::after {
    content:''; position:absolute;
    width:16px; height:16px; border-radius:50%;
    background:#fff; top:3px; right:3px;
    transition:transform .2s;
    box-shadow:0 1px 3px rgba(0,0,0,.2);
}
.sw-wrap input:checked ~ .sw-track { background:#22c55e; }
.sw-wrap input:checked ~ .sw-track::after { transform:translateX(-20px); }
.sw-label { font-size:.84rem; color:#374151; font-weight:500; }
/* Factory link */
.factory-link-card {
    border:2px dashed #e2e8f0; border-radius:12px;
    padding:1rem; text-align:center; cursor:pointer;
    transition:all .2s;
}
.factory-link-card:hover { border-color:#3b82f6; background:#f0f7ff; }
.factory-link-card.linked {
    border-style:solid; border-color:#10b981; background:#f0fdf4;
}
.factory-badge {
    display:inline-flex; align-items:center; gap:.4rem;
    background:#f0fdf4; color:#065f46; border:1px solid #6ee7b7;
    border-radius:8px; padding:.3rem .75rem; font-size:.82rem;
    font-weight:600;
}
/* Save bar */
.save-bar {
    padding:1rem 1.5rem; border-top:1px solid #f1f5f9;
    display:flex; align-items:center; justify-content:space-between;
    background:#f8fafc;
}
.empty-select {
    text-align:center; padding:2.5rem 1rem; color:#94a3b8;
}
.empty-select .bi { font-size:2.5rem; opacity:.2; display:block; margin-bottom:.5rem; }
</style>
</head>
<body>
<div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
<?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
<header class="topbar">
    <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
    <span class="tb-title">إدارة الفروع</span>
    <span class="tb-branch"><i class="bi bi-building-check me-1"></i><?= htmlspecialchars($branchName) ?></span>
    <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.8rem;color:#94a3b8">
        <span>الإدارة</span>
        <i class="bi bi-chevron-left mx-1" style="font-size:.7rem"></i>
        <span class="text-primary">الفروع</span>
    </nav>
</header>
<main class="main-content">
<div class="content-body">
<div class="branch-grid">
    <!-- ═══ قائمة الفروع ═══ -->
    <div class="branch-list-card">
        <div class="branch-list-header">
            <i class="bi bi-buildings text-primary"></i>
            الفروع (<?= count($branches) ?>)
        </div>
        <?php foreach ($branches as $b):
            [$typeLbl,$typeIcon,$typeColor,$typeBg] = $branchTypes[$b['branch_type'] ?? 'retail'];
            // استخدام لون الفرع الفعلي من قاعدة البيانات إذا موجود
            $bColor  = !empty($b['color'])  ? $b['color']  : $typeColor;
            $bIcon   = !empty($b['icon'])   ? $b['icon']   : $typeIcon;
            // توليد لون الخلفية من لون الفرع بشفافية 15%
            $bBg     = $bColor . '22';
        ?>
        <div class="branch-item" id="li-<?= $b['id'] ?>"
             onclick="selectBranch(<?= $b['id'] ?>)">
            <div class="b-icon" style="background:<?= $bBg ?>;color:<?= $bColor ?>">
                <i class="bi <?= htmlspecialchars($bIcon) ?>"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="b-name text-truncate"><?= htmlspecialchars($b['name']) ?></div>
                <div class="b-type"><?= $typeLbl ?> · <?= htmlspecialchars($b['code'] ?? '') ?></div>
            </div>
            <div class="b-status-dot"
                 style="background:<?= $b['status']==='active' ? '#22c55e' : '#e2e8f0' ?>">
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <!-- ═══ نموذج التعديل ═══ -->
    <div class="form-card" id="formCard">
        <div class="empty-select" id="emptyState">
            <i class="bi bi-building-gear"></i>
            اختر فرعاً من القائمة لتعديل إعداداته
        </div>
        <div id="formContent" style="display:none">

            <div class="form-card-header">
                <div class="form-card-title">
                    <span id="hdrIcon" class="b-icon" style="width:34px;height:34px;font-size:.95rem"></span>
                    <span id="hdrName">—</span>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <span id="statusBadge" class="badge rounded-pill" style="font-size:.75rem"></span>
                    <?php if (can('admin.branches','edit')): ?>
                    <button class="btn btn-sm btn-outline-success" style="border-radius:8px;font-size:.78rem"
                            onclick="createTables()" id="createTablesBtn" title="إنشاء/تحديث جداول قاعدة البيانات لهذا الفرع">
                        <i class="bi bi-database-add me-1"></i>إنشاء الجداول
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" style="border-radius:8px;font-size:.78rem"
                            onclick="toggleStatus()" id="statusToggleBtn">تغيير الحالة</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="form-body">
                <!-- §1 المعلومات الأساسية -->
                <div class="section-label">
                    <i class="bi bi-info-circle"></i> المعلومات الأساسية
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label-sm">اسم الفرع <span class="text-danger">*</span></label>
                        <input type="text" id="f_name" class="form-control" placeholder="فرع حلب">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-sm">الاسم بالإنجليزية</label>
                        <input type="text" id="f_name_en" class="form-control" placeholder="Aleppo Branch" dir="ltr">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-sm">الكود</label>
                        <input type="text" id="f_code" class="form-control" placeholder="ALP" dir="ltr"
                               style="text-transform:uppercase">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-sm">نوع الفرع</label>
                        <select id="f_branch_type" class="form-select">
                            <?php foreach ($branchTypes as $k=>[$l,$i,$c,$b]): ?>
                            <option value="<?= $k ?>"><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-sm">الرقم الضريبي</label>
                        <input type="text" id="f_tax_number" class="form-control" placeholder="اختياري">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-sm">الهاتف</label>
                        <input type="text" id="f_phone" class="form-control" dir="ltr">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-sm">البريد الإلكتروني</label>
                        <input type="email" id="f_email" class="form-control" dir="ltr">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label-sm">العنوان</label>
                        <input type="text" id="f_address" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-sm">المدينة</label>
                        <input type="text" id="f_city" class="form-control">
                    </div>
                </div>
                <!-- §2 ربط فرع المعمل -->
                <div class="section-label mt-4">
                    <i class="bi bi-link-45deg"></i> ربط فرع المعمل / المصدر
                </div>
                <div class="factory-link-card" id="factoryCard" onclick="openFactoryPicker()">
                    <div id="factoryEmpty">
                        <i class="bi bi-plus-circle text-muted fs-4 d-block mb-1"></i>
                        <div class="text-muted small">اضغط لربط فرع المعمل أو المصدر لهذا الفرع</div>
                        <div class="text-muted" style="font-size:.72rem;margin-top:.3rem">
                            سيُستخدم لإنشاء الطلبيات والفواتير الداخلية بين الفروع
                        </div>
                    </div>
                    <div id="factoryLinked" style="display:none">
                        <div class="factory-badge" id="factoryBadgeInner">
                            <i class="bi bi-gear-fill"></i>
                            <span id="factoryName"></span>
                        </div>
                        <div class="text-muted mt-2" style="font-size:.72rem">
                            اضغط لتغيير الفرع المرتبط
                        </div>
                    </div>
                </div>
                <input type="hidden" id="f_factory_branch_id">
                <select id="factoryPickerSelect" class="form-select mt-2"
                        style="display:none" onchange="setFactory(this.value)"
                        size="<?= count($branches) + 1 ?>">
                    <option value="">— بدون ربط —</option>
                    <?php foreach ($branches as $b): ?>
                    <option value="<?= $b['id'] ?>">
                        <?= htmlspecialchars($b['name']) ?>
                        (<?= $branchTypes[$b['branch_type'] ?? 'retail'][0] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>

                <!-- §3 العملة والتسعير -->
                <div class="section-label mt-4">
                    <i class="bi bi-currency-exchange"></i> العملة والتسعير
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label-sm">العملة الأساسية (التقارير)</label>
                        <select id="f_base_currency" class="form-select">
                            <?php foreach ($currencies as $k=>$v): ?>
                            <option value="<?= $k ?>"><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-sm">العملة المحلية (المعاملات)</label>
                        <select id="f_local_currency" class="form-select">
                            <?php foreach ($currencies as $k=>$v): ?>
                            <option value="<?= $k ?>"><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-sm">طريقة التسعير</label>
                        <select id="f_pricing_method" class="form-select">
                            <?php foreach ($pricingMethods as $k=>$v): ?>
                            <option value="<?= $k ?>"><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-sm">هامش الربح الافتراضي %</label>
                        <input type="number" id="f_default_margin_pct" class="form-control"
                               min="0" max="200" step="0.5" dir="ltr">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-sm">الضريبة الافتراضية %</label>
                        <input type="number" id="f_tax_rate_default" class="form-control"
                               min="0" max="100" step="0.5" dir="ltr">
                    </div>
                </div>

                <!-- §4 الفوترة والمحاسبة -->
                <div class="section-label mt-4">
                    <i class="bi bi-receipt"></i> الفوترة والمحاسبة
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label-sm">بادئة رقم الفاتورة</label>
                        <input type="text" id="f_invoice_prefix" class="form-control"
                               placeholder="ALP" dir="ltr" style="text-transform:uppercase">
                        <div class="form-text text-muted" style="font-size:.72rem">
                            مثال: ALP-2025-00001
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-sm">بداية السنة المالية</label>
                        <select id="f_fiscal_year_start" class="form-select">
                            <?php for ($m=1; $m<=12; $m++): ?>
                            <option value="<?= $m ?>"><?= $months[$m] ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-sm">
                            <i class="bi bi-calendar-week me-1 text-primary"></i>
                            يوم بداية الأسبوع (للرواتب)
                        </label>
                        <select id="f_week_start_day" class="form-select">
                            <option value="0">الأحد</option>
                            <option value="1">الإثنين</option>
                            <option value="2">الثلاثاء</option>
                            <option value="3">الأربعاء</option>
                            <option value="4">الخميس</option>
                            <option value="5">الجمعة</option>
                            <option value="6">السبت</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-sm">أيام الدفع الافتراضية</label>
                        <div class="input-group">
                            <input type="number" id="f_default_payment_terms" class="form-control"
                                   min="0" max="365" dir="ltr">
                            <span class="input-group-text" style="font-size:.8rem">يوم</span>
                        </div>
                    </div>
                </div>

                <!-- §5 المخزون -->
                <div class="section-label mt-4">
                    <i class="bi bi-box-seam"></i> إعدادات المخزون
                </div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="sw-wrap">
                            <input type="checkbox" id="f_allow_negative_stock" value="1">
                            <span class="sw-track"></span>
                            <div>
                                <div class="sw-label">السماح بالمخزون السالب</div>
                                <div style="font-size:.72rem;color:#94a3b8">البيع حتى لو المخزون صفر</div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- §6 الإشعارات -->
                <div class="section-label mt-4">
                    <i class="bi bi-bell"></i> الإشعارات
                </div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="sw-wrap">
                            <input type="checkbox" id="f_notify_low_stock" value="1">
                            <span class="sw-track"></span>
                            <div>
                                <div class="sw-label">إشعار عند انخفاض المخزون</div>
                            </div>
                        </label>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-sm">حد المخزون المنخفض (كمية)</label>
                        <input type="number" id="f_low_stock_threshold" class="form-control"
                               min="0" dir="ltr">
                    </div>
                    <div class="col-md-6">
                        <label class="sw-wrap">
                            <input type="checkbox" id="f_notify_new_invoice" value="1">
                            <span class="sw-track"></span>
                            <div>
                                <div class="sw-label">إشعار عند إنشاء فاتورة جديدة</div>
                            </div>
                        </label>
                    </div>
                    <div class="col-md-6">
                        <label class="sw-wrap">
                            <input type="checkbox" id="f_notify_internal_order" value="1">
                            <span class="sw-track"></span>
                            <div>
                                <div class="sw-label">إشعار عند وصول طلبية داخلية</div>
                                <div style="font-size:.72rem;color:#94a3b8">من فروع أخرى</div>
                            </div>
                        </label>
                    </div>
                    <div class="col-12">
                        <label class="form-label-sm">بريد استقبال الإشعارات</label>
                        <input type="email" id="f_notify_email" class="form-control"
                               placeholder="notify@branch.com" dir="ltr">
                    </div>
                </div>

                <!-- §7 مظهر الـ sidebar -->
                <div class="section-label mt-4">
                    <i class="bi bi-palette"></i> المظهر في قائمة اختيار الفرع
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label-sm">أيقونة Bootstrap Icons</label>
                        <div class="input-group">
                            <span class="input-group-text" id="iconPreview">
                                <i class="bi bi-building" id="iconPreviewI"></i>
                            </span>
                            <input type="text" id="f_icon" class="form-control"
                                   placeholder="bi-building" dir="ltr"
                                   oninput="updateIconPreview(this.value)">
                        </div>
                        <div class="form-text text-muted" style="font-size:.72rem">
                            <a href="https://icons.getbootstrap.com" target="_blank">تصفح الأيقونات ↗</a>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label-sm">اللون</label>
                        <input type="color" id="f_color" class="form-control form-control-color w-100"
                               style="height:42px;border-radius:10px">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label-sm">ترتيب العرض</label>
                        <input type="number" id="f_sort_order" class="form-control" min="0" dir="ltr">
                    </div>
                </div>

            </div><!-- /form-body -->

            <div class="save-bar">
                <div class="text-muted small">
                    <i class="bi bi-info-circle me-1"></i>
                    آخر تعديل: <span id="lastUpdated">—</span>
                </div>
                <?php if (can('admin.branches','edit')): ?>
                <button class="btn btn-primary btn-sm d-flex align-items-center gap-1"
                        style="border-radius:10px;min-width:110px" onclick="saveBranch()">
                    <i class="bi bi-floppy"></i> حفظ التغييرات
                    <span id="saveSpinner" class="spinner-border spinner-border-sm ms-1" style="display:none"></span>
                </button>
                <?php endif; ?>
            </div>

        </div><!-- /formContent -->
    </div><!-- /form-card -->

</div><!-- /branch-grid -->
</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Sidebar ──────────────────────────────────────────────────────
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sbOverlay');
function sbOpen()  { sidebar.classList.add('open');  overlay.classList.add('show'); }
function sbClose() { sidebar.classList.remove('open'); overlay.classList.remove('show'); }
window.addEventListener('resize', () => { if(window.innerWidth>991) sbClose(); });
document.querySelectorAll('.sb-group').forEach(g => {
    if (localStorage.getItem('sb_open_' + g.dataset.key) === 'true') g.classList.add('open');
});
function toggleGroup(g) {
    const o = g.classList.contains('open');
    document.querySelectorAll('.sb-group.open').forEach(x => x.classList.remove('open'));
    g.classList.toggle('open', !o);
    localStorage.setItem('sb_open_' + g.dataset.key, (!o).toString());
}

// ── بيانات الفروع ────────────────────────────────────────────────
const branchesData = <?= json_encode($allBranchesMap, JSON_UNESCAPED_UNICODE) ?>;
const branchTypes  = <?= json_encode(array_map(fn($v)=>[$v[0],$v[1],$v[2],$v[3]], $branchTypes), JSON_UNESCAPED_UNICODE) ?>;

let currentBranchId = null;

function selectBranch(id) {
    document.querySelectorAll('.branch-item').forEach(el => el.classList.remove('active'));
    document.getElementById('li-' + id)?.classList.add('active');

    document.getElementById('emptyState').style.display  = 'none';
    document.getElementById('formContent').style.display = '';
    currentBranchId = id;

    post({_action:'get', id}, d => fillForm(d));
}

function fillForm(b) {
    // رأس النموذج
    const [typeLbl, typeIcon, typeColor, typeBg] = branchTypes[b.branch_type] || branchTypes.retail;
    // استخدام لون الفرع الفعلي — الـ fallback فقط إذا ما في لون محدد
    const bColor = b.color  || typeColor;
    const bIcon  = b.icon   || typeIcon;
    const bBg    = bColor + '22'; // شفافية 15%

    const hdrIcon = document.getElementById('hdrIcon');
    hdrIcon.innerHTML = `<i class="bi ${bIcon}"></i>`;
    hdrIcon.style.background = bBg;
    hdrIcon.style.color = bColor;
    document.getElementById('hdrName').textContent = b.name;

    const isActive = b.status === 'active';
    const badge = document.getElementById('statusBadge');
    badge.textContent = isActive ? 'نشط' : 'معطل';
    badge.className   = `badge rounded-pill bg-${isActive?'success':'secondary'}-subtle text-${isActive?'success':'secondary'}`;

    document.getElementById('lastUpdated').textContent = b.updated_at
        ? new Date(b.updated_at).toLocaleString('ar-SY') : '—';

    // الحقول
    setVal('f_name',                   b.name);
    setVal('f_name_en',                b.name_en);
    setVal('f_code',                   b.code);
    setVal('f_branch_type',            b.branch_type || 'retail');
    setVal('f_tax_number',             b.tax_number);
    setVal('f_phone',                  b.phone);
    setVal('f_email',                  b.email);
    setVal('f_address',                b.address);
    setVal('f_city',                   b.city);
    setVal('f_base_currency',          b.base_currency || 'USD');
    setVal('f_local_currency',         b.local_currency || 'SYP');
    setVal('f_pricing_method',         b.pricing_method || 'cost_plus');
    setVal('f_default_margin_pct',     b.default_margin_pct || 20);
    setVal('f_tax_rate_default',       b.tax_rate_default || 0);
    setVal('f_invoice_prefix',         b.invoice_prefix || '');
    setVal('f_fiscal_year_start',      b.fiscal_year_start || 1);
    setVal('f_week_start_day',         b.week_start_day ?? 1);
    setVal('f_default_payment_terms',  b.default_payment_terms || 30);
    setVal('f_low_stock_threshold',    b.low_stock_threshold || 5);
    setVal('f_notify_email',           b.notify_email);
    setVal('f_icon',                   b.icon || 'bi-building');
    setVal('f_color',                  b.color || '#3b82f6');
    setVal('f_sort_order',             b.sort_order || 0);

    setCheck('f_allow_negative_stock',   b.allow_negative_stock == 1);
    setCheck('f_notify_low_stock',       b.notify_low_stock == 1);
    setCheck('f_notify_new_invoice',     b.notify_new_invoice == 1);
    setCheck('f_notify_internal_order',  b.notify_internal_order == 1);

    updateIconPreview(b.icon || 'bi-building');
    setFactory(b.factory_branch_id);
}

function setVal(id, val) {
    const el = document.getElementById(id);
    if (el) el.value = val ?? '';
}
function setCheck(id, checked) {
    const el = document.getElementById(id);
    if (el) el.checked = !!checked;
}

// ── ربط المعمل ───────────────────────────────────────────────────
let factoryPickerOpen = false;
function openFactoryPicker() {
    const sel = document.getElementById('factoryPickerSelect');
    factoryPickerOpen = !factoryPickerOpen;
    sel.style.display = factoryPickerOpen ? 'block' : 'none';
    if (factoryPickerOpen) sel.value = document.getElementById('f_factory_branch_id').value || '';
}

function setFactory(val) {
    document.getElementById('f_factory_branch_id').value = val || '';
    document.getElementById('factoryPickerSelect').value  = val || '';
    document.getElementById('factoryPickerSelect').style.display = 'none';
    factoryPickerOpen = false;

    const card    = document.getElementById('factoryCard');
    const empty   = document.getElementById('factoryEmpty');
    const linked  = document.getElementById('factoryLinked');
    const nameEl  = document.getElementById('factoryName');

    if (val && branchesData[val]) {
        card.classList.add('linked');
        empty.style.display  = 'none';
        linked.style.display = '';
        nameEl.textContent   = branchesData[val].name;
    } else {
        card.classList.remove('linked');
        empty.style.display  = '';
        linked.style.display = 'none';
    }
}

// ── حفظ ─────────────────────────────────────────────────────────
function saveBranch() {
    if (!currentBranchId) return;
    const name = document.getElementById('f_name').value.trim();
    if (!name) { alert('اسم الفرع مطلوب'); return; }

    const spinner = document.getElementById('saveSpinner');
    spinner.style.display = 'inline-block';

    const checkIds = ['allow_negative_stock','notify_low_stock','notify_new_invoice','notify_internal_order'];

    const data = {
        _action: 'save', id: currentBranchId,
        name, name_en: g('f_name_en'), branch_type: g('f_branch_type'),
        code: g('f_code').toUpperCase(), tax_number: g('f_tax_number'),
        phone: g('f_phone'), email: g('f_email'),
        address: g('f_address'), city: g('f_city'),
        factory_branch_id: g('f_factory_branch_id') || '',
        base_currency: g('f_base_currency'), local_currency: g('f_local_currency'),
        pricing_method: g('f_pricing_method'),
        default_margin_pct: g('f_default_margin_pct'),
        tax_rate_default: g('f_tax_rate_default'),
        allow_negative_stock: gck('f_allow_negative_stock') ? '1' : '0',
        notify_low_stock: gck('f_notify_low_stock') ? '1' : '0',
        low_stock_threshold: g('f_low_stock_threshold'),
        notify_new_invoice: gck('f_notify_new_invoice') ? '1' : '0',
        notify_internal_order: gck('f_notify_internal_order') ? '1' : '0',
        notify_email: g('f_notify_email'),
        invoice_prefix: g('f_invoice_prefix').toUpperCase(),
        fiscal_year_start: g('f_fiscal_year_start'),
        week_start_day: g('f_week_start_day'),
        default_payment_terms: g('f_default_payment_terms'),
        icon: g('f_icon'), color: g('f_color'),
        sort_order: g('f_sort_order'),
    };

    post(data, () => {
        spinner.style.display = 'none';
        // تحديث dot الحالة في القائمة
        branchesData[currentBranchId] = {...branchesData[currentBranchId], ...data};
        showToast('تم حفظ إعدادات الفرع بنجاح', 'success');
        document.getElementById('lastUpdated').textContent = new Date().toLocaleString('ar-SY');
    }, () => spinner.style.display = 'none');
}

function createTables() {
    if (!currentBranchId) return;
    if (!confirm('سيتم إنشاء جداول قاعدة البيانات لهذا الفرع.\nإذا كانت الجداول موجودة مسبقاً لن يتم حذف أي بيانات.\n\nهل تريد المتابعة؟')) return;

    const btn = document.getElementById('createTablesBtn');
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري الإنشاء...';
    btn.disabled  = true;

    post({_action:'create_tables', id: currentBranchId}, d => {
        btn.innerHTML = '<i class="bi bi-database-add me-1"></i>إنشاء الجداول';
        btn.disabled  = false;
        const count   = d.tables ? d.tables.length : 0;
        showToast(`✅ تم إنشاء ${count} جدول بنجاح`, 'success');
    }, () => {
        btn.innerHTML = '<i class="bi bi-database-add me-1"></i>إنشاء الجداول';
        btn.disabled  = false;
    });
}
function toggleStatus() {
    if (!currentBranchId) return;
    if (!confirm('هل تريد تغيير حالة هذا الفرع؟')) return;
    post({_action:'toggle_status', id: currentBranchId}, d => {
        const isActive = d.status === 'active';
        const dot   = document.querySelector(`#li-${currentBranchId} .b-status-dot`);
        const badge = document.getElementById('statusBadge');
        if (dot)  dot.style.background = isActive ? '#22c55e' : '#e2e8f0';
        badge.textContent = isActive ? 'نشط' : 'معطل';
        badge.className   = `badge rounded-pill bg-${isActive?'success':'secondary'}-subtle text-${isActive?'success':'secondary'}`;
        showToast(`تم ${isActive?'تفعيل':'تعطيل'} الفرع`, isActive?'success':'warning');
    });
}

// ── مساعدات ──────────────────────────────────────────────────────
const g   = id => document.getElementById(id)?.value ?? '';
const gck = id => document.getElementById(id)?.checked ?? false;

function updateIconPreview(cls) {
    const el = document.getElementById('iconPreviewI');
    if (el) el.className = 'bi ' + cls;
}

function post(data, onSuccess, onFinally) {
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k, v ?? ''));
    fetch(location.href, {method:'POST', body:fd})
        .then(r => r.json())
        .then(d => {
            if (onFinally) onFinally();
            if (d.ok) { if (onSuccess) onSuccess(d.data || d); }
            else { showToast(d.msg || 'حدث خطأ', 'danger'); }
        })
        .catch(() => { if (onFinally) onFinally(); showToast('خطأ في الاتصال', 'danger'); });
}

function showToast(msg, type) {
    const t = document.createElement('div');
    t.className = `alert alert-${type} shadow-sm`;
    t.style.cssText = 'position:fixed;top:76px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:260px;text-align:center;font-size:.85rem;padding:.6rem 1.25rem';
    t.innerHTML = `<i class="bi bi-${type==='success'?'check-circle-fill text-success':'exclamation-triangle-fill text-danger'} me-2"></i>${msg}`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3000);
}

// فتح أول فرع تلقائياً
const firstBranchId = <?= $branches[0]['id'] ?? 'null' ?>;
if (firstBranchId) selectBranch(firstBranchId);
</script>
</body>
</html>