-- ١. الرصيد بالمستودعات (يرتبط بـvariant_id)
DELETE FROM warehouse_items_ret;
ALTER TABLE warehouse_items_ret AUTO_INCREMENT = 1;

-- ٢. تفاصيل حركات المخزون (لو جربت أي فاتورة شراء/بيع عليهم)
DELETE FROM inventory_movement_details_ret;
ALTER TABLE inventory_movement_details_ret AUTO_INCREMENT = 1;
DELETE FROM inventory_movements_ret;
ALTER TABLE inventory_movements_ret AUTO_INCREMENT = 1;

-- ٣. بنود فواتير الشراء/البيع (لو جربتهم — لو لسا ما جربت، هالسطرين ما رح يأثروا، آمنين تنفّذهم برضو)
DELETE FROM purchase_items_ret;
ALTER TABLE purchase_items_ret AUTO_INCREMENT = 1;
DELETE FROM sales_invoice_items_ret;
ALTER TABLE sales_invoice_items_ret AUTO_INCREMENT = 1;

-- ٤. المتغيرات (مقاس × لون) — لازم قبل المقاسات والألوان
DELETE FROM product_variants_ret;
ALTER TABLE product_variants_ret AUTO_INCREMENT = 1;

-- ٥. المقاسات (الأسعار)
DELETE FROM product_sizes_ret;
ALTER TABLE product_sizes_ret AUTO_INCREMENT = 1;

-- ٦. المنتج نفسه (الأب — آخر شي)
DELETE FROM products_ret;
ALTER TABLE products_ret AUTO_INCREMENT = 1;