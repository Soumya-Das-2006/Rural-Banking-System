<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();
$client_id = (int) $_SESSION['client_id'];
$error = '';
$success = '';
$products_ready = true;
$accounts = array();
$loans = array();
$repayments = array();
$fixed_deposits = array();
$recurring_deposits = array();
$loan_rate = 11.5;
try {
    $mysqli->query('SELECT loan_id FROM ib_loans LIMIT 0');
} catch (Throwable $e) {
    $products_ready = false;
}

function rb_product_emi($principal, $annual_rate, $months)
{
    $monthly_rate = $annual_rate / 1200;
    if ($monthly_rate <= 0) {
        return round($principal / $months, 2);
    }
    $factor = pow(1 + $monthly_rate, $months);
    return round($principal * $monthly_rate * $factor / ($factor - 1), 2);
}

function rb_product_account_owned($db, $account_id, $client_id)
{
    $stmt = $db->prepare("SELECT account_id FROM ib_bankaccounts WHERE account_id = ? AND client_id = ? AND LOWER(TRIM(acc_status)) = 'active' LIMIT 1");
    $stmt->bind_param('ii', $account_id, $client_id);
    $stmt->execute();
    $stmt->store_result();
    $valid = $stmt->num_rows === 1;
    $stmt->close();
    return (bool) $valid;
}

$stmt = $mysqli->prepare("SELECT account_id, account_number, acc_type FROM ib_bankaccounts WHERE client_id = ? AND LOWER(TRIM(acc_status)) = 'active' ORDER BY account_id");
$stmt->bind_param('i', $client_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) { $accounts[] = $row; }
$stmt->close();

if ($products_ready && isset($_POST['apply_loan'])) {
    $account_id = filter_var(isset($_POST['account_id']) ? $_POST['account_id'] : null, FILTER_VALIDATE_INT);
    $amount = rb_money_parse(isset($_POST['loan_amount']) ? $_POST['loan_amount'] : null);
    $months = filter_var(isset($_POST['loan_term']) ? $_POST['loan_term'] : null, FILTER_VALIDATE_INT);
    if (!$account_id || !$amount || $amount > 5000000 || !$months || $months < 3 || $months > 120 || !rb_product_account_owned($mysqli, $account_id, $client_id)) {
        $error = 'Choose an active account and enter a loan amount up to ₹50,00,000 with a 3–120 month term.';
    } else {
        $emi = rb_product_emi($amount, $loan_rate, $months);
        $amount_value = number_format($amount, 2, '.', '');
        $rate_value = number_format($loan_rate, 3, '.', '');
        $emi_value = number_format($emi, 2, '.', '');
        $stmt = $mysqli->prepare("INSERT INTO ib_loans (client_id, account_id, requested_amount, annual_rate, term_months, monthly_emi, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
        $stmt->bind_param('iissis', $client_id, $account_id, $amount_value, $rate_value, $months, $emi_value);
        if ($stmt->execute()) { $success = 'Loan application submitted for manual review.'; }
        else { $error = 'Could not save your loan application.'; }
        $stmt->close();
    }
}

if ($products_ready && (isset($_POST['create_fd']) || isset($_POST['create_rd']))) {
    $is_fd = isset($_POST['create_fd']);
    $account_id = filter_var(isset($_POST['account_id']) ? $_POST['account_id'] : null, FILTER_VALIDATE_INT);
    $amount = rb_money_parse(isset($_POST['product_amount']) ? $_POST['product_amount'] : null);
    $months = filter_var(isset($_POST['product_term']) ? $_POST['product_term'] : null, FILTER_VALIDATE_INT);
    $min_months = $is_fd ? 3 : 6;
    if (!$account_id || !$amount || $amount > 10000000 || !$months || $months < $min_months || $months > 120 || !rb_product_account_owned($mysqli, $account_id, $client_id)) {
        $error = 'Choose an active account and enter a valid amount and term.';
    } else {
        $rate = $is_fd ? 7.0 : 6.5;
        if ($is_fd) {
            $maturity = $amount * pow(1 + $rate / 400, 4 * $months / 12);
            $stmt = $mysqli->prepare("INSERT INTO ib_fixed_deposits (client_id, account_id, principal, annual_rate, term_months, maturity_amount, status, matures_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', DATE_ADD(CURDATE(), INTERVAL ? MONTH))");
        } else {
            $monthly_rate = $rate / 1200;
            $maturity = $amount * ((pow(1 + $monthly_rate, $months) - 1) / $monthly_rate) * (1 + $monthly_rate);
            $stmt = $mysqli->prepare("INSERT INTO ib_recurring_deposits (client_id, account_id, monthly_amount, annual_rate, term_months, maturity_estimate, status, next_due_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', DATE_ADD(CURDATE(), INTERVAL 1 MONTH))");
        }
        $amount_value = number_format($amount, 2, '.', '');
        $rate_value = number_format($rate, 3, '.', '');
        $maturity_value = number_format($maturity, 2, '.', '');
        if ($is_fd) {
            $stmt->bind_param('iissisi', $client_id, $account_id, $amount_value, $rate_value, $months, $maturity_value, $months);
        } else {
            $stmt->bind_param('iissis', $client_id, $account_id, $amount_value, $rate_value, $months, $maturity_value);
        }
        if ($stmt->execute()) { $success = ($is_fd ? 'Fixed' : 'Recurring') . ' deposit request saved for review. No funds were moved.'; }
        else { $error = 'Could not save the deposit request.'; }
        $stmt->close();
    }
}

if ($products_ready && isset($_POST['pay_installment'])) {
    $repayment_id = filter_var(isset($_POST['repayment_id']) ? $_POST['repayment_id'] : null, FILTER_VALIDATE_INT);
    $account_id = filter_var(isset($_POST['account_id']) ? $_POST['account_id'] : null, FILTER_VALIDATE_INT);
    if (!$repayment_id || !$account_id || !rb_product_account_owned($mysqli, $account_id, $client_id)) {
        $error = 'Choose one of your active accounts and a valid installment.';
    } else {
        $stmt = $mysqli->prepare("UPDATE ib_loan_repayments r JOIN ib_loans l ON l.loan_id = r.loan_id SET r.status = 'processing' WHERE r.repayment_id = ? AND l.client_id = ? AND l.status = 'approved' AND r.status = 'due' AND r.due_date <= CURDATE()");
        $stmt->bind_param('ii', $repayment_id, $client_id);
        $stmt->execute();
        $claimed = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$claimed) {
            $error = 'This installment is not currently due or has already been processed.';
        } else {
            $stmt = $mysqli->prepare('SELECT total_amount FROM ib_loan_repayments WHERE repayment_id = ?');
            $stmt->bind_param('i', $repayment_id);
            $stmt->execute();
            $stmt->bind_result($total_amount);
            $stmt->fetch();
            $stmt->close();
            $txn = rb_post_transaction($mysqli, array(
                'role' => 'client',
                'actor_client_id' => $client_id,
                'type' => 'Withdrawal',
                'account_id' => $account_id,
                'amount' => $total_amount,
            ));
            if (!$txn['ok']) {
                $stmt = $mysqli->prepare("UPDATE ib_loan_repayments SET status = 'due' WHERE repayment_id = ? AND status = 'processing'");
                $stmt->bind_param('i', $repayment_id);
                $stmt->execute();
                $stmt->close();
                $error = $txn['msg'];
            } else {
                $stmt = $mysqli->prepare("UPDATE ib_loan_repayments SET status = 'paid', transaction_id = (SELECT tr_id FROM ib_transactions WHERE tr_code = ?), paid_at = CURRENT_TIMESTAMP WHERE repayment_id = ? AND status = 'processing'");
                $stmt->bind_param('si', $txn['code'], $repayment_id);
                if ($stmt->execute() && $stmt->affected_rows === 1) {
                    $success = 'Installment payment recorded through your account.';
                } else {
                    $error = 'The ledger withdrawal succeeded, but the installment record needs administrator reconciliation.';
                    error_log('[RuralBanking] paid loan installment could not be reconciled: ' . $stmt->error);
                }
                $stmt->close();
            }
        }
    }
}

try {
    $stmt = $mysqli->prepare('SELECT loan_id, requested_amount, annual_rate, term_months, monthly_emi, status, review_note, created_at FROM ib_loans WHERE client_id = ? ORDER BY created_at DESC LIMIT 50');
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $loans[] = $row; }
    $stmt->close();
    $stmt = $mysqli->prepare("SELECT r.repayment_id, r.loan_id, r.installment_no, r.due_date, r.total_amount, r.status, l.monthly_emi
                              FROM ib_loan_repayments r JOIN ib_loans l ON l.loan_id = r.loan_id
                              WHERE l.client_id = ? AND r.status = 'due' ORDER BY r.due_date LIMIT 50");
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $repayments[] = $row; }
    $stmt->close();
    $stmt = $mysqli->prepare('SELECT fd_id, principal, annual_rate, term_months, maturity_amount, matures_at, status FROM ib_fixed_deposits WHERE client_id = ? ORDER BY created_at DESC LIMIT 50');
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $fixed_deposits[] = $row; }
    $stmt->close();
    $stmt = $mysqli->prepare('SELECT rd_id, monthly_amount, annual_rate, term_months, maturity_estimate, next_due_at, status FROM ib_recurring_deposits WHERE client_id = ? ORDER BY created_at DESC LIMIT 50');
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $recurring_deposits[] = $row; }
    $stmt->close();
} catch (Throwable $e) {
    $products_ready = false;
    $error = 'Financial products are not ready. Import database/advanced-features-migration.sql.';
    error_log('[RuralBanking] customer financial products load failed: ' . $e->getMessage());
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
    <section class="content-header"><div class="container-fluid"><h1>Loans &amp; Deposits</h1><p class="text-muted">Review loan applications, repayments and deposit requests. Calculations are estimates. <a href="../service_information.php">Service Information</a></p></div></section>
    <section class="content"><div class="container-fluid">
      <?php if ($error !== '') { ?><div class="alert alert-warning" role="alert"><?php echo rb_e($error); ?></div><?php } ?>
      <?php if ($success !== '') { ?><div class="alert alert-success" role="status"><?php echo rb_e($success); ?></div><?php } ?>
      <?php if (!$products_ready) { ?><p>Import <code>database/advanced-features-migration.sql</code> to enable these workflows.</p><?php } else { ?>
      <div class="row">
        <div class="col-lg-4"><div class="card"><div class="card-header"><h3 class="card-title">Loan application</h3></div><div class="card-body">
          <form method="post">
            <div class="form-group"><label>Credit to account</label><select class="form-control" name="account_id" required><?php foreach ($accounts as $account) { ?><option value="<?php echo (int) $account['account_id']; ?>"><?php echo rb_e($account['acc_type'] . ' · ••••' . substr($account['account_number'], -4)); ?></option><?php } ?></select></div>
            <div class="form-group"><label>Requested amount (INR)</label><input class="form-control" type="number" name="loan_amount" min="1" max="5000000" step="0.01" required></div>
            <div class="form-group"><label>Term (months)</label><input class="form-control" type="number" name="loan_term" min="3" max="120" value="12" required></div>
            <p class="small text-muted">Indicative rate: 11.5% p.a. EMI is an estimate. Applications require review.</p>
            <button class="btn btn-success" name="apply_loan" value="1" <?php echo !$accounts ? 'disabled' : ''; ?>>Submit application</button>
          </form>
        </div></div></div>
        <div class="col-lg-4"><div class="card"><div class="card-header"><h3 class="card-title">Fixed deposit request</h3></div><div class="card-body">
          <form method="post">
            <div class="form-group"><label>Account</label><select class="form-control" name="account_id" required><?php foreach ($accounts as $account) { ?><option value="<?php echo (int) $account['account_id']; ?>"><?php echo rb_e($account['acc_type'] . ' · ••••' . substr($account['account_number'], -4)); ?></option><?php } ?></select></div>
            <div class="form-group"><label>Principal (INR)</label><input class="form-control" type="number" name="product_amount" min="1" step="0.01" required></div>
            <div class="form-group"><label>Term (3–60 months)</label><input class="form-control" type="number" name="product_term" min="3" max="60" value="12" required></div>
            <p class="small text-muted">Indicative 7% p.a., quarterly compounding. Maturity value is an estimate.</p>
            <button class="btn btn-outline-success" name="create_fd" value="1" <?php echo !$accounts ? 'disabled' : ''; ?>>Request FD</button>
          </form>
        </div></div></div>
        <div class="col-lg-4"><div class="card"><div class="card-header"><h3 class="card-title">Recurring deposit request</h3></div><div class="card-body">
          <form method="post">
            <div class="form-group"><label>Account</label><select class="form-control" name="account_id" required><?php foreach ($accounts as $account) { ?><option value="<?php echo (int) $account['account_id']; ?>"><?php echo rb_e($account['acc_type'] . ' · ••••' . substr($account['account_number'], -4)); ?></option><?php } ?></select></div>
            <div class="form-group"><label>Monthly amount (INR)</label><input class="form-control" type="number" name="product_amount" min="1" step="0.01" required></div>
            <div class="form-group"><label>Term (6–120 months)</label><input class="form-control" type="number" name="product_term" min="6" max="120" value="12" required></div>
            <p class="small text-muted">Indicative 6.5% p.a. Maturity is estimated; installments are not automatic.</p>
            <button class="btn btn-outline-success" name="create_rd" value="1" <?php echo !$accounts ? 'disabled' : ''; ?>>Request RD</button>
          </form>
        </div></div></div>
      </div>
      <div class="card"><div class="card-header"><h3 class="card-title">My loan applications &amp; EMI schedule</h3></div><div class="card-body table-responsive">
        <table class="table table-sm"><thead><tr><th>Application</th><th>Requested</th><th>EMI</th><th>Term</th><th>Status</th><th>Note</th></tr></thead><tbody>
        <?php foreach ($loans as $loan) { ?><tr><td>#<?php echo (int) $loan['loan_id']; ?></td><td><?php echo rb_e(rb_money($loan['requested_amount'])); ?></td><td><?php echo rb_e(rb_money($loan['monthly_emi'])); ?></td><td><?php echo (int) $loan['term_months']; ?></td><td><?php echo rb_e($loan['status']); ?></td><td><?php echo rb_e($loan['review_note']); ?></td></tr><?php } ?>
        <?php if (!$loans) { ?><tr><td colspan="6" class="text-muted">No loan applications yet.</td></tr><?php } ?>
        </tbody></table>
        <h5>Due installments</h5>
        <?php foreach ($repayments as $repayment) { ?>
          <form method="post" class="form-inline border-top py-2">
            <input type="hidden" name="repayment_id" value="<?php echo (int) $repayment['repayment_id']; ?>">
            <span class="mr-3">Loan #<?php echo (int) $repayment['loan_id']; ?> · installment <?php echo (int) $repayment['installment_no']; ?> · <?php echo rb_e($repayment['due_date']); ?> · <?php echo rb_e(rb_money($repayment['total_amount'])); ?></span>
            <select class="form-control form-control-sm mr-2" name="account_id" required><?php foreach ($accounts as $account) { ?><option value="<?php echo (int) $account['account_id']; ?>"><?php echo rb_e('••••' . substr($account['account_number'], -4)); ?></option><?php } ?></select>
            <button class="btn btn-sm btn-primary" name="pay_installment" value="1" <?php echo !$accounts ? 'disabled' : ''; ?>>Pay installment</button>
          </form>
        <?php } ?>
      </div></div>
      <div class="row">
        <div class="col-md-6"><div class="card"><div class="card-header"><h3 class="card-title">Fixed deposits</h3></div><div class="card-body"><?php foreach ($fixed_deposits as $fd) { ?><p>₹<?php echo rb_e(rb_money($fd['principal'])); ?> · estimate ₹<?php echo rb_e(rb_money($fd['maturity_amount'])); ?> · <?php echo (int) $fd['term_months']; ?> months · <?php echo rb_e($fd['status']); ?></p><?php } if (!$fixed_deposits) { ?><p class="text-muted">No FD requests.</p><?php } ?></div></div></div>
        <div class="col-md-6"><div class="card"><div class="card-header"><h3 class="card-title">Recurring deposits</h3></div><div class="card-body"><?php foreach ($recurring_deposits as $rd) { ?><p>₹<?php echo rb_e(rb_money($rd['monthly_amount'])); ?>/month · estimate ₹<?php echo rb_e(rb_money($rd['maturity_estimate'])); ?> · <?php echo (int) $rd['term_months']; ?> months · <?php echo rb_e($rd['status']); ?></p><?php } if (!$recurring_deposits) { ?><p class="text-muted">No RD requests.</p><?php } ?></div></div></div>
      </div>
      <?php } ?>
    </div></section>
  </div>
  <?php include("dist/_partials/footer.php"); ?>
</div>
<script src="plugins/jquery/jquery.min.js"></script><script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script><script src="dist/js/adminlte.min.js"></script>
</body>
</html>
