<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);

// ⚠ مقصود: لسا مربوط بقيمة ثابتة 'ret' لحد ما يتبنى دعم أكتر من فرع بيع
// واحد. لما يصير فيه أكتر من فرع retail، بدّل هالشرط ليتحقق من
// branch_type === 'retail' بدل قيمة table_suffix الثابتة.
if (($_SESSION['table_suffix'] ?? '') !== 'ret') {
    header('Location: ' . BASE_PATH . '/select_account.php');
    exit;
}

$currentModule = 'dashboard';
$user = getCurrentUser();
$branchName = $_SESSION['branch_name'] ?? 'فرع البيع 1';
$today = date('Y-m-d');

function q(PDO $p, string $sql, array $a = []): int|float|string
{
    try {
        $s = $p->prepare($sql);
        $s->execute($a);
        return $s->fetchColumn() ?: 0;
    } catch (Throwable $e) {
        return 0;
    }
}

$stats = [
    [
        'value' => q($pdo, "SELECT COUNT(*) FROM sales_invoices_ret WHERE DATE(created_at)=?", [$today]),
        'label' => 'فواتير اليوم',
        'icon' => 'bi-receipt',
        'bg' => '#eff6ff',
        'ic' => '#3b82f6'
    ],
    [
        'value' => '$' . number_format(q($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM sales_invoices_ret WHERE DATE(created_at)=? AND status!='cancelled'", [$today]), 0),
        'label' => 'مبيعات اليوم',
        'icon' => 'bi-currency-dollar',
        'bg' => '#f0fdf4',
        'ic' => '#16a34a'
    ],
    [
        'value' => q($pdo, "SELECT COUNT(*) FROM sales_invoices_ret WHERE status IN ('draft','pending')"),
        'label' => 'فواتير معلقة',
        'icon' => 'bi-hourglass-split',
        'bg' => '#fffbeb',
        'ic' => '#d97706'
    ],
    [
        'value' => q($pdo, "SELECT COUNT(*) FROM products_ret WHERE is_active=1"),
        'label' => 'الموديلات',
        'icon' => 'bi-tags',
        'bg' => '#fdf4ff',
        'ic' => '#9333ea'
    ],
    [
        'value' => q($pdo, "SELECT COUNT(*) FROM customers_ret WHERE status='active'"),
        'label' => 'العملاء',
        'icon' => 'bi-people',
        'bg' => '#f0fdfa',
        'ic' => '#0d9488'
    ],
    [
        'value' => q($pdo, "SELECT COUNT(DISTINCT wi.variant_id) FROM warehouse_items_ret wi WHERE wi.quantity<=wi.min_quantity AND wi.status='active'"),
        'label' => 'مخزون منخفض',
        'icon' => 'bi-exclamation-triangle',
        'bg' => '#fef2f2',
        'ic' => '#dc2626'
    ],
];

try {
    $lastInvoices = $pdo->query("
        SELECT id, invoice_number, customer_name, total_amount, status, created_at
        FROM sales_invoices_ret ORDER BY created_at DESC LIMIT 5
    ")->fetchAll();
} catch (Throwable $e) {
    $lastInvoices = [];
}

$statusCfg = [
    'draft' => ['مسودة', 'secondary'],
    'pending' => ['معلقة', 'warning'],
    'confirmed' => ['مؤكدة', 'info'],
    'partially_paid' => ['جزئي', 'primary'],
    'paid' => ['مدفوعة', 'success'],
    'cancelled' => ['ملغية', 'danger'],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>لوحة التحكم — <?= htmlspecialchars($branchName) ?></title>
<link rel="icon" href="<?= BASE_PATH ?>/assets/images/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="../assets/css/layout.css" rel="stylesheet">
<style>
.quick-action-card{
    background:#fff;border:1px solid #e2e8f0;border-radius:10px;
    padding:.85rem .5rem;text-align:center;transition:.15s;height:100%;
    display:flex;flex-direction:column;align-items:center;gap:.4rem;
}
.quick-action-card:hover{border-color:#3b82f6;transform:translateY(-2px)}
.quick-action-card i{font-size:1.3rem;color:#64748b}
.quick-action-card span{font-size:.76rem;font-weight:600;color:#334155}
.quick-action-primary{border:2px solid #3b82f6;background:#eff6ff}
.quick-action-primary i,.quick-action-primary span{color:#1e3a8a}
.quick-action-pending{opacity:.7}
</style>
</head>
<body>
<div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
<?php
// جلب قائمة الـ sidebar
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<!-- Topbar -->
<header class="topbar">
    <button class="tb-toggle" onclick="sbOpen()" aria-label="القائمة">
        <i class="bi bi-list"></i>
    </button>
    <span class="tb-title">لوحة التحكم</span>
    <span class="tb-branch">
        <i class="bi bi-shop-window"></i>
        <?= htmlspecialchars($branchName) ?>
    </span>
    <div class="ms-auto text-secondary small d-flex align-items-center gap-2">
        <i class="bi bi-calendar3"></i>
        <?= date('d M Y') ?>
    </div>
</header>

<!-- Main -->
<main class="main-content">
<div class="content-body">

    <!-- اختصارات سريعة -->
    <div class="mb-2" style="font-size:.78rem;color:#94a3b8;font-weight:600">اختصارات سريعة</div>
    <div class="row g-2 mb-4">
        <div class="col-6 col-md-4 col-xl-2">
            <a href="sales/invoices.php?action=new" class="text-decoration-none">
                <div class="quick-action-card quick-action-primary">
                    <i class="bi bi-receipt-cutoff"></i>
                    <span>فاتورة بيع جديدة</span>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="../purchases/invoice_new.php" class="text-decoration-none">
                <div class="quick-action-card">
                    <i class="bi bi-truck"></i>
                    <span>فاتورة شراء جديدة</span>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="../inventory/product_add.php" class="text-decoration-none">
                <div class="quick-action-card">
                    <i class="bi bi-tag"></i>
                    <span>منتج جديد</span>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="../inventory/consumables.php" class="text-decoration-none">
                <div class="quick-action-card">
                    <i class="bi bi-recycle"></i>
                    <span>مستهلك جديد</span>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="../inventory/warehouse.php" class="text-decoration-none">
                <div class="quick-action-card">
                    <i class="bi bi-building"></i>
                    <span>مستودع المنتجات</span>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <!-- ⚠ "تقرير مالي للمنتجات" — لسا ما تأكد المسار الصحيح
                 (finance.reports/reports/index.php غير مؤكد وجوده فعلياً).
                 مؤقتاً بيوجّه لتقرير حركة المخزون الموجود فعلياً كأقرب بديل،
                 لحد ما نتأكد من ملفات accounting/ الفعلية. -->
            <a href="../inventory/movements.php" class="text-decoration-none">
                <div class="quick-action-card quick-action-pending" title="مؤقت — بانتظار تأكيد الصفحة الصحيحة">
                    <i class="bi bi-graph-up"></i>
                    <span>تقرير حركة المخزون</span>
                </div>
            </a>
        </div>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <?php foreach ($stats as $s): ?>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="stat-card">
                    <div class="stat-icon" style="background:<?= $s['bg'] ?>;color:<?= $s['ic'] ?>">
                        <i class="bi <?= $s['icon'] ?>"></i>
                    </div>
                    <div>
                        <div class="stat-value"><?= $s['value'] ?></div>
                        <div class="stat-label"><?= $s['label'] ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- آخر الفواتير -->
    <div class="table-card">
        <div class="table-card-header">
            <span class="tc-title">
                <i class="bi bi-receipt text-primary me-2"></i>آخر الفواتير
            </span>
            <?php if (can('sales.invoices', 'view')): ?>
                <a href="sales/invoices.php" class="btn btn-sm btn-outline-primary"
                   style="font-size:.78rem;border-radius:8px">
                    عرض الكل <i class="bi bi-arrow-left ms-1"></i>
                </a>
            <?php endif; ?>
        </div>
        <?php if (empty($lastInvoices)): ?>
            <div class="text-center text-muted py-5 small">
                <i class="bi bi-receipt fs-2 d-block mb-2 opacity-25"></i>
                لا توجد فواتير بعد
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>رقم الفاتورة</th>
                            <th>العميل</th>
                            <th>المبلغ $</th>
                            <th>الحالة</th>
                            <th>التاريخ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lastInvoices as $inv):
                            [$lbl, $cls] = $statusCfg[$inv['status']] ?? [$inv['status'], 'secondary'];
                            ?>
                            <tr>
                                <td>
                                    <a href="sales/invoices.php?id=<?= $inv['id'] ?>"
                                       class="text-primary fw-600 text-decoration-none">
                                       <?= htmlspecialchars($inv['invoice_number']) ?>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars($inv['customer_name'] ?? '—') ?></td>
                                <td><?= number_format($inv['total_amount'], 2) ?></td>
                                <td>
                                    <span class="badge rounded-pill bg-<?= $cls ?>-subtle
                                          text-<?= $cls ?> border border-<?= $cls ?>-subtle"
                                          style="font-size:.72rem">
                                        <?= $lbl ?>
                                    </span>
                                </td>
                                <td class="text-muted small">
                                    <?= date('d/m/Y H:i', strtotime($inv['created_at'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>
</main>

<script>
const sidebar  = document.getElementById('sidebar');
const overlay  = document.getElementById('sbOverlay');
function sbOpen()  { sidebar.classList.add('open');  overlay.classList.add('show'); }
function sbClose() { sidebar.classList.remove('open');overlay.classList.remove('show'); }
window.addEventListener('resize', () => { if(window.innerWidth>991) sbClose(); });

// Accordion: فتح المجموعة
function toggleGroup(group) {
    const isOpen = group.classList.contains('open');
    // إغلاق كل المجموعات الأخرى
    document.querySelectorAll('.sb-group.open').forEach(g => {
        if (g !== group) g.classList.remove('open');
    });
    group.classList.toggle('open', !isOpen);
    // حفظ الحالة
    localStorage.setItem('sb_open_' + group.dataset.key, (!isOpen).toString());
}

// استعادة حالة الـ Accordion من localStorage
document.querySelectorAll('.sb-group').forEach(g => {
    const saved = localStorage.getItem('sb_open_' + g.dataset.key);
    if (saved === 'true') g.classList.add('open');
});
</script>
</body>
</html>
