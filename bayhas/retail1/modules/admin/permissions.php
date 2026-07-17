<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('admin.permissions', 'view');

$branchId   = (int)$_SESSION['branch_id'];
$branchName = $_SESSION['branch_name'] ?? 'الفرع';
$currentModule = 'admin.permissions';

$targetUserId = (int)($_GET['user_id'] ?? 0);
$targetName   = $_GET['name'] ?? '';

if (!$targetUserId) {
    header('Location: users.php'); exit;
}

// جلب بيانات المستخدم
$targetUser = $pdo->prepare("SELECT id,username,full_name,role FROM users WHERE id=?");
$targetUser->execute([$targetUserId]);
$targetUser = $targetUser->fetch();
if (!$targetUser) { header('Location: users.php'); exit; }

// ── حفظ الصلاحيات (AJAX) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    requirePermission('admin.permissions', 'edit');

    try {
        $branchTarget = (int)($_POST['branch_id'] ?? $branchId);
        $perms        = $_POST['perms'] ?? [];

        // جلب كل الأقسام
        $allMods = $pdo->query("SELECT key FROM modules WHERE parent_key IS NOT NULL AND is_active=1")
                       ->fetchAll(PDO::FETCH_COLUMN);

        $pdo->beginTransaction();

        foreach ($allMods as $modKey) {
            $p = $perms[$modKey] ?? [];
            $exists = $pdo->prepare("SELECT id FROM user_permissions WHERE user_id=? AND branch_id=? AND module_key=?");
            $exists->execute([$targetUserId, $branchTarget, $modKey]);
            $rid = $exists->fetchColumn();

            $vals = [
                (int)in_array('view',    $p),
                (int)in_array('create',  $p),
                (int)in_array('edit',    $p),
                (int)in_array('delete',  $p),
                (int)in_array('confirm', $p),
                (int)in_array('print',   $p),
                (int)in_array('export',  $p),
            ];

            if ($rid) {
                $pdo->prepare("UPDATE user_permissions SET
                    can_view=?,can_create=?,can_edit=?,can_delete=?,
                    can_confirm=?,can_print=?,can_export=?,
                    granted_by=?,updated_at=NOW()
                    WHERE id=?")->execute([...$vals, $_SESSION['user_id'], $rid]);
            } else {
                $pdo->prepare("INSERT INTO user_permissions
                    (user_id,branch_id,module_key,can_view,can_create,can_edit,
                     can_delete,can_confirm,can_print,can_export,granted_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$targetUserId, $branchTarget, $modKey, ...$vals, $_SESSION['user_id']]);
            }
        }

        $pdo->commit();

        // إعادة تحميل الصلاحيات في الـ session إذا كان المستخدم الحالي
        if ($targetUserId === (int)$_SESSION['user_id'] && $branchTarget === $branchId) {
            unset($_SESSION['permissions']);
        }

        echo json_encode(['ok' => true, 'msg' => 'تم حفظ الصلاحيات بنجاح']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── جلب البيانات للعرض ──────────────────────────────────────────
$allBranches = $pdo->query("SELECT id,name FROM branches WHERE status='active' ORDER BY sort_order")->fetchAll();
$userBranches = $pdo->prepare("SELECT branch_id FROM user_branches WHERE user_id=?");
$userBranches->execute([$targetUserId]);
$userBranchIds = array_column($userBranches->fetchAll(), 'branch_id');

// جلب الأقسام مرتبة هرمياً
$allModules = $pdo->query("SELECT * FROM modules WHERE is_active=1 ORDER BY sort_order")->fetchAll();
$parents  = [];
$children = [];
foreach ($allModules as $m) {
    if ($m['parent_key'] === null) $parents[$m['key']] = array_merge($m, ['children' => []]);
    else $children[] = $m;
}
foreach ($children as $c) {
    if (isset($parents[$c['parent_key']])) $parents[$c['parent_key']]['children'][] = $c;
}

// جلب الصلاحيات الحالية لهذا المستخدم
function getUserPerms(PDO $pdo, int $uid, int $bid): array {
    $stmt = $pdo->prepare("SELECT module_key,can_view,can_create,can_edit,can_delete,can_confirm,can_print,can_export
                           FROM user_permissions WHERE user_id=? AND branch_id=?");
    $stmt->execute([$uid, $bid]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[$r['module_key']] = $r;
    }
    return $out;
}

$selectedBranch = (int)($_GET['bid'] ?? ($userBranchIds[0] ?? $branchId));
$currentPerms   = getUserPerms($pdo, $targetUserId, $selectedBranch);

$actions = [
    'view'    => ['eye',         'عرض'],
    'create'  => ['plus-circle', 'إنشاء'],
    'edit'    => ['pencil',      'تعديل'],
    'delete'  => ['trash',       'حذف'],
    'confirm' => ['check-circle','تأكيد'],
    'print'   => ['printer',     'طباعة'],
    'export'  => ['download',    'تصدير'],
];

$sectionColors = [
    'sales'     => ['#3b82f6','#eff6ff'],
    'purchases' => ['#f59e0b','#fffbeb'],
    'inventory' => ['#10b981','#f0fdf4'],
    'finance'   => ['#8b5cf6','#fdf4ff'],
    'crm'       => ['#0d9488','#f0fdfa'],
    'admin'     => ['#ef4444','#fef2f2'],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>صلاحيات المستخدم — <?= htmlspecialchars($targetUser['full_name']) ?></title>
<link rel="icon" href="/bayhas/assets/images/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="/bayhas/assets/css/layout.css" rel="stylesheet">
<style>
.user-card {
    background:#fff;border-radius:14px;border:1px solid #e2e8f0;
    padding:1.25rem 1.5rem;margin-bottom:1.25rem;
    display:flex;align-items:center;gap:1rem;flex-wrap:wrap;
}
.u-avatar {
    width:52px;height:52px;border-radius:50%;
    background:#1e3a8a;color:#fff;font-size:1.3rem;font-weight:700;
    display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.branch-tabs {
    background:#fff;border-radius:14px;border:1px solid #e2e8f0;
    padding:.75rem 1rem;margin-bottom:1.25rem;
    display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;
}
.branch-tab {
    padding:.35rem .9rem;border-radius:8px;font-size:.82rem;
    cursor:pointer;border:1.5px solid #e2e8f0;
    background:#f8fafc;color:#64748b;text-decoration:none;
    transition:all .15s;
}
.branch-tab:hover { border-color:#3b82f6;color:#3b82f6;background:#eff6ff; }
.branch-tab.active {
    background:#1e3a8a;color:#fff;border-color:#1e3a8a;
}
.branch-tab.no-access { opacity:.4;cursor:not-allowed; }

.perm-section {
    background:#fff;border-radius:14px;border:1px solid #e2e8f0;
    overflow:hidden;margin-bottom:1rem;
}
.perm-section-header {
    padding:.85rem 1.25rem;display:flex;align-items:center;
    justify-content:space-between;border-bottom:1px solid #f1f5f9;
}
.perm-section-title {
    display:flex;align-items:center;gap:.6rem;font-weight:600;font-size:.9rem;
}
.perm-section-icon {
    width:32px;height:32px;border-radius:8px;
    display:flex;align-items:center;justify-content:center;font-size:1rem;
}
.perm-row {
    display:grid;
    grid-template-columns: 200px repeat(7, 1fr) 80px;
    align-items:center;padding:.6rem 1.25rem;
    border-bottom:1px solid #f8fafc;gap:.25rem;
}
.perm-row:last-child { border-bottom:none; }
.perm-row:hover { background:#fafafa; }
.perm-row-header {
    grid-template-columns: 200px repeat(7, 1fr) 80px;
    background:#f8fafc;padding:.5rem 1.25rem;
    border-bottom:1px solid #f1f5f9;
}
.perm-label { font-size:.83rem;color:#374151;display:flex;align-items:center;gap:.5rem; }
.perm-label .bi { font-size:.85rem;color:#94a3b8; }
.perm-header-label { font-size:.72rem;color:#64748b;text-align:center;font-weight:600; }
.perm-check-wrap { display:flex;justify-content:center; }
.perm-check {
    width:18px;height:18px;cursor:pointer;
    accent-color:#3b82f6;border-radius:4px;
}
.row-all-label { font-size:.72rem;color:#94a3b8;text-align:center; }
.btn-row-all {
    font-size:.7rem;padding:.2rem .5rem;border-radius:6px;
    border:1px solid #e2e8f0;background:#f8fafc;cursor:pointer;
    color:#64748b;transition:all .15s;white-space:nowrap;
}
.btn-row-all:hover { background:#eff6ff;color:#3b82f6;border-color:#bfdbfe; }
.save-bar {
    position:sticky;bottom:1rem;z-index:100;
    display:flex;justify-content:center;margin-top:1.5rem;
}
.save-bar button {
    background:#1e3a8a;color:#fff;border:none;
    padding:.75rem 2.5rem;border-radius:12px;font-size:.95rem;
    font-family:'Cairo',sans-serif;font-weight:600;cursor:pointer;
    box-shadow:0 4px 16px rgba(30,58,138,.3);transition:all .2s;
    display:flex;align-items:center;gap:.5rem;
}
.save-bar button:hover { background:#1d4ed8;transform:translateY(-1px); }
.preset-btns { display:flex;gap:.5rem;flex-wrap:wrap; }
.preset-btn {
    font-size:.75rem;padding:.25rem .7rem;border-radius:7px;
    border:1px solid #e2e8f0;cursor:pointer;background:#fff;
    color:#64748b;transition:all .15s;
}
.preset-btn:hover { background:#f1f5f9; }

@media(max-width:768px){
    .perm-row, .perm-row-header {
        grid-template-columns: 1fr;
        padding:.75rem 1rem;
    }
    .perm-check-wrap { justify-content:flex-start; }
}
</style>
</head>
<body>
<div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
<?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>

<header class="topbar">
    <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
    <span class="tb-title">الصلاحيات</span>
    <span class="tb-branch"><i class="bi bi-key me-1"></i><?= htmlspecialchars($branchName) ?></span>
    <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.8rem;color:#94a3b8">
        <a href="users.php" class="text-primary text-decoration-none">المستخدمون</a>
        <i class="bi bi-chevron-left mx-1" style="font-size:.7rem"></i>
        <span>صلاحيات: <?= htmlspecialchars($targetUser['full_name']) ?></span>
    </nav>
</header>

<main class="main-content">
<div class="content-body">

    <!-- بطاقة المستخدم -->
    <div class="user-card">
        <div class="u-avatar"><?= mb_substr($targetUser['full_name'], 0, 1) ?></div>
        <div class="flex-grow-1">
            <div style="font-size:1rem;font-weight:700;color:#1e293b"><?= htmlspecialchars($targetUser['full_name']) ?></div>
            <div class="text-muted" style="font-size:.82rem">@<?= htmlspecialchars($targetUser['username']) ?></div>
        </div>
        <?php if ($targetUser['role'] === 'admin'): ?>
        <div class="alert alert-warning d-flex align-items-center gap-2 mb-0 py-2 px-3"
             style="border-radius:10px;font-size:.83rem">
            <i class="bi bi-shield-fill-check text-warning"></i>
            المستخدم Admin — يمتلك كل الصلاحيات تلقائياً
        </div>
        <?php else: ?>
        <div class="preset-btns">
            <button class="preset-btn" onclick="presetAll(true)">✅ تحديد الكل</button>
            <button class="preset-btn" onclick="presetAll(false)">❌ إلغاء الكل</button>
            <button class="preset-btn" onclick="presetViewOnly()">👁 عرض فقط</button>
        </div>
        <?php endif; ?>
    </div>

    <!-- تبويبات الفروع -->
    <div class="branch-tabs">
        <span class="text-muted small ms-1 me-2">الفرع:</span>
        <?php foreach ($allBranches as $b):
            $hasAccess = in_array($b['id'], $userBranchIds);
            $isActive  = $b['id'] == $selectedBranch;
        ?>
        <a href="?user_id=<?= $targetUserId ?>&name=<?= urlencode($targetUser['full_name']) ?>&bid=<?= $b['id'] ?>"
           class="branch-tab <?= $isActive ? 'active' : '' ?> <?= !$hasAccess ? 'no-access' : '' ?>"
           title="<?= !$hasAccess ? 'المستخدم ليس لديه وصول لهذا الفرع' : '' ?>">
            <i class="bi bi-building me-1"></i>
            <?= htmlspecialchars($b['name']) ?>
            <?php if (!$hasAccess): ?><i class="bi bi-lock-fill ms-1" style="font-size:.65rem"></i><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ($targetUser['role'] !== 'admin'): ?>

    <!-- جدول الصلاحيات -->
    <?php foreach ($parents as $section): if (empty($section['children'])) continue;
        $sKey = $section['key'];
        [$sColor, $sBg] = $sectionColors[$sKey] ?? ['#64748b','#f8fafc'];
    ?>
    <div class="perm-section">

        <!-- رأس القسم -->
        <div class="perm-section-header">
            <div class="perm-section-title">
                <div class="perm-section-icon" style="background:<?= $sBg ?>;color:<?= $sColor ?>">
                    <i class="bi <?= htmlspecialchars($section['icon']) ?>"></i>
                </div>
                <span style="color:<?= $sColor ?>"><?= htmlspecialchars($section['label']) ?></span>
            </div>
            <div class="d-flex gap-2">
                <button class="preset-btn" onclick="sectionAll('<?= $sKey ?>', true)">تحديد القسم</button>
                <button class="preset-btn" onclick="sectionAll('<?= $sKey ?>', false)">إلغاء القسم</button>
            </div>
        </div>

        <!-- رأس الأعمدة -->
        <div class="perm-row perm-row-header">
            <div class="perm-header-label" style="text-align:right">الصفحة / القسم</div>
            <?php foreach ($actions as $actKey => [$icon, $label]): ?>
            <div class="perm-header-label">
                <i class="bi bi-<?= $icon ?> d-block mb-1" style="font-size:.9rem"></i>
                <?= $label ?>
            </div>
            <?php endforeach; ?>
            <div class="perm-header-label">الكل</div>
        </div>

        <!-- صفوف الصلاحيات -->
        <?php foreach ($section['children'] as $child):
            $mKey = $child['key'];
            $mp   = $currentPerms[$mKey] ?? [];
        ?>
        <div class="perm-row" data-section="<?= $sKey ?>">
            <div class="perm-label">
                <i class="bi <?= htmlspecialchars($child['icon']) ?>"></i>
                <?= htmlspecialchars($child['label']) ?>
            </div>
            <?php foreach ($actions as $actKey => [$icon, $label]): ?>
            <div class="perm-check-wrap">
                <input type="checkbox"
                       class="perm-check"
                       name="perms[<?= $mKey ?>][]"
                       value="<?= $actKey ?>"
                       data-section="<?= $sKey ?>"
                       data-module="<?= $mKey ?>"
                       data-action="<?= $actKey ?>"
                       <?= !empty($mp['can_' . $actKey]) ? 'checked' : '' ?>
                       onchange="onPermChange(this)">
            </div>
            <?php endforeach; ?>
            <div class="perm-check-wrap">
                <button class="btn-row-all" onclick="rowAll('<?= $mKey ?>')">كل</button>
            </div>
        </div>
        <?php endforeach; ?>

    </div>
    <?php endforeach; ?>

    <!-- زر الحفظ -->
    <div class="save-bar">
        <button onclick="savePerms()" id="saveBtn">
            <i class="bi bi-floppy"></i>
            حفظ الصلاحيات
            <span id="saveSpinner" class="spinner-border spinner-border-sm ms-1" style="display:none"></span>
        </button>
    </div>

    <?php endif; ?>

</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sbOverlay');
function sbOpen()  { sidebar.classList.add('open');  overlay.classList.add('show'); }
function sbClose() { sidebar.classList.remove('open'); overlay.classList.remove('show'); }
window.addEventListener('resize', () => { if(window.innerWidth>991) sbClose(); });
document.querySelectorAll('.sb-group').forEach(g => {
    if (localStorage.getItem('sb_open_' + g.dataset.key) === 'true') g.classList.add('open');
});
function toggleGroup(g) {
    const o = g.classList.contains('open');
    document.querySelectorAll('.sb-group.open').forEach(x => x.classList.remove('open'));
    g.classList.toggle('open', !o);
    localStorage.setItem('sb_open_' + g.dataset.key, (!o).toString());
}

// ── منطق الصلاحيات ──────────────────────────────────────────────
function allChecks(selector) {
    return document.querySelectorAll(selector);
}

function presetAll(val) {
    allChecks('.perm-check').forEach(c => c.checked = val);
}

function presetViewOnly() {
    allChecks('.perm-check').forEach(c => {
        c.checked = c.dataset.action === 'view';
    });
}

function sectionAll(section, val) {
    allChecks(`.perm-check[data-section="${section}"]`).forEach(c => c.checked = val);
}

function rowAll(module) {
    const checks = allChecks(`.perm-check[data-module="${module}"]`);
    const allOn  = [...checks].every(c => c.checked);
    checks.forEach(c => c.checked = !allOn);
}

// إذا شيّك view تلقائياً يشيّك create/edit/delete معها
function onPermChange(el) {
    if (el.dataset.action !== 'view') {
        if (el.checked) {
            const viewCheck = document.querySelector(`.perm-check[data-module="${el.dataset.module}"][data-action="view"]`);
            if (viewCheck) viewCheck.checked = true;
        }
    } else if (!el.checked) {
        // إذا ألغى view، يلغي الكل
        document.querySelectorAll(`.perm-check[data-module="${el.dataset.module}"]`)
            .forEach(c => c.checked = false);
    }
}

// ── حفظ ─────────────────────────────────────────────────────────
function savePerms() {
    const btn     = document.getElementById('saveBtn');
    const spinner = document.getElementById('saveSpinner');
    btn.disabled  = true;
    spinner.style.display = 'inline-block';

    const fd = new FormData();
    fd.append('branch_id', <?= $selectedBranch ?>);

    document.querySelectorAll('.perm-check:checked').forEach(c => {
        fd.append(`perms[${c.dataset.module}][]`, c.dataset.action);
    });

    fetch(location.href.split('?')[0] +
          '?user_id=<?= $targetUserId ?>&bid=<?= $selectedBranch ?>',
        {method:'POST', body: fd})
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            spinner.style.display = 'none';
            if (d.ok) showToast(d.msg, 'success');
            else      showToast(d.msg, 'danger');
        })
        .catch(() => {
            btn.disabled = false;
            spinner.style.display = 'none';
            showToast('خطأ في الاتصال بالخادم', 'danger');
        });
}

function showToast(msg, type) {
    const t = document.createElement('div');
    t.className = `alert alert-${type} shadow`;
    t.style.cssText = 'position:fixed;top:80px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:280px;text-align:center;font-size:.88rem';
    t.innerHTML = `<i class="bi bi-${type==='success'?'check-circle':'exclamation-triangle'} me-2"></i>${msg}`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3000);
}
</script>
</body>
</html>
