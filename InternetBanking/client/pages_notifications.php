<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();
$client_id = (int) $_SESSION['client_id'];
$error = '';
$notifications = array();
$unread_count = 0;

if (isset($_POST['mark_read'])) {
    $notification_id = filter_var($_POST['mark_read'], FILTER_VALIDATE_INT);
    if (!$notification_id || $notification_id < 1) {
        $error = 'Choose a valid notification.';
    } else {
        $stmt = $mysqli->prepare('UPDATE ib_user_notifications SET read_at = CURRENT_TIMESTAMP WHERE notification_id = ? AND client_id = ? AND read_at IS NULL');
        if (!$stmt) {
            $error = 'Could not update the notification. Import the notification migration and try again.';
            error_log('[RuralBanking] notification update prepare failed: ' . $mysqli->error);
        } else {
            $stmt->bind_param('ii', $notification_id, $client_id);
            if (!$stmt->execute()) {
                $error = 'Could not update the notification.';
                error_log('[RuralBanking] notification update failed: ' . $stmt->error);
            }
            $stmt->close();
        }
    }
}

try {
    $stmt = $mysqli->prepare('SELECT n.notification_id, n.category, n.subject, n.body, n.read_at, n.created_at, o.status AS email_status
                              FROM ib_user_notifications n
                              LEFT JOIN ib_email_outbox o ON o.outbox_id = n.email_outbox_id
                              WHERE n.client_id = ? ORDER BY n.created_at DESC LIMIT 100');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare customer notifications query: ' . $mysqli->error);
    }
    $stmt->bind_param('i', $client_id);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not load customer notifications: ' . $stmt->error);
    }
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if ($row['read_at'] === null) {
            $unread_count++;
        }
        $notifications[] = $row;
    }
    $stmt->close();
} catch (Throwable $e) {
    $error = 'Could not load notifications. Import database/qr-notification-migration.sql and check the database connection.';
    error_log('[RuralBanking] customer notification list failed: ' . $e->getMessage());
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
          <div class="row mb-2">
            <div class="col-sm-8">
              <h1>Notifications <span class="badge badge-success"><?php echo (int) $unread_count; ?> unread</span></h1>
              <p class="text-muted mb-0">Account and security notices are also queued for email. No SMS is used.</p>
            </div>
          </div>
        </div>
      </section>
      <section class="content">
        <div class="container-fluid">
          <?php if ($error !== '') { ?><div class="alert alert-danger" role="alert"><?php echo rb_e($error); ?></div><?php } ?>
          <?php if (!$notifications && $error === '') { ?><div class="card"><div class="card-body text-muted">You have no notifications yet.</div></div><?php } ?>
          <?php foreach ($notifications as $notification) { ?>
            <article class="card <?php echo $notification['read_at'] === null ? 'border-success' : ''; ?>">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                  <div>
                    <span class="badge badge-light"><?php echo rb_e(ucfirst($notification['category'])); ?></span>
                    <?php if ($notification['read_at'] === null) { ?><span class="badge badge-success">New</span><?php } ?>
                    <h5 class="mt-2 mb-1"><?php echo rb_e($notification['subject']); ?></h5>
                    <p class="mb-2"><?php echo nl2br(rb_e($notification['body'])); ?></p>
                    <small class="text-muted">
                      <?php echo rb_e(date('d M Y, h:i A', strtotime($notification['created_at']))); ?>
                      &middot; Email: <?php echo rb_e($notification['email_status'] ? ucfirst($notification['email_status']) : 'Not linked'); ?>
                    </small>
                  </div>
                  <?php if ($notification['read_at'] === null) { ?>
                    <form method="post">
                      <button class="btn btn-sm btn-outline-success" type="submit" name="mark_read" value="<?php echo (int) $notification['notification_id']; ?>">Mark read</button>
                    </form>
                  <?php } ?>
                </div>
              </div>
            </article>
          <?php } ?>
          <p class="small text-muted">Showing the latest 100 notifications.</p>
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
