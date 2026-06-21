<?php
/**
 * accounting/journal.php — القيود المحاسبية
 * المسار: /bayhas/aleppo/modules/accounting/journal.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('finance.journal', 'view');
$currentModule = 'finance.journal';

$TS  = $_SESSION['table_suffix'];
$TJE = "journal_entries_{$TS}";
$TJI = "journal_entry_items_{$TS}";
$TAC = "account_charts_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// ── توليد رقم القيد ──
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

        // ── حفظ قيد جديد ──
        if ($act==='save_entry') {
            requirePermission('finance.journal','create');
            $date   = $_POST['entry_date'] ?? date('Y-m-d');
            $desc   = trim($_POST['description'] ?? '');
            $curr   = $_POST['currency'] ?? 'USD';
            $rate   = max(0.0001,(float)($_POST['exchange_rate']??1));
            $lines  = json_decode($_POST['lines']??'[]',true);

            if (empty($lines)) throw new Exception('يجب إضافة سطر واحد على الأقل');

            $totD=0; $totC=0;
            foreach ($lines as $l) { $totD+=(float)$l['debit']; $totC+=(float)$l['credit']; }
            if (abs($totD-$totC)>0.01) throw new Exception('القيد غير متوازن — المدين: '.number_format($totD,2).' الدائن: '.number_format($totC,2));

            $no = genEntryNo($pdo,$TJE);
            $pdo->prepare("INSERT INTO `{$TJE}` (entry_number,entry_date,description,currency,exchange_rate,total_debit,total_credit,status,created_by)
                VALUES (?,?,?,?,?,?,?,'draft',?)")
                ->execute([$no,$date,$desc,$curr,$rate,$totD,$totC,$_SESSION['user_id']]);
            $jeId=(int)$pdo->lastInsertId();

            foreach ($lines as $l) {
                $accId=(int)$l['account_id'];
                if (!$accId) continue;
                $d=(float)$l['debit']; $c=(float)$l['credit'];
                $origAmt=$d>0?$d:$c;
                $baseAmt=$origAmt/$rate;
                $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency,exchange_rate)
                    VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$jeId,$accId,$d,$c,$origAmt,$baseAmt,$l['desc']??'',$curr,$rate]);
            }
            echo json_encode(['ok'=>true,'id'=>$jeId,'no'=>$no,'msg'=>'تم حفظ القيد كمسودة']);
        }

        // ── ترحيل قيد ──
        elseif ($act==='post_entry') {
            requirePermission('finance.journal','confirm');
            $id=(int)$_POST['id'];
            $st=$pdo->prepare("SELECT * FROM `{$TJE}` WHERE id=?");
            $st->execute([$id]); $je=$st->fetch();
            if (!$je) throw new Exception('القيد غير موجود');
            if ($je['status']!=='draft') throw new Exception('يمكن ترحيل المسودات فقط');

            $pdo->beginTransaction();
            try {
                // تحديث أرصدة الحسابات
                $items=$pdo->prepare("SELECT * FROM `{$TJI}` WHERE journal_entry_id=?");
                $items->execute([$id]);
                foreach ($items->fetchAll() as $item) {
                    $net = $item['debit'] - $item['credit'];
                    $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,updated_by=?,updated_at=NOW() WHERE id=?")
                        ->execute([$net,$_SESSION['user_id'],$item['account_id']]);
                }
                $pdo->prepare("UPDATE `{$TJE}` SET status='posted',posted_at=NOW(),posted_by=?,updated_by=?,updated_at=NOW() WHERE id=?")
                    ->execute([$_SESSION['user_id'],$_SESSION['user_id'],$id]);
                $pdo->commit();
                echo json_encode(['ok'=>true,'msg'=>'تم ترحيل القيد وتحديث الأرصدة']);
            } catch(Exception $e){ $pdo->rollBack(); throw $e; }
        }

        // ── إلغاء قيد ──
        elseif ($act==='cancel_entry') {
            requirePermission('finance.journal','edit');
            $id=(int)$_POST['id'];
            $st=$pdo->prepare("SELECT * FROM `{$TJE}` WHERE id=?");
            $st->execute([$id]); $je=$st->fetch();
            if (!$je) throw new Exception('القيد غير موجود');
            if ($je['status']==='cancelled') throw new Exception('ملغى مسبقاً');

            $pdo->beginTransaction();
            try {
                // عكس الأرصدة إذا كان مرحّلاً
                if ($je['status']==='posted') {
                    $items=$pdo->prepare("SELECT * FROM `{$TJI}` WHERE journal_entry_id=?");
                    $items->execute([$id]);
                    foreach ($items->fetchAll() as $item) {
                        $net = $item['debit'] - $item['credit'];
                        $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,updated_at=NOW() WHERE id=?")
                            ->execute([$net,$item['account_id']]);
                    }
                }
                $pdo->prepare("UPDATE `{$TJE}` SET status='cancelled',cancelled_at=NOW(),cancelled_by=?,updated_by=?,updated_at=NOW() WHERE id=?")
                    ->execute([$_SESSION['user_id'],$_SESSION['user_id'],$id]);
                $pdo->commit();
                echo json_encode(['ok'=>true,'msg'=>'تم إلغاء القيد']);
            } catch(Exception $e){ $pdo->rollBack(); throw $e; }
        }

        // ── جلب قيد ──
        elseif ($act==='get_entry') {
            $id=(int)$_POST['id'];
            $st=$pdo->prepare("SELECT je.* FROM `{$TJE}` je WHERE je.id=?");
            $st->execute([$id]); $je=$st->fetch();
            if (!$je) throw new Exception('القيد غير موجود');
            $items=$pdo->prepare("SELECT ji.*,ac.code,ac.name AS acc_name,ac.account_type
                FROM `{$TJI}` ji JOIN `{$TAC}` ac ON ac.id=ji.account_id
                WHERE ji.journal_entry_id=? ORDER BY ji.id");
            $items->execute([$id]);
            $je['items']=$items->fetchAll();
            echo json_encode(['ok'=>true,'data'=>$je]);
        }

        else throw new Exception('إجراء غير معروف');
    } catch(Exception $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

// ── بيانات الصفحة ──
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo   = $_GET['to']   ?? date('Y-m-d');
$statusF  = $_GET['status'] ?? '';
$search   = trim($_GET['q'] ?? '');

$where='WHERE je.entry_date BETWEEN ? AND ?'; $params=[$dateFrom,$dateTo];
if ($statusF) { $where.=' AND je.status=?'; $params[]=$statusF; }
if ($search)  { $where.=' AND (je.entry_number LIKE ? OR je.description LIKE ?)'; $params[]="%{$search}%"; $params[]="%{$search}%"; }

$entries=$pdo->prepare("SELECT je.*,
    COUNT(ji.id) AS lines_count
    FROM `{$TJE}` je
    LEFT JOIN `{$TJI}` ji ON ji.journal_entry_id=je.id
    {$where}
    GROUP BY je.id ORDER BY je.entry_date DESC, je.id DESC LIMIT 200");
$entries->execute($params);
$entries=$entries->fetchAll();

$accounts=$pdo->query("SELECT id,code,name,account_type,currency,is_active
    FROM `{$TAC}` WHERE is_active=1 ORDER BY code")->fetchAll();

try {
    $stats=$pdo->query("SELECT
        COUNT(*) AS total,
        SUM(status='draft') AS drafts,
        SUM(status='posted') AS posted,
        COALESCE(SUM(CASE WHEN status='posted' THEN total_debit END),0) AS total_posted
        FROM `{$TJE}`")->fetch();
} catch(Exception $e){ $stats=['total'=>0,'drafts'=>0,'posted'=>0,'total_posted'=>0]; }

$STATUS_MAP=[
    'draft'     =>['label'=>'مسودة',  'cls'=>'bg-secondary-subtle text-secondary'],
    'posted'    =>['label'=>'مرحّل',  'cls'=>'bg-success-subtle text-success'],
    'cancelled' =>['label'=>'ملغى',   'cls'=>'bg-danger-subtle text-danger'],
];
$TYPE_COLOR=['asset'=>'#1e3a8a','liability'=>'#dc2626','equity'=>'#7c3aed','revenue'=>'#16a34a','expense'=>'#d97706'];
$entryNo=genEntryNo($pdo,$TJE);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>القيود المحاسبية — <?= htmlspecialchars($branchName) ?></title>
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
table.mtbl tr:hover td{background:#f8faff}
.act-btn{width:27px;height:27px;border-radius:7px;border:1px solid #e2e8f0;background:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;color:#64748b;cursor:pointer;transition:all .12s}
.act-btn:hover{background:#f1f5f9}
.act-btn.success-h:hover{background:#dcfce7;color:#16a34a;border-color:#86efac}
.act-btn.danger:hover{background:#fee2e2;color:#dc2626;border-color:#fca5a5}
.field-lbl{font-size:.76rem;font-weight:700;color:#475569;margin-bottom:4px;display:block}
.req{color:#dc2626}
.n{font-variant-numeric:tabular-nums}
/* بنود القيد */
.jline{display:grid;grid-template-columns:2fr 1fr 1fr minmax(0,1.2fr) 24px;gap:6px;align-items:center;margin-bottom:5px}
.jline input,.jline select{font-size:.78rem;padding:4px 7px;border:1px solid #e2e8f0;border-radius:6px;width:100%}
.jline input.mdb{background:#eff6ff;border-color:#bfdbfe;font-weight:600}
.jline input.crd{background:#f0fdf4;border-color:#bbf7d0;font-weight:600}
.del-jl{width:24px;height:24px;border-radius:6px;border:1px solid #fca5a5;background:#fff;color:#dc2626;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.72rem}
.balance-ok{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:6px 12px;font-size:.78rem;color:#16a34a;font-weight:600}
.balance-err{background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:6px 12px;font-size:.78rem;color:#dc2626;font-weight:600}
/* مودال عرض القيد */
.t-account{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:#e2e8f0;border-radius:10px;overflow:hidden}
.t-side{background:#fff;padding:10px}
.t-hdr{font-size:.72rem;font-weight:700;padding:6px 10px;text-align:center}
</style>
</head>
<body>
<div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
<?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
<header class="topbar">
    <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
    <span class="tb-title"><i class="bi bi-journal-bookmark me-1 text-primary"></i>القيود المحاسبية</span>
    <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
</header>
<main class="main-content"><div class="content-body">

<!-- إحصائيات -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#eff6ff"><i class="bi bi-journal-bookmark text-primary"></i></div>
            <div><div class="stat-val"><?=$stats['total']?></div><div class="stat-lbl">إجمالي القيود</div></div>
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
            <div class="stat-icon" style="background:#eff6ff"><i class="bi bi-currency-dollar text-primary"></i></div>
            <div><div class="stat-val n">$ <?=number_format($stats['total_posted'],2)?></div><div class="stat-lbl">إجمالي المرحّل</div></div>
        </div>
    </div>
</div>

<!-- فلاتر -->
<div class="tbl-wrap mb-3">
    <div class="tbl-hdr">
        <form method="get" class="d-flex gap-2 flex-wrap align-items-center w-100">
            <input type="text" name="q" value="<?=htmlspecialchars($search)?>"
                   placeholder="رقم القيد أو الوصف..."
                   class="form-control form-control-sm" style="width:180px;border-radius:8px">
            <select name="status" class="form-select form-select-sm" style="width:120px;border-radius:8px" onchange="this.form.submit()">
                <option value="">كل الحالات</option>
                <?php foreach ($STATUS_MAP as $k=>$v): ?>
                <option value="<?=$k?>" <?=$statusF===$k?'selected':''?>><?=$v['label']?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="from" value="<?=htmlspecialchars($dateFrom)?>" class="form-control form-control-sm" style="width:140px;border-radius:8px">
            <span style="color:#94a3b8">—</span>
            <input type="date" name="to" value="<?=htmlspecialchars($dateTo)?>" class="form-control form-control-sm" style="width:140px;border-radius:8px">
            <button type="submit" class="btn btn-sm btn-primary" style="border-radius:8px"><i class="bi bi-search me-1"></i>بحث</button>
            <?php if($search||$statusF): ?>
            <a href="?from=<?=$dateFrom?>&to=<?=$dateTo?>" class="btn btn-sm btn-light" style="border-radius:8px"><i class="bi bi-x-lg"></i></a>
            <?php endif; ?>
        </form>
        <button class="btn btn-sm fw-600" style="border-radius:8px;background:#1e3a8a;color:#fff;font-size:.82rem;white-space:nowrap"
                onclick="openNewEntry()">
            <i class="bi bi-plus-lg me-1"></i>قيد جديد
        </button>
    </div>
</div>

<!-- جدول القيود -->
<div class="tbl-wrap">
    <div class="table-responsive">
    <table class="mtbl">
        <thead><tr>
            <th>رقم القيد</th><th>التاريخ</th><th>الوصف</th>
            <th>العملة</th><th class="text-center">الأسطر</th>
            <th class="text-end">المدين ($)</th>
            <th class="text-end">الدائن ($)</th>
            <th>المرجع</th><th>الحالة</th>
            <th style="text-align:center">إجراءات</th>
        </tr></thead>
        <tbody>
        <?php if(empty($entries)): ?>
        <tr><td colspan="10" class="text-center text-muted py-5">
            <i class="bi bi-journal-bookmark d-block mb-2" style="font-size:2rem;opacity:.2"></i>
            لا توجد قيود في هذه الفترة
        </td></tr>
        <?php endif; ?>
        <?php foreach($entries as $je):
            $st=$STATUS_MAP[$je['status']]??$STATUS_MAP['draft'];
        ?>
        <tr>
            <td class="n fw-600" style="direction:ltr;color:#1e3a8a"><?=htmlspecialchars($je['entry_number'])?></td>
            <td class="text-muted"><?=$je['entry_date']?></td>
            <td style="font-size:.8rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?=htmlspecialchars($je['description']??'—')?>
            </td>
            <td style="font-size:.75rem"><?=$je['currency']?></td>
            <td class="text-center"><span class="badge bg-secondary-subtle text-secondary"><?=$je['lines_count']?></span></td>
            <td class="n text-end fw-600 text-primary">$ <?=number_format($je['total_debit'],2)?></td>
            <td class="n text-end fw-600 text-success">$ <?=number_format($je['total_credit'],2)?></td>
            <td style="font-size:.72rem;color:#94a3b8"><?=$je['reference_type']?? '—'?></td>
            <td><span class="badge <?=$st['cls']?>" style="font-size:.68rem"><?=$st['label']?></span></td>
            <td>
                <div class="d-flex gap-1 justify-content-center">
                    <button class="act-btn info-h" onclick="viewEntry(<?=$je['id']?>)" title="عرض"
                            style="color:#0891b2" onmouseover="this.style.background='#e0f2fe'" onmouseout="this.style.background='#fff'">
                        <i class="bi bi-eye"></i>
                    </button>
                    <?php if($je['status']==='draft'): ?>
                    <button class="act-btn success-h" onclick="postEntry(<?=$je['id']?>,'<?=htmlspecialchars($je['entry_number'],ENT_QUOTES)?>')" title="ترحيل">
                        <i class="bi bi-check-circle"></i>
                    </button>
                    <?php endif; ?>
                    <?php if($je['status']!=='cancelled'): ?>
                    <button class="act-btn danger" onclick="cancelEntry(<?=$je['id']?>,'<?=htmlspecialchars($je['entry_number'],ENT_QUOTES)?>')" title="إلغاء">
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

<!-- مودال قيد جديد -->
<div class="modal fade" id="entryModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#0c447c,#1e3a8a);border-radius:16px 16px 0 0">
        <div>
          <h6 class="modal-title text-white fw-700 mb-0"><i class="bi bi-journal-plus me-2"></i>إضافة قيد محاسبي</h6>
          <div style="font-size:.72rem;color:rgba(255,255,255,.7);margin-top:2px" dir="ltr"><?=htmlspecialchars($entryNo)?></div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 pt-3">
        <!-- رأس القيد -->
        <div class="row g-3 mb-3 pb-3" style="border-bottom:1px solid #f1f5f9">
          <div class="col-md-3">
            <label class="field-lbl">تاريخ القيد <span class="req">*</span></label>
            <input type="date" id="eDate" class="form-control form-control-sm" value="<?=date('Y-m-d')?>">
          </div>
          <div class="col-md-3">
            <label class="field-lbl">العملة</label>
            <select id="eCurr" class="form-select form-select-sm" onchange="onCurrencyChange()">
                <option value="USD">USD — دولار</option>
                <option value="SYP">SYP — ليرة سورية</option>
                <option value="TRY">TRY — ليرة تركية</option>
            </select>
          </div>
          <div class="col-md-2" id="rateWrap">
            <label class="field-lbl">سعر الصرف vs $</label>
            <input type="number" id="eRate" class="form-control form-control-sm" value="1" min="0.0001" step="0.0001" dir="ltr" onchange="recalcAll()">
          </div>
          <div class="col-md-4">
            <label class="field-lbl">وصف القيد</label>
            <input type="text" id="eDesc" class="form-control form-control-sm" placeholder="مثال: قيد فاتورة شراء رقم...">
          </div>
        </div>

        <!-- بنود القيد -->
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span style="font-size:.82rem;font-weight:700;color:#1e293b">
            <i class="bi bi-list-columns me-1 text-primary"></i>بنود القيد
          </span>
          <div class="d-flex gap-2 align-items-center">
            <div id="balanceIndicator" class="balance-err">غير متوازن — فارق: $ 0.00</div>
            <button class="btn btn-sm" style="border-radius:8px;border:1px solid #1e3a8a;color:#1e3a8a;font-size:.76rem"
                    onclick="addLine()"><i class="bi bi-plus me-1"></i>إضافة سطر</button>
          </div>
        </div>
        <!-- رؤوس الأعمدة -->
        <div class="jline mb-1" style="background:transparent">
          <span style="font-size:.71rem;color:#64748b;font-weight:600;padding-right:4px">الحساب</span>
          <span style="font-size:.71rem;color:#1e3a8a;font-weight:600;text-align:center">مدين</span>
          <span style="font-size:.71rem;color:#16a34a;font-weight:600;text-align:center">دائن</span>
          <span style="font-size:.71rem;color:#64748b;font-weight:600">بيان</span>
          <span></span>
        </div>
        <div id="journalLines"></div>
        <!-- مجاميع -->
        <div class="row justify-content-end mt-3">
          <div class="col-md-4">
            <div style="background:#f8fafc;border-radius:10px;padding:10px 14px">
              <div class="d-flex justify-content-between mb-1">
                <span style="font-size:.78rem;color:#64748b">إجمالي المدين</span>
                <span id="totDebit" class="n fw-600 text-primary" style="font-size:.82rem">$ 0.00</span>
              </div>
              <div class="d-flex justify-content-between">
                <span style="font-size:.78rem;color:#64748b">إجمالي الدائن</span>
                <span id="totCredit" class="n fw-600 text-success" style="font-size:.82rem">$ 0.00</span>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 px-4 pb-4">
        <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
        <button class="btn btn-sm fw-600" style="border-radius:8px;border:1px solid #1e3a8a;color:#1e3a8a;font-size:.82rem"
                onclick="saveAndPost()">
            <i class="bi bi-check-circle me-1"></i>حفظ وترحيل فوراً
        </button>
        <button class="btn btn-sm fw-600" style="border-radius:8px;background:#1e3a8a;color:#fff;min-width:110px"
                onclick="saveEntry(false)" id="btnSaveEntry">
          <span id="saveEntryTxt"><i class="bi bi-floppy me-1"></i>حفظ كمسودة</span>
          <span id="saveEntrySpin" class="spinner-border spinner-border-sm" style="display:none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- مودال عرض القيد -->
<div class="modal fade" id="viewModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#0c447c,#1e3a8a);border-radius:16px 16px 0 0">
        <h6 class="modal-title text-white fw-700 mb-0" id="vTitle">تفاصيل القيد</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 py-3" id="vBody">
        <div class="text-center py-4"><span class="spinner-border text-primary"></span></div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Sidebar ──
const sb=document.getElementById('sidebar'),ov=document.getElementById('sbOverlay');
function sbOpen(){sb.classList.add('open');ov.classList.add('show');}
function sbClose(){sb.classList.remove('open');ov.classList.remove('show');}
window.addEventListener('resize',()=>{if(window.innerWidth>991)sbClose();});
function toggleGroup(g){const o=g.classList.contains('open');document.querySelectorAll('.sb-group.open').forEach(x=>x.classList.remove('open'));g.classList.toggle('open',!o);localStorage.setItem('sb_open_'+g.dataset.key,(!o).toString());}
document.querySelectorAll('.sb-group').forEach(g=>{if(localStorage.getItem('sb_open_'+g.dataset.key)==='true')g.classList.add('open');});

const entryModal = new bootstrap.Modal(document.getElementById('entryModal'));
const viewModal  = new bootstrap.Modal(document.getElementById('viewModal'));
const STATUS_MAP = <?=json_encode($STATUS_MAP)?>;
const TYPE_COLOR = <?=json_encode($TYPE_COLOR)?>;
const ACCOUNTS   = <?=json_encode(array_values($accounts))?>;
var lines=[];

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

// ── العملة ──
function onCurrencyChange(){
    const isUSD=document.getElementById('eCurr').value==='USD';
    document.getElementById('rateWrap').style.opacity=isUSD?.5:1;
    if(isUSD) document.getElementById('eRate').value='1';
    recalcAll();
}

// ── أسطر القيد ──
function openNewEntry(){
    lines=[];
    document.getElementById('journalLines').innerHTML='';
    document.getElementById('eDate').value=new Date().toISOString().split('T')[0];
    document.getElementById('eDesc').value='';
    document.getElementById('eCurr').value='USD';
    document.getElementById('eRate').value='1';
    updateTotals();
    entryModal.show();
    addLine(); addLine(); // سطرين افتراضيان
}

function buildAccOptions(selVal){
    let html='<option value="">— اختر الحساب —</option>';
    ACCOUNTS.forEach(a=>{
        const indent='　'.repeat(a.level-1);
        const sel=a.id==selVal?'selected':'';
        html+=`<option value="${a.id}" data-type="${a.account_type}" ${sel}>${indent}${a.code} — ${a.name}</option>`;
    });
    return html;
}

var lineIdx=0;
function addLine(accId='',debit='',credit='',desc=''){
    const idx=lineIdx++;
    const div=document.createElement('div');
    div.className='jline';div.id='jl_'+idx;
    div.innerHTML=`
        <select class="jl-acc" id="jlacc_${idx}" onchange="onAccChange(${idx})">
            ${buildAccOptions(accId)}
        </select>
        <input type="number" class="jl-d mdb" id="jld_${idx}" min="0" step="0.01"
               value="${debit}" dir="ltr" placeholder="0.00"
               oninput="onAmtInput(${idx},'d')">
        <input type="number" class="jl-c crd" id="jlc_${idx}" min="0" step="0.01"
               value="${credit}" dir="ltr" placeholder="0.00"
               oninput="onAmtInput(${idx},'c')">
        <input type="text" class="jl-desc" id="jldesc_${idx}" value="${desc}" placeholder="بيان">
        <button class="del-jl" onclick="removeLine(${idx})"><i class="bi bi-x-lg"></i></button>`;
    document.getElementById('journalLines').appendChild(div);
    lines.push({idx,account_id:accId,debit:debit||0,credit:credit||0,desc});
}

function onAccChange(idx){
    const sel=document.getElementById('jlacc_'+idx);
    const l=lines.find(l=>l.idx===idx);
    if(l) l.account_id=sel.value;
}
function onAmtInput(idx,type){
    const dEl=document.getElementById('jld_'+idx);
    const cEl=document.getElementById('jlc_'+idx);
    if(type==='d'&&parseFloat(dEl.value||0)>0) cEl.value='';
    if(type==='c'&&parseFloat(cEl.value||0)>0) dEl.value='';
    const l=lines.find(l=>l.idx===idx);
    if(l){ l.debit=parseFloat(dEl.value||0); l.credit=parseFloat(cEl.value||0); }
    recalcAll();
}
function removeLine(idx){
    const el=document.getElementById('jl_'+idx);
    if(el)el.remove();
    lines=lines.filter(l=>l.idx!==idx);
    recalcAll();
}

function recalcAll(){
    // تحديث القيم من الـ DOM
    lines.forEach(l=>{
        const d=document.getElementById('jld_'+l.idx);
        const c=document.getElementById('jlc_'+l.idx);
        if(d) l.debit=parseFloat(d.value||0);
        if(c) l.credit=parseFloat(c.value||0);
        const acc=document.getElementById('jlacc_'+l.idx);
        if(acc) l.account_id=acc.value;
    });
    updateTotals();
}
function updateTotals(){
    const totD=lines.reduce((s,l)=>s+l.debit,0);
    const totC=lines.reduce((s,l)=>s+l.credit,0);
    const diff=Math.abs(totD-totC);
    document.getElementById('totDebit').textContent='$ '+totD.toFixed(2);
    document.getElementById('totCredit').textContent='$ '+totC.toFixed(2);
    const bi=document.getElementById('balanceIndicator');
    if(diff<0.01){
        bi.className='balance-ok';
        bi.textContent='✓ القيد متوازن';
    } else {
        bi.className='balance-err';
        bi.textContent='فارق: $ '+diff.toFixed(2);
    }
}

function getLines(){
    recalcAll();
    return lines.map(l=>{
        const acc=document.getElementById('jlacc_'+l.idx);
        const desc=document.getElementById('jldesc_'+l.idx);
        return {account_id:acc?acc.value:l.account_id,
                debit:l.debit,credit:l.credit,
                desc:desc?desc.value:l.desc};
    }).filter(l=>l.account_id&&(l.debit>0||l.credit>0));
}

var _postAfterSave=false;
function saveAndPost(){ _postAfterSave=true; saveEntry(true); }

function saveEntry(andPost){
    const validLines=getLines();
    if(!validLines.length){toast('يجب إضافة سطر واحد على الأقل','danger');return;}

    const totD=validLines.reduce((s,l)=>s+l.debit,0);
    const totC=validLines.reduce((s,l)=>s+l.credit,0);
    if(Math.abs(totD-totC)>0.01){
        toast('القيد غير متوازن — فارق: $'+Math.abs(totD-totC).toFixed(2),'danger');return;
    }

    const btn=document.getElementById('btnSaveEntry');
    document.getElementById('saveEntryTxt').style.opacity='0';
    document.getElementById('saveEntrySpin').style.display='inline-block';
    btn.disabled=true;

    post({
        _action:'save_entry',
        entry_date:   document.getElementById('eDate').value,
        description:  document.getElementById('eDesc').value,
        currency:     document.getElementById('eCurr').value,
        exchange_rate:document.getElementById('eRate').value,
        lines:        JSON.stringify(validLines),
    }).then(d=>{
        document.getElementById('saveEntryTxt').style.opacity='1';
        document.getElementById('saveEntrySpin').style.display='none';
        btn.disabled=false;
        if(!d.ok){toast(d.msg,'danger');return;}
        if(andPost){
            post({_action:'post_entry',id:d.id}).then(p=>{
                if(p.ok){toast('✅ تم حفظ القيد وترحيله — '+d.no);entryModal.hide();setTimeout(()=>location.reload(),800);}
                else toast(p.msg,'danger');
            });
        } else {
            toast('✅ '+d.msg+' — '+d.no);entryModal.hide();setTimeout(()=>location.reload(),800);
        }
    });
}

// ── عرض القيد ──
function viewEntry(id){
    document.getElementById('vTitle').textContent='جارٍ التحميل...';
    document.getElementById('vBody').innerHTML='<div class="text-center py-4"><span class="spinner-border text-primary"></span></div>';
    viewModal.show();
    post({_action:'get_entry',id}).then(d=>{
        if(!d.ok){document.getElementById('vBody').innerHTML=`<div class="text-danger p-3">${d.msg}</div>`;return;}
        const je=d.data;
        const st=STATUS_MAP[je.status]||STATUS_MAP['draft'];
        document.getElementById('vTitle').textContent='قيد: '+je.entry_number;

        const debitRows=(je.items||[]).filter(i=>i.debit>0).map(i=>`
            <div style="display:flex;justify-content:space-between;font-size:.8rem;padding:4px 0;border-bottom:1px solid #f1f5f9">
                <div>
                    <span style="color:${TYPE_COLOR[i.account_type]||'#1e293b'};font-weight:600;font-size:.72rem;direction:ltr">${i.code}</span>
                    <span style="margin-right:6px">${i.acc_name}</span>
                    ${i.description?`<span style="color:#94a3b8;font-size:.72rem">— ${i.description}</span>`:''}
                </div>
                <span class="n fw-600 text-primary">$ ${parseFloat(i.debit).toFixed(2)}</span>
            </div>`).join('');

        const creditRows=(je.items||[]).filter(i=>i.credit>0).map(i=>`
            <div style="display:flex;justify-content:space-between;font-size:.8rem;padding:4px 0;border-bottom:1px solid #f1f5f9">
                <div>
                    <span style="color:${TYPE_COLOR[i.account_type]||'#1e293b'};font-weight:600;font-size:.72rem;direction:ltr">${i.code}</span>
                    <span style="margin-right:6px">${i.acc_name}</span>
                    ${i.description?`<span style="color:#94a3b8;font-size:.72rem">— ${i.description}</span>`:''}
                </div>
                <span class="n fw-600 text-success">$ ${parseFloat(i.credit).toFixed(2)}</span>
            </div>`).join('');

        document.getElementById('vBody').innerHTML=`
        <div class="row g-2 mb-3">
            <div class="col-md-3"><small style="color:#64748b">رقم القيد</small><div class="fw-600 n" dir="ltr">${je.entry_number}</div></div>
            <div class="col-md-3"><small style="color:#64748b">التاريخ</small><div>${je.entry_date}</div></div>
            <div class="col-md-3"><small style="color:#64748b">العملة</small><div>${je.currency} (x${parseFloat(je.exchange_rate).toFixed(4)})</div></div>
            <div class="col-md-3"><small style="color:#64748b">الحالة</small><div><span class="badge ${st.cls}">${st.label}</span></div></div>
            ${je.description?`<div class="col-12"><small style="color:#64748b">الوصف</small><div style="font-size:.82rem">${je.description}</div></div>`:''}
        </div>
        <div class="t-account">
            <div class="t-side">
                <div class="t-hdr" style="background:#eff6ff;color:#1e3a8a">مدين</div>
                <div style="padding:8px 4px">${debitRows||'<div class="text-muted text-center py-2" style="font-size:.78rem">لا توجد بنود</div>'}</div>
                <div style="padding:4px 8px;text-align:left;font-weight:700;color:#1e3a8a;font-size:.82rem;border-top:1px solid #f1f5f9">
                    $ ${parseFloat(je.total_debit).toFixed(2)}
                </div>
            </div>
            <div class="t-side">
                <div class="t-hdr" style="background:#f0fdf4;color:#16a34a">دائن</div>
                <div style="padding:8px 4px">${creditRows||'<div class="text-muted text-center py-2" style="font-size:.78rem">لا توجد بنود</div>'}</div>
                <div style="padding:4px 8px;text-align:left;font-weight:700;color:#16a34a;font-size:.82rem;border-top:1px solid #f1f5f9">
                    $ ${parseFloat(je.total_credit).toFixed(2)}
                </div>
            </div>
        </div>
        ${je.status==='draft'?`<div style="margin-top:12px;display:flex;gap:8px">
            <button class="btn btn-sm fw-600" style="border-radius:8px;background:#16a34a;color:#fff;flex:1;font-size:.8rem"
                onclick="postEntry(${je.id},'${je.entry_number}')">
                <i class="bi bi-check-circle me-1"></i>ترحيل القيد
            </button>
            <button class="btn btn-sm fw-600" style="border-radius:8px;border:1px solid #dc2626;color:#dc2626;flex:1;font-size:.8rem"
                onclick="cancelEntry(${je.id},'${je.entry_number}')">
                <i class="bi bi-x-circle me-1"></i>إلغاء القيد
            </button>
        </div>`:''}
        ${je.reference_type?`<div style="margin-top:8px;font-size:.72rem;color:#94a3b8"><i class="bi bi-link me-1"></i>مرجع: ${je.reference_type} #${je.reference_id||''}</div>`:''}`;
    });
}

// ── ترحيل / إلغاء ──
function postEntry(id,no){
    if(!confirm(`ترحيل القيد "${no}"؟\nسيتم تحديث أرصدة الحسابات.`))return;
    post({_action:'post_entry',id}).then(d=>{
        if(d.ok){toast('✅ '+d.msg);viewModal.hide();setTimeout(()=>location.reload(),700);}
        else toast(d.msg,'danger');
    });
}
function cancelEntry(id,no){
    if(!confirm(`إلغاء القيد "${no}"؟\n${no.includes('posted')?'سيتم عكس الأرصدة.':''}`))return;
    post({_action:'cancel_entry',id}).then(d=>{
        if(d.ok){toast(d.msg);viewModal.hide();setTimeout(()=>location.reload(),700);}
        else toast(d.msg,'danger');
    });
}
</script>
</body>
</html>
