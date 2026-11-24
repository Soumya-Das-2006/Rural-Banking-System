<?php
/**
 * Online money transfer for customers: Details -> Review -> Confirm (password) -> Receipt.
 * (c) 2026 Soumya Das
 */
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();
$client_id = (int) $_SESSION['client_id'];

$daily_limit = (float) rb_env('ONLINE_DAILY_LIMIT', 50000);
$form_error  = '';
$step        = 'form';   // form | confirm | receipt
$receipt     = null;
$form        = array('from_account' => 0, 'receiving_acc_no' => '', 'amount' => '');

/* Own active accounts with live balances */
$accounts = array();
$beneficiaries = array();
$stmt = $mysqli->prepare("SELECT account_id, acc_name, account_number, acc_type FROM ib_bankaccounts WHERE client_id = ? AND LOWER(TRIM(acc_status)) = 'active' ORDER BY account_id");
$stmt->bind_param('i', $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $accounts[(int) $r['account_id']] = $r;
}
$stmt->close();
foreach ($accounts as $aid => $a) {
    $accounts[$aid]['balance'] = rb_account_balance($mysqli, $aid);
}
try {
    $stmt = $mysqli->prepare("SELECT beneficiary_id, name, receiving_account_number FROM ib_beneficiaries
                              WHERE client_id = ? AND status = 'active' ORDER BY name LIMIT 100");
    if ($stmt) {
        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) { $beneficiaries[] = $row; }
        $stmt->close();
    }
} catch (Throwable $e) {
    error_log('[RuralBanking] saved beneficiaries are unavailable: ' . $e->getMessage());
}

/* Resolve a local QR/UPI identifier on the server before showing a payment confirmation. */
$qr_prefilled = false;
$qr_fixed_amount = null;
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['request_code'])) {
    try {
        $request_code = (string) $_GET['request_code'];
        if (!preg_match('/^[a-f0-9]{32}$/iD', $request_code)) {
            throw new RuntimeException('Invalid payment request code.');
        }
        $request_code = strtolower($request_code);
        $stmt = $mysqli->prepare("SELECT q.qr_token, p.profile_id, p.upi_id, p.display_name, a.account_number, r.amount
                                  FROM ib_payment_requests r
                                  JOIN ib_upi_profiles p ON p.profile_id = r.payee_profile_id
                                  JOIN ib_qr_codes q ON q.profile_id = p.profile_id
                                  JOIN ib_bankaccounts a ON a.account_id = p.account_id AND a.client_id = p.client_id
                                  WHERE r.request_code = ? AND r.payer_client_id = ? AND r.status = 'pending'
                                    AND r.expires_at > CURRENT_TIMESTAMP AND p.status = 'active' AND q.status = 'active'
                                    AND LOWER(TRIM(a.acc_status)) = 'active' LIMIT 1");
        $stmt->bind_param('si', $request_code, $client_id);
        $stmt->execute();
        $stmt->bind_result($token, $profile_id, $upi_id, $display_name, $account_number, $request_amount);
        if (!$stmt->fetch()) {
            $stmt->close();
            throw new RuntimeException('This payment request is unavailable, expired, or not addressed to your account.');
        }
        $stmt->close();
        $qr_fixed_amount = number_format((float) $request_amount, 2, '.', '');
        $_SESSION['rb_qr_context'] = array(
            'token' => $token,
            'profile_id' => (int) $profile_id,
            'upi_id' => $upi_id,
            'display_name' => $display_name,
            'account_number' => $account_number,
            'amount' => $qr_fixed_amount,
            'request_code' => $request_code,
            'ts' => time(),
        );
        $form['receiving_acc_no'] = $account_number;
        $form['amount'] = $qr_fixed_amount;
        $qr_prefilled = true;
    } catch (Throwable $e) {
        unset($_SESSION['rb_qr_context']);
        $form_error = $e instanceof RuntimeException ? $e->getMessage() : 'This payment request is unavailable.';
        error_log('[RuralBanking] payment request lookup failed: ' . $e->getMessage());
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['qr_token']) || isset($_GET['upi_id']))) {
    try {
        if (isset($_GET['qr_token']) && preg_match('/^[a-f0-9]{32}$/i', (string) $_GET['qr_token'])) {
            $identifier = strtolower((string) $_GET['qr_token']);
            $lookupSql = "SELECT q.qr_token, p.profile_id, p.upi_id, p.display_name, a.account_number
                          FROM ib_qr_codes q
                          JOIN ib_upi_profiles p ON p.profile_id = q.profile_id
                          JOIN ib_bankaccounts a ON a.account_id = p.account_id AND a.client_id = p.client_id
                          WHERE q.qr_token = ? AND q.status = 'active' AND p.status = 'active'
                            AND LOWER(TRIM(a.acc_status)) = 'active' LIMIT 1";
        } elseif (isset($_GET['upi_id']) && preg_match('/^[a-z0-9._-]{4,32}@(bankofsoumya\.local|ruralbank\.demo)$/i', (string) $_GET['upi_id'])) {
            $identifier = strtolower((string) $_GET['upi_id']);
            $lookupSql = "SELECT q.qr_token, p.profile_id, p.upi_id, p.display_name, a.account_number
                          FROM ib_upi_profiles p
                          JOIN ib_qr_codes q ON q.profile_id = p.profile_id
                          JOIN ib_bankaccounts a ON a.account_id = p.account_id AND a.client_id = p.client_id
                          WHERE p.upi_id = ? AND q.status = 'active' AND p.status = 'active'
                            AND LOWER(TRIM(a.acc_status)) = 'active' LIMIT 1";
        } else {
            throw new RuntimeException('Invalid QR or virtual ID.');
        }
        $stmt = $mysqli->prepare($lookupSql);
        $stmt->bind_param('s', $identifier);
        $stmt->execute();
        $stmt->bind_result($token, $profile_id, $upi_id, $display_name, $account_number);
        if (!$stmt->fetch()) {
            $stmt->close();
            throw new RuntimeException('That QR profile or virtual ID is unavailable or disabled.');
        }
        $stmt->close();
        if (isset($_GET['amount']) && $_GET['amount'] !== '') {
            $amount = rb_money_parse($_GET['amount']);
            if ($amount === null) {
                throw new RuntimeException('The QR amount is invalid. Enter the amount again.');
            }
            $qr_fixed_amount = number_format($amount, 2, '.', '');
            $form['amount'] = $qr_fixed_amount;
        }
        $_SESSION['rb_qr_context'] = array(
            'token' => $token,
            'profile_id' => (int) $profile_id,
            'upi_id' => $upi_id,
            'display_name' => $display_name,
            'account_number' => $account_number,
            'amount' => $qr_fixed_amount,
            'ts' => time(),
        );
        $form['receiving_acc_no'] = $account_number;
        $qr_prefilled = true;
    } catch (Throwable $e) {
        unset($_SESSION['rb_qr_context']);
        $form_error = $e instanceof RuntimeException ? $e->getMessage() : 'The QR payment profile is unavailable. Import the QR migration and try again.';
        error_log('[RuralBanking] QR payment lookup failed: ' . $e->getMessage());
    }
}

/* How much has this customer already sent online today? */
function rb_sent_today($db, $client_id)
{
    $stmt = $db->prepare("SELECT COALESCE(SUM(transaction_amt),0) FROM ib_transactions WHERE client_id = ? AND tr_type = 'Transfer' AND DATE(created_at) = CURDATE()");
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $stmt->bind_result($sum);
    $stmt->fetch();
    $stmt->close();
    return (float) $sum;
}

/* ---------- Step 1 -> 2: review ---------- */
if (isset($_POST['review'])) {
    $form['from_account']      = (int) (isset($_POST['from_account']) ? $_POST['from_account'] : 0);
    $form['receiving_acc_no']  = trim((string) (isset($_POST['receiving_acc_no']) ? $_POST['receiving_acc_no'] : ''));
    $form['amount']            = trim((string) (isset($_POST['amount']) ? $_POST['amount'] : ''));
    $amount = rb_money_parse($form['amount']);
    $qr_context = isset($_SESSION['rb_qr_context']) ? $_SESSION['rb_qr_context'] : null;
    $qr_context_valid = $qr_context
        && isset($qr_context['account_number'], $qr_context['ts'])
        && (time() - (int) $qr_context['ts']) <= 600;

    if (!isset($accounts[$form['from_account']])) {
        $form_error = 'Choose one of your active accounts to send from.';
    } elseif (!preg_match('/^[0-9A-Za-z\-]{4,30}$/', $form['receiving_acc_no'])) {
        $form_error = 'Enter a valid receiving account number.';
    } elseif ($amount === null) {
        $form_error = 'Enter a valid amount greater than zero (maximum two decimals).';
    } elseif ($qr_context_valid && isset($qr_context['amount']) && $qr_context['amount'] !== null
        && abs($amount - (float) $qr_context['amount']) > 0.00001) {
        $form_error = 'The amount is fixed by this QR code and cannot be changed.';
    } elseif ($form['receiving_acc_no'] === $accounts[$form['from_account']]['account_number']) {
        $form_error = 'You cannot send money to the same account.';
    } else {
        $stmt = $mysqli->prepare('SELECT client_name, acc_status FROM ib_bankaccounts WHERE account_number = ? LIMIT 1');
        $stmt->bind_param('s', $form['receiving_acc_no']);
        $stmt->execute();
        $stmt->bind_result($holder, $recv_status);
        $found = $stmt->fetch();
        $stmt->close();

        if (!$found) {
            $form_error = 'We could not find that receiving account. Please check the number.';
        } elseif (strcasecmp(trim($recv_status), 'Active') !== 0) {
            $form_error = 'The receiving account is not active.';
        } elseif ($amount > $accounts[$form['from_account']]['balance'] + 0.00001) {
            $form_error = 'Insufficient funds. Available balance is ' . rb_money($accounts[$form['from_account']]['balance']) . '.';
        } elseif (rb_sent_today($mysqli, $client_id) + $amount > $daily_limit + 0.00001) {
            $form_error = 'This would exceed your daily online transfer limit of ' . rb_money($daily_limit) . '. Visit your branch for larger transfers.';
        } else {
            $channel = 'standard';
            if ($qr_context_valid
                && hash_equals($qr_context['account_number'], $form['receiving_acc_no'])) {
                $channel = 'upi_qr';
            } else {
                unset($_SESSION['rb_qr_context']);
            }
            $_SESSION['rb_send'] = array(
                'nonce'  => bin2hex(random_bytes(16)),
                'from'   => $form['from_account'],
                'to'     => $form['receiving_acc_no'],
                'holder' => $holder,
                'amount' => number_format($amount, 2, '.', ''),
                'channel' => $channel,
                'request_code' => $qr_context_valid && isset($qr_context['request_code']) ? $qr_context['request_code'] : '',
                'ts'     => time(),
            );
            $step = 'confirm';
        }
    }
}

/* ---------- Step 2 -> 3: confirm with password ---------- */
if (isset($_POST['confirm'])) {
    $p = isset($_SESSION['rb_send']) ? $_SESSION['rb_send'] : null;
    $nonce = isset($_POST['nonce']) ? (string) $_POST['nonce'] : '';
    if (!$p || !hash_equals($p['nonce'], $nonce) || (time() - $p['ts']) > 600) {
        unset($_SESSION['rb_send']);
        $form_error = 'This transfer request expired or was already used. Please start again.';
    } else {
        $step = 'confirm';
        $stmt = $mysqli->prepare('SELECT password FROM ib_clients WHERE client_id = ?');
        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $stmt->bind_result($stored);
        $stmt->fetch();
        $stmt->close();

        if (rb_login_locked($mysqli, 'transfer')) {
            $form_error = 'Too many wrong passwords. Please wait 15 minutes and try again.';
        } elseif (!rb_verify_password(isset($_POST['password']) ? $_POST['password'] : '', $stored)) {
            rb_login_record_failure($mysqli, 'transfer-' . $client_id);
            $form_error = 'Incorrect password. The transfer was not made.';
        } elseif (rb_sent_today($mysqli, $client_id) + (float) $p['amount'] > $daily_limit + 0.00001) {
            unset($_SESSION['rb_send']);
            $step = 'form';
            $form_error = 'This would exceed your daily online transfer limit of ' . rb_money($daily_limit) . '.';
        } else {
            unset($_SESSION['rb_send']);
            $request_code = isset($p['request_code']) ? (string) $p['request_code'] : '';
            $request_claimed = false;
            if ($request_code !== '') {
                try {
                    $stmt = $mysqli->prepare("UPDATE ib_payment_requests
                                              SET status = 'processing'
                                              WHERE request_code = ? AND payer_client_id = ? AND amount = ?
                                                AND status = 'pending' AND expires_at > CURRENT_TIMESTAMP");
                    $stmt->bind_param('sis', $request_code, $client_id, $p['amount']);
                    $stmt->execute();
                    $request_claimed = $stmt->affected_rows === 1;
                    $stmt->close();
                } catch (Throwable $e) {
                    error_log('[RuralBanking] payment request claim failed: ' . $e->getMessage());
                }
                if (!$request_claimed) {
                    $step = 'form';
                    $form_error = 'This payment request has expired, been cancelled, or was already processed.';
                }
            }

            if ($request_code === '' || $request_claimed) {
                $txn = rb_post_transaction($mysqli, array(
                    'role'             => 'client',
                    'actor_client_id'  => $client_id,
                    'type'             => 'Transfer',
                    'channel'          => isset($p['channel']) ? $p['channel'] : 'standard',
                    'account_id'       => $p['from'],
                    'amount'           => $p['amount'],
                    'receiving_acc_no' => $p['to'],
                ));
                if ($txn['ok']) {
                    $request_status_ok = true;
                    if ($request_claimed) {
                        try {
                            $stmt = $mysqli->prepare('SELECT tr_id FROM ib_transactions WHERE tr_code = ? AND client_id = ? LIMIT 1');
                            $stmt->bind_param('si', $txn['code'], $client_id);
                            $stmt->execute();
                            $stmt->bind_result($request_transaction_id);
                            if (!$stmt->fetch()) {
                                $stmt->close();
                                throw new RuntimeException('The payment transaction could not be located for request reconciliation.');
                            }
                            $stmt->close();
                            $stmt = $mysqli->prepare("UPDATE ib_payment_requests SET status = 'paid', transaction_id = ?
                                                      WHERE request_code = ? AND payer_client_id = ? AND status = 'processing'");
                            $stmt->bind_param('isi', $request_transaction_id, $request_code, $client_id);
                            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                                $stmt->close();
                                throw new RuntimeException('The payment request could not be marked paid.');
                            }
                            $stmt->close();
                        } catch (Throwable $e) {
                            $request_status_ok = false;
                            error_log('[RuralBanking] completed payment request needs reconciliation: ' . $e->getMessage());
                        }
                    }
                    unset($_SESSION['rb_qr_context']);
                    $step = 'receipt';
                    $receipt = array(
                        'code'   => $txn['code'],
                        'amount' => $p['amount'],
                        'channel' => isset($p['channel']) ? $p['channel'] : 'standard',
                        'from'   => isset($accounts[$p['from']]) ? $accounts[$p['from']]['account_number'] : '',
                        'to'     => $p['to'],
                        'holder' => $p['holder'],
                        'time'   => date('d M Y, h:i A'),
                        'request_status_warning' => !$request_status_ok,
                    );
                } else {
                    if ($request_claimed) {
                        $stmt = $mysqli->prepare("UPDATE ib_payment_requests SET status = 'pending'
                                                  WHERE request_code = ? AND payer_client_id = ? AND status = 'processing'");
                        $stmt->bind_param('si', $request_code, $client_id);
                        $stmt->execute();
                        $stmt->close();
                    }
                    $step = 'form';
                    $form_error = $txn['msg'];
                }
            }
        }
    }
}
$pending = ($step === 'confirm' && isset($_SESSION['rb_send'])) ? $_SESSION['rb_send'] : null;
?>
<!DOCTYPE html>
<html>
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
            <div class="col-sm-6"><h1 class="rb-static">Send Money</h1></div>
            <div class="col-sm-6">
              <ol class="breadcrumb float-sm-right">
                <li class="breadcrumb-item"><a href="pages_dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active">Send Money</li>
              </ol>
            </div>
          </div>
        </div>
      </section>

      <section class="content">
        <div class="container-fluid">
          <div class="row justify-content-center">
            <div class="col-lg-7 col-md-9">

              <ol class="rb-stepper" aria-label="Progress">
                <li class="<?php echo $step === 'form' ? 'active' : 'done'; ?>"><b>1</b><span>Details</span></li>
                <li class="<?php echo $step === 'confirm' ? 'active' : ($step === 'receipt' ? 'done' : ''); ?>"><b>2</b><span>Confirm</span></li>
                <li class="<?php echo $step === 'receipt' ? 'active' : ''; ?>"><b>3</b><span>Receipt</span></li>
              </ol>

              <?php if ($form_error !== '') { ?>
                <div class="alert alert-danger" role="alert"><i class="fas fa-exclamation-circle mr-1"></i> <?php echo rb_e($form_error); ?></div>
              <?php } ?>

              <?php if ($step === 'receipt' && $receipt) { ?>
                <!-- ---------------- RECEIPT ---------------- -->
                <div class="card rb-receipt">
                  <div class="card-body text-center">
                    <div class="rb-receipt__tick"><i class="fas fa-check"></i></div>
                    <h3><?php echo $receipt['channel'] === 'upi_qr' ? 'QR payment recorded' : 'Transfer successful'; ?></h3>
                    <?php if (!empty($receipt['request_status_warning'])) { ?><div class="alert alert-warning">The transfer is complete, but the payment-request status needs administrator reconciliation. Do not retry the transfer.</div><?php } ?>
                    <p class="rb-receipt__amt"><?php echo rb_e(rb_money($receipt['amount'])); ?></p>
                    <dl class="rb-receipt__list">
                      <div><dt>Paid to</dt><dd><?php echo rb_e($receipt['holder']); ?></dd></div>
                      <div><dt>Account</dt><dd><?php echo rb_e($receipt['to']); ?></dd></div>
                      <div><dt>From account</dt><dd><?php echo rb_e($receipt['from']); ?></dd></div>
                      <div><dt>Date &amp; time</dt><dd><?php echo rb_e($receipt['time']); ?></dd></div>
                      <div><dt>Reference</dt><dd><code><?php echo rb_e($receipt['code']); ?></code></dd></div>
                      <?php if ($receipt['channel'] === 'upi_qr') { ?><div><dt>Channel</dt><dd>QR / virtual ID</dd></div><?php } ?>
                    </dl>
                    <a href="pages_send_money.php" class="btn btn-success"><i class="fas fa-paper-plane mr-1"></i> Send another</a>
                    <a href="pages_transactions_engine.php" class="btn btn-outline-success ml-1">View history</a>
                    <button type="button" class="btn btn-outline-success ml-1" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                  </div>
                </div>

              <?php } elseif ($step === 'confirm' && $pending) { ?>
                <!-- ---------------- CONFIRM ---------------- -->
                <div class="card">
                  <div class="card-header"><h3 class="card-title">Review and confirm</h3></div>
                  <form method="post" autocomplete="off">
                    <div class="card-body">
                      <dl class="rb-receipt__list">
                        <div><dt>You are sending</dt><dd class="rb-big"><?php echo rb_e(rb_money($pending['amount'])); ?></dd></div>
                        <div><dt>To</dt><dd><?php echo rb_e($pending['holder']); ?></dd></div>
                        <div><dt>Account</dt><dd><?php echo rb_e($pending['to']); ?></dd></div>
                        <div><dt>From account</dt><dd><?php echo rb_e(isset($accounts[$pending['from']]) ? $accounts[$pending['from']]['account_number'] : ''); ?></dd></div>
                      </dl>
                      <div class="form-group mt-3">
                        <label for="pw">Enter your password to authorise</label>
                        <input type="password" id="pw" name="password" class="form-control" required autofocus autocomplete="current-password">
                      </div>
                      <input type="hidden" name="nonce" value="<?php echo rb_e($pending['nonce']); ?>">
                      <p class="text-muted small mb-0"><i class="fas fa-lock mr-1"></i> Check the name above carefully. Transfers cannot be undone by you once confirmed.</p>
                    </div>
                    <div class="card-footer">
                      <button type="submit" name="confirm" class="btn btn-success">Confirm &amp; send</button>
                      <a href="pages_send_money.php" class="btn btn-outline-success ml-1">Cancel</a>
                    </div>
                  </form>
                </div>

              <?php } else { ?>
                <!-- ---------------- DETAILS ---------------- -->
                <div class="card">
                  <div class="card-header"><h3 class="card-title">Transfer details</h3></div>
                  <?php if (!$accounts) { ?>
                    <div class="card-body">
                      <p class="mb-3">You need an active bank account before you can send money.</p>
                      <a class="btn btn-success" href="pages_open_acc.php">Open an account</a>
                    </div>
                  <?php } else { ?>
                  <form method="post" autocomplete="off">
                    <div class="card-body">
                      <div class="form-group">
                        <label for="from_account">From account</label>
                        <select name="from_account" id="from_account" class="form-control" required>
                          <?php foreach ($accounts as $aid => $a) { ?>
                            <option value="<?php echo (int) $aid; ?>" <?php echo ($form['from_account'] == $aid) ? 'selected' : ''; ?>>
                              <?php echo rb_e($a['acc_type'] . ' · ' . $a['account_number'] . ' · ' . rb_money($a['balance'])); ?>
                            </option>
                          <?php } ?>
                        </select>
                      </div>
                      <div class="form-group">
                        <label for="to">Receiving account number</label>
                        <input type="text" id="to" name="receiving_acc_no" inputmode="numeric" class="form-control" required maxlength="30"
                               value="<?php echo rb_e($form['receiving_acc_no']); ?>" placeholder="Enter the account number">
                        <?php if ($beneficiaries) { ?>
                          <label class="mt-2" for="saved-beneficiary">Choose a saved beneficiary</label>
                          <select class="form-control" id="saved-beneficiary">
                            <option value="">Enter an account manually</option>
                            <?php foreach ($beneficiaries as $beneficiary) { ?>
                              <option value="<?php echo rb_e($beneficiary['receiving_account_number']); ?>"><?php echo rb_e($beneficiary['name'] . ' · ••••' . substr($beneficiary['receiving_account_number'], -4)); ?></option>
                            <?php } ?>
                          </select>
                        <?php } ?>
                        <small id="holder" class="form-text rb-holder"></small>
                        <?php if ($qr_prefilled || (isset($_SESSION['rb_qr_context']) && isset($_SESSION['rb_qr_context']['account_number']) && $_SESSION['rb_qr_context']['account_number'] === $form['receiving_acc_no'])) { ?>
                          <small class="form-text text-success">Recipient matched to a QR profile.</small>
                        <?php } ?>
                      </div>
                      <div class="form-group">
                        <label for="amount">Amount (<?php echo RB_CURRENCY; ?>)</label>
                        <input type="text" id="amount" name="amount" inputmode="decimal" class="form-control" required
                               <?php echo isset($_SESSION['rb_qr_context']['amount']) && $_SESSION['rb_qr_context']['amount'] !== null ? 'readonly' : ''; ?>
                               value="<?php echo rb_e($form['amount']); ?>" placeholder="0.00">
                        <div class="rb-chips mt-2">
                          <?php foreach (array(500, 1000, 2000, 5000) as $q) { ?>
                            <button type="button" class="rb-chip" data-amt="<?php echo $q; ?>"><?php echo RB_CURRENCY . ' ' . number_format($q); ?></button>
                          <?php } ?>
                        </div>
                      </div>
                      <p class="text-muted small mb-0">Daily online limit: <?php echo rb_e(rb_money($daily_limit)); ?></p>
                    </div>
                    <div class="card-footer">
                      <button type="submit" name="review" class="btn btn-success">Continue <i class="fas fa-arrow-right ml-1"></i></button>
                    </div>
                  </form>
                  <?php } ?>
                </div>
              <?php } ?>

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
  <script>
    $(function () {
      var t;
      function lookup() {
        var v = $.trim($('#to').val());
        if (v.length < 4) { $('#holder').text(''); return; }
        $.post('pages_ajax.php', { iBankAccHolder: v }, function (d) {
          d = $.trim(d);
          $('#holder').html(d ? '<i class="fas fa-user-check"></i> ' + d : '<i class="fas fa-times-circle"></i> Account not found')
                      .toggleClass('ok', !!d).toggleClass('bad', !d);
        });
      }
      $('#to').on('input', function () { clearTimeout(t); t = setTimeout(lookup, 350); });
      if ($('#to').val()) { lookup(); }
      $('#saved-beneficiary').on('change', function () {
        if ($(this).val()) { $('#to').val($(this).val()).trigger('input'); }
      });
      $('.rb-chip').on('click', function () { $('#amount').val($(this).data('amt')).focus(); });
    });
  </script>
</body>

</html>
