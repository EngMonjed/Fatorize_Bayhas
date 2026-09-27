<?php
/**
 * consumable_reports.php — تقارير المصاريف والمستهلكات
 * المسار: retail1/modules/expenses_and_consumables/consumable_reports.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('expenses.consumable_reports', 'view');
$currentModule = 'expenses.consumable_reports';

$TS = $_SESSION['table_suffix'];
$TI  = "consumable_items_{$TS}";
$TU  = "consumable_units_{$TS}";
$TC  = "consumable_categories_{$TS}";
$TM  = "consumable_movements_{$TS}";
$TW  = "warehouses_{$TS}";
$TD  = "consumable_departments_{$TS}";
$TE  = "expenses_{$TS}";
$TAC = "account_charts_{$TS}";
$TP  = "consumable_purchases_{$TS}";
$TSP = "product_suppliers_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

$branchBaseCurrencySymbol = '$';
if (!empty($_SESSION['branch_id'])) {
    $bcSt = $pdo->prepare("SELECT c.symbol FROM branches b LEFT JOIN currencies c ON c.id = b.base_currency_id WHERE b.id = ?");
    $bcSt->execute([$_SESSION['branch_id']]);
    $branchBaseCurrencySymbol = $bcSt->fetchColumn() ?: '$';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        if ($act === 'report_expenses') {
            $from = $_POST['from'] ?? '';
            $to = $_POST['to'] ?? '';
            $accF = (int) ($_POST['acc'] ?? 0);

            $where = "WHERE (e.status != 'cancelled' OR e.status IS NULL)";
            $params = [];
            if ($from) { $where .= ' AND e.expense_date >= ?'; $params[] = $from; }
            if ($to) { $where .= ' AND e.expense_date <= ?'; $params[] = $to; }
            if ($accF) { $where .= ' AND e.expense_account_id = ?'; $params[] = $accF; }

            $st = $pdo->prepare("SELECT ea.id, ea.code, ea.name,
                    COUNT(*) AS cnt, COALESCE(SUM(e.amount_base),0) AS total
                FROM `{$TE}` e
                LEFT JOIN `{$TAC}` ea ON ea.id = e.expense_account_id
                {$where}
                GROUP BY ea.id, ea.code, ea.name
                ORDER BY total DESC");
            $st->execute($params);
            $byAccount = $st->fetchAll();

            $totalAll = array_sum(array_column($byAccount, 'total'));
            $countAll = array_sum(array_column($byAccount, 'cnt'));

            echo json_encode(['ok' => true, 'by_account' => $byAccount, 'total' => $totalAll, 'count' => $countAll]);
        }

        elseif ($act === 'report_consumption') {
            $from = $_POST['from'] ?? '';
            $to = $_POST['to'] ?? '';
            $whF = (int) ($_POST['wh'] ?? 0);
            $deptF = (int) ($_POST['dept'] ?? 0);
            $itemF = (int) ($_POST['item'] ?? 0);

            $where = "WHERE m.movement_type = 'issue' AND m.direction = 'out'";
            $params = [];
            if ($from) { $where .= ' AND m.movement_date >= ?'; $params[] = $from; }
            if ($to) { $where .= ' AND m.movement_date <= ?'; $params[] = $to; }
            if ($whF) { $where .= ' AND m.warehouse_id = ?'; $params[] = $whF; }
            if ($itemF) { $where .= ' AND m.item_id = ?'; $params[] = $itemF; }

            $deptJoin = '';
            if ($deptF) {
                $deptJoin = "JOIN `consumable_issues_{$TS}` ci ON ci.id = m.reference_id AND m.reference_type='issue' AND ci.department_id = ?";
                $params[] = $deptF;
            }

            $st = $pdo->prepare("SELECT ci.id, ci.name, cun.name AS unit,
                    SUM(m.quantity) AS total_qty, SUM(m.total_cost_base) AS total_value
                FROM `{$TM}` m
                JOIN `{$TI}` ci ON ci.id = m.item_id
                LEFT JOIN `{$TU}` cun ON cun.id = ci.unit_id
                {$deptJoin}
                {$where}
                GROUP BY ci.id, ci.name, cun.name
                ORDER BY total_value DESC");
            $st->execute($params);
            $byItem = $st->fetchAll();

            $where2 = "WHERE m.movement_type = 'issue' AND m.direction = 'out'";
            $params2 = [];
            if ($from) { $where2 .= ' AND m.movement_date >= ?'; $params2[] = $from; }
            if ($to) { $where2 .= ' AND m.movement_date <= ?'; $params2[] = $to; }
            if ($whF) { $where2 .= ' AND m.warehouse_id = ?'; $params2[] = $whF; }

            $st2 = $pdo->prepare("SELECT w.id, w.name,
                    SUM(m.quantity) AS total_qty, SUM(m.total_cost_base) AS total_value
                FROM `{$TM}` m
                LEFT JOIN `{$TW}` w ON w.id = m.warehouse_id
                {$where2}
                GROUP BY w.id, w.name
                ORDER BY total_value DESC");
            $st2->execute($params2);
            $byWarehouse = $st2->fetchAll();

            $st3 = $pdo->prepare("SELECT d.id, d.name,
                    SUM(m.quantity) AS total_qty, SUM(m.total_cost_base) AS total_value
                FROM `{$TM}` m
                JOIN `consumable_issues_{$TS}` ci ON ci.id = m.reference_id AND m.reference_type='issue'
                LEFT JOIN `{$TD}` d ON d.id = ci.department_id
                {$where2}
                GROUP BY d.id, d.name
                ORDER BY total_value DESC");
            $st3->execute($params2);
            $byDept = $st3->fetchAll();

            $totalValue = array_sum(array_column($byItem, 'total_value'));
            echo json_encode(['ok' => true, 'by_item' => $byItem, 'by_warehouse' => $byWarehouse, 'by_department' => $byDept, 'total_value' => $totalValue]);
        }

        elseif ($act === 'report_supplier_statement') {
            $supId = (int) ($_POST['supplier_id'] ?? 0);
            $from = $_POST['from'] ?? '';
            $to = $_POST['to'] ?? '';
            if (!$supId)
                throw new Exception('يجب اختيار مورد');

            $where = "WHERE p.supplier_id = ? AND p.status = 'confirmed'";
            $params = [$supId];
            if ($from) { $where .= ' AND p.invoice_date >= ?'; $params[] = $from; }
            if ($to) { $where .= ' AND p.invoice_date <= ?'; $params[] = $to; }

            $st = $pdo->prepare("SELECT p.id, p.invoice_no, p.invoice_date, p.total_base, p.currency
                FROM `{$TP}` p {$where} ORDER BY p.invoice_date, p.id");
            $st->execute($params);
            $invoices = $st->fetchAll();

            $running = 0;
            foreach ($invoices as &$inv) {
                $running += (float) $inv['total_base'];
                $inv['running_balance'] = $running;
            }
            unset($inv);

            $supSt = $pdo->prepare("SELECT s.name, pay.balance AS account_balance
                FROM `{$TSP}` s LEFT JOIN `{$TAC}` pay ON pay.id = s.account_id WHERE s.id=?");
            $supSt->execute([$supId]);
            $supInfo = $supSt->fetch();

            echo json_encode([
                'ok' => true,
                'invoices' => $invoices,
                'total' => $running,
                'supplier_name' => $supInfo['name'] ?? '',
                'account_balance' => $supInfo['account_balance'] ?? null,
            ]);
        } else
            throw new Exception('إجراء غير معروف');
    } catch (Throwable $e) {
        error_log('[consumable_reports] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

$expAccounts = $pdo->query("SELECT id, code, name FROM `{$TAC}` WHERE account_type='expense' AND is_active=1 ORDER BY code")->fetchAll();
$warehouses = $pdo->query("SELECT id, name FROM `{$TW}` WHERE warehouse_type='consumables' AND is_active=1 ORDER BY name")->fetchAll();
$departments = $pdo->query("SELECT id, name FROM `{$TD}` WHERE is_active=1 ORDER BY name")->fetchAll();
$items = $pdo->query("SELECT id, name FROM `{$TI}` WHERE is_active=1 ORDER BY name")->fetchAll();
$suppliers = $pdo->query("SELECT id, name FROM `{$TSP}` WHERE status='active' AND supplier_type IN ('consumable','both') ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>تقارير المصاريف والمستهلكات — <?= htmlspecialchars($branchName) ?></title>
    <link rel="icon" href="<?= BASE_PATH ?>/assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/assets/css/layout.css" rel="stylesheet">
    <style>
        .stat-card { background:#fff;border-radius:14px;border:1px solid #e2e8f0;padding:12px 16px;display:flex;align-items:center;gap:10px }
        .stat-icon { width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0 }
        .stat-val { font-size:1.2rem;font-weight:700;color:#1e293b;line-height:1 }
        .stat-lbl { font-size:.7rem;color:#64748b;margin-top:2px }
        .tbl-wrap { background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden }
        .mtbl { width:100%;font-size:.82rem;margin:0 }
        .mtbl th { background:#f8fafc;padding:10px 14px;font-weight:700;color:#475569;font-size:.75rem;text-align:right;cursor:pointer;user-select:none }
        .mtbl td { padding:9px 14px;border-top:1px solid #f1f5f9 }
        .n { font-variant-numeric: tabular-nums }
        .rep-tab { border:none;background:transparent;padding:10px 18px;font-size:.85rem;font-weight:600;color:#64748b;border-bottom:3px solid transparent;cursor:pointer }
        .rep-tab.active { color:#1e3a8a;border-bottom-color:#1e3a8a }
        .field-lbl { font-size:.74rem;font-weight:700;color:#475569;margin-bottom:4px;display:block }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
    <?php require_once __DIR__ . '/../../../includes/breadcrumb.php'; ?>

    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-bar-chart me-1 text-primary"></i>تقارير المصاريف والمستهلكات</span>
        <span class="tb-branch"><?= htmlspecialchars($branchName) ?></span>
        <?= renderBreadcrumb() ?>
    </header>

    <main class="main-content">
        <div class="content-body">

            <ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e2e8f0">
                <li class="nav-item"><a class="nav-link fw-600" href="consumables.php" style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-box-seam me-1"></i>المواد الاستهلاكية</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="consumable_purchases.php" style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-cart-plus me-1"></i>فواتير الشراء</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="consumable_issues.php" style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-arrow-bar-up me-1"></i>صرف المستهلكات</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="../inventory/warehouse.php?type=consumables" style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-building me-1"></i>مستودعات المستهلكات</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="../inventory/movements.php?tab=consumables" style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-arrow-left-right me-1"></i>حركة المستهلكات</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="consumable_transfers.php" style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-signpost-split me-1"></i>مناقلة بين المستودعات</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="expenses.php" style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-wallet2 me-1"></i>إدارة المصاريف</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="../purchases/suppliers.php?tab=consumables" style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-people me-1"></i>موردو المستهلكات</a></li>
                <li class="nav-item"><a class="nav-link fw-600 active" href="#" style="border:none;border-bottom:2px solid var(--section-color);color:var(--section-color);font-size:.83rem;margin-bottom:-2px"><i class="bi bi-bar-chart me-1"></i>التقارير</a></li>
            </ul>

            <div class="d-flex gap-1 mb-3" style="border-bottom:1px solid #e2e8f0">
                <button class="rep-tab active" id="tabBtn-expenses" onclick="switchReport('expenses')"><i class="bi bi-wallet2 me-1"></i>المصاريف</button>
                <button class="rep-tab" id="tabBtn-consumption" onclick="switchReport('consumption')"><i class="bi bi-box-seam me-1"></i>استهلاك المواد</button>
                <button class="rep-tab" id="tabBtn-supplier" onclick="switchReport('supplier')"><i class="bi bi-person-lines-fill me-1"></i>كشف حساب مورد</button>
            </div>

            <div id="repExpenses">
                <div class="tbl-wrap p-3 mb-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3"><label class="field-lbl">من تاريخ</label><input type="date" id="expFrom" class="form-control form-control-sm"></div>
                        <div class="col-md-3"><label class="field-lbl">إلى تاريخ</label><input type="date" id="expTo" class="form-control form-control-sm"></div>
                        <div class="col-md-4">
                            <label class="field-lbl">حساب المصروف</label>
                            <select id="expAcc" class="form-select form-select-sm">
                                <option value="">— كل الحسابات —</option>
                                <?php foreach ($expAccounts as $a): ?>
                                    <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2"><button class="btn btn-sm w-100" style="background:#1e3a8a;color:#fff;border-radius:8px" onclick="loadExpensesReport()"><i class="bi bi-search me-1"></i>بحث</button></div>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon" style="background:#fef9ee"><i class="bi bi-wallet2 text-warning"></i></div><div><div class="stat-val n" id="expTotalCard"><?= htmlspecialchars($branchBaseCurrencySymbol) ?> 0.00</div><div class="stat-lbl">إجمالي المصاريف</div></div></div></div>
                    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon" style="background:#eff6ff"><i class="bi bi-receipt text-primary"></i></div><div><div class="stat-val n" id="expCountCard">0</div><div class="stat-lbl">عدد المصاريف</div></div></div></div>
                </div>
                <div class="tbl-wrap">
                    <table class="mtbl" id="expTbl">
                        <thead><tr>
                            <th style="color:#1e3a8a">حساب المصروف</th>
                            <th class="text-center" style="color:#ca8a04">عدد العمليات</th>
                            <th class="text-end">الإجمالي (<?= htmlspecialchars($branchBaseCurrencySymbol) ?>)</th>
                            <th class="text-end" data-no-sort>النسبة</th>
                        </tr></thead>
                        <tbody id="expTblBody"><tr><td colspan="4" class="text-center text-muted py-4">اختر فترة واضغط بحث</td></tr></tbody>
                    </table>
                </div>
            </div>

            <div id="repConsumption" style="display:none">
                <div class="tbl-wrap p-3 mb-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-2"><label class="field-lbl">من تاريخ</label><input type="date" id="conFrom" class="form-control form-control-sm"></div>
                        <div class="col-md-2"><label class="field-lbl">إلى تاريخ</label><input type="date" id="conTo" class="form-control form-control-sm"></div>
                        <div class="col-md-2">
                            <label class="field-lbl">المستودع</label>
                            <select id="conWh" class="form-select form-select-sm">
                                <option value="">— الكل —</option>
                                <?php foreach ($warehouses as $w): ?><option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="field-lbl">الجهة المستلمة</label>
                            <select id="conDept" class="form-select form-select-sm">
                                <option value="">— الكل —</option>
                                <?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="field-lbl">المادة</label>
                            <select id="conItem" class="form-select form-select-sm">
                                <option value="">— الكل —</option>
                                <?php foreach ($items as $it): ?><option value="<?= $it['id'] ?>"><?= htmlspecialchars($it['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2"><button class="btn btn-sm w-100" style="background:#1e3a8a;color:#fff;border-radius:8px" onclick="loadConsumptionReport()"><i class="bi bi-search me-1"></i>بحث</button></div>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-4"><div class="stat-card"><div class="stat-icon" style="background:#fef9ee"><i class="bi bi-cash-stack text-warning"></i></div><div><div class="stat-val n" id="conTotalCard"><?= htmlspecialchars($branchBaseCurrencySymbol) ?> 0.00</div><div class="stat-lbl">إجمالي قيمة الاستهلاك</div></div></div></div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="fw-700 mb-2" style="font-size:.85rem;color:#1e293b"><i class="bi bi-box-seam me-1 text-primary"></i>حسب المادة</div>
                        <div class="tbl-wrap mb-3">
                            <table class="mtbl" id="conItemTbl">
                                <thead><tr><th style="color:#1e3a8a">المادة</th><th class="text-center" style="color:#ca8a04">الكمية</th><th class="text-end">القيمة</th></tr></thead>
                                <tbody id="conItemBody"><tr><td colspan="3" class="text-center text-muted py-4">اضغط بحث</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="fw-700 mb-2" style="font-size:.85rem;color:#1e293b"><i class="bi bi-building me-1 text-success"></i>حسب المستودع</div>
                        <div class="tbl-wrap mb-3">
                            <table class="mtbl" id="conWhTbl">
                                <thead><tr><th style="color:#16a34a">المستودع</th><th class="text-end">القيمة</th></tr></thead>
                                <tbody id="conWhBody"><tr><td colspan="2" class="text-center text-muted py-4">—</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="fw-700 mb-2" style="font-size:.85rem;color:#1e293b"><i class="bi bi-signpost-split me-1 text-danger"></i>حسب الجهة المستلمة</div>
                        <div class="tbl-wrap mb-3">
                            <table class="mtbl" id="conDeptTbl">
                                <thead><tr><th style="color:#dc2626">الجهة</th><th class="text-end">القيمة</th></tr></thead>
                                <tbody id="conDeptBody"><tr><td colspan="2" class="text-center text-muted py-4">—</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div id="repSupplier" style="display:none">
                <div class="tbl-wrap p-3 mb-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-5">
                            <label class="field-lbl">المورد <span style="color:#dc2626">*</span></label>
                            <select id="supSel" class="form-select form-select-sm">
                                <option value="">— اختر مورد —</option>
                                <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3"><label class="field-lbl">من تاريخ</label><input type="date" id="supFrom" class="form-control form-control-sm"></div>
                        <div class="col-md-2"><label class="field-lbl">إلى تاريخ</label><input type="date" id="supTo" class="form-control form-control-sm"></div>
                        <div class="col-md-2"><button class="btn btn-sm w-100" style="background:#1e3a8a;color:#fff;border-radius:8px" onclick="loadSupplierStatement()"><i class="bi bi-search me-1"></i>بحث</button></div>
                    </div>
                </div>

                <div id="supStatementWrap" style="display:none">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4"><div class="stat-card"><div class="stat-icon" style="background:#fef2f2"><i class="bi bi-cash-coin text-danger"></i></div><div><div class="stat-val n" id="supTotalCard"><?= htmlspecialchars($branchBaseCurrencySymbol) ?> 0.00</div><div class="stat-lbl">إجمالي فواتير الفترة</div></div></div></div>
                        <div class="col-md-4"><div class="stat-card"><div class="stat-icon" style="background:#f8fafc"><i class="bi bi-bank text-secondary"></i></div><div><div class="stat-val n" id="supAccBalCard">—</div><div class="stat-lbl">رصيد الحساب الحالي (كل الفترات)</div></div></div></div>
                    </div>
                    <div style="background:#fef9ee;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;font-size:.78rem;color:#92400e;margin-bottom:12px">
                        <i class="bi bi-info-circle me-1"></i>الكشف حالياً يعرض فواتير الشراء فقط — آلية تسجيل الدفعات لسا قيد الإنشاء (لا تُطرح دفعات من الرصيد المتحرك بعد).
                    </div>
                    <div class="tbl-wrap">
                        <table class="mtbl" id="supTbl">
                            <thead><tr><th style="color:#1e3a8a">رقم الفاتورة</th><th>التاريخ</th><th class="text-end">المبلغ</th><th class="text-end" data-no-sort>الرصيد المتحرك</th></tr></thead>
                            <tbody id="supTblBody"></tbody>
                        </table>
                    </div>
                </div>
                <div id="supEmpty" class="text-center text-muted py-5"><i class="bi bi-person-lines-fill d-block mb-2" style="font-size:1.5rem;opacity:.3"></i>اختر مورد واضغط بحث</div>
            </div>

        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= BASE_PATH ?>/assets/js/sidebar.js"></script>
    <script>
        const BASE_CUR_SYM = <?= json_encode($branchBaseCurrencySymbol) ?>;

        function post(data) {
            return fetch(location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(data) }).then(r => r.json());
        }
        function toast(msg, type = 'success') {
            const el = document.createElement('div');
            el.className = `alert alert-${type === 'danger' ? 'danger' : 'success'} position-fixed`;
            el.style.cssText = 'top:20px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:10px;font-size:.85rem;box-shadow:0 4px 20px rgba(0,0,0,.15)';
            el.textContent = msg;
            document.body.appendChild(el);
            setTimeout(() => el.remove(), 3000);
        }

        function switchReport(name) {
            ['expenses', 'consumption', 'supplier'].forEach(n => {
                document.getElementById('tabBtn-' + n).classList.toggle('active', n === name);
                document.getElementById('rep' + n[0].toUpperCase() + n.slice(1)).style.display = n === name ? '' : 'none';
            });
        }

        function loadExpensesReport() {
            post({ _action: 'report_expenses', from: document.getElementById('expFrom').value, to: document.getElementById('expTo').value, acc: document.getElementById('expAcc').value })
                .then(d => {
                    if (!d.ok) { toast(d.msg, 'danger'); return; }
                    document.getElementById('expTotalCard').textContent = BASE_CUR_SYM + ' ' + d.total.toFixed(2);
                    document.getElementById('expCountCard').textContent = d.count;
                    const body = document.getElementById('expTblBody');
                    if (!d.by_account.length) { body.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">لا توجد مصاريف بهذه الفلترة</td></tr>'; return; }
                    body.innerHTML = d.by_account.map(r => {
                        const pct = d.total > 0 ? (r.total / d.total * 100).toFixed(1) : 0;
                        return `<tr>
            <td style="color:#1e3a8a">${r.code || ''} — ${r.name || '—'}</td>
            <td class="text-center n" style="color:#ca8a04">${r.cnt}</td>
            <td class="text-end n fw-600">${BASE_CUR_SYM} ${parseFloat(r.total).toFixed(2)}</td>
            <td class="text-end" style="color:#94a3b8">${pct}%</td>
        </tr>`;
                    }).join('');
                });
        }

        function loadConsumptionReport() {
            post({ _action: 'report_consumption', from: document.getElementById('conFrom').value, to: document.getElementById('conTo').value, wh: document.getElementById('conWh').value, dept: document.getElementById('conDept').value, item: document.getElementById('conItem').value })
                .then(d => {
                    if (!d.ok) { toast(d.msg, 'danger'); return; }
                    document.getElementById('conTotalCard').textContent = BASE_CUR_SYM + ' ' + d.total_value.toFixed(2);
                    document.getElementById('conItemBody').innerHTML = d.by_item.length ? d.by_item.map(r => `<tr>
            <td style="color:#1e3a8a">${r.name}</td>
            <td class="text-center n" style="color:#ca8a04">${parseFloat(r.total_qty).toFixed(2)} ${r.unit || ''}</td>
            <td class="text-end n fw-600">${BASE_CUR_SYM} ${parseFloat(r.total_value).toFixed(2)}</td>
        </tr>`).join('') : '<tr><td colspan="3" class="text-center text-muted py-4">لا يوجد استهلاك بهذه الفلترة</td></tr>';
                    document.getElementById('conWhBody').innerHTML = d.by_warehouse.length ? d.by_warehouse.map(r => `<tr>
            <td style="color:#16a34a">${r.name || '—'}</td>
            <td class="text-end n">${BASE_CUR_SYM} ${parseFloat(r.total_value).toFixed(2)}</td>
        </tr>`).join('') : '<tr><td colspan="2" class="text-center text-muted py-3">—</td></tr>';
                    document.getElementById('conDeptBody').innerHTML = d.by_department.length ? d.by_department.map(r => `<tr>
            <td style="color:#dc2626">${r.name || '—'}</td>
            <td class="text-end n">${BASE_CUR_SYM} ${parseFloat(r.total_value).toFixed(2)}</td>
        </tr>`).join('') : '<tr><td colspan="2" class="text-center text-muted py-3">—</td></tr>';
                });
        }

        function loadSupplierStatement() {
            const supId = document.getElementById('supSel').value;
            if (!supId) { toast('اختر مورد أولاً', 'danger'); return; }
            post({ _action: 'report_supplier_statement', supplier_id: supId, from: document.getElementById('supFrom').value, to: document.getElementById('supTo').value })
                .then(d => {
                    if (!d.ok) { toast(d.msg, 'danger'); return; }
                    document.getElementById('supEmpty').style.display = 'none';
                    document.getElementById('supStatementWrap').style.display = '';
                    document.getElementById('supTotalCard').textContent = BASE_CUR_SYM + ' ' + d.total.toFixed(2);
                    document.getElementById('supAccBalCard').textContent = d.account_balance !== null ? BASE_CUR_SYM + ' ' + parseFloat(d.account_balance).toFixed(2) : '—';
                    document.getElementById('supTblBody').innerHTML = d.invoices.length ? d.invoices.map(inv => `<tr>
            <td class="fw-600" dir="ltr" style="color:#1e3a8a">${inv.invoice_no}</td>
            <td>${inv.invoice_date}</td>
            <td class="text-end n">${BASE_CUR_SYM} ${parseFloat(inv.total_base).toFixed(2)}</td>
            <td class="text-end n fw-600">${BASE_CUR_SYM} ${parseFloat(inv.running_balance).toFixed(2)}</td>
        </tr>`).join('') : '<tr><td colspan="4" class="text-center text-muted py-4">لا توجد فواتير مؤكّدة بهذه الفترة</td></tr>';
                });
        }

        function makeSortable(table) {
            if (!table) return;
            const headers = table.querySelectorAll('thead th');
            headers.forEach((th, colIndex) => {
                if (th.hasAttribute('data-no-sort')) return;
                th.style.cursor = 'pointer';
                th.addEventListener('click', () => sortTableByColumn(table, colIndex, th));
            });
        }
        function sortTableByColumn(table, colIndex, th) {
            const tbody = table.querySelector('tbody');
            if (!tbody) return;
            const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.children.length > colIndex);
            const isAsc = th.getAttribute('data-sort-dir') !== 'asc';
            table.querySelectorAll('thead th').forEach(h => h.removeAttribute('data-sort-dir'));
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
        }

        makeSortable(document.getElementById('expTbl'));
        makeSortable(document.getElementById('conItemTbl'));
        makeSortable(document.getElementById('conWhTbl'));
        makeSortable(document.getElementById('conDeptTbl'));
        makeSortable(document.getElementById('supTbl'));

        loadExpensesReport();
    </script>
</body>
</html>
