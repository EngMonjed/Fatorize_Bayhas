<?php
/**
 * barcode_print_helper.php — مساعد طباعة ملصقات الباركود
 * retail1/modules/inventory/barcode_print_helper.php
 *
 * ملف مستقل عمداً: كل منطق الطباعة (المقاسات، الأنماط، بناء نافذة
 * الطباعة) هون، عشان ما يتضخّم products.php أكتر. يُستدعى بسطر واحد
 * منه: <?php require_once __DIR__ . '/barcode_print_helper.php'; ?>
 *
 * ⚠ ملاحظة مهمة عن مقاس الورق: كروم ما بيدعم @page { size } بشكل
 * موثوق — بيقراه بس ما بيطبّقه دايماً على مقاس الورق الفعلي. لهيك
 * الحل الصحيح: المستخدم يختار المقاس هون، والكود يبني الملصق بنفس
 * المقاس بالضبط، وبعدين يختار نفس المقاس من إعدادات الطابعة كمان.
 */
?>

<!-- مودال اختيار مقاس الملصق قبل الطباعة -->
<div class="modal fade" id="bcSizeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;border:none">
            <div class="modal-header py-3 px-4 border-0"
                style="background:linear-gradient(135deg,#6d28d9,#7c3aed);border-radius:16px 16px 0 0">
                <h6 class="modal-title text-white fw-700 mb-0">
                    <i class="bi bi-rulers me-1"></i>مقاس ملصق الباركود
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pt-3 pb-2">
                <p class="text-muted" style="font-size:.8rem">
                    اختر مقاس الملصق الورقي الفعلي يلي بالطابعة عندك — كل
                    الأحجام (الخطوط، اللوغو، الباركود) رح تتكيّف تلقائياً معه.
                </p>

                <div id="bcSizeOptions" class="d-flex flex-column gap-2"></div>

                <!-- مقاس مخصّص -->
                <div class="border rounded p-2 mt-2" style="background:#f8fafc">
                    <label class="d-flex align-items-center gap-2 mb-2" style="cursor:pointer;font-size:.85rem">
                        <input type="radio" name="bcSize" value="custom" onchange="bcOnSizeChange()">
                        <b>مقاس مخصّص</b>
                    </label>
                    <div class="d-flex gap-2 align-items-center" id="bcCustomInputs"
                        style="opacity:.5;pointer-events:none">
                        <input type="number" id="bcCustomW" class="form-control form-control-sm" value="40" min="10"
                            max="200" step="1" style="width:80px">
                        <span style="font-size:.8rem">مم عرض ×</span>
                        <input type="number" id="bcCustomH" class="form-control form-control-sm" value="20" min="10"
                            max="200" step="1" style="width:80px">
                        <span style="font-size:.8rem">مم ارتفاع</span>
                    </div>
                </div>

                <div class="alert mt-3 mb-0 py-2"
                    style="background:#fffbeb;border:1px solid #fde68a;font-size:.76rem;color:#92400e">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <b>مهم:</b> بعد ما تدوس طباعة، اختار <b>نفس المقاس</b> من
                    إعدادات الطابعة (Paper size) — كروم ما بيضبط مقاس الورق
                    تلقائياً من الكود لحاله.
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-3">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">إلغاء</button>
                <button class="btn btn-sm fw-600" style="background:#7c3aed;color:#fff" onclick="bcDoPrint()">
                    <i class="bi bi-printer me-1"></i>طباعة
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    // ── مقاسات الملصقات الجاهزة ──
    // لكل مقاس: أبعاده بالمليمتر + أحجام الخطوط المتناسبة معه.
    // الأحجام محسوبة نسبياً — ملصق أصغر = خطوط أصغر، بحيث يضل المحتوى
    // كامل ظاهر بدون تداخل أو قص.
    const BC_SIZES = {
        '40x20': {
            label: '4 × 2 سم (صغير)', w: 40, h: 20,
            logoH: 3, fName: 3.6, fInfo: 3.4, fCode: 4, bcH: 20, bcW: 1.5, pad: 0.3
        },
        '50x25': {
            label: '5 × 2.5 سم (متوسط)', w: 50, h: 25,
            logoH: 4, fName: 4.6, fInfo: 4.3, fCode: 5, bcH: 26, bcW: 1.9, pad: 0.4
        },
        '80x40': {
            label: '8 × 4 سم (كبير)', w: 80, h: 40,
            logoH: 8, fName: 10, fInfo: 9, fCode: 8, bcH: 42, bcW: 2.6, pad: 0.8
        },
    };

    const BC_SIZE_STORAGE_KEY = 'bc_label_size';

    // بناء خيارات المقاسات بالمودال
    (function buildSizeOptions() {
        const saved = localStorage.getItem(BC_SIZE_STORAGE_KEY) || '40x20';
        const box = document.getElementById('bcSizeOptions');
        if (!box) return;
        box.innerHTML = Object.entries(BC_SIZES).map(([key, s]) => `
            <label class="d-flex align-items-center gap-2 border rounded p-2" style="cursor:pointer;font-size:.85rem">
                <input type="radio" name="bcSize" value="${key}" ${saved === key ? 'checked' : ''} onchange="bcOnSizeChange()">
                <span><b>${s.label}</b>
                    <span class="text-muted" style="font-size:.72rem"> — ${s.w}×${s.h} مم</span>
                </span>
            </label>`).join('');
    })();

    function bcOnSizeChange() {
        const val = document.querySelector('input[name="bcSize"]:checked')?.value;
        const customBox = document.getElementById('bcCustomInputs');
        const isCustom = val === 'custom';
        customBox.style.opacity = isCustom ? '1' : '.5';
        customBox.style.pointerEvents = isCustom ? 'auto' : 'none';
        if (!isCustom && val) localStorage.setItem(BC_SIZE_STORAGE_KEY, val);
    }

    // يرجّع إعدادات المقاس المختار حالياً (جاهزة للاستخدام بالطباعة)
    function bcGetSelectedSize() {
        const val = document.querySelector('input[name="bcSize"]:checked')?.value || '40x20';
        if (val !== 'custom') return BC_SIZES[val];

        // مقاس مخصّص — نحسب الخطوط نسبياً بالمقارنة مع المقاس المرجعي
        // (80×40 مم)، مع حد أدنى معقول حتى ما يصير الخط غير مقروء
        const w = Math.max(10, parseFloat(document.getElementById('bcCustomW').value) || 40);
        const h = Math.max(10, parseFloat(document.getElementById('bcCustomH').value) || 20);
        const scale = Math.min(w / 80, h / 40);
        return {
            label: `مخصّص ${w}×${h} مم`, w, h,
            logoH: Math.max(2.5, 8 * scale),
            fName: Math.max(3.5, 10 * scale),
            fInfo: Math.max(3.2, 9 * scale),
            fCode: Math.max(3.5, 8 * scale),
            bcH: Math.max(14, 42 * scale),
            bcW: Math.max(1.2, 2.6 * scale),
            pad: Math.max(0.2, 0.8 * scale),
        };
    }

    /**
     * بناء وفتح نافذة طباعة الملصقات.
     * @param {Array}  groups   مجموعات الباركود (كروب × لون) — كل وحدة فيها
     *                          barcode, color_name, sizes[], sizeLabel, selling_price
     * @param {String} prodName اسم المنتج
     * @param {String} modelNo  رقم الموديل
     * @param {String} logoUrl  مسار لوغو العميل
     */
    function bcOpenPrintWindow(groups, prodName, modelNo, logoUrl) {
        const S = bcGetSelectedSize();
        const printWindow = window.open('', '_blank');

        let html = `
            <html dir="rtl" lang="ar">
            <head>
            <meta charset="UTF-8">
            <style>
                @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap');
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: 'Cairo', sans-serif; margin: 0; padding: 0; }
                .barcode-label {
                    width: ${S.w}mm; height: ${S.h}mm;
                    display: flex; flex-direction: column;
                    justify-content: center; align-items: center;
                    margin: 0; padding: ${S.pad}mm;
                    overflow: hidden;
                    page-break-after: always; page-break-inside: avoid;
                }
                .logo-container {
                    display: flex; justify-content: center; align-items: center;
                    margin-bottom: 0.3mm; flex-shrink: 0;
                }
                .logo-container img { max-height: ${S.logoH}mm; max-width: 100%; object-fit: contain; }
                .product-name {
                    text-align: center; font-size: ${S.fName}pt; font-weight: 700; color: #000;
                    margin-bottom: 0.2mm; line-height: 1.15; flex-shrink: 0;
                    width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
                }
                .product-model {
                    text-align: center; font-size: ${S.fInfo}pt; font-weight: 700; color: #000;
                    margin-bottom: 0.3mm; line-height: 1.15; flex-shrink: 0;
                    width: 100%; white-space: nowrap;
                }
                /* ⚠ nowrap ضروري: بدونه "عدد القطع:" بينكسر لسطر والرقم
                   لسطر تاني بالمقاسات الصغيرة، فيفيض المحتوى ويقصّ
                   الباركود واللوغو (المشكلة يلي كانت ظاهرة بالطباعة).
                   وspace-between بيوزّعهم عالطرفين — مساحة أوسع من
                   التوسيط بهوامش ثابتة. */
                .info-row {
                    display: flex; justify-content: center;gap: 15mm; align-items: center;
                    width: 100%; padding: 0;
                    font-size: ${S.fInfo}pt; font-weight: 700; color: #000;
                    margin-bottom: 0.2mm; line-height: 1.15; flex-shrink: 0;
                    white-space: nowrap;
                }
                .info-row span { margin: 0; }
                .barcode-container {
                    display: flex; flex-direction: column;
                    justify-content: center; align-items: center;
                    width: 100%; margin-top: 0.3mm; flex-shrink: 0;
                }
                /* ⚠ width:100% ضروري — بدونه الـSVG بيضل بعرض ما ولّده
                   JsBarcode بس، فتضل فراغات بالجوانب (المشكلة الظاهرة
                   بالملصق المطبوع). هيك بيتمدّد لكامل عرض الملصق. */
                .barcode-svg { width: 100%; height: auto; display: block; }
                .barcode-number {
                    font-size: ${S.fCode}pt; font-weight: 700; color: #000;
                    margin-top: 0.2mm; text-align: center; letter-spacing: 0.3px;
                    font-family: 'Arial', sans-serif;
                }
                @media print {
                    @page { size: ${S.w}mm ${S.h}mm; margin: 0; }
                    body { margin: 0; padding: 0; }
                    .barcode-label { width: ${S.w}mm; height: ${S.h}mm; margin: 0; }
                }
            </style>
            </head>
            <body>
        `;

        // على المقاسات الصغيرة نختصر التسميات — "عدد القطع" لحالها بتاخد
        // عرض كبير نسبياً، وبالمقاس 40 مم ما بتترك مساحة للرقم واللون
        const isTight = S.w <= 50;
        const lblQty = isTight ? 'القطع' : 'عدد القطع';
        const lblColor = isTight ? '' : 'اللون: ';
        const lblSize = isTight ? 'قياس' : 'القياس';

        groups.forEach((g, index) => {
            const priceTxt = (g.selling_price !== null && g.selling_price !== undefined)
                ? parseFloat(g.selling_price).toFixed(2) : '';
            html += `
                <div class="barcode-label">
                    <div class="logo-container">
                        <img src="${logoUrl}" alt="" onerror="this.style.display='none'">
                    </div>
                    <div class="product-model">رقم الموديل: ${modelNo}</div>
                    <div class="info-row">
                        <span>${lblQty}: ${g.sizes.length}</span>
                        <span>${lblColor}${g.color_name || ''}</span>
                    </div>
                    <div class="info-row">
                        <span>${lblSize}: ${g.sizeLabel}</span>
                        <span>${priceTxt}</span>
                    </div>
                    <div class="barcode-container">
                        <svg id="barcode-${index}" class="barcode-svg"></svg>
                        <div class="barcode-number">${g.barcode}</div>
                    </div>
                </div>
            `;
        });

        html += `<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"><\/script><script>`;
        groups.forEach((g, index) => {
            html += `
                JsBarcode("#barcode-${index}", "${g.barcode}", {
                    format: "CODE128", width: ${S.bcW}, height: ${S.bcH},
                    displayValue: false, margin: 0
                });
            `;
        });
        html += `setTimeout(() => window.print(), 500);<\/script></body></html>`;

        printWindow.document.write(html);
        printWindow.document.close();
    }
</script>