<?php
session_start();

// تسجيل وقت الخروج قبل مسح الجلسة
if (!empty($_SESSION['user_id'])) {
    try {
        require_once 'config/database.php';
        getMainConnection()
            ->prepare("INSERT INTO user_activities (user_id, activity_type, type, description, created_at) VALUES (?, 'logout', 'auth', 'تسجيل خروج', NOW())")
            ->execute([$_SESSION['user_id']]);
    } catch (Throwable $e) {}
}

session_unset();
session_destroy();

// منع العودة بالـ Back button
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Location: login.php');
exit;
