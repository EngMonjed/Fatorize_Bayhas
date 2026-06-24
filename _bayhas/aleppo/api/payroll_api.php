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
    $currMap=[1=>'USD',2=>'EUR',3=>'TRY',4=>'SYP'];
    $symMap=['USD'=>'$','SYP'=>'ل.س','TRY'=>'₺','EUR'=>'€'];

    // ── get_emp_periods ──
    if($act==='get_emp_periods'){
        $empId=(int)($_POST['employee_id']??0);
        $rawMonth=trim($_POST['month']??date('Y-m'));
        $monthFrom=date('Y-m-01',strtotime($rawMonth.'-01'));
        $monthTo=date('Y-m-t',strtotime($monthFrom));
        $st=$pdo->prepare("SELECT * FROM `{$TE}` WHERE id=?");
        $st->execute([$empId]);
        $emp=$st->fetch(PDO::FETCH_ASSOC);
        if(!$emp)throw new Exception('موظف غير موجود');
        $cur=$currMap[$emp['currency_id']??1]??'USD';
        $emp['cur_code']=$cur;
        $emp['cur_sym']=$symMap[$cur]??'$';
        $emp['cur_rate']=1;
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
        $lSt=$pdo->prepare("SELECT * FROM `{$TL}` WHERE employee_id=? ORDER BY id DESC");
        $lSt->execute([$empId]);$loans=$lSt->fetchAll(PDO::FETCH_ASSOC);
        $bSt=$pdo->prepare("SELECT * FROM `{$TB}` WHERE employee_id=? ORDER BY bonus_date DESC LIMIT 10");
        $bSt->execute([$empId]);$bonuses=$bSt->fetchAll(PDO::FETCH_ASSOC);
        $out=ob_get_clean();
        if($out)echo json_encode(['ok'=>false,'msg'=>'PHP:'.$out]);
        else echo json_encode(['ok'=>true,'emp'=>$emp,'periods'=>$periods,'loans'=>$loans,'bonuses'=>$bonuses]);
        exit;
    }

    // ── calculate ──
    if($act==='calculate'){
        $empId   =(int)($_POST['employee_id']??0);
        $dateFrom=$_POST['period_from']??'';
        $dateTo  =$_POST['period_to']??'';
        // عملة الفرع الأساسية بالـ id
        $brSt=$pdo->prepare("SELECT COALESCE(base_currency_id,1) AS base_curr_id FROM branches WHERE table_suffix=? LIMIT 1");
        $brSt->execute([$TS]);
        $branchBaseCurrId=(int)($brSt->fetchColumn()?:1);
        $st=$pdo->prepare("SELECT * FROM `{$TE}` WHERE id=?");
        $st->execute([$empId]);
        $emp=$st->fetch(PDO::FETCH_ASSOC);
        if(!$emp)throw new Exception("موظف {$empId} غير موجود");

        // الراتب الأساسي — اسم الحقل الصح من DB
        $base  = (float)$emp['basic_salary'];
        $curId = (int)($emp['currency_id'] ?? 1);
        $cur   = $currMap[$curId] ?? 'USD';
        $curSym= $symMap[$cur] ?? '$';
        $otMult= (float)($emp['overtime_multiplier'] ?? 1.5);

        // ساعات الجدول الأسبوعي
        $weeklySchedHours=0;
        $days=['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
        foreach($days as $d){
            $f=$emp[$d.'_from'];$t=$emp[$d.'_to'];
            if($f!==null&&$f!==''&&$t!==null&&$t!==''){
                $diff=(int)$t-(int)$f;
                if($diff>0)$weeklySchedHours+=$diff;
            }
        }
        $hrRate=$weeklySchedHours>0?round($base/$weeklySchedHours,4):0;

        // الحضور
        $attSt=$pdo->prepare("SELECT
            SUM(CASE WHEN attendance_status IN('present','on_leave','late') THEN 1
                WHEN attendance_status='half_day' THEN 0.5 ELSE 0 END) AS work_days,
            SUM(COALESCE(hours_worked,0)) AS total_hours,
            SUM(CASE WHEN attendance_status='absent' THEN 1 ELSE 0 END) AS absent_days,
            SUM(COALESCE(overtime_hours,0)) AS overtime_h
            FROM `{$TAT}` WHERE employee_id=? AND attendance_date BETWEEN ? AND ?");
        $attSt->execute([$empId,$dateFrom,$dateTo]);
        $att=$attSt->fetch(PDO::FETCH_ASSOC);
        $workDays  =(float)($att['work_days']??0);
        $totalHours=(float)($att['total_hours']??0);
        $absDays   =(float)($att['absent_days']??0);
        $otHours   =(float)($att['overtime_h']??0);

        // العطل
        $holSt=$pdo->prepare("SELECT COUNT(*) FROM `{$TH}` WHERE holiday_date BETWEEN ? AND ?");
        $holSt->execute([$dateFrom,$dateTo]);
        $holDays=(int)$holSt->fetchColumn();

        // الحساب
        if($emp['salary_type']==='monthly'){
            $pd=max(1,(new DateTime($dateTo))->diff(new DateTime($dateFrom))->days+1);
            $hrRate=round($base/(22*8),4);
            $earned=$base*($workDays+$holDays)/$pd;
            $regularHours=($workDays+$holDays)*8;
        }else{
            $earned=$hrRate*$totalHours;
            $regularHours=$totalHours;
        }
        $holAmount=$holDays*$hrRate*8;
        $otAmt    =$otHours*$hrRate*$otMult;

        // مكافآت وسلف
        $bSt=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM `{$TB}` WHERE employee_id=? AND bonus_date BETWEEN ? AND ?");
        $bSt->execute([$empId,$dateFrom,$dateTo]);
        $bonus=(float)$bSt->fetchColumn();
        $lSt=$pdo->prepare("SELECT COALESCE(SUM(monthly_deduction),0) FROM `{$TL}` WHERE employee_id=? AND status='active'");
        $lSt->execute([$empId]);
        $loan=(float)$lSt->fetchColumn();
        $net=$earned+$otAmt+$bonus-$loan;

        $out=ob_get_clean();
        if($out){echo json_encode(['ok'=>false,'msg'=>'PHP:'.$out]);exit;}
        echo json_encode([
            'ok'=>true,
            // JS fields
            'hr_rate'        =>$hrRate,
            'regular_hours'  =>$regularHours,
            'earned_salary'  =>round($earned,2),
            'holiday_days'   =>$holDays,
            'holiday_amount' =>round($holAmount,2),
            'holiday_ot_hrs' =>0,
            'holiday_ot_amt' =>0,
            'ot_hours'       =>$otHours,
            'ot_mult'        =>$otMult,
            'ot_amount'      =>round($otAmt,2),
            'bonus'          =>round($bonus,2),
            'loan_ded'       =>round($loan,2),
            'net'            =>round($net,2),
            'currency'         =>$curSym,
            'needs_rate_input' =>$needsRate,
            'emp_cur_id'       =>$curId,
            'branch_cur_id'    =>$branchBaseCurrId,
            // pay fields
            'basic_salary'   =>round($earned,2),
            'working_days'   =>$workDays,
            'absent_days'    =>$absDays,
            'overtime_hours' =>$otHours,
            'overtime_amount'=>round($otAmt,2),
            'bonus_total'    =>round($bonus,2),
            'loan_deduction' =>round($loan,2),
            'net_salary'     =>round($net,2),
        ]);
        exit;
    }

    // ── pay ──
    if($act==='pay'){
        $empId   =(int)($_POST['employee_id']??0);
        // عملة الفرع
        $brSt2=$pdo->prepare("SELECT COALESCE(base_currency_id,1) AS base_curr_id FROM branches WHERE table_suffix=? LIMIT 1");
        $brSt2->execute([$TS]);
        $branchBaseCurrId2=(int)($brSt2->fetchColumn()?:1);
        $month   =$_POST['payroll_month']??'';
        $weekNum =(int)($_POST['week_number']??0);
        $method  =$_POST['method']??'cash';
        $notes   =trim($_POST['notes']??'');
        $dateFrom=$_POST['period_from']??'';
        $dateTo  =$_POST['period_to']??'';
        $cd=json_decode($_POST['calc_data']??'{}',true)?:[];
        $st=$pdo->prepare("SELECT * FROM `{$TE}` WHERE id=?");
        $st->execute([$empId]);$emp=$st->fetch(PDO::FETCH_ASSOC);
        if(!$emp)throw new Exception('موظف غير موجود');
        $net  =(float)($cd['net_salary']??0);
        $basic=(float)($cd['basic_salary']??0);
        $wd   =(float)($cd['working_days']??0);
        $oth  =(float)($cd['overtime_hours']??0);
        $ota  =(float)($cd['overtime_amount']??0);
        $bon  =(float)($cd['bonus_total']??0);
        $lnd  =(float)($cd['loan_deduction']??0);
        // السماح بتسجيل راتب 0 (إجازة بدون راتب أو غياب كامل)
        $chk=$pdo->prepare("SELECT id,payment_status FROM `{$TP}` WHERE employee_id=? AND period_from=?");
        $chk->execute([$empId,$dateFrom]);$ex=$chk->fetch(PDO::FETCH_ASSOC);
        if($ex&&$ex['payment_status']==='paid')throw new Exception('مصروف مسبقاً');
        $pdo->beginTransaction();
        $curId=$emp['currency_id']??1;
        $cashAccId=(int)($_POST['cash_account_id']??0)?:null;

        if($ex){
            $pdo->prepare("UPDATE `{$TP}` SET basic_salary=?,working_days=?,working_hours=?,
                overtime_hours=?,overtime_amount=?,bonus_total=?,loan_deduction=?,net_salary=?,
                currency_id=?,payment_status='paid',payment_date=NOW(),payment_method=?,
                cash_account_id=?,notes=? WHERE id=?")
                ->execute([$basic,$wd,$wd*8,$oth,$ota,$bon,$lnd,$net,$curId,$method,$cashAccId,$notes,$ex['id']]);
            $payId=$ex['id'];
        }else{
            $pdo->prepare("INSERT INTO `{$TP}` (employee_id,payroll_month,week_number,period_from,period_to,
                basic_salary,working_days,working_hours,overtime_hours,overtime_amount,bonus_total,
                loan_deduction,net_salary,currency_id,payment_status,payment_date,payment_method,
                cash_account_id,notes,created_by)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,'paid',NOW(),?,?,?,?)")
                ->execute([$empId,$month,$weekNum,$dateFrom,$dateTo,$basic,$wd,$wd*8,$oth,$ota,$bon,$lnd,$net,$curId,$method,$cashAccId,$notes,$_SESSION['user_id']]);
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
        $cur=$currMap[$curId]??'USD';
        // هل عملة الموظف نفس عملة الفرع؟
        $needsRate=($curId!==$branchBaseCurrId);
        // سعر الصرف — المُرسل من الواجهة أو من DB
        $curRatePost=(float)($_POST['exchange_rate']??0);
        if($curRatePost>0){
            $curRate=$curRatePost;
        } else {
            $curRateSt=$pdo->prepare("SELECT exchange_rate FROM currencies WHERE id=? LIMIT 1");
            $curRateSt->execute([$curId]);
            $curRate=(float)($curRateSt->fetchColumn()?:1);
            if($curRate<=0)$curRate=1;
        }
        $netUsd=round($net*$curRate,4); // المبلغ بالدولار للقيد
        $netOrig=$net; // المبلغ الأصلي بعملة الموظف
        $aS=$pdo->query("SELECT ac.* FROM `{$TIAS}` i JOIN `{$TAC}` ac ON ac.id=i.account_id WHERE i.setting_key='salary_expense' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        // حساب الدفع: المختار أو الافتراضي حسب طريقة الدفع
        if($cashAccId){
            $aCst=$pdo->prepare("SELECT * FROM `{$TAC}` WHERE id=?");
            $aCst->execute([$cashAccId]);$aC=$aCst->fetch(PDO::FETCH_ASSOC);
        } elseif($method==='bank_transfer'){
            $aC=$pdo->query("SELECT ac.* FROM `{$TIAS}` i JOIN `{$TAC}` ac ON ac.id=i.account_id WHERE i.setting_key='cash_usd' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        } else {
            $aC=$pdo->query("SELECT ac.* FROM `{$TIAS}` i JOIN `{$TAC}` ac ON ac.id=i.account_id WHERE i.setting_key='cash_usd' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        }
        if($aS&&$aC){
            $y=date('Y');
            $last=$pdo->query("SELECT entry_number FROM `{$TJE}` WHERE entry_number LIKE 'JE-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
            $seq=$last?(int)substr($last,-4)+1:1;
            $jeNo='JE-'.$y.'-'.str_pad($seq,4,'0',STR_PAD_LEFT);
            $en=$emp['full_name']??'موظف';
            $pdo->prepare("INSERT INTO `{$TJE}` (entry_number,entry_date,description,currency,exchange_rate,total_debit,total_credit,status,reference_type,reference_id,created_by) VALUES(?,?,?,?,?,?,?,'posted','payroll',?,?)")
                ->execute([$jeNo,date('Y-m-d'),"راتب {$en} {$month}",$cur,$curRate,$netUsd,$netUsd,$payId,$_SESSION['user_id']]);
            $jeId=(int)$pdo->lastInsertId();
            // مدين: مصروف الرواتب
            $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency,exchange_rate) VALUES(?,?,?,0,?,?,?,?,?)")
                ->execute([$jeId,$aS['id'],$netUsd,$netOrig,$netUsd,"راتب {$en}",$cur,$curRate]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+? WHERE id=?")->execute([$netUsd,$aS['id']]);
            // دائن: الصندوق/البنك
            $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency,exchange_rate) VALUES(?,?,0,?,?,?,?,?,?)")
                ->execute([$jeId,$aC['id'],$netUsd,$netOrig,$netUsd,"دفع راتب {$en}",$cur,$curRate]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-? WHERE id=?")->execute([$netUsd,$aC['id']]);
        }
        // ربط القيد بسند الصرف
        if(isset($jeId)){
            $pdo->prepare("UPDATE `{$TP}` SET journal_entry_id=? WHERE id=?")->execute([$jeId,$payId]);
        }
        $pdo->commit();
        $out=ob_get_clean();
        if($out)echo json_encode(['ok'=>false,'msg'=>'PHP:'.$out]);
        else echo json_encode(['ok'=>true,'msg'=>'تم صرف الراتب ✅','id'=>$payId]);
        exit;
    }

    if($act==='add_loan'){
        $empId=(int)($_POST['employee_id']??0);$amt=(float)($_POST['amount']??0);
        if($amt<=0)throw new Exception('المبلغ 0');
        $ac=$pdo->prepare("SELECT COUNT(*) FROM `{$TL}` WHERE employee_id=? AND status='active'");
        $ac->execute([$empId]);if($ac->fetchColumn())throw new Exception('سلفة نشطة موجودة');
        $pdo->prepare("INSERT INTO `{$TL}` (employee_id,loan_date,amount,currency_id,installments,paid_installments,monthly_deduction,reason,status,created_by) VALUES(?,?,?,?,?,0,?,?,'active',?)")
            ->execute([$empId,$_POST['loan_date']??date('Y-m-d'),$amt,(int)($_POST['currency_id']??1),(int)($_POST['installments']??1),round($amt/max(1,(int)($_POST['installments']??1)),2),trim($_POST['reason']??''),$_SESSION['user_id']]);
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
    echo json_encode(['ok'=>false,'msg'=>'unknown action: '.$act]);
}catch(Throwable $e){
    ob_end_clean();
    http_response_code(200);
    echo json_encode(['ok'=>false,'msg'=>$e->getMessage(),'line'=>$e->getLine()]);
}
