<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require_once '../../../config/database.php';

$pdo = getConnection();

// جرب استعلام بسيط
try {
    $stmt = $pdo->query("SELECT 1 as test");
    $result = $stmt->fetch();
    echo "✅ اتصال قاعدة البيانات يعمل.<br>";
} catch (PDOException $e) {
    echo "❌ فشل الاتصال: " . $e->getMessage();
}

// عرض محتويات الجلسة الحالية
echo "<pre>";
echo "الجلسة الحالية:\n";
print_r($_SESSION);
echo "</pre>";
?>