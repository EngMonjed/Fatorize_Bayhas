<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('hr.employees', 'view');

$branchName      = $_SESSION['branch_name'] ?? 'الفرع';
$currentModule   = 'hr.employees';
$T               = "hr_employees_{$_SESSION['table_suffix']}";

$currencies = $pdo->query("SELECT id,code,name,symbol FROM currencies WHERE status='active' ORDER BY is_base DESC,code")->fetchAll();

// أيام الأسبوع بالترتيب
const DAYS = ['friday', 'saturday', 'sunday','monday', 'tuesday', 'wednesday', 'thursday'];
const DAY_LABELS = [
    'friday' => 'الجمعة',
    'saturday' => 'السبت',
    'sunday' => 'الأحد',
    'monday' => 'الإثنين',
    'tuesday' => 'الثلاثاء',
    'wednesday' => 'الأربعاء',
    'thursday' => 'الخميس',
];
const DAY_SHORT  = [
    'friday' => 'ج',
    'saturday' => 'س',
    'sunday' => 'ح',
    'monday' => 'إ',
    'tuesday' => 'ث',
    'wednesday' => 'ر',
    'thursday' => 'خ',
];

// ── CRUD ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        if ($act === 'create' || $act === 'update') {
            $act === 'create'
                ? requirePermission('hr.employees', 'create')
                : requirePermission('hr.employees', 'edit');

            $full_name   = trim($_POST['full_name']   ?? '');
            $position    = trim($_POST['position']    ?? '');
            $department  = $_POST['department']       ?? 'sales';
            $phone       = trim($_POST['phone']       ?? '');
            $email       = trim($_POST['email']       ?? '');
            $hire_date   = $_POST['hire_date']        ?? date('Y-m-d');
            $salary_type = $_POST['salary_type']      ?? 'monthly';
            $basic_salary = floatval($_POST['basic_salary'] ?? 0);
            $currency_id = (int)($_POST['currency_id'] ?? 1);
            $bank_account = trim($_POST['bank_account'] ?? '');
            $notes       = trim($_POST['notes']       ?? '');
            $status      = $_POST['status']           ?? 'active';
            $ot_mult     = max(1.0, floatval($_POST['overtime_multiplier'] ?? 1.5));

            if (!$full_name || !$position)
                throw new Exception('الاسم الكامل والمسمى الوظيفي مطلوبان');

            // بناء حقول الأيام
            $dayCols = [];
            $dayVals = [];
            foreach (DAYS as $day) {
                $from = isset($_POST["{$day}_from"]) && $_POST["{$day}_from"] !== ''
                    ? (int)$_POST["{$day}_from"] : null;
                $to   = isset($_POST["{$day}_to"])   && $_POST["{$day}_to"]   !== ''
                    ? (int)$_POST["{$day}_to"]   : null;
                // إذا الاثنان null = عطلة، وإذا from موجود لكن to لا — عطلة
                if ($from === null || $to === null) {
                    $from = null;
                    $to = null;
                }
                $dayCols[] = "`{$day}_from`";
                $dayVals[] = $from;
                $dayCols[] = "`{$day}_to`";
                $dayVals[] = $to;
            }

            if ($act === 'create') {
                $cols   = "full_name,position,department,phone,email,hire_date,
                           salary_type,basic_salary,currency_id,bank_account,notes,
                           overtime_multiplier,status,created_by,"
                    . implode(',', $dayCols);
                $marks  = str_repeat('?,', 14 + count($dayCols));
                $marks  = rtrim($marks, ',');
                $params = [
                    $full_name,
                    $position,
                    $department,
                    $phone,
                    $email,
                    $hire_date,
                    $salary_type,
                    $basic_salary,
                    $currency_id,
                    $bank_account,
                    $notes,
                    $ot_mult,
                    $status,
                    $_SESSION['user_id'],
                    ...$dayVals
                ];
                $pdo->prepare("INSERT INTO `{$T}` ({$cols}) VALUES ({$marks})")
                    ->execute($params);
                echo json_encode(['ok' => true, 'msg' => 'تم إضافة الموظف', 'id' => $pdo->lastInsertId()]);
            } else {
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) throw new Exception('معرف غير صحيح');
                $sets   = "full_name=?,position=?,department=?,phone=?,email=?,hire_date=?,
                           salary_type=?,basic_salary=?,currency_id=?,bank_account=?,notes=?,
                           overtime_multiplier=?,status=?,updated_at=NOW(),"
                    . implode('=?,', $dayCols) . '=?';
                $params = [
                    $full_name,
                    $position,
                    $department,
                    $phone,
                    $email,
                    $hire_date,
                    $salary_type,
                    $basic_salary,
                    $currency_id,
                    $bank_account,
                    $notes,
                    $ot_mult,
                    $status,
                    ...$dayVals,
                    $id
                ];
                $pdo->prepare("UPDATE `{$T}` SET {$sets} WHERE id=?")->execute($params);
                echo json_encode(['ok' => true, 'msg' => 'تم تحديث بيانات الموظف']);
            }
        } elseif ($act === 'get') {
            $stmt = $pdo->prepare("SELECT * FROM `{$T}` WHERE id=?");
            $stmt->execute([(int)$_POST['id']]);
            $emp = $stmt->fetch();
            if (!$emp) throw new Exception('الموظف غير موجود');
            echo json_encode(['ok' => true, 'data' => $emp]);
        } elseif ($act === 'delete') {
            requirePermission('hr.employees', 'delete');
            $pdo->prepare("DELETE FROM `{$T}` WHERE id=?")->execute([(int)$_POST['id']]);
            echo json_encode(['ok' => true, 'msg' => 'تم حذف الموظف']);
        } elseif ($act === 'toggle') {
            requirePermission('hr.employees', 'edit');
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE `{$T}` SET status=IF(status='active','inactive','active'),updated_at=NOW() WHERE id=?")
                ->execute([$id]);
            $s = $pdo->prepare("SELECT status FROM `{$T}` WHERE id=?");
            $s->execute([$id]);
            echo json_encode(['ok' => true, 'status' => $s->fetchColumn()]);
        } else throw new Exception('إجراء غير معروف');
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── جلب البيانات ─────────────────────────────────────────────────
$employees = $pdo->query("
    SELECT e.*, c.code AS currency_code, c.symbol AS currency_symbol
    FROM `{$T}` e
    LEFT JOIN currencies c ON c.id = e.currency_id
    ORDER BY e.department, e.full_name
")->fetchAll();

$deptLabels   = [
    'sales' => ['مبيعات', 'info'],
    'production' => ['إنتاج', 'primary'],
    'admin' => ['إدارة', 'secondary'],
    'logistics' => ['لوجستيك', 'success'],
    'accounting' => ['محاسبة', 'danger']
];
$salaryLabels = ['monthly' => 'شهري', 'weekly' => 'أسبوعي', 'daily' => 'يومي', 'hourly' => 'ساعي'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>الموظفون — <?= htmlspecialchars($branchName) ?></title>
    <link rel="icon" href="/bayhas/assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="/bayhas/assets/css/layout.css" rel="stylesheet">
    <style>
        .emp-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .85rem;
            font-weight: 700;
            color: #fff;
            flex-shrink: 0
        }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: .75rem
        }

        .filter-bar {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            padding: .85rem 1.25rem;
            margin-bottom: 1.25rem;
            display: flex;
            gap: .75rem;
            flex-wrap: wrap;
            align-items: center
        }

        .filter-bar input,
        .filter-bar select {
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            padding: .4rem .85rem;
            font-size: .85rem;
            font-family: 'Cairo', sans-serif
        }

        .emp-table-wrap {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            overflow: hidden
        }

        .emp-table-wrap table {
            margin: 0;
            font-size: .84rem
        }

        .emp-table-wrap th {
            background: #f8fafc;
            color: #64748b;
            font-size: .77rem;
            font-weight: 600;
            border: none;
            padding: .7rem 1rem;
            white-space: nowrap
        }

        .emp-table-wrap td {
            padding: .65rem 1rem;
            vertical-align: middle;
            border-top: 1px solid #f1f5f9
        }

        .emp-table-wrap tbody tr:hover {
            background: #f8fafc
        }

        .btn-act {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e2e8f0;
            background: #fff;
            color: #64748b;
            cursor: pointer;
            transition: all .15s
        }

        .btn-act.edit:hover {
            background: #eff6ff;
            color: #3b82f6;
            border-color: #bfdbfe
        }

        .btn-act.del:hover {
            background: #fef2f2;
            color: #dc2626;
            border-color: #fecaca
        }

        .ts {
            position: relative;
            width: 38px;
            height: 20px;
            display: inline-block
        }

        .ts input {
            opacity: 0;
            width: 0;
            height: 0
        }

        .ts-sl {
            position: absolute;
            inset: 0;
            background: #e2e8f0;
            border-radius: 20px;
            cursor: pointer;
            transition: background .2s
        }

        .ts-sl::before {
            content: '';
            position: absolute;
            width: 14px;
            height: 14px;
            background: #fff;
            border-radius: 50%;
            top: 3px;
            right: 3px;
            transition: transform .2s;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .2)
        }

        input:checked+.ts-sl {
            background: #22c55e
        }

        input:checked+.ts-sl::before {
            transform: translateX(-18px)
        }

        /* جدول الدوام في القائمة */
        .sched-days {
            display: flex;
            gap: 2px
        }

        .sd {
            width: 22px;
            height: 22px;
            border-radius: 5px;
            font-size: .68rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: default
        }

        .sd.on {
            background: #dcfce7;
            color: #16a34a
        }

        .sd.off {
            background: #fee2e2;
            color: #dc2626
        }

        .ot-badge {
            font-size: .7rem;
            background: #fffbeb;
            color: #92400e;
            border: 1px solid #fde68a;
            border-radius: 6px;
            padding: 1px 6px;
            font-weight: 600
        }

        /* مودال */
        .modal-content {
            border-radius: 16px;
            border: none
        }

        .modal-header {
            background: linear-gradient(135deg, #1e3a8a, #2563eb);
            border-radius: 16px 16px 0 0;
            border: none;
            padding: 1rem 1.5rem
        }

        .sec-label {
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .05em;
            color: #94a3b8;
            margin: 1.25rem 0 .75rem;
            display: flex;
            align-items: center;
            gap: .5rem
        }

        .sec-label::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #f1f5f9
        }

        /* جدول الدوام في المودال */
        .ws-wrap {
            background: #f8fafc;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: .9rem 1rem
        }

        .ws-global {
            display: flex;
            align-items: center;
            gap: .6rem;
            flex-wrap: wrap;
            margin-bottom: .75rem;
            padding-bottom: .75rem;
            border-bottom: 1px dashed #e2e8f0
        }

        .ws-day-row {
            display: flex;
            align-items: center;
            gap: .6rem;
            background: #fff;
            border-radius: 9px;
            padding: .4rem .75rem;
            border: 1.5px solid #e2e8f0;
            margin-bottom: 4px;
            transition: all .15s
        }

        .ws-day-row.off {
            opacity: .55;
            border-color: #fecaca;
            background: #fff5f5
        }

        .ws-day-row.on {
            border-color: #bbf7d0
        }

        .ws-tog {
            position: relative;
            width: 34px;
            height: 18px;
            flex-shrink: 0
        }

        .ws-tog input {
            display: none
        }

        .ws-sl {
            position: absolute;
            inset: 0;
            background: #e2e8f0;
            border-radius: 9px;
            cursor: pointer;
            transition: background .2s
        }

        .ws-sl::before {
            content: '';
            position: absolute;
            width: 12px;
            height: 12px;
            background: #fff;
            border-radius: 50%;
            top: 3px;
            right: 3px;
            transition: transform .2s;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .2)
        }

        .ws-tog input:checked+.ws-sl {
            background: #22c55e
        }

        .ws-tog input:checked+.ws-sl::before {
            transform: translateX(-16px)
        }

        .ws-name {
            width: 72px;
            font-weight: 600;
            color: #374151;
            font-size: .83rem;
            flex-shrink: 0
        }

        .ws-hrs {
            display: flex;
            align-items: center;
            gap: .35rem;
            flex: 1
        }

        .ws-hrs select {
            font-family: 'Cairo', sans-serif
        }

        .ws-badge {
            font-size: .7rem;
            background: #eff6ff;
            color: #2563eb;
            border-radius: 6px;
            padding: 2px 6px;
            font-weight: 600;
            white-space: nowrap
        }

        .ws-off-lbl {
            font-size: .76rem;
            color: #ef4444;
            font-style: italic;
            font-weight: 600
        }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>

    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title">إدارة الموظفين</span>
        <span class="tb-branch"><i class="bi bi-people me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.8rem;color:#94a3b8">
            <span>الموارد البشرية</span>
            <i class="bi bi-chevron-left mx-1" style="font-size:.7rem"></i>
            <span class="text-primary">الموظفون</span>
        </nav>
    </header>

    <main class="main-content">
        <div class="content-body">

            <div class="page-header">
                <h5><i class="bi bi-people-fill me-2 text-primary"></i>الموظفون (<?= count($employees) ?>)</h5>
                <?php if (can('hr.employees', 'create')): ?>
                    <button class="btn btn-primary btn-sm" style="border-radius:10px" onclick="openCreate()">
                        <i class="bi bi-person-plus me-1"></i> موظف جديد
                    </button>
                <?php endif; ?>
            </div>

            <!-- فلاتر -->
            <div class="filter-bar">
                <input type="search" id="srch" placeholder="ابحث..." style="flex:1;min-width:160px">
                <select id="fDept" onchange="ft()">
                    <option value="">كل الأقسام</option>
                    <?php foreach ($deptLabels as $k => [$l, $c]): ?>
                        <option value="<?= $k ?>"><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="fStat" onchange="ft()">
                    <option value="">كل الحالات</option>
                    <option value="active">نشط</option>
                    <option value="inactive">غير نشط</option>
                    <option value="on_leave">في إجازة</option>
                </select>
                <span class="text-muted small ms-auto" id="cnt"><?= count($employees) ?> موظف</span>
            </div>

            <!-- الجدول -->
            <div class="emp-table-wrap">
                <?php if (empty($employees)): ?>
                    <div style="text-align:center;padding:3rem;color:#94a3b8">
                        <i class="bi bi-people d-block mb-2 fs-1" style="opacity:.2"></i>
                        لا يوجد موظفون بعد
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table id="empTbl">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>الموظف</th>
                                    <th>المسمى / القسم</th>
                                    <th>الراتب</th>
                                    <th>جدول الدوام</th>
                                    <th>أوفرتايم</th>
                                    <th>التعيين</th>
                                    <th>نشط</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $colors = ['#3b82f6', '#8b5cf6', '#10b981', '#f59e0b', '#ef4444', '#06b6d4'];
                                foreach ($employees as $i => $emp):
                                    $clr = $colors[$emp['id'] % count($colors)];
                                    $ini = mb_substr($emp['full_name'], 0, 1);
                                    $dept = $deptLabels[$emp['department']] ?? ['—', 'secondary'];
                                    $sym = $emp['currency_symbol'] ?? $emp['currency_code'] ?? '';
                                ?>
                                    <tr data-dept="<?= $emp['department'] ?>" data-stat="<?= $emp['status'] ?>">
                                        <td class="text-muted small"><?= $i + 1 ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="emp-avatar" style="background:<?= $clr ?>"><?= htmlspecialchars($ini) ?></div>
                                                <div>
                                                    <div class="fw-600" style="font-size:.88rem"><?= htmlspecialchars($emp['full_name']) ?></div>
                                                    <?php if ($emp['phone']): ?>
                                                        <div class="text-muted" style="font-size:.73rem"><i class="bi bi-phone me-1"></i><?= htmlspecialchars($emp['phone']) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-size:.84rem"><?= htmlspecialchars($emp['position']) ?></div>
                                            <span class="badge bg-<?= $dept[1] ?>-subtle text-<?= $dept[1] ?>" style="font-size:.7rem"><?= $dept[0] ?></span>
                                        </td>
                                        <td class="text-nowrap">
                                            <span class="fw-600"><?= number_format($emp['basic_salary'], 0) ?></span>
                                            <span class="text-muted" style="font-size:.74rem"> <?= htmlspecialchars($sym) ?></span>
                                            <div class="text-muted" style="font-size:.72rem"><?= $salaryLabels[$emp['salary_type']] ?? '' ?></div>
                                        </td>
                                        <td>
                                            <?php
                                            $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                                            $shorts = ['إ', 'ث', 'ر', 'خ', 'ج', 'س', 'ح'];
                                            echo '<div class="sched-days" title="">';
                                            foreach ($days as $di => $d):
                                                $from = $emp["{$d}_from"] ?? null;
                                                $to   = $emp["{$d}_to"]   ?? null;
                                                $on   = $from !== null && $to !== null;
                                                $tip  = $on ? "{$shorts[$di]}: {$from}:00→{$to}:00 (" . ($to - $from) . "س)" : "{$shorts[$di]}: عطلة";
                                                echo "<div class='sd " . ($on ? 'on' : 'off') . "' title='{$tip}'>{$shorts[$di]}</div>";
                                            endforeach;
                                            echo '</div>';
                                            ?>
                                        </td>
                                        <td><span class="ot-badge">×<?= number_format((float)($emp['overtime_multiplier'] ?? 1.5), 1) ?></span></td>
                                        <td class="text-muted small"><?= date('Y-m-d', strtotime($emp['hire_date'])) ?></td>
                                        <td>
                                            <?php if (can('hr.employees', 'edit')): ?>
                                                <label class="ts">
                                                    <input type="checkbox" <?= $emp['status'] === 'active' ? 'checked' : '' ?> onchange="toggleSt(<?= $emp['id'] ?>,this)">
                                                    <span class="ts-sl"></span>
                                                </label>
                                            <?php else: ?>
                                                <span class="badge bg-<?= $emp['status'] === 'active' ? 'success' : 'secondary' ?>-subtle text-<?= $emp['status'] === 'active' ? 'success' : 'secondary' ?>">
                                                    <?= $emp['status'] === 'active' ? 'نشط' : 'معطل' ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <?php if (can('hr.employees', 'edit')): ?>
                                                    <button class="btn-act edit" onclick="openEdit(<?= $emp['id'] ?>)" title="تعديل"><i class="bi bi-pencil"></i></button>
                                                <?php endif; ?>
                                                <?php if (can('hr.employees', 'delete')): ?>
                                                    <button class="btn-act del" onclick="delEmp(<?= $emp['id'] ?>,'<?= htmlspecialchars($emp['full_name'], ENT_QUOTES) ?>')" title="حذف"><i class="bi bi-trash"></i></button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- ══ مودال الموظف ══ -->
    <div class="modal fade" id="empModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title text-white fw-700 mb-0" id="mTitle">موظف جديد</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" id="eId">
                    <div class="row g-3">

                        <!-- §1 بيانات أساسية -->
                        <div class="col-12">
                            <div class="sec-label"><i class="bi bi-person me-1"></i>البيانات الأساسية</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-600 text-secondary mb-1">الاسم الكامل <span class="text-danger">*</span></label>
                            <input type="text" id="eName" class="form-control" placeholder="محمد أحمد">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-600 text-secondary mb-1">المسمى الوظيفي <span class="text-danger">*</span></label>
                            <input type="text" id="ePos" class="form-control" placeholder="بائع، خياط، محاسب...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-600 text-secondary mb-1">القسم</label>
                            <select id="eDept" class="form-select">
                                <?php foreach ($deptLabels as $k => [$l, $c]): ?>
                                    <option value="<?= $k ?>"><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-600 text-secondary mb-1">الهاتف</label>
                            <input type="text" id="ePhone" class="form-control" dir="ltr">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-600 text-secondary mb-1">البريد</label>
                            <input type="email" id="eEmail" class="form-control" dir="ltr">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-600 text-secondary mb-1">تاريخ التعيين</label>
                            <input type="date" id="eHire" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-600 text-secondary mb-1">الحالة</label>
                            <select id="eStat" class="form-select">
                                <option value="active">نشط</option>
                                <option value="inactive">غير نشط</option>
                                <option value="on_leave">في إجازة</option>
                            </select>
                        </div>

                        <!-- §2 الراتب -->
                        <div class="col-12">
                            <div class="sec-label"><i class="bi bi-cash-coin me-1"></i>الراتب</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-600 text-secondary mb-1">نظام الراتب</label>
                            <select id="eSalType" class="form-select">
                                <option value="monthly">شهري</option>
                                <option value="weekly">أسبوعي</option>
                                <option value="daily">يومي</option>
                                <option value="hourly">ساعي</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-600 text-secondary mb-1">الراتب</label>
                            <input type="number" id="eSalAmt" class="form-control" value="0" step="0.01" dir="ltr">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-600 text-secondary mb-1">العملة</label>
                            <select id="eCurrency" class="form-select">
                                <?php foreach ($currencies as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['code']) ?> - <?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-600 text-secondary mb-1">الحساب البنكي</label>
                            <input type="text" id="eBank" class="form-control" dir="ltr">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-600 text-secondary mb-1">ملاحظات</label>
                            <input type="text" id="eNotes" class="form-control">
                        </div>

                        <!-- §3 جدول الدوام -->
                        <div class="col-12">
                            <div class="sec-label"><i class="bi bi-calendar-week me-1"></i>جدول الدوام الأسبوعي</div>
                        </div>
                        <div class="col-12">
                            <div class="ws-wrap">

                                <!-- ساعات عامة للتطبيق الجماعي -->
                                <div class="ws-global">
                                    <span style="font-size:.82rem;color:#64748b;min-width:95px">تطبيق على الكل:</span>
                                    <select id="gFrom" class="form-select form-select-sm" style="width:88px" onchange="applyAll()">
                                        <?php for ($h = 0; $h < 24; $h++): ?>
                                            <option value="<?= $h ?>" <?= $h === 8 ? 'selected' : '' ?>><?= str_pad($h, 2, '0', STR_PAD_LEFT) ?>:00</option>
                                        <?php endfor; ?>
                                    </select>
                                    <span class="text-muted">→</span>
                                    <select id="gTo" class="form-select form-select-sm" style="width:88px" onchange="applyAll()">
                                        <?php for ($h = 0; $h < 24; $h++): ?>
                                            <option value="<?= $h ?>" <?= $h === 18 ? 'selected' : '' ?>><?= str_pad($h, 2, '0', STR_PAD_LEFT) ?>:00</option>
                                        <?php endfor; ?>
                                    </select>
                                    <span id="gBadge" style="font-size:.75rem;color:#64748b">= 10 ساعات</span>
                                </div>

                                <!-- صفوف الأيام -->
                                <?php
                                $dayDefs = [
                                    'friday'    => ['الجمعة',   false], // عطلة افتراضية
                                    'saturday'  => ['السبت',    true],
                                    'sunday'    => ['الأحد',    false], // عطلة افتراضية
                                    'monday'    => ['الإثنين',  true],
                                    'tuesday'   => ['الثلاثاء', true],
                                    'wednesday' => ['الأربعاء', true],
                                    'thursday'  => ['الخميس',   true],
                                ];
                                foreach ($dayDefs as $dk => [$dl, $defOn]):
                                ?>
                                    <div class="ws-day-row <?= $defOn ? 'on' : 'off' ?>" id="dRow_<?= $dk ?>">
                                        <label class="ws-tog">
                                            <input type="checkbox" id="dChk_<?= $dk ?>" <?= $defOn ? 'checked' : '' ?>
                                                onchange="toggleDay('<?= $dk ?>')">
                                            <span class="ws-sl"></span>
                                        </label>
                                        <span class="ws-name"><?= $dl ?></span>
                                        <div class="ws-hrs" id="dHrs_<?= $dk ?>" style="display:<?= $defOn ? 'flex' : 'none' ?>">
                                            <select id="dFrom_<?= $dk ?>" class="form-select form-select-sm" style="width:86px"
                                                onchange="updBadge('<?= $dk ?>')">
                                                <?php for ($h = 0; $h < 24; $h++): ?>
                                                    <option value="<?= $h ?>" <?= $h === 8 ? 'selected' : '' ?>><?= str_pad($h, 2, '0', STR_PAD_LEFT) ?>:00</option>
                                                <?php endfor; ?>
                                            </select>
                                            <span class="text-muted" style="font-size:.75rem">→</span>
                                            <select id="dTo_<?= $dk ?>" class="form-select form-select-sm" style="width:86px"
                                                onchange="updBadge('<?= $dk ?>')">
                                                <?php for ($h = 0; $h < 24; $h++): ?>
                                                    <option value="<?= $h ?>" <?= $h === 18 ? 'selected' : '' ?>><?= str_pad($h, 2, '0', STR_PAD_LEFT) ?>:00</option>
                                                <?php endfor; ?>
                                            </select>
                                            <span class="ws-badge" id="dBadge_<?= $dk ?>">10 ساعات</span>
                                        </div>
                                        <span class="ws-off-lbl" id="dOff_<?= $dk ?>" style="display:<?= $defOn ? 'none' : 'inline' ?>">
                                            <i class="bi bi-x-circle me-1"></i>عطلة
                                        </span>
                                    </div>
                                <?php endforeach; ?>

                                <!-- معامل الأوفرتايم -->
                                <div class="d-flex align-items-center gap-2 mt-3 pt-3"
                                    style="border-top:1px dashed #e2e8f0;flex-wrap:wrap">
                                    <i class="bi bi-lightning-charge text-warning"></i>
                                    <span style="font-size:.83rem;font-weight:600;color:#374151">معامل الأوفرتايم:</span>
                                    <select id="eOT" class="form-select form-select-sm" style="width:175px">
                                        <option value="1.0">× 1.0 — نفس أجرة الساعة</option>
                                        <option value="1.5" selected>× 1.5 — ساعة ونص</option>
                                        <option value="2.0">× 2.0 — ضعف الأجرة</option>
                                        <option value="2.5">× 2.5 — ضعف ونص</option>
                                    </select>
                                    <span class="text-muted" style="font-size:.74rem">لكل ساعة إضافية في يوم العطلة</span>
                                </div>

                            </div>
                        </div>

                    </div><!-- /row -->
                </div><!-- /modal-body -->

                <div class="modal-footer border-0 pb-4">
                    <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal" style="border-radius:8px">إلغاء</button>
                    <button type="button" class="btn btn-sm btn-primary fw-600" style="border-radius:8px;min-width:100px" onclick="saveEmp()">
                        <span id="sBtnTxt">حفظ</span>
                        <span id="sSpin" class="spinner-border spinner-border-sm ms-1" style="display:none"></span>
                    </button>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ── Sidebar ─────────────────────────────────────────────────────
        const sb = document.getElementById('sidebar'),
            ov = document.getElementById('sbOverlay');

        function sbOpen() {
            sb.classList.add('open');
            ov.classList.add('show');
        }

        function sbClose() {
            sb.classList.remove('open');
            ov.classList.remove('show');
        }
        window.addEventListener('resize', () => {
            if (window.innerWidth > 991) sbClose();
        });
        document.querySelectorAll('.sb-group').forEach(g => {
            if (localStorage.getItem('sb_open_' + g.dataset.key) === 'true') g.classList.add('open');
        });

        function toggleGroup(g) {
            const o = g.classList.contains('open');
            document.querySelectorAll('.sb-group.open').forEach(x => x.classList.remove('open'));
            g.classList.toggle('open', !o);
            localStorage.setItem('sb_open_' + g.dataset.key, (!o).toString());
        }

        // ── Modal ────────────────────────────────────────────────────────
        const modal = new bootstrap.Modal(document.getElementById('empModal'));
        let editMode = false;
        const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        const OFF_DEF = ['friday', 'sunday']; // عطلة افتراضية

        function openCreate() {
            editMode = false;
            document.getElementById('mTitle').textContent = 'موظف جديد';
            document.getElementById('eId').value = '';
            document.getElementById('eName').value = '';
            document.getElementById('ePos').value = '';
            document.getElementById('eDept').value = 'sales';
            document.getElementById('ePhone').value = '';
            document.getElementById('eEmail').value = '';
            document.getElementById('eHire').value = '<?= date('Y-m-d') ?>';
            document.getElementById('eStat').value = 'active';
            document.getElementById('eSalType').value = 'monthly';
            document.getElementById('eSalAmt').value = '0';
            document.getElementById('eCurrency').value = '<?= $currencies[0]['id'] ?? 1 ?>';
            document.getElementById('eBank').value = '';
            document.getElementById('eNotes').value = '';
            document.getElementById('eOT').value = '1.5';
            document.getElementById('gFrom').value = '8';
            document.getElementById('gTo').value = '18';

            // إعادة ضبط الجدول — افتراضي
            DAYS.forEach(d => {
                const isOff = OFF_DEF.includes(d);
                setDay(d, !isOff, 8, 18);
            });
            updGBadge();
            modal.show();
        }

        function openEdit(id) {
            editMode = true;
            document.getElementById('mTitle').textContent = 'تعديل بيانات الموظف';
            const fd = new FormData();
            fd.append('_action', 'get');
            fd.append('id', id);
            fetch(location.href, {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json()).then(d => {
                    if (!d.ok) {
                        alert(d.msg);
                        return;
                    }
                    const e = d.data;
                    document.getElementById('eId').value = e.id;
                    document.getElementById('eName').value = e.full_name;
                    document.getElementById('ePos').value = e.position;
                    document.getElementById('eDept').value = e.department;
                    document.getElementById('ePhone').value = e.phone || '';
                    document.getElementById('eEmail').value = e.email || '';
                    document.getElementById('eHire').value = e.hire_date;
                    document.getElementById('eStat').value = e.status;
                    document.getElementById('eSalType').value = e.salary_type;
                    document.getElementById('eSalAmt').value = e.basic_salary;
                    document.getElementById('eCurrency').value = e.currency_id || 1;
                    document.getElementById('eBank').value = e.bank_account || '';
                    document.getElementById('eNotes').value = e.notes || '';
                    document.getElementById('eOT').value = e.overtime_multiplier || '1.5';

                    // تعبئة جدول الدوام من الأعمدة الجديدة
                    DAYS.forEach(d => {
                        const fromVal = e[d + '_from'];
                        const toVal = e[d + '_to'];
                        const on = fromVal !== null && fromVal !== undefined && fromVal !== '';
                        setDay(d, on, on ? parseInt(fromVal) : 8, on ? parseInt(toVal) : 18);
                    });

                    // تحديث الساعات العامة من الإثنين
                    if (e.monday_from !== null) {
                        document.getElementById('gFrom').value = e.monday_from;
                        document.getElementById('gTo').value = e.monday_to;
                    }
                    updGBadge();
                    modal.show();
                }).catch(() => alert('خطأ في الاتصال'));
        }

        // ── جدول الدوام ──────────────────────────────────────────────────
        function setDay(day, on, from, to) {
            const chk = document.getElementById('dChk_' + day);
            const row = document.getElementById('dRow_' + day);
            const hrs = document.getElementById('dHrs_' + day);
            const off = document.getElementById('dOff_' + day);
            const fSel = document.getElementById('dFrom_' + day);
            const tSel = document.getElementById('dTo_' + day);
            if (chk) chk.checked = on;
            if (fSel) fSel.value = from;
            if (tSel) tSel.value = to;
            if (row) {
                row.classList.toggle('on', on);
                row.classList.toggle('off', !on);
            }
            if (hrs) hrs.style.display = on ? 'flex' : 'none';
            if (off) off.style.display = on ? 'none' : 'inline';
            updBadge(day);
        }

        function toggleDay(day) {
            const on = document.getElementById('dChk_' + day)?.checked;
            const fSel = document.getElementById('dFrom_' + day);
            const tSel = document.getElementById('dTo_' + day);
            setDay(day, on,
                on ? parseInt(fSel?.value ?? 8) : 8,
                on ? parseInt(tSel?.value ?? 18) : 18
            );
        }

        function updBadge(day) {
            const f = parseInt(document.getElementById('dFrom_' + day)?.value ?? 8);
            const t = parseInt(document.getElementById('dTo_' + day)?.value ?? 18);
            const b = document.getElementById('dBadge_' + day);
            if (b) b.textContent = Math.max(0, t - f) + ' ساعات';
        }

        function applyAll() {
            const f = parseInt(document.getElementById('gFrom').value);
            const t = parseInt(document.getElementById('gTo').value);
            DAYS.forEach(d => {
                if (!document.getElementById('dChk_' + d)?.checked) return;
                document.getElementById('dFrom_' + d).value = f;
                document.getElementById('dTo_' + d).value = t;
                updBadge(d);
            });
            updGBadge();
        }

        function updGBadge() {
            const f = parseInt(document.getElementById('gFrom').value);
            const t = parseInt(document.getElementById('gTo').value);
            document.getElementById('gBadge').textContent = '= ' + Math.max(0, t - f) + ' ساعات';
        }

        // ── حفظ ─────────────────────────────────────────────────────────
        function saveEmp() {
            const name = document.getElementById('eName').value.trim();
            const pos = document.getElementById('ePos').value.trim();
            if (!name || !pos) {
                alert('الاسم الكامل والمسمى مطلوبان');
                return;
            }

            document.getElementById('sBtnTxt').style.opacity = '0';
            document.getElementById('sSpin').style.display = 'inline-block';

            const fd = new FormData();
            fd.append('_action', editMode ? 'update' : 'create');
            if (editMode) fd.append('id', document.getElementById('eId').value);
            fd.append('full_name', name);
            fd.append('position', pos);
            fd.append('department', document.getElementById('eDept').value);
            fd.append('phone', document.getElementById('ePhone').value);
            fd.append('email', document.getElementById('eEmail').value);
            fd.append('hire_date', document.getElementById('eHire').value);
            fd.append('status', document.getElementById('eStat').value);
            fd.append('salary_type', document.getElementById('eSalType').value);
            fd.append('basic_salary', document.getElementById('eSalAmt').value);
            fd.append('currency_id', document.getElementById('eCurrency').value);
            fd.append('bank_account', document.getElementById('eBank').value);
            fd.append('notes', document.getElementById('eNotes').value);
            fd.append('overtime_multiplier', document.getElementById('eOT').value);

            // أعمدة الأيام — NULL إذا عطلة
            DAYS.forEach(d => {
                const on = document.getElementById('dChk_' + d)?.checked;
                const from = document.getElementById('dFrom_' + d)?.value;
                const to = document.getElementById('dTo_' + d)?.value;
                fd.append(d + '_from', on ? from : '');
                fd.append(d + '_to', on ? to : '');
            });

            fetch(location.href, {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(d => {
                    document.getElementById('sBtnTxt').style.opacity = '1';
                    document.getElementById('sSpin').style.display = 'none';
                    if (d.ok) {
                        modal.hide();
                        location.reload();
                    } else alert(d.msg);
                })
                .catch(() => {
                    document.getElementById('sBtnTxt').style.opacity = '1';
                    document.getElementById('sSpin').style.display = 'none';
                    alert('خطأ في الاتصال');
                });
        }

        // ── حذف / تفعيل ──────────────────────────────────────────────────
        function delEmp(id, name) {
            if (!confirm(`حذف "${name}"؟`)) return;
            const fd = new FormData();
            fd.append('_action', 'delete');
            fd.append('id', id);
            fetch(location.href, {
                    method: 'POST',
                    body: fd
                }).then(r => r.json())
                .then(d => {
                    if (d.ok) location.reload();
                    else alert(d.msg);
                });
        }

        function toggleSt(id, el) {
            const fd = new FormData();
            fd.append('_action', 'toggle');
            fd.append('id', id);
            fetch(location.href, {
                    method: 'POST',
                    body: fd
                }).then(r => r.json())
                .then(d => {
                    if (!d.ok) {
                        alert(d.msg);
                        el.checked = !el.checked;
                    }
                });
        }

        // ── فلترة ────────────────────────────────────────────────────────
        document.getElementById('srch')?.addEventListener('input', ft);

        function ft() {
            const s = document.getElementById('srch').value.toLowerCase();
            const dp = document.getElementById('fDept').value;
            const st = document.getElementById('fStat').value;
            let v = 0;
            document.querySelectorAll('#empTbl tbody tr').forEach(tr => {
                const show = (!s || tr.textContent.toLowerCase().includes(s)) &&
                    (!dp || tr.dataset.dept === dp) &&
                    (!st || tr.dataset.stat === st);
                tr.style.display = show ? '' : 'none';
                if (show) v++;
            });
            document.getElementById('cnt').textContent = v + ' موظف';
        }
    </script>
</body>

</html>