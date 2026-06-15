<?php
/**
 * config/auth.php — المصادقة + الصلاحيات
 */

if (session_status() === PHP_SESSION_NONE) session_start();

// ── الدوال الأساسية ──────────────────────────────────────────────

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function hasBranch(): bool {
    return !empty($_SESSION['branch_id']);
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;
    return [
        'id'          => $_SESSION['user_id'],
        'username'    => $_SESSION['username']    ?? '',
        'full_name'   => $_SESSION['full_name']   ?? '',
        'role'        => $_SESSION['role']         ?? 'user',
        'branch_id'   => $_SESSION['branch_id']   ?? null,
        'branch_name' => $_SESSION['branch_name'] ?? '',
    ];
}

function isAdmin(): bool {
    return ($_SESSION['role'] ?? '') === 'admin';
}

function hasRole(string ...$roles): bool {
    return in_array($_SESSION['role'] ?? 'user', $roles, true);
}

// ── حماية الصفحة ────────────────────────────────────────────────

function checkLogin(?PDO $pdo = null): void {
    if (!isLoggedIn()) {
        header('Location: /bayhas/login.php'); exit;
    }
    if (!hasBranch()) {
        header('Location: /bayhas/select_account.php'); exit;
    }
    // تحميل الصلاحيات تلقائياً إذا أُعطي اتصال
    if ($pdo instanceof PDO) {
        loadUserPermissions($pdo);
    }
}

// ── الصلاحيات ───────────────────────────────────────────────────

/**
 * جلب صلاحيات المستخدم الحالي (مُخزَّنة في Session للسرعة)
 */
function loadUserPermissions(PDO $pdo): void {
    if (isset($_SESSION['permissions'])) return; // محمّلة مسبقاً

    $userId   = $_SESSION['user_id']   ?? 0;
    $branchId = $_SESSION['branch_id'] ?? 0;

    if (isAdmin()) {
        // الـ admin له كل الصلاحيات
        $_SESSION['permissions'] = ['*'];
        return;
    }

    $stmt = $pdo->prepare("
        SELECT module_key,
               can_view, can_create, can_edit,
               can_delete, can_confirm, can_print, can_export
        FROM user_permissions
        WHERE user_id = ? AND branch_id = ?
    ");
    $stmt->execute([$userId, $branchId]);
    $perms = [];
    foreach ($stmt->fetchAll() as $row) {
        $perms[$row['module_key']] = $row;
    }
    $_SESSION['permissions'] = $perms;
}

/**
 * هل للمستخدم إجراء معين على قسم معين؟
 * can('sales.invoices', 'view')
 */
function can(string $moduleKey, string $action = 'view'): bool {
    if (isAdmin()) return true;

    $perms = $_SESSION['permissions'] ?? [];

    // wildcard للـ admin
    if ($perms === ['*']) return true;

    $row = $perms[$moduleKey] ?? null;
    if (!$row) return false;

    return (bool)($row['can_' . $action] ?? false);
}

/**
 * حماية مع إيقاف التنفيذ إذا ما عنده صلاحية
 */
function requirePermission(string $moduleKey, string $action = 'view'): void {
    checkLogin();
    if (!can($moduleKey, $action)) {
        http_response_code(403);
        die('<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="UTF-8">
        <title>غير مصرح</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
        </head><body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh">
        <div class="card shadow text-center p-5" style="max-width:400px">
            <div class="display-1 text-danger mb-3"><i class="bi bi-shield-x"></i></div>
            <h5>غير مصرح</h5>
            <p class="text-muted small">ليس لديك صلاحية للوصول إلى هذه الصفحة.</p>
            <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm">العودة</a>
        </div>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
        </body></html>');
    }
}

/**
 * بناء قائمة الـ sidebar بناءً على صلاحيات المستخدم
 */

/**
 * هل للمستخدم أي صلاحية على قسم معين؟ (view OR create OR ...)
 */
function hasAnyPermission(string $moduleKey): bool {
    return can($moduleKey, 'view')
        || can($moduleKey, 'create')
        || can($moduleKey, 'edit');
}
function buildSidebarMenu(PDO $pdo): array {
    loadUserPermissions($pdo);

    $allModules = $pdo->query("
        SELECT * FROM modules WHERE is_active = 1 ORDER BY sort_order
    ")->fetchAll();

    // فصل الأقسام الأب عن الأبناء
    $parents  = [];
    $children = [];
    foreach ($allModules as $m) {
        if ($m['parent_key'] === null) {
            $parents[$m['key']] = array_merge($m, ['children' => []]);
        } else {
            $children[] = $m;
        }
    }

    // تصفية حسب الصلاحيات
    foreach ($children as $child) {
        // نُظهر الابن فقط إذا كان للمستخدم view على هذا القسم
        if (!can($child['key'], 'view')) continue;
        $pk = $child['parent_key'];
        if (isset($parents[$pk])) {
            $parents[$pk]['children'][] = $child;
        }
    }

    // إخفاء الأقسام الأب التي ليس لها أبناء مرئية
    return array_filter($parents, fn($p) => !empty($p['children']));
}
