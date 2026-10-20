/* =============================================================
   LANTAW ANALYTICS – shared front-end behaviour
   Vanilla JS only. Vendor scripts (Bootstrap, Chart.js, Lucide)
   are loaded with `defer`, so everything here runs on
   DOMContentLoaded — page scripts should do the same.
   ============================================================= */

document.addEventListener('DOMContentLoaded', function () {
    /* Icons */
    if (window.lucide) {
        lucide.createIcons();
    }

    /* Sidebar (mobile) */
    var sidebar = document.getElementById('appSidebar');
    var overlay = document.getElementById('sidebarOverlay');
    var toggle = document.getElementById('sidebarToggle');

    function closeSidebar() {
        if (!sidebar) return;
        sidebar.classList.remove('open');
        if (overlay) overlay.hidden = true;
    }
    if (toggle && sidebar) {
        toggle.addEventListener('click', function () {
            var open = sidebar.classList.toggle('open');
            if (overlay) overlay.hidden = !open;
        });
    }
    if (overlay) overlay.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeSidebar();
    });

    /* Auto-dismiss alerts after 6s */
    document.querySelectorAll('.alert-dismissible').forEach(function (el) {
        setTimeout(function () {
            try {
                var alert = bootstrap.Alert.getOrCreateInstance(el);
                alert.close();
            } catch (err) { /* bootstrap not ready */ }
        }, 6000);
    });

    /* Confirm-on-submit helper: data-confirm="message" */
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        });
    });

    /* Active chart default palette */
    if (window.Chart) {
        Chart.defaults.font.family = '"Segoe UI", -apple-system, Arial, sans-serif';
        Chart.defaults.font.size = 12;
        Chart.defaults.color = '#404040';
        Chart.defaults.borderColor = '#E5DCC4';
    }

    /* Mark external export links with a brief loading state */
    document.querySelectorAll('a[data-loading]').forEach(function (a) {
        a.addEventListener('click', function () {
            var label = a.innerHTML;
            a.dataset.prevHtml = label;
            a.innerHTML = '<span class="spinner"></span>Working…';
            setTimeout(function () { a.innerHTML = label; }, 4000);
        });
    });
});

/* ---------- small helpers used by page scripts ---------- */

/**
 * Standard money/percent formatters for Chart.js tooltips and labels.
 */
var FormatMoney = function (value) {
    return '\u20B1' + Number(value).toLocaleString('en-PH', {
        minimumFractionDigits: 0, maximumFractionDigits: 2
    });
};

var FormatPeso = function (value) {
    return '\u20B1' + Number(value).toLocaleString('en-PH', {
        minimumFractionDigits: 2, maximumFractionDigits: 2
    });
};

/**
 * Build a Chart.js bar/line dataset with the standard blue palette.
 */
var BluePalette = ['#244A0C', '#91D523', '#54260D', '#8FBF3F', '#27833B', '#D98C0A', '#DC3545', '#8B4513'];

/**
 * Simple fetch wrapper that always sends the CSRF token for POSTs.
 */
async function postJSON(url, data) {
    var body = new URLSearchParams(data || {});
    body.set('_csrf', window.CSRF_TOKEN || '');
    var res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
        body: body.toString(),
        credentials: 'same-origin'
    });
    if (!res.ok) throw new Error('Request failed: ' + res.status);
    return res.json();
}
