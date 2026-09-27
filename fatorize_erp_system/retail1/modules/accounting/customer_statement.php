<?php
/**
 * accounting/customer_statement.php — كشف حساب عميل
 * المسار: retail1/modules/accounting/customer_statement.php
 *
 * يُفتح دايماً بمعرِّف صريح: ?customer_id=5 (رابط من customers.php).
 * بيسحب حركة الحساب المخصص (account_id) لهيك عميل مباشرة من
 * journal_entry_items — نفس منطق "حركة الحساب" المبني أصلاً بـ
 * accounts.php، بس بعرض تجاري (اسم العميل، فلترة بعدد عمليات أو تاريخ)
 * بدل عرض محاسبي خام.
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('finance.reports', 'view');
$currentModule = 'finance.reports';

$TS = $_SESSION['table_suffix'];
$TC = "customers_{$TS}";
$TSP = "product_suppliers_{$TS}";
$TAC = "account_charts_{$TS}";
$TJE = "journal_entries_{$TS}";
$TJI = "journal_entry_items_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

function loadEntity(PDO $pdo, string $type, int $id, string $TC, string $TSP, string $TAC): ?array
{
    $table = $type === 'customer' ? $TC : $TSP;
    $st = $pdo->prepare("SELECT e.*, ac.code AS acc_code, ac.name AS acc_name, ac.balance AS acc_balance,
            ac.base_balance AS acc_base_balance, ac.currency_id AS acc_currency_id,
            cur.symbol AS acc_sym, cur.code AS acc_cur_code,
            adv.code AS adv_code, adv.name AS adv_name, adv.balance AS adv_balance, adv.base_balance AS adv_base_balance
        FROM `{$table}` e
        LEFT JOIN `{$TAC}` ac ON ac.id = e.account_id
        LEFT JOIN currencies cur ON cur.id = ac.currency_id
        LEFT JOIN `{$TAC}` adv ON adv.id = e.prepaid_account_id
        WHERE e.id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        if ($act === 'get_statement') {
            $type = 'customer';
            $id = (int) ($_POST['customer_id'] ?? 0);
            $entity = loadEntity($pdo, $type, $id, $TC, $TSP, $TAC);
            if (!$entity)
                throw new Exception('غير موجود');
            if (!$entity['account_id'])
                throw new Exception('هذا العميل ما إله حساب ذمة مخصص بعد');

            $dateFrom = trim($_POST['date_from'] ?? '');
            $dateTo = trim($_POST['date_to'] ?? '');
            $limit = (int) ($_POST['limit'] ?? 0); // آخر N عملية — يتجاهل التاريخ لو محدَّد
            $currencyFilter = (int) ($_POST['currency_filter'] ?? 0) ?: null;

            $accId = (int) $entity['account_id'];
            // ⚠ لما نفلتر بعملة محدَّدة، الرصيد التراكمي بيصير بعملة تلك
            // العملة نفسها (original_amount)، مش بعملة الفرع (base) — منطقي
            // لأنه كل الأسطر هلق بنفس العملة، فتجميعها بقيمتها الحقيقية صحيح.
            $balCol = $currencyFilter ? "(CASE WHEN ji.debit > 0 THEN ji.original_amount ELSE -ji.original_amount END)" : "(ji.debit - ji.credit)";

            $opening = 0;
            if ($dateFrom && !$limit) {
                $obWhere = "ji.account_id = ? AND je.status = 'posted' AND je.entry_date < ?";
                $obParams = [$accId, $dateFrom];
                if ($currencyFilter) {
                    $obWhere .= " AND ji.currency_id = ?";
                    $obParams[] = $currencyFilter;
                }
                $ob = $pdo->prepare("SELECT COALESCE(SUM({$balCol}),0)
                    FROM `{$TJI}` ji JOIN `{$TJE}` je ON je.id = ji.journal_entry_id
                    WHERE {$obWhere}");
                $ob->execute($obParams);
                $opening = (float) $ob->fetchColumn();
            }

            if ($limit > 0) {
                $lWhere = "ji.account_id = ? AND je.status = 'posted'";
                $lParams = [$accId];
                if ($currencyFilter) {
                    $lWhere .= " AND ji.currency_id = ?";
                    $lParams[] = $currencyFilter;
                }
                // آخر N عملية: نجيبها بترتيب عكسي، وبعدين نرجّعها لترتيب زمني عادي
                $st = $pdo->prepare("SELECT ji.*, cur.code AS cur_code, cur.symbol AS cur_sym,
                    je.entry_number, je.entry_date, je.description AS entry_desc, je.reference_type
                    FROM `{$TJI}` ji JOIN `{$TJE}` je ON je.id = ji.journal_entry_id
                    LEFT JOIN currencies cur ON cur.id = ji.currency_id
                    WHERE {$lWhere}
                    ORDER BY je.entry_date DESC, je.id DESC LIMIT {$limit}");
                $st->execute($lParams);
                $rows = array_reverse($st->fetchAll());
                // الرصيد الافتتاحي لعرض "آخر N" = الرصيد قبل أقدم عملية بالنتيجة
                if ($rows) {
                    $firstDate = $rows[0]['entry_date'];
                    $firstId = $rows[0]['journal_entry_id'];
                    $obWhere2 = "ji.account_id = ? AND je.status = 'posted'
                        AND (je.entry_date < ? OR (je.entry_date = ? AND je.id < ?))";
                    $obParams2 = [$accId, $firstDate, $firstDate, $firstId];
                    if ($currencyFilter) {
                        $obWhere2 .= " AND ji.currency_id = ?";
                        $obParams2[] = $currencyFilter;
                    }
                    $ob = $pdo->prepare("SELECT COALESCE(SUM({$balCol}),0)
                        FROM `{$TJI}` ji JOIN `{$TJE}` je ON je.id = ji.journal_entry_id
                        WHERE {$obWhere2}");
                    $ob->execute($obParams2);
                    $opening = (float) $ob->fetchColumn();
                }
            } else {
                $where = "ji.account_id = ? AND je.status = 'posted'";
                $params = [$accId];
                if ($dateFrom) {
                    $where .= " AND je.entry_date >= ?";
                    $params[] = $dateFrom;
                }
                if ($dateTo) {
                    $where .= " AND je.entry_date <= ?";
                    $params[] = $dateTo;
                }
                if ($currencyFilter) {
                    $where .= " AND ji.currency_id = ?";
                    $params[] = $currencyFilter;
                }
                $st = $pdo->prepare("SELECT ji.*, cur.code AS cur_code, cur.symbol AS cur_sym,
                    je.entry_number, je.entry_date, je.description AS entry_desc, je.reference_type
                    FROM `{$TJI}` ji JOIN `{$TJE}` je ON je.id = ji.journal_entry_id
                    LEFT JOIN currencies cur ON cur.id = ji.currency_id
                    WHERE {$where}
                    ORDER BY je.entry_date, je.id");
                $st->execute($params);
                $rows = $st->fetchAll();
            }

            $running = $opening;
            foreach ($rows as &$r) {
                $running += $currencyFilter
                    ? ((float) $r['debit'] > 0 ? (float) $r['original_amount'] : -(float) $r['original_amount'])
                    : ((float) $r['debit'] - (float) $r['credit']);
                $r['running_balance'] = $running;
            }
            unset($r);

            // ── الرصيد الفعلي حسب كل عملة حقيقية — محسوب مباشرة من
            // original_amount (المبلغ الحقيقي بعملته الأصلية وقت كل
            // عملية)، مش من تحويل base_amount لعملة الفرع. هذا مستقل عن
            // فلتر التاريخ/العدد فوق — دايماً يعكس الصورة الكاملة الحالية. ──
            $byCurrSt = $pdo->prepare("SELECT ji.currency_id, cur.code, cur.symbol,
                    SUM(CASE WHEN ji.debit > 0 THEN ji.original_amount ELSE -ji.original_amount END) AS net
                FROM `{$TJI}` ji JOIN `{$TJE}` je ON je.id = ji.journal_entry_id
                LEFT JOIN currencies cur ON cur.id = ji.currency_id
                WHERE ji.account_id = ? AND je.status = 'posted'
                GROUP BY ji.currency_id, cur.code, cur.symbol
                HAVING ABS(net) > 0.005");
            $byCurrSt->execute([$accId]);
            $byCurrency = $byCurrSt->fetchAll();

            $filterCurSym = null;
            $filterCurCode = null;
            if ($currencyFilter) {
                $fc = $pdo->prepare("SELECT symbol, code FROM currencies WHERE id=?");
                $fc->execute([$currencyFilter]);
                $fc = $fc->fetch();
                $filterCurSym = $fc['symbol'] ?: ($fc['code'] ?? null);
                $filterCurCode = $fc['code'] ?? null;
            }

            echo json_encode(['ok' => true, 'entity' => [
                'name' => $entity['name'],
                'phone' => $entity['phone'] ?? '',
                'acc_code' => $entity['acc_code'],
                'acc_name' => $entity['acc_name'],
                'acc_sym' => $entity['acc_sym'] ?? '$',
                'acc_balance' => (float) ($entity['acc_balance'] ?? 0),
                'acc_base_balance' => (float) ($entity['acc_base_balance'] ?? 0),
                'adv_balance' => (float) ($entity['adv_balance'] ?? 0),
                'adv_base_balance' => (float) ($entity['adv_base_balance'] ?? 0),
                'has_advance' => !empty($entity['prepaid_account_id']),
            ], 'opening_balance' => $opening, 'movements' => $rows, 'closing_balance' => $running,
                'by_currency' => $byCurrency, 'filter_currency_symbol' => $filterCurSym, 'filter_currency_code' => $filterCurCode]);
        } else
            throw new Exception('إجراء غير معروف');
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

$type = 'customer';
$id = (int) ($_GET['customer_id'] ?? 0);
if (!$id) {
    die('<div dir="rtl" style="font-family:sans-serif;padding:40px;text-align:center;color:#dc2626">
        رابط غير صالح — لازم يُفتح كشف الحساب من صفحة إدارة العملاء مباشرة.
        <br><a href="customers.php">رجوع للعملاء</a></div>');
}
$entity = loadEntity($pdo, $type, $id, $TC, $TSP, $TAC);
if (!$entity) {
    die('<div dir="rtl" style="font-family:sans-serif;padding:40px;text-align:center;color:#dc2626">
        العميل غير موجود.</div>');
}
$typeLabel = 'العميل';
$backLink = 'customers.php';
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
    <title>كشف حساب <?= htmlspecialchars($entity['name']) ?> — <?= htmlspecialchars($branchName) ?></title>
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
            font-size: .82rem
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

        .kpi-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 16px;
            text-align: center
        }

        .kpi-val {
            font-size: 1.25rem;
            font-weight: 700
        }

        .kpi-lbl {
            font-size: .75rem;
            color: #64748b;
            margin-top: 4px
        }

        @media print {

            .sidebar,
            .topbar,
            .tbl-hdr,
            .no-print {
                display: none !important
            }

            .main-content {
                margin: 0 !important
            }
        }
    </style>
</head>

<body>
    <div class="sb-overlay no-print" id="sbOverlay" onclick="sbClose()"></div>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
    <header class="topbar no-print">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-file-earmark-text me-1 text-primary"></i>كشف حساب <?= $typeLabel ?></span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
            <a href="<?= $backLink ?>" style="color:#64748b;text-decoration:none"><?= $type === 'customer' ? 'العملاء' : 'الموردين' ?></a>
            <i class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
            <span class="text-primary fw-600">كشف حساب</span>
        </nav>
    </header>
    <main class="main-content">
        <div class="content-body">

            <div class="d-flex align-items-center justify-content-between mb-3 no-print">
                <a href="<?= $backLink ?>" class="btn btn-sm btn-light" style="border-radius:8px">
                    <i class="bi bi-arrow-right me-1"></i>رجوع لـ<?= $type === 'customer' ? 'العملاء' : 'الموردين' ?>
                </a>
                <button class="btn btn-sm btn-primary" style="border-radius:8px" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i>طباعة
                </button>
            </div>

            <div class="tbl-wrap mb-3" style="padding:20px">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <div style="font-size:1.1rem;font-weight:700;color:#1e293b">
                            <i class="bi bi-<?= $type === 'customer' ? 'person' : 'truck' ?> me-2 text-primary"></i>
                            <?= htmlspecialchars($entity['name']) ?>
                        </div>
                        <div style="font-size:.8rem;color:#64748b;margin-top:4px">
                            <?= $typeLabel ?><?= !empty($entity['phone']) ? ' — ' . htmlspecialchars($entity['phone']) : '' ?>
                            <?php if ($entity['acc_code']): ?>
                                · حساب الذمة: <span class="n" dir="ltr"><?= htmlspecialchars($entity['acc_code']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="font-size:.72rem;color:#94a3b8">كشف بتاريخ <?= date('Y-m-d') ?></div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3">
                    <div class="kpi-card">
                        <div class="kpi-val n" style="color:<?= ($entity['acc_balance'] ?? 0) >= 0 ? '#16a34a' : '#dc2626' ?>">
                            <?= htmlspecialchars($entity['acc_sym'] ?? '$') ?> <?= number_format($entity['acc_balance'] ?? 0, 2) ?>
                        </div>
                        <div class="kpi-lbl">الرصيد الحالي (بعملة الحساب)</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="kpi-card">
                        <div class="kpi-val n">
                            <?= htmlspecialchars($baseSym) ?> <?= number_format($entity['acc_base_balance'] ?? 0, 2) ?>
                        </div>
                        <div class="kpi-lbl">الرصيد الحالي (بعملة الفرع)</div>
                    </div>
                </div>
                <?php if (!empty($entity['prepaid_account_id'])): ?>
                    <div class="col-6 col-md-3">
                        <div class="kpi-card">
                            <div class="kpi-val n text-primary"><?= htmlspecialchars($baseSym) ?> <?= number_format($entity['adv_base_balance'] ?? 0, 2) ?></div>
                            <div class="kpi-lbl">رصيد الدفعات المقدمة</div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div id="byCurrencyWrap" class="mb-3" style="display:none">
                <div style="font-size:.78rem;font-weight:700;color:#1e293b;margin-bottom:8px">
                    <i class="bi bi-cash-stack me-1 text-primary"></i>الرصيد الفعلي حسب العملة الحقيقية (غير محوَّل)
                </div>
                <div class="row g-2" id="byCurrencyCards"></div>
            </div>

            <div class="tbl-wrap mb-3 no-print">
                <div class="tbl-hdr">
                    <label class="field-lbl mb-0">من</label>
                    <input type="date" id="stFrom" class="form-control form-control-sm" style="width:150px">
                    <label class="field-lbl mb-0">إلى</label>
                    <input type="date" id="stTo" class="form-control form-control-sm" style="width:150px">
                    <span style="color:#94a3b8">أو</span>
                    <label class="field-lbl mb-0">آخر</label>
                    <input type="number" id="stLimit" class="form-control form-control-sm" style="width:90px" min="1" placeholder="عدد">
                    <span style="font-size:.78rem;color:#64748b">عملية</span>
                    <label class="field-lbl mb-0">عملة</label>
                    <select id="stCurrency" class="form-select form-select-sm" style="width:130px">
                        <option value="">كل العملات</option>
                    </select>
                    <button class="btn btn-sm btn-primary" style="border-radius:8px" onclick="loadStatement()">
                        <i class="bi bi-search me-1"></i>عرض
                    </button>
                    <button class="btn btn-sm btn-light ms-auto" style="border-radius:8px" onclick="resetFilters()">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>الكل
                    </button>
                </div>
            </div>

            <div id="stBody"><div class="text-center py-5"><span class="spinner-border text-primary"></span></div></div>

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

        const CUSTOMER_ID = <?= (int) $id ?>;
        const BASE_SYM = <?= json_encode($baseSym) ?>;

        function post(data) {
            const fd = new FormData();
            Object.entries(data).forEach(([k, v]) => fd.append(k, v ?? ''));
            return fetch(location.href, { method: 'POST', body: fd }).then(r => r.json());
        }
        function toast(msg) {
            const t = document.createElement('div'); t.className = 'alert alert-danger shadow';
            t.style.cssText = 'position:fixed;top:70px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:240px;text-align:center;font-size:.83rem;padding:.5rem 1.2rem';
            t.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-2"></i>${msg}`;
            document.body.appendChild(t); setTimeout(() => t.remove(), 3500);
        }
        function fmt(n) { return (parseFloat(n) || 0).toFixed(2); }

        function resetFilters() {
            document.getElementById('stFrom').value = '';
            document.getElementById('stTo').value = '';
            document.getElementById('stLimit').value = '';
            document.getElementById('stCurrency').value = '';
            loadStatement();
        }

        const REF_LABELS = {
            sale: 'فاتورة بيع', sale_cogs: 'تكلفة بيع', sale_payment: 'تحصيل',
            purchase: 'فاتورة شراء', purchase_payment: 'دفعة مورد',
            receipt: 'سند قبض', payment: 'سند دفع', transfer: 'تحويل', fee: 'رسم',
        };

        // ── تعبئة قائمة فلترة العملة — بس من العملات يلي فعلياً تعامل
        // فيها هذا العميل/المورد (من by_currency، دايماً غير مفلترة) ──
        function populateCurrencyFilter(byCur) {
            const sel = document.getElementById('stCurrency');
            const prev = sel.value;
            sel.innerHTML = '<option value="">كل العملات</option>' +
                byCur.map(c => `<option value="${c.currency_id}">${c.code || 'غير معروفة'}</option>`).join('');
            if ([...sel.options].some(o => o.value === prev)) sel.value = prev;
        }

        function loadStatement() {
            document.getElementById('stBody').innerHTML = '<div class="text-center py-5"><span class="spinner-border text-primary"></span></div>';
            const currencyFilter = document.getElementById('stCurrency').value;
            post({
                _action: 'get_statement', customer_id: CUSTOMER_ID,
                date_from: document.getElementById('stFrom').value,
                date_to: document.getElementById('stTo').value,
                limit: document.getElementById('stLimit').value,
                currency_filter: currencyFilter,
            }).then(d => {
                if (!d.ok) { document.getElementById('stBody').innerHTML = ''; toast(d.msg); return; }
                const sym = d.entity.acc_sym;
                const byCur = d.by_currency || [];
                populateCurrencyFilter(byCur);

                // ── الرصيد الفعلي حسب كل عملة حقيقية (مستقل عن أي فلتر —
                // دايماً الصورة الكاملة الحالية) ──
                const byCurWrap = document.getElementById('byCurrencyWrap');
                if (byCur.length) {
                    byCurWrap.style.display = '';
                    document.getElementById('byCurrencyCards').innerHTML = byCur.map(c => `
                <div class="col-6 col-md-3">
                    <div class="kpi-card">
                        <div class="kpi-val n" style="color:${c.net >= 0 ? '#16a34a' : '#dc2626'}">
                            ${c.symbol || c.code || ''} ${fmt(c.net)}
                        </div>
                        <div class="kpi-lbl">${c.code || 'عملة غير معروفة'}</div>
                    </div>
                </div>`).join('');
                } else {
                    byCurWrap.style.display = 'none';
                }

                // لما نكون مفلترين بعملة محدَّدة، الرصيد التراكمي بيصير
                // بعملة الفلتر نفسها (مش بعملة الفرع) — أدق وأوضح لأنه كل
                // الأسطر هلق بنفس العملة فعلياً
                const runningSym = currencyFilter ? (d.filter_currency_symbol || d.filter_currency_code || sym) : sym;

                const rows = (d.movements || []).map(m => {
                    const rsym = m.cur_sym || m.cur_code || sym; // 🔴 كان يرجع لرمز عملة الفرع ($) عند غياب رمز العملة الحقيقية — مضلِّل تماماً (يوهم إنه المبلغ بعملة الفرع). هلق يرجع لكود العملة نفسه (TRY) بدل رمز غلط
                    const realDebit = m.debit > 0 ? rsym + ' ' + fmt(m.original_amount) : '—';
                    const realCredit = m.credit > 0 ? rsym + ' ' + fmt(m.original_amount) : '—';
                    return `
            <tr>
                <td class="n" style="font-size:.78rem">${m.entry_date}</td>
                <td class="n fw-600" style="direction:ltr;font-size:.78rem;color:#1e3a8a">${m.entry_number}</td>
                <td style="font-size:.78rem">
                    ${REF_LABELS[m.reference_type] || ''} ${m.description || m.entry_desc || ''}
                    ${!currencyFilter && m.cur_code ? `<span class="badge" style="font-size:.6rem;background:#eff6ff;color:#1e3a8a;padding:2px 5px">${m.cur_code}</span>` : ''}
                </td>
                <td class="n text-end" style="font-size:.8rem;color:#1e3a8a">${realDebit}</td>
                <td class="n text-end" style="font-size:.8rem;color:#16a34a">${realCredit}</td>
                <td class="n text-end fw-600" style="font-size:.8rem;color:${m.running_balance >= 0 ? '#16a34a' : '#dc2626'}">${runningSym} ${fmt(m.running_balance)}</td>
            </tr>`;
                }).join('');

                const noteText = currencyFilter
                    ? `الجدول مفلتر بعملة واحدة (${runningSym}) — كل الأعمدة بما فيها "الرصيد التراكمي" بنفس العملة الحقيقية.`
                    : `عمودا "مدين"/"دائن" بيعرضوا المبلغ الحقيقي بعملة كل عملية كما سُجِّلت فعلياً (مو محوَّلة) — عمود "الرصيد التراكمي" لوحده بعملة الفرع (${sym}) لأنه بيجمع عمليات بعملات مختلفة سوا.`;

                document.getElementById('stBody').innerHTML = `
            <div class="alert alert-warning py-2 mb-2 no-print" style="font-size:.72rem;border-radius:8px">
                <i class="bi bi-info-circle me-1"></i>${noteText}
            </div>
            <div class="tbl-wrap">
                <div class="table-responsive">
                    <table class="mtbl">
                        <thead>
                            <tr><th>التاريخ</th><th>رقم القيد</th><th>البيان</th>
                                <th class="text-end">مدين (بعملته الحقيقية)</th><th class="text-end">دائن (بعملته الحقيقية)</th>
                                <th class="text-end">الرصيد التراكمي${currencyFilter ? '' : ' (بعملة الفرع)'}</th></tr>
                        </thead>
                        <tbody>
                            <tr style="background:#f8fafc">
                                <td colspan="5" class="fw-600" style="font-size:.8rem">رصيد افتتاحي</td>
                                <td class="n text-end fw-600" style="font-size:.8rem">${runningSym} ${fmt(d.opening_balance)}</td>
                            </tr>
                            ${rows || `<tr><td colspan="6" class="text-center text-muted py-4" style="font-size:.82rem">لا توجد حركة بهذه الفترة</td></tr>`}
                            <tr style="background:#eff6ff">
                                <td colspan="5" class="fw-700" style="font-size:.82rem;color:#1e3a8a">الرصيد الختامي</td>
                                <td class="n text-end fw-700" style="font-size:.82rem;color:#1e3a8a">${runningSym} ${fmt(d.closing_balance)}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>`;
            });
        }

        loadStatement();
    </script>

</body>

</html>
