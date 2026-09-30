<?php
/**
 * inventory/movements.php — حركات المخزون
 *retail1/modules/inventory/movements.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('inventory.movements', 'view');

$TS = $_SESSION['table_suffix'];
$TCM = "consumable_movements_{$TS}";
$TCI = "consumable_items_{$TS}";
$TCU = "consumable_units_{$TS}";

// ⚠ ترجمة عربية لقيمة reference_type الخام (enum بالإنجليزي) — كانت
// تظهر حرفياً بعمود "المرجع" (زي "purchase"، "transfer"...)
$REF_TYPE_AR = [
    'purchase' => 'مشتريات',
    'sale' => 'مبيعات',
    'issue' => 'صرف استهلاكي',
    'transfer' => 'مناقلة',
    'inventory' => 'جرد',
    'manual' => 'يدوي',
    'consumable_return' => 'إرجاع استهلاكي',
];
function refTypeLabel(string $type, array $map): string
{
    return $map[$type] ?? $type;
}
$TW = "warehouses_{$TS}";
$TPI = "purchase_items_{$TS}";
$TP = "purchases_{$TS}";
$TSI = "sales_invoices_{$TS}";
$TSII = "sales_invoice_items_{$TS}";
$TPROD = "products_{$TS}";
$TIM = "inventory_movements_{$TS}";
$TIMD = "inventory_movement_details_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// عملة الفرع الأساسية — ⚠ كانت غائبة تماماً بهذا الملف، فاضطر الكود
// لكتابة رمز "$" حرفياً بدل قراءته من إعدادات الفرع الفعلية (نفس نمط
// البلوبرنت المحذّر منه: رمز عملة ثابت بدل قراءة ديناميكية من currencies).
$branchCurRow = $pdo->prepare("SELECT c.symbol FROM branches b
    JOIN currencies c ON c.id = b.base_currency_id
    WHERE b.table_suffix = ? LIMIT 1");
$branchCurRow->execute([$TS]);
$baseCurSymbol = $branchCurRow->fetchColumn() ?: '$';

// ── فلاتر ──
// ⚠ الافتراضي صار 'products' (كان 'consumables') — يطابق عنوان/أيقونة
// الصفحة "حركات المخزون". لما تُربط من قسم "المصاريف والمستهلكات"
// المستقبلي بالشريط الجانبي، مرّر ?tab=consumables صراحة دايماً.
$tab = $_GET['tab'] ?? 'products';
// ✅ $currentModule ديناميكية حسب $tab — نفس نمط warehouse.php بالضبط
// (كانت ثابتة على 'inventory.movements' فقط، فتبويبات سياق المستهلكات
// كانت بتطلع فاضية عبر tab_bar.php رغم تسجيل expenses.movements)
$currentModule = ($tab === 'consumables') ? 'expenses.movements' : 'inventory.movements';
$whF = (int) ($_GET['wh'] ?? 0);
$typeF = $_GET['type'] ?? '';
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');
$search = trim($_GET['q'] ?? '');

$warehouses = $pdo->query("SELECT * FROM `{$TW}` WHERE is_active=1 ORDER BY id")->fetchAll();

// ── حركات المستهلكات ──
$consMovements = [];
if ($tab === 'consumables') {
    $where = "WHERE m.is_posted=1 AND m.movement_date BETWEEN ? AND ?";
    $params = [$dateFrom, $dateTo];
    if ($whF) {
        $where .= ' AND m.warehouse_id=?';
        $params[] = $whF;
    }
    if ($typeF) {
        $where .= ' AND m.movement_type=?';
        $params[] = $typeF;
    }
    if ($search) {
        $where .= ' AND ci.name LIKE ?';
        $params[] = "%{$search}%";
    }

    $stmt = $pdo->prepare("SELECT m.*,
        ci.name AS item_name, cun.name AS unit,
        w.name AS wh_name
        FROM `{$TCM}` m
        JOIN `{$TCI}` ci ON ci.id=m.item_id
        LEFT JOIN `{$TCU}` cun ON cun.id=ci.unit_id
        LEFT JOIN `{$TW}` w ON w.id=m.warehouse_id
        {$where}
        ORDER BY m.movement_date DESC, m.id DESC LIMIT 300");
    $stmt->execute($params);
    $consMovements = $stmt->fetchAll();

    // إحصائيات المستهلكات
    $consStats = $pdo->prepare("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN direction='in' THEN quantity ELSE 0 END) AS total_in,
        SUM(CASE WHEN direction='out' THEN quantity ELSE 0 END) AS total_out,
        COALESCE(SUM(CASE WHEN direction='in' THEN total_cost_base ELSE 0 END),0) AS cost_in,
        COALESCE(SUM(CASE WHEN direction='out' THEN total_cost_base ELSE 0 END),0) AS cost_out
        FROM `{$TCM}` m
        JOIN `{$TCI}` ci ON ci.id=m.item_id
        {$where}");
    $consStats->execute($params);
    $consStats = $consStats->fetch();
}

// ── حركات المنتجات النهائية ──
// ⚠ إعادة بناء جوهرية: الاستعلامان السابقان (من purchase_items/
// sales_invoice_items) كانا يعيدان "استنتاج" الحركات من الفواتير —
// تأكّدنا (بفحص الأربع APIs: confirm_purchase_invoice, confirm_sale_invoice,
// confirm_purchase_return, confirm_sale_return) إنهم كلهم فعلياً بيكتبوا
// مباشرة بجدولي inventory_movements_ret/inventory_movement_details_ret
// عند كل تأكيد — وهاد المصدر الحقيقي والوحيد الموثوق (فيه أصلاً
// balance_before/balance_after، وبيغطي المرتجعات تلقائياً، عكس الاستنتاج
// اليدوي السابق يلي كان يتجاهلها تماماً).
$prodMovements = [];
if ($tab === 'products') {
    $whereM = "WHERE im.created_at BETWEEN ? AND ?";
    $paramsM = [$dateFrom, $dateTo . ' 23:59:59'];
    if ($whF) {
        $whereM .= ' AND im.warehouse_id=?';
        $paramsM[] = $whF;
    }
    if ($search) {
        $whereM .= ' AND pr.name LIKE ?';
        $paramsM[] = "%{$search}%";
    }

    $stmtM = $pdo->prepare("SELECT im.reference_type AS movement_type, im.movement_type AS direction,
        pr.name AS product_name, pr.model_number,
        psz.size, pcl.name AS color,
        imd.quantity, imd.unit_price AS unit_price_base,
        imd.total_value AS total_base,
        im.reference_number AS ref_no, im.created_at AS movement_date,
        COALESCE(sup.name, cus.name) AS party_name, w.name AS wh_name
        FROM `{$TIM}` im
        JOIN `{$TIMD}` imd ON imd.movement_id=im.id
        LEFT JOIN `{$TPROD}` pr ON pr.id=imd.product_id
        LEFT JOIN product_variants_{$TS} pv ON pv.id=imd.variant_id
        LEFT JOIN product_sizes_{$TS} psz ON psz.id=pv.size_id
        LEFT JOIN product_colors_{$TS} pcl ON pcl.id=pv.color_id
        LEFT JOIN `{$TW}` w ON w.id=im.warehouse_id
        LEFT JOIN `{$TP}` pu ON pu.id=im.reference_id AND im.reference_type IN ('purchase','purchase_return')
        LEFT JOIN product_suppliers_{$TS} sup ON sup.id=pu.supplier_id
        LEFT JOIN `{$TSI}` si ON si.id=im.reference_id AND im.reference_type IN ('sale','sale_return')
        LEFT JOIN customers_{$TS} cus ON cus.id=si.customer_id
        {$whereM}
        ORDER BY im.created_at DESC LIMIT 150");
    $stmtM->execute($paramsM);
    $prodMovements = $stmtM->fetchAll();

    // إحصائيات المنتجات
    $prodIn = array_filter($prodMovements, function ($r) {
        return $r['direction'] === 'in';
    });
    $prodOut = array_filter($prodMovements, function ($r) {
        return $r['direction'] === 'out';
    });
    $prodStats = [
        'total' => count($prodMovements),
        'total_in' => array_sum(array_column(array_values($prodIn), 'quantity')),
        'total_out' => array_sum(array_column(array_values($prodOut), 'quantity')),
        'cost_in' => array_sum(array_column(array_values($prodIn), 'total_base')),
        'cost_out' => array_sum(array_column(array_values($prodOut), 'total_base')),
    ];
}

$MOVE_TYPE_MAP = [
    'receive' => ['label' => 'استلام', 'cls' => 'bg-success-subtle text-success', 'icon' => 'bi-box-arrow-in-down'],
    'issue' => ['label' => 'صرف', 'cls' => 'bg-warning-subtle text-warning', 'icon' => 'bi-arrow-bar-up'],
    'return_in' => ['label' => 'إرجاع للمخزن', 'cls' => 'bg-info-subtle text-info', 'icon' => 'bi-arrow-return-left'],
    'return_out' => ['label' => 'إرجاع للمورد', 'cls' => 'bg-secondary-subtle text-secondary', 'icon' => 'bi-arrow-return-right'],
    'transfer' => ['label' => 'نقل', 'cls' => 'bg-primary-subtle text-primary', 'icon' => 'bi-arrows-move'],
    'adjust' => ['label' => 'تسوية', 'cls' => 'bg-dark-subtle text-dark', 'icon' => 'bi-sliders'],
    'waste' => ['label' => 'هالك', 'cls' => 'bg-danger-subtle text-danger', 'icon' => 'bi-trash'],
    'purchase' => ['label' => 'شراء', 'cls' => 'bg-success-subtle text-success', 'icon' => 'bi-cart-plus'],
    'sale' => ['label' => 'بيع', 'cls' => 'bg-primary-subtle text-primary', 'icon' => 'bi-bag'],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>حركات المخزون — <?= htmlspecialchars($branchName) ?></title>
    <link rel="icon" href="<?= BASE_PATH ?>/assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/assets/css/layout.css" rel="stylesheet">
    <style>
        .stat-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 10px
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0
        }

        .stat-val {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1e293b;
            line-height: 1
        }

        .stat-lbl {
            font-size: .7rem;
            color: #64748b;
            margin-top: 2px
        }

        .tbl-wrap {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            overflow: hidden
        }

        .tbl-hdr {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap
        }

        table.mtbl {
            width: 100%;
            border-collapse: collapse;
            font-size: .8rem
        }

        table.mtbl th {
            background: #f8fafc;
            padding: 7px 10px;
            font-weight: 600;
            color: #64748b;
            font-size: .71rem;
            border-bottom: 1px solid #f1f5f9;
            white-space: nowrap
        }

        table.mtbl td {
            padding: 7px 10px;
            border-bottom: 1px solid #f8fafc;
            vertical-align: middle
        }

        table.mtbl tr:last-child td {
            border-bottom: none
        }

        table.mtbl tr:hover td {
            background: #f8faff
        }

        .dir-in {
            color: #16a34a;
            font-weight: 700
        }

        .dir-out {
            color: #dc2626;
            font-weight: 700
        }

        .n {
            font-variant-numeric: tabular-nums
        }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-arrow-left-right me-1 text-primary"></i>حركات المخزون</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
            <?php if ($tab === 'consumables'): ?>
                <a href="../expenses_and_consumables/consumables.php" style="color:#64748b;text-decoration:none">المصاريف والمستهلكات</a>
            <?php else: ?>
                <a href="products.php" style="color:#64748b;text-decoration:none">المخزون</a>
            <?php endif; ?>
            <i class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
            <span class="text-primary">الحركات</span>
        </nav>
    </header>
    <main class="main-content">
        <div class="content-body">

            <!-- تبويبات القسم (مكوّن مشترك — يتبع الشريط الجانبي).
                 $currentModule محسوبة ديناميكياً حسب $tab أعلاه (قبل
                 sidebar.php)، فالمكوّن بيرسم تلقائياً تبويبات القسم
                 الصحيح (مخزون أو مستهلكات) بدون أي شرط يدوي هون. -->
            <?php require __DIR__ . '/../../../includes/tab_bar.php'; ?>

            <!-- تبويبات رئيسية -->
            <ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e2e8f0">
                <li class="nav-item">
                    <a class="nav-link fw-600 <?= $tab === 'consumables' ? 'active' : '' ?>"
                        href="?tab=consumables&from=<?= $dateFrom ?>&to=<?= $dateTo ?>"
                        style="border:none;<?= $tab === 'consumables' ? 'border-bottom:2px solid #1e3a8a;color:#1e3a8a;' : '' ?>font-size:.83rem;margin-bottom:-2px">
                        <i class="bi bi-box-seam me-1"></i>المستهلكات
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600 <?= $tab === 'products' ? 'active' : '' ?>"
                        href="?tab=products&from=<?= $dateFrom ?>&to=<?= $dateTo ?>"
                        style="border:none;<?= $tab === 'products' ? 'border-bottom:2px solid #1e3a8a;color:#1e3a8a;' : '' ?>font-size:.83rem;margin-bottom:-2px">
                        <i class="bi bi-boxes me-1"></i>المنتجات النهائية
                    </a>
                </li>
            </ul>

            <!-- إحصائيات -->
            <?php
            $stats = $tab === 'consumables' ? ($consStats ?? []) : ($prodStats ?? []);
            $totalLabel = $tab === 'consumables' ? 'حركة' : 'عملية';
            ?>
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#eff6ff"><i
                                class="bi bi-arrow-left-right text-primary"></i></div>
                        <div>
                            <div class="stat-val"><?= $stats['total'] ?? 0 ?></div>
                            <div class="stat-lbl">إجمالي الحركات</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#f0fdf4"><i
                                class="bi bi-box-arrow-in-down text-success"></i></div>
                        <div>
                            <div class="stat-val dir-in"><?= number_format($stats['total_in'] ?? 0, 2) ?></div>
                            <div class="stat-lbl">إجمالي الوارد</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fee2e2"><i class="bi bi-box-arrow-up text-danger"></i>
                        </div>
                        <div>
                            <div class="stat-val dir-out"><?= number_format($stats['total_out'] ?? 0, 2) ?></div>
                            <div class="stat-lbl">إجمالي الصادر</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fef3c7"><i
                                class="bi bi-currency-dollar text-warning"></i></div>
                        <div>
                            <div class="stat-val n" style="font-size:.9rem">
                                <span class="dir-in">+<?= htmlspecialchars($baseCurSymbol) ?> <?= number_format($stats['cost_in'] ?? 0, 2) ?></span>
                                <span style="font-size:.75rem;color:#94a3b8;display:block">-<?= htmlspecialchars($baseCurSymbol) ?>
                                    <?= number_format($stats['cost_out'] ?? 0, 2) ?></span>
                            </div>
                            <div class="stat-lbl">التكلفة وارد/صادر</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- فلاتر -->
            <div class="tbl-wrap mb-3">
                <div class="tbl-hdr">
                    <form method="get" class="d-flex gap-2 flex-wrap align-items-center w-100">
                        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                            placeholder="بحث بالاسم أو المرجع..." class="form-control form-control-sm"
                            style="width:180px;border-radius:8px">
                        <select name="wh" class="form-select form-select-sm" style="width:150px;border-radius:8px"
                            onchange="this.form.submit()">
                            <option value="">كل المستودعات</option>
                            <?php foreach ($warehouses as $wh): ?>
                                        <option value="<?= $wh['id'] ?>" <?= $whF == $wh['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($wh['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($tab === 'consumables'): ?>
                                    <select name="type" class="form-select form-select-sm" style="width:120px;border-radius:8px"
                                        onchange="this.form.submit()">
                                        <option value="">كل الأنواع</option>
                                        <?php foreach (['receive' => 'استلام', 'issue' => 'صرف', 'return_in' => 'إرجاع للمخزن', 'return_out' => 'إرجاع للمورد', 'transfer' => 'نقل', 'adjust' => 'تسوية', 'waste' => 'هالك'] as $k => $v): ?>
                                                    <option value="<?= $k ?>" <?= $typeF === $k ? 'selected' : '' ?>><?= $v ?></option>
                                        <?php endforeach; ?>
                                    </select>
                        <?php endif; ?>
                        <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>"
                            class="form-control form-control-sm" style="width:140px;border-radius:8px">
                        <span style="color:#94a3b8;font-size:.8rem">—</span>
                        <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>"
                            class="form-control form-control-sm" style="width:140px;border-radius:8px">
                        <button type="submit" class="btn btn-sm btn-primary" style="border-radius:8px"><i
                                class="bi bi-search me-1"></i>بحث</button>
                        <?php if ($search || $whF || $typeF): ?>
                                    <a href="?tab=<?= $tab ?>&from=<?= $dateFrom ?>&to=<?= $dateTo ?>" class="btn btn-sm btn-light"
                                        style="border-radius:8px"><i class="bi bi-x-lg"></i></a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- جدول الحركات -->
            <div class="tbl-wrap">
                <div class="d-flex align-items-center gap-2 px-3 pt-2">
                    <span style="font-size:.72rem;color:#64748b">النقر على رأس العمود:</span>
                    <div class="btn-group btn-group-sm" role="group">
                        <input type="radio" class="btn-check" name="hdrMode" id="hdrModeSort" checked onchange="setHeaderMode('sort')">
                        <label class="btn btn-outline-primary" for="hdrModeSort" style="font-size:.72rem"><i class="bi bi-sort-down me-1"></i>ترتيب</label>
                        <input type="radio" class="btn-check" name="hdrMode" id="hdrModeFilter" onchange="setHeaderMode('filter')">
                        <label class="btn btn-outline-primary" for="hdrModeFilter" style="font-size:.72rem"><i class="bi bi-funnel me-1"></i>فلترة</label>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary" style="font-size:.7rem;display:none" id="btnClearHdrFilters" onclick="clearAllHeaderFilters()">
                        <i class="bi bi-x-circle me-1"></i>مسح كل الفلاتر
                    </button>
                </div>
                <div class="table-responsive">
                    <?php if ($tab === 'consumables'): ?>
                                <table class="mtbl" id="movementsTbl">
                                    <thead>
                                        <tr>
                                            <th class="sortable-th" data-col="0" data-type="text">رقم الحركة</th>
                                            <th class="sortable-th" data-col="1" data-type="date">التاريخ</th>
                                            <th class="sortable-th" data-col="2" data-type="text">المادة</th>
                                            <th class="sortable-th" data-col="3" data-type="text">المستودع</th>
                                            <th class="sortable-th" data-col="4" data-type="text">النوع</th>
                                            <th class="sortable-th" data-col="5" data-type="text">الاتجاه</th>
                                            <th class="sortable-th text-center" data-col="6" data-type="num">الكمية</th>
                                            <th class="sortable-th text-center" data-col="7" data-type="num">قبل</th>
                                            <th class="sortable-th text-center" data-col="8" data-type="num">بعد</th>
                                            <th class="sortable-th text-center" data-col="9" data-type="num">سعر/وحدة ($)</th>
                                            <th class="sortable-th text-end" data-col="10" data-type="num">التكلفة ($)</th>
                                            <th class="sortable-th" data-col="11" data-type="text">المرجع</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($consMovements)): ?>
                                                    <tr>
                                                        <td colspan="12" class="text-center text-muted py-5">
                                                            <i class="bi bi-arrow-left-right d-block mb-2"
                                                                style="font-size:2rem;opacity:.2"></i>
                                                            لا توجد حركات في هذه الفترة
                                                        </td>
                                                    </tr>
                                        <?php endif; ?>
                                        <?php foreach ($consMovements as $mov):
                                            $mt = $MOVE_TYPE_MAP[$mov['movement_type']] ?? ['label' => $mov['movement_type'], 'cls' => 'bg-secondary-subtle text-secondary', 'icon' => 'bi-circle'];
                                            ?>
                                                    <tr>
                                                        <td dir="ltr" style="font-size:.72rem;color:#94a3b8" data-sort="<?= htmlspecialchars($mov['movement_no']) ?>">
                                                            <?= htmlspecialchars($mov['movement_no']) ?></td>
                                                        <td class="text-muted" data-sort="<?= htmlspecialchars($mov['movement_date']) ?>"><?= $mov['movement_date'] ?></td>
                                                        <td data-sort="<?= htmlspecialchars($mov['item_name']) ?>">
                                                            <div class="fw-600"><?= htmlspecialchars($mov['item_name']) ?></div>
                                                            <div style="font-size:.7rem;color:#94a3b8"><?= htmlspecialchars($mov['unit']) ?>
                                                            </div>
                                                        </td>
                                                        <td style="font-size:.78rem" data-sort="<?= htmlspecialchars($mov['wh_name'] ?? '') ?>"><?= htmlspecialchars($mov['wh_name'] ?? '—') ?></td>
                                                        <td data-sort="<?= htmlspecialchars($mt['label']) ?>"><span class="badge <?= $mt['cls'] ?>" style="font-size:.68rem"><i
                                                                    class="bi <?= $mt['icon'] ?> me-1"></i><?= $mt['label'] ?></span></td>
                                                        <td data-sort="<?= $mov['direction'] === 'in' ? 'وارد' : 'صادر' ?>">
                                                            <?php if ($mov['direction'] === 'in'): ?>
                                                                        <span class="dir-in"><i class="bi bi-arrow-down-circle me-1"></i>وارد</span>
                                                            <?php else: ?>
                                                                        <span class="dir-out"><i class="bi bi-arrow-up-circle me-1"></i>صادر</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="n text-center fw-600 <?= $mov['direction'] === 'in' ? 'dir-in' : 'dir-out' ?>" data-sort="<?= $mov['direction'] === 'in' ? '' : '-' ?><?= $mov['quantity'] ?>">
                                                            <?= $mov['direction'] === 'in' ? '+' : '-' ?>                        <?= number_format($mov['quantity'], 3) ?>
                                                        </td>
                                                        <td class="n text-center text-muted" style="font-size:.75rem" data-sort="<?= $mov['qty_before'] ?>">
                                                            <?= number_format($mov['qty_before'], 3) ?></td>
                                                        <td class="n text-center text-muted" style="font-size:.75rem" data-sort="<?= $mov['qty_after'] ?>">
                                                            <?= number_format($mov['qty_after'], 3) ?></td>
                                                        <td class="n text-center" style="font-size:.75rem" data-sort="<?= $mov['unit_cost_base'] ?>">$
                                                            <?= number_format($mov['unit_cost_base'], 4) ?></td>
                                                        <td class="n text-end fw-600" data-sort="<?= $mov['total_cost_base'] ?>"><?= htmlspecialchars($baseCurSymbol) ?> <?= number_format($mov['total_cost_base'], 2) ?></td>
                                                        <td style="font-size:.72rem;color:#64748b" data-sort="<?= htmlspecialchars(refTypeLabel($mov['reference_type'], $REF_TYPE_AR)) ?> #<?= $mov['reference_id'] ?? '' ?>">
                                                            <?= htmlspecialchars(refTypeLabel($mov['reference_type'], $REF_TYPE_AR)) ?> #<?= $mov['reference_id'] ?? '—' ?>
                                                        </td>
                                                    </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>

                    <?php else: // products ?>
                                <table class="mtbl" id="movementsTbl">
                                    <thead>
                                        <tr>
                                            <th class="sortable-th" data-col="0" data-type="date">التاريخ</th>
                                            <th class="sortable-th" data-col="1" data-type="text">العملية</th>
                                            <th class="sortable-th" data-col="2" data-type="text">المنتج</th>
                                            <th class="sortable-th" data-col="3" data-type="text">القياس</th>
                                            <th class="sortable-th" data-col="4" data-type="text">اللون</th>
                                            <th class="sortable-th" data-col="5" data-type="text">المستودع</th>
                                            <th class="sortable-th" data-col="6" data-type="text">المرجع</th>
                                            <th class="sortable-th" data-col="7" data-type="text">الطرف الآخر</th>
                                            <th class="sortable-th text-center" data-col="8" data-type="num">الكمية</th>
                                            <th class="sortable-th text-end" data-col="9" data-type="num">القيمة ($)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($prodMovements)): ?>
                                                    <tr>
                                                        <td colspan="10" class="text-center text-muted py-5">
                                                            <i class="bi bi-boxes d-block mb-2" style="font-size:2rem;opacity:.2"></i>
                                                            لا توجد حركات في هذه الفترة
                                                        </td>
                                                    </tr>
                                        <?php endif; ?>
                                        <?php foreach ($prodMovements as $mov):
                                            $mt = $MOVE_TYPE_MAP[$mov['movement_type']] ?? ['label' => $mov['movement_type'], 'cls' => 'bg-secondary-subtle text-secondary', 'icon' => 'bi-circle'];
                                            $party = $mov['party_name'] ?? '—';
                                            ?>
                                                    <tr>
                                                        <td class="text-muted" data-sort="<?= htmlspecialchars($mov['movement_date']) ?>"><?= $mov['movement_date'] ?></td>
                                                        <td data-sort="<?= htmlspecialchars($mt['label']) ?>"><span class="badge <?= $mt['cls'] ?>" style="font-size:.68rem"><i
                                                                    class="bi <?= $mt['icon'] ?> me-1"></i><?= $mt['label'] ?></span></td>
                                                        <td data-sort="<?= htmlspecialchars($mov['product_name']) ?>">
                                                            <div class="fw-600" style="font-size:.8rem">
                                                                <?= htmlspecialchars($mov['product_name']) ?></div>
                                                            <div style="font-size:.7rem;color:#94a3b8" dir="ltr">
                                                                <?= htmlspecialchars($mov['model_number'] ?? '') ?></div>
                                                        </td>
                                                        <td style="font-size:.78rem" data-sort="<?= htmlspecialchars($mov['size'] ?? '') ?>"><?= $mov['size'] ?? '—' ?></td>
                                                        <td style="font-size:.78rem" data-sort="<?= htmlspecialchars($mov['color'] ?? '') ?>"><?= $mov['color'] ?? '—' ?></td>
                                                        <td style="font-size:.78rem" data-sort="<?= htmlspecialchars($mov['wh_name'] ?? '') ?>"><?= htmlspecialchars($mov['wh_name'] ?? '—') ?></td>
                                                        <td style="font-size:.72rem;color:#1e3a8a;font-weight:600" dir="ltr" data-sort="<?= htmlspecialchars($mov['ref_no'] ?? '') ?>">
                                                            <?= htmlspecialchars($mov['ref_no'] ?? '—') ?></td>
                                                        <td style="font-size:.78rem" data-sort="<?= htmlspecialchars($party) ?>"><?= htmlspecialchars($party) ?></td>
                                                        <td class="n text-center fw-600 <?= $mov['direction'] === 'in' ? 'dir-in' : 'dir-out' ?>" data-sort="<?= $mov['direction'] === 'in' ? '' : '-' ?><?= $mov['quantity'] ?>">
                                                            <?= $mov['direction'] === 'in' ? '+' : '-' ?>                        <?= number_format($mov['quantity'], 0) ?>
                                                        </td>
                                                        <td class="n text-end fw-600" data-sort="<?= $mov['total_base'] ?>"><?= htmlspecialchars($baseCurSymbol) ?> <?= number_format($mov['total_base'], 2) ?></td>
                                                    </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const sb = document.getElementById('sidebar'), ov = document.getElementById('sbOverlay');
        function sbOpen() { sb.classList.add('open'); ov.classList.add('show'); }
        function sbClose() { sb.classList.remove('open'); ov.classList.remove('show'); }
        window.addEventListener('resize', () => { if (window.innerWidth > 991) sbClose(); });
        function toggleGroup(g) { const o = g.classList.contains('open'); document.querySelectorAll('.sb-group.open').forEach(x => x.classList.remove('open')); g.classList.toggle('open', !o); localStorage.setItem('sb_open_' + g.dataset.key, (!o).toString()); }
        document.querySelectorAll('.sb-group').forEach(g => { if (localStorage.getItem('sb_open_' + g.dataset.key) === 'true') g.classList.add('open'); });
    
        // ══════════════════════════════════════════════════════════
        // فرز الجداول بالنقر على رأس العمود — عام لأي جدول بالصفحة
        // ══════════════════════════════════════════════════════════
        // ── ترتيب/فلترة رأس الجدول (مكوّن مشترك — راجع FATORIZE-DESIGN-AND-PATTERNS.md § ب) ──
        let _hdrMode = 'sort';
        let _sortCol = null, _sortDir = 1;
        const _activeFilters = {};

        function setHeaderMode(mode) {
            _hdrMode = mode;
            document.querySelectorAll('.hdr-filter-pop').forEach(p => p.remove());
        }

        (function initTableHeaders() {
            const table = document.getElementById('movementsTbl');
            if (!table) return;
            table.querySelectorAll('th.sortable-th').forEach(th => {
                th.style.cursor = 'pointer';
                th.addEventListener('click', (e) => {
                    if (_hdrMode === 'sort') sortByColumn(th);
                    else { e.stopPropagation(); openFilterPopover(th); }
                });
            });
        })();

        function sortByColumn(th) {
            const col = parseInt(th.dataset.col);
            const type = th.dataset.type;
            if (_sortCol === col) _sortDir *= -1; else { _sortCol = col; _sortDir = 1; }
            document.querySelectorAll('th.sortable-th').forEach(h => h.innerHTML = h.innerHTML.replace(/\s*[▲▼]$/, ''));
            th.innerHTML += _sortDir === 1 ? ' ▲' : ' ▼';
            const tbody = th.closest('table').querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.cells.length > 1);
            rows.sort((a, b) => {
                let va = a.cells[col]?.dataset.sort ?? '', vb = b.cells[col]?.dataset.sort ?? '';
                if (type === 'num') { va = parseFloat(va) || 0; vb = parseFloat(vb) || 0; return (va - vb) * _sortDir; }
                if (type === 'date') return (new Date(va) - new Date(vb)) * _sortDir;
                return va.localeCompare(vb, 'ar') * _sortDir;
            });
            rows.forEach(r => tbody.appendChild(r));
        }

        function openFilterPopover(th) {
            document.querySelectorAll('.hdr-filter-pop').forEach(p => p.remove());
            const col = parseInt(th.dataset.col);
            const table = th.closest('table');
            const tbody = table.querySelector('tbody');
            const allRows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.cells.length > 1);
            const values = [...new Set(allRows.map(r => (r.cells[col]?.dataset.sort ?? '').trim()).filter(v => v !== ''))]
                .sort((a, b) => a.localeCompare(b, 'ar'));
            const selected = _activeFilters[col] || new Set(values);
            const pop = document.createElement('div');
            pop.className = 'hdr-filter-pop';
            pop.style.cssText = 'position:fixed;z-index:2000;background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.15);padding:8px;max-height:260px;overflow-y:auto;min-width:170px;font-size:.75rem';
            pop.innerHTML = `
                <div class="d-flex justify-content-between mb-1">
                    <button class="btn btn-link btn-sm p-0" style="font-size:.7rem" onclick="_toggleAllFilter(${col},true)">تحديد الكل</button>
                    <button class="btn btn-link btn-sm p-0" style="font-size:.7rem" onclick="_toggleAllFilter(${col},false)">إلغاء الكل</button>
                </div>
                ${values.map(v => `
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input filter-chk" data-val="${v.replace(/"/g, '&quot;')}" ${selected.has(v) ? 'checked' : ''}>
                        <label class="form-check-label" style="cursor:pointer">${v}</label>
                    </div>`).join('')}
                <div class="d-flex gap-1 mt-2">
                    <button class="btn btn-sm btn-primary flex-fill" style="font-size:.7rem" onclick="_applyFilter(${col})">تطبيق</button>
                    <button class="btn btn-sm btn-light" style="font-size:.7rem" onclick="document.querySelectorAll('.hdr-filter-pop').forEach(p=>p.remove())">إغلاق</button>
                </div>`;
            pop._values = values;
            pop._table = table;
            const rect = th.getBoundingClientRect();
            document.body.appendChild(pop);
            let left = rect.left;
            if (left + pop.offsetWidth > window.innerWidth - 10) left = window.innerWidth - pop.offsetWidth - 10;
            pop.style.top = (rect.bottom + 4) + 'px';
            pop.style.left = Math.max(10, left) + 'px';
            pop.addEventListener('click', e => e.stopPropagation());
        }

        function _toggleAllFilter(col, checked) {
            document.querySelectorAll('.hdr-filter-pop .filter-chk').forEach(c => c.checked = checked);
        }

        function _applyFilter(col) {
            const pop = document.querySelector('.hdr-filter-pop');
            const checked = new Set(Array.from(pop.querySelectorAll('.filter-chk:checked')).map(c => c.dataset.val));
            if (checked.size === pop._values.length) delete _activeFilters[col];
            else _activeFilters[col] = checked;
            const table = pop._table;
            pop.remove();
            _renderFilters(table);
        }

        function _renderFilters(table) {
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.cells.length > 1);
            const hasFilters = Object.keys(_activeFilters).length > 0;
            const clearBtn = document.getElementById('btnClearHdrFilters');
            if (clearBtn) clearBtn.style.display = hasFilters ? '' : 'none';
            rows.forEach(r => {
                let visible = true;
                for (const col in _activeFilters) {
                    const val = (r.cells[col]?.dataset.sort ?? '').trim();
                    if (!_activeFilters[col].has(val)) { visible = false; break; }
                }
                r.style.display = visible ? '' : 'none';
            });
            table.querySelectorAll('th.sortable-th').forEach((h) => {
                h.style.background = _activeFilters[h.dataset.col] ? '#fef3c7' : '';
            });
        }

        function clearAllHeaderFilters() {
            Object.keys(_activeFilters).forEach(k => delete _activeFilters[k]);
            _renderFilters(document.getElementById('movementsTbl'));
        }

        document.addEventListener('click', () => document.querySelectorAll('.hdr-filter-pop').forEach(p => p.remove()));
    </script>
</body>

</html>