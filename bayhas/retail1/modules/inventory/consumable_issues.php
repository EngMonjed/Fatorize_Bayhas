<?php
/**
 * inventory/consumable_issues.php — صرف المستهلكات
 *retail1/modules/inventory/consumable_issues.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('inventory.consumables', 'view');
$currentModule = 'inventory.consumables';

$TS = $_SESSION['table_suffix'];
$TIS = "consumable_issues_{$TS}";
$TII = "consumable_issue_items_{$TS}";
$TI = "consumable_items_{$TS}";
$TST = "consumable_stock_{$TS}";
$TM = "consumable_movements_{$TS}";
$TJE = "journal_entries_{$TS}";
$TJI = "journal_entry_items_{$TS}";
$TAS = "invoice_account_settings_{$TS}";
$TW = "warehouses_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

function genIssueNo(PDO $pdo, string $table): string
{
    $y = date('Y');
    $last = $pdo->query("SELECT issue_no FROM `{$table}`
        WHERE issue_no LIKE 'ISS-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $seq = $last ? (int) substr($last, -4) + 1 : 1;
    return "ISS-{$y}-" . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        // ── حفظ أمر صرف ──
        if ($act === 'save_issue') {
            requirePermission('inventory.consumables', 'create');
            $whId = (int) $_POST['warehouse_id'];
            $dept = trim($_POST['department'] ?? '');
            $date = $_POST['issue_date'] ?? date('Y-m-d');
            $notes = trim($_POST['notes'] ?? '');
            $rows = json_decode($_POST['rows'] ?? '[]', true);

            if (!$whId)
                throw new Exception('يجب اختيار المستودع');
            if (!$dept)
                throw new Exception('يجب تحديد الجهة المستلمة');
            if (empty($rows))
                throw new Exception('يجب إضافة مادة واحدة على الأقل');

            $issNo = genIssueNo($pdo, $TIS);
            $pdo->prepare("INSERT INTO `{$TIS}` (issue_no,warehouse_id,department,issue_date,status,notes,created_by)
                VALUES (?,?,?,?,?,?,?)")
                ->execute([$issNo, $whId, $dept, $date, 'draft', $notes, $_SESSION['user_id']]);
            $issId = (int) $pdo->lastInsertId();

            foreach ($rows as $r) {
                $itemId = (int) $r['item_id'];
                $qty = (float) $r['qty'];
                if (!$itemId || $qty <= 0)
                    continue;

                // جلب متوسط التكلفة من المخزون
                $stSt = $pdo->prepare("SELECT avg_cost_usd FROM `{$TST}` WHERE item_id=? AND warehouse_id=?");
                $stSt->execute([$itemId, $whId]);
                $costUsd = (float) ($stSt->fetchColumn() ?? 0);

                $pdo->prepare("INSERT INTO `{$TII}` (issue_id,item_id,quantity,unit_cost_usd,total_cost_usd,notes)
                    VALUES (?,?,?,?,?,?)")
                    ->execute([$issId, $itemId, $qty, $costUsd, $qty * $costUsd, $r['notes'] ?? '']);
            }
            echo json_encode(['ok' => true, 'id' => $issId, 'no' => $issNo, 'msg' => 'تم حفظ أمر الصرف كمسودة']);
        }

        // ── تأكيد أمر صرف ──
        elseif ($act === 'confirm_issue') {
            requirePermission('inventory.consumables', 'edit');
            $id = (int) $_POST['id'];
            $iSt = $pdo->prepare("SELECT * FROM `{$TIS}` WHERE id=?");
            $iSt->execute([$id]);
            $iss = $iSt->fetch();
            if (!$iss)
                throw new Exception('أمر الصرف غير موجود');
            if ($iss['status'] !== 'draft')
                throw new Exception('يمكن تأكيد المسودات فقط');

            $pdo->beginTransaction();
            try {
                $totalIssueCostUsd = 0.0; // لترحيل قيد محاسبي واحد يغطي كل بنود أمر الصرف
                $items = $pdo->prepare("SELECT ii.*, ci.name AS item_name
                    FROM `{$TII}` ii JOIN `{$TI}` ci ON ci.id=ii.item_id
                    WHERE ii.issue_id=?");
                $items->execute([$id]);

                foreach ($items->fetchAll() as $row) {
                    // التحقق من الكمية
                    $stSt = $pdo->prepare("SELECT quantity,avg_cost_usd FROM `{$TST}` WHERE item_id=? AND warehouse_id=?");
                    $stSt->execute([$row['item_id'], $iss['warehouse_id']]);
                    $st = $stSt->fetch();
                    $avail = $st ? (float) $st['quantity'] : 0;
                    if ($avail < $row['quantity'])
                        throw new Exception("مخزون غير كافٍ: {$row['item_name']} (متوفر: {$avail})");

                    // تسجيل حركة الصرف
                    $movNo = 'MOV-' . date('Y') . '-' . str_pad(
                        (int) $pdo->query("SELECT COUNT(*)+1 FROM `{$TM}`")->fetchColumn(),
                        5,
                        '0',
                        STR_PAD_LEFT
                    );
                    $pdo->prepare("INSERT INTO `{$TM}`
                        (movement_no,item_id,warehouse_id,movement_type,direction,quantity,
                         unit_cost_usd,total_cost_usd,qty_before,qty_after,
                         reference_type,reference_id,movement_date,is_posted,created_by)
                        VALUES (?,?,?,'issue','out',?,?,?,?,?,?,?,?,1,?)")
                        ->execute([
                            $movNo,
                            $row['item_id'],
                            $iss['warehouse_id'],
                            $row['quantity'],
                            $st['avg_cost_usd'] ?? 0,
                            $row['total_cost_usd'],
                            $avail,
                            $avail - $row['quantity'],
                            'issue',
                            $id,
                            $iss['issue_date'],
                            $_SESSION['user_id']
                        ]);
                    $movId = (int) $pdo->lastInsertId();

                    // تحديث الحركة في بنود الصرف
                    $pdo->prepare("UPDATE `{$TII}` SET movement_id=?,unit_cost_usd=?,total_cost_usd=? WHERE id=?")
                        ->execute([$movId, $st['avg_cost_usd'] ?? 0, $row['quantity'] * ($st['avg_cost_usd'] ?? 0), $row['id']]);

                    // خصم من المخزون
                    $pdo->prepare("UPDATE `{$TST}` SET quantity=GREATEST(0,quantity-?),last_movement=NOW()
                        WHERE item_id=? AND warehouse_id=?")
                        ->execute([$row['quantity'], $row['item_id'], $iss['warehouse_id']]);

                    $totalIssueCostUsd += $row['quantity'] * ($st['avg_cost_usd'] ?? 0);
                }

                $pdo->prepare("UPDATE `{$TIS}` SET status='confirmed',is_posted=1 WHERE id=?")
                    ->execute([$id]);

                // ⚠ ترحيل محاسبي (كان غائباً بالكامل) — صرف المستهلكات للاستخدام
                // الداخلي هو تكلفة فعلية، ولازم ينعكس بقيد: مدين مصروف
                // المستهلكات / دائن مخزون المستهلكات. نفس فجوة مشتريات
                // المستهلكات، بس هون أخطر لأن is_posted كان يُكتب 1
                // بدون أي قيد فعلي مقابله.
                if ($totalIssueCostUsd > 0.0000001) {
                    $accRows = $pdo->query("SELECT setting_key, account_id FROM `{$TAS}`
                        WHERE setting_key IN ('consumable_expense','consumable_inventory')")
                        ->fetchAll(PDO::FETCH_KEY_PAIR);

                    if (isset($accRows['consumable_expense'], $accRows['consumable_inventory'])) {
                        $entryNo = 'JE-' . date('Y') . '-' . str_pad(
                            (int) $pdo->query("SELECT COUNT(*)+1 FROM `{$TJE}`")->fetchColumn(),
                            4, '0', STR_PAD_LEFT
                        );
                        $pdo->prepare("INSERT INTO `{$TJE}`
                            (entry_number, entry_date, description, currency, exchange_rate,
                             total_debit, total_credit, status, reference_type, reference_id,
                             created_by, posted_at, posted_by)
                            VALUES (?,?,?,?,1,?,?,'posted','consumable_issue',?,?,NOW(),?)")
                            ->execute([
                                $entryNo, $iss['issue_date'],
                                'صرف مستهلكات — أمر رقم ' . $iss['issue_no'],
                                'USD',
                                $totalIssueCostUsd, $totalIssueCostUsd,
                                $id, $_SESSION['user_id'], $_SESSION['user_id'],
                            ]);
                        $jeId = (int) $pdo->lastInsertId();

                        $pdo->prepare("INSERT INTO `{$TJI}`
                            (journal_entry_id, account_id, debit, credit, original_amount, base_amount, description, currency, exchange_rate)
                            VALUES (?,?,?,0,?,?,?, 'USD', 1)")
                            ->execute([$jeId, $accRows['consumable_expense'], $totalIssueCostUsd, $totalIssueCostUsd, $totalIssueCostUsd, 'مصروف صرف مستهلكات']);
                        $pdo->prepare("INSERT INTO `{$TJI}`
                            (journal_entry_id, account_id, debit, credit, original_amount, base_amount, description, currency, exchange_rate)
                            VALUES (?,?,0,?,?,?,?, 'USD', 1)")
                            ->execute([$jeId, $accRows['consumable_inventory'], $totalIssueCostUsd, $totalIssueCostUsd, $totalIssueCostUsd, 'تخفيض مخزون المستهلكات']);
                    } else {
                        // مفاتيح الحسابات غير مضبوطة بعد (accounting/account_settings.php)
                        // — لا نوقف تأكيد الصرف نفسه، بس نسجّل تحذيراً واضحاً بالسجل
                        error_log("[consumable_issues] تعذّر ترحيل القيد: مفاتيح consumable_expense/consumable_inventory غير مضبوطة بـ {$TAS} (issue id={$id})");
                    }
                }

                $pdo->commit();
                echo json_encode(['ok' => true, 'msg' => 'تم تأكيد أمر الصرف وتحديث المخزون']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        // ── إلغاء ──
        elseif ($act === 'cancel_issue') {
            requirePermission('inventory.consumables', 'edit');
            $id = (int) $_POST['id'];
            $iSt = $pdo->prepare("SELECT * FROM `{$TIS}` WHERE id=?");
            $iSt->execute([$id]);
            $iss = $iSt->fetch();
            if (!$iss)
                throw new Exception('غير موجود');
            if ($iss['status'] === 'cancelled')
                throw new Exception('ملغى مسبقاً');

            // إعادة المخزون إذا كان مؤكداً
            if ($iss['status'] === 'confirmed') {
                $items = $pdo->prepare("SELECT * FROM `{$TII}` WHERE issue_id=?");
                $items->execute([$id]);
                foreach ($items->fetchAll() as $row) {
                    $pdo->prepare("UPDATE `{$TST}` SET quantity=quantity+?,last_movement=NOW()
                        WHERE item_id=? AND warehouse_id=?")
                        ->execute([$row['quantity'], $row['item_id'], $iss['warehouse_id']]);
                }
            }
            $pdo->prepare("UPDATE `{$TIS}` SET status='cancelled' WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true, 'msg' => 'تم إلغاء أمر الصرف']);
        }

        // ── جلب بيانات أمر ──
        elseif ($act === 'get_issue') {
            $id = (int) $_POST['id'];
            $iSt = $pdo->prepare("SELECT i.*,w.name AS wh_name FROM `{$TIS}` i
                LEFT JOIN `{$TW}` w ON w.id=i.warehouse_id WHERE i.id=?");
            $iSt->execute([$id]);
            $iss = $iSt->fetch();
            if (!$iss)
                throw new Exception('غير موجود');
            $items = $pdo->prepare("SELECT ii.*,ci.name AS item_name,ci.unit
                FROM `{$TII}` ii JOIN `{$TI}` ci ON ci.id=ii.item_id WHERE ii.issue_id=?");
            $items->execute([$id]);
            $iss['items'] = $items->fetchAll();
            echo json_encode(['ok' => true, 'data' => $iss]);
        } else
            throw new Exception('إجراء غير معروف');
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── بيانات الصفحة ──
$search = trim($_GET['q'] ?? '');
$statusF = $_GET['status'] ?? '';
$where = 'WHERE 1=1';
$params = [];
if ($search) {
    $where .= ' AND (i.issue_no LIKE ? OR i.department LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($statusF) {
    $where .= ' AND i.status=?';
    $params[] = $statusF;
}

$stmt = $pdo->prepare("SELECT i.*,w.name AS wh_name,
    COUNT(ii.id) AS items_count,
    COALESCE(SUM(ii.total_cost_usd),0) AS total_cost
    FROM `{$TIS}` i
    LEFT JOIN `{$TW}` w ON w.id=i.warehouse_id
    LEFT JOIN `{$TII}` ii ON ii.issue_id=i.id
    {$where}
    GROUP BY i.id ORDER BY i.created_at DESC LIMIT 200");
$stmt->execute($params);
$issues = $stmt->fetchAll();

$warehouses = $pdo->query("SELECT * FROM `{$TW}` WHERE is_active=1 ORDER BY id")->fetchAll();
$items_list = $pdo->query("SELECT ci.id,ci.name,ci.unit,ci.category,
    COALESCE(SUM(cs.quantity),0) AS stock
    FROM `{$TI}` ci
    LEFT JOIN `{$TST}` cs ON cs.item_id=ci.id
    WHERE ci.is_active=1
    GROUP BY ci.id ORDER BY ci.category,ci.name")->fetchAll();

try {
    $stats = $pdo->query("SELECT COUNT(*) AS total,
        SUM(status='draft') AS drafts,
        SUM(status='confirmed') AS confirmed,
        COALESCE(SUM(CASE WHEN status='confirmed' THEN
            (SELECT COALESCE(SUM(total_cost_usd),0) FROM `{$TII}` WHERE issue_id=id) END),0) AS total_cost
        FROM `{$TIS}`")->fetch();
} catch (Exception $e) {
    $stats = ['total' => 0, 'drafts' => 0, 'confirmed' => 0, 'total_cost' => 0];
}

$STATUS_MAP = [
    'draft' => ['label' => 'مسودة', 'cls' => 'bg-secondary-subtle text-secondary'],
    'confirmed' => ['label' => 'مصروف', 'cls' => 'bg-success-subtle text-success'],
    'cancelled' => ['label' => 'ملغى', 'cls' => 'bg-danger-subtle text-danger'],
];

$DEPT_SUGGESTIONS = ['فرع البيع', 'الاستقبال', 'المخزن الرئيسي', 'الإدارة', 'الصيانة'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>صرف المستهلكات — <?= htmlspecialchars($branchName) ?></title>
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

        .act-btn.info-h:hover {
            background: #e0f2fe;
            color: #0891b2;
            border-color: #7dd3fc
        }

        .field-lbl {
            font-size: .76rem;
            font-weight: 700;
            color: #475569;
            margin-bottom: 4px;
            display: block
        }

        .req {
            color: #dc2626
        }

        .n {
            font-variant-numeric: tabular-nums
        }

        /* بنود الصرف */
        .issue-line {
            display: grid;
            grid-template-columns: minmax(0, 2fr) 80px 80px minmax(0, 1.5fr) 28px;
            gap: 6px;
            align-items: center;
            background: #f8fafc;
            border-radius: 8px;
            padding: 6px 8px;
            margin-bottom: 5px
        }

        .issue-line input,
        .issue-line select {
            font-size: .78rem;
            padding: 4px 6px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            width: 100%;
            background: #fff
        }

        .issue-line input.stock {
            background: #f0fdf4;
            color: #16a34a;
            border-color: #bbf7d0;
            font-size: .72rem
        }

        .del-btn {
            width: 24px;
            height: 24px;
            border-radius: 6px;
            border: 1px solid #fca5a5;
            background: #fff;
            color: #dc2626;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .75rem
        }

        .dept-chip {
            display: inline-block;
            background: #eff6ff;
            color: #1e3a8a;
            border: 1px solid #bfdbfe;
            border-radius: 20px;
            font-size: .72rem;
            padding: 2px 10px;
            cursor: pointer;
            margin: 2px;
            transition: all .1s
        }

        .dept-chip:hover {
            background: #1e3a8a;
            color: #fff
        }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-arrow-bar-up me-1 text-warning"></i>صرف المستهلكات</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
            <a href="consumables.php" style="color:#64748b;text-decoration:none">المستهلكات</a>
            <i class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
            <span class="text-warning">الصرف</span>
        </nav>
    </header>
    <main class="main-content">
        <div class="content-body">

            <!-- تبويبات -->
            <ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e2e8f0">
                <li class="nav-item"><a class="nav-link fw-600" href="consumables.php"
                        style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-box-seam me-1"></i>المواد</a>
                </li>
                <li class="nav-item"><a class="nav-link fw-600" href="consumable_purchases.php"
                        style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-cart-plus me-1"></i>فواتير
                        الشراء</a></li>
                <li class="nav-item"><a class="nav-link fw-600 active" href="#"
                        style="border:none;border-bottom:2px solid #f59e0b;color:#f59e0b;font-size:.83rem;margin-bottom:-2px"><i
                            class="bi bi-arrow-bar-up me-1"></i>أوامر الصرف</a></li>
            </ul>

            <!-- إحصائيات -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fef3c7"><i
                                class="bi bi-arrow-bar-up text-warning"></i></div>
                        <div>
                            <div class="stat-val"><?= $stats['total'] ?></div>
                            <div class="stat-lbl">إجمالي أوامر الصرف</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#f1f5f9"><i class="bi bi-hourglass text-secondary"></i>
                        </div>
                        <div>
                            <div class="stat-val"><?= $stats['drafts'] ?></div>
                            <div class="stat-lbl">مسودات</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#f0fdf4"><i
                                class="bi bi-check-circle text-success"></i></div>
                        <div>
                            <div class="stat-val"><?= $stats['confirmed'] ?></div>
                            <div class="stat-lbl">مصروفة</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fee2e2"><i
                                class="bi bi-currency-dollar text-danger"></i></div>
                        <div>
                            <div class="stat-val n">$ <?= number_format($stats['total_cost'], 2) ?></div>
                            <div class="stat-lbl">إجمالي التكاليف</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- فلاتر + زر جديد -->
            <div class="tbl-wrap mb-3">
                <div class="tbl-hdr">
                    <form method="get" class="d-flex gap-2 flex-wrap align-items-center w-100">
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                            placeholder="رقم الأمر أو الجهة..." class="form-control form-control-sm"
                            style="width:180px;border-radius:8px">
                        <select name="status" class="form-select form-select-sm" style="width:120px;border-radius:8px"
                            onchange="this.form.submit()">
                            <option value="">كل الحالات</option>
                            <?php foreach ($STATUS_MAP as $k => $v): ?>
                                        <option value="<?= $k ?>" <?= $statusF === $k ? 'selected' : '' ?>><?= $v['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-primary" style="border-radius:8px"><i
                                class="bi bi-search"></i></button>
                        <?php if ($search || $statusF): ?><a href="consumable_issues.php" class="btn btn-sm btn-light"
                                        style="border-radius:8px"><i class="bi bi-x-lg"></i></a><?php endif; ?>
                    </form>
                    <button class="btn btn-sm fw-600"
                        style="border-radius:9px;background:#f59e0b;color:#fff;font-size:.82rem;border:none"
                        onclick="openNewIssue()">
                        <i class="bi bi-plus-lg me-1"></i>أمر صرف جديد
                    </button>
                </div>
            </div>

            <!-- الجدول -->
            <div class="tbl-wrap">
                <div class="table-responsive">
                    <table class="mtbl">
                        <thead>
                            <tr>
                                <th>رقم الأمر</th>
                                <th>التاريخ</th>
                                <th>المستودع</th>
                                <th>الجهة المستلمة</th>
                                <th>المواد</th>
                                <th>التكلفة ($)</th>
                                <th>الحالة</th>
                                <th style="text-align:center">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($issues)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-5">
                                                <i class="bi bi-arrow-bar-up d-block mb-2" style="font-size:2rem;opacity:.2"></i>
                                                لا توجد أوامر صرف
                                            </td>
                                        </tr>
                            <?php endif; ?>
                            <?php foreach ($issues as $iss):
                                $st = $STATUS_MAP[$iss['status']] ?? $STATUS_MAP['draft'];
                                ?>
                                        <tr>
                                            <td class="n fw-600" style="direction:ltr;color:#f59e0b">
                                                <?= htmlspecialchars($iss['issue_no']) ?></td>
                                            <td class="text-muted"><?= $iss['issue_date'] ?></td>
                                            <td style="font-size:.8rem"><?= htmlspecialchars($iss['wh_name'] ?? '—') ?></td>
                                            <td>
                                                <span class="badge bg-primary-subtle text-primary" style="font-size:.75rem">
                                                    <i
                                                        class="bi bi-building me-1"></i><?= htmlspecialchars($iss['department'] ?? '—') ?>
                                                </span>
                                            </td>
                                            <td class="text-center"><span
                                                    class="badge bg-secondary-subtle text-secondary"><?= $iss['items_count'] ?>
                                                    مادة</span></td>
                                            <td class="n fw-600">$ <?= number_format($iss['total_cost'], 2) ?></td>
                                            <td><span class="badge <?= $st['cls'] ?>"
                                                    style="font-size:.68rem"><?= $st['label'] ?></span></td>
                                            <td>
                                                <div class="d-flex gap-1 justify-content-center">
                                                    <button class="act-btn info-h" onclick="viewIssue(<?= $iss['id'] ?>)" title="عرض">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <?php if ($iss['status'] === 'draft'): ?>
                                                                <button class="act-btn success-h"
                                                                    onclick="confirmIssue(<?= $iss['id'] ?>, '<?= htmlspecialchars($iss['issue_no'], ENT_QUOTES) ?>')"
                                                                    title="تأكيد الصرف">
                                                                    <i class="bi bi-check-circle"></i>
                                                                </button>
                                                    <?php endif; ?>
                                                    <?php if ($iss['status'] !== 'cancelled'): ?>
                                                                <button class="act-btn danger"
                                                                    onclick="cancelIssue(<?= $iss['id'] ?>, '<?= htmlspecialchars($iss['issue_no'], ENT_QUOTES) ?>')"
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

    <!-- مودال أمر صرف جديد -->
    <div class="modal fade" id="issueModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#b45309,#f59e0b);border-radius:16px 16px 0 0">
                    <h6 class="modal-title text-white fw-700 mb-0"><i class="bi bi-arrow-bar-up me-2"></i>أمر صرف
                        مستهلكات جديد</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pt-3">

                    <div class="row g-3 mb-3 pb-3" style="border-bottom:1px solid #f1f5f9">
                        <div class="col-md-4">
                            <label class="field-lbl">المستودع المصدر <span class="req">*</span></label>
                            <select id="iWarehouse" class="form-select form-select-sm" onchange="loadStock()">
                                <option value="">— اختر المستودع —</option>
                                <?php foreach ($warehouses as $wh): ?>
                                            <option value="<?= $wh['id'] ?>"><?= htmlspecialchars($wh['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">الجهة المستلمة <span class="req">*</span></label>
                            <input type="text" id="iDept" class="form-control form-control-sm"
                                placeholder="مثال: فرع البيع، مستودع الخياطة...">
                            <div class="mt-1">
                                <?php foreach ($DEPT_SUGGESTIONS as $d): ?>
                                            <span class="dept-chip"
                                                onclick="document.getElementById('iDept').value='<?= htmlspecialchars($d) ?>'"><?= $d ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">التاريخ <span class="req">*</span></label>
                            <input type="date" id="iDate" class="form-control form-control-sm"
                                value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-12">
                            <label class="field-lbl">ملاحظات</label>
                            <input type="text" id="iNotes" class="form-control form-control-sm" placeholder="اختياري">
                        </div>
                    </div>

                    <!-- بنود الصرف -->
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span style="font-size:.82rem;font-weight:700;color:#1e293b">
                            <i class="bi bi-list-ul me-1 text-warning"></i>مواد الصرف
                        </span>
                        <button class="btn btn-sm"
                            style="border-radius:8px;border:1px solid #f59e0b;color:#b45309;font-size:.76rem"
                            onclick="addIssueLine()"><i class="bi bi-plus me-1"></i>إضافة مادة</button>
                    </div>
                    <div class="issue-line mb-1" style="background:transparent;padding:0 8px">
                        <span style="font-size:.72rem;color:#64748b;font-weight:600">المادة</span>
                        <span style="font-size:.72rem;color:#64748b;font-weight:600">الكمية</span>
                        <span style="font-size:.72rem;color:#16a34a;font-weight:600">المتاح</span>
                        <span style="font-size:.72rem;color:#64748b;font-weight:600">ملاحظات</span>
                        <span></span>
                    </div>
                    <div id="issueLines">
                        <div id="issueEmpty" class="text-center text-muted py-3" style="font-size:.8rem">
                            <i class="bi bi-plus-circle d-block mb-1" style="font-size:1.2rem;opacity:.3"></i>
                            اضغط "إضافة مادة" لإضافة مادة للصرف
                        </div>
                    </div>

                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button class="btn btn-sm btn-light" style="border-radius:8px"
                        data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-sm fw-600"
                        style="border-radius:8px;background:#f59e0b;color:#fff;min-width:120px;border:none"
                        onclick="saveIssue()" id="btnSaveIssue">
                        <span id="saveIssueTxt"><i class="bi bi-floppy me-1"></i>حفظ كمسودة</span>
                        <span id="saveIssueSpin" class="spinner-border spinner-border-sm" style="display:none"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- مودال عرض أمر الصرف -->
    <div class="modal fade" id="viewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#b45309,#f59e0b);border-radius:16px 16px 0 0">
                    <h6 class="modal-title text-white fw-700 mb-0" id="vTitle">تفاصيل أمر الصرف</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-3" id="vBody">
                    <div class="text-center py-4"><span class="spinner-border text-warning"></span></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const sb = document.getElementById('sidebar'), ov = document.getElementById('sbOverlay');
        function sbOpen() { sb.classList.add('open'); ov.classList.add('show'); }
        function sbClose() { sb.classList.remove('open'); ov.classList.remove('show'); }
        window.addEventListener('resize', () => { if (window.innerWidth > 991) sbClose(); });
        function toggleGroup(g) { const o = g.classList.contains('open'); document.querySelectorAll('.sb-group.open').forEach(x => x.classList.remove('open')); g.classList.toggle('open', !o); localStorage.setItem('sb_open_' + g.dataset.key, (!o).toString()); }
        document.querySelectorAll('.sb-group').forEach(g => { if (localStorage.getItem('sb_open_' + g.dataset.key) === 'true') g.classList.add('open'); });

        const issueModal = new bootstrap.Modal(document.getElementById('issueModal'));
        const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
        const STATUS_MAP = <?= json_encode($STATUS_MAP) ?>;
        const ITEMS = <?= json_encode(array_values($items_list)) ?>;
        var issueLines = [];

        function post(data) {
            const fd = new FormData();
            Object.entries(data).forEach(([k, v]) => fd.append(k, v ?? ''));
            return fetch(location.href, { method: 'POST', body: fd }).then(r => r.json());
        }
        function toast(msg, type = 'success') {
            const t = document.createElement('div'); t.className = `alert alert-${type} shadow`;
            t.style.cssText = 'position:fixed;top:70px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:240px;text-align:center;font-size:.83rem;padding:.5rem 1.2rem';
            t.innerHTML = `<i class="bi bi-${type === 'success' ? 'check-circle-fill text-success' : 'exclamation-triangle-fill text-danger'} me-2"></i>${msg}`;
            document.body.appendChild(t); setTimeout(() => t.remove(), 3200);
        }

        // ── فتح مودال جديد ──
        function openNewIssue() {
            issueLines = [];
            document.getElementById('issueLines').innerHTML = '';
            document.getElementById('issueEmpty').style.display = 'block';
            document.getElementById('issueLines').appendChild(document.getElementById('issueEmpty'));
            document.getElementById('iWarehouse').value = '';
            document.getElementById('iDept').value = '';
            document.getElementById('iDate').value = new Date().toISOString().split('T')[0];
            document.getElementById('iNotes').value = '';
            issueModal.show();
        }

        // ── إضافة سطر ──
        function addIssueLine() {
            document.getElementById('issueEmpty').style.display = 'none';
            const idx = issueLines.length;
            issueLines.push({ item_id: '', qty: 1, notes: '' });

            const div = document.createElement('div');
            div.className = 'issue-line'; div.id = 'iline_' + idx;
            div.innerHTML = `
        <select onchange="onItemSelect(${idx},this.value)" class="form-select form-select-sm" style="font-size:.78rem">
            <option value="">— اختر المادة —</option>
            ${ITEMS.map(it => `<option value="${it.id}" data-unit="${it.unit}" data-stock="${parseFloat(it.stock || 0).toFixed(3)}">${it.name} (${it.unit})</option>`).join('')}
        </select>
        <input type="number" min="0.001" step="0.001" value="1" dir="ltr"
            onchange="issueLines[${idx}].qty=parseFloat(this.value)||0"
            placeholder="الكمية">
        <input type="text" class="stock" value="—" readonly placeholder="المتاح">
        <input type="text" placeholder="ملاحظات اختياري"
            onchange="issueLines[${idx}].notes=this.value">
        <button class="del-btn" onclick="removeIssueLine(${idx})"><i class="bi bi-x-lg"></i></button>`;
            document.getElementById('issueLines').appendChild(div);
        }

        function onItemSelect(idx, itemId) {
            issueLines[idx].item_id = itemId;
            const div = document.getElementById('iline_' + idx);
            const opt = div.querySelector('select').options[div.querySelector('select').selectedIndex];
            const stock = parseFloat(opt.dataset.stock || 0);
            div.querySelector('.stock').value = stock > 0 ? stock.toFixed(3) + ' ' + opt.dataset.unit : 'نفد';
            div.querySelector('.stock').style.color = stock > 0 ? '#16a34a' : '#dc2626';
        }

        function removeIssueLine(idx) {
            issueLines.splice(idx, 1);
            const div = document.getElementById('iline_' + idx); if (div) div.remove();
            if (!issueLines.length) document.getElementById('issueEmpty').style.display = 'block';
        }

        // ── حفظ ──
        function saveIssue() {
            const whId = document.getElementById('iWarehouse').value;
            const dept = document.getElementById('iDept').value.trim();
            if (!whId) { toast('يجب اختيار المستودع', 'danger'); return; }
            if (!dept) { toast('يجب تحديد الجهة المستلمة', 'danger'); return; }
            const valid = issueLines.filter(l => l.item_id && l.qty > 0);
            if (!valid.length) { toast('يجب إضافة مادة واحدة على الأقل', 'danger'); return; }

            document.getElementById('saveIssueTxt').style.opacity = '0';
            document.getElementById('saveIssueSpin').style.display = 'inline-block';

            post({
                _action: 'save_issue', warehouse_id: whId, department: dept,
                issue_date: document.getElementById('iDate').value,
                notes: document.getElementById('iNotes').value,
                rows: JSON.stringify(valid.map(l => ({ item_id: l.item_id, qty: l.qty, notes: l.notes })))
            }).then(d => {
                document.getElementById('saveIssueTxt').style.opacity = '1';
                document.getElementById('saveIssueSpin').style.display = 'none';
                if (d.ok) { toast('✅ ' + d.msg + ' — ' + d.no); issueModal.hide(); setTimeout(() => location.reload(), 800); }
                else toast(d.msg, 'danger');
            });
        }

        // ── عرض ──
        function viewIssue(id) {
            document.getElementById('vTitle').textContent = 'جارٍ التحميل...';
            document.getElementById('vBody').innerHTML = '<div class="text-center py-4"><span class="spinner-border text-warning"></span></div>';
            viewModal.show();
            post({ _action: 'get_issue', id }).then(d => {
                if (!d.ok) { document.getElementById('vBody').innerHTML = `<div class="text-danger p-3">${d.msg}</div>`; return; }
                const iss = d.data;
                const st = STATUS_MAP[iss.status] || STATUS_MAP['draft'];
                document.getElementById('vTitle').textContent = 'أمر صرف: ' + iss.issue_no;
                const itemsHtml = (iss.items || []).map(it => `<tr>
            <td>${it.item_name}</td>
            <td class="text-center">${it.unit}</td>
            <td class="n text-center fw-600">${parseFloat(it.quantity).toFixed(3)}</td>
            <td class="n text-center" style="color:#94a3b8">$ ${parseFloat(it.unit_cost_usd || 0).toFixed(4)}</td>
            <td class="n text-end fw-600">$ ${parseFloat(it.total_cost_usd || 0).toFixed(2)}</td>
            <td style="font-size:.75rem;color:#64748b">${it.notes || '—'}</td>
        </tr>`).join('');
                const total = (iss.items || []).reduce((s, it) => s + parseFloat(it.total_cost_usd || 0), 0);
                document.getElementById('vBody').innerHTML = `
        <div class="row g-2 mb-3">
            <div class="col-md-4"><small style="color:#64748b">المستودع المصدر</small><div class="fw-600">${iss.wh_name || '—'}</div></div>
            <div class="col-md-4"><small style="color:#64748b">الجهة المستلمة</small>
                <div><span class="badge bg-primary-subtle text-primary">${iss.department || '—'}</span></div></div>
            <div class="col-md-2"><small style="color:#64748b">التاريخ</small><div>${iss.issue_date}</div></div>
            <div class="col-md-2"><small style="color:#64748b">الحالة</small>
                <div><span class="badge ${st.cls}">${st.label}</span></div></div>
        </div>
        <table class="mtbl mb-3" style="font-size:.78rem">
            <thead><tr style="background:#f8fafc">
                <th>المادة</th><th class="text-center">الوحدة</th><th class="text-center">الكمية</th>
                <th class="text-center" style="color:#94a3b8">سعر الوحدة</th>
                <th class="text-end">التكلفة ($)</th><th>ملاحظات</th>
            </tr></thead>
            <tbody>${itemsHtml}</tbody>
        </table>
        <div class="d-flex justify-content-end">
            <div style="background:#f8fafc;border-radius:10px;padding:10px 16px;min-width:200px">
                <div class="d-flex justify-content-between fw-700" style="font-size:.9rem">
                    <span>إجمالي التكلفة</span>
                    <span class="n">$ ${total.toFixed(2)}</span>
                </div>
            </div>
        </div>
        ${iss.notes ? `<div style="background:#fef3c7;border-radius:8px;padding:8px 12px;margin-top:10px;font-size:.78rem;color:#92400e">${iss.notes}</div>` : ''}
        ${iss.status === 'draft' ? `<div style="margin-top:12px">
            <button class="btn btn-sm fw-600 w-100" style="border-radius:8px;background:#f59e0b;color:#fff;border:none;font-size:.8rem"
                onclick="confirmIssue(${iss.id},'${iss.issue_no}')">
                <i class="bi bi-check-circle me-1"></i>تأكيد الصرف وخصم المخزون
            </button></div>`: ''}`;
            });
        }

        // ── تأكيد ──
        function confirmIssue(id, no) {
            if (!confirm(`تأكيد صرف "${no}"؟\nسيتم خصم الكميات من المخزون.`)) return;
            post({ _action: 'confirm_issue', id }).then(d => {
                if (d.ok) { toast('✅ ' + d.msg); viewModal.hide(); setTimeout(() => location.reload(), 700); }
                else toast(d.msg, 'danger');
            });
        }

        // ── إلغاء ──
        function cancelIssue(id, no) {
            if (!confirm(`إلغاء "${no}"؟`)) return;
            post({ _action: 'cancel_issue', id }).then(d => {
                if (d.ok) { toast(d.msg); setTimeout(() => location.reload(), 700); }
                else toast(d.msg, 'danger');
            });
        }
    </script>
</body>

</html>