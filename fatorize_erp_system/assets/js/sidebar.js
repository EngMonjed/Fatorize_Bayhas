/**
 * assets/js/sidebar.js
 * منطق الشريط الجانبي المشترك (فتح/إغلاق موبايل + طي/فرد الأقسام) —
 * ملف واحد بدل نسخ مكررة بكل صفحة موديول.
 *
 * ✅ إصلاح إضافي (نفس اليوم): الكود القديم كان بيدّي الأولوية لآخر قيمة
 * محفوظة بـlocalStorage (من تصفح سابق لقسم تاني، زي المخزون أو المشتريات)
 * على حساب القسم الصحيح الفعلي يلي الـPHP حدده مسبقاً بشكل موثوق
 * (isGroupActive() بملف sidebar.php، بناءً على الصفحة الحالية الحقيقية).
 * النتيجة: الضغط على أي تبويب بقسم معيّن كان يفتح قسم قديم غلط بدل
 * القسم الصحيح. الإصلاح: لو الـPHP أصلاً حدد قسم كـ"مفتوح" بالصفحة،
 * هالتحديد يصير المرجع الوحيد الموثوق — أي قيمة localStorage قديمة
 * لقسم تاني تُصفّر فوراً، مش تُستخدم.
 */

const sidebar = document.getElementById('sidebar');
const sbOverlay = document.getElementById('sbOverlay');

function sbOpen()  { sidebar.classList.add('open');  sbOverlay.classList.add('show'); }
function sbClose() { sidebar.classList.remove('open'); sbOverlay.classList.remove('show'); }
window.addEventListener('resize', () => { if (window.innerWidth > 991) sbClose(); });

function toggleGroup(group) {
    const isOpen = group.classList.contains('open');

    document.querySelectorAll('.sb-group.open').forEach(g => {
        if (g !== group) {
            g.classList.remove('open');
            localStorage.setItem('sb_open_' + g.dataset.key, 'false');
        }
    });

    group.classList.toggle('open', !isOpen);
    localStorage.setItem('sb_open_' + group.dataset.key, (!isOpen).toString());
}

// ⚠ القسم يلي الـPHP حدده مسبقاً كـ"مفتوح" (بناءً على الصفحة الحقيقية
// الحالية) هو المرجع الوحيد الموثوق — لازم يفوز دايماً على أي قيمة
// localStorage قديمة، مش يتنافس معها. لو موجود، نصفّر كل قسم تاني
// ونحدّث الـlocalStorage ليطابق الواقع الصحيح، بدون ما نلمس القسم
// الصحيح إطلاقاً.
const serverActiveGroup = document.querySelector('.sb-group.open');

if (serverActiveGroup) {
    document.querySelectorAll('.sb-group').forEach(g => {
        const isActive = g === serverActiveGroup;
        localStorage.setItem('sb_open_' + g.dataset.key, isActive.toString());
        if (!isActive) g.classList.remove('open');
    });
} else {
    // ⚠ حالة نادرة (صفحة بلا قسم نشط محدد من الـPHP، مثل الداشبورد) —
    // نرجع لآخر قسم فتحه المستخدم يدوياً من localStorage، بس أول واحد
    // فقط (نفس منطق الإصلاح الذاتي القديم، محفوظ كـfallback بس)
    let firstOpenFound = false;
    document.querySelectorAll('.sb-group').forEach(g => {
        const saved = localStorage.getItem('sb_open_' + g.dataset.key);
        if (saved === 'true') {
            if (!firstOpenFound) {
                g.classList.add('open');
                firstOpenFound = true;
            } else {
                g.classList.remove('open');
                localStorage.setItem('sb_open_' + g.dataset.key, 'false');
            }
        }
    });
}
