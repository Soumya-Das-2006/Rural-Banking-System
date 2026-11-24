<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();

$error = '';
$success = '';
$profiles = array();
$payments = array();
$requests = array();

if (isset($_POST['cancel_payment_request'])) {
    $request_id = filter_var($_POST['cancel_payment_request'], FILTER_VALIDATE_INT);
    if (!$request_id) {
        $error = 'Choose a valid payment request.';
    } else {
        try {
            $stmt = $mysqli->prepare("UPDATE ib_payment_requests SET status = 'cancelled'
                                      WHERE request_id = ? AND status = 'pending'");
            $stmt->bind_param('i', $request_id);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                throw new RuntimeException('This payment request is no longer pending.');
            }
            $stmt->close();
            $success = 'Pending payment request cancelled.';
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not cancel this payment request.';
            error_log('[RuralBanking] admin payment request cancel failed: ' . $e->getMessage());
        }
    }
}

if (isset($_POST['set_qr_status'], $_POST['qr_id'])
    && in_array($_POST['set_qr_status'], array('active', 'disabled'), true)) {
    $qr_id = filter_var($_POST['qr_id'], FILTER_VALIDATE_INT);
    if (!$qr_id || $qr_id < 1) {
        $error = 'Choose a valid QR record.';
    } else {
        try {
            $status = $_POST['set_qr_status'];
            $stmt = $mysqli->prepare('UPDATE ib_qr_codes SET status = ?, revoked_at = ' . ($status === 'disabled' ? 'CURRENT_TIMESTAMP' : 'NULL') . ' WHERE qr_id = ?');
            $stmt->bind_param('si', $status, $qr_id);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                throw new RuntimeException('The QR record was not changed.');
            }
            $stmt->close();
            $success = $status === 'active' ? 'QR profile re-enabled.' : 'QR profile disabled.';
        } catch (Throwable $e) {
            $error = 'Could not update the QR record.';
            error_log('[RuralBanking] admin QR status update failed: ' . $e->getMessage());
        }
    }
}

$from = isset($_GET['from']) ? trim((string) $_GET['from']) : '';
$to = isset($_GET['to']) ? trim((string) $_GET['to']) : '';
$reference = isset($_GET['reference']) ? trim((string) $_GET['reference']) : '';
if (($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from))
    || ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $to))
    || strlen($reference) > 60) {
    $error = 'Enter valid date filters and a reference of at most 60 characters.';
    $from = '';
    $to = '';
    $reference = '';
}

try {
    $result = $mysqli->query("SELECT q.qr_id, q.qr_token, q.status AS qr_status, q.created_at,
                                     p.upi_id, p.display_name, p.profile_type, p.status AS profile_status,
                                     c.name AS client_name, c.email, a.account_number
                              FROM ib_qr_codes q
                              JOIN ib_upi_profiles p ON p.profile_id = q.profile_id
                              JOIN ib_clients c ON c.client_id = p.client_id
                              JOIN ib_bankaccounts a ON a.account_id = p.account_id
                              ORDER BY q.qr_id DESC LIMIT 300");
    if (!$result) {
        throw new RuntimeException('Could not read QR profiles.');
    }
    while ($row = $result->fetch_assoc()) {
        $profiles[] = $row;
    }
    $result->free();

    $result = $mysqli->query("SELECT r.request_id, r.request_code, r.amount, r.status, r.created_at, r.expires_at,
                                     payer.name AS payer_name, payer.email AS payer_email,
                                     payee.display_name AS payee_name, payee.upi_id
                              FROM ib_payment_requests r
                              JOIN ib_clients payer ON payer.client_id = r.payer_client_id
                              JOIN ib_upi_profiles payee ON payee.profile_id = r.payee_profile_id
                              ORDER BY r.created_at DESC LIMIT 300");
    if (!$result) {
        throw new RuntimeException('Could not read payment requests.');
    }
    while ($row = $result->fetch_assoc()) { $requests[] = $row; }
    $result->free();

    $stmt = $mysqli->prepare("SELECT tr_code, created_at, client_name, account_number, transaction_amt, receiving_acc_no, receiving_acc_holder, tr_status
                              FROM ib_transactions
                              WHERE tr_channel = 'upi_qr'
                                AND (? = '' OR DATE(created_at) >= ?)
                                AND (? = '' OR DATE(created_at) <= ?)
                                AND (? = '' OR tr_code LIKE CONCAT('%', ?, '%'))
                              ORDER BY created_at DESC LIMIT 300");
    if (!$stmt) {
        throw new RuntimeException('Could not prepare QR transaction monitor.');
    }
    $stmt->bind_param('ssssss', $from, $from, $to, $to, $reference, $reference);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not load QR payment records.');
    }
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }
    $stmt->close();
} catch (Throwable $e) {
    $error = 'Could not load QR management data. Import database/qr-notification-migration.sql.';
    error_log('[RuralBanking] QR monitoring query failed: ' . $e->getMessage());
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
              <h1>QR &amp; Payment Request Management</h1>
              <p class="text-muted mb-0">Review QR profiles, payment requests and account activity. <a href="../service_information.php">Service Information</a></p>
            </div>
          </div>
        </div>
      </section>
      <section class="content">
        <div class="container-fluid">
          <?php if ($error !== '') { ?><div class="alert alert-danger" role="alert"><?php echo rb_e($error); ?></div><?php } ?>
          <?php if ($success !== '') { ?><div class="alert alert-success" role="status"><?php echo rb_e($success); ?></div><?php } ?>
          <div class="card">
            <div class="card-header"><h3 class="card-title">QR profiles</h3></div>
            <div class="card-body table-responsive p-0">
              <table class="table table-hover mb-0">
                <thead><tr><th>Profile</th><th>Customer</th><th>Virtual ID</th><th>Account</th><th>Created</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                  <?php foreach ($profiles as $profile) { ?>
                    <tr>
                      <td><?php echo rb_e(ucfirst($profile['profile_type'])); ?><br><small><?php echo rb_e($profile['display_name']); ?></small></td>
                      <td><?php echo rb_e($profile['client_name']); ?><br><small><?php echo rb_e($profile['email']); ?></small></td>
                      <td><code><?php echo rb_e($profile['upi_id']); ?></code></td>
                      <td>••••<?php echo rb_e(substr($profile['account_number'], -4)); ?></td>
                      <td><?php echo rb_e($profile['created_at']); ?></td>
                      <td><?php echo rb_e($profile['qr_status']); ?> / <?php echo rb_e($profile['profile_status']); ?></td>
                      <td>
                        <form method="post">
                          <input type="hidden" name="qr_id" value="<?php echo (int) $profile['qr_id']; ?>">
                          <?php if ($profile['qr_status'] === 'active') { ?>
                            <button class="btn btn-sm btn-outline-danger" name="set_qr_status" value="disabled">Disable</button>
                          <?php } else { ?>
                            <button class="btn btn-sm btn-outline-success" name="set_qr_status" value="active">Re-enable</button>
                          <?php } ?>
                        </form>
                      </td>
                    </tr>
                  <?php } ?>
                  <?php if (!$profiles) { ?><tr><td colspan="7" class="text-center text-muted py-3">No QR profiles.</td></tr><?php } ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="card">
            <div class="card-header"><h3 class="card-title">Payment requests</h3></div>
            <div class="card-body table-responsive">
              <table class="table table-hover table-sm">
                <thead><tr><th>Requested by</th><th>Payee</th><th>Amount</th><th>Status</th><th>Created / expires</th><th>Action</th></tr></thead>
                <tbody>
                  <?php foreach ($requests as $request) { ?>
                    <tr>
                      <td><?php echo rb_e($request['payer_name']); ?><br><small><?php echo rb_e($request['payer_email']); ?></small></td>
                      <td><?php echo rb_e($request['payee_name']); ?><br><small><?php echo rb_e($request['upi_id']); ?></small></td>
                      <td><?php echo rb_e(rb_money($request['amount'])); ?></td>
                      <td><?php echo rb_e($request['status']); ?></td>
                      <td><?php echo rb_e($request['created_at']); ?><br><small><?php echo rb_e($request['expires_at']); ?></small></td>
                      <td><?php if ($request['status'] === 'pending') { ?><form method="post"><button class="btn btn-sm btn-outline-danger" name="cancel_payment_request" value="<?php echo (int) $request['request_id']; ?>">Cancel request</button></form><?php } elseif ($request['status'] === 'processing') { ?><span class="text-warning">Reconciliation needed</span><?php } ?></td>
                    </tr>
                  <?php } ?>
                  <?php if (!$requests) { ?><tr><td colspan="6" class="text-center text-muted">No payment requests.</td></tr><?php } ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="card">
            <div class="card-header"><h3 class="card-title">QR payment history</h3></div>
            <div class="card-body">
              <form class="form-row align-items-end mb-3" method="get">
                <div class="form-group col-md-3"><label for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?php echo rb_e($from); ?>"></div>
                <div class="form-group col-md-3"><label for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?php echo rb_e($to); ?>"></div>
                <div class="form-group col-md-4"><label for="reference">Reference</label><input class="form-control" id="reference" name="reference" maxlength="60" value="<?php echo rb_e($reference); ?>"></div>
                <div class="form-group col-md-2"><button class="btn btn-primary" type="submit">Filter</button></div>
              </form>
              <div class="table-responsive">
                <table class="table table-hover">
                  <thead><tr><th>Reference</th><th>When</th><th>Sender</th><th>Recipient</th><th>Amount</th><th>Status</th></tr></thead>
                  <tbody>
                    <?php foreach ($payments as $payment) { ?>
                      <tr>
                        <td><code><?php echo rb_e($payment['tr_code']); ?></code></td>
                        <td><?php echo rb_e($payment['created_at']); ?></td>
                        <td><?php echo rb_e($payment['client_name']); ?><br><small>••••<?php echo rb_e(substr($payment['account_number'], -4)); ?></small></td>
                        <td><?php echo rb_e($payment['receiving_acc_holder']); ?><br><small>••••<?php echo rb_e(substr($payment['receiving_acc_no'], -4)); ?></small></td>
                        <td><?php echo rb_e(rb_money($payment['transaction_amt'])); ?></td>
                        <td><?php echo rb_e($payment['tr_status']); ?></td>
                      </tr>
                    <?php } ?>
                    <?php if (!$payments) { ?><tr><td colspan="6" class="text-center text-muted py-3">No matching QR payments.</td></tr><?php } ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
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
