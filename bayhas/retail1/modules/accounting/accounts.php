<?php
/**
 * accounting/accounts.php — شجرة الحسابات
 * المسار: /bayhas/aleppo/modules/accounting/accounts.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('finance.accounts', 'view');
$currentModule = 'finance.accounts';

$TS  = $_SESSION['table_suffix'];
$TAC = "account_charts_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        if ($act === 'save_account') {
            $id       = (int)($_POST['id'] ?? 0);
            $code     = trim($_POST['code'] ?? '');
            $name     = trim($_POST['name'] ?? '');
            $parentId = (int)($_POST['parent_id'] ?? 0) ?: null;
            $type     = $_POST['account_type'] ?? 'asset';
            $currency_id = (int)($_POST['currency_id'] ?? 1);
            $desc     = trim($_POST['description'] ?? '');

            if (!$code) throw new Exception('رمز الحساب مطلوب');
            if (!$name) throw new Exception('اسم الحساب مطلوب');

            // حساب المستوى
            $level = 1;
            if ($parentId) {
                $pSt = $pdo->prepare("SELECT level FROM `{$TAC}` WHERE id=?");
                $pSt->execute([$parentId]);
                $pLevel = $pSt->fetchColumn();
                $level  = ($pLevel ?: 1) + 1;
            }

            if ($id) {
                requirePermission('finance.accounts', 'edit');
                // التحقق من أنه غير مقفل
                $locked = $pdo->prepare("SELECT is_locked FROM `{$TAC}` WHERE id=?");
                $locked->execute([$id]);
                if ($locked->fetchColumn()) throw new Exception('هذا الحساب مقفل ولا يمكن تعديله');

                $pdo->prepare("UPDATE `{$TAC}` SET code=?,name=?,parent_id=?,account_type=?,
                    currency_id=?,level=?,description=?,updated_by=?,updated_at=NOW() WHERE id=?")
                    ->execute([$code,$name,$parentId,$type,$currency_id,$level,$desc,$_SESSION['user_id'],$id]);
            } else {
                requirePermission('finance.accounts', 'create');
                // التحقق من تكرار الكود
                $dup = $pdo->prepare("SELECT COUNT(*) FROM `{$TAC}` WHERE code=?");
                $dup->execute([$code]);
                if ($dup->fetchColumn()) throw new Exception("رمز الحساب {$code} مستخدم مسبقاً");

                $pdo->prepare("INSERT INTO `{$TAC}` (code,name,parent_id,account_type,currency_id,level,description,created_by)
                    VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$code,$name,$parentId,$type,$currency_id,$level,$desc,$_SESSION['user_id']]);
                $id = (int)$pdo->lastInsertId();
            }
            echo json_encode(['ok'=>true,'id'=>$id]);
        }

        elseif ($act === 'delete_account') {
            requirePermission('finance.accounts', 'delete');
            $id = (int)$_POST['id'];

            // التحقق من القفل
            $st = $pdo->prepare("SELECT is_locked, name FROM `{$TAC}` WHERE id=?");
            $st->execute([$id]);
            $acc = $st->fetch();
            if (!$acc) throw new Exception('الحساب غير موجود');
            if ($acc['is_locked']) throw new Exception('حساب نظامي مقفل لا يمكن حذفه');

            // التحقق من عدم وجود أبناء
            $children = $pdo->prepare("SELECT COUNT(*) FROM `{$TAC}` WHERE parent_id=?");
            $children->execute([$id]);
            if ($children->fetchColumn()) throw new Exception('لا يمكن حذف حساب له حسابات فرعية');

            $pdo->prepare("DELETE FROM `{$TAC}` WHERE id=?")->execute([$id]);
            echo json_encode(['ok'=>true]);
        }

        elseif ($act === 'toggle_active') {
            requirePermission('finance.accounts', 'edit');
            $id = (int)$_POST['id'];
            $st = $pdo->prepare("SELECT is_locked FROM `{$TAC}` WHERE id=?");
            $st->execute([$id]);
            if ($st->fetchColumn()) throw new Exception('حساب نظامي لا يمكن تعطيله');
            $pdo->prepare("UPDATE `{$TAC}` SET is_active=IF(is_active=1,0,1) WHERE id=?")->execute([$id]);
            $new = $pdo->prepare("SELECT is_active FROM `{$TAC}` WHERE id=?");
            $new->execute([$id]);
            echo json_encode(['ok'=>true,'active'=>(int)$new->fetchColumn()]);
        }

        else throw new Exception('إجراء غير معروف');
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

// ── بيانات الصفحة ──
$accounts = $pdo->query("SELECT ac.*, c.code AS cur_code, c.symbol AS cur_sym
    FROM `{$TAC}` ac LEFT JOIN currencies c ON c.id=ac.currency_id
    ORDER BY ac.code")->fetchAll();

// بناء الشجرة
$tree = [];
$map  = [];
foreach ($accounts as $acc) {
    $acc['children'] = [];
    $map[$acc['id']] = $acc;
}
foreach ($map as $id => &$acc) {
    if ($acc['parent_id'] && isset($map[$acc['parent_id']])) {
        $map[$acc['parent_id']]['children'][] = &$acc;
    } else {
        $tree[] = &$acc;
    }
}

$TYPE_MAP = [
    'asset'    => ['label'=>'أصول',          'cls'=>'bg-blue-subtle text-primary',   'color'=>'#1e3a8a'],
    'liability'=> ['label'=>'التزامات',       'cls'=>'bg-danger-subtle text-danger',  'color'=>'#dc2626'],
    'equity'   => ['label'=>'حقوق الملكية',  'cls'=>'bg-purple-subtle text-purple',  'color'=>'#7c3aed'],
    'revenue'  => ['label'=>'إيرادات',        'cls'=>'bg-success-subtle text-success','color'=>'#16a34a'],
    'expense'  => ['label'=>'مصاريف',         'cls'=>'bg-warning-subtle text-warning','color'=>'#d97706'],
];
$CURRENCY_MAP = ['USD'=>'$','SYP'=>'ل.س','TRY'=>'₺','EUR'=>'€'];
$curs = $pdo->query("SELECT id,code,name,symbol FROM currencies WHERE status='active' ORDER BY is_base DESC,id")->fetchAll();

// دالة لرسم الشجرة
function renderTree(array $nodes, array $TYPE_MAP, array $CURRENCY_MAP, int $depth=0): void {
    foreach ($nodes as $acc) {
        $tm      = $TYPE_MAP[$acc['account_type']] ?? $TYPE_MAP['asset'];
        $indent  = $depth * 24;
        $hasKids = !empty($acc['children']);
        $locked  = $acc['is_locked'];
        $active  = $acc['is_active'];
        $sym     = $acc['cur_sym'] ?? '$';
        ?>
        <tr class="acc-row lvl-<?= $acc['level'] ?> <?= !$active?'text-muted':'' ?>"
            data-id="<?= $acc['id'] ?>" data-parent="<?= $acc['parent_id']??'' ?>">
            <td style="padding-right:<?= $indent+12 ?>px;white-space:nowrap">
                <?php if ($hasKids): ?>
                <button class="tree-toggle btn btn-link p-0 me-1" style="font-size:.7rem;color:#94a3b8" onclick="toggleBranch(<?=$acc['id']?>)">
                    <i class="bi bi-chevron-down" id="chevron_<?=$acc['id']?>"></i>
                </button>
                <?php else: ?>
                <span style="display:inline-block;width:20px"></span>
                <?php endif; ?>
                <?php if ($locked): ?>
                <i class="bi bi-lock-fill me-1" style="font-size:.65rem;color:#94a3b8"></i>
                <?php endif; ?>
                <span style="font-weight:<?= $acc['level']<=2?'700':'400' ?>;font-size:<?= $acc['level']===1?'.88rem':'.82rem' ?>">
                    <?= htmlspecialchars($acc['name']) ?>
                </span>
            </td>
            <td dir="ltr" style="font-size:.78rem;font-weight:600;color:#475569"><?= htmlspecialchars($acc['code']) ?></td>
            <td>
                <span class="badge" style="font-size:.65rem;background:<?= $tm['color'] ?>1a;color:<?= $tm['color'] ?>;border:1px solid <?= $tm['color'] ?>33">
                    <?= $tm['label'] ?>
                </span>
            </td>
            <td style="font-size:.75rem"><?= $sym ?> <?= $acc['currency'] ?></td>
            <td class="n" style="font-size:.78rem">
                <?php if ($acc['base_balance'] != 0): ?>
                <span style="color:<?= $acc['base_balance']>0?'#16a34a':'#dc2626' ?>">
                    $ <?= number_format(abs($acc['base_balance']),2) ?>
                </span>
                <?php else: ?>
                <span style="color:#cbd5e1">—</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($active): ?>
                <span class="badge bg-success-subtle text-success" style="font-size:.65rem">نشط</span>
                <?php else: ?>
                <span class="badge bg-secondary-subtle text-secondary" style="font-size:.65rem">معطّل</span>
                <?php endif; ?>
            </td>
            <td>
                <div class="d-flex gap-1">
                    <?php if (!$locked): ?>
                    <button class="act-btn" onclick="openEdit(<?=$acc['id']?>)" title="تعديل">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <?php endif; ?>
                    <button class="act-btn success-h" onclick="openAddChild(<?=$acc['id']?>, '<?=htmlspecialchars($acc['name'],ENT_QUOTES)?>', '<?=$acc['account_type']?>')"
                            title="إضافة حساب فرعي">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                    <?php if (!$locked): ?>
                    <button class="act-btn <?= $active?'danger':'success-h' ?>"
                            onclick="toggleActive(<?=$acc['id']?>)" title="<?= $active?'تعطيل':'تفعيل' ?>">
                        <i class="bi bi-<?= $active?'slash-circle':'check-circle' ?>"></i>
                    </button>
                    <?php if (!$hasKids): ?>
                    <button class="act-btn danger" onclick="deleteAccount(<?=$acc['id']?>, '<?=htmlspecialchars($acc['name'],ENT_QUOTES)?>')" title="حذف">
                        <i class="bi bi-trash"></i>
                    </button>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php
        if ($hasKids) renderTree($acc['children'], $TYPE_MAP, $CURRENCY_MAP, $depth+1);
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>شجرة الحسابات — <?= htmlspecialchars($branchName) ?></title>
<link rel="icon" href="/bayhas/assets/images/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="/bayhas/assets/css/layout.css" rel="stylesheet">
<style>
.tbl-wrap{background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden}
.tbl-hdr{padding:12px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
table.mtbl{width:100%;border-collapse:collapse;font-size:.82rem}
table.mtbl th{background:#f8fafc;padding:8px 12px;font-weight:600;color:#64748b;font-size:.72rem;border-bottom:1px solid #f1f5f9;white-space:nowrap}
table.mtbl td{padding:6px 12px;border-bottom:1px solid #f8fafc;vertical-align:middle}
.acc-row.lvl-1>td{background:#f8fafc!important;font-weight:700}
.acc-row.lvl-2>td{background:#fafafa}
.acc-row:hover>td{background:#f0f9ff!important}
.acc-row.hidden{display:none}
.act-btn{width:26px;height:26px;border-radius:7px;border:1px solid #e2e8f0;background:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;color:#64748b;cursor:pointer;transition:all .12s}
.act-btn:hover{background:#f1f5f9}
.act-btn.success-h:hover{background:#dcfce7;color:#16a34a;border-color:#86efac}
.act-btn.danger:hover{background:#fee2e2;color:#dc2626;border-color:#fca5a5}
.act-btn.danger{color:#dc2626;border-color:#fca5a5}
.tree-toggle{border:none!important;background:transparent!important}
.field-lbl{font-size:.76rem;font-weight:700;color:#475569;margin-bottom:4px;display:block}
.req{color:#dc2626}
.n{font-variant-numeric:tabular-nums}
.spin{animation:spin .6s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
/* إحصائيات */
.acc-stat{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:10px 14px}
.acc-stat-val{font-size:1rem;font-weight:700;color:#1e293b}
.acc-stat-lbl{font-size:.7rem;color:#64748b;margin-top:1px}
</style>
</head>
<body>
<div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
<?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
<header class="topbar">
    <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
    <span class="tb-title"><i class="bi bi-diagram-3 me-1 text-primary"></i>شجرة الحسابات</span>
    <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
    <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
        <span class="text-primary">المحاسبة</span>
        <i class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
        <span>شجرة الحسابات</span>
    </nav>
</header>
<main class="main-content"><div class="content-body">

<!-- إحصائيات سريعة -->
<div class="row g-3 mb-4">
    <?php
    $totals = ['asset'=>0,'liability'=>0,'equity'=>0,'revenue'=>0,'expense'=>0];
    $counts = ['asset'=>0,'liability'=>0,'equity'=>0,'revenue'=>0,'expense'=>0];
    foreach ($accounts as $a) {
        $totals[$a['account_type']] += $a['base_balance'];
        $counts[$a['account_type']]++;
    }
    $cards = [
        ['أصول','asset','#1e3a8a','bi-bank2'],
        ['التزامات','liability','#dc2626','bi-arrow-down-circle'],
        ['إيرادات','revenue','#16a34a','bi-graph-up'],
        ['مصاريف','expense','#d97706','bi-wallet2'],
    ];
    foreach ($cards as [$label,$type,$color,$icon]):
    ?>
    <div class="col-6 col-md-3">
        <div class="acc-stat">
            <div class="d-flex align-items-center gap-2 mb-1">
                <i class="bi <?=$icon?>" style="color:<?=$color?>;font-size:1rem"></i>
                <span style="font-size:.72rem;color:#64748b"><?=$label?> (<?=$counts[$type]?> حساب)</span>
            </div>
            <div class="acc-stat-val n" style="color:<?=$color?>">
                $ <?= number_format(abs($totals[$type]),2) ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- الشجرة -->
<div class="tbl-wrap">
    <div class="tbl-hdr">
        <span style="font-size:.88rem;font-weight:700;color:#1e293b">
            <i class="bi bi-diagram-3 me-1 text-primary"></i>
            دليل الحسابات
            <span style="font-size:.75rem;color:#94a3b8;font-weight:400">(<?= count($accounts) ?> حساب)</span>
        </span>
        <div class="d-flex gap-2 ms-auto">
            <button class="btn btn-sm btn-light" style="border-radius:8px;font-size:.78rem" onclick="expandAll()">
                <i class="bi bi-arrows-expand me-1"></i>فتح الكل
            </button>
            <button class="btn btn-sm btn-light" style="border-radius:8px;font-size:.78rem" onclick="collapseAll()">
                <i class="bi bi-arrows-collapse me-1"></i>طي الكل
            </button>
            <button class="btn btn-sm fw-600" style="border-radius:8px;background:#1e3a8a;color:#fff;font-size:.78rem"
                    onclick="openAdd()">
                <i class="bi bi-plus-lg me-1"></i>حساب جديد
            </button>
        </div>
    </div>
    <div class="table-responsive">
    <table class="mtbl" id="accTable">
        <thead><tr>
            <th>اسم الحساب</th>
            <th>الرمز</th>
            <th>النوع</th>
            <th>العملة</th>
            <th>الرصيد ($)</th>
            <th>الحالة</th>
            <th style="width:130px">إجراءات</th>
        </tr></thead>
        <tbody>
        <?php renderTree($tree, $TYPE_MAP, $CURRENCY_MAP); ?>
        </tbody>
    </table>
    </div>
</div>
</div></main>

<!-- مودال إضافة/تعديل حساب -->
<div class="modal fade" id="accModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#0c447c,#1e3a8a);border-radius:16px 16px 0 0">
        <h6 class="modal-title text-white fw-700 mb-0" id="mTitle">حساب جديد</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 pt-3">
        <input type="hidden" id="mId">
        <div class="row g-3">
          <div class="col-md-5">
            <label class="field-lbl">رمز الحساب <span class="req">*</span></label>
            <input type="text" id="mCode" class="form-control form-control-sm" dir="ltr"
                   placeholder="مثال: 1210" maxlength="20">
          </div>
          <div class="col-md-7">
            <label class="field-lbl">اسم الحساب <span class="req">*</span></label>
            <input type="text" id="mName" class="form-control form-control-sm" placeholder="اسم الحساب">
          </div>
          <div class="col-md-6">
            <label class="field-lbl">الحساب الأب</label>
            <select id="mParent" class="form-select form-select-sm">
                <option value="">— حساب رئيسي —</option>
                <?php foreach ($accounts as $a): ?>
                <option value="<?=$a['id']?>" data-type="<?=$a['account_type']?>">
                    <?= str_repeat('  ',$a['level']-1) ?><?= htmlspecialchars($a['code'].' — '.$a['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="field-lbl">نوع الحساب <span class="req">*</span></label>
            <select id="mType" class="form-select form-select-sm">
                <?php foreach ($TYPE_MAP as $k=>$v): ?>
                <option value="<?=$k?>"><?=$v['label']?></option>
                <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="field-lbl">العملة</label>
            <div class="d-flex gap-1 align-items-center">
                <select id="mCurrencyId" class="form-select form-select-sm">
                    <?php foreach($curs as $cur): ?>
                    <option value="<?=$cur['id']?>"><?=htmlspecialchars($cur['code'].' — '.$cur['name'].' '.$cur['symbol'])?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-sm btn-light flex-shrink-0" style="border-radius:7px;padding:4px 7px"
                        onclick="refreshCurrencies()" title="تحديث قائمة العملات من DB">
                    <i class="bi bi-arrow-repeat" id="refreshCurIcon"></i>
                </button>
                <a href="/bayhas/aleppo/modules/accounting/currencies.php" target="_blank"
                   class="btn btn-sm btn-light flex-shrink-0" style="border-radius:7px;padding:4px 7px"
                   title="إدارة العملات">
                    <i class="bi bi-box-arrow-up-right"></i>
                </a>
            </div>
          </div>
          <div class="col-12">
            <label class="field-lbl">وصف / ملاحظات</label>
            <textarea id="mDesc" class="form-control form-control-sm" rows="2" placeholder="اختياري"></textarea>
          </div>
          <div id="parentInfo" style="display:none" class="col-12">
            <div style="background:#eff6ff;border-radius:8px;padding:8px 12px;font-size:.75rem;color:#1e3a8a">
                <i class="bi bi-info-circle me-1"></i>
                <span id="parentInfoText"></span>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 px-4 pb-4">
        <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
        <button class="btn btn-sm fw-600" style="border-radius:8px;background:#1e3a8a;color:#fff;min-width:100px"
                onclick="saveAccount()" id="btnSave">
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

const accModal = new bootstrap.Modal(document.getElementById('accModal'));

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

// ── طي/فتح الشجرة ──
function getDescendants(id){
    const rows=[];
    document.querySelectorAll(`[data-parent="${id}"]`).forEach(row=>{
        rows.push(row);
        rows.push(...getDescendants(row.dataset.id));
    });
    return rows;
}
function toggleBranch(id){
    const ch=document.getElementById('chevron_'+id);
    const isOpen=ch.classList.contains('bi-chevron-down');
    getDescendants(id).forEach(r=>r.classList.toggle('hidden',isOpen));
    ch.classList.toggle('bi-chevron-down',!isOpen);
    ch.classList.toggle('bi-chevron-left',isOpen);
}
function expandAll(){
    document.querySelectorAll('.acc-row').forEach(r=>r.classList.remove('hidden'));
    document.querySelectorAll('.bi-chevron-left').forEach(i=>{i.classList.remove('bi-chevron-left');i.classList.add('bi-chevron-down');});
}
function collapseAll(){
    document.querySelectorAll('.acc-row:not(.lvl-1)').forEach(r=>r.classList.add('hidden'));
    document.querySelectorAll('.bi-chevron-down').forEach(i=>{i.classList.remove('bi-chevron-down');i.classList.add('bi-chevron-left');});
}

// ── إضافة/تعديل ──
function resetForm(){
    ['mId','mCode','mName','mDesc'].forEach(id=>document.getElementById(id).value='');
    document.getElementById('mParent').value='';
    document.getElementById('mType').value='asset';
    document.getElementById('mCurrencyId').value='1';
    document.getElementById('parentInfo').style.display='none';
}
function openAdd(){
    resetForm();
    document.getElementById('mTitle').textContent='إضافة حساب جديد';
    accModal.show();
    setTimeout(()=>document.getElementById('mCode').focus(),300);
}
function openAddChild(parentId, parentName, parentType){
    resetForm();
    document.getElementById('mParent').value=parentId;
    document.getElementById('mType').value=parentType;
    document.getElementById('mTitle').textContent='إضافة حساب فرعي';
    document.getElementById('parentInfo').style.display='block';
    document.getElementById('parentInfoText').textContent='حساب فرعي من: '+parentName;
    accModal.show();
    setTimeout(()=>document.getElementById('mCode').focus(),300);
}
function openEdit(id){
    const row=document.querySelector(`[data-id="${id}"]`);
    if(!row)return;
    const cells=row.querySelectorAll('td');
    // نجلب البيانات من الصفحة
    post({_action:'_get',id}).catch(()=>{});
    // نقرأ من الـ DOM مؤقتاً
    document.getElementById('mId').value=id;
    document.getElementById('mTitle').textContent='تعديل الحساب';
    document.getElementById('mParent').value=row.dataset.parent||'';
    accModal.show();
    toast('عدّل البيانات المطلوبة وانقر حفظ','info');
}

// عند تغيير الأب — يضبط النوع تلقائياً
document.getElementById('mParent').addEventListener('change',function(){
    const opt=this.options[this.selectedIndex];
    if(opt.value&&opt.dataset.type){
        document.getElementById('mType').value=opt.dataset.type;
        document.getElementById('parentInfo').style.display='block';
        document.getElementById('parentInfoText').textContent='الحساب الأب: '+opt.text.trim();
    } else {
        document.getElementById('parentInfo').style.display='none';
    }
});

function saveAccount(){
    const code=document.getElementById('mCode').value.trim();
    const name=document.getElementById('mName').value.trim();
    if(!code){toast('رمز الحساب مطلوب','danger');return;}
    if(!name){toast('اسم الحساب مطلوب','danger');return;}
    document.getElementById('saveTxt').style.opacity='0';
    document.getElementById('saveSpin').style.display='inline-block';
    post({
        _action:'save_account',
        id:          document.getElementById('mId').value,
        code,name,
        parent_id:   document.getElementById('mParent').value,
        account_type:document.getElementById('mType').value,
        currency_id: document.getElementById('mCurrencyId').value,
        description: document.getElementById('mDesc').value,
    }).then(d=>{
        document.getElementById('saveTxt').style.opacity='1';
        document.getElementById('saveSpin').style.display='none';
        if(d.ok){toast('تم الحفظ بنجاح');accModal.hide();setTimeout(()=>location.reload(),700);}
        else toast(d.msg,'danger');
    });
}

// ── تحديث قائمة العملات ──
function refreshCurrencies(){
    const icon=document.getElementById('refreshCurIcon');
    icon.classList.add('spin');
    fetch('/bayhas/aleppo/api/get_currencies.php')
    .then(r=>r.json()).then(d=>{
        if(!d.ok){icon.classList.remove('spin');return;}
        const sel=document.getElementById('mCurrencyId');
        const cur=sel.value;
        sel.innerHTML=d.currencies.map(c=>
            `<option value="${c.id}">${c.code} — ${c.name} ${c.symbol}</option>`
        ).join('');
        sel.value=cur||'1';
        icon.classList.remove('spin');
        toast('تم تحديث قائمة العملات');
    }).catch(()=>{icon.classList.remove('spin');});
}
function toggleActive(id){
    post({_action:'toggle_active',id}).then(d=>{
        if(d.ok){toast(d.active?'تم التفعيل':'تم التعطيل');setTimeout(()=>location.reload(),600);}
        else toast(d.msg,'danger');
    });
}
function deleteAccount(id,name){
    if(!confirm(`حذف الحساب "${name}"؟`))return;
    post({_action:'delete_account',id}).then(d=>{
        if(d.ok){toast('تم الحذف');setTimeout(()=>location.reload(),600);}
        else toast(d.msg,'danger');
    });
}
</script>
</body>
</html>
