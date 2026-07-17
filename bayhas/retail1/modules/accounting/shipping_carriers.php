<?php
/**
 * accounting/shipping_carriers.php — إدارة شركات الشحن
 * المسار: /bayhas/aleppo/modules/accounting/shipping_carriers.php
 */
session_start();
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../config/auth.php';
$pdo = getConnection();
checkLogin($pdo);
requirePermission('finance.accounts','view');
$currentModule = 'finance.accounts';
$branchName = $_SESSION['branch_name'] ?? 'الفرع';
$TS  = $_SESSION['table_suffix'];
$TAC = "account_charts_{$TS}";
$TIAS= "invoice_account_settings_{$TS}";
$TSC = "shipping_carriers";

// ── AJAX ──
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    ob_start();
    try {
        $act = $_POST['_action'];

        // ── حفظ / تعديل ──
        if ($act==='save') {
            requirePermission('finance.accounts','edit');
            $id      = (int)($_POST['id']??0);
            $name    = trim($_POST['name']??'');
            $contact = trim($_POST['contact_person']??'');
            $phone   = trim($_POST['phone']??'');
            $mobile  = trim($_POST['mobile']??'');
            $email   = trim($_POST['email']??'');
            $address = trim($_POST['address']??'');
            $city    = trim($_POST['city']??'');
            $country = trim($_POST['country']??'');
            $website = trim($_POST['website']??'');
            $tax     = trim($_POST['tax_number']??'');
            $notes   = trim($_POST['notes']??'');
            $status  = $_POST['status']??'active';

            if (!$name) throw new Exception('اسم الشركة مطلوب');

            // جلب الحسابات الأب من الإعدادات
            $stP=$pdo->prepare("SELECT ac.id,ac.code FROM `{$TIAS}` i
                JOIN `{$TAC}` ac ON ac.id=i.account_id
                WHERE i.setting_key=? LIMIT 1");
            $stP->execute(['shipping_payable']);
            $parentPayable=$stP->fetch(PDO::FETCH_ASSOC);

            $stA=$pdo->prepare("SELECT ac.id,ac.code FROM `{$TIAS}` i
                JOIN `{$TAC}` ac ON ac.id=i.account_id
                WHERE i.setting_key=? LIMIT 1");
            $stA->execute(['shipping_advance']);
            $parentAdvance=$stA->fetch(PDO::FETCH_ASSOC);

            if (!$parentPayable || !$parentAdvance)
                throw new Exception('يرجى ضبط إعدادات الربط المحاسبي أولاً (ذمم شركات الشحن + الدفعات المقدمة)');

            $pdo->beginTransaction();
            try {
                if ($id) {
                    // تعديل
                    $pdo->prepare("UPDATE `{$TSC}` SET name=?,contact_person=?,phone=?,mobile=?,
                        email=?,address=?,city=?,country=?,website=?,tax_number=?,notes=?,status=?
                        WHERE id=?")
                        ->execute([$name,$contact,$phone,$mobile,$email,$address,
                            $city,$country,$website,$tax,$notes,$status,$id]);
                    $pdo->commit();
                    echo json_encode(['ok'=>true,'id'=>$id,'msg'=>'تم التعديل']);
                } else {
                    // إنشاء جديد — إنشاء الحسابات تلقائياً
                    // حساب الذمة
                    $payCount=(int)$pdo->query("SELECT COUNT(*) FROM `{$TAC}` WHERE parent_id={$parentPayable['id']}")->fetchColumn();
                    $payCode=$parentPayable['code'].'.'.str_pad($payCount+1,3,'0',STR_PAD_LEFT);
                    $pdo->prepare("INSERT INTO `{$TAC}` (code,name,parent_id,account_type,currency_id,level,is_locked)
                        VALUES (?,?,?,'liability',1,?,0)")
                        ->execute([$payCode,"ذمم {$name}",$parentPayable['id'],
                            (substr_count($payCode,'.')+1)]);
                    $payAccId=(int)$pdo->lastInsertId();

                    // حساب الدفعة المقدمة
                    $advCount=(int)$pdo->query("SELECT COUNT(*) FROM `{$TAC}` WHERE parent_id={$parentAdvance['id']}")->fetchColumn();
                    $advCode=$parentAdvance['code'].'.'.str_pad($advCount+1,3,'0',STR_PAD_LEFT);
                    $pdo->prepare("INSERT INTO `{$TAC}` (code,name,parent_id,account_type,currency_id,level,is_locked)
                        VALUES (?,?,?,'asset',1,?,0)")
                        ->execute([$advCode,"دفعات مقدمة — {$name}",$parentAdvance['id'],
                            (substr_count($advCode,'.')+1)]);
                    $advAccId=(int)$pdo->lastInsertId();

                    // حفظ شركة الشحن
                    $pdo->prepare("INSERT INTO `{$TSC}`
                        (name,contact_person,phone,mobile,email,address,city,country,
                         website,tax_number,account_id,payable_account_id,notes,status,created_by,created_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
                        ->execute([$name,$contact,$phone,$mobile,$email,$address,
                            $city,$country,$website,$tax,$advAccId,$payAccId,
                            $notes,$status,$_SESSION['user_id']]);
                    $id=(int)$pdo->lastInsertId();
                    $pdo->commit();
                    echo json_encode(['ok'=>true,'id'=>$id,
                        'pay_acc'=>$payCode,'adv_acc'=>$advCode,
                        'msg'=>"تمت الإضافة وإنشاء الحسابات ({$payCode}، {$advCode})✅"]);
                }
            } catch(Exception $e){ $pdo->rollBack(); throw $e; }
        }

        // ── تغيير الحالة ──
        elseif ($act==='toggle_status') {
            requirePermission('finance.accounts','edit');
            $id=(int)$_POST['id'];
            $st=$pdo->prepare("SELECT status FROM `{$TSC}` WHERE id=?");
            $st->execute([$id]);
            $cur=$st->fetchColumn();
            $new=$cur==='active'?'inactive':'active';
            $pdo->prepare("UPDATE `{$TSC}` SET status=? WHERE id=?")->execute([$new,$id]);
            echo json_encode(['ok'=>true,'status'=>$new]);
        }

        // ── حذف ──
        elseif ($act==='delete') {
            requirePermission('finance.accounts','delete');
            $id=(int)$_POST['id'];
            $st=$pdo->prepare("SELECT * FROM `{$TSC}` WHERE id=?");
            $st->execute([$id]);
            $sc=$st->fetch(PDO::FETCH_ASSOC);
            if(!$sc) throw new Exception('غير موجود');
            // تحقق من الاستخدام
            $used=$pdo->prepare("SELECT COUNT(*) FROM `journal_entries_{$TS}` WHERE reference_type='shipping' AND reference_id=?");
            $used->execute([$id]);
            if($used->fetchColumn()>0) throw new Exception('لا يمكن حذف شركة شحن لها قيود محاسبية');
            $pdo->prepare("DELETE FROM `{$TSC}` WHERE id=?")->execute([$id]);
            echo json_encode(['ok'=>true]);
        }

        // ── جلب للتعديل ──
        elseif ($act==='get') {
            $id=(int)$_POST['id'];
            $st=$pdo->prepare("SELECT sc.*,
                pay.code AS pay_code, pay.name AS pay_name,
                adv.code AS adv_code, adv.name AS adv_name
                FROM `{$TSC}` sc
                LEFT JOIN `{$TAC}` pay ON pay.id=sc.payable_account_id
                LEFT JOIN `{$TAC}` adv ON adv.id=sc.account_id
                WHERE sc.id=?");
            $st->execute([$id]);
            $row=$st->fetch(PDO::FETCH_ASSOC);
            if(!$row) throw new Exception('غير موجود');
            ob_get_clean();
            echo json_encode(['ok'=>true,'data'=>$row]);
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
$carriers = $pdo->query("SELECT sc.*,
    pay.code AS pay_code, pay.name AS pay_name,
    adv.code AS adv_code, adv.name AS adv_name
    FROM `{$TSC}` sc
    LEFT JOIN `{$TAC}` pay ON pay.id=sc.payable_account_id
    LEFT JOIN `{$TAC}` adv ON adv.id=sc.account_id
    ORDER BY sc.name")->fetchAll();

// التحقق من الإعدادات
$hasSettings = $pdo->query("SELECT COUNT(*) FROM `{$TIAS}`
    WHERE setting_key IN ('shipping_payable','shipping_advance')")->fetchColumn() >= 2;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>شركات الشحن — <?= htmlspecialchars($branchName) ?></title>
<link rel="icon" href="/bayhas/assets/images/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="/bayhas/assets/css/layout.css" rel="stylesheet">
<style>
.card-sc{background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:1.25rem}
.sc-hdr{padding:12px 20px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
table.mtbl{width:100%;border-collapse:collapse;font-size:.82rem}
table.mtbl th{background:#f8fafc;padding:8px 14px;font-weight:600;color:#64748b;font-size:.73rem;border-bottom:1px solid #f1f5f9;white-space:nowrap}
table.mtbl td{padding:9px 14px;border-bottom:1px solid #f8fafc;vertical-align:middle}
table.mtbl tr:hover td{background:#fafbff}
.ab{width:28px;height:28px;border-radius:7px;border:1px solid #e2e8f0;background:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;color:#64748b;cursor:pointer;transition:all .12s}
.ab:hover{background:#eff6ff;color:#1e3a8a;border-color:#bfdbfe}
.ab.red:hover{background:#fee2e2;color:#dc2626;border-color:#fca5a5}
.acc-badge{font-size:.68rem;padding:2px 7px;border-radius:5px;background:#f1f5f9;color:#475569;font-family:monospace}
.field-lbl{font-size:.76rem;font-weight:700;color:#475569;margin-bottom:4px;display:block}
.req{color:#dc2626}
</style>
</head>
<body>
<div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
<?php require_once __DIR__.'/../../../includes/sidebar.php'; ?>
<header class="topbar">
    <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
    <span class="tb-title"><i class="bi bi-truck me-1 text-warning"></i>شركات الشحن ومزودو الخدمة</span>
    <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
</header>
<main class="main-content"><div class="content-body">

<?php if (!$hasSettings): ?>
<div class="alert alert-warning border-0 rounded-3 mb-4" style="font-size:.83rem">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    يرجى ضبط <strong>إعدادات الربط المحاسبي</strong> أولاً:
    حساب <code>shipping_payable</code> (ذمم شركات الشحن) و<code>shipping_advance</code> (الدفعات المقدمة).
    <a href="account_settings.php" class="alert-link">اذهب للإعدادات</a>
</div>
<?php endif; ?>

<div class="d-flex gap-2 mb-4">
    <button class="btn btn-sm fw-600" style="border-radius:9px;background:#1e3a8a;color:#fff;border:none"
            onclick="openAdd()">
        <i class="bi bi-plus-lg me-1"></i>إضافة شركة شحن
    </button>
</div>

<div class="card-sc">
    <div class="sc-hdr">
        <span style="font-size:.9rem;font-weight:700;color:#1e293b">
            <i class="bi bi-truck me-2 text-warning"></i>شركات الشحن
            <span style="font-size:.75rem;color:#94a3b8;font-weight:400">(<?= count($carriers) ?>)</span>
        </span>
    </div>
    <div class="table-responsive">
    <table class="mtbl">
        <thead><tr>
            <th>#</th><th>اسم الشركة</th><th>المسؤول</th><th>التواصل</th>
            <th>حساب الذمة</th><th>حساب الدفعات المقدمة</th>
            <th>الحالة</th><th style="text-align:center">إجراءات</th>
        </tr></thead>
        <tbody>
        <?php if(empty($carriers)): ?>
        <tr><td colspan="8" class="text-center text-muted py-5">
            <i class="bi bi-truck" style="font-size:2rem;color:#e2e8f0"></i>
            <div class="mt-2" style="font-size:.83rem">لا توجد شركات شحن — أضف أول شركة</div>
        </td></tr>
        <?php endif; ?>
        <?php foreach($carriers as $i=>$sc): ?>
        <tr>
            <td class="text-muted" style="font-size:.75rem"><?=$i+1?></td>
            <td>
                <div class="fw-700" style="font-size:.86rem"><?=htmlspecialchars($sc['name'])?></div>
                <?php if($sc['city']): ?><div style="font-size:.71rem;color:#94a3b8"><?=htmlspecialchars($sc['city'])?></div><?php endif; ?>
            </td>
            <td>
                <?php if($sc['contact_person']): ?>
                <div style="font-size:.82rem"><?=htmlspecialchars($sc['contact_person'])?></div>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
            <td>
                <?php if($sc['phone']): ?><div style="font-size:.78rem;direction:ltr"><?=htmlspecialchars($sc['phone'])?></div><?php endif; ?>
                <?php if($sc['mobile']): ?><div style="font-size:.78rem;direction:ltr;color:#64748b"><?=htmlspecialchars($sc['mobile'])?></div><?php endif; ?>
            </td>
            <td>
                <?php if($sc['pay_code']): ?>
                <span class="acc-badge"><?=htmlspecialchars($sc['pay_code'])?></span>
                <div style="font-size:.7rem;color:#64748b;margin-top:2px"><?=htmlspecialchars($sc['pay_name'])?></div>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
            <td>
                <?php if($sc['adv_code']): ?>
                <span class="acc-badge"><?=htmlspecialchars($sc['adv_code'])?></span>
                <div style="font-size:.7rem;color:#64748b;margin-top:2px"><?=htmlspecialchars($sc['adv_name'])?></div>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
            <td>
                <span class="badge <?=$sc['status']==='active'?'bg-success-subtle text-success':'bg-secondary-subtle text-secondary'?>"
                      style="font-size:.68rem;cursor:pointer" onclick="toggleStatus(<?=$sc['id']?>)">
                    <?=$sc['status']==='active'?'نشطة':'معطلة'?>
                </span>
            </td>
            <td>
                <div class="d-flex gap-1 justify-content-center">
                    <button class="ab" onclick="openEdit(<?=$sc['id']?>)" title="تعديل"><i class="bi bi-pencil"></i></button>
                    <button class="ab red" onclick="deleteCarrier(<?=$sc['id']?>,'<?=htmlspecialchars($sc['name'],ENT_QUOTES)?>')" title="حذف"><i class="bi bi-trash"></i></button>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

</div></main>

<!-- مودال الإضافة/التعديل -->
<div class="modal fade" id="scModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#92400e,#d97706);border-radius:16px 16px 0 0">
        <h6 class="modal-title text-white fw-700 mb-0" id="mTitle">
            <i class="bi bi-truck me-2"></i>إضافة شركة شحن
        </h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 pt-3">
        <input type="hidden" id="mId">
        <div class="row g-3">
          <!-- المعلومات الأساسية -->
          <div class="col-12"><div style="font-size:.75rem;font-weight:700;color:#d97706;text-transform:uppercase;letter-spacing:.5px;padding-bottom:4px;border-bottom:1px solid #fde68a">المعلومات الأساسية</div></div>
          <div class="col-md-6">
            <label class="field-lbl">اسم الشركة / صاحب النقلية <span class="req">*</span></label>
            <input type="text" id="mName" class="form-control form-control-sm" placeholder="مثال: شركة النقل السريع">
          </div>
          <div class="col-md-6">
            <label class="field-lbl">اسم المسؤول / جهة الاتصال</label>
            <input type="text" id="mContact" class="form-control form-control-sm" placeholder="اسم الشخص المسؤول">
          </div>
          <div class="col-md-4">
            <label class="field-lbl">هاتف</label>
            <input type="text" id="mPhone" class="form-control form-control-sm" dir="ltr">
          </div>
          <div class="col-md-4">
            <label class="field-lbl">جوال</label>
            <input type="text" id="mMobile" class="form-control form-control-sm" dir="ltr">
          </div>
          <div class="col-md-4">
            <label class="field-lbl">بريد إلكتروني</label>
            <input type="email" id="mEmail" class="form-control form-control-sm" dir="ltr">
          </div>

          <!-- العنوان -->
          <div class="col-12"><div style="font-size:.75rem;font-weight:700;color:#d97706;text-transform:uppercase;letter-spacing:.5px;padding-bottom:4px;border-bottom:1px solid #fde68a;margin-top:4px">العنوان</div></div>
          <div class="col-md-4">
            <label class="field-lbl">المدينة</label>
            <input type="text" id="mCity" class="form-control form-control-sm">
          </div>
          <div class="col-md-4">
            <label class="field-lbl">الدولة</label>
            <input type="text" id="mCountry" class="form-control form-control-sm" value="Syria">
          </div>
          <div class="col-md-4">
            <label class="field-lbl">الرقم الضريبي</label>
            <input type="text" id="mTax" class="form-control form-control-sm" dir="ltr">
          </div>
          <div class="col-12">
            <label class="field-lbl">العنوان التفصيلي</label>
            <input type="text" id="mAddress" class="form-control form-control-sm">
          </div>
          <div class="col-md-6">
            <label class="field-lbl">الموقع الإلكتروني</label>
            <input type="url" id="mWebsite" class="form-control form-control-sm" dir="ltr" placeholder="https://...">
          </div>
          <div class="col-md-6">
            <label class="field-lbl">الحالة</label>
            <select id="mStatus" class="form-select form-select-sm">
              <option value="active">نشطة</option>
              <option value="inactive">معطلة</option>
            </select>
          </div>
          <div class="col-12">
            <label class="field-lbl">ملاحظات</label>
            <textarea id="mNotes" class="form-control form-control-sm" rows="2" placeholder="اختياري"></textarea>
          </div>

          <!-- الحسابات (للتعديل فقط) -->
          <div id="mAccSection" style="display:none" class="col-12">
            <div style="font-size:.75rem;font-weight:700;color:#1e3a8a;text-transform:uppercase;letter-spacing:.5px;padding-bottom:4px;border-bottom:1px solid #bfdbfe;margin-top:4px">الحسابات المحاسبية</div>
            <div class="row g-2 mt-1">
              <div class="col-md-6">
                <label class="field-lbl">حساب الذمة</label>
                <div style="background:#f1f5f9;border-radius:7px;padding:6px 10px;font-size:.8rem">
                  <span class="acc-badge" id="mPayCode"></span>
                  <span id="mPayName" class="ms-1 text-muted"></span>
                </div>
              </div>
              <div class="col-md-6">
                <label class="field-lbl">حساب الدفعات المقدمة</label>
                <div style="background:#f1f5f9;border-radius:7px;padding:6px 10px;font-size:.8rem">
                  <span class="acc-badge" id="mAdvCode"></span>
                  <span id="mAdvName" class="ms-1 text-muted"></span>
                </div>
              </div>
            </div>
          </div>

          <!-- تنبيه للإضافة الجديدة -->
          <div id="mNewAccNote" class="col-12">
            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:8px 12px;font-size:.78rem;color:#92400e">
              <i class="bi bi-info-circle me-1"></i>
              سيتم إنشاء حسابات محاسبية تلقائياً عند الحفظ:
              <br>• حساب ذمة تحت: <strong>ذمم شركات الشحن</strong>
              <br>• حساب دفعات مقدمة تحت: <strong>الدفعات المقدمة لمزودي الخدمة</strong>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 px-4 pb-4">
        <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
        <button class="btn btn-sm fw-600" style="border-radius:8px;background:#d97706;color:#fff;min-width:120px"
                onclick="saveCarrier()" id="btnSave">
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

const scModal=new bootstrap.Modal(document.getElementById('scModal'));

function post(data){
    const fd=new FormData();
    Object.entries(data).forEach(([k,v])=>fd.append(k,v??''));
    return fetch(location.href,{method:'POST',body:fd}).then(r=>r.json());
}
function toast(msg,type='success'){
    const t=document.createElement('div');t.className=`alert alert-${type} shadow`;
    t.style.cssText='position:fixed;top:70px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:260px;text-align:center;font-size:.83rem;padding:.55rem 1.2rem';
    t.innerHTML=`<i class="bi bi-${type==='success'?'check-circle-fill text-success':'exclamation-triangle-fill text-danger'} me-2"></i>${msg}`;
    document.body.appendChild(t);setTimeout(()=>t.remove(),3500);
}

function resetForm(){
    ['mId','mName','mContact','mPhone','mMobile','mEmail',
     'mCity','mCountry','mAddress','mWebsite','mTax','mNotes']
    .forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
    document.getElementById('mCountry').value='Syria';
    document.getElementById('mStatus').value='active';
    document.getElementById('mAccSection').style.display='none';
    document.getElementById('mNewAccNote').style.display='';
}

function openAdd(){
    resetForm();
    document.getElementById('mTitle').innerHTML='<i class="bi bi-truck me-2"></i>إضافة شركة شحن جديدة';
    scModal.show();
}

function openEdit(id){
    post({_action:'get',id}).then(d=>{
        if(!d.ok){toast(d.msg,'danger');return;}
        const sc=d.data;
        document.getElementById('mId').value=sc.id;
        document.getElementById('mName').value=sc.name||'';
        document.getElementById('mContact').value=sc.contact_person||'';
        document.getElementById('mPhone').value=sc.phone||'';
        document.getElementById('mMobile').value=sc.mobile||'';
        document.getElementById('mEmail').value=sc.email||'';
        document.getElementById('mCity').value=sc.city||'';
        document.getElementById('mCountry').value=sc.country||'Syria';
        document.getElementById('mAddress').value=sc.address||'';
        document.getElementById('mWebsite').value=sc.website||'';
        document.getElementById('mTax').value=sc.tax_number||'';
        document.getElementById('mNotes').value=sc.notes||'';
        document.getElementById('mStatus').value=sc.status||'active';
        // الحسابات
        document.getElementById('mPayCode').textContent=sc.pay_code||'—';
        document.getElementById('mPayName').textContent=sc.pay_name||'';
        document.getElementById('mAdvCode').textContent=sc.adv_code||'—';
        document.getElementById('mAdvName').textContent=sc.adv_name||'';
        document.getElementById('mAccSection').style.display='';
        document.getElementById('mNewAccNote').style.display='none';
        document.getElementById('mTitle').innerHTML=`<i class="bi bi-pencil me-2"></i>تعديل: ${sc.name}`;
        scModal.show();
    });
}

function saveCarrier(){
    const name=document.getElementById('mName').value.trim();
    if(!name){toast('اسم الشركة مطلوب','danger');return;}
    document.getElementById('saveTxt').style.opacity='0';
    document.getElementById('saveSpin').style.display='inline-block';
    document.getElementById('btnSave').disabled=true;
    post({
        _action:'save',
        id:document.getElementById('mId').value,
        name,
        contact_person:document.getElementById('mContact').value,
        phone:document.getElementById('mPhone').value,
        mobile:document.getElementById('mMobile').value,
        email:document.getElementById('mEmail').value,
        city:document.getElementById('mCity').value,
        country:document.getElementById('mCountry').value,
        address:document.getElementById('mAddress').value,
        website:document.getElementById('mWebsite').value,
        tax_number:document.getElementById('mTax').value,
        notes:document.getElementById('mNotes').value,
        status:document.getElementById('mStatus').value,
    }).then(d=>{
        document.getElementById('saveTxt').style.opacity='1';
        document.getElementById('saveSpin').style.display='none';
        document.getElementById('btnSave').disabled=false;
        if(d.ok){toast('✅ '+d.msg);scModal.hide();setTimeout(()=>location.reload(),700);}
        else toast(d.msg,'danger');
    });
}

function toggleStatus(id){
    post({_action:'toggle_status',id}).then(d=>{
        if(d.ok){toast(d.status==='active'?'تم التفعيل':'تم التعطيل');setTimeout(()=>location.reload(),600);}
        else toast(d.msg,'danger');
    });
}

function deleteCarrier(id,name){
    if(!confirm(`حذف شركة الشحن "${name}"؟\nلا يمكن التراجع.`))return;
    post({_action:'delete',id}).then(d=>{
        if(d.ok){toast('تم الحذف');setTimeout(()=>location.reload(),600);}
        else toast(d.msg,'danger');
    });
}
</script>
</body>
</html>
