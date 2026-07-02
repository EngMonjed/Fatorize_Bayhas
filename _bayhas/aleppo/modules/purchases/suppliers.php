<?php
/**
 * purchases/suppliers.php — إدارة الموردين
 * المسار: /bayhas/aleppo/modules/purchases/suppliers.php
 */
session_start();
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../config/auth.php';
$pdo = getConnection();
checkLogin($pdo);
requirePermission('purchases.suppliers','view');
$currentModule = 'purchases.suppliers';
$branchName = $_SESSION['branch_name'] ?? 'الفرع';
$TS  = $_SESSION['table_suffix'];
$TSP = "product_suppliers_{$TS}";
$TAC = "account_charts_{$TS}";
$TIAS= "invoice_account_settings_{$TS}";

// ── AJAX ──
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    ob_start();
    try {
        $act = $_POST['_action'];

        if ($act==='save') {
            requirePermission('purchases.suppliers','edit');
            $id          = (int)($_POST['id']??0);
            $name        = trim($_POST['name']??'');
            $contact     = trim($_POST['contact_person']??'');
            $phone       = trim($_POST['phone']??'');
            $email       = trim($_POST['email']??'');
            $address     = trim($_POST['address']??'');
            $tax         = trim($_POST['tax_number']??'');
            $type        = $_POST['type']??'wholesaler';
            $supType     = $_POST['supplier_type']??'product';
            $status      = $_POST['status']??'active';
            $creditLimit = (float)($_POST['credit_limit']??0);
            $discount    = (float)($_POST['discount_percentage']??0);
            $notes       = trim($_POST['notes']??'');
            $accId       = (int)($_POST['account_id']??0) ?: null;
            $prepaidId   = (int)($_POST['prepaid_account_id']??0) ?: null;
            $autoCreate  = ($_POST['auto_create']??'0')==='1';

            if (!$name) throw new Exception('اسم المورد مطلوب');

            $pdo->beginTransaction();
            try {
                // إنشاء الحسابات تلقائياً إذا طُلب
                if ($autoCreate && !$id) {
                    // جلب حسابات الأب من الإعدادات
                    $getParent = function(string $key) use ($pdo,$TIAS,$TAC): ?array {
                        $st=$pdo->prepare("SELECT ac.* FROM `{$TIAS}` i JOIN `{$TAC}` ac ON ac.id=i.account_id WHERE i.setting_key=? LIMIT 1");
                        $st->execute([$key]); return $st->fetch(PDO::FETCH_ASSOC)?:null;
                    };
                    $parentPayable = $getParent('supplier_payable');
                    $parentAdvance = $getParent('shipping_advance') ?: $getParent('employee_advance');

                    if ($parentPayable) {
                        $cnt=(int)$pdo->query("SELECT COUNT(*) FROM `{$TAC}` WHERE parent_id={$parentPayable['id']}")->fetchColumn();
                        $code=$parentPayable['code'].'.'.str_pad($cnt+1,3,'0',STR_PAD_LEFT);
                        $pdo->prepare("INSERT INTO `{$TAC}` (code,name,parent_id,account_type,currency_id,level,is_locked)
                            VALUES (?,?,?,'liability',1,?,0)")
                            ->execute([$code,"ذمم {$name}",$parentPayable['id'],substr_count($code,'.')+1]);
                        $accId=(int)$pdo->lastInsertId();
                    }
                    if ($parentAdvance) {
                        $cnt2=(int)$pdo->query("SELECT COUNT(*) FROM `{$TAC}` WHERE parent_id={$parentAdvance['id']}")->fetchColumn();
                        $code2=$parentAdvance['code'].'.'.str_pad($cnt2+1,3,'0',STR_PAD_LEFT);
                        $pdo->prepare("INSERT INTO `{$TAC}` (code,name,parent_id,account_type,currency_id,level,is_locked)
                            VALUES (?,?,?,'asset',1,?,0)")
                            ->execute([$code2,"دفعات مقدمة — {$name}",$parentAdvance['id'],substr_count($code2,'.')+1]);
                        $prepaidId=(int)$pdo->lastInsertId();
                    }
                }

                if ($id) {
                    $pdo->prepare("UPDATE `{$TSP}` SET name=?,contact_person=?,phone=?,email=?,
                        address=?,tax_number=?,type=?,supplier_type=?,status=?,
                        credit_limit=?,discount_percentage=?,notes=?,
                        account_id=?,prepaid_account_id=?,updated_by=? WHERE id=?")
                        ->execute([$name,$contact,$phone,$email,$address,$tax,
                            $type,$supType,$status,$creditLimit,$discount,$notes,
                            $accId,$prepaidId,$_SESSION['user_id'],$id]);
                    $msg='تم التعديل';
                } else {
                    $pdo->prepare("INSERT INTO `{$TSP}`
                        (name,contact_person,phone,email,address,tax_number,type,supplier_type,
                         status,credit_limit,discount_percentage,notes,account_id,prepaid_account_id,created_by)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$name,$contact,$phone,$email,$address,$tax,
                            $type,$supType,$status,$creditLimit,$discount,$notes,
                            $accId,$prepaidId,$_SESSION['user_id']]);
                    $id=(int)$pdo->lastInsertId();
                    $msg='تمت الإضافة';
                    if($autoCreate) $msg.=' وإنشاء الحسابات تلقائياً ✅';
                }
                $pdo->commit();
                ob_get_clean();
                echo json_encode(['ok'=>true,'id'=>$id,'msg'=>$msg,
                    'account_id'=>$accId,'prepaid_account_id'=>$prepaidId]);

            } catch(Exception $e){ $pdo->rollBack(); throw $e; }
        }

        elseif ($act==='get') {
            $id=(int)$_POST['id'];
            $st=$pdo->prepare("SELECT s.*,
                pay.code AS pay_code, pay.name AS pay_name,
                pre.code AS pre_code, pre.name AS pre_name
                FROM `{$TSP}` s
                LEFT JOIN `{$TAC}` pay ON pay.id=s.account_id
                LEFT JOIN `{$TAC}` pre ON pre.id=s.prepaid_account_id
                WHERE s.id=?");
            $st->execute([$id]);
            $row=$st->fetch(PDO::FETCH_ASSOC);
            ob_get_clean();
            echo json_encode(['ok'=>true,'data'=>$row]);
            exit;
        }

        elseif ($act==='toggle_status') {
            $id=(int)$_POST['id'];
            $cur=$pdo->prepare("SELECT status FROM `{$TSP}` WHERE id=?");
            $cur->execute([$id]); $s=$cur->fetchColumn();
            $new=$s==='active'?'inactive':'active';
            $pdo->prepare("UPDATE `{$TSP}` SET status=? WHERE id=?")->execute([$new,$id]);
            ob_get_clean();
            echo json_encode(['ok'=>true,'status'=>$new]);
            exit;
        }

        elseif ($act==='delete') {
            requirePermission('purchases.suppliers','delete');
            $id=(int)$_POST['id'];
            $used=$pdo->prepare("SELECT COUNT(*) FROM `purchases_{$TS}` WHERE supplier_id=?");
            $used->execute([$id]);
            if($used->fetchColumn()>0) throw new Exception('لا يمكن حذف مورد لديه فواتير');
            $pdo->prepare("DELETE FROM `{$TSP}` WHERE id=?")->execute([$id]);
            ob_get_clean();
            echo json_encode(['ok'=>true]);
            exit;
        }

        else throw new Exception('إجراء غير معروف');

    } catch(Throwable $e){
        ob_end_clean();
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

// ── بيانات الصفحة ──
$suppliers = $pdo->query("SELECT s.*,
    pay.code AS pay_code, pay.name AS pay_name,
    pre.code AS pre_code, pre.name AS pre_name
    FROM `{$TSP}` s
    LEFT JOIN `{$TAC}` pay ON pay.id=s.account_id
    LEFT JOIN `{$TAC}` pre ON pre.id=s.prepaid_account_id
    ORDER BY s.name")->fetchAll();

// حسابات الذمم (liability) وحسابات الأصول (للدفعات المقدمة)
$liabilityAccs=$pdo->query("SELECT id,code,name FROM `{$TAC}` WHERE account_type='liability' AND is_active=1 ORDER BY code")->fetchAll();
$assetAccs=$pdo->query("SELECT id,code,name FROM `{$TAC}` WHERE account_type='asset' AND is_active=1 ORDER BY code")->fetchAll();

$typeLabels=['manufacturer'=>'مصنّع','distributor'=>'موزّع','wholesaler'=>'جملة','retailer'=>'مفرد'];
$supTypeLabels=['product'=>'منتجات','consumable'=>'مستهلكات','both'=>'كليهما'];
$colors=['#3b82f6','#8b5cf6','#10b981','#f59e0b','#ef4444','#06b6d4'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>الموردون — <?= htmlspecialchars($branchName) ?></title>
<link rel="icon" href="/bayhas/assets/images/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="/bayhas/assets/css/layout.css" rel="stylesheet">
<style>
.card-sup{background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden}
.sup-hdr{padding:12px 20px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
table.mtbl{width:100%;border-collapse:collapse;font-size:.82rem}
table.mtbl th{background:#f8fafc;padding:8px 14px;font-weight:600;color:#64748b;font-size:.73rem;border-bottom:1px solid #f1f5f9;white-space:nowrap}
table.mtbl td{padding:9px 14px;border-bottom:1px solid #f8fafc;vertical-align:middle}
table.mtbl tr:hover td{background:#fafbff}
.ab{width:28px;height:28px;border-radius:7px;border:1px solid #e2e8f0;background:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;color:#64748b;cursor:pointer;transition:all .12s}
.ab:hover{background:#eff6ff;color:#1e3a8a;border-color:#bfdbfe}
.ab.red:hover{background:#fee2e2;color:#dc2626;border-color:#fca5a5}
.acc-badge{font-size:.68rem;padding:2px 7px;border-radius:5px;background:#f1f5f9;color:#475569;font-family:monospace}
.field-lbl{font-size:.76rem;font-weight:700;color:#475569;margin-bottom:4px;display:block}
.sec-title{font-size:.72rem;font-weight:700;color:#1e3a8a;text-transform:uppercase;letter-spacing:.5px;padding-bottom:4px;border-bottom:1px solid #bfdbfe;margin-top:4px;margin-bottom:8px}
.avatar{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;color:#fff}
</style>
</head>
<body>
<div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
<?php require_once __DIR__.'/../../../includes/sidebar.php'; ?>
<header class="topbar">
    <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
    <span class="tb-title"><i class="bi bi-truck me-1 text-primary"></i>إدارة الموردين</span>
    <span class="tb-branch"><?= htmlspecialchars($branchName) ?></span>
</header>
<main class="main-content"><div class="content-body">

<div class="d-flex gap-2 mb-4">
    <button class="btn btn-sm fw-600" style="border-radius:9px;background:#1e3a8a;color:#fff;border:none"
            onclick="openAdd()">
        <i class="bi bi-plus-lg me-1"></i>إضافة مورد
    </button>
</div>

<div class="card-sup">
    <div class="sup-hdr">
        <span style="font-size:.9rem;font-weight:700;color:#1e293b">
            <i class="bi bi-people me-2 text-primary"></i>الموردون
            <span style="font-size:.75rem;color:#94a3b8;font-weight:400">(<?= count($suppliers) ?>)</span>
        </span>
    </div>
    <div class="table-responsive">
    <table class="mtbl">
        <thead><tr>
            <th>#</th><th>المورد</th><th>التواصل</th><th>النوع</th>
            <th>حساب الذمة</th><th>حساب الدفعات المقدمة</th>
            <th>الحد الائتماني</th><th>الحالة</th><th style="text-align:center">إجراءات</th>
        </tr></thead>
        <tbody>
        <?php if(empty($suppliers)): ?>
        <tr><td colspan="9" class="text-center text-muted py-5">
            <i class="bi bi-truck" style="font-size:2rem;color:#e2e8f0"></i>
            <div class="mt-2">لا يوجد موردون — أضف أول مورد</div>
        </td></tr>
        <?php endif; ?>
        <?php foreach($suppliers as $i=>$sup):
            $clr=$colors[$sup['id']%count($colors)];
            $ini=mb_substr($sup['name'],0,1);
        ?>
        <tr>
            <td class="text-muted" style="font-size:.75rem"><?=$i+1?></td>
            <td>
                <div class="d-flex align-items-center gap-2">
                    <div class="avatar" style="background:<?=$clr?>"><?=$ini?></div>
                    <div>
                        <div class="fw-700" style="font-size:.85rem"><?=htmlspecialchars($sup['name'])?></div>
                        <?php if($sup['contact_person']): ?>
                        <div style="font-size:.71rem;color:#94a3b8"><?=htmlspecialchars($sup['contact_person'])?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </td>
            <td>
                <?php if($sup['phone']): ?><div style="font-size:.78rem;direction:ltr"><?=htmlspecialchars($sup['phone'])?></div><?php endif; ?>
                <?php if($sup['email']): ?><div style="font-size:.71rem;color:#64748b"><?=htmlspecialchars($sup['email'])?></div><?php endif; ?>
            </td>
            <td>
                <span class="badge bg-primary-subtle text-primary" style="font-size:.68rem"><?=$typeLabels[$sup['type']]??$sup['type']?></span>
                <span class="badge bg-info-subtle text-info ms-1" style="font-size:.68rem"><?=$supTypeLabels[$sup['supplier_type']]??'—'?></span>
            </td>
            <td>
                <?php if($sup['pay_code']): ?>
                <span class="acc-badge"><?=htmlspecialchars($sup['pay_code'])?></span>
                <div style="font-size:.7rem;color:#64748b;margin-top:2px"><?=htmlspecialchars($sup['pay_name'])?></div>
                <?php else: ?>
                <span class="text-danger" style="font-size:.75rem"><i class="bi bi-exclamation-circle me-1"></i>غير محدد</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if($sup['pre_code']): ?>
                <span class="acc-badge"><?=htmlspecialchars($sup['pre_code'])?></span>
                <div style="font-size:.7rem;color:#64748b;margin-top:2px"><?=htmlspecialchars($sup['pre_name'])?></div>
                <?php else: ?>
                <span class="text-muted" style="font-size:.75rem">—</span>
                <?php endif; ?>
            </td>
            <td class="n" style="font-size:.8rem"><?=$sup['credit_limit']>0?number_format($sup['credit_limit'],0):'—'?></td>
            <td>
                <span class="badge <?=$sup['status']==='active'?'bg-success-subtle text-success':'bg-secondary-subtle text-secondary'?>"
                      style="font-size:.68rem;cursor:pointer" onclick="toggleStatus(<?=$sup['id']?>)">
                    <?=$sup['status']==='active'?'نشط':'معطل'?>
                </span>
            </td>
            <td>
                <div class="d-flex gap-1 justify-content-center">
                    <button class="ab" onclick="openEdit(<?=$sup['id']?>)" title="تعديل"><i class="bi bi-pencil"></i></button>
                    <button class="ab red" onclick="deleteSupplier(<?=$sup['id']?>,'<?=htmlspecialchars($sup['name'],ENT_QUOTES)?>')" title="حذف"><i class="bi bi-trash"></i></button>
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
<div class="modal fade" id="supModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#1e3a8a,#2563eb);border-radius:16px 16px 0 0">
        <h6 class="modal-title text-white fw-700 mb-0" id="mTitle">
            <i class="bi bi-person-plus me-2"></i>إضافة مورد
        </h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 pt-3">
        <input type="hidden" id="mId">
        <div class="row g-3">

          <!-- المعلومات الأساسية -->
          <div class="col-12"><div class="sec-title">المعلومات الأساسية</div></div>
          <div class="col-md-6">
            <label class="field-lbl">اسم المورد <span style="color:#dc2626">*</span></label>
            <input type="text" id="mName" class="form-control form-control-sm">
          </div>
          <div class="col-md-6">
            <label class="field-lbl">جهة الاتصال</label>
            <input type="text" id="mContact" class="form-control form-control-sm">
          </div>
          <div class="col-md-4">
            <label class="field-lbl">هاتف</label>
            <input type="text" id="mPhone" class="form-control form-control-sm" dir="ltr">
          </div>
          <div class="col-md-4">
            <label class="field-lbl">بريد إلكتروني</label>
            <input type="email" id="mEmail" class="form-control form-control-sm" dir="ltr">
          </div>
          <div class="col-md-4">
            <label class="field-lbl">الرقم الضريبي</label>
            <input type="text" id="mTax" class="form-control form-control-sm" dir="ltr">
          </div>
          <div class="col-12">
            <label class="field-lbl">العنوان</label>
            <input type="text" id="mAddress" class="form-control form-control-sm">
          </div>
          <div class="col-md-4">
            <label class="field-lbl">نوع المورد</label>
            <select id="mType" class="form-select form-select-sm">
              <option value="manufacturer">مصنّع</option>
              <option value="distributor">موزّع</option>
              <option value="wholesaler" selected>جملة</option>
              <option value="retailer">مفرد</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="field-lbl">يورد</label>
            <select id="mSupType" class="form-select form-select-sm">
              <option value="product">منتجات</option>
              <option value="consumable">مستهلكات</option>
              <option value="both">كليهما</option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="field-lbl">الحد الائتماني</label>
            <input type="number" id="mCredit" class="form-control form-control-sm" value="0" dir="ltr">
          </div>
          <div class="col-md-2">
            <label class="field-lbl">خصم%</label>
            <input type="number" id="mDiscount" class="form-control form-control-sm" value="0" min="0" max="100" dir="ltr">
          </div>

          <!-- الحسابات المحاسبية -->
          <div class="col-12"><div class="sec-title" style="color:#16a34a;border-color:#bbf7d0">الربط المحاسبي</div></div>

          <!-- خيار الإنشاء التلقائي (للمورد الجديد فقط) -->
          <div class="col-12" id="mAutoWrap">
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;padding:10px 14px">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="mAutoCreate" onchange="onAutoToggle(this)">
                <label class="form-check-label fw-600" for="mAutoCreate" style="font-size:.82rem;color:#16a34a">
                  <i class="bi bi-magic me-1"></i>إنشاء الحسابات تلقائياً
                </label>
              </div>
              <div style="font-size:.72rem;color:#64748b;margin-top:4px">
                سيتم إنشاء حساب ذمة تحت <strong>ذمم الموردين</strong> وحساب دفعات تحت <strong>الدفعات المقدمة</strong> تلقائياً
              </div>
            </div>
          </div>

          <!-- اختيار يدوي -->
          <div class="col-md-6" id="mPayAccWrap">
            <label class="field-lbl">
                <i class="bi bi-bank me-1 text-danger"></i>حساب ذمة المورد (Payable)
            </label>
            <select id="mAccId" class="form-select form-select-sm">
                <option value="">— اختر من شجرة الحسابات —</option>
                <?php foreach($liabilityAccs as $ac): ?>
                <option value="<?=$ac['id']?>"><?=htmlspecialchars($ac['code'].' — '.$ac['name'])?></option>
                <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6" id="mPreAccWrap">
            <label class="field-lbl">
                <i class="bi bi-cash-coin me-1 text-success"></i>حساب الدفعات المقدمة (Prepaid)
            </label>
            <select id="mPrepaidId" class="form-select form-select-sm">
                <option value="">— اختر من شجرة الحسابات —</option>
                <?php foreach($assetAccs as $ac): ?>
                <option value="<?=$ac['id']?>"><?=htmlspecialchars($ac['code'].' — '.$ac['name'])?></option>
                <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12">
            <label class="field-lbl">ملاحظات</label>
            <textarea id="mNotes" class="form-control form-control-sm" rows="2" placeholder="اختياري"></textarea>
          </div>
          <div class="col-md-4">
            <label class="field-lbl">الحالة</label>
            <select id="mStatus" class="form-select form-select-sm">
              <option value="active">نشط</option>
              <option value="inactive">معطل</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 px-4 pb-4">
        <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
        <button class="btn btn-sm fw-600" style="border-radius:8px;background:#1e3a8a;color:#fff;min-width:120px"
                onclick="saveSupplier()" id="btnSave">
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

const supModal=new bootstrap.Modal(document.getElementById('supModal'));

function post(data){
    const fd=new FormData();
    Object.entries(data).forEach(([k,v])=>fd.append(k,v??''));
    return fetch(location.href,{method:'POST',body:fd}).then(r=>r.json());
}
function toast(msg,type='success'){
    const t=document.createElement('div');t.className=`alert alert-${type} shadow`;
    t.style.cssText='position:fixed;top:70px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:260px;text-align:center;font-size:.83rem;padding:.5rem 1.2rem';
    t.innerHTML=`<i class="bi bi-${type==='success'?'check-circle-fill text-success':'exclamation-triangle-fill text-danger'} me-2"></i>${msg}`;
    document.body.appendChild(t);setTimeout(()=>t.remove(),3500);
}

let _isEdit=false;
function resetForm(){
    ['mId','mName','mContact','mPhone','mEmail','mAddress','mTax','mNotes']
        .forEach(id=>document.getElementById(id).value='');
    document.getElementById('mType').value='wholesaler';
    document.getElementById('mSupType').value='product';
    document.getElementById('mCredit').value='0';
    document.getElementById('mDiscount').value='0';
    document.getElementById('mStatus').value='active';
    document.getElementById('mAccId').value='';
    document.getElementById('mPrepaidId').value='';
    document.getElementById('mAutoCreate').checked=false;
    document.getElementById('mAutoWrap').style.display='';
    document.getElementById('mPayAccWrap').style.display='';
    document.getElementById('mPreAccWrap').style.display='';
}

function openAdd(){
    resetForm(); _isEdit=false;
    document.getElementById('mTitle').innerHTML='<i class="bi bi-person-plus me-2"></i>إضافة مورد جديد';
    supModal.show();
}

function openEdit(id){
    _isEdit=true;
    post({_action:'get',id}).then(d=>{
        if(!d.ok){toast(d.msg,'danger');return;}
        const s=d.data;
        document.getElementById('mId').value=s.id;
        document.getElementById('mName').value=s.name||'';
        document.getElementById('mContact').value=s.contact_person||'';
        document.getElementById('mPhone').value=s.phone||'';
        document.getElementById('mEmail').value=s.email||'';
        document.getElementById('mAddress').value=s.address||'';
        document.getElementById('mTax').value=s.tax_number||'';
        document.getElementById('mType').value=s.type||'wholesaler';
        document.getElementById('mSupType').value=s.supplier_type||'product';
        document.getElementById('mCredit').value=s.credit_limit||'0';
        document.getElementById('mDiscount').value=s.discount_percentage||'0';
        document.getElementById('mStatus').value=s.status||'active';
        document.getElementById('mNotes').value=s.notes||'';
        document.getElementById('mAccId').value=s.account_id||'';
        document.getElementById('mPrepaidId').value=s.prepaid_account_id||'';
        document.getElementById('mAutoWrap').style.display='none'; // التعديل بدون إنشاء تلقائي
        document.getElementById('mTitle').innerHTML=`<i class="bi bi-pencil me-2"></i>تعديل: ${s.name}`;
        supModal.show();
    });
}

function onAutoToggle(cb){
    const on=cb.checked;
    document.getElementById('mPayAccWrap').style.display=on?'none':'';
    document.getElementById('mPreAccWrap').style.display=on?'none':'';
}

function saveSupplier(){
    const name=document.getElementById('mName').value.trim();
    if(!name){toast('اسم المورد مطلوب','danger');return;}
    const autoCreate=document.getElementById('mAutoCreate')?.checked&&!_isEdit;
    const accId=document.getElementById('mAccId').value;
    const prepaidId=document.getElementById('mPrepaidId').value;
    if(!autoCreate && !accId) { toast('يرجى تحديد حساب الذمة أو تفعيل الإنشاء التلقائي','danger'); return; }

    document.getElementById('saveTxt').style.opacity='0';
    document.getElementById('saveSpin').style.display='inline-block';
    document.getElementById('btnSave').disabled=true;

    post({
        _action:'save',
        id:document.getElementById('mId').value,
        name,
        contact_person:document.getElementById('mContact').value,
        phone:document.getElementById('mPhone').value,
        email:document.getElementById('mEmail').value,
        address:document.getElementById('mAddress').value,
        tax_number:document.getElementById('mTax').value,
        type:document.getElementById('mType').value,
        supplier_type:document.getElementById('mSupType').value,
        credit_limit:document.getElementById('mCredit').value,
        discount_percentage:document.getElementById('mDiscount').value,
        status:document.getElementById('mStatus').value,
        notes:document.getElementById('mNotes').value,
        account_id:accId,
        prepaid_account_id:prepaidId,
        auto_create:autoCreate?'1':'0',
    }).then(d=>{
        document.getElementById('saveTxt').style.opacity='1';
        document.getElementById('saveSpin').style.display='none';
        document.getElementById('btnSave').disabled=false;
        if(d.ok){toast('✅ '+d.msg);supModal.hide();setTimeout(()=>location.reload(),700);}
        else toast(d.msg,'danger');
    });
}

function toggleStatus(id){
    post({_action:'toggle_status',id}).then(d=>{
        if(d.ok){toast(d.status==='active'?'تم التفعيل':'تم التعطيل');setTimeout(()=>location.reload(),600);}
        else toast(d.msg,'danger');
    });
}

function deleteSupplier(id,name){
    if(!confirm(`حذف المورد "${name}"؟`))return;
    post({_action:'delete',id}).then(d=>{
        if(d.ok){toast('تم الحذف');setTimeout(()=>location.reload(),600);}
        else toast(d.msg,'danger');
    });
}
</script>
</body>
</html>
