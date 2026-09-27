<?php
/**
 * consumable_transfers.php — تحويل مستهلكات بين مستودعين ضمن نفس الفرع
 * المسار: retail1/modules/expenses_and_consumables/consumable_transfers.php
 *
 * ⚠ لا يوجد أي أثر محاسبي لهالعملية عمداً — القيمة الإجمالية لمخزون
 * المستهلكات (حساب 1.1.6 بشجرة الحسابات) ما بتتغيّر، بس توزيعها
 * الفيزيائي بين المستودعات هو يلي بيتغيّر.
 */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('expenses.consumable_transfers', 'view');
$currentModule = 'expenses.consumable_transfers';

$branchName = $_SESSION['branch_name'] ?? 'الفرع';
$TS  = $_SESSION['table_suffix'];
$TI  = "consumable_items_{$TS}";
$TU  = "consumable_units_{$TS}";
$TC  = "consumable_categories_{$TS}";
$TST = "consumable_stock_{$TS}";
$TM  = "consumable_movements_{$TS}";
$TW  = "warehouses_{$TS}";
$TPK = "consumable_item_packagings_{$TS}";
$TT  = "consumable_transfers_{$TS}";
$TTI = "consumable_transfer_items_{$TS}";

function genTransferNo(PDO $pdo, string $table): string
{
    $y = date('Y');
    $last = $pdo->query("SELECT transfer_no FROM `{$table}`
        WHERE transfer_no LIKE 'TRF-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $seq = $last ? (int) substr($last, -4) + 1 : 1;
    return "TRF-{$y}-" . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        // ── جلب رصيد المواد بمستودع محدد (المصدر) — الكمية الفعلية
        // بهالمستودع تحديداً، مش إجمالي كل المستودعات ──
        if ($act === 'get_warehouse_stock') {
            $whId = (int) $_POST['warehouse_id'];
            $rows = $pdo->prepare("SELECT ci.id, ci.name, cun.name AS unit,
                    COALESCE(cs.quantity, 0) AS stock
                FROM `{$TI}` ci
                LEFT JOIN `{$TST}` cs ON cs.item_id = ci.id AND cs.warehouse_id = ?
                LEFT JOIN `{$TU}` cun ON cun.id = ci.unit_id
                WHERE ci.is_active = 1 AND COALESCE(cs.quantity, 0) > 0.0001
                ORDER BY ci.name");
            $rows->execute([$whId]);
            $items = $rows->fetchAll();

            // ⚠ عبوات كل مادة (كرتونة/ماعون...) — نفس نمط الشراء/الصرف،
            // حتى يقدر المستخدم يدخل الكمية بوحدة العبوة بدل ما يحسبها
            // يدوياً بوحدة المخزون الأساسية بس
            $pkgRows = $pdo->query("SELECT id, item_id, name, qty_per_package FROM `{$TPK}` WHERE is_active=1")->fetchAll();
            $pkgByItem = [];
            foreach ($pkgRows as $pk) {
                $pkgByItem[$pk['item_id']][] = $pk;
            }
            foreach ($items as &$it) {
                $it['packagings'] = $pkgByItem[$it['id']] ?? [];
            }
            unset($it);

            echo json_encode(['ok' => true, 'items' => $items]);
        }

        // ── حفظ كمسودة (بلا أثر فعلي على المخزون بعد — التنفيذ الفعلي وقت التأكيد) ──
        elseif ($act === 'save_transfer') {
            requirePermission('expenses.consumable_transfers', 'create');
            $fromWh = (int) ($_POST['from_warehouse_id'] ?? 0);
            $toWh   = (int) ($_POST['to_warehouse_id'] ?? 0);
            $date   = $_POST['transfer_date'] ?? date('Y-m-d');
            $notes  = trim($_POST['notes'] ?? '');
            $rows   = json_decode($_POST['rows'] ?? '[]', true);

            if (!$fromWh || !$toWh)
                throw new Exception('يجب اختيار المستودع المصدر والمستودع الهدف');
            if ($fromWh === $toWh)
                throw new Exception('لا يمكن التحويل من وإلى نفس المستودع');
            if (empty($rows))
                throw new Exception('يجب إضافة مادة واحدة على الأقل');

            // ⚠ التأكد إنه المستودعين فعلاً من نوع "مستهلكات" ومفعّلين
            $whCheck = $pdo->prepare("SELECT id FROM `{$TW}` WHERE id IN (?,?) AND warehouse_type='consumables' AND is_active=1");
            $whCheck->execute([$fromWh, $toWh]);
            if (count($whCheck->fetchAll()) !== 2)
                throw new Exception('أحد المستودعين غير صالح أو غير مفعّل');

            // ⚠ حفظ كمسودة فقط — بدون أي أثر فعلي على المخزون. النقل
            // الحقيقي (خصم/إضافة + الحركات) ما بيصير إلا وقت التأكيد
            // (نفس مبدأ فاتورة شراء المستهلكات بالضبط)
            $pdo->beginTransaction();
            try {
                $trfNo = genTransferNo($pdo, $TT);
                $pdo->prepare("INSERT INTO `{$TT}` (transfer_no, from_warehouse_id, to_warehouse_id, transfer_date, status, notes, created_by)
                    VALUES (?,?,?,?,'draft',?,?)")
                    ->execute([$trfNo, $fromWh, $toWh, $date, $notes, $_SESSION['user_id']]);
                $transferId = (int) $pdo->lastInsertId();

                foreach ($rows as $r) {
                    $itemId = (int) $r['item_id'];
                    $enteredQty = (float) $r['qty'];
                    if (!$itemId || $enteredQty <= 0)
                        continue;
                    $packagingId = (int) ($r['packaging_id'] ?? 0) ?: null;

                    $factor = 1.0;
                    if ($packagingId) {
                        $pkSt = $pdo->prepare("SELECT qty_per_package FROM `{$TPK}` WHERE id=? AND item_id=? AND is_active=1");
                        $pkSt->execute([$packagingId, $itemId]);
                        $factor = (float) ($pkSt->fetchColumn() ?: 0);
                        if ($factor <= 0)
                            throw new Exception('عبوة غير صالحة لهذه المادة');
                    }
                    $qty = $enteredQty * $factor;

                    // ⚠ فحص مبدئي بس وقت الحفظ (تجربة مستخدم أفضل) — الفحص
                    // الحاسم الفعلي (يمنع رصيد سالب فعلياً) بيصير وقت
                    // التأكيد، لأنه الرصيد ممكن يتغيّر بين الحفظ والتأكيد
                    $stSt = $pdo->prepare("SELECT quantity, avg_cost_base FROM `{$TST}` WHERE item_id=? AND warehouse_id=?");
                    $stSt->execute([$itemId, $fromWh]);
                    $srcStock = $stSt->fetch();
                    $available = $srcStock ? (float) $srcStock['quantity'] : 0;
                    if ($qty > $available + 0.0001) {
                        $itemName = $pdo->prepare("SELECT name FROM `{$TI}` WHERE id=?");
                        $itemName->execute([$itemId]);
                        throw new Exception('الكمية المطلوب تحويلها لمادة "' . $itemName->fetchColumn() . '" أكبر من المتاح بالمستودع المصدر (' . number_format($available, 3) . ')');
                    }

                    $pdo->prepare("INSERT INTO `{$TTI}` (transfer_id, item_id, packaging_id, packaging_qty, quantity, unit_cost_base, notes)
                        VALUES (?,?,?,?,?,?,?)")
                        ->execute([
                            $transferId, $itemId, $packagingId,
                            $packagingId ? $enteredQty : null, // للعرض/التدقيق بس — الكمية الأصلية كما أُدخلت بوحدة العبوة
                            $qty, $srcStock ? (float) $srcStock['avg_cost_base'] : 0, $r['notes'] ?? ''
                        ]);
                }

                $pdo->commit();
                echo json_encode(['ok' => true, 'id' => $transferId, 'no' => $trfNo, 'msg' => 'تم حفظ المناقلة كمسودة']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        // ── تأكيد المناقلة — هون فقط يصير النقل الفعلي بالمخزون ──
        elseif ($act === 'confirm_transfer') {
            requirePermission('expenses.consumable_transfers', 'edit');
            $transferId = (int) ($_POST['id'] ?? 0);
            $tSt = $pdo->prepare("SELECT * FROM `{$TT}` WHERE id=?");
            $tSt->execute([$transferId]);
            $trf = $tSt->fetch();
            if (!$trf)
                throw new Exception('المناقلة غير موجودة');
            if ($trf['status'] !== 'draft')
                throw new Exception('لا يمكن تأكيد مناقلة غير مسودة');

            $fromWh = (int) $trf['from_warehouse_id'];
            $toWh = (int) $trf['to_warehouse_id'];
            $date = $trf['transfer_date'];

            $itemsSt = $pdo->prepare("SELECT * FROM `{$TTI}` WHERE transfer_id=?");
            $itemsSt->execute([$transferId]);
            $items = $itemsSt->fetchAll();
            if (empty($items))
                throw new Exception('لا توجد مواد بهذه المناقلة');

            $pdo->beginTransaction();
            try {
                foreach ($items as $it) {
                    $itemId = (int) $it['item_id'];
                    $qty = (float) $it['quantity'];

                    // ⚠ الفحص الحاسم الفعلي — الرصيد ممكن يكون تغيّر منذ
                    // حفظ المسودة (صرف/تحويل تاني صار بينهم)، فلازم يتأكد
                    // من جديد هون مباشرة قبل أي تغيير فعلي، بمنع رصيد سالب
                    $stSt = $pdo->prepare("SELECT quantity, avg_cost_base FROM `{$TST}` WHERE item_id=? AND warehouse_id=? FOR UPDATE");
                    $stSt->execute([$itemId, $fromWh]);
                    $srcStock = $stSt->fetch();
                    $available = $srcStock ? (float) $srcStock['quantity'] : 0;
                    if ($qty > $available + 0.0001) {
                        $itemName = $pdo->prepare("SELECT name FROM `{$TI}` WHERE id=?");
                        $itemName->execute([$itemId]);
                        throw new Exception('الرصيد تغيّر منذ حفظ المسودة — الكمية المطلوبة لمادة "' . $itemName->fetchColumn() . '" أكبر من المتاح حالياً (' . number_format($available, 3) . ')');
                    }
                    $unitCostBase = (float) $srcStock['avg_cost_base'];

                    $pdo->prepare("UPDATE `{$TST}` SET quantity = quantity - ?, last_movement = NOW() WHERE item_id=? AND warehouse_id=?")
                        ->execute([$qty, $itemId, $fromWh]);

                    $dstSt = $pdo->prepare("SELECT quantity, avg_cost_base FROM `{$TST}` WHERE item_id=? AND warehouse_id=?");
                    $dstSt->execute([$itemId, $toWh]);
                    $dst = $dstSt->fetch();
                    if ($dst) {
                        $oldQty = (float) $dst['quantity'];
                        $newQty = $oldQty + $qty;
                        $newAvg = $newQty > 0 ? (($oldQty * (float) $dst['avg_cost_base']) + ($qty * $unitCostBase)) / $newQty : $unitCostBase;
                        $pdo->prepare("UPDATE `{$TST}` SET quantity=?, avg_cost_base=?, last_movement=NOW() WHERE item_id=? AND warehouse_id=?")
                            ->execute([$newQty, $newAvg, $itemId, $toWh]);
                    } else {
                        $pdo->prepare("INSERT INTO `{$TST}` (item_id, warehouse_id, quantity, avg_cost_base, last_movement)
                            VALUES (?,?,?,?,NOW())")
                            ->execute([$itemId, $toWh, $qty, $unitCostBase]);
                    }

                    $totalCostBase = $qty * $unitCostBase;

                    $movNoOut = 'MOV-' . date('Y') . '-' . str_pad((int) $pdo->query("SELECT COUNT(*)+1 FROM `{$TM}`")->fetchColumn(), 5, '0', STR_PAD_LEFT);
                    $pdo->prepare("INSERT INTO `{$TM}`
                        (movement_no, item_id, warehouse_id, movement_type, direction,
                         quantity, unit_cost_base, total_cost_base, qty_before, qty_after,
                         reference_type, reference_id, to_warehouse_id, movement_date, is_posted, created_by)
                        VALUES (?,?,?,'transfer','out',?,?,?,?,?,'transfer',?,?,?,1,?)")
                        ->execute([$movNoOut, $itemId, $fromWh, $qty, $unitCostBase, $totalCostBase, $available, $available - $qty, $transferId, $toWh, $date, $_SESSION['user_id']]);
                    $movOutId = (int) $pdo->lastInsertId();

                    $movNoIn = 'MOV-' . date('Y') . '-' . str_pad((int) $pdo->query("SELECT COUNT(*)+1 FROM `{$TM}`")->fetchColumn(), 5, '0', STR_PAD_LEFT);
                    $oldDstQty = $dst ? (float) $dst['quantity'] : 0;
                    $pdo->prepare("INSERT INTO `{$TM}`
                        (movement_no, item_id, warehouse_id, movement_type, direction,
                         quantity, unit_cost_base, total_cost_base, qty_before, qty_after,
                         reference_type, reference_id, movement_date, is_posted, created_by)
                        VALUES (?,?,?,'transfer','in',?,?,?,?,?,'transfer',?,?,1,?)")
                        ->execute([$movNoIn, $itemId, $toWh, $qty, $unitCostBase, $totalCostBase, $oldDstQty, $oldDstQty + $qty, $transferId, $date, $_SESSION['user_id']]);
                    $movInId = (int) $pdo->lastInsertId();

                    $pdo->prepare("UPDATE `{$TTI}` SET unit_cost_base=?, movement_out_id=?, movement_in_id=? WHERE id=?")
                        ->execute([$unitCostBase, $movOutId, $movInId, $it['id']]);
                }

                $pdo->prepare("UPDATE `{$TT}` SET status='confirmed' WHERE id=?")->execute([$transferId]);

                $pdo->commit();
                echo json_encode(['ok' => true, 'msg' => 'تم تأكيد المناقلة — تم النقل الفعلي بالمخزون']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        elseif ($act === 'get_transfer') {
            $id = (int) $_POST['id'];
            $tSt = $pdo->prepare("SELECT t.*, wf.name AS from_wh_name, wt.name AS to_wh_name
                FROM `{$TT}` t
                LEFT JOIN `{$TW}` wf ON wf.id = t.from_warehouse_id
                LEFT JOIN `{$TW}` wt ON wt.id = t.to_warehouse_id
                WHERE t.id=?");
            $tSt->execute([$id]);
            $trf = $tSt->fetch();
            if (!$trf)
                throw new Exception('غير موجود');
            $items = $pdo->prepare("SELECT ti.*, ci.name AS item_name, cun.name AS unit,
                    pk.name AS packaging_name, pk.qty_per_package
                FROM `{$TTI}` ti JOIN `{$TI}` ci ON ci.id = ti.item_id
                LEFT JOIN `{$TU}` cun ON cun.id = ci.unit_id
                LEFT JOIN `{$TPK}` pk ON pk.id = ti.packaging_id
                WHERE ti.transfer_id=?");
            $items->execute([$id]);
            $trf['items'] = $items->fetchAll();
            echo json_encode(['ok' => true, 'data' => $trf]);
        } else
            throw new Exception('إجراء غير معروف');
    } catch (Throwable $e) {
        error_log('[consumable_transfers] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── بيانات الصفحة ──
$warehouses = $pdo->query("SELECT * FROM `{$TW}` WHERE is_active=1 AND warehouse_type='consumables' ORDER BY id")->fetchAll();

$transfers = $pdo->query("SELECT t.*, wf.name AS from_wh_name, wt.name AS to_wh_name,
        COUNT(ti.id) AS items_count, COALESCE(SUM(ti.quantity * ti.unit_cost_base),0) AS total_cost
    FROM `{$TT}` t
    LEFT JOIN `{$TW}` wf ON wf.id = t.from_warehouse_id
    LEFT JOIN `{$TW}` wt ON wt.id = t.to_warehouse_id
    LEFT JOIN `{$TTI}` ti ON ti.transfer_id = t.id
    GROUP BY t.id ORDER BY t.created_at DESC LIMIT 200")->fetchAll();

$baseCurSt = $pdo->prepare("SELECT c.symbol FROM branches b LEFT JOIN currencies c ON c.id = b.base_currency_id WHERE b.id = ?");
$baseCurSt->execute([$_SESSION['branch_id'] ?? 0]);
$baseCurSymbol = $baseCurSt->fetchColumn() ?: '$';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مناقلة بين المستودعات — FATORIZE</title>
    <link rel="icon" type="image/png" href="../../assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../../assets/css/layout.css" rel="stylesheet">
    <style>
        .transfer-line {
            background: #f8fafc;
            border: 1px solid #eef1f5;
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 8px
        }

        .transfer-line-top {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px
        }

        .transfer-line-top select.item-sel {
            flex: 1;
            min-width: 0;
            font-size: .88rem;
            font-weight: 600
        }

        .transfer-line-fields {
            display: flex;
            flex-wrap: wrap;
            gap: 10px
        }

        .tsf {
            display: flex;
            flex-direction: column;
            gap: 3px
        }

        .tsf label {
            font-size: .68rem;
            font-weight: 700;
            color: #64748b;
            white-space: nowrap
        }

        .transfer-line input,
        .transfer-line select {
            font-size: .82rem;
            line-height: 1.4;
            padding: 6px 8px;
            border: 1px solid #dde3ea;
            border-radius: 7px;
            width: 100%;
            background: #fff;
            height: 38px;
            box-sizing: border-box
        }

        .transfer-line input.stock {
            background: #f0fdf4;
            color: #16a34a;
            border-color: #bbf7d0;
            font-size: .74rem;
            font-weight: 600
        }

        .del-btn {
            width: 24px;
            height: 24px;
            border-radius: 6px;
            border: 1px solid #fca5a5;
            background: #fff;
            color: #dc2626;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .75rem
        }

        .act-btn {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: .85rem;
            color: #64748b
        }

        .act-btn.danger {
            color: #dc2626;
            border-color: #fca5a5
        }

        .field-lbl {
            font-size: .78rem;
            font-weight: 700;
            color: #334155;
            margin-bottom: 4px;
            display: block
        }

        .req {
            color: #dc2626
        }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
    <?php require_once __DIR__ . '/../../../includes/breadcrumb.php'; ?>

    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-signpost-split me-1 text-primary"></i>مناقلة بين المستودعات</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <?= renderBreadcrumb() ?>
    </header>

    <main class="main-content">
        <div class="content-body">

            <!-- التبويبات الموحّدة -->
            <ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e2e8f0">
                <li class="nav-item"><a class="nav-link fw-600" href="consumables.php"
                        style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-box-seam me-1"></i>المواد
                        الاستهلاكية</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="consumable_purchases.php"
                        style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-cart-plus me-1"></i>فواتير
                        الشراء</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="consumable_issues.php"
                        style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-arrow-bar-up me-1"></i>صرف
                        المستهلكات</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="../inventory/warehouse.php?type=consumables"
                        style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-building me-1"></i>مستودعات
                        المستهلكات</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="../inventory/movements.php?tab=consumables"
                        style="border:none;color:#64748b;font-size:.83rem"><i
                            class="bi bi-arrow-left-right me-1"></i>حركة المستهلكات</a></li>
                <li class="nav-item"><a class="nav-link fw-600 active" href="#"
                        style="border:none;border-bottom:2px solid #1e3a8a;color:#1e3a8a;font-size:.83rem;margin-bottom:-2px"><i
                            class="bi bi-signpost-split me-1"></i>مناقلة بين المستودعات</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="expenses.php"
                        style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-wallet2 me-1"></i>إدارة
                        المصاريف</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="../purchases/suppliers.php?tab=consumables"
                        style="border:none;color:#64748b;font-size:.83rem"><i
                            class="bi bi-people me-1"></i>موردو المستهلكات</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="consumable_reports.php"
                        style="border:none;color:#64748b;font-size:.83rem"><i
                            class="bi bi-bar-chart me-1"></i>التقارير</a></li>
            </ul>

            <div class="d-flex justify-content-between align-items-center mb-3">
                <span style="font-size:.9rem;font-weight:700;color:#1e293b"><i
                        class="bi bi-list-ul me-1 text-primary"></i>سجل التحويلات</span>
                <button class="btn btn-sm fw-600" style="border-radius:9px;background:#1e3a8a;color:#fff;font-size:.82rem"
                    onclick="openNewTransfer()">
                    <i class="bi bi-plus-lg me-1"></i>تحويل جديد
                </button>
            </div>

            <div class="tbl-wrap">
                <table class="table table-hover align-middle mb-0" id="transfersTbl" style="font-size:.85rem">
                    <thead style="background:#f8fafc">
                        <tr>
                            <th style="color:#1e3a8a">رقم التحويل</th>
                            <th style="color:#16a34a">من مستودع</th>
                            <th style="color:#dc2626">إلى مستودع</th>
                            <th class="text-center">التاريخ</th>
                            <th class="text-center" style="color:#ca8a04">عدد المواد</th>
                            <th class="text-end">القيمة (<?= htmlspecialchars($baseCurSymbol) ?>)</th>
                            <th class="text-center">الحالة</th>
                            <th class="text-center" data-no-sort>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transfers)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">لا توجد تحويلات مسجّلة بعد</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transfers as $t): ?>
                                <tr>
                                    <td class="fw-600" dir="ltr"><span style="color:#1e3a8a"><?= htmlspecialchars($t['transfer_no']) ?></span></td>
                                    <td style="color:#16a34a"><?= htmlspecialchars($t['from_wh_name'] ?? '—') ?></td>
                                    <td style="color:#dc2626"><?= htmlspecialchars($t['to_wh_name'] ?? '—') ?></td>
                                    <td class="text-center"><?= htmlspecialchars($t['transfer_date']) ?></td>
                                    <td class="text-center fw-600" style="color:#ca8a04"><?= (int) $t['items_count'] ?></td>
                                    <td class="text-end n"><?= number_format($t['total_cost'], 2) ?></td>
                                    <td class="text-center">
                                        <?php if (($t['status'] ?? 'draft') === 'confirmed'): ?>
                                            <span class="badge bg-success-subtle text-success"><i
                                                    class="bi bi-check-circle me-1"></i>مؤكّد</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary"><i
                                                    class="bi bi-file-earmark me-1"></i>مسودة</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-flex justify-content-center gap-1">
                                            <button class="act-btn" onclick="viewTransfer(<?= $t['id'] ?>)" title="عرض">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <?php if (($t['status'] ?? 'draft') === 'draft'): ?>
                                                <button class="act-btn success-h" onclick="confirmTransfer(<?= $t['id'] ?>, '<?= htmlspecialchars($t['transfer_no'], ENT_QUOTES) ?>')"
                                                    title="تأكيد — تنفيذ النقل الفعلي">
                                                    <i class="bi bi-check-circle"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- مودال تحويل جديد -->
    <div class="modal fade" id="transferModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl" style="max-width:1000px">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#1e3a8a,#1e40af);border-radius:16px 16px 0 0">
                    <h6 class="modal-title text-white fw-700 mb-0"><i class="bi bi-arrow-left-right me-2"></i>تحويل
                        مستهلكات بين مستودعين</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-3">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="field-lbl">من مستودع <span class="req">*</span></label>
                            <select id="tFromWh" class="form-select form-select-sm">
                                <option value="">— اختر —</option>
                                <?php foreach ($warehouses as $w): ?>
                                    <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">إلى مستودع <span class="req">*</span></label>
                            <select id="tToWh" class="form-select form-select-sm">
                                <option value="">— اختر —</option>
                                <?php foreach ($warehouses as $w): ?>
                                    <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">تاريخ التحويل <span class="req">*</span></label>
                            <input type="date" id="tDate" class="form-control form-control-sm">
                        </div>
                        <div class="col-12">
                            <label class="field-lbl">ملاحظات (اختياري)</label>
                            <input type="text" id="tNotes" class="form-control form-control-sm" placeholder="سبب التحويل">
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span style="font-size:.82rem;font-weight:700;color:#1e293b"><i
                                class="bi bi-list-ul me-1 text-primary"></i>المواد المُحوَّلة</span>
                        <button class="btn btn-sm" style="border-radius:8px;border:1px solid #1e3a8a;color:#1e3a8a;font-size:.76rem"
                            onclick="addTransferLine()" id="btnAddLine" disabled><i class="bi bi-plus me-1"></i>إضافة
                            مادة</button>
                    </div>
                    <div id="transferLines"></div>
                    <div id="transferEmpty" class="text-center text-muted py-3" style="font-size:.8rem">
                        <i class="bi bi-arrow-right-circle d-block mb-1" style="font-size:1.2rem;opacity:.3"></i>
                        اختر "من مستودع" أولاً حتى تظهر المواد المتاحة فيه
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-sm fw-600" id="btnSaveTransfer"
                        style="border-radius:8px;background:#1e3a8a;color:#fff;min-width:130px" onclick="saveTransfer()">
                        <span id="saveTransferTxt"><i class="bi bi-floppy me-1"></i>حفظ كمسودة</span>
                        <span id="saveTransferSpin" class="spinner-border spinner-border-sm" style="display:none"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- مودال عرض تفاصيل التحويل -->
    <div class="modal fade" id="viewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#0c447c,#1e3a8a);border-radius:16px 16px 0 0">
                    <h6 class="modal-title text-white fw-700 mb-0" id="vTitle">تفاصيل التحويل</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-3" id="vBody"></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= BASE_PATH ?>/assets/js/sidebar.js"></script>
    <script>
        function post(data) {
            return fetch(location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(data)
            }).then(r => r.json());
        }
        function toast(msg, type = 'success') {
            const el = document.createElement('div');
            el.className = `alert alert-${type === 'danger' ? 'danger' : 'success'} position-fixed`;
            el.style.cssText = 'top:20px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:10px;font-size:.85rem;box-shadow:0 4px 20px rgba(0,0,0,.15)';
            el.textContent = msg;
            document.body.appendChild(el);
            setTimeout(() => el.remove(), 3000);
        }

        const transferModal = new bootstrap.Modal(document.getElementById('transferModal'));
        const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
        const BASE_CUR_SYM = <?= json_encode($baseCurSymbol) ?>;

        let transferLines = [];
        let availableItems = []; // مواد المستودع المصدر المختار، بأرصدتها الفعلية فيه

        function openNewTransfer() {
            transferLines = [];
            document.getElementById('transferLines').innerHTML = '';
            document.getElementById('transferEmpty').style.display = 'block';
            document.getElementById('transferEmpty').textContent = 'اختر "من مستودع" أولاً حتى تظهر المواد المتاحة فيه';
            document.getElementById('tFromWh').value = '';
            document.getElementById('tToWh').value = '';
            document.getElementById('tDate').value = new Date().toISOString().split('T')[0];
            document.getElementById('tNotes').value = '';
            document.getElementById('btnAddLine').disabled = true;
            availableItems = [];
            transferModal.show();
        }

        // ⚠ لما يتغيّر المستودع المصدر، نجيب أرصدته الفعلية (لا إجمالي
        // كل المستودعات) — هاد الفرق الجوهري عن صفحات الشراء/الصرف
        document.getElementById('tFromWh').addEventListener('change', function () {
            transferLines = [];
            document.getElementById('transferLines').innerHTML = '';
            if (!this.value) {
                document.getElementById('btnAddLine').disabled = true;
                availableItems = [];
                document.getElementById('transferEmpty').style.display = 'block';
                return;
            }
            post({ _action: 'get_warehouse_stock', warehouse_id: this.value }).then(d => {
                availableItems = d.items || [];
                document.getElementById('btnAddLine').disabled = availableItems.length === 0;
                document.getElementById('transferEmpty').style.display = 'block';
                document.getElementById('transferEmpty').textContent = availableItems.length
                    ? 'اضغط "إضافة مادة" لإضافة مادة من رصيد هذا المستودع'
                    : 'لا توجد أي مادة برصيد فعلي بهذا المستودع';
            });
        });
        document.getElementById('tToWh').addEventListener('change', function () {
            const fromEl = document.getElementById('tFromWh');
            if (this.value && this.value === fromEl.value) {
                toast('لا يمكن اختيار نفس المستودع كمصدر وهدف', 'danger');
                this.value = '';
            }
        });

        function addTransferLine() {
            document.getElementById('transferEmpty').style.display = 'none';
            const lineData = { item_id: '', packaging_id: '', qty: 0, notes: '' };
            transferLines.push(lineData);
            buildTransferLineDOM(lineData);
        }

        function buildTransferLineDOM(lineData) {
            const div = document.createElement('div');
            div.className = 'transfer-line';
            div.innerHTML = `
    <div class="transfer-line-top">
      <select class="item-sel">
        <option value="">— اختر المادة —</option>
        ${availableItems.map(it => `<option value="${it.id}" data-unit="${it.unit}" data-stock="${parseFloat(it.stock).toFixed(3)}">${it.name} (${it.unit})</option>`).join('')}
      </select>
      <button class="del-btn" type="button" title="حذف السطر"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="transfer-line-fields">
      <div class="tsf" style="width:200px">
        <label>الوحدة / العبوة</label>
        <select class="unit-sel" disabled>
          <option value="">وحدة المادة</option>
        </select>
      </div>
      <div class="tsf" style="width:110px">
        <label>الكمية</label>
        <input type="number" min="0.001" step="0.001" value="" dir="ltr" class="qty-in">
      </div>
      <div class="tsf" style="width:200px">
        <label>المتاح بالمستودع المصدر</label>
        <input type="text" class="stock" value="—" readonly>
      </div>
      <div class="tsf" style="flex:1;min-width:180px">
        <label>ملاحظات (اختياري)</label>
        <input type="text" class="notes-in" placeholder="اختياري">
      </div>
    </div>`;

            const selEl = div.querySelector('.item-sel');
            const unitSelEl = div.querySelector('.unit-sel');
            const qtyEl = div.querySelector('.qty-in');
            const stockEl = div.querySelector('.stock');
            const notesEl = div.querySelector('.notes-in');
            const delBtn = div.querySelector('.del-btn');

            // ⚠ "المتاح" بيعرض الرصيد بوحدة المخزون الأساسية *و* مكافئه
            // بالعبوة المختارة (لو محددة) — نفس منطق صفحة الصرف
            function updateStockDisplay() {
                const opt = selEl.options[selEl.selectedIndex];
                const stock = parseFloat(opt?.dataset.stock || 0);
                if (!selEl.value) { stockEl.value = '—'; return; }
                let text = stock.toFixed(3) + ' ' + opt.dataset.unit;
                const uOpt = unitSelEl.options[unitSelEl.selectedIndex];
                const factor = parseFloat(uOpt?.dataset.factor) || 1;
                if (factor !== 1 && stock > 0) {
                    text += ` (≈ ${(stock / factor).toFixed(2)} ${uOpt.textContent.split(' (')[0]})`;
                }
                stockEl.value = text;
                // الحد الأقصى للكمية المُدخلة يضل دايماً بوحدة العبوة المختارة
                qtyEl.max = factor > 0 ? (stock / factor) : stock;
            }

            function rebuildUnitOptions() {
                const it = availableItems.find(x => x.id == lineData.item_id);
                if (!it) {
                    unitSelEl.disabled = true;
                    unitSelEl.innerHTML = '<option value="">وحدة المادة</option>';
                    return;
                }
                const pkgs = it.packagings || [];
                unitSelEl.disabled = false;
                unitSelEl.innerHTML = `<option value="" data-factor="1">${it.unit} (وحدة المخزون)</option>` +
                    pkgs.map(pk => `<option value="${pk.id}" data-factor="${pk.qty_per_package}">${pk.name} (= ${parseFloat(pk.qty_per_package).toFixed(2)} ${it.unit})</option>`).join('');
            }

            selEl.addEventListener('change', function () {
                lineData.item_id = this.value;
                lineData.packaging_id = '';
                rebuildUnitOptions();
                updateStockDisplay();
            });
            unitSelEl.addEventListener('change', function () {
                lineData.packaging_id = this.value || '';
                updateStockDisplay();
            });
            qtyEl.addEventListener('input', function () {
                const max = parseFloat(this.max) || 0;
                if (parseFloat(this.value) > max) { this.value = max; toast('الكمية أكبر من المتاح — تم التصحيح للحد الأقصى', 'danger'); }
                lineData.qty = parseFloat(this.value) || 0;
            });
            notesEl.addEventListener('input', function () { lineData.notes = this.value; });
            delBtn.addEventListener('click', function () {
                const idx = transferLines.indexOf(lineData);
                if (idx > -1) transferLines.splice(idx, 1);
                div.remove();
                if (!transferLines.length) document.getElementById('transferEmpty').style.display = 'block';
            });

            document.getElementById('transferLines').appendChild(div);
        }

        function saveTransfer() {
            const fromWh = document.getElementById('tFromWh').value;
            const toWh = document.getElementById('tToWh').value;
            if (!fromWh || !toWh) { toast('يجب اختيار المستودع المصدر والهدف', 'danger'); return; }
            if (fromWh === toWh) { toast('لا يمكن التحويل لنفس المستودع', 'danger'); return; }
            const valid = transferLines.filter(l => l.item_id && l.qty > 0);
            if (!valid.length) { toast('يجب إضافة مادة واحدة على الأقل بكمية صحيحة', 'danger'); return; }

            document.getElementById('saveTransferTxt').style.opacity = '0';
            document.getElementById('saveTransferSpin').style.display = 'inline-block';

            post({
                _action: 'save_transfer',
                from_warehouse_id: fromWh, to_warehouse_id: toWh,
                transfer_date: document.getElementById('tDate').value,
                notes: document.getElementById('tNotes').value,
                rows: JSON.stringify(valid.map(l => ({ item_id: l.item_id, packaging_id: l.packaging_id || '', qty: l.qty, notes: l.notes })))
            }).then(d => {
                document.getElementById('saveTransferTxt').style.opacity = '1';
                document.getElementById('saveTransferSpin').style.display = 'none';
                if (d.ok) {
                    toast('✅ ' + d.msg + ' — ' + d.no);
                    transferModal.hide();
                    setTimeout(() => location.reload(), 800);
                } else toast(d.msg, 'danger');
            });
        }

        // ⚠ هون فقط يصير النقل الفعلي بالمخزون — نفس مبدأ تأكيد فاتورة
        // شراء المستهلكات بالضبط (المسودة ما إلها أي أثر فعلي قبلها)
        function confirmTransfer(id, no) {
            if (!confirm(`تأكيد المناقلة "${no}"؟\nسيتم فعلياً خصم الكمية من المستودع المصدر وإضافتها للمستودع الهدف.`)) return;
            post({ _action: 'confirm_transfer', id }).then(d => {
                if (d.ok) { toast('✅ ' + d.msg); setTimeout(() => location.reload(), 800); }
                else toast(d.msg, 'danger');
            });
        }

        function viewTransfer(id) {
            post({ _action: 'get_transfer', id }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                const t = d.data;
                const itemsHtml = (t.items || []).map(it => {
                    const qtyLabel = it.packaging_name
                        ? `${parseFloat(it.packaging_qty).toFixed(2)} ${it.packaging_name} <small style="color:#94a3b8">(=${parseFloat(it.quantity).toFixed(3)} ${it.unit})</small>`
                        : `${parseFloat(it.quantity).toFixed(3)}`;
                    return `<tr>
            <td>${it.item_name}</td>
            <td class="text-center">${it.unit}</td>
            <td class="n text-center fw-600">${qtyLabel}</td>
            <td class="n text-center" style="color:#94a3b8">${BASE_CUR_SYM} ${parseFloat(it.unit_cost_base).toFixed(4)}</td>
            <td class="n text-end fw-600">${BASE_CUR_SYM} ${(parseFloat(it.quantity) * parseFloat(it.unit_cost_base)).toFixed(2)}</td>
            <td style="font-size:.75rem;color:#64748b">${it.notes || '—'}</td>
        </tr>`;
                }).join('');
                document.getElementById('vTitle').innerHTML = 'تفاصيل التحويل — <span style="color:#1e3a8a">' + t.transfer_no + '</span>';
                document.getElementById('vBody').innerHTML = `
        <div class="row g-2 mb-3">
          <div class="col-6"><small style="color:#64748b">من مستودع</small><div class="fw-600" style="color:#16a34a">${t.from_wh_name || '—'}</div></div>
          <div class="col-6"><small style="color:#64748b">إلى مستودع</small><div class="fw-600" style="color:#dc2626">${t.to_wh_name || '—'}</div></div>
          <div class="col-6"><small style="color:#64748b">التاريخ</small><div>${t.transfer_date}</div></div>
        </div>
        <table class="mtbl mb-3" style="font-size:.78rem;width:100%">
          <thead><tr style="background:#f8fafc">
            <th>المادة</th><th class="text-center">الوحدة</th><th class="text-center">الكمية</th>
            <th class="text-center">تكلفة الوحدة</th><th class="text-end">الإجمالي</th><th>ملاحظات</th>
          </tr></thead>
          <tbody>${itemsHtml}</tbody>
        </table>
        ${t.notes ? `<div style="background:#f8fafc;border-radius:8px;padding:8px 12px;font-size:.78rem;color:#64748b">${t.notes}</div>` : ''}`;
                viewModal.show();
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

        makeSortable(document.getElementById('transfersTbl'));
    </script>
</body>

</html>
