# Staff Management Guide - Rider Assignment & GPS Tracking

## Overview
This guide explains how staff members can assign riders to deliveries and track their real-time GPS locations.

---

## 1. Rider Assignment System

### How It Works
- **Staff Role**: Approve orders → Assign riders → Monitor deliveries
- **Rider Role**: Receive assigned orders → Start delivery → Share GPS location
- **Customer Role**: Track their order → See rider location in real-time

### Access Levels

**Only Assigned Riders Can See:**
- Their assigned orders
- Live GPS on those specific orders
- Customer location details
- Delivery instructions

**Staff Can See:**
- All approved orders
- Assigned/unassigned status
- GPS tracking status (if active)
- All riders in the system

---

## 2. Assigning Riders to Orders

### Step-by-Step Process

#### 1. Open Staff Dashboard
```
URL: /staff/dashboard.php
Login with: admin or staff credentials
```

#### 2. Find Order in "Delivery Runs"
- Section shows all approved orders
- Displays order ID, customer name, and current status

#### 3. Select Rider from Dropdown
```html
<select name="rider_id">
    <!-- Shows list of active riders -->
    <option value="R-001">John Rider</option>
    <option value="R-002">Jane Delivery</option>
</select>
```

#### 4. Click "Assign Rider"
- Order is now assigned to selected rider
- Rider can now see this order in their dashboard
- Rider receives notification (if enabled)

### Visual Indicators

| Status | Icon | Meaning |
|--------|------|---------|
| Unassigned | ⚠️ | No rider assigned yet |
| Assigned | ✓ | Rider has been assigned |
| On Way | 🚗 | Rider is delivering |
| GPS Active | 📍 | Real-time location being tracked |
| Delivered | ✅ | Delivery completed |

---

## 3. Monitoring GPS Tracking

### Staff Dashboard GPS Indicators

**Green "GPS Active" Badge**
```
Appears when:
✓ Rider status is "On the Way"
✓ GPS tracking is enabled on rider's device
✓ Location is being updated
```

### What Staff Can See
- Rider name with motorcycle icon
- GPS status indicator
- Last GPS update time
- Delivery progress status

### Example Display
```
TRX-001
Juan Dela Cruz
🏍️ John Rider [GPS Active] - On Way
```

---

## 4. Workflow for Complete Delivery

### Order Lifecycle

```
1. PENDING
   └─ Customer places order
   
2. APPROVE / DENY
   └─ Staff reviews and approves
   
3. ASSIGN RIDER
   └─ Staff selects rider from dropdown
   └─ Order now appears in rider's dashboard
   
4. RIDER STARTS DELIVERY
   └─ Rider clicks "Start" button
   └─ Status changes to "On the Way"
   
5. RIDER ENABLES GPS
   └─ Rider clicks "Live GPS" button
   └─ Browser requests location permission
   └─ Location updates every 10 seconds
   └─ Customer sees live location
   
6. RIDER COMPLETES DELIVERY
   └─ Rider clicks "Complete" button
   └─ GPS automatically disables
   └─ Status changes to "Delivered"
   
7. CUSTOMER PROVIDES FEEDBACK
   └─ Customer rates delivery experience
   └─ Feedback saved to system
```

---

## 5. Database Schema

### Rider Assignment Storage

```sql
-- Transactions table includes rider assignment
ALTER TABLE transactions ADD COLUMN rider_id VARCHAR(50);
ALTER TABLE transactions ADD COLUMN assigned_rider VARCHAR(50);

-- Example: Order assigned to rider R-001
UPDATE transactions 
SET rider_id = 'R-001', assigned_rider = 'R-001'
WHERE transaction_id = 'TRX-001';
```

### GPS Location Storage

```sql
-- Rider locations table
CREATE TABLE rider_locations (
    transaction_id VARCHAR(255),      -- Which order
    rider_id VARCHAR(50),             -- Which rider
    rider_latitude DECIMAL(10, 8),    -- GPS latitude
    rider_longitude DECIMAL(11, 8),   -- GPS longitude
    last_update TIMESTAMP             -- When updated
);

-- Example: GPS update
INSERT INTO rider_locations 
VALUES ('TRX-001', 'R-001', 14.5995, 120.9842, NOW());
```

---

## 6. Verification & Testing

### Test Checklist

- [ ] Can assign rider from dropdown
- [ ] Rider sees assigned order in their dashboard
- [ ] Unassigned rider cannot see the order
- [ ] GPS button appears for "On the Way" status
- [ ] Customer sees live location updates
- [ ] GPS updates every 5-10 seconds
- [ ] Multiple riders don't see each other's orders
- [ ] Completed deliveries show in history

### Test URLs

```
Staff Dashboard:  /staff/dashboard.php
Rider Portal:     /rider/dashboard.php
Track Order:      /user/track_order.php
Test Dashboard:   /test_gps.html
```

---

## 7. Query Examples for Staff

### Get Active Riders
```sql
SELECT rider_id, full_name, contact_number 
FROM rider_users 
WHERE status = 'active'
ORDER BY full_name;
```

### Get All Assigned Orders
```sql
SELECT t.transaction_id, t.delivery_status, ru.full_name
FROM transactions t
JOIN rider_users ru ON t.rider_id = ru.rider_id
WHERE t.status = 'approved'
ORDER BY t.created_at DESC;
```

### Get Orders with GPS Tracking
```sql
SELECT 
    t.transaction_id,
    ru.full_name,
    rl.rider_latitude,
    rl.rider_longitude,
    rl.last_update
FROM transactions t
LEFT JOIN rider_users ru ON t.rider_id = ru.rider_id
LEFT JOIN rider_locations rl ON t.transaction_id = rl.transaction_id
WHERE t.delivery_status IN ('on_way', 'on_the_way')
AND rl.last_update IS NOT NULL;
```

---

## 8. Common Issues & Solutions

### Issue: Rider Not Appearing in Dropdown
**Solution:**
- Verify rider status is "active" in database
- Check rider_users table for the rider
- Confirm rider_id is properly set

**Query to Check:**
```sql
SELECT rider_id, full_name, status 
FROM rider_users 
WHERE status = 'active';
```

### Issue: Rider Can't See Assigned Order
**Solution:**
- Verify `rider_id` column in transactions table
- Confirm rider_id matches between assignment and rider_users
- Check if order status is "approved"

**Query to Check:**
```sql
SELECT transaction_id, rider_id, status, delivery_status
FROM transactions
WHERE transaction_id = 'TRX-001';
```

### Issue: GPS Not Updating for Customer
**Solution:**
- Verify rider has GPS enabled on their device
- Check rider_locations table has recent entries
- Confirm delivery_status is "on_way"
- Test with test_gps.html

**Query to Check:**
```sql
SELECT transaction_id, rider_latitude, rider_longitude, last_update
FROM rider_locations
WHERE transaction_id = 'TRX-001'
ORDER BY last_update DESC LIMIT 1;
```

---

## 9. Security & Access Control

### Authentication Flow

```
1. Staff Login (admin or staff role)
   ├─ Can see all orders
   ├─ Can assign any rider
   └─ Can view all GPS data

2. Rider Login (specific rider_id)
   ├─ Can only see their assigned orders
   ├─ Can enable GPS for their orders
   └─ Cannot see other riders' orders

3. Customer (search by mobile/ID)
   ├─ Can only see their own orders
   └─ Can only see GPS for their assigned rider
```

### Database Access Control

The system enforces this via:
- Session validation in check_auth.php
- SQL WHERE clauses filtering by rider_id
- Rider scope conditions in queries

---

## 10. Performance Tips

### Optimize Rider Assignment Query
```sql
CREATE INDEX idx_rider_id ON transactions(rider_id);
CREATE INDEX idx_delivery_status ON transactions(delivery_status);
```

### Monitor GPS Table Growth
```sql
SELECT COUNT(*) FROM rider_locations;

-- Clean up old GPS data (optional)
DELETE FROM rider_locations 
WHERE last_update < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

---

## 11. API Endpoints for Staff Integration

### Get Rider List
```
GET /api/delivery_tracker.php?request=get_all_deliveries
Response: List of all deliveries with riders
```

### Get Specific Order Location
```
GET /api/delivery_tracker.php?request=get_rider_location&transaction_id=TRX-001
Response: Rider's current location for that order
```

### Update Rider Location (Automatic)
```
POST /api/delivery_tracker.php?request=update_rider_location
Body: {transaction_id, latitude, longitude}
Response: Success/error confirmation
```

---

## 12. Reports & Analytics

### Orders by Rider
```sql
SELECT ru.full_name, COUNT(t.transaction_id) as order_count
FROM transactions t
JOIN rider_users ru ON t.rider_id = ru.rider_id
WHERE t.delivery_status = 'delivered'
GROUP BY ru.full_name
ORDER BY order_count DESC;
```

### Average Delivery Time per Rider
```sql
SELECT 
    ru.full_name,
    AVG(TIMESTAMPDIFF(MINUTE, t.created_at, t.updated_at)) as avg_minutes
FROM transactions t
JOIN rider_users ru ON t.rider_id = ru.rider_id
WHERE t.delivery_status = 'delivered'
GROUP BY ru.full_name;
```

---

## Quick Reference

| Task | Location | Steps |
|------|----------|-------|
| Assign Rider | Staff Dashboard | Select dropdown → Click "Assign Rider" |
| Monitor GPS | Staff Dashboard | Look for "GPS Active" badge |
| View Order | Rider Dashboard | Shows automatically after assignment |
| Track Order | Track Order Page | Search by mobile → See live location |
| Test System | Test GPS Page | /test_gps.html → Run tests |

---

## Support

**Issue**: Questions about assignment or GPS tracking  
**Email**: hydromis.support@gmail.com  
**Include**: Order ID, Rider name, Screenshot

**Documentation**:
- QUICK_START.md - Quick setup guide
- LIVE_GPS_SETUP.md - Technical setup
- LIVE_GPS_USER_GUIDE.md - User guide

---

**Last Updated**: May 2024  
**Version**: 1.0  
**Status**: ✅ Production Ready
