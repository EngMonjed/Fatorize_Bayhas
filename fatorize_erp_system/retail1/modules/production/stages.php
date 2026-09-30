<?php
/**
 * production/stages.php — إعداد الإنتاج: مراحل + محطات عمل + مقاولين
 * المسار: retail1|factory1/modules/production/stages.php
 * ٣ تبويبات بصفحة وحدة، كل تبويب صلاحيته الخاصة (ب.٢٢):
 *   production.stages / production.work_centers / production.contractors
 */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('production.stages', 'view');

$TS = $_SESSION['table_suffix'];
$currentModule = 'production.stages';
$userId = (int) $_SESSION['user_id'];

$T_STAGE = "production_stages_{$TS}";
$T_WC = "production_work_centers_{$TS}";
$T_WCU = "production_work_center_users_{$TS}";
$T_CONTR = "production_contractors_{$TS}";
$T_ACCOUNT = "account_charts_{$TS}";
$T_SETTINGS = "invoice_account_settings_{$TS}";

/**
 * ينشئ حسابين محاسبيين لمورد خدمة جديد (ذمم + دفعة مقدمة)، بنفس الرقم
 * التسلسلي، تحت الحسابين الأب المضبوطين بجدول إعدادات الربط المحاسبي.
 * يرمي Exception لو الإعدادات ناقصة — ما بيخمّن أي شي.
 */
function createServiceVendorAccounts(PDO $pdo, string $T_SETTINGS, string $T_ACCOUNT, string $vendorName, int $userId): array
{
    $st = $pdo->prepare("SELECT setting_key, account_id FROM {$T_SETTINGS} WHERE setting_key IN ('service_vendor_payable','service_vendor_advance')");
    $st->execute();
    $settings = [];
    foreach ($st->fetchAll() as $row)
        $settings[$row['setting_key']] = (int) $row['account_id'];

    if (empty($settings['service_vendor_payable']) || empty($settings['service_vendor_advance'])) {
        throw new Exception('لسا ما انضبطت حسابات موردي الخدمة الأب بدليل الحسابات — راجع مسؤول النظام لإضافتها أول (ذمم موردي الخدمة + دفعات مقدمة لموردي الخدمة)');
    }

    $accSt = $pdo->prepare("SELECT code, level, cash_flow_category FROM {$T_ACCOUNT} WHERE id = ?");
    $accSt->execute([$settings['service_vendor_payable']]);
    $payableParent = $accSt->fetch();
    $accSt->execute([$settings['service_vendor_advance']]);
    $advanceParent = $accSt->fetch();
    if (!$payableParent || !$advanceParent) {
        throw new Exception('الحسابات الأب المضبوطة بالإعدادات غير موجودة فعلياً بدليل الحسابات');
    }

    // رقم تسلسلي جديد — فرع الذمم هو المرجع الوحيد لتوليد الرقم، ونفس
    // الرقم يُطبَّق على فرع الدفعات المقدمة كمان (نفس نمط الموردين الحاليين)
    $seqSt = $pdo->prepare("SELECT code FROM {$T_ACCOUNT} WHERE code LIKE ? ORDER BY id DESC LIMIT 1");
    $seqSt->execute([$payableParent['code'] . '.%']);
    $lastCode = $seqSt->fetchColumn();
    $nextNum = 1;
    if ($lastCode) {
        $parts = explode('.', $lastCode);
        $nextNum = (int) end($parts) + 1;
    }
    $seq = str_pad((string) $nextNum, 3, '0', STR_PAD_LEFT);

    $ins = $pdo->prepare("
        INSERT INTO {$T_ACCOUNT}
            (code, name, parent_id, account_type, cash_flow_category, currency_id, exchange_rate, level, is_active, is_locked, created_by)
        VALUES (?, ?, ?, ?, ?, 1, 1.0000, ?, 1, 0, ?)
    ");
    $ins->execute([
        $payableParent['code'] . '.' . $seq,
        'ذمم ' . $vendorName,
        $settings['service_vendor_payable'],
        'liability',
        $payableParent['cash_flow_category'],
        (int) $payableParent['level'] + 1,
        $userId
    ]);
    $payableAccountId = (int) $pdo->lastInsertId();

    $ins->execute([
        $advanceParent['code'] . '.' . $seq,
        'دفعات مقدمة — ' . $vendorName,
        $settings['service_vendor_advance'],
        'asset',
        $advanceParent['cash_flow_category'],
        (int) $advanceParent['level'] + 1,
        $userId
    ]);
    $advanceAccountId = (int) $pdo->lastInsertId();

    return [$payableAccountId, $advanceAccountId];
}

$activeTab = $_GET['tab'] ?? 'stages';
if (!in_array($activeTab, ['stages', 'work_centers', 'contractors'], true))
    $activeTab = 'stages';

// اسم الفرع + عملته الوظيفية
$branchName = '';
$baseCurSym = '';
try {
    $bn = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
    $bn->execute([$_SESSION['branch_id'] ?? 0]);
    $brow = $bn->fetch();
    if ($brow) {
        $branchName = $brow['name'] ?? ($brow['branch_name'] ?? '');
        $baseCurSym = $brow['base_currency'] ?? '';
    }
} catch (Throwable $e) { /* يُعرض بدون اسم/عملة بدل ما تنكسر الصفحة */
}

// ===================== AJAX =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $action = $_POST['_action'];

        switch ($action) {

            // ---------- مراحل التصنيع ----------
            case 'stage_get': {
                $id = (int) ($_POST['id'] ?? 0);
                $st = $pdo->prepare("SELECT * FROM {$T_STAGE} WHERE id = ?");
                $st->execute([$id]);
                $row = $st->fetch();
                if (!$row)
                    throw new Exception('المرحلة غير موجودة');
                echo json_encode(['ok' => true, 'data' => $row]);
                exit;
            }

            case 'stage_save': {
                $id = (int) ($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $code = trim($_POST['code'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                if ($name === '')
                    throw new Exception('اسم المرحلة مطلوب');

                if ($id > 0) {
                    requirePermission('production.stages', 'edit');
                    $pdo->prepare("UPDATE {$T_STAGE} SET name=?, code=?, description=? WHERE id=?")
                        ->execute([$name, $code !== '' ? $code : null, $desc !== '' ? $desc : null, $id]);
                    echo json_encode(['ok' => true, 'msg' => 'تم تحديث المرحلة']);
                } else {
                    requirePermission('production.stages', 'create');
                    $pdo->prepare("INSERT INTO {$T_STAGE} (name, code, description) VALUES (?,?,?)")
                        ->execute([$name, $code !== '' ? $code : null, $desc !== '' ? $desc : null]);
                    echo json_encode(['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'msg' => 'تمت إضافة المرحلة']);
                }
                exit;
            }

            case 'stage_toggle': {
                requirePermission('production.stages', 'edit');
                $id = (int) ($_POST['id'] ?? 0);
                $st = $pdo->prepare("SELECT is_active FROM {$T_STAGE} WHERE id=?");
                $st->execute([$id]);
                $cur = $st->fetchColumn();
                if ($cur === false)
                    throw new Exception('المرحلة غير موجودة');
                $pdo->prepare("UPDATE {$T_STAGE} SET is_active=? WHERE id=?")->execute([$cur ? 0 : 1, $id]);
                echo json_encode(['ok' => true, 'is_active' => $cur ? 0 : 1]);
                exit;
            }

            // ---------- محطات العمل ----------
            case 'wc_get': {
                $id = (int) ($_POST['id'] ?? 0);
                $st = $pdo->prepare("SELECT * FROM {$T_WC} WHERE id = ?");
                $st->execute([$id]);
                $row = $st->fetch();
                if (!$row)
                    throw new Exception('محطة العمل غير موجودة');
                echo json_encode(['ok' => true, 'data' => $row]);
                exit;
            }

            case 'wc_save': {
                $id = (int) ($_POST['id'] ?? 0);
                $stageId = (int) ($_POST['stage_id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $type = ($_POST['type'] ?? '') === 'external' ? 'external' : 'internal';
                $contractorId = $type === 'external' ? (int) ($_POST['contractor_id'] ?? 0) : null;
                $rate = (float) ($_POST['rate'] ?? 0);

                if ($stageId <= 0)
                    throw new Exception('اختر المرحلة');
                if ($name === '')
                    throw new Exception('اسم المحطة مطلوب');
                if ($type === 'external' && !$contractorId)
                    throw new Exception('اختر مورد الخدمة للمحطة الخارجية');
                if ($rate < 0)
                    throw new Exception('التسعيرة ما بتنكون سالبة');

                if ($id > 0) {
                    requirePermission('production.work_centers', 'edit');
                    $pdo->prepare("UPDATE {$T_WC} SET stage_id=?, name=?, type=?, contractor_id=?, rate=? WHERE id=?")
                        ->execute([$stageId, $name, $type, $contractorId, $rate, $id]);
                    echo json_encode(['ok' => true, 'msg' => 'تم تحديث محطة العمل']);
                } else {
                    requirePermission('production.work_centers', 'create');
                    $pdo->prepare("INSERT INTO {$T_WC} (stage_id, name, type, contractor_id, rate) VALUES (?,?,?,?,?)")
                        ->execute([$stageId, $name, $type, $contractorId, $rate]);
                    echo json_encode(['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'msg' => 'تمت إضافة محطة العمل']);
                }
                exit;
            }

            case 'wc_toggle': {
                requirePermission('production.work_centers', 'edit');
                $id = (int) ($_POST['id'] ?? 0);
                $st = $pdo->prepare("SELECT is_active FROM {$T_WC} WHERE id=?");
                $st->execute([$id]);
                $cur = $st->fetchColumn();
                if ($cur === false)
                    throw new Exception('محطة العمل غير موجودة');
                $pdo->prepare("UPDATE {$T_WC} SET is_active=? WHERE id=?")->execute([$cur ? 0 : 1, $id]);
                echo json_encode(['ok' => true, 'is_active' => $cur ? 0 : 1]);
                exit;
            }

            case 'wc_list_users': {
                $wcId = (int) ($_POST['work_center_id'] ?? 0);
                $st = $pdo->prepare("
                    SELECT wcu.id, wcu.user_id, wcu.can_approve, wcu.can_execute,
                           COALESCE(u.full_name, u.username, CONCAT('مستخدم #', u.id)) AS user_label
                    FROM {$T_WCU} wcu
                    JOIN users u ON u.id = wcu.user_id
                    WHERE wcu.work_center_id = ?
                    ORDER BY user_label
                ");
                $st->execute([$wcId]);
                echo json_encode(['ok' => true, 'data' => $st->fetchAll()]);
                exit;
            }

            case 'wc_user_add': {
                requirePermission('production.work_centers', 'edit');
                $wcId = (int) ($_POST['work_center_id'] ?? 0);
                $uId = (int) ($_POST['user_id'] ?? 0);
                $canApprove = !empty($_POST['can_approve']) ? 1 : 0;
                $canExecute = !empty($_POST['can_execute']) ? 1 : 0;
                if ($wcId <= 0 || $uId <= 0)
                    throw new Exception('بيانات ناقصة');
                if (!$canApprove && !$canExecute)
                    throw new Exception('لازم صلاحية وحدة عالأقل (قبول أو تنفيذ)');

                $pdo->prepare("
                    INSERT INTO {$T_WCU} (work_center_id, user_id, can_approve, can_execute)
                    VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE can_approve=VALUES(can_approve), can_execute=VALUES(can_execute)
                ")->execute([$wcId, $uId, $canApprove, $canExecute]);
                echo json_encode(['ok' => true, 'msg' => 'تم حفظ التخويل']);
                exit;
            }

            case 'wc_user_remove': {
                requirePermission('production.work_centers', 'edit');
                $id = (int) ($_POST['id'] ?? 0);
                $pdo->prepare("DELETE FROM {$T_WCU} WHERE id=?")->execute([$id]);
                echo json_encode(['ok' => true, 'msg' => 'تم إلغاء التخويل']);
                exit;
            }

            case 'list_users_for_auth': {
                // قائمة مستخدمين لإضافة تخويل جديد — بحث بسيط بالاسم/اسم المستخدم
                $q = trim($_POST['q'] ?? '');
                $sql = "SELECT id, COALESCE(full_name, username, CONCAT('مستخدم #', id)) AS label FROM users";
                $params = [];
                if ($q !== '') {
                    $sql .= " WHERE full_name LIKE ? OR username LIKE ?";
                    $params = ["%$q%", "%$q%"];
                }
                $sql .= " ORDER BY label LIMIT 20";
                $st = $pdo->prepare($sql);
                $st->execute($params);
                echo json_encode(['ok' => true, 'data' => $st->fetchAll()]);
                exit;
            }

            // ---------- موردي الخدمات ----------
            case 'contractor_get': {
                $id = (int) ($_POST['id'] ?? 0);
                $st = $pdo->prepare("SELECT * FROM {$T_CONTR} WHERE id = ?");
                $st->execute([$id]);
                $row = $st->fetch();
                if (!$row)
                    throw new Exception('مورد الخدمة غير موجود');
                echo json_encode(['ok' => true, 'data' => $row]);
                exit;
            }

            case 'contractor_save': {
                $id = (int) ($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $contact = trim($_POST['contact_info'] ?? '');
                if ($name === '')
                    throw new Exception('اسم مورد الخدمة مطلوب');

                if ($id > 0) {
                    requirePermission('production.contractors', 'edit');
                    $pdo->prepare("UPDATE {$T_CONTR} SET name=?, contact_info=? WHERE id=?")
                        ->execute([$name, $contact !== '' ? $contact : null, $id]);
                    // مزامنة اسم الحسابين المرتبطين (لو موجودين) مع الاسم الجديد
                    $cSt = $pdo->prepare("SELECT account_id, advance_account_id FROM {$T_CONTR} WHERE id=?");
                    $cSt->execute([$id]);
                    if ($crow = $cSt->fetch()) {
                        if ($crow['account_id'])
                            $pdo->prepare("UPDATE {$T_ACCOUNT} SET name=? WHERE id=?")->execute(['ذمم ' . $name, $crow['account_id']]);
                        if ($crow['advance_account_id'])
                            $pdo->prepare("UPDATE {$T_ACCOUNT} SET name=? WHERE id=?")->execute(['دفعات مقدمة — ' . $name, $crow['advance_account_id']]);
                    }
                    echo json_encode(['ok' => true, 'msg' => 'تم تحديث مورد الخدمة']);
                } else {
                    requirePermission('production.contractors', 'create');
                    $pdo->beginTransaction();
                    try {
                        [$payableId, $advanceId] = createServiceVendorAccounts($pdo, $T_SETTINGS, $T_ACCOUNT, $name, $userId);
                        $pdo->prepare("INSERT INTO {$T_CONTR} (name, contact_info, account_id, advance_account_id) VALUES (?,?,?,?)")
                            ->execute([$name, $contact !== '' ? $contact : null, $payableId, $advanceId]);
                        $newId = (int) $pdo->lastInsertId();
                        $pdo->commit();
                        echo json_encode(['ok' => true, 'id' => $newId, 'msg' => 'تمت إضافة المورد، وأُنشئ حسابه المحاسبي (ذمم + دفعة مقدمة) تلقائياً']);
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        throw $e;
                    }
                }
                exit;
            }

            case 'contractor_toggle': {
                requirePermission('production.contractors', 'edit');
                $id = (int) ($_POST['id'] ?? 0);
                $st = $pdo->prepare("SELECT is_active FROM {$T_CONTR} WHERE id=?");
                $st->execute([$id]);
                $cur = $st->fetchColumn();
                if ($cur === false)
                    throw new Exception('مورد الخدمة غير موجود');
                $pdo->prepare("UPDATE {$T_CONTR} SET is_active=? WHERE id=?")->execute([$cur ? 0 : 1, $id]);
                echo json_encode(['ok' => true, 'is_active' => $cur ? 0 : 1]);
                exit;
            }

            default:
                throw new Exception('إجراء غير معروف');
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ===================== بيانات صفحة العرض =====================

$stages = $pdo->query("SELECT * FROM {$T_STAGE} ORDER BY name")->fetchAll();

$workCenters = $pdo->query("
    SELECT wc.*, s.name AS stage_name, c.name AS contractor_name
    FROM {$T_WC} wc
    LEFT JOIN {$T_STAGE} s ON s.id = wc.stage_id
    LEFT JOIN {$T_CONTR} c ON c.id = wc.contractor_id
    ORDER BY s.name, wc.name
")->fetchAll();

$contractors = $pdo->query("
    SELECT c.*, pa.code AS payable_code, pa.name AS payable_name,
           aa.code AS advance_code, aa.name AS advance_name
    FROM {$T_CONTR} c
    LEFT JOIN {$T_ACCOUNT} pa ON pa.id = c.account_id
    LEFT JOIN {$T_ACCOUNT} aa ON aa.id = c.advance_account_id
    ORDER BY c.name
")->fetchAll();

// هل إعدادات حسابات موردي الخدمة مضبوطة؟ (للتنبيه بالواجهة قبل أي محاولة حفظ)
$serviceVendorSettingsReady = false;
try {
    $cnt = $pdo->query("SELECT COUNT(*) FROM {$T_SETTINGS} WHERE setting_key IN ('service_vendor_payable','service_vendor_advance')")->fetchColumn();
    $serviceVendorSettingsReady = ((int) $cnt === 2);
} catch (Throwable $e) { /* يُعامل كغير مضبوط */
}

$can_stage_create = can('production.stages', 'create');
$can_stage_edit = can('production.stages', 'edit');
$can_wc_create = can('production.work_centers', 'create');
$can_wc_edit = can('production.work_centers', 'edit');
$can_contr_create = can('production.contractors', 'create');
$can_contr_edit = can('production.contractors', 'edit');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إعداد الإنتاج — FATORIZE</title>
    <link rel="icon" type="image/png" href="<?= BASE_PATH ?>/assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/assets/css/layout.css" rel="stylesheet">
    <style>
        .badge-status {
            padding: .25rem .6rem;
            border-radius: 20px;
            font-size: .75rem;
            font-weight: 600;
        }

        .badge-active {
            background: #ecfdf5;
            color: #16a34a;
        }

        .badge-inactive {
            background: #f1f5f9;
            color: #64748b;
        }

        .badge-type-internal {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .badge-type-external {
            background: #fdf4ff;
            color: #a21caf;
        }

        .subtab-nav {
            display: flex;
            gap: .4rem;
            margin-bottom: 1.1rem;
        }

        .subtab-nav button {
            padding: .5rem 1rem;
            font-size: .82rem;
            font-weight: 600;
            color: #64748b;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 9px;
            cursor: pointer;
        }

        .subtab-nav button.active {
            color: #fff;
            background: var(--section-color);
            border-color: var(--section-color);
        }

        .subtab-pane {
            display: none;
        }

        .subtab-pane.active {
            display: block;
        }
    </style>
</head>

<body>
    <div class="sb-overlay" id="sbOverlay" onclick="sbClose()"></div>
    <?php require_once __DIR__ . '/../../../includes/sidebar.php'; ?>
    <header class="topbar">
        <button class="tb-toggle" onclick="sbOpen()"><i class="bi bi-list"></i></button>
        <span class="tb-title"><i class="bi bi-sliders me-1" style="color:var(--section-color)"></i>إعداد الإنتاج</span>
        <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
        <nav class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem;color:#94a3b8">
            <span>الإنتاج</span>
            <i class="bi bi-chevron-left mx-1" style="font-size:.65rem"></i>
            <span class="fw-600" style="color:var(--section-color)">إعداد الإنتاج</span>
        </nav>
    </header>

    <main class="main-content">
        <div class="content-body">

            <ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e2e8f0">
                <li class="nav-item">
                    <a class="nav-link fw-600" href="raw_materials.php"
                        style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-box2 me-1"></i>المواد الأولية
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600 active" href="stages.php"
                        style="border:none;border-bottom:2px solid var(--section-color);color:var(--section-color);font-size:.83rem;margin-bottom:-2px">
                        <i class="bi bi-sliders me-1"></i>إعداد الإنتاج
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="operations.php" style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-diagram-3 me-1"></i>عمليات التصنيع
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-600" href="production_entries.php"
                        style="border:none;color:#64748b;font-size:.83rem">
                        <i class="bi bi-clipboard-check me-1"></i>أوامر الإنتاج
                    </a>
                </li>
            </ul>

            <div class="subtab-nav">
                <button type="button" data-tab="stages" onclick="showSubTab('stages')"><i
                        class="bi bi-diagram-3 me-1"></i>مراحل التصنيع</button>
                <button type="button" data-tab="work_centers" onclick="showSubTab('work_centers')"><i
                        class="bi bi-diagram-2 me-1"></i>محطات العمل</button>
                <button type="button" data-tab="contractors" onclick="showSubTab('contractors')"><i
                        class="bi bi-building me-1"></i>موردي الخدمات</button>
            </div>

            <!-- ===================== تبويب: مراحل التصنيع ===================== -->
            <div class="subtab-pane" id="pane-stages">
                <div class="tbl-wrap">
                    <div class="tbl-hdr">
                        <span style="font-size:.88rem;font-weight:700;color:#1e293b">
                            <i class="bi bi-list-ul me-1" style="color:var(--section-color)"></i>مراحل التصنيع
                        </span>
                        <?php if ($can_stage_create): ?>
                            <button class="btn btn-sm fw-600 ms-auto" onclick="openStageModal()"
                                style="border-radius:9px;background:var(--section-color);color:#fff;font-size:.82rem">
                                <i class="bi bi-plus-lg me-1"></i>إضافة مرحلة
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="table-responsive">
                        <table class="mtbl">
                            <thead>
                                <tr>
                                    <th>الاسم</th>
                                    <th>الرمز</th>
                                    <th>الوصف</th>
                                    <th>الحالة</th>
                                    <th style="text-align:center">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stages as $s):
                                    $active = (int) $s['is_active'] === 1; ?>
                                    <tr>
                                        <td class="fw-600"><?= htmlspecialchars($s['name']) ?></td>
                                        <td class="text-muted"><?= htmlspecialchars($s['code'] ?? '—') ?></td>
                                        <td class="text-muted" style="font-size:.82rem">
                                            <?= htmlspecialchars($s['description'] ?? '—') ?>
                                        </td>
                                        <td><span
                                                class="badge-status <?= $active ? 'badge-active' : 'badge-inactive' ?>"><?= $active ? 'نشطة' : 'معطّلة' ?></span>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1 justify-content-center">
                                                <?php if ($can_stage_edit): ?>
                                                    <button class="act-btn" title="تعديل"
                                                        onclick="openStageModal(<?= (int) $s['id'] ?>)"><i
                                                            class="bi bi-pencil"></i></button>
                                                    <button class="act-btn <?= $active ? 'danger' : 'success-h' ?>"
                                                        title="<?= $active ? 'تعطيل' : 'تفعيل' ?>"
                                                        onclick="stageToggle(<?= (int) $s['id'] ?>)"><i
                                                            class="bi bi-<?= $active ? 'slash-circle' : 'check-circle' ?>"></i></button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$stages): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">ما في مراحل مسجّلة بعد</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ===================== تبويب: محطات العمل ===================== -->
            <div class="subtab-pane" id="pane-work_centers">
                <div class="tbl-wrap">
                    <div class="tbl-hdr">
                        <span style="font-size:.88rem;font-weight:700;color:#1e293b">
                            <i class="bi bi-list-ul me-1" style="color:var(--section-color)"></i>محطات العمل
                        </span>
                        <?php if ($can_wc_create): ?>
                            <button class="btn btn-sm fw-600 ms-auto" onclick="openWcModal()"
                                style="border-radius:9px;background:var(--section-color);color:#fff;font-size:.82rem">
                                <i class="bi bi-plus-lg me-1"></i>إضافة محطة عمل
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="table-responsive">
                        <table class="mtbl">
                            <thead>
                                <tr>
                                    <th>المحطة</th>
                                    <th>المرحلة</th>
                                    <th>النوع</th>
                                    <th>مورد الخدمة</th>
                                    <th>التسعيرة</th>
                                    <th>الحالة</th>
                                    <th style="text-align:center">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($workCenters as $w):
                                    $active = (int) $w['is_active'] === 1;
                                    $isExt = $w['type'] === 'external'; ?>
                                    <tr>
                                        <td class="fw-600"><?= htmlspecialchars($w['name']) ?></td>
                                        <td class="text-muted"><?= htmlspecialchars($w['stage_name'] ?? '—') ?></td>
                                        <td><span
                                                class="badge-status <?= $isExt ? 'badge-type-external' : 'badge-type-internal' ?>"><?= $isExt ? 'خارجية' : 'داخلية' ?></span>
                                        </td>
                                        <td class="text-muted"><?= htmlspecialchars($w['contractor_name'] ?? '—') ?></td>
                                        <td class="n"><?= number_format((float) $w['rate'], 2) ?>
                                            <?= htmlspecialchars($baseCurSym) ?>
                                        </td>
                                        <td><span
                                                class="badge-status <?= $active ? 'badge-active' : 'badge-inactive' ?>"><?= $active ? 'نشطة' : 'معطّلة' ?></span>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1 justify-content-center">
                                                <button class="act-btn info-h" title="تخويل المستخدمين"
                                                    onclick="openWcUsersModal(<?= (int) $w['id'] ?>, '<?= htmlspecialchars($w['name'], ENT_QUOTES) ?>')"><i
                                                        class="bi bi-people"></i></button>
                                                <?php if ($can_wc_edit): ?>
                                                    <button class="act-btn" title="تعديل"
                                                        onclick="openWcModal(<?= (int) $w['id'] ?>)"><i
                                                            class="bi bi-pencil"></i></button>
                                                    <button class="act-btn <?= $active ? 'danger' : 'success-h' ?>"
                                                        title="<?= $active ? 'تعطيل' : 'تفعيل' ?>"
                                                        onclick="wcToggle(<?= (int) $w['id'] ?>)"><i
                                                            class="bi bi-<?= $active ? 'slash-circle' : 'check-circle' ?>"></i></button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$workCenters): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">ما في محطات عمل مسجّلة بعد</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ===================== تبويب: المقاولين ===================== -->
            <div class="subtab-pane" id="pane-contractors">
                <?php if (!$serviceVendorSettingsReady): ?>
                    <div class="alert alert-warning py-2 px-3" style="font-size:.82rem">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        حسابات موردي الخدمة الأب (ذمم + دفعات مقدمة) لسا ما انضبطت بدليل الحسابات — إضافة مورد جديد رح تفشل
                        لحد ما تُضبط. راجع مسؤول النظام.
                    </div>
                <?php endif; ?>
                <div class="tbl-wrap">
                    <div class="tbl-hdr">
                        <span style="font-size:.88rem;font-weight:700;color:#1e293b">
                            <i class="bi bi-list-ul me-1" style="color:var(--section-color)"></i>موردي الخدمات
                        </span>
                        <?php if ($can_contr_create): ?>
                            <button class="btn btn-sm fw-600 ms-auto" onclick="openContractorModal()"
                                style="border-radius:9px;background:var(--section-color);color:#fff;font-size:.82rem">
                                <i class="bi bi-plus-lg me-1"></i>إضافة مقاول
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="table-responsive">
                        <table class="mtbl">
                            <thead>
                                <tr>
                                    <th>الاسم</th>
                                    <th>التواصل</th>
                                    <th>حساب الذمم</th>
                                    <th>حساب الدفعة المقدمة</th>
                                    <th>الحالة</th>
                                    <th style="text-align:center">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($contractors as $c):
                                    $active = (int) $c['is_active'] === 1; ?>
                                    <tr>
                                        <td class="fw-600"><?= htmlspecialchars($c['name']) ?></td>
                                        <td class="text-muted"><?= htmlspecialchars($c['contact_info'] ?? '—') ?></td>
                                        <td class="text-muted" style="font-size:.8rem">
                                            <?= $c['payable_code'] ? htmlspecialchars($c['payable_code'] . ' — ' . $c['payable_name']) : '—' ?>
                                        </td>
                                        <td class="text-muted" style="font-size:.8rem">
                                            <?= $c['advance_code'] ? htmlspecialchars($c['advance_code'] . ' — ' . $c['advance_name']) : '—' ?>
                                        </td>
                                        <td><span
                                                class="badge-status <?= $active ? 'badge-active' : 'badge-inactive' ?>"><?= $active ? 'نشط' : 'معطّل' ?></span>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1 justify-content-center">
                                                <?php if ($can_contr_edit): ?>
                                                    <button class="act-btn" title="تعديل"
                                                        onclick="openContractorModal(<?= (int) $c['id'] ?>)"><i
                                                            class="bi bi-pencil"></i></button>
                                                    <button class="act-btn <?= $active ? 'danger' : 'success-h' ?>"
                                                        title="<?= $active ? 'تعطيل' : 'تفعيل' ?>"
                                                        onclick="contractorToggle(<?= (int) $c['id'] ?>)"><i
                                                            class="bi bi-<?= $active ? 'slash-circle' : 'check-circle' ?>"></i></button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$contractors): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">ما في موردي خدمة مسجّلين بعد
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <!-- مودال مرحلة -->
    <div class="modal fade" id="stageModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="stageModalTitle">إضافة مرحلة</h5><button type="button" class="btn-close"
                        data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="stage_id">
                    <div class="mb-2"><label class="form-label small fw-600">اسم المرحلة *</label><input type="text"
                            id="stage_name" class="form-control"></div>
                    <div class="mb-2"><label class="form-label small fw-600">الرمز (اختياري)</label><input type="text"
                            id="stage_code" class="form-control"></div>
                    <div class="mb-2"><label class="form-label small fw-600">الوصف</label><textarea
                            id="stage_description" class="form-control" rows="2"></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="button" class="btn fw-600" style="background:var(--section-color);color:#fff"
                        onclick="saveStage()">حفظ</button>
                </div>
            </div>
        </div>
    </div>

    <!-- مودال محطة عمل -->
    <div class="modal fade" id="wcModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="wcModalTitle">إضافة محطة عمل</h5><button type="button" class="btn-close"
                        data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="wc_id">
                    <div class="mb-2">
                        <label class="form-label small fw-600">اسم المحطة *</label>
                        <input type="text" id="wc_name" class="form-control">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-600">المرحلة *</label>
                        <select id="wc_stage_id" class="form-select">
                            <option value="">— اختر —</option>
                            <?php foreach ($stages as $s):
                                if ((int) $s['is_active'] !== 1)
                                    continue; ?>
                                <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-600">النوع *</label>
                        <select id="wc_type" class="form-select" onchange="toggleWcContractorField()">
                            <option value="internal">داخلية</option>
                            <option value="external">خارجية (مقاول)</option>
                        </select>
                    </div>
                    <div class="mb-2" id="wc_contractor_wrap" style="display:none">
                        <label class="form-label small fw-600">مورد الخدمة *</label>
                        <select id="wc_contractor_id" class="form-select">
                            <option value="">— اختر —</option>
                            <?php foreach ($contractors as $c):
                                if ((int) $c['is_active'] !== 1)
                                    continue; ?>
                                <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-600">التسعيرة (بعملة الفرع لكل قطعة)</label>
                        <input type="number" step="0.0001" min="0" id="wc_rate" class="form-control" value="0">
                        <div class="form-text" style="font-size:.75rem">داخلية = تسعيرة معيارية للتكلفة (لا دفع فعلي).
                            خارجية = تسعيرة مورد الخدمة الحقيقية.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="button" class="btn fw-600" style="background:var(--section-color);color:#fff"
                        onclick="saveWc()">حفظ</button>
                </div>
            </div>
        </div>
    </div>

    <!-- مودال تخويل مستخدمين لمحطة عمل -->
    <div class="modal fade" id="wcUsersModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">تخويل المستخدمين — <span id="wcUsersName"></span></h5><button type="button"
                        class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="wcu_work_center_id">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>المستخدم</th>
                                <th>قبول/رفض/إنهاء</th>
                                <th>تنفيذ</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="wcUsersBody"></tbody>
                    </table>
                    <hr>
                    <h6 class="small fw-600">إضافة تخويل جديد</h6>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label small">المستخدم</label>
                            <select id="wcu_new_user" class="form-select form-select-sm">
                                <option value="">— جاري التحميل —</option>
                            </select>
                        </div>
                        <div class="col-md-3 form-check d-flex align-items-center gap-1 mb-0" style="margin-top:1.6rem">
                            <input class="form-check-input" type="checkbox" id="wcu_new_approve"
                                style="margin-top:0"><label class="form-check-label small"
                                for="wcu_new_approve">قبول/إنهاء</label>
                        </div>
                        <div class="col-md-2 form-check d-flex align-items-center gap-1 mb-0" style="margin-top:1.6rem">
                            <input class="form-check-input" type="checkbox" id="wcu_new_execute" checked
                                style="margin-top:0"><label class="form-check-label small"
                                for="wcu_new_execute">تنفيذ</label>
                        </div>
                        <div class="col-md-2"><button class="btn btn-sm w-100"
                                style="background:var(--section-color);color:#fff" onclick="addWcUser()">إضافة</button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary"
                        data-bs-dismiss="modal">إغلاق</button></div>
            </div>
        </div>
    </div>

    <!-- مودال مقاول -->
    <div class="modal fade" id="contractorModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="contractorModalTitle">إضافة مقاول</h5><button type="button"
                        class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="contractor_id">
                    <div class="mb-2"><label class="form-label small fw-600">اسم مورد الخدمة *</label><input type="text"
                            id="contractor_name" class="form-control"></div>
                    <div class="mb-2"><label class="form-label small fw-600">معلومات التواصل</label><input type="text"
                            id="contractor_contact" class="form-control"></div>
                    <div class="alert alert-light border py-2 px-3 mb-0" id="contractorAccountNotice"
                        style="font-size:.78rem;color:#64748b">
                        <i class="bi bi-info-circle me-1"></i>
                        <span id="contractorAccountNoticeText">سيتم إنشاء حساب الذمم وحساب الدفعة المقدمة بدليل الحسابات
                            تلقائياً عند الحفظ.</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="button" class="btn fw-600" style="background:var(--section-color);color:#fff"
                        onclick="saveContractor()">حفظ</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function post(action, data = {}) {
            const fd = new FormData();
            fd.append('_action', action);
            for (const k in data) fd.append(k, data[k]);
            return fetch(location.pathname, { method: 'POST', body: fd }).then(r => r.json());
        }

        // ---------- تبويبات ----------
        function showSubTab(tab) {
            document.querySelectorAll('.subtab-pane').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.subtab-nav button').forEach(b => b.classList.remove('active'));
            document.getElementById('pane-' + tab).classList.add('active');
            document.querySelector('.subtab-nav button[data-tab="' + tab + '"]').classList.add('active');
            history.replaceState(null, '', '?tab=' + tab);
        }
        showSubTab(<?= json_encode($activeTab) ?>);

        // ---------- مراحل ----------
        const stageModal = new bootstrap.Modal(document.getElementById('stageModal'));
        function openStageModal(id = null) {
            document.getElementById('stage_id').value = '';
            document.getElementById('stage_name').value = '';
            document.getElementById('stage_code').value = '';
            document.getElementById('stage_description').value = '';
            document.getElementById('stageModalTitle').innerText = 'إضافة مرحلة';
            if (id) {
                post('stage_get', { id }).then(res => {
                    if (!res.ok) { alert(res.msg); return; }
                    const d = res.data;
                    document.getElementById('stage_id').value = d.id;
                    document.getElementById('stage_name').value = d.name;
                    document.getElementById('stage_code').value = d.code || '';
                    document.getElementById('stage_description').value = d.description || '';
                    document.getElementById('stageModalTitle').innerText = 'تعديل مرحلة';
                    stageModal.show();
                });
            } else { stageModal.show(); }
        }
        function saveStage() {
            const name = document.getElementById('stage_name').value.trim();
            if (!name) { alert('اسم المرحلة مطلوب'); return; }
            post('stage_save', {
                id: document.getElementById('stage_id').value,
                name, code: document.getElementById('stage_code').value.trim(),
                description: document.getElementById('stage_description').value.trim()
            }).then(res => { if (!res.ok) { alert(res.msg); return; } location.reload(); });
        }
        function stageToggle(id) {
            if (!confirm('تأكيد تغيير حالة المرحلة؟')) return;
            post('stage_toggle', { id }).then(res => { if (!res.ok) { alert(res.msg); return; } location.reload(); });
        }

        // ---------- محطات عمل ----------
        const wcModal = new bootstrap.Modal(document.getElementById('wcModal'));
        function toggleWcContractorField() {
            document.getElementById('wc_contractor_wrap').style.display =
                document.getElementById('wc_type').value === 'external' ? 'block' : 'none';
        }
        function openWcModal(id = null) {
            document.getElementById('wc_id').value = '';
            document.getElementById('wc_name').value = '';
            document.getElementById('wc_stage_id').value = '';
            document.getElementById('wc_type').value = 'internal';
            document.getElementById('wc_contractor_id').value = '';
            document.getElementById('wc_rate').value = 0;
            toggleWcContractorField();
            document.getElementById('wcModalTitle').innerText = 'إضافة محطة عمل';
            if (id) {
                post('wc_get', { id }).then(res => {
                    if (!res.ok) { alert(res.msg); return; }
                    const d = res.data;
                    document.getElementById('wc_id').value = d.id;
                    document.getElementById('wc_name').value = d.name;
                    document.getElementById('wc_stage_id').value = d.stage_id;
                    document.getElementById('wc_type').value = d.type;
                    document.getElementById('wc_contractor_id').value = d.contractor_id || '';
                    document.getElementById('wc_rate').value = d.rate;
                    toggleWcContractorField();
                    document.getElementById('wcModalTitle').innerText = 'تعديل محطة عمل';
                    wcModal.show();
                });
            } else { wcModal.show(); }
        }
        function saveWc() {
            const stageId = document.getElementById('wc_stage_id').value;
            const name = document.getElementById('wc_name').value.trim();
            if (!stageId) { alert('اختر المرحلة'); return; }
            if (!name) { alert('اسم المحطة مطلوب'); return; }
            post('wc_save', {
                id: document.getElementById('wc_id').value,
                stage_id: stageId, name,
                type: document.getElementById('wc_type').value,
                contractor_id: document.getElementById('wc_contractor_id').value,
                rate: document.getElementById('wc_rate').value || 0
            }).then(res => { if (!res.ok) { alert(res.msg); return; } location.reload(); });
        }
        function wcToggle(id) {
            if (!confirm('تأكيد تغيير حالة محطة العمل؟')) return;
            post('wc_toggle', { id }).then(res => { if (!res.ok) { alert(res.msg); return; } location.reload(); });
        }

        // ---------- تخويل مستخدمين لمحطة عمل ----------
        const wcUsersModal = new bootstrap.Modal(document.getElementById('wcUsersModal'));
        function openWcUsersModal(wcId, wcName) {
            document.getElementById('wcu_work_center_id').value = wcId;
            document.getElementById('wcUsersName').innerText = wcName;
            loadWcUsers(wcId);
            loadUserOptions();
            wcUsersModal.show();
        }
        function loadWcUsers(wcId) {
            post('wc_list_users', { work_center_id: wcId }).then(res => {
                const body = document.getElementById('wcUsersBody');
                body.innerHTML = '';
                if (!res.ok) { body.innerHTML = `<tr><td colspan="4">${res.msg}</td></tr>`; return; }
                if (!res.data.length) { body.innerHTML = `<tr><td colspan="4" class="text-muted text-center">ما في تخويلات بعد</td></tr>`; return; }
                res.data.forEach(u => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                    <td>${u.user_label}</td>
                    <td>${u.can_approve == 1 ? '<i class="bi bi-check-circle text-success"></i>' : '—'}</td>
                    <td>${u.can_execute == 1 ? '<i class="bi bi-check-circle text-success"></i>' : '—'}</td>
                    <td><button class="btn btn-sm btn-outline-danger" onclick="removeWcUser(${u.id}, ${document.getElementById('wcu_work_center_id').value}, '${document.getElementById('wcUsersName').innerText}')"><i class="bi bi-trash"></i></button></td>
                `;
                    body.appendChild(tr);
                });
            });
        }
        function loadUserOptions(q = '') {
            post('list_users_for_auth', { q }).then(res => {
                const sel = document.getElementById('wcu_new_user');
                sel.innerHTML = '';
                if (!res.ok || !res.data.length) { sel.innerHTML = '<option value="">ما في نتائج</option>'; return; }
                res.data.forEach(u => sel.add(new Option(u.label, u.id)));
            });
        }
        function addWcUser() {
            const wcId = document.getElementById('wcu_work_center_id').value;
            const userId = document.getElementById('wcu_new_user').value;
            if (!userId) { alert('اختر مستخدم'); return; }
            post('wc_user_add', {
                work_center_id: wcId, user_id: userId,
                can_approve: document.getElementById('wcu_new_approve').checked ? 1 : 0,
                can_execute: document.getElementById('wcu_new_execute').checked ? 1 : 0
            }).then(res => {
                if (!res.ok) { alert(res.msg); return; }
                loadWcUsers(wcId);
            });
        }
        function removeWcUser(id, wcId, wcName) {
            if (!confirm('تأكيد إلغاء تخويل هالمستخدم؟')) return;
            post('wc_user_remove', { id }).then(res => {
                if (!res.ok) { alert(res.msg); return; }
                loadWcUsers(wcId);
            });
        }

        // ---------- مقاولين ----------
        const contractorModal = new bootstrap.Modal(document.getElementById('contractorModal'));
        function openContractorModal(id = null) {
            document.getElementById('contractor_id').value = '';
            document.getElementById('contractor_name').value = '';
            document.getElementById('contractor_contact').value = '';
            document.getElementById('contractorModalTitle').innerText = 'إضافة مقاول';
            document.getElementById('contractorAccountNoticeText').innerText = 'سيتم إنشاء حساب الذمم وحساب الدفعة المقدمة بدليل الحسابات تلقائياً عند الحفظ.';
            if (id) {
                post('contractor_get', { id }).then(res => {
                    if (!res.ok) { alert(res.msg); return; }
                    const d = res.data;
                    document.getElementById('contractor_id').value = d.id;
                    document.getElementById('contractor_name').value = d.name;
                    document.getElementById('contractor_contact').value = d.contact_info || '';
                    document.getElementById('contractorModalTitle').innerText = 'تعديل مقاول';
                    document.getElementById('contractorAccountNoticeText').innerText = 'حسابه المحاسبي (ذمم + دفعة مقدمة) موجود مسبقاً — بيتحدّث اسمه تلقائياً لو غيّرت اسم المورد.';
                    contractorModal.show();
                });
            } else { contractorModal.show(); }
        }
        function saveContractor() {
            const name = document.getElementById('contractor_name').value.trim();
            if (!name) { alert('اسم مورد الخدمة مطلوب'); return; }
            post('contractor_save', {
                id: document.getElementById('contractor_id').value,
                name, contact_info: document.getElementById('contractor_contact').value.trim()
            }).then(res => { if (!res.ok) { alert(res.msg); return; } location.reload(); });
        }
        function contractorToggle(id) {
            if (!confirm('تأكيد تغيير حالة المقاول؟')) return;
            post('contractor_toggle', { id }).then(res => { if (!res.ok) { alert(res.msg); return; } location.reload(); });
        }
    </script>
    <script src="<?= BASE_PATH ?>/assets/js/sidebar.js"></script>
</body>

</html>