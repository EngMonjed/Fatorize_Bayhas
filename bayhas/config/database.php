<?php
/**
 * config/database.php
 * الاتصال بقاعدة بيانات "الشركة الحالية" (tenant) — يُحدَّد تلقائياً من
 * الساب دومين بالرابط (راجع config/tenant_resolver.php).
 *
 * ⚠ تغيير جوهري عن النسخة السابقة: هذا الملف لم يعد يتصل بقاعدة بيانات
 * ثابتة واحدة (u987540206fatorize_erp_system). كل شركة مشتركة بالنظام الآن لها
 * قاعدة بيانات منفصلة خاصة فيها، وهذا الملف يحدد أيّها يجب الاتصال
 * بها في كل طلب، بناءً على الساب دومين.
 *
 * كل باقي ملفات النظام (كل الوحدات) تستدعي getConnection() بدون أي
 * وسيط تماماً كما كانت تفعل سابقاً — لا حاجة لتعديلها.
 */

// ⚠ نقطة تحكم مركزية واحدة لمسار جذر التطبيق. أي رينيم مستقبلي للمجلد
// (أو النقل لبنية SaaS بساب-دومين حقيقي لاحقاً) بيصير هون بس، بدل ما
// تلاحق كل ملف فيه مسار مطلق مكتوب حرفياً.
//
// - النسخة المحلية (Laragon) بعد الرينيم: بتنفتح عبر
//   http://localhost/fatorize_erp_system/...
// - لو رح تحوّل النسخة المحلية لدومين وهمي من نوع bayhas.test (Laragon
//   Auto Virtual Hosts)، لازم تصير القيمة '' (فاضية) بدل المسار،
//   لأنه بهالحالة الدومين بيوصل مباشرة لجذر المشروع بدون مسار فرعي.
if (!defined('BASE_PATH'))
    define('BASE_PATH', '/fatorize_erp_system');
require_once __DIR__ . '/tenant_resolver.php';
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php-error.log');
date_default_timezone_set('Asia/Damascus');
/**
 * الاتصال بقاعدة بيانات الشركة الحالية — singleton لكل طلب (request)
 * واحد. يُحلّ الـtenant تلقائياً من الساب دومين أول مرة يُستدعى فيها.
 */
function getConnection(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO)
        return $pdo;

    $tenant = resolveCurrentTenant();

    $dsn = 'mysql:host=' . $tenant['db_host']
        . ';dbname=' . $tenant['db_name']
        . ';charset=utf8mb4';

    try {
        $pdo = new PDO($dsn, $tenant['db_user'], $tenant['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        error_log('[tenant:' . $tenant['subdomain'] . '] DB connection failed: ' . $e->getMessage());
        http_response_code(503);
        die('تعذّر الاتصال بقاعدة البيانات حالياً. حاول لاحقاً أو تواصل مع الدعم.');
    }

    return $pdo;
}

/**
 * معلومات الشركة الحالية (بدون بيانات الاتصال الحساسة) — مفيدة لعرض
 * اسم الشركة/نوعها بالواجهة (مثلاً لتحديد أي لوحة تحكم افتراضية تُعرض:
 * تصنيع أم مبيعات) دون الحاجة لاستعلام إضافي.
 */
function getCurrentTenantInfo(): array
{
    $t = resolveCurrentTenant();
    unset($t['db_host'], $t['db_name'], $t['db_user'], $t['db_pass']);
    return $t;
}

// aliases للتوافق مع الكود الحالي — لا تغيير على استخدامها بباقي الملفات
function getMainConnection(): PDO
{
    return getConnection();
}
function getAleppoConnection(): PDO
{
    return getConnection();
}
function getPdoByAccount(string $a = ''): PDO
{
    return getConnection();
}

function checkDatabaseConnection(): bool
{
    try {
        getConnection()->query('SELECT 1');
        return true;
    } catch (Throwable $e) {
        error_log($e->getMessage());
        return false;
    }
}