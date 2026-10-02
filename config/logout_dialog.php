<?php
if (defined('HYDROMIS_LOGOUT_DIALOG_RENDERED')) return;
define('HYDROMIS_LOGOUT_DIALOG_RENDERED', true);
if (empty($_SESSION['logout_csrf'])) $_SESSION['logout_csrf'] = bin2hex(random_bytes(32));
$logoutDialogRole = str_contains(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/staff/') ? 'staff' : 'admin';
$logoutDialogName = $_SESSION[$logoutDialogRole . '_auth_full_name'] ?? $_SESSION['full_name'] ?? ucfirst($logoutDialogRole);
?>
<?php if ($logoutDialogRole === 'staff'): ?>
<script src="../js/staff-pending-notifications.js" defer></script>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const role = <?php echo json_encode($logoutDialogRole); ?>;
    const name = <?php echo json_encode($logoutDialogName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const token = <?php echo json_encode($_SESSION['logout_csrf']); ?>;
    const dialog = document.createElement('dialog');
    dialog.setAttribute('aria-labelledby', 'accountLogoutTitle');
    dialog.setAttribute('aria-describedby', 'accountLogoutDescription');
    dialog.className = 'account-logout-dialog';
    dialog.innerHTML = '<h2 id="accountLogoutTitle"></h2><strong id="accountLogoutName"></strong><p id="accountLogoutDescription">You can sign in again anytime. Your saved work will remain available.</p><form method="POST" action="../logout.php"><input type="hidden" name="csrf_token"><input type="hidden" name="logout_role"><div class="account-logout-actions"><button type="button" class="account-logout-stay">Stay signed in</button><button type="submit" class="account-logout-confirm">Log out</button></div></form>';
    dialog.querySelector('h2').textContent = 'Log out of your ' + role + ' account?';
    dialog.querySelector('#accountLogoutName').textContent = name;
    dialog.querySelector('[name="csrf_token"]').value = token;
    dialog.querySelector('[name="logout_role"]').value = role;
    const style = document.createElement('style');
    style.textContent = '.account-logout-dialog{width:min(92vw,400px);margin:auto;padding:24px;border:1px solid #dce6e5;border-radius:20px;background:#fff;color:#172532;box-shadow:0 24px 65px #18364133;font-family:Arial,sans-serif}.account-logout-dialog::backdrop{background:#08182699}.account-logout-dialog h2{margin:0 0 12px;font-size:21px;line-height:1.3}.account-logout-dialog p{margin:12px 0;color:#526777;font-size:14px;line-height:1.6}.account-logout-actions{display:flex;gap:10px;margin-top:20px}.account-logout-actions button{flex:1;min-height:48px;padding:10px;border:1px solid #d7e0e3;border-radius:11px;font:600 13px Arial,sans-serif;cursor:pointer}.account-logout-stay{background:#eef5f7;color:#23445d}.account-logout-confirm{background:#dc2626;color:white;border-color:#dc2626!important}.account-logout-actions button:focus-visible{outline:3px solid #38bdf8;outline-offset:3px}';
    document.head.appendChild(style);
    document.body.appendChild(dialog);
    let opener = null;
    document.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (!link || new URL(link.href, location.href).pathname.split('/').pop() !== 'logout.php') return;
        event.preventDefault();
        opener = link;
        dialog.showModal();
        dialog.querySelector('.account-logout-stay').focus();
    });
    dialog.querySelector('.account-logout-stay').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => opener?.focus());
    dialog.querySelector('form').addEventListener('submit', () => {
        const button = dialog.querySelector('.account-logout-confirm');
        button.disabled = true;
        button.textContent = 'Logging out…';
    });
});
</script>
