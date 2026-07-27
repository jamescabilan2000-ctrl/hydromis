/**
 * Live GPS Tracking Module for Rider Dashboard
 * Handles geolocation updates and sends to server
 */

class RiderGPSTracker {
    constructor(options = {}) {
        this.isTracking = false;
        this.watchId = null;
        this.updateInterval = options.updateInterval || 10000; // 10 seconds
        this.updateUrl = '../api/delivery_tracker.php?request=update_rider_location';
        this.callback = options.callback || null;
        this.errorCallback = options.errorCallback || null;
    }

    /**
     * Start live GPS tracking
     */
    startTracking(transactionId) {
        if (!('geolocation' in navigator)) {
            this.triggerError('Geolocation is not supported by your browser');
            return false;
        }

        this.transactionId = transactionId;
        this.isTracking = true;

        // Get initial position
        navigator.geolocation.getCurrentPosition(
            (position) => this.sendLocation(position),
            (error) => this.triggerError('Error getting location: ' + error.message)
        );

        // Start watching position at intervals
        this.watchId = navigator.geolocation.watchPosition(
            (position) => this.sendLocation(position),
            (error) => console.log('Watch position error: ' + error.message),
            {
                enableHighAccuracy: true,
                timeout: 30000,
                maximumAge: 0
            }
        );

        return true;
    }

    /**
     * Stop live GPS tracking
     */
    stopTracking() {
        if (this.watchId !== null) {
            navigator.geolocation.clearWatch(this.watchId);
            this.watchId = null;
        }
        this.isTracking = false;
    }

    /**
     * Send location to server
     */
    sendLocation(position) {
        const coords = position.coords;
        const data = {
            transaction_id: this.transactionId,
            latitude: coords.latitude,
            longitude: coords.longitude,
            accuracy: coords.accuracy,
            speed: coords.speed,
            heading: coords.heading
        };

        fetch(this.updateUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                if (this.callback) {
                    this.callback({
                        latitude: coords.latitude,
                        longitude: coords.longitude,
                        accuracy: coords.accuracy
                    });
                }
            }
        })
        .catch(error => this.triggerError('Failed to send location: ' + error.message));
    }

    /**
     * Trigger error callback
     */
    triggerError(message) {
        console.error(message);
        if (this.errorCallback) {
            this.errorCallback(message);
        }
    }

    /**
     * Get current tracking status
     */
    isActive() {
        return this.isTracking;
    }
}

// Global GPS tracker instance
let riderGPSTracker = null;

/**
 * Initialize GPS tracker for a delivery
 */
function initializeGPSTracker(transactionId) {
    if (riderGPSTracker === null) {
        riderGPSTracker = new RiderGPSTracker({
            updateInterval: 10000,
            callback: function(location) {
                console.log('Location updated:', location);
                // Update UI to show successful tracking
                const statusElement = document.getElementById(`gps-status-${transactionId}`);
                if (statusElement) {
                    statusElement.innerHTML = `
                        <i class="fas fa-map-marker-alt" style="color: #10b981;"></i>
                        Location: ${location.latitude.toFixed(6)}, ${location.longitude.toFixed(6)}
                        <small style="display: block; font-size: 12px; margin-top: 4px;">
                            Accuracy: ±${Math.round(location.accuracy)}m
                        </small>
                    `;
                }
            },
            errorCallback: function(error) {
                const statusElement = document.getElementById(`gps-status-${transactionId}`);
                if (statusElement) {
                    statusElement.innerHTML = `<i class="fas fa-exclamation-circle" style="color: #ef4444;"></i> ${error}`;
                }
            }
        });
    }

    return riderGPSTracker.startTracking(transactionId);
}

/**
 * Toggle GPS tracking
 */
function toggleGPSTracking(button, transactionId) {
    if (riderGPSTracker && riderGPSTracker.isActive()) {
        riderGPSTracker.stopTracking();
        button.querySelector('.gps-btn-text').textContent = 'Live GPS';
        button.classList.remove('tracking-active');
        button.classList.add('btn-success');
        
        // Clear status display
        const statusElement = document.getElementById(`gps-status-${transactionId}`);
        if (statusElement) {
            statusElement.innerHTML = '';
        }
    } else {
        if (initializeGPSTracker(transactionId)) {
            button.querySelector('.gps-btn-text').textContent = 'Stop GPS';
            button.classList.add('tracking-active');
            button.classList.remove('btn-success');
        }
    }
}

/**
 * Stop all GPS tracking on page unload
 */
window.addEventListener('beforeunload', function() {
    if (riderGPSTracker && riderGPSTracker.isActive()) {
        riderGPSTracker.stopTracking();
    }
});
