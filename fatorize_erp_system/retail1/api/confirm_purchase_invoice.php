<?php
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
    requirePermission('purchases.invoices', 'confirm');

    $TS = $_SESSION['table_suffix'];
    $TP = "purchases_{$TS}";
    $TPI = "purchase_items_{$TS}";
    $TWI = "warehouse_items_{$TS}";
    $TIM = "inventory_movements_{$TS}";
    $TIMD = "inventory_movement_details_{$TS}";
    $TAC = "account_charts_{$TS}";
    $TJE = "journal_entries_{$TS}";
    $TJI = "journal_entry_items_{$TS}";
    $TIAS = "invoice_account_settings_{$TS}";
    $TSP = "product_suppliers_{$TS}";

    // ⚠ عملة الفرع الأساسية الحقيقية — تُستخدم بدل 'USD' المكتوبة حرفياً
    // بعدة أماكن أدناه (قيود لا تنطوي فعلياً على تحويل عملة حقيقي، مجرد
    // إعادة تخصيص محاسبية بعملة الفرع نفسها).
    // ⚠ branches.base_currency (نص مباشر) عمود قديم/تالف — العملة الحقيقية
    // تُقرأ عبر base_currency_id (FK) بجدول currencies، نفس الإصلاح
    // الموثّق أصلاً بـ README (فقرة "branches.base_currency data corruption fix").
    $branchBaseCurrency = 'USD';
    $costingMethod = 'last_cost';
    if (!empty($_SESSION['branch_id'])) {
        $bcStmt = $pdo->prepare("SELECT c.code, b.costing_method FROM branches b
            JOIN currencies c ON c.id = b.base_currency_id WHERE b.id = ?");
        $bcStmt->execute([$_SESSION['branch_id']]);
        $bcRow = $bcStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $branchBaseCurrency = $bcRow['code'] ?? 'USD';
        $costingMethod = $bcRow['costing_method'] ?? 'last_cost';
    }

    // ⚠ journal_entries_{TS}/journal_entry_items_{TS} تستخدمان currency_id
    // (FK رقمي، NOT NULL) لا عمود currency نصي — نفس اتفاقية purchases_{TS}
    // بالضبط. هالدالة بتحوّل أي رمز عملة (كود) لمعرّفه الرقمي مع تخزين
    // مؤقت (cache) بسيط بذاكرة الطلب حتى ما نكرر نفس الاستعلام.
    static $curIdCache = [];
    $curId = function (string $code) use ($pdo, &$curIdCache): int {
        if (isset($curIdCache[$code]))
            return $curIdCache[$code];
        $st = $pdo->prepare("SELECT id FROM currencies WHERE code=? LIMIT 1");
        $st->execute([$code]);
        $id = (int) ($st->fetchColumn() ?: 0);
        if (!$id)
            throw new Exception("عملة غير معروفة بجدول العملات: {$code}");
        return $curIdCache[$code] = $id;
    };

    // ⚠ إغلاق فجوة موثّقة سابقاً: تكاليف الشحن بعملة غير عملة الفرع
    // الأساسية كانت تُسجَّل بدون تحويل حقيقي (كأنها 1:1). نفس معادلة
    // get_currency_rate بـ index.php: exchange_rate بجدول currencies =
    // "1 مرجع عالمي = X هالعملة"، فالتحويل من عملة A لعملة B = مبلغ×(rate_B/rate_A).
    $rateCache = [];
    $getCurRate = function (string $code) use ($pdo, &$rateCache): float {
        if (isset($rateCache[$code])) return $rateCache[$code];
        $st = $pdo->prepare("SELECT exchange_rate FROM currencies WHERE code=? LIMIT 1");
        $st->execute([$code]);
        $r = (float) ($st->fetchColumn() ?: 1);
        return $rateCache[$code] = ($r > 0 ? $r : 1);
    };
    $convertToBase = function (float $amount, string $fromCur) use ($getCurRate, $branchBaseCurrency): float {
        if ($fromCur === $branchBaseCurrency) return $amount;
        return round($amount / $getCurRate($fromCur) * $getCurRate($branchBaseCurrency), 4);
    };

    // ⚠ تحويل مبلغ الشحن تحديداً — أولوية مختلفة عن convertToBase العامة:
    // (1) عملة الشحن = عملة الفاتورة → نستخدم exchange_rate المسجَّل على
    //     الفاتورة نفسها (معروف ومقفل تاريخياً، أدق من جدول currencies).
    // (2) عملة الشحن = عملة الفرع → 1:1 بديهياً.
    // (3) عملة ثالثة (غير الفاتورة وغير الفرع) → سعر صرف يدوي مُرسَل من
    //     الواجهة (shipping_exchange_rate)، لأنه جدول currencies بيعطي
    //     "آخر سعر معروف" مش بالضرورة السعر الفعلي وقت هالشحنة بالذات.
    $convertShippingToBase = function (float $amount, string $shipCur, string $invCur, float $invRate) use ($branchBaseCurrency): float {
        if ($shipCur === $branchBaseCurrency) return $amount;
        if ($shipCur === $invCur) return round($amount / $invRate, 4);
        $manualRate = (float) ($_POST['shipping_exchange_rate'] ?? 0);
        if ($manualRate <= 0) throw new Exception('سعر صرف الشحن مطلوب — عملة الشحن مختلفة عن الفاتورة والفرع');
        return round($amount / $manualRate, 4);
    };

    $act = $_POST['_action'] ?? '';

    // ── تأكيد فاتورة الشراء ──
    if ($act === 'confirm') {
        $purId = (int) ($_POST['invoice_id'] ?? 0);
        if (!$purId)
            throw new Exception('رقم الفاتورة مطلوب');

        // جلب الفاتورة
        // ⚠ إصلاح جذري: purchases_ret عندها عمود نصي قديم متروك اسمه
        // `currency` (نفس فئة branches.base_currency القديمة) — غير
        // محدَّث وممكن يحمل قيمة قديمة خاطئة. عملة الفاتورة الحقيقية
        // تُقرأ حصراً عبر invoice_currency_id (FK) بجوين على currencies.
        $stPur = $pdo->prepare("SELECT p.*, s.name AS supplier_name, c.code AS real_currency_code
            FROM `{$TP}` p
            LEFT JOIN product_suppliers_{$TS} s ON s.id=p.supplier_id
            LEFT JOIN currencies c ON c.id = p.invoice_currency_id
            WHERE p.id=?");
        $stPur->execute([$purId]);
        $pur = $stPur->fetch(PDO::FETCH_ASSOC);
        if (!$pur)
            throw new Exception('الفاتورة غير موجودة');
        if ($pur['status'] !== 'draft')
            throw new Exception('يمكن تأكيد المسودات فقط (الحالة: ' . $pur['status'] . ')');

        // جلب البنود
        $stItems = $pdo->prepare("SELECT * FROM `{$TPI}` WHERE purchase_id=?");
        $stItems->execute([$purId]);
        $items = $stItems->fetchAll(PDO::FETCH_ASSOC);
        if (empty($items))
            throw new Exception('الفاتورة لا تحتوي بنوداً');

        $pdo->beginTransaction();
        try {
            $finalBase = (float) ($pur['final_amount_base_currency'] ?? $pur['final_amount']);
            $rate = (float) ($pur['exchange_rate'] ?? 1);
            // ⚠ لا نقرأ $pur['currency'] (العمود القديم المتروك) — العملة
            // الحقيقية من الجوين الجديد فوق (real_currency_code)
            $curCode = $pur['real_currency_code'] ?? 'USD';
            $supName = $pur['supplier_name'] ?? 'مورد';
            $receiveDate = $_POST['receive_date'] ?? date('Y-m-d');
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0) ?: null;
            $shippingCost = (float) ($_POST['shipping_cost'] ?? 0);
            $shippingCur = $_POST['shipping_currency'] ?? $curCode;
            $shippingDesc = trim($_POST['shipping_desc'] ?? '');
            $shippingCarrierId = (int) ($_POST['shipping_carrier_id'] ?? 0) ?: null;
            $shippingPayMethod = $_POST['shipping_pay_method'] ?? 'cash'; // cash | credit
            $shippingCashAccId = (int) ($_POST['shipping_cash_account'] ?? 0) ?: null;
            $shippingPayableId = (int) ($_POST['shipping_payable_id'] ?? 0) ?: null;
            $paidOrigAmt = (float) ($_POST['paid_amount'] ?? 0);
            $paidCur = $_POST['paid_currency'] ?? $curCode;
            // ⚠ تبسيط جذري (يوليو ٢٠٢٦): عملة الدفع بالواجهة محصورة بخيارين
            // معروفين بس (عملة الفاتورة، أو عملة الفرع) — فما عاد داعي
            // نعتمد على أي "سعر صرف" مُرسَل من الواجهة لحساب المبلغ
            // المحاسبي الفعلي (`$paidRate` كان عرضة لعكس الاتجاه بالغلط
            // أكتر من مرة). الحساب هلق مستقل ١٠٠٪ عن أي قيمة قادمة من
            // الواجهة، معتمد بس على $rate الفاتورة نفسه (ثابت وموثوق):
            //   - لو الدفع بعملة الفرع نفسها → المبلغ الأساسي = المبلغ
            //     المدخل مباشرة (بديهي، بدون أي تحويل).
            //   - لو الدفع بعملة الفاتورة → نحوّل عبر $rate (نفس اتفاقية
            //     invoice_new.php: $rate = كم وحدة عملة فاتورة تساوي 1
            //     وحدة عملة فرع).
            if ($paidCur === $branchBaseCurrency) {
                $paidAmt = round($paidOrigAmt, 4);
            } elseif ($paidCur === $curCode) {
                $paidAmt = round($paidOrigAmt / $rate, 4);
            } else {
                // احتياط دفاعي فقط — الواجهة المقيَّدة ما لازم توصل هون أصلاً
                throw new Exception('عملة دفع غير مدعومة بمودال التأكيد — يجب أن تطابق عملة الفاتورة أو عملة الفرع');
            }
            $cashAccId = (int) ($_POST['cash_account_id'] ?? 0) ?: null;
            $notes = trim($_POST['notes'] ?? '');

            // رفع صورة الفاتورة
            $imgPath = null;
            if (!empty($_FILES['invoice_image']['tmp_name'])) {
                $uploadDir = __DIR__ . '/../../uploads/purchase_invoices/';
                if (!is_dir($uploadDir))
                    mkdir($uploadDir, 0755, true);
                $ext = pathinfo($_FILES['invoice_image']['name'], PATHINFO_EXTENSION);
                $imgName = 'PUR-' . $purId . '-' . time() . '.' . $ext;
                move_uploaded_file($_FILES['invoice_image']['tmp_name'], $uploadDir . $imgName);
                $imgPath = 'uploads/purchase_invoices/' . $imgName;
            }

            // ── إضافة للمخزون ──
            $movNo = 'MOV-IN-' . date('Ymd') . '-' . str_pad($purId, 5, '0', STR_PAD_LEFT);
            $totalQty = array_sum(array_column($items, 'quantity'));

            $pdo->prepare("INSERT INTO `{$TIM}`
                (movement_number,movement_type,warehouse_id,items_count,total_quantity,
                 total_value_base,reference_type,reference_id,reference_number,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $movNo,
                    'in',
                    $warehouseId ?: ($pur['warehouse_id'] ?? null),
                    count($items),
                    $totalQty,
                    $finalBase,
                    'purchase',
                    $purId,
                    $pur['purchase_number'],
                    $_SESSION['user_id']
                ]);
            $movId = (int) $pdo->lastInsertId();

            foreach ($items as $item) {
                if (!$item['variant_id'])
                    continue;
                // ⚠ المستودع صار مستوى فاتورة واحد لكامل المستند (قرار
                // معماري سابق موثّق بـ get_purchase بملف index.php) — لا
                // نقرأه من purchase_items_{TS}.warehouse_id (عمود قديم غير
                // مُحدَّث فعلياً بالفواتير الحالية، وكان بيرجّع 0 ويكسر
                // FOREIGN KEY على warehouse_items_{TS}).
                $wid = $warehouseId ?: (int) ($pur['warehouse_id'] ?? 0);
                if (!$wid) {
                    throw new Exception('لا يوجد مستودع محدَّد لهذه الفاتورة — لا يمكن إضافة المخزون بدونه');
                }
                $qty = (float) $item['quantity'];
                // ⚠ إصلاح مزدوج (راجع confirm_invoices_changes_summary.md
                // + محادثتنا حول خصم السطر الخاص):
                // ١) item['unit_price'] خام (قبل خصم السطر الخاص بهالبند
                //    تحديداً) — السعر الصافي الفعلي المدفوع لازم يُشتق من
                //    total_price (المخزَّن أصلاً بعد الخصم) ÷ الكمية، لا
                //    من unit_price الخام مباشرة. وإلا current_cost
                //    (والمتوسط المرجّح) بيطلعوا أعلى من التكلفة الحقيقية
                //    لأي بند فيه خصم سطر.
                // ٢) القيمة المخزَّنة بعمود unit_price بجدول تفاصيل الحركة
                //    لازم تكون بعملة الفرع دايماً (نفس $unitBase المستخدم
                //    بعمود cost_price أصلاً) — لا بعملة الفاتورة الخام،
                //    لأنه دفتر الحركات الموحَّد (تقارير/movements.php)
                //    مفروض كل أرقامه بعملة واحدة موحَّدة بلا استثناء.
                // ⚠ إصلاح إضافي (بعد قرار invoice_new.php/invoice_edit.php:
                // "جدول البنود = عملة الفرع فقط"): total_price بجدول
                // purchase_items أصلاً بعملة الفرع دايماً (لا عملة الفاتورة
                // كما كان مفترَضاً هون سابقاً) — القسمة على $rate كانت
                // تحويل مزدوج غلط، بتفسد current_cost فعلياً لأي فاتورة
                // بعملة غير عملة الفرع (اكتُشف بمراجعة فاتورة تجريبية
                // بعملة TRY). unit_price_base_currency بالفولباك كمان
                // أصلاً بعملة الفرع مباشرة، بلا أي قسمة.
                $unitBase = $qty > 0
                    ? ((float) $item['total_price'] / $qty)
                    : (float) ($item['unit_price_base_currency'] ?? $item['unit_price']);

                // جلب الكمية والتكلفة الحالية قبل هالعملية
                $stB = $pdo->prepare("SELECT quantity, current_cost FROM `{$TWI}`
                    WHERE variant_id=? AND warehouse_id=?");
                $stB->execute([$item['variant_id'], $wid]);
                $curRow = $stB->fetch(PDO::FETCH_ASSOC);
                $before = (float) ($curRow['quantity'] ?? 0);
                $oldCost = (float) ($curRow['current_cost'] ?? 0);
                $after = $before + $qty;

                // ⚠ التكلفة الحالية الجديدة — حسب طريقة الحساب المختارة
                // بإعدادات الفرع (costing_method): آخر سعر شراء أو متوسط
                // مرجّح بالكمية. هاد الرقم هو يلي بيُقرأ لاحقاً وقت البيع
                // الفعلي (بدل product_sizes.cost_price الثابت القديم).
                if ($costingMethod === 'weighted_average' && ($before + $qty) > 0) {
                    $newCost = (($before * $oldCost) + ($qty * $unitBase)) / ($before + $qty);
                } else {
                    $newCost = $unitBase; // last_cost (افتراضي)
                }

                // UPSERT في warehouse_items
                $pdo->prepare("INSERT INTO `{$TWI}`
                    (warehouse_id,variant_id,product_id,quantity,current_cost,last_movement_at)
                    VALUES (?,?,?,?,?,NOW())
                    ON DUPLICATE KEY UPDATE
                    quantity=quantity+?,current_cost=?,last_movement_at=NOW()")
                    ->execute([$wid, $item['variant_id'], $item['product_id'] ?? 0, $qty, $newCost, $qty, $newCost]);

                // تفاصيل الحركة
                $pdo->prepare("INSERT INTO `{$TIMD}`
                    (movement_id,variant_id,product_id,quantity,unit_price,cost_price,
                     total_value,balance_before,balance_after)
                    VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([
                        $movId,
                        $item['variant_id'],
                        $item['product_id'] ?? 0,
                        $qty,
                        $unitBase,
                        $unitBase,
                        $unitBase * $qty,
                        $before,
                        $after
                    ]);


                if ($item['unit_price_base_currency'] === null) {
                    // ⚠ هون لازم السعر الخام (غير المخصوم)، مطابق لعمود
                    // العرض الأصلي unit_price_base_currency — لا $unitBase
                    // (الصافي، خاص بتقييم المخزون بس أعلاه).
                    $grossUnitBase = (float) ($item['unit_price'] / $rate);
                    $pdo->prepare("UPDATE `{$TPI}` SET unit_price_base_currency=? WHERE id=?")
                        ->execute([$grossUnitBase, $item['id']]);
                }
            }

            // ── جلب حسابات الربط ──
            $getAcc = function (string $key) use ($pdo, $TIAS, $TAC): ?array {
                $st = $pdo->prepare("SELECT ac.* FROM `{$TIAS}` i
                    JOIN `{$TAC}` ac ON ac.id=i.account_id
                    WHERE i.setting_key=? LIMIT 1");
                $st->execute([$key]);
                return $st->fetch(PDO::FETCH_ASSOC) ?: null;
            };

            // حساب ذمة المورد: يُفضَّل الحساب الخاص بالمورد إن وُجد، وإلا الحساب العام
            $accSupplier = null;
            if (!empty($pur['supplier_id'])) {
                $stSupAcc = $pdo->prepare("SELECT ac.* FROM `{$TSP}` s
                    JOIN `{$TAC}` ac ON ac.id=s.account_id
                    WHERE s.id=? AND s.account_id IS NOT NULL LIMIT 1");
                $stSupAcc->execute([$pur['supplier_id']]);
                $accSupplier = $stSupAcc->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$accSupplier)
                $accSupplier = $getAcc('supplier_payable');

            $accInventory = $getAcc('finished_inventory');

            if (!$accSupplier || !$accInventory)
                throw new Exception('يرجى ضبط حساب ذمة للمورد أو إعدادات الربط المحاسبي العامة (ذمم الموردين + المخزون)');

            // ── قيد محاسبي: مدين المخزون / دائن ذمم الموردين ──
            $y = date('Y');
            // دالة مساعدة: تولّد رقم القيد التالي الفعلي في كل استدعاء (تتجنب التعارض)
            $nextJeNo = function () use ($pdo, $TJE, $y): string {
                $last = $pdo->query("SELECT entry_number FROM `{$TJE}`
                    WHERE entry_number LIKE 'JE-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
                $seq = $last ? (int) substr($last, -4) + 1 : 1;
                return 'JE-' . $y . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
            };
            $jeNo = $nextJeNo();

            $pdo->prepare("INSERT INTO `{$TJE}`
                (entry_number,entry_date,description,currency_id,exchange_rate,
                 total_debit,total_credit,status,reference_type,reference_id,created_by)
                VALUES (?,?,?,?,1,?,?,'posted','purchase',?,?)")
                ->execute([
                    $jeNo,
                    date('Y-m-d'),
                    "شراء فاتورة {$pur['purchase_number']} — {$supName}",
                    // ⚠ تصحيح: total_debit/total_credit تحت فعلياً بعملة
                    // الفرع الأساسية ($finalBase) — مو بعملة الفاتورة. رأس
                    // القيد لازم يتطابق مع العملة الحقيقية لمبالغه (نفس
                    // فئة الباگ يلي ظهر بمودال "تفاصيل التأكيد" — طلعت
                    // TRY 59.7 بينما الرقم فعلياً USD). مستوى السطور تحت
                    // (JI) ضلت currency_id=عملة الفاتورة عمداً — هناك صحيحة
                    // فعلاً لأنها مقترنة بـoriginal_amount (بعملة الفاتورة).
                    $curId($branchBaseCurrency),
                    $finalBase,
                    $finalBase,
                    $purId,
                    $_SESSION['user_id']
                ]);
            $jeId = (int) $pdo->lastInsertId();

            // ⚠ القيمة الحقيقية بعملة الفاتورة — مُشتقة، لا مقروءة من
            // final_amount مباشرة. final_amount صار (بعد قرار invoice_new.
            // php: "عملة الهيدر توثيقية بس") نسخة طبق الأصل من
            // final_amount_base_currency، فما عاد يمثّل قيمة حقيقية بعملة
            // الفاتورة. exchange_rate مجمَّد على المستند نفسه (بتاريخه)،
            // فالاشتقاق هون دقيق ١٠٠٪ بدون أي تخزين مكرَّر لنفس المعلومة —
            // القرار المحاسبي الصريح: لا نخزّن قيمة مشتقة رياضياً كحقيقة
            // مستقلة، نحسبها وقت الحاجة.
            $finalOrig = round($finalBase * $rate, 4);

            // مدين: المخزون
            $pdo->prepare("INSERT INTO `{$TJI}`
                (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                VALUES (?,?,?,0,?,?,?,?,?)")
                ->execute([
                    $jeId,
                    $accInventory['id'],
                    $finalBase,
                    $finalOrig,
                    $finalBase,
                    "مخزون {$pur['purchase_number']}",
                    $curId($curCode),
                    $rate
                ]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                ->execute([$finalBase, $finalBase, $accInventory['id']]);

            // دائن: ذمم الموردين
            // ⚠ إصلاح إشارة حرج (بعد تحقيق مع المستخدم بمثال حقيقي): حساب
            // "ذمة المورد" التزام (Liability) — رصيده الطبيعي دائن، يعني
            // بالمعيار المحاسبي القياسي: دائن يزيد الرصيد، مدين ينقصه.
            // الكود القديم كان يطبّق نفس معادلة "مدين+/دائن-" الموحَّدة
            // على كل الحسابات بلا استثناء (صحيحة للأصول متل المخزون فوق،
            // غلط لحسابات الالتزام) — فكانت النتيجة معكوسة: رصيد سالب كل
            // ما زاد الدَين الفعلي، بينما ينتظر المستخدم موجب = عليه دين
            // (نفس قراءة أي كشف حساب تقليدي). ⚠ الأرصدة القديمة المخزَّنة
            // قبل هالإصلاح ضلّت بالإشارة المعكوسة عمداً (قرار صريح: نصلّح
            // الكود بس هلق، الأرصدة القديمة تُصحَّح لاحقاً بميغريشن منفصل).
            $pdo->prepare("INSERT INTO `{$TJI}`
                (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                VALUES (?,?,0,?,?,?,?,?,?)")
                ->execute([
                    $jeId,
                    $accSupplier['id'],
                    $finalBase,
                    $finalOrig,
                    $finalBase,
                    "ذمة {$supName}",
                    $curId($curCode),
                    $rate
                ]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                ->execute([$finalBase, $finalBase, $accSupplier['id']]);

            // ── الدفعة المقدمة صارت تُطبَّق يدوياً حصراً من الواجهة ──
            // ⚠ عُطِّل التطبيق التلقائي هون بقرار صريح (يوليو ٢٠٢٦): المستخدم
            // هلق يقدر يختار حساب الدفعة المقدمة تبع المورد بنفسه من قائمة
            // "حساب الدفع" بمودال التأكيد (بنفس مسار cash_account_id تحت)،
            // مع عرض الرصيد المتاح والتحقق منه بالواجهة قبل الإرسال. لو
            // بقي هالتطبيق التلقائي شغّال بالتوازي، كان ممكن يصير خصم
            // مزدوج من نفس حساب المورد (مرة تلقائي هون، ومرة يدوي تحت) —
            // الكود الأصلي محفوظ بالتعليق تحت للمرجعية لو احتجنا نرجعه.
            $advanceApplied = 0;
            /*
            if (!empty($pur['supplier_id'])) {
                $stPrepaid = $pdo->prepare("SELECT ac.* FROM `{$TSP}` s
                    JOIN `{$TAC}` ac ON ac.id=s.prepaid_account_id
                    WHERE s.id=? AND s.prepaid_account_id IS NOT NULL LIMIT 1");
                $stPrepaid->execute([$pur['supplier_id']]);
                $accPrepaid = $stPrepaid->fetch(PDO::FETCH_ASSOC);

                if ($accPrepaid && (float) $accPrepaid['base_balance'] > 0) {
                    $advanceBalance = (float) $accPrepaid['base_balance'];
                    $advanceApplied = min($advanceBalance, $finalBase);

                    if ($advanceApplied > 0) {
                        $jeAdvNo = $nextJeNo();
                        $pdo->prepare("INSERT INTO `{$TJE}`
                            (entry_number,entry_date,description,currency,exchange_rate,
                             total_debit,total_credit,status,reference_type,reference_id,created_by)
                            VALUES (?,?,?,?,1,?,?,'posted','purchase_advance_applied',?,?)")
                            ->execute([
                                $jeAdvNo,
                                date('Y-m-d'),
                                "تطبيق دفعة مقدمة على فاتورة {$pur['purchase_number']} — {$supName}",
                                $branchBaseCurrency,
                                $advanceApplied,
                                $advanceApplied,
                                $purId,
                                $_SESSION['user_id']
                            ]);
                        $jeAdvId = (int) $pdo->lastInsertId();

                        // مدين: ذمم الموردين (تقليل الذمة) — ⚠ نفس إصلاح
                        // الإشارة أعلاه: مدين على التزام = ينقص الرصيد
                        $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency,exchange_rate)
                            VALUES (?,?,?,0,?,?,'تطبيق دفعة مقدمة',?,1)")
                            ->execute([$jeAdvId, $accSupplier['id'], $advanceApplied, $advanceApplied, $advanceApplied, $branchBaseCurrency]);
                        $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                            ->execute([$advanceApplied, $advanceApplied, $accSupplier['id']]);

                        // دائن: الدفعات المقدمة (تصفير الرصيد المستخدم)
                        $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency,exchange_rate)
                            VALUES (?,?,0,?,?,?,'تطبيق دفعة مقدمة',?,1)")
                            ->execute([$jeAdvId, $accPrepaid['id'], $advanceApplied, $advanceApplied, $advanceApplied, $branchBaseCurrency]);
                        $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                            ->execute([$advanceApplied, $advanceApplied, $accPrepaid['id']]);
                    }
                }
            }
            */

            // ── تسجيل تكاليف الشحن (قيد منفصل) ──
            if ($shippingCost > 0) {
                $accShipExp = $getAcc('shipping_expense') ?: $getAcc('consumable_expense');
                if ($accShipExp) {
                    $jeShip = $nextJeNo();

                    if ($shippingPayMethod === 'cash' && $shippingCashAccId) {
                        // دفع نقدي: مدين مصاريف شحن / دائن الصندوق
                        $stCA = $pdo->prepare("SELECT * FROM `{$TAC}` WHERE id=?");
                        $stCA->execute([$shippingCashAccId]);
                        $cashAcc2 = $stCA->fetch(PDO::FETCH_ASSOC);
                        if ($cashAcc2) {
                            // ⚠ تصحيح: تحويل حقيقي لعملة الفرع الأساسية —
                            // كانت هاي فجوة موثّقة (rate=1 مفترض)، انصلحت.
                            $shippingCostBase = $convertShippingToBase($shippingCost, $shippingCur, $curCode, $rate);
                            $pdo->prepare("INSERT INTO `{$TJE}`
                                (entry_number,entry_date,description,currency_id,exchange_rate,
                                 total_debit,total_credit,status,reference_type,reference_id,created_by)
                                VALUES (?,?,?,?,1,?,?,'posted','purchase_shipping',?,?)")
                                ->execute([
                                    $jeShip,
                                    date('Y-m-d'),
                                    "أجور شحن {$pur['purchase_number']} — {$shippingDesc}",
                                    $curId($branchBaseCurrency),
                                    $shippingCostBase,
                                    $shippingCostBase,
                                    $purId,
                                    $_SESSION['user_id']
                                ]);
                            $jeShipId = (int) $pdo->lastInsertId();
                            // مدين: مصاريف الشحن (حساب بعملة الفرع الأساسية أصلاً)
                            $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                                VALUES (?,?,?,0,?,?,'أجور شحن',?,?)")
                                ->execute([$jeShipId, $accShipExp['id'], $shippingCostBase, $shippingCost, $shippingCostBase, $curId($shippingCur), ($shippingCostBase > 0 ? round($shippingCost / $shippingCostBase, 6) : 1)]);
                            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                                ->execute([$shippingCostBase, $shippingCostBase, $accShipExp['id']]);
                            // دائن: الصندوق (بعملته الخاصة — raw، بما إنه محفوظ فعلاً بهاي العملة)
                            $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                                VALUES (?,?,0,?,?,?,'دفع أجور شحن',?,?)")
                                ->execute([$jeShipId, $shippingCashAccId, $shippingCostBase, $shippingCost, $shippingCostBase, $curId($shippingCur), ($shippingCostBase > 0 ? round($shippingCost / $shippingCostBase, 6) : 1)]);
                            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                                ->execute([$shippingCostBase, $shippingCost, $shippingCashAccId]);
                        }
                    } else {
                        // آجل: مدين مصاريف شحن / دائن ذمة شركة الشحن
                        $payableId = $shippingPayableId ?? null;
                        if (!$payableId) {
                            // جلب حساب ذمة الشركة من shipping_carriers
                            if ($shippingCarrierId) {
                                $scSt = $pdo->prepare("SELECT payable_account_id FROM shipping_carriers WHERE id=?");
                                $scSt->execute([$shippingCarrierId]);
                                $payableId = (int) ($scSt->fetchColumn() ?: 0) ?: null;
                            }
                        }
                        if ($payableId) {
                            $shippingCostBase = $convertShippingToBase($shippingCost, $shippingCur, $curCode, $rate);
                            $pdo->prepare("INSERT INTO `{$TJE}`
                                (entry_number,entry_date,description,currency_id,exchange_rate,
                                 total_debit,total_credit,status,reference_type,reference_id,created_by)
                                VALUES (?,?,?,?,1,?,?,'posted','purchase_shipping',?,?)")
                                ->execute([
                                    $jeShip,
                                    date('Y-m-d'),
                                    "أجور شحن آجل {$pur['purchase_number']} — {$shippingDesc}",
                                    $curId($branchBaseCurrency),
                                    $shippingCostBase,
                                    $shippingCostBase,
                                    $purId,
                                    $_SESSION['user_id']
                                ]);
                            $jeShipId = (int) $pdo->lastInsertId();
                            // مدين: مصاريف الشحن
                            $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                                VALUES (?,?,?,0,?,?,'أجور شحن آجل',?,?)")
                                ->execute([$jeShipId, $accShipExp['id'], $shippingCostBase, $shippingCost, $shippingCostBase, $curId($shippingCur), ($shippingCostBase > 0 ? round($shippingCost / $shippingCostBase, 6) : 1)]);
                            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                                ->execute([$shippingCostBase, $shippingCostBase, $accShipExp['id']]);
                            // دائن: ذمة شركة الشحن (بعملتها الخاصة — raw)
                            $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                                VALUES (?,?,0,?,?,?,'ذمة شحن آجل',?,?)")
                                ->execute([$jeShipId, $payableId, $shippingCostBase, $shippingCost, $shippingCostBase, $curId($shippingCur), ($shippingCostBase > 0 ? round($shippingCost / $shippingCostBase, 6) : 1)]);
                            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                                ->execute([$shippingCostBase, $shippingCost, $payableId]);
                        }
                    }
                }
            }

            // ── تسجيل الدفع الجزئي ──
            $paidStatus = 'pending';
            if ($paidAmt > 0 && $cashAccId) {
                $stCash = $pdo->prepare("SELECT * FROM `{$TAC}` WHERE id=?");
                $stCash->execute([$cashAccId]);
                $cashAcc = $stCash->fetch(PDO::FETCH_ASSOC);
                if ($cashAcc) {
                    $jePayNo = $nextJeNo();
                    $pdo->prepare("INSERT INTO `{$TJE}`
                        (entry_number,entry_date,description,currency_id,exchange_rate,
                         total_debit,total_credit,status,reference_type,reference_id,created_by)
                        VALUES (?,?,?,?,1,?,?,'posted','purchase_payment',?,?)")
                        ->execute([
                            $jePayNo,
                            date('Y-m-d'),
                            "دفعة فاتورة {$pur['purchase_number']}",
                            // ⚠ نفس تصحيح القيد الرئيسي: $paidAmt (بعد
                            // إصلاح معادلة التحويل بالرسالة السابقة) صار
                            // فعلياً بعملة الفرع الأساسية، مو عملة الدفع
                            // المختارة — رأس القيد لازم يتطابق معه.
                            $curId($branchBaseCurrency),
                            $paidAmt,
                            $paidAmt,
                            $purId,
                            $_SESSION['user_id']
                        ]);
                    $jePayId = (int) $pdo->lastInsertId();
                    // ⚠ سعر الصرف المخزَّن بسطور القيد لازم يعكس التحويل
                    // الفعلي بين original_amount(عملة الدفع) وbase_amount
                    // (عملة الفرع) — لا $paidRate الخام (ممكن يكون =1
                    // لسبب مختلف كلياً، متل "عملة الدفع=عملة الفاتورة"،
                    // بينما عملة الفاتورة نفسها مختلفة عن عملة الفرع، فيصير
                    // الرقم المخزَّن مضلّلاً رغم إنه المبلغ الأساسي صحيح).
                    $paidEffRate = $paidAmt > 0 ? round($paidOrigAmt / $paidAmt, 6) : 1;
                    // مدين: ذمم الموردين (بعملة الفاتورة)
                    $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                        VALUES (?,?,?,0,?,?,?,?,?)")
                        ->execute([
                            $jePayId,
                            $accSupplier['id'],
                            $paidAmt,
                            $paidOrigAmt,
                            $paidAmt,
                            "دفع للمورد",
                            $curId($paidCur),
                            $paidEffRate
                        ]);
                    $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                        // ⚠ إصلاح إشارة (محدَّث بعد قرار عكس القاعدة الكامل
                        // للحسابات الالتزامية — راجع تعليق القيد الرئيسي
                        // أعلاه): دائن الآن = +زيادة الالتزام، مدين = −تخفيضه.
                        // هالسطر "مدين" (دفعة، تخفيض ذمة) لازم يطرح، لا يجمع
                        // — عكس تماماً القاعدة القديمة (طرح صار على الدائن).
                        ->execute([$paidAmt, $paidAmt, $accSupplier['id']]);
                    // دائن: الصندوق (بعملته الأصلية)
                    $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                        VALUES (?,?,0,?,?,?,?,?,?)")
                        ->execute([
                            $jePayId,
                            $cashAccId,
                            $paidAmt,
                            $paidOrigAmt,
                            $paidAmt,
                            "دفع للمورد",
                            $curId($paidCur),
                            $paidEffRate
                        ]);
                    // ⚠ اتجاه تحديث الرصيد يفرّق هون بحسب نوع الحساب المختار:
                    // - صندوق/بنك عادي: هاد سطر "دائن" (فلوس خرجت فعلياً
                    //   من الصندوق) — حساب أصل، فالدائن لازم يطرح (ينقص)،
                    //   نفس اتجاه قيد الشحن بالضبط (حساب 1001 بمثال
                    //   المستخدم انخفض صح لـ-11). كان هالسطر يجمع بالغلط
                    //   (باگ حقيقي — الصندوق كان يزيد بدل ما ينقص عند
                    //   الدفع لمورد، تناقض مباشر مع تسمية السطر "دائن"
                    //   بالتعليق أصلاً).
                    // - حساب دفعة مقدمة المورد نفسه: لازم رصيده **ينقص** عند
                    //   الاستخدام (نفس اتجاه الكتلة التلقائية المعطّلة أعلاه
                    //   بالضبط) — وإلا رصيد الدفعة المقدمة بيكبر بدل ما يُستهلك،
                    //   وهاد خطأ محاسبي جوهري يفتح باب استخدام نفس الدفعة
                    //   المقدمة أكتر من مرة.
                    // ⚠ كلا الحالتين (صندوق عادي أو حساب دفعة مقدمة المورد
                    // نفسه) تطرح — الاثنان ينقصان فعلياً عند الدفع.
                    $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                        ->execute([$paidAmt, $paidOrigAmt, $cashAccId]);
                }
            }

            // ── خصم تعجيل الدفع — تحقق سيادي بالسيرفر (مو من الواجهة) ──
            // شروط الانطباق الثلاثة معاً: (1) نسبة الخصم محدَّدة على
            // الفاتورة، (2) تاريخ اليوم لسا ضمن المهلة (due_date)،
            // (3) الدفعة **كاملة** — يعني المبلغ المدفوع نقداً (+ أي
            // دفعة مقدمة مطبَّقة) يغطي المبلغ بعد الخصم. دفعة جزئية
            // (أقل من المبلغ المخصوم) لا تُفعِّل الخصم إطلاقاً مهما كان
            // التاريخ — بالضبط متل ما تحدد.
            $settleDiscApplied = 0;
            $settlePct = (float) ($pur['settlement_discount_pct'] ?? 0);
            if ($settlePct > 0 && !empty($pur['due_date'])) {
                $stillEligible = date('Y-m-d') <= $pur['due_date'];
                $settleDiscFullBase = round($finalBase * $settlePct / 100, 2);
                $discountedFinalBase = $finalBase - $settleDiscFullBase;
                $coveredSoFar = $paidAmt + $advanceApplied; // نقدي + دفعة مقدمة مطبَّقة
                if ($stillEligible && $coveredSoFar >= ($discountedFinalBase - 0.01)) {
                    $accSettleDisc = $getAcc('settlement_discount_income');
                    if ($accSettleDisc) {
                        $jeSettleNo = $nextJeNo();
                        $pdo->prepare("INSERT INTO `{$TJE}`
                            (entry_number,entry_date,description,currency_id,exchange_rate,
                             total_debit,total_credit,status,reference_type,reference_id,created_by)
                            VALUES (?,?,?,?,1,?,?,'posted','purchase_settlement_discount',?,?)")
                            ->execute([
                                $jeSettleNo,
                                date('Y-m-d'),
                                "خصم تعجيل دفع {$settlePct}% — فاتورة {$pur['purchase_number']} — {$supName}",
                                $curId($branchBaseCurrency),
                                $settleDiscFullBase,
                                $settleDiscFullBase,
                                $purId,
                                $_SESSION['user_id']
                            ]);
                        $jeSettleId = (int) $pdo->lastInsertId();
                        // ⚠ المكافئ بعملة الفاتورة الأصلية (لا تكرار لنفس
                        // رقم عملة الأساس) — نفس اتفاقية باقي القيود
                        // (استلام الفاتورة، الدفع): original_amount بعملة
                        // الفاتورة، base_amount بعملة الفرع، currency_id
                        // على مستوى السطر = عملة الفاتورة.
                        $settleDiscOrig = round($settleDiscFullBase * $rate, 2);
                        // مدين: ذمم الموردين (تخفيض إضافي — الخصم صار جزء من التسوية)
                        $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                            VALUES (?,?,?,0,?,?,'خصم تعجيل دفع',?,?)")
                            ->execute([$jeSettleId, $accSupplier['id'], $settleDiscFullBase, $settleDiscOrig, $settleDiscFullBase, $curId($curCode), $rate]);
                        $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                            // ⚠ إصلاح إشارة (بعد عكس القاعدة الكاملة —
                            // راجع تعليق القيد الرئيسي): مدين على التزام
                            // ينقص الرصيد الآن، لا يزيده.
                            ->execute([$settleDiscFullBase, $settleDiscFullBase, $accSupplier['id']]);
                        // دائن: إيراد خصم تعجيل الدفع
                        $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                            VALUES (?,?,0,?,?,?,'خصم تعجيل دفع',?,?)")
                            ->execute([$jeSettleId, $accSettleDisc['id'], $settleDiscFullBase, $settleDiscOrig, $settleDiscFullBase, $curId($curCode), $rate]);
                        $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                            ->execute([$settleDiscFullBase, $settleDiscFullBase, $accSettleDisc['id']]);
                        $settleDiscApplied = $settleDiscFullBase;
                    }
                    // ⚠ لو حساب settlement_discount_income غير مربوط بعد
                    // (account_settings.php)، الخصم ما بينطبق بصمت — نفس
                    // فلسفة $getAcc() بباقي الملف (تجاهل آمن، مو throw).
                }
            }

            // ── تحديث الفاتورة (يشمل الدفع النقدي + الدفعة المقدمة + خصم التعجيل إن انطبق) ──
            $totalPaidIncAdvance = $paidAmt + $advanceApplied + $settleDiscApplied; // بعملة الفرع الأساسية

            // ⚠ تسوية فروقات التقريب الصغيرة (اختيارية، بموافقة صريحة من
            // المستخدم عبر تشيك بوكس بالواجهة — مو تلقائية بصمت). فروقات
            // كسور السنت شائعة لما يصير تحويل مزدوج بين عملتين (عملة
            // الدفع → عملة الفاتورة → عملة الفرع)، وملاحقتها لكل فاتورة
            // أعقد من فايدتها. نطبّقها فقط لو: (1) المستخدم فعّل الخيار
            // صراحة، و(2) الفرق فعلاً صغير (≤ 0.01$ بعملة الفرع) — أي فرق
            // أكبر من كذا ما بينسوّى تلقائياً حتى لو الخيار مفعّل، حماية
            // من إخفاء فرق حقيقي بالغلط.
            $exactSettle = !empty($_POST['exact_settle']);
            if ($exactSettle && abs($totalPaidIncAdvance - $finalBase) <= 0.01 && $totalPaidIncAdvance != $finalBase) {
                // يشمل الحالتين: نقص بسيط (كان رح يظهر "جزئي" بالغلط)
                // أو زيادة بسيطة (كانت رح تظهر "رصيد سالب" تافه بالغلط)
                $totalPaidIncAdvance = $finalBase;
            }

            $finalPaidStatus = $totalPaidIncAdvance >= $finalBase ? 'paid'
                : ($totalPaidIncAdvance > 0 ? 'partial' : 'pending');

            // ⚠ إصلاح حرج: أعمدة paid_amount/balance_amount بجدول purchases_{TS}
            // بعملة الفاتورة (نفس ما بيتعرض بـindex.php جنب currency_symbol) —
            // بينما $totalPaidIncAdvance بعملة الفرع الأساسية. لازم نحوّل
            // لعملة الفاتورة بضرب $rate قبل الطرح.
            // ⚠ إصلاح إضافي: الطرح لازم يصير من $finalOrig (القيمة الحقيقية
            // المُشتقة بعملة الفاتورة، محسوبة فوق) — لا من عمود final_amount
            // المقروء مباشرة من الجدول، لأنه هلق بعملة الفرع دائماً (بعد
            // قرار invoice_new.php)، فقراءته هون كانت بتطرح رقمين بعملتين
            // مختلفتين فعلياً (بالضبط سبب "المتبقي على المورد" الغلط
            // يلي ظهر سابقاً بمودال التفاصيل — نفس فئة المشكلة، مكان مختلف).
            $totalPaidInvCur = round($totalPaidIncAdvance * $rate, 4);
            $balanceInvCur = round($finalOrig - $totalPaidInvCur, 4);

            $pdo->prepare("UPDATE `{$TP}` SET
                status='confirmed',
                paid_amount=?,
                balance_amount=?,
                payment_status=?,
                journal_entry_id=?,
                notes=CONCAT(COALESCE(notes,''),?),
                updated_by=?
                WHERE id=?")
                ->execute([
                    $totalPaidInvCur,
                    $balanceInvCur,
                    $finalPaidStatus,
                    $jeId,
                    $notes ? ' | ' . $notes : '',
                    $_SESSION['user_id'],
                    $purId
                ]);

            $pdo->commit();
            ob_get_clean();
            $msg = 'تم تأكيد الفاتورة وإضافة المخزون والقيد المحاسبي';
            if ($advanceApplied > 0)
                $msg .= " — تم تطبيق دفعة مقدمة بقيمة {$advanceApplied}$";
            echo json_encode([
                'ok' => true,
                'msg' => $msg,
                'je_no' => $jeNo,
                'movement' => $movNo,
                'status' => 'confirmed',
                'paid' => $paidAmt,
                'advance_applied' => $advanceApplied,
                'img' => $imgPath,
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ── إلغاء فاتورة الشراء ──
    elseif ($act === 'cancel') {
        $purId = (int) ($_POST['invoice_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        $stPur = $pdo->prepare("SELECT * FROM `{$TP}` WHERE id=?");
        $stPur->execute([$purId]);
        $pur = $stPur->fetch(PDO::FETCH_ASSOC);
        if (!$pur)
            throw new Exception('الفاتورة غير موجودة');
        if ($pur['status'] === 'cancelled')
            throw new Exception('ملغاة مسبقاً');

        $pdo->beginTransaction();
        try {
            // عكس المخزون إذا كانت مؤكدة
            if ($pur['status'] === 'confirmed') {
                $stItems = $pdo->prepare("SELECT * FROM `{$TPI}` WHERE purchase_id=?");
                $stItems->execute([$purId]);
                $widCancel = (int) ($pur['warehouse_id'] ?? 0);
                foreach ($stItems->fetchAll(PDO::FETCH_ASSOC) as $item) {
                    if (!$item['variant_id'])
                        continue;
                    $pdo->prepare("UPDATE `{$TWI}` SET quantity=GREATEST(0,quantity-?),last_movement_at=NOW()
                        WHERE variant_id=? AND warehouse_id=?")
                        ->execute([$item['quantity'], $item['variant_id'], $widCancel]);
                }
                // عكس القيد
                if ($pur['journal_entry_id']) {
                    $stJI = $pdo->prepare("SELECT ji.*, ac.account_type FROM `{$TJI}` ji
                        JOIN `{$TAC}` ac ON ac.id = ji.account_id
                        WHERE ji.journal_entry_id=?");
                    $stJI->execute([$pur['journal_entry_id']]);
                    foreach ($stJI->fetchAll(PDO::FETCH_ASSOC) as $ji) {
                        // ⚠ إصلاح حرج مرتبط بإصلاح إشارة حساب الذمة أعلاه:
                        // حسابات الأصول/المصاريف (inventory, cash, expense)
                        // لسا على القاعدة القياسية (مدين=+/دائن=−)، فعكسها
                        // = balance -= (debit-credit) زي ما كان دايماً.
                        // حسابات الالتزام (liability — ذمة المورد تحديداً)
                        // صارت بالقاعدة المعاكسة (دائن=+/مدين=−)، فعكسها
                        // لازم معادلة معاكسة: balance += (debit-credit)
                        // (يعني balance -= (credit-debit))، وإلا كان عكس
                        // القيد رح يضاعف الخطأ بدل ما يلغيه.
                        $net = $ji['debit'] - $ji['credit'];
                        if ($ji['account_type'] === 'liability') {
                            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                                ->execute([$net, $net, $ji['account_id']]);
                        } else {
                            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                                ->execute([$net, $net, $ji['account_id']]);
                        }
                    }
                    $pdo->prepare("UPDATE `{$TJE}` SET status='cancelled',cancelled_at=NOW(),cancelled_by=? WHERE id=?")
                        ->execute([$_SESSION['user_id'], $pur['journal_entry_id']]);
                }
            }
            $pdo->prepare("UPDATE `{$TP}` SET status='cancelled',
                notes=CONCAT(COALESCE(notes,''),' | إلغاء: {$reason}'),
                updated_by=? WHERE id=?")
                ->execute([$_SESSION['user_id'], $purId]);
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
