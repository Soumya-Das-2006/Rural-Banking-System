<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();
$admin_id = (int) $_SESSION['admin_id'];
$error = '';
$success = '';
$ready = true;

function rb_admin_finalize_loan($db, $loan_id, $admin_id, $note)
{
    $db->begin_transaction();
    try {
        $stmt = $db->prepare('SELECT client_id, account_id, requested_amount, annual_rate, term_months, monthly_emi, status, disbursement_reference
                              FROM ib_loans WHERE loan_id = ? FOR UPDATE');
        $stmt->bind_param('i', $loan_id);
        $stmt->execute();
        $stmt->bind_result($client_id, $account_id, $principal, $annual_rate, $term_months, $monthly_emi, $status, $reference);
        if (!$stmt->fetch()) {
            $stmt->close();
            throw new RuntimeException('Loan application details are unavailable.');
        }
        $stmt->close();
        if ($status !== 'processing' || $reference === '') {
            throw new RuntimeException('This application has no recorded disbursement to reconcile.');
        }

        $stmt = $db->prepare("SELECT tr_id FROM ib_transactions
                              WHERE tr_code = ? AND account_id = ? AND client_id = ? AND tr_type = 'Deposit'
                                AND transaction_amt = ? AND tr_status = 'Success' LIMIT 1");
        $stmt->bind_param('siis', $reference, $account_id, $client_id, $principal);
        $stmt->execute();
        $stmt->store_result();
        $deposit_exists = $stmt->num_rows === 1;
        $stmt->close();
        if (!$deposit_exists) {
            throw new RuntimeException('The matching ledger deposit was not found; reconcile this application manually.');
        }

        $stmt = $db->prepare('SELECT COUNT(*) FROM ib_loan_repayments WHERE loan_id = ?');
        $stmt->bind_param('i', $loan_id);
        $stmt->execute();
        $stmt->bind_result($schedule_count);
        $stmt->fetch();
        $stmt->close();
        if ((int) $schedule_count !== 0) {
            throw new RuntimeException('A repayment schedule already exists; reconcile this application manually.');
        }

        $balance = (float) $principal;
        $monthly_rate = (float) $annual_rate / 1200;
        $insert = $db->prepare("INSERT INTO ib_loan_repayments
                                (loan_id, installment_no, due_date, principal_amount, interest_amount, total_amount, status)
                                VALUES (?, ?, ?, ?, ?, ?, 'due')");
        if (!$insert) {
            throw new RuntimeException('Could not prepare the repayment schedule.');
        }
        for ($installment = 1; $installment <= (int) $term_months; $installment++) {
            $interest = round($balance * $monthly_rate, 2);
            $installment_principal = round((float) $monthly_emi - $interest, 2);
            if ($installment === (int) $term_months || $installment_principal > $balance) {
                $installment_principal = $balance;
            }
            $total = round($installment_principal + $interest, 2);
            $balance = max(0, round($balance - $installment_principal, 2));
            $due_date = date('Y-m-d', strtotime('+' . $installment . ' months'));
            $principal_value = number_format($installment_principal, 2, '.', '');
            $interest_value = number_format($interest, 2, '.', '');
            $total_value = number_format($total, 2, '.', '');
            $insert->bind_param('iissss', $loan_id, $installment, $due_date, $principal_value, $interest_value, $total_value);
            if (!$insert->execute()) {
                $insert->close();
                throw new RuntimeException('Could not create the complete repayment schedule.');
            }
        }
        $insert->close();
        $approved = 'approved';
        $stmt = $db->prepare("UPDATE ib_loans SET status = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP,
                              review_note = ? WHERE loan_id = ? AND status = 'processing'");
        $stmt->bind_param('sisi', $approved, $admin_id, $note, $loan_id);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('Could not finalize the loan review.');
        }
        $stmt->close();
        $db->commit();
        return $reference;
    } catch (Throwable $e) {
        try { $db->rollback(); } catch (Throwable $rollbackError) {}
        throw $e;
    }
}

try {
    $mysqli->query('SELECT loan_id FROM ib_loans LIMIT 0');
} catch (Throwable $e) {
    $ready = false;
}

if ($ready && isset($_POST['decision'], $_POST['loan_id'])) {
    $loan_id = filter_var($_POST['loan_id'], FILTER_VALIDATE_INT);
    $decision = isset($_POST['decision']) ? (string) $_POST['decision'] : '';
    $note = trim((string) (isset($_POST['review_note']) ? $_POST['review_note'] : ''));
    if (!$loan_id || !in_array($decision, array('approve', 'reject'), true) || strlen($note) > 500) {
        $error = 'Choose a valid application decision and a note of at most 500 characters.';
    } elseif ($decision === 'reject') {
        $status = 'rejected';
        $stmt = $mysqli->prepare("UPDATE ib_loans SET status = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, review_note = ? WHERE loan_id = ? AND status = 'pending'");
        $stmt->bind_param('sisi', $status, $admin_id, $note, $loan_id);
        if ($stmt->execute() && $stmt->affected_rows === 1) {
            $success = 'Loan application rejected.';
        } else {
            $error = 'This application is no longer pending.';
        }
        $stmt->close();
    } else {
        try {
            $stmt = $mysqli->prepare("UPDATE ib_loans SET status = 'processing' WHERE loan_id = ? AND status = 'pending'");
            $stmt->bind_param('i', $loan_id);
            $stmt->execute();
            $claimed = $stmt->affected_rows === 1;
            $stmt->close();
            if (!$claimed) {
                throw new RuntimeException('This application is no longer pending.');
            }

            $stmt = $mysqli->prepare('SELECT client_id, account_id, requested_amount, annual_rate, term_months, monthly_emi FROM ib_loans WHERE loan_id = ?');
            $stmt->bind_param('i', $loan_id);
            $stmt->execute();
            $stmt->bind_result($client_id, $account_id, $principal, $annual_rate, $term_months, $monthly_emi);
            if (!$stmt->fetch()) {
                $stmt->close();
                throw new RuntimeException('Loan application details are unavailable.');
            }
            $stmt->close();

            $txn = rb_post_transaction($mysqli, array(
                'role' => 'admin',
                'actor_client_id' => (int) $client_id,
                'type' => 'Deposit',
                'account_id' => (int) $account_id,
                'amount' => $principal,
            ));
            if (!$txn['ok']) {
                $stmt = $mysqli->prepare("UPDATE ib_loans SET status = 'pending' WHERE loan_id = ? AND status = 'processing'");
                $stmt->bind_param('i', $loan_id);
                $stmt->execute();
                $stmt->close();
                throw new RuntimeException($txn['msg']);
            }

            $stmt = $mysqli->prepare("UPDATE ib_loans SET disbursement_reference = ? WHERE loan_id = ? AND status = 'processing' AND disbursement_reference = ''");
            $stmt->bind_param('si', $txn['code'], $loan_id);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('The ledger deposit succeeded, but its reference could not be saved. Reconcile this application manually.');
            }
            $stmt->close();
            rb_admin_finalize_loan($mysqli, $loan_id, $admin_id, $note);
            $success = 'Application approved; funds credited to the internal account ledger and EMI schedule created.';

            $actor_role = 'admin';
            $action = 'loan_approved';
            $entity_type = 'loan';
            $entity_id = (string) $loan_id;
            $audit = $mysqli->prepare('INSERT INTO ib_audit_log (actor_role, actor_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?, ?)');
            if ($audit) {
                $audit->bind_param('sissss', $actor_role, $admin_id, $action, $entity_type, $entity_id, $txn['code']);
                $audit->execute();
                $audit->close();
            }
        } catch (Throwable $e) {
            try { $mysqli->rollback(); } catch (Throwable $rollbackError) {}
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Loan approval failed. Check whether a ledger deposit was already recorded before retrying.';
            error_log('[RuralBanking] loan approval failed: ' . $e->getMessage());
        }
    }
}

if ($ready && isset($_POST['finalize_loan'], $_POST['loan_id'])) {
    $loan_id = filter_var($_POST['loan_id'], FILTER_VALIDATE_INT);
    $note = trim((string) (isset($_POST['review_note']) ? $_POST['review_note'] : ''));
    if (!$loan_id || strlen($note) > 500) {
        $error = 'Choose a valid application and a note of at most 500 characters.';
    } else {
        try {
            $reference = rb_admin_finalize_loan($mysqli, $loan_id, $admin_id, $note);
            $success = 'Repayment schedule reconciled for the existing disbursement. No duplicate deposit was made.';
            $actor_role = 'admin';
            $action = 'loan_reconciled';
            $entity_type = 'loan';
            $entity_id = (string) $loan_id;
            $audit = $mysqli->prepare('INSERT INTO ib_audit_log (actor_role, actor_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?, ?)');
            if ($audit) {
                $audit->bind_param('sissss', $actor_role, $admin_id, $action, $entity_type, $entity_id, $reference);
                $audit->execute();
                $audit->close();
            }
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Loan reconciliation failed.';
            error_log('[RuralBanking] loan reconciliation failed: ' . $e->getMessage());
        }
    }
}

if ($ready && isset($_POST['set_product_status'], $_POST['product_id'], $_POST['product_type'])) {
    $product_id = filter_var($_POST['product_id'], FILTER_VALIDATE_INT);
    $product_type = (string) $_POST['product_type'];
    $status = (string) $_POST['set_product_status'];
    if (!$product_id || !in_array($status, array('active', 'matured', 'cancelled'), true)
        || !in_array($product_type, array('fd', 'rd'), true)) {
        $error = 'Invalid deposit product status request.';
    } else {
        $table = $product_type === 'fd' ? 'ib_fixed_deposits' : 'ib_recurring_deposits';
        $id_column = $product_type === 'fd' ? 'fd_id' : 'rd_id';
        $stmt = $mysqli->prepare("UPDATE $table SET status = ? WHERE $id_column = ? AND status IN ('pending','active')");
        $stmt->bind_param('si', $status, $product_id);
        if ($stmt->execute() && $stmt->affected_rows === 1) {
            $success = strtoupper($product_type) . ' status updated.';
        } else {
            $error = 'The deposit product is no longer pending or active.';
        }
        $stmt->close();
    }
}

$loans = array();
$fixed_deposits = array();
$recurring_deposits = array();
if ($ready) {
    try {
        $result = $mysqli->query("SELECT l.loan_id, l.requested_amount, l.annual_rate, l.term_months, l.monthly_emi, l.status, l.created_at, l.disbursement_reference, c.name, c.email, a.account_number
                                  FROM ib_loans l JOIN ib_clients c ON c.client_id = l.client_id
                                  JOIN ib_bankaccounts a ON a.account_id = l.account_id
                                  ORDER BY (l.status = 'pending') DESC, l.created_at DESC LIMIT 300");
        while ($result && ($row = $result->fetch_assoc())) { $loans[] = $row; }
        $result = $mysqli->query("SELECT fd.fd_id, fd.principal, fd.maturity_amount, fd.term_months, fd.matures_at, fd.status, c.name
                                  FROM ib_fixed_deposits fd JOIN ib_clients c ON c.client_id = fd.client_id
                                  ORDER BY (fd.status = 'pending') DESC, fd.created_at DESC LIMIT 200");
        while ($result && ($row = $result->fetch_assoc())) { $fixed_deposits[] = $row; }
        $result = $mysqli->query("SELECT rd.rd_id, rd.monthly_amount, rd.maturity_estimate, rd.term_months, rd.next_due_at, rd.status, c.name
                                  FROM ib_recurring_deposits rd JOIN ib_clients c ON c.client_id = rd.client_id
                                  ORDER BY (rd.status = 'pending') DESC, rd.created_at DESC LIMIT 200");
        while ($result && ($row = $result->fetch_assoc())) { $recurring_deposits[] = $row; }
    } catch (Throwable $e) {
        $ready = false;
        $error = 'Could not load financial product applications.';
        error_log('[RuralBanking] admin product list failed: ' . $e->getMessage());
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
    <section class="content-header"><div class="container-fluid"><h1>Loan &amp; Deposit Management</h1><p class="text-muted">Review applications, repayments and deposit requests. <a href="../service_information.php">Service Information</a></p></div></section>
    <section class="content"><div class="container-fluid">
      <?php if (!$ready) { ?><div class="alert alert-warning">Import <code>database/advanced-features-migration.sql</code> before using financial products.</div><?php } ?>
      <?php if ($error !== '') { ?><div class="alert alert-danger" role="alert"><?php echo rb_e($error); ?></div><?php } ?>
      <?php if ($success !== '') { ?><div class="alert alert-success" role="status"><?php echo rb_e($success); ?></div><?php } ?>
      <div class="card"><div class="card-header"><h3 class="card-title">Loan applications</h3></div><div class="card-body table-responsive">
        <table class="table table-hover"><thead><tr><th>Customer</th><th>Account</th><th>Amount</th><th>EMI</th><th>Term</th><th>Status</th><th>Review</th></tr></thead><tbody>
        <?php foreach ($loans as $loan) { ?>
          <tr><td><?php echo rb_e($loan['name']); ?><br><small><?php echo rb_e($loan['email']); ?></small></td><td>••••<?php echo rb_e(substr($loan['account_number'], -4)); ?></td><td><?php echo rb_e(rb_money($loan['requested_amount'])); ?></td><td><?php echo rb_e(rb_money($loan['monthly_emi'])); ?></td><td><?php echo (int) $loan['term_months']; ?> mo</td><td><?php echo rb_e($loan['status']); ?></td><td>
          <?php if ($loan['status'] === 'pending') { ?><form method="post"><input type="hidden" name="loan_id" value="<?php echo (int) $loan['loan_id']; ?>"><input class="form-control form-control-sm mb-1" name="review_note" maxlength="500" placeholder="Review note"><button class="btn btn-sm btn-success" name="decision" value="approve" type="submit">Approve</button> <button class="btn btn-sm btn-outline-danger" name="decision" value="reject" type="submit">Reject</button></form><?php } elseif ($loan['status'] === 'processing' && $loan['disbursement_reference'] !== '') { ?><form method="post"><input type="hidden" name="loan_id" value="<?php echo (int) $loan['loan_id']; ?>"><button class="btn btn-sm btn-outline-warning" name="finalize_loan" value="1">Reconcile schedule</button></form><?php } elseif ($loan['status'] === 'processing') { ?><span class="text-warning">Manual ledger reconciliation required</span><?php } else { ?><small>Ref: <?php echo rb_e($loan['created_at']); ?></small><?php } ?>
          </td></tr>
        <?php } ?>
        <?php if (!$loans) { ?><tr><td colspan="7" class="text-center text-muted">No loan applications.</td></tr><?php } ?>
        </tbody></table>
      </div></div>
      <div class="row">
        <div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Fixed deposits</h3></div><div class="card-body table-responsive"><table class="table table-sm"><thead><tr><th>Customer</th><th>Principal</th><th>Maturity estimate</th><th>Status</th><th>Action</th></tr></thead><tbody>
          <?php foreach ($fixed_deposits as $item) { ?><tr><td><?php echo rb_e($item['name']); ?></td><td><?php echo rb_e(rb_money($item['principal'])); ?></td><td><?php echo rb_e(rb_money($item['maturity_amount'])); ?> · <?php echo rb_e($item['matures_at']); ?></td><td><?php echo rb_e($item['status']); ?></td><td><?php if ($item['status'] === 'pending' || $item['status'] === 'active') { ?><form method="post"><input type="hidden" name="product_id" value="<?php echo (int) $item['fd_id']; ?>"><input type="hidden" name="product_type" value="fd"><button class="btn btn-sm btn-outline-success" name="set_product_status" value="active">Activate</button><button class="btn btn-sm btn-outline-primary" name="set_product_status" value="matured">Mark matured</button></form><?php } ?></td></tr><?php } ?>
        </tbody></table></div></div></div>
        <div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Recurring deposits</h3></div><div class="card-body table-responsive"><table class="table table-sm"><thead><tr><th>Customer</th><th>Monthly</th><th>Maturity estimate</th><th>Status</th><th>Action</th></tr></thead><tbody>
          <?php foreach ($recurring_deposits as $item) { ?><tr><td><?php echo rb_e($item['name']); ?></td><td><?php echo rb_e(rb_money($item['monthly_amount'])); ?></td><td><?php echo rb_e(rb_money($item['maturity_estimate'])); ?> · due <?php echo rb_e($item['next_due_at']); ?></td><td><?php echo rb_e($item['status']); ?></td><td><?php if ($item['status'] === 'pending' || $item['status'] === 'active') { ?><form method="post"><input type="hidden" name="product_id" value="<?php echo (int) $item['rd_id']; ?>"><input type="hidden" name="product_type" value="rd"><button class="btn btn-sm btn-outline-success" name="set_product_status" value="active">Activate</button><button class="btn btn-sm btn-outline-primary" name="set_product_status" value="matured">Mark matured</button></form><?php } ?></td></tr><?php } ?>
        </tbody></table></div></div></div>
      </div>
    </div></section>
  </div>
  <?php include("dist/_partials/footer.php"); ?>
</div>
<script src="plugins/jquery/jquery.min.js"></script><script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script><script src="dist/js/adminlte.min.js"></script>
</body>
</html>
