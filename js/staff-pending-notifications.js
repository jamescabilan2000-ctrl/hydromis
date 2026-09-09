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
            container.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 999999;
                width: min(420px, calc(100vw - 32px));
                display: flex;
                flex-direction: column;
                gap: 12px;
                pointer-events: none;
            `;
            document.body.appendChild(container);
        }
        return container;
    }

    // Display Popup Card for a New Pending Order
    function showOrderPopup(order) {
        const container = getContainer();

        const card = document.createElement('div');
        card.style.cssText = `
            pointer-events: auto;
            position: relative;
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.96) 0%, rgba(26, 38, 66, 0.96) 100%);
            color: #f1f5f9;
            border: 1px solid rgba(245, 158, 11, 0.5);
            border-left: 5px solid #f59e0b;
            border-radius: 16px;
            padding: 18px 20px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.45), 0 0 25px rgba(245, 158, 11, 0.18);
            backdrop-filter: blur(16px);
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            opacity: 0;
            transform: translateY(-20px) scale(0.95);
            transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
        `;

        const isStaff = window.location.pathname.includes('/staff/');
        const reviewUrl = isStaff ? 'pending.php' : 'transactions.php?filter=pending';

        const amountFormatted = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(order.amount);
        const fulfillmentBadge = order.fulfillment_method === 'pickup'
            ? `<span style="background: rgba(168, 85, 247, 0.2); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.3); padding: 3px 8px; border-radius: 99px; font-size: 11px; font-weight: 700; text-transform: uppercase;"><i class="fas fa-store"></i> Pickup</span>`
            : `<span style="background: rgba(59, 130, 246, 0.2); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); padding: 3px 8px; border-radius: 99px; font-size: 11px; font-weight: 700; text-transform: uppercase;"><i class="fas fa-truck"></i> Delivery</span>`;

        card.innerHTML = `
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="display: inline-grid; place-items: center; width: 28px; height: 28px; border-radius: 8px; background: rgba(245, 158, 11, 0.2); color: #fbbf24;">
                        <i class="fas fa-bell" style="animation: ringBell 1.5s ease infinite;"></i>
                    </span>
                    <strong style="font-size: 13px; letter-spacing: 0.5px; color: #fbbf24; text-transform: uppercase; font-weight: 800;">New Pending Order!</strong>
                </div>
                <button type="button" class="hydromis-notif-close" style="border: 0; background: transparent; color: #94a3b8; font-size: 20px; cursor: pointer; padding: 0 4px; line-height: 1;" aria-label="Close">&times;</button>
            </div>
            
            <div style="margin-bottom: 12px;">
                <div style="font-size: 16px; font-weight: 800; color: #ffffff; margin-bottom: 2px;">${escapeHtml(order.full_name)}</div>
                <div style="font-size: 12px; color: #94a3b8; display: flex; align-items: center; gap: 8px;">
                    <span>Order: <strong>${escapeHtml(order.transaction_id)}</strong></span>
                    <span>&bull;</span>
                    <span>${escapeHtml(order.time_ago)}</span>
                </div>
            </div>

            <div style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 14px;">
                <span style="background: rgba(16, 185, 129, 0.2); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); padding: 3px 8px; border-radius: 99px; font-size: 11px; font-weight: 800;">${amountFormatted}</span>
                ${fulfillmentBadge}
                <span style="background: rgba(255, 255, 255, 0.08); color: #cbd5e1; border: 1px solid rgba(255, 255, 255, 0.12); padding: 3px 8px; border-radius: 99px; font-size: 11px;">${escapeHtml(order.quantity)}x ${escapeHtml(order.container_size)} (${escapeHtml(order.water_type)})</span>
            </div>

            <div style="display: flex; gap: 8px;">
                <a href="${reviewUrl}" style="flex: 1; text-align: center; background: linear-gradient(135deg, #f59e0b, #d97706); color: #000000; font-weight: 800; font-size: 12px; padding: 9px 12px; border-radius: 10px; text-decoration: none; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3); transition: transform 0.2s ease;">
                    <i class="fas fa-eye"></i> Review Order
                </a>
                <button type="button" class="hydromis-notif-dismiss" style="background: rgba(255, 255, 255, 0.1); color: #cbd5e1; border: 1px solid rgba(255, 255, 255, 0.15); font-weight: 700; font-size: 12px; padding: 9px 14px; border-radius: 10px; cursor: pointer;">
                    Dismiss
                </button>
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

    // Inject CSS keyframes for bell animation
    const style = document.createElement('style');
    style.textContent = `
        @keyframes ringBell {
            0%, 100% { transform: rotate(0); }
            15% { transform: rotate(15deg); }
            30% { transform: rotate(-15deg); }
            45% { transform: rotate(10deg); }
            60% { transform: rotate(-10deg); }
            75% { transform: rotate(5deg); }
        }
    `;
    document.head.appendChild(style);

    // Initial poll & 6-second interval
    pollPendingOrders();
    setInterval(pollPendingOrders, 6000);
})();
