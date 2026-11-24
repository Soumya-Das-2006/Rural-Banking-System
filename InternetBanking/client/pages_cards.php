<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
require_once __DIR__ . '/../core/cards.php';
check_login();

$client_id = (int) $_SESSION['client_id'];
$err = '';
$success = '';
$accounts = array();
$cards = array();
$history = array();
$tables_ready = true;

if (isset($_SESSION['rb_demo_card_flash'])) {
    $success = (string) $_SESSION['rb_demo_card_flash'];
    unset($_SESSION['rb_demo_card_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['card_action'])) {
    $result = rb_demo_card_client_action($mysqli, $client_id, $_POST);
    if ($result['ok']) {
        $_SESSION['rb_demo_card_flash'] = $result['msg'];
        header('Location: pages_cards.php', true, 303);
        exit;
    }
    $err = $result['msg'];
}

try {
    $stmt = $mysqli->prepare("SELECT account_id, acc_name, acc_type, account_number FROM ib_bankaccounts WHERE client_id = ? AND acc_status = 'Active' ORDER BY account_id DESC");
    if (!$stmt) {
        throw new RuntimeException('Could not prepare active account query.');
    }
    $stmt->bind_param('i', $client_id);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not read active bank accounts.');
    }
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $accounts[] = $row;
    }
    $stmt->close();

    $stmt = $mysqli->prepare(
        'SELECT c.*, a.acc_name, a.account_number, DATEDIFF(c.expiry_date, CURRENT_DATE()) AS days_to_expiry, '
        . '(c.frozen_until IS NOT NULL AND c.frozen_until > CURRENT_TIMESTAMP) AS is_frozen, '
        . '(SELECT COALESCE(SUM(t.amount), 0) FROM ib_demo_card_transactions t WHERE t.card_id = c.card_id AND t.channel = \'atm\' AND t.decision = \'approved\' AND t.created_at >= CURRENT_DATE()) AS atm_used_today, '
        . '(SELECT COUNT(*) FROM ib_demo_cards r WHERE r.replacement_for_card_id = c.card_id AND r.archived_at IS NULL AND r.card_status IN (\'requested\',\'inactive\',\'active\')) AS pending_replacement_count '
        . 'FROM ib_demo_cards c INNER JOIN ib_bankaccounts a ON a.account_id = c.account_id AND a.client_id = c.client_id WHERE c.client_id = ? ORDER BY c.created_at DESC, c.card_id DESC'
    );
    if (!$stmt) {
        throw new RuntimeException('Could not prepare card query.');
    }
    $stmt->bind_param('i', $client_id);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not read card records.');
    }
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $cards[] = $row;
    }
    $stmt->close();

    $stmt = $mysqli->prepare(
        'SELECT t.card_transaction_id, t.channel, t.amount, t.decision, t.reason, t.created_at, c.card_reference '
        . 'FROM ib_demo_card_transactions t INNER JOIN ib_demo_cards c ON c.card_id = t.card_id AND c.client_id = t.client_id '
        . 'WHERE t.client_id = ? ORDER BY t.created_at DESC, t.card_transaction_id DESC LIMIT 50'
    );
    if (!$stmt) {
        throw new RuntimeException('Could not prepare card history query.');
    }
    $stmt->bind_param('i', $client_id);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not read card history.');
    }
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }
    $stmt->close();
} catch (Throwable $e) {
    $tables_ready = false;
    $accounts = array();
    $cards = array();
    $history = array();
    $err = 'Could not load card records. Check the database connection and import database/card-management-migration.sql and database/card-archive-migration.sql.';
    error_log('[RuralBanking] demo card page read failed: ' . $e->getMessage());
}

function rb_demo_card_status_label(array $card)
{
    if (!empty($card['archived_at'])) {
        return 'Archived';
    }
    if (!empty($card['is_frozen'])) {
        return 'Temporarily frozen';
    }
    $labels = array(
        'requested' => 'Pending staff approval',
        'inactive' => 'Inactive — activate when ready',
        'active' => 'Active',
        'blocked' => 'Permanently blocked',
        'rejected' => 'Request rejected',
        'replaced' => 'Replaced',
    );
    return isset($labels[$card['card_status']]) ? $labels[$card['card_status']] : 'Unknown';
}

function rb_demo_card_status_class(array $card)
{
    if (!empty($card['archived_at'])) {
        return 'secondary';
    }
    if (!empty($card['is_frozen'])) {
        return 'warning';
    }
    if ($card['card_status'] === 'active') {
        return 'success';
    }
    if (in_array($card['card_status'], array('blocked', 'rejected', 'replaced'), true)) {
        return 'secondary';
    }
    return 'info';
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
              <h1>Cards &amp; Controls</h1>
              <p class="text-muted mb-0">Manage demo debit-card records, ATM service requests, limits and simulated authorizations. These controls do not issue a real bank or credit card and never move money. <a href="../service_information.php">Service Information</a></p>
            </div>
          </div>
        </div>
      </section>

      <section class="content">
        <div class="container-fluid">
          <?php if ($err !== '') { ?><div class="alert alert-danger" role="alert"><?php echo rb_e($err); ?></div><?php } ?>
          <?php if ($success !== '') { ?><div class="alert alert-success" role="status"><?php echo rb_e($success); ?></div><?php } ?>
          <div class="card">
            <div class="card-header"><h3 class="card-title">Card services</h3></div>
            <div class="card-body">
              <?php if (!$tables_ready) { ?>
                <p class="mb-0">Import the card-management migration before requesting cards.</p>
              <?php } elseif (!$accounts) { ?>
                <p class="mb-0">You need an active bank account before submitting a card service request.</p>
              <?php } else { ?>
                <form method="post" action="pages_cards.php" class="rb-card-request-form">
                  <div class="form-group">
                    <label for="card_account">Link to your active account</label>
                    <select class="form-control" id="card_account" name="account_id" required>
                      <?php foreach ($accounts as $account) { ?>
                        <option value="<?php echo rb_e((int) $account['account_id']); ?>">
                          <?php echo rb_e($account['acc_name']); ?> · <?php echo rb_e($account['acc_type']); ?> · account ending <?php echo rb_e(substr($account['account_number'], -4)); ?>
                        </option>
                      <?php } ?>
                    </select>
                  </div>
                  <button class="btn btn-primary" type="submit" name="card_action" value="request_virtual">
                    <i class="fas fa-mobile-alt mr-1"></i> Create virtual card record
                  </button>
                  <button class="btn btn-outline-primary" type="submit" name="card_action" value="request_physical">
                    <i class="fas fa-credit-card mr-1"></i> Submit ATM service request
                  </button>
                  <small class="form-text text-muted mt-2">Virtual card records start inactive. ATM requests require staff approval.</small>
                </form>
              <?php } ?>
            </div>
          </div>

          <div class="row">
            <?php if (!$cards && $tables_ready) { ?>
              <div class="col-12"><div class="card"><div class="card-body text-center py-4">No card records yet. Submit a service request above to get started.</div></div></div>
            <?php } ?>
            <?php foreach ($cards as $card) {
              $cardId = (int) $card['card_id'];
              $cardTypeLabel = $card['card_type'] === 'virtual' ? 'Virtual debit card' : 'ATM card service request';
              $daysToExpiry = $card['days_to_expiry'] === null ? null : (int) $card['days_to_expiry'];
              $isIssued = !empty($card['card_reference']);
              $isArchived = !empty($card['archived_at']);
              $canManage = !$isArchived && in_array($card['card_status'], array('inactive', 'active'), true);
            ?>
              <div class="col-xl-6">
                <div class="card rb-demo-card-panel h-100">
                  <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                      <div>
                        <h2 class="h5 mb-1"><?php echo rb_e($cardTypeLabel); ?></h2>
                        <p class="text-muted mb-2">Account ending <?php echo rb_e(substr($card['account_number'], -4)); ?> · <?php echo rb_e($card['acc_name']); ?></p>
                      </div>
                      <span class="badge badge-<?php echo rb_e(rb_demo_card_status_class($card)); ?>"><?php echo rb_e(rb_demo_card_status_label($card)); ?></span>
                    </div>

                    <?php if ($isIssued) { ?>
                      <div class="rb-demo-card-face">
                        <span class="rb-demo-card-brand">BANK OF SOUMYA</span>
                        <strong><?php echo rb_e($card['card_reference']); ?></strong>
                        <span>Card reference</span>
                        <small>Expires <?php echo rb_e(date('m/Y', strtotime($card['expiry_date']))); ?></small>
                      </div>
                      <?php if ($daysToExpiry !== null && $daysToExpiry < 0) { ?>
                        <div class="alert alert-danger mt-3 mb-2">This card record has expired. Submit a replacement request.</div>
                      <?php } elseif ($daysToExpiry !== null && $daysToExpiry <= 30) { ?>
                        <div class="alert alert-warning mt-3 mb-2">Expiry reminder: this card record expires in <?php echo rb_e($daysToExpiry); ?> day(s).</div>
                      <?php } ?>
                    <?php } elseif ($isArchived) { ?>
                      <div class="alert alert-secondary mt-3 mb-2">This card record has been archived. Its history is retained; contact staff if it was archived by mistake.</div>
                    <?php } else { ?>
                      <div class="rb-demo-card-pending mt-3">
                        <i class="fas fa-hourglass-half mr-1"></i> A staff member must approve this ATM service request before it can be activated.
                      </div>
                    <?php } ?>

                    <?php if ($isIssued) { ?>
                      <div class="rb-card-limits-summary">
                        <div><span>ATM daily limit</span><strong><?php echo rb_e(rb_money($card['atm_daily_limit'])); ?></strong></div>
                        <div><span>Today's ATM authorizations</span><strong><?php echo rb_e(rb_money($card['atm_used_today'])); ?></strong></div>
                        <div><span>POS per transaction</span><strong><?php echo rb_e(rb_money($card['pos_transaction_limit'])); ?></strong></div>
                        <div><span>Online per transaction</span><strong><?php echo rb_e(rb_money($card['online_transaction_limit'])); ?></strong></div>
                      </div>
                    <?php } ?>

                    <div class="rb-card-actions mt-3">
                      <?php if (!$isArchived && $card['card_status'] === 'inactive' && $daysToExpiry !== null && $daysToExpiry >= 0) { ?>
                        <form method="post" action="pages_cards.php">
                          <input type="hidden" name="card_id" value="<?php echo rb_e($cardId); ?>">
                          <button class="btn btn-success btn-sm" type="submit" name="card_action" value="activate">Activate card record</button>
                        </form>
                      <?php } ?>
                      <?php if (!$isArchived && $card['card_status'] === 'active' && !empty($card['is_frozen'])) { ?>
                        <form method="post" action="pages_cards.php">
                          <input type="hidden" name="card_id" value="<?php echo rb_e($cardId); ?>">
                          <button class="btn btn-outline-success btn-sm" type="submit" name="card_action" value="unfreeze">Unfreeze card</button>
                        </form>
                      <?php } elseif (!$isArchived && $card['card_status'] === 'active' && $daysToExpiry !== null && $daysToExpiry >= 0) { ?>
                        <form method="post" action="pages_cards.php">
                          <input type="hidden" name="card_id" value="<?php echo rb_e($cardId); ?>">
                          <select class="form-control form-control-sm mb-1" name="freeze_hours" aria-label="Temporary freeze duration">
                            <option value="1">Freeze for 1 hour</option>
                            <option value="24">Freeze for 24 hours</option>
                            <option value="168">Freeze for 7 days</option>
                          </select>
                          <button class="btn btn-warning btn-sm" type="submit" name="card_action" value="freeze">Temporarily freeze</button>
                        </form>
                      <?php } ?>
                      <?php if (!$isArchived && in_array($card['card_status'], array('active', 'inactive'), true)) { ?>
                        <form method="post" action="pages_cards.php" onsubmit="return confirm('Permanently block this card record? It cannot be reactivated.');">
                          <input type="hidden" name="card_id" value="<?php echo rb_e($cardId); ?>">
                          <button class="btn btn-outline-danger btn-sm" type="submit" name="card_action" value="block">Block permanently</button>
                        </form>
                      <?php } ?>
                      <?php if (!$isArchived && in_array($card['card_status'], array('active', 'inactive', 'blocked'), true) && (int) $card['pending_replacement_count'] === 0) { ?>
                        <form method="post" action="pages_cards.php" onsubmit="return confirm('Request a replacement? The current card will be blocked immediately for safety.');">
                          <input type="hidden" name="card_id" value="<?php echo rb_e($cardId); ?>">
                          <button class="btn btn-outline-secondary btn-sm" type="submit" name="card_action" value="replace">Request replacement</button>
                        </form>
                      <?php } ?>
                    </div>

                    <?php if ($canManage) { ?>
                      <details class="rb-card-limits-editor mt-3">
                        <summary>Change spending limits</summary>
                        <form method="post" action="pages_cards.php" class="row mt-2">
                          <input type="hidden" name="card_id" value="<?php echo rb_e($cardId); ?>">
                          <div class="form-group col-md-4">
                            <label for="atm_limit_<?php echo rb_e($cardId); ?>">ATM daily (₹)</label>
                            <input class="form-control" id="atm_limit_<?php echo rb_e($cardId); ?>" name="atm_daily_limit" type="number" min="0" max="100000" step="0.01" value="<?php echo rb_e($card['atm_daily_limit']); ?>" required>
                          </div>
                          <div class="form-group col-md-4">
                            <label for="pos_limit_<?php echo rb_e($cardId); ?>">POS per transaction (₹)</label>
                            <input class="form-control" id="pos_limit_<?php echo rb_e($cardId); ?>" name="pos_transaction_limit" type="number" min="0" max="100000" step="0.01" value="<?php echo rb_e($card['pos_transaction_limit']); ?>" required>
                          </div>
                          <div class="form-group col-md-4">
                            <label for="online_limit_<?php echo rb_e($cardId); ?>">Online per transaction (₹)</label>
                            <input class="form-control" id="online_limit_<?php echo rb_e($cardId); ?>" name="online_transaction_limit" type="number" min="0" max="100000" step="0.01" value="<?php echo rb_e($card['online_transaction_limit']); ?>" required>
                          </div>
                          <div class="col-12"><button class="btn btn-primary btn-sm" type="submit" name="card_action" value="limits">Save limits</button></div>
                        </form>
                        <small class="text-muted">Zero disables a channel. Each limit is capped at ₹100,000.</small>
                      </details>
                    <?php } ?>

                    <?php if ($isIssued && !$isArchived) { ?>
                      <details class="rb-card-simulator mt-3">
                        <summary>Review an authorization</summary>
                        <form method="post" action="pages_cards.php" class="form-row">
                          <input type="hidden" name="card_id" value="<?php echo rb_e($cardId); ?>">
                          <input type="hidden" name="request_key" value="<?php echo rb_e(bin2hex(random_bytes(16))); ?>">
                          <div class="form-group col-md-4">
                            <label for="channel_<?php echo rb_e($cardId); ?>">Channel</label>
                            <select class="form-control" id="channel_<?php echo rb_e($cardId); ?>" name="channel" required>
                              <option value="atm">ATM withdrawal</option>
                              <option value="pos">POS purchase</option>
                              <option value="online">Online shopping</option>
                            </select>
                          </div>
                          <div class="form-group col-md-5">
                            <label for="amount_<?php echo rb_e($cardId); ?>">Authorization amount (₹)</label>
                            <input class="form-control" id="amount_<?php echo rb_e($cardId); ?>" name="amount" type="number" min="0.01" max="10000000" step="0.01" required>
                          </div>
                          <div class="form-group col-md-3 d-flex align-items-end">
                            <button class="btn btn-outline-primary btn-block" type="submit" name="card_action" value="simulate">Review authorization</button>
                          </div>
                        </form>
                      </details>
                    <?php } ?>
                  </div>
                </div>
              </div>
            <?php } ?>
          </div>

          <div class="card">
            <div class="card-header"><h3 class="card-title">Card authorization history</h3></div>
            <div class="card-body p-0">
              <?php if (!$history) { ?>
                <p class="p-3 mb-0 text-muted">No card authorizations have been recorded yet.</p>
              <?php } else { ?>
                <div class="table-responsive">
                  <table class="table table-hover mb-0">
                    <thead><tr><th>Date</th><th>Card reference</th><th>Channel</th><th>Amount</th><th>Decision</th><th>Reason</th></tr></thead>
                    <tbody>
                    <?php foreach ($history as $row) { ?>
                      <tr>
                        <td><?php echo rb_e(date('d M Y H:i', strtotime($row['created_at']))); ?></td>
                        <td><?php echo rb_e($row['card_reference'] ?: 'Pending card'); ?></td>
                        <td><?php echo rb_e(strtoupper($row['channel'])); ?></td>
                        <td><?php echo rb_e(rb_money($row['amount'])); ?></td>
                        <td><span class="badge badge-<?php echo $row['decision'] === 'approved' ? 'success' : 'danger'; ?>"><?php echo rb_e(strtoupper($row['decision'])); ?></span></td>
                        <td><?php echo rb_e($row['reason']); ?></td>
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
    <?php include("dist/_partials/footer.php"); ?>
  </div>

  <script src="plugins/jquery/jquery.min.js"></script>
  <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="dist/js/adminlte.min.js"></script>
</body>
</html>
