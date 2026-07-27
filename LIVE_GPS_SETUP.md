# Live GPS Tracking Feature Setup Guide

## Overview

The Live GPS Tracking feature enables riders to share their real-time location with customers during deliveries. Customers can then see where their order is going on an interactive map with live location updates.

## Components

### 1. **Database**
- **Table**: `rider_locations`
- **Purpose**: Stores rider GPS coordinates, accuracy, speed, and heading in real-time
- **Setup**: Run the SQL script at `/database/add_rider_locations.sql`

```sql
CREATE TABLE IF NOT EXISTS rider_locations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_id VARCHAR(255) NOT NULL,
    rider_id VARCHAR(50) NOT NULL,
    rider_latitude DECIMAL(10, 8) NOT NULL,
    rider_longitude DECIMAL(11, 8) NOT NULL,
    accuracy FLOAT,
    speed FLOAT,
    heading FLOAT,
    last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id),
    FOREIGN KEY (rider_id) REFERENCES rider_users(rider_id),
    UNIQUE KEY unique_transaction_rider (transaction_id, rider_id),
    INDEX idx_rider_id (rider_id),
    INDEX idx_transaction_id (transaction_id),
    INDEX idx_last_update (last_update)
);
```

### 2. **API Endpoints**

#### Update Rider Location (POST)
**Endpoint**: `/api/delivery_tracker.php?request=update_rider_location`

**Request Body** (JSON):
```json
{
    "transaction_id": "TRX-001",
    "latitude": 14.5995,
    "longitude": 120.9842,
    "accuracy": 10.5,
    "speed": 35.2,
    "heading": 180.5
}
```

**Response**:
```json
{
    "success": true,
    "message": "Rider location updated successfully",
    "data": {
        "transaction_id": "TRX-001",
        "latitude": 14.5995,
        "longitude": 120.9842
    }
}
```

#### Get Rider Location (GET)
**Endpoint**: `/api/delivery_tracker.php?request=get_rider_location&transaction_id=TRX-001`

**Response**:
```json
{
    "success": true,
    "data": {
        "transaction_id": "TRX-001",
        "delivery_status": "on_way",
        "status": "approved",
        "destination": "123 Main Street",
        "customer_name": "Juan Dela Cruz",
        "contact_number": "09171234567",
        "address": "123 Main Street, Manila",
        "rider_name": "John Rider",
        "rider_contact_number": "09189876543",
        "rider_location": {
            "latitude": 14.5995,
            "longitude": 120.9842,
            "last_update": "2024-05-01 14:30:45"
        }
    }
}
```

### 3. **Frontend Components**

#### Rider Dashboard (`rider/dashboard.php`)
- **Feature**: "Live GPS" button appears on each delivery when status is "on_way"
- **JavaScript**: `js/rider-gps-tracker.js`
- **Functionality**:
  - Click "Live GPS" to start sharing location
  - Browser requests permission to access geolocation
  - Location updates every 10 seconds to the server
  - Status display shows current coordinates and accuracy
  - Click "Stop GPS" to disable tracking

#### User Tracking Page (`user/track_order.php`)
- **Feature**: Live location updates below the map
- **JavaScript**: Inline script for polling rider location
- **Display**:
  - Real-time rider GPS coordinates
  - Customer delivery address
  - Last update timestamp
  - Status pill showing delivery status

### 4. **JavaScript Files**

#### `js/rider-gps-tracker.js` (Rider Side)
- **Class**: `RiderGPSTracker`
- **Methods**:
  - `startTracking(transactionId)`: Begin GPS updates
  - `stopTracking()`: Stop tracking
  - `sendLocation(position)`: Send coords to server
  - `isActive()`: Check if tracking is active

#### `js/order-tracking-map.js` (User Side - Fallback)
- **Class**: `OrderTrackingMap`
- **Methods**:
  - `initialize()`: Setup map display
  - `loadOrderLocation()`: Fetch rider location
  - `updateMap(data)`: Update markers and polylines
  - `startRefreshing()`: Auto-refresh location
  - `stopRefreshing()`: Stop auto-refresh

## Setup Instructions

### Step 1: Create Database Table
```bash
# Option 1: Using MySQL CLI
mysql -u root -p hydromis < database/add_rider_locations.sql

# Option 2: Using phpMyAdmin
# - Open phpMyAdmin
# - Select 'hydromis' database
# - Go to "SQL" tab
# - Paste the SQL from add_rider_locations.sql
# - Execute
```

### Step 2: Verify API Endpoints
```bash
# Test if API responds
curl "http://localhost/HydroMIS-1.3/api/delivery_tracker.php?request=get_rider_location&transaction_id=TRX-001"
```

### Step 3: Test Rider GPS
1. Log in as a rider
2. Go to Dashboard
3. Start a delivery (change status to "on_way")
4. Click "Live GPS" button
5. Allow browser geolocation permission
6. Verify location updates in real-time

### Step 4: Test Customer Tracking
1. Go to track order page (`user/track_order.php`)
2. Search for a rider's delivery using mobile number
3. Verify live location shows below map
4. Confirm updates occur every 5 seconds

## Browser Compatibility

### Geolocation API Support
- ✅ Chrome 5+
- ✅ Firefox 3.5+
- ✅ Safari 5+
- ✅ Edge 12+
- ✅ Opera 10.6+
- ✅ Mobile browsers (iOS Safari, Chrome Mobile, etc.)

### Requirements
- **HTTPS**: Geolocation only works on HTTPS (or localhost)
- **User Permission**: Browser will ask user to allow location access
- **Accuracy**: Typically ±10-30 meters in urban areas

## Security Considerations

1. **Data Privacy**
   - Location is only visible for active deliveries
   - Data is cleared after delivery completion (optional)
   - Use HTTPS for all location transmissions

2. **Rate Limiting**
   - GPS updates every 10 seconds (rider side)
   - Customer polls every 5 seconds (user side)
   - Adjust intervals in respective JavaScript files

3. **Database**
   - `rider_locations` table has foreign key constraints
   - Automatic cleanup: Consider archiving old location data
   - Index optimization for large datasets

## Troubleshooting

### GPS Button Not Appearing
**Issue**: "Live GPS" button doesn't show on rider dashboard
**Solutions**:
- Ensure delivery status is "on_way" (not "assigned" or "delivered")
- Check browser console for JavaScript errors
- Verify `rider-gps-tracker.js` is loaded correctly

### Location Not Updating
**Issue**: Rider location not updating on customer's end
**Solutions**:
1. Check browser permission settings - allow location access
2. Verify GPS accuracy with phone's native maps app
3. Check internet connection - must be stable
4. Test API endpoint directly:
   ```bash
   curl -X POST http://localhost/HydroMIS-1.3/api/delivery_tracker.php?request=update_rider_location \
     -H "Content-Type: application/json" \
     -d '{"transaction_id":"TRX-001","latitude":14.5995,"longitude":120.9842}'
   ```

### High Battery Drain
**Issue**: GPS tracking drains device battery
**Solutions**:
- Increase update interval: Change `updateInterval` in `RiderGPSTracker` constructor
- Reduce accuracy: Use `enableHighAccuracy: false` in geolocation options
- Educate riders to close app after delivery

### Inaccurate Location
**Issue**: Rider location shows incorrect coordinates
**Solutions**:
- Ensure GPS is enabled on device
- Move to open area (away from buildings)
- Wait for GPS to acquire signal (satellite fix)
- Check for spoofing apps (some devices may have location spoofing)

## Performance Optimization

### Database Queries
```sql
-- Add index for faster queries
CREATE INDEX idx_active_deliveries ON rider_locations(transaction_id, last_update);

-- Archive old data
DELETE FROM rider_locations WHERE last_update < DATE_SUB(NOW(), INTERVAL 30 DAY) AND rider_id NOT IN (
    SELECT rider_id FROM transactions WHERE delivery_status != 'delivered'
);
```

### API Caching
Consider adding Redis cache for frequently accessed locations:
```php
$cache_key = "rider_location_{$transaction_id}";
$cached = $redis->get($cache_key);
if (!$cached) {
    // Fetch from DB
    $redis->setex($cache_key, 10, json_encode($data)); // Cache for 10 seconds
}
```

## Future Enhancements

1. **Geofencing**: Alert when rider enters/exits delivery area
2. **Route Optimization**: Show optimal route to customer
3. **Estimated Arrival**: Calculate ETA based on current location
4. **Photo Proof**: Capture photo at delivery location
5. **Signature Capture**: Digital signature on delivery completion
6. **Multi-stop Routes**: Show rider progress through multiple stops
7. **Historical Playback**: Replay rider's entire route
8. **Real-time Notifications**: Push notifications for status updates

## Configuration Files

### Rider GPS Tracker Config (`js/rider-gps-tracker.js`)
```javascript
// Adjust these settings
this.updateInterval = 10000;  // Update every 10 seconds
enableHighAccuracy: true,      // Use high accuracy
timeout: 30000,                // 30 second timeout
maximumAge: 0                  // No cached positions
```

### Order Tracking Config (`user/track_order.php`)
```javascript
// In the script section
const refreshInterval = 5000;  // Poll every 5 seconds
```

## Support & Maintenance

- **Database Backup**: Regularly backup `rider_locations` table
- **Log Monitoring**: Check `VSCODE_TARGET_SESSION_LOG` for API errors
- **User Support**: Provide FAQ on geolocation permission issues
- **Testing**: Test with actual GPS devices, not just simulators

---

**Last Updated**: May 2024
**Version**: 1.0
