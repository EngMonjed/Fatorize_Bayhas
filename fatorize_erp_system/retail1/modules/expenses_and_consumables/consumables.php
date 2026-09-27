<?php
/**
 * consumables.php — إدارة المستهلكات
 *retail1/modules/expenses_and_consumables/consumables.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('inventory.consumables', 'view');
$currentModule = 'inventory.consumables'; // ✅ كانت غير معرّفة — نفس فجوة warehouse.php

$TS  = $_SESSION['table_suffix'];
$TI  = "consumable_items_{$TS}";
$TST = "consumable_stock_{$TS}";
$TM  = "consumable_movements_{$TS}";
$TW = "warehouses_{$TS}";
$TC = "consumable_categories_{$TS}";
$TU = "consumable_units_{$TS}";
$TPK = "consumable_item_packagings_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// ── عملة الفرع الأساسية — كل التكاليف التقديرية تُسجَّل فيها مباشرة ──
$baseCurRow = $pdo->query("SELECT id, code, symbol FROM currencies WHERE is_base=1 LIMIT 1")->fetch();
$baseCurrencyId = (int) ($baseCurRow['id'] ?? 0);
$baseCurrencyLabel = $baseCurRow ? ($baseCurRow['symbol'] ?: $baseCurRow['code']) : '';

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        if ($act === 'get_item') {
            $id = (int) $_POST['id'];
            $st = $pdo->prepare("SELECT ci.*,
                COALESCE(SUM(cs.quantity),0) AS total_qty,
                MAX(cs.min_quantity)         AS min_qty,
                MAX(cs.avg_cost_base)         AS avg_cost,
                cu.code AS currency_code, cu.symbol AS currency_symbol,
                cc.name AS category_name, cc.icon AS category_icon,
                cc.color AS category_color, cc.bg_color AS category_bg,
                cun.name AS unit_name
                FROM `{$TI}` ci
                LEFT JOIN `{$TST}` cs ON cs.item_id=ci.id
                LEFT JOIN `currencies` cu ON cu.id=ci.currency_id
                LEFT JOIN `{$TC}` cc ON cc.id=ci.category_id
                LEFT JOIN `{$TU}` cun ON cun.id=ci.unit_id
                WHERE ci.id=? GROUP BY ci.id");
            $st->execute([$id]);
            $item = $st->fetch();
            if (!$item)
                throw new Exception('المادة غير موجودة');
            $stk = $pdo->prepare("SELECT cs.*, w.name AS wh_name FROM `{$TST}` cs
                JOIN `{$TW}` w ON w.id=cs.warehouse_id WHERE cs.item_id=?");
            $stk->execute([$id]);
            $item['stock'] = $stk->fetchAll();

            // مستودعات المستهلكات النشطة اللي المادة لسا مش فيها — لدعم "إضافة رصيد بمستودع جديد" من مودال التفاصيل
            $usedWhIds = array_column($item['stock'], 'warehouse_id');
            if ($usedWhIds) {
                $ph = implode(',', array_fill(0, count($usedWhIds), '?'));
                $availSt = $pdo->prepare("SELECT id, name FROM `{$TW}` WHERE is_active=1 AND warehouse_type='consumables' AND id NOT IN ({$ph})");
                $availSt->execute($usedWhIds);
            } else {
                $availSt = $pdo->query("SELECT id, name FROM `{$TW}` WHERE is_active=1 AND warehouse_type='consumables'");
            }
            $item['available_warehouses'] = $availSt->fetchAll();

            // عبوات المادة الخاصة (كرتونة/دستة...) — معامل التحويل مربوط بهذه المادة فقط
            $pkSt = $pdo->prepare("SELECT * FROM `{$TPK}` WHERE item_id=? AND is_active=1 ORDER BY id");
            $pkSt->execute([$id]);
            $item['packagings'] = $pkSt->fetchAll();

            echo json_encode(['ok' => true, 'data' => $item]);
        } elseif ($act === 'save_item') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $category_id = (int) ($_POST['category_id'] ?? 0) ?: null;
            $unit_id = (int) ($_POST['unit_id'] ?? 0) ?: null;
            $est_cost = (float) ($_POST['estimated_cost'] ?? 0);
            $min_qty = (float) ($_POST['min_quantity'] ?? 0);
            $wh_id = (int) ($_POST['warehouse_id'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            if (!$name)
                throw new Exception('اسم المادة مطلوب');
            if (!$category_id)
                throw new Exception('يجب اختيار الفئة');
            if (!$unit_id)
                throw new Exception('يجب اختيار وحدة القياس');

            // ⚠ التكلفة التقديرية دايماً بعملة الفرع الأساسية — لا يوجد
            // حقل عملة بالواجهة (نفس قرار product_add.php/product_edit.php)
            $currency_id = $baseCurrencyId ?: null;

            // ── تحقق من التكرار: الاسم وحده هو معيار الهوية — مش
            // الاسم+الوحدة مع بعض. اشتراك وحدتين مادتين مختلفتين بنفس
            // وحدة القياس ("قطعة" مثلاً) شي طبيعي تماماً ومش علامة
            // تكرار. لو الفرق لون/مقاس بمادتين متشابهتين، لازم يتوضح
            // ضمن الاسم نفسه (بالضبط لهيك عندنا avg_cost_base بالمخزون
            // أصلاً، مش السعر معيار الهوية أيضاً) ──
            $dupSt = $pdo->prepare("SELECT id FROM `{$TI}`
                WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND id <> ?");
            $dupSt->execute([$name, $id]);
            if ($dupSt->fetchColumn()) {
                throw new Exception('توجد مادة مسجّلة مسبقاً بنفس الاسم — لو الفرق لون/مقاس/وحدة قياس، وضّحه ضمن الاسم نفسه (مثال: "زر 5سم - أخضر")');
            }

            if ($id) {
                requirePermission('inventory.consumables', 'edit');
                $pdo->prepare("UPDATE `{$TI}` SET name=?,category_id=?,unit_id=?,estimated_cost=?,currency_id=?,notes=?,updated_at=NOW() WHERE id=?")
                    ->execute([$name, $category_id, $unit_id, $est_cost, $currency_id, $notes, $id]);
            } else {
                requirePermission('inventory.consumables', 'create');
                $pdo->prepare("INSERT INTO `{$TI}` (name,category_id,unit_id,estimated_cost,currency_id,notes,created_by) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$name, $category_id, $unit_id, $est_cost, $currency_id, $notes, $_SESSION['user_id']]);
                $id = (int) $pdo->lastInsertId();
            }
            if ($wh_id && $id) {
                $pdo->prepare("INSERT INTO `{$TST}` (item_id,warehouse_id,quantity,min_quantity) VALUES (?,?,0,?)
                    ON DUPLICATE KEY UPDATE min_quantity=VALUES(min_quantity)")
                    ->execute([$id, $wh_id, $min_qty]);
            }
            echo json_encode(['ok' => true, 'id' => $id]);
        } elseif ($act === 'get_lookups') {
            // ⚠ تحديث قوائم الفئة/الوحدة/المستودع بدون إعادة تحميل الصفحة
            // كاملة — مفيد لو ضاف حدا فئة/وحدة/مستودع من مكان تاني
            // (مثلاً صفحة المستودعات بتبويبة جديدة) بنفس الوقت
            $catsRow = $pdo->query("SELECT id, name FROM `{$TC}` WHERE is_active=1 ORDER BY sort_order, name")->fetchAll();
            $unitsRow = $pdo->query("SELECT id, name FROM `{$TU}` WHERE is_active=1 ORDER BY name")->fetchAll();
            $whRow = $pdo->query("SELECT id, name FROM `{$TW}` WHERE is_active=1 AND warehouse_type='consumables' ORDER BY id")->fetchAll();
            echo json_encode(['ok' => true, 'categories' => $catsRow, 'units' => $unitsRow, 'warehouses' => $whRow]);
        } elseif ($act === 'add_unit') {
            // ⚠ نفس نمط add_category بالضبط — وحدة قياس أساسية جديدة (بلا معامل تحويل، هاد فقط تصنيف)
            requirePermission('inventory.consumables', 'create');
            $name = trim($_POST['name'] ?? '');
            if (!$name)
                throw new Exception('اسم الوحدة مطلوب');
            $dupSt = $pdo->prepare("SELECT id FROM `{$TU}` WHERE LOWER(TRIM(name))=LOWER(TRIM(?))");
            $dupSt->execute([$name]);
            if ($dupSt->fetchColumn())
                throw new Exception('هذه الوحدة موجودة مسبقاً');
            $pdo->prepare("INSERT INTO `{$TU}` (name, created_by) VALUES (?,?)")
                ->execute([$name, $_SESSION['user_id']]);
            $newId = (int) $pdo->lastInsertId();
            echo json_encode(['ok' => true, 'unit' => ['id' => $newId, 'name' => $name]]);
        } elseif ($act === 'save_packaging') {
            // ⚠ عبوة/تغليف خاص بمادة واحدة تحديداً — معامل التحويل هون
            // مربوط بالمادة (item_id)، مش عالمي، تفادياً لتعارض بين
            // مواد مختلفة بنفس اسم العبوة وعدد مختلف فعلياً
            requirePermission('inventory.consumables', 'edit');
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $qtyPer = (float) ($_POST['qty_per_package'] ?? 0);
            if (!$itemId || !$name)
                throw new Exception('بيانات ناقصة');
            if ($qtyPer <= 0)
                throw new Exception('كمية العبوة يجب أن تكون أكبر من صفر');

            // ⚠ الحذف "ناعم" (is_active=0)، والجدول فيه قيد فريد على
            // (item_id, name) — يعني صف بنفس الاسم يضل محجوز بقاعدة
            // البيانات حتى لو معطّل بالواجهة. فبدل ما نمنع أو نحاول
            // ننشئ صف جديد (وينكسر على القيد الفريد)، نتحقق أولاً:
            $dupSt = $pdo->prepare("SELECT id, is_active FROM `{$TPK}` WHERE item_id=? AND LOWER(TRIM(name))=LOWER(TRIM(?))");
            $dupSt->execute([$itemId, $name]);
            $existing = $dupSt->fetch();

            if ($existing && (int) $existing['is_active'] === 1) {
                throw new Exception('هذه العبوة موجودة مسبقاً لهذه المادة');
            } elseif ($existing) {
                // كان معطّل (محذوف سابقاً) — نُحييه بدل ما ننشئ صف جديد
                $pdo->prepare("UPDATE `{$TPK}` SET qty_per_package=?, is_active=1, created_by=?, created_at=NOW() WHERE id=?")
                    ->execute([$qtyPer, $_SESSION['user_id'], $existing['id']]);
                echo json_encode(['ok' => true, 'id' => (int) $existing['id']]);
            } else {
                $pdo->prepare("INSERT INTO `{$TPK}` (item_id, name, qty_per_package, created_by) VALUES (?,?,?,?)")
                    ->execute([$itemId, $name, $qtyPer, $_SESSION['user_id']]);
                echo json_encode(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
            }
        } elseif ($act === 'delete_packaging') {
            requirePermission('inventory.consumables', 'edit');
            $id = (int) ($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE `{$TPK}` SET is_active=0 WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true]);
        } elseif ($act === 'add_category') {
            // ⚠ نفس نمط add_supplier بملف consumable_purchases.php —
            // مودال إضافة سريع فوق مودال المادة، بدون ما نسكرها
            requirePermission('inventory.consumables', 'create');
            $name = trim($_POST['name'] ?? '');
            $icon = trim($_POST['icon'] ?? '') ?: 'bi-tag';
            if (!$name)
                throw new Exception('اسم الفئة مطلوب');
            $dupSt = $pdo->prepare("SELECT id FROM `{$TC}` WHERE LOWER(TRIM(name))=LOWER(TRIM(?))");
            $dupSt->execute([$name]);
            if ($dupSt->fetchColumn())
                throw new Exception('هذه الفئة موجودة مسبقاً');
            $pdo->prepare("INSERT INTO `{$TC}` (name, icon, created_by) VALUES (?,?,?)")
                ->execute([$name, $icon, $_SESSION['user_id']]);
            $newId = (int) $pdo->lastInsertId();
            $row = $pdo->prepare("SELECT * FROM `{$TC}` WHERE id=?");
            $row->execute([$newId]);
            echo json_encode(['ok' => true, 'category' => $row->fetch()]);
        } elseif ($act === 'save_item_warehouse') {
            // ⚠ إدارة رصيد/حد تنبيه المادة لكل مستودع بشكل مستقل —
            // مكانها الصحيح مودال "تفاصيل" (view)، مو مودال الإضافة/التعديل،
            // لأنه ممكن تكون المادة بأكتر من مستودع بنفس الوقت (نمط
            // معياري بالأنظمة المحاسبية: نقطة إعادة الطلب مربوطة
            // بالمستودع، مش بالمادة نفسها).
            requirePermission('inventory.consumables', 'edit');
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $whId = (int) ($_POST['warehouse_id'] ?? 0);
            $minQty = (float) ($_POST['min_quantity'] ?? 0);
            if (!$itemId || !$whId)
                throw new Exception('بيانات ناقصة');
            $pdo->prepare("INSERT INTO `{$TST}` (item_id,warehouse_id,quantity,min_quantity) VALUES (?,?,0,?)
                ON DUPLICATE KEY UPDATE min_quantity=VALUES(min_quantity)")
                ->execute([$itemId, $whId, $minQty]);
            echo json_encode(['ok' => true]);
        } elseif ($act === 'toggle_item') {
            requirePermission('inventory.consumables', 'edit');
            $id = (int) $_POST['id'];
            $pdo->prepare("UPDATE `{$TI}` SET is_active=NOT is_active WHERE id=?")->execute([$id]);
            $row = $pdo->prepare("SELECT is_active FROM `{$TI}` WHERE id=?");
            $row->execute([$id]);
            echo json_encode(['ok' => true, 'is_active' => (int) $row->fetchColumn()]);
        } elseif ($act === 'delete_item') {
            requirePermission('inventory.consumables', 'delete');
            $id = (int) $_POST['id'];
            $chk = $pdo->prepare("SELECT COUNT(*) FROM `{$TM}` WHERE item_id=?");
            $chk->execute([$id]);
            if ($chk->fetchColumn() > 0)
                throw new Exception('لا يمكن حذف مادة لها حركات — يمكنك تعطيلها فقط');
            // ⚠ عبوات الشراء (كرتونة...) مجرد بيانات تعريف/إعداد، مش
            // سجل تاريخي حقيقي زي الحركات — آمن حذفها تلقائياً مع
            // المادة، بعكس فحص الحركات فوق يلي لازم يضل يمنع الحذف
            $pdo->prepare("DELETE FROM `{$TPK}` WHERE item_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM `{$TST}` WHERE item_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM `{$TI}` WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true]);
        } else
            throw new Exception('إجراء غير معروف');
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── بيانات الصفحة ──────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$filterCat = (int) ($_GET['cat'] ?? 0);
$where = 'WHERE 1=1';
$params = [];
if ($search) {
    $where .= ' AND ci.name LIKE ?';
    $params[] = "%{$search}%";
}
if ($filterCat) {
    $where .= ' AND ci.category_id=?';
    $params[] = $filterCat;
}

$stmt = $pdo->prepare("SELECT ci.*,
    COALESCE(SUM(cs.quantity),0)                    AS total_qty,
    MAX(cs.min_quantity)                             AS min_qty,
    COALESCE(AVG(NULLIF(cs.avg_cost_base,0)),0)      AS avg_cost,
    COUNT(DISTINCT cs.warehouse_id)                  AS wh_count,
    cu.code AS currency_code, cu.symbol AS currency_symbol,
    cc.name AS category_name, cc.icon AS category_icon,
    cc.color AS category_color, cc.bg_color AS category_bg,
    cun.name AS unit_name
    FROM `{$TI}` ci
    LEFT JOIN `{$TST}` cs ON cs.item_id=ci.id
    LEFT JOIN `currencies` cu ON cu.id=ci.currency_id
    LEFT JOIN `{$TC}` cc ON cc.id=ci.category_id
    LEFT JOIN `{$TU}` cun ON cun.id=ci.unit_id
    {$where}
    GROUP BY ci.id ORDER BY cc.sort_order, ci.name");
$stmt->execute($params);
$items = $stmt->fetchAll();

$warehouses = $pdo->query("SELECT * FROM `{$TW}` WHERE is_active=1 AND warehouse_type='consumables' ORDER BY id")->fetchAll();
$categories = $pdo->query("SELECT * FROM `{$TC}` WHERE is_active=1 ORDER BY sort_order, name")->fetchAll();
$units = $pdo->query("SELECT * FROM `{$TU}` WHERE is_active=1 ORDER BY name")->fetchAll();
$catsById = [];
foreach ($categories as $c) {
    $catsById[$c['id']] = ['label' => $c['name'], 'icon' => $c['icon'], 'clr' => $c['color'], 'bg' => $c['bg_color']];
}
$DEFAULT_CAT = ['label' => 'غير مصنّف', 'icon' => 'bi-question', 'clr' => '#64748b', 'bg' => '#f1f5f9'];
$CATS = $catsById; // اسم قديم محفوظ للتوافق مع أماكن تانية بالملف تستخدمه أدناه

// إحصائيات
$s = $pdo->query("SELECT COUNT(*) AS total, SUM(is_active) AS active FROM `{$TI}`")->fetch();
try {
    $low = $pdo->query("SELECT COUNT(DISTINCT item_id) FROM `{$TST}` WHERE quantity>0 AND min_quantity>0 AND quantity<=min_quantity")->fetchColumn();
    $out = $pdo->query("SELECT COUNT(DISTINCT ci.id) FROM `{$TI}` ci LEFT JOIN `{$TST}` cs ON cs.item_id=ci.id WHERE ci.is_active=1 HAVING COALESCE(SUM(cs.quantity),0)=0")->fetchColumn();
} catch (Exception $e) {
    $low = 0;
    $out = 0;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>إدارة المستهلكات — <?= htmlspecialchars($branchName) ?></title>
    <link rel="icon" href="<?= BASE_PATH ?>/assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/assets/css/layout.css" rel="stylesheet">
    <style>
        .stat-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 12px
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0
        }

        .stat-val {
            font-size: 1.4rem;
            font-weight: 700;
            color: #1e293b;
            line-height: 1
        }

        .stat-lbl {
            font-size: .73rem;
            color: #64748b;
            margin-top: 2px
        }

        .tbl-wrap {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            overflow: hidden
        }

        .tbl-hdr {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap
        }

        table.mtbl {
            width: 100%;
            border-collapse: collapse;
            font-size: .83rem
        }

        table.mtbl th {
            background: #f8fafc;
            padding: 9px 12px;
            font-weight: 600;
            color: #64748b;
            font-size: .73rem;
            border-bottom: 1px solid #f1f5f9;
            white-space: nowrap
        }

        table.mtbl td {
            padding: 9px 12px;
            border-bottom: 1px solid #f8fafc;
            vertical-align: middle
        }

        table.mtbl tr:last-child td {
            border-bottom: none
        }

        table.mtbl tr:hover td {
            background: #f8faff
        }

        .cat-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 20px;
            font-size: .7rem;
            padding: 2px 9px;
            font-weight: 600;
            border: 1px solid
        }

        .act-btn {
            width: 30px;
            height: 30px;
            border-radius: 7px;
            border: 1px solid #e2e8f0;
            background: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .82rem;
            color: #64748b;
            cursor: pointer;
            transition: all .12s;
            text-decoration: none
        }

        .act-btn:hover {
            background: #f1f5f9;
            color: #1e293b
        }

        .act-btn.danger:hover {
            background: #fee2e2;
            color: #dc2626;
            border-color: #fca5a5
        }

        .act-btn.success-h:hover {
            background: #dcfce7;
            color: #16a34a;
            border-color: #86efac
        }

        .field-lbl {
            font-size: .75rem;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 4px;
            display: block
        }

        .req {
            color: #dc2626
        }

        .field-hint {
            font-size: .7rem;
            color: #94a3b8;
            margin-top: 3px
        }

        .srow {
            display: flex;
            justify-content: space-between;
            font-size: .78rem;
            padding: 4px 0;
            border-bottom: 1px solid #f1f5f9
        }

        .srow:last-child {
            border-bottom: none
        }

        .n {
            font-variant-numeric: tabular-nums
        }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php
    require_once __DIR__ . '/../../../includes/sidebar.php';
    require_once __DIR__ . '/../../../includes/breadcrumb.php';
    ?>

    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-box-seam me-1 text-primary"></i>المستهلكات</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <?= renderBreadcrumb() ?>
    </header>

    <main class="main-content">
        <div class="content-body">

            <!-- التبويبات -->
            <ul class="nav nav-tabs mb-3" id="mainTabs" style="border-bottom:2px solid #e2e8f0">
                <li class="nav-item">
                    <a class="nav-link active fw-600" id="tab-items" href="#" onclick="switchTab('items',this)"
                        style="border:none;border-bottom:2px solid #1e3a8a;color:#1e3a8a;font-size:.83rem;margin-bottom:-2px">
                        <i class="bi bi-box-seam me-1"></i>المواد الاستهلاكية
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" id="tab-purchases" href="consumable_purchases.php"
                        style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-cart-plus me-1"></i>فواتير الشراء
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" id="tab-issues" href="consumable_issues.php"
                        style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-arrow-bar-up me-1"></i>صرف المستهلكات
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="../inventory/warehouse.php?type=consumables"
                        style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-building me-1"></i>مستودعات المستهلكات
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="../inventory/movements.php?tab=consumables"
                        style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-arrow-left-right me-1"></i>حركة المستهلكات
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="consumable_transfers.php"
                        style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-signpost-split me-1"></i>مناقلة بين المستودعات
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="expenses.php"
                        style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-wallet2 me-1"></i>إدارة المصاريف
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="../purchases/suppliers.php?tab=consumables"
                        style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-people me-1"></i>موردو المستهلكات
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="consumable_reports.php"
                        style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-bar-chart me-1"></i>التقارير
                    </a>
                </li>
            </ul>

            <!-- إحصائيات -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#eff6ff"><i class="bi bi-box-seam text-primary"></i>
                        </div>
                        <div>
                            <div class="stat-val"><?= $s['total'] ?></div>
                            <div class="stat-lbl">إجمالي المواد</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#f0fdf4"><i
                                class="bi bi-check-circle text-success"></i></div>
                        <div>
                            <div class="stat-val"><?= $s['active'] ?></div>
                            <div class="stat-lbl">مواد نشطة</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fef3c7"><i
                                class="bi bi-exclamation-triangle text-warning"></i></div>
                        <div>
                            <div class="stat-val"><?= $low ?></div>
                            <div class="stat-lbl">مخزون منخفض</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fee2e2"><i class="bi bi-x-circle text-danger"></i>
                        </div>
                        <div>
                            <div class="stat-val"><?= $out ?></div>
                            <div class="stat-lbl">نفد المخزون</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- الجدول -->
            <div class="tbl-wrap">
                <div class="tbl-hdr">
                    <span style="font-size:.88rem;font-weight:700;color:#1e293b">
                        <i class="bi bi-list-ul me-1 text-primary"></i>قائمة المستهلكات
                    </span>
                    <div class="d-flex gap-2 ms-auto flex-wrap align-items-center">
                        <form method="get" class="d-flex gap-2">
                            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="بحث..."
                                class="form-control form-control-sm" style="width:150px;border-radius:8px">
                            <select name="cat" class="form-select form-select-sm" style="width:130px;border-radius:8px"
                                onchange="this.form.submit()">
                                <option value="">كل الفئات</option>
                                <?php foreach ($categories as $c): ?>
                                            <option value="<?= $c['id'] ?>" <?= $filterCat === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <button class="btn btn-sm fw-600"
                            style="border-radius:9px;background:#1e3a8a;color:#fff;font-size:.82rem"
                            onclick="openAdd()">
                            <i class="bi bi-plus-lg me-1"></i>مادة جديدة
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="mtbl" id="itemsTbl">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th style="color:#1e3a8a">اسم المادة</th>
                                <th>الفئة</th>
                                <th>الوحدة</th>
                                <th>الرصيد</th>
                                <th>حد التنبيه</th>
                                <th>التكلفة التقديرية</th>
                                <th>الحالة</th>
                                <th style="text-align:center" data-no-sort>إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($items)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted py-4">
                                                <i class="bi bi-box-seam d-block mb-2" style="font-size:2rem;opacity:.2"></i>
                                                لا توجد مواد<?= $search ? " تطابق \"{$search}\"" : '' ?>
                                            </td>
                                        </tr>
                            <?php endif; ?>
                            <?php foreach ($items as $i => $item):
                                $cat = $catsById[$item['category_id']] ?? $DEFAULT_CAT;
                                $qty = (float) $item['total_qty'];
                                $minQ = (float) $item['min_qty'];
                                $qClr = $qty <= 0 ? '#dc2626' : ($minQ > 0 && $qty <= $minQ ? '#d97706' : '#16a34a');
                                ?>
                                        <tr id="iRow_<?= $item['id'] ?>">
                                            <td class="text-muted small"><?= $i + 1 ?></td>
                                            <td>
                                                <div class="fw-600" style="color:#1e3a8a"><?= htmlspecialchars($item['name']) ?></div>
                                                <?php if ($item['notes']): ?>
                                                            <div class="text-muted" style="font-size:.7rem">
                                                                <?= htmlspecialchars(mb_substr($item['notes'], 0, 40)) ?>...</div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="cat-badge"
                                                    style="color:<?= $cat['clr'] ?>;background:<?= $cat['bg'] ?>;border-color:<?= $cat['clr'] ?>55">
                                                    <?= $cat['label'] ?>
                                                </span>
                                            </td>
                                            <td style="color:#64748b;font-size:.78rem"><?= htmlspecialchars($item['unit_name'] ?? '—') ?></td>
                                            <td class="n fw-600" style="color:<?= $qClr ?>">
                                                <?= number_format($qty, 2) ?>
                                                <span
                                                    style="font-weight:400;font-size:.7rem;color:#94a3b8"><?= htmlspecialchars($item['unit_name'] ?? '—') ?></span>
                                            </td>
                                            <td class="n text-muted"><?= $minQ > 0 ? number_format($minQ, 2) : '—' ?></td>
                                            <td class="n text-muted">
                                                <?= $item['estimated_cost'] > 0 ? number_format($item['estimated_cost'], 4) . ' ' . htmlspecialchars($item['currency_code'] ?? '$') : '—' ?>
                                            </td>
                                            <td>
                                                <?php if ($item['is_active']): ?>
                                                            <span class="badge bg-success-subtle text-success border border-success-subtle"
                                                                style="font-size:.68rem">نشط</span>
                                                <?php else: ?>
                                                            <span class="badge bg-secondary-subtle text-secondary"
                                                                style="font-size:.68rem">معطّل</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="d-flex gap-1 justify-content-center">
                                                    <button class="act-btn" onclick="viewItem(<?= $item['id'] ?>)" title="تفاصيل"
                                                        style="color:#0891b2;border-color:#a5f3fc">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <button class="act-btn" onclick="openEdit(<?= $item['id'] ?>)" title="تعديل">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button class="act-btn <?= $item['is_active'] ? 'danger' : 'success-h' ?>"
                                                        onclick="toggleItem(<?= $item['id'] ?>)"
                                                        title="<?= $item['is_active'] ? 'تعطيل' : 'تفعيل' ?>">
                                                        <i class="bi bi-<?= $item['is_active'] ? 'slash-circle' : 'check-circle' ?>"></i>
                                                    </button>
                                                    <button class="act-btn danger"
                                                        onclick="deleteItem(<?= $item['id'] ?>,'<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>')"
                                                        title="حذف">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

    <!-- مودال الإضافة/التعديل -->
    <div class="modal fade" id="itemModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#0c447c,#1e3a8a);border-radius:16px 16px 0 0">
                    <h6 class="modal-title text-white fw-700 mb-0" id="mTitle">إضافة مادة استهلاكية</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pt-3">
                    <input type="hidden" id="mId">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="field-lbl">اسم المادة <span class="req">*</span></label>
                            <input type="text" id="mName" class="form-control form-control-sm"
                                placeholder="مثال: قهوة، قرطاسية، غاز">
                        </div>
                        <div class="col-md-6">
                            <label class="field-lbl">الفئة <span class="req">*</span></label>
                            <div class="d-flex gap-1">
                                <select id="mCategory" class="form-select form-select-sm">
                                    <option value="">— اختر —</option>
                                    <?php foreach ($categories as $c): ?>
                                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-sm btn-outline-secondary" title="تحديث القائمة"
                                    style="border-radius:8px" onclick="refreshLookup('categories','mCategory')">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" title="فئة جديدة"
                                    style="border-radius:8px" onclick="openCategoryModal()">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">وحدة القياس (المخزون) <span class="req">*</span></label>
                            <div class="d-flex gap-1">
                                <select id="mUnit" class="form-select form-select-sm">
                                    <option value="">— اختر —</option>
                                    <?php foreach ($units as $u): ?>
                                                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-sm btn-outline-secondary" title="تحديث القائمة"
                                    style="border-radius:8px" onclick="refreshLookup('units','mUnit')">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" title="وحدة جديدة"
                                    style="border-radius:8px" onclick="openUnitModal()">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                            <div class="field-hint">هذه وحدة تتبع المخزون — عبوات الشراء (كرتونة...) تُدار من مودال "تفاصيل" بعد الحفظ</div>
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">التكلفة التقديرية</label>
                            <input type="number" id="mEstCost" class="form-control form-control-sm" min="0" step="0.0001"
                                placeholder="0.00">
                            <div class="field-hint">بعملة الفرع الأساسية (<?= htmlspecialchars($baseCurrencyLabel ?: '؟') ?>) — للمقارنة مع التكلفة الفعلية</div>
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">حد التنبيه (الكمية)</label>
                            <input type="number" id="mMinQty" class="form-control form-control-sm" min="0" step="0.001"
                                placeholder="0">
                            <div class="field-hint">تنبيه عند انخفاض المخزون</div>
                        </div>
                        <div class="col-md-6">
                            <label class="field-lbl">المستودع الافتراضي</label>
                            <div class="d-flex gap-1">
                                <select id="mWarehouse" class="form-select form-select-sm">
                                    <option value="">— اختر المستودع —</option>
                                    <?php foreach ($warehouses as $wh): ?>
                                                <option value="<?= $wh['id'] ?>"><?= htmlspecialchars($wh['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-sm btn-outline-secondary" title="تحديث القائمة"
                                    style="border-radius:8px" onclick="refreshLookup('warehouses','mWarehouse')">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                                <a href="../inventory/warehouse.php?type=consumables" target="_blank" rel="noopener" title="إدارة المستودعات (تبويبة جديدة)"
                                    class="btn btn-sm btn-outline-secondary" style="border-radius:8px">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="field-lbl">ملاحظات</label>
                            <textarea id="mNotes" class="form-control form-control-sm" rows="2"
                                placeholder="اختياري"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button class="btn btn-sm btn-light" style="border-radius:8px"
                        data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-sm fw-600"
                        style="border-radius:8px;background:#1e3a8a;color:#fff;min-width:100px" onclick="saveItem()">
                        <span id="saveTxt"><i class="bi bi-floppy me-1"></i>حفظ</span>
                        <span id="saveSpin" class="spinner-border spinner-border-sm" style="display:none"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- مودال إضافة فئة سريع -->
    <div class="modal fade" id="categoryModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#7c3aed,#5b21b6);border-radius:16px 16px 0 0">
                    <h6 class="modal-title text-white fw-700 mb-0">فئة جديدة</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pt-3">
                    <div class="mb-2">
                        <label class="field-lbl">اسم الفئة <span class="req">*</span></label>
                        <input type="text" id="cName" class="form-control form-control-sm" placeholder="مثال: تعبئة وتغليف">
                    </div>
                    <div class="mb-2">
                        <label class="field-lbl">أيقونة (اختياري)</label>
                        <input type="text" id="cIcon" class="form-control form-control-sm" placeholder="bi-tag"
                            value="bi-tag">
                        <div class="field-hint">اسم أيقونة Bootstrap Icons — اتركه كما هو إذا لم تكن متأكداً</div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-sm fw-600" style="border-radius:8px;background:#1e3a8a;color:#fff;min-width:90px"
                        onclick="saveCategory()">حفظ</button>
                </div>
            </div>
        </div>
    </div>

    <!-- مودال إضافة وحدة قياس سريع -->
    <div class="modal fade" id="unitModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#0891b2,#0e7490);border-radius:16px 16px 0 0">
                    <h6 class="modal-title text-white fw-700 mb-0">وحدة قياس جديدة</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pt-3">
                    <label class="field-lbl">اسم الوحدة <span class="req">*</span></label>
                    <input type="text" id="uName" class="form-control form-control-sm" placeholder="مثال: طن، دستة">
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-sm fw-600" style="border-radius:8px;background:#1e3a8a;color:#fff;min-width:90px"
                        onclick="saveUnit()">حفظ</button>
                </div>
            </div>
        </div>
    </div>

    <!-- مودال التفاصيل -->
    <div class="modal fade" id="viewModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#0c447c,#1e3a8a);border-radius:16px 16px 0 0">
                    <h6 class="modal-title text-white fw-700 mb-0" id="vTitle">تفاصيل المادة</h6>
                    <div class="d-flex gap-2 align-items-center">
                        <button class="btn btn-sm" id="vEditBtn"
                            style="border-radius:8px;background:rgba(255,255,255,.15);color:#fff;font-size:.76rem;border:1px solid rgba(255,255,255,.3)">
                            <i class="bi bi-pencil me-1"></i>تعديل
                        </button>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                </div>
                <div class="modal-body px-4 py-3" id="vBody">
                    <div class="text-center py-4"><span class="spinner-border text-primary"></span></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= BASE_PATH ?>/assets/js/sidebar.js"></script>
    <script>
        const itemModal = new bootstrap.Modal(document.getElementById('itemModal'));
        const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
        const categoryModal = new bootstrap.Modal(document.getElementById('categoryModal'));
        const unitModal = new bootstrap.Modal(document.getElementById('unitModal'));

        const CATS = <?= json_encode($CATS) ?>; // مفاتيحها id الفئة الآن (رقم)، مو نص ثابت

        function post(data) {
            const fd = new FormData();
            Object.entries(data).forEach(([k, v]) => fd.append(k, v ?? ''));
            return fetch(location.href, { method: 'POST', body: fd }).then(r => r.json());
        }
        function toast(msg, type = 'success') {
            const t = document.createElement('div');
            t.className = `alert alert-${type} shadow`;
            t.style.cssText = 'position:fixed;top:70px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:220px;text-align:center;font-size:.83rem;padding:.5rem 1.2rem';
            t.innerHTML = `<i class="bi bi-${type === 'success' ? 'check-circle-fill text-success' : 'exclamation-triangle-fill text-danger'} me-2"></i>${msg}`;
            document.body.appendChild(t);
            setTimeout(() => t.remove(), 3000);
        }

        function openAdd() {
            ['mId', 'mName', 'mEstCost', 'mMinQty', 'mNotes'].forEach(id => document.getElementById(id).value = '');
            document.getElementById('mCategory').value = '';
            document.getElementById('mUnit').value = '';
            document.getElementById('mWarehouse').value = '';
            document.getElementById('mTitle').textContent = 'إضافة مادة استهلاكية';
            itemModal.show();
        }

        function openEdit(id) {
            post({ _action: 'get_item', id }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                const it = d.data;
                document.getElementById('mId').value = it.id;
                document.getElementById('mName').value = it.name;
                document.getElementById('mCategory').value = it.category_id || '';
                document.getElementById('mUnit').value = it.unit_id || '';
                document.getElementById('mEstCost').value = it.estimated_cost || '';
                document.getElementById('mMinQty').value = it.min_qty || '';
                document.getElementById('mNotes').value = it.notes || '';
                const stk = it.stock || [];
                document.getElementById('mWarehouse').value = stk.length ? stk[0].warehouse_id : '';
                document.getElementById('mTitle').textContent = 'تعديل: ' + it.name;
                viewModal.hide();
                itemModal.show();
            });
        }

        function saveItem() {
            const name = document.getElementById('mName').value.trim();
            const category_id = document.getElementById('mCategory').value;
            const unit_id = document.getElementById('mUnit').value;
            if (!name) { toast('اسم المادة مطلوب', 'danger'); return; }
            if (!category_id) { toast('يجب اختيار الفئة', 'danger'); return; }
            if (!unit_id) { toast('يجب اختيار وحدة القياس', 'danger'); return; }
            document.getElementById('saveTxt').style.opacity = '0';
            document.getElementById('saveSpin').style.display = 'inline-block';
            post({
                _action: 'save_item',
                id: document.getElementById('mId').value,
                name,
                category_id,
                unit_id,
                estimated_cost: document.getElementById('mEstCost').value,
                min_quantity: document.getElementById('mMinQty').value,
                warehouse_id: document.getElementById('mWarehouse').value,
                notes: document.getElementById('mNotes').value,
            }).then(d => {
                document.getElementById('saveTxt').style.opacity = '1';
                document.getElementById('saveSpin').style.display = 'none';
                if (d.ok) { toast('تم الحفظ بنجاح'); itemModal.hide(); setTimeout(() => location.reload(), 700); }
                else toast(d.msg, 'danger');
            });
        }

        // ── تحديث قائمة (فئة/وحدة/مستودع) بدون إعادة تحميل الصفحة كاملة ──
        // مفيد لو ضاف حدا فئة/وحدة/مستودع من تبويبة تانية بنفس الوقت
        function refreshLookup(type, selectId) {
            post({ _action: 'get_lookups' }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                const list = d[type] || [];
                const sel = document.getElementById(selectId);
                const current = sel.value;
                const placeholder = sel.querySelector('option[value=""]');
                sel.innerHTML = '';
                if (placeholder) sel.appendChild(placeholder);
                list.forEach(item => {
                    const opt = document.createElement('option');
                    opt.value = item.id;
                    opt.textContent = item.name;
                    sel.appendChild(opt);
                });
                // نحافظ على الاختيار الحالي لو لسا موجود بالقائمة المحدّثة
                if (list.some(item => String(item.id) === String(current))) sel.value = current;
                toast('تم التحديث');
            });
        }

        // ── وحدة قياس جديدة (مودال سريع فوق مودال المادة) ──
        function openUnitModal() {
            document.getElementById('uName').value = '';
            unitModal.show();
        }

        function saveUnit() {
            const name = document.getElementById('uName').value.trim();
            if (!name) { toast('اسم الوحدة مطلوب', 'danger'); return; }
            post({ _action: 'add_unit', name }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                const sel = document.getElementById('mUnit');
                const opt = document.createElement('option');
                opt.value = d.unit.id;
                opt.textContent = d.unit.name;
                opt.selected = true;
                sel.appendChild(opt);
                unitModal.hide();
                toast('تمت إضافة الوحدة');
            });
        }

        // ── فئة جديدة (مودال سريع فوق مودال المادة، بدون إغلاقها) ──
        function openCategoryModal() {
            document.getElementById('cName').value = '';
            document.getElementById('cIcon').value = 'bi-tag';
            categoryModal.show();
        }

        function saveCategory() {
            const name = document.getElementById('cName').value.trim();
            if (!name) { toast('اسم الفئة مطلوب', 'danger'); return; }
            post({
                _action: 'add_category',
                name,
                icon: document.getElementById('cIcon').value.trim() || 'bi-tag',
            }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                const sel = document.getElementById('mCategory');
                const opt = document.createElement('option');
                opt.value = d.category.id;
                opt.textContent = d.category.name;
                opt.selected = true;
                sel.appendChild(opt);
                categoryModal.hide();
                toast('تمت إضافة الفئة');
            });
        }

        function viewItem(id) {
            document.getElementById('vTitle').textContent = 'جارٍ التحميل...';
            document.getElementById('vBody').innerHTML = '<div class="text-center py-4"><span class="spinner-border text-primary"></span></div>';
            document.getElementById('vEditBtn').onclick = () => openEdit(id);
            viewModal.show();
            post({ _action: 'get_item', id }).then(d => {
                if (!d.ok) { document.getElementById('vBody').innerHTML = `<div class="text-danger p-3">${d.msg}</div>`; return; }
                const it = d.data;
                const unitLabel = it.unit_name || '—';
                const cat = CATS[it.category_id] || { label: 'غير مصنّف', clr: '#64748b', bg: '#f1f5f9' };
                const qty = parseFloat(it.total_qty || 0);
                const minQ = parseFloat(it.min_qty || 0);
                const qClr = qty <= 0 ? '#dc2626' : (minQ > 0 && qty <= minQ ? '#d97706' : '#16a34a');
                document.getElementById('vTitle').textContent = it.name;

                // صف لكل مستودع: حد التنبيه قابل للتعديل مباشرة (نمط معياري —
                // نقطة إعادة الطلب مربوطة بالمستودع، مش بالمادة نفسها)
                const stkHtml = (it.stock || []).map(s => `
            <div class="srow" data-wh="${s.warehouse_id}">
                <span style="color:#64748b">${s.wh_name}</span>
                <span class="n fw-600 d-flex align-items-center gap-1" style="color:${parseFloat(s.quantity) <= 0 ? '#dc2626' : '#1e293b'}">
                    ${parseFloat(s.quantity).toFixed(2)} ${unitLabel}
                    <input type="number" min="0" step="0.001" class="form-control form-control-sm wh-min-input"
                        style="width:70px;font-size:.72rem;padding:2px 6px" value="${parseFloat(s.min_quantity || 0)}"
                        title="حد التنبيه بهذا المستودع">
                    <button class="btn btn-sm btn-outline-primary py-0 px-1" style="font-size:.68rem"
                        onclick="saveItemWarehouse(${it.id}, ${s.warehouse_id}, this)"><i class="bi bi-check-lg"></i></button>
                </span>
            </div>`).join('') || '<div class="text-muted text-center py-2" style="font-size:.78rem">لا يوجد رصيد</div>';

                // إضافة المادة لمستودع جديد لسا مش مسجلة فيه
                const avail = it.available_warehouses || [];
                const addWhHtml = avail.length ? `
        <div class="d-flex gap-1 mt-2">
            <select id="vNewWh" class="form-select form-select-sm" style="font-size:.75rem">
                ${avail.map(w => `<option value="${w.id}">${w.name}</option>`).join('')}
            </select>
            <input type="number" id="vNewWhMin" min="0" step="0.001" placeholder="حد التنبيه"
                class="form-control form-control-sm" style="width:90px;font-size:.75rem">
            <button class="btn btn-sm btn-outline-primary" style="font-size:.72rem;white-space:nowrap"
                onclick="addItemWarehouse(${it.id})"><i class="bi bi-plus-lg"></i> إضافة مستودع</button>
        </div>` : '';

                // ⚠ عبوات الشراء الخاصة بهذه المادة تحديداً (كرتونة = كذا
                // وحدة مخزون لهذه المادة بالذات، مش رقم عالمي مشترك مع
                // مواد تانية) — تُستخدم لاحقاً بفواتير الشراء لتحويل
                // الكمية تلقائياً لوحدة المخزون وقت التأكيد.
                const pkgs = it.packagings || [];
                const pkgHtml = pkgs.map(p => `
            <div class="srow">
                <span style="color:#64748b">${p.name}</span>
                <span class="n d-flex align-items-center gap-2">
                    <span style="font-size:.72rem;color:#94a3b8">= ${parseFloat(p.qty_per_package).toFixed(3)} ${unitLabel}</span>
                    <button class="btn btn-sm btn-outline-danger py-0 px-1" style="font-size:.68rem"
                        onclick="deletePackaging(${p.id}, ${it.id})"><i class="bi bi-x-lg"></i></button>
                </span>
            </div>`).join('') || '<div class="text-muted text-center py-2" style="font-size:.74rem">لا توجد عبوات مُعرَّفة لهذه المادة</div>';

                document.getElementById('vBody').innerHTML = `
        <div class="d-flex align-items-center gap-2 mb-3 mt-1">
            <span class="cat-badge" style="color:${cat.clr};background:${cat.bg};border-color:${cat.clr}55;font-size:.72rem;padding:3px 10px;border-radius:20px;font-weight:600;border:1px solid">${cat.label}</span>
            <span style="font-size:.78rem;color:#64748b">وحدة المخزون: ${unitLabel}</span>
            <span class="badge ms-auto ${it.is_active == '1' ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary'}" style="font-size:.68rem">${it.is_active == '1' ? 'نشط' : 'معطّل'}</span>
        </div>
        <div style="background:#f8fafc;border-radius:10px;padding:10px 14px;margin-bottom:10px">
            <div class="srow"><span style="color:#64748b">الرصيد الكلي</span><span class="n fw-700" style="color:${qClr}">${qty.toFixed(2)} ${unitLabel}</span></div>
            <div class="srow"><span style="color:#64748b">متوسط التكلفة</span><span class="n">${parseFloat(it.avg_cost || 0) > 0 ? parseFloat(it.avg_cost).toFixed(4) + ' $' : '—'}</span></div>
            <div class="srow"><span style="color:#64748b">التكلفة التقديرية</span><span class="n">${parseFloat(it.estimated_cost || 0) > 0 ? parseFloat(it.estimated_cost).toFixed(4) + ' ' + (it.currency_code || '$') : '—'}</span></div>
        </div>
        <div style="font-size:.75rem;font-weight:700;color:#1e293b;margin-bottom:5px">الأرصدة بالمستودعات — حد التنبيه قابل للتعديل لكل مستودع</div>
        <div>${stkHtml}</div>
        ${addWhHtml}
        <div style="font-size:.75rem;font-weight:700;color:#1e293b;margin:14px 0 5px">عبوات الشراء الخاصة بهذه المادة</div>
        <div>${pkgHtml}</div>
        <div class="d-flex gap-1 mt-2">
            <input type="text" id="vPkgName" placeholder="اسم العبوة (كرتونة...)" class="form-control form-control-sm" style="font-size:.75rem">
            <input type="number" id="vPkgQty" min="0.0001" step="0.0001" placeholder="= كم ${unitLabel}؟" class="form-control form-control-sm" style="width:110px;font-size:.75rem">
            <button class="btn btn-sm btn-outline-primary" style="font-size:.72rem;white-space:nowrap"
                onclick="addPackaging(${it.id})"><i class="bi bi-plus-lg"></i> إضافة عبوة</button>
        </div>
        ${it.notes ? `<div style="background:#f8fafc;border-radius:8px;padding:8px 12px;margin-top:10px;font-size:.78rem;color:#64748b">${it.notes}</div>` : ''}`;
            });
        }

        // ── إدارة عبوات المادة الخاصة (كرتونة/دستة...) ──
        function addPackaging(itemId) {
            const name = document.getElementById('vPkgName').value.trim();
            const qty = document.getElementById('vPkgQty').value;
            if (!name) { toast('اسم العبوة مطلوب', 'danger'); return; }
            if (!qty || parseFloat(qty) <= 0) { toast('كمية العبوة يجب أن تكون أكبر من صفر', 'danger'); return; }
            post({ _action: 'save_packaging', item_id: itemId, name, qty_per_package: qty }).then(d => {
                if (d.ok) { toast('تمت إضافة العبوة'); viewItem(itemId); }
                else toast(d.msg, 'danger');
            });
        }

        function deletePackaging(pkgId, itemId) {
            if (!confirm('حذف هذه العبوة؟')) return;
            post({ _action: 'delete_packaging', id: pkgId }).then(d => {
                if (d.ok) { toast('تم الحذف'); viewItem(itemId); }
                else toast(d.msg, 'danger');
            });
        }

        // ── تعديل حد التنبيه لمستودع موجود مسبقاً ──
        function saveItemWarehouse(itemId, whId, btnEl) {
            const row = btnEl.closest('.srow');
            const minQty = row.querySelector('.wh-min-input').value || 0;
            post({ _action: 'save_item_warehouse', item_id: itemId, warehouse_id: whId, min_quantity: minQty })
                .then(d => {
                    if (d.ok) toast('تم تحديث حد التنبيه');
                    else toast(d.msg, 'danger');
                });
        }

        // ── إضافة المادة لمستودع جديد (رصيد يبدأ من صفر، بيتحدث لاحقاً عبر فواتير الشراء) ──
        function addItemWarehouse(itemId) {
            const whId = document.getElementById('vNewWh').value;
            const minQty = document.getElementById('vNewWhMin').value || 0;
            if (!whId) { toast('اختر مستودعاً', 'danger'); return; }
            post({ _action: 'save_item_warehouse', item_id: itemId, warehouse_id: whId, min_quantity: minQty })
                .then(d => {
                    if (d.ok) { toast('تمت إضافة المستودع'); viewItem(itemId); }
                    else toast(d.msg, 'danger');
                });
        }

        function toggleItem(id) {
            post({ _action: 'toggle_item', id }).then(d => {
                if (d.ok) { toast(d.is_active ? 'تم التفعيل' : 'تم التعطيل'); setTimeout(() => location.reload(), 600); }
                else toast(d.msg, 'danger');
            });
        }

        function deleteItem(id, name) {
            if (!confirm(`حذف "${name}" نهائياً؟\nلا يمكن الحذف إذا كانت للمادة حركات مخزون.`)) return;
            post({ _action: 'delete_item', id }).then(d => {
                if (d.ok) { toast('تم الحذف'); setTimeout(() => location.reload(), 600); }
                else toast(d.msg, 'danger');
            });
        }
    
        // ══════════════════════════════════════════════════════════
        // فرز الجداول بالنقر على رأس العمود — عام لأي جدول بالصفحة
        // ══════════════════════════════════════════════════════════
        function makeSortable(table) {
            if (!table) return;
            const headers = table.querySelectorAll('thead th');
            headers.forEach((th, colIndex) => {
                if (th.hasAttribute('data-no-sort')) return;
                th.style.cursor = 'pointer';
                th.style.userSelect = 'none';
                th.title = 'اضغط للفرز';
                th.addEventListener('click', () => sortTableByColumn(table, colIndex, th));
            });
        }

        function sortTableByColumn(table, colIndex, th) {
            const tbody = table.querySelector('tbody');
            if (!tbody) return;
            const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.children.length > colIndex);
            const isAsc = th.getAttribute('data-sort-dir') !== 'asc';

            table.querySelectorAll('thead th').forEach(h => {
                h.removeAttribute('data-sort-dir');
                const ind = h.querySelector('.sort-ind');
                if (ind) ind.remove();
            });
            th.setAttribute('data-sort-dir', isAsc ? 'asc' : 'desc');

            const getCellValue = (row) => (row.children[colIndex]?.innerText || '').trim();

            // ⚠ ترتيب فحص القيمة مهم: تاريخ أولاً (YYYY-MM-DD) — لأنه
            // parseFloat على "2026-07-21" كان بيرجّع 2026 بس (بيوقف
            // عند أول شرطة)، فكل تواريخ نفس السنة كانت تطلع "متساوية"
            // ومفيش فرز فعلي بينهم. بعدين رقم صرف (تحقق مطابقة كاملة
            // للنص، مش بس بادئة)، وأخيراً نص عربي عادي.
            const isoDateRe = /^\d{4}-\d{2}-\d{2}/;
            rows.sort((a, b) => {
                const valA = getCellValue(a), valB = getCellValue(b);
                if (isoDateRe.test(valA) && isoDateRe.test(valB)) {
                    const dA = new Date(valA.slice(0, 10)).getTime();
                    const dB = new Date(valB.slice(0, 10)).getTime();
                    return isAsc ? dA - dB : dB - dA;
                }
                const cleanA = valA.replace(/[^0-9.\-]/g, '');
                const cleanB = valB.replace(/[^0-9.\-]/g, '');
                const fullyNumeric = /^-?[0-9]+(\.[0-9]+)?$/.test(cleanA) && /^-?[0-9]+(\.[0-9]+)?$/.test(cleanB)
                    && cleanA !== '' && cleanB !== '' && cleanA !== '-' && cleanB !== '-';
                if (fullyNumeric) {
                    return isAsc ? parseFloat(cleanA) - parseFloat(cleanB) : parseFloat(cleanB) - parseFloat(cleanA);
                }
                return isAsc ? valA.localeCompare(valB, 'ar') : valB.localeCompare(valA, 'ar');
            });

            rows.forEach(row => tbody.appendChild(row));

            const ind = document.createElement('i');
            ind.className = 'bi bi-caret-' + (isAsc ? 'up' : 'down') + '-fill sort-ind';
            ind.style.cssText = 'font-size:.65rem;margin-right:4px';
            th.appendChild(ind);
        }

        makeSortable(document.getElementById('itemsTbl'));
    </script>
</body>

</html>