(function () {
    'use strict';
    const meta = document.querySelector('meta[name="hydromis-user-id"]');
    const pageUserId = meta ? meta.content.trim() : '';
    if (pageUserId) localStorage.setItem('hydromisUserId', pageUserId);
    const userId = pageUserId || (localStorage.getItem('hydromisUserId') || '').trim();
    if (!userId) return;

    const scriptUrl = document.currentScript && document.currentScript.src;
    const endpoint = scriptUrl
        ? new URL('../api/user_notifications.php', scriptUrl).href
        : '../api/user_notifications.php';
    const shown = new Set(JSON.parse(sessionStorage.getItem('hydromisShownNotifications') || '[]'));
    const appUrl = new URL('../', scriptUrl || new URL('../js/user-notifications.js', location.href));
    let registrationPromise;
    function notificationRegistration() {
        if (!registrationPromise) {
            registrationPromise = navigator.serviceWorker.register(new URL('notification-worker.js', appUrl).href)
                .then(() => navigator.serviceWorker.ready)
                .catch(error => { registrationPromise = null; throw error; });
        }
        return registrationPromise;
    }

    async function systemNotification(item) {
        if (!window.isSecureContext || !('Notification' in window) ||
            Notification.permission !== 'granted' || !('serviceWorker' in navigator)) return;
        const registration = await notificationRegistration();
        const target = new URL('user/track_order.php', appUrl);
        target.searchParams.set('user_id', userId);
        if (item.transaction_id) target.searchParams.set('transaction_id', item.transaction_id);
        await registration.showNotification(item.title, {
            body: item.message,
            icon: new URL('imagess/logosystem.png', appUrl).href,
            tag: 'hydromis-' + userId + '-' + item.id,
            data: {url: target.href}
        });
    }

    function toast(item) {
        let container = document.getElementById('user-notification-live');
        if (!container) {
            container = document.createElement('div');
            container.id = 'user-notification-live';
            container.setAttribute('aria-live', 'polite');
            container.style.cssText = 'position:fixed;right:12px;top:max(12px,env(safe-area-inset-top));z-index:2147483647;width:min(370px,calc(100vw - 24px));display:grid;gap:10px;max-height:80dvh;overflow-y:auto;pointer-events:none';
            document.body.appendChild(container);
        }
        const card = document.createElement('div');
        card.style.cssText = 'position:relative;pointer-events:auto;overflow-wrap:anywhere;background:#111c30;color:#e8f1ff;border:1px solid rgba(255,255,255,.13);border-left:4px solid #f59e0b;border-radius:13px;padding:14px 44px 14px 16px;box-shadow:0 18px 40px rgba(0,0,0,.35);font:14px/1.45 system-ui';
        const title = document.createElement('strong'); title.textContent = item.title; title.style.display = 'block';
        const message = document.createElement('span'); message.textContent = item.message; message.style.cssText = 'display:block;color:#a9bad3;margin-top:4px';
        card.append(title, message); container.appendChild(card);
        const dismiss = document.createElement('button');
        dismiss.type = 'button'; dismiss.textContent = '\u00d7';
        dismiss.setAttribute('aria-label', 'Dismiss notification');
        dismiss.style.cssText = 'position:absolute;right:4px;top:4px;width:40px;height:40px;border:0;background:transparent;color:inherit;font-size:24px;cursor:pointer';
        dismiss.addEventListener('click', () => card.remove());
        card.appendChild(dismiss);
        if (card.animate && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            card.animate([{transform:'translateY(-24px)',opacity:0},{transform:'translateY(0)',opacity:1}], {duration:250,easing:'ease-out'});
        }
        setTimeout(() => card.remove(), 12000);
    }

    async function markRead(id) {
        const data = new URLSearchParams({user_id:userId,notification_id:String(id)});
        await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:data,credentials:'same-origin'});
    }

    async function poll() {
        try {
            const response = await fetch(endpoint+'?user_id='+encodeURIComponent(userId),{cache:'no-store',credentials:'same-origin'});
            const data = await response.json();
            if (!data.success) return;
            for (const item of data.notifications) {
                if (!shown.has(String(item.id))) {
                    shown.add(String(item.id)); toast(item);
                    try { await systemNotification(item); } catch (error) { console.warn('Unable to display phone notification.', error); }
                }
                await markRead(item.id);
            }
            sessionStorage.setItem('hydromisShownNotifications',JSON.stringify(Array.from(shown).slice(-100)));
        } catch (_) { /* Retry on the next poll when the connection returns. */ }
    }

    if (window.isSecureContext && 'serviceWorker' in navigator && 'Notification' in window && Notification.permission === 'default') {
        const button=document.createElement('button');button.type='button';button.className='notification-permission-button';button.textContent='Enable account notifications';
        button.style.cssText='position:fixed;right:18px;bottom:18px;z-index:99998;border:0;border-radius:999px;padding:11px 16px;background:#2563eb;color:white;font-weight:700;box-shadow:0 10px 28px rgba(0,0,0,.28);cursor:pointer';
        button.onclick = async () => {
            try {
                const permission = await Notification.requestPermission();
                if (permission === 'granted') {
                    await systemNotification({id:'enabled',title:'HydroMIS notifications enabled',message:'Customer alerts will appear here while HydroMIS is open.'});
                    button.remove();
                } else if (permission === 'denied') {
                    button.remove();
                }
                poll();
            } catch (error) {
                button.textContent = 'Retry enabling notifications';
                console.warn('Unable to enable phone notifications.', error);
            }
        };
        document.body.appendChild(button);
    }
    poll(); setInterval(poll,15000);
})();
