<?php
/**
 * purchases/invoice_edit.php — تعديل فاتورة شراء (مسودة فقط)
 *retail1/modules/purchases/invoice_edit.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('purchases.invoices', 'edit');
$currentModule = 'purchases.invoices';

$TS = $_SESSION['table_suffix'];
$TP = "purchases_{$TS}";
$TPI = "purchase_items_{$TS}";
$TSP = "product_suppliers_{$TS}";
$TW = "warehouses_{$TS}";
$TWI = "warehouse_items_{$TS}";
$TV = "product_variants_{$TS}";
$TSZ = "product_sizes_{$TS}";
$TPROD = "products_{$TS}";
$TCL = "product_colors_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// ── تحميل الفاتورة المطلوب تعديلها ──────────────────────────────────
$purchaseId = (int) ($_GET['id'] ?? 0);
if (!$purchaseId) {
    http_response_code(404);
    exit('رقم الفاتورة غير محدَّد.');
}
$existingPur = $pdo->prepare("SELECT * FROM `{$TP}` WHERE id = ? LIMIT 1");
$existingPur->execute([$purchaseId]);
$existingPur = $existingPur->fetch(PDO::FETCH_ASSOC);
if (!$existingPur) {
    http_response_code(404);
    exit('الفاتورة غير موجودة.');
}
// ⚠ منع صريح: التعديل مسموح للمسودات فقط (نفس قاعدة البلوبرينت العامة:
// {section}_edit.php = نفس قيود new.php + رفض التعديل لو الحالة ≠ draft).
// بدل صفحة خطأ منفصلة، تحويل مباشر لمودال التفاصيل بقائمة الفواتير —
// قرار صريح بدل ما نبني صفحة خطأ لحالها.
if ($existingPur['status'] !== 'draft') {
    header('Location: index.php?view_id=' . $purchaseId);
    exit;
}

// ── إعادة بناء نسب الخصم/الضريبة من القيم المحفوظة ──────────────────
// جدول purchases يخزّن القيمة المحسوبة (discount_amount/tax_amount) لا
// النسبة الأصلية (discPct/taxPct) — بنعيد اشتقاقها هون حتى نعبّي حقول
// النسب بالواجهة بنفس القيم يلي المستخدم كتبها أصلاً وقت الإنشاء.
$existingTotalAmt = (float) $existingPur['total_amount'];
$existingDiscAmt = (float) $existingPur['discount_amount'];
$existingTaxAmt = (float) $existingPur['tax_amount'];
$existingDiscPct = $existingTotalAmt > 0 ? round($existingDiscAmt / $existingTotalAmt * 100, 4) : 0;
$existingAfterDisc = $existingTotalAmt - $existingDiscAmt;
$existingTaxPct = $existingAfterDisc > 0 ? round($existingTaxAmt / $existingAfterDisc * 100, 4) : 0;

// ── أسعار الصرف: عملة الفرع الأساسية + نسبة كل عملة إليها ──────────
// نُحمّل هذا هنا (قبل معالج AJAX) لأن search_product يحتاجه أيضاً —
// سابقاً كان محسوباً فقط بقسم عرض الصفحة (بعد exit مباشر AJAX)، فكان
// endpoint البحث عن منتج لا يعرف شيئاً عن عملة أي منتج إطلاقاً.
$branchCurRow = $pdo->prepare("SELECT c.id, c.code, c.symbol, c.exchange_rate, b.invoice_prefix, b.default_purchase_tax_pct FROM branches b
    JOIN currencies c ON c.id = b.base_currency_id
    WHERE b.table_suffix = ? LIMIT 1");
$branchCurRow->execute([$TS]);
$branchCurRow = $branchCurRow->fetch(PDO::FETCH_ASSOC)
    ?: ['id' => 1, 'code' => 'USD', 'symbol' => '$', 'exchange_rate' => 1.0, 'invoice_prefix' => null, 'default_purchase_tax_pct' => 0];
$branchCur = ['code' => $branchCurRow['code'], 'symbol' => $branchCurRow['symbol']];
$branchPrefix = trim((string) ($branchCurRow['invoice_prefix'] ?? '')) ?: strtoupper($TS);
$defaultTaxPct = (float) ($branchCurRow['default_purchase_tax_pct'] ?? 0);

// سعر صرف عملة الفرع نسبة للمرجع العالمي (عادة USD is_base=1)
$branchBaseRateVsAnchor = (float) $branchCurRow['exchange_rate'] ?: 1.0;

$currencies = $pdo->query("SELECT * FROM currencies WHERE status='active'
    ORDER BY is_base DESC, code")->fetchAll();

// لكل عملة: نحسب سعر الصرف الفعلي نسبة لعملة الفرع (مو نسبة للمرجع العالمي)
// المعادلة: 1 عملة_الفرع = (rate_العملة_الحالية / rate_عملة_الفرع) وحدة من هذه العملة
$currencyRateById = [];
$currencyCodeById = [];
$currencyIdByCode = []; // ⚠ جديد — لازم لتحويل رمز العملة القادم من الواجهة (مثلاً "USD")
// إلى invoice_currency_id عند الحفظ، بدون أي تعديل على الواجهة نفسها
foreach ($currencies as &$cu) {
    $cu['rate_vs_branch'] = $branchBaseRateVsAnchor > 0
        ? ((float) $cu['exchange_rate']) / $branchBaseRateVsAnchor
        : 1.0;
    $currencyRateById[(int) $cu['id']] = $cu['rate_vs_branch'];
    $currencyCodeById[(int) $cu['id']] = $cu['code'];
    $currencyIdByCode[$cu['code']] = (int) $cu['id'];
}
unset($cu);

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        // ── بحث بالباركود أو الاسم ──
        if ($act === 'search_product') {
            $q = trim($_POST['q'] ?? '');
            if (!$q)
                throw new Exception('أدخل باركود أو اسم منتج');

            // بحث بالباركود أولاً
            $st = $pdo->prepare("
                SELECT v.id AS variant_id, v.barcode, v.color_id,
                    p.id AS product_id, p.name AS product_name, p.model_number,
                    s.size, s.selling_price, s.cost_price, s.age_type, s.packet_qty,
                    s.base_currency_id AS price_base_currency_id,
                    c.name AS color_name, c.hex_code AS color_hex
                FROM `{$TV}` v
                JOIN `{$TPROD}` p ON p.id = v.product_id
                JOIN `{$TSZ}` s   ON s.id = v.size_id
                LEFT JOIN `{$TCL}` c ON c.id = v.color_id
                WHERE v.barcode = ? AND v.is_active=1 AND p.is_active=1");
            $st->execute([$q]);
            // ⚠ الباركود ممكن يكون مشترك بين كل مقاسات نفس الكروب (نفس
            // اللون)، مو خاص بمقاس وحيد — كان LIMIT 1 يقتل هالسلوك ويرجّع
            // أول مقاس بس. هلق منرجع كل المطابقات، والواجهة بتدمجهم بنفس
            // منطق دمج الكروب المعتمد أصلاً (mergeVariant).
            $foundAll = $st->fetchAll();
            $found = $foundAll[0] ?? null;

            // ⚠ تصحيح مهم: cost_price/selling_price المخزّنة بـproduct_sizes
            // هي بالفعل محوّلة ومخزّنة بعملة الفرع الأساسية وقت التسجيل
            // (base_currency_id) — وليست بعملة currency_id كما افترضنا
            // بإصلاح سابق (currency_id/exchange_rate هما فقط سجل تاريخي
            // لكيفية إدخال السعر أصلاً، وليسا عملة القيمة المخزّنة فعلياً).
            // لذلك التحويل المطلوب خطوة واحدة بس: من عملة الفرع لعملة
            // الفاتورة المختارة — بدون أي قسمة إضافية.
            if ($found) {
                foreach ($foundAll as &$fr) {
                    $fr['price_base_currency_code'] = $currencyCodeById[(int) ($fr['price_base_currency_id'] ?? 0)] ?? $branchCur['code'];
                }
                unset($fr);
                echo json_encode(['ok' => true, 'type' => 'barcode', 'data' => $found, 'group' => $foundAll]);
            } else {
                // بحث بالاسم أو الموديل
                $st2 = $pdo->prepare("
                    SELECT v.id AS variant_id, v.barcode, v.color_id,
                        p.id AS product_id, p.name AS product_name, p.model_number,
                        s.size, s.selling_price, s.cost_price, s.age_type, s.packet_qty,
                        s.base_currency_id AS price_base_currency_id,
                        c.name AS color_name, c.hex_code AS color_hex
                    FROM `{$TV}` v
                    JOIN `{$TPROD}` p ON p.id = v.product_id
                    JOIN `{$TSZ}` s   ON s.id = v.size_id
                    LEFT JOIN `{$TCL}` c ON c.id = v.color_id
                    WHERE (p.name LIKE ? OR p.model_number LIKE ?)
                        AND v.is_active=1 AND p.is_active=1
                    ORDER BY p.name, s.selling_price, s.age_type, s.sort_order
                    LIMIT 200");
                $st2->execute(["%{$q}%", "%{$q}%"]);
                $results = $st2->fetchAll();
                foreach ($results as &$r) {
                    $r['price_base_currency_code'] = $currencyCodeById[(int) ($r['price_base_currency_id'] ?? 0)] ?? $branchCur['code'];
                }
                unset($r);
                echo json_encode(['ok' => true, 'type' => 'search', 'data' => $results]);
            }
        }

        // ── حفظ الفاتورة ──
        elseif ($act === 'save_invoice') {
            // ⚠ حماية دفاعية: هالحقول الأربعة مقفلة بالواجهة (disabled) —
            // بس عنصر disabled ما بينبعث أصلاً بأي POST حقيقي (لو تلاعب
            // حدا وفعّله من devtools، القيمة المُرسَلة ممكن تكون مزوَّرة).
            // نتجاهل أي قيمة قادمة من $_POST لهالحقول تحديداً، ونعتمد
            // القيم الأصلية المحفوظة بـ$existingPur حصراً — بغض النظر شو
            // انبعث فعلياً.
            $supplierId = (int) $existingPur['supplier_id'] ?: null;
            $whId = (int) $existingPur['warehouse_id'];
            $invDate = $existingPur['purchase_date'];
            $dueDate = $existingPur['due_date'] ?? null;

            $currency = $_POST['currency'] ?? 'USD';
            $exRate = max(0.0001, (float) ($_POST['exchange_rate'] ?? 1));
            $settleDiscPct = ($_POST['settlement_discount_pct'] ?? '') !== ''
                ? max(0, min(100, (float) $_POST['settlement_discount_pct'])) : null;
            $payMethod = 'deferred'; // يُحدَّد لاحقاً عند الدفع
            $notes = trim($_POST['notes'] ?? '');
            $discPct = (float) ($_POST['discount_pct'] ?? 0);
            $taxPct = (float) ($_POST['tax_pct'] ?? 0);
            $saveAs = 'draft'; // دائماً مسودة — التأكيد من صفحة المشتريات
            $rows = json_decode($_POST['rows'] ?? '[]', true);

            if (!$supplierId)
                throw new Exception('يجب اختيار المورد');
            if (!$whId)
                throw new Exception('يجب اختيار المستودع');
            if (empty($rows))
                throw new Exception('يجب إضافة منتج واحد على الأقل');

            // ⚠ قرار نهائي: البيع/الشراء بالقطعة — الرقم المكتوب يُعتمد
            // حرفياً بلا أي تعديل. راجع distributeQty() للتوزيع.

            // حساب الإجماليات
            // ⚠ إصلاح: unit_price = سعر القطعة الواحدة فعلاً (تأكيد صريح
            // من المستخدم) — الإجمالي الصحيح = عدد الكروبات × سعر القطعة
            // × عدد المقاسات المدموجة بالسطر (variant_ids). كان الحساب
            // القديم ناقص عامل عدد المقاسات بالكامل، فيطلع أقل من الصحيح
            // بمقدار (عدد المقاسات) ضعف.
            // ⚠ حساب الإجماليات — الجدول صار بعملة الفرع فقط (قرار صريح):
            // عدد المنتجات (عدد الكروبات × عدد القطع بالباكيت) × السعر
            // الصافي بعد الخصم الإفرادي. بدون أي ضرب بعدد المقاسات
            // المدموجة (تصحيح صريح من المستخدم)، وبدون أي علاقة بسعر صرف
            // الفاتورة (الأسعار أصلاً بعملة الفرع).
            $totalAmt = 0;
            foreach ($rows as $r) {
                $packetQty = max(1, (float) ($r['packet_qty'] ?? 1));
                $pieceCount = (float) $r['qty'] * $packetQty;
                $netPrice = isset($r['net_price']) && $r['net_price'] !== ''
                    ? (float) $r['net_price']
                    : (float) $r['default_price'];
                $totalAmt += $pieceCount * $netPrice;
            }
            $discAmt = $totalAmt * $discPct / 100;
            $taxAmt = ($totalAmt - $discAmt) * $taxPct / 100;
            $finalAmt = $totalAmt - $discAmt + $taxAmt;
            // ⚠ قرار صريح: حقل "عملة الفاتورة" وسعر الصرف بالهيدر أصبحا
            // معلوماتيَّين بس (يُخزَّنان للتوثيق/العرض)، ولا يدخلان بأي
            // تحويل حسابي — كل المجاميع أعلاه (gross/afterLineDisc/
            // afterAllDisc/tax/finalAmt) محسوبة أصلاً من أسعار بنود
            // مخزّنة بعملة الفرع فقط (قرار سابق: جدول البنود = عملة
            // الفرع دائماً). لذلك final_amount و final_amount_base_currency
            // نفس الرقم بالضبط — لا قسمة/ضرب بـ exRate هون إطلاقاً.
            $finalBase = $finalAmt;

            // ⚠ العملة القادمة من الواجهة رمز نصي (مثلاً "USD") — الحقل
            // invoice_currency_id الجديد بجدول purchases يحتاج المعرّف
            // الرقمي من جدول currencies، فنحوّله هون بدل ما نلمس الواجهة.
            // ملاحظة: هالحقل (و$exRate تحت) يُخزَّنان الآن كسجل توثيقي بس
            // — راجع التعليق أعلاه.
            $invoiceCurrencyId = $currencyIdByCode[$currency] ?? (int) $branchCurRow['id'];
            $baseCurrencyId = (int) $branchCurRow['id']; // عملة الفرع وقت تسجيل الفاتورة
            $createdBy = (int) $_SESSION['user_id'];

            // ⚠ رقم الفاتورة يضل ثابت دائماً بالتعديل — لا يُقرأ من الواجهة
            // ولا يُعاد توليده، عكس صفحة الإنشاء تماماً.
            $purNo = $existingPur['purchase_number'];

            // ⚠ حماية دفاعية: إعادة التحقق من حالة الفاتورة وقت الحفظ
            // الفعلي (لا الاكتفاء بالفحص وقت تحميل الصفحة GET) — لو
            // انأكدت الفاتورة بتبويب تاني بالفترة بين فتح الصفحة والحفظ،
            // لازم نرفض هون كمان، لا نسمح بالكتابة فوق فاتورة مؤكدة.
            $statusChk = $pdo->prepare("SELECT status FROM `{$TP}` WHERE id=? LIMIT 1");
            $statusChk->execute([$purchaseId]);
            if ($statusChk->fetchColumn() !== 'draft') {
                throw new Exception('لا يمكن تعديل فاتورة بعد تأكيدها — حدّث الصفحة.');
            }

            $pdo->prepare("UPDATE `{$TP}` SET
                    supplier_id=?, purchase_date=?, due_date=?, settlement_discount_pct=?,
                    total_amount=?, tax_amount=?, discount_amount=?, final_amount=?, final_amount_base_currency=?,
                    balance_amount=?, invoice_currency_id=?, base_currency_id=?, warehouse_id=?, exchange_rate=?,
                    notes=?, updated_by=?, updated_at=NOW()
                WHERE id=?")
                ->execute([
                    $supplierId,
                    $invDate,
                    $dueDate,
                    $settleDiscPct,
                    $totalAmt,
                    $taxAmt,
                    $discAmt,
                    $finalAmt,
                    $finalBase,
                    $finalAmt,   // balance = كامل المبلغ (لا دفعات على مسودة أصلاً)
                    $invoiceCurrencyId,
                    $baseCurrencyId,
                    $whId,
                    $exRate,
                    $notes,
                    (int) $_SESSION['user_id'],
                    $purchaseId
                ]);
            $purId = $purchaseId;
            $createdBy = (int) $_SESSION['user_id'];

            // ⚠ إعادة بناء البنود بالكامل: حذف القديمة كلها ثم إدراج
            // الجديدة — أبسط وأضمن من محاولة مطابقة/تحديث سطر-بسطر لجدول
            // متغيّر البنية (إضافة/حذف صفوف بالواجهة). آمن هون تحديداً
            // لأنه مسودة فقط (صفر أثر مخزون/محاسبة بعد) — لا ينطبق على
            // فاتورة مؤكدة (ممنوع أصلاً، الحماية أعلاه).
            $pdo->prepare("DELETE FROM `{$TPI}` WHERE purchase_id=?")->execute([$purId]);

            // حفظ البنود — بدون أي تأثير على المخزون أو الحسابات
            foreach ($rows as $r) {
                $qty = (float) $r['qty']; // = عدد الكروبات
                $packetQty = max(1, (float) ($r['packet_qty'] ?? 1)); // من product_sizes.packet_qty — للقراءة فقط
                $defaultPr = (float) $r['default_price']; // سعر البيع الافتراضي (بعملة الفرع) — من product_sizes.selling_price
                $netPrice = isset($r['net_price']) && $r['net_price'] !== ''
                    ? (float) $r['net_price']
                    : $defaultPr;
                $discValuePerUnit = max(0, $defaultPr - $netPrice); // قيمة الخصم الإفرادي لكل وحدة (مبلغ، لا نسبة)
                $discPctForRecord = $defaultPr > 0 ? round($discValuePerUnit / $defaultPr * 100, 4) : 0; // نسبة محسوبة للتوافق مع عمود discount_percentage الموجود
                $variantIds = $r['variant_ids'] ?? [$r['variant_id'] ?? 0];
                $variantIds = array_values(array_filter(array_map('intval', $variantIds)));

                foreach ($variantIds as $variantId) {
                    if (!$variantId)
                        continue;

                    $vChk = $pdo->prepare("SELECT id FROM `{$TV}` WHERE id=?");
                    $vChk->execute([$variantId]);
                    if (!$vChk->fetchColumn())
                        continue;

                    // ⚠ نفس منطق الحساب المُصلَح بـinvoice_new.php بالضبط —
                    // quantity لكل مقاس بمفرده = qty (عدد الباكيتات)، لا
                    // piece_count الكامل. راجع تعليقات invoice_new.php
                    // للتفصيل الكامل لسبب هالقرار.
                    $itemQty = $qty;
                    $itemLineTot = $itemQty * $netPrice;
                    $itemDiscAmt = $itemQty * $discValuePerUnit;

                    $pdo->prepare("INSERT INTO `{$TPI}`
                        (purchase_id, product_id, variant_id, quantity, unit_price, unit_price_base_currency,
                         total_price, discount_amount, discount_percentage, created_by)
                        VALUES (?,?,?,?,?,?,?,?,?,?)")
                        ->execute([
                            $purId,
                            (int) $r['product_id'],
                            $variantId,
                            $itemQty,
                            // نفس الإصلاح المزدوج: unit_price_base_currency
                            // = netPrice، unit_price = netPrice×exRate.
                            round($netPrice * $exRate, 4),
                            $netPrice,
                            $itemLineTot,
                            $itemDiscAmt,
                            $discPctForRecord,
                            $createdBy
                        ]);
                }
            }

            echo json_encode([
                'ok' => true,
                'id' => $purId,
                'no' => $purNo,
                'msg' => 'تم حفظ التعديلات على الفاتورة'
            ]);
        }

        // ── إضافة مورد جديد ──
        // ⚠ نفس منطق suppliers.php بالضبط — راجع التعليق المطابق بـ
        // invoice_new.php لتفصيل سبب هالإصلاح (فجوة حسابات محاسبية).
        elseif ($act === 'add_supplier') {
            $name = trim($_POST['name'] ?? '');
            $contact = trim($_POST['contact_person'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $tax = trim($_POST['tax_number'] ?? '');
            $type = $_POST['type'] ?? 'wholesaler';
            $supType = $_POST['supplier_type'] ?? 'product';
            $creditLimit = (float) ($_POST['credit_limit'] ?? 0);
            $discount = (float) ($_POST['discount_percentage'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            if (!$name)
                throw new Exception('اسم المورد مطلوب');

            $TIAS = "invoice_account_settings_{$TS}";
            $TAC = "account_charts_{$TS}";
            $baseCurrencyId = (int) $branchCurRow['id'];

            $pdo->beginTransaction();
            try {
                $getParent = function (string $key) use ($pdo, $TIAS, $TAC): ?array {
                    $st = $pdo->prepare("SELECT ac.* FROM `{$TIAS}` i JOIN `{$TAC}` ac ON ac.id=i.account_id WHERE i.setting_key=? LIMIT 1");
                    $st->execute([$key]);
                    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
                };
                $parentPayable = $getParent('supplier_payable');
                $parentAdvance = $getParent('shipping_advance') ?: $getParent('employee_advance');
                if (!$parentPayable || !$parentAdvance)
                    throw new Exception('اضبط حسابي "ذمم الموردين" و"دفعات مقدمة للموردين" أولاً من صفحة إعدادات الربط المحاسبي');

                $cnt = (int) $pdo->query("SELECT COUNT(*) FROM `{$TAC}` WHERE parent_id={$parentPayable['id']}")->fetchColumn();
                $code = $parentPayable['code'] . '.' . str_pad($cnt + 1, 3, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO `{$TAC}` (code,name,parent_id,account_type,currency_id,level,is_locked)
                    VALUES (?,?,?,'liability',?,?,0)")
                    ->execute([$code, "ذمم {$name}", $parentPayable['id'], $baseCurrencyId, substr_count($code, '.') + 1]);
                $accId = (int) $pdo->lastInsertId();

                $cnt2 = (int) $pdo->query("SELECT COUNT(*) FROM `{$TAC}` WHERE parent_id={$parentAdvance['id']}")->fetchColumn();
                $code2 = $parentAdvance['code'] . '.' . str_pad($cnt2 + 1, 3, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO `{$TAC}` (code,name,parent_id,account_type,currency_id,level,is_locked)
                    VALUES (?,?,?,'asset',?,?,0)")
                    ->execute([$code2, "دفعات مقدمة — {$name}", $parentAdvance['id'], $baseCurrencyId, substr_count($code2, '.') + 1]);
                $prepaidId = (int) $pdo->lastInsertId();

                $pdo->prepare("INSERT INTO `{$TSP}`
                    (name,contact_person,phone,email,address,tax_number,type,supplier_type,
                     status,credit_limit,discount_percentage,notes,account_id,prepaid_account_id,created_by)
                    VALUES (?,?,?,?,?,?,?,?,'active',?,?,?,?,?,?)")
                    ->execute([
                        $name,
                        $contact,
                        $phone,
                        $email,
                        $address,
                        $tax,
                        $type,
                        $supType,
                        $creditLimit,
                        $discount,
                        $notes,
                        $accId,
                        $prepaidId,
                        $_SESSION['user_id']
                    ]);
                $supId = (int) $pdo->lastInsertId();
                $pdo->commit();

                echo json_encode([
                    'ok' => true,
                    'id' => $supId,
                    'name' => $name,
                    'phone' => $phone,
                    'discount_percentage' => $discount,
                    'msg' => 'تمت إضافة المورد وإنشاء حسابي الذمة والدفعة المقدمة ✅'
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        } else
            throw new Exception('إجراء غير معروف');

    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── بيانات الصفحة ──────────────────────────────────────────────
$suppliers = $pdo->query("SELECT id,name,phone,contact_person,discount_percentage FROM `{$TSP}`
    WHERE status='active' AND supplier_type IN ('product','both') ORDER BY name")->fetchAll();
$warehouses = $pdo->query("SELECT * FROM `{$TW}` WHERE is_active=1 ORDER BY id")->fetchAll();
// ملاحظة: $currencies و$branchCur و$branchBaseRateVsAnchor و$currencyRateById
// محسوبة مسبقاً بأعلى الملف (قبل معالج AJAX) — راجع التعليق هناك.

// ── تحميل بنود الفاتورة الموجودة، وإعادة تجميعها لنفس شكل كائن
// "line" المستخدم بالإنشاء (product+color+سعر افتراضي = خط واحد،
// بمقاساته المدموجة) ──────────────────────────────────────────
$existingItemsRaw = $pdo->prepare("SELECT pi.*,
        pr.name AS product_name, pr.model_number,
        psz.size AS size, psz.age_type, psz.packet_qty,
        pcl.id AS color_id, pcl.name AS color_name, pcl.hex_code AS color_hex
    FROM `{$TPI}` pi
    LEFT JOIN `{$TPROD}` pr ON pr.id = pi.product_id
    LEFT JOIN `{$TV}` pv ON pv.id = pi.variant_id
    LEFT JOIN `{$TSZ}` psz ON psz.id = pv.size_id
    LEFT JOIN `{$TCL}` pcl ON pcl.id = pv.color_id
    WHERE pi.purchase_id = ?
    ORDER BY pi.id");
$existingItemsRaw->execute([$purchaseId]);
$existingItemsRaw = $existingItemsRaw->fetchAll(PDO::FETCH_ASSOC);

$existingGrpMap = [];
$existingLineSeq = 0;
foreach ($existingItemsRaw as $it) {
    $netPrice = (float) $it['unit_price_base_currency'];
    $discPctRow = (float) $it['discount_percentage'];
    // نفس منطق إعادة بناء السعر الافتراضي المستخدم بمودال التفاصيل —
    // موثوق أكتر من أي عمود آخر لأنه مبني على القيم الفعلية المحفوظة.
    $defaultPr = $discPctRow > 0 ? round($netPrice / (1 - $discPctRow / 100), 4) : $netPrice;
    $grpKey = $it['product_id'] . '_' . ($it['color_id'] ?: 0) . '_' . number_format($defaultPr, 4, '.', '');
    if (!isset($existingGrpMap[$grpKey])) {
        $existingLineSeq++;
        $existingGrpMap[$grpKey] = [
            'grp_key' => 'edit_' . $existingLineSeq . '_' . uniqid(),
            'row_id' => 'row_edit_' . $existingLineSeq . '_' . uniqid(),
            'product_id' => (int) $it['product_id'],
            'product_name' => $it['product_name'] ?? '—',
            'model_number' => $it['model_number'] ?? '',
            'sizes' => [],
            'age_type' => $it['age_type'] ?? '',
            'color_name' => $it['color_name'] ?? '',
            'color_hex' => $it['color_hex'] ?? '',
            // ⚠ السعر الافتراضي هون تاريخي (وقت الشراء الأصلي)، لا يُعاد
            // جلبه حياً من product_sizes.selling_price الحالي — لأنه لو
            // تغيّر سعر المنتج منذ الشراء، تعديل فاتورة قديمة يجب أن
            // يعكس القيم الأصلية يلي انحفظت فعلاً، لا سعر اليوم. (فرق
            // متعمَّد عن سلوك invoice_new.php، حيث السعر دايماً حي لأنه
            // إنشاء جديد من الأساس).
            'default_price' => $defaultPr,
            'packet_qty' => (int) ($it['packet_qty'] ?? 1),
            'qty' => (float) $it['quantity'], // نفس القيمة على كل مقاسات الخط (موحَّدة)
            'net_price' => $netPrice,
            'discount_value' => round($defaultPr - $netPrice, 4),
            'variants' => [],
        ];
    }
    if ($it['size'] && !in_array($it['size'], $existingGrpMap[$grpKey]['sizes']))
        $existingGrpMap[$grpKey]['sizes'][] = $it['size'];
    $existingGrpMap[$grpKey]['variants'][] = ['variant_id' => (int) $it['variant_id']];
}
$existingLinesForJs = array_values($existingGrpMap);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>تعديل فاتورة شراء — <?= htmlspecialchars($existingPur['purchase_number']) ?></title>
    <link rel="icon" href="<?= BASE_PATH ?>/assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/assets/css/layout.css" rel="stylesheet">
    <style>
        /* layout */
        .page-grid {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 16px;
            align-items: start
        }

        /* ⚠ مهم: عناصر الـgrid عندها min-width:auto افتراضياً — يعني
           عمود المحتوى الرئيسي (1fr) ما بينضغط تحت العرض الطبيعي لأوسع
           محتوى بداخله (هون: جدول بنود الفاتورة بـ١٣ عمود). بدون هالسطر،
           الجدول العريض بيدفع عرض .page-grid كامل أوسع من الشاشة، وهاد
           بينعكس على عرض الصفحة كلها (والشريط الجانبي المجاور إذا كان
           بدون عرض ثابت بملفه الخاص). الحل المحلي هون: نجبر العمود الأول
           ينضغط لعرضه المتاح، وoverflow الجدول ينحصر جوا .table-responsive
           (سكرول أفقي محلي بس، مو دفع للصفحة كلها).
        */
        .page-grid>div:first-child {
            min-width: 0
        }

        @media(max-width:900px) {
            .page-grid {
                grid-template-columns: 1fr
            }
        }

        /* cards */
        .card-sec {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            margin-bottom: 12px
        }

        .card-sec-hdr {
            padding: 10px 16px;
            border-bottom: 1px solid #f1f5f9;
            font-size: .83rem;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px
        }

        .card-sec-body {
            padding: 14px 16px
        }

        /* fields */
        .field-lbl {
            font-size: .75rem;
            font-weight: 700;
            color: #475569;
            margin-bottom: 3px;
            display: block
        }

        .field-hint {
            font-size: .7rem;
            color: #94a3b8;
            margin-top: 2px
        }

        .req {
            color: #dc2626
        }

        .sec-title {
            font-size: .72rem;
            font-weight: 700;
            color: #64748b;
            border-bottom: 1.5px solid #f1f5f9;
            padding-bottom: 6px;
            margin-bottom: 2px
        }

        /* scanner */
        .scan-wrap {
            display: flex;
            gap: 8px;
            margin-bottom: 10px
        }

        .scan-input {
            flex: 1;
            border: 2px solid #1e3a8a;
            border-radius: 10px;
            padding: 8px 12px;
            font-size: .85rem;
            font-family: inherit
        }

        .scan-input:focus {
            outline: none;
            border-color: #0891b2;
            box-shadow: 0 0 0 3px rgba(8, 145, 178, .1)
        }

        .scan-btn {
            border-radius: 10px;
            border: none;
            background: #1e3a8a;
            color: #fff;
            padding: 8px 14px;
            cursor: pointer;
            font-size: .85rem;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap
        }

        /* search results */
        .search-res {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            max-height: 260px;
            overflow-y: auto;
            margin-top: 6px
        }

        .search-item {
            padding: 8px 12px;
            cursor: pointer;
            border-bottom: 1px solid #f8fafc;
            font-size: .8rem;
            transition: background .1s
        }

        .search-item:last-child {
            border-bottom: none
        }

        .search-item:hover {
            background: #eff6ff
        }

        .search-item .si-name {
            font-weight: 600;
            color: #1e293b
        }

        .search-item .si-meta {
            color: #64748b;
            font-size: .73rem;
            margin-top: 1px
        }

        /* lines table — تصميم محدَّث */
        .lines-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            table-layout: fixed;
            font-size: .78rem
        }

        .lines-table thead th {
            background: #f8fafc;
            padding: 10px 7px;
            font-weight: 700;
            color: #475569;
            font-size: .66rem;
            letter-spacing: .01em;
            border-bottom: 2px solid #1e3a8a;
            white-space: normal;
            line-height: 1.3;
            vertical-align: bottom
        }

        .lines-table tbody tr {
            border-right: 3px solid var(--grp-c, transparent);
            transition: background .12s
        }

        .lines-table tbody tr:hover {
            background: #f8faff
        }

        .lines-table td {
            padding: 9px 7px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            font-weight: 700;
            font-size: .78rem;
            font-variant-numeric: tabular-nums
        }

        .lines-table tr:last-child td {
            border-bottom: none
        }

        .row-num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 19px;
            height: 19px;
            border-radius: 50%;
            background: #f1f5f9;
            color: #64748b;
            font-size: .66rem;
            font-weight: 800
        }

        .lines-table input {
            width: 100%;
            padding: 6px 7px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            font-size: .78rem;
            font-weight: 700;
            color: #1e293b;
            font-variant-numeric: tabular-nums;
            background: #fff;
            transition: border-color .15s, box-shadow .15s
        }

        .lines-table input:focus {
            outline: none;
            border-color: #1e3a8a;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, .1)
        }

        /* حقول مقفلة (من بيانات المنتج أو محسوبة) — نسيج خفيف "خلية محمية"
           بدل الرمادي الفاطس، حتى تُقرأ بصرياً كحقل نظام لا يُعدَّل يدوياً */
        .lines-table input[readonly] {
            background:
                repeating-linear-gradient(135deg, rgba(100, 116, 139, .06) 0 6px, transparent 6px 12px),
                #f8fafc;
            color: #64748b;
            border-color: #eef2f6;
            cursor: default
        }

        .lines-table input.calc {
            background: #f0fdf4;
            color: #16a34a;
            border-color: #bbf7d0;
            font-weight: 800;
            text-align: center
        }

        .del-btn {
            width: 26px;
            height: 26px;
            border-radius: 8px;
            border: 1.5px solid transparent;
            background: #fef2f2;
            color: #dc2626;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .75rem;
            transition: all .15s
        }

        .del-btn:hover {
            background: #dc2626;
            color: #fff
        }

        /* color dot */
        .clr-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 1px solid rgba(0, 0, 0, .12);
            display: inline-block;
            flex-shrink: 0
        }

        /* مبالغ الفاتورة — سلسلة حسابية متصلة */
        .calc-section-lbl {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: .64rem;
            font-weight: 800;
            color: #94a3b8;
            letter-spacing: .03em;
            padding: 4px 0 8px
        }

        .calc-section-lbl::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #f1f5f9
        }

        /* التوثيق فقط — عملة/سعر صرف، بلا أي تأثير حسابي */
        .doc-pill-box {
            background: #f8fafc;
            border: 1.5px dashed #cbd5e1;
            border-radius: 10px;
            padding: 9px 11px;
            margin-bottom: 14px
        }

        .doc-pill-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: .78rem;
            padding: 3px 0
        }

        /* السلسلة الحسابية: gross → صافي بعد الخصم الإفرادي، متصلة بخط ونقاط */
        .calc-chain {
            position: relative;
            padding-right: 16px;
            margin-bottom: 6px
        }

        .calc-chain::before {
            content: '';
            position: absolute;
            right: 4px;
            top: 9px;
            bottom: 9px;
            width: 2px;
            background: #eef2f6
        }

        .calc-step {
            position: relative;
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            font-size: .8rem;
            padding: 6px 0
        }

        .calc-step::before {
            content: '';
            position: absolute;
            right: -16px;
            top: 50%;
            transform: translateY(-50%);
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #94a3b8;
            box-shadow: 0 0 0 3px #fff
        }

        .calc-step.sub::before {
            background: #dc2626
        }

        .calc-step.add::before {
            background: #16a34a
        }

        .calc-step.subtotal {
            font-weight: 800;
            color: #1e293b
        }

        .calc-step.subtotal::before {
            width: 10px;
            height: 10px;
            background: #1e3a8a
        }

        .calc-label {
            color: #64748b
        }

        .calc-val {
            font-variant-numeric: tabular-nums;
            font-weight: 700;
            white-space: nowrap
        }

        .calc-val.neg {
            color: #dc2626
        }

        .calc-val.pos {
            color: #16a34a
        }

        /* صفوف النِسَب (خصم عام / تعجيل الدفع / ضريبة) */
        .rate-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 7px 0;
            font-size: .8rem
        }

        .rate-input-pill {
            display: flex;
            align-items: center;
            border: 1.5px solid #e2e8f0;
            border-radius: 20px;
            overflow: hidden;
            background: #fff;
            transition: border-color .15s
        }

        .rate-input-pill:focus-within {
            border-color: #1e3a8a
        }

        .rate-input-pill input {
            border: none;
            width: 42px;
            padding: 4px 2px 4px 4px;
            font-size: .76rem;
            font-weight: 700;
            text-align: center;
            font-variant-numeric: tabular-nums
        }

        .rate-input-pill input:focus {
            outline: none
        }

        .rate-input-pill .pct {
            background: #f1f5f9;
            color: #64748b;
            padding: 5px 9px;
            font-size: .66rem;
            font-weight: 800
        }

        /* الإجمالي النهائي — بانر بارز */
        .grand-total-banner {
            margin-top: 12px;
            background: linear-gradient(135deg, #1e3a8a, #1e40af);
            border-radius: 12px;
            padding: 13px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #fff;
            box-shadow: 0 8px 20px -8px rgba(30, 58, 138, .5)
        }

        .grand-total-banner .gtb-lbl {
            font-size: .68rem;
            font-weight: 700;
            opacity: .78
        }

        .grand-total-banner .gtb-val {
            font-size: 1.3rem;
            font-weight: 800;
            font-variant-numeric: tabular-nums
        }

        .n {
            font-variant-numeric: tabular-nums
        }

        /* select modal */
        .sel-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .82rem
        }

        .sel-table th {
            background: #f8fafc;
            padding: 8px 12px;
            font-weight: 600;
            color: #64748b;
            font-size: .72rem;
            border-bottom: 1px solid #f1f5f9;
            white-space: nowrap
        }

        .sel-table td {
            padding: 7px 10px;
            border-bottom: 1px solid #f8fafc;
            vertical-align: middle
        }

        .sel-table tr:hover td {
            background: #f8faff
        }

        .sel-table tr.checked td {
            background: #eff6ff
        }

        .sel-table input[type=checkbox] {
            width: 16px;
            height: 16px;
            cursor: pointer;
            accent-color: #1e3a8a
        }

        .grp-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 20px;
            font-size: .68rem;
            padding: 2px 8px;
            font-weight: 600;
            border: 1px solid
        }

        /* scanner pulse */
        @keyframes pulse {

            0%,
            100% {
                opacity: 1
            }

            50% {
                opacity: .5
            }
        }

        .scanning {
            animation: pulse .8s infinite;
            color: #16a34a
        }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>

    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-pencil-square me-1 text-primary"></i>تعديل فاتورة شراء</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
            <a href="index.php" style="color:#64748b;text-decoration:none">فواتير الشراء</a>
            <i class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
            <span class="text-primary"><?= htmlspecialchars($existingPur['purchase_number']) ?></span>
        </nav>
    </header>

    <main class="main-content">
        <div class="content-body">

            <div class="page-grid">
                <!-- ── العمود الرئيسي ── -->
                <div>

                    <!-- بيانات الفاتورة -->
                    <div class="card-sec">
                        <div class="card-sec-hdr">
                            <i class="bi bi-receipt text-primary"></i>بيانات الفاتورة
                        </div>
                        <div class="card-sec-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="field-lbl">المورد <i class="bi bi-lock-fill"
                                            style="font-size:.68rem;color:#94a3b8" title="مقفل — لا يتغيّر بالتعديل"></i></label>
                                    <div class="d-flex gap-1">
                                        <select id="iSupplier" class="form-select form-select-sm" style="flex:1;background:#f8fafc"
                                            disabled>
                                            <option value="">— بدون مورد —</option>
                                            <?php foreach ($suppliers as $sp): ?>
                                                        <option value="<?= $sp['id'] ?>"
                                                            data-phone="<?= htmlspecialchars($sp['phone'] ?? '') ?>"
                                                            data-discount="<?= (float) ($sp['discount_percentage'] ?? 0) ?>"
                                                            <?= (int) $sp['id'] === (int) $existingPur['supplier_id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($sp['name']) ?>
                                                        </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn btn-sm" disabled
                                            style="border-radius:7px;border:1px solid #e2e8f0;color:#cbd5e1;padding:4px 8px"
                                            title="غير متاح — المورد مقفل بالتعديل"><i class="bi bi-plus"></i></button>
                                    </div>
                                    <div class="field-hint">مقفل — لا يتغيّر بعد إنشاء الفاتورة</div>
                                    <div id="supplierPhone"
                                        style="display:none;font-size:.7rem;color:#64748b;margin-top:3px">
                                        <i class="bi bi-telephone me-1"></i><span id="spPhoneTxt"></span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="field-lbl">المستودع <i class="bi bi-lock-fill"
                                            style="font-size:.68rem;color:#94a3b8" title="مقفل — لا يتغيّر بالتعديل"></i></label>
                                    <select id="iWarehouse" class="form-select form-select-sm" style="background:#f8fafc" disabled>
                                        <option value="">— اختر المستودع —</option>
                                        <?php foreach ($warehouses as $wh): ?>
                                                    <option value="<?= $wh['id'] ?>"
                                                        <?= (int) $wh['id'] === (int) $existingPur['warehouse_id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($wh['name']) ?>
                                                    </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="field-hint">مقفل — لا يتغيّر بعد إنشاء الفاتورة</div>
                                </div>
                                <div class="col-md-3">
                                    <label class="field-lbl">تاريخ الفاتورة <i class="bi bi-lock-fill"
                                            style="font-size:.68rem;color:#94a3b8" title="مقفل — لا يتغيّر بالتعديل"></i></label>
                                    <input type="date" id="iDate" class="form-control form-control-sm" style="background:#f8fafc"
                                        value="<?= htmlspecialchars($existingPur['purchase_date']) ?>" disabled>
                                </div>
                                <div class="col-md-3">
                                    <label class="field-lbl">تاريخ الاستحقاق <i class="bi bi-lock-fill"
                                            style="font-size:.68rem;color:#94a3b8" title="مقفل — لا يتغيّر بالتعديل"></i></label>
                                    <input type="date" id="iDueDate" class="form-control form-control-sm" style="background:#f8fafc"
                                        value="<?= htmlspecialchars($existingPur['due_date'] ?? $existingPur['purchase_date']) ?>"
                                        disabled>
                                </div>
                                <div class="col-md-3">
                                    <label class="field-lbl">رقم الفاتورة الداخلي</label>
                                    <input type="text" id="iInvNo" class="form-control form-control-sm"
                                        value="<?= htmlspecialchars($existingPur['purchase_number']) ?>" dir="ltr"
                                        readonly
                                        style="background:#f8fafc;font-weight:700;color:#1e3a8a;letter-spacing:1px">
                                    <div class="field-hint">ثابت — لا يتغيّر بالتعديل</div>
                                </div>
                                <div class="col-12">
                                    <label class="field-lbl">ملاحظات</label>
                                    <input type="text" id="iNotes" class="form-control form-control-sm"
                                        value="<?= htmlspecialchars($existingPur['notes'] ?? '') ?>"
                                        placeholder="اختياري">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- إضافة المنتجات -->
                    <div class="card-sec">
                        <div class="card-sec-hdr">
                            <i class="bi bi-barcode-scan text-primary"></i>إضافة المنتجات
                            <span style="margin-right:auto;font-size:.72rem;color:#64748b;font-weight:400">
                                امسح الباركود أو ابحث بالاسم / الموديل
                            </span>
                            <button class="btn btn-sm" onclick="refreshProducts()" id="btnRefresh"
                                style="border-radius:8px;border:1px solid #0891b2;color:#0891b2;font-size:.75rem"
                                title="تحديث قائمة المنتجات">
                                <i class="bi bi-arrow-clockwise me-1"></i>تحديث
                            </button>
                            <a href="../inventory/product_add.php" target="_blank" class="btn btn-sm"
                                style="border-radius:8px;border:1px solid #16a34a;color:#16a34a;font-size:.75rem;text-decoration:none"
                                title="إضافة منتج جديد">
                                <i class="bi bi-plus-circle me-1"></i>منتج جديد
                            </a>
                            <button class="btn btn-sm" onclick="toggleCamera()"
                                style="border-radius:8px;border:1px solid #1e3a8a;color:#1e3a8a;font-size:.75rem"
                                id="btnCamera">
                                <i class="bi bi-camera me-1"></i>كاميرا
                            </button>
                        </div>
                        <div class="card-sec-body">
                            <!-- كاميرا الباركود -->
                            <div id="cameraWrap" style="display:none;margin-bottom:12px">
                                <div
                                    style="background:#000;border-radius:10px;overflow:hidden;position:relative;max-width:400px">
                                    <video id="camVideo" style="width:100%;display:block" autoplay playsinline></video>
                                    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);
                    width:60%;height:40%;border:2px solid #22c55e;border-radius:8px;pointer-events:none"></div>
                                    <canvas id="camCanvas" style="display:none"></canvas>
                                </div>
                                <div id="camStatus" style="font-size:.75rem;color:#16a34a;margin-top:6px">
                                    <i class="bi bi-circle-fill scanning me-1"></i>جارٍ المسح...
                                </div>
                            </div>

                            <!-- حقل البحث/الباركود -->
                            <div class="scan-wrap">
                                <input type="text" id="scanInput" class="scan-input" dir="ltr"
                                    placeholder="امسح الباركود أو اكتب اسم المنتج / الموديل..." autocomplete="off">
                                <button class="scan-btn" onclick="doSearch()">
                                    <i class="bi bi-search"></i>بحث
                                </button>
                            </div>
                            <div id="searchResults" style="display:none"></div>
                        </div>
                    </div>

                    <!-- جدول البنود -->
                    <div class="card-sec">
                        <div class="card-sec-hdr">
                            <i class="bi bi-list-ul text-primary"></i>بنود الفاتورة
                            <span id="linesCount"
                                style="margin-right:4px;font-size:.72rem;color:#64748b;font-weight:400"></span>
                        </div>
                        <div class="card-sec-body" style="padding:0">
                            <div class="table-responsive">
                                <table class="lines-table">
                                    <thead>
                                        <tr>
                                            <th style="width:24px">#</th>
                                            <th>بيان المنتج</th>
                                            <th>الموديل</th>
                                            <th>الكروب / القياس</th>
                                            <th class="text-center">عدد القطع بالباكيت</th>
                                            <th>اللون</th>
                                            <th class="text-center">عدد الكروبات</th>
                                            <th class="text-center">عدد المنتجات</th>
                                            <th class="text-center">سعر البيع الافتراضي</th>
                                            <th class="text-center">قيمة الخصم الإفرادي</th>
                                            <th class="text-center">سعر البيع بعد الخصم</th>
                                            <th class="text-center">الإجمالي</th>
                                            <th style="width:22px"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="linesBody">
                                        <tr id="emptyRow">
                                            <td colspan="13" class="text-center text-muted py-4"
                                                style="font-size:.8rem">
                                                <i class="bi bi-barcode d-block mb-2"
                                                    style="font-size:1.5rem;opacity:.3"></i>
                                                امسح باركود أو ابحث عن منتج لإضافته
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div><!-- end main col -->

                <!-- ── الشريط الجانبي الأيمن ── -->
                <div>

                    <!-- مبالغ الفاتورة -->
                    <div class="card-sec" style="position:sticky;top:80px">
                        <div class="card-sec-hdr">
                            <i class="bi bi-calculator text-primary"></i>مبالغ الفاتورة
                            <span style="margin-right:auto;font-size:.72rem;color:#64748b;font-weight:400">
                                <span id="sumLines">0</span> بند
                            </span>
                        </div>
                        <div class="card-sec-body">

                            <!-- توثيقي فقط — بلا تأثير حسابي -->
                            <div class="doc-pill-box">
                                <div class="doc-pill-row">
                                    <span class="calc-label">عملة الفاتورة</span>
                                    <select id="iCurrency" class="form-select form-select-sm" style="width:auto"
                                        onchange="onCurrencyChange()">
                                        <?php foreach ($currencies as $cu): ?>
                                                    <option value="<?= $cu['code'] ?>" data-rate="<?= $cu['rate_vs_branch'] ?>"
                                                        data-sym="<?= htmlspecialchars($cu['symbol']) ?>"
                                                        <?= (int) $cu['id'] === (int) $existingPur['invoice_currency_id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($cu['code']) ?>
                                                    </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="doc-pill-row">
                                    <span class="calc-label">سعر الصرف <small>(مقابل
                                            <?= htmlspecialchars($branchCur['symbol']) ?>)</small></span>
                                    <input type="number" id="iExRate" min="0.0001" step="0.0001"
                                        value="<?= (float) $existingPur['exchange_rate'] ?: 1 ?>" dir="ltr"
                                        onchange="onExRateChange()"
                                        style="width:78px;padding:4px 6px;font-size:.75rem;font-weight:700;border:1.5px solid #e2e8f0;border-radius:8px;text-align:center;background:#fff">
                                </div>
                                <div id="exRateHint" class="field-hint text-end" style="margin-top:2px"></div>
                                <div class="field-hint text-end" style="margin-top:2px;color:#94a3b8">
                                    <i class="bi bi-info-circle me-1"></i>للتوثيق فقط — كل المبالغ بالأسفل بعملة الفرع دائماً</div>
                            </div>

                            <!-- السلسلة الحسابية -->
                            <div class="calc-section-lbl">التسلسل الحسابي</div>
                            <div class="calc-chain">
                                <div class="calc-step">
                                    <span class="calc-label">المبلغ الصافي <small>(بدون الخصم الإفرادي)</small></span>
                                    <span id="sumGross" class="calc-val">0.00</span>
                                </div>
                                <div class="calc-step sub">
                                    <span class="calc-label">قيمة الخصم الإفرادي</span>
                                    <span id="sumLineDisc" class="calc-val neg">-0.00</span>
                                </div>
                                <div class="calc-step subtotal">
                                    <span class="calc-label">المبلغ الصافي بعد الخصم الإفرادي</span>
                                    <span id="sumAfterLineDisc" class="calc-val">0.00</span>
                                </div>

                                <div class="rate-row">
                                    <span class="calc-label">نسبة الخصم العام للمورد</span>
                                    <div class="rate-input-pill">
                                        <input type="number" id="discPct" min="0" max="100"
                                            value="<?= $existingDiscPct ?>" step="0.01" dir="ltr"
                                            oninput="calcTotals()">
                                        <span class="pct">%</span>
                                    </div>
                                </div>

                                <div class="rate-row">
                                    <span class="calc-label" style="display:flex;align-items:center;gap:5px">
                                        <input type="checkbox" class="form-check-input" id="iHasSettleDisc"
                                            style="margin:0"
                                            <?= $existingPur['settlement_discount_pct'] !== null ? 'checked' : '' ?>
                                            onchange="document.getElementById('iSettleDiscWrap').style.display=this.checked?'':'none';calcTotals()">
                                        <label for="iHasSettleDisc" style="cursor:pointer;margin:0">خصم تعجيل الدفع؟</label>
                                    </span>
                                    <span id="iSettleDiscWrap"
                                        style="<?= $existingPur['settlement_discount_pct'] !== null ? '' : 'display:none' ?>">
                                        <div class="rate-input-pill">
                                            <input type="number" id="iSettleDiscPct" min="0" max="100"
                                                value="<?= (float) ($existingPur['settlement_discount_pct'] ?? 0) ?>"
                                                step="0.5" dir="ltr" oninput="calcTotals()">
                                            <span class="pct">%</span>
                                        </div>
                                    </span>
                                </div>
                                <div class="calc-step" style="opacity:.65">
                                    <span class="calc-label">قيمة خصم تعجيل الدفع <small>(معلوماتي)</small></span>
                                    <span id="sumSettle" class="calc-val" style="color:#94a3b8">0.00</span>
                                </div>

                                <div class="calc-step subtotal">
                                    <span class="calc-label">المبلغ الصافي بعد الخصوم</span>
                                    <span id="sumAfterAllDisc" class="calc-val">0.00</span>
                                </div>

                                <div class="rate-row">
                                    <span class="calc-label">نسبة ضريبة المشتريات العامة</span>
                                    <div class="rate-input-pill">
                                        <input type="number" id="taxPct" min="0" max="100"
                                            value="<?= $existingTaxPct ?>" step="0.01" dir="ltr" oninput="calcTotals()">
                                        <span class="pct">%</span>
                                    </div>
                                </div>
                                <div class="calc-step add">
                                    <span class="calc-label">قيمة ضريبة المشتريات</span>
                                    <span id="sumTax" class="calc-val pos">+0.00</span>
                                </div>
                            </div>

                            <!-- الإجمالي النهائي -->
                            <div class="grand-total-banner">
                                <span class="gtb-lbl">المبلغ الإجمالي<br><span
                                        style="opacity:.75;font-weight:500">(بعملة الفرع)</span></span>
                                <span id="sumTotal" class="gtb-val">0.00</span>
                            </div>

                            <!-- أزرار الحفظ -->
                            <div class="d-grid gap-2 mt-3">
                                <button class="btn btn-sm fw-600"
                                    style="border-radius:9px;background:#1e3a8a;color:#fff;padding:8px"
                                    onclick="saveInvoice('draft')">
                                    <i class="bi bi-floppy me-1"></i>حفظ التعديلات
                                </button>
                                <a href="index.php" class="btn btn-sm"
                                    style="border-radius:9px;border:1px solid #e2e8f0;color:#64748b;padding:8px;text-decoration:none;text-align:center">
                                    <i class="bi bi-x-lg me-1"></i>إلغاء
                                </a>
                            </div>
                        </div>
                    </div>

                </div><!-- end side col -->
            </div><!-- end page-grid -->

        </div>
    </main>

    <!-- مودال اختيار المنتجات المتعدد -->
    <div class="modal fade" id="selectModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#0c447c,#1e3a8a);border-radius:16px 16px 0 0">
                    <div>
                        <h6 class="modal-title text-white fw-700 mb-0" id="selModalTitle">اختيار المنتجات</h6>
                        <div style="font-size:.75rem;color:rgba(255,255,255,.7);margin-top:2px">
                            حدد المنتجات المطلوبة + الكمية + السعر ثم اضغط إضافة
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span id="selCount" style="font-size:.78rem;color:rgba(255,255,255,.8);font-weight:600"></span>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                </div>
                <div class="modal-body p-0" id="selModalBody">
                    <div class="text-center py-4"><span class="spinner-border text-primary"></span></div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button class="btn btn-sm btn-light" style="border-radius:8px"
                        data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-sm fw-600"
                        style="border-radius:8px;background:#1e3a8a;color:#fff;min-width:140px"
                        onclick="confirmSelection()">
                        <i class="bi bi-plus-circle me-1"></i>إضافة المحدد للفاتورة
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- مودال مورد جديد -->
    <div class="modal fade" id="supplierModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#0c447c,#1e3a8a);border-radius:16px 16px 0 0">
                    <h6 class="modal-title text-white fw-700 mb-0"><i class="bi bi-truck me-2"></i>إضافة مورد جديد</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pt-3">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="sec-title">المعلومات الأساسية</div>
                        </div>
                        <div class="col-md-6">
                            <label class="field-lbl">اسم المورد <span class="req">*</span></label>
                            <input type="text" id="spName" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="field-lbl">جهة الاتصال</label>
                            <input type="text" id="spContact" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">الهاتف</label>
                            <input type="text" id="spPhone" class="form-control form-control-sm" dir="ltr">
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">بريد إلكتروني</label>
                            <input type="email" id="spEmail" class="form-control form-control-sm" dir="ltr">
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">الرقم الضريبي</label>
                            <input type="text" id="spTax" class="form-control form-control-sm" dir="ltr">
                        </div>
                        <div class="col-12">
                            <label class="field-lbl">العنوان</label>
                            <input type="text" id="spAddress" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">نوع المورد</label>
                            <select id="spType" class="form-select form-select-sm">
                                <option value="manufacturer">مصنّع</option>
                                <option value="distributor">موزّع</option>
                                <option value="wholesaler" selected>جملة</option>
                                <option value="retailer">مفرد</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">يورد</label>
                            <select id="spSupType" class="form-select form-select-sm">
                                <option value="product">منتجات</option>
                                <option value="consumable">مستهلكات</option>
                                <option value="both">كليهما</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="field-lbl">الحد الائتماني</label>
                            <input type="number" id="spCredit" class="form-control form-control-sm" value="0"
                                dir="ltr">
                        </div>
                        <div class="col-md-2">
                            <label class="field-lbl">خصم%</label>
                            <input type="number" id="spDiscount" class="form-control form-control-sm" value="0"
                                min="0" max="100" dir="ltr">
                        </div>
                        <div class="col-12">
                            <div
                                style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;padding:10px 14px">
                                <div class="fw-600" style="font-size:.8rem;color:#16a34a">
                                    <i class="bi bi-magic me-1"></i>حسابات المورد تُنشأ تلقائياً
                                </div>
                                <div style="font-size:.72rem;color:#64748b;margin-top:4px">
                                    حساب ذمة وحساب دفعات مقدمة، تحت الحسابين الأب المضبوطين مسبقاً بصفحة إعدادات
                                    الربط المحاسبي — إجباري لكل مورد جديد.
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="field-lbl">ملاحظات</label>
                            <textarea id="spNotes" class="form-control form-control-sm" rows="2"
                                placeholder="اختياري"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button class="btn btn-sm btn-light" style="border-radius:8px"
                        data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-sm fw-600" style="border-radius:8px;background:#1e3a8a;color:#fff"
                        onclick="saveSupplier()">
                        <i class="bi bi-plus me-1"></i>إضافة
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ── Sidebar ──
        const sb = document.getElementById('sidebar'), ov = document.getElementById('sbOverlay');
        function sbOpen() { sb.classList.add('open'); ov.classList.add('show'); }
        function sbClose() { sb.classList.remove('open'); ov.classList.remove('show'); }
        window.addEventListener('resize', () => { if (window.innerWidth > 991) sbClose(); });
        function toggleGroup(g) {
            const o = g.classList.contains('open');
            document.querySelectorAll('.sb-group.open').forEach(x => x.classList.remove('open'));
            g.classList.toggle('open', !o);
            localStorage.setItem('sb_open_' + g.dataset.key, (!o).toString());
        }
        document.querySelectorAll('.sb-group').forEach(g => {
            if (localStorage.getItem('sb_open_' + g.dataset.key) === 'true') g.classList.add('open');
        });

        const supplierModal = new bootstrap.Modal(document.getElementById('supplierModal'));

        // ── متغيرات الكاميرا — تُعرَّف أولاً ──
        var _camStream = null;
        var _camInterval = null;
        var lines = [];
        // بنود الفاتورة الأصلية (من purchase_items)، معاد بناؤها بنفس شكل
        // كائن "line" — راجع القسم PHP فوق (بعد تحميل $existingPur).
        const EXISTING_LINES = <?= json_encode($existingLinesForJs, JSON_UNESCAPED_UNICODE) ?>;

        // ── عملة الفاتورة ──
        let symCur = '$', codeCur = 'USD', exRate = 1;
        const BASE_CUR_CODE = '<?= htmlspecialchars($branchCur['code']) ?>';
        const BASE_CUR_SYM = '<?= htmlspecialchars($branchCur['symbol']) ?>';

        function onCurrencyChange() {
            const sel = document.getElementById('iCurrency');
            const opt = sel.options[sel.selectedIndex];
            exRate = parseFloat(opt.dataset.rate) || 1;
            symCur = opt.dataset.sym || '$';
            codeCur = sel.value;
            document.getElementById('iExRate').value = exRate;
            document.getElementById('exRateHint').textContent = `1 ${BASE_CUR_CODE} = ${exRate.toFixed(6)} ${codeCur}`;
            // ⚠ جدول بنود الفاتورة صار بعملة الفرع الثابتة فقط (قرار
            // صريح) — تغيير عملة الفاتورة ما عاد يأثر على أسعار البنود
            // إطلاقاً، فحذفنا إعادة الحساب هون.
            calcTotals();
        }
        function onExRateChange() {
            exRate = Math.max(0.0001, parseFloat(document.getElementById('iExRate').value) || 1);
            document.getElementById('exRateHint').textContent = `1 ${BASE_CUR_CODE} = ${exRate.toFixed(6)} ${codeCur}`;
            calcTotals();
        }
        // تهيئة
        onCurrencyChange();

        // ── بيانات البنود ──

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
            document.body.appendChild(t); setTimeout(() => t.remove(), 3200);
        }

        // ── بحث ──
        let _searchTimer = null;
        document.getElementById('scanInput').addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); doSearch(); }
        });
        document.getElementById('scanInput').addEventListener('input', e => {
            clearTimeout(_searchTimer);
            const v = e.target.value.trim();
            if (v.length >= 3) { _searchTimer = setTimeout(doSearch, 400); }
            else document.getElementById('searchResults').style.display = 'none';
        });

        function doSearch() {
            const q = document.getElementById('scanInput').value.trim();
            if (!q) return;
            post({ _action: 'search_product', q }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                if (d.type === 'barcode') {
                    // وجد مباشرة بالباركود — نمرّره لنفس مودال المراجعة (بكل
                    // المتغيرات المشاركة بنفس الباركود، لو الباركود مشترك
                    // بين مقاسات الكروب كله) بدل الإضافة الصامتة المباشرة،
                    // ليقدر المستخدم يراجع/يعدّل سعر الصرف قبل الإضافة،
                    // بالضبط متل نتائج البحث المتعددة. هذا يوحّد التجربة،
                    // ويلغي الحاجة لمسار "إضافة بدون مراجعة" كان بيتجاوز
                    // فحص سعر الصرف بالكامل.
                    showSearchResults(d.group && d.group.length ? d.group : [d.data]);
                    document.getElementById('scanInput').value = '';
                } else {
                    // عرض نتائج البحث
                    showSearchResults(d.data);
                }
            });
        }

        function showSearchResults(items) {
            if (!items.length) {
                toast('لا توجد نتائج للبحث', 'danger');
                return;
            }
            // فتح مودال الاختيار المتعدد
            openSelectModal(items);
        }

        // ── مودال الاختيار المتعدد ──
        var _selItems = [];
        var _selModal = null;

        function openSelectModal(items) {
            _selItems = items;
            _selModal = _selModal || new bootstrap.Modal(document.getElementById('selectModal'));

            // تجميع بالمنتج ثم بالكروب (selling_price)
            const GRP_COLORS = [
                ['#eff6ff', '#1e3a8a', '#bfdbfe'],
                ['#f0fdf4', '#065f46', '#bbf7d0'],
                ['#fff7ed', '#7c2d12', '#fed7aa'],
                ['#f5f3ff', '#4c1d95', '#ddd6fe'],
            ];

            const grouped = {};
            items.forEach(it => {
                const pid = it.product_id;
                if (!grouped[pid]) grouped[pid] = { name: it.product_name, model: it.model_number, grps: {} };
                // مفتاح الكروب: selling_price + age_type معاً
                const gk = (it.selling_price || '0') + '_' + (it.age_type || 'سنة');
                if (!grouped[pid].grps[gk]) grouped[pid].grps[gk] = { price: it.selling_price, age_type: it.age_type || 'سنة', variants: [] };
                grouped[pid].grps[gk].variants.push(it);
            });

            // بناء الجدول — كروب × لون = سطر واحد
            // نجمع أولاً: لكل (product × grp × color) → سطر
            // نبني مفتاح فريد: product_id + selling_price + color_id
            const rows = {};
            items.forEach(it => {
                // مفتاح فريد: منتج × سعر × نوع العمر × لون
                const key = `${it.product_id}_${it.selling_price}_${it.age_type || 'سنة'}_${it.color_id || 0}`;
                if (!rows[key]) {
                    rows[key] = {
                        key,
                        product_id: it.product_id,
                        product_name: it.product_name,
                        model_number: it.model_number,
                        selling_price: it.selling_price,
                        age_type: it.age_type || 'سنة',
                        color_id: it.color_id,
                        color_name: it.color_name,
                        color_hex: it.color_hex,
                        variants: [],
                        sizes: [],
                        cost_price: it.cost_price,
                        price_base_currency_code: it.price_base_currency_code,
                    };
                }
                rows[key].variants.push(it);
                if (!rows[key].sizes.includes(it.size)) rows[key].sizes.push(it.size);
            });

            // ترتيب: بالمنتج ثم بالسعر ثم باللون
            const sortedRows = Object.values(rows).sort((a, b) => {
                if (a.product_id !== b.product_id) return a.product_id - b.product_id;
                if (a.selling_price !== b.selling_price) return parseFloat(a.selling_price) - parseFloat(b.selling_price);
                if ((a.age_type || '') !== (b.age_type || '')) return (a.age_type || '').localeCompare(b.age_type || '');
                return (a.color_name || '').localeCompare(b.color_name || '');
            });

            // تحديد ألوان الكروبات لكل منتج
            const prodGrpColor = {};
            sortedRows.forEach(r => {
                if (!prodGrpColor[r.product_id]) prodGrpColor[r.product_id] = {};
                const pk = r.product_id;
                const gk = `${r.selling_price}_${r.age_type || 'سنة'}`;
                if (prodGrpColor[pk][gk] === undefined) {
                    prodGrpColor[pk][gk] = Object.keys(prodGrpColor[pk]).length;
                }
            });

            let html = '<div class="table-responsive"><table class="sel-table"><thead><tr>'
                + '<th style="width:36px"><input type="checkbox" id="chkAll" onchange="toggleAllSel(this)"></th>'
                + '<th>الموديل</th><th>المنتج</th>'
                + '<th>الكروب</th>'
                + '<th>القياسات</th>'
                + '<th>اللون</th>'
                + `<th>سعر الوحدة (${symCur})</th>`
                + '<th>عدد الكروبات</th>'
                + '<th>المجموع</th>'
                + '</tr></thead><tbody>';

            let lastProd = null;
            sortedRows.forEach(r => {
                const grpIdx = prodGrpColor[r.product_id][`${r.selling_price}_${r.age_type || 'سنة'}`];
                const [bg, clr, br] = GRP_COLORS[grpIdx % 4];
                const grpLabel = `<span class="grp-badge" style="background:${bg};color:${clr};border-color:${br}">
            كروب ${grpIdx + 1}
        </span>`;
                const colorDot = r.color_hex
                    ? `<span class="clr-dot" style="background:${r.color_hex};margin-left:4px"></span>` : '';
                const sizesStr = r.sizes.join(' · ');

                // السعر المرجعي بعملة الفرع الأساسية — من product_sizes.selling_price
                // (وليس cost_price — قرار مؤكد سابقاً: فاتورة الشراء تعرض سعر
                // البيع الافتراضي، لا سعر التكلفة). يُستخدم داخلياً فقط لحساب
                // سعر الوحدة المقترح أدناه؛ لم يعد يُعرض كعمود مستقل بالمودال
                // (حُذف "السعر المسجّل" و"سعر الصرف" بناءً على طلب إزالتهما).
                const rRawPrice = parseFloat(r.selling_price || 0);

                // سعر الصرف المقترح = سعر صرف عملة الفاتورة الحالية نسبة لعملة
                // الفرع مباشرة (exRate) — بدون أي قسمة إضافية، لأن rRawPrice
                // أصلاً بعملة الفرع. القسمة كانت هي البق: كانت تُلغي نفسها
                // بالخطأ فقط حين تتصادف عملة الفاتورة مع عملة تسجيل المنتج.
                const suggestedRate = exRate > 0 ? exRate : 1;
                const suggestedPrice = rRawPrice > 0 ? (rRawPrice * suggestedRate) : 0;

                // فاصل بين المنتجات
                if (lastProd !== r.product_id) {
                    if (lastProd !== null) {
                        html += `<tr><td colspan="9" style="height:6px;background:#f1f5f9;padding:0"></td></tr>`;
                    }
                    lastProd = r.product_id;
                }
                html += `<tr id="selrow_${r.key}" onclick="toggleSelRow(this,'${r.key}')">
            <td><input type="checkbox" class="sel-chk" data-key="${r.key}"
                onchange="onSelChk(this)" onclick="event.stopPropagation()"></td>
            <td dir="ltr" style="font-size:.75rem;color:#64748b">${r.model_number || '—'}</td>
            <td><div class="fw-600" style="font-size:.8rem">${r.product_name}</div></td>
            <td>${grpLabel}</td>
            <td style="font-size:.8rem;color:#1e293b;font-weight:600">${sizesStr} <span style="font-size:.68rem;color:#94a3b8">${r.age_type || ''}</span></td>
            <td><div class="d-flex align-items-center">${colorDot}<span style="font-size:.78rem">${r.color_name || '—'}</span></div></td>
            <td style="width:110px">
                <input type="number" class="sel-price" data-key="${r.key}"
                    value="${suggestedPrice.toFixed(4)}" min="0" step="0.0001" dir="ltr" readonly
                    style="width:100%;padding:3px 5px;border:1px solid #e2e8f0;border-radius:6px;font-size:.78rem;background:#f8fafc;color:#64748b"
                    title="من بيانات المنتج — للقراءة فقط" onclick="event.stopPropagation()">
            </td>
            <td style="width:70px">
                <input type="number" class="sel-qty" data-key="${r.key}"
                    value="1" min="1" step="1" dir="ltr"
                    style="width:100%;padding:3px 5px;border:1px solid #e2e8f0;border-radius:6px;font-size:.78rem"
                    onclick="event.stopPropagation()" oninput="recalcSelRowTotal('${r.key}')">
            </td>
            <td style="width:90px" dir="ltr">
                <span class="sel-total" data-key="${r.key}" style="font-size:.8rem;font-weight:700;color:#1e293b">
                    ${suggestedPrice.toFixed(2)}
                </span>
            </td>
        </tr>`;
            });

            html += '</tbody></table></div>';
            // حفظ الصفوف للاستخدام في confirmSelection
            window._selRows = sortedRows;

            // العنوان
            const names = [...new Set(items.map(i => i.product_name))];
            document.getElementById('selModalTitle').textContent =
                names.length === 1 ? names[0] : `${names.length} منتجات`;
            document.getElementById('selModalBody').innerHTML = html;
            document.getElementById('selCount').textContent = '';
            _selModal.show();
        }

        function toggleSelRow(tr, key) {
            const chk = tr.querySelector('.sel-chk');
            chk.checked = !chk.checked;
            tr.classList.toggle('checked', chk.checked);
            updateSelCount();
        }

        function onSelChk(chk) {
            const tr = chk.closest('tr');
            tr.classList.toggle('checked', chk.checked);
            updateSelCount();
        }

        function toggleAllSel(master) {
            document.querySelectorAll('.sel-chk').forEach(chk => {
                chk.checked = master.checked;
                chk.closest('tr').classList.toggle('checked', master.checked);
            });
            updateSelCount();
        }

        function updateSelCount() {
            const n = document.querySelectorAll('.sel-chk:checked').length;
            document.getElementById('selCount').textContent = n ? `(${n} محدد)` : '';
        }

        // المجموع = سعر الوحدة × الكمية — يتحدّث فوراً عند تعديل أي منهما
        // (بما فيه تعديل السعر يدوياً مباشرة، بغض النظر عن سعر الصرف).
        function recalcSelRowTotal(key) {
            const priceEl = document.querySelector(`.sel-price[data-key="${key}"]`);
            const qtyEl = document.querySelector(`.sel-qty[data-key="${key}"]`);
            const totalEl = document.querySelector(`.sel-total[data-key="${key}"]`);
            if (!priceEl || !qtyEl || !totalEl) return;
            const total = (parseFloat(priceEl.value) || 0) * (parseFloat(qtyEl.value) || 0);
            totalEl.textContent = total.toFixed(2);
        }

        function confirmSelection() {
            const checked = document.querySelectorAll('.sel-chk:checked');
            if (!checked.length) { toast('اختر كروب واحد على الأقل', 'danger'); return; }
            let added = 0;
            checked.forEach(chk => {
                const key = chk.dataset.key;
                const rowDef = (window._selRows || []).find(r => r.key === key);
                if (!rowDef) return;
                const prEl = document.querySelector(`.sel-price[data-key="${key}"]`);
                const qtyEl = document.querySelector(`.sel-qty[data-key="${key}"]`);
                const pr = parseFloat(prEl?.value || 0);
                const qty = parseInt(qtyEl?.value || 1);

                // أول variant يُنشئ السطر، الباقي يُدمج
                rowDef.variants.forEach((v, vi) => {
                    // pr هو السعر كما راجعه/عدّله المستخدم بالمودال — بعملة الفاتورة
                    // الحالية (متل ما هو ظاهر بعنوان العمود). نحوّله هنا لعملة الفرع
                    // (المرجع الثابت) قبل تمريره لـ addLine، بدل تركه يُعاد تفسيره
                    // كأنه بعملة الفرع أصلاً (كان هذا يسبب تحويلاً مضاعفاً خاطئاً).
                    const costBaseDirect = exRate > 0 ? pr / exRate : pr;
                    const lineItem = { ...v, cost_base_direct: costBaseDirect, selling_price: rowDef.selling_price };
                    if (vi === 0) addLine(lineItem);
                    else mergeVariant(lineItem);
                    added++;
                });
                // تعديل الكمية بعد الدمج
                if (qty > 1) {
                    const gk = makeGrpKey({ ...rowDef.variants[0], selling_price: rowDef.selling_price });
                    const line = lines.find(l => l.grp_key === gk);
                    if (line) {
                        line.qty = qty;
                        const row = document.getElementById(line.row_id);
                        if (row) { row.querySelector('.q-input').value = qty; recalcLine(line, row); }
                    }
                }
            });
            _selModal.hide();
            document.getElementById('scanInput').value = '';
            calcTotals();
            toast(`تمت إضافة ${added} متغير للفاتورة`);
        }

        // ── إضافة سطر (كروب×لون) ──
        // grp_key = product_id + selling_price + color_id
        function makeGrpKey(item) {
            return `${item.product_id}_${item.selling_price || item.default_price || 0}_${item.age_type || 'سنة'}_${item.color_id || 0}`;
        }

        function addLine(item) {
            const gk = makeGrpKey(item);

            // هل الكروب×لون موجود؟ → زيادة الكمية
            const exist = lines.find(l => l.grp_key === gk);
            if (exist) {
                exist.qty++;
                const row = document.getElementById('lgrp_' + gk.replace(/[^a-z0-9]/gi, '_'));
                if (row) { row.querySelector('.q-input').value = exist.qty; recalcLine(exist, row); }
                calcTotals();
                toast('تمت زيادة الكمية');
                return;
            }

            // سطر جديد — نجمع كل variants هذا الكروب×لون
            const rowId = 'lgrp_' + gk.replace(/[^a-z0-9]/gi, '_');
            // سعر القطعة الثابت بعملة الفرع (المرجع الأساسي — لا يتغير أبداً،
            // ويُعاد ضربه بـ exRate تلقائياً عند تغيير عملة الفاتورة — انظر
            // onCurrencyChange()). يوجد مصدران محتملان لهذه القيمة:
            let costBase;
            if (item.cost_base_direct !== undefined) {
                // من مودال الاختيار المتعدد: المستخدم راجع/عدّل السعر بعملة
                // الفاتورة، وconfirmSelection() سبق أن حوّله لعملة الفرع.
                costBase = parseFloat(item.cost_base_direct) || 0;
            } else {
                // مسار احتياطي غير مُستخدم حالياً (المطابقة المباشرة بالباركود
                // صارت تمر بالمودال دائماً — راجع doSearch()). لو استُخدم مستقبلاً:
                // selling_price مخزّن أصلاً بعملة الفرع (base_currency_id)، فلا
                // حاجة لأي تحويل هنا — القيمة الخام هي costBase مباشرة.
                // ⚠ عمداً بدون fallback لـ cost_price — سعر البيع الافتراضي
                // بفاتورة الشراء يجي من selling_price حصراً (قرار مؤكد).
                costBase = parseFloat(item.selling_price || 0);
            }
            const line = {
                grp_key: gk,
                row_id: rowId,
                variants: [item],           // variant_ids في هذا الكروب×لون
                product_id: item.product_id,
                product_name: item.product_name,
                model_number: item.model_number || '',
                selling_price: parseFloat(item.selling_price || 0),
                age_type: item.age_type || '',
                color_id: item.color_id || 0,
                color_name: item.color_name || '',
                color_hex: item.color_hex || '',
                sizes: [item.size || ''],
                packet_qty: parseFloat(item.packet_qty) || 1, // ⚠ عدد القطع بالباكيت — من product_sizes.packet_qty، للقراءة فقط
                qty: 1,                                        // عدد الكروبات
                default_price: costBase,                       // سعر البيع الافتراضي (بعملة الفرع) — من product_sizes.selling_price
                discount_value: 0,                             // قيمة الخصم الإفرادي (مبلغ، لا نسبة) — يُحفظ بعمود discount_amount
                net_price: costBase,                           // سعر البيع بعد الخصم الإفرادي — مرتبط ثنائياً بقيمة الخصم
                piece_count: 0,                                // عدد المنتجات = qty × packet_qty — هو المخزَّن بعمود quantity لاحقاً
                total: 0,
            };
            lines.push(line);
            document.getElementById('emptyRow').style.display = 'none';

            const GRP_COLORS = [
                ['#eff6ff', '#1e3a8a', '#bfdbfe'],
                ['#f0fdf4', '#065f46', '#bbf7d0'],
                ['#fff7ed', '#7c2d12', '#fed7aa'],
                ['#f5f3ff', '#4c1d95', '#ddd6fe'],
            ];
            // تحديد لون الكروب بناءً على selling_price
            const pricesForProd = [...new Set(lines.filter(l => l.product_id === item.product_id).map(l => l.selling_price))];
            const grpIdx = pricesForProd.indexOf(line.selling_price);
            const [bg, clr, br] = GRP_COLORS[grpIdx % 4];
            const grpBadge = `<span style="background:${bg};color:${clr};border:1px solid ${br};border-radius:12px;font-size:.68rem;padding:2px 8px;font-weight:600">
        كروب ${grpIdx + 1}
    </span>`;
            const colorDot = line.color_hex ? `<span class="clr-dot" style="background:${line.color_hex}"></span>` : '';
            const idx = lines.length;

            const tbody = document.getElementById('linesBody');
            const tr = document.createElement('tr');
            tr.id = rowId;
            tr.style.setProperty('--grp-c', clr);
            tr.innerHTML = `
        <td class="text-center"><span class="row-num">${idx}</span></td>
        <td>${line.product_name}</td>
        <td class="text-muted" dir="ltr">${line.model_number}</td>
        <td>
            ${grpBadge}
            <div class="sizes-lbl mt-1" style="color:#334155">${line.sizes.join(' · ')} ${line.age_type}</div>
        </td>
        <td class="text-center">
            <input type="number" class="pk-input" value="${line.packet_qty}" dir="ltr" readonly
                title="من إعدادات المنتج — للقراءة فقط">
        </td>
        <td><div class="d-flex align-items-center gap-1">${colorDot}<span>${line.color_name || '—'}</span></div></td>
        <td style="width:55px"><input type="number" class="q-input" min="1" step="1" value="1" dir="ltr"
            onchange="updateLine('${gk}','qty',this.value)"></td>
        <td class="text-center pc-lbl" style="color:#7c3aed">0</td>
        <td style="width:75px"><input type="number" class="p-input" min="0" step="0.0001"
            value="${line.default_price > 0 ? line.default_price : ''}" dir="ltr" readonly
            title="من بيانات المنتج — للقراءة فقط" placeholder="0.00"></td>
        <td style="width:75px"><input type="number" class="dv-input" min="0" step="0.0001"
            value="0" dir="ltr" readonly
            title="محسوب تلقائياً: الافتراضي − سعر البيع بعد الخصم — للقراءة فقط"></td>
        <td style="width:75px"><input type="number" class="np-input" min="0" step="0.0001"
            value="${line.net_price > 0 ? line.net_price : ''}" dir="ltr" placeholder="0.00"
            onchange="updateLine('${gk}','net_price',this.value)"></td>
        <td style="width:80px"><input type="number" class="t-input calc" readonly dir="ltr" placeholder="0.00"></td>
        <td><button class="del-btn" onclick="removeLine('${gk}')"><i class="bi bi-x-lg"></i></button></td>`;
            tbody.appendChild(tr);
            recalcLine(line, tr);
            calcTotals();
            updateLinesCount();
            if (!line.default_price) tr.querySelector('.q-input').focus();
        }

        // دمج variant في كروب موجود
        function mergeVariant(item) {
            const gk = makeGrpKey(item);
            const line = lines.find(l => l.grp_key === gk);
            if (!line) return addLine(item);
            if (!line.variants.find(v => v.variant_id === item.variant_id)) {
                line.variants.push(item);
                if (!line.sizes.includes(item.size)) line.sizes.push(item.size);
                // ⚠ قرار نهائي: الشراء بالكروب — دمج متغيّر جديد بنفس
                // الكروب ما بيغيّر عدد الكروبات المكتوب إطلاقاً.
                const row = document.getElementById(line.row_id);
                if (row) {
                    row.querySelector('.sizes-lbl').textContent = line.sizes.join(' · ') + ' ' + line.age_type;
                    recalcLine(line, row);
                }
            }
        }

        function updateLine(gk, field, val) {
            const line = lines.find(l => l.grp_key === gk);
            if (!line) return;
            // ⚠ قرار نهائي: الشراء بالقطعة — الكمية تُعتمد حرفياً بلا أي
            // تحقق/تقريب (انلغى قرار "مضاعف صحيح" السابق).
            if (field === 'qty') line.qty = Math.max(0.001, parseFloat(val) || 0);
            // ⚠ سعر البيع الافتراضي وقيمة الخصم صارا حقلين مقفلين (readonly)
            // — ما بوصلهم onchange من الواجهة إطلاقاً. الحقل الوحيد يلي
            // بيغيّر الخصم هو سعر البيع بعد الخصم نفسه (يُكتب مباشرة).
            if (field === 'net_price') {
                line.net_price = Math.max(0, parseFloat(val) || 0);
            }
            const row = document.getElementById(line.row_id);
            recalcLine(line, row);
            calcTotals();
        }

        function recalcLine(line, row) {
            // ⚠ الاتجاه الوحيد للحساب الآن: قيمة الخصم مقفلة ومحسوبة دائماً
            // = الافتراضي − سعر البيع بعد الخصم. سعر البيع بعد الخصم هو
            // الحقل التحريري الوحيد بين الاثنين (نفس معادلة الربط الأصلية:
            // سعر البيع بعد الخصم = سعر البيع الافتراضي − قيمة الخصم —
            // بس محلولة بالاتجاه المعاكس لأنه صار هو المُدخَل، لا الناتج).
            line.discount_value = Math.max(0, line.default_price - (line.net_price || 0));

            // ⚠ عدد المنتجات = عدد الكروبات × عدد القطع بالباكيت فقط —
            // هاي الكمية الحقيقية يلي بتُحفظ بعمود quantity لكل متغيّر
            // بمفرده (تأكيد صريح من المستخدم)، بدون أي ضرب إضافي بعدد
            // المقاسات المدموجة بالسطر.
            line.piece_count = (line.qty || 0) * (line.packet_qty || 1);
            line.total = line.piece_count * line.net_price;

            if (row) {
                row.querySelector('.pc-lbl').textContent = line.piece_count.toFixed(0);
                row.querySelector('.dv-input').value = (line.discount_value || 0).toFixed(2);
                row.querySelector('.np-input').value = line.net_price > 0 ? line.net_price.toFixed(2) : '';
                row.querySelector('.t-input').value = line.total > 0 ? line.total.toFixed(2) : '';
            }
        }

        function removeLine(gk) {
            // ⚠ إصلاح: كنا نعيد بناء الـID من نمط ثابت ('lgrp_'+gk) —
            // صحيح بس للأسطر المضافة عبر addLine()، بس أسطر الفاتورة
            // المحمّلة أصلاً من قاعدة البيانات (loadExistingLines) عندها
            // row_id بصيغة مختلفة (row_edit_...، من PHP). النتيجة: الحذف
            // كان يشتغل حسابياً (lines array صحيح) بس الصف البصري يضل
            // عالق لأنه getElementById ما بيلاقي عنصر بهيك ID. الحل: نجيب
            // row_id الحقيقي من كائن الخط نفسه قبل ما نحذفه من lines —
            // يشتغل صح بغض النظر شو كانت صيغته.
            const line = lines.find(l => l.grp_key === gk);
            lines = lines.filter(l => l.grp_key !== gk);
            if (line) {
                const row = document.getElementById(line.row_id);
                if (row) row.remove();
            }
            if (!lines.length) document.getElementById('emptyRow').style.display = '';
            calcTotals();
            updateLinesCount();
        }

        function updateLinesCount() {
            const n = lines.length;
            document.getElementById('linesCount').textContent = n ? `(${n} بند)` : '';
        }

        // ── الإجماليات ──
        function calcTotals() {
            // ١) عدد البنود = إجمالي الكروبات (مجموع qty على كل الأسطر)
            const totalLines = lines.reduce((s, l) => s + (l.qty || 0), 0);

            // ٤) المبلغ الصافي بدون الخصم الإفرادي = سعر البيع الافتراضي × عدد المنتجات (مجموع على كل الأسطر)
            const gross = lines.reduce((s, l) => s + (l.default_price || 0) * (l.piece_count || 0), 0);
            // ٥) مجموع قيم الخصوم الإفرادية (قيمة الخصم للوحدة × عدد المنتجات، مجموع على كل الأسطر)
            const lineDiscTotal = lines.reduce((s, l) => s + ((l.default_price || 0) - (l.net_price || 0)) * (l.piece_count || 0), 0);
            // ٦) المبلغ الصافي بعد الخصم الإفرادي = ٤ − ٥ (يساوي مجموع l.total أصلاً)
            const afterLineDisc = gross - lineDiscTotal;

            // ٧) نسبة الخصم العام للمورد
            const discPct = parseFloat(document.getElementById('discPct').value) || 0;

            // ٨-٩) خصم تعجيل الدفع — معلوماتي بس، محسوب من حقل ٦ مباشرة
            // (تأكيد صريح: ما بيدخل بحساب المبلغ الإجمالي إطلاقاً)
            const hasSettle = document.getElementById('iHasSettleDisc').checked;
            const settlePct = hasSettle ? (parseFloat(document.getElementById('iSettleDiscPct').value) || 0) : 0;
            const settleAmt = afterLineDisc * settlePct / 100;

            // ١٠) المبلغ الصافي بعد احتساب الخصوم (الإفرادية والعامة فقط)
            const afterAllDisc = afterLineDisc * (1 - discPct / 100);

            // ١١-١٢) الضريبة العامة — على حقل ١٠ (بعد كل الخصومات)
            const taxPct = parseFloat(document.getElementById('taxPct').value) || 0;
            const taxAmt = afterAllDisc * taxPct / 100;

            // ١٣) المبلغ الإجمالي = ١٠ + ١٢ (بدون خصم تعجيل الدفع)
            const grandTotal = afterAllDisc + taxAmt;

            document.getElementById('sumLines').textContent = totalLines.toFixed(0);
            document.getElementById('sumGross').textContent = gross.toFixed(2) + ' ' + BASE_CUR_SYM;
            document.getElementById('sumLineDisc').textContent = '-' + lineDiscTotal.toFixed(2) + ' ' + BASE_CUR_SYM;
            document.getElementById('sumAfterLineDisc').textContent = afterLineDisc.toFixed(2) + ' ' + BASE_CUR_SYM;
            document.getElementById('sumSettle').textContent = settleAmt.toFixed(2) + ' ' + BASE_CUR_SYM;
            document.getElementById('sumAfterAllDisc').textContent = afterAllDisc.toFixed(2) + ' ' + BASE_CUR_SYM;
            document.getElementById('sumTax').textContent = '+' + taxAmt.toFixed(2) + ' ' + BASE_CUR_SYM;
            document.getElementById('sumTotal').textContent = grandTotal.toFixed(2) + ' ' + BASE_CUR_SYM;
        }

        // ── حفظ الفاتورة ──
        function saveInvoice(saveAs) {
            if (!document.getElementById('iSupplier').value) { toast('يجب اختيار المورد', 'danger'); document.getElementById('iSupplier').focus(); return; }
            if (!document.getElementById('iWarehouse').value) { toast('يجب اختيار المستودع', 'danger'); return; }
            const valid = lines.filter(l => l.qty > 0 && l.default_price > 0);
            if (!valid.length) { toast('يجب إضافة منتج واحد على الأقل بسعر وكمية', 'danger'); return; }

            const btn = saveAs === 'confirmed'
                ? document.querySelector('[onclick="saveInvoice(\'confirmed\')"]')
                : document.querySelector('[onclick="saveInvoice(\'draft\')"]');
            const origHTML = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جارٍ الحفظ...';
            btn.disabled = true;

            post({
                _action: 'save_invoice',
                supplier_id: document.getElementById('iSupplier').value,
                warehouse_id: document.getElementById('iWarehouse').value,
                invoice_no: document.getElementById('iInvNo').value,
                invoice_date: document.getElementById('iDate').value,
                due_date: document.getElementById('iDueDate').value,
                settlement_discount_pct: document.getElementById('iHasSettleDisc').checked
                    ? (document.getElementById('iSettleDiscPct').value || 0) : '',
                currency: codeCur,
                exchange_rate: exRate,
                notes: document.getElementById('iNotes').value,
                discount_pct: document.getElementById('discPct').value,
                tax_pct: document.getElementById('taxPct').value,
                save_as: saveAs,
                rows: JSON.stringify(valid.map(l => ({ ...l, variant_ids: l.variants.map(v => v.variant_id) }))),
            }).then(d => {
                btn.innerHTML = origHTML;
                btn.disabled = false;
                if (d.ok) {
                    toast('✅ ' + d.msg + ' — ' + d.no);
                    setTimeout(() => window.location.href = 'index.php', 1200);
                } else toast(d.msg, 'danger');
            });
        }

        // ── كاميرا الباركود ──

        async function toggleCamera() {
            const wrap = document.getElementById('cameraWrap');
            if (_camStream) {
                stopCamera();
                wrap.style.display = 'none';
                document.getElementById('btnCamera').innerHTML = '<i class="bi bi-camera me-1"></i>كاميرا';
            } else {
                try {
                    _camStream = await navigator.mediaDevices.getUserMedia({
                        video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }
                    });
                    const video = document.getElementById('camVideo');
                    video.srcObject = _camStream;
                    wrap.style.display = 'block';
                    document.getElementById('btnCamera').innerHTML = '<i class="bi bi-camera-video-off me-1"></i>إيقاف';
                    startBarcodeDetection();
                } catch (e) {
                    toast('لا يمكن الوصول للكاميرا: ' + e.message, 'danger');
                }
            }
        }

        function stopCamera() {
            if (_camStream) { _camStream.getTracks().forEach(t => t.stop()); _camStream = null; }
            if (_camInterval) { cancelAnimationFrame(_camInterval); _camInterval = null; }
        }

        function startBarcodeDetection() {
            const video = document.getElementById('camVideo');
            const canvas = document.getElementById('camCanvas');
            const ctx = canvas.getContext('2d');
            const status = document.getElementById('camStatus');

            function processFrame() {
                if (!_camStream) return;
                if (video.readyState !== video.HAVE_ENOUGH_DATA) {
                    _camInterval = requestAnimationFrame(processFrame); return;
                }
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                ctx.drawImage(video, 0, 0);

                // ── محاولة BarcodeDetector الأصلية ──
                if ('BarcodeDetector' in window) {
                    const detector = new BarcodeDetector({
                        formats: ['ean_13', 'ean_8', 'code_128', 'code_39', 'qr_code', 'upc_a', 'upc_e']
                    });
                    detector.detect(canvas).then(codes => {
                        if (codes.length > 0) onBarcodeFound(codes[0].rawValue);
                        else _camInterval = requestAnimationFrame(processFrame);
                    }).catch(() => { _camInterval = requestAnimationFrame(processFrame); });
                    return;
                }

                // ── بديل: ZXing-js ──
                if (window._zxingReader) {
                    const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                    const lum = new window.ZXing.RGBLuminanceSource(imgData.data, canvas.width, canvas.height);
                    const bmp = new window.ZXing.BinaryBitmap(new window.ZXing.HybridBinarizer(lum));
                    try {
                        const result = window._zxingReader.decode(bmp);
                        if (result) { onBarcodeFound(result.getText()); return; }
                    } catch (e) { }
                }
                _camInterval = requestAnimationFrame(processFrame);
            }

            function onBarcodeFound(bc) {
                document.getElementById('scanInput').value = bc;
                status.innerHTML = `<i class="bi bi-check-circle-fill text-success me-1"></i>تم قراءة: ${bc}`;
                doSearch();
                // توقف مؤقت ثم استئناف
                setTimeout(() => {
                    if (_camStream) _camInterval = requestAnimationFrame(processFrame);
                }, 2000);
            }

            // تحميل ZXing إذا BarcodeDetector غير متوفر
            if (!('BarcodeDetector' in window) && !window._zxingLoaded) {
                window._zxingLoaded = true;
                status.innerHTML = '<i class="bi bi-hourglass-split me-1 text-warning"></i>جارٍ تحميل مكتبة المسح...';
                const s = document.createElement('script');
                s.src = 'https://cdn.jsdelivr.net/npm/@zxing/library@0.21.3/umd/index.min.js';
                s.onload = () => {
                    try {
                        window._zxingReader = new window.ZXing.MultiFormatReader();
                        status.innerHTML = '<i class="bi bi-circle-fill scanning me-1"></i>جارٍ المسح...';
                    } catch (e) {
                        status.innerHTML = '<i class="bi bi-exclamation-triangle me-1 text-warning"></i>تعذّر تحميل مكتبة المسح — اكتب الباركود يدوياً';
                    }
                    _camInterval = requestAnimationFrame(processFrame);
                };
                s.onerror = () => {
                    status.innerHTML = '<i class="bi bi-exclamation-triangle me-1 text-warning"></i>تعذّر تحميل المكتبة — اكتب الباركود يدوياً';
                };
                document.head.appendChild(s);
                return;
            }

            status.innerHTML = '<i class="bi bi-circle-fill scanning me-1"></i>جارٍ المسح...';
            _camInterval = requestAnimationFrame(processFrame);
        }

        // إيقاف الكاميرا عند مغادرة الصفحة
        window.addEventListener('beforeunload', stopCamera);

        // ── المورد ──
        function onSupplierChange() {
            const sel = document.getElementById('iSupplier');
            const opt = sel.options[sel.selectedIndex];
            const phone = opt.dataset.phone || '';
            const wrap = document.getElementById('supplierPhone');
            if (sel.value && phone) {
                document.getElementById('spPhoneTxt').textContent = phone;
                wrap.style.display = 'block';
            } else wrap.style.display = 'none';
            // ⚠ نسبة الخصم العام تتعبّى تلقائياً من إعدادات المورد
            // (discount_percentage) — تضل قابلة للتعديل يدوياً بهالفاتورة
            // تحديداً بدون ما تأثر على إعداد المورد نفسه.
            document.getElementById('discPct').value = sel.value ? (opt.dataset.discount || 0) : 0;
            calcTotals();
        }

        function openSupplierModal() {
            ['spName', 'spContact', 'spPhone', 'spEmail', 'spTax', 'spAddress', 'spNotes'].forEach(id => document.getElementById(id).value = '');
            document.getElementById('spType').value = 'wholesaler';
            document.getElementById('spSupType').value = 'product';
            document.getElementById('spCredit').value = 0;
            document.getElementById('spDiscount').value = 0;
            supplierModal.show();
        }

        function saveSupplier() {
            const name = document.getElementById('spName').value.trim();
            if (!name) { toast('اسم المورد مطلوب', 'danger'); return; }
            post({
                _action: 'add_supplier', name,
                contact_person: document.getElementById('spContact').value,
                phone: document.getElementById('spPhone').value,
                email: document.getElementById('spEmail').value,
                tax_number: document.getElementById('spTax').value,
                address: document.getElementById('spAddress').value,
                type: document.getElementById('spType').value,
                supplier_type: document.getElementById('spSupType').value,
                credit_limit: document.getElementById('spCredit').value,
                discount_percentage: document.getElementById('spDiscount').value,
                notes: document.getElementById('spNotes').value,
            }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                const sel = document.getElementById('iSupplier');
                const opt = document.createElement('option');
                opt.value = d.id; opt.dataset.phone = d.phone || '';
                opt.dataset.discount = d.discount_percentage || 0;
                opt.textContent = d.name; opt.selected = true;
                sel.appendChild(opt);
                onSupplierChange();
                supplierModal.hide();
                toast(d.msg || 'تمت إضافة المورد');
            });
        }

        // ── تحديث المنتجات ──
        // ⚠ قرار صريح: بلا sessionStorage — زر "تحديث" يرجع دائماً للنسخة
        // المحفوظة بقاعدة البيانات (أي تعديل غير محفوظ وقت الضغط يضيع
        // عمداً، تبسيطاً وتوضيحاً للسلوك). لو المستخدم بنص تعديل، خليه
        // يحفظ أول قبل ما يضغط تحديث.
        function refreshProducts() {
            location.reload();
        }

        // ── تحميل بنود الفاتورة الأصلية عند فتح صفحة التعديل ─────────
        // البيانات جايّة من PHP (EXISTING_LINES)، مبنية من purchase_items
        // المحفوظة فعلياً — نفس شكل كائن "line" المستخدم بالإنشاء تماماً،
        // فنعيد استخدام نفس منطق بناء الصف بدل تكراره.
        (function loadExistingLines() {
            // ⚠ لازم أول شي — يزامن متغيّرات exRate/symCur/codeCur الفعلية
            // مع عملة الفاتورة المحفوظة (الحقل بالـHTML معبّى من PHP، بس
            // المتغيّرات نفسها تبدأ بقيم افتراضية لعملة الفرع دائماً).
            onCurrencyChange();

            // ⚠ عرض هاتف المورد المحدَّد مسبقاً — بدون استدعاء
            // onSupplierChange() الكاملة عمداً، لأنها بتصفّر discPct
            // بقيمة افتراضية من إعدادات المورد الحالية، وهيك بتمسح نسبة
            // الخصم التاريخية المُعاد بناؤها من الفاتورة الأصلية (القيمة
            // يلي المستخدم كتبها فعلياً وقت الإنشاء، مو الإعداد الحالي
            // للمورد).
            const supSel = document.getElementById('iSupplier');
            const supOpt = supSel.options[supSel.selectedIndex];
            if (supSel.value && supOpt?.dataset.phone) {
                document.getElementById('spPhoneTxt').textContent = supOpt.dataset.phone;
                document.getElementById('supplierPhone').style.display = 'block';
            }

            if (!EXISTING_LINES.length) return;
            document.getElementById('emptyRow').style.display = 'none';
            const tbody = document.getElementById('linesBody');
            const GRP_COLORS = [['#eff6ff', '#1e3a8a', '#bfdbfe'], ['#f0fdf4', '#065f46', '#bbf7d0'], ['#fff7ed', '#7c2d12', '#fed7aa'], ['#f5f3ff', '#4c1d95', '#ddd6fe']];
            EXISTING_LINES.forEach(l => {
                lines.push(l);
                const tr = document.createElement('tr');
                tr.id = l.row_id;
                // ⚠ تلوين الكروب حسب السعر الافتراضي (المرجعي) — نفس معيار
                // مودال التفاصيل بـindex.php، لا السعر الصافي (ممكن يتصادف
                // بين كروبات مختلفة فعلياً — راجع الإصلاح هناك للتفصيل).
                const pricesForProd = [...new Set(EXISTING_LINES.filter(x => x.product_id === l.product_id).map(x => x.default_price))];
                const grpIdx = pricesForProd.indexOf(l.default_price);
                const [bg, clr, br] = GRP_COLORS[grpIdx % 4];
                tr.style.setProperty('--grp-c', clr);
                const colorDot = l.color_hex ? `<span class="clr-dot" style="background:${l.color_hex}"></span>` : '';
                const grpBadge = `<span style="background:${bg};color:${clr};border:1px solid ${br};border-radius:12px;font-size:.68rem;padding:2px 8px;font-weight:600">كروب ${grpIdx + 1}</span>`;
                tr.innerHTML = `
            <td class="text-center"><span class="row-num">${lines.length}</span></td>
            <td>${l.product_name}</td>
            <td class="text-muted" dir="ltr">${l.model_number}</td>
            <td>${grpBadge}
                <div class="sizes-lbl mt-1" style="color:#334155">${l.sizes.join(' · ')} ${l.age_type}</div></td>
            <td class="text-center">
                <input type="number" class="pk-input" value="${l.packet_qty || 1}" dir="ltr" readonly
                    title="من إعدادات المنتج — للقراءة فقط">
            </td>
            <td><div class="d-flex align-items-center gap-1">${colorDot}<span>${l.color_name || '—'}</span></div></td>
            <td style="width:55px"><input type="number" class="q-input" min="1" step="1" value="${l.qty}" dir="ltr"
                onchange="updateLine('${l.grp_key}','qty',this.value)"></td>
            <td class="text-center pc-lbl" style="color:#7c3aed">0</td>
            <td style="width:75px"><input type="number" class="p-input" min="0" step="0.0001"
                value="${l.default_price || ''}" dir="ltr" readonly
                title="من بيانات المنتج — للقراءة فقط" placeholder="0.00"></td>
            <td style="width:75px"><input type="number" class="dv-input" min="0" step="0.0001"
                value="${l.discount_value || 0}" dir="ltr" readonly
                title="محسوب تلقائياً: الافتراضي − سعر البيع بعد الخصم — للقراءة فقط"></td>
            <td style="width:75px"><input type="number" class="np-input" min="0" step="0.0001"
                value="${l.net_price || ''}" dir="ltr" placeholder="0.00"
                onchange="updateLine('${l.grp_key}','net_price',this.value)"></td>
            <td style="width:80px"><input type="number" class="t-input calc" readonly dir="ltr" placeholder="0.00"></td>
            <td><button class="del-btn" onclick="removeLine('${l.grp_key}')"><i class="bi bi-x-lg"></i></button></td>`;
                tbody.appendChild(tr);
                recalcLine(l, tr);
            });
            calcTotals();
            updateLinesCount();
        })();

        // إغلاق نتائج البحث بالنقر خارجها
        document.addEventListener('click', e => {
            const sr = document.getElementById('searchResults');
            if (!sr.contains(e.target) && e.target.id !== 'scanInput')
                sr.style.display = 'none';
        });
    </script>
</body>

</html>