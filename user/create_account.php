<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once '../config/database.php';
require_once '../config/storage_service.php';

$success = false;
$error = '';
$new_user_id = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name = sanitize($_POST['full_name']);
    $barangay = sanitize($_POST['barangay']);
    $street_number = sanitize($_POST['street_number']);
    $address = $street_number . ', ' . $barangay; // Combine for storage
    $contact_number = sanitize($_POST['contact_number']);
    
    // Validate form
    if (empty($full_name) || empty($barangay) || empty($street_number) || empty($contact_number)) {
        $error = 'All fields are required!';
    } elseif (strlen($contact_number) < 10) {
        $error = 'Contact number should be at least 10 digits!';
    } else {
        // Generate unique user ID
        $user_id = generateUserID();
        
        // Insert user into database
        $enc_name = $conn->real_escape_string(encrypt_sensitive(htmlspecialchars_decode($full_name)));
        $enc_address = $conn->real_escape_string(encrypt_sensitive(htmlspecialchars_decode($address)));
        $enc_contact = $conn->real_escape_string(encrypt_sensitive($contact_number));
        $contact_lookup = sensitive_lookup($contact_number);
        $sql = "INSERT INTO users (user_id, full_name, address, contact_number, contact_lookup, status) 
                VALUES ('$user_id', '$enc_name', '$enc_address', '$enc_contact', '$contact_lookup', 'pending')";
        
        if ($conn->query($sql) === TRUE) {
            // Generate QR code
            $qr_data = json_encode([
                'user_id' => $user_id,
                'full_name' => $full_name,
                'address' => $address,
                'contact_number' => $contact_number
            ]);
            
            // Use qr-server.com API to generate QR code
            $qr_code_url = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($qr_data);
            
            // Save QR code file
            $qr_image_path = 'qrcodes/' . $user_id . '.png';
            
            // Download and save QR code image
            $qr_image_content = file_get_contents($qr_code_url);
            if ($qr_image_content === false || !hydromis_store_bytes($qr_image_path, $qr_image_content, 'image/png')) {
                throw new RuntimeException('Unable to store the customer QR code.');
            }
            
            // Update user with QR code path
            $sql = "UPDATE users SET qr_code_path = '$qr_image_path' WHERE user_id = '$user_id'";
            $conn->query($sql);
            
            $success = true;
            $new_user_id = $user_id;
            $_SESSION['qr_download_user_id'] = $user_id;
        } else {
            $error = 'Error creating account: ' . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account - HydroMIS</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../css/animations.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', sans-serif;
        }
        .navbar {
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(10px);
            padding: 15px 0;
        }
        .navbar-brand {
            font-size: 24px;
            font-weight: bold;
            color: white !important;
        }
        .nav-link {
            color: white !important;
            margin-left: 20px;
        }
        .container-main {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 90vh;
            padding: 20px;
        }
        .card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            max-width: 520px;
            width: 100%;
        }
        .card-body {
            padding: 40px;
        }
        .card-title {
            color: #1f2937;
            font-weight: 700;
            margin-bottom: 8px;
            font-size: 28px;
            display: flex;
            align-items: center;
        }
        .card-title i {
            color: #667eea;
            margin-right: 10px;
        }
        .card-body > p:nth-of-type(2) {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 28px;
        }
        .form-group {
            margin-bottom: 28px;
            display: flex;
            flex-direction: column;
        }
        .form-group label {
            display: flex;
            align-items: center;
            color: #1f2937;
            font-weight: 600;
            margin-bottom: 12px;
            font-size: 15px;
            letter-spacing: -0.3px;
        }
        .form-group label i {
            margin-right: 8px;
            color: #667eea;
            width: 18px;
            text-align: center;
        }
        .form-group label span {
            color: #ef4444;
            margin-left: 4px;
        }
        .form-control {
            border-radius: 10px;
            border: 1.5px solid #e5e7eb;
            padding: 13px 16px;
            font-size: 15px;
            line-height: 1.5;
            transition: all 0.3s ease;
            background: #f9fafb;
            color: #1f2937;
            height: 48px;
            display: flex;
            align-items: center;
        }
        select.form-control {
            height: auto;
            padding: 12px 16px;
        }
        select.form-control {
            cursor: pointer;
            padding-right: 16px;
            width: 100%;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-color: white;
            color: #1f2937;
        }
        select.form-control option {
            color: #1f2937;
            background-color: white;
            padding: 10px;
        }
        select.form-control option:checked {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .form-control::placeholder {
            color: #9ca3af;
        }
        .form-control:focus {
            outline: none;
            border-color: #667eea;
            background-color: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .btn-submit {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 14px 24px;
            font-size: 16px;
            border-radius: 10px;
            width: 100%;
            margin-top: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
        .btn-submit:active {
            transform: translateY(0);
        }
        .error-message {
            color: #991b1b;
            background: #fee2e2;
            border: 1.5px solid #fecaca;
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 24px;
            font-size: 14px;
            border-left: 4px solid #dc2626;
            display: flex;
            align-items: flex-start;
        }
        .error-message i {
            margin-right: 10px;
            margin-top: 2px;
            flex-shrink: 0;
        }
        .success-box {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            padding: 40px;
            text-align: center;
            max-width: 520px;
            width: 100%;
        }
        .success-icon {
            font-size: 60px;
            color: #10b981;
            margin-bottom: 20px;
        }
        .success-message {
            color: #10b981;
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 20px;
        }
        .qr-code-display {
            background: #f5f7fa;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
        }
        .qr-code-display img {
            max-width: 300px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        .user-details {
            background: #f5f7fa;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
            text-align: left;
        }
        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #ddd;
        }
        .detail-item:last-child {
            border-bottom: none;
        }
        .detail-label {
            color: #666;
            font-weight: 600;
        }
        .detail-value {
            color: #333;
            font-weight: 500;
        }
        .btn-new {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            margin-right: 10px;
        }
        .btn-new:hover {
            transform: translateY(-2px);
        }
        .btn-scan {
            background: #10b981;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }
        .btn-scan:hover {
            transform: translateY(-2px);
        }
        .error-message {
            color: #ef4444;
            padding: 12px 15px;
            background: #fee2e2;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #ef4444;
        }
        .form-width {
            max-width: 600px;
            width: 100%;
        }
    </style>
    <script src="../js/ui-protection.js" defer></script>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container-fluid">
            <span class="navbar-brand">HydroMIS</span>
            <div class="ml-auto">
                <a href="create_account.php" class="nav-link">Create Account</a>
                <a href="scan_qr.php" class="nav-link">Scan QR Code</a>
            </div>
        </div>
    </nav>

    <div class="container-main">
        <?php if ($success): ?>
        <!-- Success Message -->
        <div class="success-box">
            <div class="success-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="success-message">Account Created Successfully!</div>
            
            <div class="qr-code-display">
                <p style="color: #666; margin-bottom: 15px;">Your QR Code</p>
                <img id="qrCodeImage" src="../download_qr.php?inline=1&amp;user_id=<?php echo rawurlencode($new_user_id); ?>" alt="QR Code">
                <p><a href="../download_qr.php?user_id=<?php echo rawurlencode($new_user_id); ?>" class="btn btn-primary mt-3"><i class="fas fa-download mr-2"></i>Download QR Code</a></p>
            </div>

            <div class="user-details">
                <div class="detail-item">
                    <span class="detail-label">User ID:</span>
                    <span class="detail-value"><?php echo $new_user_id; ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Full Name:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($_POST['full_name']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Address:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($_POST['address']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Contact Number:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($_POST['contact_number']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value"><span style="background: #fef3c7; color: #92400e; padding: 4px 8px; border-radius: 4px;">Pending Approval</span></span>
                </div>
            </div>

            <div style="margin-top: 30px;">
                <button type="button" class="btn-new" onclick="window.location.href='create_account.php';">
                    <i class="fas fa-plus mr-2"></i> Create Another Account
                </button>
                <button type="button" class="btn-scan" onclick="window.location.href='scan_qr.php';">
                    <i class="fas fa-qrcode mr-2"></i> Scan QR Code
                </button>
            </div>

            <p style="color: #666; margin-top: 30px; font-size: 12px;">
                <i class="fas fa-info-circle"></i> Your account is pending approval by the admin. Please check back soon!
            </p>
        </div>

        <?php else: ?>
        <!-- Form -->
        <div class="card form-width">
            <div class="card-body">
                <h5 class="card-title">
                    <i class="fas fa-user-plus mr-2"></i> Create New Account
                </h5>

                <?php if ($error): ?>
                    <div class="error-message">
                        <i class="fas fa-exclamation-circle mr-2"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <label for="full_name"><i class="fas fa-user mr-2"></i>Full Name *</label>
                        <input type="text" class="form-control" name="full_name" id="full_name" required>
                    </div>

                    <div class="form-group">
                        <label for="barangay"><i class="fas fa-map-marker-alt mr-2"></i>Barangay *</label>
                        <select class="form-control" name="barangay" id="barangay" required>
                            <option value="">-- Select Your Barangay --</option>
                            <option value="BagongBanwa Island">BagongBanwa Island</option>
                            <option value="Banlasan">Banlasan</option>
                            <option value="Batasan Island">Batasan Island</option>
                            <option value="BilangBilangan Island">BilangBilangan Island</option>
                            <option value="Bosongon">Bosongon</option>
                            <option value="Buenos Aires">Buenos Aires</option>
                            <option value="Bunacan">Bunacan</option>
                            <option value="Cabulijan">Cabulijan</option>
                            <option value="Cahayag">Cahayag</option>
                            <option value="Cawayanan">Cawayanan</option>
                            <option value="Centro">Centro</option>
                            <option value="Genonocan">Genonocan</option>
                            <option value="Guiwanon">Guiwanon</option>
                            <option value="Ilijan Norte">Ilijan Norte</option>
                            <option value="Ilijan Sur">Ilijan Sur</option>
                            <option value="Libertad">Libertad</option>
                            <option value="Macaas">Macaas</option>
                            <option value="Matabao">Matabao</option>
                            <option value="Mocaboc Island">Mocaboc Island</option>
                            <option value="Panadtaran">Panadtaran</option>
                            <option value="Panaytayon">Panaytayon</option>
                            <option value="Pandan">Pandan</option>
                            <option value="Pangapasan Island">Pangapasan Island</option>
                            <option value="Pinayagan Norte">Pinayagan Norte</option>
                            <option value="Pinayagan Sur">Pinayagan Sur</option>
                            <option value="Pooc Occidental">Pooc Occidental</option>
                            <option value="Pooc Oriental">Pooc Oriental</option>
                            <option value="Potohan">Potohan</option>
                            <option value="Talenceras">Talenceras</option>
                            <option value="Tan-awan">Tan-awan</option>
                            <option value="Tinangnan">Tinangnan</option>
                            <option value="Ubojan">Ubojan</option>
                            <option value="Ubay Island">Ubay Island</option>
                            <option value="Villanueva">Villanueva</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="street_number"><i class="fas fa-home mr-2"></i>Street Number or Purok *</label>
                        <input type="text" class="form-control" name="street_number" id="street_number" placeholder="e.g., Blk 5 Lot 12 or Purok 3" required>
                    </div>

                    <div class="form-group">
                        <label for="contact_number"><i class="fas fa-phone mr-2"></i>Contact Number *</label>
                        <input type="tel" class="form-control" name="contact_number" id="contact_number" placeholder="09171234567" required>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-check mr-2"></i> Create Account
                    </button>
                </form>

                <hr style="margin: 30px 0;">

                <div style="text-align: center;">
                    <p style="color: #666; margin-bottom: 15px;">Already have an account?</p>
                    <a href="scan_qr.php" style="color: #667eea; text-decoration: none; font-weight: 600;">
                        <i class="fas fa-qrcode mr-2"></i> Scan Your QR Code
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        // Location Picker
        function selectLocation(location) {
            const addressField = document.getElementById('address');
            addressField.value = location + ', Philippines';
            document.getElementById('locationSearch').value = location;
            document.getElementById('locationDropdown').style.display = 'none';
            addressField.focus();
            addressField.setSelectionRange(addressField.value.length, addressField.value.length);
        }

        // Location Search
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('locationSearch');
            const dropdown = document.getElementById('locationDropdown');
            const options = dropdown.querySelectorAll('.location-option');

            searchInput.addEventListener('focus', function() {
                dropdown.style.display = 'block';
            });

            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                options.forEach(option => {
                    if (option.textContent.toLowerCase().includes(searchTerm) || searchTerm === '') {
                        option.style.display = 'block';
                    } else {
                        option.style.display = 'none';
                    }
                });
            });

            document.addEventListener('click', function(e) {
                if (!document.querySelector('.location-picker-wrapper').contains(e.target)) {
                    dropdown.style.display = 'none';
                }
            });
        });

        // Geolocation
        function getLocationAddress() {
            const btn = document.getElementById('getLocationBtn');
            const statusDiv = document.getElementById('locationStatus');
            const addressField = document.getElementById('address');
            
            btn.disabled = true;
            statusDiv.textContent = 'Getting location...';
            statusDiv.className = 'location-status';
            
            if (!navigator.geolocation) {
                statusDiv.textContent = 'Geolocation not supported by your browser';
                statusDiv.className = 'location-status error';
                btn.disabled = false;
                return;
            }
            
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    
                    // Use OpenStreetMap Nominatim API for reverse geocoding (free, no key needed)
                    fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`)
                        .then(response => response.json())
                        .then(data => {
                            const address = data.address;
                            let fullAddress = '';
                            
                            // Build address from components
                            if (address.house_number) fullAddress += address.house_number + ' ';
                            if (address.road) fullAddress += address.road + ', ';
                            if (address.neighbourhood) fullAddress += address.neighbourhood + ', ';
                            if (address.suburb) fullAddress += address.suburb + ', ';
                            if (address.city) fullAddress += address.city + ', ';
                            if (address.state) fullAddress += address.state + ' ';
                            if (address.postcode) fullAddress += address.postcode + ', ';
                            if (address.country) fullAddress += address.country;
                            
                            if (fullAddress.trim()) {
                                addressField.value = fullAddress.trim();
                                statusDiv.textContent = '✓ Location detected successfully!';
                                statusDiv.className = 'location-status success';
                            } else {
                                statusDiv.textContent = 'Could not find address for this location';
                                statusDiv.className = 'location-status error';
                            }
                            btn.disabled = false;
                        })
                        .catch(error => {
                            statusDiv.textContent = 'Error getting address: ' + error.message;
                            statusDiv.className = 'location-status error';
                            btn.disabled = false;
                        });
                },
                function(error) {
                    let errorMsg = 'Location not available';
                    if (error.code === error.PERMISSION_DENIED) {
                        errorMsg = 'Please enable location access in your browser settings';
                    } else if (error.code === error.POSITION_UNAVAILABLE) {
                        errorMsg = 'Location information is unavailable';
                    } else if (error.code === error.TIMEOUT) {
                        errorMsg = 'Location request timed out';
                    }
                    statusDiv.textContent = errorMsg;
                    statusDiv.className = 'location-status error';
                    btn.disabled = false;
                }
            );
        }
    </script>
