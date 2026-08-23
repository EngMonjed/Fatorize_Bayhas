<?php
/**
 * purchases/index.php — فواتير شراء المنتجات النهائية
 *retail1/modules/purchases/index.php
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('purchases.invoices', 'view');
$currentModule = 'purchases.invoices';

$TS = $_SESSION['table_suffix'];
$TP = "purchases_{$TS}";
$TPI = "purchase_items_{$TS}";
$TSP = "product_suppliers_{$TS}";
$TW = "warehouses_{$TS}";
$TWI = "warehouse_items_{$TS}";
$TV = "product_variants_{$TS}";
$TPR = "products_{$TS}";
$TPSZ = "product_sizes_{$TS}";
$TPCL = "product_colors_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// بيانات الفرع للطباعة
$branchInfo = $pdo->prepare("SELECT * FROM branches WHERE table_suffix=? LIMIT 1");
$branchInfo->execute([$TS]);
$branchInfo = $branchInfo->fetch(PDO::FETCH_ASSOC) ?: [];

// شركات الشحن
try {
    $shippingCarriers = $pdo->query("SELECT id,name,contact_person,phone FROM shipping_carriers WHERE status='active' ORDER BY name")->fetchAll();
} catch (Exception $e) {
    $shippingCarriers = [];
}

// بيانات المودال
try {
    $warehouses = $pdo->query("SELECT id,name FROM `{$TW}` WHERE status='active' ORDER BY name")->fetchAll();
} catch (Exception $e) {
    $warehouses = [];
}

try {
    $currencies = $pdo->query("SELECT id,code,symbol FROM currencies WHERE status='active' ORDER BY is_base DESC")->fetchAll();
} catch (Exception $e) {
    $currencies = [];
}

$TIAS = "invoice_account_settings_{$TS}";
$TAC = "account_charts_{$TS}";
try {
    $cashAccounts = $pdo->query("SELECT ac.id,ac.code,ac.name,ac.balance,ac.base_balance,
        c.code AS cur_code,c.symbol AS cur_sym
        FROM `{$TAC}` ac
        LEFT JOIN currencies c ON c.id=ac.currency_id
        WHERE ac.account_type='asset' AND ac.is_active=1 AND ac.level>=3
          AND ac.id NOT IN (SELECT prepaid_account_id FROM `{$TSP}` WHERE prepaid_account_id IS NOT NULL)
        ORDER BY ac.code")->fetchAll();
} catch (Exception $e) {
    $cashAccounts = [];
}
// رمز عملة الفرع الأساسية — لعرض المبالغ المحوَّلة بالجدول والإحصائيات
// بدل $ الثابتة (الفواتير هلق ممكن تكون بأي عملة، مو بس عملة الفرع).
$baseCurrencySymbol = '$';
if (!empty($_SESSION['branch_id'])) {
    $bcStmt = $pdo->prepare("SELECT c.symbol FROM branches b
        LEFT JOIN currencies c ON c.id = b.base_currency_id
        WHERE b.id = ?");
    $bcStmt->execute([$_SESSION['branch_id']]);
    $baseCurrencySymbol = $bcStmt->fetchColumn() ?: '$';
}
// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        if ($act === 'get_purchase') {
            $id = (int) $_POST['id'];
            $st = $pdo->prepare("SELECT p.*, s.name AS supplier_name,
                s.phone AS supplier_phone,
                s.email AS supplier_email,
                s.address AS supplier_address,
                s.tax_number AS supplier_tax,
                c.code AS currency_code,
                c.symbol AS currency_symbol,
                bc.code AS base_currency_code,
                w.name AS warehouse_name
                FROM `{$TP}` p
                LEFT JOIN `{$TSP}` s ON s.id=p.supplier_id
                LEFT JOIN currencies c ON c.id=p.invoice_currency_id
                LEFT JOIN currencies bc ON bc.id=p.base_currency_id
                LEFT JOIN `{$TW}` w ON w.id=p.warehouse_id
                WHERE p.id=?");
            $st->execute([$id]);
            $pur = $st->fetch();
            if (!$pur)
                throw new Exception('الفاتورة غير موجودة');
            // warehouse_name/warehouse_id now come from purchases_{TS}.warehouse_id
            // (one warehouse per whole invoice) instead of purchase_items_{TS} —
            // resolved per the schema-change design decision.
            // sizes use a `size` column (not `name`); colors use `name` — different
            // conventions between the two tables.
            $it = $pdo->prepare("SELECT pi.*,
                pr.name AS product_name,
                pr.model_number AS model_number,
                psz.size AS size,
                psz.age_type AS age_type,
                psz.group_key AS group_key,
                pcl.name AS color,
                pv.barcode AS barcode
                FROM `{$TPI}` pi
                LEFT JOIN `{$TPR}` pr ON pr.id=pi.product_id
                LEFT JOIN `{$TV}` pv ON pv.id=pi.variant_id
                LEFT JOIN `{$TPSZ}` psz ON psz.id=pv.size_id
                LEFT JOIN `{$TPCL}` pcl ON pcl.id=pv.color_id
                WHERE pi.purchase_id=? ORDER BY pi.id");
            $it->execute([$id]);
            $pur['items'] = $it->fetchAll();

            // حساب الدفعة المقدمة — لمورد هالفاتورة بالتحديد فقط (مو كل
            // الموردين)، مع رصيده الحالي، حتى تُعرض كخيار إضافي بقائمة
            // حساب الدفع بمودال التأكيد (تحكّم يدوي كامل — لا تطبيق تلقائي).
            $adv = $pdo->prepare("SELECT ac.id, ac.code, ac.name, ac.balance, ac.base_balance,
                    c.code AS cur_code, c.symbol AS cur_sym
                FROM `{$TSP}` s
                JOIN `{$TAC}` ac ON ac.id = s.prepaid_account_id
                LEFT JOIN currencies c ON c.id = ac.currency_id
                WHERE s.id = ?");
            $adv->execute([$pur['supplier_id']]);
            $pur['advance_account'] = $adv->fetch() ?: null;

            // ⚠ القيود المرتبطة فعلياً (لو الفاتورة مؤكدة) — نجيبها من
            // journal_entries_{TS} مباشرة (مصدر الحقيقة الفعلي)، مو من
            // أعمدة الفاتورة لوحدها، لأنه تفاصيل الشحن/الدفع الجزئي/
            // خصم التعجيل كل وحدة منها قيد مستقل بذاته (راجع
            // confirm_purchase_invoice.php)، ما بتنخزن كأعمدة على
            // purchases_{TS} نفسها.
            $TJE2 = "journal_entries_{$TS}";
            $jes = $pdo->prepare("SELECT je.*, c.code AS je_currency
                FROM `{$TJE2}` je
                LEFT JOIN currencies c ON c.id = je.currency_id
                WHERE je.reference_type IN ('purchase','purchase_shipping','purchase_payment','purchase_settlement_discount')
                  AND je.reference_id = ?
                ORDER BY je.id");
            $jes->execute([$id]);
            $pur['journal_entries'] = $jes->fetchAll();

            // ⚠ رصيد المورد (بعملة الفرع) — الحالي، و"قبل هالفاتورة"
            // تحديداً بعكس أثرها الخاص (القيد الرئيسي زاد الالتزام
            // بمقدار final_amount_base_currency عبر balance-=، وأي دفعة
            // زادت balance عبر balance+=) — نفس مبدأ كشف الحساب المختصر
            // بفواتير المبيعات. تقريب "لقطة" دقيق طالما ما في معاملات
            // تانية بينهم وبين لحظة الطباعة (الحالة الشائعة: طباعة فوراً
            // بعد التأكيد).
            $accSupBal = null;
            if (!empty($pur['supplier_id'])) {
                $stSA = $pdo->prepare("SELECT ac.base_balance FROM `{$TSP}` s
                    JOIN `{$TAC}` ac ON ac.id=s.account_id
                    WHERE s.id=? AND s.account_id IS NOT NULL LIMIT 1");
                $stSA->execute([$pur['supplier_id']]);
                $accSupBal = $stSA->fetch(PDO::FETCH_ASSOC);
            }
            $pur['supplier_balance_after'] = $accSupBal ? (float) $accSupBal['base_balance'] : null;
            $pur['supplier_balance_before'] = null;
            if ($pur['supplier_balance_after'] !== null && $pur['status'] === 'confirmed') {
                $rateForBal = (float) ($pur['exchange_rate'] ?: 1);
                $finalBaseForBal = (float) ($pur['final_amount_base_currency'] ?? $pur['final_amount']);
                // ⚠ paid_amount مخزَّن بعملة الفاتورة (إصلاح سابق) — نحوّله
                // لعملة الفرع بالقسمة على exchange_rate قبل عكس أثره.
                $paidBaseForBal = $rateForBal > 0 ? (float) $pur['paid_amount'] / $rateForBal : (float) $pur['paid_amount'];
                $pur['supplier_balance_before'] = $pur['supplier_balance_after'] + $finalBaseForBal - $paidBaseForBal;
            }

            echo json_encode(['ok' => true, 'data' => $pur]);
        }

        // سعر الصرف بين عملتين — من جدول currencies المحلي حصراً (بدون
        // أي اتصال إنترنت خارجي)، بنفس اتفاقية exchange_rate الموثّقة
        // أصلاً بـ invoice_new.php (كل عملة عندها exchange_rate نسبة
        // لمرجع عالمي واحد؛ السعر بين عملتين = نسبة الاثنتين لبعض):
        // 1 وحدة من from = (exchange_rate[to] / exchange_rate[from]) من to
        elseif ($act === 'get_currency_rate') {
            $from = trim($_POST['from'] ?? '');
            $to = trim($_POST['to'] ?? '');
            if (!$from || !$to)
                throw new Exception('عملتان مطلوبتان');
            // ⚠ تحسين: لو الطرف "from" هو عملة فاتورة قائمة فعلاً، سعرها
            // مقفول تاريخياً على الفاتورة نفسها (IAS 21) — ما لازم نعيد
            // جلبه من جدول currencies العام (ممكن يكون تحدّث لاحقاً لقيمة
            // مختلفة شوي، فيصير الاقتراح غير متّسق مع الفاتورة من الأساس).
            // $_POST['from_rate_override'] بيمرَّر من الواجهة = exchange_rate
            // الفاتورة نفسها لما ينطبق.
            $fromRateOverride = ($_POST['from_rate_override'] ?? '') !== ''
                ? (float) $_POST['from_rate_override'] : null;

            $rt = $pdo->prepare("SELECT code, exchange_rate FROM currencies WHERE code IN (?,?)");
            $rt->execute([$from, $to]);
            $rates = [];
            foreach ($rt->fetchAll() as $row)
                $rates[$row['code']] = (float) $row['exchange_rate'];
            if ($fromRateOverride !== null && $fromRateOverride > 0)
                $rates[$from] = $fromRateOverride;
            if (!isset($rates[$from]) || !isset($rates[$to]) || $rates[$from] <= 0)
                throw new Exception('عملة غير معروفة بجدول العملات');
            $rate = $rates[$to] / $rates[$from];
            echo json_encode(['ok' => true, 'rate' => round($rate, 6)]);
        }

        // ملاحظة: أُزيل من هنا إجراءان محليان ميتان (dead code) لم يكونا
        // يُستدعَيان من أي زر بالواجهة إطلاقاً — confirm_purchase و
        // cancel_purchase. تحقّقتُ عبر البحث بكل استدعاءات الجافاسكربت
        // بهذا الملف: زر "تأكيد الاستلام" وزر "إلغاء" ينادوا فعلياً
        // api/confirm_purchase_invoice.php حصراً (كلاهما يُرحّلان القيود
        // المحاسبية بشكل صحيح). الإجراءان المحذوفان كانا يحدّثان المخزون
        // فقط بدون أي قيد محاسبي — لو بقيا موجودين، أي تعديل مستقبلي
        // بسيط بالواجهة كان ممكن (بالغلط) يستدعيهما بدل المسار الصحيح،
        // ليعيد بصمت نفس مشكلة "تأكيد بدون قيود" التي أُصلحت سابقاً
        // بملف المبيعات (sales_index.php).
        else
            throw new Exception('إجراء غير معروف');
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── بيانات الصفحة ──────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$suppFil = (int) ($_GET['supplier'] ?? 0);
$dateFrom = $_GET['from'] ?? '';
$dateTo = $_GET['to'] ?? '';
$where = 'WHERE 1=1';
$params = [];
if ($search) {
    $where .= ' AND (p.purchase_number LIKE ? OR s.name LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($status) {
    $where .= ' AND p.status=?';
    $params[] = $status;
}
if ($suppFil) {
    $where .= ' AND p.supplier_id=?';
    $params[] = $suppFil;
}
if ($dateFrom) {
    $where .= ' AND p.purchase_date>=?';
    $params[] = $dateFrom;
}
if ($dateTo) {
    $where .= ' AND p.purchase_date<=?';
    $params[] = $dateTo;
}

$stmt = $pdo->prepare("SELECT p.*, s.name AS supplier_name,
    c.code AS currency_code,
    c.symbol AS currency_symbol,
    COUNT(pi.id) AS items_count
    FROM `{$TP}` p
    LEFT JOIN `{$TSP}` s ON s.id=p.supplier_id
    LEFT JOIN currencies c ON c.id=p.invoice_currency_id
    LEFT JOIN `{$TPI}` pi ON pi.purchase_id=p.id
    {$where}
    GROUP BY p.id ORDER BY p.created_at DESC LIMIT 200");
$stmt->execute($params);
$purchases = $stmt->fetchAll();

$suppliers = $pdo->query("SELECT id,name FROM `{$TSP}`
    WHERE status='active' AND supplier_type IN ('product','both')
    ORDER BY name")->fetchAll();

try {
    $stats = $pdo->query("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END) AS drafts,
        SUM(CASE WHEN status='confirmed' THEN 1 ELSE 0 END) AS confirmed,
        COALESCE(SUM(CASE WHEN status!='cancelled' THEN final_amount_base_currency END),0) AS total_base,
        COALESCE(SUM(CASE WHEN payment_status='pending' AND status='confirmed' THEN final_amount_base_currency END),0) AS balance_base
        FROM `{$TP}`")->fetch();
} catch (Exception $e) {
    $stats = ['total' => 0, 'drafts' => 0, 'confirmed' => 0, 'total_base' => 0, 'balance_base' => 0];
}

$STATUS_MAP = [
    'draft' => ['label' => 'مسودة', 'cls' => 'bg-secondary-subtle text-secondary'],
    'confirmed' => ['label' => 'مؤكدة', 'cls' => 'bg-info-subtle text-success'],
    'cancelled' => ['label' => 'ملغاة', 'cls' => 'bg-danger-subtle text-danger'],
];
// ⚠ تلوين السطر كامل (خلفية + لون خط) حسب حالة الفاتورة — طلب صريح
// لتمييز بصري سريع بلا حاجة قراءة عمود الحالة لحاله. نفس لوحة الألوان
// المستخدمة بدالة invoiceRowStyle() بملف sales_index.php بالضبط — ٣
// حالات فقط بالمشتريات (مسودة/مؤكدة/ملغاة، بعد حذف "مستلمة" نهائياً).
function purchaseRowStyle(string $status): string
{
    if ($status === 'draft')
        return 'background:#f8fafc;color:#64748b'; // مسودة — فضي
    if ($status === 'cancelled')
        return 'background:#fef2f2;color:#dc2626'; // ملغاة — أحمر
    return 'background:#f0fdf4;color:#065f46'; // confirmed (مؤكدة) — أخضر
}

$PAY_MAP = [
    'pending' => ['label' => 'غير مدفوعة', 'cls' => 'text-danger'],
    'partial' => ['label' => 'جزئي', 'cls' => 'text-warning'],
    'paid' => ['label' => 'مدفوعة', 'cls' => 'text-success'],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>فواتير الشراء — <?= htmlspecialchars($branchName) ?></title>
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
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 10px
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0
        }

        .stat-val {
            font-size: 1.2rem;
            font-weight: 700;
            color: #1e293b;
            line-height: 1
        }

        .stat-lbl {
            font-size: .7rem;
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

        .sec-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            margin-bottom: 1.1rem
        }

        .sec-card table {
            margin: 0;
            font-size: .83rem
        }

        .sec-card th {
            background: #f8fafc;
            color: #64748b;
            font-size: .75rem;
            font-weight: 600;
            border: none;
            padding: .6rem .9rem;
            white-space: nowrap
        }

        .sec-card td {
            padding: .55rem .9rem;
            vertical-align: middle;
            border-top: 1px solid #f1f5f9
        }

        .sec-card tbody tr:hover td {
            background: #f8fafc
        }

        .sec-hdr {
            padding: .7rem 1.1rem;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: .5rem
        }

        table.mtbl {
            width: 100%;
            border-collapse: collapse;
            font-size: .82rem
        }

        table.mtbl th {
            background: #f8fafc;
            padding: 8px 12px;
            font-weight: 600;
            color: #64748b;
            font-size: .72rem;
            border-bottom: 1px solid #f1f5f9;
            white-space: nowrap
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

        .field-lbl {
            font-size: .76rem;
            font-weight: 700;
            color: #475569;
            margin-bottom: 4px;
            display: block
        }

        .det-row {
            display: flex;
            justify-content: space-between;
            font-size: .8rem;
            padding: 3px 0;
            border-bottom: 1px solid #f8fafc
        }

        .det-row:last-child {
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
        <span class="tb-title"><i class="bi bi-cart-plus me-1 text-primary"></i>فواتير الشراء</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <?= renderBreadcrumb() ?>
    </header>
    <main class="main-content">
        <div class="content-body">

            <!-- تبويبات -->
            <ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e2e8f0">
                <li class="nav-item">
                    <a class="nav-link fw-600 active" href="index.php"
                        style="border:none;border-bottom:2px solid var(--section-color);color:var(--section-color);font-size:.83rem;margin-bottom:-2px">
                        <i class="bi bi-receipt me-1"></i>فواتير المشتريات
                    </a>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="suppliers.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-people me-1"></i>إدارة الموردين
                    </a>
                </li>
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
                    <a class="nav-link fw-600" href="reports.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-bar-chart me-1"></i>التقارير
                    </a>
                </li>
            </ul>

            <!-- إحصائيات -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#eff6ff"><i class="bi bi-receipt text-primary"></i>
                        </div>
                        <div>
                            <div class="stat-val"><?= $stats['total'] ?></div>
                            <div class="stat-lbl">إجمالي الفواتير</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fef3c7"><i class="bi bi-hourglass text-warning"></i>
                        </div>
                        <div>
                            <div class="stat-val"><?= $stats['drafts'] ?></div>
                            <div class="stat-lbl">مسودات</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#f0fdf4"><i
                                class="bi bi-currency-dollar text-success"></i></div>
                        <div>
                            <div class="stat-val n"><?= htmlspecialchars($baseCurrencySymbol) ?>
                                <?= number_format($stats['total_base'], 2) ?>
                            </div>
                            <div class="stat-lbl">إجمالي المشتريات</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fee2e2"><i
                                class="bi bi-exclamation-circle text-danger"></i></div>
                        <div>
                            <div class="stat-val n"><?= htmlspecialchars($baseCurrencySymbol) ?>
                                <?= number_format($stats['balance_base'], 2) ?>
                            </div>
                            <div class="stat-lbl">التزامات الموردين</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- الجدول -->
            <div class="tbl-wrap">
                <div class="tbl-hdr">
                    <span style="font-size:.88rem;font-weight:700;color:#1e293b;white-space:nowrap">
                        <i class="bi bi-receipt me-1 text-success"></i>سجل فواتير المشتريات
                    </span>
                    <form method="get" class="d-flex gap-2 flex-wrap align-items-center ms-auto">
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                            placeholder="رقم الفاتورة أو المورد..." class="form-control form-control-sm"
                            style="width:180px;border-radius:8px">
                        <select name="status" class="form-select form-select-sm" style="width:120px;border-radius:8px"
                            onchange="this.form.submit()">
                            <option value="">كل الحالات</option>
                            <?php foreach ($STATUS_MAP as $k => $v): ?>
                                <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $v['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="supplier" class="form-select form-select-sm" style="width:160px;border-radius:8px"
                            onchange="this.form.submit()">
                            <option value="">كل الموردين</option>
                            <?php foreach ($suppliers as $sp): ?>
                                <option value="<?= $sp['id'] ?>" <?= $suppFil == $sp['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sp['name']) ?>
                                </option>
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
                        <?php if ($search || $status || $suppFil || $dateFrom || $dateTo): ?>
                            <a href="index.php" class="btn btn-sm btn-light" style="border-radius:8px">
                                <i class="bi bi-x-lg me-1"></i>مسح
                            </a>
                        <?php endif; ?>
                    </form>
                    <a href="../accounting/reciepts.php" class="btn btn-sm fw-600"
                        style="border-radius:9px;background:var(--section-color);color:#fff;font-size:.82rem;text-decoration:none"
                        target="_blank" title="سندات الدفع (قسم المالية)">
                        <i class="bi bi-credit-card me-1"></i>سندات الدفع
                    </a>
                    <a href="invoice_new.php" class="btn btn-sm fw-600"
                        style="border-radius:9px;background:var(--section-color);color:#fff;font-size:.82rem;text-decoration:none"
                        target="_blank">
                        <i class="bi bi-plus-lg me-1"></i>فاتورة جديدة
                    </a>
                </div>
            </div>
            <!-- جدول الفواتير -->
            <div class="tbl-wrap">
                <div class="table-responsive">
                    <table class="mtbl" id="purchasesTbl">
                        <thead>
                            <tr>
                                <th style="color:#1e3a8a">رقم الفاتورة</th>
                                <th>التاريخ</th>
                                <th style="color:#1e3a8a">المورد</th>
                                <th>البنود</th>
                                <th>العملة</th>
                                <th>الإجمالي</th>
                                <th>بعملة الفرع (<?= htmlspecialchars($baseCurrencySymbol) ?>)</th>
                                <th>حالة الدفع</th>
                                <th>الحالة</th>
                                <th style="text-align:center" data-no-sort>إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($purchases)): ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-5">
                                        <i class="bi bi-receipt d-block mb-2" style="font-size:2rem;opacity:.2"></i>
                                        لا توجد فواتير<?= $search ? " تطابق \"{$search}\"" : '' ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($purchases as $pur):
                                $st = $STATUS_MAP[$pur['status']] ?? $STATUS_MAP['draft'];
                                $pay = $PAY_MAP[$pur['payment_status']] ?? $PAY_MAP['pending'];
                                $sym = $pur['currency_symbol'] ?? '$';
                                ?>
                                <tr style="<?= purchaseRowStyle($pur['status'], $pur['payment_status']) ?>">
                                    <td class="n fw-600" style="direction:rtl;color:#16a34a">
                                        <a onclick="viewInvoice(<?= $pur['id'] ?>)" title="عرض"
                                            style="color:#1e3a8a;text-decoration:none">
                                            <?= htmlspecialchars($pur['purchase_number']) ?>
                                        </a>
                                    </td>
                                    <td class="text-muted" style="direction:rtl"><?= $pur['purchase_date'] ?></td>
                                    <td>
                                        <div class="fw-600" style="font-size:.83rem;color:#16a34a;direction:rtl">
                                            <?= htmlspecialchars($pur['supplier_name'] ?? '—') ?>
                                        </div>
                                    </td>
                                    <td class=" text-center" style="direction:rtl">
                                        <span class="badge bg-secondary-subtle text-secondary"><?= $pur['items_count'] ?>
                                            بند</span>
                                    </td>
                                    <td>
                                        <span class="badge bg-info-subtle text-info" style="font-size:.72rem" dir="rtl">
                                            <?= htmlspecialchars($pur['currency_code'] ?: '—') ?>
                                        </span>
                                    </td>
                                    <td class="n fw-600"><?= number_format($pur['final_amount'], 2) ?>     <?= $sym ?></td>
                                    <td class="n text-muted" style="font-size:.78rem">
                                        <?= $pur['final_amount_base_currency'] ? number_format($pur['final_amount_base_currency'], 2) . ' $' : '—' ?>
                                    </td>
                                    <td><span class="<?= $pay['cls'] ?>"
                                            style="font-size:.78rem;font-weight:600"><?= $pay['label'] ?></span></td>
                                    <td><span class="badge <?= $st['cls'] ?>"
                                            style="font-size:.68rem"><?= $st['label'] ?></span></td>
                                    <td>
                                        <div class="d-flex gap-1 justify-content-center">
                                            <button class="act-btn info-h" onclick="viewInvoice(<?= $pur['id'] ?>)"
                                                title="عرض">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <?php if ($pur['status'] === 'draft'): ?>
                                                <a href="invoice_edit.php?id=<?= $pur['id'] ?>" class="act-btn" title="تعديل">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($pur['status'] === 'draft'): ?>
                                                <button class="act-btn success-h"
                                                    onclick="confirmInvoice(<?= $pur['id'] ?>,'<?= htmlspecialchars($pur['purchase_number'], ENT_QUOTES) ?>')"
                                                    title="تأكيد الفاتورة"><i class="bi bi-check-circle"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($pur['status'] === 'confirmed'): ?>

                                                <a href="returns.php?open_purchase_id=<?= $pur['id'] ?>" class="act-btn"
                                                    style="color:#dc2626" title="إنشاء مرتجع لهذه الفاتورة">
                                                    <i class="bi bi-arrow-return-right"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($pur['status'] !== 'cancelled'): ?>
                                                <button class="act-btn danger"
                                                    onclick="cancelInvoice(<?= $pur['id'] ?>,'<?= htmlspecialchars($pur['purchase_number'], ENT_QUOTES) ?>')"
                                                    title="إلغاء"><i class="bi bi-x-circle"></i>
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
        </div>
        </div>
    </main>

    <!-- مودال عرض -->
    <div class="modal fade" id="viewModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#0c447c,var(--section-color));border-radius:16px 16px 0 0">
                    <div>
                        <h6 class="modal-title text-white fw-700 mb-0" id="vTitle">تفاصيل الفاتورة</h6>
                        <div id="vSub" style="font-size:.75rem;color:rgba(255,255,255,.7);margin-top:2px"></div>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <button type="button" class="btn btn-sm" onclick="printPurchaseInvoice()"
                            style="border-radius:8px;background:rgba(255,255,255,.15);color:#fff;font-size:.76rem;border:1px solid rgba(255,255,255,.3)">
                            <i class="bi bi-printer me-1"></i>طباعة
                        </button>
                        <a id="vEditBtn" href="#" class="btn btn-sm"
                            style="display:none;border-radius:8px;background:rgba(255,255,255,.15);color:#fff;font-size:.76rem;border:1px solid rgba(255,255,255,.3)">
                            <i class="bi bi-pencil me-1"></i>تعديل
                        </a>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                </div>
                <div class="modal-body px-4 py-3" id="vBody">
                    <div class="text-center py-4"><span class="spinner-border text-primary"></span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- مودال تأكيد الاستلام -->
    <div class="modal fade" id="confirmModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#065f46,#16a34a);border-radius:16px 16px 0 0">
                    <div>
                        <h6 class="modal-title text-white fw-700 mb-0">
                            <i class="bi bi-check-circle me-2"></i>تأكيد استلام الفاتورة
                        </h6>
                        <div id="cInvNo" style="font-size:.78rem;color:rgba(255,255,255,.8);margin-top:2px"></div>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <button class="btn btn-sm" style="background:rgba(255,255,255,.2);color:#fff;border-radius:8px"
                            onclick="printPurchaseInvoice()" title="طباعة الفاتورة">
                            <i class="bi bi-printer me-1"></i>طباعة
                        </button>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                </div>
                <div class="modal-body px-4 py-3">
                    <input type="hidden" id="cId">
                    <input type="hidden" id="cCurCode">
                    <input type="hidden" id="cInvTotal">
                    <input type="hidden" id="cCurSym">

                    <div class="row g-3">

                        <!-- ── بنود الفاتورة ── -->
                        <div class="col-12">
                            <div style="background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;overflow:hidden">
                                <div
                                    style="padding:8px 14px;background:#f1f5f9;font-size:.8rem;font-weight:700;color:#1e293b;border-bottom:1px solid #e2e8f0">
                                    <i class="bi bi-list-ul me-1 text-primary"></i>بنود الفاتورة
                                </div>
                                <div class="table-responsive">
                                    <table style="width:100%;font-size:.78rem;border-collapse:collapse"
                                        id="cItemsTable">
                                        <thead>
                                            <tr style="background:#f8fafc">
                                                <th
                                                    style="padding:6px 10px;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0">
                                                    #</th>
                                                <th
                                                    style="padding:6px 10px;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0">
                                                    بيان القطعة</th>
                                                <th
                                                    style="padding:6px 10px;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0">
                                                    القياس</th>
                                                <th
                                                    style="padding:6px 10px;color:#16a34a;font-weight:600;border-bottom:1px solid #e2e8f0">
                                                    اللون</th>
                                                <th
                                                    style="padding:6px 10px;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0">
                                                    عدد الكروبات</th>
                                                <th
                                                    style="padding:6px 10px;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0">
                                                    سعر الوحدة</th>
                                                <th
                                                    style="padding:6px 10px;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0">
                                                    الإجمالي</th>
                                            </tr>
                                        </thead>
                                        <tbody id="cItemsBody"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- ── ملخص المبالغ — بنفس عرض المودال، مباشرة تحت جدول البنود ── -->
                        <div class="col-12">
                            <div
                                style="background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;padding:10px 14px;font-size:.8rem">
                                <div class="row g-2 text-center text-md-start">
                                    <div class="col-6 col-md-2">
                                        <div class="text-muted" style="font-size:.7rem">المبلغ الصافي للمنتجات</div>
                                        <div class="fw-600" id="cSumProducts">—</div>
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <div class="text-muted" style="font-size:.7rem">نسبة الخصم</div>
                                        <div class="fw-600 text-danger" id="cSumDiscount">0%</div>
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <div class="text-muted" style="font-size:.7rem">قيمة الخصم</div>
                                        <div class="fw-600 text-danger" id="cSumDiscountAmt">0.00</div>
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <div class="text-muted" style="font-size:.7rem">الضريبة</div>
                                        <div class="fw-600" id="cSumTax">0.00</div>
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <div class="text-muted" style="font-size:.7rem">سعر الصرف عند الإنشاء</div>
                                        <div class="fw-600" style="color:#7c3aed" id="cSumExRate">—</div>
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <div class="text-muted" style="font-size:.7rem">المبلغ الإجمالي النهائي</div>
                                        <div class="fw-700" style="color:var(--section-color);font-size:.95rem"
                                            id="cSumFinal">—</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ── بانر خصم تعجيل الدفع (يظهر بس لو الفاتورة مؤهلة) ── -->
                        <div class="col-12" id="cSettleDiscBanner" style="display:none">
                            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:10px 14px;font-size:.8rem"
                                class="d-flex align-items-center flex-wrap gap-2">
                                <i class="bi bi-lightning-charge-fill" style="color:#16a34a"></i>
                                <span id="cSettleDiscText" class="text-success fw-600"></span>
                                <button type="button" class="btn btn-sm btn-success ms-auto" id="cSettleDiscBtn"
                                    style="border-radius:8px;font-size:.75rem" onclick="applySettlementDiscount()">
                                    <i class="bi bi-check2 me-1"></i>دفع كامل بالخصم الآن
                                </button>
                            </div>
                        </div>

                        <!-- ── تكاليف الشحن ── -->
                        <div class="col-12">
                            <label class="form-label small fw-600 text-secondary mb-1">
                                <i class="bi bi-truck me-1 text-warning"></i>تكاليف الشحن والنقلية
                            </label>
                            <div class="row g-2">
                                <div class="col-12 col-md-4">
                                    <div class="btn-group w-100 mb-2" role="group">
                                        <input type="radio" class="btn-check" name="shippingOn" id="shipOnUs" value="us"
                                            checked onchange="updateTotal()">
                                        <label class="btn btn-sm btn-outline-danger fw-600" for="shipOnUs"
                                            style="border-radius:8px 0 0 8px">
                                            <i class="bi bi-arrow-down-circle me-1"></i>علينا (تُضاف للتكلفة)
                                        </label>
                                        <input type="radio" class="btn-check" name="shippingOn" id="shipOnThem"
                                            value="them" onchange="updateTotal()">
                                        <label class="btn btn-sm btn-outline-success fw-600" for="shipOnThem"
                                            style="border-radius:0 8px 8px 0">
                                            <i class="bi bi-arrow-up-circle me-1"></i>على البائع (مجانية)
                                        </label>
                                    </div>
                                </div>
                                <div class="col-7 col-md-4" id="shippingAmtWrap">
                                    <div class="input-group input-group-sm">
                                        <input type="number" id="cShipping" class="form-control" placeholder="0.00"
                                            min="0" step="0.01" oninput="updateTotal()">
                                        <!-- عملة الشحن مستقلة كلياً عن قرار حصر عملة الدفع — بتقدر تكون
                                        أي عملة من الجدول (مو محصورة بالفاتورة/الفرع)، لأنه غالباً شركة
                                        الشحن نفسها بتحدد عملتها -->
                                        <select id="cShippingCur" class="form-select" style="max-width:85px"
                                            onchange="onShippingCurChange()">
                                            <?php foreach ($currencies as $cur): ?>
                                                <option value="<?= $cur['code'] ?>"><?= $cur['code'] ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <!-- سعر صرف الشحن — يظهر بس لو عملة الشحن عملة ثالثة (مو عملة
                                الفاتورة ومو عملة الفرع)، لأنه بهاتين الحالتين السعر معروف أصلاً
                                (سعر الفاتورة نفسه، أو 1 على التوالي) وما في داعي حقل إضافي -->
                                <div class="col-12 col-md-4" id="shippingRateWrap" style="display:none">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text" style="font-size:.72rem" id="shippingRateLbl">1
                                            ? =</span>
                                        <input type="number" id="cShippingRate" class="form-control" min="0.0001"
                                            step="0.0001" placeholder="سعر الصرف" oninput="updateTotal()">
                                    </div>
                                    <div class="form-text" style="font-size:.68rem">
                                        عملة الشحن مختلفة عن عملة الفاتورة وعملة الفرع — أدخل سعر الصرف الفعلي وقت
                                        هالشحنة يدوياً
                                    </div>
                                </div>
                                <div class="col-5 col-md-4" id="shippingAmtWrap2">
                                    <div class="input-group input-group-sm">
                                        <select id="cShippingCarrier" class="form-select form-select-sm"
                                            onchange="onCarrierChange(this)">
                                            <option value="">— شركة الشحن —</option>
                                            <?php foreach ($shippingCarriers as $sc): ?>
                                                <option value="<?= $sc['id'] ?>"
                                                    data-name="<?= htmlspecialchars($sc['name'], ENT_QUOTES) ?>"
                                                    data-phone="<?= htmlspecialchars($sc['phone'] ?? '', ENT_QUOTES) ?>"
                                                    data-payable-id="<?= $sc['payable_account_id'] ?? 0 ?>">
                                                    <?= htmlspecialchars($sc['name']) ?>
                                                    <?php if ($sc['contact_person']): ?>(<?= htmlspecialchars($sc['contact_person']) ?>)<?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <?php if (empty($shippingCarriers)): ?>
                                                <option value="" disabled>لا توجد شركات — أضف من الإعدادات</option>
                                            <?php endif; ?>
                                        </select>
                                        <a href="<?= BASE_PATH ?>/retail1/modules/accounting/shipping_carriers.php"
                                            target="_blank" class="btn btn-sm btn-outline-warning"
                                            style="padding:3px 7px" title="إدارة شركات الشحن">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                    </div>
                                    <input type="hidden" id="cShippingDesc">
                                    <input type="hidden" id="cShippingPayableId">
                                </div>
                                <!-- طريقة دفع الشحن -->
                                <div class="col-12" id="shippingPayWrap">
                                    <div class="btn-group w-100" role="group">
                                        <input type="radio" class="btn-check" name="shippingPay" id="shipPayCash"
                                            value="cash" checked onchange="onShipPayChange()">
                                        <label class="btn btn-sm btn-outline-success fw-600" for="shipPayCash"
                                            style="border-radius:8px 0 0 8px;font-size:.75rem">
                                            <i class="bi bi-cash me-1"></i>دفع نقدي (يُخصم من الصندوق)
                                        </label>
                                        <input type="radio" class="btn-check" name="shippingPay" id="shipPayCredit"
                                            value="credit" onchange="onShipPayChange()">
                                        <label class="btn btn-sm btn-outline-warning fw-600" for="shipPayCredit"
                                            style="border-radius:0 8px 8px 0;font-size:.75rem">
                                            <i class="bi bi-clock-history me-1"></i>آجل (ذمة شركة الشحن)
                                        </label>
                                    </div>
                                    <!-- حساب الصندوق عند الدفع النقدي -->
                                    <div id="shipCashAccWrap" class="mt-1">
                                        <select id="cShipCashAccount" class="form-select form-select-sm">
                                            <option value="">— حساب الصندوق —</option>
                                            <?php foreach ($cashAccounts as $ca): ?>
                                                <option value="<?= $ca['id'] ?>"
                                                    data-cur="<?= htmlspecialchars($ca['cur_code'] ?? '') ?>">
                                                    <?= htmlspecialchars($ca['code'] . ' — ' . $ca['name']) ?>
                                                    (<?= htmlspecialchars($ca['cur_sym'] ?? '') ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <!-- تنبيه الذمة -->
                                    <div id="shipCreditNote" class="mt-1"
                                        style="display:none;font-size:.72rem;color:#d97706;background:#fffbeb;border-radius:6px;padding:4px 8px">
                                        <i class="bi bi-info-circle me-1"></i>
                                        سيُسجَّل المبلغ كذمة لشركة الشحن في حسابها بشجرة الحسابات
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ── تاريخ الاستلام + مستودع ── -->
                        <div class="col-md-4">
                            <label class="form-label small fw-600 text-secondary mb-1">
                                <i class="bi bi-calendar-check me-1 text-success"></i>تاريخ الاستلام
                            </label>
                            <input type="date" id="cReceiveDate" class="form-control form-control-sm"
                                value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-600 text-secondary mb-1">
                                <i class="bi bi-building me-1 text-primary"></i>مستودع الاستلام
                            </label>
                            <div id="cWarehouseDisplay"
                                style="background:#f1f5f9;border-radius:8px;padding:6px 10px;font-size:.82rem;color:#1e293b;font-weight:600">
                                <i class="bi bi-building me-1 text-primary"></i><span id="cWarehouseName">جارٍ
                                    التحميل...</span>
                            </div>
                            <input type="hidden" id="cWarehouse">
                        </div>

                        <!-- ── دفع جزئي ── -->
                        <div class="col-12">
                            <label class="form-label small fw-600 text-secondary mb-1">
                                <i class="bi bi-cash me-1 text-success"></i>دفع جزئي عند الاستلام
                                <span style="font-size:.68rem;color:#94a3b8">(اختياري)</span>
                            </label>
                            <div class="row g-2">
                                <div class="col-md-5">
                                    <div class="input-group input-group-sm">
                                        <input type="number" id="cPaidAmt" class="form-control fw-600"
                                            placeholder="0.00" min="0" step="0.01" oninput="onPaidChange()">
                                        <!-- ⚠ قرار تصميمي متعمَّد: عملة دفع الفاتورة (لا الشحن) محصورة
                                        عملة الفاتورة أو عملة الفرع فقط، بدون أي تعقيد — تُملأ ديناميكياً
                                        فور تحميل بيانات الفاتورة. أي دفع بعملة ثالثة يصير من صفحة
                                        "المدفوعات" بقسم المحاسبة، مو من هون. -->
                                        <select id="cPaidCur" class="form-select" style="max-width:85px"
                                            onchange="_cPaidRateTouched=false; onPaidChange()">
                                        </select>
                                    </div>
                                </div>
                                <!-- سعر الصرف — يظهر فقط لو الدفع بعملة الفرع (مختلفة عن الفاتورة).
                                عملة الفرع هي المرجع الثابت دايماً ("1 عملة الفرع = س عملة الفاتورة")
                                — نفس اتفاقية exchange_rate المخزَّن على الفاتورة نفسها بالضبط.
                                مقترح تلقائياً، وقابل للتعديل اليدوي بالكامل، مع زر تحديث يجيب آخر
                                سعر من جدول currencies العام وقت الحاجة. -->
                                <div class="col-md-7" id="cPaidRateWrap" style="display:none">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text" style="font-size:.73rem" id="cPaidRateLabel">1 ?
                                            =</span>
                                        <input type="number" id="cPaidRate" class="form-control fw-600" min="0.000001"
                                            step="0.0001" dir="ltr" oninput="_cPaidRateTouched=true; updateTotal()">
                                        <span class="input-group-text" id="cPaidRateSuffix"
                                            style="font-size:.73rem"></span>
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                            style="border-radius:0 7px 7px 0" onclick="fetchPaidRate()"
                                            title="تحديث السعر من جدول العملات">
                                            <i class="bi bi-arrow-repeat" id="paidRateIcon"></i>
                                        </button>
                                    </div>
                                    <div id="cPaidRateHint" style="font-size:.7rem;color:#64748b;margin-top:3px"></div>
                                </div>
                                <!-- المبلغ المحوَّل لعملة الفاتورة -->
                                <div class="col-12" id="cPaidConvertWrap" style="display:none">
                                    <div style="background:#f0fdf4;border-radius:7px;padding:5px 10px;font-size:.78rem">
                                        <i class="bi bi-arrow-left-right me-1 text-success"></i>
                                        المبلغ بعملة الفاتورة:
                                        <strong id="cPaidConverted" style="color:#16a34a">—</strong>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- حساب الدفع -->
                        <div class="col-md-6">
                            <label class="form-label small fw-600 text-secondary mb-1">
                                <i class="bi bi-safe me-1"></i>حساب الدفع (صندوق / بنك / دفعة مقدمة للمورد)
                            </label>
                            <select id="cCashAccount" class="form-select form-select-sm"
                                onchange="onCashAccountChange()">
                                <option value="">— اختر حساب الدفع —</option>
                                <?php foreach ($cashAccounts as $ca): ?>
                                    <option value="<?= $ca['id'] ?>"
                                        data-cur="<?= htmlspecialchars($ca['cur_code'] ?? '') ?>"
                                        data-balance="<?= (float) $ca['balance'] ?>"
                                        data-sym="<?= htmlspecialchars($ca['cur_sym'] ?? '') ?>" data-type="regular">
                                        <?= htmlspecialchars($ca['code'] . ' — ' . $ca['name']) ?>
                                        (<?= htmlspecialchars($ca['cur_sym'] ?? '') ?>)
                                    </option>
                                <?php endforeach; ?>
                                <!-- خيار حساب الدفعة المقدمة الخاص بمورد هالفاتورة ينضاف ديناميكياً بالجافاسكربت -->
                            </select>
                            <div id="cCashAccBalanceHint" style="font-size:.72rem;margin-top:4px"></div>
                        </div>

                        <!-- صورة الفاتورة -->
                        <div class="col-md-6">
                            <label class="form-label small fw-600 text-secondary mb-1">
                                <i class="bi bi-image me-1 text-info"></i>صورة فاتورة المورد
                            </label>
                            <input type="file" id="cInvoiceImg" class="form-control form-control-sm"
                                accept="image/*,application/pdf" onchange="previewImg(this)">
                            <div id="cImgPreview" style="display:none;margin-top:6px">
                                <img id="cImgThumb" style="max-height:60px;border-radius:6px;border:1px solid #e2e8f0">
                                <button type="button" class="btn btn-sm btn-light ms-1" onclick="clearImg()"
                                    style="border-radius:5px;font-size:.7rem">
                                    <i class="bi bi-x"></i>
                                </button>
                            </div>
                        </div>

                        <!-- ملاحظات -->
                        <div class="col-12">
                            <label class="form-label small fw-600 text-secondary mb-1">
                                <i class="bi bi-chat-left-text me-1"></i>ملاحظات الاستلام
                            </label>
                            <textarea id="cNotes" class="form-control form-control-sm" rows="2"
                                placeholder="حالة البضاعة، ملاحظات خاصة..."></textarea>
                        </div>

                        <!-- ملخص مالي نهائي -->
                        <div class="col-12">
                            <div
                                style="background:#f0fdf4;border-radius:10px;border:1px solid #bbf7d0;padding:10px 14px;font-size:.82rem">
                                <div class="fw-700 mb-2" style="color:#065f46;font-size:.8rem">
                                    <i class="bi bi-calculator me-1"></i>ملخص المبالغ حسب العملة
                                </div>
                                <div id="cTotSummary">
                                    <!-- يتم بناؤه ديناميكياً في updateTotal() -->
                                </div>
                            </div>
                        </div>

                        <!-- تسوية فروقات التقريب الصغيرة -->
                        <div class="col-12">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="cExactSettle">
                                <label class="form-check-label" for="cExactSettle"
                                    style="font-size:.78rem;cursor:pointer">
                                    <i class="bi bi-check2-circle me-1 text-success"></i>
                                    اعتبار الفاتورة مسدَّدة بالكامل (تسوية فروقات التقريب الصغيرة تلقائياً، حتى ٠.٠١$)
                                </label>
                            </div>
                        </div>

                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4 gap-2">
                    <button class="btn btn-sm btn-light" style="border-radius:8px"
                        data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-sm fw-600"
                        style="border-radius:8px;background:#16a34a;color:#fff;min-width:140px" onclick="doConfirm()"
                        id="btnConfirm">
                        <span id="confirmTxt"><i class="bi bi-check-circle me-1"></i>تأكيد الاستلام</span>
                        <span id="confirmSpin" class="spinner-border spinner-border-sm" style="display:none"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>



    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= BASE_PATH ?>/assets/js/sidebar.js"></script>
    <script>

        const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
        const confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
        const STATUS_MAP = <?= json_encode($STATUS_MAP) ?>;
        const PAY_MAP = <?= json_encode($PAY_MAP) ?>;
        // رمز عملة الفرع الأساسية — كل مبالغ "سعر التكلفة بعد الخصم"/"الإجمالي"
        // بجدولي التفاصيل والتأكيد بعملة الفرع دائماً (مطابق لقرار invoice_new.php).
        const BASE_CUR_SYM = <?= json_encode($baseCurrencySymbol) ?>;
        function post(data) {
            const fd = new FormData();
            Object.entries(data).forEach(([k, v]) => fd.append(k, v ?? ''));
            return fetch(location.href, {method: 'POST', body: fd}).then(r => r.json());
        }
        function toast(msg, type = 'success') {
            const t = document.createElement('div');
            t.className = `alert alert-${type} shadow`;
            t.style.cssText = 'position:fixed;top:70px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:240px;text-align:center;font-size:.83rem;padding:.5rem 1.2rem';
            t.innerHTML = `<i class="bi bi-${type === 'success' ? 'check-circle-fill text-success' : 'exclamation-triangle-fill text-danger'} me-2"></i>${msg}`;
            document.body.appendChild(t); setTimeout(() => t.remove(), 3200);
        }
        // ⚠ عرض القياس كنطاق مختصر بدل تعداد كل المقاسات: "{نوع العمر}
        // {أصغر}-{أكبر}" — نفس منطق returns.php بالضبط، لضمان الاتساق.
        function formatSizeRange(g) {
            if (!g.sizes.length) return '—';
            const nums = g.sizes.map(s => parseFloat(s)).filter(n => !isNaN(n));
            if (!nums.length) return g.sizes.join(' · ') + (g.ageType ? ' ' + g.ageType : '');
            const min = Math.min(...nums), max = Math.max(...nums);
            const rangeTxt = min === max ? `${min}` : `${min}-${max}`;
            return g.ageType ? `${g.ageType} ${rangeTxt}` : rangeTxt;
        }

        function viewInvoice(id) {
            document.getElementById('vTitle').textContent = 'جارٍ التحميل...';
            document.getElementById('vSub').textContent = '';
            document.getElementById('vEditBtn').style.display = 'none';
            document.getElementById('vBody').innerHTML = '<div class="text-center py-4"><span class="spinner-border text-primary"></span></div>';
            viewModal.show();
            post({_action: 'get_purchase', id}).then(d => {
                if (!d.ok) {document.getElementById('vBody').innerHTML = `<div class="text-danger p-3">${d.msg}</div>`; return;}
                const p = d.data;
                _cPurchaseData = p; // ⚠ حتى يشتغل زر الطباعة صح من مودال التفاصيل مباشرة، بدون الحاجة لفتح مودال التأكيد قبله
                const st = STATUS_MAP[p.status] || STATUS_MAP['draft'];
                const pay = PAY_MAP[p.payment_status] || PAY_MAP['pending'];
                const sym = p.currency_symbol || '$';
                const cur = p.currency || 'USD';
                const rate = parseFloat(p.exchange_rate) || 1;
                const isUSD = cur === 'USD';
                const fmt = n => new Intl.NumberFormat('en').format(parseFloat(n || 0).toFixed(2));
                document.getElementById('vTitle').textContent = 'فاتورة: ' + p.purchase_number;
                document.getElementById('vSub').textContent = p.supplier_name || '';
                if (p.status === 'draft') {
                    const eb = document.getElementById('vEditBtn');
                    eb.href = `invoice_edit.php?id=${p.id}`;
                    eb.style.display = '';
                }
                // تجميع البنود بـ (product × unit_price_base_currency) —
                // الكروب — بدون اللون بالمفتاح، حتى تنجمع كل ألوان نفس
                // الكروب بصف واحد. ⚠ التجميع بعملة الفرع (unit_price_base_
                // currency) لا unit_price (عملة الفاتورة) — لأنه net_price
                // (سعر التكلفة بعد الخصم) دائماً بعملة الفرع بحسب التصميم
                // المعتمد بـinvoice_new.php.
                //
                // ⚠ تسليم من المحادثة العامة (handoff_group_key_purchases.md):
                // group_key الحقيقي المخزَّن على product_sizes صار مصدر
                // الحقيقة الوحيد للتجميع — بدل إعادة بناء السعر الافتراضي
                // من unit_price_base_currency/discount_percentage (كانت
                // طريقة هشة، بالضبط المثال يلي وقعنا فيه: 8.8−0.3=8.5
                // و9.9−1.4=8.5 نفس الصافي بالصدفة، كروبين مختلفين تماماً).
                // احتياط بسيط لبيانات قديمة بلا group_key (بند اتحفظ قبل
                // إضافة العمود): نرجع لنفس منطق إعادة البناء القديم.
                const GRP_COLORS = [['#eff6ff', '#dc2626', '#bfdbfe'], ['#f0fdf4', '#065f46', '#bbf7d0'],
                ['#fff7ed', '#7c2d12', '#fed7aa'], ['#f5f3ff', '#4c1d95', '#ddd6fe']];
                const grpMap = {};
                (p.items || []).forEach(it => {
                    const priceBase = parseFloat(it.unit_price_base_currency ?? it.unit_price);
                    const discPct = parseFloat(it.discount_percentage) || 0;
                    const defaultPriceBase = discPct > 0 ? priceBase / (1 - discPct / 100) : priceBase;
                    const groupKey = it.group_key || defaultPriceBase.toFixed(4); // احتياط لبيانات قديمة
                    const k = `${it.product_id || it.product_name || it.id}_${groupKey}`;
                    if (!grpMap[k]) {
                        grpMap[k] = {
                            product_name: it.product_name || '—', model_number: it.model_number || '',
                            unit_price_base: priceBase, default_price_base: defaultPriceBase, group_key: groupKey,
                            qty: 0, total: 0, sizes: [], colors: [], ageType: it.age_type || '', _colorQty: {}
                        };
                    }
                    // ⚠ عدد الكروبات (qty) لكل لون = quantity المخزَّنة فعلياً
                    // (بعد إصلاح saveInvoice: qty لكل مقاس بمفرده = عدد
                    // الباكيتات الحقيقي، موحَّد على كل مقاسات نفس اللون) —
                    // نسجّلها مرة وحدة لكل لون فريد (مو استبدال يضيع البيانات)،
                    // وبعدين نجمعها مع بعض لناتج "عدد الكروبات" الكلي للمجموعة.
                    const colorKey = it.color || '_none';
                    grpMap[k]._colorQty[colorKey] = parseFloat(it.quantity);
                    grpMap[k].qty = Object.values(grpMap[k]._colorQty).reduce((s, v) => s + v, 0);
                    grpMap[k].total += parseFloat(it.total_price);
                    if (it.size && !grpMap[k].sizes.includes(it.size)) grpMap[k].sizes.push(it.size);
                    if (it.color && !grpMap[k].colors.includes(it.color)) grpMap[k].colors.push(it.color);
                });

                // ترتيب الكروبات بالسعر الافتراضي (نفس معيار التجميع)، وتلوينها
                const grpRows = Object.values(grpMap).sort((a, b) => a.default_price_base - b.default_price_base);
                // ⚠ قائمة الأسعار المميّزة لازم تُبنى لكل منتج لحاله، لا
                // عبر كل منتجات الفاتورة مع بعض — وإلا رقم/لون "الكروب"
                // بيصير متسلسل عشوائياً حسب ترتيب الأسعار العالمي (مثال
                // حقيقي: منتج له كروبان بسعرين مختلفين، بس ظهروا "كروب٤"
                // و"كروب٨" بدل "كروب١" و"كروب٢" لأنه منتجات تانية تدخّلت
                // بينهم بالترتيب العالمي بالسعر).
                const priceListByProduct = {};
                grpRows.forEach(g => {
                    if (!priceListByProduct[g.product_id]) priceListByProduct[g.product_id] = [];
                    if (!priceListByProduct[g.product_id].includes(g.default_price_base))
                        priceListByProduct[g.product_id].push(g.default_price_base);
                });
                Object.values(priceListByProduct).forEach(list => list.sort((a, b) => a - b));
                const itemsHtml = grpRows.map(g => {
                    const pi = priceListByProduct[g.product_id].indexOf(g.default_price_base);
                    const [bg, clr, br] = GRP_COLORS[pi % 4];
                    const grpBadge = `<span style="background:${bg};color:${clr};border:1px solid ${br};border-radius:12px;font-size:.68rem;padding:2px 8px;font-weight:600">كروب ${pi + 1}</span>`;
                    // ⚠ عدد المنتجات = عدد الكروبات × عدد القطع بالباكيت —
                    // نفس آلية invoice_new.php بالضبط. عدد القطع بالباكيت
                    // نفسه غير مخزَّن على purchase_items (لا عمود له بالجدول)،
                    // بس مشتق موثوق: هو أصلاً = عدد المقاسات المميّزة ضمن
                    // نفس الكروب (كل باكيت = قطعة وحدة من كل مقاس).
                    const packetQty = g.sizes.length || 1;
                    const pieceCount = g.qty * packetQty;
                    return `<tr>
                <td>
                    <div class="fw-600" style="font-size:.8rem">${g.product_name}</div>
                    <div style="font-size:.7rem;color:#94a3b8" dir="ltr">${g.model_number}</div>
                </td>
                <td>${grpBadge}
                    <div style="font-size:.72rem;font-weight:600;color:#334155;margin-top:3px">${formatSizeRange(g)}</div>
                </td>
                <td class="text-center" style="font-size:.78rem">${g.colors.join(' · ') || '—'}</td>
                <td class="n text-center fw-600">${g.qty.toFixed(0)}</td>
                <td class="n text-center" style="color:#7c3aed">${pieceCount.toFixed(0)}</td>
                <td class="n text-center">${g.unit_price_base.toFixed(2)} ${BASE_CUR_SYM}</td>
                <td class="n text-end fw-600">${g.total.toFixed(2)} ${BASE_CUR_SYM}</td>
            </tr>`;
                }).join('');
                document.getElementById('vBody').innerHTML = `
        <div class="row g-2 mb-3">
            <div class="col-md-3"><small style="color:#64748b">المورد</small><div class="fw-600">${p.supplier_name || '—'}</div></div>
            <div class="col-md-2"><small style="color:#64748b">التاريخ</small><div>${p.purchase_date}</div></div>
            <div class="col-md-2"><small style="color:#64748b">الاستحقاق</small><div>${p.due_date || '—'}</div></div>
            <div class="col-md-2"><small style="color:#64748b">العملة</small>
                <div><span class="badge bg-secondary-subtle text-secondary">${cur}</span>
                ${!isUSD ? `<small style="color:#64748b"> 1$=${rate}${sym}</small>` : ''}</div>
            </div>
            <div class="col-md-1"><small style="color:#64748b">الحالة</small>
                <div><span class="badge ${st.cls}">${st.label}</span></div>
            </div>
            <div class="col-md-2"><small style="color:#64748b">الدفع</small>
                <div class="${pay.cls} fw-600" style="font-size:.83rem">${pay.label}</div>
            </div>
        </div>
        <div class="table-responsive mb-3">
        <table class="mtbl" id="viewItemsTbl" style="font-size:.78rem">
            <thead><tr style="background:#f8fafc">
                <th>بيان المنتج / الموديل</th>
                <th class="text-center">الكروب / القياس</th>
                <th class="text-center">الألوان</th>
                <th class="text-center">عدد الكروبات</th>
                <th class="text-center" style="color:#7c3aed">عدد المنتجات</th>
                <th class="text-center">سعر التكلفة بعد الخصم (${BASE_CUR_SYM})</th>
                <th class="text-end">الإجمالي (${BASE_CUR_SYM})</th>
            </tr></thead>
            <tbody>${itemsHtml || '<tr><td colspan="7" class="text-center text-muted py-3">لا توجد بنود</td></tr>'}</tbody>
        </table>
        </div>
        <div class="row justify-content-end">
          <div class="col-md-5">
            <div style="background:#f8fafc;border-radius:10px;padding:10px 14px">
                ${parseFloat(p.discount_amount) > 0 ? `<div class="det-row"><span style="color:#64748b">الخصم</span><span class="n text-danger">-${parseFloat(p.discount_amount).toFixed(2)} ${sym}</span></div>` : ''}
                ${parseFloat(p.tax_amount) > 0 ? `<div class="det-row"><span style="color:#64748b">الضريبة</span><span class="n">+${parseFloat(p.tax_amount).toFixed(2)} ${sym}</span></div>` : ''}
                <div class="det-row" style="font-weight:700;font-size:.9rem;border-top:1px solid #e2e8f0;padding-top:6px">
                    <span>الصافي</span>
                    <span class="n">${fmt(p.final_amount)} ${sym}
                    ${!isUSD && p.final_amount_base_currency ? `<small style="color:#94a3b8;font-weight:400"> (${parseFloat(p.final_amount_base_currency).toFixed(2)} $)</small>` : ''}</span>
                </div>
            </div>
            ${(() => {
                        // ⚠ تحقق تحذيري فقط — الصافي المعروض فوق دايماً من
                        // final_amount المخزّن (الحقيقة المحاسبية المعتمدة)،
                        // مو من مجموع البنود المعروضة. لو فيه فرق حقيقي بينهم
                        // (زي الفاتورة يلي كشفت باگ الكمية المضاعفة)، نطلع
                        // تحذير صريح بدل ما نخبيه أو نعرض رقم البنود بصمت
                        // وكأنه هو "الصافي" الرسمي.
                        // ⚠ تصحيح: المقارنة الصح هي مع total_amount (المجموع
                        // قبل الخصم والضريبة) — final_amount هو بعد تطبيقهم
                        // فطبيعي يختلف عن مجموع البنود الخام دايماً لو فيه
                        // خصم أو ضريبة على الفاتورة (هاد مو باگ، هيك المفروض).
                        const itemsSum = grpRows.reduce((s, g) => s + g.total, 0);
                        const diff = Math.abs(itemsSum - parseFloat(p.total_amount || 0));
                        if (diff > 0.05) {
                            return `<div class="mt-2" style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:8px 12px;font-size:.75rem;color:#dc2626">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    تحذير: مجموع البنود المعروضة (${fmt(itemsSum)} ${sym}) لا يطابق المجموع الفرعي المخزَّن على الفاتورة (قبل الخصم والضريبة) —
                    فرق ${fmt(diff)} ${sym}. راجع الفاتورة يدوياً.
                </div>`;
                        }
                        return '';
                    })()}
          </div>
        </div>
        ${buildConfirmationDetailsHtml(p)}
        ${p.notes ? `<div style="background:#f8fafc;border-radius:8px;padding:8px 12px;margin-top:10px;font-size:.78rem;color:#64748b">${p.notes}</div>` : ''}
        ${p.status === 'draft' ? `<div style="margin-top:12px">
            <a href="invoice_edit.php?id=${p.id}" class="btn btn-sm fw-600"
               style="border-radius:8px;border:1px solid var(--section-color);color:var(--section-color);width:100%;font-size:.8rem;text-decoration:none;text-align:center;display:block;padding:.5rem">
                <i class="bi bi-pencil me-1"></i>تعديل
            </a>
        </div>`: ''}`;
                // ⚠ لازم بعد تعيين innerHTML مباشرة — الجدول يُبنى من
                // جديد بكل مرة يُفتح فيها المودال، فما بيقدر يشتغل الفرز
                // إلا بعد ما يصير العنصر موجود فعلياً بالـDOM.
                makeSortable(document.getElementById('viewItemsTbl'));
            });
        }
        let _cId = 0, _cTotal = 0, _cCurSym = '$', _cDiscount = 0, _cTax = 0, _cProducts = 0, _cPurchaseData = null, _cSettleDiscAmt = 0;

        // ⚠ يبني قسم "تفاصيل التأكيد" من القيود الفعلية المرجعة من
        // get_purchase (p.journal_entries) — مو من تخمين أو حساب محلي،
        // حتى ما يصير عرض غير مطابق للواقع المحاسبي الحقيقي.
        function buildConfirmationDetailsHtml(p) {
            if (p.status === 'draft') return '';
            const JE_LABELS = {
                purchase: {label: 'استلام الفاتورة (مخزون)', icon: 'bi-box-seam', color: '#dc2626'},
                purchase_shipping: {label: 'أجور شحن', icon: 'bi-truck', color: '#92400e'},
                purchase_payment: {label: 'دفعة نقدية', icon: 'bi-cash-coin', color: '#16a34a'},
                purchase_settlement_discount: {label: 'خصم تعجيل دفع', icon: 'bi-lightning-charge', color: '#16a34a'},
            };
            const jes = p.journal_entries || [];
            const mainJe = jes.find(j => j.reference_type === 'purchase');
            const confirmDate = mainJe?.entry_date || p.updated_at || '—';
            const fmt = n => new Intl.NumberFormat('en').format(parseFloat(n || 0).toFixed(2));

            const rows = jes.map(j => {
                const meta = JE_LABELS[j.reference_type] || {label: j.reference_type, icon: 'bi-receipt', color: '#64748b'};
                return `<tr>
            <td style="font-size:.78rem"><i class="bi ${meta.icon} me-1" style="color:${meta.color}"></i>${meta.label}</td>
            <td style="font-size:.76rem;color:#94a3b8">${j.entry_date}</td>
            <td style="font-size:.76rem;color:#94a3b8" dir="ltr">${j.entry_number}</td>
            <td class="n text-end fw-600" style="font-size:.8rem">${fmt(j.total_debit)} ${j.je_currency || ''}</td>
        </tr>`;
            }).join('');

            const payStatusColor = p.payment_status === 'paid' ? '#16a34a' : (p.payment_status === 'partial' ? '#d97706' : '#dc2626');

            return `
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 14px;margin-top:12px">
            <div style="font-size:.82rem;font-weight:700;color:#065f46;margin-bottom:8px">
                <i class="bi bi-check-circle-fill me-1"></i>تفاصيل التأكيد — بتاريخ ${confirmDate}
            </div>
            <div class="row g-2 mb-2">
                <div class="col-6 col-md-3">
                    <small style="color:#64748b">حالة الدفع</small>
                    <div class="fw-600" style="color:${payStatusColor}">${PAY_MAP[p.payment_status]?.label || p.payment_status}</div>
                </div>
                <div class="col-6 col-md-3">
                    <small style="color:#64748b">إجمالي المسدَّد</small>
                    <div class="fw-600">${fmt(p.paid_amount)} ${p.currency_symbol || '$'}</div>
                </div>
                <div class="col-6 col-md-3">
                    <small style="color:#64748b">المتبقي على المورد</small>
                    <div class="fw-600" style="color:${parseFloat(p.balance_amount) > 0 ? '#dc2626' : '#16a34a'}">${fmt(p.balance_amount)} ${p.currency_symbol || '$'}</div>
                </div>
                <div class="col-6 col-md-3">
                    <small style="color:#64748b">عدد القيود المرتبطة</small>
                    <div class="fw-600">${jes.length}</div>
                </div>
            </div>
            ${rows ? `<div class="table-responsive">
                <table class="mtbl" style="font-size:.78rem;background:#fff;border-radius:8px">
                    <thead><tr style="background:#f8fafc">
                        <th>نوع القيد</th><th>التاريخ</th><th>رقم القيد</th><th class="text-end">المبلغ</th>
                    </tr></thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>` : '<div class="text-muted" style="font-size:.78rem">لا قيود مرتبطة موجودة</div>'}
        </div>`;
        }

        function float(v) {return parseFloat(v) || 0;}

        function openConfirmModal(id, no, total, sym) {
            _cId = id; _cTotal = parseFloat(total) || 0; _cCurSym = sym || '$';
            document.getElementById('cId').value = id;
            document.getElementById('cInvNo').textContent = 'فاتورة: ' + no;
            document.getElementById('cCurSym').value = sym;
            document.getElementById('cInvTotal').value = total;
            document.getElementById('cReceiveDate').value = '<?= date('Y-m-d') ?>';
            document.getElementById('cShipping').value = '';
            document.getElementById('cShippingCarrier').value = '';
            document.getElementById('cShippingDesc').value = '';
            document.getElementById('cShippingPayableId').value = '';
            document.getElementById('shippingPayWrap').style.display = 'none';
            document.getElementById('shipCreditNote').style.display = 'none';
            document.getElementById('cShipCashAccount').value = '';
            document.getElementById('cPaidAmt').value = '';
            document.getElementById('cNotes').value = '';
            document.getElementById('cWarehouse').value = '';
            document.getElementById('cCashAccount').value = '';
            document.getElementById('cExactSettle').checked = false;
            _cPaidRateTouched = false;
            document.getElementById('cItemsBody').innerHTML = '<tr><td colspan="7" class="text-center p-3"><span class="spinner-border spinner-border-sm"></span></td></tr>';
            clearImg();
            confirmModal.show();
            // جلب بنود الفاتورة
            post({_action: 'get_purchase', id}).then(d => {
                if (!d.ok) {document.getElementById('cItemsBody').innerHTML = '<tr><td colspan="7" class="text-center text-danger p-2">خطأ: ' + d.msg + '</td></tr>'; return;}
                const p = d.data;
                _cPurchaseData = p; // حفظ للطباعة

                // ⚠ عملة دفع الفاتورة محصورة بخيارين بس (قرار تصميمي
                // متعمَّد): عملة الفاتورة نفسها، أو عملة الفرع الأساسية —
                // بدون أي عملة ثالثة، حتى ما نحتاج سعر صرف يدوي إطلاقاً
                // بهالحقل. دفع بعملة ثالثة (نادر) يصير من صفحة "المدفوعات"
                // بقسم المحاسبة، مو من هون.
                (function populatePaidCurOptions() {
                    const sel = document.getElementById('cPaidCur');
                    const invC = p.currency_code || 'USD';
                    const baseC = p.base_currency_code || 'USD';
                    const opts = invC === baseC ? [invC] : [invC, baseC];
                    sel.innerHTML = opts.map(c => `<option value="${c}">${c}</option>`).join('');
                })();
                _cProducts = parseFloat(p.total_amount) || 0;
                _cDiscount = parseFloat(p.discount_amount) || 0;
                _cTax = parseFloat(p.tax_amount) || 0;
                const sym = p.currency_symbol || '$';
                const cur = p.currency_code || 'USD';
                const fmt = n => new Intl.NumberFormat('en').format(parseFloat(n || 0).toFixed(2));

                // ── المستودع من الفاتورة ──
                // now sourced from purchases_{TS}.warehouse_id (one warehouse per
                // whole invoice) instead of purchase_items_{TS} — see get_purchase.
                document.getElementById('cWarehouseName').textContent = p.warehouse_name || 'المستودع الرئيسي';
                document.getElementById('cWarehouse').value = p.warehouse_id || '';
                // بنود — مجمعة حسب الكروب (منتج × group_key الحقيقي)، مع
                // تجميع كل الألوان ضمن نفس الكروب بعمود واحد بدل صف منفصل
                // لكل لون.
                // ⚠ تسليم من المحادثة العامة (handoff_group_key_purchases.md):
                // group_key المخزَّن على product_sizes صار مصدر الحقيقة —
                // بدل إعادة بناء السعر الافتراضي، يلي كان بيفشل لو كروبين
                // مختلفين (سعر افتراضي وخصم مختلفين) صادف طلع صافيهم نفس
                // الرقم. احتياط بسيط لبيانات قديمة بلا group_key.
                const groups = {};
                (p.items || []).forEach(it => {
                    const priceBase = parseFloat(it.unit_price_base_currency ?? it.unit_price);
                    const discPct = parseFloat(it.discount_percentage) || 0;
                    const defaultPriceBase = discPct > 0 ? priceBase / (1 - discPct / 100) : priceBase;
                    const groupKey = it.group_key || defaultPriceBase.toFixed(4);
                    const key = (it.product_id || it.product_name || it.id) + '_' + groupKey;
                    if (!groups[key]) groups[key] = {
                        name: it.product_name || ('بند #' + it.id), colors: [], sizes: [], ageType: it.age_type || '',
                        qty: 0, unit: priceBase, total: 0, _colorQty: {}
                    };
                    // ⚠ إصلاح جوهري: نجمع مرة وحدة لكل لون فريد ثم نجمع
                    // الألوان مع بعض — راجع نفس الشرح المفصَّل بمودال
                    // التفاصيل (viewInvoice) أعلاه بالضبط.
                    const colorKey2 = it.color || '_none';
                    groups[key]._colorQty[colorKey2] = parseFloat(it.quantity);
                    groups[key].qty = Object.values(groups[key]._colorQty).reduce((s, v) => s + v, 0);
                    groups[key].total += parseFloat(it.total_price);
                    if (it.color && !groups[key].colors.includes(it.color)) groups[key].colors.push(it.color);
                    if (it.size && !groups[key].sizes.includes(it.size)) groups[key].sizes.push(it.size);
                });
                let rows = '', i = 1;
                Object.values(groups).forEach(g => {
                    rows += `<tr style="border-bottom:1px solid #f1f5f9">
                <td style="padding:5px 10px;color:#94a3b8">${i++}</td>
                <td style="padding:5px 10px;font-weight:600">${g.name}</td>
                <td style="padding:5px 10px;text-align:center;font-size:.72rem;font-weight:600;color:#334155">${formatSizeRange(g)}</td>
                <td style="padding:5px 10px;text-align:center;color:#16a34a">${g.colors.join(' · ') || '—'}</td>
                <td style="padding:5px 10px;text-align:center">${g.qty}</td>
                <td style="padding:5px 10px;text-align:left;direction:ltr">${BASE_CUR_SYM} ${fmt(g.unit)}</td>
                <td style="padding:5px 10px;text-align:left;direction:ltr;font-weight:600">${BASE_CUR_SYM} ${fmt(g.total)}</td>
            </tr>`;
                });
                document.getElementById('cItemsBody').innerHTML = rows || '<tr><td colspan="7" class="text-center text-muted p-2">لا توجد بنود</td></tr>';
                // ملخص المبالغ — ⚠ بعملة الفرع دائماً (BASE_CUR_SYM)، لا
                // عملة الفاتورة (sym) — total_amount/discount_amount/
                // tax_amount/final_amount كلهم بعملة الفرع فعلياً (قرار
                // invoice_new.php)، عرضهم برمز الفاتورة كان مضلِّلاً
                // (مبلغ صحيح، رمز عملة غلط).
                document.getElementById('cSumProducts').textContent = BASE_CUR_SYM + ' ' + fmt(p.total_amount);
                document.getElementById('cSumDiscount').textContent = (parseFloat(p.discount_amount || 0) / parseFloat(p.total_amount || 1) * 100).toFixed(2) + '%';
                document.getElementById('cSumDiscountAmt').textContent = '- ' + BASE_CUR_SYM + ' ' + fmt(p.discount_amount);
                document.getElementById('cSumTax').textContent = BASE_CUR_SYM + ' ' + fmt(p.tax_amount);
                document.getElementById('cSumFinal').textContent = BASE_CUR_SYM + ' ' + fmt(p.final_amount);
                const exRate = parseFloat(p.exchange_rate) || 1;
                document.getElementById('cSumExRate').textContent = cur === (p.base_currency_code || 'USD') ? '—' : ('1 ' + (p.base_currency_code || 'USD') + ' = ' + exRate.toFixed(4) + ' ' + cur);
                _cCurSym = sym; _cTotal = parseFloat(p.final_amount) || 0;
                updateTotal();

                // ── خصم تعجيل الدفع ──
                // ⚠ العرض هون بس معلومة/اقتراح — القرار النهائي (هل
                // ينطبق فعلاً) بيصير على السيرفر وقت التأكيد (تحقق من
                // التاريخ + كون الدفعة كاملة)، مو من هالعرض. الزر هون
                // بس بيعبّي المبلغ المخصوم بحقل الدفع كاقتراح مريح.
                const settlePct = parseFloat(p.settlement_discount_pct || 0);
                const banner = document.getElementById('cSettleDiscBanner');
                _cSettleDiscAmt = 0;
                if (settlePct > 0 && p.due_date) {
                    const today = new Date().toISOString().slice(0, 10);
                    const stillEligible = today <= p.due_date;
                    _cSettleDiscAmt = parseFloat(p.final_amount) * settlePct / 100;
                    const discountedTotal = parseFloat(p.final_amount) - _cSettleDiscAmt;
                    if (stillEligible) {
                        banner.style.display = '';
                        document.getElementById('cSettleDiscText').innerHTML =
                            `خصم تعجيل دفع ${settlePct}% متاح لو سدّدت الفاتورة كاملة اليوم (قبل ${p.due_date}) —
                             المبلغ بعد الخصم: <strong>${sym} ${fmt(discountedTotal)}</strong> بدل ${sym} ${fmt(p.final_amount)}`;
                        document.getElementById('cSettleDiscBtn').style.display = '';
                    } else {
                        banner.style.display = '';
                        banner.querySelector('div').style.background = '#fef2f2';
                        banner.querySelector('div').style.borderColor = '#fecaca';
                        document.getElementById('cSettleDiscText').className = 'text-danger fw-600';
                        document.getElementById('cSettleDiscText').innerHTML =
                            `فات موعد خصم تعجيل الدفع (${settlePct}%) — كان لازم السداد قبل ${p.due_date}. المبلغ الكامل مستحق الآن.`;
                        document.getElementById('cSettleDiscBtn').style.display = 'none';
                    }
                } else {
                    banner.style.display = 'none';
                }

                // إضافة خيار حساب الدفعة المقدمة الخاص بمورد هالفاتورة
                // بالتحديد (لو معرَّف له حساب أصلاً) — تحكّم يدوي كامل،
                // بدون أي تطبيق تلقائي بالخلفية (راجع confirm_purchase_invoice.php:
                // تعطّلت كتلة التطبيق التلقائي القديمة لتفادي خصم مزدوج).
                const cashSel = document.getElementById('cCashAccount');
                cashSel.querySelectorAll('option[data-type="advance"]').forEach(o => o.remove());
                if (p.advance_account) {
                    const av = p.advance_account;
                    const opt = document.createElement('option');
                    opt.value = av.id;
                    opt.dataset.cur = av.cur_code || '';
                    opt.dataset.balance = av.balance || 0;
                    opt.dataset.sym = av.cur_sym || '';
                    opt.dataset.type = 'advance';
                    opt.textContent = `🔸 دفعة مقدمة — ${p.supplier_name} (الرصيد: ${Number(av.balance).toFixed(2)} ${av.cur_sym || ''})`;
                    cashSel.appendChild(opt);
                }
                document.getElementById('cCashAccBalanceHint').innerHTML = '';

                // فلترة حسابات الصندوق/البنك حسب العملة المختارة أول ما تفتح
                onShippingCurChange();
                filterAccountsByCurrency('cCashAccount', document.getElementById('cPaidCur').value);
            });
        }

        // عرض رصيد الحساب المختار (صندوق/بنك/دفعة مقدمة) كتلميح — وتحذير
        // أحمر صريح لو الحساب المختار هو "دفعة مقدمة" ورصيده أقل من
        // المبلغ المطلوب دفعه من خلاله (مو رفض تلقائي، بس تنبيه واضح؛
        // الرفض الفعلي بيصير عند الضغط على "تأكيد" — راجع doConfirm()).
        function applySettlementDiscount() {
            if (!_cPurchaseData || !_cSettleDiscAmt) return;
            const invCur = _cPurchaseData.currency_code || 'USD';
            document.getElementById('cPaidCur').value = invCur;
            const discountedTotal = parseFloat(_cPurchaseData.final_amount) - _cSettleDiscAmt;
            document.getElementById('cPaidAmt').value = discountedTotal.toFixed(2);
            onPaidChange();
            // ⚠ تفعيل تلقائي لتسوية فروقات التقريب — بما إنه المستخدم
            // فعّل خصم التعجيل بنية التسديد الكامل صراحة، منطقي نضمن
            // إنه فرق تقريب بسيط (كسور سنت) ما يمنع تسجيلها "مدفوعة
            // بالكامل". لو ضغط المستخدم هالزر، هاي التسوية تلقائية —
            // ما بتحتاج تفعيل يدوي إضافي منه.
            document.getElementById('cExactSettle').checked = true;
            toast('✅ تم تعبئة المبلغ بعد خصم التعجيل — بقي تختار حساب الدفع وتضغط تأكيد اليوم بالذات');
        }

        function onCashAccountChange() {
            const sel = document.getElementById('cCashAccount');
            const opt = sel.options[sel.selectedIndex];
            const hint = document.getElementById('cCashAccBalanceHint');
            if (!opt || !opt.value) {hint.innerHTML = ''; return;}

            const balance = parseFloat(opt.dataset.balance || 0);
            const sym = opt.dataset.sym || '';
            const isAdvance = opt.dataset.type === 'advance';
            const paidAmt = parseFloat(document.getElementById('cPaidAmt')?.value || 0);

            if (isAdvance && paidAmt > 0 && paidAmt > balance) {
                hint.innerHTML = `<i class="bi bi-exclamation-triangle-fill text-danger me-1"></i>
                    <span class="text-danger fw-600">رصيد الدفعة المقدمة للمورد صفر أو غير كافٍ
                    (المتاح: ${balance.toFixed(2)} ${sym}) — قلّل المبلغ أو اختر حساب صندوق/بنك للباقي</span>`;
                return;
            }

            // تلوين الرصيد حسب إشارته: أخضر = موجب، أحمر = سالب، أصفر = صفر تماماً
            let color, icon;
            if (balance > 0) {color = '#16a34a'; icon = 'bi-wallet2';}
            else if (balance < 0) {color = '#dc2626'; icon = 'bi-exclamation-circle';}
            else {color = '#ca8a04'; icon = 'bi-dash-circle';}

            hint.innerHTML = `<i class="bi ${icon} me-1" style="color:${color}"></i>
                الرصيد الحالي: <strong style="color:${color}">${balance.toFixed(2)} ${sym}</strong>`;
        }

        // فلترة قائمة حسابات صندوق/بنك لتُظهر فقط الحسابات بنفس عملة
        // currencyCode المطلوبة (عبر data-cur المُخزّن أصلاً بكل <option>)
        // — بدل ما تظهر كل الحسابات بكل العملات مع بعض (مو منطقي تختار
        // صندوق ليرة سورية وتدفع منه بالدولار).
        function filterAccountsByCurrency(selectId, currencyCode) {
            const sel = document.getElementById(selectId);
            if (!sel) return;
            let stillValid = false;
            Array.from(sel.options).forEach(opt => {
                if (!opt.value) {opt.style.display = ''; return;} // خيار "— اختر —" دائماً ظاهر
                const show = !currencyCode || opt.dataset.cur === currencyCode;
                opt.style.display = show ? '' : 'none';
                if (show && opt.value === sel.value) stillValid = true;
            });
            if (!stillValid) sel.value = '';
        }

        function onShippingCurChange() {
            filterAccountsByCurrency('cShipCashAccount', document.getElementById('cShippingCur').value);

            // ⚠ حقل سعر صرف الشحن يظهر فقط لو عملة الشحن "عملة ثالثة" —
            // مختلفة عن عملة الفاتورة (يلي سعرها معروف أصلاً من الفاتورة
            // نفسها) ومختلفة عن عملة الفرع (يلي سعرها = 1 بديهياً). هالسعر
            // يُدخَل يدوياً من المستخدم (لحظة الشحنة الفعلية)، مو من جدول
            // currencies العام — لأنه ممكن يختلف عن "آخر سعر معروف" بالجدول.
            const shipCur = document.getElementById('cShippingCur').value;
            const invCur = _cPurchaseData?.currency_code || 'USD';
            const baseCur = _cPurchaseData?.base_currency_code || 'USD';
            const wrap = document.getElementById('shippingRateWrap');
            const isThirdCur = shipCur && shipCur !== invCur && shipCur !== baseCur;
            wrap.style.display = isThirdCur ? '' : 'none';
            if (isThirdCur) {
                document.getElementById('shippingRateLbl').textContent = `1 ${baseCur} =`;
            }
            updateTotal();
        }

        let _cPaidRateTouched = false;

        function onPaidChange() {
            const paidCur = document.getElementById('cPaidCur').value;
            const invCur = _cPurchaseData?.currency_code || 'USD';
            const baseCur = _cPurchaseData?.base_currency_code || 'USD';
            const rateWrap = document.getElementById('cPaidRateWrap');
            const convertWrap = document.getElementById('cPaidConvertWrap');
            filterAccountsByCurrency('cCashAccount', paidCur);
            if (paidCur && paidCur !== invCur) {
                // ⚠ عملة الفرع هي المرجع الثابت دايماً ("1 عملة الفرع = س
                // عملة الفاتورة") — نفس exchange_rate المخزَّن على الفاتورة
                // نفسها مباشرة، بدون أي عكس. لو وصلنا هون (عملة الدفع ≠
                // عملة الفاتورة)، فهاد يعني الدفع بعملة الفرع تحديداً.
                rateWrap.style.display = '';
                document.getElementById('cPaidRateLabel').textContent = '1 ' + baseCur + ' =';
                document.getElementById('cPaidRateSuffix').textContent = invCur;
                if (!_cPaidRateTouched) {
                    document.getElementById('cPaidRate').value = (parseFloat(_cPurchaseData?.exchange_rate) || 1).toFixed(4);
                }
                convertWrap.style.display = '';
            } else {
                rateWrap.style.display = 'none';
                convertWrap.style.display = 'none';
                document.getElementById('cPaidRate').value = '1';
            }
            updateTotal();
            onCashAccountChange();
        }

        // جلب آخر سعر صرف من جدول currencies العام — بمعنى "1 عملة الفرع
        // = س عملة الفاتورة" (عملة الفرع مرجع ثابت، نفس اتفاقية exchange_rate
        // المخزَّن على أي فاتورة). القيمة تبقى قابلة للتعديل اليدوي بعدها
        // بكل الأحوال — هالزر بس اقتراح/تحديث، مو تثبيت.
        async function fetchPaidRate() {
            const invCur = _cPurchaseData?.currency_code || 'USD';
            const baseCur = _cPurchaseData?.base_currency_code || 'USD';
            const icon = document.getElementById('paidRateIcon');
            icon.classList.add('spin');
            try {
                const d = await post({_action: 'get_currency_rate', from: baseCur, to: invCur});
                if (d.ok && d.rate) {
                    document.getElementById('cPaidRate').value = d.rate.toFixed(4);
                    _cPaidRateTouched = false; // القيمة صارت من جدول العملات، مو تعديل يدوي
                    document.getElementById('cPaidRateHint').innerHTML =
                        '<i class="bi bi-check-circle-fill text-success me-1"></i>1 ' + baseCur + ' = ' + d.rate.toFixed(4) + ' ' + invCur + ' (من جدول العملات — قابل للتعديل يدوياً)';
                    updateTotal();
                } else {
                    document.getElementById('cPaidRateHint').innerHTML =
                        '<i class="bi bi-exclamation-triangle text-warning me-1"></i>' + (d.msg || 'غير متوفر') + ' — أدخل السعر يدوياً';
                }
            } catch (e) {
                document.getElementById('cPaidRateHint').innerHTML =
                    '<i class="bi bi-wifi-off text-danger me-1"></i>تعذّر الجلب — أدخل السعر يدوياً';
            } finally {icon.classList.remove('spin');}
        }

        function onCarrierChange(sel) {
            const opt = sel.options[sel.selectedIndex];
            document.getElementById('cShippingDesc').value = opt.value ? opt.dataset.name : '';
            document.getElementById('cShippingPayableId').value = opt.value ? (opt.dataset.payableId || '') : '';
            // إظهار قسم طريقة الدفع فقط إذا اختار شركة + مبلغ
            const hasShip = parseFloat(document.getElementById('cShipping').value) || 0;
            document.getElementById('shippingPayWrap').style.display =
                (opt.value && hasShip > 0 && document.querySelector('input[name="shippingOn"]:checked')?.value === 'us') ? '' : 'none';
        }

        function onShipPayChange() {
            const val = document.querySelector('input[name="shippingPay"]:checked')?.value || 'cash';
            document.getElementById('shipCashAccWrap').style.display = val === 'cash' ? '' : 'none';
            document.getElementById('shipCreditNote').style.display = val === 'credit' ? '' : 'none';
        }

        function updateTotal() {
            const shipOn = document.querySelector('input[name="shippingOn"]:checked')?.value || 'us';
            const ship = parseFloat(document.getElementById('cShipping').value) || 0;
            const shipCur = document.getElementById('cShippingCur').value || 'USD';
            const invCur = _cPurchaseData?.currency_code || 'USD';
            const invSym = _cPurchaseData?.currency_symbol || '$';
            const invRate = parseFloat(_cPurchaseData?.exchange_rate) || 1; // "1 عملة الفرع = invRate عملة الفاتورة"
            const fmt = n => new Intl.NumberFormat('en').format(Math.abs(parseFloat(n || 0)).toFixed(2));

            // الدفع الجزئي مع التحويل
            const paidAmt = parseFloat(document.getElementById('cPaidAmt').value) || 0;
            const paidCur = document.getElementById('cPaidCur').value || invCur;
            // ⚠ cPaidRate معناه "1 عملة الفرع = س عملة الفاتورة" (عملة
            // الفرع هي المرجع الثابت — نفس اتفاقية exchange_rate المخزَّن
            // على الفاتورة نفسها بالضبط، بدون أي عكس). لما تدفع بعملة
            // الفرع، التحويل لعملة الفاتورة = ضرب (مبلغ الفرع × السعر)،
            // مو قسمة.
            const paidRate = parseFloat(document.getElementById('cPaidRate').value) || 1;
            const paidInInvCur = paidCur === invCur ? paidAmt : paidAmt * paidRate;

            // عرض المبلغ المحوّل
            if (paidAmt > 0 && paidCur !== invCur) {
                document.getElementById('cPaidConverted').textContent =
                    invSym + ' ' + new Intl.NumberFormat('en').format(paidInInvCur.toFixed(2));
            }

            // ⚠ _cTotal مخزَّن بعملة الفرع دائماً (p.final_amount — قرار
            // invoice_new.php). القسم تحت عنوانه صراحة "عملة: <عملة
            // الفاتورة>"، فلازم نحوّله هون محلياً لعملة الفاتورة الحقيقية
            // قبل أي عرض أو طرح — وإلا كنا عم نطرح paidInInvCur (فعلاً
            // بعملة الفاتورة) من _cTotal (فعلاً بعملة الفرع) مباشرة، رقمين
            // بعملتين مختلفتين، نفس فئة الباگ يلي انصلح بجدول البنود
            // وملخص "cSumProducts/Final" فوق. ما نلمس _cTotal نفسه (متغيّر
            // عام مستخدم بعملة الفرع بمكان تاني).
            const totalInInvCur = _cTotal * invRate;

            // تجميع مبالغ الفاتورة والمدفوع حسب العملة — الشحن عمداً
            // برّا هالتجميع (راجع تعليق أسفل)
            const totals = {};
            if (!totals[invCur]) totals[invCur] = {sym: invSym, inv: 0, paid: 0};
            totals[invCur].inv = totalInInvCur;
            if (paidAmt > 0) {
                if (!totals[invCur]) totals[invCur] = {sym: invSym, inv: 0, paid: 0};
                totals[invCur].paid = paidInInvCur;
            }

            // بناء HTML الملخص — قسمين منفصلين تماماً عمداً:
            // (١) رصيد الفاتورة نفسها (المدفوع/المتبقي عبر cPaidAmt) —
            // (٢) تكلفة الشحن، بشكل معلوماتي بس، بدون أي "متبقي" أو دمج
            // مع رصيد الفاتورة — لأنها بتتسوى بآلية منفصلة كلياً (نقدي
            // فوري من حسابها الخاص، أو آجل كذمة لشركة الشحن)، مش من
            // خلال حقل الدفع الجزئي إطلاقاً. دمجهم سابقاً كان مضلّل —
            // بيوهم إنه فيه "رصيد شحن معلّق" لسا لازم يُدفع من نفس
            // مصدر دفع الفاتورة، وهاد غير صحيح.
            let html = '';
            Object.entries(totals).forEach(([cur, t]) => {
                const balance = t.inv - t.paid;
                if (!t.inv) return;
                html += `<div style="border:1px solid #d1fae5;border-radius:7px;padding:7px 10px;margin-bottom:6px;background:#fff">
            <div style="font-size:.72rem;font-weight:700;color:#065f46;margin-bottom:4px">
                <i class="bi bi-currency-exchange me-1"></i>عملة: ${cur}
            </div>
            <div class="d-flex justify-content-between" style="font-size:.79rem;margin-bottom:2px">
            <span class="text-muted">إجمالي الفاتورة</span>
            <span class="fw-600">${t.sym} ${fmt(t.inv)}</span></div>`;
                if (t.paid) html += `<div class="d-flex justify-content-between" style="font-size:.79rem;margin-bottom:2px">
            <span class="text-muted">— المدفوع الآن</span>
            <span class="fw-600 text-success">${t.sym} ${fmt(t.paid)}</span></div>`;
                html += `<div class="d-flex justify-content-between fw-700 border-top pt-1 mt-1" style="font-size:.82rem">
            <span>المتبقي من الفاتورة</span>
            <span style="color:${balance > 0 ? '#dc2626' : '#16a34a'}">${t.sym} ${fmt(balance)}</span></div>
            </div>`;
            });
            // بطاقة الشحن — منفصلة، معلوماتية بس، بدون أي دمج مع رصيد الفاتورة
            if (ship > 0 && shipOn === 'us') {
                html += `<div style="border:1px solid #fde68a;border-radius:7px;padding:7px 10px;margin-bottom:6px;background:#fffbeb">
            <div style="font-size:.72rem;font-weight:700;color:#92400e;margin-bottom:4px">
                <i class="bi bi-truck me-1"></i>تكاليف الشحن (منفصلة عن الفاتورة)
            </div>
            <div class="d-flex justify-content-between" style="font-size:.79rem">
            <span class="text-muted">ستُسجَّل بقيد محاسبي مستقل</span>
            <span class="fw-600 text-warning">${shipCur} ${fmt(ship)}</span></div>
            </div>`;
            }
            document.getElementById('cTotSummary').innerHTML = html ||
                '<div class="text-muted" style="font-size:.79rem">أدخل المبالغ لعرض الملخص</div>';

            // إظهار/إخفاء حقل مبلغ الشحن
            document.getElementById('shippingAmtWrap').style.opacity = shipOn === 'us' ? '1' : '0.4';
            // إظهار طريقة دفع الشحن فقط إذا كان علينا + مبلغ + شركة
            const carrierId = document.getElementById('cShippingCarrier').value;
            const shipPayWrap = document.getElementById('shippingPayWrap');
            if (shipOn === 'us' && ship > 0 && carrierId) {
                shipPayWrap.style.display = '';
                onShipPayChange();
            } else {
                shipPayWrap.style.display = 'none';
            }
        }

        function previewImg(inp) {
            if (!inp.files || !inp.files[0]) return;
            const f = inp.files[0];
            if (f.type.startsWith('image/')) {
                const rd = new FileReader();
                rd.onload = e => {document.getElementById('cImgThumb').src = e.target.result; document.getElementById('cImgPreview').style.display = '';};
                rd.readAsDataURL(f);
            } else {document.getElementById('cImgPreview').style.display = '';}
        }
        function clearImg() {
            document.getElementById('cInvoiceImg').value = '';
            document.getElementById('cImgPreview').style.display = 'none';
            document.getElementById('cImgThumb').src = '';
        }

        // ── طباعة الفاتورة ──
        const BRANCH = <?= json_encode([
            'name' => $branchInfo['name'] ?? $branchName,
            'phone' => $branchInfo['phone'] ?? '',
            'address' => $branchInfo['address'] ?? '',
            'city' => $branchInfo['city'] ?? '',
            'email' => $branchInfo['email'] ?? '',
            'tax_number' => $branchInfo['tax_number'] ?? '',
            'tenant_slogan' => $branchInfo['tenant_slogan'] ?? '',
            'commercial_registration_number' => $branchInfo['commercial_registration_number'] ?? '',
        ]) ?>;

        function printPurchaseInvoice() {
            const p = _cPurchaseData;
            if (!p) {toast('يرجى فتح مودال التأكيد أولاً', 'danger'); return;}
            // ⚠ عملة الفرع دائماً — نفس قرار invoice_new.php: كل الأسعار
            // والمبالغ (سعر الوحدة، الإجماليات، كشف الحساب) حقيقتها
            // الوحيدة بعملة الفرع. عملة الفاتورة (sym) توثيقية بس، ما
            // بتُستخدم هون إطلاقاً — نفس الإصلاح المطبَّق بمودالي
            // التفاصيل والتأكيد سابقاً.
            const fmt = n => BASE_CUR_SYM + ' ' + new Intl.NumberFormat('en').format(parseFloat(n || 0).toFixed(2));

            // ⚠ تسليم من المحادثة العامة (handoff_group_key_purchases.md):
            // group_key الحقيقي المخزَّن على product_sizes، لا السعر
            // الافتراضي المُعاد بناؤه (نفس إصلاح تصادم الكروبات بمودالي
            // التفاصيل والتأكيد). احتياط بسيط لبيانات قديمة بلا group_key.
            const grpMap = {};
            (p.items || []).forEach(it => {
                const priceBase = parseFloat(it.unit_price_base_currency ?? it.unit_price);
                const discPct = parseFloat(it.discount_percentage) || 0;
                const defaultPriceBase = discPct > 0 ? priceBase / (1 - discPct / 100) : priceBase;
                const groupKey = it.group_key || defaultPriceBase.toFixed(4);
                const key = (it.product_id || it.product_name) + '_' + groupKey;
                if (!grpMap[key]) grpMap[key] = {
                    name: it.product_name || '—', model: it.model_number || '',
                    sizes: [], colors: [], ageType: it.age_type || '',
                    default_price: defaultPriceBase, net_price: priceBase,
                    qty: 0, _colorQty: {}
                };
                const g = grpMap[key];
                const colorKey = it.color || '_none';
                g._colorQty[colorKey] = parseFloat(it.quantity);
                g.qty = Object.values(g._colorQty).reduce((s, v) => s + v, 0);
                if (it.color && !g.colors.includes(it.color)) g.colors.push(it.color);
                if (it.size && !g.sizes.includes(it.size)) g.sizes.push(it.size);
            });
            // ⚠ عدد القطع بالباكيت = عدد المقاسات المميّزة بالكروب (نفس
            // اشتقاق مودال التفاصيل — العمود غير مخزَّن مباشرة بـ
            // purchase_items). الكمية الكلية = عدد الكروبات × الباكيت،
            // والمبلغ الإجمالي = الكمية الكلية × سعر الوحدة بعد الخصم.
            const groups = Object.values(grpMap).map(g => {
                const packetQty = g.sizes.length || 1;
                const totalQty = g.qty * packetQty;
                return {...g, packet_qty: packetQty, total_qty: totalQty, line_total: totalQty * g.net_price};
            });

            let itemRows = '', i = 1, grandTotalQty = 0;
            groups.forEach(g => {
                grandTotalQty += g.total_qty;
                itemRows += `<tr>
                <td>${i++}</td>
                <td>${g.name}</td>
                <td dir="ltr">${g.model || '—'}</td>
                <td dir="ltr">${formatSizeRange(g)}</td>
                <td style="color:#16a34a">${g.colors.join(' · ') || '—'}</td>
                <td>${fmt(g.default_price)}</td>
                <td>${fmt(g.net_price)}</td>
                <td>${g.qty}</td>
                <td>${g.total_qty}</td>
                <td class="n-total">${fmt(g.line_total)}</td>
            </tr>`;
            });

            // ⚠ المبالغ — من الحقول المخزَّنة فعلياً بعملة الفرع (قرار
            // invoice_new.php)، بلا أي تحويل عملة إضافي هون.
            const grossProducts = groups.reduce((s, g) => s + g.total_qty * g.default_price, 0);
            const sumItemDiscounts = groups.reduce((s, g) => s + g.total_qty * (g.default_price - g.net_price), 0);
            const afterItemDiscount = parseFloat(p.total_amount) || 0;
            const generalDiscPct = afterItemDiscount > 0 ? (parseFloat(p.discount_amount || 0) / afterItemDiscount * 100) : 0;
            const afterGeneralDisc = afterItemDiscount - parseFloat(p.discount_amount || 0);
            const taxPct = afterGeneralDisc > 0 ? (parseFloat(p.tax_amount || 0) / afterGeneralDisc * 100) : 0;

            const docTitle = 'فاتورة شراء منتجات';
            const docColor = '#dc2626'; // أحمر = شراء منتجات (أخضر=بيع — نفس اتفاقية sales_index.php)

            // ⚠ كشف حساب سريع مختصر — بعملة الفرع، من رصيد حساب ذمة
            // المورد (الخاص لو موجود، وإلا العام) قبل/بعد هالفاتورة —
            // راجع شرح الحساب بمعالج get_purchase بالسيرفر. آخر دفعة
            // مخزَّنة بعملة الفاتورة (paid_amount)، نحوّلها هون لعملة
            // الفرع بالقسمة على exchange_rate (نفس اتفاقية التحويل
            // المعتمدة بكل الملف).
            const balBefore = p.supplier_balance_before;
            const balAfter = p.supplier_balance_after;
            const invRate = parseFloat(p.exchange_rate) || 1;
            const lastPaymentBase = (parseFloat(p.paid_amount) || 0) / invRate;

            const TENANT_LOGO_URL = '<?= BASE_PATH ?>/assets/images/tenant_logo.png';
            const FATORIZE_LOGO_URL = '<?= BASE_PATH ?>/assets/images/fatorize.png';
            const html = `<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
<meta charset="UTF-8">
<title>فاتورة شراء ${p.purchase_number}</title>
<style>
*{margin:0;padding:0}
body{font-family:'Arial',sans-serif;font-size:12px;color:#111;padding:20px;margin:0 auto}
/* ── الترويسة: ٣ أقسام ── */
.header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:3px;padding-bottom:10px;border-bottom:2px solid ${docColor}}
.hdr-tenant{text-align:center;min-width:190px}
.hdr-tenant img{max-height:190px;max-width:190px;object-fit:contain}
.hdr-tenant .slogan{font-size:18px;font-weight:bold;color:${docColor};margin-top:15px}
.hdr-title{text-align:center;flex:1;padding-top:35px}
.hdr-title .doc-type{background:${docColor};color:#fff;font-weight:700;font-size:22px;padding:6px 22px;border-radius:12px}
.hdr-branch{min-width:100px;font-size:10px;line-height:1.7}
.hdr-branch .br-name{font-size:12px;font-weight:800;color:${docColor};margin-bottom:2px}
/* ── معلومات المورد ── */
.inv-meta{border:1px solid #e2e8f0;border-radius:6px;padding:5px 5px;margin-bottom:10px;background:#f8fafc}
.inv-meta h4{font-size:7px;font-weight:700;color:${docColor};text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;padding-bottom:3px;border-bottom:1px solid #e2e8f0}
.meta-grid{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:4px 14px}
.meta-item span:first-child{color:#64748b;font-size:7.5px;display:block}
.meta-item span:last-child{font-weight:600;font-size:8.5px}
/* ── جدول البنود ── */
table{width:100%;border-collapse:collapse;margin-bottom:10px;font-size:7.5px;border:1.5px solid #1e293b}
thead th{background:${docColor};color:#fff;padding:5px 4px;text-align:center;font-weight:600;border:1px solid #1e293b}
tbody td{padding:4px;border:1px solid #94a3b8;text-align:center}
tbody td:nth-child(2){text-align:right}
tbody tr:nth-child(even) td{background:#f8fafc}
.n-total{font-weight:700}
tfoot td{background:#f1f5f9;font-weight:700;padding:5px;text-align:center;border:1px solid #94a3b8}
/* ── قسم المبالغ ── */
.totals-wrap{display:flex;justify-content:flex-end;margin-top:8px}
.totals{width:60%;border:1px solid #e2e8f0;border-radius:6px;overflow:hidden}
.tot-row{display:flex;justify-content:space-between;padding:4px 12px;font-size:8px;border-bottom:1px solid #f1f5f9}
.tot-row.sub{color:#64748b}
.tot-row.final{background:${docColor};color:#fff;font-weight:700;font-size:10px;border:none}
/* ── الفوتر: كشف حساب مختصر ── */
.statement{margin-top:14px;border:1px solid #e2e8f0;border-radius:6px;padding:8px 12px;background:#f8fafc}
.statement h4{font-size:7px;font-weight:700;color:${docColor};margin-bottom:6px}
.statement-grid{display:flex;justify-content:space-between;text-align:center}
.statement-grid .item span:first-child{color:#64748b;font-size:7.5px;display:block;margin-bottom:2px}
.statement-grid .item span:last-child{font-weight:700;font-size:9px}
.footer-bottom{display:flex;justify-content:space-between;align-items:center;margin-top:10px;padding-top:8px;border-top:1px solid #e2e8f0}
.footer-bottom .txt{font-size:7px;color:#94a3b8}
.footer-bottom img{height:22px}
@media print{@page{margin:8mm}button{display:none}}
</style>
</head>
<body>

<!-- ══ الترويسة ══ -->
<div class="header">
  <div class="hdr-tenant">
    <img src="${TENANT_LOGO_URL}" alt="Logo" onerror="this.style.display='none'">
    ${BRANCH.tenant_slogan ? `<div class="slogan">${BRANCH.tenant_slogan}</div>` : ''}
  </div>
  <div class="hdr-title">
    <span class="doc-type">${docTitle}</span>
  </div>
  <div class="hdr-branch">
    <div class="br-name">${BRANCH.name}</div>
    ${BRANCH.address ? `<div>${BRANCH.address}</div>` : ''}
    ${BRANCH.phone ? `<div>هاتف: ${BRANCH.phone}</div>` : ''}
    ${BRANCH.tax_number ? `<div>الرقم الضريبي: ${BRANCH.tax_number}</div>` : ''}
    ${BRANCH.commercial_registration_number ? `<div>السجل التجاري: ${BRANCH.commercial_registration_number}</div>` : ''}
  </div>
</div>

<!-- ══ معلومات المورد والشحن ══ -->
<div class="inv-meta">
  <h4>معلومات المورد والشحن</h4>
  <div class="meta-grid">
    <div class="meta-item"><span>اسم المورد</span><span>${p.supplier_name || '—'}</span></div>
    <div class="meta-item"><span>رقم الهاتف</span><span dir="ltr">${p.supplier_phone || '—'}</span></div>
    <div class="meta-item"><span>رقم الفاتورة</span><span dir="ltr">${p.purchase_number}</span></div>
    <div class="meta-item"><span>تاريخ الفاتورة</span><span>${p.purchase_date || '—'}</span></div>
    <div class="meta-item"><span>العنوان</span><span>${p.supplier_address || '—'}</span></div>
    <div class="meta-item"><span>الرقم الضريبي</span><span dir="ltr">${p.supplier_tax || '—'}</span></div>
    <div class="meta-item"><span>المستودع</span><span>${p.warehouse_name || '—'}</span></div>
    <div class="meta-item"><span>العملة</span><span>${p.currency_code || 'USD'}</span></div>
  </div>
</div>

<!-- ══ بنود الفاتورة ══ -->
<div class="inv-meta">
<h4>بنود الفاتورة</h4>
<table>
  <thead><tr>
    <th>#</th><th>بيان المنتج</th><th>رقم الموديل</th><th>النمرة/القياس</th><th>اللون</th>
    <th>سعر الوحدة (بدون خصم)</th><th>سعر الوحدة بعد الخصم</th><th>عدد الكروبات</th>
    <th>الكمية الكلية</th><th>المبلغ الإجمالي</th>
  </tr></thead>
  <tbody>${itemRows}</tbody>
  <tfoot><tr>
    <td colspan="8">إجمالي الكميات</td>
    <td>${grandTotalQty}</td>
    <td>${fmt(p.final_amount)}</td>
  </tr></tfoot>
</table>
</div>

<!-- ══ المبالغ (عملة الفرع) ══ -->
<div class="totals-wrap">
  <div class="totals">
    <div class="tot-row sub"><span>المبلغ الصافي للمنتجات (بدون أي خصم):</span><span>${fmt(grossProducts)}</span></div>
    <div class="tot-row sub"><span>مجموع خصومات المنتجات الإفرادية:</span><span>- ${fmt(sumItemDiscounts)}</span></div>
    <div class="tot-row"><span>الإجمالي بعد الخصم الإفرادي:</span><span>${fmt(afterItemDiscount)}</span></div>
    <div class="tot-row sub"><span>نسبة الخصم العام:</span><span>${generalDiscPct.toFixed(2)}%</span></div>
    <div class="tot-row"><span>الإجمالي بعد الخصم العام:</span><span>${fmt(afterGeneralDisc)}</span></div>
    <div class="tot-row sub"><span>نسبة الضريبة:</span><span>${taxPct.toFixed(2)}%</span></div>
    <div class="tot-row sub"><span>قيمة الضريبة:</span><span>${fmt(p.tax_amount)}</span></div>
    <div class="tot-row final"><span>المبلغ الإجمالي النهائي:</span><span>${fmt(p.final_amount)}</span></div>
  </div>
</div>

${p.notes ? `<div style="margin-top:10px;padding:6px 10px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;font-size:8px"><b>ملاحظات:</b> ${p.notes}</div>` : ''}

<!-- ══ كشف حساب سريع مختصر (بعملة الفرع) ══ -->
<div class="statement">
  <h4>كشف حساب المورد — لمحة سريعة (بعملة الفرع)</h4>
  <div class="statement-grid">
    <div class="item"><span>المستحق قبل الفاتورة</span><span>${balBefore != null ? fmt(balBefore) : '—'}</span></div>
    <div class="item"><span>آخر دفعة</span><span style="color:#16a34a">${lastPaymentBase > 0 ? fmt(lastPaymentBase) : '—'}</span></div>
    <div class="item"><span>الرصيد النهائي</span><span style="color:${(balAfter || 0) < 0 ? '#dc2626' : '#16a34a'}">${balAfter != null ? fmt(balAfter) : '—'}</span></div>
  </div>
</div>
<div class="footer-bottom">
  <span class="txt">نظام فاتورايز المحاسبي الإداري — Fatorize ERP System</span>
  <img src="${FATORIZE_LOGO_URL}" alt="Fatorize" onerror="this.style.display='none'">
</div>

<script>window.onload=()=>window.print()<\/script>
</body></html>`;
            const w = window.open('', '_blank', 'width=900,height=700');
            w.document.write(html);
            w.document.close();
        }

        function doConfirm() {
            const paid = parseFloat(document.getElementById('cPaidAmt').value) || 0;
            const cashAcc = document.getElementById('cCashAccount').value;
            if (paid > 0 && !cashAcc) {toast('اختر حساب الدفع', 'danger'); return;}

            // ⚠ لو حقل سعر صرف الشحن ظاهر (عملة شحن ثالثة) وفيه مبلغ شحن،
            // لازم يكون معبّى — ما منسمح نمرر بدون سعر صرف حقيقي.
            const shipCost = parseFloat(document.getElementById('cShipping').value) || 0;
            const shipOn2 = document.querySelector('input[name="shippingOn"]:checked')?.value || 'us';
            if (shipCost > 0 && shipOn2 === 'us' && document.getElementById('shippingRateWrap').style.display !== 'none'
                && !document.getElementById('cShippingRate').value) {
                toast('أدخل سعر صرف الشحن (عملته مختلفة عن الفاتورة والفرع)', 'danger'); return;
            }

            // ⚠ تحقق إلزامي (مو تلميح فقط): لو الحساب المختار "دفعة مقدمة"
            // ورصيده أقل من المبلغ المطلوب دفعه منه، امنع التأكيد نهائياً —
            // بدل ما نسمح برصيد سالب لحساب دفعة مقدمة مورد.
            if (paid > 0 && cashAcc) {
                const sel = document.getElementById('cCashAccount');
                const opt = sel.options[sel.selectedIndex];
                if (opt?.dataset.type === 'advance') {
                    const balance = parseFloat(opt.dataset.balance || 0);
                    if (paid > balance) {
                        toast(`رصيد الدفعة المقدمة للمورد صفر أو غير كافٍ (المتاح: ${balance.toFixed(2)} ${opt.dataset.sym || ''}) — قلّل المبلغ أو اختر حساب صندوق/بنك`, 'danger');
                        return;
                    }
                }
            }

            // ⚠ تحذير تأكيدي (مو منع) لو المبلغ المدفوع أكبر من المتبقي
            // فعلياً على الفاتورة — سيناريو مشروع (دفعة مقدمة إضافية
            // لفاتورة جاية)، بس برضو محتمل يكون غلطة كتابية (رقم زيادة
            // بالغلط)، فلازم تنبيه صريح قبل ما يكمل، مو منع كامل.
            if (paid > 0) {
                const paidCur = document.getElementById('cPaidCur').value;
                const invCur = _cPurchaseData?.currency || 'USD';
                const rate = parseFloat(_cPurchaseData?.exchange_rate) || 1;
                // المتبقي الفعلي بنفس عملة الدفع المختارة (لا نقارن عملتين مختلفتين مع بعض)
                const balanceInInvCur = parseFloat(_cPurchaseData?.balance_amount) || 0;
                const outstanding = paidCur === invCur ? balanceInInvCur : balanceInInvCur * rate;
                if (paid > outstanding + 0.01) {
                    const diff = (paid - outstanding).toFixed(2);
                    const ok = confirm(
                        `المبلغ المدخل (${paid.toFixed(2)} ${paidCur}) أكبر من المتبقي على الفاتورة (${outstanding.toFixed(2)} ${paidCur}) بفارق ${diff} ${paidCur}.\n\n` +
                        `هل تقصد دفعة مقدمة إضافية للمورد (رح يترحّل الفرق كرصيد له لصالحك)؟\n\n` +
                        `اضغط "موافق" للمتابعة، أو "إلغاء" لتصحيح المبلغ.`
                    );
                    if (!ok) return;
                }
            }

            const shipOn = document.querySelector('input[name="shippingOn"]:checked')?.value || 'us';
            const ship = shipOn === 'us' ? (parseFloat(document.getElementById('cShipping').value) || 0) : 0;
            document.getElementById('confirmTxt').style.opacity = '0';
            document.getElementById('confirmSpin').style.display = 'inline-block';
            document.getElementById('btnConfirm').disabled = true;
            const fd = new FormData();
            fd.append('_action', 'confirm');
            fd.append('invoice_id', _cId);
            fd.append('receive_date', document.getElementById('cReceiveDate').value);
            fd.append('warehouse_id', document.getElementById('cWarehouse').value);
            fd.append('shipping_cost', ship);
            fd.append('shipping_on', shipOn);
            fd.append('shipping_currency', document.getElementById('cShippingCur').value);
            fd.append('shipping_exchange_rate', document.getElementById('cShippingRate').value || '');
            fd.append('shipping_carrier_id', document.getElementById('cShippingCarrier').value);
            fd.append('shipping_payable_id', document.getElementById('cShippingPayableId').value);
            fd.append('shipping_pay_method', document.querySelector('input[name="shippingPay"]:checked')?.value || 'cash');
            fd.append('shipping_cash_account', document.getElementById('cShipCashAccount').value);
            fd.append('exact_settle', document.getElementById('cExactSettle').checked ? '1' : '0');
            fd.append('shipping_desc', document.getElementById('cShippingDesc').value);
            fd.append('paid_amount', document.getElementById('cPaidAmt').value || '0');
            fd.append('paid_currency', document.getElementById('cPaidCur').value);
            fd.append('paid_rate', document.getElementById('cPaidRate').value || '1');
            fd.append('cash_account_id', cashAcc);
            fd.append('notes', document.getElementById('cNotes').value);
            const img = document.getElementById('cInvoiceImg');
            if (img.files && img.files[0]) fd.append('invoice_image', img.files[0]);
            fetch('../../api/confirm_purchase_invoice.php', {method: 'POST', body: fd})
                .then(r => r.json()).then(d => {
                    document.getElementById('confirmTxt').style.opacity = '1';
                    document.getElementById('confirmSpin').style.display = 'none';
                    document.getElementById('btnConfirm').disabled = false;
                    if (d.ok) {toast('✅ ' + d.msg); confirmModal.hide(); setTimeout(() => location.reload(), 800);}
                    else toast(d.msg, 'danger');
                }).catch(() => {
                    document.getElementById('confirmTxt').style.opacity = '1';
                    document.getElementById('confirmSpin').style.display = 'none';
                    document.getElementById('btnConfirm').disabled = false;
                    toast('خطأ في الاتصال', 'danger');
                });
        }

        function confirmInvoice(id, no) {
            openConfirmModal(id, no, 0, '$');
        }
        function cancelInvoice(id, no) {
            if (!confirm(`إلغاء الفاتورة "${no}"؟\nالفواتير المؤكدة سيتم عكس مخزونها وقيودها.`)) return;
            const fd = new FormData();
            fd.append('_action', 'cancel');
            fd.append('invoice_id', id);
            fetch('../../api/confirm_purchase_invoice.php', {method: 'POST', body: fd})
                .then(r => r.json())
                .then(d => {
                    if (d.ok) {toast(d.msg); setTimeout(() => location.reload(), 700);}
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

        makeSortable(document.getElementById('purchasesTbl'));

        // ── فتح مودال التفاصيل تلقائياً لو الرابط فيه ?view_id= ──────
        // مصدرها الوحيد حالياً: invoice_edit.php لما يرفض فتح فاتورة
        // مؤكدة (status ≠ draft) ويحوّل هون بدل صفحة خطأ منفصلة.
        (function autoOpenViewFromUrl() {
            const params = new URLSearchParams(location.search);
            const vid = parseInt(params.get('view_id'), 10);
            if (vid) viewInvoice(vid);
        })();
    </script>
</body>

</html>