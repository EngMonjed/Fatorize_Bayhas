<?php
/**
 * sales/orders.php — أوامر البيع / عروض الأسعار
 * المسار: retail1/modules/sales/orders.php
 *
 * ⚠ وضع صيانة مؤقت عمداً — نفس نمط purchases/orders.php وinventory/
 * internal_orders.php بالضبط. الصفحة مبنية بالكامل (شريط جانبي +
 * شريط تبويبات القسم + تصميم متسق)، بس بلا أي منطق عمل فعلي لسا —
 * بانتظار قرار صريح لاحق حول آلية العمل المطلوبة (أوامر بيع؟ عروض
 * أسعار؟ الاثنين سوا؟) قبل بناء الجداول والمنطق الحقيقي.
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('sales.orders', 'view');
$currentModule = 'sales.orders';

$branchName = $_SESSION['branch_name'] ?? 'الفرع';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>أوامر البيع — FATORIZE</title>
    <link rel="icon" type="image/png" href="<?= BASE_PATH ?>/assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/assets/css/layout.css" rel="stylesheet">
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-file-earmark-text me-1 text-success"></i>أوامر البيع / عروض
            الأسعار</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
            <span>المبيعات</span>
            <i class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
            <span class="text-success fw-600">أوامر البيع</span>
        </nav>
    </header>

    <main class="main-content">
        <div class="content-body">

            <!-- تبويبات القسم -->
            <ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e2e8f0">
                <li class="nav-item">
                    <a class="nav-link fw-600" href="sales_index.php"
                        style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-receipt me-1"></i>فواتير المبيعات
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="customers.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-people me-1"></i>إدارة العملاء
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="returns.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-arrow-return-right me-1"></i>مرتجعات المبيعات
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="orders.php"
                        style="border:none;border-bottom:2px solid var(--section-color);color:var(--section-color);font-size:.83rem;margin-bottom:-2px">
                        <i class="bi bi-file-earmark-text me-1"></i>أوامر البيع / عروض الأسعار
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="reports.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-bar-chart me-1"></i>التقارير
                    </a>
                </li>
            </ul>

            <!-- بطاقة وضع الصيانة -->
            <div class="d-flex align-items-center justify-content-center" style="min-height:60vh">
                <div class="text-center" style="max-width:460px">
                    <div class="mb-3"
                        style="width:90px;height:90px;border-radius:50%;background:#f0fdf4;display:flex;align-items:center;justify-content:center;margin:0 auto">
                        <i class="bi bi-tools" style="font-size:2.4rem;color:#16a34a"></i>
                    </div>
                    <h5 class="fw-700 mb-2" style="color:#1e293b">هالقسم قيد الصيانة حالياً</h5>
                    <p style="color:#64748b;font-size:.88rem;line-height:1.8">
                        أوامر البيع وعروض الأسعار موجودة بالخريطة العامة للنظام، بس آلية العمل الفعلية
                        (أوامر بيع مبدئية قبل الفاتورة؟ عروض أسعار للعملاء؟ تحويل مباشر لفاتورة؟) لسا
                        محتاجة قرار واضح قبل ما تُبنى — تجنّباً لبناء منطق غير مناسب لاحتياجك الفعلي.
                    </p>
                    <a href="sales_index.php" class="btn btn-sm fw-600 mt-2"
                        style="border-radius:8px;background:#16a34a;color:#fff;padding:8px 20px">
                        <i class="bi bi-arrow-right me-1"></i>الرجوع لفواتير المبيعات
                    </a>
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