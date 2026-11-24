<?php
if (!defined('RB_CARD_REQUESTS_PORTAL') || !in_array(RB_CARD_REQUESTS_PORTAL, array('admin', 'staff'), true)) {
    http_response_code(404);
    exit;
}

$portal = RB_CARD_REQUESTS_PORTAL;
$err = '';
$success = '';
$requests = array();
$cards = array();
$cardTransactions = array();
$recordCount = 0;
$transactionCount = 0;
$pageSize = 100;
$statusOptions = array('all', 'requested', 'inactive', 'active', 'blocked', 'rejected', 'replaced', 'archived');
$search = isset($_GET['q']) && is_scalar($_GET['q']) ? trim((string) $_GET['q']) : '';
$recordStatus = isset($_GET['status']) && is_scalar($_GET['status']) ? (string) $_GET['status'] : 'all';
$recordPage = isset($_GET['page']) && is_scalar($_GET['page']) ? filter_var($_GET['page'], FILTER_VALIDATE_INT) : 1;
$transactionPage = isset($_GET['tx_page']) && is_scalar($_GET['tx_page']) ? filter_var($_GET['tx_page'], FILTER_VALIDATE_INT) : 1;
$recordPage = $recordPage && $recordPage > 0 ? (int) $recordPage : 1;
$transactionPage = $transactionPage && $transactionPage > 0 ? (int) $transactionPage : 1;
$recordPage = min($recordPage, 100000);
$transactionPage = min($transactionPage, 100000);
$recordOffset = ($recordPage - 1) * $pageSize;
$transactionOffset = ($transactionPage - 1) * $pageSize;
$tables_ready = true;
$flashKey = 'rb_demo_card_review_flash';
if (isset($_SESSION[$flashKey])) {
    $success = (string) $_SESSION[$flashKey];
    unset($_SESSION[$flashKey]);
}

if (strlen($search) > 100) {
    $err = 'Search text must be 100 characters or fewer.';
    $search = '';
}
if (!in_array($recordStatus, $statusOptions, true)) {
    $recordStatus = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['card_action'])) {
    $cardId = isset($_POST['card_id']) && is_scalar($_POST['card_id'])
        ? filter_var($_POST['card_id'], FILTER_VALIDATE_INT)
        : false;
    if ($cardId === false || $cardId < 1) {
        $err = 'Choose a valid card record.';
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
        . "WHERE c.card_status = 'requested' AND c.archived_at IS NULL ORDER BY c.created_at ASC, c.card_id ASC"
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

    $pattern = '%' . $search . '%';
    $filterSql = "WHERE (? = '' OR client.name LIKE ? OR client.email LIKE ? OR client.client_number LIKE ? OR c.card_reference LIKE ?)"
        . " AND (? = 'all' OR (? = 'archived' AND c.archived_at IS NOT NULL) OR (? = c.card_status AND c.archived_at IS NULL))";
    $joins = ' FROM ib_demo_cards c'
        . ' LEFT JOIN ib_clients client ON client.client_id = c.client_id'
        . ' LEFT JOIN ib_bankaccounts account ON account.account_id = c.account_id AND account.client_id = c.client_id';
    $stmt = $mysqli->prepare('SELECT COUNT(*)' . $joins . ' ' . $filterSql);
    if (!$stmt) {
        throw new RuntimeException('Could not prepare card records count.');
    }
    $stmt->bind_param('ssssssss', $search, $pattern, $pattern, $pattern, $pattern, $recordStatus, $recordStatus, $recordStatus);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not count card records.');
    }
    $stmt->bind_result($recordCount);
    $stmt->fetch();
    $stmt->close();

    $stmt = $mysqli->prepare(
        'SELECT c.card_id, c.client_id, c.card_type, c.card_status, c.card_reference, c.expiry_date, c.frozen_until, '
        . 'c.atm_daily_limit, c.pos_transaction_limit, c.online_transaction_limit, c.reviewed_by_role, c.reviewed_by_id, '
        . 'c.reviewed_at, c.archived_at, c.archived_by_role, c.archived_by_id, c.created_at, c.updated_at, '
        . 'client.name AS client_name, client.email AS client_email, client.client_number, '
        . 'account.acc_name, account.acc_type, account.account_number, '
        . '(SELECT COALESCE(SUM(t.amount), 0) FROM ib_demo_card_transactions t WHERE t.card_id = c.card_id AND t.channel = \'atm\' AND t.decision = \'approved\' AND t.created_at >= CURRENT_DATE()) AS atm_used_today'
        . $joins . ' ' . $filterSql
        . ' ORDER BY c.created_at DESC, c.card_id DESC LIMIT ? OFFSET ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Could not prepare card records query.');
    }
    $stmt->bind_param('ssssssssii', $search, $pattern, $pattern, $pattern, $pattern, $recordStatus, $recordStatus, $recordStatus, $pageSize, $recordOffset);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not read card records.');
    }
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $cards[] = $row;
    }
    $stmt->close();

    $transactionFilter = "WHERE (? = '' OR client.name LIKE ? OR client.email LIKE ? OR client.client_number LIKE ? OR c.card_reference LIKE ?)";
    $transactionJoins = ' FROM ib_demo_card_transactions t'
        . ' INNER JOIN ib_demo_cards c ON c.card_id = t.card_id AND c.client_id = t.client_id'
        . ' LEFT JOIN ib_clients client ON client.client_id = t.client_id'
        . ' LEFT JOIN ib_bankaccounts account ON account.account_id = t.account_id AND account.client_id = t.client_id';
    $stmt = $mysqli->prepare('SELECT COUNT(*)' . $transactionJoins . ' ' . $transactionFilter);
    if (!$stmt) {
        throw new RuntimeException('Could not prepare card authorization count.');
    }
    $stmt->bind_param('sssss', $search, $pattern, $pattern, $pattern, $pattern);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not count card authorizations.');
    }
    $stmt->bind_result($transactionCount);
    $stmt->fetch();
    $stmt->close();

    $stmt = $mysqli->prepare(
        'SELECT t.card_transaction_id, t.channel, t.amount, t.decision, t.reason, t.created_at, '
        . 'c.card_id, c.card_type, c.card_reference, c.archived_at, client.name AS client_name, client.client_number, '
        . 'account.acc_name, account.acc_type, account.account_number'
        . $transactionJoins . ' ' . $transactionFilter
        . ' ORDER BY t.created_at DESC, t.card_transaction_id DESC LIMIT ? OFFSET ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Could not prepare card authorization history.');
    }
    $stmt->bind_param('sssssii', $search, $pattern, $pattern, $pattern, $pattern, $pageSize, $transactionOffset);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not read card authorization history.');
    }
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $cardTransactions[] = $row;
    }
    $stmt->close();
} catch (Throwable $e) {
    $tables_ready = false;
    $requests = array();
    $cards = array();
    $cardTransactions = array();
    $err = 'Could not load card records. Import database/card-management-migration.sql and database/card-archive-migration.sql, and verify the database connection.';
    error_log('[RuralBanking] pending card requests read failed: ' . $e->getMessage());
}

function rb_card_records_page_url($page, $transactionPage)
{
    $query = array(
        'q' => isset($_GET['q']) && is_scalar($_GET['q']) ? (string) $_GET['q'] : '',
        'status' => isset($_GET['status']) && is_scalar($_GET['status']) ? (string) $_GET['status'] : 'all',
        'page' => (int) $page,
        'tx_page' => (int) $transactionPage,
    );
    return 'pages_card_requests.php?' . http_build_query($query);
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
              <p class="text-muted mb-0">Review ATM service requests and manage all demo debit-card records. Credit profiles are customer-reported estimates, not issued credit cards. <a href="pages_credit_profiles.php">Review credit profiles</a> · <a href="../service_information.php">Service Information</a></p>
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

          <div class="card">
            <div class="card-header">
              <h3 class="card-title">All card records</h3>
              <div class="card-tools">
                <form method="get" action="pages_card_requests.php" class="form-inline">
                  <input class="form-control form-control-sm mr-2" type="search" name="q" maxlength="100" value="<?php echo rb_e($search); ?>" placeholder="Customer, number, or reference" aria-label="Search card records">
                  <select class="form-control form-control-sm mr-2" name="status" aria-label="Filter card status">
                    <?php foreach ($statusOptions as $option) { ?>
                      <option value="<?php echo rb_e($option); ?>" <?php echo $recordStatus === $option ? 'selected' : ''; ?>><?php echo rb_e(ucfirst($option)); ?></option>
                    <?php } ?>
                  </select>
                  <button class="btn btn-sm btn-primary" type="submit">Filter</button>
                </form>
              </div>
            </div>
            <div class="card-body p-0">
              <?php if ($tables_ready && !$cards) { ?><p class="p-3 mb-0 text-muted">No card records match this filter.</p><?php } ?>
              <?php if ($cards) { ?>
                <div class="table-responsive">
                  <table class="table table-hover table-sm mb-0">
                    <thead><tr><th>Customer / account</th><th>Card record</th><th>Status</th><th>Expires / freeze</th><th>Limits (ATM / POS / online)</th><th>Review / archive</th><th>Created / updated</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($cards as $card) {
                      $archived = !empty($card['archived_at']);
                      $statusLabel = $archived ? 'Archived · ' . ucfirst((string) $card['card_status']) : ucfirst((string) $card['card_status']);
                      $cardTypeLabel = $card['card_type'] === 'physical' ? 'ATM / physical debit (demo)' : 'Virtual debit (demo)';
                    ?>
                      <tr>
                        <td>
                          <?php echo rb_e($card['client_name'] ?: 'Customer record unavailable'); ?>
                          <br><small class="text-muted"><?php echo rb_e($card['client_number'] ?: ''); ?> · <?php echo rb_e($card['client_email'] ?: ''); ?></small>
                          <br><?php echo rb_e($card['acc_name'] ?: 'Account unavailable'); ?> (<?php echo rb_e($card['acc_type'] ?: ''); ?>)
                          <br><small class="text-muted">Account ending <?php echo rb_e(substr((string) $card['account_number'], -4)); ?></small>
                        </td>
                        <td>
                          <?php echo rb_e($cardTypeLabel); ?><br>
                          <strong><?php echo rb_e($card['card_reference'] ?: 'Not issued'); ?></strong>
                          <br><small class="text-muted">Record #<?php echo (int) $card['card_id']; ?> · ATM used today <?php echo rb_e(rb_money($card['atm_used_today'])); ?></small>
                        </td>
                        <td><span class="badge badge-<?php echo $archived ? 'secondary' : ($card['card_status'] === 'active' ? 'success' : ($card['card_status'] === 'requested' ? 'warning' : 'info')); ?>"><?php echo rb_e($statusLabel); ?></span></td>
                        <td>
                          <?php echo $card['expiry_date'] ? rb_e(date('m/Y', strtotime($card['expiry_date']))) : 'Not issued'; ?>
                          <?php if (!empty($card['frozen_until'])) { ?><br><small class="text-warning">Frozen until <?php echo rb_e(date('d M Y H:i', strtotime($card['frozen_until']))); ?></small><?php } ?>
                        </td>
                        <td><?php echo rb_e(rb_money($card['atm_daily_limit'])); ?><br><?php echo rb_e(rb_money($card['pos_transaction_limit'])); ?><br><?php echo rb_e(rb_money($card['online_transaction_limit'])); ?></td>
                        <td>
                          <?php if ($card['reviewed_at']) { ?>
                            <?php echo rb_e(ucfirst((string) $card['reviewed_by_role']) . ' #' . (int) $card['reviewed_by_id']); ?><br><small><?php echo rb_e(date('d M Y H:i', strtotime($card['reviewed_at']))); ?></small>
                          <?php } else { ?><span class="text-muted">Not reviewed</span><?php } ?>
                          <?php if ($archived) { ?><br><small class="text-muted">Archived by <?php echo rb_e(ucfirst((string) $card['archived_by_role']) . ' #' . (int) $card['archived_by_id']); ?> · <?php echo rb_e(date('d M Y H:i', strtotime($card['archived_at']))); ?></small><?php } ?>
                        </td>
                        <td><?php echo rb_e(date('d M Y H:i', strtotime($card['created_at']))); ?><br><small class="text-muted">Updated <?php echo rb_e(date('d M Y H:i', strtotime($card['updated_at']))); ?></small></td>
                        <td>
                          <?php if (!$archived && $card['card_status'] === 'inactive' && !empty($card['card_reference']) && !empty($card['expiry_date']) && $card['expiry_date'] >= date('Y-m-d')) { ?>
                            <form method="post" action="pages_card_requests.php" class="mb-1" onsubmit="return confirm('Activate this issued demo card?');">
                              <input type="hidden" name="card_id" value="<?php echo (int) $card['card_id']; ?>">
                              <button class="btn btn-sm btn-outline-success" type="submit" name="card_action" value="activate">Activate</button>
                            </form>
                          <?php } elseif (!$archived && $card['card_status'] === 'active') { ?>
                            <form method="post" action="pages_card_requests.php" class="mb-1" onsubmit="return confirm('Deactivate this card record? It can be activated again later.');">
                              <input type="hidden" name="card_id" value="<?php echo (int) $card['card_id']; ?>">
                              <button class="btn btn-sm btn-outline-warning" type="submit" name="card_action" value="deactivate">Deactivate</button>
                            </form>
                          <?php } ?>
                          <form method="post" action="pages_card_requests.php" onsubmit="return confirm('<?php echo $archived ? 'Restore this record? Previously active cards remain inactive.' : 'Archive this record? Card and authorization history are retained; active cards become inactive.'; ?>');">
                            <input type="hidden" name="card_id" value="<?php echo (int) $card['card_id']; ?>">
                            <button class="btn btn-sm <?php echo $archived ? 'btn-outline-primary' : 'btn-outline-danger'; ?>" type="submit" name="card_action" value="<?php echo $archived ? 'restore' : 'archive'; ?>"><?php echo $archived ? 'Restore' : 'Archive'; ?></button>
                          </form>
                        </td>
                      </tr>
                    <?php } ?>
                    </tbody>
                  </table>
                </div>
                <?php
                  $recordPages = max(1, (int) ceil((int) $recordCount / $pageSize));
                  $recordFirst = $recordCount > 0 ? $recordOffset + 1 : 0;
                  $recordLast = min($recordOffset + $pageSize, (int) $recordCount);
                ?>
                <div class="d-flex justify-content-between align-items-center p-3">
                  <small class="text-muted">Showing <?php echo (int) $recordFirst; ?>–<?php echo (int) $recordLast; ?> of <?php echo (int) $recordCount; ?> card records</small>
                  <div>
                    <?php if ($recordPage > 1) { ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo rb_e(rb_card_records_page_url($recordPage - 1, $transactionPage)); ?>">Previous</a><?php } ?>
                    <span class="small mx-2">Page <?php echo (int) $recordPage; ?> of <?php echo (int) $recordPages; ?></span>
                    <?php if ($recordPage < $recordPages) { ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo rb_e(rb_card_records_page_url($recordPage + 1, $transactionPage)); ?>">Next</a><?php } ?>
                  </div>
                </div>
              <?php } ?>
            </div>
          </div>

          <div class="card">
            <div class="card-header"><h3 class="card-title">Card authorization records</h3></div>
            <div class="card-body p-0">
              <?php if ($tables_ready && !$cardTransactions) { ?><p class="p-3 mb-0 text-muted">No authorization records match this search.</p><?php } ?>
              <?php if ($cardTransactions) { ?>
                <div class="table-responsive">
                  <table class="table table-hover mb-0">
                    <thead><tr><th>Date</th><th>Customer / account</th><th>Card</th><th>Channel</th><th>Amount</th><th>Decision / reason</th></tr></thead>
                    <tbody>
                    <?php foreach ($cardTransactions as $transaction) { ?>
                      <tr>
                        <td><?php echo rb_e(date('d M Y H:i', strtotime($transaction['created_at']))); ?><br><small class="text-muted">Authorization #<?php echo (int) $transaction['card_transaction_id']; ?></small></td>
                        <td><?php echo rb_e($transaction['client_name'] ?: 'Customer record unavailable'); ?><br><small class="text-muted"><?php echo rb_e($transaction['client_number'] ?: ''); ?> · <?php echo rb_e($transaction['acc_name'] ?: 'Account unavailable'); ?> ending <?php echo rb_e(substr((string) $transaction['account_number'], -4)); ?></small></td>
                        <td><?php echo rb_e($transaction['card_reference'] ?: 'Pending card'); ?><br><small class="text-muted"><?php echo rb_e($transaction['card_type'] === 'physical' ? 'ATM / physical demo' : 'Virtual debit demo'); ?><?php echo !empty($transaction['archived_at']) ? ' · archived' : ''; ?></small></td>
                        <td><?php echo rb_e(strtoupper((string) $transaction['channel'])); ?></td>
                        <td><?php echo rb_e(rb_money($transaction['amount'])); ?></td>
                        <td><span class="badge badge-<?php echo $transaction['decision'] === 'approved' ? 'success' : 'danger'; ?>"><?php echo rb_e(strtoupper((string) $transaction['decision'])); ?></span><br><small><?php echo rb_e($transaction['reason']); ?></small></td>
                      </tr>
                    <?php } ?>
                    </tbody>
                  </table>
                </div>
                <?php
                  $transactionPages = max(1, (int) ceil((int) $transactionCount / $pageSize));
                  $transactionFirst = $transactionCount > 0 ? $transactionOffset + 1 : 0;
                  $transactionLast = min($transactionOffset + $pageSize, (int) $transactionCount);
                ?>
                <div class="d-flex justify-content-between align-items-center p-3">
                  <small class="text-muted">Showing <?php echo (int) $transactionFirst; ?>–<?php echo (int) $transactionLast; ?> of <?php echo (int) $transactionCount; ?> authorization records</small>
                  <div>
                    <?php if ($transactionPage > 1) { ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo rb_e(rb_card_records_page_url($recordPage, $transactionPage - 1)); ?>">Previous</a><?php } ?>
                    <span class="small mx-2">Page <?php echo (int) $transactionPage; ?> of <?php echo (int) $transactionPages; ?></span>
                    <?php if ($transactionPage < $transactionPages) { ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo rb_e(rb_card_records_page_url($recordPage, $transactionPage + 1)); ?>">Next</a><?php } ?>
                  </div>
                </div>
              <?php } ?>
              <p class="small text-muted p-3 mb-0">These authorization records are simulations only; they do not represent settled payments or debit/credit card network transactions.</p>
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
