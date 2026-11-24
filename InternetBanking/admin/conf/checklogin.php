<?php
/* Session guard for the admin portal. Redirects (relative, works on http and https) and stops. */
function check_login()
{
    if (empty($_SESSION['admin_id'])) {
        header('Location: pages_index.php');
        exit;
    }
}
