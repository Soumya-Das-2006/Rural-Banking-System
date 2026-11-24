<?php
if (!defined('RB_CARD_REQUESTS_PORTAL') || !in_array(RB_CARD_REQUESTS_PORTAL, array('admin', 'staff'), true)) {
    http_response_code(404);
    exit;
}

$portal = RB_CARD_REQUESTS_PORTAL;
$err = '';
$success = '';
$requests = array();
$tables_ready = true;
$flashKey = 'rb_demo_card_review_flash';
if (isset($_SESSION[$flashKey])) {
    $success = (string) $_SESSION[$flashKey];
    unset($_SESSION[$flashKey]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['card_action'])) {
    $cardId = isset($_POST['card_id']) && is_scalar($_POST['card_id'])
        ? filter_var($_POST['card_id'], FILTER_VALIDATE_INT)
        : false;
    if ($cardId === false || $cardId < 1) {
        $err = 'Choose a valid pending card request.';
    } else {
        $action = isset($_POST['card_action']) && is_scalar($_POST['card_action']) ? (string) $_POST['card_action'] : '';
        $reviewerId = isset($_SESSION[$portal . '_id']) ? (int) $_SESSION[$portal . '_id'] : 0;
        $result = rb_demo_card_staff_action($mysqli, (int) $cardId, $action, $portal, $reviewerId);
        if ($result['ok']) {
            $_SESSION[$flashKey] = $result['msg'];
            header('Location: pages_card_requests.php', true, 303);
            exit;
        }
        $err = $result['msg'];
    }
}

try {
    $stmt = $mysqli->prepare(
        "SELECT c.card_id, c.card_type, c.created_at, c.replacement_for_card_id, "
        . "client.name AS client_name, account.acc_name, account.account_number, old.card_reference AS old_reference "
        . "FROM ib_demo_cards c "
        . "LEFT JOIN ib_clients client ON client.client_id = c.client_id "
        . "LEFT JOIN ib_bankaccounts account ON account.account_id = c.account_id AND account.client_id = c.client_id "
        . "LEFT JOIN ib_demo_cards old ON old.card_id = c.replacement_for_card_id "
        . "WHERE c.card_status = 'requested' ORDER BY c.created_at ASC, c.card_id ASC"
    );
    if (!$stmt) {
        throw new RuntimeException('Could not prepare pending card requests query.');
    }
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not read pending card requests.');
    }
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }
    $stmt->close();
} catch (Throwable $e) {
    $tables_ready = false;
    $requests = array();
    $err = 'Could not load pending requests. Check the database connection and ensure database/card-management-migration.sql has been imported.';
    error_log('[RuralBanking] pending card requests read failed: ' . $e->getMessage());
}

$headPath = __DIR__ . '/' . $portal . '/dist/_partials/head.php';
$navPath = __DIR__ . '/' . $portal . '/dist/_partials/nav.php';
$sidebarPath = __DIR__ . '/' . $portal . '/dist/_partials/sidebar.php';
$footerPath = __DIR__ . '/' . $portal . '/dist/_partials/footer.php';
?>
<!DOCTYPE html>
<html lang="en">
<meta http-equiv="content-type" content="text/html;charset=utf-8" />
<?php include($headPath); ?>

<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed">
  <div class="wrapper">
    <?php include($navPath); ?>
    <?php include($sidebarPath); ?>

    <div class="content-wrapper">
      <section class="content-header">
        <div class="container-fluid">
          <div class="row mb-2">
            <div class="col-sm-8">
              <h1>Card Requests</h1>
              <p class="text-muted mb-0">Review ATM card service and replacement requests. <a href="../service_information.php">Service Information</a></p>
            </div>
          </div>
        </div>
      </section>
      <section class="content">
        <div class="container-fluid">
          <?php if ($err !== '') { ?><div class="alert alert-danger" role="alert"><?php echo rb_e($err); ?></div><?php } ?>
          <?php if ($success !== '') { ?><div class="alert alert-success" role="status"><?php echo rb_e($success); ?></div><?php } ?>
          <div class="card">
            <div class="card-header"><h3 class="card-title">Pending ATM card requests</h3></div>
            <div class="card-body p-0">
              <?php if ($tables_ready && !$requests) { ?><p class="p-3 mb-0 text-muted">There are no pending card requests.</p><?php } ?>
              <?php if ($requests) { ?>
                <div class="table-responsive">
                  <table class="table table-hover mb-0">
                    <thead><tr><th>Requested</th><th>Customer</th><th>Account</th><th>Request</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($requests as $request) { ?>
                      <tr>
                        <td><?php echo rb_e(date('d M Y H:i', strtotime($request['created_at']))); ?></td>
                        <td><?php echo rb_e($request['client_name'] ?: 'Customer record unavailable'); ?></td>
                        <td><?php echo rb_e($request['acc_name'] ?: 'Account unavailable'); ?><br><small class="text-muted">ending <?php echo rb_e(substr((string) $request['account_number'], -4)); ?></small></td>
                        <td>
                          <?php echo $request['replacement_for_card_id'] === null ? 'ATM card request' : 'ATM replacement'; ?>
                          <?php if ($request['old_reference']) { ?><br><small class="text-muted">Replacing <?php echo rb_e($request['old_reference']); ?></small><?php } ?>
                        </td>
                        <td>
                          <form method="post" action="pages_card_requests.php" class="rb-card-review-actions">
                            <input type="hidden" name="card_id" value="<?php echo rb_e((int) $request['card_id']); ?>">
                            <button class="btn btn-success btn-sm" type="submit" name="card_action" value="approve">Approve request</button>
                            <button class="btn btn-outline-danger btn-sm" type="submit" name="card_action" value="reject" onclick="return confirm('Reject this card request?');">Reject</button>
                          </form>
                        </td>
                      </tr>
                    <?php } ?>
                    </tbody>
                  </table>
                </div>
              <?php } ?>
            </div>
          </div>
        </div>
      </section>
    </div>
    <?php include($footerPath); ?>
  </div>
  <script src="plugins/jquery/jquery.min.js"></script>
  <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="dist/js/adminlte.min.js"></script>
</body>
</html>
