<?php
/**
 * accounting/currencies.php — إدارة العملات
 * المسار: /bayhas/aleppo/modules/accounting/currencies.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('finance.accounts', 'view');
$currentModule = 'finance.accounts';
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// ── AJAX ──
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        // ── حفظ عملة ──
        if ($act==='save_currency') {
            requirePermission('finance.accounts','edit');
            $id     = (int)($_POST['id']??0);
            $code   = strtoupper(trim($_POST['code']??''));
            $name   = trim($_POST['name']??'');
            $symbol = trim($_POST['symbol']??'');
            $rate   = max(0.0001,(float)($_POST['exchange_rate']??1));
            $status = $_POST['status']??'active';

            if (!$code || !$name) throw new Exception('الكود والاسم مطلوبان');
            if (strlen($code)>3) throw new Exception('كود العملة 3 أحرف كحد أقصى');

            $TS2 = $_SESSION['table_suffix'];
            $TAC2= "account_charts_{$TS2}";
            $TIAS2="invoice_account_settings_{$TS2}";

            if ($id) {
                $pdo->prepare("UPDATE currencies SET name=?,symbol=?,exchange_rate=?,status=? WHERE id=?")
                    ->execute([$name,$symbol,$rate,$status,$id]);
                echo json_encode(['ok'=>true,'id'=>$id]);
            } else {
                $dup=$pdo->prepare("SELECT COUNT(*) FROM currencies WHERE code=?");
                $dup->execute([$code]);
                if ($dup->fetchColumn()) throw new Exception("الكود {$code} مستخدم مسبقاً");
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("INSERT INTO currencies (code,name,symbol,exchange_rate,status) VALUES (?,?,?,?,?)")
                        ->execute([$code,$name,$symbol,$rate,$status]);
                    $id=(int)$pdo->lastInsertId();

                    // ── إنشاء حسابات الصندوق والبنك تلقائياً ──
                    // جلب عدد الصناديق الموجودة لتوليد كود تسلسلي
                    $cashParent=$pdo->query("SELECT id FROM `{$TAC2}` WHERE code='1.1.1' LIMIT 1")->fetchColumn();
                    $bankParent=$pdo->query("SELECT id FROM `{$TAC2}` WHERE code='1.1.2' LIMIT 1")->fetchColumn();

                    if ($cashParent && $bankParent) {
                        $cashCount=(int)$pdo->query("SELECT COUNT(*) FROM `{$TAC2}` WHERE parent_id={$cashParent}")->fetchColumn();
                        $bankCount=(int)$pdo->query("SELECT COUNT(*) FROM `{$TAC2}` WHERE parent_id={$bankParent}")->fetchColumn();
                        $cashCode=sprintf('1.1.1.%03d',$cashCount+1);
                        $bankCode=sprintf('1.1.2.%03d',$bankCount+1);

                        // صندوق
                        $pdo->prepare("INSERT INTO `{$TAC2}` (code,name,parent_id,account_type,currency_id,level,is_locked)
                            VALUES (?,?,?,'asset',?,4,0)")
                            ->execute([$cashCode,"صندوق {$name}",$cashParent,$id]);
                        $cashId=(int)$pdo->lastInsertId();

                        // بنك
                        $pdo->prepare("INSERT INTO `{$TAC2}` (code,name,parent_id,account_type,currency_id,level,is_locked)
                            VALUES (?,?,?,'asset',?,4,0)")
                            ->execute([$bankCode,"بنك {$name}",$bankParent,$id]);
                        $bankId=(int)$pdo->lastInsertId();

                        // إعدادات الربط
                        $lcode=strtolower($code);
                        $pdo->prepare("INSERT INTO `{$TIAS2}` (setting_key,account_id,account_code,account_name,description)
                            VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE account_id=?,account_code=?,account_name=?")
                            ->execute(["cash_{$lcode}",$cashId,$cashCode,"صندوق {$name}","صندوق {$code}",
                                $cashId,$cashCode,"صندوق {$name}"]);
                        $pdo->prepare("INSERT INTO `{$TIAS2}` (setting_key,account_id,account_code,account_name,description)
                            VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE account_id=?,account_code=?,account_name=?")
                            ->execute(["bank_{$lcode}",$bankId,$bankCode,"بنك {$name}","بنك {$code}",
                                $bankId,$bankCode,"بنك {$name}"]);
                    }
                    $pdo->commit();
                    echo json_encode(['ok'=>true,'id'=>$id,'msg'=>"تمت إضافة العملة وإنشاء حسابات الصندوق والبنك تلقائياً"]);
                } catch(Exception $e){ $pdo->rollBack(); throw $e; }
            }
        }

        // ── تحديث سعر يدوي ──
        elseif ($act==='update_rate') {
            requirePermission('finance.accounts','edit');
            $id   = (int)$_POST['id'];
            $rate = max(0.0001,(float)$_POST['rate']);
            $pdo->prepare("UPDATE currencies SET exchange_rate=?,updated_at=NOW() WHERE id=?")
                ->execute([$rate,$id]);
            echo json_encode(['ok'=>true]);
        }

        // ── حذف عملة ──
        elseif ($act==='delete_currency') {
            requirePermission('finance.accounts','delete');
            $id=(int)$_POST['id'];
            $st=$pdo->prepare("SELECT is_base FROM currencies WHERE id=?");
            $st->execute([$id]);
            $cur=$st->fetch();
            if (!$cur) throw new Exception('العملة غير موجودة');
            if ($cur['is_base']) throw new Exception('لا يمكن حذف العملة الأساسية');
            $pdo->prepare("DELETE FROM currencies WHERE id=?")->execute([$id]);
            echo json_encode(['ok'=>true]);
        }

        else throw new Exception('إجراء غير معروف');
    } catch(Exception $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

// ── بيانات الصفحة ──
$currencies = $pdo->query("SELECT * FROM currencies ORDER BY is_base DESC,id")->fetchAll();
$baseCur = array_filter($currencies, function($c){ return $c['is_base']; });
$baseCur = reset($baseCur) ?: ['code'=>'USD','symbol'=>'$'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>إدارة العملات — <?= htmlspecialchars($branchName) ?></title>
<link rel="icon" href="/bayhas/assets/images/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="/bayhas/assets/css/layout.css" rel="stylesheet">
<style>
.cur-card{background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden}
.cur-hdr{padding:12px 20px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:10px}
table.mtbl{width:100%;border-collapse:collapse;font-size:.83rem}
table.mtbl th{background:#f8fafc;padding:9px 14px;font-weight:600;color:#64748b;font-size:.73rem;border-bottom:1px solid #f1f5f9}
table.mtbl td{padding:10px 14px;border-bottom:1px solid #f8fafc;vertical-align:middle}
table.mtbl tr:last-child td{border-bottom:none}
table.mtbl tr:hover td{background:#fafbff}
.rate-input{width:130px;padding:4px 8px;border:1px solid #e2e8f0;border-radius:7px;font-size:.82rem;text-align:left;direction:ltr;font-family:'Cairo',sans-serif}
.rate-input:focus{outline:none;border-color:#1e3a8a}
.act-btn{width:28px;height:28px;border-radius:7px;border:1px solid #e2e8f0;background:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;color:#64748b;cursor:pointer;transition:all .12s}
.act-btn:hover{background:#f1f5f9}
.act-btn.success-h:hover{background:#dcfce7;color:#16a34a;border-color:#86efac}
.act-btn.danger:hover{background:#fee2e2;color:#dc2626;border-color:#fca5a5}
.act-btn.primary:hover{background:#eff6ff;color:#1e3a8a;border-color:#bfdbfe}
.field-lbl{font-size:.76rem;font-weight:700;color:#475569;margin-bottom:4px;display:block}
.req{color:#dc2626} .n{font-variant-numeric:tabular-nums}
.auto-badge{font-size:.65rem;padding:1px 6px;border-radius:4px;background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe}
.stale-badge{font-size:.65rem;padding:1px 6px;border-radius:4px;background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.fresh-badge{font-size:.65rem;padding:1px 6px;border-radius:4px;background:#dcfce7;color:#14532d;border:1px solid #86efac}
</style>
</head>
<body>
<div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
<?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
<header class="topbar">
    <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
    <span class="tb-title"><i class="bi bi-currency-exchange me-1 text-primary"></i>إدارة العملات</span>
    <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
</header>
<main class="main-content"><div class="content-body">

<!-- شريط الإجراءات -->
<div class="d-flex gap-2 mb-4 flex-wrap align-items-center">
    <button class="btn btn-sm fw-600" style="border-radius:9px;background:#1e3a8a;color:#fff;border:none"
            onclick="openAdd()">
        <i class="bi bi-plus-lg me-1"></i>إضافة عملة
    </button>
    <button class="btn btn-sm fw-600" style="border-radius:9px;border:1px solid #1e3a8a;color:#1e3a8a"
            onclick="autoUpdateRates()" id="btnAutoUpdate">
        <span id="autoTxt"><i class="bi bi-arrow-repeat me-1"></i>تحديث تلقائي من الإنترنت</span>
        <span id="autoSpin" class="spinner-border spinner-border-sm" style="display:none"></span>
    </button>
    <span style="font-size:.75rem;color:#94a3b8">
        <i class="bi bi-info-circle me-1"></i>
        يتم جلب الأسعار من ExchangeRate-API مقابل <strong><?= htmlspecialchars($baseCur['code']) ?></strong>
    </span>
    <div id="autoResult" style="display:none;font-size:.78rem"></div>
</div>

<!-- جدول العملات -->
<div class="cur-card">
    <div class="cur-hdr">
        <i class="bi bi-currency-exchange text-primary"></i>
        <span style="font-size:.9rem;font-weight:700;color:#1e293b">
            جدول العملات
            <span style="font-size:.75rem;color:#94a3b8;font-weight:400">(<?= count($currencies) ?> عملة)</span>
        </span>
    </div>
    <div class="table-responsive">
    <table class="mtbl">
        <thead><tr>
            <th>الكود</th><th>الاسم</th><th>الرمز</th>
            <th>سعر الصرف مقابل <?= htmlspecialchars($baseCur['symbol']) ?></th>
            <th>السعر العكسي</th>
            <th>آخر تحديث</th>
            <th>الحالة</th>
            <th style="text-align:center">إجراءات</th>
        </tr></thead>
        <tbody>
        <?php foreach ($currencies as $cur):
            $isBase = $cur['is_base'];
            $rate   = (float)$cur['exchange_rate'];
            $inverse = $rate>0 ? round(1/$rate,6) : 0;
            $updatedAt = $cur['updated_at'];
            $daysSince = $updatedAt
                ? floor((time()-strtotime($updatedAt))/86400)
                : 999;
            if ($isBase)         $freshCls='';
            elseif ($daysSince<=1)  $freshCls='fresh-badge';
            elseif ($daysSince<=7)  $freshCls='auto-badge';
            else                    $freshCls='stale-badge';
        ?>
        <tr id="row_<?=$cur['id']?>">
            <td>
                <span class="fw-700" style="font-size:.9rem;direction:ltr;display:inline-block;color:#1e3a8a"><?= htmlspecialchars($cur['code']) ?></span>
                <?php if($isBase): ?>
                <span class="badge bg-primary-subtle text-primary ms-1" style="font-size:.62rem">أساسية</span>
                <?php endif; ?>
            </td>
            <td class="fw-600"><?= htmlspecialchars($cur['name']) ?></td>
            <td style="font-size:1.1rem"><?= htmlspecialchars($cur['symbol']??'') ?></td>
            <td>
                <?php if ($isBase): ?>
                <span class="text-muted" style="font-size:.8rem">1.0000 (أساس)</span>
                <?php else: ?>
                <div class="d-flex align-items-center gap-2">
                    <input type="number" class="rate-input" id="rate_<?=$cur['id']?>"
                           value="<?= $rate ?>" min="0.0001" step="0.0001" dir="ltr"
                           onchange="updateRate(<?=$cur['id']?>,this.value)"
                           title="1 <?=$baseCur['code']?> = X <?=$cur['code']?>">
                    <span style="font-size:.72rem;color:#94a3b8"><?= htmlspecialchars($cur['code']) ?></span>
                    <button class="act-btn" onclick="fetchSingleRate(<?=$cur['id']?>,'<?=htmlspecialchars($cur['code'],ENT_QUOTES)?>')"
                            title="جلب السعر من الإنترنت" id="fetchBtn_<?=$cur['id']?>">
                        <i class="bi bi-arrow-repeat" id="fetchIcon_<?=$cur['id']?>"></i>
                    </button>
                    <button class="act-btn success-h" onclick="updateRate(<?=$cur['id']?>,document.getElementById('rate_<?=$cur['id']?>').value)" title="حفظ السعر">
                        <i class="bi bi-check-lg"></i>
                    </button>
                </div>
                <?php endif; ?>
            </td>
            <td class="n" style="font-size:.78rem;color:#64748b" dir="ltr">
                <?php if (!$isBase && $rate>0): ?>
                1 <?= htmlspecialchars($cur['code']) ?> = <?= number_format($inverse,6) ?> <?= htmlspecialchars($baseCur['code']) ?>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td>
                <?php if ($isBase): ?>
                <span style="font-size:.75rem;color:#94a3b8">ثابت</span>
                <?php elseif ($updatedAt): ?>
                <span class="<?= $freshCls ?>" id="freshbadge_<?=$cur['id']?>">
                    <?= $daysSince===0?'اليوم':($daysSince===1?'أمس':"منذ {$daysSince} يوم") ?>
                </span>
                <?php else: ?>
                <span class="stale-badge">لم يُحدَّث</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($isBase): ?>
                <span class="badge bg-success-subtle text-success" style="font-size:.68rem">نشطة</span>
                <?php else: ?>
                <span class="badge <?=$cur['status']==='active'?'bg-success-subtle text-success':'bg-secondary-subtle text-secondary'?>" style="font-size:.68rem">
                    <?=$cur['status']==='active'?'نشطة':'معطلة'?>
                </span>
                <?php endif; ?>
            </td>
            <td>
                <div class="d-flex gap-1 justify-content-center">
                    <button class="act-btn primary" onclick="openEdit(<?=$cur['id']?>,'<?=htmlspecialchars($cur['code'],ENT_QUOTES)?>','<?=htmlspecialchars($cur['name'],ENT_QUOTES)?>','<?=htmlspecialchars($cur['symbol']??'',ENT_QUOTES)?>',<?=$rate?>,'<?=$cur['status']?>')" title="تعديل">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <?php if (!$isBase): ?>
                    <button class="act-btn danger" onclick="deleteCur(<?=$cur['id']?>,'<?=htmlspecialchars($cur['name'],ENT_QUOTES)?>')" title="حذف">
                        <i class="bi bi-trash"></i>
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

<!-- مودال إضافة/تعديل -->
<div class="modal fade" id="curModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#0c447c,#1e3a8a);border-radius:16px 16px 0 0">
        <h6 class="modal-title text-white fw-700 mb-0" id="mTitle">
            <i class="bi bi-currency-exchange me-2"></i>إضافة عملة
        </h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 pt-3">
        <input type="hidden" id="mId">
        <input type="hidden" id="mCode">
        <div class="row g-3">
          <div class="col-12" id="mCurSelectWrap">
            <label class="field-lbl">اختر العملة <span class="req">*</span></label>
            <div class="d-flex gap-2 align-items-center">
                <select id="mCurSelect" class="form-select form-select-sm" onchange="onCurSelectChange(this)">
                    <option value="">— جارٍ التحميل... —</option>
                </select>
                <button type="button" class="btn btn-sm btn-light" style="border-radius:7px;white-space:nowrap"
                        onclick="loadApiCurrencies()" title="تحديث من API">
                    <i class="bi bi-arrow-repeat" id="apiRefreshIcon"></i>
                </button>
            </div>
            <div style="font-size:.72rem;color:#94a3b8;margin-top:4px">
                <i class="bi bi-globe me-1"></i>القائمة من ExchangeRate-API — عملات موثوقة فقط
            </div>
          </div>
          <div class="col-md-4">
            <label class="field-lbl">الرمز</label>
            <input type="text" id="mSymbol" class="form-control form-control-sm" maxlength="5" placeholder="$">
          </div>
          <div class="col-md-8">
            <label class="field-lbl">سعر الصرف مقابل <?= htmlspecialchars($baseCur['code']) ?></label>
            <div class="input-group input-group-sm">
                <span class="input-group-text" style="font-size:.78rem">1 <?= htmlspecialchars($baseCur['code']) ?> =</span>
                <input type="number" id="mRate" class="form-control fw-600"
                       min="0.0001" step="0.0001" dir="ltr" placeholder="1.0000">
                <span class="input-group-text" id="mRateSuffix" style="font-size:.78rem">—</span>
            </div>
            <div style="font-size:.71rem;color:#16a34a;margin-top:3px" id="mRateHint" style="display:none"></div>
          </div>
          <div class="col-md-8">
            <label class="field-lbl">اسم العملة (يمكن تعديله)</label>
            <input type="text" id="mName" class="form-control form-control-sm" placeholder="اسم العملة">
          </div>
          <div class="col-md-4">
            <label class="field-lbl">الحالة</label>
            <select id="mStatus" class="form-select form-select-sm">
                <option value="active">نشطة</option>
                <option value="inactive">معطلة</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 px-4 pb-4">
        <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
        <button class="btn btn-sm fw-600" style="border-radius:8px;background:#1e3a8a;color:#fff;min-width:110px"
                onclick="saveCurrency()" id="btnSave">
          <span id="saveTxt"><i class="bi bi-floppy me-1"></i>حفظ</span>
          <span id="saveSpin" class="spinner-border spinner-border-sm" style="display:none"></span>
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

const curModal=new bootstrap.Modal(document.getElementById('curModal'));
const BASE_CODE='<?= htmlspecialchars($baseCur['code']) ?>';

function post(data){
    const fd=new FormData();
    Object.entries(data).forEach(([k,v])=>fd.append(k,v??''));
    return fetch(location.href,{method:'POST',body:fd}).then(r=>r.json());
}
function toast(msg,type='success'){
    const t=document.createElement('div');t.className=`alert alert-${type} shadow`;
    t.style.cssText='position:fixed;top:70px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:240px;text-align:center;font-size:.83rem;padding:.5rem 1.2rem';
    t.innerHTML=`<i class="bi bi-${type==='success'?'check-circle-fill text-success':'exclamation-triangle-fill text-danger'} me-2"></i>${msg}`;
    document.body.appendChild(t);setTimeout(()=>t.remove(),3000);
}

// ── مودال ──
document.getElementById('mCode').addEventListener('input',function(){
    this.value=this.value.toUpperCase();
    document.getElementById('mRateSuffix').textContent=this.value||'—';
});

function openAdd(){
    document.getElementById('mId').value='';
    document.getElementById('mCode').value='';
    document.getElementById('mName').value='';
    document.getElementById('mSymbol').value='';
    document.getElementById('mRate').value='';
    document.getElementById('mStatus').value='active';
    document.getElementById('mTitle').textContent='إضافة عملة جديدة';
    document.getElementById('mRateSuffix').textContent='—';
    document.getElementById('mCurSelectWrap').style.display='';
    document.getElementById('mCurSelect').value='';
    curModal.show();
    loadApiCurrencies();
}

// جلب العملات من API
async function loadApiCurrencies(){
    const icon=document.getElementById('apiRefreshIcon');
    icon.classList.add('spin');
    try {
        const resp=await fetch('https://api.exchangerate-api.com/v4/latest/<?= htmlspecialchars($baseCur['code']) ?>');
        const data=await resp.json();
        const rates=data.rates;
        const sel=document.getElementById('mCurSelect');
        // قائمة العملات الموثوقة مع أسمائها
        const NAMES={
            'USD':'دولار أمريكي','EUR':'يورو','GBP':'جنيه إسترليني',
            'TRY':'ليرة تركية','SYP':'ليرة سورية','SAR':'ريال سعودي',
            'AED':'درهم إماراتي','KWD':'دينار كويتي','JOD':'دينار أردني',
            'EGP':'جنيه مصري','IQD':'دينار عراقي','LBP':'ليرة لبنانية',
            'CNY':'يوان صيني','JPY':'ين ياباني','CAD':'دولار كندي',
            'AUD':'دولار أسترالي','CHF':'فرنك سويسري','INR':'روبية هندية',
            'RUB':'روبل روسي','QAR':'ريال قطري','BHD':'دينار بحريني',
        };
        // فلترة: فقط العملات الموجودة في قائمتنا
        const available=Object.keys(NAMES).filter(code=>rates[code]||code==='<?= htmlspecialchars($baseCur['code']) ?>');
        sel.innerHTML='<option value="">— اختر العملة —</option>';
        available.forEach(code=>{
            sel.innerHTML+=`<option value="${code}" data-rate="${rates[code]||1}" data-name="${NAMES[code]}">${code} — ${NAMES[code]}</option>`;
        });
        icon.classList.remove('spin');
    } catch(e){
        icon.classList.remove('spin');
        document.getElementById('mCurSelect').innerHTML='<option value="">فشل الاتصال — أدخل يدوياً</option>';
    }
}

function onCurSelectChange(sel){
    const opt=sel.options[sel.selectedIndex];
    if(!opt.value)return;
    document.getElementById('mCode').value=opt.value;
    document.getElementById('mName').value=opt.dataset.name||'';
    document.getElementById('mRateSuffix').textContent=opt.value;
    const rate=parseFloat(opt.dataset.rate||1);
    document.getElementById('mRate').value=rate.toFixed(4);
    document.getElementById('mRateHint').textContent=
        '✓ سعر الصرف الحالي من API: 1 <?= htmlspecialchars($baseCur['code']) ?> = '+rate.toFixed(4)+' '+opt.value;
    document.getElementById('mRateHint').style.display='block';
}
function openEdit(id,code,name,symbol,rate,status){
    document.getElementById('mId').value=id;
    document.getElementById('mCode').value=code;
    document.getElementById('mName').value=name;
    document.getElementById('mSymbol').value=symbol;
    document.getElementById('mRate').value=rate;
    document.getElementById('mStatus').value=status;
    document.getElementById('mTitle').textContent='تعديل عملة: '+code;
    document.getElementById('mRateSuffix').textContent=code;
    // إخفاء select عند التعديل
    document.getElementById('mCurSelectWrap').style.display='none';
    curModal.show();
}

function saveCurrency(){
    const code=document.getElementById('mCode').value.trim().toUpperCase();
    const name=document.getElementById('mName').value.trim();
    if(!name){toast('الاسم مطلوب','danger');return;}

    document.getElementById('saveTxt').style.opacity='0';
    document.getElementById('saveSpin').style.display='inline-block';
    document.getElementById('btnSave').disabled=true;

    post({
        _action:'save_currency',
        id:document.getElementById('mId').value,
        code,name,
        symbol:document.getElementById('mSymbol').value,
        exchange_rate:document.getElementById('mRate').value||'1',
        status:document.getElementById('mStatus').value,
    }).then(d=>{
        document.getElementById('saveTxt').style.opacity='1';
        document.getElementById('saveSpin').style.display='none';
        document.getElementById('btnSave').disabled=false;
        if(d.ok){toast('✅ تم الحفظ');curModal.hide();setTimeout(()=>location.reload(),700);}
        else toast(d.msg,'danger');
    });
}

// ── تحديث سعر يدوي ──
// ── تحديث سعر عملة واحدة من API ──
async function fetchSingleRate(id, code){
    const icon=document.getElementById('fetchIcon_'+id);
    const btn=document.getElementById('fetchBtn_'+id);
    icon.classList.add('spin');
    btn.disabled=true;
    try {
        const resp=await fetch('https://api.exchangerate-api.com/v4/latest/'+BASE_CODE);
        if(!resp.ok) throw new Error();
        const data=await resp.json();
        const rate=data.rates[code];
        if(!rate) throw new Error('غير متوفر');
        document.getElementById('rate_'+id).value=rate.toFixed(4);
        // حفظ تلقائي
        await fetch(location.href,{
            method:'POST',
            body:new URLSearchParams({_action:'update_rate',id,rate})
        });
        icon.classList.remove('spin');
        icon.classList.remove('bi-arrow-repeat');
        icon.classList.add('bi-check-circle-fill');
        icon.style.color='#16a34a';
        btn.disabled=false;
        setTimeout(()=>{
            icon.classList.remove('bi-check-circle-fill');
            icon.classList.add('bi-arrow-repeat');
            icon.style.color='';
            location.reload();
        },1200);
    } catch(e){
        icon.classList.remove('spin');
        icon.classList.remove('bi-arrow-repeat');
        icon.classList.add('bi-exclamation-triangle-fill');
        icon.style.color='#dc2626';
        btn.disabled=false;
        setTimeout(()=>{
            icon.classList.remove('bi-exclamation-triangle-fill');
            icon.classList.add('bi-arrow-repeat');
            icon.style.color='';
        },2000);
    }
}

// ── تحديث يدوي ──
function updateRate(id,rate){
    rate=parseFloat(rate);
    if(!rate||rate<=0){toast('سعر غير صالح','danger');return;}
    post({_action:'update_rate',id,rate}).then(d=>{
        if(d.ok){
            toast('✅ تم تحديث السعر');
            // تحديث السعر العكسي في الجدول
            setTimeout(()=>location.reload(),800);
        } else toast(d.msg,'danger');
    });
}

// ── حذف ──
function deleteCur(id,name){
    if(!confirm(`حذف العملة "${name}"؟\nتأكد أنها غير مستخدمة في الحسابات.`))return;
    post({_action:'delete_currency',id}).then(d=>{
        if(d.ok){toast('تم الحذف');setTimeout(()=>location.reload(),700);}
        else toast(d.msg,'danger');
    });
}

// ── تحديث تلقائي من ExchangeRate-API ──
async function autoUpdateRates(){
    document.getElementById('autoTxt').style.opacity='0';
    document.getElementById('autoSpin').style.display='inline-block';
    document.getElementById('btnAutoUpdate').disabled=true;
    document.getElementById('autoResult').style.display='none';

    try {
        // جلب الأسعار مقابل العملة الأساسية
        const resp = await fetch(`https://api.exchangerate-api.com/v4/latest/${BASE_CODE}`);
        if(!resp.ok) throw new Error('فشل الاتصال بالخادم');
        const data = await resp.json();
        const rates = data.rates;

        // تحديث كل عملة غير أساسية
        const rows = document.querySelectorAll('[id^="rate_"]');
        let updated=0, skipped=0;
        const promises=[];

        for(const inp of rows){
            const curId=inp.id.replace('rate_','');
            const row=document.getElementById('row_'+curId);
            // جلب كود العملة من العمود الأول
            const codeEl=row.querySelector('td:first-child .fw-700');
            if(!codeEl)continue;
            const code=codeEl.textContent.trim();
            if(!rates[code]){skipped++;continue;}

            const newRate=rates[code];
            inp.value=newRate.toFixed(4);
            promises.push(
                post({_action:'update_rate',id:curId,rate:newRate}).then(d=>{
                    if(d.ok) updated++;
                })
            );
        }

        await Promise.all(promises);

        document.getElementById('autoResult').style.display='block';
        document.getElementById('autoResult').innerHTML=
            `<span class="badge bg-success-subtle text-success">
                <i class="bi bi-check-circle me-1"></i>
                تم تحديث ${updated} عملة${skipped>0?' · '+skipped+' غير متوفرة (SYP وغيرها)':''}
            </span>`;
        setTimeout(()=>location.reload(),2000);

    } catch(e){
        document.getElementById('autoResult').style.display='block';
        document.getElementById('autoResult').innerHTML=
            `<span class="badge bg-danger-subtle text-danger">
                <i class="bi bi-exclamation-triangle me-1"></i>
                ${e.message||'خطأ في الاتصال'}
            </span>`;
    } finally {
        document.getElementById('autoTxt').style.opacity='1';
        document.getElementById('autoSpin').style.display='none';
        document.getElementById('btnAutoUpdate').disabled=false;
    }
}
</script>
</body>
</html>
