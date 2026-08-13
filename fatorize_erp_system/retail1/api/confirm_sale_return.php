<?php
/**
 * api/confirm_sale_return.php — تأكيد مرتجع فاتورة بيع
 * (عكس مخزون بالإضافة + قيد محاسبي معاكس لقيد فاتورة البيع الأصلية)
 *
 * ⚠ ما إله مرجع جاهز (confirm_purchase_return.php غير مبني هو الآخر
 * حسب توثيق returns.php بالمشتريات) — مبني اعتماداً على منطق
 * confirm_sale_invoice.php نفسه (نفس اتفاقية العملة، نفس أسلوب بناء
 * القيود)، بعكس الاتجاه المحاسبي المناسب لمرتجع (نطرح من الإيرادات
 * بدل ما نضيف، نخفّض ذمم العملاء بدل ما نزيدها، نضيف للمخزون بدل ما
 * نطرح منه).
 *
 * ⚠ قرار غير مؤكَّد صراحة مع صاحب المشروع — يستاهل مراجعة: طريقة
 * التسوية (payment_handling) بس 'paid_refund_cash' بتولّد قيد نقدي
 * إضافي فعلي (استرداد كاش). باقي القيم (not_paid/partial/
 * paid_credit_customer) كلهم بيتعاملوا نفس المعاملة — بس تخفيض ذمم
 * العميل (نفس تأثير القيد الرئيسي، بلا قيد إضافي)، لأنه اقتصادياً
 * "خصم من ذمة العميل" هو بالضبط نفس التأثير الافتراضي لتخفيض الذمم.
 */
ob_start();
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

try {
    session_start();
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['ok' => false, 'msg' => 'غير مسجل']);
        exit;
    }
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../config/auth.php';

    $pdo = getConnection();
    requirePermission('sales.returns', 'confirm');

    $TS = $_SESSION['table_suffix'];
    $TR = "sales_returns_{$TS}";
    $TRI = "sales_return_items_{$TS}";
    $TWI = "warehouse_items_{$TS}";
    $TC = "customers_{$TS}";
    $TAC = "account_charts_{$TS}";
    $TIAS = "invoice_account_settings_{$TS}";
    $TJE = "journal_entries_{$TS}";
    $TJI = "journal_entry_items_{$TS}";

    // ── عملة الفرع الأساسية ──
    $baseCurrencyId = 0;
    if (!empty($_SESSION['branch_id'])) {
        $bcStmt = $pdo->prepare("SELECT base_currency_id FROM `branches` WHERE id=?");
        $bcStmt->execute([$_SESSION['branch_id']]);
        $baseCurrencyId = (int) ($bcStmt->fetchColumn() ?: 0);
    }

    $getAcc = function (string $key) use ($pdo, $TIAS, $TAC): ?array {
        $st = $pdo->prepare("SELECT ac.* FROM `{$TIAS}` i
            JOIN `{$TAC}` ac ON ac.id=i.account_id
            WHERE i.setting_key=? LIMIT 1");
        $st->execute([$key]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    };

    $act = $_POST['_action'] ?? '';

    // ════════════════════════════════════════════════════════════
    // إلغاء مرتجع مؤكَّد
    // ════════════════════════════════════════════════════════════
    if ($act === 'cancel_return') {
        requirePermission('sales.returns', 'confirm');
        $retId = (int) ($_POST['return_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if (!$retId) throw new Exception('رقم المرتجع مطلوب');

        $stRet = $pdo->prepare("SELECT * FROM `{$TR}` WHERE id=?");
        $stRet->execute([$retId]);
        $ret = $stRet->fetch(PDO::FETCH_ASSOC);
        if (!$ret) throw new Exception('المرتجع غير موجود');
        if ($ret['status'] === 'cancelled') throw new Exception('ملغى مسبقاً');
        if ($ret['status'] !== 'posted') throw new Exception('يمكن إلغاء المرتجعات المؤكدة فقط');

        $pdo->beginTransaction();
        try {
            // عكس إضافة المخزون (كانت أُضيفت وقت التأكيد، هلق نطرحها)
            $stItems = $pdo->prepare("SELECT ri.*, s.cost_price FROM `{$TRI}` ri
                LEFT JOIN `product_variants_{$TS}` v ON v.id=ri.variant_id
                LEFT JOIN `product_sizes_{$TS}` s ON s.id=v.size_id
                WHERE ri.return_id=?");
            $stItems->execute([$retId]);
            $cancelItems = $stItems->fetchAll(PDO::FETCH_ASSOC);

            $movDetailsCancel = [];
            foreach ($cancelItems as $item) {
                if (!$item['variant_id'] || !$ret['warehouse_id']) continue;
                $stB = $pdo->prepare("SELECT quantity FROM `{$TWI}` WHERE variant_id=? AND warehouse_id=?");
                $stB->execute([$item['variant_id'], $ret['warehouse_id']]);
                $balBefore = (float) ($stB->fetchColumn() ?: 0);

                $pdo->prepare("UPDATE `{$TWI}` SET quantity=GREATEST(0,quantity-?), last_movement_at=NOW()
                    WHERE variant_id=? AND warehouse_id=?")
                    ->execute([$item['quantity_returned'], $item['variant_id'], $ret['warehouse_id']]);

                $cost = (float) ($item['cost_price'] ?? 0);
                $movDetailsCancel[] = [
                    'variant_id' => $item['variant_id'], 'product_id' => $item['product_id'],
                    'quantity' => $item['quantity_returned'], 'unit_price' => $item['unit_price'],
                    'cost_price' => $cost, 'total_value' => $cost * (float) $item['quantity_returned'],
                    'balance_before' => $balBefore,
                    'balance_after' => max(0, $balBefore - (float) $item['quantity_returned']),
                ];
            }
            if (!empty($movDetailsCancel)) {
                $movNoCancel = 'MOV-OUT-CNL-' . date('Ymd') . '-' . str_pad($retId, 5, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO `inventory_movements_{$TS}`
                    (movement_number, movement_type, warehouse_id, items_count, total_quantity,
                     total_value_base, reference_type, reference_id, reference_number, notes, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([
                        $movNoCancel, 'out', $ret['warehouse_id'], count($movDetailsCancel),
                        array_sum(array_column($movDetailsCancel, 'quantity')),
                        array_sum(array_column($movDetailsCancel, 'total_value')),
                        'sale_return', $retId, $ret['return_number'],
                        "إلغاء مرتجع بيع {$ret['return_number']} — طرح مخزون" . ($reason ? " ({$reason})" : ''),
                        $_SESSION['user_id']
                    ]);
                $movIdCancel = (int) $pdo->lastInsertId();
                foreach ($movDetailsCancel as $md) {
                    $pdo->prepare("INSERT INTO `inventory_movement_details_{$TS}`
                        (movement_id, variant_id, product_id, quantity, unit_price, cost_price,
                         total_value, balance_before, balance_after)
                        VALUES (?,?,?,?,?,?,?,?,?)")
                        ->execute([
                            $movIdCancel, $md['variant_id'], $md['product_id'], $md['quantity'],
                            $md['unit_price'], $md['cost_price'], $md['total_value'],
                            $md['balance_before'], $md['balance_after']
                        ]);
                }
            }

            // ⚠ نفس اقتصار فاتورة البيع بالضبط: بيعكس بس القيد الرئيسي
            // (journal_entry_id المخزَّن على المرتجع نفسه) — قيود COGS
            // والاسترداد النقدي المنفصلة ما بتنعكس تلقائياً هون. قيد
            // موروث من نفس منطق إلغاء الفاتورة، مو باگ جديد.
            if ($ret['journal_entry_id']) {
                $stJI = $pdo->prepare("SELECT * FROM `{$TJI}` WHERE journal_entry_id=?");
                $stJI->execute([$ret['journal_entry_id']]);
                foreach ($stJI->fetchAll(PDO::FETCH_ASSOC) as $ji) {
                    // ⚠ إصلاح: القيد الرئيسي هون بيلمس بس حسابات غير
                    // نقدية (إيراد/عميل/ضريبة/خصم) — كلهم balance ==
                    // base_balance بالضبط (نفس تصحيح فاتورة البيع). $net
                    // وحده كافٍ للاثنين.
                    $net = $ji['debit'] - $ji['credit'];
                    $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                        ->execute([$net, $net, $ji['account_id']]);
                }
                $pdo->prepare("UPDATE `{$TJE}` SET status='cancelled',cancelled_at=NOW(),cancelled_by=? WHERE id=?")
                    ->execute([$_SESSION['user_id'], $ret['journal_entry_id']]);
            }

            $pdo->prepare("UPDATE `{$TR}` SET status='cancelled',
                notes=CONCAT(COALESCE(notes,''),' | إلغاء: {$reason}')
                WHERE id=?")
                ->execute([$retId]);

            $pdo->commit();
            ob_get_clean();
            echo json_encode(['ok' => true, 'msg' => 'تم إلغاء المرتجع وعكس المخزون والقيد الرئيسي']);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        exit;
    }

    if ($act !== 'confirm_return') throw new Exception('إجراء غير معروف');

    $retId = (int) ($_POST['return_id'] ?? 0);
    if (!$retId) throw new Exception('رقم المرتجع مطلوب');

    $stRet = $pdo->prepare("SELECT r.*, c.account_id AS customer_account_id, c.prepaid_account_id AS customer_prepaid_account_id
        FROM `{$TR}` r
        LEFT JOIN `{$TC}` c ON c.id = r.customer_id
        WHERE r.id=?");
    $stRet->execute([$retId]);
    $ret = $stRet->fetch(PDO::FETCH_ASSOC);
    if (!$ret) throw new Exception('المرتجع غير موجود');
    if ($ret['status'] !== 'draft') throw new Exception('يمكن تأكيد المسودات فقط');

    $stItems = $pdo->prepare("SELECT * FROM `{$TRI}` WHERE return_id=?");
    $stItems->execute([$retId]);
    $items = $stItems->fetchAll(PDO::FETCH_ASSOC);
    if (empty($items)) throw new Exception('المرتجع لا يحتوي بنوداً');

    $rate = (float) ($ret['exchange_rate'] ?: 1);
    if ($rate <= 0) $rate = 1;
    $docCurrencyId = (int) ($ret['return_currency_id'] ?: $baseCurrencyId);

    // ⚠⚠ نفس إصلاح confirm_sale_invoice.php بالضبط: return_amount/
    // tax_amount/discount_amount بجدول المرتجع صاروا بعملة الفرع
    // مباشرة (returns.php يحسبهم من total_price المخزَّن بعملة الفرع
    // أصلاً) — القراءة المباشرة هي "Base"، والمكافئ بعملة الفاتورة
    // يُشتق بالضرب لا القسمة.
    $returnBase = (float) $ret['return_amount'];
    $returnOrig = round($returnBase * $rate, 4);
    $taxBase = (float) $ret['tax_amount'];
    $discBase = (float) $ret['discount_amount'];
    $taxOrig = round($taxBase * $rate, 4);
    $discOrig = round($discBase * $rate, 4);

    $pdo->beginTransaction();
    try {
        // ── إضافة المخزون (عكس فاتورة البيع: هون نضيف، مو نطرح) ──
        // ⚠ كانت ناقصة سجل الحركة بالكامل (inventory_movements/details) —
        // نفس فجوة confirm_sale_invoice.php بالضبط، صار عندنا سجل تدقيق كامل.
        $totalCogs = 0;
        $movDetails = [];
        foreach ($items as $item) {
            if (!$item['variant_id'] || !$ret['warehouse_id']) continue;

            $costSt = $pdo->prepare("SELECT s.cost_price FROM `product_variants_{$TS}` v
                JOIN `product_sizes_{$TS}` s ON s.id=v.size_id WHERE v.id=?");
            $costSt->execute([$item['variant_id']]);
            $cost = (float) ($costSt->fetchColumn() ?: 0);
            $totalCogs += $cost * (float) $item['quantity_returned'];

            $stB = $pdo->prepare("SELECT quantity FROM `{$TWI}` WHERE variant_id=? AND warehouse_id=?");
            $stB->execute([$item['variant_id'], $ret['warehouse_id']]);
            $balBefore = (float) ($stB->fetchColumn() ?: 0);

            $pdo->prepare("UPDATE `{$TWI}` SET quantity=quantity+?, last_movement_at=NOW()
                WHERE variant_id=? AND warehouse_id=?")
                ->execute([$item['quantity_returned'], $item['variant_id'], $ret['warehouse_id']]);

            $movDetails[] = [
                'variant_id' => $item['variant_id'], 'product_id' => $item['product_id'],
                'quantity' => $item['quantity_returned'], 'unit_price' => $item['unit_price'],
                'cost_price' => $cost, 'total_value' => $cost * (float) $item['quantity_returned'],
                'balance_before' => $balBefore, 'balance_after' => $balBefore + (float) $item['quantity_returned'],
            ];
        }

        if (!empty($movDetails)) {
            $movNo = 'MOV-IN-RET-' . date('Ymd') . '-' . str_pad($retId, 5, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO `inventory_movements_{$TS}`
                (movement_number, movement_type, warehouse_id, items_count, total_quantity,
                 total_value_base, reference_type, reference_id, reference_number, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $movNo, 'in', $ret['warehouse_id'], count($movDetails),
                    array_sum(array_column($movDetails, 'quantity')),
                    array_sum(array_column($movDetails, 'total_value')),
                    'sale_return', $retId, $ret['return_number'],
                    "إضافة مخزون مرتجع بيع {$ret['return_number']} — {$ret['customer_name']}",
                    $_SESSION['user_id']
                ]);
            $movId = (int) $pdo->lastInsertId();
            foreach ($movDetails as $md) {
                $pdo->prepare("INSERT INTO `inventory_movement_details_{$TS}`
                    (movement_id, variant_id, product_id, quantity, unit_price, cost_price,
                     total_value, balance_before, balance_after)
                    VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([
                        $movId, $md['variant_id'], $md['product_id'], $md['quantity'],
                        $md['unit_price'], $md['cost_price'], $md['total_value'],
                        $md['balance_before'], $md['balance_after']
                    ]);
            }
        }

        // ── حسابات الربط ──
        // ⚠ نفس إصلاح confirm_sale_invoice.php بالضبط: لو العميل عنده
        // حساب ذمة خاص، المرتجع لازم يرحّل لنفس الحساب يلي الفاتورة
        // الأصلية استخدمته — مش الحساب العام دايماً، وإلا رصيد العميل
        // الخاص ما بينعكس فيه أثر المرتجع إطلاقاً.
        $accCustomer = null;
        if (!empty($ret['customer_account_id'])) {
            $stCa = $pdo->prepare("SELECT * FROM `{$TAC}` WHERE id=? AND is_active=1");
            $stCa->execute([$ret['customer_account_id']]);
            $accCustomer = $stCa->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$accCustomer) {
            $accCustomer = $getAcc('customer_receivable');
        }
        $accRevenue = $getAcc('sales_revenue');
        $accCogs = $getAcc('cogs');
        $accInventory = $getAcc('finished_inventory');
        $accTaxPayable = $getAcc('sales_tax_payable');
        $accSalesDiscount = $getAcc('sales_discount_given');
        if (!$accCustomer || !$accRevenue)
            throw new Exception('يرجى ضبط إعدادات الربط المحاسبي أولاً (ذمم العملاء + إيرادات المبيعات)');

        $y = date('Y');
        $last = $pdo->query("SELECT entry_number FROM `{$TJE}`
            WHERE entry_number LIKE 'JE-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $seq = $last ? (int) substr($last, -4) + 1 : 1;
        $jeNo = 'JE-' . $y . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

        // ⚠ نفس فلسفة تفكيك الضريبة/الخصم بفاتورة البيع، بس معكوسة:
        // مدين الإيراد (تخفيضه) بدل دائن، مدين الضريبة (تخفيض الالتزام)
        // بدل دائن، دائن الخصم (تخفيض المصروف يلي كنا سجّلناه) بدل مدين.
        $splitTax = $accTaxPayable && $taxOrig > 0;
        $splitDisc = $accSalesDiscount && $discOrig > 0;

        $revenueReversalOrig = $returnOrig + ($splitDisc ? $discOrig : 0) - ($splitTax ? $taxOrig : 0);
        $revenueReversalBase = $returnBase + ($splitDisc ? $discBase : 0) - ($splitTax ? $taxBase : 0);
        $jeTotalBase = $returnBase + ($splitDisc ? $discBase : 0);

        $pdo->prepare("INSERT INTO `{$TJE}`
            (entry_number,entry_date,description,currency_id,exchange_rate,
             total_debit,total_credit,status,reference_type,reference_id,created_by)
            VALUES (?,?,?,?,1,?,?,'posted','sale_return',?,?)")
            ->execute([
                $jeNo, date('Y-m-d'),
                "مرتجع بيع {$ret['return_number']} — {$ret['customer_name']}",
                $baseCurrencyId, $jeTotalBase, $jeTotalBase, $retId, $_SESSION['user_id']
            ]);
        $jeId = (int) $pdo->lastInsertId();

        // مدين: إيرادات المبيعات (تخفيض)
        $pdo->prepare("INSERT INTO `{$TJI}`
            (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
            VALUES (?,?,?,0,?,?,?,?,?)")
            ->execute([$jeId, $accRevenue['id'], $revenueReversalBase, $revenueReversalOrig, $revenueReversalBase,
                "مرتجع {$ret['return_number']}", $docCurrencyId, $rate]);
        $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
            ->execute([$revenueReversalBase, $revenueReversalBase, $accRevenue['id']]);

        // دائن: ذمم العملاء (تخفيض — العميل ما عاد يدين إلنا بهالقيمة)
        $pdo->prepare("INSERT INTO `{$TJI}`
            (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
            VALUES (?,?,0,?,?,?,?,?,?)")
            ->execute([$jeId, $accCustomer['id'], $returnBase, $returnOrig, $returnBase,
                "ذمة {$ret['customer_name']} — مرتجع", $docCurrencyId, $rate]);
        $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
            ->execute([$returnBase, $returnBase, $accCustomer['id']]);

        // مدين: ضريبة مبيعات مستحقة (تخفيض الالتزام، لو مفكَّكة)
        if ($splitTax) {
            $pdo->prepare("INSERT INTO `{$TJI}`
                (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                VALUES (?,?,?,0,?,?,?,?,?)")
                ->execute([$jeId, $accTaxPayable['id'], $taxBase, $taxOrig, $taxBase,
                    "ضريبة مرتجع {$ret['return_number']}", $docCurrencyId, $rate]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                ->execute([$taxBase, $taxBase, $accTaxPayable['id']]);
        }

        // دائن: خصومات مبيعات ممنوحة (تخفيض المصروف يلي سُجِّل بالفاتورة الأصلية)
        if ($splitDisc) {
            $pdo->prepare("INSERT INTO `{$TJI}`
                (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                VALUES (?,?,0,?,?,?,?,?,?)")
                ->execute([$jeId, $accSalesDiscount['id'], $discBase, $discOrig, $discBase,
                    "خصم مرتجع {$ret['return_number']}", $docCurrencyId, $rate]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                ->execute([$discBase, $discBase, $accSalesDiscount['id']]);
        }

        // ── قيد منفصل: عكس تكلفة البضاعة المباعة (إضافة للمخزون،
        // تخفيض COGS) — دايماً بعملة الفرع ──
        if ($accCogs && $accInventory && $totalCogs > 0) {
            $seq++;
            $jeNo2 = 'JE-' . $y . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO `{$TJE}`
                (entry_number,entry_date,description,currency_id,exchange_rate,
                 total_debit,total_credit,status,reference_type,reference_id,created_by)
                VALUES (?,?,?,?,1,?,?,'posted','sale_return_cogs',?,?)")
                ->execute([$jeNo2, date('Y-m-d'), "عكس تكلفة بضاعة — مرتجع {$ret['return_number']}",
                    $baseCurrencyId, $totalCogs, $totalCogs, $retId, $_SESSION['user_id']]);
            $jeCogsId = (int) $pdo->lastInsertId();

            // مدين: المخزون (رجعت البضاعة فعلياً)
            $pdo->prepare("INSERT INTO `{$TJI}`
                (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                VALUES (?,?,?,0,?,?,?,?,1)")
                ->execute([$jeCogsId, $accInventory['id'], $totalCogs, $totalCogs, $totalCogs,
                    "مخزون مرتجع {$ret['return_number']}", $baseCurrencyId]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                ->execute([$totalCogs, $totalCogs, $accInventory['id']]);

            // دائن: تكلفة البضاعة المباعة (تخفيض المصروف)
            $pdo->prepare("INSERT INTO `{$TJI}`
                (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                VALUES (?,?,0,?,?,?,?,?,1)")
                ->execute([$jeCogsId, $accCogs['id'], $totalCogs, $totalCogs, $totalCogs,
                    "عكس COGS {$ret['return_number']}", $baseCurrencyId]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                ->execute([$totalCogs, $totalCogs, $accCogs['id']]);
        }

        // ── قيد منفصل حسب "الحساب المستهدف" — نمط جديد مطابق لمرتجعات
        // المشتريات: receivable (الافتراضي) = بلا قيد إضافي (القيد
        // الرئيسي فوق كافي، خفّض ذمة العميل وخلص). cash/prepaid = لازم
        // نعكس تخفيض الذمة (القيد الرئيسي خفّضها بافتراض default)،
        // ونحوّل الأثر لحساب تاني (صندوق فعلي، أو رصيد دائن بحساب
        // العميل الخاص).
        $targetType = $ret['target_account_type'] ?? null;

        if ($targetType === 'cash' && $ret['refund_account_id']) {
            $seq++;
            $jeNo3 = 'JE-' . $y . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO `{$TJE}`
                (entry_number,entry_date,description,currency_id,exchange_rate,
                 total_debit,total_credit,status,reference_type,reference_id,created_by)
                VALUES (?,?,?,?,1,?,?,'posted','sale_return_refund',?,?)")
                ->execute([$jeNo3, date('Y-m-d'), "استرداد نقدي — مرتجع {$ret['return_number']}",
                    $baseCurrencyId, $returnBase, $returnBase, $retId, $_SESSION['user_id']]);
            $jeRefundId = (int) $pdo->lastInsertId();

            // مدين: ذمم العملاء (عكس تخفيض القيد الرئيسي — رجعنا كاش
            // فعلي مو تخفيض دين)
            $pdo->prepare("INSERT INTO `{$TJI}`
                (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                VALUES (?,?,?,0,?,?,?,?,?)")
                ->execute([$jeRefundId, $accCustomer['id'], $returnBase, $returnOrig, $returnBase,
                    "عكس تخفيض ذمة — استرداد نقدي", $docCurrencyId, $rate]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                ->execute([$returnBase, $returnBase, $accCustomer['id']]);

            // دائن: حساب الاسترداد (صندوق/بنك) — balance بعملته الأصلية
            // (حساب نقدي حقيقي، نفس نمط الصناديق بفاتورة البيع/الشراء)
            $pdo->prepare("INSERT INTO `{$TJI}`
                (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                VALUES (?,?,0,?,?,?,?,?,?)")
                ->execute([$jeRefundId, (int) $ret['refund_account_id'], $returnBase, $returnOrig, $returnBase,
                    "استرداد نقدي {$ret['return_number']}", $docCurrencyId, $rate]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                ->execute([$returnBase, $returnOrig, (int) $ret['refund_account_id']]);

        } elseif ($targetType === 'prepaid') {
            $prepaidAccId = (int) ($ret['customer_prepaid_account_id'] ?? 0);
            if (!$prepaidAccId) {
                throw new Exception('العميل ما إله حساب دفعة مقدمة مضبوط — لا يمكن تحويل المرتجع لرصيد دائن');
            }
            $seq++;
            $jeNo3 = 'JE-' . $y . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO `{$TJE}`
                (entry_number,entry_date,description,currency_id,exchange_rate,
                 total_debit,total_credit,status,reference_type,reference_id,created_by)
                VALUES (?,?,?,?,1,?,?,'posted','sale_return_prepaid',?,?)")
                ->execute([$jeNo3, date('Y-m-d'), "تحويل مرتجع لرصيد دائن — {$ret['return_number']}",
                    $baseCurrencyId, $returnBase, $returnBase, $retId, $_SESSION['user_id']]);
            $jePrepaidId = (int) $pdo->lastInsertId();

            // مدين: ذمم العملاء (عكس تخفيض القيد الرئيسي)
            $pdo->prepare("INSERT INTO `{$TJI}`
                (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                VALUES (?,?,?,0,?,?,?,?,?)")
                ->execute([$jePrepaidId, $accCustomer['id'], $returnBase, $returnOrig, $returnBase,
                    "عكس تخفيض ذمة — تحويل لرصيد دائن", $docCurrencyId, $rate]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                ->execute([$returnBase, $returnBase, $accCustomer['id']]);

            // دائن: حساب الدفعة المقدمة الخاص بالعميل (رصيد دائن يستفيد
            // منه بفاتورة مستقبلية) — غير نقدي، balance=base_balance
            $pdo->prepare("INSERT INTO `{$TJI}`
                (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                VALUES (?,?,0,?,?,?,?,?,1)")
                ->execute([$jePrepaidId, $prepaidAccId, $returnBase, $returnBase, $returnBase,
                    "رصيد دائن — مرتجع {$ret['return_number']}", $baseCurrencyId]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                ->execute([$returnBase, $returnBase, $prepaidAccId]);
        }
        // target_account_type === 'receivable' (أو null لو not_paid) —
        // بلا قيد إضافي، القيد الرئيسي فوق كافي وكامل.

        // ── تحديث المرتجع ──
        $pdo->prepare("UPDATE `{$TR}` SET status='posted', journal_entry_id=? WHERE id=?")
            ->execute([$jeId, $retId]);

        $pdo->commit();
        ob_get_clean();
        echo json_encode(['ok' => true, 'msg' => 'تم تأكيد المرتجع وإضافة المخزون والقيد المحاسبي', 'je_no' => $jeNo]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(200);
    echo json_encode(['ok' => false, 'msg' => $e->getMessage(), 'line' => $e->getLine()]);
}
