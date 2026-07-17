<?php
/**
 * find-my-company.php — يسأل الزائر عن اسم/رابط شركته، ويوجّهه لصفحة
 * تسجيل الدخول الخاصة فيها (بالساب دومين الصحيح).
 * المسار: _bayhas/find-my-company.php (جذر المشروع)
 *
 * ⚠ عمداً يتصل بـ config/master_database.php (عبر tenant_resolver.php)
 * مباشرة، وليس config/database.php (اتصال شركة محددة) — هذه الصفحة
 * سابقة لمعرفة أي شركة أصلاً.
 */

session_start();
require_once __DIR__ . '/config/tenant_resolver.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subdomain = strtolower(trim($_POST['subdomain'] ?? ''));

    if ($subdomain === '') {
        $error = 'اكتب اسم/رابط شركتك';
    } else {
        $subdomain = preg_replace('/\..*/', '', $subdomain);
        $subdomain = preg_replace('/[^a-z0-9-]/', '', $subdomain);

        try {
            $pdo = getMasterConnection();
            $st = $pdo->prepare('SELECT subdomain, status FROM tenants WHERE subdomain = ? LIMIT 1');
            $st->execute([$subdomain]);
            $tenant = $st->fetch();

            if (!$tenant) {
                $error = 'ما لقينا شركة بهذا الاسم. تأكد من الاسم أو تواصل مع الدعم.';
            } elseif ($tenant['status'] === 'suspended') {
                $error = 'حساب هذه الشركة موقوف مؤقتاً. تواصل مع الدعم.';
            } elseif ($tenant['status'] === 'cancelled') {
                $error = 'حساب هذه الشركة غير نشط حالياً.';
            } else {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $target = $scheme . '://' . $subdomain . '.' . PLATFORM_BASE_DOMAIN . '/login.php';
                header('Location: ' . $target);
                exit;
            }
        } catch (Throwable $e) {
            error_log('find-my-company error: ' . $e->getMessage());
            $error = 'حدث خطأ تقني، حاول لاحقاً.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>الدخول لحسابك — فاتورايز</title>
<link rel="icon" type="image/png" href="assets/images/logo.png">
<link href="https://fonts.googleapis.com/css2?family=Changa:wght@600;700&family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/css/marketing.css" rel="stylesheet">
<style>
body {
    min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem;
    background: radial-gradient(circle at 30% 20%, #E8EEF7 0%, var(--chambray) 60%);
}
.login-wrap { width: 100%; max-width: 440px; }
.login-wrap .tag-card { width: 100%; padding: 2.4rem 2.2rem; }
.login-wrap h1 { font-size: 1.5rem; margin: .6rem 0 .4rem; }
.login-wrap p.sub { color: var(--muted); font-size: .88rem; margin-bottom: 1.8rem; line-height: 1.7; }
.field-group { margin-bottom: 1.2rem; }
.field-group label { display: block; font-size: .85rem; font-weight: 600; color: var(--navy); margin-bottom: .4rem; }
.field-wrap { display: flex; }
.field-wrap input {
    flex: 1; border: 1.5px solid var(--navy); border-left: none; border-radius: 10px 0 0 10px;
    padding: .8rem 1rem; font-family: 'Cairo'; font-size: .95rem; background: #fff;
}
.field-wrap input:focus { outline: none; }
.field-suffix {
    background: var(--navy); color: #fff; display: flex; align-items: center; padding: 0 1rem;
    border-radius: 0 10px 10px 0; font-family: 'IBM Plex Mono', monospace; font-size: .78rem;
}
.btn-submit { width: 100%; border: none; justify-content: center; margin-top: .3rem; }
.back-link { display: inline-flex; align-items: center; gap: .3rem; margin-top: 1.5rem; color: var(--muted); font-size: .85rem; text-decoration: none; }
.back-link:hover { color: var(--navy); }
</style>
</head>
<body>
<div class="login-wrap">
    <div class="tag-card">
        <div class="tag-hole-string" aria-hidden="true"></div>
        <span class="eyebrow"><i class="bi bi-building"></i> الدخول لحسابك</span>
        <h1>وين شركتك؟</h1>
        <p class="sub">اكتب اسم/رابط شركتك بفاتورايز، وبنوصّلك لصفحة الدخول الخاصة فيها مباشرة.</p>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 small" style="border-radius:10px;margin-bottom:1.2rem">
                <i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="field-group">
                <label>اسم الشركة</label>
                <div class="field-wrap">
                    <input type="text" name="subdomain" placeholder="bayhas" required autofocus
                           value="<?= htmlspecialchars($_POST['subdomain'] ?? '') ?>">
                    <span class="field-suffix">.fatorize.com</span>
                </div>
            </div>
            <button type="submit" class="btn-gold btn-submit">متابعة <i class="bi bi-arrow-left ms-1"></i></button>
        </form>

        <a href="index.php" class="back-link"><i class="bi bi-arrow-right"></i> رجوع للصفحة الرئيسية</a>
    </div>
</div>
</body>
</html>
