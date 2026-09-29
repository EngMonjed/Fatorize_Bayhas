<?php
/**
 * includes/tab_bar.php — شريط تبويبات القسم (عرض ثانٍ للشريط الجانبي)
 *
 * الفكرة: مافي قائمة تبويبات خاصة بالمكوّن ولا بأي صفحة. بيقرأ $currentModule
 * ويلاقي القسم الأب بـ$menu (ناتج buildSidebarMenu — نفس مصدر الشريط الجانبي)
 * ويرسم أبناء ذاك القسم كتبويبات. يعني: تسجيل صفحة جديدة بجدول modules وبخريطة
 * moduleUrl() (لازم أصلاً للشريط الجانبي) = ظهورها بالشريط الجانبي وبكل تبويبات
 * القسم تلقائياً، بدون تعديل أي صفحة.
 *
 * الاستخدام (بكل صفحة، مكان شريط التبويبات القديم):
 *     <?php require __DIR__ . '/../../../includes/tab_bar.php'; ?>
 * الشروط: $currentModule معرَّف، و includes/sidebar.php مضمَّن قبله بالصفحة
 * (بيعرّف $menu ودالة moduleUrl()). لو أي شرط ناقص → ما بيرسم شي وبيترك تعليق
 * HTML للتشخيص (فشل آمن، ما بيكسر الصفحة).
 *
 * - الأسماء: modules.tab_label (اختياري، مختصر) وإلا modules.label (الشريط الجانبي).
 *   كلاهما جاهز بـ$menu (buildSidebarMenu = SELECT * FROM modules)، فمافي استعلام
 *   إضافي. لو عمود tab_label لسا مو موجود → المفتاح غايب فبيرجع لـlabel تلقائياً.
 * - الصلاحيات والترتيب: بيتبعوا الشريط الجانبي بالضبط (نفس $menu).
 * - اللون: var(--section-color) من modules.theme_color (يعرّفه sidebar.php).
 *
 * ⚠ السحب والإفلات (ترتيب مخصَّص لكل مستخدم) — معطَّل بعلَم واحد ومش مجرَّب بعد:
 *     define('TAB_BAR_DND_ENABLED', true);   // قبل الـrequire، أو غيّر الافتراضي تحت
 *   لتفعيله فعلياً لازم كمان تنشئ api/save_tab_order.php: يستقبل POST
 *   (group = مفتاح القسم، order = JSON بمفاتيح الوحدات)، يتحقق إن المفاتيح من
 *   أبناء القسم، ويكتب/يحدّث جدول user_tab_order (user_id, page_group, tab_order).
 *   الترتيب المحفوظ القديم (أسماء ملفات مثل treasury.php) مدعوم بالقراءة.
 */

if (!defined('TAB_BAR_DND_ENABLED')) {
    define('TAB_BAR_DND_ENABLED', false);
}
if (!defined('TAB_BAR_SAVE_URL')) {
    define('TAB_BAR_SAVE_URL', (defined('BASE_PATH') ? BASE_PATH : '') . '/api/save_tab_order.php');
}

if (!function_exists('renderSectionTabBar')) {
    function renderSectionTabBar(array $menu, string $currentModule, ?PDO $pdo): void
    {
        static $cssPrinted = false;
        $e = function ($s): string {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        };

        // ١) القسم الحالي: القسم يلي أحد أبنائه = $currentModule
        $section = null;
        foreach ($menu as $sec) {
            foreach (($sec['children'] ?? []) as $ch) {
                if (($ch['key'] ?? null) === $currentModule) {
                    $section = $sec;
                    break 2;
                }
            }
        }
        if (!$section) {
            echo '<!-- tab_bar: ما انلقى قسم للوحدة "' . $e($currentModule)
                . '" بالقائمة الجانبية — تأكد من $currentModule وتسجيلها بجدول modules وبصلاحية العرض -->';
            return;
        }
        $children = $section['children'];

        // ٢) الأسماء: tab_label (مختصر، اختياري) وإلا label — الاتنين جاهزين بكل ابن بـ$menu
        //    لأن buildSidebarMenu بتعمل SELECT * FROM modules (مافي استعلام إضافي).

        // ٣) ترتيب مخصَّص لكل مستخدم — بس لو علَم السحب والإفلات مفعَّل
        if (TAB_BAR_DND_ENABLED && $pdo && !empty($_SESSION['user_id'])) {
            try {
                $st = $pdo->prepare("SELECT tab_order FROM user_tab_order WHERE user_id = ? AND page_group = ?");
                if ($st && $st->execute([$_SESSION['user_id'], $section['key']])) {
                    $saved = json_decode((string) $st->fetchColumn(), true);
                    if (is_array($saved) && $saved) {
                        $pos = [];
                        foreach ($saved as $i => $id) {
                            $pos[(string) $id] = $i;
                        }
                        $ranked = [];
                        foreach ($children as $i => $ch) {
                            $file = basename(moduleUrl($ch['key']));
                            $rank = $pos[$ch['key']] ?? ($pos[$file] ?? PHP_INT_MAX);
                            $ranked[] = [$rank, $i, $ch];
                        }
                        usort($ranked, function ($a, $b) {
                            return [$a[0], $a[1]] <=> [$b[0], $b[1]];
                        });
                        $children = array_map(function ($x) {
                            return $x[2];
                        }, $ranked);
                    }
                }
            } catch (Throwable $ex) {
                // جدول user_tab_order مو موجود — ترتيب الشريط الجانبي الافتراضي
            }
        }

        // ٤) التنسيق (مرة وحدة بالصفحة)
        if (!$cssPrinted) {
            $cssPrinted = true;
            echo <<<'CSS'
<style>
    .sec-tabs { border-bottom: 2px solid #e2e8f0; flex-wrap: wrap; }
    .sec-tabs .nav-link { border: none; color: #64748b; font-size: .83rem; font-weight: 600; white-space: nowrap; }
    .sec-tabs .nav-link:hover { color: var(--section-color, #1e3a8a); }
    .sec-tabs .nav-link.active { border: none; border-bottom: 2px solid var(--section-color, #1e3a8a); margin-bottom: -2px; color: var(--section-color, #1e3a8a); background: transparent; }
    .sec-tabs .nav-item.dragging { opacity: .4; }
</style>

CSS;
        }

        // ٥) التبويبات
        echo '<ul class="nav nav-tabs sec-tabs mb-3" id="sectionTabs" data-group="' . $e($section['key']) . '">' . "\n";
        foreach ($children as $ch) {
            $key = $ch['key'];
            $active = ($key === $currentModule);
            $label = !empty($ch['tab_label']) ? $ch['tab_label'] : ($ch['label'] ?? $key);
            $icon = !empty($ch['icon']) ? $ch['icon'] : 'bi-circle';
            echo '    <li class="nav-item" data-key="' . $e($key) . '"' . (TAB_BAR_DND_ENABLED ? ' draggable="true"' : '') . '>'
                . '<a class="nav-link' . ($active ? ' active' : '') . '" href="' . $e(moduleUrl($key)) . '"'
                . ($active ? ' aria-current="page"' : '') . '>'
                . '<i class="bi ' . $e($icon) . ' me-1"></i>' . $e($label) . '</a></li>' . "\n";
        }
        echo "</ul>\n";

        // ٦) السحب والإفلات (معطَّل افتراضياً — راجع التعليق بأول الملف)
        if (TAB_BAR_DND_ENABLED) {
            echo '<script>const TAB_BAR_SAVE_URL = ' . json_encode(TAB_BAR_SAVE_URL) . ";\n";
            echo <<<'JS'
(function () {
    var ul = document.getElementById('sectionTabs');
    if (!ul) return;
    var drag = null;
    ul.addEventListener('dragstart', function (e) {
        drag = e.target.closest('li');
        if (drag) { drag.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; }
    });
    ul.addEventListener('dragover', function (e) {
        e.preventDefault();
        var over = e.target.closest('li');
        if (!drag || !over || over === drag) return;
        var r = over.getBoundingClientRect();
        var after = (e.clientX - r.left) < r.width / 2; // RTL: النصف الأيسر = لاحقاً
        ul.insertBefore(drag, after ? over.nextSibling : over);
    });
    ul.addEventListener('dragend', function () {
        if (!drag) return;
        drag.classList.remove('dragging');
        drag = null;
        var order = Array.prototype.map.call(ul.querySelectorAll('li[data-key]'), function (li) { return li.dataset.key; });
        fetch(TAB_BAR_SAVE_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'group=' + encodeURIComponent(ul.dataset.group) + '&order=' + encodeURIComponent(JSON.stringify(order))
        }).catch(function () {});
    });
})();
JS;
            echo "\n</script>\n";
        }
    }
}

renderSectionTabBar(
    (isset($menu) && is_array($menu)) ? $menu : [],
    isset($currentModule) ? (string) $currentModule : '',
    (isset($pdo) && $pdo instanceof PDO) ? $pdo : null
);
