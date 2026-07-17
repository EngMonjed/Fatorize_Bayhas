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
?>
<style>
/* تجميع بصري لأقسام القائمة حسب تكرار الاستخدام — مضاف محلياً هون
   لتفادي التعديل على layout.css المشترك بين كل الصفحات. */
.sb-group-label{
    padding:14px 16px 4px; font-size:11px; color:rgba(255,255,255,.45);
    text-transform:none; letter-spacing:.02em;
}
.sb-nav > .sb-group-label:first-child{ padding-top:4px; }
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
    $priority = ['sales', 'inventory', 'purchases', 'crm', 'finance', 'expenses', 'hr', 'admin'];

    $isRetailBranch = ($tableSuffix === 'ret');
    $hiddenKeys     = ['production', 'manufacturing'];
    $hiddenLabelHints = ['إنتاج', 'تصنيع'];

    $visible = [];
    foreach ($menu as $section) {
        $key   = $section['key'] ?? '';
        $label = $section['label'] ?? '';

        $looksLikeProduction = in_array($key, $hiddenKeys, true);
        foreach ($hiddenLabelHints as $hint) {
            if (mb_strpos($label, $hint) !== false) { $looksLikeProduction = true; break; }
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

/** هل أي ابن في هذا القسم هو الصفحة الحالية؟ */
function isGroupActive(string $current, array $section): bool
{
    foreach ($section['children'] as $child) {
        if ($child['key'] === $current)
            return true;
    }
    // أيضاً إذا كانت الصفحة الحالية هي قسم فرعي من هذا القسم
    return strpos($current, $section['key'] . '.') === 0;
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
    'sales.invoices'     => 'sales/sales_index.php',        // ✅ مسار مُصلح (كان sales/invoices.php)
    'sales.invoices.new' => 'sales/sales_invoice_new.php',  // ✅ مسار مُصلح (كان sales/invoices.php?action=new)
    // sales.returns أُزيل — sales_returns_ret schema-only، لا صفحة فعلية

    // المشتريات
    'purchases.invoices'  => 'purchases/index.php',
    'purchases.suppliers' => 'purchases/suppliers.php',
    // purchases.returns أُزيل — purchase_returns_ret schema-only، لا صفحة فعلية

    // المخزون
    'inventory.products'         => 'inventory/products.php',
    'inventory.warehouse'        => 'inventory/warehouse.php',
    'inventory.movements'        => 'inventory/movements.php',
    'inventory.internal_orders'  => 'inventory/internal_orders.php', // ✅ كانت ناقصة تماماً — كان يرجع '#' دايماً
    'inventory.consumables'          => 'inventory/consumables.php',            // بدون تغيير — كانت صحيحة أصلاً
    'inventory.consumable_purchases' => 'inventory/consumable_purchases.php',   // ✅ كانت ناقصة تماماً من الخريطة
    'inventory.consumable_issues'    => 'inventory/consumable_issues.php',      // ✅ كانت ناقصة تماماً من الخريطة
    // inventory.raw_materials / inventory.operations أُزيلا — لا ملفات فعلية بعد (مواد أولية/تصنيع)

    // المحاسبة/المالية
    'finance.accounts'          => 'accounting/accounts.php',
    'finance.account_settings'  => 'accounting/account_settings.php', // ✅ موجودة، كانت غير مربوطة بأي قائمة
    'finance.journal'            => 'accounting/journal.php',
    'finance.receipts'           => 'accounting/receipts.php',        // ✅ مسار مُصلح (كان receipts/index.php)
    'finance.expenses'           => 'accounting/expenses.php',        // ✅ مسار مُصلح (كان expenses/index.php)
    'finance.currencies'         => 'accounting/currencies.php',      // ✅ موجودة، كانت غير مربوطة بأي قائمة
    'finance.shipping_carriers'  => 'accounting/shipping_carriers.php', // ✅ موجودة، كانت غير مربوطة بأي قائمة
    // finance.reports أُزيل — لا صفحة تقارير فعلية بعد

    // العملاء والموردون
    'crm.customers' => 'sales/customers.php',      // ✅ مسار مُصلح (كان customers/index.php — الملف فعلياً جوا sales/)
    'crm.suppliers' => 'purchases/suppliers.php',  // نفس ملف purchases.suppliers (صفحة موردين واحدة، مرتبطة من قسمين)
    // crm.customers.statement / crm.suppliers.statement أُزيلا — لا صفحات كشف حساب فعلية بعد

    // الموارد البشرية
    'hr.employees'  => 'hr/employees.php',
    'hr.attendance' => 'hr/attendance.php',
    'hr.payroll'    => 'hr/payroll.php',
    // hr.reports أُزيل — لا صفحة تقارير فعلية بعد

    // ⚫ قسم "expenses.*" (المصاريف والمستهلكات القديم) أُزيل بالكامل —
    // مجلد expenses/ ما إله وجود إطلاقاً بالمشروع. كان قسم كامل بالشريط
    // الجانبي بروابط ميتة (٤٠٤/#). لازم أيضاً يُحذف صف "المصاريف
    // والمستهلكات" القديم (بمفاتيحه الثلاثة) من جدول modules نفسه —
    // تصليح الخريطة هون لا يخفي القسم من الشريط الجانبي وحده.

    // الإدارة
    'admin.users'       => 'admin/users.php',
    'admin.permissions' => 'admin/permissions.php',
    'admin.branches'    => 'admin/branches.php',
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