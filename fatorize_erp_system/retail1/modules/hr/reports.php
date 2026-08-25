<?php
/**
 * reports.php — View
 * retail1/modules/hr/reports.php
 *
 * تقرير ١ من ٤ مخطَّطين لقسم تقارير الموارد البشرية: كشف حساب موظف واحد.
 * الباقي (ملخص حضور/غياب، تكلفة رواتب حسب قسم، لوحة شاملة) — لاحقاً.
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';
require_once __DIR__ . '/../../../includes/breadcrumb.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('hr.reports', 'view');

$branchName = $_SESSION['branch_name'] ?? 'الفرع';
$currentModule = 'hr.reports';
$TS = $_SESSION['table_suffix'];
$TE = "hr_employees_{$TS}";
$TP = "hr_payroll_{$TS}";
$TL = "hr_loans_{$TS}";
$TB = "hr_bonuses_{$TS}";
$TA = "hr_attendance_{$TS}";
$TAC = "account_charts_{$TS}";

// ── AJAX: كشف حساب موظف واحد ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        if ($act === 'employee_statement') {
            $empId = (int) ($_POST['employee_id'] ?? 0);
            $dateFrom = $_POST['date_from'] ?? date('Y-m-01');
            $dateTo = $_POST['date_to'] ?? date('Y-m-t');
            if (!$empId)
                throw new Exception('اختر موظف');

            $st = $pdo->prepare("SELECT e.*, c.code AS cur_code, c.symbol AS cur_symbol
                FROM `{$TE}` e LEFT JOIN currencies c ON c.id=e.currency_id WHERE e.id=?");
            $st->execute([$empId]);
            $emp = $st->fetch(PDO::FETCH_ASSOC);
            if (!$emp)
                throw new Exception('موظف غير موجود');

            // رصيد الحسابين الفرعيين الحقيقي من شجرة الحسابات — نفس اللحظة،
            // بغض النظر عن أي فلترة بتاريخ (الرصيد الحالي الفعلي دايماً)
            $balances = ['payable' => null, 'loan' => null];
            if ($emp['payable_account_id']) {
                $b = $pdo->prepare("SELECT code,name,balance,base_balance FROM `{$TAC}` WHERE id=?");
                $b->execute([$emp['payable_account_id']]);
                $balances['payable'] = $b->fetch(PDO::FETCH_ASSOC);
            }
            if ($emp['loan_account_id']) {
                $b = $pdo->prepare("SELECT code,name,balance,base_balance FROM `{$TAC}` WHERE id=?");
                $b->execute([$emp['loan_account_id']]);
                $balances['loan'] = $b->fetch(PDO::FETCH_ASSOC);
            }

            // سجل الرواتب بالفترة
            $ps = $pdo->prepare("SELECT * FROM `{$TP}` WHERE employee_id=? AND period_from>=? AND period_from<=? ORDER BY period_from DESC");
            $ps->execute([$empId, $dateFrom, $dateTo]);
            $payrolls = $ps->fetchAll(PDO::FETCH_ASSOC);

            // السلف (كل السلف بغض النظر عن الفترة — تاريخ حياة السلفة أهم من فلترة شهر واحد)
            $ls = $pdo->prepare("SELECT * FROM `{$TL}` WHERE employee_id=? ORDER BY loan_date DESC");
            $ls->execute([$empId]);
            $loans = $ls->fetchAll(PDO::FETCH_ASSOC);

            // المكافآت بالفترة
            $bs = $pdo->prepare("SELECT * FROM `{$TB}` WHERE employee_id=? AND bonus_date>=? AND bonus_date<=? ORDER BY bonus_date DESC");
            $bs->execute([$empId, $dateFrom, $dateTo]);
            $bonuses = $bs->fetchAll(PDO::FETCH_ASSOC);

            // ملخص حضور بالفترة
            $as = $pdo->prepare("SELECT attendance_status, COUNT(*) AS cnt, COALESCE(SUM(hours_worked),0) AS hrs, COALESCE(SUM(overtime_hours),0) AS ot
                FROM `{$TA}` WHERE employee_id=? AND attendance_date>=? AND attendance_date<=? GROUP BY attendance_status");
            $as->execute([$empId, $dateFrom, $dateTo]);
            $attRows = $as->fetchAll(PDO::FETCH_ASSOC);
            $attSummary = ['present' => 0, 'absent' => 0, 'late' => 0, 'half_day' => 0, 'holiday' => 0, 'total_hours' => 0, 'total_ot' => 0];
            foreach ($attRows as $r) {
                $attSummary[$r['attendance_status']] = (int) $r['cnt'];
                $attSummary['total_hours'] += (float) $r['hrs'];
                $attSummary['total_ot'] += (float) $r['ot'];
            }

            echo json_encode([
                'ok' => true,
                'employee' => $emp,
                'balances' => $balances,
                'payrolls' => $payrolls,
                'loans' => $loans,
                'bonuses' => $bonuses,
                'attendance' => $attSummary,
            ]);
            exit;
        }
        throw new Exception('إجراء غير معروف');
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

$employees = $pdo->query("SELECT id, full_name, department, position FROM `{$TE}` WHERE status='active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

$salaryTypeLabels = ['monthly' => 'شهري', 'weekly' => 'أسبوعي', 'daily' => 'يومي', 'hourly' => 'ساعي'];
$statusLabels = ['pending' => ['انتظار', '#d97706', '#fffbeb'], 'accrued' => ['معتمد', '#7c3aed', '#f5f3ff'], 'paid' => ['مصروف', '#16a34a', '#f0fdf4']];
$bonusLabels = ['performance' => 'أداء', 'holiday' => 'عيد', 'commission' => 'عمولة', 'transport' => 'نقل', 'housing' => 'سكن', 'other' => 'أخرى'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>تقارير الموارد البشرية — <?= htmlspecialchars($branchName) ?></title>
    <link rel="icon" href="<?= BASE_PATH ?>/assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/assets/css/layout.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Cairo', sans-serif
        }

        .stat-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            padding: .9rem 1.25rem;
            display: flex;
            align-items: center;
            gap: .9rem
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0
        }

        .stat-val {
            font-size: 1.3rem;
            font-weight: 700;
            line-height: 1.1
        }

        .stat-lbl {
            font-size: .73rem;
            color: #64748b;
            margin-top: .2rem
        }

        .section-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            padding: 1.1rem 1.25rem;
            margin-bottom: 1rem
        }

        .section-card h6 {
            font-size: .88rem;
            font-weight: 700;
            margin-bottom: .8rem;
            display: flex;
            align-items: center;
            gap: .4rem
        }

        table.mini-table {
            width: 100%;
            font-size: .8rem
        }

        table.mini-table th {
            color: #94a3b8;
            font-weight: 600;
            font-size: .72rem;
            padding: .4rem .5rem;
            border-bottom: 1px solid #f1f5f9;
            text-align: right
        }

        table.mini-table td {
            padding: .5rem;
            border-bottom: 1px solid #f8fafc
        }

        .badge-status {
            font-size: .7rem;
            padding: .25rem .6rem;
            border-radius: 20px;
            font-weight: 600
        }

        .empty-hint {
            text-align: center;
            color: #94a3b8;
            font-size: .82rem;
            padding: 1.5rem 0
        }
    </style>
</head>

<body>
    <?php
    require_once __DIR__ . '/../../../includes/sidebar.php';
    ?>

    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-file-earmark-bar-graph me-1 text-primary"></i>تقارير الموارد البشرية</span>
        <span class="tb-branch"><i class="bi bi-file-earmark-bar-graph me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <?= renderBreadcrumb() ?>
    </header>

    <main class="main-content">
        <div class="content-body">

            <!-- تبويبات -->
            <ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e2e8f0">
                <li class="nav-item">
                    <a class="nav-link fw-600" href="employees.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-people-fill me-1"></i>الموظفون
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="attendance.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-calendar-check me-1"></i>الحضور والانصراف
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="payroll.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-cash-stack me-1"></i>الرواتب والسلف والمكافآت
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600 active" href="reports.php"
                        style="border:none;border-bottom:2px solid #1e3a8a;color:#1e3a8a;font-size:.83rem;margin-bottom:-2px">
                        <i class="bi bi-file-earmark-bar-graph me-1"></i>التقارير
                    </a>
                </li>
            </ul>

            <!-- اختيار نوع التقرير (مخطَّط لاحقاً — كشف الموظف بس شغّال حالياً) -->
            <ul class="nav nav-pills mb-3" style="font-size:.8rem">
                <li class="nav-item">
                    <span class="nav-link active" style="background:#1e3a8a;border-radius:8px">
                        <i class="bi bi-person-lines-fill me-1"></i>كشف حساب موظف
                    </span>
                </li>
                <li class="nav-item">
                    <span class="nav-link disabled" style="color:#cbd5e1" title="قريباً">
                        <i class="bi bi-calendar-week me-1"></i>ملخص حضور وغياب
                    </span>
                </li>
                <li class="nav-item">
                    <span class="nav-link disabled" style="color:#cbd5e1" title="قريباً">
                        <i class="bi bi-cash-coin me-1"></i>تكلفة الرواتب حسب القسم
                    </span>
                </li>
            </ul>

            <!-- فلاتر -->
            <div class="section-card">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small fw-600 text-secondary mb-1">الموظف</label>
                        <select id="fEmp" class="form-select form-select-sm">
                            <option value="">— اختر موظف —</option>
                            <?php foreach ($employees as $e): ?>
                                <option value="<?= $e['id'] ?>">
                                    <?= htmlspecialchars($e['full_name']) ?> — <?= htmlspecialchars($e['position']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-600 text-secondary mb-1">من تاريخ</label>
                        <input type="date" id="fFrom" class="form-control form-control-sm"
                            value="<?= date('Y-m-01') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-600 text-secondary mb-1">إلى تاريخ</label>
                        <input type="date" id="fTo" class="form-control form-control-sm" value="<?= date('Y-m-t') ?>">
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-sm btn-primary w-100" style="border-radius:8px" onclick="loadStatement()">
                            <i class="bi bi-search me-1"></i>عرض الكشف
                        </button>
                    </div>
                </div>
            </div>

            <div id="statementWrap" style="display:none">

                <!-- رأس الموظف + الأرصدة الحقيقية من شجرة الحسابات -->
                <div class="row g-2 mb-3">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-icon" style="background:#eff6ff;color:#1d4ed8"><i
                                    class="bi bi-person-badge"></i></div>
                            <div>
                                <div class="stat-val" id="sEmpName" style="font-size:.95rem">—</div>
                                <div class="stat-lbl" id="sEmpMeta">—</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-icon" style="background:#f5f3ff;color:#7c3aed"><i
                                    class="bi bi-journal-check"></i></div>
                            <div>
                                <div class="stat-val" id="sPayableBal">—</div>
                                <div class="stat-lbl">رصيد "مستحقات الموظف" الحالي (GL)</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-icon" style="background:#fef2f2;color:#dc2626"><i
                                    class="bi bi-cash-coin"></i></div>
                            <div>
                                <div class="stat-val" id="sLoanBal">—</div>
                                <div class="stat-lbl">رصيد "سلف الموظف" الحالي (GL)</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-icon" style="background:#f0fdf4;color:#16a34a"><i
                                    class="bi bi-clock-history"></i></div>
                            <div>
                                <div class="stat-val" id="sAttHours">—</div>
                                <div class="stat-lbl">ساعات العمل بالفترة</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-lg-6">
                        <div class="section-card">
                            <h6><i class="bi bi-cash-stack text-primary"></i>سجل الرواتب بالفترة</h6>
                            <div id="payrollTable"></div>
                        </div>
                        <div class="section-card">
                            <h6><i class="bi bi-calendar-check text-success"></i>ملخص الحضور بالفترة</h6>
                            <div id="attTable"></div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="section-card">
                            <h6><i class="bi bi-cash-coin text-danger"></i>السلف (كل السجل)</h6>
                            <div id="loansTable"></div>
                        </div>
                        <div class="section-card">
                            <h6><i class="bi bi-gift text-warning"></i>المكافآت بالفترة</h6>
                            <div id="bonusTable"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="emptyHint" class="empty-hint">
                <i class="bi bi-arrow-up-circle" style="font-size:1.5rem"></i><br>
                اختر موظف وفترة ثم اضغط "عرض الكشف"
            </div>

        </div>
    </main>

    <script>
        const STATUS_LABELS = <?= json_encode($statusLabels, JSON_UNESCAPED_UNICODE) ?>;
        const BONUS_LABELS = <?= json_encode($bonusLabels, JSON_UNESCAPED_UNICODE) ?>;
        const SALARY_TYPE_LABELS = <?= json_encode($salaryTypeLabels, JSON_UNESCAPED_UNICODE) ?>;

        function post(data) {
            const fd = new FormData();
            for (const k in data) fd.append(k, data[k]);
            return fetch(location.href, { method: 'POST', body: fd }).then(r => r.json());
        }
        function fmt(n) { return (parseFloat(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

        async function loadStatement() {
            const empId = document.getElementById('fEmp').value;
            if (!empId) { alert('اختر موظف'); return; }
            const d = await post({
                _action: 'employee_statement',
                employee_id: empId,
                date_from: document.getElementById('fFrom').value,
                date_to: document.getElementById('fTo').value,
            });
            if (!d.ok) { alert(d.msg); return; }

            document.getElementById('emptyHint').style.display = 'none';
            document.getElementById('statementWrap').style.display = '';

            const emp = d.employee;
            document.getElementById('sEmpName').textContent = emp.full_name;
            document.getElementById('sEmpMeta').textContent =
                (emp.position || '') + ' · ' + (emp.department || '') + ' · ' + (SALARY_TYPE_LABELS[emp.salary_type] || emp.salary_type);

            const cur = emp.cur_symbol || emp.cur_code || '';
            document.getElementById('sPayableBal').textContent =
                d.balances.payable ? fmt(d.balances.payable.base_balance) + ' ' + cur : '— (بدون حساب)';
            document.getElementById('sLoanBal').textContent =
                d.balances.loan ? fmt(d.balances.loan.base_balance) + ' ' + cur : '— (بدون حساب)';
            document.getElementById('sAttHours').textContent =
                fmt(d.attendance.total_hours) + (d.attendance.total_ot > 0 ? ' (+' + fmt(d.attendance.total_ot) + ' إضافي)' : '');

            // سجل الرواتب
            const pt = document.getElementById('payrollTable');
            if (!d.payrolls.length) {
                pt.innerHTML = '<div class="empty-hint">لا يوجد رواتب بهذه الفترة</div>';
            } else {
                pt.innerHTML = '<table class="mini-table"><thead><tr><th>الفترة</th><th>إجمالي</th><th>خصم سلفة</th><th>الصافي</th><th>الحالة</th></tr></thead><tbody>' +
                    d.payrolls.map(p => {
                        const gross = (parseFloat(p.basic_salary) || 0) + (parseFloat(p.overtime_amount) || 0) + (parseFloat(p.bonus_total) || 0);
                        const st = STATUS_LABELS[p.payment_status] || ['—', '#94a3b8', '#f8fafc'];
                        return `<tr>
                        <td>${p.period_from} → ${p.period_to}</td>
                        <td>${fmt(gross)}</td>
                        <td style="color:#dc2626">${p.loan_deduction > 0 ? '-' + fmt(p.loan_deduction) : '—'}</td>
                        <td class="fw-600">${fmt(p.net_salary)}</td>
                        <td><span class="badge-status" style="background:${st[2]};color:${st[1]}">${st[0]}</span></td>
                    </tr>`;
                    }).join('') + '</tbody></table>';
            }

            // السلف
            const lt = document.getElementById('loansTable');
            if (!d.loans.length) {
                lt.innerHTML = '<div class="empty-hint">لا يوجد سلف مسجّلة</div>';
            } else {
                lt.innerHTML = '<table class="mini-table"><thead><tr><th>التاريخ</th><th>المبلغ</th><th>الأقساط</th><th>الحالة</th></tr></thead><tbody>' +
                    d.loans.map(l => {
                        const stMap = { active: ['نشطة', '#d97706'], completed: ['مسدَّدة', '#16a34a'], cancelled: ['ملغاة', '#94a3b8'] };
                        const st = stMap[l.status] || ['—', '#94a3b8'];
                        return `<tr>
                        <td>${l.loan_date}</td>
                        <td>${fmt(l.amount)}</td>
                        <td>${l.paid_installments} / ${l.installments}</td>
                        <td><span style="color:${st[1]};font-weight:600;font-size:.75rem">${st[0]}</span></td>
                    </tr>`;
                    }).join('') + '</tbody></table>';
            }

            // المكافآت
            const bt = document.getElementById('bonusTable');
            if (!d.bonuses.length) {
                bt.innerHTML = '<div class="empty-hint">لا يوجد مكافآت بهذه الفترة</div>';
            } else {
                bt.innerHTML = '<table class="mini-table"><thead><tr><th>التاريخ</th><th>النوع</th><th>المبلغ</th><th>الحالة</th></tr></thead><tbody>' +
                    d.bonuses.map(b => `<tr>
                        <td>${b.bonus_date}</td>
                        <td>${BONUS_LABELS[b.bonus_type] || b.bonus_type}</td>
                        <td>${fmt(b.amount)}</td>
                        <td>${b.status === 'cancelled' ? '<span style="color:#94a3b8">ملغاة</span>' : '<span style="color:#16a34a">فعّالة</span>'}</td>
                    </tr>`).join('') + '</tbody></table>';
            }

            // ملخص الحضور
            const att = d.attendance;
            const attMap = [['present', 'حاضر', '#16a34a'], ['absent', 'غائب', '#dc2626'], ['late', 'متأخر', '#d97706'], ['half_day', 'نصف يوم', '#3b82f6'], ['holiday', 'عطلة', '#7c3aed']];
            document.getElementById('attTable').innerHTML =
                '<div class="d-flex flex-wrap gap-2">' +
                attMap.map(([k, lbl, clr]) => `<div style="background:${clr}11;border:1px solid ${clr}33;border-radius:8px;padding:.4rem .8rem;font-size:.78rem">
                    <span style="color:${clr};font-weight:700">${att[k] || 0}</span> ${lbl}
                </div>`).join('') + '</div>';
        }
    </script>
</body>

</html>
