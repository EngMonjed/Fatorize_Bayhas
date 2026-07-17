<?php
/**
 * super_admin/add_tenant.php
 * أداة داخلية (لصاحب منصة فاتورايز فقط، مش لأي عميل/tenant) لتسجيل
 * شركة جديدة بالسجل المركزي بعد ما تكون أنشأت قاعدة بياناتها يدوياً
 * وعبّيتها بالسكيما (u987540206_bayhas.sql) عبر hPanel/phpMyAdmin.
 *
 * ⚠ هذا الملف حسّاس جداً — أي شخص يوصله فيه يقدر يسجّل/يشوف بيانات
 * اتصال قواعد بيانات كل عملائك. الحماية الحالية (كلمة مرور واحدة
 * ثابتة) هي حد أدنى مؤقت فقط. يُنصح بشدة أيضاً بحصر الوصول لهذا
 * المجلد بعنوان IP معيّن (عبر .htaccess) بالإضافة لكلمة المرور.
 */

session_start();
// نطلب tenant_resolver.php وليس master_database.php مباشرة، لأن
// PLATFORM_BASE_DOMAIN معرّف هناك (وهو يطلب master_database.php داخلياً
// أصلاً، فنحصل على getMasterConnection() كمان). آمن: هذا الملف يُعرّف
// دوال/ثوابت فقط عند التضمين، ولا يُشغّل resolveCurrentTenant() تلقائياً.
require_once __DIR__ . '/../config/tenant_resolver.php';

// ─────────────────────────────────────────────────────────────
// كلمة مرور دخول بسيطة لهذه الأداة فقط — غيّرها فوراً قبل الاستخدام
// لتوليد hash جديد: php -r "echo password_hash('كلمة_مرورك', PASSWORD_DEFAULT);"
// ─────────────────────────────────────────────────────────────
if (!defined('SUPER_ADMIN_PASSWORD_HASH')) {
    define('SUPER_ADMIN_PASSWORD_HASH', '$2y$10$jXIq1kU3E.1kTRGVXnlQIeQZVbAaRYuswv40fCA2lA7A0w614izqG');
}

$loggedIn = !empty($_SESSION['super_admin_ok']);

if (isset($_POST['_login'])) {
    if (password_verify((string)($_POST['admin_password'] ?? ''), SUPER_ADMIN_PASSWORD_HASH)) {
        $_SESSION['super_admin_ok'] = true;
        $loggedIn = true;
    } else {
        $loginError = 'كلمة مرور خاطئة';
    }
}

if (!$loggedIn) {
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
    <meta charset="UTF-8">
    <title>دخول لوحة إدارة المنصة — فاتورايز</title>
    <link href="https://fonts.googleapis.com/css2?family=Changa:wght@600;700&family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/marketing.css" rel="stylesheet">
    <style>
        body { min-height:100vh; display:flex; align-items:center; justify-content:center; }
        .login-box { width:100%; max-width:400px; }
        .login-box .eyebrow { background:var(--navy); color:#fff; border-color:var(--navy); }
        .login-box h5 { font-family:'Changa'; font-size:1.3rem; margin:.6rem 0 1.5rem; }
        .form-control { width:100%; border:1.5px solid var(--border); border-radius:10px; padding:.75rem 1rem; font-family:'Cairo'; margin-bottom:1rem; }
        .btn-go { width:100%; padding:.8rem; }
    </style>
    </head>
    <body>
        <div class="tag-card login-box">
            <span class="eyebrow"><i class="bi bi-shield-lock"></i> لوحة المطوّر الداخلية</span>
            <h5>دخول إدارة المنصة</h5>
            <?php if (!empty($loginError)): ?>
                <div class="alert alert-danger py-2 small" style="border-radius:10px"><?= htmlspecialchars($loginError) ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="_login" value="1">
                <input type="password" name="admin_password" class="form-control" placeholder="كلمة المرور" required autofocus>
                <button class="btn-gold btn-go" style="border:none;justify-content:center;width:100%">دخول</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$pdo = getMasterConnection();
$message = '';
$messageType = 'success';

// ─────────────────────────────────────────────────────────────
// معالجة إضافة عميل جديد
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'add_tenant') {
    try {
        $companyName = trim($_POST['company_name'] ?? '');
        $subdomain   = strtolower(trim($_POST['subdomain'] ?? ''));
        $tenantType  = $_POST['tenant_type'] ?? 'shop';
        $dbHost      = trim($_POST['db_host'] ?? 'localhost');
        $dbName      = trim($_POST['db_name'] ?? '');
        $dbUser      = trim($_POST['db_user'] ?? '');
        // ⚠ إصلاح: كلمة مرور قاعدة البيانات يجوز تكون فاضية فعلاً وبشكل
        // صحيح (شائع جداً بالتطوير المحلي — root بدون كلمة سر). سابقاً
        // كان حقل db_pass عليه `required` بالـHTML، فكان يمنع ترك الحقل
        // فاضياً حتى لو هذه هي القيمة الصحيحة فعلاً — أُزيل هذا القيد.
        $dbPass      = (string)($_POST['db_pass'] ?? '');
        $plan        = trim($_POST['plan'] ?? 'basic');
        $status      = $_POST['status'] ?? 'trial';

        if ($companyName === '' || $subdomain === '' || $dbName === '' || $dbUser === '') {
            throw new Exception('يرجى تعبئة كل الحقول المطلوبة (كلمة مرور القاعدة يمكن أن تبقى فاضية)');
        }
        if (!preg_match('/^[a-z0-9-]{2,63}$/', $subdomain)) {
            throw new Exception('الساب دومين يجب أن يحتوي أحرف إنجليزية صغيرة/أرقام/شرطة فقط');
        }

        // تأكد عدم تكرار الساب دومين
        $chk = $pdo->prepare('SELECT COUNT(*) FROM tenants WHERE subdomain = ?');
        $chk->execute([$subdomain]);
        if ((int)$chk->fetchColumn() > 0) {
            throw new Exception("الساب دومين \"{$subdomain}\" مستخدم مسبقاً");
        }

        // تحقق فعلي: هل بيانات الاتصال هاي شغالة ووصلت فعلاً لقاعدة العميل؟
        try {
            $testDsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
            $testPdo = new PDO($testDsn, $dbUser, $dbPass, [PDO::ATTR_TIMEOUT => 5]);
            $tableCount = (int)$testPdo->query("SHOW TABLES")->rowCount();
            if ($tableCount < 10) {
                throw new Exception("الاتصال نجح لكن القاعدة فيها {$tableCount} جدول فقط — تأكد إنك استوردت السكيما الكاملة (u987540206_bayhas.sql) فيها قبل ما تضيفها هون");
            }
        } catch (PDOException $e) {
            throw new Exception('تعذّر الاتصال بقاعدة بيانات العميل بهاي البيانات — تأكد من إنشائها واستيراد السكيما فيها أولاً. تفاصيل: ' . $e->getMessage());
        }

        $dbPassEnc = encryptSecret($dbPass);

        $ins = $pdo->prepare("INSERT INTO tenants
            (company_name, subdomain, tenant_type, db_host, db_name, db_user, db_pass_enc, status, plan)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([$companyName, $subdomain, $tenantType, $dbHost, $dbName, $dbUser, $dbPassEnc, $status, $plan]);

        $message = "تمت إضافة الشركة \"{$companyName}\" بنجاح — بيوصلها المستخدمين عبر: {$subdomain}." . PLATFORM_BASE_DOMAIN;
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

$tenants = $pdo->query("SELECT id, company_name, subdomain, tenant_type, status, plan, created_at FROM tenants ORDER BY created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>إدارة عملاء المنصة — فاتورايز</title>
<link href="https://fonts.googleapis.com/css2?family=Changa:wght@600;700;800&family=Cairo:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="../assets/css/marketing.css" rel="stylesheet">
<style>
body { padding: 2rem 1rem; }
.admin-wrap { max-width: 960px; margin: 0 auto; }
.admin-card { background: #fff; border-radius: var(--radius); box-shadow: var(--shadow-soft); margin-bottom: 1.6rem; overflow: hidden; }
.admin-card .head { background: var(--navy); color: #fff; padding: 1.1rem 1.5rem; font-family: 'Changa'; font-weight: 700; font-size: 1.05rem; }
.admin-card .body { padding: 1.6rem; }
.form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; }
.form-grid .full { grid-column: 1 / -1; }
label.flabel { display:block; font-size:.85rem; font-weight:600; color:var(--navy); margin-bottom:.35rem; }
.form-control, .form-select {
    width:100%; border:1.5px solid var(--border); border-radius:10px; padding:.65rem 1rem;
    font-family:'Cairo'; font-size:.92rem;
}
.hint { font-size:.78rem; color:var(--muted); margin-top:.3rem; }
.divider { grid-column:1/-1; border-top:1.5px dashed var(--border); margin:.6rem 0; }
.alert { padding:.8rem 1.1rem; border-radius:10px; font-size:.9rem; margin-bottom:1.2rem; }
.alert-success { background:#ECFDF5; color:#047857; }
.alert-danger { background:#FEF2F2; color:#B91C1C; }
table { width:100%; border-collapse:collapse; }
table th { text-align:right; font-size:.78rem; color:var(--muted); padding:.7rem 1.5rem; border-bottom:1.5px solid var(--border); }
table td { padding:.85rem 1.5rem; font-size:.88rem; border-bottom:1px solid #F1F4F9; }
.badge-status { padding:.25rem .75rem; border-radius:100px; font-size:.75rem; font-weight:700; }
.badge-active { background:#ECFDF5; color:#047857; }
.badge-trial { background:#FEF3C7; color:#B45309; }
.badge-other { background:#F1F5F9; color:#64748B; }
</style>
</head>
<body>
<div class="admin-wrap">
    <span class="eyebrow"><i class="bi bi-shield-lock"></i> لوحة المطوّر الداخلية — مو لأي tenant</span>
    <h1 style="margin:.5rem 0 1.6rem">إدارة عملاء منصة فاتورايز</h1>

    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="admin-card">
        <div class="head"><i class="bi bi-plus-circle me-1"></i> إضافة شركة (tenant) جديدة</div>
        <div class="body">
            <p class="hint" style="margin-bottom:1.2rem">
                ⚠ قبل تعبئة هذا النموذج: أنشئ قاعدة بيانات جديدة يدوياً، واستورد فيها ملف
                <code>u987540206_bayhas.sql</code> كاملاً. هذا النموذج فقط <strong>يسجّل</strong> شركة جاهزة، لا ينشئها.
            </p>
            <form method="POST" class="form-grid">
                <input type="hidden" name="_action" value="add_tenant">
                <div>
                    <label class="flabel">اسم الشركة</label>
                    <input type="text" name="company_name" class="form-control" required>
                </div>
                <div>
                    <label class="flabel">الساب دومين (أحرف إنجليزية صغيرة فقط)</label>
                    <input type="text" name="subdomain" class="form-control" required pattern="[a-z0-9-]+" placeholder="bayhas">
                </div>
                <div>
                    <label class="flabel">نوع النشاط</label>
                    <select name="tenant_type" class="form-select">
                        <option value="shop">محل/مبيعات</option>
                        <option value="factory">مصنع</option>
                        <option value="both">الاثنين (مصنع + مبيعات)</option>
                    </select>
                </div>
                <div>
                    <label class="flabel">الخطة</label>
                    <select name="plan" class="form-select">
                        <option value="basic">Basic</option>
                        <option value="pro">Pro</option>
                        <option value="enterprise">Enterprise</option>
                    </select>
                </div>
                <div class="full">
                    <label class="flabel">الحالة</label>
                    <select name="status" class="form-select">
                        <option value="active">نشط</option>
                        <option value="trial">تجريبي</option>
                    </select>
                </div>

                <div class="divider"></div>

                <div>
                    <label class="flabel">DB Host</label>
                    <input type="text" name="db_host" class="form-control" value="localhost" required>
                </div>
                <div>
                    <label class="flabel">DB Name</label>
                    <input type="text" name="db_name" class="form-control" required placeholder="bayhas_local">
                </div>
                <div>
                    <label class="flabel">DB User</label>
                    <input type="text" name="db_user" class="form-control" required value="root">
                </div>
                <div>
                    <label class="flabel">DB Password</label>
                    <input type="password" name="db_pass" class="form-control" placeholder="اتركها فاضية إذا ما في كلمة سر">
                    <p class="hint">يمكن تُترك فاضية — شائع بالتطوير المحلي (root بدون كلمة سر بلارغون/XAMPP).</p>
                </div>

                <div class="full">
                    <button class="btn-gold" style="border:none">إضافة الشركة</button>
                </div>
            </form>
        </div>
    </div>

    <div class="admin-card">
        <div class="head"><i class="bi bi-building me-1"></i> الشركات الحالية (<?= count($tenants) ?>)</div>
        <table>
            <thead><tr><th>الاسم</th><th>الساب دومين</th><th>النوع</th><th>الحالة</th><th>الخطة</th><th>تاريخ الإضافة</th></tr></thead>
            <tbody>
            <?php foreach ($tenants as $t): ?>
                <tr>
                    <td><?= htmlspecialchars($t['company_name']) ?></td>
                    <td dir="ltr" class="mono"><?= htmlspecialchars($t['subdomain']) ?>.fatorize.com</td>
                    <td><?= htmlspecialchars($t['tenant_type']) ?></td>
                    <td><span class="badge-status badge-<?= $t['status']==='active'?'active':($t['status']==='trial'?'trial':'other') ?>"><?= htmlspecialchars($t['status']) ?></span></td>
                    <td><?= htmlspecialchars($t['plan']) ?></td>
                    <td class="mono" style="font-size:.78rem"><?= htmlspecialchars($t['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
