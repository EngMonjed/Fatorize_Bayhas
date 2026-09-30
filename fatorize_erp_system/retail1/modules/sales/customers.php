<?php

/**
 * sales/customers.php — إدارة العملاء
 *retail1/modules/sales/customers.php
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('crm.customers', 'view');
$currentModule = 'crm.customers';

$TS = $_SESSION['table_suffix'];
$TC = "customers_{$TS}";
$TSI = "sales_invoices_{$TS}";
$TAC = "account_charts_{$TS}";
$TPP = "pricing_patterns_{$TS}";
$TIAS = "invoice_account_settings_{$TS}";
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

// رمز عملة الفرع الأساسية — الأرقام المجمَّعة بالإحصائيات (total_base/
// balance_base) هلق محوّلة فعلياً لهاي العملة (مش $ ثابتة افتراضياً).
$baseCurrencySymbol = '$';
$baseCurrencyId = 1; // fallback فقط لو الفرع بلا base_currency_id مضبوط
if (!empty($_SESSION['branch_id'])) {
    $bcStmt = $pdo->prepare("SELECT c.symbol, b.base_currency_id FROM branches b
        LEFT JOIN currencies c ON c.id = b.base_currency_id
        WHERE b.id = ?");
    $bcStmt->execute([$_SESSION['branch_id']]);
    $bcRow = $bcStmt->fetch();
    if ($bcRow) {
        $baseCurrencySymbol = $bcRow['symbol'] ?: '$';
        $baseCurrencyId = $bcRow['base_currency_id'] ?: 1;
    }
}

// ── AJAX ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $act = $_POST['_action'];

        if ($act === 'get_customer') {
            $id = (int) $_POST['id'];
            $st = $pdo->prepare("SELECT c.*,
                pp.name AS pricing_pattern_name,
                rec.code AS rec_code, rec.name AS rec_name, rec.balance AS rec_balance,
                rec.base_balance AS rec_base_balance, recCur.code AS rec_cur_code, recCur.symbol AS rec_cur_sym,
                adv.code AS adv_code, adv.name AS adv_name, adv.balance AS adv_balance,
                adv.base_balance AS adv_base_balance, advCur.code AS adv_cur_code, advCur.symbol AS adv_cur_sym
                FROM `{$TC}` c
                LEFT JOIN `{$TPP}` pp ON pp.id=c.pricing_pattern_id
                LEFT JOIN `{$TAC}` rec ON rec.id=c.account_id
                LEFT JOIN `currencies` recCur ON recCur.id=rec.currency_id
                LEFT JOIN `{$TAC}` adv ON adv.id=c.prepaid_account_id
                LEFT JOIN `currencies` advCur ON advCur.id=adv.currency_id
                WHERE c.id=?");
            $st->execute([$id]);
            $cust = $st->fetch();
            if (!$cust)
                throw new Exception('العميل غير موجود');
            // إحصائيات — مفروزة بالكامل بين "مؤكدة" و"مسودة"، بلا خلط:
            // - confirmed_cnt/confirmed_total: فواتير حقيقية (انعكست على
            //   المخزون والقيود المحاسبية فعلياً) — هاي "المبيعات الحقيقية".
            // - draft_cnt: عدد المسودات فقط (بدون مجموع مبلغ — مسودة لسا
            //   مش مستند مالي فعلي، مجموعها المالي مش له معنى محاسبي).
            //
            // ⚠⚠ إصلاح جذري: "المستحق" ما عاد يُحسب بجمع فواتير غير
            // مسدَّدة من جدول sales_invoices فقط — هالطريقة كانت تتجاهل
            // كلياً أي رصيد افتتاحي أو قيد يدوي مباشر بحساب ذمة العميل
            // (مثال حقيقي: عميل بصفر فواتير، بس عنده ٣٠$ رصيد افتتاحي
            // بشجرة الحسابات — كانت "المستحق" تطلع صفر رغم إنه فعلياً
            // مدين). الصح: "المستحق" = نفس رصيد حساب الذمة الحي مباشرة
            // (rec_base_balance، مجلوب أصلاً بالاستعلام فوق) — مصدر
            // الحقيقة الوحيد، يشمل الفواتير + الرصيد الافتتاحي + أي قيد
            // يدوي، بلا استثناء.
            //
            // ⚠ إصلاح ثانوي منفصل: total_amount صار بعملة الفرع مباشرة
            // (بعد قرار "عملة الفرع بالهيدر")، فالقسمة القديمة على
            // exchange_rate كانت خاطئة — حتى لو ما ظهرت هون لأنها كانت
            // بالعادة تُضرب بصفر.
            $stats = $pdo->prepare("SELECT
                COUNT(CASE WHEN status='confirmed' THEN 1 END) AS confirmed_cnt,
                COUNT(CASE WHEN status='draft' THEN 1 END) AS draft_cnt,
                COALESCE(SUM(CASE WHEN status='confirmed' THEN total_amount END),0) AS confirmed_total_base
                FROM `{$TSI}` WHERE customer_id=? AND status!='cancelled'");
            $stats->execute([$id]);
            $cust['stats'] = $stats->fetch();
            // المستحق = رصيد حساب الذمة الحي مباشرة (مصدر الحقيقة
            // الوحيد) — لا حساب منفصل من الفواتير.
            $cust['stats']['balance_base'] = (float) ($cust['rec_base_balance'] ?? 0);
            echo json_encode(['ok' => true, 'data' => $cust]);
        } elseif ($act === 'save_customer') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $contact = trim($_POST['contact_person'] ?? '');
            $type = $_POST['type'] ?? 'retail';
            $pricingPatternId = (int) ($_POST['pricing_pattern_id'] ?? 0) ?: null;
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $taxNo = trim($_POST['tax_number'] ?? '');
            $ship = trim($_POST['shipping_company'] ?? '');
            $shipCode = trim($_POST['shipping_code'] ?? '');
            $creditLimit = (float) ($_POST['credit_limit'] ?? 0);
            $discountPct = (float) ($_POST['discount_percentage'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            $status = $_POST['status'] ?? 'active';
            $accId = (int) ($_POST['account_id'] ?? 0) ?: null;
            $prepaidId = (int) ($_POST['prepaid_account_id'] ?? 0) ?: null;
            if (!$name)
                throw new Exception('اسم العميل مطلوب');

            $pdo->beginTransaction();
            try {
                // ⚠ قرار نهائي (يلغي "اختيار حساب أب بكل مرة" السابق):
                // الحساب الأب صار يُضبط مرة وحدة بصفحة إعدادات الربط
                // المحاسبي (customer_receivable/customer_advance)، وكل
                // عميل جديد ياخد حسابيه تلقائياً تحتهم — إجباري دايماً،
                // بلا أي قائمة اختيار أو checkbox بالفورم.
                if (!$id) {
                    $getParent = function (string $key) use ($pdo, $TIAS, $TAC): ?array {
                        $st = $pdo->prepare("SELECT ac.* FROM `{$TIAS}` i JOIN `{$TAC}` ac ON ac.id=i.account_id WHERE i.setting_key=? LIMIT 1");
                        $st->execute([$key]);
                        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
                    };
                    $parentReceivable = $getParent('customer_receivable');
                    $parentAdvance = $getParent('customer_advance');
                    if (!$parentReceivable || !$parentAdvance)
                        throw new Exception('اضبط حسابي "ذمم العملاء" و"دفعات مقدمة من العملاء" أولاً من صفحة إعدادات الربط المحاسبي');

                    // إنشاء حساب الذمة تحت الحساب الأب المضبوط بالإعدادات
                    $cnt = (int) $pdo->query("SELECT COUNT(*) FROM `{$TAC}` WHERE parent_id={$parentReceivable['id']}")->fetchColumn();
                    $code = $parentReceivable['code'] . '.' . str_pad($cnt + 1, 3, '0', STR_PAD_LEFT);
                    $pdo->prepare("INSERT INTO `{$TAC}` (code,name,parent_id,account_type,currency_id,level,is_locked)
                        VALUES (?,?,?,'asset',?,?,0)")
                        ->execute([$code, "ذمة {$name}", $parentReceivable['id'], $baseCurrencyId, substr_count($code, '.') + 1]);
                    $accId = (int) $pdo->lastInsertId();

                    // إنشاء حساب الدفعة المقدمة تحت الحساب الأب المضبوط بالإعدادات
                    $cnt2 = (int) $pdo->query("SELECT COUNT(*) FROM `{$TAC}` WHERE parent_id={$parentAdvance['id']}")->fetchColumn();
                    $code2 = $parentAdvance['code'] . '.' . str_pad($cnt2 + 1, 3, '0', STR_PAD_LEFT);
                    $pdo->prepare("INSERT INTO `{$TAC}` (code,name,parent_id,account_type,currency_id,level,is_locked)
                        VALUES (?,?,?,'liability',?,?,0)")
                        ->execute([$code2, "دفعات مقدمة — {$name}", $parentAdvance['id'], $baseCurrencyId, substr_count($code2, '.') + 1]);
                    $prepaidId = (int) $pdo->lastInsertId();
                }

                if ($id) {
                    requirePermission('crm.customers', 'edit');
                    $pdo->prepare("UPDATE `{$TC}` SET name=?,contact_person=?,type=?,pricing_pattern_id=?,phone=?,
                        email=?,address=?,tax_number=?,shipping_company=?,shipping_code=?,
                        credit_limit=?,discount_percentage=?,notes=?,
                        status=?,account_id=?,prepaid_account_id=?,updated_at=NOW() WHERE id=?")
                        ->execute([
                            $name,
                            $contact,
                            $type,
                            $pricingPatternId,
                            $phone,
                            $email,
                            $address,
                            $taxNo,
                            $ship,
                            $shipCode,
                            $creditLimit,
                            $discountPct,
                            $notes,
                            $status,
                            $accId,
                            $prepaidId,
                            $id
                        ]);
                    $msg = 'تم التعديل';
                } else {
                    requirePermission('crm.customers', 'create');
                    $pdo->prepare("INSERT INTO `{$TC}`
                        (name,contact_person,type,pricing_pattern_id,phone,email,address,tax_number,
                         shipping_company,shipping_code,credit_limit,discount_percentage,notes,
                         status,account_id,prepaid_account_id)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([
                            $name,
                            $contact,
                            $type,
                            $pricingPatternId,
                            $phone,
                            $email,
                            $address,
                            $taxNo,
                            $ship,
                            $shipCode,
                            $creditLimit,
                            $discountPct,
                            $notes,
                            $status,
                            $accId,
                            $prepaidId
                        ]);
                    $id = (int) $pdo->lastInsertId();
                    $msg = 'تمت الإضافة وإنشاء حسابي الذمة والدفعة المقدمة ✅';
                }
                $pdo->commit();
                echo json_encode(['ok' => true, 'id' => $id, 'msg' => $msg]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        } elseif ($act === 'add_pricing_pattern') {
            requirePermission('crm.customers', 'create');
            $name = trim($_POST['name'] ?? '');
            if (!$name)
                throw new Exception('اسم نمط التسعير مطلوب');
            $pdo->prepare("INSERT INTO `{$TPP}` (name, description) VALUES (?, ?)")
                ->execute([$name, trim($_POST['description'] ?? '')]);
            echo json_encode(['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'name' => $name, 'msg' => 'تمت إضافة نمط التسعير']);
        } elseif ($act === 'toggle_status') {
            requirePermission('crm.customers', 'edit');
            $id = (int) $_POST['id'];
            $pdo->prepare("UPDATE `{$TC}` SET status=IF(status='active','inactive','active'),
                updated_at=NOW() WHERE id=?")
                ->execute([$id]);
            $row = $pdo->prepare("SELECT status FROM `{$TC}` WHERE id=?");
            $row->execute([$id]);
            echo json_encode(['ok' => true, 'status' => $row->fetchColumn()]);
        } else
            throw new Exception('إجراء غير معروف');
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── بيانات الصفحة ──────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$typeF = $_GET['type'] ?? '';
$statusF = $_GET['status'] ?? '';
$where = 'WHERE 1=1';
$params = [];
if ($search) {
    $where .= ' AND (c.name LIKE ? OR c.phone LIKE ? OR c.contact_person LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($typeF) {
    $where .= ' AND c.type=?';
    $params[] = $typeF;
}
if ($statusF) {
    $where .= ' AND c.status=?';
    $params[] = $statusF;
}

$stmt = $pdo->prepare("SELECT c.*,
    rec.code AS rec_code, rec.name AS rec_name, rec.base_balance AS rec_base_balance,
    adv.code AS adv_code, adv.name AS adv_name,
    COUNT(DISTINCT CASE WHEN si.status='confirmed' THEN si.id END) AS confirmed_cnt,
    COUNT(DISTINCT CASE WHEN si.status='draft' THEN si.id END) AS draft_cnt,
    COALESCE(SUM(CASE WHEN si.status='confirmed'
        THEN si.total_amount END),0) AS total_base
    FROM `{$TC}` c
    LEFT JOIN `{$TAC}` rec ON rec.id=c.account_id
    LEFT JOIN `{$TAC}` adv ON adv.id=c.prepaid_account_id
    LEFT JOIN `{$TSI}` si ON si.customer_id=c.id AND si.status!='cancelled'
    {$where}
    GROUP BY c.id ORDER BY c.name");
$stmt->execute($params);
$customers = $stmt->fetchAll();
// ⚠ المستحق = رصيد حساب الذمة الحي مباشرة (مصدر الحقيقة الوحيد) — نفس
// إصلاح get_customer أعلاه، بلا حساب منفصل من الفواتير فقط.
foreach ($customers as &$c) {
    $c['balance_base'] = (float) ($c['rec_base_balance'] ?? 0);
}
unset($c);

// حسابات الذمم (asset — ذمة العميل هي أصل بالنسبة إلنا) وحسابات
// الدفعات المقدمة من العملاء (liability — دفعة مقدّمة منهم علينا
// التزام لحد ما نسلّم البضاعة) — عكس اتجاه الموردين تماماً بالضبط.
$receivableAccs = $pdo->query("SELECT id,code,name FROM `{$TAC}` WHERE account_type='asset' AND is_active=1 ORDER BY code")->fetchAll();
$advanceAccs = $pdo->query("SELECT id,code,name FROM `{$TAC}` WHERE account_type='liability' AND is_active=1 ORDER BY code")->fetchAll();

try {
    $stats = $pdo->query("SELECT COUNT(*) AS total,
        SUM(status='active') AS active,
        SUM(type='wholesale') AS wholesale_cnt,
        SUM(type='retail') AS retail_cnt,
        SUM(type='super_wholesale') AS super_wholesale_cnt
        FROM `{$TC}`")->fetch();
} catch (Exception $e) {
    $stats = ['total' => 0, 'active' => 0, 'wholesale_cnt' => 0, 'retail_cnt' => 0, 'super_wholesale_cnt' => 0];
}

$TYPE_MAP = ['wholesale' => 'جملة', 'retail' => 'مفرق', 'super_wholesale' => 'جملة الجملة'];
$pricingPatterns = $pdo->query("SELECT id, name FROM `{$TPP}` WHERE is_active=1 ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>إدارة العملاء — <?= htmlspecialchars($branchName) ?></title>
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

        .sec-title {
            font-size: .72rem;
            font-weight: 700;
            color: #1e3a8a;
            text-transform: uppercase;
            letter-spacing: .5px;
            padding-bottom: 4px;
            border-bottom: 1px solid #bfdbfe;
            margin-top: 4px;
            margin-bottom: 8px
        }

        .acc-badge {
            font-size: .68rem;
            padding: 2px 7px;
            border-radius: 5px;
            background: #f1f5f9;
            color: #475569;
            font-family: monospace
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

        .avatar {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #eff6ff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            font-weight: 700;
            color: #1e3a8a;
            flex-shrink: 0
        }

        .avatar.company {
            background: #fef3c7;
            color: #92400e
        }

        .avatar.super-wholesale {
            background: #f0fdf4;
            color: #065f46
        }

        .det-row {
            display: flex;
            justify-content: space-between;
            font-size: .8rem;
            padding: 5px 0;
            border-bottom: 1px solid #f8fafc
        }

        .det-row:last-child {
            border-bottom: none
        }

        .sup-hdr {
            padding: 12px 20px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between
        }

        .card-sup {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            overflow: hidden
        }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-people me-1 text-primary"></i>إدارة العملاء</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
            <span>المبيعات</span>
            <i class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
            <span class="text-primary fw-600">إدارة العملاء</span>
        </nav>
    </header>
    <main class="main-content">
        <div class="content-body">
            <!-- تبويبات — نفس تصميم sales_index.php بالضبط، عشان قسم
                 المبيعات كله (الفواتير/العملاء) يصير متسق ومترابط
                 ببعضه بدون رجوع للشريط الجانبي كل مرة. -->
            <!-- تبويبات القسم (مكوّن مشترك — يتبع الشريط الجانبي) -->
            <?php require __DIR__ . '/../../../includes/tab_bar.php'; ?>
            <!-- إحصائيات -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#eff6ff"><i class="bi bi-people text-primary"></i>
                        </div>
                        <div>
                            <div class="stat-val"><?= $stats['total'] ?></div>
                            <div class="stat-lbl">إجمالي العملاء</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#f0fdf4"><i
                                class="bi bi-person-check text-success"></i></div>
                        <div>
                            <div class="stat-val"><?= $stats['active'] ?></div>
                            <div class="stat-lbl">عملاء نشطون</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#eff6ff"><i class="bi bi-box-seam text-primary"></i>
                        </div>
                        <div>
                            <div class="stat-val"><?= $stats['wholesale_cnt'] ?></div>
                            <div class="stat-lbl">جملة</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fef3c7"><i class="bi bi-shop text-warning"></i>
                        </div>
                        <div>
                            <div class="stat-val"><?= $stats['retail_cnt'] ?></div>
                            <div class="stat-lbl">مفرق</div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- فلاتر -->
            <div class="card-sup mb-3">
                <div class="sup-hdr">
                    <span style="font-size:.9rem;font-weight:700;color:#1e293b;white-space:nowrap">
                        <i class="bi bi-people me-2 text-primary"></i>سجل قائمة العملاء
                        <span style="font-size:.75rem;color:#94a3b8;font-weight:400">(<?= count($customers) ?>)</span>
                    </span>
                    <form method="get" class="d-flex gap-2 flex-wrap align-items-center ms-auto">
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                            placeholder="بحث بالاسم أو الهاتف..." class="form-control form-control-sm"
                            style="width:200px;border-radius:8px">
                        <select name="type" class="form-select form-select-sm" style="width:130px;border-radius:8px"
                            onchange="this.form.submit()">
                            <option value="">النوع</option>
                            <option value="wholesale" <?= $typeF === 'wholesale' ? 'selected' : '' ?>>جملة</option>
                            <option value="retail" <?= $typeF === 'retail' ? 'selected' : '' ?>>مفرق</option>
                            <option value="super_wholesale" <?= $typeF === 'super_wholesale' ? 'selected' : '' ?>>جملة الجملة</option>
                        </select>
                        <select name="status" class="form-select form-select-sm" style="width:180px;border-radius:8px"
                            onchange="this.form.submit()">
                            <option value="">الحالة</option>
                            <option value="active" <?= $statusF === 'active' ? 'selected' : '' ?>>نشط</option>
                            <option value="inactive" <?= $statusF === 'inactive' ? 'selected' : '' ?>>معطّل</option>
                        </select>
                    </form>

                    <a class="btn btn-sm fw-600" onclick="openAdd()"
                        style="border-radius:9px;background:var(--section-color);color:#fff;border:none;white-space:nowrap">
                        <i class="bi bi-plus-lg me-1"></i>عميل جديد
                    </a>
                </div>
            </div>
            <!-- الجدول -->
            <div class="card-sup">
                <div class="table-responsive" id="customersTbl">
                    <table class="mtbl">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>العميل</th>
                                <th>النوع</th>
                                <th>الهاتف</th>
                                <th>شركة الشحن</th>
                                <th>حساب الذمة</th>
                                <th>حساب الدفعات المقدمة</th>
                                <th title="عدد الفواتير المؤكدة — المسودات تظهر كشارة منفصلة">الفواتير</th>
                                <th>إجمالي المبيعات</th>
                                <th>المستحق</th>
                                <th>الحالة</th>
                                <th style="text-align:center">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($customers)): ?>
                                <tr>
                                    <td colspan="12" class="text-center text-muted py-5">
                                        <i class="bi bi-people d-block mb-2" style="font-size:2rem;opacity:.2"></i>
                                        لا يوجد عملاء<?= $search ? " يطابقون \"{$search}\"" : '' ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php
                            $TYPE_STYLE = [
                                'wholesale' => ['avatar' => 'company', 'badge' => 'bg-warning-subtle text-warning', 'icon' => 'box-seam'],
                                'retail' => ['avatar' => '', 'badge' => 'bg-secondary-subtle text-secondary', 'icon' => 'shop'],
                                'super_wholesale' => ['avatar' => 'super-wholesale', 'badge' => 'bg-success-subtle text-success', 'icon' => 'buildings'],
                            ];
                            ?>
                            <?php foreach ($customers as $i => $c):
                                $init = mb_substr($c['name'], 0, 1, 'UTF-8');
                                $ts = $TYPE_STYLE[$c['type']] ?? $TYPE_STYLE['retail'];
                                ?>
                                <tr>
                                    <td class="text-muted" style="font-size:.75rem"><?= $i + 1 ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="avatar <?= $ts['avatar'] ?>"><?= $init ?></div>
                                            <div>
                                                <div class="fw-600"><?= htmlspecialchars($c['name']) ?></div>
                                                <?php if ($c['contact_person']): ?>
                                                    <div class="text-muted" style="font-size:.72rem">
                                                        <i
                                                            class="bi bi-person me-1"></i><?= htmlspecialchars($c['contact_person']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span
                                            class="badge <?= $ts['badge'] ?>"
                                            style="font-size:.68rem">
                                            <i
                                                class="bi bi-<?= $ts['icon'] ?> me-1"></i><?= $TYPE_MAP[$c['type']] ?>
                                        </span>
                                    </td>
                                    <td dir="ltr" style="font-size:.8rem;color:#475569">
                                        <?= $c['phone'] ? htmlspecialchars($c['phone']) : '—' ?>
                                    </td>
                                    <td style="font-size:.78rem;color:#64748b">
                                        <?php if ($c['shipping_company']): ?>
                                            <div><?= htmlspecialchars($c['shipping_company']) ?></div>
                                            <?php if ($c['shipping_code']): ?>
                                                <div dir="ltr" style="font-size:.7rem;color:#94a3b8">
                                                    <?= htmlspecialchars($c['shipping_code']) ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else:
                                            echo '—';
                                        endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($c['rec_code']): ?>
                                            <span class="acc-badge"><?= htmlspecialchars($c['rec_code']) ?></span>
                                            <div style="font-size:.7rem;color:#64748b;margin-top:2px">
                                                <?= htmlspecialchars($c['rec_name']) ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-danger" style="font-size:.75rem"><i
                                                    class="bi bi-exclamation-circle me-1"></i>غير محدد</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($c['adv_code']): ?>
                                            <span class="acc-badge"><?= htmlspecialchars($c['adv_code']) ?></span>
                                            <div style="font-size:.7rem;color:#64748b;margin-top:2px">
                                                <?= htmlspecialchars($c['adv_name']) ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size:.75rem">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-secondary-subtle text-secondary"
                                            title="فواتير مؤكدة"><?= $c['confirmed_cnt'] ?></span>
                                        <?php if ($c['draft_cnt'] > 0): ?>
                                            <span class="badge bg-warning-subtle text-warning" title="مسودات (غير مؤكدة)"
                                                style="font-size:.68rem">+<?= $c['draft_cnt'] ?> مسودة</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="n fw-600" style="font-size:.78rem">
                                        <?= $c['total_base'] > 0 ? number_format($c['total_base'], 2) . ' ' . htmlspecialchars($baseCurrencySymbol) : '—' ?>
                                    </td>
                                    <td class="n <?= $c['balance_base'] > 0 ? 'text-danger fw-600' : '' ?>"
                                        style="font-size:.78rem">
                                        <?= $c['balance_base'] > 0 ? number_format($c['balance_base'], 2) . ' ' . htmlspecialchars($baseCurrencySymbol) : '—' ?>
                                    </td>
                                    <td>
                                        <?php if ($c['status'] === 'active'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle"
                                                style="font-size:.68rem">نشط</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary"
                                                style="font-size:.68rem">معطّل</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1 justify-content-center">
                                            <button class="act-btn info-h" onclick="viewCustomer(<?= $c['id'] ?>)"
                                                title="عرض">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <button class="act-btn" onclick="openEdit(<?= $c['id'] ?>)" title="تعديل">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <a href="sales_index.php?customer=<?= $c['id'] ?>" class="act-btn info-h"
                                                title="فواتير العميل">
                                                <i class="bi bi-receipt"></i>
                                            </a>
                                            <a href="../accounting/customer_statement.php?customer_id=<?= $c['id'] ?>"
                                                class="act-btn" title="كشف حساب (قسم المحاسبة)">
                                                <i class="bi bi-journal-text"></i>
                                            </a>
                                            <button
                                                class="act-btn <?= $c['status'] === 'active' ? 'danger' : 'success-h' ?>"
                                                onclick="toggleStatus(<?= $c['id'] ?>)"
                                                title="<?= $c['status'] === 'active' ? 'تعطيل' : 'تفعيل' ?>">
                                                <i
                                                    class="bi bi-<?= $c['status'] === 'active' ? 'slash-circle' : 'check-circle' ?>"></i>
                                            </button>
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

    <!-- مودال إضافة/تعديل -->
    <div class="modal fade" id="custModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:var(--section-color);border-radius:16px 16px 0 0">
                    <h6 class="modal-title text-white fw-700 mb-0" id="mTitle">
                        <i class="bi bi-person-plus me-2"></i>إضافة عميل
                    </h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pt-3">
                    <input type="hidden" id="mId">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="field-lbl">اسم العميل <span class="req">*</span></label>
                            <input type="text" id="mName" class="form-control form-control-sm"
                                placeholder="الاسم الكامل أو اسم الشركة">
                        </div>
                        <div class="col-md-3">
                            <label class="field-lbl">نوع العميل</label>
                            <select id="mType" class="form-select form-select-sm">
                                <option value="wholesale">جملة</option>
                                <option value="retail">مفرق</option>
                                <option value="super_wholesale">جملة الجملة</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="field-lbl">نمط التسعير</label>
                            <div class="d-flex gap-1">
                                <select id="mPricingPattern" class="form-select form-select-sm" style="flex:1">
                                    <option value="">— بلا نمط —</option>
                                    <?php foreach ($pricingPatterns as $pp): ?>
                                        <option value="<?= $pp['id'] ?>"><?= htmlspecialchars($pp['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-sm"
                                    style="border-radius:7px;border:1px solid #16a34a;color:#16a34a;padding:4px 8px"
                                    onclick="openAddPricingPattern()" title="نمط تسعير جديد"><i
                                        class="bi bi-plus"></i></button>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="field-lbl">الحالة</label>
                            <select id="mStatus" class="form-select form-select-sm">
                                <option value="active">نشط</option>
                                <option value="inactive">معطّل</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">جهة الاتصال</label>
                            <input type="text" id="mContact" class="form-control form-control-sm"
                                placeholder="اسم المسؤول">
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">رقم الهاتف</label>
                            <input type="text" id="mPhone" class="form-control form-control-sm" dir="ltr">
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">البريد الإلكتروني</label>
                            <input type="email" id="mEmail" class="form-control form-control-sm" dir="ltr">
                        </div>
                        <div class="col-12">
                            <label class="field-lbl">العنوان</label>
                            <input type="text" id="mAddress" class="form-control form-control-sm"
                                placeholder="المدينة، الحي...">
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">الرقم الضريبي</label>
                            <input type="text" id="mTaxNo" class="form-control form-control-sm" dir="ltr">
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">حد الذمة (Credit Limit)</label>
                            <input type="number" id="mCreditLimit" class="form-control form-control-sm" min="0"
                                step="0.01" dir="ltr" placeholder="0.00">
                        </div>
                        <div class="col-md-4">
                            <label class="field-lbl">نسبة الخصم الافتراضية %</label>
                            <input type="number" id="mDiscountPct" class="form-control form-control-sm" min="0"
                                max="100" step="0.01" dir="ltr" placeholder="0">
                        </div>
                        <div class="col-md-6">
                            <label class="field-lbl">شركة الشحن</label>
                            <input type="text" id="mShip" class="form-control form-control-sm"
                                placeholder="اسم شركة الشحن">
                        </div>
                        <div class="col-md-6">
                            <label class="field-lbl">كود الشحن</label>
                            <input type="text" id="mShipCode" class="form-control form-control-sm" dir="ltr"
                                placeholder="رقم الحساب / الكود">
                        </div>
                        <div class="col-12">
                            <label class="field-lbl">ملاحظات</label>
                            <textarea id="mNotes" class="form-control form-control-sm" rows="2"
                                placeholder="اختياري"></textarea>
                        </div>

                        <!-- الحسابات المحاسبية -->
                        <div class="col-12">
                            <div class="sec-title" style="color:#1e3a8a;border-color:#bfdbfe">الربط المحاسبي</div>
                        </div>

                        <!-- عميل جديد: إنشاء إجباري، بحساب أب يختاره المستخدم -->
                        <div class="col-12" id="mAutoWrap">
                            <div
                                style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:9px;padding:10px 14px;margin-bottom:8px">
                                <div class="fw-600" style="font-size:.8rem;color:#1e3a8a">
                                    <i class="bi bi-magic me-1"></i>حسابات العميل تُنشأ تلقائياً
                                </div>
                                <div style="font-size:.72rem;color:#64748b;margin-top:4px">
                                    حساب ذمة وحساب دفعات مقدمة، تحت الحسابين الأب المضبوطين مسبقاً بصفحة
                                    <a href="../accounting/account_settings.php" target="_blank">إعدادات الربط
                                        المحاسبي</a> — إجباري لكل عمل جديد،
                                </div>
                            </div>
                        </div>

                        <!-- تعديل عميل موجود: عرض/تغيير الحساب المرتبط مباشرة -->
                        <div class="col-md-6" id="mPayAccWrap" style="display:none">
                            <label class="field-lbl">
                                <i class="bi bi-bank me-1 text-primary"></i>حساب ذمة العميل (Receivable)
                            </label>
                            <select id="mAccId" class="form-select form-select-sm">
                                <option value="">— اختر من شجرة الحسابات —</option>
                                <?php foreach ($receivableAccs as $ac): ?>
                                    <option value="<?= $ac['id'] ?>">
                                        <?= htmlspecialchars($ac['code'] . ' — ' . $ac['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="mAccBalance" style="font-size:.72rem;margin-top:4px"></div>
                        </div>
                        <div class="col-md-6" id="mPreAccWrap" style="display:none">
                            <label class="field-lbl">
                                <i class="bi bi-cash-coin me-1 text-success"></i>حساب الدفعات المقدمة (Advance)
                            </label>
                            <select id="mPrepaidId" class="form-select form-select-sm">
                                <option value="">— اختر من شجرة الحسابات —</option>
                                <?php foreach ($advanceAccs as $ac): ?>
                                    <option value="<?= $ac['id'] ?>">
                                        <?= htmlspecialchars($ac['code'] . ' — ' . $ac['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="mPrepaidBalance" style="font-size:.72rem;margin-top:4px"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button class="btn btn-sm btn-light" style="border-radius:8px"
                        data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-sm fw-600"
                        style="border-radius:8px;background:var(--section-color);color:#fff;min-width:120px"
                        onclick="saveCustomer()" id="btnSave">
                        <span id="saveTxt"><i class="bi bi-floppy me-1"></i>حفظ</span>
                        <span id="saveSpin" class="spinner-border spinner-border-sm" style="display:none"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- مودال عرض العميل -->
    <div class="modal fade" id="viewModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="border-radius:16px;border:none">
                <div class="modal-header py-3 px-4 border-0"
                    style="background:linear-gradient(135deg,#0c447c,#1e3a8a);border-radius:16px 16px 0 0">
                    <div>
                        <h6 class="modal-title text-white fw-700 mb-0" id="vTitle">تفاصيل العميل</h6>
                        <div id="vSub" style="font-size:.72rem;color:rgba(255,255,255,.7);margin-top:2px"></div>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <button id="vEditBtn" class="btn btn-sm"
                            style="border-radius:8px;background:rgba(255,255,255,.15);color:#fff;font-size:.76rem;border:1px solid rgba(255,255,255,.3)">
                            <i class="bi bi-pencil me-1"></i>تعديل
                        </button>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                </div>
                <div class="modal-body px-4 py-3" id="vBody">
                    <div class="text-center py-4"><span class="spinner-border text-primary"></span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- مودال إضافة نمط تسعير سريع -->
    <div class="modal fade" id="pricingPatternModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-sm">
            <div class="modal-content" style="border-radius:14px">
                <div class="modal-header">
                    <h6 class="modal-title fw-700"><i class="bi bi-tags me-2 text-success"></i>نمط تسعير جديد</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="field-lbl">اسم النمط <span class="req">*</span></label>
                    <input type="text" id="ppName" class="form-control form-control-sm mb-2"
                        placeholder="مثلاً: تجار الجملة الكبار">
                    <label class="field-lbl">وصف (اختياري)</label>
                    <input type="text" id="ppDescription" class="form-control form-control-sm">
                </div>
                <div class="modal-footer">
                    <button class="btn btn-sm btn-light" data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-sm fw-600" style="background:#16a34a;color:#fff"
                        onclick="saveNewPricingPattern()">حفظ</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const sb = document.getElementById('sidebar'),
            ov = document.getElementById('sbOverlay');

        function sbOpen() {
            sb.classList.add('open');
            ov.classList.add('show');
        }

        function sbClose() {
            sb.classList.remove('open');
            ov.classList.remove('show');
        }
        window.addEventListener('resize', () => {
            if (window.innerWidth > 991) sbClose();
        });

        function toggleGroup(g) {
            const o = g.classList.contains('open');
            document.querySelectorAll('.sb-group.open').forEach(x => x.classList.remove('open'));
            g.classList.toggle('open', !o);
            localStorage.setItem('sb_open_' + g.dataset.key, (!o).toString());
        }
        document.querySelectorAll('.sb-group').forEach(g => {
            if (localStorage.getItem('sb_open_' + g.dataset.key) === 'true') g.classList.add('open');
        });

        const custModal = new bootstrap.Modal(document.getElementById('custModal'));
        const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
        const TYPE_MAP = <?= json_encode($TYPE_MAP) ?>;
        const BASE_CUR_SYMBOL = <?= json_encode($baseCurrencySymbol) ?>;

        function post(data) {
            const fd = new FormData();
            Object.entries(data).forEach(([k, v]) => fd.append(k, v ?? ''));
            return fetch(location.href, {
                method: 'POST',
                body: fd
            }).then(r => r.json());
        }
        // ⚠ تهريب HTML لأي نص جاي من قاعدة البيانات قبل حقنه بـ innerHTML.
        // الجزء PHP بالجدول أصلاً بيستخدم htmlspecialchars() صح، بس مودال
        // "عرض العميل" (viewCustomer تحت) كان عم يحقن عناصر متل جهة
        // الاتصال/العنوان/شركة الشحن مباشرة بدون أي تهريب — ثغرة XSS
        // مخزّنة حقيقية (لو حد كتب مثلاً <img onerror=...> باسم جهة
        // الاتصال، رح ينفّذ أول ما موظف يفتح تفاصيل هالعميل).
        function esc(v) {
            if (v === null || v === undefined) return '';
            return String(v)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function toast(msg, type = 'success') {
            const t = document.createElement('div');
            t.className = `alert alert-${type} shadow`;
            t.style.cssText = 'position:fixed;top:70px;left:50%;transform:translateX(-50%);z-index:9999;border-radius:12px;min-width:240px;text-align:center;font-size:.83rem;padding:.5rem 1.2rem';
            t.innerHTML = `<i class="bi bi-${type === 'success' ? 'check-circle-fill text-success' : 'exclamation-triangle-fill text-danger'} me-2"></i>${msg}`;
            document.body.appendChild(t);
            setTimeout(() => t.remove(), 3000);
        }

        function resetForm() {
            ['mId', 'mName', 'mContact', 'mPhone', 'mEmail', 'mAddress', 'mTaxNo', 'mShip', 'mShipCode', 'mNotes']
                .forEach(id => document.getElementById(id).value = '');
            document.getElementById('mType').value = 'retail';
            document.getElementById('mPricingPattern').value = '';
            document.getElementById('mStatus').value = 'active';
            document.getElementById('mAccId').value = '';
            document.getElementById('mPrepaidId').value = '';
            document.getElementById('mCreditLimit').value = '0';
            document.getElementById('mDiscountPct').value = '0';
            document.getElementById('mAutoWrap').style.display = '';
            document.getElementById('mPayAccWrap').style.display = 'none';
            document.getElementById('mPreAccWrap').style.display = 'none';
        }

        function openAdd() {
            resetForm();
            document.getElementById('mTitle').innerHTML = '<i class="bi bi-person-plus me-2"></i>إضافة عميل ';
            custModal.show();
        }

        function openEdit(id) {
            post({
                _action: 'get_customer',
                id
            }).then(d => {
                if (!d.ok) {
                    toast(d.msg, 'danger');
                    return;
                }
                const c = d.data;
                document.getElementById('mId').value = c.id;
                document.getElementById('mName').value = c.name;
                document.getElementById('mContact').value = c.contact_person || '';
                document.getElementById('mType').value = c.type || 'retail';
                document.getElementById('mPricingPattern').value = c.pricing_pattern_id || '';
                document.getElementById('mPhone').value = c.phone || '';
                document.getElementById('mEmail').value = c.email || '';
                document.getElementById('mAddress').value = c.address || '';
                document.getElementById('mTaxNo').value = c.tax_number || '';
                document.getElementById('mShip').value = c.shipping_company || '';
                document.getElementById('mShipCode').value = c.shipping_code || '';
                document.getElementById('mCreditLimit').value = c.credit_limit || '0';
                document.getElementById('mDiscountPct').value = c.discount_percentage || '0';
                document.getElementById('mNotes').value = c.notes || '';
                document.getElementById('mStatus').value = c.status || 'active';
                document.getElementById('mAccId').value = c.account_id || '';
                document.getElementById('mPrepaidId').value = c.prepaid_account_id || '';
                // عرض رصيد كل حساب بعملته الخاصة — بلون أخضر لو دائن
                // (رصيد موجب لصالحنا)، أحمر لو مدين علينا
                const fmtBal = (bal, sym) => {
                    if (bal === null || bal === undefined) return '';
                    const n = parseFloat(bal);
                    const color = n > 0 ? '#16a34a' : (n < 0 ? '#dc2626' : '#94a3b8');
                    return `<span style="color:${color};font-weight:600">الرصيد الحالي: ${n.toFixed(2)} ${sym || ''}</span>`;
                };
                document.getElementById('mAccBalance').innerHTML = c.rec_code ?
                    fmtBal(c.rec_balance, c.rec_cur_sym) : '';
                document.getElementById('mPrepaidBalance').innerHTML = c.adv_code ?
                    fmtBal(c.adv_balance, c.adv_cur_sym) : '';
                // تعديل عميل موجود: الحسابات أصلاً منشأة — نعرض قوائم
                // الاختيار المباشر (عرض/تغيير)، مو مربّع الإنشاء التلقائي.
                document.getElementById('mAutoWrap').style.display = 'none';
                document.getElementById('mPayAccWrap').style.display = '';
                document.getElementById('mPreAccWrap').style.display = '';
                document.getElementById('mTitle').textContent = 'تعديل: ' + c.name;
                viewModal.hide();
                custModal.show();
            });
        }

        function saveCustomer() {
            const name = document.getElementById('mName').value.trim();
            if (!name) {
                toast('اسم العميل مطلوب', 'danger');
                return;
            }
            document.getElementById('saveTxt').style.opacity = '0';
            document.getElementById('saveSpin').style.display = 'inline-block';
            post({
                _action: 'save_customer',
                id: document.getElementById('mId').value,
                name,
                contact_person: document.getElementById('mContact').value,
                type: document.getElementById('mType').value,
                pricing_pattern_id: document.getElementById('mPricingPattern').value,
                phone: document.getElementById('mPhone').value,
                email: document.getElementById('mEmail').value,
                address: document.getElementById('mAddress').value,
                tax_number: document.getElementById('mTaxNo').value,
                shipping_company: document.getElementById('mShip').value,
                shipping_code: document.getElementById('mShipCode').value,
                credit_limit: document.getElementById('mCreditLimit').value,
                discount_percentage: document.getElementById('mDiscountPct').value,
                notes: document.getElementById('mNotes').value,
                status: document.getElementById('mStatus').value,
                account_id: document.getElementById('mAccId').value,
                prepaid_account_id: document.getElementById('mPrepaidId').value,
            }).then(d => {
                document.getElementById('saveTxt').style.opacity = '1';
                document.getElementById('saveSpin').style.display = 'none';
                if (d.ok) {
                    toast('✅ ' + (d.msg || 'تم الحفظ بنجاح'));
                    custModal.hide();
                    setTimeout(() => location.reload(), 700);
                } else toast(d.msg, 'danger');
            });
        }

        // ── نمط تسعير جديد (زر "+" جنب حقل نمط التسعير) — نفس نمط
        // "إضافة فئة جديدة" بصفحة المنتجات بالضبط ──
        let pricingPatternModal;
        function openAddPricingPattern() {
            document.getElementById('ppName').value = '';
            document.getElementById('ppDescription').value = '';
            if (!pricingPatternModal) pricingPatternModal = new bootstrap.Modal(document.getElementById('pricingPatternModal'));
            pricingPatternModal.show();
        }
        function saveNewPricingPattern() {
            const name = document.getElementById('ppName').value.trim();
            if (!name) { toast('اسم نمط التسعير مطلوب', 'danger'); return; }
            post({
                _action: 'add_pricing_pattern',
                name,
                description: document.getElementById('ppDescription').value,
            }).then(d => {
                if (!d.ok) { toast(d.msg, 'danger'); return; }
                const sel = document.getElementById('mPricingPattern');
                const opt = document.createElement('option');
                opt.value = d.id; opt.textContent = d.name; opt.selected = true;
                sel.appendChild(opt);
                pricingPatternModal.hide();
                toast(d.msg || 'تمت الإضافة');
            });
        }

        function viewCustomer(id) {
            document.getElementById('vTitle').textContent = 'جارٍ التحميل...';
            document.getElementById('vSub').textContent = '';
            document.getElementById('vEditBtn').onclick = () => openEdit(id);
            document.getElementById('vBody').innerHTML = '<div class="text-center py-4"><span class="spinner-border text-primary"></span></div>';
            viewModal.show();
            post({
                _action: 'get_customer',
                id
            }).then(d => {
                if (!d.ok) {
                    document.getElementById('vBody').innerHTML = `<div class="text-danger p-3">${d.msg}</div>`;
                    return;
                }
                const c = d.data;
                const st = d.data.stats || {};
                document.getElementById('vTitle').textContent = c.name;
                document.getElementById('vSub').textContent = TYPE_MAP[c.type] || '';
                const TYPE_BADGE = {
                    wholesale: '<span class="badge bg-warning-subtle text-warning">جملة</span>',
                    retail: '<span class="badge bg-secondary-subtle text-secondary">مفرق</span>',
                    super_wholesale: '<span class="badge bg-success-subtle text-success">جملة الجملة</span>',
                };
                const rows = [
                    ['النوع', TYPE_BADGE[c.type] || '—'],
                    ['نمط التسعير', c.pricing_pattern_name ? esc(c.pricing_pattern_name) : '—'],
                    ['جهة الاتصال', c.contact_person ? esc(c.contact_person) : '—'],
                    ['الهاتف', c.phone ? `<span dir="ltr">${esc(c.phone)}</span>` : '—'],
                    ['البريد', c.email ? `<a href="mailto:${esc(c.email)}" dir="ltr">${esc(c.email)}</a>` : '—'],
                    ['العنوان', c.address ? esc(c.address) : '—'],
                    ['الرقم الضريبي', c.tax_number ? `<span dir="ltr">${esc(c.tax_number)}</span>` : '—'],
                    ['شركة الشحن', c.shipping_company ? esc(c.shipping_company) : '—'],
                    ['كود الشحن', c.shipping_code ? `<span dir="ltr">${esc(c.shipping_code)}</span>` : '—'],
                    ['حد الذمة', parseFloat(c.credit_limit || 0) > 0 ? `<span class="n fw-600">${parseFloat(c.credit_limit).toFixed(2)}</span>` : '—'],
                    ['نسبة الخصم الافتراضية', parseFloat(c.discount_percentage || 0) > 0 ? `<span class="n fw-600 text-success">${parseFloat(c.discount_percentage).toFixed(2)}%</span>` : '—'],
                    ['الحالة', c.status === 'active' ? '<span class="badge bg-success-subtle text-success border border-success-subtle">نشط</span>' : '<span class="badge bg-secondary-subtle text-secondary">معطّل</span>'],
                ].map(([k, v]) => `<div class="det-row"><span style="color:#64748b">${k}</span><span>${v}</span></div>`).join('');
                const notesHtml = c.notes ? `<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:8px 12px;margin-top:10px;font-size:.78rem;color:#92400e"><i class="bi bi-sticky me-1"></i>${esc(c.notes)}</div>` : '';

                document.getElementById('vBody').innerHTML = `
        ${rows}
        ${notesHtml}
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:10px 14px;margin-top:12px">
            <div style="font-size:.75rem;font-weight:700;color:#1e3a8a;margin-bottom:6px">
                <i class="bi bi-bank me-1"></i>الربط المحاسبي
            </div>
            <div class="det-row">
                <span style="color:#64748b">حساب الذمة</span>
                <span>${c.rec_code ? `<span class="acc-badge">${esc(c.rec_code)}</span> ${esc(c.rec_name)}` : '<span class="text-danger">غير محدد</span>'}</span>
            </div>
            ${c.rec_code ? `<div class="det-row"><span style="color:#64748b">رصيد الذمة</span><span class="n fw-600" style="color:${parseFloat(c.rec_balance) > 0 ? '#16a34a' : (parseFloat(c.rec_balance) < 0 ? '#dc2626' : '#94a3b8')}">${parseFloat(c.rec_balance || 0).toFixed(2)} ${c.rec_cur_sym || ''}</span></div>` : ''}
            <div class="det-row">
                <span style="color:#64748b">حساب الدفعة المقدمة</span>
                <span>${c.adv_code ? `<span class="acc-badge">${esc(c.adv_code)}</span> ${esc(c.adv_name)}` : '<span class="text-muted">—</span>'}</span>
            </div>
            ${c.adv_code ? `<div class="det-row"><span style="color:#64748b">رصيد الدفعة المقدمة</span><span class="n fw-600" style="color:${parseFloat(c.adv_balance) > 0 ? '#16a34a' : (parseFloat(c.adv_balance) < 0 ? '#dc2626' : '#94a3b8')}">${parseFloat(c.adv_balance || 0).toFixed(2)} ${c.adv_cur_sym || ''}</span></div>` : ''}
        </div>
        <div style="background:#f8fafc;border-radius:10px;padding:10px 14px;margin-top:12px">
            <div style="font-size:.75rem;font-weight:700;color:#1e293b;margin-bottom:6px">إحصائيات المبيعات</div>
            <div class="det-row"><span style="color:#64748b">فواتير مؤكدة</span><span class="fw-600">${st.confirmed_cnt || 0}</span></div>
            <div class="det-row"><span style="color:#64748b">مسودات (غير مؤكدة)</span><span class="fw-600 text-muted">${st.draft_cnt || 0}</span></div>
            <div class="det-row"><span style="color:#64748b">إجمالي المبيعات (المؤكدة فقط)</span><span class="n fw-600">${parseFloat(st.confirmed_total_base || 0).toFixed(2)} ${BASE_CUR_SYMBOL}</span></div>
            <div class="det-row"><span style="color:#dc2626">المستحق</span><span class="n fw-600 text-danger">${parseFloat(st.balance_base || 0).toFixed(2)} ${BASE_CUR_SYMBOL}</span></div>
        </div>
        <div style="margin-top:10px;display:flex;gap:8px">
            <a href="sales_index.php?customer=${c.id}" class="btn btn-sm fw-600"
               style="border-radius:8px;border:1px solid #1e3a8a;color:#1e3a8a;flex:1;text-align:center;text-decoration:none;font-size:.78rem">
               <i class="bi bi-receipt me-1"></i>فواتير العميل
            </a>
            <a href="../accounting/customer_statement.php?customer_id=${c.id}" class="btn btn-sm fw-600"
               style="border-radius:8px;border:1px solid #64748b;color:#64748b;flex:1;text-align:center;text-decoration:none;font-size:.78rem">
               <i class="bi bi-journal-text me-1"></i>كشف حساب
            </a>
        </div>`;
            });
        }

        function toggleStatus(id) {
            post({
                _action: 'toggle_status',
                id
            }).then(d => {
                if (d.ok) {
                    toast(d.status === 'active' ? 'تم التفعيل' : 'تم التعطيل');
                    setTimeout(() => location.reload(), 600);
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

            const isoDateRe = /^\d{4}-\d{2}-\d{2}/;
            rows.sort((a, b) => {
                const valA = getCellValue(a),
                    valB = getCellValue(b);
                if (isoDateRe.test(valA) && isoDateRe.test(valB)) {
                    const dA = new Date(valA.slice(0, 10)).getTime();
                    const dB = new Date(valB.slice(0, 10)).getTime();
                    return isAsc ? dA - dB : dB - dA;
                }
                const cleanA = valA.replace(/[^0-9.\-]/g, '');
                const cleanB = valB.replace(/[^0-9.\-]/g, '');
                const fullyNumeric = /^-?[0-9]+(\.[0-9]+)?$/.test(cleanA) && /^-?[0-9]+(\.[0-9]+)?$/.test(cleanB) &&
                    cleanA !== '' && cleanB !== '' && cleanA !== '-' && cleanB !== '-';
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

        makeSortable(document.getElementById('customersTbl'));


    </script>
</body>

</html>