<?php
require_once __DIR__ . '/../InternetBanking/core/bootstrap.php';

function expect_auth_session($condition, $case)
{
    if (!$condition) {
        throw new RuntimeException('Auth session test failed: ' . $case);
    }
}

session_start();
$_SESSION['admin_id'] = 7;
$_SESSION['other_session_value'] = 'clear-on-login';
$previousSessionId = session_id();
expect_auth_session(rb_auth_session_login('staff', 12, 1000), 'successful login starts a new authenticated session');
expect_auth_session(session_id() !== $previousSessionId, 'successful login regenerates the session id');
expect_auth_session(empty($_SESSION['admin_id']) && empty($_SESSION['other_session_value']), 'successful login clears prior role and session data');
expect_auth_session($_SESSION['staff_id'] === 12, 'successful login records the authenticated user id');
expect_auth_session(rb_auth_session_is_valid('staff', 1899), 'active session is accepted before the idle timeout');
expect_auth_session($_SESSION['_rb_auth_last_activity'] === 1899, 'valid activity refreshes the idle timer');
expect_auth_session(!rb_auth_session_is_valid('admin', 1900), 'a session cannot authenticate for a different role');

$_SESSION['_rb_auth_last_activity'] = 1000;
expect_auth_session(!rb_auth_session_is_valid('staff', 1900), 'session expires at the idle timeout');

$_SESSION['_rb_auth_started_at'] = 1000;
$_SESSION['_rb_auth_last_activity'] = 1000 + RB_AUTH_ABSOLUTE_TIMEOUT_SECONDS - 1;
expect_auth_session(!rb_auth_session_is_valid('staff', 1000 + RB_AUTH_ABSOLUTE_TIMEOUT_SECONDS), 'session expires at the absolute timeout');

$_SESSION['staff_id'] = 12;
unset($_SESSION['_rb_auth_role']);
expect_auth_session(!rb_auth_session_is_valid('staff', 2000), 'legacy sessions without login metadata require sign-in again');

rb_auth_session_destroy();
expect_auth_session(session_status() === PHP_SESSION_NONE, 'logout destroys the server-side session');

echo "Authentication session tests passed.\n";
