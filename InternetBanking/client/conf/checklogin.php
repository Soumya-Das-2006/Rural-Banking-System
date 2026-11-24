<?php
/* Session guard for the client portal. Redirects (relative, works on http and https) and stops. */
function check_login()
{
    if (empty($_SESSION['client_id'])) {
        header('Location: pages_client_index.php');
        exit;
    }
    if (!rb_auth_session_is_valid('client')) {
        rb_auth_session_destroy();
        header('Location: pages_client_index.php?session_expired=1');
        exit;
    }
    rb_client_guard();
}

/**
 * Customers may only touch their own data. This guard runs on every client page
 * (through check_login) and rejects destructive actions and any request that
 * references an account or client id that does not belong to the signed-in customer.
 */
function rb_client_guard()
{
    $cid = (int) $_SESSION['client_id'];
    $db  = rb_mysqli();
    if (!rb_client_session_is_active($db, $cid)) {
        rb_auth_session_destroy();
        header('Location: pages_client_index.php?session_revoked=1');
        exit;
    }

    foreach (rb_csrf_actions() as $action) {
        if (isset($_GET[$action])) {
            rb_fail_page('This action is not available for customer accounts', 403);
        }
    }
    foreach (array($_GET, $_POST) as $src) {
        if (isset($src['client_id']) && (int) $src['client_id'] !== $cid) {
            rb_fail_page('You do not have access to this record', 403);
        }
        if (isset($src['account_id'])) {
            $aid = (int) $src['account_id'];
            $stmt = $db->prepare('SELECT COUNT(*) FROM ib_bankaccounts WHERE account_id = ? AND client_id = ?');
            $stmt->bind_param('ii', $aid, $cid);
            $stmt->execute();
            $stmt->bind_result($n);
            $stmt->fetch();
            $stmt->close();
            if ($n < 1) {
                rb_fail_page('You do not have access to this account', 403);
            }
        }
        if (isset($src['account_number'])) {
            $an = (string) $src['account_number'];
            $stmt = $db->prepare('SELECT COUNT(*) FROM ib_bankaccounts WHERE account_number = ? AND client_id = ?');
            $stmt->bind_param('si', $an, $cid);
            $stmt->execute();
            $stmt->bind_result($n);
            $stmt->fetch();
            $stmt->close();
            if ($n < 1) {
                rb_fail_page('You do not have access to this account', 403);
            }
        }
    }
}
