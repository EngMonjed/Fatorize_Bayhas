<?php
/**
 * includes/product_save_helper.php
 * حفظ مقاسات ومتغيرات المنتج (UPSERT) — يُستخدم من product_add / product_edit
 * كما يُستخدم من inventory/barcode.php لتوليد باركودات للعناصر الناقصة.
 *
 * ✅ تحديث (أغسطس ٢٠٢٦): "الكروب" صار حقيقة مخزَّنة فعلياً بعمود
 * product_sizes.group_key، مو مُعاد استنتاجه من (السعر+packet_qty) بكل
 * مكان لحاله. هالتغيير بيلغي فئة كاملة من البقات (تجميع غلط بمودال
 * الفاتورة، باركود غير مشترك صح) كانت ناتجة عن اعتماد كل ملف على
 * إعادة استنتاج هش بدل قراءة مصدر حقيقة واحد.
 */

/**
 * ✅ محدَّثة: المفتاح صار يشمل group_key كمان — عشان نفس قيمة المقاس
 * تقدر توجد بأكتر من كروب لنفس الموديل (حالة حقيقية متكررة: كروب
 * 2-3-4-5-6 بسعر X، وكروب 6-7-8-9 بسعر Y — النمرة 6 مكررة شرعياً
 * بالكروبين). قبل هالتعديل كان المفتاح (نوع العمر + المقاس) بس، فصفّا
 * المقاس المكرر يتصادموا ويكتب الثاني فوق الأول بصمت.
 *
 * ⚠ $groupKey اختياري عمداً (افتراضي فاضي) — عشان أي نداء قديم للدالة
 * من ملف تاني يضل يشتغل بنفس سلوكه السابق بالضبط، بدون كسر.
 */
function productSizeKey(string $ageType, string $size, string $groupKey = ''): string
{
    return $ageType . '|' . $size . '|' . $groupKey;
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
    $curId = $baseCurId;
    $exRate = 1.0;

    foreach ($pricing as $pr) {
        if (($pr['group_key'] ?? '') !== $grpKey) {
            continue;
        }
        $sellPriceRaw = (float) ($pr['sell_price'] ?? 0);
        $costRaw = $pr['cost_price'] ?? '';
        $marginRaw = $pr['margin'] ?? '';
        $costPriceRaw = ($costRaw !== '' && $costRaw !== null) ? (float) $costRaw : null;
        $marginPct = ($marginRaw !== '' && $marginRaw !== null) ? (float) $marginRaw : null;
        $curId = (int) ($pr['currency_id'] ?? $baseCurId) ?: $baseCurId;
        $exRate = max(0.000001, (float) ($pr['exchange_rate'] ?? 1));
        $sellPrice = $curId === $baseCurId ? $sellPriceRaw : round($sellPriceRaw / $exRate, 4);
        $costPrice = $costPriceRaw === null ? null
            : ($curId === $baseCurId ? $costPriceRaw : round($costPriceRaw / $exRate, 4));
        break;
    }

    return compact('sellPrice', 'costPrice', 'marginPct', 'curId', 'exRate');
}

/**
 * حفظ المقاسات بـ UPSERT (يحافظ على id المقاسات الموجودة)
 * ✅ يخزّن group_key الحقيقي (مفتاح الكروب من الواجهة) مباشرة بكل صف —
 * مصدر الحقيقة الوحيد لأي تجميع كروب لاحق بأي ملف.
 * @return array قائمة المقاسات النشطة [{id, size, age_type, group_key}, ...]
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
        // ✅ group_key مُضاف للمفتاح — لازم يطابق نفس صيغة المفتاح
        // المستخدمة بالحلقة تحت، وإلا ما بينلاقى الصف الموجود فينحذف
        // ويُعاد إنشاؤه (وبالتالي تضيع باركوداته المطبوعة)
        $existingMap[productSizeKey($row['age_type'], (string) $row['size'], (string) ($row['group_key'] ?? ''))] = $row;
    }

    $sortOrder = 0;
    $keepKeys = [];

    foreach ($groups as $grp) {
        $grpKey = $grp['key'] ?? '';
        $ageType = $grp['type'] ?? 'سنة';
        $packetQty = count($grp['sizes'] ?? []);
        extract(resolveGroupPricing($pricing, $grpKey, $baseCurId));

        foreach ($grp['sizes'] ?? [] as $szVal) {
            $szLabel = trim((string) $szVal);
            if ($szLabel === '') {
                continue;
            }

            $key = productSizeKey($ageType, $szLabel, $grpKey);
            $keepKeys[] = $key;

            if (isset($existingMap[$key])) {
                $rowId = (int) $existingMap[$key]['id'];
                $pdo->prepare("UPDATE `{$table}` SET
                    sort_order=?, selling_price=?, cost_price=?,
                    base_currency_id=?, currency_id=?, exchange_rate=?,
                    margin_pct=?, packet_qty=?, group_key=?, is_active=1,
                    updated_by=?, updated_at=NOW()
                    WHERE id=?")
                    ->execute([
                        $sortOrder++,
                        $sellPrice,
                        $costPrice,
                        $baseCurId,
                        $curId,
                        $exRate,
                        $marginPct,
                        $packetQty,
                        $grpKey,
                        $userId,
                        $rowId,
                    ]);
            } else {
                $pdo->prepare("INSERT INTO `{$table}`
                    (product_id, size, age_type, sort_order, selling_price, cost_price,
                     base_currency_id, currency_id, exchange_rate, margin_pct, packet_qty,
                     group_key, is_active, updated_by, updated_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,NOW())")
                    ->execute([
                        $productId,
                        $szLabel,
                        $ageType,
                        $sortOrder++,
                        $sellPrice,
                        $costPrice,
                        $baseCurId,
                        $curId,
                        $exRate,
                        $marginPct,
                        $packetQty,
                        $grpKey,
                        $userId,
                    ]);
            }
        }
    }

    foreach ($existingMap as $key => $row) {
        if (!in_array($key, $keepKeys, true)) {
            $pdo->prepare("DELETE FROM `{$table}` WHERE id=?")->execute([(int) $row['id']]);
        }
    }

    // ✅ group_key مُضاف للـSELECT — بيرجع مع كل صف من هون فصاعداً،
    // عشان syncProductVariants() تقرأه مباشرة بدل ما تعيد استنتاجه
    $st = $pdo->prepare("SELECT id, size, age_type, group_key FROM `{$table}` WHERE product_id=? AND is_active=1 ORDER BY sort_order");
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
 * ✅ محدَّثة (أغسطس ٢٠٢٦): تجميع الباركود المشترك صار بالاعتماد على
 * group_key **المخزَّن فعلياً** بكل صف مقاس (مُمرَّر عبر $allSizes من
 * saveProductSizes())، بدل إعادة بنائه من قيمة المقاس النصية عبر
 * $sizeValueToGroupKey (الطريقة القديمة الهشة — كانت بتفشل لو نفس قيمة
 * المقاس تكررت بمنتجات/سياقات مختلفة).
 *
 * ⚠ محدَّثة سابقاً: الباركود صار مشترك لكل (كروب سعري × لون) — بقرار
 * صريح: الشغل بالنظام كله قائم على إدخال/تخريج بالكروب (باكيت)، مو
 * بالقطعة المفردة.
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
    $newColorIds = array_values(array_filter(array_map(fn($c) => (int) ($c['id'] ?? 0), $colors)));
    $newSizeIds = array_column($allSizes, 'id');

    if ($newColorIds && $newSizeIds) {
        $inClr = implode(',', array_fill(0, count($newColorIds), '?'));
        $inSz = implode(',', array_fill(0, count($newSizeIds), '?'));
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
        $colorId = (int) ($clr['id'] ?? 0);
        if (!$colorId) {
            continue;
        }
        foreach ($allSizes as $sz) {
            $stmt->execute([$productId, $sz['id'], $colorId, $userId, $userId]);
        }
    }

    // ✅ خريطة "size_id" → "group_key" — من group_key المخزَّن فعلياً
    // بكل صف مقاس (مصدر الحقيقة)، مو إعادة استنتاج من قيمة المقاس النصية
    $sizeIdToGroupKey = [];
    foreach ($allSizes as $sz) {
        $sizeIdToGroupKey[$sz['id']] = $sz['group_key'] ?? 'g0';
    }

    $groupColorBarcode = []; // "{group_key}|{color_id}" => الباركود المشترك

    // ✅ إصلاح: نعبّي الخريطة أولاً من الباركودات **الموجودة فعلياً**
    // بقاعدة البيانات. بدون هالخطوة، أي مقاس جديد يُضاف لكروب موجود
    // كان بياخد باركوداً جديداً كلياً بدل باركود كروبه — فيصير "يتيم"
    // ويظهر لحاله بشاشة الباركود بدل ما ينضم لكروبه.
    $existingBc = $pdo->prepare("SELECT size_id, color_id, barcode
        FROM `{$table}`
        WHERE product_id=? AND barcode IS NOT NULL AND barcode<>''");
    $existingBc->execute([$productId]);
    foreach ($existingBc->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $grpKey = $sizeIdToGroupKey[$row['size_id']] ?? null;
        if ($grpKey === null) {
            continue; // مقاس ما عاد ينتمي للمنتج — نتجاهل باركوده
        }
        $mapKey = $grpKey . '|' . $row['color_id'];
        if (!isset($groupColorBarcode[$mapKey])) {
            $groupColorBarcode[$mapKey] = $row['barcode'];
        }
    }

    // توليد باركود لكل (كروب × لون) — فقط للمتغيرات الجديدة بلا باركود
    $missing = $pdo->prepare("SELECT id, size_id, color_id FROM `{$table}` WHERE product_id=? AND (barcode IS NULL OR barcode='')");
    $missing->execute([$productId]);
    $upd = $pdo->prepare("UPDATE `{$table}` SET barcode=?, updated_by=?, updated_at=NOW() WHERE id=?");

    foreach ($missing->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $grpKey = $sizeIdToGroupKey[$row['size_id']] ?? 'g0';
        $mapKey = $grpKey . '|' . $row['color_id'];

        // هلق بيولّد جديد بس لو الكروب×اللون فعلاً ما عنده باركود
        // (لا بقاعدة البيانات ولا انولّد بهالدورة نفسها)
        if (!isset($groupColorBarcode[$mapKey])) {
            $groupColorBarcode[$mapKey] = generateFallbackBarcode($model, (int) $row['id'], $pdo, $table);
        }
        $upd->execute([$groupColorBarcode[$mapKey], $userId, $row['id']]);
    }
}
