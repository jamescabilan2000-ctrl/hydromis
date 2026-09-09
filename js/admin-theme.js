(function () {
    'use strict';
    var key = 'hydromis-admin-color-mode';
    function normalize(mode) { return mode === 'light' ? 'light' : 'dark'; }
    window.applyAdminColorMode = function (mode, persist) {
        mode = normalize(mode);
        document.documentElement.setAttribute('data-admin-color-mode', mode);
        if (document.body) document.body.setAttribute('data-color-mode', mode);
        if (persist !== false) localStorage.setItem(key, mode);
        window.dispatchEvent(new CustomEvent('admin-theme-change', { detail:{ mode:mode } }));
        return mode;
    };
    var saved = normalize(localStorage.getItem(key));
    document.documentElement.setAttribute('data-admin-color-mode', saved);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { 
            window.applyAdminColorMode(saved, false); 
            if (!document.querySelector('script[src*="staff-pending-notifications.js"]')) {
                var s = document.createElement('script');
                s.src = '../js/staff-pending-notifications.js';
                s.defer = true;
                document.head.appendChild(s);
            }
        }, { once:true });
    } else {
        window.applyAdminColorMode(saved, false);
        if (!document.querySelector('script[src*="staff-pending-notifications.js"]')) {
            var s = document.createElement('script');
            s.src = '../js/staff-pending-notifications.js';
            s.defer = true;
            document.head.appendChild(s);
        }
    }
})();
