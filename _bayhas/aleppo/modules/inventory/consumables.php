<?php
/**
 * consumables.php — إدارة المستهلكات
 * المسار: /bayhas/aleppo/modules/inventory/consumables.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('inventory.consumables', 'view');

$TS  = $_SESSION['table_suffix'];
$TI  = "consumable_items_alp";
$TST = "consumable_stock_alp";
$TW  = "warehouses_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        if ($act === 'get_item') {
            $id = (int)$_POST['id'];
            $st = $pdo->prepare("SELECT ci.*,
                COALESCE(SUM(cs.quantity),0) AS total_qty,
                MAX(cs.min_quantity)         AS min_qty,
                MAX(cs.avg_cost_usd)         AS avg_cost
                FROM `{$TI}` ci
                LEFT JOIN `{$TST}` cs ON cs.item_id=ci.id
                WHERE ci.id=? GROUP BY ci.id");
            $st->execute([$id]);
            $item = $st->fetch();
            if (!$item) throw new Exception('المادة غير موجودة');
            $stk = $pdo->prepare("SELECT cs.*, w.name AS wh_name FROM `{$TST}` cs
                JOIN `{$TW}` w ON w.id=cs.warehouse_id WHERE cs.item_id=?");
            $stk->execute([$id]);
            $item['stock'] = $stk->fetchAll();
            echo json_encode(['ok'=>true,'data'=>$item]);
        }

        elseif ($act === 'save_item') {
            $id       = (int)($_POST['id'] ?? 0);
            $name     = trim($_POST['name'] ?? '');
            $category = $_POST['category'] ?? 'other';
            $unit     = trim($_POST['unit'] ?? 'قطعة');
            $est_cost = (float)($_POST['estimated_cost'] ?? 0);
            $min_qty  = (float)($_POST['min_quantity'] ?? 0);
            $wh_id    = (int)($_POST['warehouse_id'] ?? 0);
            $notes    = trim($_POST['notes'] ?? '');
            if (!$name) throw new Exception('اسم المادة مطلوب');
            if ($id) {
                requirePermission('inventory.consumables','edit');
                $pdo->prepare("UPDATE `{$TI}` SET name=?,category=?,unit=?,estimated_cost=?,notes=?,updated_at=NOW() WHERE id=?")
                    ->execute([$name,$category,$unit,$est_cost,$notes,$id]);
            } else {
                requirePermission('inventory.consumables','create');
                $pdo->prepare("INSERT INTO `{$TI}` (name,category,unit,estimated_cost,notes,created_by) VALUES (?,?,?,?,?,?)")
                    ->execute([$name,$category,$unit,$est_cost,$notes,$_SESSION['user_id']]);
                $id = (int)$pdo->lastInsertId();
            }
            if ($wh_id && $id) {
                $pdo->prepare("INSERT INTO `{$TST}` (item_id,warehouse_id,quantity,min_quantity) VALUES (?,?,0,?)
                    ON DUPLICATE KEY UPDATE min_quantity=VALUES(min_quantity)")
                    ->execute([$id,$wh_id,$min_qty]);
            }
            echo json_encode(['ok'=>true,'id'=>$id]);
        }

        elseif ($act === 'toggle_item') {
            requirePermission('inventory.consumables','edit');
            $id = (int)$_POST['id'];
            $pdo->prepare("UPDATE `{$TI}` SET is_active=NOT is_active WHERE id=?")->execute([$id]);
            $row = $pdo->prepare("SELECT is_active FROM `{$TI}` WHERE id=?");
            $row->execute([$id]);
            echo json_encode(['ok'=>true,'is_active'=>(int)$row->fetchColumn()]);
        }

        elseif ($act === 'delete_item') {
            requirePermission('inventory.consumables','delete');
            $id = (int)$_POST['id'];
            $chk = $pdo->prepare("SELECT COUNT(*) FROM `consumable_movements_alp` WHERE item_id=?");
            $chk->execute([$id]);
            if ($chk->fetchColumn() > 0)
                throw new Exception('لا يمكن حذف مادة لها حركات — يمكنك تعطيلها فقط');
            $pdo->prepare("DELETE FROM `{$TST}` WHERE item_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM `{$TI}` WHERE id=?")->execute([$id]);
            echo json_encode(['ok'=>true]);
        }

        else throw new Exception('إجراء غير معروف');
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

// ── بيانات الصفحة ──────────────────────────────────────────────
$search    = trim($_GET['q'] ?? '');
$filterCat = $_GET['cat'] ?? '';
$where = 'WHERE 1=1'; $params = [];
if ($search)    { $where .= ' AND ci.name LIKE ?'; $params[] = "%{$search}%"; }
if ($filterCat) { $where .= ' AND ci.category=?';  $params[] = $filterCat; }

$stmt = $pdo->prepare("SELECT ci.*,
    COALESCE(SUM(cs.quantity),0)                    AS total_qty,
    MAX(cs.min_quantity)                             AS min_qty,
    COALESCE(AVG(NULLIF(cs.avg_cost_usd,0)),0)      AS avg_cost,
    COUNT(DISTINCT cs.warehouse_id)                  AS wh_count
    FROM `{$TI}` ci
    LEFT JOIN `{$TST}` cs ON cs.item_id=ci.id
    {$where}
    GROUP BY ci.id ORDER BY ci.category, ci.name");
$stmt->execute($params);
$items = $stmt->fetchAll();

$warehouses = $pdo->query("SELECT * FROM `{$TW}` WHERE is_active=1 ORDER BY id")->fetchAll();

// إحصائيات
$s = $pdo->query("SELECT COUNT(*) AS total, SUM(is_active) AS active FROM `{$TI}`")->fetch();
try {
    $low = $pdo->query("SELECT COUNT(DISTINCT item_id) FROM `{$TST}` WHERE quantity>0 AND min_quantity>0 AND quantity<=min_quantity")->fetchColumn();
    $out = $pdo->query("SELECT COUNT(DISTINCT ci.id) FROM `{$TI}` ci LEFT JOIN `{$TST}` cs ON cs.item_id=ci.id WHERE ci.is_active=1 HAVING COALESCE(SUM(cs.quantity),0)=0")->fetchColumn();
} catch(Exception $e) { $low=0; $out=0; }

$CATS = [
    'utility'     => ['label'=>'مرافق',   'clr'=>'#0891b2','bg'=>'#e0f7fa'],
    'supplies'    => ['label'=>'قرطاسية', 'clr'=>'#7c3aed','bg'=>'#f3e8ff'],
    'food'        => ['label'=>'مأكولات', 'clr'=>'#d97706','bg'=>'#fef3c7'],
    'maintenance' => ['label'=>'صيانة',   'clr'=>'#dc2626','bg'=>'#fee2e2'],
    'other'       => ['label'=>'أخرى',    'clr'=>'#64748b','bg'=>'#f1f5f9'],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>إدارة المستهلكات — <?= htmlspecialchars($branchName) ?></title>
<link rel="icon" href="/bayhas/assets/images/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="/bayhas/assets/css/layout.css" rel="stylesheet">
<style>
.stat-card{background:#fff;border-radius:14px;border:1px solid #e2e8f0;padding:14px 18px;display:flex;align-items:center;gap:12px}
.stat-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0}
.stat-val{font-size:1.4rem;font-weight:700;color:#1e293b;line-height:1}
.stat-lbl{font-size:.73rem;color:#64748b;margin-top:2px}
.tbl-wrap{background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden}
.tbl-hdr{padding:12px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
table.mtbl{width:100%;border-collapse:collapse;font-size:.83rem}
table.mtbl th{background:#f8fafc;padding:9px 12px;font-weight:600;color:#64748b;font-size:.73rem;border-bottom:1px solid #f1f5f9;white-space:nowrap}
table.mtbl td{padding:9px 12px;border-bottom:1px solid #f8fafc;vertical-align:middle}
table.mtbl tr:last-child td{border-bottom:none}
table.mtbl tr:hover td{background:#f8faff}
.cat-badge{display:inline-flex;align-items:center;border-radius:20px;font-size:.7rem;padding:2px 9px;font-weight:600;border:1px solid}
.act-btn{width:30px;height:30px;border-radius:7px;border:1px solid #e2e8f0;background:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.82rem;color:#64748b;cursor:pointer;transition:all .12s;text-decoration:none}
.act-btn:hover{background:#f1f5f9;color:#1e293b}
.act-btn.danger:hover{background:#fee2e2;color:#dc2626;border-color:#fca5a5}
.act-btn.success-h:hover{background:#dcfce7;color:#16a34a;border-color:#86efac}
.field-lbl{font-size:.75rem;font-weight:600;color:#64748b;margin-bottom:4px;display:block}
.req{color:#dc2626}
.field-hint{font-size:.7rem;color:#94a3b8;margin-top:3px}
.srow{display:flex;justify-content:space-between;font-size:.78rem;padding:4px 0;border-bottom:1px solid #f1f5f9}
.srow:last-child{border-bottom:none}
.n{font-variant-numeric:tabular-nums}
</style>
</head>
<body>
<div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
<?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>

<header class="topbar">
    <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
    <span class="tb-title"><i class="bi bi-box-seam me-1 text-primary"></i>المستهلكات</span>
    <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
    <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
        <span>المخزون</span><i class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
        <span class="text-primary">إدارة المستهلكات</span>
    </nav>
</header>

<main class="main-content"><div class="content-body">

<!-- إحصائيات -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#eff6ff"><i class="bi bi-box-seam text-primary"></i></div>
            <div><div class="stat-val"><?= $s['total'] ?></div><div class="stat-lbl">إجمالي المواد</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#f0fdf4"><i class="bi bi-check-circle text-success"></i></div>
            <div><div class="stat-val"><?= $s['active'] ?></div><div class="stat-lbl">مواد نشطة</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fef3c7"><i class="bi bi-exclamation-triangle text-warning"></i></div>
            <div><div class="stat-val"><?= $low ?></div><div class="stat-lbl">مخزون منخفض</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fee2e2"><i class="bi bi-x-circle text-danger"></i></div>
            <div><div class="stat-val"><?= $out ?></div><div class="stat-lbl">نفد المخزون</div></div>
        </div>
    </div>
</div>

<!-- الجدول -->
<div class="tbl-wrap">
    <div class="tbl-hdr">
        <span style="font-size:.88rem;font-weight:700;color:#1e293b">
            <i class="bi bi-list-ul me-1 text-primary"></i>قائمة المستهلكات
        </span>
        <div class="d-flex gap-2 ms-auto flex-wrap align-items-center">
            <form method="get" class="d-flex gap-2">
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                       placeholder="بحث..." class="form-control form-control-sm" style="width:150px;border-radius:8px">
                <select name="cat" class="form-select form-select-sm" style="width:130px;border-radius:8px" onchange="this.form.submit()">
                    <option value="">كل الفئات</option>
                    <?php foreach ($CATS as $k=>$v): ?>
                    <option value="<?=$k?>" <?= $filterCat===$k?'selected':'' ?>><?= $v['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <button class="btn btn-sm fw-600" style="border-radius:9px;background:#1e3a8a;color:#fff;font-size:.82rem"
                    onclick="openAdd()">
                <i class="bi bi-plus-lg me-1"></i>مادة جديدة
            </button>
        </div>
    </div>
    <div class="table-responsive">
    <table class="mtbl">
        <thead><tr>
            <th>#</th><th>اسم المادة</th><th>الفئة</th><th>الوحدة</th>
            <th>الرصيد</th><th>حد التنبيه</th><th>التكلفة التقديرية</th>
            <th>الحالة</th><th style="text-align:center">إجراءات</th>
        </tr></thead>
        <tbody>
        <?php if (empty($items)): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">
            <i class="bi bi-box-seam d-block mb-2" style="font-size:2rem;opacity:.2"></i>
            لا توجد مواد<?= $search ? " تطابق \"{$search}\"" : '' ?>
        </td></tr>
        <?php endif; ?>
        <?php foreach ($items as $i => $item):
            $cat  = $CATS[$item['category']] ?? $CATS['other'];
            $qty  = (float)$item['total_qty'];
            $minQ = (float)$item['min_qty'];
            $qClr = $qty<=0 ? '#dc2626' : ($minQ>0&&$qty<=$minQ ? '#d97706' : '#16a34a');
        ?>
        <tr id="iRow_<?=$item['id']?>">
            <td class="text-muted small"><?=$i+1?></td>
            <td>
                <div class="fw-600"><?= htmlspecialchars($item['name']) ?></div>
                <?php if ($item['notes']): ?>
                <div class="text-muted" style="font-size:.7rem"><?= htmlspecialchars(mb_substr($item['notes'],0,40)) ?>...</div>
                <?php endif; ?>
            </td>
            <td>
                <span class="cat-badge" style="color:<?=$cat['clr']?>;background:<?=$cat['bg']?>;border-color:<?=$cat['clr']?>55">
                    <?= $cat['label'] ?>
                </span>
            </td>
            <td style="color:#64748b;font-size:.78rem"><?= htmlspecialchars($item['unit']) ?></td>
            <td class="n fw-600" style="color:<?=$qClr?>">
                <?= number_format($qty,2) ?>
                <span style="font-weight:400;font-size:.7rem;color:#94a3b8"><?= htmlspecialchars($item['unit']) ?></span>
            </td>
            <td class="n text-muted"><?= $minQ>0 ? number_format($minQ,2) : '—' ?></td>
            <td class="n text-muted"><?= $item['estimated_cost']>0 ? number_format($item['estimated_cost'],2).' $' : '—' ?></td>
            <td>
                <?php if ($item['is_active']): ?>
                <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size:.68rem">نشط</span>
                <?php else: ?>
                <span class="badge bg-secondary-subtle text-secondary" style="font-size:.68rem">معطّل</span>
                <?php endif; ?>
            </td>
            <td>
                <div class="d-flex gap-1 justify-content-center">
                    <button class="act-btn" onclick="viewItem(<?=$item['id']?>)" title="تفاصيل" style="color:#0891b2;border-color:#a5f3fc">
                        <i class="bi bi-eye"></i>
                    </button>
                    <button class="act-btn" onclick="openEdit(<?=$item['id']?>)" title="تعديل">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="act-btn <?=$item['is_active']?'danger':'success-h'?>"
                            onclick="toggleItem(<?=$item['id']?>)"
                            title="<?=$item['is_active']?'تعطيل':'تفعيل'?>">
                        <i class="bi bi-<?=$item['is_active']?'slash-circle':'check-circle'?>"></i>
                    </button>
                    <button class="act-btn danger" onclick="deleteItem(<?=$item['id']?>,'<?= htmlspecialchars($item['name'],ENT_QUOTES) ?>')" title="حذف">
                        <i class="bi bi-trash"></i>
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

<!-- مودال الإضافة/التعديل -->
<div class="modal fade" id="itemModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#0c447c,#1e3a8a);border-radius:16px 16px 0 0">
        <h6 class="modal-title text-white fw-700 mb-0" id="mTitle">إضافة مادة استهلاكية</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 pt-3">
        <input type="hidden" id="mId">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="field-lbl">اسم المادة <span class="req">*</span></label>
            <input type="text" id="mName" class="form-control form-control-sm" placeholder="مثال: قهوة، قرطاسية، غاز">
          </div>
          <div class="col-md-6">
            <label class="field-lbl">الفئة <span class="req">*</span></label>
            <select id="mCategory" class="form-select form-select-sm">
              <option value="utility">مرافق (كهرباء، ماء، إنترنت)</option>
              <option value="supplies">قرطاسية ومستلزمات</option>
              <option value="food">مأكولات ومشروبات</option>
              <option value="maintenance">صيانة وتشغيل</option>
              <option value="other">أخرى</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="field-lbl">وحدة القياس <span class="req">*</span></label>
            <input type="text" id="mUnit" class="form-control form-control-sm" placeholder="قطعة، كيلو، لتر، فاتورة">
          </div>
          <div class="col-md-4">
            <label class="field-lbl">التكلفة التقديرية ($)</label>
            <input type="number" id="mEstCost" class="form-control form-control-sm" min="0" step="0.01" placeholder="0.00">
            <div class="field-hint">للمقارنة مع التكلفة الفعلية</div>
          </div>
          <div class="col-md-4">
            <label class="field-lbl">حد التنبيه (الكمية)</label>
            <input type="number" id="mMinQty" class="form-control form-control-sm" min="0" step="0.001" placeholder="0">
            <div class="field-hint">تنبيه عند انخفاض المخزون</div>
          </div>
          <div class="col-md-6">
            <label class="field-lbl">المستودع الافتراضي</label>
            <select id="mWarehouse" class="form-select form-select-sm">
              <option value="">— اختر المستودع —</option>
              <?php foreach ($warehouses as $wh): ?>
              <option value="<?=$wh['id']?>"><?= htmlspecialchars($wh['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="field-lbl">ملاحظات</label>
            <textarea id="mNotes" class="form-control form-control-sm" rows="2" placeholder="اختياري"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 px-4 pb-4">
        <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
        <button class="btn btn-sm fw-600" style="border-radius:8px;background:#1e3a8a;color:#fff;min-width:100px" onclick="saveItem()">
            <span id="saveTxt"><i class="bi bi-floppy me-1"></i>حفظ</span>
            <span id="saveSpin" class="spinner-border spinner-border-sm" style="display:none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- مودال التفاصيل -->
<div class="modal fade" id="viewModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#0c447c,#1e3a8a);border-radius:16px 16px 0 0">
        <h6 class="modal-title text-white fw-700 mb-0" id="vTitle">تفاصيل المادة</h6>
        <div class="d-flex gap-2 align-items-center">
          <button class="btn btn-sm" id="vEditBtn"
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
const sb = document.getElementById('sidebar'), ov = document.getElementById('sbOverlay');
function sbOpen()  { sb.classList.add('open');  ov.classList.add('show'); }
function sbClose() { sb.classList.remove('open'); ov.classList.remove('show'); }

const itemModal = new bootstrap.Modal(document.getElementById('itemModal'));
const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));

const CATS = <?= json_encode($CATS) ?>;

function post(data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k, v??''));
    return fetch(location.href,{method:'POST',body:fd}).then(r=>r.json());
}
function toast(msg, type='success') {
    const t = document.createElement('div');
    t.className = `alert alert-${type} shadow`;
    t.style.cssText='position:fixed;top:70px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:220px;text-align:center;font-size:.83rem;padding:.5rem 1.2rem';
    t.innerHTML=`<i class="bi bi-${type==='success'?'check-circle-fill text-success':'exclamation-triangle-fill text-danger'} me-2"></i>${msg}`;
    document.body.appendChild(t);
    setTimeout(()=>t.remove(),3000);
}

function openAdd() {
    ['mId','mName','mEstCost','mMinQty','mNotes'].forEach(id=>document.getElementById(id).value='');
    document.getElementById('mCategory').value='other';
    document.getElementById('mUnit').value='قطعة';
    document.getElementById('mWarehouse').value='';
    document.getElementById('mTitle').textContent='إضافة مادة استهلاكية';
    itemModal.show();
}

function openEdit(id) {
    post({_action:'get_item',id}).then(d=>{
        if(!d.ok){toast(d.msg,'danger');return;}
        const it=d.data;
        document.getElementById('mId').value       = it.id;
        document.getElementById('mName').value     = it.name;
        document.getElementById('mCategory').value = it.category;
        document.getElementById('mUnit').value     = it.unit;
        document.getElementById('mEstCost').value  = it.estimated_cost||'';
        document.getElementById('mMinQty').value   = it.min_qty||'';
        document.getElementById('mNotes').value    = it.notes||'';
        const stk=it.stock||[];
        document.getElementById('mWarehouse').value= stk.length?stk[0].warehouse_id:'';
        document.getElementById('mTitle').textContent='تعديل: '+it.name;
        viewModal.hide();
        itemModal.show();
    });
}

function saveItem() {
    const name=document.getElementById('mName').value.trim();
    if(!name){toast('اسم المادة مطلوب','danger');return;}
    document.getElementById('saveTxt').style.opacity='0';
    document.getElementById('saveSpin').style.display='inline-block';
    post({
        _action:'save_item',
        id:             document.getElementById('mId').value,
        name,
        category:       document.getElementById('mCategory').value,
        unit:           document.getElementById('mUnit').value,
        estimated_cost: document.getElementById('mEstCost').value,
        min_quantity:   document.getElementById('mMinQty').value,
        warehouse_id:   document.getElementById('mWarehouse').value,
        notes:          document.getElementById('mNotes').value,
    }).then(d=>{
        document.getElementById('saveTxt').style.opacity='1';
        document.getElementById('saveSpin').style.display='none';
        if(d.ok){toast('تم الحفظ بنجاح');itemModal.hide();setTimeout(()=>location.reload(),700);}
        else toast(d.msg,'danger');
    });
}

function viewItem(id) {
    document.getElementById('vTitle').textContent='جارٍ التحميل...';
    document.getElementById('vBody').innerHTML='<div class="text-center py-4"><span class="spinner-border text-primary"></span></div>';
    document.getElementById('vEditBtn').onclick=()=>openEdit(id);
    viewModal.show();
    post({_action:'get_item',id}).then(d=>{
        if(!d.ok){document.getElementById('vBody').innerHTML=`<div class="text-danger p-3">${d.msg}</div>`;return;}
        const it=d.data;
        const cat=CATS[it.category]||CATS['other'];
        const qty=parseFloat(it.total_qty||0);
        const minQ=parseFloat(it.min_qty||0);
        const qClr=qty<=0?'#dc2626':(minQ>0&&qty<=minQ?'#d97706':'#16a34a');
        document.getElementById('vTitle').textContent=it.name;
        const stkHtml=(it.stock||[]).map(s=>`
            <div class="srow">
                <span style="color:#64748b">${s.wh_name}</span>
                <span class="n fw-600" style="color:${parseFloat(s.quantity)<=0?'#dc2626':'#1e293b'}">
                    ${parseFloat(s.quantity).toFixed(2)} ${it.unit}
                    ${s.min_quantity>0?`<span style="font-size:.68rem;color:#94a3b8"> (حد: ${parseFloat(s.min_quantity).toFixed(0)})</span>`:''}
                </span>
            </div>`).join('')||'<div class="text-muted text-center py-2" style="font-size:.78rem">لا يوجد رصيد</div>';
        document.getElementById('vBody').innerHTML=`
        <div class="d-flex align-items-center gap-2 mb-3 mt-1">
            <span class="cat-badge" style="color:${cat.clr};background:${cat.bg};border-color:${cat.clr}55;font-size:.72rem;padding:3px 10px;border-radius:20px;font-weight:600;border:1px solid">${cat.label}</span>
            <span style="font-size:.78rem;color:#64748b">وحدة: ${it.unit}</span>
            <span class="badge ms-auto ${it.is_active=='1'?'bg-success-subtle text-success border border-success-subtle':'bg-secondary-subtle text-secondary'}" style="font-size:.68rem">${it.is_active=='1'?'نشط':'معطّل'}</span>
        </div>
        <div style="background:#f8fafc;border-radius:10px;padding:10px 14px;margin-bottom:10px">
            <div class="srow"><span style="color:#64748b">الرصيد الكلي</span><span class="n fw-700" style="color:${qClr}">${qty.toFixed(2)} ${it.unit}</span></div>
            <div class="srow"><span style="color:#64748b">متوسط التكلفة</span><span class="n">${parseFloat(it.avg_cost||0)>0?parseFloat(it.avg_cost).toFixed(4)+' $':'—'}</span></div>
            <div class="srow"><span style="color:#64748b">التكلفة التقديرية</span><span class="n">${parseFloat(it.estimated_cost||0)>0?parseFloat(it.estimated_cost).toFixed(2)+' $':'—'}</span></div>
        </div>
        <div style="font-size:.75rem;font-weight:700;color:#1e293b;margin-bottom:5px">الأرصدة بالمستودعات</div>
        <div>${stkHtml}</div>
        ${it.notes?`<div style="background:#f8fafc;border-radius:8px;padding:8px 12px;margin-top:10px;font-size:.78rem;color:#64748b">${it.notes}</div>`:''}`;
    });
}

function toggleItem(id) {
    post({_action:'toggle_item',id}).then(d=>{
        if(d.ok){toast(d.is_active?'تم التفعيل':'تم التعطيل');setTimeout(()=>location.reload(),600);}
        else toast(d.msg,'danger');
    });
}

function deleteItem(id, name) {
    if(!confirm(`حذف "${name}" نهائياً؟\nلا يمكن الحذف إذا كانت للمادة حركات مخزون.`))return;
    post({_action:'delete_item',id}).then(d=>{
        if(d.ok){toast('تم الحذف');setTimeout(()=>location.reload(),600);}
        else toast(d.msg,'danger');
    });
}
</script>
</body>
</html>
