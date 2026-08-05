<?php
/**
 * reset_password.php
 * ارفع هذا الملف على السيرفر مرة واحدة فقط لإعادة تعيين كلمة المرور
 * ثم احذفه فوراً بعد الانتهاء
 */
session_start();
require_once 'config/database.php';

$message = '';
$done    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPass  = $_POST['new_password']  ?? '';
    $username = $_POST['username']      ?? 'admin';
    $secret   = $_POST['secret']        ?? '';

    // مفتاح أمان بسيط — غيّره قبل الرفع
    if ($secret !== 'fatorize2024reset') {
        $message = '❌ المفتاح السري غير صحيح';
    } elseif (strlen($newPass) < 6) {
        $message = '❌ كلمة المرور قصيرة جداً (6 أحرف على الأقل)';
    } else {
        $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 10]);
        $pdo  = getConnection();

        // تحديث أو إنشاء المستخدم
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $exists = $stmt->fetchColumn();

        if ($exists) {
            $pdo->prepare("UPDATE users SET password = ?, is_active = 1 WHERE username = ?")
                ->execute([$hash, $username]);
            $message = "✅ تم تحديث كلمة مرور المستخدم: <strong>$username</strong>";
        } else {
            $pdo->prepare("INSERT INTO users (username, full_name, email, password, role, is_active) VALUES (?,?,?,?,?,1)")
                ->execute([$username, 'مدير النظام', 'admin@fatorize.com', $hash, 'admin']);
            $userId = $pdo->lastInsertId();
            // ربط بكل الفروع
            $branches = $pdo->query("SELECT id FROM branches")->fetchAll();
            foreach ($branches as $b) {
                try {
                    $pdo->prepare("INSERT IGNORE INTO user_branches (user_id, branch_id) VALUES (?,?)")
                        ->execute([$userId, $b['id']]);
                } catch (Throwable $e) {}
            }
            $message = "✅ تم إنشاء المستخدم: <strong>$username</strong>";
        }

        $done = true;
        $message .= "<br><small style='color:#888'>الـ Hash: <code>" . htmlspecialchars(substr($hash,0,20)) . "...</code></small>";
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>إعادة تعيين كلمة المرور</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh">
<div class="card shadow" style="width:420px">
    <div class="card-header bg-danger text-white fw-bold">
        🔑 إعادة تعيين كلمة المرور
    </div>
    <div class="card-body">
        <?php if ($message): ?>
        <div class="alert <?= $done ? 'alert-success' : 'alert-danger' ?>">
            <?= $message ?>
        </div>
        <?php endif; ?>
        <?php if (!$done): ?>
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">اسم المستخدم</label>
                <input type="text" name="username" class="form-control" value="admin">
            </div>
            <div class="mb-3">
                <label class="form-label">كلمة المرور الجديدة</label>
                <input type="password" name="new_password" class="form-control" placeholder="6 أحرف على الأقل">
            </div>
            <div class="mb-3">
                <label class="form-label">المفتاح السري</label>
                <input type="text" name="secret" class="form-control" placeholder="fatorize2024reset">
                <div class="form-text text-muted">المفتاح الافتراضي: fatorize2024reset</div>
            </div>
            <button type="submit" class="btn btn-danger w-100">تحديث كلمة المرور</button>
        </form>
        <?php else: ?>
        <a href="login.php" class="btn btn-primary w-100">← الذهاب لصفحة تسجيل الدخول</a>
        <div class="alert alert-warning mt-3 small">
            ⚠️ <strong>احذف هذا الملف فوراً من السيرفر!</strong>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
