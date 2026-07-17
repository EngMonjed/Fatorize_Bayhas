<?php
/**
 * sales/customers.php — إدارة العملاء
 * المسار: /bayhas/aleppo/modules/sales/customers.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('crm.customers', 'view');
$currentModule = 'crm.customers';

$TS  = $_SESSION['table_suffix'];
$TC  = "customers_{$TS}";
$TSI = "sales_invoices_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        if ($act === 'get_customer') {
            $id  = (int)$_POST['id'];
            $st  = $pdo->prepare("SELECT * FROM `{$TC}` WHERE id=?");
            $st->execute([$id]);
            $cust = $st->fetch();
            if (!$cust) throw new Exception('العميل غير موجود');
            // إحصائيات
            $stats = $pdo->prepare("SELECT
                COUNT(*) AS invoices,
                COALESCE(SUM(total_amount),0) AS total_usd,
                COALESCE(SUM(CASE WHEN payment_status IN ('pending','partial') THEN total_amount END),0) AS balance_usd,
                COALESCE(SUM(CASE WHEN status='confirmed' THEN 1 ELSE 0 END),0) AS confirmed
                FROM `{$TSI}` WHERE customer_id=? AND status!='cancelled'");
            $stats->execute([$id]);
            $cust['stats'] = $stats->fetch();
            echo json_encode(['ok'=>true,'data'=>$cust]);
        }

        elseif ($act === 'save_customer') {
            $id      = (int)($_POST['id'] ?? 0);
            $name    = trim($_POST['name'] ?? '');
            $contact = trim($_POST['contact_person'] ?? '');
            $type    = $_POST['type'] ?? 'individual';
            $phone   = trim($_POST['phone'] ?? '');
            $email   = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $taxNo   = trim($_POST['tax_number'] ?? '');
            $ship    = trim($_POST['shipping_company'] ?? '');
            $shipCode= trim($_POST['shipping_code'] ?? '');
            $status  = $_POST['status'] ?? 'active';
            if (!$name) throw new Exception('اسم العميل مطلوب');
            if ($id) {
                requirePermission('crm.customers','edit');
                $pdo->prepare("UPDATE `{$TC}` SET name=?,contact_person=?,type=?,phone=?,
                    email=?,address=?,tax_number=?,shipping_company=?,shipping_code=?,
                    status=?,updated_at=NOW() WHERE id=?")
                    ->execute([$name,$contact,$type,$phone,$email,$address,
                        $taxNo,$ship,$shipCode,$status,$id]);
            } else {
                requirePermission('crm.customers','create');
                $pdo->prepare("INSERT INTO `{$TC}`
                    (name,contact_person,type,phone,email,address,tax_number,
                     shipping_company,shipping_code,status)
                    VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$name,$contact,$type,$phone,$email,$address,
                        $taxNo,$ship,$shipCode,$status]);
                $id = (int)$pdo->lastInsertId();
            }
            echo json_encode(['ok'=>true,'id'=>$id]);
        }

        elseif ($act === 'toggle_status') {
            requirePermission('crm.customers','edit');
            $id = (int)$_POST['id'];
            $pdo->prepare("UPDATE `{$TC}` SET status=IF(status='active','inactive','active'),
                updated_at=NOW() WHERE id=?")
                ->execute([$id]);
            $row = $pdo->prepare("SELECT status FROM `{$TC}` WHERE id=?");
            $row->execute([$id]);
            echo json_encode(['ok'=>true,'status'=>$row->fetchColumn()]);
        }

        else throw new Exception('إجراء غير معروف');
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

// ── بيانات الصفحة ──────────────────────────────────────────────
$search  = trim($_GET['q'] ?? '');
$typeF   = $_GET['type'] ?? '';
$statusF = $_GET['status'] ?? '';
$where   = 'WHERE 1=1'; $params = [];
if ($search)  { $where.=' AND (c.name LIKE ? OR c.phone LIKE ? OR c.contact_person LIKE ?)'; $params[]="%{$search}%"; $params[]="%{$search}%"; $params[]="%{$search}%"; }
if ($typeF)   { $where.=' AND c.type=?'; $params[]=$typeF; }
if ($statusF) { $where.=' AND c.status=?'; $params[]=$statusF; }

$stmt = $pdo->prepare("SELECT c.*,
    COUNT(DISTINCT si.id) AS invoices_cnt,
    COALESCE(SUM(si.total_amount),0) AS total_usd,
    COALESCE(SUM(CASE WHEN si.payment_status IN ('pending','partial') AND si.status='confirmed' THEN si.total_amount END),0) AS balance_usd
    FROM `{$TC}` c
    LEFT JOIN `{$TSI}` si ON si.customer_id=c.id AND si.status!='cancelled'
    {$where}
    GROUP BY c.id ORDER BY c.name");
$stmt->execute($params);
$customers = $stmt->fetchAll();

try {
    $stats = $pdo->query("SELECT COUNT(*) AS total,
        SUM(status='active') AS active,
        SUM(type='individual') AS individuals,
        SUM(type='company') AS companies
        FROM `{$TC}`")->fetch();
} catch (Exception $e) {
    $stats = ['total'=>0,'active'=>0,'individuals'=>0,'companies'=>0];
}

$TYPE_MAP = ['individual'=>'فرد','company'=>'شركة'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>إدارة العملاء — <?= htmlspecialchars($branchName) ?></title>
<link rel="icon" href="/bayhas/assets/images/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="/bayhas/assets/css/layout.css" rel="stylesheet">
<style>
.stat-card{background:#fff;border-radius:14px;border:1px solid #e2e8f0;padding:12px 16px;display:flex;align-items:center;gap:10px}
.stat-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.stat-val{font-size:1.2rem;font-weight:700;color:#1e293b;line-height:1}
.stat-lbl{font-size:.7rem;color:#64748b;margin-top:2px}
.tbl-wrap{background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden}
.tbl-hdr{padding:12px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
table.mtbl{width:100%;border-collapse:collapse;font-size:.82rem}
table.mtbl th{background:#f8fafc;padding:8px 12px;font-weight:600;color:#64748b;font-size:.72rem;border-bottom:1px solid #f1f5f9;white-space:nowrap}
table.mtbl td{padding:8px 12px;border-bottom:1px solid #f8fafc;vertical-align:middle}
table.mtbl tr:last-child td{border-bottom:none}
table.mtbl tr:hover td{background:#f8faff}
.act-btn{width:28px;height:28px;border-radius:7px;border:1px solid #e2e8f0;background:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem;color:#64748b;cursor:pointer;transition:all .12s;text-decoration:none}
.act-btn:hover{background:#f1f5f9}
.act-btn.success-h:hover{background:#dcfce7;color:#16a34a;border-color:#86efac}
.act-btn.danger:hover{background:#fee2e2;color:#dc2626;border-color:#fca5a5}
.act-btn.info-h:hover{background:#e0f2fe;color:#0891b2;border-color:#7dd3fc}
.field-lbl{font-size:.76rem;font-weight:700;color:#475569;margin-bottom:4px;display:block}
.req{color:#dc2626}
.n{font-variant-numeric:tabular-nums}
.avatar{width:38px;height:38px;border-radius:10px;background:#eff6ff;display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:700;color:#1e3a8a;flex-shrink:0}
.avatar.company{background:#fef3c7;color:#92400e}
.det-row{display:flex;justify-content:space-between;font-size:.8rem;padding:5px 0;border-bottom:1px solid #f8fafc}
.det-row:last-child{border-bottom:none}
</style>
</head>
<body>
<div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
<?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
<header class="topbar">
    <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
    <span class="tb-title"><i class="bi bi-people me-1 text-primary"></i>إدارة العملاء</span>
    <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
    <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
        <span class="text-primary">العملاء</span>
    </nav>
</header>
<main class="main-content"><div class="content-body">

<!-- إحصائيات -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#eff6ff"><i class="bi bi-people text-primary"></i></div>
            <div><div class="stat-val"><?= $stats['total'] ?></div><div class="stat-lbl">إجمالي العملاء</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#f0fdf4"><i class="bi bi-person-check text-success"></i></div>
            <div><div class="stat-val"><?= $stats['active'] ?></div><div class="stat-lbl">عملاء نشطون</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#eff6ff"><i class="bi bi-person text-primary"></i></div>
            <div><div class="stat-val"><?= $stats['individuals'] ?></div><div class="stat-lbl">أفراد</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fef3c7"><i class="bi bi-building text-warning"></i></div>
            <div><div class="stat-val"><?= $stats['companies'] ?></div><div class="stat-lbl">شركات</div></div>
        </div>
    </div>
</div>

<!-- الجدول -->
<div class="tbl-wrap">
    <div class="tbl-hdr">
        <span style="font-size:.88rem;font-weight:700;color:#1e293b">
            <i class="bi bi-people me-1 text-primary"></i>قائمة العملاء
        </span>
        <form method="get" class="d-flex gap-2 flex-wrap align-items-center ms-auto">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                   placeholder="بحث بالاسم أو الهاتف..."
                   class="form-control form-control-sm" style="width:180px;border-radius:8px">
            <select name="type" class="form-select form-select-sm" style="width:110px;border-radius:8px" onchange="this.form.submit()">
                <option value="">كل الأنواع</option>
                <option value="individual" <?= $typeF==='individual'?'selected':'' ?>>فرد</option>
                <option value="company" <?= $typeF==='company'?'selected':'' ?>>شركة</option>
            </select>
            <select name="status" class="form-select form-select-sm" style="width:110px;border-radius:8px" onchange="this.form.submit()">
                <option value="">كل الحالات</option>
                <option value="active" <?= $statusF==='active'?'selected':'' ?>>نشط</option>
                <option value="inactive" <?= $statusF==='inactive'?'selected':'' ?>>معطّل</option>
            </select>
            <button type="submit" class="btn btn-sm btn-primary" style="border-radius:8px"><i class="bi bi-search"></i></button>
            <?php if ($search||$typeF||$statusF): ?>
            <a href="index.php" class="btn btn-sm btn-light" style="border-radius:8px"><i class="bi bi-x-lg"></i></a>
            <?php endif; ?>
        </form>
        <button class="btn btn-sm fw-600" style="border-radius:9px;background:#1e3a8a;color:#fff;font-size:.82rem"
                onclick="openAdd()">
            <i class="bi bi-plus-lg me-1"></i>عميل جديد
        </button>
    </div>
    <div class="table-responsive">
    <table class="mtbl">
        <thead><tr>
            <th>#</th><th>العميل</th><th>النوع</th><th>الهاتف</th>
            <th>شركة الشحن</th><th>الفواتير</th>
            <th>إجمالي المبيعات</th><th>المستحق</th>
            <th>الحالة</th><th style="text-align:center">إجراءات</th>
        </tr></thead>
        <tbody>
        <?php if (empty($customers)): ?>
        <tr><td colspan="10" class="text-center text-muted py-5">
            <i class="bi bi-people d-block mb-2" style="font-size:2rem;opacity:.2"></i>
            لا يوجد عملاء<?= $search?" يطابقون \"{$search}\"":'' ?>
        </td></tr>
        <?php endif; ?>
        <?php foreach ($customers as $i => $c):
            $init = mb_substr($c['name'],0,1,'UTF-8');
            $isCompany = $c['type']==='company';
        ?>
        <tr>
            <td class="text-muted" style="font-size:.75rem"><?= $i+1 ?></td>
            <td>
                <div class="d-flex align-items-center gap-2">
                    <div class="avatar <?= $isCompany?'company':'' ?>"><?= $init ?></div>
                    <div>
                        <div class="fw-600"><?= htmlspecialchars($c['name']) ?></div>
                        <?php if ($c['contact_person']): ?>
                        <div class="text-muted" style="font-size:.72rem">
                            <i class="bi bi-person me-1"></i><?= htmlspecialchars($c['contact_person']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </td>
            <td>
                <span class="badge <?= $isCompany?'bg-warning-subtle text-warning':'bg-secondary-subtle text-secondary' ?>" style="font-size:.68rem">
                    <i class="bi bi-<?= $isCompany?'building':'person' ?> me-1"></i><?= $TYPE_MAP[$c['type']] ?>
                </span>
            </td>
            <td dir="ltr" style="font-size:.8rem;color:#475569"><?= $c['phone']?htmlspecialchars($c['phone']):'—' ?></td>
            <td style="font-size:.78rem;color:#64748b">
                <?php if ($c['shipping_company']): ?>
                <div><?= htmlspecialchars($c['shipping_company']) ?></div>
                <?php if ($c['shipping_code']): ?>
                <div dir="ltr" style="font-size:.7rem;color:#94a3b8"><?= htmlspecialchars($c['shipping_code']) ?></div>
                <?php endif; ?>
                <?php else: echo '—'; endif; ?>
            </td>
            <td class="text-center">
                <span class="badge bg-secondary-subtle text-secondary"><?= $c['invoices_cnt'] ?></span>
            </td>
            <td class="n fw-600" style="font-size:.78rem"><?= $c['total_usd']>0?number_format($c['total_usd'],2).' $':'—' ?></td>
            <td class="n <?= $c['balance_usd']>0?'text-danger fw-600':'' ?>" style="font-size:.78rem">
                <?= $c['balance_usd']>0?number_format($c['balance_usd'],2).' $':'—' ?>
            </td>
            <td>
                <?php if ($c['status']==='active'): ?>
                <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size:.68rem">نشط</span>
                <?php else: ?>
                <span class="badge bg-secondary-subtle text-secondary" style="font-size:.68rem">معطّل</span>
                <?php endif; ?>
            </td>
            <td>
                <div class="d-flex gap-1 justify-content-center">
                    <button class="act-btn info-h" onclick="viewCustomer(<?=$c['id']?>)" title="عرض">
                        <i class="bi bi-eye"></i>
                    </button>
                    <button class="act-btn" onclick="openEdit(<?=$c['id']?>)" title="تعديل">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <a href="index.php?customer=<?=$c['id']?>" class="act-btn info-h" title="فواتير العميل">
                        <i class="bi bi-receipt"></i>
                    </a>
                    <a href="../customers/statement.php?id=<?=$c['id']?>" class="act-btn" title="كشف حساب">
                        <i class="bi bi-journal-text"></i>
                    </a>
                    <button class="act-btn <?=$c['status']==='active'?'danger':'success-h'?>"
                            onclick="toggleStatus(<?=$c['id']?>)" title="<?=$c['status']==='active'?'تعطيل':'تفعيل'?>">
                        <i class="bi bi-<?=$c['status']==='active'?'slash-circle':'check-circle'?>"></i>
                    </button>
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
<div class="modal fade" id="custModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#0c447c,#1e3a8a);border-radius:16px 16px 0 0">
        <h6 class="modal-title text-white fw-700 mb-0" id="mTitle">إضافة عميل جديد</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 pt-3">
        <input type="hidden" id="mId">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="field-lbl">اسم العميل <span class="req">*</span></label>
            <input type="text" id="mName" class="form-control form-control-sm" placeholder="الاسم الكامل أو اسم الشركة">
          </div>
          <div class="col-md-3">
            <label class="field-lbl">النوع</label>
            <select id="mType" class="form-select form-select-sm">
                <option value="individual">فرد</option>
                <option value="company">شركة</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="field-lbl">الحالة</label>
            <select id="mStatus" class="form-select form-select-sm">
                <option value="active">نشط</option>
                <option value="inactive">معطّل</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="field-lbl">جهة الاتصال</label>
            <input type="text" id="mContact" class="form-control form-control-sm" placeholder="اسم المسؤول">
          </div>
          <div class="col-md-4">
            <label class="field-lbl">رقم الهاتف</label>
            <input type="text" id="mPhone" class="form-control form-control-sm" dir="ltr" placeholder="+963...">
          </div>
          <div class="col-md-4">
            <label class="field-lbl">البريد الإلكتروني</label>
            <input type="email" id="mEmail" class="form-control form-control-sm" dir="ltr">
          </div>
          <div class="col-12">
            <label class="field-lbl">العنوان</label>
            <input type="text" id="mAddress" class="form-control form-control-sm" placeholder="المدينة، الحي...">
          </div>
          <div class="col-md-4">
            <label class="field-lbl">الرقم الضريبي</label>
            <input type="text" id="mTaxNo" class="form-control form-control-sm" dir="ltr">
          </div>
          <div class="col-md-4">
            <label class="field-lbl">شركة الشحن</label>
            <input type="text" id="mShip" class="form-control form-control-sm" placeholder="اسم شركة الشحن">
          </div>
          <div class="col-md-4">
            <label class="field-lbl">كود الشحن</label>
            <input type="text" id="mShipCode" class="form-control form-control-sm" dir="ltr" placeholder="رقم الحساب / الكود">
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 px-4 pb-4">
        <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
        <button class="btn btn-sm fw-600" style="border-radius:8px;background:#1e3a8a;color:#fff;min-width:100px"
                onclick="saveCustomer()" id="btnSave">
          <span id="saveTxt"><i class="bi bi-floppy me-1"></i>حفظ</span>
          <span id="saveSpin" class="spinner-border spinner-border-sm" style="display:none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- مودال عرض العميل -->
<div class="modal fade" id="viewModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#0c447c,#1e3a8a);border-radius:16px 16px 0 0">
        <div>
          <h6 class="modal-title text-white fw-700 mb-0" id="vTitle">تفاصيل العميل</h6>
          <div id="vSub" style="font-size:.72rem;color:rgba(255,255,255,.7);margin-top:2px"></div>
        </div>
        <div class="d-flex gap-2 align-items-center">
          <button id="vEditBtn" class="btn btn-sm"
              style="border-radius:8px;background:rgba(255,255,255,.15);color:#fff;font-size:.76rem;border:1px solid rgba(255,255,255,.3)">
              <i class="bi bi-pencil me-1"></i>تعديل
          </button>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
      </div>
      <div class="modal-body px-4 py-3" id="vBody">
        <div class="text-center py-4"><span class="spinner-border text-primary"></span></div>
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
function toggleGroup(g){
    const o=g.classList.contains('open');
    document.querySelectorAll('.sb-group.open').forEach(x=>x.classList.remove('open'));
    g.classList.toggle('open',!o);
    localStorage.setItem('sb_open_'+g.dataset.key,(!o).toString());
}
document.querySelectorAll('.sb-group').forEach(g=>{
    if(localStorage.getItem('sb_open_'+g.dataset.key)==='true')g.classList.add('open');
});

const custModal = new bootstrap.Modal(document.getElementById('custModal'));
const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
const TYPE_MAP  = <?= json_encode($TYPE_MAP) ?>;

function post(data){
    const fd=new FormData();
    Object.entries(data).forEach(([k,v])=>fd.append(k,v??''));
    return fetch(location.href,{method:'POST',body:fd}).then(r=>r.json());
}
function toast(msg,type='success'){
    const t=document.createElement('div');
    t.className=`alert alert-${type} shadow`;
    t.style.cssText='position:fixed;top:70px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:240px;text-align:center;font-size:.83rem;padding:.5rem 1.2rem';
    t.innerHTML=`<i class="bi bi-${type==='success'?'check-circle-fill text-success':'exclamation-triangle-fill text-danger'} me-2"></i>${msg}`;
    document.body.appendChild(t);setTimeout(()=>t.remove(),3000);
}

function resetForm(){
    ['mId','mName','mContact','mPhone','mEmail','mAddress','mTaxNo','mShip','mShipCode']
        .forEach(id=>document.getElementById(id).value='');
    document.getElementById('mType').value='individual';
    document.getElementById('mStatus').value='active';
}
function openAdd(){
    resetForm();
    document.getElementById('mTitle').textContent='إضافة عميل جديد';
    custModal.show();
}
function openEdit(id){
    post({_action:'get_customer',id}).then(d=>{
        if(!d.ok){toast(d.msg,'danger');return;}
        const c=d.data;
        document.getElementById('mId').value       = c.id;
        document.getElementById('mName').value     = c.name;
        document.getElementById('mContact').value  = c.contact_person||'';
        document.getElementById('mType').value     = c.type||'individual';
        document.getElementById('mPhone').value    = c.phone||'';
        document.getElementById('mEmail').value    = c.email||'';
        document.getElementById('mAddress').value  = c.address||'';
        document.getElementById('mTaxNo').value    = c.tax_number||'';
        document.getElementById('mShip').value     = c.shipping_company||'';
        document.getElementById('mShipCode').value = c.shipping_code||'';
        document.getElementById('mStatus').value   = c.status||'active';
        document.getElementById('mTitle').textContent='تعديل: '+c.name;
        viewModal.hide();
        custModal.show();
    });
}
function saveCustomer(){
    const name=document.getElementById('mName').value.trim();
    if(!name){toast('اسم العميل مطلوب','danger');return;}
    document.getElementById('saveTxt').style.opacity='0';
    document.getElementById('saveSpin').style.display='inline-block';
    post({
        _action:'save_customer',
        id:             document.getElementById('mId').value,
        name,
        contact_person: document.getElementById('mContact').value,
        type:           document.getElementById('mType').value,
        phone:          document.getElementById('mPhone').value,
        email:          document.getElementById('mEmail').value,
        address:        document.getElementById('mAddress').value,
        tax_number:     document.getElementById('mTaxNo').value,
        shipping_company:document.getElementById('mShip').value,
        shipping_code:  document.getElementById('mShipCode').value,
        status:         document.getElementById('mStatus').value,
    }).then(d=>{
        document.getElementById('saveTxt').style.opacity='1';
        document.getElementById('saveSpin').style.display='none';
        if(d.ok){toast('تم الحفظ بنجاح');custModal.hide();setTimeout(()=>location.reload(),700);}
        else toast(d.msg,'danger');
    });
}
function viewCustomer(id){
    document.getElementById('vTitle').textContent='جارٍ التحميل...';
    document.getElementById('vSub').textContent='';
    document.getElementById('vEditBtn').onclick=()=>openEdit(id);
    document.getElementById('vBody').innerHTML='<div class="text-center py-4"><span class="spinner-border text-primary"></span></div>';
    viewModal.show();
    post({_action:'get_customer',id}).then(d=>{
        if(!d.ok){document.getElementById('vBody').innerHTML=`<div class="text-danger p-3">${d.msg}</div>`;return;}
        const c=d.data;
        const st=d.data.stats||{};
        document.getElementById('vTitle').textContent=c.name;
        document.getElementById('vSub').textContent=TYPE_MAP[c.type]||'';
        const rows=[
            ['النوع', c.type==='company'?'<span class="badge bg-warning-subtle text-warning">شركة</span>':'<span class="badge bg-secondary-subtle text-secondary">فرد</span>'],
            ['جهة الاتصال', c.contact_person||'—'],
            ['الهاتف', c.phone?`<span dir="ltr">${c.phone}</span>`:'—'],
            ['البريد', c.email?`<a href="mailto:${c.email}" dir="ltr">${c.email}</a>`:'—'],
            ['العنوان', c.address||'—'],
            ['الرقم الضريبي', c.tax_number?`<span dir="ltr">${c.tax_number}</span>`:'—'],
            ['شركة الشحن', c.shipping_company||'—'],
            ['كود الشحن', c.shipping_code?`<span dir="ltr">${c.shipping_code}</span>`:'—'],
            ['الحالة', c.status==='active'?'<span class="badge bg-success-subtle text-success border border-success-subtle">نشط</span>':'<span class="badge bg-secondary-subtle text-secondary">معطّل</span>'],
        ].map(([k,v])=>`<div class="det-row"><span style="color:#64748b">${k}</span><span>${v}</span></div>`).join('');

        document.getElementById('vBody').innerHTML=`
        ${rows}
        <div style="background:#f8fafc;border-radius:10px;padding:10px 14px;margin-top:12px">
            <div style="font-size:.75rem;font-weight:700;color:#1e293b;margin-bottom:6px">إحصائيات المبيعات</div>
            <div class="det-row"><span style="color:#64748b">عدد الفواتير</span><span class="fw-600">${st.invoices||0}</span></div>
            <div class="det-row"><span style="color:#64748b">إجمالي المبيعات</span><span class="n fw-600">${parseFloat(st.total_usd||0).toFixed(2)} $</span></div>
            <div class="det-row"><span style="color:#dc2626">المستحق</span><span class="n fw-600 text-danger">${parseFloat(st.balance_usd||0).toFixed(2)} $</span></div>
        </div>
        <div style="margin-top:10px;display:flex;gap:8px">
            <a href="index.php?customer=${c.id}" class="btn btn-sm fw-600"
               style="border-radius:8px;border:1px solid #1e3a8a;color:#1e3a8a;flex:1;text-align:center;text-decoration:none;font-size:.78rem">
               <i class="bi bi-receipt me-1"></i>فواتير العميل
            </a>
            <a href="../customers/statement.php?id=${c.id}" class="btn btn-sm fw-600"
               style="border-radius:8px;border:1px solid #64748b;color:#64748b;flex:1;text-align:center;text-decoration:none;font-size:.78rem">
               <i class="bi bi-journal-text me-1"></i>كشف حساب
            </a>
        </div>`;
    });
}
function toggleStatus(id){
    post({_action:'toggle_status',id}).then(d=>{
        if(d.ok){toast(d.status==='active'?'تم التفعيل':'تم التعطيل');setTimeout(()=>location.reload(),600);}
        else toast(d.msg,'danger');
    });
}
</script>
</body>
</html>
