<?php
/**
 * production/raw_materials.php — كتالوج المواد الأولية + رصيدها بالمستودعات
 * المسار: aleppo/modules/production/raw_materials.php
 *
 * النطاق: كتالوج المواد الأولية (إضافة/تعديل/تعطيل) + عرض الرصيد الحالي
 * لكل مادة بكل مستودع + تسوية يدوية للرصيد (بديل مؤقت لحين بناء صفحة
 * "مشتريات مواد أولية" التي ستغذي الرصيد تلقائياً لاحقاً — كل حركة هون
 * موثّقة بجدول raw_material_movements حتى التسوية اليدوية).
 */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('production.raw_materials', 'view');

$TS = $_SESSION['table_suffix'];
$currentModule = 'production.raw_materials';
$userId = (int)$_SESSION['user_id'];

$T_MAT   = "raw_materials_{$TS}";
$T_CAT   = "raw_material_categories_{$TS}";
$T_UNIT  = "raw_material_units_{$TS}";
$T_STOCK = "raw_material_stock_{$TS}";
$T_MOVE  = "raw_material_movements_{$TS}";
$T_WH    = "warehouses_{$TS}";

function genMaterialCode(PDO $pdo, string $table): string {
    $last = $pdo->query("SELECT code FROM {$table} ORDER BY id DESC LIMIT 1")->fetchColumn();
    $next = 1;
    if ($last && preg_match('/(\d+)$/', $last, $m)) {
        $next = (int)$m[1] + 1;
    }
    return 'RM-' . str_pad((string)$next, 6, '0', STR_PAD_LEFT);
}

// ===================== AJAX =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $action = $_POST['_action'];

        switch ($action) {

            case 'add_category': {
                requirePermission('production.raw_materials', 'create');
                $name = trim($_POST['name'] ?? '');
                if ($name === '') throw new Exception('اسم الفئة مطلوب');
                $pdo->prepare("INSERT INTO {$T_CAT} (name) VALUES (?)")->execute([$name]);
                echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'name' => $name]);
                exit;
            }

            case 'add_unit': {
                requirePermission('production.raw_materials', 'create');
                $name = trim($_POST['name'] ?? '');
                $symbol = trim($_POST['symbol'] ?? '');
                if ($name === '') throw new Exception('اسم الوحدة مطلوب');
                $pdo->prepare("INSERT INTO {$T_UNIT} (name, symbol) VALUES (?, ?)")->execute([$name, $symbol !== '' ? $symbol : null]);
                echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'name' => $name, 'symbol' => $symbol]);
                exit;
            }

            case 'get': {
                $id = (int)($_POST['id'] ?? 0);
                $st = $pdo->prepare("SELECT * FROM {$T_MAT} WHERE id = ?");
                $st->execute([$id]);
                $row = $st->fetch();
                if (!$row) throw new Exception('المادة غير موجودة');
                echo json_encode(['ok' => true, 'data' => $row]);
                exit;
            }

            case 'save': {
                $id           = (int)($_POST['id'] ?? 0);
                $name         = trim($_POST['name'] ?? '');
                $categoryId   = ($_POST['category_id'] ?? '') !== '' ? (int)$_POST['category_id'] : null;
                $unitId       = (int)($_POST['unit_id'] ?? 0);
                $minThreshold = (float)($_POST['min_stock_threshold'] ?? 0);
                $lastCost     = (float)($_POST['last_cost'] ?? 0);
                $notes        = trim($_POST['notes'] ?? '');

                if ($name === '') throw new Exception('اسم المادة مطلوب');
                if ($unitId <= 0) throw new Exception('وحدة القياس مطلوبة');
                if ($minThreshold < 0 || $lastCost < 0) throw new Exception('القيم الرقمية ما بتنكون سالبة');

                if ($id > 0) {
                    requirePermission('production.raw_materials', 'edit');
                    $pdo->prepare("
                        UPDATE {$T_MAT}
                        SET name=?, category_id=?, unit_id=?, min_stock_threshold=?, last_cost=?, notes=?, updated_by=?
                        WHERE id=?
                    ")->execute([$name, $categoryId, $unitId, $minThreshold, $lastCost, $notes !== '' ? $notes : null, $userId, $id]);
                    echo json_encode(['ok' => true, 'id' => $id, 'msg' => 'تم تحديث المادة بنجاح']);
                } else {
                    requirePermission('production.raw_materials', 'create');
                    $code = genMaterialCode($pdo, $T_MAT);
                    $pdo->prepare("
                        INSERT INTO {$T_MAT} (code, name, category_id, unit_id, min_stock_threshold, last_cost, notes, created_by, updated_by)
                        VALUES (?,?,?,?,?,?,?,?,?)
                    ")->execute([$code, $name, $categoryId, $unitId, $minThreshold, $lastCost, $notes !== '' ? $notes : null, $userId, $userId]);
                    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'code' => $code, 'msg' => 'تمت إضافة المادة بنجاح']);
                }
                exit;
            }

            case 'toggle_status': {
                requirePermission('production.raw_materials', 'edit');
                $id = (int)($_POST['id'] ?? 0);
                $st = $pdo->prepare("SELECT is_active FROM {$T_MAT} WHERE id = ?");
                $st->execute([$id]);
                $cur = $st->fetchColumn();
                if ($cur === false) throw new Exception('المادة غير موجودة');
                $new = $cur ? 0 : 1;
                $pdo->prepare("UPDATE {$T_MAT} SET is_active=?, updated_by=? WHERE id=?")->execute([$new, $userId, $id]);
                echo json_encode(['ok' => true, 'is_active' => $new]);
                exit;
            }

            case 'get_stock_breakdown': {
                $materialId = (int)($_POST['material_id'] ?? 0);
                $st = $pdo->prepare("
                    SELECT w.id AS warehouse_id, w.name AS warehouse_name,
                           COALESCE(s.quantity, 0) AS quantity
                    FROM {$T_WH} w
                    LEFT JOIN {$T_STOCK} s ON s.warehouse_id = w.id AND s.raw_material_id = ?
                    ORDER BY w.name
                ");
                $st->execute([$materialId]);
                echo json_encode(['ok' => true, 'data' => $st->fetchAll()]);
                exit;
            }

            case 'get_movements': {
                $materialId = (int)($_POST['material_id'] ?? 0);
                $st = $pdo->prepare("
                    SELECT mv.*, w.name AS warehouse_name, u.full_name AS user_name, u.username
                    FROM {$T_MOVE} mv
                    JOIN {$T_WH} w ON w.id = mv.warehouse_id
                    LEFT JOIN users u ON u.id = mv.created_by
                    WHERE mv.raw_material_id = ?
                    ORDER BY mv.created_at DESC
                    LIMIT 100
                ");
                $st->execute([$materialId]);
                echo json_encode(['ok' => true, 'data' => $st->fetchAll()]);
                exit;
            }

            case 'adjust_stock': {
                requirePermission('production.raw_materials', 'edit');
                $materialId  = (int)($_POST['material_id'] ?? 0);
                $warehouseId = (int)($_POST['warehouse_id'] ?? 0);
                $newQty      = ($_POST['new_quantity'] ?? '') !== '' ? (float)$_POST['new_quantity'] : null;
                $notes       = trim($_POST['notes'] ?? '');

                if ($materialId <= 0 || $warehouseId <= 0) throw new Exception('اختر المادة والمستودع');
                if ($newQty === null || $newQty < 0) throw new Exception('الكمية الجديدة غير صحيحة');
                if ($notes === '') throw new Exception('لازم تكتب سبب التسوية');

                $pdo->beginTransaction();
                try {
                    $st = $pdo->prepare("SELECT quantity FROM {$T_STOCK} WHERE raw_material_id=? AND warehouse_id=? FOR UPDATE");
                    $st->execute([$materialId, $warehouseId]);
                    $beforeRaw = $st->fetchColumn();
                    $before = ($beforeRaw === false) ? 0.0 : (float)$beforeRaw;
                    $change = $newQty - $before;

                    if (abs($change) < 0.0001) {
                        $pdo->rollBack();
                        echo json_encode(['ok' => true, 'msg' => 'الكمية نفسها، ما في تغيير', 'quantity' => $newQty]);
                        exit;
                    }

                    $pdo->prepare("
                        INSERT INTO {$T_STOCK} (raw_material_id, warehouse_id, quantity)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
                    ")->execute([$materialId, $warehouseId, $newQty]);

                    $type = $change >= 0 ? 'adjustment_in' : 'adjustment_out';
                    $pdo->prepare("
                        INSERT INTO {$T_MOVE}
                            (raw_material_id, warehouse_id, movement_type, quantity_before, quantity_change, quantity_after, reference_type, notes, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, 'manual_adjustment', ?, ?)
                    ")->execute([$materialId, $warehouseId, $type, $before, $change, $newQty, $notes, $userId]);

                    $pdo->commit();
                    echo json_encode(['ok' => true, 'msg' => 'تم تحديث الرصيد', 'quantity' => $newQty]);
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                exit;
            }

            default:
                throw new Exception('إجراء غير معروف');
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ===================== بيانات صفحة العرض =====================

$categories = $pdo->query("SELECT id, name FROM {$T_CAT} WHERE is_active = 1 ORDER BY name")->fetchAll();
$units      = $pdo->query("SELECT id, name, symbol FROM {$T_UNIT} WHERE is_active = 1 ORDER BY name")->fetchAll();
$warehouses = $pdo->query("SELECT id, name FROM {$T_WH} ORDER BY name")->fetchAll();

$materials = $pdo->query("
    SELECT m.*, c.name AS category_name, u.name AS unit_name, u.symbol AS unit_symbol,
           COALESCE((SELECT SUM(s.quantity) FROM {$T_STOCK} s WHERE s.raw_material_id = m.id), 0) AS total_stock
    FROM {$T_MAT} m
    LEFT JOIN {$T_CAT} c ON c.id = m.category_id
    LEFT JOIN {$T_UNIT} u ON u.id = m.unit_id
    ORDER BY m.created_at DESC
")->fetchAll();

$statTotalActive = 0;
$statLowStock    = 0;
$statStockValue  = 0.0;
foreach ($materials as $m) {
    if ((int)$m['is_active'] === 1) $statTotalActive++;
    if ((int)$m['is_active'] === 1 && (float)$m['total_stock'] <= (float)$m['min_stock_threshold']) $statLowStock++;
    $statStockValue += (float)$m['total_stock'] * (float)$m['last_cost'];
}
$statCatCount = count($categories);

// اسم الفرع + عملته الوظيفية (branches.base_currency هو الحقل الموثوق، مو base_currency_id)
$branchName = '';
$baseCurSym = '';
try {
    $bn = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
    $bn->execute([$_SESSION['branch_id'] ?? 0]);
    $brow = $bn->fetch();
    if ($brow) {
        $branchName = $brow['name'] ?? ($brow['branch_name'] ?? '');
        $baseCurSym = $brow['base_currency'] ?? '';
    }
} catch (Throwable $e) { /* يُعرض بدون اسم/عملة بدل ما تنكسر الصفحة */ }

$can_create = can('production.raw_materials', 'create');
$can_edit   = can('production.raw_materials', 'edit');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>المواد الأولية — FATORIZE</title>
    <link rel="icon" type="image/png" href="<?= BASE_PATH ?>/assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/assets/css/layout.css" rel="stylesheet">
    <style>
        table.mtbl { width: 100% !important; }
        .badge-status { padding: .25rem .6rem; border-radius: 20px; font-size: .75rem; font-weight: 600; }
        .badge-active { background: #ecfdf5; color: #16a34a; }
        .badge-inactive { background: #f1f5f9; color: #64748b; }
        .badge-low { background: #fffbeb; color: #ca8a04; }
        .qty-cell { font-weight: 600; }
        .code-cell { direction: ltr; text-align: right; font-weight: 600; font-size: .82rem; color: var(--section-color); }
        .quick-add-btn { border: 1px dashed #94a3b8; background: #fff; border-radius: 8px; padding: .35rem .5rem; font-size: .8rem; color: #475569; }
        .th-sort { cursor: pointer; white-space: nowrap; }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-box2 me-1" style="color:var(--section-color)"></i>المواد الأولية</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
            <span>الإنتاج</span>
            <i class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
            <span class="fw-600" style="color:var(--section-color)">المواد الأولية</span>
        </nav>
    </header>

    <main class="main-content">
        <div class="content-body">

            <!-- تبويبات القسم (مكوّن مشترك — يتبع الشريط الجانبي) -->
            <?php require __DIR__ . '/../../../includes/tab_bar.php'; ?>

            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#f0fdf4"><i class="bi bi-box2 text-success"></i></div>
                        <div>
                            <div class="stat-val"><?= $statTotalActive ?></div>
                            <div class="stat-lbl">مواد نشطة</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fef3c7"><i class="bi bi-exclamation-triangle text-warning"></i></div>
                        <div>
                            <div class="stat-val"><?= $statLowStock ?></div>
                            <div class="stat-lbl">تحت الحد الأدنى</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#eff6ff"><i class="bi bi-tags text-primary"></i></div>
                        <div>
                            <div class="stat-val"><?= $statCatCount ?></div>
                            <div class="stat-lbl">عدد الفئات</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#f0fdf4"><i class="bi bi-cash-stack text-success"></i></div>
                        <div>
                            <div class="stat-val n"><?= number_format($statStockValue, 2) ?> <?= htmlspecialchars($baseCurSym) ?></div>
                            <div class="stat-lbl">قيمة المخزون التقديرية (بعملة الفرع)</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tbl-wrap">
                <div class="tbl-hdr">
                    <span style="font-size:.88rem;font-weight:700;color:#1e293b">
                        <i class="bi bi-list-ul me-1" style="color:var(--section-color)"></i>كتالوج المواد الأولية
                    </span>
                    <div class="d-flex gap-2 ms-auto flex-wrap align-items-center">
                        <input type="text" id="searchBox" class="form-control form-control-sm"
                            style="width:200px;border-radius:8px" placeholder="بحث بالاسم أو الكود...">
                        <select id="filterCategory" class="form-select form-select-sm" style="width:150px;border-radius:8px">
                            <option value="">كل الفئات</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="filterStatus" class="form-select form-select-sm" style="width:120px;border-radius:8px">
                            <option value="">كل الحالات</option>
                            <option value="1">نشط</option>
                            <option value="0">معطّل</option>
                        </select>
                        <div class="form-check d-flex align-items-center gap-1 mb-0">
                            <input class="form-check-input" type="checkbox" id="filterLow" style="margin-top:0">
                            <label class="form-check-label small" for="filterLow">تحت الحد الأدنى بس</label>
                        </div>
                        <?php if ($can_create): ?>
                            <button class="btn btn-sm fw-600" onclick="openMaterialModal()"
                                style="border-radius:9px;background:var(--section-color);color:#fff;font-size:.82rem;white-space:nowrap">
                                <i class="bi bi-plus-lg me-1"></i>إضافة مادة
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="mtbl" id="matTable">
                        <thead>
                            <tr>
                                <th class="th-sort" onclick="sortTableByColumn(0)">الكود</th>
                                <th class="th-sort" onclick="sortTableByColumn(1)">الاسم</th>
                                <th class="th-sort" onclick="sortTableByColumn(2)">الفئة</th>
                                <th class="th-sort" onclick="sortTableByColumn(3)">الوحدة</th>
                                <th class="th-sort" onclick="sortTableByColumn(4)">الرصيد الحالي</th>
                                <th class="th-sort" onclick="sortTableByColumn(5)">الحد الأدنى</th>
                                <th class="th-sort" onclick="sortTableByColumn(6)">آخر تكلفة</th>
                                <th class="th-sort" onclick="sortTableByColumn(7)">الحالة</th>
                                <th style="text-align:center" data-no-sort>إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($materials as $m):
                                $isLow = (float) $m['total_stock'] <= (float) $m['min_stock_threshold'];
                                $isActive = (int) $m['is_active'] === 1;
                                ?>
                                <tr data-cat="<?= (int) $m['category_id'] ?>" data-active="<?= $isActive ? 1 : 0 ?>"
                                    data-low="<?= $isLow ? 1 : 0 ?>"
                                    data-search="<?= htmlspecialchars(mb_strtolower($m['name'] . ' ' . $m['code'])) ?>">
                                    <td class="code-cell"><?= htmlspecialchars($m['code']) ?></td>
                                    <td class="fw-600"><?= htmlspecialchars($m['name']) ?></td>
                                    <td class="text-muted"><?= htmlspecialchars($m['category_name'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($m['unit_name'] ?? '—') ?>
                                        <?= $m['unit_symbol'] ? '(' . htmlspecialchars($m['unit_symbol']) . ')' : '' ?></td>
                                    <td class="n qty-cell">
                                        <?= number_format((float) $m['total_stock'], 2) ?>
                                        <?php if ($isLow && $isActive): ?><span class="badge-status badge-low ms-1">منخفض</span><?php endif; ?>
                                    </td>
                                    <td class="n"><?= number_format((float) $m['min_stock_threshold'], 2) ?></td>
                                    <td class="n"><?= number_format((float) $m['last_cost'], 2) ?> <?= htmlspecialchars($baseCurSym) ?></td>
                                    <td><span class="badge-status <?= $isActive ? 'badge-active' : 'badge-inactive' ?>"><?= $isActive ? 'نشط' : 'معطّل' ?></span></td>
                                    <td>
                                        <div class="d-flex gap-1 justify-content-center">
                                            <button class="act-btn info-h" title="الرصيد بالتفصيل"
                                                onclick="openStockModal(<?= (int) $m['id'] ?>, '<?= htmlspecialchars($m['name'], ENT_QUOTES) ?>')"><i class="bi bi-boxes"></i></button>
                                            <?php if ($can_edit): ?>
                                                <button class="act-btn" title="تعديل" onclick="openMaterialModal(<?= (int) $m['id'] ?>)"><i class="bi bi-pencil"></i></button>
                                                <button class="act-btn <?= $isActive ? 'danger' : 'success-h' ?>" title="<?= $isActive ? 'تعطيل' : 'تفعيل' ?>"
                                                    onclick="toggleStatus(<?= (int) $m['id'] ?>)"><i class="bi bi-<?= $isActive ? 'slash-circle' : 'check-circle' ?>"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$materials): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-5">
                                        <i class="bi bi-box2 d-block mb-2" style="font-size:2rem;opacity:.2"></i>
                                        ما في مواد أولية مسجّلة بعد
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

<!-- مودال إضافة/تعديل مادة -->
<div class="modal fade" id="materialModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="materialModalTitle">إضافة مادة أولية</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="mat_id">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label small fw-600">اسم المادة *</label>
            <input type="text" id="mat_name" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-600">الفئة</label>
            <div class="d-flex gap-1">
              <select id="mat_category" class="form-select">
                <option value="">— بدون فئة —</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="button" class="quick-add-btn" onclick="quickAdd('category')"><i class="bi bi-plus-lg"></i></button>
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-600">وحدة القياس *</label>
            <div class="d-flex gap-1">
              <select id="mat_unit" class="form-select">
                <option value="">— اختر —</option>
                <?php foreach ($units as $u): ?>
                  <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['name']) ?><?= $u['symbol'] ? ' ('.htmlspecialchars($u['symbol']).')' : '' ?></option>
                <?php endforeach; ?>
              </select>
              <button type="button" class="quick-add-btn" onclick="quickAdd('unit')"><i class="bi bi-plus-lg"></i></button>
            </div>
          </div>
          <div class="col-md-3">
            <label class="form-label small fw-600">الحد الأدنى للتنبيه</label>
            <input type="number" step="0.001" min="0" id="mat_threshold" class="form-control" value="0">
          </div>
          <div class="col-md-3">
            <label class="form-label small fw-600">آخر تكلفة (بعملة الفرع)</label>
            <input type="number" step="0.0001" min="0" id="mat_cost" class="form-control" value="0">
          </div>
          <div class="col-12">
            <label class="form-label small fw-600">ملاحظات</label>
            <textarea id="mat_notes" class="form-control" rows="2"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
        <button type="button" class="btn fw-600" style="background:var(--section-color);color:#fff" onclick="saveMaterial()">حفظ</button>
      </div>
    </div>
  </div>
</div>

<!-- مودال الرصيد بالتفصيل + التسوية -->
<div class="modal fade" id="stockModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">رصيد <span id="stockMatName"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="stock_mat_id">
        <table class="table table-sm">
          <thead><tr><th>المستودع</th><th>الرصيد الحالي</th><?php if ($can_edit): ?><th>إجراء</th><?php endif; ?></tr></thead>
          <tbody id="stockBreakdownBody"></tbody>
        </table>

        <?php if ($can_edit): ?>
        <hr>
        <h6 class="small fw-600">تسوية يدوية للرصيد</h6>
        <div class="row g-2 align-items-end">
          <div class="col-md-3">
            <label class="form-label small">المستودع</label>
            <select id="adj_warehouse" class="form-select form-select-sm">
              <?php foreach ($warehouses as $w): ?>
                <option value="<?= (int)$w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label small">الكمية الجديدة (الرصيد الفعلي)</label>
            <input type="number" step="0.001" min="0" id="adj_qty" class="form-control form-control-sm">
          </div>
          <div class="col-md-4">
            <label class="form-label small">سبب التسوية *</label>
            <input type="text" id="adj_notes" class="form-control form-control-sm" placeholder="مثال: جرد فعلي، تلف، رصيد افتتاحي">
          </div>
          <div class="col-md-2">
            <button class="btn btn-warning btn-sm w-100" onclick="adjustStock()">تسوية</button>
          </div>
        </div>
        <?php endif; ?>

        <hr>
        <h6 class="small fw-600">آخر الحركات</h6>
        <div style="max-height:220px;overflow-y:auto">
        <table class="table table-sm table-striped">
          <thead><tr><th>التاريخ</th><th>المستودع</th><th>النوع</th><th>التغيير</th><th>الرصيد بعدها</th><th>ملاحظات</th><th>المستخدم</th></tr></thead>
          <tbody id="movementsBody"></tbody>
        </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CAN_EDIT = <?= $can_edit ? 'true' : 'false' ?>;
const movementTypeLabels = {
    adjustment_in: 'تسوية (زيادة)', adjustment_out: 'تسوية (نقصان)',
    purchase_in: 'شراء', production_out: 'استهلاك إنتاج',
    transfer_in: 'تحويل وارد', transfer_out: 'تحويل صادر'
};

function post(action, data = {}) {
    const fd = new FormData();
    fd.append('_action', action);
    for (const k in data) fd.append(k, data[k]);
    return fetch(location.pathname, { method: 'POST', body: fd }).then(r => r.json());
}

// ---------- فلترة الجدول ----------
function applyFilters() {
    const q = document.getElementById('searchBox').value.trim().toLowerCase();
    const cat = document.getElementById('filterCategory').value;
    const status = document.getElementById('filterStatus').value;
    const lowOnly = document.getElementById('filterLow').checked;
    document.querySelectorAll('#matTable tbody tr[data-search]').forEach(tr => {
        let show = true;
        if (q && !tr.dataset.search.includes(q)) show = false;
        if (cat && tr.dataset.cat !== cat) show = false;
        if (status !== '' && tr.dataset.active !== status) show = false;
        if (lowOnly && tr.dataset.low !== '1') show = false;
        tr.style.display = show ? '' : 'none';
    });
}
['searchBox','filterCategory','filterStatus'].forEach(id => {
    document.getElementById(id).addEventListener('input', applyFilters);
    document.getElementById(id).addEventListener('change', applyFilters);
});
document.getElementById('filterLow').addEventListener('change', applyFilters);

// ---------- ترتيب الجدول ----------
function sortTableByColumn(colIndex) {
    const table = document.getElementById('matTable');
    const tbody = table.tBodies[0];
    const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.dataset.search !== undefined);
    const asc = table.dataset.sortCol == colIndex ? table.dataset.sortDir !== 'asc' : true;
    table.dataset.sortCol = colIndex;
    table.dataset.sortDir = asc ? 'asc' : 'desc';

    rows.sort((a, b) => {
        let av = a.cells[colIndex].innerText.trim();
        let bv = b.cells[colIndex].innerText.trim();
        const an = parseFloat(av.replace(/,/g, ''));
        const bn = parseFloat(bv.replace(/,/g, ''));
        if (!isNaN(an) && !isNaN(bn) && /^[\d.,\s]+/.test(av)) {
            return asc ? an - bn : bn - an;
        }
        return asc ? av.localeCompare(bv, 'ar') : bv.localeCompare(av, 'ar');
    });
    rows.forEach(r => tbody.appendChild(r));
}

// ---------- إضافة/تعديل مادة ----------
const materialModal = new bootstrap.Modal(document.getElementById('materialModal'));
function openMaterialModal(id = null) {
    document.getElementById('mat_id').value = '';
    document.getElementById('mat_name').value = '';
    document.getElementById('mat_category').value = '';
    document.getElementById('mat_unit').value = '';
    document.getElementById('mat_threshold').value = 0;
    document.getElementById('mat_cost').value = 0;
    document.getElementById('mat_notes').value = '';
    document.getElementById('materialModalTitle').innerText = 'إضافة مادة أولية';

    if (id) {
        post('get', { id }).then(res => {
            if (!res.ok) { alert(res.msg); return; }
            const d = res.data;
            document.getElementById('mat_id').value = d.id;
            document.getElementById('mat_name').value = d.name;
            document.getElementById('mat_category').value = d.category_id || '';
            document.getElementById('mat_unit').value = d.unit_id || '';
            document.getElementById('mat_threshold').value = d.min_stock_threshold;
            document.getElementById('mat_cost').value = d.last_cost;
            document.getElementById('mat_notes').value = d.notes || '';
            document.getElementById('materialModalTitle').innerText = 'تعديل مادة أولية — ' + d.code;
            materialModal.show();
        });
    } else {
        materialModal.show();
    }
}

function saveMaterial() {
    const payload = {
        id: document.getElementById('mat_id').value,
        name: document.getElementById('mat_name').value.trim(),
        category_id: document.getElementById('mat_category').value,
        unit_id: document.getElementById('mat_unit').value,
        min_stock_threshold: document.getElementById('mat_threshold').value || 0,
        last_cost: document.getElementById('mat_cost').value || 0,
        notes: document.getElementById('mat_notes').value.trim()
    };
    if (!payload.name) { alert('اسم المادة مطلوب'); return; }
    if (!payload.unit_id) { alert('وحدة القياس مطلوبة'); return; }

    post('save', payload).then(res => {
        if (!res.ok) { alert(res.msg); return; }
        location.reload();
    });
}

function toggleStatus(id) {
    if (!confirm('تأكيد تغيير حالة المادة؟')) return;
    post('toggle_status', { id }).then(res => {
        if (!res.ok) { alert(res.msg); return; }
        location.reload();
    });
}

// ---------- إضافة سريعة (فئة/وحدة) ----------
function quickAdd(type) {
    const name = prompt(type === 'category' ? 'اسم الفئة الجديدة:' : 'اسم الوحدة الجديدة:');
    if (!name || !name.trim()) return;
    if (type === 'category') {
        post('add_category', { name: name.trim() }).then(res => {
            if (!res.ok) { alert(res.msg); return; }
            const opt = new Option(res.name, res.id, true, true);
            document.getElementById('mat_category').add(opt);
        });
    } else {
        const symbol = prompt('رمز الوحدة (اختياري، مثال: كغ):') || '';
        post('add_unit', { name: name.trim(), symbol: symbol.trim() }).then(res => {
            if (!res.ok) { alert(res.msg); return; }
            const label = res.symbol ? `${res.name} (${res.symbol})` : res.name;
            const opt = new Option(label, res.id, true, true);
            document.getElementById('mat_unit').add(opt);
        });
    }
}

// ---------- مودال الرصيد ----------
const stockModal = new bootstrap.Modal(document.getElementById('stockModal'));
function openStockModal(materialId, name) {
    document.getElementById('stock_mat_id').value = materialId;
    document.getElementById('stockMatName').innerText = name;
    loadStockBreakdown(materialId);
    loadMovements(materialId);
    stockModal.show();
}

function loadStockBreakdown(materialId) {
    post('get_stock_breakdown', { material_id: materialId }).then(res => {
        const body = document.getElementById('stockBreakdownBody');
        body.innerHTML = '';
        if (!res.ok) { body.innerHTML = `<tr><td colspan="3">${res.msg}</td></tr>`; return; }
        res.data.forEach(row => {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td>${row.warehouse_name}</td><td>${Number(row.quantity).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</td>` +
                (CAN_EDIT ? `<td><button class="btn btn-sm btn-outline-warning" onclick="prefillAdjust(${row.warehouse_id})">تسوية</button></td>` : '');
            body.appendChild(tr);
        });
    });
}

function loadMovements(materialId) {
    post('get_movements', { material_id: materialId }).then(res => {
        const body = document.getElementById('movementsBody');
        body.innerHTML = '';
        if (!res.ok || !res.data.length) { body.innerHTML = `<tr><td colspan="7" class="text-muted text-center">ما في حركات مسجّلة بعد</td></tr>`; return; }
        res.data.forEach(mv => {
            const tr = document.createElement('tr');
            const changeClass = mv.quantity_change >= 0 ? 'text-success' : 'text-danger';
            tr.innerHTML = `
                <td>${mv.created_at}</td>
                <td>${mv.warehouse_name}</td>
                <td>${movementTypeLabels[mv.movement_type] || mv.movement_type}</td>
                <td class="${changeClass}">${mv.quantity_change >= 0 ? '+' : ''}${Number(mv.quantity_change).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
                <td>${Number(mv.quantity_after).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
                <td>${mv.notes || ''}</td>
                <td>${mv.full_name || mv.username || '—'}</td>
            `;
            body.appendChild(tr);
        });
    });
}

function prefillAdjust(warehouseId) {
    document.getElementById('adj_warehouse').value = warehouseId;
    document.getElementById('adj_qty').focus();
}

function adjustStock() {
    const materialId = document.getElementById('stock_mat_id').value;
    const warehouseId = document.getElementById('adj_warehouse').value;
    const qty = document.getElementById('adj_qty').value;
    const notes = document.getElementById('adj_notes').value.trim();

    if (qty === '' || qty < 0) { alert('أدخل الكمية الجديدة الصحيحة'); return; }
    if (!notes) { alert('لازم تكتب سبب التسوية'); return; }

    post('adjust_stock', { material_id: materialId, warehouse_id: warehouseId, new_quantity: qty, notes }).then(res => {
        if (!res.ok) { alert(res.msg); return; }
        document.getElementById('adj_qty').value = '';
        document.getElementById('adj_notes').value = '';
        loadStockBreakdown(materialId);
        loadMovements(materialId);
    });
}
</script>
<script src="<?= BASE_PATH ?>/assets/js/sidebar.js"></script>
</body>
</html>
