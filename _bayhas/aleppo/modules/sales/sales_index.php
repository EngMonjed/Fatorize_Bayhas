<?php
/**
 * sales/invoices.php — فواتير البيع
 * المسار: /bayhas/aleppo/modules/sales/invoices.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('sales.invoices', 'view');
$currentModule = 'sales.invoices';

$TS  = $_SESSION['table_suffix'];
$TI  = "sales_invoices_{$TS}";
$TII = "sales_invoice_items_{$TS}";
$TC  = "customers_{$TS}";
$TW  = "warehouses_{$TS}";
$TWI = "warehouse_items_{$TS}";
$TV  = "product_variants_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        // ── جلب تفاصيل فاتورة ──
        if ($act === 'get_invoice') {
            $id = (int)$_POST['id'];
            $st = $pdo->prepare("SELECT i.*, c.phone AS customer_phone
                FROM `{$TI}` i
                LEFT JOIN `{$TC}` c ON c.id=i.customer_id
                WHERE i.id=?");
            $st->execute([$id]);
            $inv = $st->fetch();
            if (!$inv) throw new Exception('الفاتورة غير موجودة');
            $items = $pdo->prepare("SELECT * FROM `{$TII}` WHERE invoice_id=? ORDER BY id");
            $items->execute([$id]);
            $inv['items'] = $items->fetchAll();
            echo json_encode(['ok'=>true,'data'=>$inv]);
        }

        // ── تأكيد فاتورة ──
        elseif ($act === 'confirm_invoice') {
            requirePermission('sales.invoices','edit');
            $id  = (int)$_POST['id'];
            $pSt = $pdo->prepare("SELECT * FROM `{$TI}` WHERE id=?");
            $pSt->execute([$id]);
            $inv = $pSt->fetch();
            if (!$inv)                       throw new Exception('الفاتورة غير موجودة');
            if ($inv['status'] !== 'draft')  throw new Exception('يمكن تأكيد المسودات فقط');

            $pdo->beginTransaction();
            try {
                // خصم المخزون
                $items = $pdo->prepare("SELECT * FROM `{$TII}` WHERE invoice_id=?");
                $items->execute([$id]);
                foreach ($items->fetchAll() as $row) {
                    if (!$row['variant_id'] || !$row['warehouse_id']) continue;
                    $cur = $pdo->prepare("SELECT quantity FROM `{$TWI}` WHERE variant_id=? AND warehouse_id=?");
                    $cur->execute([$row['variant_id'], $row['warehouse_id']]);
                    $stock = (float)($cur->fetchColumn() ?? 0);
                    if ($stock < $row['quantity'])
                        throw new Exception("مخزون غير كافٍ: {$row['item_name']} (متوفر: {$stock})");
                    $pdo->prepare("UPDATE `{$TWI}` SET quantity=quantity-? WHERE variant_id=? AND warehouse_id=?")
                        ->execute([$row['quantity'], $row['variant_id'], $row['warehouse_id']]);
                }
                // تحديث الفاتورة
                $pdo->prepare("UPDATE `{$TI}` SET status='confirmed', payment_status='pending',
                    paid_amount=0, balance_amount=total_amount,
                    confirmed_at=NOW(), confirmed_by=?, updated_by=?, updated_at=NOW()
                    WHERE id=?")
                    ->execute([$_SESSION['user_id'], $_SESSION['user_id'], $id]);
                $pdo->commit();
                echo json_encode(['ok'=>true,'msg'=>'تم تأكيد الفاتورة وخصم المخزون']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        // ── إلغاء فاتورة ──
        elseif ($act === 'cancel_invoice') {
            requirePermission('sales.invoices','edit');
            $id  = (int)$_POST['id'];
            $pSt = $pdo->prepare("SELECT * FROM `{$TI}` WHERE id=?");
            $pSt->execute([$id]);
            $inv = $pSt->fetch();
            if (!$inv)                           throw new Exception('الفاتورة غير موجودة');
            if ($inv['status'] === 'cancelled')  throw new Exception('الفاتورة ملغاة مسبقاً');

            // إعادة المخزون إذا كانت مؤكدة
            if ($inv['status'] === 'confirmed') {
                $items = $pdo->prepare("SELECT * FROM `{$TII}` WHERE invoice_id=?");
                $items->execute([$id]);
                foreach ($items->fetchAll() as $row) {
                    if (!$row['variant_id'] || !$row['warehouse_id']) continue;
                    $pdo->prepare("UPDATE `{$TWI}` SET quantity=quantity+? WHERE variant_id=? AND warehouse_id=?")
                        ->execute([$row['quantity'], $row['variant_id'], $row['warehouse_id']]);
                }
            }
            $pdo->prepare("UPDATE `{$TI}` SET status='cancelled', updated_by=?, updated_at=NOW() WHERE id=?")
                ->execute([$_SESSION['user_id'], $id]);
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
$statusF  = $_GET['status'] ?? '';
$custF    = (int)($_GET['customer'] ?? 0);
$dateFrom = $_GET['from'] ?? '';
$dateTo   = $_GET['to']   ?? '';

$where = 'WHERE 1=1'; $params = [];
if ($search)   { $where.=' AND (i.invoice_number LIKE ? OR i.customer_name LIKE ?)'; $params[]="%{$search}%"; $params[]="%{$search}%"; }
if ($statusF)  { $where.=' AND i.status=?';      $params[]=$statusF; }
if ($custF)    { $where.=' AND i.customer_id=?'; $params[]=$custF; }
if ($dateFrom) { $where.=' AND i.invoice_date>=?'; $params[]=$dateFrom; }
if ($dateTo)   { $where.=' AND i.invoice_date<=?'; $params[]=$dateTo; }

$stmt = $pdo->prepare("SELECT i.*,
    COUNT(ii.id) AS items_count
    FROM `{$TI}` i
    LEFT JOIN `{$TII}` ii ON ii.invoice_id=i.id
    {$where}
    GROUP BY i.id ORDER BY i.created_at DESC LIMIT 200");
$stmt->execute($params);
$invoices = $stmt->fetchAll();

$customers = $pdo->query("SELECT id,name FROM `{$TC}` WHERE status='active' ORDER BY name")->fetchAll();

try {
    $stats = $pdo->query("SELECT
        COUNT(*) AS total,
        SUM(status='draft') AS drafts,
        SUM(status='confirmed') AS confirmed,
        COALESCE(SUM(CASE WHEN status='confirmed' THEN total_amount END),0) AS total_usd,
        COALESCE(SUM(CASE WHEN status='confirmed' AND payment_status='pending' THEN balance_amount END),0) AS balance_usd
        FROM `{$TI}`")->fetch();
} catch (Exception $e) {
    $stats=['total'=>0,'drafts'=>0,'confirmed'=>0,'total_usd'=>0,'balance_usd'=>0];
}

$STATUS_MAP = [
    'draft'     => ['label'=>'مسودة',   'cls'=>'bg-secondary-subtle text-secondary'],
    'confirmed' => ['label'=>'مؤكدة',   'cls'=>'bg-success-subtle text-success'],
    'cancelled' => ['label'=>'ملغاة',   'cls'=>'bg-danger-subtle text-danger'],
    'paid'      => ['label'=>'مدفوعة',  'cls'=>'bg-primary-subtle text-primary'],
];
$PAY_MAP = [
    'pending' => ['label'=>'غير مدفوعة','cls'=>'text-danger'],
    'partial' => ['label'=>'جزئي',      'cls'=>'text-warning'],
    'paid'    => ['label'=>'مدفوعة',    'cls'=>'text-success'],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>فواتير البيع — <?= htmlspecialchars($branchName) ?></title>
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
table.mtbl tr:hover td{background:#f8fff8}
.act-btn{width:28px;height:28px;border-radius:7px;border:1px solid #e2e8f0;background:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem;color:#64748b;cursor:pointer;transition:all .12s;text-decoration:none}
.act-btn:hover{background:#f1f5f9}
.act-btn.success-h:hover{background:#dcfce7;color:#16a34a;border-color:#86efac}
.act-btn.danger:hover{background:#fee2e2;color:#dc2626;border-color:#fca5a5}
.act-btn.info-h:hover{background:#e0f2fe;color:#0891b2;border-color:#7dd3fc}
.n{font-variant-numeric:tabular-nums}
.det-row{display:flex;justify-content:space-between;font-size:.8rem;padding:4px 0;border-bottom:1px solid #f8fafc}
.det-row:last-child{border-bottom:none}
.clr-dot{width:10px;height:10px;border-radius:50%;border:1px solid rgba(0,0,0,.12);display:inline-block}
.grp-badge{display:inline-flex;align-items:center;border-radius:20px;font-size:.68rem;padding:2px 8px;font-weight:600;border:1px solid}
</style>
</head>
<body>
<div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
<?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
<header class="topbar">
    <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
    <span class="tb-title"><i class="bi bi-receipt me-1 text-success"></i>فواتير البيع</span>
    <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
    <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
        <span class="text-success">فواتير البيع</span>
    </nav>
</header>
<main class="main-content"><div class="content-body">

<!-- تبويبات -->
<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e2e8f0">
    <li class="nav-item">
        <a class="nav-link fw-600 active" href="invoices.php"
           style="border:none;border-bottom:2px solid #16a34a;color:#16a34a;font-size:.83rem;margin-bottom:-2px">
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
           style="border-radius:9px;background:#16a34a;color:#fff;font-size:.82rem;text-decoration:none">
            <i class="bi bi-plus-lg me-1"></i>فاتورة جديدة
        </a>
    </li>
</ul>

<!-- إحصائيات -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#f0fdf4"><i class="bi bi-receipt text-success"></i></div>
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
            <div><div class="stat-val n">$ <?= number_format($stats['total_usd'],2) ?></div><div class="stat-lbl">إجمالي المبيعات</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fee2e2"><i class="bi bi-exclamation-circle text-danger"></i></div>
            <div><div class="stat-val n">$ <?= number_format($stats['balance_usd'],2) ?></div><div class="stat-lbl">المستحق من العملاء</div></div>
        </div>
    </div>
</div>

<!-- فلاتر -->
<div class="tbl-wrap mb-3">
    <div class="tbl-hdr">
        <form method="get" class="d-flex gap-2 flex-wrap align-items-center w-100">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                   placeholder="رقم الفاتورة أو العميل..."
                   class="form-control form-control-sm" style="width:180px;border-radius:8px">
            <select name="status" class="form-select form-select-sm" style="width:120px;border-radius:8px" onchange="this.form.submit()">
                <option value="">كل الحالات</option>
                <?php foreach ($STATUS_MAP as $k=>$v): ?>
                <option value="<?=$k?>" <?= $statusF===$k?'selected':'' ?>><?= $v['label'] ?></option>
                <?php endforeach; ?>
            </select>
            <select name="customer" class="form-select form-select-sm" style="width:160px;border-radius:8px" onchange="this.form.submit()">
                <option value="">كل العملاء</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?=$c['id']?>" <?= $custF==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
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
            <?php if ($search||$statusF||$custF||$dateFrom||$dateTo): ?>
            <a href="invoices.php" class="btn btn-sm btn-light" style="border-radius:8px">
                <i class="bi bi-x-lg me-1"></i>مسح
            </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- جدول الفواتير -->
<div class="tbl-wrap">
    <div class="table-responsive">
    <table class="mtbl">
        <thead><tr>
            <th>رقم الفاتورة</th><th>التاريخ</th><th>العميل</th>
            <th>البنود</th><th>الإجمالي ($)</th>
            <th>المدفوع ($)</th><th>المتبقي ($)</th>
            <th>الدفع</th><th>الحالة</th>
            <th style="text-align:center">إجراءات</th>
        </tr></thead>
        <tbody>
        <?php if (empty($invoices)): ?>
        <tr><td colspan="10" class="text-center text-muted py-5">
            <i class="bi bi-receipt d-block mb-2" style="font-size:2rem;opacity:.2"></i>
            لا توجد فواتير<?= $search?" تطابق \"{$search}\"":'' ?>
        </td></tr>
        <?php endif; ?>
        <?php foreach ($invoices as $inv):
            $st  = $STATUS_MAP[$inv['status']] ?? $STATUS_MAP['draft'];
            $pay = $PAY_MAP[$inv['payment_status']] ?? $PAY_MAP['pending'];
        ?>
        <tr>
            <td class="n fw-600" style="direction:ltr;color:#16a34a">
                <?= htmlspecialchars($inv['invoice_number']) ?>
            </td>
            <td class="text-muted"><?= $inv['invoice_date'] ?></td>
            <td>
                <div class="fw-600" style="font-size:.83rem"><?= htmlspecialchars($inv['customer_name']??'—') ?></div>
            </td>
            <td class="text-center">
                <span class="badge bg-secondary-subtle text-secondary"><?= $inv['items_count'] ?> بند</span>
            </td>
            <td class="n fw-600">$ <?= number_format($inv['total_amount'],2) ?></td>
            <td class="n text-success">$ <?= number_format($inv['paid_amount']??0,2) ?></td>
            <td class="n <?= ($inv['balance_amount']??0)>0?'text-danger fw-600':'' ?>">
                $ <?= number_format($inv['balance_amount']??0,2) ?>
            </td>
            <td>
                <span class="<?= $pay['cls'] ?>" style="font-size:.78rem;font-weight:600"><?= $pay['label'] ?></span>
            </td>
            <td><span class="badge <?=$st['cls']?>" style="font-size:.68rem"><?=$st['label']?></span></td>
            <td>
                <div class="d-flex gap-1 justify-content-center">
                    <button class="act-btn info-h" onclick="viewInvoice(<?=$inv['id']?>)" title="عرض">
                        <i class="bi bi-eye"></i>
                    </button>
                    <?php if ($inv['status']==='draft'): ?>
                    <a href="invoice_edit.php?id=<?=$inv['id']?>" class="act-btn" title="تعديل">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <button class="act-btn success-h"
                            onclick="confirmInvoice(<?=$inv['id']?>,'<?=htmlspecialchars($inv['invoice_number'],ENT_QUOTES)?>')"
                            title="تأكيد الفاتورة">
                        <i class="bi bi-check-circle"></i>
                    </button>
                    <?php endif; ?>
                    <?php if ($inv['status']==='confirmed'): ?>
                    <button class="act-btn" onclick="viewInvoice(<?=$inv['id']?>)" title="تسجيل دفعة"
                            style="color:#8b5cf6;border-color:#c4b5fd">
                        <i class="bi bi-cash-coin"></i>
                    </button>
                    <?php endif; ?>
                    <?php if ($inv['status']!=='cancelled'): ?>
                    <button class="act-btn danger"
                            onclick="cancelInvoice(<?=$inv['id']?>,'<?=htmlspecialchars($inv['invoice_number'],ENT_QUOTES)?>')"
                            title="إلغاء">
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

<!-- مودال عرض الفاتورة -->
<div class="modal fade" id="viewModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#065f46,#16a34a);border-radius:16px 16px 0 0">
        <div>
          <h6 class="modal-title text-white fw-700 mb-0" id="vTitle">تفاصيل الفاتورة</h6>
          <div id="vSub" style="font-size:.75rem;color:rgba(255,255,255,.7);margin-top:2px"></div>
        </div>
        <div class="d-flex gap-2 align-items-center">
          <a id="vEditBtn" href="#"
             style="display:none;border-radius:8px;background:rgba(255,255,255,.15);color:#fff;font-size:.76rem;border:1px solid rgba(255,255,255,.3);padding:4px 10px;text-decoration:none">
            <i class="bi bi-pencil me-1"></i>تعديل
          </a>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
      </div>
      <div class="modal-body px-4 py-3" id="vBody">
        <div class="text-center py-4"><span class="spinner-border text-success"></span></div>
      </div>
    </div>
  </div>
</div>

<!-- مودال تأكيد الفاتورة -->
<div class="modal fade" id="confirmModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#065f46,#16a34a);border-radius:16px 16px 0 0">
        <h6 class="modal-title text-white fw-700 mb-0">
            <i class="bi bi-check-circle me-2"></i>تأكيد فاتورة البيع
        </h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 py-3">
        <input type="hidden" id="cId">
        <div class="p-3" style="background:#f0fdf4;border-radius:10px;border:1px solid #bbf7d0">
          <div class="fw-600" style="color:#065f46" id="cInvNo"></div>
          <div style="font-size:.8rem;color:#64748b;margin-top:6px">
            <i class="bi bi-box-arrow-up me-1"></i>سيتم خصم الكميات من المخزون
          </div>
          <div style="font-size:.8rem;color:#64748b;margin-top:3px">
            <i class="bi bi-clock me-1"></i>حالة الدفع تبقى <b>معلقة</b> حتى تسجيل دفعة من العميل
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 px-4 pb-4">
        <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
        <button class="btn btn-sm fw-600" style="border-radius:8px;background:#16a34a;color:#fff;min-width:120px"
                onclick="doConfirm()" id="btnConfirm">
          <span id="confirmTxt"><i class="bi bi-check-circle me-1"></i>تأكيد</span>
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

const viewModal    = new bootstrap.Modal(document.getElementById('viewModal'));
const confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
const STATUS_MAP   = <?= json_encode($STATUS_MAP) ?>;
const PAY_MAP      = <?= json_encode($PAY_MAP) ?>;
const GRP_COLORS   = [['#eff6ff','#1e3a8a','#bfdbfe'],['#f0fdf4','#065f46','#bbf7d0'],['#fff7ed','#7c2d12','#fed7aa'],['#f5f3ff','#4c1d95','#ddd6fe']];

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

// ── عرض الفاتورة ──
function viewInvoice(id){
    document.getElementById('vTitle').textContent='جارٍ التحميل...';
    document.getElementById('vSub').textContent='';
    document.getElementById('vEditBtn').style.display='none';
    document.getElementById('vBody').innerHTML='<div class="text-center py-4"><span class="spinner-border text-success"></span></div>';
    viewModal.show();
    post({_action:'get_invoice',id}).then(d=>{
        if(!d.ok){document.getElementById('vBody').innerHTML=`<div class="text-danger p-3">${d.msg}</div>`;return;}
        const inv=d.data;
        const st=STATUS_MAP[inv.status]||STATUS_MAP['draft'];
        const pay=PAY_MAP[inv.payment_status]||PAY_MAP['pending'];
        document.getElementById('vTitle').textContent='فاتورة: '+inv.invoice_number;
        document.getElementById('vSub').textContent=inv.customer_name||'';
        if(inv.status==='draft'){
            const eb=document.getElementById('vEditBtn');
            eb.href=`invoice_edit.php?id=${inv.id}`;
            eb.style.display='';
        }

        // تجميع البنود بكروبات
        const grpMap={};
        (inv.items||[]).forEach(it=>{
            const k=`${it.product_id||it.item_name}_${it.unit_price}_${it.color||''}`;
            if(!grpMap[k]) grpMap[k]={
                item_name:it.item_name, model_number:it.model_number||'',
                unit_price:parseFloat(it.unit_price), color:it.color||'—',
                qty:parseFloat(it.quantity), total:parseFloat(it.total_price),
                sizes:[], cost_total:0
            };
            else{
                grpMap[k].qty+=parseFloat(it.quantity);
                grpMap[k].total+=parseFloat(it.total_price);
            }
            grpMap[k].cost_total+=(parseFloat(it.cost_price_usd||0)*parseFloat(it.quantity));
            if(it.size&&!grpMap[k].sizes.includes(it.size)) grpMap[k].sizes.push(it.size);
        });
        const grpRows=Object.values(grpMap).sort((a,b)=>a.unit_price-b.unit_price);
        const priceList=[...new Set(grpRows.map(g=>g.unit_price))];

        const itemsHtml=grpRows.map(g=>{
            const pi=priceList.indexOf(g.unit_price);
            const [bg,clr,br]=GRP_COLORS[pi%4];
            const badge=`<span style="background:${bg};color:${clr};border:1px solid ${br};border-radius:12px;font-size:.68rem;padding:2px 8px;font-weight:600">كروب ${pi+1}</span>`;
            return `<tr>
                <td><div class="fw-600" style="font-size:.8rem">${g.item_name}</div>
                    <div style="font-size:.7rem;color:#94a3b8" dir="ltr">${g.model_number}</div></td>
                <td>${badge}<div style="font-size:.72rem;font-weight:600;color:#334155;margin-top:2px">${g.sizes.join(' · ')}</div></td>
                <td class="text-center" style="font-size:.78rem">${g.color}</td>
                <td class="n text-center fw-600">${g.qty.toFixed(0)}</td>
                <td class="n text-center">$ ${g.unit_price.toFixed(4)}</td>
                <td class="n text-end fw-600">$ ${g.total.toFixed(2)}</td>
                <td class="n text-center" style="color:#94a3b8;font-size:.72rem">$ ${g.cost_total.toFixed(2)}</td>
            </tr>`;
        }).join('');

        const profit=parseFloat(inv.total_amount)-(inv.items||[]).reduce((s,it)=>s+(parseFloat(it.cost_price_usd||0)*parseFloat(it.quantity)),0);

        document.getElementById('vBody').innerHTML=`
        <div class="row g-2 mb-3">
            <div class="col-md-3"><small style="color:#64748b">العميل</small><div class="fw-600">${inv.customer_name||'—'}</div></div>
            <div class="col-md-2"><small style="color:#64748b">التاريخ</small><div>${inv.invoice_date}</div></div>
            <div class="col-md-2"><small style="color:#64748b">الاستحقاق</small><div>${inv.due_date||'—'}</div></div>
            <div class="col-md-2"><small style="color:#64748b">الحالة</small>
                <div><span class="badge ${st.cls}">${st.label}</span></div></div>
            <div class="col-md-3"><small style="color:#64748b">الدفع</small>
                <div class="${pay.cls} fw-600" style="font-size:.83rem">${pay.label}</div></div>
        </div>
        <div class="table-responsive mb-3">
        <table class="mtbl" style="font-size:.78rem">
            <thead><tr style="background:#f8fafc">
                <th>المنتج</th><th>الكروب/القياسات</th><th class="text-center">اللون</th>
                <th class="text-center">الكمية</th>
                <th class="text-center">سعر الوحدة ($)</th>
                <th class="text-end">الإجمالي ($)</th>
                <th class="text-center" style="color:#94a3b8">التكلفة ($)</th>
            </tr></thead>
            <tbody>${itemsHtml}</tbody>
        </table>
        </div>
        <div class="row justify-content-end">
          <div class="col-md-4">
            <div style="background:#f8fafc;border-radius:10px;padding:10px 14px">
                ${parseFloat(inv.discount_amount)>0?`<div class="det-row"><span style="color:#64748b">خصم (${parseFloat(inv.discount_percentage)*100}%)</span><span class="n text-danger">-$ ${parseFloat(inv.discount_amount).toFixed(2)}</span></div>`:''}
                ${parseFloat(inv.kdv_amount)>0?`<div class="det-row"><span style="color:#64748b">ضريبة (${inv.kdv_rate}%)</span><span class="n">+$ ${parseFloat(inv.kdv_amount).toFixed(2)}</span></div>`:''}
                <div class="det-row" style="font-weight:700;font-size:.9rem;border-top:1px solid #e2e8f0;padding-top:6px">
                    <span>الإجمالي</span><span class="n text-success">$ ${parseFloat(inv.total_amount).toFixed(2)}</span>
                </div>
                <div class="det-row"><span style="color:#16a34a">المدفوع</span>
                    <span class="n text-success">$ ${parseFloat(inv.paid_amount||0).toFixed(2)}</span></div>
                <div class="det-row"><span style="color:#dc2626">المتبقي</span>
                    <span class="n text-danger">$ ${parseFloat(inv.balance_amount||0).toFixed(2)}</span></div>
                <div class="det-row" style="border-top:1px solid #e2e8f0;padding-top:6px;margin-top:4px">
                    <span style="color:#7c3aed">الربح التقديري</span>
                    <span class="n" style="color:#7c3aed;font-weight:600">$ ${profit.toFixed(2)}</span>
                </div>
            </div>
          </div>
        </div>
        ${inv.notes?`<div style="background:#f8fafc;border-radius:8px;padding:8px 12px;margin-top:10px;font-size:.78rem;color:#64748b">${inv.notes}</div>`:''}
        ${inv.status==='draft'?`<div style="margin-top:12px">
            <button class="btn btn-sm fw-600 w-100" style="border-radius:8px;background:#16a34a;color:#fff;font-size:.8rem"
                onclick="confirmInvoice(${inv.id},'${inv.invoice_number}')">
                <i class="bi bi-check-circle me-1"></i>تأكيد الفاتورة وخصم المخزون
            </button>
        </div>`:''}`;
    });
}

// ── تأكيد ──
let _cId=0;
function confirmInvoice(id,no){
    _cId=id;
    document.getElementById('cInvNo').textContent='فاتورة: '+no;
    viewModal.hide();
    confirmModal.show();
}
function doConfirm(){
    document.getElementById('confirmTxt').style.opacity='0';
    document.getElementById('confirmSpin').style.display='inline-block';
    post({_action:'confirm_invoice',id:_cId}).then(d=>{
        document.getElementById('confirmTxt').style.opacity='1';
        document.getElementById('confirmSpin').style.display='none';
        if(d.ok){toast('✅ '+d.msg);confirmModal.hide();setTimeout(()=>location.reload(),800);}
        else toast(d.msg,'danger');
    });
}

// ── إلغاء ──
function cancelInvoice(id,no){
    if(!confirm(`إلغاء الفاتورة "${no}"؟\nالفواتير المؤكدة سيتم إعادة كمياتها للمخزون.`))return;
    post({_action:'cancel_invoice',id}).then(d=>{
        if(d.ok){toast(d.msg);setTimeout(()=>location.reload(),700);}
        else toast(d.msg,'danger');
    });
}
</script>
</body>
</html>
