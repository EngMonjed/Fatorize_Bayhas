<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('admin.users', 'view');

$branchId   = (int)$_SESSION['branch_id'];
$branchName = $_SESSION['branch_name'] ?? 'الفرع';
$currentModule = 'admin.users';

// ── CRUD API (Ajax) ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = $_POST['_action'];

    try {
        // ── إنشاء مستخدم ──
        if ($act === 'create') {
            requirePermission('admin.users', 'create');
            $username  = trim($_POST['username'] ?? '');
            $fullName  = trim($_POST['full_name'] ?? '');
            $email     = trim($_POST['email'] ?? '');
            $password  = $_POST['password'] ?? '';
            $role      = $_POST['role'] ?? 'user';
            $branches  = $_POST['branches'] ?? [];

            if (!$username || !$fullName || !$password)
                throw new Exception('يرجى تعبئة الحقول المطلوبة');
            if (strlen($password) < 6)
                throw new Exception('كلمة المرور يجب أن تكون 6 أحرف على الأقل');

            $check = $pdo->prepare("SELECT id FROM users WHERE username=?");
            $check->execute([$username]);
            if ($check->fetchColumn())
                throw new Exception('اسم المستخدم موجود مسبقاً');

            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
            $pdo->prepare("INSERT INTO users (username,full_name,email,password,role,is_active,created_at) VALUES (?,?,?,?,?,1,NOW())")
                ->execute([$username, $fullName, $email, $hash, $role]);
            $uid = (int)$pdo->lastInsertId();

            foreach ((array)$branches as $bid) {
                $pdo->prepare("INSERT IGNORE INTO user_branches (user_id,branch_id) VALUES (?,?)")
                    ->execute([$uid, (int)$bid]);
            }
            echo json_encode(['ok' => true, 'msg' => 'تم إنشاء المستخدم بنجاح', 'id' => $uid]);
        }

        // ── تعديل مستخدم ──
        elseif ($act === 'update') {
            requirePermission('admin.users', 'edit');
            $uid      = (int)($_POST['id'] ?? 0);
            $fullName = trim($_POST['full_name'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $role     = $_POST['role'] ?? 'user';
            $active   = (int)($_POST['is_active'] ?? 1);
            $branches = $_POST['branches'] ?? [];

            if (!$uid || !$fullName) throw new Exception('بيانات غير مكتملة');

            $params = [$fullName, $email, $role, $active];
            $sql    = "UPDATE users SET full_name=?,email=?,role=?,is_active=?,updated_at=NOW()";

            if (!empty($_POST['password'])) {
                if (strlen($_POST['password']) < 6)
                    throw new Exception('كلمة المرور يجب أن تكون 6 أحرف على الأقل');
                $sql     .= ",password=?";
                $params[] = password_hash($_POST['password'], PASSWORD_BCRYPT, ['cost' => 10]);
            }
            $params[] = $uid;
            $pdo->prepare($sql . " WHERE id=?")->execute($params);

            // تحديث الفروع
            $pdo->prepare("DELETE FROM user_branches WHERE user_id=?")->execute([$uid]);
            foreach ((array)$branches as $bid) {
                $pdo->prepare("INSERT IGNORE INTO user_branches (user_id,branch_id) VALUES (?,?)")
                    ->execute([$uid, (int)$bid]);
            }
            echo json_encode(['ok' => true, 'msg' => 'تم تحديث المستخدم بنجاح']);
        }

        // ── حذف مستخدم ──
        elseif ($act === 'delete') {
            requirePermission('admin.users', 'delete');
            $uid = (int)($_POST['id'] ?? 0);
            if (!$uid) throw new Exception('معرف غير صحيح');
            if ($uid === (int)$_SESSION['user_id'])
                throw new Exception('لا يمكنك حذف حسابك الحالي');
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
            echo json_encode(['ok' => true, 'msg' => 'تم حذف المستخدم']);
        }

        // ── جلب بيانات مستخدم ──
        elseif ($act === 'get') {
            $uid  = (int)($_POST['id'] ?? 0);
            $user = $pdo->prepare("SELECT id,username,full_name,email,role,is_active FROM users WHERE id=?");
            $user->execute([$uid]);
            $u = $user->fetch();
            if (!$u) throw new Exception('المستخدم غير موجود');

            $bStmt = $pdo->prepare("SELECT branch_id FROM user_branches WHERE user_id=?");
            $bStmt->execute([$uid]);
            $u['branches'] = array_column($bStmt->fetchAll(), 'branch_id');
            echo json_encode(['ok' => true, 'data' => $u]);
        }

        // ── تبديل التفعيل ──
        elseif ($act === 'toggle') {
            requirePermission('admin.users', 'edit');
            $uid = (int)($_POST['id'] ?? 0);
            if ($uid === (int)$_SESSION['user_id'])
                throw new Exception('لا يمكنك تعطيل حسابك الحالي');
            $pdo->prepare("UPDATE users SET is_active = 1-is_active, updated_at=NOW() WHERE id=?")
                ->execute([$uid]);
            $newVal = $pdo->prepare("SELECT is_active FROM users WHERE id=?");
            $newVal->execute([$uid]);
            echo json_encode(['ok' => true, 'is_active' => (int)$newVal->fetchColumn()]);
        }

        else throw new Exception('إجراء غير معروف');

    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── جلب البيانات للعرض ──────────────────────────────────────────
$users = $pdo->query("
    SELECT u.id, u.username, u.full_name, u.email, u.role, u.is_active,
           u.last_login, u.created_at,
           GROUP_CONCAT(b.name ORDER BY b.name SEPARATOR ', ') AS branch_names,
           COUNT(DISTINCT ub.branch_id) AS branch_count
    FROM users u
    LEFT JOIN user_branches ub ON ub.user_id = u.id
    LEFT JOIN branches b ON b.id = ub.branch_id
    GROUP BY u.id ORDER BY u.created_at DESC
")->fetchAll();

$allBranches = $pdo->query("SELECT id, name FROM branches WHERE status='active' ORDER BY sort_order")->fetchAll();

$roleLabels = [
    'admin'      => ['Admn',          'danger'],
    'accountant' => ['محاسب',         'info'],
    'sales'      => ['مبيعات',        'success'],
    'purchases'  => ['مشتريات',       'warning'],
    'warehouse'  => ['مستودع',        'secondary'],
    'user'       => ['مستخدم',        'light'],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>إدارة المستخدمين — <?= htmlspecialchars($branchName) ?></title>
<link rel="icon" href="/bayhas/assets/images/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="/bayhas/assets/css/layout.css" rel="stylesheet">
<style>
.user-avatar {
    width: 38px; height: 38px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: .85rem; font-weight: 700; flex-shrink: 0;
    color: #fff;
}
.page-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 1.5rem; flex-wrap: wrap; gap: .75rem;
}
.page-header h5 { margin: 0; font-size: 1.05rem; font-weight: 700; color: #1e293b; }
.filter-bar {
    background: #fff; border-radius: 14px; border: 1px solid #e2e8f0;
    padding: .85rem 1.25rem; margin-bottom: 1.25rem;
    display: flex; gap: .75rem; flex-wrap: wrap; align-items: center;
}
.filter-bar input, .filter-bar select {
    border-radius: 8px; border: 1px solid #e2e8f0;
    padding: .4rem .85rem; font-size: .85rem; font-family: 'Cairo', sans-serif;
}
.filter-bar input:focus, .filter-bar select:focus {
    border-color: #3b82f6; outline: none; box-shadow: 0 0 0 3px rgba(59,130,246,.1);
}
.users-table { background: #fff; border-radius: 14px; border: 1px solid #e2e8f0; overflow: hidden; }
.users-table table { margin: 0; font-size: .84rem; }
.users-table th {
    background: #f8fafc; color: #64748b;
    font-size: .77rem; font-weight: 600;
    border: none; padding: .75rem 1rem; white-space: nowrap;
}
.users-table td { padding: .7rem 1rem; vertical-align: middle; border-top: 1px solid #f1f5f9; }
.users-table tbody tr:hover { background: #f8fafc; }
.btn-action {
    width: 30px; height: 30px; border-radius: 8px;
    display: inline-flex; align-items: center; justify-content: center;
    border: 1px solid #e2e8f0; background: #fff;
    color: #64748b; cursor: pointer; transition: all .15s;
    text-decoration: none; font-size: .9rem;
}
.btn-action:hover { background: #f1f5f9; color: #1e293b; border-color: #cbd5e1; }
.btn-action.edit:hover  { background: #eff6ff; color: #3b82f6; border-color: #bfdbfe; }
.btn-action.perm:hover  { background: #fdf4ff; color: #9333ea; border-color: #e9d5ff; }
.btn-action.del:hover   { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
.toggle-switch { position: relative; width: 38px; height: 20px; display: inline-block; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider {
    position: absolute; inset: 0;
    background: #e2e8f0; border-radius: 20px; cursor: pointer;
    transition: background .2s;
}
.toggle-slider::before {
    content: ''; position: absolute;
    width: 14px; height: 14px; background: #fff;
    border-radius: 50%; top: 3px; right: 3px;
    transition: transform .2s; box-shadow: 0 1px 3px rgba(0,0,0,.2);
}
input:checked + .toggle-slider { background: #22c55e; }
input:checked + .toggle-slider::before { transform: translateX(-18px); }
.empty-state { text-align: center; padding: 3rem 1rem; color: #94a3b8; }
.empty-state .bi { font-size: 2.5rem; opacity: .3; display: block; margin-bottom: .75rem; }
</style>
</head>
<body>
<div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
<?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>

<header class="topbar">
    <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
    <span class="tb-title">إدارة المستخدمين</span>
    <span class="tb-branch"><i class="bi bi-shield-check me-1"></i><?= htmlspecialchars($branchName) ?></span>
    <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.8rem;color:#94a3b8">
        <span>الرئيسية</span><i class="bi bi-chevron-left mx-1" style="font-size:.7rem"></i>
        <span>الإدارة</span><i class="bi bi-chevron-left mx-1" style="font-size:.7rem"></i>
        <span class="text-primary">المستخدمون</span>
    </nav>
</header>

<main class="main-content">
<div class="content-body">

    <div class="page-header">
        <h5><i class="bi bi-people me-2 text-primary"></i>المستخدمون</h5>
        <?php if (can('admin.users','create')): ?>
        <button class="btn btn-primary btn-sm d-flex align-items-center gap-1"
                style="border-radius:10px;font-size:.85rem" onclick="openCreate()">
            <i class="bi bi-person-plus"></i> مستخدم جديد
        </button>
        <?php endif; ?>
    </div>

    <!-- فلاتر -->
    <div class="filter-bar">
        <input type="search" id="searchInput" placeholder="ابحث عن مستخدم..." style="flex:1;min-width:180px">
        <select id="roleFilter" onchange="filterTable()">
            <option value="">كل الأدوار</option>
            <option value="admin">Admin</option>
            <option value="accountant">محاسب</option>
            <option value="sales">مبيعات</option>
            <option value="purchases">مشتريات</option>
            <option value="warehouse">مستودع</option>
            <option value="user">مستخدم</option>
        </select>
        <select id="statusFilter" onchange="filterTable()">
            <option value="">كل الحالات</option>
            <option value="1">نشط</option>
            <option value="0">معطل</option>
        </select>
        <span class="text-muted small ms-auto" id="countLabel">
            <?= count($users) ?> مستخدم
        </span>
    </div>

    <!-- الجدول -->
    <div class="users-table">
        <?php if (empty($users)): ?>
        <div class="empty-state">
            <i class="bi bi-people"></i>
            لا يوجد مستخدمون حتى الآن
        </div>
        <?php else: ?>
        <div class="table-responsive">
        <table id="usersTable">
            <thead>
                <tr>
                    <th style="width:44px">#</th>
                    <th>المستخدم</th>
                    <th>البريد</th>
                    <th>الدور</th>
                    <th>الفروع</th>
                    <th>آخر دخول</th>
                    <th style="width:60px">نشط</th>
                    <th style="width:110px;text-align:center">إجراءات</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $i => $u):
                [$roleLbl, $roleCls] = $roleLabels[$u['role']] ?? ['—','secondary'];
                $colors = ['#3b82f6','#8b5cf6','#10b981','#f59e0b','#ef4444','#06b6d4'];
                $color  = $colors[$u['id'] % count($colors)];
                $initials = mb_substr($u['full_name'] ?: $u['username'], 0, 1);
            ?>
            <tr data-role="<?= $u['role'] ?>" data-active="<?= $u['is_active'] ?>">
                <td class="text-muted small"><?= $i + 1 ?></td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="user-avatar" style="background:<?= $color ?>">
                            <?= htmlspecialchars($initials) ?>
                        </div>
                        <div>
                            <div class="fw-600" style="font-size:.88rem"><?= htmlspecialchars($u['full_name']) ?></div>
                            <div class="text-muted" style="font-size:.75rem">@<?= htmlspecialchars($u['username']) ?></div>
                        </div>
                    </div>
                </td>
                <td class="text-muted small"><?= htmlspecialchars($u['email'] ?: '—') ?></td>
                <td>
                    <span class="badge bg-<?= $roleCls ?>-subtle text-<?= $roleCls ?> border border-<?= $roleCls ?>-subtle"
                          style="font-size:.72rem;border-radius:6px">
                        <?= $roleLbl ?>
                    </span>
                </td>
                <td>
                    <?php if ($u['branch_count'] > 0): ?>
                    <span class="badge bg-light text-secondary border" style="font-size:.72rem">
                        <i class="bi bi-building me-1"></i><?= $u['branch_count'] ?> فروع
                    </span>
                    <div class="text-muted" style="font-size:.72rem;margin-top:2px">
                        <?= htmlspecialchars($u['branch_names'] ?? '') ?>
                    </div>
                    <?php else: ?>
                    <span class="text-muted small">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-muted small">
                    <?= $u['last_login'] ? date('d/m/Y H:i', strtotime($u['last_login'])) : '—' ?>
                </td>
                <td>
                    <?php if (can('admin.users','edit')): ?>
                    <label class="toggle-switch">
                        <input type="checkbox" <?= $u['is_active'] ? 'checked' : '' ?>
                               onchange="toggleUser(<?= $u['id'] ?>, this)">
                        <span class="toggle-slider"></span>
                    </label>
                    <?php else: ?>
                    <span class="badge bg-<?= $u['is_active'] ? 'success' : 'secondary' ?>-subtle
                                text-<?= $u['is_active'] ? 'success' : 'secondary' ?>">
                        <?= $u['is_active'] ? 'نشط' : 'معطل' ?>
                    </span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="d-flex gap-1 justify-content-center">
                        <?php if (can('admin.users','edit')): ?>
                        <button class="btn-action edit" onclick="openEdit(<?= $u['id'] ?>)"
                                title="تعديل"><i class="bi bi-pencil"></i></button>
                        <?php endif; ?>
                        <?php if (can('admin.permissions','view')): ?>
                        <a class="btn-action perm"
                           href="permissions.php?user_id=<?= $u['id'] ?>&name=<?= urlencode($u['full_name']) ?>"
                           title="الصلاحيات"><i class="bi bi-key"></i></a>
                        <?php endif; ?>
                        <?php if (can('admin.users','delete') && $u['id'] !== (int)$_SESSION['user_id']): ?>
                        <button class="btn-action del" onclick="deleteUser(<?= $u['id'] ?>, '<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>')"
                                title="حذف"><i class="bi bi-trash"></i></button>
                        <?php endif; ?>
                    </div>
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

<!-- ═══ Modal: إنشاء / تعديل مستخدم ═══ -->
<div class="modal fade" id="userModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header" style="background:#1e3a8a;border-radius:16px 16px 0 0;border:none;padding:1rem 1.5rem">
        <h6 class="modal-title text-white fw-700 mb-0" id="modalTitle">مستخدم جديد</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <input type="hidden" id="userId">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label small fw-600 text-secondary mb-1">الاسم الكامل <span class="text-danger">*</span></label>
            <input type="text" id="fullName" class="form-control" placeholder="محمد أحمد">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-600 text-secondary mb-1">اسم المستخدم <span class="text-danger">*</span></label>
            <input type="text" id="username" class="form-control" placeholder="m.ahmed" dir="ltr">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-600 text-secondary mb-1">البريد الإلكتروني</label>
            <input type="email" id="email" class="form-control" placeholder="m@company.com" dir="ltr">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-600 text-secondary mb-1">كلمة المرور <span id="passRequired" class="text-danger">*</span></label>
            <div class="input-group">
              <input type="password" id="password" class="form-control" placeholder="6 أحرف على الأقل" dir="ltr">
              <button class="btn btn-outline-secondary" type="button" onclick="togglePass()">
                <i class="bi bi-eye" id="eyeIcon"></i>
              </button>
            </div>
            <div class="form-text text-muted" id="passHint" style="display:none">اتركها فارغة للإبقاء على الحالية</div>
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-600 text-secondary mb-1">الدور</label>
            <select id="role" class="form-select">
              <option value="user">مستخدم عادي</option>
              <option value="sales">مبيعات</option>
              <option value="purchases">مشتريات</option>
              <option value="warehouse">مستودع</option>
              <option value="accountant">محاسب</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-600 text-secondary mb-1">الحالة</label>
            <select id="isActive" class="form-select">
              <option value="1">نشط</option>
              <option value="0">معطل</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label small fw-600 text-secondary mb-2">الفروع المسموح بها</label>
            <div class="d-flex flex-wrap gap-2">
              <?php foreach ($allBranches as $b): ?>
              <label class="d-flex align-items-center gap-2 border rounded-3 px-3 py-2"
                     style="cursor:pointer;font-size:.85rem;background:#f8fafc">
                <input type="checkbox" class="branch-check" value="<?= $b['id'] ?>"
                       style="width:15px;height:15px;accent-color:#3b82f6">
                <span><?= htmlspecialchars($b['name']) ?></span>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0 pb-4 px-4">
        <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal" style="border-radius:8px">إلغاء</button>
        <button type="button" class="btn btn-primary btn-sm" style="border-radius:8px;min-width:100px" onclick="saveUser()">
          <span id="saveText">حفظ</span>
          <span id="saveSpinner" class="spinner-border spinner-border-sm ms-1" style="display:none"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sbOverlay');
function sbOpen()  { sidebar.classList.add('open');  overlay.classList.add('show'); }
function sbClose() { sidebar.classList.remove('open'); overlay.classList.remove('show'); }
window.addEventListener('resize', () => { if (window.innerWidth > 991) sbClose(); });
document.querySelectorAll('.sb-group').forEach(g => {
    if (localStorage.getItem('sb_open_' + g.dataset.key) === 'true') g.classList.add('open');
});
function toggleGroup(g) {
    const o = g.classList.contains('open');
    document.querySelectorAll('.sb-group.open').forEach(x => x.classList.remove('open'));
    g.classList.toggle('open', !o);
    localStorage.setItem('sb_open_' + g.dataset.key, (!o).toString());
}

const modal = new bootstrap.Modal(document.getElementById('userModal'));
let editMode = false;

function openCreate() {
    editMode = false;
    document.getElementById('modalTitle').textContent = 'مستخدم جديد';
    document.getElementById('userId').value = '';
    document.getElementById('fullName').value = '';
    document.getElementById('username').value = '';
    document.getElementById('email').value = '';
    document.getElementById('password').value = '';
    document.getElementById('role').value = 'user';
    document.getElementById('isActive').value = '1';
    document.getElementById('passRequired').style.display = '';
    document.getElementById('passHint').style.display = 'none';
    document.getElementById('username').disabled = false;
    document.querySelectorAll('.branch-check').forEach(c => c.checked = false);
    modal.show();
}

function openEdit(id) {
    editMode = true;
    document.getElementById('modalTitle').textContent = 'تعديل المستخدم';
    document.getElementById('passRequired').style.display = 'none';
    document.getElementById('passHint').style.display = 'block';
    document.getElementById('username').disabled = true;
    post({_action:'get', id}, d => {
        document.getElementById('userId').value   = d.id;
        document.getElementById('fullName').value = d.full_name;
        document.getElementById('username').value = d.username;
        document.getElementById('email').value    = d.email || '';
        document.getElementById('role').value     = d.role;
        document.getElementById('isActive').value = d.is_active;
        document.getElementById('password').value = '';
        document.querySelectorAll('.branch-check').forEach(c => {
            c.checked = d.branches.map(Number).includes(Number(c.value));
        });
        modal.show();
    });
}

function saveUser() {
    const id       = document.getElementById('userId').value;
    const fullName = document.getElementById('fullName').value.trim();
    const username = document.getElementById('username').value.trim();
    const email    = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value;
    const role     = document.getElementById('role').value;
    const isActive = document.getElementById('isActive').value;
    const branches = [...document.querySelectorAll('.branch-check:checked')].map(c => c.value);

    if (!fullName || (!editMode && !username)) {
        alert('يرجى تعبئة الحقول المطلوبة'); return;
    }
    if (!editMode && !password) { alert('كلمة المرور مطلوبة'); return; }
    if (password && password.length < 6) { alert('كلمة المرور يجب أن تكون 6 أحرف على الأقل'); return; }

    setBtnLoading(true);
    const data = {
        _action: editMode ? 'update' : 'create',
        id, full_name: fullName, username, email,
        password, role, is_active: isActive, branches
    };
    post(data, () => { modal.hide(); location.reload(); });
}

function deleteUser(id, name) {
    if (!confirm(`هل تريد حذف المستخدم "${name}"؟\nلا يمكن التراجع عن هذا الإجراء.`)) return;
    post({_action:'delete', id}, () => location.reload());
}

function toggleUser(id, el) {
    post({_action:'toggle', id}, d => {
        el.checked = d.is_active === 1;
        const row = el.closest('tr');
        row.dataset.active = d.is_active;
    });
}

function togglePass() {
    const inp = document.getElementById('password');
    const ico = document.getElementById('eyeIcon');
    if (inp.type === 'password') { inp.type = 'text'; ico.className = 'bi bi-eye-slash'; }
    else { inp.type = 'password'; ico.className = 'bi bi-eye'; }
}

// فلترة الجدول
document.getElementById('searchInput').addEventListener('input', filterTable);
function filterTable() {
    const s    = document.getElementById('searchInput').value.toLowerCase();
    const role = document.getElementById('roleFilter').value;
    const sta  = document.getElementById('statusFilter').value;
    let vis = 0;
    document.querySelectorAll('#usersTable tbody tr').forEach(tr => {
        const text   = tr.textContent.toLowerCase();
        const trRole = tr.dataset.role;
        const trAct  = tr.dataset.active;
        const show = (!s || text.includes(s))
                  && (!role || trRole === role)
                  && (sta === '' || trAct === sta);
        tr.style.display = show ? '' : 'none';
        if (show) vis++;
    });
    document.getElementById('countLabel').textContent = vis + ' مستخدم';
}

// POST helper
function post(data, onSuccess) {
    const fd = new FormData();
    Object.entries(data).forEach(([k, v]) => {
        if (Array.isArray(v)) v.forEach(val => fd.append(k + '[]', val));
        else fd.append(k, v ?? '');
    });
    fetch(location.href, {method:'POST', body: fd})
        .then(r => r.json())
        .then(d => {
            setBtnLoading(false);
            if (d.ok) { if (onSuccess) onSuccess(d.data || d); }
            else alert(d.msg || 'حدث خطأ غير متوقع');
        })
        .catch(() => { setBtnLoading(false); alert('خطأ في الاتصال'); });
}

function setBtnLoading(on) {
    document.getElementById('saveText').style.opacity    = on ? '0' : '1';
    document.getElementById('saveSpinner').style.display = on ? 'inline-block' : 'none';
}
</script>
</body>
</html>
