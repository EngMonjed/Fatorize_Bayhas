<?php
/**
 * purchases/reports.php — تقارير قسم المشتريات
 * المسار: retail1/modules/purchases/reports.php
 *
 * سبع تقارير (باقي التقارير — كشف حساب مورد، أعمار الذمم، الخصومات
 * والضريبة — انتقلت لقسم المحاسبة والمالية بقرار صريح، مو هون):
 *   1) سجل المشتريات   2) الأكثر شراءً   3) تقرير المرتجعات
 *   4) نسبة الإرجاع لكل مورد   5) فرص خصم تعجيل الدفع القريبة
 *   6) توزيع المشتريات حسب العملة   7) تاريخ سعر الشراء لكل منتج
 *
 * كل التقارير قراءة فقط (لا أي تعديل بيانات) — بلا معاملات، بلا أقفال.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('purchases.reports', 'view');
$currentModule = 'purchases.reports';

$TS = $_SESSION['table_suffix'];
$TP = "purchases_{$TS}";
$TPI = "purchase_items_{$TS}";
$TR = "purchase_returns_{$TS}";
$TSP = "product_suppliers_{$TS}";
$TPROD = "products_{$TS}";
$TV = "product_variants_{$TS}";
$TPSZ = "product_sizes_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// ⚠ عملة الفرع الأساسية — عبر base_currency_id (FK)، لا رمز ثابت بالكود
// (نفس القاعدة المعتمدة بـindex.php وreturns.php بالضبط). كل التقارير
// السبعة كانت بتكتب "$" ثابت بكل مكان بدل هالقيمة الحقيقية.
$baseCurSym = '$';
if (!empty($_SESSION['branch_id'])) {
    $bc = $pdo->prepare("SELECT c.symbol FROM branches b JOIN currencies c ON c.id=b.base_currency_id WHERE b.id=?");
    $bc->execute([$_SESSION['branch_id']]);
    $baseCurSym = $bc->fetchColumn() ?: '$';
}

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];
        $from = $_POST['from'] ?? '';
        $to = $_POST['to'] ?? '';

        // ١) سجل المشتريات
        if ($act === 'rep_register') {
            // ⚠ قرار صريح: كل تقارير التجميع بهالملف تستبعد الفواتير/
            // المرتجعات الملغاة (status='cancelled') من الإحصائيات —
            // فاتورة اتأكدت ثم انلغت بالكامل (مخزون رجع، قيد انعكس) صفر
            // أثر حقيقي على الأعمال، فما لازم تُحسب بـ"الأكثر شراءً"/
            // "توزيع العملة"/"نسبة الإرجاع". كان الفلتر السابق (!='draft')
            // بيشملها بالغلط — نفس النمط بكل الاستعلامات هون فصاعداً.
            $w = ["p.status = 'confirmed'"];
            $prm = [];
            if ($from) {
                $w[] = 'p.purchase_date >= ?';
                $prm[] = $from;
            }
            if ($to) {
                $w[] = 'p.purchase_date <= ?';
                $prm[] = $to;
            }
            $sql = "SELECT p.purchase_number, p.purchase_date, s.name AS supplier_name,
                    p.final_amount, p.final_amount_base_currency, p.payment_status, p.status,
                    c.symbol AS cur_sym, c.code AS cur_code
                FROM `{$TP}` p
                LEFT JOIN `{$TSP}` s ON s.id = p.supplier_id
                LEFT JOIN currencies c ON c.id = p.invoice_currency_id
                WHERE " . implode(' AND ', $w) . "
                ORDER BY p.purchase_date DESC, p.id DESC";
            $st = $pdo->prepare($sql);
            $st->execute($prm);
            $rows = $st->fetchAll();
            echo json_encode([
                'ok' => true,
                'rows' => $rows,
                'summary' => [
                    'count' => count($rows),
                    'total_base' => array_sum(array_column($rows, 'final_amount_base_currency')),
                ]
            ]);
            exit;
        }

        // ٢) الأكثر شراءً
        if ($act === 'rep_top_suppliers') {
            $w = ["p.status = 'confirmed'"];
            $prm = [];
            if ($from) {
                $w[] = 'p.purchase_date >= ?';
                $prm[] = $from;
            }
            if ($to) {
                $w[] = 'p.purchase_date <= ?';
                $prm[] = $to;
            }
            $sql = "SELECT s.id, s.name AS supplier_name,
                    COUNT(p.id) AS invoices_count,
                    SUM(p.final_amount_base_currency) AS total_base
                FROM `{$TP}` p
                LEFT JOIN `{$TSP}` s ON s.id = p.supplier_id
                WHERE " . implode(' AND ', $w) . "
                GROUP BY s.id, s.name
                ORDER BY total_base DESC
                LIMIT 30";
            $st = $pdo->prepare($sql);
            $st->execute($prm);
            echo json_encode(['ok' => true, 'rows' => $st->fetchAll()]);
            exit;
        }

        // ٣) تقرير المرتجعات
        if ($act === 'rep_returns') {
            $w = ["r.status = 'posted'"];
            $prm = [];
            if ($from) {
                $w[] = 'r.return_date >= ?';
                $prm[] = $from;
            }
            if ($to) {
                $w[] = 'r.return_date <= ?';
                $prm[] = $to;
            }
            $sql = "SELECT r.return_number, r.purchase_number, r.return_date, s.name AS supplier_name,
                    r.return_reason, r.return_amount, r.status,
                    c.symbol AS cur_sym,
                    -- ⚠ إصلاح: return_amount أصلاً بعملة الفرع دائماً (مشتق
                    -- من purchase_items.total_price المخزَّن بعملة الفرع —
                    -- قرار invoice_new.php)، لا حاجة لأي قسمة على
                    -- exchange_rate هون. كانت تحويل مزدوج غلط، نفس فئة باگ
                    -- \$unitBase يلي صلّحناه بـconfirm_purchase_invoice.php.
                    r.return_amount AS return_amount_base
                FROM `{$TR}` r
                LEFT JOIN `{$TSP}` s ON s.id = r.supplier_id
                LEFT JOIN currencies c ON c.id = r.return_currency_id
                WHERE " . implode(' AND ', $w) . "
                ORDER BY r.return_date DESC, r.id DESC";
            $st = $pdo->prepare($sql);
            $st->execute($prm);
            $rows = $st->fetchAll();

            // تجميع حسب السبب — لأكتر سبب متكرر
            $byReason = [];
            foreach ($rows as $r) {
                $reason = trim($r['return_reason']) ?: 'غير محدَّد';
                $byReason[$reason] = ($byReason[$reason] ?? 0) + (float) $r['return_amount_base'];
            }
            arsort($byReason);

            echo json_encode([
                'ok' => true,
                'rows' => $rows,
                'by_reason' => $byReason,
                'total_base' => array_sum(array_column($rows, 'return_amount_base'))
            ]);
            exit;
        }

        // ٤) نسبة الإرجاع لكل مورد
        if ($act === 'rep_return_ratio') {
            $w = ["p.status = 'confirmed'"];
            $prm = [];
            if ($from) {
                $w[] = 'p.purchase_date >= ?';
                $prm[] = $from;
            }
            if ($to) {
                $w[] = 'p.purchase_date <= ?';
                $prm[] = $to;
            }
            $purchasesBySupplier = $pdo->prepare("SELECT s.id, s.name AS supplier_name,
                    SUM(p.final_amount_base_currency) AS purchases_base
                FROM `{$TP}` p LEFT JOIN `{$TSP}` s ON s.id = p.supplier_id
                WHERE " . implode(' AND ', $w) . "
                GROUP BY s.id, s.name");
            $purchasesBySupplier->execute($prm);
            $purchMap = [];
            foreach ($purchasesBySupplier->fetchAll() as $row)
                $purchMap[$row['id']] = $row;

            $w2 = ["r.status = 'posted'"];
            $prm2 = [];
            if ($from) {
                $w2[] = 'r.return_date >= ?';
                $prm2[] = $from;
            }
            if ($to) {
                $w2[] = 'r.return_date <= ?';
                $prm2[] = $to;
            }
            $returnsBySupplier = $pdo->prepare("SELECT r.supplier_id,
                    -- ⚠ نفس الإصلاح — return_amount أصلاً بعملة الفرع.
                    SUM(r.return_amount) AS returns_base
                FROM `{$TR}` r WHERE " . implode(' AND ', $w2) . "
                GROUP BY r.supplier_id");
            $returnsBySupplier->execute($prm2);
            $retMap = [];
            foreach ($returnsBySupplier->fetchAll() as $row)
                $retMap[$row['supplier_id']] = (float) $row['returns_base'];

            $rows = [];
            foreach ($purchMap as $sid => $row) {
                $purchBase = (float) $row['purchases_base'];
                $retBase = $retMap[$sid] ?? 0;
                $rows[] = [
                    'supplier_name' => $row['supplier_name'],
                    'purchases_base' => $purchBase,
                    'returns_base' => $retBase,
                    'ratio' => $purchBase > 0 ? round($retBase / $purchBase * 100, 2) : 0,
                ];
            }
            usort($rows, fn($a, $b) => $b['ratio'] <=> $a['ratio']);
            echo json_encode(['ok' => true, 'rows' => $rows]);
            exit;
        }

        // ٥) فرص خصم تعجيل الدفع القريبة
        if ($act === 'rep_settlement_opportunities') {
            $sql = "SELECT p.purchase_number, s.name AS supplier_name, p.due_date,
                    p.settlement_discount_pct, p.final_amount_base_currency, p.balance_amount,
                    p.final_amount, c.symbol AS cur_sym,
                    DATEDIFF(p.due_date, CURDATE()) AS days_left
                FROM `{$TP}` p
                LEFT JOIN `{$TSP}` s ON s.id = p.supplier_id
                LEFT JOIN currencies c ON c.id = p.invoice_currency_id
                WHERE p.status = 'confirmed'
                  AND p.payment_status != 'paid'
                  AND p.settlement_discount_pct > 0
                  AND p.due_date >= CURDATE()
                ORDER BY p.due_date ASC";
            $rows = $pdo->query($sql)->fetchAll();
            foreach ($rows as &$r) {
                $r['potential_saving_base'] = round((float) $r['final_amount_base_currency'] * (float) $r['settlement_discount_pct'] / 100, 2);
            }
            echo json_encode([
                'ok' => true,
                'rows' => $rows,
                'total_potential_saving' => array_sum(array_column($rows, 'potential_saving_base'))
            ]);
            exit;
        }

        // ٦) توزيع المشتريات حسب العملة
        if ($act === 'rep_currency_dist') {
            $w = ["p.status = 'confirmed'"];
            $prm = [];
            if ($from) {
                $w[] = 'p.purchase_date >= ?';
                $prm[] = $from;
            }
            if ($to) {
                $w[] = 'p.purchase_date <= ?';
                $prm[] = $to;
            }
            $sql = "SELECT c.code AS cur_code, c.symbol AS cur_sym,
                    COUNT(p.id) AS invoices_count,
                    SUM(p.final_amount) AS total_orig,
                    SUM(p.final_amount_base_currency) AS total_base
                FROM `{$TP}` p LEFT JOIN currencies c ON c.id = p.invoice_currency_id
                WHERE " . implode(' AND ', $w) . "
                GROUP BY c.code, c.symbol
                ORDER BY total_base DESC";
            $st = $pdo->prepare($sql);
            $st->execute($prm);
            echo json_encode(['ok' => true, 'rows' => $st->fetchAll()]);
            exit;
        }

        // بحث منتج (لتقرير تاريخ السعر)
        if ($act === 'search_product') {
            $q = trim($_POST['q'] ?? '');
            $st = $pdo->prepare("SELECT id, name, model_number FROM `{$TPROD}`
                WHERE name LIKE ? OR model_number LIKE ? LIMIT 15");
            $like = "%{$q}%";
            $st->execute([$like, $like]);
            echo json_encode(['ok' => true, 'rows' => $st->fetchAll()]);
            exit;
        }

        // ٧) تاريخ سعر الشراء لمنتج معيّن
        if ($act === 'rep_price_history') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            if (!$productId) {
                echo json_encode(['ok' => false, 'msg' => 'اختر منتج أولاً']);
                exit;
            }
            $sql = "SELECT p.purchase_date, p.purchase_number, s.name AS supplier_name,
                    pi.unit_price_base_currency, pi.unit_price, c.symbol AS cur_sym,
                    psz.size AS size, psz.age_type AS age_type
                FROM `{$TPI}` pi
                JOIN `{$TP}` p ON p.id = pi.purchase_id
                LEFT JOIN `{$TSP}` s ON s.id = p.supplier_id
                LEFT JOIN currencies c ON c.id = p.invoice_currency_id
                LEFT JOIN `{$TV}` pv ON pv.id = pi.variant_id
                LEFT JOIN `{$TPSZ}` psz ON psz.id = pv.size_id
                WHERE pi.product_id = ? AND p.status = 'confirmed'
                ORDER BY p.purchase_date ASC, p.id ASC";
            $st = $pdo->prepare($sql);
            $st->execute([$productId]);
            echo json_encode(['ok' => true, 'rows' => $st->fetchAll()]);
            exit;
        }

        echo json_encode(['ok' => false, 'msg' => 'إجراء غير معروف']);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقارير المشتريات — FATORIZE</title>
    <link rel="icon" type="image/png" href="<?= BASE_PATH ?>/assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/assets/css/layout.css" rel="stylesheet">
    <style>
        .n {
            font-variant-numeric: tabular-nums
        }

        .rep-pill {
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 8px 14px;
            font-size: .8rem;
            font-weight: 600;
            color: #64748b;
            background: #fff;
            cursor: pointer;
            transition: all .15s;
            white-space: nowrap;
        }

        /* ⚠ إصلاح: الجدول كان يتقلّص لعرض محتواه فعلياً (خصوصاً بتقارير
        قليلة الصفوف/قصيرة النصوص كأرقام)، فيبان "مضغوط ومزنوق" بزاوية
        وحدة بدل ما يمتد على كامل عرض البطاقة زي باقي جداول القسم. الحل:
        فرض العرض الكامل + توزيع أعمدة تلقائي متساوي نسبياً. */
        #repBody .table-responsive {
            width: 100%;
        }

        #repBody table.mtbl {
            width: 100% !important;
            table-layout: auto;
        }

        #repBody table.mtbl th,
        #repBody table.mtbl td {
            white-space: nowrap;
        }

        /* ⚠ نفس التعريفات المحلية الناقصة تماماً من index.php — راجع
        نفس الشرح بملف returns.php، نفس السبب بالضبط. */
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

        table.mtbl th {
            background: #f8fafc;
            padding: 8px 12px;
            font-weight: 600;
            color: #64748b;
            font-size: .72rem;
            border-bottom: 1px solid #f1f5f9
        }

        table.mtbl td {
            padding: 8px 12px;
            border-bottom: 1px solid #f8fafc;
            vertical-align: middle
        }

        table.mtbl tr:last-child td {
            border-bottom: none
        }

        table.mtbl tr:hover td {
            background: #f8faff
        }

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

        .rep-pill:hover {
            border-color: var(--section-color);
            color: var(--section-color)
        }

        .rep-pill.active {
            background: var(--section-color);
            color: #fff;
            border-color: var(--section-color)
        }

        @media print {
            .no-print {
                display: none !important
            }
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
        <span class="tb-title"><i class="bi bi-bar-chart me-1 text-primary"></i>تقارير المشتريات</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <?= renderBreadcrumb() ?>
    </header>

    <main class="main-content">
        <div class="content-body">

            <!-- تبويبات القسم -->
            <ul class="nav nav-tabs mb-3 no-print" style="border-bottom:2px solid #e2e8f0">
                <li class="nav-item">
                    <a class="nav-link fw-600" href="index.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-receipt me-1"></i>فواتير المشتريات
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="suppliers.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-people me-1"></i>إدارة الموردين
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="returns.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-arrow-return-right me-1"></i>مرتجعات المشتريات
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="orders.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-file-earmark-text me-1"></i>أوامر الشراء / طلبات عروض الأسعار
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600 active" href="reports.php"
                        style="border:none;border-bottom:2px solid var(--section-color);color:var(--section-color);font-size:.83rem;margin-bottom:-2px">
                        <i class="bi bi-bar-chart me-1"></i>التقارير
                    </a>
                </li>
            </ul>

            <!-- منتقي التقرير -->
            <div class="d-flex gap-2 flex-wrap mb-3 no-print" id="repPills">
                <div class="rep-pill active" data-rep="register" onclick="switchReport('register')">
                    <i class="bi bi-journal-text me-1"></i>سجل المشتريات
                </div>
                <div class="rep-pill" data-rep="top_suppliers" onclick="switchReport('top_suppliers')">
                    <i class="bi bi-trophy me-1"></i>الأكثر شراءً
                </div>
                <div class="rep-pill" data-rep="returns" onclick="switchReport('returns')">
                    <i class="bi bi-arrow-return-right me-1"></i>تقرير المرتجعات
                </div>
                <div class="rep-pill" data-rep="return_ratio" onclick="switchReport('return_ratio')">
                    <i class="bi bi-percent me-1"></i>نسبة الإرجاع لكل مورد
                </div>
                <div class="rep-pill" data-rep="settlement" onclick="switchReport('settlement')">
                    <i class="bi bi-lightning-charge me-1"></i>فرص خصم تعجيل الدفع
                </div>
                <div class="rep-pill" data-rep="currency_dist" onclick="switchReport('currency_dist')">
                    <i class="bi bi-currency-exchange me-1"></i>توزيع حسب العملة
                </div>
                <div class="rep-pill" data-rep="price_history" onclick="switchReport('price_history')">
                    <i class="bi bi-graph-up me-1"></i>تاريخ سعر الشراء
                </div>
            </div>

            <!-- شريط الفلاتر (فترة زمنية — لا ينطبق على كل التقارير) -->
            <div class="tbl-wrap mb-3">
                <div class="tbl-hdr no-print">
                    <span style="font-size:.88rem;font-weight:700;color:#1e293b" id="repTitle">
                        <i class="bi bi-journal-text me-1 text-primary"></i>سجل المشتريات
                    </span>
                    <div class="d-flex gap-2 ms-auto flex-wrap align-items-center">
                        <div id="dateFilterWrap" class="d-flex gap-2 align-items-center">
                            <input type="date" id="repFrom" class="form-control form-control-sm"
                                style="width:150px;border-radius:8px">
                            <span style="font-size:.8rem;color:#94a3b8">—</span>
                            <input type="date" id="repTo" class="form-control form-control-sm"
                                style="width:150px;border-radius:8px">
                        </div>
                        <div id="productPickerWrap" style="display:none">
                            <input type="text" id="repProductSearch" class="form-control form-control-sm"
                                placeholder="ابحث عن منتج بالاسم أو رقم الموديل..."
                                style="width:260px;border-radius:8px" oninput="searchProductForReport()">
                            <div id="repProductResults" class="list-group position-absolute"
                                style="max-height:220px;overflow:auto;z-index:20;width:260px"></div>
                        </div>
                        <button class="btn btn-sm btn-primary" style="border-radius:8px" onclick="loadReport()">
                            <i class="bi bi-search me-1"></i>تطبيق
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" style="border-radius:8px"
                            onclick="window.print()">
                            <i class="bi bi-printer me-1"></i>طباعة
                        </button>
                    </div>
                </div>

                <!-- ترويسة الطباعة (تظهر بس عند الطباعة) -->
                <div class="d-none d-print-flex justify-content-between align-items-center p-3"
                    style="border-bottom:2px solid var(--section-color)">
                    <div class="d-flex align-items-center gap-2">
                        <img src="<?= BASE_PATH ?>/assets/images/fatorize.png" style="height:34px">
                        <div>
                            <div style="font-weight:700;color:var(--section-color)">فاتورايز — FATORIZE</div>
                            <div style="font-size:.75rem;color:#64748b" id="printSubtitle">سجل المشتريات</div>
                        </div>
                    </div>
                    <div style="font-size:.75rem;color:#64748b"><?= htmlspecialchars($branchName) ?> —
                        <?= date('Y-m-d') ?>
                    </div>
                </div>

                <!-- بطاقات الملخص -->
                <div class="row g-3 p-3 no-print" id="repSummaryCards"></div>

                <div id="repBody" class="p-2">
                    <div class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm"></span>
                        جارٍ التحميل...</div>
                </div>
            </div>

        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentReport = 'register';
        // رمز عملة الفرع الأساسية الحقيقي — بدل "$" الثابت المكرَّر بكل تقرير
        const BASE_CUR_SYM = <?= json_encode($baseCurSym) ?>;
        let selectedProductId = null;
        let selectedProductLabel = '';

        function post(data) {
            const fd = new FormData();
            for (const k in data) fd.append(k, data[k]);
            return fetch('reports.php', {method: 'POST', body: fd}).then(r => r.json());
        }

        const fmt = n => new Intl.NumberFormat('en').format(parseFloat(n || 0).toFixed(2));

        const REPORTS = {
            register: {title: 'سجل المشتريات', icon: 'bi-journal-text', needsDate: true, needsProduct: false},
            top_suppliers: {title: 'الأكثر شراءً', icon: 'bi-trophy', needsDate: true, needsProduct: false},
            returns: {title: 'تقرير المرتجعات', icon: 'bi-arrow-return-right', needsDate: true, needsProduct: false},
            return_ratio: {title: 'نسبة الإرجاع لكل مورد', icon: 'bi-percent', needsDate: true, needsProduct: false},
            settlement: {title: 'فرص خصم تعجيل الدفع القريبة', icon: 'bi-lightning-charge', needsDate: false, needsProduct: false},
            currency_dist: {title: 'توزيع المشتريات حسب العملة', icon: 'bi-currency-exchange', needsDate: true, needsProduct: false},
            price_history: {title: 'تاريخ سعر الشراء لكل منتج', icon: 'bi-graph-up', needsDate: false, needsProduct: true},
        };

        function switchReport(rep) {
            currentReport = rep;
            document.querySelectorAll('.rep-pill').forEach(p => p.classList.toggle('active', p.dataset.rep === rep));
            const meta = REPORTS[rep];
            document.getElementById('repTitle').innerHTML = `<i class="bi ${meta.icon} me-1 text-primary"></i>${meta.title}`;
            document.getElementById('printSubtitle').textContent = meta.title;
            document.getElementById('dateFilterWrap').style.display = meta.needsDate ? '' : 'none';
            document.getElementById('productPickerWrap').style.display = meta.needsProduct ? '' : 'none';
            loadReport();
        }

        function searchProductForReport() {
            const q = document.getElementById('repProductSearch').value.trim();
            if (q.length < 2) {document.getElementById('repProductResults').innerHTML = ''; return;}
            post({_action: 'search_product', q}).then(d => {
                const box = document.getElementById('repProductResults');
                if (!d.ok || !d.rows.length) {box.innerHTML = ''; return;}
                box.innerHTML = d.rows.map(p => `
                    <button type="button" class="list-group-item list-group-item-action" style="font-size:.8rem"
                        onclick="selectProductForReport(${p.id}, '${(p.name + ' ' + (p.model_number || '')).replace(/'/g, "")}')">
                        ${p.name} <span class="text-muted">${p.model_number || ''}</span>
                    </button>`).join('');
            });
        }

        function selectProductForReport(id, label) {
            selectedProductId = id;
            selectedProductLabel = label;
            document.getElementById('repProductSearch').value = label;
            document.getElementById('repProductResults').innerHTML = '';
            loadReport();
        }

        function loadReport() {
            const from = document.getElementById('repFrom').value;
            const to = document.getElementById('repTo').value;
            document.getElementById('repBody').innerHTML = '<div class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm"></span> جارٍ التحميل...</div>';
            document.getElementById('repSummaryCards').innerHTML = '';

            const actMap = {
                register: 'rep_register', top_suppliers: 'rep_top_suppliers', returns: 'rep_returns',
                return_ratio: 'rep_return_ratio', settlement: 'rep_settlement_opportunities',
                currency_dist: 'rep_currency_dist', price_history: 'rep_price_history'
            };
            const payload = {_action: actMap[currentReport], from, to};
            if (currentReport === 'price_history') payload.product_id = selectedProductId || '';

            post(payload).then(d => {
                if (!d.ok) {document.getElementById('repBody').innerHTML = `<div class="text-danger text-center py-4">${d.msg}</div>`; return;}
                RENDERERS[currentReport](d);
            });
        }

        function statCard(label, value, icon, color, bg) {
            return `<div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background:${bg}"><i class="bi ${icon}" style="color:${color}"></i></div>
                    <div><div class="stat-val">${value}</div><div class="stat-lbl">${label}</div></div>
                </div></div>`;
        }

        function emptyMsg(text) {
            return `<div class="text-center text-muted py-5"><i class="bi bi-inbox d-block mb-2" style="font-size:2rem;opacity:.2"></i>${text}</div>`;
        }

        const PAY_LABELS = {paid: 'مدفوعة', partial: 'جزئي', pending: 'غير مدفوعة'};
        // ⚠ خريطتين منفصلتين — الفاتورة والمرتجع عندهم قيم status مختلفة
        // فعلياً بقاعدة البيانات (draft/confirmed/cancelled للفاتورة،
        // draft/posted/cancelled للمرتجع، تأكدنا منها بالـALTER TABLE
        // سابقاً)، خريطة وحدة مشتركة كانت تعرض قيمة خام غير مترجمة لأحدهم.
        const PUR_STATUS_LABELS = {draft: 'مسودة', confirmed: 'مؤكدة', cancelled: 'ملغاة'};
        const RET_STATUS_LABELS = {draft: 'مسودة', posted: 'مؤكد', cancelled: 'ملغى'};

        const RENDERERS = {
            register(d) {
                document.getElementById('repSummaryCards').innerHTML =
                    statCard('عدد الفواتير', d.summary.count, 'bi-receipt', '#1e3a8a', '#eff6ff') +
                    statCard('الإجمالي (عملة الفرع)', BASE_CUR_SYM + ' ' + fmt(d.summary.total_base), 'bi-cash-stack', '#16a34a', '#f0fdf4');
                if (!d.rows.length) {document.getElementById('repBody').innerHTML = emptyMsg('لا توجد فواتير بهالفترة'); return;}
                document.getElementById('repBody').innerHTML = `<div class="table-responsive"><table class="mtbl" id="repTable">
                    <thead><tr>
                        <th style="color:#1e3a8a">رقم الفاتورة</th><th>التاريخ</th><th style="color:#16a34a">المورد</th>
                        <th>الإجمالي (عملة الفرع)</th><th>الدفع</th><th>الحالة</th>
                    </tr></thead><tbody>${d.rows.map(r => `
                        <tr>
                            <td class="fw-600" style="color:#1e3a8a" dir="ltr">${r.purchase_number}</td>
                            <td class="text-muted">${r.purchase_date}</td>
                            <td style="color:#16a34a" class="fw-600">${r.supplier_name || '—'}</td>
                            <td class="n text-success fw-600">${BASE_CUR_SYM} ${fmt(r.final_amount_base_currency)}</td>
                            <td><span class="badge bg-light text-dark">${PAY_LABELS[r.payment_status] || r.payment_status}</span></td>
                            <td><span class="badge bg-light text-dark">${PUR_STATUS_LABELS[r.status] || r.status}</span></td>
                        </tr>`).join('')}</tbody></table></div>`;
                makeSortable(document.getElementById('repTable'));
            },
            top_suppliers(d) {
                if (!d.rows.length) {document.getElementById('repBody').innerHTML = emptyMsg('لا توجد بيانات'); return;}
                document.getElementById('repBody').innerHTML = `<div class="table-responsive"><table class="mtbl" id="repTable">
                    <thead><tr><th>#</th><th style="color:#16a34a">المورد</th><th>عدد الفواتير</th><th>الإجمالي (عملة الفرع)</th></tr></thead>
                    <tbody>${d.rows.map((r, i) => `
                        <tr>
                            <td class="text-muted">${i + 1}</td>
                            <td style="color:#16a34a" class="fw-600">${r.supplier_name || '—'}</td>
                            <td class="n">${r.invoices_count}</td>
                            <td class="n fw-600 text-success">${BASE_CUR_SYM} ${fmt(r.total_base)}</td>
                        </tr>`).join('')}</tbody></table></div>`;
                makeSortable(document.getElementById('repTable'));
            },
            returns(d) {
                const reasonsHtml = Object.entries(d.by_reason).slice(0, 4).map(([reason, amt]) =>
                    statCard(reason, BASE_CUR_SYM + ' ' + fmt(amt), 'bi-tag', '#dc2626', '#fef2f2')).join('');
                document.getElementById('repSummaryCards').innerHTML =
                    statCard('إجمالي المرتجعات (عملة الفرع)', BASE_CUR_SYM + ' ' + fmt(d.total_base), 'bi-arrow-return-right', '#dc2626', '#fef2f2') + reasonsHtml;
                if (!d.rows.length) {document.getElementById('repBody').innerHTML = emptyMsg('لا توجد مرتجعات بهالفترة'); return;}
                document.getElementById('repBody').innerHTML = `<div class="table-responsive"><table class="mtbl" id="repTable">
                    <thead><tr>
                        <th style="color:#1e3a8a">رقم المرتجع</th><th>الفاتورة الأصلية</th><th>التاريخ</th>
                        <th style="color:#16a34a">المورد</th><th>السبب</th><th>القيمة</th><th>الحالة</th>
                    </tr></thead><tbody>${d.rows.map(r => `
                        <tr>
                            <td class="fw-600" style="color:#1e3a8a" dir="ltr">${r.return_number}</td>
                            <td class="text-muted" dir="ltr">${r.purchase_number}</td>
                            <td class="text-muted">${r.return_date}</td>
                            <td style="color:#16a34a" class="fw-600">${r.supplier_name || '—'}</td>
                            <td style="font-size:.78rem">${r.return_reason || '—'}</td>
                            <td class="n">${fmt(r.return_amount)} ${r.cur_sym || ''}</td>
                            <td><span class="badge bg-light text-dark">${RET_STATUS_LABELS[r.status] || r.status}</span></td>
                        </tr>`).join('')}</tbody></table></div>`;
                makeSortable(document.getElementById('repTable'));
            },
            return_ratio(d) {
                if (!d.rows.length) {document.getElementById('repBody').innerHTML = emptyMsg('لا توجد بيانات'); return;}
                document.getElementById('repBody').innerHTML = `<div class="table-responsive"><table class="mtbl" id="repTable">
                    <thead><tr><th style="color:#16a34a">المورد</th><th>إجمالي المشتريات</th><th>إجمالي المرتجعات</th><th>نسبة الإرجاع</th></tr></thead>
                    <tbody>${d.rows.map(r => {
                    const color = r.ratio >= 10 ? '#dc2626' : (r.ratio >= 3 ? '#d97706' : '#16a34a');
                    return `<tr>
                            <td style="color:#16a34a" class="fw-600">${r.supplier_name || '—'}</td>
                            <td class="n">${BASE_CUR_SYM} ${fmt(r.purchases_base)}</td>
                            <td class="n text-danger">${BASE_CUR_SYM} ${fmt(r.returns_base)}</td>
                            <td class="n fw-700" style="color:${color}">${r.ratio}%</td>
                        </tr>`;
                }).join('')}</tbody></table></div>`;
                makeSortable(document.getElementById('repTable'));
            },
            settlement(d) {
                document.getElementById('repSummaryCards').innerHTML =
                    statCard('عدد الفرص المتاحة', d.rows.length, 'bi-lightning-charge', '#16a34a', '#f0fdf4') +
                    statCard('إجمالي التوفير الممكن (عملة الفرع)', BASE_CUR_SYM + ' ' + fmt(d.total_potential_saving), 'bi-piggy-bank', '#16a34a', '#f0fdf4');
                if (!d.rows.length) {document.getElementById('repBody').innerHTML = emptyMsg('لا توجد فرص خصم تعجيل حالياً'); return;}
                document.getElementById('repBody').innerHTML = `<div class="table-responsive"><table class="mtbl" id="repTable">
                    <thead><tr>
                        <th style="color:#1e3a8a">رقم الفاتورة</th><th style="color:#16a34a">المورد</th>
                        <th>تاريخ الاستحقاق</th><th>الأيام المتبقية</th><th>نسبة الخصم</th>
                        <th>التوفير المحتمل</th><th>الرصيد الحالي</th>
                    </tr></thead><tbody>${d.rows.map(r => {
                    const urgent = r.days_left <= 3;
                    return `<tr ${urgent ? 'style="background:#fef2f2"' : ''}>
                            <td class="fw-600" style="color:#1e3a8a" dir="ltr">${r.purchase_number}</td>
                            <td style="color:#16a34a" class="fw-600">${r.supplier_name || '—'}</td>
                            <td class="text-muted">${r.due_date}</td>
                            <td class="n fw-700" style="color:${urgent ? '#dc2626' : '#d97706'}">${r.days_left} يوم</td>
                            <td class="n">${r.settlement_discount_pct}%</td>
                            <td class="n fw-600 text-success">${BASE_CUR_SYM} ${fmt(r.potential_saving_base)}</td>
                            <td class="n">${fmt(r.balance_amount)} ${r.cur_sym || ''}</td>
                        </tr>`;
                }).join('')}</tbody></table></div>`;
                makeSortable(document.getElementById('repTable'));
            },
            currency_dist(d) {
                if (!d.rows.length) {document.getElementById('repBody').innerHTML = emptyMsg('لا توجد بيانات'); return;}
                document.getElementById('repBody').innerHTML = `<div class="table-responsive"><table class="mtbl" id="repTable">
                    <thead><tr><th>العملة</th><th>عدد الفواتير</th><th>الإجمالي (بعملتها)</th><th>الإجمالي (عملة الفرع)</th></tr></thead>
                    <tbody>${d.rows.map(r => `
                        <tr>
                            <td class="fw-600" style="color:#dc2626">${r.cur_code}</td>
                            <td class="n">${r.invoices_count}</td>
                            <td class="n">${fmt(r.total_orig)} ${r.cur_sym || ''}</td>
                            <td class="n fw-600 text-success">${BASE_CUR_SYM} ${fmt(r.total_base)}</td>
                        </tr>`).join('')}</tbody></table></div>`;
                makeSortable(document.getElementById('repTable'));
            },
            price_history(d) {
                if (!selectedProductId) {document.getElementById('repBody').innerHTML = emptyMsg('ابحث واختر منتج فوق لعرض تاريخ أسعاره'); return;}
                if (!d.rows.length) {document.getElementById('repBody').innerHTML = emptyMsg('لا يوجد تاريخ شراء لهذا المنتج'); return;}

                // ⚠ إصلاح جوهري: المنتج الواحد ممكن يكون عنده عدة كروبات
                // (نطاقات مقاسات) بأسعار مختلفة تماماً — مقارنة "أول سعر
                // مقابل آخر سعر" على مستوى المنتج ككل كانت بتخلط كروبات
                // مختلفة وكأنها نفس السعر تغيّر عبر الزمن، وهاد غلط منطقياً.
                // الحل: نجمّع أول شي بالمقاس (كل مقاس له تسلسله الزمني
                // الخاص)، وبعدين ندمج المقاسات يلي عندها بالضبط نفس تسلسل
                // (تاريخ+سعر) عبر كل السجل التاريخي بصف "كروب" واحد — لأنه
                // هيك التعريف الحقيقي لـ"نفس الكروب": مقاسات اشتُريت دايماً
                // سوا بنفس السعر بكل مرة، من أول فاتورة لآخر وحدة.
                const bySize = {};
                d.rows.forEach(r => {
                    const sz = r.size || '—';
                    if (!bySize[sz]) bySize[sz] = { ageType: r.age_type || '', history: [] };
                    bySize[sz].history.push({
                        date: r.purchase_date, no: r.purchase_number, supplier: r.supplier_name || '—',
                        priceBase: parseFloat(r.unit_price_base_currency), priceOrig: parseFloat(r.unit_price),
                        sym: r.cur_sym || ''
                    });
                });
                const signature = obj => obj.history.map(x => x.date + ':' + x.no + ':' + x.priceBase.toFixed(4)).join('|');
                const grpMap = {};
                Object.entries(bySize).forEach(([sz, obj]) => {
                    const sig = signature(obj);
                    if (!grpMap[sig]) grpMap[sig] = { sizes: [], ageType: obj.ageType, history: obj.history };
                    grpMap[sig].sizes.push(sz);
                });
                const groups = Object.values(grpMap);

                // بطاقات ملخص — عدد الكروبات المميّزة، مو رقم واحد مضلِّل
                document.getElementById('repSummaryCards').innerHTML =
                    statCard('عدد الكروبات المميّزة', groups.length, 'bi-collection', '#1e3a8a', '#eff6ff') +
                    statCard('إجمالي سجلات الشراء', d.rows.length, 'bi-clock-history', '#64748b', '#f8fafc');

                document.getElementById('repBody').innerHTML = groups.map((g, gi) => {
                    const sizeLabel = formatSizeRangeSimple(g.sizes, g.ageType);
                    const first = g.history[0], last = g.history[g.history.length - 1];
                    const trend = g.history.length < 2 ? '<span class="text-muted">سجل واحد بس</span>'
                        : (last.priceBase > first.priceBase
                            ? '<span class="text-danger"><i class="bi bi-graph-up-arrow me-1"></i>مرتفع</span>'
                            : (last.priceBase < first.priceBase
                                ? '<span class="text-success"><i class="bi bi-graph-down-arrow me-1"></i>منخفض</span>'
                                : '<span class="text-muted"><i class="bi bi-dash me-1"></i>ثابت</span>'));
                    return `<div class="tbl-wrap mb-3">
                        <div class="tbl-hdr" style="justify-content:space-between">
                            <div>
                                <span class="badge" style="background:#eff6ff;color:#1e3a8a;font-size:.8rem">كروب ${gi + 1} — ${sizeLabel}</span>
                            </div>
                            <div style="font-size:.8rem">
                                <span style="color:#64748b">أول سعر:</span> <b>${BASE_CUR_SYM} ${fmt(first.priceBase)}</b>
                                <span style="color:#64748b;margin-right:10px">آخر سعر:</span> <b>${BASE_CUR_SYM} ${fmt(last.priceBase)}</b>
                                <span style="margin-right:10px">${trend}</span>
                            </div>
                        </div>
                        <div class="table-responsive">
                        <table class="mtbl">
                            <thead><tr><th>التاريخ</th><th>رقم الفاتورة</th><th style="color:#16a34a">المورد</th><th>السعر (بعملة الفاتورة)</th><th>السعر (عملة الفرع)</th></tr></thead>
                            <tbody>${g.history.map(h => `
                                <tr>
                                    <td class="text-muted">${h.date}</td>
                                    <td dir="ltr">${h.no}</td>
                                    <td style="color:#16a34a" class="fw-600">${h.supplier}</td>
                                    <td class="n">${fmt(h.priceOrig)} ${h.sym}</td>
                                    <td class="n fw-600 text-success">${BASE_CUR_SYM} ${fmt(h.priceBase)}</td>
                                </tr>`).join('')}</tbody>
                        </table>
                        </div>
                    </div>`;
                }).join('');
            },
        };

        // نطاق مقاسات مبسَّط لعنوان الكروب — نفس مبدأ formatSizeRange
        // المستخدم بباقي صفحات القسم، بدون الحاجة لكائن group كامل هون.
        function formatSizeRangeSimple(sizes, ageType) {
            if (!sizes.length) return '—';
            const nums = sizes.map(s => parseFloat(s)).filter(n => !isNaN(n));
            if (!nums.length) return sizes.join(' · ') + (ageType ? ' ' + ageType : '');
            const min = Math.min(...nums), max = Math.max(...nums);
            const rangeTxt = min === max ? `${min}` : `${min}-${max}`;
            return ageType ? `${ageType} ${rangeTxt}` : rangeTxt;
        }

        // فرز الجداول — نفس الدالة المعتمدة بكل صفحات القسم
        function makeSortable(table) {
            if (!table) return;
            table.querySelectorAll('thead th').forEach((th, colIndex) => {
                th.style.cursor = 'pointer'; th.style.userSelect = 'none'; th.title = 'اضغط للفرز';
                th.addEventListener('click', () => sortTableByColumn(table, colIndex, th));
            });
        }
        function sortTableByColumn(table, colIndex, th) {
            const tbody = table.querySelector('tbody');
            if (!tbody) return;
            const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.children.length > colIndex);
            const isAsc = th.getAttribute('data-sort-dir') !== 'asc';
            table.querySelectorAll('thead th').forEach(h => {h.removeAttribute('data-sort-dir'); h.querySelector('.sort-ind')?.remove();});
            th.setAttribute('data-sort-dir', isAsc ? 'asc' : 'desc');
            const getCellValue = (row) => (row.children[colIndex]?.innerText || '').trim();
            const isoDateRe = /^\d{4}-\d{2}-\d{2}/;
            rows.sort((a, b) => {
                const valA = getCellValue(a), valB = getCellValue(b);
                if (isoDateRe.test(valA) && isoDateRe.test(valB)) {
                    return isAsc ? new Date(valA).getTime() - new Date(valB).getTime() : new Date(valB).getTime() - new Date(valA).getTime();
                }
                const cleanA = valA.replace(/[^0-9.\-]/g, ''), cleanB = valB.replace(/[^0-9.\-]/g, '');
                const fullyNumeric = /^-?[0-9]+(\.[0-9]+)?$/.test(cleanA) && /^-?[0-9]+(\.[0-9]+)?$/.test(cleanB) && cleanA !== '' && cleanB !== '';
                if (fullyNumeric) return isAsc ? parseFloat(cleanA) - parseFloat(cleanB) : parseFloat(cleanB) - parseFloat(cleanA);
                return isAsc ? valA.localeCompare(valB, 'ar') : valB.localeCompare(valA, 'ar');
            });
            rows.forEach(row => tbody.appendChild(row));
            const ind = document.createElement('i');
            ind.className = 'bi bi-caret-' + (isAsc ? 'up' : 'down') + '-fill sort-ind';
            ind.style.cssText = 'font-size:.65rem;margin-right:4px';
            th.appendChild(ind);
        }

        // تحميل أولي — آخر ٣٠ يوم افتراضياً
        (function initDates() {
            const to = new Date(), from = new Date();
            from.setDate(from.getDate() - 30);
            document.getElementById('repTo').value = to.toISOString().slice(0, 10);
            document.getElementById('repFrom').value = from.toISOString().slice(0, 10);
        })();
        loadReport();
    </script>
    <!-- ✅ كانت مفقودة بالكامل — هي سبب البق (toggleGroup غير معرّفة).
         نفس السطر الموجود بـ returns.php وباقي الصفحات الشغالة صح. -->
    <script src="<?= BASE_PATH ?>/assets/js/sidebar.js"></script>
</body>

</html>