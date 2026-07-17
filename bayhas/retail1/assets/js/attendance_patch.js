// ================================================================
// attendance_patch.js
// ضعه في: /bayhas/aleppo/assets/js/attendance_patch.js
// وأضف في attendance.php قبل </body>:
// <script src="../../assets/js/attendance_patch.js"></script>
// ================================================================

// ── تحويل YYYY-MM-DD لاسم اليوم (توقيت محلي) ──
function getDayName(dateStr) {
    if (!dateStr) return '';
    const [y, m, d] = dateStr.split('-').map(Number);
    return new Date(y, m - 1, d)
        .toLocaleDateString('en-US', { weekday: 'long' })
        .toLowerCase();
}

// ── فحص العطلة وتعديل المودال ──
function applyDateType(dateStr) {
    if (!dateStr || !currentEmployee?.schedule) return;

    const dayName = getDayName(dateStr);
    const cfg     = currentEmployee.schedule[dayName];
    const isOff   = cfg ? cfg.on === false : false;

    const dateInput = document.getElementById('attDate');
    const btnUnpaid = document.getElementById('btnUnpaid');
    const btnPaid   = document.getElementById('btnPaid');
    const btnNone   = document.getElementById('btnNone');
    const btnOT     = document.getElementById('btnOvertime');

    if (isOff) {
        // يوم عطلة
        dateInput.style.borderColor     = '#ef4444';
        dateInput.style.backgroundColor = '#fff5f5';

        if (btnUnpaid) btnUnpaid.style.display = 'none';
        if (btnPaid)   btnPaid.style.display   = 'none';
        if (btnOT)     btnOT.style.display      = '';

        const otRadio = document.querySelector('input[name="leaveType"][value="overtime"]');
        if (otRadio) { otRadio.checked = true; updateLeaveUI('overtime'); }

    } else {
        // يوم دوام عادي
        dateInput.style.borderColor     = '';
        dateInput.style.backgroundColor = '';

        if (btnUnpaid) btnUnpaid.style.display = '';
        if (btnPaid)   btnPaid.style.display   = '';
        if (btnOT)     btnOT.style.display      = 'none';

        const checked = document.querySelector('input[name="leaveType"]:checked')?.value;
        if (checked === 'overtime') {
            const noneRadio = document.querySelector('input[name="leaveType"][value=""]');
            if (noneRadio) { noneRadio.checked = true; updateLeaveUI(''); }
        }
    }
}

// ── override onAttDateChange ──
function onAttDateChange() {
    const dateStr = document.getElementById('attDate').value;

    // setDefaultTimes
    if (currentEmployee?.schedule && dateStr) {
        const day = getDayName(dateStr);
        const cfg = currentEmployee.schedule[day];
        if (cfg) {
            const pad = n => String(Math.floor(n)).padStart(2, '0');
            document.getElementById('attCheckIn').value  = pad(cfg.from ?? 8)  + ':00';
            document.getElementById('attCheckOut').value = pad(cfg.to   ?? 18) + ':00';
        }
    }

    applyDateType(dateStr);
    if (typeof calcHours === 'function') calcHours();
}

// ── إضافة زر overtime إذا غير موجود ──
document.addEventListener('DOMContentLoaded', () => {
    const btnNone = document.getElementById('btnNone');
    if (btnNone && !document.getElementById('btnOvertime')) {
        const btnOT = document.createElement('label');
        btnOT.className  = 'leave-btn';
        btnOT.id         = 'btnOvertime';
        btnOT.style.display = 'none';
        btnOT.innerHTML  = `<input type="radio" name="leaveType" value="overtime"
                             onchange="onLeaveChange()">
                            <i class="bi bi-lightning-charge" style="color:#f59e0b"></i>
                            دوام إضافي`;
        btnNone.parentNode.insertBefore(btnOT, btnNone.nextSibling);
    }

    // override updateLeaveUI
    window.updateLeaveUI = function(val) {
        const sc = (id, active, cls) => {
            const el = document.getElementById(id);
            if (el) el.className = 'leave-btn' + (active ? ' ' + cls : '');
        };
        sc('btnUnpaid',   val==='unpaid',   'active-unpaid');
        sc('btnPaid',     val==='paid',     'active-paid');
        sc('btnNone',     val==='',         'active-none');
        sc('btnOvertime', val==='overtime', 'active-ot');

        const disabled = val === 'unpaid' || val === 'paid';
        ['attCheckIn','attCheckOut'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.disabled = disabled;
        });
        const tr = document.getElementById('attTimesRow');
        if (tr) tr.style.opacity = disabled ? '.4' : '1';
    };

    // patch openAttendanceModal لاستدعاء applyDateType بعد تعيين الموظف
    const origOpen = window.openAttendanceModal;
    window.openAttendanceModal = function(...args) {
        origOpen?.(...args);
        // بعد تعيين currentEmployee نفحص اليوم
        setTimeout(() => {
            const dateStr = document.getElementById('attDate')?.value;
            if (dateStr) applyDateType(dateStr);
        }, 50);
    };

    // CSS active-ot
    if (!document.querySelector('style[data-patch]')) {
        const style = document.createElement('style');
        style.dataset.patch = '1';
        style.textContent = `.leave-btn.active-ot{border-color:#f59e0b;color:#92400e;background:#fffbeb}`;
        document.head.appendChild(style);
    }
});
