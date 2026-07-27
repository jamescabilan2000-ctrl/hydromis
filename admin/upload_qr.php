<?php
require_once 'check_auth.php';
require_once '../config/database.php';

header('Content-Type: application/json');

// GET — Fetch current QR settings
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $result = $conn->query("SELECT payment_method, qr_image_path, account_number, account_name, updated_at FROM payment_qr_settings ORDER BY payment_method");
    $settings = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $settings[$row['payment_method']] = [
                'qr_image_path'  => $row['qr_image_path'],
                'account_number' => $row['account_number'],
                'account_name'   => $row['account_name'],
                'updated_at'     => $row['updated_at']
            ];
        }
    }
    echo json_encode(['success' => true, 'data' => $settings]);
    exit;
}

// POST — Upload QR image and/or update account details
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $method = $_POST['payment_method'] ?? '';
    if (!in_array($method, ['gcash', 'maya'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid payment method']);
        exit;
    }

    $accountNumber = trim($_POST['account_number'] ?? '');
    $accountName   = trim($_POST['account_name'] ?? '');

    // Ensure upload directory exists
    $uploadDir = __DIR__ . '/../uploads/payment_qr/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $qrImagePath = null;

    // Handle file upload if present
    if (isset($_FILES['qr_image']) && $_FILES['qr_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['qr_image'];

        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedTypes)) {
            echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.']);
            exit;
        }

        // Validate file size (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'File too large. Maximum size is 5MB.']);
            exit;
        }

        // Generate unique filename
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        if (!$ext) {
            $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            $ext = $extMap[$mimeType] ?? 'jpg';
        }
        $filename = $method . '_qr_' . time() . '.' . $ext;
        $destination = $uploadDir . $filename;

        // Delete old uploaded QR image (not default ones in imagess/)
        $oldResult = $conn->query("SELECT qr_image_path FROM payment_qr_settings WHERE payment_method = '" . $conn->real_escape_string($method) . "' LIMIT 1");
        if ($oldResult && $oldResult->num_rows > 0) {
            $oldPath = $oldResult->fetch_assoc()['qr_image_path'];
            if (strpos($oldPath, 'uploads/payment_qr/') !== false) {
                $oldFile = __DIR__ . '/../' . $oldPath;
                if (file_exists($oldFile)) {
                    unlink($oldFile);
                }
            }
        }

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file.']);
            exit;
        }

        $qrImagePath = 'uploads/payment_qr/' . $filename;
    }

    // Build update query
    $safeMethod  = $conn->real_escape_string($method);
    $safeNumber  = $conn->real_escape_string($accountNumber);
    $safeName    = $conn->real_escape_string($accountName);

    if ($qrImagePath) {
        $safePath = $conn->real_escape_string($qrImagePath);
        $sql = "INSERT INTO payment_qr_settings (payment_method, qr_image_path, account_number, account_name)
                VALUES ('$safeMethod', '$safePath', '$safeNumber', '$safeName')
                ON DUPLICATE KEY UPDATE qr_image_path = '$safePath', account_number = '$safeNumber', account_name = '$safeName'";
    } else {
        // Update only account details, keep existing image
        $sql = "INSERT INTO payment_qr_settings (payment_method, qr_image_path, account_number, account_name)
                VALUES ('$safeMethod', '', '$safeNumber', '$safeName')
                ON DUPLICATE KEY UPDATE account_number = '$safeNumber', account_name = '$safeName'";
    }

    if ($conn->query($sql)) {
        // Fetch updated record
        $updated = $conn->query("SELECT qr_image_path, account_number, account_name FROM payment_qr_settings WHERE payment_method = '$safeMethod' LIMIT 1");
        $data = $updated ? $updated->fetch_assoc() : [];
        echo json_encode([
            'success' => true,
            'message' => ucfirst($method) . ' QR settings updated successfully!',
            'data'    => $data
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request method']);
