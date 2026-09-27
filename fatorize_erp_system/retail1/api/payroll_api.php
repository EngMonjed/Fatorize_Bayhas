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
    $TLA="hr_payroll_loan_allocations_{$TS}";
    $act=$_POST['_action']??'';
    // جلب العملات من DB بدل hardcoded
    $curRows=$pdo->query("SELECT id,code,symbol FROM currencies WHERE status='active'")->fetchAll(PDO::FETCH_ASSOC);
    $currMap=[]; $symMap=[];
    foreach($curRows as $cr){ $currMap[$cr['id']]=$cr['code']; $symMap[$cr['code']]=$cr['symbol']; }

    // سعر الصرف "الآمن" لتحويل مبلغ من عملة الموظف لعملة الفرع الأساسية.
    // المصدر الوحيد المعتمد لحساب base_amount هو جدول currencies (نفس
    // المصدر المعروض بالواجهة عبر زر "جلب السعر") — لا نثق بأي رقم قادم
    // من POST لحساب المبلغ المحاسبي الفعلي إطلاقاً، حتى لو الواجهة عرضت
    // رقم مختلف (تعديل يدوي بالمتصفح أو تلاعب بالطلب لا يغيّر القيد الحقيقي)
    function payrollSafeRate(PDO $pdo, int $curId, int $branchBaseCurrId): float {
        if ($curId === $branchBaseCurrId || !$branchBaseCurrId) return 1.0;
        $st = $pdo->prepare("SELECT exchange_rate FROM currencies WHERE id=? LIMIT 1");
        $st->execute([$curId]);
        $rate = (float)($st->fetchColumn() ?: 0);
        return $rate > 0 ? $rate : 1.0;
    }

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
        $cur=$currMap[$emp['currency_id']]??'USD';
        $emp['cur_code']=$cur;
        $emp['cur_sym']=$symMap[$cur]??'$';
        $emp['cur_rate']=1;
        // نجيب أي سجل راتب موجود بنطاق أوسع من الشهر المختار بقليل (٦ أيام
        // زيادة كل طرف) — لأنه أسبوع الموظف الحقيقي ممكن يبلش بالشهر
        // السابق أو يمتد للشهر التالي. المطابقة بتاريخ البداية الفعلي
        // (period_from) مباشرة، مو برقم أسبوع مرتبط بالشهر
        $wideFrom=(new DateTime($monthFrom))->modify('-6 days')->format('Y-m-d');
        $wideTo=(new DateTime($monthTo))->modify('+6 days')->format('Y-m-d');
        $exSt=$pdo->prepare("SELECT * FROM `{$TP}` WHERE employee_id=? AND period_from BETWEEN ? AND ?");
        $exSt->execute([$empId,$wideFrom,$wideTo]);
        $existing=[];
        foreach($exSt->fetchAll(PDO::FETCH_ASSOC) as $r)$existing[$r['period_from']]=$r;
        $periods=[];
        if($emp['salary_type']==='monthly'){
            $ex=$existing[$monthFrom]??null;
            $periods[]=['week_num'=>0,'label'=>'الشهر كاملاً','from'=>$monthFrom,'to'=>$monthTo,
                'month'=>$monthFrom,'status'=>$ex?$ex['payment_status']:'pending',
                'net'=>$ex?(float)$ex['net_salary']:null,'id'=>$ex?$ex['id']:null];
        }else{
            // أسابيع متواصلة مرتكزة على يوم عطلة الموظف الأسبوعية الفعلي —
            // مستقلة تماماً عن حدود الشهر التقويمي. الأسبوع بيبلش أول يوم
            // دوام بعد يوم عطلته مباشرة (مثال: عطلته الجمعة → أسبوعه دايماً
            // سبت→جمعة، حتى لو امتد بين شهرين). بدون هالمنطق، آخر أيام
            // الشهر السابق التابعة لنفس الأسبوع كانت تُفقد بالكامل — لا
            // تُحسب بالشهر السابق ولا بهذا الشهر
            $dayNames=['sunday','monday','tuesday','wednesday','thursday','friday','saturday'];
            $offIdx=null;
            foreach($dayNames as $idx=>$dn){
                $f=$emp[$dn.'_from']??null;
                if($f===null||$f===''){$offIdx=$idx;break;}
            }
            $weekStartIdx=$offIdx!==null?($offIdx+1)%7:0; // الأحد افتراضياً لو ما له عطلة محددة أصلاً

            $cursor=new DateTime($monthFrom);
            while((int)$cursor->format('w')!==$weekStartIdx){
                $cursor->modify('-1 day');
            }
            $end=new DateTime($monthTo);
            $w=1;
            while($cursor<=$end&&$w<=6){
                $wS=clone $cursor;$wE=clone $cursor;$wE->modify('+6 days');
                $fromKey=$wS->format('Y-m-d');
                $ex=$existing[$fromKey]??null;
                $periods[]=['week_num'=>$w,
                    'label'=>"الأسبوع {$w} ({$wS->format('m/d')}→{$wE->format('m/d')})",
                    'from'=>$fromKey,'to'=>$wE->format('Y-m-d'),'month'=>$monthFrom,
                    'status'=>$ex?$ex['payment_status']:'pending',
                    'net'=>$ex?(float)$ex['net_salary']:null,'id'=>$ex?$ex['id']:null];
                $cursor->modify('+7 days');$w++;
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
        // عملة الفرع الأساسية — جوين حي عبر base_currency النصي المضمون
        // (branches.base_currency_id موجود بس nullable وغير مضمون التعبئة
        // لكل الفروع — راجع DESCRIBE branches الفعلي). لو حتى هيك ما لقى
        // تطابق (بيانات فرع تالفة فعلاً)، احتياط ديناميكي حقيقي is_base=1،
        // لا معرّف ثابت مفترض
        $brSt=$pdo->prepare("SELECT c.id FROM branches b
            JOIN currencies c ON c.code = b.base_currency
            WHERE b.table_suffix=? LIMIT 1");
        $brSt->execute([$TS]);
        $branchBaseCurrId=(int)($brSt->fetchColumn() ?: 0);
        if(!$branchBaseCurrId){
            $branchBaseCurrId=(int)($pdo->query("SELECT id FROM currencies WHERE is_base=1 LIMIT 1")->fetchColumn() ?: 0);
        }
        $st=$pdo->prepare("SELECT * FROM `{$TE}` WHERE id=?");
        $st->execute([$empId]);
        $emp=$st->fetch(PDO::FETCH_ASSOC);
        if(!$emp)throw new Exception("موظف {$empId} غير موجود");

        // الراتب الأساسي — اسم الحقل الصح من DB
        $base  = (float)$emp['basic_salary'];
        $curId = (int)($emp['currency_id'] ?? $branchBaseCurrId);
        $cur   = $currMap[$curId] ?? 'USD';
        $curSym= $symMap[$cur] ?? '$';
        $otMult= (float)($emp['overtime_multiplier'] ?? 1.5);
        $needsRate = ($curId !== $branchBaseCurrId);

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
        $hrRate=$weeklySchedHours>0?round($base/$weeklySchedHours,4):0; // افتراضي (أسبوعي)

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

        // العطل — الاستحقاق الأساسي (holAmount) بيصير لكل أيام العطل
        // الرسمية بالفترة، بغض النظر اشتغل الموظف فيها ولا لأ (حقه
        // الأساسي بيوم إجازة مدفوعة). لو اشتغل فعلياً، ساعاته/يومه هلق
        // تُحسب كإضافي (بمعامل overtime_multiplier الخاص فيه) بدل الأجر
        // العادي — تعويضين منفصلين، مو واحد بدل التاني
        $holSt=$pdo->prepare("SELECT COUNT(*) FROM `{$TH}` WHERE holiday_date BETWEEN ? AND ?");
        $holSt->execute([$dateFrom,$dateTo]);
        $holDays=(int)$holSt->fetchColumn();

        // ساعات/أيام الحضور الفعلية المسجَّلة تحديداً بأيام عطلة رسمية —
        // هاي لازم تُطرح من المجموع العادي (totalHours/workDays) قبل ما
        // يدخل بحساب $earned، لأنها هلق رح تُحسب لحالها بمعامل الإضافي
        $holWorkSt=$pdo->prepare("SELECT
            COALESCE(SUM(a.hours_worked),0) AS hol_hours,
            COUNT(*) AS hol_days
            FROM `{$TAT}` a JOIN `{$TH}` h ON h.holiday_date=a.attendance_date
            WHERE a.employee_id=? AND a.attendance_date BETWEEN ? AND ? AND a.hours_worked>0");
        $holWorkSt->execute([$empId,$dateFrom,$dateTo]);
        $holWork=$holWorkSt->fetch(PDO::FETCH_ASSOC);
        $holWorkedHours=(float)($holWork['hol_hours']??0);
        $holWorkedDays =(int)($holWork['hol_days']??0);

        // طرح مبدئي من المجاميع العادية (قبل الـswitch) — لأنواع الحساب
        // بالساعة (شهري/أسبوعي/ساعي). "يومي" بيتم طرحه لاحقاً بعد الـswitch
        // مباشرة لأنه معتمد على عدد الأيام مش الساعات
        $totalHours=max(0,$totalHours-$holWorkedHours);

        // الحساب — حسب نوع الراتب الفعلي، الأربعة أنواع منفصلين عن بعض
        // (كانت "يومي"/"ساعي" بتُعاملان زي "أسبوعي" بالغلط: القسمة على
        // ساعات الأسبوع المجدولة بدل معاملة basic_salary كأجر يوم/ساعة
        // مباشر — تصحيح جذري، مو تعديل تجميلي)
        switch($emp['salary_type']){
            case 'monthly':
                // أجر الساعة للموظف الشهري ديناميكي حسب الشهر الفعلي —
                // مو افتراض ثابت "٢٢ يوم × ٨ ساعات". نمر يوم يوم على
                // كامل الفترة (عادة = الشهر التقويمي كامل للموظف الشهري)،
                // ونجمع ساعات الدوام المجدولة فعلياً لكل يوم مو عطلة
                // أسبوعية له — فيختلف المجموع تلقائياً حسب عدد أيام الشهر
                // (٢٨-٣١) وحسب يوم بداية الشهر (بيغيّر كم مرة تقع عطلته
                // الأسبوعية بنفس الشهر)
                $totalSchedHours=0;
                $dayNames=['sunday','monday','tuesday','wednesday','thursday','friday','saturday'];
                $period=new DatePeriod(new DateTime($dateFrom), new DateInterval('P1D'), (new DateTime($dateTo))->modify('+1 day'));
                foreach($period as $d){
                    $dn=$dayNames[(int)$d->format('w')];
                    $f=$emp[$dn.'_from'];$t=$emp[$dn.'_to'];
                    if($f!==null&&$f!==''&&$t!==null&&$t!==''){
                        $diff=(int)$t-(int)$f;
                        if($diff>0)$totalSchedHours+=$diff;
                    }
                }
                $hrRate=$totalSchedHours>0?round($base/$totalSchedHours,4):0;
                $earned=$hrRate*$totalHours;
                $regularHours=$totalHours;
                break;
            case 'daily':
                // basic_salary = أجر اليوم الواحد مباشرة، لا يُقسَم على
                // ساعات الأسبوع. hrRate هون بس مكافئ نظري (÷٨ ساعات)
                // يُستخدم لحساب الإضافي فقط، مو الأجر الأساسي نفسه.
                // أيام العطلة المشتغلة تُطرح من workDays هون تحديداً
                // (معتمد على عدد الأيام مش الساعات، بعكس باقي الأنواع)
                $workDays=max(0,$workDays-$holWorkedDays);
                $hrRate=round($base/8,4);
                $earned=$base*$workDays;
                $regularHours=$workDays*8;
                break;
            case 'hourly':
                // basic_salary = أجر الساعة مباشرة، بدون أي قسمة إطلاقاً
                $hrRate=$base;
                $earned=$hrRate*$totalHours;
                $regularHours=$totalHours;
                break;
            case 'weekly':
            default:
                // basic_salary إجمالي الأسبوع ÷ ساعاته المجدولة = أجر الساعة
                // الفعلي، والأجر المكتسب = أجر الساعة × الساعات الفعلية بالحضور
                $earned=$hrRate*$totalHours;
                $regularHours=$totalHours;
                break;
        }
        $holAmount=$holDays*$hrRate*8;
        $otAmt    =$otHours*$hrRate*$otMult;
        // إضافي العمل بيوم عطلة رسمية — بنفس معامل إضافي الموظف نفسه
        // (overtime_multiplier)، بمعادل ساعات مبني على $hrRate النهائي
        // لهذا النوع راتب (بعد الـswitch فوق). "يومي" مبني على أيام
        // (هرRate هون أصلاً = base÷8، فالمعادلة بتُرجع نفس اليوم × ٨ ساعات
        // بشكل صحيح)، الباقي مبني على الساعات الفعلية المسجَّلة
        $holOvertimeHoursEquiv=($emp['salary_type']==='daily')?($holWorkedDays*8):$holWorkedHours;
        $holOvertimeAmt=$holOvertimeHoursEquiv*$hrRate*$otMult;

        // مكافآت وسلف
        $bSt=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM `{$TB}` WHERE employee_id=? AND bonus_date BETWEEN ? AND ?");
        $bSt->execute([$empId,$dateFrom,$dateTo]);
        $bonus=(float)$bSt->fetchColumn();
        $lSt=$pdo->prepare("SELECT COALESCE(SUM(monthly_deduction),0) FROM `{$TL}` WHERE employee_id=? AND status='active'");
        $lSt->execute([$empId]);
        $loan=(float)$lSt->fetchColumn();
        // إجمالي المتبقي فعلياً على كل السلف النشطة سوا — الحد الأقصى
        // المسموح خصمه هالمرة (مو مجموع الأقساط الشهرية بس، ممكن الموظف
        // يسدد أكتر من قسط بمرة وحدة لو حاب)
        $loSt=$pdo->prepare("SELECT COALESCE(SUM(amount-paid_amount),0) FROM `{$TL}` WHERE employee_id=? AND status='active'");
        $loSt->execute([$empId]);
        $loanOutstanding=(float)$loSt->fetchColumn();
        $net=$earned+$otAmt+$holAmount+$holOvertimeAmt+$bonus-$loan;

        $out=ob_get_clean();
        if($out){echo json_encode(['ok'=>false,'msg'=>'PHP:'.$out]);exit;}
        echo json_encode([
            'ok'=>true,
            // JS fields — regular_hours/earned_salary هون مدموج فيهم
            // استحقاق العطلة الأساسي (holAmount) لغرض عرض الواجهة فقط
            // (معادلة "الساعات × السعر = earned_salary − holiday_amount"
            // لازم تطلع = $earned الصافي). الحقل basic_salary تحت (قسم
            // "pay fields") يضل نقي بدون دمج — هو يلي فعلياً بيدخل حساب
            // accrue، ودمج holAmount فيه بيسبب ازدواج (holAmount مضاف
            // هناك كمكوّن منفصل أصلاً)
            'hr_rate'        =>$hrRate,
            'regular_hours'  =>$regularHours,
            'earned_salary'  =>round($earned+$holAmount,2),
            'holiday_days'   =>$holDays,
            'holiday_amount' =>round($holAmount,2),
            'holiday_ot_hrs' =>$holOvertimeHoursEquiv,
            'holiday_ot_amt' =>round($holOvertimeAmt,2),
            'ot_hours'       =>$otHours,
            'ot_mult'        =>$otMult,
            'ot_amount'      =>round($otAmt,2),
            'bonus'          =>round($bonus,2),
            'loan_ded'       =>round($loan,2),
            'loan_outstanding_total' =>round($loanOutstanding,2),
            'net'            =>round($net,2),
            'currency'         =>$curSym,
            'emp_cur_code'     =>$cur,
            'needs_rate_input' =>$needsRate,
            'emp_cur_id'       =>$curId,
            'branch_cur_id'    =>$branchBaseCurrId,
            // pay fields — basic_salary نقي (بدون holiday) عمداً، يُستخدم
            // مباشرة بحساب gross بدالة accrue حيث holAmount مضاف لحاله
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

    // ── get_currency_rate: يقرأ سعر الصرف من جدول العملات المسجّل بقاعدة
    // البيانات — بديل استدعاء exchangerate-api.com الخارجي يلي انشال.
    // القيمة المعروضة هون للعرض/التعبئة بس؛ الباك-إند بـaccrue/pay بيقرأ
    // نفس المصدر مباشرة عند الحساب الفعلي، ما بيثق بأي رقم راجع من هون
    if($act==='get_currency_rate'){
        $curId=(int)($_POST['currency_id']??0);
        $st=$pdo->prepare("SELECT code,symbol,exchange_rate FROM currencies WHERE id=? LIMIT 1");
        $st->execute([$curId]);
        $row=$st->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new Exception('عملة غير موجودة');
        echo json_encode(['ok'=>true,'exchange_rate'=>(float)$row['exchange_rate'],'code'=>$row['code'],'symbol'=>$row['symbol']]);
        exit;
    }

    // ── accrue: اعتماد راتب الفترة كالتزام محاسبي (استحقاق)، منفصل تماماً
    // عن الدفع الفعلي. فعل صريح دايماً (زر "اعتماد")، ما في أتمتة بتاريخ
    // تقويمي — القرار المحاسبي مرتبط باعتماد المسير، لا بيوم بالشهر.
    // القيد:
    //   مدين مصروف رواتب (gross)
    //       دائن سلف الموظف [حسابه الفرعي]        (loan_deduction، إن وُجد)
    //       دائن مستحقات الموظف [حسابه الفرعي]     (net)
    if($act==='accrue'){
        $empId   =(int)($_POST['employee_id']??0);
        $month   =$_POST['payroll_month']??'';
        $weekNum =(int)($_POST['week_number']??0);
        $dateFrom=$_POST['period_from']??'';
        $dateTo  =$_POST['period_to']??'';
        $cd=json_decode($_POST['calc_data']??'{}',true)?:[];
        $st=$pdo->prepare("SELECT * FROM `{$TE}` WHERE id=?");
        $st->execute([$empId]);$emp=$st->fetch(PDO::FETCH_ASSOC);
        if(!$emp)throw new Exception('موظف غير موجود');
        if(!$emp['payable_account_id']||!$emp['loan_account_id'])
            throw new Exception('هذا الموظف بدون حسابات محاسبية فرعية (مستحقات/سلف) — أضِف موظف جديد بنفس بياناته من صفحة الموظفين، أو اضبط الحسابات يدوياً');

        $brSt3=$pdo->prepare("SELECT c.id FROM branches b JOIN currencies c ON c.code=b.base_currency WHERE b.table_suffix=? LIMIT 1");
        $brSt3->execute([$TS]);
        $branchBaseCurrId3=(int)($brSt3->fetchColumn() ?: 0);
        if(!$branchBaseCurrId3){
            $branchBaseCurrId3=(int)($pdo->query("SELECT id FROM currencies WHERE is_base=1 LIMIT 1")->fetchColumn() ?: 0);
        }
        $curId=(int)($emp['currency_id'] ?? $branchBaseCurrId3);

        $basic=(float)($cd['basic_salary']??0);
        $ota  =(float)($cd['overtime_amount']??0);
        $hol  =(float)($cd['holiday_amount']??0);
        $holOt=(float)($cd['holiday_ot_amt']??0);
        $bon  =(float)($cd['bonus_total']??0);
        $lnd  =(float)($cd['loan_deduction']??0);
        $wd   =(float)($cd['working_days']??0);
        $oth  =(float)($cd['overtime_hours']??0);
        // gross محسوب من مكوّناته مباشرة، وnet = gross - loan_deduction —
        // نحسبه هيك ما نثق برقم net منفصل قد يختلف تقريب عشري بسيط ويكسر توازن القيد
        $gross=round($basic+$ota+$hol+$holOt+$bon,2);
        $net=round($gross-$lnd,2);
        if($gross<=0)throw new Exception('لا يوجد مبلغ مستحق لهذه الفترة');

        // تحقق أمان: مبلغ الخصم المُدخَل (قد يكون جزئي/مخصَّص من الواجهة)
        // ما يقدر يتجاوز إجمالي المتبقي فعلياً على كل السلف النشطة سوا —
        // لا نثق بحد أقصى مفروض بالواجهة بس، نتحقق بالسيرفر أيضاً
        if($lnd>0){
            $totOutSt=$pdo->prepare("SELECT COALESCE(SUM(amount-paid_amount),0) FROM `{$TL}` WHERE employee_id=? AND status='active'");
            $totOutSt->execute([$empId]);
            $totOutstanding=(float)$totOutSt->fetchColumn();
            if($lnd>$totOutstanding+0.01)
                throw new Exception("مبلغ الخصم ({$lnd}) أكبر من إجمالي المتبقي على السلف النشطة ({$totOutstanding})");
        }

        $chk=$pdo->prepare("SELECT id,payment_status FROM `{$TP}` WHERE employee_id=? AND period_from=?");
        $chk->execute([$empId,$dateFrom]);$ex=$chk->fetch(PDO::FETCH_ASSOC);
        if($ex&&in_array($ex['payment_status'],['accrued','paid']))throw new Exception('هذه الفترة معتمدة مسبقاً');

        $pdo->beginTransaction();
        try{
            $exchangeRate=payrollSafeRate($pdo,$curId,$branchBaseCurrId3);
            $grossBase=round($gross/$exchangeRate,4);
            $lndBase=round($lnd/$exchangeRate,4);
            $netBase=round($grossBase-$lndBase,4); // فرق بالطرح يضمن توازن القيد تماماً

            $aS=$pdo->query("SELECT ac.* FROM `{$TIAS}` i JOIN `{$TAC}` ac ON ac.id=i.account_id WHERE i.setting_key='salary_expense' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if(!$aS)throw new Exception('اضبط حساب "مصاريف الرواتب والأجور" أولاً من صفحة إعدادات الربط المحاسبي');

            $y=date('Y');
            $last=$pdo->query("SELECT entry_number FROM `{$TJE}` WHERE entry_number LIKE 'JE-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
            $seq=$last?(int)substr($last,-4)+1:1;
            $jeNo='JE-'.$y.'-'.str_pad($seq,4,'0',STR_PAD_LEFT);
            $en=$emp['full_name']??'موظف';
            $branchCurCode=$currMap[$branchBaseCurrId3]??'USD';

            $pdo->prepare("INSERT INTO `{$TJE}` (entry_number,entry_date,description,currency_id,exchange_rate,total_debit,total_credit,status,reference_type,reference_id,created_by) VALUES(?,?,?,?,?,?,?,'posted','payroll_accrual',?,?)")
                ->execute([$jeNo,date('Y-m-d'),"استحقاق راتب {$en} {$month}",$branchBaseCurrId3,$exchangeRate,$grossBase,$grossBase,0,$_SESSION['user_id']]);
            $jeId=(int)$pdo->lastInsertId();

            // مدين: مصروف الرواتب (gross)
            $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate) VALUES(?,?,?,0,?,?,?,?,1)")
                ->execute([$jeId,$aS['id'],$grossBase,$grossBase,$grossBase,"استحقاق راتب {$en}",$branchBaseCurrId3]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                ->execute([$grossBase,$grossBase,$aS['id']]);

            // دائن: تصفية جزئية لسلف الموظف (لو في خصم سلفة بهالفترة)
            if($lndBase>0){
                $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate) VALUES(?,?,0,?,?,?,?,?,1)")
                    ->execute([$jeId,$emp['loan_account_id'],$lndBase,$lndBase,$lndBase,"خصم سلفة من راتب {$en}",$branchBaseCurrId3]);
                $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                    ->execute([$lndBase,$lndBase,$emp['loan_account_id']]);
            }

            // دائن: مستحقات الموظف (net)
            $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate) VALUES(?,?,0,?,?,?,?,?,1)")
                ->execute([$jeId,$emp['payable_account_id'],$netBase,$netBase,$netBase,"استحقاق راتب {$en}",$branchBaseCurrId3]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                ->execute([$netBase,$netBase,$emp['payable_account_id']]);

            if($ex){
                $pdo->prepare("UPDATE `{$TP}` SET basic_salary=?,working_days=?,working_hours=?,overtime_hours=?,
                    overtime_amount=?,bonus_total=?,loan_deduction=?,net_salary=?,currency_id=?,
                    payment_status='accrued',accrual_entry_id=? WHERE id=?")
                    ->execute([$basic,$wd,$wd*8,$oth,$ota,$bon,$lnd,$net,$curId,$jeId,$ex['id']]);
                $payId=$ex['id'];
            }else{
                $pdo->prepare("INSERT INTO `{$TP}` (employee_id,payroll_month,week_number,period_from,period_to,
                    basic_salary,working_days,working_hours,overtime_hours,overtime_amount,bonus_total,
                    loan_deduction,net_salary,currency_id,payment_status,accrual_entry_id,created_by)
                    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,'accrued',?,?)")
                    ->execute([$empId,$month,$weekNum,$dateFrom,$dateTo,$basic,$wd,$wd*8,$oth,$ota,$bon,$lnd,$net,$curId,$jeId,$_SESSION['user_id']]);
                $payId=(int)$pdo->lastInsertId();
            }
            $pdo->prepare("UPDATE `{$TJE}` SET reference_id=? WHERE id=?")->execute([$payId,$jeId]);

            // توزيع مبلغ الخصم (قد يكون جزئي/مخصَّص) على السلف النشطة —
            // الأقدم أول (FIFO). بيحدّث paid_amount الفعلي لكل سلفة، وبيوسمها
            // "مسدَّدة" لما تكتمل، بدل الاعتماد على عدّاد أقساط ثابت.
            // كل توزيع بيتسجّل بجدول hr_payroll_loan_allocations — عشان
            // "إلغاء الاعتماد" لاحقاً (لو صار) يقدر يرجع بالضبط لنفس
            // الأرقام، بغض النظر شو صار بالسلف بعدها
            if($lnd>0){
                $remaining=$lnd;
                $ls=$pdo->prepare("SELECT * FROM `{$TL}` WHERE employee_id=? AND status='active' ORDER BY loan_date ASC");
                $ls->execute([$empId]);
                foreach($ls->fetchAll(PDO::FETCH_ASSOC) as $ln){
                    if($remaining<=0.001)break;
                    $outstanding=round($ln['amount']-$ln['paid_amount'],2);
                    if($outstanding<=0)continue;
                    $apply=min($remaining,$outstanding);
                    $newPaid=round($ln['paid_amount']+$apply,2);
                    $newInst=$ln['monthly_deduction']>0
                        ? min($ln['installments'],(int)floor($newPaid/$ln['monthly_deduction']))
                        : $ln['paid_installments'];
                    $newStatus=($newPaid>=$ln['amount']-0.01)?'completed':'active';
                    $pdo->prepare("UPDATE `{$TL}` SET paid_amount=?,paid_installments=?,status=? WHERE id=?")
                        ->execute([$newPaid,$newInst,$newStatus,$ln['id']]);
                    $pdo->prepare("INSERT INTO `{$TLA}` (payroll_id,loan_id,amount) VALUES(?,?,?)")
                        ->execute([$payId,$ln['id'],$apply]);
                    $remaining=round($remaining-$apply,2);
                }
            }
            $pdo->commit();
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
        $out=ob_get_clean();
        if($out)echo json_encode(['ok'=>false,'msg'=>'PHP:'.$out]);
        else echo json_encode(['ok'=>true,'msg'=>'تم اعتماد الراتب كمستحق ✅','id'=>$payId]);
        exit;
    }

    // ── pay ──
    if($act==='pay'){
        $empId   =(int)($_POST['employee_id']??0);
        // عملة الفرع الأساسية
        $brSt2=$pdo->prepare("SELECT c.id FROM branches b
            JOIN currencies c ON c.code = b.base_currency
            WHERE b.table_suffix=? LIMIT 1");
        $brSt2->execute([$TS]);
        $branchBaseCurrId2=(int)($brSt2->fetchColumn() ?: 0);
        if(!$branchBaseCurrId2){
            $branchBaseCurrId2=(int)($pdo->query("SELECT id FROM currencies WHERE is_base=1 LIMIT 1")->fetchColumn() ?: 0);
        }
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
        $curId=(int)($emp['currency_id'] ?? $branchBaseCurrId2);
        $cashAccId=(int)($_POST['cash_account_id']??0)?:null;

        $chk=$pdo->prepare("SELECT * FROM `{$TP}` WHERE employee_id=? AND period_from=?");
        $chk->execute([$empId,$dateFrom]);$ex=$chk->fetch(PDO::FETCH_ASSOC);
        if($ex&&$ex['payment_status']==='paid')throw new Exception('مصروف مسبقاً');

        $aC = null;
        if($cashAccId){
            $aCst=$pdo->prepare("SELECT * FROM `{$TAC}` WHERE id=?");
            $aCst->execute([$cashAccId]);$aC=$aCst->fetch(PDO::FETCH_ASSOC);
        } else {
            $aC=$pdo->query("SELECT ac.* FROM `{$TIAS}` i JOIN `{$TAC}` ac ON ac.id=i.account_id WHERE i.setting_key='cash_usd' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        }
        if(!$aC)throw new Exception('اختر صندوق الدفع أو اضبط الصندوق الافتراضي بإعدادات الربط المحاسبي');

        $pdo->beginTransaction();
        try{
            $y=date('Y');
            $genJeNo=function() use ($pdo,$TJE,$y){
                $last=$pdo->query("SELECT entry_number FROM `{$TJE}` WHERE entry_number LIKE 'JE-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
                $seq=$last?(int)substr($last,-4)+1:1;
                return 'JE-'.$y.'-'.str_pad($seq,4,'0',STR_PAD_LEFT);
            };
            $en=$emp['full_name']??'موظف';
            $cashCurId=(int)($aC['currency_id']??$branchBaseCurrId2);
            $cashCur=$currMap[$cashCurId]??'USD';
            $branchCurCode=$currMap[$branchBaseCurrId2]??'USD';

            if($ex && $ex['payment_status']==='accrued'){
                // ═══ الموظف مستحقة رواتبه مسبقاً — قيد تصفية بس، ما نعيد
                // تسجيل المصروف. نقرأ المبلغ بالضبط من سطر "مستحقات الموظف"
                // بقيد الاستحقاق نفسه — لضمان تطابق كامل بغض النظر عن أي
                // تغيّر بسعر الصرف بين تاريخ الاستحقاق وتاريخ الدفع ═══
                if(!$emp['payable_account_id'])throw new Exception('هذا الموظف بدون حساب "مستحقات" مرتبط — راجع بيانات الموظف');
                $lineSt=$pdo->prepare("SELECT * FROM `{$TJI}` WHERE journal_entry_id=? AND account_id=? LIMIT 1");
                $lineSt->execute([$ex['accrual_entry_id'],$emp['payable_account_id']]);
                $accLine=$lineSt->fetch(PDO::FETCH_ASSOC);
                if(!$accLine)throw new Exception('تعذر إيجاد قيد الاستحقاق الأصلي لهذا الموظف');
                $netBase=(float)$accLine['base_amount'];
                $netOrig=(float)$ex['net_salary'];

                $jeNo=$genJeNo();
                $pdo->prepare("INSERT INTO `{$TJE}` (entry_number,entry_date,description,currency_id,exchange_rate,total_debit,total_credit,status,reference_type,reference_id,created_by) VALUES(?,?,?,?,1,?,?,'posted','payroll_pay',?,?)")
                    ->execute([$jeNo,date('Y-m-d'),"صرف راتب {$en} {$month}",$branchBaseCurrId2,$netBase,$netBase,$ex['id'],$_SESSION['user_id']]);
                $jeId=(int)$pdo->lastInsertId();

                // مدين: تصفية مستحقات الموظف (حسابه الفرعي)
                $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate) VALUES(?,?,?,0,?,?,?,?,1)")
                    ->execute([$jeId,$emp['payable_account_id'],$netBase,$netBase,$netBase,"صرف راتب {$en}",$branchBaseCurrId2]);
                $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                    ->execute([$netBase,$netBase,$emp['payable_account_id']]);

                // دائن: الصندوق
                $exchangeRate=payrollSafeRate($pdo,$cashCurId,$branchBaseCurrId2);
                $cashOrig=round($netBase*$exchangeRate,4);
                $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate) VALUES(?,?,0,?,?,?,?,?,?)")
                    ->execute([$jeId,$aC['id'],$netBase,$cashOrig,$netBase,"صرف راتب {$en}",$cashCurId,$exchangeRate]);
                $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                    ->execute([$netBase,$cashOrig,$aC['id']]);

                $pdo->prepare("UPDATE `{$TP}` SET payment_status='paid',payment_date=NOW(),payment_method=?,cash_account_id=?,notes=?,payment_entry_id=? WHERE id=?")
                    ->execute([$method,$cashAccId,$notes,$jeId,$ex['id']]);
                $payId=$ex['id'];

            } else {
                // ═══ Fallback القديم (موظف بدون خطوة استحقاق — توافقية فقط):
                // قيد مباشر مصروف→صندوق بخطوة وحدة، بدون مرور بمستحقات الموظفين ═══
                $net  =(float)($cd['net_salary']??0);
                $basic=(float)($cd['basic_salary']??0);
                $wd   =(float)($cd['working_days']??0);
                $oth  =(float)($cd['overtime_hours']??0);
                $ota  =(float)($cd['overtime_amount']??0);
                $bon  =(float)($cd['bonus_total']??0);
                $lnd  =(float)($cd['loan_deduction']??0);

                // نفس تحقق الأمان المطبَّق بـ accrue — مبلغ الخصم ما يقدر
                // يتجاوز إجمالي المتبقي فعلياً على السلف النشطة
                if($lnd>0){
                    $totOutSt=$pdo->prepare("SELECT COALESCE(SUM(amount-paid_amount),0) FROM `{$TL}` WHERE employee_id=? AND status='active'");
                    $totOutSt->execute([$empId]);
                    $totOutstanding=(float)$totOutSt->fetchColumn();
                    if($lnd>$totOutstanding+0.01)
                        throw new Exception("مبلغ الخصم ({$lnd}) أكبر من إجمالي المتبقي على السلف النشطة ({$totOutstanding})");
                }

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
                    $remaining=$lnd;
                    $ls=$pdo->prepare("SELECT * FROM `{$TL}` WHERE employee_id=? AND status='active' ORDER BY loan_date ASC");
                    $ls->execute([$empId]);
                    foreach($ls->fetchAll(PDO::FETCH_ASSOC) as $ln){
                        if($remaining<=0.001)break;
                        $outstanding=round($ln['amount']-$ln['paid_amount'],2);
                        if($outstanding<=0)continue;
                        $apply=min($remaining,$outstanding);
                        $newPaid=round($ln['paid_amount']+$apply,2);
                        $newInst=$ln['monthly_deduction']>0
                            ? min($ln['installments'],(int)floor($newPaid/$ln['monthly_deduction']))
                            : $ln['paid_installments'];
                        $newStatus=($newPaid>=$ln['amount']-0.01)?'completed':'active';
                        $pdo->prepare("UPDATE `{$TL}` SET paid_amount=?,paid_installments=?,status=? WHERE id=?")
                            ->execute([$newPaid,$newInst,$newStatus,$ln['id']]);
                        $pdo->prepare("INSERT INTO `{$TLA}` (payroll_id,loan_id,amount) VALUES(?,?,?)")
                            ->execute([$payId,$ln['id'],$apply]);
                        $remaining=round($remaining-$apply,2);
                    }
                }
                $netOrig=$net;
                $exchangeRate=payrollSafeRate($pdo,$curId,$branchBaseCurrId2);
                $netBase=round($netOrig/$exchangeRate,4);
                $aS=$pdo->query("SELECT ac.* FROM `{$TIAS}` i JOIN `{$TAC}` ac ON ac.id=i.account_id WHERE i.setting_key='salary_expense' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if($aS){
                    $jeNo=$genJeNo();
                    $pdo->prepare("INSERT INTO `{$TJE}` (entry_number,entry_date,description,currency_id,exchange_rate,total_debit,total_credit,status,reference_type,reference_id,created_by) VALUES(?,?,?,?,?,?,?,'posted','payroll',?,?)")
                        ->execute([$jeNo,date('Y-m-d'),"راتب {$en} {$month}",$branchBaseCurrId2,$exchangeRate,$netBase,$netBase,$payId,$_SESSION['user_id']]);
                    $jeId=(int)$pdo->lastInsertId();

                    $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate) VALUES(?,?,?,0,?,?,?,?,1)")
                        ->execute([$jeId,$aS['id'],$netBase,$netBase,$netBase,"راتب {$en}",$branchBaseCurrId2]);
                    $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                        ->execute([$netBase,$netBase,$aS['id']]);

                    $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate) VALUES(?,?,0,?,?,?,?,?,?)")
                        ->execute([$jeId,$aC['id'],$netBase,$netOrig,$netBase,"دفع راتب {$en}",$cashCurId,$exchangeRate]);
                    $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                        ->execute([$netBase,$netOrig,$aC['id']]);

                    $pdo->prepare("UPDATE `{$TP}` SET payment_entry_id=? WHERE id=?")->execute([$jeId,$payId]);
                }
            }
            $pdo->commit();
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
        $out=ob_get_clean();
        if($out)echo json_encode(['ok'=>false,'msg'=>'PHP:'.$out]);
        else echo json_encode(['ok'=>true,'msg'=>'تم صرف الراتب ✅','id'=>$payId]);
        exit;
    }

    // ── cancel_payment: إلغاء صرف راتب (يرجع لحالة "معتمد") — قيد عكسي
    // منفصل يعكس فقط قيد التصفية (مدين/دائن الصندوق)، لا يلمس أي شي متعلق
    // بالسلف (السلف اتصفّت وقت الاعتماد، مو وقت الصرف). محمي بصلاحية
    // hr.payroll:confirm — نفس صلاحية اعتماد/صرف الراتب أصلاً، ما في
    // فحص صلاحيات تاني بهالملف حالياً فهاي أول مرة صراحة
    if($act==='cancel_payment'){
        // auth.php أصلاً محمَّل، والصلاحيات محمَّلة بالجلسة من تحميل الصفحة
        if(!can('hr.payroll','confirm'))throw new Exception('ما عندك صلاحية إلغاء صرف الرواتب');

        $payId=(int)($_POST['id']??0);
        $st=$pdo->prepare("SELECT * FROM `{$TP}` WHERE id=? AND payment_status='paid'");
        $st->execute([$payId]);$pr=$st->fetch(PDO::FETCH_ASSOC);
        if(!$pr)throw new Exception('سجل غير موجود أو غير مصروف');
        if(!$pr['payment_entry_id'])throw new Exception('لا يوجد قيد دفع مرتبط لعكسه');

        $empSt=$pdo->prepare("SELECT * FROM `{$TE}` WHERE id=?");
        $empSt->execute([$pr['employee_id']]);$emp=$empSt->fetch(PDO::FETCH_ASSOC);

        $pdo->beginTransaction();
        try{
            $lines=$pdo->prepare("SELECT * FROM `{$TJI}` WHERE journal_entry_id=?");
            $lines->execute([$pr['payment_entry_id']]);
            $items=$lines->fetchAll(PDO::FETCH_ASSOC);
            $y=date('Y');
            $last=$pdo->query("SELECT entry_number FROM `{$TJE}` WHERE entry_number LIKE 'JE-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
            $seq=$last?(int)substr($last,-4)+1:1;
            $jeNo='JE-'.$y.'-'.str_pad($seq,4,'0',STR_PAD_LEFT);
            $en=$emp['full_name']??'موظف';
            $totBase=array_sum(array_column($items,'base_amount'))/2; // القيد متوازن، النصف = المبلغ الفعلي

            $pdo->prepare("INSERT INTO `{$TJE}` (entry_number,entry_date,description,currency_id,exchange_rate,total_debit,total_credit,status,reference_type,reference_id,created_by) VALUES(?,?,?,?,1,?,?,'posted','payroll_pay_cancel',?,?)")
                ->execute([$jeNo,date('Y-m-d'),"إلغاء صرف راتب {$en}",$pr['currency_id'],$totBase,$totBase,$payId,$_SESSION['user_id']]);
            $jeId=(int)$pdo->lastInsertId();

            // عكس كل سطر بالضبط (مدين↔دائن)، بنفس المبالغ الأصلية تماماً
            foreach($items as $it){
                $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate) VALUES(?,?,?,?,?,?,?,?,?)")
                    ->execute([$jeId,$it['account_id'],$it['credit'],$it['debit'],$it['original_amount'],$it['base_amount'],"إلغاء صرف راتب {$en}",$it['currency_id'],$it['exchange_rate']]);
                $delta=$it['debit']-$it['credit']; // كان زاد الحساب بهالمقدار، هلق لازم ينعكس
                $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                    ->execute([$delta,($it['original_amount']*($it['debit']>0?1:-1)),$it['account_id']]);
            }

            $pdo->prepare("UPDATE `{$TP}` SET payment_status='accrued',cancel_payment_entry_id=? WHERE id=?")
                ->execute([$jeId,$payId]);
            $pdo->commit();
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
        echo json_encode(['ok'=>true,'msg'=>'تم إلغاء الصرف — الفترة رجعت لحالة معتمد']);exit;
    }

    // ── cancel_accrual: إلغاء اعتماد راتب (يرجع لحالة "معلَّق"، جاهز
    // لإعادة الاعتماد من جديد). قيد عكسي منفصل يعكس مصروف الرواتب وأي
    // سلف اتخصمت، بالاعتماد على جدول hr_payroll_loan_allocations (دقيق
    // ١٠٠٪ حتى لو صار عندك أكتر من سلفة أو تغيّرت حالتها بعدين) — لا
    // نعيد حساب أو نخمّن التوزيع العكسي إطلاقاً
    if($act==='cancel_accrual'){
        // auth.php أصلاً محمَّل، والصلاحيات محمَّلة بالجلسة من تحميل الصفحة
        if(!can('hr.payroll','confirm'))throw new Exception('ما عندك صلاحية إلغاء اعتماد الرواتب');

        $payId=(int)($_POST['id']??0);
        $st=$pdo->prepare("SELECT * FROM `{$TP}` WHERE id=? AND payment_status='accrued'");
        $st->execute([$payId]);$pr=$st->fetch(PDO::FETCH_ASSOC);
        if(!$pr)throw new Exception('سجل غير موجود أو غير معتمد (لازم تلغي الصرف أولاً لو مصروف)');
        if(!$pr['accrual_entry_id'])throw new Exception('لا يوجد قيد استحقاق مرتبط لعكسه');

        $empSt=$pdo->prepare("SELECT * FROM `{$TE}` WHERE id=?");
        $empSt->execute([$pr['employee_id']]);$emp=$empSt->fetch(PDO::FETCH_ASSOC);

        $pdo->beginTransaction();
        try{
            // ١) عكس قيد الاستحقاق بالكامل (كل الأسطر، بنفس المبالغ)
            $lines=$pdo->prepare("SELECT * FROM `{$TJI}` WHERE journal_entry_id=?");
            $lines->execute([$pr['accrual_entry_id']]);
            $items=$lines->fetchAll(PDO::FETCH_ASSOC);
            $y=date('Y');
            $last=$pdo->query("SELECT entry_number FROM `{$TJE}` WHERE entry_number LIKE 'JE-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
            $seq=$last?(int)substr($last,-4)+1:1;
            $jeNo='JE-'.$y.'-'.str_pad($seq,4,'0',STR_PAD_LEFT);
            $en=$emp['full_name']??'موظف';
            $totBase=(float)$pr['basic_salary']; // تقريبي للعرض فقط، القيد نفسه بيتوازن من أسطره

            $grossLine=null;
            foreach($items as $it){ if($it['debit']>0){$grossLine=$it;break;} }
            $grossBase=$grossLine?(float)$grossLine['base_amount']:0;

            $pdo->prepare("INSERT INTO `{$TJE}` (entry_number,entry_date,description,currency_id,exchange_rate,total_debit,total_credit,status,reference_type,reference_id,created_by) VALUES(?,?,?,?,1,?,?,'posted','payroll_accrual_cancel',?,?)")
                ->execute([$jeNo,date('Y-m-d'),"إلغاء اعتماد راتب {$en}",$pr['currency_id'],$grossBase,$grossBase,$payId,$_SESSION['user_id']]);
            $jeId=(int)$pdo->lastInsertId();

            foreach($items as $it){
                $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate) VALUES(?,?,?,?,?,?,?,?,?)")
                    ->execute([$jeId,$it['account_id'],$it['credit'],$it['debit'],$it['original_amount'],$it['base_amount'],"إلغاء اعتماد راتب {$en}",$it['currency_id'],$it['exchange_rate']]);
                $delta=$it['debit']-$it['credit'];
                $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                    ->execute([$delta,$delta,$it['account_id']]);
            }

            // ٢) عكس توزيع السلف بالضبط — من جدول التتبّع، لا إعادة حساب
            $allocSt=$pdo->prepare("SELECT * FROM `{$TLA}` WHERE payroll_id=? AND reversed=0");
            $allocSt->execute([$payId]);
            foreach($allocSt->fetchAll(PDO::FETCH_ASSOC) as $al){
                $lnSt=$pdo->prepare("SELECT * FROM `{$TL}` WHERE id=?");
                $lnSt->execute([$al['loan_id']]);$ln=$lnSt->fetch(PDO::FETCH_ASSOC);
                if(!$ln)continue;
                $newPaid=round($ln['paid_amount']-$al['amount'],2);
                if($newPaid<0)$newPaid=0;
                $newInst=$ln['monthly_deduction']>0
                    ? (int)floor($newPaid/$ln['monthly_deduction'])
                    : max(0,$ln['paid_installments']-1);
                $pdo->prepare("UPDATE `{$TL}` SET paid_amount=?,paid_installments=?,status='active' WHERE id=?")
                    ->execute([$newPaid,$newInst,$ln['id']]);
                $pdo->prepare("UPDATE `{$TLA}` SET reversed=1 WHERE id=?")->execute([$al['id']]);
            }

            // ٣) الفترة ترجع "معلَّقة" — جاهزة لإعادة الاعتماد من جديد
            //    (accrual_entry_id الأصلي يضل مسجَّل للتوثيق التاريخي)
            $pdo->prepare("UPDATE `{$TP}` SET payment_status='pending',cancel_accrual_entry_id=? WHERE id=?")
                ->execute([$jeId,$payId]);
            $pdo->commit();
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
        echo json_encode(['ok'=>true,'msg'=>'تم إلغاء الاعتماد — الفترة رجعت معلَّقة']);exit;
    }

    // ── add_loan: صرف سلفة — قيد فوري مدين سلف الموظف [حسابه الفرعي] / دائن الصندوق ──
    // ملاحظة: مسموح بأكتر من سلفة نشطة بنفس الوقت لنفس الموظف — كل سلفة
    // مستقلة بأقساطها، و`calculate` أصلاً بيجمع خصم كل السلف النشطة سوا
    // (SUM وليس سلفة وحدة)، فالمبلغ المالي بالراتب صحيح دايماً بغض النظر
    // عن عدد السلف النشطة
    if($act==='add_loan'){
        $empId=(int)($_POST['employee_id']??0);$amt=(float)($_POST['amount']??0);
        if($amt<=0)throw new Exception('المبلغ 0');

        $st=$pdo->prepare("SELECT * FROM `{$TE}` WHERE id=?");
        $st->execute([$empId]);$emp=$st->fetch(PDO::FETCH_ASSOC);
        if(!$emp)throw new Exception('موظف غير موجود');
        if(!$emp['loan_account_id'])
            throw new Exception('هذا الموظف بدون حساب "سلف" فرعي — أضِف موظف جديد بنفس بياناته من صفحة الموظفين');

        $brSt4=$pdo->prepare("SELECT c.id FROM branches b JOIN currencies c ON c.code=b.base_currency WHERE b.table_suffix=? LIMIT 1");
        $brSt4->execute([$TS]);
        $branchBaseCurrId4=(int)($brSt4->fetchColumn() ?: 0);
        if(!$branchBaseCurrId4){
            $branchBaseCurrId4=(int)($pdo->query("SELECT id FROM currencies WHERE is_base=1 LIMIT 1")->fetchColumn() ?: 0);
        }
        $curId=(int)($_POST['currency_id'] ?? $emp['currency_id'] ?? $branchBaseCurrId4);
        $installments=(int)($_POST['installments']??1);
        $cashAccId=(int)($_POST['cash_account_id']??0)?:null;
        if(!$cashAccId)throw new Exception('اختر الصندوق يلي بتصرف منه السلفة');
        $aCst=$pdo->prepare("SELECT * FROM `{$TAC}` WHERE id=?");
        $aCst->execute([$cashAccId]);$aC=$aCst->fetch(PDO::FETCH_ASSOC);
        if(!$aC)throw new Exception('صندوق غير موجود');

        $pdo->beginTransaction();
        try{
            $pdo->prepare("INSERT INTO `{$TL}` (employee_id,loan_date,amount,currency_id,installments,paid_installments,monthly_deduction,reason,status,created_by) VALUES(?,?,?,?,?,0,?,?,'active',?)")
                ->execute([$empId,$_POST['loan_date']??date('Y-m-d'),$amt,$curId,$installments,round($amt/max(1,$installments),2),trim($_POST['reason']??''),$_SESSION['user_id']]);
            $loanId=(int)$pdo->lastInsertId();

            $exchangeRate=payrollSafeRate($pdo,$curId,$branchBaseCurrId4);
            $amtBase=round($amt/$exchangeRate,4);
            $cashCurId=(int)($aC['currency_id']??$branchBaseCurrId4);
            $cashCur=$currMap[$cashCurId]??'USD';
            $branchCurCode=$currMap[$branchBaseCurrId4]??'USD';
            $en=$emp['full_name']??'موظف';

            $y=date('Y');
            $last=$pdo->query("SELECT entry_number FROM `{$TJE}` WHERE entry_number LIKE 'JE-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
            $seq=$last?(int)substr($last,-4)+1:1;
            $jeNo='JE-'.$y.'-'.str_pad($seq,4,'0',STR_PAD_LEFT);

            $pdo->prepare("INSERT INTO `{$TJE}` (entry_number,entry_date,description,currency_id,exchange_rate,total_debit,total_credit,status,reference_type,reference_id,created_by) VALUES(?,?,?,?,?,?,?,'posted','employee_loan',?,?)")
                ->execute([$jeNo,date('Y-m-d'),"سلفة موظف — {$en}",$branchBaseCurrId4,$exchangeRate,$amtBase,$amtBase,$loanId,$_SESSION['user_id']]);
            $jeId=(int)$pdo->lastInsertId();

            // مدين: سلف الموظف (حسابه الفرعي)
            $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate) VALUES(?,?,?,0,?,?,?,?,1)")
                ->execute([$jeId,$emp['loan_account_id'],$amtBase,$amtBase,$amtBase,"سلفة — {$en}",$branchBaseCurrId4]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                ->execute([$amtBase,$amtBase,$emp['loan_account_id']]);

            // دائن: الصندوق
            $cashOrig=round($amtBase*$exchangeRate,4);
            $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate) VALUES(?,?,0,?,?,?,?,?,?)")
                ->execute([$jeId,$aC['id'],$amtBase,$cashOrig,$amtBase,"صرف سلفة — {$en}",$cashCurId,$exchangeRate]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                ->execute([$amtBase,$cashOrig,$aC['id']]);

            $pdo->prepare("UPDATE `{$TL}` SET journal_entry_id=? WHERE id=?")->execute([$jeId,$loanId]);
            $pdo->commit();
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
        echo json_encode(['ok'=>true,'id'=>$loanId]);exit;
    }

    // ── delete_loan: إلغاء سلفة غير مسدَّدة — مستند إلغاء بقيد عكسي منفصل
    // مرتبط بالسلفة الأصلية (نفس فلسفة مرتجع المشتريات)، لا حذف ولا تعديل
    // على القيد الأصلي. يُمنع الإلغاء لو تم تسديد أي قسط منها فعلياً محاسبياً
    // (installments مخصومة بلحظة الاستحقاق — راجع accrue أعلاه)
    if($act==='delete_loan'){
        $loanId=(int)($_POST['id']??0);
        $st=$pdo->prepare("SELECT * FROM `{$TL}` WHERE id=? AND status='active'");
        $st->execute([$loanId]);$ln=$st->fetch(PDO::FETCH_ASSOC);
        if(!$ln)throw new Exception('سلفة غير موجودة أو غير نشطة');
        if($ln['paid_installments']>0)
            throw new Exception('لا يمكن إلغاء سلفة تم استحقاق جزء منها بالرواتب — سدّدها بشكل طبيعي عبر الأقساط المتبقية');

        $empSt=$pdo->prepare("SELECT * FROM `{$TE}` WHERE id=?");
        $empSt->execute([$ln['employee_id']]);$emp=$empSt->fetch(PDO::FETCH_ASSOC);
        if(!$emp||!$emp['loan_account_id'])throw new Exception('تعذر إيجاد حساب السلف الفرعي لهذا الموظف');

        $pdo->beginTransaction();
        try{
            if($ln['journal_entry_id']){
                $brSt5=$pdo->prepare("SELECT c.id FROM branches b JOIN currencies c ON c.code=b.base_currency WHERE b.table_suffix=? LIMIT 1");
                $brSt5->execute([$TS]);
                $branchBaseCurrId5=(int)($brSt5->fetchColumn() ?: 0);
                if(!$branchBaseCurrId5){
                    $branchBaseCurrId5=(int)($pdo->query("SELECT id FROM currencies WHERE is_base=1 LIMIT 1")->fetchColumn() ?: 0);
                }
                $branchCurCode=$currMap[$branchBaseCurrId5]??'USD';
                $en=$emp['full_name']??'موظف';

                // نقرأ base_amount من سطر السلفة الأصلي بالضبط — تطابق كامل
                $lineSt=$pdo->prepare("SELECT * FROM `{$TJI}` WHERE journal_entry_id=? AND account_id=? LIMIT 1");
                $lineSt->execute([$ln['journal_entry_id'],$emp['loan_account_id']]);
                $origLine=$lineSt->fetch(PDO::FETCH_ASSOC);
                $amtBase=$origLine?(float)$origLine['base_amount']:0;

                // الصندوق يلي انصرفت منه السلفة أصلاً (السطر الدائن بنفس القيد)
                $cashLineSt=$pdo->prepare("SELECT * FROM `{$TJI}` WHERE journal_entry_id=? AND account_id!=? LIMIT 1");
                $cashLineSt->execute([$ln['journal_entry_id'],$emp['loan_account_id']]);
                $cashLine=$cashLineSt->fetch(PDO::FETCH_ASSOC);

                if($amtBase>0 && $cashLine){
                    $y=date('Y');
                    $last=$pdo->query("SELECT entry_number FROM `{$TJE}` WHERE entry_number LIKE 'JE-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
                    $seq=$last?(int)substr($last,-4)+1:1;
                    $jeNo='JE-'.$y.'-'.str_pad($seq,4,'0',STR_PAD_LEFT);

                    $pdo->prepare("INSERT INTO `{$TJE}` (entry_number,entry_date,description,currency_id,exchange_rate,total_debit,total_credit,status,reference_type,reference_id,created_by) VALUES(?,?,?,?,1,?,?,'posted','employee_loan_cancel',?,?)")
                        ->execute([$jeNo,date('Y-m-d'),"إلغاء سلفة — {$en}",$branchBaseCurrId5,$amtBase,$amtBase,$loanId,$_SESSION['user_id']]);
                    $jeId=(int)$pdo->lastInsertId();

                    // مدين: الصندوق (رجوع الفلوس)
                    $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate) VALUES(?,?,?,0,?,?,?,?,?)")
                        ->execute([$jeId,$cashLine['account_id'],$amtBase,$cashLine['original_amount'],$amtBase,"إلغاء سلفة — {$en}",$cashLine['currency_id'],$cashLine['exchange_rate']]);
                    $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                        ->execute([$amtBase,$cashLine['original_amount'],$cashLine['account_id']]);

                    // دائن: تصفية سلف الموظف
                    $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate) VALUES(?,?,0,?,?,?,?,?,1)")
                        ->execute([$jeId,$emp['loan_account_id'],$amtBase,$amtBase,$amtBase,"إلغاء سلفة — {$en}",$branchBaseCurrId5]);
                    $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                        ->execute([$amtBase,$amtBase,$emp['loan_account_id']]);

                    $pdo->prepare("UPDATE `{$TL}` SET status='cancelled',cancel_entry_id=? WHERE id=?")->execute([$jeId,$loanId]);
                }else{
                    $pdo->prepare("UPDATE `{$TL}` SET status='cancelled' WHERE id=?")->execute([$loanId]);
                }
            }else{
                // سلفة قديمة بدون قيد أصلي مسجّل (من قبل هالتحديث) — إلغاء بدون أثر محاسبي
                $pdo->prepare("UPDATE `{$TL}` SET status='cancelled' WHERE id=?")->execute([$loanId]);
            }
            $pdo->commit();
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
        echo json_encode(['ok'=>true]);exit;
    }

    // ── add_bonus: لا قيد فوري — بتنضم لقيد الاستحقاق (accrue) وقت اعتماد الراتب ──
    if($act==='add_bonus'){
        $empId=(int)($_POST['employee_id']??0);$amt=(float)($_POST['amount']??0);
        if($amt<=0)throw new Exception('المبلغ 0');
        $st=$pdo->prepare("SELECT currency_id FROM `{$TE}` WHERE id=?");
        $st->execute([$empId]);$empCurId=(int)($st->fetchColumn() ?: 0);
        if(!$empCurId){
            $empCurId=(int)($pdo->query("SELECT id FROM currencies WHERE is_base=1 LIMIT 1")->fetchColumn() ?: 0);
        }
        $curId=(int)($_POST['currency_id'] ?: $empCurId);
        $pdo->prepare("INSERT INTO `{$TB}` (employee_id,bonus_date,bonus_type,amount,currency_id,description,status,created_by) VALUES(?,?,?,?,?,?,'active',?)")
            ->execute([$empId,$_POST['bonus_date']??date('Y-m-d'),$_POST['bonus_type']??'other',$amt,$curId,trim($_POST['description']??''),$_SESSION['user_id']]);
        echo json_encode(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);exit;
    }
    // ── delete_bonus: إلغاء ناعم — لا حذف فعلي، عشان يضل أثر لو المكافأة
    // كانت أصلاً دخلت راتب مُعتمَد سابقاً (net_salary بجدول hr_payroll) ──
    if($act==='delete_bonus'){
        $pdo->prepare("UPDATE `{$TB}` SET status='cancelled' WHERE id=? AND status='active'")->execute([(int)($_POST['id']??0)]);
        echo json_encode(['ok'=>true]);exit;
    }
    $out=ob_get_clean();
    echo json_encode(['ok'=>false,'msg'=>'unknown action: '.$act]);
}catch(Throwable $e){
    ob_end_clean();
    http_response_code(200);
    echo json_encode(['ok'=>false,'msg'=>$e->getMessage(),'line'=>$e->getLine()]);
}
