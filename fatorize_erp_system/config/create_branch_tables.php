<?php
/**
 * config/create_branch_tables.php
 *
 * تُستدعى عند إنشاء فرع جديد (من branch_add.php أو زر "إنشاء الجداول"
 * بصفحة branches.php).
 *
 * ⚠ إعادة كتابة كاملة (أغسطس ٢٠٢٦) — النسخة القديمة كانت قائمة SQL
 * ثابتة مكتوبة يدوياً، وصارت قديمة جداً مقارنة بالبنية الفعلية بعد كل
 * جلسات التطوير المتراكمة (أعمدة ناقصة، جداول كاملة غايبة زي كل نظام
 * المستهلكات الحقيقي، بنية variants غلط بالكامل...). **الحل الجذري:**
 * بدل قائمة ثابتة، الدالة هلق بتكتشف تلقائياً كل جداول فرع البيع الأول
 * (`_ret`) الموجودة فعلياً وتستنسخها بـ`CREATE TABLE ... LIKE` — فرع
 * البيع الأول صار "القالب المرجعي الحي"، دايماً متزامن مع الواقع
 * تلقائياً، بدون أي صيانة يدوية مستقبلية.
 *
 * الاستخدام:
 *   require_once 'config/create_branch_tables.php';
 *   $result = createBranchTables($pdo, 'fac1', 'factory');
 *
 * ⚠ قيد معروف ومقصود: CREATE TABLE ... LIKE بينسخ الأعمدة/الفهارس/
 * الـPK/الـauto_increment بدقة، بس **مش** قيود الـFOREIGN KEY (لأنها
 * أصلاً كانت رح تشاور غلط لجداول _ret، مش لجداول الفرع الجديد). هاد
 * متّسق مع كون `FOREIGN_KEY_CHECKS` أصلاً معطّلة وقت الإنشاء، والتطبيق
 * بمعظمه بيدير العلاقات بمنطق PHP مباشرة، مو قيود DB صارمة.
 */

/** اسم لاحقة فرع البيع الأول — القالب المرجعي الحي لكل الجداول المشتركة */
const BRANCH_TEMPLATE_SUFFIX = 'ret';

/**
 * جداول حصرية بفروع التصنيع فقط — ما إلها قالب بفرع البيع (منطقي، مش
 * محتاجها)، فلازم تُكتب يدوياً هون، لا يوجد بديل. لو أضفت عمود عليهم
 * مستقبلاً، **هون بالضبط المكان الوحيد يلي لازم تحدّثه يدوياً** —
 * كل شي تاني بالملف صار تلقائي.
 */
function getFactoryOnlyTablesSql(string $s): array
{
    return [
        "raw_materials_{$s}" => "CREATE TABLE IF NOT EXISTS `raw_materials_{$s}` (
            `id`        INT(11)       NOT NULL AUTO_INCREMENT,
            `name`      VARCHAR(150)  NOT NULL,
            `unit`      VARCHAR(30)   NOT NULL DEFAULT 'kg',
            `category`  VARCHAR(100)  DEFAULT NULL,
            `notes`     TEXT          DEFAULT NULL,
            `is_active` TINYINT(1)    NOT NULL DEFAULT 1,
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "raw_material_stock_{$s}" => "CREATE TABLE IF NOT EXISTS `raw_material_stock_{$s}` (
            `id`               INT(11)       NOT NULL AUTO_INCREMENT,
            `material_id`      INT(11)       NOT NULL,
            `warehouse_id`     INT(11)       NOT NULL DEFAULT 1,
            `quantity`         DECIMAL(12,3) NOT NULL DEFAULT 0.000,
            `avg_cost_base`    DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
            `last_movement_at` DATETIME      DEFAULT NULL,
            `updated_at`       DATETIME      DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_material_wh` (`material_id`,`warehouse_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "production_operations_{$s}" => "CREATE TABLE IF NOT EXISTS `production_operations_{$s}` (
            `id`                 INT(11)       NOT NULL AUTO_INCREMENT,
            `name`               VARCHAR(100)  NOT NULL COMMENT 'خياطة، تطريز، كحت',
            `unit`               VARCHAR(30)   NOT NULL DEFAULT 'piece',
            `default_price_base` DECIMAL(10,4) DEFAULT NULL,
            `notes`              TEXT          DEFAULT NULL,
            `is_active`          TINYINT(1)    NOT NULL DEFAULT 1,
            `created_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "production_entries_{$s}" => "CREATE TABLE IF NOT EXISTS `production_entries_{$s}` (
            `id`             INT(11)       NOT NULL AUTO_INCREMENT,
            `operation_id`   INT(11)       NOT NULL,
            `entry_date`     DATE          NOT NULL,
            `quantity`       DECIMAL(10,2) NOT NULL,
            `price_per_unit` DECIMAL(10,4) NOT NULL,
            `total_base`     DECIMAL(12,4) NOT NULL,
            `product_id`     INT(11)       DEFAULT NULL,
            `worker_id`      INT(11)       DEFAULT NULL,
            `notes`          TEXT          DEFAULT NULL,
            `created_by`     INT(11)       NOT NULL,
            `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_entry_date` (`entry_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "manufacturing_bom_{$s}" => "CREATE TABLE IF NOT EXISTS `manufacturing_bom_{$s}` (
            `id`                INT(11)       NOT NULL AUTO_INCREMENT,
            `product_id`        INT(11)       NOT NULL,
            `component_type`    ENUM('raw_material','consumable','service') NOT NULL DEFAULT 'raw_material',
            `component_id`      INT(11)       NOT NULL,
            `quantity_required` DECIMAL(10,4) NOT NULL,
            `unit_cost`         DECIMAL(10,4) NOT NULL,
            `is_variable`       TINYINT(1)    NOT NULL DEFAULT 0,
            `notes`             TEXT          DEFAULT NULL,
            `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`        DATETIME      DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

/**
 * دليل الحسابات الكامل الافتراضي لأي فرع جديد — ٤٤ حساب، مبني حسب
 * الشجرة الفعلية المعتمدة (بايهاس). كل صف: [كود قديم بفرع البيع،
 * الكود، الاسم، الوصف، كود الأب القديم أو null، النوع، تصنيف
 * التدفق النقدي، نشط؟، مقفول؟].
 *
 * ⚠ التسلسل الهرمي بيتحل ديناميكياً (مو بالاعتماد على عمود level
 * الخام) — بيتم إدخال أي حساب أبوه معروف مسبقاً (أو أب-جذر)، بتكرار
 * حتى يخلص الكل، بغض النظر عن ترتيب المصفوفة. هيك مقاوم لأي خلل
 * بترتيب/مستوى البيانات المصدر.
 */
function getDefaultChartOfAccountsSeed(): array
{
    return [
        // جذور
        [1, '1', 'الأصول', null, null, 'asset', 'none', 1, 1],
        [2, '2', 'الالتزامات', null, null, 'liability', 'none', 1, 1],
        [3, '3', 'حقوق الملكية', null, null, 'equity', 'financing', 1, 1],
        [4, '4', 'الإيرادات', null, null, 'revenue', 'operating', 1, 1],
        [5, '5', 'المصاريف', null, null, 'expense', 'operating', 1, 1],
        // مستوى ٢
        [10, '1.1', 'الأصول المتداولة', null, 1, 'asset', 'operating', 1, 1],
        [11, '1.2', 'الأصول الثابتة', null, 1, 'asset', 'investing', 1, 0],
        [20, '2.1', 'الالتزامات المتداولة', null, 2, 'liability', 'operating', 1, 1],
        [21, '2.2', 'الالتزامات طويلة الأمد', null, 2, 'liability', 'financing', 1, 0],
        [30, '3.1', 'رأس المال', null, 3, 'equity', 'financing', 1, 0],
        [31, '3.2', 'الأرباح المبقاة', null, 3, 'equity', 'financing', 1, 0],
        [32, '3.3', 'أرباح السنة الحالية', null, 3, 'equity', 'financing', 1, 1],
        [40, '4.1', 'إيرادات المبيعات', null, 4, 'revenue', 'operating', 1, 1],
        [41, '4.2', 'إيرادات أخرى', null, 4, 'revenue', 'operating', 1, 0],
        [50, '5.1', 'تكلفة المبيعات', null, 5, 'expense', 'operating', 1, 1],
        [51, '5.2', 'مصاريف التشغيل', null, 5, 'expense', 'operating', 1, 0],
        [52, '5.3', 'مصاريف الموظفين', null, 5, 'expense', 'operating', 1, 1],
        [53, '5.4', 'مصاريف إدارية وعمومية', null, 5, 'expense', 'operating', 1, 0],
        // مستوى ٣
        [100, '1.1.1', 'النقدية والصناديق', null, 10, 'asset', 'excluded', 1, 1],
        [101, '1.1.2', 'البنوك', null, 10, 'asset', 'excluded', 1, 1],
        [102, '1.1.3', 'ذمم العملاء', null, 10, 'asset', 'operating', 1, 1],
        [103, '1.1.4', 'سلف الموظفين', null, 10, 'asset', 'operating', 1, 0],
        [104, '1.1.5', 'المخزون', null, 10, 'asset', 'operating', 1, 1],
        [105, '1.1.6', 'مخزون المستهلكات', null, 10, 'asset', 'operating', 1, 1],
        [1017, '1.1.7', 'دفعات مقدمة للموردين', null, 10, 'asset', 'operating', 1, 0],
        [1020, '1.1.8', 'ضريبة مشتريات قابلة للاسترداد', null, 10, 'asset', 'operating', 1, 0],
        [200, '2.1.1', 'ذمم الموردين', null, 20, 'liability', 'operating', 1, 1],
        [201, '2.1.2', 'مستحقات الموظفين', null, 20, 'liability', 'operating', 1, 1],
        [202, '2.1.3', 'ضرائب مستحقة', null, 20, 'liability', 'operating', 1, 0],
        [1028, '2.1.4', 'ضريبة مبيعات مستحقة', 'sales_tax_payable', 20, 'liability', 'operating', 1, 0],
        [1031, '2.1.5', 'الدفعات المقدمة من العملاء', null, 20, 'liability', 'operating', 1, 0],
        [500, '5.1.1', 'تكلفة البضاعة المباعة', null, 50, 'expense', 'operating', 1, 1],
        [510, '5.2.1', 'مصاريف المستهلكات', null, 51, 'expense', 'operating', 1, 1],
        [511, '5.2.2', 'إيجار المحل', null, 51, 'expense', 'operating', 1, 0],
        [512, '5.2.3', 'كهرباء وماء', null, 51, 'expense', 'operating', 1, 0],
        [513, '5.2.4', 'صيانة وإصلاح', null, 51, 'expense', 'operating', 1, 0],
        [514, '5.2.5', 'شحن ونقل', null, 51, 'expense', 'operating', 1, 0],
        [1029, '5.2.6', 'خصومات مبيعات ممنوحة', 'sales_discount_given', 51, 'expense', 'operating', 1, 0],
        [1030, '5.2.7', 'خصم تعجيل استلام من العملاء المبيعات', 'settlement_discount_expense', 51, 'expense', 'operating', 1, 0],
        [520, '5.3.1', 'رواتب وأجور', null, 52, 'expense', 'operating', 1, 1],
        [521, '5.3.2', 'مكافآت وحوافز', null, 52, 'expense', 'operating', 1, 0],
        [530, '5.4.1', 'مصاريف إدارية عامة', null, 53, 'expense', 'operating', 1, 0],
        [531, '5.4.2', 'قرطاسية ومكتبية', null, 53, 'expense', 'operating', 1, 0],
        [532, '5.4.3', 'فروقات أسعار صرف', null, 53, 'expense', 'operating', 1, 1],
        [1021, '4.2.1', 'خصم مشتريات تجاري مكتسب', null, 41, 'revenue', 'operating', 1, 0],
        [1022, '4.2.2', 'إيراد خصم تعجيل الدفع', null, 41, 'revenue', 'operating', 1, 0],
        [1032, '4.2.3', 'أرباح فروقات الصرف', null, 41, 'revenue', 'operating', 1, 0],
        [1066, '3900', 'رصيد افتتاحي', null, 3, 'equity', 'none', 1, 0],
        // مستوى ٤
        [1001, '1.1.1.001', 'صندوق دولار أمريكي', null, 100, 'asset', 'excluded', 1, 0],
        [1011, '1.1.2.001', 'بنك دولار أمريكي', null, 101, 'asset', 'excluded', 1, 0],
    ];
}

/**
 * يزرع دليل الحسابات الكامل بفرع جديد، بحل التسلسل الهرمي ديناميكياً
 * (parent-first resolution) — بغض النظر عن ترتيب المصفوفة، وبعملة
 * الفرع الوظيفية الحقيقية (مو دايماً currency_id=1).
 */
function seedDefaultChartOfAccounts(PDO $pdo, string $s, int $baseCurrencyId): void
{
    $table = "account_charts_{$s}";
    $rows = getDefaultChartOfAccountsSeed();
    $idMap = []; // old_id (بفرع البيع) => new_id (بالفرع الجديد)
    $remaining = $rows;

    // إدخال parent-first: بأي دورة، ندخل كل صف أبوه معروف (جذر أو
    // موجود بالـidMap مسبقاً)، ونكرر لحد ما تخلص كل الصفوف أو ما
    // يصير تقدّم (حماية من حلقة لا نهائية لو بيانات فيها خطأ مرجعي)
    while (!empty($remaining)) {
        $progressed = false;
        foreach ($remaining as $key => $row) {
            [$oldId, $code, $name, $desc, $oldParentId, $type, $cashFlow, $isActive, $isLocked] = $row;
            $resolvedParentId = null;
            if ($oldParentId !== null) {
                if (!isset($idMap[$oldParentId]))
                    continue; // أبوه لسا ما انزرع
                $resolvedParentId = $idMap[$oldParentId];
            }
            $level = $resolvedParentId === null ? 1 : null; // بيتحسب فعلياً تحت

            $pdo->prepare("INSERT IGNORE INTO `{$table}`
                (code, name, description, parent_id, account_type, cash_flow_category, currency_id, level, is_active, is_locked)
                VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $code,
                    $name,
                    $desc,
                    $resolvedParentId,
                    $type,
                    $cashFlow,
                    $baseCurrencyId,
                    $resolvedParentId === null ? 1 : 0, // مؤقت، بنصححه تحت
                    $isActive,
                    $isLocked,
                ]);
            $newId = (int) $pdo->lastInsertId();
            if ($newId === 0) { // INSERT IGNORE تجاهل صف موجود مسبقاً — نجيب id الموجود
                $existing = $pdo->prepare("SELECT id FROM `{$table}` WHERE code = ?");
                $existing->execute([$code]);
                $newId = (int) $existing->fetchColumn();
            }
            $idMap[$oldId] = $newId;
            unset($remaining[$key]);
            $progressed = true;
        }
        if (!$progressed)
            break; // بيانات فيها مرجع دائري أو أب مفقود — نوقف بأمان
    }

    // تصحيح عمود level الحقيقي بناءً على عمق كل حساب فعلياً (مو تخمين وقت الإدخال)
    for ($i = 0; $i < 4; $i++) {
        $pdo->exec("UPDATE `{$table}` c
            JOIN `{$table}` p ON p.id = c.parent_id
            SET c.level = p.level + 1
            WHERE c.parent_id IS NOT NULL");
    }
}

/** فئات المستهلكات الافتراضية */
function seedDefaultConsumableCategories(PDO $pdo, string $s): void
{
    $table = "consumable_categories_{$s}";
    $rows = [
        ['مواد غذائية', 'bi-lightning-charge', '#0891b2', '#e0f7fa', 10],
        ['قرطاسية', 'bi-pencil', '#7c3aed', '#f3e8ff', 20],
        ['منظفات', 'bi-cup-hot', '#d97706', '#fef3c7', 30],
        ['صيانة', 'bi-tools', '#dc2626', '#fee2e2', 40],
    ];
    foreach ($rows as [$name, $icon, $color, $bg, $sort]) {
        $pdo->prepare("INSERT IGNORE INTO `{$table}` (name, icon, color, bg_color, is_active, sort_order) VALUES (?,?,?,?,1,?)")
            ->execute([$name, $icon, $color, $bg, $sort]);
    }
}

/** وحدات قياس المستهلكات الافتراضية */
function seedDefaultConsumableUnits(PDO $pdo, string $s): void
{
    $table = "consumable_units_{$s}";
    $units = ['قطعة', 'كيلوغرام', 'غرام', 'لتر', 'مليلتر', 'متر', 'علبة', 'كيس', 'فاتورة', 'صندوق', 'كونة', 'ورقة'];
    foreach ($units as $name) {
        $pdo->prepare("INSERT IGNORE INTO `{$table}` (name, is_active) VALUES (?, 1)")->execute([$name]);
    }
}

/**
 * الدالة الرئيسية — تُستدعى من branch_add.php / branches.php
 */
function createBranchTables(PDO $pdo, string $tableSuffix, string $branchType): array
{
    $errors = [];
    $created = [];
    $s = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $tableSuffix));

    if (empty($s)) {
        return ['ok' => false, 'created' => [], 'errors' => ['table_suffix غير صالح']];
    }
    if ($s === BRANCH_TEMPLATE_SUFFIX) {
        return ['ok' => false, 'created' => [], 'errors' => ["لاحقة '" . BRANCH_TEMPLATE_SUFFIX . "' محجوزة لفرع القالب المرجعي نفسه — جداوله الأصل، ما بيحتاج زر الإنشاء"]];
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    // ── ١) اكتشاف كل جداول الفرع المرجعي تلقائياً واستنساخها ──
    $suffixPattern = '\\_' . BRANCH_TEMPLATE_SUFFIX;
    $sourceTables = $pdo->query("SHOW TABLES LIKE '%{$suffixPattern}'")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($sourceTables as $sourceTable) {
        $baseName = preg_replace('/_' . BRANCH_TEMPLATE_SUFFIX . '$/', '', $sourceTable);
        $newTable = "{$baseName}_{$s}";
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `{$newTable}` LIKE `{$sourceTable}`");
            $created[] = $newTable;
        } catch (PDOException $e) {
            $errors[] = "{$newTable}: " . $e->getMessage();
        }
    }

    // ── ٢) جداول حصرية بفروع التصنيع (لا يوجد قالب بفرع البيع) ──
    if ($branchType === 'factory') {
        foreach (getFactoryOnlyTablesSql($s) as $tableName => $sql) {
            try {
                $pdo->exec($sql);
                $created[] = $tableName;
            } catch (PDOException $e) {
                $errors[] = "{$tableName}: " . $e->getMessage();
            }
        }
    }

    // ── ٣) بذرة دليل الحسابات الكاملة (٤٤ حساب) بعملة الفرع الحقيقية ──
    try {
        $curStmt = $pdo->prepare("SELECT base_currency_id FROM branches WHERE table_suffix = ?");
        $curStmt->execute([$s]);
        $baseCurrencyId = (int) ($curStmt->fetchColumn() ?: 1);
        seedDefaultChartOfAccounts($pdo, $s, $baseCurrencyId);
    } catch (PDOException $e) {
        $errors[] = "seed accounts: " . $e->getMessage();
    }

    // ── ٤) فئات ووحدات المستهلكات الافتراضية ──
    try {
        seedDefaultConsumableCategories($pdo, $s);
        seedDefaultConsumableUnits($pdo, $s);
    } catch (PDOException $e) {
        $errors[] = "seed consumable lookups: " . $e->getMessage();
    }

    // ── ٥) مستودعات افتراضية (منتجات + مستهلكات) ──
    try {
        $whTable = "warehouses_{$s}";
        $pdo->prepare("INSERT IGNORE INTO `{$whTable}` (code, name, warehouse_type) VALUES (?, ?, 'products')")
            ->execute([strtoupper($s) . '-WH-01', 'المستودع الرئيسي']);
        $pdo->prepare("INSERT IGNORE INTO `{$whTable}` (code, name, warehouse_type) VALUES (?, ?, 'consumables')")
            ->execute([strtoupper($s) . '-WH-CONS', 'مستودع المستهلكات']);
    } catch (PDOException $e) {
        $errors[] = "default warehouses: " . $e->getMessage();
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    return [
        'ok' => empty($errors),
        'created' => $created,
        'errors' => $errors,
        'suffix' => $s,
        'type' => $branchType,
    ];
}
