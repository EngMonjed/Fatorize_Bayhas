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
requirePermission('expenses.consumable_issues', 'view');
$currentModule = 'expenses.consumable_issues'; // ✅ كانت 'inventory.consumables' بالغلط (نفس مفتاح صفحة الكتالوج، مش هالصفحة)

$TS = $_SESSION['table_suffix'];
$TIS = "consumable_issues_{$TS}";
$TII = "consumable_issue_items_{$TS}";
$TU  = "consumable_units_{$TS}";
$TC  = "consumable_categories_{$TS}";
$TD  = "consumable_departments_{$TS}";
$TPK = "consumable_item_packagings_{$TS}";
$TI = "consumable_items_{$TS}";
$TST = "consumable_stock_{$TS}";
$TM = "consumable_movements_{$TS}";
$TJE = "journal_entries_{$TS}";
$TJI = "journal_entry_items_{$TS}";
$TAS = "invoice_account_settings_{$TS}";
$TAC = "account_charts_{$TS}";
$TR  = "consumable_returns_{$TS}";
$TRI = "consumable_return_items_{$TS}";
$TW = "warehouses_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// ⚠ عملة الفرع الأساسية — نعتمد على العمود الرقمي الموثوق
// (branches.base_currency_id، FK حقيقي لجدول currencies) كمصدر أساسي
// مباشر، بدل عمود branches.base_currency النصي القديم يلي لقيناه أحياناً
// فيه قيمة غير صالحة (مثلاً "1" رقم بدل رمز حقيقي زي "USD") — على
// الأغلب أثر جانبي من ترحيل هيكلة العملة. مطابقة نصية بالكود القديمة
// كانت ممكن تفشل بصمت (لو الرمز غلط، والاحتياطي ما اشتغل لأي سبب)،
// فهون منقرأ العملة مباشرة بالـ ID، بدون أي خطوة مطابقة نص وسيطة.
$branchBaseCurrency = 'USD';
$baseCurSymbol = '$';
$branchBaseCurrencyId = null;
if (!empty($_SESSION['branch_id'])) {
    $bcStmt = $pdo->prepare("SELECT c.id, c.code, c.symbol
        FROM branches b
        LEFT JOIN currencies c ON c.id = b.base_currency_id
        WHERE b.id = ?");
    $bcStmt->execute([$_SESSION['branch_id']]);
    $bcRow = $bcStmt->fetch();
    if ($bcRow && $bcRow['id']) {
        $branchBaseCurrencyId = (int) $bcRow['id'];
        $branchBaseCurrency = $bcRow['code'];
        $baseCurSymbol = $bcRow['symbol'] ?: $bcRow['code'];
    }
}
// شبكة أمان أخيرة — لو الفرع نفسه ما إله base_currency_id مضبوط أصلاً
if (!$branchBaseCurrencyId) {
    $fallbackCur = $pdo->query("SELECT id, code, symbol FROM currencies WHERE is_base=1 LIMIT 1")->fetch();
    if ($fallbackCur) {
        $branchBaseCurrencyId = (int) $fallbackCur['id'];
        $branchBaseCurrency = $fallbackCur['code'];
        $baseCurSymbol = $fallbackCur['symbol'] ?: $fallbackCur['code'];
    }
}

// ⚠ journal_entries/journal_entry_items.currency_id هلق FK رقمي حقيقي
// لجدول currencies (كان varchar(3) رمز نصي) — نفس دالة التحويل
// المستخدمة بملف consumable_purchases.php
function resolveCurrencyId(PDO $pdo, string $code): int
{
    static $cache = [];
    if (isset($cache[$code]))
        return $cache[$code];
    $st = $pdo->prepare("SELECT id FROM currencies WHERE code = ? LIMIT 1");
    $st->execute([$code]);
    $id = (int) ($st->fetchColumn() ?: 0);
    if (!$id)
        $id = (int) $pdo->query("SELECT id FROM currencies WHERE is_base=1 LIMIT 1")->fetchColumn();
    $cache[$code] = $id;
    return $id;
}
// ⚠ ترحيل فعلي لرصيد الحساب بشجرة الحسابات — نفس الدالة بالضبط
// المستخدمة بملف consumable_purchases.php (راجع تعليقها هناك للتفصيل)
function postAccountBalance(PDO $pdo, string $TAC, int $accountId, float $debit, float $credit): void
{
    $acc = $pdo->prepare("SELECT account_type, currency_id FROM `{$TAC}` WHERE id=?");
    $acc->execute([$accountId]);
    $row = $acc->fetch();
    if (!$row)
        return;
    $isDebitNormal = in_array($row['account_type'], ['asset', 'expense']);
    $delta = $isDebitNormal ? ($debit - $credit) : ($credit - $debit);
    $pdo->prepare("UPDATE `{$TAC}` SET base_balance = base_balance + ?, balance = balance + ? WHERE id=?")
        ->execute([$delta, $delta, $accountId]);
}
// ⚠ لو الاستعلام أعلاه لقى الـ ID مباشرة، ما في داعي نعيد استخراجه —
// بس منضل نستدعي الدالة كـ fallback لو أي سيناريو غير متوقع صار
if (!$branchBaseCurrencyId) {
    $branchBaseCurrencyId = resolveCurrencyId($pdo, $branchBaseCurrency);
}

function genIssueNo(PDO $pdo, string $table): string
{
    $y = date('Y');
    $last = $pdo->query("SELECT issue_no FROM `{$table}`
        WHERE issue_no LIKE 'ISS-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $seq = $last ? (int) substr($last, -4) + 1 : 1;
    return "ISS-{$y}-" . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

function genReturnNo(PDO $pdo, string $table): string
{
    $y = date('Y');
    $last = $pdo->query("SELECT return_no FROM `{$table}`
        WHERE return_no LIKE 'RET-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $seq = $last ? (int) substr($last, -4) + 1 : 1;
    return "RET-{$y}-" . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        // ── حفظ بنود أمر الصرف — دالة مشتركة بين الحفظ الجديد والتعديل ──
        if (!function_exists('saveIssueRows')) {
            function saveIssueRows(PDO $pdo, string $TII, string $TST, string $TPK, int $issId, int $whId, array $rows): void
            {
                foreach ($rows as $r) {
                    $itemId = (int) $r['item_id'];
                    $enteredQty = (float) $r['qty'];
                    if (!$itemId || $enteredQty <= 0)
                        continue;
                    $packagingId = (int) ($r['packaging_id'] ?? 0) ?: null;

                    // ⚠ نفس منطق تحويل العبوة/الوحدة المستخدم بفاتورة
                    // الشراء بالضبط (savePurchaseRows) — معامل التحويل
                    // بيتقرا من قاعدة البيانات مباشرة، لا من قيمة جاية
                    // من المتصفح، ومربوط بنفس المادة تحديداً
                    $factor = 1.0;
                    if ($packagingId) {
                        $pkSt = $pdo->prepare("SELECT qty_per_package FROM `{$TPK}` WHERE id=? AND item_id=? AND is_active=1");
                        $pkSt->execute([$packagingId, $itemId]);
                        $factor = (float) ($pkSt->fetchColumn() ?: 0);
                        if ($factor <= 0)
                            throw new Exception('عبوة غير صالحة لهذه المادة');
                    }
                    $qty = $enteredQty * $factor; // بوحدة المخزون — نفس معنى العمود quantity القديم

                    $stSt = $pdo->prepare("SELECT avg_cost_base FROM `{$TST}` WHERE item_id=? AND warehouse_id=?");
                    $stSt->execute([$itemId, $whId]);
                    $costBase = (float) ($stSt->fetchColumn() ?? 0);

                    $pdo->prepare("INSERT INTO `{$TII}` (issue_id,item_id,packaging_id,packaging_qty,quantity,unit_cost_base,total_cost_base,notes)
                        VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([
                            $issId, $itemId, $packagingId,
                            $packagingId ? $enteredQty : null, // للعرض/التدقيق بس — الكمية الأصلية كما أُدخلت بوحدة العبوة
                            $qty, $costBase, $qty * $costBase, $r['notes'] ?? ''
                        ]);
                }
            }
        }

        // ── حفظ أمر صرف ──
        if ($act === 'save_issue') {
            requirePermission('expenses.consumable_issues', 'create');
            $whId = (int) $_POST['warehouse_id'];
            $deptId = (int) ($_POST['department_id'] ?? 0) ?: null;
            $date = $_POST['issue_date'] ?? date('Y-m-d');
            $notes = trim($_POST['notes'] ?? '');
            $rows = json_decode($_POST['rows'] ?? '[]', true);

            if (!$whId)
                throw new Exception('يجب اختيار المستودع');
            if (!$deptId)
                throw new Exception('يجب تحديد الجهة المستلمة');
            if (empty($rows))
                throw new Exception('يجب إضافة مادة واحدة على الأقل');

            $issNo = genIssueNo($pdo, $TIS);
            $pdo->prepare("INSERT INTO `{$TIS}` (issue_no,warehouse_id,department_id,issue_date,status,notes,created_by)
                VALUES (?,?,?,?,?,?,?)")
                ->execute([$issNo, $whId, $deptId, $date, 'draft', $notes, $_SESSION['user_id']]);
            $issId = (int) $pdo->lastInsertId();

            saveIssueRows($pdo, $TII, $TST, $TPK, $issId, $whId, $rows);
            echo json_encode(['ok' => true, 'id' => $issId, 'no' => $issNo, 'msg' => 'تم حفظ أمر الصرف كمسودة']);
        }

        // ── إضافة جهة مستلمة جديدة (مودال سريع) ──
        elseif ($act === 'add_department') {
            requirePermission('expenses.consumable_issues', 'create');
            $name = trim($_POST['name'] ?? '');
            if (!$name)
                throw new Exception('اسم الجهة مطلوب');
            $dupSt = $pdo->prepare("SELECT id, is_active FROM `{$TD}` WHERE LOWER(TRIM(name))=LOWER(TRIM(?))");
            $dupSt->execute([$name]);
            $existing = $dupSt->fetch();
            if ($existing && (int) $existing['is_active'] === 1) {
                throw new Exception('هذه الجهة موجودة مسبقاً');
            } elseif ($existing) {
                // كانت معطّلة (soft-deleted سابقاً) — نُحييها بدل إنشاء صف جديد يتعارض مع القيد الفريد
                $pdo->prepare("UPDATE `{$TD}` SET is_active=1 WHERE id=?")->execute([$existing['id']]);
                echo json_encode(['ok' => true, 'department' => ['id' => $existing['id'], 'name' => $name]]);
            } else {
                $pdo->prepare("INSERT INTO `{$TD}` (name, created_by) VALUES (?,?)")
                    ->execute([$name, $_SESSION['user_id']]);
                $newId = (int) $pdo->lastInsertId();
                echo json_encode(['ok' => true, 'department' => ['id' => $newId, 'name' => $name]]);
            }
        }

        // ── تعديل أمر صرف (مسودة فقط) ──
        elseif ($act === 'update_issue') {
            requirePermission('expenses.consumable_issues', 'edit');
            $id = (int) ($_POST['id'] ?? 0);
            $chk = $pdo->prepare("SELECT status FROM `{$TIS}` WHERE id=?");
            $chk->execute([$id]);
            $curStatus = $chk->fetchColumn();
            if ($curStatus === false)
                throw new Exception('أمر الصرف غير موجود');
            if ($curStatus !== 'draft')
                throw new Exception('لا يمكن تعديل أمر مؤكد أو ملغى — المسودات فقط قابلة للتعديل');

            $whId = (int) ($_POST['warehouse_id'] ?? 0);
            $deptId = (int) ($_POST['department_id'] ?? 0) ?: null;
            $date = $_POST['issue_date'] ?? date('Y-m-d');
            $notes = trim($_POST['notes'] ?? '');
            $rows = json_decode($_POST['rows'] ?? '[]', true);

            if (!$whId)
                throw new Exception('يجب اختيار المستودع');
            if (!$deptId)
                throw new Exception('يجب تحديد الجهة المستلمة');
            if (empty($rows))
                throw new Exception('يجب إضافة مادة واحدة على الأقل');

            $pdo->beginTransaction();
            try {
                // ⚠ لسا مسودة (تأكدنا فوق) — بنودها ما أثّرت على المخزون
                // أو أي قيد إطلاقاً. آمن نمسحها ونعيد بناءها من الصفر
                $pdo->prepare("DELETE FROM `{$TII}` WHERE issue_id=?")->execute([$id]);

                $pdo->prepare("UPDATE `{$TIS}` SET warehouse_id=?, department_id=?, issue_date=?, notes=? WHERE id=?")
                    ->execute([$whId, $deptId, $date, $notes, $id]);

                saveIssueRows($pdo, $TII, $TST, $TPK, $id, $whId, $rows);

                $pdo->commit();
                echo json_encode(['ok' => true, 'id' => $id, 'msg' => 'تم تحديث أمر الصرف']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        // ── تأكيد أمر صرف ──
        elseif ($act === 'confirm_issue') {
            requirePermission('expenses.consumable_issues', 'edit');
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
                $totalIssueCostBase = 0.0; // لترحيل قيد محاسبي واحد يغطي كل بنود أمر الصرف
                $items = $pdo->prepare("SELECT ii.*, ci.name AS item_name
                    FROM `{$TII}` ii JOIN `{$TI}` ci ON ci.id=ii.item_id
                    WHERE ii.issue_id=?");
                $items->execute([$id]);

                foreach ($items->fetchAll() as $row) {
                    // التحقق من الكمية
                    $stSt = $pdo->prepare("SELECT quantity,avg_cost_base FROM `{$TST}` WHERE item_id=? AND warehouse_id=?");
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
                         unit_cost_base,total_cost_base,qty_before,qty_after,
                         reference_type,reference_id,movement_date,is_posted,created_by)
                        VALUES (?,?,?,'issue','out',?,?,?,?,?,?,?,?,1,?)")
                        ->execute([
                            $movNo,
                            $row['item_id'],
                            $iss['warehouse_id'],
                            $row['quantity'],
                            $st['avg_cost_base'] ?? 0,
                            $row['total_cost_base'],
                            $avail,
                            $avail - $row['quantity'],
                            'issue',
                            $id,
                            $iss['issue_date'],
                            $_SESSION['user_id']
                        ]);
                    $movId = (int) $pdo->lastInsertId();

                    // تحديث الحركة في بنود الصرف
                    $pdo->prepare("UPDATE `{$TII}` SET movement_id=?,unit_cost_base=?,total_cost_base=? WHERE id=?")
                        ->execute([$movId, $st['avg_cost_base'] ?? 0, $row['quantity'] * ($st['avg_cost_base'] ?? 0), $row['id']]);

                    // خصم من المخزون
                    $pdo->prepare("UPDATE `{$TST}` SET quantity=GREATEST(0,quantity-?),last_movement=NOW()
                        WHERE item_id=? AND warehouse_id=?")
                        ->execute([$row['quantity'], $row['item_id'], $iss['warehouse_id']]);

                    $totalIssueCostBase += $row['quantity'] * ($st['avg_cost_base'] ?? 0);
                }

                $pdo->prepare("UPDATE `{$TIS}` SET status='confirmed',is_posted=1 WHERE id=?")
                    ->execute([$id]);

                // ⚠ ترحيل محاسبي (كان غائباً بالكامل) — صرف المستهلكات للاستخدام
                // الداخلي هو تكلفة فعلية، ولازم ينعكس بقيد: مدين مصروف
                // المستهلكات / دائن مخزون المستهلكات. نفس فجوة مشتريات
                // المستهلكات، بس هون أخطر لأن is_posted كان يُكتب 1
                // بدون أي قيد فعلي مقابله.
                if ($totalIssueCostBase > 0.0000001) {
                    $accRows = $pdo->query("SELECT setting_key, account_id FROM `{$TAS}`
                        WHERE setting_key IN ('consumable_expense','consumable_inventory')")
                        ->fetchAll(PDO::FETCH_KEY_PAIR);

                    if (isset($accRows['consumable_expense'], $accRows['consumable_inventory'])) {
                        $entryNo = 'JE-' . date('Y') . '-' . str_pad(
                            (int) $pdo->query("SELECT COUNT(*)+1 FROM `{$TJE}`")->fetchColumn(),
                            4, '0', STR_PAD_LEFT
                        );
                        $pdo->prepare("INSERT INTO `{$TJE}`
                            (entry_number, entry_date, description, currency_id, exchange_rate,
                             total_debit, total_credit, status, reference_type, reference_id,
                             created_by, posted_at, posted_by)
                            VALUES (?,?,?,?,1,?,?,'posted','consumable_issue',?,?,NOW(),?)")
                            ->execute([
                                $entryNo, $iss['issue_date'],
                                'صرف مستهلكات — أمر رقم ' . $iss['issue_no'],
                                $branchBaseCurrencyId,
                                $totalIssueCostBase, $totalIssueCostBase,
                                $id, $_SESSION['user_id'], $_SESSION['user_id'],
                            ]);
                        $jeId = (int) $pdo->lastInsertId();

                        $pdo->prepare("INSERT INTO `{$TJI}`
                            (journal_entry_id, account_id, debit, credit, original_amount, base_amount, description, currency_id, exchange_rate)
                            VALUES (?,?,?,0,?,?,?,?,1)")
                            ->execute([$jeId, $accRows['consumable_expense'], $totalIssueCostBase, $totalIssueCostBase, $totalIssueCostBase, 'مصروف صرف مستهلكات', $branchBaseCurrencyId]);
                        postAccountBalance($pdo, $TAC, (int) $accRows['consumable_expense'], $totalIssueCostBase, 0);

                        $pdo->prepare("INSERT INTO `{$TJI}`
                            (journal_entry_id, account_id, debit, credit, original_amount, base_amount, description, currency_id, exchange_rate)
                            VALUES (?,?,0,?,?,?,?,?,1)")
                            ->execute([$jeId, $accRows['consumable_inventory'], $totalIssueCostBase, $totalIssueCostBase, $totalIssueCostBase, 'تخفيض مخزون المستهلكات', $branchBaseCurrencyId]);
                        postAccountBalance($pdo, $TAC, (int) $accRows['consumable_inventory'], 0, $totalIssueCostBase);
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
            requirePermission('expenses.consumable_issues', 'edit');
            $id = (int) $_POST['id'];
            $iSt = $pdo->prepare("SELECT * FROM `{$TIS}` WHERE id=?");
            $iSt->execute([$id]);
            $iss = $iSt->fetch();
            if (!$iss)
                throw new Exception('غير موجود');
            if ($iss['status'] === 'cancelled')
                throw new Exception('ملغى مسبقاً');

            $wasConfirmed = ($iss['status'] === 'confirmed');

            $pdo->beginTransaction();
            try {
                if ($wasConfirmed) {
                    // إعادة المخزون
                    $items = $pdo->prepare("SELECT * FROM `{$TII}` WHERE issue_id=?");
                    $items->execute([$id]);
                    foreach ($items->fetchAll() as $row) {
                        $pdo->prepare("UPDATE `{$TST}` SET quantity=quantity+?,last_movement=NOW()
                            WHERE item_id=? AND warehouse_id=?")
                            ->execute([$row['quantity'], $row['item_id'], $iss['warehouse_id']]);
                    }
                    $pdo->prepare("UPDATE `{$TM}` m
                        JOIN `{$TII}` ii ON ii.movement_id = m.id
                        SET m.is_posted=0, m.movement_type='return_in'
                        WHERE ii.issue_id=?")->execute([$id]);

                    // ⚠ قيد عكسي — لا نلمس/نحذف القيد الأصلي إطلاقاً، نفس
                    // مبدأ إلغاء فاتورة شراء المستهلكات بالضبط (سجل تاريخي
                    // كامل بدل حذف حدث مالي صار فعلياً)
                    $origJe = $pdo->prepare("SELECT id, total_debit FROM `{$TJE}`
                        WHERE reference_type='consumable_issue' AND reference_id=? AND status='posted'
                        ORDER BY id DESC LIMIT 1");
                    $origJe->execute([$id]);
                    $orig = $origJe->fetch();

                    if ($orig) {
                        $origLines = $pdo->prepare("SELECT account_id, debit, credit FROM `{$TJI}` WHERE journal_entry_id=?");
                        $origLines->execute([$orig['id']]);
                        $jLines = $origLines->fetchAll();

                        $revNo = 'JE-' . date('Y') . '-' . str_pad(
                            (int) $pdo->query("SELECT COUNT(*)+1 FROM `{$TJE}`")->fetchColumn(),
                            4, '0', STR_PAD_LEFT
                        );
                        $pdo->prepare("INSERT INTO `{$TJE}`
                            (entry_number, entry_date, description, currency_id, exchange_rate,
                             total_debit, total_credit, status, reference_type, reference_id,
                             created_by, posted_at, posted_by)
                            VALUES (?,?,?,?,1,?,?,'posted','consumable_issue_cancel',?,?,NOW(),?)")
                            ->execute([
                                $revNo, date('Y-m-d'),
                                'إلغاء أمر صرف مستهلكات — أمر رقم ' . $iss['issue_no'] . ' (عكس قيد ' . $orig['id'] . ')',
                                $branchBaseCurrencyId, $orig['total_debit'], $orig['total_debit'],
                                $id, $_SESSION['user_id'], $_SESSION['user_id'],
                            ]);
                        $revId = (int) $pdo->lastInsertId();

                        foreach ($jLines as $ln) {
                            $pdo->prepare("INSERT INTO `{$TJI}`
                                (journal_entry_id, account_id, debit, credit, original_amount, base_amount, description, currency_id, exchange_rate)
                                VALUES (?,?,?,?,?,?,?,?,1)")
                                ->execute([
                                    $revId, $ln['account_id'],
                                    $ln['credit'], $ln['debit'],
                                    max($ln['debit'], $ln['credit']), max($ln['debit'], $ln['credit']),
                                    'عكس — إلغاء أمر صرف مستهلكات ' . $iss['issue_no'],
                                    $branchBaseCurrencyId,
                                ]);
                            postAccountBalance($pdo, $TAC, (int) $ln['account_id'], (float) $ln['credit'], (float) $ln['debit']);
                        }
                    }
                    // لو ما في قيد أصلي (الحسابات ما كانت مضبوطة وقت
                    // التأكيد) فلا شي نعكسه — الإلغاء بيكمل عادي بدون قيد عكسي
                }

                $pdo->prepare("UPDATE `{$TIS}` SET status='cancelled' WHERE id=?")->execute([$id]);
                $pdo->commit();
                echo json_encode(['ok' => true, 'msg' => 'تم إلغاء أمر الصرف' . ($wasConfirmed ? ' وعكس المخزون والقيد المحاسبي' : '')]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        // ── جلب بيانات أمر ──
        // ── جلب بنود قابلة للإرجاع من أمر صرف مؤكّد ──
        elseif ($act === 'get_returnable') {
            requirePermission('expenses.consumable_issues', 'view');
            $id = (int) $_POST['issue_id'];
            $iSt = $pdo->prepare("SELECT i.*, w.name AS wh_name FROM `{$TIS}` i
                LEFT JOIN `{$TW}` w ON w.id=i.warehouse_id WHERE i.id=?");
            $iSt->execute([$id]);
            $iss = $iSt->fetch();
            if (!$iss)
                throw new Exception('أمر الصرف غير موجود');
            if ($iss['status'] !== 'confirmed')
                throw new Exception('الإرجاع متاح فقط للأوامر المؤكّدة');

            $items = $pdo->prepare("SELECT ii.*, ci.name AS item_name, cun.name AS unit,
                    (ii.quantity - ii.returned_qty) AS remaining
                FROM `{$TII}` ii JOIN `{$TI}` ci ON ci.id=ii.item_id
                LEFT JOIN `{$TU}` cun ON cun.id=ci.unit_id
                WHERE ii.issue_id=? AND (ii.quantity - ii.returned_qty) > 0.0001");
            $items->execute([$id]);
            $iss['returnable_items'] = $items->fetchAll();
            echo json_encode(['ok' => true, 'data' => $iss]);
        }

        // ── تنفيذ الإرجاع (حدث فوري ومكتمل، بلا مسودة — نفس فلسفة أنه واقعة حقيقية صارت الآن) ──
        elseif ($act === 'save_return') {
            requirePermission('expenses.consumable_issues', 'edit');
            $issueId = (int) ($_POST['issue_id'] ?? 0);
            $date = $_POST['return_date'] ?? date('Y-m-d');
            $notes = trim($_POST['notes'] ?? '');
            $rows = json_decode($_POST['rows'] ?? '[]', true);

            $iSt = $pdo->prepare("SELECT * FROM `{$TIS}` WHERE id=?");
            $iSt->execute([$issueId]);
            $iss = $iSt->fetch();
            if (!$iss)
                throw new Exception('أمر الصرف غير موجود');
            if ($iss['status'] !== 'confirmed')
                throw new Exception('الإرجاع متاح فقط للأوامر المؤكّدة');
            if (empty($rows))
                throw new Exception('يجب تحديد مادة واحدة على الأقل للإرجاع');

            $whId = (int) $iss['warehouse_id'];

            $pdo->beginTransaction();
            try {
                $retNo = genReturnNo($pdo, $TR);
                $pdo->prepare("INSERT INTO `{$TR}` (return_no, issue_id, warehouse_id, return_date, notes, created_by)
                    VALUES (?,?,?,?,?,?)")
                    ->execute([$retNo, $issueId, $whId, $date, $notes, $_SESSION['user_id']]);
                $returnId = (int) $pdo->lastInsertId();

                $totalReturnCostBase = 0;
                foreach ($rows as $r) {
                    $issueItemId = (int) $r['issue_item_id'];
                    $qty = (float) $r['qty'];
                    if ($qty <= 0)
                        continue;

                    // ⚠ نتحقق من البند الأصلي مباشرة (مش من المتصفح) —
                    // الكمية القابلة للإرجاع = المصروفة - المرتجعة سابقاً،
                    // وسعر التكلفة هو نفسه سعر الصرف الأصلي وقتها (مش
                    // متوسط تكلفة اليوم)، حتى يعكس القيد بالضبط قيمة
                    // المصروف الأصلي المطلوب عكسه جزئياً
                    $origSt = $pdo->prepare("SELECT * FROM `{$TII}` WHERE id=? AND issue_id=?");
                    $origSt->execute([$issueItemId, $issueId]);
                    $orig = $origSt->fetch();
                    if (!$orig)
                        throw new Exception('بند الصرف الأصلي غير موجود');
                    $remaining = (float) $orig['quantity'] - (float) $orig['returned_qty'];
                    if ($qty > $remaining + 0.0001)
                        throw new Exception('الكمية المُرجعة أكبر من المتاح للإرجاع لهذه المادة');

                    $itemId = (int) $orig['item_id'];
                    $unitCostBase = (float) $orig['unit_cost_base'];
                    $totalCostBase = $qty * $unitCostBase;
                    $totalReturnCostBase += $totalCostBase;

                    // إعادة الكمية للمخزون (نفس نمط استلام بسيط — بدون
                    // إعادة حساب المتوسط المرجّح، بما إنها نفس الدفعة
                    // يلي طلعت أصلاً وسعرها معروف بالضبط)
                    $pdo->prepare("UPDATE `{$TST}` SET quantity=quantity+?, last_movement=NOW()
                        WHERE item_id=? AND warehouse_id=?")
                        ->execute([$qty, $itemId, $whId]);

                    $movNo = 'MOV-' . date('Y') . '-' . str_pad(
                        (int) $pdo->query("SELECT COUNT(*)+1 FROM `{$TM}`")->fetchColumn(),
                        5, '0', STR_PAD_LEFT
                    );
                    $pdo->prepare("INSERT INTO `{$TM}`
                        (movement_no, item_id, warehouse_id, movement_type, direction,
                         quantity, unit_cost_base, total_cost_base, qty_before, qty_after,
                         reference_type, reference_id, movement_date, is_posted, created_by)
                        VALUES (?,?,?,'return_in','in',?,?,?,0,0,'consumable_return',?,?,1,?)")
                        ->execute([$movNo, $itemId, $whId, $qty, $unitCostBase, $totalCostBase, $returnId, $date, $_SESSION['user_id']]);
                    $movId = (int) $pdo->lastInsertId();

                    $pdo->prepare("INSERT INTO `{$TRI}` (return_id, issue_item_id, item_id, quantity, unit_cost_base, total_cost_base, movement_id, notes)
                        VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([$returnId, $issueItemId, $itemId, $qty, $unitCostBase, $totalCostBase, $movId, $r['notes'] ?? '']);

                    $pdo->prepare("UPDATE `{$TII}` SET returned_qty=returned_qty+? WHERE id=?")
                        ->execute([$qty, $issueItemId]);
                }

                // ⚠ قيد مستقل (مش عكس القيد الأصلي بالكامل) — بقيمة
                // الكمية المُرجعة فقط: مدين مخزون المستهلكات (رجع للمخزون)
                // / دائن مصروف المستهلكات (تخفيض المصروف المسجّل سابقاً)
                if ($totalReturnCostBase > 0.0000001) {
                    $accRows = $pdo->query("SELECT setting_key, account_id FROM `{$TAS}`
                        WHERE setting_key IN ('consumable_expense','consumable_inventory')")
                        ->fetchAll(PDO::FETCH_KEY_PAIR);

                    if (isset($accRows['consumable_expense'], $accRows['consumable_inventory'])) {
                        $entryNo = 'JE-' . date('Y') . '-' . str_pad(
                            (int) $pdo->query("SELECT COUNT(*)+1 FROM `{$TJE}`")->fetchColumn(),
                            4, '0', STR_PAD_LEFT
                        );
                        $pdo->prepare("INSERT INTO `{$TJE}`
                            (entry_number, entry_date, description, currency_id, exchange_rate,
                             total_debit, total_credit, status, reference_type, reference_id,
                             created_by, posted_at, posted_by)
                            VALUES (?,?,?,?,1,?,?,'posted','consumable_return',?,?,NOW(),?)")
                            ->execute([
                                $entryNo, $date,
                                'إرجاع مستهلكات — من أمر صرف رقم ' . $iss['issue_no'] . ' (سند إرجاع ' . $retNo . ')',
                                $branchBaseCurrencyId,
                                $totalReturnCostBase, $totalReturnCostBase,
                                $returnId, $_SESSION['user_id'], $_SESSION['user_id'],
                            ]);
                        $jeId = (int) $pdo->lastInsertId();

                        $pdo->prepare("INSERT INTO `{$TJI}`
                            (journal_entry_id, account_id, debit, credit, original_amount, base_amount, description, currency_id, exchange_rate)
                            VALUES (?,?,?,0,?,?,?,?,1)")
                            ->execute([$jeId, $accRows['consumable_inventory'], $totalReturnCostBase, $totalReturnCostBase, $totalReturnCostBase, 'إرجاع مستهلكات لمخزون', $branchBaseCurrencyId]);
                        postAccountBalance($pdo, $TAC, (int) $accRows['consumable_inventory'], $totalReturnCostBase, 0);

                        $pdo->prepare("INSERT INTO `{$TJI}`
                            (journal_entry_id, account_id, debit, credit, original_amount, base_amount, description, currency_id, exchange_rate)
                            VALUES (?,?,0,?,?,?,?,?,1)")
                            ->execute([$jeId, $accRows['consumable_expense'], $totalReturnCostBase, $totalReturnCostBase, $totalReturnCostBase, 'تخفيض مصروف مستهلكات (إرجاع)', $branchBaseCurrencyId]);
                        postAccountBalance($pdo, $TAC, (int) $accRows['consumable_expense'], 0, $totalReturnCostBase);

                        $pdo->prepare("UPDATE `{$TR}` SET journal_entry_id=? WHERE id=?")->execute([$jeId, $returnId]);
                    } else {
                        error_log("[consumable_issues] تعذّر ترحيل قيد الإرجاع: مفاتيح consumable_expense/consumable_inventory غير مضبوطة بـ {$TAS} (return id={$returnId})");
                    }
                }

                $pdo->commit();
                echo json_encode(['ok' => true, 'id' => $returnId, 'no' => $retNo, 'msg' => 'تم تسجيل الإرجاع بنجاح']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        // ── جلب مواد المستودع المحدد فقط (رصيد فعلي > صفر فيه تحديداً،
        // مش إجمالي كل المستودعات) — تفادي اختيار مادة رصيدها صفر
        // بهالمستودع، يلي كان بيمرّر الحفظ كمسودة بس يفشل عند التأكيد ──
        elseif ($act === 'get_warehouse_stock') {
            $whId = (int) $_POST['warehouse_id'];
            $rows = $pdo->prepare("SELECT ci.id, ci.name, cun.name AS unit,
                    COALESCE(cs.quantity, 0) AS stock
                FROM `{$TI}` ci
                LEFT JOIN `{$TST}` cs ON cs.item_id = ci.id AND cs.warehouse_id = ?
                LEFT JOIN `{$TU}` cun ON cun.id = ci.unit_id
                LEFT JOIN `{$TC}` cc ON cc.id = ci.category_id
                WHERE ci.is_active = 1 AND COALESCE(cs.quantity, 0) > 0.0001
                ORDER BY cc.sort_order, ci.name");
            $rows->execute([$whId]);
            $whItems = $rows->fetchAll();

            $pkgRows2 = $pdo->query("SELECT id, item_id, name, qty_per_package FROM `{$TPK}` WHERE is_active=1")->fetchAll();
            $pkgByItem2 = [];
            foreach ($pkgRows2 as $pk) {
                $pkgByItem2[$pk['item_id']][] = $pk;
            }
            foreach ($whItems as &$it) {
                $it['packagings'] = $pkgByItem2[$it['id']] ?? [];
            }
            unset($it);

            echo json_encode(['ok' => true, 'items' => $whItems]);
        }

        elseif ($act === 'get_issue') {
            $id = (int) $_POST['id'];
            $iSt = $pdo->prepare("SELECT i.*,w.name AS wh_name, cd.name AS department_name FROM `{$TIS}` i
                LEFT JOIN `{$TW}` w ON w.id=i.warehouse_id
                LEFT JOIN `{$TD}` cd ON cd.id=i.department_id
                WHERE i.id=?");
            $iSt->execute([$id]);
            $iss = $iSt->fetch();
            if (!$iss)
                throw new Exception('غير موجود');
            $items = $pdo->prepare("SELECT ii.*,ci.name AS item_name,cun.name AS unit,
                    pk.name AS packaging_name, pk.qty_per_package
                FROM `{$TII}` ii JOIN `{$TI}` ci ON ci.id=ii.item_id
                LEFT JOIN `{$TU}` cun ON cun.id=ci.unit_id
                LEFT JOIN `{$TPK}` pk ON pk.id=ii.packaging_id
                WHERE ii.issue_id=?");
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
    $where .= ' AND (i.issue_no LIKE ? OR cd.name LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($statusF) {
    $where .= ' AND i.status=?';
    $params[] = $statusF;
}

$stmt = $pdo->prepare("SELECT i.*,w.name AS wh_name, cd.name AS department_name,
    COUNT(ii.id) AS items_count,
    COALESCE(SUM(ii.total_cost_base),0) AS total_cost
    FROM `{$TIS}` i
    LEFT JOIN `{$TW}` w ON w.id=i.warehouse_id
    LEFT JOIN `{$TD}` cd ON cd.id=i.department_id
    LEFT JOIN `{$TII}` ii ON ii.issue_id=i.id
    {$where}
    GROUP BY i.id ORDER BY i.created_at DESC LIMIT 200");
$stmt->execute($params);
$issues = $stmt->fetchAll();

$warehouses = $pdo->query("SELECT * FROM `{$TW}` WHERE is_active=1 AND warehouse_type='consumables' ORDER BY id")->fetchAll();
$departments = $pdo->query("SELECT * FROM `{$TD}` WHERE is_active=1 ORDER BY name")->fetchAll();
$items_list = $pdo->query("SELECT ci.id,ci.name,cun.name AS unit,
    COALESCE(SUM(cs.quantity),0) AS stock
    FROM `{$TI}` ci
    LEFT JOIN `{$TST}` cs ON cs.item_id=ci.id
    LEFT JOIN `{$TU}` cun ON cun.id=ci.unit_id
    LEFT JOIN `{$TC}` cc ON cc.id=ci.category_id
    WHERE ci.is_active=1
    GROUP BY ci.id ORDER BY cc.sort_order,ci.name")->fetchAll();

// ⚠ عبوات كل مادة (كرتونة/ماعون...) — نفس نمط consumable_purchases.php
// بالضبط، حتى تصير الوحدة المختارة بالصرف قابلة للتحويل تلقائياً
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
    $stats = $pdo->query("SELECT COUNT(*) AS total,
        SUM(status='draft') AS drafts,
        SUM(status='confirmed') AS confirmed,
        COALESCE(SUM(CASE WHEN status='confirmed' THEN
            (SELECT COALESCE(SUM(total_cost_base),0) FROM `{$TII}` WHERE issue_id=id) END),0) AS total_cost
        FROM `{$TIS}`")->fetch();
} catch (Exception $e) {
    $stats = ['total' => 0, 'drafts' => 0, 'confirmed' => 0, 'total_cost' => 0];
}

$STATUS_MAP = [
    'draft' => ['label' => 'مسودة', 'cls' => 'bg-secondary-subtle text-secondary'],
    'confirmed' => ['label' => 'مصروف', 'cls' => 'bg-success-subtle text-success'],
    'cancelled' => ['label' => 'ملغى', 'cls' => 'bg-danger-subtle text-danger'],
];
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

        /* بنود الصرف — تصميم بطاقة بعناوين واضحة لكل حقل */
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

        .issue-line {
            background: #f8fafc;
            border: 1px solid #eef1f5;
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 8px
        }

        .issue-line-top {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px
        }

        .issue-line-top select.item-sel {
            flex: 1;
            min-width: 0;
            font-size: .88rem;
            font-weight: 600
        }

        .issue-line-fields {
            display: flex;
            flex-wrap: wrap;
            gap: 10px
        }

        .isf {
            display: flex;
            flex-direction: column;
            gap: 3px
        }

        .isf label {
            font-size: .68rem;
            font-weight: 700;
            color: #64748b;
            white-space: nowrap
        }

        .issue-line input,
        .issue-line select {
            font-size: .82rem;
            line-height: 1.4;
            padding: 6px 8px;
            border: 1px solid #dde3ea;
            border-radius: 7px;
            width: 100%;
            background: #fff;
            height: 38px;
            box-sizing: border-box
        }

        .issue-line input.stock {
            background: #f0fdf4;
            color: #16a34a;
            border-color: #bbf7d0;
            font-size: .74rem;
            font-weight: 600
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
                        style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-box-seam me-1"></i>المواد
                        الاستهلاكية</a>
                </li>
                <li class="nav-item"><a class="nav-link fw-600" href="consumable_purchases.php"
                        style="border:none;color:#64748b;font-size:.83rem"><i class="bi bi-cart-plus me-1"></i>فواتير
                        الشراء</a></li>
                <li class="nav-item"><a class="nav-link fw-600 active" href="#"
                        style="border:none;border-bottom:2px solid #1e3a8a;color:#1e3a8a;font-size:.83rem;margin-bottom:-2px"><i
                            class="bi bi-arrow-bar-up me-1"></i>صرف المستهلكات</a></li>
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
                <li class="nav-item"><a class="nav-link fw-600" href="../purchases/suppliers.php?tab=consumables"
                        style="border:none;color:#64748b;font-size:.83rem"><i
                            class="bi bi-people me-1"></i>موردو المستهلكات</a></li>
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
                            <div class="stat-val n"><?= htmlspecialchars($baseCurSymbol) ?> <?= number_format($stats['total_cost'], 2) ?></div>
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
                    <table class="mtbl" id="issuesTbl">
                        <thead>
                            <tr>
                                <th style="color:#1e3a8a">رقم الأمر</th>
                                <th>التاريخ</th>
                                <th style="color:#16a34a">المستودع</th>
                                <th style="color:#dc2626">الجهة المستلمة</th>
                                <th style="color:#ca8a04">المواد</th>
                                <th>التكلفة (<?= htmlspecialchars($baseCurSymbol) ?>)</th>
                                <th>الحالة</th>
                                <th style="text-align:center" data-no-sort>إجراءات</th>
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
                                            <td class="n fw-600" style="direction:ltr;color:#1e3a8a">
                                                <?= htmlspecialchars($iss['issue_no']) ?></td>
                                            <td class="text-muted"><?= $iss['issue_date'] ?></td>
                                            <td style="font-size:.8rem;color:#16a34a"><?= htmlspecialchars($iss['wh_name'] ?? '—') ?></td>
                                            <td>
                                                <span class="badge bg-danger-subtle text-danger" style="font-size:.75rem">
                                                    <i
                                                        class="bi bi-building me-1"></i><?= htmlspecialchars($iss['department_name'] ?? '—') ?>
                                                </span>
                                            </td>
                                            <td class="text-center"><span
                                                    class="badge bg-secondary-subtle text-secondary"><?= $iss['items_count'] ?>
                                                    مادة</span></td>
                                            <td class="n fw-600"><?= htmlspecialchars($baseCurSymbol) ?> <?= number_format($iss['total_cost'], 2) ?></td>
                                            <td><span class="badge <?= $st['cls'] ?>"
                                                    style="font-size:.68rem"><?= $st['label'] ?></span></td>
                                            <td>
                                                <div class="d-flex gap-1 justify-content-center">
                                                    <button class="act-btn info-h" onclick="viewIssue(<?= $iss['id'] ?>)" title="عرض">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <?php if ($iss['status'] === 'draft'): ?>
                                                                <button class="act-btn" onclick="openEditIssue(<?= $iss['id'] ?>)"
                                                                    title="تعديل" style="color:#7c3aed;border-color:#ddd6fe">
                                                                    <i class="bi bi-pencil"></i>
                                                                </button>
                                                                <button class="act-btn success-h"
                                                                    onclick="confirmIssue(<?= $iss['id'] ?>, '<?= htmlspecialchars($iss['issue_no'], ENT_QUOTES) ?>')"
                                                                    title="تأكيد الصرف">
                                                                    <i class="bi bi-check-circle"></i>
                                                                </button>
                                                    <?php endif; ?>
                                                    <?php if ($iss['status'] === 'confirmed'): ?>
                                                                <button class="act-btn" onclick="openReturnModal(<?= $iss['id'] ?>)"
                                                                    title="إرجاع" style="color:#0891b2;border-color:#a5f3fc">
                                                                    <i class="bi bi-arrow-return-left"></i>
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
        <div class="modal-dialog modal-xl" style="max-width:1100px">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#b45309,#f59e0b);border-radius:16px 16px 0 0">
                    <h6 class="modal-title text-white fw-700 mb-0" id="issueModalTitle"><i class="bi bi-arrow-bar-up me-2"></i>أمر صرف
                        مستهلكات جديد</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pt-3">

                    <div class="row g-3 mb-3 pb-3" style="border-bottom:1px solid #f1f5f9">
                        <div class="col-md-4">
                            <label class="field-lbl">المستودع المصدر <span class="req">*</span></label>
                            <select id="iWarehouse" class="form-select form-select-sm" onchange="onIssueWarehouseChange()">
                                <option value="">— اختر المستودع —</option>
                                <?php foreach ($warehouses as $wh): ?>
                                            <option value="<?= $wh['id'] ?>"><?= htmlspecialchars($wh['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">الجهة المستلمة <span class="req">*</span></label>
                            <div class="d-flex gap-1">
                                <select id="iDept" class="form-select form-select-sm">
                                    <option value="">— اختر —</option>
                                    <?php foreach ($departments as $d): ?>
                                                <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-sm btn-outline-secondary" title="جهة جديدة"
                                    style="border-radius:8px" onclick="openDeptModal()">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
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
                        <button class="btn btn-sm" id="btnAddIssueLine"
                            style="border-radius:8px;border:1px solid #f59e0b;color:#b45309;font-size:.76rem"
                            onclick="addIssueLine()" disabled><i class="bi bi-plus me-1"></i>إضافة مادة</button>
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

    <!-- مودال إضافة جهة مستلمة سريع -->
    <div class="modal fade" id="deptModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#7c3aed,#5b21b6);border-radius:16px 16px 0 0">
                    <h6 class="modal-title text-white fw-700 mb-0">جهة مستلمة جديدة</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pt-3">
                    <label class="field-lbl">اسم الجهة <span class="req">*</span></label>
                    <input type="text" id="dName" class="form-control form-control-sm" placeholder="مثال: مستودع الخياطة">
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-sm fw-600" style="border-radius:8px;background:#1e3a8a;color:#fff;min-width:90px"
                        onclick="saveDept()">حفظ</button>
                </div>
            </div>
        </div>
    </div>

    <!-- مودال عرض أمر الصرف -->
    <div class="modal fade" id="viewModal" tabindex="-1">
        <div class="modal-dialog modal-xl" style="max-width:1000px">
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

    <!-- مودال الإرجاع -->
    <div class="modal fade" id="returnModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#0891b2,#0e7490);border-radius:16px 16px 0 0">
                    <h6 class="modal-title text-white fw-700 mb-0"><i class="bi bi-arrow-return-left me-2"></i>إرجاع
                        مستهلكات — <span id="rIssueNo"></span></h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-3">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="field-lbl">تاريخ الإرجاع <span class="req">*</span></label>
                            <input type="date" id="rDate" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="field-lbl">ملاحظات (اختياري)</label>
                            <input type="text" id="rNotes" class="form-control form-control-sm" placeholder="سبب الإرجاع">
                        </div>
                    </div>
                    <div style="font-size:.82rem;font-weight:700;color:#1e293b;margin-bottom:8px">
                        <i class="bi bi-list-ul me-1 text-info"></i>المواد القابلة للإرجاع
                    </div>
                    <div id="returnItems"></div>
                    <div id="returnEmpty" class="text-center text-muted py-3" style="font-size:.8rem">جارٍ التحميل...</div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-sm fw-600" id="btnSaveReturn"
                        style="border-radius:8px;background:#0891b2;color:#fff;min-width:130px" onclick="saveReturn()">
                        <span id="saveReturnTxt"><i class="bi bi-floppy me-1"></i>تنفيذ الإرجاع</span>
                        <span id="saveReturnSpin" class="spinner-border spinner-border-sm" style="display:none"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= BASE_PATH ?>/assets/js/sidebar.js"></script>
    <script>
        const issueModal = new bootstrap.Modal(document.getElementById('issueModal'));
        // ⚠ نخزّن مرجع العنصر مرة وحدة هون — لأنه innerHTML='' على الحاوية
        // بيمحي العنصر من الشجرة، وبعدها getElementById('issueEmpty')
        // برجع null (نفس بق حذف السطر: مرجع مباشر أضمن من إعادة البحث
        // بالـ ID بعد ما يكون العنصر انمحى فعلياً من الصفحة)
        const issueEmptyEl = document.getElementById('issueEmpty');
        const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
        const deptModal = new bootstrap.Modal(document.getElementById('deptModal'));
        const returnModal = new bootstrap.Modal(document.getElementById('returnModal'));
        let currentReturnIssueId = null;
        const STATUS_MAP = <?= json_encode($STATUS_MAP) ?>;
        const ITEMS = <?= json_encode(array_values($items_list)) ?>;
        // ⚠ ITEMS أعلاه إجمالي كل المستودعات — يستخدم بس لأغراض عامة
        // (مثل مودال الإرجاع، يلي بيشتغل على بنود موجودة أصلاً بالأمر).
        // لبناء أسطر أمر صرف جديد، لازم نستخدم warehouseItems (رصيد
        // المستودع المُختار تحديداً)، حتى ما يقدر المستخدم يختار مادة
        // رصيدها صفر بهالمستودع بالذات
        let warehouseItems = [];
        const BASE_CUR_SYM = <?= json_encode($baseCurSymbol) ?>;
        var issueLines = [];
        var editingIssueId = null; // null = أمر جديد، رقم = تعديل مسودة موجودة

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
            editingIssueId = null;
            issueLines = [];
            warehouseItems = [];
            document.getElementById('issueLines').innerHTML = '';
            issueEmptyEl.style.display = 'block';
            issueEmptyEl.textContent = 'اختر المستودع أولاً حتى تظهر المواد المتاحة فيه';
            document.getElementById('issueLines').appendChild(issueEmptyEl);
            document.getElementById('iWarehouse').value = '';
            document.getElementById('iDept').value = '';
            document.getElementById('iDate').value = new Date().toISOString().split('T')[0];
            document.getElementById('iNotes').value = '';
            document.getElementById('btnAddIssueLine').disabled = true;
            document.getElementById('issueModalTitle').innerHTML = '<i class="bi bi-arrow-bar-up me-2"></i>أمر صرف مستهلكات جديد';
            document.getElementById('saveIssueTxt').innerHTML = '<i class="bi bi-floppy me-1"></i>حفظ كمسودة';
            issueModal.show();
        }

        // ⚠ لما يتغيّر المستودع، نجيب رصيده الفعلي فقط (مش إجمالي كل
        // المستودعات) — يمنع اختيار مادة رصيدها صفر بهالمستودع تحديداً،
        // وهاد بالضبط سبب فشل التأكيد يلي كان يصير قبل هالإصلاح
        function onIssueWarehouseChange() {
            const whId = document.getElementById('iWarehouse').value;
            issueLines = [];
            document.getElementById('issueLines').innerHTML = '';
            if (!whId) {
                warehouseItems = [];
                document.getElementById('btnAddIssueLine').disabled = true;
                issueEmptyEl.style.display = 'block';
                issueEmptyEl.textContent = 'اختر المستودع أولاً حتى تظهر المواد المتاحة فيه';
                document.getElementById('issueLines').appendChild(issueEmptyEl);
                return;
            }
            post({ _action: 'get_warehouse_stock', warehouse_id: whId }).then(d => {
                warehouseItems = d.items || [];
                document.getElementById('btnAddIssueLine').disabled = warehouseItems.length === 0;
                issueEmptyEl.textContent = warehouseItems.length
                    ? 'اضغط "إضافة مادة" لإضافة مادة من رصيد هذا المستودع'
                    : 'لا توجد أي مادة برصيد فعلي بهذا المستودع';
                issueEmptyEl.style.display = 'block';
                document.getElementById('issueLines').appendChild(issueEmptyEl);
            });
        }

        // ⚠ تعديل مسودة موجودة — مسموح للمسودات فقط (نفس الشرط بالسيرفر)
        function openEditIssue(id) {
            post({ _action: 'get_issue', id }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                const iss = d.data;
                if (iss.status !== 'draft') { toast('لا يمكن تعديل أمر مؤكد أو ملغى', 'danger'); return; }

                editingIssueId = id;
                issueLines = [];
                document.getElementById('issueLines').innerHTML = '';
                document.getElementById('iWarehouse').value = iss.warehouse_id || '';
                document.getElementById('iDept').value = iss.department_id || '';
                document.getElementById('iDate').value = iss.issue_date;
                document.getElementById('iNotes').value = iss.notes || '';

                // ⚠ لازم نجيب رصيد هالمستودع تحديداً أولاً (نفس مصدر
                // قائمة المواد المستخدمة بالإنشاء الجديد)، قبل ما نبني
                // الأسطر — وإلا خيارات القائمة المنسدلة تضل فاضية
                post({ _action: 'get_warehouse_stock', warehouse_id: iss.warehouse_id }).then(dw => {
                    warehouseItems = dw.items || [];
                    document.getElementById('btnAddIssueLine').disabled = warehouseItems.length === 0;

                    (iss.items || []).forEach(it => {
                        issueEmptyEl.style.display = 'none';
                        // ⚠ نسترجع الكمية كما أُدخلت أصلاً بوحدة العبوة (لو
                        // كانت محددة)، مش بوحدة المخزون المحفوظة بقاعدة
                        // البيانات — نفس منطق فاتورة المشتريات بالضبط
                        const enteredQty = it.packaging_id ? parseFloat(it.packaging_qty) : parseFloat(it.quantity);
                        const lineData = {
                            item_id: String(it.item_id),
                            packaging_id: it.packaging_id ? String(it.packaging_id) : '',
                            qty: enteredQty,
                            notes: it.notes || ''
                        };
                        issueLines.push(lineData);
                        buildIssueLineDOM(lineData, false);
                    });
                    if (!issueLines.length) issueEmptyEl.style.display = 'block';

                    document.getElementById('issueModalTitle').innerHTML = '<i class="bi bi-arrow-bar-up me-2"></i>تعديل أمر صرف رقم ' + iss.issue_no;
                    document.getElementById('saveIssueTxt').innerHTML = '<i class="bi bi-floppy me-1"></i>حفظ التعديلات';
                    issueModal.show();
                });
            });
        }

        // ── بناء سطر واحد (مادة/كمية/متاح/ملاحظات) ──
        // ⚠ الأحداث هلق مربوطة عبر addEventListener بمرجع مباشر لعنصر
        // بيانات السطر (lineData)، مش عبر رقم index مكتوب حرفياً جوا
        // نص الـ HTML وقت الإنشاء — قبل هالإصلاح، حذف سطر من نص القائمة
        // كان يخلّي كل الأسطر يلي بعده تكتب بيانتها بمكان غلط بالمصفوفة
        // (لأن index كل عنصر كان يضل ثابت بالـ HTML حتى لو انزاح موقعه
        // الفعلي بالمصفوفة بعد الحذف).
        function buildIssueLineDOM(lineData, isNew = true) {
            const div = document.createElement('div');
            div.className = 'issue-line';

            div.innerHTML = `
    <div class="issue-line-top">
      <select class="item-sel">
        <option value="">— اختر المادة —</option>
        ${warehouseItems.map(it => `<option value="${it.id}" data-unit="${it.unit}" data-stock="${parseFloat(it.stock || 0).toFixed(3)}">${it.name} (${it.unit})</option>`).join('')}
      </select>
      <button class="del-btn" type="button" title="حذف السطر"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="issue-line-fields">
      <div class="isf" style="width:230px">
        <label>الوحدة / العبوة</label>
        <select class="unit-sel" disabled>
          <option value="">وحدة المادة</option>
        </select>
      </div>
      <div class="isf" style="width:100px">
        <label>الكمية</label>
        <input type="number" min="0.001" step="0.001" value="1" dir="ltr" class="qty-in">
      </div>
      <div class="isf" style="width:190px">
        <label>المتاح بالمستودع</label>
        <input type="text" class="stock" value="—" readonly>
      </div>
      <div class="isf" style="flex:1;min-width:180px">
        <label>ملاحظات (اختياري)</label>
        <input type="text" class="notes-in" placeholder="مثال: سبب الصرف">
      </div>
    </div>`;

            const selEl = div.querySelector('.item-sel');
            const unitSelEl = div.querySelector('.unit-sel');
            const qtyEl = div.querySelector('.qty-in');
            const notesEl = div.querySelector('.notes-in');
            const stockEl = div.querySelector('.stock');
            const delBtn = div.querySelector('.del-btn');

            // ⚠ "المتاح" هلق بيعرض الرصيد بوحدة المخزون الأساسية *و*
            // مكافئه بالعبوة المختارة (لو في وحدة/عبوة مختارة) — مثال:
            // "1000.000 غرام (≈ 4.00 كيلو)" — حتى ما يحتاج المستخدم
            // يحسب بنفسه قديش عبوة متاحة فعلياً بالمخزون
            function updateStockDisplay() {
                const opt = selEl.options[selEl.selectedIndex];
                const stock = parseFloat(opt?.dataset.stock || 0);
                if (!selEl.value) { stockEl.value = '—'; stockEl.style.color = ''; return; }
                let text = stock > 0 ? stock.toFixed(3) + ' ' + opt.dataset.unit : 'نفد';
                const uOpt = unitSelEl.options[unitSelEl.selectedIndex];
                const factor = parseFloat(uOpt?.dataset.factor) || 1;
                if (factor !== 1 && stock > 0) {
                    text += ` (≈ ${(stock / factor).toFixed(2)} ${uOpt.textContent.split(' (')[0]})`;
                }
                stockEl.value = text;
                stockEl.style.color = stock > 0 ? '#16a34a' : '#dc2626';
            }

            function rebuildUnitOptions() {
                const it = warehouseItems.find(x => x.id == lineData.item_id);
                if (!it) {
                    unitSelEl.disabled = true;
                    unitSelEl.innerHTML = '<option value="">وحدة المادة</option>';
                    return;
                }
                const pkgs = it.packagings || [];
                unitSelEl.disabled = false;
                unitSelEl.innerHTML = `<option value="" data-factor="1">${it.unit} (وحدة المخزون)</option>` +
                    pkgs.map(pk => `<option value="${pk.id}" data-factor="${pk.qty_per_package}">${pk.name} (= ${parseFloat(pk.qty_per_package).toFixed(2)} ${it.unit})</option>`).join('');
                unitSelEl.value = lineData.packaging_id || '';
            }

            // استرجاع القيم لو كنا نبني سطر موجود مسبقاً (بعد حذف سطر تاني، أو تعديل مسودة)
            selEl.value = lineData.item_id || '';
            qtyEl.value = lineData.qty || 1;
            notesEl.value = lineData.notes || '';
            if (lineData.item_id) { rebuildUnitOptions(); updateStockDisplay(); }

            selEl.addEventListener('change', function () {
                lineData.item_id = this.value;
                lineData.packaging_id = '';
                rebuildUnitOptions();
                updateStockDisplay();
            });
            unitSelEl.addEventListener('change', function () {
                lineData.packaging_id = this.value || '';
                updateStockDisplay();
            });
            qtyEl.addEventListener('input', function () { lineData.qty = parseFloat(this.value) || 0; });
            notesEl.addEventListener('input', function () { lineData.notes = this.value; });
            delBtn.addEventListener('click', function () { removeIssueLine(lineData); });

            div._lineData = lineData;

            // ⚠ نفس آلية إدراج بند فاتورة الشراء بالضبط — سطر جديد
            // يظهر فوق (جنب زر "إضافة مادة")، مع تظليل مؤقت وتركيز
            // تلقائي على حقل اختيار المادة. بس وقت الإضافة الفعلية
            // (isNew=true)، مش أثناء استرجاع أسطر موجودة مسبقاً بوضع
            // التعديل (isNew=false) — حتى يضل الترتيب الأصلي محفوظ
            const wrap = document.getElementById('issueLines');
            if (isNew) {
                wrap.prepend(div);
                div.style.animation = 'newRowHighlight 900ms ease-out';
                setTimeout(() => { div.style.animation = ''; }, 900);
                setTimeout(() => selEl.focus(), 50);
            } else {
                wrap.appendChild(div);
            }
        }

        // ── إضافة سطر ──
        function addIssueLine() {
            issueEmptyEl.style.display = 'none';
            const lineData = { item_id: '', packaging_id: '', qty: 1, notes: '' };
            issueLines.push(lineData);
            buildIssueLineDOM(lineData, true);
        }

        // ⚠ الحذف هلق بيشتغل بمرجع الكائن نفسه (lineData)، مش برقمه —
        // فمافي خطر انزياح index بعد الحذف، وما عاد في داعي لإعادة بناء
        // كل الأسطر التانية أصلاً (بعكس فاتورة المشتريات، هون التصميم
        // الجديد بسيط بما فيه الكفاية إنه الحذف المباشر آمن ١٠٠٪)
        function removeIssueLine(lineData) {
            const idx = issueLines.indexOf(lineData);
            if (idx === -1) return;
            issueLines.splice(idx, 1);
            document.querySelectorAll('#issueLines .issue-line').forEach(div => {
                if (div._lineData === lineData) div.remove();
            });
            if (!issueLines.length) issueEmptyEl.style.display = 'block';
        }

        // ── جهة مستلمة جديدة (مودال سريع فوق مودال أمر الصرف) ──
        function openDeptModal() {
            document.getElementById('dName').value = '';
            deptModal.show();
        }

        function saveDept() {
            const name = document.getElementById('dName').value.trim();
            if (!name) { toast('اسم الجهة مطلوب', 'danger'); return; }
            post({ _action: 'add_department', name }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                const sel = document.getElementById('iDept');
                const opt = document.createElement('option');
                opt.value = d.department.id;
                opt.textContent = d.department.name;
                opt.selected = true;
                sel.appendChild(opt);
                deptModal.hide();
                toast('تمت إضافة الجهة');
            });
        }

        // ── حفظ ──
        function saveIssue() {
            const whId = document.getElementById('iWarehouse').value;
            const deptId = document.getElementById('iDept').value;
            if (!whId) { toast('يجب اختيار المستودع', 'danger'); return; }
            if (!deptId) { toast('يجب تحديد الجهة المستلمة', 'danger'); return; }
            const valid = issueLines.filter(l => l.item_id && l.qty > 0);
            if (!valid.length) { toast('يجب إضافة مادة واحدة على الأقل', 'danger'); return; }

            document.getElementById('saveIssueTxt').style.opacity = '0';
            document.getElementById('saveIssueSpin').style.display = 'inline-block';

            const payload = {
                _action: editingIssueId ? 'update_issue' : 'save_issue',
                warehouse_id: whId, department_id: deptId,
                issue_date: document.getElementById('iDate').value,
                notes: document.getElementById('iNotes').value,
                rows: JSON.stringify(valid.map(l => ({ item_id: l.item_id, packaging_id: l.packaging_id || '', qty: l.qty, notes: l.notes })))
            };
            if (editingIssueId) payload.id = editingIssueId;

            post(payload).then(d => {
                document.getElementById('saveIssueTxt').style.opacity = '1';
                document.getElementById('saveIssueSpin').style.display = 'none';
                if (d.ok) {
                    toast('✅ ' + d.msg + (d.no ? ' — ' + d.no : ''));
                    editingIssueId = null;
                    issueModal.hide();
                    setTimeout(() => location.reload(), 800);
                } else toast(d.msg, 'danger');
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
                const itemsHtml = (iss.items || []).map(it => {
                    const qtyLabel = it.packaging_name
                        ? `${parseFloat(it.packaging_qty).toFixed(2)} ${it.packaging_name} <small style="color:#94a3b8">(=${parseFloat(it.quantity).toFixed(3)} ${it.unit})</small>`
                        : `${parseFloat(it.quantity).toFixed(3)}`;
                    return `<tr>
            <td style="white-space:nowrap">${it.item_name}</td>
            <td class="text-center" style="white-space:nowrap">${it.unit}</td>
            <td class="n text-center fw-600" style="white-space:nowrap">${qtyLabel}</td>
            <td class="n text-center" style="color:#94a3b8;white-space:nowrap">${BASE_CUR_SYM} ${parseFloat(it.unit_cost_base || 0).toFixed(4)}</td>
            <td class="n text-end fw-600" style="white-space:nowrap">${BASE_CUR_SYM} ${parseFloat(it.total_cost_base || 0).toFixed(2)}</td>
            <td style="font-size:.75rem;color:#64748b">${it.notes || '—'}</td>
        </tr>`;
                }).join('');
                const total = (iss.items || []).reduce((s, it) => s + parseFloat(it.total_cost_base || 0), 0);
                document.getElementById('vBody').innerHTML = `
        <div class="row g-2 mb-3">
            <div class="col-md-4"><small style="color:#64748b">المستودع المصدر</small><div class="fw-600">${iss.wh_name || '—'}</div></div>
            <div class="col-md-4"><small style="color:#64748b">الجهة المستلمة</small>
                <div><span class="badge bg-primary-subtle text-primary">${iss.department_name || '—'}</span></div></div>
            <div class="col-md-2"><small style="color:#64748b">التاريخ</small><div>${iss.issue_date}</div></div>
            <div class="col-md-2"><small style="color:#64748b">الحالة</small>
                <div><span class="badge ${st.cls}">${st.label}</span></div></div>
        </div>
        <div class="table-responsive">
        <table class="mtbl mb-3" style="font-size:.78rem">
            <thead><tr style="background:#f8fafc">
                <th>المادة</th><th class="text-center">الوحدة</th><th class="text-center">الكمية</th>
                <th class="text-center" style="color:#94a3b8">متوسط تكلفة الوحدة (<?= htmlspecialchars($baseCurSymbol) ?>)</th>
                <th class="text-end">الإجمالي (<?= htmlspecialchars($baseCurSymbol) ?>)</th><th>ملاحظات</th>
            </tr></thead>
            <tbody>${itemsHtml}</tbody>
        </table>
        </div>
        <div class="d-flex justify-content-end">
            <div style="background:#f8fafc;border-radius:10px;padding:10px 16px;min-width:200px">
                <div class="d-flex justify-content-between fw-700" style="font-size:.9rem">
                    <span>إجمالي التكلفة</span>
                    <span class="n">${BASE_CUR_SYM} ${total.toFixed(2)}</span>
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

        // ── إرجاع (منفصل تماماً عن الإلغاء — كمية جزئية أو كاملة من أمر مؤكّد) ──
        function openReturnModal(issueId) {
            currentReturnIssueId = issueId;
            document.getElementById('rDate').value = new Date().toISOString().split('T')[0];
            document.getElementById('rNotes').value = '';
            document.getElementById('returnItems').innerHTML = '';
            document.getElementById('returnEmpty').style.display = 'block';
            document.getElementById('returnEmpty').textContent = 'جارٍ التحميل...';
            document.getElementById('rIssueNo').textContent = '';
            returnModal.show();

            post({ _action: 'get_returnable', issue_id: issueId }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); returnModal.hide(); return; }
                const iss = d.data;
                document.getElementById('rIssueNo').textContent = iss.issue_no;
                const items = iss.returnable_items || [];
                if (!items.length) {
                    document.getElementById('returnEmpty').textContent = 'لا توجد كميات متبقية قابلة للإرجاع بهذا الأمر';
                    return;
                }
                document.getElementById('returnEmpty').style.display = 'none';
                document.getElementById('returnItems').innerHTML = items.map(it => `
        <div class="row g-2 align-items-center mb-2 return-item-row" data-issue-item-id="${it.id}" data-max="${parseFloat(it.remaining)}">
            <div class="col-5">
                <div class="fw-600" style="font-size:.85rem">${it.item_name}</div>
                <small style="color:#64748b">المتاح للإرجاع: ${parseFloat(it.remaining).toFixed(3)} ${it.unit}</small>
            </div>
            <div class="col-3">
                <input type="number" class="form-control form-control-sm ret-qty" min="0" max="${parseFloat(it.remaining)}"
                    step="0.001" placeholder="الكمية" dir="ltr">
            </div>
            <div class="col-4">
                <input type="text" class="form-control form-control-sm ret-notes" placeholder="ملاحظات اختياري">
            </div>
        </div>`).join('');
            });
        }

        function saveReturn() {
            const rows = [];
            document.querySelectorAll('.return-item-row').forEach(row => {
                const qty = parseFloat(row.querySelector('.ret-qty').value) || 0;
                const max = parseFloat(row.dataset.max);
                if (qty > 0) {
                    rows.push({
                        issue_item_id: row.dataset.issueItemId,
                        qty: Math.min(qty, max),
                        notes: row.querySelector('.ret-notes').value
                    });
                }
            });
            if (!rows.length) { toast('حدّد كمية إرجاع لمادة واحدة على الأقل', 'danger'); return; }

            document.getElementById('saveReturnTxt').style.opacity = '0';
            document.getElementById('saveReturnSpin').style.display = 'inline-block';

            post({
                _action: 'save_return',
                issue_id: currentReturnIssueId,
                return_date: document.getElementById('rDate').value,
                notes: document.getElementById('rNotes').value,
                rows: JSON.stringify(rows)
            }).then(d => {
                document.getElementById('saveReturnTxt').style.opacity = '1';
                document.getElementById('saveReturnSpin').style.display = 'none';
                if (d.ok) {
                    toast('✅ ' + d.msg + ' — ' + d.no);
                    returnModal.hide();
                    setTimeout(() => location.reload(), 800);
                } else toast(d.msg, 'danger');
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

        makeSortable(document.getElementById('issuesTbl'));
    </script>
</body>

</html>