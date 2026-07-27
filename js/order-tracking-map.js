/**
 * Live GPS Map Display Module for User Tracking
 * Shows rider's real-time location on an interactive map
 */

class OrderTrackingMap {
    constructor(mapContainerId, options = {}) {
        this.mapContainer = document.getElementById(mapContainerId);
        this.transactionId = options.transactionId || null;
        this.mapElement = null;
        this.userMarker = null;
        this.riderMarker = null;
        this.polyline = null;
        this.refreshInterval = options.refreshInterval || 5000; // 5 seconds
        this.refreshTimeoutId = null;
        this.mapLibrary = options.mapLibrary || 'leaflet'; // 'leaflet' or 'google'
        this.isInitialized = false;
        
        // Fallback coordinates (Manila)
        this.defaultLat = 14.5995;
        this.defaultLng = 120.9842;
    }

    /**
     * Initialize the map
     */
    async initialize() {
        if (this.isInitialized) return;

        if (this.mapLibrary === 'leaflet') {
            await this.initializeLeafletMap();
        } else {
            await this.initializeSimpleMap();
        }

        this.isInitialized = true;
        this.startRefreshing();
    }

    /**
     * Initialize Leaflet Map (requires Leaflet library)
     */
    async initializeLeafletMap() {
        // Check if Leaflet is loaded, if not load it
        if (typeof L === 'undefined') {
            // Create a simple canvas-based map instead if Leaflet is not available
            await this.initializeCanvasMap();
            return;
        }

        const mapDiv = document.createElement('div');
        mapDiv.id = 'leaflet-map';
        mapDiv.style.width = '100%';
        mapDiv.style.height = '400px';
        mapDiv.style.borderRadius = '12px';
        mapDiv.style.boxShadow = '0 8px 24px rgba(11,24,41,.11)';
        this.mapContainer.appendChild(mapDiv);

        // Initialize map
        const map = L.map('leaflet-map').setView([this.defaultLat, this.defaultLng], 14);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors',
            maxZoom: 19
        }).addTo(map);

        // Store map instance
        this.leafletMap = map;

        // Load current order location
        if (this.transactionId) {
            await this.loadOrderLocation();
        }
    }

    /**
     * Initialize Canvas-based Map (fallback)
     */
    async initializeCanvasMap() {
        const mapDiv = document.createElement('div');
        mapDiv.id = 'canvas-map';
        mapDiv.style.width = '100%';
        mapDiv.style.height = '400px';
        mapDiv.style.borderRadius = '12px';
        mapDiv.style.background = 'linear-gradient(135deg, #e8f2ff 0%, #f0f7fe 100%)';
        mapDiv.style.border = '1px solid #e2ecf6';
        mapDiv.style.position = 'relative';
        mapDiv.style.overflow = 'hidden';
        this.mapContainer.appendChild(mapDiv);

        this.canvasMapDiv = mapDiv;

        // Load current order location
        if (this.transactionId) {
            await this.loadOrderLocation();
        }
    }

    /**
     * Load order location data
     */
    async loadOrderLocation() {
        try {
            const response = await fetch(`../api/delivery_tracker.php?request=get_rider_location&transaction_id=${this.transactionId}`);
            const result = await response.json();

            if (result.success) {
                const data = result.data;
                this.updateMap(data);
                return data;
            }
        } catch (error) {
            console.error('Error loading order location:', error);
        }
        return null;
    }

    /**
     * Update map with location data
     */
    updateMap(data) {
        if (!data) return;

        const riderLat = parseFloat(data.rider_location.latitude);
        const riderLng = parseFloat(data.rider_location.longitude);

        if (this.leafletMap) {
            this.updateLeafletMap(data, riderLat, riderLng);
        } else if (this.canvasMapDiv) {
            this.updateCanvasMap(data, riderLat, riderLng);
        }

        // Update location info
        this.updateLocationInfo(data);
    }

    /**
     * Update Leaflet Map
     */
    updateLeafletMap(data, riderLat, riderLng) {
        const map = this.leafletMap;

        // Remove old markers
        if (this.userMarker) map.removeLayer(this.userMarker);
        if (this.riderMarker) map.removeLayer(this.riderMarker);
        if (this.polyline) map.removeLayer(this.polyline);

        // Add user location marker
        this.userMarker = L.marker([this.defaultLat, this.defaultLng], {
            title: data.customer_name,
            icon: L.icon({
                iconUrl: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCI+PHBhdGggZmlsbD0iIzFkNmZkOCIgZD0iTTEyIDJDNi40OCAyIDIgNi40OCAyIDEyYzAgNy4zMSAxMCAxOCAxMCAxOHMxMC0xMC42OSAxMC0xOGMwLTUuNTItNC40OC0xMC0xMC0xMHptMCA4YzEuMSAwIDIgLjkgMiAycy0uOSAyLTIgMi0yLS45LTItMiAuOS0yIDItMnoiLz48L3N2Zz4=',
                iconSize: [40, 40],
                iconAnchor: [20, 40]
            })
        }).addTo(map).bindPopup(`<strong>${data.customer_name}</strong><br/>Customer Location`);

        // Add rider location marker with animated icon
        this.riderMarker = L.marker([riderLat, riderLng], {
            title: data.rider_name || 'Rider',
            icon: L.icon({
                iconUrl: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCI+PHBhdGggZmlsbD0iIzEwYjk4MSIgZD0iTTEyIDJDNi40OCAyIDIgNi40OCAyIDEyYzAgNy4zMSAxMCAxOCAxMCAxOHMxMC0xMC42OSAxMC0xOGMwLTUuNTItNC40OC0xMC0xMC0xMHptMCA4YzEuMSAwIDIgLjkgMiAycy0uOSAyLTIgMi0yLS45LTItMiAuOS0yIDItMnoiLz48L3N2Zz4=',
                iconSize: [40, 40],
                iconAnchor: [20, 40]
            })
        }).addTo(map).bindPopup(`<strong>${data.rider_name || 'Rider'}</strong><br/>Current Location`);

        // Draw line between user and rider
        if (riderLat !== this.defaultLat || riderLng !== this.defaultLng) {
            this.polyline = L.polyline([
                [this.defaultLat, this.defaultLng],
                [riderLat, riderLng]
            ], {
                color: '#2d85f0',
                weight: 2,
                opacity: 0.7,
                dashArray: '5, 5'
            }).addTo(map);
        }

        // Fit map to bounds
        const group = new L.featureGroup([this.userMarker, this.riderMarker]);
        map.fitBounds(group.getBounds().pad(0.1));
    }

    /**
     * Update Canvas Map (fallback)
     */
    updateCanvasMap(data, riderLat, riderLng) {
        const div = this.canvasMapDiv;
        
        // Clear previous content
        div.innerHTML = '';

        // Calculate distance between points for visualization
        const latDiff = riderLat - this.defaultLat;
        const lngDiff = riderLng - this.defaultLng;
        const distance = Math.sqrt(latDiff * latDiff + lngDiff * lngDiff);

        // Create map representation
        const mapContent = document.createElement('div');
        mapContent.style.cssText = `
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            padding: 20px;
            text-align: center;
            color: #0b1829;
            font-size: 14px;
        `;

        const mapHeader = document.createElement('div');
        mapHeader.style.cssText = 'font-weight: 700; margin-bottom: 16px; font-size: 16px;';
        mapHeader.textContent = '📍 Live Tracking Map';

        const coordsBox = document.createElement('div');
        coordsBox.style.cssText = `
            background: rgba(45, 133, 240, 0.1);
            border: 2px solid #2d85f0;
            border-radius: 12px;
            padding: 12px;
            margin: 12px 0;
            font-family: monospace;
            font-size: 12px;
            line-height: 1.6;
        `;
        coordsBox.innerHTML = `
            <div><strong>📍 You are here:</strong></div>
            <div>${this.defaultLat.toFixed(6)}, ${this.defaultLng.toFixed(6)}</div>
            <div style="margin-top: 8px; border-top: 1px solid #2d85f0; padding-top: 8px;">
                <strong>🚗 Rider:</strong>
            </div>
            <div>${riderLat.toFixed(6)}, ${riderLng.toFixed(6)}</div>
            <div style="margin-top: 8px; color: #10b981;">
                <strong>📏 Distance: ~${(distance * 111).toFixed(2)}km away</strong>
            </div>
        `;

        const riderInfo = document.createElement('div');
        riderInfo.style.cssText = `
            margin-top: 16px;
            padding: 12px;
            background: #f0f7fe;
            border-radius: 8px;
            width: 100%;
        `;
        riderInfo.innerHTML = `
            <div><strong>Rider: ${data.rider_name || 'N/A'}</strong></div>
            <div style="font-size: 12px; margin-top: 4px;">
                <i class="fas fa-phone"></i> ${data.rider_contact_number || 'N/A'}
            </div>
        `;

        mapContent.appendChild(mapHeader);
        mapContent.appendChild(coordsBox);
        mapContent.appendChild(riderInfo);
        div.appendChild(mapContent);
    }

    /**
     * Update location information display
     */
    updateLocationInfo(data) {
        const infoContainer = document.getElementById('live-location-info');
        if (!infoContainer) return;

        const lastUpdate = new Date(data.rider_location.last_update);
        const timeAgo = this.formatTimeAgo(lastUpdate);

        infoContainer.innerHTML = `
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 13px;">
                <div>
                    <div style="color: #7b97b8; font-size: 12px; margin-bottom: 4px;">RIDER</div>
                    <div style="font-weight: 600; color: #0b1829;">${data.rider_name || 'Not assigned'}</div>
                    <div style="color: #7b97b8; font-size: 12px; margin-top: 4px;">
                        <i class="fas fa-phone"></i> ${data.rider_contact_number || 'N/A'}
                    </div>
                </div>
                <div>
                    <div style="color: #7b97b8; font-size: 12px; margin-bottom: 4px;">DELIVERY STATUS</div>
                    <div style="font-weight: 600; color: #10b981;">
                        ${this.formatDeliveryStatus(data.delivery_status)}
                    </div>
                    <div style="color: #7b97b8; font-size: 12px; margin-top: 4px;">Updated ${timeAgo}</div>
                </div>
            </div>
        `;
    }

    /**
     * Format delivery status for display
     */
    formatDeliveryStatus(status) {
        const statusMap = {
            'pending': '⏳ Pending',
            'preparing': '📦 Preparing',
            'on_way': '🚗 On the Way',
            'on_the_way': '🚗 On the Way',
            'delivered': '✅ Delivered'
        };
        return statusMap[status?.toLowerCase()] || status || 'Unknown';
    }

    /**
     * Format time ago
     */
    formatTimeAgo(date) {
        const seconds = Math.floor((new Date() - date) / 1000);
        if (seconds < 60) return 'just now';
        if (seconds < 3600) return Math.floor(seconds / 60) + 'm ago';
        if (seconds < 86400) return Math.floor(seconds / 3600) + 'h ago';
        return Math.floor(seconds / 86400) + 'd ago';
    }

    /**
     * Start auto-refreshing location
     */
    startRefreshing() {
        this.refreshTimeoutId = setInterval(() => {
            this.loadOrderLocation();
        }, this.refreshInterval);
    }

    /**
     * Stop auto-refreshing
     */
    stopRefreshing() {
        if (this.refreshTimeoutId) {
            clearInterval(this.refreshTimeoutId);
            this.refreshTimeoutId = null;
        }
    }

    /**
     * Destroy the map
     */
    destroy() {
        this.stopRefreshing();
        if (this.leafletMap) {
            this.leafletMap.remove();
        }
    }
}

// Global map instance
let orderTrackingMap = null;

/**
 * Initialize order tracking map on page
 */
async function initializeOrderTrackingMap(transactionId, containerId = 'gps-map-container') {
    if (orderTrackingMap !== null) {
        orderTrackingMap.destroy();
    }

    orderTrackingMap = new OrderTrackingMap(containerId, {
        transactionId: transactionId,
        refreshInterval: 5000,
        mapLibrary: 'leaflet'
    });

    await orderTrackingMap.initialize();
    return orderTrackingMap;
}
