<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
/**
 * reports.php — تقارير المنتجات (حركة / تاريخ أسعار / ربح)
 * retail1/modules/inventory/reports.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('inventory.reports', 'view');
$currentModule = 'inventory.reports'; // ✅ كانت غير معرّفة — الشريط الجانبي
                                      // بيقرا هالمتغيّر ليعرف أي عنصر يفعّل،
                                      // وبدونها ما بينفعّل ولا عنصر إطلاقاً

$branchName = $_SESSION['branch_name'] ?? 'الفرع';
$TS   = $_SESSION['table_suffix'];
$TP   = "products_{$TS}";
$TV   = "product_variants_{$TS}";
$TSZ  = "product_sizes_{$TS}";
$TCL  = "product_colors_{$TS}";
$TIM  = "inventory_movements_{$TS}";
$TIMD = "inventory_movement_details_{$TS}";
$TPUR = "purchases_{$TS}";
$TSI  = "sales_invoices_{$TS}";
$TSP  = "product_suppliers_{$TS}";

// عملة الفرع الأساسية — كل الأرقام هون بعملة الفرع مباشرة (نفس اتفاقية
// كل صفحات القسم — راجع Deferred currency features بالـREADME)
$branchCurRow = $pdo->prepare("SELECT c.symbol FROM branches b
    JOIN currencies c ON c.id = b.base_currency_id
    WHERE b.table_suffix = ? LIMIT 1");
$branchCurRow->execute([$TS]);
$baseCurSymbol = $branchCurRow->fetchColumn() ?: '$';

// ── AJAX ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        if ($act === 'search_products') {
            $q = trim($_POST['q'] ?? '');
            if (strlen($q) < 2) throw new Exception('اكتب حرفين على الأقل');
            $st = $pdo->prepare("SELECT id, name, model_number FROM `{$TP}`
                WHERE (name LIKE ? OR model_number LIKE ?) AND is_active=1
                ORDER BY name LIMIT 20");
            $like = "%{$q}%";
            $st->execute([$like, $like]);
            echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        }

        elseif ($act === 'get_product_reports') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            if (!$productId) throw new Exception('منتج غير محدَّد');

            $p = $pdo->prepare("SELECT id, name, model_number FROM `{$TP}` WHERE id=?");
            $p->execute([$productId]);
            $product = $p->fetch(PDO::FETCH_ASSOC);
            if (!$product) throw new Exception('المنتج غير موجود');

            // ── ١) حركة المنتج (من دفتر الحركات الحقيقي) ──
            $movSt = $pdo->prepare("SELECT im.reference_type, im.movement_type AS direction,
                imd.quantity, imd.unit_price, imd.balance_after,
                im.reference_number, im.created_at,
                psz.size, pcl.name AS color_name
                FROM `{$TIMD}` imd
                JOIN `{$TIM}` im ON im.id = imd.movement_id
                LEFT JOIN `{$TV}` pv ON pv.id = imd.variant_id
                LEFT JOIN `{$TSZ}` psz ON psz.id = pv.size_id
                LEFT JOIN `{$TCL}` pcl ON pcl.id = pv.color_id
                WHERE imd.product_id = ?
                ORDER BY im.created_at DESC LIMIT 300");
            $movSt->execute([$productId]);
            $movements = $movSt->fetchAll(PDO::FETCH_ASSOC);

            // ── ٢) تاريخ أسعار الشراء الفعلية ──
            $priceSt = $pdo->prepare("SELECT im.created_at, imd.unit_price, imd.quantity,
                im.reference_number, sp.name AS supplier_name
                FROM `{$TIMD}` imd
                JOIN `{$TIM}` im ON im.id = imd.movement_id
                LEFT JOIN `{$TPUR}` pu ON pu.id = im.reference_id AND im.reference_type = 'purchase'
                LEFT JOIN `{$TSP}` sp ON sp.id = pu.supplier_id
                WHERE imd.product_id = ? AND im.reference_type = 'purchase'
                ORDER BY im.created_at ASC LIMIT 300");
            $priceSt->execute([$productId]);
            $priceHistory = $priceSt->fetchAll(PDO::FETCH_ASSOC);

            // ── ٣) الربح (من عمليات البيع — سعر البيع مقابل التكلفة وقت البيع) ──
            $profitSt = $pdo->prepare("SELECT im.created_at, imd.quantity, imd.unit_price AS sale_price,
                imd.cost_price, im.reference_number, cu.name AS customer_name
                FROM `{$TIMD}` imd
                JOIN `{$TIM}` im ON im.id = imd.movement_id
                LEFT JOIN `{$TSI}` si ON si.id = im.reference_id AND im.reference_type = 'sale'
                LEFT JOIN customers_{$TS} cu ON cu.id = si.customer_id
                WHERE imd.product_id = ? AND im.reference_type = 'sale'
                ORDER BY im.created_at DESC LIMIT 300");
            $profitSt->execute([$productId]);
            $profitRows = $profitSt->fetchAll(PDO::FETCH_ASSOC);

            $totalRevenue = 0.0;
            $totalCost = 0.0;
            foreach ($profitRows as &$r) {
                $qty = (float) $r['quantity'];
                $sale = (float) $r['sale_price'];
                $cost = (float) ($r['cost_price'] ?? 0);
                $r['profit'] = round(($sale - $cost) * $qty, 2);
                $r['revenue'] = round($sale * $qty, 2);
                $totalRevenue += $r['revenue'];
                $totalCost += $cost * $qty;
            }
            unset($r);
            $totalProfit = round($totalRevenue - $totalCost, 2);
            $margin = $totalRevenue > 0 ? round(($totalProfit / $totalRevenue) * 100, 1) : 0;

            echo json_encode([
                'ok' => true,
                'product' => $product,
                'movements' => $movements,
                'price_history' => $priceHistory,
                'profit_rows' => $profitRows,
                'summary' => [
                    'total_revenue' => round($totalRevenue, 2),
                    'total_cost' => round($totalCost, 2),
                    'total_profit' => $totalProfit,
                    'margin' => $margin,
                ],
            ]);
        }

        else {
            throw new Exception('إجراء غير معروف');
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>تقارير المنتجات — <?= htmlspecialchars($branchName) ?></title>
    <link rel="icon" href="<?= BASE_PATH ?>/assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/assets/css/layout.css" rel="stylesheet">
    <style>
        .stat-card { background: #fff; border-radius: 14px; border: 1px solid #e2e8f0; padding: .85rem 1.1rem; display: flex; align-items: center; gap: .85rem }
        .stat-icon { width: 40px; height: 40px; border-radius: 11px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0 }
        .stat-val { font-size: 1.25rem; font-weight: 700; line-height: 1.1 }
        .stat-lbl { font-size: .72rem; color: #64748b; margin-top: .15rem }
        .rep-tabs { display: flex; gap: .4rem; margin-bottom: 1rem; flex-wrap: wrap }
        .rep-tab { border: 1px solid #e2e8f0; background: #fff; border-radius: 10px; padding: .5rem 1.1rem; font-size: .82rem; font-weight: 600; color: #64748b; cursor: pointer }
        .rep-tab.active { background: #1e3a8a; color: #fff; border-color: #1e3a8a }
        .dir-in { color: #16a34a; font-weight: 700 }
        .dir-out { color: #dc2626; font-weight: 700 }
        #searchResults { position: absolute; z-index: 50; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.08); width: 100%; max-height: 280px; overflow-y: auto; display: none }
        #searchResults .res-item { padding: .6rem 1rem; cursor: pointer; font-size: .85rem; border-bottom: 1px solid #f1f5f9 }
        #searchResults .res-item:hover { background: #f8fafc }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>

    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-bar-chart-line me-1 text-primary"></i>تقارير المنتجات</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.8rem;color:#94a3b8">
            <span>المخزون</span><i class="bi bi-chevron-left mx-1" style="font-size:.7rem"></i>
            <span class="text-primary">التقارير</span>
        </nav>
    </header>

    <main class="main-content">
        <div class="content-body">

            <ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e2e8f0">
                <li class="nav-item"><a class="nav-link fw-600" href="products.php"
                        style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-boxes me-1"></i>المنتجات</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="warehouse.php?type=products"
                        style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-building me-1"></i>مستودعات المنتجات</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="movements.php?tab=products"
                        style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-arrow-left-right me-1"></i>حركة المخزون</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="internal_orders.php"
                        style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-signpost-split me-1"></i>الطلبات الداخلية</a></li>
                <li class="nav-item"><a class="nav-link fw-600 active" href="#"
                        style="border:none;border-bottom:2px solid #1e3a8a;color:#1e3a8a;font-size:.83rem;margin-bottom:-2px"><i
                            class="bi bi-bar-chart-line me-1"></i>التقارير</a></li>
            </ul>

            <!-- بحث عن منتج -->
            <div class="position-relative mb-4" style="max-width:420px">
                <input type="text" id="prodSearch" class="form-control" placeholder="ابحث باسم المنتج أو رقم الموديل..."
                    autocomplete="off" oninput="searchProducts(this.value)">
                <div id="searchResults"></div>
            </div>

            <div id="reportArea">
                <div class="text-center text-muted py-5">
                    <i class="bi bi-search fs-1 d-block mb-2 opacity-50"></i>
                    ابحث عن منتج بالأعلى لعرض تقاريره
                </div>
            </div>

        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= BASE_PATH ?>/assets/js/sidebar.js"></script>
    <script>
        const baseCurSymbol = <?= json_encode($baseCurSymbol) ?>;

        function post(data) {
            const fd = new FormData();
            Object.entries(data).forEach(([k, v]) => fd.append(k, v ?? ''));
            return fetch(location.href, { method: 'POST', body: fd }).then(r => r.json());
        }

        let _searchTimer = null;
        function searchProducts(q) {
            clearTimeout(_searchTimer);
            const box = document.getElementById('searchResults');
            if (q.trim().length < 2) { box.style.display = 'none'; return; }
            _searchTimer = setTimeout(() => {
                post({ _action: 'search_products', q }).then(d => {
                    if (!d.ok || !d.data.length) { box.innerHTML = '<div class="res-item text-muted">لا نتائج</div>'; box.style.display = 'block'; return; }
                    box.innerHTML = d.data.map(p => `
                        <div class="res-item" onclick="loadReports(${p.id}, '${p.name.replace(/'/g, "\\'")}')">
                            <b>${p.name}</b> <span class="text-muted" style="font-size:.75rem">— ${p.model_number}</span>
                        </div>`).join('');
                    box.style.display = 'block';
                });
            }, 300);
        }
        document.addEventListener('click', e => {
            if (!e.target.closest('#searchResults') && e.target.id !== 'prodSearch') {
                document.getElementById('searchResults').style.display = 'none';
            }
        });

        let _repData = null;
        function loadReports(productId, productName) {
            document.getElementById('searchResults').style.display = 'none';
            document.getElementById('prodSearch').value = productName;
            document.getElementById('reportArea').innerHTML = '<div class="text-center py-5"><span class="spinner-border text-primary"></span></div>';

            post({ _action: 'get_product_reports', product_id: productId }).then(d => {
                if (!d.ok) { document.getElementById('reportArea').innerHTML = `<div class="alert alert-danger">${d.msg}</div>`; return; }
                _repData = d;
                renderReportShell();
                showRepTab('movement');
            });
        }

        function renderReportShell() {
            document.getElementById('reportArea').innerHTML = `
                <h6 class="fw-bold mb-3">${_repData.product.name} — ${_repData.product.model_number}</h6>
                <div class="rep-tabs">
                    <div class="rep-tab" data-tab="movement" onclick="showRepTab('movement')"><i class="bi bi-arrow-left-right me-1"></i>حركة المنتج</div>
                    <div class="rep-tab" data-tab="price" onclick="showRepTab('price')"><i class="bi bi-graph-up me-1"></i>تاريخ أسعار الشراء</div>
                    <div class="rep-tab" data-tab="profit" onclick="showRepTab('profit')"><i class="bi bi-cash-coin me-1"></i>الربح</div>
                </div>
                <div id="repBody"></div>`;
        }

        function showRepTab(tab) {
            document.querySelectorAll('.rep-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
            const body = document.getElementById('repBody');
            if (tab === 'movement') body.innerHTML = renderMovementTab();
            else if (tab === 'price') body.innerHTML = renderPriceTab();
            else body.innerHTML = renderProfitTab();
        }

        function renderMovementTab() {
            const rows = _repData.movements;
            if (!rows.length) return '<div class="text-muted text-center py-4">لا توجد حركات مسجَّلة لهذا المنتج</div>';
            const refLabel = { purchase: 'شراء', sale: 'بيع', purchase_return: 'مرتجع شراء', sale_return: 'مرتجع بيع' };
            return `<div class="table-responsive"><table class="mtbl"><thead><tr>
                <th>التاريخ</th><th>النوع</th><th>المرجع</th><th>المقاس</th><th>اللون</th>
                <th>الاتجاه</th><th>الكمية</th><th>السعر</th><th>الرصيد بعدها</th>
            </tr></thead><tbody>
                ${rows.map(r => `<tr>
                    <td>${r.created_at}</td>
                    <td>${refLabel[r.reference_type] || r.reference_type}</td>
                    <td class="mono">${r.reference_number || '—'}</td>
                    <td>${r.size || '—'}</td>
                    <td>${r.color_name || '—'}</td>
                    <td class="${r.direction === 'in' ? 'dir-in' : 'dir-out'}">${r.direction === 'in' ? 'دخول ▲' : 'خروج ▼'}</td>
                    <td>${r.quantity}</td>
                    <td>${parseFloat(r.unit_price).toFixed(2)} ${baseCurSymbol}</td>
                    <td class="fw-600">${r.balance_after}</td>
                </tr>`).join('')}
            </tbody></table></div>`;
        }

        function renderPriceTab() {
            const rows = _repData.price_history;
            if (!rows.length) return '<div class="text-muted text-center py-4">لا توجد عمليات شراء مسجَّلة لهذا المنتج بعد</div>';
            return `<div class="table-responsive"><table class="mtbl"><thead><tr>
                <th>التاريخ</th><th>فاتورة الشراء</th><th>المورد</th><th>الكمية</th><th>سعر الوحدة</th>
            </tr></thead><tbody>
                ${rows.map(r => `<tr>
                    <td>${r.created_at}</td>
                    <td class="mono">${r.reference_number || '—'}</td>
                    <td>${r.supplier_name || '—'}</td>
                    <td>${r.quantity}</td>
                    <td class="fw-600">${parseFloat(r.unit_price).toFixed(2)} ${baseCurSymbol}</td>
                </tr>`).join('')}
            </tbody></table></div>
            <p class="text-muted small mt-2"><i class="bi bi-info-circle me-1"></i>هذا تاريخ أسعار الشراء الفعلية من فواتير الشراء المؤكَّدة — وليس سجل "تغيير سعر" مستقل (لا يوجد جدول لهذا حالياً).</p>`;
        }

        function renderProfitTab() {
            const rows = _repData.profit_rows;
            const s = _repData.summary;
            const cards = `<div class="row g-3 mb-3">
                <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon" style="background:#eff6ff;color:#2563eb"><i class="bi bi-cash-stack"></i></div><div><div class="stat-val">${s.total_revenue.toFixed(2)} ${baseCurSymbol}</div><div class="stat-lbl">إجمالي المبيعات</div></div></div></div>
                <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon" style="background:#fef2f2;color:#dc2626"><i class="bi bi-box-seam"></i></div><div><div class="stat-val">${s.total_cost.toFixed(2)} ${baseCurSymbol}</div><div class="stat-lbl">إجمالي التكلفة</div></div></div></div>
                <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon" style="background:#f0fdf4;color:#16a34a"><i class="bi bi-graph-up-arrow"></i></div><div><div class="stat-val">${s.total_profit.toFixed(2)} ${baseCurSymbol}</div><div class="stat-lbl">صافي الربح</div></div></div></div>
                <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon" style="background:#fffbeb;color:#d97706"><i class="bi bi-percent"></i></div><div><div class="stat-val">${s.margin}%</div><div class="stat-lbl">هامش الربح</div></div></div></div>
            </div>`;
            if (!rows.length) return cards + '<div class="text-muted text-center py-4">لا توجد عمليات بيع مسجَّلة لهذا المنتج بعد</div>';
            return cards + `<div class="table-responsive"><table class="mtbl"><thead><tr>
                <th>التاريخ</th><th>الفاتورة</th><th>العميل</th><th>الكمية</th><th>سعر البيع</th><th>التكلفة</th><th>الربح</th>
            </tr></thead><tbody>
                ${rows.map(r => `<tr>
                    <td>${r.created_at}</td>
                    <td class="mono">${r.reference_number || '—'}</td>
                    <td>${r.customer_name || '—'}</td>
                    <td>${r.quantity}</td>
                    <td>${parseFloat(r.sale_price).toFixed(2)} ${baseCurSymbol}</td>
                    <td class="text-muted">${parseFloat(r.cost_price || 0).toFixed(2)} ${baseCurSymbol}</td>
                    <td class="fw-600 ${r.profit >= 0 ? 'dir-in' : 'dir-out'}">${r.profit.toFixed(2)} ${baseCurSymbol}</td>
                </tr>`).join('')}
            </tbody></table></div>`;
        }
    </script>
</body>

</html>
