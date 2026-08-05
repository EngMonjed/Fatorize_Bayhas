<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
session_start();
require_once 'config/database.php';

$error = '';

// إذا مسجل دخول + اختار فرع — وجّهه
if (!empty($_SESSION['user_id']) && !empty($_SESSION['branch_id'])) {
    $pdo  = getMainConnection();
    $stmt = $pdo->prepare("SELECT dashboard_path FROM branches WHERE id = ?");
    $stmt->execute([$_SESSION['branch_id']]);
    $b = $stmt->fetch();
    if ($b) { header('Location: ' . $b['dashboard_path']); exit; }
}

// معالجة POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $pdo  = getMainConnection();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['full_name'] = $user['full_name'] ?? $user['name'];
            $_SESSION['role']      = $user['role'];

            // تسجيل نشاط
            try {
                $pdo->prepare("INSERT INTO user_activities (user_id, activity_type, type, description, created_at) VALUES (?, 'login', 'auth', 'تسجيل دخول', NOW())")
                    ->execute([$user['id']]);
            } catch (Throwable $e) {}

            header('Location: select_account.php');
            exit;
        } else {
            $error = 'اسم المستخدم أو كلمة المرور غير صحيحة';
        }
    } else {
        $error = 'يرجى إدخال اسم المستخدم وكلمة المرور';
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تسجيل الدخول — FATORIZE</title>
<link rel="icon" type="image/png" href="assets/images/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
    --brand: #1e3a8a;
    --brand-light: #3b82f6;
    --brand-hover: #1d4ed8;
}
* { box-sizing: border-box; }
body {
    min-height: 100vh;
    background: linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 100%);
    display: flex; align-items: center; justify-content: center;
    font-family: 'Cairo', sans-serif;
    padding: 1rem;
}
.login-card {
    width: 100%; max-width: 440px;
    background: rgba(255,255,255,.97);
    border-radius: 20px;
    padding: 2.5rem 2rem;
    box-shadow: 0 20px 60px rgba(30,58,138,.12);
    border: 1px solid rgba(255,255,255,.6);
    animation: slideUp .5s ease;
}
@keyframes slideUp {
    from { opacity:0; transform:translateY(24px); }
    to   { opacity:1; transform:translateY(0); }
}
.brand-logo { width: 90px; margin-bottom: 1rem; }
.brand-title {
    font-size: 1.1rem; font-weight: 700;
    color: var(--brand); line-height: 1.5;
}
.form-control {
    border-radius: 12px; border: 1.5px solid #e2e8f0;
    padding: .75rem 1rem; font-family: 'Cairo', sans-serif;
    font-size: .95rem; transition: all .25s;
}
.form-control:focus {
    border-color: var(--brand-light);
    box-shadow: 0 0 0 4px rgba(59,130,246,.12);
}
.input-icon-wrapper { position: relative; }
.input-icon-wrapper .bi {
    position: absolute; right: 14px; top: 50%;
    transform: translateY(-50%); color: #94a3b8; font-size: 1.1rem;
    pointer-events: none;
}
.input-icon-wrapper .toggle-pass {
    position: absolute; left: 14px; top: 50%;
    transform: translateY(-50%); color: #94a3b8;
    cursor: pointer; pointer-events: auto; background: none; border: none;
    font-size: 1.1rem; padding: 0; transition: color .2s;
}
.toggle-pass:hover { color: var(--brand-light); }
.input-icon-wrapper input { padding-left: 2.8rem; padding-right: 2.8rem; }
.btn-login {
    width: 100%; padding: .85rem;
    background: linear-gradient(135deg, var(--brand-light), var(--brand));
    border: none; border-radius: 12px;
    color: #fff; font-size: 1rem; font-weight: 600;
    font-family: 'Cairo', sans-serif; cursor: pointer;
    transition: all .25s; position: relative; overflow: hidden;
}
.btn-login:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(30,58,138,.3); }
.btn-login:active { transform: translateY(0); }
.btn-login.loading { pointer-events: none; opacity: .8; }
.alert-error {
    background: #fef2f2; border: 1px solid #fecaca;
    color: #dc2626; border-radius: 12px;
    padding: .75rem 1rem; font-size: .9rem;
    display: flex; align-items: center; gap: .5rem;
}
</style>
</head>
<body>
<div class="login-card">
    <div class="text-center mb-4">
        <img src="assets/images/fatorize.png" alt="Fatorize" class="brand-logo"
             onerror="this.style.display='none'">
        <div class="brand-title">نظام فاتورايز الشامل<br>للإدارة المالية والمحاسبية</div>
    </div>

    <?php if ($error): ?>
    <div class="alert-error mb-3">
        <i class="bi bi-exclamation-circle-fill"></i>
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="loginForm" autocomplete="off" novalidate>
        <div class="mb-3">
            <label class="form-label fw-600 small text-secondary mb-1">اسم المستخدم</label>
            <div class="input-icon-wrapper">
                <input type="text" name="username" class="form-control"
                       placeholder="أدخل اسم المستخدم" required
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                <i class="bi bi-person"></i>
            </div>
        </div>
        <div class="mb-4">
            <label class="form-label fw-600 small text-secondary mb-1">كلمة المرور</label>
            <div class="input-icon-wrapper">
                <input type="password" name="password" id="passInput"
                       class="form-control" placeholder="أدخل كلمة المرور" required>
                <i class="bi bi-lock"></i>
                <button type="button" class="toggle-pass" id="togglePass" tabindex="-1">
                    <i class="bi bi-eye-slash" id="passIcon"></i>
                </button>
            </div>
        </div>
        <button type="submit" class="btn-login" id="loginBtn">
            <i class="bi bi-box-arrow-in-right me-2"></i>تسجيل الدخول
        </button>
    </form>
</div>

<script>
const passInput  = document.getElementById('passInput');
const passIcon   = document.getElementById('passIcon');
const togglePass = document.getElementById('togglePass');
const loginBtn   = document.getElementById('loginBtn');
const loginForm  = document.getElementById('loginForm');

togglePass.addEventListener('click', () => {
    const isPass = passInput.type === 'password';
    passInput.type = isPass ? 'text' : 'password';
    passIcon.className = isPass ? 'bi bi-eye' : 'bi bi-eye-slash';
});

loginForm.addEventListener('submit', () => {
    loginBtn.classList.add('loading');
    loginBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري التحقق...';
});
</script>
</body>
</html>
