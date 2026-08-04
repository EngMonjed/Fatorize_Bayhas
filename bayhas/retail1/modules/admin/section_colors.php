<?php
/**
 * admin/section_colors.php — إدارة ألوان الأقسام
 * المسار: retail1/modules/admin/section_colors.php
 *
 * صفحة إعدادات بسيطة — قائمة الأقسام الرئيسية (parent_key IS NULL)
 * بجدول modules، كل وحدة مع Color Picker، حفظ فوري عبر AJAX لعمود
 * theme_color. القيمة بعدها بتنعكس تلقائياً بكل صفحات القسم عبر
 * متغيّر CSS (--section-color) المحقون من includes/sidebar.php.
 */

session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('admin.section_colors', 'view');
$currentModule = 'admin.section_colors';

$TS = $_SESSION['table_suffix'];
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($_POST['_action'] === 'save_color') {
            requirePermission('admin.section_colors', 'edit');
            $key   = trim($_POST['key'] ?? '');
            $color = trim($_POST['color'] ?? '');

            if ($key === '' || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                throw new Exception('قيمة اللون غير صالحة');
            }

            $pdo->prepare("UPDATE modules SET theme_color = ? WHERE `key` = ? AND parent_key IS NULL")
                ->execute([$color, $key]);

            echo json_encode(['ok' => true, 'msg' => 'تم حفظ اللون']);
        } else {
            echo json_encode(['ok' => false, 'msg' => 'إجراء غير معروف']);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── بيانات الصفحة ────────────────────────────────────────────────
$sections = $pdo->query("
    SELECT `key`, label, icon, theme_color
    FROM modules
    WHERE parent_key IS NULL AND is_active = 1
    ORDER BY sort_order
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ألوان الأقسام — <?= htmlspecialchars($branchName) ?></title>
<link rel="icon" href="<?= BASE_PATH ?>/assets/images/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="../../assets/css/layout.css" rel="stylesheet">
<style>
.color-card{
    background:#fff;border:1px solid #e2e8f0;border-radius:12px;
    padding:1rem 1.25rem;display:flex;align-items:center;gap:1rem;
}
.color-swatch{
    width:44px;height:44px;border-radius:10px;flex-shrink:0;
    border:1px solid rgba(0,0,0,.08);cursor:pointer;
}
.color-swatch input[type=color]{
    opacity:0;width:100%;height:100%;cursor:pointer;
}
.color-hex{font-family:monospace;font-size:.8rem;color:#64748b}
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
    <span class="tb-title"><i class="bi bi-palette me-1 text-primary"></i>ألوان الأقسام</span>
    <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
    <?= renderBreadcrumb() ?>
</header>

<main class="main-content">
<div class="content-body">

    <div class="alert alert-info d-flex align-items-center gap-2" style="border-radius:10px;font-size:.85rem">
        <i class="bi bi-info-circle fs-5"></i>
        <div>لون كل قسم بينعكس تلقائياً على كل صفحاته (أزرار، تبويبات، حدود...)
        عبر متغيّر <code>--section-color</code>. التغيير هون بيطبّق فوراً بدون
        الحاجة لإعادة نشر أي ملف.</div>
    </div>

    <div class="row g-3">
        <?php foreach ($sections as $s): ?>
        <div class="col-md-6 col-lg-4">
            <div class="color-card">
                <label class="color-swatch" style="background:<?= htmlspecialchars($s['theme_color'] ?: '#3b82f6') ?>">
                    <input type="color"
                           value="<?= htmlspecialchars($s['theme_color'] ?: '#3b82f6') ?>"
                           data-key="<?= htmlspecialchars($s['key']) ?>"
                           onchange="saveColor(this)">
                </label>
                <div>
                    <div class="fw-600"><i class="bi <?= htmlspecialchars($s['icon']) ?> me-1"></i><?= htmlspecialchars($s['label']) ?></div>
                    <div class="color-hex" data-hex-for="<?= htmlspecialchars($s['key']) ?>"><?= htmlspecialchars($s['theme_color'] ?: '#3b82f6') ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

</div>
</main>

<script>
function post(data) {
    const fd = new FormData();
    for (const k in data) fd.append(k, data[k]);
    return fetch(location.href, { method: 'POST', body: fd }).then(r => r.json());
}
function toast(msg, type = 'success') {
    const el = document.createElement('div');
    el.className = `alert alert-${type} position-fixed`;
    el.style.cssText = 'bottom:20px;left:20px;z-index:9999;border-radius:10px;font-size:.85rem';
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 2500);
}
function saveColor(input) {
    const key = input.dataset.key;
    const color = input.value;
    input.closest('.color-swatch').style.background = color;
    document.querySelector(`[data-hex-for="${key}"]`).textContent = color;
    post({ _action: 'save_color', key, color }).then(d => {
        if (d.ok) toast(d.msg);
        else toast(d.msg, 'danger');
    });
}

const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sbOverlay');
function sbOpen()  { sidebar.classList.add('open');  overlay.classList.add('show'); }
function sbClose() { sidebar.classList.remove('open');overlay.classList.remove('show'); }
window.addEventListener('resize', () => { if (window.innerWidth > 991) sbClose(); });

function toggleGroup(group) {
    const isOpen = group.classList.contains('open');
    document.querySelectorAll('.sb-group.open').forEach(g => {
        if (g !== group) g.classList.remove('open');
    });
    group.classList.toggle('open', !isOpen);
    localStorage.setItem('sb_open_' + group.dataset.key, (!isOpen).toString());
}
document.querySelectorAll('.sb-group').forEach(g => {
    const saved = localStorage.getItem('sb_open_' + g.dataset.key);
    if (saved === 'true') g.classList.add('open');
});
</script>
</body>
</html>
