<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();
$client_id = (int) $_SESSION['client_id'];
$current_hash = hash('sha256', session_id());
$error = '';
$success = '';
$sessions = array();
$ready = true;

try {
    $mysqli->query('SELECT device_id FROM ib_login_devices LIMIT 0');
} catch (Throwable $e) {
    $ready = false;
}

if ($ready && isset($_POST['revoke_session'])) {
    $device_id = filter_var($_POST['revoke_session'], FILTER_VALIDATE_INT);
    if (!$device_id) {
        $error = 'Choose a valid session.';
    } else {
        try {
            $stmt = $mysqli->prepare('SELECT device_hash FROM ib_login_devices WHERE device_id = ? AND client_id = ? AND status = \'active\' LIMIT 1');
            $stmt->bind_param('ii', $device_id, $client_id);
            $stmt->execute();
            $stmt->bind_result($session_hash);
            $found = $stmt->fetch();
            $stmt->close();
            if (!$found) {
                throw new RuntimeException('This session is no longer active.');
            }
            $stmt = $mysqli->prepare("UPDATE ib_login_devices SET status = 'revoked', revoked_at = CURRENT_TIMESTAMP WHERE device_id = ? AND client_id = ? AND status = 'active'");
            $stmt->bind_param('ii', $device_id, $client_id);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('Could not revoke this session.');
            }
            $stmt->close();
            $success = 'Session revoked.';
            if (hash_equals($current_hash, $session_hash)) {
                $_SESSION = array();
                session_destroy();
                header('Location: pages_client_index.php?session_revoked=1');
                exit;
            }
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not revoke this session.';
            error_log('[RuralBanking] client session revocation failed: ' . $e->getMessage());
        }
    }
}

if ($ready) {
    try {
        $stmt = $mysqli->prepare('SELECT device_id, device_hash, label, ip_hash, status, first_seen, last_seen, revoked_at
                                  FROM ib_login_devices WHERE client_id = ? ORDER BY last_seen DESC LIMIT 100');
        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) { $sessions[] = $row; }
        $stmt->close();
    } catch (Throwable $e) {
        $ready = false;
        $error = 'Could not load your sign-in history.';
        error_log('[RuralBanking] client session list failed: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<meta http-equiv="content-type" content="text/html;charset=utf-8" />
<?php include("dist/_partials/head.php"); ?>
<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed">
<div class="wrapper">
  <?php include("dist/_partials/nav.php"); ?><?php include("dist/_partials/sidebar.php"); ?>
  <div class="content-wrapper">
    <section class="content-header"><div class="container-fluid"><h1>Security Center</h1><p class="text-muted">Recent sign-ins are recorded with a browser label and one-way IP hash. Revoking a session blocks it on its next request.</p></div></section>
    <section class="content"><div class="container-fluid">
      <?php if (!$ready) { ?><div class="alert alert-warning">Import <code>database/advanced-features-migration.sql</code> and sign in again to enable session history.</div><?php } ?>
      <?php if ($error !== '') { ?><div class="alert alert-danger" role="alert"><?php echo rb_e($error); ?></div><?php } ?>
      <?php if ($success !== '') { ?><div class="alert alert-success" role="status"><?php echo rb_e($success); ?></div><?php } ?>
      <div class="alert alert-info">Your password is never shown here. If you do not recognize a sign-in, revoke it and change your password. Sign-in emails are queued for email delivery only.</div>
      <div class="card"><div class="card-header"><h3 class="card-title">Sign-in and session history</h3></div><div class="card-body table-responsive">
        <table class="table table-hover"><thead><tr><th>Browser / device label</th><th>First seen</th><th>Last seen</th><th>Network token</th><th>Status</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($sessions as $login) { ?><tr><td><?php echo rb_e($login['label']); ?><?php if (hash_equals($current_hash, $login['device_hash'])) { ?> <span class="badge badge-info">Current session</span><?php } ?></td><td><?php echo rb_e($login['first_seen']); ?></td><td><?php echo rb_e($login['last_seen']); ?></td><td><code><?php echo rb_e(substr($login['ip_hash'], 0, 10)); ?>…</code></td><td><?php echo rb_e($login['status']); ?></td><td><?php if ($login['status'] === 'active') { ?><form method="post"><button class="btn btn-sm btn-outline-danger" name="revoke_session" value="<?php echo (int) $login['device_id']; ?>">Revoke</button></form><?php } ?></td></tr><?php } ?>
        <?php if (!$sessions && $ready) { ?><tr><td colspan="6" class="text-muted">No sign-in history yet. Sign out and sign in again after applying the migration to record a session.</td></tr><?php } ?>
        </tbody></table>
      </div></div>
    </div></section>
  </div>
  <?php include("dist/_partials/footer.php"); ?>
</div>
<script src="plugins/jquery/jquery.min.js"></script><script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script><script src="dist/js/adminlte.min.js"></script>
</body>
</html>
