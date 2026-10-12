<?php
// Run with C:/xampp/php/php.exe tests/guest_ordering.php.
// Uses a newly created local test database, never the application database.
define('HYDROMIS_DATABASE_LIBRARY_ONLY', true);
putenv('HYDROMIS_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/guest_ordering.php';
require_once __DIR__ . '/../config/order_contact.php';
require_once __DIR__ . '/../config/inventory_service.php';
require_once __DIR__ . '/../config/loyalty_service.php';

function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$valid = ['full_name'=>"María O'Neill",'contact_number'=>'09123456789','barangay'=>'','purok'=>''];
[$data,$errors] = validate_order_contact($valid, 'pickup');
expect(!$errors && $data['address'] === '', 'Pickup must accept no address.');
expect(count(validate_order_contact($valid, 'delivery')[1]) === 2, 'Delivery must require barangay and purok.');
$delivery = array_replace($valid, ['barangay'=>'Centro','purok'=>'Purok 2, House #10']);
expect(!validate_order_contact($delivery, 'delivery')[1], 'Valid delivery contact rejected.');
foreach (['Juan123','<script>','A',str_repeat('a',101)] as $name) expect((bool)validate_order_contact(array_replace($valid,['full_name'=>$name]),'pickup')[1], 'Invalid name accepted.');
foreach (['0912345678','091234567890','08123456789','09abc456789'] as $mobile) expect((bool)validate_order_contact(array_replace($valid,['contact_number'=>$mobile]),'pickup')[1], 'Invalid mobile accepted.');
expect((bool)validate_order_contact(array_replace($valid,['barangay'=>'Invented']),'pickup')[1], 'Optional address still requires validation.');
expect((bool)validate_order_contact(array_replace($delivery,['purok'=>"Purok\n2"]),'delivery')[1], 'Control characters accepted in address.');
echo "Contact validation checks passed.\n";

$cfg = hydromis_database_config();
// Optional credentials for a separately started disposable MySQL instance.
if (getenv('HYDROMIS_TEST_DB_PORT')) $cfg = ['host'=>'127.0.0.1','port'=>getenv('HYDROMIS_TEST_DB_PORT'),'user'=>'root','password'=>'','database'=>''];
if (!in_array($cfg['host'], ['localhost','127.0.0.1','::1'], true)) { echo "Database integration skipped: requires local MySQL.\n"; exit; }
mysqli_report(MYSQLI_REPORT_OFF);
$raw = @new mysqli($cfg['host'], $cfg['user'], $cfg['password'], '', (int)$cfg['port']);
if ($raw->connect_error) { fwrite(STDERR,"Local test database connection unavailable.\n"); exit(2); }
$testDb = 'hydromis_guest_test_' . bin2hex(random_bytes(6));
expect($testDb !== $cfg['database'] && preg_match('/\Ahydromis_guest_test_[a-f0-9]{12}\z/', $testDb) === 1, 'Unsafe test database name.');
expect($raw->query("CREATE DATABASE `$testDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci") === true, 'Unable to create isolated test database.');
try {
    $conn = new DBCompatConnection($cfg['host'],$cfg['user'],$cfg['password'],$testDb,(int)$cfg['port']);
    enforce_annual_loyalty_reset($conn);
    // Exercise migration of older installations that have the account FK.
    expect($conn->query('ALTER TABLE transactions ADD CONSTRAINT test_customer_fk FOREIGN KEY (user_id) REFERENCES users(user_id)') === true, 'Test FK setup failed.');
    ensure_guest_ordering_schema($conn); // Fresh installation uses one table.
    expect($conn->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guest_customers'")->num_rows===0,'Fresh install created separate guest storage.');
    expect($conn->query("CREATE TABLE guest_customers (user_id VARCHAR(50) PRIMARY KEY, full_name TEXT,contact_number TEXT,address TEXT,barangay VARCHAR(512),purok VARCHAR(512),access_token_hash CHAR(64),created_at DATETIME DEFAULT CURRENT_TIMESTAMP)"), 'Legacy guest table setup failed.');
    $legacyName=encrypt_sensitive('Legacy Guest'); $legacyMobile=encrypt_sensitive('09111111111'); $legacyHash=hash('sha256','legacy-private-token');
    $seed=$conn->prepare("INSERT INTO guest_customers (user_id,full_name,contact_number,address,barangay,purok,access_token_hash) VALUES ('GUEST-LEGACY',?,?,'','','',?)");
    $seed->bind_param('sss',$legacyName,$legacyMobile,$legacyHash);
    expect($seed->execute(),'Legacy guest setup failed.');
    expect($conn->query("UPDATE system_settings SET setting_value='1' WHERE setting_key='guest_ordering_schema_version'"),'Legacy version setup failed.');
    ensure_guest_ordering_schema($conn);
    ensure_guest_ordering_schema($conn); // Idempotent.
    $migrated=$conn->query("SELECT * FROM users WHERE user_id='GUEST-LEGACY'")->fetch_assoc();
    expect($migrated && $migrated['full_name']==='Legacy Guest' && $migrated['access_token_hash']===$legacyHash && $migrated['customer_type']==='guest','Legacy guest identity or tracking token was lost.');
    expect($conn->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guest_customers'")->num_rows===0,'Separate guest table was not removed.');
    ensure_inventory_schema($conn);
    $guestId = 'GUEST-' . strtoupper(bin2hex(random_bytes(16)));
    $hash = hash('sha256', bin2hex(random_bytes(32)));
    $name = encrypt_sensitive($valid['full_name']); $mobile=encrypt_sensitive($valid['contact_number']); $address='';
    $stmt=$conn->prepare("INSERT INTO users (user_id,full_name,contact_number,address,access_token_hash,customer_type,status) VALUES (?,?,?,?,?,'guest','approved')");
    $stmt->bind_param('sssss',$guestId,$name,$mobile,$address,$hash);
    expect($stmt->execute(), 'Guest insert failed.');
    expect((int)$conn->query("SELECT COUNT(*) AS n FROM users WHERE user_id='$guestId'")->fetch_assoc()['n'] === 1, 'Guest is missing from the shared customer table.');
    $guest=$conn->query("SELECT * FROM order_customers WHERE user_id='$guestId'")->fetch_assoc();
    expect($guest['full_name']===$valid['full_name'] && $guest['customer_type']==='guest', 'Directory identity/decryption failed.');
    expect($conn->query("SELECT user_id FROM users WHERE user_id='$guestId' AND customer_type='registered'")->num_rows===0,'Guest can enter registered account flow.');
    expect($guest['loyalty_points']==0, 'Guest initialized with loyalty points.');
    expect($conn->query("UPDATE inventory_items SET quantity=10 WHERE item_code='CNT-25-SLIM'"), 'Stock setup failed.');
    expect($conn->query("INSERT INTO transactions (transaction_id,user_id,amount,quantity,container_size,container_status,fulfillment_method) VALUES ('TXN-GUEST-TEST','$guestId',60,2,'2.5gal-slim','existing','pickup')"), 'Guest order insertion failed.');
    expect(order_contact_snapshot($conn,'TXN-GUEST-TEST',$guest,$data,'staff_guest','STAFF-TEST'), 'Contact snapshot failed.');
    [$ok,$message]=approve_or_cancel_order_for_stock($conn,'TXN-GUEST-TEST','STAFF-TEST');
    expect($ok,$message);
    $order=$conn->query("SELECT * FROM transactions WHERE transaction_id='TXN-GUEST-TEST'")->fetch_assoc();
    expect((int)$order['loyalty_points_earned']===0 && $order['delivery_status']==='ready_for_pickup', 'Guest points/pickup status incorrect.');
    expect($order['created_by_staff_id']==='STAFF-TEST' && $order['customer_name']===$valid['full_name'], 'Staff attribution/snapshot failed.');
    expect((int)$conn->query("SELECT quantity FROM inventory_items WHERE item_code='CNT-25-SLIM'")->fetch_assoc()['quantity']===8, 'Approval failed to reserve stock.');
    expect(!approve_or_cancel_order_for_stock($conn,'TXN-GUEST-TEST','STAFF-TEST')[0], 'Duplicate approval accepted.');
    expect(deny_order_and_release_stock($conn,'TXN-GUEST-TEST','STAFF-TEST'), 'Cancellation failed.');
    expect((int)$conn->query("SELECT quantity FROM inventory_items WHERE item_code='CNT-25-SLIM'")->fetch_assoc()['quantity']===10, 'Cancellation failed to release stock.');
    $raw->select_db($testDb);
    $stored=$raw->query("SELECT customer_name,customer_contact FROM transactions WHERE transaction_id='TXN-GUEST-TEST'")->fetch_assoc();
    expect(DataSecurity::isEncrypted($stored['customer_name']) && DataSecurity::isEncrypted($stored['customer_contact']), 'Snapshot stored personal data without encryption.');
    foreach ([
        "SELECT t.*, COALESCE(t.customer_name,u.full_name) AS full_name,COALESCE(t.customer_contact,u.contact_number) AS contact_number FROM transactions t JOIN order_customers u ON t.user_id=u.user_id",
        "SELECT t.*,COALESCE(t.customer_address,u.address) AS address,u.email,u.contact_lookup FROM transactions t JOIN order_customers u ON t.user_id=u.user_id",
        "SELECT t.transaction_id,COALESCE(COALESCE(t.customer_name,u.full_name),'Unknown Customer') AS customer_name FROM transactions t LEFT JOIN order_customers u ON t.user_id=u.user_id"
    ] as $sql) expect($conn->query($sql)!==false, 'Order list query failed: '.$conn->error);
    // Registered ordering retains its points behavior.
    $registered='USR-TEST'; $lookup=sensitive_lookup('09999999999');
    $testPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    expect($conn->query("INSERT INTO users (user_id,full_name,contact_number,contact_lookup,status,password) VALUES ('$registered','Registered Test','09999999999','$lookup','approved','$testPassword')"), 'Registered test account failed.');
    expect($conn->query("INSERT INTO transactions (transaction_id,user_id,amount,quantity,container_size,container_status,fulfillment_method) VALUES ('TXN-REGISTERED-TEST','$registered',30,1,'2.5gal-slim','existing','delivery')"), 'Registered test order failed.');
    expect(approve_or_cancel_order_for_stock($conn,'TXN-REGISTERED-TEST','STAFF-TEST')[0], 'Registered approval failed.');
    $points=$conn->query("SELECT loyalty_points FROM users WHERE user_id='$registered'")->fetch_assoc()['loyalty_points'];
    expect((int)$points===system_int_setting($conn,'points_per_gallon',1,0,100), 'Registered points behavior changed.');
    echo "Guest migration, encryption, order visibility, stock, cancellation, staff attribution and loyalty checks passed.\n";
} catch (Throwable $failure) {
    if (isset($conn) && $conn->error !== '') fwrite(STDERR, 'Database check failed: ' . $conn->error . "\n");
    throw $failure;
} finally {
    if (isset($conn)) $conn->close();
    // Only the generated, verified test database is removed.
    $raw->query("DROP DATABASE `$testDb`");
    $raw->close();
}
