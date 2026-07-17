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
                // ⚠ إصلاح: product_sizes_alp لا يحتوي عمود created_by إطلاقاً
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
 * يُولّد قيمة باركود خطي متوافقة مع Code128 (حروف/أرقام إنجليزية فقط —
 * بلا مسافات أو رموز قد تُربك بعض قارئات الباركود الرخيصة)، فريدة بشكل
 * مضمون لأنها مبنية على معرّف المتغيّر (variant id) الذي هو AUTO_INCREMENT
 * فريد أصلاً، مع إبقاء رقم الموديل ظاهراً في الكود لتسهيل القراءة البشرية.
 *
 * تُستخدم من:
 *  - syncProductVariants() أدناه (عند إنشاء متغيّر جديد بلا باركود)
 *  - inventory/barcode.php (توليد دفعة/توليد فردي للعناصر الناقصة)
 */
function generateFallbackBarcode(string $modelNumber, int $variantId): string
{
    $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $modelNumber));
    if ($clean === '') {
        $clean = 'ITEM';
    }
    $clean = substr($clean, 0, 12); // إبقاء الكود قصيراً بما يكفي لملصق طباعة
    return $clean . '-V' . str_pad((string)$variantId, 6, '0', STR_PAD_LEFT);
}

/**
 * مزامنة متغيرات المنتج (لون × مقاس)
 *
 * ملاحظة إصلاح: النسخة السابقة كانت تُركّب باركوداً وتكتبه عبر
 * `ON DUPLICATE KEY UPDATE barcode = VALUES(barcode)` — أي أنها كانت
 * تُعيد كتابة/تغيير باركود أي متغيّر موجود مسبقاً في كل مرة يُحفظ فيها
 * المنتج، حتى لو كان الباركود مطبوعاً فعلياً على ملصق مادي. الآن: عمود
 * barcode لا يُلمس إطلاقاً عند التحديث؛ يُدرَج بقيمة NULL للمتغيرات
 * الجديدة فقط، ثم يُولَّد لها باركود فريد دفعة واحدة بعد الحلقة.
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

    // توليد باركود لأي متغيّر جديد بلا باركود (لا يمس متغيّرات موجودة مسبقاً)
    $missing = $pdo->prepare("SELECT id FROM `{$table}` WHERE product_id=? AND (barcode IS NULL OR barcode='')");
    $missing->execute([$productId]);
    $upd = $pdo->prepare("UPDATE `{$table}` SET barcode=?, updated_by=?, updated_at=NOW() WHERE id=?");
    foreach ($missing->fetchAll(PDO::FETCH_COLUMN) as $variantId) {
        $upd->execute([generateFallbackBarcode($model, (int)$variantId), $userId, $variantId]);
    }
}
