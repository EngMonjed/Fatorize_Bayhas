<?php
/**
 * api/confirm_purchase_return.php — تأكيد مرتجع فاتورة شراء
 * المسار: retail1/api/confirm_purchase_return.php
 *
 * ⚠ عكس تماماً لـ api/confirm_purchase_invoice.php:
 *   - نقصان المخزون (لا زيادة) — movement_type='out'
 *   - قيد محاسبي واحد فقط لكل تأكيد: دائن المخزون دائماً، والطرف
 *     المقابل (مدين) يتحدد حسب "الحساب المستهدف" (target_account_type)
 *     المرتبط بـ"نوعية التسوية" (payment_handling) وقت إنشاء مسودة
 *     المرتجع بـ returns.php:
 *       - payment_handling='not_paid' → target_account_type=NULL دائماً
 *         → مدين: ذمة المورد (الحالة الافتراضية)
 *       - payment_handling='partial'/'paid_full' → target_account_type
 *         إجباري (cash/supplier/advance):
 *           - 'cash' → مدين: الصندوق/البنك المختار (refund_account_id)
 *           - 'supplier' → مدين: ذمة المورد (صراحة)
 *           - 'advance' → مدين: حساب الدفعة المقدمة الخاص بالمورد
 *   - current_cost بـ warehouse_items لا يُلمَس إطلاقاً (قرار صريح:
 *     المرتجع بينقص الكمية بس، ما بيعيد حساب تكلفة المخزون)
 *
 * كل معادلات العملة (original_amount المُشتق من base×rate، لا مقروء
 * مباشرة من عمود مكرَّر) مطابقة تماماً لنفس الإصلاحات المعتمدة بـ
 * confirm_purchase_invoice.php — راجعه كمرجع تفصيلي لأي تعديل مستقبلي.
 *
 * ⚠ يتطلب عمود purchase_returns_{TS}.target_account_type
 * (ENUM('cash','supplier','advance') NULL) — لازم يُضاف بالـschema قبل
 * نشر هالملف، وإلا الحفظ من returns.php هيفشل فوراً بخطأ SQL واضح.
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
    requirePermission('purchases.returns', 'confirm');

    $TS = $_SESSION['table_suffix'];
    $TR = "purchase_returns_{$TS}";
    $TRI = "purchase_return_items_{$TS}";
    $TP = "purchases_{$TS}";
    $TWI = "warehouse_items_{$TS}";
    $TIM = "inventory_movements_{$TS}";
    $TIMD = "inventory_movement_details_{$TS}";
    $TAC = "account_charts_{$TS}";
    $TJE = "journal_entries_{$TS}";
    $TJI = "journal_entry_items_{$TS}";
    $TIAS = "invoice_account_settings_{$TS}";
    $TSP = "product_suppliers_{$TS}";

    // ⚠ عملة الفرع الأساسية الحقيقية — نفس إصلاح confirm_purchase_invoice.php
    // (branches.base_currency عمود نصي قديم/تالف، العملة الحقيقية عبر
    // base_currency_id فقط).
    $branchBaseCurrency = 'USD';
    $allowNegativeStock = false;
    if (!empty($_SESSION['branch_id'])) {
        $bcStmt = $pdo->prepare("SELECT c.code, b.allow_negative_stock FROM branches b
            JOIN currencies c ON c.id = b.base_currency_id WHERE b.id = ?");
        $bcStmt->execute([$_SESSION['branch_id']]);
        $bcRow = $bcStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $branchBaseCurrency = $bcRow['code'] ?? 'USD';
        $allowNegativeStock = !empty($bcRow['allow_negative_stock']);
    }

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

    $act = $_POST['_action'] ?? '';

    // ── تأكيد مرتجع الشراء ──
    if ($act === 'confirm_return') {
        $retId = (int) ($_POST['return_id'] ?? 0);
        if (!$retId)
            throw new Exception('رقم المرتجع مطلوب');

        $stRet = $pdo->prepare("SELECT r.*, s.name AS supplier_name, c.code AS curr_code
            FROM `{$TR}` r
            LEFT JOIN `{$TSP}` s ON s.id = r.supplier_id
            LEFT JOIN currencies c ON c.id = r.return_currency_id
            WHERE r.id = ?");
        $stRet->execute([$retId]);
        $ret = $stRet->fetch(PDO::FETCH_ASSOC);
        if (!$ret)
            throw new Exception('المرتجع غير موجود');
        if ($ret['status'] !== 'draft')
            throw new Exception('يمكن تأكيد المسودات فقط (الحالة: ' . $ret['status'] . ')');

        $stItems = $pdo->prepare("SELECT * FROM `{$TRI}` WHERE return_id=?");
        $stItems->execute([$retId]);
        $items = $stItems->fetchAll(PDO::FETCH_ASSOC);
        if (empty($items))
            throw new Exception('المرتجع لا يحتوي بنوداً');

        // المستودع — من الفاتورة الأصلية (نفس مبدأ "مستودع واحد لكامل
        // المستند" المعتمد بفواتير الشراء).
        $stPur = $pdo->prepare("SELECT warehouse_id, purchase_number FROM `{$TP}` WHERE id=?");
        $stPur->execute([$ret['purchase_id']]);
        $pur = $stPur->fetch(PDO::FETCH_ASSOC);
        $wid = (int) ($ret['warehouse_id'] ?? $pur['warehouse_id'] ?? 0);
        if (!$wid)
            throw new Exception('لا يوجد مستودع محدَّد لهذا المرتجع');

        $pdo->beginTransaction();
        try {
            // ⚠ return_amount أصلاً بعملة الفرع (مشتق من purchase_items.
            // total_price المخزَّن بعملة الفرع — قرار invoice_new.php).
            $returnBase = (float) $ret['return_amount'];
            $rate = (float) ($ret['exchange_rate'] ?: 1);
            $curCode = $ret['curr_code'] ?? $branchBaseCurrency;
            $supName = $ret['supplier_name'] ?? 'مورد';

            // ── التحقق المسبق من توفر الكمية (قبل أي تعديل فعلي) ──
            // ⚠ لازم فحص مستقل عن "الكمية القابلة للإرجاع" المحسوبة وقت
            // إنشاء المسودة (يلي قارنت بالمشترى الأصلي بس) — المخزون
            // الفعلي ممكن يكون نقص أكتر بالفترة بين إنشاء المسودة
            // والتأكيد (بيع جزء منه مثلاً). فحص سيادي هون يمنع رصيد سالب
            // غير مقصود لو allow_negative_stock=0.
            if (!$allowNegativeStock) {
                foreach ($items as $it) {
                    if (!$it['variant_id'])
                        continue;
                    $stChk = $pdo->prepare("SELECT quantity FROM `{$TWI}` WHERE variant_id=? AND warehouse_id=?");
                    $stChk->execute([$it['variant_id'], $wid]);
                    $avail = (float) ($stChk->fetchColumn() ?: 0);
                    if ($avail < (float) $it['quantity_returned']) {
                        throw new Exception("الكمية المتوفرة فعلياً بالمخزون لصنف \"{$it['product_name']}\" ({$avail}) أقل من كمية المرتجع ({$it['quantity_returned']}) — لا يمكن التأكيد بدون السماح برصيد سالب");
                    }
                }
            }

            // ── نقصان المخزون ──
            $movNo = 'MOV-OUT-RET-' . date('Ymd') . '-' . str_pad($retId, 5, '0', STR_PAD_LEFT);
            $totalQty = array_sum(array_column($items, 'quantity_returned'));

            $pdo->prepare("INSERT INTO `{$TIM}`
                (movement_number,movement_type,warehouse_id,items_count,total_quantity,
                 total_value_base,reference_type,reference_id,reference_number,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $movNo,
                    'out',
                    $wid,
                    count($items),
                    $totalQty,
                    $returnBase,
                    'purchase_return',
                    $retId,
                    $ret['return_number'],
                    $_SESSION['user_id']
                ]);
            $movId = (int) $pdo->lastInsertId();

            foreach ($items as $it) {
                if (!$it['variant_id'])
                    continue;
                $qty = (float) $it['quantity_returned'];
                $unitPrice = (float) $it['unit_price']; // بعملة الفرع أصلاً (نفس منطق purchase_items)

                $stB = $pdo->prepare("SELECT quantity, current_cost FROM `{$TWI}` WHERE variant_id=? AND warehouse_id=?");
                $stB->execute([$it['variant_id'], $wid]);
                $curRow = $stB->fetch(PDO::FETCH_ASSOC);
                $before = (float) ($curRow['quantity'] ?? 0);
                $costNow = (float) ($curRow['current_cost'] ?? $unitPrice);
                $after = max(0, $before - $qty);

                // ⚠ current_cost لا يُلمَس إطلاقاً — قرار صريح: المرتجع
                // بينقص الكمية بس، ما بيعيد حساب تكلفة المخزون (لا متوسط
                // مرجّح معكوس ولا أي إعادة تقييم).
                $pdo->prepare("UPDATE `{$TWI}` SET quantity=?, last_movement_at=NOW()
                    WHERE variant_id=? AND warehouse_id=?")
                    ->execute([$after, $it['variant_id'], $wid]);

                $pdo->prepare("INSERT INTO `{$TIMD}`
                    (movement_id,variant_id,product_id,quantity,unit_price,cost_price,
                     total_value,balance_before,balance_after)
                    VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([
                        $movId,
                        $it['variant_id'],
                        $it['product_id'] ?? 0,
                        $qty,
                        $unitPrice,
                        $costNow,
                        $unitPrice * $qty,
                        $before,
                        $after
                    ]);
            }

            // ── جلب حسابات الربط ──
            $getAcc = function (string $key) use ($pdo, $TIAS, $TAC): ?array {
                $st = $pdo->prepare("SELECT ac.* FROM `{$TIAS}` i
                    JOIN `{$TAC}` ac ON ac.id=i.account_id
                    WHERE i.setting_key=? LIMIT 1");
                $st->execute([$key]);
                return $st->fetch(PDO::FETCH_ASSOC) ?: null;
            };

            // حساب ذمة المورد: يُفضَّل الحساب الخاص بالمورد، وإلا العام
            // (نفس منطق confirm_purchase_invoice.php حرفياً — بدون أي
            // حساب جديد، فقط الحسابين الموجودين أصلاً لكل مورد).
            $accSupplier = null;
            if (!empty($ret['supplier_id'])) {
                $stSupAcc = $pdo->prepare("SELECT ac.* FROM `{$TSP}` s
                    JOIN `{$TAC}` ac ON ac.id=s.account_id
                    WHERE s.id=? AND s.account_id IS NOT NULL LIMIT 1");
                $stSupAcc->execute([$ret['supplier_id']]);
                $accSupplier = $stSupAcc->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$accSupplier)
                $accSupplier = $getAcc('supplier_payable');

            $accInventory = $getAcc('finished_inventory');

            if (!$accSupplier || !$accInventory)
                throw new Exception('يرجى ضبط حساب ذمة للمورد أو إعدادات الربط المحاسبي العامة (ذمم الموردين + المخزون)');

            // ── تحديد الطرف المدين حسب الحساب المستهدف ──
            // ⚠ قرار معماري صريح (بلا أي حساب جديد بالنظام): القيد
            // الرئيسي دائماً "دائن: المخزون"، والطرف المدين المقابل
            // يتحدد حسب "الحساب المستهدف" (target_account_type) المختار
            // وقت إنشاء مسودة المرتجع — مرتبط بنوعية التسوية
            // (payment_handling): not_paid = بلا حساب مستهدف (مخزَّن
            // NULL)، فالافتراضي هو ذمة المورد. partial/paid_full = حساب
            // مستهدف إجباري (صندوق/ذمة/دفعة مقدمة، من الحسابين الموجودين
            // أصلاً لكل مورد + أي صندوق/بنك — لا حساب "مرتجعات مشتريات"
            // وسيط إطلاقاً).
            $debitAccId = null;
            $debitAccLabel = '';
            $targetType = $ret['target_account_type'] ?? null;

            if ($targetType === 'cash') {
                $refundAccId = (int) ($ret['refund_account_id'] ?? 0);
                if (!$refundAccId)
                    throw new Exception('لم يُحدَّد حساب استرداد نقدي (صندوق/بنك) لهذا المرتجع');
                $stCash = $pdo->prepare("SELECT * FROM `{$TAC}` WHERE id=? AND account_type='asset' AND is_active=1");
                $stCash->execute([$refundAccId]);
                $accCash = $stCash->fetch(PDO::FETCH_ASSOC);
                if (!$accCash)
                    throw new Exception('حساب الاسترداد المحدَّد غير صالح');
                $debitAccId = $accCash;
                $debitAccLabel = "استرداد مرتجع {$ret['return_number']} — {$supName}";
            } elseif ($targetType === 'advance') {
                if (empty($ret['supplier_id']))
                    throw new Exception('لا يمكن زيادة دفعة مقدمة بدون مورد محدَّد');
                $stPrepaid = $pdo->prepare("SELECT ac.* FROM `{$TSP}` s
                    JOIN `{$TAC}` ac ON ac.id=s.prepaid_account_id
                    WHERE s.id=? AND s.prepaid_account_id IS NOT NULL LIMIT 1");
                $stPrepaid->execute([$ret['supplier_id']]);
                $accPrepaid = $stPrepaid->fetch(PDO::FETCH_ASSOC);
                if (!$accPrepaid)
                    throw new Exception('المورد ليس له حساب دفعة مقدمة مضبوط — راجع صفحة الموردين');
                $debitAccId = $accPrepaid;
                $debitAccLabel = "دفعة مقدمة من مرتجع {$ret['return_number']} — {$supName}";
            } else {
                // target_account_type = 'supplier' أو NULL (not_paid) —
                // الحالة الافتراضية: تخفيض ذمة المورد مباشرة. إرجاع بضاعة
                // للمورد بيخفّض ما ندين فيه له بغض النظر عن استرداد
                // الفلوس فعلياً أو لأ — هاد جوهر معنى "إشعار دائن"
                // (Credit Note) محاسبياً.
                $debitAccId = $accSupplier;
                $debitAccLabel = "ذمة {$supName} — مرتجع {$ret['return_number']}";
            }

            $y = date('Y');
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
                VALUES (?,?,?,?,1,?,?,'posted','purchase_return',?,?)")
                ->execute([
                    $jeNo,
                    date('Y-m-d'),
                    "مرتجع فاتورة {$ret['purchase_number']} — {$supName}",
                    $curId($branchBaseCurrency),
                    $returnBase,
                    $returnBase,
                    $retId,
                    $_SESSION['user_id']
                ]);
            $jeId = (int) $pdo->lastInsertId();

            // ⚠ نفس اشتقاق $finalOrig بـ confirm_purchase_invoice.php
            // بالضبط — القيمة الحقيقية بعملة الفاتورة، مُشتقة من الأساس
            // × سعر الصرف المجمَّد، لا مخزَّنة كنسخة مكرَّرة مستقلة.
            $returnOrig = round($returnBase * $rate, 4);

            // مدين: الطرف المحدَّد أعلاه (ذمة المورد / صندوق-بنك / دفعة مقدمة)
            $pdo->prepare("INSERT INTO `{$TJI}`
                (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                VALUES (?,?,?,0,?,?,?,?,?)")
                ->execute([
                    $jeId,
                    $debitAccId['id'],
                    $returnBase,
                    $returnOrig,
                    $returnBase,
                    $debitAccLabel,
                    $curId($curCode),
                    $rate
                ]);
            // ⚠ إصلاح إشارة حرج (بعد تحقيق مع المستخدم بمثال حقيقي —
            // راجع نفس الشرح المفصَّل بـ confirm_purchase_invoice.php):
            // حساب الذمة التزام (Liability)، رصيده الطبيعي دائن — بالمعيار
            // المحاسبي القياسي دائن يزيد رصيده، مدين ينقصه. القاعدة الموحَّدة
            // القديمة ("مدين=+" لكل الحسابات بلا استثناء) كانت معكوسة لحسابات
            // الالتزام تحديداً. هون تحديداً "الطرف المدين" متغيّر (صندوق/دفعة
            // مقدمة = أصل، أو ذمة المورد = التزام)، فلازم نتحقق من نوع الحساب
            // الفعلي ونطبّق المعادلة المناسبة له.
            if (($debitAccId['account_type'] ?? '') === 'liability') {
                $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                    ->execute([$returnBase, $returnBase, $debitAccId['id']]);
            } else {
                $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                    ->execute([$returnBase, $returnBase, $debitAccId['id']]);
            }

            // دائن: المخزون (دائماً، بلا استثناء)
            $pdo->prepare("INSERT INTO `{$TJI}`
                (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency_id,exchange_rate)
                VALUES (?,?,0,?,?,?,?,?,?)")
                ->execute([
                    $jeId,
                    $accInventory['id'],
                    $returnBase,
                    $returnOrig,
                    $returnBase,
                    "مخزون — مرتجع {$ret['return_number']}",
                    $curId($curCode),
                    $rate
                ]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                ->execute([$returnBase, $returnBase, $accInventory['id']]);

            // ── تحديث حالة المرتجع ──
            // ⚠ purchase_returns_{TS} بدون عمودي updated_by/updated_at
            // (بعكس purchases_{TS}) — نفس أعمدة UPDATE المستخدمة فعلياً
            // بمعالج save_return الأصلي بـreturns.php (السطر ٢٤٧)، بلا
            // أي منهما.
            $pdo->prepare("UPDATE `{$TR}` SET status='posted', journal_entry_id=?
                WHERE id=?")
                ->execute([$jeId, $retId]);

            $pdo->commit();
            ob_get_clean();
            echo json_encode([
                'ok' => true,
                'msg' => 'تم تأكيد المرتجع، تنزيل المخزون، وترحيل القيد المحاسبي',
                'je_no' => $jeNo,
                'movement' => $movNo,
                'status' => 'posted',
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ── إلغاء مرتجع مؤكد (عكس كامل — نفس مبدأ إلغاء فاتورة الشراء) ──
    elseif ($act === 'cancel_return') {
        $retId = (int) ($_POST['return_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        $stRet = $pdo->prepare("SELECT * FROM `{$TR}` WHERE id=?");
        $stRet->execute([$retId]);
        $ret = $stRet->fetch(PDO::FETCH_ASSOC);
        if (!$ret)
            throw new Exception('المرتجع غير موجود');
        if ($ret['status'] === 'cancelled')
            throw new Exception('ملغى مسبقاً');

        $pdo->beginTransaction();
        try {
            if ($ret['status'] === 'posted') {
                // إعادة الكمية للمخزون (عكس النقصان)
                $stItems = $pdo->prepare("SELECT * FROM `{$TRI}` WHERE return_id=?");
                $stItems->execute([$retId]);
                $wid = (int) ($ret['warehouse_id'] ?? 0);
                foreach ($stItems->fetchAll(PDO::FETCH_ASSOC) as $it) {
                    if (!$it['variant_id'])
                        continue;
                    $pdo->prepare("UPDATE `{$TWI}` SET quantity=quantity+?, last_movement_at=NOW()
                        WHERE variant_id=? AND warehouse_id=?")
                        ->execute([$it['quantity_returned'], $it['variant_id'], $wid]);
                }
                // عكس القيد
                if ($ret['journal_entry_id']) {
                    $stJI = $pdo->prepare("SELECT ji.*, ac.account_type FROM `{$TJI}` ji
                        JOIN `{$TAC}` ac ON ac.id = ji.account_id
                        WHERE ji.journal_entry_id=?");
                    $stJI->execute([$ret['journal_entry_id']]);
                    foreach ($stJI->fetchAll(PDO::FETCH_ASSOC) as $ji) {
                        // ⚠ نفس إصلاح confirm_purchase_invoice.php: حسابات
                        // الالتزام (ذمة المورد) بالقاعدة المعاكسة الآن —
                        // عكس قيدها يحتاج معادلة معاكسة عن الأصول/المصاريف.
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
                        ->execute([$_SESSION['user_id'], $ret['journal_entry_id']]);
                }
            }
            $pdo->prepare("UPDATE `{$TR}` SET status='cancelled',
                notes=CONCAT(COALESCE(notes,''),' | إلغاء: {$reason}')
                WHERE id=?")
                ->execute([$retId]);
            $pdo->commit();
            ob_get_clean();
            echo json_encode(['ok' => true, 'msg' => 'تم إلغاء المرتجع وعكس جميع التأثيرات']);
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
