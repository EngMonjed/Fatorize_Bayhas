<?php
/**
 * includes/product_save_helper.php
 * حفظ مقاسات ومتغيرات المنتج (UPSERT) — يُستخدم من product_add / product_edit
 * كما يُستخدم من inventory/barcode.php لتوليد باركودات للعناصر الناقصة.
 */

function productSizeKey(string $ageType, string $size): string
{
    return $ageType . '|' . $size;
}

/** التأكد من وجود عمود updated_by في جدول المقاسات */
function ensureProductSizesAuditColumns(PDO $pdo, string $table): void
{
    static $checked = [];
    if (isset($checked[$table])) {
        return;
    }
    try {
        $pdo->query("SELECT updated_by FROM `{$table}` LIMIT 0");
    } catch (Throwable $e) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `updated_by` INT(11) DEFAULT NULL COMMENT 'آخر من عدّل' AFTER `is_active`");
    }
    $checked[$table] = true;
}

/** استخراج بيانات التسعير لكروب واحد */
function resolveGroupPricing(array $pricing, string $grpKey, int $baseCurId): array
{
    $sellPrice = 0.0;
    $costPrice = null;
    $marginPct = null;
    $curId     = $baseCurId;
    $exRate    = 1.0;

    foreach ($pricing as $pr) {
        if (($pr['group_key'] ?? '') !== $grpKey) {
            continue;
        }
        $sellPriceRaw = (float)($pr['sell_price'] ?? 0);
        $costRaw      = $pr['cost_price'] ?? '';
        $marginRaw    = $pr['margin'] ?? '';
        $costPriceRaw = ($costRaw !== '' && $costRaw !== null) ? (float)$costRaw : null;
        $marginPct    = ($marginRaw !== '' && $marginRaw !== null) ? (float)$marginRaw : null;
        $curId        = (int)($pr['currency_id'] ?? $baseCurId) ?: $baseCurId;
        $exRate       = max(0.000001, (float)($pr['exchange_rate'] ?? 1));
        $sellPrice    = $curId === $baseCurId ? $sellPriceRaw : round($sellPriceRaw / $exRate, 4);
        $costPrice    = $costPriceRaw === null ? null
            : ($curId === $baseCurId ? $costPriceRaw : round($costPriceRaw / $exRate, 4));
        break;
    }

    return compact('sellPrice', 'costPrice', 'marginPct', 'curId', 'exRate');
}

/**
 * حفظ المقاسات بـ UPSERT (يحافظ على id المقاسات الموجودة)
 * @return array قائمة المقاسات النشطة [{id, size, age_type}, ...]
 */
function saveProductSizes(
    PDO $pdo,
    string $table,
    int $productId,
    array $groups,
    array $pricing,
    int $baseCurId,
    int $userId
): array {
    ensureProductSizesAuditColumns($pdo, $table);

    $st = $pdo->prepare("SELECT * FROM `{$table}` WHERE product_id = ?");
    $st->execute([$productId]);
    $existingMap = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existingMap[productSizeKey($row['age_type'], (string)$row['size'])] = $row;
    }

    $sortOrder = 0;
    $keepKeys  = [];

    foreach ($groups as $grp) {
        $grpKey    = $grp['key'] ?? '';
        $ageType   = $grp['type'] ?? 'سنة';
        $packetQty = count($grp['sizes'] ?? []);
        extract(resolveGroupPricing($pricing, $grpKey, $baseCurId));

        foreach ($grp['sizes'] ?? [] as $szVal) {
            $szLabel = trim((string)$szVal);
            if ($szLabel === '') {
                continue;
            }

            $key = productSizeKey($ageType, $szLabel);
            $keepKeys[] = $key;

            if (isset($existingMap[$key])) {
                $rowId = (int)$existingMap[$key]['id'];
                $pdo->prepare("UPDATE `{$table}` SET
                    sort_order=?, selling_price=?, cost_price=?,
                    base_currency_id=?, currency_id=?, exchange_rate=?,
                    margin_pct=?, packet_qty=?, is_active=1,
                    updated_by=?, updated_at=NOW()
                    WHERE id=?")
                    ->execute([
                        $sortOrder++, $sellPrice, $costPrice,
                        $baseCurId, $curId, $exRate,
                        $marginPct, $packetQty, $userId, $rowId,
                    ]);
            } else {
                // (تحقّقنا من CREATE TABLE الفعلي — فيه updated_by بس).
                // النسخة السابقة كانت تحاول تدرج created_by فسبّبت خطأ
                // SQL يوقف كل عملية إضافة منتج بمنتصفها (المقاسات
                // والمتغيرات ما كانت تُحفظ أبداً بسبب توقف التنفيذ هنا).
                $pdo->prepare("INSERT INTO `{$table}`
                    (product_id, size, age_type, sort_order, selling_price, cost_price,
                     base_currency_id, currency_id, exchange_rate, margin_pct, packet_qty,
                     is_active, updated_by, updated_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?,NOW())")
                    ->execute([
                        $productId, $szLabel, $ageType, $sortOrder++, $sellPrice, $costPrice,
                        $baseCurId, $curId, $exRate, $marginPct, $packetQty,
                        $userId,
                    ]);
            }
        }
    }

    foreach ($existingMap as $key => $row) {
        if (!in_array($key, $keepKeys, true)) {
            $pdo->prepare("DELETE FROM `{$table}` WHERE id=?")->execute([(int)$row['id']]);
        }
    }

    $st = $pdo->prepare("SELECT id, size, age_type FROM `{$table}` WHERE product_id=? AND is_active=1 ORDER BY sort_order");
    $st->execute([$productId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * ⚠ محدَّثة: باركود رقمي عشوائي من ٩ خانات (بدل صيغة Code128 النصية
 * القديمة {MODEL}-V{000000}) — بدون أي علاقة برقم الموديل أو variant_id.
 * التفرّد يُتحقّق منه فعلياً بقاعدة البيانات (لازم $pdo/$table)، لأن
 * فراغ ٩ خانات (10^9) أصغر بكتير من صيغة الـ15 المقترحة سابقاً، فالتصادم
 * فعلياً وارد بعد عدد كبير من الكروبات/الألوان.
 *
 * تُستخدم من:
 *  - syncProductVariants() أدناه (كروب واحد × لون واحد = باركود مشترك)
 *  - inventory/barcode.php (توليد دفعة/توليد فردي للعناصر الناقصة)
 */
function generateFallbackBarcode(string $modelNumber, int $variantId, ?PDO $pdo = null, ?string $table = null): string
{
    $generate = function (): string {
        $first = (string) random_int(1, 9); // يضمن طول فعلي ثابت = 9
        $rest = '';
        for ($i = 0; $i < 8; $i++) {
            $rest .= (string) random_int(0, 9);
        }
        return $first . $rest;
    };

    if ($pdo && $table) {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = $generate();
            $chk = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE barcode = ?");
            $chk->execute([$code]);
            if ((int) $chk->fetchColumn() === 0) {
                return $code;
            }
        }
        throw new RuntimeException('تعذّر توليد باركود فريد من 9 خانات بعد عدة محاولات');
    }

    return $generate();
}

/**
 * مزامنة متغيرات المنتج (لون × مقاس)
 *
 * ⚠ محدَّثة: الباركود صار مشترك لكل (كروب سعري × لون) — بقرار صريح:
 * الشغل بالنظام كله قائم على إدخال/تخريج بالكروب (باكيت)، مو بالقطعة
 * المفردة، فالباركود لازم يعكس نفس المنطق. يعني كروب "٢-٥ سنة" أحمر =
 * باركود واحد يغطي كل مقاساته، مختلف عن نفس الكروب أخضر، ومختلف عن
 * كروب "٦-٩ سنة" أحمر. (تمييز القطعة المفردة بالضبط — مؤجّل لحد ما
 * تُبنى نقطة بيع مفرّق مستقبلاً، مو مطلوب هلق.)
 *
 * ملاحظة محفوظة من الإصلاح الأصلي: عمود barcode لا يُلمس إطلاقاً عند
 * التحديث لمتغيّر موجود مسبقاً — يُدرَج/يُولَّد فقط للمتغيرات الجديدة
 * (بلا باركود)، حفاظاً على أي ملصق مطبوع فعلياً على قطعة مادية.
 */
function syncProductVariants(
    PDO $pdo,
    string $table,
    int $productId,
    string $model,
    array $groups,
    array $colors,
    array $allSizes,
    int $userId
): void {
    $newColorIds = array_values(array_filter(array_map(fn($c) => (int)($c['id'] ?? 0), $colors)));
    $newSizeIds  = array_column($allSizes, 'id');

    if ($newColorIds && $newSizeIds) {
        $inClr = implode(',', array_fill(0, count($newColorIds), '?'));
        $inSz  = implode(',', array_fill(0, count($newSizeIds), '?'));
        $pdo->prepare("DELETE FROM `{$table}` WHERE product_id=? AND (color_id NOT IN ({$inClr}) OR size_id NOT IN ({$inSz}))")
            ->execute(array_merge([$productId], $newColorIds, $newSizeIds));
    } elseif (!$newColorIds) {
        $pdo->prepare("DELETE FROM `{$table}` WHERE product_id=?")->execute([$productId]);
    }

    $stmt = $pdo->prepare("INSERT INTO `{$table}`
        (product_id, size_id, color_id, barcode, is_active, created_by, updated_by, updated_at)
        VALUES (?, ?, ?, NULL, 1, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            is_active  = 1,
            updated_by = VALUES(updated_by),
            updated_at = NOW()");

    foreach ($colors as $ci => $clr) {
        $colorId = (int)($clr['id'] ?? 0);
        if (!$colorId) {
            continue;
        }
        foreach ($allSizes as $sz) {
            $stmt->execute([$productId, $sz['id'], $colorId, $userId, $userId]);
        }
    }

    // ── خريطة "قيمة المقاس" → مفتاح الكروب الذي ينتمي له ──
    $sizeValueToGroupKey = [];
    foreach ($groups as $grp) {
        $grpKey = $grp['key'] ?? '';
        foreach ($grp['sizes'] ?? [] as $szVal) {
            $sizeValueToGroupKey[(string)$szVal] = $grpKey;
        }
    }
    $sizeIdToValue = [];
    foreach ($allSizes as $sz) {
        $sizeIdToValue[$sz['id']] = (string)($sz['size'] ?? '');
    }

    // توليد باركود لكل (كروب × لون) — فقط للمتغيرات الجديدة بلا باركود
    $missing = $pdo->prepare("SELECT id, size_id, color_id FROM `{$table}` WHERE product_id=? AND (barcode IS NULL OR barcode='')");
    $missing->execute([$productId]);
    $upd = $pdo->prepare("UPDATE `{$table}` SET barcode=?, updated_by=?, updated_at=NOW() WHERE id=?");

    $groupColorBarcode = []; // "{group_key}|{color_id}" => الباركود المشترك
    foreach ($missing->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sizeVal = $sizeIdToValue[$row['size_id']] ?? '';
        $grpKey  = $sizeValueToGroupKey[$sizeVal] ?? 'g0';
        $mapKey  = $grpKey . '|' . $row['color_id'];

        if (!isset($groupColorBarcode[$mapKey])) {
            $groupColorBarcode[$mapKey] = generateFallbackBarcode($model, (int)$row['id'], $pdo, $table);
        }
        $upd->execute([$groupColorBarcode[$mapKey], $userId, $row['id']]);
    }
}
