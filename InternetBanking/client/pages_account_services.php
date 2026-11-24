<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();
$client_id = (int) $_SESSION['client_id'];
$error = '';
$success = '';
$accounts = array();
$beneficiaries = array();
$cheque_requests = array();
$tickets = array();
$ready = true;

try {
    $mysqli->query('SELECT beneficiary_id FROM ib_beneficiaries LIMIT 0');
    $mysqli->query('SELECT cheque_request_id FROM ib_cheque_requests LIMIT 0');
    $mysqli->query('SELECT ticket_id FROM ib_support_tickets LIMIT 0');
} catch (Throwable $e) {
    $ready = false;
}

$stmt = $mysqli->prepare("SELECT account_id, account_number, acc_type FROM ib_bankaccounts WHERE client_id = ? AND LOWER(TRIM(acc_status)) = 'active' ORDER BY account_id");
$stmt->bind_param('i', $client_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) { $accounts[] = $row; }
$stmt->close();

if ($ready && isset($_POST['add_beneficiary'])) {
    $account_id = filter_var(isset($_POST['account_id']) ? $_POST['account_id'] : null, FILTER_VALIDATE_INT);
    $name = trim((string) (isset($_POST['beneficiary_name']) ? $_POST['beneficiary_name'] : ''));
    $receiving_account = trim((string) (isset($_POST['receiving_account']) ? $_POST['receiving_account'] : ''));
    if (!$account_id || $name === '' || strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/', $name)
        || !preg_match('/^[0-9A-Za-z\-]{4,50}$/D', $receiving_account)) {
        $error = 'Choose an active account and enter a valid beneficiary name and account number.';
    } else {
        try {
            $stmt = $mysqli->prepare("SELECT account_id FROM ib_bankaccounts WHERE account_id = ? AND client_id = ? AND LOWER(TRIM(acc_status)) = 'active' LIMIT 1");
            $stmt->bind_param('ii', $account_id, $client_id);
            $stmt->execute();
            $stmt->store_result();
            $owns_account = $stmt->num_rows === 1;
            $stmt->close();
            if (!$owns_account) {
                throw new RuntimeException('The selected account is not available.');
            }
            $stmt = $mysqli->prepare("SELECT account_id FROM ib_bankaccounts WHERE account_number = ? AND LOWER(TRIM(acc_status)) = 'active' LIMIT 1");
            $stmt->bind_param('s', $receiving_account);
            $stmt->execute();
            $stmt->bind_result($receiving_account_id);
            $exists = $stmt->fetch();
            $stmt->close();
            if (!$exists) {
                throw new RuntimeException('The receiving account must be an active Bank Of Soumya account.');
            }
            $stmt = $mysqli->prepare("SELECT beneficiary_id FROM ib_beneficiaries WHERE client_id = ? AND account_id = ? AND receiving_account_number = ? AND status = 'active' LIMIT 1");
            $stmt->bind_param('iis', $client_id, $account_id, $receiving_account);
            $stmt->execute();
            $stmt->store_result();
            $duplicate = $stmt->num_rows > 0;
            $stmt->close();
            if ($duplicate) {
                throw new RuntimeException('That beneficiary is already saved for this account.');
            }
            $stmt = $mysqli->prepare("INSERT INTO ib_beneficiaries (client_id, account_id, name, receiving_account_number, status) VALUES (?, ?, ?, ?, 'active')");
            $stmt->bind_param('iiss', $client_id, $account_id, $name, $receiving_account);
            if (!$stmt->execute()) {
                throw new RuntimeException('Could not save this beneficiary.');
            }
            $stmt->close();
            $success = 'Beneficiary added. Confirm the recipient name and account number before sending money.';
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not add the beneficiary.';
            error_log('[RuralBanking] beneficiary create failed: ' . $e->getMessage());
        }
    }
}

if ($ready && isset($_POST['remove_beneficiary'])) {
    $beneficiary_id = filter_var($_POST['remove_beneficiary'], FILTER_VALIDATE_INT);
    if (!$beneficiary_id) {
        $error = 'Choose a valid beneficiary.';
    } else {
        $stmt = $mysqli->prepare("UPDATE ib_beneficiaries SET status = 'inactive' WHERE beneficiary_id = ? AND client_id = ? AND status = 'active'");
        $stmt->bind_param('ii', $beneficiary_id, $client_id);
        if ($stmt->execute() && $stmt->affected_rows === 1) {
            $success = 'Beneficiary deactivated.';
        } else {
            $error = 'That beneficiary is no longer active.';
        }
        $stmt->close();
    }
}

if ($ready && isset($_POST['request_cheque_book'])) {
    $account_id = filter_var(isset($_POST['cheque_account_id']) ? $_POST['cheque_account_id'] : null, FILTER_VALIDATE_INT);
    $leaf_count = filter_var(isset($_POST['leaf_count']) ? $_POST['leaf_count'] : null, FILTER_VALIDATE_INT);
    if (!$account_id || !in_array($leaf_count, array(10, 25, 50), true)) {
        $error = 'Choose an active account and a cheque book size of 10, 25, or 50 leaves.';
    } else {
        $stmt = $mysqli->prepare("SELECT account_id FROM ib_bankaccounts WHERE account_id = ? AND client_id = ? AND LOWER(TRIM(acc_status)) = 'active' LIMIT 1");
        $stmt->bind_param('ii', $account_id, $client_id);
        $stmt->execute();
        $stmt->store_result();
        $owns_account = $stmt->num_rows === 1;
        $stmt->close();
        if (!$owns_account) {
            $error = 'The selected account is not available.';
        } else {
            $stmt = $mysqli->prepare("SELECT cheque_request_id FROM ib_cheque_requests WHERE client_id = ? AND account_id = ? AND status = 'pending' LIMIT 1");
            $stmt->bind_param('ii', $client_id, $account_id);
            $stmt->execute();
            $stmt->store_result();
            $pending = $stmt->num_rows > 0;
            $stmt->close();
            if ($pending) {
                $error = 'A cheque book request for this account is already pending.';
            } else {
                $stmt = $mysqli->prepare("INSERT INTO ib_cheque_requests (client_id, account_id, leaf_count, status) VALUES (?, ?, ?, 'pending')");
                $stmt->bind_param('iii', $client_id, $account_id, $leaf_count);
                if ($stmt->execute()) {
                    $success = 'Cheque book request submitted for branch review.';
                } else {
                    $error = 'Could not submit the cheque book request.';
                }
                $stmt->close();
            }
        }
    }
}

if ($ready && isset($_POST['create_ticket'])) {
    $subject = trim((string) (isset($_POST['subject']) ? $_POST['subject'] : ''));
    $message = trim((string) (isset($_POST['message']) ? $_POST['message'] : ''));
    if ($subject === '' || strlen($subject) > 120 || preg_match('/[\x00-\x1F\x7F]/', $subject)
        || strlen($message) < 10 || strlen($message) > 4000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $message)) {
        $error = 'Enter a subject (up to 120 characters) and a message between 10 and 4,000 characters.';
    } else {
        $stmt = $mysqli->prepare("INSERT INTO ib_support_tickets (client_id, subject, message, status) VALUES (?, ?, ?, 'open')");
        $stmt->bind_param('iss', $client_id, $subject, $message);
        if ($stmt->execute()) {
            $success = 'Support ticket submitted.';
        } else {
            $error = 'Could not submit this support ticket.';
        }
        $stmt->close();
    }
}

if ($ready) {
    try {
        $stmt = $mysqli->prepare('SELECT beneficiary_id, account_id, name, receiving_account_number, status, created_at FROM ib_beneficiaries WHERE client_id = ? ORDER BY created_at DESC LIMIT 100');
        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) { $beneficiaries[] = $row; }
        $stmt->close();

        $stmt = $mysqli->prepare('SELECT q.cheque_request_id, q.leaf_count, q.status, q.created_at, a.account_number FROM ib_cheque_requests q JOIN ib_bankaccounts a ON a.account_id = q.account_id WHERE q.client_id = ? ORDER BY q.created_at DESC LIMIT 50');
        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) { $cheque_requests[] = $row; }
        $stmt->close();

        $stmt = $mysqli->prepare('SELECT t.ticket_id, t.subject, t.status, r.response AS admin_response, t.created_at, t.updated_at
                                  FROM ib_support_tickets t
                                  LEFT JOIN ib_support_responses r ON r.response_id = (
                                    SELECT MAX(r2.response_id) FROM ib_support_responses r2 WHERE r2.ticket_id = t.ticket_id
                                  )
                                  WHERE t.client_id = ? ORDER BY t.created_at DESC LIMIT 50');
        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) { $tickets[] = $row; }
        $stmt->close();
    } catch (Throwable $e) {
        $ready = false;
        $error = 'Could not load account services. Check the advanced features migration.';
        error_log('[RuralBanking] account services load failed: ' . $e->getMessage());
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
    <section class="content-header"><div class="container-fluid"><h1>Account Services</h1><p class="text-muted">Beneficiaries, cheque-book requests and customer support.</p></div></section>
    <section class="content"><div class="container-fluid">
      <?php if (!$ready) { ?><div class="alert alert-warning">Import <code>database/advanced-features-migration.sql</code> to enable these services.</div><?php } ?>
      <?php if ($error !== '') { ?><div class="alert alert-danger" role="alert"><?php echo rb_e($error); ?></div><?php } ?>
      <?php if ($success !== '') { ?><div class="alert alert-success" role="status"><?php echo rb_e($success); ?></div><?php } ?>
      <div class="row">
        <div class="col-lg-6">
          <div class="card"><div class="card-header"><h3 class="card-title">Add a beneficiary</h3></div><div class="card-body">
            <?php if ($ready) { ?><form method="post">
              <div class="form-group"><label for="account_id">Link to your account</label><select class="form-control" id="account_id" name="account_id" required><?php foreach ($accounts as $account) { ?><option value="<?php echo (int) $account['account_id']; ?>"><?php echo rb_e($account['acc_type'] . ' · ••••' . substr($account['account_number'], -4)); ?></option><?php } ?></select></div>
              <div class="form-group"><label for="beneficiary_name">Beneficiary name</label><input class="form-control" id="beneficiary_name" name="beneficiary_name" maxlength="100" required></div>
              <div class="form-group"><label for="receiving_account">Receiving account number</label><input class="form-control" id="receiving_account" name="receiving_account" maxlength="50" required></div>
              <button class="btn btn-success" name="add_beneficiary" value="1" <?php echo !$accounts ? 'disabled' : ''; ?>>Save beneficiary</button>
            </form><?php } ?>
          </div></div>
          <div class="card"><div class="card-header"><h3 class="card-title">Saved beneficiaries</h3></div><div class="card-body table-responsive"><table class="table table-sm"><thead><tr><th>Name</th><th>Account</th><th>Linked to</th><th>Status</th><th></th></tr></thead><tbody>
            <?php foreach ($beneficiaries as $beneficiary) { ?><tr><td><?php echo rb_e($beneficiary['name']); ?></td><td>••••<?php echo rb_e(substr($beneficiary['receiving_account_number'], -4)); ?></td><td>#<?php echo (int) $beneficiary['account_id']; ?></td><td><?php echo rb_e($beneficiary['status']); ?></td><td><?php if ($beneficiary['status'] === 'active') { ?><form method="post"><button class="btn btn-sm btn-outline-danger" name="remove_beneficiary" value="<?php echo (int) $beneficiary['beneficiary_id']; ?>">Remove</button></form><?php } ?></td></tr><?php } ?>
            <?php if (!$beneficiaries) { ?><tr><td colspan="5" class="text-muted">No saved beneficiaries.</td></tr><?php } ?>
          </tbody></table></div></div>
        </div>
        <div class="col-lg-6">
          <div class="card"><div class="card-header"><h3 class="card-title">Request a cheque book</h3></div><div class="card-body">
            <?php if ($ready) { ?><form method="post">
              <div class="form-group"><label for="cheque_account_id">Account</label><select class="form-control" id="cheque_account_id" name="cheque_account_id" required><?php foreach ($accounts as $account) { ?><option value="<?php echo (int) $account['account_id']; ?>"><?php echo rb_e($account['acc_type'] . ' · ••••' . substr($account['account_number'], -4)); ?></option><?php } ?></select></div>
              <div class="form-group"><label for="leaf_count">Number of leaves</label><select class="form-control" id="leaf_count" name="leaf_count"><option value="10">10</option><option value="25">25</option><option value="50">50</option></select></div>
              <button class="btn btn-outline-success" name="request_cheque_book" value="1" <?php echo !$accounts ? 'disabled' : ''; ?>>Submit request</button>
            </form><?php } ?>
            <hr><h5>Request history</h5><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Account</th><th>Leaves</th><th>Status</th><th>Requested</th></tr></thead><tbody>
              <?php foreach ($cheque_requests as $request) { ?><tr><td>••••<?php echo rb_e(substr($request['account_number'], -4)); ?></td><td><?php echo (int) $request['leaf_count']; ?></td><td><?php echo rb_e($request['status']); ?></td><td><?php echo rb_e($request['created_at']); ?></td></tr><?php } ?>
              <?php if (!$cheque_requests) { ?><tr><td colspan="4" class="text-muted">No cheque-book requests.</td></tr><?php } ?>
            </tbody></table></div>
          </div></div>
          <div class="card"><div class="card-header"><h3 class="card-title">Email-only support</h3></div><div class="card-body">
            <?php if ($ready) { ?><form method="post"><div class="form-group"><label for="subject">Subject</label><input class="form-control" id="subject" name="subject" maxlength="120" required></div><div class="form-group"><label for="message">Message</label><textarea class="form-control" id="message" name="message" rows="4" maxlength="4000" required></textarea></div><button class="btn btn-success" name="create_ticket" value="1">Submit ticket</button></form><?php } ?>
            <hr><h5>Ticket history</h5>
            <?php foreach ($tickets as $ticket) { ?><div class="border-top py-2"><strong>#<?php echo (int) $ticket['ticket_id']; ?> · <?php echo rb_e($ticket['subject']); ?></strong><span class="badge badge-light"><?php echo rb_e($ticket['status']); ?></span><div class="small text-muted"><?php echo rb_e($ticket['created_at']); ?></div><?php if ($ticket['admin_response'] !== null && $ticket['admin_response'] !== '') { ?><div class="alert alert-light mt-2 mb-0"><strong>Admin response:</strong><br><?php echo nl2br(rb_e($ticket['admin_response'])); ?></div><?php } ?></div><?php } ?>
            <?php if (!$tickets) { ?><p class="text-muted">No support tickets.</p><?php } ?>
          </div></div>
        </div>
      </div>
    </div></section>
  </div>
  <?php include("dist/_partials/footer.php"); ?>
</div>
<script src="plugins/jquery/jquery.min.js"></script><script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script><script src="dist/js/adminlte.min.js"></script>
</body>
</html>
