<?php
/**
 * purchases/index.php — فواتير شراء المنتجات النهائية
 * المسار: /bayhas/aleppo/modules/purchases/index.php
 */
ini_set('display_errors',1);
error_reporting(E_ALL);
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
$TPR   = "products_{$TS}";
$TPSZ  = "product_sizes_{$TS}";
$TPCL  = "product_colors_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// بيانات الفرع للطباعة
$branchInfo = $pdo->prepare("SELECT * FROM branches WHERE table_suffix=? LIMIT 1");
$branchInfo->execute([$TS]);
$branchInfo = $branchInfo->fetch(PDO::FETCH_ASSOC) ?: [];

// شركات الشحن
try {
    $shippingCarriers = $pdo->query("SELECT id,name,contact_person,phone FROM shipping_carriers WHERE status='active' ORDER BY name")->fetchAll();
} catch(Exception $e){ $shippingCarriers=[]; }

// بيانات المودال
try { $warehouses = $pdo->query("SELECT id,name FROM `{$TW}` WHERE status='active' ORDER BY name")->fetchAll(); }
catch(Exception $e){ $warehouses=[]; }

try { $currencies = $pdo->query("SELECT id,code,symbol FROM currencies WHERE status='active' ORDER BY is_base DESC")->fetchAll(); }
catch(Exception $e){ $currencies=[]; }

$TIAS = "invoice_account_settings_{$TS}";
$TAC  = "account_charts_{$TS}";
try {
    $cashAccounts=$pdo->query("SELECT ac.id,ac.code,ac.name,c.code AS cur_code,c.symbol AS cur_sym
        FROM `{$TAC}` ac
        LEFT JOIN currencies c ON c.id=ac.currency_id
        WHERE ac.account_type='asset' AND ac.is_active=1 AND ac.level>=3
        ORDER BY ac.code")->fetchAll();
} catch(Exception $e){ $cashAccounts=[]; }

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        if ($act === 'get_purchase') {
            $id = (int)$_POST['id'];
            $st = $pdo->prepare("SELECT p.*, s.name AS supplier_name,
                s.phone AS supplier_phone,
                s.email AS supplier_email,
                s.address AS supplier_address,
                s.tax_number AS supplier_tax,
                c.code AS currency,
                c.symbol AS currency_symbol,
                w.name AS warehouse_name
                FROM `{$TP}` p
                LEFT JOIN `{$TSP}` s ON s.id=p.supplier_id
                LEFT JOIN currencies c ON c.id=p.invoice_currency_id
                LEFT JOIN `{$TW}` w ON w.id=p.warehouse_id
                WHERE p.id=?");
            $st->execute([$id]);
            $pur = $st->fetch();
            if (!$pur) throw new Exception('الفاتورة غير موجودة');
            // warehouse_name/warehouse_id now come from purchases_{TS}.warehouse_id
            // (one warehouse per whole invoice) instead of purchase_items_{TS} —
            // resolved per the schema-change design decision.

            // Confirmed against the real product_sizes_alp/product_colors_alp DDL:
            // sizes use a `size` column (not `name`); colors use `name` — different
            // conventions between the two tables.
            $it = $pdo->prepare("SELECT pi.*,
                pr.name AS product_name,
                pr.model_number AS model_number,
                psz.size AS size,
                pcl.name AS color,
                pv.barcode AS barcode
                FROM `{$TPI}` pi
                LEFT JOIN `{$TPR}` pr ON pr.id=pi.product_id
                LEFT JOIN `{$TV}` pv ON pv.id=pi.variant_id
                LEFT JOIN `{$TPSZ}` psz ON psz.id=pv.size_id
                LEFT JOIN `{$TPCL}` pcl ON pcl.id=pv.color_id
                WHERE pi.purchase_id=? ORDER BY pi.id");
            $it->execute([$id]);
            $pur['items'] = $it->fetchAll();
            echo json_encode(['ok'=>true,'data'=>$pur]);
        }

        // ملاحظة: أُزيل من هنا إجراءان محليان ميتان (dead code) لم يكونا
        // يُستدعَيان من أي زر بالواجهة إطلاقاً — confirm_purchase و
        // cancel_purchase. تحقّقتُ عبر البحث بكل استدعاءات الجافاسكربت
        // بهذا الملف: زر "تأكيد الاستلام" وزر "إلغاء" ينادوا فعلياً
        // api/confirm_purchase_invoice.php حصراً (كلاهما يُرحّلان القيود
        // المحاسبية بشكل صحيح). الإجراءان المحذوفان كانا يحدّثان المخزون
        // فقط بدون أي قيد محاسبي — لو بقيا موجودين، أي تعديل مستقبلي
        // بسيط بالواجهة كان ممكن (بالغلط) يستدعيهما بدل المسار الصحيح،
        // ليعيد بصمت نفس مشكلة "تأكيد بدون قيود" التي أُصلحت سابقاً
        // بملف المبيعات (sales_index.php).

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
    c.code AS currency,
    c.symbol AS currency_symbol,
    COUNT(pi.id) AS items_count
    FROM `{$TP}` p
    LEFT JOIN `{$TSP}` s ON s.id=p.supplier_id
    LEFT JOIN currencies c ON c.id=p.invoice_currency_id
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
        COALESCE(SUM(CASE WHEN status!='cancelled' THEN final_amount_base_currency END),0) AS total_base,
        COALESCE(SUM(CASE WHEN payment_status='pending' AND status='received' THEN final_amount_base_currency END),0) AS balance_base
        FROM `{$TP}`")->fetch();
} catch (Exception $e) {
    $stats=['total'=>0,'drafts'=>0,'received'=>0,'total_base'=>0,'balance_base'=>0];
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
            <div><div class="stat-val n"><?= number_format($stats['total_base'],2) ?> $</div><div class="stat-lbl">إجمالي المشتريات</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fee2e2"><i class="bi bi-exclamation-circle text-danger"></i></div>
            <div><div class="stat-val n"><?= number_format($stats['balance_base'],2) ?> $</div><div class="stat-lbl">المستحق للموردين</div></div>
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
                <?= $pur['final_amount_base_currency'] ? number_format($pur['final_amount_base_currency'],2).' $' : '—' ?>
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
<div class="modal fade" id="confirmModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#065f46,#16a34a);border-radius:16px 16px 0 0">
        <div>
          <h6 class="modal-title text-white fw-700 mb-0">
              <i class="bi bi-check-circle me-2"></i>تأكيد استلام الفاتورة
          </h6>
          <div id="cInvNo" style="font-size:.78rem;color:rgba(255,255,255,.8);margin-top:2px"></div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <button class="btn btn-sm" style="background:rgba(255,255,255,.2);color:#fff;border-radius:8px"
                    onclick="printPurchaseInvoice()" title="طباعة الفاتورة">
                <i class="bi bi-printer me-1"></i>طباعة
            </button>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
      </div>
      <div class="modal-body px-4 py-3">
        <input type="hidden" id="cId">
        <input type="hidden" id="cCurCode">
        <input type="hidden" id="cInvTotal">
        <input type="hidden" id="cCurSym">

        <div class="row g-3">

          <!-- ── بنود الفاتورة ── -->
          <div class="col-12">
            <div style="background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;overflow:hidden">
              <div style="padding:8px 14px;background:#f1f5f9;font-size:.8rem;font-weight:700;color:#1e293b;border-bottom:1px solid #e2e8f0">
                <i class="bi bi-list-ul me-1 text-primary"></i>بنود الفاتورة
              </div>
              <div class="table-responsive">
                <table style="width:100%;font-size:.78rem;border-collapse:collapse" id="cItemsTable">
                  <thead>
                    <tr style="background:#f8fafc">
                      <th style="padding:6px 10px;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0">#</th>
                      <th style="padding:6px 10px;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0">بيان القطعة</th>
                      <th style="padding:6px 10px;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0">الكمية</th>
                      <th style="padding:6px 10px;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0">سعر الوحدة</th>
                      <th style="padding:6px 10px;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0">الإجمالي</th>
                    </tr>
                  </thead>
                  <tbody id="cItemsBody"></tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- ── ملخص المبالغ ── -->
          <div class="col-md-6">
            <div style="background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;padding:10px 14px;font-size:.8rem">
              <div class="d-flex justify-content-between mb-1">
                <span class="text-muted">المبلغ الصافي للمنتجات</span>
                <span class="fw-600" id="cSumProducts">—</span>
              </div>
              <div class="d-flex justify-content-between mb-1">
                <span class="text-muted">نسبة الخصم</span>
                <span class="fw-600 text-danger" id="cSumDiscount">0%</span>
              </div>
              <div class="d-flex justify-content-between mb-1">
                <span class="text-muted">قيمة الخصم</span>
                <span class="fw-600 text-danger" id="cSumDiscountAmt">0.00</span>
              </div>
              <div class="d-flex justify-content-between mb-1">
                <span class="text-muted">الضريبة</span>
                <span class="fw-600" id="cSumTax">0.00</span>
              </div>
              <hr style="margin:5px 0;border-color:#e2e8f0">
              <div class="d-flex justify-content-between fw-700">
                <span>المبلغ الإجمالي النهائي</span>
                <span style="color:#1e3a8a" id="cSumFinal">—</span>
              </div>
            </div>
          </div>

          <!-- ── تكاليف الشحن ── -->
          <div class="col-md-6">
            <label class="form-label small fw-600 text-secondary mb-1">
                <i class="bi bi-truck me-1 text-warning"></i>تكاليف الشحن والنقلية
            </label>
            <div class="row g-2">
              <div class="col-12">
                <div class="btn-group w-100 mb-2" role="group">
                  <input type="radio" class="btn-check" name="shippingOn" id="shipOnUs" value="us" checked onchange="updateTotal()">
                  <label class="btn btn-sm btn-outline-danger fw-600" for="shipOnUs" style="border-radius:8px 0 0 8px">
                    <i class="bi bi-arrow-down-circle me-1"></i>علينا (تُضاف للتكلفة)
                  </label>
                  <input type="radio" class="btn-check" name="shippingOn" id="shipOnThem" value="them" onchange="updateTotal()">
                  <label class="btn btn-sm btn-outline-success fw-600" for="shipOnThem" style="border-radius:0 8px 8px 0">
                    <i class="bi bi-arrow-up-circle me-1"></i>على البائع (مجانية)
                  </label>
                </div>
              </div>
              <div class="col-7" id="shippingAmtWrap">
                <div class="input-group input-group-sm">
                  <input type="number" id="cShipping" class="form-control" placeholder="0.00"
                         min="0" step="0.01" oninput="updateTotal()">
                  <select id="cShippingCur" class="form-select" style="max-width:85px" onchange="updateTotal()">
                    <?php foreach($currencies as $cur): ?>
                    <option value="<?=$cur['code']?>"><?=$cur['code']?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="col-5" id="shippingAmtWrap2">
                <div class="input-group input-group-sm">
                    <select id="cShippingCarrier" class="form-select form-select-sm"
                            onchange="onCarrierChange(this)">
                        <option value="">— شركة الشحن —</option>
                        <?php foreach($shippingCarriers as $sc): ?>
                        <option value="<?=$sc['id']?>"
                                data-name="<?=htmlspecialchars($sc['name'],ENT_QUOTES)?>"
                                data-phone="<?=htmlspecialchars($sc['phone']??'',ENT_QUOTES)?>"
                                data-payable-id="<?=$sc['payable_account_id']??0?>">
                            <?=htmlspecialchars($sc['name'])?>
                            <?php if($sc['contact_person']): ?>(<?=htmlspecialchars($sc['contact_person'])?>)<?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                        <?php if(empty($shippingCarriers)): ?>
                        <option value="" disabled>لا توجد شركات — أضف من الإعدادات</option>
                        <?php endif; ?>
                    </select>
                    <a href="/bayhas/aleppo/modules/accounting/shipping_carriers.php" target="_blank"
                       class="btn btn-sm btn-outline-warning" style="padding:3px 7px" title="إدارة شركات الشحن">
                        <i class="bi bi-box-arrow-up-right"></i>
                    </a>
                </div>
                <input type="hidden" id="cShippingDesc">
                <input type="hidden" id="cShippingPayableId">
              </div>
              <!-- طريقة دفع الشحن -->
              <div class="col-12" id="shippingPayWrap">
                <div class="btn-group w-100" role="group">
                  <input type="radio" class="btn-check" name="shippingPay" id="shipPayCash" value="cash" checked onchange="onShipPayChange()">
                  <label class="btn btn-sm btn-outline-success fw-600" for="shipPayCash" style="border-radius:8px 0 0 8px;font-size:.75rem">
                    <i class="bi bi-cash me-1"></i>دفع نقدي (يُخصم من الصندوق)
                  </label>
                  <input type="radio" class="btn-check" name="shippingPay" id="shipPayCredit" value="credit" onchange="onShipPayChange()">
                  <label class="btn btn-sm btn-outline-warning fw-600" for="shipPayCredit" style="border-radius:0 8px 8px 0;font-size:.75rem">
                    <i class="bi bi-clock-history me-1"></i>آجل (ذمة شركة الشحن)
                  </label>
                </div>
                <!-- حساب الصندوق عند الدفع النقدي -->
                <div id="shipCashAccWrap" class="mt-1">
                  <select id="cShipCashAccount" class="form-select form-select-sm">
                    <option value="">— حساب الصندوق —</option>
                    <?php foreach($cashAccounts as $ca): ?>
                    <option value="<?=$ca['id']?>"
                            data-cur="<?=htmlspecialchars($ca['cur_code']??'')?>">
                        <?=htmlspecialchars($ca['code'].' — '.$ca['name'])?> (<?=htmlspecialchars($ca['cur_sym']??'')?>)
                    </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <!-- تنبيه الذمة -->
                <div id="shipCreditNote" class="mt-1" style="display:none;font-size:.72rem;color:#d97706;background:#fffbeb;border-radius:6px;padding:4px 8px">
                  <i class="bi bi-info-circle me-1"></i>
                  سيُسجَّل المبلغ كذمة لشركة الشحن في حسابها بشجرة الحسابات
                </div>
              </div>
            </div>
          </div>

          <!-- ── تاريخ الاستلام + مستودع ── -->
          <div class="col-md-4">
            <label class="form-label small fw-600 text-secondary mb-1">
                <i class="bi bi-calendar-check me-1 text-success"></i>تاريخ الاستلام
            </label>
            <input type="date" id="cReceiveDate" class="form-control form-control-sm"
                   value="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-600 text-secondary mb-1">
                <i class="bi bi-building me-1 text-primary"></i>مستودع الاستلام
            </label>
            <div id="cWarehouseDisplay" style="background:#f1f5f9;border-radius:8px;padding:6px 10px;font-size:.82rem;color:#1e293b;font-weight:600">
                <i class="bi bi-building me-1 text-primary"></i><span id="cWarehouseName">جارٍ التحميل...</span>
            </div>
            <input type="hidden" id="cWarehouse">
          </div>

          <!-- ── دفع جزئي ── -->
          <div class="col-12">
            <label class="form-label small fw-600 text-secondary mb-1">
                <i class="bi bi-cash me-1 text-success"></i>دفع جزئي عند الاستلام
                <span style="font-size:.68rem;color:#94a3b8">(اختياري)</span>
            </label>
            <div class="row g-2">
              <div class="col-md-5">
                <div class="input-group input-group-sm">
                  <input type="number" id="cPaidAmt" class="form-control fw-600" placeholder="0.00"
                         min="0" step="0.01" oninput="onPaidChange()">
                  <select id="cPaidCur" class="form-select" style="max-width:85px" onchange="onPaidChange()">
                    <?php foreach($currencies as $cur): ?>
                    <option value="<?=$cur['code']?>"><?=$cur['code']?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <!-- سعر الصرف — يظهر فقط عند اختلاف العملة -->
              <div class="col-md-7" id="cPaidRateWrap" style="display:none">
                <div class="input-group input-group-sm">
                  <span class="input-group-text" style="font-size:.73rem" id="cPaidRateLabel">1 ? =</span>
                  <input type="number" id="cPaidRate" class="form-control fw-600"
                         min="0.000001" step="0.001" dir="ltr" placeholder="سعر الصرف"
                         oninput="updateTotal()">
                  <span class="input-group-text" id="cPaidRateSuffix" style="font-size:.73rem"></span>
                  <button type="button" class="btn btn-sm btn-outline-primary" style="border-radius:0 7px 7px 0"
                          onclick="fetchPaidRate()" title="جلب السعر من الإنترنت">
                    <i class="bi bi-arrow-repeat" id="paidRateIcon"></i>
                  </button>
                </div>
                <div id="cPaidRateHint" style="font-size:.7rem;color:#64748b;margin-top:3px"></div>
              </div>
              <!-- المبلغ المحوَّل لعملة الفاتورة -->
              <div class="col-12" id="cPaidConvertWrap" style="display:none">
                <div style="background:#f0fdf4;border-radius:7px;padding:5px 10px;font-size:.78rem">
                  <i class="bi bi-arrow-left-right me-1 text-success"></i>
                  المبلغ بعملة الفاتورة:
                  <strong id="cPaidConverted" style="color:#16a34a">—</strong>
                </div>
              </div>
            </div>
          </div>

          <!-- حساب الدفع -->
          <div class="col-md-6">
            <label class="form-label small fw-600 text-secondary mb-1">
                <i class="bi bi-safe me-1"></i>حساب الدفع (صندوق/بنك)
            </label>
            <select id="cCashAccount" class="form-select form-select-sm">
                <option value="">— اختر حساب الدفع —</option>
                <?php foreach($cashAccounts as $ca): ?>
                <option value="<?=$ca['id']?>"
                        data-cur="<?=htmlspecialchars($ca['cur_code']??'')?>">
                    <?=htmlspecialchars($ca['code'].' — '.$ca['name'])?> (<?=htmlspecialchars($ca['cur_sym']??'')?>)
                </option>
                <?php endforeach; ?>
            </select>
          </div>

          <!-- صورة الفاتورة -->
          <div class="col-md-6">
            <label class="form-label small fw-600 text-secondary mb-1">
                <i class="bi bi-image me-1 text-info"></i>صورة فاتورة المورد
            </label>
            <input type="file" id="cInvoiceImg" class="form-control form-control-sm"
                   accept="image/*,application/pdf" onchange="previewImg(this)">
            <div id="cImgPreview" style="display:none;margin-top:6px">
                <img id="cImgThumb" style="max-height:60px;border-radius:6px;border:1px solid #e2e8f0">
                <button type="button" class="btn btn-sm btn-light ms-1" onclick="clearImg()"
                        style="border-radius:5px;font-size:.7rem">
                    <i class="bi bi-x"></i>
                </button>
            </div>
          </div>

          <!-- ملاحظات -->
          <div class="col-12">
            <label class="form-label small fw-600 text-secondary mb-1">
                <i class="bi bi-chat-left-text me-1"></i>ملاحظات الاستلام
            </label>
            <textarea id="cNotes" class="form-control form-control-sm" rows="2"
                      placeholder="حالة البضاعة، ملاحظات خاصة..."></textarea>
          </div>

          <!-- ملخص مالي نهائي -->
          <div class="col-12">
            <div style="background:#f0fdf4;border-radius:10px;border:1px solid #bbf7d0;padding:10px 14px;font-size:.82rem">
              <div class="fw-700 mb-2" style="color:#065f46;font-size:.8rem">
                <i class="bi bi-calculator me-1"></i>ملخص المبالغ حسب العملة
              </div>
              <div id="cTotSummary">
                <!-- يتم بناؤه ديناميكياً في updateTotal() -->
              </div>
            </div>
          </div>

        </div>
      </div>
      <div class="modal-footer border-0 px-4 pb-4 gap-2">
        <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
        <button class="btn btn-sm fw-600" style="border-radius:8px;background:#16a34a;color:#fff;min-width:140px"
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
            const k=`${it.product_id||it.product_name||it.id}_${it.unit_price}_${it.color||''}`;
            if(!grpMap[k]){
                grpMap[k]={
                    product_name:it.product_name||'—', model_number:it.model_number||'',
                    unit_price:parseFloat(it.unit_price),
                    unit_price_base:parseFloat(it.unit_price_base_currency||(parseFloat(it.unit_price)/rate)),
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
                <td class="n text-center" style="color:#16a34a;font-size:.72rem">${g.unit_price_base.toFixed(4)} $</td>
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
                    ${!isUSD&&p.final_amount_base_currency?`<small style="color:#94a3b8;font-weight:400"> (${parseFloat(p.final_amount_base_currency).toFixed(2)} $)</small>`:''}</span>
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
let _cId=0,_cTotal=0,_cCurSym='$',_cDiscount=0,_cTax=0,_cProducts=0,_cPurchaseData=null;
function float(v){ return parseFloat(v)||0; }

function openConfirmModal(id,no,total,sym){
    _cId=id;_cTotal=parseFloat(total)||0;_cCurSym=sym||'$';
    document.getElementById('cId').value=id;
    document.getElementById('cInvNo').textContent='فاتورة: '+no;
    document.getElementById('cCurSym').value=sym;
    document.getElementById('cInvTotal').value=total;
    document.getElementById('cReceiveDate').value='<?= date('Y-m-d') ?>';
    document.getElementById('cShipping').value='';
    document.getElementById('cShippingCarrier').value='';
    document.getElementById('cShippingDesc').value='';
    document.getElementById('cShippingPayableId').value='';
    document.getElementById('shippingPayWrap').style.display='none';
    document.getElementById('shipCreditNote').style.display='none';
    document.getElementById('cShipCashAccount').value='';
    document.getElementById('cPaidAmt').value='';
    document.getElementById('cNotes').value='';
    document.getElementById('cWarehouse').value='';
    document.getElementById('cCashAccount').value='';
    document.getElementById('cItemsBody').innerHTML='<tr><td colspan="5" class="text-center p-3"><span class="spinner-border spinner-border-sm"></span></td></tr>';
    clearImg();
    confirmModal.show();
    // جلب بنود الفاتورة
    post({_action:'get_purchase',id}).then(d=>{
        if(!d.ok){ document.getElementById('cItemsBody').innerHTML='<tr><td colspan="5" class="text-center text-danger p-2">خطأ: '+d.msg+'</td></tr>'; return; }
        const p=d.data;
        _cPurchaseData=p; // حفظ للطباعة
        _cProducts=parseFloat(p.total_amount)||0;
        _cDiscount=parseFloat(p.discount_amount)||0;
        _cTax=parseFloat(p.tax_amount)||0;
        const sym=p.currency_symbol||'$';
        const cur=p.currency||'USD';
        const fmt=n=>new Intl.NumberFormat('en').format(parseFloat(n||0).toFixed(2));

        // ── المستودع من الفاتورة ──
        // now sourced from purchases_{TS}.warehouse_id (one warehouse per
        // whole invoice) instead of purchase_items_{TS} — see get_purchase.
        document.getElementById('cWarehouseName').textContent=p.warehouse_name||'المستودع الرئيسي';
        document.getElementById('cWarehouse').value=p.warehouse_id||'';
        // بنود — مجمعة حسب المنتج
        const groups={};
        (p.items||[]).forEach(it=>{
            const key=(it.product_name||('بند #'+it.id))+(it.color?' - '+it.color:'');
            if(!groups[key]) groups[key]={name:key,qty:0,unit:parseFloat(it.unit_price),total:0};
            groups[key].qty+=parseFloat(it.quantity);
            groups[key].total+=parseFloat(it.total_price);
        });
        let rows='',i=1;
        Object.values(groups).forEach(g=>{
            rows+=`<tr style="border-bottom:1px solid #f1f5f9">
                <td style="padding:5px 10px;color:#94a3b8">${i++}</td>
                <td style="padding:5px 10px;font-weight:600">${g.name}</td>
                <td style="padding:5px 10px;text-align:center">${g.qty}</td>
                <td style="padding:5px 10px;text-align:left;direction:ltr">${sym} ${fmt(g.unit)}</td>
                <td style="padding:5px 10px;text-align:left;direction:ltr;font-weight:600">${sym} ${fmt(g.total)}</td>
            </tr>`;
        });
        document.getElementById('cItemsBody').innerHTML=rows||'<tr><td colspan="5" class="text-center text-muted p-2">لا توجد بنود</td></tr>';
        // ملخص المبالغ
        document.getElementById('cSumProducts').textContent=sym+' '+fmt(p.total_amount);
        document.getElementById('cSumDiscount').textContent=(parseFloat(p.discount_amount||0)/parseFloat(p.total_amount||1)*100).toFixed(2)+'%';
        document.getElementById('cSumDiscountAmt').textContent='- '+sym+' '+fmt(p.discount_amount);
        document.getElementById('cSumTax').textContent=sym+' '+fmt(p.tax_amount);
        document.getElementById('cSumFinal').textContent=sym+' '+fmt(p.final_amount);
        _cCurSym=sym; _cTotal=parseFloat(p.final_amount)||0;
        updateTotal();
    });
}

function onPaidChange(){
    const paidCur=document.getElementById('cPaidCur').value;
    const invCur=_cPurchaseData?.currency||'USD';
    const rateWrap=document.getElementById('cPaidRateWrap');
    const convertWrap=document.getElementById('cPaidConvertWrap');
    if(paidCur && paidCur!==invCur){
        rateWrap.style.display='';
        document.getElementById('cPaidRateLabel').textContent='1 '+paidCur+' =';
        document.getElementById('cPaidRateSuffix').textContent=invCur;
        convertWrap.style.display='';
        if(!document.getElementById('cPaidRate').value) fetchPaidRate();
    } else {
        rateWrap.style.display='none';
        convertWrap.style.display='none';
        document.getElementById('cPaidRate').value='1';
    }
    updateTotal();
}

async function fetchPaidRate(){
    const paidCur=document.getElementById('cPaidCur').value;
    const invCur=_cPurchaseData?.currency||'USD';
    const icon=document.getElementById('paidRateIcon');
    icon.classList.add('spin');
    try {
        const resp=await fetch('https://api.exchangerate-api.com/v4/latest/'+invCur);
        const data=await resp.json();
        const rate=data.rates[paidCur];
        if(rate){
            document.getElementById('cPaidRate').value=rate.toFixed(4);
            document.getElementById('cPaidRateHint').innerHTML=
                '<i class="bi bi-check-circle-fill text-success me-1"></i>1 '+paidCur+' = '+rate.toFixed(4)+' '+invCur;
            updateTotal();
        } else {
            document.getElementById('cPaidRateHint').innerHTML=
                '<i class="bi bi-exclamation-triangle text-warning me-1"></i>غير متوفر — أدخل يدوياً';
        }
    } catch(e){
        document.getElementById('cPaidRateHint').innerHTML=
            '<i class="bi bi-wifi-off text-danger me-1"></i>تعذّر الاتصال';
    } finally { icon.classList.remove('spin'); }
}

function onCarrierChange(sel){
    const opt=sel.options[sel.selectedIndex];
    document.getElementById('cShippingDesc').value=opt.value?opt.dataset.name:'';
    document.getElementById('cShippingPayableId').value=opt.value?(opt.dataset.payableId||''):'';
    // إظهار قسم طريقة الدفع فقط إذا اختار شركة + مبلغ
    const hasShip=parseFloat(document.getElementById('cShipping').value)||0;
    document.getElementById('shippingPayWrap').style.display=
        (opt.value&&hasShip>0&&document.querySelector('input[name="shippingOn"]:checked')?.value==='us')?'':'none';
}

function onShipPayChange(){
    const val=document.querySelector('input[name="shippingPay"]:checked')?.value||'cash';
    document.getElementById('shipCashAccWrap').style.display=val==='cash'?'':'none';
    document.getElementById('shipCreditNote').style.display=val==='credit'?'':'none';
}

function updateTotal(){
    const shipOn  =document.querySelector('input[name="shippingOn"]:checked')?.value||'us';
    const ship    =parseFloat(document.getElementById('cShipping').value)||0;
    const shipCur =document.getElementById('cShippingCur').value||'USD';
    const invCur  =_cPurchaseData?.currency||'USD';
    const invSym  =_cPurchaseData?.currency_symbol||'$';
    const fmt     =n=>new Intl.NumberFormat('en').format(Math.abs(parseFloat(n||0)).toFixed(2));

    // الدفع الجزئي مع التحويل
    const paidAmt =parseFloat(document.getElementById('cPaidAmt').value)||0;
    const paidCur =document.getElementById('cPaidCur').value||invCur;
    const paidRate=parseFloat(document.getElementById('cPaidRate').value)||1;
    const paidInInvCur = paidCur===invCur ? paidAmt : paidAmt/paidRate;

    // عرض المبلغ المحوّل
    if(paidAmt>0 && paidCur!==invCur){
        document.getElementById('cPaidConverted').textContent=
            invSym+' '+new Intl.NumberFormat('en').format(paidInInvCur.toFixed(2));
    }

    // تجميع المبالغ حسب العملة
    const totals={};
    if(!totals[invCur]) totals[invCur]={sym:invSym,inv:0,ship:0,paid:0};
    totals[invCur].inv=_cTotal;

    if(shipOn==='us' && ship>0){
        if(!totals[shipCur]) totals[shipCur]={sym:shipCur,inv:0,ship:0,paid:0};
        totals[shipCur].ship=ship;
    }
    if(paidAmt>0){
        if(!totals[invCur]) totals[invCur]={sym:invSym,inv:0,ship:0,paid:0};
        totals[invCur].paid=paidInInvCur;
    }

    // بناء HTML الملخص
    let html='';
    Object.entries(totals).forEach(([cur,t])=>{
        const invTotal=t.inv+t.ship;
        const balance=invTotal-t.paid;
        html+=`<div style="border:1px solid #d1fae5;border-radius:7px;padding:7px 10px;margin-bottom:6px;background:#fff">
            <div style="font-size:.72rem;font-weight:700;color:#065f46;margin-bottom:4px">
                <i class="bi bi-currency-exchange me-1"></i>عملة: ${cur}
            </div>`;
        if(t.inv) html+=`<div class="d-flex justify-content-between" style="font-size:.79rem;margin-bottom:2px">
            <span class="text-muted">إجمالي الفاتورة</span>
            <span class="fw-600">${t.sym} ${fmt(t.inv)}</span></div>`;
        if(t.ship&&shipOn==='us') html+=`<div class="d-flex justify-content-between" style="font-size:.79rem;margin-bottom:2px">
            <span class="text-muted">+ تكاليف الشحن</span>
            <span class="fw-600 text-warning">${t.sym} ${fmt(t.ship)}</span></div>`;
        if(t.paid) html+=`<div class="d-flex justify-content-between" style="font-size:.79rem;margin-bottom:2px">
            <span class="text-muted">— المدفوع الآن</span>
            <span class="fw-600 text-success">${t.sym} ${fmt(t.paid)}</span></div>`;
        if(t.inv||t.ship) html+=`<div class="d-flex justify-content-between fw-700 border-top pt-1 mt-1" style="font-size:.82rem">
            <span>المتبقي</span>
            <span style="color:${balance>0?'#dc2626':'#16a34a'}">${t.sym} ${fmt(balance)}</span></div>`;
        html+='</div>';
    });
    document.getElementById('cTotSummary').innerHTML=html||
        '<div class="text-muted" style="font-size:.79rem">أدخل المبالغ لعرض الملخص</div>';

    // إظهار/إخفاء حقل مبلغ الشحن
    document.getElementById('shippingAmtWrap').style.opacity=shipOn==='us'?'1':'0.4';
    // إظهار طريقة دفع الشحن فقط إذا كان علينا + مبلغ + شركة
    const carrierId=document.getElementById('cShippingCarrier').value;
    const shipPayWrap=document.getElementById('shippingPayWrap');
    if(shipOn==='us' && ship>0 && carrierId){
        shipPayWrap.style.display='';
        onShipPayChange();
    } else {
        shipPayWrap.style.display='none';
    }
}

function previewImg(inp){
    if(!inp.files||!inp.files[0])return;
    const f=inp.files[0];
    if(f.type.startsWith('image/')){
        const rd=new FileReader();
        rd.onload=e=>{document.getElementById('cImgThumb').src=e.target.result;document.getElementById('cImgPreview').style.display='';};
        rd.readAsDataURL(f);
    }else{document.getElementById('cImgPreview').style.display='';}
}
function clearImg(){
    document.getElementById('cInvoiceImg').value='';
    document.getElementById('cImgPreview').style.display='none';
    document.getElementById('cImgThumb').src='';
}

// ── طباعة الفاتورة ──
const BRANCH=<?= json_encode([
    'name'      => $branchInfo['name']       ?? $branchName,
    'phone'     => $branchInfo['phone']      ?? '',
    'address'   => $branchInfo['address']    ?? '',
    'city'      => $branchInfo['city']       ?? '',
    'email'     => $branchInfo['email']      ?? '',
    'tax_number'=> $branchInfo['tax_number'] ?? '',
]) ?>;

function printPurchaseInvoice(){
    const p=_cPurchaseData;
    if(!p){ toast('يرجى فتح مودال التأكيد أولاً','danger'); return; }
    const sym=p.currency_symbol||'$';
    const fmt=n=>sym+' '+new Intl.NumberFormat('en').format(parseFloat(n||0).toFixed(2));
        // تجميع البنود
        const groups={};
        (p.items||[]).forEach(it=>{
            const key=(it.model_number||'')+'|'+(it.product_name||('بند #'+it.id))+'|'+(it.color||'');
            if(!groups[key]) groups[key]={
                name:it.product_name||'—',model:it.model_number||'',
                color:it.color||'',size:it.size||'',
                qty:0,unit:parseFloat(it.unit_price),total:0
            };
            groups[key].qty+=parseFloat(it.quantity);
            groups[key].total+=parseFloat(it.total_price);
        });
        let rows='',i=1;
        Object.values(groups).forEach(g=>{
            rows+=`<tr>
                <td>${i++}</td>
                <td>${g.name}</td>
                <td>${g.size}</td>
                <td style="color:#2563eb">${g.color}</td>
                <td>${g.model}</td>
                <td>${g.qty}</td>
                <td>${fmt(g.unit)}</td>
                <td>${fmt(g.total)}</td>
            </tr>`;
        });
        const LOGO_URL='/bayhas/assets/images/bayhas_logo.png';
        const html=`<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
<meta charset="UTF-8">
<title>فاتورة شراء ${p.purchase_number}</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Arial',sans-serif;font-size:11px;color:#111;padding:20px;max-width:800px;margin:0 auto}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;padding-bottom:12px;border-bottom:2px solid #1e3a8a}
.header-logo{width:100px;height:auto;object-fit:contain}
.header-center{text-align:center;flex:1;padding:0 16px}
.header-title{font-size:22px;font-weight:800;color:#1e3a8a;margin-bottom:4px}
.header-sub{font-size:10px;color:#64748b}
.header-branch{text-align:right;font-size:10px;min-width:160px}
.header-branch .br-name{font-size:14px;font-weight:800;color:#1e3a8a}
.inv-meta{display:flex;gap:8px;margin-bottom:12px}
.inv-meta-box{flex:1;border:1px solid #e2e8f0;border-radius:6px;padding:8px 12px;background:#f8fafc}
.inv-meta-box h4{font-size:9px;font-weight:700;color:#1e3a8a;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;padding-bottom:3px;border-bottom:1px solid #e2e8f0}
.meta-row{display:flex;justify-content:space-between;font-size:10px;margin-bottom:3px}
.meta-row span:first-child{color:#64748b}
.meta-row span:last-child{font-weight:600}
table{width:100%;border-collapse:collapse;margin-bottom:12px;font-size:10px}
thead th{background:#1e3a8a;color:#fff;padding:6px 8px;text-align:right;font-weight:600}
tbody td{padding:5px 8px;border-bottom:1px solid #f1f5f9}
tbody tr:nth-child(even) td{background:#f8fafc}
tfoot td{background:#f1f5f9;font-weight:700;padding:5px 8px}
.totals-wrap{display:flex;justify-content:flex-end;margin-top:8px}
.totals{width:55%;border:1px solid #e2e8f0;border-radius:6px;overflow:hidden}
.tot-row{display:flex;justify-content:space-between;padding:5px 12px;font-size:10px;border-bottom:1px solid #f1f5f9}
.tot-row.final{background:#1e3a8a;color:#fff;font-weight:700;font-size:12px;border:none}
.footer{text-align:center;margin-top:16px;font-size:9px;color:#94a3b8;border-top:1px solid #e2e8f0;padding-top:8px}
@media print{@page{margin:10mm}button{display:none}}
</style>
</head>
<body>

<!-- الرأسية -->
<div class="header">
  <img src="${LOGO_URL}" class="header-logo" alt="Logo"
       onerror="this.style.display='none'">
  <div class="header-center">
    <div class="header-title">فاتورة شراء</div>
    <div class="header-sub">Purchase Invoice</div>
  </div>
  <div class="header-branch">
    <div class="br-name">${BRANCH.name}</div>
    ${BRANCH.city?`<div>${BRANCH.city}${BRANCH.address?' - '+BRANCH.address:''}</div>`:''}
    ${BRANCH.phone?`<div>هاتف: ${BRANCH.phone}</div>`:''}
    ${BRANCH.email?`<div>${BRANCH.email}</div>`:''}
    ${BRANCH.tax_number?`<div>الرقم الضريبي: ${BRANCH.tax_number}</div>`:''}
  </div>
</div>

<!-- معلومات الفاتورة والمورد -->
<div class="inv-meta">
  <!-- معلومات الفاتورة -->
  <div class="inv-meta-box">
    <h4>معلومات الفاتورة</h4>
    <div class="meta-row"><span>رقم الفاتورة:</span><span>${p.purchase_number}</span></div>
    <div class="meta-row"><span>تاريخ الفاتورة:</span><span>${p.purchase_date||'—'}</span></div>
    <div class="meta-row"><span>تاريخ الاستحقاق:</span><span>${p.due_date||'—'}</span></div>
    <div class="meta-row"><span>العملة:</span><span>${p.currency||'USD'}</span></div>
    <div class="meta-row"><span>طريقة الدفع:</span><span>${p.payment_method||'—'}</span></div>
  </div>
  <!-- معلومات المورد -->
  <div class="inv-meta-box">
    <h4>معلومات المورد</h4>
    <div class="meta-row"><span>اسم المورد:</span><span>${p.supplier_name||'—'}</span></div>
    ${p.supplier_phone?`<div class="meta-row"><span>هاتف:</span><span dir="ltr">${p.supplier_phone}</span></div>`:''}
    ${p.supplier_email?`<div class="meta-row"><span>بريد:</span><span dir="ltr">${p.supplier_email}</span></div>`:''}
    ${p.supplier_address?`<div class="meta-row"><span>العنوان:</span><span>${p.supplier_address}</span></div>`:''}
    ${p.supplier_tax?`<div class="meta-row"><span>الرقم الضريبي:</span><span>${p.supplier_tax}</span></div>`:''}
  </div>
</div>

<!-- تفاصيل المنتجات -->
<table>
  <thead><tr>
    <th>#</th><th>بيان القطعة</th><th>القياس</th>
    <th>اللون</th><th>رقم الموديل</th>
    <th>الكمية</th><th>سعر الوحدة</th><th>المجموع</th>
  </tr></thead>
  <tbody>${rows}</tbody>
  <tfoot><tr>
    <td colspan="5" style="text-align:center">إجمالي الكميات</td>
    <td>${Object.values(groups).reduce((s,g)=>s+g.qty,0)}</td>
    <td></td>
    <td>${fmt(p.final_amount)}</td>
  </tr></tfoot>
</table>

<!-- المبالغ -->
<div class="totals-wrap">
  <div class="totals">
    <div class="tot-row"><span>المبلغ الصافي للمنتجات:</span><span>${fmt(p.total_amount)}</span></div>
    <div class="tot-row"><span>نسبة الخصم:</span><span>${((parseFloat(p.discount_amount||0)/parseFloat(p.total_amount||1))*100).toFixed(5)}%</span></div>
    <div class="tot-row"><span>قيمة الخصم:</span><span>- ${fmt(p.discount_amount)}</span></div>
    <div class="tot-row"><span>الضريبة:</span><span>${fmt(p.tax_amount)}</span></div>
    <div class="tot-row final"><span>المبلغ الإجمالي النهائي:</span><span>${fmt(p.final_amount)}</span></div>
  </div>
</div>

${p.notes?`<div style="margin-top:12px;padding:8px 12px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;font-size:10px"><b>ملاحظات:</b> ${p.notes}</div>`:''}

<div class="footer">نظام فاتورايز المحاسبي — ${BRANCH.name}</div>
<script>window.onload=()=>window.print()<\/script>
</body></html>`;
        const w=window.open('','_blank','width=900,height=700');
        w.document.write(html);
        w.document.close();
}

function doConfirm(){
    const paid=parseFloat(document.getElementById('cPaidAmt').value)||0;
    const cashAcc=document.getElementById('cCashAccount').value;
    if(paid>0&&!cashAcc){toast('اختر حساب الدفع','danger');return;}
    const shipOn=document.querySelector('input[name="shippingOn"]:checked')?.value||'us';
    const ship=shipOn==='us'?(parseFloat(document.getElementById('cShipping').value)||0):0;
    document.getElementById('confirmTxt').style.opacity='0';
    document.getElementById('confirmSpin').style.display='inline-block';
    document.getElementById('btnConfirm').disabled=true;
    const fd=new FormData();
    fd.append('_action','confirm');
    fd.append('invoice_id',_cId);
    fd.append('receive_date',document.getElementById('cReceiveDate').value);
    fd.append('warehouse_id',document.getElementById('cWarehouse').value);
    fd.append('shipping_cost',ship);
    fd.append('shipping_on',shipOn);
    fd.append('shipping_currency',document.getElementById('cShippingCur').value);
    fd.append('shipping_carrier_id',document.getElementById('cShippingCarrier').value);
    fd.append('shipping_payable_id',document.getElementById('cShippingPayableId').value);
    fd.append('shipping_pay_method',document.querySelector('input[name="shippingPay"]:checked')?.value||'cash');
    fd.append('shipping_cash_account',document.getElementById('cShipCashAccount').value);
    fd.append('shipping_desc',document.getElementById('cShippingDesc').value);
    fd.append('paid_amount',document.getElementById('cPaidAmt').value||'0');
    fd.append('paid_currency',document.getElementById('cPaidCur').value);
    fd.append('paid_rate',document.getElementById('cPaidRate').value||'1');
    fd.append('cash_account_id',cashAcc);
    fd.append('notes',document.getElementById('cNotes').value);
    const img=document.getElementById('cInvoiceImg');
    if(img.files&&img.files[0])fd.append('invoice_image',img.files[0]);
    fetch('../../api/confirm_purchase_invoice.php',{method:'POST',body:fd})
    .then(r=>r.json()).then(d=>{
        document.getElementById('confirmTxt').style.opacity='1';
        document.getElementById('confirmSpin').style.display='none';
        document.getElementById('btnConfirm').disabled=false;
        if(d.ok){toast('✅ '+d.msg);confirmModal.hide();setTimeout(()=>location.reload(),800);}
        else toast(d.msg,'danger');
    }).catch(()=>{
        document.getElementById('confirmTxt').style.opacity='1';
        document.getElementById('confirmSpin').style.display='none';
        document.getElementById('btnConfirm').disabled=false;
        toast('خطأ في الاتصال','danger');
    });
}

function confirmInvoice(id,no){
    openConfirmModal(id,no,0,'$');
}
function cancelInvoice(id,no){
    if(!confirm(`إلغاء الفاتورة "${no}"؟\nالفواتير المستلمة سيتم عكس مخزونها وقيودها.`))return;
    const fd=new FormData();
    fd.append('_action','cancel');
    fd.append('invoice_id',id);
    fetch('../../api/confirm_purchase_invoice.php',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(d=>{
        if(d.ok){toast(d.msg);setTimeout(()=>location.reload(),700);}
        else toast(d.msg,'danger');
    });
}
</script>
</body>
</html>
