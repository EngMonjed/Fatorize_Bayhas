<?php
/**
 * config/tenant_resolver.php
 * يحدد "مين العميل" (tenant) بناءً على الساب دومين بالرابط الحالي،
 * ويرجّع بيانات الاتصال بقاعدة بياناته الخاصة.
 *
 * مثال: bayhas.fatorize.com → subdomain = "bayhas" → يبحث بجدول
 * tenants بالقاعدة المركزية → يرجّع db_host/db_name/db_user/db_pass
 * (بعد فك التشفير) الخاصة بشركة Bayhas تحديداً.
 */

require_once __DIR__ . '/master_database.php';

// اسم النطاق الأساسي لمنصّتك — غيّره لدومينك الفعلي
if (!defined('PLATFORM_BASE_DOMAIN')) define('PLATFORM_BASE_DOMAIN', 'fatorize.com');

// أثناء التطوير المحلي (localhost)، يمكن تحديد شركة افتراضية للاختبار
// عبر: config/local_tenant_override.php (اختياري، غير مرفوع لأي سيرفر إنتاج)
if (!defined('DEV_FALLBACK_SUBDOMAIN')) define('DEV_FALLBACK_SUBDOMAIN', '');

/**
 * يستخرج الساب دومين من اسم المضيف الحالي (HTTP_HOST).
 * يرجّع null إذا كان الطلب على النطاق الأساسي نفسه بدون ساب دومين
 * (مثال: fatorize.com أو www.fatorize.com — صفحة تسويقية عامة، مش عميل).
 */
function extractSubdomainFromHost(string $host): ?string
{
    $host = strtolower(trim($host));
    $host = preg_replace('/:\d+$/', '', $host); // إزالة رقم البورت إن وجد

    if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
        return DEV_FALLBACK_SUBDOMAIN !== '' ? DEV_FALLBACK_SUBDOMAIN : null;
    }

    $base = PLATFORM_BASE_DOMAIN;
    if ($host === $base || $host === 'www.' . $base) {
        return null; // الصفحة الرئيسية/التسويقية، مش عميل محدد
    }

    $suffix = '.' . $base;
    $isSubdomainOfBase = substr($host, -strlen($suffix)) === $suffix; // PHP 7.4-compatible (no str_ends_with)
    if ($isSubdomainOfBase) {
        $sub = substr($host, 0, -(strlen($base) + 1));
        // ساب دومينز متداخلة غير متوقعة (a.b.fatorize.com) — ناخذ أول جزء فقط
        $parts = explode('.', $sub);
        return $parts[0] !== '' ? $parts[0] : null;
    }

    return null;
}

/**
 * يعرض صفحة خطأ واضحة ومتوقفة (بدون كشف تفاصيل تقنية) لما يكون
 * الطلب على ساب دومين غير موجود أو حساب موقوف/ملغى.
 */
function rejectTenantRequest(string $title, string $message): void
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8">'
       . '<title>' . htmlspecialchars($title) . '</title>'
       . '<style>body{font-family:Tahoma,Arial,sans-serif;background:#f8fafc;display:flex;'
       . 'align-items:center;justify-content:center;height:100vh;margin:0}'
       . '.box{text-align:center;background:#fff;padding:40px 50px;border-radius:14px;'
       . 'box-shadow:0 2px 12px rgba(0,0,0,.08)}h1{color:#dc2626;font-size:1.3rem}'
       . 'p{color:#64748b;font-size:.9rem}</style></head><body>'
       . '<div class="box"><h1>' . htmlspecialchars($title) . '</h1>'
       . '<p>' . htmlspecialchars($message) . '</p></div></body></html>';
    exit;
}

/**
 * الدالة الرئيسية: تحدد الشركة الحالية وترجّع بيانات الاتصال بقاعدتها.
 * تُستدعى من config/database.php — لا داعي لاستدعائها يدوياً من صفحات
 * الوحدات (modules) نفسها.
 *
 * @return array{id:int, company_name:string, subdomain:string, tenant_type:string,
 *               db_host:string, db_name:string, db_user:string, db_pass:string, status:string}
 */
function resolveCurrentTenant(): array
{
    static $cached = null;
    if ($cached !== null) return $cached;

    $subdomain = extractSubdomainFromHost($_SERVER['HTTP_HOST'] ?? '');
    if ($subdomain === null || $subdomain === '') {
        rejectTenantRequest(
            'لم يتم تحديد الشركة',
            'الرابط الذي فتحته لا يخص شركة محددة. تأكد من الدخول عبر رابط شركتك الخاص، مثال: yourcompany.' . PLATFORM_BASE_DOMAIN
        );
    }

    $st = getMasterConnection()->prepare('SELECT * FROM tenants WHERE subdomain = ? LIMIT 1');
    $st->execute([$subdomain]);
    $tenant = $st->fetch();

    if (!$tenant) {
        rejectTenantRequest('الشركة غير موجودة', 'لا يوجد حساب مسجّل بهذا الرابط. تحقق من الرابط أو تواصل مع الدعم.');
    }
    if ($tenant['status'] === 'suspended') {
        rejectTenantRequest('الحساب موقوف مؤقتاً', 'تم إيقاف هذا الحساب. تواصل مع الدعم لمعرفة السبب وإعادة التفعيل.');
    }
    if ($tenant['status'] === 'cancelled') {
        rejectTenantRequest('الحساب غير نشط', 'تم إلغاء هذا الاشتراك. تواصل مع الدعم إذا كان هذا غير متوقع.');
    }

    $cached = [
        'id'           => (int)$tenant['id'],
        'company_name' => $tenant['company_name'],
        'subdomain'    => $tenant['subdomain'],
        'tenant_type'  => $tenant['tenant_type'],
        'db_host'      => $tenant['db_host'],
        'db_name'      => $tenant['db_name'],
        'db_user'      => $tenant['db_user'],
        'db_pass'      => decryptSecret($tenant['db_pass_enc']),
        'status'       => $tenant['status'],
    ];

    return $cached;
}
