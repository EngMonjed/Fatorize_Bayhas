<?php
/**
 * config/master_database.php
 * الاتصال بقاعدة البيانات المركزية (سجل الشركات المشتركة) + أدوات تشفير
 * كلمات مرور قواعد بيانات العملاء.
 *
 * ⚠ هذا الملف يخص القاعدة المركزية فقط (جدول tenants) — ليس أي قاعدة
 * بيانات عميل. راجع config/database.php للاتصال بقاعدة العميل نفسه.
 *
 * ⚠ أمان: MASTER_ENCRYPTION_KEY أدناه *مثال فقط*. يجب توليد مفتاح
 * خاص وفريد لمنصّتك وعدم مشاركته أو رفعه لأي مستودع كود عام. يُفضّل
 * لاحقاً نقله لمتغيّر بيئة (Environment Variable) بدل ثبات بالملف.
 * لتوليد مفتاح جديد عشوائي: php -r "echo bin2hex(random_bytes(32));"
 */

if (!defined('MASTER_DB_HOST')) define('MASTER_DB_HOST', 'localhost');
if (!defined('MASTER_DB_NAME')) define('MASTER_DB_NAME', 'CHANGE_ME_master');   // مثال: u987540206_master
if (!defined('MASTER_DB_USER')) define('MASTER_DB_USER', 'CHANGE_ME_master');
if (!defined('MASTER_DB_PASS')) define('MASTER_DB_PASS', 'CHANGE_ME_password');

// مفتاح تشفير 32 بايت (64 حرف hex) — غيّره فوراً بمفتاح خاص فريد
if (!defined('MASTER_ENCRYPTION_KEY')) {
    define('MASTER_ENCRYPTION_KEY', 'REPLACE_WITH_YOUR_OWN_64_HEX_CHAR_SECRET_KEY_0000000000000000');
}

/**
 * اتصال PDO بقاعدة البيانات المركزية (سجل الشركات) — singleton منفصل
 * تماماً عن اتصال قاعدة بيانات أي عميل.
 */
function getMasterConnection(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = 'mysql:host=' . MASTER_DB_HOST
         . ';dbname=' . MASTER_DB_NAME
         . ';charset=utf8mb4';

    $pdo = new PDO($dsn, MASTER_DB_USER, MASTER_DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

/**
 * تشفير نص (تُستخدم لتخزين كلمة مرور قاعدة بيانات كل عميل بشكل مشفّر
 * بدل نص صريح بجدول tenants).
 */
function encryptSecret(string $plain): string
{
    $key = hex2bin(MASTER_ENCRYPTION_KEY) ?: MASTER_ENCRYPTION_KEY;
    $iv  = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        throw new RuntimeException('تعذّر تشفير القيمة');
    }
    // نُخزّن IV + النص المشفّر معاً (base64) ليمكن فك التشفير لاحقاً
    return base64_encode($iv . $cipher);
}

/**
 * فك تشفير قيمة مُشفّرة بواسطة encryptSecret()
 */
function decryptSecret(string $encoded): string
{
    $key  = hex2bin(MASTER_ENCRYPTION_KEY) ?: MASTER_ENCRYPTION_KEY;
    $raw  = base64_decode($encoded);
    if ($raw === false || strlen($raw) < 17) {
        throw new RuntimeException('قيمة مشفّرة غير صالحة');
    }
    $iv     = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain  = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($plain === false) {
        throw new RuntimeException('تعذّر فك تشفير القيمة — تحقق من مفتاح التشفير');
    }
    return $plain;
}
