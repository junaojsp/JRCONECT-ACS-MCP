/* JRC_CC_DEVICES_REFERENCE_V1 */
(function () {
    'use strict';
    function setup() {
        var side = document.getElementById('sidebar');
        if (!side) return;
        side.querySelectorAll('.m4g-sec a').forEach(function (link) {
            var label = (link.textContent || '').trim();
            if (label) { link.title = label; link.setAttribute('aria-label', label); }
        });
        var toggle = document.getElementById('sidebarToggle');
        function update() {
            if (!toggle) return;
            var pinned = side.classList.contains('cc-nav-pinned');
            toggle.setAttribute('aria-expanded', String(pinned));
            toggle.setAttribute('aria-label', pinned ? 'Recolher menu' : 'Fixar menu expandido');
            toggle.title = pinned ? 'Recolher menu' : 'Fixar menu expandido';
        }
        try {side.classList.toggle('cc-nav-pinned', localStorage.getItem('jrc-cc-nav-pinned') === '1');} catch (_) {}
        if (toggle) toggle.addEventListener('click', function (event) {
            if (!window.matchMedia('(min-width:769px)').matches) return;
            event.preventDefault();event.stopImmediatePropagation();
            side.classList.toggle('cc-nav-pinned');
            try {localStorage.setItem('jrc-cc-nav-pinned', side.classList.contains('cc-nav-pinned') ? '1' : '0');} catch (_) {}
            update();
        }, true);
        update();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setup);
    else setup();
})();
