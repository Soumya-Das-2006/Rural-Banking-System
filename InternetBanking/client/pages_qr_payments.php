<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();
$client_id = (int) $_SESSION['client_id'];
$error = '';
$success = '';
$accounts = array();
$profiles = array();
$requests_sent = array();
$requests_incoming = array();
$tables_ready = true;
try {
    $mysqli->query('SELECT qr_id FROM ib_qr_codes LIMIT 0');
    $mysqli->query('SELECT request_id FROM ib_payment_requests LIMIT 0');
} catch (Throwable $e) {
    $tables_ready = false;
}

if ($tables_ready && isset($_POST['create_profile'])) {
    $account_id = filter_var(isset($_POST['account_id']) ? $_POST['account_id'] : null, FILTER_VALIDATE_INT);
    $profile_type = isset($_POST['profile_type']) ? (string) $_POST['profile_type'] : 'personal';
    $display_name = trim((string) (isset($_POST['display_name']) ? $_POST['display_name'] : ''));
    if (!$account_id || !in_array($profile_type, array('personal', 'merchant'), true)) {
        $error = 'Choose a valid active account and profile type.';
    } elseif (strlen($display_name) > 100 || preg_match('/[\x00-\x1F\x7F]/', $display_name)) {
        $error = 'Enter a display name of up to 100 characters.';
    } else {
        try {
            $stmt = $mysqli->prepare("SELECT a.account_id, c.name FROM ib_bankaccounts a JOIN ib_clients c ON c.client_id = a.client_id WHERE a.account_id = ? AND a.client_id = ? AND LOWER(TRIM(a.acc_status)) = 'active' LIMIT 1");
            $stmt->bind_param('ii', $account_id, $client_id);
            $stmt->execute();
            $stmt->bind_result($owned_account_id, $client_name);
            $owns_account = $stmt->fetch();
            $stmt->close();
            if (!$owns_account) {
                throw new RuntimeException('That active account is not available to your profile.');
            }
            if ($display_name === '') {
                $display_name = $profile_type === 'merchant' ? '' : $client_name;
            }
            if ($display_name === '' || strlen($display_name) > 100) {
                throw new RuntimeException('Enter a display name for this profile.');
            }

            $mysqli->begin_transaction();
            $created = false;
            for ($attempt = 0; $attempt < 5 && !$created; $attempt++) {
                $upi_id = 'rb' . bin2hex(random_bytes(5)) . '@bankofsoumya.local';
                $stmt = $mysqli->prepare('INSERT INTO ib_upi_profiles (client_id, account_id, upi_id, display_name, profile_type) VALUES (?, ?, ?, ?, ?)');
                $stmt->bind_param('iisss', $client_id, $account_id, $upi_id, $display_name, $profile_type);
                if ($stmt->execute()) {
                    $profile_id = (int) $mysqli->insert_id;
                    $stmt->close();
                    $qr_token = bin2hex(random_bytes(16));
                    $stmt = $mysqli->prepare("INSERT INTO ib_qr_codes (profile_id, qr_token, status) VALUES (?, ?, 'active')");
                    if (!$stmt) {
                        throw new RuntimeException('Could not prepare the QR profile.');
                    }
                    $stmt->bind_param('is', $profile_id, $qr_token);
                    if (!$stmt->execute()) {
                        $stmt->close();
                        throw new RuntimeException('Could not save the QR profile.');
                    }
                    $stmt->close();
                    $created = true;
                } else {
                    $duplicate = $stmt->errno === 1062;
                    $stmt->close();
                    if (!$duplicate) {
                        throw new RuntimeException('Could not create the payment profile.');
                    }
                }
            }
            if (!$created) {
                throw new RuntimeException('Could not create a unique virtual ID. Please try again.');
            }
            $mysqli->commit();
            $success = 'Your payment profile is ready.';
        } catch (Throwable $e) {
            try {
                $mysqli->rollback();
            } catch (Throwable $rollbackError) {
            }
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not create the payment profile. Import the QR migration and try again.';
            error_log('[RuralBanking] QR profile creation failed: ' . $e->getMessage());
        }
    }
}

if ($tables_ready && isset($_POST['create_payment_request'])) {
    $profile_id = filter_var(isset($_POST['payee_profile_id']) ? $_POST['payee_profile_id'] : null, FILTER_VALIDATE_INT);
    $payer_email = trim((string) (isset($_POST['payer_email']) ? $_POST['payer_email'] : ''));
    $amount = rb_money_parse(isset($_POST['request_amount']) ? $_POST['request_amount'] : null);
    if (!$profile_id || !rb_email_valid_address($payer_email) || $amount === null) {
        $error = 'Choose a profile, enter the payer email, and provide a valid amount.';
    } else {
        try {
            $stmt = $mysqli->prepare("SELECT p.upi_id, p.display_name FROM ib_upi_profiles p
                                      JOIN ib_qr_codes q ON q.profile_id = p.profile_id
                                      WHERE p.profile_id = ? AND p.client_id = ? AND p.status = 'active' AND q.status = 'active' LIMIT 1");
            $stmt->bind_param('ii', $profile_id, $client_id);
            $stmt->execute();
            $stmt->bind_result($payee_upi, $payee_name);
            if (!$stmt->fetch()) {
                $stmt->close();
                throw new RuntimeException('Choose one of your active payment profiles.');
            }
            $stmt->close();
            $stmt = $mysqli->prepare('SELECT client_id FROM ib_clients WHERE email = ? LIMIT 1');
            $stmt->bind_param('s', $payer_email);
            $stmt->execute();
            $stmt->bind_result($payer_client_id);
            $found_payer = $stmt->fetch();
            $stmt->close();
            if (!$found_payer || (int) $payer_client_id === $client_id) {
                throw new RuntimeException('Enter another customer’s registered email address.');
            }

            $request_code = bin2hex(random_bytes(16));
            $amount_value = number_format($amount, 2, '.', '');
            $mysqli->begin_transaction();
            $stmt = $mysqli->prepare("INSERT INTO ib_payment_requests (request_code, payer_client_id, payee_profile_id, amount, status, expires_at)
                                      VALUES (?, ?, ?, ?, 'pending', DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 7 DAY))");
            $stmt->bind_param('siis', $request_code, $payer_client_id, $profile_id, $amount_value);
            if (!$stmt->execute()) { throw new RuntimeException('Could not create the payment request.'); }
            $stmt->close();
            $mail = rb_email_queue($mysqli, $payer_email, 'Payment request from ' . $payee_name,
                $payee_name . " requested " . rb_money($amount) . " in the Bank Of Soumya application.\n\n"
                . 'Virtual ID: ' . $payee_upi . "\n"
                . 'Request code: ' . $request_code . "\n"
                . "Sign in to your Bank Of Soumya account and open QR Payments to review it.",
                (int) $payer_client_id, 'account');
            if (!$mail['ok'] || !$mail['queued']) {
                throw new RuntimeException($mail['error']);
            }
            $mysqli->commit();
            $success = 'Payment request created and queued for email delivery.';
        } catch (Throwable $e) {
            try { $mysqli->rollback(); } catch (Throwable $rollbackError) {}
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not create the payment request.';
            error_log('[RuralBanking] payment request create failed: ' . $e->getMessage());
        }
    }
}

if ($tables_ready && isset($_POST['cancel_payment_request'])) {
    $request_id = filter_var($_POST['cancel_payment_request'], FILTER_VALIDATE_INT);
    if (!$request_id) {
        $error = 'Choose a valid payment request.';
    } else {
        $stmt = $mysqli->prepare("UPDATE ib_payment_requests r JOIN ib_upi_profiles p ON p.profile_id = r.payee_profile_id
                                  SET r.status = 'cancelled'
                                  WHERE r.request_id = ? AND p.client_id = ? AND r.status = 'pending'");
        $stmt->bind_param('ii', $request_id, $client_id);
        if ($stmt->execute() && $stmt->affected_rows === 1) {
            $success = 'Payment request cancelled.';
        } else {
            $error = 'This request is no longer pending.';
        }
        $stmt->close();
    }
}

try {
    $stmt = $mysqli->prepare("SELECT a.account_id, a.account_number, a.acc_type, p.profile_id, p.upi_id, p.display_name, p.profile_type, p.status AS profile_status, q.qr_token, q.status AS qr_status
                              FROM ib_bankaccounts a
                              LEFT JOIN ib_upi_profiles p ON p.account_id = a.account_id AND p.client_id = a.client_id
                              LEFT JOIN ib_qr_codes q ON q.profile_id = p.profile_id
                              WHERE a.client_id = ? AND LOWER(TRIM(a.acc_status)) = 'active'
                              ORDER BY a.account_id");
    if (!$stmt) {
        throw new RuntimeException('Could not prepare QR profile list: ' . $mysqli->error);
    }
    $stmt->bind_param('i', $client_id);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not load QR profiles: ' . $stmt->error);
    }
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $accounts[] = $row;
        if ($row['profile_id'] !== null) {
            $profiles[] = $row;
        }
    }
    $stmt->close();

    $stmt = $mysqli->prepare('SELECT r.request_id, r.request_code, r.amount, r.status, r.expires_at, r.created_at, p.upi_id, p.display_name, c.email AS payer_email
                              FROM ib_payment_requests r
                              JOIN ib_upi_profiles p ON p.profile_id = r.payee_profile_id
                              JOIN ib_clients c ON c.client_id = r.payer_client_id
                              WHERE p.client_id = ? ORDER BY r.created_at DESC LIMIT 50');
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $requests_sent[] = $row; }
    $stmt->close();

    $stmt = $mysqli->prepare('SELECT r.request_id, r.request_code, r.amount, r.status, r.expires_at, r.created_at, p.upi_id, p.display_name, c.name AS payee_name
                              FROM ib_payment_requests r
                              JOIN ib_upi_profiles p ON p.profile_id = r.payee_profile_id
                              JOIN ib_clients c ON c.client_id = p.client_id
                              WHERE r.payer_client_id = ? ORDER BY r.created_at DESC LIMIT 50');
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $requests_incoming[] = $row; }
    $stmt->close();
} catch (Throwable $e) {
    $tables_ready = false;
    $accounts = array();
    $profiles = array();
    if ($error === '') {
        $error = 'QR services are not ready. Import database/qr-notification-migration.sql.';
    }
    error_log('[RuralBanking] QR profile list failed: ' . $e->getMessage());
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
              <h1>QR Payments &amp; Virtual IDs</h1>
              <p class="text-muted mb-0">Create QR profiles, review payment requests and manage virtual IDs. <a href="../service_information.php">Service Information</a></p>
            </div>
          </div>
        </div>
      </section>
      <section class="content">
        <div class="container-fluid">
          <?php if ($error !== '') { ?><div class="alert alert-danger" role="alert"><?php echo rb_e($error); ?></div><?php } ?>
          <?php if ($success !== '') { ?><div class="alert alert-success" role="status"><?php echo rb_e($success); ?></div><?php } ?>

          <div class="row">
            <div class="col-lg-7">
              <?php if ($profiles) { ?>
                <?php foreach ($profiles as $profile) { ?>
                  <div class="card">
                    <div class="card-header">
                      <h3 class="card-title"><?php echo rb_e(ucfirst($profile['profile_type'])); ?> QR profile</h3>
                      <span class="badge badge-<?php echo $profile['qr_status'] === 'active' && $profile['profile_status'] === 'active' ? 'success' : 'secondary'; ?>">
                        <?php echo rb_e($profile['qr_status'] === 'active' && $profile['profile_status'] === 'active' ? 'Active' : 'Disabled'); ?>
                      </span>
                    </div>
                    <div class="card-body">
                      <div class="row align-items-center">
                        <div class="col-md-6 text-center">
                          <?php if ($profile['qr_status'] === 'active' && $profile['profile_status'] === 'active') { ?>
                            <div class="rb-qr-image mx-auto" id="qr-<?php echo (int) $profile['profile_id']; ?>" data-token="<?php echo rb_e($profile['qr_token']); ?>" data-upi="<?php echo rb_e($profile['upi_id']); ?>" data-target="qr-<?php echo (int) $profile['profile_id']; ?>"></div>
                          <?php } else { ?>
                            <div class="alert alert-secondary">This QR profile is disabled and cannot receive payment requests.</div>
                          <?php } ?>
                        </div>
                        <div class="col-md-6">
                          <p class="mb-1"><strong><?php echo rb_e($profile['display_name']); ?></strong></p>
                          <p class="small text-muted mb-0">Virtual ID (in-app only; not a UPI address)</p>
                          <p class="text-monospace mb-1"><?php echo rb_e($profile['upi_id']); ?></p>
                          <p class="small text-muted">Linked account: ••••<?php echo rb_e(substr($profile['account_number'], -4)); ?> (<?php echo rb_e($profile['acc_type']); ?>)</p>
                          <?php if ($profile['qr_status'] === 'active' && $profile['profile_status'] === 'active') { ?>
                            <label for="amount-<?php echo (int) $profile['profile_id']; ?>">Optional payment amount (INR)</label>
                            <input class="form-control rb-qr-amount" id="amount-<?php echo (int) $profile['profile_id']; ?>" type="number" min="0.01" max="10000000" step="0.01" placeholder="Leave blank for payer to enter">
                            <button type="button" class="btn btn-outline-success btn-sm mt-2 rb-qr-download" data-target="qr-<?php echo (int) $profile['profile_id']; ?>">Download QR</button>
                          <?php } ?>
                        </div>
                      </div>
                    </div>
                  </div>
                <?php } ?>
              <?php } elseif ($tables_ready) { ?>
                <div class="card"><div class="card-body text-muted">Create a profile for an active account to generate a personal or business QR.</div></div>
              <?php } ?>
            </div>

            <div class="col-lg-5">
              <div class="card">
                <div class="card-header"><h3 class="card-title">Create a payment profile</h3></div>
                <div class="card-body">
                  <?php if (!$tables_ready) { ?>
                    <p class="text-muted mb-0">Import <code>database/qr-notification-migration.sql</code> first.</p>
                  <?php } elseif (!$accounts) { ?>
                    <p class="text-muted mb-0">You need an active bank account to create a payment profile.</p>
                  <?php } else { ?>
                    <form method="post">
                      <div class="form-group">
                        <label for="account_id">Link to active account</label>
                        <select class="form-control" id="account_id" name="account_id" required>
                          <?php foreach ($accounts as $account) { if ($account['profile_id'] === null) { ?>
                            <option value="<?php echo (int) $account['account_id']; ?>"><?php echo rb_e($account['acc_type'] . ' · ••••' . substr($account['account_number'], -4)); ?></option>
                          <?php } } ?>
                        </select>
                      </div>
                      <div class="form-group">
                        <label for="profile_type">Profile type</label>
                        <select class="form-control" id="profile_type" name="profile_type">
                          <option value="personal">Personal</option>
                          <option value="merchant">Business display profile</option>
                        </select>
                      </div>
                      <div class="form-group">
                        <label for="display_name">Display name</label>
                        <input class="form-control" id="display_name" name="display_name" maxlength="100" placeholder="Leave blank for your account name">
                      </div>
                      <button class="btn btn-success" type="submit" name="create_profile" value="1" <?php echo count($profiles) >= count($accounts) ? 'disabled' : ''; ?>>Create profile</button>
                      <small class="form-text text-muted">Business display names are not verified. <a href="../service_information.php#services">Service Information</a></small>
                    </form>
                  <?php } ?>
                </div>
              </div>

              <div class="card">
                <div class="card-header"><h3 class="card-title">Pay by QR or virtual ID</h3></div>
                <div class="card-body">
                  <div class="form-group">
                    <label for="rb-qr-input">Scan a Bank Of Soumya QR or enter an in-app virtual ID</label>
                    <input class="form-control" id="rb-qr-input" autocomplete="off" placeholder="Enter in-app virtual ID">
                  </div>
                  <div class="form-group">
                    <label for="rb-qr-pay-amount">Amount (INR), if the QR has no amount</label>
                    <input class="form-control" id="rb-qr-pay-amount" type="number" min="0.01" max="10000000" step="0.01" placeholder="0.00">
                  </div>
                  <button type="button" class="btn btn-primary" id="rb-qr-continue">Review payment</button>
                  <button type="button" class="btn btn-outline-secondary ml-1" id="rb-qr-start">Use camera</button>
                  <div id="rb-qr-scan-status" class="small text-muted mt-2" role="status"></div>
                  <video id="rb-qr-video" class="w-100 mt-2" playsinline hidden></video>
                  <p class="small text-muted mt-3 mb-0">Camera scanning requires HTTPS and browser support. Your scan is decoded locally; no QR contents are sent to a QR service.</p>
                  <p class="small text-muted mt-2 mb-0">QR payments are recorded only in this application’s ledger. They are not sent through UPI or an external payment network.</p>
                </div>
              </div>
              <div class="card">
                <div class="card-header"><h3 class="card-title">Request a payment</h3></div>
                <div class="card-body">
                  <?php if (!$tables_ready) { ?><p class="text-muted mb-0">Import <code>database/qr-notification-migration.sql</code> first.</p>
                  <?php } elseif (!$profiles) { ?><p class="text-muted mb-0">Create an active payment profile before requesting a payment.</p>
                  <?php } else { ?>
                    <form method="post">
                      <div class="form-group"><label for="payee_profile_id">Request to this profile</label><select class="form-control" id="payee_profile_id" name="payee_profile_id" required><?php foreach ($profiles as $profile) { if ($profile['qr_status'] === 'active' && $profile['profile_status'] === 'active') { ?><option value="<?php echo (int) $profile['profile_id']; ?>"><?php echo rb_e($profile['display_name'] . ' · ' . $profile['upi_id']); ?></option><?php } } ?></select></div>
                      <div class="form-group"><label for="payer_email">Other customer's email</label><input class="form-control" type="email" id="payer_email" name="payer_email" maxlength="190" required></div>
                      <div class="form-group"><label for="request_amount">Amount (INR)</label><input class="form-control" type="number" id="request_amount" name="request_amount" min="0.01" max="10000000" step="0.01" required></div>
                      <button class="btn btn-outline-success" type="submit" name="create_payment_request" value="1">Send payment request</button>
                    </form>
                  <?php } ?>
                </div>
              </div>
              <div class="card"><div class="card-header"><h3 class="card-title">Incoming payment requests</h3></div><div class="card-body table-responsive"><table class="table table-sm"><thead><tr><th>From</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead><tbody>
                <?php foreach ($requests_incoming as $request) { ?><tr><td><?php echo rb_e($request['payee_name']); ?><div class="small text-muted"><?php echo rb_e($request['upi_id']); ?></div></td><td><?php echo rb_e(rb_money($request['amount'])); ?></td><td><?php echo rb_e($request['status']); ?></td><td><?php if ($request['status'] === 'pending' && (!$request['expires_at'] || strtotime($request['expires_at']) > time())) { ?><a class="btn btn-sm btn-success" href="pages_send_money.php?request_code=<?php echo rb_e($request['request_code']); ?>">Review &amp; pay</a><?php } elseif ($request['status'] === 'pending') { ?><span class="text-muted">Expired</span><?php } ?></td></tr><?php } ?>
                <?php if (!$requests_incoming) { ?><tr><td colspan="4" class="text-muted">No incoming requests.</td></tr><?php } ?>
              </tbody></table></div></div>
              <div class="card"><div class="card-header"><h3 class="card-title">Requests you sent</h3></div><div class="card-body table-responsive"><table class="table table-sm"><thead><tr><th>Payer</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead><tbody>
                <?php foreach ($requests_sent as $request) { ?><tr><td><?php echo rb_e($request['payer_email']); ?></td><td><?php echo rb_e(rb_money($request['amount'])); ?></td><td><?php echo rb_e($request['status']); ?></td><td><?php if ($request['status'] === 'pending') { ?><form method="post"><button class="btn btn-sm btn-outline-danger" name="cancel_payment_request" value="<?php echo (int) $request['request_id']; ?>">Cancel</button></form><?php } ?></td></tr><?php } ?>
                <?php if (!$requests_sent) { ?><tr><td colspan="4" class="text-muted">No requests sent.</td></tr><?php } ?>
              </tbody></table></div></div>
            </div>
          </div>
        </div>
      </section>
    </div>
    <?php include("dist/_partials/footer.php"); ?>
  </div>
  <script src="../assets/js/qrcode.min.js"></script>
  <script src="plugins/jquery/jquery.min.js"></script>
  <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="dist/js/adminlte.min.js"></script>
  <script src="../assets/js/rb-qr.js?v=20261124-1"></script>
</body>
</html>
