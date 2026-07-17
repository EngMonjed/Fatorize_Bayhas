<?php
/**
 * accounting/expenses.php — المصاريف التشغيلية
 * المسار: /bayhas/aleppo/modules/accounting/expenses.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('finance.expenses', 'view');
$currentModule = 'finance.expenses';

$TS  = $_SESSION['table_suffix'];
$TE  = "expenses_{$TS}";
$TAC = "account_charts_{$TS}";
$TJE = "journal_entries_{$TS}";
$TJI = "journal_entry_items_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

function genEntryNo(PDO $pdo, string $table): string {
    $y    = date('Y');
    $last = $pdo->query("SELECT entry_number FROM `{$table}`
        WHERE entry_number LIKE 'JE-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $seq  = $last ? (int)substr($last,-4)+1 : 1;
    return 'JE-'.$y.'-'.str_pad($seq,4,'0',STR_PAD_LEFT);
}

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        if ($act==='save_expense') {
            requirePermission('finance.expenses','create');
            $expAccId  = (int)$_POST['expense_account_id'];
            $cashAccId = (int)$_POST['cash_account_id'];
            $amtOrig   = (float)$_POST['amount_original'];
            $currency  = $_POST['currency'] ?? 'USD';
            $rate      = max(0.0001,(float)($_POST['exchange_rate']??1));
            $date      = $_POST['expense_date'] ?? date('Y-m-d');
            $desc      = trim($_POST['description']??'');

            if (!$expAccId)  throw new Exception('يجب اختيار حساب المصروف');
            if (!$cashAccId) throw new Exception('يجب اختيار حساب الدفع');
            if ($amtOrig<=0) throw new Exception('المبلغ يجب أن يكون أكبر من صفر');

            $amtUsd = $amtOrig / $rate;

            $pdo->beginTransaction();
            try {
                // إنشاء قيد محاسبي تلقائي
                $jeNo = genEntryNo($pdo,$TJE);
                $expAcc  = $pdo->query("SELECT * FROM `{$TAC}` WHERE id={$expAccId}")->fetch();
                $cashAcc = $pdo->query("SELECT * FROM `{$TAC}` WHERE id={$cashAccId}")->fetch();

                $pdo->prepare("INSERT INTO `{$TJE}` (entry_number,entry_date,description,currency,
                    exchange_rate,total_debit,total_credit,status,reference_type,created_by)
                    VALUES (?,?,?,?,?,?,?,'posted','expense',?)")
                    ->execute([$jeNo,$date,
                        ($desc?:($expAcc['name']??'مصروف')),
                        $currency,$rate,$amtUsd,$amtUsd,$_SESSION['user_id']]);
                $jeId=(int)$pdo->lastInsertId();

                // مدين: حساب المصروف
                $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,
                    original_amount,base_amount,description,currency,exchange_rate)
                    VALUES (?,?,?,0,?,?,?,?,?)")
                    ->execute([$jeId,$expAccId,$amtUsd,$amtOrig,$amtUsd,$desc,$currency,$rate]);
                $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,updated_at=NOW() WHERE id=?")
                    ->execute([$amtUsd,$expAccId]);

                // دائن: حساب الصندوق/الدفع
                $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,
                    original_amount,base_amount,description,currency,exchange_rate)
                    VALUES (?,?,0,?,?,?,?,?,?)")
                    ->execute([$jeId,$cashAccId,$amtUsd,$amtOrig,$amtUsd,$desc,$currency,$rate]);
                $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,updated_at=NOW() WHERE id=?")
                    ->execute([$amtUsd,$cashAccId]);

                // حفظ المصروف
                $pdo->prepare("INSERT INTO `{$TE}` (expense_account_id,cash_account_id,
                    amount_original,currency,exchange_rate,amount_usd,description,expense_date,
                    journal_entry_id,user_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$expAccId,$cashAccId,$amtOrig,$currency,$rate,
                        $amtUsd,$desc,$date,$jeId,$_SESSION['user_id']]);

                $pdo->commit();
                echo json_encode(['ok'=>true,'msg'=>'تم تسجيل المصروف والقيد المحاسبي']);
            } catch(Exception $e){ $pdo->rollBack(); throw $e; }
        }

        elseif ($act==='delete_expense') {
            requirePermission('finance.expenses','delete');
            $id=(int)$_POST['id'];
            $st=$pdo->prepare("SELECT * FROM `{$TE}` WHERE id=?");
            $st->execute([$id]); $exp=$st->fetch();
            if(!$exp) throw new Exception('المصروف غير موجود');

            $pdo->beginTransaction();
            try {
                // عكس القيد
                if($exp['journal_entry_id']){
                    $items=$pdo->prepare("SELECT * FROM `{$TJI}` WHERE journal_entry_id=?");
                    $items->execute([$exp['journal_entry_id']]);
                    foreach($items->fetchAll() as $item){
                        $net=$item['debit']-$item['credit'];
                        $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,updated_at=NOW() WHERE id=?")
                            ->execute([$net,$item['account_id']]);
                    }
                    $pdo->prepare("DELETE FROM `{$TJI}` WHERE journal_entry_id=?")->execute([$exp['journal_entry_id']]);
                    $pdo->prepare("DELETE FROM `{$TJE}` WHERE id=?")->execute([$exp['journal_entry_id']]);
                }
                $pdo->prepare("DELETE FROM `{$TE}` WHERE id=?")->execute([$id]);
                $pdo->commit();
                echo json_encode(['ok'=>true,'msg'=>'تم حذف المصروف وعكس القيد']);
            } catch(Exception $e){ $pdo->rollBack(); throw $e; }
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
$accF     = (int)($_GET['acc'] ?? 0);
$search   = trim($_GET['q'] ?? '');

$where='WHERE e.expense_date BETWEEN ? AND ?'; $params=[$dateFrom,$dateTo];
if($accF)   { $where.=' AND e.expense_account_id=?'; $params[]=$accF; }
if($search) { $where.=' AND e.description LIKE ?';   $params[]="%{$search}%"; }

$expenses=$pdo->prepare("SELECT e.*,
    ea.code AS exp_code, ea.name AS exp_name,
    ca.code AS cash_code, ca.name AS cash_name
    FROM `{$TE}` e
    JOIN `{$TAC}` ea ON ea.id=e.expense_account_id
    JOIN `{$TAC}` ca ON ca.id=e.cash_account_id
    {$where}
    ORDER BY e.expense_date DESC, e.id DESC LIMIT 200");
$expenses->execute($params);
$expenses=$expenses->fetchAll();

// حسابات المصاريف (نوع expense)
$expAccounts=$pdo->query("SELECT id,code,name,level FROM `{$TAC}`
    WHERE account_type='expense' AND is_active=1 ORDER BY code")->fetchAll();

// حسابات الصندوق والبنك (asset level>=3 رموز 111x,112x)
$cashAccounts=$pdo->query("SELECT id,code,name,currency FROM `{$TAC}`
    WHERE account_type='asset' AND is_active=1 AND level>=3
    AND (code LIKE '111%' OR code LIKE '112%') ORDER BY code")->fetchAll();

try {
    $stats=$pdo->query("SELECT
        COUNT(*) AS total,
        COALESCE(SUM(amount_usd),0) AS total_usd,
        COALESCE(SUM(CASE WHEN expense_date>=DATE_FORMAT(NOW(),'%Y-%m-01') THEN amount_usd END),0) AS month_usd,
        COALESCE(SUM(CASE WHEN expense_date=CURDATE() THEN amount_usd END),0) AS today_usd
        FROM `{$TE}`")->fetch();
} catch(Exception $e){ $stats=['total'=>0,'total_usd'=>0,'month_usd'=>0,'today_usd'=>0]; }
$CURR_SYM=['USD'=>'$','SYP'=>'ل.س','TRY'=>'₺','EUR'=>'€'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>المصاريف — <?= htmlspecialchars($branchName) ?></title>
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
table.mtbl tr:hover td{background:#fffbeb}
.act-btn{width:27px;height:27px;border-radius:7px;border:1px solid #e2e8f0;background:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;color:#64748b;cursor:pointer;transition:all .12s}
.act-btn:hover{background:#f1f5f9}
.act-btn.danger:hover{background:#fee2e2;color:#dc2626;border-color:#fca5a5}
.field-lbl{font-size:.76rem;font-weight:700;color:#475569;margin-bottom:4px;display:block}
.req{color:#dc2626} .n{font-variant-numeric:tabular-nums}
</style>
</head>
<body>
<div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
<?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
<header class="topbar">
    <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
    <span class="tb-title"><i class="bi bi-wallet2 me-1 text-warning"></i>المصاريف التشغيلية</span>
    <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
</header>
<main class="main-content"><div class="content-body">

<!-- إحصائيات -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fef3c7"><i class="bi bi-wallet2 text-warning"></i></div>
            <div><div class="stat-val"><?=$stats['total']?></div><div class="stat-lbl">إجمالي السجلات</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fee2e2"><i class="bi bi-calendar-day text-danger"></i></div>
            <div><div class="stat-val n">$ <?=number_format($stats['today_usd'],2)?></div><div class="stat-lbl">مصاريف اليوم</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fee2e2"><i class="bi bi-calendar-month text-danger"></i></div>
            <div><div class="stat-val n">$ <?=number_format($stats['month_usd'],2)?></div><div class="stat-lbl">مصاريف الشهر</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fee2e2"><i class="bi bi-currency-dollar text-danger"></i></div>
            <div><div class="stat-val n">$ <?=number_format($stats['total_usd'],2)?></div><div class="stat-lbl">الإجمالي الكلي</div></div>
        </div>
    </div>
</div>

<!-- فلاتر -->
<div class="tbl-wrap mb-3">
    <div class="tbl-hdr">
        <form method="get" class="d-flex gap-2 flex-wrap align-items-center w-100">
            <input type="text" name="q" value="<?=htmlspecialchars($search)?>"
                   placeholder="بحث في الوصف..." class="form-control form-control-sm" style="width:160px;border-radius:8px">
            <select name="acc" class="form-select form-select-sm" style="width:180px;border-radius:8px" onchange="this.form.submit()">
                <option value="">كل حسابات المصاريف</option>
                <?php foreach($expAccounts as $a): ?>
                <option value="<?=$a['id']?>" <?=$accF==$a['id']?'selected':''?>>
                    <?=str_repeat('  ',$a['level']-1)?><?=htmlspecialchars($a['code'].' — '.$a['name'])?>
                </option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="from" value="<?=htmlspecialchars($dateFrom)?>" class="form-control form-control-sm" style="width:140px;border-radius:8px">
            <span style="color:#94a3b8">—</span>
            <input type="date" name="to" value="<?=htmlspecialchars($dateTo)?>" class="form-control form-control-sm" style="width:140px;border-radius:8px">
            <button type="submit" class="btn btn-sm btn-primary" style="border-radius:8px"><i class="bi bi-search me-1"></i>بحث</button>
            <?php if($search||$accF): ?>
            <a href="?from=<?=$dateFrom?>&to=<?=$dateTo?>" class="btn btn-sm btn-light" style="border-radius:8px"><i class="bi bi-x-lg"></i></a>
            <?php endif; ?>
        </form>
        <button class="btn btn-sm fw-600" style="border-radius:8px;background:#d97706;color:#fff;font-size:.82rem;border:none;white-space:nowrap"
                onclick="openNew()">
            <i class="bi bi-plus-lg me-1"></i>تسجيل مصروف
        </button>
    </div>
</div>

<!-- الجدول -->
<div class="tbl-wrap">
    <div class="table-responsive">
    <table class="mtbl">
        <thead><tr>
            <th>التاريخ</th>
            <th>حساب المصروف</th>
            <th>الوصف</th>
            <th>حساب الدفع</th>
            <th>العملة</th>
            <th class="text-end">المبلغ</th>
            <th class="text-end">بالدولار ($)</th>
            <th>القيد</th>
            <th style="text-align:center">حذف</th>
        </tr></thead>
        <tbody>
        <?php if(empty($expenses)): ?>
        <tr><td colspan="9" class="text-center text-muted py-5">
            <i class="bi bi-wallet2 d-block mb-2" style="font-size:2rem;opacity:.2"></i>
            لا توجد مصاريف في هذه الفترة
        </td></tr>
        <?php endif; ?>
        <?php foreach($expenses as $exp):
            $sym=$CURR_SYM[$exp['currency']]??'$';
        ?>
        <tr>
            <td class="text-muted"><?=$exp['expense_date']?></td>
            <td>
                <div class="fw-600" style="font-size:.8rem"><?=htmlspecialchars($exp['exp_name'])?></div>
                <div style="font-size:.7rem;color:#94a3b8" dir="ltr"><?=htmlspecialchars($exp['exp_code'])?></div>
            </td>
            <td style="font-size:.8rem;color:#475569"><?=htmlspecialchars($exp['description']??'—')?></td>
            <td>
                <div style="font-size:.78rem"><?=htmlspecialchars($exp['cash_name'])?></div>
                <div style="font-size:.7rem;color:#94a3b8" dir="ltr"><?=htmlspecialchars($exp['cash_code'])?></div>
            </td>
            <td style="font-size:.75rem"><?=$exp['currency']?></td>
            <td class="n text-end fw-600"><?=$sym?> <?=number_format($exp['amount_original'],2)?></td>
            <td class="n text-end fw-600 text-danger">$ <?=number_format($exp['amount_usd'],2)?></td>
            <td>
                <?php if($exp['journal_entry_id']): ?>
                <span class="badge bg-success-subtle text-success" style="font-size:.65rem">
                    <i class="bi bi-check me-1"></i>قيد مرحّل
                </span>
                <?php else: ?>
                <span class="badge bg-secondary-subtle text-secondary" style="font-size:.65rem">—</span>
                <?php endif; ?>
            </td>
            <td>
                <div class="d-flex justify-content-center">
                    <button class="act-btn danger" onclick="deleteExpense(<?=$exp['id']?>)" title="حذف وعكس القيد">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if(!empty($expenses)): ?>
        <tfoot>
            <tr style="background:#fef9ee">
                <td colspan="6" class="fw-600 text-end" style="font-size:.8rem;color:#64748b">الإجمالي (الفترة المحددة)</td>
                <td class="n fw-600 text-end text-danger">
                    $ <?=number_format(array_sum(array_column($expenses,'amount_usd')),2)?>
                </td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
    </div>
</div>
</div></main>

<!-- مودال تسجيل مصروف -->
<div class="modal fade" id="expModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#92400e,#d97706);border-radius:16px 16px 0 0">
        <h6 class="modal-title text-white fw-700 mb-0">
            <i class="bi bi-wallet2 me-2"></i>تسجيل مصروف جديد
        </h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 pt-3">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="field-lbl">حساب المصروف <span class="req">*</span></label>
            <select id="eExpAcc" class="form-select form-select-sm">
                <option value="">— اختر حساب المصروف —</option>
                <?php foreach($expAccounts as $a): ?>
                <option value="<?=$a['id']?>">
                    <?=str_repeat('  ',$a['level']-1)?><?=htmlspecialchars($a['code'].' — '.$a['name'])?>
                </option>
                <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="field-lbl">حساب الدفع <span class="req">*</span></label>
            <select id="eCashAcc" class="form-select form-select-sm">
                <option value="">— الصندوق/البنك —</option>
                <?php foreach($cashAccounts as $a): ?>
                <option value="<?=$a['id']?>"><?=htmlspecialchars($a['code'].' — '.$a['name'])?></option>
                <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="field-lbl">التاريخ <span class="req">*</span></label>
            <input type="date" id="eDate" class="form-control form-control-sm" value="<?=date('Y-m-d')?>">
          </div>
          <div class="col-md-3">
            <label class="field-lbl">العملة</label>
            <select id="eCurr" class="form-select form-select-sm" onchange="onExpCurrChange()">
                <option value="USD">USD $</option>
                <option value="SYP">SYP ل.س</option>
                <option value="TRY">TRY ₺</option>
            </select>
          </div>
          <div class="col-md-2" id="eRateWrap">
            <label class="field-lbl">سعر الصرف</label>
            <input type="number" id="eRate" class="form-control form-control-sm" value="1" min="0.0001" step="0.01" dir="ltr" oninput="calcUsd()">
          </div>
          <div class="col-md-3">
            <label class="field-lbl">المبلغ <span class="req">*</span></label>
            <input type="number" id="eAmt" class="form-control form-control-sm fw-600" min="0" step="0.01"
                   dir="ltr" placeholder="0.00" oninput="calcUsd()">
          </div>
          <div class="col-12">
            <label class="field-lbl">وصف المصروف</label>
            <input type="text" id="eDesc" class="form-control form-control-sm" placeholder="مثال: فاتورة كهرباء شهر يونيو">
          </div>
          <div class="col-12">
            <div style="background:#fef9ee;border-radius:8px;padding:8px 14px;font-size:.78rem;display:flex;justify-content:space-between;align-items:center">
                <span style="color:#92400e"><i class="bi bi-info-circle me-1"></i>المبلغ بالدولار:</span>
                <span id="eUsdPreview" class="n fw-600 text-danger">$ 0.00</span>
            </div>
          </div>
          <div class="col-12">
            <div style="background:#f0fdf4;border-radius:8px;padding:8px 14px;font-size:.75rem;color:#065f46">
                <i class="bi bi-journal-check me-1"></i>
                سيتم إنشاء قيد محاسبي تلقائي ومرحّل فور الحفظ
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 px-4 pb-4">
        <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
        <button class="btn btn-sm fw-600" style="border-radius:8px;background:#d97706;color:#fff;min-width:120px;border:none"
                onclick="saveExpense()" id="btnSaveExp">
          <span id="saveExpTxt"><i class="bi bi-floppy me-1"></i>حفظ وترحيل</span>
          <span id="saveExpSpin" class="spinner-border spinner-border-sm" style="display:none"></span>
        </button>
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

const expModal=new bootstrap.Modal(document.getElementById('expModal'));

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

function openNew(){
    document.getElementById('eExpAcc').value='';
    document.getElementById('eCashAcc').value='';
    document.getElementById('eDate').value=new Date().toISOString().split('T')[0];
    document.getElementById('eCurr').value='USD';
    document.getElementById('eRate').value='1';
    document.getElementById('eAmt').value='';
    document.getElementById('eDesc').value='';
    document.getElementById('eUsdPreview').textContent='$ 0.00';
    expModal.show();
    setTimeout(()=>document.getElementById('eExpAcc').focus(),300);
}

function onExpCurrChange(){
    const isUSD=document.getElementById('eCurr').value==='USD';
    document.getElementById('eRateWrap').style.opacity=isUSD?.4:1;
    if(isUSD) document.getElementById('eRate').value='1';
    calcUsd();
}

function calcUsd(){
    const amt=parseFloat(document.getElementById('eAmt').value||0);
    const rate=parseFloat(document.getElementById('eRate').value||1);
    document.getElementById('eUsdPreview').textContent='$ '+(amt/rate).toFixed(2);
}

function saveExpense(){
    const expAcc=document.getElementById('eExpAcc').value;
    const cashAcc=document.getElementById('eCashAcc').value;
    const amt=parseFloat(document.getElementById('eAmt').value||0);
    if(!expAcc){toast('يجب اختيار حساب المصروف','danger');return;}
    if(!cashAcc){toast('يجب اختيار حساب الدفع','danger');return;}
    if(amt<=0){toast('يجب إدخال مبلغ أكبر من صفر','danger');return;}

    const btn=document.getElementById('btnSaveExp');
    document.getElementById('saveExpTxt').style.opacity='0';
    document.getElementById('saveExpSpin').style.display='inline-block';
    btn.disabled=true;

    post({
        _action:'save_expense',
        expense_account_id: expAcc,
        cash_account_id:    cashAcc,
        amount_original:    amt,
        currency:           document.getElementById('eCurr').value,
        exchange_rate:      document.getElementById('eRate').value,
        expense_date:       document.getElementById('eDate').value,
        description:        document.getElementById('eDesc').value,
    }).then(d=>{
        document.getElementById('saveExpTxt').style.opacity='1';
        document.getElementById('saveExpSpin').style.display='none';
        btn.disabled=false;
        if(d.ok){toast('✅ '+d.msg);expModal.hide();setTimeout(()=>location.reload(),700);}
        else toast(d.msg,'danger');
    });
}

function deleteExpense(id){
    if(!confirm('حذف هذا المصروف؟\nسيتم عكس القيد المحاسبي.'))return;
    post({_action:'delete_expense',id}).then(d=>{
        if(d.ok){toast(d.msg);setTimeout(()=>location.reload(),600);}
        else toast(d.msg,'danger');
    });
}
</script>
</body>
</html>
