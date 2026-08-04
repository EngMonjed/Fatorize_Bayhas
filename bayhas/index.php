<?php
/**
 * index.php — الصفحة الرئيسية (Landing Page) لمنصة فاتورايز
 * المسار: fatorize_erp_system/index.php (جذر المشروع)
 * ⚠ لا تتصل بأي قاعدة بيانات عمداً — صفحة عامة، مش خاصة بأي tenant.
 */
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>فاتورايز — نظام ERP لمصانع ومحلات الألبسة</title>
<meta name="description" content="نظام محاسبي وإداري متكامل يربط مصنعك بفروع البيع — مبيعات، مشتريات، مخزون، محاسبة، رواتب. بسيط الاستخدام، مصمم لصناعة الألبسة بالمنطقة العربية.">
<link rel="icon" type="image/png" href="assets/images/logo.png">
<link href="https://fonts.googleapis.com/css2?family=Changa:wght@500;600;700;800&family=Cairo:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
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
            <a href="about.php">من نحن</a>
            <a href="about.php#audience">لمين هالنظام</a>
            <a href="about.php#pilot">قصتنا مع أول شريك</a>
            <a href="find-my-company.php" class="btn-nav-login">تسجيل الدخول</a>
        </div>
        <button class="nav-toggle" id="navToggle" aria-label="القائمة" aria-expanded="false"><i class="bi bi-list"></i></button>
    </div>
</nav>
<script>
document.getElementById('navToggle').addEventListener('click', function(){
    var links = document.querySelector('.nav-links');
    var open = links.classList.toggle('open');
    this.setAttribute('aria-expanded', open ? 'true' : 'false');
    this.querySelector('i').className = open ? 'bi bi-x-lg' : 'bi bi-list';
});
</script>

<section class="hero">
    <div class="container hero-grid">
        <div>
            <span class="eyebrow"><i class="bi bi-scissors"></i> صُمم لصناعة الألبسة تحديداً</span>
            <h1>نظام واحد يربط <em>المصنع</em> بمحلّك،<br>من القماش لحد الفاتورة</h1>
            <p class="lede">
                فاتورايز نظام محاسبي وإداري متكامل — مبيعات، مشتريات، مخزون، محاسبة حقيقية،
                ورواتب. بديل أبسط بكتير من الأنظمة العالمية المعقّدة، وبالعربي بالكامل.
            </p>
            <div class="hero-cta">
                <a href="find-my-company.php" class="btn-gold"><i class="bi bi-box-arrow-in-right"></i> دخول حسابي</a>
                <a href="about.php" class="btn-outline">تعرّف علينا أكتر</a>
            </div>
            <div class="stat-row">
                <div class="stat"><b>٦</b><span>وحدات متكاملة بمكان واحد</span></div>
                <div class="stat"><b>٢</b><span>لوحتا تحكم: مصنع ومبيعات</span></div>
                <div class="stat"><b>١٠٠٪</b><span>عربي، من التصميم للدعم</span></div>
            </div>
        </div>
        <div class="hero-visual">
            <div class="tag-card tag-2" aria-hidden="true">
                <div class="tag-code">MODEL #5024</div>
                <div class="tag-barcode">
                    <span style="height:16px"></span><span style="height:22px"></span><span style="height:12px"></span>
                    <span style="height:20px"></span><span style="height:16px"></span><span style="height:24px"></span>
                    <span style="height:14px"></span><span style="height:20px"></span>
                </div>
            </div>
            <div class="tag-card">
                <div class="tag-hole-string" aria-hidden="true"></div>
                <div class="tag-code">FTR-2026-00184</div>
                <h4 style="font-size:1.05rem;margin:.5rem 0">بنطلون بوي فريند</h4>
                <p style="color:var(--muted);font-size:.82rem;margin:0 0 .8rem">مقاس L · لون كحلي</p>
                <div class="tag-barcode">
                    <span style="height:20px"></span><span style="height:26px"></span><span style="height:14px"></span>
                    <span style="height:22px"></span><span style="height:18px"></span><span style="height:26px"></span>
                    <span style="height:12px"></span><span style="height:22px"></span><span style="height:16px"></span>
                    <span style="height:24px"></span>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section" id="features">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><i class="bi bi-grid"></i> كل شي بمكان واحد</span>
            <h2>لا داعي لخمس برامج منفصلة</h2>
            <p>كل قسم بشركتك — من خط الإنتاج لحد سند القبض — بنظام واحد مترابط.</p>
        </div>
        <div class="feature-grid">
            <div class="tag-card feature-tag">
                <div class="ficon"><i class="bi bi-bag"></i></div>
                <h5>المبيعات والمشتريات</h5>
                <p>فواتير بالمقاسات والألوان والباركود، تأكيد واحد يعكس المخزون والقيود المحاسبية معاً.</p>
            </div>
            <div class="tag-card feature-tag">
                <div class="ficon"><i class="bi bi-box-seam"></i></div>
                <h5>مخزون متعدد المستودعات</h5>
                <p>كل مستودع بأرصدته الحقيقية، وطلب تجديد مباشر من المصنع بضغطة وحدة.</p>
            </div>
            <div class="tag-card feature-tag">
                <div class="ficon"><i class="bi bi-bank"></i></div>
                <h5>محاسبة حقيقية</h5>
                <p>دليل حسابات، قيود يومية متوازنة تلقائياً، عملات متعددة، سندات قبض.</p>
            </div>
            <div class="tag-card feature-tag">
                <div class="ficon"><i class="bi bi-people"></i></div>
                <h5>الموظفون والرواتب</h5>
                <p>حضور، رواتب، سلف، مكافآت — لكل موظف جدول دوامه الأسبوعي الخاص فيه.</p>
            </div>
            <div class="tag-card feature-tag">
                <div class="ficon"><i class="bi bi-shield-check"></i></div>
                <h5>صلاحيات دقيقة</h5>
                <p>لكل مستخدم صلاحياته بكل قسم وفرع — عرض، إضافة، تعديل، تأكيد، طباعة.</p>
            </div>
            <div class="tag-card feature-tag">
                <div class="ficon"><i class="bi bi-upc-scan"></i></div>
                <h5>باركود جاهز للطباعة</h5>
                <p>باركود خطي فريد لكل قطعة، وملصقات جاهزة للطباعة مباشرة من النظام.</p>
            </div>
        </div>
    </div>
</section>

<section class="split-section">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><i class="bi bi-diagram-3"></i> لوحتان، مو لوحة واحدة</span>
            <h2>كل نشاط إله لوحته المناسبة</h2>
            <p>مصنعك مو زي محلك — فاتورايز بيفرّق بينهم من أول يوم.</p>
        </div>
        <div class="split-grid">
            <div class="split-card">
                <span class="eyebrow"><i class="bi bi-gear-wide-connected"></i> فرع التصنيع</span>
                <h3>لأصحاب المصانع</h3>
                <p>كل شي مرتبط بخط الإنتاج والمواد الأولية وتكلفة كل قطعة فعلياً.</p>
                <ul>
                    <li>خطوط الإنتاج وتكاليفها الحقيقية</li>
                    <li>المواد الأولية ومخزونها</li>
                    <li>استلام طلبات فروع البيع والرد عليها مباشرة</li>
                </ul>
            </div>
            <div class="split-card">
                <span class="eyebrow"><i class="bi bi-shop"></i> فرع المبيعات</span>
                <h3>لأصحاب المحلات</h3>
                <p>واجهة أسرع وأبسط، مركّزة على البيع اليومي ومتابعة الزبائن.</p>
                <ul>
                    <li>فواتير بيع سريعة بالباركود</li>
                    <li>متابعة العملاء وكشف حساباتهم</li>
                    <li>طلب تجديد مخزون من المصنع مباشرة</li>
                </ul>
            </div>
        </div>
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
