<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('hr.attendance', 'view');

$branchName      = $_SESSION['branch_name'] ?? 'الفرع';
$currentModule   = 'hr.attendance';
$T  = "hr_employees_{$_SESSION['table_suffix']}";
$TA = "hr_attendance_{$_SESSION['table_suffix']}";

// إنشاء الجداول إذا لم تكن موجودة
$TH = "public_holidays_{$_SESSION['table_suffix']}";
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$TH}` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `holiday_date` date NOT NULL,
        `name` varchar(150) NOT NULL,
        `description` text DEFAULT NULL,
        `is_recurring` tinyint(1) NOT NULL DEFAULT 0,
        `created_by` int(11) DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
        `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_date` (`holiday_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

// إنشاء جدول الحضور إذا لم يكن موجوداً
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$TA}` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `employee_id` int(11) NOT NULL,
        `attendance_date` date NOT NULL,
        `check_in` time DEFAULT NULL,
        `check_out` time DEFAULT NULL,
        `hours_worked` decimal(5,2) DEFAULT 0.00,
        `overtime_hours` decimal(5,2) DEFAULT 0.00,
        `attendance_status` enum('present','absent','late','half_day','holiday') NOT NULL DEFAULT 'present',
        `notes` text DEFAULT NULL,
        `created_by` int(11) DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
        `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_attendance` (`employee_id`,`attendance_date`),
        KEY `idx_employee` (`employee_id`),
        KEY `idx_date` (`attendance_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

// ── AJAX ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        // ── جلب بيانات الموظف ──
        // ── إدارة العطل الرسمية ──
        if ($act === 'get_holidays') {
            $year = (int)($_POST['year'] ?? date('Y'));
            $stmt = $pdo->prepare("SELECT * FROM `{$TH}` ORDER BY holiday_date");
            $stmt->execute();
            $holidays = $stmt->fetchAll();
            echo json_encode(['ok'=>true,'data'=>$holidays]);
        }
        elseif ($act === 'save_holiday') {
            requirePermission('hr.holidays', 'create');
            $hdate = $_POST['holiday_date'] ?? '';
            $hname = trim($_POST['name'] ?? '');
            $hdesc = trim($_POST['description'] ?? '');
            $hrec  = (int)($_POST['is_recurring'] ?? 0);
            if (!$hdate || !$hname) throw new Exception('التاريخ والاسم مطلوبان');
            $ex = $pdo->prepare("SELECT id FROM `{$TH}` WHERE holiday_date=?");
            $ex->execute([$hdate]);
            if ($ex->fetch()) throw new Exception('هذا التاريخ مسجّل مسبقاً');
            $pdo->prepare("INSERT INTO `{$TH}` (holiday_date,name,description,is_recurring,created_by) VALUES (?,?,?,?,?)")
                ->execute([$hdate,$hname,$hdesc,$hrec,$_SESSION['user_id']]);
            echo json_encode(['ok'=>true,'msg'=>'تمت إضافة العطلة','id'=>$pdo->lastInsertId()]);
        }
        elseif ($act === 'delete_holiday') {
            requirePermission('hr.holidays', 'delete');
            $pdo->prepare("DELETE FROM `{$TH}` WHERE id=?")->execute([(int)$_POST['id']]);
            echo json_encode(['ok'=>true]);
        }
        elseif ($act === 'get_employee') {
            $id   = (int)($_POST['employee_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM `{$T}` WHERE id=?");
            $stmt->execute([$id]);
            $emp  = $stmt->fetch();
            if (!$emp) throw new Exception('الموظف غير موجود id='.$id);
            $emp['overtime_multiplier'] = (float)($emp['overtime_multiplier'] ?? 1.5);
            try {
                $cs = $pdo->prepare("SELECT symbol FROM currencies WHERE id=?");
                $cs->execute([$emp['currency_id'] ?? 1]);
                $emp['currency_symbol'] = $cs->fetch()['symbol'] ?? '';
            } catch (Throwable $e) { $emp['currency_symbol'] = ''; }
            // إضافة عطل الفرع للـ response
            try {
                $hStmt = $pdo->prepare("SELECT holiday_date, name, is_recurring FROM `{$TH}`");
                $hStmt->execute();
                $emp['public_holidays'] = $hStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { $emp['public_holidays'] = []; }
            echo json_encode(['ok' => true, 'data' => $emp]);
        }

        // ── حفظ سريع: دوام كامل بضغطة واحدة ──
        elseif ($act === 'quick_save') {
            requirePermission('hr.attendance', 'create');
            $emp_id = (int)$_POST['employee_id'];
            $date   = $_POST['date'];
            $stmt   = $pdo->prepare("SELECT * FROM `{$T}` WHERE id=?");
            $stmt->execute([$emp_id]);
            $emp = $stmt->fetch();
            if (!$emp) throw new Exception('الموظف غير موجود');
            $day      = strtolower(date('l', strtotime($date)));
            $from_col = $emp["{$day}_from"] ?? null;
            $to_col   = $emp["{$day}_to"]   ?? null;
            if ($from_col === null || $to_col === null) throw new Exception('هذا اليوم عطلة لهذا الموظف');
            $ci    = sprintf('%02d:00:00', (int)$from_col);
            $co    = sprintf('%02d:00:00', (int)$to_col);
            $hours = max(0, (int)$to_col - (int)$from_col);
            $ex = $pdo->prepare("SELECT id FROM `{$TA}` WHERE employee_id=? AND attendance_date=?");
            $ex->execute([$emp_id, $date]);
            if ($ex->fetch()) {
                $pdo->prepare("UPDATE `{$TA}` SET check_in=?,check_out=?,hours_worked=?,
                    overtime_hours=0,attendance_status='present',notes=NULL,
                    created_by=?,updated_at=NOW() WHERE employee_id=? AND attendance_date=?")
                    ->execute([$ci,$co,$hours,$_SESSION['user_id'],$emp_id,$date]);
            } else {
                $pdo->prepare("INSERT INTO `{$TA}`
                    (employee_id,attendance_date,check_in,check_out,hours_worked,
                     overtime_hours,attendance_status,created_by)
                    VALUES (?,?,?,?,?,0,'present',?)")
                    ->execute([$emp_id,$date,$ci,$co,$hours,$_SESSION['user_id']]);
            }
            echo json_encode(['ok'=>true,'msg'=>'تم تسجيل الدوام',
                'check_in'=>$ci,'check_out'=>$co,'hours'=>$hours]);
        }

        // ── حفظ تفصيلي (من المودال) ──
        elseif ($act === 'save_attendance') {
            requirePermission('hr.attendance', 'create');
            $emp_id    = (int)$_POST['employee_id'];
            $date      = $_POST['date'];
            $leave     = $_POST['leave_type'] ?? '';
            $check_in  = $_POST['check_in']   ?? '';
            $check_out = $_POST['check_out']   ?? '';
            $notes     = trim($_POST['notes']  ?? '');
            if (!$emp_id || !$date) throw new Exception('بيانات ناقصة');

            $stmt = $pdo->prepare("SELECT * FROM `{$T}` WHERE id=?");
            $stmt->execute([$emp_id]);
            $employee = $stmt->fetch();
            if (!$employee) throw new Exception('الموظف غير موجود');

            $day      = strtolower(date('l', strtotime($date)));
            $f_col    = $employee["{$day}_from"] ?? null;
            $t_col    = $employee["{$day}_to"]   ?? null;
            $day_on   = ($f_col !== null && $t_col !== null);
            $day_hrs  = $day_on ? max(1,(int)$t_col-(int)$f_col) : 8;
            $day_cfg  = ['on'=>$day_on,'from'=>(int)($f_col??8),'to'=>(int)($t_col??18)];
            $dl       = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
            $wdays    = max(1, count(array_filter($dl, fn($d) => $employee["{$d}_from"] !== null)));
            $periods  = $employee['salary_type'] === 'weekly' ? 1 : 4;
            $hr_rate  = $employee['basic_salary'] / ($wdays * $periods * $day_hrs);
            $ot_mult  = (float)($employee['overtime_multiplier'] ?? 1.5);

            if ($leave === 'unpaid') {
                $hw = 0; $ot = 0; $st = 'absent'; $check_in = null; $check_out = null;
            } elseif ($leave === 'paid') {
                $hw = $day_hrs; $ot = 0; $st = 'present';
                $check_in  = sprintf('%02d:00:00', $day_cfg['from']);
                $check_out = sprintf('%02d:00:00', $day_cfg['to']);
            } elseif ($leave === 'overtime') {
                if (!$check_in || !$check_out) throw new Exception('أدخل وقت الدخول والخروج');
                $hw = max(0, round((strtotime($check_out)-strtotime($check_in))/3600, 2));
                $ot = $hw; $st = 'present';
            } else {
                if (!$check_in || !$check_out) throw new Exception('أدخل وقت الدخول والخروج');
                $hw = max(0, round((strtotime($check_out)-strtotime($check_in))/3600, 2));

                // ── منطق التحقق من الدوام النظامي ──
                $sched_from = $day_cfg['from']; // ساعة البداية النظامية
                $sched_to   = $day_cfg['to'];   // ساعة النهاية النظامية
                $actual_in  = (int)date('H', strtotime($check_in));  // ساعة الدخول الفعلي
                $actual_out = (int)date('H', strtotime($check_out)); // ساعة الخروج الفعلي

                $late_hours = max(0, $actual_in - $sched_from);      // ساعات التأخير
                $early_leave= max(0, $sched_to  - $actual_out);      // ساعات الخروج المبكر
                $ot         = max(0, round($hw - $day_hrs, 2));       // ساعات إضافية

                // تحديد الحالة
                if ($hw <= 0) {
                    $st = 'absent';
                } elseif ($hw < ($day_hrs * 0.5)) {
                    // أقل من نصف الدوام النظامي
                    $st = 'absent';
                } elseif ($hw < $day_hrs) {
                    // دوام ناقص
                    $st = ($late_hours > 0 || $early_leave > 0) ? 'late' : 'half_day';
                } else {
                    $st = 'present';
                }

                // إضافة ملاحظة تلقائية إذا في تأخير أو خروج مبكر
                if (empty($notes)) {
                    $auto_notes = [];
                    if ($late_hours > 0)   $auto_notes[] = "تأخير {$late_hours}س";
                    if ($early_leave > 0)  $auto_notes[] = "خروج مبكر {$early_leave}س";
                    if ($ot > 0)           $auto_notes[] = "أوفرتايم {$ot}س";
                    if ($auto_notes)       $notes = implode(' · ', $auto_notes);
                }
            }

            $ex = $pdo->prepare("SELECT id FROM `{$TA}` WHERE employee_id=? AND attendance_date=?");
            $ex->execute([$emp_id,$date]);
            if ($ex->fetch()) {
                $pdo->prepare("UPDATE `{$TA}` SET check_in=?,check_out=?,hours_worked=?,
                    overtime_hours=?,attendance_status=?,notes=?,created_by=?,updated_at=NOW()
                    WHERE employee_id=? AND attendance_date=?")
                    ->execute([$check_in,$check_out,$hw,$ot,$st,$notes,$_SESSION['user_id'],$emp_id,$date]);
            } else {
                $pdo->prepare("INSERT INTO `{$TA}`
                    (employee_id,attendance_date,check_in,check_out,hours_worked,
                     overtime_hours,attendance_status,notes,created_by)
                    VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$emp_id,$date,$check_in,$check_out,$hw,$ot,$st,$notes,$_SESSION['user_id']]);
            }
            $earn = (min($hw,$day_hrs)*$hr_rate) + ($ot*$hr_rate*$ot_mult);
            echo json_encode([
                'ok'            => true,
                'msg'           => 'تم حفظ الدوام',
                'hours'         => $hw,
                'overtime'      => $ot,
                'late_hours'    => $late_hours ?? 0,
                'early_leave'   => $early_leave ?? 0,
                'status'        => $st,
                'hourly_rate'   => round($hr_rate, 4),
                'overtime_mult' => $ot_mult,
                'day_earnings'  => round($earn, 2),
                'sched_hours'   => $day_hrs,
                'notes_auto'    => $notes,
            ]);
        }

        else throw new Exception('إجراء غير معروف');

    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

// ── جلب البيانات ─────────────────────────────────────────────────
$today         = date('Y-m-d');
$selected_date = $_GET['date'] ?? $today;
$day_of_week   = date('l', strtotime($selected_date));

$emp_stmt = $pdo->prepare("
    SELECT e.*, c.symbol AS currency_symbol, c.code AS currency_code
    FROM `{$T}` e
    LEFT JOIN currencies c ON c.id = e.currency_id
    WHERE e.status='active'
      AND e.hire_date <= ?
    ORDER BY e.full_name
");
$emp_stmt->execute([$selected_date]);
$employees = $emp_stmt->fetchAll();

// جلب العطل الرسمية — يدعم المتكررة والمحددة
$holidays_stmt = $pdo->prepare("SELECT * FROM `{$TH}` WHERE
    holiday_date = ? OR
    (is_recurring=1 AND DATE_FORMAT(holiday_date,'%m-%d') = DATE_FORMAT(?,'%m-%d'))");
$holidays_stmt->execute([$selected_date, $selected_date]);
$public_holidays = $holidays_stmt->fetchAll();
$is_public_holiday = count($public_holidays) > 0;
$holiday_name = $is_public_holiday ? $public_holidays[0]['name'] : '';

$att_stmt = $pdo->prepare("SELECT * FROM `{$TA}` WHERE attendance_date=?");
$att_stmt->execute([$selected_date]);
$attendance = [];
while ($row = $att_stmt->fetch()) $attendance[$row['employee_id']] = $row;

// إحصائيات
$stats = ['present'=>0,'absent'=>0,'holiday'=>0,'pending'=>0];
foreach ($employees as $emp) {
    $att      = $attendance[$emp['id']] ?? null;
    $day_name = strtolower(date('l', strtotime($selected_date)));
    $is_off   = ($emp["{$day_name}_from"] === null);
    if ($is_off) { $stats['holiday']++; continue; }
    if (!$att)   { $stats['pending']++; continue; }
    $st = $att['attendance_status'] ?? 'absent';
    if (isset($stats[$st])) $stats[$st]++;
    else $stats['present']++;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>الحضور والانصراف — <?= htmlspecialchars($branchName) ?></title>
<link rel="icon" href="/bayhas/assets/images/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="/bayhas/assets/css/layout.css" rel="stylesheet">
<style>
/* ── stats ── */
.att-stat{background:#fff;border-radius:14px;border:1px solid #e2e8f0;padding:.9rem 1.25rem;display:flex;align-items:center;gap:.9rem;transition:all .2s}
.att-stat:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.06)}
.att-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0}
.att-val{font-size:1.5rem;font-weight:700;color:#1e293b;line-height:1}
.att-lbl{font-size:.74rem;color:#64748b;margin-top:.2rem}

/* ── top bar ── */
.att-topbar{background:#fff;border-radius:14px;border:1px solid #e2e8f0;padding:.85rem 1.25rem;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:1.25rem}
.att-day-lbl{font-size:.95rem;font-weight:700;color:#1e293b}
.att-day-sub{font-size:.78rem;color:#94a3b8}
.btn-save-all{background:#1e3a8a;color:#fff;border:none;border-radius:10px;padding:.45rem 1.1rem;font-size:.84rem;font-weight:600;cursor:pointer;font-family:'Cairo',sans-serif;display:flex;align-items:center;gap:.4rem;transition:all .15s}
.btn-save-all:hover{background:#1d4ed8;transform:translateY(-1px)}

/* ── table ── */
.att-table-wrap{background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden}
.att-table-wrap table{margin:0;font-size:.84rem}
.att-table-wrap th{background:#f8fafc;color:#64748b;font-size:.77rem;font-weight:600;border:none;padding:.7rem 1rem;white-space:nowrap}
.att-table-wrap td{padding:.65rem 1rem;vertical-align:middle;border-top:1px solid #f1f5f9}
.att-table-wrap tbody tr:hover{background:#f8fafc}
.emp-avatar{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.82rem;font-weight:700;color:#fff;flex-shrink:0}

/* ── schedule badges ── */
.sch-badge{display:inline-flex;align-items:center;gap:4px;font-size:.74rem;padding:3px 8px;border-radius:7px;font-weight:600;white-space:nowrap}
.sch-on {background:#f0fdf4;color:#16a34a;border:1px solid #86efac}
.sch-off{background:#fef2f2;color:#dc2626;border:1px solid #fca5a5}

/* ── hours badges ── */
.h-badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:6px;font-size:.74rem;font-weight:600}
.h-full{background:#f0fdf4;color:#16a34a}
.h-part{background:#fffbeb;color:#92400e}
.h-off {background:#fef2f2;color:#dc2626}
.h-none{background:#f8fafc;color:#94a3b8}
.h-ot  {background:#fdf4ff;color:#7c3aed}

/* ── action buttons ── */
.btn-quick-save{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:8px;font-size:.77rem;font-weight:600;border:none;cursor:pointer;font-family:'Cairo',sans-serif;background:#16a34a;color:#fff;transition:all .15s}
.btn-quick-save:hover{background:#15803d;transform:translateY(-1px)}
.btn-quick-save:disabled{opacity:.55;cursor:not-allowed;transform:none}
.btn-edit-att{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer;transition:all .15s;font-size:.9rem}
.btn-edit-att:hover{background:#eff6ff;color:#3b82f6;border-color:#bfdbfe}
.btn-ot{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:8px;font-size:.77rem;font-weight:600;border:1px solid #ddd6fe;background:#f5f3ff;color:#7c3aed;cursor:pointer;font-family:'Cairo',sans-serif;transition:all .15s}
.btn-ot:hover{background:#ede9fe}
.btn-done{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:8px;font-size:.77rem;font-weight:600;border:1px solid #bbf7d0;background:#f0fdf4;color:#16a34a;cursor:default}

/* ── modal ── */
.modal-content{border-radius:16px;border:none}
.leave-btn{flex:1;text-align:center;padding:.5rem;border:1.5px solid #e2e8f0;border-radius:10px;cursor:pointer;font-size:.8rem;font-weight:600;color:#64748b;transition:all .15s;user-select:none}
.leave-btn input{display:none}
.leave-btn:hover{border-color:#3b82f6;color:#3b82f6;background:#eff6ff}
.leave-btn.active-unpaid{border-color:#ef4444;color:#ef4444;background:#fef2f2}
.leave-btn.active-paid{border-color:#22c55e;color:#16a34a;background:#f0fdf4}
.leave-btn.active-none{border-color:#3b82f6;color:#1d4ed8;background:#eff6ff}
.leave-btn.active-ot{border-color:#8b5cf6;color:#7c3aed;background:#f5f3ff}
.att-summary{background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;padding:.75rem 1rem}

/* ── time cell ── */
.time-ok{color:#16a34a;font-weight:600;font-size:.83rem}
.time-na{color:#94a3b8;font-size:.83rem}
</style>
</head>
<body>
<div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
<?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>

<header class="topbar">
    <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
    <span class="tb-title">الحضور والانصراف</span>
    <span class="tb-branch"><i class="bi bi-calendar-check me-1"></i><?= htmlspecialchars($branchName) ?></span>
    <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.8rem;color:#94a3b8">
        <span>الموارد البشرية</span>
        <i class="bi bi-chevron-left mx-1" style="font-size:.7rem"></i>
        <span class="text-primary">الحضور والانصراف</span>
    </nav>
</header>

<main class="main-content">
<div class="content-body">

    <!-- إحصائيات -->
    <div class="row g-3 mb-3">
        <?php
        $statItems = [
            [$stats['present'], 'حاضر',    'bi-person-check',  '#16a34a','#f0fdf4'],
            [$stats['absent'],  'غائب',     'bi-person-x',      '#dc2626','#fef2f2'],
            [$stats['pending'], 'لم يُسجَّل','bi-hourglass-split','#d97706','#fffbeb'],
            [$stats['holiday'], 'عطلة',     'bi-calendar-x',    '#7c3aed','#f5f3ff'],
        ];
        foreach ($statItems as [$v,$l,$ic,$clr,$bg]):
        ?>
        <div class="col-6 col-md-3">
            <div class="att-stat">
                <div class="att-icon" style="background:<?=$bg?>;color:<?=$clr?>">
                    <i class="bi <?=$ic?>"></i>
                </div>
                <div>
                    <div class="att-val" style="color:<?=$clr?>"><?=$v?></div>
                    <div class="att-lbl"><?=$l?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- شريط التحكم -->
    <div class="att-topbar">
        <!-- التنقل بالأيام -->
        <button class="btn btn-sm btn-outline-secondary" style="border-radius:8px"
                onclick="gotoDate('<?= date('Y-m-d', strtotime($selected_date.' -1 day')) ?>')">
            <i class="bi bi-chevron-right"></i>
        </button>
        <div>
            <div class="att-day-lbl">
                <?php
                $days_ar = ['Monday'=>'الإثنين','Tuesday'=>'الثلاثاء','Wednesday'=>'الأربعاء',
                            'Thursday'=>'الخميس','Friday'=>'الجمعة','Saturday'=>'السبت','Sunday'=>'الأحد'];
                echo ($days_ar[$day_of_week] ?? $day_of_week) . '، ' . date('d M Y', strtotime($selected_date));
                ?>
            </div>
            <div class="att-day-sub" id="attDaySub">
                <?= $stats['present'] ?> حاضر من <?= count($employees) ?> موظف
                <?php if ($is_public_holiday): ?>
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle ms-2"
                      style="font-size:.72rem">
                    <i class="bi bi-star-fill me-1"></i><?= htmlspecialchars($holiday_name) ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
        <button class="btn btn-sm btn-outline-secondary" style="border-radius:8px"
                onclick="gotoDate('<?= date('Y-m-d', strtotime($selected_date.' +1 day')) ?>')">
            <i class="bi bi-chevron-left"></i>
        </button>

        <!-- اختيار تاريخ -->
        <input type="date" id="datePicker" class="form-control form-control-sm"
               style="width:155px;border-radius:8px" value="<?= $selected_date ?>"
               onchange="gotoDate(this.value)">

        <button class="btn btn-sm btn-outline-primary" style="border-radius:8px"
                onclick="gotoDate('<?= $today ?>')">
            <i class="bi bi-calendar-day me-1"></i>اليوم
        </button>

        <?php if (can('hr.holidays','view')): ?>
        <button class="btn btn-sm btn-outline-danger" style="border-radius:8px"
                onclick="openHolidaysModal()">
            <i class="bi bi-calendar-x me-1"></i>العطل الرسمية
        </button>
        <?php endif; ?>
        <button class="btn-save-all ms-auto" onclick="saveAllPending()">
            <i class="bi bi-check2-all"></i>
            حفظ دوام الكل
            <span id="saveAllSpin" class="spinner-border spinner-border-sm ms-1" style="display:none"></span>
        </button>
    </div>

    <!-- جدول الحضور -->
    <div class="att-table-wrap">
        <table id="attTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>الموظف</th>
                    <th>الدوام المحدد</th>
                    <th>الدخول</th>
                    <th>الخروج</th>
                    <th>الساعات</th>
                    <th>الحالة</th>
                    <th style="text-align:center">الإجراء</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $colors = ['#3b82f6','#8b5cf6','#10b981','#f59e0b','#ef4444','#06b6d4'];
            $days_ar_short = ['Monday'=>'إثنين','Tuesday'=>'ثلاثاء','Wednesday'=>'أربعاء',
                              'Thursday'=>'خميس','Friday'=>'جمعة','Saturday'=>'سبت','Sunday'=>'أحد'];
            foreach ($employees as $i => $emp):
                $clr      = $colors[$emp['id'] % count($colors)];
                $ini      = mb_substr($emp['full_name'], 0, 1);
                $att      = $attendance[$emp['id']] ?? null;
                $day_name   = strtolower(date('l', strtotime($selected_date)));
                $f_col      = $emp["{$day_name}_from"] ?? null;
                $t_col      = $emp["{$day_name}_to"]   ?? null;
                $is_off     = ($f_col === null || $t_col === null) || $is_public_holiday;
                $sch_hrs    = ($f_col !== null && $t_col !== null) ? ((int)$t_col - (int)$f_col) : 0;
                $ci       = $att['check_in']  ?? null;
                $co       = $att['check_out'] ?? null;
                $hw       = $att ? (float)$att['hours_worked'] : 0;
                $ot       = $att ? (float)$att['overtime_hours'] : 0;
                $st       = $att['attendance_status'] ?? null;
                $registered = ($ci !== null);

                // بادج الساعات
                $off_label = $is_public_holiday ? ('🌟 '.$holiday_name) : 'عطلة';
                if ($is_off)       [$hClass, $hTxt] = ['h-off',  $off_label];
                elseif (!$att)     [$hClass, $hTxt] = ['h-none', '—'];
                elseif ($ot > 0)   [$hClass, $hTxt] = ['h-ot',   $hw.'س ('.$ot.' أوفرتايم)'];
                elseif ($hw >= $sch_hrs) [$hClass, $hTxt] = ['h-full', $hw.' ساعة'];
                else               [$hClass, $hTxt] = ['h-part', $hw.' ساعة'];

                // بادج الحالة
                $stCfg = [
                    'present'  => ['حاضر',     'success'],
                    'absent'   => ['غائب',     'danger'],
                    'late'     => ['متأخر',    'warning'],
                    'half_day' => ['نصف يوم',  'info'],
                    'holiday'  => ['إجازة',    'secondary'],
                ];
            ?>
            <tr id="attRow_<?= $emp['id'] ?>">
                <td class="text-muted small"><?= $i+1 ?></td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="emp-avatar" style="background:<?= $clr ?>"><?= htmlspecialchars($ini) ?></div>
                        <div>
                            <div class="fw-600" style="font-size:.86rem"><?= htmlspecialchars($emp['full_name']) ?></div>
                            <div class="text-muted" style="font-size:.73rem">
                                <?= $emp['salary_type'] === 'weekly' ? 'أسبوعي' : ($emp['salary_type'] === 'monthly' ? 'شهري' : $emp['salary_type']) ?>
                                · <?= htmlspecialchars($emp['position']) ?>
                            </div>
                        </div>
                    </div>
                </td>
                <td>
                    <?php if ($is_off): ?>
                    <span class="sch-badge sch-off">
                        <i class="bi bi-x-circle"></i>
                        عطلة (<?= $days_ar_short[$day_of_week] ?? '' ?>)
                    </span>
                    <?php else: ?>
                    <?php if ($is_public_holiday): ?>
                    <span class="sch-badge sch-off" title="<?= htmlspecialchars($holiday_name) ?>">
                        <i class="bi bi-star-fill"></i>
                        <?= htmlspecialchars($holiday_name) ?> — أوفرتايم
                    </span>
                    <?php else: ?>
                    <span class="sch-badge sch-on">
                        <i class="bi bi-clock"></i>
                        <?= str_pad($f_col,2,'0',STR_PAD_LEFT) ?>:00 → <?= str_pad($t_col,2,'0',STR_PAD_LEFT) ?>:00
                        · <?= $sch_hrs ?>س
                    </span>
                    <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td id="ci_<?= $emp['id'] ?>" class="<?= $ci ? 'time-ok' : 'time-na' ?>">
                    <?= $ci ? substr($ci,0,5) : '—' ?>
                </td>
                <td id="co_<?= $emp['id'] ?>" class="<?= $co ? 'time-ok' : 'time-na' ?>">
                    <?= $co ? substr($co,0,5) : '—' ?>
                </td>
                <td id="hw_<?= $emp['id'] ?>">
                    <span class="h-badge <?= $hClass ?>"><?= $hTxt ?></span>
                </td>
                <td id="st_<?= $emp['id'] ?>">
                    <?php if ($st && isset($stCfg[$st])): [$sl,$sc] = $stCfg[$st]; ?>
                    <span class="badge bg-<?= $sc ?>-subtle text-<?= $sc ?>"
                          style="font-size:.72rem"><?= $sl ?></span>
                    <?php else: ?>
                    <span class="text-muted small">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="d-flex gap-1 justify-content-center">
                        <?php if ($is_off): ?>
                        <!-- يوم عطلة: زر أوفرتايم فقط -->
                        <button class="btn-ot att-btn" data-id="<?= $emp['id'] ?>"
                                title="تسجيل دوام إضافي في يوم عطلة">
                            <i class="bi bi-lightning-charge"></i> أوفرتايم
                        </button>
                        <?php elseif (!$registered): ?>
                        <!-- لم يُسجَّل: زر حفظ سريع + زر تفصيل -->
                        <button class="btn-quick-save quick-save-btn"
                                data-id="<?= $emp['id'] ?>"
                                data-date="<?= $selected_date ?>"
                                title="حفظ دوام <?= $f_col ?>:00 → <?= $t_col ?>:00"
                                <?= $is_public_holiday ? 'disabled title="عطلة رسمية — استخدم زر تفصيلي"' : '' ?>>
                            <i class="bi bi-check-circle-fill"></i> حفظ الدوام
                        </button>
                        <button class="btn-edit-att att-btn" data-id="<?= $emp['id'] ?>"
                                title="تسجيل تفصيلي">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <?php else: ?>
                        <!-- مسجّل: بادج + زر تعديل -->
                        <span class="btn-done">
                            <i class="bi bi-check2-circle"></i> مسجّل
                        </span>
                        <button class="btn-edit-att att-btn" data-id="<?= $emp['id'] ?>"
                                title="تعديل">
                            <i class="bi bi-pencil"></i>
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
</main>

<!-- ══ مودال تسجيل الدوام التفصيلي ══ -->
<div class="modal fade" id="attModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-3 px-4"
           style="background:linear-gradient(135deg,#1e3a8a,#2563eb);color:#fff;border:none;border-radius:16px 16px 0 0">
        <div>
            <h6 class="modal-title mb-0 fw-700">
                <i class="bi bi-clock-history me-2"></i>تسجيل الدوام
            </h6>
            <div id="mSubtitle" style="font-size:.78rem;opacity:.75;margin-top:2px">—</div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 pt-3 pb-2">
        <input type="hidden" id="mEmpId">

        <!-- التاريخ -->
        <div class="mb-3">
            <label class="form-label small fw-600 text-secondary mb-1">
                <i class="bi bi-calendar3 me-1"></i>تاريخ الدوام
            </label>
            <input type="date" id="mDate" class="form-control"
                   value="<?= $selected_date ?>" onchange="onMDateChange()">
        </div>

        <!-- نوع الدوام -->
        <div class="mb-3">
            <label class="form-label small fw-600 text-secondary mb-1">نوع الدوام</label>
            <div class="d-flex gap-2">
                <label class="leave-btn" id="mBtnNone">
                    <input type="radio" name="mLeave" value="" checked onchange="onMLeaveChange()">
                    <i class="bi bi-clock"></i><br>دوام عادي
                </label>
                <label class="leave-btn" id="mBtnPaid">
                    <input type="radio" name="mLeave" value="paid" onchange="onMLeaveChange()">
                    <i class="bi bi-check-circle"></i><br>إجازة براتب
                </label>
                <label class="leave-btn" id="mBtnUnpaid">
                    <input type="radio" name="mLeave" value="unpaid" onchange="onMLeaveChange()">
                    <i class="bi bi-x-circle"></i><br>بدون راتب
                </label>
                <label class="leave-btn" id="mBtnOT" style="display:none">
                    <input type="radio" name="mLeave" value="overtime" onchange="onMLeaveChange()">
                    <i class="bi bi-lightning-charge"></i><br>أوفرتايم
                </label>
            </div>
        </div>

        <!-- الأوقات -->
        <div id="mTimesRow" class="row g-2 mb-3">
            <div class="col-6">
                <label class="form-label small fw-600 text-secondary mb-1">
                    <i class="bi bi-box-arrow-in-right text-success me-1"></i>الدخول
                </label>
                <input type="time" id="mCheckIn" class="form-control" oninput="calcMHours()">
            </div>
            <div class="col-6">
                <label class="form-label small fw-600 text-secondary mb-1">
                    <i class="bi bi-box-arrow-left text-danger me-1"></i>الخروج
                </label>
                <input type="time" id="mCheckOut" class="form-control" oninput="calcMHours()">
            </div>
        </div>

        <!-- ملخص -->
        <div id="mSummary" class="att-summary mb-3" style="display:none">
            <div class="d-flex justify-content-between">
                <span class="text-muted small">ساعات الدوام</span>
                <span id="mHours" class="fw-700 text-primary">—</span>
            </div>
            <div class="d-flex justify-content-between mt-1">
                <span class="text-muted small">أجرة الساعة</span>
                <span id="mRate" class="fw-600 text-secondary">—</span>
            </div>
            <div class="d-flex justify-content-between mt-1 pt-1" style="border-top:1px solid #e2e8f0">
                <span class="text-muted small">استحقاق اليوم</span>
                <span id="mEarnings" class="fw-700 text-success">—</span>
            </div>
        </div>

        <!-- ملاحظات -->
        <div class="mb-1">
            <label class="form-label small fw-600 text-secondary mb-1">ملاحظات</label>
            <textarea id="mNotes" class="form-control form-control-sm" rows="2" placeholder="اختياري..."></textarea>
        </div>
      </div>
      <div class="modal-footer border-0 px-4 pb-3 pt-1">
        <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal"
                style="border-radius:8px">إلغاء</button>
        <button type="button" class="btn btn-sm btn-primary fw-600"
                style="border-radius:8px;min-width:110px" onclick="saveMModal()" id="mSaveBtn">
            <span id="mSaveTxt"><i class="bi bi-floppy me-1"></i>حفظ</span>
            <span id="mSpin" class="spinner-border spinner-border-sm ms-1" style="display:none"></span>
        </button>
      </div>
    </div>
  </div>
</div>


<!-- ══ مودال العطل الرسمية ══ -->
<div class="modal fade" id="holidaysModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4"
           style="background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff;border:none;border-radius:16px 16px 0 0">
        <div>
          <h6 class="modal-title mb-0 fw-700">
            <i class="bi bi-calendar-x me-2"></i>العطل الرسمية
          </h6>
          <div style="font-size:.78rem;opacity:.75;margin-top:2px">إدارة أيام العطل الرسمية للفرع</div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">

        <!-- إضافة عطلة -->
        <?php if (can('hr.holidays','create')): ?>
        <div class="p-3 mb-3" style="background:#fff5f5;border-radius:12px;border:1px solid #fecaca">
          <div class="fw-600 mb-2" style="font-size:.86rem;color:#dc2626">
            <i class="bi bi-plus-circle me-1"></i>إضافة عطلة جديدة
          </div>
          <div class="row g-2">
            <div class="col-md-3">
              <label class="form-label small fw-600 text-secondary mb-1">التاريخ</label>
              <input type="date" id="hDate" class="form-control form-control-sm">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-600 text-secondary mb-1">اسم العطلة</label>
              <input type="text" id="hName" class="form-control form-control-sm" placeholder="عيد الفطر...">
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-600 text-secondary mb-1">وصف</label>
              <input type="text" id="hDesc" class="form-control form-control-sm" placeholder="اختياري">
            </div>
            <div class="col-md-2 d-flex align-items-end">
              <button class="btn btn-danger btn-sm w-100" style="border-radius:8px" onclick="addHoliday()">
                <i class="bi bi-plus"></i> إضافة
              </button>
            </div>
          </div>
          <div class="mt-2">
            <label class="d-flex align-items-center gap-2" style="cursor:pointer;font-size:.82rem">
              <input type="checkbox" id="hRecurring" style="accent-color:#dc2626">
              <span>تتكرر كل سنة (عيد ثابت)</span>
            </label>
          </div>
        </div>
        <?php endif; ?>

        <!-- قائمة العطل -->
        <div id="holidaysList">
          <div class="text-muted text-center py-3">
            <i class="bi bi-hourglass-split d-block mb-2 fs-4"></i>
            جاري التحميل...
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Sidebar ──────────────────────────────────────────────────────
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

// ── تنقل بين الأيام ──────────────────────────────────────────────
function gotoDate(d) { window.location.href = '?date=' + d; }

// ── حالة المودال ─────────────────────────────────────────────────
const modal = new bootstrap.Modal(document.getElementById('attModal'));
let mEmployee = null;
const SEL_DATE = '<?= $selected_date ?>';

function getDayName(dateStr) {
    if (!dateStr) return '';
    const [y,m,d] = dateStr.split('-').map(Number);
    return new Date(y,m-1,d).toLocaleDateString('en-US',{weekday:'long'}).toLowerCase();
}

// ── Event delegation ─────────────────────────────────────────────
document.addEventListener('click', e => {
    // زر الحفظ السريع
    const qBtn = e.target.closest('.quick-save-btn');
    if (qBtn && !qBtn.disabled) { quickSave(qBtn); return; }

    // زر المودال التفصيلي / الأوفرتايم
    const aBtn = e.target.closest('.att-btn');
    if (aBtn) { openModal(aBtn.dataset.id); return; }
});

// ── حفظ سريع ─────────────────────────────────────────────────────
function quickSave(btn) {
    const id   = btn.dataset.id;
    const date = btn.dataset.date;
    const origHTML = btn.innerHTML;
    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    post({_action:'quick_save', employee_id:id, date})
    .then(d => {
        if (d.ok) {
            updateRow(id, d.check_in, d.check_out, d.hours, 'full', 'present');
            btn.outerHTML = '<span class="btn-done"><i class="bi bi-check2-circle"></i> مسجّل</span>';
            toast('تم تسجيل دوام '+d.hours+' ساعة', 'success');
            updateCounters();
        } else {
            btn.disabled = false; btn.innerHTML = origHTML;
            toast(d.msg, 'danger');
        }
    });
}

// ── حفظ دوام الكل ────────────────────────────────────────────────
function saveAllPending() {
    const btns = [...document.querySelectorAll('.quick-save-btn:not([disabled])')];
    if (!btns.length) { toast('لا يوجد دوام معلق للحفظ', 'info'); return; }
    const spin = document.getElementById('saveAllSpin');
    spin.style.display = 'inline-block';
    let done = 0;
    btns.forEach(btn => {
        post({_action:'quick_save', employee_id:btn.dataset.id, date:btn.dataset.date})
        .then(d => {
            if (d.ok) {
                updateRow(btn.dataset.id, d.check_in, d.check_out, d.hours, 'full', 'present');
                btn.outerHTML = '<span class="btn-done"><i class="bi bi-check2-circle"></i> مسجّل</span>';
            }
            done++;
            if (done === btns.length) {
                spin.style.display = 'none';
                toast('تم حفظ دوام '+btns.length+' موظف', 'success');
                updateCounters();
            }
        });
    });
}

// ── المودال التفصيلي ──────────────────────────────────────────────
function openModal(empId) {
    mEmployee = null;
    document.getElementById('mEmpId').value   = empId;
    document.getElementById('mSubtitle').textContent = '...';
    document.getElementById('mDate').value    = SEL_DATE;
    document.getElementById('mNotes').value   = '';
    document.getElementById('mSummary').style.display = 'none';
    document.querySelectorAll('input[name="mLeave"]').forEach(r => r.checked = r.value === '');
    onMLeaveChange();
    modal.show();

    post({_action:'get_employee', employee_id:empId})
    .then(d => {
        if (!d.ok) { toast(d.msg, 'danger'); modal.hide(); return; }
        const emp = d.data;
        // بناء schedule من الأعمدة
        const DAYS = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
        const sch  = {};
        DAYS.forEach(day => {
            const f = emp[day+'_from'], t = emp[day+'_to'];
            sch[day] = {on:(f!=null&&f!==''), from:parseInt(f??8), to:parseInt(t??18)};
        });
        mEmployee = {
            id: emp.id, name: emp.full_name,
            salaryType: emp.salary_type, basicSalary: parseFloat(emp.basic_salary)||0,
            currencySymbol: emp.currency_symbol||'',
            otMultiplier: parseFloat(emp.overtime_multiplier)||1.5,
            schedule: sch,
            publicHolidays: emp.public_holidays || [],
        };
        document.getElementById('mSubtitle').textContent =
            emp.full_name + ' · ' + emp.salary_type;
        setDefaultTimes(SEL_DATE);
        applyDateType(SEL_DATE);
        calcMHours();
    });
}

function setDefaultTimes(dateStr) {
    if (!mEmployee?.schedule) return;
    const day = getDayName(dateStr);
    const cfg = mEmployee.schedule[day];
    if (!cfg) return;
    const pad = n => String(Math.floor(n)).padStart(2,'0');
    document.getElementById('mCheckIn').value  = pad(cfg.from)+':00';
    document.getElementById('mCheckOut').value = pad(cfg.to)+':00';
}

function applyDateType(dateStr) {
    if (!mEmployee?.schedule) return;
    const day   = getDayName(dateStr);
    const cfg   = mEmployee.schedule[day];
    const isOff = cfg ? !cfg.on : false;

    // فحص العطل الرسمية
    const ph = mEmployee.publicHolidays || [];
    const [y,m,d] = dateStr.split('-').map(n => n.padStart(2,'0'));
    const mmdd = m+'-'+d;
    const phMatch = ph.find(h => {
        const hd = (h.holiday_date || '').slice(0,10); // YYYY-MM-DD فقط
        if (hd === dateStr) return true;
        if (parseInt(h.is_recurring) === 1) {
            return hd.slice(5) === mmdd; // MM-DD
        }
        return false;
    });
    const isPublicHoliday = !!phMatch;
    const effectiveOff = isOff || isPublicHoliday;
    const dateEl = document.getElementById('mDate');
    const btnOT  = document.getElementById('mBtnOT');
    const btnPaid= document.getElementById('mBtnPaid');
    const btnUnp = document.getElementById('mBtnUnpaid');
    const btnNon = document.getElementById('mBtnNone');

    if (effectiveOff) {
        if (phMatch) { dateEl.title = '🌟 عطلة رسمية: ' + phMatch.name; }
        dateEl.style.borderColor     = '#ef4444';
        dateEl.style.backgroundColor = '#fff5f5';
        if (btnOT)   btnOT.style.display   = '';
        if (btnPaid) btnPaid.style.display  = 'none';
        if (btnUnp)  btnUnp.style.display   = 'none';
        if (btnNon)  btnNon.style.display   = '';
        const otR = document.querySelector('input[name="mLeave"][value="overtime"]');
        if (otR) { otR.checked = true; onMLeaveChange(); }
    } else {
        dateEl.style.borderColor     = '#22c55e';
        dateEl.style.backgroundColor = '#f0fdf4';
        dateEl.title = '';
        if (btnOT)   btnOT.style.display   = 'none';
        if (btnPaid) btnPaid.style.display  = '';
        if (btnUnp)  btnUnp.style.display   = '';
        if (btnNon)  btnNon.style.display   = '';
        const checked = document.querySelector('input[name="mLeave"]:checked')?.value;
        if (checked === 'overtime') {
            document.querySelector('input[name="mLeave"][value=""]').checked = true;
            onMLeaveChange();
        }
    }
}

function onMDateChange() {
    const ds = document.getElementById('mDate').value;
    setDefaultTimes(ds);
    applyDateType(ds);
    calcMHours();
}

function onMLeaveChange() {
    const val = document.querySelector('input[name="mLeave"]:checked')?.value ?? '';
    const sc = (id, on, cls) => {
        const el = document.getElementById(id);
        if (el) el.className = 'leave-btn' + (on ? ' '+cls : '');
    };
    sc('mBtnNone',   val==='',         'active-none');
    sc('mBtnPaid',   val==='paid',     'active-paid');
    sc('mBtnUnpaid', val==='unpaid',   'active-unpaid');
    sc('mBtnOT',     val==='overtime', 'active-ot');

    const dis = val==='paid' || val==='unpaid';
    ['mCheckIn','mCheckOut'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.disabled = dis;
    });
    const tr = document.getElementById('mTimesRow');
    if (tr) tr.style.opacity = dis ? '.4' : '1';
    calcMHours();
}

function calcMHours() {
    if (!mEmployee) return;
    const leave  = document.querySelector('input[name="mLeave"]:checked')?.value ?? '';
    const date   = document.getElementById('mDate').value || SEL_DATE;
    const day    = getDayName(date);
    const cfg    = mEmployee.schedule[day] ?? {from:8, to:18};
    const dayHrs = Math.max(1, (cfg.to||18) - (cfg.from||8));

    const DAYS   = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
    const wdays  = DAYS.filter(d => mEmployee.schedule[d]?.on !== false).length || 6;
    const periods= mEmployee.salaryType === 'weekly' ? 1 : 4;
    const hr     = mEmployee.basicSalary / Math.max(1, wdays * periods * dayHrs);
    const otM    = mEmployee.otMultiplier || 1.5;
    const sym    = mEmployee.currencySymbol;

    let hw = 0, lateHrs = 0, earlyLeave = 0;

    if (leave === 'paid')        hw = dayHrs;
    else if (leave === 'unpaid') hw = 0;
    else {
        const inV  = document.getElementById('mCheckIn').value;
        const outV = document.getElementById('mCheckOut').value;
        if (inV && outV) {
            const toM = t => { const p=t.trim().split(':'); return parseInt(p[0])*60+parseInt(p[1]); };
            const inMin  = toM(inV);
            const outMin = toM(outV);
            hw = Math.max(0, (outMin - inMin) / 60);
            // تأخير الدخول
            const actualInH  = Math.floor(inMin / 60);
            const actualOutH = Math.floor(outMin / 60);
            lateHrs    = Math.max(0, actualInH  - (cfg.from||8));
            earlyLeave = Math.max(0, (cfg.to||18) - actualOutH);
        }
    }

    const isOt   = leave === 'overtime';
    const ot     = isOt ? hw : Math.max(0, hw - dayHrs);
    const regular= isOt ? 0 : Math.min(hw, dayHrs);
    const earn   = regular*hr + ot*hr*otM;

    // حالة الدوام
    let statusTxt, statusClr;
    if (hw <= 0)             { statusTxt='غائب';             statusClr='#dc2626'; }
    else if (hw < dayHrs*.5) { statusTxt='غائب (أقل من نصف)'; statusClr='#dc2626'; }
    else if (hw < dayHrs)    { statusTxt= lateHrs>0?'متأخر':'نصف يوم'; statusClr='#d97706'; }
    else if (ot > 0)         { statusTxt='حاضر + أوفرتايم';  statusClr='#7c3aed'; }
    else                     { statusTxt='حاضر';             statusClr='#16a34a'; }

    // تفاصيل
    let details = '';
    if (lateHrs > 0)    details += `<div style="color:#d97706;font-size:.78rem"><i class="bi bi-clock-history me-1"></i>تأخير دخول: ${lateHrs.toFixed(1)} ساعة</div>`;
    if (earlyLeave > 0) details += `<div style="color:#d97706;font-size:.78rem"><i class="bi bi-box-arrow-left me-1"></i>خروج مبكر: ${earlyLeave.toFixed(1)} ساعة</div>`;
    if (ot > 0)         details += `<div style="color:#7c3aed;font-size:.78rem"><i class="bi bi-lightning-charge me-1"></i>أوفرتايم: ${ot.toFixed(1)}س × ${otM} = ${(ot*hr*otM).toFixed(2)} ${sym}</div>`;

    document.getElementById('mHours').innerHTML =
        `<span style="color:${statusClr};font-weight:700">${hw.toFixed(2)} ساعة</span>
         <span style="background:${statusClr};color:#fff;font-size:.7rem;border-radius:6px;padding:1px 7px;margin-right:4px">${statusTxt}</span>
         <span class="text-muted" style="font-size:.75rem">/ ${dayHrs} نظامية</span>`;
    document.getElementById('mRate').textContent     = hr.toFixed(4)+' '+sym;
    document.getElementById('mEarnings').textContent = earn.toFixed(2)+' '+sym;

    let dEl = document.getElementById('mDetails');
    if (!dEl && details) {
        dEl = document.createElement('div');
        dEl.id = 'mDetails';
        dEl.style.marginTop = '6px';
        document.getElementById('mSummary').appendChild(dEl);
    }
    if (dEl) dEl.innerHTML = details;
    document.getElementById('mSummary').style.display = '';
}

function saveMModal() {
    const empId = document.getElementById('mEmpId').value;
    const date  = document.getElementById('mDate').value;
    const leave = document.querySelector('input[name="mLeave"]:checked')?.value ?? '';
    const ci    = document.getElementById('mCheckIn').value;
    const co    = document.getElementById('mCheckOut').value;
    const notes = document.getElementById('mNotes').value;

    if (!date) { toast('حدد التاريخ','danger'); return; }
    if (!leave && (!ci || !co)) { toast('أدخل وقت الدخول والخروج','danger'); return; }

    document.getElementById('mSaveTxt').style.opacity = '0';
    document.getElementById('mSpin').style.display    = 'inline-block';

    post({_action:'save_attendance',
          employee_id:empId, date, leave_type:leave,
          check_in:ci, check_out:co, notes})
    .then(d => {
        document.getElementById('mSaveTxt').style.opacity = '1';
        document.getElementById('mSpin').style.display    = 'none';
        if (d.ok) {
            modal.hide();
            toast(d.msg, 'success');
            // تحديث الصف
            const hType = d.hours >= (mEmployee?.schedule?.[getDayName(date)]?.to - mEmployee?.schedule?.[getDayName(date)]?.from || 8) ? 'full' : 'part';
            updateRow(empId, ci, co, d.hours, hType, 'present');
            updateCounters();
            // تحديث الزر
            const actCell = document.querySelector(`#attRow_${empId} td:last-child div`);
            if (actCell) actCell.innerHTML = `
                <span class="btn-done"><i class="bi bi-check2-circle"></i> مسجّل</span>
                <button class="btn-edit-att att-btn" data-id="${empId}" title="تعديل"><i class="bi bi-pencil"></i></button>`;
        } else toast(d.msg, 'danger');
    });
}

// ── مساعدات ──────────────────────────────────────────────────────
function updateRow(id, ci, co, hours, hType, status) {
    const ciEl = document.getElementById('ci_'+id);
    const coEl = document.getElementById('co_'+id);
    const hwEl = document.getElementById('hw_'+id);
    if (ciEl) { ciEl.textContent = ci ? ci.slice(0,5) : '—'; ciEl.className = ci ? 'time-ok' : 'time-na'; }
    if (coEl) { coEl.textContent = co ? co.slice(0,5) : '—'; coEl.className = co ? 'time-ok' : 'time-na'; }
    if (hwEl) {
        const cls = hType==='full'?'h-full':hType==='part'?'h-part':hType==='ot'?'h-ot':'h-none';
        hwEl.innerHTML = `<span class="h-badge ${cls}">${hours} ساعة</span>`;
    }
}

function updateCounters() {
    const present = document.querySelectorAll('.btn-done').length;
    const pending = document.querySelectorAll('.quick-save-btn:not([disabled])').length;
    const sub = document.getElementById('attDaySub');
    if (sub) sub.textContent = present+' حاضر من <?= count($employees) ?> موظف';
}

function post(data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k, v??''));
    return fetch(location.href, {method:'POST', body:fd}).then(r => r.json());
}

// ── العطل الرسمية ──────────────────────────────────────────────
const holidaysModal = new bootstrap.Modal(document.getElementById('holidaysModal'));

function openHolidaysModal() {
    holidaysModal.show();
    loadHolidays();
}

function loadHolidays() {
    post({_action:'get_holidays'}).then(d => {
        const list = document.getElementById('holidaysList');
        if (!d.ok || !d.data.length) {
            list.innerHTML = '<div class="text-center text-muted py-3"><i class="bi bi-calendar-check d-block mb-2 fs-3" style="opacity:.2"></i>لا توجد عطل رسمية مسجّلة</div>';
            return;
        }
        const months = ['','يناير','فبراير','مارس','أبريل','مايو','يونيو',
                        'يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
        list.innerHTML = d.data.map(h => {
            const dt = h.holiday_date ? h.holiday_date.slice(0,10) : '';
            const [hy,hm,hd] = dt.split('-');
            const dispDate = parseInt(hd)+' '+months[parseInt(hm)]+(h.is_recurring=='1'?'':' '+hy);
            return `<div class="d-flex align-items-center justify-content-between py-2 px-3 mb-1"
                         style="background:#fff;border-radius:10px;border:1px solid #fee2e2">
                <div class="d-flex align-items-center gap-2">
                    <span style="width:90px;font-size:.82rem;font-weight:600;color:#dc2626">${dispDate}</span>
                    <div>
                        <div style="font-size:.86rem;font-weight:600">${h.name}</div>
                        ${h.description?`<div class="text-muted" style="font-size:.74rem">${h.description}</div>`:''}
                    </div>
                    ${h.is_recurring=='1'?'<span class="badge bg-danger-subtle text-danger border border-danger-subtle" style="font-size:.7rem">سنوي</span>':''}
                </div>
                <button onclick="deleteHoliday(${h.id})" class="btn btn-sm"
                        style="border-radius:8px;background:#fef2f2;color:#dc2626;border:1px solid #fecaca">
                    <i class="bi bi-trash"></i>
                </button>
            </div>`;
        }).join('');
    });
}

function addHoliday() {
    const date = document.getElementById('hDate').value;
    const name = document.getElementById('hName').value.trim();
    const desc = document.getElementById('hDesc').value.trim();
    const rec  = document.getElementById('hRecurring').checked ? 1 : 0;
    if (!date || !name) { toast('التاريخ والاسم مطلوبان','danger'); return; }
    post({_action:'save_holiday', holiday_date:date, name, description:desc, is_recurring:rec})
    .then(d => {
        if (d.ok) {
            document.getElementById('hDate').value = '';
            document.getElementById('hName').value = '';
            document.getElementById('hDesc').value = '';
            document.getElementById('hRecurring').checked = false;
            loadHolidays();
            toast('تمت إضافة العطلة','success');
        } else toast(d.msg,'danger');
    });
}

function deleteHoliday(id) {
    if (!confirm('حذف هذه العطلة؟')) return;
    post({_action:'delete_holiday', id}).then(d => {
        if (d.ok) { loadHolidays(); toast('تم الحذف','success'); }
        else toast(d.msg,'danger');
    });
}

function toast(msg, type) {
    const t = document.createElement('div');
    t.className = `alert alert-${type} shadow-sm`;
    t.style.cssText = 'position:fixed;top:76px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:240px;text-align:center;font-size:.85rem;padding:.55rem 1.25rem';
    const ic = type==='success'?'check-circle-fill text-success':type==='danger'?'exclamation-triangle-fill text-danger':'info-circle-fill text-info';
    t.innerHTML = `<i class="bi bi-${ic} me-2"></i>${msg}`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3000);
}
</script>
</body>
</html>