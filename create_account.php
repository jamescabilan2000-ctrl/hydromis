<?php
session_start();
require_once 'config/database.php';

$error = '';
$success = false;
$new_user_id = '';

// Handle Account Creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_submit'])) {
    $full_name = sanitize($_POST['full_name']);
    $barangay = sanitize($_POST['barangay']);
    $street_number = sanitize($_POST['street_number']);
    $address = $street_number . ', ' . $barangay; // Combine for storage
    $contact_number = sanitize($_POST['contact_number']);
    
    if (empty($full_name) || empty($barangay) || empty($street_number) || empty($contact_number)) {
        $error = 'All fields are required!';
    } elseif (strlen($contact_number) < 10) {
        $error = 'Contact number should be at least 10 digits!';
    } else {
        $user_id = generateID('USR');
        
        $sql = "INSERT INTO users (user_id, full_name, address, contact_number, status) 
                VALUES ('$user_id', '$full_name', '$address', '$contact_number', 'pending')";
        
        if ($conn->query($sql) === TRUE) {
            $qr_data = json_encode([
                'user_id' => $user_id,
                'full_name' => $full_name,
                'address' => $address,
                'contact_number' => $contact_number
            ]);
            
            $qr_code_url = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($qr_data);
            
            $qrcodes_dir = 'qrcodes/';
            if (!is_dir($qrcodes_dir)) {
                mkdir($qrcodes_dir, 0755, true);
            }
            
            $qr_image_path = $qrcodes_dir . $user_id . '.png';
            $qr_image_content = file_get_contents($qr_code_url);
            file_put_contents($qr_image_path, $qr_image_content);
            
            $sql = "UPDATE users SET qr_code_path = '$qr_image_path' WHERE user_id = '$user_id'";
            $conn->query($sql);
            
            $success = true;
            $new_user_id = $user_id;
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
    <title>HydroMIS - Create Account</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="css/public-ui.css" rel="stylesheet">
    <link href="css/animations.css" rel="stylesheet">
    <style>
        body.public-ui {
            min-height: 100vh;
        }
        .create-wrap {
            max-width: 1160px;
            margin: 20px auto 38px;
            padding: 0 20px;
        }
        .container-main {
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 45px rgba(8, 33, 55, 0.14);
            width: 100%;
            max-width: 680px;
            margin: 0 auto;
            overflow: hidden;
            border: 1px solid rgba(16, 38, 58, 0.08);
        }
        .header {
            text-align: center;
            padding: 64px 48px;
            background: linear-gradient(135deg, #0d3657 0%, #106780 78%);
            color: white;
        }
        .header h1 {
            font-size: 56px;
            font-weight: 700;
            margin-bottom: 18px;
            letter-spacing: -0.9px;
        }
        .header p {
            font-size: 20px;
            opacity: 0.9;
            font-weight: 500;
            letter-spacing: 0.4px;
        }
        .content {
            padding: 30px;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            color: #10263a;
            font-weight: 700;
            margin-bottom: 8px;
            font-size: 15px;
        }
        .form-group label i {
            color: #1c75bc;
        }
        .form-control {
            border-radius: 12px;
            border: 1px solid #d8e5f0;
            padding: 12px 14px;
            font-size: 15px;
            background: #f4f8fb;
            color: #10263a;
            height: 48px;
        }
        select.form-control {
            height: auto;
            padding: 12px 14px;
        }
        .form-control::placeholder {
            color: #6a8497;
        }
        .form-control:focus {
            border-color: #6da8d8;
            box-shadow: 0 0 0 0.18rem rgba(28, 117, 188, 0.15);
            background: #ffffff;
        }
        .btn-submit {
            background: linear-gradient(140deg, #0d9b8a 0%, #0f8f5f 100%);
            border: none;
            color: white;
            padding: 13px 20px;
            font-size: 16px;
            font-weight: 800;
            border-radius: 12px;
            width: 100%;
            margin-top: 10px;
            box-shadow: 0 12px 22px rgba(15, 143, 95, 0.23);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 28px rgba(15, 143, 95, 0.28);
            color: white;
        }
        .error-message {
            color: #7f1f1f;
            padding: 11px 13px;
            background: #fff0f0;
            border-radius: 10px;
            margin-bottom: 16px;
            font-size: 14px;
            border: 1px solid #efb4b4;
            border-left: 4px solid #b73333;
            font-weight: 600;
        }
        .success-box {
            text-align: center;
            padding: 10px;
        }
        .success-icon {
            font-size: 56px;
            color: #10b981;
            margin-bottom: 20px;
            animation: bounce 0.6s ease-out;
        }
        @keyframes bounce {
            0% { transform: scale(0.8); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }
        .success-message {
            color: #0f8f5f;
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 24px;
        }
        .qr-code-display {
            background: linear-gradient(135deg, #f3f4f6 0%, #f9fafb 100%);
            padding: 30px;
            border-radius: 12px;
            margin: 28px 0;
            text-align: center;
        }
        .qr-code-display p {
            color: #6b7280;
            font-weight: 600;
            margin-bottom: 16px;
            font-size: 15px;
        }
        .qr-code-display img {
            max-width: 280px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
            border: 3px solid white;
        }
        .qr-message {
            background: #edf7ff;
            padding: 14px 16px;
            border-radius: 10px;
            margin: 20px 0;
            border-left: 4px solid #1c75bc;
        }
        .qr-instruction {
            color: #3730a3;
            font-size: 14px;
            margin: 0;
            display: flex;
            align-items: flex-start;
        }
        .qr-instruction i {
            margin-right: 10px;
            margin-top: 2px;
            flex-shrink: 0;
        }
        .demo-credentials {
            background: #edf7ff;
            padding: 16px 18px;
            border-radius: 12px;
            margin-top: 24px;
            font-size: 13px;
            color: #1f4f77;
            border: 1px solid #cde3f3;
        }
        .demo-credentials strong {
            display: block;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .demo-credentials p {
            margin: 6px 0;
            color: #4338ca;
        }
        .back-link {
            text-align: center;
            margin-top: 20px;
        }
        .back-link a {
            color: #185f97;
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
            transition: color 0.3s ease;
        }
        .back-link a:hover {
            color: #124c78;
        }
        .btn-action {
            background: linear-gradient(135deg, #145c9e 0%, #1c75bc 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 700;
            width: 100%;
            margin-top: 12px;
            font-size: 15px;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 10px 20px rgba(20, 92, 158, 0.26);
        }
        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 24px rgba(20, 92, 158, 0.3);
            color: white;
            text-decoration: none;
        }
        .btn-secondary-action {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        .btn-secondary-action:hover {
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }
        @media (max-width: 768px) {
            .create-wrap {
                padding: 0 14px;
            }
            .content {
                padding: 22px 16px;
            }
            .header h1 {
                font-size: 32px;
            }
        }
    </style>
</head>
<body class="public-ui">
    <header class="topbar">
        <div class="topbar-inner">
            <a class="brand" href="home.php" aria-label="HydroMIS Home">
                <span class="brand-icon"><img src="imagess/logosystem.png" alt="HydroMIS Logo" style="width: 100%; height: 100%; object-fit: contain;"></span>
                <h1 class="brand-wordmark">HydroMIS</h1>
            </a>
        </div>
    </header>

    <main class="create-wrap">
    <div class="container-main">
        <div class="header">
            <h1>HydroMIS</h1>
            <p>Create Your Account</p>
        </div>

        <div class="content">
            <?php if ($success): ?>
                <!-- Success Message -->
                <div class="success-box">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="success-message">Account Created Successfully!</div>
                    
                    <div class="qr-code-display">
                        <p style="color: #666; margin-bottom: 15px; font-size: 13px; font-weight: 600;">Your QR Code</p>
                        <img src="qrcodes/<?php echo $new_user_id; ?>.png" alt="QR Code">
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
                            <span class="detail-label">Contact:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($_POST['contact_number']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Status:</span>
                            <span class="detail-value"><span style="background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">Pending Approval</span></span>
                        </div>
                    </div>

                    <button type="button" class="btn-action" onclick="location.reload();">
                        <i class="fas fa-plus mr-2"></i> Create Another Account
                    </button>
                    
                    <a href="user/scan_qr.php" class="btn-action btn-secondary-action">
                        <i class="fas fa-qrcode mr-2"></i> Scan QR Code
                    </a>

                    <a href="home.php" class="btn-action" style="background: #6b7280;">
                        <i class="fas fa-home mr-2"></i> Back to Home
                    </a>
                </div>
            <?php else: ?>
                <!-- Registration Form -->
                <?php if ($error): ?>
                    <div class="error-message">
                        <i class="fas fa-exclamation-circle mr-2"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="create_submit" value="1">
                    
                    <div class="form-group">
                        <label for="full_name"><i class="fas fa-user mr-2"></i>Full Name *</label>
                        <input type="text" class="form-control" name="full_name" id="full_name" required value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="barangay"><i class="fas fa-map-marker-alt mr-2"></i>Barangay *</label>
                        <select class="form-control" name="barangay" id="barangay" required>
                            <option value="">-- Select Your Barangay --</option>
                            <option value="BagongBanwa Island" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'BagongBanwa Island') ? 'selected' : ''; ?>>BagongBanwa Island</option>
                            <option value="Banlasan" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Banlasan') ? 'selected' : ''; ?>>Banlasan</option>
                            <option value="Batasan Island" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Batasan Island') ? 'selected' : ''; ?>>Batasan Island</option>
                            <option value="BilangBilangan Island" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'BilangBilangan Island') ? 'selected' : ''; ?>>BilangBilangan Island</option>
                            <option value="Bosongon" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Bosongon') ? 'selected' : ''; ?>>Bosongon</option>
                            <option value="Buenos Aires" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Buenos Aires') ? 'selected' : ''; ?>>Buenos Aires</option>
                            <option value="Bunacan" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Bunacan') ? 'selected' : ''; ?>>Bunacan</option>
                            <option value="Cabulijan" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Cabulijan') ? 'selected' : ''; ?>>Cabulijan</option>
                            <option value="Cahayag" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Cahayag') ? 'selected' : ''; ?>>Cahayag</option>
                            <option value="Cawayanan" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Cawayanan') ? 'selected' : ''; ?>>Cawayanan</option>
                            <option value="Centro" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Centro') ? 'selected' : ''; ?>>Centro</option>
                            <option value="Genonocan" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Genonocan') ? 'selected' : ''; ?>>Genonocan</option>
                            <option value="Guiwanon" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Guiwanon') ? 'selected' : ''; ?>>Guiwanon</option>
                            <option value="Ilijan Norte" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Ilijan Norte') ? 'selected' : ''; ?>>Ilijan Norte</option>
                            <option value="Ilijan Sur" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Ilijan Sur') ? 'selected' : ''; ?>>Ilijan Sur</option>
                            <option value="Libertad" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Libertad') ? 'selected' : ''; ?>>Libertad</option>
                            <option value="Macaas" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Macaas') ? 'selected' : ''; ?>>Macaas</option>
                            <option value="Matabao" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Matabao') ? 'selected' : ''; ?>>Matabao</option>
                            <option value="Mocaboc Island" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Mocaboc Island') ? 'selected' : ''; ?>>Mocaboc Island</option>
                            <option value="Panadtaran" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Panadtaran') ? 'selected' : ''; ?>>Panadtaran</option>
                            <option value="Panaytayon" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Panaytayon') ? 'selected' : ''; ?>>Panaytayon</option>
                            <option value="Pandan" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Pandan') ? 'selected' : ''; ?>>Pandan</option>
                            <option value="Pangapasan Island" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Pangapasan Island') ? 'selected' : ''; ?>>Pangapasan Island</option>
                            <option value="Pinayagan Norte" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Pinayagan Norte') ? 'selected' : ''; ?>>Pinayagan Norte</option>
                            <option value="Pinayagan Sur" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Pinayagan Sur') ? 'selected' : ''; ?>>Pinayagan Sur</option>
                            <option value="Pooc Occidental" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Pooc Occidental') ? 'selected' : ''; ?>>Pooc Occidental</option>
                            <option value="Pooc Oriental" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Pooc Oriental') ? 'selected' : ''; ?>>Pooc Oriental</option>
                            <option value="Potohan" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Potohan') ? 'selected' : ''; ?>>Potohan</option>
                            <option value="Talenceras" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Talenceras') ? 'selected' : ''; ?>>Talenceras</option>
                            <option value="Tan-awan" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Tan-awan') ? 'selected' : ''; ?>>Tan-awan</option>
                            <option value="Tinangnan" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Tinangnan') ? 'selected' : ''; ?>>Tinangnan</option>
                            <option value="Ubojan" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Ubojan') ? 'selected' : ''; ?>>Ubojan</option>
                            <option value="Ubay Island" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Ubay Island') ? 'selected' : ''; ?>>Ubay Island</option>
                            <option value="Villanueva" <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == 'Villanueva') ? 'selected' : ''; ?>>Villanueva</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="street_number"><i class="fas fa-home mr-2"></i>Street Number or Purok *</label>
                        <input type="text" class="form-control" name="street_number" id="street_number" placeholder="" required value="<?php echo isset($_POST['street_number']) ? htmlspecialchars($_POST['street_number']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="contact_number"><i class="fas fa-phone mr-2"></i>Contact Number *</label>
                        <input type="tel" class="form-control" name="contact_number" id="contact_number" placeholder="" required value="<?php echo isset($_POST['contact_number']) ? htmlspecialchars($_POST['contact_number']) : ''; ?>">
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-check mr-2"></i> Register Account
                    </button>
                </form>


                <div class="back-link">
                    <a href="home.php">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Home
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    </main>

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
