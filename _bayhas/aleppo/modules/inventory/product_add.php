<?php
/**
 * product_add.php — إضافة / تعديل منتج
 * المسار: /bayhas/aleppo/modules/inventory/product_add.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('inventory.products', 'view');

$branchName = $_SESSION['branch_name'] ?? 'الفرع';
$TS  = $_SESSION['table_suffix'];
$TP  = "products_{$TS}";
$TC  = "product_categories_{$TS}";
$TV  = "product_variants_{$TS}";
$TSZ = "product_sizes_{$TS}";
$TW  = "warehouses_{$TS}";
$TWI = "warehouse_items_{$TS}";
$TCL = "product_colors_{$TS}";  // جدول الألوان
$TSP = "product_suppliers_{$TS}"; // جدول الموردين

// عملة الفرع الأساسية + كل العملات النشطة
$branchCurRow = $pdo->prepare("SELECT c.id, c.code, c.symbol, c.exchange_rate FROM branches b
    JOIN currencies c ON c.id = b.base_currency_id
    WHERE b.table_suffix = ? LIMIT 1");
$branchCurRow->execute([$TS]);
$branchCurRow = $branchCurRow->fetch(PDO::FETCH_ASSOC)
    ?: ['id'=>1,'code'=>'USD','symbol'=>'$','exchange_rate'=>1.0];
$BASE_CUR_ID  = (int)$branchCurRow['id'];
$BASE_CUR_CODE= $branchCurRow['code'];
$BASE_CUR_SYM = $branchCurRow['symbol'];
$branchBaseRateVsAnchor = (float)$branchCurRow['exchange_rate'] ?: 1.0;

$allCurrencies = $pdo->query("SELECT * FROM currencies WHERE status='active' ORDER BY code")->fetchAll();
foreach ($allCurrencies as &$cu) {
    $cu['rate_vs_branch'] = $branchBaseRateVsAnchor > 0
        ? ((float)$cu['exchange_rate']) / $branchBaseRateVsAnchor
        : 1.0;
}
unset($cu);

// ── AJAX ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        // ── جلب منتج للتعديل ──
        if ($act === 'get_product') {
            $id = (int)$_POST['id'];
            $st = $pdo->prepare("SELECT * FROM `{$TP}` WHERE id=?");
            $st->execute([$id]);
            $p = $st->fetch();
            if (!$p) throw new Exception('المنتج غير موجود');
            $sz = $pdo->prepare("SELECT * FROM `{$TSZ}` WHERE product_id=? ORDER BY sort_order");
            $sz->execute([$id]);
            $p['sizes'] = $sz->fetchAll();
            $vr = $pdo->prepare("SELECT v.*, s.size AS size_label
                FROM `{$TV}` v LEFT JOIN `{$TSZ}` s ON s.id=v.size_id
                WHERE v.product_id=? AND v.is_active=1 ORDER BY v.color, s.sort_order");
            $vr->execute([$id]);
            $p['variants'] = $vr->fetchAll();
            // جلب آخر منتج
            $lp = $pdo->query("SELECT * FROM `{$TP}` ORDER BY id DESC LIMIT 1")->fetch();
            echo json_encode(['ok'=>true,'data'=>$p,'last'=>$lp]);
        }

        // ── جلب آخر منتج للنسخ ──
        elseif ($act === 'get_last_product') {
            $lp = $pdo->query("SELECT * FROM `{$TP}` ORDER BY id DESC LIMIT 1")->fetch();
            if (!$lp) throw new Exception('لا يوجد منتج سابق');
            $sz = $pdo->prepare("SELECT * FROM `{$TSZ}` WHERE product_id=? ORDER BY sort_order");
            $sz->execute([$lp['id']]);
            $lp['sizes'] = $sz->fetchAll();
            echo json_encode(['ok'=>true,'data'=>$lp]);
        }

        // ── إضافة فئة جديدة ──
        elseif ($act === 'add_category') {
            requirePermission('inventory.products','create');
            $name      = trim($_POST['name'] ?? '');
            $parent_id = (int)($_POST['parent_id'] ?? 0) ?: null;
            if (!$name) throw new Exception('اسم الفئة مطلوب');
            $pdo->prepare("INSERT INTO `{$TC}` (name,parent_id,is_active) VALUES (?,?,1)")
                ->execute([$name, $parent_id]);
            $newId = (int)$pdo->lastInsertId();
            echo json_encode(['ok'=>true,'id'=>$newId,'name'=>$name,'parent_id'=>$parent_id]);
        }

        // ── جلب الموردين ──
        elseif ($act === 'get_suppliers') {
            $list = $pdo->query("SELECT id, name, contact_person, phone, type FROM `{$TSP}` WHERE status='active' ORDER BY name")->fetchAll();
            echo json_encode(['ok'=>true,'data'=>$list]);
        }

        // ── إضافة مورد جديد ──
        elseif ($act === 'add_supplier') {
            requirePermission('inventory.products','create');
            $name    = trim($_POST['name'] ?? '');
            $contact = trim($_POST['contact_person'] ?? '');
            $phone   = trim($_POST['phone'] ?? '');
            $type    = $_POST['type'] ?? 'wholesaler';
            $notes   = trim($_POST['notes'] ?? '');
            if (!$name) throw new Exception('اسم المورد مطلوب');
            $pdo->prepare("INSERT INTO `{$TSP}` (name,contact_person,phone,type,notes,created_by)
                VALUES (?,?,?,?,?,?)")
                ->execute([$name,$contact,$phone,$type,$notes,$_SESSION['user_id']]);
            $newId = (int)$pdo->lastInsertId();
            echo json_encode(['ok'=>true,'id'=>$newId,'name'=>$name,'phone'=>$phone,'type'=>$type]);
        }

        // ── إضافة لون جديد ──
        elseif ($act === 'add_color') {
            requirePermission('inventory.products','create');
            $name = trim($_POST['name'] ?? '');
            $hex  = trim($_POST['hex']  ?? '#000000');
            if (!$name) throw new Exception('اسم اللون مطلوب');
            try {
                $pdo->prepare("INSERT INTO `{$TCL}` (name,hex_code,is_active) VALUES (?,?,1)")
                    ->execute([$name, $hex]);
                $newId = (int)$pdo->lastInsertId();
            } catch (Exception $e) {
                throw new Exception('خطأ في حفظ اللون — تأكد من وجود جدول الألوان');
            }
            echo json_encode(['ok'=>true,'id'=>$newId,'name'=>$name,'hex'=>$hex]);
        }

        // ── حفظ منتج (إنشاء/تعديل) ──
        elseif ($act === 'save_product') {
            $id          = (int)($_POST['id'] ?? 0);
            $name        = trim($_POST['name'] ?? '');
            $model       = trim($_POST['model_number'] ?? '');
            $cat_id      = (int)($_POST['category_id'] ?? 0);
            $fabric      = trim($_POST['fabric_type'] ?? '');
            $supplier_id = (int)($_POST['supplier_id'] ?? 0) ?: null;
            $wh_id       = (int)($_POST['warehouse_id'] ?? 0);
            $age_type    = $_POST['age_type'] ?? 'سنة'; // للكروبات فقط — لا يُحفظ بـ products
            $notes       = trim($_POST['notes'] ?? '');
            $groups      = json_decode($_POST['size_groups'] ?? '[]', true);
            $colors      = json_decode($_POST['colors'] ?? '[]', true);

            $pricing     = json_decode($_POST['pricing'] ?? '[]', true);

            if (!$name || !$model) throw new Exception('اسم المنتج ورقم الموديل مطلوبان');

            if ($id) {
                requirePermission('inventory.products','edit');
                $pdo->prepare("UPDATE `{$TP}` SET name=?,model_number=?,category_id=?,fabric_type=?,
                    supplier_id=?,notes=?,updated_by=?,updated_at=NOW() WHERE id=?")
                    ->execute([$name,$model,$cat_id,$fabric,$supplier_id,$notes,$_SESSION['user_id'],$id]);
            } else {
                requirePermission('inventory.products','create');
                $dup = $pdo->prepare("SELECT id FROM `{$TP}` WHERE model_number=?");
                $dup->execute([$model]);
                if ($dup->fetch()) throw new Exception("رقم الموديل {$model} موجود مسبقاً");
                // fabric_type قد لا يكون موجوداً في جدول قديم — نستخدم try/catch
                try {
                    $pdo->prepare("INSERT INTO `{$TP}` (name,model_number,category_id,fabric_type,supplier_id,notes,created_by)
                        VALUES (?,?,?,?,?,?,?)")
                        ->execute([$name,$model,$cat_id,$fabric,$supplier_id,$notes,$_SESSION['user_id']]);
                } catch (Exception $e) {
                    $pdo->prepare("INSERT INTO `{$TP}` (name,model_number,category_id,notes,created_by)
                        VALUES (?,?,?,?,?)")
                        ->execute([$name,$model,$cat_id,$notes,$_SESSION['user_id']]);
                }
                $id = (int)$pdo->lastInsertId();
            }

            // حذف المقاسات القديمة وإعادة الإدخال
            $pdo->prepare("DELETE FROM `{$TSZ}` WHERE product_id=?")->execute([$id]);
            $sortOrder = 0;
            foreach ($groups as $grpNo => $grp) {
                $sellPrice  = 0;
                $packetQty  = count($grp['sizes']);  // عدد القطع = عدد الأرقام المختارة
                $costPrice  = null;
                $marginPct  = null;
                $curId      = $BASE_CUR_ID;
                $exRate     = 1.0;
                foreach ($pricing as $pr) {
                    if ($pr['group_key'] === $grp['key']) {
                        $sellPriceRaw = (float)($pr['sell_price'] ?? 0);
                        $costPriceRaw = $pr['cost_price'] !== '' ? (float)$pr['cost_price'] : null;
                        $marginPct    = $pr['margin']     !== '' ? (float)$pr['margin']     : null;
                        $curId        = (int)($pr['currency_id'] ?? $BASE_CUR_ID) ?: $BASE_CUR_ID;
                        $exRate       = max(0.000001, (float)($pr['exchange_rate'] ?? 1));
                        // تحويل الأسعار من العملة المختارة إلى عملة الفرع الأساسية للتخزين
                        $sellPrice = $curId === $BASE_CUR_ID ? $sellPriceRaw : round($sellPriceRaw / $exRate, 4);
                        $costPrice = $costPriceRaw === null ? null
                            : ($curId === $BASE_CUR_ID ? $costPriceRaw : round($costPriceRaw / $exRate, 4));
                        break;
                    }
                }
                $ageType   = $grp['type'] ?? 'سنة';
                foreach ($grp['sizes'] as $szVal) {
                    $szLabel = trim((string)$szVal);
                    if (!$szLabel) continue;
                    $pdo->prepare("INSERT INTO `{$TSZ}`
                            (product_id,size,age_type,sort_order,selling_price,cost_price,
                             base_currency_id,currency_id,exchange_rate,margin_pct,packet_qty,is_active)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,1)")
                        ->execute([$id, $szLabel, $ageType, $sortOrder++, $sellPrice, $costPrice,
                            $BASE_CUR_ID, $curId, $exRate, $marginPct, $packetQty]);
                }
            }

            // حذف المتغيرات القديمة نهائياً وإعادة البناء من صفر
            $pdo->prepare("DELETE FROM `{$TV}` WHERE product_id=?")->execute([$id]);

            // جلب المقاسات المُدخلة للتو
            $szRows = $pdo->prepare("SELECT id,size FROM `{$TSZ}` WHERE product_id=? AND is_active=1 ORDER BY sort_order");
            $szRows->execute([$id]);
            $allSizes = $szRows->fetchAll();

            // خريطة: قيمة المقاس => رقم الكروب
            $sizeGrpMap = [];
            foreach ($groups as $gi => $grp) {
                foreach ($grp['sizes'] as $szVal) {
                    $sizeGrpMap[(string)$szVal] = $gi;
                }
            }
            // خريطة: size_id => رقم الكروب
            $sizeIdGrpMap = [];
            foreach ($allSizes as $sz) {
                $sizeIdGrpMap[$sz['id']] = $sizeGrpMap[(string)$sz['size']] ?? 0;
            }

            // إدراج: كل لون × كل مقاس = سطر مستقل
            $insertVariant = $pdo->prepare("INSERT INTO `{$TV}`
                (product_id, size_id, color_id, barcode, is_active, created_by)
                VALUES (?, ?, ?, ?, 1, ?)");

            foreach ($colors as $ci => $clr) {
                $colorId = (int)($clr['id'] ?? 0);
                if (!$colorId) continue;
                foreach ($allSizes as $sz) {
                    $grpIdx     = $sizeIdGrpMap[$sz['id']] ?? 0;
                    $grpNum     = str_pad($grpIdx + 1, 2, '0', STR_PAD_LEFT);
                    $colorNum   = str_pad($ci + 1, 2, '0', STR_PAD_LEFT);
                    $grpBarcode = strtoupper($model) . '-G' . $grpNum . '-C' . $colorNum . '-S' . $sz['id'];
                    $insertVariant->execute([$id, $sz['id'], $colorId, $grpBarcode, $_SESSION['user_id']]);
                }
            }

            // ربط بالمستودع مع حد التنبيه
            $minQty = (int)($_POST['min_quantity'] ?? 0);
            if ($wh_id) {
                $vrAll = $pdo->prepare("SELECT id FROM `{$TV}` WHERE product_id=? AND is_active=1");
                $vrAll->execute([$id]);
                foreach ($vrAll->fetchAll() as $vr) {
                    $pdo->prepare("INSERT INTO `{$TWI}` (warehouse_id,variant_id,product_id,quantity,min_quantity)
                        VALUES (?,?,?,0,?)
                        ON DUPLICATE KEY UPDATE min_quantity=VALUES(min_quantity)")
                        ->execute([$wh_id,$vr['id'],$id,$minQty]);
                }
            }

            echo json_encode(['ok'=>true,'msg'=>'تم حفظ المنتج بنجاح','id'=>$id]);
        }

        else throw new Exception('إجراء غير معروف');

    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

// ── جلب بيانات الصفحة ───────────────────────────────────────────────
// صفحة الإضافة فقط — التعديل في product_edit.php
$editProd = null;

$categories = $pdo->query("SELECT * FROM `{$TC}` WHERE is_active=1 ORDER BY parent_id, name")->fetchAll();
$warehouses = $pdo->query("SELECT * FROM `{$TW}` WHERE is_active=1 ORDER BY id")->fetchAll();

// جلب الألوان من جدول الألوان (مع fallback لو الجدول غير موجود)
$colors = [];
try {
    $colors = $pdo->query("SELECT * FROM `{$TCL}` WHERE is_active=1 ORDER BY name")->fetchAll();
} catch (Exception $e) {
    // الجدول غير موجود بعد، نستخدم ألواناً افتراضية
    $colors = [
        ['id'=>0,'name'=>'أسود',    'hex_code'=>'#1a1a2e'],
        ['id'=>0,'name'=>'أبيض',    'hex_code'=>'#f5f0e8'],
        ['id'=>0,'name'=>'رمادي',   'hex_code'=>'#6b7280'],
        ['id'=>0,'name'=>'أزرق',    'hex_code'=>'#3b82f6'],
        ['id'=>0,'name'=>'أحمر',    'hex_code'=>'#ef4444'],
        ['id'=>0,'name'=>'أخضر',    'hex_code'=>'#22c55e'],
        ['id'=>0,'name'=>'أصفر',    'hex_code'=>'#f59e0b'],
        ['id'=>0,'name'=>'بني',     'hex_code'=>'#92400e'],
        ['id'=>0,'name'=>'بيج فاتح','hex_code'=>'#d4b896'],
        ['id'=>0,'name'=>'بنفسجي', 'hex_code'=>'#8b5cf6'],
        ['id'=>0,'name'=>'زهري',   'hex_code'=>'#ec4899'],
        ['id'=>0,'name'=>'تركواز', 'hex_code'=>'#0e7490'],
    ];
}

// الفئات الجذر
$rootCats = array_values(array_filter($categories, fn($c) => !$c['parent_id']));

// جلب الموردين
$suppliers = $pdo->query("SELECT id,name,contact_person,phone,type FROM `{$TSP}` WHERE status='active' ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>إضافة منتج جديد — <?= htmlspecialchars($branchName) ?></title>
<link rel="icon" href="/bayhas/assets/images/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="/bayhas/assets/css/layout.css" rel="stylesheet">
<style>
.sec-card{background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:14px}
.sec-hdr{background:#f8fafc;padding:9px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:8px}
.sec-num{width:22px;height:22px;border-radius:50%;background:#1e3a8a;color:#fff;font-size:11px;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0}
.sec-title{font-size:.83rem;font-weight:700;color:#1e293b}
.sec-body{padding:14px 16px}
.field-lbl{font-size:.75rem;font-weight:600;color:#64748b;margin-bottom:4px;display:block}
.req{color:#dc2626}
.field-hint{font-size:.7rem;color:#94a3b8;margin-top:3px}
.sz-btn{width:34px;height:32px;border-radius:7px;border:1px solid #e2e8f0;background:#f8fafc;font-size:.78rem;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;color:#64748b;font-weight:500;transition:all .12s;flex-shrink:0}
.sz-btn:hover{border-color:#93c5fd;background:#eff6ff;color:#1d4ed8}
.sz-btn.sel{background:#1e3a8a;color:#fff;border-color:#1e3a8a;font-weight:600}
.sz-btn.grp1.sel{background:#1e3a8a;border-color:#1e3a8a}
.sz-btn.grp2.sel{background:#065f46;border-color:#065f46}
.sz-btn.grp3.sel{background:#7c2d12;border-color:#7c2d12}
.sz-btn.grp4.sel{background:#4c1d95;border-color:#4c1d95}
.grp-pill{display:inline-flex;align-items:center;gap:4px;border-radius:20px;font-size:.72rem;padding:3px 9px;font-weight:600;cursor:pointer;border:1px solid transparent}
.grp-pill.g1{background:#eff6ff;color:#1e3a8a;border-color:#bfdbfe}
.grp-pill.g2{background:#f0fdf4;color:#065f46;border-color:#bbf7d0}
.grp-pill.g3{background:#fff7ed;color:#7c2d12;border-color:#fed7aa}
.grp-pill.g4{background:#f5f3ff;color:#4c1d95;border-color:#ddd6fe}
.color-dot{width:28px;height:28px;border-radius:50%;cursor:pointer;border:2px solid transparent;flex-shrink:0;transition:all .12s;position:relative}
.color-dot.sel{border-color:#1e3a8a;box-shadow:0 0 0 2px #fff inset}
.color-dot:hover{transform:scale(1.12)}
.bc-row{display:flex;align-items:center;gap:8px;background:#f8fafc;border-radius:8px;padding:5px 10px;margin-bottom:5px}
.bc-dot{width:14px;height:14px;border-radius:50%;flex-shrink:0}
.bc-name{font-size:.78rem;width:60px;color:#334155}
.bc-val{font-size:.75rem;font-family:monospace;color:#64748b;direction:ltr;flex:1;background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:3px 8px}
.price-tbl{width:100%;border-collapse:collapse;font-size:.78rem}
.price-tbl th{background:#f8fafc;padding:6px 10px;font-weight:600;color:#64748b;font-size:.72rem;border-bottom:1px solid #f1f5f9}
.price-tbl td{padding:5px 8px;border-bottom:1px solid #f8fafc;vertical-align:middle}
.price-tbl tr:last-child td{border-bottom:none}
.price-tbl input{border:1px solid #e2e8f0;border-radius:6px;padding:4px 7px;font-size:.78rem;width:100%;background:#fff}
.price-tbl input[readonly]{background:#f8fafc;color:#94a3b8}
.btn-add-sm{display:inline-flex;align-items:center;gap:3px;font-size:.75rem;border:1px solid #1e3a8a;color:#1e3a8a;border-radius:7px;padding:4px 10px;cursor:pointer;background:transparent;white-space:nowrap;transition:all .12s}
.btn-add-sm:hover{background:#eff6ff}
.sb-card{background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:12px}
.sb-hdr{background:#f8fafc;padding:8px 14px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:6px}
.sb-hdr-title{font-size:.78rem;font-weight:700;color:#1e293b}
.sb-body{padding:12px 14px}
.sb-row{display:flex;justify-content:space-between;font-size:.75rem;padding:4px 0;border-bottom:1px solid #f8fafc}
.sb-row:last-child{border-bottom:none}
.sb-row span:first-child{color:#64748b}
.sb-row span:last-child{font-weight:600;color:#1e293b}
.chk-row{display:flex;align-items:center;gap:6px;font-size:.75rem;padding:4px 0;border-bottom:1px solid #f8fafc}
.chk-row:last-child{border-bottom:none}
.chk-row .lbl{color:#64748b}
.upload-box{border:1.5px dashed #e2e8f0;border-radius:10px;padding:20px;text-align:center;cursor:pointer;color:#94a3b8;transition:all .15s;position:relative;overflow:hidden}
.upload-box:hover{border-color:#93c5fd;background:#f0f9ff}
.upload-box.has-img{padding:0;border-color:#93c5fd;background:#000}
.upload-box.has-img img{width:100%;height:130px;object-fit:cover;border-radius:9px;display:block;opacity:.85}
.upload-box.has-img .img-overlay{position:absolute;inset:0;background:rgba(0,0,0,0);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;transition:background .2s}
.upload-box.has-img:hover .img-overlay{background:rgba(0,0,0,.45)}
.upload-box.has-img:hover .img-overlay span{opacity:1}
.upload-box.has-img .img-overlay span{opacity:0;color:#fff;font-size:.72rem;transition:opacity .2s}
.img-toast{display:none;align-items:center;gap:6px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:5px 10px;margin-top:6px;font-size:.74rem;color:#16a34a}
.img-toast.show{display:flex}
.img-name{font-size:.7rem;color:#64748b;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
.n{font-variant-numeric:tabular-nums}
/* مودال اضافة فئة/لون */
.mini-modal{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:2000;display:none;align-items:center;justify-content:center}
.mini-modal.show{display:flex}
.mini-modal-box{background:#fff;border-radius:14px;width:380px;max-width:95vw;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,.15)}
.mini-modal-hdr{background:#1e3a8a;padding:12px 16px;display:flex;align-items:center;justify-content:space-between}
.mini-modal-hdr h6{color:#fff;font-size:.85rem;font-weight:700;margin:0}
.spin{animation:spin .6s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body>
<div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
<?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>

<header class="topbar">
    <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
    <span class="tb-title"><i class="bi bi-boxes me-1 text-primary"></i>المنتجات</span>
    <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
    <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
        <a href="products.php" style="color:#64748b;text-decoration:none">المنتجات</a>
        <i class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
        <span class="text-primary">إضافة منتج</span>
    </nav>
</header>

<main class="main-content">
<div class="content-body">

    <!-- عنوان الصفحة -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div style="font-size:.95rem;font-weight:700;color:#1e293b">
            <i class="bi bi-plus-circle me-2 text-primary"></i>
            إضافة منتج جديد
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-sm" style="border-radius:8px;border:1px solid #1e3a8a;color:#1e3a8a;font-size:.78rem"
                    onclick="copyLastProduct()">
                <i class="bi bi-copy me-1"></i>نسخ آخر منتج
            </button>
            <a href="products.php" class="btn btn-sm btn-light" style="border-radius:8px;font-size:.78rem">
                <i class="bi bi-x-lg me-1"></i>إلغاء
            </a>
            <button class="btn btn-sm fw-600" style="border-radius:8px;background:#1e3a8a;color:#fff;font-size:.78rem;min-width:100px"
                    onclick="saveProduct()" id="btnSave">
                <span id="saveTxt"><i class="bi bi-floppy me-1"></i>حفظ المنتج</span>
                <span id="saveSpin" class="spinner-border spinner-border-sm" style="display:none"></span>
            </button>
        </div>
    </div>

    <input type="hidden" id="pId" value="">

    <div class="row g-3">
    <!-- ══ العمود الرئيسي ══ -->
    <div class="col-lg-8">

        <!-- §1 بيان القطعة -->
        <div class="sec-card">
            <div class="sec-hdr">
                <div class="sec-num">1</div>
                <span class="sec-title"><i class="bi bi-file-text me-1 text-primary"></i>بيان القطعة</span>
            </div>
            <div class="sec-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="field-lbl">اسم المنتج <span class="req">*</span></label>
                        <input type="text" id="pName" class="form-control form-control-sm"
                               placeholder="مثال: بنطلون جينز أطفال"
                               value="">
                    </div>
                    <div class="col-md-6">
                        <label class="field-lbl">رقم الموديل <span class="req">*</span></label>
                        <input type="text" id="pModel" class="form-control form-control-sm"
                               placeholder="مثال: MDL-2024-001" dir="ltr"
                               value=""
                               oninput="updateBarcodes()">
                    </div>
                </div>
            </div>
        </div>

        <!-- §2 التصنيف -->
        <div class="sec-card">
            <div class="sec-hdr">
                <div class="sec-num">2</div>
                <span class="sec-title"><i class="bi bi-tags me-1 text-primary"></i>التصنيف</span>
            </div>
            <div class="sec-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="field-lbl">الفئة <span class="req">*</span></label>
                        <div class="d-flex gap-2">
                            <select id="pCat" class="form-select form-select-sm" style="flex:1">
                                <option value="">— اختر الفئة —</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"
                                    data-parent="<?= $cat['parent_id'] ?>"
                                    >
                                    <?= $cat['parent_id'] ? '↳ ' : '' ?><?= htmlspecialchars($cat['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn-add-sm" onclick="showCatModal()">
                                <i class="bi bi-plus"></i>فئة جديدة
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="field-lbl">صنف المنتج (الخامة)</label>
                        <input type="text" id="pFabric" class="form-control form-control-sm"
                               placeholder="مثال: RAPID، كوتون، جينز"
                               value="">
                    </div>
                </div>
                <div class="row g-3 mt-0">
                    <div class="col-md-8">
                        <label class="field-lbl">المورد</label>
                        <div class="d-flex gap-2">
                            <select id="pSupplier" class="form-select form-select-sm" style="flex:1" onchange="onSupplierChange()">
                                <option value="">— اختر المورد —</option>
                                <?php foreach ($suppliers as $sp): ?>
                                <option value="<?= $sp['id'] ?>"
                                    data-phone="<?= htmlspecialchars($sp['phone']??'') ?>"
                                    data-type="<?= htmlspecialchars($sp['type']??'') ?>">
                                    <?= htmlspecialchars($sp['name']) ?>
                                    <?= $sp['contact_person'] ? '— '.htmlspecialchars($sp['contact_person']) : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn-add-sm" onclick="showSupplierModal()">
                                <i class="bi bi-plus me-1"></i>مورد جديد
                            </button>
                        </div>
                    </div>
                    <div class="col-md-4" id="supplierInfoWrap" style="display:none">
                        <label class="field-lbl">&nbsp;</label>
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:6px 10px;font-size:.74rem;color:#475569">
                            <i class="bi bi-telephone me-1 text-primary"></i>
                            <span id="supplierPhone">—</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- §3 القياس والأعمار -->
        <div class="sec-card">
            <div class="sec-hdr">
                <div class="sec-num">3</div>
                <span class="sec-title"><i class="bi bi-rulers me-1 text-primary"></i>القياس والأعمار</span>
            </div>
            <div class="sec-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="field-lbl">نوع عمر الكروب النشط</label>
                        <select id="pAgeType" class="form-select form-select-sm" onchange="onAgeTypeChange()">
                            <option value="سنة" selected>سنة</option>
                            <option value="شهر">شهر</option>
                        </select>
                        <div class="field-hint" id="ageTypeHint">يُطبّق على الكروب النشط</div>
                    </div>
                    <div class="col-md-4">
                        <label class="field-lbl">&nbsp;</label>
                        <div>
                            <button class="btn-add-sm" id="btnNewGrp" onclick="startNewGroup()" style="height:31px">
                                <i class="bi bi-plus-circle me-1"></i>كروب جديد
                            </button>
                        </div>
                    </div>
                </div>

                <!-- كروبات مختارة -->
                <div class="mb-2">
                    <div style="font-size:.72rem;font-weight:600;color:#64748b;margin-bottom:5px">
                        الكروبات المختارة (حتى 4) — انقر على كروب لإزالته
                    </div>
                    <div id="grpPills" class="d-flex flex-wrap gap-2 mb-2"></div>
                </div>

                <!-- شبكة الأرقام -->
                <div id="sizeGridWrap">
                    <div id="sizeGridLabel" style="font-size:.72rem;font-weight:600;color:#64748b;margin-bottom:5px"></div>
                    <div id="sizeGrid" class="d-flex flex-wrap gap-1"></div>
                </div>
            </div>
        </div>

        <!-- §4 الألوان -->
        <div class="sec-card">
            <div class="sec-hdr">
                <div class="sec-num">4</div>
                <span class="sec-title"><i class="bi bi-palette me-1 text-primary"></i>الألوان</span>
                <button class="btn-add-sm ms-auto" onclick="showColorModal()">
                    <i class="bi bi-plus"></i>لون جديد
                </button>
            </div>
            <div class="sec-body">
                <div style="font-size:.72rem;font-weight:600;color:#64748b;margin-bottom:8px">
                    انقر على اللون لتحديده / إلغاء تحديده
                </div>
                <div id="colorGrid" class="d-flex flex-wrap gap-2 mb-2"></div>
                <div id="colorSelected" class="field-hint"></div>
            </div>
        </div>

        <!-- §5 الباركودات -->
        <div class="sec-card">
            <div class="sec-hdr">
                <div class="sec-num">5</div>
                <span class="sec-title"><i class="bi bi-upc-scan me-1 text-primary"></i>الباركودات المولّدة تلقائياً</span>
            </div>
            <div class="sec-body">
                <div class="field-hint mb-2">باركود واحد لكل لون — يتولّد تلقائياً من رقم الموديل + اسم اللون</div>
                <div id="barcodeList"></div>
            </div>
        </div>

        <!-- §6 التسعير -->
        <div class="sec-card">
            <div class="sec-hdr">
                <div class="sec-num">6</div>
                <span class="sec-title"><i class="bi bi-currency-dollar me-1 text-primary"></i>التسعير (بحسب الكروب)</span>
            </div>
            <div class="sec-body">
                <div id="pricingTable">
                    <div class="text-muted" style="font-size:.78rem">اختر القياسات أولاً لظهور جدول التسعير</div>
                </div>
            </div>
        </div>

        <!-- §7 التفاصيل الإضافية -->
        <div class="sec-card">
            <div class="sec-hdr">
                <div class="sec-num">7</div>
                <span class="sec-title"><i class="bi bi-info-circle me-1 text-primary"></i>تفاصيل إضافية</span>
            </div>
            <div class="sec-body">
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="field-lbl">وصف المنتج</label>
                        <textarea id="pNotes" class="form-control form-control-sm" rows="3"
                                  placeholder="وصف اختياري..."></textarea>
                    </div>
                    <div class="col-md-3">
                        <label class="field-lbl">حد التنبيه للمخزون</label>
                        <input type="number" id="pMinQty" class="form-control form-control-sm"
                               min="0" value="0" placeholder="0">
                        <div class="field-hint">تنبيه عند انخفاض المخزون لهذا الحد</div>
                    </div>
                    <div class="col-md-4">
                        <label class="field-lbl">صورة المنتج</label>
                        <div class="upload-box" id="uploadBox" onclick="document.getElementById('pImg').click()">
                            <i class="bi bi-cloud-arrow-up d-block mb-1" style="font-size:1.4rem" id="uploadIcon"></i>
                            <span style="font-size:.75rem" id="uploadTxt">انقر لرفع صورة</span>
                            <div class="img-overlay" id="imgOverlay" style="display:none">
                                <img src="" id="imgPreviewInner" style="display:none">
                                <span><i class="bi bi-arrow-repeat me-1"></i>تغيير الصورة</span>
                            </div>
                        </div>
                        <div class="img-toast" id="imgToast">
                            <i class="bi bi-check-circle-fill"></i>
                            <span id="imgToastName">تم رفع الصورة</span>
                            <button onclick="removeImg()" style="background:none;border:none;color:#16a34a;margin-right:auto;cursor:pointer;font-size:.72rem;padding:0">
                                <i class="bi bi-x-lg"></i> إزالة
                            </button>
                        </div>
                        <input type="file" id="pImg" accept="image/*" style="display:none" onchange="onImgSelect(this)">
                    </div>
                </div>
            </div>
        </div>

        <!-- أزرار الحفظ -->
        <div class="d-flex justify-content-end gap-2 mb-4">
            <button class="btn btn-sm" style="border-radius:8px;border:1px solid #1e3a8a;color:#1e3a8a;font-size:.78rem"
                    onclick="copyLastProduct()">
                <i class="bi bi-copy me-1"></i>نسخ آخر منتج
            </button>
            <a href="products.php" class="btn btn-sm btn-light" style="border-radius:8px;font-size:.78rem">
                <i class="bi bi-x-lg me-1"></i>إلغاء
            </a>
            <button class="btn btn-sm fw-600" style="border-radius:8px;background:#1e3a8a;color:#fff;font-size:.78rem;min-width:100px"
                    onclick="saveProduct()">
                <i class="bi bi-floppy me-1"></i>حفظ المنتج
            </button>
        </div>

    </div><!-- /col-lg-8 -->

    <!-- ══ الشريط الجانبي الأيمن ══ -->
    <div class="col-lg-4">
      <div style="position:sticky;top:70px">
        <!-- أزرار العمل العائمة -->
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px 14px;margin-bottom:12px;display:flex;flex-direction:column;gap:8px">
            <button class="btn btn-sm fw-600 w-100" style="border-radius:8px;background:#1e3a8a;color:#fff;font-size:.82rem"
                    onclick="saveProduct()" id="btnSave2">
                <span id="saveTxt2"><i class="bi bi-floppy me-1"></i>حفظ المنتج</span>
                <span id="saveSpin2" class="spinner-border spinner-border-sm" style="display:none"></span>
            </button>
            <div class="d-flex gap-2">
                <button class="btn btn-sm w-50" style="border-radius:8px;border:1px solid #1e3a8a;color:#1e3a8a;font-size:.77rem"
                        onclick="copyLastProduct()">
                    <i class="bi bi-copy me-1"></i>نسخ آخر منتج
                </button>
                <a href="products.php" class="btn btn-sm btn-light w-50" style="border-radius:8px;font-size:.77rem;text-align:center">
                    <i class="bi bi-x-lg me-1"></i>إلغاء
                </a>
            </div>
        </div>

        <!-- ملخص المنتج -->
        <div class="sb-card">
            <div class="sb-hdr">
                <i class="bi bi-eye text-primary" style="font-size:.85rem"></i>
                <span class="sb-hdr-title">ملخص المنتج</span>
            </div>
            <div class="sb-body">
                <div class="sb-row"><span>الفئة</span><span id="sbCat">—</span></div>
                <div class="sb-row"><span>الخامة</span><span id="sbFabric">—</span></div>
                <div class="sb-row"><span>الكروبات</span><span id="sbGrps">0</span></div>
                <div class="sb-row"><span>الألوان</span><span id="sbColors">0</span></div>
                <div class="sb-row"><span>المتغيرات الكلية</span><span id="sbVariants">0</span></div>
                <div class="sb-row" style="align-items:flex-start"><span>الباكيت</span><span id="sbPacket" style="text-align:left">—</span></div>

            </div>
        </div>

        <!-- حالة الإدخال -->
        <div class="sb-card">
            <div class="sb-hdr">
                <i class="bi bi-check2-all text-primary" style="font-size:.85rem"></i>
                <span class="sb-hdr-title">حالة الإدخال</span>
            </div>
            <div class="sb-body">
                <div class="chk-row" id="ck1">
                    <i class="bi bi-circle text-secondary" style="font-size:.85rem"></i>
                    <span class="lbl">بيان القطعة</span>
                </div>
                <div class="chk-row" id="ck2">
                    <i class="bi bi-circle text-secondary" style="font-size:.85rem"></i>
                    <span class="lbl">التصنيف</span>
                </div>
                <div class="chk-row" id="ck3">
                    <i class="bi bi-circle text-secondary" style="font-size:.85rem"></i>
                    <span class="lbl">القياسات</span>
                </div>
                <div class="chk-row" id="ck4">
                    <i class="bi bi-circle text-secondary" style="font-size:.85rem"></i>
                    <span class="lbl">الألوان</span>
                </div>
                <div class="chk-row" id="ck5">
                    <i class="bi bi-circle text-secondary" style="font-size:.85rem"></i>
                    <span class="lbl">التسعير</span>
                </div>
                <div class="chk-row" style="border-bottom:none">
                    <i class="bi bi-circle text-secondary" style="font-size:.85rem;opacity:.4"></i>
                    <span class="lbl" style="opacity:.5">التفاصيل (اختياري)</span>
                </div>
            </div>
        </div>

        <!-- المستودع -->
        <div class="sb-card">
            <div class="sb-hdr">
                <i class="bi bi-building-warehouse text-primary" style="font-size:.85rem"></i>
                <span class="sb-hdr-title">تعيين المستودع</span>
            </div>
            <div class="sb-body">
                <label class="field-lbl">المستودع</label>
                <select id="pWarehouse" class="form-select form-select-sm">
                    <?php foreach ($warehouses as $wh): ?>
                    <option value="<?= $wh['id'] ?>"><?= htmlspecialchars($wh['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

      </div><!-- /sticky -->
    </div><!-- /col-lg-4 -->
    </div><!-- /row -->

</div>
</main>

<!-- ══ مودال إضافة فئة ══ -->
<div class="mini-modal" id="catModal">
    <div class="mini-modal-box">
        <div class="mini-modal-hdr">
            <h6><i class="bi bi-tag me-2"></i>إضافة فئة جديدة</h6>
            <button onclick="hideCatModal()" style="background:none;border:none;color:rgba(255,255,255,.7);font-size:1rem;cursor:pointer">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="p-3">
            <div class="mb-3">
                <label class="field-lbl">الفئة الأم (اتركها فارغة إذا كانت جذرية)</label>
                <select id="catParent" class="form-select form-select-sm">
                    <option value="">— فئة جذرية —</option>
                    <?php foreach ($rootCats as $rc): ?>
                    <option value="<?= $rc['id'] ?>"><?= htmlspecialchars($rc['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="field-lbl">اسم الفئة <span class="req">*</span></label>
                <input type="text" id="catName" class="form-control form-control-sm" placeholder="مثال: بنطال">
            </div>
            <div class="d-flex gap-2 justify-content-end">
                <button class="btn btn-sm btn-light" style="border-radius:8px" onclick="hideCatModal()">إلغاء</button>
                <button class="btn btn-sm fw-600" style="border-radius:8px;background:#1e3a8a;color:#fff" onclick="saveCat()">
                    <i class="bi bi-plus-lg me-1"></i>إضافة
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══ مودال إضافة لون ══ -->
<div class="mini-modal" id="colorModal">
    <div class="mini-modal-box">
        <div class="mini-modal-hdr">
            <h6><i class="bi bi-palette me-2"></i>إضافة لون جديد</h6>
            <button onclick="hideColorModal()" style="background:none;border:none;color:rgba(255,255,255,.7);font-size:1rem;cursor:pointer">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="p-3">
            <div class="mb-3">
                <label class="field-lbl">اسم اللون <span class="req">*</span></label>
                <input type="text" id="colorName" class="form-control form-control-sm" placeholder="مثال: أخضر داكن">
            </div>
            <div class="mb-3">
                <label class="field-lbl">الكود اللوني</label>
                <div class="d-flex gap-2 align-items-center">
                    <input type="color" id="colorHex" value="#000000" style="width:44px;height:34px;border-radius:7px;border:1px solid #e2e8f0;padding:2px;cursor:pointer">
                    <input type="text" id="colorHexTxt" class="form-control form-control-sm" dir="ltr" value="#000000"
                           oninput="document.getElementById('colorHex').value=this.value" placeholder="#rrggbb">
                </div>
            </div>
            <div class="d-flex gap-2 justify-content-end">
                <button class="btn btn-sm btn-light" style="border-radius:8px" onclick="hideColorModal()">إلغاء</button>
                <button class="btn btn-sm fw-600" style="border-radius:8px;background:#1e3a8a;color:#fff" onclick="saveColor()">
                    <i class="bi bi-plus-lg me-1"></i>إضافة
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══ مودال إضافة مورد ══ -->
<div class="mini-modal" id="supplierModal">
    <div class="mini-modal-box" style="width:460px">
        <div class="mini-modal-hdr">
            <h6><i class="bi bi-truck me-2"></i>إضافة مورد جديد</h6>
            <button onclick="hideSupplierModal()" style="background:none;border:none;color:rgba(255,255,255,.7);font-size:1rem;cursor:pointer">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="p-3">
            <div class="row g-2 mb-2">
                <div class="col-md-7">
                    <label class="field-lbl">اسم المورد <span class="req">*</span></label>
                    <input type="text" id="spName" class="form-control form-control-sm" placeholder="مثال: شركة النور للتوزيع">
                </div>
                <div class="col-md-5">
                    <label class="field-lbl">نوع المورد</label>
                    <select id="spType" class="form-select form-select-sm">
                        <option value="wholesaler">موزّع بالجملة</option>
                        <option value="manufacturer">مصنّع</option>
                        <option value="distributor">موزّع</option>
                        <option value="retailer">تاجر تجزئة</option>
                    </select>
                </div>
            </div>
            <div class="row g-2 mb-2">
                <div class="col-md-6">
                    <label class="field-lbl">اسم جهة الاتصال</label>
                    <input type="text" id="spContact" class="form-control form-control-sm" placeholder="اسم المسؤول">
                </div>
                <div class="col-md-6">
                    <label class="field-lbl">رقم الهاتف</label>
                    <input type="text" id="spPhone" class="form-control form-control-sm" placeholder="+963..." dir="ltr">
                </div>
            </div>
            <div class="mb-3">
                <label class="field-lbl">ملاحظات</label>
                <textarea id="spNotes" class="form-control form-control-sm" rows="2" placeholder="اختياري"></textarea>
            </div>
            <div class="d-flex gap-2 justify-content-end">
                <button class="btn btn-sm btn-light" style="border-radius:8px" onclick="hideSupplierModal()">إلغاء</button>
                <button class="btn btn-sm fw-600" style="border-radius:8px;background:#1e3a8a;color:#fff" onclick="saveSupplier()">
                    <i class="bi bi-plus-lg me-1"></i>إضافة المورد
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Sidebar ──
const sb = document.getElementById('sidebar'), ov = document.getElementById('sbOverlay');
function sbOpen()  { sb.classList.add('open');  ov.classList.add('show'); }
function sbClose() { sb.classList.remove('open'); ov.classList.remove('show'); }
window.addEventListener('resize', () => { if(window.innerWidth>991) sbClose(); });
document.querySelectorAll('.sb-group').forEach(g => {
    if (localStorage.getItem('sb_open_'+g.dataset.key)==='true') g.classList.add('open');
});
function toggleGroup(g) {
    const o = g.classList.contains('open');
    document.querySelectorAll('.sb-group.open').forEach(x => x.classList.remove('open'));
    g.classList.toggle('open',!o);
    localStorage.setItem('sb_open_'+g.dataset.key,(!o).toString());
}

// ── بيانات JS ──
const ALL_CATS = <?= json_encode($categories) ?>;
const ALL_COLORS_INIT = <?= json_encode($colors) ?>;

// حالة الصفحة
// ── عملة الفرع والعملات المتاحة ──
const BASE_CUR_ID   = <?= $BASE_CUR_ID ?>;
const BASE_CUR_CODE = '<?= htmlspecialchars($BASE_CUR_CODE) ?>';
const BASE_CUR_SYM  = '<?= htmlspecialchars($BASE_CUR_SYM) ?>';
const CURRENCIES = <?= json_encode(array_map(function($c){
    return ['id'=>(int)$c['id'],'code'=>$c['code'],'symbol'=>$c['symbol'],'rate'=>(float)$c['rate_vs_branch']];
}, $allCurrencies)) ?>;

let sizeGroups   = [];   // [{key, label, sizes[], grpIdx}]  max 4
let _grpKeySeq = 0; // عداد فريد لتوليد key بدون تصادم
let activeGrpIdx = 0;    // الكروب النشط حالياً
let selColors    = [];   // [{id, name, hex}]
let allColors    = ALL_COLORS_INIT.map(c => ({id:c.id, name:c.name, hex:c.hex_code}));
let pricing      = [];   // [{group_key, cost_price, margin, sell_price}]

const GRP_COLORS = ['g1','g2','g3','g4'];
const GRP_NAMES  = ['الكروب الأول','الكروب الثاني','الكروب الثالث','الكروب الرابع'];

// ── AJAX helper ──
function post(data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k, v ?? ''));
    return fetch(location.href, {method:'POST', body:fd}).then(r => r.json());
}
function toast(msg, type='success') {
    const t = document.createElement('div');
    t.className = `alert alert-${type} shadow-sm`;
    t.style.cssText = 'position:fixed;top:76px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:240px;text-align:center;font-size:.83rem;padding:.5rem 1.2rem';
    t.innerHTML = `<i class="bi bi-${type==='success'?'check-circle-fill text-success':'exclamation-triangle-fill text-danger'} me-2"></i>${msg}`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3200);
}

// ── شبكة القياسات ──
function getActiveType() {
    // نوع الكروب النشط — أو قيمة الـ select إذا الكروب جديد
    if (sizeGroups[activeGrpIdx]) return sizeGroups[activeGrpIdx].type;
    return document.getElementById('pAgeType').value;
}

function onAgeTypeChange() {
    // تغيير نوع الكروب النشط
    const newType = document.getElementById('pAgeType').value;
    if (sizeGroups[activeGrpIdx]) {
        if (sizeGroups[activeGrpIdx].sizes.length > 0) {
            if (!confirm('تغيير نوع العمر سيحذف أرقام الكروب النشط. متابعة؟')) {
                document.getElementById('pAgeType').value = sizeGroups[activeGrpIdx].type;
                return;
            }
            sizeGroups[activeGrpIdx].sizes = [];
        }
        sizeGroups[activeGrpIdx].type = newType;
    }
    renderSizeGrid();
    updatePacket();
    updatePricing();
    updateSummary();
}

function renderSizeGrid() {
    const ageType = getActiveType();
    // sync select مع الكروب النشط
    document.getElementById('pAgeType').value = ageType;

    const lbl  = document.getElementById('sizeGridLabel');
    const grid = document.getElementById('sizeGrid');

    if (ageType === 'سنة') {
        lbl.textContent = 'سنوات (1–30) — انقر لتحديد:';
        const nums = Array.from({length:30}, (_,i) => i+1);
        grid.innerHTML = nums.map(n => {
            const grpIdx = getNumGrpIdx(n, 'سنة');
            const cls = grpIdx >= 0 ? `sz-btn sel grp${grpIdx+1}` : 'sz-btn';
            const activeHint = grpIdx < 0 && sizeGroups[activeGrpIdx] ? ` title="أضف للكروب ${activeGrpIdx+1}"` : '';
            return `<button class="${cls}"${activeHint} onclick="toggleSize(${n},'سنة')">${n}</button>`;
        }).join('');
    } else {
        lbl.textContent = 'أشهر (6–24، خطوة 6) — انقر لتحديد:';
        const months = [6,12,18,24];
        grid.innerHTML = months.map(m => {
            const grpIdx = getNumGrpIdx(m, 'شهر');
            const cls = grpIdx >= 0 ? `sz-btn sel grp${grpIdx+1}` : 'sz-btn';
            return `<button class="${cls}" style="width:40px" onclick="toggleSize(${m},'شهر')">${m}م</button>`;
        }).join('');
    }
    renderGrpPills();
}

function getNumGrpIdx(num, type) {
    for (let i=0; i<sizeGroups.length; i++) {
        if (sizeGroups[i].type === type && sizeGroups[i].sizes.includes(num)) return i;
    }
    return -1;
}

function toggleSize(num, type) {
    // نوع العمر الفعلي = من الكروب النشط
    if (sizeGroups[activeGrpIdx]) type = sizeGroups[activeGrpIdx].type;
    // هل هو مختار في أي كروب؟
    const existGrpIdx = getNumGrpIdx(num, type);
    if (existGrpIdx >= 0) {
        // إزالة من كروبه
        sizeGroups[existGrpIdx].sizes = sizeGroups[existGrpIdx].sizes.filter(s => s !== num);
        if (sizeGroups[existGrpIdx].sizes.length === 0) {
            sizeGroups.splice(existGrpIdx, 1);
            sizeGroups.forEach((g,i) => { g.grpIdx = i; });
            activeGrpIdx = Math.max(0, sizeGroups.length - 1);
        }
        renderSizeGrid();
        updatePacket();
        updatePricing();
        updateSummary();
        return;
    }

    // أضف للكروب النشط — إذا لا يوجد كروب نشط أنشئ واحداً
    if (sizeGroups.length === 0) {
        sizeGroups.push({key: 'g'+(_grpKeySeq++)+'_'+Date.now(), type, sizes:[], grpIdx: 0});
        activeGrpIdx = 0;
    }
    // تأكد أن الكروب النشط موجود
    if (!sizeGroups[activeGrpIdx]) activeGrpIdx = sizeGroups.length - 1;

    sizeGroups[activeGrpIdx].sizes.push(num);
    sizeGroups[activeGrpIdx].sizes.sort((a,b) => a-b);
    renderSizeGrid();
    updatePacket();
    updatePricing();
    updateSummary();
}

// ── بدء كروب جديد ──
function startNewGroup() {
    if (sizeGroups.length >= 4) {
        toast('الحد الأقصى 4 كروبات','danger'); return;
    }
    if (sizeGroups[activeGrpIdx] && sizeGroups[activeGrpIdx].sizes.length === 0) {
        toast('أضف أرقاماً للكروب الحالي أولاً','danger'); return;
    }
    // الكروب الجديد يبدأ بنوع العمر الافتراضي (سنة) — يمكن تغييره من الـ select
    const type = 'سنة';
    document.getElementById('pAgeType').value = type;
    sizeGroups.push({key: 'g'+(_grpKeySeq++)+'_'+Date.now(), type, sizes:[], grpIdx: sizeGroups.length});
    activeGrpIdx = sizeGroups.length - 1;
    renderSizeGrid();
    updatePricing();
    updateBtnNewGrp();
    toast('كروب جديد — اختر نوع العمر من القائمة ثم حدد الأرقام');
}

function updateBtnNewGrp() {
    const btn = document.getElementById('btnNewGrp');
    if (!btn) return;
    const disabled = sizeGroups.length >= 4;
    btn.style.opacity = disabled ? '0.4' : '1';
    btn.style.pointerEvents = disabled ? 'none' : 'auto';
    // اظهر الكروب النشط
    btn.title = sizeGroups.length >= 4 ? 'وصلت للحد الأقصى (4 كروبات)' : `الكروب النشط: ${activeGrpIdx + 1}`;
}

function renderGrpPills() {
    const container = document.getElementById('grpPills');
    if (!sizeGroups.length) {
        container.innerHTML = '<span style="font-size:.72rem;color:#94a3b8">لم يتم تحديد قياسات بعد</span>';
        return;
    }
    container.innerHTML = sizeGroups.map((grp,i) => {
        const isEmpty  = grp.sizes.length === 0;
        const minS = isEmpty ? '?' : Math.min(...grp.sizes);
        const maxS = isEmpty ? '?' : Math.max(...grp.sizes);
        const unit = grp.type === 'شهر' ? 'م' : ' سنة';
        const typeBadge = `<span style="font-size:.6rem;opacity:.75;margin-right:2px">(${grp.type})</span>`;
        const lbl  = isEmpty ? 'يُكتب...' : (minS === maxS ? `${minS}${unit}` : `${minS}–${maxS}${unit}`);
        const activeStyle = i === activeGrpIdx ? 'outline:2px solid #1e3a8a;outline-offset:1px;' : '';
        return `<span class="grp-pill ${GRP_COLORS[i]}" style="${activeStyle}" onclick="setActiveGrp(${i})" title="${i===activeGrpIdx?'الكروب النشط':'انقر للتبديل'}">
            ${i===activeGrpIdx ? '<i class="bi bi-pencil-fill" style="font-size:.6rem"></i>' : ''}
            ${GRP_NAMES[i]}: ${lbl} ${typeBadge}
            <i class="bi bi-x" style="font-size:.65rem;margin-right:2px" onclick="event.stopPropagation();removeGrp(${i})"></i>
        </span>`;
    }).join('');
    updateBtnNewGrp();
}

function removeGrp(idx) {
    sizeGroups.splice(idx,1);
    sizeGroups.forEach((g,i) => { g.grpIdx = i; });
    activeGrpIdx = Math.max(0, sizeGroups.length - 1);
    renderSizeGrid();
    updatePacket();
    updatePricing();
    updateSummary();
    updateBtnNewGrp();
}

function setActiveGrp(idx) {
    activeGrpIdx = idx;
    // sync الـ select مع نوع الكروب المختار
    if (sizeGroups[idx]) {
        document.getElementById('pAgeType').value = sizeGroups[idx].type;
    }
    renderGrpPills();
    renderSizeGrid();
}

function updatePacket() {
    const total = sizeGroups.reduce((s,g) => s + g.sizes.length, 0);
    document.getElementById('sbPacket').textContent = total ? total + ' قطعة' : '—';
}

// ── التسعير ──
function updatePricing() {
    const container = document.getElementById('pricingTable');
    if (!sizeGroups.length) {
        container.innerHTML = '<div class="text-muted" style="font-size:.78rem">اختر القياسات أولاً لظهور جدول التسعير</div>';
        return;
    }
    // حافظ على القيم المدخلة مسبقاً
    const oldPricing = {};
    pricing.forEach(p => { oldPricing[p.group_key] = p; });

    pricing = sizeGroups.map(grp => {
        const prev = oldPricing[grp.key] || {};
        return {
            group_key:  grp.key,
            packet_qty: grp.sizes.length,
            cost_price: prev.cost_price !== undefined ? prev.cost_price : '',
            margin:     prev.margin     !== undefined ? prev.margin     : 30,
            sell_price: prev.sell_price !== undefined ? prev.sell_price : '',
            currency_id:prev.currency_id!== undefined ? prev.currency_id: BASE_CUR_ID,
            exchange_rate: prev.exchange_rate !== undefined ? prev.exchange_rate : 1,
            rate_dir: prev.rate_dir || 'fwd' // fwd: 1 base=X chosen | inv: 1 chosen=X base
        };
    });

    container.innerHTML = `
    <table class="price-tbl w-100">
        <thead><tr>
            <th>الكروب</th>
            <th>قطع الباكيت</th>
            <th>العملة</th>
            <th>سعر الصرف</th>
            <th>سعر الشراء</th>
            <th>نسبة المحل %</th>
            <th>سعر البيع</th>
        </tr></thead>
        <tbody>
        ${sizeGroups.map((grp,i) => {
            const pr = pricing[i];
            const unit = grp.type === 'شهر' ? 'م' : ' سنة';
            const isEmpty = grp.sizes.length === 0;
            const minS = isEmpty ? '?' : Math.min(...grp.sizes);
            const maxS = isEmpty ? '?' : Math.max(...grp.sizes);
            const lbl = isEmpty ? 'يُكتب...' : (minS===maxS ? `${minS}${unit}` : `${minS}–${maxS}${unit}`);
            const isForeign = pr.currency_id != BASE_CUR_ID;
            return `<tr>
                <td><span class="grp-pill ${GRP_COLORS[i]}">${lbl}</span></td>
                <td>
                    <input type="number" readonly
                        value="${grp.sizes.length||0}"
                        id="packetInp_${i}"
                        style="width:56px;background:#f8fafc;color:#1e293b;font-weight:600;text-align:center">
                </td>
                <td>
                    <select style="width:82px;font-size:.75rem" onchange="onPriceCurrencyChange(${i},this.value)">
                        ${CURRENCIES.map(c=>`<option value="${c.id}" ${c.id==pr.currency_id?'selected':''}>${c.code}</option>`).join('')}
                    </select>
                </td>
                <td style="min-width:150px">
                    <div class="d-flex gap-1 align-items-center">
                        <button type="button" onclick="toggleRateDir(${i})" title="عكس الاتجاه"
                            style="border:1px solid #e2e8f0;border-radius:6px;background:#fff;width:24px;height:24px;font-size:.7rem;flex-shrink:0">
                            <i class="bi bi-arrow-left-right"></i>
                        </button>
                        <input type="number" min="0.0000000001" step="any"
                            value="${pr.rate_dir==='inv' ? (1/pr.exchange_rate).toFixed(10) : pr.exchange_rate}"
                            id="rateInp_${i}"
                            style="width:82px;${isForeign?'':'background:#f8fafc;color:#94a3b8'}"
                            ${isForeign?'':'readonly'}
                            oninput="updateSellPrice(${i},this.value,'rate')">
                        <button type="button" onclick="fetchRate(${i})" title="جلب من الإنترنت"
                            style="border:1px solid #e2e8f0;border-radius:6px;background:#fff;width:24px;height:24px;font-size:.7rem;flex-shrink:0" ${isForeign?'':'disabled'}>
                            <i class="bi bi-arrow-repeat" id="rateIcon_${i}"></i>
                        </button>
                    </div>
                    <div style="font-size:.63rem;color:#94a3b8;margin-top:2px" id="rateHint_${i}">
                        1 ${pr.rate_dir==='inv' ? (CURRENCIES.find(c=>c.id==pr.currency_id)?.code||'') : BASE_CUR_CODE}
                        = ${pr.rate_dir==='inv' ? BASE_CUR_CODE : (CURRENCIES.find(c=>c.id==pr.currency_id)?.code||'')}
                    </div>
                </td>
                <td><input type="number" min="0" step="0.01" placeholder="0.00"
                    value="${pr.cost_price}"
                    oninput="updateSellPrice(${i},this.value,'cost')"></td>
                <td><input type="number" min="0" max="100" placeholder="30"
                    value="${pr.margin}"
                    oninput="updateSellPrice(${i},this.value,'margin')"></td>
                <td><input type="number" readonly placeholder="0.00"
                    value="${pr.sell_price}"
                    style="background:#f8fafc;color:#64748b"
                    id="sellInp_${i}"></td>
            </tr>`;
        }).join('')}
        </tbody>
    </table>
    <div style="font-size:.7rem;color:#94a3b8;margin-top:6px">
        <i class="bi bi-info-circle me-1"></i>
        الأسعار تُحفظ بعملة الفرع الأساسية (${BASE_CUR_CODE}) — إذا اخترت عملة أخرى أدخل السعر بها وحدد سعر الصرف للتحويل التلقائي
    </div>`;
    checkStatus();
}

function onPriceCurrencyChange(idx, curId){
    pricing[idx].currency_id = parseInt(curId);
    const cur = CURRENCIES.find(c=>c.id==curId);
    pricing[idx].exchange_rate = cur ? cur.rate : 1;
    pricing[idx].rate_dir = 'fwd';
    if (parseInt(curId) === BASE_CUR_ID) pricing[idx].exchange_rate = 1;
    updatePricing();
}

// عكس اتجاه عرض سعر الصرف (لا يغيّر القيمة الفعلية المخزّنة، فقط طريقة الإدخال/العرض)
function toggleRateDir(idx){
    pricing[idx].rate_dir = pricing[idx].rate_dir === 'fwd' ? 'inv' : 'fwd';
    updatePricing();
}
// جلب سعر الصرف تلقائياً بالاتجاه المعروض حالياً
async function fetchRate(idx){
    const icon = document.getElementById(`rateIcon_${idx}`);
    if (icon) icon.classList.add('spin');
    try {
        const curId = pricing[idx].currency_id;
        const cur = CURRENCIES.find(c=>c.id==curId);
        if (!cur) return;
        const resp = await fetch(`https://api.exchangerate-api.com/v4/latest/${BASE_CUR_CODE}`);
        const data = await resp.json();
        const rate = data.rates[cur.code]; // 1 BASE = rate CHOSEN
        if (rate) {
            pricing[idx].exchange_rate = rate; // دايماً نخزّن canonical: 1 base = X chosen
            updatePricing();
        }
    } catch(e){} finally { if (icon) icon.classList.remove('spin'); }
}

function updateSellPrice(idx, val, field) {
    if (field === 'cost')   pricing[idx].cost_price   = parseFloat(val) || 0;
    if (field === 'margin') pricing[idx].margin       = parseFloat(val) || 0;
    if (field === 'rate') {
        const v = Math.max(0.0000000001, parseFloat(val) || 1);
        // لو الاتجاه المعروض معكوس (1 مختارة = X أساسية) نحوّله لصيغة canonical (1 أساسية = X مختارة) بدقة 10 أرقام
        pricing[idx].exchange_rate = pricing[idx].rate_dir === 'inv'
            ? parseFloat((1 / v).toFixed(10))
            : v;
    }
    const cost   = parseFloat(pricing[idx].cost_price) || 0;
    const margin = parseFloat(pricing[idx].margin) || 0;
    const sell   = cost > 0 ? (cost * (1 + margin/100)).toFixed(2) : '';
    pricing[idx].sell_price = sell;
    const inp = document.getElementById(`sellInp_${idx}`);
    if (inp) inp.value = sell;
    checkStatus();
}

// ── الألوان ──
// selColors يخزن الـ index في allColors للتأكد من الدقة
let selColorIdxs = []; // array of indices in allColors

function pickColor(i) {
    const pos = selColorIdxs.indexOf(i);
    if (pos >= 0) selColorIdxs.splice(pos, 1);
    else          selColorIdxs.push(i);
    // تحديث selColors من selColorIdxs
    selColors = selColorIdxs.map(idx => ({id: allColors[idx].id, name: allColors[idx].name, hex: allColors[idx].hex}));
    renderColorGrid();
    updateBarcodes();
    updateSummary();
    checkStatus();
}

function renderColorGrid() {
    const grid = document.getElementById('colorGrid');
    grid.innerHTML = allColors.map((c, i) => {
        const isSel  = selColorIdxs.includes(i);
        const border = (c.hex.toLowerCase() === '#f5f0e8' || c.hex.toLowerCase() === '#ffffff')
            ? 'border:1px solid #e2e8f0;' : '';
        const ring   = isSel ? 'outline:3px solid #1e3a8a;outline-offset:2px;' : '';
        return `<div class="color-dot ${isSel ? 'sel' : ''}"
            style="background:${c.hex};${border}${ring}"
            title="${c.name}"
            onclick="pickColor(${i})"></div>`;
    }).join('');
    document.getElementById('colorSelected').textContent =
        selColorIdxs.length
            ? 'المحدد: ' + selColorIdxs.map(i => allColors[i].name).join('، ')
            : 'لم يتم تحديد ألوان';
}

// ── الباركودات ──
function updateBarcodes() {
    const model     = (document.getElementById('pModel').value || '').trim().toUpperCase();
    const container = document.getElementById('barcodeList');
    if (!sizeGroups.length) {
        container.innerHTML = '<div class="field-hint">حدد الكروبات أولاً لتوليد الباركودات</div>';
        return;
    }
    const GRP_CLR = ['#1e3a8a','#065f46','#7c2d12','#4c1d95'];
    // باركود واحد لكل كروب — مشترك بين جميع الألوان
    const rows = sizeGroups.map((grp, i) => {
        const unit    = grp.type === 'شهر' ? 'م' : ' سنة';
        const isEmpty = grp.sizes.length === 0;
        const lbl     = isEmpty ? 'يُكتب...'
            : (Math.min(...grp.sizes) === Math.max(...grp.sizes)
                ? `${Math.min(...grp.sizes)}${unit}`
                : `${Math.min(...grp.sizes)}–${Math.max(...grp.sizes)}${unit}`);
        const grpNum = String(i + 1).padStart(2, '0');
        const bc     = model ? `${model}-G${grpNum}` : `AUTO-G${grpNum}`;
        const clr    = GRP_CLR[i] || '#334155';
        const clrNames = selColors.length
            ? `<span style="font-size:.68rem;color:#64748b;margin-right:6px">يشمل: ${selColors.map(c=>c.name).join('، ')}</span>`
            : '';
        return `<div class="bc-row" style="flex-wrap:wrap;gap:4px">
            <div class="bc-dot" style="background:${clr};border-radius:4px;flex-shrink:0"></div>
            <div class="bc-name" style="color:${clr};font-weight:600;width:auto;min-width:80px">كروب ${i+1}: ${lbl}</div>
            <input class="bc-val" value="${bc}" readonly style="flex:1;min-width:120px">
            ${clrNames}
        </div>`;
    }).join('');
    container.innerHTML = rows || '<div class="field-hint">لم تُحدد كروبات بعد</div>';
}

// ── الملخص ──
function updateSummary() {
    const catSel = document.getElementById('pCat');
    const catTxt = catSel.options[catSel.selectedIndex]?.text || '—';
    document.getElementById('sbCat').textContent     = catTxt.replace('↳ ','') || '—';
    document.getElementById('sbFabric').textContent  = document.getElementById('pFabric').value || '—';
    document.getElementById('sbGrps').textContent    = sizeGroups.length;
    document.getElementById('sbColors').textContent  = selColors.length;
    const totalSizes = sizeGroups.reduce((s,g) => s+g.sizes.length, 0);
    document.getElementById('sbVariants').textContent = totalSizes * selColors.length;
    // الباكيت: عدد القطع = عدد الأرقام في كل كروب
    const sbPk = document.getElementById('sbPacket');
    if (sbPk) {
        if (!sizeGroups.length) { sbPk.textContent = '—'; }
        else {
            sbPk.innerHTML = sizeGroups.map((g,i) => {
                if (!g || g.sizes.length === 0) return '';
                const unit = g.type==='شهر'?'م':' سنة';
                const lbl = g.sizes.length===1 ? `${g.sizes[0]}${unit}` : `${Math.min(...g.sizes)}–${Math.max(...g.sizes)}${unit}`;
                return `<span style="display:block;font-size:.7rem">${lbl}: <b>${g.sizes.length}</b> قطعة</span>`;
            }).join('');
        }
    }
    // تحديث خلايا الباكيت في جدول التسعير إذا كان مرسوماً
    sizeGroups.forEach((g,i) => {
        const inp = document.getElementById('packetInp_'+i);
        if (inp) inp.value = g.sizes.length;
    });
}

// ── حالة الإدخال ──
function checkStatus() {
    const checks = [
        {id:'ck1', ok: !!(document.getElementById('pName').value.trim() && document.getElementById('pModel').value.trim())},
        {id:'ck2', ok: !!(document.getElementById('pCat').value)},
        {id:'ck3', ok: sizeGroups.length > 0},
        {id:'ck4', ok: selColors.length > 0},
        {id:'ck5', ok: pricing.length > 0 && pricing.every(p => parseFloat(p.cost_price) > 0)},
    ];
    checks.forEach(c => {
        const el = document.getElementById(c.id);
        if (!el) return;
        el.querySelector('i').className = c.ok
            ? 'bi bi-check-circle-fill text-success'
            : 'bi bi-circle text-secondary';
        el.querySelector('i').style.fontSize = '.85rem';
    });
}

// ── مودال الفئة ──
function showCatModal() { document.getElementById('catModal').classList.add('show'); }
function hideCatModal() { document.getElementById('catModal').classList.remove('show'); }
function saveCat() {
    const name = document.getElementById('catName').value.trim();
    const parent = document.getElementById('catParent').value;
    if (!name) { toast('اسم الفئة مطلوب','danger'); return; }
    post({_action:'add_category', name, parent_id: parent}).then(d => {
        if (!d.ok) { toast(d.msg,'danger'); return; }
        // أضف للـ select
        const sel = document.getElementById('pCat');
        const opt = document.createElement('option');
        opt.value = d.id;
        opt.textContent = (d.parent_id ? '↳ ' : '') + d.name;
        opt.selected = true;
        sel.appendChild(opt);
        hideCatModal();
        document.getElementById('catName').value = '';
        toast('تمت إضافة الفئة');
        updateSummary();
        checkStatus();
    });
}

// ── مودال اللون ──
function showColorModal() {
    document.getElementById('colorModal').classList.add('show');
    document.getElementById('colorHex').addEventListener('input', function(){
        document.getElementById('colorHexTxt').value = this.value;
    });
}
function hideColorModal() { document.getElementById('colorModal').classList.remove('show'); }
function saveColor() {
    const name = document.getElementById('colorName').value.trim();
    const hex  = document.getElementById('colorHex').value;
    if (!name) { toast('اسم اللون مطلوب','danger'); return; }
    post({_action:'add_color', name, hex}).then(d => {
        if (!d.ok) {
            // حتى لو فشل الحفظ بالجدول نضيفه محلياً
        }
        const newId = parseInt(d.id) || 0;
        allColors.push({id: newId, name, hex});
        selColorIdxs.push(allColors.length - 1);
        selColors = selColorIdxs.map(idx => ({id: allColors[idx].id, name: allColors[idx].name, hex: allColors[idx].hex}));
        renderColorGrid();
        hideColorModal();
        document.getElementById('colorName').value = '';
        document.getElementById('colorHex').value = '#000000';
        document.getElementById('colorHexTxt').value = '#000000';
        toast('تمت إضافة اللون');
    });
}

// ── نسخ آخر منتج ──
function copyLastProduct() {
    if (!confirm('نسخ بيانات آخر منتج مضاف؟ سيتم استبدال البيانات الحالية.')) return;
    post({_action:'get_last_product'}).then(d => {
        if (!d.ok) { toast(d.msg,'danger'); return; }
        const p = d.data;
        document.getElementById('pName').value   = p.name + ' (نسخة)';
        document.getElementById('pModel').value  = '';
        document.getElementById('pFabric').value = p.fabric_type || '';
        document.getElementById('pAgeType').value = p.age_type || 'سنة';
        if (p.category_id) document.getElementById('pCat').value = p.category_id;
        sizeGroups = []; selColors = []; selColorIdxs = []; pricing = [];
        renderSizeGrid();
        renderColorGrid();
        updatePricing();
        updateSummary();
        checkStatus();
        toast('تم نسخ بيانات المنتج — عدّل ما يلزم وأضف القياسات والألوان');
    });
}

// ── حفظ المنتج ──
function saveProduct() {
    const name  = document.getElementById('pName').value.trim();
    const model = document.getElementById('pModel').value.trim();
    if (!name || !model) { toast('اسم المنتج ورقم الموديل مطلوبان','danger'); return; }
    if (!sizeGroups.length) { toast('يرجى اختيار القياسات','danger'); return; }
    if (!selColors.length)  { toast('يرجى اختيار لون واحد على الأقل','danger'); return; }

    document.getElementById('saveTxt').style.opacity = '0';
    document.getElementById('saveSpin').style.display = 'inline-block';
    if (document.getElementById('saveTxt2')) document.getElementById('saveTxt2').style.opacity = '0';
    if (document.getElementById('saveSpin2')) document.getElementById('saveSpin2').style.display = 'inline-block';

    post({
        _action:       'save_product',
        id:            document.getElementById('pId').value,
        name,
        model_number:  model,
        category_id:   document.getElementById('pCat').value,
        fabric_type:   document.getElementById('pFabric').value,
        supplier_id:   document.getElementById('pSupplier').value,
        warehouse_id:  document.getElementById('pWarehouse').value,
        age_type:      document.getElementById('pAgeType').value,
        notes:         document.getElementById('pNotes').value,
        min_quantity:  document.getElementById('pMinQty').value,
        size_groups:   JSON.stringify(sizeGroups),
        colors:        JSON.stringify(selColors),
        pricing:       JSON.stringify(pricing),
    }).then(d => {
        document.getElementById('saveTxt').style.opacity = '1';
        document.getElementById('saveSpin').style.display = 'none';
        if (document.getElementById('saveTxt2')) document.getElementById('saveTxt2').style.opacity = '1';
        if (document.getElementById('saveSpin2')) document.getElementById('saveSpin2').style.display = 'none';
        if (d.ok) {
            toast('تم حفظ المنتج بنجاح');
            setTimeout(() => window.location.href = 'products.php', 1400);
        } else toast(d.msg,'danger');
    });
}

// ── مراقبة حقول النص لتحديث الحالة ──
['pName','pModel','pCat','pFabric'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', () => { updateSummary(); checkStatus(); });
    if (el && el.tagName==='SELECT') el.addEventListener('change', () => { updateSummary(); checkStatus(); });
});
document.getElementById('pCat').addEventListener('change', () => { updateSummary(); checkStatus(); });

// ── المورد ──
function showSupplierModal() { document.getElementById('supplierModal').classList.add('show'); }
function hideSupplierModal() {
    document.getElementById('supplierModal').classList.remove('show');
    ['spName','spContact','spPhone','spNotes'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('spType').value = 'wholesaler';
}
function saveSupplier() {
    const name    = document.getElementById('spName').value.trim();
    const contact = document.getElementById('spContact').value.trim();
    const phone   = document.getElementById('spPhone').value.trim();
    const type    = document.getElementById('spType').value;
    const notes   = document.getElementById('spNotes').value.trim();
    if (!name) { toast('اسم المورد مطلوب','danger'); return; }
    post({_action:'add_supplier', name, contact_person:contact, phone, type, notes}).then(d => {
        if (!d.ok) { toast(d.msg,'danger'); return; }
        const sel = document.getElementById('pSupplier');
        const opt = document.createElement('option');
        opt.value = d.id;
        opt.dataset.phone = d.phone || '';
        opt.dataset.type  = d.type  || '';
        opt.textContent   = d.name + (contact ? ' — '+contact : '');
        opt.selected = true;
        sel.appendChild(opt);
        onSupplierChange();
        hideSupplierModal();
        toast('تمت إضافة المورد بنجاح');
    });
}
function onSupplierChange() {
    const sel  = document.getElementById('pSupplier');
    const opt  = sel.options[sel.selectedIndex];
    const wrap = document.getElementById('supplierInfoWrap');
    const ph   = document.getElementById('supplierPhone');
    if (sel.value && opt && opt.dataset.phone) {
        wrap.style.display = 'block';
        ph.textContent = opt.dataset.phone;
    } else {
        wrap.style.display = 'none';
    }
}

// ── الصورة ──
function onImgSelect(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const maxMB = 50;
    if (file.size > maxMB * 1024 * 1024) {
        toast('حجم الصورة يتجاوز ' + maxMB + 'MB','danger'); return;
    }
    const reader = new FileReader();
    reader.onload = function(e) {
        const box      = document.getElementById('uploadBox');
        const icon     = document.getElementById('uploadIcon');
        const txt      = document.getElementById('uploadTxt');
        const overlay  = document.getElementById('imgOverlay');
        const preview  = document.getElementById('imgPreviewInner');
        const toast    = document.getElementById('imgToast');
        const toastNm  = document.getElementById('imgToastName');

        // معاينة
        preview.src = e.target.result;
        preview.style.display = 'block';
        box.classList.add('has-img');
        icon.style.display = 'none';
        txt.style.display  = 'none';
        overlay.style.display = 'flex';

        // إشعار
        toast.classList.add('show');
        toastNm.textContent = file.name + ' (' + (file.size/1024).toFixed(0) + ' KB)';
    };
    reader.readAsDataURL(file);
}

function removeImg() {
    document.getElementById('pImg').value = '';
    const box     = document.getElementById('uploadBox');
    const icon    = document.getElementById('uploadIcon');
    const txt     = document.getElementById('uploadTxt');
    const overlay = document.getElementById('imgOverlay');
    const preview = document.getElementById('imgPreviewInner');
    const toast   = document.getElementById('imgToast');

    box.classList.remove('has-img');
    icon.style.display  = 'block';
    txt.style.display   = 'block';
    overlay.style.display = 'none';
    preview.src = '';
    toast.classList.remove('show');
}

// ── تهيئة ──
renderSizeGrid();
renderColorGrid();
updatePricing();
updateSummary();
checkStatus();
updateBtnNewGrp();
</script>
</body>
</html>
