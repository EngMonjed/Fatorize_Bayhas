<?php
/**
 * includes/sidebar.php
 * يُضمَّن في كل صفحة داخل الفرع
 * المتغيرات المطلوبة: $pdo, $currentModule (مثل 'sales.invoices')
 */

$menu = buildSidebarMenu($pdo);
injectInternalOrdersToMenu($menu);
$user = getCurrentUser();
$branchName = $_SESSION['branch_name'] ?? 'الفرع';
$currentPage = $currentModule ?? '';
?>
<aside class="sidebar" id="sidebar">

    <!-- Brand -->
    <div class="sb-brand">
        <img src="/bayhas/assets/images/fatorize.png" alt="" onerror="this.style.display='none'">
        <div class="sb-brand-text">
            <div class="sb-name">FATORIZE</div>
            <div class="sb-branch"><?= htmlspecialchars($branchName) ?></div>
        </div>
    </div>

    <!-- Nav -->
    <nav class="sb-nav">
        <?php foreach ($menu as $section): ?>

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
            <a href="/bayhas/select_account.php">
                <i class="bi bi-arrow-repeat"></i> تغيير الفرع
            </a>
            <a href="/bayhas/logout.php" class="logout">
                <i class="bi bi-box-arrow-right"></i> خروج
            </a>
        </div>
    </div>
</aside>

<?php
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
            if (!in_array('inventory.consumables', $existing)) {
                $toAdd[] = ['key' => 'inventory.consumables', 'label' => 'إدارة المستهلكات', 'icon' => 'bi-box-seam'];
            }
            foreach (array_reverse($toAdd) as $item) {
                array_unshift($section['children'], $item);
            }
        }
        if ($section['key'] === 'purchases') {
            $existing = array_column($section['children'], 'key');
            $toAdd = [];
            if (!in_array('purchases.suppliers', $existing)) {
                $toAdd[] = ['key' => 'purchases.suppliers', 'label' => 'إدارة الموردين', 'icon' => 'bi-truck'];
            }
            foreach (array_reverse($toAdd) as $item) {
                array_unshift($section['children'], $item);
            }
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
    // المبيعات
    'sales.invoices' => 'sales/invoices.php',
    'sales.invoices.new' => 'sales/invoices.php?action=new',
    'sales.returns' => 'sales/returns.php',
    // المشتريات
    'purchases.invoices'   => 'purchases/index.php',
    'purchases.returns'    => 'purchases/returns.php',
    'purchases.suppliers'  => 'purchases/suppliers.php',
    // المخزون
    'inventory.products' => 'inventory/products.php',
    'inventory.warehouse' => 'inventory/warehouse.php',
    'inventory.movements' => 'inventory/movements.php',
    'inventory.raw_materials' => 'inventory/raw_materials.php',
    'inventory.operations' => 'inventory/operations.php',
    'inventory.consumables' => 'inventory/consumables.php',
    // المالية
    'finance.accounts' => 'accounting/accounts.php',
    'finance.journal' => 'accounting/journal.php',
    'finance.receipts' => 'receipts/index.php',
    'finance.expenses' => 'expenses/index.php',
    'finance.reports' => 'reports/index.php',
    // العملاء والموردون
    'crm.customers' => 'customers/index.php',
    'crm.customers.statement' => 'customers/statement.php',
    'crm.suppliers' => 'suppliers/index.php',
    'crm.suppliers.statement' => 'suppliers/statement.php',
    // الموارد البشرية
    'hr.employees' => 'hr/employees.php',
    'hr.attendance' => 'hr/attendance.php',
    'hr.payroll' => 'hr/payroll.php',
    'hr.reports' => 'hr/reports.php',
    // المصاريف التشغيلية
    'expenses.consumables' => 'expenses/consumables.php',
    'expenses.utilities' => 'expenses/utilities.php',
    'expenses.reports' => 'expenses/reports.php',
    // الإدارة
    'admin.users' => 'admin/users.php',
    'admin.permissions' => 'admin/permissions.php',
    'admin.settings' => 'settings/index.php',
    'admin.branches' => 'admin/branches.php',
    ];
    // قراءة base_path من session حسب الفرع
    $suffix = $_SESSION['table_suffix'] ?? 'alp';
    $branchFolderMap = ['alp' => 'aleppo', 'ist' => 'istanbul', 'gaz' => 'gaziantep', 'lab' => 'lab', 'alp_lab' => 'alep_lab'];
    $branchFolder = isset($branchFolderMap[$suffix]) ? $branchFolderMap[$suffix] : 'aleppo';
    $base = '/bayhas/' . $branchFolder . '/modules/';
    return $base . ($map[$key] ?? '#');
}
?>