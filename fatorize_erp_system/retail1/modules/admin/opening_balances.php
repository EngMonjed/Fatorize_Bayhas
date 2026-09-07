<?php
/**
 * admin/opening_balances.php — الأرصدة الافتتاحية
 * المسار: retail1/modules/admin/opening_balances.php
 *
 * إدخال حر (مرحلة واحدة) لمخزون أول المدة (منتجات + مستهلكات) وأرصدة
 * العملاء/الموردين الافتتاحية، ثم قفل يدوي نهائي من المدير. كل إدخال
 * بيترحّل قيد محاسبي حقيقي مقابل حساب "رصيد افتتاحي" (equity)،
 * بدل ما يتعامل كفاتورة شراء/بيع وهمية.
 */

session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('admin.opening_balances', 'view');
$currentModule = 'admin.opening_balances';

$TS = $_SESSION['table_suffix'];
$branchId = (int) $_SESSION['branch_id'];
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

$TW   = "warehouses_{$TS}";
$TWI  = "warehouse_items_{$TS}";
$TIM  = "inventory_movements_{$TS}";
$TIMD = "inventory_movement_details_{$TS}";
$TPV  = "product_variants_{$TS}";
$TP   = "products_{$TS}";
$TPS  = "product_sizes_{$TS}";   // ✅ جديد — المقاس الفعلي مرتبط بـsize_id، مش عمود مباشر على product_variants
$TPC  = "product_colors_{$TS}";  // ✅ جديد — نفس الشي للون (color_id)
$TAC  = "account_charts_{$TS}";
$TJE  = "journal_entries_{$TS}";
$TJI  = "journal_entry_items_{$TS}";
$TCI  = "consumable_items_{$TS}";
$TCS  = "consumable_stock_{$TS}";
$TCM  = "consumable_movements_{$TS}";
$TC   = "customers_{$TS}";
$TSUP = "product_suppliers_{$TS}";
$TIAS = "invoice_account_settings_{$TS}";

// ⚠ عملة الفرع الأساسية — لقيود الأرصدة الافتتاحية (لا تحويل عملة حقيقي)
$branchBaseCurrency = 'USD';
$branchCurrencyId = 1;
$bcStmt = $pdo->prepare("SELECT base_currency, base_currency_id FROM branches WHERE id = ?");
$bcStmt->execute([$branchId]);
$bcRow = $bcStmt->fetch(PDO::FETCH_ASSOC);
$branchBaseCurrency = $bcRow['base_currency'] ?? 'USD';
$branchCurrencyId = (int) ($bcRow['base_currency_id'] ?? 1);

function getSettingAccount(PDO $pdo, string $tias, string $key, string $tac): ?array
{
    $st = $pdo->prepare("SELECT ac.* FROM `{$tias}` ias
        JOIN `{$tac}` ac ON ac.id=ias.account_id
        WHERE ias.setting_key=? LIMIT 1");
    $st->execute([$key]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getOpeningEquityAccount(PDO $pdo, string $tac): array
{
    $st = $pdo->prepare("SELECT * FROM `{$tac}` WHERE code = '3900' LIMIT 1");
    $st->execute();
    $acc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$acc) {
        throw new Exception('حساب "رصيد افتتاحي" غير موجود — راجع 16_add_opening_balances_infrastructure.sql');
    }
    return $acc;
}

function nextEntryNo(PDO $pdo, string $tje): string
{
    $n = (int) $pdo->query("SELECT COUNT(*) FROM `{$tje}`")->fetchColumn() + 1;
    return 'JE-OB-' . date('Y') . '-' . str_pad($n, 4, '0', STR_PAD_LEFT);
}

function bumpAccountBalance(PDO $pdo, string $tac, int $accId, float $delta): void
{
    $pdo->prepare("UPDATE `{$tac}` SET base_balance = base_balance + ?, balance = balance + ? WHERE id = ?")
        ->execute([$delta, $delta, $accId]);
}

// ── حالة القفل ──────────────────────────────────────────────────
$branchRow = $pdo->prepare("SELECT opening_balance_locked_at, opening_balance_locked_by FROM branches WHERE id = ?");
$branchRow->execute([$branchId]);
$branchRow = $branchRow->fetch(PDO::FETCH_ASSOC);
$isLocked = !empty($branchRow['opening_balance_locked_at']);

// ── AJAX ──────────────────────────────────────────────────────────
/**
 * ✅ يرحّل رصيد افتتاحي لمتغيّر واحد (نفس منطق save_stock الأصلي،
 * مستخرَج كدالة مشتركة عشان تُستدعى لكل مقاس بمجموعة "الكروب" بالإجراء
 * الجماعي الجديد save_stock_group، بدون تكرار الكود).
 */
function postVariantOpeningStock(PDO $pdo, int $variantId, int $warehouseId, float $qty, float $cost,
    string $TPV, string $TP, string $TWI, string $TIM, string $TIMD, string $TIAS, string $TAC, string $TJE, string $TJI,
    int $branchCurrencyId, int $userId): void
{
    $variant = $pdo->prepare("SELECT v.*, p.name AS product_name FROM `{$TPV}` v JOIN `{$TP}` p ON p.id=v.product_id WHERE v.id=?");
    $variant->execute([$variantId]);
    $variant = $variant->fetch(PDO::FETCH_ASSOC);
    if (!$variant) throw new Exception("متغيّر غير موجود (id={$variantId})");

    $totalValue = $qty * $cost;

    $exists = $pdo->prepare("SELECT id, quantity FROM `{$TWI}` WHERE variant_id=? AND warehouse_id=?");
    $exists->execute([$variantId, $warehouseId]);
    $exists = $exists->fetch(PDO::FETCH_ASSOC);
    $balanceBefore = $exists ? (float) $exists['quantity'] : 0;

    if ($exists) {
        $pdo->prepare("UPDATE `{$TWI}` SET quantity=quantity+?, current_cost=? WHERE id=?")
            ->execute([$qty, $cost, $exists['id']]);
    } else {
        $pdo->prepare("INSERT INTO `{$TWI}` (warehouse_id,variant_id,product_id,quantity,current_cost,status,created_at)
            VALUES (?,?,?,?,?,'active',NOW())")
            ->execute([$warehouseId, $variantId, $variant['product_id'], $qty, $cost]);
    }

    $movNo = 'OB-' . date('Y') . '-' . str_pad((int) $pdo->query("SELECT COUNT(*) FROM `{$TIM}`")->fetchColumn() + 1, 4, '0', STR_PAD_LEFT);
    $pdo->prepare("INSERT INTO `{$TIM}` (movement_number,movement_type,warehouse_id,items_count,total_quantity,total_value_base,reference_type,reference_number,created_by)
        VALUES (?,?,?,1,?,?,?,?,?)")
        ->execute([$movNo, 'opening', $warehouseId, $qty, $totalValue, 'opening_balance', $movNo, $userId]);
    $movId = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `{$TIMD}` (movement_id,variant_id,product_id,quantity,unit_price,cost_price,total_value,balance_before,balance_after,notes)
        VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$movId, $variantId, $variant['product_id'], $qty, $cost, $cost, $totalValue, $balanceBefore, $balanceBefore + $qty, 'رصيد افتتاحي']);

    $accInventory = getSettingAccount($pdo, $TIAS, 'inventory', $TAC);
    $accEquity = getOpeningEquityAccount($pdo, $TAC);
    if ($accInventory) {
        $entryNo = nextEntryNo($pdo, $TJE);
        $pdo->prepare("INSERT INTO `{$TJE}` (entry_number,entry_date,description,currency_id,exchange_rate,total_debit,total_credit,status,reference_type,reference_id,created_by,posted_at,posted_by)
            VALUES (?,CURDATE(),?,1,1,?,?,'posted',?,?,?,NOW(),?)")
            ->execute([$entryNo, "رصيد افتتاحي مخزون — {$variant['product_name']}", $totalValue, $totalValue, 'opening_balance', $movId, $userId, $userId]);
        $jeId = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
            VALUES (?,?,?,0,?,?,?,?,1)")
            ->execute([$jeId, $accInventory['id'], $totalValue, $totalValue, $totalValue, 'رصيد افتتاحي مخزون', $branchCurrencyId]);
        bumpAccountBalance($pdo, $TAC, $accInventory['id'], $totalValue);

        $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
            VALUES (?,?,0,?,?,?,?,?,1)")
            ->execute([$jeId, $accEquity['id'], $totalValue, $totalValue, $totalValue, 'رصيد افتتاحي مخزون', $branchCurrencyId]);
        bumpAccountBalance($pdo, $TAC, $accEquity['id'], -$totalValue);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($isLocked && $_POST['_action'] !== 'unlock_info') {
            throw new Exception('الأرصدة الافتتاحية مقفولة نهائياً لهذا الفرع — لا يمكن التعديل');
        }

        $pdo->beginTransaction();

        if ($_POST['_action'] === 'save_stock') {
            requirePermission('admin.opening_balances', 'create');
            $variantId = (int) $_POST['variant_id'];
            $warehouseId = (int) $_POST['warehouse_id'];
            $qty = (float) $_POST['quantity'];
            $cost = (float) $_POST['cost'];
            if ($qty <= 0 || $cost < 0) throw new Exception('كمية أو تكلفة غير صالحة');

            $variant = $pdo->prepare("SELECT v.*, p.name AS product_name FROM `{$TPV}` v JOIN `{$TP}` p ON p.id=v.product_id WHERE v.id=?");
            $variant->execute([$variantId]);
            $variant = $variant->fetch(PDO::FETCH_ASSOC);
            if (!$variant) throw new Exception('صنف غير موجود');

            $totalValue = $qty * $cost;

            // رصيد المستودع
            $exists = $pdo->prepare("SELECT id, quantity FROM `{$TWI}` WHERE variant_id=? AND warehouse_id=?");
            $exists->execute([$variantId, $warehouseId]);
            $exists = $exists->fetch(PDO::FETCH_ASSOC);
            $balanceBefore = $exists ? (float) $exists['quantity'] : 0;

            if ($exists) {
                $pdo->prepare("UPDATE `{$TWI}` SET quantity=quantity+?, current_cost=? WHERE id=?")
                    ->execute([$qty, $cost, $exists['id']]);
            } else {
                $pdo->prepare("INSERT INTO `{$TWI}` (warehouse_id,variant_id,product_id,quantity,current_cost,status,created_at)
                    VALUES (?,?,?,?,?,'active',NOW())")
                    ->execute([$warehouseId, $variantId, $variant['product_id'], $qty, $cost]);
            }

            // حركة مخزون (opening)
            $movNo = 'OB-' . date('Y') . '-' . str_pad((int) $pdo->query("SELECT COUNT(*) FROM `{$TIM}`")->fetchColumn() + 1, 4, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO `{$TIM}` (movement_number,movement_type,warehouse_id,items_count,total_quantity,total_value_base,reference_type,reference_number,created_by)
                VALUES (?,?,?,1,?,?,?,?,?)")
                ->execute([$movNo, 'opening', $warehouseId, $qty, $totalValue, 'opening_balance', $movNo, $_SESSION['user_id']]);
            $movId = (int) $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO `{$TIMD}` (movement_id,variant_id,product_id,quantity,unit_price,cost_price,total_value,balance_before,balance_after,notes)
                VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$movId, $variantId, $variant['product_id'], $qty, $cost, $cost, $totalValue, $balanceBefore, $balanceBefore + $qty, 'رصيد افتتاحي']);

            // قيد محاسبي: مدين مخزون / دائن رصيد افتتاحي
            $accInventory = getSettingAccount($pdo, $TIAS, 'inventory', $TAC);
            $accEquity = getOpeningEquityAccount($pdo, $TAC);
            if ($accInventory) {
                $entryNo = nextEntryNo($pdo, $TJE);
                $pdo->prepare("INSERT INTO `{$TJE}` (entry_number,entry_date,description,currency_id,exchange_rate,total_debit,total_credit,status,reference_type,reference_id,created_by,posted_at,posted_by)
                    VALUES (?,CURDATE(),?,1,1,?,?,'posted',?,?,?,NOW(),?)")
                    ->execute([$entryNo, "رصيد افتتاحي مخزون — {$variant['product_name']}", $totalValue, $totalValue, 'opening_balance', $movId, $_SESSION['user_id'], $_SESSION['user_id']]);
                $jeId = (int) $pdo->lastInsertId();

                $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                    VALUES (?,?,?,0,?,?,?,?,1)")
                    ->execute([$jeId, $accInventory['id'], $totalValue, $totalValue, $totalValue, 'رصيد افتتاحي مخزون', $branchCurrencyId]);
                bumpAccountBalance($pdo, $TAC, $accInventory['id'], $totalValue);

                $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                    VALUES (?,?,0,?,?,?,?,?,1)")
                    ->execute([$jeId, $accEquity['id'], $totalValue, $totalValue, $totalValue, 'رصيد افتتاحي مخزون', $branchCurrencyId]);
                bumpAccountBalance($pdo, $TAC, $accEquity['id'], -$totalValue);
            }

            $pdo->commit();
            echo json_encode(['ok' => true, 'msg' => 'تم تسجيل رصيد المخزون الافتتاحي']);
        } elseif ($_POST['_action'] === 'save_stock_group') {
            // ✅ جديد — يستقبل كروب كامل (كل مقاساته مع بعض عبر
            // variant_ids)، ويقسّم الكمية الإجمالية على عدد المقاسات
            // بالكروب تلقائياً (نفس منطق "24÷4=6" المعتمد بأداة استيراد
            // المنتجات)، ثم يرحّل كل مقاس لحاله بنفس منطق save_stock
            requirePermission('admin.opening_balances', 'create');
            $variantIds = array_values(array_filter(array_map('intval', explode(',', $_POST['variant_ids'] ?? ''))));
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            $totalQty = (float) ($_POST['total_quantity'] ?? 0);
            $cost = (float) ($_POST['cost'] ?? 0);

            if (empty($variantIds)) throw new Exception('كروب غير صالح');
            if (!$warehouseId) throw new Exception('اختر مستودع');
            if ($totalQty <= 0 || $cost < 0) throw new Exception('كمية أو تكلفة غير صالحة');

            $perSizeQty = $totalQty / count($variantIds);
            foreach ($variantIds as $vid) {
                postVariantOpeningStock($pdo, $vid, $warehouseId, $perSizeQty, $cost,
                    $TPV, $TP, $TWI, $TIM, $TIMD, $TIAS, $TAC, $TJE, $TJI,
                    $branchCurrencyId, $_SESSION['user_id']);
            }

            $pdo->commit();
            echo json_encode(['ok' => true, 'msg' => "تم توزيع {$totalQty} على " . count($variantIds) . " مقاس (" . number_format($perSizeQty, 2) . " لكل مقاس)"]);
        } elseif ($_POST['_action'] === 'save_consumable') {
            requirePermission('admin.opening_balances', 'create');
            $itemId = (int) $_POST['item_id'];
            $warehouseId = (int) $_POST['warehouse_id'];
            $qty = (float) $_POST['quantity'];
            $cost = (float) $_POST['cost'];
            if ($qty <= 0 || $cost < 0) throw new Exception('كمية أو تكلفة غير صالحة');

            $item = $pdo->prepare("SELECT * FROM `{$TCI}` WHERE id=?");
            $item->execute([$itemId]);
            $item = $item->fetch(PDO::FETCH_ASSOC);
            if (!$item) throw new Exception('مادة غير موجودة');

            $totalValue = $qty * $cost;

            $exists = $pdo->prepare("SELECT id, quantity FROM `{$TCS}` WHERE item_id=? AND warehouse_id=?");
            $exists->execute([$itemId, $warehouseId]);
            $exists = $exists->fetch(PDO::FETCH_ASSOC);
            $balanceBefore = $exists ? (float) $exists['quantity'] : 0;

            if ($exists) {
                $pdo->prepare("UPDATE `{$TCS}` SET quantity=quantity+?, avg_cost_base=? WHERE id=?")
                    ->execute([$qty, $cost, $exists['id']]);
            } else {
                $pdo->prepare("INSERT INTO `{$TCS}` (item_id,warehouse_id,quantity,avg_cost_base,updated_at) VALUES (?,?,?,?,NOW())")
                    ->execute([$itemId, $warehouseId, $qty, $cost]);
            }

            $movNo = 'OBC-' . date('Y') . '-' . str_pad((int) $pdo->query("SELECT COUNT(*) FROM `{$TCM}`")->fetchColumn() + 1, 4, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO `{$TCM}` (movement_no,item_id,warehouse_id,movement_type,direction,quantity,unit_cost_base,total_cost_base,qty_before,qty_after,reference_type,is_posted,movement_date,notes,created_by)
                VALUES (?,?,?,'opening','in',?,?,?,?,?,?,1,CURDATE(),?,?)")
                ->execute([$movNo, $itemId, $warehouseId, $qty, $cost, $totalValue, $balanceBefore, $balanceBefore + $qty, 'opening_balance', 'رصيد افتتاحي', $_SESSION['user_id']]);

            $accConsInv = getSettingAccount($pdo, $TIAS, 'consumable_inventory', $TAC);
            $accEquity = getOpeningEquityAccount($pdo, $TAC);
            if ($accConsInv) {
                $entryNo = nextEntryNo($pdo, $TJE);
                $pdo->prepare("INSERT INTO `{$TJE}` (entry_number,entry_date,description,currency_id,exchange_rate,total_debit,total_credit,status,reference_type,created_by,posted_at,posted_by)
                    VALUES (?,CURDATE(),?,1,1,?,?,'posted',?,?,NOW(),?)")
                    ->execute([$entryNo, "رصيد افتتاحي مستهلكات — {$item['name']}", $totalValue, $totalValue, 'opening_balance', $_SESSION['user_id'], $_SESSION['user_id']]);
                $jeId = (int) $pdo->lastInsertId();

                $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                    VALUES (?,?,?,0,?,?,?,?,1)")
                    ->execute([$jeId, $accConsInv['id'], $totalValue, $totalValue, $totalValue, 'رصيد افتتاحي مستهلكات', $branchCurrencyId]);
                bumpAccountBalance($pdo, $TAC, $accConsInv['id'], $totalValue);

                $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                    VALUES (?,?,0,?,?,?,?,?,1)")
                    ->execute([$jeId, $accEquity['id'], $totalValue, $totalValue, $totalValue, 'رصيد افتتاحي مستهلكات', $branchCurrencyId]);
                bumpAccountBalance($pdo, $TAC, $accEquity['id'], -$totalValue);
            }

            $pdo->commit();
            echo json_encode(['ok' => true, 'msg' => 'تم تسجيل رصيد المستهلك الافتتاحي']);
        } elseif ($_POST['_action'] === 'save_party') {
            requirePermission('admin.opening_balances', 'create');
            $partyType = $_POST['party_type']; // customer | supplier
            $partyId = (int) $_POST['party_id'];
            $amount = (float) $_POST['amount'];
            if ($amount <= 0) throw new Exception('المبلغ غير صالح');
            if (!in_array($partyType, ['customer', 'supplier'], true)) throw new Exception('نوع غير صالح');

            $table = $partyType === 'customer' ? $TC : $TSUP;
            $party = $pdo->prepare("SELECT * FROM `{$table}` WHERE id=?");
            $party->execute([$partyId]);
            $party = $party->fetch(PDO::FETCH_ASSOC);
            if (!$party || empty($party['account_id'])) throw new Exception('لا يوجد حساب محاسبي مرتبط بهذا العميل/المورد');

            $accEquity = getOpeningEquityAccount($pdo, $TAC);
            $entryNo = nextEntryNo($pdo, $TJE);
            $label = $partyType === 'customer' ? 'رصيد افتتاحي عميل' : 'رصيد افتتاحي مورد';

            $pdo->prepare("INSERT INTO `{$TJE}` (entry_number,entry_date,description,currency_id,exchange_rate,total_debit,total_credit,status,reference_type,created_by,posted_at,posted_by)
                VALUES (?,CURDATE(),?,1,1,?,?,'posted',?,?,NOW(),?)")
                ->execute([$entryNo, "{$label} — {$party['name']}", $amount, $amount, 'opening_balance', $_SESSION['user_id'], $_SESSION['user_id']]);
            $jeId = (int) $pdo->lastInsertId();

            if ($partyType === 'customer') {
                // مدين: حساب العميل (علينا نستلم) / دائن: رصيد افتتاحي
                $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate) VALUES (?,?,?,0,?,?,?,?,1)")
                    ->execute([$jeId, $party['account_id'], $amount, $amount, $amount, $label, $branchCurrencyId]);
                bumpAccountBalance($pdo, $TAC, $party['account_id'], $amount);
            } else {
                // مدين: رصيد افتتاحي / دائن: حساب المورد (علينا ندفع)
                $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate) VALUES (?,?,0,?,?,?,?,?,1)")
                    ->execute([$jeId, $party['account_id'], $amount, $amount, $amount, $label, $branchCurrencyId]);
                // ✅ مُصلح: كانت $amount موجبة بالغلط — القيد "دائن" لازم
                // يخلي الرصيد المخزَّن أكتر سلبية (نفس اتجاه حسابات
                // الالتزامات بباقي النظام)، مو موجب زي حالة العميل
                // (مدين) فوق.
                bumpAccountBalance($pdo, $TAC, $party['account_id'], -$amount);
            }

            $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                VALUES (?,?,?,?,?,?,?,?,1)")
                ->execute([
                    $jeId, $accEquity['id'],
                    $partyType === 'supplier' ? $amount : 0,
                    $partyType === 'customer' ? $amount : 0,
                    $amount, $amount, $label, $branchCurrencyId,
                ]);
            bumpAccountBalance($pdo, $TAC, $accEquity['id'], $partyType === 'customer' ? -$amount : $amount);

            $pdo->commit();
            echo json_encode(['ok' => true, 'msg' => 'تم تسجيل الرصيد الافتتاحي']);
        } elseif ($_POST['_action'] === 'lock') {
            requirePermission('admin.opening_balances', 'confirm');
            if (empty($_POST['confirm']) || $_POST['confirm'] !== 'yes') {
                throw new Exception('تأكيد القفل مطلوب');
            }
            $pdo->prepare("UPDATE branches SET opening_balance_locked_at = NOW(), opening_balance_locked_by = ? WHERE id = ?")
                ->execute([$_SESSION['user_id'], $branchId]);
            $pdo->commit();
            echo json_encode(['ok' => true, 'msg' => 'تم قفل الأرصدة الافتتاحية نهائياً لهذا الفرع']);
        } elseif ($_POST['_action'] === 'save_cash') {
            requirePermission('admin.opening_balances', 'create');
            $accountId = (int) ($_POST['account_id'] ?? 0);
            $amount = (float) ($_POST['amount'] ?? 0);
            if ($amount <= 0) throw new Exception('المبلغ غير صالح');

            $acc = $pdo->prepare("SELECT ac.*, c.code AS currency_code, c.exchange_rate AS currency_rate
                FROM `{$TAC}` ac JOIN currencies c ON c.id = ac.currency_id WHERE ac.id = ?");
            $acc->execute([$accountId]);
            $acc = $acc->fetch(PDO::FETCH_ASSOC);
            if (!$acc) throw new Exception('حساب غير موجود');

            // ✅ سعر الصرف: 1 لو الحساب أصلاً بعملة الفرع الوظيفية، وإلا
            // سعر currencies.exchange_rate (سعر الصرف المخزَّن لعملة
            // الحساب مقابل عملة الفرع)
            $exRate = ((int) $acc['currency_id'] === $branchCurrencyId) ? 1.0 : (float) $acc['currency_rate'];
            $baseAmount = round($amount * $exRate, 2);

            $accEquity = getOpeningEquityAccount($pdo, $TAC);
            $entryNo = nextEntryNo($pdo, $TJE);
            $label = "رصيد افتتاحي صندوق/بنك — {$acc['name']}";

            $pdo->prepare("INSERT INTO `{$TJE}` (entry_number,entry_date,description,currency_id,exchange_rate,total_debit,total_credit,status,reference_type,created_by,posted_at,posted_by)
                VALUES (?,CURDATE(),?,?,?,?,?,'posted',?,?,NOW(),?)")
                ->execute([$entryNo, $label, $acc['currency_id'], $exRate, $baseAmount, $baseAmount, 'opening_balance', $_SESSION['user_id'], $_SESSION['user_id']]);
            $jeId = (int) $pdo->lastInsertId();

            // مدين: حساب الصندوق/البنك (بعملته الخاصة + المكافئ بعملة الفرع)
            $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate) VALUES (?,?,?,0,?,?,?,?,?)")
                ->execute([$jeId, $accountId, $amount, $amount, $baseAmount, $label, $acc['currency_id'], $exRate]);
            $pdo->prepare("UPDATE `{$TAC}` SET balance = balance + ?, base_balance = base_balance + ? WHERE id = ?")
                ->execute([$amount, $baseAmount, $accountId]);

            // دائن: رصيد افتتاحي (بعملة الفرع دايماً)
            $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate) VALUES (?,?,0,?,?,?,?,?,1)")
                ->execute([$jeId, $accEquity['id'], $baseAmount, $baseAmount, $baseAmount, $label, $branchCurrencyId]);
            bumpAccountBalance($pdo, $TAC, $accEquity['id'], -$baseAmount);

            $pdo->commit();
            echo json_encode(['ok' => true, 'msg' => 'تم تسجيل رصيد الصندوق/البنك الافتتاحي']);
        } else {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'إجراء غير معروف']);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── بيانات الصفحة ────────────────────────────────────────────────
// ✅ حسابات الصناديق/البنوك — من invoice_account_settings مباشرة
// (مفاتيح cash_%/bank_%)، نفس مبدأ treasury.php — مش تخمين بادئة كود
$cashAccounts = $pdo->query("
    SELECT ias.setting_key, ac.id AS account_id, ac.name, ac.balance, ac.base_balance,
           c.code AS currency_code
    FROM `{$TIAS}` ias
    JOIN `{$TAC}` ac ON ac.id = ias.account_id
    JOIN currencies c ON c.id = ac.currency_id
    WHERE ias.setting_key LIKE 'cash\\_%' OR ias.setting_key LIKE 'bank\\_%'
    ORDER BY ias.setting_key
")->fetchAll(PDO::FETCH_ASSOC);

$warehouses = $pdo->query("SELECT * FROM `{$TW}` WHERE is_active=1 AND warehouse_type='products' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$consWarehouses = $pdo->query("SELECT * FROM `{$TW}` WHERE is_active=1 AND warehouse_type='consumables' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
// ✅ مُحدَّث: تجميع حقيقي بـ group_key (نفس مصدر الحقيقة المعتمد بمودال
// اختيار المنتجات بفاتورة البيع) — بدل عرض كل متغيّر (مقاس×لون) كصف
// مسطّح لحاله. صف واحد لكل (منتج × كروب × لون)، القياسات كلها مجمَّعة
// بعرض واحد، وvariant_ids محفوظة للاستخدام وقت التوزيع التلقائي للكمية.
$variantsRaw = $pdo->query("SELECT v.id AS variant_id, v.product_id, v.color_id,
        p.model_number, p.name AS product_name,
        ps.size, ps.group_key, ps.packet_qty, ps.sort_order,
        COALESCE(ps.cost_price, 0) AS default_cost,
        pc.name AS color
    FROM `{$TPV}` v
    JOIN `{$TP}` p ON p.id = v.product_id
    JOIN `{$TPS}` ps ON ps.id = v.size_id
    LEFT JOIN `{$TPC}` pc ON pc.id = v.color_id
    WHERE p.is_active=1 AND v.is_active=1
    ORDER BY p.name, ps.group_key, v.color_id, ps.sort_order")->fetchAll(PDO::FETCH_ASSOC);

$productGroups = [];
foreach ($variantsRaw as $v) {
    $gk = $v['product_id'] . '|' . $v['group_key'] . '|' . ($v['color_id'] ?: '0');
    if (!isset($productGroups[$gk])) {
        $productGroups[$gk] = [
            'product_id' => $v['product_id'],
            'model_number' => $v['model_number'],
            'product_name' => $v['product_name'],
            'group_key' => $v['group_key'],
            'color' => $v['color'],
            'default_cost' => $v['default_cost'],
            'sizes' => [],
            'variant_ids' => [],
        ];
    }
    $productGroups[$gk]['sizes'][] = $v['size'];
    $productGroups[$gk]['variant_ids'][] = $v['variant_id'];
}
$productGroups = array_values($productGroups);

// ترقيم "كروب ١/٢/٣..." لكل منتج — حسب ترتيب ظهور group_key الأول له
$groupIndexByProduct = [];
foreach ($productGroups as $g) {
    $pid = $g['product_id'];
    if (!isset($groupIndexByProduct[$pid])) $groupIndexByProduct[$pid] = [];
    if (!isset($groupIndexByProduct[$pid][$g['group_key']])) {
        $groupIndexByProduct[$pid][$g['group_key']] = count($groupIndexByProduct[$pid]);
    }
}
$GRP_COLORS = [
    ['#eff6ff', '#1d4ed8', '#bfdbfe'],
    ['#f0fdf4', '#15803d', '#bbf7d0'],
    ['#fefce8', '#a16207', '#fde68a'],
    ['#fdf2f8', '#be185d', '#fbcfe8'],
];

$consumableItems = $pdo->query("SELECT id, name, category FROM `{$TCI}` WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$customers = $pdo->query("SELECT id, name, account_id FROM `{$TC}` WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$suppliers = $pdo->query("SELECT id, name, account_id FROM `{$TSUP}` WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>الأرصدة الافتتاحية — <?= htmlspecialchars($branchName) ?></title>
<link rel="icon" href="<?= BASE_PATH ?>/assets/images/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="../../assets/css/layout.css" rel="stylesheet">
<style>
.ob-row{display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:.5rem;align-items:center;padding:.5rem 0;border-bottom:1px solid #f1f5f9}
.ob-row input,.ob-row select{font-size:.8rem}
.lock-banner{border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.25rem;display:flex;align-items:center;justify-content:space-between}
</style>
</head>
<body>
<div class="sb-overlay" id="sbOverlay"></div>
<?php
require_once __DIR__ . '/../../../includes/sidebar.php';
require_once __DIR__ . '/../../../includes/breadcrumb.php';
?>
<header class="topbar">
    <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
    <span class="tb-title"><i class="bi bi-clock-history me-1 text-primary"></i>الأرصدة الافتتاحية</span>
    <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
    <?= renderBreadcrumb() ?>
</header>

<main class="main-content">
<div class="content-body">

    <?php if ($isLocked): ?>
        <div class="lock-banner bg-secondary-subtle text-secondary">
            <div><i class="bi bi-lock-fill me-2"></i>الأرصدة الافتتاحية <strong>مقفولة نهائياً</strong> لهذا الفرع منذ <?= htmlspecialchars($branchRow['opening_balance_locked_at']) ?> — لا يمكن التعديل</div>
        </div>
    <?php else: ?>
        <div class="lock-banner bg-warning-subtle text-warning-emphasis">
            <div><i class="bi bi-unlock-fill me-2"></i>الأرصدة الافتتاحية <strong>مفتوحة للتعديل</strong> — تأكد من صحة كل الأرصدة قبل القفل النهائي</div>
            <?php if (can('admin.opening_balances', 'confirm')): ?>
            <button class="btn btn-danger btn-sm" onclick="lockBalances()"><i class="bi bi-lock-fill me-1"></i>قفل نهائي</button>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e2e8f0">
        <li class="nav-item"><a class="nav-link fw-600 active" data-bs-toggle="tab" href="#tab-stock"><i class="bi bi-box-seam me-1"></i>مخزون المنتجات</a></li>
        <li class="nav-item"><a class="nav-link fw-600" data-bs-toggle="tab" href="#tab-cons"><i class="bi bi-recycle me-1"></i>مخزون المستهلكات</a></li>
        <li class="nav-item"><a class="nav-link fw-600" data-bs-toggle="tab" href="#tab-cust"><i class="bi bi-person-lines-fill me-1"></i>أرصدة العملاء</a></li>
        <li class="nav-item"><a class="nav-link fw-600" data-bs-toggle="tab" href="#tab-sup"><i class="bi bi-truck me-1"></i>أرصدة الموردين</a></li>
        <li class="nav-item"><a class="nav-link fw-600" data-bs-toggle="tab" href="#tab-cash"><i class="bi bi-cash-coin me-1"></i>الصندوق والبنوك</a></li>
    </ul>

    <div class="tab-content">
        <!-- مخزون منتجات — مجمَّع بالكروب (group_key)، قابل للبحث/الفلترة -->
        <div class="tab-pane fade show active" id="tab-stock">
            <div class="table-card p-3">
                <input type="text" id="productSearchInput" class="form-control form-control-sm mb-3"
                       placeholder="بحث برقم الموديل أو اسم المنتج..." oninput="filterProductRows()">
                <div style="overflow-x:auto">
                <table class="table table-sm align-middle mb-0" style="font-size:.82rem">
                    <thead>
                        <tr class="text-muted" style="font-size:.75rem">
                            <th>الموديل</th><th>المنتج</th><th>الكروب</th><th>القياسات</th><th>اللون</th>
                            <th>مستودع</th><th>الكمية الإجمالية</th><th>تكلفة الوحدة</th><th></th>
                        </tr>
                    </thead>
                    <tbody id="productRowsBody">
                    <?php foreach ($productGroups as $g):
                        $grpIdx = $groupIndexByProduct[$g['product_id']][$g['group_key']] ?? 0;
                        [$bg, $clr, $br] = $GRP_COLORS[$grpIdx % 4];
                        $sizesStr = implode(' · ', $g['sizes']);
                        $searchKey = mb_strtolower($g['model_number'] . ' ' . $g['product_name']);
                    ?>
                        <tr class="product-group-row" data-search="<?= htmlspecialchars($searchKey) ?>"
                            data-variant-ids="<?= implode(',', $g['variant_ids']) ?>">
                            <td class="fw-600"><?= htmlspecialchars($g['model_number']) ?></td>
                            <td><?= htmlspecialchars($g['product_name']) ?></td>
                            <td><span class="badge" style="background:<?= $bg ?>;color:<?= $clr ?>;border:1px solid <?= $br ?>">كروب <?= $grpIdx + 1 ?></span></td>
                            <td class="text-muted"><?= htmlspecialchars($sizesStr) ?></td>
                            <td><?= htmlspecialchars($g['color'] ?: '-') ?></td>
                            <td>
                                <select class="form-select form-select-sm wh-select" <?= $isLocked ? 'disabled' : '' ?>>
                                    <option value="">اختر مستودع</option>
                                    <?php foreach ($warehouses as $w): ?>
                                    <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="number" step="0.01" class="form-control form-control-sm qty-input-group"
                                       placeholder="مجموع كل المقاسات" title="بتنقسم تلقائياً على <?= count($g['sizes']) ?> مقاس"
                                       <?= $isLocked ? 'disabled' : '' ?>>
                            </td>
                            <td>
                                <input type="number" step="0.0001" class="form-control form-control-sm cost-input"
                                       value="<?= htmlspecialchars((string) $g['default_cost']) ?>" <?= $isLocked ? 'disabled' : '' ?>>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary" onclick="saveStockGroup(this)" <?= $isLocked ? 'disabled' : '' ?>>
                                    <i class="bi bi-check-lg"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if (empty($productGroups)): ?>
                <div class="text-center text-muted py-4 small">ما في منتجات نشطة بعد</div>
                <?php endif; ?>
            </div>
        </div>


        <!-- مخزون مستهلكات -->
        <div class="tab-pane fade" id="tab-cons">
            <div class="table-card p-3">
                <div class="ob-row fw-600 text-muted" style="font-size:.78rem">
                    <div>المادة</div><div>المستودع</div><div>الكمية</div><div>تكلفة الوحدة</div><div></div>
                </div>
                <?php foreach ($consumableItems as $it): ?>
                <div class="ob-row" data-item="<?= $it['id'] ?>">
                    <div><?= htmlspecialchars($it['name']) ?> <span class="text-muted">(<?= htmlspecialchars($it['category']) ?>)</span></div>
                    <select class="form-select form-select-sm wh-select" <?= $isLocked ? 'disabled' : '' ?>>
                        <option value="">اختر مستودع</option>
                        <?php foreach ($consWarehouses as $w): ?>
                        <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" step="0.001" class="form-control form-control-sm qty-input" placeholder="0" <?= $isLocked ? 'disabled' : '' ?>>
                    <input type="number" step="0.0001" class="form-control form-control-sm cost-input" placeholder="0.00" <?= $isLocked ? 'disabled' : '' ?>>
                    <button class="btn btn-sm btn-outline-primary" onclick="saveConsumable(this)" <?= $isLocked ? 'disabled' : '' ?>><i class="bi bi-check-lg"></i></button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- أرصدة عملاء -->
        <div class="tab-pane fade" id="tab-cust">
            <div class="table-card p-3">
                <div class="alert alert-info py-2" style="font-size:.8rem">المبلغ = ما يستحقه العميل لنا (مدين).</div>
                <?php foreach ($customers as $c): ?>
                <div class="ob-row" style="grid-template-columns:2fr 1fr auto" data-party="<?= $c['id'] ?>">
                    <div><?= htmlspecialchars($c['name']) ?></div>
                    <input type="number" step="0.01" class="form-control form-control-sm amount-input" placeholder="0.00" <?= (!$c['account_id'] || $isLocked) ? 'disabled' : '' ?>>
                    <button class="btn btn-sm btn-outline-primary" onclick="saveParty(this,'customer')" <?= (!$c['account_id'] || $isLocked) ? 'disabled' : '' ?>><i class="bi bi-check-lg"></i></button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- أرصدة موردين -->
        <div class="tab-pane fade" id="tab-sup">
            <div class="table-card p-3">
                <div class="alert alert-info py-2" style="font-size:.8rem">المبلغ = ما نستحقه على المورد (دائن).</div>
                <?php foreach ($suppliers as $s): ?>
                <div class="ob-row" style="grid-template-columns:2fr 1fr auto" data-party="<?= $s['id'] ?>">
                    <div><?= htmlspecialchars($s['name']) ?></div>
                    <input type="number" step="0.01" class="form-control form-control-sm amount-input" placeholder="0.00" <?= (!$s['account_id'] || $isLocked) ? 'disabled' : '' ?>>
                    <button class="btn btn-sm btn-outline-primary" onclick="saveParty(this,'supplier')" <?= (!$s['account_id'] || $isLocked) ? 'disabled' : '' ?>><i class="bi bi-check-lg"></i></button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- الصندوق والبنوك -->
        <div class="tab-pane fade" id="tab-cash">
            <div class="table-card p-3">
                <div class="alert alert-info py-2" style="font-size:.8rem">
                    الحسابات مسحوبة مباشرة من إعدادات الربط المحاسبي (<code>invoice_account_settings</code>) — مو تخمين. المبلغ بعملة الحساب نفسه، وبيتحوّل تلقائياً لعملة الفرع بسعر الصرف المسجَّل.
                </div>
                <?php if (empty($cashAccounts)): ?>
                <div class="text-muted small">ما في حسابات صندوق/بنك مربوطة بعد بصفحة إعدادات الربط المحاسبي.</div>
                <?php endif; ?>
                <?php foreach ($cashAccounts as $ca): ?>
                <div class="ob-row" style="grid-template-columns:2fr 1fr 1fr auto" data-account="<?= $ca['account_id'] ?>">
                    <div><?= htmlspecialchars($ca['name']) ?> <span class="badge bg-light text-dark border"><?= htmlspecialchars($ca['currency_code']) ?></span></div>
                    <div class="text-muted small">الرصيد الحالي: <?= number_format((float) $ca['balance'], 2) ?></div>
                    <input type="number" step="0.01" class="form-control form-control-sm amount-input" placeholder="0.00" <?= $isLocked ? 'disabled' : '' ?>>
                    <button class="btn btn-sm btn-outline-primary" onclick="saveCash(this)" <?= $isLocked ? 'disabled' : '' ?>><i class="bi bi-check-lg"></i></button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_PATH ?>/assets/js/sidebar.js"></script>
<script>
function post(data) {
    const fd = new FormData();
    for (const k in data) fd.append(k, data[k]);
    return fetch(location.href, { method: 'POST', body: fd }).then(r => r.json());
}
function toast(msg, type = 'success') {
    const el = document.createElement('div');
    el.className = `alert alert-${type} position-fixed`;
    el.style.cssText = 'bottom:20px;left:20px;z-index:9999;border-radius:10px;font-size:.85rem';
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 3000);
}
function saveStock(btn) {
    const row = btn.closest('.ob-row');
    post({
        _action: 'save_stock',
        variant_id: row.dataset.variant,
        warehouse_id: row.querySelector('.wh-select').value,
        quantity: row.querySelector('.qty-input').value,
        cost: row.querySelector('.cost-input').value,
    }).then(d => {
        if (d.ok) { toast(d.msg); row.style.opacity = '.5'; }
        else toast(d.msg, 'danger');
    });
}

// ✅ جديد — بحث/فلترة صفوف المنتجات المجمَّعة بالكروب (client-side،
// بيانات محمَّلة أصلاً بالصفحة، ما يحتاج طلب إضافي للسيرفر)
function filterProductRows() {
    const q = document.getElementById('productSearchInput').value.trim().toLowerCase();
    document.querySelectorAll('#productRowsBody .product-group-row').forEach(row => {
        row.style.display = row.dataset.search.includes(q) ? '' : 'none';
    });
}

// ✅ جديد — حفظ رصيد كروب كامل (كل مقاساته مع بعض) بضغطة وحدة. الكمية
// المُدخلة = إجمالي كل المقاسات، السيرفر بيقسّمها تلقائياً على عدد
// المقاسات بالكروب (نفس منطق "24÷4=6" المعتمد بأداة استيراد المنتجات)
function saveStockGroup(btn) {
    const row = btn.closest('tr');
    post({
        _action: 'save_stock_group',
        variant_ids: row.dataset.variantIds,
        warehouse_id: row.querySelector('.wh-select').value,
        total_quantity: row.querySelector('.qty-input-group').value,
        cost: row.querySelector('.cost-input').value,
    }).then(d => {
        if (d.ok) { toast(d.msg); row.style.opacity = '.5'; }
        else toast(d.msg, 'danger');
    });
}

function saveConsumable(btn) {
    const row = btn.closest('.ob-row');
    post({
        _action: 'save_consumable',
        item_id: row.dataset.item,
        warehouse_id: row.querySelector('.wh-select').value,
        quantity: row.querySelector('.qty-input').value,
        cost: row.querySelector('.cost-input').value,
    }).then(d => {
        if (d.ok) { toast(d.msg); row.style.opacity = '.5'; }
        else toast(d.msg, 'danger');
    });
}
function saveParty(btn, type) {
    const row = btn.closest('.ob-row');
    post({
        _action: 'save_party',
        party_type: type,
        party_id: row.dataset.party,
        amount: row.querySelector('.amount-input').value,
    }).then(d => {
        if (d.ok) { toast(d.msg); row.style.opacity = '.5'; }
        else toast(d.msg, 'danger');
    });
}
function saveCash(btn) {
    const row = btn.closest('.ob-row');
    post({
        _action: 'save_cash',
        account_id: row.dataset.account,
        amount: row.querySelector('.amount-input').value,
    }).then(d => {
        if (d.ok) { toast(d.msg); row.style.opacity = '.5'; }
        else toast(d.msg, 'danger');
    });
}
function lockBalances() {
    if (!confirm('تأكيد القفل النهائي — لا يمكن التراجع بعدها. هل أنت متأكد؟')) return;
    post({ _action: 'lock', confirm: 'yes' }).then(d => {
        if (d.ok) { toast(d.msg); setTimeout(() => location.reload(), 800); }
        else toast(d.msg, 'danger');
    });
}
</script>
</body>
</html>
