<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once '../config/database.php';

$payment_success = false;
$payment_error = '';
$payment_transaction_id = '';
$transaction = null;
$user = null;

$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS payment_method VARCHAR(20) DEFAULT 'cash'");
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(255)");
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) DEFAULT 'pending'");
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS payment_date TIMESTAMP NULL");
$conn->query("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS payment_proof VARCHAR(255)");
$conn->query("CREATE TABLE IF NOT EXISTS payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    payment_id VARCHAR(255) UNIQUE NOT NULL,
    transaction_id VARCHAR(255) NOT NULL,
    user_id VARCHAR(255) NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    payment_method VARCHAR(20) NOT NULL,
    payment_reference VARCHAR(255),
    payment_status VARCHAR(20) DEFAULT 'pending',
    payment_proof VARCHAR(255),
    gcash_number VARCHAR(20),
    maya_number VARCHAR(20),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

function normalizePaymentMobile($value) {
    return preg_replace('/\D+/', '', (string) $value);
}

function loadTransactionForPayment($transactionId, $userId = '') {
    global $conn;

    if ($transactionId !== '') {
        $safeTransactionId = sanitize($transactionId);
        $result = $conn->query("SELECT * FROM transactions WHERE transaction_id = '$safeTransactionId' LIMIT 1");
    } elseif ($userId !== '') {
        $safeUserId = sanitize($userId);
        $result = $conn->query("SELECT * FROM transactions WHERE user_id = '$safeUserId' ORDER BY created_at DESC LIMIT 1");
    } else {
        return null;
    }

    if (!$result || $result->num_rows === 0) {
        return null;
    }

    return $result->fetch_assoc();
}

function savePaymentProof($fieldName, $paymentId) {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, 'Payment proof screenshot is required.'];
    }

    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return [null, 'Unable to upload payment proof. Please try again.'];
    }

    if ($_FILES[$fieldName]['size'] > 5 * 1024 * 1024) {
        return [null, 'Payment proof must be 5MB or smaller.'];
    }

    $tmpName = $_FILES[$fieldName]['tmp_name'];
    $imageInfo = @getimagesize($tmpName);
    if ($imageInfo === false) {
        return [null, 'Payment proof must be a valid image file.'];
    }

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];

    $mimeType = $imageInfo['mime'] ?? '';
    if (!isset($allowedMimeTypes[$mimeType])) {
        return [null, 'Payment proof must be a JPG, PNG, or WEBP image.'];
    }

    $uploadDir = __DIR__ . '/../uploads/payment_proofs/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        return [null, 'Payment proof storage is not available.'];
    }

    $fileName = $paymentId . '-' . time() . '.' . $allowedMimeTypes[$mimeType];
    $destination = $uploadDir . $fileName;

    if (!move_uploaded_file($tmpName, $destination)) {
        return [null, 'Unable to save payment proof. Please try again.'];
    }

    return ['uploads/payment_proofs/' . $fileName, null];
}

$requestedTransactionId = trim($_POST['transaction_id'] ?? $_GET['transaction_id'] ?? '');
$requestedUserId = trim($_POST['user_id'] ?? $_GET['user_id'] ?? '');
$transaction = loadTransactionForPayment($requestedTransactionId, $requestedUserId);

if ($transaction) {
    $payment_transaction_id = $transaction['transaction_id'];
    $userResult = $conn->query("SELECT * FROM users WHERE user_id = '" . sanitize($transaction['user_id']) . "' LIMIT 1");
    if ($userResult && $userResult->num_rows > 0) {
        $user = $userResult->fetch_assoc();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment'])) {
    if (!$transaction || !$user) {
        $payment_error = 'Transaction not found. Please open payment from your order receipt.';
    } else {
        $allowedMethods = ['cash', 'gcash', 'maya'];
        $paymentMethod = sanitize($_POST['payment_method'] ?? '');
        $amount = (float) ($transaction['amount'] ?? 0);
        $paymentReference = null;
        $paymentProof = null;
        $gcashNumber = null;
        $mayaNumber = null;
        $paymentStatus = $paymentMethod === 'cash' ? 'pending' : 'processing';
        $paymentId = generateID('PAY');

        if (!in_array($paymentMethod, $allowedMethods, true)) {
            $payment_error = 'Please select a valid payment method.';
        } elseif ($amount <= 0) {
            $payment_error = 'Invalid order amount. Please contact staff.';
        } elseif ($paymentMethod === 'gcash' || $paymentMethod === 'maya') {
            $referenceField = $paymentMethod . '_reference';
            $numberField = $paymentMethod . '_number';
            $paymentReference = sanitize(trim($_POST[$referenceField] ?? ''));
            $walletNumber = normalizePaymentMobile($_POST[$numberField] ?? '');

            if ($walletNumber === '' || !preg_match('/^(09|\+639|639)\d{9}$/', $walletNumber)) {
                $payment_error = ucfirst($paymentMethod) . ' mobile number must be a valid Philippine mobile number.';
            } elseif ($paymentReference === '' || strlen($paymentReference) < 6 || strlen($paymentReference) > 64) {
                $payment_error = ucfirst($paymentMethod) . ' reference number must be 6 to 64 characters.';
            } else {
                [$paymentProof, $uploadError] = savePaymentProof('payment_proof', $paymentId);
                if ($uploadError) {
                    $payment_error = $uploadError;
                } elseif ($paymentMethod === 'gcash') {
                    $gcashNumber = sanitize($walletNumber);
                } else {
                    $mayaNumber = sanitize($walletNumber);
                }
            }
        }

        if ($payment_error === '') {
            $safeTransactionId = sanitize($transaction['transaction_id']);
            $safeUserId = sanitize($transaction['user_id']);
            $safeMethod = sanitize($paymentMethod);
            $safeStatus = sanitize($paymentStatus);
            $safeReference = $paymentReference !== null ? "'" . sanitize($paymentReference) . "'" : "NULL";
            $safeProof = $paymentProof !== null ? "'" . sanitize($paymentProof) . "'" : "NULL";
            $safeGcash = $gcashNumber !== null ? "'" . sanitize($gcashNumber) . "'" : "NULL";
            $safeMaya = $mayaNumber !== null ? "'" . sanitize($mayaNumber) . "'" : "NULL";
            $safeNotes = $paymentMethod === 'cash'
                ? "'Cash payment to be collected and verified on delivery.'"
                : "'Manual wallet payment submitted by customer; staff verification required.'";

            $existingPayment = $conn->query("SELECT payment_id FROM payments WHERE transaction_id = '$safeTransactionId' LIMIT 1");
            if ($existingPayment && $existingPayment->num_rows > 0) {
                $existing = $existingPayment->fetch_assoc();
                $paymentId = sanitize($existing['payment_id']);
                $saved = $conn->query("UPDATE payments SET
                    amount = '$amount',
                    payment_method = '$safeMethod',
                    payment_reference = $safeReference,
                    payment_status = '$safeStatus',
                    payment_proof = $safeProof,
                    gcash_number = $safeGcash,
                    maya_number = $safeMaya,
                    notes = $safeNotes
                    WHERE payment_id = '$paymentId'");
            } else {
                $saved = $conn->query("INSERT INTO payments (
                    payment_id, transaction_id, user_id, amount, payment_method, payment_reference,
                    payment_status, payment_proof, gcash_number, maya_number, notes
                ) VALUES (
                    '$paymentId', '$safeTransactionId', '$safeUserId', '$amount', '$safeMethod',
                    $safeReference, '$safeStatus', $safeProof, $safeGcash, $safeMaya, $safeNotes
                )");
            }

            if ($saved) {
                $conn->query("UPDATE transactions SET
                    payment_method = '$safeMethod',
                    payment_reference = $safeReference,
                    payment_status = '$safeStatus',
                    payment_proof = $safeProof,
                    payment_date = NULL
                    WHERE transaction_id = '$safeTransactionId'");

                $_SESSION['payment_transaction_id'] = $safeTransactionId;
                $payment_transaction_id = $safeTransactionId;
                $payment_success = true;
            } else {
                $payment_error = 'Unable to record payment: ' . $conn->error;
            }
        }
    }
}

if (!$transaction && $payment_error === '') {
    $payment_error = 'Open this page from an order receipt or include a transaction ID.';
}

if (!$user) {
    $user = [
        'full_name' => 'Unknown customer',
        'user_id' => $transaction['user_id'] ?? '',
        'contact_number' => '',
        'address' => ''
    ];
}

include 'payment_view.php';
