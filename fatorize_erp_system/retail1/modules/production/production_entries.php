<?php
/**
 * production/production_entries.php — أوامر الإنتاج
 * المسار: retail1|factory1/modules/production/production_entries.php
 * ⚠ وضع صيانة مؤقت — الصفحة مؤجّلة عن قصد، مسجّلة بجدول modules
 *   عشان رابط الشريط الجانبي والتبويبات ما تنكسر.
 */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('production.entries', 'view');
$TS = $_SESSION['table_suffix'];
$currentModule = 'production.entries';

// اسم الفرع + عملته الوظيفية (branches.base_currency هو الحقل الموثوق، مو base_currency_id)
$branchName = '';
$baseCurSym = '';
try {
    $bn = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
    $bn->execute([$_SESSION['branch_id'] ?? 0]);
    $brow = $bn->fetch();
    if ($brow) {
        $branchName = $brow['name'] ?? ($brow['branch_name'] ?? '');
        $baseCurSym = $brow['base_currency'] ?? '';
    }
} catch (Throwable $e) { /* يُعرض بدون اسم/عملة بدل ما تنكسر الصفحة */ }

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>أوامر الإنتاج — FATORIZE</title>
    <link rel="icon" type="image/png" href="<?= BASE_PATH ?>/assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/assets/css/layout.css" rel="stylesheet">
    <style>
        .maint-box { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 3rem 2rem; text-align: center; max-width: 520px; margin: 2rem auto; }
        .maint-box i.big { font-size: 2.4rem; color: #94a3b8; }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-clipboard-check me-1" style="color:var(--section-color)"></i>أوامر الإنتاج</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
            <span>الإنتاج</span>
            <i class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
            <span class="fw-600" style="color:var(--section-color)">أوامر الإنتاج</span>
        </nav>
    </header>

    <main class="main-content">
        <div class="content-body">

            <ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e2e8f0">
                <li class="nav-item">
                    <a class="nav-link fw-600" href="raw_materials.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-box2 me-1"></i>المواد الأولية
                    </a>
                </li>
                                <li class="nav-item">
                    <a class="nav-link fw-600" href="stages.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-sliders me-1"></i>إعداد الإنتاج
                    </a>
                </li>
<li class="nav-item">
                    <a class="nav-link fw-600" href="operations.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-diagram-3 me-1"></i>عمليات التصنيع
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600 active" href="production_entries.php"
                        style="border:none;border-bottom:2px solid var(--section-color);color:var(--section-color);font-size:.83rem;margin-bottom:-2px">
                        <i class="bi bi-clipboard-check me-1"></i>أوامر الإنتاج
                    </a>
                </li>
            </ul>

            <div class="maint-box">
                <i class="bi bi-cone-striped big d-block mb-3"></i>
                <h5>أوامر الإنتاج قيد الإنشاء</h5>
                <p class="text-muted small">هاي الصفحة رح تسجّل أوامر الإنتاج والتنفيذ الفعلي بين المراحل مع حساب التكلفة. مؤجّلة مؤقتاً لحد ما ننتهي من المواد الأولية وعمليات التصنيع.</p>
                <a href="raw_materials.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-right me-1"></i>رجوع للمواد الأولية</a>
            </div>

        </div>
    </main>

    <script src="<?= BASE_PATH ?>/assets/js/sidebar.js"></script>
</body>

</html>
