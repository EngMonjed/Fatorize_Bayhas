-- ١. الرصيد بالمستودعات (يرتبط بـvariant_id)
DELETE FROM warehouse_items_ret;

-- ٢. تفاصيل حركات المخزون (لو جربت أي فاتورة شراء/بيع عليهم)
DELETE FROM inventory_movement_details_ret;
DELETE FROM inventory_movements_ret;

-- ٣. بنود فواتير الشراء/البيع (لو جربتهم — لو لسا ما جربت، هالسطرين ما رح يأثروا، آمنين تنفّذهم برضو)
DELETE FROM purchase_items_ret;
DELETE FROM sales_invoice_items_ret;

-- ٤. المتغيرات (مقاس × لون) — لازم قبل المقاسات والألوان
DELETE FROM product_variants_ret;

-- ٥. المقاسات (الأسعار)
DELETE FROM product_sizes_ret;

-- ٦. المنتج نفسه (الأب — آخر شي)
DELETE FROM products_ret;