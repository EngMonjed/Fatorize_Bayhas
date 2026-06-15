<?php
/**
 * config/database.php
 * قاعدة بيانات موحدة — u987540206_bayhas
 * كل الفروع في نفس القاعدة بـ suffix مختلف
 */

if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'u987540206_bayhas');
if (!defined('DB_USER')) define('DB_USER', 'u987540206_bayhas');
if (!defined('DB_PASS')) define('DB_PASS', 'Bb1234%^&*(');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php-error.log');
date_default_timezone_set('Asia/Damascus');

/**
 * الاتصال الوحيد — singleton
 */
function getConnection(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = 'mysql:host=' . DB_HOST
         . ';dbname=' . DB_NAME
         . ';charset=utf8mb4';

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

// aliases للتوافق
function getMainConnection(): PDO      { return getConnection(); }
function getAleppoConnection(): PDO    { return getConnection(); }
function getPdoByAccount(string $a = ''): PDO { return getConnection(); }

function checkDatabaseConnection(): bool {
    try { getConnection()->query('SELECT 1'); return true; }
    catch (Throwable $e) { error_log($e->getMessage()); return false; }
}
