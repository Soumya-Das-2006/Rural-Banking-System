<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();

$admin_id = (int) $_SESSION['admin_id'];
$error = '';
$success = isset($_SESSION['rb_admin_insights_success']) ? (string) $_SESSION['rb_admin_insights_success'] : '';
unset($_SESSION['rb_admin_insights_success']);
$ready = true;
$search = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$search = substr($search, 0, 100);
$month = date('Y-m');
$budgets = array();
$goals = array();
$rewards = array();
$devices = array();
$metrics = array(
    'current_budgets' => 0,
    'active_goals' => 0,
    'active_devices' => 0,
    'reward_points' => 0,
);
$reward_reason_labels = array(
    'demo_badge_redemption' => 'Reward badge redemption',
    'external_transfer' => 'Account transfer reward',
);

try {
    $mysqli->query('SELECT budget_id FROM ib_budgets LIMIT 0');
    $mysqli->query('SELECT entry_id FROM ib_expense_entries LIMIT 0');
    $mysqli->query('SELECT goal_id FROM ib_savings_goals LIMIT 0');
    $mysqli->query('SELECT reward_id FROM ib_rewards LIMIT 0');
    $mysqli->query('SELECT device_id FROM ib_login_devices LIMIT 0');
    $mysqli->query('SELECT audit_id FROM ib_audit_log LIMIT 0');
} catch (Throwable $e) {
    $ready = false;
}

if ($ready && isset($_POST['revoke_device'])) {
    $device_id = filter_var($_POST['revoke_device'], FILTER_VALIDATE_INT);
    $client_id = filter_var(isset($_POST['client_id']) ? $_POST['client_id'] : null, FILTER_VALIDATE_INT);
    if (!$device_id || !$client_id) {
        $error = 'Choose a valid active customer session.';
    } else {
        try {
            $mysqli->begin_transaction();
            $stmt = $mysqli->prepare("UPDATE ib_login_devices
                                      SET status = 'revoked', revoked_at = CURRENT_TIMESTAMP
                                      WHERE device_id = ? AND client_id = ? AND status = 'active'");
            if (!$stmt) {
                throw new RuntimeException('Could not prepare session revocation.');
            }
            $stmt->bind_param('ii', $device_id, $client_id);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('This customer session is no longer active.');
            }
            $stmt->close();

            $actor_role = 'admin';
            $action = 'customer_session_revoked';
            $entity_type = 'login_device';
            $entity_id = (string) $device_id;
            $details = 'Customer session revoked by administrator.';
            $stmt = $mysqli->prepare('INSERT INTO ib_audit_log (actor_role, actor_id, action, entity_type, entity_id, details)
                                      VALUES (?, ?, ?, ?, ?, ?)');
            if (!$stmt) {
                throw new RuntimeException('Could not prepare the audit record.');
            }
            $stmt->bind_param('sissss', $actor_role, $admin_id, $action, $entity_type, $entity_id, $details);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Could not record the session revocation.');
            }
            $stmt->close();
            $mysqli->commit();
            $success = 'Customer session revoked. The customer is signed out on their next authenticated request.';
            $_SESSION['rb_admin_insights_success'] = $success;
            header('Location: pages_customer_insights.php' . ($search !== '' ? '?q=' . rawurlencode($search) : ''));
            exit;
        } catch (Throwable $e) {
            try {
                $mysqli->rollback();
            } catch (Throwable $rollback_error) {
            }
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not revoke this customer session.';
            error_log('[RuralBanking] admin customer session revocation failed: ' . $e->getMessage());
        }
    }
}

if ($ready && isset($_POST['adjust_rewards'])) {
    $client_id = filter_var(isset($_POST['reward_client_id']) ? $_POST['reward_client_id'] : null, FILTER_VALIDATE_INT);
    $points = filter_var(isset($_POST['reward_points']) ? $_POST['reward_points'] : null, FILTER_VALIDATE_INT);
    $direction = isset($_POST['reward_direction']) ? (string) $_POST['reward_direction'] : '';
    $reason = trim((string) (isset($_POST['reward_reason']) ? $_POST['reward_reason'] : ''));
    if (!$client_id || !$points || $points > 1000
        || !in_array($direction, array('award', 'deduct'), true)
        || $reason === '' || strlen($reason) > 72 || preg_match('/[\x00-\x1F\x7F]/', $reason)) {
        $error = 'Choose a customer, enter 1–1,000 points, and provide a reason of at most 72 characters.';
    } else {
        try {
            $mysqli->begin_transaction();
            $stmt = $mysqli->prepare('SELECT client_id FROM ib_clients WHERE client_id = ? FOR UPDATE');
            $stmt->bind_param('i', $client_id);
            $stmt->execute();
            $stmt->store_result();
            $client_exists = $stmt->num_rows === 1;
            $stmt->close();
            if (!$client_exists) {
                throw new RuntimeException('The selected customer does not exist.');
            }

            $stmt = $mysqli->prepare('SELECT COALESCE(SUM(points_delta), 0) FROM ib_rewards WHERE client_id = ?');
            $stmt->bind_param('i', $client_id);
            $stmt->execute();
            $stmt->bind_result($available_points);
            $stmt->fetch();
            $stmt->close();
            if ($direction === 'deduct' && (int) $available_points < $points) {
                throw new RuntimeException('The customer does not have enough reward points for this deduction.');
            }

            $points_delta = $direction === 'award' ? $points : -$points;
            $reward_reason = 'Admin: ' . $reason;
            $reference = 'ADM-' . strtoupper(bin2hex(random_bytes(12)));
            $stmt = $mysqli->prepare('INSERT INTO ib_rewards (client_id, points_delta, reason, reference_code) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('iiss', $client_id, $points_delta, $reward_reason, $reference);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Could not save this points adjustment.');
            }
            $reward_id = (int) $mysqli->insert_id;
            $stmt->close();

            $actor_role = 'admin';
            $action = $direction === 'award' ? 'reward_points_awarded' : 'reward_points_deducted';
            $entity_type = 'reward';
            $entity_id = (string) $reward_id;
            $details = $direction . ' ' . $points . ' reward points for customer #' . $client_id;
            $stmt = $mysqli->prepare('INSERT INTO ib_audit_log (actor_role, actor_id, action, entity_type, entity_id, details)
                                      VALUES (?, ?, ?, ?, ?, ?)');
            if (!$stmt) {
                throw new RuntimeException('Could not prepare the audit record.');
            }
            $stmt->bind_param('sissss', $actor_role, $admin_id, $action, $entity_type, $entity_id, $details);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Could not record the points adjustment.');
            }
            $stmt->close();
            $mysqli->commit();
            $success = ucfirst($direction) . 'ed ' . $points . ' reward points for customer #' . $client_id . '.';
            $_SESSION['rb_admin_insights_success'] = $success;
            header('Location: pages_customer_insights.php' . ($search !== '' ? '?q=' . rawurlencode($search) : ''));
            exit;
        } catch (Throwable $e) {
            try {
                $mysqli->rollback();
            } catch (Throwable $rollback_error) {
            }
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not adjust reward points.';
            error_log('[RuralBanking] admin reward adjustment failed: ' . $e->getMessage());
        }
    }
}

if ($ready) {
    try {
        $stmt = $mysqli->prepare("SELECT
                                     (SELECT COUNT(*) FROM ib_budgets WHERE budget_month = ?) AS current_budgets,
                                     (SELECT COUNT(*) FROM ib_savings_goals WHERE status = 'active') AS active_goals,
                                     (SELECT COUNT(*) FROM ib_login_devices WHERE status = 'active') AS active_devices,
                                     (SELECT COALESCE(SUM(points_delta), 0) FROM ib_rewards) AS reward_points");
        $stmt->bind_param('s', $month);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $metrics = $row;
        }
        $stmt->close();

        $stmt = $mysqli->prepare("SELECT b.budget_month, b.limit_amount, c.name AS category_name,
                                         client.client_id, client.name AS client_name,
                                         COALESCE(SUM(t.transaction_amt), 0) AS spent
                                  FROM ib_budgets b
                                  JOIN ib_clients client ON client.client_id = b.client_id
                                  JOIN ib_expense_categories c ON c.category_id = b.category_id
                                  LEFT JOIN ib_expense_entries e
                                    ON e.client_id = b.client_id AND e.category_id = b.category_id
                                  LEFT JOIN ib_transactions t
                                    ON t.tr_id = e.transaction_id
                                   AND DATE_FORMAT(t.created_at, '%Y-%m') = b.budget_month
                                  WHERE (? = '' OR client.name LIKE CONCAT('%', ?, '%') OR client.email LIKE CONCAT('%', ?, '%'))
                                  GROUP BY b.budget_id, b.budget_month, b.limit_amount, c.name, client.client_id, client.name
                                  ORDER BY b.budget_month DESC, b.budget_id DESC LIMIT 100");
        $stmt->bind_param('sss', $search, $search, $search);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $budgets[] = $row;
        }
        $stmt->close();

        $stmt = $mysqli->prepare("SELECT g.goal_id, g.title, g.goal_type, g.target_amount, g.saved_amount,
                                         g.target_date, g.status, g.created_at, c.client_id, c.name AS client_name
                                  FROM ib_savings_goals g
                                  JOIN ib_clients c ON c.client_id = g.client_id
                                  WHERE (? = '' OR c.name LIKE CONCAT('%', ?, '%') OR c.email LIKE CONCAT('%', ?, '%'))
                                  ORDER BY g.created_at DESC LIMIT 100");
        $stmt->bind_param('sss', $search, $search, $search);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $goals[] = $row;
        }
        $stmt->close();

        $stmt = $mysqli->prepare("SELECT r.points_delta, r.reason, r.created_at, c.client_id, c.name AS client_name
                                  FROM ib_rewards r
                                  JOIN ib_clients c ON c.client_id = r.client_id
                                  WHERE (? = '' OR c.name LIKE CONCAT('%', ?, '%') OR c.email LIKE CONCAT('%', ?, '%'))
                                  ORDER BY r.created_at DESC LIMIT 100");
        $stmt->bind_param('sss', $search, $search, $search);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $rewards[] = $row;
        }
        $stmt->close();

        $stmt = $mysqli->prepare("SELECT d.device_id, d.client_id, d.label, d.status, d.first_seen, d.last_seen,
                                         d.revoked_at, c.name AS client_name
                                  FROM ib_login_devices d
                                  JOIN ib_clients c ON c.client_id = d.client_id
                                  WHERE (? = '' OR c.name LIKE CONCAT('%', ?, '%') OR c.email LIKE CONCAT('%', ?, '%'))
                                  ORDER BY (d.status = 'active') DESC, d.last_seen DESC LIMIT 100");
        $stmt->bind_param('sss', $search, $search, $search);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $devices[] = $row;
        }
        $stmt->close();
    } catch (Throwable $e) {
        $ready = false;
        $error = 'Could not load customer oversight data. Check the advanced features migration.';
        error_log('[RuralBanking] admin customer insights load failed: ' . $e->getMessage());
    }
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
          <h1>Customer Insights &amp; Security</h1>
          <p class="text-muted mb-0">Review customer budgets, savings goals and rewards. Session revocation and point adjustments are audited. <a href="../service_information.php">Service Information</a></p>
        </div>
      </section>
      <section class="content">
        <div class="container-fluid">
          <?php if (!$ready) { ?><div class="alert alert-warning">Import <code>database/advanced-features-migration.sql</code> before using this page.</div><?php } ?>
          <?php if ($error !== '') { ?><div class="alert alert-danger" role="alert"><?php echo rb_e($error); ?></div><?php } ?>
          <?php if ($success !== '') { ?><div class="alert alert-success" role="status"><?php echo rb_e($success); ?></div><?php } ?>
          <div class="row">
            <div class="col-md-3"><div class="info-box"><span class="info-box-icon bg-info"><i class="fas fa-wallet"></i></span><div class="info-box-content"><span class="info-box-text">Budgets this month</span><span class="info-box-number"><?php echo (int) $metrics['current_budgets']; ?></span></div></div></div>
            <div class="col-md-3"><div class="info-box"><span class="info-box-icon bg-success"><i class="fas fa-bullseye"></i></span><div class="info-box-content"><span class="info-box-text">Active savings goals</span><span class="info-box-number"><?php echo (int) $metrics['active_goals']; ?></span></div></div></div>
            <div class="col-md-3"><div class="info-box"><span class="info-box-icon bg-warning"><i class="fas fa-shield-alt"></i></span><div class="info-box-content"><span class="info-box-text">Active customer sessions</span><span class="info-box-number"><?php echo (int) $metrics['active_devices']; ?></span></div></div></div>
            <div class="col-md-3"><div class="info-box"><span class="info-box-icon bg-purple"><i class="fas fa-star"></i></span><div class="info-box-content"><span class="info-box-text">Net reward points</span><span class="info-box-number"><?php echo (int) $metrics['reward_points']; ?></span></div></div></div>
          </div>
          <div class="card">
            <div class="card-body">
              <form method="get" class="form-inline">
                <label for="customer-search" class="mr-2">Filter customer activity</label>
                <input id="customer-search" class="form-control mr-2" name="q" value="<?php echo rb_e($search); ?>" maxlength="100" placeholder="Name or email">
                <button class="btn btn-primary mr-2" type="submit">Search</button>
                <?php if ($search !== '') { ?><a class="btn btn-outline-secondary" href="pages_customer_insights.php">Clear</a><?php } ?>
              </form>
            </div>
          </div>
          <div class="card">
            <div class="card-header"><h3 class="card-title">Customer budgets &amp; categorized withdrawals</h3></div>
            <div class="card-body table-responsive p-0"><table class="table table-sm table-hover mb-0">
              <thead><tr><th>Customer</th><th>Month</th><th>Category</th><th>Limit</th><th>Categorized spend</th><th>Usage</th></tr></thead>
              <tbody><?php foreach ($budgets as $budget) {
                  $limit = (float) $budget['limit_amount'];
                  $spent = (float) $budget['spent'];
                  $percent = $limit > 0 ? min(100, ($spent / $limit) * 100) : 0;
                  $over = $spent > $limit;
              ?><tr>
                <td><?php echo rb_e($budget['client_name']); ?> <small class="text-muted">#<?php echo (int) $budget['client_id']; ?></small></td>
                <td><?php echo rb_e($budget['budget_month']); ?></td>
                <td><?php echo rb_e($budget['category_name']); ?></td>
                <td><?php echo rb_e(rb_money($limit)); ?></td>
                <td><?php echo rb_e(rb_money($spent)); ?></td>
                <td><span class="badge <?php echo $over ? 'badge-danger' : 'badge-success'; ?>"><?php echo $over ? 'Over limit' : number_format($percent, 0) . '%'; ?></span></td>
              </tr><?php } ?>
              <?php if (!$budgets) { ?><tr><td colspan="6" class="text-center text-muted py-3">No matching budget records.</td></tr><?php } ?></tbody>
            </table></div>
            <div class="card-footer small text-muted">Spending includes only customer-categorized withdrawals. Personal expense notes are not shown.</div>
          </div>
          <div class="row">
            <div class="col-lg-7"><div class="card">
              <div class="card-header"><h3 class="card-title">Savings goals</h3></div>
              <div class="card-body table-responsive p-0"><table class="table table-sm table-hover mb-0">
                <thead><tr><th>Customer</th><th>Goal</th><th>Type</th><th>Progress estimate</th><th>Status</th></tr></thead>
                <tbody><?php foreach ($goals as $goal) {
                    $target = (float) $goal['target_amount'];
                    $saved = (float) $goal['saved_amount'];
                    $progress = $target > 0 ? min(100, ($saved / $target) * 100) : 0;
                ?><tr>
                  <td><?php echo rb_e($goal['client_name']); ?></td>
                  <td><?php echo rb_e($goal['title']); ?><div class="small text-muted"><?php echo $goal['target_date'] ? rb_e($goal['target_date']) : 'No target date'; ?></div></td>
                  <td><?php echo rb_e($goal['goal_type']); ?></td>
                  <td><?php echo rb_e(rb_money($saved)); ?> / <?php echo rb_e(rb_money($target)); ?><div class="progress progress-xs"><div class="progress-bar bg-success" role="progressbar" style="width: <?php echo number_format($progress, 1, '.', ''); ?>%"></div></div></td>
                  <td><?php echo rb_e($goal['status']); ?></td>
                </tr><?php } ?>
                <?php if (!$goals) { ?><tr><td colspan="5" class="text-center text-muted py-3">No matching savings goals.</td></tr><?php } ?></tbody>
              </table></div>
            </div></div>
            <div class="col-lg-5"><div class="card">
              <div class="card-header"><h3 class="card-title">Recent reward activity</h3></div>
              <div class="card-body table-responsive p-0"><table class="table table-sm table-hover mb-0">
                <thead><tr><th>Customer</th><th>Points</th><th>Reason</th><th>Date</th></tr></thead>
                <tbody><?php foreach ($rewards as $reward) {
                  $reason_label = isset($reward_reason_labels[$reward['reason']]) ? $reward_reason_labels[$reward['reason']] : $reward['reason'];
                ?><tr>
                  <td><?php echo rb_e($reward['client_name']); ?><div class="small text-muted">#<?php echo (int) $reward['client_id']; ?></div></td>
                  <td class="<?php echo (int) $reward['points_delta'] < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo (int) $reward['points_delta']; ?></td>
                  <td><?php echo rb_e($reason_label); ?></td>
                  <td><?php echo rb_e($reward['created_at']); ?></td>
                </tr><?php } ?>
                <?php if (!$rewards) { ?><tr><td colspan="4" class="text-center text-muted py-3">No matching reward activity.</td></tr><?php } ?></tbody>
              </table></div>
              <div class="card-footer small text-muted">Manual adjustments are shown in this history and in the admin audit log.</div>
            </div></div>
          </div>
          <div class="card">
            <div class="card-header"><h3 class="card-title">Adjust reward points</h3></div>
            <div class="card-body">
              <p class="small text-muted">Manual awards or deductions are limited to 1,000 points per action, cannot create a negative customer balance, and are written to the admin audit log.</p>
              <form method="post" class="form-row">
                <div class="form-group col-md-2"><label for="reward-client-id">Customer ID</label><input id="reward-client-id" class="form-control" type="number" min="1" name="reward_client_id" required></div>
                <div class="form-group col-md-2"><label for="reward-direction">Action</label><select id="reward-direction" class="form-control" name="reward_direction"><option value="award">Award</option><option value="deduct">Deduct</option></select></div>
                <div class="form-group col-md-2"><label for="reward-points">Points</label><input id="reward-points" class="form-control" type="number" min="1" max="1000" name="reward_points" required></div>
                <div class="form-group col-md-4"><label for="reward-reason">Reason (visible to customer)</label><input id="reward-reason" class="form-control" name="reward_reason" maxlength="72" required></div>
                <div class="form-group col-md-2 d-flex align-items-end"><button class="btn btn-primary" type="submit" name="adjust_rewards" value="1">Save adjustment</button></div>
              </form>
            </div>
          </div>
          <div class="card">
            <div class="card-header"><h3 class="card-title">Customer sign-in sessions</h3></div>
            <div class="card-body table-responsive p-0"><table class="table table-sm table-hover mb-0">
              <thead><tr><th>Customer</th><th>Browser/device label</th><th>Status</th><th>First seen</th><th>Last seen</th><th>Action</th></tr></thead>
              <tbody><?php foreach ($devices as $device) { ?><tr>
                <td><?php echo rb_e($device['client_name']); ?> <small class="text-muted">#<?php echo (int) $device['client_id']; ?></small></td>
                <td><?php echo rb_e($device['label']); ?></td>
                <td><?php echo rb_e($device['status']); ?></td>
                <td><?php echo rb_e($device['first_seen']); ?></td>
                <td><?php echo rb_e($device['last_seen']); ?></td>
                <td><?php if ($device['status'] === 'active') { ?><form method="post" onsubmit="return confirm('Revoke this customer session?');">
                  <input type="hidden" name="client_id" value="<?php echo (int) $device['client_id']; ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit" name="revoke_device" value="<?php echo (int) $device['device_id']; ?>">Revoke</button>
                </form><?php } else { ?><?php echo $device['revoked_at'] ? rb_e($device['revoked_at']) : '—'; ?><?php } ?></td>
              </tr><?php } ?>
              <?php if (!$devices) { ?><tr><td colspan="6" class="text-center text-muted py-3">No matching customer sessions.</td></tr><?php } ?></tbody>
            </table></div>
            <div class="card-footer small text-muted">IP and session hashes are intentionally hidden. Revocation is enforced at the customer's next authenticated request.</div>
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
