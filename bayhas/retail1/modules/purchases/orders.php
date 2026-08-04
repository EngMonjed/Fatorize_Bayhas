<?php
/**
 * purchases/orders.php — أوامر الشراء / طلبات عروض الأسعار
 * المسار: retail1/modules/purchases/orders.php
 *
 * ⚠ الصفحة موقوفة مؤقتاً بقرار صريح (منطق CRUD/التأكيد لسا مش مبني —
 * راجع internal_orders.php كمرجع لنفس فلسفة draft→sent→reviewing→
 * approved→converted). القرارات المعمارية (مرحلة واحدة بجدول واحد،
 * مورد واحد لكل أمر، اعتماد الأمر يتحوّل تلقائياً لمسودة فاتورة شراء)
 * متّفق عليها ومسجَّلة — البناء الفعلي مؤجَّل لمحادثة قادمة مخصصة.
 *
 * ⚠ الشكل العام (Sidebar/Breadcrumb/شريط التبويبات) مبني بالكامل وحقيقي
 * — بس منطقة المحتوى الفعلية (الجدول/النماذج) بديلة برسالة "قيد الإعداد"
 * لحد ما يُبنى المنطق الحقيقي. لإعادة التفعيل لاحقاً: استبدل قسم
 * "منطقة المحتوى" بالأسفل بالجدول/النماذج الحقيقية، بدون أي تعديل على
 * باقي الصفحة.
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('purchases.orders', 'view');
$currentModule = 'purchases.orders';
$branchName = $_SESSION['branch_name'] ?? 'الفرع';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>أوامر الشراء — FATORIZE</title>
    <link rel="icon" type="image/png" href="<?= BASE_PATH ?>/assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/assets/css/layout.css" rel="stylesheet">
    <style>
        .n {
            font-variant-numeric: tabular-nums
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
            font-size: .82rem
        }

        table.mtbl th {
            background: #f8fafc;
            padding: 8px 12px;
            font-weight: 600;
            color: #64748b;
            font-size: .72rem;
            border-bottom: 1px solid #f1f5f9;
            white-space: nowrap
        }

        table.mtbl td {
            padding: 8px 12px;
            border-bottom: 1px solid #f8fafc;
            vertical-align: middle
        }

        table.mtbl tr:hover td {
            background: #f8faff
        }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php
    require_once __DIR__ . '/../../../includes/sidebar.php';
    require_once __DIR__ . '/../../../includes/breadcrumb.php';
    ?>
    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-file-earmark-text me-1 text-primary"></i>أوامر الشراء / طلبات عروض
            الأسعار</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <?= renderBreadcrumb() ?>
    </header>

    <main class="main-content">
        <div class="content-body">

            <!-- تبويبات — نفس الخمسة بباقي صفحات القسم بالضبط -->
            <ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e2e8f0">
                <li class="nav-item">
                    <a class="nav-link fw-600" href="index.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-receipt me-1"></i>فواتير المشتريات
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="suppliers.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-people me-1"></i>إدارة الموردين
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="returns.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-arrow-return-right me-1"></i>مرتجعات المشتريات
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600 active" href="orders.php"
                        style="border:none;border-bottom:2px solid var(--section-color);color:var(--section-color);font-size:.83rem;margin-bottom:-2px">
                        <i class="bi bi-file-earmark-text me-1"></i>أوامر الشراء / طلبات عروض الأسعار
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="reports.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-bar-chart me-1"></i>التقارير
                    </a>
                </li>
            </ul>

            <!-- ══ منطقة المحتوى — بديلة مؤقتاً برسالة الصيانة ══ -->
            <!-- لإعادة التفعيل: استبدل الـdiv تحت بالجدول/النماذج الحقيقية -->
            <div class="tbl-wrap">
                <div class="text-center py-5 px-4">
                    <i class="bi bi-cone-striped" style="font-size:3rem;color:#f59e0b"></i>
                    <h5 class="mt-3 fw-bold">أوامر الشراء / طلبات عروض الأسعار — قيد الإعداد</h5>
                    <p class="text-muted small mx-auto" style="max-width:460px">
                        هذا القسم موقوف عمداً حالياً. القرارات الأساسية (مرحلة واحدة
                        بنفس فلسفة الطلبات الداخلية بين الفروع، مورد واحد لكل أمر،
                        واعتماد الأمر يتحوّل تلقائياً لمسودة فاتورة شراء) متّفق عليها
                        ومسجَّلة — بس البناء الفعلي مؤجَّل لمحادثة مخصصة قادمة. لا
                        بيانات ولا كود انحذف.
                    </p>
                    <a href="index.php" class="btn btn-sm mt-2"
                        style="border-radius:8px;background:var(--section-color);color:#fff">
                        <i class="bi bi-arrow-right me-1"></i>رجوع لفواتير المشتريات
                    </a>
                </div>
            </div>

        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>