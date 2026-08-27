<?php
/**
 * expenses_and_consumables/expenses.php — المصاريف التشغيلية
 *retail1/modules/expenses_and_consumables/expenses.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('finance.expenses', 'view');
$currentModule = 'finance.expenses';

$TS = $_SESSION['table_suffix'];
$TE = "expenses_{$TS}";
$TAC = "account_charts_{$TS}";
$TAS = "invoice_account_settings_{$TS}";
$TJE = "journal_entries_{$TS}";
$TJI = "journal_entry_items_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// ⚠ عملة الفرع الأساسية — نفس منطق الاعتماد على base_currency_id
// الموثوق (مش عمود branches.base_currency النصي القديم، يلي لقيناه
// أحياناً فيه قيمة غير صالحة بملفات تانية بنفس القسم)
$branchBaseCurrency = 'USD';
$branchBaseCurrencyId = null;
if (!empty($_SESSION['branch_id'])) {
    $bcStmt = $pdo->prepare("SELECT c.id, c.code FROM branches b
        LEFT JOIN currencies c ON c.id = b.base_currency_id WHERE b.id = ?");
    $bcStmt->execute([$_SESSION['branch_id']]);
    $bcRow = $bcStmt->fetch();
    if ($bcRow && $bcRow['id']) {
        $branchBaseCurrencyId = (int) $bcRow['id'];
        $branchBaseCurrency = $bcRow['code'];
    }
}
if (!$branchBaseCurrencyId) {
    $fallbackCur = $pdo->query("SELECT id, code FROM currencies WHERE is_base=1 LIMIT 1")->fetch();
    if ($fallbackCur) {
        $branchBaseCurrencyId = (int) $fallbackCur['id'];
        $branchBaseCurrency = $fallbackCur['code'];
    }
}

// ⚠ journal_entries/journal_entry_items.currency_id هلق FK رقمي حقيقي
// لجدول currencies (كان varchar(3) رمز نصي) — نفس الدالة المستخدمة
// بملفي consumable_purchases.php/consumable_issues.php
function resolveCurrencyId(PDO $pdo, string $code): int
{
    static $cache = [];
    if (isset($cache[$code]))
        return $cache[$code];
    $st = $pdo->prepare("SELECT id FROM currencies WHERE code = ? LIMIT 1");
    $st->execute([$code]);
    $id = (int) ($st->fetchColumn() ?: 0);
    if (!$id)
        $id = (int) $pdo->query("SELECT id FROM currencies WHERE is_base=1 LIMIT 1")->fetchColumn();
    $cache[$code] = $id;
    return $id;
}

// ⚠ ترحيل فعلي لرصيد الحساب بشجرة الحسابات — نفس الدالة بالضبط
// المستخدمة بملفي المستهلكات (راجع تعليقها هناك للتفصيل الكامل).
// بتحل محل التحديث اليدوي القديم (كان يفترض دايماً "مدين=+/دائن=-"
// بدون فحص طبيعة الحساب الفعلية، وما كان يحدّث عمود balance إطلاقاً)
function postAccountBalance(PDO $pdo, string $TAC, int $accountId, float $debit, float $credit): void
{
    $acc = $pdo->prepare("SELECT account_type FROM `{$TAC}` WHERE id=?");
    $acc->execute([$accountId]);
    $row = $acc->fetch();
    if (!$row)
        return;
    $isDebitNormal = in_array($row['account_type'], ['asset', 'expense']);
    $delta = $isDebitNormal ? ($debit - $credit) : ($credit - $debit);
    $pdo->prepare("UPDATE `{$TAC}` SET base_balance = base_balance + ?, balance = balance + ?, updated_at = NOW() WHERE id=?")
        ->execute([$delta, $delta, $accountId]);
}

function genEntryNo(PDO $pdo, string $table): string
{
    $y = date('Y');
    $last = $pdo->query("SELECT entry_number FROM `{$table}`
        WHERE entry_number LIKE 'JE-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $seq = $last ? (int) substr($last, -4) + 1 : 1;
    return 'JE-' . $y . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        if ($act === 'save_expense') {
            requirePermission('finance.expenses', 'create');
            $expAccId = (int) $_POST['expense_account_id'];
            $cashAccId = (int) $_POST['cash_account_id'];
            $amtOrig = (float) $_POST['amount_original'];
            $currency = $_POST['currency'] ?? $branchBaseCurrency;
            $currencyId = resolveCurrencyId($pdo, $currency);
            $rate = max(0.0001, (float) ($_POST['exchange_rate'] ?? 1));
            $date = $_POST['expense_date'] ?? date('Y-m-d');
            $desc = trim($_POST['description'] ?? '');

            if (!$expAccId)
                throw new Exception('يجب اختيار حساب المصروف');
            if (!$cashAccId)
                throw new Exception('يجب اختيار حساب الدفع');
            if ($amtOrig <= 0)
                throw new Exception('المبلغ يجب أن يكون أكبر من صفر');

            $amtBase = $amtOrig / $rate;

            $pdo->beginTransaction();
            try {
                // إنشاء قيد محاسبي تلقائي
                $jeNo = genEntryNo($pdo, $TJE);
                $expAcc = $pdo->query("SELECT * FROM `{$TAC}` WHERE id={$expAccId}")->fetch();
                $cashAcc = $pdo->query("SELECT * FROM `{$TAC}` WHERE id={$cashAccId}")->fetch();

                $pdo->prepare("INSERT INTO `{$TJE}` (entry_number,entry_date,description,currency_id,
                    exchange_rate,total_debit,total_credit,status,reference_type,created_by)
                    VALUES (?,?,?,?,?,?,?,'posted','expense',?)")
                    ->execute([
                        $jeNo,
                        $date,
                        ($desc ?: ($expAcc['name'] ?? 'مصروف')),
                        $currencyId,
                        $rate,
                        $amtBase,
                        $amtBase,
                        $_SESSION['user_id']
                    ]);
                $jeId = (int) $pdo->lastInsertId();

                // مدين: حساب المصروف
                $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,
                    original_amount,base_amount,description,currency_id,exchange_rate)
                    VALUES (?,?,?,0,?,?,?,?,?)")
                    ->execute([$jeId, $expAccId, $amtBase, $amtOrig, $amtBase, $desc, $currencyId, $rate]);
                postAccountBalance($pdo, $TAC, $expAccId, $amtBase, 0);

                // دائن: حساب الصندوق/الدفع
                $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,
                    original_amount,base_amount,description,currency_id,exchange_rate)
                    VALUES (?,?,0,?,?,?,?,?,?)")
                    ->execute([$jeId, $cashAccId, $amtBase, $amtOrig, $amtBase, $desc, $currencyId, $rate]);
                postAccountBalance($pdo, $TAC, $cashAccId, 0, $amtBase);

                // حفظ المصروف
                $pdo->prepare("INSERT INTO `{$TE}` (expense_account_id,cash_account_id,
                    amount_original,currency,exchange_rate,amount_base,description,expense_date,
                    journal_entry_id,user_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([
                        $expAccId,
                        $cashAccId,
                        $amtOrig,
                        $currency,
                        $rate,
                        $amtBase,
                        $desc,
                        $date,
                        $jeId,
                        $_SESSION['user_id']
                    ]);

                $pdo->commit();
                echo json_encode(['ok' => true, 'msg' => 'تم تسجيل المصروف والقيد المحاسبي']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        } elseif ($act === 'cancel_expense') {
            requirePermission('finance.expenses', 'delete');
            $id = (int) $_POST['id'];
            $st = $pdo->prepare("SELECT * FROM `{$TE}` WHERE id=?");
            $st->execute([$id]);
            $exp = $st->fetch();
            if (!$exp)
                throw new Exception('المصروف غير موجود');
            if (($exp['status'] ?? 'active') === 'cancelled')
                throw new Exception('هذا المصروف ملغى مسبقاً');

            $pdo->beginTransaction();
            try {
                // ⚠ قيد عكسي (مش حذف) — نفس مبدأ إلغاء فاتورة الشراء/أمر
                // الصرف بالضبط: القيد الأصلي يضل موجود بدفتر اليومية
                // للأبد (سجل تاريخي)، وبنضيف قيد جديد يعكس تأثيره فقط
                if ($exp['journal_entry_id']) {
                    $origItems = $pdo->prepare("SELECT account_id, debit, credit, original_amount, base_amount, currency_id, exchange_rate FROM `{$TJI}` WHERE journal_entry_id=?");
                    $origItems->execute([$exp['journal_entry_id']]);
                    $lines = $origItems->fetchAll();

                    $origJe = $pdo->prepare("SELECT total_debit FROM `{$TJE}` WHERE id=?");
                    $origJe->execute([$exp['journal_entry_id']]);
                    $origTotal = (float) $origJe->fetchColumn();

                    $revNo = genEntryNo($pdo, $TJE);
                    $pdo->prepare("INSERT INTO `{$TJE}` (entry_number,entry_date,description,currency_id,
                        exchange_rate,total_debit,total_credit,status,reference_type,reference_id,created_by)
                        VALUES (?,?,?,?,1,?,?,'posted','expense_cancel',?,?)")
                        ->execute([
                            $revNo, date('Y-m-d'),
                            'إلغاء مصروف — عكس قيد رقم ' . $exp['journal_entry_id'],
                            $branchBaseCurrencyId, $origTotal, $origTotal, $id, $_SESSION['user_id']
                        ]);
                    $revId = (int) $pdo->lastInsertId();

                    foreach ($lines as $ln) {
                        $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,
                            original_amount,base_amount,description,currency_id,exchange_rate)
                            VALUES (?,?,?,?,?,?,?,?,?)")
                            ->execute([
                                $revId, $ln['account_id'],
                                $ln['credit'], $ln['debit'], // عكس الاتجاه
                                $ln['original_amount'], $ln['base_amount'],
                                'عكس — إلغاء مصروف', $ln['currency_id'], $ln['exchange_rate']
                            ]);
                        postAccountBalance($pdo, $TAC, (int) $ln['account_id'], (float) $ln['credit'], (float) $ln['debit']);
                    }
                }

                $pdo->prepare("UPDATE `{$TE}` SET status='cancelled', cancelled_at=NOW(), cancelled_by=? WHERE id=?")
                    ->execute([$_SESSION['user_id'], $id]);

                $pdo->commit();
                echo json_encode(['ok' => true, 'msg' => 'تم إلغاء المصروف وعكس القيد المحاسبي']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        } else
            throw new Exception('إجراء غير معروف');
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── بيانات الصفحة ──
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');
$accF = (int) ($_GET['acc'] ?? 0);
$search = trim($_GET['q'] ?? '');

$where = 'WHERE e.expense_date BETWEEN ? AND ?';
$params = [$dateFrom, $dateTo];
if ($accF) {
    $where .= ' AND e.expense_account_id=?';
    $params[] = $accF;
}
if ($search) {
    $where .= ' AND e.description LIKE ?';
    $params[] = "%{$search}%";
}

$expenses = $pdo->prepare("SELECT e.*,
    ea.code AS exp_code, ea.name AS exp_name,
    ca.code AS cash_code, ca.name AS cash_name
    FROM `{$TE}` e
    JOIN `{$TAC}` ea ON ea.id=e.expense_account_id
    JOIN `{$TAC}` ca ON ca.id=e.cash_account_id
    {$where}
    ORDER BY e.expense_date DESC, e.id DESC LIMIT 200");
$expenses->execute($params);
$expenses = $expenses->fetchAll();

// حسابات المصاريف (نوع expense)
$expAccounts = $pdo->query("SELECT id,code,name,level FROM `{$TAC}`
    WHERE account_type='expense' AND is_active=1 ORDER BY code")->fetchAll();

// حسابات الصندوق والبنك (asset level>=3 رموز 111x,112x)
// ⚠ حسابات الصندوق/البنك — هلق مِن الربط المحاسبي الفعلي المضبوط
// (invoice_account_settings، مفاتيح cash_*/bank_*)، مش تخمين حسب
// نمط كود الحساب (111x/112x) زي قبل — أدق وأصح، ومربوط مباشرة بنفس
// الإعدادات المستخدمة بباقي الوحدات (المستهلكات، المشتريات...).
// كل حساب معه رمز عملته المستخرج من اسم المفتاح نفسه (cash_usd → USD)
$cashSettings = $pdo->query("SELECT setting_key, account_id FROM `{$TAS}`
    WHERE setting_key LIKE 'cash_%' OR setting_key LIKE 'bank_%'")->fetchAll();
$cashAccounts = [];
$seenAccIds = [];
foreach ($cashSettings as $cs) {
    if (!$cs['account_id'] || isset($seenAccIds[$cs['account_id']]))
        continue; // نفس الحساب ممكن يتكرر (مثلاً cash_usd وbank_usd مختلفين، بس تحسباً)
    $accRow = $pdo->prepare("SELECT ac.id, ac.code, ac.name, cur.code AS currency_code, cur.symbol AS currency_symbol
        FROM `{$TAC}` ac LEFT JOIN currencies cur ON cur.id = ac.currency_id WHERE ac.id=? AND ac.is_active=1");
    $accRow->execute([$cs['account_id']]);
    $acc = $accRow->fetch();
    if ($acc) {
        $acc['is_cash'] = str_starts_with($cs['setting_key'], 'cash_');
        $cashAccounts[] = $acc;
        $seenAccIds[$cs['account_id']] = true;
    }
}
// ⚠ شبكة أمان: لو ولا مفتاح واحد مضبوط بعد بالإعدادات المحاسبية،
// نرجع مؤقتاً لأسلوب التخمين القديم (نمط الكود) بدل ما تطلع القائمة
// فاضية تماماً وتصير الصفحة غير قابلة للاستخدام
if (empty($cashAccounts)) {
    $fallbackCash = $pdo->query("SELECT ac.id, ac.code, ac.name, cur.code AS currency_code, cur.symbol AS currency_symbol
        FROM `{$TAC}` ac LEFT JOIN currencies cur ON cur.id = ac.currency_id
        WHERE ac.account_type='asset' AND ac.is_active=1 AND ac.level>=3
        AND (ac.code LIKE '111%' OR ac.code LIKE '112%') ORDER BY ac.code")->fetchAll();
    $cashAccounts = $fallbackCash;
}

try {
    $stats = $pdo->query("SELECT
        COUNT(*) AS total,
        COALESCE(SUM(amount_base),0) AS total_base,
        COALESCE(SUM(CASE WHEN expense_date>=DATE_FORMAT(NOW(),'%Y-%m-01') THEN amount_base END),0) AS month_base,
        COALESCE(SUM(CASE WHEN expense_date=CURDATE() THEN amount_base END),0) AS today_base
        FROM `{$TE}` WHERE status != 'cancelled' OR status IS NULL")->fetch();
} catch (Exception $e) {
    $stats = ['total' => 0, 'total_base' => 0, 'month_base' => 0, 'today_base' => 0];
}
// ⚠ كل العملات المفعّلة ديناميكياً من الجدول — بدل قائمة ثابتة بالكود
// كانت بتحصر الخيارات بثلاث عملات بس (USD/SYP/TRY)، حتى لو في عملات
// تانية مفعّلة فعلياً بجدول currencies (يورو مثلاً)
$allCurrencies = $pdo->query("SELECT code, symbol, exchange_rate FROM currencies WHERE status='active' ORDER BY is_base DESC, code")->fetchAll();
$CURR_SYM = [];
foreach ($allCurrencies as $c) {
    $CURR_SYM[$c['code']] = $c['symbol'];
}
$branchBaseCurrencySymbol = $CURR_SYM[$branchBaseCurrency] ?? '$';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>المصاريف — <?= htmlspecialchars($branchName) ?></title>
    <link rel="icon" href="<?= BASE_PATH ?>/assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/assets/css/layout.css" rel="stylesheet">
    <style>
        .stat-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 10px
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0
        }

        .stat-val {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1e293b;
            line-height: 1
        }

        .stat-lbl {
            font-size: .7rem;
            color: #64748b;
            margin-top: 2px
        }

        .tbl-wrap {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            overflow: hidden
        }

        .tbl-hdr {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap
        }

        table.mtbl {
            width: 100%;
            border-collapse: collapse;
            font-size: .82rem
        }

        table.mtbl th {
            background: #f8fafc;
            padding: 8px 12px;
            font-weight: 600;
            color: #64748b;
            font-size: .72rem;
            border-bottom: 1px solid #f1f5f9
        }

        table.mtbl td {
            padding: 7px 12px;
            border-bottom: 1px solid #f8fafc;
            vertical-align: middle
        }

        table.mtbl tr:last-child td {
            border-bottom: none
        }

        table.mtbl tr:hover td {
            background: #fffbeb
        }

        .act-btn {
            width: 27px;
            height: 27px;
            border-radius: 7px;
            border: 1px solid #e2e8f0;
            background: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .78rem;
            color: #64748b;
            cursor: pointer;
            transition: all .12s
        }

        .act-btn:hover {
            background: #f1f5f9
        }

        .act-btn.danger:hover {
            background: #fee2e2;
            color: #dc2626;
            border-color: #fca5a5
        }

        .field-lbl {
            font-size: .76rem;
            font-weight: 700;
            color: #475569;
            margin-bottom: 4px;
            display: block
        }

        .req {
            color: #dc2626
        }

        .n {
            font-variant-numeric: tabular-nums
        }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-wallet2 me-1 text-warning"></i>المصاريف التشغيلية</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
            <a href="consumables.php" style="color:#64748b;text-decoration:none">المصاريف والمستهلكات</a><i
                class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
            <span class="text-warning">إدارة المصاريف</span>
        </nav>
    </header>
    <main class="main-content">
        <div class="content-body">

            <!-- التبويبات -->
            <ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e2e8f0">
                <li class="nav-item"><a class="nav-link fw-600" href="consumables.php"
                        style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-box-seam me-1"></i>المواد
                        الاستهلاكية</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="consumable_purchases.php"
                        style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-cart-plus me-1"></i>فواتير
                        الشراء</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="consumable_issues.php"
                        style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-arrow-bar-up me-1"></i>صرف
                        المستهلكات</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="../inventory/warehouse.php?type=consumables"
                        style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-building me-1"></i>مستودعات
                        المستهلكات</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="../inventory/movements.php?tab=consumables"
                        style="border:none;color:#64748b;font-size:.83rem"><i
                            class="bi bi-arrow-left-right me-1"></i>حركة المستهلكات</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="consumable_transfers.php"
                        style="border:none;color:#64748b;font-size:.83rem"><i
                            class="bi bi-signpost-split me-1"></i>مناقلة بين المستودعات</a></li>
                <li class="nav-item"><a class="nav-link fw-600 active" href="#"
                        style="border:none;border-bottom:2px solid #f59e0b;color:#f59e0b;font-size:.83rem;margin-bottom:-2px"><i
                            class="bi bi-wallet2 me-1"></i>إدارة المصاريف</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="../purchases/suppliers.php?tab=consumables"
                        style="border:none;color:#64748b;font-size:.83rem"><i
                            class="bi bi-people me-1"></i>موردو المستهلكات</a></li>
            </ul>

            <!-- إحصائيات -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fef3c7"><i class="bi bi-wallet2 text-warning"></i>
                        </div>
                        <div>
                            <div class="stat-val"><?= $stats['total'] ?></div>
                            <div class="stat-lbl">إجمالي السجلات</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fee2e2"><i class="bi bi-calendar-day text-danger"></i>
                        </div>
                        <div>
                            <div class="stat-val n"><?= htmlspecialchars($branchBaseCurrencySymbol) ?> <?= number_format($stats['today_base'], 2) ?></div>
                            <div class="stat-lbl">مصاريف اليوم</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fee2e2"><i
                                class="bi bi-calendar-month text-danger"></i></div>
                        <div>
                            <div class="stat-val n"><?= htmlspecialchars($branchBaseCurrencySymbol) ?> <?= number_format($stats['month_base'], 2) ?></div>
                            <div class="stat-lbl">مصاريف الشهر</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fee2e2"><i
                                class="bi bi-currency-dollar text-danger"></i></div>
                        <div>
                            <div class="stat-val n"><?= htmlspecialchars($branchBaseCurrencySymbol) ?> <?= number_format($stats['total_base'], 2) ?></div>
                            <div class="stat-lbl">الإجمالي الكلي</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- فلاتر -->
            <div class="tbl-wrap mb-3">
                <div class="tbl-hdr">
                    <form method="get" class="d-flex gap-2 flex-wrap align-items-center w-100">
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                            placeholder="بحث في الوصف..." class="form-control form-control-sm"
                            style="width:160px;border-radius:8px">
                        <select name="acc" class="form-select form-select-sm" style="width:180px;border-radius:8px"
                            onchange="this.form.submit()">
                            <option value="">كل حسابات المصاريف</option>
                            <?php foreach ($expAccounts as $a): ?>
                                <option value="<?= $a['id'] ?>" <?= $accF == $a['id'] ? 'selected' : '' ?>>
                                    <?= str_repeat('  ', $a['level'] - 1) ?>
                                    <?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>"
                            class="form-control form-control-sm" style="width:140px;border-radius:8px">
                        <span style="color:#94a3b8">—</span>
                        <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>"
                            class="form-control form-control-sm" style="width:140px;border-radius:8px">
                        <button type="submit" class="btn btn-sm btn-primary" style="border-radius:8px"><i
                                class="bi bi-search me-1"></i>بحث</button>
                        <?php if ($search || $accF): ?>
                            <a href="?from=<?= $dateFrom ?>&to=<?= $dateTo ?>" class="btn btn-sm btn-light"
                                style="border-radius:8px"><i class="bi bi-x-lg"></i></a>
                        <?php endif; ?>
                    </form>
                    <button class="btn btn-sm fw-600"
                        style="border-radius:8px;background:#d97706;color:#fff;font-size:.82rem;border:none;white-space:nowrap"
                        onclick="openNew()">
                        <i class="bi bi-plus-lg me-1"></i>تسجيل مصروف
                    </button>
                </div>
            </div>

            <!-- الجدول -->
            <div class="tbl-wrap">
                <div class="table-responsive">
                    <table class="mtbl" id="expensesTbl">
                        <thead>
                            <tr>
                                <th>التاريخ</th>
                                <th style="color:#dc2626">حساب المصروف</th>
                                <th>الوصف</th>
                                <th style="color:#16a34a">حساب الدفع</th>
                                <th>العملة</th>
                                <th class="text-end">المبلغ</th>
                                <th class="text-end">بعملة الفرع (<?= htmlspecialchars($branchBaseCurrencySymbol) ?>)</th>
                                <th>القيد</th>
                                <th style="text-align:center" data-no-sort>إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($expenses)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-5">
                                        <i class="bi bi-wallet2 d-block mb-2" style="font-size:2rem;opacity:.2"></i>
                                        لا توجد مصاريف في هذه الفترة
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($expenses as $exp):
                                $sym = $CURR_SYM[$exp['currency']] ?? '$';
                                ?>
                                <tr style="<?= ($exp['status'] ?? 'active') === 'cancelled' ? 'opacity:.55' : '' ?>">
                                    <td class="text-muted"><?= $exp['expense_date'] ?></td>
                                    <td>
                                        <div class="fw-600" style="font-size:.8rem;color:#dc2626">
                                            <?= htmlspecialchars($exp['exp_name']) ?>
                                        </div>
                                        <div style="font-size:.7rem;color:#94a3b8" dir="ltr">
                                            <?= htmlspecialchars($exp['exp_code']) ?>
                                        </div>
                                    </td>
                                    <td style="font-size:.8rem;color:#475569">
                                        <?= htmlspecialchars($exp['description'] ?? '—') ?>
                                    </td>
                                    <td>
                                        <div style="font-size:.78rem;color:#16a34a"><?= htmlspecialchars($exp['cash_name']) ?></div>
                                        <div style="font-size:.7rem;color:#94a3b8" dir="ltr">
                                            <?= htmlspecialchars($exp['cash_code']) ?>
                                        </div>
                                    </td>
                                    <td style="font-size:.75rem"><?= $exp['currency'] ?></td>
                                    <td class="n text-end fw-600"><?= $sym ?>
                                        <?= number_format($exp['amount_original'], 2) ?>
                                    </td>
                                    <td class="n text-end fw-600 text-danger"><?= htmlspecialchars($branchBaseCurrencySymbol) ?> <?= number_format($exp['amount_base'], 2) ?>
                                    </td>
                                    <td>
                                        <?php if (($exp['status'] ?? 'active') === 'cancelled'): ?>
                                            <span class="badge bg-danger-subtle text-danger" style="font-size:.65rem">
                                                <i class="bi bi-x-circle me-1"></i>ملغى
                                            </span>
                                        <?php elseif ($exp['journal_entry_id']): ?>
                                            <span class="badge bg-success-subtle text-success" style="font-size:.65rem">
                                                <i class="bi bi-check me-1"></i>قيد مرحّل
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary"
                                                style="font-size:.65rem">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex justify-content-center">
                                            <?php if (($exp['status'] ?? 'active') !== 'cancelled'): ?>
                                                <button class="act-btn danger" onclick="cancelExpense(<?= $exp['id'] ?>)"
                                                    title="إلغاء وعكس القيد">
                                                    <i class="bi bi-x-circle"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if (!empty($expenses)): ?>
                            <tfoot>
                                <tr style="background:#fef9ee">
                                    <td colspan="6" class="fw-600 text-end" style="font-size:.8rem;color:#64748b">الإجمالي
                                        (الفترة المحددة)</td>
                                    <td class="n fw-600 text-end text-danger">
                                        <?= htmlspecialchars($branchBaseCurrencySymbol) ?> <?= number_format(array_sum(array_column(array_filter($expenses, fn($e) => ($e['status'] ?? 'active') !== 'cancelled'), 'amount_base')), 2) ?>
                                    </td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <!-- مودال تسجيل مصروف -->
    <div class="modal fade" id="expModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#92400e,#d97706);border-radius:16px 16px 0 0">
                    <h6 class="modal-title text-white fw-700 mb-0">
                        <i class="bi bi-wallet2 me-2"></i>تسجيل مصروف جديد
                    </h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-4">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="field-lbl">حساب المصروف <span class="req">*</span></label>
                            <select id="eExpAcc" class="form-select form-select-sm">
                                <option value="">— اختر حساب المصروف —</option>
                                <?php foreach ($expAccounts as $a): ?>
                                    <option value="<?= $a['id'] ?>">
                                        <?= str_repeat('  ', $a['level'] - 1) ?>
                                        <?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="field-lbl">حساب الدفع <span class="req">*</span></label>
                            <select id="eCashAcc" class="form-select form-select-sm">
                                <option value="">— الصندوق/البنك —</option>
                                <?php foreach ($cashAccounts as $a): ?>
                                    <option value="<?= $a['id'] ?>" data-currency="<?= htmlspecialchars($a['currency_code'] ?? '') ?>">
                                        <?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?><?= $a['currency_code'] ? ' (' . htmlspecialchars($a['currency_code']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="field-lbl">التاريخ <span class="req">*</span></label>
                            <input type="date" id="eDate" class="form-control form-control-sm"
                                value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="field-lbl">العملة</label>
                            <select id="eCurr" class="form-select form-select-sm" onchange="onExpCurrChange()">
                                <?php foreach ($allCurrencies as $c): ?>
                                    <option value="<?= htmlspecialchars($c['code']) ?>" data-rate="<?= $c['exchange_rate'] ?>">
                                        <?= htmlspecialchars($c['code']) ?> <?= htmlspecialchars($c['symbol']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3" id="eRateWrap">
                            <label class="field-lbl">سعر الصرف</label>
                            <input type="number" id="eRate" class="form-control form-control-sm" value="1" min="0.0001"
                                step="0.01" dir="ltr" oninput="calcUsd()">
                        </div>
                        <div class="col-md-3">
                            <label class="field-lbl">المبلغ <span class="req">*</span></label>
                            <input type="number" id="eAmt" class="form-control form-control-sm fw-600" min="0"
                                step="0.01" dir="ltr" placeholder="0.00" oninput="calcUsd()">
                        </div>
                        <div class="col-12">
                            <label class="field-lbl">وصف المصروف</label>
                            <input type="text" id="eDesc" class="form-control form-control-sm"
                                placeholder="مثال: فاتورة كهرباء شهر يونيو">
                        </div>
                        <div class="col-12">
                            <div
                                style="background:#fef9ee;border-radius:8px;padding:10px 16px;font-size:.8rem;display:flex;justify-content:space-between;align-items:center">
                                <span style="color:#92400e"><i class="bi bi-info-circle me-1"></i>المبلغ
                                    بعملة الفرع:</span>
                                <span id="eUsdPreview" class="n fw-600 text-danger"><?= htmlspecialchars($branchBaseCurrencySymbol) ?> 0.00</span>
                            </div>
                        </div>
                        <div class="col-12">
                            <div
                                style="background:#f0fdf4;border-radius:8px;padding:10px 16px;font-size:.78rem;color:#065f46">
                                <i class="bi bi-journal-check me-1"></i>
                                سيتم إنشاء قيد محاسبي تلقائي ومرحّل فور الحفظ
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button class="btn btn-sm btn-light" style="border-radius:8px"
                        data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-sm fw-600"
                        style="border-radius:8px;background:#d97706;color:#fff;min-width:120px;border:none"
                        onclick="saveExpense()" id="btnSaveExp">
                        <span id="saveExpTxt"><i class="bi bi-floppy me-1"></i>حفظ وترحيل</span>
                        <span id="saveExpSpin" class="spinner-border spinner-border-sm" style="display:none"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= BASE_PATH ?>/assets/js/sidebar.js"></script>
    <script>
        const expModal = new bootstrap.Modal(document.getElementById('expModal'));
        const BASE_CUR_CODE = <?= json_encode($branchBaseCurrency) ?>;
        const BASE_CUR_SYM = <?= json_encode($branchBaseCurrencySymbol) ?>;
        const CASH_ACCOUNTS = <?= json_encode(array_values($cashAccounts)) ?>;

        function post(data) {
            const fd = new FormData();
            Object.entries(data).forEach(([k, v]) => fd.append(k, v ?? ''));
            return fetch(location.href, { method: 'POST', body: fd }).then(r => r.json());
        }
        function toast(msg, type = 'success') {
            const t = document.createElement('div'); t.className = `alert alert-${type} shadow`;
            t.style.cssText = 'position:fixed;top:70px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:240px;text-align:center;font-size:.83rem;padding:.5rem 1.2rem';
            t.innerHTML = `<i class="bi bi-${type === 'success' ? 'check-circle-fill text-success' : 'exclamation-triangle-fill text-danger'} me-2"></i>${msg}`;
            document.body.appendChild(t); setTimeout(() => t.remove(), 3500);
        }

        function openNew() {
            document.getElementById('eExpAcc').value = '';
            document.getElementById('eDate').value = new Date().toISOString().split('T')[0];
            document.getElementById('eCurr').value = BASE_CUR_CODE;
            document.getElementById('eAmt').value = '';
            document.getElementById('eDesc').value = '';
            document.getElementById('eUsdPreview').textContent = BASE_CUR_SYM + ' 0.00';
            onExpCurrChange();
            expModal.show();
            setTimeout(() => document.getElementById('eExpAcc').focus(), 300);
        }

        // ⚠ العملة هي يلي بتحدد حسابات الصندوق/البنك المتاحة، مش العكس —
        // ما بيصح تختار "صندوق ليرة سورية" وتسجّل المبلغ بعملة الدولار.
        // كل حساب صندوق/بنك مربوط بعملة واحدة ثابتة (نفس عملته الفعلية
        // بشجرة الحسابات)، فالقائمة هلق بتتفلتر تلقائياً حسب العملة
        // المختارة، وسعر الصرف بينقفل تماماً (مش بس يعتم) لو العملة
        // نفس عملة الفرع الأساسية (سعرها الوحيد المنطقي = 1)
        function onExpCurrChange() {
            const sel = document.getElementById('eCurr');
            const currCode = sel.value;
            const isBase = currCode === BASE_CUR_CODE;

            const rateInput = document.getElementById('eRate');
            document.getElementById('eRateWrap').style.opacity = isBase ? .4 : 1;
            rateInput.disabled = isBase;
            if (isBase) {
                rateInput.value = '1';
            } else {
                // ⚠ تعبئة تلقائية بسعر الصرف المسجّل بجدول العملات كنقطة
                // بداية مريحة — المستخدم لسا قادر يعدّله يدوياً لو السعر
                // الفعلي بالفاتورة مختلف عن السعر العام لهيك اليوم
                const opt = sel.options[sel.selectedIndex];
                const rate = parseFloat(opt?.dataset.rate) || 1;
                rateInput.value = rate;
            }

            // إعادة بناء قائمة حسابات الصندوق/البنك — بس الحسابات
            // المطابقة لعملة نفس المُختارة
            const cashSel = document.getElementById('eCashAcc');
            const prevValue = cashSel.value;
            const matching = CASH_ACCOUNTS.filter(a => a.currency_code === currCode);
            cashSel.innerHTML = '<option value="">— الصندوق/البنك —</option>' +
                matching.map(a => `<option value="${a.id}">${a.code} — ${a.name}${a.currency_code ? ' (' + a.currency_code + ')' : ''}</option>`).join('');
            // نحافظ على نفس الاختيار لو ضل صالح بعد الفلترة، غير هيك نصفّره
            if (matching.some(a => String(a.id) === prevValue)) {
                cashSel.value = prevValue;
            } else {
                cashSel.value = '';
                if (!matching.length) {
                    toast('⚠ ما في حساب صندوق/بنك مضبوط بعملة ' + currCode + ' بعد — راجع الربط المحاسبي', 'danger');
                }
            }

            calcUsd();
        }

        function calcUsd() {
            const amt = parseFloat(document.getElementById('eAmt').value || 0);
            const rate = parseFloat(document.getElementById('eRate').value || 1);
            document.getElementById('eUsdPreview').textContent = BASE_CUR_SYM + ' ' + (amt / rate).toFixed(2);
        }

        function saveExpense() {
            const expAcc = document.getElementById('eExpAcc').value;
            const cashAcc = document.getElementById('eCashAcc').value;
            const amt = parseFloat(document.getElementById('eAmt').value || 0);
            if (!expAcc) { toast('يجب اختيار حساب المصروف', 'danger'); return; }
            if (!cashAcc) { toast('يجب اختيار حساب الدفع', 'danger'); return; }
            if (amt <= 0) { toast('يجب إدخال مبلغ أكبر من صفر', 'danger'); return; }

            const btn = document.getElementById('btnSaveExp');
            document.getElementById('saveExpTxt').style.opacity = '0';
            document.getElementById('saveExpSpin').style.display = 'inline-block';
            btn.disabled = true;

            post({
                _action: 'save_expense',
                expense_account_id: expAcc,
                cash_account_id: cashAcc,
                amount_original: amt,
                currency: document.getElementById('eCurr').value,
                exchange_rate: document.getElementById('eRate').value,
                expense_date: document.getElementById('eDate').value,
                description: document.getElementById('eDesc').value,
            }).then(d => {
                document.getElementById('saveExpTxt').style.opacity = '1';
                document.getElementById('saveExpSpin').style.display = 'none';
                btn.disabled = false;
                if (d.ok) { toast('✅ ' + d.msg); expModal.hide(); setTimeout(() => location.reload(), 700); }
                else toast(d.msg, 'danger');
            });
        }

        function cancelExpense(id) {
            if (!confirm('إلغاء هذا المصروف؟\nسيبقى السجل ظاهراً بحالة "ملغى"، ويُنشأ قيد عكسي جديد (القيد الأصلي لا يُحذف).')) return;
            post({ _action: 'cancel_expense', id }).then(d => {
                if (d.ok) { toast(d.msg); setTimeout(() => location.reload(), 600); }
                else toast(d.msg, 'danger');
            });
        }
    
        // ══════════════════════════════════════════════════════════
        // فرز الجداول بالنقر على رأس العمود — عام لأي جدول بالصفحة
        // ══════════════════════════════════════════════════════════
        function makeSortable(table) {
            if (!table) return;
            const headers = table.querySelectorAll('thead th');
            headers.forEach((th, colIndex) => {
                if (th.hasAttribute('data-no-sort')) return;
                th.style.cursor = 'pointer';
                th.style.userSelect = 'none';
                th.title = 'اضغط للفرز';
                th.addEventListener('click', () => sortTableByColumn(table, colIndex, th));
            });
        }

        function sortTableByColumn(table, colIndex, th) {
            const tbody = table.querySelector('tbody');
            if (!tbody) return;
            const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.children.length > colIndex);
            const isAsc = th.getAttribute('data-sort-dir') !== 'asc';

            table.querySelectorAll('thead th').forEach(h => {
                h.removeAttribute('data-sort-dir');
                const ind = h.querySelector('.sort-ind');
                if (ind) ind.remove();
            });
            th.setAttribute('data-sort-dir', isAsc ? 'asc' : 'desc');

            const getCellValue = (row) => (row.children[colIndex]?.innerText || '').trim();

            // ⚠ ترتيب فحص القيمة مهم: تاريخ أولاً (YYYY-MM-DD) — لأنه
            // parseFloat على "2026-07-21" كان بيرجّع 2026 بس (بيوقف
            // عند أول شرطة)، فكل تواريخ نفس السنة كانت تطلع "متساوية"
            // ومفيش فرز فعلي بينهم. بعدين رقم صرف (تحقق مطابقة كاملة
            // للنص، مش بس بادئة)، وأخيراً نص عربي عادي.
            const isoDateRe = /^\d{4}-\d{2}-\d{2}/;
            rows.sort((a, b) => {
                const valA = getCellValue(a), valB = getCellValue(b);
                if (isoDateRe.test(valA) && isoDateRe.test(valB)) {
                    const dA = new Date(valA.slice(0, 10)).getTime();
                    const dB = new Date(valB.slice(0, 10)).getTime();
                    return isAsc ? dA - dB : dB - dA;
                }
                const cleanA = valA.replace(/[^0-9.\-]/g, '');
                const cleanB = valB.replace(/[^0-9.\-]/g, '');
                const fullyNumeric = /^-?[0-9]+(\.[0-9]+)?$/.test(cleanA) && /^-?[0-9]+(\.[0-9]+)?$/.test(cleanB)
                    && cleanA !== '' && cleanB !== '' && cleanA !== '-' && cleanB !== '-';
                if (fullyNumeric) {
                    return isAsc ? parseFloat(cleanA) - parseFloat(cleanB) : parseFloat(cleanB) - parseFloat(cleanA);
                }
                return isAsc ? valA.localeCompare(valB, 'ar') : valB.localeCompare(valA, 'ar');
            });

            rows.forEach(row => tbody.appendChild(row));

            const ind = document.createElement('i');
            ind.className = 'bi bi-caret-' + (isAsc ? 'up' : 'down') + '-fill sort-ind';
            ind.style.cssText = 'font-size:.65rem;margin-right:4px';
            th.appendChild(ind);
        }

        makeSortable(document.getElementById('expensesTbl'));
    </script>
</body>

</html>