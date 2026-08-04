<?php
/**
 * consumable_purchases.php — فواتير شراء المستهلكات
 *retail1/modules/inventory/consumable_purchases.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('purchases.consumable_purchases', 'view');
$currentModule = 'purchases.consumable_purchases'; // ✅ كانت غير معرّفة إطلاقاً — الشريط الجانبي ما كان يبيّن هالصفحة كنشطة

$TS = $_SESSION['table_suffix'];
$TI  = "consumable_items_{$TS}";
$TST = "consumable_stock_{$TS}";
$TM  = "consumable_movements_{$TS}";
$TP  = "consumable_purchases_{$TS}";
$TPI = "consumable_purchase_items_{$TS}";
$TW = "warehouses_{$TS}";
$TSP = "product_suppliers_{$TS}";
$TU  = "consumable_units_{$TS}";
$TPK = "consumable_item_packagings_{$TS}";
$TJE = "journal_entries_{$TS}";
$TJI = "journal_entry_items_{$TS}";
$TAS = "invoice_account_settings_{$TS}";
$TAC = "account_charts_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// ⚠ عملة الفرع الأساسية الحقيقية — تُستخدم بدل 'USD' المكتوبة حرفياً
// بقيود ترحيل/إلغاء شراء المستهلكات (قيود داخلية بحتة، لا تنطوي على
// تحويل عملة حقيقي — القيمة أصلاً محسوبة بعملة التقارير مسبقاً).
$branchBaseCurrency = 'USD';
if (!empty($_SESSION['branch_id'])) {
    $bcStmt = $pdo->prepare("SELECT base_currency FROM branches WHERE id = ?");
    $bcStmt->execute([$_SESSION['branch_id']]);
    $branchBaseCurrency = $bcStmt->fetchColumn() ?: 'USD';
}
// ⚠ journal_entries/journal_entry_items.currency_id هلق FK رقمي حقيقي
// لجدول currencies (كان varchar(3) رمز نصي) — بنحتاج نحوّل أي رمز
// عملة (رمز الفرع أو رمز الفاتورة) لرقمه قبل الكتابة بقيود GL
function resolveCurrencyId(PDO $pdo, string $code): int
{
    static $cache = [];
    if (isset($cache[$code]))
        return $cache[$code];
    $st = $pdo->prepare("SELECT id FROM currencies WHERE code = ? LIMIT 1");
    $st->execute([$code]);
    $id = (int) ($st->fetchColumn() ?: 0);
    if (!$id) // fallback أخير: عملة الفرع الأساسية لو الرمز مش موجود بالجدول لأي سبب
        $id = (int) $pdo->query("SELECT id FROM currencies WHERE is_base=1 LIMIT 1")->fetchColumn();
    $cache[$code] = $id;
    return $id;
}
$branchBaseCurrencyId = resolveCurrencyId($pdo, $branchBaseCurrency);

// ⚠ ترحيل فعلي لرصيد الحساب بشجرة الحسابات (account_charts.balance/
// base_balance) — كان القيد بينكتب بدفتر اليومية بس، بدون ما ينعكس
// فعلياً على الرصيد المخزّن بالحساب نفسه، رغم إنه هالنظام مصمم
// أصلاً يحتفظ برصيد مخزّن (بعكس بعض الأنظمة يلي بتحسبه لحظياً من
// الصفر كل مرة). كل حساب إله "طبيعة" (مدين أو دائن) — الرصيد بيزيد
// بنفس اتجاه طبيعته وينقص بعكسها:
// - أصول/مصاريف (asset/expense): طبيعتها مدين → الرصيد += (مدين - دائن)
// - التزامات/حقوق ملكية/إيرادات: طبيعتها دائن → الرصيد += (دائن - مدين)
function postAccountBalance(PDO $pdo, string $TAC, int $accountId, float $debit, float $credit): void
{
    $acc = $pdo->prepare("SELECT account_type, currency_id FROM `{$TAC}` WHERE id=?");
    $acc->execute([$accountId]);
    $row = $acc->fetch();
    if (!$row)
        return;
    $isDebitNormal = in_array($row['account_type'], ['asset', 'expense']);
    $delta = $isDebitNormal ? ($debit - $credit) : ($credit - $debit);
    // ⚠ base_balance دايماً بعملة التقارير (base currency) — نفس عملة
    // debit/credit الممرّرة هون أصلاً (محسوبة مسبقاً بعملة الفرع). أما
    // balance (عملة الحساب نفسه) فبنحدّثه بنفس القيمة مباشرة — صحيح
    // ١٠٠٪ لحسابات المستهلكات الفعلية (تأكدنا: currency_id عندها كلها
    // = عملة الفرع الأساسية بالفحص المباشر لشجرة الحسابات). لو حساب
    // بعملة مختلفة فعلاً (صندوق نقد أجنبي مثلاً)، هاد تحديث تقريبي بس
    // — تحويله الدقيق بعملته الأصلية مؤجّل لمحادثة المحاسبة العامة
    $pdo->prepare("UPDATE `{$TAC}` SET base_balance = base_balance + ?, balance = balance + ? WHERE id=?")
        ->execute([$delta, $delta, $accountId]);
}
// ⚠ رمز عملة الفرع الفعلي (٢.٠٠$ / ٢.٠٠ ل.س...) — للعرض بمودال تفاصيل
// الفاتورة بدل "$" مكتوب حرفياً (كان بيبيّن دولار حتى لو عملة الفرع شي تاني)
$baseCurSymSt = $pdo->prepare("SELECT symbol FROM currencies WHERE id = ? LIMIT 1");
$baseCurSymSt->execute([$branchBaseCurrencyId]);
$baseCurSymbol = $baseCurSymSt->fetchColumn() ?: $branchBaseCurrency;

// ── توليد رقم متسلسل ──────────────────────────────────────────────
function genNo(PDO $pdo, string $table, string $prefix): string
{
    $y = date('Y');
    $last = $pdo->query("SELECT invoice_no FROM `{$table}` ORDER BY id DESC LIMIT 1")->fetchColumn();
    $seq = $last ? (int) substr($last, -4) + 1 : 1;
    return "{$prefix}-{$y}-" . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

// ── تحديث المخزون (Weighted Average) ─────────────────────────────
function updateStock(PDO $pdo, string $TST, int $itemId, int $whId, float $qty, float $costBase): void
{
    $st = $pdo->prepare("SELECT quantity, avg_cost_base FROM `{$TST}` WHERE item_id=? AND warehouse_id=?");
    $st->execute([$itemId, $whId]);
    $cur = $st->fetch();
    if ($cur) {
        $oldQty = (float) $cur['quantity'];
        $newQty = $oldQty + $qty;
        $newCost = $newQty > 0
            ? (($oldQty * (float) $cur['avg_cost_base']) + ($qty * $costBase)) / $newQty
            : $costBase;
        $pdo->prepare("UPDATE `{$TST}` SET quantity=?, avg_cost_base=?, last_movement=NOW()
            WHERE item_id=? AND warehouse_id=?")
            ->execute([$newQty, $newCost, $itemId, $whId]);
    } else {
        $pdo->prepare("INSERT INTO `{$TST}` (item_id, warehouse_id, quantity, avg_cost_base, last_movement)
            VALUES (?,?,?,?,NOW())")
            ->execute([$itemId, $whId, $qty, $costBase]);
    }
}

// ── حفظ بنود فاتورة شراء مستهلكات + حركات معلقة (is_posted=0) ──
// ⚠ دالة مشتركة يستخدمها كل من save_purchase (فاتورة جديدة) و
// update_purchase (تعديل مسودة) — نفس منطق تحويل العبوة/الوحدة
// بالضبط، بمكان واحد بدل تكراره، تفادياً لأي تباين بين الاثنين
function savePurchaseRows(PDO $pdo, string $TM, string $TPI, string $TPK, int $purchaseId, int $whId, string $invDate, float $exRate, array $rows, int $userId): void
{
    foreach ($rows as $r) {
        $itemId = (int) $r['item_id'];
        $enteredQty = (float) $r['qty'];
        $enteredPrOrig = (float) $r['unit_price_orig'];
        $disc = (float) ($r['discount_pct'] ?? 0);
        $packagingId = (int) ($r['packaging_id'] ?? 0) ?: null;

        // ⚠ تحويل الكمية/السعر لوحدة المخزون — لو المستخدم اختار
        // عبوة (كرتونة...) بدل وحدة المخزون الأساسية. معامل
        // التحويل بيتقرا من قاعدة البيانات مباشرة (مش من قيمة
        // جاية من المتصفح) ومربوط بنفس المادة، تحسباً لأي تلاعب
        // أو خطأ بالواجهة.
        $factor = 1.0;
        if ($packagingId) {
            $pkSt = $pdo->prepare("SELECT qty_per_package FROM `{$TPK}` WHERE id=? AND item_id=? AND is_active=1");
            $pkSt->execute([$packagingId, $itemId]);
            $factor = (float) ($pkSt->fetchColumn() ?: 0);
            if ($factor <= 0)
                throw new Exception('عبوة غير صالحة لهذه المادة');
        }

        $qty = $enteredQty * $factor;                 // بوحدة المخزون — نفس معنى العمود quantity القديم، بدون أي تغيير على منطق التأكيد
        $unitPrOrig = $factor > 0 ? $enteredPrOrig / $factor : $enteredPrOrig; // سعر الوحدة الواحدة بالمخزون
        $unitPrBase = $unitPrOrig / $exRate;
        $totalRowOrig = $qty * $unitPrOrig * (1 - $disc / 100); // نفس القيمة رياضياً لو حسبناها بالكمية/السعر الأصليين المُدخلين
        $totalRowBase = $totalRowOrig / $exRate;

        // حركة معلقة
        $movNo = 'MOV-' . date('Y') . '-' . str_pad(
            (int) $pdo->query("SELECT COUNT(*)+1 FROM `{$TM}`")->fetchColumn(),
            5,
            '0',
            STR_PAD_LEFT
        );
        $pdo->prepare("INSERT INTO `{$TM}`
            (movement_no, item_id, warehouse_id, movement_type, direction,
             quantity, unit_cost_base, total_cost_base,
             qty_before, qty_after,
             reference_type, reference_id, movement_date, is_posted, created_by)
            VALUES (?,?,?,'receive','in',?,?,?,0,0,'purchase',?,?,0,?)")
            ->execute([
                $movNo,
                $itemId,
                $whId,
                $qty,
                $unitPrBase,
                $totalRowBase,
                $purchaseId,
                $invDate,
                $userId
            ]);
        $movId = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO `{$TPI}`
            (purchase_id, item_id, packaging_id, packaging_qty, quantity, unit_price_orig, unit_price_base,
             discount_pct, total_orig, total_base, movement_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $purchaseId,
                $itemId,
                $packagingId,
                $packagingId ? $enteredQty : null, // للعرض/التدقيق بس — الكمية الأصلية كما أُدخلت بوحدة العبوة
                $qty,
                $unitPrOrig,
                $unitPrBase,
                $disc,
                $totalRowOrig,
                $totalRowBase,
                $movId
            ]);
    }
}

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        // ── جلب فاتورة ──
        if ($act === 'get_purchase') {
            $id = (int) $_POST['id'];
            $st = $pdo->prepare("
                SELECT p.*, s.name AS supplier_name, w.name AS wh_name,
                    c.code AS currency_code, c.symbol AS currency_symbol
                FROM `{$TP}` p
                LEFT JOIN `{$TSP}` s ON s.id = p.supplier_id
                LEFT JOIN `{$TW}`  w ON w.id = p.warehouse_id
                LEFT JOIN currencies c ON c.code = p.currency
                WHERE p.id = ?");
            $st->execute([$id]);
            $pur = $st->fetch();
            if (!$pur)
                throw new Exception('الفاتورة غير موجودة');

            $it = $pdo->prepare("
                SELECT pi.*, ci.name AS item_name, cun.name AS unit,
                    ci.estimated_cost, ci.last_purchase_price_base,
                    cur.code AS item_currency_code, cur.symbol AS item_currency_symbol,
                    pk.name AS packaging_name, pk.qty_per_package
                FROM `{$TPI}` pi
                JOIN `{$TI}` ci ON ci.id = pi.item_id
                LEFT JOIN `{$TU}` cun ON cun.id = ci.unit_id
                LEFT JOIN currencies cur ON cur.id = ci.currency_id
                LEFT JOIN `{$TPK}` pk ON pk.id = pi.packaging_id
                WHERE pi.purchase_id = ?");
            $it->execute([$id]);
            $pur['items'] = $it->fetchAll();
            echo json_encode(['ok' => true, 'data' => $pur]);
        }

        // ── حفظ فاتورة (مسودة) ──
        elseif ($act === 'save_purchase') {
            requirePermission('purchases.consumable_purchases', 'create');

            $supplierId = (int) ($_POST['supplier_id'] ?? 0) ?: null;
            $whId = (int) ($_POST['warehouse_id'] ?? 0);
            $invDate = $_POST['invoice_date'] ?? date('Y-m-d');
            $suppRef = trim($_POST['supplier_ref'] ?? '');
            $payMethod = $_POST['payment_method'] ?? 'deferred';
            $notes = trim($_POST['notes'] ?? '');
            $currency = $_POST['currency'] ?? 'USD';
            $exRate = max(0.0001, (float) ($_POST['exchange_rate'] ?? 1));
            $discPct = (float) ($_POST['discount_pct'] ?? 0);
            $taxPct = (float) ($_POST['tax_pct'] ?? 0);
            // ⚠ لا يوجد "مبلغ مدفوع" هون عمداً — الدفع خطوة منفصلة تماماً
            // عن حفظ/تأكيد الفاتورة (مبدأ فصل الالتزام عن التسوية)،
            // وستُبنى لاحقاً كمودال مستقل عند التأكيد. كل فاتورة جديدة
            // تُحفظ دايماً كـ "مسودة"، بدون مبلغ مدفوع من الأساس.
            $paidOrig = 0.0;
            $rows = json_decode($_POST['rows'] ?? '[]', true);

            if (!$whId)
                throw new Exception('يجب اختيار المستودع');
            if (empty($rows))
                throw new Exception('يجب إضافة مادة واحدة على الأقل');

            // حساب الإجماليات بعملة الفاتورة ثم بالدولار
            $subtotalOrig = 0;
            foreach ($rows as $r) {
                $subtotalOrig += (float) $r['qty'] * (float) $r['unit_price_orig'] * (1 - (float) ($r['discount_pct'] ?? 0) / 100);
            }
            $discOrig = $subtotalOrig * $discPct / 100;
            $taxOrig = ($subtotalOrig - $discOrig) * $taxPct / 100;
            $totalOrig = $subtotalOrig - $discOrig + $taxOrig;
            $balanceOrig = $totalOrig - $paidOrig;

            $subtotalBase = $subtotalOrig / $exRate;
            $discBase = $discOrig / $exRate;
            $taxBase = $taxOrig / $exRate;
            $totalBase = $totalOrig / $exRate;
            $paidBase = $paidOrig / $exRate;
            $balanceBase = $balanceOrig / $exRate;

            $status = 'draft'; // دايماً — التأكيد وحده لاحقاً هو يلي بيغيّر الحالة

            $invNo = genNo($pdo, $TP, 'PUR');

            $pdo->prepare("INSERT INTO `{$TP}`
                (invoice_no, supplier_ref, supplier_id, warehouse_id, currency, exchange_rate,
                 invoice_date, payment_method, notes,
                 subtotal_orig, subtotal_base,
                 discount_pct, discount_orig, discount_base,
                 tax_pct, tax_orig, tax_base,
                 total_orig, total_base,
                 paid_orig, paid_base,
                 balance_orig, balance_base,
                 status, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $invNo,
                    $suppRef,
                    $supplierId,
                    $whId,
                    $currency,
                    $exRate,
                    $invDate,
                    $payMethod,
                    $notes,
                    $subtotalOrig,
                    $subtotalBase,
                    $discPct,
                    $discOrig,
                    $discBase,
                    $taxPct,
                    $taxOrig,
                    $taxBase,
                    $totalOrig,
                    $totalBase,
                    $paidOrig,
                    $paidBase,
                    $balanceOrig,
                    $balanceBase,
                    $status,
                    $_SESSION['user_id']
                ]);
            $purchaseId = (int) $pdo->lastInsertId();

            savePurchaseRows($pdo, $TM, $TPI, $TPK, $purchaseId, $whId, $invDate, $exRate, $rows, $_SESSION['user_id']);

            echo json_encode([
                'ok' => true,
                'id' => $purchaseId,
                'invoice_no' => $invNo,
                'msg' => 'تم حفظ الفاتورة كمسودة'
            ]);
        }

        // ── تعديل فاتورة (مسودة فقط) ──
        elseif ($act === 'update_purchase') {
            requirePermission('purchases.consumable_purchases', 'edit');
            $id = (int) ($_POST['id'] ?? 0);
            $chk = $pdo->prepare("SELECT status FROM `{$TP}` WHERE id=?");
            $chk->execute([$id]);
            $curStatus = $chk->fetchColumn();
            if ($curStatus === false)
                throw new Exception('الفاتورة غير موجودة');
            if ($curStatus !== 'draft')
                throw new Exception('لا يمكن تعديل فاتورة مؤكدة أو ملغاة — المسودات فقط قابلة للتعديل');

            $supplierId = (int) ($_POST['supplier_id'] ?? 0) ?: null;
            $whId = (int) ($_POST['warehouse_id'] ?? 0);
            $invDate = $_POST['invoice_date'] ?? date('Y-m-d');
            $suppRef = trim($_POST['supplier_ref'] ?? '');
            $payMethod = $_POST['payment_method'] ?? 'deferred';
            $notes = trim($_POST['notes'] ?? '');
            $currency = $_POST['currency'] ?? 'USD';
            $exRate = max(0.0001, (float) ($_POST['exchange_rate'] ?? 1));
            $discPct = (float) ($_POST['discount_pct'] ?? 0);
            $taxPct = (float) ($_POST['tax_pct'] ?? 0);
            $rows = json_decode($_POST['rows'] ?? '[]', true);

            if (!$whId)
                throw new Exception('يجب اختيار المستودع');
            if (empty($rows))
                throw new Exception('يجب إضافة مادة واحدة على الأقل');

            $subtotalOrig = 0;
            foreach ($rows as $r) {
                $subtotalOrig += (float) $r['qty'] * (float) $r['unit_price_orig'] * (1 - (float) ($r['discount_pct'] ?? 0) / 100);
            }
            $discOrig = $subtotalOrig * $discPct / 100;
            $taxOrig = ($subtotalOrig - $discOrig) * $taxPct / 100;
            $totalOrig = $subtotalOrig - $discOrig + $taxOrig;
            $subtotalBase = $subtotalOrig / $exRate;
            $discBase = $discOrig / $exRate;
            $taxBase = $taxOrig / $exRate;
            $totalBase = $totalOrig / $exRate;

            $pdo->beginTransaction();
            try {
                // ⚠ الفاتورة لسا مسودة (تأكدنا فوق) — يعني بنودها وحركاتها
                // كلها is_posted=0 وما أثّرت على المخزون أو أي قيد إطلاقاً.
                // آمن نمسحهم بالكامل ونعيد بنائهم من الصفر بالبيانات
                // الجديدة، بدل محاولة "تعديل جزئي" معقّد وعرضة للأخطاء.
                $oldItems = $pdo->prepare("SELECT movement_id FROM `{$TPI}` WHERE purchase_id=?");
                $oldItems->execute([$id]);
                $oldMovIds = $oldItems->fetchAll(PDO::FETCH_COLUMN);

                $pdo->prepare("DELETE FROM `{$TPI}` WHERE purchase_id=?")->execute([$id]);
                if ($oldMovIds) {
                    $ph = implode(',', array_fill(0, count($oldMovIds), '?'));
                    $pdo->prepare("DELETE FROM `{$TM}` WHERE id IN ({$ph})")->execute($oldMovIds);
                }

                $pdo->prepare("UPDATE `{$TP}` SET
                    supplier_ref=?, supplier_id=?, warehouse_id=?, currency=?, exchange_rate=?,
                    invoice_date=?, payment_method=?, notes=?,
                    subtotal_orig=?, subtotal_base=?,
                    discount_pct=?, discount_orig=?, discount_base=?,
                    tax_pct=?, tax_orig=?, tax_base=?,
                    total_orig=?, total_base=?,
                    balance_orig=?, balance_base=?,
                    updated_by=?, updated_at=NOW()
                    WHERE id=?")
                    ->execute([
                        $suppRef,
                        $supplierId,
                        $whId,
                        $currency,
                        $exRate,
                        $invDate,
                        $payMethod,
                        $notes,
                        $subtotalOrig,
                        $subtotalBase,
                        $discPct,
                        $discOrig,
                        $discBase,
                        $taxPct,
                        $taxOrig,
                        $taxBase,
                        $totalOrig,
                        $totalBase,
                        $totalOrig, // balance_orig = total_orig كامل (لسا مافي دفع بمرحلة المسودة)
                        $totalBase,
                        $_SESSION['user_id'],
                        $id
                    ]);

                savePurchaseRows($pdo, $TM, $TPI, $TPK, $id, $whId, $invDate, $exRate, $rows, $_SESSION['user_id']);

                $pdo->commit();
                echo json_encode(['ok' => true, 'id' => $id, 'msg' => 'تم تحديث الفاتورة']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        // ── تأكيد فاتورة ──
        elseif ($act === 'confirm_purchase') {
            requirePermission('purchases.consumable_purchases', 'edit');
            $id = (int) $_POST['id'];
            $pSt = $pdo->prepare("SELECT * FROM `{$TP}` WHERE id=?");
            $pSt->execute([$id]);
            $pur = $pSt->fetch();
            if (!$pur)
                throw new Exception('الفاتورة غير موجودة');
            if ($pur['status'] !== 'draft')
                throw new Exception('يمكن تأكيد المسودات فقط');

            $whId = (int) $pur['warehouse_id'];
            $exRate = max(0.0001, (float) $pur['exchange_rate']);

            $pdo->beginTransaction();
            try {
                // جلب البنود وتحديث المخزون
                $items = $pdo->prepare("SELECT pi.*, m.id AS mov_id
                    FROM `{$TPI}` pi
                    LEFT JOIN `{$TM}` m ON m.id = pi.movement_id
                    WHERE pi.purchase_id = ?");
                $items->execute([$id]);

                foreach ($items->fetchAll() as $row) {
                    $itemId = (int) $row['item_id'];
                    $qty = (float) $row['quantity'];
                    $prBase = (float) $row['unit_price_base'];

                    // جلب الرصيد الحالي
                    $curSt = $pdo->prepare("SELECT quantity, avg_cost_base FROM `{$TST}`
                        WHERE item_id=? AND warehouse_id=?");
                    $curSt->execute([$itemId, $whId]);
                    $cur = $curSt->fetch();
                    $before = $cur ? (float) $cur['quantity'] : 0;
                    $after = $before + $qty;

                    // تحديث الحركة
                    if ($row['mov_id']) {
                        $pdo->prepare("UPDATE `{$TM}` SET qty_before=?, qty_after=?, is_posted=1 WHERE id=?")
                            ->execute([$before, $after, $row['mov_id']]);
                    }

                    // تحديث المخزون
                    updateStock($pdo, $TST, $itemId, $whId, $qty, $prBase);

                    // تحديث آخر سعر شراء في كتالوج المادة
                    $pdo->prepare("UPDATE `{$TI}` SET last_purchase_price_base=?, last_purchase_date=? WHERE id=?")
                        ->execute([$prBase, $pur['invoice_date'], $itemId]);
                }

                // تحديث حالة الفاتورة
                $paid = (float) $pur['paid_orig'];
                $total = (float) $pur['total_orig'];
                $newStatus = $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'confirmed');
                $pdo->prepare("UPDATE `{$TP}` SET status=?, updated_by=?, updated_at=NOW() WHERE id=?")
                    ->execute([$newStatus, $_SESSION['user_id'], $id]);

                // ⚠ ترحيل محاسبي (كان غائباً بالكامل) — شراء المستهلكات
                // زيادة أصل حقيقي (مخزون مستهلكات) مقابل التزام حقيقي
                // تجاه المورد، ولازم ينعكس بقيد: مدين مخزون المستهلكات /
                // دائن ذمم موردي المستهلكات. نفس نمط confirm_issue بملف
                // consumable_issues.php، بعكس الاتجاه، وبنفس السلوك عند
                // غياب إعدادات الحسابات: لا نوقف تأكيد الفاتورة، بس نسجّل
                // تحذيراً واضحاً بالسجل.
                $totalPurchaseCostBase = (float) $pur['total_base'];
                // ⚠ original_amount لازم يكون المبلغ الفعلي بعملة الفاتورة
                // نفسها (مش نفس رقم base_amount المكرر) — وexchange_rate
                // هوّ السعر الحقيقي المستخدم بالفاتورة وقتها، مش 1 ثابتة.
                // currency_id هلق FK رقمي حقيقي لجدول currencies (كان
                // رمز نصي)، فبنحوّله عبر resolveCurrencyId()
                $origAmountPur = (float) $pur['total_orig'];
                $invCurrencyIdPur = resolveCurrencyId($pdo, $pur['currency']);
                $invExRatePur = max(0.0001, (float) $pur['exchange_rate']);

                // ⚠ فصل الضريبة عن تكلفة المخزون — اختياري حسب إعداد كل
                // فرع (tax_input_recoverable): بعض الأنظمة الضريبية (VAT
                // بتركيا مثلاً) بتخلي ضريبة المشتريات قابلة للاسترداد،
                // فلازم تُسجَّل كأصل منفصل مش جزء من تكلفة المخزون. أنظمة
                // تانية (سوريا حالياً، ضريبة مبيعات مرحلة وحيدة غير قابلة
                // للاسترداد) بتعتبرها فعلياً جزء حقيقي من التكلفة —
                // فبغياب هالمفتاح، السلوك القديم (تضمين الضريبة بالمخزون)
                // يضل صحيح تماماً لهالحالة، مش مجرد fallback مؤقت.
                $taxBase = (float) $pur['tax_base'];
                $taxOrig = (float) $pur['tax_orig'];
                $netInventoryBase = $totalPurchaseCostBase - $taxBase;
                $netInventoryOrig = $origAmountPur - $taxOrig;

                if ($totalPurchaseCostBase > 0.0000001) {
                    $accRows = $pdo->query("SELECT setting_key, account_id FROM `{$TAS}`
                        WHERE setting_key IN ('consumable_inventory','consumable_supplier','tax_input_recoverable')")
                        ->fetchAll(PDO::FETCH_KEY_PAIR);

                    if (isset($accRows['consumable_inventory'], $accRows['consumable_supplier'])) {
                        $splitTax = isset($accRows['tax_input_recoverable']) && $taxBase > 0.0000001;

                        $entryNo = 'JE-' . date('Y') . '-' . str_pad(
                            (int) $pdo->query("SELECT COUNT(*)+1 FROM `{$TJE}`")->fetchColumn(),
                            4, '0', STR_PAD_LEFT
                        );
                        $pdo->prepare("INSERT INTO `{$TJE}`
                            (entry_number, entry_date, description, currency_id, exchange_rate,
                             total_debit, total_credit, status, reference_type, reference_id,
                             created_by, posted_at, posted_by)
                            VALUES (?,?,?,?,1,?,?,'posted','consumable_purchase',?,?,NOW(),?)")
                            ->execute([
                                $entryNo, $pur['invoice_date'],
                                'شراء مستهلكات — فاتورة رقم ' . $pur['invoice_no'],
                                $branchBaseCurrencyId,
                                $totalPurchaseCostBase, $totalPurchaseCostBase,
                                $id, $_SESSION['user_id'], $_SESSION['user_id'],
                            ]);
                        $jeId = (int) $pdo->lastInsertId();

                        if ($splitTax) {
                            // مدين مخزون (صافي بدون ضريبة) + مدين ضريبة قابلة للاسترداد، مقابل دائن ذمم المورد بالإجمالي الكامل
                            $pdo->prepare("INSERT INTO `{$TJI}`
                                (journal_entry_id, account_id, debit, credit, original_amount, base_amount, description, currency_id, exchange_rate)
                                VALUES (?,?,?,0,?,?,?,?,?)")
                                ->execute([$jeId, $accRows['consumable_inventory'], $netInventoryBase, $netInventoryOrig, $netInventoryBase, 'زيادة مخزون المستهلكات (صافي بدون ضريبة)', $invCurrencyIdPur, $invExRatePur]);
                            postAccountBalance($pdo, $TAC, (int) $accRows['consumable_inventory'], $netInventoryBase, 0);

                            $pdo->prepare("INSERT INTO `{$TJI}`
                                (journal_entry_id, account_id, debit, credit, original_amount, base_amount, description, currency_id, exchange_rate)
                                VALUES (?,?,?,0,?,?,?,?,?)")
                                ->execute([$jeId, $accRows['tax_input_recoverable'], $taxBase, $taxOrig, $taxBase, 'ضريبة مشتريات قابلة للاسترداد', $invCurrencyIdPur, $invExRatePur]);
                            postAccountBalance($pdo, $TAC, (int) $accRows['tax_input_recoverable'], $taxBase, 0);

                            $pdo->prepare("INSERT INTO `{$TJI}`
                                (journal_entry_id, account_id, debit, credit, original_amount, base_amount, description, currency_id, exchange_rate)
                                VALUES (?,?,0,?,?,?,?,?,?)")
                                ->execute([$jeId, $accRows['consumable_supplier'], $totalPurchaseCostBase, $origAmountPur, $totalPurchaseCostBase, 'ذمم مورد المستهلكات', $invCurrencyIdPur, $invExRatePur]);
                            postAccountBalance($pdo, $TAC, (int) $accRows['consumable_supplier'], 0, $totalPurchaseCostBase);
                        } else {
                            $pdo->prepare("INSERT INTO `{$TJI}`
                                (journal_entry_id, account_id, debit, credit, original_amount, base_amount, description, currency_id, exchange_rate)
                                VALUES (?,?,?,0,?,?,?,?,?)")
                                ->execute([$jeId, $accRows['consumable_inventory'], $totalPurchaseCostBase, $origAmountPur, $totalPurchaseCostBase, 'زيادة مخزون المستهلكات', $invCurrencyIdPur, $invExRatePur]);
                            postAccountBalance($pdo, $TAC, (int) $accRows['consumable_inventory'], $totalPurchaseCostBase, 0);

                            $pdo->prepare("INSERT INTO `{$TJI}`
                                (journal_entry_id, account_id, debit, credit, original_amount, base_amount, description, currency_id, exchange_rate)
                                VALUES (?,?,0,?,?,?,?,?,?)")
                                ->execute([$jeId, $accRows['consumable_supplier'], $totalPurchaseCostBase, $origAmountPur, $totalPurchaseCostBase, 'ذمم مورد المستهلكات', $invCurrencyIdPur, $invExRatePur]);
                            postAccountBalance($pdo, $TAC, (int) $accRows['consumable_supplier'], 0, $totalPurchaseCostBase);
                        }
                    } else {
                        // مفاتيح الحسابات غير مضبوطة بعد (accounting/account_settings.php)
                        // — لا نوقف تأكيد الفاتورة نفسها، بس نسجّل تحذيراً واضحاً بالسجل
                        error_log("[consumable_purchases] تعذّر ترحيل القيد: مفاتيح consumable_inventory/consumable_supplier غير مضبوطة بـ {$TAS} (purchase id={$id})");
                    }
                }

                $pdo->commit();
                echo json_encode(['ok' => true, 'msg' => 'تم تأكيد الفاتورة وتحديث المخزون']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        // ── إلغاء فاتورة ──
        elseif ($act === 'cancel_purchase') {
            requirePermission('purchases.consumable_purchases', 'edit');
            $id = (int) $_POST['id'];
            $pSt = $pdo->prepare("SELECT * FROM `{$TP}` WHERE id=?");
            $pSt->execute([$id]);
            $pur = $pSt->fetch();
            if (!$pur)
                throw new Exception('الفاتورة غير موجودة');
            if ($pur['status'] === 'cancelled')
                throw new Exception('الفاتورة ملغاة مسبقاً');

            $wasConfirmed = in_array($pur['status'], ['confirmed', 'partial', 'paid']);

            $pdo->beginTransaction();
            try {
                if ($wasConfirmed) {
                    $items = $pdo->prepare("SELECT * FROM `{$TPI}` WHERE purchase_id=?");
                    $items->execute([$id]);
                    foreach ($items->fetchAll() as $row) {
                        $pdo->prepare("UPDATE `{$TST}`
                            SET quantity = GREATEST(0, quantity - ?), last_movement = NOW()
                            WHERE item_id=? AND warehouse_id=?")
                            ->execute([$row['quantity'], $row['item_id'], $pur['warehouse_id']]);
                    }
                    $pdo->prepare("UPDATE `{$TM}` m
                        JOIN `{$TPI}` pi ON pi.movement_id = m.id
                        SET m.is_posted=0, m.movement_type='return_out'
                        WHERE pi.purchase_id=?")->execute([$id]);

                    // ⚠ قيد عكسي (reversal) — لا نلمس/نحذف القيد الأصلي إطلاقاً،
                    // منشئ قيد جديد بعكس نفس المبلغ لإعادة الأرصدة لوضعها
                    // الطبيعي، فيضل السجل التاريخي كامل (الفاتورة الأصلية +
                    // قيدها + قيد الإلغاء جنب بعض) بدل حذف/تعديل حدث مالي صار فعلياً.
                    $origJe = $pdo->prepare("SELECT id, total_debit FROM `{$TJE}`
                        WHERE reference_type='consumable_purchase' AND reference_id=? AND status='posted'
                        ORDER BY id DESC LIMIT 1");
                    $origJe->execute([$id]);
                    $orig = $origJe->fetch();

                    if ($orig) {
                        $origLines = $pdo->prepare("SELECT account_id, debit, credit, original_amount, currency_id, exchange_rate FROM `{$TJI}` WHERE journal_entry_id=?");
                        $origLines->execute([$orig['id']]);
                        $lines = $origLines->fetchAll();

                        $revNo = 'JE-' . date('Y') . '-' . str_pad(
                            (int) $pdo->query("SELECT COUNT(*)+1 FROM `{$TJE}`")->fetchColumn(),
                            4, '0', STR_PAD_LEFT
                        );
                        $pdo->prepare("INSERT INTO `{$TJE}`
                            (entry_number, entry_date, description, currency_id, exchange_rate,
                             total_debit, total_credit, status, reference_type, reference_id,
                             created_by, posted_at, posted_by)
                            VALUES (?,?,?,?,1,?,?,'posted','consumable_purchase_cancel',?,?,NOW(),?)")
                            ->execute([
                                $revNo, date('Y-m-d'),
                                'إلغاء فاتورة شراء مستهلكات — فاتورة رقم ' . $pur['invoice_no'] . ' (عكس قيد ' . $orig['id'] . ')',
                                $branchBaseCurrencyId, $orig['total_debit'], $orig['total_debit'],
                                $id, $_SESSION['user_id'], $_SESSION['user_id'],
                            ]);
                        $revId = (int) $pdo->lastInsertId();

                        foreach ($lines as $ln) {
                            // عكس: يلي كان مدين بيصير دائن، والعكس — بس
                            // original_amount/currency_id/exchange_rate تبقى
                            // كما هي (نفس مبلغ الفاتورة الأصلي بعملتها)،
                            // فقط اتجاه debit/credit ينعكس
                            $baseAmt = max($ln['debit'], $ln['credit']);
                            $pdo->prepare("INSERT INTO `{$TJI}`
                                (journal_entry_id, account_id, debit, credit, original_amount, base_amount, description, currency_id, exchange_rate)
                                VALUES (?,?,?,?,?,?,?,?,?)")
                                ->execute([
                                    $revId, $ln['account_id'],
                                    $ln['credit'], $ln['debit'],
                                    $ln['original_amount'], $baseAmt,
                                    'عكس — إلغاء فاتورة شراء مستهلكات ' . $pur['invoice_no'],
                                    $ln['currency_id'], $ln['exchange_rate'],
                                ]);
                            // ⚠ نفس معكوس القيم المكتوبة فوق بالضبط
                            postAccountBalance($pdo, $TAC, (int) $ln['account_id'], (float) $ln['credit'], (float) $ln['debit']);
                        }
                    }
                    // لو ما في قيد أصلي (مثلاً لأن الحسابات ما كانت مضبوطة وقت
                    // التأكيد) فلا شي نعكسه — الإلغاء بيكمل عادي بدون قيد عكسي.

                    $msg = 'تم إلغاء الفاتورة وعكس المخزون والقيد المحاسبي';
                } else {
                    $msg = 'تم إلغاء المسودة';
                }

                $pdo->prepare("UPDATE `{$TP}` SET status='cancelled', updated_by=?, updated_at=NOW() WHERE id=?")
                    ->execute([$_SESSION['user_id'], $id]);

                $pdo->commit();
                echo json_encode(['ok' => true, 'msg' => $msg]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        // ── إضافة مورد جديد ──
        elseif ($act === 'get_dated_rate') {
            // ⚠ تحضير للربط المستقبلي مع exchange_rates_ret (سيُبرمَج
            // فعلياً بمحادثة المحاسبة) — هلق الجدول فاضي غالباً، فهاد
            // بيرجع null والواجهة بترجع تلقائياً لسعر currencies
            // الحالي (نفس السلوك القديم). بمجرد ما يبلش تسجيل أسعار
            // مؤرخة بالجدول من قسم المحاسبة، هالفاتورة بتستفيد فوراً
            // بدون أي تعديل إضافي هون.
            $curCode = strtoupper(trim($_POST['currency'] ?? ''));
            $date = $_POST['date'] ?? date('Y-m-d');
            $baseCode = (string) $pdo->query("SELECT code FROM currencies WHERE is_base=1 LIMIT 1")->fetchColumn();

            if (!$curCode || !$baseCode || $curCode === $baseCode) {
                echo json_encode(['ok' => true, 'rate' => null]); // نفس عملة الفرع — لا داعي لسعر خاص
            } else {
                // أقرب سعر مسجل بتاريخ ≤ تاريخ الفاتورة (يمثّل السعر
                // الساري وقتها)، بالبحث بالاتجاهين (مسجّل من الفرع
                // للفاتورة أو بالعكس)
                $st = $pdo->prepare("SELECT currency_from, currency_to, rate, rate_date, source
                    FROM `exchange_rates_{$TS}`
                    WHERE rate_date <= ?
                      AND ((currency_from=? AND currency_to=?) OR (currency_from=? AND currency_to=?))
                    ORDER BY rate_date DESC LIMIT 1");
                $st->execute([$date, $baseCode, $curCode, $curCode, $baseCode]);
                $row = $st->fetch();

                if (!$row) {
                    echo json_encode(['ok' => true, 'rate' => null]); // ما في سعر مسجل — الواجهة بترجع لسعر currencies الحالي
                } else {
                    // rate بالجدول يعني: وحدة واحدة من currency_from = rate وحدة من currency_to
                    $rate = ($row['currency_from'] === $baseCode)
                        ? (float) $row['rate']
                        : ((float) $row['rate'] > 0 ? 1 / (float) $row['rate'] : null);
                    echo json_encode([
                        'ok' => true,
                        'rate' => $rate,
                        'rate_date' => $row['rate_date'],
                        'source' => $row['source']
                    ]);
                }
            }
        }

        // ═══════════════════════════════════════════════════════════
        // ⚠⚠⚠ زر تجريبي مؤقت — احذفه قبل أي استخدام إنتاجي حقيقي ⚠⚠⚠
        // بيعمل "rollback" كامل لعملية تأكيد فاتورة مستهلكات: يحذف
        // القيود المحاسبية المرتبطة، يصفّر كل رصيد مخزون المستهلكات،
        // ويرجّع الفاتورة والحركات لحالة "مسودة" — لتسهيل إعادة اختبار
        // منطق التأكيد بشكل متكرر أثناء التطوير بس.
        // ═══════════════════════════════════════════════════════════
        elseif ($act === 'dev_reset_purchase') {
            requirePermission('purchases.consumable_purchases', 'edit');
            $id = (int) ($_POST['id'] ?? 0);
            $pSt = $pdo->prepare("SELECT * FROM `{$TP}` WHERE id=?");
            $pSt->execute([$id]);
            $pur = $pSt->fetch();
            if (!$pur)
                throw new Exception('الفاتورة غير موجودة');

            $pdo->beginTransaction();
            try {
                // ١) حذف القيود المحاسبية (الأصلي + أي عكسي مرتبط بنفس الفاتورة)
                $jeIds = $pdo->prepare("SELECT id FROM `{$TJE}` WHERE reference_type IN ('consumable_purchase','consumable_purchase_cancel') AND reference_id=?");
                $jeIds->execute([$id]);
                $jeIdList = $jeIds->fetchAll(PDO::FETCH_COLUMN);
                if ($jeIdList) {
                    $ph = implode(',', array_fill(0, count($jeIdList), '?'));
                    $pdo->prepare("DELETE FROM `{$TJI}` WHERE journal_entry_id IN ({$ph})")->execute($jeIdList);
                    $pdo->prepare("DELETE FROM `{$TJE}` WHERE id IN ({$ph})")->execute($jeIdList);
                }

                // ٢) تصفير كامل لرصيد مخزون المستهلكات (كل المواد، كل المستودعات)
                $pdo->exec("UPDATE `{$TST}` SET quantity=0, avg_cost_base=0");

                // ٣) إرجاع حركات هالفاتورة لحالة معلّقة (مسودة)
                $pdo->prepare("UPDATE `{$TM}` SET is_posted=0 WHERE reference_type='purchase' AND reference_id=?")
                    ->execute([$id]);

                // ٤) إرجاع الفاتورة نفسها لحالة مسودة
                $pdo->prepare("UPDATE `{$TP}` SET status='draft' WHERE id=?")->execute([$id]);

                $pdo->commit();
                echo json_encode(['ok' => true, 'msg' => '🔧 تم التصفير الكامل (تجريبي) — الفاتورة رجعت مسودة، والمخزون والقيود اتصفّروا']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        } elseif ($act === 'add_supplier') {
            $name = trim($_POST['name'] ?? '');
            $contact = trim($_POST['contact_person'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $type = $_POST['type'] ?? 'wholesaler';
            $notes = trim($_POST['notes'] ?? '');
            if (!$name)
                throw new Exception('اسم المورد مطلوب');
            $pdo->prepare("INSERT INTO `{$TSP}` (name, contact_person, phone, type, supplier_type, notes, created_by)
                VALUES (?,?,?,?,'consumable',?,?)")
                ->execute([$name, $contact, $phone, $type, $notes, $_SESSION['user_id']]);
            $newId = (int) $pdo->lastInsertId();
            echo json_encode([
                'ok' => true,
                'id' => $newId,
                'name' => $name,
                'phone' => $phone,
                'contact' => $contact
            ]);
        } else
            throw new Exception('إجراء غير معروف');

    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── بيانات الصفحة ──────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$where = 'WHERE 1=1';
$params = [];
if ($search) {
    $where .= ' AND (p.invoice_no LIKE ? OR s.name LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($status) {
    $where .= ' AND p.status=?';
    $params[] = $status;
}

$stmt = $pdo->prepare("
    SELECT p.*, s.name AS supplier_name, w.name AS wh_name,
        p.currency AS currency_code,
        COALESCE(c.symbol, '$') AS currency_symbol
    FROM `{$TP}` p
    LEFT JOIN `{$TSP}` s ON s.id = p.supplier_id
    LEFT JOIN `{$TW}`  w ON w.id = p.warehouse_id
    LEFT JOIN currencies c ON c.code = p.currency
    {$where}
    ORDER BY p.created_at DESC LIMIT 100");
$stmt->execute($params);
$purchases = $stmt->fetchAll();

$suppliers = $pdo->query("SELECT id, name, phone, contact_person FROM `{$TSP}`
    WHERE status='active' AND supplier_type IN ('consumable','both') ORDER BY name")->fetchAll();
$warehouses = $pdo->query("SELECT * FROM `{$TW}` WHERE is_active=1 AND warehouse_type='consumables' ORDER BY id")->fetchAll();
$currencies = $pdo->query("SELECT * FROM currencies WHERE status='active'
    ORDER BY is_base DESC, code")->fetchAll();
$items_list = $pdo->query("SELECT ci.id, ci.name, ci.unit_id,
    cun.name AS unit,
    ci.estimated_cost, ci.last_purchase_price_base,
    ci.currency_id,
    cu.code AS currency_code, cu.symbol AS currency_symbol, cu.exchange_rate AS currency_rate
    FROM `{$TI}` ci
    LEFT JOIN currencies cu ON cu.id = ci.currency_id
    LEFT JOIN `{$TU}` cun ON cun.id = ci.unit_id
    WHERE ci.is_active=1 ORDER BY ci.name")->fetchAll();

// عبوات كل مادة (كرتونة/دستة...) — معامل التحويل خاص بكل مادة على حدة
$pkgRows = $pdo->query("SELECT id, item_id, name, qty_per_package FROM `{$TPK}` WHERE is_active=1")->fetchAll();
$pkgByItem = [];
foreach ($pkgRows as $pk) {
    $pkgByItem[$pk['item_id']][] = $pk;
}
foreach ($items_list as &$it) {
    $it['packagings'] = $pkgByItem[$it['id']] ?? [];
}
unset($it);

try {
    $stats = $pdo->query("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status='confirmed' THEN 1 ELSE 0 END) AS confirmed,
        SUM(CASE WHEN status='partial'   THEN 1 ELSE 0 END) AS partial,
        SUM(CASE WHEN status='paid'      THEN 1 ELSE 0 END) AS paid,
        COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','draft') THEN total_base END), 0) AS total_amount,
        COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','draft') THEN balance_base END), 0) AS total_balance
        FROM `{$TP}`")->fetch();
} catch (Exception $e) {
    $stats = ['total' => 0, 'confirmed' => 0, 'partial' => 0, 'paid' => 0, 'total_amount' => 0, 'total_balance' => 0];
}

$STATUS_MAP = [
    'draft' => ['label' => 'مسودة', 'cls' => 'bg-secondary-subtle text-secondary'],
    'confirmed' => ['label' => 'مؤكدة', 'cls' => 'bg-info-subtle text-info'],
    'partial' => ['label' => 'جزئي', 'cls' => 'bg-warning-subtle text-warning'],
    'paid' => ['label' => 'مدفوعة', 'cls' => 'bg-success-subtle text-success'],
    'cancelled' => ['label' => 'ملغاة', 'cls' => 'bg-danger-subtle text-danger'],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>فواتير شراء المستهلكات — <?= htmlspecialchars($branchName) ?></title>
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
            font-size: 1.2rem;
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
            border-bottom: 1px solid #f1f5f9;
            white-space: nowrap
        }

        table.mtbl td {
            padding: 8px 12px;
            border-bottom: 1px solid #f8fafc;
            vertical-align: middle
        }

        table.mtbl tr:last-child td {
            border-bottom: none
        }

        table.mtbl tr:hover td {
            background: #f8faff
        }

        .act-btn {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            border: 1px solid #e2e8f0;
            background: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .8rem;
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

        .field-lbl {
            font-size: .8rem;
            font-weight: 700;
            color: #475569;
            margin-bottom: 5px;
            display: block
        }

        .req {
            color: #dc2626
        }

        .field-hint {
            font-size: .74rem;
            color: #94a3b8;
            margin-top: 4px
        }

        /* ── بنود الفاتورة (إعادة تصميم: كل حقل بعنوانه الخاص، عرض مرن) ── */
        @keyframes newRowHighlight {
            0% {
                background: #dcfce7;
                border-color: #86efac
            }

            100% {
                background: #f8fafc;
                border-color: #eef1f5
            }
        }

        .inv-row {
            background: #f8fafc;
            border: 1px solid #eef1f5;
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 10px;
            transition: background .3s, border-color .3s
        }

        .inv-row-top {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px
        }

        .inv-row-top select.item-sel {
            flex: 1;
            font-size: .92rem;
            font-weight: 600
        }

        .inv-row-fields {
            display: flex;
            flex-wrap: wrap;
            gap: 10px
        }

        .line-field {
            display: flex;
            flex-direction: column;
            gap: 3px
        }

        .line-field label {
            font-size: .72rem;
            font-weight: 700;
            color: #64748b;
            white-space: nowrap
        }

        .line-field label small {
            font-weight: 600
        }

        .inv-row input,
        .inv-row select {
            font-size: .86rem;
            padding: 7px 10px;
            border: 1px solid #dde3ea;
            border-radius: 7px;
            width: 100%;
            background: #fff;
            color: #1e293b;
            height: 38px;
            box-sizing: border-box
        }

        .inv-row input[readonly] {
            background: #f1f5f9;
            color: #64748b;
            border-color: #e2e8f0
        }

        .inv-row input.calc {
            background: #f0fdf4;
            color: #16a34a;
            border-color: #86efac;
            font-weight: 700
        }

        .inv-row input.ref {
            background: #fef9ee;
            color: #92400e;
            border-color: #fcd34d;
            font-size: .8rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis
        }

        .conv-hint {
            font-size: .74rem;
            color: #0891b2;
            font-weight: 600;
            margin-top: 8px
        }

        .del-btn {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            border: 1px solid #fca5a5;
            background: #fff;
            color: #dc2626;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .9rem;
            flex-shrink: 0
        }

        .tot-box {
            background: #f8fafc;
            border-radius: 10px;
            padding: 10px 14px
        }

        .tot-row {
            display: flex;
            justify-content: space-between;
            font-size: .8rem;
            padding: 3px 0
        }

        .tot-row.final {
            font-weight: 700;
            font-size: .9rem;
            border-top: 1px solid #e2e8f0;
            padding-top: 6px;
            margin-top: 3px;
            color: #1e293b
        }

        .n {
            font-variant-numeric: tabular-nums
        }

        .legend {
            display: flex;
            gap: 12px;
            font-size: .7rem;
            color: #64748b;
            margin-bottom: 8px;
            flex-wrap: wrap
        }

        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 2px;
            display: inline-block;
            margin-left: 4px
        }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>

    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-cart-plus me-1 text-primary"></i>فواتير شراء المستهلكات</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
            <a href="consumables.php" style="color:#64748b;text-decoration:none">المستهلكات</a>
            <i class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
            <span class="text-primary">فواتير الشراء</span>
        </nav>
    </header>

    <main class="main-content">
        <div class="content-body">

            <!-- تبويبات -->
            <ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e2e8f0">
                <li class="nav-item"><a class="nav-link fw-600" href="consumables.php"
                        style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-box-seam me-1"></i>المواد
                        الاستهلاكية</a></li>
                <li class="nav-item"><a class="nav-link fw-600 active" href="#"
                        style="border:none;border-bottom:2px solid #1e3a8a;color:#1e3a8a;font-size:.83rem;margin-bottom:-2px"><i
                            class="bi bi-cart-plus me-1"></i>فواتير الشراء</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="consumable_issues.php"
                        style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-arrow-bar-up me-1"></i>صرف
                        المستهلكات</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="../inventory/warehouse.php?type=consumables"
                        style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-building me-1"></i>مستودعات
                        المستهلكات</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="../inventory/movements.php?tab=consumables"
                        style="border:none;color:#64748b;font-size:.83rem"><i
                            class="bi bi-arrow-left-right me-1"></i>حركة المستهلكات</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="consumable_transfers.php"
                        style="border:none;color:#64748b;font-size:.83rem"><i
                            class="bi bi-signpost-split me-1"></i>مناقلة بين المستودعات</a></li>
                <li class="nav-item"><a class="nav-link fw-600" href="expenses.php"
                        style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-wallet2 me-1"></i>إدارة
                        المصاريف</a></li>
            </ul>

            <!-- إحصائيات -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#eff6ff"><i class="bi bi-receipt text-primary"></i>
                        </div>
                        <div>
                            <div class="stat-val"><?= $stats['total'] ?></div>
                            <div class="stat-lbl">إجمالي الفواتير</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fef3c7"><i
                                class="bi bi-hourglass-split text-warning"></i></div>
                        <div>
                            <div class="stat-val"><?= ($stats['confirmed'] ?? 0) + ($stats['partial'] ?? 0) ?></div>
                            <div class="stat-lbl">معلّقة / جزئية</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#f0fdf4"><i
                                class="bi bi-currency-dollar text-success"></i></div>
                        <div>
                            <div class="stat-val n"><?= number_format($stats['total_amount'], 2) ?> $</div>
                            <div class="stat-lbl">إجمالي المشتريات</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fee2e2"><i
                                class="bi bi-exclamation-circle text-danger"></i></div>
                        <div>
                            <div class="stat-val n"><?= number_format($stats['total_balance'], 2) ?> $</div>
                            <div class="stat-lbl">إجمالي المستحق</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- جدول الفواتير -->
            <div class="tbl-wrap">
                <div class="tbl-hdr">
                    <span style="font-size:.88rem;font-weight:700;color:#1e293b"><i
                            class="bi bi-list-ul me-1 text-primary"></i>سجل فواتير الشراء</span>
                    <div class="d-flex gap-2 ms-auto flex-wrap align-items-center">
                        <form method="get" class="d-flex gap-2">
                            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="بحث..."
                                class="form-control form-control-sm" style="width:140px;border-radius:8px">
                            <select name="status" class="form-select form-select-sm"
                                style="width:120px;border-radius:8px" onchange="this.form.submit()">
                                <option value="">كل الحالات</option>
                                <?php foreach ($STATUS_MAP as $k => $v): ?>
                                            <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $v['label'] ?>
                                            </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <button class="btn btn-sm fw-600"
                            style="border-radius:9px;background:#1e3a8a;color:#fff;font-size:.82rem"
                            onclick="openNewInvoice()">
                            <i class="bi bi-plus-lg me-1"></i>فاتورة جديدة
                        </button>
                        <button class="btn btn-sm fw-600" title="⚠ أداة تجريبية مؤقتة — احذفها قبل الإنتاج"
                            style="border-radius:9px;background:#dc2626;color:#fff;font-size:.82rem"
                            onclick="devResetPurchase()">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>🔧 تصفير تجريبي
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="mtbl" id="purchasesTbl">
                        <thead>
                            <tr>
                                <th style="color:#1e3a8a">رقم الفاتورة</th>
                                <th>التاريخ</th>
                                <th style="color:#16a34a">المورد</th>
                                <th style="color:#dc2626">المستودع</th>
                                <th>العملة</th>
                                <th>الإجمالي</th>
                                <th>المدفوع</th>
                                <th>المتبقي</th>
                                <th>الحالة</th>
                                <th style="text-align:center" data-no-sort>إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($purchases)): ?>
                                        <tr>
                                            <td colspan="10" class="text-center text-muted py-4">
                                                <i class="bi bi-receipt d-block mb-2" style="font-size:2rem;opacity:.2"></i>
                                                لا توجد فواتير<?= $search ? " تطابق \"{$search}\"" : '' ?>
                                            </td>
                                        </tr>
                            <?php endif; ?>
                            <?php foreach ($purchases as $pur):
                                $st = $STATUS_MAP[$pur['status']] ?? $STATUS_MAP['draft'];
                                $sym = $pur['currency_symbol'] ?? '$';
                                $tO = (float) ($pur['total_orig'] ?? $pur['total_base']);
                                $pO = (float) ($pur['paid_orig'] ?? $pur['paid_base']);
                                $bO = (float) ($pur['balance_orig'] ?? $pur['balance_base']);
                                ?>
                                        <tr>
                                            <td class="n fw-600" style="direction:ltr;color:#1e3a8a"><?= htmlspecialchars($pur['invoice_no']) ?>
                                            </td>
                                            <td class="text-muted"><?= $pur['invoice_date'] ?></td>
                                            <td style="color:#16a34a"><?= htmlspecialchars($pur['supplier_name'] ?? '—') ?></td>
                                            <td style="font-size:.78rem;color:#dc2626">
                                                <?= htmlspecialchars($pur['wh_name'] ?? '—') ?>
                                            </td>
                                            <td><span
                                                    class="badge bg-secondary-subtle text-secondary"><?= $pur['currency_code'] ?></span>
                                            </td>
                                            <td class="n fw-600"><?= number_format($tO, 2) ?>             <?= $sym ?></td>
                                            <td class="n text-success"><?= number_format($pO, 2) ?>             <?= $sym ?></td>
                                            <td class="n <?= $bO > 0 ? 'text-danger fw-600' : '' ?>"><?= number_format($bO, 2) ?>
                                                <?= $sym ?>
                                            </td>
                                            <td><span class="badge <?= $st['cls'] ?>"
                                                    style="font-size:.68rem"><?= $st['label'] ?></span></td>
                                            <td>
                                                <div class="d-flex gap-1 justify-content-center">
                                                    <button class="act-btn" onclick="viewInvoice(<?= $pur['id'] ?>)" title="عرض"
                                                        style="color:#0891b2;border-color:#a5f3fc">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <?php if ($pur['status'] === 'draft'): ?>
                                                                <button class="act-btn" onclick="openEditInvoice(<?= $pur['id'] ?>)"
                                                                    title="تعديل" style="color:#7c3aed;border-color:#ddd6fe">
                                                                    <i class="bi bi-pencil"></i>
                                                                </button>
                                                                <button class="act-btn success-h"
                                                                    onclick="confirmInvoice(<?= $pur['id'] ?>,'<?= htmlspecialchars($pur['invoice_no'], ENT_QUOTES) ?>')"
                                                                    title="تأكيد">
                                                                    <i class="bi bi-check-circle"></i>
                                                                </button>
                                                    <?php endif; ?>
                                                    <?php if (in_array($pur['status'], ['confirmed', 'partial'])):
                                                        // ⚠ الزر بيظهر لكل حدا شايف الفاتورة (حتى أمين المستودع)،
                                                        // بس بيكون مفعّل فقط لو عنده صلاحية finance.payments —
                                                        // نفس آلية can() الموجودة أصلاً بالنظام، بدون أي منطق
                                                        // صلاحيات جديد. الرابط مجهّز لصفحة الدفعات المستقبلية
                                                        // (محادثة المحاسبة) معبّى مسبقاً بالمورد والفاتورة.
                                                        $canPay = can('finance.payments', 'create');
                                                        // رابط نسبي بسيط (نفس مجلد modules، بس مجلد accounting المجاور)
                                                        // — أبسط وأصعب ينكسر من إعادة بناء BASE_PATH يدوياً هون
                                                        $payUrl = 'payments.php?supplier_id=' . (int) $pur['supplier_id'] . '&purchase_id=' . (int) $pur['id'];
                                                        ?>
                                                                <?php if ($canPay): ?>
                                                                            <a href="<?= $payUrl ?>" class="act-btn" title="تسجيل دفعة"
                                                                                style="color:#15803d;border-color:#86efac">
                                                                                <i class="bi bi-cash-coin"></i>
                                                                            </a>
                                                                <?php else: ?>
                                                                            <button class="act-btn" disabled title="تسجيل دفعة — الصلاحية غير متوفرة لحسابك"
                                                                                style="color:#cbd5e1;border-color:#e2e8f0;cursor:not-allowed;opacity:.6">
                                                                                <i class="bi bi-cash-coin"></i>
                                                                            </button>
                                                                <?php endif; ?>
                                                    <?php endif; ?>
                                                    <?php if (!in_array($pur['status'], ['cancelled', 'paid'])): ?>
                                                                <button class="act-btn danger"
                                                                    onclick="cancelInvoice(<?= $pur['id'] ?>,'<?= htmlspecialchars($pur['invoice_no'], ENT_QUOTES) ?>')"
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

    <!-- ══ مودال فاتورة جديدة ══ -->
    <div class="modal fade" id="invModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl" style="max-width:1200px">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#15803d,#14532d);border-radius:16px 16px 0 0">
                    <div>
                        <h6 class="modal-title text-white fw-700 mb-0" id="invModalTitle">فاتورة شراء مستهلكات جديدة</h6>
                        <div style="font-size:.75rem;color:rgba(255,255,255,.7);margin-top:2px" id="invModalSubtitle">رقم الفاتورة يُولَّد
                            تلقائياً</div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pt-3">

                    <!-- رأس الفاتورة -->
                    <div class="row g-3 mb-3 pb-3" style="border-bottom:1px solid #f1f5f9">
                        <div class="col-md-3">
                            <label class="field-lbl">المورد</label>
                            <div class="d-flex gap-1">
                                <select id="iSupplier" class="form-select form-select-sm" style="flex:1"
                                    onchange="onSupplierChange()">
                                    <option value="">— بدون مورد —</option>
                                    <?php foreach ($suppliers as $sp): ?>
                                                <option value="<?= $sp['id'] ?>"
                                                    data-phone="<?= htmlspecialchars($sp['phone'] ?? '') ?>"
                                                    data-contact="<?= htmlspecialchars($sp['contact_person'] ?? '') ?>">
                                                    <?= htmlspecialchars($sp['name']) ?>
                                                </option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm"
                                    style="border-radius:7px;border:1px solid #1e3a8a;color:#1e3a8a;padding:4px 7px"
                                    onclick="openSupplierModal()" title="مورد جديد"><i class="bi bi-plus"></i></button>
                            </div>
                            <div id="supplierInfo" style="display:none;font-size:.7rem;color:#64748b;margin-top:3px">
                                <i class="bi bi-telephone me-1"></i><span id="supplierPhone"></span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="field-lbl">المستودع <span class="req">*</span></label>
                            <select id="iWarehouse" class="form-select form-select-sm">
                                <option value="">— اختر —</option>
                                <?php foreach ($warehouses as $wh): ?>
                                            <option value="<?= $wh['id'] ?>" <?= strpos($wh['name'], 'استهلاك') !== false ? 'selected' : '' ?>><?= htmlspecialchars($wh['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="field-lbl">التاريخ <span class="req">*</span></label>
                            <input type="date" id="iDate" class="form-control form-control-sm"
                                value="<?= date('Y-m-d') ?>" onchange="checkDatedRate()">
                        </div>
                        <div class="col-md-2">
                            <label class="field-lbl">عملة الفاتورة <span class="req">*</span></label>
                            <select id="iCurrency" class="form-select form-select-sm" onchange="onCurrencyChange()">
                                <?php foreach ($currencies as $cu): ?>
                                            <option value="<?= $cu['code'] ?>" data-rate="<?= $cu['exchange_rate'] ?>"
                                                data-sym="<?= htmlspecialchars($cu['symbol']) ?>" <?= $cu['is_base'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($cu['code'] . ' - ' . $cu['name']) ?>
                                            </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="field-lbl">سعر الصرف (مقابل عملة الفرع الأساسية)</label>
                            <input type="number" id="iExRate" class="form-control form-control-sm" value="1"
                                min="0.0001" step="0.0001" dir="ltr" onchange="onExRateChange()">
                            <div id="exRateHint" class="field-hint">1 $ = 1 $</div>
                        </div>
                        <div class="col-md-2">
                            <label class="field-lbl">رقم فاتورة المورد</label>
                            <input type="text" id="iSupplierRef" class="form-control form-control-sm" dir="ltr"
                                placeholder="اختياري">
                        </div>
                        <div class="col-md-3">
                            <label class="field-lbl">طريقة الدفع (المتوقعة)</label>
                            <select id="iPayMethod" class="form-select form-select-sm">
                                <option value="deferred">آجل</option>
                                <option value="cash">نقداً</option>
                                <option value="bank">تحويل بنكي</option>
                                <option value="card">بطاقة</option>
                            </select>
                            <div class="field-hint">للعلم فقط — تسجيل الدفع الفعلي يصير لاحقاً عند تأكيد الفاتورة</div>
                        </div>
                        <div class="col-md-5">
                            <label class="field-lbl">ملاحظات</label>
                            <input type="text" id="iNotes" class="form-control form-control-sm" placeholder="اختياري">
                        </div>
                    </div>

                    <!-- بنود الفاتورة -->
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span style="font-size:.82rem;font-weight:700;color:#1e293b">
                            <i class="bi bi-list-ul me-1 text-primary"></i>بنود الفاتورة
                            <small style="font-weight:600;color:#0891b2">
                                — الأسعار والإجمالي بعملة الفاتورة <span id="invCurLbl2"></span><span id="invCurLbl3" style="display:none"></span>
                            </small>
                        </span>
                        <button class="btn btn-sm"
                            style="border-radius:8px;border:1px solid #1e3a8a;color:#1e3a8a;font-size:.76rem"
                            onclick="addLine()"><i class="bi bi-plus-lg me-1"></i>إضافة مادة</button>
                    </div>

                    <div class="legend">
                        <span><span class="legend-dot" style="background:#fef9ee;border:1px solid #fcd34d"></span>سعر
                            أساسي مرجعي</span>
                        <span><span class="legend-dot" style="background:#f0fdf4;border:1px solid #86efac"></span>محسوب
                            تلقائياً</span>
                        <span><span class="legend-dot" style="background:#fff;border:1px solid #e2e8f0"></span>إدخال
                            يدوي</span>
                    </div>

                    <div id="linesWrap"></div>

                    <!-- الإجماليات -->
                    <div class="row g-3 mt-2">
                        <div class="col-md-5 ms-auto">
                            <div class="tot-box">
                                <div class="tot-row">
                                    <span style="color:#64748b">المجموع الجزئي</span>
                                    <span class="n" id="tSubtotal">0.00</span>
                                </div>
                                <div class="tot-row">
                                    <span style="color:#64748b">
                                        خصم %
                                        <input type="number" id="tDiscPct" min="0" max="100" value="0" step="0.01"
                                            style="width:48px;padding:1px 4px;font-size:.75rem;border:1px solid #e2e8f0;border-radius:5px;text-align:center;display:inline-block"
                                            oninput="calcTotals()">
                                    </span>
                                    <span class="n text-danger" id="tDiscAmt">-0.00</span>
                                </div>
                                <div class="tot-row">
                                    <span style="color:#64748b">
                                        ضريبة %
                                        <input type="number" id="tTaxPct" min="0" max="100" value="0" step="0.01"
                                            style="width:48px;padding:1px 4px;font-size:.75rem;border:1px solid #e2e8f0;border-radius:5px;text-align:center;display:inline-block"
                                            oninput="calcTotals()">
                                    </span>
                                    <span class="n" id="tTaxAmt">+0.00</span>
                                </div>
                                <div class="tot-row final">
                                    <span>الإجمالي</span>
                                    <span class="n" id="tTotal">0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button class="btn btn-sm btn-light" style="border-radius:8px"
                        data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-sm fw-600"
                        style="border-radius:8px;background:#1e3a8a;color:#fff;min-width:130px" onclick="saveInvoice()"
                        id="btnSaveInv">
                        <span id="saveInvTxt"><i class="bi bi-floppy me-1"></i>حفظ كمسودة</span>
                        <span id="saveInvSpin" class="spinner-border spinner-border-sm" style="display:none"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ مودال عرض فاتورة ══ -->
    <div class="modal fade" id="viewModal" tabindex="-1">
        <div class="modal-dialog modal-xl" style="max-width:1150px">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#0c447c,#1e3a8a);border-radius:16px 16px 0 0">
                    <h6 class="modal-title text-white fw-700 mb-0" id="vTitle">تفاصيل الفاتورة</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-3" id="vBody">
                    <div class="text-center py-4"><span class="spinner-border text-primary"></span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ مودال مورد جديد ══ -->
    <div class="modal fade" id="supplierModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#0c447c,#1e3a8a);border-radius:16px 16px 0 0">
                    <h6 class="modal-title text-white fw-700 mb-0"><i class="bi bi-truck me-2"></i>إضافة مورد مستهلكات
                    </h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pt-3">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="field-lbl">اسم المورد <span class="req">*</span></label>
                            <input type="text" id="spName" class="form-control form-control-sm"
                                placeholder="اسم الشركة أو المورد">
                        </div>
                        <div class="col-md-5">
                            <label class="field-lbl">نوع المورد</label>
                            <select id="spType" class="form-select form-select-sm">
                                <option value="wholesaler">موزّع بالجملة</option>
                                <option value="manufacturer">مصنّع</option>
                                <option value="distributor">موزّع</option>
                                <option value="retailer">تاجر تجزئة</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="field-lbl">جهة الاتصال</label>
                            <input type="text" id="spContact" class="form-control form-control-sm"
                                placeholder="اسم المسؤول">
                        </div>
                        <div class="col-md-6">
                            <label class="field-lbl">رقم الهاتف</label>
                            <input type="text" id="spPhone" class="form-control form-control-sm" dir="ltr"
                                placeholder="+963...">
                        </div>
                        <div class="col-12">
                            <label class="field-lbl">ملاحظات</label>
                            <textarea id="spNotes" class="form-control form-control-sm" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button class="btn btn-sm btn-light" style="border-radius:8px"
                        data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-sm fw-600" style="border-radius:8px;background:#1e3a8a;color:#fff"
                        onclick="saveSupplier()">
                        <i class="bi bi-plus-lg me-1"></i>إضافة
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= BASE_PATH ?>/assets/js/sidebar.js"></script>
    <script>
        // ── Modals ──
        const invModal = new bootstrap.Modal(document.getElementById('invModal'));
        const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
        const supplierModal = new bootstrap.Modal(document.getElementById('supplierModal'));

        // ── بيانات المواد والعملات ──
        const ITEMS = <?= json_encode(array_values($items_list)) ?>;
        const CURRENCIES = <?= json_encode(array_column($currencies, null, 'code')) ?>;
        const STATUS_MAP = <?= json_encode($STATUS_MAP) ?>;
        const BASE_CUR_SYM = <?= json_encode($baseCurSymbol) ?>;

        let lines = [];
        let symCur = '$';
        let codeCur = 'USD';
        let exRate = 1;
        let editingInvoiceId = null; // null = فاتورة جديدة، رقم = تعديل مسودة موجودة

        // ── AJAX ──
        function post(data) {
            const fd = new FormData();
            Object.entries(data).forEach(([k, v]) => fd.append(k, v ?? ''));
            return fetch(location.href, { method: 'POST', body: fd }).then(r => r.json());
        }
        function toast(msg, type = 'success') {
            const t = document.createElement('div');
            t.className = `alert alert-${type} shadow`;
            t.style.cssText = 'position:fixed;top:70px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:240px;text-align:center;font-size:.83rem;padding:.5rem 1.2rem';
            t.innerHTML = `<i class="bi bi-${type === 'success' ? 'check-circle-fill text-success' : 'exclamation-triangle-fill text-danger'} me-2"></i>${msg}`;
            document.body.appendChild(t);
            setTimeout(() => t.remove(), 3200);
        }

        // ── تغيير العملة ──
        function onCurrencyChange() {
            const sel = document.getElementById('iCurrency');
            const opt = sel.options[sel.selectedIndex];
            exRate = parseFloat(opt.dataset.rate) || 1;
            symCur = opt.dataset.sym || '$';
            codeCur = sel.value;
            document.getElementById('iExRate').value = exRate;
            document.getElementById('exRateHint').textContent = `1 ${codeCur} = ${(1 / exRate).toFixed(6)} $`;
            document.getElementById('invCurLbl2').textContent = '(' + symCur + ')';
            document.getElementById('invCurLbl3').textContent = '(' + symCur + ')';
            refreshAllLines();
            calcTotals();
            checkDatedRate(); // نتأكد إذا في سعر مؤرخ مسجل يفضّل السعر الافتراضي أعلاه
        }

        function onExRateChange() {
            exRate = Math.max(0.0001, parseFloat(document.getElementById('iExRate').value) || 1);
            document.getElementById('exRateHint').textContent = `1 ${codeCur} = ${(1 / exRate).toFixed(6)} $`;
            refreshAllLines();
            calcTotals();
        }

        // ── تفضيل سعر صرف مؤرخ (exchange_rates_ret) على سعر اليوم ──
        // ⚠ الحقل يضل مفتوح للتعديل اليدوي دايماً بكل الحالات — هاد
        // بس تعبئة تلقائية أولية، مش قفل. لو الجدول فاضي (الحالة
        // الحالية غالباً)، ما بيصير أي تغيير، ويضل السعر الافتراضي من
        // جدول currencies كما هو.
        function checkDatedRate() {
            const currency = document.getElementById('iCurrency').value;
            const date = document.getElementById('iDate').value;
            if (!currency || !date) return;
            post({ _action: 'get_dated_rate', currency, date }).then(d => {
                if (d.ok && d.rate) {
                    exRate = d.rate;
                    document.getElementById('iExRate').value = exRate.toFixed(6);
                    document.getElementById('exRateHint').innerHTML =
                        `<i class="bi bi-calendar-check me-1"></i>سعر مؤرخ (${d.rate_date}, ${d.source}): 1 ${codeCur} = ${(1 / exRate).toFixed(6)} $`;
                    refreshAllLines();
                    calcTotals();
                }
                // لو d.rate === null: ما في سعر مؤرخ مسجل، نسيب السعر الافتراضي الحالي كما هو (fallback)
            });
        }

        // ── إدارة الأسطر ──
        // ⚠⚠⚠ أداة تجريبية مؤقتة — احذفها قبل أي استخدام إنتاجي حقيقي ⚠⚠⚠
        function devResetPurchase() {
            const id = prompt('🔧 [تجريبي] رقم الفاتورة (id) يلي بدك ترجعها مسودة وتصفّر معها كل مخزون المستهلكات والقيود المرتبطة فيها:');
            if (!id || isNaN(id)) return;
            if (!confirm('⚠ هاد رح يصفّر رصيد كل المستهلكات بكل المستودعات (مش بس هالفاتورة)، ويحذف القيود المرتبطة، ويرجّع الفاتورة رقم ' + id + ' مسودة. متأكد؟')) return;
            post({ _action: 'dev_reset_purchase', id }).then(d => {
                if (d.ok) { toast(d.msg); setTimeout(() => location.reload(), 900); }
                else toast(d.msg, 'danger');
            });
        }

        function openNewInvoice() {
            editingInvoiceId = null;
            lines = [];
            document.getElementById('linesWrap').innerHTML = '';
            document.getElementById('iSupplier').value = '';
            document.getElementById('iSupplierRef').value = '';
            document.getElementById('iDate').value = new Date().toISOString().split('T')[0];
            document.getElementById('iPayMethod').value = 'deferred';
            document.getElementById('iNotes').value = '';
            document.getElementById('tDiscPct').value = '0';
            document.getElementById('tTaxPct').value = '0';
            document.getElementById('supplierInfo').style.display = 'none';
            document.getElementById('invModalTitle').textContent = 'فاتورة شراء مستهلكات جديدة';
            document.getElementById('invModalSubtitle').textContent = 'رقم الفاتورة يُولَّد تلقائياً';
            document.getElementById('saveInvTxt').innerHTML = '<i class="bi bi-floppy me-1"></i>حفظ كمسودة';
            onCurrencyChange();
            addLine();
            invModal.show();
        }

        // ⚠ تعديل مسودة موجودة — نفس مودال الإنشاء بالضبط، بس معبّى
        // ببيانات الفاتورة الحالية، وزر الحفظ بيستدعي update_purchase
        // بدل save_purchase. مسموح للمسودات فقط (نفس الشرط بالسيرفر).
        function openEditInvoice(id) {
            post({ _action: 'get_purchase', id }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                const p = d.data;
                if (p.status !== 'draft') { toast('لا يمكن تعديل فاتورة مؤكدة أو ملغاة', 'danger'); return; }

                editingInvoiceId = id;
                lines = [];
                document.getElementById('linesWrap').innerHTML = '';

                document.getElementById('iSupplier').value = p.supplier_id || '';
                onSupplierChange();
                document.getElementById('iWarehouse').value = p.warehouse_id || '';
                document.getElementById('iDate').value = p.invoice_date;
                document.getElementById('iCurrency').value = p.currency;
                document.getElementById('iSupplierRef').value = p.supplier_ref || '';
                document.getElementById('iPayMethod').value = p.payment_method || 'deferred';
                document.getElementById('iNotes').value = p.notes || '';
                document.getElementById('tDiscPct').value = p.discount_pct || 0;
                document.getElementById('tTaxPct').value = p.tax_pct || 0;

                // ⚠ نضبط العملة يدوياً بدون استدعاء onCurrencyChange() —
                // لأنها بتشغّل checkDatedRate() (بحث غير متزامن) يلي ممكن
                // يوصل رده متأخر ويطغى على سعر الصرف الصحيح المحفوظ
                // بالفاتورة نفسها أصلاً. إحنا هون عندنا السعر الحقيقي
                // المستخدم وقتها، ما بنحتاج نبحث عن سعر بديل.
                const curOpt = document.getElementById('iCurrency').selectedOptions[0];
                symCur = curOpt?.dataset.sym || '$';
                codeCur = p.currency;
                exRate = parseFloat(p.exchange_rate) || 1;
                document.getElementById('iExRate').value = exRate;
                document.getElementById('exRateHint').textContent = `1 ${codeCur} = ${(1 / exRate).toFixed(6)} $`;
                document.getElementById('invCurLbl2').textContent = '(' + symCur + ')';
                document.getElementById('invCurLbl3').textContent = '(' + symCur + ')';

                // إعادة بناء كل بند — استرجاع الكمية/السعر كما أُدخلوا
                // أصلاً بوحدة العبوة المختارة وقتها (لو في عبوة)، مش
                // بوحدة المخزون الأساسية المحفوظة بقاعدة البيانات
                (p.items || []).forEach(it => {
                    const idx = lines.length;
                    const factor = it.packaging_id ? (parseFloat(it.qty_per_package) || 1) : 1;
                    const enteredQty = it.packaging_id ? parseFloat(it.packaging_qty) : parseFloat(it.quantity);
                    const enteredPrice = parseFloat(it.unit_price_orig) * factor;
                    lines.push({
                        item_id: String(it.item_id),
                        packaging_id: it.packaging_id ? String(it.packaging_id) : '',
                        factor,
                        qty: enteredQty,
                        unit_price_orig: enteredPrice,
                        discount_pct: parseFloat(it.discount_pct) || 0,
                        total_orig: 0, unit_price_base: 0, total_base: 0
                    });
                    buildLineDOM(idx, false);
                    const div = document.getElementById('line_' + idx);
                    const selEl = div.querySelector('.item-sel');
                    const unitSelEl = div.querySelector('.unit-sel');
                    const qtyUnitLabelEl = div.querySelector('.qty-unit-label');
                    const priceUnitLabelEl = div.querySelector('.price-unit-label');
                    const { qtyEl, prEl, discEl, usdEl, totEl } = div._els;

                    selEl.value = String(it.item_id);
                    const itemObj = ITEMS.find(x => x.id == it.item_id);
                    if (itemObj) {
                        const pkgs = itemObj.packagings || [];
                        unitSelEl.disabled = false;
                        unitSelEl.innerHTML = `<option value="" data-factor="1" data-name="${itemObj.unit}">${itemObj.unit} (وحدة المخزون)</option>` +
                            pkgs.map(pk => `<option value="${pk.id}" data-factor="${pk.qty_per_package}" data-name="${pk.name}">${pk.name} (= ${parseFloat(pk.qty_per_package).toFixed(2)} ${itemObj.unit})</option>`).join('');
                        unitSelEl.value = it.packaging_id ? String(it.packaging_id) : '';
                        const unitName = unitSelEl.options[unitSelEl.selectedIndex]?.dataset.name || itemObj.unit;
                        qtyUnitLabelEl.textContent = `(${unitName})`;
                        priceUnitLabelEl.textContent = `(${unitName})`;
                    }
                    qtyEl.value = enteredQty;
                    prEl.value = enteredPrice.toFixed(4);
                    discEl.value = it.discount_pct || 0;
                    recalcLine(idx, { qtyEl, prEl, discEl, usdEl, totEl });
                });

                document.getElementById('invModalTitle').textContent = 'تعديل فاتورة رقم ' + p.invoice_no;
                document.getElementById('invModalSubtitle').textContent = 'التعديل مسموح للمسودات فقط';
                document.getElementById('saveInvTxt').innerHTML = '<i class="bi bi-floppy me-1"></i>حفظ التعديلات';
                calcTotals();
                invModal.show();
            });
        }

        function addLine() {
            const idx = lines.length;
            lines.push({ item_id: '', packaging_id: '', factor: 1, qty: 1, unit_price_orig: 0, discount_pct: 0, total_orig: 0, unit_price_base: 0, total_base: 0 });
            buildLineDOM(idx);
        }

        function buildLineDOM(idx, isNew = true) {
            const wrap = document.getElementById('linesWrap');
            const div = document.createElement('div');
            div.className = 'inv-row';
            div.id = 'line_' + idx;

            div.innerHTML = `
    <div class="inv-row-top">
      <select class="form-select form-select-sm item-sel">
        <option value="">— اختر المادة —</option>
        ${ITEMS.map(it => `<option value="${it.id}">${it.name} (${it.unit})</option>`).join('')}
      </select>
      <button type="button" class="del-btn" title="حذف السطر"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="inv-row-fields">
      <div class="line-field" style="width:100px">
        <label>الكمية <span class="qty-unit-label" style="color:#0891b2;font-weight:600"></span></label>
        <input type="number" min="0.001" step="0.001" value="1" dir="ltr" class="qty-in">
      </div>
      <div class="line-field" style="width:170px">
        <label>الوحدة</label>
        <select class="form-select form-select-sm unit-sel" disabled>
          <option value="">وحدة المادة</option>
        </select>
      </div>
      <div class="line-field" style="width:190px">
        <label>السعر الأساسي <small style="color:#92400e">(مرجع)</small></label>
        <input type="text" readonly class="ref-in ref" dir="ltr" title="">
      </div>
      <div class="line-field" style="width:150px">
        <label>السعر <span class="price-unit-label" style="color:#0891b2;font-weight:600"></span></label>
        <input type="number" min="0" step="0.0001" value="" dir="ltr" class="pr-in">
        <small class="price-per-stock-hint" style="color:#0891b2;font-size:.68rem"></small>
      </div>
      <div class="line-field" style="width:85px">
        <label>خصم %</label>
        <input type="number" min="0" max="100" step="0.01" value="0" dir="ltr" class="disc-in">
      </div>
      <div class="line-field" style="width:110px">
        <label>سعر/$ <small style="color:#16a34a">(تلقائي)</small></label>
        <input type="number" readonly class="usd-in calc" dir="ltr">
      </div>
      <div class="line-field" style="width:150px">
        <label>الإجمالي <small id="lblTot${idx}" style="color:#1e3a8a"></small></label>
        <input type="number" readonly class="tot-in calc" dir="ltr">
      </div>
    </div>
    <div class="conv-hint"></div>`;

            wrap.prepend(div);

            // لمسة UX: تظليل خفيف مؤقت + تركيز تلقائي على حقل اختيار
            // المادة فور ظهورها فوق — بس عند إضافة سطر جديد فعلياً، مش
            // وقت إعادة البناء بعد حذف سطر (حتى ما يقفز التركيز بشكل مربك)
            if (isNew) {
                div.style.animation = 'newRowHighlight 900ms ease-out';
                setTimeout(() => { div.style.animation = ''; }, 900);
            }

            // مراجع العناصر — بالاسم (class) لا بالترتيب، أوضح وأصعب ينكسر بالخطأ
            const selEl = div.querySelector('.item-sel');
            const unitSelEl = div.querySelector('.unit-sel');
            const qtyEl = div.querySelector('.qty-in');
            const refEl = div.querySelector('.ref-in');
            const prEl = div.querySelector('.pr-in');
            const discEl = div.querySelector('.disc-in');
            const usdEl = div.querySelector('.usd-in');
            const totEl = div.querySelector('.tot-in');
            const delBtn = div.querySelector('.del-btn');
            const hintEl = div.querySelector('.conv-hint');
            const priceHintEl = div.querySelector('.price-per-stock-hint');
            const qtyUnitLabelEl = div.querySelector('.qty-unit-label');
            const priceUnitLabelEl = div.querySelector('.price-unit-label');

            // تركيز تلقائي على حقل اختيار المادة — بس للسطر المُضاف
            // حديثاً فعلياً، مش أثناء إعادة البناء بعد حذف سطر
            if (isNew) setTimeout(() => selEl.focus(), 50);

            // زر الحذف
            delBtn.addEventListener('click', () => removeLine(idx));

            function updateConvHint() {
                const it = ITEMS.find(x => x.id == lines[idx].item_id);
                const factor = lines[idx].factor || 1;
                const qty = parseFloat(qtyEl.value) || 0;
                const price = parseFloat(prEl.value) || 0;

                if (it && factor !== 1) {
                    hintEl.innerHTML = `<i class="bi bi-arrow-left-right me-1"></i>= ${(qty * factor).toFixed(3)} ${it.unit} بوحدة المخزون`;
                    // ⚠ توضيح إنه السعر المُدخل هو لكل "وحدة مختارة" (عبوة)
                    // كاملة، مش لكل وحدة مخزون أساسية — نفس اللبس يلي
                    // صار (سعر الوقية بالغلط فُهم كسعر الغرام)
                    const pricePerStock = factor > 0 ? price / factor : 0;
                    priceHintEl.textContent = price > 0 ? `= ${pricePerStock.toFixed(4)} لكل ${it.unit}` : '';
                } else {
                    hintEl.textContent = '';
                    priceHintEl.textContent = '';
                }
            }

            // ⚠ اقتراح السعر المرجعي — دالة موحّدة تُستدعى عند تغيير
            // المادة أو تغيير الوحدة/العبوة، وبتاخد بعين الاعتبار معامل
            // العبوة الحالي (factor) دايماً. قبل هالإصلاح، اختيار عبوة
            // (كرتونة/علبة) كان بيحدّث التحويل بس بيسيب السعر المقترح
            // بدون ضرب بمعامل العبوة — فيصير السعر المقترح لوحدة
            // المخزون الأساسية (غرام مثلاً) بينما الكمية المُدخلة صارت
            // بوحدة العبوة (علبة)، وهيك يطلع الإجمالي شبه صفر ومفكوك
            // عن باقي الحقول.
            function applyReferencePrice() {
                const it = ITEMS.find(x => x.id == lines[idx].item_id);
                if (!it) {
                    refEl.value = '—';
                    refEl.title = '';
                    qtyUnitLabelEl.textContent = '';
                    priceUnitLabelEl.textContent = '';
                    return;
                }
                const factor = lines[idx].factor || 1;
                const lastUsd = parseFloat(it.last_purchase_price_base) || 0;
                const estCost = parseFloat(it.estimated_cost) || 0;
                const itemSym = it.currency_symbol || '$';
                const itemRate = parseFloat(it.currency_rate) || 1;
                const factorSuffix = factor !== 1 ? ` × ${factor} (للعبوة المختارة)` : '';

                // ⚠ اسم الوحدة المختارة فعلياً (وقية/كرتونة/غرام...) يُقرأ
                // من data-name بخيار unit-sel المحدد — نفس التسمية تظهر
                // بعنوان حقلي "الكمية" و"السعر" مباشرة، حتى ما يصير لبس
                // متل يلي صار (سعر الوقية اتفهم غلط كسعر الغرام)
                const selectedUnitName = unitSelEl.options[unitSelEl.selectedIndex]?.dataset.name || it.unit;
                qtyUnitLabelEl.textContent = `(${selectedUnitName})`;
                priceUnitLabelEl.textContent = `(${selectedUnitName})`;

                let refText, suggested = null;
                if (lastUsd > 0) {
                    refText = lastUsd.toFixed(4) + ' $ (آخر شراء)' + factorSuffix;
                    suggested = lastUsd * exRate * factor;
                } else if (estCost > 0) {
                    refText = estCost.toFixed(4) + ' ' + itemSym + ' (تقديري)' + factorSuffix;
                    const estUsd = estCost / itemRate;
                    suggested = estUsd * exRate * factor;
                } else {
                    refText = '—';
                }
                refEl.value = refText;
                refEl.title = refText;
                if (suggested !== null) {
                    prEl.value = suggested.toFixed(4);
                    lines[idx].unit_price_orig = suggested;
                }
            }

            // عند اختيار مادة
            selEl.addEventListener('change', function () {
                const it = ITEMS.find(x => x.id == this.value);
                lines[idx].item_id = this.value;
                lines[idx].packaging_id = '';
                lines[idx].factor = 1;

                // إعادة بناء قائمة الوحدة/العبوة الخاصة بهذه المادة تحديداً
                if (it) {
                    const pkgs = it.packagings || [];
                    unitSelEl.disabled = false;
                    unitSelEl.innerHTML = `<option value="" data-factor="1" data-name="${it.unit}">${it.unit} (وحدة المخزون)</option>` +
                        pkgs.map(pk => `<option value="${pk.id}" data-factor="${pk.qty_per_package}" data-name="${pk.name}">${pk.name} (= ${parseFloat(pk.qty_per_package).toFixed(2)} ${it.unit})</option>`).join('');
                } else {
                    unitSelEl.disabled = true;
                    unitSelEl.innerHTML = '<option value="">وحدة المادة</option>';
                }

                applyReferencePrice();
                updateConvHint();
                recalcLine(idx, { qtyEl, prEl, discEl, usdEl, totEl });
            });

            // عند اختيار عبوة (كرتونة...) بدل وحدة المخزون الأساسية
            unitSelEl.addEventListener('change', function () {
                const opt = this.options[this.selectedIndex];
                lines[idx].packaging_id = this.value || '';
                lines[idx].factor = parseFloat(opt?.dataset.factor) || 1;
                applyReferencePrice(); // ⚠ هذا كان الناقص — إعادة حساب السعر المقترح بمعامل العبوة الجديد
                updateConvHint();
                recalcLine(idx, { qtyEl, prEl, discEl, usdEl, totEl });
            });

            // تحديث عند تغيير الكمية أو السعر أو الخصم
            [qtyEl, prEl, discEl].forEach(el => {
                el.addEventListener('input', () => {
                    lines[idx].qty = parseFloat(qtyEl.value) || 0;
                    lines[idx].unit_price_orig = parseFloat(prEl.value) || 0;
                    lines[idx].discount_pct = parseFloat(discEl.value) || 0;
                    updateConvHint();
                    recalcLine(idx, { qtyEl, prEl, discEl, usdEl, totEl });
                });
            });

            // حفظ مراجع العناصر للتحديث الخارجي
            div._els = { qtyEl, prEl, discEl, usdEl, totEl };
        }

        function recalcLine(idx, els) {
            const l = lines[idx];
            const qty = parseFloat(els.qtyEl.value) || 0;
            const pr = parseFloat(els.prEl.value) || 0;
            const disc = parseFloat(els.discEl.value) || 0;
            const rate = exRate || 1;

            l.qty = qty;
            l.unit_price_orig = pr;
            l.discount_pct = disc;
            l.unit_price_base = rate > 0 ? pr / rate : 0;
            l.total_orig = qty * pr * (1 - disc / 100);
            l.total_base = l.total_orig / rate;

            els.usdEl.value = l.unit_price_base > 0 ? l.unit_price_base.toFixed(4) : '';
            els.totEl.value = l.total_orig > 0 ? l.total_orig.toFixed(2) : '';
            calcTotals();
        }

        function refreshAllLines() {
            document.querySelectorAll('#linesWrap .inv-row').forEach((div, idx) => {
                if (!div._els || !lines[idx]) return;
                const { prEl, qtyEl, discEl, usdEl, totEl } = div._els;
                // إعادة حساب بالسعر الجديد
                lines[idx].unit_price_base = exRate > 0 ? (lines[idx].unit_price_orig / exRate) : 0;
                lines[idx].total_base = lines[idx].total_orig / exRate;
                usdEl.value = lines[idx].unit_price_base > 0 ? lines[idx].unit_price_base.toFixed(4) : '';
                totEl.value = lines[idx].total_orig > 0 ? lines[idx].total_orig.toFixed(2) : '';
            });
        }

        function removeLine(idx) {
            lines.splice(idx, 1);
            // إعادة بناء الكل فقط عند الحذف
            const saved = JSON.parse(JSON.stringify(lines));
            lines = [];
            document.getElementById('linesWrap').innerHTML = '';
            saved.forEach((l, i) => {
                lines.push(l);
                buildLineDOM(i, false);
                const div = document.getElementById('line_' + i);
                if (!div || !div._els) return;
                const { qtyEl, prEl, discEl, usdEl, totEl } = div._els;

                // ⚠ استرجاع اختيار المادة والوحدة/العبوة بصرياً كمان —
                // كانت تنسى بعد إعادة البناء (البيانات بالخلفية كانت
                // محفوظة صح، بس العرض بيرجع فاضي). نعيد تعبئتهم مباشرة
                // بدل ما نشغّل حدث "change" الكامل، حتى ما يطغى على
                // السعر/الكمية المحفوظين بقيمهم الافتراضية من جديد.
                const selEl = div.querySelector('.item-sel');
                const unitSelEl = div.querySelector('.unit-sel');
                const qtyUnitLabelEl = div.querySelector('.qty-unit-label');
                const priceUnitLabelEl = div.querySelector('.price-unit-label');
                selEl.value = l.item_id || '';
                const it = ITEMS.find(x => x.id == l.item_id);
                if (it) {
                    const pkgs = it.packagings || [];
                    unitSelEl.disabled = false;
                    unitSelEl.innerHTML = `<option value="" data-factor="1" data-name="${it.unit}">${it.unit} (وحدة المخزون)</option>` +
                        pkgs.map(pk => `<option value="${pk.id}" data-factor="${pk.qty_per_package}" data-name="${pk.name}">${pk.name} (= ${parseFloat(pk.qty_per_package).toFixed(2)} ${it.unit})</option>`).join('');
                    unitSelEl.value = l.packaging_id || '';
                    const selectedUnitName = unitSelEl.options[unitSelEl.selectedIndex]?.dataset.name || it.unit;
                    qtyUnitLabelEl.textContent = `(${selectedUnitName})`;
                    priceUnitLabelEl.textContent = `(${selectedUnitName})`;
                }

                qtyEl.value = l.qty || 1;
                prEl.value = l.unit_price_orig || '';
                discEl.value = l.discount_pct || 0;
                recalcLine(i, { qtyEl, prEl, discEl, usdEl, totEl });
            });
            calcTotals();
        }

        // ── حساب الإجماليات ──
        function calcTotals() {
            const subtotal = lines.reduce((s, l) => s + (l.total_orig || 0), 0);
            const discPct = parseFloat(document.getElementById('tDiscPct').value) || 0;
            const taxPct = parseFloat(document.getElementById('tTaxPct').value) || 0;
            const discAmt = subtotal * discPct / 100;
            const taxAmt = (subtotal - discAmt) * taxPct / 100;
            const total = subtotal - discAmt + taxAmt;

            document.getElementById('tSubtotal').textContent = subtotal.toFixed(2) + ' ' + symCur;
            document.getElementById('tDiscAmt').textContent = '-' + discAmt.toFixed(2) + ' ' + symCur;
            document.getElementById('tTaxAmt').textContent = '+' + taxAmt.toFixed(2) + ' ' + symCur;
            document.getElementById('tTotal').textContent = total.toFixed(2) + ' ' + symCur;
        }

        // ── حفظ الفاتورة ──
        function saveInvoice() {
            if (!document.getElementById('iWarehouse').value) { toast('يجب اختيار المستودع', 'danger'); return; }
            const validLines = lines.filter(l => l.item_id && l.qty > 0 && l.unit_price_orig > 0);
            if (!validLines.length) { toast('يجب إضافة مادة واحدة على الأقل بسعر وكمية', 'danger'); return; }

            document.getElementById('saveInvTxt').style.opacity = '0';
            document.getElementById('saveInvSpin').style.display = 'inline-block';

            const payload = {
                _action: editingInvoiceId ? 'update_purchase' : 'save_purchase',
                supplier_id: document.getElementById('iSupplier').value,
                warehouse_id: document.getElementById('iWarehouse').value,
                invoice_date: document.getElementById('iDate').value,
                supplier_ref: document.getElementById('iSupplierRef').value,
                payment_method: document.getElementById('iPayMethod').value,
                currency: codeCur,
                exchange_rate: exRate,
                discount_pct: document.getElementById('tDiscPct').value,
                tax_pct: document.getElementById('tTaxPct').value,
                notes: document.getElementById('iNotes').value,
                rows: JSON.stringify(validLines),
            };
            if (editingInvoiceId) payload.id = editingInvoiceId;

            post(payload).then(d => {
                document.getElementById('saveInvTxt').style.opacity = '1';
                document.getElementById('saveInvSpin').style.display = 'none';
                if (d.ok) {
                    toast('✅ ' + d.msg + (d.invoice_no ? ' — ' + d.invoice_no : ''));
                    editingInvoiceId = null;
                    invModal.hide();
                    setTimeout(() => location.reload(), 1000);
                } else toast(d.msg, 'danger');
            });
        }

        // ── عرض فاتورة ──
        function viewInvoice(id) {
            document.getElementById('vTitle').textContent = 'جارٍ التحميل...';
            document.getElementById('vBody').innerHTML = '<div class="text-center py-4"><span class="spinner-border text-primary"></span></div>';
            viewModal.show();
            post({ _action: 'get_purchase', id }).then(d => {
                if (!d.ok) { document.getElementById('vBody').innerHTML = `<div class="text-danger p-3">${d.msg}</div>`; return; }
                const p = d.data;
                const st = STATUS_MAP[p.status] || STATUS_MAP['draft'];
                const sym = p.currency_symbol || '$';
                const cur = p.currency_code || 'USD';
                const rate = parseFloat(p.exchange_rate) || 1;
                const isUSD = cur === 'USD';

                document.getElementById('vTitle').textContent = 'فاتورة: ' + p.invoice_no;

                const itemsHtml = (p.items || []).map(it => {
                    const unitOrig = parseFloat(it.unit_price_orig || it.unit_price_base);
                    const totalOrig = parseFloat(it.total_orig || it.total_base);
                    const unitBase = parseFloat(it.unit_price_base);
                    const totalBase = parseFloat(it.total_base);
                    const qtyLabel = it.packaging_name
                        ? `${parseFloat(it.packaging_qty).toFixed(2)} ${it.packaging_name} <small style="color:#94a3b8">(=${parseFloat(it.quantity).toFixed(2)} ${it.unit})</small>`
                        : `${parseFloat(it.quantity).toFixed(3)}`;
                    return `<tr>
                <td style="white-space:nowrap">${it.item_name} <small style="color:#94a3b8">(${it.unit})</small></td>
                <td class="n text-center" style="white-space:nowrap">${qtyLabel}</td>
                <td class="n text-center" style="white-space:nowrap">${unitOrig.toFixed(4)} ${sym}</td>
                <td class="n text-center">${parseFloat(it.discount_pct).toFixed(0)}%</td>
                <td class="n text-center" style="color:#16a34a;white-space:nowrap">${unitBase.toFixed(4)} ${BASE_CUR_SYM}</td>
                <td class="n text-end fw-600" style="white-space:nowrap">${totalOrig.toFixed(2)} ${sym}</td>
                <td class="n text-end fw-600" style="color:#16a34a;white-space:nowrap">${totalBase.toFixed(2)} ${BASE_CUR_SYM}</td>
            </tr>`;
                }).join('');

                const tO = parseFloat(p.total_orig || p.total_base);
                const pO = parseFloat(p.paid_orig || p.paid_base);
                const bO = parseFloat(p.balance_orig || p.balance_base);
                const sO = parseFloat(p.subtotal_orig || p.subtotal_base);
                const dO = parseFloat(p.discount_orig || p.discount_base);
                const xO = parseFloat(p.tax_orig || p.tax_base);
                const pB = parseFloat(p.paid_base);
                const bB = parseFloat(p.balance_base);

                document.getElementById('vBody').innerHTML = `
        <div class="row g-2 mb-3">
          <div class="col-6"><small style="color:#64748b">المورد</small><div class="fw-600">${p.supplier_name || '—'}</div></div>
          <div class="col-6"><small style="color:#64748b">المستودع</small><div class="fw-600">${p.wh_name || '—'}</div></div>
          <div class="col-4"><small style="color:#64748b">التاريخ</small><div>${p.invoice_date}</div></div>
          <div class="col-4"><small style="color:#64748b">العملة</small>
            <div><span class="badge bg-secondary-subtle text-secondary">${cur}</span>
            ${!isUSD ? `<small style="color:#64748b"> (1$=${rate} ${sym})</small>` : ''}</div>
          </div>
          <div class="col-4"><small style="color:#64748b">الحالة</small>
            <div><span class="badge ${st.cls}">${st.label}</span></div>
          </div>
          ${p.supplier_ref ? `<div class="col-12"><small style="color:#64748b">رقم فاتورة المورد</small><div dir="ltr">${p.supplier_ref}</div></div>` : ''}
        </div>
        <div class="table-responsive">
        <table class="mtbl mb-3" style="font-size:.78rem">
          <thead><tr style="background:#f8fafc">
            <th>المادة</th>
            <th class="text-center">الكمية</th>
            <th class="text-center">سعر الوحدة (عملة الفاتورة)</th>
            <th class="text-center">خصم</th>
            <th class="text-center" style="color:#16a34a">سعر الوحدة (عملة الفرع)</th>
            <th class="text-end">الإجمالي (عملة الفاتورة)</th>
            <th class="text-end" style="color:#16a34a">الإجمالي (عملة الفرع)</th>
          </tr></thead>
          <tbody>${itemsHtml}</tbody>
        </table>
        </div>
        <div style="background:#f8fafc;border-radius:10px;padding:10px 14px">
          <div class="d-flex justify-content-between mb-1" style="font-size:.8rem">
            <span style="color:#64748b">المجموع</span>
            <span class="n">${sO.toFixed(2)} ${sym}${!isUSD ? ` <small style="color:#94a3b8">(${parseFloat(p.subtotal_base).toFixed(2)} ${BASE_CUR_SYM})</small>` : ''}</span>
          </div>
          ${dO > 0 ? `<div class="d-flex justify-content-between mb-1" style="font-size:.8rem">
            <span style="color:#64748b">خصم (${p.discount_pct}%)</span>
            <span class="n text-danger">-${dO.toFixed(2)} ${sym}</span></div>` : ''}
          ${xO > 0 ? `<div class="d-flex justify-content-between mb-1" style="font-size:.8rem">
            <span style="color:#64748b">ضريبة (${p.tax_pct}%)</span>
            <span class="n">+${xO.toFixed(2)} ${sym}</span></div>` : ''}
          <div class="d-flex justify-content-between fw-700 mb-1" style="font-size:.9rem;border-top:1px solid #e2e8f0;padding-top:6px">
            <span>الإجمالي</span>
            <span class="n">${tO.toFixed(2)} ${sym}${!isUSD ? ` <small style="color:#94a3b8;font-weight:400">(${parseFloat(p.total_base).toFixed(2)} ${BASE_CUR_SYM})</small>` : ''}</span>
          </div>
          <div class="d-flex justify-content-between" style="font-size:.8rem">
            <span style="color:#16a34a">المدفوع</span><span class="n text-success">${pO.toFixed(2)} ${sym}${!isUSD ? ` <small style="color:#94a3b8">(${pB.toFixed(2)} ${BASE_CUR_SYM})</small>` : ''}</span>
          </div>
          <div class="d-flex justify-content-between" style="font-size:.8rem">
            <span style="color:#dc2626">المتبقي</span><span class="n text-danger">${bO.toFixed(2)} ${sym}${!isUSD ? ` <small style="color:#94a3b8">(${bB.toFixed(2)} ${BASE_CUR_SYM})</small>` : ''}</span>
          </div>
        </div>
        ${p.notes ? `<div style="background:#f8fafc;border-radius:8px;padding:8px 12px;margin-top:10px;font-size:.78rem;color:#64748b">${p.notes}</div>` : ''}`;
            });
        }

        // ── تأكيد فاتورة ──
        function confirmInvoice(id, no) {
            if (!confirm(`تأكيد الفاتورة "${no}"؟\nسيتم تحديث المخزون وآخر سعر شراء للمواد.`)) return;
            post({ _action: 'confirm_purchase', id }).then(d => {
                if (d.ok) { toast('✅ ' + d.msg); setTimeout(() => location.reload(), 700); }
                else toast(d.msg, 'danger');
            });
        }

        // ── إلغاء فاتورة ──
        function cancelInvoice(id, no) {
            if (!confirm(`إلغاء الفاتورة "${no}"؟\nالفواتير المؤكدة سيتم عكس مخزونها.`)) return;
            post({ _action: 'cancel_purchase', id }).then(d => {
                if (d.ok) { toast(d.msg); setTimeout(() => location.reload(), 700); }
                else toast(d.msg, 'danger');
            });
        }

        // ── المورد ──
        function onSupplierChange() {
            const sel = document.getElementById('iSupplier');
            const opt = sel.options[sel.selectedIndex];
            const info = document.getElementById('supplierInfo');
            if (sel.value && opt.dataset.phone) {
                document.getElementById('supplierPhone').textContent = opt.dataset.phone;
                info.style.display = 'block';
            } else {
                info.style.display = 'none';
            }
        }

        function openSupplierModal() {
            ['spName', 'spContact', 'spPhone', 'spNotes'].forEach(id => document.getElementById(id).value = '');
            document.getElementById('spType').value = 'wholesaler';
            supplierModal.show();
        }

        function saveSupplier() {
            const name = document.getElementById('spName').value.trim();
            if (!name) { toast('اسم المورد مطلوب', 'danger'); return; }
            post({
                _action: 'add_supplier',
                name,
                contact_person: document.getElementById('spContact').value,
                phone: document.getElementById('spPhone').value,
                type: document.getElementById('spType').value,
                notes: document.getElementById('spNotes').value,
            }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                const sel = document.getElementById('iSupplier');
                const opt = document.createElement('option');
                opt.value = d.id;
                opt.dataset.phone = d.phone || '';
                opt.dataset.contact = d.contact || '';
                opt.textContent = d.name;
                opt.selected = true;
                sel.appendChild(opt);
                onSupplierChange();
                supplierModal.hide();
                toast('تمت إضافة المورد');
            });
        }
    
        // ══════════════════════════════════════════════════════════
        // فرز الجداول بالنقر على رأس العمود — عام لأي جدول بالصفحة
        // ══════════════════════════════════════════════════════════
        function makeSortable(table) {
            if (!table) return;
            const headers = table.querySelectorAll('thead th');
            headers.forEach((th, colIndex) => {
                if (th.hasAttribute('data-no-sort')) return;
                th.style.cursor = 'pointer';
                th.style.userSelect = 'none';
                th.title = 'اضغط للفرز';
                th.addEventListener('click', () => sortTableByColumn(table, colIndex, th));
            });
        }

        function sortTableByColumn(table, colIndex, th) {
            const tbody = table.querySelector('tbody');
            if (!tbody) return;
            const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.children.length > colIndex);
            const isAsc = th.getAttribute('data-sort-dir') !== 'asc';

            table.querySelectorAll('thead th').forEach(h => {
                h.removeAttribute('data-sort-dir');
                const ind = h.querySelector('.sort-ind');
                if (ind) ind.remove();
            });
            th.setAttribute('data-sort-dir', isAsc ? 'asc' : 'desc');

            const getCellValue = (row) => (row.children[colIndex]?.innerText || '').trim();

            // ⚠ ترتيب فحص القيمة مهم: تاريخ أولاً (YYYY-MM-DD) — لأنه
            // parseFloat على "2026-07-21" كان بيرجّع 2026 بس (بيوقف
            // عند أول شرطة)، فكل تواريخ نفس السنة كانت تطلع "متساوية"
            // ومفيش فرز فعلي بينهم. بعدين رقم صرف (تحقق مطابقة كاملة
            // للنص، مش بس بادئة)، وأخيراً نص عربي عادي.
            const isoDateRe = /^\d{4}-\d{2}-\d{2}/;
            rows.sort((a, b) => {
                const valA = getCellValue(a), valB = getCellValue(b);
                if (isoDateRe.test(valA) && isoDateRe.test(valB)) {
                    const dA = new Date(valA.slice(0, 10)).getTime();
                    const dB = new Date(valB.slice(0, 10)).getTime();
                    return isAsc ? dA - dB : dB - dA;
                }
                const cleanA = valA.replace(/[^0-9.\-]/g, '');
                const cleanB = valB.replace(/[^0-9.\-]/g, '');
                const fullyNumeric = /^-?[0-9]+(\.[0-9]+)?$/.test(cleanA) && /^-?[0-9]+(\.[0-9]+)?$/.test(cleanB)
                    && cleanA !== '' && cleanB !== '' && cleanA !== '-' && cleanB !== '-';
                if (fullyNumeric) {
                    return isAsc ? parseFloat(cleanA) - parseFloat(cleanB) : parseFloat(cleanB) - parseFloat(cleanA);
                }
                return isAsc ? valA.localeCompare(valB, 'ar') : valB.localeCompare(valA, 'ar');
            });

            rows.forEach(row => tbody.appendChild(row));

            const ind = document.createElement('i');
            ind.className = 'bi bi-caret-' + (isAsc ? 'up' : 'down') + '-fill sort-ind';
            ind.style.cssText = 'font-size:.65rem;margin-right:4px';
            th.appendChild(ind);
        }

        makeSortable(document.getElementById('purchasesTbl'));
    </script>
</body>

</html>