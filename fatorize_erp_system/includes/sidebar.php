<?php
/**
 * includes/sidebar.php
 * يُضمَّن في كل صفحة داخل الفرع
 * المتغيرات المطلوبة: $pdo, $currentModule (مثل 'sales.invoices')
 */

$menu = buildSidebarMenu($pdo);
injectInternalOrdersToMenu($menu);
$menu = reorderAndFilterSidebarMenu($menu, $_SESSION['table_suffix'] ?? '');
$user = getCurrentUser();
$branchName = $_SESSION['branch_name'] ?? 'الفرع';
$currentPage = $currentModule ?? '';
$sectionColor = getCurrentSectionColor($menu, $currentPage);
?>
<style>
    /* ✅ لون القسم الحالي — متغيّر CSS واحد، مصدره عمود modules.theme_color
   (راجع 15_add_section_theme_colors.sql). أي صفحة داخل هالقسم بتقدر
   تستخدم var(--section-color) بأي عنصر (أزرار، تبويبات، حدود...)
   بدل ما تكتب لون حرفي ثابت — هيك كل عناصر القسم بتاخد نفس اللون
   تلقائياً، وتغييره من صفحة الإعدادات بيطبّق على كل الصفحات فوراً. */
    :root {
        --section-color:
            <?= htmlspecialchars($sectionColor) ?>
        ;
    }
</style>
<style>
    /* تجميع بصري لأقسام القائمة حسب تكرار الاستخدام — مضاف محلياً هون
   لتفادي التعديل على layout.css المشترك بين كل الصفحات. */
    .sb-group-label {
        padding: 14px 16px 4px;
        font-size: 11px;
        color: rgba(255, 255, 255, .45);
        text-transform: none;
        letter-spacing: .02em;
    }

    .sb-nav>.sb-group-label:first-child {
        padding-top: 4px;
    }
</style>
<aside class="sidebar" id="sidebar">

    <!-- Brand -->
    <div class="sb-brand">
        <img src="<?= BASE_PATH ?>/assets/images/fatorize.png" alt="" onerror="this.style.display='none'">
        <div class="sb-brand-text">
            <div class="sb-name">FATORIZE</div>
            <div class="sb-branch"><?= htmlspecialchars($branchName) ?></div>
        </div>
    </div>

    <!-- Nav -->
    <nav class="sb-nav">
        <?php
        // عتبات التجميع البصري — مطابقة لترتيب $priority بدالة
        // reorderAndFilterSidebarMenu(): أول 4 = الأكثر استخداماً،
        // التالية 3 = دوري، الباقي = إداري.
        $groupLabels = [0 => 'الأكثر استخداماً', 4 => 'دوري', 7 => 'إداري'];
        foreach ($menu as $i => $section):
            if (isset($groupLabels[$i])):
                ?>
                <div class="sb-group-label"><?= htmlspecialchars($groupLabels[$i]) ?></div>
            <?php endif; ?>

            <div class="sb-group <?= isGroupActive($currentPage, $section) ? 'open' : '' ?>"
                data-key="<?= $section['key'] ?>">

                <!-- القسم الأب — قابل للنقر للفتح/الإغلاق -->
                <button class="sb-parent" type="button" onclick="toggleGroup(this.closest('.sb-group'))">
                    <span class="sb-parent-left">
                        <i class="bi <?= htmlspecialchars($section['icon']) ?>"></i>
                        <span><?= htmlspecialchars($section['label']) ?></span>
                    </span>
                    <i class="bi bi-chevron-down sb-chevron"></i>
                </button>

                <!-- الأبناء -->
                <div class="sb-children">
                    <?php foreach ($section['children'] as $child): ?>
                        <?php
                        $isActive = ($currentPage === $child['key']);
                        $href = moduleUrl($child['key']);
                        ?>
                        <a href="<?= $href ?>" class="sb-child <?= $isActive ? 'active' : '' ?>">
                            <i class="bi <?= htmlspecialchars($child['icon']) ?>"></i>
                            <span><?= htmlspecialchars($child['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

        <?php endforeach; ?>
    </nav>

    <!-- Footer -->
    <div class="sb-footer">
        <div class="sb-user">
            <div class="sb-avatar"><?= mb_substr($user['full_name'] ?: 'U', 0, 1) ?></div>
            <div class="sb-user-info">
                <div class="sb-user-name"><?= htmlspecialchars($user['full_name']) ?></div>
                <div class="sb-user-role"><?= htmlspecialchars($user['role']) ?></div>
            </div>
        </div>
        <div class="sb-footer-links">
            <a href="<?= BASE_PATH ?>/select_account.php">
                <i class="bi bi-arrow-repeat"></i> تغيير الفرع
            </a>
            <a href="<?= BASE_PATH ?>/logout.php" class="logout">
                <i class="bi bi-box-arrow-right"></i> خروج
            </a>
        </div>
    </div>
</aside>

<?php
/**
 * يرتب أقسام القائمة الجانبية حسب تكرار الاستخدام الفعلي بدل الترتيب
 * الخام من جدول modules (sort_order)، ويخفي قسم "الإنتاج" لفرع بيع.
 *
 * ⚠ مؤقت/يدوي: الترتيب والإخفاء هون معتمدين على مفاتيح الأقسام (section
 * key) اللي افترضناها بناءً على بادئات moduleUrl() الموجودة فعلياً
 * (sales./purchases./inventory./finance./crm./hr./expenses./admin.).
 * "الإنتاج" بالتحديد اسم مفتاحه غير مؤكد (production أو manufacturing)
 * — بيتفلتر هون بمطابقة مزدوجة (مفتاح أو نص التسمية) لحد ما يتأكد
 * الاسم الفعلي من جدول modules مباشرة. لو المطابقة ما نجحت، القسم
 * بيضل ظاهر بس بآخر القائمة (فشل آمن، مو إخفاء أعمى).
 *
 * كمان: الإخفاء مربوط حرفياً بـ table_suffix === 'ret' حالياً (نفس
 * نمط الضعف الموثّق بـ dashboard.php) — لازم يتحول لاحقاً للتحقق من
 * branch_type === 'retail' ديناميكياً بدل قيمة ثابتة.
 */
function reorderAndFilterSidebarMenu(array $menu, string $tableSuffix): array
{
    // الأكثر استخداماً أولاً، فالدوري، فالإداري. أي قسم غير مذكور هون
    // (غير معروف/جديد) بينضاف تلقائياً بالآخر، مش بيختفي.
    $priority = ['inventory', 'purchases', 'sales', 'expenses', 'hr', 'finance', 'admin']; // 'crm' أُزيلت — المجموعة نفسها انحلّت (راجع 11_dissolve_crm_group.sql)

    $isRetailBranch = ($tableSuffix === 'ret');
    $hiddenKeys = ['production', 'manufacturing'];
    $hiddenLabelHints = ['إنتاج', 'تصنيع'];

    $visible = [];
    foreach ($menu as $section) {
        $key = $section['key'] ?? '';
        $label = $section['label'] ?? '';

        $looksLikeProduction = in_array($key, $hiddenKeys, true);
        foreach ($hiddenLabelHints as $hint) {
            if (mb_strpos($label, $hint) !== false) {
                $looksLikeProduction = true;
                break;
            }
        }

        if ($isRetailBranch && $looksLikeProduction) {
            continue; // مخفي لفرع بيع — راجع التعليق أعلاه لو انضاف فرع تصنيع لاحقاً
        }
        $visible[] = $section;
    }

    usort($visible, function ($a, $b) use ($priority) {
        $posA = array_search($a['key'] ?? '', $priority, true);
        $posB = array_search($b['key'] ?? '', $priority, true);
        $posA = ($posA === false) ? count($priority) : $posA;
        $posB = ($posB === false) ? count($priority) : $posB;
        return $posA <=> $posB;
    });

    return $visible;
}

/**
 * إضافة الطلبات الداخلية والمنتجات لقسم المخزون
 */
function injectInternalOrdersToMenu(array &$menu): void
{
    foreach ($menu as &$section) {
        if ($section['key'] === 'inventory') {
            $existing = array_column($section['children'], 'key');
            $toAdd = [];
            if (!in_array('inventory.internal_orders', $existing)) {
                $toAdd[] = ['key' => 'inventory.internal_orders', 'label' => 'الطلبات الداخلية', 'icon' => 'bi-arrow-left-right'];
            }
            if (!in_array('inventory.products', $existing)) {
                $toAdd[] = ['key' => 'inventory.products', 'label' => 'المنتجات', 'icon' => 'bi-boxes'];
            }
            foreach (array_reverse($toAdd) as $item) {
                array_unshift($section['children'], $item);
            }
            return;
        }
    }
}

/**
 * لون القسم الحالي — بتدوّر جوا $menu (نفس بيانات buildSidebarMenu())
 * عن القسم الأب المطابق لـ$currentModule، وترجّع theme_color تبعه.
 * نفس منطق البحث الموجود بـrenderBreadcrumb() بالضبط، بس هون بترجّع
 * لون بدل نص.
 *
 * ⚠ لو القسم ما عنده theme_color بعد (عمود جديد، قيم افتراضية بس)،
 * أو الصفحة غير مربوطة بجدول modules أصلاً، بترجع لون افتراضي آمن
 * بدل فراغ يكسر التصميم.
 */
function getCurrentSectionColor(array $menu, string $currentModule): string
{
    $default = '#3b82f6';
    if ($currentModule === '') {
        return $default;
    }
    foreach ($menu as $section) {
        foreach ($section['children'] ?? [] as $child) {
            if (($child['key'] ?? null) === $currentModule) {
                return $section['theme_color'] ?? $default;
            }
        }
    }
    return $default;
}

/** هل أي ابن في هذا القسم هو الصفحة الحالية؟ */
function isGroupActive(string $current, array $section): bool
{
    foreach ($section['children'] as $child) {
        if ($child['key'] === $current)
            return true;
    }
    return false;
}

/** تحويل مفتاح القسم إلى URL */
function moduleUrl(string $key): string
{
    static $map = [
    // ⚠ خريطة مُعاد بناؤها بالكامل (يوليو ٢٠٢٦) بمطابقة مباشرة لتشجير
    // modules/ الحقيقي المؤكد من المستخدم. أي مفتاح كان يشاور على ملف
    // غير موجود انحذف أو انصلح مساره. راجع Migration Log بـ
    // README-claude.md للتفاصيل الكاملة.

    // المبيعات
    'sales.invoices' => 'sales/sales_index.php',        // ✅ مسار مُصلح (كان sales/invoices.php)
    // sales.invoices.new أُزيلت (راجع 16_remove_sales_invoices_new_menu_item.sql)
    // — كانت عنصر شريط جانبي منفصل لـ"فاتورة جديدة"، عكس نمط المشتريات
    // (purchases.invoices.new غير موجود إطلاقاً). "فاتورة جديدة" الآن
    // بس زر داخل sales_index.php (<a href="invoice_new.php">)، مو عنصر
    // قائمة مستقل — نفس نمط المشتريات بالضبط.
    // ملاحظة: invoice_edit.php (تعديل فاتورة بيع) ما محتاج مفتاح
    // modules منفصل هون — بيستخدم نفس مفتاح 'sales.invoices' الموجود
    // فوق، بس بصلاحية action='edit' (أحد الأعلام السبعة)، مش قسم
    // مستقل بالقائمة الجانبية. الوصول إلها من زر "تعديل" داخل
    // sales_index.php مباشرة (<a href="invoice_edit.php?id=...">) لا من
    // القائمة الجانبية.
    'sales.returns' => 'sales/returns.php', // ✅ صفحة فعلية كاملة (CRUD مسودات + تأكيد/إلغاء عبر confirm_sale_return.php)
    'sales.reports' => 'sales/reports.php', // ✅ جديد — 6 تقارير (سجل مبيعات، أكثر عملاء، أكثر منتجات، هامش ربح، مرتجعات، حالة دفع)
    'sales.orders' => 'sales/orders.php', // ⚠ جديد — وضع صيانة مؤقت عمداً (نفس نمط purchases.orders)، بانتظار بناء المنطق الفعلي

    // المشتريات
    'purchases.invoices' => 'purchases/index.php',
    'purchases.suppliers' => 'purchases/suppliers.php',
    'purchases.consumable_purchases' => 'expenses_and_consumables/consumable_purchases.php', // ✅ نُقلت فيزيائياً من inventory/ لمجلدها المخصص الجديد
    'purchases.returns' => 'purchases/returns.php', // ✅ إنشاء + تأكيد كامل (مخزون + قيد محاسبي) مختبر وناجح
    'purchases.reports' => 'purchases/reports.php', // ✅ جديد — 7 تقارير (سجل مشتريات، أكثر شراءً، مرتجعات، نسبة إرجاع، خصم تعجيل، توزيع عملة، تاريخ سعر)
    'purchases.orders' => 'purchases/orders.php', // ⚠ جديد — وضع صيانة مؤقت عمداً (نفس نمط inventory.internal_orders)، بانتظار بناء المنطق الفعلي

    // المخزون (منتجات فقط — المستهلكات صارت تحت "المصاريف والمستهلكات")
    'inventory.products' => 'inventory/products.php',
    'inventory.warehouse' => 'inventory/warehouse.php',   // افتراضي type=products داخل الملف نفسه
    'inventory.movements' => 'inventory/movements.php',   // افتراضي tab=products داخل الملف نفسه
    'inventory.internal_orders' => 'inventory/internal_orders.php',
    'inventory.reports' => 'inventory/reports.php', // ✅ جديد — تقارير المنتجات (حركة/أسعار/ربح)
    'inventory.consumables' => 'expenses_and_consumables/consumables.php', // ✅ نُقلت فيزيائياً من inventory/ — المفتاح بدون تغيير (parent_key بجدول modules صار 'expenses')
    'inventory.import_products' => 'inventory/import_products.php', // ✅ جديد
    // inventory.raw_materials / inventory.operations أُزيلا — لا ملفات فعلية بعد (مواد أولية/تصنيع)

    // المصاريف والمستهلكات — ✅ مجموعة أُعيد بناؤها بالكامل بجدول modules
    // (راجع 07_restructure_modules_menu.sql). الأسماء القديمة الوهمية
    // (expenses.consumables/utilities/reports مع مجلد expenses/ غير
    // موجود) اختفت من الأساس لأنها ما كانت موجودة فعلياً بجدول modules
    // الحقيقي — كانت بس بخريطة moduleUrl() القديمة.
    'expenses.consumable_issues' => 'expenses_and_consumables/consumable_issues.php', // ✅ نُقلت فيزيائياً من inventory/
    'expenses.consumable_transfers' => 'expenses_and_consumables/consumable_transfers.php', // ✅ صفحة جديدة (مناقلة بين المستودعات المستهلكات)
    'expenses.warehouse' => 'inventory/warehouse.php?type=consumables',
    'expenses.consumable_entries' => 'inventory/movements.php?tab=consumables', // ✅ صف يتيم أُعيد تدويره — كان "سجل الاستهلاك" بلا صفحة
    'finance.expenses' => 'expenses_and_consumables/expenses.php', // ✅ نُقلت فيزيائياً من accounting/ — المفتاح بدون تغيير

    // المحاسبة/المالية
    'finance.accounts' => 'accounting/accounts.php',
    'finance.account_settings' => 'accounting/account_settings.php', // ✅ موجودة، كانت غير مربوطة بأي قائمة
    'finance.journal' => 'accounting/journal.php',
    'finance.receipts' => 'accounting/receipts.php',        // ✅ مسار مُصلح (كان receipts/index.php)
    'finance.payments' => 'accounting/payments.php',        // ✅ جديد — سندات الدفع (مقابل finance.receipts)
    'finance.treasury' => 'accounting/treasury.php',        // ✅ جديد — الصندوق (نظرة عامة + تحويل بين الصناديق/البنوك)
    'finance.taxes' => 'accounting/taxes.php',           // ✅ جديد — الضرائب والرسوم
    'finance.reports' => 'accounting/reports.php',         // ✅ جديد — التقارير المالية (ميزانية/أرباح وخسائر/تدفقات نقدية)
    'finance.currencies' => 'accounting/currencies.php',      // ✅ موجودة، كانت غير مربوطة بأي قائمة
    'finance.shipping_carriers' => 'accounting/shipping_carriers.php', // ✅ موجودة، كانت غير مربوطة بأي قائمة

    // ✅ مجموعة "العملاء والموردون" (crm) انحلّت بالكامل (راجع
    // 11_dissolve_crm_group.sql) — crm.customers نُقلت لمجموعة sales
    // (المفتاح بدون تغيير)، crm.suppliers انحذفت (مكررة مع
    // purchases.suppliers أدناه، نفس الملف بالضبط).
    'crm.customers' => 'sales/customers.php',      // ✅ مسار مُصلح (كان customers/index.php — الملف فعلياً جوا sales/)، parent_key صار 'sales'
    // crm.suppliers أُزيلت نهائياً من جدول modules — استخدم purchases.suppliers
    // crm.customers.statement / crm.suppliers.statement أُزيلا — لا صفحات كشف حساب فعلية بعد
    // sales.customers أُزيل نهائياً من جدول modules (كان تكرار حرفي لـ crm.customers)

    // الموارد البشرية
    'hr.employees' => 'hr/employees.php',
    'hr.attendance' => 'hr/attendance.php',
    'hr.payroll' => 'hr/payroll.php',
    // hr.reports أُزيل — لا صفحة تقارير فعلية بعد
    // hr.holidays أُزيل — العطل مُدارة جوا attendance.php نفسها، لا صفحة مستقلة

    // الإدارة
    'admin.users' => 'admin/users.php',
    'admin.permissions' => 'admin/permissions.php',
    'admin.branches' => 'admin/branches.php',
    'admin.section_colors' => 'admin/section_colors.php', // ✅ جديد — صفحة إعدادات ألوان الأقسام
    'admin.opening_balances' => 'admin/opening_balances.php', // ✅ كانت مفقودة
    // admin.settings أُزيل — لا صفحة settings/index.php فعلية بعد
    ];
    // قراءة base_path من session حسب الفرع
    // ⚠ 'ret' = فرع البيع 1 (كان aleppo/alp سابقاً، انترينيم لـ retail1/ret).
    // باقي الفروع (ist/gaz/lab/alp_lab) لسا schema-only بدون كود حقيقي —
    // خليناها كمرجع مستقبلي لحد ما يتبنى الهيكل العام لفرع تصنيع/بيع إضافي.
    $suffix = $_SESSION['table_suffix'] ?? 'ret';
    $branchFolderMap = ['ret' => 'retail1', 'ist' => 'istanbul', 'gaz' => 'gaziantep', 'lab' => 'lab', 'alp_lab' => 'alep_lab'];
    $branchFolder = isset($branchFolderMap[$suffix]) ? $branchFolderMap[$suffix] : 'retail1';
    $base = BASE_PATH . '/' . $branchFolder . '/modules/';
    return $base . ($map[$key] ?? '#');
}
?>