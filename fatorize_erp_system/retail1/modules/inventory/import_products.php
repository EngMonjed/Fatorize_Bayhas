<?php
/**
 * inventory/import_products.php — استيراد دفعي للمنتجات (+ كميات اختيارية)
 * المسار: retail1/modules/inventory/import_products.php
 *
 * ملف CSV: سطر واحد لكل متغيّر (مقاس×لون). نفس model_number بعدة أسطر
 * = نفس المنتج (يُجمَّع تلقائياً). عمودا warehouse_code/quantity
 * اختياريان — لو معبّيين، الكمية بتترحّل بنفس منطق الأرصدة الافتتاحية
 * بالضبط (قيد محاسبي حقيقي مقابل حساب "رصيد افتتاحي"، مو رقم خام).
 *
 * تدفّق العمل: رفع الملف → معاينة/تحقق (بدون أي كتابة لقاعدة البيانات)
 * → تأكيد الاستيراد الفعلي.
 */

session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth.php';

$pdo = getConnection();
checkLogin($pdo);
requirePermission('inventory.import_products', 'create');
$currentModule = 'inventory.import_products';
$branchName = $_SESSION['branch_name'] ?? 'الفرع';

$TS = $_SESSION['table_suffix'];
$branchId = (int) $_SESSION['branch_id'];

$TP    = "products_{$TS}";
$TCAT  = "product_categories_{$TS}";
$TSUP  = "product_suppliers_{$TS}";
$TPS   = "product_sizes_{$TS}";
$TCOL  = "product_colors_{$TS}";
$TPV   = "product_variants_{$TS}";
$TW    = "warehouses_{$TS}";
$TWI   = "warehouse_items_{$TS}";
$TIM   = "inventory_movements_{$TS}";
$TIMD  = "inventory_movement_details_{$TS}";
$TAC   = "account_charts_{$TS}";
$TJE   = "journal_entries_{$TS}";
$TJI   = "journal_entry_items_{$TS}";
$TIAS  = "invoice_account_settings_{$TS}";

$branchBaseCurrency = $pdo->prepare("SELECT base_currency, base_currency_id, opening_balance_locked_at FROM branches WHERE id = ?");
$branchBaseCurrency->execute([$branchId]);
$branchRow = $branchBaseCurrency->fetch(PDO::FETCH_ASSOC);
$branchCurrency = $branchRow['base_currency'] ?? 'USD';
$branchCurrencyId = (int) ($branchRow['base_currency_id'] ?? 1);
$openingLocked = !empty($branchRow['opening_balance_locked_at']);

// ── دوال مساعدة (نفس منطق admin/opening_balances.php بالضبط) ──────
function getSettingAccount(PDO $pdo, string $tias, string $key, string $tac): ?array
{
    $st = $pdo->prepare("SELECT ac.* FROM `{$tias}` ias JOIN `{$tac}` ac ON ac.id=ias.account_id WHERE ias.setting_key=? LIMIT 1");
    $st->execute([$key]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function getOpeningEquityAccount(PDO $pdo, string $tac): ?array
{
    $st = $pdo->prepare("SELECT * FROM `{$tac}` WHERE code='3900' LIMIT 1");
    $st->execute();
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function nextEntryNo(PDO $pdo, string $tje): string
{
    $n = (int) $pdo->query("SELECT COUNT(*) FROM `{$tje}`")->fetchColumn() + 1;
    return 'JE-IMP-' . date('Y') . '-' . str_pad($n, 4, '0', STR_PAD_LEFT);
}
function bumpAccountBalance(PDO $pdo, string $tac, int $accId, float $delta): void
{
    $pdo->prepare("UPDATE `{$tac}` SET base_balance=base_balance+?, balance=balance+? WHERE id=?")->execute([$delta, $delta, $accId]);
}

/**
 * ✅ مطابقة تماماً لـ includes/product_save_helper.php الحقيقي —
 * باركود رقمي عشوائي ٩ خانات، متحقَّق من تفرّده فعلياً بقاعدة البيانات
 * (بدل صيغة {MODEL}-V{ID} القديمة المستخدمة سابقاً هون بالغلط).
 */
function generateBarcode9Digit(PDO $pdo, string $table): string
{
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $code = (string) random_int(1, 9);
        for ($i = 0; $i < 8; $i++) $code .= (string) random_int(0, 9);
        $chk = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE barcode = ?");
        $chk->execute([$code]);
        if ((int) $chk->fetchColumn() === 0) return $code;
    }
    throw new RuntimeException('تعذّر توليد باركود فريد من 9 خانات');
}

$REQUIRED_COLS = ['model_number', 'product_name', 'size', 'selling_price'];
$ALL_COLS = ['model_number', 'product_name', 'category', 'size', 'age_type', 'selling_price', 'cost_price', 'packet_qty', 'group_no', 'color', 'barcode', 'warehouse_code', 'quantity'];

/**
 * ✅ تطبيع الأحرف العربية المتقاربة إملائياً (الهمزة على الألف، الياء
 * المقصورة، التاء المربوطة) — تفادياً لتقسيم نفس اللون/الفئة/المورد
 * لسجلّين منفصلين بالغلط بسبب اختلاف تهجئة بسيط (مثال حقيقي مكتشَف:
 * "أزرق داكن" و"ازرق داكن" كانا بيتسجلوا كلونين مختلفين تماماً).
 * يُستخدم للمقارنة/البحث بس — الاسم المخزَّن فعلياً يضل بنفس تهجئته
 * الأصلية بالملف (أول تهجئة تنلاقى).
 */
function normalizeArabic(string $s): string
{
    $s = str_replace(['أ', 'إ', 'آ'], 'ا', $s);
    $s = str_replace('ى', 'ي', $s);
    $s = str_replace('ة', 'ه', $s);
    return mb_strtolower(trim($s));
}

function parseCsvRows(string $tmpPath): array
{
    $rows = [];
    // ✅ إزالة BOM تلقائياً — Excel بيضيفه أحياناً عند الحفظ/إعادة الحفظ،
    // وكان يخلي أول عمود بالهيدر (model_number) ينقرأ غلط بصمت.
    $raw = file_get_contents($tmpPath);
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
        $raw = substr($raw, 3);
    }
    $cleanPath = $tmpPath . '_clean.csv';
    file_put_contents($cleanPath, $raw);

    if (($h = fopen($cleanPath, 'r')) !== false) {
        $header = fgetcsv($h);
        if ($header === false) { fclose($h); return []; }
        $header = array_map(fn($c) => trim(strtolower($c)), $header);
        while (($line = fgetcsv($h)) !== false) {
            if (count($line) === 1 && trim($line[0]) === '') continue; // سطر فاضي
            $row = [];
            foreach ($header as $i => $col) {
                if ($col === '') continue; // ✅ تجاهل أعمدة فاضية الاسم (فاصلة زايدة بآخر السطر من Excel)
                $row[$col] = trim($line[$i] ?? '');
            }
            $rows[] = $row;
        }
        fclose($h);
    }
    unlink($cleanPath);
    return $rows;
}

/**
 * ✅ يدعم القيمة كـID رقمي مباشر أو كاسم نصي — لو القيمة أرقام صرفة،
 * تُعامَل كـID ويُبحث عنها بالمفتاح الأساسي مباشرة. غير هيك، بحث بالاسم
 * (السلوك الأصلي).
 */
function resolveByIdOrName(PDO $pdo, string $table, string $value, string $nameCol = 'name'): ?array
{
    if ($value === '') return null;
    if (ctype_digit($value)) {
        $st = $pdo->prepare("SELECT * FROM `{$table}` WHERE id = ?");
        $st->execute([(int) $value]);
    } else {
        $st = $pdo->prepare("SELECT * FROM `{$table}` WHERE `{$nameCol}` = ?");
        $st->execute([$value]);
    }
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── AJAX/POST ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($_POST['_action'] === 'preview') {
            if (empty($_FILES['csv_file']['tmp_name'])) throw new Exception('لم يتم رفع أي ملف');
            $rows = parseCsvRows($_FILES['csv_file']['tmp_name']);
            if (empty($rows)) throw new Exception('الملف فاضي أو صيغته غلط');

            // بيانات مرجعية للتحقق
            $existingModels = $pdo->query("SELECT model_number FROM `{$TP}`")->fetchAll(PDO::FETCH_COLUMN);
            $existingWarehouses = $pdo->query("SELECT code FROM `{$TW}` WHERE is_active=1")->fetchAll(PDO::FETCH_COLUMN);
            $seenBarcodes = [];

            // ✅ كشف مسبق: نفس (موديل, مقاس, نوع القياس) بسعر بيع مختلف
            // بأسطر مختلفة — بما إنه product_sizes عندها قيد فريد على
            // هالثلاثة مع بعض، أول سعر بيتسجل والباقي بيتجاهل بصمت لو ما
            // كشفناها هون صراحة. لازم المستخدم يصحح الملف المصدر يدوياً
            // (أي سعر هو الصحيح؟) قبل أي استيراد.
            $priceByKey = [];
            foreach ($rows as $row) {
                if (empty($row['model_number']) || empty($row['size']) || empty($row['selling_price'])) continue;
                $ageType = $row['age_type'] ?: 'سنة';
                $key = $row['model_number'] . '|' . $row['size'] . '|' . $ageType;
                $priceByKey[$key][$row['selling_price']] = true;
            }
            $conflictKeys = [];
            foreach ($priceByKey as $key => $prices) {
                if (count($prices) > 1) $conflictKeys[$key] = array_keys($prices);
            }

            $preview = [];
            $rowNum = 1;
            foreach ($rows as $row) {
                $rowNum++;
                $errors = [];
                $warnings = [];
                foreach ($REQUIRED_COLS as $col) {
                    if (empty($row[$col])) $errors[] = "عمود '{$col}' مطلوب وفاضي";
                }
                if (!empty($row['selling_price']) && !is_numeric($row['selling_price'])) $errors[] = 'سعر البيع لازم يكون رقم';
                if (!empty($row['cost_price']) && !is_numeric($row['cost_price'])) $errors[] = 'سعر التكلفة لازم يكون رقم';
                if (!empty($row['quantity']) && !is_numeric($row['quantity'])) $errors[] = 'الكمية لازم تكون رقم';
                if (!empty($row['quantity']) && (float)$row['quantity'] > 0 && empty($row['warehouse_code'])) {
                    $errors[] = 'محدَّدة كمية بدون مستودع';
                }
                if (!empty($row['warehouse_code']) && !in_array($row['warehouse_code'], $existingWarehouses, true)) {
                    $errors[] = "مستودع '{$row['warehouse_code']}' غير موجود";
                }
                // ✅ تعارض سعر — خطأ يمنع الاستيراد لهالسطر تحديداً
                if (!empty($row['model_number']) && !empty($row['size'])) {
                    $ageType = $row['age_type'] ?: 'سنة';
                    $key = $row['model_number'] . '|' . $row['size'] . '|' . $ageType;
                    if (isset($conflictKeys[$key])) {
                        $errors[] = 'تعارض سعر: نفس الموديل/المقاس بأسعار مختلفة بالملف (' . implode(' مقابل ', $conflictKeys[$key]) . ') — صحّح الملف المصدر أول';
                    }
                }
                // ⚠ supplier مو مستخدم — منتج واحد ما بينحصر بمورد واحد
                // (نفس القرار المعماري المطبَّق فعلياً بـproduct_add.php،
                // العمود products.supplier_id محجوز لميزة "قائمة أسعار
                // لكل مورد" المؤجّلة، مش مستخدم حالياً)
                // ✅ الفئة: يدعم ID رقمي أو اسم نصي — ID غير موجود = خطأ (ما
                // في معنى "ننشئ فئة جديدة برقم معيّن")، اسم جديد = تحذير بس
                if (!empty($row['category'])) {
                    if (ctype_digit($row['category'])) {
                        $catRow = resolveByIdOrName($pdo, $TCAT, $row['category'], 'name');
                        if (!$catRow) $errors[] = "فئة رقم '{$row['category']}' غير موجودة";
                    } else {
                        $catRow = resolveByIdOrName($pdo, $TCAT, $row['category'], 'name');
                        if (!$catRow) $warnings[] = "فئة جديدة ستُنشأ: '{$row['category']}'";
                    }
                }
                if (!empty($row['barcode'])) {
                    if (isset($seenBarcodes[$row['barcode']])) $errors[] = 'باركود مكرر بنفس الملف';
                    $seenBarcodes[$row['barcode']] = true;
                }
                if (!empty($row['quantity']) && (float)$row['quantity'] > 0 && $openingLocked) {
                    $errors[] = 'الأرصدة الافتتاحية مقفولة لهذا الفرع — الكمية لن تُستورد';
                }

                $preview[] = [
                    'row' => $rowNum,
                    'data' => $row,
                    'is_new_product' => !in_array($row['model_number'] ?? '', $existingModels, true),
                    'errors' => $errors,
                    'warnings' => $warnings,
                ];
            }

            $_SESSION['import_products_preview'] = $preview;
            $validCount = count(array_filter($preview, fn($p) => empty($p['errors'])));

            // ✅ شفافية: أي ألوان بالملف رح تتوحّد تلقائياً بسبب تفاوت
            // إملائي (همزة على الألف...)، عشان المستخدم يشوفها بوضوح
            // بدل ما تصير بصمت
            $colorGroups = [];
            foreach ($rows as $row) {
                if (empty($row['color'])) continue;
                $colorGroups[normalizeArabic($row['color'])][$row['color']] = true;
            }
            $colorMerges = [];
            foreach ($colorGroups as $variants) {
                if (count($variants) > 1) $colorMerges[] = array_keys($variants);
            }

            echo json_encode(['ok' => true, 'preview' => $preview, 'total' => count($preview), 'valid' => $validCount, 'color_merges' => $colorMerges]);
        } elseif ($_POST['_action'] === 'commit') {
            $preview = $_SESSION['import_products_preview'] ?? [];
            if (empty($preview)) throw new Exception('لا يوجد بيانات معاينة — ارفع الملف من جديد');

            $created = ['products' => 0, 'sizes' => 0, 'variants' => 0, 'stock_lines' => 0];
            $skipped = 0;
            $productCache = []; // model_number => product_id
            $sizeCache = [];    // model_number|size|age_type => size_id
            $sortOrderCounters = []; // product_id => آخر sort_order مستخدم
            $colorCache = [];   // normalizeArabic(name) => color_id
            // ✅ الكروب الحقيقي = نفس المنتج + نفس سعر البيع + نفس عدد
            // قطع الباكيت (مطابق لتعريف syncProductVariants() الفعلي) —
            // باركود واحد مشترك لكل (كروب × لون)
            $groupColorBarcode = []; // "{product_id}|{selling_price}|{packet_qty}|{color_id}" => الباركود

            // ✅ تحميل كل الألوان الموجودة أصلاً بقاعدة البيانات، مفهرسة
            // بالاسم المُطبَّع — عشان "أزرق داكن" الموجودة أصلاً تتلاقى
            // حتى لو الملف كاتبها "ازرق داكن" (بدون همزة)
            $existingColorsRaw = $pdo->query("SELECT id, name FROM `{$TCOL}`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($existingColorsRaw as $c) {
                $colorCache[normalizeArabic($c['name'])] = (int) $c['id'];
            }

            $pdo->beginTransaction();
            foreach ($preview as $item) {
                if (!empty($item['errors'])) { $skipped++; continue; }
                $row = $item['data'];

                // ١) المنتج (find-or-create)
                $modelNo = $row['model_number'];
                if (!isset($productCache[$modelNo])) {
                    $existing = $pdo->prepare("SELECT id FROM `{$TP}` WHERE model_number=?");
                    $existing->execute([$modelNo]);
                    $pid = $existing->fetchColumn();

                    // ✅ حل الفئة **خارج** شرط "منتج جديد" — لازم تتطبّق
                    // حتى على منتج موجود أصلاً (مثال حقيقي: منتج اتسجل
                    // بنسخة قديمة من الأداة قبل دعم الفئة، وbcategory_id
                    // ضل NULL — إعادة رفع نفس الملف لازم تصلحه رجعياً،
                    // مو تتخطاه بصمت لأنه المنتج "موجود أصلاً")
                    $categoryId = null;
                    if (!empty($row['category'])) {
                        $catRow = resolveByIdOrName($pdo, $TCAT, $row['category'], 'name');
                        if ($catRow) {
                            $categoryId = $catRow['id'];
                        } elseif (!ctype_digit($row['category'])) {
                            $pdo->prepare("INSERT INTO `{$TCAT}` (name, is_active, created_at) VALUES (?,1,NOW())")->execute([$row['category']]);
                            $categoryId = (int) $pdo->lastInsertId();
                        }
                        // لو رقم ID ومالقيناه: بيضل NULL — الصف أصلاً كان محظور بالمعاينة
                    }

                    if (!$pid) {
                        // ⚠ supplier_id عمداً غير مُدرَج — راجع القرار
                        // المعماري بـproduct_add.php (منتج واحد ما بينحصر
                        // بمورد واحد)
                        $pdo->prepare("INSERT INTO `{$TP}` (model_number, name, category_id, is_active, created_by, created_at)
                            VALUES (?,?,?,1,?,NOW())")
                            ->execute([$modelNo, $row['product_name'], $categoryId, $_SESSION['user_id']]);
                        $pid = (int) $pdo->lastInsertId();
                        $created['products']++;
                    } elseif ($categoryId !== null) {
                        // ✅ منتج موجود أصلاً — حدّث category_id بس لو
                        // الملف بيوفّر قيمة فعلية (ما نمسح فئة موجودة صح
                        // بقيمة فاضية بالغلط)
                        $pdo->prepare("UPDATE `{$TP}` SET category_id = ? WHERE id = ? AND category_id IS NULL")
                            ->execute([$categoryId, $pid]);
                    }
                    $productCache[$modelNo] = $pid;
                }
                $productId = $productCache[$modelNo];

                // ٢) المقاس (find-or-create)
                $ageType = $row['age_type'] ?: 'سنة';
                $sizeKey = $modelNo . '|' . $row['size'] . '|' . $ageType;
                if (!isset($sizeCache[$sizeKey])) {
                    $existing = $pdo->prepare("SELECT id FROM `{$TPS}` WHERE product_id=? AND size=? AND age_type=?");
                    $existing->execute([$productId, $row['size'], $ageType]);
                    $sid = $existing->fetchColumn();
                    if (!$sid) {
                        // ✅ الكروب الحقيقي — لو الملف فيه عمود group_no
                        // صريح (بدك تحدده يدوياً)، يُستخدم كمفتاح فريد
                        // لكل كروب لنفس المنتج. وإلا، يُشتق تلقائياً من
                        // (سعر البيع + عدد قطع الباكيت) — نفس الاحتياط
                        // القديم، بس هلق **يُخزَّن** بدل ما يُعاد استنتاجه
                        // كل مرة بملف تاني.
                        $groupKeyVal = !empty($row['group_no'])
                            ? "g_{$modelNo}_{$row['group_no']}"
                            : "g_{$row['selling_price']}_{$row['packet_qty']}";

                        // ✅ sort_order تسلسلي حسب ترتيب الظهور بالملف لكل
                        // منتج — كان دايماً 0 قبل (القيمة الافتراضية بس)
                        $sortOrderCounters[$productId] = ($sortOrderCounters[$productId] ?? 0) + 1;
                        $sortOrder = $sortOrderCounters[$productId];

                        $pdo->prepare("INSERT INTO `{$TPS}` (product_id, size, age_type, sort_order, selling_price, cost_price, base_currency_id, currency_id, packet_qty, group_key, is_active, created_at)
                            VALUES (?,?,?,?,?,?,?,?,?,?,1,NOW())")
                            ->execute([
                                $productId, $row['size'], $ageType, $sortOrder,
                                (float) $row['selling_price'],
                                $row['cost_price'] !== '' ? (float) $row['cost_price'] : null,
                                $branchCurrencyId, $branchCurrencyId,
                                $row['packet_qty'] !== '' ? (int) $row['packet_qty'] : null,
                                $groupKeyVal,
                            ]);
                        $sid = (int) $pdo->lastInsertId();
                        $created['sizes']++;
                    }
                    $sizeCache[$sizeKey] = $sid;
                }
                $sizeId = $sizeCache[$sizeKey];

                // ٣) اللون (find-or-create، اختياري) — مطابقة مُطبَّعة
                // (بدون همزات/تفريق ة-ه) تمنع تكرار نفس اللون بتهجئتين
                $colorId = null;
                if (!empty($row['color'])) {
                    $ck = normalizeArabic($row['color']);
                    if (!isset($colorCache[$ck])) {
                        $pdo->prepare("INSERT INTO `{$TCOL}` (name, is_active, created_at) VALUES (?,1,NOW())")->execute([$row['color']]);
                        $colorCache[$ck] = (int) $pdo->lastInsertId();
                    }
                    $colorId = $colorCache[$ck];
                }

                // ٤) المتغيّر (find-or-create حسب size_id+color_id)
                $existing = $pdo->prepare("SELECT id FROM `{$TPV}` WHERE size_id=? AND " . ($colorId ? "color_id=?" : "color_id IS NULL"));
                $params = $colorId ? [$sizeId, $colorId] : [$sizeId];
                $existing->execute($params);
                $variantId = $existing->fetchColumn();
                if (!$variantId) {
                    // ✅ نفس منطق group_key المخزَّن أعلاه بالضبط — باركود
                    // مشترك لكل (كروب × لون)
                    $groupKeyVal = !empty($row['group_no'])
                        ? "g_{$modelNo}_{$row['group_no']}"
                        : "g_{$row['selling_price']}_{$row['packet_qty']}";
                    $groupKey = $productId . '|' . $groupKeyVal . '|' . ($colorId ?: '0');
                    if (!isset($groupColorBarcode[$groupKey])) {
                        $groupColorBarcode[$groupKey] = $row['barcode'] ?: generateBarcode9Digit($pdo, $TPV);
                    }
                    $barcode = $groupColorBarcode[$groupKey];

                    $pdo->prepare("INSERT INTO `{$TPV}` (product_id, size_id, color_id, barcode, is_active, created_by, created_at)
                        VALUES (?,?,?,?,1,?,NOW())")
                        ->execute([$productId, $sizeId, $colorId, $barcode, $_SESSION['user_id']]);
                    $variantId = (int) $pdo->lastInsertId();
                    $created['variants']++;
                }

                // ٥) الكمية الافتتاحية (اختيارية) — نفس منطق opening_balances.php بالضبط
                $qty = $row['quantity'] !== '' ? (float) $row['quantity'] : 0;
                if ($qty > 0 && !empty($row['warehouse_code']) && !$openingLocked) {
                    $wh = $pdo->prepare("SELECT id FROM `{$TW}` WHERE code=?");
                    $wh->execute([$row['warehouse_code']]);
                    $warehouseId = (int) $wh->fetchColumn();
                    $cost = $row['cost_price'] !== '' ? (float) $row['cost_price'] : 0;
                    $totalValue = $qty * $cost;

                    $exists = $pdo->prepare("SELECT id, quantity FROM `{$TWI}` WHERE variant_id=? AND warehouse_id=?");
                    $exists->execute([$variantId, $warehouseId]);
                    $wiRow = $exists->fetch(PDO::FETCH_ASSOC);
                    $balanceBefore = $wiRow ? (float) $wiRow['quantity'] : 0;

                    if ($wiRow) {
                        $pdo->prepare("UPDATE `{$TWI}` SET quantity=quantity+?, current_cost=? WHERE id=?")->execute([$qty, $cost, $wiRow['id']]);
                    } else {
                        $pdo->prepare("INSERT INTO `{$TWI}` (warehouse_id,variant_id,product_id,quantity,current_cost,status,created_at) VALUES (?,?,?,?,?,'active',NOW())")
                            ->execute([$warehouseId, $variantId, $productId, $qty, $cost]);
                    }

                    $movNo = 'IMP-' . date('Y') . '-' . str_pad((int) $pdo->query("SELECT COUNT(*) FROM `{$TIM}`")->fetchColumn() + 1, 4, '0', STR_PAD_LEFT);
                    $pdo->prepare("INSERT INTO `{$TIM}` (movement_number,movement_type,warehouse_id,items_count,total_quantity,total_value_base,reference_type,reference_number,created_by)
                        VALUES (?,'opening',?,1,?,?,?,?,?)")
                        ->execute([$movNo, $warehouseId, $qty, $totalValue, 'opening_balance', $movNo, $_SESSION['user_id']]);
                    $movId = (int) $pdo->lastInsertId();

                    $pdo->prepare("INSERT INTO `{$TIMD}` (movement_id,variant_id,product_id,quantity,unit_price,cost_price,total_value,balance_before,balance_after,notes)
                        VALUES (?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$movId, $variantId, $productId, $qty, $cost, $cost, $totalValue, $balanceBefore, $balanceBefore + $qty, 'استيراد دفعي — رصيد افتتاحي']);

                    $accInventory = getSettingAccount($pdo, $TIAS, 'inventory', $TAC);
                    $accEquity = getOpeningEquityAccount($pdo, $TAC);
                    if ($accInventory && $accEquity && $totalValue > 0) {
                        $entryNo = nextEntryNo($pdo, $TJE);
                        $pdo->prepare("INSERT INTO `{$TJE}` (entry_number,entry_date,description,currency_id,exchange_rate,total_debit,total_credit,status,reference_type,reference_id,created_by,posted_at,posted_by)
                            VALUES (?,CURDATE(),?,1,1,?,?,'posted',?,?,?,NOW(),?)")
                            ->execute([$entryNo, "استيراد دفعي — رصيد افتتاحي {$row['product_name']}", $totalValue, $totalValue, 'opening_balance', $movId, $_SESSION['user_id'], $_SESSION['user_id']]);
                        $jeId = (int) $pdo->lastInsertId();

                        $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency,exchange_rate) VALUES (?,?,?,0,?,?,?,?,1)")
                            ->execute([$jeId, $accInventory['id'], $totalValue, $totalValue, $totalValue, 'استيراد رصيد افتتاحي', $branchCurrency]);
                        bumpAccountBalance($pdo, $TAC, $accInventory['id'], $totalValue);

                        $pdo->prepare("INSERT INTO `{$TJI}` (journal_entry_id,account_id,debit,credit,original_amount,base_amount,description,currency,exchange_rate) VALUES (?,?,0,?,?,?,?,?,1)")
                            ->execute([$jeId, $accEquity['id'], $totalValue, $totalValue, $totalValue, 'استيراد رصيد افتتاحي', $branchCurrency]);
                        bumpAccountBalance($pdo, $TAC, $accEquity['id'], -$totalValue);
                    }
                    $created['stock_lines']++;
                }
            }
            $pdo->commit();
            unset($_SESSION['import_products_preview']);

            echo json_encode(['ok' => true, 'msg' => 'تم الاستيراد بنجاح', 'created' => $created, 'skipped' => $skipped]);
        } else {
            echo json_encode(['ok' => false, 'msg' => 'إجراء غير معروف']);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

$warehouses = $pdo->query("SELECT code, name FROM `{$TW}` WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>استيراد المنتجات وبضاعة أول المدة — <?= htmlspecialchars($branchName) ?></title>
<link rel="icon" href="<?= BASE_PATH ?>/assets/images/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="<?= BASE_PATH ?>/assets/css/layout.css" rel="stylesheet">
<style>
.drop-zone{border:2px dashed #cbd5e1;border-radius:14px;padding:2.5rem;text-align:center;cursor:pointer;transition:.2s}
.drop-zone:hover{border-color:var(--section-color,#3b82f6)}
table.preview-tbl{font-size:.75rem}
table.preview-tbl td,table.preview-tbl th{padding:.4rem .5rem;white-space:nowrap}
.row-error{background:#fef2f2}
.row-ok{background:#f0fdf4}
.row-warning{background:#fffbeb}
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
    <span class="tb-title"><i class="bi bi-upload me-1 text-primary"></i>استيراد المنتجات وبضاعة أول المدة</span>
    <span class="tb-branch"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($branchName) ?></span>
    <?= renderBreadcrumb() ?>
</header>

<main class="main-content">
<div class="content-body">

    <?php if ($openingLocked): ?>
    <div class="alert alert-warning" style="border-radius:10px">
        <i class="bi bi-lock-fill me-1"></i> الأرصدة الافتتاحية مقفولة لهذا الفرع — أي كمية بالملف <strong>لن تُستورد</strong>، بس المنتجات نفسها هتتنشئ عادي.
    </div>
    <?php else: ?>
    <div class="alert alert-info" style="border-radius:10px">
        <i class="bi bi-info-circle me-1"></i> هالصفحة بتخدم غرضين مع بعض: <strong>تعريف المنتجات</strong> (موديل/مقاسات/ألوان/باركود)، **و**<strong>تسجيل بضاعة أول المدة</strong> (كمية أول رصيد) لو عبّيت عمودي <code>warehouse_code</code>/<code>quantity</code>. الكمية بتترحّل بنفس القيد المحاسبي لصفحة "الأرصدة الافتتاحية" بالضبط.
    </div>
    <?php endif; ?>

    <div class="table-card p-3 mb-3">
        <h6 class="fw-bold mb-2"><i class="bi bi-info-circle me-1"></i>صيغة الملف المطلوبة (CSV)</h6>
        <p class="small text-muted mb-2">سطر واحد لكل متغيّر (مقاس×لون). نفس <code>model_number</code> بعدة أسطر = نفس المنتج.</p>
        <code style="font-size:.72rem;word-break:break-all">model_number,product_name,category,size,age_type,selling_price,cost_price,packet_qty,color,barcode,warehouse_code,quantity</code>
        <p class="small text-muted mt-2 mb-0">⚠ ما في عمود مورد — منتج واحد ما بينحصر بمورد واحد (نفس قرار صفحة "إضافة منتج" الحقيقية بالنظام).</p>
        <div class="mt-2 small">
            <strong>المستودعات المتاحة:</strong>
            <?php foreach ($warehouses as $w): ?>
                <span class="badge bg-light text-dark border me-1"><?= htmlspecialchars($w['code']) ?> (<?= htmlspecialchars($w['name']) ?>)</span>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="table-card p-4 mb-3" id="uploadSection">
        <div class="drop-zone" onclick="document.getElementById('csvFile').click()">
            <i class="bi bi-file-earmark-spreadsheet" style="font-size:2.5rem;color:#94a3b8"></i>
            <div class="mt-2 fw-600">اضغط لاختيار ملف CSV</div>
            <input type="file" id="csvFile" accept=".csv" style="display:none" onchange="uploadFile()">
        </div>
        <div id="uploadStatus" class="mt-2"></div>
    </div>

    <div id="previewSection" style="display:none">
        <div class="table-card p-3 mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold mb-0">معاينة قبل الاستيراد</h6>
                <div id="previewSummary" class="small"></div>
            </div>
            <div style="overflow-x:auto;max-height:500px">
                <table class="table table-sm preview-tbl mb-0">
                    <thead><tr>
                        <th>#</th><th>الموديل</th><th>الاسم</th><th>مقاس</th><th>لون</th>
                        <th>سعر بيع</th><th>تكلفة</th><th>مستودع</th><th>كمية</th><th>ملاحظات</th>
                    </tr></thead>
                    <tbody id="previewBody"></tbody>
                </table>
            </div>
            <button class="btn btn-primary mt-3" id="confirmBtn" onclick="confirmImport()">
                <span class="spinner-border spinner-border-sm me-1" id="commitSpinner" style="display:none"></span>
                <i class="bi bi-check-lg me-1"></i>تأكيد الاستيراد
            </button>
            <button class="btn btn-outline-secondary mt-3" onclick="location.reload()">إلغاء</button>
        </div>
    </div>

</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_PATH ?>/assets/js/sidebar.js"></script>
<script>
function toast(msg, type = 'success') {
    const el = document.createElement('div');
    el.className = `alert alert-${type} position-fixed`;
    el.style.cssText = 'bottom:20px;left:20px;z-index:9999;border-radius:10px;font-size:.85rem;min-width:280px';
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 4000);
}

function uploadFile() {
    const file = document.getElementById('csvFile').files[0];
    if (!file) return;
    document.getElementById('uploadStatus').innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm"></span> جارِ التحليل...</span>';

    const fd = new FormData();
    fd.append('_action', 'preview');
    fd.append('csv_file', file);

    fetch(location.href, { method: 'POST', body: fd }).then(r => r.json()).then(d => {
        if (!d.ok) { toast(d.msg, 'danger'); document.getElementById('uploadStatus').innerHTML = ''; return; }
        document.getElementById('uploadStatus').innerHTML = '';
        renderPreview(d.preview, d.total, d.valid, d.color_merges || []);
    });
}

function renderPreview(preview, total, valid, colorMerges) {
    document.getElementById('previewSection').style.display = 'block';
    const warnCount = preview.filter(p => p.errors.length === 0 && p.warnings.length > 0).length;

    let mergesHtml = '';
    if (colorMerges.length) {
        mergesHtml = '<div class="alert alert-info py-2 mt-2 mb-0" style="font-size:.78rem">' +
            '<i class="bi bi-palette me-1"></i> ألوان رح تتوحّد تلقائياً (تفاوت إملائي بسيط): ' +
            colorMerges.map(g => g.join(' = ')).join('، ') + '</div>';
    }

    document.getElementById('previewSummary').innerHTML =
        `<span class="badge bg-success">${valid} صالح</span> ` +
        (warnCount ? `<span class="badge bg-warning text-dark">${warnCount} فيها تحذير (بتستورد عادي)</span> ` : '') +
        `<span class="badge bg-danger">${total - valid} فيه خطأ (لن تُستورد)</span>` +
        mergesHtml;

    const tbody = document.getElementById('previewBody');
    tbody.innerHTML = '';
    preview.forEach(item => {
        const d = item.data;
        const tr = document.createElement('tr');
        tr.className = item.errors.length ? 'row-error' : (item.warnings.length ? 'row-warning' : 'row-ok');
        const notes = [
            ...item.errors.map(e => `<span class="text-danger">${e}</span>`),
            ...item.warnings.map(w => `<span class="text-warning-emphasis">${w}</span>`),
        ].join('<br>');
        tr.innerHTML = `
            <td>${item.row}</td>
            <td>${d.model_number || ''} ${item.is_new_product ? '<span class="badge bg-info">جديد</span>' : ''}</td>
            <td>${d.product_name || ''}</td>
            <td>${d.size || ''}</td>
            <td>${d.color || '-'}</td>
            <td>${d.selling_price || ''}</td>
            <td>${d.cost_price || '-'}</td>
            <td>${d.warehouse_code || '-'}</td>
            <td>${d.quantity || '-'}</td>
            <td>${notes}</td>
        `;
        tbody.appendChild(tr);
    });

    document.getElementById('confirmBtn').disabled = valid === 0;
}

function confirmImport() {
    if (!confirm('تأكيد استيراد الصفوف الصالحة؟ الصفوف الفيها أخطاء رح تُتخطّى.')) return;
    document.getElementById('commitSpinner').style.display = 'inline-block';

    const fd = new FormData();
    fd.append('_action', 'commit');
    fetch(location.href, { method: 'POST', body: fd }).then(r => r.json()).then(d => {
        document.getElementById('commitSpinner').style.display = 'none';
        if (d.ok) {
            toast(`تم: ${d.created.products} منتج، ${d.created.sizes} مقاس، ${d.created.variants} متغيّر، ${d.created.stock_lines} سطر رصيد`);
            setTimeout(() => { window.location.href = 'products.php'; }, 2000);
        } else {
            toast(d.msg, 'danger');
        }
    });
}
</script>
</body>
</html>
