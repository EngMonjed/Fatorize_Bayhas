<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('finance.account_settings', 'edit');
$currentModule = 'finance.account_settings';

$TS = $_SESSION['table_suffix'];
$TIAS = "invoice_account_settings_{$TS}";
$TAC = "account_charts_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// ── تعريف الإعدادات ──
$SETTINGS_MAP = [
    // المبيعات
    'sales_revenue' => ['label' => 'إيرادات المبيعات', 'group' => 'المبيعات', 'icon' => 'bi-bag', 'color' => '#16a34a', 'type' => 'revenue'],
    'customer_receivable' => ['label' => 'ذمم العملاء (مدينون)', 'group' => 'المبيعات', 'icon' => 'bi-person-lines-fill', 'color' => '#2563eb', 'type' => 'asset'],
    'customer_advance' => ['label' => 'دفعات مقدمة من العملاء (أب)', 'group' => 'المبيعات', 'icon' => 'bi-wallet2', 'color' => '#0891b2', 'type' => 'liability'],
    'cogs' => ['label' => 'تكلفة البضاعة المباعة', 'group' => 'المبيعات', 'icon' => 'bi-box-arrow-up', 'color' => '#dc2626', 'type' => 'expense'],
    'finished_inventory' => ['label' => 'مخزون المنتجات النهائية', 'group' => 'المبيعات', 'icon' => 'bi-boxes', 'color' => '#7c3aed', 'type' => 'asset'],
    // ⚠ اختياري بالكامل — اتركه فارغاً لو بدك الضريبة/الخصم يضلوا مدمجين
    // بصافي الإيراد (نفس نمط المشتريات الافتراضي). اضبطه بس لو بدك
    // تتبّع ضريبة المبيعات المستحقة/خصومات المبيعات كحسابات مستقلة
    // بقائمة الدخل والميزانية — راجع confirm_sale_invoice.php للتفاصيل.
    'sales_tax_payable' => ['label' => 'ضريبة مبيعات مستحقة', 'group' => 'المبيعات', 'icon' => 'bi-receipt', 'color' => '#d97706', 'type' => 'liability'],
    'sales_discount_given' => ['label' => 'خصومات مبيعات ممنوحة', 'group' => 'المبيعات', 'icon' => 'bi-percent', 'color' => '#dc2626', 'type' => 'expense'],
    'settlement_discount_expense' => ['label' => 'خصم تعجيل استلام من العملاء المبيعات', 'group' => 'المبيعات', 'icon' => 'bi-lightning-charge', 'color' => '#dc2626', 'type' => 'expense'],
    // المشتريات
    'supplier_payable' => ['label' => 'ذمم الموردين (دائنون)', 'group' => 'المشتريات', 'icon' => 'bi-truck', 'color' => '#d97706', 'type' => 'liability'],
    'supplier_forgiveness_income' => ['label' => 'مسامحة ديون الموردين', 'group' => 'المسامحة', 'icon' => 'bi-heart-fill', 'color' => '#16a34a', 'type' => 'revenue'],
    'customer_forgiveness_expense' => ['label' => 'مسامحة ديون العملاء', 'group' => 'المسامحة', 'icon' => 'bi-heart', 'color' => '#dc2626', 'type' => 'expense'],
    // المستهلكات
    'consumable_expense' => ['label' => 'مصاريف المستهلكات', 'group' => 'المستهلكات', 'icon' => 'bi-box-seam', 'color' => '#0891b2', 'type' => 'expense'],
    'consumable_inventory' => ['label' => 'مخزون المستهلكات', 'group' => 'المستهلكات', 'icon' => 'bi-archive', 'color' => '#0891b2', 'type' => 'asset'],
    'consumable_supplier' => ['label' => 'ذمم موردي المستهلكات', 'group' => 'المستهلكات', 'icon' => 'bi-truck', 'color' => '#64748b', 'type' => 'liability'],
    // ⚠ اختياري بالكامل — اتركه فارغاً لو الضريبة على المشتريات عندكم
    // غير قابلة للاسترداد (متل سوريا حالياً)؛ اضبطه بس لو نظامكم
    // الضريبي بيسمح باسترداد ضريبة المشتريات (متل VAT بتركيا) — عندها
    // فاتورة شراء المستهلكات بتفصل الضريبة لهالحساب بدل ما تضمّها
    // بتكلفة المخزون
    'tax_input_recoverable' => ['label' => 'ضريبة مشتريات قابلة للاسترداد', 'group' => 'المستهلكات', 'icon' => 'bi-receipt-cutoff', 'color' => '#16a34a', 'type' => 'asset'],
    // الرواتب
    'salary_expense' => ['label' => 'مصاريف الرواتب والأجور', 'group' => 'الموارد البشرية', 'icon' => 'bi-people', 'color' => '#7c3aed', 'type' => 'expense'],
    'salary_payable' => ['label' => 'مستحقات الموظفين', 'group' => 'الموارد البشرية', 'icon' => 'bi-person-badge', 'color' => '#7c3aed', 'type' => 'liability'],
    'employee_advance' => ['label' => 'سلف الموظفين', 'group' => 'الموارد البشرية', 'icon' => 'bi-credit-card', 'color' => '#dc2626', 'type' => 'asset'],
];

// شركات الشحن
$SETTINGS_MAP['shipping_payable'] = ['label' => 'ذمم شركات الشحن (حساب أب)', 'group' => 'الشحن والنقل', 'icon' => 'bi-truck', 'color' => '#d97706', 'type' => 'liability'];
$SETTINGS_MAP['shipping_advance'] = ['label' => 'الدفعات المقدمة لمزودي الخدمة', 'group' => 'الشحن والنقل', 'icon' => 'bi-cash-coin', 'color' => '#0891b2', 'type' => 'asset'];
$SETTINGS_MAP['shipping_expense'] = ['label' => 'مصاريف الشحن والنقل', 'group' => 'الشحن والنقل', 'icon' => 'bi-box-arrow-up-right', 'color' => '#dc2626', 'type' => 'expense'];
// النقليات
$SETTINGS_MAP['transport_payable'] = ['label' => 'ذمم شركات النقل (حساب أب)', 'group' => 'الشحن والنقل', 'icon' => 'bi-truck-front', 'color' => '#d97706', 'type' => 'liability'];
$SETTINGS_MAP['transport_advance'] = ['label' => 'الدفعات المقدمة لشركات النقل', 'group' => 'الشحن والنقل', 'icon' => 'bi-cash-coin', 'color' => '#0891b2', 'type' => 'asset'];
$SETTINGS_MAP['transport_expense'] = ['label' => 'مصاريف النقل (الأجرة من المعمل)', 'group' => 'الشحن والنقل', 'icon' => 'bi-box-arrow-up-right', 'color' => '#dc2626', 'type' => 'expense'];

// الجمارك — تُدار يدوياً عبر سندات القبض/الدفع، بدون ترحيل تلقائي
$SETTINGS_MAP['customs_suspense'] = ['label' => 'أمانات الجمارك (قبض من الزبون / دفع للجمارك)', 'group' => 'الشحن والنقل', 'icon' => 'bi-bank2', 'color' => '#64748b', 'type' => 'liability'];
$SETTINGS_MAP['customs_expense'] = ['label' => 'فروقات/مصاريف الجمارك', 'group' => 'الشحن والنقل', 'icon' => 'bi-receipt-cutoff', 'color' => '#dc2626', 'type' => 'expense'];
// فروقات سعر الصرف — حسابين منفصلين عمداً (لا netting): الربح إيراد
// والخسارة مصروف، كل وحدة بحسابها الخاص. تُستخدم بـtreasury.php عند
// تصريف/تحويل مبلغ بين عملتين مختلفتين وسعر التفاوض الفعلي يختلف عن
// سعر النظام المخزَّن بجدول currencies.
$SETTINGS_MAP['fx_gain'] = ['label' => 'أرباح فروقات الصرف', 'group' => 'فروقات الصرف', 'icon' => 'bi-graph-up-arrow', 'color' => '#16a34a', 'type' => 'revenue'];
$SETTINGS_MAP['fx_loss'] = ['label' => 'خسائر فروقات الصرف', 'group' => 'فروقات الصرف', 'icon' => 'bi-graph-down-arrow', 'color' => '#dc2626', 'type' => 'expense'];

// ضريبة الاستقطاع المخصومة من دفعات الموردين — التزام مستحق للدولة
// حتى يتم توريدها (تُستخدم بـpayments.php وتُدار من taxes.php)
$SETTINGS_MAP['withholding_tax_payable'] = ['label' => 'ضريبة استقطاع مستحقة', 'group' => 'الضرائب والرسوم', 'icon' => 'bi-receipt-cutoff', 'color' => '#d97706', 'type' => 'liability'];

// إضافة الصناديق والبنوك ديناميكياً من جدول العملات
$currencies_list = $pdo->query("SELECT * FROM currencies WHERE status='active' ORDER BY is_base DESC,id")->fetchAll();
foreach ($currencies_list as $cur) {
    $code = strtolower($cur['code']);
    $SETTINGS_MAP['cash_' . $code] = [
        'label' => 'صندوق ' . $cur['name'] . ' (' . $cur['symbol'] . ')',
        'group' => 'الصناديق والبنوك',
        'icon' => 'bi-cash-coin',
        'color' => '#16a34a',
        'type' => 'asset',
    ];
    $SETTINGS_MAP['bank_' . $code] = [
        'label' => 'بنك ' . $cur['name'] . ' (' . $cur['symbol'] . ')',
        'group' => 'الصناديق والبنوك',
        'icon' => 'bi-bank',
        'color' => '#1e3a8a',
        'type' => 'asset',
    ];
}


// ── ترتيب تبويبات قسم المالية ──
// ⚠ ميزة السحب والإفلات (Drag & Drop) معطَّلة مؤقتاً بطلب صريح — التبويبات
// هلق ثابتة بالترتيب الافتراضي أدناه، غير قابلة للسحب. جدول قاعدة البيانات
// user_tab_order ومنطق قراءة أي ترتيب محفوظ سابقاً تُركا كما هما عمداً
// (ما انحذفوا) لأنه محتمل نرجع نفعّل الميزة لاحقاً — راجع تحديثات_مستقبلية.md.
$tabsMeta = [
    'accounts.php' => ['bi-diagram-3', 'شجرة الحسابات'],
    'account_settings.php' => ['bi-gear', 'إعدادات الربط'],
    'journal.php' => ['bi-journal-bookmark', 'القيود المحاسبية'],
    'receipts.php' => ['bi-cash-stack', 'سندات القبض'],
    'payments.php' => ['bi-cash-coin', 'سندات الدفع'],
    'treasury.php' => ['bi-safe', 'الصندوق'],
    'taxes.php' => ['bi-receipt-cutoff', 'الضرائب والرسوم'],
    'reports.php' => ['bi-bar-chart-line', 'التقارير المالية'],
    'currencies.php' => ['bi-currency-exchange', 'العملات'],
    'shipping_carriers.php' => ['bi-truck', 'شركات الشحن']
];
$defaultTabOrder = array_keys($tabsMeta);
$tabOrder = $defaultTabOrder;

// ── AJAX ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $settings = json_decode($_POST['settings'] ?? '[]', true);
        if (empty($settings))
            throw new Exception('لا توجد إعدادات للحفظ');

        foreach ($settings as $key => $accId) {
            $accId = (int) $accId;
            if (!$accId)
                continue;

            // جلب بيانات الحساب
            $st = $pdo->prepare("SELECT * FROM `{$TAC}` WHERE id=?");
            $st->execute([$accId]);
            $acc = $st->fetch();
            if (!$acc)
                continue;

            // UPSERT
            $pdo->prepare("INSERT INTO `{$TIAS}` (setting_key,account_id,account_code,account_name,description,created_by)
                VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE account_id=?,account_code=?,account_name=?,updated_at=NOW()")
                ->execute([
                    $key,
                    $accId,
                    $acc['code'],
                    $acc['name'],
                    $SETTINGS_MAP[$key]['label'] ?? $key,
                    $_SESSION['user_id'],
                    $accId,
                    $acc['code'],
                    $acc['name']
                ]);
        }
        echo json_encode(['ok' => true, 'msg' => 'تم حفظ الإعدادات بنجاح']);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}
// تحميل الترتيب المخصَّص للمستخدم الحالي (لو محفوظ) — يستبدل الترتيب
// الافتراضي أعلاه؛ أي تبويب جديد ينضاف بالمستقبل ما كان بالترتيب
// المحفوظ القديم بينضاف تلقائياً بالآخر (مش بيختفي).
try {
    $ordSt = $pdo->prepare("SELECT tab_order FROM user_tab_order WHERE user_id=? AND page_group='finance'");
    $ordSt->execute([$_SESSION['user_id']]);
    $savedTabJson = $ordSt->fetchColumn();
    if ($savedTabJson) {
        $savedTabArr = json_decode($savedTabJson, true);
        if (is_array($savedTabArr)) {
            $validOrder = array_values(array_intersect($savedTabArr, $defaultTabOrder));
            $missingOrder = array_values(array_diff($defaultTabOrder, $validOrder));
            $tabOrder = array_merge($validOrder, $missingOrder);
        }
    }
} catch (Exception $e) {
    // جدول user_tab_order لسا ما انعمل؟ رجوع آمن للترتيب الافتراضي بصمت
}



// ── مفاتيح "حرجة" فعلياً تُقرأ من كود ترحيل القيود ──
// ⚠ لو مفتاح هون فاضي، القيد المحاسبي المرتبط به بيفشل يترحّل بصمت
// (بس error_log، بدون أي تنبيه بالواجهة وقت التأكيد). موثّق حالياً:
// consumable_expense/consumable_inventory يُقرأان من inventory/consumable_issues.php
// عند تأكيد أمر صرف مستهلكات. أضف أي مفتاح تاني هون فقط بعد ما تتأكد
// فعلياً إنه مقروء من مكان ما بالكود (مو كل مفتاح بـ $SETTINGS_MAP حرج).
$criticalKeys = [
    'consumable_expense' => 'صرف المستهلكات (inventory/consumable_issues.php)',
    'consumable_inventory' => 'صرف المستهلكات (inventory/consumable_issues.php)',
    'salary_payable' => 'إنشاء حساب مستحقات فرعي عند إضافة موظف (hr/employees.php)',
    'employee_advance' => 'إنشاء حساب سلف فرعي عند إضافة موظف (hr/employees.php)',
    'transport_payable' => 'قيد النقلية عند تحديث حالة التسليم (sales/sales_index.php)',
    'transport_expense' => 'قيد النقلية عند تحديث حالة التسليم (sales/sales_index.php)',
];

// ── جلب الإعدادات الحالية ──
$currentSettings = [];
$st = $pdo->query("SELECT setting_key, account_id FROM `{$TIAS}`");
foreach ($st->fetchAll() as $r) {
    $currentSettings[$r['setting_key']] = $r['account_id'];
}

// ── جلب الحسابات حسب النوع ──
$accounts = $pdo->query("SELECT ac.id, ac.code, ac.name, ac.account_type, ac.level,
    c.code AS cur_code, c.symbol AS cur_sym
    FROM `{$TAC}` ac
    LEFT JOIN currencies c ON c.id=ac.currency_id
    WHERE ac.is_active=1 ORDER BY ac.code")->fetchAll();

// تجميع الحسابات حسب النوع
$acsByType = [];
foreach ($accounts as $ac) {
    $acsByType[$ac['account_type']][] = $ac;
}

// تجميع الإعدادات حسب المجموعة
$groups = [];
foreach ($SETTINGS_MAP as $key => $cfg) {
    $groups[$cfg['group']][$key] = $cfg;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>إعدادات الربط المحاسبي — <?= htmlspecialchars($branchName) ?></title>
    <link rel="icon" href="<?= BASE_PATH ?>/assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/assets/css/layout.css" rel="stylesheet">
    <style>
        .settings-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            margin-bottom: 1.5rem
        }

        .settings-hdr {
            padding: 12px 20px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 10px
        }

        .settings-hdr-icon {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0
        }

        .setting-row {
            padding: 14px 20px;
            border-bottom: 1px solid #f8fafc;
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 16px;
            align-items: center
        }

        .setting-row:last-child {
            border-bottom: none
        }

        .setting-row:hover {
            background: #fafbfc
        }

        .setting-label {
            font-size: .83rem;
            font-weight: 600;
            color: #1e293b
        }

        .setting-desc {
            font-size: .72rem;
            color: #94a3b8;
            margin-top: 2px
        }

        .setting-select {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: .8rem;
            font-family: 'Cairo', sans-serif;
            color: #1e293b;
            background: #fff;
            transition: border-color .15s
        }

        .setting-select:focus {
            outline: none;
            border-color: #1e3a8a;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, .08)
        }

        .setting-select.has-value {
            border-color: #bbf7d0;
            background: #f0fdf4
        }

        .cur-badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-size: .68rem;
            padding: 1px 6px;
            border-radius: 4px;
            background: #f1f5f9;
            color: #64748b;
            margin-right: 4px
        }

        .missing-badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-size: .68rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 5px;
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
            margin-right: 6px
        }

        .save-bar {
            position: sticky;
            bottom: 0;
            background: #fff;
            border-top: 1px solid #e2e8f0;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 10
        }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-gear me-1 text-primary"></i>إعدادات الربط المحاسبي</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
            <a href="accounts.php" style="color:#64748b;text-decoration:none">شجرة الحسابات</a>
            <i class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
            <span class="text-primary">إعدادات الربط</span>
        </nav>
    </header>
    <main class="main-content">
        <div class="content-body">
            <!-- تبويبات صفحات المالية — قابلة للسحب وإعادة الترتيب، محفوظة لكل مستخدم -->
            <ul class="nav nav-tabs mb-3" id="financeTabs" style="border-bottom:2px solid #e2e8f0;flex-wrap:wrap">
                <li class="nav-item" data-href="accounts.php">
                    <a class="nav-link fw-600" href="accounts.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-diagram-3 me-1"></i>شجرة الحسابات
                    </a>
                </li>
                <li class="nav-item" data-href="account_settings.php">
                    <a class="nav-link fw-600 active" href="account_settings.php"
                        style="border:none;border-bottom:2px solid #1e3a8a;color:#1e3a8a;font-size:.83rem;margin-bottom:-2px">
                        <i class="bi bi-gear me-1"></i>إعدادات الربط
                    </a>
                </li>
                <li class="nav-item" data-href="journal.php">
                    <a class="nav-link fw-600" href="journal.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-journal-bookmark me-1"></i>القيود المحاسبية
                    </a>
                </li>
                <li class="nav-item" data-href="receipts.php">
                    <a class="nav-link fw-600" href="receipts.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-cash-stack me-1"></i>سندات القبض
                    </a>
                </li>
                <li class="nav-item" data-href="payments.php">
                    <a class="nav-link fw-600" href="payments.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-cash-coin me-1"></i>سندات الدفع
                    </a>
                </li>
                <li class="nav-item" data-href="treasury.php">
                    <a class="nav-link fw-600" href="treasury.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-safe me-1"></i>الصندوق
                    </a>
                </li>
                <li class="nav-item" data-href="taxes.php">
                    <a class="nav-link fw-600" href="taxes.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-receipt-cutoff me-1"></i>الضرائب والرسوم
                    </a>
                </li>
                <li class="nav-item" data-href="reports.php">
                    <a class="nav-link fw-600" href="reports.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-bar-chart-line me-1"></i>التقارير المالية
                    </a>
                </li>
                <li class="nav-item" data-href="currencies.php">
                    <a class="nav-link fw-600" href="currencies.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-currency-exchange me-1"></i>العملات
                    </a>
                </li>
                <li class="nav-item" data-href="shipping_carriers.php">
                    <a class="nav-link fw-600" href="shipping_carriers.php"
                        style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-truck me-1"></i>شركات الشحن
                    </a>
                </li>
            </ul>


            <!-- تنبيه -->
            <div
                style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:12px 16px;margin-bottom:1.5rem;font-size:.82rem;color:#1e3a8a">
                <i class="bi bi-info-circle-fill me-2"></i>
                هذه الإعدادات تحدد الحسابات المحاسبية التي تُستخدم تلقائياً عند تأكيد الفواتير وصرف الرواتب وعمليات
                المستهلكات.
                يُنصح بمراجعتها مع محاسب قانوني.
            </div>

            <?php
            $missingCritical = [];
            foreach ($criticalKeys as $ck => $ckUsage) {
                if (empty($currentSettings[$ck])) {
                    $missingCritical[$ck] = $ckUsage;
                }
            }
            ?>
            <?php if ($missingCritical): ?>
                <div
                    style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:12px 16px;margin-bottom:1.5rem;font-size:.82rem;color:#991b1b">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <strong>تنبيه:</strong> في إعدادات حسابات لسا غير مضبوطة، وبتُستخدم فعلياً عند ترحيل قيود محاسبية —
                    العملية بتتأكد وبيتحدث المخزون بنجاح، بس القيد المحاسبي المرتبط <u>ما بينكتب</u> لحد ما تضبط الحساب:
                    <ul style="margin:6px 0 0;padding-inline-start:1.2rem">
                        <?php foreach ($missingCritical as $ck => $ckUsage): ?>
                            <li><?= htmlspecialchars($SETTINGS_MAP[$ck]['label'] ?? $ck) ?> —
                                <span style="color:#7f1d1d"><?= htmlspecialchars($ckUsage) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php foreach ($groups as $groupName => $groupSettings): ?>
                <div class="settings-card">
                    <div class="settings-hdr">
                        <?php
                        $groupIcons = [
                            'المبيعات' => ['bi-bag-check', '#16a34a', '#f0fdf4'],
                            'المشتريات' => ['bi-cart3', '#d97706', '#fffbeb'],
                            'المستهلكات' => ['bi-box-seam', '#0891b2', '#f0f9ff'],
                            'الموارد البشرية' => ['bi-people', '#7c3aed', '#f5f3ff'],
                            'الصناديق والبنوك' => ['bi-safe', '#16a34a', '#f0fdf4'],
                        ];
                        [$gIc, $gClr, $gBg] = $groupIcons[$groupName] ?? ['bi-gear', '#64748b', '#f8fafc'];
                        ?>
                        <div class="settings-hdr-icon" style="background:<?= $gBg ?>;color:<?= $gClr ?>">
                            <i class="bi <?= $gIc ?>"></i>
                        </div>
                        <span
                            style="font-size:.9rem;font-weight:700;color:#1e293b"><?= htmlspecialchars($groupName) ?></span>
                    </div>

                    <?php foreach ($groupSettings as $key => $cfg): ?>
                        <?php
                        $currentAccId = $currentSettings[$key] ?? 0;
                        $typeAccounts = $acsByType[$cfg['type']] ?? [];
                        ?>
                        <div class="setting-row">
                            <div>
                                <div class="setting-label">
                                    <i class="bi <?= $cfg['icon'] ?> me-1" style="color:<?= $cfg['color'] ?>"></i>
                                    <?= htmlspecialchars($cfg['label']) ?>
                                    <?php if (!$currentAccId && isset($criticalKeys[$key])): ?>
                                        <span class="missing-badge" title="<?= htmlspecialchars($criticalKeys[$key]) ?>">
                                            <i class="bi bi-exclamation-triangle-fill"></i> غير مضبوط
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="setting-desc"><?= htmlspecialchars($key) ?></div>
                            </div>
                            <div>
                                <select class="setting-select <?= $currentAccId ? 'has-value' : '' ?>" data-key="<?= $key ?>"
                                    onchange="this.classList.toggle('has-value',!!this.value); markChanged()">
                                    <option value="">— غير محدد —</option>
                                    <?php foreach ($typeAccounts as $ac): ?>
                                        <option value="<?= $ac['id'] ?>" <?= $ac['id'] == $currentAccId ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($ac['code'] . ' — ' . $ac['name']) ?>
                                            <?php if ($ac['cur_sym']): ?>
                                                (<?= htmlspecialchars($ac['cur_sym']) ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <!-- شريط الحفظ -->
            <div class="save-bar">
                <span id="changeIndicator" style="font-size:.8rem;color:#94a3b8">
                    <i class="bi bi-check-circle text-success me-1"></i>لا توجد تغييرات
                </span>
                <button class="btn btn-sm fw-600"
                    style="border-radius:9px;background:#1e3a8a;color:#fff;min-width:130px;border:none"
                    onclick="saveSettings()" id="btnSave" disabled>
                    <span id="saveTxt"><i class="bi bi-floppy me-1"></i>حفظ الإعدادات</span>
                    <span id="saveSpin" class="spinner-border spinner-border-sm" style="display:none"></span>
                </button>
            </div>

        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const sb = document.getElementById('sidebar'), ov = document.getElementById('sbOverlay');
        function sbOpen() { sb.classList.add('open'); ov.classList.add('show'); }
        function sbClose() { sb.classList.remove('open'); ov.classList.remove('show'); }
        window.addEventListener('resize', () => { if (window.innerWidth > 991) sbClose(); });
        function toggleGroup(g) { const o = g.classList.contains('open'); document.querySelectorAll('.sb-group.open').forEach(x => x.classList.remove('open')); g.classList.toggle('open', !o); localStorage.setItem('sb_open_' + g.dataset.key, (!o).toString()); }
        document.querySelectorAll('.sb-group').forEach(g => { if (localStorage.getItem('sb_open_' + g.dataset.key) === 'true') g.classList.add('open'); });

        function markChanged() {
            document.getElementById('changeIndicator').innerHTML = '<i class="bi bi-pencil text-warning me-1"></i>يوجد تغييرات غير محفوظة';
            document.getElementById('btnSave').disabled = false;
        }

        function saveSettings() {
            const settings = {};
            document.querySelectorAll('.setting-select').forEach(sel => {
                if (sel.value) settings[sel.dataset.key] = sel.value;
            });

            document.getElementById('saveTxt').style.opacity = '0';
            document.getElementById('saveSpin').style.display = 'inline-block';
            document.getElementById('btnSave').disabled = true;

            const fd = new FormData();
            fd.append('_action', 'save');
            fd.append('settings', JSON.stringify(settings));
            fetch(location.href, { method: 'POST', body: fd }).then(r => r.json()).then(d => {
                document.getElementById('saveTxt').style.opacity = '1';
                document.getElementById('saveSpin').style.display = 'none';
                if (d.ok) {
                    document.getElementById('changeIndicator').innerHTML =
                        '<i class="bi bi-check-circle-fill text-success me-1"></i>تم الحفظ بنجاح';
                } else {
                    document.getElementById('btnSave').disabled = false;
                    alert(d.msg);
                }
            });
        }

    </script>

</body>

</html>