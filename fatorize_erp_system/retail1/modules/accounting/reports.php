<?php
/**
 * accounting/reports.php — التقارير المالية (ميزانية عمومية / أرباح وخسائر / تدفقات نقدية)
 * المسار: retail1/modules/accounting/reports.php
 *
 * ⚠ ملاحظات منهجية مهمة:
 * - الميزانية العمومية: لحظة زمنية (as-of-date). لو التاريخ = اليوم، تُقرأ
 *   base_balance مباشرة (سريع). لو تاريخ ماضي، تُعاد بناؤها من مجموع حركة
 *   journal_entry_items المرحّلة لغاية ذاك التاريخ (أبطأ، بس دقيق ومرن).
 * - الأرباح والخسائر: حركة **فترة** (مش رصيد لحظي) لحسابات الإيرادات/المصاريف.
 * - التدفقات النقدية: طريقة مباشرة (Direct Method) — لكل قيد فيه سطر صندوق/بنك،
 *   تصنيف الأثر النقدي حسب تصنيف السطر الآخر "المهيمن" (الأكبر قيمة) بنفس
 *   القيد، عبر عمود cash_flow_category المخزَّن صراحة بشجرة الحسابات (راجع
 *   cash_flow_category_setup.sql) — مش تخمين من بادئة الكود بكل تشغيل.
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('finance.reports', 'view');
$currentModule = 'finance.reports';

$TS = $_SESSION['table_suffix'];
$TAC = "account_charts_{$TS}";
$TIAS = "invoice_account_settings_{$TS}";
$TJE = "journal_entries_{$TS}";
$TJI = "journal_entry_items_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// ── حساب أرصدة الحسابات كلها بتاريخ معيّن (أو الحالي لو فاضي/اليوم) ──
function calcBalancesAsOf(PDO $pdo, string $TAC, string $TJE, string $TJI, ?string $asOfDate): array
{
    $today = date('Y-m-d');
    if (!$asOfDate || $asOfDate >= $today) {
        $rows = $pdo->query("SELECT id, base_balance FROM `{$TAC}`")->fetchAll();
        $out = [];
        foreach ($rows as $r)
            $out[$r['id']] = (float) $r['base_balance'];
        return $out;
    }
    $st = $pdo->prepare("SELECT ji.account_id, SUM(ji.debit - ji.credit) AS net
        FROM `{$TJI}` ji JOIN `{$TJE}` je ON je.id = ji.journal_entry_id
        WHERE je.status = 'posted' AND je.entry_date <= ?
        GROUP BY ji.account_id");
    $st->execute([$asOfDate]);
    $out = [];
    foreach ($st->fetchAll() as $r)
        $out[$r['account_id']] = (float) $r['net'];
    return $out;
}

// ── حركة فترة (مش رصيد لحظي) لحسابات محدَّدة ──
function calcPeriodMovement(PDO $pdo, string $TJE, string $TJI, string $fromDate, string $toDate): array
{
    $st = $pdo->prepare("SELECT ji.account_id, SUM(ji.debit - ji.credit) AS net
        FROM `{$TJI}` ji JOIN `{$TJE}` je ON je.id = ji.journal_entry_id
        WHERE je.status = 'posted' AND je.entry_date BETWEEN ? AND ?
        GROUP BY ji.account_id");
    $st->execute([$fromDate, $toDate]);
    $out = [];
    foreach ($st->fetchAll() as $r)
        $out[$r['account_id']] = (float) $r['net'];
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        // ══════════ الميزانية العمومية ══════════
        if ($act === 'get_balance_sheet') {
            $asOf = trim($_POST['as_of_date'] ?? '') ?: date('Y-m-d');
            $balances = calcBalancesAsOf($pdo, $TAC, $TJE, $TJI, $asOf);
            $accounts = $pdo->query("SELECT id,code,name,account_type,level,parent_id
                FROM `{$TAC}` WHERE is_active=1 ORDER BY code")->fetchAll();

            $groups = ['asset' => [], 'liability' => [], 'equity' => []];
            $unclosedEarnings = 0; // صافي ربح/خسارة تراكمي (منذ البداية) لسا ما انقفل بقيد فعلي
            foreach ($accounts as $a) {
                $bal = $balances[$a['id']] ?? 0;
                if ($a['account_type'] === 'revenue') {
                    $unclosedEarnings += -$bal; // الإيراد دائن الطبيعة
                    continue;
                }
                if ($a['account_type'] === 'expense') {
                    $unclosedEarnings -= $bal;
                    continue;
                }
                if (!isset($groups[$a['account_type']]))
                    continue;
                if (abs($bal) < 0.005)
                    continue; // إخفاء الحسابات الصفرية لتوضيح التقرير
                // الالتزامات وحقوق الملكية طبيعتها دائنة — نعرضها موجبة بعلامة معكوسة
                $display = $a['account_type'] === 'asset' ? $bal : -$bal;
                $groups[$a['account_type']][] = ['code' => $a['code'], 'name' => $a['name'], 'amount' => $display];
            }
            // 🔴 حسابات الإيرادات/المصاريف حسابات مؤقتة، لازم تُقفَل بقيد فعلي
            // بآخر الفترة المحاسبية لحساب "أرباح السنة الحالية" (٣.٣ بشجرة
            // حساباتك) — الإقفال نفسه لسا مش مبني (بند مؤجَّل بـ
            // تحديثات_مستقبلية.md). لحد ما يصير الإقفال، الميزانية ما بتوازن
            // بدون هالسطر الاصطناعي: نعرض صافي الربح/الخسارة التراكمي (منذ
            // البداية لتاريخ التقرير) كسطر إضافي بحقوق الملكية — بدون أي قيد
            // فعلي بقاعدة البيانات، للعرض بس.
            if (abs($unclosedEarnings) > 0.005) {
                $groups['equity'][] = [
                    'code' => '—',
                    'name' => 'أرباح الفترة الحالية (غير مقفلة)',
                    'amount' => $unclosedEarnings,
                ];
            }
            $totals = [
                'asset' => array_sum(array_column($groups['asset'], 'amount')),
                'liability' => array_sum(array_column($groups['liability'], 'amount')),
                'equity' => array_sum(array_column($groups['equity'], 'amount')),
            ];
            echo json_encode(['ok' => true, 'as_of' => $asOf, 'groups' => $groups, 'totals' => $totals]);
        }

        // ══════════ الأرباح والخسائر ══════════
        // ── أقدم تاريخ قيد مرحّل فعلياً — أساس زر "منذ البداية" (بيانات
        // حقيقية، مش تاريخ إنشاء الفرع التقني اللي ممكن يستبعد قيود
        // تاريخية حقيقية أقدم منه) ──
        elseif ($act === 'get_earliest_entry_date') {
            $minDate = $pdo->query("SELECT MIN(entry_date) FROM `{$TJE}` WHERE status='posted'")->fetchColumn();
            echo json_encode(['ok' => true, 'date' => $minDate ?: date('Y-m-01')]);
        }

        elseif ($act === 'get_income_statement') {
            $from = trim($_POST['from_date'] ?? '') ?: date('Y-m-01');
            $to = trim($_POST['to_date'] ?? '') ?: date('Y-m-d');
            if ($from > $to)
                throw new Exception('تاريخ البداية لازم يكون قبل تاريخ النهاية');

            $movement = calcPeriodMovement($pdo, $TJE, $TJI, $from, $to);
            $accounts = $pdo->query("SELECT id,code,name,account_type FROM `{$TAC}`
                WHERE is_active=1 AND account_type IN ('revenue','expense') ORDER BY code")->fetchAll();

            $revenue = [];
            $expense = [];
            foreach ($accounts as $a) {
                $net = $movement[$a['id']] ?? 0;
                if (abs($net) < 0.005)
                    continue;
                if ($a['account_type'] === 'revenue') {
                    $revenue[] = ['code' => $a['code'], 'name' => $a['name'], 'amount' => -$net]; // الإيراد دائن الطبيعة
                } else {
                    $expense[] = ['code' => $a['code'], 'name' => $a['name'], 'amount' => $net];
                }
            }
            $totalRevenue = array_sum(array_column($revenue, 'amount'));
            $totalExpense = array_sum(array_column($expense, 'amount'));
            echo json_encode(['ok' => true, 'from' => $from, 'to' => $to,
                'revenue' => $revenue, 'expense' => $expense,
                'total_revenue' => $totalRevenue, 'total_expense' => $totalExpense,
                'net_income' => $totalRevenue - $totalExpense]);
        }

        // ══════════ التدفقات النقدية ══════════
        elseif ($act === 'get_cash_flow') {
            $from = trim($_POST['from_date'] ?? '') ?: date('Y-m-01');
            $to = trim($_POST['to_date'] ?? '') ?: date('Y-m-d');
            if ($from > $to)
                throw new Exception('تاريخ البداية لازم يكون قبل تاريخ النهاية');

            $cashIds = $pdo->query("SELECT account_id FROM `{$TIAS}`
                WHERE setting_key LIKE 'cash_%' OR setting_key LIKE 'bank_%'")->fetchAll(PDO::FETCH_COLUMN);
            if (empty($cashIds)) {
                throw new Exception('ماكو حسابات صناديق/بنوك مضبوطة بإعدادات الربط المحاسبي بعد');
            }
            $cashIdsList = implode(',', array_map('intval', $cashIds));

            $dayBefore = date('Y-m-d', strtotime($from . ' -1 day'));
            $openingMap = calcBalancesAsOf($pdo, $TAC, $TJE, $TJI, $dayBefore);
            $closingMap = calcBalancesAsOf($pdo, $TAC, $TJE, $TJI, $to);
            $opening = 0;
            $closing = 0;
            foreach ($cashIds as $cid) {
                $opening += $openingMap[$cid] ?? 0;
                $closing += $closingMap[$cid] ?? 0;
            }

            // كل القيود المرحّلة بالفترة يلي فيها سطر صندوق/بنك بأثر غير صفري
            $entriesSt = $pdo->prepare("SELECT ji.journal_entry_id AS je_id,
                    SUM(ji.debit - ji.credit) AS cash_net
                FROM `{$TJI}` ji JOIN `{$TJE}` je ON je.id = ji.journal_entry_id
                WHERE je.status = 'posted' AND je.entry_date BETWEEN ? AND ?
                    AND ji.account_id IN ({$cashIdsList})
                GROUP BY ji.journal_entry_id
                HAVING ABS(cash_net) > 0.005");
            $entriesSt->execute([$from, $to]);
            $entries = $entriesSt->fetchAll();

            $catTotals = ['operating' => 0, 'investing' => 0, 'financing' => 0, 'none' => 0];
            $catDetail = ['operating' => [], 'investing' => [], 'financing' => [], 'none' => []];

            $lineSt = $pdo->prepare("SELECT ji.account_id, ji.debit, ji.credit, ac.name, ac.code, ac.cash_flow_category
                FROM `{$TJI}` ji JOIN `{$TAC}` ac ON ac.id = ji.account_id
                WHERE ji.journal_entry_id = ? AND ji.account_id NOT IN ({$cashIdsList})
                ORDER BY ABS(ji.debit - ji.credit) DESC LIMIT 1");
            $jeInfoSt = $pdo->prepare("SELECT entry_number, entry_date, description FROM `{$TJE}` WHERE id=?");

            foreach ($entries as $e) {
                $lineSt->execute([$e['je_id']]);
                $dominant = $lineSt->fetch();
                $cat = $dominant['cash_flow_category'] ?? 'none';
                if (!isset($catTotals[$cat]))
                    $cat = 'none';
                $catTotals[$cat] += (float) $e['cash_net'];

                $jeInfoSt->execute([$e['je_id']]);
                $jeInfo = $jeInfoSt->fetch();
                $catDetail[$cat][] = [
                    'entry_number' => $jeInfo['entry_number'] ?? '',
                    'entry_date' => $jeInfo['entry_date'] ?? '',
                    'description' => $jeInfo['description'] ?? '',
                    'other_account' => $dominant ? ($dominant['code'] . ' — ' . $dominant['name']) : '—',
                    'amount' => (float) $e['cash_net'],
                ];
            }

            echo json_encode(['ok' => true, 'from' => $from, 'to' => $to,
                'opening' => $opening, 'closing' => $closing,
                'net_change' => $closing - $opening,
                'categories' => $catTotals, 'detail' => $catDetail]);
        }

        else
            throw new Exception('إجراء غير معروف');
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

$baseSym = '$';
if (!empty($_SESSION['branch_id'])) {
    $bcStmt = $pdo->prepare("SELECT c.symbol FROM branches b
        LEFT JOIN currencies c ON c.id = b.base_currency_id WHERE b.id = ?");
    $bcStmt->execute([$_SESSION['branch_id']]);
    $baseSym = $bcStmt->fetchColumn() ?: '$';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>التقارير المالية — <?= htmlspecialchars($branchName) ?></title>
    <link rel="icon" href="<?= BASE_PATH ?>/assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/assets/css/layout.css" rel="stylesheet">
    <style>
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
            font-size: .84rem
        }

        table.mtbl th {
            background: #f8fafc;
            padding: 8px 12px;
            font-size: .74rem;
            font-weight: 700;
            color: #64748b;
            text-align: right
        }

        table.mtbl td {
            padding: 8px 12px;
            border-top: 1px solid #f1f5f9
        }

        .rep-group-hdr td {
            background: #eff6ff;
            font-weight: 700;
            color: #1e3a8a;
            font-size: .8rem
        }

        .rep-total-row td {
            background: #f8fafc;
            font-weight: 700;
            border-top: 2px solid #e2e8f0
        }

        .field-lbl {
            font-size: .75rem;
            font-weight: 600;
            color: #475569;
            margin-bottom: 4px;
            display: block
        }

        .n {
            direction: ltr;
            unicode-bidi: plaintext
        }

        .sub-nav {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
            flex-wrap: wrap
        }

        .sub-nav button {
            padding: 8px 16px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            background: #fff;
            font-size: .82rem;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: .15s
        }

        .sub-nav button.active {
            background: #1e3a8a;
            color: #fff;
            border-color: #1e3a8a
        }

        .kpi-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 16px;
            text-align: center
        }

        .kpi-val {
            font-size: 1.3rem;
            font-weight: 700
        }

        .kpi-lbl {
            font-size: .75rem;
            color: #64748b;
            margin-top: 4px
        }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-bar-chart-line me-1 text-primary"></i>التقارير المالية</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
            <span>المالية</span>
            <i class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
            <span class="text-primary fw-600">التقارير المالية</span>
        </nav>
    </header>
    <main class="main-content">
        <div class="content-body">

            <!-- تبويبات قسم المالية (مكوّن مشترك — يتبع الشريط الجانبي) -->
            <?php require __DIR__ . '/../../../includes/tab_bar.php'; ?>

            <!-- تبديل التقرير -->
            <div class="sub-nav">
                <button id="btnRepBS" class="active" onclick="showReport('bs')">
                    <i class="bi bi-columns-gap me-1"></i>الميزانية العمومية
                </button>
                <button id="btnRepPL" onclick="showReport('pl')">
                    <i class="bi bi-graph-up me-1"></i>الأرباح والخسائر
                </button>
                <button id="btnRepCF" onclick="showReport('cf')">
                    <i class="bi bi-arrow-left-right me-1"></i>التدفقات النقدية
                </button>
            </div>

            <!-- ═══════ الميزانية العمومية ═══════ -->
            <div id="repBS">
                <div class="tbl-wrap mb-3">
                    <div class="tbl-hdr">
                        <span style="font-size:.9rem;font-weight:700;color:#1e293b">
                            <i class="bi bi-calendar-date me-2 text-primary"></i>بتاريخ
                        </span>
                        <button class="btn btn-sm btn-light" style="border-radius:8px" onclick="shiftBsDate(-1)" title="اليوم السابق">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                        <input type="date" id="bsDate" class="form-control form-control-sm" style="width:160px" onchange="loadBalanceSheet()">
                        <button class="btn btn-sm btn-light" style="border-radius:8px" onclick="shiftBsDate(1)" title="اليوم التالي">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <button class="btn btn-sm btn-primary" style="border-radius:8px" onclick="loadBalanceSheet()">
                            <i class="bi bi-search me-1"></i>عرض
                        </button>
                        <button class="btn btn-sm btn-light ms-auto" style="border-radius:8px" onclick="setBsToday()">
                            <i class="bi bi-clock-history me-1"></i>اليوم
                        </button>
                    </div>
                </div>
                <div id="bsBody"><div class="text-center py-5"><span class="spinner-border text-primary"></span></div></div>
            </div>

            <!-- ═══════ الأرباح والخسائر ═══════ -->
            <div id="repPL" style="display:none">
                <div class="tbl-wrap mb-3">
                    <div class="tbl-hdr">
                        <span style="font-size:.9rem;font-weight:700;color:#1e293b">
                            <i class="bi bi-calendar-range me-2 text-primary"></i>من
                        </span>
                        <input type="date" id="plFrom" class="form-control form-control-sm" style="width:160px">
                        <span>إلى</span>
                        <input type="date" id="plTo" class="form-control form-control-sm" style="width:160px">
                        <button class="btn btn-sm btn-primary" style="border-radius:8px" onclick="loadIncomeStatement()">
                            <i class="bi bi-search me-1"></i>عرض
                        </button>
                        <button class="btn btn-sm btn-light" style="border-radius:8px" onclick="setPlSinceInception()">
                            <i class="bi bi-hourglass-split me-1"></i>منذ البداية
                        </button>
                        <button class="btn btn-sm btn-light ms-auto" style="border-radius:8px" onclick="setPlThisMonth()">
                            <i class="bi bi-calendar-month me-1"></i>الشهر الحالي
                        </button>
                    </div>
                </div>
                <div id="plBody">
                    <div class="text-center text-muted py-5" style="font-size:.85rem">
                        <i class="bi bi-calendar-range d-block mb-2" style="font-size:1.8rem;opacity:.3"></i>
                        اختر فترة (من-إلى) واضغط "عرض"، أو "الشهر الحالي" كاختصار
                    </div>
                </div>
            </div>

            <!-- ═══════ التدفقات النقدية ═══════ -->
            <div id="repCF" style="display:none">
                <div class="tbl-wrap mb-3">
                    <div class="tbl-hdr">
                        <span style="font-size:.9rem;font-weight:700;color:#1e293b">
                            <i class="bi bi-calendar-range me-2 text-primary"></i>من
                        </span>
                        <input type="date" id="cfFrom" class="form-control form-control-sm" style="width:160px">
                        <span>إلى</span>
                        <input type="date" id="cfTo" class="form-control form-control-sm" style="width:160px">
                        <button class="btn btn-sm btn-primary" style="border-radius:8px" onclick="loadCashFlow()">
                            <i class="bi bi-search me-1"></i>عرض
                        </button>
                        <button class="btn btn-sm btn-light" style="border-radius:8px" onclick="setCfSinceInception()">
                            <i class="bi bi-hourglass-split me-1"></i>منذ البداية
                        </button>
                        <button class="btn btn-sm btn-light ms-auto" style="border-radius:8px" onclick="setCfThisMonth()">
                            <i class="bi bi-calendar-month me-1"></i>الشهر الحالي
                        </button>
                    </div>
                </div>
                <div id="cfBody">
                    <div class="text-center text-muted py-5" style="font-size:.85rem">
                        <i class="bi bi-calendar-range d-block mb-2" style="font-size:1.8rem;opacity:.3"></i>
                        اختر فترة (من-إلى) واضغط "عرض"، أو "الشهر الحالي" كاختصار
                    </div>
                </div>
            </div>

        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const sb = document.getElementById('sidebar'), ov = document.getElementById('sbOverlay');
        function sbOpen() { sb.classList.add('open'); ov.classList.add('show'); }
        function sbClose() { sb.classList.remove('open'); ov.classList.remove('show'); }
        window.addEventListener('resize', () => { if (window.innerWidth > 991) sbClose(); });
        function toggleGroup(g) { const o = g.classList.contains('open'); document.querySelectorAll('.sb-group.open').forEach(x => x.classList.remove('open')); g.classList.toggle('open', !o); localStorage.setItem('sb_open_' + g.dataset.key, (!o).toString()); }
        document.querySelectorAll('.sb-group').forEach(g => { if (localStorage.getItem('sb_open_' + g.dataset.key) === 'true') g.classList.add('open'); });

        const BASE_SYM = <?= json_encode($baseSym) ?>;

        // ⚠ toISOString() بيحوّل للتوقيت العالمي UTC أول — بمنطقة زمنية
        // متقدّمة عن UTC (دمشق UTC+3)، منتصف الليل المحلي بيصير بتاريخ
        // اليوم السابق بالـUTC، فبيطلع تاريخ غلط. هالدالة بتنسّق التاريخ
        // بالتوقيت المحلي مباشرة، بدون أي تحويل UTC.
        function toLocalDateStr(d) {
            const y = d.getFullYear();
            const m = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            return `${y}-${m}-${day}`;
        }

        function post(data) {
            const fd = new FormData();
            Object.entries(data).forEach(([k, v]) => fd.append(k, v ?? ''));
            return fetch(location.href, { method: 'POST', body: fd }).then(r => r.json());
        }
        function toast(msg, type = 'danger') {
            const t = document.createElement('div'); t.className = `alert alert-${type} shadow`;
            t.style.cssText = 'position:fixed;top:70px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:240px;text-align:center;font-size:.83rem;padding:.5rem 1.2rem';
            t.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-2"></i>${msg}`;
            document.body.appendChild(t); setTimeout(() => t.remove(), 3500);
        }
        function fmt(n) { return (parseFloat(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

        function showReport(rep) {
            document.getElementById('repBS').style.display = rep === 'bs' ? '' : 'none';
            document.getElementById('repPL').style.display = rep === 'pl' ? '' : 'none';
            document.getElementById('repCF').style.display = rep === 'cf' ? '' : 'none';
            document.getElementById('btnRepBS').classList.toggle('active', rep === 'bs');
            document.getElementById('btnRepPL').classList.toggle('active', rep === 'pl');
            document.getElementById('btnRepCF').classList.toggle('active', rep === 'cf');
        }

        // ══════════ الميزانية العمومية ══════════
        function setBsToday() {
            document.getElementById('bsDate').value = toLocalDateStr(new Date());
            loadBalanceSheet();
        }
        function shiftBsDate(days) {
            const inp = document.getElementById('bsDate');
            const cur = inp.value ? new Date(inp.value + 'T00:00:00') : new Date();
            cur.setDate(cur.getDate() + days);
            inp.value = toLocalDateStr(cur);
            loadBalanceSheet();
        }
        function loadBalanceSheet() {
            document.getElementById('bsBody').innerHTML = '<div class="text-center py-5"><span class="spinner-border text-primary"></span></div>';
            post({ _action: 'get_balance_sheet', as_of_date: document.getElementById('bsDate').value }).then(d => {
                if (!d.ok) { document.getElementById('bsBody').innerHTML = ''; toast(d.msg); return; }
                const groupRows = (items, cls) => items.map(a => `
            <tr><td class="n" style="direction:ltr">${a.code}</td><td>${a.name}</td>
                <td class="n text-end ${cls}">${BASE_SYM} ${fmt(a.amount)}</td></tr>`).join('');

                document.getElementById('bsBody').innerHTML = `
            <div class="row g-3 mb-3">
                <div class="col-md-4"><div class="kpi-card"><div class="kpi-val text-primary n">${BASE_SYM} ${fmt(d.totals.asset)}</div><div class="kpi-lbl">إجمالي الأصول</div></div></div>
                <div class="col-md-4"><div class="kpi-card"><div class="kpi-val text-danger n">${BASE_SYM} ${fmt(d.totals.liability)}</div><div class="kpi-lbl">إجمالي الالتزامات</div></div></div>
                <div class="col-md-4"><div class="kpi-card"><div class="kpi-val text-success n">${BASE_SYM} ${fmt(d.totals.equity)}</div><div class="kpi-lbl">إجمالي حقوق الملكية</div></div></div>
            </div>
            ${Math.abs(d.totals.asset - (d.totals.liability + d.totals.equity)) > 0.5 ? `
            <div class="alert alert-warning py-2 mb-3" style="font-size:.78rem;border-radius:10px">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                الأصول (${BASE_SYM}${fmt(d.totals.asset)}) لا تساوي الالتزامات + حقوق الملكية (${BASE_SYM}${fmt(d.totals.liability + d.totals.equity)}) —
                فرق ${BASE_SYM}${fmt(Math.abs(d.totals.asset - (d.totals.liability + d.totals.equity)))}. راجع القيود غير المتوازنة أو المسودات غير المرحّلة.
            </div>` : ''}
            <div class="tbl-wrap">
                <div class="table-responsive">
                    <table class="mtbl">
                        <thead><tr><th>الرمز</th><th>الحساب</th><th class="text-end">الرصيد</th></tr></thead>
                        <tbody>
                            <tr class="rep-group-hdr"><td colspan="3"><i class="bi bi-box me-1"></i>الأصول</td></tr>
                            ${groupRows(d.groups.asset, 'text-primary') || '<tr><td colspan="3" class="text-center text-muted py-2">لا يوجد</td></tr>'}
                            <tr class="rep-total-row"><td colspan="2">إجمالي الأصول</td><td class="n text-end">${BASE_SYM} ${fmt(d.totals.asset)}</td></tr>
                            <tr class="rep-group-hdr"><td colspan="3"><i class="bi bi-credit-card me-1"></i>الالتزامات</td></tr>
                            ${groupRows(d.groups.liability, 'text-danger') || '<tr><td colspan="3" class="text-center text-muted py-2">لا يوجد</td></tr>'}
                            <tr class="rep-total-row"><td colspan="2">إجمالي الالتزامات</td><td class="n text-end">${BASE_SYM} ${fmt(d.totals.liability)}</td></tr>
                            <tr class="rep-group-hdr"><td colspan="3"><i class="bi bi-piggy-bank me-1"></i>حقوق الملكية</td></tr>
                            ${groupRows(d.groups.equity, 'text-success') || '<tr><td colspan="3" class="text-center text-muted py-2">لا يوجد</td></tr>'}
                            <tr class="rep-total-row"><td colspan="2">إجمالي حقوق الملكية</td><td class="n text-end">${BASE_SYM} ${fmt(d.totals.equity)}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>`;
            });
        }

        // ══════════ الأرباح والخسائر ══════════
        function setPlThisMonth() {
            const now = new Date();
            document.getElementById('plFrom').value = toLocalDateStr(new Date(now.getFullYear(), now.getMonth(), 1));
            document.getElementById('plTo').value = toLocalDateStr(now);
            loadIncomeStatement();
        }
        function setPlSinceInception() {
            post({ _action: 'get_earliest_entry_date' }).then(d => {
                if (!d.ok) { toast(d.msg); return; }
                document.getElementById('plFrom').value = d.date;
                document.getElementById('plTo').value = toLocalDateStr(new Date());
                loadIncomeStatement();
            });
        }
        function loadIncomeStatement() {
            document.getElementById('plBody').innerHTML = '<div class="text-center py-5"><span class="spinner-border text-primary"></span></div>';
            post({ _action: 'get_income_statement', from_date: document.getElementById('plFrom').value, to_date: document.getElementById('plTo').value }).then(d => {
                if (!d.ok) { document.getElementById('plBody').innerHTML = ''; toast(d.msg); return; }
                const rows = (items, cls) => items.map(a => `
            <tr><td class="n" style="direction:ltr">${a.code}</td><td>${a.name}</td>
                <td class="n text-end ${cls}">${BASE_SYM} ${fmt(a.amount)}</td></tr>`).join('');
                const netColor = d.net_income >= 0 ? 'text-success' : 'text-danger';
                document.getElementById('plBody').innerHTML = `
            <div class="row g-3 mb-3">
                <div class="col-md-4"><div class="kpi-card"><div class="kpi-val text-success n">${BASE_SYM} ${fmt(d.total_revenue)}</div><div class="kpi-lbl">إجمالي الإيرادات</div></div></div>
                <div class="col-md-4"><div class="kpi-card"><div class="kpi-val text-danger n">${BASE_SYM} ${fmt(d.total_expense)}</div><div class="kpi-lbl">إجمالي المصاريف</div></div></div>
                <div class="col-md-4"><div class="kpi-card"><div class="kpi-val ${netColor} n">${BASE_SYM} ${fmt(d.net_income)}</div><div class="kpi-lbl">${d.net_income >= 0 ? 'صافي الربح' : 'صافي الخسارة'}</div></div></div>
            </div>
            <div class="tbl-wrap">
                <div class="table-responsive">
                    <table class="mtbl">
                        <thead><tr><th>الرمز</th><th>الحساب</th><th class="text-end">المبلغ</th></tr></thead>
                        <tbody>
                            <tr class="rep-group-hdr"><td colspan="3"><i class="bi bi-graph-up-arrow me-1"></i>الإيرادات</td></tr>
                            ${rows(d.revenue, 'text-success') || '<tr><td colspan="3" class="text-center text-muted py-2">لا يوجد</td></tr>'}
                            <tr class="rep-total-row"><td colspan="2">إجمالي الإيرادات</td><td class="n text-end">${BASE_SYM} ${fmt(d.total_revenue)}</td></tr>
                            <tr class="rep-group-hdr"><td colspan="3"><i class="bi bi-graph-down-arrow me-1"></i>المصاريف</td></tr>
                            ${rows(d.expense, 'text-danger') || '<tr><td colspan="3" class="text-center text-muted py-2">لا يوجد</td></tr>'}
                            <tr class="rep-total-row"><td colspan="2">إجمالي المصاريف</td><td class="n text-end">${BASE_SYM} ${fmt(d.total_expense)}</td></tr>
                            <tr class="rep-total-row" style="background:#eff6ff"><td colspan="2" class="text-primary">${d.net_income >= 0 ? 'صافي الربح' : 'صافي الخسارة'}</td><td class="n text-end text-primary">${BASE_SYM} ${fmt(d.net_income)}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>`;
            });
        }

        // ══════════ التدفقات النقدية ══════════
        function setCfThisMonth() {
            const now = new Date();
            document.getElementById('cfFrom').value = toLocalDateStr(new Date(now.getFullYear(), now.getMonth(), 1));
            document.getElementById('cfTo').value = toLocalDateStr(now);
            loadCashFlow();
        }
        function setCfSinceInception() {
            post({ _action: 'get_earliest_entry_date' }).then(d => {
                if (!d.ok) { toast(d.msg); return; }
                document.getElementById('cfFrom').value = d.date;
                document.getElementById('cfTo').value = toLocalDateStr(new Date());
                loadCashFlow();
            });
        }
        function loadCashFlow() {
            document.getElementById('cfBody').innerHTML = '<div class="text-center py-5"><span class="spinner-border text-primary"></span></div>';
            post({ _action: 'get_cash_flow', from_date: document.getElementById('cfFrom').value, to_date: document.getElementById('cfTo').value }).then(d => {
                if (!d.ok) { document.getElementById('cfBody').innerHTML = ''; toast(d.msg); return; }
                const catLabel = { operating: 'الأنشطة التشغيلية', investing: 'الأنشطة الاستثمارية', financing: 'الأنشطة التمويلية', none: 'غير مصنَّف' };
                const catIcon = { operating: 'bi-gear', investing: 'bi-building', financing: 'bi-bank', none: 'bi-question-circle' };
                let catsHtml = '';
                ['operating', 'investing', 'financing', 'none'].forEach(cat => {
                    if (cat === 'none' && Math.abs(d.categories[cat]) < 0.01 && !d.detail[cat].length) return;
                    const rows = d.detail[cat].map(x => `
                <tr><td class="n" style="font-size:.76rem">${x.entry_date}</td>
                    <td class="n" style="font-size:.76rem;direction:ltr">${x.entry_number}</td>
                    <td style="font-size:.78rem">${x.other_account}</td>
                    <td class="n text-end ${x.amount >= 0 ? 'text-success' : 'text-danger'}">${BASE_SYM} ${fmt(x.amount)}</td></tr>`).join('');
                    catsHtml += `
                <div class="tbl-wrap mb-3">
                    <div class="tbl-hdr">
                        <span style="font-size:.86rem;font-weight:700;color:#1e293b"><i class="bi ${catIcon[cat]} me-2 text-primary"></i>${catLabel[cat]}</span>
                        <span class="n fw-700 ms-auto ${d.categories[cat] >= 0 ? 'text-success' : 'text-danger'}">${BASE_SYM} ${fmt(d.categories[cat])}</span>
                    </div>
                    <div class="table-responsive">
                        <table class="mtbl">
                            <thead><tr><th>التاريخ</th><th>رقم القيد</th><th>الطرف الآخر</th><th class="text-end">الأثر النقدي</th></tr></thead>
                            <tbody>${rows || '<tr><td colspan="4" class="text-center text-muted py-2" style="font-size:.8rem">لا توجد حركة</td></tr>'}</tbody>
                        </table>
                    </div>
                </div>`;
                });

                document.getElementById('cfBody').innerHTML = `
            <div class="row g-3 mb-3">
                <div class="col-md-3"><div class="kpi-card"><div class="kpi-val n">${BASE_SYM} ${fmt(d.opening)}</div><div class="kpi-lbl">الرصيد الافتتاحي</div></div></div>
                <div class="col-md-3"><div class="kpi-card"><div class="kpi-val ${d.net_change >= 0 ? 'text-success' : 'text-danger'} n">${BASE_SYM} ${fmt(d.net_change)}</div><div class="kpi-lbl">صافي التغيّر</div></div></div>
                <div class="col-md-3"><div class="kpi-card"><div class="kpi-val text-primary n">${BASE_SYM} ${fmt(d.closing)}</div><div class="kpi-lbl">الرصيد الختامي</div></div></div>
                <div class="col-md-3"><div class="kpi-card"><div class="kpi-val n">${BASE_SYM} ${fmt(d.categories.operating + d.categories.investing + d.categories.financing + d.categories.none)}</div><div class="kpi-lbl">مجموع الحركة المصنَّفة</div></div></div>
            </div>
            ${catsHtml}`;
            });
        }

        // ── تحميل أولي ──
        // الميزانية العمومية = لحظة زمنية، منطقي تفتح على "اليوم" تلقائياً.
        // الأرباح/الخسائر والتدفقات النقدية = تقارير فترة، ما بتحمَّل
        // تلقائياً أبداً — تضل فاضية لحد ما تختار المستخدم فترة بنفسه
        // ويضغط "عرض" (أو زر "الشهر الحالي" كاختصار سريع).
        document.getElementById('bsDate').value = toLocalDateStr(new Date());
        loadBalanceSheet();
        showReport('bs');
    </script>

</body>

</html>
