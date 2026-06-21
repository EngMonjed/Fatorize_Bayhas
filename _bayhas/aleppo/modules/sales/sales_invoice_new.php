<?php
/**
 * sales/invoice_new.php — فاتورة بيع جديدة
 * المسار: /bayhas/aleppo/modules/sales/invoice_new.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('sales.invoices', 'create');
$currentModule = 'sales.invoices';

$TS    = $_SESSION['table_suffix'];
$TI    = "sales_invoices_{$TS}";
$TII   = "sales_invoice_items_{$TS}";
$TC    = "customers_{$TS}";
$TW    = "warehouses_{$TS}";
$TWI   = "warehouse_items_{$TS}";
$TV    = "product_variants_{$TS}";
$TSZ   = "product_sizes_{$TS}";
$TPROD = "products_{$TS}";
$TCL   = "product_colors_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// ── توليد رقم الفاتورة ──
function genInvoiceNo(PDO $pdo, string $table): string {
    $y    = date('Y');
    $last = $pdo->query("SELECT invoice_number FROM `{$table}`
        WHERE invoice_number LIKE 'INV-{$y}-%'
        ORDER BY id DESC LIMIT 1")->fetchColumn();
    $seq  = $last ? (int)substr($last, -5) + 1 : 1;
    return "INV-{$y}-" . str_pad($seq, 5, '0', STR_PAD_LEFT);
}

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        // ── بحث منتج ──
        if ($act === 'search_product') {
            $q = trim($_POST['q'] ?? '');
            if (!$q) throw new Exception('أدخل باركود أو اسم منتج');

            // بحث بالباركود
            $st = $pdo->prepare("
                SELECT v.id AS variant_id, v.barcode, v.color_id,
                    p.id AS product_id, p.name AS product_name, p.model_number,
                    s.id AS size_id, s.size, s.selling_price, s.cost_price, s.age_type,
                    c.name AS color_name, c.hex_code AS color_hex
                FROM `{$TV}` v
                JOIN `{$TPROD}` p ON p.id=v.product_id
                JOIN `{$TSZ}` s   ON s.id=v.size_id
                LEFT JOIN `{$TCL}` c ON c.id=v.color_id
                WHERE v.barcode=? AND v.is_active=1 AND p.is_active=1
                LIMIT 1");
            $st->execute([$q]);
            $found = $st->fetch();
            if ($found) {
                echo json_encode(['ok'=>true,'type'=>'barcode','data'=>$found]);
            } else {
                $st2 = $pdo->prepare("
                    SELECT v.id AS variant_id, v.barcode, v.color_id,
                        p.id AS product_id, p.name AS product_name, p.model_number,
                        s.id AS size_id, s.size, s.selling_price, s.cost_price, s.age_type,
                        c.name AS color_name, c.hex_code AS color_hex
                    FROM `{$TV}` v
                    JOIN `{$TPROD}` p ON p.id=v.product_id
                    JOIN `{$TSZ}` s   ON s.id=v.size_id
                    LEFT JOIN `{$TCL}` c ON c.id=v.color_id
                    WHERE (p.name LIKE ? OR p.model_number LIKE ?)
                        AND v.is_active=1 AND p.is_active=1
                    ORDER BY p.name, s.selling_price, s.age_type, s.sort_order
                    LIMIT 200");
                $st2->execute(["%{$q}%", "%{$q}%"]);
                echo json_encode(['ok'=>true,'type'=>'search','data'=>$st2->fetchAll()]);
            }
        }

        // ── حفظ الفاتورة ──
        elseif ($act === 'save_invoice') {
            $customerId  = (int)($_POST['customer_id'] ?? 0);
            $whId        = (int)($_POST['warehouse_id'] ?? 0);
            $invDate     = $_POST['invoice_date'] ?? date('Y-m-d');
            $dueDate     = $_POST['due_date'] ?? null ?: null;
            $notes       = trim($_POST['notes'] ?? '');
            $discPct     = (float)($_POST['discount_pct'] ?? 0);
            $taxPct      = (float)($_POST['tax_pct'] ?? 0);
            $rows        = json_decode($_POST['rows'] ?? '[]', true);

            if (!$customerId) throw new Exception('يجب اختيار العميل');
            if (!$whId)       throw new Exception('يجب اختيار المستودع');
            if (empty($rows)) throw new Exception('يجب إضافة منتج واحد على الأقل');

            // جلب اسم العميل
            $cSt = $pdo->prepare("SELECT name, type FROM `{$TC}` WHERE id=?");
            $cSt->execute([$customerId]);
            $cust = $cSt->fetch();

            // حساب الإجماليات
            $subtotal = 0;
            foreach ($rows as $r) {
                $subtotal += (float)$r['qty'] * (float)$r['unit_price'] * (1 - (float)($r['discount_pct']??0)/100);
            }
            $discAmt  = $subtotal * $discPct / 100;
            $taxAmt   = ($subtotal - $discAmt) * $taxPct / 100;
            $total    = $subtotal - $discAmt + $taxAmt;

            // التحقق من الفرادة
            $invNo = trim($_POST['invoice_no'] ?? '');
            if (!$invNo) $invNo = genInvoiceNo($pdo, $TI);
            $chk = $pdo->prepare("SELECT COUNT(*) FROM `{$TI}` WHERE invoice_number=?");
            $chk->execute([$invNo]);
            if ($chk->fetchColumn() > 0) $invNo = genInvoiceNo($pdo, $TI);

            $pdo->prepare("INSERT INTO `{$TI}`
                (invoice_number, customer_id, customer_name, customer_type,
                 invoice_date, due_date,
                 subtotal, discount_percentage, discount_amount,
                 kdv_rate, kdv_amount, total_amount,
                 paid_amount, balance_amount,
                 payment_status, status, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,?,  'pending','draft',?,?)")
                ->execute([
                    $invNo, $customerId, $cust['name']??'', $cust['type']??'external',
                    $invDate, $dueDate,
                    $subtotal, $discPct, $discAmt,
                    $taxPct, $taxAmt, $total,
                    $total, // balance = total
                    $notes, $_SESSION['user_id']
                ]);
            $invId = (int)$pdo->lastInsertId();

            // حفظ البنود — بدون تأثير على المخزون حتى التأكيد
            foreach ($rows as $r) {
                $qty      = (float)$r['qty'];
                $unitPr   = (float)$r['unit_price'];
                $disc     = (float)($r['discount_pct']??0);
                $lineTot  = $qty * $unitPr * (1 - $disc/100);
                $variantIds = $r['variant_ids'] ?? [$r['variant_id']??0];

                foreach ($variantIds as $variantId) {
                    $variantId = (int)$variantId;
                    if (!$variantId) continue;
                    $vSt = $pdo->prepare("SELECT v.*, s.size, s.age_type, s.cost_price,
                        c.name AS color_name
                        FROM `{$TV}` v
                        JOIN `{$TSZ}` s ON s.id=v.size_id
                        LEFT JOIN `{$TCL}` c ON c.id=v.color_id
                        WHERE v.id=?");
                    $vSt->execute([$variantId]);
                    $vRow = $vSt->fetch();
                    if (!$vRow) continue;

                    $pdo->prepare("INSERT INTO `{$TII}`
                        (invoice_id, product_id, variant_id, item_name, model_number,
                         size, color, barcode, quantity, unit_price, cost_price_usd,
                         total_price, warehouse_id)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([
                            $invId, (int)$r['product_id'], $variantId,
                            $r['product_name'], $r['model_number']??'',
                            $vRow['size']??'', $vRow['color_name']??'',
                            $vRow['barcode']??'', $qty, $unitPr,
                            $vRow['cost_price']??0,
                            $lineTot, $whId
                        ]);
                }
            }
            echo json_encode(['ok'=>true,'id'=>$invId,'no'=>$invNo,'msg'=>'تم حفظ الفاتورة كمسودة']);
        }

        // ── إضافة عميل جديد ──
        elseif ($act === 'add_customer') {
            $name = trim($_POST['name'] ?? '');
            $phone= trim($_POST['phone'] ?? '');
            $type = $_POST['type'] ?? 'individual';
            if (!$name) throw new Exception('اسم العميل مطلوب');
            $pdo->prepare("INSERT INTO `{$TC}` (name,phone,type,status) VALUES (?,?,?,'active')")
                ->execute([$name,$phone,$type]);
            echo json_encode(['ok'=>true,'id'=>(int)$pdo->lastInsertId(),'name'=>$name,'phone'=>$phone]);
        }

        else throw new Exception('إجراء غير معروف');
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

// ── بيانات الصفحة ──
$customers  = $pdo->query("SELECT id,name,phone FROM `{$TC}`
    WHERE status='active' ORDER BY name")->fetchAll();
$warehouses = $pdo->query("SELECT * FROM `{$TW}` WHERE is_active=1 ORDER BY id")->fetchAll();
$invNo      = genInvoiceNo($pdo, $TI);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>فاتورة بيع جديدة — <?= htmlspecialchars($branchName) ?></title>
<link rel="icon" href="/bayhas/assets/images/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="/bayhas/assets/css/layout.css" rel="stylesheet">
<style>
.page-grid{display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start}
@media(max-width:900px){.page-grid{grid-template-columns:1fr}}
.card-sec{background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:12px}
.card-sec-hdr{padding:10px 16px;border-bottom:1px solid #f1f5f9;font-size:.83rem;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:8px}
.card-sec-body{padding:14px 16px}
.field-lbl{font-size:.75rem;font-weight:700;color:#475569;margin-bottom:3px;display:block}
.field-hint{font-size:.7rem;color:#94a3b8;margin-top:2px}
.req{color:#dc2626}
.scan-wrap{display:flex;gap:8px;margin-bottom:10px}
.scan-input{flex:1;border:2px solid #1e3a8a;border-radius:10px;padding:8px 12px;font-size:.85rem;font-family:inherit}
.scan-input:focus{outline:none;border-color:#0891b2;box-shadow:0 0 0 3px rgba(8,145,178,.1)}
.scan-btn{border-radius:10px;border:none;background:#1e3a8a;color:#fff;padding:8px 14px;cursor:pointer;font-size:.85rem;display:flex;align-items:center;gap:6px}
.lines-table{width:100%;border-collapse:collapse;font-size:.8rem}
.lines-table th{background:#f8fafc;padding:7px 10px;font-weight:600;color:#64748b;font-size:.72rem;border-bottom:1px solid #f1f5f9;white-space:nowrap}
.lines-table td{padding:6px 8px;border-bottom:1px solid #f8fafc;vertical-align:middle}
.lines-table input{width:100%;padding:4px 6px;border:1px solid #e2e8f0;border-radius:6px;font-size:.78rem;color:#1e293b}
.lines-table input:focus{outline:none;border-color:#1e3a8a}
.lines-table input.calc{background:#f0fdf4;color:#16a34a;border-color:#bbf7d0;font-weight:600}
.del-btn{width:24px;height:24px;border-radius:6px;border:1px solid #fca5a5;background:#fff;color:#dc2626;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.75rem}
.clr-dot{width:12px;height:12px;border-radius:50%;border:1px solid rgba(0,0,0,.12);display:inline-block;flex-shrink:0}
.tot-box{background:#f8fafc;border-radius:10px;padding:12px 14px}
.tot-row{display:flex;justify-content:space-between;font-size:.8rem;padding:3px 0}
.tot-row.final{font-weight:700;font-size:.95rem;border-top:1px solid #e2e8f0;padding-top:8px;margin-top:4px;color:#1e293b}
.n{font-variant-numeric:tabular-nums}
/* select modal */
.sel-table{width:100%;border-collapse:collapse;font-size:.82rem}
.sel-table th{background:#f8fafc;padding:8px 12px;font-weight:600;color:#64748b;font-size:.72rem;border-bottom:1px solid #f1f5f9}
.sel-table td{padding:7px 10px;border-bottom:1px solid #f8fafc;vertical-align:middle}
.sel-table tr:hover td{background:#f8faff}
.sel-table tr.checked td{background:#eff6ff}
.sel-table input[type=checkbox]{width:16px;height:16px;cursor:pointer;accent-color:#1e3a8a}
.grp-badge{display:inline-flex;align-items:center;border-radius:20px;font-size:.68rem;padding:2px 8px;font-weight:600;border:1px solid}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
.scanning{animation:pulse .8s infinite;color:#16a34a}
</style>
</head>
<body>
<div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
<?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>

<header class="topbar">
    <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
    <span class="tb-title"><i class="bi bi-plus-circle me-1 text-success"></i>فاتورة بيع جديدة</span>
    <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
    <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
        <a href="invoices.php" style="color:#64748b;text-decoration:none">فواتير البيع</a>
        <i class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
        <span class="text-success">فاتورة جديدة</span>
    </nav>
</header>

<main class="main-content"><div class="content-body">
<div class="page-grid">

<!-- ── العمود الرئيسي ── -->
<div>

<!-- بيانات الفاتورة -->
<div class="card-sec">
    <div class="card-sec-hdr"><i class="bi bi-receipt text-success"></i>بيانات الفاتورة</div>
    <div class="card-sec-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="field-lbl">العميل <span class="req">*</span></label>
                <div class="d-flex gap-1">
                    <select id="iCustomer" class="form-select form-select-sm" style="flex:1" onchange="onCustomerChange()">
                        <option value="">— اختر العميل —</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?=$c['id']?>" data-phone="<?= htmlspecialchars($c['phone']??'') ?>">
                            <?= htmlspecialchars($c['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm" style="border-radius:7px;border:1px solid #16a34a;color:#16a34a;padding:4px 8px"
                            onclick="openAddCustomer()" title="عميل جديد"><i class="bi bi-plus"></i></button>
                </div>
                <div id="custPhone" style="display:none;font-size:.7rem;color:#64748b;margin-top:3px">
                    <i class="bi bi-telephone me-1"></i><span id="custPhoneTxt"></span>
                </div>
            </div>
            <div class="col-md-4">
                <label class="field-lbl">المستودع <span class="req">*</span></label>
                <select id="iWarehouse" class="form-select form-select-sm">
                    <option value="">— اختر المستودع —</option>
                    <?php foreach ($warehouses as $wh): ?>
                    <option value="<?=$wh['id']?>" <?= $wh['id']==1?'selected':'' ?>>
                        <?= htmlspecialchars($wh['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="field-lbl">رقم الفاتورة</label>
                <input type="text" id="iInvNo" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($invNo) ?>" readonly dir="ltr"
                       style="background:#f8fafc;font-weight:700;color:#16a34a;letter-spacing:1px">
                <div class="field-hint">يُولَّد تلقائياً • 5 أرقام</div>
            </div>
            <div class="col-md-3">
                <label class="field-lbl">تاريخ الفاتورة <span class="req">*</span></label>
                <input type="date" id="iDate" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"
                       onchange="document.getElementById('iDueDate').value=this.value">
            </div>
            <div class="col-md-3">
                <label class="field-lbl">تاريخ الاستحقاق</label>
                <input type="date" id="iDueDate" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-6">
                <label class="field-lbl">ملاحظات</label>
                <input type="text" id="iNotes" class="form-control form-control-sm" placeholder="اختياري">
            </div>
        </div>
    </div>
</div>

<!-- إضافة المنتجات -->
<div class="card-sec">
    <div class="card-sec-hdr">
        <i class="bi bi-barcode-scan text-success"></i>إضافة المنتجات
        <span style="margin-right:auto;font-size:.72rem;color:#64748b;font-weight:400">امسح الباركود أو ابحث</span>
        <button class="btn btn-sm" onclick="refreshProducts()" id="btnRefresh"
            style="border-radius:8px;border:1px solid #0891b2;color:#0891b2;font-size:.75rem">
            <i class="bi bi-arrow-clockwise me-1"></i>تحديث
        </button>
        <a href="../inventory/product_add.php" target="_blank" class="btn btn-sm"
            style="border-radius:8px;border:1px solid #16a34a;color:#16a34a;font-size:.75rem;text-decoration:none">
            <i class="bi bi-plus-circle me-1"></i>منتج جديد
        </a>
        <button class="btn btn-sm" onclick="toggleCamera()" id="btnCamera"
            style="border-radius:8px;border:1px solid #1e3a8a;color:#1e3a8a;font-size:.75rem">
            <i class="bi bi-camera me-1"></i>كاميرا
        </button>
    </div>
    <div class="card-sec-body">
        <div id="cameraWrap" style="display:none;margin-bottom:12px">
            <div style="background:#000;border-radius:10px;overflow:hidden;position:relative;max-width:400px">
                <video id="camVideo" style="width:100%;display:block" autoplay playsinline></video>
                <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:60%;height:40%;border:2px solid #22c55e;border-radius:8px;pointer-events:none"></div>
                <canvas id="camCanvas" style="display:none"></canvas>
            </div>
            <div id="camStatus" style="font-size:.75rem;color:#16a34a;margin-top:6px">
                <i class="bi bi-circle-fill scanning me-1"></i>جارٍ المسح...
            </div>
        </div>
        <div class="scan-wrap">
            <input type="text" id="scanInput" class="scan-input" dir="ltr"
                   placeholder="امسح الباركود أو اكتب اسم المنتج / الموديل..." autocomplete="off">
            <button class="scan-btn" onclick="doSearch()"><i class="bi bi-search"></i>بحث</button>
        </div>
        <div id="searchResults" style="display:none"></div>
    </div>
</div>

<!-- بنود الفاتورة -->
<div class="card-sec">
    <div class="card-sec-hdr">
        <i class="bi bi-list-ul text-success"></i>بنود الفاتورة
        <span id="linesCount" style="font-size:.72rem;color:#64748b;font-weight:400;margin-right:4px"></span>
    </div>
    <div class="card-sec-body" style="padding:0">
        <div class="table-responsive">
        <table class="lines-table">
            <thead><tr>
                <th>#</th>
                <th>المنتج / الموديل</th>
                <th>الكروب / القياسات</th>
                <th>اللون</th>
                <th>الكمية</th>
                <th>سعر الوحدة ($)</th>
                <th>خصم %</th>
                <th>الإجمالي ($)</th>
                <th></th>
            </tr></thead>
            <tbody id="linesBody">
            <tr id="emptyRow">
                <td colspan="9" class="text-center text-muted py-4" style="font-size:.8rem">
                    <i class="bi bi-barcode d-block mb-2" style="font-size:1.5rem;opacity:.3"></i>
                    امسح باركود أو ابحث عن منتج
                </td>
            </tr>
            </tbody>
        </table>
        </div>
    </div>
</div>

</div><!-- end main col -->

<!-- ── الشريط الجانبي الأيمن ── -->
<div>
<div class="card-sec" style="position:sticky;top:80px">
    <div class="card-sec-hdr"><i class="bi bi-calculator text-success"></i>ملخص الفاتورة</div>
    <div class="card-sec-body">
        <div style="font-size:.72rem;color:#64748b;margin-bottom:8px;padding:4px 8px;background:#f0fdf4;border-radius:6px">
            <i class="bi bi-info-circle me-1 text-success"></i>جميع المبالغ بالدولار الأمريكي $
        </div>
        <div class="tot-box mb-3">
            <div class="tot-row"><span style="color:#64748b">عدد البنود</span><span id="sumLines">0</span></div>
            <div class="tot-row"><span style="color:#64748b">إجمالي الكمية</span><span id="sumQty" class="n">0</span></div>
            <div class="tot-row"><span style="color:#64748b">المجموع</span><span id="sumSubtotal" class="n">$ 0.00</span></div>
            <div class="tot-row">
                <span style="color:#64748b">
                    خصم %
                    <input type="number" id="discPct" min="0" max="100" value="0" step="0.01"
                           style="width:46px;padding:1px 4px;font-size:.73rem;border:1px solid #e2e8f0;border-radius:5px;text-align:center;display:inline-block;margin-right:4px"
                           oninput="calcTotals()">
                </span>
                <span id="sumDisc" class="n text-danger">- $ 0.00</span>
            </div>
            <div class="tot-row">
                <span style="color:#64748b">
                    ضريبة %
                    <input type="number" id="taxPct" min="0" max="100" value="0" step="0.01"
                           style="width:46px;padding:1px 4px;font-size:.73rem;border:1px solid #e2e8f0;border-radius:5px;text-align:center;display:inline-block;margin-right:4px"
                           oninput="calcTotals()">
                </span>
                <span id="sumTax" class="n">+ $ 0.00</span>
            </div>
            <div class="tot-row final">
                <span>الإجمالي</span>
                <span id="sumTotal" class="n">$ 0.00</span>
            </div>
        </div>
        <div class="d-grid gap-2">
            <button class="btn btn-sm fw-600"
                    style="border-radius:9px;background:#16a34a;color:#fff;padding:8px"
                    onclick="saveInvoice()">
                <i class="bi bi-floppy me-1"></i>حفظ الفاتورة
            </button>
            <a href="invoices.php" class="btn btn-sm"
               style="border-radius:9px;border:1px solid #e2e8f0;color:#64748b;padding:8px;text-decoration:none;text-align:center">
                <i class="bi bi-x-lg me-1"></i>إلغاء
            </a>
        </div>
    </div>
</div>
</div>

</div><!-- end page-grid -->
</div></main>

<!-- مودال اختيار المنتجات -->
<div class="modal fade" id="selectModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#065f46,#16a34a);border-radius:16px 16px 0 0">
        <div>
          <h6 class="modal-title text-white fw-700 mb-0" id="selModalTitle">اختيار المنتجات</h6>
          <div style="font-size:.75rem;color:rgba(255,255,255,.7);margin-top:2px">حدد + كمية ثم اضغط إضافة</div>
        </div>
        <div class="d-flex gap-2 align-items-center">
          <span id="selCount" style="font-size:.78rem;color:rgba(255,255,255,.8);font-weight:600"></span>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
      </div>
      <div class="modal-body p-0" id="selModalBody"></div>
      <div class="modal-footer border-0 px-4 pb-4">
        <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
        <button class="btn btn-sm fw-600" style="border-radius:8px;background:#16a34a;color:#fff;min-width:140px"
                onclick="confirmSelection()">
          <i class="bi bi-plus-circle me-1"></i>إضافة المحدد للفاتورة
        </button>
      </div>
    </div>
  </div>
</div>

<!-- مودال عميل جديد -->
<div class="modal fade" id="custModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header py-3 px-4 border-0"
           style="background:linear-gradient(135deg,#065f46,#16a34a);border-radius:16px 16px 0 0">
        <h6 class="modal-title text-white fw-700 mb-0"><i class="bi bi-person-plus me-2"></i>إضافة عميل جديد</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 pt-3">
        <div class="row g-3">
          <div class="col-md-8">
            <label class="field-lbl">اسم العميل <span class="req">*</span></label>
            <input type="text" id="cName" class="form-control form-control-sm" placeholder="الاسم الكامل">
          </div>
          <div class="col-md-4">
            <label class="field-lbl">النوع</label>
            <select id="cType" class="form-select form-select-sm">
                <option value="individual">فرد</option>
                <option value="company">شركة</option>
            </select>
          </div>
          <div class="col-12">
            <label class="field-lbl">الهاتف</label>
            <input type="text" id="cPhone" class="form-control form-control-sm" dir="ltr" placeholder="+963...">
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 px-4 pb-4">
        <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
        <button class="btn btn-sm fw-600" style="border-radius:8px;background:#16a34a;color:#fff" onclick="saveCustomer()">
          <i class="bi bi-plus me-1"></i>إضافة
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Sidebar ──
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

var _camStream=null, _camInterval=null, _selModal=null;
var lines=[];

const selectModal = new bootstrap.Modal(document.getElementById('selectModal'));
const custModal   = new bootstrap.Modal(document.getElementById('custModal'));

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

// ── العميل ──
function onCustomerChange(){
    const sel=document.getElementById('iCustomer');
    const opt=sel.options[sel.selectedIndex];
    const phone=opt.dataset.phone||'';
    const wrap=document.getElementById('custPhone');
    if(sel.value&&phone){document.getElementById('custPhoneTxt').textContent=phone;wrap.style.display='block';}
    else wrap.style.display='none';
}
function openAddCustomer(){
    document.getElementById('cName').value='';
    document.getElementById('cPhone').value='';
    document.getElementById('cType').value='individual';
    custModal.show();
}
function saveCustomer(){
    const name=document.getElementById('cName').value.trim();
    if(!name){toast('اسم العميل مطلوب','danger');return;}
    post({_action:'add_customer',name,phone:document.getElementById('cPhone').value,type:document.getElementById('cType').value})
    .then(d=>{
        if(!d.ok){toast(d.msg,'danger');return;}
        const sel=document.getElementById('iCustomer');
        const opt=document.createElement('option');
        opt.value=d.id;opt.dataset.phone=d.phone||'';opt.textContent=d.name;opt.selected=true;
        sel.appendChild(opt);onCustomerChange();custModal.hide();toast('تمت إضافة العميل');
    });
}

// ── بحث المنتج ──
let _searchTimer=null;
document.getElementById('scanInput').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();doSearch();}});
document.getElementById('scanInput').addEventListener('input',e=>{
    clearTimeout(_searchTimer);
    if(e.target.value.trim().length>=3) _searchTimer=setTimeout(doSearch,400);
    else document.getElementById('searchResults').style.display='none';
});
function doSearch(){
    const q=document.getElementById('scanInput').value.trim();
    if(!q)return;
    post({_action:'search_product',q}).then(d=>{
        if(!d.ok){toast(d.msg,'danger');return;}
        if(d.type==='barcode'){addLine(d.data);document.getElementById('scanInput').value='';document.getElementById('searchResults').style.display='none';}
        else showSelectModal(d.data);
    });
}

// ── مودال الاختيار ──
var _selItems=[];
var _selRows=[];
const GRP_COLORS=[['#eff6ff','#1e3a8a','#bfdbfe'],['#f0fdf4','#065f46','#bbf7d0'],['#fff7ed','#7c2d12','#fed7aa'],['#f5f3ff','#4c1d95','#ddd6fe']];

function showSelectModal(items){
    if(!items.length){toast('لا توجد نتائج','danger');return;}
    _selItems=items;
    const rows={};
    items.forEach(it=>{
        const key=`${it.product_id}_${it.selling_price}_${it.age_type||'سنة'}_${it.color_id||0}`;
        if(!rows[key]) rows[key]={key,product_id:it.product_id,product_name:it.product_name,
            model_number:it.model_number,selling_price:it.selling_price,age_type:it.age_type||'سنة',
            color_id:it.color_id||0,color_name:it.color_name||'',color_hex:it.color_hex||'',
            variants:[],sizes:[],cost_price:it.cost_price};
        rows[key].variants.push(it);
        if(!rows[key].sizes.includes(it.size)) rows[key].sizes.push(it.size);
    });
    _selRows=Object.values(rows).sort((a,b)=>{
        if(a.product_id!==b.product_id) return a.product_id-b.product_id;
        if(a.selling_price!==b.selling_price) return parseFloat(a.selling_price)-parseFloat(b.selling_price);
        if((a.age_type||'')!==(b.age_type||'')) return (a.age_type||'').localeCompare(b.age_type||'');
        return (a.color_name||'').localeCompare(b.color_name||'');
    });
    const prodGrp={};
    _selRows.forEach(r=>{
        if(!prodGrp[r.product_id]) prodGrp[r.product_id]={};
        const gk=`${r.selling_price}_${r.age_type||'سنة'}`;
        if(prodGrp[r.product_id][gk]===undefined) prodGrp[r.product_id][gk]=Object.keys(prodGrp[r.product_id]).length;
    });

    let html='<div class="table-responsive"><table class="sel-table"><thead><tr>'
        +'<th style="width:36px"><input type="checkbox" id="chkAll" onchange="toggleAllSel(this)"></th>'
        +'<th>الموديل</th><th>المنتج</th><th>الكروب</th><th>القياسات</th><th>اللون</th>'
        +'<th>سعر البيع ($)</th><th>الكمية</th></tr></thead><tbody>';

    _selRows.forEach(r=>{
        const grpIdx=prodGrp[r.product_id][`${r.selling_price}_${r.age_type||'سنة'}`]||0;
        const [bg,clr,br]=GRP_COLORS[grpIdx%4];
        const grpBadge=`<span class="grp-badge" style="background:${bg};color:${clr};border-color:${br}">كروب ${grpIdx+1}</span>`;
        const colorDot=r.color_hex?`<span class="clr-dot" style="background:${r.color_hex};margin-left:4px"></span>`:'';
        const sizesStr=r.sizes.join(' · ')+` <span style="font-size:.68rem;color:#94a3b8">${r.age_type||''}</span>`;
        html+=`<tr id="selrow_${r.key.replace(/[^a-z0-9]/gi,'_')}" onclick="toggleSelRow(this,'${r.key}')">
            <td><input type="checkbox" class="sel-chk" data-key="${r.key}" onchange="onSelChk(this)" onclick="event.stopPropagation()"></td>
            <td dir="ltr" style="font-size:.75rem;color:#64748b">${r.model_number||'—'}</td>
            <td><div class="fw-600" style="font-size:.8rem">${r.product_name}</div></td>
            <td>${grpBadge}</td>
            <td style="font-size:.8rem;font-weight:600">${sizesStr}</td>
            <td><div class="d-flex align-items-center">${colorDot}<span style="font-size:.78rem">${r.color_name||'—'}</span></div></td>
            <td style="width:110px">
                <input type="number" class="sel-price" data-key="${r.key}" min="0" step="0.0001" dir="ltr"
                    value="${parseFloat(r.selling_price||0).toFixed(4)}"
                    style="width:100%;padding:3px 5px;border:1px solid #e2e8f0;border-radius:6px;font-size:.78rem"
                    onclick="event.stopPropagation()">
            </td>
            <td style="width:70px">
                <input type="number" class="sel-qty" data-key="${r.key}" min="1" step="1" value="1" dir="ltr"
                    style="width:100%;padding:3px 5px;border:1px solid #e2e8f0;border-radius:6px;font-size:.78rem"
                    onclick="event.stopPropagation()">
            </td>
        </tr>`;
    });
    html+='</tbody></table></div>';

    const names=[...new Set(items.map(i=>i.product_name))];
    document.getElementById('selModalTitle').textContent=names.length===1?names[0]:`${names.length} منتجات`;
    document.getElementById('selModalBody').innerHTML=html;
    document.getElementById('selCount').textContent='';
    selectModal.show();
}
function toggleSelRow(tr,key){const chk=tr.querySelector('.sel-chk');chk.checked=!chk.checked;tr.classList.toggle('checked',chk.checked);updateSelCount();}
function onSelChk(chk){chk.closest('tr').classList.toggle('checked',chk.checked);updateSelCount();}
function toggleAllSel(master){document.querySelectorAll('.sel-chk').forEach(c=>{c.checked=master.checked;c.closest('tr').classList.toggle('checked',master.checked);});updateSelCount();}
function updateSelCount(){const n=document.querySelectorAll('.sel-chk:checked').length;document.getElementById('selCount').textContent=n?`(${n} محدد)`:'';};

function confirmSelection(){
    const checked=document.querySelectorAll('.sel-chk:checked');
    if(!checked.length){toast('اختر منتجاً واحداً على الأقل','danger');return;}
    let added=0;
    checked.forEach(chk=>{
        const key=chk.dataset.key;
        const rowDef=_selRows.find(r=>r.key===key);
        if(!rowDef)return;
        const prEl=document.querySelector(`.sel-price[data-key="${key}"]`);
        const qtyEl=document.querySelector(`.sel-qty[data-key="${key}"]`);
        const pr=parseFloat(prEl?.value||0);
        const qty=parseInt(qtyEl?.value||1);
        rowDef.variants.forEach((v,vi)=>{
            const item={...v,cost_price:v.cost_price,selling_price:rowDef.selling_price};
            if(vi===0) addLine({...item,unit_price:pr});
            else mergeVariant({...item,unit_price:pr});
            added++;
        });
        if(qty>1){
            const gk=makeGrpKey({...rowDef.variants[0],selling_price:rowDef.selling_price});
            const line=lines.find(l=>l.grp_key===gk);
            if(line){line.qty=qty;const row=document.getElementById(line.row_id);if(row){row.querySelector('.q-input').value=qty;recalcLine(line,row);}}
        }
    });
    selectModal.hide();
    document.getElementById('scanInput').value='';
    calcTotals();
    toast(`تمت إضافة ${added} متغير للفاتورة`);
}

// ── إدارة البنود ──
function makeGrpKey(item){return `${item.product_id}_${item.selling_price||item.unit_price||0}_${item.age_type||'سنة'}_${item.color_id||0}`;}

function addLine(item){
    const gk=makeGrpKey(item);
    const exist=lines.find(l=>l.grp_key===gk);
    if(exist){exist.qty++;const row=document.getElementById(exist.row_id);if(row){row.querySelector('.q-input').value=exist.qty;recalcLine(exist,row);}calcTotals();toast('تمت زيادة الكمية');return;}

    const idx=lines.length;
    const line={
        grp_key:gk, row_id:'lgrp_'+gk.replace(/[^a-z0-9]/gi,'_'),
        variants:[item], product_id:item.product_id, product_name:item.product_name,
        model_number:item.model_number||'', selling_price:parseFloat(item.selling_price||0),
        age_type:item.age_type||'سنة', color_id:item.color_id||0,
        color_name:item.color_name||'', color_hex:item.color_hex||'',
        sizes:[item.size||''], qty:1,
        unit_price:parseFloat(item.unit_price||item.selling_price||0),
        discount_pct:0, total:0,
    };
    lines.push(line);
    document.getElementById('emptyRow').style.display='none';

    const pricesForProd=[...new Set(lines.filter(l=>l.product_id===item.product_id&&(l.age_type||'سنة')===(line.age_type||'سنة')).map(l=>l.selling_price))];
    const grpIdx=pricesForProd.indexOf(line.selling_price);
    const [bg,clr,br]=GRP_COLORS[grpIdx%4];
    const grpBadge=`<span style="background:${bg};color:${clr};border:1px solid ${br};border-radius:12px;font-size:.68rem;padding:2px 8px;font-weight:600">كروب ${grpIdx+1}</span>`;
    const colorDot=line.color_hex?`<span class="clr-dot" style="background:${line.color_hex}"></span>`:'';

    const tbody=document.getElementById('linesBody');
    const tr=document.createElement('tr');
    tr.id=line.row_id;
    tr.innerHTML=`
        <td class="text-muted" style="font-size:.75rem">${idx+1}</td>
        <td><div class="fw-600" style="font-size:.8rem">${line.product_name}</div>
            <div class="text-muted" style="font-size:.7rem" dir="ltr">${line.model_number}</div></td>
        <td>${grpBadge}
            <div class="sizes-lbl mt-1" style="font-size:.72rem;color:#334155;font-weight:600">${line.sizes.join(' · ')} ${line.age_type}</div>
        </td>
        <td><div class="d-flex align-items-center gap-1">${colorDot}<span style="font-size:.78rem">${line.color_name||'—'}</span></div></td>
        <td style="width:70px"><input type="number" class="q-input" min="1" step="1" value="1" dir="ltr"
            onchange="updateLine('${gk}','qty',this.value)"></td>
        <td style="width:100px"><input type="number" class="p-input" min="0" step="0.0001"
            value="${line.unit_price>0?line.unit_price:''}" dir="ltr" placeholder="0.0000"
            onchange="updateLine('${gk}','unit_price',this.value)"></td>
        <td style="width:65px"><input type="number" class="d-input" min="0" max="100" step="0.01"
            value="0" dir="ltr" onchange="updateLine('${gk}','discount_pct',this.value)"></td>
        <td style="width:90px"><input type="number" class="t-input calc" readonly dir="ltr" placeholder="0.00"></td>
        <td><button class="del-btn" onclick="removeLine('${gk}')"><i class="bi bi-x-lg"></i></button></td>`;
    tbody.appendChild(tr);
    recalcLine(line,tr);calcTotals();updateLinesCount();
    if(!line.unit_price) tr.querySelector('.p-input').focus();
}

function mergeVariant(item){
    const gk=makeGrpKey(item);
    const line=lines.find(l=>l.grp_key===gk);
    if(!line)return addLine(item);
    if(!line.variants.find(v=>v.variant_id===item.variant_id)){
        line.variants.push(item);
        if(!line.sizes.includes(item.size)) line.sizes.push(item.size);
        const row=document.getElementById(line.row_id);
        if(row){const sl=row.querySelector('.sizes-lbl');if(sl)sl.textContent=line.sizes.join(' · ')+' '+line.age_type;}
    }
}
function updateLine(gk,field,val){
    const line=lines.find(l=>l.grp_key===gk);if(!line)return;
    if(field==='qty')          line.qty          = Math.max(0.001,parseFloat(val)||0);
    if(field==='unit_price')   line.unit_price   = parseFloat(val)||0;
    if(field==='discount_pct') line.discount_pct = Math.min(100,Math.max(0,parseFloat(val)||0));
    const row=document.getElementById(line.row_id);recalcLine(line,row);calcTotals();
}
function recalcLine(line,row){
    line.total=line.qty*line.unit_price*(1-line.discount_pct/100);
    if(row) row.querySelector('.t-input').value=line.total>0?line.total.toFixed(2):'';
}
function removeLine(gk){
    lines=lines.filter(l=>l.grp_key!==gk);
    const rowId='lgrp_'+gk.replace(/[^a-z0-9]/gi,'_');
    const row=document.getElementById(rowId);if(row)row.remove();
    if(!lines.length)document.getElementById('emptyRow').style.display='';
    calcTotals();updateLinesCount();
}
function updateLinesCount(){document.getElementById('linesCount').textContent=lines.length?`(${lines.length} بند)`:'';};

// ── الإجماليات ──
function calcTotals(){
    const subtotal=lines.reduce((s,l)=>s+l.total,0);
    const totalQty=lines.reduce((s,l)=>s+l.qty,0);
    const discPct=parseFloat(document.getElementById('discPct').value)||0;
    const taxPct=parseFloat(document.getElementById('taxPct').value)||0;
    const discAmt=subtotal*discPct/100;
    const taxAmt=(subtotal-discAmt)*taxPct/100;
    const total=subtotal-discAmt+taxAmt;
    document.getElementById('sumLines').textContent=lines.length;
    document.getElementById('sumQty').textContent=totalQty.toFixed(0);
    document.getElementById('sumSubtotal').textContent='$ '+subtotal.toFixed(2);
    document.getElementById('sumDisc').textContent='- $ '+discAmt.toFixed(2);
    document.getElementById('sumTax').textContent='+ $ '+taxAmt.toFixed(2);
    document.getElementById('sumTotal').textContent='$ '+total.toFixed(2);
}

// ── حفظ الفاتورة ──
function saveInvoice(){
    if(!document.getElementById('iCustomer').value){toast('يجب اختيار العميل','danger');document.getElementById('iCustomer').focus();return;}
    if(!document.getElementById('iWarehouse').value){toast('يجب اختيار المستودع','danger');return;}
    const valid=lines.filter(l=>l.qty>0&&l.unit_price>0);
    if(!valid.length){toast('يجب إضافة منتج واحد على الأقل بسعر وكمية','danger');return;}

    const btn=document.querySelector('[onclick="saveInvoice()"]');
    const orig=btn?btn.innerHTML:'';
    if(btn){btn.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>جارٍ الحفظ...';btn.disabled=true;}

    post({
        _action:       'save_invoice',
        invoice_no:    document.getElementById('iInvNo').value,
        customer_id:   document.getElementById('iCustomer').value,
        warehouse_id:  document.getElementById('iWarehouse').value,
        invoice_date:  document.getElementById('iDate').value,
        due_date:      document.getElementById('iDueDate').value,
        notes:         document.getElementById('iNotes').value,
        discount_pct:  document.getElementById('discPct').value,
        tax_pct:       document.getElementById('taxPct').value,
        rows:          JSON.stringify(valid.map(l=>({...l,variant_ids:l.variants.map(v=>v.variant_id)})))
    }).then(d=>{
        if(btn){btn.innerHTML=orig;btn.disabled=false;}
        if(d.ok){toast('✅ '+d.msg+' — '+d.no);setTimeout(()=>window.location.href='invoices.php',1200);}
        else toast(d.msg,'danger');
    });
}

// ── كاميرا ──
async function toggleCamera(){
    const wrap=document.getElementById('cameraWrap');
    if(_camStream){stopCamera();wrap.style.display='none';document.getElementById('btnCamera').innerHTML='<i class="bi bi-camera me-1"></i>كاميرا';}
    else{
        try{
            _camStream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'}});
            document.getElementById('camVideo').srcObject=_camStream;
            wrap.style.display='block';
            document.getElementById('btnCamera').innerHTML='<i class="bi bi-camera-video-off me-1"></i>إيقاف';
            startBarcodeDetection();
        }catch(e){toast('لا يمكن الوصول للكاميرا','danger');}
    }
}
function stopCamera(){if(_camStream){_camStream.getTracks().forEach(t=>t.stop());_camStream=null;}if(_camInterval){cancelAnimationFrame(_camInterval);_camInterval=null;}}
function startBarcodeDetection(){
    const video=document.getElementById('camVideo'),canvas=document.getElementById('camCanvas'),ctx=canvas.getContext('2d'),status=document.getElementById('camStatus');
    function onFound(bc){document.getElementById('scanInput').value=bc;status.innerHTML=`<i class="bi bi-check-circle-fill text-success me-1"></i>تم: ${bc}`;doSearch();setTimeout(()=>{if(_camStream)_camInterval=requestAnimationFrame(processFrame);},2000);}
    function processFrame(){
        if(!_camStream)return;
        if(video.readyState!==video.HAVE_ENOUGH_DATA){_camInterval=requestAnimationFrame(processFrame);return;}
        canvas.width=video.videoWidth;canvas.height=video.videoHeight;ctx.drawImage(video,0,0);
        if('BarcodeDetector' in window){new BarcodeDetector({formats:['ean_13','ean_8','code_128','code_39','qr_code']}).detect(canvas).then(c=>{if(c.length)onFound(c[0].rawValue);else _camInterval=requestAnimationFrame(processFrame);}).catch(()=>{_camInterval=requestAnimationFrame(processFrame);});return;}
        if(window._zxingReader){try{const r=window._zxingReader.decode(new window.ZXing.BinaryBitmap(new window.ZXing.HybridBinarizer(new window.ZXing.RGBLuminanceSource(ctx.getImageData(0,0,canvas.width,canvas.height).data,canvas.width,canvas.height))));if(r){onFound(r.getText());return;}}catch(e){}_camInterval=requestAnimationFrame(processFrame);return;}
        if(!window._zxingLoaded){window._zxingLoaded=true;status.innerHTML='<i class="bi bi-hourglass-split me-1 text-warning"></i>جارٍ تحميل مكتبة المسح...';const s=document.createElement('script');s.src='https://cdn.jsdelivr.net/npm/@zxing/library@0.21.3/umd/index.min.js';s.onload=()=>{try{window._zxingReader=new window.ZXing.MultiFormatReader();status.innerHTML='<i class="bi bi-circle-fill scanning me-1"></i>جارٍ المسح...';}catch(e){}; _camInterval=requestAnimationFrame(processFrame);};document.head.appendChild(s);return;}
        _camInterval=requestAnimationFrame(processFrame);
    }
    status.innerHTML='<i class="bi bi-circle-fill scanning me-1"></i>جارٍ المسح...';
    _camInterval=requestAnimationFrame(processFrame);
}
window.addEventListener('beforeunload',stopCamera);

// ── تحديث المنتجات ──
function refreshProducts(){
    const btn=document.getElementById('btnRefresh');
    btn.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>جارٍ...';btn.disabled=true;
    const state={customer:document.getElementById('iCustomer').value,warehouse:document.getElementById('iWarehouse').value,date:document.getElementById('iDate').value,dueDate:document.getElementById('iDueDate').value,notes:document.getElementById('iNotes').value,discPct:document.getElementById('discPct').value,taxPct:document.getElementById('taxPct').value,lines};
    sessionStorage.setItem('sales_draft',JSON.stringify(state));location.reload();
}
(function restoreDraft(){
    const saved=sessionStorage.getItem('sales_draft');if(!saved)return;
    sessionStorage.removeItem('sales_draft');
    try{
        const s=JSON.parse(saved);
        if(s.customer)  document.getElementById('iCustomer').value=s.customer;
        if(s.warehouse) document.getElementById('iWarehouse').value=s.warehouse;
        if(s.date)      document.getElementById('iDate').value=s.date;
        if(s.dueDate)   document.getElementById('iDueDate').value=s.dueDate;
        if(s.notes)     document.getElementById('iNotes').value=s.notes;
        if(s.discPct)   document.getElementById('discPct').value=s.discPct;
        if(s.taxPct)    document.getElementById('taxPct').value=s.taxPct;
        onCustomerChange();
        if(s.lines&&s.lines.length){
            s.lines.forEach(l=>{lines.push(l);document.getElementById('emptyRow').style.display='none';
                const pricesForProd=[...new Set(s.lines.filter(x=>x.product_id===l.product_id).map(x=>x.selling_price))];
                const grpIdx=pricesForProd.indexOf(l.selling_price);const [bg,clr,br]=GRP_COLORS[grpIdx%4];
                const colorDot=l.color_hex?`<span class="clr-dot" style="background:${l.color_hex}"></span>`:'';
                const tbody=document.getElementById('linesBody');const tr=document.createElement('tr');tr.id=l.row_id;
                tr.innerHTML=`<td class="text-muted" style="font-size:.75rem">${lines.length}</td>
                    <td><div class="fw-600" style="font-size:.8rem">${l.product_name}</div><div class="text-muted" style="font-size:.7rem" dir="ltr">${l.model_number}</div></td>
                    <td><span style="background:${bg};color:${clr};border:1px solid ${br};border-radius:12px;font-size:.68rem;padding:2px 8px;font-weight:600">كروب ${grpIdx+1}</span>
                        <div class="sizes-lbl mt-1" style="font-size:.72rem;color:#334155;font-weight:600">${l.sizes.join(' · ')} ${l.age_type}</div></td>
                    <td><div class="d-flex align-items-center gap-1">${colorDot}<span style="font-size:.78rem">${l.color_name||'—'}</span></div></td>
                    <td style="width:70px"><input type="number" class="q-input" min="1" step="1" value="${l.qty}" dir="ltr" onchange="updateLine('${l.grp_key}','qty',this.value)"></td>
                    <td style="width:100px"><input type="number" class="p-input" min="0" step="0.0001" value="${l.unit_price||''}" dir="ltr" onchange="updateLine('${l.grp_key}','unit_price',this.value)"></td>
                    <td style="width:65px"><input type="number" class="d-input" min="0" max="100" step="0.01" value="${l.discount_pct||0}" dir="ltr" onchange="updateLine('${l.grp_key}','discount_pct',this.value)"></td>
                    <td style="width:90px"><input type="number" class="t-input calc" readonly dir="ltr" value="${l.total>0?l.total.toFixed(2):''}"></td>
                    <td><button class="del-btn" onclick="removeLine('${l.grp_key}')"><i class="bi bi-x-lg"></i></button></td>`;
                tbody.appendChild(tr);
            });
            calcTotals();updateLinesCount();toast('تم استعادة بيانات الفاتورة ✅');
        }
    }catch(e){}
})();

document.addEventListener('click',e=>{const sr=document.getElementById('searchResults');if(!sr.contains(e.target)&&e.target.id!=='scanInput')sr.style.display='none';});
</script>
</body>
</html>
