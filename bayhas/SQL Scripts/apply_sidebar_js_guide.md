# تعليمات: تفعيل ملف `assets/js/sidebar.js` المشترك بأي صفحة

> انسخ هالملف كامل لأي محادثة قسم، مع الصفحة (أو الصفحات) يلي بدك
> تطبّق عليها. هاد وصف كافي لوحده لفهم المطلوب بدون أي سياق إضافي.

## الخلفية (سطرين بس)

كل صفحة موديول بالمشروع كانت تكرر نفس ٣ دوال JS (فتح/إغلاق الشريط
الجانبي بالموبايل + طي/فرد الأقسام) يدوياً بآخرها. هالتكرار سبب بقين
حقيقيين (دالة مفقودة بصفحة، وتراكم حالة قديمة بـ`localStorage`). الحل:
ملف واحد مشترك `assets/js/sidebar.js`، وكل صفحة تستدعيه بسطر وحيد.

## المطلوب بالضبط لأي صفحة PHP

### ١. تأكد الصفحة تستدعي `sidebar.php` أصلاً
لازم يكون موجود بمكان ما بالملف:
```php
require_once __DIR__ . '/../../../includes/sidebar.php';
```
(لو مش موجود، هالصفحة أصلاً ما فيها شريط جانبي، تخطّاها).

### ٢. دوّر عن هالنمط بآخر الملف (عادةً جوا `<script>` قبل `</script>` و`</body>`)

علامات مميزة تأكّدك إنك بالمكان الصح — دوّر عن **أي وحدة** من هالأسطر:
```js
function toggleGroup(group) {
```
أو:
```js
function sbOpen()
```
أو:
```js
localStorage.getItem('sb_open_'
```

### ٣. احذف الكتلة كاملة من أول تعريف لـ`sidebar`/`overlay` لحد آخر سطر `localStorage`

الشكل الكامل يلي لازم يتحذف (ممكن يختلف شوي بالتفاصيل من صفحة لصفحة،
بس نفس الفكرة والدوال):
```js
const sidebar  = document.getElementById('sidebar');
const overlay  = document.getElementById('sbOverlay');
function sbOpen()  { sidebar.classList.add('open');  overlay.classList.add('show'); }
function sbClose() { sidebar.classList.remove('open');overlay.classList.remove('show'); }
window.addEventListener('resize', () => { if(window.innerWidth>991) sbClose(); });

function toggleGroup(group) {
    const isOpen = group.classList.contains('open');
    document.querySelectorAll('.sb-group.open').forEach(g => {
        if (g !== group) g.classList.remove('open');
    });
    group.classList.toggle('open', !isOpen);
    localStorage.setItem('sb_open_' + group.dataset.key, (!isOpen).toString());
}

document.querySelectorAll('.sb-group').forEach(g => {
    const saved = localStorage.getItem('sb_open_' + g.dataset.key);
    if (saved === 'true') g.classList.add('open');
});
```

### ٤. حطّ بدالها سطر وحيد، بنفس المكان تماماً

```php
<script src="<?= BASE_PATH ?>/assets/js/sidebar.js"></script>
```

### ٥. ⚠️ قاعدة أساسية: لا تحذف غير هالكتلة بس

لو الصفحة فيها كود JS تاني (دوال حفظ، مودالات، AJAX خاص بالصفحة نفسها)
**خليه زي ما هو بمكانه** — احذف بس الجزء المطابق فوق بالضبط، مش كل
محتوى `<script>`.

### ٦. اختبار سريع بعد التعديل
- زر القائمة ☰ (موبايل) بيفتح/يسكّر الشريط
- الضغط على عنوان قسم بيفتحه ويسكّر الباقي
- افتح صفحة تانية — لازم يفتح بس آخر قسم ضغطت عليه، مش كل الأقسام مع بعض

---

**الملف الجاهز نفسه (`assets/js/sidebar.js`) مرفوع أصلاً على السيرفر
بمسار `assets/js/sidebar.js`** — هالتعليمات بس لتعديل كل صفحة تدعوه.
