/**
 * HydroMIS Application Utilities
 * Global helpers for API calls, notifications, and UI interactions
 */

// ===== API Service =====
const APIService = {
    baseUrl: '/api/delivery_tracker.php',
    
    /**
     * Make API request to delivery tracker
     */
    async request(method, request, data = null) {
        try {
            const url = new URL(window.location.origin + this.baseUrl);
            url.searchParams.append('request', request);
            
            const options = {
                method: method,
                headers: {
                    'Content-Type': 'application/json'
                }
            };
            
            if (data && (method === 'POST' || method === 'PUT')) {
                options.body = JSON.stringify(data);
                for (let key in data) {
                    url.searchParams.append(key, data[key]);
                }
            }
            
            const response = await fetch(url, options);
            const result = await response.json();
            
            if (!response.ok) {
                throw new Error(result.error || 'API request failed');
            }
            
            return result;
        } catch (error) {
            console.error('API Error:', error);
            throw error;
        }
    },
    
    // Delivery tracker endpoints
    getRiderLocation(transactionId) {
        return this.request('GET', 'get_rider_location', { transaction_id: transactionId });
    },
    
    getDeliveryDetails(transactionId) {
        return this.request('GET', 'get_delivery_details', { transaction_id: transactionId });
    },
    
    getAllDeliveries() {
        return this.request('GET', 'get_all_deliveries');
    },
    
    updateRiderLocation(transactionId, latitude, longitude) {
        return this.request('POST', 'update_rider_location', {
            transaction_id: transactionId,
            latitude: latitude,
            longitude: longitude
        });
    },
    
    completeDelivery(transactionId) {
        return this.request('POST', 'complete_delivery', { transaction_id: transactionId });
    }
};

// ===== Notification System =====
const Notifications = {
    container: null,

    ensureStyles() {
        if (document.getElementById('copilot-notification-fallback-styles')) {
            return;
        }

        const style = document.createElement('style');
        style.id = 'copilot-notification-fallback-styles';
        style.textContent = `
            #notification-container {
                position: fixed;
                top: 16px;
                right: 16px;
                z-index: 9999;
                display: flex;
                flex-direction: column;
                gap: 10px;
                max-width: 420px;
            }
            .notification {
                background: #fff;
                border-radius: 10px;
                padding: 12px 14px;
                box-shadow: 0 8px 24px rgba(11, 18, 32, 0.22);
                border-left: 4px solid #3b82f6;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }
            .notification-content {
                display: flex;
                align-items: center;
                gap: 8px;
                color: #1f2937;
                font-size: 14px;
                font-weight: 600;
                line-height: 1.35;
            }
            .notification-close {
                background: transparent;
                border: none;
                color: #64748b;
                cursor: pointer;
                width: 20px;
                height: 20px;
                border-radius: 4px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 0;
                font-size: 16px;
                line-height: 1;
            }
            .notification-close:hover {
                background: #f1f5f9;
                color: #334155;
            }
            .notification-success { border-left-color: #10b981; background: #ecfdf5; }
            .notification-success .notification-content { color: #065f46; }
            .notification-error { border-left-color: #ef4444; background: #fef2f2; }
            .notification-error .notification-content { color: #991b1b; }
            .notification-warning { border-left-color: #f59e0b; background: #fffbeb; }
            .notification-warning .notification-content { color: #78350f; }
            .notification-info { border-left-color: #2563eb; background: #eff6ff; }
            .notification-info .notification-content { color: #1e40af; }
            .animate-slide-in { animation: copilotToastIn 0.25s ease-out; }
            .animate-fade-out { animation: copilotToastOut 0.25s ease-out; }
            @keyframes copilotToastIn {
                from { opacity: 0; transform: translateX(16px); }
                to { opacity: 1; transform: translateX(0); }
            }
            @keyframes copilotToastOut {
                from { opacity: 1; transform: translateX(0); }
                to { opacity: 0; transform: translateX(10px); }
            }
        `;
        document.head.appendChild(style);
    },
    
    init() {
        this.ensureStyles();
        if (!document.getElementById('notification-container')) {
            this.container = document.createElement('div');
            this.container.id = 'notification-container';
            document.body.appendChild(this.container);
        } else {
            this.container = document.getElementById('notification-container');
        }
    },
    
    show(message, type = 'info', duration = 4000) {
        this.init();
        
        const notification = document.createElement('div');
        notification.className = `notification notification-${type} animate-slide-in`;
        
        const icons = {
            success: '<i class="fas fa-check-circle"></i>',
            error: '<i class="fas fa-exclamation-circle"></i>',
            warning: '<i class="fas fa-exclamation-triangle"></i>',
            info: '<i class="fas fa-info-circle"></i>',
            loading: '<i class="fas fa-spinner fa-spin"></i>'
        };
        
        notification.innerHTML = `
            <div class="notification-content">
                ${icons[type] || icons.info}
                <span>${message}</span>
            </div>
            <button class="notification-close" onclick="this.parentElement.remove()" aria-label="Close notification" title="Close">
                &times;
            </button>
        `;
        
        this.container.appendChild(notification);
        
        if (duration > 0 && type !== 'loading') {
            setTimeout(() => {
                notification.classList.add('animate-fade-out');
                setTimeout(() => notification.remove(), 300);
            }, duration);
        }
        
        return notification;
    },
    
    success(message, duration = 3000) {
        return this.show(message, 'success', duration);
    },
    
    error(message, duration = 4000) {
        return this.show(message, 'error', duration);
    },
    
    warning(message, duration = 3500) {
        return this.show(message, 'warning', duration);
    },
    
    info(message, duration = 3000) {
        return this.show(message, 'info', duration);
    },
    
    loading(message) {
        return this.show(message, 'loading', 0);
    }
};

// ===== Real-time Location Tracker =====
const LocationTracker = {
    watchId: null,
    isTracking: false,
    
    startTracking() {
        this.isTracking = true;
        Notifications.info('Location tracking started...');
        
        if ('geolocation' in navigator) {
            this.watchId = navigator.geolocation.watchPosition(
                (position) => this.handleLocation(position),
                (error) => this.handleError(error),
                { enableHighAccuracy: true, timeout: 5000, maximumAge: 0 }
            );
        } else {
            Notifications.error('Geolocation is not supported by your browser');
        }
    },
    
    stopTracking() {
        if (this.watchId !== null) {
            navigator.geolocation.clearWatch(this.watchId);
            this.isTracking = false;
            Notifications.info('Location tracking stopped');
        }
    },
    
    handleLocation(position) {
        const { latitude, longitude, accuracy } = position.coords;
        
        // Dispatch custom event with location data
        window.dispatchEvent(new CustomEvent('locationUpdate', {
            detail: { latitude, longitude, accuracy, timestamp: Date.now() }
        }));
    },
    
    handleError(error) {
        const messages = {
            1: 'Location permission denied',
            2: 'Unable to retrieve location',
            3: 'Request timeout'
        };
        Notifications.warning(messages[error.code] || 'Location tracking error');
    }
};

// ===== Real-time Data Refresh =====
const RealtimeData = {
    intervals: {},
    
    startPolling(key, callback, interval = 10000) {
        if (this.intervals[key]) {
            clearInterval(this.intervals[key]);
        }
        
        callback(); // Call immediately
        
        this.intervals[key] = setInterval(callback, interval);
    },
    
    stopPolling(key) {
        if (this.intervals[key]) {
            clearInterval(this.intervals[key]);
            delete this.intervals[key];
        }
    },
    
    stopAll() {
        for (let key in this.intervals) {
            clearInterval(this.intervals[key]);
        }
        this.intervals = {};
    }
};

// ===== Form Validation =====
const FormValidator = {
    rules: {
        required: (value) => value.trim().length > 0,
        email: (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value),
        phone: (value) => /^[0-9\-\+]{10,}$/.test(value),
        number: (value) => !isNaN(value) && value.trim().length > 0,
        minLength: (length) => (value) => value.length >= length,
        maxLength: (length) => (value) => value.length <= length
    },
    
    validate(form) {
        const errors = {};
        const inputs = form.querySelectorAll('[data-validate]');
        
        inputs.forEach(input => {
            const rules = input.dataset.validate.split('|');
            const value = input.value;
            
            rules.forEach(rule => {
                if (rule === 'required' && !this.rules.required(value)) {
                    errors[input.name] = `${input.placeholder || 'This field'} is required`;
                }
                if (rule === 'email' && value && !this.rules.email(value)) {
                    errors[input.name] = 'Invalid email format';
                }
                if (rule === 'phone' && value && !this.rules.phone(value)) {
                    errors[input.name] = 'Invalid phone number';
                }
            });
        });
        
        return Object.keys(errors).length === 0 ? null : errors;
    },
    
    displayErrors(form, errors) {
        form.querySelectorAll('.error-text').forEach(e => e.remove());
        form.querySelectorAll('.is-invalid').forEach(e => e.classList.remove('is-invalid'));
        
        if (errors) {
            for (let fieldName in errors) {
                const field = form.querySelector(`[name="${fieldName}"]`);
                if (field) {
                    field.classList.add('is-invalid');
                    const errorDiv = document.createElement('small');
                    errorDiv.className = 'error-text text-danger';
                    errorDiv.innerText = errors[fieldName];
                    field.parentElement.appendChild(errorDiv);
                }
            }
        }
    }
};

// ===== DOM Utilities =====
const DOM = {
    show(element) {
        if (typeof element === 'string') {
            element = document.querySelector(element);
        }
        if (element) element.style.display = '';
    },
    
    hide(element) {
        if (typeof element === 'string') {
            element = document.querySelector(element);
        }
        if (element) element.style.display = 'none';
    },
    
    toggle(element) {
        if (typeof element === 'string') {
            element = document.querySelector(element);
        }
        if (element) {
            element.style.display = element.style.display === 'none' ? '' : 'none';
        }
    },
    
    addClass(element, className) {
        if (typeof element === 'string') {
            element = document.querySelector(element);
        }
        if (element) element.classList.add(className);
    },
    
    removeClass(element, className) {
        if (typeof element === 'string') {
            element = document.querySelector(element);
        }
        if (element) element.classList.remove(className);
    },
    
    toggleClass(element, className) {
        if (typeof element === 'string') {
            element = document.querySelector(element);
        }
        if (element) element.classList.toggle(className);
    }
};

// ===== Live Counter Animation =====
const Counter = {
    animate(element, targetValue, duration = 1000) {
        const startValue = parseInt(element.innerText) || 0;
        const increment = (targetValue - startValue) / (duration / 16);
        let currentValue = startValue;
        
        const timer = setInterval(() => {
            currentValue += increment;
            if (currentValue >= targetValue) {
                element.innerText = targetValue;
                clearInterval(timer);
            } else {
                element.innerText = Math.floor(currentValue);
            }
        }, 16);
    }
};

// ===== Search and Filter =====
const SearchFilter = {
    search(inputSelector, tableSelector, columns = []) {
        const input = document.querySelector(inputSelector);
        const table = document.querySelector(tableSelector);
        
        if (!input || !table) return;
        
        input.addEventListener('keyup', (e) => {
            const searchTerm = e.target.value.toLowerCase();
            const rows = table.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const text = row.innerText.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
    },
    
    filterByStatus(statusSelector, tableSelector, statusColumn = 1) {
        const statusSelect = document.querySelector(statusSelector);
        const table = document.querySelector(tableSelector);
        
        if (!statusSelect || !table) return;
        
        statusSelect.addEventListener('change', (e) => {
            const filterValue = e.target.value.toLowerCase();
            const rows = table.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const statusCell = row.cells[statusColumn];
                if (statusCell) {
                    const status = statusCell.innerText.toLowerCase();
                    row.style.display = (filterValue === '' || status.includes(filterValue)) ? '' : 'none';
                }
            });
        });
    }
};

// ===== Modal Helper =====
const Modal = {
    open(selector) {
        const modal = document.querySelector(selector);
        if (modal) {
            modal.classList.add('show');
            modal.style.display = 'block';
        }
    },
    
    close(selector) {
        const modal = document.querySelector(selector);
        if (modal) {
            modal.classList.remove('show');
            modal.style.display = 'none';
        }
    }
};

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    Notifications.init();
});

// Cleanup on page leave
window.addEventListener('beforeunload', () => {
    LocationTracker.stopTracking();
    RealtimeData.stopAll();
});
