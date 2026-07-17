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
$currentModule = 'inventory.movements';

$TS = $_SESSION['table_suffix'];
$TCM = "consumable_movements_{$TS}";
$TCI = "consumable_items_{$TS}";
$TW = "warehouses_{$TS}";
$TPI = "purchase_items_{$TS}";
$TP = "purchases_{$TS}";
$TSI = "sales_invoices_{$TS}";
$TSII = "sales_invoice_items_{$TS}";
$TPROD = "products_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// ── فلاتر ──
// ⚠ الافتراضي صار 'products' (كان 'consumables') — يطابق عنوان/أيقونة
// الصفحة "حركات المخزون". لما تُربط من قسم "المصاريف والمستهلكات"
// المستقبلي بالشريط الجانبي، مرّر ?tab=consumables صراحة دايماً.
$tab = $_GET['tab'] ?? 'products';
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
        ci.name AS item_name, ci.unit,
        w.name AS wh_name
        FROM `{$TCM}` m
        JOIN `{$TCI}` ci ON ci.id=m.item_id
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
        COALESCE(SUM(CASE WHEN direction='in' THEN total_cost_usd ELSE 0 END),0) AS cost_in,
        COALESCE(SUM(CASE WHEN direction='out' THEN total_cost_usd ELSE 0 END),0) AS cost_out
        FROM `{$TCM}` m
        JOIN `{$TCI}` ci ON ci.id=m.item_id
        {$where}");
    $consStats->execute($params);
    $consStats = $consStats->fetch();
}

// ── حركات المنتجات النهائية ──
$prodMovements = [];
if ($tab === 'products') {
    // جلب حركات الشراء
    $whereP = "WHERE p.status='received' AND p.purchase_date BETWEEN ? AND ?";
    $paramsP = [$dateFrom, $dateTo];
    if ($whF) {
        $whereP .= ' AND pi.warehouse_id=?';
        $paramsP[] = $whF;
    }
    if ($search) {
        $whereP .= ' AND pi.product_name LIKE ?';
        $paramsP[] = "%{$search}%";
    }

    $stmtPur = $pdo->prepare("SELECT 'purchase' AS movement_type, 'in' AS direction,
        pi.product_name, pi.model_number, pi.size, pi.color,
        pi.quantity, pi.unit_price AS unit_price_usd, pi.total_price AS total_usd,
        p.purchase_number AS ref_no, p.purchase_date AS movement_date,
        s.name AS party_name, w.name AS wh_name
        FROM `{$TPI}` pi
        JOIN `{$TP}` p ON p.id=pi.purchase_id
        LEFT JOIN product_suppliers_{$TS} s ON s.id=p.supplier_id
        LEFT JOIN `{$TW}` w ON w.id=pi.warehouse_id
        {$whereP} LIMIT 150");
    $stmtPur->execute($paramsP);
    $purRows = $stmtPur->fetchAll();

    // جلب حركات البيع
    $whereS = "WHERE inv.status='confirmed' AND inv.invoice_date BETWEEN ? AND ?";
    $paramsS = [$dateFrom, $dateTo];
    if ($whF) {
        $whereS .= ' AND si.warehouse_id=?';
        $paramsS[] = $whF;
    }
    if ($search) {
        $whereS .= ' AND si.item_name LIKE ?';
        $paramsS[] = "%{$search}%";
    }

    $stmtSal = $pdo->prepare("SELECT 'sale' AS movement_type, 'out' AS direction,
        si.item_name AS product_name, si.model_number, si.size, si.color,
        si.quantity, si.unit_price AS unit_price_usd, si.total_price AS total_usd,
        inv.invoice_number AS ref_no, inv.invoice_date AS movement_date,
        inv.customer_name AS party_name, w.name AS wh_name
        FROM `{$TSII}` si
        JOIN `{$TSI}` inv ON inv.id=si.invoice_id
        LEFT JOIN `{$TW}` w ON w.id=si.warehouse_id
        {$whereS} LIMIT 150");
    $stmtSal->execute($paramsS);
    $salRows = $stmtSal->fetchAll();

    // دمج وترتيب
    $prodMovements = array_merge($purRows, $salRows);
    usort($prodMovements, function ($a, $b) {
        return strcmp($b['movement_date'], $a['movement_date']);
    });

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
        'cost_in' => array_sum(array_column(array_values($prodIn), 'total_usd')),
        'cost_out' => array_sum(array_column(array_values($prodOut), 'total_usd')),
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
    </header>
    <main class="main-content">
        <div class="content-body">

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
                                <span class="dir-in">+$ <?= number_format($stats['cost_in'] ?? 0, 2) ?></span>
                                <span style="font-size:.75rem;color:#94a3b8;display:block">-$
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
                <div class="table-responsive">
                    <?php if ($tab === 'consumables'): ?>
                                <table class="mtbl">
                                    <thead>
                                        <tr>
                                            <th>رقم الحركة</th>
                                            <th>التاريخ</th>
                                            <th>المادة</th>
                                            <th>المستودع</th>
                                            <th>النوع</th>
                                            <th>الاتجاه</th>
                                            <th class="text-center">الكمية</th>
                                            <th class="text-center">قبل</th>
                                            <th class="text-center">بعد</th>
                                            <th class="text-center">سعر/وحدة ($)</th>
                                            <th class="text-end">التكلفة ($)</th>
                                            <th>المرجع</th>
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
                                                        <td dir="ltr" style="font-size:.72rem;color:#94a3b8">
                                                            <?= htmlspecialchars($mov['movement_no']) ?></td>
                                                        <td class="text-muted"><?= $mov['movement_date'] ?></td>
                                                        <td>
                                                            <div class="fw-600"><?= htmlspecialchars($mov['item_name']) ?></div>
                                                            <div style="font-size:.7rem;color:#94a3b8"><?= htmlspecialchars($mov['unit']) ?>
                                                            </div>
                                                        </td>
                                                        <td style="font-size:.78rem"><?= htmlspecialchars($mov['wh_name'] ?? '—') ?></td>
                                                        <td><span class="badge <?= $mt['cls'] ?>" style="font-size:.68rem"><i
                                                                    class="bi <?= $mt['icon'] ?> me-1"></i><?= $mt['label'] ?></span></td>
                                                        <td>
                                                            <?php if ($mov['direction'] === 'in'): ?>
                                                                        <span class="dir-in"><i class="bi bi-arrow-down-circle me-1"></i>وارد</span>
                                                            <?php else: ?>
                                                                        <span class="dir-out"><i class="bi bi-arrow-up-circle me-1"></i>صادر</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="n text-center fw-600 <?= $mov['direction'] === 'in' ? 'dir-in' : 'dir-out' ?>">
                                                            <?= $mov['direction'] === 'in' ? '+' : '-' ?>                        <?= number_format($mov['quantity'], 3) ?>
                                                        </td>
                                                        <td class="n text-center text-muted" style="font-size:.75rem">
                                                            <?= number_format($mov['qty_before'], 3) ?></td>
                                                        <td class="n text-center text-muted" style="font-size:.75rem">
                                                            <?= number_format($mov['qty_after'], 3) ?></td>
                                                        <td class="n text-center" style="font-size:.75rem">$
                                                            <?= number_format($mov['unit_cost_usd'], 4) ?></td>
                                                        <td class="n text-end fw-600">$ <?= number_format($mov['total_cost_usd'], 2) ?></td>
                                                        <td style="font-size:.72rem;color:#64748b">
                                                            <?= htmlspecialchars($mov['reference_type']) ?> #<?= $mov['reference_id'] ?? '—' ?>
                                                        </td>
                                                    </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>

                    <?php else: // products ?>
                                <table class="mtbl">
                                    <thead>
                                        <tr>
                                            <th>التاريخ</th>
                                            <th>العملية</th>
                                            <th>المنتج</th>
                                            <th>القياس</th>
                                            <th>اللون</th>
                                            <th>المستودع</th>
                                            <th>المرجع</th>
                                            <th>الطرف الآخر</th>
                                            <th class="text-center">الكمية</th>
                                            <th class="text-end">القيمة ($)</th>
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
                                                        <td class="text-muted"><?= $mov['movement_date'] ?></td>
                                                        <td><span class="badge <?= $mt['cls'] ?>" style="font-size:.68rem"><i
                                                                    class="bi <?= $mt['icon'] ?> me-1"></i><?= $mt['label'] ?></span></td>
                                                        <td>
                                                            <div class="fw-600" style="font-size:.8rem">
                                                                <?= htmlspecialchars($mov['product_name']) ?></div>
                                                            <div style="font-size:.7rem;color:#94a3b8" dir="ltr">
                                                                <?= htmlspecialchars($mov['model_number'] ?? '') ?></div>
                                                        </td>
                                                        <td style="font-size:.78rem"><?= $mov['size'] ?? '—' ?></td>
                                                        <td style="font-size:.78rem"><?= $mov['color'] ?? '—' ?></td>
                                                        <td style="font-size:.78rem"><?= htmlspecialchars($mov['wh_name'] ?? '—') ?></td>
                                                        <td style="font-size:.72rem;color:#1e3a8a;font-weight:600" dir="ltr">
                                                            <?= htmlspecialchars($mov['ref_no'] ?? '—') ?></td>
                                                        <td style="font-size:.78rem"><?= htmlspecialchars($party) ?></td>
                                                        <td class="n text-center fw-600 <?= $mov['direction'] === 'in' ? 'dir-in' : 'dir-out' ?>">
                                                            <?= $mov['direction'] === 'in' ? '+' : '-' ?>                        <?= number_format($mov['quantity'], 0) ?>
                                                        </td>
                                                        <td class="n text-end fw-600">$ <?= number_format($mov['total_usd'], 2) ?></td>
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
    </script>
</body>

</html>