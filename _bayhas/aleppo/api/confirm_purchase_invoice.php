<?php
/**
 * api/confirm_purchase_invoice.php — تأكيد فاتورة الشراء
 * المسار: /bayhas/aleppo/api/confirm_purchase_invoice.php
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
    requirePermission('purchases.invoices','confirm');

    $TS   = $_SESSION['table_suffix'];
    $TP   = "purchases_{$TS}";
    $TPI  = "purchase_items_{$TS}";
    $TWI  = "warehouse_items_{$TS}";
    $TIM  = "inventory_movements_{$TS}";
    $TIMD = "inventory_movement_details_{$TS}";
    $TAC  = "account_charts_{$TS}";
    $TJE  = "journal_entries_{$TS}";
    $TJI  = "journal_entry_items_{$TS}";
    $TIAS = "invoice_account_settings_{$TS}";
    $TSP  = "product_suppliers_{$TS}";

    $act = $_POST['_action'] ?? '';

    // ── تأكيد فاتورة الشراء ──
    if ($act === 'confirm') {
        $purId = (int)($_POST['invoice_id'] ?? 0);
        if (!$purId) throw new Exception('رقم الفاتورة مطلوب');

        // جلب الفاتورة
        $stPur = $pdo->prepare("SELECT p.*, s.name AS supplier_name
            FROM `{$TP}` p
            LEFT JOIN product_suppliers_{$TS} s ON s.id=p.supplier_id
            WHERE p.id=?");
        $stPur->execute([$purId]);
        $pur = $stPur->fetch(PDO::FETCH_ASSOC);
        if (!$pur) throw new Exception('الفاتورة غير موجودة');
        if ($pur['status'] !== 'draft') throw new Exception('يمكن تأكيد المسودات فقط (الحالة: '.$pur['status'].')');

        // جلب البنود
        $stItems = $pdo->prepare("SELECT * FROM `{$TPI}` WHERE purchase_id=?");
        $stItems->execute([$purId]);
        $items = $stItems->fetchAll(PDO::FETCH_ASSOC);
        if (empty($items)) throw new Exception('الفاتورة لا تحتوي بنوداً');

        $pdo->beginTransaction();
        try {
            $finalUsd    = (float)($pur['final_amount_usd'] ?? $pur['final_amount']);
            $rate        = (float)($pur['exchange_rate'] ?? 1);
            $curCode     = $pur['currency'] ?? 'USD';
            $supName     = $pur['supplier_name'] ?? 'مورد';
            $receiveDate = $_POST['receive_date'] ?? date('Y-m-d');
            $warehouseId = (int)($_POST['warehouse_id'] ?? 0) ?: null;
            $shippingCost= (float)($_POST['shipping_cost'] ?? 0);
            $shippingCur = $_POST['shipping_currency'] ?? $curCode;
            $shippingDesc      = trim($_POST['shipping_desc'] ?? '');
            $shippingCarrierId = (int)($_POST['shipping_carrier_id'] ?? 0) ?: null;
            $shippingPayMethod = $_POST['shipping_pay_method'] ?? 'cash'; // cash | credit
            $shippingCashAccId = (int)($_POST['shipping_cash_account'] ?? 0) ?: null;
            $shippingPayableId = (int)($_POST['shipping_payable_id'] ?? 0) ?: null;
            $paidOrigAmt = (float)($_POST['paid_amount'] ?? 0);
            $paidCur     = $_POST['paid_currency'] ?? $curCode;
            $paidRate    = max(0.000001,(float)($_POST['paid_rate'] ?? 1));
            // تحويل للعملة الأساسية للفاتورة
            $paidAmt     = ($paidCur===$curCode) ? $paidOrigAmt : round($paidOrigAmt/$paidRate,4);
            $cashAccId   = (int)($_POST['cash_account_id'] ?? 0) ?: null;
            $notes       = trim($_POST['notes'] ?? '');

            // رفع صورة الفاتورة
            $imgPath = null;
            if (!empty($_FILES['invoice_image']['tmp_name'])) {
                $uploadDir = __DIR__.'/../../uploads/purchase_invoices/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $ext = pathinfo($_FILES['invoice_image']['name'], PATHINFO_EXTENSION);
                $imgName = 'PUR-'.$purId.'-'.time().'.'.$ext;
                move_uploaded_file($_FILES['invoice_image']['tmp_name'], $uploadDir.$imgName);
                $imgPath = 'uploads/purchase_invoices/'.$imgName;
            }

            // ── إضافة للمخزون ──
            $movNo = 'MOV-IN-'.date('Ymd').'-'.str_pad($purId,5,'0',STR_PAD_LEFT);
            $totalQty = array_sum(array_column($items,'quantity'));

            $pdo->prepare("INSERT INTO `{$TIM}`
                (movement_number,movement_type,warehouse_id,items_count,total_quantity,
                 total_value_usd,reference_type,reference_id,reference_number,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$movNo,'in',$items[0]['warehouse_id']??null,
                    count($items),$totalQty,$finalUsd,
                    'purchase',$purId,$pur['purchase_number'],$_SESSION['user_id']]);
            $movId = (int)$pdo->lastInsertId();

            foreach ($items as $item) {
                if (!$item['variant_id']) continue;
                $wid    = (int)$item['warehouse_id'];
                $qty    = (float)$item['quantity'];
                $unitUsd= (float)($item['unit_price_usd'] ?? ($item['unit_price']/$rate));

                // جلب الكمية الحالية
                $stB = $pdo->prepare("SELECT quantity FROM `{$TWI}`
                    WHERE variant_id=? AND warehouse_id=?");
                $stB->execute([$item['variant_id'],$wid]);
                $before = (float)($stB->fetchColumn() ?? 0);
                $after  = $before + $qty;

                // UPSERT في warehouse_items
                $pdo->prepare("INSERT INTO `{$TWI}`
                    (warehouse_id,variant_id,product_id,quantity,last_movement_at)
                    VALUES (?,?,?,?,NOW())
                    ON DUPLICATE KEY UPDATE
                    quantity=quantity+?,last_movement_at=NOW()")
                    ->execute([$wid,$item['variant_id'],$item['product_id']??0,$qty,$qty]);

                // تفاصيل الحركة
                $pdo->prepare("INSERT INTO `{$TIMD}`
                    (movement_id,variant_id,product_id,quantity,unit_price,cost_price,
                     total_value,balance_before,balance_after)
                    VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$movId,$item['variant_id'],$item['product_id']??0,
                        $qty,$item['unit_price'],$unitUsd,$unitUsd*$qty,$before,$after]);

                // تحديث cost_price_usd في بنود فاتورة البيع (للمخزون القادم)
                if ($item['unit_price_usd'] === null) {
                    $pdo->prepare("UPDATE `{$TPI}` SET unit_price_usd=? WHERE id=?")
                        ->execute([$unitUsd,$item['id']]);
                }
            }

            // ── جلب حسابات الربط ──
            $getAcc = function(string $key) use ($pdo,$TIAS,$TAC): ?array {
                $st=$pdo->prepare("SELECT ac.* FROM `{$TIAS}` i
                    JOIN `{$TAC}` ac ON ac.id=i.account_id
                    WHERE i.setting_key=? LIMIT 1");
                $st->execute([$key]);
                return $st->fetch(PDO::FETCH_ASSOC)?:null;
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
            if (!$accSupplier) $accSupplier = $getAcc('supplier_payable');

            $accInventory = $getAcc('finished_inventory');

            if (!$accSupplier || !$accInventory)
                throw new Exception('يرجى ضبط حساب ذمة للمورد أو إعدادات الربط المحاسبي العامة (ذمم الموردين + المخزون)');

            // ── قيد محاسبي: مدين المخزون / دائن ذمم الموردين ──
            $y = date('Y');
            // دالة مساعدة: تولّد رقم القيد التالي الفعلي في كل استدعاء (تتجنب التعارض)
            $nextJeNo = function() use ($pdo, $TJE, $y): string {
                $last = $pdo->query("SELECT entry_number FROM `{$TJE}`
                    WHERE entry_number LIKE 'JE-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
                $seq = $last ? (int)substr($last, -4) + 1 : 1;
                return 'JE-'.$y.'-'.str_pad($seq, 4, '0', STR_PAD_LEFT);
            };
            $jeNo = $nextJeNo();

            $pdo->prepare("INSERT INTO `{$TJE}`
                (entry_number,entry_date,description,currency,exchange_rate,
                 total_debit,total_credit,status,reference_type,reference_id,created_by)
                VALUES (?,?,?,?,?,?,?,'posted','purchase',?,?)")
                ->execute([$jeNo,date('Y-m-d'),
                    "شراء فاتورة {$pur['purchase_number']} — {$supName}",
                    'USD',1,$finalUsd,$finalUsd,$purId,$_SESSION['user_id']]);
            $jeId = (int)$pdo->lastInsertId();

            // مدين: المخزون
            $pdo->prepare("INSERT INTO `{$TJI}`
                (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency,exchange_rate)
                VALUES (?,?,?,0,?,?,?,?,?)")
                ->execute([$jeId,$accInventory['id'],$finalUsd,
                    (float)$pur['final_amount'],$finalUsd,
                    "مخزون {$pur['purchase_number']}",$curCode,$rate]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                ->execute([$finalUsd,$finalUsd,$accInventory['id']]);

            // دائن: ذمم الموردين
            $pdo->prepare("INSERT INTO `{$TJI}`
                (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency,exchange_rate)
                VALUES (?,?,0,?,?,?,?,?,?)")
                ->execute([$jeId,$accSupplier['id'],$finalUsd,
                    (float)$pur['final_amount'],$finalUsd,
                    "ذمة {$supName}",$curCode,$rate]);
            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                ->execute([$finalUsd,$finalUsd,$accSupplier['id']]);

            // ── خصم الدفعة المقدمة تلقائياً إن وُجد رصيد للمورد ──
            $advanceApplied = 0;
            if (!empty($pur['supplier_id'])) {
                $stPrepaid = $pdo->prepare("SELECT ac.* FROM `{$TSP}` s
                    JOIN `{$TAC}` ac ON ac.id=s.prepaid_account_id
                    WHERE s.id=? AND s.prepaid_account_id IS NOT NULL LIMIT 1");
                $stPrepaid->execute([$pur['supplier_id']]);
                $accPrepaid = $stPrepaid->fetch(PDO::FETCH_ASSOC);

                if ($accPrepaid && (float)$accPrepaid['base_balance'] > 0) {
                    $advanceBalance = (float)$accPrepaid['base_balance'];
                    $advanceApplied = min($advanceBalance, $finalUsd);

                    if ($advanceApplied > 0) {
                        $jeAdvNo = $nextJeNo();
                        $pdo->prepare("INSERT INTO `{$TJE}`
                            (entry_number,entry_date,description,currency,exchange_rate,
                             total_debit,total_credit,status,reference_type,reference_id,created_by)
                            VALUES (?,?,?,'USD',1,?,?,'posted','purchase_advance_applied',?,?)")
                            ->execute([$jeAdvNo,date('Y-m-d'),
                                "تطبيق دفعة مقدمة على فاتورة {$pur['purchase_number']} — {$supName}",
                                $advanceApplied,$advanceApplied,$purId,$_SESSION['user_id']]);
                        $jeAdvId=(int)$pdo->lastInsertId();

                        // مدين: ذمم الموردين (تقليل الذمة)
                        $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency,exchange_rate)
                            VALUES (?,?,?,0,?,?,'تطبيق دفعة مقدمة','USD',1)")
                            ->execute([$jeAdvId,$accSupplier['id'],$advanceApplied,$advanceApplied,$advanceApplied]);
                        $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                            ->execute([$advanceApplied,$advanceApplied,$accSupplier['id']]);

                        // دائن: الدفعات المقدمة (تصفير الرصيد المستخدم)
                        $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency,exchange_rate)
                            VALUES (?,?,0,?,?,?,'تطبيق دفعة مقدمة','USD',1)")
                            ->execute([$jeAdvId,$accPrepaid['id'],$advanceApplied,$advanceApplied,$advanceApplied]);
                        $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                            ->execute([$advanceApplied,$advanceApplied,$accPrepaid['id']]);
                    }
                }
            }

            // ── تسجيل تكاليف الشحن (قيد منفصل) ──
            if ($shippingCost > 0) {
                $accShipExp=$getAcc('shipping_expense')?:$getAcc('consumable_expense');
                if ($accShipExp) {
                    $jeShip=$nextJeNo();

                    if ($shippingPayMethod==='cash' && $shippingCashAccId) {
                        // دفع نقدي: مدين مصاريف شحن / دائن الصندوق
                        $stCA=$pdo->prepare("SELECT * FROM `{$TAC}` WHERE id=?");
                        $stCA->execute([$shippingCashAccId]);
                        $cashAcc2=$stCA->fetch(PDO::FETCH_ASSOC);
                        if($cashAcc2){
                            $pdo->prepare("INSERT INTO `{$TJE}`
                                (entry_number,entry_date,description,currency,exchange_rate,
                                 total_debit,total_credit,status,reference_type,reference_id,created_by)
                                VALUES (?,?,?,?,1,?,?,'posted','purchase_shipping',?,?)")
                                ->execute([$jeShip,date('Y-m-d'),
                                    "أجور شحن {$pur['purchase_number']} — {$shippingDesc}",
                                    $shippingCurrency??'USD',$shippingCost,$shippingCost,
                                    $purId,$_SESSION['user_id']]);
                            $jeShipId=(int)$pdo->lastInsertId();
                            // مدين: مصاريف الشحن
                            $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency,exchange_rate)
                                VALUES (?,?,?,0,?,?,'أجور شحن','USD',1)")
                                ->execute([$jeShipId,$accShipExp['id'],$shippingCost,$shippingCost,$shippingCost]);
                            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                                ->execute([$shippingCost,$shippingCost,$accShipExp['id']]);
                            // دائن: الصندوق
                            $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency,exchange_rate)
                                VALUES (?,?,0,?,?,?,'دفع أجور شحن','USD',1)")
                                ->execute([$jeShipId,$shippingCashAccId,$shippingCost,$shippingCost,$shippingCost]);
                            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                                ->execute([$shippingCost,$shippingCost,$shippingCashAccId]);
                        }
                    } else {
                        // آجل: مدين مصاريف شحن / دائن ذمة شركة الشحن
                        $payableId=$shippingPayableId??null;
                        if(!$payableId){
                            // جلب حساب ذمة الشركة من shipping_carriers
                            if($shippingCarrierId){
                                $scSt=$pdo->prepare("SELECT payable_account_id FROM shipping_carriers WHERE id=?");
                                $scSt->execute([$shippingCarrierId]);
                                $payableId=(int)($scSt->fetchColumn()?:0)?:null;
                            }
                        }
                        if($payableId){
                            $pdo->prepare("INSERT INTO `{$TJE}`
                                (entry_number,entry_date,description,currency,exchange_rate,
                                 total_debit,total_credit,status,reference_type,reference_id,created_by)
                                VALUES (?,?,?,'USD',1,?,?,'posted','purchase_shipping',?,?)")
                                ->execute([$jeShip,date('Y-m-d'),
                                    "أجور شحن آجل {$pur['purchase_number']} — {$shippingDesc}",
                                    $shippingCost,$shippingCost,$purId,$_SESSION['user_id']]);
                            $jeShipId=(int)$pdo->lastInsertId();
                            // مدين: مصاريف الشحن
                            $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency,exchange_rate)
                                VALUES (?,?,?,0,?,?,'أجور شحن آجل','USD',1)")
                                ->execute([$jeShipId,$accShipExp['id'],$shippingCost,$shippingCost,$shippingCost]);
                            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                                ->execute([$shippingCost,$shippingCost,$accShipExp['id']]);
                            // دائن: ذمة شركة الشحن
                            $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency,exchange_rate)
                                VALUES (?,?,0,?,?,?,'ذمة شحن آجل','USD',1)")
                                ->execute([$jeShipId,$payableId,$shippingCost,$shippingCost,$shippingCost]);
                            $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                                ->execute([$shippingCost,$shippingCost,$payableId]);
                        }
                    }
                }
            }

            // ── تسجيل الدفع الجزئي ──
            $paidStatus = 'pending';
            if ($paidAmt > 0 && $cashAccId) {
                $stCash=$pdo->prepare("SELECT * FROM `{$TAC}` WHERE id=?");
                $stCash->execute([$cashAccId]);
                $cashAcc=$stCash->fetch(PDO::FETCH_ASSOC);
                if ($cashAcc) {
                    $jePayNo=$nextJeNo();
                    $pdo->prepare("INSERT INTO `{$TJE}`
                        (entry_number,entry_date,description,currency,exchange_rate,
                         total_debit,total_credit,status,reference_type,reference_id,created_by)
                        VALUES (?,?,?,'USD',1,?,?,'posted','purchase_payment',?,?)")
                        ->execute([$jePayNo,date('Y-m-d'),
                            "دفعة فاتورة {$pur['purchase_number']}",
                            $paidAmt,$paidAmt,$purId,$_SESSION['user_id']]);
                    $jePayId=(int)$pdo->lastInsertId();
                    // مدين: ذمم الموردين (بعملة الفاتورة)
                    $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency,exchange_rate)
                        VALUES (?,?,?,0,?,?,?,?,?)")
                        ->execute([$jePayId,$accSupplier['id'],$paidAmt,
                            $paidOrigAmt,$paidAmt,"دفع للمورد",$paidCur,$paidRate]);
                    $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                        ->execute([$paidAmt,$paidOrigAmt,$accSupplier['id']]);
                    // دائن: الصندوق (بعملته الأصلية)
                    $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency,exchange_rate)
                        VALUES (?,?,0,?,?,?,?,?,?)")
                        ->execute([$jePayId,$cashAccId,$paidAmt,
                            $paidOrigAmt,$paidAmt,"دفع للمورد",$paidCur,$paidRate]);
                    $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance+?,balance=balance+? WHERE id=?")
                        ->execute([$paidAmt,$paidOrigAmt,$cashAccId]);
                    $paidStatus = $paidAmt >= $finalUsd ? 'paid' : 'partial';
                }
            }

            // ── تحديث الفاتورة (يشمل الدفع النقدي + الدفعة المقدمة المطبَّقة) ──
            $totalPaidIncAdvance = $paidAmt + $advanceApplied;
            $finalPaidStatus = $totalPaidIncAdvance >= $finalUsd ? 'paid'
                : ($totalPaidIncAdvance > 0 ? 'partial' : 'pending');

            $pdo->prepare("UPDATE `{$TP}` SET
                status='received',
                paid_amount=?,
                balance_amount=final_amount-?,
                payment_status=?,
                journal_entry_id=?,
                notes=CONCAT(COALESCE(notes,''),?),
                updated_by=?
                WHERE id=?")
                ->execute([$totalPaidIncAdvance,$totalPaidIncAdvance,$finalPaidStatus,$jeId,
                    $notes?' | '.$notes:'',
                    $_SESSION['user_id'],$purId]);

            $pdo->commit();
            ob_get_clean();
            $msg = 'تم تأكيد الفاتورة وإضافة المخزون والقيد المحاسبي';
            if ($advanceApplied > 0) $msg .= " — تم تطبيق دفعة مقدمة بقيمة {$advanceApplied}$";
            echo json_encode([
                'ok'      => true,
                'msg'     => $msg,
                'je_no'   => $jeNo,
                'movement'=> $movNo,
                'status'  => 'received',
                'paid'    => $paidAmt,
                'advance_applied' => $advanceApplied,
                'img'     => $imgPath,
            ]);

        } catch(Exception $e){ $pdo->rollBack(); throw $e; }
    }

    // ── إلغاء فاتورة الشراء ──
    elseif ($act === 'cancel') {
        $purId  = (int)($_POST['invoice_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        $stPur = $pdo->prepare("SELECT * FROM `{$TP}` WHERE id=?");
        $stPur->execute([$purId]);
        $pur = $stPur->fetch(PDO::FETCH_ASSOC);
        if (!$pur) throw new Exception('الفاتورة غير موجودة');
        if ($pur['status'] === 'cancelled') throw new Exception('ملغاة مسبقاً');

        $pdo->beginTransaction();
        try {
            // عكس المخزون إذا كانت مستلمة
            if ($pur['status'] === 'received') {
                $stItems = $pdo->prepare("SELECT * FROM `{$TPI}` WHERE purchase_id=?");
                $stItems->execute([$purId]);
                foreach ($stItems->fetchAll(PDO::FETCH_ASSOC) as $item) {
                    if (!$item['variant_id']) continue;
                    $pdo->prepare("UPDATE `{$TWI}` SET quantity=GREATEST(0,quantity-?),last_movement_at=NOW()
                        WHERE variant_id=? AND warehouse_id=?")
                        ->execute([$item['quantity'],$item['variant_id'],$item['warehouse_id']]);
                }
                // عكس القيد
                if ($pur['journal_entry_id']) {
                    $stJI=$pdo->prepare("SELECT * FROM `{$TJI}` WHERE journal_entry_id=?");
                    $stJI->execute([$pur['journal_entry_id']]);
                    foreach ($stJI->fetchAll(PDO::FETCH_ASSOC) as $ji){
                        $net=$ji['debit']-$ji['credit'];
                        $pdo->prepare("UPDATE `{$TAC}` SET base_balance=base_balance-?,balance=balance-? WHERE id=?")
                            ->execute([$net,$net,$ji['account_id']]);
                    }
                    $pdo->prepare("UPDATE `{$TJE}` SET status='cancelled',cancelled_at=NOW(),cancelled_by=? WHERE id=?")
                        ->execute([$_SESSION['user_id'],$pur['journal_entry_id']]);
                }
            }
            $pdo->prepare("UPDATE `{$TP}` SET status='cancelled',
                notes=CONCAT(COALESCE(notes,''),' | إلغاء: {$reason}'),
                updated_by=? WHERE id=?")
                ->execute([$_SESSION['user_id'],$purId]);
            $pdo->commit();
            ob_get_clean();
            echo json_encode(['ok'=>true,'msg'=>'تم إلغاء الفاتورة وعكس جميع التأثيرات']);
        } catch(Exception $e){ $pdo->rollBack(); throw $e; }
    }

    else throw new Exception('إجراء غير معروف');

} catch(Throwable $e){
    ob_end_clean();
    http_response_code(200);
    echo json_encode(['ok'=>false,'msg'=>$e->getMessage(),'line'=>$e->getLine()]);
}
