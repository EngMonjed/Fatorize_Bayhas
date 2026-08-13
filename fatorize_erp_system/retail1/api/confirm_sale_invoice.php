<?php
/**
 * api/confirm_sale_invoice.php — تأكيد فاتورة بيع (خصم مخزون + قيود محاسبية)
 * مبني مطابقاً لآلية confirm_purchase_invoice.php بالضبط، بعكس الاتجاهات
 * المحاسبية المناسبة (العميل = ذمة/أصل علينا نحصّله، مو التزام علينا
 * كالمورد؛ خصم تعجيل الاستلام = مصروف علينا نحن (نحن يلي بنمنحه)، مو دخل
 * إلنا كما بالمشتريات (المورد يلي كان بيمنحنا الخصم)).
 *
 * ⚠ ملاحظة تسمية حالة الفاتورة: المشتريات بتستخدم status='received'
 * كحالة نهائية بعد التأكيد. المبيعات هون تستخدم 'confirmed' بدل
 * 'received' عمداً — لأنه كل كود المبيعات المبني سابقاً (sales_index.php،
 * customers.php...) أصلاً بيفترض 'confirmed' كحالة الاكتمال، فتغييرها
 * لـ'received' كان رح يكسر كل شي تاني.
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
    requirePermission('sales.invoices', 'confirm');

    $TS = $_SESSION['table_suffix'];
    $TI = "sales_invoices_{$TS}";
    $TII = "sales_invoice_items_{$TS}";
    $TC = "customers_{$TS}";
    $TW = "warehouses_{$TS}";
    $TWI = "warehouse_items_{$TS}";
    $TV = "product_variants_{$TS}";
    $TPROD = "products_{$TS}";
    $TSZ = "product_sizes_{$TS}";
    $TCL = "product_colors_{$TS}";
    $TAC = "account_charts_{$TS}";
    $TIAS = "invoice_account_settings_{$TS}";
    $TJE = "journal_entries_{$TS}";
    $TJI = "journal_entry_items_{$TS}";

    // ── عملة الفرع الأساسية ──
    $baseCurrencyId = 0;
    $baseCurrencyCode = 'USD';
    if (!empty($_SESSION['branch_id'])) {
        $bcStmt = $pdo->prepare("SELECT b.base_currency_id, c.code AS base_currency_code
            FROM `branches` b LEFT JOIN `currencies` c ON c.id = b.base_currency_id
            WHERE b.id = ?");
        $bcStmt->execute([$_SESSION['branch_id']]);
        $bc = $bcStmt->fetch(PDO::FETCH_ASSOC);
        if ($bc) {
            $baseCurrencyId = (int) ($bc['base_currency_id'] ?: 0);
            $baseCurrencyCode = $bc['base_currency_code'] ?: 'USD';
        }
    }

    // كود عملة → id، بذاكرة تخزين مؤقت (نفس نمط المشتريات بالضبط)
    $curIdCache = [];
    $curId = function (string $code) use ($pdo, &$curIdCache): int {
        if (isset($curIdCache[$code]))
            return $curIdCache[$code];
        $st = $pdo->prepare("SELECT id FROM `currencies` WHERE code=? LIMIT 1");
        $st->execute([$code]);
        $id = (int) ($st->fetchColumn() ?: 0);
        $curIdCache[$code] = $id;
        return $id;
    };

    $getAcc = function (string $key) use ($pdo, $TIAS, $TAC): ?array {
        $st = $pdo->prepare("SELECT ac.* FROM `{$TIAS}` i
            JOIN `{$TAC}` ac ON ac.id=i.account_id
            WHERE i.setting_key=? LIMIT 1");
        $st->execute([$key]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    };

    $act = $_POST['_action'] ?? '';

    // ════════════════════════════════════════════════════════════
    // تأكيد الفاتورة
    // ════════════════════════════════════════════════════════════
    if ($act === 'confirm') {
        $invId = (int) ($_POST['invoice_id'] ?? 0);
        if (!$invId)
            throw new Exception('رقم الفاتورة مطلوب');

        $stInv = $pdo->prepare("SELECT i.*, c.name AS customer_name, c.account_id AS customer_account_id,
                cur.code AS currency_code
            FROM `{$TI}` i
            LEFT JOIN `{$TC}` c ON c.id=i.customer_id
            LEFT JOIN `currencies` cur ON cur.id=i.invoice_currency_id
            WHERE i.id=?");
        $stInv->execute([$invId]);
        $inv = $stInv->fetch(PDO::FETCH_ASSOC);
        if (!$inv)
            throw new Exception('الفاتورة غير موجودة');
        if ($inv['status'] !== 'draft')
            throw new Exception('يمكن تأكيد المسودات فقط');

        $whId = (int) ($_POST['warehouse_id'] ?? $inv['warehouse_id'] ?? 0);
        if (!$whId)
            throw new Exception('المستودع مطلوب');

        // ⚠ إصلاح: كان يقرا s.cost_price (ثابت، من وقت تسجيل المنتج أول
        // مرة، ما بيتحدّث مع عمليات الشراء اللاحقة) — صار يقرا
        // wi.current_cost (التكلفة الحيّة، بتتحدّث بكل عملية شراء حسب
        // costing_method تبع الفرع). fallback لـs.cost_price بس لو
        // current_cost لسا صفر (منتج قديم ما دخل عبر النظام الجديد بعد).
        $stItems = $pdo->prepare("SELECT ii.*, p.name AS product_name,
            COALESCE(NULLIF(wi.current_cost, 0), s.cost_price) AS cost_price
            FROM `{$TII}` ii
            LEFT JOIN `{$TPROD}` p ON p.id=ii.product_id
            LEFT JOIN `{$TV}` v ON v.id=ii.variant_id
            LEFT JOIN `{$TSZ}` s ON s.id=v.size_id
            LEFT JOIN `{$TWI}` wi ON wi.variant_id=ii.variant_id AND wi.warehouse_id=?
            WHERE ii.invoice_id=?");
        $stItems->execute([$whId, $invId]);
        $items = $stItems->fetchAll(PDO::FETCH_ASSOC);
        if (empty($items))
            throw new Exception('الفاتورة لا تحتوي بنوداً');

        // ── التحقق من كفاية المخزون قبل أي تعديل (عكس المشتريات: هون
        // منسحب من المخزون، مو نضيف له، فلازم تحقق مسبق) ──
        foreach ($items as $item) {
            if (!$item['variant_id'])
                continue;
            $stStock = $pdo->prepare("SELECT quantity FROM `{$TWI}` WHERE variant_id=? AND warehouse_id=?");
            $stStock->execute([$item['variant_id'], $whId]);
            $stock = (float) ($stStock->fetchColumn() ?? 0);
            if ($stock < $item['quantity']) {
                throw new Exception("مخزون غير كافٍ: {$item['product_name']} (متوفر: {$stock}، مطلوب: {$item['quantity']})");
            }
        }

        // ── عملة الفاتورة (مثبتة وقت الإنشاء، ما بتتغيّر وقت التأكيد) ──
        $rate = (float) ($inv['exchange_rate'] ?: 1);
        if ($rate <= 0)
            $rate = 1;
        $curCode = $inv['currency_code'] ?: $baseCurrencyCode;
        $docCurrencyId = (int) ($inv['invoice_currency_id'] ?: $baseCurrencyId);

        // ⚠⚠ إصلاح معماري حرج: بعد قرار "عملة الفرع = مصدر الحقيقة
        // الوحيد بالهيدر" (نفس قرار invoice_new.php)، عمود final_amount
        // صار بعملة الفرع مباشرة (== final_amount_base_currency)، مش
        // عملة الفاتورة كما كان مفترَضاً هون سابقاً. القسمة القديمة
        // (finalOrig/rate) كانت بتنتج رقم مصغَّر خاطئ فوق قيمة أصلاً
        // صحيحة بعملة الفرع — تماماً نفس فئة الإصلاح بـ
        // confirm_purchase_invoice.php (finalBase مباشرة، finalOrig
        // مُشتق بالضرب لا القسمة).
        $finalBase = (float) ($inv['final_amount_base_currency'] ?? $inv['final_amount']);
        $finalOrig = round($finalBase * $rate, 4); // المكافئ بعملة الفاتورة — توثيقي بس

        $pdo->beginTransaction();
        try {
            // ── خصم المخزون + حركة مخزون ──
            // ⚠ كانت ناقصة بالكامل — الكود القديم بيحدّث warehouse_items
            // مباشرة بلا أي سجل حركة بجدولي inventory_movements/
            // inventory_movement_details (عكس فاتورة الشراء، يلي عندها
            // سجل حركة كامل). صار عندنا سجل تدقيق كامل الآن.
            $movNo = 'MOV-OUT-' . date('Ymd') . '-' . str_pad($invId, 5, '0', STR_PAD_LEFT);
            $totalCogs = 0;
            foreach ($items as $item) {
                $totalCogs += (float) ($item['cost_price'] ?? 0) * (float) $item['quantity'];
            }

            $movDetails = [];
            foreach ($items as $item) {
                if (!$item['variant_id'])
                    continue;

                $stB = $pdo->prepare("SELECT quantity FROM `{$TWI}` WHERE variant_id=? AND warehouse_id=?");
                $stB->execute([$item['variant_id'], $whId]);
                $balBefore = (float) ($stB->fetchColumn() ?: 0);

                $pdo->prepare("UPDATE `{$TWI}` SET quantity=quantity-?, last_movement_at=NOW()
                    WHERE variant_id=? AND warehouse_id=?")
                    ->execute([$item['quantity'], $item['variant_id'], $whId]);

                $movDetails[] = [
                    'variant_id' => $item['variant_id'],
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => (float) ($item['unit_price_base_currency'] ?? ($rate > 0 ? $item['unit_price'] / $rate : $item['unit_price'])), // سعر البيع بعملة الفرع — ⚠ كان يُخزَّن خام بعملة الفاتورة
                    'cost_price' => (float) ($item['cost_price'] ?? 0), // قيمة التكلفة الفعلية
                    'total_value' => (float) ($item['cost_price'] ?? 0) * (float) $item['quantity'],
                    'balance_before' => $balBefore,
                    'balance_after' => $balBefore - (float) $item['quantity'],
                ];
            }

            if (!empty($movDetails)) {
                $pdo->prepare("INSERT INTO `inventory_movements_{$TS}`
                    (movement_number, movement_type, warehouse_id, items_count, total_quantity,
                     total_value_base, reference_type, reference_id, reference_number, notes, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([
                        $movNo,
                        'out',
                        $whId,
                        count($movDetails),
                        array_sum(array_column($movDetails, 'quantity')),
                        array_sum(array_column($movDetails, 'total_value')),
                        'sale',
                        $invId,
                        $inv['invoice_number'],
                        "خصم مخزون فاتورة بيع {$inv['invoice_number']} — {$inv['customer_name']}",
                        $_SESSION['user_id']
                    ]);
                $movId = (int) $pdo->lastInsertId();

                foreach ($movDetails as $md) {
                    $pdo->prepare("INSERT INTO `inventory_movement_details_{$TS}`
                        (movement_id, variant_id, product_id, quantity, unit_price, cost_price,
                         total_value, balance_before, balance_after)
                        VALUES (?,?,?,?,?,?,?,?,?)")
                        ->execute([
                            $movId,
                            $md['variant_id'],
                            $md['product_id'],
                            $md['quantity'],
                            $md['unit_price'],
                            $md['cost_price'],
                            $md['total_value'],
                            $md['balance_before'],
                            $md['balance_after']
                        ]);
                }
            }

            // ── حسابات الربط ──
            // ⚠ إصلاح رجعة كانت انفقدت أثناء إعادة البناء السابقة: لو
            // العميل عنده حساب ذمة خاص (customers.account_id)، الفاتورة
            // لازم تترحّل لنفس هالحساب — مش الحساب العام دايماً. الحساب
            // العام يضل fallback بس لو العميل ما إله حساب خاص مضبوط
            // (أو الحساب المضبوط أصبح غير نشط).
            $accCustomer = null;
            if (!empty($inv['customer_account_id'])) {
                $stCa = $pdo->prepare("SELECT * FROM `{$TAC}` WHERE id=? AND is_active=1");
                $stCa->execute([$inv['customer_account_id']]);
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

            // ── القيد الرئيسي ──
            // ⚠ تفكيك بقرار صريح: الضريبة والخصم ما عادوا مدمجين صامتين
            // بالإيراد الصافي — لو الحسابين (sales_tax_payable/
            // sales_discount_given) مضبوطين فعلياً بـaccount_settings.php،
            // بينفصلوا كسطرين مستقلين بالقيد. لو أي وحدة منهم مو مضبوطة،
            // بيتراجع تلقائياً للسلوك القديم (يدمجها بالإيراد الصافي)
            // — بلا ما يفشل الحفظ.
            //
            // الصيغة العامة (مُتحقَّق رياضياً توازنها بكل الحالات الأربع):
            //   دائن الإيراد = final_amount + (خصم منفصل؟ discount_amount : 0)
            //                              − (ضريبة منفصلة؟ tax_amount : 0)
            //   + دائن ضريبة منفصل (لو موجود) = tax_amount
            //   + مدين خصم منفصل (لو موجود)   = discount_amount
            //   = مدين ذمم العملاء (final_amount) دايماً بلا تغيير
            $y = date('Y');
            $last = $pdo->query("SELECT entry_number FROM `{$TJE}`
                WHERE entry_number LIKE 'JE-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
            $seq = $last ? (int) substr($last, -4) + 1 : 1;
            $jeNo = 'JE-' . $y . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

            $splitTax = $accTaxPayable && (float) $inv['tax_amount'] > 0;
            $splitDisc = $accSalesDiscount && (float) $inv['discount_amount'] > 0;

            // ⚠ نفس الإصلاح: tax_amount/discount_amount بجدول الفاتورة
            // صارا بعملة الفرع مباشرة (لا عملة الفاتورة) — القراءة
            // المباشرة هي "Base"، والمكافئ بعملة الفاتورة يُشتق بالضرب.
            $taxBaseAmt = (float) $inv['tax_amount'];
            $discBaseAmt = (float) $inv['discount_amount'];
            $taxOrig = round($taxBaseAmt * $rate, 4);
            $discOrig = round($discBaseAmt * $rate, 4);

            $revenueOrig = $finalOrig + ($splitDisc ? $discOrig : 0) - ($splitTax ? $taxOrig : 0);
            $revenueBase = $finalBase + ($splitDisc ? $discBaseAmt : 0) - ($splitTax ? $taxBaseAmt : 0);

            // مجموع القيد (لازم يتوازن: مدين العميل + مدين الخصم(إن وُجد) = دائن الإيراد + دائن الضريبة(إن وُجدت))
            $jeTotalBase = $finalBase + ($splitDisc ? $discBaseAmt : 0);

            // ⚠ رأس القيد دايماً بعملة الفرع الأساسية (currency_id/exchange_rate
            // ثابتين هون)، بغض النظر عن عملة الفاتورة — نفس نمط المشتريات.
            $pdo->prepare("INSERT INTO `{$TJE}`
                (entry_number,entry_date,description,currency_id,exchange_rate,
                 total_debit,total_credit,status,reference_type,reference_id,created_by)
                VALUES (?,?,?,?,1,?,?,'posted','sale',?,?)")
                ->execute([
                    $jeNo,
                    date('Y-m-d'),
                    "إيرادات فاتورة بيع {$inv['invoice_number']} — {$inv['customer_name']}",
                    $baseCurrencyId,
                    $jeTotalBase,
                    $jeTotalBase,
                    $invId,
                    $_SESSION['user_id']
                ]);
            $jeId = (int) $pdo->lastInsertId();

            // مدين: ذمم العملاء (أصل، += للزيادة) — يضل final_amount دايماً بلا تغيير
            $pdo->prepare("INSERT INTO `{$TJI}`
                (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                VALUES (?,?,?,0,?,?,?,?,?)")
                ->execute([
                    $jeId,
                    $accCustomer['id'],
                    $finalBase,
                    $finalOrig,
                    $finalBase,
                    "ذمة {$inv['customer_name']}",
                    $docCurrencyId,
                    $rate
                ]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                ->execute([$finalBase, $finalBase, $accCustomer['id']]);

            // دائن: إيرادات المبيعات (صافية أو إجمالية حسب التفكيك أعلاه)
            $pdo->prepare("INSERT INTO `{$TJI}`
                (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                VALUES (?,?,0,?,?,?,?,?,?)")
                ->execute([
                    $jeId,
                    $accRevenue['id'],
                    $revenueBase,
                    $revenueOrig,
                    $revenueBase,
                    "إيراد {$inv['invoice_number']}",
                    $docCurrencyId,
                    $rate
                ]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                ->execute([$revenueBase, $revenueBase, $accRevenue['id']]);

            // دائن: ضريبة مبيعات مستحقة (لو مفكَّكة)
            if ($splitTax) {
                $pdo->prepare("INSERT INTO `{$TJI}`
                    (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                    VALUES (?,?,0,?,?,?,?,?,?)")
                    ->execute([
                        $jeId,
                        $accTaxPayable['id'],
                        $taxBaseAmt,
                        $taxOrig,
                        $taxBaseAmt,
                        "ضريبة {$inv['invoice_number']}",
                        $docCurrencyId,
                        $rate
                    ]);
                $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                    ->execute([$taxBaseAmt, $taxBaseAmt, $accTaxPayable['id']]);
            }

            // مدين: خصومات مبيعات ممنوحة (لو مفكَّكة)
            if ($splitDisc) {
                $pdo->prepare("INSERT INTO `{$TJI}`
                    (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                    VALUES (?,?,?,0,?,?,?,?,?)")
                    ->execute([
                        $jeId,
                        $accSalesDiscount['id'],
                        $discBaseAmt,
                        $discOrig,
                        $discBaseAmt,
                        "خصم {$inv['invoice_number']}",
                        $docCurrencyId,
                        $rate
                    ]);
                $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                    ->execute([$discBaseAmt, $discBaseAmt, $accSalesDiscount['id']]);
            }

            // ── قيد منفصل: تكلفة البضاعة المباعة (COGS) — دايماً بعملة
            // الفرع (التكلفة الأصلية مخزّنة هيك دايماً بجدول المنتجات) ──
            if ($accCogs && $accInventory && $totalCogs > 0) {
                $seq++;
                $jeNo2 = 'JE-' . $y . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO `{$TJE}`
                    (entry_number,entry_date,description,currency_id,exchange_rate,
                     total_debit,total_credit,status,reference_type,reference_id,created_by)
                    VALUES (?,?,?,?,1,?,?,'posted','sale_cogs',?,?)")
                    ->execute([
                        $jeNo2,
                        date('Y-m-d'),
                        "تكلفة بضاعة مباعة {$inv['invoice_number']}",
                        $baseCurrencyId,
                        $totalCogs,
                        $totalCogs,
                        $invId,
                        $_SESSION['user_id']
                    ]);
                $jeCogsId = (int) $pdo->lastInsertId();

                $pdo->prepare("INSERT INTO `{$TJI}`
                    (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                    VALUES (?,?,?,0,?,?,?,?,1)")
                    ->execute([
                        $jeCogsId,
                        $accCogs['id'],
                        $totalCogs,
                        $totalCogs,
                        $totalCogs,
                        "COGS {$inv['invoice_number']}",
                        $baseCurrencyId
                    ]);
                $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                    ->execute([$totalCogs, $totalCogs, $accCogs['id']]);

                $pdo->prepare("INSERT INTO `{$TJI}`
                    (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                    VALUES (?,?,0,?,?,?,?,?,1)")
                    ->execute([
                        $jeCogsId,
                        $accInventory['id'],
                        $totalCogs,
                        $totalCogs,
                        $totalCogs,
                        "مخزون {$inv['invoice_number']}",
                        $baseCurrencyId
                    ]);
                $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                    ->execute([$totalCogs, $totalCogs, $accInventory['id']]);
            }

            // ── قيد منفصل: تكلفة التوصيل للعميل (لو "علينا") ──
            $shipCost = (float) ($_POST['shipping_cost'] ?? 0);
            $shipOn = $_POST['shipping_on'] ?? 'us';
            $shipCarrierId = (int) ($_POST['shipping_carrier_id'] ?? 0);
            $shipDesc = trim($_POST['shipping_desc'] ?? '');
            $shipPayableId = (int) ($_POST['shipping_payable_id'] ?? 0);
            $shipPayMethod = $_POST['shipping_pay_method'] ?? 'cash';
            $shipCashAccId = (int) ($_POST['shipping_cash_account'] ?? 0);
            $shipCur = $_POST['shipping_currency'] ?? $baseCurrencyCode;
            $shipRateManual = (float) ($_POST['shipping_exchange_rate'] ?? 0);

            if ($shipCost > 0 && $shipOn === 'us') {
                $accShipExpense = $getAcc('shipping_expense');
                if ($accShipExpense) {
                    // تحويل تكلفة التوصيل لعملة الفرع
                    if ($shipCur === $baseCurrencyCode) {
                        $shipBase = $shipCost;
                    } elseif ($shipCur === $curCode) {
                        $shipBase = $rate > 0 ? $shipCost / $rate : $shipCost;
                    } else {
                        $shipBase = $shipRateManual > 0 ? $shipCost / $shipRateManual : $shipCost;
                    }
                    $shipRateForLine = $shipBase > 0 ? round($shipCost / $shipBase, 6) : 1;

                    $seq++;
                    $jeNo3 = 'JE-' . $y . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
                    $pdo->prepare("INSERT INTO `{$TJE}`
                        (entry_number,entry_date,description,currency_id,exchange_rate,
                         total_debit,total_credit,status,reference_type,reference_id,created_by)
                        VALUES (?,?,?,?,1,?,?,'posted','sale_shipping',?,?)")
                        ->execute([
                            $jeNo3,
                            date('Y-m-d'),
                            "تكلفة توصيل {$inv['invoice_number']}" . ($shipDesc ? " — {$shipDesc}" : ''),
                            $baseCurrencyId,
                            $shipBase,
                            $shipBase,
                            $invId,
                            $_SESSION['user_id']
                        ]);
                    $jeShipId = (int) $pdo->lastInsertId();

                    // مدين: مصروف التوصيل
                    $pdo->prepare("INSERT INTO `{$TJI}`
                        (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                        VALUES (?,?,?,0,?,?,?,?,?)")
                        ->execute([
                            $jeShipId,
                            $accShipExpense['id'],
                            $shipBase,
                            $shipCost,
                            $shipBase,
                            'مصروف توصيل',
                            $curId($shipCur),
                            $shipRateForLine
                        ]);
                    $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                        ->execute([$shipBase, $shipBase, $accShipExpense['id']]);

                    // دائن: حسب طريقة الدفع (نقدي من صندوق، أو آجل لذمة شركة التوصيل)
                    if ($shipPayMethod === 'cash' && $shipCashAccId) {
                        $pdo->prepare("INSERT INTO `{$TJI}`
                            (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                            VALUES (?,?,0,?,?,?,?,?,?)")
                            ->execute([
                                $jeShipId,
                                $shipCashAccId,
                                $shipBase,
                                $shipCost,
                                $shipBase,
                                'دفع نقدي — توصيل',
                                $curId($shipCur),
                                $shipRateForLine
                            ]);
                        $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                            ->execute([$shipBase, $shipCost, $shipCashAccId]);
                    } elseif ($shipPayableId) {
                        $pdo->prepare("INSERT INTO `{$TJI}`
                            (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                            VALUES (?,?,0,?,?,?,?,?,?)")
                            ->execute([
                                $jeShipId,
                                $shipPayableId,
                                $shipBase,
                                $shipCost,
                                $shipBase,
                                'ذمة شركة توصيل',
                                $curId($shipCur),
                                $shipRateForLine
                            ]);
                        $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                            ->execute([$shipBase, $shipBase, $shipPayableId]);
                    }
                }
            }

            // ── التحصيل الجزئي (لو موجود) ──
            $paidAmtInput = (float) ($_POST['paid_amount'] ?? 0);
            $paidCur = $_POST['paid_currency'] ?? $curCode;
            $paidRateManual = (float) ($_POST['paid_rate'] ?? 1); // "1 عملة الفرع = س عملة الفاتورة"
            $cashAccountId = (int) ($_POST['cash_account_id'] ?? 0);

            $paidAmt = 0; // بعملة الفرع الأساسية — أساس كل الحسابات المحاسبية
            if ($paidAmtInput > 0 && $cashAccountId) {
                if ($paidCur === $curCode) {
                    $paidAmt = $rate > 0 ? $paidAmtInput / $rate : $paidAmtInput;
                } elseif ($paidCur === $baseCurrencyCode) {
                    $paidAmt = $paidAmtInput;
                } else {
                    $paidAmt = $paidRateManual > 0 ? $paidAmtInput / $paidRateManual : $paidAmtInput;
                }

                $seq++;
                $jeNo4 = 'JE-' . $y . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO `{$TJE}`
                    (entry_number,entry_date,description,currency_id,exchange_rate,
                     total_debit,total_credit,status,reference_type,reference_id,created_by)
                    VALUES (?,?,?,?,1,?,?,'posted','sale_payment',?,?)")
                    ->execute([
                        $jeNo4,
                        date('Y-m-d'),
                        "تحصيل جزئي {$inv['invoice_number']}",
                        $baseCurrencyId,
                        $paidAmt,
                        $paidAmt,
                        $invId,
                        $_SESSION['user_id']
                    ]);
                $jePayId = (int) $pdo->lastInsertId();

                $paidRateForLine = $paidAmt > 0 ? round($paidAmtInput / $paidAmt, 6) : 1;

                // مدين: حساب التحصيل (صندوق/بنك، أو حساب دفعة العميل
                // المقدمة — بدون أي فرق بمعالجتهم، تحكّم يدوي كامل من
                // المستخدم بأي حساب يختار)
                $pdo->prepare("INSERT INTO `{$TJI}`
                    (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                    VALUES (?,?,?,0,?,?,?,?,?)")
                    ->execute([
                        $jePayId,
                        $cashAccountId,
                        $paidAmt,
                        $paidAmtInput,
                        $paidAmt,
                        'تحصيل جزئي',
                        $curId($paidCur),
                        $paidRateForLine
                    ]);
                $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                    ->execute([$paidAmt, $paidAmtInput, $cashAccountId]);

                // دائن: ذمم العملاء (تخفيض — العميل دفع)
                $pdo->prepare("INSERT INTO `{$TJI}`
                    (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                    VALUES (?,?,0,?,?,?,?,?,?)")
                    ->execute([
                        $jePayId,
                        $accCustomer['id'],
                        $paidAmt,
                        $paidAmtInput,
                        $paidAmt,
                        'تحصيل من ' . $inv['customer_name'],
                        $curId($paidCur),
                        $paidRateForLine
                    ]);
                $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                    ->execute([$paidAmt, $paidAmt, $accCustomer['id']]);
            }

            // ── خصم تعجيل الاستلام — تحقق سيادي بالسيرفر حصراً (تاريخ +
            // تسديد كامل)، مو مجرد قيمة جاية من الواجهة ──
            $settleDiscApplied = 0;
            $settlePct = (float) ($inv['settlement_discount_pct'] ?? 0);
            if ($settlePct > 0 && $inv['due_date']) {
                $today = date('Y-m-d');
                $stillEligible = $today <= $inv['due_date'];
                $settleDiscFullBase = $finalBase * $settlePct / 100;
                $fullyPaidWithDiscount = $paidAmt > 0 && abs(($paidAmt + $settleDiscFullBase) - $finalBase) <= 0.01;

                if ($stillEligible && $fullyPaidWithDiscount) {
                    $accSettleDisc = $getAcc('settlement_discount_expense');
                    if ($accSettleDisc) {
                        $seq++;
                        $jeNo5 = 'JE-' . $y . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
                        $pdo->prepare("INSERT INTO `{$TJE}`
                            (entry_number,entry_date,description,currency_id,exchange_rate,
                             total_debit,total_credit,status,reference_type,reference_id,created_by)
                            VALUES (?,?,?,?,1,?,?,'posted','sale_settlement_discount',?,?)")
                            ->execute([
                                $jeNo5,
                                date('Y-m-d'),
                                "خصم تعجيل دفع {$inv['invoice_number']}",
                                $baseCurrencyId,
                                $settleDiscFullBase,
                                $settleDiscFullBase,
                                $invId,
                                $_SESSION['user_id']
                            ]);
                        $jeSettleId = (int) $pdo->lastInsertId();

                        $settleDiscOrig = round($settleDiscFullBase * $rate, 2);
                        // مدين: مصروف خصم تعجيل الاستلام (نحن يلي منمنحه للعميل — عكس المشتريات)
                        $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                            VALUES (?,?,?,0,?,?,'خصم تعجيل دفع',?,?)")
                            ->execute([$jeSettleId, $accSettleDisc['id'], $settleDiscFullBase, $settleDiscOrig, $settleDiscFullBase, $docCurrencyId, $rate]);
                        $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                            ->execute([$settleDiscFullBase, $settleDiscFullBase, $accSettleDisc['id']]);

                        // دائن: ذمم العملاء (تخفيض إضافي)
                        $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                            VALUES (?,?,0,?,?,?,'خصم تعجيل دفع',?,?)")
                            ->execute([$jeSettleId, $accCustomer['id'], $settleDiscFullBase, $settleDiscOrig, $settleDiscFullBase, $docCurrencyId, $rate]);
                        $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                            ->execute([$settleDiscFullBase, $settleDiscFullBase, $accCustomer['id']]);

                        $settleDiscApplied = $settleDiscFullBase;
                    }
                }
            }

            // ── تحديث الفاتورة ──
            $totalPaidIncDisc = $paidAmt + $settleDiscApplied; // عملة الفرع
            $exactSettle = !empty($_POST['exact_settle']);
            if ($exactSettle && abs($totalPaidIncDisc - $finalBase) <= 0.01 && $totalPaidIncDisc != $finalBase) {
                $totalPaidIncDisc = $finalBase;
            }
            $finalPaidStatus = $totalPaidIncDisc >= $finalBase ? 'paid'
                : ($totalPaidIncDisc > 0 ? 'partial' : 'pending');
            // paid_amount/balance_amount بجدول sales_invoices بعملة الفاتورة
            $totalPaidInvCur = $rate > 0 ? round($totalPaidIncDisc * $rate, 4) : $totalPaidIncDisc;

            $notes = trim($_POST['notes'] ?? '');
            $pdo->prepare("UPDATE `{$TI}` SET
                status='confirmed',
                paid_amount=?,
                balance_amount=final_amount-?,
                payment_status=?,
                journal_entry_id=?,
                notes=CONCAT(COALESCE(notes,''),?),
                updated_by=?
                WHERE id=?")
                ->execute([
                    $totalPaidInvCur,
                    $totalPaidInvCur,
                    $finalPaidStatus,
                    $jeId,
                    $notes ? ' | ' . $notes : '',
                    $_SESSION['user_id'],
                    $invId
                ]);

            $pdo->commit();
            ob_get_clean();
            $msg = 'تم تأكيد الفاتورة وخصم المخزون والقيد المحاسبي';
            echo json_encode([
                'ok' => true,
                'msg' => $msg,
                'je_no' => $jeNo,
                'movement' => $movNo,
                'status' => 'confirmed',
                'paid' => $paidAmt,
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ════════════════════════════════════════════════════════════
    // إلغاء الفاتورة
    // ════════════════════════════════════════════════════════════
    elseif ($act === 'cancel') {
        $invId = (int) ($_POST['invoice_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        $stInv = $pdo->prepare("SELECT * FROM `{$TI}` WHERE id=?");
        $stInv->execute([$invId]);
        $inv = $stInv->fetch(PDO::FETCH_ASSOC);
        if (!$inv)
            throw new Exception('الفاتورة غير موجودة');
        if ($inv['status'] === 'cancelled')
            throw new Exception('ملغاة مسبقاً');

        $pdo->beginTransaction();
        try {
            // إعادة المخزون إذا كانت مؤكدة
            if ($inv['status'] === 'confirmed') {
                // ⚠ إصلاح: كان يقرا s.cost_price (ثابت، قد يكون تغيّر
                // كلياً من وقت البيع الأصلي). للإلغاء الصحيح محاسبياً،
                // لازم نعكس بالضبط نفس القيمة يلي اتسجّلت وقتها فعلياً —
                // مش قيمة "حالية" جديدة قد تكون مختلفة. نقرأها من سجل
                // الحركة الأصلي لهذه الفاتورة بالذات.
                $origCostMap = [];
                $stOrigCost = $pdo->prepare("SELECT imd.variant_id, imd.cost_price
                    FROM `inventory_movement_details_{$TS}` imd
                    JOIN `inventory_movements_{$TS}` im ON im.id = imd.movement_id
                    WHERE im.reference_type = 'sale' AND im.reference_id = ?");
                $stOrigCost->execute([$invId]);
                foreach ($stOrigCost->fetchAll(PDO::FETCH_ASSOC) as $oc) {
                    $origCostMap[$oc['variant_id']] = (float) $oc['cost_price'];
                }

                $stItems = $pdo->prepare("SELECT ii.*, p.name AS product_name, s.cost_price AS fallback_cost_price
                    FROM `{$TII}` ii
                    LEFT JOIN `products_{$TS}` p ON p.id=ii.product_id
                    LEFT JOIN `product_variants_{$TS}` v ON v.id=ii.variant_id
                    LEFT JOIN `product_sizes_{$TS}` s ON s.id=v.size_id
                    WHERE ii.invoice_id=?");
                $stItems->execute([$invId]);
                $cancelItems = $stItems->fetchAll(PDO::FETCH_ASSOC);
                foreach ($cancelItems as &$ci) {
                    $ci['cost_price'] = $origCostMap[$ci['variant_id']] ?? (float) $ci['fallback_cost_price'];
                }
                unset($ci);
                $wid = (int) ($inv['warehouse_id'] ?? 0);
                // ⚠ إضافة: $rate ما كانت معرَّفة بهذا النطاق إطلاقاً — لازمة
                // لتحويل unit_price لعملة الفرع (نفس مبدأ المسار الرئيسي)
                $rate = (float) ($inv['exchange_rate'] ?: 1);
                if ($rate <= 0) $rate = 1;

                // ⚠ نفس فجوة كانت موجودة بالتأكيد نفسه — حركة الإلغاء
                // (إعادة المخزون) كانت بتحدّث warehouse_items مباشرة
                // بلا أي سجل تدقيق. صار عندنا سجل حركة كامل هلق.
                $movDetailsCancel = [];
                foreach ($cancelItems as $item) {
                    if (!$item['variant_id'])
                        continue;
                    $stB = $pdo->prepare("SELECT quantity FROM `{$TWI}` WHERE variant_id=? AND warehouse_id=?");
                    $stB->execute([$item['variant_id'], $wid]);
                    $balBefore = (float) ($stB->fetchColumn() ?: 0);

                    $pdo->prepare("UPDATE `{$TWI}` SET quantity=quantity+?, last_movement_at=NOW()
                        WHERE variant_id=? AND warehouse_id=?")
                        ->execute([$item['quantity'], $item['variant_id'], $wid]);

                    $movDetailsCancel[] = [
                        'variant_id' => $item['variant_id'],
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => (float) ($item['unit_price_base_currency'] ?? ($item['unit_price'] / $rate)), // ⚠ نفس الإصلاح — بعملة الفرع
                        'cost_price' => (float) ($item['cost_price'] ?? 0),
                        'total_value' => (float) ($item['cost_price'] ?? 0) * (float) $item['quantity'],
                        'balance_before' => $balBefore,
                        'balance_after' => $balBefore + (float) $item['quantity'],
                    ];
                }
                if (!empty($movDetailsCancel)) {
                    $movNoCancel = 'MOV-IN-CNL-' . date('Ymd') . '-' . str_pad($invId, 5, '0', STR_PAD_LEFT);
                    $pdo->prepare("INSERT INTO `inventory_movements_{$TS}`
                        (movement_number, movement_type, warehouse_id, items_count, total_quantity,
                         total_value_base, reference_type, reference_id, reference_number, notes, created_by)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([
                            $movNoCancel,
                            'in',
                            $wid,
                            count($movDetailsCancel),
                            array_sum(array_column($movDetailsCancel, 'quantity')),
                            array_sum(array_column($movDetailsCancel, 'total_value')),
                            'sale',
                            $invId,
                            $inv['invoice_number'],
                            "إلغاء فاتورة بيع {$inv['invoice_number']} — إعادة مخزون" . ($reason ? " ({$reason})" : ''),
                            $_SESSION['user_id']
                        ]);
                    $movIdCancel = (int) $pdo->lastInsertId();
                    foreach ($movDetailsCancel as $md) {
                        $pdo->prepare("INSERT INTO `inventory_movement_details_{$TS}`
                            (movement_id, variant_id, product_id, quantity, unit_price, cost_price,
                             total_value, balance_before, balance_after)
                            VALUES (?,?,?,?,?,?,?,?,?)")
                            ->execute([
                                $movIdCancel,
                                $md['variant_id'],
                                $md['product_id'],
                                $md['quantity'],
                                $md['unit_price'],
                                $md['cost_price'],
                                $md['total_value'],
                                $md['balance_before'],
                                $md['balance_after']
                            ]);
                    }
                }
                // ⚠ نفس اقتصار المشتريات بالضبط: بيعكس بس القيد الرئيسي
                // (journal_entry_id المخزَّن على الفاتورة نفسها) — القيود
                // المنفصلة (توصيل/تحصيل/خصم تعجيل) ما بتنعكس تلقائياً
                // هون. قيد موروث من نفس منطق المشتريات، مو باگ جديد.
                if ($inv['journal_entry_id']) {
                    $stJI = $pdo->prepare("SELECT * FROM `{$TJI}` WHERE journal_entry_id=?");
                    $stJI->execute([$inv['journal_entry_id']]);
                    foreach ($stJI->fetchAll(PDO::FETCH_ASSOC) as $ji) {
                        // ⚠ إصلاح: بعد قرار "عملة الفرع بالهيدر"، القيد
                        // الرئيسي هون بيلمس بس حسابات غير نقدية (ذمة
                        // العميل/الإيراد/الضريبة/الخصم) — وكلهم صاروا
                        // balance == base_balance بالضبط (راجع تصحيح
                        // القيد الرئيسي أعلاه). $net (بعملة الفرع) كافٍ
                        // وحده للاثنين، مطابق تماماً لنمط عكس الإلغاء
                        // بـconfirm_purchase_invoice.php.
                        $net = $ji['debit'] - $ji['credit'];
                        $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                            ->execute([$net, $net, $ji['account_id']]);
                    }
                    $pdo->prepare("UPDATE `{$TJE}` SET status='cancelled',cancelled_at=NOW(),cancelled_by=? WHERE id=?")
                        ->execute([$_SESSION['user_id'], $inv['journal_entry_id']]);
                }
            }
            $pdo->prepare("UPDATE `{$TI}` SET status='cancelled',
                notes=CONCAT(COALESCE(notes,''),' | إلغاء: {$reason}'),
                updated_by=? WHERE id=?")
                ->execute([$_SESSION['user_id'], $invId]);
            $pdo->commit();
            ob_get_clean();
            echo json_encode(['ok' => true, 'msg' => 'تم إلغاء الفاتورة وعكس جميع التأثيرات']);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    } else
        throw new Exception('إجراء غير معروف');

} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(200);
    echo json_encode(['ok' => false, 'msg' => $e->getMessage(), 'line' => $e->getLine()]);
}
