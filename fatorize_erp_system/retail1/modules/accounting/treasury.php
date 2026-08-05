<?php
/**
 * accounting/treasury.php — الصندوق (نظرة عامة على الصناديق/البنوك + تحويل بينها)
 * المسار: retail1/modules/accounting/treasury.php
 *
 * ⚠ ملاحظة تصميم مهمة على التحويل بين الصناديق: المبلغ يُدخَل مرة وحدة
 * بعملة مختارة (متل journal.php)، ويتحوَّل لعملة الفرع الأساسية، ونفس
 * القيمة المحوَّلة (amountBase) تُقيَّد مدين على الحساب الوجهة ودائن
 * على حساب المصدر — فالقيد متوازن دايماً بالبناء. هذا التصميم ما بيسجّل
 * أي فرق سعر صرف فعلي (لو التحويل فعلياً بين عملتين وسعر الصرف اللحظي
 * يختلف عن سعر النظام) — لو محتاج تتبّع أرباح/خسائر فروقات صرف بدقة،
 * هاي نقطة تستاهل نقاش وتصميم منفصل لاحقاً (سجّلها بملف "تحديثات مستقبلية").
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('finance.treasury', 'view');
$currentModule = 'finance.treasury';

$TS = $_SESSION['table_suffix'];
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

// ── جلب حساب من الإعدادات (نفس الدالة المستخدمة بـreceipts.php/payments.php) ──
function getSettingAccount(PDO $pdo, string $tias, string $key, string $tac): ?array
{
    $st = $pdo->prepare("SELECT ac.* FROM `{$tias}` ias
        JOIN `{$tac}` ac ON ac.id=ias.account_id
        WHERE ias.setting_key=? LIMIT 1");
    $st->execute([$key]);
    return $st->fetch() ?: null;
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

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];


        // ── آخر حركة مختصرة لحساب صندوق/بنك محدد (بدون فلترة تاريخ — LIMIT ثابت) ──
        if ($act === 'get_recent_movements') {
            $accId = (int) ($_POST['account_id'] ?? 0);
            if (!$accId)
                throw new Exception('حساب غير صالح');
            $st = $pdo->prepare("SELECT ji.debit, ji.credit, je.entry_number, je.entry_date, je.description
                FROM `{$TJI}` ji JOIN `{$TJE}` je ON je.id = ji.journal_entry_id
                WHERE ji.account_id = ? AND je.status = 'posted'
                ORDER BY je.entry_date DESC, je.id DESC LIMIT 10");
            $st->execute([$accId]);
            echo json_encode(['ok' => true, 'data' => $st->fetchAll()]);
        }

        // ── تحويل بين صندوقين/بنكين ──
        elseif ($act === 'save_transfer') {
            requirePermission('finance.treasury', 'create');
            $fromId = (int) ($_POST['from_account_id'] ?? 0);
            $toId = (int) ($_POST['to_account_id'] ?? 0);
            $date = $_POST['transfer_date'] ?? date('Y-m-d');
            $amountOut = (float) ($_POST['amount_out'] ?? 0);
            $amountIn = (float) ($_POST['amount_in'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');

            if (!$fromId || !$toId)
                throw new Exception('يجب اختيار حساب المصدر والوجهة');
            if ($fromId === $toId)
                throw new Exception('حساب المصدر والوجهة لا يمكن أن يكونا نفس الحساب');
            if ($amountOut <= 0 || $amountIn <= 0)
                throw new Exception('المبلغين (الخارج والداخل) يجب أن يكونا أكبر من صفر');

            // ⚠ التحقق من إنه الحسابين فعلاً من ضمن حسابات الصناديق/البنوك —
            // من جدول الربط المحاسبي (invoice_account_settings، مفاتيح
            // cash_*/bank_*) يلي هو المصدر الحقيقي لهالتصنيف، مش تخمين
            // بادئة الكود (كان بيفترض ترقيم ثابت 111/112 دايماً).
            $validIds = $pdo->query("SELECT account_id FROM `{$TIAS}`
                WHERE setting_key LIKE 'cash_%' OR setting_key LIKE 'bank_%'")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array($fromId, $validIds) || !in_array($toId, $validIds)) {
                throw new Exception('التحويل مسموح فقط بين حسابات الصناديق/البنوك المضبوطة بإعدادات الربط المحاسبي');
            }

            $fromAcc = $pdo->prepare("SELECT ac.*, c.code AS cur_code, c.exchange_rate AS cur_rate
                FROM `{$TAC}` ac LEFT JOIN currencies c ON c.id=ac.currency_id WHERE ac.id=?");
            $fromAcc->execute([$fromId]);
            $fromAcc = $fromAcc->fetch();
            $toAcc = $pdo->prepare("SELECT ac.*, c.code AS cur_code, c.exchange_rate AS cur_rate
                FROM `{$TAC}` ac LEFT JOIN currencies c ON c.id=ac.currency_id WHERE ac.id=?");
            $toAcc->execute([$toId]);
            $toAcc = $toAcc->fetch();
            if (!$fromAcc || !$toAcc)
                throw new Exception('أحد الحسابين غير موجود');

            // ⚠ سعر كل حساب بعملته الخاصة من جدول currencies مباشرة — ما
            // منثق بأي سعر مُرسَل من الواجهة، دايماً من مصدر الحقيقة بالسيرفر.
            $fromRate = max(0.0001, (float) ($fromAcc['cur_rate'] ?: 1));
            $toRate = max(0.0001, (float) ($toAcc['cur_rate'] ?: 1));

            $amountBaseFrom = $amountOut / $fromRate;
            $amountBaseTo = $amountIn / $toRate;
            $diff = round($amountBaseTo - $amountBaseFrom, 4); // >0 = ربح، <0 = خسارة، 0 = تحويل مطابق بدون فرق

            // حسابات ربح/خسارة فروقات الصرف — لازم تكون مضبوطة بإعدادات
            // الربط المحاسبي لو في فرق فعلي (حسابات نفس العملة أو بنفس
            // القيمة بالضبط ما بتحتاجهم إطلاقاً).
            $accGain = $accLoss = null;
            if (abs($diff) > 0.0001) {
                if ($diff > 0) {
                    $accGain = getSettingAccount($pdo, $TIAS, 'fx_gain', $TAC);
                    if (!$accGain)
                        throw new Exception('فيه فرق ربح صرف، بس حساب "أرباح فروقات الصرف" مش مضبوط بإعدادات الربط المحاسبي');
                } else {
                    $accLoss = getSettingAccount($pdo, $TIAS, 'fx_loss', $TAC);
                    if (!$accLoss)
                        throw new Exception('فيه فرق خسارة صرف، بس حساب "خسائر فروقات الصرف" مش مضبوط بإعدادات الربط المحاسبي');
                }
            }

            $pdo->beginTransaction();
            try {
                $jeNo = genEntryNo($pdo, $TJE);
                $desc = "تحويل من {$fromAcc['name']} إلى {$toAcc['name']}"
                    . " ({$fromAcc['cur_code']} {$amountOut} ← {$toAcc['cur_code']} {$amountIn})"
                    . ($notes ? " — {$notes}" : '');
                $totalSide = max($amountBaseFrom, $amountBaseTo); // الطرف الأكبر = إجمالي المدين = إجمالي الدائن دايماً
                $pdo->prepare("INSERT INTO `{$TJE}` (entry_number,entry_date,description,currency_id,
                    exchange_rate,total_debit,total_credit,status,reference_type,created_by)
                    VALUES (?,?,?,?,?,?,?,'draft','transfer',?)")
                    ->execute([$jeNo, $date, $desc, $toAcc['currency_id'] ?: $fromAcc['currency_id'], 1, $totalSide, $totalSide, $_SESSION['user_id']]);
                $jeId = (int) $pdo->lastInsertId();

                // مدين: الحساب الوجهة (بكامل المبلغ الوارد إليه بعملة الفرع)
                $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,
                    original_amount,base_amount,description,currency_id,exchange_rate)
                    VALUES (?,?,?,0,?,?,?,?,?)")
                    ->execute([$jeId, $toId, $amountBaseTo, $amountIn, $amountBaseTo, "تحويل وارد — {$jeNo}", $toAcc['currency_id'], $toRate]);
                // دائن: حساب المصدر (بكامل المبلغ الصادر منه بعملة الفرع)
                $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,
                    original_amount,base_amount,description,currency_id,exchange_rate)
                    VALUES (?,?,0,?,?,?,?,?,?)")
                    ->execute([$jeId, $fromId, $amountBaseFrom, $amountOut, $amountBaseFrom, "تحويل صادر — {$jeNo}", $fromAcc['currency_id'], $fromRate]);

                // سطر إضافي لفرق سعر الصرف (لو في فرق فعلي) — يوازن القيد
                if ($accGain) {
                    // ربح: الوجهة استلمت قيمة أكبر مما صدر من المصدر — دائن أرباح فروقات الصرف
                    $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,
                        original_amount,base_amount,description,currency_id,exchange_rate)
                        VALUES (?,?,0,?,?,?,?,?,1)")
                        ->execute([$jeId, $accGain['id'], $diff, $diff, $diff, "فرق ربح صرف — {$jeNo}", $toAcc['currency_id']]);
                } elseif ($accLoss) {
                    // خسارة: الوجهة استلمت قيمة أقل مما صدر من المصدر — مدين خسائر فروقات الصرف
                    $lossAmt = abs($diff);
                    $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,
                        original_amount,base_amount,description,currency_id,exchange_rate)
                        VALUES (?,?,?,0,?,?,?,?,1)")
                        ->execute([$jeId, $accLoss['id'], $lossAmt, $lossAmt, $lossAmt, "فرق خسارة صرف — {$jeNo}", $fromAcc['currency_id']]);
                }

                // ترحيل مباشر (التحويل عملية داخلية، ما إلها معنى "مسودة" فعلياً)
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

                $pdo->commit();
                $msg = 'تم التحويل وترحيله';
                if ($accGain)
                    $msg .= " (ربح صرف: {$baseSym}" . number_format($diff, 2) . ")";
                if ($accLoss)
                    $msg .= " (خسارة صرف: {$baseSym}" . number_format(abs($diff), 2) . ")";
                echo json_encode(['ok' => true, 'no' => $jeNo, 'msg' => $msg]);
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


// ── بيانات الصفحة ──
// رمز عملة الفرع الأساسية
$baseSym = '$';
$baseCurId = 1;
if (!empty($_SESSION['branch_id'])) {
    $bcStmt = $pdo->prepare("SELECT c.id, c.symbol FROM branches b
        LEFT JOIN currencies c ON c.id = b.base_currency_id
        WHERE b.id = ?");
    $bcStmt->execute([$_SESSION['branch_id']]);
    $bcRow = $bcStmt->fetch();
    if ($bcRow) {
        $baseSym = $bcRow['symbol'] ?: '$';
        $baseCurId = $bcRow['id'] ?: 1;
    }
}

// قائمة العملات الحقيقية
$currenciesList = $pdo->query("SELECT id,code,name,symbol,is_base,exchange_rate FROM currencies WHERE status='active' ORDER BY is_base DESC,id")->fetchAll();

// كل حسابات الصناديق/البنوك — من جدول الربط المحاسبي (invoice_account_settings)
// مباشرة، مش تخمين بادئة الكود. account_settings.php هو يلي بيولّد مفاتيح
// cash_{عملة}/bank_{عملة} ديناميكياً لكل عملة نشطة ويربطها بحساب حقيقي —
// هون بنقرأ نفس الربط، فأي حساب مش مصرَّح فيه هناك ما بيظهر هون أبداً.
$cashAccounts = $pdo->query("SELECT ac.*, c.code AS cur_code, c.symbol AS cur_sym, ias.setting_key
    FROM `{$TIAS}` ias
    JOIN `{$TAC}` ac ON ac.id = ias.account_id
    LEFT JOIN currencies c ON c.id = ac.currency_id
    WHERE (ias.setting_key LIKE 'cash_%' OR ias.setting_key LIKE 'bank_%')
      AND ac.is_active = 1
    ORDER BY ac.code")->fetchAll();

// إحصائيات
$stats = [
    'total_accounts' => count($cashAccounts),
    'total_base' => array_sum(array_column($cashAccounts, 'base_balance')),
    'cash_count' => count(array_filter($cashAccounts, fn($a) => strpos($a['setting_key'], 'cash_') === 0)),
    'bank_count' => count(array_filter($cashAccounts, fn($a) => strpos($a['setting_key'], 'bank_') === 0)),
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>الصندوق — <?= htmlspecialchars($branchName) ?></title>
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

        .cash-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            padding: 0;
            height: 100%;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transition: box-shadow .2s, transform .2s;
        }

        .cash-card:hover {
            box-shadow: 0 8px 24px rgba(15, 23, 42, .08);
            transform: translateY(-2px);
        }

        .cash-card-accent {
            height: 5px;
            width: 100%;
        }

        .cash-card-body {
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            flex: 1;
        }

        .cash-card-hdr {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px
        }

        .cash-card-icon-wrap {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0
        }

        .cash-bal-section {
            background: #f8fafc;
            border-radius: 10px;
            padding: 10px 12px;
        }

        .cash-bal {
            font-size: 1.35rem;
            font-weight: 700;
            line-height: 1.3;
        }

        .cash-mov-chip {
            background: #f8fafc;
            border-radius: 8px;
            padding: 6px 10px;
            font-size: .72rem;
            color: #94a3b8;
            min-height: 30px;
            display: flex;
            align-items: center;
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

        .mov-row {
            display: grid;
            grid-template-columns: 90px 1fr auto;
            gap: 8px;
            font-size: .76rem;
            padding: 4px 0;
            border-bottom: 1px solid #f8fafc
        }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-safe me-1 text-primary"></i>الصندوق</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
            <span>المالية</span>
            <i class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
            <span class="text-primary fw-600">الصندوق</span>
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
                    <a class="nav-link fw-600" href="account_settings.php" style="border:none;color:#64748b;font-size:.83rem">
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
                    <a class="nav-link fw-600 active" href="treasury.php" style="border:none;border-bottom:2px solid #1e3a8a;color:#1e3a8a;font-size:.83rem;margin-bottom:-2px">
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
                    <a class="nav-link fw-600" href="shipping_carriers.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-truck me-1"></i>شركات الشحن
                    </a>
                </li>
            </ul>

            <!-- إحصائيات -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#eff6ff"><i class="bi bi-safe text-primary"></i></div>
                        <div>
                            <div class="stat-val"><?= $stats['total_accounts'] ?></div>
                            <div class="stat-lbl">إجمالي الصناديق/البنوك</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#f0fdf4"><i class="bi bi-cash-coin text-success"></i></div>
                        <div>
                            <div class="stat-val"><?= $stats['cash_count'] ?></div>
                            <div class="stat-lbl">صناديق نقدية</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#eff6ff"><i class="bi bi-bank text-primary"></i></div>
                        <div>
                            <div class="stat-val"><?= $stats['bank_count'] ?></div>
                            <div class="stat-lbl">حسابات بنكية</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fffbeb"><i class="bi bi-wallet2 text-warning"></i></div>
                        <div>
                            <div class="stat-val n"><?= htmlspecialchars($baseSym) ?> <?= number_format($stats['total_base'], 2) ?></div>
                            <div class="stat-lbl">إجمالي السيولة (بعملة الفرع)</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex align-items-center justify-content-between mb-3">
                <span style="font-size:.95rem;font-weight:700;color:#1e293b">
                    <i class="bi bi-grid-3x3-gap me-1 text-primary"></i>الصناديق والبنوك
                </span>
                <button class="btn btn-sm fw-600" style="border-radius:9px;background:#1e3a8a;color:#fff;border:none"
                    onclick="openTransfer()">
                    <i class="bi bi-arrow-left-right me-1"></i>تحويل بين الصناديق
                </button>
            </div>

            <!-- بطاقات الصناديق/البنوك -->
            <div class="row g-3 mb-4">
                <?php if (empty($cashAccounts)): ?>
                    <div class="col-12">
                        <div class="text-center text-muted py-4" style="font-size:.85rem">
                            <i class="bi bi-inbox d-block mb-2" style="font-size:1.5rem;opacity:.3"></i>
                            ماكو حسابات صناديق/بنوك مضبوطة بعد — اضبطها من
                            <a href="account_settings.php">إعدادات الربط المحاسبي</a>
                            (مفاتيح "صندوق ..." و"بنك ..." تحت مجموعة "الصناديق والبنوك")
                        </div>
                    </div>
                <?php endif; ?>
                <?php foreach ($cashAccounts as $acc):
                    $isCash = strpos($acc['setting_key'], 'cash_') === 0;
                    $icon = $isCash ? 'bi-cash-coin' : 'bi-bank';
                    $color = $isCash ? '#16a34a' : '#1e3a8a';
                    ?>
                    <div class="col-md-4 col-sm-6">
                        <div class="cash-card">
                            <div class="cash-card-accent" style="background:<?= $color ?>"></div>
                            <div class="cash-card-body">
                                <div class="cash-card-hdr">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="cash-card-icon-wrap" style="background:<?= $color ?>1a">
                                            <i class="bi <?= $icon ?>" style="color:<?= $color ?>"></i>
                                        </div>
                                        <div>
                                            <div style="font-size:.85rem;font-weight:700;color:#1e293b">
                                                <?= htmlspecialchars($acc['name']) ?>
                                            </div>
                                            <div class="n" style="font-size:.7rem;color:#1e3a8a;font-weight:600" dir="ltr">
                                                <?= htmlspecialchars($acc['code']) ?>
                                            </div>
                                        </div>
                                    </div>
                                    <span class="badge" style="font-size:.65rem;background:<?= $color ?>1a;color:<?= $color ?>">
                                        <?= htmlspecialchars($acc['cur_code'] ?? '') ?>
                                    </span>
                                </div>

                                <div class="cash-bal-section">
                                    <div class="cash-bal n" style="color:<?= ($acc['balance'] ?? 0) >= 0 ? '#16a34a' : '#dc2626' ?>">
                                        <?= htmlspecialchars($acc['cur_sym'] ?? '') ?> <?= number_format($acc['balance'] ?? 0, 2) ?>
                                    </div>
                                    <div class="n" style="font-size:.72rem;color:#64748b;margin-top:2px">
                                        = <?= htmlspecialchars($baseSym) ?> <?= number_format($acc['base_balance'] ?? 0, 2) ?> بعملة الفرع
                                    </div>
                                </div>

                                <div id="mov_<?= $acc['id'] ?>" class="cash-mov-chip">
                                    <span class="spinner-border spinner-border-sm" style="width:.7rem;height:.7rem"></span>
                                </div>

                                <div style="margin-top:auto;display:flex;gap:6px;padding-top:2px">
                                    <a href="accounts.php?view_movements=<?= $acc['id'] ?>" class="btn btn-sm"
                                        style="flex:1;border-radius:8px;border:1px solid #64748b;color:#64748b;font-size:.72rem">
                                        <i class="bi bi-clock-history me-1"></i>الحركة الكاملة
                                    </a>
                                    <button class="btn btn-sm" onclick="openTransfer(<?= $acc['id'] ?>)"
                                        style="flex:1;border-radius:8px;border:1px solid #1e3a8a;color:#1e3a8a;font-size:.72rem">
                                        <i class="bi bi-arrow-left-right me-1"></i>تحويل
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>

    <!-- مودال التحويل -->
    <div class="modal fade" id="trModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#1e3a8a,#2563eb);border-radius:16px 16px 0 0">
                    <h6 class="modal-title text-white fw-700 mb-0"><i class="bi bi-arrow-left-right me-2"></i>تحويل بين الصناديق</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-4">
                    <!-- قسم الحسابين -->
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin-bottom:16px">
                        <div style="font-size:.78rem;font-weight:700;color:#1e3a8a;margin-bottom:10px">
                            <i class="bi bi-arrow-left-right me-1"></i>الحسابان
                        </div>
                        <div class="row g-3 align-items-start">
                            <div class="col-md-6">
                                <label class="field-lbl">من حساب (المصدر) <span class="req">*</span></label>
                                <select id="trFrom" class="form-select form-select-sm"
                                    onchange="onAccountSelectionChange()">
                                    <option value="">— اختر —</option>
                                    <?php foreach ($cashAccounts as $acc): ?>
                                        <option value="<?= $acc['id'] ?>" data-cur="<?= $acc['currency_id'] ?>">
                                            <?= htmlspecialchars($acc['code'] . ' — ' . $acc['name'] . ' (' . ($acc['cur_code'] ?? '') . ')') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="trFromBal"
                                    style="font-size:.72rem;color:#64748b;margin-top:6px;min-height:18px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:4px 8px">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="field-lbl">إلى حساب (الوجهة) <span class="req">*</span></label>
                                <select id="trTo" class="form-select form-select-sm" onchange="onAccountSelectionChange()">
                                    <option value="">— اختر —</option>
                                    <?php foreach ($cashAccounts as $acc): ?>
                                        <option value="<?= $acc['id'] ?>" data-cur="<?= $acc['currency_id'] ?>">
                                            <?= htmlspecialchars($acc['code'] . ' — ' . $acc['name'] . ' (' . ($acc['cur_code'] ?? '') . ')') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="trToBal"
                                    style="font-size:.72rem;color:#64748b;margin-top:6px;min-height:18px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:4px 8px">
                                </div>
                            </div>
                        </div>
                        <div id="trSameCurrHint" style="display:none;margin-top:10px">
                            <div class="alert alert-success py-1 px-2 mb-0" style="font-size:.72rem;border-radius:8px">
                                <i class="bi bi-check-circle me-1"></i>نفس العملة — المبلغ الداخل = الخارج تلقائياً
                            </div>
                        </div>
                    </div>

                    <!-- قسم تفاصيل التحويل -->
                    <div style="border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin-bottom:12px">
                        <div style="font-size:.78rem;font-weight:700;color:#1e3a8a;margin-bottom:10px">
                            <i class="bi bi-cash-coin me-1"></i>تفاصيل التحويل
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="field-lbl">التاريخ</label>
                                <input type="date" id="trDate" class="form-control form-control-sm"
                                    value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="field-lbl" id="trOutLbl">المبلغ الخارج من المصدر <span class="req">*</span></label>
                                <input type="number" id="trAmountOut" class="form-control form-control-sm fw-600" min="0"
                                    step="0.01" dir="ltr" placeholder="0.00" oninput="onAmountOutInput()">
                            </div>
                            <div class="col-md-4">
                                <label class="field-lbl" id="trInLbl">المبلغ الداخل للوجهة <span class="req">*</span></label>
                                <input type="number" id="trAmountIn" class="form-control form-control-sm fw-600" min="0"
                                    step="0.01" dir="ltr" placeholder="0.00" oninput="updateDiffHint()">
                            </div>
                            <div class="col-12">
                                <label class="field-lbl">ملاحظات</label>
                                <input type="text" id="trNotes" class="form-control form-control-sm" placeholder="اختياري">
                            </div>
                        </div>
                    </div>

                    <div id="trDiffHint" class="alert py-2 mb-2" style="font-size:.78rem;border-radius:10px;display:none"></div>
                    <div class="alert alert-warning py-2 mb-0" style="font-size:.72rem;border-radius:10px">
                        <i class="bi bi-info-circle me-1"></i>
                        لو الحسابين بعملتين مختلفتين، أدخل المبلغ الفعلي يلي خرج من المصدر والمبلغ الفعلي يلي دخل للوجهة (نظام بيقترح المبلغ الداخل تلقائياً حسب سعر الصرف المسجَّل، وتقدر تعدّله لو سعر التفاوض الفعلي مختلف). أي فرق بينهم بينسجّل تلقائياً كربح أو خسارة صرف بقيود منفصلة.
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-sm fw-600" style="border-radius:8px;background:#1e3a8a;color:#fff;min-width:110px"
                        onclick="saveTransfer()" id="btnSaveTr">
                        <span id="saveTrTxt"><i class="bi bi-check-circle me-1"></i>تحويل وترحيل</span>
                        <span id="saveTrSpin" class="spinner-border spinner-border-sm" style="display:none"></span>
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

        const trModal = new bootstrap.Modal(document.getElementById('trModal'));
        const BASE_ID = '<?= (int) $baseCurId ?>';
        const BASE_SYM = <?= json_encode($baseSym) ?>;
        const CASH_ACCOUNTS = <?= json_encode(array_map(fn($a) => [
            'id' => $a['id'],
            'name' => $a['name'],
            'currency_id' => (int) $a['currency_id'],
            'cur_code' => $a['cur_code'] ?? '',
            'cur_sym' => $a['cur_sym'] ?? '',
            'balance' => (float) ($a['balance'] ?? 0),
            'base_balance' => (float) ($a['base_balance'] ?? 0),
        ], $cashAccounts)) ?>;
        // خريطة العملات: id → {code, symbol, rate} — rate هون بنفس اتفاقية
        // كل الصفحات التانية (1 عملة أساسية = rate عملة معيّنة)
        const CURR_MAP = <?= json_encode(array_column(array_map(fn($c) => [
            'id' => $c['id'], 'code' => $c['code'], 'symbol' => $c['symbol'], 'rate' => (float) $c['exchange_rate'],
        ], $currenciesList), null, 'id')) ?>;

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

        // ── عرض رصيد كل حساب فور اختياره + تجهيز حقلي المبلغ (خارج/داخل) ──
        function onAccountSelectionChange() {
            const fromId = document.getElementById('trFrom').value;
            const toId = document.getElementById('trTo').value;
            const fromAcc = CASH_ACCOUNTS.find(a => String(a.id) === fromId);
            const toAcc = CASH_ACCOUNTS.find(a => String(a.id) === toId);

            document.getElementById('trFromBal').innerHTML = fromAcc
                ? `الرصيد الحالي: <b>${fromAcc.cur_sym} ${fromAcc.balance.toFixed(2)}</b> (= ${BASE_SYM} ${fromAcc.base_balance.toFixed(2)})`
                : '';
            document.getElementById('trToBal').innerHTML = toAcc
                ? `الرصيد الحالي: <b>${toAcc.cur_sym} ${toAcc.balance.toFixed(2)}</b> (= ${BASE_SYM} ${toAcc.base_balance.toFixed(2)})`
                : '';

            document.getElementById('trOutLbl').innerHTML =
                'المبلغ الخارج من المصدر' + (fromAcc ? ` (${fromAcc.cur_sym} ${fromAcc.cur_code})` : '') + ' <span class="req">*</span>';
            document.getElementById('trInLbl').innerHTML =
                'المبلغ الداخل للوجهة' + (toAcc ? ` (${toAcc.cur_sym} ${toAcc.cur_code})` : '') + ' <span class="req">*</span>';

            const sameCurrency = fromAcc && toAcc && fromAcc.currency_id === toAcc.currency_id;
            document.getElementById('trSameCurrHint').style.display = sameCurrency ? '' : 'none';

            onAmountOutInput();
        }

        // اقتراح المبلغ الداخل تلقائياً حسب سعر الصرف المسجَّل (قابل للتعديل
        // اليدوي لو سعر التفاوض الفعلي مختلف — هون بالضبط بينحسب فرق الربح/الخسارة)
        function onAmountOutInput() {
            const fromId = document.getElementById('trFrom').value;
            const toId = document.getElementById('trTo').value;
            const fromAcc = CASH_ACCOUNTS.find(a => String(a.id) === fromId);
            const toAcc = CASH_ACCOUNTS.find(a => String(a.id) === toId);
            const amountOut = parseFloat(document.getElementById('trAmountOut').value || 0);
            if (!fromAcc || !toAcc || amountOut <= 0) { updateDiffHint(); return; }

            if (fromAcc.currency_id === toAcc.currency_id) {
                document.getElementById('trAmountIn').value = amountOut;
            } else {
                const fromRate = CURR_MAP[fromAcc.currency_id]?.rate ?? 1;
                const toRate = CURR_MAP[toAcc.currency_id]?.rate ?? 1;
                const suggested = (amountOut / fromRate) * toRate;
                document.getElementById('trAmountIn').value = suggested.toFixed(2);
            }
            updateDiffHint();
        }

        // معاينة فرق الربح/الخسارة قبل الحفظ (تقديري بعملة الفرع)
        function updateDiffHint() {
            const fromId = document.getElementById('trFrom').value;
            const toId = document.getElementById('trTo').value;
            const fromAcc = CASH_ACCOUNTS.find(a => String(a.id) === fromId);
            const toAcc = CASH_ACCOUNTS.find(a => String(a.id) === toId);
            const el = document.getElementById('trDiffHint');
            const amountOut = parseFloat(document.getElementById('trAmountOut').value || 0);
            const amountIn = parseFloat(document.getElementById('trAmountIn').value || 0);
            if (!fromAcc || !toAcc || amountOut <= 0 || amountIn <= 0) { el.style.display = 'none'; return; }

            const fromRate = CURR_MAP[fromAcc.currency_id]?.rate ?? 1;
            const toRate = CURR_MAP[toAcc.currency_id]?.rate ?? 1;
            const baseFrom = amountOut / fromRate;
            const baseTo = amountIn / toRate;
            const diff = baseTo - baseFrom;

            if (Math.abs(diff) < 0.01) {
                el.style.display = 'none';
                return;
            }
            el.style.display = '';
            if (diff > 0) {
                el.className = 'alert alert-success py-2';
                el.innerHTML = `<i class="bi bi-graph-up-arrow me-1"></i>سيُسجَّل ربح صرف تقديري: <b>${BASE_SYM} ${diff.toFixed(2)}</b>`;
            } else {
                el.className = 'alert alert-danger py-2';
                el.innerHTML = `<i class="bi bi-graph-down-arrow me-1"></i>ستُسجَّل خسارة صرف تقديرية: <b>${BASE_SYM} ${Math.abs(diff).toFixed(2)}</b>`;
            }
        }

        function openTransfer(fromId) {
            document.getElementById('trFrom').value = fromId || '';
            document.getElementById('trTo').value = '';
            document.getElementById('trDate').value = new Date().toISOString().split('T')[0];
            document.getElementById('trAmountOut').value = '';
            document.getElementById('trAmountIn').value = '';
            document.getElementById('trNotes').value = '';
            document.getElementById('trDiffHint').style.display = 'none';
            onAccountSelectionChange();
            trModal.show();
        }

        function saveTransfer() {
            const fromId = document.getElementById('trFrom').value;
            const toId = document.getElementById('trTo').value;
            const amountOut = parseFloat(document.getElementById('trAmountOut').value || 0);
            const amountIn = parseFloat(document.getElementById('trAmountIn').value || 0);
            if (!fromId || !toId) { toast('اختر حساب المصدر والوجهة', 'danger'); return; }
            if (fromId === toId) { toast('لا يمكن التحويل لنفس الحساب', 'danger'); return; }
            if (amountOut <= 0 || amountIn <= 0) { toast('أدخل المبلغين (الخارج والداخل) أكبر من صفر', 'danger'); return; }

            document.getElementById('saveTrTxt').style.opacity = '0';
            document.getElementById('saveTrSpin').style.display = 'inline-block';
            document.getElementById('btnSaveTr').disabled = true;

            post({
                _action: 'save_transfer',
                from_account_id: fromId,
                to_account_id: toId,
                transfer_date: document.getElementById('trDate').value,
                amount_out: amountOut,
                amount_in: amountIn,
                notes: document.getElementById('trNotes').value,
            }).then(d => {
                document.getElementById('saveTrTxt').style.opacity = '1';
                document.getElementById('saveTrSpin').style.display = 'none';
                document.getElementById('btnSaveTr').disabled = false;
                if (d.ok) { toast('✅ ' + d.msg + ' — ' + d.no); trModal.hide(); setTimeout(() => location.reload(), 800); }
                else toast(d.msg, 'danger');
            });
        }

        // ── تحميل ملخص آخر حركة لكل صندوق عند فتح الصفحة ──
        function loadMiniMovements() {
            CASH_ACCOUNTS.forEach(acc => {
                post({ _action: 'get_recent_movements', account_id: acc.id }).then(d => {
                    const el = document.getElementById('mov_' + acc.id);
                    if (!el) return;
                    if (!d.ok || !d.data || !d.data.length) {
                        el.innerHTML = '<span style="color:#cbd5e1">لا توجد حركة مرحّلة</span>';
                        return;
                    }
                    const last = d.data[0];
                    const amt = parseFloat(last.debit) > 0 ? parseFloat(last.debit) : parseFloat(last.credit);
                    const dir = parseFloat(last.debit) > 0 ? 'وارد' : 'صادر';
                    const color = parseFloat(last.debit) > 0 ? '#16a34a' : '#dc2626';
                    el.innerHTML = `آخر حركة: ${last.entry_date} — <span style="color:${color};font-weight:600">${dir} ${amt.toFixed(2)}</span>`;
                });
            });
        }
        loadMiniMovements();
    
    </script>

</body>

</html>
