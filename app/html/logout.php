<?php
/**
 * logout.php
 *
 * Ends the admin session. Only accepts a POST with the CSRF token, so another
 * site can't log you out with a link.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

start_admin_session();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin.php');
    exit;
}
verify_csrf();
end_admin_session();
header('Location: admin_login.php?signed_out=1');
exit;
