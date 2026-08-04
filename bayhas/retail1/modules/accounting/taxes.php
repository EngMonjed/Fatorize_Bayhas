<?php
/**
 * accounting/taxes.php — إدارة الضرائب والرسوم
 * المسار: retail1/modules/accounting/taxes.php
 *
 * قسمين: (١) إعدادات أنواع الضرائب (مبيعات/مشتريات/استقطاع) — بنية
 * جاهزة للمستقبل، بدون فرض تفعيلها فوراً. (٢) الرسوم الثابتة/الدورية
 * (رخص، اشتراكات...) مع تتبّع الاستحقاق والتسديد وقيد محاسبي تلقائي.
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('finance.taxes', 'view');
$currentModule = 'finance.taxes';

$TS = $_SESSION['table_suffix'];
$TTT = "tax_types_{$TS}";
$TFF = "fixed_fees_{$TS}";
$TAC = "account_charts_{$TS}";
$TIAS = "invoice_account_settings_{$TS}";
$TJE = "journal_entries_{$TS}";
$TJI = "journal_entry_items_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

function genEntryNo(PDO $pdo, string $table): string
{
    $y = date('Y');
    $last = $pdo->query("SELECT entry_number FROM `{$table}`
        WHERE entry_number LIKE 'JE-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $seq = $last ? (int) substr($last, -4) + 1 : 1;
    return 'JE-' . $y . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        // ══════════ أنواع الضرائب ══════════
        if ($act === 'save_tax_type') {
            requirePermission('finance.taxes', $_POST['id'] ? 'edit' : 'create');
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $scope = $_POST['tax_scope'] ?? '';
            $calcType = $_POST['calc_type'] ?? 'percentage';
            $rate = (float) ($_POST['rate_value'] ?? 0);
            $accId = (int) ($_POST['account_id'] ?? 0) ?: null;
            $notes = trim($_POST['notes'] ?? '');

            if (!$name)
                throw new Exception('اسم الضريبة مطلوب');
            if (!in_array($scope, ['sales', 'purchase', 'withholding']))
                throw new Exception('نوع الضريبة غير صالح');
            if ($rate < 0)
                throw new Exception('القيمة لا يمكن أن تكون سالبة');
            if ($calcType === 'percentage' && $rate > 100)
                throw new Exception('النسبة المئوية لا يمكن أن تتجاوز ١٠٠٪');

            if ($id) {
                $pdo->prepare("UPDATE `{$TTT}` SET name=?,tax_scope=?,calc_type=?,rate_value=?,account_id=?,notes=?,updated_at=NOW() WHERE id=?")
                    ->execute([$name, $scope, $calcType, $rate, $accId, $notes, $id]);
            } else {
                $pdo->prepare("INSERT INTO `{$TTT}` (name,tax_scope,calc_type,rate_value,account_id,notes,created_by) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$name, $scope, $calcType, $rate, $accId, $notes, $_SESSION['user_id']]);
            }
            echo json_encode(['ok' => true]);
        } elseif ($act === 'toggle_tax_active') {
            requirePermission('finance.taxes', 'edit');
            $id = (int) $_POST['id'];
            $st = $pdo->prepare("SELECT is_active FROM `{$TTT}` WHERE id=?");
            $st->execute([$id]);
            $cur = $st->fetchColumn();
            if ($cur === false)
                throw new Exception('غير موجود');
            $new = $cur ? 0 : 1;
            $pdo->prepare("UPDATE `{$TTT}` SET is_active=? WHERE id=?")->execute([$new, $id]);
            echo json_encode(['ok' => true, 'active' => (bool) $new]);
        } elseif ($act === 'delete_tax_type') {
            requirePermission('finance.taxes', 'delete');
            $id = (int) $_POST['id'];
            $pdo->prepare("DELETE FROM `{$TTT}` WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true]);
        } elseif ($act === 'get_tax_type') {
            $id = (int) $_POST['id'];
            $st = $pdo->prepare("SELECT * FROM `{$TTT}` WHERE id=?");
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row)
                throw new Exception('غير موجود');
            echo json_encode(['ok' => true, 'data' => $row]);
        }

        // ══════════ الرسوم الثابتة ══════════
        elseif ($act === 'save_fee') {
            requirePermission('finance.taxes', $_POST['id'] ? 'edit' : 'create');
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $amount = (float) ($_POST['amount'] ?? 0);
            $currId = (int) ($_POST['currency_id'] ?? 0);
            $dueDate = $_POST['due_date'] ?? date('Y-m-d');
            $recurrence = $_POST['recurrence'] ?? 'once';
            $expAccId = (int) ($_POST['expense_account_id'] ?? 0) ?: null;
            $notes = trim($_POST['notes'] ?? '');

            if (!$name)
                throw new Exception('اسم الرسم مطلوب');
            if ($amount <= 0)
                throw new Exception('المبلغ يجب أن يكون أكبر من صفر');
            if (!$currId)
                throw new Exception('العملة مطلوبة');
            if (!in_array($recurrence, ['once', 'monthly', 'yearly']))
                throw new Exception('نوع التكرار غير صالح');

            if ($id) {
                $pdo->prepare("UPDATE `{$TFF}` SET name=?,amount=?,currency_id=?,due_date=?,recurrence=?,expense_account_id=?,notes=?,updated_at=NOW() WHERE id=? AND status='pending'")
                    ->execute([$name, $amount, $currId, $dueDate, $recurrence, $expAccId, $notes, $id]);
            } else {
                $pdo->prepare("INSERT INTO `{$TFF}` (name,amount,currency_id,due_date,recurrence,expense_account_id,notes,status,created_by) VALUES (?,?,?,?,?,?,?,'pending',?)")
                    ->execute([$name, $amount, $currId, $dueDate, $recurrence, $expAccId, $notes, $_SESSION['user_id']]);
            }
            echo json_encode(['ok' => true]);
        } elseif ($act === 'get_fee') {
            $id = (int) $_POST['id'];
            $st = $pdo->prepare("SELECT * FROM `{$TFF}` WHERE id=?");
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row)
                throw new Exception('غير موجود');
            echo json_encode(['ok' => true, 'data' => $row]);
        } elseif ($act === 'delete_fee') {
            requirePermission('finance.taxes', 'delete');
            $id = (int) $_POST['id'];
            $st = $pdo->prepare("SELECT status FROM `{$TFF}` WHERE id=?");
            $st->execute([$id]);
            if ($st->fetchColumn() === 'paid')
                throw new Exception('لا يمكن حذف رسم تم تسديده — له قيد محاسبي مرتبط');
            $pdo->prepare("DELETE FROM `{$TFF}` WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true]);
        }

        // ── تسديد رسم: يعمل قيد محاسبي (مدين مصروف الرسم، دائن الصندوق) ويرحّله فوراً ──
        elseif ($act === 'pay_fee') {
            requirePermission('finance.taxes', 'create');
            $id = (int) $_POST['id'];
            $cashAccId = (int) ($_POST['cash_account_id'] ?? 0);
            $payDate = $_POST['pay_date'] ?? date('Y-m-d');

            $fee = $pdo->prepare("SELECT ff.*, c.code AS cur_code, c.exchange_rate AS cur_rate
                FROM `{$TFF}` ff LEFT JOIN currencies c ON c.id=ff.currency_id WHERE ff.id=?");
            $fee->execute([$id]);
            $fee = $fee->fetch();
            if (!$fee)
                throw new Exception('الرسم غير موجود');
            if ($fee['status'] === 'paid')
                throw new Exception('هذا الرسم مسدَّد أصلاً');
            if (!$cashAccId)
                throw new Exception('يجب اختيار حساب الصندوق/البنك للتسديد');

            $expAccId = (int) ($fee['expense_account_id'] ?: 0);
            if (!$expAccId)
                throw new Exception('لازم تحدّد حساب المصروف لهذا الرسم أول (عدّل الرسم وحدّده)');

            $cashAcc = $pdo->prepare("SELECT * FROM `{$TAC}` WHERE id=?");
            $cashAcc->execute([$cashAccId]);
            $cashAcc = $cashAcc->fetch();
            if (!$cashAcc)
                throw new Exception('حساب الصندوق غير موجود');

            $rate = max(0.0001, (float) ($fee['cur_rate'] ?: 1));
            $amountBase = (float) $fee['amount'] / $rate;

            $pdo->beginTransaction();
            try {
                $jeNo = genEntryNo($pdo, $TJE);
                $pdo->prepare("INSERT INTO `{$TJE}` (entry_number,entry_date,description,currency_id,
                    exchange_rate,total_debit,total_credit,status,reference_type,created_by)
                    VALUES (?,?,?,?,?,?,?,'draft','fee',?)")
                    ->execute([$jeNo, $payDate, "تسديد رسم: {$fee['name']}", $fee['currency_id'], $rate, $amountBase, $amountBase, $_SESSION['user_id']]);
                $jeId = (int) $pdo->lastInsertId();

                $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,
                    original_amount,base_amount,description,currency_id,exchange_rate)
                    VALUES (?,?,?,0,?,?,?,?,?)")
                    ->execute([$jeId, $expAccId, $amountBase, $fee['amount'], $amountBase, "رسم — {$jeNo}", $fee['currency_id'], $rate]);
                $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,
                    original_amount,base_amount,description,currency_id,exchange_rate)
                    VALUES (?,?,0,?,?,?,?,?,?)")
                    ->execute([$jeId, $cashAccId, $amountBase, $fee['amount'], $amountBase, "تسديد رسم — {$jeNo}", $fee['currency_id'], $rate]);

                $items = $pdo->prepare("SELECT * FROM `{$TJI}` WHERE journal_entry_id=?");
                $items->execute([$jeId]);
                foreach ($items->fetchAll() as $item) {
                    $net = $item['debit'] - $item['credit'];
                    $ownNet = ((float) $item['debit'] > 0) ? (float) $item['original_amount'] : -(float) $item['original_amount'];
                    $pdo->prepare("UPDATE `{$TAC}` SET
                            base_balance=base_balance+?,
                            balance=balance+(CASE WHEN currency_id=? THEN ? ELSE ? END),
                            updated_at=NOW()
                        WHERE id=?")
                        ->execute([$net, $item['currency_id'], $ownNet, $net, $item['account_id']]);
                }
                $pdo->prepare("UPDATE `{$TJE}` SET status='posted',posted_at=NOW(),posted_by=? WHERE id=?")
                    ->execute([$_SESSION['user_id'], $jeId]);

                $pdo->prepare("UPDATE `{$TFF}` SET status='paid',cash_account_id=?,journal_entry_id=?,paid_at=NOW() WHERE id=?")
                    ->execute([$cashAccId, $jeId, $id]);

                if ($fee['recurrence'] !== 'once') {
                    $nextDue = $fee['recurrence'] === 'monthly'
                        ? date('Y-m-d', strtotime($fee['due_date'] . ' +1 month'))
                        : date('Y-m-d', strtotime($fee['due_date'] . ' +1 year'));
                    $pdo->prepare("INSERT INTO `{$TFF}` (name,amount,currency_id,due_date,recurrence,expense_account_id,notes,status,created_by)
                        VALUES (?,?,?,?,?,?,?,'pending',?)")
                        ->execute([$fee['name'], $fee['amount'], $fee['currency_id'], $nextDue, $fee['recurrence'], $expAccId, $fee['notes'], $_SESSION['user_id']]);
                }

                $pdo->commit();
                echo json_encode(['ok' => true, 'no' => $jeNo, 'msg' => 'تم التسديد وترحيل القيد']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        elseif ($act === 'save_tab_order') {
            $order = json_decode($_POST['order'] ?? '[]', true);
            if (!is_array($order))
                throw new Exception('ترتيب غير صالح');
            $order = array_values(array_intersect($order, $defaultTabOrder));
            if (empty($order))
                throw new Exception('ترتيب فاضي');
            $pdo->prepare("INSERT INTO user_tab_order (user_id, page_group, tab_order, updated_at)
                VALUES (?, 'finance', ?, NOW())
                ON DUPLICATE KEY UPDATE tab_order=VALUES(tab_order), updated_at=NOW()")
                ->execute([$_SESSION['user_id'], json_encode($order, JSON_UNESCAPED_UNICODE)]);
            echo json_encode(['ok' => true]);
        } else
            throw new Exception('إجراء غير معروف');
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

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
}

$baseSym = '$';
$baseCurId = 1;
if (!empty($_SESSION['branch_id'])) {
    $bcStmt = $pdo->prepare("SELECT c.id, c.symbol FROM branches b
        LEFT JOIN currencies c ON c.id = b.base_currency_id WHERE b.id = ?");
    $bcStmt->execute([$_SESSION['branch_id']]);
    $bcRow = $bcStmt->fetch();
    if ($bcRow) {
        $baseSym = $bcRow['symbol'] ?: '$';
        $baseCurId = $bcRow['id'] ?: 1;
    }
}

$currenciesList = $pdo->query("SELECT id,code,name,symbol,is_base FROM currencies WHERE status='active' ORDER BY is_base DESC,id")->fetchAll();
$accountsList = $pdo->query("SELECT id,code,name,account_type FROM `{$TAC}` WHERE is_active=1 ORDER BY code")->fetchAll();
$cashAccounts = $pdo->query("SELECT ac.id,ac.code,ac.name,c.code AS cur_code
    FROM `{$TIAS}` ias JOIN `{$TAC}` ac ON ac.id=ias.account_id LEFT JOIN currencies c ON c.id=ac.currency_id
    WHERE (ias.setting_key LIKE 'cash_%' OR ias.setting_key LIKE 'bank_%') AND ac.is_active=1
    ORDER BY ac.code")->fetchAll();

try {
    $taxTypes = $pdo->query("SELECT tt.*, ac.code AS acc_code, ac.name AS acc_name
        FROM `{$TTT}` tt LEFT JOIN `{$TAC}` ac ON ac.id=tt.account_id ORDER BY tt.tax_scope, tt.name")->fetchAll();
} catch (Exception $e) {
    $taxTypes = [];
}
try {
    $fees = $pdo->query("SELECT ff.*, c.code AS cur_code, c.symbol AS cur_sym, ea.code AS exp_code, ea.name AS exp_name
        FROM `{$TFF}` ff LEFT JOIN currencies c ON c.id=ff.currency_id
        LEFT JOIN `{$TAC}` ea ON ea.id=ff.expense_account_id
        ORDER BY ff.status='pending' DESC, ff.due_date")->fetchAll();
} catch (Exception $e) {
    $fees = [];
}

$SCOPE_MAP = [
    'sales' => ['label' => 'مبيعات', 'color' => '#16a34a', 'icon' => 'bi-cart-check'],
    'purchase' => ['label' => 'مشتريات', 'color' => '#2563eb', 'icon' => 'bi-bag'],
    'withholding' => ['label' => 'استقطاع', 'color' => '#d97706', 'icon' => 'bi-scissors'],
];
$today = date('Y-m-d');
$pendingFees = array_filter($fees, fn($f) => $f['status'] === 'pending');
$overdueCount = count(array_filter($pendingFees, fn($f) => $f['due_date'] < $today));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>الضرائب والرسوم — <?= htmlspecialchars($branchName) ?></title>
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
            padding: 14px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            height: 100%
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0
        }

        .stat-val {
            font-size: 1.15rem;
            font-weight: 700;
            color: #1e293b
        }

        .stat-lbl {
            font-size: .72rem;
            color: #64748b
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
            padding: 10px 12px;
            font-size: .74rem;
            font-weight: 700;
            color: #64748b;
            text-align: right;
            white-space: nowrap
        }

        table.mtbl td {
            padding: 10px 12px;
            border-top: 1px solid #f1f5f9;
            vertical-align: middle
        }

        table.mtbl tbody tr:hover {
            background: #fafbfc
        }

        .field-lbl {
            font-size: .75rem;
            font-weight: 600;
            color: #475569;
            margin-bottom: 4px;
            display: block
        }

        .req {
            color: #dc2626
        }

        .n {
            direction: ltr;
            unicode-bidi: plaintext
        }

        .act-btn {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            transition: .15s
        }

        .act-btn:hover {
            background: #f1f5f9
        }

        .act-btn.danger:hover {
            background: #fef2f2;
            color: #dc2626;
            border-color: #fecaca
        }

        .sub-nav {
            display: flex;
            gap: 8px;
            margin-bottom: 16px
        }

        .sub-nav button {
            padding: 8px 16px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            background: #fff;
            font-size: .82rem;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: .15s
        }

        .sub-nav button.active {
            background: #1e3a8a;
            color: #fff;
            border-color: #1e3a8a
        }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-receipt-cutoff me-1 text-warning"></i>الضرائب والرسوم</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
            <span>المالية</span>
            <i class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
            <span class="text-warning fw-600">الضرائب والرسوم</span>
        </nav>
    </header>
    <main class="main-content">
        <div class="content-body">

            <!-- تبويبات صفحات المالية — قابلة للسحب وإعادة الترتيب، محفوظة لكل مستخدم -->
            <ul class="nav nav-tabs mb-3" id="financeTabs" style="border-bottom:2px solid #e2e8f0;flex-wrap:wrap">
                <?php foreach ($tabOrder as $href):
                    if (!isset($tabsMeta[$href]))
                        continue;
                    [$icon, $label] = $tabsMeta[$href];
                    $active = ($href === 'taxes.php');
                    $cls = $active ? 'nav-link fw-600 active' : 'nav-link fw-600';
                    $style = $active
                        ? 'border:none;border-bottom:2px solid #1e3a8a;color:#1e3a8a;font-size:.83rem;margin-bottom:-2px'
                        : 'border:none;color:#64748b;font-size:.83rem';
                    ?>
                <li class="nav-item" data-href="<?= htmlspecialchars($href) ?>">
                    <a class="<?= $cls ?>" href="<?= htmlspecialchars($href) ?>" style="<?= $style ?>">
                        <i class="bi <?= $icon ?> me-1"></i><?= htmlspecialchars($label) ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>

            <!-- إحصائيات -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fffbeb"><i class="bi bi-percent text-warning"></i></div>
                        <div>
                            <div class="stat-val"><?= count($taxTypes) ?></div>
                            <div class="stat-lbl">أنواع ضرائب معرَّفة</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#f0fdf4"><i class="bi bi-check-circle text-success"></i></div>
                        <div>
                            <div class="stat-val"><?= count(array_filter($taxTypes, fn($t) => $t['is_active'])) ?></div>
                            <div class="stat-lbl">ضرائب نشطة</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#eff6ff"><i class="bi bi-hourglass-split text-primary"></i></div>
                        <div>
                            <div class="stat-val"><?= count($pendingFees) ?></div>
                            <div class="stat-lbl">رسوم مستحقة</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:<?= $overdueCount ? '#fef2f2' : '#f0fdf4' ?>">
                            <i class="bi bi-exclamation-triangle <?= $overdueCount ? 'text-danger' : 'text-success' ?>"></i>
                        </div>
                        <div>
                            <div class="stat-val"><?= $overdueCount ?></div>
                            <div class="stat-lbl">رسوم متأخرة عن موعدها</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- تبديل القسم الفرعي -->
            <div class="sub-nav">
                <button id="btnSecTax" class="active" onclick="showSection('tax')">
                    <i class="bi bi-percent me-1"></i>إعدادات الضرائب
                </button>
                <button id="btnSecFee" onclick="showSection('fee')">
                    <i class="bi bi-calendar-check me-1"></i>الرسوم الثابتة
                </button>
            </div>

            <!-- ═══════════ قسم أنواع الضرائب ═══════════ -->
            <div id="secTax">
                <div class="tbl-wrap mb-3">
                    <div class="tbl-hdr">
                        <span style="font-size:.9rem;font-weight:700;color:#1e293b">
                            <i class="bi bi-percent me-2 text-warning"></i>أنواع الضرائب
                            <span style="font-size:.75rem;color:#94a3b8;font-weight:400">(<?= count($taxTypes) ?>)</span>
                        </span>
                        <button class="btn btn-sm fw-600 ms-auto" style="border-radius:9px;background:#1e3a8a;color:#fff;border:none"
                            onclick="openAddTax()">
                            <i class="bi bi-plus-lg me-1"></i>إضافة ضريبة
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="mtbl">
                            <thead>
                                <tr>
                                    <th>الاسم</th>
                                    <th>النطاق</th>
                                    <th>طريقة الحساب</th>
                                    <th>القيمة</th>
                                    <th>الحساب المحاسبي</th>
                                    <th>الحالة</th>
                                    <th style="width:110px">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($taxTypes)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4" style="font-size:.82rem">
                                            <i class="bi bi-inbox d-block mb-2" style="font-size:1.3rem;opacity:.3"></i>
                                            ماكو أنواع ضرائب معرَّفة بعد
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($taxTypes as $t): $sc = $SCOPE_MAP[$t['tax_scope']] ?? ['label' => $t['tax_scope'], 'color' => '#64748b', 'icon' => 'bi-tag']; ?>
                                    <tr>
                                        <td class="fw-600"><?= htmlspecialchars($t['name']) ?></td>
                                        <td>
                                            <span class="badge" style="background:<?= $sc['color'] ?>1a;color:<?= $sc['color'] ?>">
                                                <i class="bi <?= $sc['icon'] ?> me-1"></i><?= $sc['label'] ?>
                                            </span>
                                        </td>
                                        <td style="font-size:.78rem"><?= $t['calc_type'] === 'percentage' ? 'نسبة مئوية' : 'مبلغ ثابت' ?></td>
                                        <td class="n fw-600">
                                            <?= $t['calc_type'] === 'percentage' ? number_format($t['rate_value'], 2) . '٪' : htmlspecialchars($baseSym) . ' ' . number_format($t['rate_value'], 2) ?>
                                        </td>
                                        <td style="font-size:.78rem">
                                            <?= $t['acc_code'] ? htmlspecialchars($t['acc_code'] . ' — ' . $t['acc_name']) : '<span class="text-danger">غير مربوط</span>' ?>
                                        </td>
                                        <td>
                                            <span class="badge" style="background:<?= $t['is_active'] ? '#f0fdf4' : '#f8fafc' ?>;color:<?= $t['is_active'] ? '#16a34a' : '#94a3b8' ?>">
                                                <?= $t['is_active'] ? 'نشطة' : 'معطّلة' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <button class="act-btn" onclick="openEditTax(<?= $t['id'] ?>)" title="تعديل"><i class="bi bi-pencil"></i></button>
                                                <button class="act-btn" onclick="toggleTax(<?= $t['id'] ?>)" title="<?= $t['is_active'] ? 'تعطيل' : 'تفعيل' ?>">
                                                    <i class="bi bi-<?= $t['is_active'] ? 'pause' : 'play' ?>"></i>
                                                </button>
                                                <button class="act-btn danger" onclick="deleteTax(<?= $t['id'] ?>,'<?= htmlspecialchars($t['name'], ENT_QUOTES) ?>')" title="حذف"><i class="bi bi-trash"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="alert alert-warning py-2" style="font-size:.75rem;border-radius:10px">
                    <i class="bi bi-info-circle me-1"></i>
                    "ضريبة استقطاع" مربوطة تلقائياً بـسندات الدفع (payments.php) — أي ضريبة استقطاع نشطة هون بتظهر كخيار وقت دفع مورد. أنواع "مبيعات"/"مشتريات" بنية جاهزة، ولسا مش مربوطة بفواتير البيع/الشراء (خطوة لاحقة).
                </div>
            </div>

            <!-- ═══════════ قسم الرسوم الثابتة ═══════════ -->
            <div id="secFee" style="display:none">
                <div class="tbl-wrap">
                    <div class="tbl-hdr">
                        <span style="font-size:.9rem;font-weight:700;color:#1e293b">
                            <i class="bi bi-calendar-check me-2 text-primary"></i>الرسوم الثابتة/الدورية
                            <span style="font-size:.75rem;color:#94a3b8;font-weight:400">(<?= count($fees) ?>)</span>
                        </span>
                        <button class="btn btn-sm fw-600 ms-auto" style="border-radius:9px;background:#1e3a8a;color:#fff;border:none"
                            onclick="openAddFee()">
                            <i class="bi bi-plus-lg me-1"></i>إضافة رسم
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="mtbl">
                            <thead>
                                <tr>
                                    <th>الاسم</th>
                                    <th>المبلغ</th>
                                    <th>تاريخ الاستحقاق</th>
                                    <th>التكرار</th>
                                    <th>الحالة</th>
                                    <th style="width:150px">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($fees)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4" style="font-size:.82rem">
                                            <i class="bi bi-inbox d-block mb-2" style="font-size:1.3rem;opacity:.3"></i>
                                            ماكو رسوم مسجَّلة بعد
                                        </td>
                                    </tr>
                                <?php endif;
                                $recLabels = ['once' => 'مرة واحدة', 'monthly' => 'شهري', 'yearly' => 'سنوي'];
                                foreach ($fees as $f):
                                    $isOverdue = $f['status'] === 'pending' && $f['due_date'] < $today;
                                    ?>
                                    <tr>
                                        <td class="fw-600"><?= htmlspecialchars($f['name']) ?></td>
                                        <td class="n fw-600"><?= htmlspecialchars($f['cur_sym'] ?? '') ?> <?= number_format($f['amount'], 2) ?></td>
                                        <td class="n <?= $isOverdue ? 'text-danger fw-600' : '' ?>">
                                            <?= $f['due_date'] ?>
                                            <?php if ($isOverdue): ?><i class="bi bi-exclamation-triangle-fill ms-1" title="متأخر"></i><?php endif; ?>
                                        </td>
                                        <td style="font-size:.78rem"><?= $recLabels[$f['recurrence']] ?></td>
                                        <td>
                                            <?php if ($f['status'] === 'paid'): ?>
                                                <span class="badge" style="background:#f0fdf4;color:#16a34a">مسدَّد</span>
                                            <?php else: ?>
                                                <span class="badge" style="background:<?= $isOverdue ? '#fef2f2' : '#fffbeb' ?>;color:<?= $isOverdue ? '#dc2626' : '#d97706' ?>">
                                                    <?= $isOverdue ? 'متأخر' : 'مستحق' ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <?php if ($f['status'] === 'pending'): ?>
                                                    <button class="btn btn-sm" style="border-radius:7px;border:1px solid #16a34a;color:#16a34a;font-size:.72rem"
                                                        onclick="openPayFee(<?= $f['id'] ?>,'<?= htmlspecialchars($f['name'], ENT_QUOTES) ?>')">
                                                        <i class="bi bi-check-circle me-1"></i>تسديد
                                                    </button>
                                                    <button class="act-btn" onclick="openEditFee(<?= $f['id'] ?>)" title="تعديل"><i class="bi bi-pencil"></i></button>
                                                    <button class="act-btn danger" onclick="deleteFee(<?= $f['id'] ?>,'<?= htmlspecialchars($f['name'], ENT_QUOTES) ?>')" title="حذف"><i class="bi bi-trash"></i></button>
                                                <?php else: ?>
                                                    <span style="font-size:.72rem;color:#94a3b8"><i class="bi bi-journal-check me-1"></i>مرحّل</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <!-- مودال إضافة/تعديل ضريبة -->
    <div class="modal fade" id="taxModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#1e3a8a,#2563eb);border-radius:16px 16px 0 0">
                    <h6 class="modal-title text-white fw-700 mb-0" id="taxModalTitle"><i class="bi bi-percent me-2"></i>إضافة ضريبة</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-4">
                    <input type="hidden" id="taxId">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="field-lbl">اسم الضريبة <span class="req">*</span></label>
                            <input type="text" id="taxName" class="form-control form-control-sm" placeholder="مثلاً: ضريبة مبيعات قياسية">
                        </div>
                        <div class="col-md-6">
                            <label class="field-lbl">النطاق <span class="req">*</span></label>
                            <select id="taxScope" class="form-select form-select-sm">
                                <option value="sales">مبيعات (Output Tax)</option>
                                <option value="purchase">مشتريات (Input Tax)</option>
                                <option value="withholding">استقطاع من الموردين (Withholding)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">طريقة الحساب</label>
                            <select id="taxCalcType" class="form-select form-select-sm" onchange="onTaxCalcTypeChange()">
                                <option value="percentage">نسبة مئوية</option>
                                <option value="fixed">مبلغ ثابت</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl" id="taxRateLbl">النسبة (٪) <span class="req">*</span></label>
                            <input type="number" id="taxRate" class="form-control form-control-sm" min="0" step="0.01" dir="ltr" placeholder="0">
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">الحساب المحاسبي</label>
                            <select id="taxAccount" class="form-select form-select-sm">
                                <option value="">— بدون —</option>
                                <?php foreach ($accountsList as $a): ?>
                                    <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="field-lbl">ملاحظات</label>
                            <input type="text" id="taxNotes" class="form-control form-control-sm" placeholder="اختياري">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-sm fw-600" style="border-radius:8px;background:#1e3a8a;color:#fff;min-width:100px" onclick="saveTax()">
                        <i class="bi bi-check-circle me-1"></i>حفظ
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- مودال إضافة/تعديل رسم -->
    <div class="modal fade" id="feeModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#1e3a8a,#2563eb);border-radius:16px 16px 0 0">
                    <h6 class="modal-title text-white fw-700 mb-0" id="feeModalTitle"><i class="bi bi-calendar-check me-2"></i>إضافة رسم</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-4">
                    <input type="hidden" id="feeId">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="field-lbl">اسم الرسم <span class="req">*</span></label>
                            <input type="text" id="feeName" class="form-control form-control-sm" placeholder="مثلاً: رخصة بلدية سنوية">
                        </div>
                        <div class="col-md-3">
                            <label class="field-lbl">المبلغ <span class="req">*</span></label>
                            <input type="number" id="feeAmount" class="form-control form-control-sm fw-600" min="0" step="0.01" dir="ltr">
                        </div>
                        <div class="col-md-3">
                            <label class="field-lbl">العملة</label>
                            <select id="feeCurrency" class="form-select form-select-sm">
                                <?php foreach ($currenciesList as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= $c['id'] == $baseCurId ? 'selected' : '' ?>><?= htmlspecialchars($c['code']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">تاريخ الاستحقاق</label>
                            <input type="date" id="feeDueDate" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">التكرار</label>
                            <select id="feeRecurrence" class="form-select form-select-sm">
                                <option value="once">مرة واحدة</option>
                                <option value="monthly">شهري</option>
                                <option value="yearly">سنوي</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">حساب المصروف <span class="req">*</span></label>
                            <select id="feeExpAccount" class="form-select form-select-sm">
                                <option value="">— اختر —</option>
                                <?php foreach ($accountsList as $a): if ($a['account_type'] === 'expense'): ?>
                                    <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?></option>
                                <?php endif; endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="field-lbl">ملاحظات</label>
                            <input type="text" id="feeNotes" class="form-control form-control-sm" placeholder="اختياري">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-sm fw-600" style="border-radius:8px;background:#1e3a8a;color:#fff;min-width:100px" onclick="saveFee()">
                        <i class="bi bi-check-circle me-1"></i>حفظ
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- مودال تسديد رسم -->
    <div class="modal fade" id="payFeeModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#16a34a,#22c55e);border-radius:16px 16px 0 0">
                    <h6 class="modal-title text-white fw-700 mb-0"><i class="bi bi-check-circle me-2"></i>تسديد رسم: <span id="payFeeName"></span></h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-4">
                    <input type="hidden" id="payFeeId">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="field-lbl">تاريخ التسديد</label>
                            <input type="date" id="payFeeDate" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="field-lbl">حساب الصندوق/البنك <span class="req">*</span></label>
                            <select id="payFeeCashAcc" class="form-select form-select-sm">
                                <option value="">— اختر —</option>
                                <?php foreach ($cashAccounts as $acc): ?>
                                    <option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['code'] . ' — ' . $acc['name'] . ' (' . ($acc['cur_code'] ?? '') . ')') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="alert alert-warning py-2 mt-3" style="font-size:.75rem;border-radius:10px">
                        <i class="bi bi-info-circle me-1"></i>التسديد بيعمل قيد محاسبي ويرحّله فوراً — ما ممكن التراجع عنه من هون بعد الحفظ.
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-sm fw-600" style="border-radius:8px;background:#16a34a;color:#fff;min-width:100px" onclick="confirmPayFee()">
                        <i class="bi bi-check-circle me-1"></i>تأكيد التسديد
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const sb = document.getElementById('sidebar'), ov = document.getElementById('sbOverlay');
        function sbOpen() { sb.classList.add('open'); ov.classList.add('show'); }
        function sbClose() { sb.classList.remove('open'); ov.classList.remove('show'); }
        window.addEventListener('resize', () => { if (window.innerWidth > 991) sbClose(); });
        function toggleGroup(g) { const o = g.classList.contains('open'); document.querySelectorAll('.sb-group.open').forEach(x => x.classList.remove('open')); g.classList.toggle('open', !o); localStorage.setItem('sb_open_' + g.dataset.key, (!o).toString()); }
        document.querySelectorAll('.sb-group').forEach(g => { if (localStorage.getItem('sb_open_' + g.dataset.key) === 'true') g.classList.add('open'); });

        const taxModal = new bootstrap.Modal(document.getElementById('taxModal'));
        const feeModal = new bootstrap.Modal(document.getElementById('feeModal'));
        const payFeeModal = new bootstrap.Modal(document.getElementById('payFeeModal'));

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

        function showSection(sec) {
            document.getElementById('secTax').style.display = sec === 'tax' ? '' : 'none';
            document.getElementById('secFee').style.display = sec === 'fee' ? '' : 'none';
            document.getElementById('btnSecTax').classList.toggle('active', sec === 'tax');
            document.getElementById('btnSecFee').classList.toggle('active', sec === 'fee');
        }

        function onTaxCalcTypeChange() {
            const isPct = document.getElementById('taxCalcType').value === 'percentage';
            document.getElementById('taxRateLbl').textContent = isPct ? 'النسبة (٪) *' : 'المبلغ الثابت *';
        }
        function openAddTax() {
            document.getElementById('taxId').value = '';
            document.getElementById('taxModalTitle').innerHTML = '<i class="bi bi-percent me-2"></i>إضافة ضريبة';
            document.getElementById('taxName').value = '';
            document.getElementById('taxScope').value = 'sales';
            document.getElementById('taxCalcType').value = 'percentage';
            document.getElementById('taxRate').value = '';
            document.getElementById('taxAccount').value = '';
            document.getElementById('taxNotes').value = '';
            onTaxCalcTypeChange();
            taxModal.show();
        }
        function openEditTax(id) {
            post({ _action: 'get_tax_type', id }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                const t = d.data;
                document.getElementById('taxId').value = t.id;
                document.getElementById('taxModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>تعديل: ' + t.name;
                document.getElementById('taxName').value = t.name;
                document.getElementById('taxScope').value = t.tax_scope;
                document.getElementById('taxCalcType').value = t.calc_type;
                document.getElementById('taxRate').value = t.rate_value;
                document.getElementById('taxAccount').value = t.account_id || '';
                document.getElementById('taxNotes').value = t.notes || '';
                onTaxCalcTypeChange();
                taxModal.show();
            });
        }
        function saveTax() {
            const name = document.getElementById('taxName').value.trim();
            const rate = parseFloat(document.getElementById('taxRate').value || 0);
            if (!name) { toast('اسم الضريبة مطلوب', 'danger'); return; }
            if (rate <= 0) { toast('القيمة يجب أن تكون أكبر من صفر', 'danger'); return; }
            post({
                _action: 'save_tax_type',
                id: document.getElementById('taxId').value,
                name, tax_scope: document.getElementById('taxScope').value,
                calc_type: document.getElementById('taxCalcType').value,
                rate_value: rate,
                account_id: document.getElementById('taxAccount').value,
                notes: document.getElementById('taxNotes').value,
            }).then(d => {
                if (d.ok) { toast('تم الحفظ'); taxModal.hide(); setTimeout(() => location.reload(), 500); }
                else toast(d.msg, 'danger');
            });
        }
        function toggleTax(id) {
            post({ _action: 'toggle_tax_active', id }).then(d => {
                if (d.ok) { toast(d.active ? 'تم التفعيل' : 'تم التعطيل'); setTimeout(() => location.reload(), 500); }
                else toast(d.msg, 'danger');
            });
        }
        function deleteTax(id, name) {
            if (!confirm(`حذف الضريبة "${name}"؟`)) return;
            post({ _action: 'delete_tax_type', id }).then(d => {
                if (d.ok) { toast('تم الحذف'); setTimeout(() => location.reload(), 500); }
                else toast(d.msg, 'danger');
            });
        }

        function openAddFee() {
            document.getElementById('feeId').value = '';
            document.getElementById('feeModalTitle').innerHTML = '<i class="bi bi-calendar-check me-2"></i>إضافة رسم';
            document.getElementById('feeName').value = '';
            document.getElementById('feeAmount').value = '';
            document.getElementById('feeDueDate').value = new Date().toISOString().split('T')[0];
            document.getElementById('feeRecurrence').value = 'once';
            document.getElementById('feeExpAccount').value = '';
            document.getElementById('feeNotes').value = '';
            feeModal.show();
        }
        function openEditFee(id) {
            post({ _action: 'get_fee', id }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                const f = d.data;
                document.getElementById('feeId').value = f.id;
                document.getElementById('feeModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>تعديل: ' + f.name;
                document.getElementById('feeName').value = f.name;
                document.getElementById('feeAmount').value = f.amount;
                document.getElementById('feeCurrency').value = f.currency_id;
                document.getElementById('feeDueDate').value = f.due_date;
                document.getElementById('feeRecurrence').value = f.recurrence;
                document.getElementById('feeExpAccount').value = f.expense_account_id || '';
                document.getElementById('feeNotes').value = f.notes || '';
                feeModal.show();
            });
        }
        function saveFee() {
            const name = document.getElementById('feeName').value.trim();
            const amount = parseFloat(document.getElementById('feeAmount').value || 0);
            if (!name) { toast('اسم الرسم مطلوب', 'danger'); return; }
            if (amount <= 0) { toast('المبلغ يجب أن يكون أكبر من صفر', 'danger'); return; }
            post({
                _action: 'save_fee',
                id: document.getElementById('feeId').value,
                name, amount,
                currency_id: document.getElementById('feeCurrency').value,
                due_date: document.getElementById('feeDueDate').value,
                recurrence: document.getElementById('feeRecurrence').value,
                expense_account_id: document.getElementById('feeExpAccount').value,
                notes: document.getElementById('feeNotes').value,
            }).then(d => {
                if (d.ok) { toast('تم الحفظ'); feeModal.hide(); setTimeout(() => location.reload(), 500); }
                else toast(d.msg, 'danger');
            });
        }
        function deleteFee(id, name) {
            if (!confirm(`حذف الرسم "${name}"؟`)) return;
            post({ _action: 'delete_fee', id }).then(d => {
                if (d.ok) { toast('تم الحذف'); setTimeout(() => location.reload(), 500); }
                else toast(d.msg, 'danger');
            });
        }
        function openPayFee(id, name) {
            document.getElementById('payFeeId').value = id;
            document.getElementById('payFeeName').textContent = name;
            document.getElementById('payFeeDate').value = new Date().toISOString().split('T')[0];
            document.getElementById('payFeeCashAcc').value = '';
            payFeeModal.show();
        }
        function confirmPayFee() {
            const cashAcc = document.getElementById('payFeeCashAcc').value;
            if (!cashAcc) { toast('اختر حساب الصندوق/البنك', 'danger'); return; }
            post({
                _action: 'pay_fee',
                id: document.getElementById('payFeeId').value,
                pay_date: document.getElementById('payFeeDate').value,
                cash_account_id: cashAcc,
            }).then(d => {
                if (d.ok) { toast('✅ ' + d.msg); payFeeModal.hide(); setTimeout(() => location.reload(), 800); }
                else toast(d.msg, 'danger');
            });
        }
    </script>

</body>

</html>
