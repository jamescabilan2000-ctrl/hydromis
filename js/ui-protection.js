(function () {
    'use strict';

    function showNotice(message) {
        var notice = document.getElementById('hydromis-protection-notice');
        if (!notice) {
            notice = document.createElement('div');
            notice.id = 'hydromis-protection-notice';
            notice.setAttribute('role', 'status');
            notice.setAttribute('aria-live', 'polite');
            Object.assign(notice.style, {
                position: 'fixed', left: '50%', bottom: '24px', zIndex: '2147483647',
                transform: 'translate(-50%, 18px)', opacity: '0', pointerEvents: 'none',
                padding: '10px 15px', border: '1px solid rgba(125,211,252,.35)',
                borderRadius: '10px', background: 'rgba(10,24,48,.94)', color: '#e8f4ff',
                boxShadow: '0 12px 32px rgba(0,0,0,.3)', font: '600 12px system-ui,sans-serif',
                transition: 'opacity .2s ease, transform .2s ease'
            });
            document.body.appendChild(notice);
        }
        notice.textContent = message;
        notice.style.opacity = '1';
        notice.style.transform = 'translate(-50%, 0)';
        clearTimeout(notice._hideTimer);
        notice._hideTimer = setTimeout(function () {
            notice.style.opacity = '0';
            notice.style.transform = 'translate(-50%, 18px)';
        }, 1800);
    }

    document.addEventListener('contextmenu', function (event) {
        event.preventDefault();
        showNotice('Right-click is disabled on this system.');
    });

    document.addEventListener('keydown', function (event) {
        var key = String(event.key || '').toLowerCase();
        var blocked = key === 'f12' ||
            (event.ctrlKey && event.shiftKey && ['i', 'j', 'c'].includes(key)) ||
            (event.ctrlKey && key === 'u');

        if (blocked) {
            event.preventDefault();
            event.stopImmediatePropagation();
            showNotice('Developer shortcuts are disabled on this system.');
        }
    }, true);
}());
