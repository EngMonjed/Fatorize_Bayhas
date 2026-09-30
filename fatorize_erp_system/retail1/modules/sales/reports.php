<?php
/**
 * sales/reports.php — تقارير قسم المبيعات
 * المسار: retail1/modules/sales/reports.php
 *
 * ست تقارير (بقرار صريح من صاحب المشروع — نطاق أول نسخة):
 *   1) سجل المبيعات   2) الأكثر شراءً (عملاء)   3) الأكثر مبيعاً (منتجات)
 *   4) هامش الربح التقديري   5) تقرير المرتجعات   6) حالة الدفع
 *
 * مبنية مطابقة حرفياً لـpurchases/reports.php من ناحية الهيكل العام
 * (تبويبات، منتقي تقارير، فلتر تاريخ، بطاقات ملخص، جدول قابل للفرز،
 * طباعة) — بس بمجموعة تقارير مختلفة تناسب المبيعات.
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
requirePermission('sales.reports', 'view');
$currentModule = 'sales.reports';

$TS = $_SESSION['table_suffix'];
$TI = "sales_invoices_{$TS}";
$TII = "sales_invoice_items_{$TS}";
$TR = "sales_returns_{$TS}";
$TC = "customers_{$TS}";
$TPROD = "products_{$TS}";
$TV = "product_variants_{$TS}";
$TSZ = "product_sizes_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];
        $from = $_POST['from'] ?? '';
        $to = $_POST['to'] ?? '';

        // ١) سجل المبيعات
        if ($act === 'rep_register') {
            $w = ["i.status != 'draft'"];
            $prm = [];
            if ($from) {
                $w[] = 'i.invoice_date >= ?';
                $prm[] = $from;
            }
            if ($to) {
                $w[] = 'i.invoice_date <= ?';
                $prm[] = $to;
            }
            $sql = "SELECT i.invoice_number, i.invoice_date, c.name AS customer_name,
                    i.final_amount, i.final_amount_base_currency, i.payment_status, i.status,
                    cur.symbol AS cur_sym, cur.code AS cur_code
                FROM `{$TI}` i
                LEFT JOIN `{$TC}` c ON c.id = i.customer_id
                LEFT JOIN currencies cur ON cur.id = i.invoice_currency_id
                WHERE " . implode(' AND ', $w) . "
                ORDER BY i.invoice_date DESC, i.id DESC";
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

        // ٢) الأكثر شراءً (عملاء)
        if ($act === 'rep_top_customers') {
            $w = ["i.status != 'draft'"];
            $prm = [];
            if ($from) {
                $w[] = 'i.invoice_date >= ?';
                $prm[] = $from;
            }
            if ($to) {
                $w[] = 'i.invoice_date <= ?';
                $prm[] = $to;
            }
            $sql = "SELECT c.id, c.name AS customer_name,
                    COUNT(i.id) AS invoices_count,
                    SUM(i.final_amount_base_currency) AS total_base
                FROM `{$TI}` i
                LEFT JOIN `{$TC}` c ON c.id = i.customer_id
                WHERE " . implode(' AND ', $w) . "
                GROUP BY c.id, c.name
                ORDER BY total_base DESC
                LIMIT 30";
            $st = $pdo->prepare($sql);
            $st->execute($prm);
            echo json_encode(['ok' => true, 'rows' => $st->fetchAll()]);
            exit;
        }

        // ٣) الأكثر مبيعاً (منتجات)
        if ($act === 'rep_top_products') {
            $w = ["i.status != 'draft'"];
            $prm = [];
            if ($from) {
                $w[] = 'i.invoice_date >= ?';
                $prm[] = $from;
            }
            if ($to) {
                $w[] = 'i.invoice_date <= ?';
                $prm[] = $to;
            }
            $sql = "SELECT p.id, p.name AS product_name, p.model_number,
                    SUM(ii.quantity) AS qty_sold,
                    SUM(ii.quantity * ii.unit_price_base_currency) AS total_base
                FROM `{$TII}` ii
                JOIN `{$TI}` i ON i.id = ii.invoice_id
                LEFT JOIN `{$TPROD}` p ON p.id = ii.product_id
                WHERE " . implode(' AND ', $w) . "
                GROUP BY p.id, p.name, p.model_number
                ORDER BY qty_sold DESC
                LIMIT 30";
            $st = $pdo->prepare($sql);
            $st->execute($prm);
            echo json_encode(['ok' => true, 'rows' => $st->fetchAll()]);
            exit;
        }

        // ٤) هامش الربح التقديري (إيرادات ناقص COGS حي من جدول المنتج)
        if ($act === 'rep_profit_margin') {
            $w = ["i.status != 'draft'"];
            $prm = [];
            if ($from) {
                $w[] = 'i.invoice_date >= ?';
                $prm[] = $from;
            }
            if ($to) {
                $w[] = 'i.invoice_date <= ?';
                $prm[] = $to;
            }
            $whereSql = implode(' AND ', $w);
            $sql = "SELECT i.id, i.invoice_number, i.invoice_date, c.name AS customer_name,
                    i.final_amount_base_currency AS revenue_base,
                    (SELECT COALESCE(SUM(ii.quantity * s.cost_price),0)
                        FROM `{$TII}` ii
                        LEFT JOIN `{$TV}` v ON v.id = ii.variant_id
                        LEFT JOIN `{$TSZ}` s ON s.id = v.size_id
                        WHERE ii.invoice_id = i.id) AS cogs_base
                FROM `{$TI}` i
                LEFT JOIN `{$TC}` c ON c.id = i.customer_id
                WHERE {$whereSql}
                ORDER BY i.invoice_date DESC, i.id DESC";
            $st = $pdo->prepare($sql);
            $st->execute($prm);
            $rows = $st->fetchAll();
            $totalRevenue = 0;
            $totalCogs = 0;
            foreach ($rows as &$r) {
                $rev = (float) $r['revenue_base'];
                $cogs = (float) $r['cogs_base'];
                $r['profit_base'] = round($rev - $cogs, 2);
                $r['margin_pct'] = $rev > 0 ? round(($rev - $cogs) / $rev * 100, 2) : 0;
                $totalRevenue += $rev;
                $totalCogs += $cogs;
            }
            $totalProfit = $totalRevenue - $totalCogs;
            echo json_encode([
                'ok' => true,
                'rows' => $rows,
                'totals' => [
                    'revenue_base' => round($totalRevenue, 2),
                    'cogs_base' => round($totalCogs, 2),
                    'profit_base' => round($totalProfit, 2),
                    'margin_pct' => $totalRevenue > 0 ? round($totalProfit / $totalRevenue * 100, 2) : 0,
                ]
            ]);
            exit;
        }

        // ٥) تقرير المرتجعات
        if ($act === 'rep_returns') {
            $w = ["r.status != 'draft'"];
            $prm = [];
            if ($from) {
                $w[] = 'r.return_date >= ?';
                $prm[] = $from;
            }
            if ($to) {
                $w[] = 'r.return_date <= ?';
                $prm[] = $to;
            }
            $sql = "SELECT r.return_number, r.invoice_number, r.return_date,
                    COALESCE(c.name, r.customer_name) AS customer_name,
                    r.return_reason, r.return_amount, r.status,
                    cur.symbol AS cur_sym,
                    r.return_amount AS return_amount_base
                FROM `{$TR}` r
                LEFT JOIN `{$TC}` c ON c.id = r.customer_id
                LEFT JOIN currencies cur ON cur.id = r.return_currency_id
                WHERE " . implode(' AND ', $w) . "
                ORDER BY r.return_date DESC, r.id DESC";
            $st = $pdo->prepare($sql);
            $st->execute($prm);
            $rows = $st->fetchAll();

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

        // ٦) حالة الدفع (مدفوعة/جزئية/معلقة) — للفواتير المؤكدة فقط
        if ($act === 'rep_payment_status') {
            $w = ["i.status = 'confirmed'"];
            $prm = [];
            if ($from) {
                $w[] = 'i.invoice_date >= ?';
                $prm[] = $from;
            }
            if ($to) {
                $w[] = 'i.invoice_date <= ?';
                $prm[] = $to;
            }
            $sql = "SELECT i.payment_status,
                    COUNT(i.id) AS invoices_count,
                    SUM(i.final_amount_base_currency) AS total_base,
                    SUM(i.balance_amount) AS balance_base
                FROM `{$TI}` i
                WHERE " . implode(' AND ', $w) . "
                GROUP BY i.payment_status
                ORDER BY FIELD(i.payment_status,'pending','partial','paid')";
            $st = $pdo->prepare($sql);
            $st->execute($prm);
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
    <title>تقارير المبيعات — <?= htmlspecialchars($branchName) ?></title>
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

        .rep-pill:hover {
            border-color: #16a34a;
            color: #16a34a
        }

        .rep-pill.active {
            background: #16a34a;
            color: #fff;
            border-color: #16a34a
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
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-bar-chart me-1 text-success"></i>تقارير المبيعات</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <nav class="ms-auto d-flex align-items-center gap-1 no-print" style="font-size:.78rem;color:#94a3b8">
            <span>المبيعات</span>
            <i class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
            <span class="text-success fw-600">التقارير</span>
        </nav>
    </header>

    <main class="main-content">
        <div class="content-body">
            <!-- تبويبات القسم (مكوّن مشترك — يتبع الشريط الجانبي) -->
            <?php require __DIR__ . '/../../../includes/tab_bar.php'; ?>

            <!-- منتقي التقرير -->
            <div class="d-flex gap-2 flex-wrap mb-3 no-print" id="repPills">
                <div class="rep-pill active" data-rep="register" onclick="switchReport('register')">
                    <i class="bi bi-journal-text me-1"></i>سجل المبيعات
                </div>
                <div class="rep-pill" data-rep="top_customers" onclick="switchReport('top_customers')">
                    <i class="bi bi-trophy me-1"></i>الأكثر شراءً
                </div>
                <div class="rep-pill" data-rep="top_products" onclick="switchReport('top_products')">
                    <i class="bi bi-box-seam me-1"></i>الأكثر مبيعاً
                </div>
                <div class="rep-pill" data-rep="profit_margin" onclick="switchReport('profit_margin')">
                    <i class="bi bi-graph-up-arrow me-1"></i>هامش الربح
                </div>
                <div class="rep-pill" data-rep="returns" onclick="switchReport('returns')">
                    <i class="bi bi-arrow-return-right me-1"></i>تقرير المرتجعات
                </div>
                <div class="rep-pill" data-rep="payment_status" onclick="switchReport('payment_status')">
                    <i class="bi bi-cash-coin me-1"></i>حالة الدفع
                </div>
            </div>

            <!-- شريط الفلاتر -->
            <div class="tbl-wrap mb-3">
                <div class="tbl-hdr no-print">
                    <span style="font-size:.88rem;font-weight:700;color:#1e293b" id="repTitle">
                        <i class="bi bi-journal-text me-1 text-success"></i>سجل المبيعات
                    </span>


                    <div class="d-flex gap-2 ms-auto flex-wrap align-items-center">
                        <div id="dateFilterWrap" class="d-flex gap-2 align-items-center">
                            <input type="date" id="repFrom" class="form-control form-control-sm"
                                style="width:150px;border-radius:8px">
                            <span style="font-size:.8rem;color:#94a3b8">—</span>
                            <input type="date" id="repTo" class="form-control form-control-sm"
                                style="width:150px;border-radius:8px">
                        </div>
                        <button class="btn btn-sm fw-600" style="border-radius:8px;background:#16a34a;color:#fff"
                            onclick="loadReport()">
                            <i class="bi bi-search me-1"></i>تطبيق
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" style="border-radius:8px"
                            onclick="window.print()">
                            <i class="bi bi-printer me-1"></i>طباعة
                        </button>
                    </div>
                </div>

                <!-- ترويسة الطباعة -->
                <div class="d-none d-print-flex justify-content-between align-items-center p-3"
                    style="border-bottom:2px solid #16a34a">
                    <div class="d-flex align-items-center gap-2">
                        <img src="<?= BASE_PATH ?>/assets/images/fatorize.png" style="height:34px">
                        <div>
                            <div style="font-weight:700;color:#065f46">فاتورايز — FATORIZE</div>
                            <div style="font-size:.75rem;color:#64748b" id="printSubtitle">سجل المبيعات</div>
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
        const sb = document.getElementById('sidebar'), ov = document.getElementById('sbOverlay');
        function sbOpen() {sb.classList.add('open'); ov.classList.add('show');}
        function sbClose() {sb.classList.remove('open'); ov.classList.remove('show');}
        window.addEventListener('resize', () => {if (window.innerWidth > 991) sbClose();});
        function toggleGroup(g) {const o = g.classList.contains('open'); document.querySelectorAll('.sb-group.open').forEach(x => x.classList.remove('open')); g.classList.toggle('open', !o); localStorage.setItem('sb_open_' + g.dataset.key, (!o).toString());}
        document.querySelectorAll('.sb-group').forEach(g => {if (localStorage.getItem('sb_open_' + g.dataset.key) === 'true') g.classList.add('open');});

        let currentReport = 'register';

        function post(data) {
            const fd = new FormData();
            for (const k in data) fd.append(k, data[k]);
            return fetch('reports.php', {method: 'POST', body: fd}).then(r => r.json());
        }

        const fmt = n => new Intl.NumberFormat('en').format(parseFloat(n || 0).toFixed(2));

        const REPORTS = {
            register: {title: 'سجل المبيعات', icon: 'bi-journal-text', needsDate: true},
            top_customers: {title: 'الأكثر شراءً (عملاء)', icon: 'bi-trophy', needsDate: true},
            top_products: {title: 'الأكثر مبيعاً (منتجات)', icon: 'bi-box-seam', needsDate: true},
            profit_margin: {title: 'هامش الربح التقديري', icon: 'bi-graph-up-arrow', needsDate: true},
            returns: {title: 'تقرير المرتجعات', icon: 'bi-arrow-return-right', needsDate: true},
            payment_status: {title: 'حالة الدفع', icon: 'bi-cash-coin', needsDate: true},
        };

        function switchReport(rep) {
            currentReport = rep;
            document.querySelectorAll('.rep-pill').forEach(p => p.classList.toggle('active', p.dataset.rep === rep));
            const meta = REPORTS[rep];
            document.getElementById('repTitle').innerHTML = `<i class="bi ${meta.icon} me-1 text-success"></i>${meta.title}`;
            document.getElementById('printSubtitle').textContent = meta.title;
            document.getElementById('dateFilterWrap').style.display = meta.needsDate ? '' : 'none';
            loadReport();
        }

        function loadReport() {
            const from = document.getElementById('repFrom').value;
            const to = document.getElementById('repTo').value;
            document.getElementById('repBody').innerHTML = '<div class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm"></span> جارٍ التحميل...</div>';
            document.getElementById('repSummaryCards').innerHTML = '';

            const actMap = {
                register: 'rep_register', top_customers: 'rep_top_customers', top_products: 'rep_top_products',
                profit_margin: 'rep_profit_margin', returns: 'rep_returns', payment_status: 'rep_payment_status'
            };
            post({_action: actMap[currentReport], from, to}).then(d => {
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
        const STATUS_LABELS = {confirmed: 'مؤكدة', cancelled: 'ملغاة', draft: 'مسودة', posted: 'مؤكد'};

        const RENDERERS = {
            register(d) {
                document.getElementById('repSummaryCards').innerHTML =
                    statCard('عدد الفواتير', d.summary.count, 'bi-receipt', '#065f46', '#f0fdf4') +
                    statCard('الإجمالي (عملة الفرع)', '$ ' + fmt(d.summary.total_base), 'bi-cash-stack', '#16a34a', '#f0fdf4');
                if (!d.rows.length) {document.getElementById('repBody').innerHTML = emptyMsg('لا توجد فواتير بهالفترة'); return;}
                document.getElementById('repBody').innerHTML = `<div class="table-responsive"><table class="mtbl" id="repTable">
                    <thead><tr>
                        <th style="color:#065f46">رقم الفاتورة</th><th>التاريخ</th><th style="color:#16a34a">العميل</th>
                        <th>الإجمالي</th><th>بعملة الفرع</th><th>الدفع</th><th>الحالة</th>
                    </tr></thead><tbody>${d.rows.map(r => `
                        <tr>
                            <td class="fw-600" style="color:#065f46" dir="ltr">${r.invoice_number}</td>
                            <td class="text-muted">${r.invoice_date}</td>
                            <td style="color:#16a34a" class="fw-600">${r.customer_name || '—'}</td>
                            <td class="n">${fmt(r.final_amount)} ${r.cur_sym || ''}</td>
                            <td class="n text-success">$ ${fmt(r.final_amount_base_currency)}</td>
                            <td><span class="badge bg-light text-dark">${PAY_LABELS[r.payment_status] || r.payment_status}</span></td>
                            <td><span class="badge bg-light text-dark">${STATUS_LABELS[r.status] || r.status}</span></td>
                        </tr>`).join('')}</tbody></table></div>`;
                makeSortable(document.getElementById('repTable'));
            },
            top_customers(d) {
                if (!d.rows.length) {document.getElementById('repBody').innerHTML = emptyMsg('لا توجد بيانات'); return;}
                document.getElementById('repBody').innerHTML = `<div class="table-responsive"><table class="mtbl" id="repTable">
                    <thead><tr><th>#</th><th style="color:#16a34a">العميل</th><th>عدد الفواتير</th><th>الإجمالي (عملة الفرع)</th></tr></thead>
                    <tbody>${d.rows.map((r, i) => `
                        <tr>
                            <td class="text-muted">${i + 1}</td>
                            <td style="color:#16a34a" class="fw-600">${r.customer_name || '—'}</td>
                            <td class="n">${r.invoices_count}</td>
                            <td class="n fw-600 text-success">$ ${fmt(r.total_base)}</td>
                        </tr>`).join('')}</tbody></table></div>`;
                makeSortable(document.getElementById('repTable'));
            },
            top_products(d) {
                if (!d.rows.length) {document.getElementById('repBody').innerHTML = emptyMsg('لا توجد بيانات'); return;}
                document.getElementById('repBody').innerHTML = `<div class="table-responsive"><table class="mtbl" id="repTable">
                    <thead><tr><th>#</th><th style="color:#065f46">المنتج</th><th>رقم الموديل</th><th>الكمية المباعة</th><th>الإجمالي (عملة الفرع)</th></tr></thead>
                    <tbody>${d.rows.map((r, i) => `
                        <tr>
                            <td class="text-muted">${i + 1}</td>
                            <td style="color:#065f46" class="fw-600">${r.product_name || '—'}</td>
                            <td class="text-muted" dir="ltr">${r.model_number || '—'}</td>
                            <td class="n fw-600">${fmt(r.qty_sold)}</td>
                            <td class="n fw-600 text-success">$ ${fmt(r.total_base)}</td>
                        </tr>`).join('')}</tbody></table></div>`;
                makeSortable(document.getElementById('repTable'));
            },
            profit_margin(d) {
                const t = d.totals;
                document.getElementById('repSummaryCards').innerHTML =
                    statCard('إجمالي الإيرادات', '$ ' + fmt(t.revenue_base), 'bi-cash-stack', '#065f46', '#f0fdf4') +
                    statCard('تكلفة البضاعة المباعة', '$ ' + fmt(t.cogs_base), 'bi-box-arrow-up', '#dc2626', '#fef2f2') +
                    statCard('الربح التقديري', '$ ' + fmt(t.profit_base), 'bi-graph-up-arrow', '#16a34a', '#f0fdf4') +
                    statCard('هامش الربح', t.margin_pct + '%', 'bi-percent', '#16a34a', '#f0fdf4');
                if (!d.rows.length) {document.getElementById('repBody').innerHTML = emptyMsg('لا توجد فواتير بهالفترة'); return;}
                document.getElementById('repBody').innerHTML = `<div class="table-responsive"><table class="mtbl" id="repTable">
                    <thead><tr>
                        <th style="color:#065f46">رقم الفاتورة</th><th>التاريخ</th><th style="color:#16a34a">العميل</th>
                        <th>الإيراد</th><th>التكلفة</th><th>الربح</th><th>الهامش</th>
                    </tr></thead><tbody>${d.rows.map(r => {
                    const color = r.margin_pct >= 30 ? '#16a34a' : (r.margin_pct >= 10 ? '#d97706' : '#dc2626');
                    return `<tr>
                            <td class="fw-600" style="color:#065f46" dir="ltr">${r.invoice_number}</td>
                            <td class="text-muted">${r.invoice_date}</td>
                            <td style="color:#16a34a" class="fw-600">${r.customer_name || '—'}</td>
                            <td class="n">$ ${fmt(r.revenue_base)}</td>
                            <td class="n text-danger">$ ${fmt(r.cogs_base)}</td>
                            <td class="n fw-600 text-success">$ ${fmt(r.profit_base)}</td>
                            <td class="n fw-700" style="color:${color}">${r.margin_pct}%</td>
                        </tr>`;
                }).join('')}</tbody></table></div>`;
                makeSortable(document.getElementById('repTable'));
            },
            returns(d) {
                const reasonsHtml = Object.entries(d.by_reason).slice(0, 4).map(([reason, amt]) =>
                    statCard(reason, '$ ' + fmt(amt), 'bi-tag', '#dc2626', '#fef2f2')).join('');
                document.getElementById('repSummaryCards').innerHTML =
                    statCard('إجمالي المرتجعات (عملة الفرع)', '$ ' + fmt(d.total_base), 'bi-arrow-return-right', '#dc2626', '#fef2f2') + reasonsHtml;
                if (!d.rows.length) {document.getElementById('repBody').innerHTML = emptyMsg('لا توجد مرتجعات بهالفترة'); return;}
                document.getElementById('repBody').innerHTML = `<div class="table-responsive"><table class="mtbl" id="repTable">
                    <thead><tr>
                        <th style="color:#065f46">رقم المرتجع</th><th>الفاتورة الأصلية</th><th>التاريخ</th>
                        <th style="color:#16a34a">العميل</th><th>السبب</th><th>القيمة</th><th>الحالة</th>
                    </tr></thead><tbody>${d.rows.map(r => `
                        <tr>
                            <td class="fw-600" style="color:#065f46" dir="ltr">${r.return_number}</td>
                            <td class="text-muted" dir="ltr">${r.invoice_number}</td>
                            <td class="text-muted">${r.return_date}</td>
                            <td style="color:#16a34a" class="fw-600">${r.customer_name || '—'}</td>
                            <td style="font-size:.78rem">${r.return_reason || '—'}</td>
                            <td class="n">${fmt(r.return_amount)} ${r.cur_sym || ''}</td>
                            <td><span class="badge bg-light text-dark">${STATUS_LABELS[r.status] || r.status}</span></td>
                        </tr>`).join('')}</tbody></table></div>`;
                makeSortable(document.getElementById('repTable'));
            },
            payment_status(d) {
                const STATUS_FULL = {paid: 'مدفوعة بالكامل', partial: 'مدفوعة جزئياً', pending: 'غير مدفوعة'};
                const STATUS_COLOR = {paid: '#16a34a', partial: '#d97706', pending: '#dc2626'};
                if (!d.rows.length) {document.getElementById('repBody').innerHTML = emptyMsg('لا توجد فواتير مؤكدة بهالفترة'); return;}
                const totalAll = d.rows.reduce((s, r) => s + parseFloat(r.total_base || 0), 0);
                document.getElementById('repSummaryCards').innerHTML = d.rows.map(r =>
                    statCard(STATUS_FULL[r.payment_status] || r.payment_status, '$ ' + fmt(r.total_base),
                        'bi-cash-coin', STATUS_COLOR[r.payment_status] || '#64748b',
                        (r.payment_status === 'paid' ? '#f0fdf4' : r.payment_status === 'partial' ? '#fffbeb' : '#fef2f2'))
                ).join('') + statCard('إجمالي كل الحالات', '$ ' + fmt(totalAll), 'bi-cash-stack', '#065f46', '#f0fdf4');
                document.getElementById('repBody').innerHTML = `<div class="table-responsive"><table class="mtbl" id="repTable">
                    <thead><tr><th style="color:#065f46">حالة الدفع</th><th>عدد الفواتير</th><th>الإجمالي (عملة الفرع)</th><th>المتبقي (عملة الفرع)</th></tr></thead>
                    <tbody>${d.rows.map(r => `
                        <tr>
                            <td class="fw-600" style="color:${STATUS_COLOR[r.payment_status] || '#64748b'}">${STATUS_FULL[r.payment_status] || r.payment_status}</td>
                            <td class="n">${r.invoices_count}</td>
                            <td class="n fw-600">$ ${fmt(r.total_base)}</td>
                            <td class="n fw-600 text-danger">$ ${fmt(r.balance_base)}</td>
                        </tr>`).join('')}</tbody></table></div>`;
                makeSortable(document.getElementById('repTable'));
            },
        };

        // فرز الجداول
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
</body>

</html>