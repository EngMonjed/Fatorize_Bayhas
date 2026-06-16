<?php
/**
 * consumable_purchases.php — فواتير شراء المستهلكات
 * المسار: /bayhas/aleppo/modules/inventory/consumable_purchases.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('inventory.consumables', 'view');

$TS  = $_SESSION['table_suffix'];
$TI  = "consumable_items_alp";
$TST = "consumable_stock_alp";
$TM  = "consumable_movements_alp";
$TP  = "consumable_purchases_alp";
$TPI = "consumable_purchase_items_alp";
$TW  = "warehouses_{$TS}";
$TSP = "product_suppliers_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// ── دالة توليد رقم الفاتورة ──────────────────────────────────────
function generatePurchaseNo(PDO $pdo, string $table): string {
    $year = date('Y');
    $last = $pdo->query("SELECT invoice_no FROM `{$table}` WHERE invoice_no LIKE 'PUR-{$year}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $seq  = $last ? (int)substr($last, -4) + 1 : 1;
    return "PUR-{$year}-" . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

// ── دالة تحديث المخزون ───────────────────────────────────────────
function updateStock(PDO $pdo, string $TST, int $itemId, int $whId, float $qty, float $unitCost): void {
    // جلب الرصيد الحالي
    $cur = $pdo->prepare("SELECT quantity, avg_cost_usd FROM `{$TST}` WHERE item_id=? AND warehouse_id=?");
    $cur->execute([$itemId, $whId]);
    $row = $cur->fetch();
    if ($row) {
        $oldQty  = (float)$row['quantity'];
        $oldCost = (float)$row['avg_cost_usd'];
        // Weighted Average Cost
        $newQty  = $oldQty + $qty;
        $newCost = $newQty > 0 ? (($oldQty * $oldCost) + ($qty * $unitCost)) / $newQty : $unitCost;
        $pdo->prepare("UPDATE `{$TST}` SET quantity=?, avg_cost_usd=?, last_movement=NOW() WHERE item_id=? AND warehouse_id=?")
            ->execute([$newQty, $newCost, $itemId, $whId]);
    } else {
        $pdo->prepare("INSERT INTO `{$TST}` (item_id,warehouse_id,quantity,avg_cost_usd,last_movement) VALUES (?,?,?,?,NOW())")
            ->execute([$itemId, $whId, $qty, $unitCost]);
    }
}

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        // ── جلب فاتورة ──
        if ($act === 'get_purchase') {
            $id = (int)$_POST['id'];
            $st = $pdo->prepare("SELECT p.*, s.name AS supplier_name, w.name AS wh_name,
                p.currency AS currency_code,
                COALESCE(c.symbol,'$') AS currency_symbol
                FROM `{$TP}` p
                LEFT JOIN `{$TSP}` s ON s.id=p.supplier_id
                LEFT JOIN `{$TW}` w  ON w.id=p.warehouse_id
                LEFT JOIN `currencies` c ON c.code=p.currency
                WHERE p.id=?");
            $st->execute([$id]);
            $p = $st->fetch();
            if (!$p) throw new Exception('الفاتورة غير موجودة');
            $items = $pdo->prepare("SELECT pi.*, ci.name AS item_name, ci.unit
                FROM `{$TPI}` pi JOIN `{$TI}` ci ON ci.id=pi.item_id WHERE pi.purchase_id=?");
            $items->execute([$id]);
            $p['items'] = $items->fetchAll();
            echo json_encode(['ok'=>true,'data'=>$p]);
        }

        // ── حفظ فاتورة جديدة ──
        elseif ($act === 'save_purchase') {
            requirePermission('inventory.consumables','create');
            $supplierId  = (int)($_POST['supplier_id'] ?? 0) ?: null;
            $whId        = (int)($_POST['warehouse_id'] ?? 0);
            $currId      = (int)($_POST['currency_id'] ?? 1); // id للعملة نجلب منه الكود
            $exRate      = (float)($_POST['exchange_rate'] ?? 1);
            $invDate     = $_POST['invoice_date'] ?? date('Y-m-d');
            $suppRef     = trim($_POST['supplier_ref'] ?? '');
            $payMethod   = $_POST['payment_method'] ?? 'deferred';
            $notes       = trim($_POST['notes'] ?? '');
            $rows        = json_decode($_POST['rows'] ?? '[]', true);

            if (!$whId) throw new Exception('يجب اختيار المستودع');
            if (empty($rows)) throw new Exception('يجب إضافة مادة واحدة على الأقل');

            // حساب الإجماليات بالعملتين
            $subtotalOrig = 0;
            foreach ($rows as $r) { $subtotalOrig += (float)$r['qty'] * (float)$r['unit_price']; }
            $discPct    = (float)($_POST['discount_pct'] ?? 0);
            $discOrig   = $subtotalOrig * $discPct / 100;
            $taxPct     = (float)($_POST['tax_pct'] ?? 0);
            $taxOrig    = ($subtotalOrig - $discOrig) * $taxPct / 100;
            $totalOrig  = $subtotalOrig - $discOrig + $taxOrig;
            $paidOrig   = (float)($_POST['paid_usd'] ?? 0); // المدفوع بعملة الفاتورة
            $balanceOrig= $totalOrig - $paidOrig;

            // التحويل للدولار
            $rate       = $exRate > 0 ? $exRate : 1;
            $subtotal   = $subtotalOrig / $rate;
            $discAmt    = $discOrig     / $rate;
            $taxAmt     = $taxOrig      / $rate;
            $total      = $totalOrig    / $rate;
            $paid       = $paidOrig     / $rate;
            $balance    = $balanceOrig  / $rate;

            $status = $paidOrig >= $totalOrig ? 'paid' : ($paidOrig > 0 ? 'partial' : 'draft');

            $invNo = generatePurchaseNo($pdo, $TP);

            // جلب كود العملة
            $currRow = $pdo->prepare("SELECT code,symbol FROM `currencies` WHERE id=?");
            $currRow->execute([$currId]);
            $currData = $currRow->fetch();
            $currCode = $currData['code'] ?? 'USD';

            $pdo->prepare("INSERT INTO `{$TP}`
                (invoice_no,supplier_ref,supplier_id,warehouse_id,currency,exchange_rate,
                invoice_date,
                subtotal_orig,subtotal_usd,
                discount_pct,discount_orig,discount_usd,
                tax_pct,tax_orig,tax_usd,
                total_orig,total_usd,
                paid_orig,paid_usd,
                balance_orig,balance_usd,
                status,payment_method,notes,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$invNo,$suppRef,$supplierId,$whId,$currCode,$exRate,
                    $invDate,
                    $subtotalOrig,$subtotal,
                    $discPct,$discOrig,$discAmt,
                    $taxPct,$taxOrig,$taxAmt,
                    $totalOrig,$total,
                    $paidOrig,$paid,
                    $balanceOrig,$balance,
                    $status,$payMethod,$notes,$_SESSION['user_id']]);
            $purchaseId = (int)$pdo->lastInsertId();

            // إدراج التفاصيل فقط — بدون تحديث المخزون حتى التأكيد
            foreach ($rows as $r) {
                $itemId    = (int)$r['item_id'];
                $qty       = (float)$r['qty'];
                $price     = (float)$r['unit_price'];
                $disc      = (float)($r['discount_pct'] ?? 0);
                $lineTotal = $qty * $price * (1 - $disc/100);
                $priceUsd  = $exRate > 0 ? $price / $exRate : $price;

                // نسجل حركة معلقة (بدون تأثير على المخزون بعد)
                $movNo = 'MOV-'.date('Y').'-'.str_pad(
                    (int)$pdo->query("SELECT COUNT(*)+1 FROM `{$TM}`")->fetchColumn(), 5, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO `{$TM}`
                    (movement_no,item_id,warehouse_id,movement_type,direction,quantity,
                    unit_cost_usd,total_cost_usd,qty_before,qty_after,
                    reference_type,reference_id,movement_date,is_posted,created_by)
                    VALUES (?,?,?,'receive','in',?,?,?,0,0,'purchase',?,?,0,?)")
                    ->execute([$movNo,$itemId,$whId,$qty,$priceUsd,$qty*$priceUsd,
                        $purchaseId,$invDate,$_SESSION['user_id']]);
                $movId = (int)$pdo->lastInsertId();

                $pdo->prepare("INSERT INTO `{$TPI}` (purchase_id,item_id,quantity,unit_price_usd,discount_pct,total_usd,movement_id)
                    VALUES (?,?,?,?,?,?,?)")
                    ->execute([$purchaseId,$itemId,$qty,$priceUsd,$disc,$lineTotal,$movId]);
            }
            // المخزون لا يتحدث هنا — ينتظر التأكيد

            echo json_encode(['ok'=>true,'invoice_no'=>$invNo,'id'=>$purchaseId,'msg'=>'تم حفظ الفاتورة بنجاح']);
        }

        // ── تأكيد فاتورة ──
        elseif ($act === 'confirm_purchase') {
            requirePermission('inventory.consumables','edit');
            $id  = (int)$_POST['id'];
            $pSt = $pdo->prepare("SELECT * FROM `{$TP}` WHERE id=?");
            $pSt->execute([$id]);
            $pur = $pSt->fetch();
            if (!$pur) throw new Exception('الفاتورة غير موجودة');
            if ($pur['status'] !== 'draft') throw new Exception('يمكن تأكيد المسودات فقط');

            $whId  = (int)$pur['warehouse_id'];
            $paid  = (float)($pur['paid_orig'] ?? $pur['paid_usd']);
            $total = (float)($pur['total_orig'] ?? $pur['total_usd']);
            $newStatus = $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'confirmed');

            // جلب تفاصيل الفاتورة وتحديث المخزون
            $items = $pdo->prepare("SELECT pi.*, m.id AS mov_id
                FROM `{$TPI}` pi
                LEFT JOIN `{$TM}` m ON m.id=pi.movement_id
                WHERE pi.purchase_id=?");
            $items->execute([$id]);
            foreach ($items->fetchAll() as $row) {
                $itemId   = (int)$row['item_id'];
                $qty      = (float)$row['quantity'];
                $priceUsd = (float)$row['unit_price_usd'];

                // جلب الرصيد الحالي
                $curSt = $pdo->prepare("SELECT quantity,avg_cost_usd FROM `{$TST}` WHERE item_id=? AND warehouse_id=?");
                $curSt->execute([$itemId,$whId]);
                $cur = $curSt->fetch();
                $before   = $cur ? (float)$cur['quantity'] : 0;
                $oldCost  = $cur ? (float)$cur['avg_cost_usd'] : 0;
                $newQty   = $before + $qty;
                $newCost  = $newQty > 0 ? (($before * $oldCost) + ($qty * $priceUsd)) / $newQty : $priceUsd;

                // تحديث الحركة بالأرصدة الصحيحة
                if ($row['mov_id']) {
                    $pdo->prepare("UPDATE `{$TM}` SET qty_before=?,qty_after=?,is_posted=1 WHERE id=?")
                        ->execute([$before, $newQty, $row['mov_id']]);
                }

                // تحديث المخزون
                if ($cur) {
                    $pdo->prepare("UPDATE `{$TST}` SET quantity=?,avg_cost_usd=?,last_movement=NOW() WHERE item_id=? AND warehouse_id=?")
                        ->execute([$newQty,$newCost,$itemId,$whId]);
                } else {
                    $pdo->prepare("INSERT INTO `{$TST}` (item_id,warehouse_id,quantity,avg_cost_usd,last_movement) VALUES (?,?,?,?,NOW())")
                        ->execute([$itemId,$whId,$qty,$priceUsd]);
                }
            }

            $pdo->prepare("UPDATE `{$TP}` SET status=?,updated_by=?,updated_at=NOW() WHERE id=?")
                ->execute([$newStatus,$_SESSION['user_id'],$id]);
            echo json_encode(['ok'=>true,'msg'=>'تم تأكيد الفاتورة وتحديث المخزون']);
        }

        // ── إلغاء فاتورة ──
        elseif ($act === 'cancel_purchase') {
            requirePermission('inventory.consumables','edit');
            $id  = (int)$_POST['id'];
            $pSt = $pdo->prepare("SELECT * FROM `{$TP}` WHERE id=?");
            $pSt->execute([$id]);
            $pur = $pSt->fetch();
            if (!$pur) throw new Exception('الفاتورة غير موجودة');
            if ($pur['status'] === 'cancelled') throw new Exception('الفاتورة ملغاة مسبقاً');

            $wasPosted = in_array($pur['status'], ['confirmed','partial','paid']);

            if ($wasPosted) {
                // عكس المخزون فقط إذا كانت مؤكدة
                $items = $pdo->prepare("SELECT pi.* FROM `{$TPI}` pi WHERE pi.purchase_id=?");
                $items->execute([$id]);
                foreach ($items->fetchAll() as $row) {
                    $pdo->prepare("UPDATE `{$TST}` SET quantity=GREATEST(0,quantity-?), last_movement=NOW()
                        WHERE item_id=? AND warehouse_id=?")
                        ->execute([$row['quantity'],$row['item_id'],$pur['warehouse_id']]);
                }
                // تحديث حالة الحركات
                $pdo->prepare("UPDATE `{$TM}` m
                    JOIN `{$TPI}` pi ON pi.movement_id=m.id
                    SET m.is_posted=0, m.movement_type='return_out'
                    WHERE pi.purchase_id=?")
                    ->execute([$id]);
                $msg = 'تم إلغاء الفاتورة وعكس المخزون';
            } else {
                // مسودة — فقط نلغيها بدون عكس مخزون
                $pdo->prepare("UPDATE `{$TM}` m JOIN `{$TPI}` pi ON pi.movement_id=m.id
                    SET m.is_posted=0 WHERE pi.purchase_id=?")->execute([$id]);
                $msg = 'تم إلغاء المسودة';
            }

            $pdo->prepare("UPDATE `{$TP}` SET status='cancelled',updated_by=?,updated_at=NOW() WHERE id=?")
                ->execute([$_SESSION['user_id'],$id]);
            echo json_encode(['ok'=>true,'msg'=>$msg]);
        }

        // ── إضافة مورد جديد ──
        elseif ($act === 'add_supplier') {
            $name    = trim($_POST['name'] ?? '');
            $contact = trim($_POST['contact_person'] ?? '');
            $phone   = trim($_POST['phone'] ?? '');
            $type    = $_POST['type'] ?? 'wholesaler';
            $notes   = trim($_POST['notes'] ?? '');
            if (!$name) throw new Exception('اسم المورد مطلوب');
            $pdo->prepare("INSERT INTO `{$TSP}` (name,contact_person,phone,type,supplier_type,notes,created_by)
                VALUES (?,?,?,?,'consumable',?,?)")
                ->execute([$name,$contact,$phone,$type,$notes,$_SESSION['user_id']]);
            $newId = (int)$pdo->lastInsertId();
            echo json_encode(['ok'=>true,'id'=>$newId,'name'=>$name,'phone'=>$phone,'contact'=>$contact]);
        }

        else throw new Exception('إجراء غير معروف');
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

// ── بيانات الصفحة ──────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$where  = 'WHERE 1=1'; $params = [];
if ($search) { $where .= ' AND (p.invoice_no LIKE ? OR s.name LIKE ?)'; $params[] = "%{$search}%"; $params[] = "%{$search}%"; }
if ($status) { $where .= ' AND p.status=?'; $params[] = $status; }

$stmt = $pdo->prepare("SELECT p.*, s.name AS supplier_name, w.name AS wh_name,
    p.currency AS currency_code,
    COALESCE(c.symbol, '$') AS currency_symbol
    FROM `{$TP}` p
    LEFT JOIN `{$TSP}` s ON s.id=p.supplier_id
    LEFT JOIN `{$TW}` w  ON w.id=p.warehouse_id
    LEFT JOIN `currencies` c ON c.code=p.currency
    {$where}
    ORDER BY p.created_at DESC LIMIT 100");
$stmt->execute($params);
$purchases = $stmt->fetchAll();

$suppliers  = $pdo->query("SELECT id,name,phone,contact_person FROM `{$TSP}` WHERE status='active' AND supplier_type IN ('consumable','both') ORDER BY name")->fetchAll();
$warehouses = $pdo->query("SELECT * FROM `{$TW}` WHERE is_active=1 ORDER BY id")->fetchAll();
$currencies = $pdo->query("SELECT * FROM `currencies` WHERE status='active' ORDER BY is_base DESC, code")->fetchAll();
$items_list = $pdo->query("SELECT id,name,unit,estimated_cost,currency_id FROM `{$TI}` WHERE is_active=1 ORDER BY category,name")->fetchAll();

// إحصائيات
try {
    $stats = $pdo->query("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status='confirmed' THEN 1 ELSE 0 END) AS confirmed,
        SUM(CASE WHEN status='partial'   THEN 1 ELSE 0 END) AS partial,
        SUM(CASE WHEN status='paid'      THEN 1 ELSE 0 END) AS paid,
        COALESCE(SUM(CASE WHEN status NOT IN ('cancelled') THEN total_usd END),0) AS total_amount,
        COALESCE(SUM(CASE WHEN status NOT IN ('cancelled') THEN balance_usd END),0) AS total_balance
        FROM `{$TP}`")->fetch();
} catch(Exception $e) {
    $stats = ['total'=>0,'confirmed'=>0,'partial'=>0,'paid'=>0,'total_amount'=>0,'total_balance'=>0];
}

$STATUS_MAP = [
    'draft'     => ['label'=>'مسودة',     'cls'=>'bg-secondary-subtle text-secondary'],
    'confirmed' => ['label'=>'مؤكدة',     'cls'=>'bg-info-subtle text-info'],
    'partial'   => ['label'=>'جزئي',      'cls'=>'bg-warning-subtle text-warning'],
    'paid'      => ['label'=>'مدفوعة',    'cls'=>'bg-success-subtle text-success'],
    'cancelled' => ['label'=>'ملغاة',     'cls'=>'bg-danger-subtle text-danger'],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>فواتير شراء المستهلكات — <?= htmlspecialchars($branchName) ?></title>
<link rel="icon" href="/bayhas/assets/images/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="/bayhas/assets/css/layout.css" rel="stylesheet">
<style>
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
table.mtbl tr:hover td{background:#f8faff}
.act-btn{width:28px;height:28px;border-radius:7px;border:1px solid #e2e8f0;background:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem;color:#64748b;cursor:pointer;transition:all .12s}
.act-btn:hover{background:#f1f5f9;color:#1e293b}
.act-btn.danger:hover{background:#fee2e2;color:#dc2626;border-color:#fca5a5}
.act-btn.success-h:hover{background:#dcfce7;color:#16a34a;border-color:#86efac}
/* ── فاتورة الإدخال ── */
.inv-line{display:grid;grid-template-columns:minmax(0,2.5fr) 80px 100px 80px 80px 90px 32px;gap:6px;align-items:center;margin-bottom:6px}
.inv-line input,.inv-line select{font-size:.78rem;padding:4px 7px;border:1px solid #e2e8f0;border-radius:7px;width:100%}
.inv-line input[readonly]{background:#f8fafc;color:#64748b}
.inv-total{background:#f8fafc;border-radius:10px;padding:10px 14px;font-size:.82rem}
.inv-total-row{display:flex;justify-content:space-between;padding:3px 0}
.inv-total-row.final{font-weight:700;color:#1e293b;font-size:.9rem;border-top:1px solid #e2e8f0;padding-top:6px;margin-top:4px}
.field-lbl{font-size:.76rem;font-weight:700;color:#475569;margin-bottom:4px;display:block}
.req{color:#dc2626}
.n{font-variant-numeric:tabular-nums}
</style>
</head>
<body>
<div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
<?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>

<header class="topbar">
    <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
    <span class="tb-title"><i class="bi bi-cart-plus me-1 text-primary"></i>فواتير الشراء</span>
    <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
    <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
        <a href="consumables.php" style="color:#64748b;text-decoration:none">المستهلكات</a>
        <i class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
        <span class="text-primary">فواتير الشراء</span>
    </nav>
</header>

<main class="main-content"><div class="content-body">

<!-- إحصائيات -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#eff6ff"><i class="bi bi-receipt text-primary"></i></div>
            <div><div class="stat-val"><?= $stats['total'] ?></div><div class="stat-lbl">إجمالي الفواتير</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fef3c7"><i class="bi bi-hourglass-split text-warning"></i></div>
            <div><div class="stat-val"><?= ($stats['confirmed']??0) + ($stats['partial']??0) ?></div><div class="stat-lbl">معلّقة / جزئية</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#f0fdf4"><i class="bi bi-currency-dollar text-success"></i></div>
            <div><div class="stat-val n"><?= number_format($stats['total_amount']??0,2) ?> $</div><div class="stat-lbl">إجمالي المشتريات</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fee2e2"><i class="bi bi-exclamation-circle text-danger"></i></div>
            <div><div class="stat-val n"><?= number_format($stats['total_balance']??0,2) ?> $</div><div class="stat-lbl">إجمالي المستحق</div></div>
        </div>
    </div>
</div>

<!-- التبويبات -->
<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e2e8f0">
    <li class="nav-item">
        <a class="nav-link fw-600 " href="consumables.php"
           style="border:none;color:#64748b;font-size:.83rem">
            <i class="bi bi-box-seam me-1"></i>المواد الاستهلاكية
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link fw-600 active" href="consumable_purchases.php"
           style="border:none;border-bottom:2px solid #1e3a8a;color:#1e3a8a;margin-bottom:-2px;font-size:.83rem">
            <i class="bi bi-cart-plus me-1"></i>فواتير الشراء
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link fw-600 " href="consumable_issues.php"
           style="border:none;color:#64748b;font-size:.83rem">
            <i class="bi bi-arrow-bar-up me-1"></i>صرف المستهلكات
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link fw-600 " href="consumable_sales.php"
           style="border:none;color:#64748b;font-size:.83rem">
            <i class="bi bi-receipt me-1"></i>فواتير البيع
        </a>
    </li>
    <li class="nav-item ms-auto">
        <a class="nav-link fw-600" href="consumable_movements.php"
           style="border:none;color:#0891b2;font-size:.83rem">
            <i class="bi bi-arrow-left-right me-1"></i>سجل الحركات
        </a>
    </li>
</ul>
<!-- الجدول -->
<div class="tbl-wrap">
    <div class="tbl-hdr">
        <span style="font-size:.88rem;font-weight:700;color:#1e293b">
            <i class="bi bi-list-ul me-1 text-primary"></i>سجل فواتير الشراء
        </span>
        <div class="d-flex gap-2 ms-auto flex-wrap align-items-center">
            <form method="get" class="d-flex gap-2">
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                       placeholder="بحث..." class="form-control form-control-sm" style="width:140px;border-radius:8px">
                <select name="status" class="form-select form-select-sm" style="width:120px;border-radius:8px" onchange="this.form.submit()">
                    <option value="">كل الحالات</option>
                    <?php foreach ($STATUS_MAP as $k=>$v): ?>
                    <option value="<?=$k?>" <?= $status===$k?'selected':'' ?>><?= $v['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <button class="btn btn-sm fw-600" style="border-radius:9px;background:#1e3a8a;color:#fff;font-size:.82rem"
                    onclick="openNewInvoice()">
                <i class="bi bi-plus-lg me-1"></i>فاتورة جديدة
            </button>
        </div>
    </div>
    <div class="table-responsive">
    <table class="mtbl">
        <thead><tr>
            <th>رقم الفاتورة</th><th>التاريخ</th><th>المورد</th>
            <th>المستودع</th><th>الإجمالي</th><th>المدفوع</th>
            <th>المتبقي</th><th>الحالة</th><th style="text-align:center">إجراءات</th>
        </tr></thead>
        <tbody>
        <?php if (empty($purchases)): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">
            <i class="bi bi-receipt d-block mb-2" style="font-size:2rem;opacity:.2"></i>
            لا توجد فواتير<?= $search?" تطابق \"{$search}\"":'' ?>
        </td></tr>
        <?php endif; ?>
        <?php foreach ($purchases as $pur):
            $st = $STATUS_MAP[$pur['status']] ?? $STATUS_MAP['draft'];
            $sym = $pur['currency_symbol'] ?? '$';
        ?>
        <tr id="pRow_<?=$pur['id']?>">
            <td class="n fw-600" style="direction:ltr"><?= htmlspecialchars($pur['invoice_no']) ?></td>
            <td class="text-muted"><?= $pur['invoice_date'] ?></td>
            <td><?= htmlspecialchars($pur['supplier_name'] ?? '—') ?></td>
            <td style="font-size:.78rem;color:#64748b"><?= htmlspecialchars($pur['wh_name'] ?? '—') ?></td>
            <?php
                $tOrig = $pur['total_orig']   ?? $pur['total_usd'];
                $pOrig = $pur['paid_orig']    ?? $pur['paid_usd'];
                $bOrig = $pur['balance_orig'] ?? $pur['balance_usd'];
            ?>
            <td class="n fw-600"><?= number_format($tOrig,2) ?> <?=$sym?></td>
            <td class="n text-success fw-600"><?= number_format($pOrig,2) ?> <?=$sym?></td>
            <td class="n <?= $bOrig>0?'text-danger fw-600':'' ?>">
                <?= number_format($bOrig,2) ?> <?=$sym?>
            </td>
            <td><span class="badge <?=$st['cls']?>" style="font-size:.68rem"><?=$st['label']?></span></td>
            <td>
                <div class="d-flex gap-1 justify-content-center">
                    <button class="act-btn" onclick="viewInvoice(<?=$pur['id']?>)" title="عرض" style="color:#0891b2;border-color:#a5f3fc">
                        <i class="bi bi-eye"></i>
                    </button>
                    <?php if ($pur['status'] === 'draft'): ?>
                    <button class="act-btn success-h" onclick="confirmInvoice(<?=$pur['id']?>, '<?=htmlspecialchars($pur['invoice_no'],ENT_QUOTES)?>')" title="تأكيد الفاتورة" style="color:#16a34a;border-color:#86efac">
                        <i class="bi bi-check-circle"></i>
                    </button>
                    <?php endif; ?>
                    <?php if (!in_array($pur['status'],['cancelled','paid'])): ?>
                    <button class="act-btn danger" onclick="cancelInvoice(<?=$pur['id']?>, '<?=htmlspecialchars($pur['invoice_no'],ENT_QUOTES)?>')" title="إلغاء">
                        <i class="bi bi-x-circle"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

</div></main>

<!-- ══ مودال فاتورة جديدة ══ -->
<div class="modal fade" id="invModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#0c447c,#1e3a8a);border-radius:16px 16px 0 0">
        <div>
          <h6 class="modal-title text-white fw-700 mb-0">فاتورة شراء مستهلكات جديدة</h6>
          <div id="invNoPreview" style="font-size:.75rem;color:rgba(255,255,255,.7);margin-top:2px">رقم الفاتورة يُولَّد تلقائياً</div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 pt-3" style="font-size:.85rem;font-weight:500">

        <!-- بيانات رأس الفاتورة -->
        <div class="row g-3 mb-3 pb-3" style="border-bottom:1px solid #f1f5f9">
          <div class="col-md-3">
            <label class="field-lbl">المورد</label>
            <div class="d-flex gap-2">
              <select id="iSupplier" class="form-select form-select-sm" style="flex:1" onchange="onSupplierChange()">
                <option value="">— بدون مورد —</option>
                <?php foreach ($suppliers as $sp): ?>
                <option value="<?=$sp['id']?>"
                    data-phone="<?= htmlspecialchars($sp['phone']??'') ?>"
                    data-contact="<?= htmlspecialchars($sp['contact_person']??'') ?>">
                    <?= htmlspecialchars($sp['name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-sm" style="border-radius:8px;border:1px solid #1e3a8a;color:#1e3a8a;padding:4px 8px;white-space:nowrap"
                      onclick="showSupplierModal()" title="إضافة مورد جديد">
                <i class="bi bi-plus"></i>
              </button>
            </div>
            <div id="supplierInfo" style="display:none;font-size:.7rem;color:#64748b;margin-top:3px">
              <i class="bi bi-telephone me-1"></i><span id="supplierPhone"></span>
            </div>
          </div>
          <div class="col-md-3">
            <label class="field-lbl">المستودع <span class="req">*</span></label>
            <select id="iWarehouse" class="form-select form-select-sm">
              <option value="">— اختر —</option>
              <?php foreach ($warehouses as $wh): ?>
              <option value="<?=$wh['id']?>" <?= strpos($wh['name'],'استهلاك')!==false?'selected':'' ?>><?= htmlspecialchars($wh['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="field-lbl">تاريخ الفاتورة <span class="req">*</span></label>
            <input type="date" id="iDate" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-md-2">
            <label class="field-lbl">العملة</label>
            <select id="iCurrency" class="form-select form-select-sm" onchange="onCurrencyChange()">
              <?php foreach ($currencies as $cu): ?>
              <option value="<?=$cu['id']?>" data-rate="<?=$cu['exchange_rate']?>" data-sym="<?= htmlspecialchars($cu['symbol']) ?>" <?= $cu['is_base']?'selected':'' ?>>
                <?= htmlspecialchars($cu['code'].' - '.$cu['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="field-lbl">سعر الصرف (/$)</label>
            <input type="number" id="iExRate" class="form-control form-control-sm" value="1" min="0.0001" step="0.0001" oninput="calcTotals()">
            <div id="rateWarning" style="display:none;font-size:.68rem;color:#d97706;margin-top:2px">
              <i class="bi bi-exclamation-triangle me-1"></i>تأكد من سعر الصرف
            </div>
          </div>
          <div class="col-md-3">
            <label class="field-lbl">رقم فاتورة المورد</label>
            <input type="text" id="iSupplierRef" class="form-control form-control-sm" placeholder="اختياري" dir="ltr">
          </div>
          <div class="col-md-3">
            <label class="field-lbl">طريقة الدفع</label>
            <select id="iPayMethod" class="form-select form-select-sm">
              <option value="deferred">آجل</option>
              <option value="cash">نقداً</option>
              <option value="bank">تحويل بنكي</option>
              <option value="card">بطاقة</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="field-lbl">المبلغ المدفوع <span id="iPaidCurLabel" style="color:#0891b2"></span></label>
            <input type="number" id="iPaid" class="form-control form-control-sm" min="0" step="0.01" placeholder="0.00" oninput="calcTotals()">
            <div class="field-hint">بعملة الفاتورة</div>
          </div>
          <div class="col-md-3">
            <label class="field-lbl">ملاحظات</label>
            <input type="text" id="iNotes" class="form-control form-control-sm" placeholder="اختياري">
          </div>
        </div>

        <!-- بنود الفاتورة -->
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span style="font-size:.82rem;font-weight:700;color:#1e293b">
            <i class="bi bi-list-ul me-1 text-primary"></i>بنود الفاتورة
          </span>
          <button class="btn btn-sm" style="border-radius:8px;border:1px solid #1e3a8a;color:#1e3a8a;font-size:.76rem"
                  onclick="addLine()">
            <i class="bi bi-plus-lg me-1"></i>إضافة مادة
          </button>
        </div>

        <!-- رأس الجدول -->
        <div style="display:grid;grid-template-columns:minmax(0,2.5fr) 80px 100px 80px 80px 90px 32px;gap:6px;margin-bottom:6px;font-size:.75rem;color:#334155;font-weight:700">
          <span>اسم المادة</span><span>الكمية</span><span>السعر</span><span>خصم%</span><span>بالدولار</span><span>الإجمالي</span><span></span>
        </div>
        <div id="linesWrap"></div>

        <!-- الإجماليات -->
        <div class="row g-3 mt-2">
          <div class="col-md-5 ms-auto">
            <div class="inv-total">
              <div class="inv-total-row"><span style="color:#64748b">المجموع الجزئي</span><span class="n" id="tSubtotal">0.00</span></div>
              <div class="inv-total-row">
                <span style="color:#64748b">خصم %
                  <input type="number" id="tDiscPct" class="form-control form-control-sm d-inline-block"
                         style="width:55px;padding:2px 5px;font-size:.75rem" min="0" max="100" value="0" oninput="calcTotals()">
                </span>
                <span class="n text-danger" id="tDiscAmt">-0.00</span>
              </div>
              <div class="inv-total-row">
                <span style="color:#64748b">ضريبة %
                  <input type="number" id="tTaxPct" class="form-control form-control-sm d-inline-block"
                         style="width:55px;padding:2px 5px;font-size:.75rem" min="0" max="100" value="0" oninput="calcTotals()">
                </span>
                <span class="n" id="tTaxAmt">+0.00</span>
              </div>
              <div class="inv-total-row final">
                <span>الإجمالي</span><span class="n" id="tTotal">0.00</span>
              </div>
              <div class="inv-total-row"><span style="color:#16a34a">المدفوع</span><span class="n text-success" id="tPaid">0.00</span></div>
              <div class="inv-total-row"><span style="color:#dc2626">المتبقي</span><span class="n text-danger" id="tBalance">0.00</span></div>
            </div>
          </div>
        </div>

      </div>
      <div class="modal-footer border-0 px-4 pb-4">
        <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
        <button class="btn btn-sm fw-600" style="border-radius:8px;background:#1e3a8a;color:#fff;min-width:120px"
                onclick="saveInvoice()" id="btnSaveInv">
          <span id="saveInvTxt"><i class="bi bi-floppy me-1"></i>حفظ الفاتورة</span>
          <span id="saveInvSpin" class="spinner-border spinner-border-sm" style="display:none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ مودال عرض الفاتورة ══ -->
<div class="modal fade" id="viewModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#0c447c,#1e3a8a);border-radius:16px 16px 0 0">
        <h6 class="modal-title text-white fw-700 mb-0" id="vTitle">تفاصيل الفاتورة</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 py-3" id="vBody">
        <div class="text-center py-4"><span class="spinner-border text-primary"></span></div>
      </div>
    </div>
  </div>
</div>

<!-- مودال إضافة مورد -->
<div class="modal fade" id="supplierModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#0c447c,#1e3a8a);border-radius:16px 16px 0 0">
        <h6 class="modal-title text-white fw-700 mb-0"><i class="bi bi-truck me-2"></i>إضافة مورد مستهلكات</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 pt-3">
        <div class="row g-3">
          <div class="col-md-7">
            <label class="field-lbl">اسم المورد <span class="req">*</span></label>
            <input type="text" id="spName" class="form-control form-control-sm" placeholder="مثال: شركة النور للتوزيع">
          </div>
          <div class="col-md-5">
            <label class="field-lbl">نوع المورد</label>
            <select id="spType" class="form-select form-select-sm">
              <option value="wholesaler">موزّع بالجملة</option>
              <option value="manufacturer">مصنّع</option>
              <option value="distributor">موزّع</option>
              <option value="retailer">تاجر تجزئة</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="field-lbl">جهة الاتصال</label>
            <input type="text" id="spContact" class="form-control form-control-sm" placeholder="اسم المسؤول">
          </div>
          <div class="col-md-6">
            <label class="field-lbl">رقم الهاتف</label>
            <input type="text" id="spPhone" class="form-control form-control-sm" dir="ltr" placeholder="+963...">
          </div>
          <div class="col-12">
            <label class="field-lbl">ملاحظات</label>
            <textarea id="spNotes" class="form-control form-control-sm" rows="2" placeholder="اختياري"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 px-4 pb-4">
        <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
        <button class="btn btn-sm fw-600" style="border-radius:8px;background:#1e3a8a;color:#fff" onclick="saveSupplier()">
          <i class="bi bi-plus-lg me-1"></i>إضافة المورد
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const sb = document.getElementById('sidebar'), ov = document.getElementById('sbOverlay');
function sbOpen()  { sb.classList.add('open');  ov.classList.add('show'); }
function sbClose() { sb.classList.remove('open'); ov.classList.remove('show'); }
window.addEventListener('resize', () => { if(window.innerWidth>991) sbClose(); });
function toggleGroup(g) {
    const o = g.classList.contains('open');
    document.querySelectorAll('.sb-group.open').forEach(x => x.classList.remove('open'));
    g.classList.toggle('open', !o);
    localStorage.setItem('sb_open_' + g.dataset.key, (!o).toString());
}
document.querySelectorAll('.sb-group').forEach(g => {
    if (localStorage.getItem('sb_open_' + g.dataset.key) === 'true') g.classList.add('open');
});

const invModal      = new bootstrap.Modal(document.getElementById('invModal'));
const viewModal     = new bootstrap.Modal(document.getElementById('viewModal'));
const supplierModal = new bootstrap.Modal(document.getElementById('supplierModal'));

// بيانات المواد
const ITEMS = <?= json_encode($items_list) ?>;
const STATUS_MAP = <?= json_encode($STATUS_MAP) ?>;

let lines = [];
let symCur = '$';

function post(data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k, v??''));
    return fetch(location.href,{method:'POST',body:fd}).then(r=>r.json());
}
function toast(msg, type='success') {
    const t = document.createElement('div');
    t.className=`alert alert-${type} shadow`;
    t.style.cssText='position:fixed;top:70px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:220px;text-align:center;font-size:.83rem;padding:.5rem 1.2rem';
    t.innerHTML=`<i class="bi bi-${type==='success'?'check-circle-fill text-success':'exclamation-triangle-fill text-danger'} me-2"></i>${msg}`;
    document.body.appendChild(t); setTimeout(()=>t.remove(),3200);
}

// ── المورد ──
function showSupplierModal() {
    ['spName','spContact','spPhone','spNotes'].forEach(id => document.getElementById(id).value='');
    document.getElementById('spType').value='wholesaler';
    supplierModal.show();
}
function saveSupplier() {
    const name = document.getElementById('spName').value.trim();
    if (!name) { toast('اسم المورد مطلوب','danger'); return; }
    post({
        _action: 'add_supplier',
        name,
        contact_person: document.getElementById('spContact').value,
        phone:          document.getElementById('spPhone').value,
        type:           document.getElementById('spType').value,
        notes:          document.getElementById('spNotes').value,
    }).then(d => {
        if (!d.ok) { toast(d.msg,'danger'); return; }
        const sel = document.getElementById('iSupplier');
        const opt = document.createElement('option');
        opt.value = d.id;
        opt.dataset.phone   = d.phone||'';
        opt.dataset.contact = d.contact||'';
        opt.textContent = d.name;
        opt.selected = true;
        sel.appendChild(opt);
        onSupplierChange();
        supplierModal.hide();
        toast('تمت إضافة المورد');
    });
}
function onSupplierChange() {
    const sel  = document.getElementById('iSupplier');
    const opt  = sel.options[sel.selectedIndex];
    const info = document.getElementById('supplierInfo');
    if (sel.value && opt.dataset.phone) {
        document.getElementById('supplierPhone').textContent = opt.dataset.phone;
        info.style.display = 'block';
    } else {
        info.style.display = 'none';
    }
}

// ── تغيير العملة ──
function onCurrencyChange() {
    const sel  = document.getElementById('iCurrency');
    const opt  = sel.options[sel.selectedIndex];
    const rate = parseFloat(opt.dataset.rate) || 1;
    symCur = opt.dataset.sym || '$';
    document.getElementById('iExRate').value = rate;
    const lbl = document.getElementById('iPaidCurLabel');
    if (lbl) lbl.textContent = '(' + (opt.text.split(' - ')[0]) + ')';
    // تحذير إذا عملة غير USD وسعر صرف = 1
    const warn = document.getElementById('rateWarning');
    const isBase = opt.text.includes('USD');
    warn.style.display = (!isBase && rate <= 1) ? 'block' : 'none';
    refreshUsdFields();
    calcTotals();
}

// ── فتح مودال فاتورة جديدة ──
function openNewInvoice() {
    lines = [];
    document.getElementById('linesWrap').innerHTML = '';
    document.getElementById('iSupplier').value    = '';
    document.getElementById('iSupplierRef').value = '';
    document.getElementById('iDate').value        = new Date().toISOString().split('T')[0];
    document.getElementById('iPayMethod').value   = 'deferred';
    document.getElementById('iPaid').value        = '';
    document.getElementById('iNotes').value       = '';
    document.getElementById('tDiscPct').value     = '0';
    document.getElementById('tTaxPct').value      = '0';
    onCurrencyChange();
    addLine();
    invModal.show();
}

// ── إضافة سطر ──
function addLine() {
    const i = lines.length;
    lines.push({item_id:'', qty:1, unit_price:'', discount_pct:0, total:0});
    appendLineDOM(i);
    calcTotals();
}

function removeLine(idx) {
    lines.splice(idx, 1);
    rebuildAllLines();
    calcTotals();
}

function appendLineDOM(i) {
    const wrap = document.getElementById('linesWrap');
    const div  = document.createElement('div');
    div.className = 'inv-line';
    div.id = 'line_' + i;
    div.innerHTML = `
      <select class="form-select form-select-sm" style="font-size:.78rem">
        <option value="">— اختر المادة —</option>
        ${ITEMS.map(it=>`<option value="${it.id}">${it.name} (${it.unit})</option>`).join('')}
      </select>
      <input type="number" min="0.001" step="0.001" value="1" placeholder="الكمية">
      <input type="number" min="0" step="0.01" value="" placeholder="السعر">
      <input type="number" min="0" max="100" step="0.01" value="0" placeholder="0">
      <input type="number" readonly placeholder="$" style="background:#f0fdf4;color:#16a34a;font-size:.72rem">
      <input type="number" readonly placeholder="0.00">
      <button type="button" onclick="removeLine(${i})" style="width:28px;height:28px;border-radius:7px;border:1px solid #fca5a5;background:#fff;color:#dc2626;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.82rem">
        <i class="bi bi-x-lg"></i>
      </button>`;
    wrap.appendChild(div);

    const inputs = div.querySelectorAll('input, select');
    const selEl  = inputs[0];
    const qtyEl  = inputs[1];
    const prEl   = inputs[2];
    const discEl = inputs[3];
    const usdEl  = inputs[4];
    const totEl  = inputs[5];

    function recalc() {
        const qty  = parseFloat(qtyEl.value)  || 0;
        const pr   = parseFloat(prEl.value)   || 0;
        const disc = parseFloat(discEl.value) || 0;
        const rate = parseFloat(document.getElementById('iExRate').value) || 1;
        lines[i].qty          = qty;
        lines[i].unit_price   = pr;
        lines[i].discount_pct = disc;
        lines[i].total        = qty * pr * (1 - disc/100);
        usdEl.value = pr > 0 ? (pr / rate).toFixed(4) : '';
        totEl.value = lines[i].total > 0 ? lines[i].total.toFixed(2) : '';
        calcTotals();
    }

    selEl.addEventListener('change', function() {
        const it = ITEMS.find(x => x.id == this.value);
        lines[i].item_id = this.value;
        if (it && parseFloat(it.estimated_cost) > 0) {
            prEl.value = it.estimated_cost;
            lines[i].unit_price = parseFloat(it.estimated_cost);
        }
        recalc();
    });
    qtyEl.addEventListener('input',  recalc);
    prEl.addEventListener('input',   recalc);
    discEl.addEventListener('input', recalc);
    // حفظ recalc للتحديث من الخارج (سعر الصرف)
    div._recalc = recalc;
}

function rebuildAllLines() {
    const saved = JSON.parse(JSON.stringify(lines));
    document.getElementById('linesWrap').innerHTML = '';
    lines = [];
    saved.forEach((l, i) => {
        lines.push(l);
        appendLineDOM(i);
        const div = document.getElementById('line_' + i);
        if (!div) return;
        const inputs = div.querySelectorAll('input, select');
        inputs[0].value = l.item_id || '';
        inputs[1].value = l.qty     || 1;
        inputs[2].value = l.unit_price || '';
        inputs[3].value = l.discount_pct || 0;
        if (div._recalc) div._recalc();
    });
}

function refreshUsdFields() {
    document.querySelectorAll('#linesWrap .inv-line').forEach(div => {
        if (div._recalc) div._recalc();
    });
}

function checkRateWarning() {
    const exRate = parseFloat(document.getElementById('iExRate').value) || 1;
    const sel    = document.getElementById('iCurrency');
    const isBase = sel.options[sel.selectedIndex]?.text.includes('USD');
    document.getElementById('rateWarning').style.display = (!isBase && exRate <= 1) ? 'block' : 'none';
}
document.getElementById('iExRate')?.addEventListener('input', () => { checkRateWarning(); refreshUsdFields(); calcTotals(); });

function calcTotals() {
    const subtotal = lines.reduce((s,l) => s + (parseFloat(l.total)||0), 0);
    const discPct  = parseFloat(document.getElementById('tDiscPct').value) || 0;
    const taxPct   = parseFloat(document.getElementById('tTaxPct').value)  || 0;
    const discAmt  = subtotal * discPct / 100;
    const taxAmt   = (subtotal - discAmt) * taxPct / 100;
    const total    = subtotal - discAmt + taxAmt;
    const paid     = parseFloat(document.getElementById('iPaid').value) || 0;
    const balance  = total - paid;
    document.getElementById('tSubtotal').textContent = subtotal.toFixed(2)+' '+symCur;
    document.getElementById('tDiscAmt').textContent  = '-'+discAmt.toFixed(2)+' '+symCur;
    document.getElementById('tTaxAmt').textContent   = '+'+taxAmt.toFixed(2)+' '+symCur;
    document.getElementById('tTotal').textContent    = total.toFixed(2)+' '+symCur;
    document.getElementById('tPaid').textContent     = paid.toFixed(2)+' '+symCur;
    document.getElementById('tBalance').textContent  = balance.toFixed(2)+' '+symCur;
}

// ── حفظ الفاتورة ──
function saveInvoice() {
    if (!document.getElementById('iWarehouse').value) { toast('يجب اختيار المستودع','danger'); return; }
    if (!lines.length || !lines.some(l=>l.item_id&&l.qty>0)) {
        toast('يجب إضافة مادة واحدة على الأقل','danger'); return;
    }
    document.getElementById('saveInvTxt').style.opacity='0';
    document.getElementById('saveInvSpin').style.display='inline-block';

    const sel = document.getElementById('iCurrency');
    post({
        _action:      'save_purchase',
        supplier_id:  document.getElementById('iSupplier').value,
        warehouse_id: document.getElementById('iWarehouse').value,
        currency_id:  sel.value,
        exchange_rate:document.getElementById('iExRate').value,
        invoice_date: document.getElementById('iDate').value,
        supplier_ref: document.getElementById('iSupplierRef').value,
        payment_method:document.getElementById('iPayMethod').value,
        paid_usd:     document.getElementById('iPaid').value,
        discount_pct: document.getElementById('tDiscPct').value,
        tax_pct:      document.getElementById('tTaxPct').value,
        notes:        document.getElementById('iNotes').value,
        rows:         JSON.stringify(lines.filter(l=>l.item_id&&l.qty>0)),
    }).then(d=>{
        document.getElementById('saveInvTxt').style.opacity='1';
        document.getElementById('saveInvSpin').style.display='none';
        if(d.ok){ toast('✅ '+d.msg+' — '+d.invoice_no); invModal.hide(); setTimeout(()=>location.reload(),1000); }
        else toast(d.msg,'danger');
    });
}

// ── عرض فاتورة ──
function viewInvoice(id) {
    document.getElementById('vTitle').textContent='جارٍ التحميل...';
    document.getElementById('vBody').innerHTML='<div class="text-center py-4"><span class="spinner-border text-primary"></span></div>';
    viewModal.show();
    post({_action:'get_purchase',id}).then(d=>{
        if(!d.ok){document.getElementById('vBody').innerHTML=`<div class="text-danger p-3">${d.msg}</div>`;return;}
        const p=d.data;
        const st=STATUS_MAP[p.status]||STATUS_MAP['draft'];
        const sym=p.currency_symbol||'$';
        document.getElementById('vTitle').textContent='فاتورة: '+p.invoice_no;
        const exRateV = parseFloat(p.exchange_rate||1);
        const itemsHtml=(p.items||[]).map(it=>{
            const unitOrig  = parseFloat(it.unit_price_usd) * exRateV;
            const totalOrig = parseFloat(it.total_usd)      * exRateV;
            const isUSDv    = p.currency === 'USD';
            return `<tr>
                <td>${it.item_name}</td>
                <td class="n text-center">${parseFloat(it.quantity).toFixed(3)} ${it.unit}</td>
                <td class="n text-center">${unitOrig.toFixed(2)} ${sym}</td>
                <td class="n text-center">${parseFloat(it.discount_pct).toFixed(0)}%</td>
                <td class="n text-end fw-600">${totalOrig.toFixed(2)} ${sym}</td>
                ${!isUSDv?`<td class="n text-end" style="color:#94a3b8;font-size:.72rem">${parseFloat(it.total_usd).toFixed(2)} $</td>`:''}
            </tr>`;}).join('');
        // استخدام المبالغ الأصلية بعملة الفاتورة
        const tOrig = parseFloat(p.total_orig   || p.total_usd);
        const pOrig = parseFloat(p.paid_orig    || p.paid_usd);
        const bOrig = parseFloat(p.balance_orig || p.balance_usd);
        const sOrig = parseFloat(p.subtotal_orig|| p.subtotal_usd);
        const dOrig = parseFloat(p.discount_orig|| p.discount_usd);
        const xOrig = parseFloat(p.tax_orig     || p.tax_usd);
        const exRate= parseFloat(p.exchange_rate|| 1);
        const isUSD = p.currency === 'USD';

        document.getElementById('vBody').innerHTML=`
        <div class="row g-2 mb-3">
            <div class="col-6"><span style="font-size:.72rem;color:#64748b">المورد</span><div class="fw-600" style="font-size:.84rem">${p.supplier_name||'—'}</div></div>
            <div class="col-6"><span style="font-size:.72rem;color:#64748b">المستودع</span><div class="fw-600" style="font-size:.84rem">${p.wh_name||'—'}</div></div>
            <div class="col-6"><span style="font-size:.72rem;color:#64748b">التاريخ</span><div style="font-size:.84rem">${p.invoice_date}</div></div>
            <div class="col-6"><span style="font-size:.72rem;color:#64748b">الحالة</span><div><span class="badge ${st.cls}" style="font-size:.7rem">${st.label}</span></div></div>
            <div class="col-6"><span style="font-size:.72rem;color:#64748b">العملة</span><div style="font-size:.82rem">${p.currency_code||'USD'} ${!isUSD?`(1$ = ${exRate} ${sym})`:''}  </div></div>
            ${p.supplier_ref?`<div class="col-6"><span style="font-size:.72rem;color:#64748b">رقم فاتورة المورد</span><div dir="ltr" style="font-size:.82rem">${p.supplier_ref}</div></div>`:''}
        </div>
        <table class="mtbl mb-3" style="font-size:.78rem">
            <thead><tr style="background:#f8fafc">
                <th>المادة</th><th class="text-center">الكمية</th>
                <th class="text-center">سعر الوحدة (${sym})</th>
                <th class="text-center">خصم</th>
                <th class="text-end">الإجمالي (${sym})</th>
                ${!isUSD?`<th class="text-end">بالدولار</th>`:''}
            </tr></thead>
            <tbody>${itemsHtml}</tbody>
        </table>
        <div style="background:#f8fafc;border-radius:10px;padding:10px 14px">
            <div class="d-flex justify-content-between mb-1" style="font-size:.8rem">
                <span style="color:#64748b">المجموع</span>
                <span class="n">${sOrig.toFixed(2)} ${sym} ${!isUSD?`<span style="color:#94a3b8;font-size:.7rem">(${parseFloat(p.subtotal_usd).toFixed(2)} $)</span>`:''}</span>
            </div>
            ${dOrig>0?`<div class="d-flex justify-content-between mb-1" style="font-size:.8rem"><span style="color:#64748b">خصم (${p.discount_pct}%)</span><span class="n text-danger">-${dOrig.toFixed(2)} ${sym}</span></div>`:''}
            ${xOrig>0?`<div class="d-flex justify-content-between mb-1" style="font-size:.8rem"><span style="color:#64748b">ضريبة (${p.tax_pct}%)</span><span class="n">+${xOrig.toFixed(2)} ${sym}</span></div>`:''}
            <div class="d-flex justify-content-between mb-1 fw-700" style="font-size:.86rem;border-top:1px solid #e2e8f0;padding-top:6px">
                <span>الإجمالي</span>
                <span class="n">${tOrig.toFixed(2)} ${sym} ${!isUSD?`<span style="color:#94a3b8;font-size:.7rem">(${parseFloat(p.total_usd).toFixed(2)} $)</span>`:''}</span>
            </div>
            <div class="d-flex justify-content-between mb-1" style="font-size:.8rem"><span style="color:#16a34a">المدفوع</span><span class="n text-success">${pOrig.toFixed(2)} ${sym}</span></div>
            <div class="d-flex justify-content-between" style="font-size:.8rem"><span style="color:#dc2626">المتبقي</span><span class="n text-danger">${bOrig.toFixed(2)} ${sym}</span></div>
        </div>
        ${p.notes?`<div style="background:#f8fafc;border-radius:8px;padding:8px 12px;margin-top:10px;font-size:.78rem;color:#64748b">${p.notes}</div>`:''}`;
    });
}

// ── تأكيد فاتورة ──
function confirmInvoice(id, no) {
    if(!confirm(`تأكيد الفاتورة "${no}"؟
سيتم اعتماد الفاتورة وتسجيل المخزون نهائياً.`)) return;
    post({_action:'confirm_purchase',id}).then(d=>{
        if(d.ok){toast('✅ '+d.msg);setTimeout(()=>location.reload(),700);}
        else toast(d.msg,'danger');
    });
}

// ── إلغاء فاتورة ──
function cancelInvoice(id, no) {
    if(!confirm(`إلغاء الفاتورة "${no}"؟\nسيتم عكس حركات المخزون تلقائياً.`)) return;
    post({_action:'cancel_purchase',id}).then(d=>{
        if(d.ok){toast(d.msg);setTimeout(()=>location.reload(),800);}
        else toast(d.msg,'danger');
    });
}
</script>
</body>
</html>
