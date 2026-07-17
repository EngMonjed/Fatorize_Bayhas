<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
session_start();
require_once 'config/database.php';

// حماية الصفحة
if (empty($_SESSION['user_id'])) {
    header('Location: login.php'); exit;
}

$pdo     = getMainConnection();
$user_id = (int)$_SESSION['user_id'];

// جلب الفروع المسموح للمستخدم
$stmt = $pdo->prepare("
    SELECT b.id, b.name, b.dashboard_path, b.table_suffix, b.icon, b.color
    FROM branches b
    JOIN user_branches ub ON b.id = ub.branch_id
    WHERE ub.user_id = ? AND b.status = 'active'
    ORDER BY b.sort_order ASC, b.name ASC
");
$stmt->execute([$user_id]);
$branches = $stmt->fetchAll();

// معالجة اختيار الفرع
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['branch_id'])) {
    $branchId = (int)$_POST['branch_id'];
    $stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ? AND status = 'active'");
    $stmt->execute([$branchId]);
    $branch = $stmt->fetch();

    if ($branch) {
        $_SESSION['branch_id']     = $branch['id'];
        $_SESSION['branch_name']   = $branch['name'];
        $_SESSION['table_suffix']  = $branch['table_suffix'] ?? '';
        $_SESSION['dashboard_path']= $branch['dashboard_path'];

        header('Location: ' . $branch['dashboard_path']);
        exit;
    }
}

// خريطة الأيقونات والألوان حسب اسم الفرع
$branchMeta = [
    'معمل عنتاب'   => ['icon' => 'bi-gear-fill',        'color' => '#10b981', 'bg' => '#ecfdf5'],
    'محل عنتاب'    => ['icon' => 'bi-shop',              'color' => '#10b981', 'bg' => '#ecfdf5'],
    'محل استنبول'  => ['icon' => 'bi-buildings',         'color' => '#ef4444', 'bg' => '#fef2f2'],
    'فرع استنبول'  => ['icon' => 'bi-buildings',         'color' => '#ef4444', 'bg' => '#fef2f2'],
    'محل حلب'      => ['icon' => 'bi-shop-window',       'color' => '#f59e0b', 'bg' => '#fffbeb'],
    'فرع حلب'      => ['icon' => 'bi-shop-window',       'color' => '#f59e0b', 'bg' => '#fffbeb'],
    'معمل حلب'     => ['icon' => 'bi-factory',           'color' => '#f59e0b', 'bg' => '#fffbeb'],
    'default'       => ['icon' => 'bi-building',          'color' => '#3b82f6', 'bg' => '#eff6ff'],
];

function getBranchMeta(array $branch, array $map): array {
    // أولوية: من قاعدة البيانات
    if (!empty($branch['icon']))  return ['icon' => $branch['icon'], 'color' => $branch['color'] ?? '#3b82f6', 'bg' => '#eff6ff'];
    return $map[$branch['name']] ?? $map['default'];
}

$fullName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'المستخدم';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>اختيار الفرع — FATORIZE</title>
<link rel="icon" type="image/png" href="assets/images/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root { --brand: #1e3a8a; --brand-light: #3b82f6; }
* { box-sizing: border-box; }
body {
    min-height: 100vh;
    background: linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 100%);
    font-family: 'Cairo', sans-serif;
    display: flex; flex-direction: column;
}
.topbar {
    background: rgba(255,255,255,.9);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid rgba(30,58,138,.08);
    padding: .75rem 1.5rem;
    display: flex; align-items: center; justify-content: space-between;
}
.topbar .brand { display: flex; align-items: center; gap: .75rem; }
.topbar .brand img { height: 36px; }
.topbar .brand-name { font-size: 1rem; font-weight: 700; color: var(--brand); }
.topbar .user-info {
    display: flex; align-items: center; gap: .5rem;
    font-size: .88rem; color: #64748b;
}
.topbar .user-avatar {
    width: 34px; height: 34px; border-radius: 50%;
    background: var(--brand); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: .8rem; font-weight: 600;
}
.main { flex: 1; display: flex; align-items: center; justify-content: center; padding: 2rem 1rem; }
.select-card {
    background: rgba(255,255,255,.97);
    border-radius: 24px;
    padding: 2.5rem;
    box-shadow: 0 20px 60px rgba(30,58,138,.12);
    max-width: 800px; width: 100%;
    animation: slideUp .5s ease;
}
@keyframes slideUp {
    from { opacity:0; transform:translateY(24px); }
    to   { opacity:1; transform:translateY(0); }
}
.select-title {
    font-size: 1.35rem; font-weight: 700; color: var(--brand);
    margin-bottom: .4rem;
}
.select-sub { color: #64748b; font-size: .92rem; margin-bottom: 2rem; }
.branches-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
    gap: 1rem;
}
.branch-btn {
    background: none; border: none; padding: 0; cursor: pointer;
    width: 100%; text-align: center;
}
.branch-card {
    background: #fff;
    border: 1.5px solid #e2e8f0;
    border-radius: 18px;
    padding: 1.75rem 1rem;
    transition: all .25s cubic-bezier(.4,0,.2,1);
    height: 100%;
    display: flex; flex-direction: column; align-items: center; gap: .75rem;
}
.branch-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 12px 32px rgba(0,0,0,.1);
    border-color: transparent;
}
.branch-icon-wrap {
    width: 60px; height: 60px; border-radius: 16px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.7rem; transition: transform .25s;
}
.branch-btn:hover .branch-icon-wrap { transform: scale(1.08); }
.branch-label {
    font-size: .95rem; font-weight: 600; color: #1e293b;
    line-height: 1.3;
}
.logout-link {
    display: inline-flex; align-items: center; gap: .4rem;
    color: #94a3b8; font-size: .85rem; text-decoration: none;
    margin-top: 1.75rem; transition: color .2s;
}
.logout-link:hover { color: #ef4444; }
footer {
    background: transparent; padding: 1rem;
    text-align: center; font-size: .8rem; color: #94a3b8;
}
@media (max-width: 480px) {
    .select-card { padding: 1.5rem 1.25rem; }
    .branches-grid { grid-template-columns: repeat(2, 1fr); gap: .75rem; }
    .branch-card { padding: 1.25rem .75rem; }
    .branch-icon-wrap { width: 50px; height: 50px; font-size: 1.4rem; }
    .branch-label { font-size: .85rem; }
}
</style>
</head>
<body>

<div class="topbar">
    <div class="brand">
        <img src="assets/images/fatorize.png" alt="Fatorize"
             onerror="this.style.display='none'">
        <span class="brand-name">FATORIZE</span>
    </div>
    <div class="user-info">
        <div class="user-avatar">
            <?= mb_substr($fullName, 0, 1) ?>
        </div>
        <span class="d-none d-sm-inline"><?= htmlspecialchars($fullName) ?></span>
    </div>
</div>

<main class="main">
    <div class="select-card">
        <div class="select-title">اختر الفرع</div>
        <div class="select-sub">مرحباً <?= htmlspecialchars($fullName) ?>، اختر الفرع الذي تريد الدخول إليه</div>

        <?php if (empty($branches)): ?>
        <div class="alert alert-warning d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle"></i>
            لا توجد فروع مخصصة لحسابك. تواصل مع المدير.
        </div>
        <?php else: ?>
        <form method="POST">
            <div class="branches-grid">
                <?php foreach ($branches as $b):
                    $meta = getBranchMeta($b, $branchMeta);
                ?>
                <button type="submit" name="branch_id" value="<?= $b['id'] ?>" class="branch-btn">
                    <div class="branch-card" style="border-color: <?= $meta['color'] ?>22">
                        <div class="branch-icon-wrap"
                             style="background: <?= $meta['bg'] ?>; color: <?= $meta['color'] ?>">
                            <i class="bi <?= htmlspecialchars($meta['icon']) ?>"></i>
                        </div>
                        <div class="branch-label"><?= htmlspecialchars($b['name']) ?></div>
                    </div>
                </button>
                <?php endforeach; ?>
            </div>
        </form>
        <?php endif; ?>

        <div class="text-center">
            <a href="logout.php" class="logout-link">
                <i class="bi bi-box-arrow-right"></i>تسجيل الخروج
            </a>
        </div>
    </div>
</main>

<footer>
    <img src="assets/images/fatorize.png" height="20" alt="" style="opacity:.4; vertical-align:middle; margin-left:6px"
         onerror="this.style.display='none'">
    جميع الحقوق محفوظة &copy; Fatorize <?= date('Y') ?>
</footer>

</body>
</html>
