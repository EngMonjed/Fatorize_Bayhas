<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
/**
 * internal_orders.php — الطلبات الداخلية بين الفروع
 *retail1/modules/inventory/internal_orders.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('inventory.internal_orders', 'view');

$branchName = $_SESSION['branch_name'] ?? 'الفرع';
$branchId = (int) ($_SESSION['branch_id'] ?? 0);
$TS = $_SESSION['table_suffix'];

// جلب معلومات الفرع الحالي ومعمله المرتبط
$brStmt = $pdo->prepare("SELECT b.*, f.id AS factory_id, f.name AS factory_name,
    f.table_suffix AS factory_suffix
    FROM branches b
    LEFT JOIN branches f ON f.id = b.factory_branch_id
    WHERE b.id = ?");
$brStmt->execute([$branchId]);
$branch = $brStmt->fetch();

$branchType = $branch['branch_type'] ?? 'retail';   // retail | factory
$isFactory = ($branchType === 'factory');              // هل أنا معمل؟
$isRetail = ($branchType === 'retail');               // هل أنا فرع بيع؟
$factoryId = $branch['factory_id'] ?? null;
$factoryName = $branch['factory_name'] ?? 'المعمل';
$factorySuffix = $branch['factory_suffix'] ?? null;

// فرع البيع → يرى طلباته الصادرة فقط
// فرع التصنيع → يرى الطلبات الواردة عليه فقط + يستجيب لها

// جلب كل الفروع للعرض
$allBranches = $pdo->query("SELECT id,name,branch_type,table_suffix FROM branches WHERE status='active' ORDER BY sort_order")->fetchAll();

// إنشاء الجداول
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `internal_orders` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `order_number` varchar(50) NOT NULL,
        `from_branch_id` int(11) NOT NULL,
        `to_branch_id` int(11) NOT NULL,
        `order_date` date NOT NULL,
        `required_date` date DEFAULT NULL,
        `currency` varchar(3) NOT NULL DEFAULT 'USD',
        `exchange_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
        `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
        `notes` text DEFAULT NULL,
        `status` enum('draft','sent','reviewing','approved','partially_approved','rejected','converted','cancelled') NOT NULL DEFAULT 'draft',
        `purchase_id` int(11) DEFAULT NULL,
        `responded_by` int(11) DEFAULT NULL,
        `responded_at` datetime DEFAULT NULL,
        `response_notes` text DEFAULT NULL,
        `created_by` int(11) NOT NULL,
        `updated_by` int(11) DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
        `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_order_number` (`order_number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `internal_order_items` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `order_id` int(11) NOT NULL,
        `product_id` int(11) DEFAULT NULL,
        `variant_id` int(11) DEFAULT NULL,
        `product_name` varchar(255) NOT NULL,
        `model_number` varchar(50) DEFAULT NULL,
        `size` varchar(20) DEFAULT NULL,
        `color` varchar(50) DEFAULT NULL,
        `barcode` varchar(100) DEFAULT NULL,
        `quantity_requested` decimal(10,2) NOT NULL,
        `quantity_approved` decimal(10,2) DEFAULT NULL,
        `unit_price` decimal(10,4) DEFAULT NULL,
        `unit_price_usd` decimal(10,4) DEFAULT NULL,
        `total_price` decimal(12,2) DEFAULT NULL,
        `notes` text DEFAULT NULL,
        `status` enum('pending','approved','partially_approved','rejected','unavailable') NOT NULL DEFAULT 'pending',
        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_order` (`order_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
}

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        // ── جلب منتجات المعمل للاختيار ──
        if ($act === 'get_factory_products') {
            if (!$factorySuffix)
                throw new Exception('الفرع غير مرتبط بمعمل');
            $TP = "products_{$factorySuffix}";
            $TV = "product_variants_{$factorySuffix}";
            $TW = "warehouse_items_{$factorySuffix}";
            try {
                // التحقق من وجود الجدول أولاً
                $tableCheck = $pdo->query("SHOW TABLES LIKE '{$TP}'")->fetchColumn();
                if (!$tableCheck) {
                    echo json_encode(['ok' => true, 'data' => [], 'msg' => 'لا توجد منتجات مسجلة في المعمل بعد']);
                    exit;
                }
                $stmt = $pdo->query("
                    SELECT p.id, p.model_number, p.name, p.age_type, p.age_from, p.age_to,
                        COUNT(DISTINCT v.id) AS variant_count,
                        COALESCE(SUM(wi.quantity),0) AS total_stock
                    FROM `{$TP}` p
                    LEFT JOIN `{$TV}` v ON v.product_id = p.id AND v.is_active=1
                    LEFT JOIN `{$TW}` wi ON wi.variant_id = v.id
                    GROUP BY p.id
                    ORDER BY p.model_number
                    LIMIT 200
                ");
                echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()]);
            } catch (Throwable $e) {
                echo json_encode(['ok' => false, 'msg' => 'خطأ في جلب المنتجات: ' . $e->getMessage()]);
            }
        }

        // ── جلب متغيرات منتج محدد ──
        elseif ($act === 'get_variants') {
            if (!$factorySuffix)
                throw new Exception('الفرع غير مرتبط بمعمل');
            $pid = (int) $_POST['product_id'];
            $TV = "product_variants_{$factorySuffix}";
            $TW = "warehouse_items_{$factorySuffix}";
            $TS_prod = "product_sizes_{$factorySuffix}";
            try {
                $stmt = $pdo->prepare("
                    SELECT v.id, v.color, v.barcode, v.last_cost_price,
                        COALESCE(s.label, v.size_id) AS size_label,
                        COALESCE(SUM(wi.quantity),0) AS stock
                    FROM `{$TV}` v
                    LEFT JOIN `{$TS_prod}` s ON s.id = v.size_id
                    LEFT JOIN `{$TW}` wi ON wi.variant_id = v.id
                    WHERE v.product_id=? AND v.is_active=1
                    GROUP BY v.id
                    ORDER BY v.color, s.sort_order
                ");
                $stmt->execute([$pid]);
                echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()]);
            } catch (Throwable $e) {
                echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            }
        }

        // ── إنشاء طلب جديد ──
        elseif ($act === 'create_order') {
            requirePermission('inventory.internal_orders', 'create');
            if (!$factoryId)
                throw new Exception('الفرع غير مرتبط بمعمل — تحقق من إعدادات الفرع');

            $items = json_decode($_POST['items'] ?? '[]', true);
            if (empty($items))
                throw new Exception('أضف بنداً واحداً على الأقل');

            $req_date = $_POST['required_date'] ?? null;
            $currency = $_POST['currency'] ?? 'USD';
            $ex_rate = (float) ($_POST['exchange_rate'] ?? 1);
            $notes = trim($_POST['notes'] ?? '');

            // رقم الطلب
            $lastNum = $pdo->query("SELECT COUNT(*)+1 FROM internal_orders WHERE from_branch_id={$branchId}")->fetchColumn();
            $orderNum = strtoupper($TS) . '-ORD-' . str_pad($lastNum, 4, '0', STR_PAD_LEFT);

            $total = 0;
            foreach ($items as $item) {
                $total += (float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0);
            }

            $pdo->prepare("INSERT INTO internal_orders
                (order_number,from_branch_id,to_branch_id,order_date,required_date,
                 currency,exchange_rate,total_amount,notes,status,created_by)
                VALUES (?,?,?,NOW(),?,?,?,?,?,'draft',?)")
                ->execute([$orderNum, $branchId, $factoryId, $req_date, $currency, $ex_rate, $total, $notes, $_SESSION['user_id']]);

            $orderId = $pdo->lastInsertId();

            // بنود الطلب
            foreach ($items as $item) {
                $qty = (float) ($item['quantity'] ?? 0);
                $price = (float) ($item['unit_price'] ?? 0);
                $pdo->prepare("INSERT INTO internal_order_items
                    (order_id,product_id,variant_id,product_name,model_number,
                     size,color,barcode,quantity_requested,unit_price,
                     unit_price_usd,total_price)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([
                        $orderId,
                        $item['product_id'] ?? null,
                        $item['variant_id'] ?? null,
                        $item['product_name'] ?? '',
                        $item['model_number'] ?? null,
                        $item['size'] ?? null,
                        $item['color'] ?? null,
                        $item['barcode'] ?? null,
                        $qty,
                        $price,
                        $ex_rate > 0 ? round($price / $ex_rate, 4) : $price,
                        round($qty * $price, 2),
                    ]);
            }

            echo json_encode(['ok' => true, 'msg' => 'تم إنشاء الطلب', 'order_number' => $orderNum, 'id' => $orderId]);
        }

        // ── إرسال الطلب للمعمل ──
        elseif ($act === 'send_order') {
            requirePermission('inventory.internal_orders', 'edit');
            $id = (int) $_POST['id'];
            $pdo->prepare("UPDATE internal_orders SET status='sent', updated_by=?, updated_at=NOW()
                WHERE id=? AND from_branch_id=? AND status='draft'")
                ->execute([$_SESSION['user_id'], $id, $branchId]);
            echo json_encode(['ok' => true, 'msg' => 'تم إرسال الطلب للمعمل']);
        }

        // ── رد المعمل: قبول/رفض/تعديل ──
        elseif ($act === 'respond_order') {
            requirePermission('inventory.internal_orders', 'confirm');
            $id = (int) $_POST['id'];
            $new_status = $_POST['new_status'] ?? 'approved'; // approved|rejected|partially_approved
            $resp_notes = trim($_POST['response_notes'] ?? '');
            $items_resp = json_decode($_POST['items'] ?? '[]', true);

            // تحديث الطلب
            $pdo->prepare("UPDATE internal_orders SET status=?,responded_by=?,responded_at=NOW(),response_notes=?,updated_at=NOW()
                WHERE id=?")
                ->execute([$new_status, $_SESSION['user_id'], $resp_notes, $id]);

            // تحديث كميات البنود المعتمدة
            foreach ($items_resp as $item) {
                $pdo->prepare("UPDATE internal_order_items SET
                    quantity_approved=?, unit_price=?, total_price=?, status=?
                    WHERE id=? AND order_id=?")
                    ->execute([
                        (float) ($item['quantity_approved'] ?? 0),
                        (float) ($item['unit_price'] ?? 0),
                        round((float) ($item['quantity_approved'] ?? 0) * (float) ($item['unit_price'] ?? 0), 2),
                        $item['status'] ?? 'approved',
                        (int) $item['id'],
                        $id,
                    ]);
            }
            echo json_encode(['ok' => true, 'msg' => 'تم تسجيل الرد']);
        }

        // ── تحويل لفاتورة شراء ──
        elseif ($act === 'convert_to_purchase') {
            requirePermission('inventory.internal_orders', 'confirm');
            $id = (int) $_POST['id'];

            $ord = $pdo->prepare("SELECT * FROM internal_orders WHERE id=? AND to_branch_id=?");
            $ord->execute([$id, $branchId]);
            $order = $ord->fetch();
            if (!$order)
                throw new Exception('الطلب غير موجود أو غير مصرح');
            if (!in_array($order['status'], ['approved', 'partially_approved']))
                throw new Exception('يجب أن يكون الطلب معتمداً أولاً');

            $items = $pdo->prepare("SELECT * FROM internal_order_items WHERE order_id=? AND status IN ('approved','partially_approved')");
            $items->execute([$id]);
            $approved_items = $items->fetchAll();
            if (empty($approved_items))
                throw new Exception('لا توجد بنود معتمدة للتحويل');

            $total = array_sum(array_column($approved_items, 'total_price'));

            // فاتورة الشراء في الفرع الطالب
            $TP_buy = "purchases_{$TS}";
            $TPI_buy = "purchase_items_{$TS}";

            // ⚠ إصلاح بق حقيقي: كانت هذه الفاتورة تُنشأ بحالة status='confirmed'
            // مباشرة، بدون أي مرور على api/confirm_purchase_invoice.php —
            // يعني لا تحديث فعلي للمخزون (warehouse_items) ولا أي قيد
            // محاسبي، رغم أن الحالة كانت "تكذب" وتقول إن كل شي تم. الآن
            // تُنشأ كمسودة (draft) بأمانة، ويكمّلها المستخدم بضغطة "تأكيد"
            // عادية من قائمة فواتير الشراء — نفس المسار الموحّد والمُختبر
            // أصلاً (تحديث المخزون + ترحيل القيد معاً) بدل تكرار/تقليد
            // هذا المنطق المعقّد هنا من جديد بشكل منفصل وعرضة للتضارب.
            $purch_num = 'INV-' . $order['order_number'];
            $pdo->prepare("INSERT INTO `{$TP_buy}`
                (purchase_number,purchase_date,total_amount,final_amount,final_amount_usd,
                 currency,exchange_rate,status,notes,user_id)
                VALUES (?,NOW(),?,?,?,?,?,'draft',?,?)")
                ->execute([
                    $purch_num,
                    $total,
                    $total,
                    $order['exchange_rate'] > 0 ? round($total / $order['exchange_rate'], 4) : $total,
                    $order['currency'],
                    $order['exchange_rate'],
                    'من طلب داخلي: ' . $order['order_number'] . ' — بانتظار التأكيد النهائي',
                    $_SESSION['user_id'],
                ]);
            $purchId = $pdo->lastInsertId();

            foreach ($approved_items as $item) {
                $pdo->prepare("INSERT INTO `{$TPI_buy}`
                    (purchase_id,product_id,variant_id,product_name,model_number,
                     size,color,barcode,quantity,unit_price,unit_price_usd,total_price)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([
                        $purchId,
                        $item['product_id'],
                        $item['variant_id'],
                        $item['product_name'],
                        $item['model_number'],
                        $item['size'],
                        $item['color'],
                        $item['barcode'],
                        $item['quantity_approved'],
                        $item['unit_price'],
                        $item['unit_price_usd'],
                        $item['total_price'],
                    ]);
            }

            // تحديث الطلب
            $pdo->prepare("UPDATE internal_orders SET status='converted', purchase_id=?, updated_at=NOW() WHERE id=?")
                ->execute([$purchId, $id]);

            echo json_encode([
                'ok' => true,
                'msg' => 'تم إنشاء مسودة فاتورة شراء رقم ' . $purch_num . ' — افتحها من قائمة فواتير الشراء ودوس "تأكيد" لتحديث المخزون وترحيل القيد المحاسبي',
                'purchase_id' => $purchId,
            ]);
        }

        // ── جلب تفاصيل طلب ──
        elseif ($act === 'get_order') {
            $id = (int) $_POST['id'];
            $ord = $pdo->prepare("SELECT o.*, bf.name AS from_name, bt.name AS to_name
                FROM internal_orders o
                JOIN branches bf ON bf.id=o.from_branch_id
                JOIN branches bt ON bt.id=o.to_branch_id
                WHERE o.id=?");
            $ord->execute([$id]);
            $order = $ord->fetch();
            if (!$order)
                throw new Exception('الطلب غير موجود');
            $items_stmt = $pdo->prepare("SELECT * FROM internal_order_items WHERE order_id=? ORDER BY id");
            $items_stmt->execute([$id]);
            $order['items'] = $items_stmt->fetchAll();
            echo json_encode(['ok' => true, 'data' => $order]);
        } else
            throw new Exception('إجراء غير معروف');

    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── جلب الطلبات ──────────────────────────────────────────────────
// الطلبات الصادرة (أنا طالب) + الواردة (أنا مورد)
$orders_out = $pdo->prepare("SELECT o.*, b.name AS to_name
    FROM internal_orders o JOIN branches b ON b.id=o.to_branch_id
    WHERE o.from_branch_id=? ORDER BY o.created_at DESC LIMIT 50");
$orders_out->execute([$branchId]);
$orders_out = $orders_out->fetchAll();

$orders_in = []; // محجوز لواجهة فرع التصنيع لاحقاً

$currencies = $pdo->query("SELECT * FROM currencies WHERE status='active' ORDER BY is_base DESC")->fetchAll();

$statusConfig = [
    'draft' => ['مسودة', 'secondary', 'bi-pencil'],
    'sent' => ['مُرسَل', 'info', 'bi-send'],
    'reviewing' => ['قيد المراجعة', 'warning', 'bi-hourglass-split'],
    'approved' => ['معتمد', 'success', 'bi-check-circle'],
    'partially_approved' => ['معتمد جزئياً', 'warning', 'bi-check-circle-half'],
    'rejected' => ['مرفوض', 'danger', 'bi-x-circle'],
    'converted' => ['محوّل لفاتورة', 'success', 'bi-file-earmark-check'],
    'cancelled' => ['ملغي', 'secondary', 'bi-slash-circle'],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>الطلبات الداخلية — <?= htmlspecialchars($branchName) ?></title>
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
            padding: .85rem 1.1rem;
            display: flex;
            align-items: center;
            gap: .85rem
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0
        }

        .stat-val {
            font-size: 1.3rem;
            font-weight: 700;
            line-height: 1.1
        }

        .stat-lbl {
            font-size: .72rem;
            color: #64748b;
            margin-top: .15rem
        }

        .ctrl-bar {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            padding: .65rem 1.1rem;
            margin-bottom: 1.1rem;
            display: flex;
            align-items: center;
            gap: .65rem;
            flex-wrap: wrap
        }

        .tab-wrap {
            display: flex;
            gap: 3px;
            background: #f1f5f9;
            border-radius: 9px;
            padding: 3px
        }

        .t-btn {
            padding: .38rem .9rem;
            border-radius: 7px;
            font-size: .81rem;
            cursor: pointer;
            color: #64748b;
            border: none;
            background: none;
            font-family: 'Cairo', sans-serif;
            font-weight: 600;
            transition: all .15s;
            white-space: nowrap
        }

        .t-btn.act {
            background: #fff;
            color: #1e3a8a;
            box-shadow: 0 1px 4px rgba(0, 0, 0, .07)
        }

        .sec-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            margin-bottom: 1.1rem
        }

        .sec-card table {
            margin: 0;
            font-size: .82rem
        }

        .sec-card th {
            background: #f8fafc;
            color: #64748b;
            font-size: .75rem;
            font-weight: 600;
            border: none;
            padding: .6rem .9rem;
            white-space: nowrap
        }

        .sec-card td {
            padding: .55rem .9rem;
            vertical-align: middle;
            border-top: 1px solid #f1f5f9
        }

        .sec-card tbody tr:hover td {
            background: #f8fafc
        }

        .sec-hdr {
            padding: .7rem 1.1rem;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: .5rem
        }

        .act-btn {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e2e8f0;
            background: #fff;
            color: #64748b;
            cursor: pointer;
            font-size: .85rem;
            transition: all .15s
        }

        .act-btn:hover {
            background: #eff6ff;
            color: #2563eb;
            border-color: #bfdbfe
        }

        .act-btn.danger:hover {
            background: #fef2f2;
            color: #dc2626;
            border-color: #fca5a5
        }

        .act-btn.success:hover {
            background: #f0fdf4;
            color: #16a34a;
            border-color: #bbf7d0
        }

        /* مودال */
        .modal-content {
            border-radius: 16px;
            border: none
        }

        .mhdr-out {
            background: linear-gradient(135deg, #1e3a8a, #2563eb);
            border-radius: 16px 16px 0 0;
            border: none
        }

        .mhdr-in {
            background: linear-gradient(135deg, #065f46, #16a34a);
            border-radius: 16px 16px 0 0;
            border: none
        }

        .mhdr-view {
            background: linear-gradient(135deg, #4c1d95, #7c3aed);
            border-radius: 16px 16px 0 0;
            border: none
        }

        /* بناء الطلب */
        .order-item-row {
            background: #f8fafc;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            padding: .6rem .85rem;
            margin-bottom: 6px;
            display: grid;
            grid-template-columns: 1fr auto auto auto auto;
            gap: .5rem;
            align-items: center
        }

        .order-item-row .prod-lbl {
            font-size: .82rem;
            font-weight: 600
        }

        .order-item-row .prod-sub {
            font-size: .73rem;
            color: #64748b
        }

        .qty-inp {
            width: 70px;
            border-radius: 7px;
            border: 1px solid #e2e8f0;
            padding: 3px 6px;
            font-size: .82rem;
            text-align: center;
            font-family: 'Cairo', sans-serif
        }

        .price-inp {
            width: 90px;
            border-radius: 7px;
            border: 1px solid #e2e8f0;
            padding: 3px 6px;
            font-size: .82rem;
            text-align: center;
            font-family: 'Cairo', sans-serif
        }

        .remove-item {
            width: 24px;
            height: 24px;
            border-radius: 6px;
            border: 1px solid #fca5a5;
            background: #fef2f2;
            color: #dc2626;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .8rem
        }

        /* product selector */
        .prod-picker {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            max-height: 240px;
            overflow-y: auto
        }

        .prod-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: .5rem .85rem;
            cursor: pointer;
            border-bottom: 1px solid #f1f5f9;
            transition: background .1s
        }

        .prod-row:hover {
            background: #eff6ff
        }

        .prod-row:last-child {
            border-bottom: none
        }

        .stock-ok {
            color: #16a34a;
            font-size: .74rem;
            font-weight: 600
        }

        .stock-low {
            color: #d97706;
            font-size: .74rem;
            font-weight: 600
        }

        .stock-out {
            color: #dc2626;
            font-size: .74rem;
            font-weight: 600
        }

        /* respond */
        .resp-item {
            background: #f8fafc;
            border-radius: 9px;
            padding: .55rem .85rem;
            margin-bottom: 5px;
            border: 1px solid #e2e8f0
        }

        .resp-btns {
            display: flex;
            gap: 4px
        }

        .resp-btn {
            padding: 2px 8px;
            border-radius: 6px;
            font-size: .75rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            font-family: 'Cairo', sans-serif;
            transition: all .12s
        }

        .rb-ok {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0
        }

        .rb-ok.active {
            background: #16a34a;
            color: #fff
        }

        .rb-part {
            background: #fffbeb;
            color: #92400e;
            border: 1px solid #fde68a
        }

        .rb-part.active {
            background: #d97706;
            color: #fff
        }

        .rb-no {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fca5a5
        }

        .rb-no.active {
            background: #dc2626;
            color: #fff
        }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>

    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title">
            <?php if ($isFactory): ?>
                        <i class="bi bi-buildings me-1 text-success"></i>طلبات الشراء الواردة
            <?php else: ?>
                        <i class="bi bi-send me-1 text-primary"></i>طلبات الشراء الداخلية
            <?php endif; ?>
        </span>
        <span class="tb-branch">
            <i class="bi bi-arrow-left-right me-1"></i>
            <?= htmlspecialchars($branchName) ?>
            <?php if ($factoryName): ?>
                        <span class="text-muted mx-1">↔</span>
                        <?= htmlspecialchars($factoryName) ?>
            <?php endif; ?>
        </span>
        <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.8rem;color:#94a3b8">
            <span>المخزون</span><i class="bi bi-chevron-left mx-1" style="font-size:.7rem"></i>
            <span class="text-primary">الطلبات الداخلية</span>
        </nav>
    </header>

    <main class="main-content">
        <div class="content-body">

            <!-- إحصائيات -->
            <div class="row g-3 mb-3">
                <?php
                // إحصائيات فرع البيع فقط — واردة محجوزة لفرع التصنيع
                $statItems = [
                    [count($orders_out), 'طلباتي الصادرة', 'bi-send', '#2563eb', '#eff6ff'],
                    [
                        count(array_filter($orders_out, function ($o) {
                            return $o['status'] === 'sent';
                        })),
                        'بانتظار الرد',
                        'bi-hourglass-split',
                        '#d97706',
                        '#fffbeb'
                    ],
                    [
                        count(array_filter($orders_out, function ($o) {
                            return $o['status'] === 'approved';
                        })),
                        'معتمدة',
                        'bi-check-circle',
                        '#16a34a',
                        '#f0fdf4'
                    ],
                    [
                        count(array_filter($orders_out, function ($o) {
                            return $o['status'] === 'converted';
                        })),
                        'محوّلة لفواتير',
                        'bi-file-earmark-check',
                        '#7c3aed',
                        '#f5f3ff'
                    ],
                ];
                foreach ($statItems as [$v, $l, $ic, $clr, $bg]): ?>
                            <div class="col-6 col-md-3">
                                <div class="stat-card">
                                    <div class="stat-icon" style="background:<?= $bg ?>;color:<?= $clr ?>"><i class="bi <?= $ic ?>"></i>
                                    </div>
                                    <div>
                                        <div class="stat-val" style="color:<?= $clr ?>"><?= $v ?></div>
                                        <div class="stat-lbl"><?= $l ?></div>
                                    </div>
                                </div>
                            </div>
                <?php endforeach; ?>
            </div>

            <!-- شريط التحكم -->
            <div class="ctrl-bar">
                <div class="tab-wrap">
                    <button class="t-btn act" id="tabOut" onclick="switchTab('Out')">
                        <i class="bi bi-send me-1"></i>طلباتي الصادرة
                    </button>
                </div>
                <?php if ($isRetail && $factoryId): ?>
                            <button class="btn btn-sm ms-auto"
                                style="border-radius:9px;background:#1e3a8a;color:#fff;font-weight:600;font-size:.82rem"
                                onclick="openCreateModal()">
                                <i class="bi bi-plus-lg me-1"></i>طلب شراء جديد
                            </button>
                <?php elseif ($isRetail && !$factoryId): ?>
                            <span class="text-warning ms-auto small">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                الفرع غير مرتبط بمعمل — تحقق من إعدادات الفرع
                            </span>
                <?php else: ?>
                            <span class="text-muted ms-auto small">
                                <i class="bi bi-buildings me-1"></i>
                                <?= htmlspecialchars($branchName) ?> — فرع تصنيع
                            </span>
                <?php endif; ?>
            </div>

            <!-- طلباتي الصادرة -->
            <div id="secOut">
                <div class="sec-card">
                    <div class="sec-hdr">
                        <span style="font-size:.88rem;font-weight:700;color:#1e293b">
                            <i class="bi bi-send me-2 text-primary"></i>طلباتي لـ
                            <?= htmlspecialchars($factoryName ?: 'المعمل') ?>
                        </span>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>رقم الطلب</th>
                                    <th>التاريخ</th>
                                    <th>تاريخ التسليم المطلوب</th>
                                    <th>العملة</th>
                                    <th>إجمالي الطلب</th>
                                    <th>عدد البنود</th>
                                    <th>الحالة</th>
                                    <th style="text-align:center">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($orders_out)): ?>
                                            <tr>
                                                <td colspan="9" class="text-center text-muted py-4">
                                                    <i class="bi bi-inbox d-block mb-2 fs-2" style="opacity:.2"></i>
                                                    لا توجد طلبات صادرة بعد
                                                </td>
                                            </tr>
                                <?php endif; ?>
                                <?php foreach ($orders_out as $i => $ord):
                                    $sc = $statusConfig[$ord['status']] ?? ['—', 'secondary', 'bi-question'];
                                    $cnt = $pdo->prepare("SELECT COUNT(*) FROM internal_order_items WHERE order_id=?");
                                    $cnt->execute([$ord['id']]);
                                    $items_cnt = $cnt->fetchColumn();
                                    ?>
                                            <tr>
                                                <td class="text-muted small"><?= $i + 1 ?></td>
                                                <td class="fw-600" style="font-size:.84rem">
                                                    <?= htmlspecialchars($ord['order_number']) ?></td>
                                                <td class="text-muted small"><?= $ord['order_date'] ?></td>
                                                <td class="text-muted small"><?= $ord['required_date'] ?? '—' ?></td>
                                                <td class="text-muted small"><?= $ord['currency'] ?></td>
                                                <td class="fw-600" style="font-size:.83rem">
                                                    <?= number_format($ord['total_amount'], 2) ?></td>
                                                <td class="text-center"><?= $items_cnt ?></td>
                                                <td>
                                                    <span
                                                        class="badge bg-<?= $sc[1] ?>-subtle text-<?= $sc[1] ?> border border-<?= $sc[1] ?>-subtle"
                                                        style="font-size:.72rem">
                                                        <i class="bi <?= $sc[2] ?> me-1"></i><?= $sc[0] ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="d-flex gap-1 justify-content-center">
                                                        <button class="act-btn" onclick="viewOrder(<?= $ord['id'] ?>)"
                                                            title="عرض التفاصيل">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <?php if ($ord['status'] === 'draft'): ?>
                                                                    <button class="act-btn success"
                                                                        onclick="sendOrder(<?= $ord['id'] ?>, '<?= htmlspecialchars($ord['order_number'], ENT_QUOTES) ?>')"
                                                                        title="إرسال للمعمل">
                                                                        <i class="bi bi-send"></i>
                                                                    </button>
                                                        <?php endif; ?>
                                                        <?php if (in_array($ord['status'], ['approved', 'partially_approved']) && !$ord['purchase_id']): ?>
                                                                    <button class="act-btn success"
                                                                        onclick="convertOrder(<?= $ord['id'] ?>, '<?= htmlspecialchars($ord['order_number'], ENT_QUOTES) ?>')"
                                                                        title="تحويل لفاتورة شراء">
                                                                        <i class="bi bi-file-earmark-check"></i>
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

            <?php /* محجوز لواجهة فرع التصنيع */ ?>
            <div id="secIn" style="display:none">
                <div class="sec-card">
                    <div class="sec-hdr">
                        <span style="font-size:.88rem;font-weight:700;color:#1e293b">
                            <i class="bi bi-inbox me-2" style="color:#7c3aed"></i>طلبات واردة من الفروع الأخرى
                        </span>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>رقم الطلب</th>
                                    <th>من فرع</th>
                                    <th>التاريخ</th>
                                    <th>التسليم المطلوب</th>
                                    <th>الإجمالي</th>
                                    <th>الحالة</th>
                                    <th style="text-align:center">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($orders_in)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center text-muted py-4">
                                                    <i class="bi bi-inbox d-block mb-2 fs-2" style="opacity:.2"></i>
                                                    لا توجد طلبات واردة
                                                </td>
                                            </tr>
                                <?php endif; ?>
                                <?php foreach ($orders_in as $i => $ord):
                                    $sc = $statusConfig[$ord['status']] ?? ['—', 'secondary', 'bi-question'];
                                    ?>
                                            <tr>
                                                <td class="text-muted small"><?= $i + 1 ?></td>
                                                <td class="fw-600" style="font-size:.84rem">
                                                    <?= htmlspecialchars($ord['order_number']) ?></td>
                                                <td>
                                                    <span class="badge bg-info-subtle text-info border border-info-subtle"
                                                        style="font-size:.72rem">
                                                        <?= htmlspecialchars($ord['from_name']) ?>
                                                    </span>
                                                </td>
                                                <td class="text-muted small"><?= $ord['order_date'] ?></td>
                                                <td class="text-muted small"><?= $ord['required_date'] ?? '—' ?></td>
                                                <td class="fw-600" style="font-size:.83rem">
                                                    <?= number_format($ord['total_amount'], 2) ?>             <?= $ord['currency'] ?></td>
                                                <td>
                                                    <span
                                                        class="badge bg-<?= $sc[1] ?>-subtle text-<?= $sc[1] ?> border border-<?= $sc[1] ?>-subtle"
                                                        style="font-size:.72rem">
                                                        <i class="bi <?= $sc[2] ?> me-1"></i><?= $sc[0] ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="d-flex gap-1 justify-content-center">
                                                        <button class="act-btn" onclick="viewOrder(<?= $ord['id'] ?>)" title="عرض">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <?php if ($ord['status'] === 'sent'): ?>
                                                                    <button class="act-btn success" onclick="openRespondModal(<?= $ord['id'] ?>)"
                                                                        title="الرد على الطلب">
                                                                        <i class="bi bi-reply"></i>
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
        </div>
    </main>
    <!-- ══ مودال إنشاء طلب جديد ══ -->
    <div class="modal fade" id="createModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header mhdr-out py-3 px-4 border-0">
                    <div>
                        <h6 class="modal-title text-white fw-700 mb-0">
                            <i class="bi bi-plus-circle me-2"></i>طلب شراء جديد
                        </h6>
                        <div style="font-size:.78rem;color:rgba(255,255,255,.75);margin-top:2px">
                            <?= htmlspecialchars($branchName) ?> ← <?= htmlspecialchars($factoryName ?: 'المعمل') ?>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pt-3 pb-2">
                    <div class="row g-3">
                        <!-- بيانات الطلب -->
                        <div class="col-md-3">
                            <label class="form-label small fw-600 text-secondary mb-1">تاريخ التسليم المطلوب</label>
                            <input type="date" id="reqDate" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-600 text-secondary mb-1">العملة</label>
                            <select id="reqCurrency" class="form-select form-select-sm">
                                <option value="USD">USD</option>
                                <option value="TRY">TRY</option>
                                <option value="SYP">SYP</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-600 text-secondary mb-1">سعر الصرف</label>
                            <input type="number" id="reqExRate" class="form-control form-control-sm" value="1"
                                step="0.0001">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-600 text-secondary mb-1">ملاحظات</label>
                            <input type="text" id="reqNotes" class="form-control form-control-sm" placeholder="اختياري">
                        </div>
                        <!-- اختيار المنتج -->
                        <div class="col-md-5">
                            <label class="form-label small fw-600 text-secondary mb-1">
                                <i class="bi bi-search me-1"></i>اختر من منتجات
                                <?= htmlspecialchars($factoryName ?: 'المعمل') ?>
                            </label>
                            <input type="search" id="prodSearch" class="form-control form-control-sm mb-1"
                                placeholder="بحث بالاسم أو الموديل..." oninput="filterProds()">
                            <div class="prod-picker" id="prodPicker">
                                <div class="text-muted text-center py-3">
                                    <span class="spinner-border spinner-border-sm me-2"></span>جاري التحميل...
                                </div>
                            </div>
                        </div>

                        <!-- البنود المضافة -->
                        <div class="col-md-7">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <label class="form-label small fw-600 text-secondary mb-0">
                                    <i class="bi bi-list-check me-1"></i>بنود الطلب
                                    <span id="itemCount" class="badge bg-primary-subtle text-primary ms-1">0</span>
                                </label>
                                <span id="orderTotal" class="fw-700" style="font-size:.9rem;color:#1e3a8a">0.00</span>
                            </div>
                            <div id="orderItems" style="min-height:120px">
                                <div class="text-muted text-center py-4" style="font-size:.83rem" id="emptyItems">
                                    <i class="bi bi-arrow-right-circle me-1"></i>اختر منتجاً من اليسار
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-3 pt-1">
                    <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal"
                        style="border-radius:8px">إلغاء</button>
                    <button class="btn btn-sm fw-600"
                        style="border-radius:8px;min-width:130px;background:#1e3a8a;color:#fff"
                        onclick="submitOrder(false)">
                        <i class="bi bi-save me-1"></i>حفظ كمسودة
                    </button>
                    <button class="btn btn-sm fw-600"
                        style="border-radius:8px;min-width:130px;background:#16a34a;color:#fff"
                        onclick="submitOrder(true)">
                        <i class="bi bi-send me-1"></i>إرسال للمعمل
                    </button>
                </div>
            </div>
        </div>
    </div>
    <!-- ══ مودال الرد على طلب ══ -->
    <div class="modal fade" id="respondModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header mhdr-in py-3 px-4 border-0">
                    <div>
                        <h6 class="modal-title text-white fw-700 mb-0">
                            <i class="bi bi-reply me-2"></i>الرد على الطلب
                        </h6>
                        <div id="respOrderNum" style="font-size:.78rem;color:rgba(255,255,255,.75);margin-top:2px">—
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pt-3 pb-2">
                    <input type="hidden" id="respOrderId">
                    <div id="respItemsList"></div>
                    <div class="mt-3">
                        <label class="form-label small fw-600 text-secondary mb-1">ملاحظات الرد</label>
                        <textarea id="respNotes" class="form-control form-control-sm" rows="2"
                            placeholder="اختياري"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-3 pt-1">
                    <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal"
                        style="border-radius:8px">إلغاء</button>
                    <button class="btn btn-sm fw-600"
                        style="border-radius:8px;min-width:110px;background:#dc2626;color:#fff"
                        onclick="respondOrder('rejected')">
                        <i class="bi bi-x-circle me-1"></i>رفض الكل
                    </button>
                    <button class="btn btn-sm fw-600"
                        style="border-radius:8px;min-width:110px;background:#16a34a;color:#fff"
                        onclick="respondOrder('approved')">
                        <i class="bi bi-check-circle me-1"></i>قبول الكل
                    </button>
                </div>
            </div>
        </div>
    </div>
    <!-- ══ مودال عرض تفاصيل طلب ══ -->
    <div class="modal fade" id="viewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header mhdr-view py-3 px-4 border-0">
                    <h6 class="modal-title text-white fw-700 mb-0">
                        <i class="bi bi-eye me-2"></i>تفاصيل الطلب
                    </h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pt-3 pb-2" id="viewBody">
                    <div class="text-center py-4"><span class="spinner-border"></span></div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ── Sidebar ──────────────────────────────────────────────────────
        const sb = document.getElementById('sidebar'), ov = document.getElementById('sbOverlay');
        function sbOpen() { sb.classList.add('open'); ov.classList.add('show'); }
        function sbClose() { sb.classList.remove('open'); ov.classList.remove('show'); }
        window.addEventListener('resize', () => { if (window.innerWidth > 991) sbClose(); });
        document.querySelectorAll('.sb-group').forEach(g => {
            if (localStorage.getItem('sb_open_' + g.dataset.key) === 'true') g.classList.add('open');
        });
        function toggleGroup(g) {
            const o = g.classList.contains('open');
            document.querySelectorAll('.sb-group.open').forEach(x => x.classList.remove('open'));
            g.classList.toggle('open', !o);
            localStorage.setItem('sb_open_' + g.dataset.key, (!o).toString());
        }
        // ── Tabs ──────────────────────────────────────────────────────────
        // فرع البيع — تبويب واحد فقط (واردة محجوزة لفرع التصنيع)
        function switchTab(t) {
            ['Out', 'In'].forEach(x => {
                const sec = document.getElementById('sec' + x);
                const tab = document.getElementById('tab' + x);
                if (sec) sec.style.display = x === t ? '' : 'none';
                if (tab) tab.classList.toggle('act', x === t);
            });
        }
        // ── Modals ────────────────────────────────────────────────────────
        const createModal = new bootstrap.Modal(document.getElementById('createModal'));
        const respondModal = new bootstrap.Modal(document.getElementById('respondModal'));
        const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
        // ── مساعدات ──────────────────────────────────────────────────────
        function post(data) {
            const fd = new FormData();
            Object.entries(data).forEach(([k, v]) => fd.append(k, v ?? ''));
            return fetch(location.href, { method: 'POST', body: fd }).then(r => r.json());
        }
        function toast(msg, type = 'success') {
            const t = document.createElement('div');
            t.className = `alert alert-${type} shadow-sm`;
            t.style.cssText = 'position:fixed;top:76px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:240px;text-align:center;font-size:.85rem;padding:.55rem 1.25rem';
            t.innerHTML = `<i class="bi bi-${type === 'success' ? 'check-circle-fill text-success' : 'exclamation-triangle-fill text-danger'} me-2"></i>${msg}`;
            document.body.appendChild(t);
            setTimeout(() => t.remove(), 3200);
        }
        function fmt(n) { return new Intl.NumberFormat('ar-SY', { minimumFractionDigits: 2 }).format(n || 0); }
        // ── بناء الطلب ───────────────────────────────────────────────────
        let allProducts = [], orderItems = [];
        function openCreateModal() {
            orderItems = [];
            document.getElementById('orderItems').innerHTML = `<div class="text-muted text-center py-4" style="font-size:.83rem" id="emptyItems"><i class="bi bi-arrow-right-circle me-1"></i>اختر منتجاً من اليسار</div>`;
            document.getElementById('itemCount').textContent = '0';
            document.getElementById('orderTotal').textContent = '0.00';
            document.getElementById('reqNotes').value = '';
            document.getElementById('prodSearch').value = '';
            createModal.show();

            if (allProducts.length === 0) {
                post({ _action: 'get_factory_products' }).then(d => {
                    if (d.ok) { allProducts = d.data; renderProds(allProducts); }
                    else document.getElementById('prodPicker').innerHTML = `<div class="text-danger text-center py-3 small">${d.msg}</div>`;
                });
            } else renderProds(allProducts);
        }
        function renderProds(prods) {
            const picker = document.getElementById('prodPicker');
            if (!prods.length) { picker.innerHTML = '<div class="text-muted text-center py-3 small">لا توجد منتجات</div>'; return; }
            picker.innerHTML = prods.map(p => {
                const stock = parseInt(p.total_stock || 0);
                const sc = stock > 10 ? 'stock-ok' : stock > 0 ? 'stock-low' : 'stock-out';
                const sl = stock > 10 ? stock + ' قطعة' : stock > 0 ? 'منخفض: ' + stock : 'نفد';
                return `<div class="prod-row" onclick="addProduct(${p.id})">
            <div>
                <div style="font-size:.83rem;font-weight:600">${p.name}</div>
                <div style="font-size:.73rem;color:#64748b">${p.model_number} · ${p.variant_count || 0} متغير</div>
            </div>
            <span class="${sc}">${sl}</span>
        </div>`;
            }).join('');
        }
        function filterProds() {
            const q = document.getElementById('prodSearch').value.toLowerCase();
            renderProds(q ? allProducts.filter(p => p.name.toLowerCase().includes(q) || (p.model_number || '').toLowerCase().includes(q)) : allProducts);
        }
        function addProduct(productId) {
            const prod = allProducts.find(p => p.id == productId);
            if (!prod) return;
            // إذا موجود بالفعل ننبّه
            if (orderItems.find(i => i.product_id == productId && !i.variant_id)) {
                toast('المنتج مضاف بالفعل — عدّل الكمية في البند', 'warning');
                return;
            }
            const item = {
                temp_id: Date.now(),
                product_id: prod.id,
                variant_id: null,
                product_name: prod.name,
                model_number: prod.model_number,
                size: '',
                color: '',
                barcode: '',
                quantity_requested: 1,
                unit_price: 0,
            };
            orderItems.push(item);
            renderOrderItems();
        }
        function renderOrderItems() {
            const container = document.getElementById('orderItems');
            if (!orderItems.length) {
                container.innerHTML = '<div class="text-muted text-center py-4" style="font-size:.83rem"><i class="bi bi-arrow-right-circle me-1"></i>اختر منتجاً من اليسار</div>';
                document.getElementById('itemCount').textContent = '0';
                document.getElementById('orderTotal').textContent = '0.00';
                return;
            }
            container.innerHTML = orderItems.map((item, idx) => `
        <div class="order-item-row" id="oir_${item.temp_id}">
            <div>
                <div class="prod-lbl">${item.product_name}</div>
                <div class="prod-sub">${item.model_number || ''}</div>
            </div>
            <input type="text" class="qty-inp" placeholder="اللون/المقاس"
                   value="${item.color || ''}"
                   onchange="updateItem(${item.temp_id},'color',this.value)"
                   style="width:80px">
            <input type="number" class="qty-inp" placeholder="الكمية"
                   value="${item.quantity_requested}" min="1" step="1"
                   onchange="updateItem(${item.temp_id},'quantity_requested',this.value)">
            <input type="number" class="price-inp" placeholder="السعر"
                   value="${item.unit_price}" min="0" step="0.01"
                   onchange="updateItem(${item.temp_id},'unit_price',this.value)">
            <button class="remove-item" onclick="removeItem(${item.temp_id})">
                <i class="bi bi-x"></i>
            </button>
        </div>`).join('');

            const total = orderItems.reduce((s, i) => s + (i.quantity_requested * i.unit_price), 0);
            document.getElementById('itemCount').textContent = orderItems.length;
            document.getElementById('orderTotal').textContent = fmt(total);
        }
        function updateItem(tempId, field, value) {
            const item = orderItems.find(i => i.temp_id == tempId);
            if (!item) return;
            item[field] = field === 'quantity_requested' || field === 'unit_price' ? parseFloat(value) || 0 : value;
            const total = orderItems.reduce((s, i) => s + (i.quantity_requested * i.unit_price), 0);
            document.getElementById('orderTotal').textContent = fmt(total);
        }
        function removeItem(tempId) {
            orderItems = orderItems.filter(i => i.temp_id != tempId);
            renderOrderItems();
        }
        function submitOrder(sendNow) {
            if (!orderItems.length) { toast('أضف بنداً واحداً على الأقل', 'danger'); return; }
            const data = {
                _action: 'create_order',
                required_date: document.getElementById('reqDate').value,
                currency: document.getElementById('reqCurrency').value,
                exchange_rate: document.getElementById('reqExRate').value,
                notes: document.getElementById('reqNotes').value,
                items: JSON.stringify(orderItems),
            };
            post(data).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                if (sendNow) {
                    post({ _action: 'send_order', id: d.id }).then(s => {
                        createModal.hide();
                        toast(s.ok ? 'تم إنشاء الطلب وإرساله للمعمل' : d.msg + ' — ' + s.msg);
                        setTimeout(() => location.reload(), 1200);
                    });
                } else {
                    createModal.hide();
                    toast('تم حفظ الطلب كمسودة — رقم: ' + d.order_number);
                    setTimeout(() => location.reload(), 1200);
                }
            });
        }
        // ── إرسال طلب ────────────────────────────────────────────────────
        function sendOrder(id, num) {
            if (!confirm(`إرسال الطلب ${num} للمعمل؟`)) return;
            post({ _action: 'send_order', id }).then(d => {
                if (d.ok) { toast(d.msg); setTimeout(() => location.reload(), 1200); }
                else toast(d.msg, 'danger');
            });
        }
        // ── تحويل لفاتورة شراء ──────────────────────────────────────────
        function convertOrder(id, num) {
            if (!confirm(`تحويل الطلب ${num} لفاتورة شراء؟`)) return;
            post({ _action: 'convert_to_purchase', id }).then(d => {
                if (d.ok) { toast(d.msg); setTimeout(() => location.reload(), 1200); }
                else toast(d.msg, 'danger');
            });
        }
        // ── الرد على طلب وارد ────────────────────────────────────────────
        let respItems = [];
        function openRespondModal(id) {
            document.getElementById('respOrderId').value = id;
            document.getElementById('respOrderNum').textContent = '...';
            document.getElementById('respItemsList').innerHTML = '<div class="text-center py-3"><span class="spinner-border spinner-border-sm"></span></div>';
            document.getElementById('respNotes').value = '';
            respondModal.show();
            post({ _action: 'get_order', id }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                const ord = d.data;
                document.getElementById('respOrderNum').textContent = ord.order_number + ' — ' + ord.from_name;
                respItems = ord.items.map(item => ({ ...item, resp_status: 'approved', resp_qty: item.quantity_requested }));
                renderRespItems();
            });
        }
        function renderRespItems() {
            document.getElementById('respItemsList').innerHTML = respItems.map((item, idx) => `
        <div class="resp-item">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div>
                    <span style="font-size:.84rem;font-weight:600">${item.product_name}</span>
                    <span class="text-muted ms-2" style="font-size:.76rem">${item.model_number || ''} ${item.color || ''} ${item.size || ''}</span>
                </div>
                <span class="text-muted" style="font-size:.8rem">طلب: <strong>${item.quantity_requested}</strong></span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="resp-btns">
                    <button class="resp-btn rb-ok ${item.resp_status === 'approved' ? 'active' : ''}" onclick="setRespStatus(${idx},'approved')">قبول</button>
                    <button class="resp-btn rb-part ${item.resp_status === 'partially_approved' ? 'active' : ''}" onclick="setRespStatus(${idx},'partially_approved')">جزئي</button>
                    <button class="resp-btn rb-no ${item.resp_status === 'rejected' ? 'active' : ''}" onclick="setRespStatus(${idx},'rejected')">رفض</button>
                </div>
                <input type="number" class="qty-inp" value="${item.resp_qty}"
                       onchange="setRespQty(${idx},this.value)"
                       ${item.resp_status === 'rejected' ? 'disabled' : ''}>
                <input type="number" class="price-inp" placeholder="سعر الوحدة" value="${item.unit_price || ''}"
                       onchange="setRespPrice(${idx},this.value)">
            </div>
        </div>`).join('');
        }
        function setRespStatus(idx, status) {
            respItems[idx].resp_status = status;
            if (status === 'rejected') respItems[idx].resp_qty = 0;
            else if (status === 'approved') respItems[idx].resp_qty = respItems[idx].quantity_requested;
            renderRespItems();
        }
        function setRespQty(idx, val) { respItems[idx].resp_qty = parseFloat(val) || 0; }
        function setRespPrice(idx, val) { respItems[idx].unit_price = parseFloat(val) || 0; }
        function respondOrder(overallStatus) {
            const id = document.getElementById('respOrderId').value;
            // إذا overallStatus='approved' نضبط كل البنود
            if (overallStatus === 'approved') {
                respItems.forEach(i => { i.resp_status = 'approved'; i.resp_qty = i.quantity_requested; });
            } else if (overallStatus === 'rejected') {
                respItems.forEach(i => { i.resp_status = 'rejected'; i.resp_qty = 0; });
            }
            // نحدد الحالة الكلية
            const statuses = [...new Set(respItems.map(i => i.resp_status))];
            let finalStatus = 'approved';
            if (statuses.includes('rejected') && statuses.includes('approved')) finalStatus = 'partially_approved';
            else if (statuses.every(s => s === 'rejected')) finalStatus = 'rejected';
            const items_data = respItems.map(i => ({
                id: i.id,
                quantity_approved: i.resp_qty,
                unit_price: i.unit_price || 0,
                status: i.resp_status,
            }));

            post({
                _action: 'respond_order',
                id,
                new_status: finalStatus,
                response_notes: document.getElementById('respNotes').value,
                items: JSON.stringify(items_data),
            }).then(d => {
                if (d.ok) { respondModal.hide(); toast(d.msg); setTimeout(() => location.reload(), 1200); }
                else toast(d.msg, 'danger');
            });
        }
        // ── عرض تفاصيل ───────────────────────────────────────────────────
        function viewOrder(id) {
            document.getElementById('viewBody').innerHTML = '<div class="text-center py-4"><span class="spinner-border"></span></div>';
            viewModal.show();
            post({ _action: 'get_order', id }).then(d => {
                if (!d.ok) { document.getElementById('viewBody').innerHTML = `<div class="text-danger">${d.msg}</div>`; return; }
                const ord = d.data;
                const statusMap = <?= json_encode(array_map(function ($s) {
                    return $s[0];
                }, $statusConfig)) ?>;
                document.getElementById('viewBody').innerHTML = `
            <div class="row g-2 mb-3">
                <div class="col-4"><div class="bg-light rounded p-2 text-center"><div class="fw-700">${ord.order_number}</div><div class="text-muted" style="font-size:.75rem">رقم الطلب</div></div></div>
                <div class="col-4"><div class="bg-light rounded p-2 text-center"><div class="fw-700">${ord.from_name}</div><div class="text-muted" style="font-size:.75rem">من فرع</div></div></div>
                <div class="col-4"><div class="bg-light rounded p-2 text-center"><div class="fw-700">${ord.to_name}</div><div class="text-muted" style="font-size:.75rem">إلى فرع</div></div></div>
            </div>
            <table class="table table-sm table-bordered" style="font-size:.82rem">
                <thead class="table-light"><tr>
                    <th>المنتج</th><th>اللون/المقاس</th>
                    <th class="text-center">الكمية المطلوبة</th>
                    <th class="text-center">الكمية المعتمدة</th>
                    <th class="text-center">السعر</th>
                    <th class="text-center">المجموع</th>
                    <th class="text-center">الحالة</th>
                </tr></thead>
                <tbody>
                ${ord.items.map(i => `<tr>
                    <td class="fw-600">${i.product_name}<div class="text-muted" style="font-size:.72rem">${i.model_number || ''}</div></td>
                    <td>${(i.color || '') + ' ' + (i.size || '')}</td>
                    <td class="text-center">${i.quantity_requested}</td>
                    <td class="text-center">${i.quantity_approved ?? '—'}</td>
                    <td class="text-center">${i.unit_price ? parseFloat(i.unit_price).toFixed(2) : '—'}</td>
                    <td class="text-center fw-600">${i.total_price ? parseFloat(i.total_price).toFixed(2) : '—'}</td>
                    <td class="text-center"><span class="badge bg-${i.status === 'approved' ? 'success' : i.status === 'rejected' ? 'danger' : 'warning'}-subtle text-${i.status === 'approved' ? 'success' : i.status === 'rejected' ? 'danger' : 'warning'}" style="font-size:.7rem">${statusMap[i.status] || i.status}</span></td>
                </tr>`).join('')}
                </tbody>
                <tfoot><tr><td colspan="5" class="text-end fw-700">الإجمالي:</td><td class="fw-700 text-center">${parseFloat(ord.total_amount).toFixed(2)} ${ord.currency}</td><td></td></tr></tfoot>
            </table>
            ${ord.response_notes ? `<div class="alert alert-info py-2 small"><i class="bi bi-chat-left-text me-1"></i>${ord.response_notes}</div>` : ''}
        `;
            });
        }
    </script>
</body>

</html>