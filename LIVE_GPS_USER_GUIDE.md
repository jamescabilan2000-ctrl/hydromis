# Live GPS Tracking - Quick Reference Guide

## For Riders

### How to Share Your Live Location

1. **Navigate to Active Delivery**
   - Log in to rider portal
   - Go to "My Orders" tab
   - Find your delivery that's "On the Way"

2. **Start GPS Tracking**
   - Click the blue **"Live GPS"** button on the delivery row
   - Browser will ask for permission to access your location
   - **Click "Allow"** when prompted

3. **Monitor Tracking Status**
   - Once enabled, you'll see:
     - ✅ Green checkmark with coordinates
     - 📍 GPS coordinates (latitude, longitude)
     - 📏 Accuracy radius (±Xm)
     - ⏱️ Last update time

4. **Stop Tracking**
   - Click **"Stop GPS"** button to disable location sharing
   - Location will no longer be sent to customers

### ⚠️ Important Notes

- **Battery**: Keep screen on during delivery for best tracking
- **Network**: Requires stable internet/mobile data connection
- **Accuracy**: Works best in open areas, weaker in tunnels/buildings
- **Privacy**: Location only shared during "On the Way" status

---

## For Customers

### How to Track Your Order

1. **Go to Track Order Page**
   - Visit HydroMIS "Track Your Order" section
   - Or directly: `/user/track_order.php`

2. **Search for Your Order**
   - Enter your mobile number or User ID
   - Click "Search Orders"

3. **View Live Location**
   - See the map with rider's current location
   - Blue location shows rider's position
   - Coordinates update every 5 seconds
   - View rider's contact information

4. **Monitor Progress**
   - Step tracker shows delivery status:
     - ✅ Confirmed
     - ✅ Preparing
     - 🚗 On the Way (live tracking active)
     - Delivered

### 📊 Live Tracking Display

**Shows:**
- 📍 Rider's GPS coordinates
- 🎯 Your delivery address
- ⏰ Last location update time
- 👤 Rider's name and phone
- 📱 Call button to contact rider

---

## Troubleshooting

### Rider: GPS Button Not Showing?
**Solution**: 
- Delivery must be in "On the Way" status
- Click "Start" button to change status first
- Check browser console (F12) for errors

### Rider: Location Not Updating?
**Solution**:
- Check that GPS is enabled on phone
- Move to open area away from buildings
- Check internet connection
- Try starting GPS again

### Customer: Location Not Appearing?
**Solution**:
- Refresh page (F5)
- Wait 5-10 seconds for initial update
- Check if rider has started GPS tracking
- Clear browser cache

### Rider: Battery Draining Fast?
**Solution**:
- Stop GPS after delivery complete
- Reduce screen brightness
- Close other apps using location
- Enable battery saver mode

---

## Features

✅ Real-time GPS location sharing  
✅ Automatic updates every 10 seconds (rider) / 5 seconds (customer)  
✅ GPS accuracy display (±Xm)  
✅ Mobile phone contact button  
✅ Delivery progress tracking  
✅ Historical delivery timeline  

---

## Browser Support

| Browser | Support | Notes |
|---------|---------|-------|
| Chrome | ✅ Yes | Full support |
| Firefox | ✅ Yes | Full support |
| Safari | ✅ Yes | Requires iOS 14.5+ |
| Edge | ✅ Yes | Full support |
| IE 11 | ❌ No | Not supported |
| Mobile Chrome | ✅ Yes | Best accuracy |
| Mobile Safari | ✅ Yes | Best accuracy |

---

## Privacy & Security

- **Location Data**: Only visible for active deliveries
- **Encryption**: Uses HTTPS (if enabled)
- **Duration**: Automatically stops after delivery complete
- **Access**: Only customers of that specific order can see location
- **Storage**: Location data retained for tracking history

---

## Common Questions

### Q: How accurate is the GPS?
**A**: Typically ±10-30 meters in urban areas, ±5-10 meters with good satellite signal

### Q: Will this drain my battery?
**A**: High-accuracy GPS can drain 10-15% per hour. Disable after delivery.

### Q: Is my location stored permanently?
**A**: Location data kept for order history. You can request deletion if needed.

### Q: Can I share my location with others?
**A**: No, only assigned customers for that specific delivery can see it.

### Q: What if GPS doesn't work?
**A**: Location won't update. Try different area or enable high accuracy mode.

### Q: Why is location sometimes inaccurate?
**A**: Buildings, trees, tunnels, and weather affect GPS accuracy. Move to open area.

---

## Contact Support

- **Email**: hydromis.support@gmail.com
- **Issue**: Live GPS not working
- **Include**: 
  - Device type (iPhone/Android/etc)
  - Browser name and version
  - Your location/area
  - Screenshot of error (if any)

---

**Last Updated**: May 2024  
**Version**: 1.0
