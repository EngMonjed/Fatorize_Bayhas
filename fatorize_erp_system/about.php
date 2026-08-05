<?php
/**
 * about.php — من نحن / المستهدفين / قصتنا مع أول شريك
 * المسار: fatorize_erp_system/about.php (جذر المشروع)
 * ⚠ لا تتصل بأي قاعدة بيانات عمداً — صفحة عامة، مش خاصة بأي tenant.
 */
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>من نحن — فاتورايز</title>
    <meta name="description"
        content="فاتورايز من تطوير شركة كايلنك — نظام ERP عربي بالكامل، مبني بالتعاون المباشر مع مصانع ومحلات ألبسة حقيقية.">
    <link rel="icon" type="image/png" href="assets/images/logo.png">
    <link
        href="https://fonts.googleapis.com/css2?family=Changa:wght@500;600;700;800&family=Cairo:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@400;500&display=swap"
        rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/marketing.css" rel="stylesheet">
</head>

<body>

    <nav class="nav">
        <div class="container">
            <a href="index.php" class="nav-brand">
                <img src="assets/images/fatorize.png" alt="" onerror="this.style.display='none'">
                فاتورايز
            </a>
            <div class="nav-links">
                <a href="index.php#features">الميزات</a>
                <a href="about.php">من نحن</a>
                <a href="find-my-company.php" class="btn-nav-login">تسجيل الدخول</a>
            </div>
            <button class="nav-toggle" id="navToggle" aria-label="القائمة" aria-expanded="false"><i
                    class="bi bi-list"></i></button>
        </div>
    </nav>
    <script>
        document.getElementById('navToggle').addEventListener('click', function () {
            var links = document.querySelector('.nav-links');
            var open = links.classList.toggle('open');
            this.setAttribute('aria-expanded', open ? 'true' : 'false');
            this.querySelector('i').className = open ? 'bi bi-x-lg' : 'bi bi-list';
        });
    </script>

    <header class="page-header">
        <div class="container">
            <span class="eyebrow"><i class="bi bi-building"></i> شركة كايلنك</span>
            <h1>نظام بُني مع أصحاب المصانع، مو بس لهم</h1>
            <p>فاتورايز مش فكرة جاهزة نزّلناها على السوق — بنيناها وهي شغّالة فعلياً بمصنع ومحل حقيقيين، خطوة خطوة.</p>
        </div>
    </header>

    <section class="section">
        <div class="container">
            <div class="story-block">
                <div>
                    <span class="eyebrow"><i class="bi bi-flag"></i> ليش بلشنا</span>
                    <h3>الأنظمة العالمية قوية... بس مو مصممة لصناعتنا</h3>
                    <p>
                        أوراكل وساب وأودو أنظمة قوية فعلاً، بس بنيت لتخدم كل صناعة بنفس القالب.
                        صناعة الألبسة إلها تفاصيلها الخاصة: مقاسات وألوان وكروبات أسعار، مصنع
                        مرتبط بفروع بيع بعملات مختلفة، وموظفين بأنظمة دوام وأجور متنوعة. حاولنا
                        نبني نظام يفهم هالتفاصيل من الأساس، مو يحاول يتكيّف معها لاحقاً.
                    </p>
                </div>
                <div class="story-visual" style="display:flex;justify-content:center">
                    <div class="tag-card" style="width:260px">
                        <div class="tag-hole-string" aria-hidden="true"></div>
                        <div class="tag-code">KAYLINK · 2026</div>
                        <h4 style="font-size:1rem;margin:.5rem 0">فاتورايز</h4>
                        <p style="color:var(--muted);font-size:.82rem;margin:0">مصمم بالعربي، من الأساس</p>
                    </div>
                </div>
            </div>

            <div class="story-block reverse">
                <div>
                    <span class="eyebrow"><i class="bi bi-people"></i> فريقنا</span>
                    <h3>كايلنك — نبني أدوات تشتغل فعلاً، مو بس تعرض جيد</h3>
                    <p>
                        كايلنك شركة تقنية متخصصة ببناء أنظمة إدارية للأعمال بالمنطقة العربية.
                        فلسفتنا بسيطة: أي ميزة بالنظام لازم تكون شغّالة بمكان حقيقي قبل ما
                        نعتبرها جاهزة. مش عرض تقديمي، نظام بيشتغل يومياً بفواتير حقيقية.
                    </p>
                </div>
                <div class="story-visual" style="display:flex;justify-content:center">
                    <div class="tag-card" style="width:260px">
                        <div class="tag-code">FTR-PHILOSOPHY</div>
                        <h4 style="font-size:1rem;margin:.5rem 0">شغّال، مو بس جاهز</h4>
                        <div class="tag-barcode">
                            <span style="height:14px"></span><span style="height:20px"></span><span
                                style="height:24px"></span>
                            <span style="height:12px"></span><span style="height:22px"></span><span
                                style="height:16px"></span>
                            <span style="height:20px"></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="section-head">
                <span class="eyebrow"><i class="bi bi-compass"></i> يلي بيوجّهنا</span>
                <h2>ثلاث قواعد ما بنتنازل عنها</h2>
            </div>
            <div class="value-grid">
                <div class="value-card">
                    <div class="vnum">القاعدة الأولى</div>
                    <h5>كل عملية مالية = قيد متوازن</h5>
                    <p>ما في عملية بتصير بالنظام (بيع، شراء، راتب) بدون ما تنعكس صح على دفتر الأستاذ — تلقائياً، مو
                        يدوياً.</p>
                </div>
                <div class="value-card">
                    <div class="vnum">القاعدة الثانية</div>
                    <h5>العربي أول، مو ترجمة لاحقة</h5>
                    <p>الواجهة والتوثيق والدعم كلهم مبنيين بالعربي من الأول — مو نظام أجنبي ترجمناه.</p>
                </div>
                <div class="value-card">
                    <div class="vnum">القاعدة الثالثة</div>
                    <h5>بسيط يكفي إنه يشتغل صح</h5>
                    <p>مو كل ميزة موجودة بأوراكل لازم تكون عنا. بنركّز على يلي فعلاً بيحتاجه صاحب المصنع أو المحل.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="section" id="audience" style="background:#fff">
        <div class="container">
            <div class="section-head">
                <span class="eyebrow"><i class="bi bi-people-fill"></i> لمين هالنظام</span>
                <h2>مبني لنوعين محددين من أصحاب الأعمال</h2>
                <p>مو نظام عام لأي شركة — مبني تحديداً لصناعة الألبسة بالمنطقة العربية.</p>
            </div>
            <div class="audience-grid">
                <div class="audience-card">
                    <div class="tag-hole-string" aria-hidden="true"></div>
                    <h5><i class="bi bi-factory me-1"></i> أصحاب مصانع الألبسة</h5>
                    <p>
                        عندك خطوط إنتاج، مواد أولية، وفروع بيع بتحتاج تجدد مخزونها منك باستمرار؟
                        فاتورايز بيربطك فيهم مباشرة — بدون ملفات إكسل متناثرة أو رسائل واتساب
                        لمتابعة الطلبات.
                    </p>
                </div>
                <div class="audience-card">
                    <div class="tag-hole-string" aria-hidden="true"></div>
                    <h5><i class="bi bi-shop-window me-1"></i> أصحاب محلات ومؤسسات بيع الألبسة</h5>
                    <p>
                        محتاج تدير مبيعاتك ومخزونك ومحاسبتك بمكان واحد، وتطلب تجديد بضاعة من
                        المصنع بضغطة بدل مكالمة تلفون؟ هاد بالضبط يلي صُمم فاتورايز لأجله.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <section class="section" id="pilot">
        <div class="container">
            <div class="section-head">
                <span class="eyebrow"><i class="bi bi-award"></i> قصة حقيقية، مو دراسة حالة مصطنعة</span>
                <h2>بدأنا مع شريك واحد حقيقي: بايهاس</h2>
            </div>
            <div class="pilot-card">
                <div>
                    <span class="eyebrow">أول شريك لنا</span>
                    <h3>بايهاس — مصنع ومحلات ألبسة، حلب</h3>
                    <p>
                        كل ميزة بفاتورايز — من الفواتير للباركود للمحاسبة — انبنت وانجربت أول
                        شي مع بايهاس، بفروعها الحقيقية بحلب. مش عميل تجريبي وهمي؛ بيانات
                        حقيقية، فواتير حقيقية، مشاكل حقيقية حلّيناها سوا خطوة خطوة.
                    </p>
                    <p style="font-size:.85rem;color:#8C97B3">
                        نحن بمرحلة توسيع الشراكات — إذا صناعتك شبه بايهاس (تصنيع أو بيع ألبسة)،
                        حابين نسمع قصتك.
                    </p>
                </div>
                <div class="pilot-quote">
                    "أول شركة بنيت فاتورايز لأجلها. كل موديول بالنظام — من فاتورة الشراء
                    لتوليد الباركود — انبنى واتصلح بناءً على شغلها اليومي الفعلي، مو افتراضات
                    نظرية."
                </div>
            </div>
        </div>
    </section>

    <section class="section" style="text-align:center">
        <div class="container">
            <span class="eyebrow"><i class="bi bi-chat-dots"></i> جاهزين نحكي</span>
            <h2 style="margin-bottom:1rem">صناعتك شبه بايهاس؟</h2>
            <p style="color:var(--muted);max-width:480px;margin:0 auto 2rem">
                إذا عندك مصنع أو محل ألبسة وتعبت من إدارة كل شي بملفات متفرقة، تواصل معنا.
            </p>
            <a href="mailto:info@fatorize.com" class="btn-gold"><i class="bi bi-envelope"></i> راسلنا</a>
        </div>
    </section>

    <footer class="site-footer">
        <div class="container">
            <div class="footer-grid">
                <div>
                    <h6>فاتورايز</h6>
                    <p style="font-size:.86rem;line-height:1.8;max-width:280px">
                        نظام ERP عربي مصمم خصيصاً لمصانع ومحلات الألبسة — من تطوير شركة كايلنك.
                    </p>
                </div>
                <div>
                    <h6>الشركة</h6>
                    <a href="about.php">من نحن</a>
                    <a href="about.php#audience">لمين هالنظام</a>
                    <a href="about.php#pilot">قصتنا مع أول شريك</a>
                </div>
                <div>
                    <h6>تواصل</h6>
                    <a href="mailto:info@fatorize.com">info@fatorize.com</a>
                    <a href="find-my-company.php">تسجيل الدخول</a>
                </div>
            </div>
            <div class="footer-bottom">
                <span>© <?= date('Y') ?> فاتورايز — تطوير شركة كايلنك</span>
                <span>صُنع بعناية لصناعة الألبسة العربية</span>
            </div>
        </div>
    </footer>

</body>

</html>