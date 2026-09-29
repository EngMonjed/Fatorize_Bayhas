<?php
/**
 * accounting/partners.php — الشركاء والمالك (رأس مال / مسحوبات / قروض)
 * المسار: retail1/modules/accounting/partners.php
 *
 * المبادئ (متّسقة مع باقي قسم المالية):
 * - كل حركة = قيد محاسبي مسودة (مدين = دائن) يترحّل بزر "ترحيل" (صلاحية confirm)
 *   ويُلغى بعكس الأرصدة + تعليم القيد ملغى (نفس سندات القبض/الدفع).
 * - net = debit − credit موحَّد لكل أنواع الحسابات عند تحديث base_balance
 *   (حقوق الملكية والالتزامات بتطلع سالبة خاماً — والعرض بيعكسها).
 * - currency_id (FK رقمي) دايماً — لا عمود currency نصي.
 * - المسحوبات مو مصروف: حساب حقوق ملكية (مقابل)، ما بتأثر على الأرباح والخسائر.
 * - حسابات الشريك (رأس مال/مسحوبات) تتولّد تلقائياً وقت إضافته تحت الحسابات
 *   الأب المضبوطة بإعدادات الربط؛ حسابات القروض تتولّد عند أول قرض فعلي (lazy).
 * - القروض: رصيد السداد لا يتجاوز القرض القائم (تحقق سيرفر).
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('finance.partners', 'view');
$currentModule = 'finance.partners';

$TS = $_SESSION['table_suffix'];
$TP = "partners_{$TS}";
$TPT = "partner_transactions_{$TS}";
$TAC = "account_charts_{$TS}";
$TIAS = "invoice_account_settings_{$TS}";
$TJE = "journal_entries_{$TS}";
$TJI = "journal_entry_items_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// ══════════════════ تعريفات ثابتة ══════════════════
// dir: in = الفلوس تدخل الصندوق/البنك (مدين)، out = تطلع منه (دائن).
// الطرف الآخر دايماً العكس. acct = دور حساب الشريك المتأثر.
function txnTypes(): array
{
    return [
        'capital_in' => ['label' => 'مدخول رأس مال', 'group' => 'حقوق ملكية', 'dir' => 'in', 'acct' => 'capital',
            'preview' => 'مدين: الصندوق/البنك ← دائن: رأس مال الشريك'],
        'drawing' => ['label' => 'مسحوبات', 'group' => 'حقوق ملكية', 'dir' => 'out', 'acct' => 'drawings',
            'preview' => 'مدين: مسحوبات الشريك (حقوق ملكية — مو مصروف) ← دائن: الصندوق/البنك'],
        'loan_in' => ['label' => 'استلام قرض من الشريك', 'group' => 'قرض من الشريك للشركة', 'dir' => 'in', 'acct' => 'loan_payable',
            'preview' => 'مدين: الصندوق/البنك ← دائن: قرض من الشريك (التزام)'],
        'loan_repay' => ['label' => 'سداد قرض للشريك', 'group' => 'قرض من الشريك للشركة', 'dir' => 'out', 'acct' => 'loan_payable',
            'preview' => 'مدين: قرض من الشريك (التزام) ← دائن: الصندوق/البنك'],
        'loan_out' => ['label' => 'إعطاء قرض للشريك', 'group' => 'قرض من الشركة للشريك', 'dir' => 'out', 'acct' => 'loan_receivable',
            'preview' => 'مدين: قرض إلى الشريك (أصل) ← دائن: الصندوق/البنك'],
        'loan_out_repay' => ['label' => 'سداد الشريك لقرضه', 'group' => 'قرض من الشركة للشريك', 'dir' => 'in', 'acct' => 'loan_receivable',
            'preview' => 'مدين: الصندوق/البنك ← دائن: قرض إلى الشريك (أصل)'],
    ];
}

function acctRoles(): array
{
    return [
        'capital' => ['col' => 'capital_account_id', 'parent' => 'partner_capital_parent', 'type' => 'equity', 'prefix' => 'رأس مال — ', 'parent_lbl' => 'رأس مال الشركاء'],
        'drawings' => ['col' => 'drawings_account_id', 'parent' => 'partner_drawings_parent', 'type' => 'equity', 'prefix' => 'مسحوبات — ', 'parent_lbl' => 'مسحوبات الشركاء'],
        'loan_payable' => ['col' => 'loan_payable_account_id', 'parent' => 'partner_loan_payable_parent', 'type' => 'liability', 'prefix' => 'قرض من — ', 'parent_lbl' => 'قروض من الشركاء'],
        'loan_receivable' => ['col' => 'loan_receivable_account_id', 'parent' => 'partner_loan_receivable_parent', 'type' => 'asset', 'prefix' => 'قرض إلى — ', 'parent_lbl' => 'قروض للشركاء'],
    ];
}

function getSettingAccount(PDO $pdo, string $tias, string $key, string $tac): ?array
{
    $st = $pdo->prepare("SELECT ac.* FROM `{$tias}` i JOIN `{$tac}` ac ON ac.id=i.account_id
        WHERE i.setting_key=? AND ac.is_active=1 LIMIT 1");
    $st->execute([$key]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function genEntryNo(PDO $pdo, string $TJE): string
{
    $y = date('Y');
    $st = $pdo->prepare("SELECT entry_number FROM `{$TJE}` WHERE entry_number LIKE ? ORDER BY id DESC LIMIT 1");
    $st->execute(["JE-{$y}-%"]);
    $last = $st->fetchColumn();
    $n = $last ? ((int) substr($last, -4)) + 1 : 1;
    return "JE-{$y}-" . str_pad($n, 4, '0', STR_PAD_LEFT);
}

function genTxnNo(PDO $pdo, string $TPT): string
{
    $y = date('Y');
    $st = $pdo->prepare("SELECT txn_number FROM `{$TPT}` WHERE txn_number LIKE ? ORDER BY id DESC LIMIT 1");
    $st->execute(["PTX-{$y}-%"]);
    $last = $st->fetchColumn();
    $n = $last ? ((int) substr($last, -4)) + 1 : 1;
    return "PTX-{$y}-" . str_pad($n, 4, '0', STR_PAD_LEFT);
}

// إنشاء حساب فرعي مخصص للشريك تحت الحساب الأب المضبوط بالإعدادات
function createPartnerAccount(PDO $pdo, string $TAC, string $TIAS, string $role, string $partnerName, int $baseCurId): int
{
    $cfg = acctRoles()[$role];
    $parent = getSettingAccount($pdo, $TIAS, $cfg['parent'], $TAC);
    if (!$parent)
        throw new Exception("اضبط حساب \"{$cfg['parent_lbl']}\" (حساب أب) أولاً من صفحة إعدادات الربط المحاسبي — مجموعة \"الشركاء والمالك\"");
    $cnt = (int) $pdo->query("SELECT COUNT(*) FROM `{$TAC}` WHERE parent_id=" . (int) $parent['id'])->fetchColumn();
    $exists = $pdo->prepare("SELECT COUNT(*) FROM `{$TAC}` WHERE code=?");
    do {
        $cnt++;
        $code = $parent['code'] . '.' . str_pad($cnt, 3, '0', STR_PAD_LEFT);
        $exists->execute([$code]);
    } while ((int) $exists->fetchColumn() > 0);
    $pdo->prepare("INSERT INTO `{$TAC}` (code,name,parent_id,account_type,currency_id,level,is_locked,cash_flow_category)
        VALUES (?,?,?,?,?,?,0,'financing')")
        ->execute([$code, $cfg['prefix'] . $partnerName, $parent['id'], $cfg['type'], $baseCurId, substr_count($code, '.') + 1]);
    return (int) $pdo->lastInsertId();
}

// حساب الصندوق/البنك: صريح (مع تحقق العملة والنوع) أو تلقائي حسب العملة وطريقة الدفع
function resolveCashAccount(PDO $pdo, string $TAC, string $TIAS, ?int $cashAccId, string $method, array $cur): array
{
    $needType = $method === 'cash' ? 'cash' : ($method === 'check' ? null : 'bank');
    if ($cashAccId) {
        $st = $pdo->prepare("SELECT ac.*, CASE WHEN ias.setting_key LIKE 'cash_%' THEN 'cash' ELSE 'bank' END AS acc_type
            FROM `{$TIAS}` ias JOIN `{$TAC}` ac ON ac.id=ias.account_id
            WHERE ac.id=? AND (ias.setting_key LIKE 'cash_%' OR ias.setting_key LIKE 'bank_%') AND ac.is_active=1 LIMIT 1");
        $st->execute([$cashAccId]);
        $acc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$acc)
            throw new Exception('حساب الصندوق/البنك المختار غير صالح');
        if ((int) $acc['currency_id'] !== (int) $cur['id'])
            throw new Exception("عملة الحساب المختار لا تطابق عملة السند ({$cur['code']})");
        if ($needType && $acc['acc_type'] !== $needType)
            throw new Exception($needType === 'cash' ? 'طريقة الدفع "نقدي" تتطلب حساب صندوق' : 'طريقة الدفع "تحويل/بطاقة" تتطلب حساب بنك');
        return $acc;
    }
    $key = ($method === 'bank' || $method === 'card') ? 'bank_' : 'cash_';
    $acc = getSettingAccount($pdo, $TIAS, $key . strtolower($cur['code']), $TAC);
    if (!$acc)
        throw new Exception('ما في حساب ' . ($key === 'bank_' ? 'بنك' : 'صندوق') . " مضبوط لعملة {$cur['code']} بإعدادات الربط المحاسبي");
    return $acc;
}

// سداد القرض لا يتجاوز القرض القائم (بعملة الفرع، من الأرصدة المرحَّلة فقط)
function checkRepayLimit(PDO $pdo, string $TAC, array $partner, string $type, float $amountBase): void
{
    if (!in_array($type, ['loan_repay', 'loan_out_repay']))
        return;
    $col = $type === 'loan_repay' ? 'loan_payable_account_id' : 'loan_receivable_account_id';
    $accId = (int) ($partner[$col] ?? 0);
    $bal = 0.0;
    if ($accId) {
        $st = $pdo->prepare("SELECT base_balance FROM `{$TAC}` WHERE id=?");
        $st->execute([$accId]);
        $raw = (float) $st->fetchColumn();
        $bal = $type === 'loan_repay' ? -$raw : $raw; // الالتزام خاماً سالب
    }
    if ($bal + 0.01 < $amountBase) {
        throw new Exception('مبلغ السداد (' . number_format($amountBase, 2) . ') أكبر من رصيد القرض القائم ('
            . number_format(max(0, $bal), 2) . ')');
    }
}

// ══════════════════ AJAX ══════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];
        $baseCurId = (int) ($pdo->query("SELECT id FROM currencies WHERE is_base=1 LIMIT 1")->fetchColumn() ?: 1);
        $types = txnTypes();

        // ── حفظ شريك (إضافة/تعديل) ──
        if ($act === 'save_partner') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $pctRaw = trim($_POST['ownership_pct'] ?? '');
            $pct = $pctRaw === '' ? null : (float) $pctRaw;
            $notes = trim($_POST['notes'] ?? '');
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            if (!$name)
                throw new Exception('اسم الشريك مطلوب');
            if ($pct !== null && ($pct < 0 || $pct > 100))
                throw new Exception('نسبة الملكية لازم تكون بين 0 و100');
            $dup = $pdo->prepare("SELECT COUNT(*) FROM `{$TP}` WHERE name=? AND id<>?");
            $dup->execute([$name, $id]);
            if ($dup->fetchColumn())
                throw new Exception('في شريك بنفس الاسم');

            if ($id) {
                requirePermission('finance.partners', 'edit');
                $cur = $pdo->prepare("SELECT * FROM `{$TP}` WHERE id=?");
                $cur->execute([$id]);
                $cur = $cur->fetch();
                if (!$cur)
                    throw new Exception('الشريك غير موجود');
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE `{$TP}` SET name=?,phone=?,ownership_pct=?,status=?,notes=?,updated_at=NOW() WHERE id=?")
                    ->execute([$name, $phone, $pct, $status, $notes, $id]);
                // تحديث أسماء حسابات الشريك مع اسمه الجديد
                foreach (acctRoles() as $cfg) {
                    $aid = (int) ($cur[$cfg['col']] ?? 0);
                    if ($aid)
                        $pdo->prepare("UPDATE `{$TAC}` SET name=?,updated_at=NOW() WHERE id=?")->execute([$cfg['prefix'] . $name, $aid]);
                }
                $pdo->commit();
                echo json_encode(['ok' => true, 'msg' => 'تم تعديل الشريك']);
            } else {
                requirePermission('finance.partners', 'create');
                $pdo->beginTransaction();
                $capId = createPartnerAccount($pdo, $TAC, $TIAS, 'capital', $name, $baseCurId);
                $drId = createPartnerAccount($pdo, $TAC, $TIAS, 'drawings', $name, $baseCurId);
                $pdo->prepare("INSERT INTO `{$TP}` (name,phone,ownership_pct,status,capital_account_id,drawings_account_id,notes,created_by)
                    VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$name, $phone, $pct, $status, $capId, $drId, $notes, $_SESSION['user_id']]);
                $pdo->commit();
                echo json_encode(['ok' => true, 'msg' => 'تم إضافة الشريك وإنشاء حسابيه (رأس المال والمسحوبات)']);
            }
        }

        elseif ($act === 'get_partner') {
            $st = $pdo->prepare("SELECT * FROM `{$TP}` WHERE id=?");
            $st->execute([(int) $_POST['id']]);
            $row = $st->fetch();
            if (!$row)
                throw new Exception('الشريك غير موجود');
            echo json_encode(['ok' => true, 'data' => $row]);
        }

        elseif ($act === 'toggle_partner') {
            requirePermission('finance.partners', 'edit');
            $id = (int) $_POST['id'];
            $pdo->prepare("UPDATE `{$TP}` SET status=IF(status='active','inactive','active'),updated_at=NOW() WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true]);
        }

        // ── حذف شريك: ممنوع لو عليه أي حركة (عطّله بدل الحذف) ──
        elseif ($act === 'delete_partner') {
            requirePermission('finance.partners', 'delete');
            $id = (int) $_POST['id'];
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM `{$TPT}` WHERE partner_id=?");
            $cnt->execute([$id]);
            if ($cnt->fetchColumn())
                throw new Exception('ما ينحذف — عليه حركات مسجَّلة. عطّله بدل الحذف');
            $p = $pdo->prepare("SELECT * FROM `{$TP}` WHERE id=?");
            $p->execute([$id]);
            $p = $p->fetch();
            if (!$p)
                throw new Exception('الشريك غير موجود');
            $pdo->beginTransaction();
            // الحسابات تُعطَّل (مش تنحذف) — نفس سياسة حذف العملات
            foreach (acctRoles() as $cfg) {
                $aid = (int) ($p[$cfg['col']] ?? 0);
                if ($aid)
                    $pdo->prepare("UPDATE `{$TAC}` SET is_active=0,updated_at=NOW() WHERE id=?")->execute([$aid]);
            }
            $pdo->prepare("DELETE FROM `{$TP}` WHERE id=?")->execute([$id]);
            $pdo->commit();
            echo json_encode(['ok' => true, 'msg' => 'تم حذف الشريك وتعطيل حساباته']);
        }

        // ── حفظ حركة (مسودة + قيد مسودة) ──
        elseif ($act === 'save_txn') {
            requirePermission('finance.partners', 'create');
            $partnerId = (int) ($_POST['partner_id'] ?? 0);
            $type = $_POST['txn_type'] ?? '';
            $date = $_POST['txn_date'] ?? date('Y-m-d');
            $amount = (float) ($_POST['amount'] ?? 0);
            $currId = (int) ($_POST['currency_id'] ?? 0);
            $rate = (float) ($_POST['exchange_rate'] ?? 1);
            $method = $_POST['payment_method'] ?? 'cash';
            $cashAccId = (int) ($_POST['cash_account_id'] ?? 0) ?: null;
            $notes = trim($_POST['notes'] ?? '');

            if (!isset($types[$type]))
                throw new Exception('نوع الحركة غير صالح');
            if ($amount <= 0)
                throw new Exception('المبلغ لازم يكون أكبر من صفر');
            if (!in_array($method, ['cash', 'bank', 'card', 'check']))
                throw new Exception('طريقة دفع غير صالحة');
            if ($method === 'check' && !$cashAccId)
                throw new Exception('لطريقة الدفع "شيك" لازم تختار حساب الصندوق أو البنك يدوياً');

            $pSt = $pdo->prepare("SELECT * FROM `{$TP}` WHERE id=?");
            $pSt->execute([$partnerId]);
            $partner = $pSt->fetch();
            if (!$partner)
                throw new Exception('اختر الشريك');
            if ($partner['status'] !== 'active')
                throw new Exception('هذا الشريك معطَّل');

            $cSt = $pdo->prepare("SELECT * FROM currencies WHERE id=?");
            $cSt->execute([$currId]);
            $cur = $cSt->fetch();
            if (!$cur)
                throw new Exception('اختر العملة');
            if ($cur['is_base'] || $rate <= 0)
                $rate = 1;
            $amountBase = round($amount / $rate, 4);

            $accCash = resolveCashAccount($pdo, $TAC, $TIAS, $cashAccId, $method, $cur);
            checkRepayLimit($pdo, $TAC, $partner, $type, $amountBase);

            $role = $types[$type]['acct'];
            $cfg = acctRoles()[$role];

            $pdo->beginTransaction();
            $otherId = (int) ($partner[$cfg['col']] ?? 0);
            if (!$otherId) {
                if (!in_array($role, ['loan_payable', 'loan_receivable']))
                    throw new Exception('حساب الشريك ناقص — احذفه وأعد إضافته');
                // إنشاء حساب القرض عند أول قرض فعلي (lazy)
                $otherId = createPartnerAccount($pdo, $TAC, $TIAS, $role, $partner['name'], $baseCurId);
                $pdo->prepare("UPDATE `{$TP}` SET {$cfg['col']}=? WHERE id=?")->execute([$otherId, $partnerId]);
            }

            $txnNo = genTxnNo($pdo, $TPT);
            $pdo->prepare("INSERT INTO `{$TPT}` (txn_number,txn_date,partner_id,txn_type,amount,currency_id,exchange_rate,
                amount_base,payment_method,cash_account_id,notes,status,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,'draft',?)")
                ->execute([$txnNo, $date, $partnerId, $type, $amount, $currId, $rate, $amountBase, $method,
                    $accCash['id'], $notes, $_SESSION['user_id']]);
            $txnId = (int) $pdo->lastInsertId();

            $jeNo = genEntryNo($pdo, $TJE);
            $desc = $types[$type]['label'] . " — {$partner['name']} — سند {$txnNo}";
            $pdo->prepare("INSERT INTO `{$TJE}` (entry_number,entry_date,description,currency_id,exchange_rate,
                total_debit,total_credit,status,reference_type,reference_id,created_by)
                VALUES (?,?,?,?,?,?,?,'draft','partner_txn',?,?)")
                ->execute([$jeNo, $date, $desc, $currId, $rate, $amountBase, $amountBase, $txnId, $_SESSION['user_id']]);
            $jeId = (int) $pdo->lastInsertId();

            $in = $types[$type]['dir'] === 'in';
            $itemSql = "INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,
                description,currency_id,exchange_rate) VALUES (?,?,?,?,?,?,?,?,?)";
            // سطر الصندوق/البنك
            $pdo->prepare($itemSql)->execute([$jeId, $accCash['id'], $in ? $amountBase : 0, $in ? 0 : $amountBase,
                $amount, $amountBase, $types[$type]['label'] . " — {$txnNo}", $currId, $rate]);
            // سطر حساب الشريك (الطرف المقابل)
            $pdo->prepare($itemSql)->execute([$jeId, $otherId, $in ? 0 : $amountBase, $in ? $amountBase : 0,
                $amount, $amountBase, "{$partner['name']} — {$txnNo}", $currId, $rate]);

            $pdo->prepare("UPDATE `{$TPT}` SET journal_entry_id=? WHERE id=?")->execute([$jeId, $txnId]);
            $pdo->commit();
            echo json_encode(['ok' => true, 'id' => $txnId, 'no' => $txnNo]);
        }

        // ── ترحيل حركة (يحدّث الأرصدة) ──
        elseif ($act === 'post_txn') {
            requirePermission('finance.partners', 'confirm');
            $id = (int) $_POST['id'];
            $st = $pdo->prepare("SELECT * FROM `{$TPT}` WHERE id=?");
            $st->execute([$id]);
            $txn = $st->fetch();
            if (!$txn)
                throw new Exception('الحركة غير موجودة');
            if ($txn['status'] !== 'draft')
                throw new Exception('يمكن ترحيل المسودات فقط');
            if (!$txn['journal_entry_id'])
                throw new Exception('ما في قيد مرتبط بهالحركة');

            $pSt = $pdo->prepare("SELECT * FROM `{$TP}` WHERE id=?");
            $pSt->execute([$txn['partner_id']]);
            $partner = $pSt->fetch();
            if (!$partner)
                throw new Exception('الشريك غير موجود');
            checkRepayLimit($pdo, $TAC, $partner, $txn['txn_type'], (float) $txn['amount_base']);

            $pdo->beginTransaction();
            $items = $pdo->prepare("SELECT * FROM `{$TJI}` WHERE journal_entry_id=?");
            $items->execute([$txn['journal_entry_id']]);
            foreach ($items->fetchAll() as $item) {
                $net = $item['debit'] - $item['credit'];
                $ownNet = ((float) $item['debit'] > 0) ? (float) $item['original_amount'] : -(float) $item['original_amount'];
                $pdo->prepare("UPDATE `{$TAC}` SET
                        base_balance=base_balance+?,
                        balance=balance+(CASE WHEN currency_id=? THEN ? ELSE ? END),
                        updated_at=NOW()
                    WHERE id=?")
                    ->execute([$net, $item['currency_id'], $ownNet, $net, $item['account_id']]);
            }
            $pdo->prepare("UPDATE `{$TJE}` SET status='posted',posted_at=NOW(),posted_by=? WHERE id=?")
                ->execute([$_SESSION['user_id'], $txn['journal_entry_id']]);
            $pdo->prepare("UPDATE `{$TPT}` SET status='posted',updated_by=?,updated_at=NOW() WHERE id=?")
                ->execute([$_SESSION['user_id'], $id]);
            $pdo->commit();
            echo json_encode(['ok' => true, 'msg' => 'تم الترحيل وتحديث الأرصدة']);
        }

        // ── إلغاء حركة (يعكس الأرصدة لو كانت مرحّلة) ──
        elseif ($act === 'cancel_txn') {
            requirePermission('finance.partners', 'edit');
            $id = (int) $_POST['id'];
            $st = $pdo->prepare("SELECT * FROM `{$TPT}` WHERE id=?");
            $st->execute([$id]);
            $txn = $st->fetch();
            if (!$txn)
                throw new Exception('الحركة غير موجودة');
            if ($txn['status'] === 'cancelled')
                throw new Exception('ملغاة مسبقاً');

            // إلغاء قرض مرحَّل بعد ما انسدد جزء منه بيخلّي رصيد القرض سالب — ممنوع
            if ($txn['status'] === 'posted' && in_array($txn['txn_type'], ['loan_in', 'loan_out'])) {
                $pSt = $pdo->prepare("SELECT * FROM `{$TP}` WHERE id=?");
                $pSt->execute([$txn['partner_id']]);
                $partner = $pSt->fetch();
                $col = $txn['txn_type'] === 'loan_in' ? 'loan_payable_account_id' : 'loan_receivable_account_id';
                $accId = (int) ($partner[$col] ?? 0);
                $raw = 0.0;
                if ($accId) {
                    $b = $pdo->prepare("SELECT base_balance FROM `{$TAC}` WHERE id=?");
                    $b->execute([$accId]);
                    $raw = (float) $b->fetchColumn();
                }
                $bal = $txn['txn_type'] === 'loan_in' ? -$raw : $raw;
                if ($bal + 0.01 < (float) $txn['amount_base'])
                    throw new Exception('ما ينلغى القرض — انسدد جزء منه. ألغِ حركات السداد أولاً');
            }
            $pdo->beginTransaction();
            if ($txn['journal_entry_id']) {
                if ($txn['status'] === 'posted') {
                    $items = $pdo->prepare("SELECT * FROM `{$TJI}` WHERE journal_entry_id=?");
                    $items->execute([$txn['journal_entry_id']]);
                    foreach ($items->fetchAll() as $item) {
                        $net = $item['debit'] - $item['credit'];
                        $ownNet = ((float) $item['debit'] > 0) ? (float) $item['original_amount'] : -(float) $item['original_amount'];
                        $pdo->prepare("UPDATE `{$TAC}` SET
                                base_balance=base_balance-?,
                                balance=balance-(CASE WHEN currency_id=? THEN ? ELSE ? END),
                                updated_at=NOW()
                            WHERE id=?")
                            ->execute([$net, $item['currency_id'], $ownNet, $net, $item['account_id']]);
                    }
                }
                $pdo->prepare("UPDATE `{$TJE}` SET status='cancelled',cancelled_at=NOW(),cancelled_by=? WHERE id=?")
                    ->execute([$_SESSION['user_id'], $txn['journal_entry_id']]);
            }
            $pdo->prepare("UPDATE `{$TPT}` SET status='cancelled',updated_by=?,updated_at=NOW() WHERE id=?")
                ->execute([$_SESSION['user_id'], $id]);
            $pdo->commit();
            echo json_encode(['ok' => true, 'msg' => 'تم إلغاء الحركة وعكس التأثيرات']);
        }

        elseif ($act === 'get_txn') {
            $st = $pdo->prepare("SELECT t.*, p.name AS partner_name, c.code AS cur_code, c.symbol AS cur_sym,
                    cash.code AS cash_code, cash.name AS cash_name,
                    je.entry_number, je.status AS je_status
                FROM `{$TPT}` t
                JOIN `{$TP}` p ON p.id=t.partner_id
                LEFT JOIN currencies c ON c.id=t.currency_id
                LEFT JOIN `{$TAC}` cash ON cash.id=t.cash_account_id
                LEFT JOIN `{$TJE}` je ON je.id=t.journal_entry_id
                WHERE t.id=?");
            $st->execute([(int) $_POST['id']]);
            $row = $st->fetch();
            if (!$row)
                throw new Exception('الحركة غير موجودة');
            $row['type_label'] = $types[$row['txn_type']]['label'] ?? $row['txn_type'];
            $row['type_preview'] = $types[$row['txn_type']]['preview'] ?? '';
            echo json_encode(['ok' => true, 'data' => $row]);
        } else
            throw new Exception('إجراء غير معروف');
    } catch (Exception $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}
// ══════════════════ بيانات الصفحة ══════════════════
$baseCur = $pdo->query("SELECT id,code,symbol FROM currencies WHERE is_base=1 LIMIT 1")->fetch() ?: ['id' => 1, 'code' => 'USD', 'symbol' => '$'];
$baseSym = $baseCur['symbol'] ?: $baseCur['code'];
try {
    $partners = $pdo->query("SELECT p.*, cap.base_balance AS cap_bal, dr.base_balance AS dr_bal,
            lp.base_balance AS lp_bal, lr.base_balance AS lr_bal
        FROM `{$TP}` p
        LEFT JOIN `{$TAC}` cap ON cap.id=p.capital_account_id
        LEFT JOIN `{$TAC}` dr ON dr.id=p.drawings_account_id
        LEFT JOIN `{$TAC}` lp ON lp.id=p.loan_payable_account_id
        LEFT JOIN `{$TAC}` lr ON lr.id=p.loan_receivable_account_id
        ORDER BY p.status='active' DESC, p.name")->fetchAll();
} catch (Exception $e) {
    $hint = strpos($e->getMessage(), '1146') !== false
        ? '<br>الجدول غير موجود بقاعدة البيانات — شغّل <b>partners_module_setup.sql</b> مع الـsuffix الصحيح لهذا الفرع (<b>' . htmlspecialchars($TS) . '</b>)'
        : '';
    die('<div dir="rtl" style="font-family:sans-serif;padding:40px;text-align:center;color:#dc2626">
        تعذّر جلب بيانات الشركاء:<br><code dir="ltr" style="color:#334155">' . htmlspecialchars($e->getMessage()) . '</code>' . $hint . '</div>');
}
// الأرصدة الخام: حقوق الملكية والالتزامات سالبة (دائن) — نعكسها للعرض
$totCapital = $totDrawings = $totLoanFrom = $totLoanTo = 0;
$partnersJs = [];
$activePartners = 0;
foreach ($partners as &$p) {
    $p['capital'] = -(float) ($p['cap_bal'] ?? 0);
    $p['drawings'] = (float) ($p['dr_bal'] ?? 0);
    $p['loan_from'] = -(float) ($p['lp_bal'] ?? 0);
    $p['loan_to'] = (float) ($p['lr_bal'] ?? 0);
    $p['net_equity'] = $p['capital'] - $p['drawings'];
    $totCapital += $p['capital'];
    $totDrawings += $p['drawings'];
    $totLoanFrom += $p['loan_from'];
    $totLoanTo += $p['loan_to'];
    if ($p['status'] === 'active')
        $activePartners++;
    $partnersJs[$p['id']] = ['name' => $p['name'], 'status' => $p['status'], 'capital' => $p['capital'],
        'drawings' => $p['drawings'], 'loanFrom' => $p['loan_from'], 'loanTo' => $p['loan_to']];
}
unset($p);
// الحسابات الأب المطلوبة بالإعدادات — تنبيه لو ناقصة
$missingParents = [];
foreach (acctRoles() as $role => $cfg) {
    if (!getSettingAccount($pdo, $TIAS, $cfg['parent'], $TAC))
        $missingParents[] = $cfg['parent_lbl'];
}
// العملات: المربوطة بفرع المستخدم (currency_branch_links)، مع رجوع لكل العملات النشطة لو الجدول غير جاهز
$currenciesList = [];
try {
    $bSt = $pdo->prepare("SELECT id FROM branches WHERE table_suffix=?");
    $bSt->execute([$TS]);
    $myBranchId = (int) $bSt->fetchColumn();
    $cl = $pdo->prepare("SELECT c.id,c.code,c.name,c.symbol,c.exchange_rate,c.is_base
        FROM currencies c JOIN currency_branch_links l ON l.currency_id=c.id AND l.branch_id=?
        WHERE c.status='active' ORDER BY c.is_base DESC,c.id");
    $cl->execute([$myBranchId]);
    $currenciesList = $cl->fetchAll();
} catch (Exception $e) {
}
if (!$currenciesList) {
    $currenciesList = $pdo->query("SELECT id,code,name,symbol,exchange_rate,is_base FROM currencies WHERE status='active' ORDER BY is_base DESC,id")->fetchAll();
}
// حسابات الصندوق/البنك من إعدادات الربط (نوع كل حساب الحقيقي cash/bank)
$cashAccounts = $pdo->query("SELECT ac.id, ac.code, ac.name, ac.currency_id, c.code AS cur_code,
        CASE WHEN ias.setting_key LIKE 'cash_%' THEN 'cash' ELSE 'bank' END AS acc_type
    FROM `{$TIAS}` ias
    JOIN `{$TAC}` ac ON ac.id = ias.account_id
    LEFT JOIN currencies c ON c.id = ac.currency_id
    WHERE (ias.setting_key LIKE 'cash_%' OR ias.setting_key LIKE 'bank_%') AND ac.is_active = 1
    ORDER BY ac.code")->fetchAll();
// ── فلاتر الحركات ──
$q = trim($_GET['q'] ?? '');
$fPartner = (int) ($_GET['partner'] ?? 0);
$fType = $_GET['type'] ?? '';
$fStatus = $_GET['status'] ?? '';
$fFrom = $_GET['from'] ?? '';
$fTo = $_GET['to'] ?? '';
$sec = ($_GET['sec'] ?? '') === 'tx' ? 'tx' : 'pt';
$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(t.txn_number LIKE ? OR p.name LIKE ? OR t.notes LIKE ?)';
    array_push($params, "%{$q}%", "%{$q}%", "%{$q}%");
}
if ($fPartner) {
    $where[] = 't.partner_id=?';
    $params[] = $fPartner;
}
if ($fType !== '' && isset(txnTypes()[$fType])) {
    $where[] = 't.txn_type=?';
    $params[] = $fType;
}
if (in_array($fStatus, ['draft', 'posted', 'cancelled'])) {
    $where[] = 't.status=?';
    $params[] = $fStatus;
}
if ($fFrom !== '') {
    $where[] = 't.txn_date>=?';
    $params[] = $fFrom;
}
if ($fTo !== '') {
    $where[] = 't.txn_date<=?';
    $params[] = $fTo;
}
$txSt = $pdo->prepare("SELECT t.*, p.name AS partner_name, c.code AS cur_code, c.symbol AS cur_sym
    FROM `{$TPT}` t
    JOIN `{$TP}` p ON p.id=t.partner_id
    LEFT JOIN currencies c ON c.id=t.currency_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY t.txn_date DESC, t.id DESC LIMIT 300");
$txSt->execute($params);
$txns = $txSt->fetchAll();
$typesJs = txnTypes();
$methodLabels = ['cash' => 'نقدي', 'bank' => 'تحويل بنكي', 'card' => 'بطاقة', 'check' => 'شيك'];
$statusMeta = [
    'draft' => ['مسودة', '#f59e0b', '#fffbeb'],
    'posted' => ['مرحّل', '#16a34a', '#f0fdf4'],
    'cancelled' => ['ملغى', '#94a3b8', '#f1f5f9'],
];
function fmtN($n): string
{
    return number_format((float) $n, 2);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>الشركاء والمالك — <?= htmlspecialchars($branchName) ?></title>
    <link rel="icon" href="<?= BASE_PATH ?>/assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/assets/css/layout.css" rel="stylesheet">
    <style>
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
            font-size: .74rem;
            font-weight: 700;
            color: #64748b;
            text-align: right;
            white-space: nowrap
        }

        table.mtbl td {
            padding: 8px 12px;
            border-top: 1px solid #f1f5f9;
            vertical-align: middle
        }

        .field-lbl {
            font-size: .75rem;
            font-weight: 600;
            color: #475569;
            margin-bottom: 4px;
            display: block
        }

        .n {
            direction: ltr;
            unicode-bidi: plaintext;
            font-variant-numeric: tabular-nums
        }

        .kpi-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px;
            text-align: center
        }

        .kpi-val {
            font-size: 1.15rem;
            font-weight: 700
        }

        .kpi-lbl {
            font-size: .72rem;
            color: #64748b;
            margin-top: 4px
        }

        .sub-nav {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
            flex-wrap: wrap
        }

        .sub-nav button {
            padding: 8px 16px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            background: #fff;
            font-size: .82rem;
            font-weight: 600;
            color: #64748b;
            cursor: pointer
        }

        .sub-nav button.active {
            background: #1e3a8a;
            color: #fff;
            border-color: #1e3a8a
        }

        .st-badge {
            display: inline-block;
            padding: 2px 9px;
            border-radius: 20px;
            font-size: .68rem;
            font-weight: 700
        }

        .act-btn {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            border: 1px solid #e2e8f0;
            background: #fff;
            color: #64748b;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .8rem;
            cursor: pointer;
            transition: .15s
        }

        .act-btn:hover {
            background: #f1f5f9;
            color: #1e3a8a
        }

        .act-btn.ok:hover {
            background: #f0fdf4;
            color: #16a34a
        }

        .act-btn.danger:hover {
            background: #fef2f2;
            color: #dc2626
        }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-people me-1 text-primary"></i>الشركاء والمالك</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
            <span>المالية</span>
            <i class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
            <span class="text-primary fw-600">الشركاء والمالك</span>
        </nav>
    </header>
    <main class="main-content">
        <div class="content-body">

            <!-- تبويبات قسم المالية (مكوّن مشترك — يتبع الشريط الجانبي) -->
            <?php require __DIR__ . '/../../../includes/tab_bar.php'; ?>

            <?php if ($missingParents): ?>
                <div class="alert alert-warning py-2 mb-3" style="font-size:.8rem;border-radius:10px">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    حسابات "أب" ناقصة بإعدادات الربط المحاسبي:
                    <b><?= htmlspecialchars(implode('، ', $missingParents)) ?></b> —
                    أنشئها بشجرة الحسابات واربطها من <a href="account_settings.php">إعدادات الربط</a> (مجموعة "الشركاء والمالك")
                    قبل إضافة شريك أو تسجيل حركات.
                </div>
            <?php endif; ?>

            <!-- إحصائيات -->
            <div class="row g-3 mb-3">
                <div class="col-6 col-md"><div class="kpi-card"><div class="kpi-val text-primary"><?= $activePartners ?></div><div class="kpi-lbl">شركاء نشطين</div></div></div>
                <div class="col-6 col-md"><div class="kpi-card"><div class="kpi-val n text-success"><?= htmlspecialchars($baseSym) ?> <?= fmtN($totCapital) ?></div><div class="kpi-lbl">إجمالي رأس المال</div></div></div>
                <div class="col-6 col-md"><div class="kpi-card"><div class="kpi-val n text-danger"><?= htmlspecialchars($baseSym) ?> <?= fmtN($totDrawings) ?></div><div class="kpi-lbl">إجمالي المسحوبات</div></div></div>
                <div class="col-6 col-md"><div class="kpi-card"><div class="kpi-val n" style="color:#d97706"><?= htmlspecialchars($baseSym) ?> <?= fmtN($totLoanFrom) ?></div><div class="kpi-lbl">قروض علينا للشركاء</div></div></div>
                <div class="col-6 col-md"><div class="kpi-card"><div class="kpi-val n" style="color:#2563eb"><?= htmlspecialchars($baseSym) ?> <?= fmtN($totLoanTo) ?></div><div class="kpi-lbl">قروض لنا على الشركاء</div></div></div>
            </div>

            <div class="sub-nav">
                <button id="btnSecPt" class="<?= $sec === 'pt' ? 'active' : '' ?>" onclick="showSection('pt')">
                    <i class="bi bi-people me-1"></i>الشركاء وأرصدتهم
                </button>
                <button id="btnSecTx" class="<?= $sec === 'tx' ? 'active' : '' ?>" onclick="showSection('tx')">
                    <i class="bi bi-arrow-left-right me-1"></i>الحركات
                </button>
            </div>

            <!-- ═══════ الشركاء ═══════ -->
            <div id="secPt" style="<?= $sec === 'pt' ? '' : 'display:none' ?>">
                <div class="tbl-wrap">
                    <div class="tbl-hdr">
                        <span style="font-size:.9rem;font-weight:700;color:#1e293b">
                            <i class="bi bi-people me-2 text-primary"></i>الشركاء (<?= count($partners) ?>)
                        </span>
                        <button class="btn btn-sm btn-primary ms-auto" style="border-radius:8px" onclick="openPartnerModal()">
                            <i class="bi bi-plus-lg me-1"></i>إضافة شريك
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="mtbl">
                            <thead>
                                <tr>
                                    <th>الشريك</th>
                                    <th>الملكية %</th>
                                    <th class="text-end">رأس المال</th>
                                    <th class="text-end">المسحوبات</th>
                                    <th class="text-end">صافي حقوقه</th>
                                    <th class="text-end">قرض منه</th>
                                    <th class="text-end">قرض له</th>
                                    <th>الحالة</th>
                                    <th style="width:150px">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$partners): ?>
                                    <tr><td colspan="9" class="text-center text-muted py-4">لا يوجد شركاء بعد — أضف أول شريك</td></tr>
                                <?php endif; ?>
                                <?php foreach ($partners as $p): $inactive = $p['status'] !== 'active'; ?>
                                    <tr style="<?= $inactive ? 'opacity:.55' : '' ?>">
                                        <td>
                                            <div class="fw-600"><?= htmlspecialchars($p['name']) ?></div>
                                            <?php if ($p['phone']): ?><div style="font-size:.7rem;color:#94a3b8" class="n"><?= htmlspecialchars($p['phone']) ?></div><?php endif; ?>
                                        </td>
                                        <td class="n"><?= $p['ownership_pct'] !== null ? fmtN($p['ownership_pct']) . '%' : '—' ?></td>
                                        <td class="n text-end text-success"><?= fmtN($p['capital']) ?></td>
                                        <td class="n text-end text-danger"><?= fmtN($p['drawings']) ?></td>
                                        <td class="n text-end fw-600" style="color:<?= $p['net_equity'] >= 0 ? '#16a34a' : '#dc2626' ?>"><?= fmtN($p['net_equity']) ?></td>
                                        <td class="n text-end" style="color:#d97706"><?= $p['loan_from'] > 0.005 ? fmtN($p['loan_from']) : '—' ?></td>
                                        <td class="n text-end" style="color:#2563eb"><?= $p['loan_to'] > 0.005 ? fmtN($p['loan_to']) : '—' ?></td>
                                        <td>
                                            <span class="st-badge" style="background:<?= $inactive ? '#f1f5f9' : '#f0fdf4' ?>;color:<?= $inactive ? '#94a3b8' : '#16a34a' ?>">
                                                <?= $inactive ? 'معطَّل' : 'نشط' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <?php if (!$inactive): ?>
                                                    <button class="act-btn ok" title="حركة جديدة لهذا الشريك" onclick="openNewTxn(<?= (int) $p['id'] ?>)"><i class="bi bi-plus-circle"></i></button>
                                                <?php endif; ?>
                                                <button class="act-btn" title="تعديل" onclick="openPartnerModal(<?= (int) $p['id'] ?>)"><i class="bi bi-pencil"></i></button>
                                                <button class="act-btn" title="<?= $inactive ? 'تفعيل' : 'تعطيل' ?>" onclick="togglePartner(<?= (int) $p['id'] ?>)"><i class="bi bi-<?= $inactive ? 'toggle-off' : 'toggle-on' ?>"></i></button>
                                                <button class="act-btn danger" title="حذف" onclick="deletePartner(<?= (int) $p['id'] ?>, <?= htmlspecialchars(json_encode($p['name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)"><i class="bi bi-trash"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══════ الحركات ═══════ -->
            <div id="secTx" style="<?= $sec === 'tx' ? '' : 'display:none' ?>">
                <div class="tbl-wrap">
                    <div class="tbl-hdr">
                        <form method="get" class="d-flex flex-wrap gap-2 align-items-center">
                            <input type="hidden" name="sec" value="tx">
                            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="form-control form-control-sm" style="width:150px" placeholder="بحث: رقم/شريك/ملاحظة">
                            <select name="partner" class="form-select form-select-sm" style="width:140px">
                                <option value="">كل الشركاء</option>
                                <?php foreach ($partners as $p): ?>
                                    <option value="<?= (int) $p['id'] ?>" <?= $fPartner === (int) $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="type" class="form-select form-select-sm" style="width:160px">
                                <option value="">كل الأنواع</option>
                                <?php foreach ($typesJs as $k => $t): ?>
                                    <option value="<?= $k ?>" <?= $fType === $k ? 'selected' : '' ?>><?= htmlspecialchars($t['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="status" class="form-select form-select-sm" style="width:110px">
                                <option value="">كل الحالات</option>
                                <?php foreach ($statusMeta as $k => $m): ?>
                                    <option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= $m[0] ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="date" name="from" value="<?= htmlspecialchars($fFrom) ?>" class="form-control form-control-sm" style="width:135px" title="من تاريخ">
                            <input type="date" name="to" value="<?= htmlspecialchars($fTo) ?>" class="form-control form-control-sm" style="width:135px" title="إلى تاريخ">
                            <button class="btn btn-sm btn-primary" style="border-radius:8px"><i class="bi bi-search me-1"></i>بحث</button>
                            <a href="partners.php?sec=tx" class="btn btn-sm btn-light" style="border-radius:8px">مسح</a>
                        </form>
                        <button class="btn btn-sm btn-primary ms-auto" style="border-radius:8px" onclick="openNewTxn()">
                            <i class="bi bi-plus-lg me-1"></i>حركة جديدة
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="mtbl">
                            <thead>
                                <tr>
                                    <th>رقم الحركة</th><th>التاريخ</th><th>الشريك</th><th>النوع</th>
                                    <th class="text-end">المبلغ</th><th class="text-end">بعملة الفرع</th>
                                    <th>الطريقة</th><th>الحالة</th><th style="width:110px">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$txns): ?>
                                    <tr><td colspan="9" class="text-center text-muted py-4">لا توجد حركات</td></tr>
                                <?php endif; ?>
                                <?php foreach ($txns as $t):
                                    $tm = $typesJs[$t['txn_type']] ?? ['label' => $t['txn_type'], 'dir' => 'in'];
                                    $sm = $statusMeta[$t['status']] ?? ['—', '#64748b', '#f1f5f9'];
                                    $col = $tm['dir'] === 'in' ? '#16a34a' : '#dc2626';
                                    ?>
                                    <tr style="<?= $t['status'] === 'cancelled' ? 'opacity:.5' : '' ?>">
                                        <td class="n fw-600" style="color:#1e3a8a"><?= htmlspecialchars($t['txn_number']) ?></td>
                                        <td class="n"><?= htmlspecialchars($t['txn_date']) ?></td>
                                        <td><?= htmlspecialchars($t['partner_name']) ?></td>
                                        <td><?= htmlspecialchars($tm['label']) ?></td>
                                        <td class="n text-end fw-600" style="color:<?= $col ?>"><?= htmlspecialchars($t['cur_sym'] ?: $t['cur_code']) ?> <?= fmtN($t['amount']) ?></td>
                                        <td class="n text-end" style="color:#64748b"><?= htmlspecialchars($baseSym) ?> <?= fmtN($t['amount_base']) ?></td>
                                        <td><?= $methodLabels[$t['payment_method']] ?? '' ?></td>
                                        <td><span class="st-badge" style="background:<?= $sm[2] ?>;color:<?= $sm[1] ?>"><?= $sm[0] ?></span></td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <button class="act-btn" title="عرض" onclick="viewTxn(<?= (int) $t['id'] ?>)"><i class="bi bi-eye"></i></button>
                                                <?php if ($t['status'] === 'draft'): ?>
                                                    <button class="act-btn ok" title="ترحيل" onclick="postTxn(<?= (int) $t['id'] ?>)"><i class="bi bi-check2-circle"></i></button>
                                                <?php endif; ?>
                                                <?php if ($t['status'] !== 'cancelled'): ?>
                                                    <button class="act-btn danger" title="إلغاء" onclick="cancelTxn(<?= (int) $t['id'] ?>)"><i class="bi bi-x-circle"></i></button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (count($txns) >= 300): ?>
                        <div class="text-center text-muted py-2" style="font-size:.72rem">معروض آخر ٣٠٠ حركة — استخدم الفلاتر لتضييق النتائج</div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>

    <!-- مودال شريك -->
    <div class="modal fade" id="partnerModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0" style="background:linear-gradient(135deg,#1e3a8a,#3b82f6);border-radius:16px 16px 0 0">
                    <h6 class="modal-title text-white fw-700 mb-0" id="partnerModalTitle"><i class="bi bi-people me-2"></i>شريك</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-4">
                    <input type="hidden" id="pId">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="field-lbl">اسم الشريك *</label>
                            <input type="text" id="pName" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="field-lbl">الهاتف</label>
                            <input type="text" id="pPhone" class="form-control form-control-sm n" dir="ltr">
                        </div>
                        <div class="col-md-6">
                            <label class="field-lbl">نسبة الملكية % <span style="color:#94a3b8;font-weight:400">(معلوماتية)</span></label>
                            <input type="number" id="pPct" class="form-control form-control-sm n" min="0" max="100" step="0.01">
                        </div>
                        <div class="col-md-6">
                            <label class="field-lbl">الحالة</label>
                            <select id="pStatus" class="form-select form-select-sm">
                                <option value="active">نشط</option>
                                <option value="inactive">معطَّل</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="field-lbl">ملاحظات</label>
                            <input type="text" id="pNotes" class="form-control form-control-sm" maxlength="255">
                        </div>
                        <div class="col-12" id="pNewHint">
                            <div class="alert alert-info py-2 mb-0" style="font-size:.74rem;border-radius:10px">
                                <i class="bi bi-info-circle me-1"></i>عند الحفظ بيتولّد تلقائياً حسابا "رأس المال" و"المسحوبات" للشريك بشجرة الحسابات
                                (حسابات القروض بتتولّد عند أول قرض فعلي).
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-sm btn-primary fw-600" style="border-radius:8px;min-width:100px" onclick="savePartner()" id="btnSavePartner">
                        <i class="bi bi-floppy me-1"></i>حفظ
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- مودال حركة -->
    <div class="modal fade" id="txnModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0" style="background:linear-gradient(135deg,#1e3a8a,#3b82f6);border-radius:16px 16px 0 0">
                    <h6 class="modal-title text-white fw-700 mb-0"><i class="bi bi-arrow-left-right me-2"></i>حركة شريك جديدة</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="field-lbl">الشريك *</label>
                            <select id="tPartner" class="form-select form-select-sm" onchange="refreshTxnHint()">
                                <option value="">— اختر —</option>
                                <?php foreach ($partners as $p):
                                    if ($p['status'] !== 'active')
                                        continue; ?>
                                    <option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="field-lbl">نوع الحركة *</label>
                            <select id="tType" class="form-select form-select-sm" onchange="refreshTxnHint()">
                                <?php
                                $lastGroup = null;
                                foreach ($typesJs as $k => $t):
                                    if ($t['group'] !== $lastGroup) {
                                        if ($lastGroup !== null)
                                            echo '</optgroup>';
                                        echo '<optgroup label="' . htmlspecialchars($t['group']) . '">';
                                        $lastGroup = $t['group'];
                                    }
                                    ?>
                                    <option value="<?= $k ?>"><?= htmlspecialchars($t['label']) ?></option>
                                <?php endforeach;
                                if ($lastGroup !== null)
                                    echo '</optgroup>'; ?>
                            </select>
                        </div>
                        <div class="col-12" id="tHint" style="display:none"></div>

                        <div class="col-md-3">
                            <label class="field-lbl">التاريخ *</label>
                            <input type="date" id="tDate" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="field-lbl">العملة *</label>
                            <select id="tCurr" class="form-select form-select-sm" onchange="onTxnCurrencyChange()">
                                <?php foreach ($currenciesList as $c): ?>
                                    <option value="<?= (int) $c['id'] ?>" data-rate="<?= htmlspecialchars($c['exchange_rate']) ?>"
                                        data-base="<?= $c['is_base'] ? 1 : 0 ?>" data-sym="<?= htmlspecialchars($c['symbol'] ?: $c['code']) ?>">
                                        <?= htmlspecialchars($c['code'] . ' — ' . $c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="field-lbl">سعر الصرف</label>
                            <input type="number" id="tRate" class="form-control form-control-sm n" step="0.0001" min="0.0001" value="1" oninput="recalcTxn()">
                        </div>
                        <div class="col-md-3">
                            <label class="field-lbl">المبلغ *</label>
                            <input type="number" id="tAmount" class="form-control form-control-sm n" step="0.01" min="0" oninput="recalcTxn()">
                        </div>
                        <div class="col-12" style="margin-top:-6px">
                            <span style="font-size:.74rem;color:#64748b">المعادل بعملة الفرع:</span>
                            <span id="tBase" class="n fw-600 text-success"><?= htmlspecialchars($baseSym) ?> 0.00</span>
                        </div>

                        <div class="col-md-4">
                            <label class="field-lbl">طريقة الدفع</label>
                            <select id="tMethod" class="form-select form-select-sm" onchange="filterTxnCashAcc()">
                                <option value="cash">نقدي</option>
                                <option value="bank">تحويل بنكي</option>
                                <option value="card">بطاقة</option>
                                <option value="check">شيك</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="field-lbl">حساب الصندوق/البنك</label>
                            <select id="tCashAcc" class="form-select form-select-sm">
                                <option value="" id="tCashAccAuto">— تلقائي حسب الإعدادات —</option>
                                <?php foreach ($cashAccounts as $acc): ?>
                                    <option value="<?= (int) $acc['id'] ?>" data-cur="<?= (int) $acc['currency_id'] ?>" data-type="<?= $acc['acc_type'] ?>">
                                        <?= htmlspecialchars($acc['code'] . ' — ' . $acc['name'] . ($acc['cur_code'] ? ' (' . $acc['cur_code'] . ')' : '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="tCashAccHint" style="display:none;font-size:.68rem;color:#d97706;margin-top:3px">
                                <i class="bi bi-info-circle me-1"></i>الشيك ممكن يُصرف كاش أو يُودَع بنكي — اختر الحساب الفعلي يدوياً
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="field-lbl">ملاحظات</label>
                            <input type="text" id="tNotes" class="form-control form-control-sm" maxlength="255">
                        </div>
                        <div class="col-12">
                            <div id="tPreview" class="alert alert-secondary py-2 mb-0" style="font-size:.74rem;border-radius:10px"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button class="btn btn-sm btn-light" style="border-radius:8px" data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-sm btn-outline-primary fw-600" style="border-radius:8px" onclick="saveTxn(false)" id="btnSaveTxn">
                        <i class="bi bi-floppy me-1"></i>حفظ كمسودة
                    </button>
                    <button class="btn btn-sm btn-success fw-600" style="border-radius:8px" onclick="saveTxn(true)" id="btnSavePostTxn">
                        <i class="bi bi-check2-circle me-1"></i>حفظ وترحيل
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- مودال عرض حركة -->
    <div class="modal fade" id="viewModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0" style="background:linear-gradient(135deg,#1e3a8a,#3b82f6);border-radius:16px 16px 0 0">
                    <h6 class="modal-title text-white fw-700 mb-0" id="vTitle">حركة</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-4" id="vBody"></div>
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

        const BASE_SYM = <?= json_encode($baseSym) ?>;
        const PARTNERS = <?= json_encode($partnersJs, JSON_UNESCAPED_UNICODE) ?>;
        const TYPES = <?= json_encode($typesJs, JSON_UNESCAPED_UNICODE) ?>;
        const partnerModal = new bootstrap.Modal(document.getElementById('partnerModal'));
        const txnModal = new bootstrap.Modal(document.getElementById('txnModal'));
        const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));

        // ⚠ toISOString() بيحوّل لـUTC فبيطلع تاريخ اليوم السابق بمنطقة UTC+3 — نستخدم التنسيق المحلي
        function toLocalDateStr(d) {
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        }
        function post(data) {
            const fd = new FormData();
            Object.entries(data).forEach(([k, v]) => fd.append(k, v ?? ''));
            return fetch(location.pathname, { method: 'POST', body: fd }).then(r => r.json());
        }
        function toast(msg, type = 'success') {
            const t = document.createElement('div'); t.className = `alert alert-${type} shadow`;
            t.style.cssText = 'position:fixed;top:70px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:240px;max-width:90vw;text-align:center;font-size:.83rem;padding:.5rem 1.2rem';
            t.textContent = msg;
            document.body.appendChild(t); setTimeout(() => t.remove(), 3800);
        }
        function fmt(n) { return (parseFloat(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
        function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

        function showSection(s) {
            document.getElementById('secPt').style.display = s === 'pt' ? '' : 'none';
            document.getElementById('secTx').style.display = s === 'tx' ? '' : 'none';
            document.getElementById('btnSecPt').classList.toggle('active', s === 'pt');
            document.getElementById('btnSecTx').classList.toggle('active', s === 'tx');
        }

        // ══════════ الشركاء ══════════
        function openPartnerModal(id) {
            ['pId', 'pName', 'pPhone', 'pPct', 'pNotes'].forEach(x => document.getElementById(x).value = '');
            document.getElementById('pStatus').value = 'active';
            document.getElementById('pNewHint').style.display = id ? 'none' : '';
            document.getElementById('partnerModalTitle').innerHTML = '<i class="bi bi-people me-2"></i>' + (id ? 'تعديل شريك' : 'إضافة شريك');
            if (!id) { partnerModal.show(); return; }
            post({ _action: 'get_partner', id }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                const r = d.data;
                document.getElementById('pId').value = r.id;
                document.getElementById('pName').value = r.name || '';
                document.getElementById('pPhone').value = r.phone || '';
                document.getElementById('pPct').value = r.ownership_pct ?? '';
                document.getElementById('pStatus').value = r.status;
                document.getElementById('pNotes').value = r.notes || '';
                partnerModal.show();
            });
        }
        function savePartner() {
            const btn = document.getElementById('btnSavePartner'); btn.disabled = true;
            post({
                _action: 'save_partner', id: document.getElementById('pId').value,
                name: document.getElementById('pName').value, phone: document.getElementById('pPhone').value,
                ownership_pct: document.getElementById('pPct').value, status: document.getElementById('pStatus').value,
                notes: document.getElementById('pNotes').value,
            }).then(d => {
                btn.disabled = false;
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                toast(d.msg); partnerModal.hide(); setTimeout(() => location.reload(), 800);
            });
        }
        function togglePartner(id) {
            post({ _action: 'toggle_partner', id }).then(d => { if (d.ok) location.reload(); else toast(d.msg, 'danger'); });
        }
        function deletePartner(id, name) {
            if (!confirm('حذف الشريك "' + name + '"؟ (بيتعطّل حساباته، وبيترفض لو عليه حركات)')) return;
            post({ _action: 'delete_partner', id }).then(d => {
                if (d.ok) { toast(d.msg); setTimeout(() => location.reload(), 800); } else toast(d.msg, 'danger');
            });
        }

        // ══════════ الحركات ══════════
        function openNewTxn(partnerId) {
            document.getElementById('tPartner').value = partnerId || '';
            document.getElementById('tType').selectedIndex = 0;
            document.getElementById('tDate').value = toLocalDateStr(new Date());
            document.getElementById('tAmount').value = '';
            document.getElementById('tNotes').value = '';
            document.getElementById('tMethod').value = 'cash';
            document.getElementById('tCashAcc').value = '';
            const cur = document.getElementById('tCurr');
            const baseIdx = [...cur.options].findIndex(o => o.dataset.base === '1');
            cur.selectedIndex = baseIdx >= 0 ? baseIdx : 0;
            onTxnCurrencyChange();
            refreshTxnHint();
            txnModal.show();
        }

        function onTxnCurrencyChange() {
            const sel = document.getElementById('tCurr');
            const opt = sel.options[sel.selectedIndex];
            const isBase = opt && opt.dataset.base === '1';
            const rate = document.getElementById('tRate');
            rate.value = isBase ? 1 : (parseFloat(opt ? opt.dataset.rate : 1) || 1);
            rate.readOnly = isBase;
            recalcTxn();
            filterTxnCashAcc();
        }
        function recalcTxn() {
            const amt = parseFloat(document.getElementById('tAmount').value || 0);
            const rate = parseFloat(document.getElementById('tRate').value || 1) || 1;
            document.getElementById('tBase').textContent = BASE_SYM + ' ' + fmt(amt / rate);
        }

        // تصفية الحسابات: (١) عملة السند (٢) طريقة الدفع — نقدي=صندوق، تحويل/بطاقة=بنك، شيك=الاثنين مع اختيار صريح
        function filterTxnCashAcc() {
            const curId = document.getElementById('tCurr').value;
            const method = document.getElementById('tMethod').value;
            const sel = document.getElementById('tCashAcc');
            const autoOpt = document.getElementById('tCashAccAuto');
            const needType = method === 'cash' ? 'cash' : (method === 'check' ? null : 'bank');
            let hidCurrent = false;
            [...sel.options].forEach(opt => {
                if (!opt.value) return;
                const match = opt.dataset.cur === curId && (!needType || opt.dataset.type === needType);
                opt.hidden = !match;
                if (!match && opt.selected) hidCurrent = true;
            });
            autoOpt.hidden = method === 'check';
            if (method === 'check' && sel.value === '') hidCurrent = true;
            document.getElementById('tCashAccHint').style.display = method === 'check' ? '' : 'none';
            if (hidCurrent) sel.value = '';
        }

        function refreshTxnHint() {
            const pid = document.getElementById('tPartner').value;
            const type = document.getElementById('tType').value;
            const box = document.getElementById('tHint');
            document.getElementById('tPreview').innerHTML = '<i class="bi bi-journal-text me-1"></i><b>القيد:</b> ' + esc((TYPES[type] || {}).preview || '');
            if (!pid || !PARTNERS[pid]) { box.style.display = 'none'; return; }
            const p = PARTNERS[pid];
            let html = `<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:8px 12px;font-size:.74rem;color:#475569">
                رأس المال <b class="n">${BASE_SYM} ${fmt(p.capital)}</b> · مسحوبات <b class="n">${BASE_SYM} ${fmt(p.drawings)}</b> ·
                قرض منه <b class="n">${BASE_SYM} ${fmt(p.loanFrom)}</b> · قرض له <b class="n">${BASE_SYM} ${fmt(p.loanTo)}</b>`;
            if (type === 'loan_repay') html += `<br><span style="color:#d97706">المتاح للسداد (القرض القائم): <b class="n">${BASE_SYM} ${fmt(p.loanFrom)}</b></span>`;
            if (type === 'loan_out_repay') html += `<br><span style="color:#2563eb">المتاح للسداد (القرض القائم): <b class="n">${BASE_SYM} ${fmt(p.loanTo)}</b></span>`;
            html += '</div>';
            box.innerHTML = html; box.style.display = '';
        }

        function saveTxn(andPost) {
            const pid = document.getElementById('tPartner').value;
            const amount = parseFloat(document.getElementById('tAmount').value || 0);
            const method = document.getElementById('tMethod').value;
            if (!pid) { toast('اختر الشريك', 'danger'); return; }
            if (amount <= 0) { toast('أدخل مبلغاً أكبر من صفر', 'danger'); return; }
            if (method === 'check' && !document.getElementById('tCashAcc').value) { toast('للشيك لازم تختار حساب الصندوق/البنك يدوياً', 'danger'); return; }
            const b1 = document.getElementById('btnSaveTxn'), b2 = document.getElementById('btnSavePostTxn');
            b1.disabled = b2.disabled = true;
            post({
                _action: 'save_txn', partner_id: pid, txn_type: document.getElementById('tType').value,
                txn_date: document.getElementById('tDate').value, amount, currency_id: document.getElementById('tCurr').value,
                exchange_rate: document.getElementById('tRate').value, payment_method: method,
                cash_account_id: document.getElementById('tCashAcc').value, notes: document.getElementById('tNotes').value,
            }).then(d => {
                if (!d.ok) { b1.disabled = b2.disabled = false; toast(d.msg, 'danger'); return; }
                if (!andPost) { toast('✅ تم حفظ المسودة — ' + d.no); txnModal.hide(); setTimeout(() => location.href = 'partners.php?sec=tx', 700); return; }
                post({ _action: 'post_txn', id: d.id }).then(p => {
                    b1.disabled = b2.disabled = false;
                    if (p.ok) { toast('✅ تم الحفظ والترحيل — ' + d.no); txnModal.hide(); setTimeout(() => location.href = 'partners.php?sec=tx', 700); }
                    else { toast('انحفظت كمسودة (' + d.no + ') لكن الترحيل فشل: ' + p.msg, 'danger'); }
                });
            });
        }
        function postTxn(id) {
            if (!confirm('ترحيل الحركة وتحديث الأرصدة؟')) return;
            post({ _action: 'post_txn', id }).then(d => {
                if (d.ok) { toast(d.msg); setTimeout(() => location.reload(), 700); } else toast(d.msg, 'danger');
            });
        }
        function cancelTxn(id) {
            if (!confirm('إلغاء الحركة؟ (بتنعكس الأرصدة لو كانت مرحّلة)')) return;
            post({ _action: 'cancel_txn', id }).then(d => {
                if (d.ok) { toast(d.msg); setTimeout(() => location.reload(), 700); } else toast(d.msg, 'danger');
            });
        }
        function viewTxn(id) {
            document.getElementById('vTitle').textContent = 'جارٍ التحميل...';
            document.getElementById('vBody').innerHTML = '<div class="text-center py-4"><span class="spinner-border text-primary"></span></div>';
            viewModal.show();
            post({ _action: 'get_txn', id }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); viewModal.hide(); return; }
                const r = d.data;
                const stMap = { draft: 'مسودة', posted: 'مرحّل', cancelled: 'ملغى' };
                const mMap = { cash: 'نقدي', bank: 'تحويل بنكي', card: 'بطاقة', check: 'شيك' };
                const sym = r.cur_sym || r.cur_code || '';
                const row = (k, v) => `<div class="d-flex justify-content-between py-1" style="border-bottom:1px solid #f1f5f9;font-size:.82rem"><span style="color:#64748b">${k}</span><span class="fw-600">${v}</span></div>`;
                document.getElementById('vTitle').textContent = r.txn_number + ' — ' + r.type_label;
                document.getElementById('vBody').innerHTML =
                    row('الشريك', esc(r.partner_name)) +
                    row('التاريخ', esc(r.txn_date)) +
                    row('المبلغ', '<span class="n">' + esc(sym) + ' ' + fmt(r.amount) + '</span>') +
                    row('سعر الصرف', '<span class="n">' + fmt(r.exchange_rate) + '</span>') +
                    row('بعملة الفرع', '<span class="n">' + BASE_SYM + ' ' + fmt(r.amount_base) + '</span>') +
                    row('طريقة الدفع', mMap[r.payment_method] || '') +
                    row('الحساب', r.cash_code ? esc(r.cash_code + ' — ' + r.cash_name) : '—') +
                    row('الحالة', stMap[r.status] || r.status) +
                    row('القيد', r.entry_number ? esc(r.entry_number) + ' (' + (stMap[r.je_status] || r.je_status) + ')' : '—') +
                    `<div style="background:#f8fafc;border-radius:8px;padding:8px 12px;margin-top:10px;font-size:.74rem;color:#475569"><i class="bi bi-journal-text me-1"></i>${esc(r.type_preview)}</div>` +
                    (r.notes ? `<div style="background:#f8fafc;border-radius:8px;padding:8px 12px;margin-top:8px;font-size:.78rem;color:#64748b">${esc(r.notes)}</div>` : '');
            });
        }

        // تهيئة أولية لحقول المودال (سعر الصرف/الفلترة/معاينة القيد)
        onTxnCurrencyChange();
        refreshTxnHint();
    </script>

</body>

</html>
