<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
/**
 * warehouse.php — إدارة المستودعات (فرع البيع)
 * retail1/modules/inventory/warehouse.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('inventory.warehouse', 'view');
$currentModule = 'inventory.warehouse'; // ✅ كانت غير معرّفة — الشريط الجانبي ما كان يبيّن هالصفحة كـ"نشطة" أبداً

$branchName = $_SESSION['branch_name'] ?? 'الفرع';
$TS  = $_SESSION['table_suffix'];
$TW  = "warehouses_{$TS}";
$TWI = "warehouse_items_{$TS}";
$THR = "hr_employees_{$TS}";

// ── AJAX ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        if ($act === 'save_warehouse') {
            $id        = (int)($_POST['id'] ?? 0);
            $code      = trim($_POST['code'] ?? '');
            $name      = trim($_POST['name'] ?? '');
            $address   = trim($_POST['address'] ?? '');
            $managerId = (int)($_POST['manager_id'] ?? 0) ?: null;
            // ⚠ نوع المستودع — يحدد فلترته بالصفحة (منتجات/مستهلكات/مواد
            // أولية مستقبلاً). القيمة تُقفل حسب أي تبويب كان المستخدم
            // فيه لما فتح نافذة "مستودع جديد"، مش قابلة للتعديل لاحقاً
            // (تفادي نقل مستودع فيه أرصدة فعلية بين نوعين بالغلط).
            $whType = in_array($_POST['warehouse_type'] ?? '', ['products','consumables','raw_materials'], true)
                ? $_POST['warehouse_type'] : 'products';

            if ($code === '' || $name === '') {
                throw new Exception('الكود والاسم مطلوبان');
            }

            requirePermission('inventory.warehouse', $id ? 'edit' : 'create');

            if ($id) {
                $pdo->prepare("UPDATE `{$TW}` SET code=?, name=?, address=?, manager_id=? WHERE id=?")
                    ->execute([$code, $name, $address ?: null, $managerId, $id]);
                echo json_encode(['ok' => true, 'msg' => 'تم تحديث بيانات المستودع']);
            } else {
                $pdo->prepare("INSERT INTO `{$TW}` (code, name, warehouse_type, address, manager_id, is_active, created_at)
                    VALUES (?,?,?,?,?,1,NOW())")
                    ->execute([$code, $name, $whType, $address ?: null, $managerId]);
                echo json_encode(['ok' => true, 'msg' => 'تمت إضافة المستودع', 'id' => (int)$pdo->lastInsertId()]);
            }
        }

        elseif ($act === 'toggle_warehouse') {
            requirePermission('inventory.warehouse', 'edit');
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new Exception('معرّف غير صالح');

            $curRow = $pdo->prepare("SELECT is_active, warehouse_type FROM `{$TW}` WHERE id=?");
            $curRow->execute([$id]);
            $whRow = $curRow->fetch(PDO::FETCH_ASSOC);
            if (!$whRow) throw new Exception('المستودع غير موجود');
            $isActive = (int)$whRow['is_active'];

            // ⚠ فحص الأرصدة الفعلية قبل التعطيل — مصدر الفحص يختلف حسب
            // نوع المستودع (منتجات ↔ warehouse_items، مستهلكات ↔
            // consumable_stock). كانت الصفحة تفحص المنتجات بس دايماً،
            // فمستودع مستهلكات فيه أرصدة كان ممكن يتعطّل بالغلط.
            if ($whRow['warehouse_type'] === 'consumables') {
                $st = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM `consumable_stock_{$TS}` WHERE warehouse_id=?");
            } else {
                $st = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM `{$TWI}` WHERE warehouse_id=?");
            }
            $st->execute([$id]);
            $stockQty = (float)$st->fetchColumn();

            if ($isActive && $stockQty > 0) {
                throw new Exception('لا يمكن تعطيل هذا المستودع — لسا فيه أرصدة فعلية (' . $stockQty . '). فرّغه أو انقل رصيده أولاً.');
            }

            $pdo->prepare("UPDATE `{$TW}` SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true, 'msg' => 'تم تحديث حالة المستودع']);
        }

        elseif ($act === 'get_warehouse') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM `{$TW}` WHERE id=?");
            $st->execute([$id]);
            $w = $st->fetch(PDO::FETCH_ASSOC);
            if (!$w) throw new Exception('المستودع غير موجود');
            echo json_encode(['ok' => true, 'data' => $w]);
        }

        else {
            throw new Exception('إجراء غير معروف');
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── بيانات الصفحة ────────────────────────────────────────────────
// ⚠ تبويب النوع — بنفس نمط movements.php?tab=. الافتراضي 'products'
// لأنه هاد أصل الصفحة. من قسم "المصاريف والمستهلكات" مستقبلاً بالشريط
// الجانبي، الرابط لازم يمرر ?type=consumables صراحة.
$type = $_GET['type'] ?? 'products';
if (!in_array($type, ['products', 'consumables', 'raw_materials'], true)) {
    $type = 'products';
}

$managers = [];
try {
    $managers = $pdo->query("SELECT id, full_name FROM `{$THR}` WHERE status='active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $managers = []; // جدول الموظفين قد لا يكون مُهيّأ بعد بهذا الفرع
}

// مصدر الرصيد يختلف حسب النوع — منتجات من warehouse_items، مستهلكات من
// consumable_stock. لا يوجد بعد جدول رصيد لمواد أولية (raw_material_stock
// موجود لكن بلا بيانات فعلية حتى الآن)، فبيرجع صفر مؤقتاً لهالنوع.
if ($type === 'consumables') {
    $statsSubqueryQty     = "(SELECT COALESCE(SUM(cs.quantity),0) FROM `consumable_stock_{$TS}` cs WHERE cs.warehouse_id = w.id)";
    $statsSubqueryCount   = "(SELECT COUNT(DISTINCT cs.item_id) FROM `consumable_stock_{$TS}` cs WHERE cs.warehouse_id = w.id AND cs.quantity > 0)";
} elseif ($type === 'raw_materials') {
    $statsSubqueryQty     = "(SELECT COALESCE(SUM(rms.quantity),0) FROM `raw_material_stock_{$TS}` rms WHERE rms.warehouse_id = w.id)";
    $statsSubqueryCount   = "(SELECT COUNT(DISTINCT rms.material_id) FROM `raw_material_stock_{$TS}` rms WHERE rms.warehouse_id = w.id AND rms.quantity > 0)";
} else {
    $statsSubqueryQty     = "(SELECT COALESCE(SUM(wi.quantity),0) FROM `{$TWI}` wi WHERE wi.warehouse_id = w.id)";
    $statsSubqueryCount   = "(SELECT COUNT(DISTINCT wi.variant_id) FROM `{$TWI}` wi WHERE wi.warehouse_id = w.id AND wi.quantity > 0)";
}

$stmt = $pdo->prepare("
    SELECT w.*, e.full_name AS manager_name,
        {$statsSubqueryQty} AS total_qty,
        {$statsSubqueryCount} AS variant_count
    FROM `{$TW}` w
    LEFT JOIN `{$THR}` e ON e.id = w.manager_id
    WHERE w.warehouse_type = ?
    ORDER BY w.is_active DESC, w.name
");
$stmt->execute([$type]);
$warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalWh    = count($warehouses);
$activeWh   = count(array_filter($warehouses, fn($w) => (int)$w['is_active'] === 1));
$totalStock = array_sum(array_column($warehouses, 'total_qty'));

$TYPE_LABELS = [
    'products'      => ['label' => 'مستودعات المنتجات',       'icon' => 'bi-boxes',    'unit_label' => 'قطعة'],
    'consumables'   => ['label' => 'مستودعات المواد الاستهلاكية', 'icon' => 'bi-recycle', 'unit_label' => 'وحدة'],
    'raw_materials' => ['label' => 'مستودعات المواد الأولية',  'icon' => 'bi-boxes',    'unit_label' => 'وحدة'],
];
$curTypeInfo = $TYPE_LABELS[$type];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars($curTypeInfo['label']) ?> — <?= htmlspecialchars($branchName) ?></title>
    <link rel="icon" href="<?= BASE_PATH ?>/assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/assets/css/layout.css" rel="stylesheet">
    <style>
        .stat-card { background: #fff; border-radius: 14px; border: 1px solid #e2e8f0; padding: .85rem 1.1rem; display: flex; align-items: center; gap: .85rem; transition: all .2s }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,.06) }
        .stat-icon { width: 40px; height: 40px; border-radius: 11px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0 }
        .stat-val { font-size: 1.3rem; font-weight: 700; line-height: 1.1 }
        .stat-lbl { font-size: .72rem; color: #64748b; margin-top: .15rem }
        .wh-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 1.1rem 1.2rem; transition: all .2s; height: 100% }
        .wh-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.06) }
        .wh-card.inactive { opacity: .55 }
        .wh-code { font-family: monospace; font-size: .72rem; color: #94a3b8; letter-spacing: .03em }
        .wh-name { font-weight: 700; font-size: 1rem; color: #1e293b; margin: .2rem 0 .5rem }
        .wh-meta { font-size: .78rem; color: #64748b; display: flex; align-items: center; gap: .35rem; margin-bottom: .3rem }
        .wh-badge { font-size: .68rem; padding: 3px 9px; border-radius: 100px; font-weight: 600 }
        .wh-badge.on { background: #f0fdf4; color: #16a34a }
        .wh-badge.off { background: #fef2f2; color: #dc2626 }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>

    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi <?= $curTypeInfo['icon'] ?> me-1 text-primary"></i><?= htmlspecialchars($curTypeInfo['label']) ?></span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.8rem;color:#94a3b8">
            <span><?= $type === 'consumables' ? 'المصاريف والمستهلكات' : 'المخزون' ?></span><i class="bi bi-chevron-left mx-1" style="font-size:.7rem"></i>
            <span class="text-primary">المستودعات</span>
        </nav>
    </header>

    <main class="main-content">
        <div class="content-body">

            <!-- تبويبات النوع — بنفس نمط movements.php؟tab= -->
            <ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e2e8f0">
                <li class="nav-item">
                    <a class="nav-link fw-600 <?= $type === 'products' ? 'active' : '' ?>" href="?type=products"
                        style="border:none;<?= $type === 'products' ? 'border-bottom:2px solid #1e3a8a;color:#1e3a8a;' : '' ?>font-size:.83rem;margin-bottom:-2px">
                        <i class="bi bi-boxes me-1"></i>مستودعات المنتجات
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600 <?= $type === 'consumables' ? 'active' : '' ?>" href="?type=consumables"
                        style="border:none;<?= $type === 'consumables' ? 'border-bottom:2px solid #1e3a8a;color:#1e3a8a;' : '' ?>font-size:.83rem;margin-bottom:-2px">
                        <i class="bi bi-recycle me-1"></i>مستودعات المستهلكات
                    </a>
                </li>
            </ul>

            <div class="row g-3 mb-3">
                <?php foreach ([
                    [$totalWh, 'إجمالي المستودعات', 'bi-building', '#2563eb', '#eff6ff'],
                    [$activeWh, 'مستودعات نشطة', 'bi-check-circle-fill', '#16a34a', '#f0fdf4'],
                    [number_format($totalStock, 0), 'إجمالي القطع بكل المستودعات', 'bi-boxes', '#7c3aed', '#f5f3ff'],
                ] as [$val, $lbl, $icon, $color, $bg]): ?>
                    <div class="col-6 col-md-4">
                        <div class="stat-card">
                            <div class="stat-icon" style="background:<?= $bg ?>;color:<?= $color ?>"><i class="bi <?= $icon ?>"></i></div>
                            <div>
                                <div class="stat-val"><?= $val ?></div>
                                <div class="stat-lbl"><?= $lbl ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0 fw-bold">قائمة المستودعات</h6>
                <?php if (can('inventory.warehouse', 'create')): ?>
                    <button class="btn btn-primary btn-sm" onclick="openWhModal()">
                        <i class="bi bi-plus-circle me-1"></i>مستودع جديد
                    </button>
                <?php endif; ?>
            </div>

            <?php if (empty($warehouses)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-building fs-1 d-block mb-2 opacity-50"></i>
                    لا توجد مستودعات مسجّلة بعد
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($warehouses as $w): ?>
                        <div class="col-md-4 col-lg-3">
                            <div class="wh-card <?= $w['is_active'] ? '' : 'inactive' ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <span class="wh-code"><?= htmlspecialchars($w['code']) ?></span>
                                    <span class="wh-badge <?= $w['is_active'] ? 'on' : 'off' ?>">
                                        <?= $w['is_active'] ? 'نشط' : 'معطّل' ?>
                                    </span>
                                </div>
                                <div class="wh-name"><?= htmlspecialchars($w['name']) ?></div>
                                <?php if ($w['address']): ?>
                                    <div class="wh-meta"><i class="bi bi-geo-alt"></i><?= htmlspecialchars($w['address']) ?></div>
                                <?php endif; ?>
                                <div class="wh-meta"><i class="bi bi-person-badge"></i><?= htmlspecialchars($w['manager_name'] ?? 'بدون مدير محدد') ?></div>
                                <div class="wh-meta"><i class="bi bi-boxes"></i><?= number_format((float)$w['total_qty'], 0) ?> <?= htmlspecialchars($curTypeInfo['unit_label']) ?> — <?= (int)$w['variant_count'] ?> صنف</div>
                                <div class="d-flex gap-1 mt-2">
                                    <?php if (can('inventory.warehouse', 'edit')): ?>
                                        <button class="btn btn-outline-secondary btn-sm flex-fill" onclick="openWhModal(<?= (int)$w['id'] ?>)">
                                            <i class="bi bi-pencil"></i> تعديل
                                        </button>
                                        <button class="btn btn-outline-<?= $w['is_active'] ? 'danger' : 'success' ?> btn-sm flex-fill" onclick="toggleWh(<?= (int)$w['id'] ?>)">
                                            <i class="bi bi-power"></i> <?= $w['is_active'] ? 'تعطيل' : 'تفعيل' ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>
    </main>

    <!-- مودال إضافة/تعديل -->
    <div class="modal fade" id="whModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title" id="whModalTitle">مستودع جديد</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="whId">
                    <input type="hidden" id="whType" value="<?= htmlspecialchars($type) ?>">
                    <div class="mb-2 d-flex align-items-center gap-2" style="font-size:.78rem;color:#64748b">
                        <i class="bi <?= $curTypeInfo['icon'] ?>"></i>
                        <span>النوع: <?= htmlspecialchars($curTypeInfo['label']) ?></span>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-600">الكود</label>
                        <input type="text" class="form-control" id="whCode" dir="ltr" placeholder="WH-01">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-600">الاسم</label>
                        <input type="text" class="form-control" id="whName" placeholder="المستودع الرئيسي">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-600">العنوان (اختياري)</label>
                        <textarea class="form-control" id="whAddress" rows="2"></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-600">المدير المسؤول (اختياري)</label>
                        <select class="form-select" id="whManager">
                            <option value="">بدون مدير محدد</option>
                            <?php foreach ($managers as $m): ?>
                                <option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-light btn-sm" data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-primary btn-sm" onclick="saveWh()">حفظ</button>
                </div>
            </div>
        </div>
    </div>

    <div id="toastBox" style="position:fixed;bottom:20px;left:20px;z-index:2000"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const whModal = new bootstrap.Modal(document.getElementById('whModal'));

        function post(data) {
            const fd = new FormData();
            Object.entries(data).forEach(([k, v]) => fd.append(k, v ?? ''));
            return fetch(location.href, { method: 'POST', body: fd }).then(r => r.json());
        }

        function toast(msg, type = 'success') {
            const id = 't' + Date.now();
            document.getElementById('toastBox').insertAdjacentHTML('beforeend', `
                <div id="${id}" class="alert alert-${type === 'success' ? 'success' : 'danger'} shadow-sm" style="min-width:260px">${msg}</div>`);
            setTimeout(() => document.getElementById(id)?.remove(), 3500);
        }

        function openWhModal(id) {
            document.getElementById('whId').value = '';
            document.getElementById('whCode').value = '';
            document.getElementById('whName').value = '';
            document.getElementById('whAddress').value = '';
            document.getElementById('whManager').value = '';
            document.getElementById('whModalTitle').textContent = id ? 'تعديل مستودع' : 'مستودع جديد';

            if (id) {
                post({ _action: 'get_warehouse', id }).then(d => {
                    if (!d.ok) { toast(d.msg, 'danger'); return; }
                    document.getElementById('whId').value = d.data.id;
                    document.getElementById('whCode').value = d.data.code;
                    document.getElementById('whName').value = d.data.name;
                    document.getElementById('whAddress').value = d.data.address || '';
                    document.getElementById('whManager').value = d.data.manager_id || '';
                    whModal.show();
                });
            } else {
                whModal.show();
            }
        }

        function saveWh() {
            const id = document.getElementById('whId').value;
            const code = document.getElementById('whCode').value.trim();
            const name = document.getElementById('whName').value.trim();
            if (!code || !name) { toast('الكود والاسم مطلوبان', 'danger'); return; }

            post({
                _action: 'save_warehouse', id,
                code, name,
                warehouse_type: document.getElementById('whType').value,
                address: document.getElementById('whAddress').value.trim(),
                manager_id: document.getElementById('whManager').value,
            }).then(d => {
                if (d.ok) { toast(d.msg); whModal.hide(); setTimeout(() => location.reload(), 600); }
                else toast(d.msg, 'danger');
            });
        }

        function toggleWh(id) {
            if (!confirm('تأكيد تغيير حالة هذا المستودع؟')) return;
            post({ _action: 'toggle_warehouse', id }).then(d => {
                if (d.ok) { toast(d.msg); setTimeout(() => location.reload(), 600); }
                else toast(d.msg, 'danger');
            });
        }
    </script>
</body>

</html>
