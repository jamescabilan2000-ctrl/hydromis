<?php
require_once 'check_auth.php';
require_once '../config/database.php';
require_once '../config/system_settings.php';
$systemLogo = system_logo_path($conn);
$conn->query("ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS profile_image VARCHAR(255) NULL");

if (empty($_SESSION['staff_account_csrf'])) {
    $_SESSION['staff_account_csrf'] = bin2hex(random_bytes(32));
}

$success = '';
$error = '';
$staffResult = $conn->query("SELECT id, admin_id, username, full_name, profile_image, created_at FROM admin_users WHERE role = 'staff' ORDER BY id ASC LIMIT 1");
$staff = $staffResult && $staffResult->num_rows ? $staffResult->fetch_assoc() : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    $username = trim((string)($_POST['username'] ?? ''));
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    $profileUpload = $_FILES['profile_image'] ?? null;
    $uploadError = '';
    $uploadedProfilePath = null;
    if ($profileUpload && (int)$profileUpload['error'] !== UPLOAD_ERR_NO_FILE) {
        if ((int)$profileUpload['error'] !== UPLOAD_ERR_OK) $uploadError = 'The profile image could not be uploaded.';
        elseif ((int)$profileUpload['size'] > 3 * 1024 * 1024) $uploadError = 'The profile image must be 3 MB or smaller.';
        else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($profileUpload['tmp_name']);
            $allowedImages = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            if (!isset($allowedImages[$mime])) $uploadError = 'Use a JPG, PNG, or WEBP profile image.';
            else {
                $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'staff_profiles';
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true)) $uploadError = 'The profile image folder could not be created.';
                else {
                    $fileName = 'staff_' . bin2hex(random_bytes(12)) . '.' . $allowedImages[$mime];
                    if (move_uploaded_file($profileUpload['tmp_name'], $uploadDirectory . DIRECTORY_SEPARATOR . $fileName)) $uploadedProfilePath = 'uploads/staff_profiles/' . $fileName;
                    else $uploadError = 'The profile image could not be saved.';
                }
            }
        }
    }

    if (!hash_equals($_SESSION['staff_account_csrf'], $token)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif ($uploadError !== '') {
        $error = $uploadError;
    } elseif ($username === '' || $fullName === '') {
        $error = 'Username and full name are required.';
    } elseif (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
        $error = 'Username must be 3–50 characters and use only letters, numbers, dots, dashes, or underscores.';
    } elseif ($newPassword !== '' && strlen($newPassword) < 8) {
        $error = 'The new password must contain at least 8 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'The new password and confirmation do not match.';
    } else {
        $currentId = (int)($staff['id'] ?? 0);
        $usernameLookup = sensitive_lookup($username);
        $duplicate = $conn->prepare("SELECT id FROM admin_users WHERE username_lookup = ? AND id <> ? LIMIT 1");
        $duplicate->bind_param('si', $usernameLookup, $currentId);
        $duplicate->execute();

        if ($duplicate->get_result()->num_rows > 0) {
            $error = 'That username is already being used by another account.';
        } elseif ($staff) {
            if ($newPassword !== '') {
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $encUsername = encrypt_sensitive($username); $encFullName = encrypt_sensitive($fullName);
                $update = $conn->prepare("UPDATE admin_users SET username = ?, username_lookup = ?, full_name = ?, password = ? WHERE id = ? AND role = 'staff'");
                $update->bind_param('ssssi', $encUsername, $usernameLookup, $encFullName, $passwordHash, $currentId);
            } else {
                $encUsername = encrypt_sensitive($username); $encFullName = encrypt_sensitive($fullName);
                $update = $conn->prepare("UPDATE admin_users SET username = ?, username_lookup = ?, full_name = ? WHERE id = ? AND role = 'staff'");
                $update->bind_param('sssi', $encUsername, $usernameLookup, $encFullName, $currentId);
            }
            if ($update->execute()) {
                if ($uploadedProfilePath !== null) {
                    $photoUpdate = $conn->prepare("UPDATE admin_users SET profile_image = ? WHERE id = ? AND role = 'staff'");
                    $photoUpdate->bind_param('si', $uploadedProfilePath, $currentId); $photoUpdate->execute();
                }
                $success = 'Staff account updated successfully.';
            }
            else $error = 'The staff account could not be updated.';
        } elseif ($newPassword === '') {
            $error = 'Set a password when creating the staff account.';
        } else {
            $staffId = 'STF-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $usernameLookup = sensitive_lookup($username); $encUsername = encrypt_sensitive($username); $encFullName = encrypt_sensitive($fullName);
            $insert = $conn->prepare("INSERT INTO admin_users (admin_id, username, username_lookup, password, full_name, role) VALUES (?, ?, ?, ?, ?, 'staff')");
            $insert->bind_param('sssss', $staffId, $encUsername, $usernameLookup, $passwordHash, $encFullName);
            if ($insert->execute()) {
                if ($uploadedProfilePath !== null) {
                    $createdId = (int)$insert->insert_id;
                    $photoUpdate = $conn->prepare("UPDATE admin_users SET profile_image = ? WHERE id = ? AND role = 'staff'");
                    $photoUpdate->bind_param('si', $uploadedProfilePath, $createdId); $photoUpdate->execute();
                }
                $success = 'The staff account was created successfully.';
            }
            else $error = 'The staff account could not be created.';
        }

        $staffResult = $conn->query("SELECT id, admin_id, username, full_name, profile_image, created_at FROM admin_users WHERE role = 'staff' ORDER BY id ASC LIMIT 1");
        $staff = $staffResult && $staffResult->num_rows ? $staffResult->fetch_assoc() : null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Account - HydroMIS</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="../css/admin-sidebar-hover.css" rel="stylesheet">
<style>
:root{--bg:#0d1117;--surface:#161b24;--surface2:#1e2533;--border:rgba(255,255,255,.09);--text:#e8edf5;--muted:#8290a3;--aqua:#2dd4bf;--blue:#3b82f6;--green:#22c55e;--red:#f43f5e;--sidebar:260px}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:'Plus Jakarta Sans',sans-serif;min-height:100vh}.shell{display:grid;grid-template-columns:var(--sidebar) 1fr;min-height:100vh}.sidebar{background:var(--surface);border-right:1px solid var(--border);padding:28px 16px;display:flex;flex-direction:column;gap:28px;position:sticky;top:0;height:100vh}.brand{display:flex;align-items:center;gap:11px;padding:0 9px}.brand b{width:38px;height:38px;display:grid;place-items:center;background:linear-gradient(135deg,#1e9e8f,#0e6d7a);border-radius:10px}.brand strong{display:block;font-size:18px}.brand span{display:block;color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:1.2px}.nav-label{color:#4e5c6e;font-size:10px;font-weight:800;letter-spacing:1.3px;text-transform:uppercase;padding:0 12px;margin:12px 0 6px}.nav-item{display:flex;gap:10px;align-items:center;padding:10px 12px;border-radius:12px;color:var(--muted);text-decoration:none;font-size:14px;margin:2px 0}.nav-item:hover{background:var(--surface2);color:var(--text)}.nav-item.active{background:var(--surface2);color:var(--aqua);font-weight:700}.sidebar-foot{margin-top:auto;padding:12px;background:var(--surface2);border-radius:13px;display:flex;align-items:center;gap:9px}.avatar{width:35px;height:35px;border-radius:9px;background:linear-gradient(135deg,var(--blue),#a78bfa);display:grid;place-items:center;font-weight:800}.sidebar-foot div:nth-child(2){min-width:0;flex:1}.sidebar-foot strong,.sidebar-foot span{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.sidebar-foot span{font-size:10px;color:var(--muted)}.sidebar-foot a{color:var(--muted)}.main{min-width:0}.topbar{height:67px;padding:0 28px;border-bottom:1px solid var(--border);background:var(--surface);display:flex;align-items:center;color:var(--muted);font-size:13px}.content{padding:32px;max-width:980px}.heading{margin-bottom:24px}.heading h1{font-size:27px;margin:0 0 6px}.heading p{color:var(--muted);margin:0}.notice{border:1px solid;padding:12px 14px;border-radius:12px;margin-bottom:18px;font-size:13px}.notice.success{color:#86efac;background:rgba(34,197,94,.1);border-color:rgba(34,197,94,.25)}.notice.error{color:#fda4af;background:rgba(244,63,94,.1);border-color:rgba(244,63,94,.25)}.card{background:var(--surface);border:1px solid var(--border);border-radius:18px;overflow:hidden}.card-head{padding:20px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:14px}.card-icon{width:43px;height:43px;border-radius:12px;background:rgba(45,212,191,.12);color:var(--aqua);display:grid;place-items:center;font-size:18px}.card-head h2{margin:0;font-size:16px}.card-head p{margin:4px 0 0;color:var(--muted);font-size:12px}.badge{margin-left:auto;color:var(--aqua);background:rgba(45,212,191,.1);padding:6px 10px;border-radius:99px;font-size:10px;font-weight:800;text-transform:uppercase}.form{padding:24px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.field.full{grid-column:1/-1}.field label{display:block;font-size:12px;font-weight:700;margin-bottom:7px}.field input{width:100%;border:1px solid var(--border);background:var(--surface2);color:var(--text);border-radius:11px;padding:12px 13px;font:inherit;outline:none}.field input:focus{border-color:var(--aqua);box-shadow:0 0 0 3px rgba(45,212,191,.08)}.field small{display:block;color:var(--muted);margin-top:6px;font-size:10px}.readonly{opacity:.7}.actions{display:flex;justify-content:flex-end;margin-top:24px;padding-top:20px;border-top:1px solid var(--border)}button{border:0;border-radius:11px;background:var(--aqua);color:#082f2d;padding:11px 18px;font:700 13px inherit;cursor:pointer}button:hover{filter:brightness(1.08)}@media(max-width:800px){.shell{grid-template-columns:1fr}.sidebar{position:static;height:auto}.content{padding:20px}.grid{grid-template-columns:1fr}.field.full{grid-column:auto}}
.profile-upload{display:flex;align-items:center;gap:16px;padding:14px;border:1px dashed var(--border);border-radius:13px;background:var(--surface2)}.profile-preview{width:76px;height:76px;flex:0 0 76px;border-radius:50%;object-fit:cover;background:var(--bg);border:3px solid rgba(45,212,191,.32)}.profile-picker{display:inline-flex!important;align-items:center;gap:8px;margin-bottom:5px!important;padding:10px 13px;border-radius:10px;background:rgba(59,130,246,.13);color:#82b4ff!important;cursor:pointer;font-size:12px;font-weight:700}.profile-picker:hover{background:rgba(59,130,246,.22)}.profile-picker input{position:absolute!important;width:1px!important;height:1px!important;opacity:0;pointer-events:none}html[data-admin-color-mode="light"] .profile-picker{color:#1d5fc4!important;background:#e4efff!important}
</style>
<script src="../js/ui-protection.js" defer></script>
    <link rel="stylesheet" href="../css/admin-theme.css">
    <script src="../js/admin-theme.js"></script>
</head>
<body><div class="shell">
<aside class="sidebar">
    <div class="brand"><b><img src="../<?php echo htmlspecialchars($systemLogo); ?>" alt="HydroMIS logo" style="width:25px;height:25px;object-fit:contain;"></b><div><strong>HydroMIS</strong><span>Admin</span></div></div>
    <nav>
        <div class="nav-label">Main</div>
        <a class="nav-item" href="dashboard.php"><i class="fas fa-chart-pie"></i> Dashboard</a>
        <a class="nav-item" href="transactions.php"><i class="fas fa-exchange-alt"></i> Transactions</a>
        <a class="nav-item" href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a>
        <a class="nav-item" href="inventory.php"><i class="fas fa-boxes-stacked"></i> Inventory</a>
        <div class="nav-label">People</div>
        <a class="nav-item" href="users.php"><i class="fas fa-users"></i> Users</a>
        <a class="nav-item active" href="staff_account.php"><i class="fas fa-user-shield"></i> Staff Account</a>
        <a class="nav-item" href="manage_riders.php"><i class="fas fa-motorcycle"></i> Riders</a>
        <div class="nav-label">System</div>
        <a class="nav-item" href="activity_logs.php"><i class="fas fa-clock-rotate-left"></i> Activity Log</a>
        <a class="nav-item" href="dashboard.php?open_settings=1"><i class="fas fa-cog"></i> Settings</a>
    </nav>
    <div class="sidebar-foot"><div class="avatar"><?php echo htmlspecialchars(strtoupper(substr($_SESSION['full_name'] ?? 'A',0,1))); ?></div><div><strong><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Administrator'); ?></strong><span>Administrator</span></div><a href="../logout.php" title="Logout"><i class="fas fa-sign-out-alt"></i></a></div>
</aside>
<main class="main"><div class="topbar"><i class="fas fa-home" style="margin-right:8px"></i> Admin &nbsp;/&nbsp; Staff Account</div>
<div class="content">
    <div class="heading"><h1>Staff Account</h1><p>Manage the single staff login allowed for this system.</p></div>
    <?php if ($success): ?><div class="notice success"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice error"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <section class="card">
        <div class="card-head"><div class="card-icon"><i class="fas fa-user-shield"></i></div><div><h2><?php echo $staff ? 'Edit staff credentials' : 'Create staff credentials'; ?></h2><p>Changes take effect on the staff's next login.</p></div><span class="badge">One account only</span></div>
        <form class="form" method="post" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['staff_account_csrf']); ?>">
            <div class="grid">
                <div class="field full"><label>Profile image</label><div class="profile-upload"><img class="profile-preview" id="staff-profile-preview" src="<?php echo !empty($staff['profile_image']) ? '../' . htmlspecialchars($staff['profile_image']) : 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2276%22 height=%2276%22%3E%3Crect width=%2276%22 height=%2276%22 rx=%2238%22 fill=%22%23242d3d%22/%3E%3Ccircle cx=%2238%22 cy=%2229%22 r=%2213%22 fill=%22%232dd4bf%22/%3E%3Cpath d=%22M15 68c2-15 12-23 23-23s21 8 23 23%22 fill=%22%232dd4bf%22/%3E%3C/svg%3E'; ?>" alt="Staff profile preview"><div><label class="profile-picker" for="staff-profile-image"><i class="fas fa-camera"></i> Choose profile image<input id="staff-profile-image" name="profile_image" type="file" accept="image/jpeg,image/png,image/webp"></label><small>JPG, PNG, or WEBP. Maximum size: 3 MB.</small></div></div></div>
                <div class="field"><label>Staff ID</label><input class="readonly" value="<?php echo htmlspecialchars($staff['admin_id'] ?? 'Generated automatically'); ?>" readonly></div>
                <div class="field"><label for="staff-name">Full name</label><input id="staff-name" name="full_name" maxlength="255" required value="<?php echo htmlspecialchars($staff['full_name'] ?? ''); ?>"></div>
                <div class="field full"><label for="staff-username">Username</label><input id="staff-username" name="username" maxlength="50" required value="<?php echo htmlspecialchars($staff['username'] ?? ''); ?>"><small>Used on the Staff option of the login page.</small></div>
                <div class="field"><label for="staff-password">New password</label><input id="staff-password" name="new_password" type="password" minlength="8" placeholder="<?php echo $staff ? 'Leave blank to keep current password' : 'At least 8 characters'; ?>"><small><?php echo $staff ? 'Only fill this in when resetting the password.' : 'Required when creating the account.'; ?></small></div>
                <div class="field"><label for="staff-confirm">Confirm new password</label><input id="staff-confirm" name="confirm_password" type="password" minlength="8" placeholder="Repeat new password"></div>
            </div>
            <div class="actions"><button type="submit"><i class="fas fa-floppy-disk"></i> <?php echo $staff ? 'Save staff account' : 'Create staff account'; ?></button></div>
        </form>
    </section>
</div></main></div><script>document.getElementById('staff-profile-image')?.addEventListener('change',function(){const file=this.files&&this.files[0];if(!file)return;const reader=new FileReader();reader.onload=e=>document.getElementById('staff-profile-preview').src=e.target.result;reader.readAsDataURL(file)});</script></body></html>
