<?php
/**
 * accounting/receipts.php — سندات القبض
 * المسار: /bayhas/aleppo/modules/accounting/receipts.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('finance.receipts', 'view');
$currentModule = 'finance.receipts';

$TS  = $_SESSION['table_suffix'];
$TR  = "receipts_{$TS}";
$TRI = "receipt_invoices_{$TS}";
$TSI = "sales_invoices_{$TS}";
$TC  = "customers_{$TS}";
$TJE = "journal_entries_{$TS}";
$TJI = "journal_entry_items_{$TS}";
$TAC = "account_charts_{$TS}";
$TIAS= "invoice_account_settings_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

function genReceiptNo(PDO $pdo, string $table): string {
    $y    = date('Y');
    $last = $pdo->query("SELECT receipt_number FROM `{$table}`
        WHERE receipt_number LIKE 'RCP-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $seq  = $last ? (int)substr($last,-4)+1 : 1;
    return 'RCP-'.$y.'-'.str_pad($seq,4,'0',STR_PAD_LEFT);
}

function genEntryNo(PDO $pdo, string $table): string {
    $y    = date('Y');
    $last = $pdo->query("SELECT entry_number FROM `{$table}`
        WHERE entry_number LIKE 'JE-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $seq  = $last ? (int)substr($last,-4)+1 : 1;
    return 'JE-'.$y.'-'.str_pad($seq,4,'0',STR_PAD_LEFT);
}

// ── جلب حساب من الإعدادات ──
function getSettingAccount(PDO $pdo, string $tias, string $key): ?array {
    $st = $pdo->prepare("SELECT ac.* FROM `{$tias}` ias
        JOIN account_charts_alp ac ON ac.id=ias.account_id
        WHERE ias.setting_key=? LIMIT 1");
    $st->execute([$key]);
    return $st->fetch() ?: null;
}

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        // ── جلب فواتير عميل غير مدفوعة ──
        if ($act==='get_customer_invoices') {
            $custId = (int)$_POST['customer_id'];
            $st = $pdo->prepare("SELECT id, invoice_number, invoice_date, total_amount,
                paid_amount, balance_amount, payment_status
                FROM `{$TSI}`
                WHERE customer_id=? AND status='confirmed'
                AND payment_status IN ('pending','partial')
                ORDER BY invoice_date");
            $st->execute([$custId]);
            echo json_encode(['ok'=>true,'data'=>$st->fetchAll()]);
        }

        // ── حفظ سند قبض ──
        elseif ($act==='save_receipt') {
            requirePermission('finance.receipts','create');
            $custId   = (int)$_POST['customer_id'];
            $date     = $_POST['receipt_date'] ?? date('Y-m-d');
            $amount   = (float)$_POST['amount'];
            $currency = $_POST['currency'] ?? 'USD';
            $rate     = max(0.0001,(float)($_POST['exchange_rate']??1));
            $method   = $_POST['payment_method'] ?? 'cash';
            $cashAccId= (int)($_POST['cash_account_id']??0) ?: null;
            $notes    = trim($_POST['notes']??'');
            $allocs   = json_decode($_POST['allocations']??'[]',true);

            if (!$custId) throw new Exception('يجب اختيار العميل');
            if ($amount<=0) throw new Exception('المبلغ يجب أن يكون أكبر من صفر');

            $amountUsd = $amount / $rate;

            // التحقق من مجموع التوزيع
            $totalAlloc = array_sum(array_column($allocs,'amount'));
            if ($totalAlloc > $amountUsd + 0.01)
                throw new Exception('مجموع التوزيع ('.number_format($totalAlloc,2).') أكبر من مبلغ السند ('.number_format($amountUsd,2).')');

            // جلب اسم العميل
            $cSt = $pdo->prepare("SELECT name FROM `{$TC}` WHERE id=?");
            $cSt->execute([$custId]);
            $custName = $cSt->fetchColumn() ?: '';

            $pdo->beginTransaction();
            try {
                $rcpNo = genReceiptNo($pdo,$TR);
                $pdo->prepare("INSERT INTO `{$TR}` (receipt_number,receipt_date,customer_id,customer_name,
                    amount,currency,exchange_rate,amount_usd,payment_method,cash_account_id,notes,status,created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,'draft',?)")
                    ->execute([$rcpNo,$date,$custId,$custName,$amount,$currency,$rate,$amountUsd,
                        $method,$cashAccId,$notes,$_SESSION['user_id']]);
                $rcpId = (int)$pdo->lastInsertId();

                // توزيع على الفواتير
                foreach ($allocs as $al) {
                    $invId  = (int)$al['invoice_id'];
                    $alAmt  = (float)$al['amount'];
                    if (!$invId || $alAmt<=0) continue;
                    $pdo->prepare("INSERT INTO `{$TRI}` (receipt_id,invoice_id,allocated_amount)
                        VALUES (?,?,?)")->execute([$rcpId,$invId,$alAmt]);
                    // تحديث الفاتورة
                    $pdo->prepare("UPDATE `{$TSI}` SET
                        paid_amount=paid_amount+?,
                        balance_amount=GREATEST(0,balance_amount-?),
                        payment_status=CASE
                            WHEN balance_amount-? <= 0.01 THEN 'paid'
                            ELSE 'partial' END
                        WHERE id=?")
                        ->execute([$alAmt,$alAmt,$alAmt,$invId]);
                }

                // ── إنشاء قيد محاسبي تلقائي ──
                $accCustomer = getSettingAccount($pdo,$TIAS,'customer_receivable');
                $accCash     = $cashAccId ?
                    $pdo->query("SELECT * FROM `{$TAC}` WHERE id={$cashAccId}")->fetch() :
                    getSettingAccount($pdo,$TIAS,'cash_usd');

                if ($accCustomer && $accCash) {
                    $jeNo = genEntryNo($pdo,$TJE);
                    $pdo->prepare("INSERT INTO `{$TJE}` (entry_number,entry_date,description,currency,
                        exchange_rate,total_debit,total_credit,status,reference_type,reference_id,created_by)
                        VALUES (?,?,?,?,?,?,?,'draft','receipt',?,?)")
                        ->execute([$jeNo,$date,
                            "قبض من العميل {$custName} — سند {$rcpNo}",
                            $currency,$rate,$amountUsd,$amountUsd,$rcpId,$_SESSION['user_id']]);
                    $jeId=(int)$pdo->lastInsertId();

                    // مدين: الصندوق/البنك
                    $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,
                        original_amount,base_amount,description,currency,exchange_rate)
                        VALUES (?,?,?,0,?,?,?,?,?)")
                        ->execute([$jeId,$accCash['id'],$amountUsd,$amount,$amountUsd,
                            "قبض — {$rcpNo}",$currency,$rate]);
                    // دائن: ذمم العملاء
                    $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,
                        original_amount,base_amount,description,currency,exchange_rate)
                        VALUES (?,?,0,?,?,?,?,?,?)")
                        ->execute([$jeId,$accCustomer['id'],$amountUsd,$amount,$amountUsd,
                            "ذمة — {$custName}",$currency,$rate]);

                    $pdo->prepare("UPDATE `{$TR}` SET journal_entry_id=? WHERE id=?")->execute([$jeId,$rcpId]);
                }

                $pdo->commit();
                echo json_encode(['ok'=>true,'id'=>$rcpId,'no'=>$rcpNo,'je_created'=>($accCustomer&&$accCash)]);
            } catch(Exception $e){ $pdo->rollBack(); throw $e; }
        }

        // ── ترحيل سند ──
        elseif ($act==='post_receipt') {
            requirePermission('finance.receipts','confirm');
            $id=(int)$_POST['id'];
            $st=$pdo->prepare("SELECT * FROM `{$TR}` WHERE id=?");
            $st->execute([$id]); $rcp=$st->fetch();
            if(!$rcp) throw new Exception('السند غير موجود');
            if($rcp['status']!=='draft') throw new Exception('يمكن ترحيل المسودات فقط');

            $pdo->beginTransaction();
            try {
                // ترحيل القيد المحاسبي
                if($rcp['journal_entry_id']){
                    $items=$pdo->prepare("SELECT * FROM `{$TJI}` WHERE journal_entry_id=?");
                    $items->execute([$rcp['journal_entry_id']]);
                    foreach($items->fetchAll() as $item){
                        $net=$item['debit']-$item['credit'];
                        $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,updated_at=NOW() WHERE id=?")
                            ->execute([$net,$item['account_id']]);
                    }
                    $pdo->prepare("UPDATE `{$TJE}` SET status='posted',posted_at=NOW(),posted_by=? WHERE id=?")
                        ->execute([$_SESSION['user_id'],$rcp['journal_entry_id']]);
                }
                $pdo->prepare("UPDATE `{$TR}` SET status='posted',updated_by=?,updated_at=NOW() WHERE id=?")
                    ->execute([$_SESSION['user_id'],$id]);
                $pdo->commit();
                echo json_encode(['ok'=>true,'msg'=>'تم ترحيل سند القبض وتحديث الأرصدة']);
            } catch(Exception $e){ $pdo->rollBack(); throw $e; }
        }

        // ── إلغاء سند ──
        elseif ($act==='cancel_receipt') {
            requirePermission('finance.receipts','edit');
            $id=(int)$_POST['id'];
            $st=$pdo->prepare("SELECT * FROM `{$TR}` WHERE id=?");
            $st->execute([$id]); $rcp=$st->fetch();
            if(!$rcp) throw new Exception('السند غير موجود');
            if($rcp['status']==='cancelled') throw new Exception('ملغى مسبقاً');

            $pdo->beginTransaction();
            try {
                // عكس التوزيع على الفواتير
                $allocs=$pdo->prepare("SELECT * FROM `{$TRI}` WHERE receipt_id=?");
                $allocs->execute([$id]);
                foreach($allocs->fetchAll() as $al){
                    $pdo->prepare("UPDATE `{$TSI}` SET
                        paid_amount=GREATEST(0,paid_amount-?),
                        balance_amount=balance_amount+?,
                        payment_status=CASE WHEN paid_amount-? <= 0.01 THEN 'pending' ELSE 'partial' END
                        WHERE id=?")
                        ->execute([$al['allocated_amount'],$al['allocated_amount'],$al['allocated_amount'],$al['invoice_id']]);
                }
                // عكس القيد المحاسبي إذا مرحّل
                if($rcp['journal_entry_id'] && $rcp['status']==='posted'){
                    $items=$pdo->prepare("SELECT * FROM `{$TJI}` WHERE journal_entry_id=?");
                    $items->execute([$rcp['journal_entry_id']]);
                    foreach($items->fetchAll() as $item){
                        $net=$item['debit']-$item['credit'];
                        $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,updated_at=NOW() WHERE id=?")
                            ->execute([$net,$item['account_id']]);
                    }
                    $pdo->prepare("UPDATE `{$TJE}` SET status='cancelled',cancelled_at=NOW(),cancelled_by=? WHERE id=?")
                        ->execute([$_SESSION['user_id'],$rcp['journal_entry_id']]);
                }
                $pdo->prepare("UPDATE `{$TR}` SET status='cancelled',updated_by=?,updated_at=NOW() WHERE id=?")
                    ->execute([$_SESSION['user_id'],$id]);
                $pdo->commit();
                echo json_encode(['ok'=>true,'msg'=>'تم إلغاء السند وعكس التأثيرات']);
            } catch(Exception $e){ $pdo->rollBack(); throw $e; }
        }

        // ── جلب سند ──
        elseif ($act==='get_receipt') {
            $id=(int)$_POST['id'];
            $st=$pdo->prepare("SELECT r.*,ac.name AS cash_acc_name
                FROM `{$TR}` r LEFT JOIN `{$TAC}` ac ON ac.id=r.cash_account_id WHERE r.id=?");
            $st->execute([$id]); $rcp=$st->fetch();
            if(!$rcp) throw new Exception('السند غير موجود');
            $allocs=$pdo->prepare("SELECT ri.*,si.invoice_number,si.invoice_date,si.total_amount
                FROM `{$TRI}` ri JOIN `{$TSI}` si ON si.id=ri.invoice_id WHERE ri.receipt_id=?");
            $allocs->execute([$id]);
            $rcp['allocations']=$allocs->fetchAll();
            echo json_encode(['ok'=>true,'data'=>$rcp]);
        }

        else throw new Exception('إجراء غير معروف');
    } catch(Exception $e){
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

// ── بيانات الصفحة ──
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo   = $_GET['to']   ?? date('Y-m-d');
$statusF  = $_GET['status'] ?? '';
$custF    = (int)($_GET['customer'] ?? 0);
$search   = trim($_GET['q'] ?? '');

$where='WHERE r.receipt_date BETWEEN ? AND ?'; $params=[$dateFrom,$dateTo];
if($statusF) { $where.=' AND r.status=?'; $params[]=$statusF; }
if($custF)   { $where.=' AND r.customer_id=?'; $params[]=$custF; }
if($search)  { $where.=' AND (r.receipt_number LIKE ? OR r.customer_name LIKE ?)'; $params[]="%{$search}%"; $params[]="%{$search}%"; }

$receipts=$pdo->prepare("SELECT r.*,
    COUNT(ri.id) AS invoices_count
    FROM `{$TR}` r
    LEFT JOIN `{$TRI}` ri ON ri.receipt_id=r.id
    {$where}
    GROUP BY r.id ORDER BY r.receipt_date DESC,r.id DESC LIMIT 200");
$receipts->execute($params);
$receipts=$receipts->fetchAll();

$customers=$pdo->query("SELECT id,name,phone FROM `{$TC}` WHERE status='active' ORDER BY name")->fetchAll();
$cashAccounts=$pdo->query("SELECT id,code,name,currency FROM `{$TAC}`
    WHERE account_type='asset' AND is_active=1 AND level>=3
    AND (code LIKE '111%' OR code LIKE '112%') ORDER BY code")->fetchAll();

try {
    $stats=$pdo->query("SELECT COUNT(*) AS total,
        SUM(status='draft') AS drafts,
        SUM(status='posted') AS posted,
        COALESCE(SUM(CASE WHEN status='posted' THEN amount_usd END),0) AS total_usd
        FROM `{$TR}`")->fetch();
} catch(Exception $e){ $stats=['total'=>0,'drafts'=>0,'posted'=>0,'total_usd'=>0]; }

$STATUS_MAP=[
    'draft'    =>['label'=>'مسودة','cls'=>'bg-secondary-subtle text-secondary'],
    'posted'   =>['label'=>'مرحّل','cls'=>'bg-success-subtle text-success'],
    'cancelled'=>['label'=>'ملغى', 'cls'=>'bg-danger-subtle text-danger'],
];
$PAY_METHOD=['cash'=>'نقدي','bank'=>'بنك','card'=>'بطاقة','check'=>'شيك'];
$CURR_SYM=['USD'=>'$','SYP'=>'ل.س','TRY'=>'₺','EUR'=>'€'];
$rcpNo=genReceiptNo($pdo,$TR);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>سندات القبض — <?= htmlspecialchars($branchName) ?></title>
<link rel="icon" href="/bayhas/assets/images/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="/bayhas/assets/css/layout.css" rel="stylesheet">
<style>
.stat-card{background:#fff;border-radius:14px;border:1px solid #e2e8f0;padding:12px 16px;display:flex;align-items:center;gap:10px}
.stat-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.stat-val{font-size:1.1rem;font-weight:700;color:#1e293b;line-height:1}
.stat-lbl{font-size:.7rem;color:#64748b;margin-top:2px}
.tbl-wrap{background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden}
.tbl-hdr{padding:12px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
table.mtbl{width:100%;border-collapse:collapse;font-size:.82rem}
table.mtbl th{background:#f8fafc;padding:8px 12px;font-weight:600;color:#64748b;font-size:.72rem;border-bottom:1px solid #f1f5f9}
table.mtbl td{padding:7px 12px;border-bottom:1px solid #f8fafc;vertical-align:middle}
table.mtbl tr:last-child td{border-bottom:none}
table.mtbl tr:hover td{background:#f8fff8}
.act-btn{width:27px;height:27px;border-radius:7px;border:1px solid #e2e8f0;background:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;color:#64748b;cursor:pointer;transition:all .12s}
.act-btn:hover{background:#f1f5f9}
.act-btn.success-h:hover{background:#dcfce7;color:#16a34a;border-color:#86efac}
.act-btn.danger:hover{background:#fee2e2;color:#dc2626;border-color:#fca5a5}
.act-btn.info-h:hover{background:#e0f2fe;color:#0891b2;border-color:#7dd3fc}
.field-lbl{font-size:.76rem;font-weight:700;color:#475569;margin-bottom:4px;display:block}
.req{color:#dc2626} .n{font-variant-numeric:tabular-nums}
.inv-alloc-row{display:grid;grid-template-columns:minmax(0,1fr) 90px 80px 90px 80px;gap:6px;align-items:center;
    padding:6px 8px;border-radius:8px;background:#f8fafc;margin-bottom:4px;font-size:.78rem}
.inv-alloc-row.selected{background:#eff6ff;border:1px solid #bfdbfe}
.alloc-input{width:100%;padding:3px 6px;border:1px solid #e2e8f0;border-radius:6px;font-size:.78rem;text-align:left;direction:ltr}
.alloc-input:focus{outline:none;border-color:#16a34a}
.balance-bar{background:#f1f5f9;border-radius:8px;padding:8px 14px;display:flex;justify-content:space-between;align-items:center;font-size:.8rem}
</style>
</head>
<body>
<div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
<?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
<header class="topbar">
    <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
    <span class="tb-title"><i class="bi bi-cash-stack me-1 text-success"></i>سندات القبض</span>
    <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
</header>
<main class="main-content"><div class="content-body">

<!-- إحصائيات -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#f0fdf4"><i class="bi bi-cash-stack text-success"></i></div>
            <div><div class="stat-val"><?=$stats['total']?></div><div class="stat-lbl">إجمالي السندات</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fef3c7"><i class="bi bi-hourglass text-warning"></i></div>
            <div><div class="stat-val"><?=$stats['drafts']?></div><div class="stat-lbl">مسودات</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#f0fdf4"><i class="bi bi-check-circle text-success"></i></div>
            <div><div class="stat-val"><?=$stats['posted']?></div><div class="stat-lbl">مرحّلة</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#f0fdf4"><i class="bi bi-currency-dollar text-success"></i></div>
            <div><div class="stat-val n">$ <?=number_format($stats['total_usd'],2)?></div><div class="stat-lbl">إجمالي المقبوض</div></div>
        </div>
    </div>
</div>

<!-- فلاتر -->
<div class="tbl-wrap mb-3">
    <div class="tbl-hdr">
        <form method="get" class="d-flex gap-2 flex-wrap align-items-center w-100">
            <input type="text" name="q" value="<?=htmlspecialchars($search)?>"
                   placeholder="رقم السند أو العميل..." class="form-control form-control-sm" style="width:180px;border-radius:8px">
            <select name="customer" class="form-select form-select-sm" style="width:160px;border-radius:8px" onchange="this.form.submit()">
                <option value="">كل العملاء</option>
                <?php foreach($customers as $c): ?>
                <option value="<?=$c['id']?>" <?=$custF==$c['id']?'selected':''?>><?=htmlspecialchars($c['name'])?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="form-select form-select-sm" style="width:110px;border-radius:8px" onchange="this.form.submit()">
                <option value="">كل الحالات</option>
                <?php foreach($STATUS_MAP as $k=>$v): ?>
                <option value="<?=$k?>" <?=$statusF===$k?'selected':''?>><?=$v['label']?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="from" value="<?=htmlspecialchars($dateFrom)?>" class="form-control form-control-sm" style="width:140px;border-radius:8px">
            <span style="color:#94a3b8">—</span>
            <input type="date" name="to" value="<?=htmlspecialchars($dateTo)?>" class="form-control form-control-sm" style="width:140px;border-radius:8px">
            <button type="submit" class="btn btn-sm btn-primary" style="border-radius:8px"><i class="bi bi-search me-1"></i>بحث</button>
            <?php if($search||$statusF||$custF): ?>
            <a href="?from=<?=$dateFrom?>&to=<?=$dateTo?>" class="btn btn-sm btn-light" style="border-radius:8px"><i class="bi bi-x-lg"></i></a>
            <?php endif; ?>
        </form>
        <button class="btn btn-sm fw-600" style="border-radius:8px;background:#16a34a;color:#fff;font-size:.82rem;white-space:nowrap"
                onclick="openNewReceipt()">
            <i class="bi bi-plus-lg me-1"></i>سند قبض جديد
        </button>
    </div>
</div>

<!-- الجدول -->
<div class="tbl-wrap">
    <div class="table-responsive">
    <table class="mtbl">
        <thead><tr>
            <th>رقم السند</th><th>التاريخ</th><th>العميل</th>
            <th>طريقة الدفع</th><th>العملة</th>
            <th class="text-end">المبلغ</th>
            <th class="text-end">بالدولار ($)</th>
            <th class="text-center">الفواتير</th>
            <th>الحالة</th>
            <th style="text-align:center">إجراءات</th>
        </tr></thead>
        <tbody>
        <?php if(empty($receipts)): ?>
        <tr><td colspan="10" class="text-center text-muted py-5">
            <i class="bi bi-cash-stack d-block mb-2" style="font-size:2rem;opacity:.2"></i>
            لا توجد سندات قبض
        </td></tr>
        <?php endif; ?>
        <?php foreach($receipts as $rcp):
            $st=$STATUS_MAP[$rcp['status']]??$STATUS_MAP['draft'];
            $sym=$CURR_SYM[$rcp['currency']]??'$';
        ?>
        <tr>
            <td class="n fw-600" style="direction:ltr;color:#16a34a"><?=htmlspecialchars($rcp['receipt_number'])?></td>
            <td class="text-muted"><?=$rcp['receipt_date']?></td>
            <td class="fw-600" style="font-size:.83rem"><?=htmlspecialchars($rcp['customer_name']??'—')?></td>
            <td>
                <span class="badge bg-secondary-subtle text-secondary" style="font-size:.68rem">
                    <i class="bi bi-<?=$rcp['payment_method']==='cash'?'cash-coin':($rcp['payment_method']==='bank'?'bank':'credit-card')?> me-1"></i>
                    <?=$PAY_METHOD[$rcp['payment_method']]??$rcp['payment_method']?>
                </span>
            </td>
            <td style="font-size:.75rem"><?=$rcp['currency']?></td>
            <td class="n text-end fw-600"><?=$sym?> <?=number_format($rcp['amount'],2)?></td>
            <td class="n text-end fw-600 text-success">$ <?=number_format($rcp['amount_usd'],2)?></td>
            <td class="text-center">
                <?php if($rcp['invoices_count']): ?>
                <span class="badge bg-primary-subtle text-primary"><?=$rcp['invoices_count']?> فاتورة</span>
                <?php else: ?>
                <span class="badge bg-warning-subtle text-warning">بدون توزيع</span>
                <?php endif; ?>
            </td>
            <td><span class="badge <?=$st['cls']?>" style="font-size:.68rem"><?=$st['label']?></span></td>
            <td>
                <div class="d-flex gap-1 justify-content-center">
                    <button class="act-btn info-h" onclick="viewReceipt(<?=$rcp['id']?>)" title="عرض">
                        <i class="bi bi-eye"></i>
                    </button>
                    <?php if($rcp['status']==='draft'): ?>
                    <button class="act-btn success-h" onclick="postReceipt(<?=$rcp['id']?>,'<?=htmlspecialchars($rcp['receipt_number'],ENT_QUOTES)?>')" title="ترحيل">
                        <i class="bi bi-check-circle"></i>
                    </button>
                    <?php endif; ?>
                    <?php if($rcp['status']!=='cancelled'): ?>
                    <button class="act-btn danger" onclick="cancelReceipt(<?=$rcp['id']?>,'<?=htmlspecialchars($rcp['receipt_number'],ENT_QUOTES)?>')" title="إلغاء">
                        <i class="bi bi-x-circle"></i>
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
</div></main>

<!-- مودال سند جديد -->
<div class="modal fade" id="rcpModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#065f46,#16a34a);border-radius:16px 16px 0 0">
        <div>
          <h6 class="modal-title text-white fw-700 mb-0"><i class="bi bi-cash-coin me-2"></i>سند قبض جديد</h6>
          <div style="font-size:.72rem;color:rgba(255,255,255,.7);margin-top:2px" dir="ltr"><?=htmlspecialchars($rcpNo)?></div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 pt-3">

        <!-- بيانات السند -->
        <div class="row g-3 mb-3 pb-3" style="border-bottom:1px solid #f1f5f9">
          <div class="col-md-6">
            <label class="field-lbl">العميل <span class="req">*</span></label>
            <select id="rCust" class="form-select form-select-sm" onchange="loadCustomerInvoices()">
                <option value="">— اختر العميل —</option>
                <?php foreach($customers as $c): ?>
                <option value="<?=$c['id']?>"><?=htmlspecialchars($c['name'])?></option>
                <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="field-lbl">التاريخ <span class="req">*</span></label>
            <input type="date" id="rDate" class="form-control form-control-sm" value="<?=date('Y-m-d')?>">
          </div>
          <div class="col-md-3">
            <label class="field-lbl">طريقة الدفع</label>
            <select id="rMethod" class="form-select form-select-sm">
                <option value="cash">نقدي</option>
                <option value="bank">تحويل بنكي</option>
                <option value="card">بطاقة</option>
                <option value="check">شيك</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="field-lbl">العملة</label>
            <select id="rCurr" class="form-select form-select-sm" onchange="onRcpCurrencyChange()">
                <option value="USD">USD $</option>
                <option value="SYP">SYP ل.س</option>
                <option value="TRY">TRY ₺</option>
            </select>
          </div>
          <div class="col-md-2" id="rRateWrap">
            <label class="field-lbl">سعر الصرف vs $</label>
            <input type="number" id="rRate" class="form-control form-control-sm" value="1" min="0.0001" step="0.0001" dir="ltr" oninput="recalcSnd()">
          </div>
          <div class="col-md-3">
            <label class="field-lbl">المبلغ المقبوض <span class="req">*</span></label>
            <input type="number" id="rAmount" class="form-control form-control-sm fw-600" min="0" step="0.01"
                   dir="ltr" placeholder="0.00" oninput="recalcSnd()">
          </div>
          <div class="col-md-4">
            <label class="field-lbl">حساب الصندوق/البنك</label>
            <select id="rCashAcc" class="form-select form-select-sm">
                <option value="">— تلقائي حسب الإعدادات —</option>
                <?php foreach($cashAccounts as $acc): ?>
                <option value="<?=$acc['id']?>"><?=htmlspecialchars($acc['code'].' — '.$acc['name'])?></option>
                <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="field-lbl">ملاحظات</label>
            <input type="text" id="rNotes" class="form-control form-control-sm" placeholder="اختياري">
          </div>
        </div>

        <!-- شريط الرصيد -->
        <div class="balance-bar mb-3">
            <div>
                <span style="color:#64748b">المبلغ بالدولار:</span>
                <span id="rAmtUsd" class="n fw-600 text-success ms-1">$ 0.00</span>
            </div>
            <div>
                <span style="color:#64748b">موزَّع:</span>
                <span id="rAllocated" class="n fw-600 text-primary ms-1">$ 0.00</span>
            </div>
            <div>
                <span style="color:#64748b">متبقي للتوزيع:</span>
                <span id="rRemaining" class="n fw-600 ms-1">$ 0.00</span>
            </div>
        </div>

        <!-- توزيع على الفواتير -->
        <div class="mb-2 d-flex align-items-center justify-content-between">
            <span style="font-size:.82rem;font-weight:700;color:#1e293b">
                <i class="bi bi-receipt me-1 text-success"></i>توزيع على الفواتير
            </span>
            <span style="font-size:.72rem;color:#94a3b8">اختر فاتورة لتوزيع المبلغ عليها</span>
        </div>
        <div id="invoicesList">
            <div class="text-center text-muted py-3" style="font-size:.8rem">
                <i class="bi bi-person-check d-block mb-1" style="font-size:1.2rem;opacity:.3"></i>
                اختر العميل أولاً لعرض فواتيره المستحقة
            </div>
        </div>

      </div>
      <div class="modal-footer border-0 px-4 pb-4">
        <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
        <button class="btn btn-sm fw-600" style="border-radius:8px;border:1px solid #16a34a;color:#16a34a;font-size:.82rem"
                onclick="saveReceipt(true)">
            <i class="bi bi-check-circle me-1"></i>حفظ وترحيل
        </button>
        <button class="btn btn-sm fw-600" style="border-radius:8px;background:#16a34a;color:#fff;min-width:110px"
                onclick="saveReceipt(false)" id="btnSaveRcp">
          <span id="saveRcpTxt"><i class="bi bi-floppy me-1"></i>حفظ كمسودة</span>
          <span id="saveRcpSpin" class="spinner-border spinner-border-sm" style="display:none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- مودال عرض السند -->
<div class="modal fade" id="viewModal" tabindex="-1">
  <div class="modal-dialog modal-md">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#065f46,#16a34a);border-radius:16px 16px 0 0">
        <h6 class="modal-title text-white fw-700 mb-0" id="vTitle">سند القبض</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 py-3" id="vBody">
        <div class="text-center py-4"><span class="spinner-border text-success"></span></div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const sb=document.getElementById('sidebar'),ov=document.getElementById('sbOverlay');
function sbOpen(){sb.classList.add('open');ov.classList.add('show');}
function sbClose(){sb.classList.remove('open');ov.classList.remove('show');}
window.addEventListener('resize',()=>{if(window.innerWidth>991)sbClose();});
function toggleGroup(g){const o=g.classList.contains('open');document.querySelectorAll('.sb-group.open').forEach(x=>x.classList.remove('open'));g.classList.toggle('open',!o);localStorage.setItem('sb_open_'+g.dataset.key,(!o).toString());}
document.querySelectorAll('.sb-group').forEach(g=>{if(localStorage.getItem('sb_open_'+g.dataset.key)==='true')g.classList.add('open');});

const rcpModal  = new bootstrap.Modal(document.getElementById('rcpModal'));
const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
const STATUS_MAP= <?=json_encode($STATUS_MAP)?>;
const PAY_METHOD= <?=json_encode($PAY_METHOD)?>;
const CURR_SYM  = <?=json_encode($CURR_SYM)?>;
var _invoices   = [];

function post(data){
    const fd=new FormData();
    Object.entries(data).forEach(([k,v])=>fd.append(k,v??''));
    return fetch(location.href,{method:'POST',body:fd}).then(r=>r.json());
}
function toast(msg,type='success'){
    const t=document.createElement('div');t.className=`alert alert-${type} shadow`;
    t.style.cssText='position:fixed;top:70px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:240px;text-align:center;font-size:.83rem;padding:.5rem 1.2rem';
    t.innerHTML=`<i class="bi bi-${type==='success'?'check-circle-fill text-success':'exclamation-triangle-fill text-danger'} me-2"></i>${msg}`;
    document.body.appendChild(t);setTimeout(()=>t.remove(),3500);
}

function openNewReceipt(){
    document.getElementById('rCust').value='';
    document.getElementById('rDate').value=new Date().toISOString().split('T')[0];
    document.getElementById('rMethod').value='cash';
    document.getElementById('rCurr').value='USD';
    document.getElementById('rRate').value='1';
    document.getElementById('rAmount').value='';
    document.getElementById('rCashAcc').value='';
    document.getElementById('rNotes').value='';
    document.getElementById('invoicesList').innerHTML='<div class="text-center text-muted py-3" style="font-size:.8rem"><i class="bi bi-person-check d-block mb-1" style="font-size:1.2rem;opacity:.3"></i>اختر العميل أولاً</div>';
    _invoices=[];
    recalcSnd();
    rcpModal.show();
}

function onRcpCurrencyChange(){
    const isUSD=document.getElementById('rCurr').value==='USD';
    document.getElementById('rRateWrap').style.opacity=isUSD?.4:1;
    if(isUSD) document.getElementById('rRate').value='1';
    recalcSnd();
}

function recalcSnd(){
    const amt=parseFloat(document.getElementById('rAmount').value||0);
    const rate=parseFloat(document.getElementById('rRate').value||1);
    const amtUsd=amt/rate;
    document.getElementById('rAmtUsd').textContent='$ '+amtUsd.toFixed(2);
    updateAllocBar(amtUsd);
}

function updateAllocBar(amtUsd){
    if(!amtUsd) amtUsd=parseFloat(document.getElementById('rAmount').value||0)/parseFloat(document.getElementById('rRate').value||1);
    let allocated=0;
    document.querySelectorAll('.alloc-input').forEach(inp=>{allocated+=parseFloat(inp.value||0);});
    const remaining=amtUsd-allocated;
    document.getElementById('rAllocated').textContent='$ '+allocated.toFixed(2);
    const remEl=document.getElementById('rRemaining');
    remEl.textContent='$ '+remaining.toFixed(2);
    remEl.style.color=remaining<-0.01?'#dc2626':(remaining<0.01?'#16a34a':'#f59e0b');
}

function loadCustomerInvoices(){
    const custId=document.getElementById('rCust').value;
    if(!custId){document.getElementById('invoicesList').innerHTML='<div class="text-center text-muted py-3" style="font-size:.8rem">اختر العميل</div>';return;}
    document.getElementById('invoicesList').innerHTML='<div class="text-center py-3"><span class="spinner-border spinner-border-sm text-success"></span></div>';
    post({_action:'get_customer_invoices',customer_id:custId}).then(d=>{
        if(!d.ok){toast(d.msg,'danger');return;}
        _invoices=d.data;
        if(!_invoices.length){
            document.getElementById('invoicesList').innerHTML='<div class="text-center text-muted py-3" style="font-size:.8rem"><i class="bi bi-check-circle-fill text-success me-1"></i>لا توجد فواتير مستحقة لهذا العميل</div>';
            return;
        }
        let html='<div class="inv-alloc-row mb-1" style="background:transparent;padding:4px 8px">'
            +'<span style="font-size:.7rem;color:#64748b;font-weight:600">الفاتورة</span>'
            +'<span style="font-size:.7rem;color:#64748b;font-weight:600;text-align:center">الإجمالي</span>'
            +'<span style="font-size:.7rem;color:#dc2626;font-weight:600;text-align:center">المستحق</span>'
            +'<span style="font-size:.7rem;color:#16a34a;font-weight:600;text-align:center">توزيع ($)</span>'
            +'<span></span></div>';
        _invoices.forEach(inv=>{
            html+=`<div class="inv-alloc-row" id="irow_${inv.id}">
                <div>
                    <div class="fw-600 n" style="direction:ltr;font-size:.78rem;color:#1e3a8a">${inv.invoice_number}</div>
                    <div style="color:#94a3b8;font-size:.68rem">${inv.invoice_date}</div>
                </div>
                <div class="n text-center">$ ${parseFloat(inv.total_amount).toFixed(2)}</div>
                <div class="n text-center text-danger fw-600">$ ${parseFloat(inv.balance_amount).toFixed(2)}</div>
                <input type="number" class="alloc-input" id="alloc_${inv.id}"
                    data-invoice="${inv.id}" data-balance="${inv.balance_amount}"
                    min="0" step="0.01" placeholder="0.00"
                    oninput="onAllocInput(${inv.id})">
                <button class="btn btn-sm" style="border-radius:6px;border:1px solid #e2e8f0;font-size:.68rem;padding:2px 6px;color:#64748b"
                    onclick="setFullBalance(${inv.id})">الكل</button>
            </div>`;
        });
        document.getElementById('invoicesList').innerHTML=html;
    });
}

function setFullBalance(invId){
    const inp=document.getElementById('alloc_'+invId);
    const amtUsd=parseFloat(document.getElementById('rAmount').value||0)/parseFloat(document.getElementById('rRate').value||1);
    let allocated=0;
    document.querySelectorAll('.alloc-input').forEach(i=>{if(parseInt(i.dataset.invoice)!==invId) allocated+=parseFloat(i.value||0);});
    const remaining=amtUsd-allocated;
    const balance=parseFloat(inp.dataset.balance);
    inp.value=Math.min(balance,remaining).toFixed(2);
    onAllocInput(invId);
}

function onAllocInput(invId){
    const inp=document.getElementById('alloc_'+invId);
    const balance=parseFloat(inp.dataset.balance);
    if(parseFloat(inp.value)>balance) inp.value=balance.toFixed(2);
    document.getElementById('irow_'+invId).classList.toggle('selected',parseFloat(inp.value||0)>0);
    updateAllocBar();
}

function getAllocations(){
    const allocs=[];
    document.querySelectorAll('.alloc-input').forEach(inp=>{
        const amt=parseFloat(inp.value||0);
        if(amt>0) allocs.push({invoice_id:inp.dataset.invoice,amount:amt});
    });
    return allocs;
}

function saveReceipt(andPost){
    const custId=document.getElementById('rCust').value;
    const amount=parseFloat(document.getElementById('rAmount').value||0);
    if(!custId){toast('يجب اختيار العميل','danger');return;}
    if(amount<=0){toast('يجب إدخال مبلغ أكبر من صفر','danger');return;}

    const btn=document.getElementById('btnSaveRcp');
    document.getElementById('saveRcpTxt').style.opacity='0';
    document.getElementById('saveRcpSpin').style.display='inline-block';
    btn.disabled=true;

    post({
        _action:'save_receipt',
        customer_id:  custId,
        receipt_date: document.getElementById('rDate').value,
        amount,
        currency:     document.getElementById('rCurr').value,
        exchange_rate:document.getElementById('rRate').value,
        payment_method:document.getElementById('rMethod').value,
        cash_account_id:document.getElementById('rCashAcc').value,
        notes:        document.getElementById('rNotes').value,
        allocations:  JSON.stringify(getAllocations()),
    }).then(d=>{
        document.getElementById('saveRcpTxt').style.opacity='1';
        document.getElementById('saveRcpSpin').style.display='none';
        btn.disabled=false;
        if(!d.ok){toast(d.msg,'danger');return;}
        const jeMsg=d.je_created?' (قيد محاسبي تلقائي ✅)':'';
        if(andPost){
            post({_action:'post_receipt',id:d.id}).then(p=>{
                if(p.ok){toast('✅ تم حفظ السند وترحيله — '+d.no+jeMsg);rcpModal.hide();setTimeout(()=>location.reload(),800);}
                else toast(p.msg,'danger');
            });
        } else {
            toast('✅ تم حفظ السند — '+d.no+jeMsg);rcpModal.hide();setTimeout(()=>location.reload(),800);
        }
    });
}

function viewReceipt(id){
    document.getElementById('vTitle').textContent='جارٍ التحميل...';
    document.getElementById('vBody').innerHTML='<div class="text-center py-4"><span class="spinner-border text-success"></span></div>';
    viewModal.show();
    post({_action:'get_receipt',id}).then(d=>{
        if(!d.ok){document.getElementById('vBody').innerHTML=`<div class="text-danger p-3">${d.msg}</div>`;return;}
        const r=d.data;
        const st=STATUS_MAP[r.status]||STATUS_MAP['draft'];
        const sym=CURR_SYM[r.currency]||'$';
        document.getElementById('vTitle').textContent='سند: '+r.receipt_number;
        const allocHtml=(r.allocations||[]).map(al=>`
            <div style="display:flex;justify-content:space-between;font-size:.8rem;padding:4px 0;border-bottom:1px solid #f8fafc">
                <span class="fw-600 n" dir="ltr" style="color:#1e3a8a">${al.invoice_number}</span>
                <span style="color:#94a3b8;font-size:.72rem">${al.invoice_date}</span>
                <span class="n fw-600 text-success">$ ${parseFloat(al.allocated_amount).toFixed(2)}</span>
            </div>`).join('');
        document.getElementById('vBody').innerHTML=`
        <div class="row g-2 mb-3">
            <div class="col-6"><small style="color:#64748b">العميل</small><div class="fw-600">${r.customer_name||'—'}</div></div>
            <div class="col-6"><small style="color:#64748b">التاريخ</small><div>${r.receipt_date}</div></div>
            <div class="col-6"><small style="color:#64748b">طريقة الدفع</small>
                <div><span class="badge bg-secondary-subtle text-secondary">${PAY_METHOD[r.payment_method]||r.payment_method}</span></div></div>
            <div class="col-6"><small style="color:#64748b">الحالة</small>
                <div><span class="badge ${st.cls}">${st.label}</span></div></div>
        </div>
        <div style="background:#f0fdf4;border-radius:10px;padding:10px 14px;text-align:center;margin-bottom:12px">
            <div style="font-size:.75rem;color:#64748b">المبلغ المقبوض</div>
            <div class="n fw-600" style="font-size:1.4rem;color:#16a34a">${sym} ${parseFloat(r.amount).toFixed(2)}</div>
            ${r.currency!=='USD'?`<div class="n" style="font-size:.78rem;color:#94a3b8">= $ ${parseFloat(r.amount_usd).toFixed(2)}</div>`:''}
        </div>
        ${allocHtml?`<div style="font-size:.78rem;font-weight:700;color:#1e293b;margin-bottom:6px"><i class="bi bi-receipt me-1"></i>توزيع على الفواتير</div>${allocHtml}`:'<div style="font-size:.78rem;color:#94a3b8;text-align:center">بدون توزيع على فواتير</div>'}
        ${r.notes?`<div style="background:#f8fafc;border-radius:8px;padding:8px 12px;margin-top:10px;font-size:.78rem;color:#64748b">${r.notes}</div>`:''}
        ${r.status==='draft'?`<div style="margin-top:12px;display:flex;gap:8px">
            <button class="btn btn-sm fw-600" style="border-radius:8px;background:#16a34a;color:#fff;flex:1;font-size:.8rem"
                onclick="postReceipt(${r.id},'${r.receipt_number}')">
                <i class="bi bi-check-circle me-1"></i>ترحيل السند
            </button></div>`:''}`;
    });
}

function postReceipt(id,no){
    if(!confirm(`ترحيل السند "${no}"؟\nسيتم تحديث أرصدة الحسابات.`))return;
    post({_action:'post_receipt',id}).then(d=>{
        if(d.ok){toast('✅ '+d.msg);viewModal.hide();setTimeout(()=>location.reload(),700);}
        else toast(d.msg,'danger');
    });
}
function cancelReceipt(id,no){
    if(!confirm(`إلغاء السند "${no}"؟\nسيتم عكس تأثيره على الفواتير والحسابات.`))return;
    post({_action:'cancel_receipt',id}).then(d=>{
        if(d.ok){toast(d.msg);viewModal.hide();setTimeout(()=>location.reload(),700);}
        else toast(d.msg,'danger');
    });
}
</script>
</body>
</html>
