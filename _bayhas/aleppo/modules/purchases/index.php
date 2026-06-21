<?php
/**
 * purchases/index.php — فواتير شراء المنتجات النهائية
 * المسار: /bayhas/aleppo/modules/purchases/index.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('purchases.invoices', 'view');
$currentModule = 'purchases.invoices';

$TS    = $_SESSION['table_suffix'];
$TP    = "purchases_{$TS}";
$TPI   = "purchase_items_{$TS}";
$TSP   = "product_suppliers_{$TS}";
$TW    = "warehouses_{$TS}";
$TWI   = "warehouse_items_{$TS}";
$TV    = "product_variants_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        if ($act === 'get_purchase') {
            $id = (int)$_POST['id'];
            $st = $pdo->prepare("SELECT p.*, s.name AS supplier_name,
                c.symbol AS currency_symbol
                FROM `{$TP}` p
                LEFT JOIN `{$TSP}` s ON s.id=p.supplier_id
                LEFT JOIN currencies c ON c.code=p.currency
                WHERE p.id=?");
            $st->execute([$id]);
            $pur = $st->fetch();
            if (!$pur) throw new Exception('الفاتورة غير موجودة');

            $it = $pdo->prepare("SELECT pi.*, w.name AS wh_name
                FROM `{$TPI}` pi
                LEFT JOIN `{$TW}` w ON w.id=pi.warehouse_id
                WHERE pi.purchase_id=? ORDER BY pi.id");
            $it->execute([$id]);
            $pur['items'] = $it->fetchAll();
            echo json_encode(['ok'=>true,'data'=>$pur]);
        }

        elseif ($act === 'confirm_purchase') {
            requirePermission('purchases.invoices','edit');
            $id  = (int)$_POST['id'];
            $pSt = $pdo->prepare("SELECT * FROM `{$TP}` WHERE id=?");
            $pSt->execute([$id]);
            $pur = $pSt->fetch();
            if (!$pur) throw new Exception('الفاتورة غير موجودة');
            if ($pur['status'] !== 'draft')
                throw new Exception('يمكن تأكيد المسودات فقط');

            $pdo->beginTransaction();
            try {
                // 1. تحديث المخزون (warehouse_items)
                $items = $pdo->prepare("SELECT * FROM `{$TPI}` WHERE purchase_id=?");
                $items->execute([$id]);
                foreach ($items->fetchAll() as $row) {
                    if (!$row['variant_id'] || !$row['warehouse_id']) continue;
                    $vr = $pdo->prepare("SELECT product_id FROM `{$TV}` WHERE id=?");
                    $vr->execute([$row['variant_id']]);
                    $prodId = (int)$vr->fetchColumn();
                    $pdo->prepare("INSERT INTO `{$TWI}` (warehouse_id,variant_id,product_id,quantity,min_quantity)
                        VALUES (?,?,?,?,0)
                        ON DUPLICATE KEY UPDATE quantity=quantity+?")
                        ->execute([$row['warehouse_id'],$row['variant_id'],$prodId,
                            $row['quantity'],$row['quantity']]);
                }

                // 2. تحديث حالة الفاتورة + المبالغ
                // paid_amount=0, balance_amount=final_amount (المستحق للمورد)
                $pdo->prepare("UPDATE `{$TP}` SET
                    status='received',
                    payment_status='pending',
                    paid_amount=0,
                    balance_amount=final_amount,
                    updated_by=?, updated_at=NOW()
                    WHERE id=?")
                    ->execute([$_SESSION['user_id'],$id]);

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok'=>true,'msg'=>'تم تأكيد الفاتورة وتحديث المخزون — المبلغ المستحق: '.number_format($pur['final_amount'],2).' '.$pur['currency']]);
        }

        elseif ($act === 'cancel_purchase') {
            requirePermission('purchases.invoices','edit');
            $id  = (int)$_POST['id'];
            $pSt = $pdo->prepare("SELECT * FROM `{$TP}` WHERE id=?");
            $pSt->execute([$id]);
            $pur = $pSt->fetch();
            if (!$pur) throw new Exception('الفاتورة غير موجودة');
            if ($pur['status']==='cancelled') throw new Exception('الفاتورة ملغاة مسبقاً');
            if ($pur['status']==='received') {
                $items = $pdo->prepare("SELECT * FROM `{$TPI}` WHERE purchase_id=?");
                $items->execute([$id]);
                foreach ($items->fetchAll() as $row) {
                    if (!$row['variant_id']||!$row['warehouse_id']) continue;
                    $pdo->prepare("UPDATE `{$TWI}` SET quantity=GREATEST(0,quantity-?)
                        WHERE variant_id=? AND warehouse_id=?")
                        ->execute([$row['quantity'],$row['variant_id'],$row['warehouse_id']]);
                }
            }
            $pdo->prepare("UPDATE `{$TP}` SET status='cancelled',updated_by=?,updated_at=NOW() WHERE id=?")
                ->execute([$_SESSION['user_id'],$id]);
            echo json_encode(['ok'=>true,'msg'=>'تم إلغاء الفاتورة']);
        }

        else throw new Exception('إجراء غير معروف');
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

// ── بيانات الصفحة ──────────────────────────────────────────────
$search   = trim($_GET['q'] ?? '');
$status   = $_GET['status'] ?? '';
$suppFil  = (int)($_GET['supplier'] ?? 0);
$dateFrom = $_GET['from'] ?? '';
$dateTo   = $_GET['to']   ?? '';
$where = 'WHERE 1=1'; $params = [];
if ($search)   { $where .= ' AND (p.purchase_number LIKE ? OR s.name LIKE ?)'; $params[]="%{$search}%"; $params[]="%{$search}%"; }
if ($status)   { $where .= ' AND p.status=?';      $params[]=$status; }
if ($suppFil)  { $where .= ' AND p.supplier_id=?'; $params[]=$suppFil; }
if ($dateFrom) { $where .= ' AND p.purchase_date>=?'; $params[]=$dateFrom; }
if ($dateTo)   { $where .= ' AND p.purchase_date<=?'; $params[]=$dateTo; }

$stmt = $pdo->prepare("SELECT p.*, s.name AS supplier_name,
    c.symbol AS currency_symbol,
    COUNT(pi.id) AS items_count
    FROM `{$TP}` p
    LEFT JOIN `{$TSP}` s ON s.id=p.supplier_id
    LEFT JOIN currencies c ON c.code=p.currency
    LEFT JOIN `{$TPI}` pi ON pi.purchase_id=p.id
    {$where}
    GROUP BY p.id ORDER BY p.created_at DESC LIMIT 200");
$stmt->execute($params);
$purchases = $stmt->fetchAll();

$suppliers = $pdo->query("SELECT id,name FROM `{$TSP}`
    WHERE status='active' AND supplier_type IN ('product','both')
    ORDER BY name")->fetchAll();

try {
    $stats = $pdo->query("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END) AS drafts,
        SUM(CASE WHEN status='received' THEN 1 ELSE 0 END) AS received,
        COALESCE(SUM(CASE WHEN status!='cancelled' THEN final_amount_usd END),0) AS total_usd,
        COALESCE(SUM(CASE WHEN payment_status='pending' AND status='received' THEN final_amount_usd END),0) AS balance_usd
        FROM `{$TP}`")->fetch();
} catch (Exception $e) {
    $stats=['total'=>0,'drafts'=>0,'received'=>0,'total_usd'=>0,'balance_usd'=>0];
}

$STATUS_MAP=[
    'draft'     =>['label'=>'مسودة',  'cls'=>'bg-secondary-subtle text-secondary'],
    'confirmed' =>['label'=>'مؤكدة',  'cls'=>'bg-info-subtle text-info'],
    'received'  =>['label'=>'مستلمة', 'cls'=>'bg-success-subtle text-success'],
    'cancelled' =>['label'=>'ملغاة',  'cls'=>'bg-danger-subtle text-danger'],
];
$PAY_MAP=[
    'pending'=>['label'=>'غير مدفوعة','cls'=>'text-danger'],
    'partial'=>['label'=>'جزئي',      'cls'=>'text-warning'],
    'paid'   =>['label'=>'مدفوعة',    'cls'=>'text-success'],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>فواتير الشراء — <?= htmlspecialchars($branchName) ?></title>
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
.det-row{display:flex;justify-content:space-between;font-size:.8rem;padding:3px 0;border-bottom:1px solid #f8fafc}
.det-row:last-child{border-bottom:none}
.n{font-variant-numeric:tabular-nums}
</style>
</head>
<body>
<div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
<?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
<header class="topbar">
    <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
    <span class="tb-title"><i class="bi bi-cart-plus me-1 text-primary"></i>فواتير الشراء</span>
    <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
    <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
        <span class="text-primary">فواتير الشراء</span>
    </nav>
</header>
<main class="main-content"><div class="content-body">

<!-- تبويبات -->
<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e2e8f0">
    <li class="nav-item">
        <a class="nav-link fw-600 active" href="index.php"
           style="border:none;border-bottom:2px solid #1e3a8a;color:#1e3a8a;font-size:.83rem;margin-bottom:-2px">
            <i class="bi bi-receipt me-1"></i>الفواتير
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link fw-600" href="returns.php" style="border:none;color:#64748b;font-size:.83rem">
            <i class="bi bi-arrow-return-right me-1"></i>المرتجعات
        </a>
    </li>
    <li class="nav-item ms-auto d-flex align-items-center">
        <a href="invoice_new.php" class="btn btn-sm fw-600"
           style="border-radius:9px;background:#1e3a8a;color:#fff;font-size:.82rem;text-decoration:none">
            <i class="bi bi-plus-lg me-1"></i>فاتورة جديدة
        </a>
    </li>
</ul>

<!-- إحصائيات -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#eff6ff"><i class="bi bi-receipt text-primary"></i></div>
            <div><div class="stat-val"><?= $stats['total'] ?></div><div class="stat-lbl">إجمالي الفواتير</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fef3c7"><i class="bi bi-hourglass text-warning"></i></div>
            <div><div class="stat-val"><?= $stats['drafts'] ?></div><div class="stat-lbl">مسودات</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#f0fdf4"><i class="bi bi-currency-dollar text-success"></i></div>
            <div><div class="stat-val n"><?= number_format($stats['total_usd'],2) ?> $</div><div class="stat-lbl">إجمالي المشتريات</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fee2e2"><i class="bi bi-exclamation-circle text-danger"></i></div>
            <div><div class="stat-val n"><?= number_format($stats['balance_usd'],2) ?> $</div><div class="stat-lbl">المستحق للموردين</div></div>
        </div>
    </div>
</div>

<!-- فلاتر -->
<div class="tbl-wrap mb-3">
    <div class="tbl-hdr">
        <form method="get" class="d-flex gap-2 flex-wrap align-items-center w-100">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                   placeholder="رقم الفاتورة أو المورد..."
                   class="form-control form-control-sm" style="width:180px;border-radius:8px">
            <select name="status" class="form-select form-select-sm" style="width:120px;border-radius:8px">
                <option value="">كل الحالات</option>
                <?php foreach ($STATUS_MAP as $k=>$v): ?>
                <option value="<?=$k?>" <?= $status===$k?'selected':'' ?>><?= $v['label'] ?></option>
                <?php endforeach; ?>
            </select>
            <select name="supplier" class="form-select form-select-sm" style="width:160px;border-radius:8px">
                <option value="">كل الموردين</option>
                <?php foreach ($suppliers as $sp): ?>
                <option value="<?=$sp['id']?>" <?= $suppFil==$sp['id']?'selected':'' ?>><?= htmlspecialchars($sp['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>"
                   class="form-control form-control-sm" style="width:140px;border-radius:8px">
            <span style="font-size:.8rem;color:#94a3b8">—</span>
            <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>"
                   class="form-control form-control-sm" style="width:140px;border-radius:8px">
            <button type="submit" class="btn btn-sm btn-primary" style="border-radius:8px">
                <i class="bi bi-search me-1"></i>بحث
            </button>
            <?php if ($search||$status||$suppFil||$dateFrom||$dateTo): ?>
            <a href="index.php" class="btn btn-sm btn-light" style="border-radius:8px">
                <i class="bi bi-x-lg me-1"></i>مسح
            </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- الجدول -->
<div class="tbl-wrap">
    <div class="table-responsive">
    <table class="mtbl">
        <thead><tr>
            <th>رقم الفاتورة</th><th>التاريخ</th><th>المورد</th>
            <th>البنود</th><th>العملة</th><th>الإجمالي</th>
            <th>بالدولار</th><th>الدفع</th><th>الحالة</th>
            <th style="text-align:center">إجراءات</th>
        </tr></thead>
        <tbody>
        <?php if (empty($purchases)): ?>
        <tr><td colspan="10" class="text-center text-muted py-5">
            <i class="bi bi-receipt d-block mb-2" style="font-size:2rem;opacity:.2"></i>
            لا توجد فواتير<?= $search?" تطابق \"{$search}\"":'' ?>
        </td></tr>
        <?php endif; ?>
        <?php foreach ($purchases as $pur):
            $st  = $STATUS_MAP[$pur['status']] ?? $STATUS_MAP['draft'];
            $pay = $PAY_MAP[$pur['payment_status']] ?? $PAY_MAP['pending'];
            $sym = $pur['currency_symbol'] ?? '$';
        ?>
        <tr>
            <td class="n fw-600" style="direction:ltr">
                <a href="invoice_view.php?id=<?=$pur['id']?>" style="color:#1e3a8a;text-decoration:none">
                    <?= htmlspecialchars($pur['purchase_number']) ?>
                </a>
            </td>
            <td class="text-muted"><?= $pur['purchase_date'] ?></td>
            <td><div class="fw-600" style="font-size:.83rem"><?= htmlspecialchars($pur['supplier_name']??'—') ?></div></td>
            <td class="text-center">
                <span class="badge bg-secondary-subtle text-secondary"><?= $pur['items_count'] ?> بند</span>
            </td>
            <td><span class="badge bg-secondary-subtle text-secondary" style="font-size:.68rem"><?= $pur['currency'] ?></span></td>
            <td class="n fw-600"><?= number_format($pur['final_amount'],2) ?> <?=$sym?></td>
            <td class="n text-muted" style="font-size:.78rem">
                <?= $pur['final_amount_usd'] ? number_format($pur['final_amount_usd'],2).' $' : '—' ?>
            </td>
            <td><span class="<?= $pay['cls'] ?>" style="font-size:.78rem;font-weight:600"><?= $pay['label'] ?></span></td>
            <td><span class="badge <?=$st['cls']?>" style="font-size:.68rem"><?=$st['label']?></span></td>
            <td>
                <div class="d-flex gap-1 justify-content-center">
                    <button class="act-btn info-h" onclick="viewInvoice(<?=$pur['id']?>)" title="عرض">
                        <i class="bi bi-eye"></i>
                    </button>
                    <?php if ($pur['status']==='draft'): ?>
                    <a href="invoice_edit.php?id=<?=$pur['id']?>" class="act-btn" title="تعديل">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <?php endif; ?>
                    <?php if ($pur['status']==='draft'): ?>
                    <button class="act-btn success-h"
                        onclick="confirmInvoice(<?=$pur['id']?>,'<?=htmlspecialchars($pur['purchase_number'],ENT_QUOTES)?>')"
                        title="تأكيد الفاتورة"><i class="bi bi-check-circle"></i>
                    </button>
                    <?php endif; ?>
                    <?php if ($pur['status']!=='cancelled'): ?>
                    <button class="act-btn danger"
                        onclick="cancelInvoice(<?=$pur['id']?>,'<?=htmlspecialchars($pur['purchase_number'],ENT_QUOTES)?>')"
                        title="إلغاء"><i class="bi bi-x-circle"></i>
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

<!-- مودال عرض -->
<div class="modal fade" id="viewModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#0c447c,#1e3a8a);border-radius:16px 16px 0 0">
        <div>
          <h6 class="modal-title text-white fw-700 mb-0" id="vTitle">تفاصيل الفاتورة</h6>
          <div id="vSub" style="font-size:.75rem;color:rgba(255,255,255,.7);margin-top:2px"></div>
        </div>
        <div class="d-flex gap-2 align-items-center">
          <a id="vEditBtn" href="#" class="btn btn-sm"
             style="display:none;border-radius:8px;background:rgba(255,255,255,.15);color:#fff;font-size:.76rem;border:1px solid rgba(255,255,255,.3)">
            <i class="bi bi-pencil me-1"></i>تعديل
          </a>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
      </div>
      <div class="modal-body px-4 py-3" id="vBody">
        <div class="text-center py-4"><span class="spinner-border text-primary"></span></div>
      </div>
    </div>
  </div>
</div>

<!-- مودال تأكيد الاستلام -->
<div class="modal fade" id="confirmModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#065f46,#16a34a);border-radius:16px 16px 0 0">
        <h6 class="modal-title text-white fw-700 mb-0">
            <i class="bi bi-check-circle me-2"></i>تأكيد استلام الفاتورة
        </h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 py-3">
        <input type="hidden" id="cId">
        <div class="p-3" style="background:#f0fdf4;border-radius:10px;border:1px solid #bbf7d0">
          <div class="fw-600" style="color:#065f46" id="cInvNo"></div>
          <div style="font-size:.8rem;color:#64748b;margin-top:6px">
            <i class="bi bi-info-circle me-1"></i>سيتم تحديث المخزون فور التأكيد
          </div>
          <div style="font-size:.8rem;color:#64748b;margin-top:3px">
            <i class="bi bi-clock me-1"></i>حالة الدفع ستبقى <b>معلقة</b> حتى تسجيل الدفعة من قسم المحاسبة
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 px-4 pb-4">
        <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
        <button class="btn btn-sm fw-600" style="border-radius:8px;background:#16a34a;color:#fff;min-width:130px"
                onclick="doConfirm()" id="btnConfirm">
          <span id="confirmTxt"><i class="bi bi-check-circle me-1"></i>تأكيد الاستلام</span>
          <span id="confirmSpin" class="spinner-border spinner-border-sm" style="display:none"></span>
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
function toggleGroup(g){
    const o=g.classList.contains('open');
    document.querySelectorAll('.sb-group.open').forEach(x=>x.classList.remove('open'));
    g.classList.toggle('open',!o);
    localStorage.setItem('sb_open_'+g.dataset.key,(!o).toString());
}
document.querySelectorAll('.sb-group').forEach(g=>{
    if(localStorage.getItem('sb_open_'+g.dataset.key)==='true')g.classList.add('open');
});
const viewModal=new bootstrap.Modal(document.getElementById('viewModal'));
const confirmModal=new bootstrap.Modal(document.getElementById('confirmModal'));
const STATUS_MAP=<?= json_encode($STATUS_MAP) ?>;
const PAY_MAP=<?= json_encode($PAY_MAP) ?>;
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
    document.body.appendChild(t);setTimeout(()=>t.remove(),3200);
}
function viewInvoice(id){
    document.getElementById('vTitle').textContent='جارٍ التحميل...';
    document.getElementById('vSub').textContent='';
    document.getElementById('vEditBtn').style.display='none';
    document.getElementById('vBody').innerHTML='<div class="text-center py-4"><span class="spinner-border text-primary"></span></div>';
    viewModal.show();
    post({_action:'get_purchase',id}).then(d=>{
        if(!d.ok){document.getElementById('vBody').innerHTML=`<div class="text-danger p-3">${d.msg}</div>`;return;}
        const p=d.data;
        const st=STATUS_MAP[p.status]||STATUS_MAP['draft'];
        const pay=PAY_MAP[p.payment_status]||PAY_MAP['pending'];
        const sym=p.currency_symbol||'$';
        const cur=p.currency||'USD';
        const rate=parseFloat(p.exchange_rate)||1;
        const isUSD=cur==='USD';
        document.getElementById('vTitle').textContent='فاتورة: '+p.purchase_number;
        document.getElementById('vSub').textContent=p.supplier_name||'';
        if(p.status==='draft'){
            const eb=document.getElementById('vEditBtn');
            eb.href=`invoice_edit.php?id=${p.id}`;
            eb.style.display='';
        }
        // تجميع البنود بـ (product × unit_price × color)
        const GRP_COLORS=[['#eff6ff','#1e3a8a','#bfdbfe'],['#f0fdf4','#065f46','#bbf7d0'],
            ['#fff7ed','#7c2d12','#fed7aa'],['#f5f3ff','#4c1d95','#ddd6fe']];
        const grpMap={};
        (p.items||[]).forEach(it=>{
            const k=`${it.product_id||it.product_name}_${it.unit_price}_${it.color||''}`;
            if(!grpMap[k]){
                grpMap[k]={
                    product_name:it.product_name, model_number:it.model_number||'',
                    unit_price:parseFloat(it.unit_price),
                    unit_price_usd:parseFloat(it.unit_price_usd||(parseFloat(it.unit_price)/rate)),
                    color:it.color||'—', wh_name:it.wh_name||'—',
                    qty:parseFloat(it.quantity), // كمية أول سطر = كمية اللون
                    total:0, sizes:[], sizeCount:0
                };
            }
            // نجمع الإجمالي من total_price مباشرة
            grpMap[k].total += parseFloat(it.total_price);
            if(it.size && !grpMap[k].sizes.includes(it.size)) grpMap[k].sizes.push(it.size);
            grpMap[k].sizeCount++;
        });

        // ترتيب الكروبات بالسعر ثم اللون
        const grpRows=Object.values(grpMap).sort((a,b)=>a.unit_price-b.unit_price||(a.color||'').localeCompare(b.color||''));
        // تلوين الكروبات بحسب السعر
        const priceList=[...new Set(grpRows.map(g=>g.unit_price))];
        const itemsHtml=grpRows.map(g=>{
            const pi=priceList.indexOf(g.unit_price);
            const [bg,clr,br]=GRP_COLORS[pi%4];
            const grpBadge=`<span style="background:${bg};color:${clr};border:1px solid ${br};border-radius:12px;font-size:.68rem;padding:2px 8px;font-weight:600">كروب ${pi+1}</span>`;
            return `<tr>
                <td>
                    <div class="fw-600" style="font-size:.8rem">${g.product_name}</div>
                    <div style="font-size:.7rem;color:#94a3b8" dir="ltr">${g.model_number}</div>
                </td>
                <td>${grpBadge}
                    <div style="font-size:.72rem;font-weight:600;color:#334155;margin-top:3px">${g.sizes.join(' · ')}</div>
                </td>
                <td class="text-center" style="font-size:.78rem">${g.color}</td>
                <td class="n text-center fw-600">${g.qty.toFixed(0)}</td>
                <td class="n text-center">${g.unit_price.toFixed(4)} ${sym}</td>
                <td class="n text-center" style="color:#16a34a;font-size:.72rem">${g.unit_price_usd.toFixed(4)} $</td>
                <td class="n text-end fw-600">${g.total.toFixed(2)} ${sym}</td>
                <td style="font-size:.75rem;color:#64748b">${g.wh_name}</td>
            </tr>`;
        }).join('');
        document.getElementById('vBody').innerHTML=`
        <div class="row g-2 mb-3">
            <div class="col-md-3"><small style="color:#64748b">المورد</small><div class="fw-600">${p.supplier_name||'—'}</div></div>
            <div class="col-md-2"><small style="color:#64748b">التاريخ</small><div>${p.purchase_date}</div></div>
            <div class="col-md-2"><small style="color:#64748b">الاستحقاق</small><div>${p.due_date||'—'}</div></div>
            <div class="col-md-2"><small style="color:#64748b">العملة</small>
                <div><span class="badge bg-secondary-subtle text-secondary">${cur}</span>
                ${!isUSD?`<small style="color:#64748b"> 1$=${rate}${sym}</small>`:''}</div>
            </div>
            <div class="col-md-1"><small style="color:#64748b">الحالة</small>
                <div><span class="badge ${st.cls}">${st.label}</span></div>
            </div>
            <div class="col-md-2"><small style="color:#64748b">الدفع</small>
                <div class="${pay.cls} fw-600" style="font-size:.83rem">${pay.label}</div>
            </div>
        </div>
        <div class="table-responsive mb-3">
        <table class="mtbl" style="font-size:.78rem">
            <thead><tr style="background:#f8fafc">
                <th>المنتج</th><th class="text-center">القياس</th><th class="text-center">اللون</th>
                <th class="text-center">الكمية</th>
                <th class="text-center">سعر الوحدة (${sym})</th>
                <th class="text-center" style="color:#16a34a">سعر/$</th>
                <th class="text-end">الإجمالي (${sym})</th>
                <th>المستودع</th>
            </tr></thead>
            <tbody>${itemsHtml||'<tr><td colspan="8" class="text-center text-muted py-3">لا توجد بنود</td></tr>'}</tbody>
        </table>
        </div>
        <div class="row justify-content-end">
          <div class="col-md-4">
            <div style="background:#f8fafc;border-radius:10px;padding:10px 14px">
                ${parseFloat(p.discount_amount)>0?`<div class="det-row"><span style="color:#64748b">الخصم</span><span class="n text-danger">-${parseFloat(p.discount_amount).toFixed(2)} ${sym}</span></div>`:''}
                ${parseFloat(p.tax_amount)>0?`<div class="det-row"><span style="color:#64748b">الضريبة</span><span class="n">+${parseFloat(p.tax_amount).toFixed(2)} ${sym}</span></div>`:''}
                <div class="det-row" style="font-weight:700;font-size:.9rem;border-top:1px solid #e2e8f0;padding-top:6px">
                    <span>الصافي</span>
                    <span class="n">${grpRows.reduce((s,g)=>s+g.total,0).toFixed(2)} ${sym}
                    ${!isUSD&&p.final_amount_usd?`<small style="color:#94a3b8;font-weight:400"> (${parseFloat(p.final_amount_usd).toFixed(2)} $)</small>`:''}</span>
                </div>
            </div>
          </div>
        </div>
        ${p.notes?`<div style="background:#f8fafc;border-radius:8px;padding:8px 12px;margin-top:10px;font-size:.78rem;color:#64748b">${p.notes}</div>`:''}
        ${p.status==='draft'?`<div style="margin-top:12px;display:flex;gap:8px">
            <button class="btn btn-sm fw-600" style="border-radius:8px;background:#16a34a;color:#fff;flex:1;font-size:.8rem"
                onclick="confirmInvoice(${p.id},'${p.purchase_number}')">
                <i class="bi bi-check-circle me-1"></i>تأكيد الفاتورة
            </button>
            <a href="invoice_edit.php?id=${p.id}" class="btn btn-sm fw-600"
               style="border-radius:8px;border:1px solid #1e3a8a;color:#1e3a8a;flex:1;font-size:.8rem;text-decoration:none;text-align:center">
                <i class="bi bi-pencil me-1"></i>تعديل
            </a>
        </div>`:''}`;
    });
}
let _cId=0;
function openConfirmModal(id,no,total,sym){
    _cId=id;
    document.getElementById('cInvNo').textContent='فاتورة: '+no;
    document.getElementById('cTotal').value=parseFloat(total).toFixed(2)+' '+sym;
    document.getElementById('cPaid').value='0';
    confirmModal.show();
}
function doConfirm(){
    document.getElementById('confirmTxt').style.opacity='0';
    document.getElementById('confirmSpin').style.display='inline-block';
    post({_action:'confirm_purchase',id:_cId})
    .then(d=>{
        document.getElementById('confirmTxt').style.opacity='1';
        document.getElementById('confirmSpin').style.display='none';
        if(d.ok){toast('✅ '+d.msg);confirmModal.hide();setTimeout(()=>location.reload(),800);}
        else toast(d.msg,'danger');
    });
}

function confirmInvoice(id,no){
    _cId=id;
    document.getElementById('cInvNo').textContent='فاتورة: '+no;
    document.getElementById('cTotal').value='';
    confirmModal.show();
}
function cancelInvoice(id,no){
    if(!confirm(`إلغاء الفاتورة "${no}"؟\nالفواتير المستلمة سيتم عكس مخزونها.`))return;
    post({_action:'cancel_purchase',id}).then(d=>{
        if(d.ok){toast(d.msg);setTimeout(()=>location.reload(),700);}
        else toast(d.msg,'danger');
    });
}
</script>
</body>
</html>
