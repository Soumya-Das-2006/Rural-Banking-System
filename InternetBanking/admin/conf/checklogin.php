<?php
/* Session guard for the admin portal. Redirects (relative, works on http and https) and stops. */
function check_login()
{
    if (empty($_SESSION['admin_id'])) {
        header('Location: pages_index.php');
        exit;
    }
    if (!rb_auth_session_is_valid('admin')) {
        rb_auth_session_destroy();
        header('Location: pages_index.php?session_expired=1');
        exit;
    }
}
