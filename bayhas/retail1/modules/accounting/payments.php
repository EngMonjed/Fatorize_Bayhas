<?php
/**
 * accounting/payments.php — سندات الدفع
 *retail1/modules/accounting/payments.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('finance.payments', 'view');
$currentModule = 'finance.payments';

$TS = $_SESSION['table_suffix'];
$TP = "purchase_payments_{$TS}";
$TPI = "purchase_payment_invoices_{$TS}";
$TPU = "purchases_{$TS}";
$TSP = "product_suppliers_{$TS}";
$TJE = "journal_entries_{$TS}";
$TJI = "journal_entry_items_{$TS}";
$TAC = "account_charts_{$TS}";
$TIAS = "invoice_account_settings_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

function genPaymentNo(PDO $pdo, string $table): string
{
    $y = date('Y');
    $last = $pdo->query("SELECT payment_number FROM `{$table}`
        WHERE payment_number LIKE 'PAY-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $seq = $last ? (int) substr($last, -4) + 1 : 1;
    return 'PAY-' . $y . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

function genEntryNo(PDO $pdo, string $table): string
{
    $y = date('Y');
    $last = $pdo->query("SELECT entry_number FROM `{$table}`
        WHERE entry_number LIKE 'JE-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $seq = $last ? (int) substr($last, -4) + 1 : 1;
    return 'JE-' . $y . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

// ── جلب حساب من الإعدادات ──
function getSettingAccount(PDO $pdo, string $tias, string $key, string $tac): ?array
{
    // ترحيل alp→ret، ونجت من كل عمليات البحث السابقة لأنها جوا نص SQL
    // مباشرة، مش مسار ملف. الجدول القديم غير موجود إطلاقاً الآن — أي
    // استدعاء لهالدالة كان يفشل بخطأ "Unknown table".
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


        // ── جلب فواتير مورد غير مدفوعة ──
        // ⚠ افتراض: purchases_{TS} فيها نفس بنية sales_invoices_{TS}
        // (purchase_number, purchase_date, total_amount, paid_amount,
        // balance_amount, payment_status, supplier_id, status, exchange_rate)
        // — لازم تأكيد إذا أسماء الأعمدة الفعلية مختلفة.
        if ($act === 'get_supplier_invoices') {
            $supId = (int) $_POST['supplier_id'];
            $st = $pdo->prepare("SELECT id, purchase_number, purchase_date, total_amount,
                paid_amount, balance_amount, payment_status
                FROM `{$TPU}`
                WHERE supplier_id=? AND status='confirmed'
                AND payment_status IN ('pending','partial')
                ORDER BY purchase_date");
            $st->execute([$supId]);
            echo json_encode(['ok' => true, 'data' => $st->fetchAll()]);
        }

        // ── حفظ سند دفع (لمورد محدد أو دفعة عامة بدون مورد) ──
        elseif ($act === 'save_payment') {
            requirePermission('finance.payments', 'create');
            $supId = (int) ($_POST['supplier_id'] ?? 0) ?: null;
            $debitAccIdInput = (int) ($_POST['debit_account_id'] ?? 0) ?: null;
            $date = $_POST['payment_date'] ?? date('Y-m-d');
            $amount = (float) $_POST['amount'];
            $currency = $_POST['currency'] ?? 'USD';
            $rate = max(0.0001, (float) ($_POST['exchange_rate'] ?? 1));
            $method = $_POST['payment_method'] ?? 'cash';
            $cashAccId = (int) ($_POST['cash_account_id'] ?? 0) ?: null;
            $notes = trim($_POST['notes'] ?? '');
            $allocs = $supId ? json_decode($_POST['allocations'] ?? '[]', true) : [];
            $whTaxId = $supId ? ((int) ($_POST['withholding_tax_type_id'] ?? 0) ?: null) : null;

            // دفعة لمورد محدد: لازم supplier_id. دفعة عامة (بدون مورد): لازم
            // اختيار الحساب المدين مباشرة (مصروف/التزام تاني مش مورد مسجّل).
            if (!$supId && !$debitAccIdInput)
                throw new Exception('يجب اختيار مورد، أو تحديد الحساب المدين لدفعة عامة');
            if ($amount <= 0)
                throw new Exception('المبلغ يجب أن يكون أكبر من صفر');

            $amountBase = $amount / $rate;

            // ── استقطاع ضريبي اختياري (بس لدفعات الموردين) ──
            // المورد بتتقيَّد ذمته كاملة (كأنه استلم كامل المبلغ)، بس
            // المبلغ الفعلي الخارج من الصندوق أقل بمقدار الاستقطاع —
            // الفرق بينحط بحساب "ضريبة استقطاع مستحقة" (التزام للدولة).
            $whAmount = 0; // بعملة السند
            $whAccount = null;
            if ($whTaxId) {
                $whTax = $pdo->prepare("SELECT * FROM `tax_types_{$TS}` WHERE id=? AND tax_scope='withholding' AND is_active=1");
                $whTax->execute([$whTaxId]);
                $whTax = $whTax->fetch();
                if ($whTax) {
                    $whAmount = $whTax['calc_type'] === 'percentage'
                        ? round($amount * (float) $whTax['rate_value'] / 100, 4)
                        : min((float) $whTax['rate_value'], $amount);
                    $whAccount = getSettingAccount($pdo, $TIAS, 'withholding_tax_payable', $TAC);
                    if (!$whAccount)
                        throw new Exception('اخترت استقطاع ضريبي، بس حساب "ضريبة استقطاع مستحقة" مش مضبوط بإعدادات الربط المحاسبي');
                }
            }
            $whAmountBase = $whAmount / $rate;
            $netCashAmount = $amount - $whAmount; // بعملة السند
            $netCashBase = $amountBase - $whAmountBase;

            // 🔴 journal_entries/journal_entry_items الحقيقيان عندك فيهم
            // currency_id (FK رقمي) مش عمود currency نصي — كان القيد هون
            // رح يفشل بخطأ SQL وقت الترحيل. نحلّ كود العملة (USD/SYP/TRY)
            // لمعرّفه الرقمي قبل أي INSERT بجداول القيود.
            $currIdSt = $pdo->prepare("SELECT id FROM currencies WHERE code=? LIMIT 1");
            $currIdSt->execute([$currency]);
            $currId = (int) $currIdSt->fetchColumn();
            if (!$currId) {
                $currId = (int) ($pdo->query("SELECT id FROM currencies WHERE is_base=1 LIMIT 1")->fetchColumn() ?: 1);
            }

            // التحقق من مجموع التوزيع (فقط لو في مورد محدد وفواتير مرتبطة)
            $totalAlloc = array_sum(array_column($allocs, 'amount'));
            if ($totalAlloc > $amountBase + 0.01)
                throw new Exception('مجموع التوزيع (' . number_format($totalAlloc, 2) . ') أكبر من مبلغ السند (' . number_format($amountBase, 2) . ')');

            $supName = null;
            $supRow = null;
            if ($supId) {
                // جلب اسم المورد + حساب الذمة المخصص فيه (suppliers.php
                // بينشئه تلقائياً لكل مورد جديد تحت حساب "ذمم الموردين" الأب)
                $cSt = $pdo->prepare("SELECT name, account_id FROM `{$TSP}` WHERE id=?");
                $cSt->execute([$supId]);
                $supRow = $cSt->fetch();
                $supName = $supRow['name'] ?? '';
            }

            $pdo->beginTransaction();
            try {
                $payNo = genPaymentNo($pdo, $TP);
                $pdo->prepare("INSERT INTO `{$TP}` (payment_number,payment_date,supplier_id,supplier_name,
                    amount,currency,exchange_rate,amount_base,payment_method,cash_account_id,debit_account_id,notes,status,created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'draft',?)")
                    ->execute([
                        $payNo,
                        $date,
                        $supId,
                        $supName,
                        $amount,
                        $currency,
                        $rate,
                        $amountBase,
                        $method,
                        $cashAccId,
                        $debitAccIdInput,
                        $notes,
                        $_SESSION['user_id']
                    ]);
                $payId = (int) $pdo->lastInsertId();

                // توزيع على فواتير الشراء (فقط لو في مورد محدد)
                foreach ($allocs as $al) {
                    $invId = (int) $al['invoice_id'];
                    $alAmt = (float) $al['amount'];
                    if (!$invId || $alAmt <= 0)
                        continue;
                    $pdo->prepare("INSERT INTO `{$TPI}` (payment_id,purchase_id,allocated_amount)
                        VALUES (?,?,?)")->execute([$payId, $invId, $alAmt]);
                    // تحديث فاتورة الشراء
                    $pdo->prepare("UPDATE `{$TPU}` SET
                        paid_amount=paid_amount+?,
                        balance_amount=GREATEST(0,balance_amount-?),
                        payment_status=CASE
                            WHEN balance_amount-? <= 0.01 THEN 'paid'
                            ELSE 'partial' END
                        WHERE id=?")
                        ->execute([$alAmt, $alAmt, $alAmt, $invId]);
                }

                // ── إنشاء قيد محاسبي تلقائي ──
                // مدين: حساب المورد المخصص (suppliers.account_id) لو دفعة
                // لمورد محدد، أو الحساب يلي اختاره المستخدم مباشرة لو دفعة
                // عامة (مصروف/التزام تاني). الرجوع للحساب الأب المشترك
                // (supplier_payable بالإعدادات) محفوظ فقط كـ fallback
                // لموردين قدام ما إلهم account_id مضبوط.
                $accDebit = null;
                if ($supId) {
                    if (!empty($supRow['account_id'])) {
                        $accDebit = $pdo->query("SELECT * FROM `{$TAC}` WHERE id=" . (int) $supRow['account_id'])->fetch();
                    }
                    if (!$accDebit) {
                        $accDebit = getSettingAccount($pdo, $TIAS, 'supplier_payable', $TAC);
                    }
                } else {
                    $accDebit = $pdo->query("SELECT * FROM `{$TAC}` WHERE id=" . (int) $debitAccIdInput)->fetch();
                }

                // دائن: الصندوق/البنك — نفس منطق cash_{currency} الديناميكي
                $accCash = $cashAccId ?
                    $pdo->query("SELECT * FROM `{$TAC}` WHERE id={$cashAccId}")->fetch() :
                    getSettingAccount($pdo, $TIAS, 'cash_' . strtolower($currency), $TAC);

                if ($cashAccId && $accCash) {
                    $accCurCode = $pdo->prepare("SELECT code FROM currencies WHERE id=?");
                    $accCurCode->execute([$accCash['currency_id'] ?? 0]);
                    $accCurCode = $accCurCode->fetchColumn();
                    if ($accCurCode && $accCurCode !== $currency) {
                        throw new Exception("عملة حساب الصندوق المختار ({$accCurCode}) لا تطابق عملة السند ({$currency})");
                    }
                }

                if ($accDebit && $accCash) {
                    $jeNo = genEntryNo($pdo, $TJE);
                    $desc = $supId ? "دفع للمورد {$supName} — سند {$payNo}" : "دفعة عامة — سند {$payNo}";
                    $pdo->prepare("INSERT INTO `{$TJE}` (entry_number,entry_date,description,currency_id,
                        exchange_rate,total_debit,total_credit,status,reference_type,reference_id,created_by)
                        VALUES (?,?,?,?,?,?,?,'draft','payment',?,?)")
                        ->execute([
                            $jeNo,
                            $date,
                            $desc,
                            $currId,
                            $rate,
                            $amountBase,
                            $amountBase,
                            $payId,
                            $_SESSION['user_id']
                        ]);
                    $jeId = (int) $pdo->lastInsertId();

                    // مدين: ذمم المورد (أو الحساب المختار لدفعة عامة)
                    $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,
                        original_amount,base_amount,description,currency_id,exchange_rate)
                        VALUES (?,?,?,0,?,?,?,?,?)")
                        ->execute([
                            $jeId,
                            $accDebit['id'],
                            $amountBase,
                            $amount,
                            $amountBase,
                            $supId ? "ذمم — {$supName}" : "دفعة — {$payNo}",
                            $currId,
                            $rate
                        ]);
                    // دائن: الصندوق/البنك — المبلغ الصافي بعد أي استقطاع ضريبي
                    // (لو ماكو استقطاع، netCashBase/netCashAmount = amountBase/amount تماماً)
                    $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,
                        original_amount,base_amount,description,currency_id,exchange_rate)
                        VALUES (?,?,0,?,?,?,?,?,?)")
                        ->execute([
                            $jeId,
                            $accCash['id'],
                            $netCashBase,
                            $netCashAmount,
                            $netCashBase,
                            "دفع — {$payNo}",
                            $currId,
                            $rate
                        ]);

                    // دائن: ضريبة استقطاع مستحقة (فقط لو في استقطاع فعلي)
                    if ($whAmount > 0 && $whAccount) {
                        $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,
                            original_amount,base_amount,description,currency_id,exchange_rate)
                            VALUES (?,?,0,?,?,?,?,?,?)")
                            ->execute([
                                $jeId,
                                $whAccount['id'],
                                $whAmountBase,
                                $whAmount,
                                $whAmountBase,
                                "استقطاع ضريبي — {$payNo}",
                                $currId,
                                $rate
                            ]);
                    }

                    $pdo->prepare("UPDATE `{$TP}` SET journal_entry_id=? WHERE id=?")->execute([$jeId, $payId]);
                }

                $pdo->commit();
                echo json_encode(['ok' => true, 'id' => $payId, 'no' => $payNo, 'je_created' => ($accDebit && $accCash), 'withheld' => $whAmount]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        // ── ترحيل سند ──
        elseif ($act === 'post_payment') {
            requirePermission('finance.payments', 'confirm');
            $id = (int) $_POST['id'];
            $st = $pdo->prepare("SELECT * FROM `{$TP}` WHERE id=?");
            $st->execute([$id]);
            $rcp = $st->fetch();
            if (!$rcp)
                throw new Exception('السند غير موجود');
            if ($rcp['status'] !== 'draft')
                throw new Exception('يمكن ترحيل المسودات فقط');

            $pdo->beginTransaction();
            try {
                // ترحيل القيد المحاسبي
                if ($rcp['journal_entry_id']) {
                    $items = $pdo->prepare("SELECT * FROM `{$TJI}` WHERE journal_entry_id=?");
                    $items->execute([$rcp['journal_entry_id']]);
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
                        ->execute([$_SESSION['user_id'], $rcp['journal_entry_id']]);
                }
                $pdo->prepare("UPDATE `{$TP}` SET status='posted',updated_by=?,updated_at=NOW() WHERE id=?")
                    ->execute([$_SESSION['user_id'], $id]);
                $pdo->commit();
                echo json_encode(['ok' => true, 'msg' => 'تم ترحيل سند الدفع وتحديث الأرصدة']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        // ── إلغاء سند ──
        elseif ($act === 'cancel_payment') {
            requirePermission('finance.payments', 'edit');
            $id = (int) $_POST['id'];
            $st = $pdo->prepare("SELECT * FROM `{$TP}` WHERE id=?");
            $st->execute([$id]);
            $rcp = $st->fetch();
            if (!$rcp)
                throw new Exception('السند غير موجود');
            if ($rcp['status'] === 'cancelled')
                throw new Exception('ملغى مسبقاً');

            $pdo->beginTransaction();
            try {
                // عكس التوزيع على الفواتير
                $allocs = $pdo->prepare("SELECT * FROM `{$TPI}` WHERE payment_id=?");
                $allocs->execute([$id]);
                foreach ($allocs->fetchAll() as $al) {
                    $pdo->prepare("UPDATE `{$TPU}` SET
                        paid_amount=GREATEST(0,paid_amount-?),
                        balance_amount=balance_amount+?,
                        payment_status=CASE WHEN paid_amount-? <= 0.01 THEN 'pending' ELSE 'partial' END
                        WHERE id=?")
                        ->execute([$al['allocated_amount'], $al['allocated_amount'], $al['allocated_amount'], $al['purchase_id']]);
                }
                // عكس القيد المحاسبي إذا مرحّل
                if ($rcp['journal_entry_id'] && $rcp['status'] === 'posted') {
                    $items = $pdo->prepare("SELECT * FROM `{$TJI}` WHERE journal_entry_id=?");
                    $items->execute([$rcp['journal_entry_id']]);
                    foreach ($items->fetchAll() as $item) {
                        $net = $item['debit'] - $item['credit'];
                        $ownNet = ((float) $item['debit'] > 0) ? (float) $item['original_amount'] : -(float) $item['original_amount'];
                        $pdo->prepare("UPDATE `{$TAC}` SET
                                base_balance=base_balance-?,
                                balance=balance-(CASE WHEN currency_id=? THEN ? ELSE ? END),
                                updated_at=NOW()
                            WHERE id=?")
                            ->execute([$net, $item['currency_id'], $ownNet, $net, $item['account_id']]);
                    }
                    $pdo->prepare("UPDATE `{$TJE}` SET status='cancelled',cancelled_at=NOW(),cancelled_by=? WHERE id=?")
                        ->execute([$_SESSION['user_id'], $rcp['journal_entry_id']]);
                }
                $pdo->prepare("UPDATE `{$TP}` SET status='cancelled',updated_by=?,updated_at=NOW() WHERE id=?")
                    ->execute([$_SESSION['user_id'], $id]);
                $pdo->commit();
                echo json_encode(['ok' => true, 'msg' => 'تم إلغاء السند وعكس التأثيرات']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        // ── جلب سند ──
        elseif ($act === 'get_payment') {
            $id = (int) $_POST['id'];
            $st = $pdo->prepare("SELECT r.*,ac.name AS cash_acc_name
                FROM `{$TP}` r LEFT JOIN `{$TAC}` ac ON ac.id=r.cash_account_id WHERE r.id=?");
            $st->execute([$id]);
            $rcp = $st->fetch();
            if (!$rcp)
                throw new Exception('السند غير موجود');
            $allocs = $pdo->prepare("SELECT ri.*,si.purchase_number,si.purchase_date,si.total_amount
                FROM `{$TPI}` ri JOIN `{$TPU}` si ON si.id=ri.purchase_id WHERE ri.payment_id=?");
            $allocs->execute([$id]);
            $rcp['allocations'] = $allocs->fetchAll();
            echo json_encode(['ok' => true, 'data' => $rcp]);
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
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');
$statusF = $_GET['status'] ?? '';
$supF = (int) ($_GET['supplier'] ?? 0);
$search = trim($_GET['q'] ?? '');

$where = 'WHERE r.payment_date BETWEEN ? AND ?';
$params = [$dateFrom, $dateTo];
if ($statusF) {
    $where .= ' AND r.status=?';
    $params[] = $statusF;
}
if ($supF) {
    $where .= ' AND r.supplier_id=?';
    $params[] = $supF;
}
if ($search) {
    $where .= ' AND (r.payment_number LIKE ? OR r.supplier_name LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$payments = $pdo->prepare("SELECT r.*,
    COUNT(ri.id) AS invoices_count
    FROM `{$TP}` r
    LEFT JOIN `{$TPI}` ri ON ri.payment_id=r.id
    {$where}
    GROUP BY r.id ORDER BY r.payment_date DESC,r.id DESC LIMIT 200");
$payments->execute($params);
$payments = $payments->fetchAll();

$suppliers = $pdo->query("SELECT id,name,phone,account_id FROM `{$TSP}` WHERE status='active' ORDER BY name")->fetchAll();
$cashAccounts = $pdo->query("SELECT ac.id,ac.code,ac.name,c.code AS cur_code
    FROM `{$TAC}` ac LEFT JOIN currencies c ON c.id=ac.currency_id
    WHERE ac.account_type='asset' AND ac.is_active=1 AND ac.level>=3
    AND (ac.code LIKE '111%' OR ac.code LIKE '112%') ORDER BY ac.code")->fetchAll();
// حسابات عامة لدفعة "بدون مورد محدد" (مصاريف نثرية، سداد التزام تاني...)
// — نفس قائمة الحسابات المستخدمة بـjournal.php
$generalAccounts = $pdo->query("SELECT id,code,name,account_type FROM `{$TAC}`
    WHERE is_active=1 ORDER BY code")->fetchAll();

// أنواع الاستقطاع الضريبي النشطة (تُدار من taxes.php) — خيار اختياري
// عند دفع مورد/مقاول، يخصم نسبة/مبلغ من الدفعة الفعلية ويحوّله لحساب
// "ضريبة استقطاع مستحقة" بدل ما ينزل بالكامل من الصندوق.
try {
    $withholdingTypes = $pdo->query("SELECT id,name,calc_type,rate_value
        FROM `tax_types_{$TS}` WHERE tax_scope='withholding' AND is_active=1 ORDER BY name")->fetchAll();
} catch (Exception $e) {
    $withholdingTypes = [];
}

// ── قائمة العملات الحقيقية من جدول currencies (بدل قائمة/رموز ثابتة بالكود) ──
$currenciesList = $pdo->query("SELECT id,code,name,symbol,is_base FROM currencies WHERE status='active' ORDER BY is_base DESC,id")->fetchAll();
$baseCur = null;
foreach ($currenciesList as $c) {
    if ($c['is_base']) { $baseCur = $c; break; }
}
if (!$baseCur) $baseCur = $currenciesList[0] ?? ['code' => 'USD', 'name' => 'دولار', 'symbol' => '$'];
$baseSym = htmlspecialchars($baseCur['symbol'] ?? '$');

try {
    $stats = $pdo->query("SELECT COUNT(*) AS total,
        SUM(status='draft') AS drafts,
        SUM(status='posted') AS posted,
        COALESCE(SUM(CASE WHEN status='posted' THEN amount_base END),0) AS total_base
        FROM `{$TP}`")->fetch();
} catch (Exception $e) {
    $stats = ['total' => 0, 'drafts' => 0, 'posted' => 0, 'total_base' => 0];
}

$STATUS_MAP = [
    'draft' => ['label' => 'مسودة', 'cls' => 'bg-secondary-subtle text-secondary'],
    'posted' => ['label' => 'مرحّل', 'cls' => 'bg-success-subtle text-success'],
    'cancelled' => ['label' => 'ملغى', 'cls' => 'bg-danger-subtle text-danger'],
];
$PAY_METHOD = ['cash' => 'نقدي', 'bank' => 'بنك', 'card' => 'بطاقة', 'check' => 'شيك'];
$CURR_SYM = array_column($currenciesList, 'symbol', 'code');
$payNo = genPaymentNo($pdo, $TP);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>سندات الدفع — <?= htmlspecialchars($branchName) ?></title>
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
            background: #f8fff8
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

        .act-btn.success-h:hover {
            background: #dcfce7;
            color: #16a34a;
            border-color: #86efac
        }

        .act-btn.danger:hover {
            background: #fee2e2;
            color: #dc2626;
            border-color: #fca5a5
        }

        .act-btn.info-h:hover {
            background: #e0f2fe;
            color: #0891b2;
            border-color: #7dd3fc
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

        .inv-alloc-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 90px 80px 90px 80px;
            gap: 6px;
            align-items: center;
            padding: 6px 8px;
            border-radius: 8px;
            background: #f8fafc;
            margin-bottom: 4px;
            font-size: .78rem
        }

        .inv-alloc-row.selected {
            background: #eff6ff;
            border: 1px solid #bfdbfe
        }

        .alloc-input {
            width: 100%;
            padding: 3px 6px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: .78rem;
            text-align: left;
            direction: ltr
        }

        .alloc-input:focus {
            outline: none;
            border-color: #16a34a
        }

        .balance-bar {
            background: #f1f5f9;
            border-radius: 8px;
            padding: 8px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: .8rem
        }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-cash-stack me-1 text-danger"></i>سندات الدفع</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
            <span>المالية</span>
            <i class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
            <span class="text-danger fw-600">سندات الدفع</span>
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
                    <a class="nav-link fw-600 active" href="payments.php" style="border:none;border-bottom:2px solid #1e3a8a;color:#1e3a8a;font-size:.83rem;margin-bottom:-2px">
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
                    <a class="nav-link fw-600" href="shipping_carriers.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-truck me-1"></i>شركات الشحن
                    </a>
                </li>
            </ul>


            <!-- إحصائيات -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#f0fdf4"><i class="bi bi-cash-stack text-success"></i>
                        </div>
                        <div>
                            <div class="stat-val"><?= $stats['total'] ?></div>
                            <div class="stat-lbl">إجمالي السندات</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fef3c7"><i class="bi bi-hourglass text-warning"></i>
                        </div>
                        <div>
                            <div class="stat-val"><?= $stats['drafts'] ?></div>
                            <div class="stat-lbl">مسودات</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#f0fdf4"><i
                                class="bi bi-check-circle text-success"></i></div>
                        <div>
                            <div class="stat-val"><?= $stats['posted'] ?></div>
                            <div class="stat-lbl">مرحّلة</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#f0fdf4"><i
                                class="bi bi-currency-dollar text-success"></i></div>
                        <div>
                            <div class="stat-val n"><?= $baseSym ?> <?= number_format($stats['total_base'], 2) ?></div>
                            <div class="stat-lbl">إجمالي المدفوع</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- فلاتر -->
            <div class="tbl-wrap mb-3">
                <div class="tbl-hdr">
                    <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                            placeholder="رقم السند أو المورد..." class="form-control form-control-sm"
                            style="width:180px;border-radius:8px">
                        <select name="supplier" class="form-select form-select-sm" style="width:160px;border-radius:8px"
                            onchange="this.form.submit()">
                            <option value="">كل الموردين</option>
                            <?php foreach ($suppliers as $c): ?>
                                        <option value="<?= $c['id'] ?>" <?= $supF == $c['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="status" class="form-select form-select-sm" style="width:110px;border-radius:8px"
                            onchange="this.form.submit()">
                            <option value="">كل الحالات</option>
                            <?php foreach ($STATUS_MAP as $k => $v): ?>
                                        <option value="<?= $k ?>" <?= $statusF === $k ? 'selected' : '' ?>><?= $v['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>"
                            class="form-control form-control-sm" style="width:140px;border-radius:8px">
                        <span style="color:#94a3b8">—</span>
                        <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>"
                            class="form-control form-control-sm" style="width:140px;border-radius:8px">
                        <button type="submit" class="btn btn-sm btn-primary" style="border-radius:8px"><i
                                class="bi bi-search me-1"></i>بحث</button>
                        <?php if ($search || $statusF || $supF): ?>
                                    <a href="?from=<?= $dateFrom ?>&to=<?= $dateTo ?>" class="btn btn-sm btn-light"
                                        style="border-radius:8px"><i class="bi bi-x-lg"></i></a>
                        <?php endif; ?>
                    </form>
                    <button class="btn btn-sm fw-600 ms-auto"
                        style="border-radius:8px;background:#16a34a;color:#fff;font-size:.82rem;white-space:nowrap"
                        onclick="openNewPayment()">
                        <i class="bi bi-plus-lg me-1"></i>سند دفع جديد
                    </button>
                </div>
            </div>

            <!-- الجدول -->
            <div class="tbl-wrap">
                <div class="table-responsive">
                    <table class="mtbl">
                        <thead>
                            <tr>
                                <th>رقم السند</th>
                                <th>التاريخ</th>
                                <th>المورد</th>
                                <th>طريقة الدفع</th>
                                <th>العملة</th>
                                <th class="text-end">المبلغ</th>
                                <th class="text-end">بعملة الفرع (<?= $baseSym ?>)</th>
                                <th class="text-center">الفواتير</th>
                                <th>الحالة</th>
                                <th style="text-align:center">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payments)): ?>
                                        <tr>
                                            <td colspan="10" class="text-center text-muted py-5">
                                                <i class="bi bi-cash-stack d-block mb-2" style="font-size:2rem;opacity:.2"></i>
                                                لا توجد سندات دفع
                                            </td>
                                        </tr>
                            <?php endif; ?>
                            <?php foreach ($payments as $rcp):
                                $st = $STATUS_MAP[$rcp['status']] ?? $STATUS_MAP['draft'];
                                $sym = $CURR_SYM[$rcp['currency']] ?? '$';
                                ?>
                                        <tr>
                                            <td class="n fw-600" style="direction:ltr;color:#16a34a">
                                                <?= htmlspecialchars($rcp['payment_number']) ?></td>
                                            <td class="text-muted"><?= $rcp['payment_date'] ?></td>
                                            <td class="fw-600" style="font-size:.83rem">
                                                <?= htmlspecialchars($rcp['supplier_name'] ?? 'دفعة عامة') ?></td>
                                            <td>
                                                <span class="badge bg-secondary-subtle text-secondary" style="font-size:.68rem">
                                                    <i
                                                        class="bi bi-<?= $rcp['payment_method'] === 'cash' ? 'cash-coin' : ($rcp['payment_method'] === 'bank' ? 'bank' : 'credit-card') ?> me-1"></i>
                                                    <?= $PAY_METHOD[$rcp['payment_method']] ?? $rcp['payment_method'] ?>
                                                </span>
                                            </td>
                                            <td style="font-size:.75rem"><?= $rcp['currency'] ?></td>
                                            <td class="n text-end fw-600"><?= $sym ?>             <?= number_format($rcp['amount'], 2) ?></td>
                                            <td class="n text-end fw-600 text-success"><?= $baseSym ?> <?= number_format($rcp['amount_base'], 2) ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($rcp['invoices_count']): ?>
                                                            <span class="badge bg-primary-subtle text-primary"><?= $rcp['invoices_count'] ?>
                                                                فاتورة</span>
                                                <?php else: ?>
                                                            <span class="badge bg-warning-subtle text-warning">بدون توزيع</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge <?= $st['cls'] ?>"
                                                    style="font-size:.68rem"><?= $st['label'] ?></span></td>
                                            <td>
                                                <div class="d-flex gap-1 justify-content-center">
                                                    <button class="act-btn info-h" onclick="viewPayment(<?= $rcp['id'] ?>)"
                                                        title="عرض">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <?php if ($rcp['status'] === 'draft'): ?>
                                                                <button class="act-btn success-h"
                                                                    onclick="postPayment(<?= $rcp['id'] ?>,'<?= htmlspecialchars($rcp['payment_number'], ENT_QUOTES) ?>')"
                                                                    title="ترحيل">
                                                                    <i class="bi bi-check-circle"></i>
                                                                </button>
                                                    <?php endif; ?>
                                                    <?php if ($rcp['status'] !== 'cancelled'): ?>
                                                                <button class="act-btn danger"
                                                                    onclick="cancelPayment(<?= $rcp['id'] ?>,'<?= htmlspecialchars($rcp['payment_number'], ENT_QUOTES) ?>')"
                                                                    title="إلغاء">
                                                                    <i class="bi bi-x-circle"></i>
                                                                </button>
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
    </main>

    <!-- مودال سند جديد -->
    <div class="modal fade" id="rcpModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#065f46,#16a34a);border-radius:16px 16px 0 0">
                    <div>
                        <h6 class="modal-title text-white fw-700 mb-0"><i class="bi bi-cash-coin me-2"></i>سند دفع جديد
                        </h6>
                        <div style="font-size:.72rem;color:rgba(255,255,255,.7);margin-top:2px" dir="ltr">
                            <?= htmlspecialchars($payNo) ?></div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pt-3">

                    <!-- بيانات السند -->
                    <div class="row g-3 mb-3 pb-3" style="border-bottom:1px solid #f1f5f9">
                        <div class="col-md-6">
                            <label class="field-lbl">المورد <span style="color:#94a3b8;font-weight:400">(اتركه فاضي لدفعة عامة)</span></label>
                            <select id="pSup" class="form-select form-select-sm" onchange="onSupplierChange()">
                                <option value="">— اختر المورد (أو اتركه لدفعة عامة) —</option>
                                <?php foreach ($suppliers as $c): ?>
                                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6" id="pDebitAccWrap" style="display:none">
                            <label class="field-lbl">الحساب المدين (دفعة عامة) <span class="req">*</span></label>
                            <select id="pDebitAcc" class="form-select form-select-sm">
                                <option value="">— اختر الحساب —</option>
                                <?php foreach ($generalAccounts as $ga): ?>
                                            <option value="<?= $ga['id'] ?>">
                                                <?= htmlspecialchars($ga['code'] . ' — ' . $ga['name']) ?>
                                            </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="field-lbl">التاريخ <span class="req">*</span></label>
                            <input type="date" id="rDate" class="form-control form-control-sm"
                                value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="field-lbl">طريقة الدفع</label>
                            <select id="rMethod" class="form-select form-select-sm">
                                <option value="cash">نقدي</option>
                                <option value="bank">تحويل بنكي</option>
                                <option value="card">بطاقة</option>
                                <option value="check">شيك</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="field-lbl">العملة</label>
                            <select id="rCurr" class="form-select form-select-sm" onchange="onRcpCurrencyChange()">
                                <?php foreach ($currenciesList as $c): ?>
                                    <option value="<?= htmlspecialchars($c['code']) ?>" <?= $c['id'] == $baseCur['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['code'] . ' ' . ($c['symbol'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2" id="rRateWrap">
                            <label class="field-lbl">سعر الصرف مقابل <?= htmlspecialchars($baseCur['code']) ?></label>
                            <input type="number" id="rRate" class="form-control form-control-sm" value="1" min="0.0001"
                                step="0.0001" dir="ltr" oninput="recalcSnd()">
                        </div>
                        <div class="col-md-3">
                            <label class="field-lbl">المبلغ المدفوع <span class="req">*</span></label>
                            <input type="number" id="rAmount" class="form-control form-control-sm fw-600" min="0"
                                step="0.01" dir="ltr" placeholder="0.00" oninput="recalcSnd()">
                        </div>
                        <div class="col-md-4" id="withholdingWrap">
                            <label class="field-lbl">استقطاع ضريبي <span style="color:#94a3b8;font-weight:400">(اختياري)</span></label>
                            <select id="rWithholding" class="form-select form-select-sm" onchange="recalcSnd()">
                                <option value="">— بدون استقطاع —</option>
                                <?php foreach ($withholdingTypes as $wt): ?>
                                    <option value="<?= $wt['id'] ?>" data-calc="<?= $wt['calc_type'] ?>" data-rate="<?= $wt['rate_value'] ?>">
                                        <?= htmlspecialchars($wt['name']) ?>
                                        (<?= $wt['calc_type'] === 'percentage' ? number_format($wt['rate_value'], 2) . '٪' : $baseSym . number_format($wt['rate_value'], 2) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12" id="withholdingPreview" style="display:none">
                            <div class="alert alert-warning py-2 mb-0" style="font-size:.75rem;border-radius:8px">
                                <i class="bi bi-scissors me-1"></i>
                                سيُقتطع <b id="whAmount">0.00</b> ضريبة استقطاع — الصافي الفعلي الخارج من الصندوق:
                                <b id="whNet">0.00</b> (وتُقيَّد ذمة المورد كاملة كأنه استلم المبلغ الإجمالي)
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">حساب الصندوق/البنك</label>
                            <select id="rCashAcc" class="form-select form-select-sm">
                                <option value="">— تلقائي حسب الإعدادات —</option>
                                <?php foreach ($cashAccounts as $acc): ?>
                                            <option value="<?= $acc['id'] ?>" data-cur="<?= htmlspecialchars($acc['cur_code'] ?? '') ?>">
                                                <?= htmlspecialchars($acc['code'] . ' — ' . $acc['name'] . ($acc['cur_code'] ? ' (' . $acc['cur_code'] . ')' : '')) ?>
                                            </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="field-lbl">ملاحظات</label>
                            <input type="text" id="rNotes" class="form-control form-control-sm" placeholder="اختياري">
                        </div>
                    </div>

                    <!-- شريط الرصيد -->
                    <div class="balance-bar mb-3">
                        <div>
                            <span style="color:#64748b">المبلغ بعملة الفرع:</span>
                            <span id="rAmtUsd" class="n fw-600 text-success ms-1"><?= $baseSym ?> 0.00</span>
                        </div>
                        <div>
                            <span style="color:#64748b">موزَّع:</span>
                            <span id="rAllocated" class="n fw-600 text-primary ms-1"><?= $baseSym ?> 0.00</span>
                        </div>
                        <div>
                            <span style="color:#64748b">متبقي للتوزيع:</span>
                            <span id="rRemaining" class="n fw-600 ms-1"><?= $baseSym ?> 0.00</span>
                        </div>
                    </div>

                    <!-- توزيع على فواتير الشراء (يظهر فقط لو في مورد محدد) -->
                    <div id="allocSection">
                    <div class="mb-2 d-flex align-items-center justify-content-between">
                        <span style="font-size:.82rem;font-weight:700;color:#1e293b">
                            <i class="bi bi-receipt me-1 text-success"></i>توزيع على فواتير الشراء
                        </span>
                        <span style="font-size:.72rem;color:#94a3b8">اختر فاتورة لتوزيع المبلغ عليها</span>
                    </div>
                    <div id="invoicesList">
                        <div class="text-center text-muted py-3" style="font-size:.8rem">
                            <i class="bi bi-person-check d-block mb-1" style="font-size:1.2rem;opacity:.3"></i>
                            اختر المورد (أو اتركه لدفعة عامة) أولاً لعرض فواتيره المستحقة
                        </div>
                    </div>
                    </div>

                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button class="btn btn-sm btn-light" style="border-radius:8px"
                        data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-sm fw-600"
                        style="border-radius:8px;border:1px solid #16a34a;color:#16a34a;font-size:.82rem"
                        onclick="savePayment(true)">
                        <i class="bi bi-check-circle me-1"></i>حفظ وترحيل
                    </button>
                    <button class="btn btn-sm fw-600"
                        style="border-radius:8px;background:#16a34a;color:#fff;min-width:110px"
                        onclick="savePayment(false)" id="btnSaveRcp">
                        <span id="saveRcpTxt"><i class="bi bi-floppy me-1"></i>حفظ كمسودة</span>
                        <span id="saveRcpSpin" class="spinner-border spinner-border-sm" style="display:none"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- مودال عرض السند -->
    <div class="modal fade" id="viewModal" tabindex="-1">
        <div class="modal-dialog modal-md">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#065f46,#16a34a);border-radius:16px 16px 0 0">
                    <h6 class="modal-title text-white fw-700 mb-0" id="vTitle">سند الدفع</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-3" id="vBody">
                    <div class="text-center py-4"><span class="spinner-border text-success"></span></div>
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

        const rcpModal = new bootstrap.Modal(document.getElementById('rcpModal'));
        const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
        const STATUS_MAP = <?= json_encode($STATUS_MAP) ?>;
        const PAY_METHOD = <?= json_encode($PAY_METHOD) ?>;
        const CURR_SYM = <?= json_encode($CURR_SYM) ?>;
        const BASE_CODE = '<?= htmlspecialchars($baseCur['code']) ?>';
        const BASE_SYM = '<?= $baseSym ?>';
        var _invoices = [];

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

        function openNewPayment() {
            document.getElementById('pSup').value = '';
            document.getElementById('pDebitAcc').value = '';
            document.getElementById('rDate').value = new Date().toISOString().split('T')[0];
            document.getElementById('rMethod').value = 'cash';
            document.getElementById('rCurr').value = BASE_CODE;
            document.getElementById('rRate').value = '1';
            document.getElementById('rAmount').value = '';
            document.getElementById('rCashAcc').value = '';
            document.getElementById('rNotes').value = '';
            document.getElementById('invoicesList').innerHTML = '<div class="text-center text-muted py-3" style="font-size:.8rem"><i class="bi bi-person-check d-block mb-1" style="font-size:1.2rem;opacity:.3"></i>اختر المورد (أو اتركه لدفعة عامة) أولاً</div>';
            _invoices = [];
            onSupplierChange();
            filterCashAccByCurrency();
            recalcSnd();
            rcpModal.show();
        }

        // ── تبديل بين "دفعة لمورد محدد" (توزيع على فواتيره + حسابه المخصص
        // تلقائياً) و"دفعة عامة" (اختيار الحساب المدين يدوياً، بدون توزيع) ──
        function onSupplierChange() {
            const hasSupplier = !!document.getElementById('pSup').value;
            document.getElementById('pDebitAccWrap').style.display = hasSupplier ? 'none' : '';
            document.getElementById('allocSection').style.display = hasSupplier ? '' : 'none';
            document.getElementById('withholdingWrap').style.display = hasSupplier ? '' : 'none';
            if (!hasSupplier) {
                document.getElementById('rWithholding').value = '';
                document.getElementById('withholdingPreview').style.display = 'none';
            }
            if (hasSupplier) {
                loadSupplierInvoices();
            } else {
                _invoices = [];
                document.getElementById('invoicesList').innerHTML = '';
                updateAllocBar();
            }
        }

        // ── تصفية حسابات الصندوق/البنك تلقائياً حسب عملة السند المختارة ──
        // (خيار "تلقائي حسب الإعدادات" يضل ظاهر دايماً لأنه مش مربوط بعملة محددة)
        function filterCashAccByCurrency() {
            const curr = document.getElementById('rCurr').value;
            const sel = document.getElementById('rCashAcc');
            let hidCurrent = false;
            Array.from(sel.options).forEach(opt => {
                if (!opt.value) { opt.hidden = false; return; }
                const match = !opt.dataset.cur || opt.dataset.cur === curr;
                opt.hidden = !match;
                if (!match && opt.selected) hidCurrent = true;
            });
            if (hidCurrent) sel.value = '';
        }

        function onRcpCurrencyChange() {
            const isBase = document.getElementById('rCurr').value === BASE_CODE;
            document.getElementById('rRateWrap').style.opacity = isBase ? .4 : 1;
            if (isBase) document.getElementById('rRate').value = '1';
            filterCashAccByCurrency();
            recalcSnd();
        }

        function recalcSnd() {
            const amt = parseFloat(document.getElementById('rAmount').value || 0);
            const rate = parseFloat(document.getElementById('rRate').value || 1);
            const amtUsd = amt / rate;
            document.getElementById('rAmtUsd').textContent = BASE_SYM + ' ' + amtUsd.toFixed(2);
            updateAllocBar(amtUsd);
            updateWithholdingPreview(amt);
        }

        // ── معاينة الاستقطاع الضريبي (بعملة السند نفسها) ──
        function updateWithholdingPreview(amt) {
            const sel = document.getElementById('rWithholding');
            const opt = sel.selectedOptions[0];
            const box = document.getElementById('withholdingPreview');
            if (!sel.value || !amt) { box.style.display = 'none'; return; }
            const calc = opt.dataset.calc, rateVal = parseFloat(opt.dataset.rate || 0);
            const withheld = calc === 'percentage' ? amt * rateVal / 100 : Math.min(rateVal, amt);
            const net = amt - withheld;
            document.getElementById('whAmount').textContent = withheld.toFixed(2);
            document.getElementById('whNet').textContent = net.toFixed(2);
            box.style.display = '';
        }

        function updateAllocBar(amtUsd) {
            if (!amtUsd) amtUsd = parseFloat(document.getElementById('rAmount').value || 0) / parseFloat(document.getElementById('rRate').value || 1);
            let allocated = 0;
            document.querySelectorAll('.alloc-input').forEach(inp => { allocated += parseFloat(inp.value || 0); });
            const remaining = amtUsd - allocated;
            document.getElementById('rAllocated').textContent = BASE_SYM + ' ' + allocated.toFixed(2);
            const remEl = document.getElementById('rRemaining');
            remEl.textContent = BASE_SYM + ' ' + remaining.toFixed(2);
            remEl.style.color = remaining < -0.01 ? '#dc2626' : (remaining < 0.01 ? '#16a34a' : '#f59e0b');
        }

        function loadSupplierInvoices() {
            const supId = document.getElementById('pSup').value;
            if (!supId) { document.getElementById('invoicesList').innerHTML = '<div class="text-center text-muted py-3" style="font-size:.8rem">اختر المورد (أو اتركه لدفعة عامة)</div>'; return; }
            document.getElementById('invoicesList').innerHTML = '<div class="text-center py-3"><span class="spinner-border spinner-border-sm text-success"></span></div>';
            post({ _action: 'get_supplier_invoices', supplier_id: supId }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                _invoices = d.data;
                if (!_invoices.length) {
                    document.getElementById('invoicesList').innerHTML = '<div class="text-center text-muted py-3" style="font-size:.8rem"><i class="bi bi-check-circle-fill text-success me-1"></i>لا توجد فواتير شراء مستحقة لهذا المورد</div>';
                    return;
                }
                let html = '<div class="inv-alloc-row mb-1" style="background:transparent;padding:4px 8px">'
                    + '<span style="font-size:.7rem;color:#64748b;font-weight:600">الفاتورة</span>'
                    + '<span style="font-size:.7rem;color:#64748b;font-weight:600;text-align:center">الإجمالي</span>'
                    + '<span style="font-size:.7rem;color:#dc2626;font-weight:600;text-align:center">المستحق</span>'
                    + '<span style="font-size:.7rem;color:#16a34a;font-weight:600;text-align:center">توزيع ($)</span>'
                    + '<span></span></div>';
                _invoices.forEach(inv => {
                    html += `<div class="inv-alloc-row" id="irow_${inv.id}">
                <div>
                    <div class="fw-600 n" style="direction:ltr;font-size:.78rem;color:#1e3a8a">${inv.purchase_number}</div>
                    <div style="color:#94a3b8;font-size:.68rem">${inv.purchase_date}</div>
                </div>
                <div class="n text-center">${BASE_SYM} ${parseFloat(inv.total_amount).toFixed(2)}</div>
                <div class="n text-center text-danger fw-600">${BASE_SYM} ${parseFloat(inv.balance_amount).toFixed(2)}</div>
                <input type="number" class="alloc-input" id="alloc_${inv.id}"
                    data-invoice="${inv.id}" data-balance="${inv.balance_amount}"
                    min="0" step="0.01" placeholder="0.00"
                    oninput="onAllocInput(${inv.id})">
                <button class="btn btn-sm" style="border-radius:6px;border:1px solid #e2e8f0;font-size:.68rem;padding:2px 6px;color:#64748b"
                    onclick="setFullBalance(${inv.id})">الكل</button>
            </div>`;
                });
                document.getElementById('invoicesList').innerHTML = html;
            });
        }

        function setFullBalance(invId) {
            const inp = document.getElementById('alloc_' + invId);
            const amtUsd = parseFloat(document.getElementById('rAmount').value || 0) / parseFloat(document.getElementById('rRate').value || 1);
            let allocated = 0;
            document.querySelectorAll('.alloc-input').forEach(i => { if (parseInt(i.dataset.invoice) !== invId) allocated += parseFloat(i.value || 0); });
            const remaining = amtUsd - allocated;
            const balance = parseFloat(inp.dataset.balance);
            inp.value = Math.min(balance, remaining).toFixed(2);
            onAllocInput(invId);
        }

        function onAllocInput(invId) {
            const inp = document.getElementById('alloc_' + invId);
            const balance = parseFloat(inp.dataset.balance);
            if (parseFloat(inp.value) > balance) inp.value = balance.toFixed(2);
            document.getElementById('irow_' + invId).classList.toggle('selected', parseFloat(inp.value || 0) > 0);
            updateAllocBar();
        }

        function getAllocations() {
            const allocs = [];
            document.querySelectorAll('.alloc-input').forEach(inp => {
                const amt = parseFloat(inp.value || 0);
                if (amt > 0) allocs.push({ invoice_id: inp.dataset.invoice, amount: amt });
            });
            return allocs;
        }

        function savePayment(andPost) {
            const supId = document.getElementById('pSup').value;
            const debitAccId = document.getElementById('pDebitAcc').value;
            const amount = parseFloat(document.getElementById('rAmount').value || 0);
            if (!supId && !debitAccId) { toast('يجب اختيار مورد، أو تحديد الحساب المدين لدفعة عامة', 'danger'); return; }
            if (amount <= 0) { toast('يجب إدخال مبلغ أكبر من صفر', 'danger'); return; }

            const btn = document.getElementById('btnSaveRcp');
            document.getElementById('saveRcpTxt').style.opacity = '0';
            document.getElementById('saveRcpSpin').style.display = 'inline-block';
            btn.disabled = true;

            post({
                _action: 'save_payment',
                supplier_id: supId,
                debit_account_id: debitAccId,
                payment_date: document.getElementById('rDate').value,
                amount,
                currency: document.getElementById('rCurr').value,
                exchange_rate: document.getElementById('rRate').value,
                payment_method: document.getElementById('rMethod').value,
                cash_account_id: document.getElementById('rCashAcc').value,
                withholding_tax_type_id: supId ? document.getElementById('rWithholding').value : '',
                notes: document.getElementById('rNotes').value,
                allocations: JSON.stringify(supId ? getAllocations() : []),
            }).then(d => {
                document.getElementById('saveRcpTxt').style.opacity = '1';
                document.getElementById('saveRcpSpin').style.display = 'none';
                btn.disabled = false;
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                const jeMsg = d.je_created ? ' (قيد محاسبي تلقائي ✅)' : '';
                const whMsg = d.withheld > 0 ? ` — استُقطع ${d.withheld.toFixed(2)} ضريبة` : '';
                if (andPost) {
                    post({ _action: 'post_payment', id: d.id }).then(p => {
                        if (p.ok) { toast('✅ تم حفظ السند وترحيله — ' + d.no + whMsg + jeMsg); rcpModal.hide(); setTimeout(() => location.reload(), 800); }
                        else toast(p.msg, 'danger');
                    });
                } else {
                    toast('✅ تم حفظ السند — ' + d.no + whMsg + jeMsg); rcpModal.hide(); setTimeout(() => location.reload(), 800);
                }
            });
        }

        function viewPayment(id) {
            document.getElementById('vTitle').textContent = 'جارٍ التحميل...';
            document.getElementById('vBody').innerHTML = '<div class="text-center py-4"><span class="spinner-border text-success"></span></div>';
            viewModal.show();
            post({ _action: 'get_payment', id }).then(d => {
                if (!d.ok) { document.getElementById('vBody').innerHTML = `<div class="text-danger p-3">${d.msg}</div>`; return; }
                const r = d.data;
                const st = STATUS_MAP[r.status] || STATUS_MAP['draft'];
                const sym = CURR_SYM[r.currency] || '$';
                document.getElementById('vTitle').textContent = 'سند: ' + r.payment_number;
                const allocHtml = (r.allocations || []).map(al => `
            <div style="display:flex;justify-content:space-between;font-size:.8rem;padding:4px 0;border-bottom:1px solid #f8fafc">
                <span class="fw-600 n" dir="ltr" style="color:#1e3a8a">${al.purchase_number}</span>
                <span style="color:#94a3b8;font-size:.72rem">${al.purchase_date}</span>
                <span class="n fw-600 text-success">${BASE_SYM} ${parseFloat(al.allocated_amount).toFixed(2)}</span>
            </div>`).join('');
                document.getElementById('vBody').innerHTML = `
        <div class="row g-2 mb-3">
            <div class="col-6"><small style="color:#64748b">المورد</small><div class="fw-600">${r.supplier_name || 'دفعة عامة (بدون مورد)'}</div></div>
            <div class="col-6"><small style="color:#64748b">التاريخ</small><div>${r.payment_date}</div></div>
            <div class="col-6"><small style="color:#64748b">طريقة الدفع</small>
                <div><span class="badge bg-secondary-subtle text-secondary">${PAY_METHOD[r.payment_method] || r.payment_method}</span></div></div>
            <div class="col-6"><small style="color:#64748b">الحالة</small>
                <div><span class="badge ${st.cls}">${st.label}</span></div></div>
        </div>
        <div style="background:#f0fdf4;border-radius:10px;padding:10px 14px;text-align:center;margin-bottom:12px">
            <div style="font-size:.75rem;color:#64748b">المبلغ المدفوع</div>
            <div class="n fw-600" style="font-size:1.4rem;color:#16a34a">${sym} ${parseFloat(r.amount).toFixed(2)}</div>
            ${r.currency !== BASE_CODE ? `<div class="n" style="font-size:.78rem;color:#94a3b8">= ${BASE_SYM} ${parseFloat(r.amount_base).toFixed(2)}</div>` : ''}
        </div>
        ${allocHtml ? `<div style="font-size:.78rem;font-weight:700;color:#1e293b;margin-bottom:6px"><i class="bi bi-receipt me-1"></i>توزيع على فواتير الشراء</div>${allocHtml}` : '<div style="font-size:.78rem;color:#94a3b8;text-align:center">بدون توزيع على فواتير (دفعة عامة أو بدون تخصيص)</div>'}
        ${r.notes ? `<div style="background:#f8fafc;border-radius:8px;padding:8px 12px;margin-top:10px;font-size:.78rem;color:#64748b">${r.notes}</div>` : ''}
        ${r.status === 'draft' ? `<div style="margin-top:12px;display:flex;gap:8px">
            <button class="btn btn-sm fw-600" style="border-radius:8px;background:#16a34a;color:#fff;flex:1;font-size:.8rem"
                onclick="postPayment(${r.id},'${r.payment_number}')">
                <i class="bi bi-check-circle me-1"></i>ترحيل السند
            </button></div>`: ''}`;
            });
        }

        function postPayment(id, no) {
            if (!confirm(`ترحيل السند "${no}"؟\nسيتم تحديث أرصدة الحسابات.`)) return;
            post({ _action: 'post_payment', id }).then(d => {
                if (d.ok) { toast('✅ ' + d.msg); viewModal.hide(); setTimeout(() => location.reload(), 700); }
                else toast(d.msg, 'danger');
            });
        }
        function cancelPayment(id, no) {
            if (!confirm(`إلغاء السند "${no}"؟\nسيتم عكس تأثيره على الفواتير والحسابات.`)) return;
            post({ _action: 'cancel_payment', id }).then(d => {
                if (d.ok) { toast(d.msg); viewModal.hide(); setTimeout(() => location.reload(), 700); }
                else toast(d.msg, 'danger');
            });
        }
    
    </script>

</body>

</html>