<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
require_once __DIR__ . '/../core/email.php';
check_login();

$err = '';
$success = '';
$messages = array();
$tables_ready = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['flush_pending'])) {
    try {
        $result = rb_email_flush_pending($mysqli, 20);
        if ($result['error'] !== '') {
            $err = $result['error'];
        } else {
            $success = 'Processed ' . (int) $result['processed'] . ' pending email(s); sent ' . (int) $result['sent'] . '.';
        }
    } catch (Throwable $e) {
        $err = 'The pending email outbox could not be processed.';
        error_log('[RuralBanking] email outbox action failed: ' . $e->getMessage());
    }
}

try {
    $result = $mysqli->query('SELECT outbox_id, recipient, subject, status, attempts, last_error, created_at, sent_at FROM ib_email_outbox ORDER BY outbox_id DESC LIMIT 100');
    if (!$result) {
        throw new RuntimeException('Could not read email outbox.');
    }
    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }
    $result->free();
} catch (Throwable $e) {
    $tables_ready = false;
    $messages = array();
    $err = 'Could not load the email outbox. Check the database connection and import database/email-notification-migration.sql.';
    error_log('[RuralBanking] email outbox read failed: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<meta http-equiv="content-type" content="text/html;charset=utf-8" />
<?php include("dist/_partials/head.php"); ?>

<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed">
  <div class="wrapper">
    <?php include("dist/_partials/nav.php"); ?>
    <?php include("dist/_partials/sidebar.php"); ?>

    <div class="content-wrapper">
      <section class="content-header">
        <div class="container-fluid">
          <div class="row mb-2"><div class="col-sm-8"><h1>Email Outbox</h1><p class="text-muted mb-0">Email only; no SMS. SMTP credentials remain private in the server's .env file.</p></div></div>
        </div>
      </section>
      <section class="content">
        <div class="container-fluid">
          <?php if ($err !== '') { ?><div class="alert alert-danger" role="alert"><?php echo rb_e($err); ?></div><?php } ?>
          <?php if ($success !== '') { ?><div class="alert alert-success" role="status"><?php echo rb_e($success); ?></div><?php } ?>
          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title">Recent notifications</h3>
              <form method="post" action="pages_email_outbox.php">
                <button class="btn btn-primary btn-sm" type="submit" name="flush_pending" value="1" <?php echo !$tables_ready ? 'disabled' : ''; ?>>Send pending emails</button>
              </form>
            </div>
            <div class="card-body p-0">
              <?php if (!$messages && $tables_ready) { ?><p class="p-3 mb-0 text-muted">The email outbox is empty.</p><?php } ?>
              <?php if ($messages) { ?>
                <div class="table-responsive">
                  <table class="table table-hover mb-0">
                    <thead><tr><th>Queued</th><th>Recipient</th><th>Subject</th><th>Status</th><th>Attempts</th><th>Last error</th></tr></thead>
                    <tbody>
                    <?php foreach ($messages as $message) { ?>
                      <tr>
                        <td><?php echo rb_e(date('d M Y H:i', strtotime($message['created_at']))); ?></td>
                        <td><?php echo rb_e($message['recipient']); ?></td>
                        <td><?php echo rb_e($message['subject']); ?></td>
                        <td><span class="badge badge-<?php echo $message['status'] === 'sent' ? 'success' : 'warning'; ?>"><?php echo rb_e($message['status']); ?></span></td>
                        <td><?php echo rb_e((int) $message['attempts']); ?></td>
                        <td><?php echo rb_e($message['last_error']); ?></td>
                      </tr>
                    <?php } ?>
                    </tbody>
                  </table>
                </div>
              <?php } ?>
            </div>
          </div>
          <p class="small text-muted">Message bodies are not shown here. Only the latest 100 delivery records are listed.</p>
        </div>
      </section>
    </div>
    <?php include("dist/_partials/footer.php"); ?>
  </div>
  <script src="plugins/jquery/jquery.min.js"></script>
  <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="dist/js/adminlte.min.js"></script>
</body>
</html>
