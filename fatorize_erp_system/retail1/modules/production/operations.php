<?php
/**
 * production/operations.php — عمليات التصنيع (خطوات/وصفات الإنتاج)
 * المسار: aleppo/modules/production/operations.php
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
requirePermission('production.operations', 'view');
$currentModule = 'production.operations';

if (true) {
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>عمليات التصنيع — فاتورايز</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="../../../assets/css/layout.css" rel="stylesheet">
<style>
.section-tabs { display:flex; gap:.4rem; margin-bottom:1.1rem; border-bottom:1px solid #e2e8f0; }
.section-tabs a { padding:.65rem 1.1rem; font-size:.9rem; font-weight:600; color:#64748b; text-decoration:none; border-bottom:2.5px solid transparent; margin-bottom:-1px; }
.section-tabs a.active { color:#1e3a8a; border-bottom-color:#1e3a8a; }
.maint-box { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:3rem 2rem; text-align:center; max-width:520px; margin:2rem auto; }
.maint-box i { font-size:2.4rem; color:#94a3b8; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
<main class="content">
    <div class="section-tabs">
        <a href="raw_materials.php"><i class="bi bi-box2 me-1"></i> المواد الأولية</a>
        <a href="operations.php" class="active"><i class="bi bi-diagram-3 me-1"></i> عمليات التصنيع</a>
        <a href="production_entries.php"><i class="bi bi-clipboard-check me-1"></i> أوامر الإنتاج</a>
    </div>
    <div class="maint-box">
        <i class="bi bi-cone-striped d-block mb-3"></i>
        <h5>عمليات التصنيع قيد الإنشاء</h5>
        <p class="text-muted small">
            هاي الصفحة رح تسمح بتعريف خطوات/وصفات الإنتاج (أي مواد أولية بأي كمية بتدخل بأي منتج نهائي).
            مؤجّلة مؤقتاً لحد ما ننتهي من كتالوج المواد الأولية.
        </p>
        <a href="raw_materials.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-right"></i> رجوع للمواد الأولية</a>
    </div>
</main>
</body>
</html>
<?php
exit;
}
// ── نهاية وضع الصيانة ── (كل المنطق الحقيقي تحت هالسطر، جاهز يتفعّل بحذف الشرط بس)
