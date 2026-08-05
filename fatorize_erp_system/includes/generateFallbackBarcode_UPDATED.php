<?php
/**
 * ⚠ استبدل بس دالة generateFallbackBarcode() الموجودة حالياً بـ
 * includes/product_save_helper.php بهاد النسخة — باقي الملف (saveProductSizes,
 * syncProductVariants) يضل زي ما هو، بدون أي تغيير.
 *
 * التغيير: من صيغة Code128 نصية ({MODEL}-V{000000}) إلى ٩ خانات أرقام
 * عشوائية فريدة بالكامل — بدون أي علاقة برقم الموديل. التفرّد يُتحقّق
 * منه فعلياً بقاعدة البيانات (مو بس بالاحتمال الرياضي)، لأن ٩ خانات
 * فراغها أصغر بكتير من ١٥ (10^9 احتمال بس)، فالتصادم فعلياً ممكن
 * يصير خصوصاً بعد عدد كبير من المنتجات.
 */
function generateFallbackBarcode(string $modelNumber, int $variantId, ?PDO $pdo = null, ?string $table = null): string
{
    // أول رقم غير صفر (يضمن طول فعلي ثابت = 9، بدون ما يبلش بصفر
    // يضيع لو تحوّل الرقم لـ int بمكان تاني بالغلط)
    $generate = function (): string {
        $first = (string) random_int(1, 9);
        $rest = '';
        for ($i = 0; $i < 8; $i++) {
            $rest .= (string) random_int(0, 9);
        }
        return $first . $rest;
    };

    if ($pdo && $table) {
        // تحقق فعلي من عدم التكرار بقاعدة البيانات — ضروري هون (بعكس
        // صيغة الـ15 خانة)، لأن فراغ 9 أرقام (10^9) أصغر بكتير ومحتمل
        // يصادف باركود موجود فعلاً بعد آلاف المنتجات.
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = $generate();
            $chk = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE barcode = ?");
            $chk->execute([$code]);
            if ((int) $chk->fetchColumn() === 0) {
                return $code;
            }
        }
        throw new RuntimeException('تعذّر توليد باركود فريد من 9 خانات بعد عدة محاولات — راجع مساحة الأرقام المتاحة');
    }

    // بدون $pdo/$table (نداء قديم أو اختبار) — توليد بدون تحقق فعلي
    return $generate();
}
