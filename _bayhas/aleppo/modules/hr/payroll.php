<?php
/**
 * payroll.php — View
 * المسار: /bayhas/aleppo/modules/hr/payroll.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('hr.payroll', 'view');

$branchName = $_SESSION['branch_name'] ?? 'الفرع';
$TS = $_SESSION['table_suffix'];
$TE = "hr_employees_{$TS}";
$TP = "hr_payroll_{$TS}";
$TL = "hr_loans_{$TS}";
$TB = "hr_bonuses_{$TS}";

// إنشاء الجداول إذا لم تكن موجودة
$tables_sql = [
    "CREATE TABLE IF NOT EXISTS `{$TP}` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `employee_id` int(11) NOT NULL,
        `payroll_month` date NOT NULL,
        `week_number` tinyint(1) NOT NULL DEFAULT 0,
        `period_from` date NOT NULL,
        `period_to` date NOT NULL,
        `basic_salary` decimal(12,2) NOT NULL DEFAULT 0,
        `working_days` int(11) NOT NULL DEFAULT 0,
        `working_hours` decimal(6,2) NOT NULL DEFAULT 0,
        `overtime_hours` decimal(6,2) NOT NULL DEFAULT 0,
        `overtime_amount` decimal(12,2) NOT NULL DEFAULT 0,
        `bonus_total` decimal(12,2) NOT NULL DEFAULT 0,
        `loan_deduction` decimal(12,2) NOT NULL DEFAULT 0,
        `net_salary` decimal(12,2) NOT NULL DEFAULT 0,
        `currency_id` int(11) NOT NULL DEFAULT 1,
        `payment_status` enum('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
        `payment_date` date DEFAULT NULL,
        `payment_method` enum('cash','bank_transfer') DEFAULT 'cash',
        `notes` text DEFAULT NULL,
        `created_by` int(11) DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_emp_period` (`employee_id`,`period_from`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS `{$TL}` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `employee_id` int(11) NOT NULL,
        `loan_date` date NOT NULL,
        `amount` decimal(12,2) NOT NULL,
        `currency_id` int(11) NOT NULL DEFAULT 1,
        `installments` int(11) NOT NULL DEFAULT 1,
        `paid_installments` int(11) NOT NULL DEFAULT 0,
        `monthly_deduction` decimal(12,2) NOT NULL DEFAULT 0,
        `reason` text DEFAULT NULL,
        `status` enum('active','completed','cancelled') NOT NULL DEFAULT 'active',
        `created_by` int(11) DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS `{$TB}` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `employee_id` int(11) NOT NULL,
        `bonus_date` date NOT NULL,
        `bonus_type` enum('performance','holiday','commission','transport','housing','other') NOT NULL DEFAULT 'performance',
        `amount` decimal(12,2) NOT NULL,
        `currency_id` int(11) NOT NULL DEFAULT 1,
        `description` text DEFAULT NULL,
        `created_by` int(11) DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
foreach ($tables_sql as $sql) { try { $pdo->exec($sql); } catch (Throwable $e) {} }

// ── جلب البيانات ──────────────────────────────────────────────────
$sel_month  = $_GET['month'] ?? date('Y-m');
[$sy, $sm]  = explode('-', $sel_month);
$month_from = "$sy-$sm-01";
$month_to   = date('Y-m-t', strtotime($month_from));

$employees = $pdo->query("SELECT e.*,c.symbol AS cur_sym,c.code AS cur_code
    FROM `{$TE}` e LEFT JOIN currencies c ON c.id=e.currency_id
    WHERE e.status='active' AND e.hire_date<='{$month_to}'
    ORDER BY e.full_name")->fetchAll();

$payrolls_stmt = $pdo->prepare("SELECT * FROM `{$TP}` WHERE payroll_month=? ORDER BY week_number");
$payrolls_stmt->execute([$month_from]);
$payrolls = [];
foreach ($payrolls_stmt->fetchAll() as $r) {
    $payrolls[$r['employee_id']][] = $r;
}

$loans_all = $pdo->query("SELECT l.*,e.full_name,c.symbol AS cur_sym
    FROM `{$TL}` l JOIN `{$TE}` e ON e.id=l.employee_id
    LEFT JOIN currencies c ON c.id=l.currency_id
    ORDER BY l.created_at DESC")->fetchAll();

$bonuses_all = $pdo->query("SELECT b.*,e.full_name,c.symbol AS cur_sym
    FROM `{$TB}` b JOIN `{$TE}` e ON e.id=b.employee_id
    LEFT JOIN currencies c ON c.id=b.currency_id
    ORDER BY b.bonus_date DESC LIMIT 40")->fetchAll();

$currencies = $pdo->query("SELECT * FROM currencies WHERE status='active' ORDER BY is_base DESC")->fetchAll();

// إحصائيات
$total_net     = 0; $paid_c = 0; $pending_c = 0;
foreach ($payrolls as $emp_rows) {
    foreach ($emp_rows as $r) {
        $total_net += (float)$r['net_salary'];
        if ($r['payment_status']==='paid')    $paid_c++;
        if ($r['payment_status']==='pending') $pending_c++;
    }
}
$total_loan_ded = array_sum(array_column(
    array_filter($loans_all, function($l){ return $l['status']==='active'; }),
    'monthly_deduction'
));

$bonus_labels = ['performance'=>'أداء','holiday'=>'عيد','commission'=>'عمولة',
                 'transport'=>'نقل','housing'=>'سكن','other'=>'أخرى'];
$colors = ['#3b82f6','#8b5cf6','#10b981','#f59e0b','#ef4444','#06b6d4'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>الرواتب — <?= htmlspecialchars($branchName) ?></title>
<link rel="icon" href="/bayhas/assets/images/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="/bayhas/assets/css/layout.css" rel="stylesheet">
<style>
.stat-card{background:#fff;border-radius:14px;border:1px solid #e2e8f0;padding:.9rem 1.25rem;display:flex;align-items:center;gap:.9rem;transition:all .2s}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 4px 16px rgba(0,0,0,.06)}
.stat-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0}
.stat-val{font-size:1.35rem;font-weight:700;line-height:1.1}
.stat-lbl{font-size:.73rem;color:#64748b;margin-top:.2rem}
.ctrl-bar{background:#fff;border-radius:14px;border:1px solid #e2e8f0;padding:.7rem 1.25rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap}
.tab-wrap{display:flex;gap:4px;background:#f1f5f9;border-radius:10px;padding:3px}
.t-btn{padding:.4rem 1rem;border-radius:8px;font-size:.82rem;cursor:pointer;color:#64748b;border:none;background:none;font-family:'Cairo',sans-serif;font-weight:600;transition:all .15s}
.t-btn.act{background:#fff;color:#1e3a8a;box-shadow:0 1px 4px rgba(0,0,0,.08)}
.sec-card{background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:1.25rem}
.sec-card table{margin:0;font-size:.83rem}
.sec-card th{background:#f8fafc;color:#64748b;font-size:.76rem;font-weight:600;border:none;padding:.65rem 1rem;white-space:nowrap}
.sec-card td{padding:.6rem 1rem;vertical-align:middle;border-top:1px solid #f1f5f9}
.sec-card tbody tr:hover td{background:#f8fafc}
.sec-hdr{padding:.75rem 1.25rem;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
.avatar{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;color:#fff;flex-shrink:0}
.n{font-variant-numeric:tabular-nums;font-size:.83rem}
.np{color:#16a34a;font-weight:600}.nn{color:#dc2626;font-weight:600}.nm{color:#94a3b8}
.ab{width:28px;height:28px;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer;font-size:.85rem;transition:all .15s}
.ab:hover{background:#eff6ff;color:#2563eb;border-color:#bfdbfe}
.ab.r:hover{background:#fef2f2;color:#dc2626;border-color:#fca5a5}
.add-btn{padding:4px 10px;border-radius:8px;font-size:.77rem;font-weight:600;border:1px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer;font-family:'Cairo',sans-serif;display:flex;align-items:center;gap:3px;transition:all .15s}
.add-btn:hover{background:#f0fdf4;color:#16a34a;border-color:#bbf7d0}
.pay-row-btn{padding:3px 10px;border-radius:7px;font-size:.77rem;font-weight:600;border:none;cursor:pointer;font-family:'Cairo',sans-serif;background:#1e3a8a;color:#fff;transition:all .15s}
.pay-row-btn:hover{background:#1d4ed8}
.calc-row-btn{padding:3px 10px;border-radius:7px;font-size:.77rem;font-weight:600;border:1px solid #bfdbfe;cursor:pointer;font-family:'Cairo',sans-serif;background:#eff6ff;color:#1d4ed8;transition:all .15s}
.calc-row-btn:hover{background:#dbeafe}
/* مودال الراتب */
.modal-content{border-radius:16px;border:none}
.mhdr-pay  {background:linear-gradient(135deg,#1e3a8a,#2563eb)}
.mhdr-loan {background:linear-gradient(135deg,#7c3aed,#8b5cf6)}
.mhdr-bonus{background:linear-gradient(135deg,#b45309,#d97706)}
.period-opt{display:block;padding:.5rem .75rem;border-radius:9px;border:1px solid #e2e8f0;cursor:pointer;font-size:.83rem;transition:all .15s;margin-bottom:5px}
.period-opt:hover{border-color:#3b82f6;background:#eff6ff}
.period-opt input{display:none}
.period-opt.sel{border-color:#1e3a8a;background:#eff6ff;font-weight:600}
.period-opt.paid{border-color:#bbf7d0;background:#f0fdf4;color:#16a34a}
.period-opt.paid::after{content:'✓ مصروف';margin-right:.4rem;font-size:.75rem}
.pay-sum{background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;padding:.75rem 1rem}
.ps-row{display:flex;justify-content:space-between;font-size:.82rem;padding:3px 0}
.ps-row.tot{border-top:1px solid #e2e8f0;margin-top:5px;padding-top:7px;font-weight:700}
</style>
</head>
<body>
<div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
<?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>

<header class="topbar">
    <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
    <span class="tb-title">الرواتب والسلف والمكافآت</span>
    <span class="tb-branch"><i class="bi bi-cash-stack me-1"></i><?= htmlspecialchars($branchName) ?></span>
    <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.8rem;color:#94a3b8">
        <span>الموارد البشرية</span><i class="bi bi-chevron-left mx-1" style="font-size:.7rem"></i>
        <span class="text-primary">الرواتب</span>
    </nav>
</header>

<main class="main-content">
<div class="content-body">

<!-- إحصائيات -->
<div class="row g-3 mb-3">
    <?php foreach ([
        [number_format($total_net,0), 'إجمالي الرواتب', 'bi-cash-stack', '#16a34a','#f0fdf4'],
        [$paid_c,    'تم صرفها',      'bi-check-circle-fill','#2563eb','#eff6ff'],
        [$pending_c, 'بانتظار الصرف', 'bi-hourglass-split',  '#d97706','#fffbeb'],
        [number_format($total_loan_ded,0), 'أقساط السلف', 'bi-credit-card','#dc2626','#fef2f2'],
    ] as [$v,$l,$ic,$clr,$bg]): ?>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:<?=$bg?>;color:<?=$clr?>"><i class="bi <?=$ic?>"></i></div>
            <div><div class="stat-val" style="color:<?=$clr?>"><?=$v?></div><div class="stat-lbl"><?=$l?></div></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- شريط التحكم -->
<div class="ctrl-bar">
    <div class="tab-wrap">
        <button class="t-btn act" id="tabPayroll"  onclick="switchTab('Payroll')"><i class="bi bi-table me-1"></i>كشف الرواتب</button>
        <button class="t-btn"     id="tabLoans"    onclick="switchTab('Loans')"><i class="bi bi-credit-card me-1"></i>السلف</button>
        <button class="t-btn"     id="tabBonuses"  onclick="switchTab('Bonuses')"><i class="bi bi-trophy me-1"></i>المكافآت</button>
    </div>
    <input type="month" id="monthPicker" class="form-control form-control-sm"
           style="width:155px;border-radius:8px" value="<?= $sel_month ?>"
           onchange="location.href='?month='+this.value">
</div>

<!-- ══ تبويب الرواتب ══ -->
<div id="secPayroll">
<div class="sec-card">
    <div class="sec-hdr">
        <span style="font-size:.88rem;font-weight:700;color:#1e293b">
            <i class="bi bi-table me-2 text-primary"></i>
            كشف رواتب <?= date('F Y', strtotime($month_from)) ?>
        </span>
    </div>
    <div class="table-responsive">
    <table>
        <thead><tr>
            <th>#</th><th>الموظف</th><th>نوع الراتب</th>
            <th>الفترات المصروفة</th><th>إجمالي الشهر</th><th style="text-align:center">إجراء</th>
        </tr></thead>
        <tbody>
        <?php foreach ($employees as $i => $emp):
            $clr      = $colors[$emp['id'] % count($colors)];
            $ini      = mb_substr($emp['full_name'],0,1);
            $is_wk    = ($emp['salary_type'] === 'weekly');
            $emp_rows = $payrolls[$emp['id']] ?? [];
            $paid_cnt = count(array_filter($emp_rows, function($r){ return $r['payment_status']==='paid'; }));
            $total_periods = $is_wk ? 4 : 1;
            $emp_net  = array_sum(array_column($emp_rows, 'net_salary'));
        ?>
        <tr>
            <td class="n nm"><?=$i+1?></td>
            <td>
                <div class="d-flex align-items-center gap-2">
                    <div class="avatar" style="background:<?=$clr?>"><?=htmlspecialchars($ini)?></div>
                    <div>
                        <div class="fw-600" style="font-size:.86rem"><?=htmlspecialchars($emp['full_name'])?></div>
                        <div class="nm" style="font-size:.72rem"><?=htmlspecialchars($emp['position'])?></div>
                    </div>
                </div>
            </td>
            <td>
                <span class="badge <?=$is_wk?'bg-purple-subtle':'bg-info-subtle'?>"
                      style="font-size:.72rem;background:<?=$is_wk?'#f5f3ff':'#eff6ff'?>;color:<?=$is_wk?'#7c3aed':'#1d4ed8'?>;border:1px solid <?=$is_wk?'#ddd6fe':'#bfdbfe'?>">
                    <?=$is_wk?'أسبوعي':'شهري'?>
                </span>
            </td>
            <td>
                <?php if (empty($emp_rows)): ?>
                <span class="nm" style="font-size:.79rem">لم يُحتسب بعد</span>
                <?php else: ?>
                <div class="d-flex align-items-center gap-1">
                    <?php foreach ($emp_rows as $r):
                        $isPaid = $r['payment_status']==='paid';
                        $lbl    = $is_wk ? 'أ'.($r['week_number']) : date('M',strtotime($r['period_from']));
                    ?>
                    <span style="font-size:.72rem;padding:2px 6px;border-radius:5px;
                          background:<?=$isPaid?'#f0fdf4':'#fffbeb'?>;
                          color:<?=$isPaid?'#16a34a':'#d97706'?>;
                          border:1px solid <?=$isPaid?'#bbf7d0':'#fde68a'?>">
                        <?=$lbl?> <?=$isPaid?'✓':'⏳'?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </td>
            <td class="n fw-600">
                <?=$emp_net>0 ? number_format($emp_net,0).' '.$emp['cur_sym'] : '<span class="nm">—</span>'?>
            </td>
            <td style="text-align:center">
                <button class="pay-row-btn" onclick="openPayrollModal(<?=$emp['id']?>)"
                        data-emp-id="<?=$emp['id']?>">
                    <i class="bi bi-calculator me-1"></i>احتساب / صرف
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
</div>

<!-- ══ تبويب السلف ══ -->
<div id="secLoans" style="display:none">
<div class="sec-card">
    <div class="sec-hdr">
        <span style="font-size:.88rem;font-weight:700;color:#1e293b"><i class="bi bi-credit-card me-2" style="color:#7c3aed"></i>السلف والأقساط</span>
        <button class="add-btn" onclick="openLoanModal()"><i class="bi bi-plus-circle"></i> سلفة جديدة</button>
    </div>
    <div class="table-responsive">
    <table>
        <thead><tr><th>#</th><th>الموظف</th><th>تاريخ السلفة</th><th>المبلغ</th><th>القسط/شهر</th><th>التقدم</th><th>المتبقي</th><th>الحالة</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($loans_all as $j => $loan):
            $remain = max(0, $loan['amount'] - $loan['paid_installments']*$loan['monthly_deduction']);
            $pct    = $loan['installments']>0 ? min(100, round($loan['paid_installments']/$loan['installments']*100)) : 0;
            [$sl,$sc] = ['active'=>['نشط','warning'],'completed'=>['مكتمل','success'],'cancelled'=>['ملغي','secondary']][$loan['status']] ?? ['—','secondary'];
        ?>
        <tr>
            <td class="n nm"><?=$j+1?></td>
            <td><div class="d-flex align-items-center gap-2">
                <div class="avatar" style="background:<?=$colors[$loan['employee_id']%count($colors)]?>"><?=mb_substr($loan['full_name'],0,1)?></div>
                <span class="fw-600" style="font-size:.86rem"><?=htmlspecialchars($loan['full_name'])?></span>
            </div></td>
            <td class="n nm"><?=$loan['loan_date']?></td>
            <td class="n fw-600"><?=number_format($loan['amount'],0)?> <?=$loan['cur_sym']?></td>
            <td class="n nn"><?=number_format($loan['monthly_deduction'],0)?></td>
            <td>
                <div class="d-flex align-items-center gap-1">
                    <div class="progress" style="width:55px;height:5px;border-radius:3px;background:#e2e8f0">
                        <div class="progress-bar" style="width:<?=$pct?>%;background:#3b82f6"></div>
                    </div>
                    <span class="n nm" style="font-size:.74rem"><?=$loan['paid_installments']?>/<?=$loan['installments']?></span>
                </div>
            </td>
            <td class="n <?=$remain>0?'nn':'np'?>"><?=$remain>0?number_format($remain,0):'مكتمل'?></td>
            <td><span class="badge bg-<?=$sc?>-subtle text-<?=$sc?>" style="font-size:.7rem"><?=$sl?></span></td>
            <td><?php if($loan['status']==='active'): ?>
                <button class="ab r" onclick="deleteLoan(<?=$loan['id']?>)" title="إلغاء"><i class="bi bi-x"></i></button>
            <?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($loans_all)): ?><tr><td colspan="9" class="text-center nm py-4">لا توجد سلف</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
</div>

<!-- ══ تبويب المكافآت ══ -->
<div id="secBonuses" style="display:none">
<div class="sec-card">
    <div class="sec-hdr">
        <span style="font-size:.88rem;font-weight:700;color:#1e293b"><i class="bi bi-trophy me-2 text-warning"></i>المكافآت</span>
        <button class="add-btn" onclick="openBonusModal()"><i class="bi bi-plus-circle"></i> مكافأة جديدة</button>
    </div>
    <div class="table-responsive">
    <table>
        <thead><tr><th>#</th><th>الموظف</th><th>النوع</th><th>المبلغ</th><th>التاريخ</th><th>الوصف</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($bonuses_all as $j => $bon): ?>
        <tr>
            <td class="n nm"><?=$j+1?></td>
            <td><div class="d-flex align-items-center gap-2">
                <div class="avatar" style="background:<?=$colors[$bon['employee_id']%count($colors)]?>"><?=mb_substr($bon['full_name'],0,1)?></div>
                <span class="fw-600" style="font-size:.86rem"><?=htmlspecialchars($bon['full_name'])?></span>
            </div></td>
            <td><span class="badge bg-warning-subtle text-warning border border-warning-subtle" style="font-size:.72rem"><?=$bonus_labels[$bon['bonus_type']]??$bon['bonus_type']?></span></td>
            <td class="n np fw-600"><?=number_format($bon['amount'],0)?> <?=$bon['cur_sym']?></td>
            <td class="n nm"><?=$bon['bonus_date']?></td>
            <td class="nm" style="font-size:.8rem"><?=htmlspecialchars($bon['description']??'')?></td>
            <td><button class="ab r" onclick="deleteBonus(<?=$bon['id']?>)" title="حذف"><i class="bi bi-trash"></i></button></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($bonuses_all)): ?><tr><td colspan="7" class="text-center nm py-4">لا توجد مكافآت</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
</div>

</div>
</main>

<!-- ══ مودال احتساب/صرف الراتب ══ -->
<div class="modal fade" id="payrollModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-md">
    <div class="modal-content">
      <div class="modal-header mhdr-pay py-3 px-4 border-0" style="border-radius:16px 16px 0 0">
        <div>
          <h6 class="modal-title text-white fw-700 mb-0"><i class="bi bi-calculator me-2"></i><span id="mEmpName">—</span></h6>
          <div id="mEmpSub" style="font-size:.78rem;color:rgba(255,255,255,.75);margin-top:2px">—</div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 pt-3 pb-2">
        <input type="hidden" id="mEmpId">

        <!-- خطوة 1: اختيار الفترة -->
        <div id="mStep1">
          <div class="fw-600 mb-2" style="font-size:.84rem;color:#1e293b">
            <i class="bi bi-calendar3 me-1 text-primary"></i>اختر الفترة:
          </div>
          <div id="mPeriodsList" style="max-height:280px;overflow-y:auto"></div>
          <div class="mt-3 d-flex justify-content-end gap-2">
            <button class="btn btn-sm btn-light" data-bs-dismiss="modal" style="border-radius:8px">إلغاء</button>
            <button class="btn btn-sm btn-primary fw-600" style="border-radius:8px;min-width:100px" onclick="calcSelected()">
              <i class="bi bi-calculator me-1"></i>احتساب
            </button>
          </div>
        </div>

        <!-- خطوة 2: النتيجة والصرف -->
        <div id="mStep2" style="display:none">
          <div class="pay-sum mb-3" id="mCalcResult"></div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label small fw-600 text-secondary mb-1">طريقة الصرف</label>
              <select id="mPayMethod" class="form-select form-select-sm">
                <option value="cash">نقداً</option>
                <option value="bank_transfer">تحويل بنكي</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label small fw-600 text-secondary mb-1">تاريخ الصرف</label>
              <input type="date" id="mPayDate" class="form-control form-control-sm" value="<?=date('Y-m-d')?>">
            </div>
          </div>
          <div class="mb-1">
            <label class="form-label small fw-600 text-secondary mb-1">ملاحظات</label>
            <input type="text" id="mPayNotes" class="form-control form-control-sm" placeholder="اختياري">
          </div>
          <div class="mt-3 d-flex justify-content-between gap-2">
            <button class="btn btn-sm btn-outline-secondary" style="border-radius:8px" onclick="backToStep1()">
              <i class="bi bi-arrow-right me-1"></i>تغيير الفترة
            </button>
            <button class="btn btn-sm fw-600" style="border-radius:8px;min-width:120px;background:#1e3a8a;color:#fff" onclick="confirmPay()" id="mPayBtn">
              <span id="mPayTxt"><i class="bi bi-check2 me-1"></i>تأكيد الصرف</span>
              <span id="mPaySpin" class="spinner-border spinner-border-sm ms-1" style="display:none"></span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ══ مودال سلفة ══ -->
<div class="modal fade" id="loanModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header mhdr-loan py-3 px-4 border-0" style="border-radius:16px 16px 0 0">
      <h6 class="modal-title text-white fw-700 mb-0"><i class="bi bi-credit-card me-2"></i>إضافة سلفة</h6>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body px-4 pt-3 pb-2">
      <div class="mb-2">
        <label class="form-label small fw-600 text-secondary mb-1">الموظف</label>
        <select id="lEmp" class="form-select form-select-sm" onchange="calcDeduction()">
          <option value="">— اختر —</option>
          <?php foreach($employees as $e): ?>
          <option value="<?=$e['id']?>"><?=htmlspecialchars($e['full_name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="row g-2 mb-2">
        <div class="col-6"><label class="form-label small fw-600 text-secondary mb-1">المبلغ</label>
          <input type="number" id="lAmt" class="form-control form-control-sm" placeholder="0" oninput="calcDeduction()"></div>
        <div class="col-6"><label class="form-label small fw-600 text-secondary mb-1">عدد الأقساط</label>
          <input type="number" id="lInst" class="form-control form-control-sm" value="1" min="1" oninput="calcDeduction()"></div>
      </div>
      <div class="row g-2 mb-2">
        <div class="col-6"><label class="form-label small fw-600 text-secondary mb-1">التاريخ</label>
          <input type="date" id="lDate" class="form-control form-control-sm" value="<?=date('Y-m-d')?>"></div>
        <div class="col-6"><label class="form-label small fw-600 text-secondary mb-1">العملة</label>
          <select id="lCur" class="form-select form-select-sm">
            <?php foreach($currencies as $c): ?>
            <option value="<?=$c['id']?>"><?=htmlspecialchars($c['code'])?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div id="lCalc" class="mb-2 p-2" style="background:#f5f3ff;border-radius:8px;font-size:.8rem;display:none">
        القسط: <strong id="lCalcVal" style="color:#7c3aed">—</strong>
      </div>
      <div class="mb-1"><label class="form-label small fw-600 text-secondary mb-1">السبب</label>
        <input type="text" id="lReason" class="form-control form-control-sm" placeholder="اختياري"></div>
    </div>
    <div class="modal-footer border-0 px-4 pb-3 pt-1">
      <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal" style="border-radius:8px">إلغاء</button>
      <button type="button" class="btn btn-sm fw-600" style="border-radius:8px;min-width:100px;background:#7c3aed;color:#fff" onclick="saveLoan()">
        <i class="bi bi-check2 me-1"></i>حفظ
      </button>
    </div>
  </div></div>
</div>

<!-- ══ مودال مكافأة ══ -->
<div class="modal fade" id="bonusModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header mhdr-bonus py-3 px-4 border-0" style="border-radius:16px 16px 0 0">
      <h6 class="modal-title text-white fw-700 mb-0"><i class="bi bi-trophy me-2"></i>إضافة مكافأة</h6>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body px-4 pt-3 pb-2">
      <div class="mb-2"><label class="form-label small fw-600 text-secondary mb-1">الموظف</label>
        <select id="bEmp" class="form-select form-select-sm">
          <option value="">— اختر —</option>
          <?php foreach($employees as $e): ?>
          <option value="<?=$e['id']?>"><?=htmlspecialchars($e['full_name'])?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="row g-2 mb-2">
        <div class="col-6"><label class="form-label small fw-600 text-secondary mb-1">النوع</label>
          <select id="bType" class="form-select form-select-sm">
            <option value="performance">أداء</option><option value="holiday">عيد</option>
            <option value="commission">عمولة</option><option value="transport">نقل</option>
            <option value="housing">سكن</option><option value="other">أخرى</option>
          </select></div>
        <div class="col-6"><label class="form-label small fw-600 text-secondary mb-1">المبلغ</label>
          <input type="number" id="bAmt" class="form-control form-control-sm" placeholder="0"></div>
      </div>
      <div class="row g-2 mb-2">
        <div class="col-6"><label class="form-label small fw-600 text-secondary mb-1">التاريخ</label>
          <input type="date" id="bDate" class="form-control form-control-sm" value="<?=date('Y-m-d')?>"></div>
        <div class="col-6"><label class="form-label small fw-600 text-secondary mb-1">العملة</label>
          <select id="bCur" class="form-select form-select-sm">
            <?php foreach($currencies as $c): ?>
            <option value="<?=$c['id']?>"><?=htmlspecialchars($c['code'])?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div class="mb-1"><label class="form-label small fw-600 text-secondary mb-1">الوصف</label>
        <input type="text" id="bDesc" class="form-control form-control-sm" placeholder="اختياري"></div>
    </div>
    <div class="modal-footer border-0 px-4 pb-3 pt-1">
      <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal" style="border-radius:8px">إلغاء</button>
      <button type="button" class="btn btn-sm fw-600" style="border-radius:8px;min-width:100px;background:#b45309;color:#fff" onclick="saveBonus()">
        <i class="bi bi-check2 me-1"></i>حفظ
      </button>
    </div>
  </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const sb = document.getElementById('sidebar'), ov = document.getElementById('sbOverlay');
function sbOpen()  { sb.classList.add('open');  ov.classList.add('show'); }
function sbClose() { sb.classList.remove('open'); ov.classList.remove('show'); }
window.addEventListener('resize', () => { if(window.innerWidth>991) sbClose(); });
document.querySelectorAll('.sb-group').forEach(g => {
    if (localStorage.getItem('sb_open_'+g.dataset.key)==='true') g.classList.add('open');
});
function toggleGroup(g) {
    const o = g.classList.contains('open');
    document.querySelectorAll('.sb-group.open').forEach(x => x.classList.remove('open'));
    g.classList.toggle('open',!o);
    localStorage.setItem('sb_open_'+g.dataset.key,(!o).toString());
}

function switchTab(t) {
    ['Payroll','Loans','Bonuses'].forEach(x => {
        document.getElementById('sec'+x).style.display = x===t ? '' : 'none';
        document.getElementById('tab'+x).classList.toggle('act', x===t);
    });
}

const WEEK_START_DAY = <?= $_SESSION['week_start_day'] ?? 1 ?>;
const payModal   = new bootstrap.Modal(document.getElementById('payrollModal'));
const loanModal  = new bootstrap.Modal(document.getElementById('loanModal'));
const bonusModal = new bootstrap.Modal(document.getElementById('bonusModal'));

function post(data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k, v ?? ''));
    return fetch('../../api/payroll_api.php', {method:'POST', body:fd}).then(r => r.json());
}

function toast(msg, type='success') {
    const t = document.createElement('div');
    t.className = `alert alert-${type} shadow-sm`;
    t.style.cssText = 'position:fixed;top:76px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:240px;text-align:center;font-size:.85rem;padding:.55rem 1.25rem';
    const ic = type==='success' ? 'check-circle-fill text-success' : 'exclamation-triangle-fill text-danger';
    t.innerHTML = `<i class="bi bi-${ic} me-2"></i>${msg}`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3000);
}

function fmt(n) { return new Intl.NumberFormat('en-tr').format(Math.round(n||0)); }

// ══ مودال الراتب ══
let mCurrentEmp = null, mSelectedPeriod = null, mCalcData = null;

function openPayrollModal(empId) {
    mCurrentEmp = null; mSelectedPeriod = null; mCalcData = null;
    document.getElementById('mEmpId').value = empId;
    document.getElementById('mEmpName').textContent = '...';
    document.getElementById('mEmpSub').textContent  = '...';
    document.getElementById('mStep1').style.display = '';
    document.getElementById('mStep2').style.display = 'none';
    document.getElementById('mPeriodsList').innerHTML =
        '<div class="text-center nm py-3"><span class="spinner-border spinner-border-sm"></span></div>';
    payModal.show();

    post({_action:'get_emp_periods', employee_id:empId, month:'<?= $sel_month ?>'}).then(d => {
        if (!d.ok) { toast(d.msg,'danger'); payModal.hide(); return; }
        mCurrentEmp = d.emp;
        document.getElementById('mEmpName').textContent = d.emp.full_name;
        document.getElementById('mEmpSub').textContent  =
            (d.emp.salary_type==='weekly'?'أسبوعي':'شهري') + ' · ' + fmt(d.emp.basic_salary) + ' ' + (d.emp.cur_sym||'');

        // عرض الفترات
        const list = document.getElementById('mPeriodsList');
        list.innerHTML = d.periods.map((p,i) => {
            const isPaid = p.status === 'paid';
            return `<label class="period-opt ${isPaid?'paid':''}" id="popt_${i}">
                <input type="radio" name="mPeriod" value="${i}" ${isPaid?'disabled':''} onchange="selectPeriod(${i})">
                <div class="d-flex justify-content-between align-items-center">
                    <span>${p.label}</span>
                    ${isPaid
                        ? '<span style="font-size:.73rem;color:#16a34a;font-weight:600">✓ مصروف</span>'
                        : p.status === 'pending'
                            ? '<span style="font-size:.73rem;color:#d97706;font-weight:600">⏳ انتظار</span>'
                            : '<span style="font-size:.73rem;color:#94a3b8">لم يُحتسب</span>'}
                </div>
            </label>`;
        }).join('');

        // السلف
        if (d.loans.length > 0) {
            const totalDed = d.loans.reduce((s,l)=>s+(parseFloat(l.monthly_deduction)||0), 0);
            list.innerHTML += `<div class="mt-2 p-2" style="background:#fff5f5;border-radius:8px;font-size:.8rem">
                <i class="bi bi-credit-card me-1 text-danger"></i>
                إجمالي أقساط السلف: <strong class="text-danger">${fmt(totalDed)}</strong>
                ${d.emp.salary_type==='weekly' ? '<span class="nm"> (يُوزَّع على 4 أسابيع)</span>' : ''}
            </div>`;
        }

        // اختر أول فترة غير مصروفة تلقائياً
        const first = d.periods.findIndex(p => p.status !== 'paid');
        if (first >= 0) {
            document.querySelector(`input[name="mPeriod"][value="${first}"]`)?.click();
        }
    });
}

let mPeriods = [];
function selectPeriod(i) {
    document.querySelectorAll('.period-opt').forEach((el,j) => {
        el.classList.toggle('sel', j===i);
    });
    // نحتفظ بالبيانات للاستخدام لاحقاً
    const listEl = document.getElementById('mPeriodsList');
    mSelectedPeriod = i;
}

function calcSelected() {
    const sel = document.querySelector('input[name="mPeriod"]:checked');
    if (!sel) { toast('اختر فترة أولاً','danger'); return; }
    const idx  = parseInt(sel.value);
    const btn  = document.querySelector('#mStep1 .btn-primary');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    // نحتاج بيانات الفترة — نجلبها مجدداً من d.periods
    // لكنها محفوظة في الـ DOM — نقرأها من attr بديل
    const opts = document.querySelectorAll('.period-opt');
    // نُعيد جلب الفترة من AJAX المحفوظ
    post({_action:'get_emp_periods', employee_id:document.getElementById('mEmpId').value, month:'<?= $sel_month ?>'})
    .then(d => {
        const p = d.periods[idx];
        return post({
            _action:       'calculate',
            employee_id:   d.emp.id,
            period_from:   p.from,
            period_to:     p.to,
            payroll_month: p.month,
            week_number:   p.week_num,
        }).then(r => ({r, p, emp:d.emp}));
    })
    .then(({r, p, emp}) => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-calculator me-1"></i>احتساب';
        if (!r.ok) { toast(r.msg,'danger'); return; }
        mCalcData = {r, p, emp};

        // عرض الملخص
        const sym = r.currency;
        document.getElementById('mCalcResult').innerHTML = `
            <div class="ps-row"><span class="text-muted">الفترة</span><span class="fw-600">${p.label}</span></div>
            <div class="ps-row">
                <span class="text-muted">أجرة الساعة</span>
                <span class="n" style="font-size:.79rem">${r.hr_rate} ${sym}</span>
            </div>
            <div class="ps-row">
                <span class="text-muted">ساعات العمل الفعلية</span>
                <span class="n">${r.regular_hours} ساعة × ${r.hr_rate} = ${fmt(r.earned_salary - r.holiday_amount)} ${sym}</span>
            </div>
            ${r.holiday_days>0 ? `<div class="ps-row">
                <span class="text-muted" style="color:#16a34a">
                    <i class="bi bi-star-fill me-1" style="font-size:.75rem"></i>
                    عطل رسمية (${r.holiday_days} يوم)
                </span>
                <span class="n np">+${fmt(r.holiday_amount)} ${sym}</span>
            </div>` : ''}
            ${r.holiday_ot_hrs>0 ? `<div class="ps-row">
                <span class="text-muted" style="color:#7c3aed">
                    <i class="bi bi-lightning-charge me-1" style="font-size:.75rem"></i>
                    أوفرتايم في عطلة (${r.holiday_ot_hrs}س × ${r.ot_mult})
                </span>
                <span class="n" style="color:#7c3aed">+${fmt(r.holiday_ot_amt)} ${sym}</span>
            </div>` : ''}
            ${r.ot_hours>0 ? `<div class="ps-row"><span class="text-muted">أوفرتايم عادي (${r.ot_hours}س × ${r.ot_mult})</span><span class="n np">+${fmt(r.ot_amount)} ${sym}</span></div>` : ''}
            ${r.bonus>0   ? `<div class="ps-row"><span class="text-muted">مكافآت</span><span class="n np">+${fmt(r.bonus)} ${sym}</span></div>` : ''}
            ${r.loan_ded>0? `<div class="ps-row"><span class="text-muted">خصم السلفة</span><span class="n nn">−${fmt(r.loan_ded)} ${sym}</span></div>` : ''}
            <div class="ps-row tot"><span>الراتب الصافي</span><span style="color:#1e3a8a;font-size:1.05rem">${fmt(r.net)} ${sym}</span></div>`;

        document.getElementById('mStep1').style.display = 'none';
        document.getElementById('mStep2').style.display = '';
    });
}

function backToStep1() {
    document.getElementById('mStep1').style.display = '';
    document.getElementById('mStep2').style.display = 'none';
}

function confirmPay() {
    if (!mCalcData) return;
    const {r, p, emp} = mCalcData;
    document.getElementById('mPayTxt').style.opacity = '0';
    document.getElementById('mPaySpin').style.display = 'inline-block';

    post({
        _action:       'pay',
        employee_id:   emp.id,
        payroll_month: p.month,
        week_number:   p.week_num,
        period_from:   p.from,
        period_to:     p.to,
        method:        document.getElementById('mPayMethod').value,
        notes:         document.getElementById('mPayNotes').value,
        calc_data:     JSON.stringify(mCalcData.r || {}),
    }).then(d => {
        document.getElementById('mPayTxt').style.opacity = '1';
        document.getElementById('mPaySpin').style.display = 'none';
        if (d.ok) {
            payModal.hide();
            toast('تم صرف الراتب بنجاح');
            setTimeout(() => location.reload(), 1200);
        } else toast(d.msg, 'danger');
    });
}

// ══ السلف ══
function openLoanModal() {
    document.getElementById('lEmp').value   = '';
    document.getElementById('lAmt').value   = '';
    document.getElementById('lInst').value  = '1';
    document.getElementById('lReason').value= '';
    document.getElementById('lCalc').style.display = 'none';
    loanModal.show();
}

function calcDeduction() {
    const a = parseFloat(document.getElementById('lAmt').value)||0;
    const n = parseInt(document.getElementById('lInst').value)||1;
    if (a>0) {
        document.getElementById('lCalcVal').textContent = fmt(a/n) + ' / شهر';
        document.getElementById('lCalc').style.display = '';
    }
}

function saveLoan() {
    const emp = document.getElementById('lEmp').value;
    const amt = document.getElementById('lAmt').value;
    if (!emp || !amt) { toast('الموظف والمبلغ مطلوبان','danger'); return; }
    post({
        _action:'add_loan', employee_id:emp, amount:amt,
        installments:document.getElementById('lInst').value,
        loan_date:document.getElementById('lDate').value,
        currency_id:document.getElementById('lCur').value,
        reason:document.getElementById('lReason').value,
    }).then(d => {
        if (d.ok) { loanModal.hide(); toast('تمت إضافة السلفة'); setTimeout(()=>location.reload(),1200); }
        else toast(d.msg,'danger');
    });
}

function deleteLoan(id) {
    if (!confirm('إلغاء هذه السلفة؟')) return;
    post({_action:'delete_loan', id}).then(d => {
        if (d.ok) { toast('تم الإلغاء'); setTimeout(()=>location.reload(),1200); }
        else toast(d.msg,'danger');
    });
}

// ══ المكافآت ══
function openBonusModal() {
    document.getElementById('bEmp').value  = '';
    document.getElementById('bAmt').value  = '';
    document.getElementById('bDesc').value = '';
    bonusModal.show();
}

function saveBonus() {
    const emp = document.getElementById('bEmp').value;
    const amt = document.getElementById('bAmt').value;
    if (!emp || !amt) { toast('الموظف والمبلغ مطلوبان','danger'); return; }
    post({
        _action:'add_bonus', employee_id:emp, amount:amt,
        bonus_type:document.getElementById('bType').value,
        bonus_date:document.getElementById('bDate').value,
        currency_id:document.getElementById('bCur').value,
        description:document.getElementById('bDesc').value,
    }).then(d => {
        if (d.ok) { bonusModal.hide(); toast('تمت إضافة المكافأة'); setTimeout(()=>location.reload(),1200); }
        else toast(d.msg,'danger');
    });
}

function deleteBonus(id) {
    if (!confirm('حذف هذه المكافأة؟')) return;
    post({_action:'delete_bonus', id}).then(d => {
        if (d.ok) { toast('تم الحذف'); setTimeout(()=>location.reload(),1200); }
        else toast(d.msg,'danger');
    });
}
</script>
</body>
</html>