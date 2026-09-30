<?php
/**
 * sales/sales_index.php — فواتير البيع
 * المسار: retail1/modules/sales/sales_index.php
 * (تصحيح: التعليق كان يقول "sales/invoices.php" سابقاً — اسم قديم/خطأ،
 * الاسم الحقيقي على القرص مؤكَّد الآن sales_index.php)
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('sales.invoices', 'view');
$currentModule = 'sales.invoices';

// بيانات الفرع الكاملة — لترويسة فاتورة الطباعة
$branchInfo = $pdo->prepare("SELECT * FROM branches WHERE table_suffix=? LIMIT 1");
$branchInfo->execute([$_SESSION['table_suffix']]);
$branchInfo = $branchInfo->fetch(PDO::FETCH_ASSOC) ?: [];

$TS = $_SESSION['table_suffix'];
$TI = "sales_invoices_{$TS}";
$TII = "sales_invoice_items_{$TS}";
$TC = "customers_{$TS}";
$TW = "warehouses_{$TS}";
$TWI = "warehouse_items_{$TS}";
$TV = "product_variants_{$TS}";
$TPROD = "products_{$TS}";
$TSZ = "product_sizes_{$TS}";
$TCL = "product_colors_{$TS}";
$TIAS = "invoice_account_settings_{$TS}";
$TAC = "account_charts_{$TS}";
$TJE2 = "journal_entries_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// شركات النقلية والشحن — من الجداول الجديدة الخاصة بالفرع (حسب
// آلية الشحن/الجمارك — الجدول العام القديم shipping_carriers أُلغي)
try {
    $transportCarriers = $pdo->query("SELECT id,name,contact_person,phone,payable_account_id
        FROM `transport_carriers_{$TS}` WHERE status='active' ORDER BY name")->fetchAll();
} catch (Exception $e) {
    $transportCarriers = [];
}
try {
    $shippingCarriersNew = $pdo->query("SELECT id,name,contact_person,phone,payable_account_id
        FROM `shipping_carriers_{$TS}` WHERE status='active' ORDER BY name")->fetchAll();
} catch (Exception $e) {
    $shippingCarriersNew = [];
}

// المستودعات + العملات — لمودال التأكيد
try {
    $modalWarehouses = $pdo->query("SELECT id,name FROM `{$TW}` WHERE is_active=1 ORDER BY name")->fetchAll();
} catch (Exception $e) {
    $modalWarehouses = [];
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

        // ── جلب تفاصيل فاتورة ──
        if ($act === 'get_invoice') {
            $id = (int) $_POST['id'];
            $st = $pdo->prepare("SELECT i.*, c.name AS customer_name, c.phone AS customer_phone,
                c.address AS customer_address, c.tax_number AS customer_tax_number,
                c.shipping_company AS customer_shipping_company, c.shipping_code AS customer_shipping_code,
                c.account_id AS customer_account_id,
                cur.code AS currency_code, cur.symbol AS currency_symbol,
                bcur.code AS base_currency_code, bcur.symbol AS base_currency_symbol,
                w.name AS warehouse_name
                FROM `{$TI}` i
                LEFT JOIN `{$TC}` c ON c.id=i.customer_id
                LEFT JOIN `currencies` cur ON cur.id=i.invoice_currency_id
                LEFT JOIN `currencies` bcur ON bcur.id=i.base_currency_id
                LEFT JOIN `{$TW}` w ON w.id=i.warehouse_id
                WHERE i.id=?");
            $st->execute([$id]);
            $inv = $st->fetch();
            if (!$inv)
                throw new Exception('الفاتورة غير موجودة');
            // ⚠ item_name/size/color/barcode/cost_price_base ما عادوا
            // مخزّنين على البند بعد إعادة بناء الجدول ليطابق هيكلة
            // المشتريات — JOIN حي دايماً على المنتج/المتغيّر/المقاس.
            // ⚠ لطباعة الفاتورة: s.selling_price = السعر المرجعي بالكتالوج
            // (بدون أي خصم إفرادي، كما هو بجدول المنتجات حرفياً — إعلامي
            // بس)، وs.packet_qty = عدد القطع بالباكيت (إعلامي بس كمان،
            // لا علاقة له بحساب الكمية الفعلية بالفاتورة).
            $items = $pdo->prepare("SELECT ii.*, p.name AS item_name, p.model_number,
                v.color_id, s.size, s.age_type, s.group_key, c.name AS color, s.cost_price AS cost_price_base,
                s.selling_price AS catalog_selling_price, s.packet_qty AS packet_qty,
                COALESCE(wi.quantity, 0) AS stock_qty
                FROM `{$TII}` ii
                LEFT JOIN `{$TPROD}` p ON p.id=ii.product_id
                LEFT JOIN `{$TV}` v ON v.id=ii.variant_id
                LEFT JOIN `{$TSZ}` s ON s.id=v.size_id
                LEFT JOIN `{$TCL}` c ON c.id=v.color_id
                LEFT JOIN `{$TWI}` wi ON wi.variant_id=ii.variant_id AND wi.warehouse_id=?
                WHERE ii.invoice_id=? ORDER BY ii.id");
            $items->execute([$inv['warehouse_id'], $id]);
            $inv['items'] = $items->fetchAll();

            // بيانات الفرع الكاملة — لترويسة الطباعة (اسم/عنوان/هاتف/
            // رقم ضريبي/رقم سجل صناعي وتجاري/سلوغن)
            if (!empty($_SESSION['branch_id'])) {
                $brSt = $pdo->prepare("SELECT name, address, phone, tax_number,
                    commercial_registration_number, tenant_slogan
                    FROM `branches` WHERE id=?");
                $brSt->execute([$_SESSION['branch_id']]);
                $inv['branch_info'] = $brSt->fetch() ?: null;
            }

            // ⚠ رصيد حساب ذمة العميل "قبل" هالفاتورة بالذات — لقسم كشف
            // الحساب المختصر بأسفل الطباعة. نستخدم نفس منطق اختيار
            // الحساب المعتمد بـconfirm_sale_invoice.php بالضبط (حساب
            // العميل الخاص لو موجود، وإلا العام)، ونطرح مساهمة هالفاتورة
            // نفسها من الرصيد الحالي لمعرفة شو كان قبلها (تقريب بسيط —
            // ما بياخد بالحسبان ترتيب زمني دقيق لعمليات لاحقة على نفس
            // الحساب، كافي لغرض "لمحة سريعة" على الطباعة بس).
            $accId = $inv['customer_account_id'] ?: null;
            if (!$accId) {
                $genAcc = $pdo->prepare("SELECT ac.id FROM `{$TIAS}` s JOIN `{$TAC}` ac ON ac.id=s.account_id
                    WHERE s.setting_key='customer_receivable' LIMIT 1");
                $genAcc->execute();
                $accId = $genAcc->fetchColumn() ?: null;
            }
            $inv['customer_balance_before'] = null;
            $inv['customer_balance_after'] = null;
            if ($accId) {
                $accSt = $pdo->prepare("SELECT base_balance FROM `{$TAC}` WHERE id=?");
                $accSt->execute([$accId]);
                $curBal = $accSt->fetchColumn();
                if ($curBal !== false) {
                    $inv['customer_balance_after'] = (float) $curBal;
                    $thisInvBase = (float) ($inv['final_amount_base_currency'] ?? 0);
                    $inv['customer_balance_before'] = $inv['status'] === 'confirmed'
                        ? (float) $curBal - $thisInvBase
                        : (float) $curBal; // مسودة — لسا ما أثّرت على الرصيد إطلاقاً
                }
            }

            // حساب الدفعة المقدمة — من عميل هالفاتورة بالتحديد فقط، مع
            // رصيده الحالي، حتى يُعرض كخيار إضافي بقائمة حساب التحصيل
            // بمودال التأكيد (تحكّم يدوي كامل — لا تطبيق تلقائي).
            $adv = $pdo->prepare("SELECT ac.id, ac.code, ac.name, ac.balance, ac.base_balance,
                    c.code AS cur_code, c.symbol AS cur_sym
                FROM `{$TC}` cu
                JOIN `{$TAC}` ac ON ac.id = cu.prepaid_account_id
                LEFT JOIN currencies c ON c.id = ac.currency_id
                WHERE cu.id = ?");
            $adv->execute([$inv['customer_id']]);
            $inv['advance_account'] = $adv->fetch() ?: null;

            // ⚠ القيود المرتبطة فعلياً (لو الفاتورة مؤكدة) — من
            // journal_entries_{TS} مباشرة (مصدر الحقيقة الفعلي)، مو من
            // أعمدة الفاتورة لوحدها — تفاصيل الشحن/التحصيل الجزئي/خصم
            // التعجيل كل وحدة منها قيد مستقل بذاته (راجع
            // confirm_sale_invoice.php لما نبنيه).
            $jes = $pdo->prepare("SELECT je.*, c.code AS je_currency
                FROM `{$TJE2}` je
                LEFT JOIN currencies c ON c.id = je.currency_id
                WHERE je.reference_type IN ('sale','sale_shipping','sale_payment','sale_settlement_discount')
                  AND je.reference_id = ?
                ORDER BY je.id");
            $jes->execute([$id]);
            $inv['journal_entries'] = $jes->fetchAll();

            echo json_encode(['ok' => true, 'data' => $inv]);
        }

        // سعر الصرف بين عملتين — من جدول currencies المحلي حصراً
        elseif ($act === 'get_currency_rate') {
            $from = trim($_POST['from'] ?? '');
            $to = trim($_POST['to'] ?? '');
            if (!$from || !$to)
                throw new Exception('عملتان مطلوبتان');
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
                throw new Exception('سعر صرف غير متوفر لإحدى العملتين');
            echo json_encode(['ok' => true, 'rate' => $rates[$to] / $rates[$from]]);
        }

        // ── تأكيد فاتورة ──
        // ⚠⚠ هالمسار المحلي مؤقت بس — بيخصم مخزون بدون ما يرحّل أي قيد
        // محاسبي إطلاقاً (نفس فئة الباگ الموثّقة بـREADME كـ"متصلحة"
        // بالمبيعات، بس فعلياً لسا موجودة وشغّالة هون). رح تُحذف بالكامل
        // لما نبني confirm_sale_invoice.php الجديد المطابق للمشتريات،
        // وزر "تأكيد" رح يصير يستدعيه حصرياً بدل هالمسار.
        // ⚠ إلغاء الفاتورة — لازم يمرّ حصراً عبر api/confirm_sale_invoice.php
        // (action=cancel)، مش معالج محلي هون، وإلا القيود المحاسبية
        // ما بتنعكس إطلاقاً (نفس نمط الباگ التاريخي الموثّق بالمشروع —
        // كان موجود فعلياً هون كمعالج حي، مش كود ميت، وبيرجّع المخزون
        // بس بلا أي عكس محاسبي). حذفناه بالكامل، الزر صار يستدعي الـAPI
        // مباشرة (راجع cancelInvoice() بالجافاسكربت).
        else
            throw new Exception('إجراء غير معروف');
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── بيانات الصفحة ──────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$statusF = $_GET['status'] ?? '';
$custF = (int) ($_GET['customer'] ?? 0);
$dateFrom = $_GET['from'] ?? '';
$dateTo = $_GET['to'] ?? '';

$where = 'WHERE 1=1';
$params = [];
if ($search) {
    $where .= ' AND (i.invoice_number LIKE ? OR c.name LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($statusF) {
    $where .= ' AND i.status=?';
    $params[] = $statusF;
}
if ($custF) {
    $where .= ' AND i.customer_id=?';
    $params[] = $custF;
}
if ($dateFrom) {
    $where .= ' AND i.invoice_date>=?';
    $params[] = $dateFrom;
}
if ($dateTo) {
    $where .= ' AND i.invoice_date<=?';
    $params[] = $dateTo;
}

$stmt = $pdo->prepare("SELECT i.*, c.name AS customer_name,
    cur.code AS currency_code, cur.symbol AS currency_symbol,
    COUNT(ii.id) AS items_count
    FROM `{$TI}` i
    LEFT JOIN `{$TC}` c ON c.id = i.customer_id
    LEFT JOIN `{$TII}` ii ON ii.invoice_id=i.id
    LEFT JOIN `currencies` cur ON cur.id = i.invoice_currency_id
    {$where}
    GROUP BY i.id ORDER BY i.created_at DESC LIMIT 200");
$stmt->execute($params);
$invoices = $stmt->fetchAll();

$customers = $pdo->query("SELECT id,name FROM `{$TC}` WHERE status='active' ORDER BY name")->fetchAll();

try {
    // ⚠ final_amount مخزّن بعملة كل فاتورة (invoice_currency_id)، مو
    // بعملة الفرع مباشرة — لازم نحوّله قبل الجمع. balance_amount نفس
    // الشي. final_amount_base_currency موجود مسبقاً محسوب ومخزَّن وقت
    // الحفظ، بس ما بنعتمد عليه هون لأنه مو محدَّث تلقائياً لو تغيّر
    // سعر الصرف بعد الحفظ (نادر لمسودة، ما بيصير لمؤكدة) — القسمة
    // المباشرة بتضمن دقة أكبر بكل الحالات.
    $stats = $pdo->query("SELECT
        COUNT(*) AS total,
        SUM(status='draft') AS drafts,
        SUM(status='confirmed') AS confirmed,
        COALESCE(SUM(CASE WHEN status='confirmed'
            THEN final_amount / NULLIF(exchange_rate,0) END),0) AS total_base,
        COALESCE(SUM(CASE WHEN status='confirmed' AND payment_status IN ('pending','partial')
            THEN balance_amount / NULLIF(exchange_rate,0) END),0) AS balance_base
        FROM `{$TI}`")->fetch();
} catch (Exception $e) {
    $stats = ['total' => 0, 'drafts' => 0, 'confirmed' => 0, 'total_base' => 0, 'balance_base' => 0];
}

$STATUS_MAP = [
    'draft' => ['label' => 'مسودة', 'cls' => 'bg-secondary-subtle text-secondary'],
    'confirmed' => ['label' => 'مؤكدة', 'cls' => 'bg-success-subtle text-success'],
    'cancelled' => ['label' => 'ملغاة', 'cls' => 'bg-danger-subtle text-danger'],
    'paid' => ['label' => 'مدفوعة', 'cls' => 'bg-primary-subtle text-primary'],
];
$PAY_MAP = [
    'pending' => ['label' => 'غير مدفوعة', 'cls' => 'text-danger'],
    'partial' => ['label' => 'جزئي', 'cls' => 'text-warning'],
    'paid' => ['label' => 'مدفوعة', 'cls' => 'text-success'],
];

// ⚠ تلوين السطر كامل (خلفية + لون خط) حسب حالة الفاتورة/الدفع مجتمعتين
// — طلب صريح لتمييز بصري سريع بلا حاجة قراءة عمود الحالة لحاله.
// نفس منطق ألوان $STATUS_MAP/$PAY_MAP بالضبط، بس مطبَّق على السطر كامل.
function invoiceRowStyle(string $status, string $paymentStatus): string
{
    if ($status === 'draft')
        return 'background:#f8fafc;color:#64748b';
    if ($status === 'cancelled')
        return 'background:#fef2f2;color:#dc2626';
    // status === 'confirmed' — التمييز هون حسب حالة الدفع تحديداً
    if ($paymentStatus === 'paid')
        return 'background:#f0fdf4;color:#065f46';
    if ($paymentStatus === 'partial')
        return 'background:#fffbeb;color:#92400e';
    return 'background:#eff6ff;color:#1e3a8a'; // مؤكدة وغير مدفوعة بعد
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>فواتير البيع — <?= htmlspecialchars($branchName) ?></title>
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
            background: #f8fff8
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

        .n {
            font-variant-numeric: tabular-nums
        }

        .det-row {
            display: flex;
            justify-content: space-between;
            font-size: .8rem;
            padding: 4px 0;
            border-bottom: 1px solid #f8fafc
        }

        .det-row:last-child {
            border-bottom: none
        }

        .clr-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            border: 1px solid rgba(0, 0, 0, .12);
            display: inline-block
        }

        .grp-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 20px;
            font-size: .68rem;
            padding: 2px 8px;
            font-weight: 600;
            border: 1px solid
        }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php
    require_once __DIR__ . '/../../../includes/breadcrumb.php';
    require_once __DIR__ . '/../../../includes/sidebar.php';
    ?>
    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-receipt me-1 text-success"></i>فواتير البيع</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <?= renderBreadcrumb() ?>
    </header>
    <main class="main-content">
        <div class="content-body">

            <!-- تبويبات القسم (مكوّن مشترك — يتبع الشريط الجانبي) -->
            <?php require __DIR__ . '/../../../includes/tab_bar.php'; ?>

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
                            <div class="stat-lbl">إجمالي المبيعات</div>
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
                            <div class="stat-lbl">المستحق من العملاء</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- فلاتر -->
            <div class="tbl-wrap">
                <div class="tbl-hdr">
                    <span style="font-size:.88rem;font-weight:700;color:#1e293b;white-space:nowrap">
                        <i class="bi bi-receipt me-1 text-success"></i>سجل فواتير المبيعات
                    </span>
                    <form method="get" class="d-flex gap-2 flex-wrap align-items-center ms-auto">
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                            placeholder="رقم الفاتورة أو العميل..." class="form-control form-control-sm"
                            style="width:180px;border-radius:8px">
                        <select name="status" class="form-select form-select-sm" style="width:120px;border-radius:8px"
                            onchange="this.form.submit()">
                            <option value="">كل الحالات</option>
                            <?php foreach ($STATUS_MAP as $k => $v): ?>
                                <option value="<?= $k ?>" <?= $statusF === $k ? 'selected' : '' ?>><?= $v['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="customer" class="form-select form-select-sm" style="width:160px;border-radius:8px"
                            onchange="this.form.submit()">
                            <option value="">كل العملاء</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $custF == $c['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['name']) ?>
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
                        <?php if ($search || $statusF || $custF || $dateFrom || $dateTo): ?>
                            <a href="sales_index.php" class="btn btn-sm btn-light" style="border-radius:8px">
                                <i class="bi bi-x-lg me-1"></i>مسح
                            </a>
                        <?php endif; ?>
                    </form>
                    <a href="../accounting/payments.php" class="btn btn-sm fw-600"
                        style="border-radius:9px;background:var(--section-color);color:#fff;font-size:.82rem;text-decoration:none"
                        target="_blank" title="سندات الدفع (قسم المالية)">
                        <i class="bi bi-credit-card me-1"></i>سندات الدفع
                    </a>
                    <a href="invoice_new.php" class="btn btn-sm fw-600"
                        style="border-radius:9px;background:var(--section-color);color:#fff;font-size:.82rem;text-decoration:none;white-space:nowrap"
                        target="_blank">
                        <i class="bi bi-plus-lg me-1"></i>فاتورة جديدة
                    </a>
                </div>
            </div>
            <!-- جدول الفواتير -->
            <div class="tbl-wrap">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span style="font-size:.72rem;color:#64748b">النقر على رأس العمود:</span>
                    <div class="btn-group btn-group-sm" role="group">
                        <input type="radio" class="btn-check" name="hdrMode" id="hdrModeSort" checked
                            onchange="setHeaderMode('sort')">
                        <label class="btn btn-outline-primary" for="hdrModeSort" style="font-size:.72rem">
                            <i class="bi bi-sort-down me-1"></i>ترتيب
                        </label>
                        <input type="radio" class="btn-check" name="hdrMode" id="hdrModeFilter"
                            onchange="setHeaderMode('filter')">
                        <label class="btn btn-outline-primary" for="hdrModeFilter" style="font-size:.72rem">
                            <i class="bi bi-funnel me-1"></i>فلترة
                        </label>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary" style="font-size:.7rem;display:none"
                        id="btnClearHdrFilters" onclick="clearAllHeaderFilters()">
                        <i class="bi bi-x-circle me-1"></i>مسح كل الفلاتر
                    </button>
                </div>
                <div class="table-responsive" style="position:relative">
                    <table class="mtbl" id="invTable">
                        <thead>
                            <tr>
                                <th style="color:#1e3a8a" class="sortable-th" data-col="0" data-type="text">رقم الفاتورة
                                </th>
                                <th class="sortable-th" data-col="1" data-type="date">التاريخ</th>
                                <th style="color:#1e3a8a" class="sortable-th" data-col="2" data-type="text">العميل</th>
                                <th class="sortable-th" data-col="3" data-type="num">البنود</th>
                                <th class="sortable-th" data-col="4" data-type="text">العملة</th>
                                <th class="sortable-th" data-col="5" data-type="num">الإجمالي</th>
                                <th class="sortable-th" data-col="6" data-type="num">بعملة الفرع
                                    (<?= htmlspecialchars($baseCurrencySymbol) ?>)</th>
                                <th class="sortable-th" data-col="7" data-type="num">المدفوع</th>
                                <th class="sortable-th" data-col="8" data-type="num">المتبقي</th>
                                <th class="sortable-th" data-col="9" data-type="text">الدفع</th>
                                <th class="sortable-th" data-col="10" data-type="text">الحالة</th>
                                <th style="text-align:center">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($invoices)): ?>
                                <tr>
                                    <td colspan="12" class="text-center text-muted py-5">
                                        <i class="bi bi-receipt d-block mb-2" style="font-size:2rem;opacity:.2"></i>
                                        لا توجد فواتير<?= $search ? " تطابق \"{$search}\"" : '' ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($invoices as $inv):
                                $st = $STATUS_MAP[$inv['status']] ?? $STATUS_MAP['draft'];
                                $pay = $PAY_MAP[$inv['payment_status']] ?? $PAY_MAP['pending'];
                                $sym = $pur['currency_symbol'] ?? '$';
                                ?>
                                <tr style="<?= invoiceRowStyle($inv['status'], $inv['payment_status']) ?>">
                                    <td class="n fw-600" style="direction:rtl"
                                        data-sort="<?= htmlspecialchars($inv['invoice_number']) ?>">
                                        <a href="javascript:void(0)" onclick="viewInvoice(<?= $inv['id'] ?>)"
                                            style="color:#1e3a8a;text-decoration:none;cursor:pointer">
                                            <?= htmlspecialchars($inv['invoice_number']) ?>
                                        </a>
                                    </td>
                                    <?php $invDateTime = !empty($inv['created_at']) ? substr($inv['created_at'], 0, 16) : $inv['invoice_date']; ?>
                                    <td class="text-muted" style="direction:ltr"
                                        data-sort="<?= htmlspecialchars($inv['created_at'] ?? $inv['invoice_date']) ?>">
                                        <?= htmlspecialchars($invDateTime) ?>
                                    </td>
                                    <td data-sort="<?= htmlspecialchars($inv['customer_name'] ?? '') ?>">
                                        <div class="fw-600" style="font-size:.83rem">
                                            <?= htmlspecialchars($inv['customer_name'] ?? '—') ?>
                                        </div>
                                    </td>
                                    <td class="text-center" data-sort="<?= (int) $inv['items_count'] ?>">
                                        <span class="badge bg-secondary-subtle text-secondary"><?= $inv['items_count'] ?>
                                            بند</span>
                                    </td>
                                    <td data-sort="<?= htmlspecialchars($inv['currency_code'] ?: '') ?>">
                                        <span class="badge bg-info-subtle text-info" style="font-size:.72rem" dir="ltr">
                                            <?= htmlspecialchars($inv['currency_code'] ?: '—') ?>
                                        </span>
                                    </td>
                                    <td class="n fw-600" data-sort="<?= (float) $inv['final_amount'] ?>">
                                        <?= number_format($inv['final_amount'], 2) ?>
                                        <?= htmlspecialchars($inv['currency_symbol'] ?? '') ?>
                                    </td>
                                    <td class="n" style="font-size:.78rem"
                                        data-sort="<?= (float) $inv['final_amount'] / (float) ($inv['exchange_rate'] ?: 1) ?>">
                                        <?= number_format((float) $inv['final_amount'] / (float) ($inv['exchange_rate'] ?: 1), 2) ?>
                                        <?= htmlspecialchars($baseCurrencySymbol) ?>
                                    </td>
                                    <td class="n text-success" data-sort="<?= (float) ($inv['paid_amount'] ?? 0) ?>">
                                        <?= number_format($inv['paid_amount'] ?? 0, 2) ?>
                                        <?= htmlspecialchars($inv['currency_symbol'] ?? '') ?>
                                    </td>
                                    <td class="n <?= ($inv['balance_amount'] ?? 0) > 0 ? 'text-danger fw-600' : '' ?>"
                                        data-sort="<?= (float) ($inv['balance_amount'] ?? 0) ?>">
                                        <?= number_format($inv['balance_amount'] ?? 0, 2) ?>
                                        <?= htmlspecialchars($inv['currency_symbol'] ?? '') ?>
                                    </td>
                                    <td data-sort="<?= htmlspecialchars($pay['label']) ?>">
                                        <span class="<?= $pay['cls'] ?>"
                                            style="font-size:.78rem;font-weight:600"><?= $pay['label'] ?></span>
                                    </td>
                                    <td data-sort="<?= htmlspecialchars($st['label']) ?>"><span
                                            class="badge <?= $st['cls'] ?>"
                                            style="font-size:.68rem"><?= $st['label'] ?></span></td>
                                    <td>
                                        <div class="d-flex gap-1 justify-content-center">
                                            <button class="act-btn info-h" onclick="viewInvoice(<?= $inv['id'] ?>)"
                                                title="عرض">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <?php if ($inv['status'] === 'draft'): ?>
                                                <a href="invoice_edit.php?id=<?= $inv['id'] ?>" class="act-btn" title="تعديل">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <button class="act-btn success-h"
                                                    onclick="confirmInvoice(<?= $inv['id'] ?>,'<?= htmlspecialchars($inv['invoice_number'], ENT_QUOTES) ?>')"
                                                    title="تأكيد الفاتورة">
                                                    <i class="bi bi-check-circle"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($inv['status'] === 'confirmed'): ?>
                                                <button class="act-btn" onclick="viewInvoice(<?= $inv['id'] ?>)"
                                                    title="تسجيل دفعة" style="color:#8b5cf6;border-color:#c4b5fd">
                                                    <i class="bi bi-cash-coin"></i>
                                                </button>
                                                <a href="returns.php?open_invoice_id=<?= $inv['id'] ?>" class="act-btn"
                                                    title="إنشاء مرتجع" style="color:#d97706;border-color:#fde68a">
                                                    <i class="bi bi-arrow-return-right"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($inv['status'] !== 'cancelled'): ?>
                                                <button class="act-btn danger"
                                                    onclick="cancelInvoice(<?= $inv['id'] ?>,'<?= htmlspecialchars($inv['invoice_number'], ENT_QUOTES) ?>')"
                                                    title="إلغاء">
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
        </div>
    </main>

    <!-- مودال عرض الفاتورة -->
    <div class="modal fade" id="viewModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#065f46,#16a34a);border-radius:16px 16px 0 0">
                    <div>
                        <h6 class="modal-title text-white fw-700 mb-0" id="vTitle">تفاصيل الفاتورة</h6>
                        <div id="vSub" style="font-size:.75rem;color:rgba(255,255,255,.7);margin-top:2px"></div>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <button type="button" onclick="printSaleInvoice()"
                            style="border-radius:8px;background:rgba(255,255,255,.15);color:#fff;font-size:.76rem;border:1px solid rgba(255,255,255,.3);padding:4px 10px">
                            <i class="bi bi-printer me-1"></i>طباعة
                        </button>
                        <a id="vEditBtn" href="#"
                            style="display:none;border-radius:8px;background:rgba(255,255,255,.15);color:#fff;font-size:.76rem;border:1px solid rgba(255,255,255,.3);padding:4px 10px;text-decoration:none">
                            <i class="bi bi-pencil me-1"></i>تعديل
                        </a>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                </div>
                <div class="modal-body px-4 py-3" id="vBody">
                    <div class="text-center py-4"><span class="spinner-border text-success"></span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- مودال تأكيد الفاتورة -->
    <div class="modal fade" id="confirmModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#065f46,#16a34a);border-radius:16px 16px 0 0">
                    <div>
                        <h6 class="modal-title text-white fw-700 mb-0">
                            <i class="bi bi-check-circle me-2"></i>تأكيد فاتورة البيع
                        </h6>
                        <div id="cInvNo" style="font-size:.78rem;color:rgba(255,255,255,.8);margin-top:2px"></div>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <button class="btn btn-sm" style="background:rgba(255,255,255,.2);color:#fff;border-radius:8px"
                            onclick="printSaleInvoice()" title="طباعة الفاتورة">
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
                            <div
                                style="background:#f8fafc;border-radius:10px;border:20px solid #e2e8f0;overflow:hidden">
                                <div
                                    style="padding:8px 14px;background:#f1f5f9;font-size:.8rem;font-weight:700;color:#1e293b;border-bottom:1px solid #e2e8f0">
                                    <i class="bi bi-list-ul me-1 text-success"></i>بنود الفاتورة
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
                                                    رقم الموديل</th>
                                                <th
                                                    style="padding:6px 10px;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0;width:70px">
                                                    القياس</th>
                                                <th
                                                    style="padding:6px 10px;color:#16a34a;font-weight:600;border-bottom:1px solid #e2e8f0">
                                                    اللون</th>
                                                <th
                                                    style="padding:6px 10px;color:#7c3aed;font-weight:600;border-bottom:1px solid #e2e8f0;width:60px">
                                                    المخزون</th>
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

                        <!-- ── ملخص المبالغ ── -->
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
                                        <div class="fw-600" style="color:#0891b2" id="cSumExRate">—</div>
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <div class="text-muted" style="font-size:.7rem">المبلغ الإجمالي النهائي</div>
                                        <div class="fw-700" style="color:#065f46;font-size:.95rem" id="cSumFinal">—
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ── بانر خصم تعجيل الدفع (إعلامي بس — بلا زر تطبيق،
                        بما إنه التحصيل صار خارج مودال التأكيد) ── -->
                        <div class="col-12" id="cSettleDiscBanner" style="display:none">
                            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:10px 14px;font-size:.8rem"
                                class="d-flex align-items-center flex-wrap gap-2">
                                <i class="bi bi-lightning-charge-fill" style="color:#16a34a"></i>
                                <span id="cSettleDiscText" class="text-success fw-600"></span>
                            </div>
                        </div>

                        <!-- ── النقلية والشحن والجمارك (ربط توثيقي بس — بلا أي
                        مبلغ/قيد محاسبي هون؛ تكلفة النقل/الشحن تُسجَّل
                        كمصروف منفصل بصفحة المصاريف والمستهلكات) ── -->
                        <div class="col-12">
                            <label class="form-label small fw-600 text-secondary mb-1">
                                <i class="bi bi-truck me-1 text-warning"></i>ربط النقلية والشحن (اختياري)
                            </label>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small text-muted mb-1">شركة النقلية</label>
                                    <select id="cTransportCarrier" class="form-select form-select-sm">
                                        <option value="">— بلا نقلية —</option>
                                        <?php foreach ($transportCarriers as $tc): ?>
                                            <option value="<?= $tc['id'] ?>">
                                                <?= htmlspecialchars($tc['name']) ?>
                                                <?php if ($tc['contact_person']): ?>(<?= htmlspecialchars($tc['contact_person']) ?>)<?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small text-muted mb-1">شركة الشحن</label>
                                    <select id="cShippingCarrierNew" class="form-select form-select-sm">
                                        <option value="">— بلا شركة شحن —</option>
                                        <?php foreach ($shippingCarriersNew as $sc): ?>
                                            <option value="<?= $sc['id'] ?>">
                                                <?= htmlspecialchars($sc['name']) ?>
                                                <?php if ($sc['contact_person']): ?>(<?= htmlspecialchars($sc['contact_person']) ?>)<?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="cHasCustoms"
                                            onchange="document.getElementById('customsCostWrap').style.display=this.checked?'':'none';updateTotal()">
                                        <label class="form-check-label small fw-600" for="cHasCustoms">
                                            <i class="bi bi-file-earmark-text me-1 text-danger"></i>يوجد جمارك؟
                                        </label>
                                    </div>
                                    <div id="customsCostWrap" style="display:none" class="mt-1">
                                        <input type="number" id="cCustomsCost" class="form-control form-control-sm"
                                            min="0" step="0.01" placeholder="أجور الجمارك" oninput="updateTotal()">
                                        <div class="form-text" style="font-size:.68rem">
                                            بيانات فقط — ما بتُضاف لإجمالي الفاتورة، وما بترحّل أي قيد تلقائي
                                            (تُسوَّى لاحقاً من صفحات المقبوضات/المدفوعات العامة)
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ── تاريخ التسليم + مستودع ── -->
                        <div class="col-md-4">
                            <label class="form-label small fw-600 text-secondary mb-1">
                                <i class="bi bi-calendar-check me-1 text-success"></i>تاريخ التسليم
                            </label>
                            <input type="date" id="cReceiveDate" class="form-control form-control-sm"
                                value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-600 text-secondary mb-1">
                                <i class="bi bi-building me-1 text-success"></i>مستودع الصرف
                            </label>
                            <div id="cWarehouseDisplay"
                                style="background:#f1f5f9;border-radius:8px;padding:6px 10px;font-size:.82rem;color:#1e293b;font-weight:600">
                                <i class="bi bi-building me-1 text-success"></i><span id="cWarehouseName">جارٍ
                                    التحميل...</span>
                            </div>
                            <input type="hidden" id="cWarehouse">
                        </div>

                        <!-- صورة الفاتورة -->
                        <div class="col-md-6">
                            <label class="form-label small fw-600 text-secondary mb-1">
                                <i class="bi bi-image me-1 text-info"></i>صورة الفاتورة / إيصال التسليم
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
                                <i class="bi bi-chat-left-text me-1"></i>ملاحظات التسليم
                            </label>
                            <textarea id="cNotes" class="form-control form-control-sm" rows="2"
                                placeholder="حالة التسليم، ملاحظات خاصة..."></textarea>
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

                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4 gap-2">
                    <button class="btn btn-sm btn-light" style="border-radius:8px"
                        data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-sm fw-600"
                        style="border-radius:8px;background:#16a34a;color:#fff;min-width:140px" onclick="doConfirm()"
                        id="btnConfirm">
                        <span id="confirmTxt"><i class="bi bi-check-circle me-1"></i>تأكيد الفاتورة</span>
                        <span id="confirmSpin" class="spinner-border spinner-border-sm" style="display:none"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= BASE_PATH ?>/assets/js/sidebar.js"></script>
    <script>
        const sb = document.getElementById('sidebar'), ov = document.getElementById('sbOverlay');
        function sbOpen() { sb.classList.add('open'); ov.classList.add('show'); }
        function sbClose() { sb.classList.remove('open'); ov.classList.remove('show'); }

        // ══════════════════════════════════════════════════════════
        // ترتيب/فلترة جدول الفواتير بالنقر على رأس العمود — وضعان
        // قابلان للتبديل (نسختين بنفس الصفحة، بلا تكرار الملف).
        // ══════════════════════════════════════════════════════════
        let _hdrMode = 'sort';
        let _sortCol = null, _sortDir = 1;
        const _activeFilters = {}; // { colIndex: Set(قيم مسموحة) }

        function setHeaderMode(mode) {
            _hdrMode = mode;
            document.querySelectorAll('.hdr-filter-pop').forEach(p => p.remove());
        }

        (function initInvoiceTableHeaders() {
            const table = document.getElementById('invTable');
            if (!table) return;
            table.querySelectorAll('th.sortable-th').forEach(th => {
                th.style.cursor = 'pointer';
                th.addEventListener('click', (e) => {
                    if (_hdrMode === 'sort') sortByColumn(th);
                    else { e.stopPropagation(); openFilterPopover(th); }
                });
            });
        })();

        function sortByColumn(th) {
            const col = parseInt(th.dataset.col);
            const type = th.dataset.type;
            if (_sortCol === col) _sortDir *= -1; else { _sortCol = col; _sortDir = 1; }

            // مؤشر السهم على العمود النشط بس
            document.querySelectorAll('#invTable th.sortable-th').forEach(h => h.innerHTML = h.innerHTML.replace(/\s*[▲▼]$/, ''));
            th.innerHTML += _sortDir === 1 ? ' ▲' : ' ▼';

            const tbody = document.querySelector('#invTable tbody');
            const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.cells.length > 1);
            rows.sort((a, b) => {
                let va = a.cells[col]?.dataset.sort ?? '', vb = b.cells[col]?.dataset.sort ?? '';
                if (type === 'num') { va = parseFloat(va) || 0; vb = parseFloat(vb) || 0; return (va - vb) * _sortDir; }
                if (type === 'date') return (new Date(va) - new Date(vb)) * _sortDir;
                return va.localeCompare(vb, 'ar') * _sortDir;
            });
            rows.forEach(r => tbody.appendChild(r));
        }

        function openFilterPopover(th) {
            document.querySelectorAll('.hdr-filter-pop').forEach(p => p.remove());
            const col = parseInt(th.dataset.col);
            const tbody = document.querySelector('#invTable tbody');
            const allRows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.cells.length > 1);
            const values = [...new Set(allRows.map(r => (r.cells[col]?.dataset.sort ?? '').trim()).filter(v => v !== ''))]
                .sort((a, b) => a.localeCompare(b, 'ar'));

            const selected = _activeFilters[col] || new Set(values);
            const pop = document.createElement('div');
            pop.className = 'hdr-filter-pop';
            pop.style.cssText = 'position:fixed;z-index:2000;background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.15);padding:8px;max-height:260px;overflow-y:auto;min-width:170px;font-size:.75rem';
            pop.innerHTML = `
                <div class="d-flex justify-content-between mb-1">
                    <button class="btn btn-link btn-sm p-0" style="font-size:.7rem" onclick="_toggleAllFilter(${col},true)">تحديد الكل</button>
                    <button class="btn btn-link btn-sm p-0" style="font-size:.7rem" onclick="_toggleAllFilter(${col},false)">إلغاء الكل</button>
                </div>
                ${values.map(v => `
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input filter-chk" data-val="${v.replace(/"/g, '&quot;')}"
                            ${selected.has(v) ? 'checked' : ''}>
                        <label class="form-check-label" style="cursor:pointer">${v}</label>
                    </div>`).join('')}
                <div class="d-flex gap-1 mt-2">
                    <button class="btn btn-sm btn-primary flex-fill" style="font-size:.7rem" onclick="_applyFilter(${col})">تطبيق</button>
                    <button class="btn btn-sm btn-light" style="font-size:.7rem" onclick="document.querySelectorAll('.hdr-filter-pop').forEach(p=>p.remove())">إغلاق</button>
                </div>`;
            pop._values = values;
            // ⚠ يُرسم على مستوى الصفحة (body) مباشرة، بلا أي علاقة أب/ابن
            // مع <th> — عنصر .table-responsive عنده overflow بيقص أي
            // عنصر position:absolute جواه لو تجاوز حدود التمرير، فكان
            // البوب-أب يظهر مقصوص/مخفي بالكامل. position:fixed +
            // getBoundingClientRect() يتفادى هالمشكلة كلياً.
            const rect = th.getBoundingClientRect();
            document.body.appendChild(pop);
            let left = rect.left;
            if (left + pop.offsetWidth > window.innerWidth - 10) left = window.innerWidth - pop.offsetWidth - 10;
            pop.style.top = (rect.bottom + 4) + 'px';
            pop.style.left = Math.max(10, left) + 'px';
            pop.addEventListener('click', e => e.stopPropagation());
        }

        function _toggleAllFilter(col, checked) {
            document.querySelectorAll('.hdr-filter-pop .filter-chk').forEach(c => c.checked = checked);
        }

        function _applyFilter(col) {
            const pop = document.querySelector('.hdr-filter-pop');
            const checked = new Set(Array.from(pop.querySelectorAll('.filter-chk:checked')).map(c => c.dataset.val));
            if (checked.size === pop._values.length) delete _activeFilters[col];
            else _activeFilters[col] = checked;
            pop.remove();
            _renderFilters();
        }

        function _renderFilters() {
            const tbody = document.querySelector('#invTable tbody');
            const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.cells.length > 1);
            const hasFilters = Object.keys(_activeFilters).length > 0;
            document.getElementById('btnClearHdrFilters').style.display = hasFilters ? '' : 'none';
            rows.forEach(r => {
                let visible = true;
                for (const col in _activeFilters) {
                    const val = (r.cells[col]?.dataset.sort ?? '').trim();
                    if (!_activeFilters[col].has(val)) { visible = false; break; }
                }
                r.style.display = visible ? '' : 'none';
            });
            // تلوين رؤوس الأعمدة المفلترة فعلياً
            document.querySelectorAll('#invTable th.sortable-th').forEach(h => {
                const c = h.dataset.col;
                h.style.background = _activeFilters[c] ? '#fef3c7' : '';
            });
        }

        function clearAllHeaderFilters() {
            Object.keys(_activeFilters).forEach(k => delete _activeFilters[k]);
            _renderFilters();
        }

        document.addEventListener('click', () => document.querySelectorAll('.hdr-filter-pop').forEach(p => p.remove()));
        window.addEventListener('resize', () => { if (window.innerWidth > 991) sbClose(); });
        function toggleGroup(g) {
            const o = g.classList.contains('open');
            document.querySelectorAll('.sb-group.open').forEach(x => x.classList.remove('open'));
            g.classList.toggle('open', !o);
            localStorage.setItem('sb_open_' + g.dataset.key, (!o).toString());
        }
        document.querySelectorAll('.sb-group').forEach(g => {
            if (localStorage.getItem('sb_open_' + g.dataset.key) === 'true') g.classList.add('open');
        });

        const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
        const confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
        const STATUS_MAP = <?= json_encode($STATUS_MAP) ?>;
        const PAY_MAP = <?= json_encode($PAY_MAP) ?>;
        const GRP_COLORS = [['#eff6ff', '#1e3a8a', '#bfdbfe'], ['#f0fdf4', '#065f46', '#bbf7d0'], ['#fff7ed', '#7c2d12', '#fed7aa'], ['#f5f3ff', '#4c1d95', '#ddd6fe']];

        function post(data) {
            const fd = new FormData();
            Object.entries(data).forEach(([k, v]) => fd.append(k, v ?? ''));
            return fetch(location.href, { method: 'POST', body: fd }).then(r => r.json());
        }
        function toast(msg, type = 'success') {
            const t = document.createElement('div');
            t.className = `alert alert-${type} shadow`;
            t.style.cssText = 'position:fixed;top:70px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:240px;text-align:center;font-size:.83rem;padding:.5rem 1.2rem';
            t.innerHTML = `<i class="bi bi-${type === 'success' ? 'check-circle-fill text-success' : 'exclamation-triangle-fill text-danger'} me-2"></i>${msg}`;
            document.body.appendChild(t); setTimeout(() => t.remove(), 3200);
        }

        // ── عرض الفاتورة ──
        function viewInvoice(id) {
            document.getElementById('vTitle').textContent = 'جارٍ التحميل...';
            document.getElementById('vSub').textContent = '';
            document.getElementById('vEditBtn').style.display = 'none';
            document.getElementById('vBody').innerHTML = '<div class="text-center py-4"><span class="spinner-border text-success"></span></div>';
            viewModal.show();
            post({ _action: 'get_invoice', id }).then(d => {
                if (!d.ok) { document.getElementById('vBody').innerHTML = `<div class="text-danger p-3">${d.msg}</div>`; return; }
                const inv = d.data;
                _cInvoiceData = inv; // مشترك مع مودال التأكيد — تلزم لـprintSaleInvoice()
                const st = STATUS_MAP[inv.status] || STATUS_MAP['draft'];
                const pay = PAY_MAP[inv.payment_status] || PAY_MAP['pending'];
                document.getElementById('vTitle').textContent = 'فاتورة: ' + inv.invoice_number;
                document.getElementById('vSub').textContent = inv.customer_name || '';
                if (inv.status === 'draft') {
                    const eb = document.getElementById('vEditBtn');
                    eb.href = `invoice_edit.php?id=${inv.id}`;
                    eb.style.display = '';
                }

                // تجميع البنود بكروبات
                // ⚠ كل مقاس (صف/متغيّر) بنفس اللون مخزّن بنفس qty وسعر
                // القطعة بالضبط (كروب واحد = قطعة من كل مقاس، والسعر مو
                // مقسوم) — فمجموع total_price عبر المقاسات بيضخّم الرقم
                // غلط. الإجمالي الصحيح = qty × سعر القطعة × عدد المقاسات
                // الفعلي، محسوب بعد ما نعرف عدد المقاسات كامل. cost_total
                // استثناء: بتختلف فعلياً حسب تكلفة كل مقاس بذاته، فتضل تُجمع.
                const grpMap = {};
                (inv.items || []).forEach(it => {
                    // ⚠ قرار مُراجَع: الألوان تنفصل بصف مستقل من جديد
                    // (مو مدموجة) — group_key لسا مصدر الحقيقة الوحيد
                    // لدمج المقاسات المختلفة بنفس اللون، بس اللون نفسه
                    // صار جزء من مفتاح الصف.
                    const k = `${it.product_id || it.item_name}_${it.group_key}_${it.color_id || it.color || ''}`;
                    if (!grpMap[k]) grpMap[k] = {
                        item_name: it.item_name, model_number: it.model_number || '—',
                        unit_price: parseFloat(it.unit_price), color: it.color || '—', stock: it.stock_qty != null ? it.stock_qty : '—',
                        qty: parseFloat(it.quantity), total: 0,
                        sizes: [], cost_total: 0
                    };
                    grpMap[k].cost_total += (parseFloat(it.cost_price_base || 0) * parseFloat(it.quantity));
                    if (it.size && !grpMap[k].sizes.includes(it.size)) grpMap[k].sizes.push(it.size);
                });
                Object.values(grpMap).forEach(g => { g.total = g.qty * g.unit_price * (g.sizes.length || 1); });
                const grpRows = Object.values(grpMap).sort((a, b) => a.unit_price - b.unit_price);
                const priceList = [...new Set(grpRows.map(g => g.unit_price))];

                // ⚠ عملة الفاتورة (سعر الوحدة/الإجمالي) مختلفة فعلياً عن
                // عملة الفرع (سعر التكلفة، مخزّن دايماً بعملة الفرع
                // الأساسية بغض النظر عن عملة الفاتورة نفسها) — لازم
                // نعرض رمزين منفصلين، مو نفس الرمز على الاثنين.
                const symDoc = inv.currency_symbol || '$';
                const symBase = inv.base_currency_symbol || symDoc;

                const itemsHtml = grpRows.map(g => {
                    const pi = priceList.indexOf(g.unit_price);
                    const [bg, clr, br] = GRP_COLORS[pi % 4];
                    const badge = `<span style="background:${bg};color:${clr};border:1px solid ${br};border-radius:12px;font-size:.68rem;padding:2px 8px;font-weight:600">كروب ${pi + 1}</span>`;
                    return `<tr>
                <td><div class="fw-600" style="font-size:.8rem">${g.item_name}</div></td>
                <td style="font-size:.73rem;color:#64748b" dir="ltr">${g.model_number}</td>
                <td>${badge}<div style="font-size:.72rem;font-weight:600;color:#334155;margin-top:2px">${g.sizes.join(' · ')}</div></td>
                <td class="text-center" style="font-size:.73rem;color:#16a34a">${g.color}</td>
                <td class="n text-center" style="color:#7c3aed;font-size:.73rem" dir="ltr">${g.stock}</td>
                <td class="n text-center fw-600">${g.qty.toFixed(0)}</td>
                <td class="n text-center">${symDoc} ${g.unit_price.toFixed(4)}</td>
                <td class="n text-end fw-600">${symDoc} ${g.total.toFixed(2)}</td>
                <td class="n text-center" style="color:#94a3b8;font-size:.72rem">${symBase} ${g.cost_total.toFixed(2)}</td>
            </tr>`;
                }).join('');

                // ⚠ الربح التقديري: final_amount بعملة الفاتورة، بينما
                // تكلفة البضاعة (cost_price_base) بعملة الفرع دايماً —
                // ما فيك تطرحهم مباشرة لو العملتين مختلفتين (كان الكود
                // القديم عم يعمل هيك، غلط رياضياً). الصح: نحوّل الإجمالي
                // لعملة الفرع أول (÷ exchange_rate)، وبعدين نطرح — الربح
                // بيطلع بعملة الفرع الأساسية دايماً، مش بعملة الفاتورة.
                const exRateInv = parseFloat(inv.exchange_rate) || 1;
                const totalCostBase = (inv.items || []).reduce((s, it) => s + (parseFloat(it.cost_price_base || 0) * parseFloat(it.quantity)), 0);
                const profitBase = (parseFloat(inv.final_amount) / exRateInv) - totalCostBase;

                document.getElementById('vBody').innerHTML = `
        <div class="row g-2 mb-3">
            <div class="col-md-3"><small style="color:#64748b">العميل</small><div class="fw-600">${inv.customer_name || '—'}</div></div>
            <div class="col-md-2"><small style="color:#64748b">التاريخ</small><div>${inv.invoice_date}</div></div>
            <div class="col-md-2"><small style="color:#64748b">الاستحقاق</small><div>${inv.due_date || '—'}</div></div>
            <div class="col-md-2"><small style="color:#64748b">الحالة</small>
                <div><span class="badge ${st.cls}">${st.label}</span></div></div>
            <div class="col-md-3"><small style="color:#64748b">الدفع</small>
                <div class="${pay.cls} fw-600" style="font-size:.83rem">${pay.label}</div></div>
        </div>
        <div class="table-responsive mb-3">
        <table class="mtbl" style="font-size:.78rem">
            <thead><tr style="background:#f8fafc">
                <th>المنتج</th><th>رقم الموديل</th>
                <th style="width:70px">الكروب/القياسات</th>
                <th class="text-center">اللون</th>
                <th class="text-center" style="color:#7c3aed;width:60px">المخزون</th>
                <th class="text-center">عدد الكروبات</th>
                <th class="text-center">سعر الوحدة (${symDoc})</th>
                <th class="text-end">الإجمالي (${symDoc})</th>
                <th class="text-center" style="color:#94a3b8">التكلفة (${symBase})</th>
            </tr></thead>
            <tbody>${itemsHtml}</tbody>
        </table>
        </div>
        <div class="row justify-content-end">
          <div class="col-md-4">
            <div style="background:#f8fafc;border-radius:10px;padding:10px 14px">
                ${parseFloat(inv.discount_amount) > 0 ? `<div class="det-row"><span style="color:#64748b">خصم</span><span class="n text-danger">-${symDoc} ${parseFloat(inv.discount_amount).toFixed(2)}</span></div>` : ''}
                ${parseFloat(inv.tax_amount) > 0 ? `<div class="det-row"><span style="color:#64748b">ضريبة</span><span class="n">+${symDoc} ${parseFloat(inv.tax_amount).toFixed(2)}</span></div>` : ''}
                <div class="det-row" style="font-weight:700;font-size:.9rem;border-top:1px solid #e2e8f0;padding-top:6px">
                    <span>الإجمالي</span><span class="n text-success">${symDoc} ${parseFloat(inv.final_amount).toFixed(2)}</span>
                </div>
                <div class="det-row"><span style="color:#16a34a">المدفوع</span>
                    <span class="n text-success">${symDoc} ${parseFloat(inv.paid_amount || 0).toFixed(2)}</span></div>
                <div class="det-row"><span style="color:#dc2626">المتبقي</span>
                    <span class="n text-danger">${symDoc} ${parseFloat(inv.balance_amount || 0).toFixed(2)}</span></div>
                <div class="det-row" style="border-top:1px solid #e2e8f0;padding-top:6px;margin-top:4px">
                    <span style="color:#7c3aed">الربح التقديري <small style="color:#94a3b8">(عملة الفرع)</small></span>
                    <span class="n" style="color:#7c3aed;font-weight:600">${symBase} ${profitBase.toFixed(2)}</span>
                </div>
            </div>
          </div>
        </div>
        ${inv.notes ? `<div style="background:#f8fafc;border-radius:8px;padding:8px 12px;margin-top:10px;font-size:.78rem;color:#64748b">${inv.notes}</div>` : ''}`;
            });
        }

        // ── تأكيد ──
        let _cId = 0, _cTotal = 0, _cCurSym = '$', _cInvoiceData = null;

        function float(v) { return parseFloat(v) || 0; }

        function confirmInvoice(id, no) {
            // الإجمالي والرمز بيترجعوا الصح من داخل openConfirmModal نفسها
            // (بعد ما تجيب بيانات الفاتورة) — هون بس نفتح ونبلش التحميل.
            viewModal.hide();
            openConfirmModal(id, no, 0, '$');
        }

        function openConfirmModal(id, no, total, sym) {
            _cId = id; _cTotal = parseFloat(total) || 0; _cCurSym = sym || '$';
            document.getElementById('cId').value = id;
            document.getElementById('cInvNo').textContent = 'فاتورة: ' + no;
            document.getElementById('cCurSym').value = sym;
            document.getElementById('cInvTotal').value = total;
            document.getElementById('cReceiveDate').value = '<?= date('Y-m-d') ?>';
            document.getElementById('cTransportCarrier').value = '';
            document.getElementById('cShippingCarrierNew').value = '';
            document.getElementById('cHasCustoms').checked = false;
            document.getElementById('cCustomsCost').value = '';
            document.getElementById('customsCostWrap').style.display = 'none';
            document.getElementById('customsCostWrap').style.display = 'none';
            document.getElementById('cNotes').value = '';
            document.getElementById('cWarehouse').value = '';
            document.getElementById('cItemsBody').innerHTML = '<tr><td colspan="9" class="text-center p-3"><span class="spinner-border spinner-border-sm"></span></td></tr>';
            clearImg();
            confirmModal.show();
            // جلب بنود الفاتورة الحقيقية
            post({ _action: 'get_invoice', id }).then(d => {
                if (!d.ok) { document.getElementById('cItemsBody').innerHTML = '<tr><td colspan="9" class="text-center text-danger p-2">خطأ: ' + d.msg + '</td></tr>'; return; }
                const p = d.data;
                _cInvoiceData = p;

                const sym2 = p.currency_symbol || '$';
                const cur = p.currency_code || 'USD';
                const fmt = n => new Intl.NumberFormat('en').format(parseFloat(n || 0).toFixed(2));

                document.getElementById('cWarehouseName').textContent = p.warehouse_name || 'المستودع الرئيسي';
                document.getElementById('cWarehouse').value = p.warehouse_id || '';

                // بنود مجمَّعة حسب الكروب (منتج × group_key) — الألوان
                // المختلفة بنفس الكروب تندمج بصف واحد (قرار جديد: مو
                // مفصولة بعد الآن). group_key مصدر الحقيقة الوحيد للتجميع.
                const groups = {};
                (p.items || []).forEach(it => {
                    // ⚠ قرار مُراجَع: الألوان تنفصل بصف مستقل من جديد.
                    const key = (it.product_id || it.item_name || it.id) + '_' + it.group_key + '_' + (it.color_id || it.color || '');
                    if (!groups[key]) groups[key] = {
                        name: it.item_name || ('بند #' + it.id), model_number: it.model_number || '—',
                        sizes: [], color: it.color || '—', stock: it.stock_qty != null ? it.stock_qty : '—',
                        qty: parseFloat(it.quantity), unit: parseFloat(it.unit_price), total: 0
                    };
                    if (it.size && !groups[key].sizes.includes(it.size)) groups[key].sizes.push(it.size);
                });
                Object.values(groups).forEach(g => { g.total = g.qty * g.unit * (g.sizes.length || 1); });
                let rows = '', i = 1;
                Object.values(groups).forEach(g => {
                    rows += `<tr style="border-bottom:1px solid #f1f5f9">
                <td style="padding:5px 10px;color:#94a3b8">${i++}</td>
                <td style="padding:5px 10px;font-weight:600">${g.name}</td>
                <td style="padding:5px 10px;text-align:center;font-size:.73rem;color:#64748b" dir="ltr">${g.model_number}</td>
                <td style="padding:5px 10px;text-align:center;font-size:.73rem">${g.sizes.join(' · ') || '—'}</td>
                <td style="padding:5px 10px;text-align:center;color:#16a34a;font-size:.73rem">${g.color}</td>
                <td style="padding:5px 10px;text-align:center;color:#7c3aed;font-size:.73rem" dir="ltr">${g.stock}</td>
                <td style="padding:5px 10px;text-align:center">${g.qty}</td>
                <td style="padding:5px 10px;text-align:left;direction:ltr">${sym2} ${fmt(g.unit)}</td>
                <td style="padding:5px 10px;text-align:left;direction:ltr;font-weight:600">${sym2} ${fmt(g.total)}</td>
            </tr>`;
                });
                document.getElementById('cItemsBody').innerHTML = rows || '<tr><td colspan="9" class="text-center text-muted p-2">لا توجد بنود</td></tr>';

                // ملخص المبالغ
                document.getElementById('cSumProducts').textContent = sym2 + ' ' + fmt(p.total_amount);
                document.getElementById('cSumDiscount').textContent = (parseFloat(p.discount_amount || 0) / parseFloat(p.total_amount || 1) * 100).toFixed(2) + '%';
                document.getElementById('cSumDiscountAmt').textContent = '- ' + sym2 + ' ' + fmt(p.discount_amount);
                document.getElementById('cSumTax').textContent = sym2 + ' ' + fmt(p.tax_amount);
                document.getElementById('cSumFinal').textContent = sym2 + ' ' + fmt(p.final_amount);
                const exRate = parseFloat(p.exchange_rate) || 1;
                document.getElementById('cSumExRate').textContent = cur === (p.base_currency_code || 'USD') ? '—' : ('1 ' + (p.base_currency_code || 'USD') + ' = ' + exRate.toFixed(4) + ' ' + cur);
                _cCurSym = sym2; _cTotal = parseFloat(p.final_amount) || 0;
                updateTotal();

                // ── خصم تعجيل الدفع (بانر إعلامي بس — التحصيل الفعلي صار
                // خارج مودال التأكيد كلياً، عبر صفحات المقبوضات العامة) ──
                const settlePct = parseFloat(p.settlement_discount_pct || 0);
                const banner = document.getElementById('cSettleDiscBanner');
                if (settlePct > 0 && p.due_date) {
                    const today = new Date().toISOString().slice(0, 10);
                    const stillEligible = today <= p.due_date;
                    const discountedTotal = parseFloat(p.final_amount) * (1 - settlePct / 100);
                    if (stillEligible) {
                        banner.style.display = '';
                        document.getElementById('cSettleDiscText').innerHTML =
                            `خصم تعجيل دفع ${settlePct}% متاح لو حصّلت الفاتورة كاملة قبل ${p.due_date} —
                             المبلغ بعد الخصم: <strong>${sym2} ${fmt(discountedTotal)}</strong> بدل ${sym2} ${fmt(p.final_amount)}`;
                    } else {
                        banner.style.display = '';
                        banner.querySelector('div').style.background = '#fef2f2';
                        banner.querySelector('div').style.borderColor = '#fecaca';
                        document.getElementById('cSettleDiscText').className = 'text-danger fw-600';
                        document.getElementById('cSettleDiscText').innerHTML =
                            `فات موعد خصم تعجيل الدفع (${settlePct}%) — كان لازم التحصيل قبل ${p.due_date}. المبلغ الكامل مستحق الآن.`;
                    }
                } else {
                    banner.style.display = 'none';
                }
            });
        }

        function updateTotal() {
            const invCur = _cInvoiceData?.currency_code || 'USD';
            const invSym = _cInvoiceData?.currency_symbol || '$';
            const fmt = n => new Intl.NumberFormat('en').format(Math.abs(parseFloat(n || 0)).toFixed(2));

            let html = `<div style="border:1px solid #d1fae5;border-radius:7px;padding:7px 10px;margin-bottom:6px;background:#fff">
            <div style="font-size:.72rem;font-weight:700;color:#065f46;margin-bottom:4px">
                <i class="bi bi-currency-exchange me-1"></i>عملة: ${invCur}
            </div>
            <div class="d-flex justify-content-between fw-700" style="font-size:.82rem">
            <span>إجمالي الفاتورة</span>
            <span>${invSym} ${fmt(_cTotal)}</span></div>
            </div>`;
            // ⚠ ملخّص إعلامي بس لربط النقلية/الشحن/الجمارك — بلا أي
            // قيد محاسبي، وبلا أي تأثير على إجمالي الفاتورة أعلاه.
            const transportSel = document.getElementById('cTransportCarrier');
            const shippingSel = document.getElementById('cShippingCarrierNew');
            const hasTransport = transportSel && transportSel.value;
            const hasShipping = shippingSel && shippingSel.value;
            const customsCost = document.getElementById('cHasCustoms')?.checked
                ? (parseFloat(document.getElementById('cCustomsCost')?.value) || 0) : 0;
            if (hasTransport || hasShipping || customsCost > 0) {
                html += `<div style="border:1px solid #fde68a;border-radius:7px;padding:7px 10px;margin-bottom:6px;background:#fffbeb">
            <div style="font-size:.72rem;font-weight:700;color:#92400e;margin-bottom:4px">
                <i class="bi bi-truck me-1"></i>بيانات لوجستية (ربط توثيقي بس)
            </div>
            ${hasTransport ? `<div class="d-flex justify-content-between" style="font-size:.75rem"><span class="text-muted">نقلية</span><span class="fw-600">${transportSel.options[transportSel.selectedIndex].text}</span></div>` : ''}
            ${hasShipping ? `<div class="d-flex justify-content-between" style="font-size:.75rem"><span class="text-muted">شحن</span><span class="fw-600">${shippingSel.options[shippingSel.selectedIndex].text}</span></div>` : ''}
            ${customsCost > 0 ? `<div class="d-flex justify-content-between" style="font-size:.75rem"><span class="text-muted">جمارك</span><span class="fw-600">${fmt(customsCost)}</span></div>` : ''}
            </div>`;
            }
            document.getElementById('cTotSummary').innerHTML = html ||
                '<div class="text-muted" style="font-size:.79rem">أدخل المبالغ لعرض الملخص</div>';
        }

        function previewImg(inp) {
            if (!inp.files || !inp.files[0]) return;
            const f = inp.files[0];
            if (f.type.startsWith('image/')) {
                const rd = new FileReader();
                rd.onload = e => { document.getElementById('cImgThumb').src = e.target.result; document.getElementById('cImgPreview').style.display = ''; };
                rd.readAsDataURL(f);
            } else { document.getElementById('cImgPreview').style.display = ''; }
        }
        function clearImg() {
            document.getElementById('cInvoiceImg').value = '';
            document.getElementById('cImgPreview').style.display = 'none';
            document.getElementById('cImgThumb').src = '';
        }

        // ── طباعة الفاتورة ──
        // مشتركة بين مودال التفاصيل (viewInvoice) ومودال التأكيد
        // (openConfirmModal) — كلاهما بيخزّن بيانات الفاتورة بنفس
        // المتغير _cInvoiceData قبل ما يستدعيها المستخدم.
        const BRANCH = <?= json_encode([
            'name' => $branchInfo['name'] ?? $branchName,
            'phone' => $branchInfo['phone'] ?? '',
            'address' => $branchInfo['address'] ?? '',
            'city' => $branchInfo['city'] ?? '',
            'email' => $branchInfo['email'] ?? '',
            'tax_number' => $branchInfo['tax_number'] ?? '',
            'commercial_registration_number' => $branchInfo['commercial_registration_number'] ?? '',
            'tenant_slogan' => $branchInfo['tenant_slogan'] ?? '',
        ], JSON_UNESCAPED_UNICODE) ?>;

        function printSaleInvoice() {
            const p = _cInvoiceData;
            if (!p) { toast('يرجى فتح تفاصيل الفاتورة أولاً', 'danger'); return; }
            const sym = p.currency_symbol || '$';
            const baseSym = p.base_currency_symbol || '$';
            const fmt = n => sym + ' ' + new Intl.NumberFormat('en').format(parseFloat(n || 0).toFixed(2));
            const fmtBase = n => baseSym + ' ' + new Intl.NumberFormat('en').format(parseFloat(n || 0).toFixed(2));

            // ⚠ قرار مُعدَّل: دمج البنود حسب الكروب (منتج + سعر) — كل
            // كروب سطر واحد، القياسات معروضة كمدى (أصغر-أكبر + نوع
            // العمر، مثلاً "2-5 سنة")، والألوان المختلفة بنفس الكروب
            // مجمَّعة بسطر واحد. "عدد الكروبات" بالسطر = مجموع الكمية
            // عبر كل القياسات/الألوان المدموجة بهالكروب.
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

            const items = p.items || [];
            const grpMap = {};
            items.forEach(it => {
                // ⚠ group_key مصدر الحقيقة الوحيد للتجميع — بلا اللون
                // هون بالذات (القرار: الألوان المختلفة بنفس الكروب
                // تندمج بسطر واحد بالطباعة).
                const key = (it.product_id || it.item_name) + '_' + it.group_key;
                if (!grpMap[key]) grpMap[key] = {
                    item_name: it.item_name, model_number: it.model_number || '',
                    sizes: [], colors: [], age_type: it.age_type || '',
                    catalog_selling_price: it.catalog_selling_price, packet_qty: it.packet_qty,
                    qty: 0, discount_amount: 0, unit_price: parseFloat(it.unit_price), total_price: 0
                };
                const g = grpMap[key];
                if (it.size && !g.sizes.includes(it.size)) g.sizes.push(it.size);
                if (it.color && !g.colors.includes(it.color)) g.colors.push(it.color);
                if (!g.age_type && it.age_type) g.age_type = it.age_type;
                g.qty += parseFloat(it.quantity) || 0;
                g.discount_amount += parseFloat(it.discount_amount) || 0;
                g.total_price += parseFloat(it.total_price) || 0;
            });
            const groups = Object.values(grpMap);

            let itemRows = '', i = 1, totalQty = 0;
            groups.forEach(g => {
                totalQty += g.qty;
                itemRows += `<tr>
                <td>${i++}</td>
                <td>${g.item_name || '—'}</td>
                <td dir="ltr">${g.model_number || '—'}</td>
                <td dir="ltr">${formatSizeRange(g.sizes, g.age_type)}</td>
                <td style="color:#16a34a">${g.colors.join(' · ') || '—'}</td>
                <td>${g.qty}</td>
                <td>${fmt(g.unit_price)}</td>
                <td class="n-total">${fmt(g.total_price)}</td>
            </tr>`;
            });

            // ⚠⚠ إصلاح: total_price لكل بند = عدد المنتجات × سعر البيع
            // مباشرة (نظام "تكلفة ثابتة + سعر بيع حر" الحالي) —
            // discount_amount صار "قيمة الفارق" (تكلفة−بيع، ممكن يكون
            // سالب = ربح)، مش خصم بسيط بيُطرح من سعر بيع أعلى. جمعه
            // مع total_price كان يعيد بناء سعر التكلفة بالغلط ويعرضه
            // كأنه "المبلغ الصافي". الصحيح: المبلغ الصافي = مجموع
            // (سعر البيع × عدد المنتجات) مباشرة = p.total_amount نفسه
            // (نفس القيمة المخزَّنة بالهيدر أصلاً)، بلا أي ذكر للتكلفة.
            const netProductsAmount = parseFloat(p.total_amount) || 0;
            const generalDiscPct = netProductsAmount > 0 ? (parseFloat(p.discount_amount || 0) / netProductsAmount * 100) : 0;
            const afterGeneralDisc = netProductsAmount - parseFloat(p.discount_amount || 0);
            const taxPct = afterGeneralDisc > 0 ? (parseFloat(p.tax_amount || 0) / afterGeneralDisc * 100) : 0;

            // ⚠ لون عنوان نوع المستند حسب النوع — أخضر لبيع منتجات (هالنموذج
            // مبني للمبيعات فقط حالياً؛ نفس البنية قابلة لإعادة الاستخدام
            // لاحقاً لمستندات الشراء بتغيير هالمتغيّرين بس)
            const docTitle = 'فاتورة بيع منتجات';
            const docColor = '#16a34a'; // أخضر = بيع منتجات (أحمر=شراء منتجات، أصفر=شراء مستهلكات — لاحقاً)

            // ⚠ كشف حساب سريع مختصر — بعملة الفرع، من رصيد حساب ذمة
            // العميل (الخاص لو موجود، وإلا العام) قبل/بعد هالفاتورة —
            // راجع شرح الحساب بمعالج get_invoice بالسيرفر.
            const balBefore = p.customer_balance_before;
            const balAfter = p.customer_balance_after;
            const lastPayment = parseFloat(p.paid_amount || 0);
            const TENANT_LOGO_URL = '<?= BASE_PATH ?>/assets/images/tenant_logo.png';
            const FATORIZE_LOGO_URL = '<?= BASE_PATH ?>/assets/images/fatorize.png';
            const html = `<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
<meta charset="UTF-8">
<title>فاتورة بيع ${p.invoice_number}</title>
<style>
*{margin:0;padding:0}
body{font-family:'Arial',sans-serif;font-size:17px;color:#111;padding:26px;margin:0 auto;line-height:1.5}
/* ── الترويسة: ٣ أقسام ── */
.header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:5px;padding-bottom:14px;border-bottom:3px solid ${docColor}}
.hdr-tenant{text-align:center;min-width:190px}
.hdr-tenant img{max-height:190px;max-width:190px;object-fit:contain}
.hdr-tenant .slogan{font-size:24px;font-weight:bold;color:var(--section-color);margin-top:18px}
.hdr-title{text-align:center;flex:1;padding-top:35px}
.hdr-title .doc-type{background:${docColor};color:#111;font-weight:700;font-size:30px;padding:9px 28px;border-radius:14px}
.hdr-branch{min-width:130px;font-size:15px;line-height:1.8}
.hdr-branch .br-name{font-size:18px;font-weight:800;color:${docColor};margin-bottom:4px}
/* ── معلومات العميل ── */
.inv-meta{border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;margin-bottom:14px;background:#f8fafc}
.inv-meta h4{font-size:13px;font-weight:700;color:${docColor};text-transform:uppercase;letter-spacing:.5px;margin-bottom:9px;padding-bottom:5px;border-bottom:1px solid #e2e8f0}
.meta-grid{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:8px 18px}
.meta-item span:first-child{color:#64748b;font-size:13px;display:block}
.meta-item span:last-child{font-weight:600;font-size:15px}
/* ── جدول البنود ── */
table{width:100%;border-collapse:collapse;margin-bottom:14px;font-size:14px;border:1px solid #cbd5e1}
thead th{background:${docColor};color:#fff;padding:9px 6px;text-align:center;font-weight:600;border:1px solid ${docColor}}
tbody td{padding:8px 6px;border:1px solid #cbd5e1;text-align:center}
tbody td:nth-child(2){text-align:right}
tbody tr:nth-child(even) td{background:#f8fafc}
.n-total{font-weight:700}
tfoot td{background:#f1f5f9;font-weight:700;padding:9px 6px;text-align:center;font-size:15px;border:1px solid #cbd5e1}
/* ── قسم المبالغ ── */
.totals-wrap{display:flex;justify-content:flex-end;margin-top:12px}
.totals{width:62%;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden}
.tot-row{display:flex;justify-content:space-between;padding:8px 16px;font-size:15px;border-bottom:1px solid #f1f5f9}
.tot-row.sub{color:#64748b}
.tot-row.final{background:${docColor};color:#fff;font-weight:700;font-size:19px;border:none;padding:10px 16px}
/* ── الفوتر: كشف حساب مختصر ── */
.statement{margin-top:18px;border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;background:#f8fafc}
.statement h4{font-size:13px;font-weight:700;color:${docColor};margin-bottom:9px}
.statement-grid{display:flex;justify-content:space-between;text-align:center}
.statement-grid .item span:first-child{color:#64748b;font-size:13px;display:block;margin-bottom:3px}
.statement-grid .item span:last-child{font-weight:700;font-size:16px}
.footer-bottom{display:flex;justify-content:space-between;align-items:center;margin-top:14px;padding-top:12px;border-top:1px solid #e2e8f0}
.footer-bottom .txt{font-size:12px;color:#94a3b8}
.footer-bottom img{height:26px}
@media print{@page{margin:10mm}button{display:none}}
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
    ${BRANCH.commercial_registration_number ? `<div> السجل الصناعي/التجاري: ${BRANCH.commercial_registration_number}</div>` : ''}
  </div>
</div>

<!-- ══ معلومات العميل والشحن ══ -->
<div class="inv-meta">
  <h4>معلومات العميل والشحن</h4>
  <div class="meta-grid">
    <div class="meta-item"><span>اسم العميل</span><span>${p.customer_name || '—'}</span></div>
    <div class="meta-item"><span>رقم الهاتف</span><span dir="ltr">${p.customer_phone || '—'}</span></div>
    <div class="meta-item"><span>رقم الفاتورة</span><span dir="ltr">${p.invoice_number}</span></div>
    <div class="meta-item"><span>تاريخ الفاتورة</span><span dir="ltr">${p.created_at ? p.created_at.replace('T', ' ').slice(0, 16) : (p.invoice_date || '—')}</span></div>
    <div class="meta-item"><span>العنوان</span><span>${p.customer_address || '—'}</span></div>
    <div class="meta-item"><span>الرقم الضريبي</span><span dir="ltr">${p.customer_tax_number || '—'}</span></div>
    <div class="meta-item"><span>شركة الشحن</span><span>${p.customer_shipping_company || '—'}</span></div>
    <div class="meta-row"><span>العملة:</span><span>${p.currency_code || 'USD'}</span></div>
  </div>
</div>

<!-- ══ بنود الفاتورة ══ -->
<div class="inv-meta">
<h4 > بنود الفاتورة</h4>
<table>
  <thead><tr>
    <th>#</th><th>بيان القطعة</th><th>رقم الموديل</th><th>القياس</th><th>اللون</th>
    <th>عدد الكروبات</th>
    <th>سعر الوحدة</th><th>الإجمالي</th>
  </tr></thead>
  <tbody>${itemRows}</tbody>
  <tfoot><tr>
    <td colspan="5">إجمالي الكميات</td>
    <td>${totalQty}</td>
    <td></td>
    <td>${fmt(p.final_amount)}</td>
  </tr></tfoot>
</table>
</div>
<!-- ══ المبالغ (عملة الفاتورة) ══ -->
<div class="totals-wrap">
  <div class="totals">
    <div class="tot-row"><span>المبلغ الصافي للمنتجات (بدون خصم عام أو ضريبة):</span><span>${fmt(netProductsAmount)}</span></div>
    <div class="tot-row sub"><span>نسبة الخصم العام:</span><span>${generalDiscPct.toFixed(2)}%</span></div>
    <div class="tot-row"><span>الإجمالي بعد الخصم العام:</span><span>${fmt(afterGeneralDisc)}</span></div>
    <div class="tot-row sub"><span>نسبة الضريبة:</span><span>${taxPct.toFixed(2)}%</span></div>
    <div class="tot-row sub"><span>قيمة الضريبة:</span><span>${fmt(p.tax_amount)}</span></div>
    <div class="tot-row final"><span>المبلغ الإجمالي النهائي:</span><span>${fmt(p.final_amount)}</span></div>
  </div>
</div>

${p.notes ? `<div style="margin-top:14px;padding:10px 14px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:14px"><b>ملاحظات:</b> ${p.notes}</div>` : ''}

<!-- ══ كشف حساب سريع مختصر (بعملة الفرع) ══ -->
<div class="statement">
  
  <div class="statement-grid">
    <div class="item"><span>المستحق قبل الفاتورة</span><span>${balBefore != null ? fmtBase(balBefore) : '—'}</span></div>
    <div class="item"><span>آخر دفعة</span><span style="color:#16a34a">${lastPayment > 0 ? fmtBase(lastPayment) : '—'}</span></div>
    <div class="item"><span>الرصيد النهائي</span><span style="color:${(balAfter || 0) > 0 ? '#dc2626' : '#16a34a'}">${balAfter != null ? fmtBase(balAfter) : '—'}</span></div>
  </div>
</div>
<div class="footer-bottom">
  <span class="txt">نظام فاتورايز المحاسبي الإداري — Fatorize ERP System</span>
  <img src="${FATORIZE_LOGO_URL}" alt="Fatorize" onerror="this.style.display='none'">
</div>

<script>window.onload=()=>window.print()<\/script>
</body></html>`;
            const w = window.open('', '_blank', 'width=950,height=750');
            w.document.write(html);
            w.document.close();
        }

        function doConfirm() {
            document.getElementById('confirmTxt').style.opacity = '0';
            document.getElementById('confirmSpin').style.display = 'inline-block';
            document.getElementById('btnConfirm').disabled = true;
            const fd = new FormData();
            fd.append('_action', 'confirm');
            fd.append('invoice_id', _cId);
            fd.append('receive_date', document.getElementById('cReceiveDate').value);
            fd.append('warehouse_id', document.getElementById('cWarehouse').value);
            // ⚠ ربط توثيقي بس — بلا أي مبلغ/قيد محاسبي هون. تكلفة
            // النقل/الشحن الفعلية تُسجَّل كمصروف منفصل بصفحة المصاريف
            // والمستهلكات. الجمارك استثناء: مبلغها يُحفظ (معلوماتي بس،
            // بلا قيد) لأنه غير مرتبط بمصروف تشغيلي عادي.
            fd.append('transport_carrier_id', document.getElementById('cTransportCarrier').value);
            fd.append('shipping_carrier_id', document.getElementById('cShippingCarrierNew').value);
            fd.append('has_customs', document.getElementById('cHasCustoms').checked ? '1' : '0');
            fd.append('customs_cost', document.getElementById('cHasCustoms').checked ? (document.getElementById('cCustomsCost').value || '') : '');
            fd.append('notes', document.getElementById('cNotes').value);
            const img = document.getElementById('cInvoiceImg');
            if (img.files && img.files[0]) fd.append('invoice_image', img.files[0]);
            fetch('../../api/confirm_sale_invoice.php', { method: 'POST', body: fd })
                .then(r => r.json()).then(d => {
                    document.getElementById('confirmTxt').style.opacity = '1';
                    document.getElementById('confirmSpin').style.display = 'none';
                    document.getElementById('btnConfirm').disabled = false;
                    if (d.ok) { toast('✅ ' + d.msg); confirmModal.hide(); setTimeout(() => location.reload(), 800); }
                    else toast(d.msg, 'danger');
                }).catch(() => {
                    document.getElementById('confirmTxt').style.opacity = '1';
                    document.getElementById('confirmSpin').style.display = 'none';
                    document.getElementById('btnConfirm').disabled = false;
                    toast('خطأ في الاتصال', 'danger');
                });
        }

        // ── إلغاء ──
        function cancelInvoice(id, no) {
            if (!confirm(`إلغاء الفاتورة "${no}"؟\nالفواتير المؤكدة سيتم إعادة كمياتها للمخزون وعكس قيدها المحاسبي.`)) return;
            const fd = new FormData();
            fd.append('_action', 'cancel');
            fd.append('invoice_id', id);
            fetch('../../api/confirm_sale_invoice.php', { method: 'POST', body: fd })
                .then(r => r.json()).then(d => {
                    if (d.ok) { toast('✅ ' + d.msg); setTimeout(() => location.reload(), 700); }
                    else toast(d.msg, 'danger');
                });
        }
    </script>
</body>

</html>