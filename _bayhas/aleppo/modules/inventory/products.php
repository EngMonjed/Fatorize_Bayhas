<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
/**
 * products.php — إدارة المنتجات (فرع البيع)
 * المسار: /bayhas/aleppo/modules/inventory/products.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('inventory.products', 'view');

$branchName = $_SESSION['branch_name'] ?? 'الفرع';
$TS = $_SESSION['table_suffix'];
$TP = "products_{$TS}";
$TC = "product_categories_{$TS}";
$TV = "product_variants_{$TS}";
$TSZ = "product_sizes_{$TS}";
$TW = "warehouses_{$TS}";
$TWI = "warehouse_items_{$TS}";
$TCL = "product_colors_{$TS}";

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        // ── جلب تفاصيل منتج للعرض ──
        if ($act === 'get_product_detail') {
            $id = (int) $_POST['id'];
            $st = $pdo->prepare("SELECT p.*, c.name AS cat_name, sp.name AS supplier_name
                FROM `{$TP}` p
                LEFT JOIN `{$TC}` c ON c.id=p.category_id
                LEFT JOIN `product_suppliers_{$TS}` sp ON sp.id=p.supplier_id
                WHERE p.id=?");
            $st->execute([$id]);
            $prod = $st->fetch();
            if (!$prod)
                throw new Exception('المنتج غير موجود');

            // المقاسات مجمّعة بكروبات (بحسب selling_price)
            $szSt = $pdo->prepare("SELECT * FROM `{$TSZ}` WHERE product_id=? AND is_active=1 ORDER BY sort_order");
            $szSt->execute([$id]);
            $sizes = $szSt->fetchAll();
            $grpMap = [];
            foreach ($sizes as $s) {
                $key = (string) $s['selling_price'];
                if (!isset($grpMap[$key]))
                    $grpMap[$key] = ['sizes' => [], 'selling_price' => $s['selling_price'], 'packet_qty' => 0];
                $grpMap[$key]['sizes'][] = $s['size'];
                $grpMap[$key]['packet_qty'] = count($grpMap[$key]['sizes']);
            }
            $prod['groups'] = array_values($grpMap);

            // الألوان عبر color_id من جدول الألوان
            $clSt = $pdo->prepare("SELECT DISTINCT pc.id, pc.name, pc.hex_code
                FROM `{$TV}` v
                JOIN `{$TCL}` pc ON pc.id = v.color_id
                WHERE v.product_id=? AND v.is_active=1
                ORDER BY pc.name");
            $clSt->execute([$id]);
            $prod['colors'] = $clSt->fetchAll();

            // الكمية الكلية وحد التنبيه من warehouse_items
            $wqSt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) AS total_qty, MAX(min_quantity) AS min_qty
                FROM `{$TWI}` WHERE product_id=?");
            $wqSt->execute([$id]);
            $wqRow = $wqSt->fetch();
            $prod['total_qty'] = (int) ($wqRow['total_qty'] ?? 0);
            $prod['min_qty'] = (int) ($wqRow['min_qty'] ?? 0);

            echo json_encode(['ok' => true, 'data' => $prod]);
        }

        // ── جلب منتج للتعديل ──
        elseif ($act === 'get_product') {
            $id = (int) $_POST['id'];
            $prod = $pdo->prepare("SELECT * FROM `{$TP}` WHERE id=?");
            $prod->execute([$id]);
            $p = $prod->fetch();
            if (!$p)
                throw new Exception('المنتج غير موجود');
            // المقاسات
            $sz = $pdo->prepare("SELECT * FROM `{$TSZ}` WHERE product_id=? ORDER BY sort_order");
            $sz->execute([$id]);
            $p['sizes'] = $sz->fetchAll();
            // المتغيرات
            $vr = $pdo->prepare("SELECT v.*, s.size AS size_label
                FROM `{$TV}` v LEFT JOIN `{$TSZ}` s ON s.id=v.size_id
                WHERE v.product_id=? AND v.is_active=1 ORDER BY v.color_id, s.sort_order");
            $vr->execute([$id]);
            $p['variants'] = $vr->fetchAll();
            echo json_encode(['ok' => true, 'data' => $p]);
        }

        // ── حفظ منتج (إنشاء/تعديل) ──
        elseif ($act === 'save_product') {
            requirePermission('inventory.products', can('inventory.products', 'create') ? 'create' : 'edit');
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $model = trim($_POST['model_number'] ?? '');
            $cat_id = (int) ($_POST['category_id'] ?? 0);
            $wh_id = (int) ($_POST['warehouse_id'] ?? 0);
            $age_type = $_POST['age_type'] ?? null;
            $age_from = $_POST['age_from'] !== '' ? (int) $_POST['age_from'] : null;
            $age_to = $_POST['age_to'] !== '' ? (int) $_POST['age_to'] : null;
            $packet_size = $_POST['packet_size'] !== '' ? (int) $_POST['packet_size'] : null;
            $notes = trim($_POST['notes'] ?? '');
            $sizes = json_decode($_POST['sizes'] ?? '[]', true);
            $variants = json_decode($_POST['variants'] ?? '[]', true);

            if (!$name || !$model)
                throw new Exception('الاسم ورقم الموديل مطلوبان');

            if ($id) {
                // تعديل
                requirePermission('inventory.products', 'edit');
                $pdo->prepare("UPDATE `{$TP}` SET name=?,model_number=?,category_id=?,
                    age_type=?,age_from=?,age_to=?,packet_size=?,notes=?,updated_by=?,updated_at=NOW()
                    WHERE id=?")
                    ->execute([$name, $model, $cat_id, $age_type, $age_from, $age_to, $packet_size, $notes, $_SESSION['user_id'], $id]);
            } else {
                // إنشاء
                requirePermission('inventory.products', 'create');
                // تحقق من تكرار الموديل
                $dup = $pdo->prepare("SELECT id FROM `{$TP}` WHERE model_number=?");
                $dup->execute([$model]);
                if ($dup->fetch())
                    throw new Exception("رقم الموديل {$model} موجود مسبقاً");
                $pdo->prepare("INSERT INTO `{$TP}` (name,model_number,category_id,age_type,age_from,age_to,packet_size,notes,created_by)
                    VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$name, $model, $cat_id, $age_type, $age_from, $age_to, $packet_size, $notes, $_SESSION['user_id']]);
                $id = (int) $pdo->lastInsertId();
            }

            // حفظ المقاسات
            $pdo->prepare("DELETE FROM `{$TSZ}` WHERE product_id=?")->execute([$id]);
            foreach ($sizes as $si => $sz) {
                $szLabel = trim($sz['size'] ?? '');
                if (!$szLabel)
                    continue;
                $pdo->prepare("INSERT INTO `{$TSZ}` (product_id,size,sort_order,selling_price,full_packet_price,is_active)
                    VALUES (?,?,?,?,?,1)")
                    ->execute([
                        $id,
                        $szLabel,
                        $si,
                        (float) ($sz['selling_price'] ?? 0),
                        $sz['full_packet_price'] ? (float) $sz['full_packet_price'] : null
                    ]);
            }

            // حفظ المتغيرات (لون + مقاس)
            foreach ($variants as $vr) {
                $color = trim($vr['color'] ?? '');
                $size_id = (int) ($vr['size_id'] ?? 0);
                $barcode = trim($vr['barcode'] ?? '') ?: null;
                if (!$color || !$size_id)
                    continue;
                // UPSERT
                $pdo->prepare("INSERT INTO `{$TV}` (product_id,size_id,color,barcode,created_by)
                    VALUES (?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE color=VALUES(color),barcode=VALUES(barcode)")
                    ->execute([$id, $size_id, $color, $barcode, $_SESSION['user_id']]);
            }

            // ربط المنتج بالمستودع (warehouse_items للمتغيرات)
            if ($wh_id) {
                $vr_stmt = $pdo->prepare("SELECT id FROM `{$TV}` WHERE product_id=? AND is_active=1");
                $vr_stmt->execute([$id]);
                foreach ($vr_stmt->fetchAll() as $vr) {
                    $pdo->prepare("INSERT IGNORE INTO `{$TWI}` (warehouse_id,variant_id,product_id,quantity)
                        VALUES (?,?,?,0)")
                        ->execute([$wh_id, $vr['id'], $id]);
                }
            }

            echo json_encode(['ok' => true, 'msg' => 'تم حفظ المنتج', 'id' => $id]);
        }

        // ── حذف/تعطيل منتج ──
        elseif ($act === 'toggle_product') {
            requirePermission('inventory.products', 'edit');
            $id = (int) $_POST['id'];
            $pdo->prepare("UPDATE `{$TP}` SET is_active=IF(is_active=1,0,1),updated_at=NOW() WHERE id=?")
                ->execute([$id]);
            $st = $pdo->prepare("SELECT is_active FROM `{$TP}` WHERE id=?");
            $st->execute([$id]);
            echo json_encode(['ok' => true, 'is_active' => (int) $st->fetchColumn()]);
        }

        // ── جلب مقاسات منتج ──
        elseif ($act === 'get_sizes') {
            $id = (int) $_POST['product_id'];
            $sz = $pdo->prepare("SELECT * FROM `{$TSZ}` WHERE product_id=? AND is_active=1 ORDER BY sort_order");
            $sz->execute([$id]);
            echo json_encode(['ok' => true, 'data' => $sz->fetchAll()]);
        } else
            throw new Exception('إجراء غير معروف');

    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── جلب البيانات ──────────────────────────────────────────────────
// فئات فرع البيع: نهائية + استهلاكية فقط (parent_id IS NULL)
$categories = $pdo->query("SELECT * FROM `{$TC}` WHERE is_active=1 ORDER BY parent_id, id")->fetchAll();

// مستودعات الفرع
$warehouses = $pdo->query("SELECT * FROM `{$TW}` WHERE is_active=1 ORDER BY id")->fetchAll();

// فلتر
$sel_cat = (int) ($_GET['cat'] ?? 0);
$search = trim($_GET['q'] ?? '');

$where = "WHERE p.is_active=1";
$params = [];
if ($sel_cat) {
    // نجلب كل الفئات الفرعية أيضاً
    $where .= " AND (p.category_id=? OR c.parent_id=?)";
    $params[] = $sel_cat;
    $params[] = $sel_cat;
}
if ($search) {
    $where .= " AND (p.name LIKE ? OR p.model_number LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$prod_stmt = $pdo->prepare("
    SELECT p.*,
        c.name AS cat_name, c.parent_id AS cat_parent,
        pc.name AS parent_cat_name,
        COUNT(DISTINCT v.id) AS variant_count,
        COALESCE(SUM(wi.quantity),0) AS total_stock,
        GROUP_CONCAT(DISTINCT v.color_id ORDER BY v.color_id SEPARATOR ',') AS color_ids
    FROM `{$TP}` p
    LEFT JOIN `{$TC}` c ON c.id=p.category_id
    LEFT JOIN `{$TC}` pc ON pc.id=c.parent_id
    LEFT JOIN `{$TV}` v ON v.product_id=p.id AND v.is_active=1
    LEFT JOIN `{$TWI}` wi ON wi.product_id=p.id
    {$where}
    GROUP BY p.id
    ORDER BY c.parent_id, p.name
    LIMIT 200
");
$prod_stmt->execute($params);
$products = $prod_stmt->fetchAll();

// إحصائيات
$total = count($products);
$in_stock = count(array_filter($products, function ($p) {
    return $p['total_stock'] > 0;
}));

// مخزون منخفض — نحتاج min_quantity من warehouse_items
$low_stmt = $pdo->query("SELECT COUNT(DISTINCT product_id) FROM `{$TWI}`
    WHERE quantity > 0 AND quantity <= min_quantity AND min_quantity > 0");
$low_count = (int) $low_stmt->fetchColumn();

$out_stmt = $pdo->query("SELECT COUNT(DISTINCT p.id) FROM `{$TP}` p
    LEFT JOIN `{$TWI}` wi ON wi.product_id=p.id
    WHERE p.is_active=1 GROUP BY p.id HAVING COALESCE(SUM(wi.quantity),0)=0");
$out_count = count($out_stmt->fetchAll());

// قيمة المخزون لكل مستودع
$wh_stats = [];
foreach ($warehouses as $wh) {
    $st = $pdo->prepare("SELECT COUNT(DISTINCT product_id) AS items,
        COALESCE(SUM(quantity),0) AS qty,
        0 AS val
        FROM `{$TWI}` WHERE warehouse_id=?");
    $st->execute([$wh['id']]);
    $wh_stats[$wh['id']] = $st->fetch();
}

// الفئات الجذر (للفلاتر)
$root_cats = array_filter($categories, function ($c) {
    return !$c['parent_id'];
});

// ألوان التصنيفات
$catColors = [
    1 => ['#E6F1FB', '#0C447C', '#85B7EB'], // نهائية — أزرق
    2 => ['#FAEEDA', '#633806', '#EF9F27'], // استهلاكية — أمبر
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>المنتجات — <?= htmlspecialchars($branchName) ?></title>
    <link rel="icon" href="/bayhas/assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="/bayhas/assets/css/layout.css" rel="stylesheet">
    <style>
        .stat-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            padding: .85rem 1.1rem;
            display: flex;
            align-items: center;
            gap: .85rem;
            transition: all .2s
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, .06)
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0
        }

        .stat-val {
            font-size: 1.3rem;
            font-weight: 700;
            line-height: 1.1
        }

        .stat-lbl {
            font-size: .72rem;
            color: #64748b;
            margin-top: .15rem
        }

        .cat-chip {
            padding: 5px 14px;
            border-radius: 20px;
            font-size: .8rem;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid #e2e8f0;
            background: #fff;
            color: #64748b;
            transition: all .15s;
            text-decoration: none;
            display: inline-block
        }

        .cat-chip:hover {
            border-color: #3b82f6;
            color: #1d4ed8
        }

        .cat-chip.act-final {
            background: #E6F1FB;
            color: #0C447C;
            border-color: #85B7EB
        }

        .cat-chip.act-cons {
            background: #FAEEDA;
            color: #633806;
            border-color: #EF9F27
        }

        .cat-chip.act-all {
            background: #1e3a8a;
            color: #fff;
            border-color: #1e3a8a
        }

        .sec-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            margin-bottom: 1.1rem
        }

        .sec-card table {
            margin: 0;
            font-size: .83rem
        }

        .sec-card th {
            background: #f8fafc;
            color: #64748b;
            font-size: .75rem;
            font-weight: 600;
            border: none;
            padding: .6rem .9rem;
            white-space: nowrap
        }

        .sec-card td {
            padding: .55rem .9rem;
            vertical-align: middle;
            border-top: 1px solid #f1f5f9
        }

        .sec-card tbody tr:hover td {
            background: #f8fafc
        }

        .sec-hdr {
            padding: .7rem 1.1rem;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: .5rem
        }

        .prod-thumb {
            width: 32px;
            height: 32px;
            border-radius: 9px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            color: #94a3b8;
            flex-shrink: 0
        }

        .act-btn {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e2e8f0;
            background: #fff;
            color: #64748b;
            cursor: pointer;
            font-size: .85rem;
            transition: all .15s
        }

        .act-btn:hover {
            background: #eff6ff;
            color: #2563eb;
            border-color: #bfdbfe
        }

        .act-btn.danger:hover {
            background: #fef2f2;
            color: #dc2626;
            border-color: #fca5a5
        }

        .wh-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            overflow: hidden
        }

        .wh-hdr {
            padding: .75rem 1.1rem;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: .65rem
        }

        .wh-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0
        }

        .wh-stat-row {
            display: flex;
            justify-content: space-between;
            font-size: .81rem;
            padding: .45rem 1.1rem;
            border-bottom: 1px solid #f1f5f9
        }

        .wh-stat-row:last-child {
            border-bottom: none
        }

        /* مودال */
        .modal-content {
            border-radius: 16px;
            border: none
        }

        .mhdr {
            border-radius: 16px 16px 0 0;
            border: none
        }

        .variant-row {
            background: #f8fafc;
            border-radius: 9px;
            padding: .45rem .75rem;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: .5rem
        }

        .size-tag {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            padding: 2px 6px;
            border-radius: 6px;
            font-size: .75rem;
            font-weight: 600;
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe
        }

        .color-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 1px solid rgba(0, 0, 0, .15);
            flex-shrink: 0
        }

        .stock-ok {
            color: #16a34a;
            font-weight: 600
        }

        .stock-low {
            color: #d97706;
            font-weight: 600
        }

        .stock-out {
            color: #dc2626;
            font-weight: 600
        }

        .n {
            font-variant-numeric: tabular-nums
        }

        .clr-dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 1px solid rgba(0, 0, 0, .12);
            flex-shrink: 0;
            display: inline-block
        }

        .grp-tag {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            background: #eff6ff;
            color: #1e3a8a;
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            font-size: .68rem;
            padding: 1px 6px;
            font-weight: 600;
            white-space: nowrap
        }

        .grp-tag.g2 {
            background: #f0fdf4;
            color: #065f46;
            border-color: #bbf7d0
        }

        .grp-tag.g3 {
            background: #fff7ed;
            color: #7c2d12;
            border-color: #fed7aa
        }

        .grp-tag.g4 {
            background: #f5f3ff;
            color: #4c1d95;
            border-color: #ddd6fe
        }

        .det-sec {
            border-radius: 10px;
            border: 1px solid #f1f5f9;
            overflow: hidden;
            margin-bottom: 10px
        }

        .det-sec-hdr {
            background: #f8fafc;
            padding: 7px 12px;
            font-size: .75rem;
            font-weight: 700;
            color: #1e293b;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 6px
        }

        .det-sec-body {
            padding: 10px 12px
        }

        .det-row {
            display: flex;
            justify-content: space-between;
            font-size: .78rem;
            padding: 3px 0;
            border-bottom: 1px solid #f8fafc
        }

        .det-row:last-child {
            border-bottom: none
        }

        .det-row span:first-child {
            color: #64748b
        }

        .det-row span:last-child {
            font-weight: 600;
            color: #1e293b
        }

        .grp-block {
            background: #f8fafc;
            border-radius: 8px;
            padding: 8px 10px;
            margin-bottom: 6px
        }

        .sz-mini {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 24px;
            border-radius: 5px;
            background: #fff;
            border: 1px solid #e2e8f0;
            font-size: .7rem;
            font-weight: 600;
            color: #334155
        }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>

    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-boxes me-1 text-primary"></i>المنتجات</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.8rem;color:#94a3b8">
            <span>المخزون</span><i class="bi bi-chevron-left mx-1" style="font-size:.7rem"></i>
            <span class="text-primary">المنتجات</span>
        </nav>
    </header>

    <main class="main-content">
        <div class="content-body">

            <!-- إحصائيات -->
            <div class="row g-3 mb-3">
                <?php foreach ([
                    [$total, 'إجمالي المنتجات', 'bi-boxes', '#2563eb', '#eff6ff'],
                    [$in_stock, 'متوفر بالمستودع', 'bi-check-circle-fill', '#16a34a', '#f0fdf4'],
                    [$low_count, 'مخزون منخفض', 'bi-exclamation-circle', '#d97706', '#fffbeb'],
                    [$out_count, 'نفد المخزون', 'bi-x-circle-fill', '#dc2626', '#fef2f2'],
                ] as [$v, $l, $ic, $clr, $bg]): ?>
                    <div class="col-6 col-md-3">
                        <div class="stat-card">
                            <div class="stat-icon" style="background:<?= $bg ?>;color:<?= $clr ?>"><i
                                    class="bi <?= $ic ?>"></i>
                            </div>
                            <div>
                                <div class="stat-val" style="color:<?= $clr ?>"><?= $v ?></div>
                                <div class="stat-lbl"><?= $l ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- فلاتر التصنيف -->
            <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                <a href="?<?= $search ? 'q=' . urlencode($search) : '' ?>"
                    class="cat-chip <?= !$sel_cat ? 'act-all' : '' ?>">
                    الكل (<?= $total ?>)
                </a>
                <?php foreach ($root_cats as $cat):
                    $cc = $catColors[$cat['id']] ?? ['#f1f5f9', '#475569', '#e2e8f0'];
                    $cnt = count(array_filter($products, function ($p) use ($cat) {
                        return $p['cat_parent'] == $cat['id'] || $p['category_id'] == $cat['id'];
                    }));
                    $cls = $cat['id'] == 1 ? 'act-final' : ($cat['id'] == 2 ? 'act-cons' : '');
                    ?>
                    <a href="?cat=<?= $cat['id'] ?><?= $search ? '&q=' . urlencode($search) : '' ?>"
                        class="cat-chip <?= $sel_cat == $cat['id'] ? $cls : '' ?>"
                        style="<?= $sel_cat == $cat['id'] ? '' : "border-color:{$cc[2]};color:{$cc[1]}" ?>">
                        <?= htmlspecialchars($cat['name']) ?> (<?= $cnt ?>)
                    </a>
                <?php endforeach; ?>

                <!-- بحث -->
                <form method="GET" class="ms-auto d-flex gap-2">
                    <?php if ($sel_cat): ?><input type="hidden" name="cat" value="<?= $sel_cat ?>"><?php endif; ?>
                    <input type="search" name="q" value="<?= htmlspecialchars($search) ?>"
                        class="form-control form-control-sm" style="width:180px;border-radius:9px"
                        placeholder="بحث بالاسم أو الموديل...">
                    <button type="submit" class="btn btn-sm btn-outline-secondary" style="border-radius:9px">
                        <i class="bi bi-search"></i>
                    </button>
                </form>
            </div>

            <!-- جدول المنتجات -->
            <div class="sec-card mb-3">
                <div class="sec-hdr">
                    <span style="font-size:.88rem;font-weight:700;color:#1e293b">
                        <i class="bi bi-boxes me-2 text-primary"></i>
                        قائمة المنتجات
                        <?php if ($sel_cat): ?>
                            <span class="text-muted fw-400" style="font-size:.8rem">
                                —
                                <?= htmlspecialchars(array_filter($categories, function ($c) use ($sel_cat) {
                                    return $c['id'] == $sel_cat;
                                })[array_key_first(array_filter($categories, function ($c) use ($sel_cat) {
                                                return $c['id'] == $sel_cat;
                                            }))]['name'] ?? '') ?>
                            </span>
                        <?php endif; ?>
                    </span>
                    <?php if (can('inventory.products', 'create')): ?>
                        <a href="product_add.php" class="btn btn-sm"
                            style="border-radius:9px;background:#1e3a8a;color:#fff;font-size:.82rem;font-weight:600;text-decoration:none">
                            <i class="bi bi-plus-lg me-1"></i>منتج جديد
                        </a>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>اسم المنتج</th>
                                <th>الموديل</th>
                                <th>الفئة</th>
                                <th>القياسات</th>
                                <th>الألوان</th>
                                <th>نوع القماش</th>
                                <th>المخزون</th>
                                <th>سعر البيع</th>
                                <th>الحالة</th>
                                <th style="text-align:center">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($products)): ?>
                                <tr>
                                    <td colspan="11" class="text-center text-muted py-4">
                                        <i class="bi bi-boxes d-block mb-2 fs-2" style="opacity:.2"></i>
                                        لا توجد منتجات<?= $search ? " تطابق \"{$search}\"" : '' ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($products as $i => $prod):
                                $rootId = $prod['cat_parent'] ?: $prod['category_id'];
                                $cc = $catColors[$rootId] ?? ['#f1f5f9', '#475569', '#e2e8f0'];
                                $stock = (float) $prod['total_stock'];

                                // جلب المقاسات بكروبات
                                $grps = [];
                                $minSell = null;
                                try {
                                    $szSt = $pdo->prepare("SELECT size, selling_price FROM `{$TSZ}` WHERE product_id=? AND is_active=1 ORDER BY sort_order");
                                    $szSt->execute([$prod['id']]);
                                    $pSizes = $szSt->fetchAll();
                                    foreach ($pSizes as $s) {
                                        $k = (string) $s['selling_price'];
                                        $grps[$k][] = $s['size'];
                                        if ($minSell === null || (float) $s['selling_price'] < $minSell)
                                            $minSell = (float) $s['selling_price'];
                                    }
                                } catch (Exception $e) {
                                    $pSizes = [];
                                }

                                // جلب الألوان عبر color_id
                                $clrIds = !empty($prod['color_ids']) ? array_filter(explode(',', $prod['color_ids'])) : [];
                                $pColors = [];
                                if ($clrIds) {
                                    try {
                                        $inQ = implode(',', array_fill(0, count($clrIds), '?'));
                                        $clSt = $pdo->prepare("SELECT id, name, hex_code FROM `{$TCL}` WHERE id IN ({$inQ}) ORDER BY name");
                                        $clSt->execute(array_values($clrIds));
                                        $pColors = $clSt->fetchAll();
                                    } catch (Exception $e) {
                                        $pColors = [];
                                    }
                                }
                                ?>
                                <tr id="pRow_<?= $prod['id'] ?>">
                                    <td class="text-muted small"><?= $i + 1 ?></td>

                                    <!-- اسم المنتج -->
                                    <td>
                                        <div class="fw-600" style="font-size:.84rem"><?= htmlspecialchars($prod['name']) ?>
                                        </div>
                                        <div class="text-muted" style="font-size:.7rem">
                                            <?= htmlspecialchars($prod['fabric_type'] ?? '') ?>
                                        </div>
                                    </td>

                                    <!-- الموديل -->
                                    <td class="n text-muted" style="font-size:.78rem;direction:ltr">
                                        <?= htmlspecialchars($prod['model_number']) ?>
                                    </td>

                                    <!-- الفئة -->
                                    <td>
                                        <span class="badge"
                                            style="background:<?= $cc[0] ?>;color:<?= $cc[1] ?>;border:1px solid <?= $cc[2] ?>;border-radius:20px;font-size:.68rem;padding:2px 7px">
                                            <?= htmlspecialchars($prod['cat_name'] ?? '—') ?>
                                        </span>
                                    </td>

                                    <!-- القياسات بالكروبات -->
                                    <td>
                                        <?php if (empty($grps)): ?>
                                            <span class="text-muted" style="font-size:.75rem">—</span>
                                        <?php else:
                                            $gi = 0;
                                            foreach ($grps as $price => $sizes):
                                                $gcls = ['', 'g2', 'g3', 'g4'][$gi] ?? ''; ?>
                                                <span class="grp-tag <?= $gcls ?>" style="margin-bottom:2px;display:inline-flex">
                                                    <?= implode('·', $sizes) ?>
                                                </span>
                                                <?php $gi++; endforeach; endif; ?>
                                    </td>

                                    <!-- الألوان -->
                                    <td>
                                        <div class="d-flex flex-wrap gap-1 align-items-center">
                                            <?php foreach (array_slice($pColors, 0, 6) as $clr): ?>
                                                <span class="clr-dot"
                                                    style="background:<?= htmlspecialchars($clr['hex_code']) ?>"
                                                    title="<?= htmlspecialchars($clr['name']) ?>"></span>
                                            <?php endforeach; ?>
                                            <?php if (count($pColors) > 6): ?>
                                                <span style="font-size:.68rem;color:#94a3b8">+<?= count($pColors) - 6 ?></span>
                                            <?php endif; ?>
                                            <?php if (empty($pColors)): ?>
                                                <span class="text-muted" style="font-size:.75rem">—</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!-- نوع القماش -->
                                    <td style="font-size:.78rem;color:#64748b">
                                        <?= htmlspecialchars($prod['fabric_type'] ?? '—') ?>
                                    </td>

                                    <!-- المخزون -->
                                    <td class="n" style="font-size:.82rem">
                                        <?php
                                        $sCls = $stock > 10 ? 'text-success' : ($stock > 0 ? 'text-warning' : 'text-danger');
                                        $sLbl = $stock > 0 ? number_format($stock, 0) . ' قطعة' : 'نفد';
                                        ?>
                                        <span class="<?= $sCls ?> fw-600"><?= $sLbl ?></span>
                                    </td>

                                    <!-- سعر البيع (أدنى سعر) -->
                                    <td class="n" style="font-size:.82rem;font-weight:600;color:#1e293b">
                                        <?= $minSell ? number_format($minSell, 2) . ' $' : '—' ?>
                                    </td>

                                    <!-- الحالة -->
                                    <td>
                                        <?php if ($prod['is_active']): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle"
                                                style="font-size:.68rem">نشط</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary"
                                                style="font-size:.68rem">معطّل</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- الإجراءات -->
                                    <td>
                                        <div class="d-flex gap-1 justify-content-center">
                                            <button class="act-btn" style="color:#0891b2;border-color:#a5f3fc"
                                                onclick="viewDetail(<?= $prod['id'] ?>)" title="عرض التفاصيل">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <a href="product_edit.php?id=<?= $prod['id'] ?>" class="act-btn" title="تعديل"
                                                style="text-decoration:none">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <button class="act-btn <?= $prod['is_active'] ? 'danger' : '' ?>"
                                                onclick="toggleProduct(<?= $prod['id'] ?>)"
                                                title="<?= $prod['is_active'] ? 'تعطيل' : 'تفعيل' ?>">
                                                <i
                                                    class="bi bi-<?= $prod['is_active'] ? 'slash-circle' : 'check-circle' ?>"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- المستودعات -->
            <div class="mb-2" style="font-size:.8rem;font-weight:600;color:#64748b;letter-spacing:.04em">
                <i class="bi bi-building-warehouse me-1"></i>المستودعات
            </div>
            <div class="row g-3">
                <?php foreach ($warehouses as $wh):
                    $ws = $wh_stats[$wh['id']] ?? ['items' => 0, 'qty' => 0, 'val' => 0];
                    $isF = strpos($wh['code'], 'FINAL') !== false;
                    $wIc = $isF ? 'bi-shirt' : 'bi-package';
                    $wBg = $isF ? '#E6F1FB' : '#FAEEDA';
                    $wClr = $isF ? '#0C447C' : '#633806';
                    ?>
                    <div class="col-md-6">
                        <div class="wh-card">
                            <div class="wh-hdr">
                                <div class="wh-icon" style="background:<?= $wBg ?>;color:<?= $wClr ?>">
                                    <i class="bi <?= $wIc ?>"></i>
                                </div>
                                <div>
                                    <div class="fw-600" style="font-size:.88rem"><?= htmlspecialchars($wh['name']) ?></div>
                                    <div class="text-muted" style="font-size:.73rem"><?= htmlspecialchars($wh['code']) ?>
                                    </div>
                                </div>
                                <a href="warehouse.php?wh=<?= $wh['id'] ?>" class="btn btn-sm ms-auto"
                                    style="border-radius:8px;font-size:.77rem">
                                    <i class="bi bi-eye me-1"></i>تفاصيل
                                </a>
                            </div>
                            <div class="wh-stat-row">
                                <span class="text-muted">إجمالي الأصناف</span>
                                <span class="n fw-600"><?= number_format($ws['items'], 0) ?> صنف</span>
                            </div>
                            <div class="wh-stat-row">
                                <span class="text-muted">إجمالي الكميات</span>
                                <span class="n fw-600"><?= number_format($ws['qty'], 0) ?> قطعة</span>
                            </div>
                            <div class="wh-stat-row">
                                <span class="text-muted">قيمة المخزون</span>
                                <span class="n fw-600" style="color:<?= $wClr ?>"><?= number_format($ws['val'], 2) ?>
                                    $</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($warehouses)): ?>
                    <div class="col-12">
                        <div class="text-center text-muted py-4" style="border:1px dashed #e2e8f0;border-radius:14px">
                            <i class="bi bi-building-warehouse d-block mb-2 fs-2" style="opacity:.2"></i>
                            لا توجد مستودعات — نفّذ SQL الإعداد أولاً
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </main>

    <!-- ══ مودال إضافة/تعديل منتج ══ -->
    <div class="modal fade" id="prodModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header mhdr py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#1e3a8a,#2563eb)">
                    <div>
                        <h6 class="modal-title text-white fw-700 mb-0" id="mTitle">منتج جديد</h6>
                        <div id="mSub" style="font-size:.78rem;color:rgba(255,255,255,.75);margin-top:2px">فرع البيع
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pt-3 pb-2">
                    <input type="hidden" id="mId">

                    <!-- §1 التصنيف والمستودع -->
                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-600 text-secondary mb-1">التصنيف <span
                                    class="text-danger">*</span></label>
                            <select id="mCat" class="form-select form-select-sm" onchange="onCatChange()">
                                <option value="">— اختر التصنيف —</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>" data-parent="<?= $cat['parent_id'] ?>"
                                        style="<?= $cat['parent_id'] ? 'padding-right:20px' : '' ?>">
                                        <?= $cat['parent_id'] ? '↳ ' : '' ?>     <?= htmlspecialchars($cat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-600 text-secondary mb-1">المستودع</label>
                            <select id="mWarehouse" class="form-select form-select-sm">
                                <?php foreach ($warehouses as $wh): ?>
                                    <option value="<?= $wh['id'] ?>"><?= htmlspecialchars($wh['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-600 text-secondary mb-1">حجم الباكيت</label>
                            <input type="number" id="mPacket" class="form-control form-control-sm"
                                placeholder="مثلاً: 12" min="1">
                        </div>
                    </div>

                    <!-- §2 بيانات المنتج -->
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-600 text-secondary mb-1">اسم المنتج <span
                                    class="text-danger">*</span></label>
                            <input type="text" id="mName" class="form-control form-control-sm"
                                placeholder="بنطلون جينز أطفال">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-600 text-secondary mb-1">رقم الموديل <span
                                    class="text-danger">*</span></label>
                            <input type="text" id="mModel" class="form-control form-control-sm" placeholder="JNS-001"
                                dir="ltr">
                        </div>
                    </div>

                    <!-- §3 الفئة العمرية (تظهر للمواد النهائية فقط) -->
                    <div id="mAgeSection" class="row g-2 mb-3" style="display:none!important">
                        <div class="col-12">
                            <label class="form-label small fw-600 text-secondary mb-1">الفئة العمرية</label>
                        </div>
                        <div class="col-3">
                            <input type="number" id="mAgeFrom" class="form-control form-control-sm" placeholder="من"
                                min="0">
                        </div>
                        <div class="col-3">
                            <input type="number" id="mAgeTo" class="form-control form-control-sm" placeholder="إلى"
                                min="0">
                        </div>
                        <div class="col-3">
                            <select id="mAgeType" class="form-select form-select-sm">
                                <option value="سنة">سنة</option>
                                <option value="شهر">شهر</option>
                                <option value="يوم">يوم</option>
                            </select>
                        </div>
                        <div class="col-3">
                            <input type="number" id="mAgeStep" class="form-control form-control-sm" placeholder="خطوة"
                                value="1" min="1">
                        </div>
                    </div>

                    <!-- §4 المقاسات -->
                    <div id="mSizesSection" class="mb-3" style="display:none!important">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <label class="form-label small fw-600 text-secondary mb-0">المقاسات</label>
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                style="border-radius:8px;font-size:.77rem" onclick="addSize()">
                                <i class="bi bi-plus me-1"></i>إضافة مقاس
                            </button>
                        </div>
                        <div id="sizesContainer"></div>
                    </div>

                    <!-- §5 المتغيرات (لون + مقاس) -->
                    <div id="mVariantsSection" class="mb-2" style="display:none!important">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <label class="form-label small fw-600 text-secondary mb-0">المتغيرات (لون × مقاس)</label>
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                style="border-radius:8px;font-size:.77rem" onclick="addVariant()">
                                <i class="bi bi-plus me-1"></i>إضافة متغير
                            </button>
                        </div>
                        <div id="variantsContainer"></div>
                    </div>

                    <!-- §6 ملاحظات -->
                    <div class="mb-1">
                        <label class="form-label small fw-600 text-secondary mb-1">ملاحظات</label>
                        <textarea id="mNotes" class="form-control form-control-sm" rows="2"
                            placeholder="اختياري"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-3 pt-1">
                    <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal"
                        style="border-radius:8px">إلغاء</button>
                    <button type="button" class="btn btn-sm fw-600"
                        style="border-radius:8px;min-width:110px;background:#1e3a8a;color:#fff" onclick="saveProduct()"
                        id="mSaveBtn">
                        <span id="mSaveTxt"><i class="bi bi-floppy me-1"></i>حفظ</span>
                        <span id="mSpin" class="spinner-border spinner-border-sm ms-1" style="display:none"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ مودال عرض المتغيرات ══ -->
    <div class="modal fade" id="variantModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#065f46,#16a34a);border-radius:16px 16px 0 0">
                    <div>
                        <h6 class="modal-title text-white fw-700 mb-0">
                            <i class="bi bi-grid-3x3-gap me-2"></i><span id="vTitle">—</span>
                        </h6>
                        <div style="font-size:.78rem;color:rgba(255,255,255,.75);margin-top:2px">المتغيرات والمخزون
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pt-3 pb-3" id="vBody">
                    <div class="text-center py-4"><span class="spinner-border"></span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- مودال تفاصيل المنتج -->
    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#0c447c,#1e3a8a);border-radius:16px 16px 0 0">
                    <div>
                        <h6 class="modal-title text-white fw-700 mb-0" id="detTitle">تفاصيل المنتج</h6>
                        <div id="detSub" style="font-size:.76rem;color:rgba(255,255,255,.7);margin-top:2px"></div>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <a id="detEditBtn" href="#" class="btn btn-sm"
                            style="border-radius:8px;background:rgba(255,255,255,.15);color:#fff;font-size:.76rem;border:1px solid rgba(255,255,255,.3)">
                            <i class="bi bi-pencil me-1"></i>تعديل
                        </a>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                </div>
                <div class="modal-body px-4 pt-3 pb-3" id="detBody">
                    <div class="text-center py-4"><span class="spinner-border text-primary"></span></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const sb = document.getElementById('sidebar'), ov = document.getElementById('sbOverlay');
        function sbOpen() { sb.classList.add('open'); ov.classList.add('show'); }
        function sbClose() { sb.classList.remove('open'); ov.classList.remove('show'); }
        window.addEventListener('resize', () => { if (window.innerWidth > 991) sbClose(); });
        document.querySelectorAll('.sb-group').forEach(g => {
            if (localStorage.getItem('sb_open_' + g.dataset.key) === 'true') g.classList.add('open');
        });
        function toggleGroup(g) {
            const o = g.classList.contains('open');
            document.querySelectorAll('.sb-group.open').forEach(x => x.classList.remove('open'));
            g.classList.toggle('open', !o);
            localStorage.setItem('sb_open_' + g.dataset.key, (!o).toString());
        }

        const prodModal = new bootstrap.Modal(document.getElementById('prodModal'));
        const variantModal = new bootstrap.Modal(document.getElementById('variantModal'));

        // بيانات المقاسات والمتغيرات
        let mSizes = [];
        let mVariants = [];

        // التصنيفات الجذر (نهائية=1، استهلاكية=2)
        const CAT_FINAL = <?= json_encode(array_values(array_filter($categories, function ($c) {
            return !$c['parent_id'] && $c['id'] == 1;
        }))) ?>;
        const ALL_CATS = <?= json_encode($categories) ?>;

        function post(data) {
            const fd = new FormData();
            Object.entries(data).forEach(([k, v]) => fd.append(k, v ?? ''));
            return fetch(location.href, { method: 'POST', body: fd }).then(r => r.json());
        }
        function toast(msg, type = 'success') {
            const t = document.createElement('div');
            t.className = `alert alert-${type} shadow-sm`;
            t.style.cssText = 'position:fixed;top:76px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:240px;text-align:center;font-size:.85rem;padding:.55rem 1.25rem';
            t.innerHTML = `<i class="bi bi-${type === 'success' ? 'check-circle-fill text-success' : 'exclamation-triangle-fill text-danger'} me-2"></i>${msg}`;
            document.body.appendChild(t);
            setTimeout(() => t.remove(), 3200);
        }

        // ── onCatChange: يُظهر/يُخفي حقول حسب التصنيف ──
        function onCatChange() {
            const catId = parseInt(document.getElementById('mCat').value) || 0;
            const cat = ALL_CATS.find(c => c.id == catId);
            const rootId = cat ? (cat.parent_id || cat.id) : 0;
            const isFinal = rootId == 1;
            const isCons = rootId == 2;

            // إظهار/إخفاء حقول
            setVisible('mAgeSection', isFinal);
            setVisible('mSizesSection', isFinal);
            setVisible('mVariantsSection', isFinal);

            // المستودع تلقائياً حسب التصنيف
            const whSel = document.getElementById('mWarehouse');
            if (isFinal) {
                // اختر مستودع نهائية
                for (let o of whSel.options) {
                    if (o.text.includes('النهائية')) { o.selected = true; break; }
                }
            } else if (isCons) {
                for (let o of whSel.options) {
                    if (o.text.includes('الاستهلاكية')) { o.selected = true; break; }
                }
            }
        }

        function setVisible(id, show) {
            const el = document.getElementById(id);
            if (!el) return;
            if (show) el.style.removeProperty('display');
            else el.style.setProperty('display', 'none', 'important');
        }

        // ── فتح إنشاء ──
        function openCreate() {
            document.getElementById('mId').value = '';
            document.getElementById('mTitle').textContent = 'منتج جديد';
            document.getElementById('mCat').value = '';
            document.getElementById('mName').value = '';
            document.getElementById('mModel').value = '';
            document.getElementById('mPacket').value = '';
            document.getElementById('mAgeFrom').value = '';
            document.getElementById('mAgeTo').value = '';
            document.getElementById('mNotes').value = '';
            mSizes = []; mVariants = [];
            renderSizes(); renderVariants();
            onCatChange();
            prodModal.show();
        }

        // ── فتح تعديل ──
        function openEdit(id) {
            document.getElementById('mTitle').textContent = 'تعديل المنتج';
            post({ _action: 'get_product', id }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                const p = d.data;
                document.getElementById('mId').value = p.id;
                document.getElementById('mCat').value = p.category_id || '';
                document.getElementById('mName').value = p.name;
                document.getElementById('mModel').value = p.model_number;
                document.getElementById('mPacket').value = p.packet_size || '';
                document.getElementById('mAgeFrom').value = p.age_from || '';
                document.getElementById('mAgeTo').value = p.age_to || '';
                document.getElementById('mAgeType').value = p.age_type || 'سنة';
                document.getElementById('mNotes').value = p.notes || '';
                mSizes = p.sizes || [];
                mVariants = p.variants || [];
                onCatChange();
                renderSizes();
                renderVariants();
                prodModal.show();
            });
        }

        // ── المقاسات ──
        function addSize() {
            mSizes.push({ size: '', selling_price: 0, full_packet_price: '', _id: Date.now() });
            renderSizes();
        }
        function removeSize(idx) { mSizes.splice(idx, 1); renderSizes(); renderVariants(); }
        function renderSizes() {
            const c = document.getElementById('sizesContainer');
            if (!mSizes.length) {
                c.innerHTML = '<div class="text-muted" style="font-size:.8rem">لا توجد مقاسات — اضغط "إضافة مقاس"</div>';
                return;
            }
            c.innerHTML = mSizes.map((s, i) => `
        <div class="variant-row">
            <input type="text" class="form-control form-control-sm" style="width:80px"
                   placeholder="مثلاً: S" value="${s.size || ''}"
                   onchange="mSizes[${i}].size=this.value;renderVariants()">
            <input type="number" class="form-control form-control-sm" style="width:100px"
                   placeholder="سعر البيع $" value="${s.selling_price || ''}"
                   onchange="mSizes[${i}].selling_price=parseFloat(this.value)||0">
            <input type="number" class="form-control form-control-sm" style="width:110px"
                   placeholder="سعر الباكيت $" value="${s.full_packet_price || ''}"
                   onchange="mSizes[${i}].full_packet_price=this.value">
            <button class="btn btn-sm" style="border-radius:7px;background:#fef2f2;color:#dc2626;border:1px solid #fca5a5"
                    onclick="removeSize(${i})"><i class="bi bi-x"></i></button>
        </div>`).join('');
        }

        // ── المتغيرات ──
        function addVariant() {
            const sizeId = mSizes[0] ? mSizes[0].id || mSizes[0]._id : 0;
            mVariants.push({ color: '', size_id: sizeId, barcode: '', _id: Date.now() });
            renderVariants();
        }
        function removeVariant(idx) { mVariants.splice(idx, 1); renderVariants(); }
        function renderVariants() {
            const c = document.getElementById('variantsContainer');
            if (!mVariants.length) {
                c.innerHTML = '<div class="text-muted" style="font-size:.8rem">لا توجد متغيرات — أضف مقاسات أولاً ثم اضغط "إضافة متغير"</div>';
                return;
            }
            const sizeOpts = mSizes.map((s, i) =>
                `<option value="${s.id || s._id}">${s.size || 'مقاس ' + (i + 1)}</option>`).join('');
            c.innerHTML = mVariants.map((v, i) => `
        <div class="variant-row">
            <input type="color" value="#3b82f6" style="width:32px;height:28px;border-radius:6px;border:1px solid #e2e8f0;padding:1px;cursor:pointer"
                   onchange="mVariants[${i}].color_hex=this.value">
            <input type="text" class="form-control form-control-sm" style="flex:1"
                   placeholder="اسم اللون (أزرق / أسود...)" value="${v.color || ''}"
                   onchange="mVariants[${i}].color=this.value">
            <select class="form-select form-select-sm" style="width:100px"
                    onchange="mVariants[${i}].size_id=this.value">
                ${sizeOpts}
            </select>
            <input type="text" class="form-control form-control-sm" style="width:120px"
                   placeholder="باركود" dir="ltr" value="${v.barcode || ''}"
                   onchange="mVariants[${i}].barcode=this.value">
            <button class="btn btn-sm" style="border-radius:7px;background:#fef2f2;color:#dc2626;border:1px solid #fca5a5"
                    onclick="removeVariant(${i})"><i class="bi bi-x"></i></button>
        </div>`).join('');
        }

        // ── حفظ المنتج ──
        function saveProduct() {
            const id = document.getElementById('mId').value;
            const name = document.getElementById('mName').value.trim();
            const model = document.getElementById('mModel').value.trim();
            if (!name || !model) { toast('الاسم ورقم الموديل مطلوبان', 'danger'); return; }

            document.getElementById('mSaveTxt').style.opacity = '0';
            document.getElementById('mSpin').style.display = 'inline-block';

            post({
                _action: 'save_product',
                id,
                name,
                model_number: model,
                category_id: document.getElementById('mCat').value,
                warehouse_id: document.getElementById('mWarehouse').value,
                age_type: document.getElementById('mAgeType').value,
                age_from: document.getElementById('mAgeFrom').value,
                age_to: document.getElementById('mAgeTo').value,
                packet_size: document.getElementById('mPacket').value,
                notes: document.getElementById('mNotes').value,
                sizes: JSON.stringify(mSizes),
                variants: JSON.stringify(mVariants),
            }).then(d => {
                document.getElementById('mSaveTxt').style.opacity = '1';
                document.getElementById('mSpin').style.display = 'none';
                if (d.ok) {
                    prodModal.hide();
                    toast('تم حفظ المنتج بنجاح');
                    setTimeout(() => location.reload(), 1200);
                } else toast(d.msg, 'danger');
            });
        }

        // ── تعطيل/تفعيل منتج ──
        // ── عرض تفاصيل المنتج ──
        let _detailModal = null;
        function viewDetail(id) {
            if (!_detailModal) _detailModal = new bootstrap.Modal(document.getElementById('detailModal'));
            document.getElementById('detTitle').textContent = 'جارٍ التحميل...';
            document.getElementById('detSub').textContent = '';
            document.getElementById('detEditBtn').href = 'product_edit.php?id=' + id;
            document.getElementById('detBody').innerHTML =
                '<div class="text-center py-4"><span class="spinner-border text-primary"></span></div>';
            _detailModal.show();

            const fd = new FormData();
            fd.append('_action', 'get_product_detail');
            fd.append('id', id);
            fetch(location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (!d.ok) {
                        document.getElementById('detBody').innerHTML = `<div class="text-danger p-3">${d.msg}</div>`;
                        return;
                    }
                    const p = d.data;
                    document.getElementById('detTitle').textContent = p.name;
                    document.getElementById('detSub').textContent = p.model_number;

                    const GRP_COLORS = [
                        ['#eff6ff', '#1e3a8a', '#bfdbfe'],
                        ['#f0fdf4', '#065f46', '#bbf7d0'],
                        ['#fff7ed', '#7c2d12', '#fed7aa'],
                        ['#f5f3ff', '#4c1d95', '#ddd6fe']
                    ];

                    // الكروبات
                    const grpsHtml = (p.groups || []).map((g, i) => {
                        const [bg, clr, br] = GRP_COLORS[i % 4];
                        const szTags = g.sizes.map(s => `<span class="sz-mini">${s}</span>`).join('');
                        return `<div class="grp-block">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span style="background:${bg};color:${clr};border:1px solid ${br};border-radius:12px;font-size:.7rem;padding:1px 8px;font-weight:700">
                            الكروب ${i + 1}
                        </span>
                        <span style="font-size:.74rem;color:#64748b">${g.sizes.length} قطعة بالباكيت</span>
                        <span style="margin-right:auto;font-size:.8rem;font-weight:700;color:#1e293b">
                            ${parseFloat(g.selling_price || 0).toFixed(2)} $
                        </span>
                    </div>
                    <div class="d-flex flex-wrap gap-1">${szTags}</div>
                </div>`;
                    }).join('') || '<div class="text-muted" style="font-size:.78rem">لا توجد قياسات</div>';

                    // الألوان
                    const clrsHtml = (p.colors || []).map(c =>
                        `<div class="d-flex align-items-center gap-2">
                    <span style="width:16px;height:16px;border-radius:50%;background:${c.hex_code};border:1px solid rgba(0,0,0,.1);flex-shrink:0"></span>
                    <span style="font-size:.78rem">${c.name}</span>
                    <span class="text-muted n" dir="ltr" style="font-size:.7rem">${c.hex_code}</span>
                </div>`
                    ).join('') || '<span class="text-muted" style="font-size:.78rem">لا توجد ألوان</span>';

                    document.getElementById('detBody').innerHTML = `
            <div class="row g-3">
              <div class="col-md-${p.image_path ? '8' : '12'}">
                <div class="det-sec">
                  <div class="det-sec-hdr"><i class="bi bi-info-circle text-primary"></i>البيانات الأساسية</div>
                  <div class="det-sec-body">
                    <div class="det-row"><span>الفئة</span><span>${p.cat_name || '—'}</span></div>
                    <div class="det-row"><span>الخامة</span><span>${p.fabric_type || '—'}</span></div>
                    <div class="det-row"><span>المورد</span><span>${p.supplier_name || '—'}</span></div>
                    <div class="det-row"><span>المخزون الكلي</span>
                      <span style="font-weight:700;color:${p.total_qty > p.min_qty ? '#16a34a' : p.total_qty > 0 ? '#d97706' : '#dc2626'}">
                        ${p.total_qty} قطعة
                        ${p.min_qty > 0 ? '<span style="font-size:.68rem;color:#94a3b8"> (حد التنبيه: ' + p.min_qty + ')</span>' : ''}
                      </span>
                    </div>
                    <div class="det-row"><span>الحالة</span>
                      <span class="badge ${p.is_active == '1' ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'}" style="font-size:.68rem">
                        ${p.is_active == '1' ? 'نشط' : 'معطّل'}
                      </span>
                    </div>
                    ${p.notes ? `<div class="det-row"><span>ملاحظات</span><span style="max-width:240px;white-space:normal;text-align:right">${p.notes}</span></div>` : ''}
                  </div>
                </div>
                <div class="det-sec">
                  <div class="det-sec-hdr"><i class="bi bi-rulers text-primary"></i>القياسات والكروبات</div>
                  <div class="det-sec-body" style="padding:8px 12px">${grpsHtml}</div>
                </div>
                <div class="det-sec">
                  <div class="det-sec-hdr"><i class="bi bi-palette text-primary"></i>الألوان (${(p.colors || []).length})</div>
                  <div class="det-sec-body"><div class="d-flex flex-column gap-2">${clrsHtml}</div></div>
                </div>
              </div>
              ${p.image_path ? `
              <div class="col-md-4">
                <div class="det-sec">
                  <div class="det-sec-hdr"><i class="bi bi-image text-primary"></i>صورة المنتج</div>
                  <div class="det-sec-body p-0">
                    <img src="${p.image_path}" style="width:100%;border-radius:0 0 10px 10px;max-height:220px;object-fit:cover">
                  </div>
                </div>
              </div>`: ''}
            </div>`;
                });
        }

        function toggleProduct(id) {
            if (!confirm('تغيير حالة المنتج؟')) return;
            post({ _action: 'toggle_product', id }).then(d => {
                if (d.ok) { toast('تم تغيير الحالة'); setTimeout(() => location.reload(), 1000); }
                else toast(d.msg, 'danger');
            });
        }

        // ── عرض المتغيرات ──
        function viewVariants(id, name) {
            document.getElementById('vTitle').textContent = name;
            document.getElementById('vBody').innerHTML = '<div class="text-center py-4"><span class="spinner-border"></span></div>';
            variantModal.show();
            post({ _action: 'get_product', id }).then(d => {
                if (!d.ok) { document.getElementById('vBody').innerHTML = `<div class="text-danger">${d.msg}</div>`; return; }
                const p = d.data;
                if (!p.variants || !p.variants.length) {
                    document.getElementById('vBody').innerHTML = '<div class="text-muted text-center py-3">لا توجد متغيرات مضافة بعد</div>';
                    return;
                }
                // بناء جدول المتغيرات
                document.getElementById('vBody').innerHTML = `
            <table class="table table-sm table-bordered" style="font-size:.82rem">
                <thead class="table-light">
                    <tr>
                        <th>اللون</th><th>المقاس</th><th>الباركود</th>
                        <th>آخر سعر شراء</th>
                    </tr>
                </thead>
                <tbody>
                ${p.variants.map(v => `<tr>
                    <td class="fw-600">${v.color || '—'}</td>
                    <td><span class="size-tag">${v.size_label || v.size_id}</span></td>
                    <td dir="ltr" style="font-size:.78rem">${v.barcode || '—'}</td>
                    <td class="n">${v.last_cost_price ? parseFloat(v.last_cost_price).toFixed(4) + ' $' : '—'}</td>
                </tr>`).join('')}
                </tbody>
            </table>`;
            });
        }
    </script>
</body>

</html>