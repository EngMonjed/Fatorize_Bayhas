<?php
/**
 * api/confirm_purchase_return.php — تأكيد مرتجع شراء (draft → posted)
 * المسار: retail1/api/confirm_purchase_return.php
 *
 * الوحيد المخوّل يحوّل مرتجع من مسودة لمستند فعلي: ينقص المخزون + يفتح
 * قيد محاسبي بمعاملة واحدة (beginTransaction/commit) — نفس مبدأ الفصل
 * المعتمد بـ confirm_purchase_invoice.php بالضبط (راجع
 * purchase-invoice-blueprint.md § ١).
 *
 * القيد الأساسي (عكس فاتورة الشراء الأصلية، بقيمة البنود المرتجعة فقط):
 *   - not_paid / partial / paid_credit_supplier: مدين ذمم الموردين (تخفيض
 *     المستحق) / دائن المخزون
 *   - paid_refund_cash: مدين حساب الاسترداد (صندوق/بنك) / دائن المخزون
 *     مباشرة (بدون المرور بذمم الموردين — المبلغ رجع كاش، مو خصم من
 *     المستحق)
 *
 * كل المبالغ المرحّلة (JE header) بعملة الفرع الأساسية — نفس درس
 * "تسمية القيود" من فاتورة الشراء (blueprint § ٤-ج).
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('purchases.returns', 'confirm');

$TS  = $_SESSION['table_suffix'];
$TR  = "purchase_returns_{$TS}";
$TRI = "purchase_return_items_{$TS}";
$TWI = "warehouse_items_{$TS}";
$TIM = "inventory_movements_{$TS}";
$TIMD = "inventory_movement_details_{$TS}";
$TAC = "account_charts_{$TS}";
$TJE = "journal_entries_{$TS}";
$TJI = "journal_entry_items_{$TS}";
$TIAS = "invoice_account_settings_{$TS}";
$TSP = "product_suppliers_{$TS}";

// ⚠ عملة الفرع الأساسية الحقيقية — عبر base_currency_id (FK)، لا
// branches.base_currency (نص قديم/تالف — راجع الدرس الموثّق بـ
// confirm_purchase_invoice.php).
$branchBaseCurrency = 'USD';
if (!empty($_SESSION['branch_id'])) {
    $bcStmt = $pdo->prepare("SELECT c.code FROM branches b
        JOIN currencies c ON c.id = b.base_currency_id WHERE b.id = ?");
    $bcStmt->execute([$_SESSION['branch_id']]);
    $branchBaseCurrency = $bcStmt->fetchColumn() ?: 'USD';
}

// تحويل رمز عملة → معرّفه الرقمي (currency_id FK)، مع تخزين مؤقت
$curIdCache = [];
$curId = function (string $code) use ($pdo, &$curIdCache): int {
    if (isset($curIdCache[$code])) return $curIdCache[$code];
    $st = $pdo->prepare("SELECT id FROM currencies WHERE code=? LIMIT 1");
    $st->execute([$code]);
    $id = (int) ($st->fetchColumn() ?: 0);
    if (!$id) throw new Exception("عملة غير معروفة بجدول العملات: {$code}");
    return $curIdCache[$code] = $id;
};

// جلب حساب مربوط بمفتاح إعداد — نفس نمط $getAcc بـ confirm_purchase_invoice.php
$getAcc = function (string $key) use ($pdo, $TIAS, $TAC) {
    $st = $pdo->prepare("SELECT ac.* FROM `{$TIAS}` s JOIN `{$TAC}` ac ON ac.id=s.account_id
        WHERE s.setting_key=? LIMIT 1");
    $st->execute([$key]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};

function nextJeNo(PDO $pdo, string $table): string
{
    $y = date('Y');
    $last = $pdo->query("SELECT entry_number FROM `{$table}`
        WHERE entry_number LIKE 'JE-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $seq = $last ? (int) substr($last, -4) + 1 : 1;
    return "JE-{$y}-" . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

header('Content-Type: application/json; charset=utf-8');

try {
    $act = $_POST['_action'] ?? '';
    if ($act !== 'confirm_return') throw new Exception('إجراء غير معروف');

    $retId = (int) ($_POST['return_id'] ?? 0);
    if (!$retId) throw new Exception('مرتجع غير محدد');

    $ret = $pdo->prepare("SELECT r.*, c.code AS ret_currency_code FROM `{$TR}` r
        LEFT JOIN currencies c ON c.id = r.return_currency_id WHERE r.id=?");
    $ret->execute([$retId]);
    $ret = $ret->fetch(PDO::FETCH_ASSOC);
    if (!$ret) throw new Exception('المرتجع غير موجود');
    if ($ret['status'] !== 'draft') throw new Exception('لا يمكن تأكيد مرتجع مؤكد أو ملغى مسبقاً');
    if (!$ret['warehouse_id']) throw new Exception('لا يوجد مستودع محدَّد لهذا المرتجع');
    $retCurCode = $ret['ret_currency_code'] ?: 'USD';

    $items = $pdo->prepare("SELECT * FROM `{$TRI}` WHERE return_id=?");
    $items->execute([$retId]);
    $items = $items->fetchAll(PDO::FETCH_ASSOC);
    if (empty($items)) throw new Exception('لا توجد بنود بهذا المرتجع');

    // ⚠ المبلغ المرحَّل محاسبياً لازم يكون بعملة الفرع الأساسية (نفس
    // درس فاتورة الشراء) — return_amount مخزّن بعملة الفاتورة الأصلية،
    // ونحوّله عبر exchange_rate المحفوظ على المرتجع نفسه (نسخة طبق
    // الأصل عن سعر صرف الفاتورة وقت الإنشاء، ثابت تاريخياً — IAS 21).
    $rate = (float) ($ret['exchange_rate'] ?: 1);
    $returnAmountBase = round((float) $ret['return_amount'] / $rate, 4);

    $supName = '';
    if (!empty($ret['supplier_id'])) {
        $s = $pdo->prepare("SELECT name FROM `{$TSP}` WHERE id=?");
        $s->execute([$ret['supplier_id']]);
        $supName = $s->fetchColumn() ?: '';
    }

    $pdo->beginTransaction();
    try {
        // ── ١) عكس المخزون لكل بند مرتجع ──
        $movNo = 'MOV-RET-' . date('Ymd') . '-' . str_pad($retId, 5, '0', STR_PAD_LEFT);
        $totalQty = array_sum(array_column($items, 'quantity_returned'));
        $pdo->prepare("INSERT INTO `{$TIM}`
                (movement_number,movement_type,warehouse_id,items_count,total_quantity,
                 total_value_base,reference_type,reference_id,reference_number,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $movNo,
                'out',
                $ret['warehouse_id'],
                count($items),
                $totalQty,
                $returnAmountBase,
                'purchase_return',
                $retId,
                $ret['return_number'],
                $_SESSION['user_id']
            ]);
        $movId = (int) $pdo->lastInsertId();

        foreach ($items as $it) {
            if (!$it['variant_id']) continue;
            $qty = (float) $it['quantity_returned'];
            $unitBase = (float) ($it['unit_price'] / $rate);

            // جلب الرصيد الحالي قبل التعديل (لتسجيله بتفاصيل الحركة)
            $stB = $pdo->prepare("SELECT quantity FROM `{$TWI}` WHERE variant_id=? AND warehouse_id=?");
            $stB->execute([$it['variant_id'], $ret['warehouse_id']]);
            $before = (float) ($stB->fetchColumn() ?? 0);
            $after = max(0, $before - $qty);

            $pdo->prepare("UPDATE `{$TWI}` SET quantity=?,last_movement_at=NOW()
                    WHERE variant_id=? AND warehouse_id=?")
                ->execute([$after, $it['variant_id'], $ret['warehouse_id']]);

            $pdo->prepare("INSERT INTO `{$TIMD}`
                    (movement_id,variant_id,product_id,quantity,unit_price,cost_price,
                     total_value,balance_before,balance_after)
                    VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $movId,
                    $it['variant_id'],
                    $it['product_id'] ?? 0,
                    $qty,
                    $it['unit_price'],
                    $unitBase,
                    $unitBase * $qty,
                    $before,
                    $after
                ]);
        }

        // ── ٢) القيد المحاسبي ──
        $accInventory = $getAcc('finished_inventory');
        if (!$accInventory) throw new Exception('حساب المخزون (finished_inventory) غير مربوط بعد بإعدادات الربط المحاسبي');

        $accSupplier = null;
        if (!empty($ret['supplier_id'])) {
            $stSupAcc = $pdo->prepare("SELECT ac.* FROM `{$TSP}` s JOIN `{$TAC}` ac ON ac.id=s.account_id
                WHERE s.id=? AND s.account_id IS NOT NULL LIMIT 1");
            $stSupAcc->execute([$ret['supplier_id']]);
            $accSupplier = $stSupAcc->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$accSupplier) $accSupplier = $getAcc('supplier_payable');
        if (!$accSupplier) throw new Exception('حساب ذمم الموردين غير مربوط بعد');

        $jeNo = nextJeNo($pdo, $TJE);
        $isCashRefund = $ret['payment_handling'] === 'paid_refund_cash';

        if ($isCashRefund) {
            if (empty($ret['refund_account_id'])) throw new Exception('لم يُحدَّد حساب الاسترداد النقدي لهذا المرتجع');
            $stCash = $pdo->prepare("SELECT * FROM `{$TAC}` WHERE id=?");
            $stCash->execute([$ret['refund_account_id']]);
            $accCash = $stCash->fetch(PDO::FETCH_ASSOC);
            if (!$accCash) throw new Exception('حساب الاسترداد غير موجود');
        }

        $pdo->prepare("INSERT INTO `{$TJE}`
                (entry_number,entry_date,description,currency_id,exchange_rate,
                 total_debit,total_credit,status,reference_type,reference_id,created_by)
                VALUES (?,?,?,?,1,?,?,'posted','purchase_return',?,?)")
            ->execute([
                $jeNo,
                date('Y-m-d'),
                "مرتجع شراء {$ret['return_number']} على فاتورة {$ret['purchase_number']} — {$supName}",
                $curId($branchBaseCurrency),
                $returnAmountBase,
                $returnAmountBase,
                $retId,
                $_SESSION['user_id']
            ]);
        $jeId = (int) $pdo->lastInsertId();

        // دائن: المخزون (ينقص — بضاعة رجعت للمورد) — بكل الحالات
        $pdo->prepare("INSERT INTO `{$TJI}`
                (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                VALUES (?,?,0,?,?,?,?,?,?)")
            ->execute([
                $jeId,
                $accInventory['id'],
                $returnAmountBase,
                (float) $ret['return_amount'],
                $returnAmountBase,
                "عكس مخزون — مرتجع {$ret['return_number']}",
                $curId($retCurCode),
                $rate
            ]);
        $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
            ->execute([$returnAmountBase, $returnAmountBase, $accInventory['id']]);

        if ($isCashRefund) {
            // مدين: حساب الاسترداد (صندوق/بنك) — المبلغ رجع كاش، بدون
            // المرور بذمم الموردين إطلاقاً (مو خصم من مستحق، استرداد فعلي)
            $pdo->prepare("INSERT INTO `{$TJI}`
                    (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                    VALUES (?,?,?,0,?,?,?,?,?)")
                ->execute([
                    $jeId,
                    $accCash['id'],
                    $returnAmountBase,
                    (float) $ret['return_amount'],
                    $returnAmountBase,
                    "استرداد نقدي — مرتجع {$ret['return_number']}",
                    $curId($retCurCode),
                    $rate
                ]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                ->execute([$returnAmountBase, $returnAmountBase, $accCash['id']]);
        } else {
            // مدين: ذمم الموردين (تخفيض المستحق) — not_paid / partial / paid_credit_supplier
            $pdo->prepare("INSERT INTO `{$TJI}`
                    (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                    VALUES (?,?,?,0,?,?,?,?,?)")
                ->execute([
                    $jeId,
                    $accSupplier['id'],
                    $returnAmountBase,
                    (float) $ret['return_amount'],
                    $returnAmountBase,
                    "تخفيض ذمة — مرتجع {$ret['return_number']}",
                    $curId($retCurCode),
                    $rate
                ]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                // ⚠ نفس إصلاح فواتير الشراء: سطر "مدين" (تخفيض الذمة نتيجة
                // المرتجع) لازم يجمع لا يطرح — حساب ذمم الموردين بيتبع
                // قاعدة "دائن=طرح / مدين=جمع".
                ->execute([$returnAmountBase, $returnAmountBase, $accSupplier['id']]);
        }

        // ── ٣) تحديث حالة المرتجع ──
        $pdo->prepare("UPDATE `{$TR}` SET status='posted' WHERE id=?")->execute([$retId]);

        $pdo->commit();
        echo json_encode(['ok' => true, 'msg' => 'تم تأكيد المرتجع وتحديث المخزون والقيد المحاسبي', 'journal_entry_id' => $jeId]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}
