(function () {
    'use strict';

    // Prevent duplicate initialization
    if (window.HydroMISPendingNotifierLoaded) return;
    window.HydroMISPendingNotifierLoaded = true;

    // Detect endpoint path based on script location or current page location
    const scriptUrl = document.currentScript && document.currentScript.src;
    const endpoint = scriptUrl
        ? new URL('../api/check_pending_orders.php', scriptUrl).href
        : '../api/check_pending_orders.php';

    // Storage for seen pending order IDs
    const SEEN_KEY = 'hydromis_seen_pending_ids';
    function getSeenIds() {
        try {
            return new Set(JSON.parse(sessionStorage.getItem(SEEN_KEY) || '[]'));
        } catch (_) {
            return new Set();
        }
    }
    function saveSeenIds(set) {
        try {
            const arr = Array.from(set).slice(-100);
            sessionStorage.setItem(SEEN_KEY, JSON.stringify(arr));
        } catch (_) {}
    }

    let seenIds = getSeenIds();
    let isFirstPoll = seenIds.size === 0;

    // Web Audio API Sound Chime synthesizer
    function playNotificationSound() {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            const ctx = new AudioCtx();
            if (ctx.state === 'suspended') {
                ctx.resume();
            }

            const now = ctx.currentTime;
            
            // Note 1 (D5 - 587.33 Hz)
            const osc1 = ctx.createOscillator();
            const gain1 = ctx.createGain();
            osc1.type = 'sine';
            osc1.frequency.setValueAtTime(587.33, now);
            gain1.gain.setValueAtTime(0, now);
            gain1.gain.linearRampToValueAtTime(0.3, now + 0.03);
            gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.25);
            osc1.connect(gain1);
            gain1.connect(ctx.destination);
            osc1.start(now);
            osc1.stop(now + 0.25);

            // Note 2 (A5 - 880 Hz)
            const osc2 = ctx.createOscillator();
            const gain2 = ctx.createGain();
            osc2.type = 'sine';
            osc2.frequency.setValueAtTime(880, now + 0.15);
            gain2.gain.setValueAtTime(0, now + 0.15);
            gain2.gain.linearRampToValueAtTime(0.4, now + 0.18);
            gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.5);
            osc2.connect(gain2);
            gain2.connect(ctx.destination);
            osc2.start(now + 0.15);
            osc2.stop(now + 0.5);
        } catch (e) {
            console.warn('Audio chime playback omitted:', e);
        }
    }

    // Ensure Notification Overlay Container exists in DOM
    function getContainer() {
        let container = document.getElementById('hydromis-staff-notif-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'hydromis-staff-notif-container';
            document.body.appendChild(container);
        }
        return container;
    }

    // Display Popup Card for a New Pending Order
    function showOrderPopup(order) {
        const container = getContainer();

        const card = document.createElement('div');
        card.className = 'hydromis-order-toast';
        card.setAttribute('role', 'status');
        card.setAttribute('aria-live', 'polite');

        const isStaff = window.location.pathname.includes('/staff/');
        const reviewUrl = isStaff ? 'pending.php' : 'transactions.php?filter=pending';

        const amountFormatted = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(order.amount);
        const isPickup = order.fulfillment_method === 'pickup';
        card.innerHTML = `
            <div class="order-toast-head">
                <span class="order-toast-icon"><i class="fas fa-bell" aria-hidden="true"></i></span>
                <div class="order-toast-heading"><strong>New order received</strong><span>Awaiting approval</span></div>
                <button type="button" class="hydromis-notif-close" aria-label="Close notification">&times;</button>
            </div>
            <div class="order-toast-customer">${escapeHtml(order.full_name)}</div>
            <div class="order-toast-meta"><span>${escapeHtml(order.transaction_id)}</span><span>${escapeHtml(order.time_ago)}</span></div>
            <div class="order-toast-summary">
                <strong class="order-toast-amount">${amountFormatted}</strong>
                <span class="order-toast-badge"><i class="fas ${isPickup ? 'fa-store' : 'fa-truck'}" aria-hidden="true"></i> ${isPickup ? 'Self pickup' : 'Delivery'}</span>
            </div>
            <p class="order-toast-items">${escapeHtml(order.quantity)} ? ${escapeHtml(order.container_size)} ? ${escapeHtml(order.water_type)}</p>
            <div class="order-toast-actions">
                <a href="${reviewUrl}" class="order-toast-review">Review order <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                <button type="button" class="hydromis-notif-dismiss">Dismiss</button>
            </div>
        `;

        container.appendChild(card);

        // Trigger animation
        requestAnimationFrame(() => {
            card.style.opacity = '1';
            card.style.transform = 'translateY(0) scale(1)';
        });

        // Close logic
        const removeCard = () => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(-15px) scale(0.95)';
            setTimeout(() => card.remove(), 350);
        };

        card.querySelector('.hydromis-notif-close').addEventListener('click', removeCard);
        card.querySelector('.hydromis-notif-dismiss').addEventListener('click', removeCard);

        // Auto dismiss after 16 seconds
        setTimeout(removeCard, 16000);

        // Desktop Push Notification
        if ('Notification' in window && Notification.permission === 'granted') {
            try {
                new Notification('🔔 New Order Pending - HydroMIS', {
                    body: `${order.full_name} placed an order (${amountFormatted})`,
                    icon: '../imagess/logosystem.png',
                    tag: 'hydromis-pending-' + order.transaction_id
                });
            } catch (e) {
                console.warn('Native notification omitted:', e);
            }
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    }

    // Update UI sidebar badges dynamically across pages
    function updateBadges(data) {
        // Pending Approvals navigation badges
        const pendingBadges = document.querySelectorAll('a[href*="pending.php"] b, a[href*="pending.php"] .nav-badge, a[href*="pending.php"] .staff-nav-redmark');
        pendingBadges.forEach(el => {
            if (data.pending_count > 0) {
                el.textContent = data.pending_count;
                el.style.display = '';
            } else {
                el.textContent = '0';
                el.style.display = 'none';
            }
        });

        // Delivery Operations navigation badges
        const deliveryBadges = document.querySelectorAll('.staff-delivery-menu > a .staff-nav-redmark, a[href*="view=deliveries"] .nav-badge');
        deliveryBadges.forEach(el => {
            if (data.delivery_count > 0) {
                el.textContent = data.delivery_count;
                el.style.display = '';
            } else {
                el.style.display = 'none';
            }
        });

        // Pickup Order sub-menu badges
        const pickupBadges = document.querySelectorAll('a[href*="section=pickups"] .staff-nav-redmark');
        pickupBadges.forEach(el => {
            if (data.pickup_count > 0) {
                el.textContent = data.pickup_count;
                el.style.display = '';
            } else {
                el.style.display = 'none';
            }
        });

        // Reward Claims badges
        const rewardBadges = document.querySelectorAll('a[href*="rewards.php"] b, a[href*="rewards.php"] .nav-badge');
        rewardBadges.forEach(el => {
            if (data.rewards_count > 0) {
                el.textContent = data.rewards_count;
                el.style.display = '';
            } else {
                el.style.display = 'none';
            }
        });

        // Stat counter cards on pending.php if currently viewing
        const pendingStatValue = document.querySelector('.alert-pending .stat-value, .stat-value-pending');
        if (pendingStatValue) {
            pendingStatValue.textContent = data.pending_count;
        }
    }

    // Poll the backend endpoint
    async function pollPendingOrders() {
        try {
            const res = await fetch(endpoint + '?t=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' });
            if (!res.ok) return;
            const data = await res.json();

            if (!data.success) return;

            // Update UI badges
            updateBadges(data);

            const pendingList = data.pending_orders || [];
            let newOrdersDetected = [];

            if (isFirstPoll) {
                // Register current orders as seen without popping up all pre-existing historical orders
                pendingList.forEach(o => seenIds.add(o.transaction_id));
                saveSeenIds(seenIds);
                isFirstPoll = false;
                return;
            }

            // Check for un-seen pending orders
            for (const order of pendingList) {
                if (!seenIds.has(order.transaction_id)) {
                    seenIds.add(order.transaction_id);
                    newOrdersDetected.push(order);
                }
            }

            saveSeenIds(seenIds);

            if (newOrdersDetected.length > 0) {
                // Play audio chime once for new orders batch
                playNotificationSound();

                // Trigger popups for new orders (up to 3 newest)
                newOrdersDetected.slice(0, 3).forEach(order => showOrderPopup(order));
            }
        } catch (err) {
            console.warn('Pending order check poll failed:', err);
        }
    }

    // Request Notification permission on user action if supported
    if ('Notification' in window && Notification.permission === 'default') {
        window.addEventListener('click', function requestNotifPerm() {
            Notification.requestPermission();
            window.removeEventListener('click', requestNotifPerm);
        }, { once: true });
    }

    // Shared popup presentation for staff and admin.
    const style = document.createElement('style');
    style.textContent = `
        #hydromis-staff-notif-container{position:fixed;top:max(16px,env(safe-area-inset-top));right:16px;z-index:999999;width:min(400px,calc(100vw - 32px));max-height:calc(100dvh - 32px);overflow-y:auto;display:flex;flex-direction:column;gap:12px;pointer-events:none}
        .hydromis-order-toast{box-sizing:border-box;pointer-events:auto;padding:20px;border:1px solid #33536b;border-top:3px solid #27c5d5;border-radius:20px;background:linear-gradient(145deg,#14283c,#172135);color:#f1f7fc;box-shadow:0 16px 44px #07142466;font-family:'Plus Jakarta Sans',system-ui,sans-serif;opacity:0;transform:translateY(-20px) scale(.98);transition:opacity .25s ease,transform .25s ease}
        .order-toast-head{display:flex;align-items:center;gap:10px;margin-bottom:18px}
        .order-toast-icon{display:grid;place-items:center;flex:0 0 38px;height:38px;border-radius:12px;background:#173f53;color:#63deeb}
        .order-toast-heading{flex:1;min-width:0}.order-toast-heading strong{display:block;font-size:14px;font-weight:800}.order-toast-heading span{display:block;margin-top:3px;font-size:11px;color:#a8bdcf}
        .hydromis-notif-close{display:grid;place-items:center;flex:0 0 36px;height:36px;border:0;border-radius:10px;background:transparent;color:#a8bdcf;font-size:24px;cursor:pointer}
        .order-toast-customer{font-size:18px;font-weight:800;line-height:1.4;overflow-wrap:anywhere}
        .order-toast-meta{display:flex;flex-wrap:wrap;gap:4px 12px;margin-top:5px;color:#a8bdcf;font-size:11px;line-height:1.6;overflow-wrap:anywhere}.order-toast-meta span{min-width:0}
        .order-toast-summary{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-top:16px}.order-toast-amount{font-size:22px;color:#66e0c2;font-variant-numeric:tabular-nums}
        .order-toast-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #345a72;border-radius:8px;color:#a5dff5;background:#1c354a;font-size:11px;font-weight:700}
        .order-toast-items{margin:9px 0 18px;color:#b9cbd9;font-size:12px;line-height:1.6;overflow-wrap:anywhere}
        .order-toast-actions{display:flex;gap:8px;border-top:1px solid #ffffff12;padding-top:14px}.order-toast-actions a,.order-toast-actions button{display:flex;align-items:center;justify-content:center;gap:10px;min-height:44px;padding:10px 14px;border-radius:11px;font:700 12px 'Plus Jakarta Sans',system-ui,sans-serif;text-decoration:none;cursor:pointer}
        .order-toast-review{flex:1;background:linear-gradient(110deg,#1769d2,#087f9e);color:white;border:1px solid #328dc5}.hydromis-notif-dismiss{background:transparent;color:#b9cbd9;border:1px solid #3a4b60}
        .order-toast-review:hover{filter:brightness(1.12)}.hydromis-notif-close:hover,.hydromis-notif-dismiss:hover{background:#ffffff0d;color:white}.hydromis-order-toast :is(a,button):focus-visible{outline:3px solid #67e8f9;outline-offset:3px}
        body.staff-light .hydromis-order-toast{background:linear-gradient(145deg,#fff,#eff8fc);color:#17324d;border-color:#c8e3ef;border-top-color:#0891b2;box-shadow:0 16px 44px #17324d26}
        body.staff-light .order-toast-icon{background:#e0f4fa;color:#087f9e}body.staff-light :is(.order-toast-heading span,.order-toast-meta,.order-toast-items,.hydromis-notif-dismiss,.hydromis-notif-close){color:#526b80}body.staff-light .order-toast-amount{color:#087f65}body.staff-light .order-toast-badge{background:#e5f4fa;color:#176189;border-color:#c4e2ee}body.staff-light .order-toast-actions{border-color:#d6e5ee}body.staff-light .hydromis-notif-dismiss{border-color:#c5d7e3}body.staff-light :is(.hydromis-notif-close,.hydromis-notif-dismiss):hover{background:#deedf5;color:#17324d}
        @media(max-width:480px){#hydromis-staff-notif-container{right:12px;width:calc(100vw - 24px)}.hydromis-order-toast{padding:16px}.order-toast-customer{font-size:17px}}
        @media(prefers-reduced-motion:reduce){.hydromis-order-toast{transition:none}}
    `;
    document.head.appendChild(style);

    // Initial poll & 6-second interval
    pollPendingOrders();
    setInterval(pollPendingOrders, 6000);
})();
