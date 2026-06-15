<?php
/**
 * payroll_api.php — API موحّد للرواتب
 * المسار: /bayhas/aleppo/api/payroll_api.php
 */

// مسك كل الأخطاء
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR])) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'msg'=>$err['message'],'line'=>$err['line'],'type'=>'FATAL']);
    }
});
ini_set('display_errors', 0);

// تحقق من مسار config
$cfg  = __DIR__ . '/../../config/database.php';
$auth = __DIR__ . '/../../config/auth.php';
if (!file_exists($cfg) || !file_exists($auth)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'msg'=>'config path error','cfg'=>$cfg,'exists'=>file_exists($cfg)]);
    exit;
}

session_start();
require_once $cfg;
require_once $auth;

$pdo = getConnection();
checkLogin($pdo);

header('Content-Type: application/json; charset=utf-8');

$TS = $_SESSION['table_suffix'];
$TE = "hr_employees_{$TS}";
$TA = "hr_attendance_{$TS}";
$TP = "hr_payroll_{$TS}";
$TL = "hr_loans_{$TS}";
$TB = "hr_bonuses_{$TS}";
$TH = "public_holidays_{$TS}";

try {
    $br = $pdo->prepare("SELECT week_start_day FROM branches WHERE id=?");
    $br->execute([$_SESSION['branch_id'] ?? 0]);
    $brRow = $br->fetch();
    $WEEK_START = isset($brRow['week_start_day']) ? (int)$brRow['week_start_day'] : 1;
} catch (Throwable $e) { $WEEK_START = 1; }
$_SESSION['week_start_day'] = $WEEK_START;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['_action'])) {
    echo json_encode(['ok'=>false,'msg'=>'invalid request']);
    exit;
}

$act = $_POST['_action'];

try {

        if ($act === 'get_emp_periods') {
            $emp_id = (int)$_POST['employee_id'];
            $stmt   = $pdo->prepare("SELECT e.*, c.symbol AS cur_sym, c.code AS cur_code
                FROM `{$TE}` e LEFT JOIN currencies c ON c.id=e.currency_id WHERE e.id=?");
            $stmt->execute([$emp_id]);
            $emp = $stmt->fetch();
            if (!$emp) throw new Exception('الموظف غير موجود');

            $is_weekly = ($emp['salary_type'] === 'weekly');
            $hire_date = $emp['hire_date']; // لا نُظهر فترات قبل تاريخ التوظيف
            $periods   = [];

            if ($is_weekly) {
                // يوم بداية الأسبوع من إعدادات الفرع
                $wsd = (int)($_SESSION['week_start_day'] ?? 1);
                $day_names = [0=>'sunday',1=>'monday',2=>'tuesday',3=>'wednesday',
                              4=>'thursday',5=>'friday',6=>'saturday'];
                $day_en = $day_names[$wsd] ?? 'monday';

                // عطلة الموظف الأولى — نحسب طول الأسبوع
                $DAYS_ORDER = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
                // نرتّب الأيام ابتداءً من يوم البداية
                $days_from_start = [];
                $start_idx = array_search($day_en, $DAYS_ORDER);
                for ($x=0; $x<7; $x++) {
                    $days_from_start[] = $DAYS_ORDER[($start_idx + $x) % 7];
                }
                // نجد أول يوم عطلة للموظف في ترتيب الأسبوع
                $week_len = 7;
                foreach ($days_from_start as $xi => $dn) {
                    if ($emp["{$dn}_from"] === null) { $week_len = $xi; break; }
                }
                $week_len = max(1, $week_len);

                // بناء الأسابيع: ابتداءً من أحدث أسبوع
                $today = date('Y-m-d');
                // نجد بداية الأسبوع الحالي
                $cur_day_idx = (int)date('w'); // 0=أحد
                $days_since_start = ($cur_day_idx - $wsd + 7) % 7;
                $cur_week_start = date('Y-m-d', strtotime("-{$days_since_start} days"));

                // حساب عدد الأسابيع من hire_date حتى اليوم
                $days_from_hire  = max(0, (strtotime($today) - strtotime($hire_date)) / 86400);
                $total_weeks     = (int)ceil($days_from_hire / 7) + 2; // +2 للأمان
                $total_weeks     = max($total_weeks, 4);

                for ($w = 0; $w < $total_weeks; $w++) {
                    $from = date('Y-m-d', strtotime($cur_week_start . " -{$w} weeks"));
                    $to   = date('Y-m-d', strtotime($from . " +" . ($week_len - 1) . " days"));
                    if ($to < $hire_date) break; // وصلنا لما قبل التوظيف — نوقف
                    if ($from < $hire_date) $from = $hire_date;
                    $month_key = date('Y-m-01', strtotime($from));
                    // رقم الأسبوع = عدد أسابيع كاملة من بداية الشهر + 1
                    $month_first_day = date('w', strtotime($month_key)); // 0=أحد
                    $days_since_month = (strtotime($from) - strtotime($month_key)) / 86400;
                    $wn = min(4, (int)floor($days_since_month / 7) + 1);
                    $ex = $pdo->prepare("SELECT payment_status FROM `{$TP}`
                        WHERE employee_id=? AND period_from=?");
                    $ex->execute([$emp_id, $from]);
                    $existing = $ex->fetch();
                    $months_ar2 = ['01'=>'يناير','02'=>'فبراير','03'=>'مارس','04'=>'أبريل',
                                   '05'=>'مايو','06'=>'يونيو','07'=>'يوليو','08'=>'أغسطس',
                                   '09'=>'سبتمبر','10'=>'أكتوبر','11'=>'نوفمبر','12'=>'ديسمبر'];
                    $m_n = date('m', strtotime($from));
                    $periods[] = [
                        'label'    => date('d/m', strtotime($from)) . ' — ' . date('d/m/Y', strtotime($to))
                                    . ' (' . ($months_ar2[$m_n]??'') . ')',
                        'from'     => $from,
                        'to'       => $to,
                        'month'    => $month_key,
                        'week_num' => $wn,
                        'status'   => $existing['payment_status'] ?? null,
                    ];
                }
            } else {
                // آخر 24 شهر — نفلتر ما قبل hire_date
                for ($mo = 0; $mo < 24; $mo++) {
                    $month_key = date('Y-m-01', strtotime("-{$mo} months"));
                    $from      = $month_key;
                    $to        = date('Y-m-t', strtotime($from));
                    // تخطي الأشهر التي تنتهي قبل تاريخ التوظيف
                    if ($to < $hire_date) continue;
                    // إذا كان الشهر يشمل hire_date نعدّل from
                    if ($from < $hire_date) $from = $hire_date;
                    $ex = $pdo->prepare("SELECT payment_status FROM `{$TP}`
                        WHERE employee_id=? AND period_from=?");
                    $ex->execute([$emp_id, $from]);
                    $existing = $ex->fetch();
                    $months_ar = ['01'=>'يناير','02'=>'فبراير','03'=>'مارس','04'=>'أبريل',
                                  '05'=>'مايو','06'=>'يونيو','07'=>'يوليو','08'=>'أغسطس',
                                  '09'=>'سبتمبر','10'=>'أكتوبر','11'=>'نوفمبر','12'=>'ديسمبر'];
                    $m_num = date('m', strtotime($month_key));
                    $y_num = date('Y', strtotime($month_key));
                    $periods[] = [
                        'label'    => ($months_ar[$m_num] ?? '') . ' ' . $y_num,
                        'from'     => $from,
                        'to'       => $to,
                        'month'    => $month_key,
                        'week_num' => 0,
                        'status'   => $existing['payment_status'] ?? null,
                    ];
                }
            }

            // السلف النشطة
            $loans = $pdo->prepare("SELECT * FROM `{$TL}`
                WHERE employee_id=? AND status='active'");
            $loans->execute([$emp_id]);
            $active_loans = $loans->fetchAll();

            echo json_encode([
                'ok'      => true,
                'emp'     => $emp,
                'periods' => $periods,
                'loans'   => $active_loans,
            ]);
        }

        // ── احتساب الراتب لفترة محددة ──
        elseif ($act === 'calculate') {
            requirePermission('hr.payroll', 'create');
            $emp_id  = (int)$_POST['employee_id'];
            $from    = $_POST['period_from'];
            $to      = $_POST['period_to'];
            $month   = $_POST['payroll_month'];
            $week_n  = (int)$_POST['week_number'];

            $stmt = $pdo->prepare("SELECT e.*, c.symbol AS cur_sym
                FROM `{$TE}` e LEFT JOIN currencies c ON c.id=e.currency_id WHERE e.id=?");
            $stmt->execute([$emp_id]);
            $e = $stmt->fetch();
            if (!$e) throw new Exception('الموظف غير موجود');

            $DAYS   = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
            $wdays  = max(1, count(array_filter($DAYS, function($d) use ($e) { return $e["{$d}_from"] !== null; })));
            $is_wk  = $e['salary_type'] === 'weekly';
            $periods_in_salary = $is_wk ? 1 : 4;

            // ساعات الدوام اليومي (من أول يوم عمل)
            $day_hrs = 8;
            foreach ($DAYS as $d) {
                if ($e["{$d}_from"] !== null && $e["{$d}_to"] !== null) {
                    $day_hrs = (int)$e["{$d}_to"] - (int)$e["{$d}_from"];
                    break;
                }
            }
            $day_hrs = max(1, $day_hrs);
            $hr_rate = $e['basic_salary'] / ($wdays * $periods_in_salary * $day_hrs);
            $ot_mult = (float)($e['overtime_multiplier'] ?? 1.5);

            // حضور الفترة
            $att = $pdo->prepare("SELECT * FROM `{$TA}`
                WHERE employee_id=? AND attendance_date BETWEEN ? AND ?");
            $att->execute([$emp_id, $from, $to]);
            $rows = $att->fetchAll();
            // فهرسة الحضور بالتاريخ
            $att_by_date = [];
            foreach ($rows as $r) $att_by_date[$r['attendance_date']] = $r;

            // ── جلب العطل الرسمية خلال الفترة ──
            $ph_days = [];
            try {
                $ph_stmt = $pdo->prepare("SELECT holiday_date, name, is_recurring FROM `{$TH}`");
                $ph_stmt->execute();
                foreach ($ph_stmt->fetchAll() as $ph) {
                    $hd = $ph['holiday_date'];
                    // عطلة ثابتة: نتحقق من MM-DD فقط
                    if ($ph['is_recurring']) {
                        $mmdd = substr($hd, 5); // MM-DD
                        // نولّد كل تواريخ هذا MM-DD بين from وto
                        $cur = $from;
                        while ($cur <= $to) {
                            if (substr($cur, 5) === $mmdd) $ph_days[$cur] = $ph['name'];
                            $cur = date('Y-m-d', strtotime($cur . ' +1 day'));
                        }
                    } else {
                        if ($hd >= $from && $hd <= $to) $ph_days[$hd] = $ph['name'];
                    }
                }
            } catch (Throwable $e) {} // الجدول غير موجود بعد

            // ── حساب أيام الدوام والعطل الرسمية ──
            $working_days      = 0;
            $working_hours     = 0;
            $overtime_hrs      = 0;
            $holiday_days      = 0;   // أيام عطلة رسمية بدون حضور (تُحتسب كاملة)
            $holiday_ot_hrs    = 0;   // ساعات حضور في عطلة رسمية (أوفرتايم كامل)

            // أيام العمل النظامية للموظف
            $DAYS_MAP = [0=>'sunday',1=>'monday',2=>'tuesday',3=>'wednesday',
                         4=>'thursday',5=>'friday',6=>'saturday'];

            // نمشي على كل أيام الفترة
            $cur_date = $from;
            while ($cur_date <= $to) {
                $dow      = (int)date('w', strtotime($cur_date));
                $day_name = $DAYS_MAP[$dow];
                $is_work_day    = ($e["{$day_name}_from"] !== null);
                $is_ph          = isset($ph_days[$cur_date]);
                $att_row        = $att_by_date[$cur_date] ?? null;
                $has_attendance = $att_row && in_array($att_row['attendance_status'], ['present','late']);

                if ($is_ph) {
                    // عطلة رسمية
                    if ($has_attendance) {
                        // حضر في العطلة → كل ساعاته أوفرتايم
                        $holiday_ot_hrs += (float)$att_row['hours_worked'];
                    } else {
                        // لم يحضر → يُحتسب يوم دوام كامل (إذا كان يوم عمله النظامي)
                        if ($is_work_day) $holiday_days++;
                    }
                } elseif ($is_work_day) {
                    // يوم دوام عادي
                    if ($has_attendance) {
                        $working_days++;
                        $working_hours += (float)$att_row['hours_worked'];
                        $overtime_hrs  += (float)$att_row['overtime_hours'];
                    }
                }
                $cur_date = date('Y-m-d', strtotime($cur_date . ' +1 day'));
            }

            // أجرة أيام العطل الرسمية (بدون حضور) = عدد الأيام × ساعات اليوم × أجرة الساعة
            $holiday_amount    = round($holiday_days * $day_hrs * $hr_rate, 2);
            // أجرة أوفرتايم العطل الرسمية
            $holiday_ot_amount = round($holiday_ot_hrs * $hr_rate * $ot_mult, 2);

            $ot_amount   = round($overtime_hrs * $hr_rate * $ot_mult, 2);

            // ── الراتب المستحق بناءً على الساعات الفعلية + العطل الرسمية ──
            $regular_hours  = max(0, $working_hours - $overtime_hrs);
            $regular_amount = round($regular_hours * $hr_rate, 2);
            // العطل الرسمية تُضاف للراتب (أجرة عادية + أوفرتايم إذا حضر)
            $earned_salary  = $regular_amount + $holiday_amount;

            // مكافآت الفترة
            $bon = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM `{$TB}`
                WHERE employee_id=? AND bonus_date BETWEEN ? AND ?");
            $bon->execute([$emp_id, $from, $to]);
            $bonus_total = (float)$bon->fetchColumn();

            // خصم السلف
            $loan_stmt = $pdo->prepare("SELECT COALESCE(SUM(monthly_deduction),0) FROM `{$TL}`
                WHERE employee_id=? AND status='active'");
            $loan_stmt->execute([$emp_id]);
            $loan_ded = (float)$loan_stmt->fetchColumn();
            // للأسبوعي: قسط السلفة يُوزَّع على 4 أسابيع
            if ($is_wk) $loan_ded = round($loan_ded / 4, 2);

            $net = round($earned_salary + $ot_amount + $holiday_ot_amount + $bonus_total - $loan_ded, 2);

            // UPSERT — INSERT ON DUPLICATE KEY UPDATE (آمن من تعارض UNIQUE)
            $pdo->prepare("INSERT INTO `{$TP}`
                (employee_id,payroll_month,week_number,period_from,period_to,
                 basic_salary,working_days,working_hours,overtime_hours,
                 overtime_amount,bonus_total,loan_deduction,net_salary,
                 currency_id,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    payroll_month   = VALUES(payroll_month),
                    week_number     = VALUES(week_number),
                    period_to       = VALUES(period_to),
                    basic_salary    = VALUES(basic_salary),
                    working_days    = VALUES(working_days),
                    working_hours   = VALUES(working_hours),
                    overtime_hours  = VALUES(overtime_hours),
                    overtime_amount = VALUES(overtime_amount),
                    bonus_total     = VALUES(bonus_total),
                    loan_deduction  = VALUES(loan_deduction),
                    net_salary      = VALUES(net_salary),
                    currency_id     = VALUES(currency_id)")
                ->execute([
                    $emp_id, $month, $week_n, $from, $to,
                    $e['basic_salary'], $working_days, round($working_hours,2),
                    $overtime_hrs, $ot_amount, $bonus_total,
                    $loan_ded, $net, $e['currency_id'],
                    $_SESSION['user_id']
                ]);

            echo json_encode([
                'ok'             => true,
                'basic'          => $e['basic_salary'],
                'hr_rate'        => round($hr_rate, 4),
                'ot_mult'        => $ot_mult,
                'regular_hours'  => round($regular_hours, 2),
                'earned_salary'  => $earned_salary,
                'work_days'      => $working_days,
                'work_hours'     => round($working_hours, 2),
                'holiday_days'   => $holiday_days,
                'holiday_amount' => $holiday_amount,
                'holiday_ot_hrs' => round($holiday_ot_hrs, 2),
                'holiday_ot_amt' => $holiday_ot_amount,
                'ot_hours'       => $overtime_hrs,
                'ot_amount'      => $ot_amount,
                'bonus'          => $bonus_total,
                'loan_ded'       => $loan_ded,
                'net'            => $net,
                'currency'       => ($e['cur_sym'] ?? $e['cur_code'] ?? ''),
            ]);
        }

        // ── صرف الراتب ──
        elseif ($act === 'pay') {
            requirePermission('hr.payroll','edit');
            $emp_id = (int)$_POST['employee_id'];
            $month  = $_POST['payroll_month'];
            $week_n = (int)$_POST['week_number'];
            $method = $_POST['method'] ?? 'cash';
            $notes  = trim($_POST['notes'] ?? '');

            // نجلب period_from من الـ POST أو نبحث بـ payroll_month+week_number
            $pf = $_POST['period_from'] ?? '';
            if ($pf) {
                $pdo->prepare("UPDATE `{$TP}` SET
                    payment_status='paid',payment_date=NOW(),payment_method=?,notes=?
                    WHERE employee_id=? AND period_from=?")
                    ->execute([$method,$notes,$emp_id,$pf]);
            } else {
                $pdo->prepare("UPDATE `{$TP}` SET
                    payment_status='paid',payment_date=NOW(),payment_method=?,notes=?
                    WHERE employee_id=? AND payroll_month=? AND week_number=?")
                    ->execute([$method,$notes,$emp_id,$month,$week_n]);
            }

            // تحديث قسط السلفة
            $pdo->prepare("UPDATE `{$TL}` SET
                paid_installments = paid_installments + 1,
                status = IF(paid_installments+1 >= installments,'completed','active')
                WHERE employee_id=? AND status='active'")
                ->execute([$emp_id]);

            echo json_encode(['ok'=>true,'msg'=>'تم صرف الراتب']);
        }

        // ── إضافة سلفة ──
        elseif ($act === 'add_loan') {
            requirePermission('hr.payroll','create');
            $emp_id = (int)$_POST['employee_id'];
            $amount = floatval($_POST['amount']);
            $inst   = max(1,(int)$_POST['installments']);
            $reason = trim($_POST['reason'] ?? '');
            $date   = $_POST['loan_date'] ?? date('Y-m-d');
            $cur    = (int)($_POST['currency_id'] ?? 1);
            $ded    = round($amount / $inst, 2);
            $pdo->prepare("INSERT INTO `{$TL}`
                (employee_id,loan_date,amount,currency_id,installments,monthly_deduction,reason,created_by)
                VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$emp_id,$date,$amount,$cur,$inst,$ded,$reason,$_SESSION['user_id']]);
            echo json_encode(['ok'=>true,'msg'=>'تمت إضافة السلفة','deduction'=>$ded]);
        }

        // ── إضافة مكافأة ──
        elseif ($act === 'add_bonus') {
            requirePermission('hr.payroll','create');
            $emp_id = (int)$_POST['employee_id'];
            $amount = floatval($_POST['amount']);
            $type   = $_POST['bonus_type'] ?? 'performance';
            $desc   = trim($_POST['description'] ?? '');
            $date   = $_POST['bonus_date'] ?? date('Y-m-d');
            $cur    = (int)($_POST['currency_id'] ?? 1);
            $pdo->prepare("INSERT INTO `{$TB}`
                (employee_id,bonus_date,bonus_type,amount,currency_id,description,created_by)
                VALUES (?,?,?,?,?,?,?)")
                ->execute([$emp_id,$date,$type,$amount,$cur,$desc,$_SESSION['user_id']]);
            echo json_encode(['ok'=>true,'msg'=>'تمت إضافة المكافأة']);
        }

        elseif ($act === 'delete_loan') {
            requirePermission('hr.payroll','delete');
            $pdo->prepare("DELETE FROM `{$TL}` WHERE id=?")->execute([(int)$_POST['id']]);
            echo json_encode(['ok'=>true]);
        }

        elseif ($act === 'delete_bonus') {
            requirePermission('hr.payroll','delete');
            $pdo->prepare("DELETE FROM `{$TB}` WHERE id=?")->execute([(int)$_POST['id']]);
            echo json_encode(['ok'=>true]);
        }

        else throw new Exception('إجراء غير معروف');



} catch (Exception $e) {
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}