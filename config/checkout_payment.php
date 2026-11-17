<?php
require_once __DIR__.'/storage_service.php';
require_once __DIR__.'/cart_validation.php';

/** Same wallet requirements as the existing checkout: mobile and image proof. */
function checkout_wallet_submission(string $method,array $input,array $files): array {
    if ($method==='cash') return [];
    if (!in_array($method,['gcash','maya'],true)) throw new InvalidArgumentException('Choose a payment method.');
    $number=is_string($input['wallet_number']??null)?trim($input['wallet_number']):'';
    if (!preg_match('/\A09\d{9}\z/',$number)) throw new CartValidationException(['wallet_number'=>'Enter the 11-digit mobile number used for your '.ucfirst($method).' payment.']);
    $file=$files['payment_proof']??null;
    if (!is_array($file) || !is_int($file['error']??null) || $file['error']===UPLOAD_ERR_NO_FILE) throw new CartValidationException(['payment_proof'=>'Upload your payment proof before placing the order.']);
    if (in_array($file['error'],[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true)) throw new CartValidationException(['payment_proof'=>'Payment proof exceeds the upload limit. Choose an image no larger than 5MB.']);
    if ($file['error']!==UPLOAD_ERR_OK) throw new CartValidationException(['payment_proof'=>'The screenshot upload failed. Select the file again and retry.']);
    if (!is_string($file['tmp_name']??null) || !is_uploaded_file($file['tmp_name'])) throw new CartValidationException(['payment_proof'=>'Select a valid payment screenshot.']);
    $temporary=(string)($file['tmp_name']??'');
    $size=filesize($temporary);
    if (!$size || $size>5*1024*1024) throw new CartValidationException(['payment_proof'=>'Payment proof must be a nonempty image, 5MB or smaller.']);
    $image=@getimagesize($temporary);
    $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($temporary);
    if (!$image || !isset($extensions[$image['mime']??'']) || $mime!==$image['mime']) throw new CartValidationException(['payment_proof'=>'Payment proof must be a valid JPG, PNG or WEBP image.']);
    $path='uploads/payment_proofs/'.bin2hex(random_bytes(16)).'.'.$extensions[$image['mime']];
    if (!hydromis_store_upload($temporary,$path,$image['mime'])) throw new RuntimeException('Unable to save your payment proof. Please try again.');
    return ['number'=>$number,'proof'=>$path];
}
