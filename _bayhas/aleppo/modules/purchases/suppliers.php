<?php
/**
 * purchases/suppliers.php — إدارة الموردين
 * المسار: /bayhas/aleppo/modules/purchases/suppliers.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('purchases.suppliers', 'view');
$currentModule = 'purchases.suppliers';

$TS  = $_SESSION['table_suffix'];
$TSP = "product_suppliers_{$TS}";
$TP  = "purchases_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        if ($act === 'get_supplier') {
            $id = (int)$_POST['id'];
            $st = $pdo->prepare("SELECT * FROM `{$TSP}` WHERE id=?");
            $st->execute([$id]);
            $sup = $st->fetch();
            if (!$sup) throw new Exception('المورد غير موجود');
            // إحصائيات
            $stats = $pdo->prepare("SELECT COUNT(*) AS cnt,
                COALESCE(SUM(final_amount_usd),0) AS total_usd,
                COALESCE(SUM(CASE WHEN payment_status='pending' THEN final_amount_usd END),0) AS balance_usd
                FROM `{$TP}` WHERE supplier_id=? AND status!='cancelled'");
            $stats->execute([$id]);
            $sup['stats'] = $stats->fetch();
            echo json_encode(['ok'=>true,'data'=>$sup]);
        }

        elseif ($act === 'save_supplier') {
            $id      = (int)($_POST['id'] ?? 0);
            $name    = trim($_POST['name'] ?? '');
            $contact = trim($_POST['contact_person'] ?? '');
            $phone   = trim($_POST['phone'] ?? '');
            $email   = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $tax_no  = trim($_POST['tax_number'] ?? '');
            $type    = $_POST['type'] ?? 'wholesaler';
            $supType = $_POST['supplier_type'] ?? 'product';
            $credit  = (float)($_POST['credit_limit'] ?? 0);
            $disc    = (float)($_POST['discount_percentage'] ?? 0);
            $notes   = trim($_POST['notes'] ?? '');
            $status  = $_POST['status'] ?? 'active';
            if (!$name) throw new Exception('اسم المورد مطلوب');
            if ($id) {
                requirePermission('purchases.suppliers','edit');
                $pdo->prepare("UPDATE `{$TSP}` SET name=?,contact_person=?,phone=?,email=?,
                    address=?,tax_number=?,type=?,supplier_type=?,credit_limit=?,
                    discount_percentage=?,notes=?,status=?,updated_by=?,updated_at=NOW()
                    WHERE id=?")
                    ->execute([$name,$contact,$phone,$email,$address,$tax_no,
                        $type,$supType,$credit,$disc,$notes,$status,
                        $_SESSION['user_id'],$id]);
            } else {
                requirePermission('purchases.suppliers','create');
                $pdo->prepare("INSERT INTO `{$TSP}`
                    (name,contact_person,phone,email,address,tax_number,type,supplier_type,
                     credit_limit,discount_percentage,notes,status,created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$name,$contact,$phone,$email,$address,$tax_no,
                        $type,$supType,$credit,$disc,$notes,$status,$_SESSION['user_id']]);
                $id = (int)$pdo->lastInsertId();
            }
            echo json_encode(['ok'=>true,'id'=>$id]);
        }

        elseif ($act === 'toggle_status') {
            requirePermission('purchases.suppliers','edit');
            $id = (int)$_POST['id'];
            $pdo->prepare("UPDATE `{$TSP}` SET status=IF(status='active','inactive','active'),
                updated_by=?,updated_at=NOW() WHERE id=?")
                ->execute([$_SESSION['user_id'],$id]);
            $row=$pdo->prepare("SELECT status FROM `{$TSP}` WHERE id=?");
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
$where='WHERE 1=1'; $params=[];
if ($search)  { $where.=' AND (s.name LIKE ? OR s.phone LIKE ? OR s.contact_person LIKE ?)'; $params[]="%{$search}%"; $params[]="%{$search}%"; $params[]="%{$search}%"; }
if ($typeF)   { $where.=' AND s.supplier_type=?'; $params[]=$typeF; }
if ($statusF) { $where.=' AND s.status=?'; $params[]=$statusF; }

$stmt=$pdo->prepare("SELECT s.*,
    COUNT(DISTINCT p.id) AS invoices_cnt,
    COALESCE(SUM(p.final_amount_usd),0) AS total_usd,
    COALESCE(SUM(CASE WHEN p.payment_status='pending' AND p.status='received' THEN p.final_amount_usd END),0) AS balance_usd
    FROM `{$TSP}` s
    LEFT JOIN `{$TP}` p ON p.supplier_id=s.id AND p.status!='cancelled'
    {$where}
    GROUP BY s.id ORDER BY s.name");
$stmt->execute($params);
$suppliers=$stmt->fetchAll();

// إحصائيات عامة
$stats=$pdo->query("SELECT COUNT(*) AS total, SUM(status='active') AS active,
    SUM(supplier_type='product') AS product_type,
    SUM(supplier_type='consumable') AS consumable_type,
    SUM(supplier_type='both') AS both_type
    FROM `{$TSP}`")->fetch();

$TYPE_MAP=['manufacturer'=>'مصنّع','distributor'=>'موزّع','wholesaler'=>'جملة','retailer'=>'تجزئة'];
$SUP_TYPE_MAP=['product'=>['label'=>'مواد نهائية','cls'=>'bg-primary-subtle text-primary','icon'=>'bi-boxes'],
    'consumable'=>['label'=>'مستهلكات','cls'=>'bg-warning-subtle text-warning','icon'=>'bi-box-seam'],
    'both'=>['label'=>'الاثنان','cls'=>'bg-success-subtle text-success','icon'=>'bi-diagram-3']];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>إدارة الموردين — <?= htmlspecialchars($branchName) ?></title>
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
</style>
</head>
<body>
<div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
<?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
<header class="topbar">
    <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
    <span class="tb-title"><i class="bi bi-truck me-1 text-primary"></i>إدارة الموردين</span>
    <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
    <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
        <span class="text-primary">الموردون</span>
    </nav>
</header>
<main class="main-content"><div class="content-body">

<!-- إحصائيات -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#eff6ff"><i class="bi bi-truck text-primary"></i></div>
            <div><div class="stat-val"><?= $stats['total'] ?></div><div class="stat-lbl">إجمالي الموردين</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#f0fdf4"><i class="bi bi-check-circle text-success"></i></div>
            <div><div class="stat-val"><?= $stats['active'] ?></div><div class="stat-lbl">موردون نشطون</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#eff6ff"><i class="bi bi-boxes text-primary"></i></div>
            <div><div class="stat-val"><?= $stats['product_type'] ?></div><div class="stat-lbl">مواد نهائية</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fef3c7"><i class="bi bi-box-seam text-warning"></i></div>
            <div><div class="stat-val"><?= $stats['consumable_type'] ?></div><div class="stat-lbl">مستهلكات</div></div>
        </div>
    </div>
</div>

<!-- فلاتر + جدول -->
<div class="tbl-wrap">
    <div class="tbl-hdr">
        <span style="font-size:.88rem;font-weight:700;color:#1e293b">
            <i class="bi bi-truck me-1 text-primary"></i>قائمة الموردين
        </span>
        <form method="get" class="d-flex gap-2 flex-wrap align-items-center ms-auto">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                   placeholder="بحث بالاسم أو الهاتف..."
                   class="form-control form-control-sm" style="width:180px;border-radius:8px">
            <select name="type" class="form-select form-select-sm" style="width:130px;border-radius:8px" onchange="this.form.submit()">
                <option value="">كل الأنواع</option>
                <option value="product" <?= $typeF==='product'?'selected':'' ?>>مواد نهائية</option>
                <option value="consumable" <?= $typeF==='consumable'?'selected':'' ?>>مستهلكات</option>
                <option value="both" <?= $typeF==='both'?'selected':'' ?>>الاثنان</option>
            </select>
            <select name="status" class="form-select form-select-sm" style="width:110px;border-radius:8px" onchange="this.form.submit()">
                <option value="">كل الحالات</option>
                <option value="active" <?= $statusF==='active'?'selected':'' ?>>نشط</option>
                <option value="inactive" <?= $statusF==='inactive'?'selected':'' ?>>معطّل</option>
            </select>
            <button type="submit" class="btn btn-sm btn-primary" style="border-radius:8px">
                <i class="bi bi-search"></i>
            </button>
            <?php if ($search||$typeF||$statusF): ?>
            <a href="suppliers.php" class="btn btn-sm btn-light" style="border-radius:8px">
                <i class="bi bi-x-lg"></i>
            </a>
            <?php endif; ?>
        </form>
        <button class="btn btn-sm fw-600" style="border-radius:9px;background:#1e3a8a;color:#fff;font-size:.82rem"
                onclick="openAdd()">
            <i class="bi bi-plus-lg me-1"></i>مورد جديد
        </button>
    </div>
    <div class="table-responsive">
    <table class="mtbl">
        <thead><tr>
            <th>#</th><th>المورد</th><th>نوع المورد</th><th>نوع البضاعة</th>
            <th>الهاتف</th><th>حد الائتمان</th><th>خصم %</th>
            <th>الفواتير</th><th>المستحق</th><th>الحالة</th>
            <th style="text-align:center">إجراءات</th>
        </tr></thead>
        <tbody>
        <?php if (empty($suppliers)): ?>
        <tr><td colspan="11" class="text-center text-muted py-5">
            <i class="bi bi-truck d-block mb-2" style="font-size:2rem;opacity:.2"></i>
            لا يوجد موردون<?= $search?" يطابقون \"{$search}\"":'' ?>
        </td></tr>
        <?php endif; ?>
        <?php foreach ($suppliers as $i => $sup):
            $st  = $SUP_TYPE_MAP[$sup['supplier_type']] ?? $SUP_TYPE_MAP['product'];
            $init= mb_substr($sup['name'],0,1,'UTF-8');
        ?>
        <tr>
            <td class="text-muted" style="font-size:.75rem"><?= $i+1 ?></td>
            <td>
                <div class="d-flex align-items-center gap-2">
                    <div class="avatar"><?= $init ?></div>
                    <div>
                        <div class="fw-600"><?= htmlspecialchars($sup['name']) ?></div>
                        <?php if ($sup['contact_person']): ?>
                        <div class="text-muted" style="font-size:.72rem">
                            <i class="bi bi-person me-1"></i><?= htmlspecialchars($sup['contact_person']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </td>
            <td><span style="font-size:.75rem;color:#64748b"><?= $TYPE_MAP[$sup['type']] ?? $sup['type'] ?></span></td>
            <td>
                <span class="badge <?= $st['cls'] ?>" style="font-size:.68rem">
                    <i class="bi <?= $st['icon'] ?> me-1"></i><?= $st['label'] ?>
                </span>
            </td>
            <td dir="ltr" style="font-size:.8rem;color:#475569">
                <?= $sup['phone'] ? htmlspecialchars($sup['phone']) : '—' ?>
            </td>
            <td class="n" style="font-size:.78rem">
                <?= $sup['credit_limit']>0 ? number_format($sup['credit_limit'],2).' $' : '—' ?>
            </td>
            <td class="n text-center" style="font-size:.78rem">
                <?= $sup['discount_percentage']>0 ? $sup['discount_percentage'].'%' : '—' ?>
            </td>
            <td class="text-center">
                <span class="badge bg-secondary-subtle text-secondary"><?= $sup['invoices_cnt'] ?></span>
            </td>
            <td class="n <?= $sup['balance_usd']>0?'text-danger fw-600':'' ?>" style="font-size:.78rem">
                <?= $sup['balance_usd']>0 ? number_format($sup['balance_usd'],2).' $' : '—' ?>
            </td>
            <td>
                <?php if ($sup['status']==='active'): ?>
                <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size:.68rem">نشط</span>
                <?php else: ?>
                <span class="badge bg-secondary-subtle text-secondary" style="font-size:.68rem">معطّل</span>
                <?php endif; ?>
            </td>
            <td>
                <div class="d-flex gap-1 justify-content-center">
                    <button class="act-btn info-h" onclick="viewSupplier(<?=$sup['id']?>)" title="عرض التفاصيل">
                        <i class="bi bi-eye"></i>
                    </button>
                    <button class="act-btn" onclick="openEdit(<?=$sup['id']?>)" title="تعديل">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <a href="index.php?supplier=<?=$sup['id']?>" class="act-btn info-h" title="فواتير المورد">
                        <i class="bi bi-receipt"></i>
                    </a>
                    <button class="act-btn <?=$sup['status']==='active'?'danger':'success-h'?>"
                            onclick="toggleStatus(<?=$sup['id']?>)"
                            title="<?=$sup['status']==='active'?'تعطيل':'تفعيل'?>">
                        <i class="bi bi-<?=$sup['status']==='active'?'slash-circle':'check-circle'?>"></i>
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
<div class="modal fade" id="supModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#0c447c,#1e3a8a);border-radius:16px 16px 0 0">
        <h6 class="modal-title text-white fw-700 mb-0" id="mTitle">إضافة مورد جديد</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 pt-3">
        <input type="hidden" id="mId">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="field-lbl">اسم المورد <span class="req">*</span></label>
            <input type="text" id="mName" class="form-control form-control-sm" placeholder="اسم الشركة أو المورد">
          </div>
          <div class="col-md-3">
            <label class="field-lbl">نوع البضاعة <span class="req">*</span></label>
            <select id="mSupType" class="form-select form-select-sm">
                <option value="product">مواد نهائية</option>
                <option value="consumable">مستهلكات</option>
                <option value="both">الاثنان</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="field-lbl">نوع المورد</label>
            <select id="mType" class="form-select form-select-sm">
                <option value="wholesaler">موزّع بالجملة</option>
                <option value="manufacturer">مصنّع</option>
                <option value="distributor">موزّع</option>
                <option value="retailer">تاجر تجزئة</option>
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
            <input type="email" id="mEmail" class="form-control form-control-sm" dir="ltr" placeholder="example@mail.com">
          </div>
          <div class="col-md-6">
            <label class="field-lbl">العنوان</label>
            <input type="text" id="mAddress" class="form-control form-control-sm" placeholder="المدينة، الحي، الشارع">
          </div>
          <div class="col-md-3">
            <label class="field-lbl">الرقم الضريبي</label>
            <input type="text" id="mTaxNo" class="form-control form-control-sm" dir="ltr">
          </div>
          <div class="col-md-3">
            <label class="field-lbl">الحالة</label>
            <select id="mStatus" class="form-select form-select-sm">
                <option value="active">نشط</option>
                <option value="inactive">معطّل</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="field-lbl">حد الائتمان ($)</label>
            <input type="number" id="mCredit" class="form-control form-control-sm" min="0" step="0.01" placeholder="0.00" dir="ltr">
            <div style="font-size:.7rem;color:#94a3b8;margin-top:2px">الحد الأقصى للديون المسموح به</div>
          </div>
          <div class="col-md-4">
            <label class="field-lbl">نسبة الخصم الثابتة %</label>
            <input type="number" id="mDisc" class="form-control form-control-sm" min="0" max="100" step="0.01" placeholder="0" dir="ltr">
          </div>
          <div class="col-12">
            <label class="field-lbl">ملاحظات</label>
            <textarea id="mNotes" class="form-control form-control-sm" rows="2" placeholder="اختياري"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 px-4 pb-4">
        <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
        <button class="btn btn-sm fw-600" style="border-radius:8px;background:#1e3a8a;color:#fff;min-width:110px"
                onclick="saveSupplier()" id="btnSave">
          <span id="saveTxt"><i class="bi bi-floppy me-1"></i>حفظ</span>
          <span id="saveSpin" class="spinner-border spinner-border-sm" style="display:none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- مودال عرض التفاصيل -->
<div class="modal fade" id="viewModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#0c447c,#1e3a8a);border-radius:16px 16px 0 0">
        <h6 class="modal-title text-white fw-700 mb-0" id="vTitle">تفاصيل المورد</h6>
        <div class="d-flex gap-2 align-items-center">
          <button id="vEditBtn" class="btn btn-sm"
              style="border-radius:8px;background:rgba(255,255,255,.15);color:#fff;font-size:.76rem;border:1px solid rgba(255,255,255,.3)"
              onclick="">
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
const supModal  = new bootstrap.Modal(document.getElementById('supModal'));
const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
const SUP_TYPE_MAP = <?= json_encode($SUP_TYPE_MAP) ?>;
const TYPE_MAP     = <?= json_encode($TYPE_MAP) ?>;

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
    ['mId','mName','mContact','mPhone','mEmail','mAddress','mTaxNo','mCredit','mDisc','mNotes']
        .forEach(id=>document.getElementById(id).value='');
    document.getElementById('mSupType').value='product';
    document.getElementById('mType').value='wholesaler';
    document.getElementById('mStatus').value='active';
}
function openAdd(){
    resetForm();
    document.getElementById('mTitle').textContent='إضافة مورد جديد';
    supModal.show();
}
function openEdit(id){
    post({_action:'get_supplier',id}).then(d=>{
        if(!d.ok){toast(d.msg,'danger');return;}
        const s=d.data;
        document.getElementById('mId').value      = s.id;
        document.getElementById('mName').value    = s.name;
        document.getElementById('mContact').value = s.contact_person||'';
        document.getElementById('mPhone').value   = s.phone||'';
        document.getElementById('mEmail').value   = s.email||'';
        document.getElementById('mAddress').value = s.address||'';
        document.getElementById('mTaxNo').value   = s.tax_number||'';
        document.getElementById('mCredit').value  = s.credit_limit||'';
        document.getElementById('mDisc').value    = s.discount_percentage||'';
        document.getElementById('mNotes').value   = s.notes||'';
        document.getElementById('mSupType').value = s.supplier_type||'product';
        document.getElementById('mType').value    = s.type||'wholesaler';
        document.getElementById('mStatus').value  = s.status||'active';
        document.getElementById('mTitle').textContent = 'تعديل: '+s.name;
        viewModal.hide();
        supModal.show();
    });
}
function saveSupplier(){
    const name=document.getElementById('mName').value.trim();
    if(!name){toast('اسم المورد مطلوب','danger');return;}
    document.getElementById('saveTxt').style.opacity='0';
    document.getElementById('saveSpin').style.display='inline-block';
    post({
        _action:'save_supplier',
        id:             document.getElementById('mId').value,
        name,
        contact_person: document.getElementById('mContact').value,
        phone:          document.getElementById('mPhone').value,
        email:          document.getElementById('mEmail').value,
        address:        document.getElementById('mAddress').value,
        tax_number:     document.getElementById('mTaxNo').value,
        supplier_type:  document.getElementById('mSupType').value,
        type:           document.getElementById('mType').value,
        credit_limit:   document.getElementById('mCredit').value||0,
        discount_percentage: document.getElementById('mDisc').value||0,
        notes:          document.getElementById('mNotes').value,
        status:         document.getElementById('mStatus').value,
    }).then(d=>{
        document.getElementById('saveTxt').style.opacity='1';
        document.getElementById('saveSpin').style.display='none';
        if(d.ok){toast('تم الحفظ بنجاح');supModal.hide();setTimeout(()=>location.reload(),700);}
        else toast(d.msg,'danger');
    });
}
function viewSupplier(id){
    document.getElementById('vTitle').textContent='جارٍ التحميل...';
    document.getElementById('vBody').innerHTML='<div class="text-center py-4"><span class="spinner-border text-primary"></span></div>';
    document.getElementById('vEditBtn').onclick=()=>openEdit(id);
    viewModal.show();
    post({_action:'get_supplier',id}).then(d=>{
        if(!d.ok){document.getElementById('vBody').innerHTML=`<div class="text-danger p-3">${d.msg}</div>`;return;}
        const s=d.data;
        const st=SUP_TYPE_MAP[s.supplier_type]||SUP_TYPE_MAP['product'];
        document.getElementById('vTitle').textContent=s.name;
        const stats=s.stats||{};
        const rows=[
            ['نوع البضاعة', `<span class="badge ${st.cls}"><i class="bi ${st.icon} me-1"></i>${st.label}</span>`],
            ['نوع المورد', TYPE_MAP[s.type]||s.type],
            ['جهة الاتصال', s.contact_person||'—'],
            ['الهاتف', s.phone?`<span dir="ltr">${s.phone}</span>`:'—'],
            ['البريد', s.email?`<a href="mailto:${s.email}" dir="ltr">${s.email}</a>`:'—'],
            ['العنوان', s.address||'—'],
            ['الرقم الضريبي', s.tax_number?`<span dir="ltr">${s.tax_number}</span>`:'—'],
            ['حد الائتمان', s.credit_limit>0?parseFloat(s.credit_limit).toFixed(2)+' $':'—'],
            ['خصم ثابت', s.discount_percentage>0?s.discount_percentage+'%':'—'],
            ['الحالة', s.status==='active'?'<span class="badge bg-success-subtle text-success border border-success-subtle">نشط</span>':'<span class="badge bg-secondary-subtle text-secondary">معطّل</span>'],
        ].map(([k,v])=>`<div style="display:flex;justify-content:space-between;font-size:.8rem;padding:5px 0;border-bottom:1px solid #f8fafc">
            <span style="color:#64748b">${k}</span><span>${v}</span></div>`).join('');
        document.getElementById('vBody').innerHTML=`
        ${rows}
        <div style="background:#f8fafc;border-radius:10px;padding:10px 14px;margin-top:12px">
            <div style="font-size:.75rem;font-weight:700;color:#1e293b;margin-bottom:6px">إحصائيات المشتريات</div>
            <div style="display:flex;justify-content:space-between;font-size:.8rem;padding:3px 0">
                <span style="color:#64748b">عدد الفواتير</span>
                <span class="fw-600">${stats.cnt||0}</span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:.8rem;padding:3px 0">
                <span style="color:#64748b">إجمالي المشتريات</span>
                <span class="n fw-600">${parseFloat(stats.total_usd||0).toFixed(2)} $</span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:.8rem;padding:3px 0">
                <span style="color:#dc2626">المستحق</span>
                <span class="n fw-600 text-danger">${parseFloat(stats.balance_usd||0).toFixed(2)} $</span>
            </div>
        </div>
        <div style="margin-top:10px;display:flex;gap:8px">
            <a href="index.php?supplier=${s.id}" class="btn btn-sm fw-600"
               style="border-radius:8px;border:1px solid #1e3a8a;color:#1e3a8a;font-size:.78rem;flex:1;text-align:center;text-decoration:none">
               <i class="bi bi-receipt me-1"></i>فواتير المورد
            </a>
            <a href="#" class="btn btn-sm fw-600"
               style="border-radius:8px;border:1px solid #64748b;color:#64748b;font-size:.78rem;flex:1;text-align:center;text-decoration:none">
               <i class="bi bi-journal-text me-1"></i>كشف حساب
            </a>
        </div>
        ${s.notes?`<div style="background:#fef9ee;border-radius:8px;padding:8px 12px;margin-top:10px;font-size:.78rem;color:#64748b"><i class="bi bi-sticky me-1"></i>${s.notes}</div>`:''}`;
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
