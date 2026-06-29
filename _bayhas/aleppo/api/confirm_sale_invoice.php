<?php
/**
 * api/confirm_sale_invoice.php — تأكيد فاتورة البيع
 * المسار: /bayhas/aleppo/api/confirm_sale_invoice.php
 */
ob_start();
ini_set('display_errors',0);
header('Content-Type: application/json; charset=utf-8');

try {
    session_start();
    if(!isset($_SESSION['user_id'])){echo json_encode(['ok'=>false,'msg'=>'غير مسجل']);exit;}
    require_once __DIR__.'/../../config/database.php';
    require_once __DIR__.'/../../config/auth.php';

    $pdo = getConnection();
    requirePermission('sales.invoices','confirm');

    $TS   = $_SESSION['table_suffix'];
    $TSI  = "sales_invoices_{$TS}";
    $TSII = "sales_invoice_items_{$TS}";
    $TWI  = "warehouse_items_{$TS}";
    $TIM  = "inventory_movements_{$TS}";
    $TIMD = "inventory_movement_details_{$TS}";
    $TAC  = "account_charts_{$TS}";
    $TJE  = "journal_entries_{$TS}";
    $TJI  = "journal_entry_items_{$TS}";
    $TIAS = "invoice_account_settings_{$TS}";

    $act = $_POST['_action'] ?? '';

    if ($act === 'confirm') {
        $invId = (int)($_POST['invoice_id'] ?? 0);
        if (!$invId) throw new Exception('رقم الفاتورة مطلوب');

        // جلب الفاتورة
        $stInv = $pdo->prepare("SELECT * FROM `{$TSI}` WHERE id=?");
        $stInv->execute([$invId]);
        $inv = $stInv->fetch(PDO::FETCH_ASSOC);
        if (!$inv) throw new Exception('الفاتورة غير موجودة');
        if ($inv['status'] === 'confirmed') throw new Exception('الفاتورة مؤكدة مسبقاً');
        if ($inv['status'] === 'cancelled') throw new Exception('الفاتورة ملغاة');

        // جلب بنود الفاتورة
        $stItems = $pdo->prepare("SELECT * FROM `{$TSII}` WHERE invoice_id=?");
        $stItems->execute([$invId]);
        $items = $stItems->fetchAll(PDO::FETCH_ASSOC);
        if (empty($items)) throw new Exception('الفاتورة لا تحتوي بنوداً');

        // ── التحقق من المخزون أولاً ──
        foreach ($items as $item) {
            if (!$item['variant_id']) continue;
            $stStock = $pdo->prepare("SELECT quantity FROM `{$TWI}`
                WHERE variant_id=? AND warehouse_id=?");
            $stStock->execute([$item['variant_id'], $item['warehouse_id']]);
            $stock = (float)($stStock->fetchColumn() ?? 0);
            if ($stock < $item['quantity']) {
                throw new Exception(
                    "مخزون غير كافٍ: {$item['item_name']} (متوفر: {$stock}، مطلوب: {$item['quantity']})"
                );
            }
        }

        $pdo->beginTransaction();
        try {
            // ── إنشاء حركة مخزون رئيسية ──
            $movNo = 'MOV-OUT-'.date('Ymd').'-'.str_pad($invId,5,'0',STR_PAD_LEFT);
            $totalQty   = array_sum(array_column($items,'quantity'));
            $totalValue = (float)$inv['cost_total'];

            $pdo->prepare("INSERT INTO `{$TIM}`
                (movement_number,movement_type,warehouse_id,items_count,total_quantity,
                 total_value_usd,reference_type,reference_id,reference_number,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$movNo,'out',$items[0]['warehouse_id']??null,
                    count($items),$totalQty,$totalValue,
                    'sale_invoice',$invId,$inv['invoice_number'],$_SESSION['user_id']]);
            $movId = (int)$pdo->lastInsertId();

            $totalCogs = 0;
            foreach ($items as $item) {
                if (!$item['variant_id']) continue;
                $wid = (int)$item['warehouse_id'];
                $qty = (float)$item['quantity'];

                // جلب الكمية قبل الخصم
                $stB = $pdo->prepare("SELECT quantity FROM `{$TWI}` WHERE variant_id=? AND warehouse_id=?");
                $stB->execute([$item['variant_id'],$wid]);
                $before = (float)($stB->fetchColumn() ?? 0);
                $after  = max(0, $before - $qty);

                // خصم من المخزون
                $pdo->prepare("UPDATE `{$TWI}` SET quantity=?,last_movement_at=NOW()
                    WHERE variant_id=? AND warehouse_id=?")
                    ->execute([$after,$item['variant_id'],$wid]);

                // تفاصيل الحركة
                $costUsd = (float)($item['cost_price_usd'] ?? 0);
                $totalItemVal = $costUsd * $qty;
                $totalCogs += $totalItemVal;

                $pdo->prepare("INSERT INTO `{$TIMD}`
                    (movement_id,variant_id,product_id,quantity,unit_price,cost_price,
                     total_value,balance_before,balance_after)
                    VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$movId,$item['variant_id'],$item['product_id']??0,
                        $qty,$item['unit_price'],$costUsd,$totalItemVal,$before,$after]);
            }

            // ── جلب حسابات الربط ──
            $getAcc = function(string $key) use ($pdo,$TIAS,$TAC): ?array {
                $st=$pdo->prepare("SELECT ac.* FROM `{$TIAS}` i
                    JOIN `{$TAC}` ac ON ac.id=i.account_id
                    WHERE i.setting_key=? LIMIT 1");
                $st->execute([$key]);
                return $st->fetch(PDO::FETCH_ASSOC)?:null;
            };

            $accCustomer  = $getAcc('customer_receivable');
            $accRevenue   = $getAcc('sales_revenue');
            $accCogs      = $getAcc('cogs');
            $accInventory = $getAcc('finished_inventory');

            if (!$accCustomer || !$accRevenue)
                throw new Exception('يرجى ضبط إعدادات الربط المحاسبي أولاً (ذمم العملاء + إيرادات المبيعات)');

            $totalUsd  = (float)$inv['total_amount'];
            $cogsUsd   = $totalCogs ?: (float)$inv['cost_total'];
            $rate      = (float)($inv['exchange_rate'] ?? 1);
            $curCode   = $inv['currency'] ?? 'USD';

            // ── قيد 1: مدين العميل / دائن الإيرادات ──
            $y    = date('Y');
            $last = $pdo->query("SELECT entry_number FROM `{$TJE}`
                WHERE entry_number LIKE 'JE-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
            $seq  = $last ? (int)substr($last,-4)+1 : 1;
            $jeNo1 = 'JE-'.$y.'-'.str_pad($seq,4,'0',STR_PAD_LEFT);

            $pdo->prepare("INSERT INTO `{$TJE}`
                (entry_number,entry_date,description,currency,exchange_rate,
                 total_debit,total_credit,status,reference_type,reference_id,created_by)
                VALUES (?,?,?,?,?,?,?,'posted','sale_invoice',?,?)")
                ->execute([$jeNo1,date('Y-m-d'),
                    "إيرادات فاتورة بيع {$inv['invoice_number']} — {$inv['customer_name']}",
                    $curCode,$rate,$totalUsd,$totalUsd,$invId,$_SESSION['user_id']]);
            $je1Id = (int)$pdo->lastInsertId();

            // مدين: ذمم العملاء
            $pdo->prepare("INSERT INTO `{$TJI}`
                (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency,exchange_rate)
                VALUES (?,?,?,0,?,?,?,?,?)")
                ->execute([$je1Id,$accCustomer['id'],$totalUsd,$totalUsd*$rate,
                    $totalUsd,"ذمة {$inv['customer_name']}",$curCode,$rate]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                ->execute([$totalUsd,$totalUsd*$rate,$accCustomer['id']]);

            // دائن: إيرادات المبيعات
            $pdo->prepare("INSERT INTO `{$TJI}`
                (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency,exchange_rate)
                VALUES (?,?,0,?,?,?,?,?,?)")
                ->execute([$je1Id,$accRevenue['id'],$totalUsd,$totalUsd*$rate,
                    $totalUsd,"إيراد {$inv['invoice_number']}",$curCode,$rate]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                ->execute([$totalUsd,$totalUsd*$rate,$accRevenue['id']]);

            // ── قيد 2: COGS (إذا عندنا حسابات المخزون) ──
            if ($accCogs && $accInventory && $cogsUsd > 0) {
                $seq++;
                $jeNo2 = 'JE-'.$y.'-'.str_pad($seq,4,'0',STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO `{$TJE}`
                    (entry_number,entry_date,description,currency,exchange_rate,
                     total_debit,total_credit,status,reference_type,reference_id,created_by)
                    VALUES (?,?,?,?,?,?,?,'posted','sale_invoice_cogs',?,?)")
                    ->execute([$jeNo2,date('Y-m-d'),
                        "تكلفة بضاعة مباعة {$inv['invoice_number']}",
                        'USD',1,$cogsUsd,$cogsUsd,$invId,$_SESSION['user_id']]);
                $je2Id = (int)$pdo->lastInsertId();

                // مدين: COGS
                $pdo->prepare("INSERT INTO `{$TJI}`
                    (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency,exchange_rate)
                    VALUES (?,?,?,0,?,?,?,?,1)")
                    ->execute([$je2Id,$accCogs['id'],$cogsUsd,$cogsUsd,$cogsUsd,"COGS {$inv['invoice_number']}",'USD']);
                $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                    ->execute([$cogsUsd,$cogsUsd,$accCogs['id']]);

                // دائن: المخزون
                $pdo->prepare("INSERT INTO `{$TJI}`
                    (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency,exchange_rate)
                    VALUES (?,?,0,?,?,?,?,?,1)")
                    ->execute([$je2Id,$accInventory['id'],$cogsUsd,$cogsUsd,$cogsUsd,"مخزون {$inv['invoice_number']}",'USD']);
                $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                    ->execute([$cogsUsd,$cogsUsd,$accInventory['id']]);
            }

            // ── تحديث حالة الفاتورة ──
            $pdo->prepare("UPDATE `{$TSI}` SET
                status='confirmed',
                confirmed_at=NOW(),
                confirmed_by=?,
                balance_amount=total_amount,
                payment_status='pending'
                WHERE id=?")
                ->execute([$_SESSION['user_id'],$invId]);

            $pdo->commit();
            $out = ob_get_clean();
            echo json_encode([
                'ok'       => true,
                'msg'      => 'تم تأكيد الفاتورة وتسجيل القيود المحاسبية',
                'je_count' => $cogsUsd>0 ? 2 : 1,
                'je1'      => $jeNo1,
                'je2'      => isset($jeNo2) ? $jeNo2 : null,
                'movement' => $movNo,
            ]);

        } catch(Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ── إلغاء فاتورة ──
    elseif ($act === 'cancel') {
        $invId = (int)($_POST['invoice_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        $stInv = $pdo->prepare("SELECT * FROM `{$TSI}` WHERE id=?");
        $stInv->execute([$invId]);
        $inv = $stInv->fetch(PDO::FETCH_ASSOC);
        if (!$inv) throw new Exception('الفاتورة غير موجودة');
        if ($inv['status'] === 'cancelled') throw new Exception('ملغاة مسبقاً');
        if ($inv['status'] === 'paid') throw new Exception('لا يمكن إلغاء فاتورة مدفوعة بالكامل');

        $pdo->beginTransaction();
        try {
            // إعادة المخزون إذا كانت مؤكدة
            if ($inv['status'] === 'confirmed') {
                $stItems = $pdo->prepare("SELECT * FROM `{$TSII}` WHERE invoice_id=?");
                $stItems->execute([$invId]);
                foreach ($stItems->fetchAll(PDO::FETCH_ASSOC) as $item) {
                    if (!$item['variant_id']) continue;
                    $pdo->prepare("UPDATE `{$TWI}` SET quantity=quantity+?,last_movement_at=NOW()
                        WHERE variant_id=? AND warehouse_id=?")
                        ->execute([$item['quantity'],$item['variant_id'],$item['warehouse_id']]);
                }
                // عكس القيود المحاسبية
                $stJE = $pdo->prepare("SELECT * FROM `{$TJE}`
                    WHERE reference_type IN ('sale_invoice','sale_invoice_cogs')
                    AND reference_id=? AND status='posted'");
                $stJE->execute([$invId]);
                foreach ($stJE->fetchAll(PDO::FETCH_ASSOC) as $je) {
                    $stJI=$pdo->prepare("SELECT * FROM `{$TJI}` WHERE journal_entry_id=?");
                    $stJI->execute([$je['id']]);
                    foreach ($stJI->fetchAll(PDO::FETCH_ASSOC) as $ji){
                        $net=$ji['debit']-$ji['credit'];
                        $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                            ->execute([$net,$net,$ji['account_id']]);
                    }
                    $pdo->prepare("UPDATE `{$TJE}` SET status='cancelled',cancelled_at=NOW(),cancelled_by=? WHERE id=?")
                        ->execute([$_SESSION['user_id'],$je['id']]);
                }
            }
            $pdo->prepare("UPDATE `{$TSI}` SET status='cancelled',notes=CONCAT(COALESCE(notes,''),' | إلغاء: {$reason}') WHERE id=?")
                ->execute([$invId]);
            $pdo->commit();
            $out=ob_get_clean();
            echo json_encode(['ok'=>true,'msg'=>'تم إلغاء الفاتورة وعكس التأثيرات']);
        } catch(Exception $e){ $pdo->rollBack(); throw $e; }
    }

    else throw new Exception('إجراء غير معروف');

} catch(Throwable $e){
    ob_end_clean();
    http_response_code(200);
    echo json_encode(['ok'=>false,'msg'=>$e->getMessage(),'line'=>$e->getLine()]);
}
