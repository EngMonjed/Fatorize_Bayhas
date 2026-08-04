<?php
/**
 * purchases/returns.php — مرتجعات فواتير شراء المنتجات
 * المسار: retail1/modules/purchases/returns.php
 *
 * ⚠ هاي الصفحة بتدير المسودات فقط (إنشاء/تعديل/حذف مرتجع بحالة draft).
 * التأكيد الفعلي (status=posted — عكس المخزون + قيد محاسبي) لازم يصير
 * حصراً عبر api/confirm_purchase_return.php (مو local action هون) —
 * نفس مبدأ فصل التأكيد المحاسبي عن CRUD المسودات المعتمد أصلاً بفواتير
 * الشراء العادية (راجع api/confirm_purchase_invoice.php). الملف هاد
 * لسا غير مبني — زر "تأكيد" بالواجهة موجود ومربوط بمساره الصحيح
 * مسبقاً، بس هيرجع خطأ لحد ما يُبنى بمحادثة لاحقة بعد مراجعة
 * confirm_purchase_invoice.php كمرجع لآلية postAccountBalance().
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('purchases.returns', 'view');
$currentModule = 'purchases.returns';

$TS   = $_SESSION['table_suffix'];
$TR   = "purchase_returns_{$TS}";
$TRI  = "purchase_return_items_{$TS}";
$TP   = "purchases_{$TS}";
$TPI  = "purchase_items_{$TS}";
$TSP  = "product_suppliers_{$TS}";
$TW   = "warehouses_{$TS}";
$TV   = "product_variants_{$TS}";
$TAC  = "account_charts_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// حسابات الصندوق/البنك — لاسترداد المرتجع نقداً (لو payment_handling=paid_refund_cash)
// نفس استبعاد حسابات الدفعات المقدمة الخاصة بالموردين المعتمد بـ index.php
$cashAccounts = $pdo->query("SELECT ac.id,ac.code,ac.name,ac.balance,c.code AS cur_code,c.symbol AS cur_sym
    FROM `{$TAC}` ac
    LEFT JOIN currencies c ON c.id=ac.currency_id
    WHERE ac.account_type='asset' AND ac.is_active=1 AND ac.level>=3
      AND ac.id NOT IN (SELECT prepaid_account_id FROM `{$TSP}` WHERE prepaid_account_id IS NOT NULL)
    ORDER BY ac.code")->fetchAll();

function genReturnNo(PDO $pdo, string $table): string
{
    $y = date('Y');
    $last = $pdo->query("SELECT return_number FROM `{$table}`
        WHERE return_number LIKE 'RET-{$y}-%'
        ORDER BY id DESC LIMIT 1")->fetchColumn();
    $seq = $last ? (int) substr($last, -5) + 1 : 1;
    return "RET-{$y}-" . str_pad($seq, 5, '0', STR_PAD_LEFT);
}

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        // بحث عن فاتورة شراء مؤكدة + بنودها القابلة للإرجاع
        if ($act === 'find_purchase') {
            $q = trim($_POST['q'] ?? '');
            // ⚠ بحث فارغ = يرجّع أحدث ١٥ فاتورة مؤكدة (بدل رفض الطلب) —
            // حتى يقدر المستخدم يشوف قائمة جاهزة فور فتح المودال، مو
            // يُجبر يكتب شي أول.
            $like = $q !== '' ? "%{$q}%" : '%';

            $st = $pdo->prepare("SELECT p.id, p.purchase_number, p.purchase_date, p.supplier_id,
                    s.name AS supplier_name, p.invoice_currency_id, p.base_currency_id, p.exchange_rate,
                    p.warehouse_id, c.code AS currency_code, c.symbol AS currency_symbol,
                    p.total_amount, p.discount_amount AS inv_discount_amount,
                    p.tax_amount AS inv_tax_amount, p.final_amount, p.payment_status,
                    bc.code AS base_currency_code, bc.symbol AS base_currency_symbol
                FROM `{$TP}` p
                JOIN `{$TSP}` s ON s.id = p.supplier_id
                LEFT JOIN currencies c ON c.id = p.invoice_currency_id
                LEFT JOIN currencies bc ON bc.id = p.base_currency_id
                WHERE p.status = 'received'
                  AND (p.purchase_number LIKE ? OR s.name LIKE ? OR p.id = ?)
                ORDER BY p.purchase_date DESC LIMIT 15");
            $st->execute([$like, $like, $q !== '' && ctype_digit($q) ? (int) $q : 0]);
            $purchases = $st->fetchAll();

            echo json_encode(['ok' => true, 'purchases' => $purchases]);
            exit;
        }

        // بنود فاتورة معيّنة + الكمية المتبقية القابلة للإرجاع لكل بند
        // (المشتراة − مجموع كل ما أُرجع سابقاً بمرتجعات غير ملغاة)
        if ($act === 'get_purchase_items') {
            $purId = (int) ($_POST['purchase_id'] ?? 0);
            if (!$purId) { echo json_encode(['ok' => false, 'msg' => 'فاتورة غير محددة']); exit; }

            $st = $pdo->prepare("SELECT
                    pi.id AS purchase_item_id, pi.product_id, pi.variant_id,
                    pi.quantity, pi.unit_price AS gross_unit_price, pi.total_price, pi.discount_percentage,
                    pr.name AS product_name, pr.model_number AS model_number,
                    sz.size AS size, sz.age_type AS age_type, cl.name AS color,
                    COALESCE((
                        SELECT SUM(ri.quantity_returned)
                        FROM `{$TRI}` ri
                        JOIN `{$TR}` r ON r.id = ri.return_id
                        WHERE ri.purchase_item_id = pi.id AND r.status != 'cancelled'
                    ), 0) AS already_returned
                FROM `{$TPI}` pi
                LEFT JOIN `product_variants_{$TS}` v ON v.id = pi.variant_id
                LEFT JOIN `products_{$TS}` pr ON pr.id = pi.product_id
                LEFT JOIN `product_sizes_{$TS}` sz ON sz.id = v.size_id
                LEFT JOIN `product_colors_{$TS}` cl ON cl.id = v.color_id
                WHERE pi.purchase_id = ?");
            $st->execute([$purId]);
            $items = $st->fetchAll();
            foreach ($items as &$it) {
                $it['returnable_qty'] = max(0, (float) $it['quantity'] - (float) $it['already_returned']);
                $it['display_name'] = trim(($it['product_name'] ?? '') . ' ' . ($it['size'] ?? '') . ' ' . ($it['color'] ?? ''));
                // ⚠ السعر الصافي الحقيقي بعد خصم السطر (لو وُجد) — مو
                // السعر الخام. total_price أصلاً محسوب = qty×unit_price×
                // (1−discount_percentage/100) وقت إنشاء الفاتورة، فقسمته
                // على الكمية بترجّع السعر الفعلي المدفوع للوحدة. لو
                // استخدمنا gross_unit_price مباشرة (القديم)، كان المرتجع
                // بيتجاهل خصم السطر بالكامل ويحسب قيمة أعلى من الصحيح.
                $it['unit_price'] = $it['quantity'] > 0
                    ? round((float) $it['total_price'] / (float) $it['quantity'], 4)
                    : (float) $it['gross_unit_price'];
            }
            echo json_encode(['ok' => true, 'items' => $items]);
            exit;
        }

        // حفظ مسودة مرتجع (إنشاء أو تعديل — بس إذا كانت لسا draft)
        if ($act === 'save_return') {
            requirePermission('purchases.returns', 'create');
            $returnId = (int) ($_POST['return_id'] ?? 0);
            $purId    = (int) ($_POST['purchase_id'] ?? 0);
            $reason   = trim($_POST['return_reason'] ?? '');
            $payHandling = $_POST['payment_handling'] ?? 'not_paid';
            $refundAccId = (int) ($_POST['refund_account_id'] ?? 0) ?: null;
            $notes    = trim($_POST['notes'] ?? '');
            $lines    = json_decode($_POST['lines'] ?? '[]', true) ?: [];

            if (!$purId || empty($lines)) {
                echo json_encode(['ok' => false, 'msg' => 'اختر الفاتورة وبند واحد على الأقل']); exit;
            }

            $pur = $pdo->prepare("SELECT * FROM `{$TP}` WHERE id=?");
            $pur->execute([$purId]);
            $pur = $pur->fetch();
            if (!$pur) { echo json_encode(['ok' => false, 'msg' => 'الفاتورة الأصلية غير موجودة']); exit; }

            $totalAmt = 0;
            $lineData = [];
            foreach ($lines as $l) {
                $piId = (int) ($l['purchase_item_id'] ?? 0);
                $qty  = (float) ($l['quantity_returned'] ?? 0);
                if (!$piId || $qty <= 0) continue;

                $pi = $pdo->prepare("SELECT * FROM `{$TPI}` WHERE id=? AND purchase_id=?");
                $pi->execute([$piId, $purId]);
                $pi = $pi->fetch();
                if (!$pi) continue;

                // إعادة تحقق الكمية القابلة للإرجاع على السيرفر (مو بس بالواجهة)
                $already = $pdo->prepare("SELECT COALESCE(SUM(ri.quantity_returned),0)
                    FROM `{$TRI}` ri JOIN `{$TR}` r ON r.id=ri.return_id
                    WHERE ri.purchase_item_id=? AND r.status != 'cancelled' AND r.id != ?");
                $already->execute([$piId, $returnId ?: 0]);
                $returnable = (float) $pi['quantity'] - (float) $already->fetchColumn();
                if ($qty > $returnable) {
                    echo json_encode(['ok' => false, 'msg' => "الكمية المطلوب إرجاعها أكبر من المتاح لبند رقم {$piId}"]); exit;
                }

                // ⚠ السعر الصافي بعد خصم السطر (لا الخام) — نفس منطق
                // get_purchase_items بالضبط، حتى لا يتجاهل المرتجع أي
                // خصم كان مسجَّلاً على السطر بالذات وقت إنشاء الفاتورة.
                $netUnitPrice = (float) $pi['quantity'] > 0
                    ? round((float) $pi['total_price'] / (float) $pi['quantity'], 4)
                    : (float) $pi['unit_price'];

                $lineTotal = $qty * $netUnitPrice;
                $totalAmt += $lineTotal;
                $lineData[] = [
                    'purchase_item_id'  => $piId,
                    'product_id'        => $pi['product_id'],
                    'variant_id'        => $pi['variant_id'],
                    'product_name'      => $l['display_name'] ?? ('بند #' . $piId),
                    'quantity_returned' => $qty,
                    'unit_price'        => $netUnitPrice,
                    'total_price'       => $lineTotal,
                ];
            }
            if (empty($lineData)) { echo json_encode(['ok' => false, 'msg' => 'لا يوجد بند صالح للحفظ']); exit; }

            // ⚠ حساب الخصم والضريبة المتناسبين — بنفس نسبة الفاتورة
            // الأصلية كاملة، مطبَّقة على قيمة البنود المرتجعة فقط. يُعاد
            // حسابه هون بالسيرفر (مو بالثقة بالرقم القادم من الواجهة)
            // لضمان صحة المبلغ المرحَّل محاسبياً لاحقاً وقت التأكيد.
            $invTotal = (float) $pur['total_amount'];
            $discAmt = 0.0;
            $taxAmt = 0.0;
            if ($invTotal > 0) {
                $discRatio = ((float) $pur['discount_amount']) / $invTotal;
                $taxBaseInv = $invTotal - (float) $pur['discount_amount'];
                $taxRatio = $taxBaseInv > 0 ? ((float) $pur['tax_amount']) / $taxBaseInv : 0;
                $discAmt = round($totalAmt * $discRatio, 2);
                $taxAmt = round(($totalAmt - $discAmt) * $taxRatio, 2);
            }
            $returnAmt = round($totalAmt - $discAmt + $taxAmt, 2);

            $pdo->beginTransaction();
            try {
                if ($returnId) {
                    // تعديل مسودة موجودة فقط
                    $chk = $pdo->prepare("SELECT status FROM `{$TR}` WHERE id=?");
                    $chk->execute([$returnId]);
                    if ($chk->fetchColumn() !== 'draft') {
                        throw new Exception('لا يمكن تعديل مرتجع مؤكد أو ملغى');
                    }
                    $pdo->prepare("UPDATE `{$TR}` SET purchase_id=?, supplier_id=?, supplier_name=?,
                            purchase_number=?, warehouse_id=?, return_date=CURDATE(),
                            total_amount=?, discount_amount=?, tax_amount=?, return_amount=?,
                            return_currency_id=?, base_currency_id=?,
                            exchange_rate=?, payment_handling=?, refund_account_id=?, return_reason=?, notes=?
                        WHERE id=? AND status='draft'")
                        ->execute([
                            $purId, $pur['supplier_id'], null, $pur['purchase_number'], $pur['warehouse_id'],
                            $totalAmt, $discAmt, $taxAmt, $returnAmt, $pur['invoice_currency_id'], $pur['base_currency_id'],
                            $pur['exchange_rate'], $payHandling, $refundAccId, $reason, $notes, $returnId
                        ]);
                    $pdo->prepare("DELETE FROM `{$TRI}` WHERE return_id=?")->execute([$returnId]);
                } else {
                    $retNo = genReturnNo($pdo, $TR);
                    $pdo->prepare("INSERT INTO `{$TR}`
                            (return_number, purchase_id, purchase_number, supplier_id, warehouse_id,
                             return_date, total_amount, discount_amount, tax_amount, return_amount,
                             return_currency_id, base_currency_id,
                             exchange_rate, payment_handling, refund_account_id, return_reason, status, notes, user_id)
                            VALUES (?,?,?,?,?, CURDATE(),?,?,?,?,?,?, ?,?,?,?, 'draft', ?, ?)")
                        ->execute([
                            $retNo, $purId, $pur['purchase_number'], $pur['supplier_id'], $pur['warehouse_id'],
                            $totalAmt, $discAmt, $taxAmt, $returnAmt, $pur['invoice_currency_id'], $pur['base_currency_id'],
                            $pur['exchange_rate'], $payHandling, $refundAccId, $reason, $notes, $_SESSION['user_id']
                        ]);
                    $returnId = (int) $pdo->lastInsertId();
                }

                foreach ($lineData as $ld) {
                    $pdo->prepare("INSERT INTO `{$TRI}`
                            (return_id, purchase_item_id, product_id, variant_id, product_name,
                             quantity_returned, unit_price, total_price)
                            VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([
                            $returnId, $ld['purchase_item_id'], $ld['product_id'], $ld['variant_id'],
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
            $r = $pdo->prepare("SELECT r.*, s.name AS supplier_name_live, c.code AS currency_code,
                    c.symbol AS currency_symbol, bc.code AS base_currency_code,
                    p.total_amount, p.discount_amount AS inv_discount_amount,
                    p.tax_amount AS inv_tax_amount, p.final_amount, p.payment_status
                FROM `{$TR}` r LEFT JOIN `{$TSP}` s ON s.id=r.supplier_id
                LEFT JOIN currencies c ON c.id=r.return_currency_id
                LEFT JOIN currencies bc ON bc.id=r.base_currency_id
                LEFT JOIN `{$TP}` p ON p.id=r.purchase_id
                WHERE r.id=?");
            $r->execute([$id]);
            $r = $r->fetch();
            if (!$r) { echo json_encode(['ok' => false, 'msg' => 'غير موجود']); exit; }
            $items = $pdo->prepare("SELECT * FROM `{$TRI}` WHERE return_id=?");
            $items->execute([$id]);
            echo json_encode(['ok' => true, 'return' => $r, 'items' => $items->fetchAll()]);
            exit;
        }

        // حذف مسودة (draft فقط — لا يوجد تأثير مخزون/محاسبة بعد لحذفها)
        if ($act === 'delete_return') {
            requirePermission('purchases.returns', 'delete');
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
$dateFrom = $_GET['from'] ?? '';
$dateTo   = $_GET['to'] ?? '';

$where = [];
$params = [];
if ($search !== '') {
    $where[] = "(r.return_number LIKE ? OR r.purchase_number LIKE ? OR s.name LIKE ?)";
    $like = "%{$search}%";
    array_push($params, $like, $like, $like);
}
if ($status !== '') { $where[] = "r.status = ?"; $params[] = $status; }
if ($dateFrom !== '') { $where[] = "r.return_date >= ?"; $params[] = $dateFrom; }
if ($dateTo !== '') { $where[] = "r.return_date <= ?"; $params[] = $dateTo; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT r.*, s.name AS supplier_name, c.code AS currency_code, c.symbol AS currency_symbol
    FROM `{$TR}` r
    LEFT JOIN `{$TSP}` s ON s.id = r.supplier_id
    LEFT JOIN currencies c ON c.id = r.return_currency_id
    {$whereSql}
    ORDER BY r.id DESC";
$st = $pdo->prepare($sql);
$st->execute($params);
$returns = $st->fetchAll();

$STATUS_MAP = [
    'draft'     => ['label' => 'مسودة', 'cls' => 'bg-secondary-subtle text-secondary'],
    'posted'    => ['label' => 'مؤكد', 'cls' => 'bg-success-subtle text-success'],
    'cancelled' => ['label' => 'ملغى', 'cls' => 'bg-danger-subtle text-danger'],
];

// ⚠ عملة الفرع الأساسية — عبر base_currency_id (FK)، لا نص ثابت بالكود
// (نفس القاعدة الموثّقة بـ blueprint-pattern.md § ٧)
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
    // ⚠ تحويل كل مرتجع لعملة الفرع الأساسية عبر سعر صرفه الخاص قبل
    // الجمع — المرتجعات ممكن تكون بعملات مختلفة (TRY, SAR...)، وجمعها
    // خام بدون تحويل كان يعطي رقم بلا معنى محاسبياً (نفس درس فاتورة
    // الشراء بخصوص خلط العملات).
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
    <title>مرتجعات المشتريات — FATORIZE</title>
    <link rel="icon" type="image/png" href="<?= BASE_PATH ?>/assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/assets/css/layout.css" rel="stylesheet">
    <style>
        .n { font-variant-numeric: tabular-nums }
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
        <span class="tb-title"><i class="bi bi-arrow-return-right me-1 text-primary"></i>مرتجعات المشتريات</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <?= renderBreadcrumb() ?>
    </header>

    <main class="main-content">
        <div class="content-body">

            <!-- تبويبات -->
            <ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e2e8f0">
                <li class="nav-item">
                    <a class="nav-link fw-600" href="suppliers.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-truck me-1"></i>إدارة الموردين
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="index.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-receipt me-1"></i>فواتير المشتريات
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600 active" href="returns.php"
                        style="border:none;border-bottom:2px solid #1e3a8a;color:#1e3a8a;font-size:.83rem;margin-bottom:-2px">
                        <i class="bi bi-arrow-return-right me-1"></i>مرتجعات المشتريات
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="orders.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-file-earmark-text me-1"></i>أوامر الشراء / طلبات عروض الأسعار
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="reports.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-bar-chart me-1"></i>التقارير
                    </a>
                </li>
            </ul>

            <!-- إحصائيات -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#eff6ff"><i
                                class="bi bi-arrow-return-right text-primary"></i></div>
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

            <!-- الجدول -->
            <div class="tbl-wrap">
                <div class="tbl-hdr">
                    <span style="font-size:.88rem;font-weight:700;color:#1e293b">
                        <i class="bi bi-list-ul me-1 text-primary"></i>سجل مرتجعات المشتريات
                    </span>
                    <div class="d-flex gap-2 ms-auto flex-wrap align-items-center">
                        <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
                            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                                placeholder="رقم المرتجع أو الفاتورة أو المورد..." class="form-control form-control-sm"
                                style="width:200px;border-radius:8px">
                            <select name="status" class="form-select form-select-sm"
                                style="width:120px;border-radius:8px" onchange="this.form.submit()">
                                <option value="">كل الحالات</option>
                                <?php foreach ($STATUS_MAP as $k => $v): ?>
                                            <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>>
                                                <?= $v['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>"
                                class="form-control form-control-sm" style="width:140px;border-radius:8px">
                            <span style="font-size:.8rem;color:#94a3b8">—</span>
                            <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>"
                                class="form-control form-control-sm" style="width:140px;border-radius:8px">
                            <button type="submit" class="btn btn-sm btn-primary" style="border-radius:8px">
                                <i class="bi bi-search me-1"></i>بحث
                            </button>
                            <?php if ($search || $status || $dateFrom || $dateTo): ?>
                                        <a href="returns.php" class="btn btn-sm btn-light" style="border-radius:8px">
                                            <i class="bi bi-x-lg me-1"></i>مسح
                                        </a>
                            <?php endif; ?>
                        </form>
                        <button class="btn btn-sm fw-600" onclick="openNewReturn()"
                            style="border-radius:9px;background:#1e3a8a;color:#fff;font-size:.82rem">
                            <i class="bi bi-plus-lg me-1"></i>مرتجع جديد
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="mtbl" id="returnsTbl">
                        <thead>
                            <tr>
                                <th style="color:#1e3a8a">رقم المرتجع</th>
                                <th>التاريخ</th>
                                <th style="color:#16a34a">المورد</th>
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
                                    'not_paid' => 'لم تُسوَّ', 'partial' => 'تسوية جزئية',
                                    'paid_refund_cash' => 'استرداد نقدي', 'paid_credit_supplier' => 'خصم من رصيد المورد'
                                ];
                                ?>
                                        <tr>
                                            <td class="n fw-600" style="direction:ltr;color:#1e3a8a">
                                                <?= htmlspecialchars($r['return_number'] ?? '—') ?>
                                            </td>
                                            <td class="text-muted"><?= $r['return_date'] ?></td>
                                            <td style="color:#16a34a" class="fw-600">
                                                <?= htmlspecialchars($r['supplier_name'] ?? '—') ?>
                                            </td>
                                            <td class="text-muted" style="font-size:.8rem">
                                                <?= htmlspecialchars($r['purchase_number'] ?? '—') ?>
                                            </td>
                                            <td class="n fw-600"><?= number_format((float) $r['return_amount'], 2) ?>
                                                <?= $sym ?></td>
                                            <td style="font-size:.78rem"><?= $payLabels[$r['payment_handling']] ?? '—' ?></td>
                                            <td><span class="badge <?= $st2['cls'] ?>"
                                                    style="font-size:.68rem"><?= $st2['label'] ?></span></td>
                                            <td>
                                                <div class="d-flex gap-1 justify-content-center">
                                                    <button class="act-btn info-h" onclick="viewReturn(<?= $r['id'] ?>)"
                                                        title="عرض"><i class="bi bi-eye"></i></button>
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

    <!-- ══ مودال مرتجع جديد/تعديل ══ -->
    <div class="modal fade" id="retModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl" style="max-width:1100px">
            <div class="modal-content" style="border-radius:14px">
                <div class="modal-header">
                    <h6 class="modal-title fw-700" id="retModalTitle"><i
                            class="bi bi-arrow-return-right me-1 text-primary"></i>مرتجع شراء جديد</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="rReturnId" value="">
                    <input type="hidden" id="rPurchaseId" value="">

                    <!-- خطوة 1: اختيار الفاتورة -->
                    <div id="stepFindPurchase">
                        <label class="form-label small fw-600">اختر الفاتورة الأصلية</label>
                        <select id="rPurchaseSelect" class="form-select form-select-sm mb-2" style="border-radius:8px"
                            onchange="onPurchaseSelectChange()">
                            <option value="">— جارٍ التحميل... —</option>
                        </select>
                        <div class="d-flex gap-2 mb-2">
                            <input type="text" id="rSearchPurchase" class="form-control form-control-sm"
                                placeholder="فلترة بالرقم أو اسم المورد..." style="border-radius:8px"
                                oninput="searchPurchaseForReturn()">
                        </div>
                    </div>

                    <!-- خطوة 2: بنود الفاتورة المختارة -->
                    <div id="stepItems" style="display:none">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div style="font-size:.85rem">
                                <span class="fw-700" id="rSelSupplier"></span> —
                                <span class="text-muted" id="rSelPurchaseNo"></span>
                            </div>
                            <button class="btn btn-sm btn-light" style="border-radius:8px"
                                onclick="resetPurchaseSelection()"><i class="bi bi-arrow-right me-1"></i>تغيير
                                الفاتورة</button>
                        </div>
                        <div class="table-responsive">
                            <table class="mtbl" style="font-size:.82rem">
                                <thead>
                                    <tr>
                                        <th>المنتج</th>
                                        <th style="min-width:110px">القياس</th>
                                        <th style="color:#16a34a">اللون</th>
                                        <th class="text-center">المشتراة</th>
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
                                    style="border-radius:8px" placeholder="بضاعة تالفة، خطأ بالطلب...">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-600">طريقة التسوية</label>
                                <select id="rPayHandling" class="form-select form-select-sm" style="border-radius:8px"
                                    onchange="onRPayHandlingChange()">
                                    <option value="not_paid">لم تُسوَّ بعد</option>
                                    <option value="partial">تسوية جزئية</option>
                                    <option value="paid_refund_cash">استرداد نقدي فوري</option>
                                    <option value="paid_credit_supplier">خصم من رصيد المورد</option>
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

                        <!-- ملخص مبالغ المرتجع — متناسب مع خصم وضريبة الفاتورة الأصلية -->
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
                                    <div class="fw-700" style="color:#1e3a8a;font-size:.95rem" id="rTotalLine">0.00</div>
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
                    <button type="button" class="btn btn-primary btn-sm" id="btnSaveReturn" style="border-radius:8px"
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
            document.getElementById('rPurchaseId').value = '';
            document.getElementById('rSearchPurchase').value = '';
            document.getElementById('rPurchaseSelect').innerHTML = '<option value="">— جارٍ التحميل... —</option>';
            document.getElementById('stepFindPurchase').style.display = '';
            document.getElementById('stepItems').style.display = 'none';
            document.getElementById('btnSaveReturn').disabled = true;
            document.getElementById('rPayHandling').value = 'not_paid';
            document.getElementById('rRefundAccWrap').style.display = 'none';
            document.getElementById('retModalTitle').innerHTML = '<i class="bi bi-arrow-return-right me-1 text-primary"></i>مرتجع شراء جديد';
            retModal.show();
            searchPurchaseForReturn(); // تحميل أحدث الفواتير المؤكدة فوراً
        }

        function resetPurchaseSelection() {
            document.getElementById('stepFindPurchase').style.display = '';
            document.getElementById('stepItems').style.display = 'none';
            document.getElementById('btnSaveReturn').disabled = true;
        }

        let _rSearchDebounce = null;
        let _rPurchasesMap = {}; // id -> بيانات الفاتورة الكاملة (لاسترجاعها عند اختيار الكومبوبوكس)

        function searchPurchaseForReturn() {
            clearTimeout(_rSearchDebounce);
            _rSearchDebounce = setTimeout(() => {
                const q = document.getElementById('rSearchPurchase').value.trim();
                post({ _action: 'find_purchase', q }).then(d => {
                    const sel = document.getElementById('rPurchaseSelect');
                    _rPurchasesMap = {};
                    if (!d.ok || !d.purchases.length) {
                        sel.innerHTML = '<option value="">— لا نتائج —</option>';
                        return;
                    }
                    d.purchases.forEach(p => _rPurchasesMap[p.id] = p);
                    sel.innerHTML = '<option value="">— اختر فاتورة —</option>' + d.purchases.map(p =>
                        `<option value="${p.id}">${p.purchase_number} — ${p.supplier_name} (${p.purchase_date})</option>`
                    ).join('');
                });
            }, 250);
        }

        function onPurchaseSelectChange() {
            const id = document.getElementById('rPurchaseSelect').value;
            if (!id || !_rPurchasesMap[id]) return;
            selectPurchase(_rPurchasesMap[id]);
        }

        let _rSelectedPurchase = null;

        function selectPurchase(p) {
            document.getElementById('rPurchaseId').value = p.id;
            document.getElementById('rSelSupplier').textContent = p.supplier_name;
            document.getElementById('rSelPurchaseNo').textContent = p.purchase_number;
            document.getElementById('stepFindPurchase').style.display = 'none';
            document.getElementById('stepItems').style.display = '';
            _rSelectedCurrency = p.currency_code || 'USD';
            _rSelectedPurchase = p;
            onRPayHandlingChange();

            const PAY_LABELS = { paid: 'مدفوعة بالكامل', partial: 'مدفوعة جزئياً', pending: 'غير مدفوعة' };
            document.getElementById('rInvCurLbl').textContent = p.currency_code || '—';
            document.getElementById('rExRateLbl').textContent = p.base_currency_code && p.exchange_rate
                ? `1 ${p.base_currency_code} = ${Number(p.exchange_rate).toFixed(4)} ${p.currency_code}` : '—';
            document.getElementById('rPayStatusLbl').textContent = PAY_LABELS[p.payment_status] || p.payment_status || '—';
            document.getElementById('rInvNetLbl').textContent = p.final_amount
                ? Number(p.final_amount).toFixed(2) + ' ' + (p.currency_symbol || '') : '—';

            // ⚠ الاسترداد النقدي منطقي بس لو فيه فعلاً مبلغ مسدَّد على
            // الفاتورة الأصلية (مافيش شي نرجعه كاش لو ما انسددت أصلاً)
            const refundOpt = document.querySelector('#rPayHandling option[value="paid_refund_cash"]');
            const hadPayment = p.payment_status === 'paid' || p.payment_status === 'partial';
            refundOpt.disabled = !hadPayment;
            refundOpt.textContent = hadPayment ? 'استرداد نقدي فوري' : 'استرداد نقدي فوري (الفاتورة غير مدفوعة أصلاً)';
            if (!hadPayment && document.getElementById('rPayHandling').value === 'paid_refund_cash') {
                document.getElementById('rPayHandling').value = 'not_paid';
                onRPayHandlingChange();
            }

            post({ _action: 'get_purchase_items', purchase_id: p.id }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                rItems = d.items;
                renderItemsTable();
            });
        }

        // إظهار/إخفاء حقل حساب الاسترداد حسب طريقة التسوية، مع فلترته
        // تلقائياً حسب عملة الفاتورة الأصلية (نفس آلية index.php)
        function onRPayHandlingChange() {
            const wrap = document.getElementById('rRefundAccWrap');
            const isRefund = document.getElementById('rPayHandling').value === 'paid_refund_cash';
            wrap.style.display = isRefund ? '' : 'none';
            if (isRefund) {
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

        // ⚠ عرض المقاسات كمدى (أصغر-أكبر) بدل قائمة كاملة، مع نوع
        // العمر (سنة/شهر) جنبه.
        function formatSizeRange(sizes, ageType) {
            const nums = sizes.map(s => parseFloat(s)).filter(n => !isNaN(n));
            let label;
            if (nums.length === sizes.length && nums.length > 0) {
                const min = Math.min(...nums), max = Math.max(...nums);
                label = min === max ? String(min) : `${min}-${max}`;
            } else {
                label = sizes.join(' · ') || '—';
            }
            return ageType ? `${label} ${ageType}` : label;
        }

        // ⚠ دمج بنود المرتجع بحسب الكروب (منتج+سعر+لون) — بلا مربع
        // اختيار منفصل، الكتابة بالحقل مباشرة تُفعّل السطر.
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
                    purchase_item_id: it.purchase_item_id,
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

            // ⚠ الخصم والضريبة يُحسبان بنفس النسبة المئوية المطبَّقة على
            // الفاتورة الأصلية كاملة — لا قيمة خام منفصلة. لو الفاتورة
            // الأصلية كانت فيها خصم 5% وضريبة 10%، نفس النسبتين تنطبقان
            // هون على قيمة البنود المرتجعة فقط (نفس مبدأ purchase-invoice-blueprint.md
            // بخصوص التحقق السيادي بالسيرفر — هالحساب هون عرض فقط، الحفظ
            // الفعلي يعيد نفس الحساب بالسيرفر مرة ثانية للتأكد).
            let discAmt = 0, taxAmt = 0;
            const p = _rSelectedPurchase;
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
                    g.members.forEach(m => {
                        lines.push({
                            purchase_item_id: m.purchase_item_id,
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
                purchase_id: document.getElementById('rPurchaseId').value,
                return_reason: document.getElementById('rReason').value,
                payment_handling: document.getElementById('rPayHandling').value,
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
            // ⚠ عرض تفصيلي (read-only) — سيُبنى مع صفحة التأكيد المحاسبي
            editReturn(id, true);
        }

        function editReturn(id, readOnly = false) {
            post({ _action: 'get_return', id }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                document.getElementById('rReturnId').value = id;
                document.getElementById('rPurchaseId').value = d.return.purchase_id;
                document.getElementById('rSelSupplier').textContent = d.return.supplier_name_live || '';
                document.getElementById('rSelPurchaseNo').textContent = d.return.purchase_number;
                document.getElementById('rReason').value = d.return.return_reason || '';
                document.getElementById('rPayHandling').value = d.return.payment_handling || 'not_paid';
                document.getElementById('rNotes').value = d.return.notes || '';
                _rSelectedCurrency = d.return.currency_code || 'USD';
                _rSelectedPurchase = {
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
                if (d.return.refund_account_id) document.getElementById('rRefundAccount').value = d.return.refund_account_id;
                document.getElementById('stepFindPurchase').style.display = 'none';
                document.getElementById('stepItems').style.display = '';
                document.getElementById('retModalTitle').innerHTML =
                    (readOnly ? '<i class="bi bi-eye me-1 text-primary"></i>عرض مرتجع: ' : '<i class="bi bi-pencil me-1 text-primary"></i>تعديل مرتجع: ') + d.return.return_number;

                post({ _action: 'get_purchase_items', purchase_id: d.return.purchase_id }).then(d2 => {
                    rItems = d2.items;
                    renderItemsTable();
                    // ⚠ البحث هون بالكروب (rGroups) يلي يحتوي هالمقاس
                    // بالذات (member)، مش بفهرس rItems مباشرة.
                    d.items.forEach(savedLine => {
                        const gIdx = rGroups.findIndex(g =>
                            g.members.some(m => m.purchase_item_id == savedLine.purchase_item_id));
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
            if (!confirm(`تأكيد المرتجع "${no}"؟\n\nهاد سيعكس المخزون ويفتح قيد محاسبي — لا يمكن التراجع عنه لاحقاً إلا بمستند إلغاء منفصل.`)) return;
            const fd = new FormData();
            fd.append('_action', 'confirm_return');
            fd.append('return_id', id);
            fetch('../../api/confirm_purchase_return.php', { method: 'POST', body: fd })
                .then(async r => {
                    // ⚠ نفحص حالة HTTP ونطبع النص الخام قبل محاولة تحليله
                    // كـJSON — حتى نعرف بالضبط شو رجع (404؟ صفحة خطأ PHP
                    // بيضاء؟) بدل ما نبتلع كل الأخطاء برسالة عامة واحدة.
                    const text = await r.text();
                    if (!r.ok) {
                        console.error('HTTP', r.status, text);
                        throw new Error(`HTTP ${r.status} — الملف موجود؟ راجع مسار confirm_purchase_return.php`);
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

        // ⚠ اختصار قادم من زر "إنشاء مرتجع" بصفحة فواتير المشتريات —
        // يفتح المودال ويختار الفاتورة تلقائياً، بدل ما يرجع المستخدم
        // يدوّر عليها يدوياً من الكومبوبوكس.
        <?php if (!empty($_GET['open_purchase_id'])): ?>
            (function () {
                const targetId = <?= (int) $_GET['open_purchase_id'] ?>;
                openNewReturn();
                const tryFind = () => {
                    if (_rPurchasesMap[targetId]) {
                        document.getElementById('rPurchaseSelect').value = targetId;
                        onPurchaseSelectChange();
                    } else {
                        // الفاتورة المطلوبة مش ضمن أحدث 15 (نادر) — ابحث عنها تحديداً بالرقم
                        post({ _action: 'find_purchase', q: '<?= addslashes($_GET['open_purchase_id']) ?>' });
                        setTimeout(() => {
                            if (_rPurchasesMap[targetId]) {
                                document.getElementById('rPurchaseSelect').value = targetId;
                                onPurchaseSelectChange();
                            }
                        }, 400);
                    }
                };
                setTimeout(tryFind, 350); // ننتظر تحميل القائمة الأولي (searchPurchaseForReturn) لحد ما يخلص
            })();
        <?php endif; ?>
    </script>
</body>

</html>
