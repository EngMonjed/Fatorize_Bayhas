<?php
ob_start();
ini_set('display_errors',1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
try {
    session_start();
    if(!isset($_SESSION['user_id'])){echo json_encode(['ok'=>false,'msg'=>'no session']);exit;}
    require_once __DIR__.'/../../config/database.php';
    require_once __DIR__.'/../../config/auth.php';
    $pdo=getConnection();
    $TS=$_SESSION['table_suffix'];
    $TE="hr_employees_{$TS}";
    $TP="hr_payroll_{$TS}";
    $TL="hr_loans_{$TS}";
    $TB="hr_bonuses_{$TS}";
    $TAT="hr_attendance_{$TS}";
    $TH="public_holidays_{$TS}";
    $TAC="account_charts_{$TS}";
    $TJE="journal_entries_{$TS}";
    $TJI="journal_entry_items_{$TS}";
    $TIAS="invoice_account_settings_{$TS}";
    $act=$_POST['_action']??'';

    if($act==='get_emp_periods'){
        $empId=(int)($_POST['employee_id']??0);
        $rawMonth=trim($_POST['month']??date('Y-m'));
        $monthFrom=date('Y-m-01',strtotime($rawMonth.'-01'));
        $monthTo=date('Y-m-t',strtotime($monthFrom));
        $empSt=$pdo->prepare("SELECT * FROM `{$TE}` WHERE id=?");
        $empSt->execute([$empId]);
        $emp=$empSt->fetch(PDO::FETCH_ASSOC);
        if(!$emp)throw new Exception('موظف غير موجود');
        $emp['cur_code']='USD';$emp['cur_sym']='$';$emp['cur_rate']=1;
        $exSt=$pdo->prepare("SELECT * FROM `{$TP}` WHERE employee_id=? AND payroll_month=?");
        $exSt->execute([$empId,$monthFrom]);
        $existing=[];
        foreach($exSt->fetchAll(PDO::FETCH_ASSOC) as $r)$existing[$r['week_number']]=$r;
        $periods=[];
        if($emp['salary_type']==='monthly'){
            $ex=$existing[0]??null;
            $periods[]=['week_num'=>0,'label'=>'الشهر كاملاً','from'=>$monthFrom,'to'=>$monthTo,
                'month'=>$monthFrom,'status'=>$ex?$ex['payment_status']:'pending',
                'net'=>$ex?(float)$ex['net_salary']:null,'id'=>$ex?$ex['id']:null];
        }else{
            $day=new DateTime($monthFrom);$end=new DateTime($monthTo);$w=1;
            while($day<=$end&&$w<=4){
                $wS=clone $day;$wE=clone $day;$wE->modify('+6 days');
                if($wE>$end)$wE=clone $end;
                $ex=$existing[$w]??null;
                $periods[]=['week_num'=>$w,'label'=>"الأسبوع {$w}",'from'=>$wS->format('Y-m-d'),
                    'to'=>$wE->format('Y-m-d'),'month'=>$monthFrom,
                    'status'=>$ex?$ex['payment_status']:'pending',
                    'net'=>$ex?(float)$ex['net_salary']:null,'id'=>$ex?$ex['id']:null];
                $day->modify('+7 days');$w++;
            }
        }
        $out=ob_get_clean();
        if($out)echo json_encode(['ok'=>false,'msg'=>'PHP output: '.$out]);
        $lSt=$pdo->prepare("SELECT * FROM `{$TL}` WHERE employee_id=? ORDER BY id DESC");
        $lSt->execute([$empId]);$loans=$lSt->fetchAll(PDO::FETCH_ASSOC);
        $bSt=$pdo->prepare("SELECT * FROM `{$TB}` WHERE employee_id=? ORDER BY bonus_date DESC LIMIT 10");
        $bSt->execute([$empId]);$bonuses=$bSt->fetchAll(PDO::FETCH_ASSOC);
        $out=ob_get_clean();
        if($out)echo json_encode(['ok'=>false,'msg'=>'PHP: '.$out]);
        else echo json_encode(['ok'=>true,'emp'=>$emp,'periods'=>$periods,'loans'=>$loans,'bonuses'=>$bonuses]);
        exit;
    }

    if($act==='calculate'){
        $empId=(int)($_POST['employee_id']??0);
        $dateFrom=$_POST['period_from']??'';
        $dateTo=$_POST['period_to']??'';
        $empSt=$pdo->prepare("SELECT * FROM `{$TE}` WHERE id=?");
        $empSt->execute([$empId]);
        $emp=$empSt->fetch(PDO::FETCH_ASSOC);
        if(!$emp)throw new Exception('موظف غير موجود');
        $attSt=$pdo->prepare("SELECT
            SUM(CASE WHEN attendance_status IN('present','on_leave','late') THEN 1
                WHEN attendance_status='half_day' THEN 0.5 ELSE 0 END) AS work_days,
            SUM(COALESCE(hours_worked,0)) AS total_hours,
            SUM(CASE WHEN attendance_status='absent' THEN 1 ELSE 0 END) AS absent_days,
            SUM(COALESCE(overtime_hours,0)) AS overtime_h
            FROM `{$TAT}` WHERE employee_id=? AND attendance_date BETWEEN ? AND ?");
        $attSt->execute([$empId,$dateFrom,$dateTo]);
        $att=$attSt->fetch(PDO::FETCH_ASSOC);
        $holSt=$pdo->prepare("SELECT COUNT(*) FROM `{$TH}` WHERE holiday_date BETWEEN ? AND ?");
        $holSt->execute([$dateFrom,$dateTo]);
        $holDays  = (int)$holSt->fetchColumn();
        $workDays = (float)($att['work_days']  ?? 0);
        $totalHours=(float)($att['total_hours'] ?? 0);
        $absDays  = (float)($att['absent_days'] ?? 0);
        $otHours  = (float)($att['overtime_h']  ?? 0);
        $base     = (float)$emp['base_salary'];
        $otMult   = (float)($emp['overtime_multiplier'] ?? 1.5);

        // حساب الراتب حسب النوع
        if ($emp['salary_type']==='monthly') {
            // شهري: نسبة أيام الحضور من أيام الشهر
            $pd      = max(1,(new DateTime($dateTo))->diff(new DateTime($dateFrom))->days+1);
            $hrRate  = round($base/(22*8),4);
            $earned  = $base * ($workDays+$holDays) / $pd;
            $regularHours = ($workDays+$holDays)*8;
        } else {
            // أسبوعي: بناءً على ساعات العمل الفعلية
            // نحسب الأجر الساعي من راتب الأسبوع ÷ ساعات الجدول
            $schedHoursDay = 0; $scheduledDays = 0;
            $days=['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
            foreach($days as $d){
                $f=$emp[$d.'_from']??null; $t=$emp[$d.'_to']??null;
                if($f!==null&&$f!==''){$schedHoursDay+=((int)$t-(int)$f);$scheduledDays++;}
            }
            $weeklySchedHours = $schedHoursDay; // مجموع ساعات الأسبوع
            $hrRate  = $weeklySchedHours>0 ? round($base/$weeklySchedHours,4) : round($base/40,4);
            $earned  = $hrRate * $totalHours;
            $regularHours = $totalHours;
        }

        // عطل رسمية
        $holAmount = $holDays * $hrRate * 8;
        $otAmt     = $otHours * $hrRate * $otMult;

        $bonSt=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM `{$TB}` WHERE employee_id=? AND bonus_date BETWEEN ? AND ?");
        $bonSt->execute([$empId,$dateFrom,$dateTo]);
        $bonus=(float)$bonSt->fetchColumn();
        $loanSt=$pdo->prepare("SELECT COALESCE(SUM(monthly_deduction),0) FROM `{$TL}` WHERE employee_id=? AND status='active'");
        $loanSt->execute([$empId]);
        $loan=(float)$loanSt->fetchColumn();
        $net=$earned+$otAmt+$bonus-$loan;
        $earnedSalary=round($earned,2);
        $holidayAmt=round($holAmount,2);
        $out=ob_get_clean();
        if($out)echo json_encode(['ok'=>false,'msg'=>'PHP: '.$out]);
        else echo json_encode(array_merge(['ok'=>true],[
            'hr_rate'        => $hrRate,
            'regular_hours'  => $regularHours,
            'earned_salary'  => $earnedSalary,
            'holiday_days'   => $holDays,
            'holiday_amount' => $holidayAmt,
            'holiday_ot_hrs' => 0,
            'holiday_ot_amt' => 0,
            'ot_hours'       => $otHours,
            'ot_mult'        => $otMult,
            'ot_amount'      => round($otAmt,2),
            'bonus'          => round($bonus,2),
            'loan_ded'       => round($loan,2),
            'net'            => round($net,2),
            'currency'       => 'USD',
            'basic_salary'   => $earnedSalary,
            'working_days'   => $workDays,
            'absent_days'    => $absDays,
            'overtime_hours' => $otHours,
            'overtime_amount'=> round($otAmt,2),
            'bonus_total'    => round($bonus,2),
            'loan_deduction' => round($loan,2),
            'net_salary'     => round($net,2),
        ]));
        exit;
    }

    if($act==='pay'){
        $empId=(int)($_POST['employee_id']??0);
        $month=$_POST['payroll_month']??'';
        $weekNum=(int)($_POST['week_number']??0);
        $method=$_POST['method']??'cash';
        $notes=trim($_POST['notes']??'');
        $dateFrom=$_POST['period_from']??'';
        $dateTo=$_POST['period_to']??'';
        $calcData=json_decode($_POST['calc_data']??'{}',true)?:[];
        $empSt=$pdo->prepare("SELECT * FROM `{$TE}` WHERE id=?");
        $empSt->execute([$empId]);$emp=$empSt->fetch(PDO::FETCH_ASSOC);
        if(!$emp)throw new Exception('موظف غير موجود');
        $net=(float)($calcData['net_salary']??0);
        $basic=(float)($calcData['basic_salary']??0);
        $wd=(float)($calcData['working_days']??0);
        $oth=(float)($calcData['overtime_hours']??0);
        $ota=(float)($calcData['overtime_amount']??0);
        $bon=(float)($calcData['bonus_total']??0);
        $lnd=(float)($calcData['loan_deduction']??0);
        $netUsd=$net;
        if($net<=0)throw new Exception('الراتب الصافي 0');
        $chk=$pdo->prepare("SELECT id,payment_status FROM `{$TP}` WHERE employee_id=? AND period_from=?");
        $chk->execute([$empId,$dateFrom]);$ex=$chk->fetch(PDO::FETCH_ASSOC);
        if($ex&&$ex['payment_status']==='paid')throw new Exception('مصروف مسبقاً');
        $pdo->beginTransaction();
        $curId=$emp['currency_id']??1;
        if($ex){
            $pdo->prepare("UPDATE `{$TP}` SET basic_salary=?,working_days=?,working_hours=?,
                overtime_hours=?,overtime_amount=?,bonus_total=?,loan_deduction=?,net_salary=?,
                currency_id=?,payment_status='paid',payment_date=NOW(),payment_method=?,notes=? WHERE id=?")
                ->execute([$basic,$wd,$wd*8,$oth,$ota,$bon,$lnd,$net,$curId,$method,$notes,$ex['id']]);
            $payId=$ex['id'];
        }else{
            $pdo->prepare("INSERT INTO `{$TP}` (employee_id,payroll_month,week_number,period_from,period_to,
                basic_salary,working_days,working_hours,overtime_hours,overtime_amount,bonus_total,
                loan_deduction,net_salary,currency_id,payment_status,payment_date,payment_method,notes,created_by)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,'paid',NOW(),?,?,?)")
                ->execute([$empId,$month,$weekNum,$dateFrom,$dateTo,$basic,$wd,$wd*8,$oth,$ota,$bon,$lnd,$net,$curId,$method,$notes,$_SESSION['user_id']]);
            $payId=(int)$pdo->lastInsertId();
        }
        if($lnd>0){
            $ls=$pdo->prepare("SELECT * FROM `{$TL}` WHERE employee_id=? AND status='active' LIMIT 1");
            $ls->execute([$empId]);$ln=$ls->fetch(PDO::FETCH_ASSOC);
            if($ln){$np=$ln['paid_installments']+1;
                $pdo->prepare("UPDATE `{$TL}` SET paid_installments=?,status=? WHERE id=?")
                ->execute([$np,$np>=$ln['installments']?'completed':'active',$ln['id']]);}
        }
        // قيد محاسبي
        $aS=$pdo->query("SELECT ac.* FROM `{$TIAS}` i JOIN `{$TAC}` ac ON ac.id=i.account_id WHERE i.setting_key='salary_expense' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $aC=$pdo->query("SELECT ac.* FROM `{$TIAS}` i JOIN `{$TAC}` ac ON ac.id=i.account_id WHERE i.setting_key='cash_usd' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if($aS&&$aC){
            $y=date('Y');
            $last=$pdo->query("SELECT entry_number FROM `{$TJE}` WHERE entry_number LIKE 'JE-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
            $seq=$last?(int)substr($last,-4)+1:1;
            $jeNo='JE-'.$y.'-'.str_pad($seq,4,'0',STR_PAD_LEFT);
            $en=$emp['full_name']??'موظف';
            $pdo->prepare("INSERT INTO `{$TJE}` (entry_number,entry_date,description,currency,exchange_rate,total_debit,total_credit,status,reference_type,reference_id,created_by) VALUES(?,?,?,?,?,?,?,'posted','payroll',?,?)")
                ->execute([$jeNo,date('Y-m-d'),"راتب {$en} {$month}",'USD',1,$netUsd,$netUsd,$payId,$_SESSION['user_id']]);
            $jeId=(int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency,exchange_rate) VALUES(?,?,?,0,?,?,?,?,?)")
                ->execute([$jeId,$aS['id'],$netUsd,$net,$netUsd,"راتب {$en}",'USD',1]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+? WHERE id=?")->execute([$netUsd,$aS['id']]);
            $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency,exchange_rate) VALUES(?,?,0,?,?,?,?,?,?)")
                ->execute([$jeId,$aC['id'],$netUsd,$net,$netUsd,"دفع {$en}",'USD',1]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-? WHERE id=?")->execute([$netUsd,$aC['id']]);
        }
        $pdo->commit();
        $out=ob_get_clean();
        if($out)echo json_encode(['ok'=>false,'msg'=>'PHP: '.$out]);
        else echo json_encode(['ok'=>true,'msg'=>'تم صرف الراتب ✅','id'=>$payId]);
        exit;
    }

    if($act==='add_loan'){
        $empId=(int)($_POST['employee_id']??0);$amt=(float)($_POST['amount']??0);
        $curId=(int)($_POST['currency_id']??1);$inst=max(1,(int)($_POST['installments']??1));
        $reason=trim($_POST['reason']??'');$date=$_POST['loan_date']??date('Y-m-d');
        if($amt<=0)throw new Exception('المبلغ 0');
        $ac=$pdo->prepare("SELECT COUNT(*) FROM `{$TL}` WHERE employee_id=? AND status='active'");
        $ac->execute([$empId]);if($ac->fetchColumn())throw new Exception('سلفة نشطة موجودة');
        $pdo->prepare("INSERT INTO `{$TL}` (employee_id,loan_date,amount,currency_id,installments,paid_installments,monthly_deduction,reason,status,created_by) VALUES(?,?,?,?,?,0,?,?,'active',?)")
            ->execute([$empId,$date,$amt,$curId,$inst,round($amt/$inst,2),$reason,$_SESSION['user_id']]);
        echo json_encode(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);exit;
    }
    if($act==='delete_loan'){
        $pdo->prepare("UPDATE `{$TL}` SET status='cancelled' WHERE id=? AND status='active'")->execute([(int)($_POST['id']??0)]);
        echo json_encode(['ok'=>true]);exit;
    }
    if($act==='add_bonus'){
        $empId=(int)($_POST['employee_id']??0);$amt=(float)($_POST['amount']??0);
        if($amt<=0)throw new Exception('المبلغ 0');
        $pdo->prepare("INSERT INTO `{$TB}` (employee_id,bonus_date,bonus_type,amount,currency_id,description,created_by) VALUES(?,?,?,?,?,?,?)")
            ->execute([$empId,$_POST['bonus_date']??date('Y-m-d'),$_POST['bonus_type']??'other',$amt,(int)($_POST['currency_id']??1),trim($_POST['description']??''),$_SESSION['user_id']]);
        echo json_encode(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);exit;
    }
    if($act==='delete_bonus'){
        $pdo->prepare("DELETE FROM `{$TB}` WHERE id=?")->execute([(int)($_POST['id']??0)]);
        echo json_encode(['ok'=>true]);exit;
    }

    $out=ob_get_clean();
    echo json_encode(['ok'=>false,'msg'=>'unknown action: '.$act.($out?' | '.$out:'')]);

}catch(Throwable $e){
    ob_end_clean();
    http_response_code(200);
    echo json_encode(['ok'=>false,'msg'=>$e->getMessage(),'file'=>basename($e->getFile()),'line'=>$e->getLine()]);
}
