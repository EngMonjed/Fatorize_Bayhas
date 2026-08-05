<?php
/**
 * ⚠ استبدل بس دالة syncProductVariants() الموجودة حالياً بـ
 * includes/product_save_helper.php بهاد النسخة. باقي الملف
 * (productSizeKey, ensureProductSizesAuditColumns, resolveGroupPricing,
 * saveProductSizes, generateFallbackBarcode — بعد تحديثها لـ٩ خانات)
 * يضل زي ما هو تماماً، بدون أي تغيير.
 *
 * ⚠ التغيير الجوهري بقرار صريح من صاحب المشروع: الباركود صار
 * **مشترك لكل (كروب سعري × لون)**، مو لكل متغيّر (مقاس مفرد) لحاله.
 * يعني كروب "٢-٥ سنة" أحمر = باركود واحد يغطي كل مقاساته (٢،٣،٤،٥)،
 * مختلف تماماً عن نفس الكروب أخضر، ومختلف عن كروب "٦-٩ سنة" أحمر.
 *
 * ⚠ تنبيه تشغيلي (ذكرته للمستخدم وواف قرار واعي): بما إنه الباركود
 * ما عاد يحدد المقاس بالضبط، أي عملية مسح (استلام بضاعة، بيع، جرد)
 * لازم يترافق معها تحديد المقاس يدوياً بعد المسح — النظام ما رح
 * يقدر يعرف "مقاس ٣" من "مقاس ٥" لنفس الكروب واللون من الباركود لحاله.
 *
 * ⚠ التوافق مع البيانات القديمة: الباركودات الفردية المخزّنة مسبقاً
 * (لمتغيرات موجودة أصلاً) ما بتتلمس إطلاقاً — نفس مبدأ عدم الكتابة فوق
 * باركود مطبوع فعلياً (موثّق بالكومنت الأصلي بالدالة). بس المتغيرات
 * الجديدة (بلا باركود) هي يلي بتاخد القيمة المشتركة الجديدة.
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
    // $groups[i]['sizes'] فيها القيم الخام (مثال: [2,3,4,5])، ونطابقها
    // مع $allSizes[i]['size'] (نفس القيمة كنص، كما رجعت من saveProductSizes)
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

    // توليد باركود لكل (كروب × لون) — بس للمتغيرات الجديدة بلا باركود
    $missing = $pdo->prepare("SELECT id, size_id, color_id FROM `{$table}` WHERE product_id=? AND (barcode IS NULL OR barcode='')");
    $missing->execute([$productId]);
    $upd = $pdo->prepare("UPDATE `{$table}` SET barcode=?, updated_by=?, updated_at=NOW() WHERE id=?");

    $groupColorBarcode = []; // "{group_key}|{color_id}" => الباركود المشترك
    foreach ($missing->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sizeVal = $sizeIdToValue[$row['size_id']] ?? '';
        $grpKey  = $sizeValueToGroupKey[$sizeVal] ?? 'g0'; // fallback آمن لو ما انلقى تطابق
        $mapKey  = $grpKey . '|' . $row['color_id'];

        if (!isset($groupColorBarcode[$mapKey])) {
            $groupColorBarcode[$mapKey] = generateFallbackBarcode($model, (int)$row['id'], $pdo, $table);
        }
        $upd->execute([$groupColorBarcode[$mapKey], $userId, $row['id']]);
    }
}
