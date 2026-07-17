<?php
/**
 * index.php — الصفحة الرئيسية (Landing Page) لمنصة فاتورايز
 * المسار: _bayhas/index.php (جذر المشروع)
 *
 * ⚠ عمداً هذا الملف لا يستدعي config/database.php ولا config/tenant_resolver.php
 * إطلاقاً — لأنها صفحة عامة تسويقية، مش خاصة بأي شركة (tenant) محددة.
 * لو استدعينا نظام تحديد الـtenant هنا، أي زائر يفتح الدومين الرئيسي
 * بدون ساب دومين كان رح يشوف رسالة خطأ "الشركة غير موجودة" بدل صفحة
 * ترحيبية طبيعية — بالضبط المشكلة يلي كانت موجودة قبل هالملف.
 */
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>فاتورايز — نظام ERP لمصانع ومحلات الألبسة</title>
<link rel="icon" type="image/png" href="assets/images/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root { --brand: #1e3a8a; --brand-light: #3b82f6; --brand-hover: #1d4ed8; }
* { box-sizing: border-box; }
body { font-family: 'Cairo', sans-serif; color: #1e293b; }

/* Nav */
.navbar-custom {
    background: rgba(255,255,255,.95); backdrop-filter: blur(10px);
    border-bottom: 1px solid #e2e8f0; padding: .9rem 0;
}
.navbar-custom .brand { font-weight: 800; font-size: 1.3rem; color: var(--brand); }
.navbar-custom .brand img { height: 34px; margin-left: 8px; }
.btn-login-nav {
    background: var(--brand); color: #fff; border-radius: 10px;
    padding: .55rem 1.4rem; font-weight: 600; font-size: .9rem;
    text-decoration: none; transition: all .2s;
}
.btn-login-nav:hover { background: var(--brand-hover); color: #fff; transform: translateY(-1px); }

/* Hero */
.hero {
    background: linear-gradient(135deg, #eff6ff 0%, #f8fafc 100%);
    padding: 5rem 1rem 6rem; text-align: center;
}
.hero h1 { font-size: 2.4rem; font-weight: 800; color: var(--brand); margin-bottom: 1rem; line-height: 1.4; }
.hero p { font-size: 1.1rem; color: #64748b; max-width: 640px; margin: 0 auto 2rem; line-height: 1.9; }
.hero-cta { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; }
.btn-hero-primary {
    background: linear-gradient(135deg, var(--brand-light), var(--brand));
    color: #fff; border-radius: 12px; padding: .9rem 2.2rem; font-weight: 700;
    text-decoration: none; font-size: 1rem; box-shadow: 0 8px 24px rgba(30,58,138,.2);
    transition: all .25s;
}
.btn-hero-primary:hover { color: #fff; transform: translateY(-2px); box-shadow: 0 12px 32px rgba(30,58,138,.3); }
.btn-hero-secondary {
    background: #fff; color: var(--brand); border: 1.5px solid #dbeafe;
    border-radius: 12px; padding: .9rem 2.2rem; font-weight: 700;
    text-decoration: none; font-size: 1rem; transition: all .25s;
}
.btn-hero-secondary:hover { color: var(--brand); border-color: var(--brand-light); background: #eff6ff; }

/* Features */
.features { padding: 5rem 1rem; background: #fff; }
.features h2 { text-align: center; font-weight: 800; color: var(--brand); font-size: 1.8rem; margin-bottom: 3rem; }
.feature-card {
    background: #f8fafc; border-radius: 16px; padding: 1.8rem; height: 100%;
    border: 1px solid #f1f5f9; transition: all .25s;
}
.feature-card:hover { transform: translateY(-4px); box-shadow: 0 12px 28px rgba(0,0,0,.06); }
.feature-icon {
    width: 52px; height: 52px; border-radius: 14px; background: #eff6ff; color: var(--brand);
    display: flex; align-items: center; justify-content: center; font-size: 1.4rem; margin-bottom: 1rem;
}
.feature-card h5 { font-weight: 700; margin-bottom: .5rem; }
.feature-card p { color: #64748b; font-size: .9rem; line-height: 1.7; margin: 0; }

/* Dashboards split */
.split-section { padding: 5rem 1rem; background: linear-gradient(135deg, #f8fafc, #eff6ff); }
.split-card {
    background: #fff; border-radius: 20px; padding: 2.2rem; height: 100%;
    box-shadow: 0 4px 20px rgba(0,0,0,.05);
}
.split-card .badge-type {
    display: inline-block; padding: .35rem 1rem; border-radius: 20px; font-size: .78rem;
    font-weight: 700; margin-bottom: 1rem;
}
.badge-factory { background: #ecfdf5; color: #059669; }
.badge-shop { background: #fef3c7; color: #d97706; }

footer { background: #0f172a; color: #94a3b8; padding: 2.5rem 1rem; text-align: center; font-size: .85rem; }
footer a { color: #cbd5e1; text-decoration: none; }
</style>
</head>
<body>

<nav class="navbar-custom">
    <div class="container d-flex justify-content-between align-items-center">
        <div class="brand d-flex align-items-center">
            <img src="assets/images/fatorize.png" alt="Fatorize" onerror="this.style.display='none'">
            فاتورايز
        </div>
        <a href="find-my-company.php" class="btn-login-nav">
            <i class="bi bi-box-arrow-in-right me-1"></i>تسجيل الدخول
        </a>
    </div>
</nav>

<section class="hero">
    <div class="container">
        <h1>نظام ERP متكامل لمصانع ومحلات الألبسة</h1>
        <p>
            فاتورايز نظام محاسبي وإداري شامل يربط المصنع بفرع المبيعات — مبيعات، مشتريات،
            مخزون، محاسبة، رواتب، وصلاحيات دقيقة لكل مستخدم. بديل أبسط استخداماً
            من أنظمة عالمية معقدة، مصمم خصيصاً للسوق العربي.
        </p>
        <div class="hero-cta">
            <a href="find-my-company.php" class="btn-hero-primary">
                <i class="bi bi-box-arrow-in-right me-1"></i>دخول حسابي
            </a>
            <a href="mailto:info@fatorize.com" class="btn-hero-secondary">
                <i class="bi bi-chat-dots me-1"></i>اطلب عرض تجريبي
            </a>
        </div>
    </div>
</section>

<section class="features">
    <div class="container">
        <h2>كل شي بمكان واحد</h2>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon"><i class="bi bi-bag"></i></div>
                    <h5>المبيعات والمشتريات</h5>
                    <p>فواتير كاملة بالمقاسات والألوان والباركود، مع تأكيد يعكس المخزون والقيود المحاسبية معاً تلقائياً.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon"><i class="bi bi-box-seam"></i></div>
                    <h5>المخزون متعدد المستودعات</h5>
                    <p>تتبّع دقيق للكميات بكل مستودع، وطلبات داخلية مباشرة بين فرع المصنع وفروع المبيعات.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon"><i class="bi bi-bank"></i></div>
                    <h5>محاسبة حقيقية</h5>
                    <p>دليل حسابات، قيود يومية، سندات قبض، عملات متعددة — كل عملية مالية تنعكس بقيد متوازن تلقائياً.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon"><i class="bi bi-people"></i></div>
                    <h5>الموارد البشرية والرواتب</h5>
                    <p>حضور، رواتب، سلف، مكافآت — لكل موظف جدول دوام أسبوعي خاص فيه.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon"><i class="bi bi-shield-check"></i></div>
                    <h5>صلاحيات دقيقة</h5>
                    <p>لكل مستخدم صلاحيات منفصلة لكل قسم وفرع — عرض، إضافة، تعديل، حذف، تأكيد، طباعة.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon"><i class="bi bi-upc-scan"></i></div>
                    <h5>باركود جاهز للطباعة</h5>
                    <p>توليد باركود خطي فريد لكل قطعة، وطباعة ملصقات جاهزة للمستودع مباشرة من النظام.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="split-section">
    <div class="container">
        <h2 class="text-center fw-bold mb-5" style="color:var(--brand)">لوحتا تحكم، لكل نوع نشاط</h2>
        <div class="row g-4">
            <div class="col-md-6">
                <div class="split-card">
                    <span class="badge-type badge-factory"><i class="bi bi-gear-wide-connected me-1"></i>فرع التصنيع</span>
                    <h4 class="fw-bold mb-3">لأصحاب المصانع</h4>
                    <p class="text-muted">خطوط الإنتاج، المواد الأولية، تكاليف التصنيع، والربط المباشر مع فروع البيع لتلبية طلباتها الداخلية.</p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="split-card">
                    <span class="badge-type badge-shop"><i class="bi bi-shop me-1"></i>فرع المبيعات</span>
                    <h4 class="fw-bold mb-3">لأصحاب المحلات</h4>
                    <p class="text-muted">فواتير بيع سريعة، متابعة عملاء، وطلب تجديد المخزون مباشرة من المصنع بضغطة واحدة.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<footer>
    <p class="mb-1">© <?= date('Y') ?> فاتورايز — نظام ERP لمصانع ومحلات الألبسة</p>
    <a href="mailto:info@fatorize.com">تواصل معنا</a>
</footer>

</body>
</html>
