<?php
/**
 * sales/returns.php — مرتجعات فواتير البيع
 * المسار: retail1/modules/sales/returns.php
 *
 * ⚠ هاي الصفحة بتدير المسودات فقط (إنشاء/تعديل/حذف مرتجع بحالة draft).
 * التأكيد الفعلي (status=posted — عكس المخزون [بالإضافة، مو الطرح — عكس
 * مرتجع الشراء] + قيد محاسبي) لازم يصير حصراً عبر api/confirm_sale_return.php
 * (مو local action هون) — نفس مبدأ فصل التأكيد المحاسبي عن CRUD المسودات
 * المعتمد أصلاً بفواتير البيع العادية (راجع api/confirm_sale_invoice.php).
 * الملف هاد لسا غير مبني — زر "تأكيد" بالواجهة موجود ومربوط بمساره
 * الصحيح مسبقاً، بس هيرجع خطأ لحد ما يُبنى بمحادثة لاحقة — بالضبط
 * نفس حالة مرتجعات المشتريات حالياً.
 *
 * مبنية مطابقة حرفياً لـpurchases/returns.php، بعكس الاتجاه حيث يلزم:
 * customer بدل supplier، invoice بدل purchase. "خصم من رصيد المورد"
 * صار "خصم من ذمة العميل" (payment_handling=paid_credit_customer).
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('sales.returns', 'view');
$currentModule = 'sales.returns';

$TS = $_SESSION['table_suffix'];
$TR = "sales_returns_{$TS}";
$TRI = "sales_return_items_{$TS}";
$TI = "sales_invoices_{$TS}";
$TII = "sales_invoice_items_{$TS}";
$TC = "customers_{$TS}";
$TW = "warehouses_{$TS}";
$TV = "product_variants_{$TS}";
$TPROD = "products_{$TS}";
$TSZ = "product_sizes_{$TS}";
$TCL = "product_colors_{$TS}";
$TAC = "account_charts_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// بيانات الفرع الكاملة — لترويسة طباعة المرتجع
$branchInfo = $pdo->prepare("SELECT * FROM branches WHERE table_suffix=? LIMIT 1");
$branchInfo->execute([$TS]);
$branchInfo = $branchInfo->fetch(PDO::FETCH_ASSOC) ?: [];

// حسابات الصندوق/البنك — لاسترداد المرتجع نقداً (لو payment_handling=paid_refund_cash)
// نفس استبعاد حسابات الدفعات المقدمة الخاصة بالعملاء المعتمد بـsales_index.php
$cashAccounts = $pdo->query("SELECT ac.id,ac.code,ac.name,ac.balance,c.code AS cur_code,c.symbol AS cur_sym
    FROM `{$TAC}` ac
    LEFT JOIN currencies c ON c.id=ac.currency_id
    WHERE ac.account_type='asset' AND ac.is_active=1 AND ac.level>=3
      AND ac.id NOT IN (SELECT prepaid_account_id FROM `{$TC}` WHERE prepaid_account_id IS NOT NULL)
    ORDER BY ac.code")->fetchAll();

function genReturnNo(PDO $pdo, string $table): string
{
    $y = date('Y');
    $last = $pdo->query("SELECT return_number FROM `{$table}`
        WHERE return_number LIKE 'SRET-{$y}-%'
        ORDER BY id DESC LIMIT 1")->fetchColumn();
    $seq = $last ? (int) substr($last, -5) + 1 : 1;
    return "SRET-{$y}-" . str_pad($seq, 5, '0', STR_PAD_LEFT);
}

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        // بحث عن فاتورة بيع مؤكدة + بنودها القابلة للإرجاع
        if ($act === 'find_invoice') {
            $q = trim($_POST['q'] ?? '');
            $like = $q !== '' ? "%{$q}%" : '%';

            $st = $pdo->prepare("SELECT i.id, i.invoice_number, i.invoice_date, i.customer_id,
                    c.name AS customer_name, i.invoice_currency_id, i.base_currency_id, i.exchange_rate,
                    i.warehouse_id, cur.code AS currency_code, cur.symbol AS currency_symbol,
                    i.total_amount, i.discount_amount AS inv_discount_amount,
                    i.tax_amount AS inv_tax_amount, i.final_amount, i.payment_status,
                    bc.code AS base_currency_code, bc.symbol AS base_currency_symbol
                FROM `{$TI}` i
                JOIN `{$TC}` c ON c.id = i.customer_id
                LEFT JOIN currencies cur ON cur.id = i.invoice_currency_id
                LEFT JOIN currencies bc ON bc.id = i.base_currency_id
                WHERE i.status = 'confirmed'
                  AND (i.invoice_number LIKE ? OR c.name LIKE ? OR i.id = ?)
                ORDER BY i.invoice_date DESC LIMIT 15");
            $st->execute([$like, $like, $q !== '' && ctype_digit($q) ? (int) $q : 0]);
            $invoices = $st->fetchAll();

            echo json_encode(['ok' => true, 'invoices' => $invoices]);
            exit;
        }

        // بنود فاتورة معيّنة + الكمية المتبقية القابلة للإرجاع لكل بند
        if ($act === 'get_invoice_items') {
            $invId = (int) ($_POST['invoice_id'] ?? 0);
            if (!$invId) { echo json_encode(['ok' => false, 'msg' => 'فاتورة غير محددة']); exit; }

            $st = $pdo->prepare("SELECT
                    ii.id AS invoice_item_id, ii.product_id, ii.variant_id,
                    ii.quantity, ii.unit_price AS gross_unit_price, ii.total_price, ii.discount_percentage,
                    pr.name AS product_name, pr.model_number AS model_number,
                    sz.size AS size, sz.age_type AS age_type, cl.name AS color,
                    COALESCE((
                        SELECT SUM(ri.quantity_returned)
                        FROM `{$TRI}` ri
                        JOIN `{$TR}` r ON r.id = ri.return_id
                        WHERE ri.invoice_item_id = ii.id AND r.status != 'cancelled'
                    ), 0) AS already_returned
                FROM `{$TII}` ii
                LEFT JOIN `{$TV}` v ON v.id = ii.variant_id
                LEFT JOIN `{$TPROD}` pr ON pr.id = ii.product_id
                LEFT JOIN `{$TSZ}` sz ON sz.id = v.size_id
                LEFT JOIN `{$TCL}` cl ON cl.id = v.color_id
                WHERE ii.invoice_id = ?");
            $st->execute([$invId]);
            $items = $st->fetchAll();
            foreach ($items as &$it) {
                $it['returnable_qty'] = max(0, (float) $it['quantity'] - (float) $it['already_returned']);
                $it['display_name'] = trim(($it['product_name'] ?? '') . ' ' . ($it['size'] ?? '') . ' ' . ($it['color'] ?? ''));
                $it['unit_price'] = $it['quantity'] > 0
                    ? round((float) $it['total_price'] / (float) $it['quantity'], 4)
                    : (float) $it['gross_unit_price'];
            }
            echo json_encode(['ok' => true, 'items' => $items]);
            exit;
        }

        // حفظ مسودة مرتجع (إنشاء أو تعديل — بس إذا كانت لسا draft)
        if ($act === 'save_return') {
            requirePermission('sales.returns', 'create');
            $returnId = (int) ($_POST['return_id'] ?? 0);
            $invId    = (int) ($_POST['invoice_id'] ?? 0);
            $reason   = trim($_POST['return_reason'] ?? '');
            $payHandling = $_POST['payment_handling'] ?? 'not_paid';
            // ⚠ نمط "الحساب المستهدف" الجديد (مطابق لمرتجعات المشتريات):
            // not_paid = بلا حساب مستهدف إطلاقاً (يُصفَّر سيادياً بالسيرفر).
            // partial/paid_full = حساب مستهدف إجباري (صندوق/ذمة/دفعة مقدمة).
            $targetType = in_array($payHandling, ['partial', 'paid_full'], true)
                ? ($_POST['target_account_type'] ?? '') : null;
            if (in_array($payHandling, ['partial', 'paid_full'], true) && !$targetType) {
                echo json_encode(['ok' => false, 'msg' => 'اختر الحساب المستهدف (صندوق/بنك، ذمة العميل، أو دفعة مقدمة)']); exit;
            }
            $refundAccId = $targetType === 'cash' ? ((int) ($_POST['refund_account_id'] ?? 0) ?: null) : null;
            if ($targetType === 'cash' && !$refundAccId) {
                echo json_encode(['ok' => false, 'msg' => 'اختر حساب الصندوق/البنك']); exit;
            }
            $notes    = trim($_POST['notes'] ?? '');
            $lines    = json_decode($_POST['lines'] ?? '[]', true) ?: [];

            if (!$invId || empty($lines)) {
                echo json_encode(['ok' => false, 'msg' => 'اختر الفاتورة وبند واحد على الأقل']); exit;
            }

            $inv = $pdo->prepare("SELECT i.*, c.name AS customer_name FROM `{$TI}` i
                JOIN `{$TC}` c ON c.id=i.customer_id WHERE i.id=?");
            $inv->execute([$invId]);
            $inv = $inv->fetch();
            if (!$inv) { echo json_encode(['ok' => false, 'msg' => 'الفاتورة الأصلية غير موجودة']); exit; }

            $totalAmt = 0;
            $lineData = [];
            foreach ($lines as $l) {
                $iiId = (int) ($l['invoice_item_id'] ?? 0);
                $qty  = (float) ($l['quantity_returned'] ?? 0);
                if (!$iiId || $qty <= 0) continue;

                $ii = $pdo->prepare("SELECT * FROM `{$TII}` WHERE id=? AND invoice_id=?");
                $ii->execute([$iiId, $invId]);
                $ii = $ii->fetch();
                if (!$ii) continue;

                $already = $pdo->prepare("SELECT COALESCE(SUM(ri.quantity_returned),0)
                    FROM `{$TRI}` ri JOIN `{$TR}` r ON r.id=ri.return_id
                    WHERE ri.invoice_item_id=? AND r.status != 'cancelled' AND r.id != ?");
                $already->execute([$iiId, $returnId ?: 0]);
                $returnable = (float) $ii['quantity'] - (float) $already->fetchColumn();
                if ($qty > $returnable) {
                    echo json_encode(['ok' => false, 'msg' => "الكمية المطلوب إرجاعها أكبر من المتاح لبند رقم {$iiId}"]); exit;
                }

                $netUnitPrice = (float) $ii['quantity'] > 0
                    ? round((float) $ii['total_price'] / (float) $ii['quantity'], 4)
                    : (float) $ii['unit_price'];

                $lineTotal = $qty * $netUnitPrice;
                $totalAmt += $lineTotal;
                $lineData[] = [
                    'invoice_item_id'   => $iiId,
                    'product_id'        => $ii['product_id'],
                    'variant_id'        => $ii['variant_id'],
                    'product_name'      => $l['display_name'] ?? ('بند #' . $iiId),
                    'quantity_returned' => $qty,
                    'unit_price'        => $netUnitPrice,
                    'total_price'       => $lineTotal,
                ];
            }
            if (empty($lineData)) { echo json_encode(['ok' => false, 'msg' => 'لا يوجد بند صالح للحفظ']); exit; }

            $invTotal = (float) $inv['total_amount'];
            $discAmt = 0.0;
            $taxAmt = 0.0;
            if ($invTotal > 0) {
                $discRatio = ((float) $inv['discount_amount']) / $invTotal;
                $taxBaseInv = $invTotal - (float) $inv['discount_amount'];
                $taxRatio = $taxBaseInv > 0 ? ((float) $inv['tax_amount']) / $taxBaseInv : 0;
                $discAmt = round($totalAmt * $discRatio, 2);
                $taxAmt = round(($totalAmt - $discAmt) * $taxRatio, 2);
            }
            $returnAmt = round($totalAmt - $discAmt + $taxAmt, 2);

            $pdo->beginTransaction();
            try {
                if ($returnId) {
                    $chk = $pdo->prepare("SELECT status FROM `{$TR}` WHERE id=?");
                    $chk->execute([$returnId]);
                    if ($chk->fetchColumn() !== 'draft') {
                        throw new Exception('لا يمكن تعديل مرتجع مؤكد أو ملغى');
                    }
                    $pdo->prepare("UPDATE `{$TR}` SET invoice_id=?, customer_id=?, customer_name=?,
                            invoice_number=?, warehouse_id=?, return_date=CURDATE(),
                            total_amount=?, discount_amount=?, tax_amount=?, return_amount=?,
                            return_currency_id=?, base_currency_id=?,
                            exchange_rate=?, payment_handling=?, target_account_type=?, refund_account_id=?, return_reason=?, notes=?
                        WHERE id=? AND status='draft'")
                        ->execute([
                            $invId, $inv['customer_id'], $inv['customer_name'], $inv['invoice_number'], $inv['warehouse_id'],
                            $totalAmt, $discAmt, $taxAmt, $returnAmt, $inv['invoice_currency_id'], $inv['base_currency_id'],
                            $inv['exchange_rate'], $payHandling, $targetType, $refundAccId, $reason, $notes, $returnId
                        ]);
                    $pdo->prepare("DELETE FROM `{$TRI}` WHERE return_id=?")->execute([$returnId]);
                } else {
                    $retNo = genReturnNo($pdo, $TR);
                    $pdo->prepare("INSERT INTO `{$TR}`
                            (return_number, invoice_id, invoice_number, customer_id, customer_name, warehouse_id,
                             return_date, total_amount, discount_amount, tax_amount, return_amount,
                             return_currency_id, base_currency_id,
                             exchange_rate, payment_handling, target_account_type, refund_account_id, return_reason, status, notes, user_id)
                            VALUES (?,?,?,?,?,?, CURDATE(),?,?,?,?,?,?, ?,?,?,?,?, 'draft', ?, ?)")
                        ->execute([
                            $retNo, $invId, $inv['invoice_number'], $inv['customer_id'], $inv['customer_name'], $inv['warehouse_id'],
                            $totalAmt, $discAmt, $taxAmt, $returnAmt, $inv['invoice_currency_id'], $inv['base_currency_id'],
                            $inv['exchange_rate'], $payHandling, $targetType, $refundAccId, $reason, $notes, $_SESSION['user_id']
                        ]);
                    $returnId = (int) $pdo->lastInsertId();
                }

                foreach ($lineData as $ld) {
                    $pdo->prepare("INSERT INTO `{$TRI}`
                            (return_id, invoice_item_id, product_id, variant_id, product_name,
                             quantity_returned, unit_price, total_price)
                            VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([
                            $returnId, $ld['invoice_item_id'], $ld['product_id'], $ld['variant_id'],
                            $ld['product_name'], $ld['quantity_returned'], $ld['unit_price'], $ld['total_price']
                        ]);
                }

                $pdo->commit();
                echo json_encode(['ok' => true, 'msg' => 'تم حفظ المرتجع كمسودة', 'return_id' => $returnId]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            }
            exit;
        }

        // جلب مرتجع كامل (للعرض/التعديل)
        if ($act === 'get_return') {
            $id = (int) ($_POST['id'] ?? 0);
            $r = $pdo->prepare("SELECT r.*, c.name AS customer_name_live, cur.code AS currency_code,
                    cur.symbol AS currency_symbol, bc.code AS base_currency_code,
                    i.total_amount, i.discount_amount AS inv_discount_amount,
                    i.tax_amount AS inv_tax_amount, i.final_amount, i.payment_status
                FROM `{$TR}` r LEFT JOIN `{$TC}` c ON c.id=r.customer_id
                LEFT JOIN currencies cur ON cur.id=r.return_currency_id
                LEFT JOIN currencies bc ON bc.id=r.base_currency_id
                LEFT JOIN `{$TI}` i ON i.id=r.invoice_id
                WHERE r.id=?");
            $r->execute([$id]);
            $r = $r->fetch();
            if (!$r) { echo json_encode(['ok' => false, 'msg' => 'غير موجود']); exit; }
            $items = $pdo->prepare("SELECT * FROM `{$TRI}` WHERE return_id=?");
            $items->execute([$id]);
            echo json_encode(['ok' => true, 'return' => $r, 'items' => $items->fetchAll()]);
            exit;
        }

        // حذف مسودة (draft فقط)
        if ($act === 'delete_return') {
            requirePermission('sales.returns', 'delete');
            $id = (int) ($_POST['id'] ?? 0);
            $chk = $pdo->prepare("SELECT status FROM `{$TR}` WHERE id=?");
            $chk->execute([$id]);
            if ($chk->fetchColumn() !== 'draft') {
                echo json_encode(['ok' => false, 'msg' => 'لا يمكن حذف مرتجع مؤكد أو ملغى']); exit;
            }
            $pdo->prepare("DELETE FROM `{$TR}` WHERE id=? AND status='draft'")->execute([$id]);
            echo json_encode(['ok' => true, 'msg' => 'تم الحذف']);
            exit;
        }

        echo json_encode(['ok' => false, 'msg' => 'إجراء غير معروف']);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── عرض الصفحة ──────────────────────────────────────────────────
$search   = trim($_GET['q'] ?? '');
$status   = $_GET['status'] ?? '';
$custF    = (int) ($_GET['customer'] ?? 0);
$dateFrom = $_GET['from'] ?? '';
$dateTo   = $_GET['to'] ?? '';

$where = [];
$params = [];
if ($search !== '') {
    $where[] = "(r.return_number LIKE ? OR r.invoice_number LIKE ? OR r.customer_name LIKE ?)";
    $like = "%{$search}%";
    array_push($params, $like, $like, $like);
}
if ($status !== '') { $where[] = "r.status = ?"; $params[] = $status; }
if ($custF) { $where[] = "r.customer_id = ?"; $params[] = $custF; }
if ($dateFrom !== '') { $where[] = "r.return_date >= ?"; $params[] = $dateFrom; }
if ($dateTo !== '') { $where[] = "r.return_date <= ?"; $params[] = $dateTo; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT r.*, c.name AS customer_name_live, cur.code AS currency_code, cur.symbol AS currency_symbol
    FROM `{$TR}` r
    LEFT JOIN `{$TC}` c ON c.id = r.customer_id
    LEFT JOIN currencies cur ON cur.id = r.return_currency_id
    {$whereSql}
    ORDER BY r.id DESC";
$st = $pdo->prepare($sql);
$st->execute($params);
$returns = $st->fetchAll();

// قائمة العملاء لفلتر البحث — مطابق تماماً لفلتر "كل الموردين" بالمشتريات
$allCustomers = $pdo->query("SELECT id, name FROM `{$TC}` WHERE status='active' ORDER BY name")->fetchAll();

$STATUS_MAP = [
    'draft'     => ['label' => 'مسودة', 'cls' => 'bg-secondary-subtle text-secondary'],
    'posted'    => ['label' => 'مؤكد', 'cls' => 'bg-success-subtle text-success'],
    'cancelled' => ['label' => 'ملغى', 'cls' => 'bg-danger-subtle text-danger'],
];

$baseCurSym = '$';
if (!empty($_SESSION['branch_id'])) {
    $bc = $pdo->prepare("SELECT c.symbol FROM branches b JOIN currencies c ON c.id=b.base_currency_id WHERE b.id=?");
    $bc->execute([$_SESSION['branch_id']]);
    $baseCurSym = $bc->fetchColumn() ?: '$';
}

$retStats = [
    'total'   => count($returns),
    'draft'   => count(array_filter($returns, fn($r) => $r['status'] === 'draft')),
    'posted'  => count(array_filter($returns, fn($r) => $r['status'] === 'posted')),
    'amount'  => array_sum(array_map(
        fn($r) => $r['status'] !== 'cancelled' ? (float) $r['return_amount'] / (float) ($r['exchange_rate'] ?: 1) : 0,
        $returns
    )),
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مرتجعات المبيعات — FATORIZE</title>
    <link rel="icon" type="image/png" href="<?= BASE_PATH ?>/assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/assets/css/layout.css" rel="stylesheet">
    <style>
        .n { font-variant-numeric: tabular-nums }

        .act-btn {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            border: 1px solid #e2e8f0;
            background: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .8rem;
            color: #64748b;
            cursor: pointer;
            transition: all .12s;
            text-decoration: none
        }

        .act-btn:hover {
            background: #f1f5f9
        }

        .act-btn.success-h:hover {
            background: #dcfce7;
            color: #16a34a;
            border-color: #86efac
        }

        .act-btn.danger:hover {
            background: #fee2e2;
            color: #dc2626;
            border-color: #fca5a5
        }

        .act-btn.info-h:hover {
            background: #e0f2fe;
            color: #0891b2;
            border-color: #7dd3fc
        }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-arrow-return-right me-1 text-success"></i>مرتجعات المبيعات</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
            <span>المبيعات</span>
            <i class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
            <span class="text-success fw-600">مرتجعات المبيعات</span>
        </nav>
    </header>

    <main class="main-content">
        <div class="content-body">

            <ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e2e8f0">
                <li class="nav-item">
                    <a class="nav-link fw-600" href="customers.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-people me-1"></i>العملاء
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="sales_index.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-receipt me-1"></i>الفواتير
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600 active" href="returns.php"
                        style="border:none;border-bottom:2px solid #16a34a;color:#16a34a;font-size:.83rem;margin-bottom:-2px">
                        <i class="bi bi-arrow-return-right me-1"></i>مرتجعات المبيعات
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="orders.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-file-earmark-text me-1"></i>أوامر البيع / عروض الأسعار
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="reports.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-bar-chart me-1"></i>التقارير
                    </a>
                </li>
            </ul>

            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#f0fdf4"><i
                                class="bi bi-arrow-return-right text-success"></i></div>
                        <div>
                            <div class="stat-val"><?= $retStats['total'] ?></div>
                            <div class="stat-lbl">إجمالي المرتجعات</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fef3c7"><i
                                class="bi bi-hourglass text-warning"></i></div>
                        <div>
                            <div class="stat-val"><?= $retStats['draft'] ?></div>
                            <div class="stat-lbl">مسودات</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#f0fdf4"><i
                                class="bi bi-check-circle text-success"></i></div>
                        <div>
                            <div class="stat-val"><?= $retStats['posted'] ?></div>
                            <div class="stat-lbl">مؤكدة</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fee2e2"><i
                                class="bi bi-currency-dollar text-danger"></i></div>
                        <div>
                            <div class="stat-val n"><?= number_format($retStats['amount'], 2) ?> <?= htmlspecialchars($baseCurSym) ?></div>
                            <div class="stat-lbl">إجمالي قيمة المرتجعات (بعملة الفرع)</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tbl-wrap">
                <div class="tbl-hdr">
                    <span style="font-size:.88rem;font-weight:700;color:#1e293b">
                        <i class="bi bi-list-ul me-1 text-success"></i>سجل مرتجعات المبيعات
                    </span>
                    <div class="d-flex gap-2 ms-auto flex-wrap align-items-center">
                        <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
                            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                                placeholder="رقم المرتجع أو الفاتورة أو العميل..." class="form-control form-control-sm"
                                style="width:200px;border-radius:8px">
                            <select name="status" class="form-select form-select-sm"
                                style="width:120px;border-radius:8px" onchange="this.form.submit()">
                                <option value="">كل الحالات</option>
                                <?php foreach ($STATUS_MAP as $k => $v): ?>
                                            <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>>
                                                <?= $v['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="customer" class="form-select form-select-sm"
                                style="width:150px;border-radius:8px" onchange="this.form.submit()">
                                <option value="">كل العملاء</option>
                                <?php foreach ($allCustomers as $ac): ?>
                                            <option value="<?= $ac['id'] ?>" <?= $custF === (int) $ac['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($ac['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>"
                                class="form-control form-control-sm" style="width:140px;border-radius:8px">
                            <span style="font-size:.8rem;color:#94a3b8">—</span>
                            <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>"
                                class="form-control form-control-sm" style="width:140px;border-radius:8px">
                            <button type="submit" class="btn btn-sm btn-success" style="border-radius:8px">
                                <i class="bi bi-search me-1"></i>بحث
                            </button>
                            <?php if ($search || $status || $custF || $dateFrom || $dateTo): ?>
                                        <a href="returns.php" class="btn btn-sm btn-light" style="border-radius:8px">
                                            <i class="bi bi-x-lg me-1"></i>مسح
                                        </a>
                            <?php endif; ?>
                        </form>
                        <button class="btn btn-sm fw-600" onclick="openNewReturn()"
                            style="border-radius:9px;background:#16a34a;color:#fff;font-size:.82rem">
                            <i class="bi bi-plus-lg me-1"></i>مرتجع جديد
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="mtbl" id="returnsTbl">
                        <thead>
                            <tr>
                                <th style="color:#16a34a">رقم المرتجع</th>
                                <th>التاريخ</th>
                                <th style="color:#16a34a">العميل</th>
                                <th>الفاتورة الأصلية</th>
                                <th>القيمة</th>
                                <th>طريقة التسوية</th>
                                <th>الحالة</th>
                                <th style="text-align:center" data-no-sort>إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($returns)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-5">
                                                <i class="bi bi-arrow-return-right d-block mb-2"
                                                    style="font-size:2rem;opacity:.2"></i>
                                                لا توجد مرتجعات<?= $search ? " تطابق \"{$search}\"" : '' ?>
                                            </td>
                                        </tr>
                            <?php endif; ?>
                            <?php foreach ($returns as $r):
                                $st2 = $STATUS_MAP[$r['status']] ?? $STATUS_MAP['draft'];
                                $sym = $r['currency_symbol'] ?? '$';
                                $payLabels = [
                                    'not_paid' => 'لم تُسوَّ', 'partial' => 'تسوية جزئية', 'paid_full' => 'تسوية كاملة',
                                ];
                                $targetLabels = [
                                    'cash' => 'استرداد نقدي', 'receivable' => 'خصم من ذمة العميل', 'prepaid' => 'دفعة مقدمة',
                                ];
                                $payDisplay = $payLabels[$r['payment_handling']] ?? '—';
                                if (!empty($r['target_account_type']) && isset($targetLabels[$r['target_account_type']])) {
                                    $payDisplay .= ' — ' . $targetLabels[$r['target_account_type']];
                                }
                                ?>
                                        <tr>
                                            <td class="n fw-600" style="direction:ltr;color:#16a34a">
                                                <?= htmlspecialchars($r['return_number'] ?? '—') ?>
                                            </td>
                                            <td class="text-muted"><?= $r['return_date'] ?></td>
                                            <td style="color:#16a34a" class="fw-600">
                                                <?= htmlspecialchars($r['customer_name_live'] ?? $r['customer_name'] ?? '—') ?>
                                            </td>
                                            <td class="text-muted" style="font-size:.8rem">
                                                <?= htmlspecialchars($r['invoice_number'] ?? '—') ?>
                                            </td>
                                            <td class="n fw-600"><?= number_format((float) $r['return_amount'], 2) ?>
                                                <?= $sym ?></td>
                                            <td style="font-size:.78rem"><?= htmlspecialchars($payDisplay) ?></td>
                                            <td><span class="badge <?= $st2['cls'] ?>"
                                                    style="font-size:.68rem"><?= $st2['label'] ?></span></td>
                                            <td>
                                                <div class="d-flex gap-1 justify-content-center">
                                                    <button class="act-btn info-h" onclick="viewReturn(<?= $r['id'] ?>)"
                                                        title="عرض"><i class="bi bi-eye"></i></button>
                                                    <button class="act-btn" onclick="printSaleReturn(<?= $r['id'] ?>)"
                                                        title="طباعة"><i class="bi bi-printer"></i></button>
                                                    <?php if ($r['status'] === 'draft'): ?>
                                                                <button class="act-btn" onclick="editReturn(<?= $r['id'] ?>)"
                                                                    title="تعديل"><i class="bi bi-pencil"></i></button>
                                                                <button class="act-btn success-h"
                                                                    onclick="confirmReturn(<?= $r['id'] ?>,'<?= htmlspecialchars($r['return_number'], ENT_QUOTES) ?>')"
                                                                    title="تأكيد"><i
                                                                        class="bi bi-check-circle"></i></button>
                                                                <button class="act-btn danger"
                                                                    onclick="deleteReturn(<?= $r['id'] ?>,'<?= htmlspecialchars($r['return_number'], ENT_QUOTES) ?>')"
                                                                    title="حذف"><i class="bi bi-trash"></i></button>
                                                    <?php elseif ($r['status'] === 'posted'): ?>
                                                                <button class="act-btn danger"
                                                                    onclick="cancelReturn(<?= $r['id'] ?>,'<?= htmlspecialchars($r['return_number'], ENT_QUOTES) ?>')"
                                                                    title="إلغاء"><i class="bi bi-x-circle"></i></button>
                                                    <?php endif; ?>
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

    <div class="modal fade" id="retModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl" style="max-width:1100px">
            <div class="modal-content" style="border-radius:14px">
                <div class="modal-header">
                    <h6 class="modal-title fw-700" id="retModalTitle"><i
                            class="bi bi-arrow-return-right me-1 text-success"></i>مرتجع بيع جديد</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="rReturnId" value="">
                    <input type="hidden" id="rInvoiceId" value="">

                    <div id="stepFindInvoice">
                        <label class="form-label small fw-600">اختر الفاتورة الأصلية</label>
                        <select id="rInvoiceSelect" class="form-select form-select-sm mb-2" style="border-radius:8px"
                            onchange="onInvoiceSelectChange()">
                            <option value="">— جارٍ التحميل... —</option>
                        </select>
                        <div class="d-flex gap-2 mb-2">
                            <input type="text" id="rSearchInvoice" class="form-control form-control-sm"
                                placeholder="فلترة بالرقم أو اسم العميل..." style="border-radius:8px"
                                oninput="searchInvoiceForReturn()">
                        </div>
                    </div>

                    <div id="stepItems" style="display:none">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div style="font-size:.85rem">
                                <span class="fw-700" id="rSelCustomer"></span> —
                                <span class="text-muted" id="rSelInvoiceNo"></span>
                            </div>
                            <button class="btn btn-sm btn-light" style="border-radius:8px"
                                onclick="resetInvoiceSelection()"><i class="bi bi-arrow-right me-1"></i>تغيير
                                الفاتورة</button>
                        </div>
                        <div class="table-responsive">
                            <table class="mtbl" style="font-size:.82rem">
                                <thead>
                                    <tr>
                                        <th>المنتج</th>
                                        <th style="min-width:110px">القياس</th>
                                        <th style="color:#16a34a">اللون</th>
                                        <th class="text-center">المباعة</th>
                                        <th class="text-center">المتاح للإرجاع</th>
                                        <th style="width:130px;color:#dc2626" class="text-center">الكمية المرتجعة</th>
                                        <th class="text-center">سعر الوحدة</th>
                                        <th class="text-end">الإجمالي</th>
                                    </tr>
                                </thead>
                                <tbody id="rItemsBody"></tbody>
                            </table>
                        </div>

                        <div class="row g-3 mt-2">
                            <div class="col-md-4">
                                <label class="form-label small fw-600">سبب الإرجاع</label>
                                <input type="text" id="rReason" class="form-control form-control-sm"
                                    style="border-radius:8px" placeholder="عيب بالمنتج، خطأ بالمقاس...">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-600">طريقة التسوية</label>
                                <select id="rPayHandling" class="form-select form-select-sm" style="border-radius:8px"
                                    onchange="onRPayHandlingChange()">
                                    <option value="not_paid">لم تُسوَّ بعد</option>
                                    <option value="partial">تسوية جزئية</option>
                                    <option value="paid_full">تسوية كاملة</option>
                                </select>
                            </div>
                            <div class="col-md-4" id="rTargetTypeWrap" style="display:none">
                                <label class="form-label small fw-600">الحساب المستهدف</label>
                                <select id="rTargetType" class="form-select form-select-sm" style="border-radius:8px"
                                    onchange="onRTargetTypeChange()">
                                    <option value="">— اختر —</option>
                                    <option value="cash">استرداد نقدي (صندوق/بنك)</option>
                                    <option value="receivable">خصم من ذمة العميل</option>
                                    <option value="prepaid">تحويل لرصيد دائن (دفعة مقدمة)</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-600">ملاحظات</label>
                                <input type="text" id="rNotes" class="form-control form-control-sm"
                                    style="border-radius:8px">
                            </div>
                            <div class="col-md-4" id="rRefundAccWrap" style="display:none">
                                <label class="form-label small fw-600">
                                    <i class="bi bi-safe me-1"></i>حساب استرداد المبلغ (صندوق/بنك)
                                </label>
                                <select id="rRefundAccount" class="form-select form-select-sm" style="border-radius:8px">
                                    <option value="">— اختر حساب الاسترداد —</option>
                                    <?php foreach ($cashAccounts as $ca): ?>
                                                <option value="<?= $ca['id'] ?>"
                                                    data-cur="<?= htmlspecialchars($ca['cur_code'] ?? '') ?>">
                                                    <?= htmlspecialchars($ca['code'] . ' — ' . $ca['name']) ?>
                                                    (<?= htmlspecialchars($ca['cur_sym'] ?? '') ?>)
                                                </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text" style="font-size:.7rem">تُفلتَر تلقائياً حسب عملة المرتجع
                                </div>
                            </div>
                        </div>

                        <div class="mt-3" style="background:#f8fafc;border-radius:10px;padding:12px 14px;font-size:.82rem">
                            <div class="row g-2 mb-2 pb-2" style="border-bottom:1px solid #e2e8f0">
                                <div class="col-6 col-md-3">
                                    <small style="color:#64748b">عملة الفاتورة</small>
                                    <div class="fw-600" id="rInvCurLbl">—</div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <small style="color:#64748b">سعر الصرف عند الإنشاء</small>
                                    <div class="fw-600" style="color:#7c3aed" id="rExRateLbl">—</div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <small style="color:#64748b">حالة دفع الفاتورة الأصلية</small>
                                    <div class="fw-600" id="rPayStatusLbl">—</div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <small style="color:#64748b">صافي الفاتورة الأصلية</small>
                                    <div class="fw-600" id="rInvNetLbl">—</div>
                                </div>
                            </div>
                            <div class="row g-2">
                                <div class="col-6 col-md-3">
                                    <small style="color:#64748b">المبلغ الفرعي المرتجع</small>
                                    <div class="fw-600" id="rSubtotalLbl">0.00</div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <small style="color:#64748b">الخصم المتناسب</small>
                                    <div class="fw-600 text-danger" id="rDiscLbl">-0.00</div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <small style="color:#64748b">الضريبة المتناسبة</small>
                                    <div class="fw-600" id="rTaxLbl">+0.00</div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <small style="color:#64748b">صافي المرتجع</small>
                                    <div class="fw-700" style="color:#065f46;font-size:.95rem" id="rTotalLine">0.00</div>
                                </div>
                            </div>
                            <div class="form-text mt-1" style="font-size:.7rem">
                                <i class="bi bi-info-circle me-1"></i>الخصم والضريبة محسوبان بنفس نسبتهما على الفاتورة
                                الأصلية، مطبَّقين على قيمة البنود المرتجعة فقط — لا يُعاد حسابهما يدوياً هون.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal"
                        style="border-radius:8px">إلغاء</button>
                    <button type="button" class="btn btn-sm fw-600" style="border-radius:8px;background:#16a34a;color:#fff" id="btnSaveReturn"
                        onclick="saveReturn()" disabled>
                        <span id="saveRetTxt"><i class="bi bi-save me-1"></i>حفظ كمسودة</span>
                        <span id="saveRetSpin" class="spinner-border spinner-border-sm" style="display:none"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const sb = document.getElementById('sidebar'), ov = document.getElementById('sbOverlay');
        function sbOpen() { sb.classList.add('open'); ov.classList.add('show'); }
        function sbClose() { sb.classList.remove('open'); ov.classList.remove('show'); }
        window.addEventListener('resize', () => { if (window.innerWidth > 991) sbClose(); });
        function toggleGroup(g) { const o = g.classList.contains('open'); document.querySelectorAll('.sb-group.open').forEach(x => x.classList.remove('open')); g.classList.toggle('open', !o); localStorage.setItem('sb_open_' + g.dataset.key, (!o).toString()); }
        document.querySelectorAll('.sb-group').forEach(g => { if (localStorage.getItem('sb_open_' + g.dataset.key) === 'true') g.classList.add('open'); });

        const retModal = new bootstrap.Modal(document.getElementById('retModal'));
        let rItems = [];
        let _rSelectedCurrency = 'USD';

        function post(data) {
            const fd = new FormData();
            for (const k in data) fd.append(k, data[k]);
            return fetch('returns.php', { method: 'POST', body: fd }).then(r => r.json());
        }

        function toast(msg, type = 'success') {
            const el = document.createElement('div');
            el.className = `alert alert-${type === 'danger' ? 'danger' : 'success'} position-fixed`;
            el.style.cssText = 'top:20px;left:20px;z-index:9999;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12)';
            el.textContent = msg;
            document.body.appendChild(el);
            setTimeout(() => el.remove(), 3000);
        }

        function openNewReturn() {
            document.getElementById('rReturnId').value = '';
            document.getElementById('rInvoiceId').value = '';
            document.getElementById('rSearchInvoice').value = '';
            document.getElementById('rInvoiceSelect').innerHTML = '<option value="">— جارٍ التحميل... —</option>';
            document.getElementById('stepFindInvoice').style.display = '';
            document.getElementById('stepItems').style.display = 'none';
            document.getElementById('btnSaveReturn').disabled = true;
            document.getElementById('rPayHandling').value = 'not_paid';
            document.getElementById('rRefundAccWrap').style.display = 'none';
            document.getElementById('retModalTitle').innerHTML = '<i class="bi bi-arrow-return-right me-1 text-success"></i>مرتجع بيع جديد';
            retModal.show();
            searchInvoiceForReturn();
        }

        function resetInvoiceSelection() {
            document.getElementById('stepFindInvoice').style.display = '';
            document.getElementById('stepItems').style.display = 'none';
            document.getElementById('btnSaveReturn').disabled = true;
        }

        let _rSearchDebounce = null;
        let _rInvoicesMap = {};

        function searchInvoiceForReturn() {
            clearTimeout(_rSearchDebounce);
            _rSearchDebounce = setTimeout(() => {
                const q = document.getElementById('rSearchInvoice').value.trim();
                post({ _action: 'find_invoice', q }).then(d => {
                    const sel = document.getElementById('rInvoiceSelect');
                    _rInvoicesMap = {};
                    if (!d.ok || !d.invoices.length) {
                        sel.innerHTML = '<option value="">— لا نتائج —</option>';
                        return;
                    }
                    d.invoices.forEach(p => _rInvoicesMap[p.id] = p);
                    sel.innerHTML = '<option value="">— اختر فاتورة —</option>' + d.invoices.map(p =>
                        `<option value="${p.id}">${p.invoice_number} — ${p.customer_name} (${p.invoice_date})</option>`
                    ).join('');
                });
            }, 250);
        }

        function onInvoiceSelectChange() {
            const id = document.getElementById('rInvoiceSelect').value;
            if (!id || !_rInvoicesMap[id]) return;
            selectInvoice(_rInvoicesMap[id]);
        }

        let _rSelectedInvoice = null;

        function selectInvoice(p) {
            document.getElementById('rInvoiceId').value = p.id;
            document.getElementById('rSelCustomer').textContent = p.customer_name;
            document.getElementById('rSelInvoiceNo').textContent = p.invoice_number;
            document.getElementById('stepFindInvoice').style.display = 'none';
            document.getElementById('stepItems').style.display = '';
            _rSelectedCurrency = p.currency_code || 'USD';
            _rSelectedInvoice = p;
            onRPayHandlingChange();

            const PAY_LABELS = { paid: 'مدفوعة بالكامل', partial: 'مدفوعة جزئياً', pending: 'غير مدفوعة' };
            document.getElementById('rInvCurLbl').textContent = p.currency_code || '—';
            document.getElementById('rExRateLbl').textContent = p.base_currency_code && p.exchange_rate
                ? `1 ${p.base_currency_code} = ${Number(p.exchange_rate).toFixed(4)} ${p.currency_code}` : '—';
            document.getElementById('rPayStatusLbl').textContent = PAY_LABELS[p.payment_status] || p.payment_status || '—';
            document.getElementById('rInvNetLbl').textContent = p.final_amount
                ? Number(p.final_amount).toFixed(2) + ' ' + (p.currency_symbol || '') : '—';

            // ⚠ "استرداد نقدي" منطقي بس لو الفاتورة فعلاً كان فيها دفع
            // (وإلا مافي مبلغ نقدي حقيقي نرجّعه). نفس تحقق سابق، بس
            // مطبَّق على خيار target_account_type='cash' هلق بدل
            // payment_handling القديم.
            const cashOpt = document.querySelector('#rTargetType option[value="cash"]');
            const hadPayment = p.payment_status === 'paid' || p.payment_status === 'partial';
            if (cashOpt) {
                cashOpt.disabled = !hadPayment;
                cashOpt.textContent = hadPayment ? 'استرداد نقدي (صندوق/بنك)' : 'استرداد نقدي (الفاتورة غير مدفوعة أصلاً)';
                if (!hadPayment && document.getElementById('rTargetType').value === 'cash') {
                    document.getElementById('rTargetType').value = '';
                    onRTargetTypeChange();
                }
            }

            post({ _action: 'get_invoice_items', invoice_id: p.id }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                rItems = d.items;
                renderItemsTable();
            });
        }

        // ⚠ نمط "الحساب المستهدف" الجديد (مطابق لمرتجعات المشتريات):
        // not_paid = بلا حساب مستهدف إطلاقاً. partial/paid_full = لازم
        // يختار نوع الحساب (صندوق/ذمة/دفعة مقدمة)، وحساب الصندوق نفسه
        // بس يظهر لو اختار "صندوق/بنك" تحديداً.
        function onRPayHandlingChange() {
            const val = document.getElementById('rPayHandling').value;
            const needsTarget = val === 'partial' || val === 'paid_full';
            document.getElementById('rTargetTypeWrap').style.display = needsTarget ? '' : 'none';
            if (!needsTarget) {
                document.getElementById('rTargetType').value = '';
                onRTargetTypeChange();
            }
        }

        function onRTargetTypeChange() {
            const wrap = document.getElementById('rRefundAccWrap');
            const isCash = document.getElementById('rTargetType').value === 'cash';
            wrap.style.display = isCash ? '' : 'none';
            if (isCash) {
                const sel = document.getElementById('rRefundAccount');
                let stillValid = false;
                Array.from(sel.options).forEach(opt => {
                    if (!opt.value) { opt.style.display = ''; return; }
                    const show = opt.dataset.cur === _rSelectedCurrency;
                    opt.style.display = show ? '' : 'none';
                    if (show && opt.value === sel.value) stillValid = true;
                });
                if (!stillValid) sel.value = '';
            }
        }

        // ⚠ عرض المقاسات كمدى (أصغر-أكبر) بدل قائمة كاملة — أوضح
        // وأقصر لما الكروب فيه أكتر من مقاسين متتاليين (مثلاً "٢-٥"
        // بدل "٢ · ٣ · ٤ · ٥")، مع نوع العمر (سنة/شهر) جنبه.
        function formatSizeRange(sizes, ageType) {
            const nums = sizes.map(s => parseFloat(s)).filter(n => !isNaN(n));
            let label;
            if (nums.length === sizes.length && nums.length > 0) {
                const min = Math.min(...nums), max = Math.max(...nums);
                label = min === max ? String(min) : `${min}-${max}`;
            } else {
                label = sizes.join(' · ') || '—'; // مقاسات نصية (S/M/L مثلاً) — تبقى كما هي
            }
            return ageType ? `${label} ${ageType}` : label;
        }

        // ⚠ دمج بنود المرتجع بحسب الكروب (منتج+سعر+لون) — نفس منطق
        // التجميع المستخدم بمودالي التفاصيل/التأكيد بفاتورة البيع
        // بالضبط. "الكمية المرتجعة" هون تمثّل نفس القيمة (عدد كروبات)
        // تُطبَّق على كل مقاس بالكروب على حدة، بحد أقصى = أقل قيمة
        // "متاح للإرجاع" بين كل المقاسات المدموجة (حتى ما نرجّع أكتر
        // مما هو متاح فعلياً لأي مقاس بالكروب). بلا مربع اختيار منفصل
        // — الكتابة بالحقل مباشرة تُفعّل السطر (أي قيمة > 0 = مُختار).
        let rGroups = [];

        function renderItemsTable() {
            const map = {};
            rItems.forEach(it => {
                const key = (it.product_id || it.item_name) + '_' + it.unit_price + '_' + (it.color || '');
                if (!map[key]) map[key] = {
                    product_name: it.product_name, model_number: it.model_number || '',
                    color: it.color || '—', unit_price: parseFloat(it.unit_price),
                    sizes: [], members: []
                };
                if (it.size && !map[key].sizes.includes(it.size)) map[key].sizes.push(it.size);
                if (!map[key].age_type) map[key].age_type = it.age_type || '';
                map[key].members.push({
                    invoice_item_id: it.invoice_item_id,
                    quantity: parseFloat(it.quantity),
                    returnable_qty: parseFloat(it.returnable_qty),
                    display_name: it.display_name
                });
            });
            rGroups = Object.values(map).map(g => ({
                ...g,
                totalQty: g.members.reduce((s, m) => s + m.quantity, 0),
                minReturnable: Math.min(...g.members.map(m => m.returnable_qty)),
                qty: 0
            }));

            const tbody = document.getElementById('rItemsBody');
            tbody.innerHTML = rGroups.map((g, i) => `
                <tr>
                    <td>
                        <div class="fw-600">${g.product_name || '—'}</div>
                        <div class="text-muted" style="font-size:.72rem" dir="ltr">${g.model_number}</div>
                    </td>
                    <td style="font-size:.8rem;font-weight:600" dir="ltr">${formatSizeRange(g.sizes, g.age_type)}</td>
                    <td style="color:#16a34a" class="fw-600">${g.color}</td>
                    <td class="n text-center">${g.totalQty}</td>
                    <td class="n text-center ${g.minReturnable <= 0 ? 'text-muted' : 'text-success fw-600'}">${g.minReturnable}</td>
                    <td style="background:#fef2f2"><input type="number" class="form-control form-control-sm" id="qty${i}" min="0"
                        max="${g.minReturnable}" step="0.01" placeholder="0"
                        ${g.minReturnable <= 0 ? 'disabled' : ''}
                        oninput="onQtyChange(${i})" style="border-radius:6px;border-color:#fecaca;text-align:center"></td>
                    <td class="n text-center">${g.unit_price.toFixed(2)}</td>
                    <td class="n text-end fw-600" id="lineTotal${i}">0.00</td>
                </tr>`).join('');
            updateTotal();
        }

        function onQtyChange(i) {
            const g = rGroups[i];
            let qty = parseFloat(document.getElementById(`qty${i}`).value) || 0;
            if (qty > g.minReturnable) { qty = g.minReturnable; document.getElementById(`qty${i}`).value = qty; }
            if (qty < 0) { qty = 0; document.getElementById(`qty${i}`).value = ''; }
            g.qty = qty;
            // إجمالي الكروب = عدد الكروبات المرتجعة × سعر القطعة × عدد
            // المقاسات المدموجة — نفس صيغة "عدد الكروبات" المعتمدة بكل
            // مكان تاني بالنظام.
            const lineTotal = qty * g.unit_price * (g.sizes.length || 1);
            document.getElementById(`lineTotal${i}`).textContent = lineTotal.toFixed(2);
            updateTotal();
        }

        function updateTotal() {
            let subtotal = 0, anySelected = false;
            rGroups.forEach(g => {
                if (g.qty > 0) {
                    anySelected = true;
                    subtotal += g.qty * g.unit_price * (g.sizes.length || 1);
                }
            });

            let discAmt = 0, taxAmt = 0;
            const p = _rSelectedInvoice;
            if (p && parseFloat(p.total_amount) > 0) {
                const discRatio = (parseFloat(p.inv_discount_amount) || 0) / parseFloat(p.total_amount);
                const taxBaseInv = parseFloat(p.total_amount) - (parseFloat(p.inv_discount_amount) || 0);
                const taxRatio = taxBaseInv > 0 ? (parseFloat(p.inv_tax_amount) || 0) / taxBaseInv : 0;
                discAmt = subtotal * discRatio;
                taxAmt = (subtotal - discAmt) * taxRatio;
            }
            const net = subtotal - discAmt + taxAmt;
            const sym = p?.currency_symbol || '';

            document.getElementById('rSubtotalLbl').textContent = subtotal.toFixed(2) + ' ' + sym;
            document.getElementById('rDiscLbl').textContent = '-' + discAmt.toFixed(2) + ' ' + sym;
            document.getElementById('rTaxLbl').textContent = '+' + taxAmt.toFixed(2) + ' ' + sym;
            document.getElementById('rTotalLine').textContent = net.toFixed(2) + ' ' + sym;
            document.getElementById('btnSaveReturn').disabled = !anySelected;
        }

        function saveReturn() {
            const lines = [];
            rGroups.forEach(g => {
                if (g.qty > 0) {
                    // ⚠ نفس القيمة (عدد الكروبات) تُطبَّق على كل مقاس
                    // بالكروب على حدة — مطابق لمنطق "عدد الكروبات" بفاتورة
                    // البيع بالضبط.
                    g.members.forEach(m => {
                        lines.push({
                            invoice_item_id: m.invoice_item_id,
                            quantity_returned: g.qty,
                            display_name: m.display_name
                        });
                    });
                }
            });
            if (!lines.length) { toast('اختر بند واحد على الأقل', 'danger'); return; }

            document.getElementById('saveRetTxt').style.opacity = '0';
            document.getElementById('saveRetSpin').style.display = '';
            document.getElementById('btnSaveReturn').disabled = true;

            post({
                _action: 'save_return',
                return_id: document.getElementById('rReturnId').value,
                invoice_id: document.getElementById('rInvoiceId').value,
                return_reason: document.getElementById('rReason').value,
                payment_handling: document.getElementById('rPayHandling').value,
                target_account_type: document.getElementById('rTargetType').value,
                refund_account_id: document.getElementById('rRefundAccount').value,
                notes: document.getElementById('rNotes').value,
                lines: JSON.stringify(lines)
            }).then(d => {
                document.getElementById('saveRetTxt').style.opacity = '1';
                document.getElementById('saveRetSpin').style.display = 'none';
                document.getElementById('btnSaveReturn').disabled = false;
                if (d.ok) { toast(d.msg); retModal.hide(); setTimeout(() => location.reload(), 700); }
                else toast(d.msg, 'danger');
            });
        }

        function viewReturn(id) {
            editReturn(id, true);
        }

        function editReturn(id, readOnly = false) {
            post({ _action: 'get_return', id }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                document.getElementById('rReturnId').value = id;
                document.getElementById('rInvoiceId').value = d.return.invoice_id;
                document.getElementById('rSelCustomer').textContent = d.return.customer_name_live || '';
                document.getElementById('rSelInvoiceNo').textContent = d.return.invoice_number;
                document.getElementById('rReason').value = d.return.return_reason || '';
                document.getElementById('rPayHandling').value = d.return.payment_handling || 'not_paid';
                document.getElementById('rNotes').value = d.return.notes || '';
                _rSelectedCurrency = d.return.currency_code || 'USD';
                _rSelectedInvoice = {
                    total_amount: d.return.total_amount, inv_discount_amount: d.return.inv_discount_amount,
                    inv_tax_amount: d.return.inv_tax_amount, final_amount: d.return.final_amount,
                    payment_status: d.return.payment_status, currency_code: d.return.currency_code,
                    currency_symbol: d.return.currency_symbol, base_currency_code: d.return.base_currency_code,
                    exchange_rate: d.return.exchange_rate
                };
                const PAY_LABELS2 = { paid: 'مدفوعة بالكامل', partial: 'مدفوعة جزئياً', pending: 'غير مدفوعة' };
                document.getElementById('rInvCurLbl').textContent = d.return.currency_code || '—';
                document.getElementById('rExRateLbl').textContent = d.return.base_currency_code && d.return.exchange_rate
                    ? `1 ${d.return.base_currency_code} = ${Number(d.return.exchange_rate).toFixed(4)} ${d.return.currency_code}` : '—';
                document.getElementById('rPayStatusLbl').textContent = PAY_LABELS2[d.return.payment_status] || d.return.payment_status || '—';
                document.getElementById('rInvNetLbl').textContent = d.return.final_amount
                    ? Number(d.return.final_amount).toFixed(2) + ' ' + (d.return.currency_symbol || '') : '—';
                onRPayHandlingChange();
                document.getElementById('rTargetType').value = d.return.target_account_type || '';
                onRTargetTypeChange();
                if (d.return.refund_account_id) document.getElementById('rRefundAccount').value = d.return.refund_account_id;
                document.getElementById('stepFindInvoice').style.display = 'none';
                document.getElementById('stepItems').style.display = '';
                document.getElementById('retModalTitle').innerHTML =
                    (readOnly ? '<i class="bi bi-eye me-1 text-success"></i>عرض مرتجع: ' : '<i class="bi bi-pencil me-1 text-success"></i>تعديل مرتجع: ') + d.return.return_number;

                post({ _action: 'get_invoice_items', invoice_id: d.return.invoice_id }).then(d2 => {
                    rItems = d2.items;
                    renderItemsTable();
                    // ⚠ البحث هون بالكروب (rGroups) يلي يحتوي هالمقاس
                    // بالذات (member)، مش بفهرس rItems مباشرة — بما إنه
                    // التجميع صار يدمج عدة مقاسات بصف واحد.
                    d.items.forEach(savedLine => {
                        const gIdx = rGroups.findIndex(g =>
                            g.members.some(m => m.invoice_item_id == savedLine.invoice_item_id));
                        if (gIdx > -1) {
                            const qtyInput = document.getElementById(`qty${gIdx}`);
                            if (qtyInput) {
                                qtyInput.value = savedLine.quantity_returned;
                                onQtyChange(gIdx);
                            }
                        }
                    });
                    if (readOnly) {
                        document.querySelectorAll('#stepItems input, #stepItems select').forEach(el => el.disabled = true);
                        document.getElementById('btnSaveReturn').style.display = 'none';
                    } else {
                        document.getElementById('btnSaveReturn').style.display = '';
                    }
                });
                retModal.show();
            });
        }

        function confirmReturn(id, no) {
            if (!confirm(`تأكيد المرتجع "${no}"؟\n\nهاد سيعكس المخزون (بالإضافة) ويفتح قيد محاسبي — لا يمكن التراجع عنه لاحقاً إلا بمستند إلغاء منفصل.`)) return;
            const fd = new FormData();
            fd.append('_action', 'confirm_return');
            fd.append('return_id', id);
            fetch('../../api/confirm_sale_return.php', { method: 'POST', body: fd })
                .then(async r => {
                    const text = await r.text();
                    if (!r.ok) {
                        console.error('HTTP', r.status, text);
                        throw new Error(`HTTP ${r.status} — الملف موجود؟ راجع مسار confirm_sale_return.php`);
                    }
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('استجابة غير صالحة (مو JSON):', text);
                        throw new Error('الخادم رجّع استجابة غير متوقعة — افتح Console (F12) لمعرفة التفاصيل');
                    }
                })
                .then(d => {
                    if (d.ok) { toast('✅ ' + d.msg); setTimeout(() => location.reload(), 800); }
                    else toast(d.msg, 'danger');
                })
                .catch(err => toast(err.message || 'تعذّر الاتصال بخادم التأكيد', 'danger'));
        }

        function deleteReturn(id, no) {
            if (!confirm(`حذف المرتجع "${no}"؟ (مسودة فقط، بدون أي تأثير على المخزون)`)) return;
            post({ _action: 'delete_return', id }).then(d => {
                if (d.ok) { toast(d.msg); setTimeout(() => location.reload(), 600); }
                else toast(d.msg, 'danger');
            });
        }

        // ── طباعة المرتجع ──
        const BRANCH = <?= json_encode([
            'name' => $branchInfo['name'] ?? $branchName,
            'phone' => $branchInfo['phone'] ?? '',
            'address' => $branchInfo['address'] ?? '',
            'city' => $branchInfo['city'] ?? '',
            'email' => $branchInfo['email'] ?? '',
            'tax_number' => $branchInfo['tax_number'] ?? '',
        ], JSON_UNESCAPED_UNICODE) ?>;

        function printSaleReturn(id) {
            post({ _action: 'get_return', id }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                const r = d.return;
                const sym = r.currency_symbol || '$';
                const fmt = n => sym + ' ' + new Intl.NumberFormat('en').format(parseFloat(n || 0).toFixed(2));

                let rows = '', i = 1;
                (d.items || []).forEach(it => {
                    rows += `<tr>
                <td>${i++}</td>
                <td>${it.product_name || ('بند #' + it.invoice_item_id)}</td>
                <td>${it.quantity_returned}</td>
                <td>${fmt(it.unit_price)}</td>
                <td>${fmt(it.total_price)}</td>
            </tr>`;
                });

                const LOGO_URL = '<?= BASE_PATH ?>/assets/images/fatorize.png';
                const html = `<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
<meta charset="UTF-8">
<title>مرتجع بيع ${r.return_number}</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Arial',sans-serif;font-size:9px;color:#111;padding:20px;max-width:800px;margin:0 auto}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;padding-bottom:12px;border-bottom:2px solid #065f46}
.header-logo{width:100px;height:auto;object-fit:contain}
.header-center{text-align:center;flex:1;padding:0 16px}
.header-title{font-size:18px;font-weight:800;color:#065f46;margin-bottom:4px}
.header-sub{font-size:8px;color:#64748b}
.header-branch{text-align:right;font-size:10px;min-width:160px}
.header-branch .br-name{font-size:14px;font-weight:800;color:#065f46}
.inv-meta{display:flex;gap:8px;margin-bottom:12px}
.inv-meta-box{flex:1;border:1px solid #e2e8f0;border-radius:6px;padding:8px 12px;background:#f8fafc}
.inv-meta-box h4{font-size:7px;font-weight:700;color:#065f46;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;padding-bottom:3px;border-bottom:1px solid #e2e8f0}
.meta-row{display:flex;justify-content:space-between;font-size:8px;margin-bottom:3px}
.meta-row span:first-child{color:#64748b}
.meta-row span:last-child{font-weight:600}
table{width:100%;border-collapse:collapse;margin-bottom:12px;font-size:8px}
thead th{background:#065f46;color:#fff;padding:6px 8px;text-align:right;font-weight:600}
tbody td{padding:5px 8px;border-bottom:1px solid #f1f5f9}
tbody tr:nth-child(even) td{background:#f8fafc}
.totals-wrap{display:flex;justify-content:flex-end;margin-top:8px}
.totals{width:55%;border:1px solid #e2e8f0;border-radius:6px;overflow:hidden}
.tot-row{display:flex;justify-content:space-between;padding:5px 12px;font-size:8px;border-bottom:1px solid #f1f5f9}
.tot-row.final{background:#065f46;color:#fff;font-weight:700;font-size:10px;border:none}
.footer{text-align:center;margin-top:16px;font-size:7px;color:#94a3b8;border-top:1px solid #e2e8f0;padding-top:8px}
@media print{@page{margin:10mm}button{display:none}}
</style>
</head>
<body>
<div class="header">
  <img src="${LOGO_URL}" class="header-logo" alt="Logo" onerror="this.style.display='none'">
  <div class="header-center">
    <div class="header-title">مرتجع بيع</div>
    <div class="header-sub">Sale Return</div>
  </div>
  <div class="header-branch">
    <div class="br-name">${BRANCH.name}</div>
    ${BRANCH.city ? `<div>${BRANCH.city}${BRANCH.address ? ' - ' + BRANCH.address : ''}</div>` : ''}
    ${BRANCH.phone ? `<div>هاتف: ${BRANCH.phone}</div>` : ''}
  </div>
</div>
<div class="inv-meta">
  <div class="inv-meta-box">
    <h4>معلومات المرتجع</h4>
    <div class="meta-row"><span>رقم المرتجع:</span><span>${r.return_number}</span></div>
    <div class="meta-row"><span>التاريخ:</span><span>${r.return_date || '—'}</span></div>
    <div class="meta-row"><span>الفاتورة الأصلية:</span><span>${r.invoice_number || '—'}</span></div>
    <div class="meta-row"><span>سبب الإرجاع:</span><span>${r.return_reason || '—'}</span></div>
  </div>
  <div class="inv-meta-box">
    <h4>معلومات العميل</h4>
    <div class="meta-row"><span>اسم العميل:</span><span>${r.customer_name_live || r.customer_name || '—'}</span></div>
  </div>
</div>
<table>
  <thead><tr><th>#</th><th>بيان القطعة</th><th>الكمية</th><th>سعر الوحدة</th><th>المجموع</th></tr></thead>
  <tbody>${rows}</tbody>
</table>
<div class="totals-wrap">
  <div class="totals">
    <div class="tot-row"><span>المبلغ الفرعي:</span><span>${fmt(r.total_amount)}</span></div>
    <div class="tot-row"><span>الخصم المتناسب:</span><span>- ${fmt(r.discount_amount)}</span></div>
    <div class="tot-row"><span>الضريبة المتناسبة:</span><span>${fmt(r.tax_amount)}</span></div>
    <div class="tot-row final"><span>صافي المرتجع:</span><span>${fmt(r.return_amount)}</span></div>
  </div>
</div>
${r.notes ? `<div style="margin-top:12px;padding:8px 12px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;font-size:10px"><b>ملاحظات:</b> ${r.notes}</div>` : ''}
<div class="footer">نظام فاتورايز المحاسبي — ${BRANCH.name}</div>
<script>window.onload=()=>window.print()<\/script>
</body></html>`;
                const w = window.open('', '_blank', 'width=900,height=700');
                w.document.write(html);
                w.document.close();
            });
        }

        function cancelReturn(id, no) {
            const reason = prompt(`سبب إلغاء المرتجع "${no}"؟ (اختياري)`);
            if (reason === null) return; // ضغط "إلغاء" بمربع السبب نفسه
            const fd = new FormData();
            fd.append('_action', 'cancel_return');
            fd.append('return_id', id);
            fd.append('reason', reason || '');
            fetch('../../api/confirm_sale_return.php', { method: 'POST', body: fd })
                .then(async r => {
                    const text = await r.text();
                    if (!r.ok) {
                        console.error('HTTP', r.status, text);
                        throw new Error(`HTTP ${r.status} — راجع مسار confirm_sale_return.php`);
                    }
                    try { return JSON.parse(text); }
                    catch (e) {
                        console.error('استجابة غير صالحة (مو JSON):', text);
                        throw new Error('الخادم رجّع استجابة غير متوقعة — افتح Console (F12)');
                    }
                })
                .then(d => {
                    if (d.ok) { toast('✅ ' + d.msg); setTimeout(() => location.reload(), 800); }
                    else toast(d.msg, 'danger');
                })
                .catch(err => toast(err.message || 'تعذّر الاتصال بخادم الإلغاء', 'danger'));
        }

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

        makeSortable(document.getElementById('returnsTbl'));

        <?php if (!empty($_GET['open_invoice_id'])): ?>
            (function () {
                const targetId = <?= (int) $_GET['open_invoice_id'] ?>;
                openNewReturn();
                const tryFind = () => {
                    if (_rInvoicesMap[targetId]) {
                        document.getElementById('rInvoiceSelect').value = targetId;
                        onInvoiceSelectChange();
                    } else {
                        post({ _action: 'find_invoice', q: '<?= addslashes($_GET['open_invoice_id']) ?>' });
                        setTimeout(() => {
                            if (_rInvoicesMap[targetId]) {
                                document.getElementById('rInvoiceSelect').value = targetId;
                                onInvoiceSelectChange();
                            }
                        }, 400);
                    }
                };
                setTimeout(tryFind, 350);
            })();
        <?php endif; ?>
    </script>
</body>

</html>
