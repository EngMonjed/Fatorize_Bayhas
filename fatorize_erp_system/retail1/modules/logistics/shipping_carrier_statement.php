<?php
/**
 * logistics/shipping_carrier_statement.php — كشف حساب شركة شحن
 * المسار: retail1/modules/logistics/shipping_carrier_statement.php
 *
 * يُفتح دايماً بمعرِّف صريح: ?carrier_id=5 (رابط من shipping_carriers.php).
 * نفس منطق كشف حساب المورد/العميل (accounting/supplier_statement.php)
 * تماماً، بفارق واحد مهم: بجدول shipping_carriers_{TS} حساب "الذمة"
 * (الأساسي) اسمه العمود payable_account_id، وحساب "الدفعة المقدمة"
 * اسمه العمود account_id — عكس تسمية جدولي customers/product_suppliers.
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('logistics.shipping_carriers', 'view');
$currentModule = 'logistics.shipping_carriers';

$TS = $_SESSION['table_suffix'];
$TSC = "shipping_carriers_{$TS}";
$TAC = "account_charts_{$TS}";
$TJE = "journal_entries_{$TS}";
$TJI = "journal_entry_items_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

function loadCarrier(PDO $pdo, string $table, int $id, string $TAC): ?array
{
    // ⚠ بجدول شركات النقلية/الشحن: payable_account_id = حساب الذمة (الأساسي)
    //    account_id = حساب الدفعات المقدمة — عكس تسمية جدولي العملاء/الموردين
    $st = $pdo->prepare("SELECT e.*, ac.code AS acc_code, ac.name AS acc_name, ac.balance AS acc_balance,
            ac.base_balance AS acc_base_balance, ac.currency_id AS acc_currency_id,
            cur.symbol AS acc_sym, cur.code AS acc_cur_code,
            adv.code AS adv_code, adv.name AS adv_name, adv.balance AS adv_balance, adv.base_balance AS adv_base_balance
        FROM `{$table}` e
        LEFT JOIN `{$TAC}` ac ON ac.id = e.payable_account_id
        LEFT JOIN currencies cur ON cur.id = ac.currency_id
        LEFT JOIN `{$TAC}` adv ON adv.id = e.account_id
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
            $id = (int) ($_POST['carrier_id'] ?? 0);
            $entity = loadCarrier($pdo, $TSC, $id, $TAC);
            if (!$entity)
                throw new Exception('غير موجود');
            if (!$entity['payable_account_id'])
                throw new Exception('هذه الشركة ما إلها حساب ذمة مخصص بعد');

            $dateFrom = trim($_POST['date_from'] ?? '');
            $dateTo = trim($_POST['date_to'] ?? '');
            $limit = (int) ($_POST['limit'] ?? 0); // آخر N عملية — يتجاهل التاريخ لو محدَّد

            $accId = (int) $entity['payable_account_id'];

            $opening = 0;
            if ($dateFrom && !$limit) {
                $ob = $pdo->prepare("SELECT COALESCE(SUM(ji.debit - ji.credit),0)
                    FROM `{$TJI}` ji JOIN `{$TJE}` je ON je.id = ji.journal_entry_id
                    WHERE ji.account_id = ? AND je.status = 'posted' AND je.entry_date < ?");
                $ob->execute([$accId, $dateFrom]);
                $opening = (float) $ob->fetchColumn();
            }

            if ($limit > 0) {
                $st = $pdo->prepare("SELECT ji.*, je.entry_number, je.entry_date, je.description AS entry_desc, je.reference_type
                    FROM `{$TJI}` ji JOIN `{$TJE}` je ON je.id = ji.journal_entry_id
                    WHERE ji.account_id = ? AND je.status = 'posted'
                    ORDER BY je.entry_date DESC, je.id DESC LIMIT {$limit}");
                $st->execute([$accId]);
                $rows = array_reverse($st->fetchAll());
                if ($rows) {
                    $firstDate = $rows[0]['entry_date'];
                    $firstId = $rows[0]['journal_entry_id'];
                    $ob = $pdo->prepare("SELECT COALESCE(SUM(ji.debit - ji.credit),0)
                        FROM `{$TJI}` ji JOIN `{$TJE}` je ON je.id = ji.journal_entry_id
                        WHERE ji.account_id = ? AND je.status = 'posted'
                        AND (je.entry_date < ? OR (je.entry_date = ? AND je.id < ?))");
                    $ob->execute([$accId, $firstDate, $firstDate, $firstId]);
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
                $st = $pdo->prepare("SELECT ji.*, je.entry_number, je.entry_date, je.description AS entry_desc, je.reference_type
                    FROM `{$TJI}` ji JOIN `{$TJE}` je ON je.id = ji.journal_entry_id
                    WHERE {$where}
                    ORDER BY je.entry_date, je.id");
                $st->execute($params);
                $rows = $st->fetchAll();
            }

            $running = $opening;
            foreach ($rows as &$r) {
                $running += ((float) $r['debit'] - (float) $r['credit']);
                $r['running_balance'] = $running;
            }
            unset($r);

            echo json_encode([
                'ok' => true,
                'entity' => [
                    'name' => $entity['name'],
                    'phone' => $entity['phone'] ?? '',
                    'acc_code' => $entity['acc_code'],
                    'acc_name' => $entity['acc_name'],
                    'acc_sym' => $entity['acc_sym'] ?? '$',
                    'acc_balance' => (float) ($entity['acc_balance'] ?? 0),
                    'acc_base_balance' => (float) ($entity['acc_base_balance'] ?? 0),
                    'adv_balance' => (float) ($entity['adv_balance'] ?? 0),
                    'adv_base_balance' => (float) ($entity['adv_base_balance'] ?? 0),
                    'has_advance' => !empty($entity['account_id']),
                ],
                'opening_balance' => $opening,
                'movements' => $rows,
                'closing_balance' => $running
            ]);
        } else
            throw new Exception('إجراء غير معروف');
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

$id = (int) ($_GET['carrier_id'] ?? 0);
if (!$id) {
    die('<div dir="rtl" style="font-family:sans-serif;padding:40px;text-align:center;color:#dc2626">
        رابط غير صالح — لازم يُفتح كشف الحساب من صفحة شركات الشحن مباشرة.
        <br><a href="shipping_carriers.php">رجوع لشركات الشحن</a></div>');
}
$entity = loadCarrier($pdo, $TSC, $id, $TAC);
if (!$entity) {
    die('<div dir="rtl" style="font-family:sans-serif;padding:40px;text-align:center;color:#dc2626">
        شركة الشحن غير موجودة.</div>');
}
$backLink = 'shipping_carriers.php';
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
        <span class="tb-title"><i class="bi bi-file-earmark-text me-1 text-warning"></i>كشف حساب شركة شحن</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
            <a href="<?= $backLink ?>" style="color:#64748b;text-decoration:none">شركات الشحن</a>
            <i class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
            <span class="text-primary fw-600">كشف حساب</span>
        </nav>
    </header>
    <main class="main-content">
        <div class="content-body">

            <div class="d-flex align-items-center justify-content-between mb-3 no-print">
                <a href="<?= $backLink ?>" class="btn btn-sm btn-light" style="border-radius:8px">
                    <i class="bi bi-arrow-right me-1"></i>رجوع لشركات الشحن
                </a>
                <button class="btn btn-sm btn-primary" style="border-radius:8px" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i>طباعة
                </button>
            </div>

            <div class="tbl-wrap mb-3" style="padding:20px">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <div style="font-size:1.1rem;font-weight:700;color:#1e293b">
                            <i class="bi bi-truck me-2 text-warning"></i>
                            <?= htmlspecialchars($entity['name']) ?>
                        </div>
                        <div style="font-size:.8rem;color:#64748b;margin-top:4px">
                            شركة شحن<?= !empty($entity['phone']) ? ' — ' . htmlspecialchars($entity['phone']) : '' ?>
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
                        <div class="kpi-val n"
                            style="color:<?= ($entity['acc_balance'] ?? 0) >= 0 ? '#16a34a' : '#dc2626' ?>">
                            <?= htmlspecialchars($entity['acc_sym'] ?? '$') ?>
                            <?= number_format($entity['acc_balance'] ?? 0, 2) ?>
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
                <?php if (!empty($entity['account_id'])): ?>
                    <div class="col-6 col-md-3">
                        <div class="kpi-card">
                            <div class="kpi-val n text-primary"><?= htmlspecialchars($baseSym) ?>
                                <?= number_format($entity['adv_base_balance'] ?? 0, 2) ?>
                            </div>
                            <div class="kpi-lbl">رصيد الدفعات المقدمة</div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="tbl-wrap mb-3 no-print">
                <div class="tbl-hdr">
                    <label class="field-lbl mb-0">من</label>
                    <input type="date" id="stFrom" class="form-control form-control-sm" style="width:150px">
                    <label class="field-lbl mb-0">إلى</label>
                    <input type="date" id="stTo" class="form-control form-control-sm" style="width:150px">
                    <span style="color:#94a3b8">أو</span>
                    <label class="field-lbl mb-0">آخر</label>
                    <input type="number" id="stLimit" class="form-control form-control-sm" style="width:90px" min="1"
                        placeholder="عدد">
                    <span style="font-size:.78rem;color:#64748b">عملية</span>
                    <button class="btn btn-sm btn-primary" style="border-radius:8px" onclick="loadStatement()">
                        <i class="bi bi-search me-1"></i>عرض
                    </button>
                    <button class="btn btn-sm btn-light ms-auto" style="border-radius:8px" onclick="resetFilters()">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>الكل
                    </button>
                </div>
            </div>

            <div id="stBody">
                <div class="text-center py-5"><span class="spinner-border text-primary"></span></div>
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

        const CARRIER_ID = <?= (int) $id ?>;
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
            loadStatement();
        }

        const REF_LABELS = {
            transport: 'ترحيل نقل (شحن من المعمل)', transport_payment: 'دفعة نقلية',
            shipping: 'ترحيل شحن (تسليم)', shipping_payment: 'دفعة شركة شحن',
            payment: 'سند دفع', receipt: 'سند قبض', transfer: 'تحويل', fee: 'رسم',
        };

        function loadStatement() {
            document.getElementById('stBody').innerHTML = '<div class="text-center py-5"><span class="spinner-border text-primary"></span></div>';
            post({
                _action: 'get_statement', carrier_id: CARRIER_ID,
                date_from: document.getElementById('stFrom').value,
                date_to: document.getElementById('stTo').value,
                limit: document.getElementById('stLimit').value,
            }).then(d => {
                if (!d.ok) { document.getElementById('stBody').innerHTML = ''; toast(d.msg); return; }
                const sym = d.entity.acc_sym;
                const rows = (d.movements || []).map(m => `
            <tr>
                <td class="n" style="font-size:.78rem">${m.entry_date}</td>
                <td class="n fw-600" style="direction:ltr;font-size:.78rem;color:#1e3a8a">${m.entry_number}</td>
                <td style="font-size:.78rem">
                    ${REF_LABELS[m.reference_type] || ''} ${m.description || m.entry_desc || ''}
                </td>
                <td class="n text-end" style="font-size:.8rem;color:#1e3a8a">${m.debit > 0 ? sym + ' ' + fmt(m.debit) : '—'}</td>
                <td class="n text-end" style="font-size:.8rem;color:#16a34a">${m.credit > 0 ? sym + ' ' + fmt(m.credit) : '—'}</td>
                <td class="n text-end fw-600" style="font-size:.8rem;color:${m.running_balance >= 0 ? '#16a34a' : '#dc2626'}">${sym} ${fmt(m.running_balance)}</td>
            </tr>`).join('');

                document.getElementById('stBody').innerHTML = `
            <div class="tbl-wrap">
                <div class="table-responsive">
                    <table class="mtbl">
                        <thead>
                            <tr><th>التاريخ</th><th>رقم القيد</th><th>البيان</th>
                                <th class="text-end">مدين</th><th class="text-end">دائن</th><th class="text-end">الرصيد التراكمي</th></tr>
                        </thead>
                        <tbody>
                            <tr style="background:#f8fafc">
                                <td colspan="5" class="fw-600" style="font-size:.8rem">رصيد افتتاحي</td>
                                <td class="n text-end fw-600" style="font-size:.8rem">${sym} ${fmt(d.opening_balance)}</td>
                            </tr>
                            ${rows || `<tr><td colspan="6" class="text-center text-muted py-4" style="font-size:.82rem">لا توجد حركة بهذه الفترة</td></tr>`}
                            <tr style="background:#eff6ff">
                                <td colspan="5" class="fw-700" style="font-size:.82rem;color:#1e3a8a">الرصيد الختامي</td>
                                <td class="n text-end fw-700" style="font-size:.82rem;color:#1e3a8a">${sym} ${fmt(d.closing_balance)}</td>
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
