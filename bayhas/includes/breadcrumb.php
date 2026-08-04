<?php
/**
 * includes/breadcrumb.php
 * Breadcrumb عام لأي صفحة موديول قياسية (قسم واحد ثابت + صفحة واحدة).
 *
 * الاستخدام: يُضمَّن بعد sidebar.php مباشرة (بنفس نطاق المتغيرات —
 * $menu و$currentModule موجودين أصلاً من sidebar.php، ما بيحتاج تمرير
 * أي بيانات يدوياً لكل صفحة):
 *
 *   require_once __DIR__ . '/../../includes/sidebar.php';
 *   require_once __DIR__ . '/../../includes/breadcrumb.php';
 *
 * ثم بالـ<header class="topbar"> بس استدعي:
 *   <?= renderBreadcrumb() ?>
 *
 * ⚠ استثناء مقصود: الصفحات "مزدوجة الغرض" (warehouse.php, movements.php)
 * ما بتستخدم هالملف — عندها breadcrumb مخصص مبني يدوياً لأنه القسم
 * نفسه بيتغيّر ديناميكياً حسب ?type=/؟tab= بنفس الصفحة (مو قسم ثابت
 * واحد). راجعهم كمرجع لو احتجت نفس النمط لصفحة مزدوجة الغرض تانية.
 */

/**
 * يدوّر داخل $menu (المبني أصلاً بـsidebar.php) عن القسم والصفحة
 * المطابقين لـ$currentModule الحالي، ويرجّع HTML الـbreadcrumb جاهز.
 */
function renderBreadcrumb(): string
{
    global $menu, $currentModule;

    if (empty($menu) || empty($currentModule)) {
        return '';
    }

    $sectionLabel = null;
    $pageLabel = null;

    foreach ($menu as $section) {
        foreach ($section['children'] ?? [] as $child) {
            if (($child['key'] ?? null) === $currentModule) {
                $sectionLabel = $section['label'] ?? null;
                $pageLabel = $child['label'] ?? null;
                break 2;
            }
        }
    }

    // لم يُلقَ تطابق (مثلاً صفحة غير مربوطة بعد بجدول modules) — ما نعرض شي
    // بدل ما نكسر التصميم بـbreadcrumb فاضي أو غلط
    if ($sectionLabel === null) {
        return '';
    }

    return '<nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.8rem;color:#94a3b8">'
        . '<span>' . htmlspecialchars($sectionLabel) . '</span>'
        . '<i class="bi bi-chevron-left mx-1" style="font-size:.7rem"></i>'
        . '<span class="text-primary">' . htmlspecialchars($pageLabel) . '</span>'
        . '</nav>';
}
