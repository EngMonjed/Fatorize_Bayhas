<?php
/**
 * purchases/returns.php — مرتجعات فواتير شراء المنتجات
 * المسار: retail1/modules/purchases/returns.php
 *
 * ⚠ هاي الصفحة بتدير المسودات فقط (إنشاء/تعديل/حذف مرتجع بحالة draft).
 * التأكيد الفعلي (status=posted — عكس المخزون + قيد محاسبي) لازم يصير
 * حصراً عبر api/confirm_purchase_return.php (مو local action هون) —
 * نفس مبدأ فصل التأكيد المحاسبي عن CRUD المسودات المعتمد أصلاً بفواتير
 * الشراء العادية (راجع api/confirm_purchase_invoice.php). الملف هاد
 * لسا غير مبني — زر "تأكيد" بالواجهة موجود ومربوط بمساره الصحيح
 * مسبقاً، بس هيرجع خطأ لحد ما يُبنى بمحادثة لاحقة بعد مراجعة
 * confirm_purchase_invoice.php كمرجع لآلية postAccountBalance().
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('purchases.returns', 'view');
$currentModule = 'purchases.returns';

$TS   = $_SESSION['table_suffix'];
$TR   = "purchase_returns_{$TS}";
$TRI  = "purchase_return_items_{$TS}";
$TP   = "purchases_{$TS}";
$TPI  = "purchase_items_{$TS}";
$TSP  = "product_suppliers_{$TS}";
$TW   = "warehouses_{$TS}";
$TV   = "product_variants_{$TS}";
$TAC  = "account_charts_{$TS}";
$TPROD = "products_{$TS}";
$TPSZ = "product_sizes_{$TS}";
$TPCL = "product_colors_{$TS}";
$TJE  = "journal_entries_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// حسابات الصندوق/البنك — لاسترداد المرتجع نقداً (لو target_account_type=cash)
// نفس استبعاد حسابات الدفعات المقدمة الخاصة بالموردين المعتمد بـ index.php
$cashAccounts = $pdo->query("SELECT ac.id,ac.code,ac.name,ac.balance,c.code AS cur_code,c.symbol AS cur_sym
    FROM `{$TAC}` ac
    LEFT JOIN currencies c ON c.id=ac.currency_id
    WHERE ac.account_type='asset' AND ac.is_active=1 AND ac.level>=3
      AND ac.id NOT IN (SELECT prepaid_account_id FROM `{$TSP}` WHERE prepaid_account_id IS NOT NULL)
    ORDER BY ac.code")->fetchAll();

function genReturnNo(PDO $pdo, string $table): string
{
    $y = date('Y');
    $last = $pdo->query("SELECT return_number FROM `{$table}`
        WHERE return_number LIKE 'RET-{$y}-%'
        ORDER BY id DESC LIMIT 1")->fetchColumn();
    $seq = $last ? (int) substr($last, -5) + 1 : 1;
    return "RET-{$y}-" . str_pad($seq, 5, '0', STR_PAD_LEFT);
}

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        // بحث عن فاتورة شراء مؤكدة + بنودها القابلة للإرجاع
        if ($act === 'find_purchase') {
            $q = trim($_POST['q'] ?? '');
            // ⚠ بحث فارغ = يرجّع أحدث ١٥ فاتورة مؤكدة (بدل رفض الطلب) —
            // حتى يقدر المستخدم يشوف قائمة جاهزة فور فتح المودال، مو
            // يُجبر يكتب شي أول.
            $like = $q !== '' ? "%{$q}%" : '%';

            $st = $pdo->prepare("SELECT p.id, p.purchase_number, p.purchase_date, p.supplier_id,
                    s.name AS supplier_name, p.invoice_currency_id, p.base_currency_id, p.exchange_rate,
                    p.warehouse_id, c.code AS currency_code, c.symbol AS currency_symbol,
                    p.total_amount, p.discount_amount AS inv_discount_amount,
                    p.tax_amount AS inv_tax_amount, p.final_amount, p.payment_status,
                    bc.code AS base_currency_code, bc.symbol AS base_currency_symbol
                FROM `{$TP}` p
                JOIN `{$TSP}` s ON s.id = p.supplier_id
                LEFT JOIN currencies c ON c.id = p.invoice_currency_id
                LEFT JOIN currencies bc ON bc.id = p.base_currency_id
                WHERE p.status = 'confirmed'
                  AND (p.purchase_number LIKE ? OR s.name LIKE ? OR p.id = ?)
                ORDER BY p.purchase_date DESC LIMIT 15");
            $st->execute([$like, $like, $q !== '' && ctype_digit($q) ? (int) $q : 0]);
            $purchases = $st->fetchAll();

            echo json_encode(['ok' => true, 'purchases' => $purchases]);
            exit;
        }

        // بنود فاتورة معيّنة + الكمية المتبقية القابلة للإرجاع لكل بند
        // (المشتراة − مجموع كل ما أُرجع سابقاً بمرتجعات غير ملغاة)
        if ($act === 'get_purchase_items') {
            $purId = (int) ($_POST['purchase_id'] ?? 0);
            if (!$purId) { echo json_encode(['ok' => false, 'msg' => 'فاتورة غير محددة']); exit; }

            $st = $pdo->prepare("SELECT
                    pi.id AS purchase_item_id, pi.product_id, pi.variant_id,
                    pi.quantity, pi.unit_price AS gross_unit_price, pi.total_price, pi.discount_percentage,
                    pr.name AS product_name, pr.model_number AS model_number,
                    sz.size AS size, sz.age_type AS age_type, sz.group_key AS group_key, cl.name AS color,
                    COALESCE((
                        SELECT SUM(ri.quantity_returned)
                        FROM `{$TRI}` ri
                        JOIN `{$TR}` r ON r.id = ri.return_id
                        WHERE ri.purchase_item_id = pi.id AND r.status != 'cancelled'
                    ), 0) AS already_returned
                FROM `{$TPI}` pi
                LEFT JOIN `product_variants_{$TS}` v ON v.id = pi.variant_id
                LEFT JOIN `products_{$TS}` pr ON pr.id = pi.product_id
                LEFT JOIN `product_sizes_{$TS}` sz ON sz.id = v.size_id
                LEFT JOIN `product_colors_{$TS}` cl ON cl.id = v.color_id
                WHERE pi.purchase_id = ?");
            $st->execute([$purId]);
            $items = $st->fetchAll();
            foreach ($items as &$it) {
                $it['returnable_qty'] = max(0, (float) $it['quantity'] - (float) $it['already_returned']);
                $it['display_name'] = trim(($it['product_name'] ?? '') . ' ' . ($it['size'] ?? '') . ' ' . ($it['color'] ?? ''));
                // ⚠ السعر الصافي الحقيقي بعد خصم السطر (لو وُجد) — مو
                // السعر الخام. total_price أصلاً محسوب = qty×unit_price×
                // (1−discount_percentage/100) وقت إنشاء الفاتورة، فقسمته
                // على الكمية بترجّع السعر الفعلي المدفوع للوحدة. لو
                // استخدمنا gross_unit_price مباشرة (القديم)، كان المرتجع
                // بيتجاهل خصم السطر بالكامل ويحسب قيمة أعلى من الصحيح.
                $it['unit_price'] = $it['quantity'] > 0
                    ? round((float) $it['total_price'] / (float) $it['quantity'], 4)
                    : (float) $it['gross_unit_price'];
                // ⚠ سعر افتراضي مُعاد بناؤه (قبل الخصم) — لمفتاح التجميع
                // بالواجهة حصراً، لا للحساب. gross_unit_price (عمود
                // purchase_items.unit_price) مُسمّى بشكل مضلِّل: هو فعلياً
                // net_price×exchange_rate (بعملة الفاتورة)، مو السعر
                // الافتراضي الحقيقي قبل الخصم — فلا يصلح كمصدر لإعادة
                // البناء. نفس معادلة إعادة البناء المعتمدة بمودالي التفاصيل
                // والتأكيد بـindex.php بالضبط.
                $discPct = (float) $it['discount_percentage'];
                $it['default_price'] = $discPct > 0
                    ? round($it['unit_price'] / (1 - $discPct / 100), 4)
                    : $it['unit_price'];
            }
            echo json_encode(['ok' => true, 'items' => $items]);
            exit;
        }

        // حفظ مسودة مرتجع (إنشاء أو تعديل — بس إذا كانت لسا draft)
        if ($act === 'save_return') {
            requirePermission('purchases.returns', 'create');
            $returnId = (int) ($_POST['return_id'] ?? 0);
            $purId    = (int) ($_POST['purchase_id'] ?? 0);
            $reason   = trim($_POST['return_reason'] ?? '');
            $payHandling = $_POST['payment_handling'] ?? 'not_paid';
            // ⚠ حقل نوعية التسوية والحساب المستهدف مرتبطين ببعض: not_paid
            // = بلا حساب مستهدف إطلاقاً (يُصفَّر بالسيرفر بغض النظر عمّا
            // وصل من الواجهة — حماية سيادية). partial/paid_full = حساب
            // مستهدف إجباري (صندوق/ذمة/دفعة مقدمة).
            $targetType = in_array($payHandling, ['partial', 'paid_full'], true)
                ? ($_POST['target_account_type'] ?? '') : null;
            if (in_array($payHandling, ['partial', 'paid_full'], true) && !$targetType) {
                echo json_encode(['ok' => false, 'msg' => 'اختر الحساب المستهدف (صندوق/بنك، ذمة المورد، أو دفعة مقدمة)']); exit;
            }
            $refundAccId = $targetType === 'cash' ? ((int) ($_POST['refund_account_id'] ?? 0) ?: null) : null;
            if ($targetType === 'cash' && !$refundAccId) {
                echo json_encode(['ok' => false, 'msg' => 'اختر حساب الصندوق/البنك']); exit;
            }
            $notes    = trim($_POST['notes'] ?? '');
            $lines    = json_decode($_POST['lines'] ?? '[]', true) ?: [];

            if (!$purId || empty($lines)) {
                echo json_encode(['ok' => false, 'msg' => 'اختر الفاتورة وبند واحد على الأقل']); exit;
            }

            $pur = $pdo->prepare("SELECT * FROM `{$TP}` WHERE id=?");
            $pur->execute([$purId]);
            $pur = $pur->fetch();
            if (!$pur) { echo json_encode(['ok' => false, 'msg' => 'الفاتورة الأصلية غير موجودة']); exit; }

            $totalAmt = 0;
            $lineData = [];
            foreach ($lines as $l) {
                $piId = (int) ($l['purchase_item_id'] ?? 0);
                $qty  = (float) ($l['quantity_returned'] ?? 0);
                if (!$piId || $qty <= 0) continue;

                $pi = $pdo->prepare("SELECT * FROM `{$TPI}` WHERE id=? AND purchase_id=?");
                $pi->execute([$piId, $purId]);
                $pi = $pi->fetch();
                if (!$pi) continue;

                // إعادة تحقق الكمية القابلة للإرجاع على السيرفر (مو بس بالواجهة)
                $already = $pdo->prepare("SELECT COALESCE(SUM(ri.quantity_returned),0)
                    FROM `{$TRI}` ri JOIN `{$TR}` r ON r.id=ri.return_id
                    WHERE ri.purchase_item_id=? AND r.status != 'cancelled' AND r.id != ?");
                $already->execute([$piId, $returnId ?: 0]);
                $returnable = (float) $pi['quantity'] - (float) $already->fetchColumn();
                if ($qty > $returnable) {
                    echo json_encode(['ok' => false, 'msg' => "الكمية المطلوب إرجاعها أكبر من المتاح لبند رقم {$piId}"]); exit;
                }

                // ⚠ السعر الصافي بعد خصم السطر (لا الخام) — نفس منطق
                // get_purchase_items بالضبط، حتى لا يتجاهل المرتجع أي
                // خصم كان مسجَّلاً على السطر بالذات وقت إنشاء الفاتورة.
                $netUnitPrice = (float) $pi['quantity'] > 0
                    ? round((float) $pi['total_price'] / (float) $pi['quantity'], 4)
                    : (float) $pi['unit_price'];

                $lineTotal = $qty * $netUnitPrice;
                $totalAmt += $lineTotal;
                $lineData[] = [
                    'purchase_item_id'  => $piId,
                    'product_id'        => $pi['product_id'],
                    'variant_id'        => $pi['variant_id'],
                    'product_name'      => $l['display_name'] ?? ('بند #' . $piId),
                    'quantity_returned' => $qty,
                    'unit_price'        => $netUnitPrice,
                    'total_price'       => $lineTotal,
                ];
            }
            if (empty($lineData)) { echo json_encode(['ok' => false, 'msg' => 'لا يوجد بند صالح للحفظ']); exit; }

            // ⚠ حساب الخصم والضريبة المتناسبين — بنفس نسبة الفاتورة
            // الأصلية كاملة، مطبَّقة على قيمة البنود المرتجعة فقط. يُعاد
            // حسابه هون بالسيرفر (مو بالثقة بالرقم القادم من الواجهة)
            // لضمان صحة المبلغ المرحَّل محاسبياً لاحقاً وقت التأكيد.
            $invTotal = (float) $pur['total_amount'];
            $discAmt = 0.0;
            $taxAmt = 0.0;
            if ($invTotal > 0) {
                $discRatio = ((float) $pur['discount_amount']) / $invTotal;
                $taxBaseInv = $invTotal - (float) $pur['discount_amount'];
                $taxRatio = $taxBaseInv > 0 ? ((float) $pur['tax_amount']) / $taxBaseInv : 0;
                $discAmt = round($totalAmt * $discRatio, 2);
                $taxAmt = round(($totalAmt - $discAmt) * $taxRatio, 2);
            }
            $returnAmt = round($totalAmt - $discAmt + $taxAmt, 2);

            $pdo->beginTransaction();
            try {
                if ($returnId) {
                    // تعديل مسودة موجودة فقط
                    $chk = $pdo->prepare("SELECT status FROM `{$TR}` WHERE id=?");
                    $chk->execute([$returnId]);
                    if ($chk->fetchColumn() !== 'draft') {
                        throw new Exception('لا يمكن تعديل مرتجع مؤكد أو ملغى');
                    }
                    $pdo->prepare("UPDATE `{$TR}` SET purchase_id=?, supplier_id=?, supplier_name=?,
                            purchase_number=?, warehouse_id=?, return_date=CURDATE(),
                            total_amount=?, discount_amount=?, tax_amount=?, return_amount=?,
                            return_currency_id=?, base_currency_id=?,
                            exchange_rate=?, payment_handling=?, target_account_type=?, refund_account_id=?, return_reason=?, notes=?
                        WHERE id=? AND status='draft'")
                        ->execute([
                            $purId, $pur['supplier_id'], null, $pur['purchase_number'], $pur['warehouse_id'],
                            $totalAmt, $discAmt, $taxAmt, $returnAmt, $pur['invoice_currency_id'], $pur['base_currency_id'],
                            $pur['exchange_rate'], $payHandling, $targetType, $refundAccId, $reason, $notes, $returnId
                        ]);
                    $pdo->prepare("DELETE FROM `{$TRI}` WHERE return_id=?")->execute([$returnId]);
                } else {
                    $retNo = genReturnNo($pdo, $TR);
                    $pdo->prepare("INSERT INTO `{$TR}`
                            (return_number, purchase_id, purchase_number, supplier_id, warehouse_id,
                             return_date, total_amount, discount_amount, tax_amount, return_amount,
                             return_currency_id, base_currency_id,
                             exchange_rate, payment_handling, target_account_type, refund_account_id, return_reason, status, notes, user_id)
                            VALUES (?,?,?,?,?, CURDATE(),?,?,?,?,?,?, ?,?,?,?,?, 'draft', ?, ?)")
                        ->execute([
                            $retNo, $purId, $pur['purchase_number'], $pur['supplier_id'], $pur['warehouse_id'],
                            $totalAmt, $discAmt, $taxAmt, $returnAmt, $pur['invoice_currency_id'], $pur['base_currency_id'],
                            $pur['exchange_rate'], $payHandling, $targetType, $refundAccId, $reason, $notes, $_SESSION['user_id']
                        ]);
                    $returnId = (int) $pdo->lastInsertId();
                }

                foreach ($lineData as $ld) {
                    $pdo->prepare("INSERT INTO `{$TRI}`
                            (return_id, purchase_item_id, product_id, variant_id, product_name,
                             quantity_returned, unit_price, total_price)
                            VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([
                            $returnId, $ld['purchase_item_id'], $ld['product_id'], $ld['variant_id'],
                            $ld['product_name'], $ld['quantity_returned'], $ld['unit_price'], $ld['total_price']
                        ]);
                }

                $pdo->commit();
                echo json_encode(['ok' => true, 'msg' => 'تم حفظ المرتجع كمسودة', 'return_id' => $returnId]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            }
            exit;
        }

        // جلب مرتجع كامل (للعرض/التعديل)
        if ($act === 'get_return') {
            $id = (int) ($_POST['id'] ?? 0);
            // ⚠ إصلاح حرج: p.total_amount (الفاتورة الأصلية) وr.total_amount
            // (المرتجع نفسه) بنفس الاسم بالضبط — بدون alias، PDO كان بيرجّع
            // مصفوفة واحدة وآخر عمود بنفس الاسم (p.total_amount، جاي بعد
            // r.*) بيدهس قيمة r.total_amount الصحيحة. سبّب عرض "١٧٧$" (إجمالي
            // الفاتورة الكاملة) بدل "١٠١$" (الصحيح لهالمرتجع تحديداً) بقسم
            // المبالغ بمودال التفاصيل.
            $r = $pdo->prepare("SELECT r.*, s.name AS supplier_name_live, s.phone AS supplier_phone,
                    c.code AS currency_code, c.symbol AS currency_symbol, bc.code AS base_currency_code,
                    p.total_amount AS inv_total_amount, p.discount_amount AS inv_discount_amount,
                    p.tax_amount AS inv_tax_amount, p.final_amount AS inv_final_amount, p.payment_status,
                    w.name AS warehouse_name
                FROM `{$TR}` r LEFT JOIN `{$TSP}` s ON s.id=r.supplier_id
                LEFT JOIN currencies c ON c.id=r.return_currency_id
                LEFT JOIN currencies bc ON bc.id=r.base_currency_id
                LEFT JOIN `{$TP}` p ON p.id=r.purchase_id
                LEFT JOIN `{$TW}` w ON w.id=r.warehouse_id
                WHERE r.id=?");
            $r->execute([$id]);
            $r = $r->fetch();
            if (!$r) { echo json_encode(['ok' => false, 'msg' => 'غير موجود']); exit; }

            // ⚠ purchase_return_items ما فيها مقاس/لون/موديل مباشرة —
            // نجيبهم عبر variant_id (نفس نمط get_purchase بـindex.php).
            // discount_percentage نجيبها من purchase_items الأصلية (عبر
            // purchase_item_id) — غير مخزَّنة على purchase_return_items
            // نفسها، بس لازمة لإعادة بناء "سعر الشراء الافتراضي" للعرض.
            $items = $pdo->prepare("SELECT tri.*,
                    pr.model_number AS model_number,
                    psz.size AS size, psz.age_type AS age_type, psz.group_key AS group_key,
                    pcl.name AS color,
                    pi.discount_percentage AS discount_percentage
                FROM `{$TRI}` tri
                LEFT JOIN `{$TPROD}` pr ON pr.id = tri.product_id
                LEFT JOIN `{$TV}` pv ON pv.id = tri.variant_id
                LEFT JOIN `{$TPSZ}` psz ON psz.id = pv.size_id
                LEFT JOIN `{$TPCL}` pcl ON pcl.id = pv.color_id
                LEFT JOIN `{$TPI}` pi ON pi.id = tri.purchase_item_id
                WHERE tri.return_id=?");
            $items->execute([$id]);
            $items = $items->fetchAll();
            // ⚠ نفس معادلة إعادة البناء المعتمدة بكل الملف — راجع
            // get_purchase_items للتفصيل الكامل.
            foreach ($items as &$it2) {
                $discPct2 = (float) ($it2['discount_percentage'] ?? 0);
                $it2['default_price'] = $discPct2 > 0
                    ? round((float) $it2['unit_price'] / (1 - $discPct2 / 100), 4)
                    : (float) $it2['unit_price'];
            }
            unset($it2);

            // ⚠ القيد المحاسبي المرتبط فعلياً (لو مؤكد) — من journal_entries
            // مباشرة (مصدر الحقيقة)، نفس مبدأ get_purchase بـindex.php.
            $jes = $pdo->prepare("SELECT je.*, c.code AS je_currency
                FROM `{$TJE}` je LEFT JOIN currencies c ON c.id = je.currency_id
                WHERE je.reference_type='purchase_return' AND je.reference_id=?
                ORDER BY je.id");
            $jes->execute([$id]);

            echo json_encode(['ok' => true, 'return' => $r, 'items' => $items, 'journal_entries' => $jes->fetchAll()]);
            exit;
        }

        // حذف مسودة (draft فقط — لا يوجد تأثير مخزون/محاسبة بعد لحذفها)
        if ($act === 'delete_return') {
            requirePermission('purchases.returns', 'delete');
            $id = (int) ($_POST['id'] ?? 0);
            $chk = $pdo->prepare("SELECT status FROM `{$TR}` WHERE id=?");
            $chk->execute([$id]);
            if ($chk->fetchColumn() !== 'draft') {
                echo json_encode(['ok' => false, 'msg' => 'لا يمكن حذف مرتجع مؤكد أو ملغى']); exit;
            }
            $pdo->prepare("DELETE FROM `{$TR}` WHERE id=? AND status='draft'")->execute([$id]);
            echo json_encode(['ok' => true, 'msg' => 'تم الحذف']);
            exit;
        }

        echo json_encode(['ok' => false, 'msg' => 'إجراء غير معروف']);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── عرض الصفحة ──────────────────────────────────────────────────
$search   = trim($_GET['q'] ?? '');
$status   = $_GET['status'] ?? '';
$suppFil  = (int) ($_GET['supplier'] ?? 0);
$dateFrom = $_GET['from'] ?? '';
$dateTo   = $_GET['to'] ?? '';

$where = [];
$params = [];
if ($search !== '') {
    $where[] = "(r.return_number LIKE ? OR r.purchase_number LIKE ? OR s.name LIKE ?)";
    $like = "%{$search}%";
    array_push($params, $like, $like, $like);
}
if ($status !== '') { $where[] = "r.status = ?"; $params[] = $status; }
if ($suppFil) { $where[] = "r.supplier_id = ?"; $params[] = $suppFil; }
if ($dateFrom !== '') { $where[] = "r.return_date >= ?"; $params[] = $dateFrom; }
if ($dateTo !== '') { $where[] = "r.return_date <= ?"; $params[] = $dateTo; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT r.*, s.name AS supplier_name, c.code AS currency_code, c.symbol AS currency_symbol
    FROM `{$TR}` r
    LEFT JOIN `{$TSP}` s ON s.id = r.supplier_id
    LEFT JOIN currencies c ON c.id = r.return_currency_id
    {$whereSql}
    ORDER BY r.id DESC";
$st = $pdo->prepare($sql);
$st->execute($params);
$returns = $st->fetchAll();

// نفس استعلام قائمة الموردين المستخدم بصفحة الفواتير بالضبط
$suppliersFilterList = $pdo->query("SELECT id,name FROM `{$TSP}`
    WHERE status='active' AND supplier_type IN ('product','both')
    ORDER BY name")->fetchAll();

$STATUS_MAP = [
    'draft'     => ['label' => 'مسودة', 'cls' => 'bg-secondary-subtle text-secondary'],
    'posted'    => ['label' => 'مؤكد', 'cls' => 'bg-success-subtle text-success'],
    'cancelled' => ['label' => 'ملغى', 'cls' => 'bg-danger-subtle text-danger'],
];

// ⚠ عملة الفرع الأساسية — عبر base_currency_id (FK)، لا نص ثابت بالكود
// (نفس القاعدة الموثّقة بـ blueprint-pattern.md § ٧)
$baseCurSym = '$';
if (!empty($_SESSION['branch_id'])) {
    $bc = $pdo->prepare("SELECT c.symbol FROM branches b JOIN currencies c ON c.id=b.base_currency_id WHERE b.id=?");
    $bc->execute([$_SESSION['branch_id']]);
    $baseCurSym = $bc->fetchColumn() ?: '$';
}

$retStats = [
    'total'   => count($returns),
    'draft'   => count(array_filter($returns, fn($r) => $r['status'] === 'draft')),
    'posted'  => count(array_filter($returns, fn($r) => $r['status'] === 'posted')),
    // ⚠ return_amount أصلاً بعملة الفرع (مشتق من purchase_items.total_price
    // المخزَّن بعملة الفرع — نفس قرار invoice_new.php) — لا حاجة لأي قسمة
    // على exchange_rate هون، كانت تحويل مزدوج غلط (نفس فئة باگ $unitBase
    // يلي صلّحناه بـconfirm_purchase_invoice.php).
    'amount'  => array_sum(array_map(
        fn($r) => $r['status'] !== 'cancelled' ? (float) $r['return_amount'] : 0,
        $returns
    )),
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مرتجعات المشتريات — FATORIZE</title>
    <link rel="icon" type="image/png" href="<?= BASE_PATH ?>/assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/assets/css/layout.css" rel="stylesheet">
    <style>
        .n { font-variant-numeric: tabular-nums }

        /* ⚠ نفس تعريف صفوف تفاصيل المبالغ المستخدم بـindex.php (مودال
        التفاصيل) — لازم لعرض قسم "المبالغ" بمودال تفاصيل المرتجع الجديد. */
        .det-row {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
            font-size: .82rem
        }

        /* ⚠ نفس تعريف بطاقات الإحصائيات المستخدم بـindex.php بالضبط —
        كان ناقص هون بالكامل (معتمد بس على layout.css العام)، وهاد سبب
        فرق الشكل (حجم/تباعد/التفاف النص) بين الصفحتين. */
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

        /* ⚠ نفس التعريفات المحلية الناقصة تماماً من index.php — كانت
        غائبة بالكامل، والصفحة كانت تعتمد فقط على layout.css العام (اللي
        ما بيغطي هالتفاصيل بنفس الدقة). هاد سبب غياب "الكرت الأبيض"
        والتباعد الصحيح لرأس الجدول. */
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
            transition: all .12s;
            text-decoration: none
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

        .act-btn.info-h:hover {
            background: #e0f2fe;
            color: #0891b2;
            border-color: #7dd3fc
        }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php
    require_once __DIR__ . '/../../../includes/sidebar.php';
    require_once __DIR__ . '/../../../includes/breadcrumb.php';
    ?>
    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-arrow-return-right me-1 text-primary"></i>مرتجعات المشتريات</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <?= renderBreadcrumb() ?>
    </header>

    <main class="main-content">
        <div class="content-body">

            <!-- تبويبات -->
            <ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e2e8f0">
                <li class="nav-item">
                    <a class="nav-link fw-600" href="index.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-receipt me-1"></i>فواتير المشتريات
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="suppliers.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-people me-1"></i>إدارة الموردين
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600 active" href="returns.php"
                        style="border:none;border-bottom:2px solid var(--section-color);color:var(--section-color);font-size:.83rem;margin-bottom:-2px">
                        <i class="bi bi-arrow-return-right me-1"></i>مرتجعات المشتريات
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="orders.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-file-earmark-text me-1"></i>أوامر الشراء / طلبات عروض الأسعار
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="reports.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-bar-chart me-1"></i>التقارير
                    </a>
                </li>
            </ul>

            <!-- إحصائيات -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#eff6ff"><i
                                class="bi bi-arrow-return-right text-primary"></i></div>
                        <div>
                            <div class="stat-val"><?= $retStats['total'] ?></div>
                            <div class="stat-lbl">إجمالي المرتجعات</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fef3c7"><i
                                class="bi bi-hourglass text-warning"></i></div>
                        <div>
                            <div class="stat-val"><?= $retStats['draft'] ?></div>
                            <div class="stat-lbl">مسودات</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#f0fdf4"><i
                                class="bi bi-check-circle text-success"></i></div>
                        <div>
                            <div class="stat-val"><?= $retStats['posted'] ?></div>
                            <div class="stat-lbl">مؤكدة</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fee2e2"><i
                                class="bi bi-currency-dollar text-danger"></i></div>
                        <div>
                            <div class="stat-val n"><?= number_format($retStats['amount'], 2) ?> <?= htmlspecialchars($baseCurSym) ?></div>
                            <div class="stat-lbl">إجمالي قيمة المرتجعات</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- الجدول -->
            <div class="tbl-wrap">
                <div class="tbl-hdr">
                    <span style="font-size:.88rem;font-weight:700;color:#1e293b">
                        <i class="bi bi-list-ul me-1 text-primary"></i>سجل مرتجعات المشتريات
                    </span>
                    <div class="d-flex gap-2 ms-auto flex-wrap align-items-center">
                        <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
                            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                                placeholder="رقم المرتجع أو الفاتورة أو المورد..." class="form-control form-control-sm"
                                style="width:200px;border-radius:8px">
                            <select name="status" class="form-select form-select-sm"
                                style="width:120px;border-radius:8px" onchange="this.form.submit()">
                                <option value="">كل الحالات</option>
                                <?php foreach ($STATUS_MAP as $k => $v): ?>
                                            <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>>
                                                <?= $v['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="supplier" class="form-select form-select-sm"
                                style="width:160px;border-radius:8px" onchange="this.form.submit()">
                                <option value="">كل الموردين</option>
                                <?php foreach ($suppliersFilterList as $sp): ?>
                                            <option value="<?= $sp['id'] ?>" <?= $suppFil == $sp['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($sp['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>"
                                class="form-control form-control-sm" style="width:140px;border-radius:8px">
                            <span style="font-size:.8rem;color:#94a3b8">—</span>
                            <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>"
                                class="form-control form-control-sm" style="width:140px;border-radius:8px">
                            <button type="submit" class="btn btn-sm btn-primary" style="border-radius:8px">
                                <i class="bi bi-search me-1"></i>بحث
                            </button>
                            <?php if ($search || $status || $suppFil || $dateFrom || $dateTo): ?>
                                        <a href="returns.php" class="btn btn-sm btn-light" style="border-radius:8px">
                                            <i class="bi bi-x-lg me-1"></i>مسح
                                        </a>
                            <?php endif; ?>
                        </form>
                        <button class="btn btn-sm fw-600" onclick="openNewReturn()"
                            style="border-radius:9px;background:var(--section-color);color:#fff;font-size:.82rem">
                            <i class="bi bi-plus-lg me-1"></i>مرتجع جديد
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="mtbl" id="returnsTbl">
                        <thead>
                            <tr>
                                <th style="color:#1e3a8a">رقم المرتجع</th>
                                <th>التاريخ</th>
                                <th style="color:#16a34a">المورد</th>
                                <th>الفاتورة الأصلية</th>
                                <th>القيمة</th>
                                <th>طريقة التسوية</th>
                                <th>الحالة</th>
                                <th style="text-align:center" data-no-sort>إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($returns)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-5">
                                                <i class="bi bi-arrow-return-right d-block mb-2"
                                                    style="font-size:2rem;opacity:.2"></i>
                                                لا توجد مرتجعات<?= $search ? " تطابق \"{$search}\"" : '' ?>
                                            </td>
                                        </tr>
                            <?php endif; ?>
                            <?php foreach ($returns as $r):
                                $st2 = $STATUS_MAP[$r['status']] ?? $STATUS_MAP['draft'];
                                $payLabels = [
                                    'not_paid' => 'لم تُسوَّ', 'partial' => 'تسوية جزئية', 'paid_full' => 'تسوية كاملة'
                                ];
                                $targetLabels = ['cash' => 'صندوق/بنك', 'supplier' => 'ذمة المورد', 'advance' => 'دفعة مقدمة'];
                                $payDisplay = $payLabels[$r['payment_handling']] ?? '—';
                                if (!empty($r['target_account_type']) && isset($targetLabels[$r['target_account_type']])) {
                                    $payDisplay .= ' — ' . $targetLabels[$r['target_account_type']];
                                }
                                ?>
                                        <tr>
                                            <td class="n fw-600" style="direction:ltr;color:#1e3a8a">
                                                <?= htmlspecialchars($r['return_number'] ?? '—') ?>
                                            </td>
                                            <td class="text-muted"><?= $r['return_date'] ?></td>
                                            <td style="color:#16a34a" class="fw-600">
                                                <?= htmlspecialchars($r['supplier_name'] ?? '—') ?>
                                            </td>
                                            <td class="text-muted" style="font-size:.8rem">
                                                <?= htmlspecialchars($r['purchase_number'] ?? '—') ?>
                                            </td>
                                            <!-- ⚠ return_amount أصلاً بعملة الفرع (راجع تعليق retStats
                                            أعلاه) — لا currency_symbol (عملة الفاتورة). -->
                                            <td class="n fw-600"><?= number_format((float) $r['return_amount'], 2) ?>
                                                <?= htmlspecialchars($baseCurSym) ?></td>
                                            <td style="font-size:.78rem"><?= htmlspecialchars($payDisplay) ?></td>
                                            <td><span class="badge <?= $st2['cls'] ?>"
                                                    style="font-size:.68rem"><?= $st2['label'] ?></span></td>
                                            <td>
                                                <div class="d-flex gap-1 justify-content-center">
                                                    <button class="act-btn info-h" onclick="viewReturn(<?= $r['id'] ?>)"
                                                        title="عرض"><i class="bi bi-eye"></i></button>
                                                    <?php if ($r['status'] === 'draft'): ?>
                                                                <button class="act-btn" onclick="editReturn(<?= $r['id'] ?>)"
                                                                    title="تعديل"><i class="bi bi-pencil"></i></button>
                                                                <button class="act-btn success-h"
                                                                    onclick="confirmReturn(<?= $r['id'] ?>,'<?= htmlspecialchars($r['return_number'], ENT_QUOTES) ?>')"
                                                                    title="تأكيد"><i
                                                                        class="bi bi-check-circle"></i></button>
                                                                <button class="act-btn danger"
                                                                    onclick="deleteReturn(<?= $r['id'] ?>,'<?= htmlspecialchars($r['return_number'], ENT_QUOTES) ?>')"
                                                                    title="حذف"><i class="bi bi-trash"></i></button>
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

    <!-- ══ مودال مرتجع جديد/تعديل ══ -->
    <div class="modal fade" id="retModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl modal-dialog-scrollable" style="max-width:1320px">
            <div class="modal-content" style="border-radius:14px">
                <div class="modal-header">
                    <h6 class="modal-title fw-700" id="retModalTitle"><i
                            class="bi bi-arrow-return-right me-1 text-primary"></i>مرتجع شراء جديد</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="rReturnId" value="">
                    <input type="hidden" id="rPurchaseId" value="">

                    <!-- خطوة 1: اختيار الفاتورة -->
                    <div id="stepFindPurchase">
                        <label class="form-label small fw-600">اختر الفاتورة الأصلية</label>
                        <select id="rPurchaseSelect" class="form-select form-select-sm mb-2" style="border-radius:8px"
                            onchange="onPurchaseSelectChange()">
                            <option value="">— جارٍ التحميل... —</option>
                        </select>
                        <div class="d-flex gap-2 mb-2">
                            <input type="text" id="rSearchPurchase" class="form-control form-control-sm"
                                placeholder="فلترة بالرقم أو اسم المورد..." style="border-radius:8px"
                                oninput="searchPurchaseForReturn()">
                        </div>
                    </div>

                    <!-- خطوة 2: بنود الفاتورة المختارة -->
                    <div id="stepItems" style="display:none">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div style="font-size:.85rem">
                                <span class="fw-700" id="rSelSupplier"></span> —
                                <span class="text-muted" id="rSelPurchaseNo"></span>
                            </div>
                            <button class="btn btn-sm btn-light" style="border-radius:8px"
                                onclick="resetPurchaseSelection()"><i class="bi bi-arrow-right me-1"></i>تغيير
                                الفاتورة</button>
                        </div>
                        <div class="table-responsive">
                            <table class="mtbl" id="rItemsTbl" style="font-size:.76rem;width:100%;table-layout:auto">
                                <thead>
                                    <tr>
                                        <th style="min-width:220px;max-width:260px">المنتج</th>
                                        <th style="min-width:120px">القياس</th>
                                        <th style="color:#16a34a;min-width:90px">اللون</th>
                                        <th class="text-center" style="min-width:70px">المشتراة</th>
                                        <th class="text-center" style="min-width:80px">المتاح للإرجاع</th>
                                        <th style="width:90px;color:#dc2626" class="text-center">عدد الكروبات</th>
                                        <th class="text-center" style="min-width:80px;color:#7c3aed">عدد المنتجات</th>
                                        <th class="text-center" style="min-width:85px;color:#64748b">سعر الشراء الافتراضي</th>
                                        <th class="text-center" style="min-width:70px;color:#dc2626">الخصم</th>
                                        <th class="text-center" style="min-width:85px">سعر الشراء المسجَّل</th>
                                        <th class="text-end" style="min-width:80px">الإجمالي</th>
                                    </tr>
                                </thead>
                                <tbody id="rItemsBody"></tbody>
                            </table>
                        </div>

                        <div class="row g-3 mt-2">
                            <div class="col-md-4">
                                <label class="form-label small fw-600">سبب الإرجاع</label>
                                <input type="text" id="rReason" class="form-control form-control-sm"
                                    style="border-radius:8px" placeholder="بضاعة تالفة، خطأ بالطلب...">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-600">نوعية التسوية المالية</label>
                                <select id="rPayHandling" class="form-select form-select-sm" style="border-radius:8px"
                                    onchange="onRPayHandlingChange()">
                                    <option value="not_paid">لم تُسوَّ بعد</option>
                                    <option value="partial">تسوية جزئية</option>
                                    <option value="paid_full">تسوية كاملة</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-600">ملاحظات</label>
                                <input type="text" id="rNotes" class="form-control form-control-sm"
                                    style="border-radius:8px">
                            </div>
                            <!-- ⚠ يظهر فقط لو نوعية التسوية "جزئية" أو "كاملة" — لا_paid يبقى
                            بلا حساب مستهدف إطلاقاً (المطالبة تضل قائمة بذمة المورد افتراضياً
                            لغاية ما تُسوّى لاحقاً). -->
                            <div class="col-md-4" id="rTargetTypeWrap" style="display:none">
                                <label class="form-label small fw-600">
                                    <i class="bi bi-bullseye me-1"></i>الحساب المستهدف
                                </label>
                                <select id="rTargetAccount" class="form-select form-select-sm" style="border-radius:8px"
                                    onchange="onRTargetTypeChange()">
                                    <option value="">— اختر —</option>
                                    <optgroup label="حساب المورد" id="rTargetSupplierGroup">
                                        <option value="supplier">ذمة المورد</option>
                                        <option value="advance">دفعة مقدمة للمورد</option>
                                    </optgroup>
                                    <optgroup label="صندوق / بنك">
                                        <?php foreach ($cashAccounts as $ca): ?>
                                                    <option value="cash:<?= $ca['id'] ?>"
                                                        data-cur="<?= htmlspecialchars($ca['cur_code'] ?? '') ?>">
                                                        <?= htmlspecialchars($ca['code'] . ' — ' . $ca['name']) ?>
                                                        (<?= htmlspecialchars($ca['cur_sym'] ?? '') ?>)
                                                    </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                </select>
                                <div class="form-text" style="font-size:.7rem" id="rTargetHint">حسابات الصندوق/البنك
                                    تُفلتَر تلقائياً حسب عملة المرتجع</div>
                            </div>
                        </div>

                        <!-- ملخص مبالغ المرتجع — متناسب مع خصم وضريبة الفاتورة الأصلية -->
                        <div class="mt-3" style="background:#f8fafc;border-radius:10px;padding:12px 14px;font-size:.82rem">
                            <div class="row g-2 mb-2 pb-2" style="border-bottom:1px solid #e2e8f0">
                                <div class="col-6 col-md-3">
                                    <small style="color:#64748b">عملة الفاتورة</small>
                                    <div class="fw-600" id="rInvCurLbl">—</div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <small style="color:#64748b">سعر الصرف عند الإنشاء</small>
                                    <div class="fw-600" style="color:#7c3aed" id="rExRateLbl">—</div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <small style="color:#64748b">حالة دفع الفاتورة الأصلية</small>
                                    <div class="fw-600" id="rPayStatusLbl">—</div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <small style="color:#64748b">صافي الفاتورة الأصلية</small>
                                    <div class="fw-600" id="rInvNetLbl">—</div>
                                </div>
                            </div>
                            <div class="row g-2">
                                <div class="col-6 col-md-3">
                                    <small style="color:#64748b">المبلغ الفرعي المرتجع</small>
                                    <div class="fw-600" id="rSubtotalLbl">0.00</div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <small style="color:#64748b">الخصم المتناسب</small>
                                    <div class="fw-600 text-danger" id="rDiscLbl">-0.00</div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <small style="color:#64748b">الضريبة المتناسبة</small>
                                    <div class="fw-600" id="rTaxLbl">+0.00</div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <small style="color:#64748b">صافي المرتجع</small>
                                    <div class="fw-700" style="color:var(--section-color);font-size:.95rem" id="rTotalLine">0.00</div>
                                </div>
                            </div>
                            <div class="form-text mt-1" style="font-size:.7rem">
                                <i class="bi bi-info-circle me-1"></i>الخصم والضريبة محسوبان بنفس نسبتهما على الفاتورة
                                الأصلية، مطبَّقين على قيمة البنود المرتجعة فقط — لا يُعاد حسابهما يدوياً هون.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal"
                        style="border-radius:8px">إلغاء</button>
                    <button type="button" class="btn btn-primary btn-sm" id="btnSaveReturn" style="border-radius:8px"
                        onclick="saveReturn()" disabled>
                        <span id="saveRetTxt"><i class="bi bi-save me-1"></i>حفظ كمسودة</span>
                        <span id="saveRetSpin" class="spinner-border spinner-border-sm" style="display:none"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ مودال تفاصيل المرتجع (قراءة فقط) — مطابق viewModal بـindex.php ══ -->
    <div class="modal fade" id="viewRetModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#0c447c,var(--section-color));border-radius:16px 16px 0 0">
                    <div>
                        <h6 class="modal-title text-white fw-700 mb-0" id="rvTitle">تفاصيل المرتجع</h6>
                        <div id="rvSub" style="font-size:.75rem;color:rgba(255,255,255,.7);margin-top:2px"></div>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <a id="rvEditBtn" href="#" class="btn btn-sm" style="display:none;border-radius:8px;background:rgba(255,255,255,.15);color:#fff;font-size:.76rem;border:1px solid rgba(255,255,255,.3)"
                            onclick="viewRetModalInst.hide();editReturn(_rvCurrentId);return false;">
                            <i class="bi bi-pencil me-1"></i>تعديل
                        </a>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                </div>
                <div class="modal-body px-4 py-3" id="rvBody">
                    <div class="text-center py-4"><span class="spinner-border text-primary"></span></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const retModal = new bootstrap.Modal(document.getElementById('retModal'));
        const viewRetModalInst = new bootstrap.Modal(document.getElementById('viewRetModal'));
        let _rvCurrentId = 0;
        // نسخة JS من STATUS_MAP (PHP) — لعرض شارة الحالة بمودال التفاصيل
        const STATUS_MAP = <?= json_encode($STATUS_MAP) ?>;
        // رمز عملة الفرع الأساسية — كل مبالغ المرتجع (مبلغ فرعي/خصم/ضريبة/
        // صافي/سعر الوحدة بجدول البنود) بعملة الفرع دائماً، لأنها مشتقة من
        // purchase_items.total_price المخزَّن بعملة الفرع (قرار invoice_new.php).
        const BASE_CUR_SYM = <?= json_encode($baseCurSym) ?>;
        let rItems = [];
        let _rSelectedCurrency = 'USD';

        function post(data) {
            const fd = new FormData();
            for (const k in data) fd.append(k, data[k]);
            return fetch('returns.php', { method: 'POST', body: fd }).then(r => r.json());
        }

        function toast(msg, type = 'success') {
            const el = document.createElement('div');
            el.className = `alert alert-${type === 'danger' ? 'danger' : 'success'} position-fixed`;
            el.style.cssText = 'top:20px;left:20px;z-index:9999;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12)';
            el.textContent = msg;
            document.body.appendChild(el);
            setTimeout(() => el.remove(), 3000);
        }

        function openNewReturn() {
            document.getElementById('rReturnId').value = '';
            document.getElementById('rPurchaseId').value = '';
            document.getElementById('rSearchPurchase').value = '';
            document.getElementById('rPurchaseSelect').innerHTML = '<option value="">— جارٍ التحميل... —</option>';
            document.getElementById('stepFindPurchase').style.display = '';
            document.getElementById('stepItems').style.display = 'none';
            document.getElementById('btnSaveReturn').disabled = true;
            document.getElementById('rPayHandling').value = 'not_paid';
            document.getElementById('rTargetTypeWrap').style.display = 'none';
            document.getElementById('rTargetAccount').value = '';
            document.getElementById('retModalTitle').innerHTML = '<i class="bi bi-arrow-return-right me-1 text-primary"></i>مرتجع شراء جديد';
            retModal.show();
            searchPurchaseForReturn(); // تحميل أحدث الفواتير المؤكدة فوراً
        }

        function resetPurchaseSelection() {
            document.getElementById('stepFindPurchase').style.display = '';
            document.getElementById('stepItems').style.display = 'none';
            document.getElementById('btnSaveReturn').disabled = true;
        }

        let _rSearchDebounce = null;
        let _rPurchasesMap = {}; // id -> بيانات الفاتورة الكاملة (لاسترجاعها عند اختيار الكومبوبوكس)

        function searchPurchaseForReturn() {
            clearTimeout(_rSearchDebounce);
            _rSearchDebounce = setTimeout(() => {
                const q = document.getElementById('rSearchPurchase').value.trim();
                post({ _action: 'find_purchase', q }).then(d => {
                    const sel = document.getElementById('rPurchaseSelect');
                    _rPurchasesMap = {};
                    if (!d.ok || !d.purchases.length) {
                        sel.innerHTML = '<option value="">— لا نتائج —</option>';
                        return;
                    }
                    d.purchases.forEach(p => _rPurchasesMap[p.id] = p);
                    sel.innerHTML = '<option value="">— اختر فاتورة —</option>' + d.purchases.map(p =>
                        `<option value="${p.id}">${p.purchase_number} — ${p.supplier_name} (${p.purchase_date})</option>`
                    ).join('');
                });
            }, 250);
        }

        function onPurchaseSelectChange() {
            const id = document.getElementById('rPurchaseSelect').value;
            if (!id || !_rPurchasesMap[id]) return;
            selectPurchase(_rPurchasesMap[id]);
        }

        let _rSelectedPurchase = null;

        function selectPurchase(p) {
            document.getElementById('rPurchaseId').value = p.id;
            document.getElementById('rSelSupplier').textContent = p.supplier_name;
            document.getElementById('rSelPurchaseNo').textContent = p.purchase_number;
            document.getElementById('stepFindPurchase').style.display = 'none';
            document.getElementById('stepItems').style.display = '';
            _rSelectedCurrency = p.currency_code || 'USD';
            _rSelectedPurchase = p;
            onRPayHandlingChange();

            const PAY_LABELS = { paid: 'مدفوعة بالكامل', partial: 'مدفوعة جزئياً', pending: 'غير مدفوعة' };
            document.getElementById('rInvCurLbl').textContent = p.currency_code || '—';
            document.getElementById('rExRateLbl').textContent = p.base_currency_code && p.exchange_rate
                ? `1 ${p.base_currency_code} = ${Number(p.exchange_rate).toFixed(4)} ${p.currency_code}` : '—';
            document.getElementById('rPayStatusLbl').textContent = PAY_LABELS[p.payment_status] || p.payment_status || '—';
            document.getElementById('rInvNetLbl').textContent = p.final_amount
                ? Number(p.final_amount).toFixed(2) + ' ' + BASE_CUR_SYM : '—';

            // ⚠ الاسترداد النقدي (خيارات الصندوق/البنك داخل القائمة
            // الموحَّدة) منطقي بس لو فيه فعلاً مبلغ مسدَّد على الفاتورة
            // الأصلية — مافيش شي نرجعه كاش لو ما انسددت أصلاً. نعطّل كل
            // خيارات الصندوق/البنك سوا (مو خيار واحد — صاروا متعددين
            // بعد الدمج بقائمة موحّدة).
            const hadPayment = p.payment_status === 'paid' || p.payment_status === 'partial';
            const cashOpts = document.querySelectorAll('#rTargetAccount option[value^="cash:"]');
            let currentIsCash = false;
            cashOpts.forEach(opt => {
                opt.disabled = !hadPayment;
                if (opt.value === document.getElementById('rTargetAccount').value) currentIsCash = true;
            });
            document.getElementById('rTargetHint').textContent = hadPayment
                ? 'حسابات الصندوق/البنك تُفلتَر تلقائياً حسب عملة المرتجع'
                : 'خيارات الصندوق/البنك معطَّلة — الفاتورة الأصلية غير مدفوعة أصلاً';
            if (!hadPayment && currentIsCash) {
                document.getElementById('rTargetAccount').value = '';
                onRTargetTypeChange();
            }

            post({ _action: 'get_purchase_items', purchase_id: p.id }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                rItems = d.items;
                renderItemsTable();
            });
        }

        // ⚠ حقل "الحساب المستهدف" يظهر بس لو نوعية التسوية "جزئية" أو
        // "كاملة" — not_paid يبقى بدون حساب مستهدف (المطالبة تضل قائمة
        // بذمة المورد افتراضياً لغاية ما تُسوّى لاحقاً — راجع تعليق
        // confirm_purchase_return.php لتفصيل السلوك الافتراضي).
        function onRPayHandlingChange() {
            const val = document.getElementById('rPayHandling').value;
            const show = val === 'partial' || val === 'paid_full';
            document.getElementById('rTargetTypeWrap').style.display = show ? '' : 'none';
            if (!show) {
                document.getElementById('rTargetAccount').value = '';
            }
        }

        // ⚠ فلترة خيارات الصندوق/البنك داخل القائمة الموحَّدة حسب عملة
        // الفاتورة الأصلية تلقائياً — خيارا "ذمة المورد"/"دفعة مقدمة"
        // يضلوا ظاهرين دايماً (حساب المورد نفسه، مو صندوق بعملة محدَّدة).
        function onRTargetTypeChange() {
            const sel = document.getElementById('rTargetAccount');
            let stillValid = !sel.value || !sel.value.startsWith('cash:');
            Array.from(sel.options).forEach(opt => {
                if (!opt.value || !opt.value.startsWith('cash:')) return;
                const show = opt.dataset.cur === _rSelectedCurrency;
                opt.style.display = show ? '' : 'none';
                if (show && opt.value === sel.value) stillValid = true;
            });
            if (!stillValid) sel.value = '';
        }

        // ⚠ التجميع بمستوى (منتج+سعر) — يدمج كل الألوان والمقاسات بنفس
        // الكروب، وكمية إرجاع واحدة تنطبق على كل الأعضاء دفعة وحدة (نفس
        // منطق invoice_new.php بالضبط: "عدد الكروبات" ينطبق كاملاً على
        // كل متغيّر بمفرده، لا يتوزّع). قرار نهائي: نرجّع الكروب كامل،
        // مو مقاس/قطعة بمفردها.
        let rGroups = [];

        function buildGroups() {
            const map = {};
            rItems.forEach((it, idx) => {
                // ⚠ تسليم من المحادثة العامة (handoff_group_key_purchases.md):
                // group_key الحقيقي المخزَّن على product_sizes، بدل السعر
                // الافتراضي المُعاد بناؤه — نفس إصلاح index.php بالضبط.
                // احتياط بسيط لبيانات قديمة بلا group_key.
                const groupKey = it.group_key || (it.default_price ?? it.unit_price);
                const key = (it.product_id || it.product_name) + '_' + groupKey;
                if (!map[key]) map[key] = {
                    name: it.product_name || ('بند #' + it.purchase_item_id),
                    model: it.model_number || '', sizes: [], colors: [], ageType: it.age_type || '',
                    unit_price: it.unit_price, default_price: it.default_price ?? it.unit_price,
                    purchased: 0, available: Infinity,
                    memberIdx: [], _colorQty: {}
                };
                const g = map[key];
                g.memberIdx.push(idx);
                // "المشتراة" تُجمع مرة وحدة لكل لون فريد (كل مقاسات نفس
                // اللون مشتركة بنفس رقم الكمية أصلاً) — مو لكل صف/مقاس
                // مباشرة، وإلا بيصير عدّ مضاعف.
                const colorKey = it.color || '_none';
                g._colorQty[colorKey] = Number(it.quantity) || 0;
                g.purchased = Object.values(g._colorQty).reduce((s, v) => s + v, 0);
                // المتاح للكروب = أقل قيمة متاحة بين كل الأعضاء (ما فينا
                // نطبّق كمية إرجاع أكبر من أضيق عضو بالكروب)
                g.available = Math.min(g.available, it.returnable_qty);
                if (it.size && !g.sizes.includes(it.size)) g.sizes.push(it.size);
                if (it.color && !g.colors.includes(it.color)) g.colors.push(it.color);
            });
            rGroups = Object.values(map).map(g => ({ ...g, packet_qty: g.sizes.length || 1 }));
        }

        // عرض القياس كنطاق مختصر: "{نوع العمر} {أصغر}-{أكبر}"
        function formatSizeRange(g) {
            if (!g.sizes.length) return '—';
            const nums = g.sizes.map(s => parseFloat(s)).filter(n => !isNaN(n));
            if (!nums.length) return g.sizes.join(' · ') + (g.ageType ? ' ' + g.ageType : '');
            const min = Math.min(...nums), max = Math.max(...nums);
            const rangeTxt = min === max ? `${min}` : `${min}-${max}`;
            return g.ageType ? `${g.ageType} ${rangeTxt}` : rangeTxt;
        }

        function renderItemsTable() {
            buildGroups();
            const tbody = document.getElementById('rItemsBody');
            tbody.innerHTML = rGroups.map((g, i) => `
                <tr>
                    <td style="white-space:normal;line-height:1.4;padding-top:8px;padding-bottom:8px">
                        <div class="fw-600">${g.name}</div>
                        ${g.model ? `<div class="text-muted" style="font-size:.7rem;margin-top:2px">${g.model}</div>` : ''}
                    </td>
                    <td style="font-size:.72rem;font-weight:600;color:#334155;white-space:normal">${formatSizeRange(g)}</td>
                    <td style="color:#16a34a" class="fw-600">${g.colors.join(' · ') || '—'}</td>
                    <td class="n text-center">${g.purchased}</td>
                    <td class="n text-center ${g.available <= 0 ? 'text-muted' : 'text-success fw-600'}">${g.available}</td>
                    <td style="background:#fef2f2;min-width:80px">
                        <input type="number" class="form-control form-control-sm" id="gqty${i}" min="0"
                        max="${g.available}" step="1" value="0" ${g.available <= 0 ? 'disabled' : ''}
                        oninput="onGroupQtyChange(${i})" style="border-radius:6px;border-color:#fecaca;text-align:center;font-size:.78rem"></td>
                    <td class="n text-center" style="color:#7c3aed" id="gPieces${i}">0</td>
                    <td class="n text-center" style="color:#64748b">${Number(g.default_price).toFixed(2)} ${BASE_CUR_SYM}</td>
                    <td class="n text-center" style="color:#dc2626">${(Number(g.default_price) - Number(g.unit_price)) > 0.001 ? '-' + (Number(g.default_price) - Number(g.unit_price)).toFixed(2) + ' ' + BASE_CUR_SYM : '—'}</td>
                    <td class="n text-center fw-600">${Number(g.unit_price).toFixed(2)} ${BASE_CUR_SYM}</td>
                    <td class="n text-end fw-600" id="gLineTotal${i}">0.00 ${BASE_CUR_SYM}</td>
                </tr>`).join('');
            updateTotal();
        }

        function onGroupQtyChange(i) {
            let qty = parseFloat(document.getElementById(`gqty${i}`).value) || 0;
            const max = rGroups[i].available;
            if (qty > max) { qty = max; document.getElementById(`gqty${i}`).value = max; }
            // ⚠ عدد المنتجات = عدد الكروبات × عدد القطع بالباكيت — نفس
            // آلية invoice_new.php بالضبط. المبلغ الإجمالي لازم يُحسب
            // على عدد المنتجات الحقيقي (كل القطع بكل المقاسات المدموجة
            // بالكروب)، لا عدد الكروبات وحده — وإلا "١" كروب بكروب فيه
            // ٤ مقاسات كان يُحسب بسعر قطعة وحدة بس بدل ٤ قطع.
            const pieces = qty * rGroups[i].packet_qty;
            document.getElementById(`gPieces${i}`).textContent = pieces.toFixed(0);
            const lineTotal = pieces * rGroups[i].unit_price;
            document.getElementById(`gLineTotal${i}`).textContent = lineTotal.toFixed(2) + ' ' + BASE_CUR_SYM;
            updateTotal();
        }

        function updateTotal() {
            let subtotal = 0, anySelected = false;
            rGroups.forEach((g, i) => {
                const qty = parseFloat(document.getElementById(`gqty${i}`)?.value) || 0;
                if (qty > 0) {
                    anySelected = true;
                    // ⚠ نفس تصحيح onGroupQtyChange — عدد المنتجات
                    // (كروبات×باكيت)، لا عدد الكروبات وحده.
                    subtotal += (qty * g.packet_qty) * g.unit_price;
                }
            });

            // ⚠ الخصم والضريبة يُحسبان بنفس النسبة المئوية المطبَّقة على
            // الفاتورة الأصلية كاملة — لا قيمة خام منفصلة. لو الفاتورة
            // الأصلية كانت فيها خصم 5% وضريبة 10%، نفس النسبتين تنطبقان
            // هون على قيمة البنود المرتجعة فقط (نفس مبدأ purchase-invoice-blueprint.md
            // بخصوص التحقق السيادي بالسيرفر — هالحساب هون عرض فقط، الحفظ
            // الفعلي يعيد نفس الحساب بالسيرفر مرة ثانية للتأكد).
            let discAmt = 0, taxAmt = 0;
            const p = _rSelectedPurchase;
            if (p && parseFloat(p.total_amount) > 0) {
                const discRatio = (parseFloat(p.inv_discount_amount) || 0) / parseFloat(p.total_amount);
                const taxBaseInv = parseFloat(p.total_amount) - (parseFloat(p.inv_discount_amount) || 0);
                const taxRatio = taxBaseInv > 0 ? (parseFloat(p.inv_tax_amount) || 0) / taxBaseInv : 0;
                discAmt = subtotal * discRatio;
                taxAmt = (subtotal - discAmt) * taxRatio;
            }
            const net = subtotal - discAmt + taxAmt;

            document.getElementById('rSubtotalLbl').textContent = subtotal.toFixed(2) + ' ' + BASE_CUR_SYM;
            document.getElementById('rDiscLbl').textContent = '-' + discAmt.toFixed(2) + ' ' + BASE_CUR_SYM;
            document.getElementById('rTaxLbl').textContent = '+' + taxAmt.toFixed(2) + ' ' + BASE_CUR_SYM;
            document.getElementById('rTotalLine').textContent = net.toFixed(2) + ' ' + BASE_CUR_SYM;
            document.getElementById('btnSaveReturn').disabled = !anySelected;
        }

        function saveReturn() {
            const lines = [];
            rGroups.forEach((g, i) => {
                const qty = parseFloat(document.getElementById(`gqty${i}`)?.value) || 0;
                if (qty > 0) {
                    // ⚠ نفس كمية الكروب تنطبق على كل عضو (كل لون ومقاس)
                    // دفعة وحدة — مطابق تماماً لمنطق invoice_new.php.
                    g.memberIdx.forEach(idx => {
                        const it = rItems[idx];
                        lines.push({
                            purchase_item_id: it.purchase_item_id,
                            quantity_returned: qty,
                            display_name: it.display_name
                        });
                    });
                }
            });
            if (!lines.length) { toast('اختر بند واحد على الأقل', 'danger'); return; }

            // ⚠ تفكيك القائمة الموحَّدة: "cash:123" → target_account_type='cash'
            // + refund_account_id=123؛ "supplier"/"advance" → نفس القيمة
            // مباشرة بلا حساب فرعي (الحساب ضمني، حساب المورد نفسه).
            const rawTarget = document.getElementById('rTargetAccount').value;
            let targetType = rawTarget, refundAccId = '';
            if (rawTarget.startsWith('cash:')) {
                targetType = 'cash';
                refundAccId = rawTarget.split(':')[1];
            }

            document.getElementById('saveRetTxt').style.opacity = '0';
            document.getElementById('saveRetSpin').style.display = '';
            document.getElementById('btnSaveReturn').disabled = true;

            post({
                _action: 'save_return',
                return_id: document.getElementById('rReturnId').value,
                purchase_id: document.getElementById('rPurchaseId').value,
                return_reason: document.getElementById('rReason').value,
                payment_handling: document.getElementById('rPayHandling').value,
                target_account_type: targetType,
                refund_account_id: refundAccId,
                notes: document.getElementById('rNotes').value,
                lines: JSON.stringify(lines)
            }).then(d => {
                document.getElementById('saveRetTxt').style.opacity = '1';
                document.getElementById('saveRetSpin').style.display = 'none';
                document.getElementById('btnSaveReturn').disabled = false;
                if (d.ok) { toast(d.msg); retModal.hide(); setTimeout(() => location.reload(), 700); }
                else toast(d.msg, 'danger');
            });
        }

        function viewReturn(id) {
            _rvCurrentId = id;
            document.getElementById('rvTitle').textContent = 'جارٍ التحميل...';
            document.getElementById('rvSub').textContent = '';
            document.getElementById('rvEditBtn').style.display = 'none';
            document.getElementById('rvBody').innerHTML = '<div class="text-center py-4"><span class="spinner-border text-primary"></span></div>';
            viewRetModalInst.show();
            post({ _action: 'get_return', id }).then(d => {
                if (!d.ok) { document.getElementById('rvBody').innerHTML = `<div class="text-danger p-3">${d.msg}</div>`; return; }
                const r = d.return, items = d.items || [], jes = d.journal_entries || [];
                const st = STATUS_MAP[r.status] || STATUS_MAP['draft'];
                const fmt = n => new Intl.NumberFormat('en').format(parseFloat(n || 0).toFixed(2));

                document.getElementById('rvTitle').textContent = 'مرتجع: ' + r.return_number;
                document.getElementById('rvSub').textContent = r.supplier_name_live || '';
                if (r.status === 'draft') {
                    document.getElementById('rvEditBtn').style.display = '';
                }

                // ⚠ تجميع البنود بنفس مبدأ viewInvoice — بمفتاح (منتج ×
                // group_key الحقيقي)، مع دمج الألوان/المقاسات بصف واحد.
                // ⚠ تسليم من المحادثة العامة (handoff_group_key_purchases.md):
                // كان المفتاح هون أصلاً السعر الصافي مباشرة (أضعف حتى من
                // السعر الافتراضي المُعاد بناؤه المستخدم بمكان تاني) —
                // احتياط بسيط لبيانات قديمة بلا group_key. default_price
                // مُعاد بناؤه بجانب السيرفر — للعرض المعلوماتي فقط، البند
                // بالمرتجع مسجَّل بسعره الصافي التاريخي مباشرة، بلا أي
                // خصم إفرادي جديد يُطبَّق وقت الإرجاع نفسه.
                // ⚠ عدد الكروبات ≠ عدد المنتجات: quantity_returned لكل
                // صف مقاس بمفرده = عدد الكروبات (مكرَّر على كل مقاسات
                // نفس اللون، نفس اتفاقية purchase_items) — لازم يُجمع
                // مرة وحدة لكل لون فريد لناتج "عدد الكروبات" الصحيح،
                // بينما "عدد المنتجات" = المجموع الفعلي عبر كل الصفوف
                // (يشمل الضرب الضمني بعدد المقاسات). نفس المبدأ بالضبط
                // المستخدم بمودال الإنشاء (buildGroups/_colorQty).
                const grpMap = {};
                items.forEach(it => {
                    const groupKey = it.group_key || parseFloat(it.unit_price).toFixed(4);
                    const k = `${it.product_id || it.product_name}_${groupKey}`;
                    if (!grpMap[k]) grpMap[k] = {
                        product_name: it.product_name || '—', model_number: it.model_number || '',
                        unit_price: parseFloat(it.unit_price), default_price: parseFloat(it.default_price ?? it.unit_price),
                        pieces: 0, total: 0,
                        sizes: [], colors: [], ageType: it.age_type || '', _colorQty: {}
                    };
                    const colorKey = it.color || '_none';
                    grpMap[k]._colorQty[colorKey] = parseFloat(it.quantity_returned);
                    grpMap[k].pieces += parseFloat(it.quantity_returned);
                    grpMap[k].total += parseFloat(it.total_price);
                    if (it.size && !grpMap[k].sizes.includes(it.size)) grpMap[k].sizes.push(it.size);
                    if (it.color && !grpMap[k].colors.includes(it.color)) grpMap[k].colors.push(it.color);
                });
                const itemsHtml = Object.values(grpMap).map(g => {
                    const groups = Object.values(g._colorQty).reduce((s, v) => s + v, 0);
                    return `<tr>
                <td>
                    <div class="fw-600" style="font-size:.8rem">${g.product_name}</div>
                    <div style="font-size:.7rem;color:#94a3b8" dir="ltr">${g.model_number}</div>
                </td>
                <td class="text-center" style="font-size:.78rem;font-weight:600">${formatSizeRange(g)}</td>
                <td class="text-center" style="font-size:.78rem">${g.colors.join(' · ') || '—'}</td>
                <td class="n text-center fw-600" style="color:#dc2626">${groups.toFixed(0)}</td>
                <td class="n text-center" style="color:#7c3aed">${g.pieces.toFixed(0)}</td>
                <td class="n text-center" style="color:#64748b">${g.default_price.toFixed(2)} ${BASE_CUR_SYM}</td>
                <td class="n text-center" style="color:#dc2626">${(g.default_price - g.unit_price) > 0.001 ? '-' + (g.default_price - g.unit_price).toFixed(2) + ' ' + BASE_CUR_SYM : '—'}</td>
                <td class="n text-center">${g.unit_price.toFixed(2)} ${BASE_CUR_SYM}</td>
                <td class="n text-end fw-600">${g.total.toFixed(2)} ${BASE_CUR_SYM}</td>
            </tr>`;
                }).join('');

                // ⚠ القيد المحاسبي المرتبط — قيد واحد بس لكل مرتجع
                // (بعكس فاتورة الشراء يلي فيها لغاية ٤ قيود منفصلة)،
                // راجع confirm_purchase_return.php.
                const TARGET_LABELS = { cash: 'صندوق/بنك', supplier: 'ذمة المورد', advance: 'دفعة مقدمة' };
                const je = jes.find(j => j.reference_type === 'purchase_return');
                const jeHtml = je ? `
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 14px;margin-top:12px">
            <div style="font-size:.82rem;font-weight:700;color:#065f46;margin-bottom:8px">
                <i class="bi bi-check-circle-fill me-1"></i>تفاصيل التأكيد — بتاريخ ${je.entry_date}
            </div>
            <div class="row g-2">
                <div class="col-6 col-md-4">
                    <small style="color:#64748b">رقم القيد</small>
                    <div class="fw-600" dir="ltr">${je.entry_number}</div>
                </div>
                <div class="col-6 col-md-4">
                    <small style="color:#64748b">الوجهة المحاسبية</small>
                    <div class="fw-600">${TARGET_LABELS[r.target_account_type] || 'ذمة المورد (افتراضي)'}</div>
                </div>
                <div class="col-6 col-md-4">
                    <small style="color:#64748b">قيمة القيد</small>
                    <div class="fw-600">${fmt(je.total_debit)} ${je.je_currency || BASE_CUR_SYM}</div>
                </div>
            </div>
        </div>` : (r.status === 'posted' ? `<div class="mt-2 text-warning small"><i class="bi bi-exclamation-triangle me-1"></i>مرتجع مؤكد بلا قيد محاسبي مرتبط — راجع يدوياً.</div>` : '');

                document.getElementById('rvBody').innerHTML = `
        <div class="row g-2 mb-3">
            <div class="col-md-3"><small style="color:#64748b">المورد</small><div class="fw-600">${r.supplier_name_live || '—'}</div></div>
            <div class="col-md-2"><small style="color:#64748b">فاتورة الشراء الأصلية</small><div dir="ltr">${r.purchase_number || '—'}</div></div>
            <div class="col-md-2"><small style="color:#64748b">تاريخ المرتجع</small><div>${r.return_date || '—'}</div></div>
            <div class="col-md-2"><small style="color:#64748b">المستودع</small><div>${r.warehouse_name || '—'}</div></div>
            <div class="col-md-1"><small style="color:#64748b">الحالة</small><div><span class="badge ${st.cls}">${st.label}</span></div></div>
            <div class="col-md-2"><small style="color:#64748b">سبب الإرجاع</small><div style="font-size:.8rem">${r.return_reason || '—'}</div></div>
        </div>
        <div class="table-responsive mb-3">
        <table class="mtbl" id="viewRetItemsTbl" style="font-size:.78rem">
            <thead><tr style="background:#f8fafc">
                <th>بيان المنتج / الموديل</th>
                <th class="text-center">القياس</th>
                <th class="text-center">الألوان</th>
                <th class="text-center" style="color:#dc2626">عدد الكروبات</th>
                <th class="text-center" style="color:#7c3aed">عدد المنتجات</th>
                <th class="text-center" style="color:#64748b">سعر الشراء الافتراضي (${BASE_CUR_SYM})</th>
                <th class="text-center" style="color:#dc2626">الخصم (${BASE_CUR_SYM})</th>
                <th class="text-center">سعر الشراء المسجَّل (${BASE_CUR_SYM})</th>
                <th class="text-end">الإجمالي (${BASE_CUR_SYM})</th>
            </tr></thead>
            <tbody>${itemsHtml || '<tr><td colspan="9" class="text-center text-muted py-3">لا توجد بنود</td></tr>'}</tbody>
        </table>
        </div>
        <div class="row justify-content-end">
          <div class="col-md-6">
            <div style="background:#f8fafc;border-radius:10px;padding:10px 14px">
                <div class="det-row"><span style="color:#64748b">المبلغ الفرعي المرتجع</span><span class="n">${fmt(r.total_amount)} ${BASE_CUR_SYM}</span></div>
                ${parseFloat(r.discount_amount) > 0 ? `<div class="det-row"><span style="color:#64748b">الخصم المتناسب</span><span class="n text-danger">-${fmt(r.discount_amount)} ${BASE_CUR_SYM}</span></div>` : ''}
                ${parseFloat(r.tax_amount) > 0 ? `<div class="det-row"><span style="color:#64748b">الضريبة المتناسبة</span><span class="n">+${fmt(r.tax_amount)} ${BASE_CUR_SYM}</span></div>` : ''}
                <div class="det-row" style="font-weight:700;font-size:.9rem;border-top:1px solid #e2e8f0;padding-top:6px">
                    <span>صافي المرتجع</span><span class="n">${fmt(r.return_amount)} ${BASE_CUR_SYM}</span>
                </div>
            </div>
          </div>
        </div>
        ${jeHtml}
        ${r.notes ? `<div style="background:#f8fafc;border-radius:8px;padding:8px 12px;margin-top:10px;font-size:.78rem;color:#64748b">${r.notes}</div>` : ''}
        ${r.status === 'draft' ? `<div style="margin-top:12px">
            <button type="button" class="btn btn-sm fw-600" style="border-radius:8px;border:1px solid var(--section-color);color:var(--section-color);width:100%;font-size:.8rem;padding:.5rem"
                onclick="viewRetModalInst.hide();confirmReturn(${r.id}, '${r.return_number}')">
                <i class="bi bi-check-circle me-1"></i>تأكيد المرتجع
            </button>
        </div>` : ''}`;
                makeSortable(document.getElementById('viewRetItemsTbl'));
            });
        }

        function editReturn(id, readOnly = false) {
            post({ _action: 'get_return', id }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                document.getElementById('rReturnId').value = id;
                document.getElementById('rPurchaseId').value = d.return.purchase_id;
                document.getElementById('rSelSupplier').textContent = d.return.supplier_name_live || '';
                document.getElementById('rSelPurchaseNo').textContent = d.return.purchase_number;
                document.getElementById('rReason').value = d.return.return_reason || '';
                document.getElementById('rPayHandling').value = d.return.payment_handling || 'not_paid';
                document.getElementById('rNotes').value = d.return.notes || '';
                _rSelectedCurrency = d.return.currency_code || 'USD';
                _rSelectedPurchase = {
                    total_amount: d.return.total_amount, inv_discount_amount: d.return.inv_discount_amount,
                    inv_tax_amount: d.return.inv_tax_amount, final_amount: d.return.final_amount,
                    payment_status: d.return.payment_status, currency_code: d.return.currency_code,
                    currency_symbol: d.return.currency_symbol, base_currency_code: d.return.base_currency_code,
                    exchange_rate: d.return.exchange_rate
                };
                const PAY_LABELS2 = { paid: 'مدفوعة بالكامل', partial: 'مدفوعة جزئياً', pending: 'غير مدفوعة' };
                document.getElementById('rInvCurLbl').textContent = d.return.currency_code || '—';
                document.getElementById('rExRateLbl').textContent = d.return.base_currency_code && d.return.exchange_rate
                    ? `1 ${d.return.base_currency_code} = ${Number(d.return.exchange_rate).toFixed(4)} ${d.return.currency_code}` : '—';
                document.getElementById('rPayStatusLbl').textContent = PAY_LABELS2[d.return.payment_status] || d.return.payment_status || '—';
                document.getElementById('rInvNetLbl').textContent = d.return.inv_final_amount
                    ? Number(d.return.inv_final_amount).toFixed(2) + ' ' + BASE_CUR_SYM : '—';
                onRPayHandlingChange();
                // ⚠ إعادة بناء قيمة القائمة الموحَّدة من الحقلين المحفوظين
                // منفصلين — عكس تفكيك saveReturn() بالضبط.
                if (d.return.target_account_type === 'cash' && d.return.refund_account_id) {
                    document.getElementById('rTargetAccount').value = 'cash:' + d.return.refund_account_id;
                } else if (d.return.target_account_type) {
                    document.getElementById('rTargetAccount').value = d.return.target_account_type;
                }
                onRTargetTypeChange();
                document.getElementById('stepFindPurchase').style.display = 'none';
                document.getElementById('stepItems').style.display = '';
                document.getElementById('retModalTitle').innerHTML =
                    (readOnly ? '<i class="bi bi-eye me-1 text-primary"></i>عرض مرتجع: ' : '<i class="bi bi-pencil me-1 text-primary"></i>تعديل مرتجع: ') + d.return.return_number;

                post({ _action: 'get_purchase_items', purchase_id: d.return.purchase_id }).then(d2 => {
                    rItems = d2.items;
                    renderItemsTable(); // يبني rGroups تلقائياً
                    // إعادة تعليم الكروبات المحفوظة أصلاً بهالمرتجع — نلاقي
                    // أي كروب فيه متغيّر واحد ع الأقل من البنود المحفوظة،
                    // ونعبّي كميته من أول متغيّر عضو فيه محفوظ (كلهم
                    // بالمفروض نفس الكمية، لأنها كانت اتحفظت من نفس الكروب).
                    rGroups.forEach((g, gi) => {
                        const savedLine = d.items.find(sl =>
                            g.memberIdx.some(idx => rItems[idx].purchase_item_id == sl.purchase_item_id));
                        if (savedLine) {
                            document.getElementById(`gqty${gi}`).value = savedLine.quantity_returned;
                            onGroupQtyChange(gi);
                        }
                    });
                    if (readOnly) {
                        document.querySelectorAll('#stepItems input, #stepItems select').forEach(el => el.disabled = true);
                        document.getElementById('btnSaveReturn').style.display = 'none';
                    } else {
                        document.getElementById('btnSaveReturn').style.display = '';
                    }
                });
                retModal.show();
            });
        }

        function confirmReturn(id, no) {
            if (!confirm(`تأكيد المرتجع "${no}"؟\n\nهاد سيعكس المخزون ويفتح قيد محاسبي — لا يمكن التراجع عنه لاحقاً إلا بمستند إلغاء منفصل.`)) return;
            const fd = new FormData();
            fd.append('_action', 'confirm_return');
            fd.append('return_id', id);
            fetch('../../api/confirm_purchase_return.php', { method: 'POST', body: fd })
                .then(async r => {
                    // ⚠ نفحص حالة HTTP ونطبع النص الخام قبل محاولة تحليله
                    // كـJSON — حتى نعرف بالضبط شو رجع (404؟ صفحة خطأ PHP
                    // بيضاء؟) بدل ما نبتلع كل الأخطاء برسالة عامة واحدة.
                    const text = await r.text();
                    if (!r.ok) {
                        console.error('HTTP', r.status, text);
                        throw new Error(`HTTP ${r.status} — الملف موجود؟ راجع مسار confirm_purchase_return.php`);
                    }
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('استجابة غير صالحة (مو JSON):', text);
                        throw new Error('الخادم رجّع استجابة غير متوقعة — افتح Console (F12) لمعرفة التفاصيل');
                    }
                })
                .then(d => {
                    if (d.ok) { toast('✅ ' + d.msg); setTimeout(() => location.reload(), 800); }
                    else toast(d.msg, 'danger');
                })
                .catch(err => toast(err.message || 'تعذّر الاتصال بخادم التأكيد', 'danger'));
        }

        function deleteReturn(id, no) {
            if (!confirm(`حذف المرتجع "${no}"؟ (مسودة فقط، بدون أي تأثير على المخزون)`)) return;
            post({ _action: 'delete_return', id }).then(d => {
                if (d.ok) { toast(d.msg); setTimeout(() => location.reload(), 600); }
                else toast(d.msg, 'danger');
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

        makeSortable(document.getElementById('returnsTbl'));

        // ⚠ اختصار قادم من زر "إنشاء مرتجع" بصفحة فواتير المشتريات —
        // يفتح المودال ويختار الفاتورة تلقائياً، بدل ما يرجع المستخدم
        // يدوّر عليها يدوياً من الكومبوبوكس.
        <?php if (!empty($_GET['open_purchase_id'])): ?>
            (function () {
                const targetId = <?= (int) $_GET['open_purchase_id'] ?>;
                openNewReturn();
                const tryFind = () => {
                    if (_rPurchasesMap[targetId]) {
                        document.getElementById('rPurchaseSelect').value = targetId;
                        onPurchaseSelectChange();
                    } else {
                        // الفاتورة المطلوبة مش ضمن أحدث 15 (نادر) — ابحث عنها تحديداً بالرقم
                        post({ _action: 'find_purchase', q: '<?= addslashes($_GET['open_purchase_id']) ?>' });
                        setTimeout(() => {
                            if (_rPurchasesMap[targetId]) {
                                document.getElementById('rPurchaseSelect').value = targetId;
                                onPurchaseSelectChange();
                            }
                        }, 400);
                    }
                };
                setTimeout(tryFind, 350); // ننتظر تحميل القائمة الأولي (searchPurchaseForReturn) لحد ما يخلص
            })();
        <?php endif; ?>
    </script>
</body>

</html>
