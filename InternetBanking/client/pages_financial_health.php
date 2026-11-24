<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();
$client_id = (int) $_SESSION['client_id'];
$error = '';
$success = '';
$categories = array();
$budgets = array();
$goals = array();
$expenses = array();
$rewards = array();
$points_balance = 0;
$income = 0.0;
$spending = 0.0;
$month = date('Y-m');
$finance_ready = true;
try {
    $mysqli->query('SELECT budget_id FROM ib_budgets LIMIT 0');
} catch (Throwable $e) {
    $finance_ready = false;
}

function rb_finance_valid_month($value)
{
    if (!is_string($value) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $value)) {
        return false;
    }
    return true;
}

if ($finance_ready && isset($_POST['save_budget'])) {
    $category_id = filter_var(isset($_POST['category_id']) ? $_POST['category_id'] : null, FILTER_VALIDATE_INT);
    $month = isset($_POST['budget_month']) ? (string) $_POST['budget_month'] : '';
    $limit = rb_money_parse(isset($_POST['limit_amount']) ? $_POST['limit_amount'] : null);
    if (!$category_id || !rb_finance_valid_month($month) || $limit === null) {
        $error = 'Choose a category, a valid month, and a positive monthly limit.';
    } else {
        $stmt = $mysqli->prepare('SELECT category_id FROM ib_expense_categories WHERE category_id = ? AND (client_id IS NULL OR client_id = ?) LIMIT 1');
        $stmt->bind_param('ii', $category_id, $client_id);
        $stmt->execute();
        $valid_category = $stmt->fetch();
        $stmt->close();
        if (!$valid_category) {
            $error = 'That expense category is unavailable.';
        } else {
            $stmt = $mysqli->prepare('INSERT INTO ib_budgets (client_id, category_id, budget_month, limit_amount) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE limit_amount = VALUES(limit_amount)');
            $limit_value = number_format($limit, 2, '.', '');
            $stmt->bind_param('iiss', $client_id, $category_id, $month, $limit_value);
            if ($stmt->execute()) {
                $success = 'Monthly budget saved.';
            } else {
                $error = 'Could not save this budget.';
            }
            $stmt->close();
        }
    }
}

if ($finance_ready && isset($_POST['tag_expense'])) {
    $transaction_id = filter_var(isset($_POST['transaction_id']) ? $_POST['transaction_id'] : null, FILTER_VALIDATE_INT);
    $category_id = filter_var(isset($_POST['expense_category_id']) ? $_POST['expense_category_id'] : null, FILTER_VALIDATE_INT);
    $note = trim((string) (isset($_POST['expense_note']) ? $_POST['expense_note'] : ''));
    if (!$transaction_id || !$category_id || strlen($note) > 180 || preg_match('/[\x00-\x1F\x7F]/', $note)) {
        $error = 'Choose a withdrawal and category; notes must be 180 characters or fewer.';
    } else {
        $stmt = $mysqli->prepare("SELECT tr_id FROM ib_transactions WHERE tr_id = ? AND client_id = ? AND tr_type = 'Withdrawal' LIMIT 1");
        $stmt->bind_param('ii', $transaction_id, $client_id);
        $stmt->execute();
        $valid_transaction = $stmt->fetch();
        $stmt->close();
        if (!$valid_transaction) {
            $error = 'Only your own withdrawal transactions can be categorized as expenses.';
        } else {
            $stmt = $mysqli->prepare('SELECT category_id FROM ib_expense_categories WHERE category_id = ? AND (client_id IS NULL OR client_id = ?) LIMIT 1');
            $stmt->bind_param('ii', $category_id, $client_id);
            $stmt->execute();
            $valid_category = $stmt->fetch();
            $stmt->close();
            if (!$valid_category) {
                $error = 'That expense category is unavailable.';
            } else {
                $stmt = $mysqli->prepare('INSERT INTO ib_expense_entries (client_id, transaction_id, category_id, note) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), note = VALUES(note)');
                $stmt->bind_param('iiis', $client_id, $transaction_id, $category_id, $note);
                if ($stmt->execute()) {
                    $success = 'Withdrawal category saved.';
                } else {
                    $error = 'Could not categorize this transaction.';
                }
                $stmt->close();
            }
        }
    }
}

if ($finance_ready && isset($_POST['create_goal'])) {
    $title = trim((string) (isset($_POST['goal_title']) ? $_POST['goal_title'] : ''));
    $goal_type = isset($_POST['goal_type']) ? (string) $_POST['goal_type'] : 'other';
    $target = rb_money_parse(isset($_POST['target_amount']) ? $_POST['target_amount'] : null);
    $target_date = trim((string) (isset($_POST['target_date']) ? $_POST['target_date'] : ''));
    $allowed_goal_types = array('education', 'farming', 'home', 'emergency', 'other');
    if ($title === '' || strlen($title) > 100 || preg_match('/[\x00-\x1F\x7F]/', $title)
        || !in_array($goal_type, $allowed_goal_types, true) || $target === null
        || ($target_date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $target_date)
            || !DateTime::createFromFormat('!Y-m-d', $target_date)
            || DateTime::createFromFormat('!Y-m-d', $target_date)->format('Y-m-d') !== $target_date))
    ) {
        $error = 'Enter a valid goal name, type, target, and optional date.';
    } else {
        $target_value = number_format($target, 2, '.', '');
        $target_date_value = $target_date === '' ? null : $target_date;
        $stmt = $mysqli->prepare('INSERT INTO ib_savings_goals (client_id, title, goal_type, target_amount, target_date) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('issss', $client_id, $title, $goal_type, $target_value, $target_date_value);
        if ($stmt->execute()) {
            $success = 'Savings goal created. Progress is an estimate and does not move account funds.';
        } else {
            $error = 'Could not create the savings goal.';
        }
        $stmt->close();
    }
}

if ($finance_ready && isset($_POST['update_goal'])) {
    $goal_id = filter_var(isset($_POST['goal_id']) ? $_POST['goal_id'] : null, FILTER_VALIDATE_INT);
    $saved = rb_money_parse(isset($_POST['saved_amount']) ? $_POST['saved_amount'] : null);
    if (!$goal_id || $saved === null) {
        $error = 'Enter a valid goal and saved amount.';
    } else {
        $saved_value = number_format($saved, 2, '.', '');
        $stmt = $mysqli->prepare("UPDATE ib_savings_goals SET saved_amount = ?, status = IF(? >= target_amount, 'complete', 'active') WHERE goal_id = ? AND client_id = ?");
        $stmt->bind_param('ssii', $saved_value, $saved_value, $goal_id, $client_id);
        if ($stmt->execute() && $stmt->affected_rows <= 1) {
            $success = 'Savings goal progress updated.';
        } else {
            $error = 'Could not update that savings goal.';
        }
        $stmt->close();
    }
}

if ($finance_ready && isset($_POST['redeem_demo_reward'])) {
    try {
        $mysqli->begin_transaction();
        $stmt = $mysqli->prepare('SELECT client_id FROM ib_clients WHERE client_id = ? FOR UPDATE');
        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $stmt->store_result();
        $client_exists = $stmt->num_rows === 1;
        $stmt->close();
        if (!$client_exists) {
            throw new RuntimeException('Customer account not found.');
        }
        $stmt = $mysqli->prepare('SELECT COALESCE(SUM(points_delta), 0) FROM ib_rewards WHERE client_id = ?');
        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $stmt->bind_result($available_points);
        $stmt->fetch();
        $stmt->close();
        if ((int) $available_points < 100) {
            throw new RuntimeException('At least 100 points are required to redeem a reward badge.');
        }
        $reason = 'demo_badge_redemption';
        $reference = 'RDM-' . strtoupper(bin2hex(random_bytes(12)));
        $points = -100;
        $stmt = $mysqli->prepare('INSERT INTO ib_rewards (client_id, points_delta, reason, reference_code) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('iiss', $client_id, $points, $reason, $reference);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not redeem the reward badge.');
        }
        $stmt->close();
        $mysqli->commit();
        $success = '100 points redeemed for a reward badge.';
    } catch (Throwable $e) {
        try { $mysqli->rollback(); } catch (Throwable $rollbackError) {}
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not redeem this reward.';
        error_log('[RuralBanking] reward redemption failed: ' . $e->getMessage());
    }
}

try {
    $stmt = $mysqli->prepare("SELECT category_id, name FROM ib_expense_categories WHERE client_id IS NULL OR client_id = ? ORDER BY is_system DESC, name");
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $categories[] = $row; }
    $stmt->close();

    $stmt = $mysqli->prepare("SELECT COALESCE(SUM(CASE WHEN tr_type = 'Deposit' THEN transaction_amt ELSE 0 END), 0),
                                     COALESCE(SUM(CASE WHEN tr_type = 'Withdrawal' THEN transaction_amt ELSE 0 END), 0)
                              FROM ib_transactions
                              WHERE client_id = ? AND DATE_FORMAT(created_at, '%Y-%m') = ?");
    $stmt->bind_param('is', $client_id, $month);
    $stmt->execute();
    $stmt->bind_result($income, $spending);
    $stmt->fetch();
    $stmt->close();

    $stmt = $mysqli->prepare("SELECT b.budget_id, b.budget_month, b.limit_amount, c.name,
                                     COALESCE(SUM(t.transaction_amt),0) AS spent
                              FROM ib_budgets b JOIN ib_expense_categories c ON c.category_id = b.category_id
                              LEFT JOIN ib_expense_entries e ON e.client_id = b.client_id AND e.category_id = b.category_id
                              LEFT JOIN ib_transactions t ON t.tr_id = e.transaction_id AND DATE_FORMAT(t.created_at, '%Y-%m') = b.budget_month
                              WHERE b.client_id = ? AND b.budget_month = ?
                              GROUP BY b.budget_id, b.budget_month, b.limit_amount, c.name ORDER BY c.name");
    $stmt->bind_param('is', $client_id, $month);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $budgets[] = $row; }
    $stmt->close();

    $stmt = $mysqli->prepare('SELECT g.goal_id, g.title, g.goal_type, g.target_amount, g.saved_amount, g.target_date, g.status FROM ib_savings_goals g WHERE g.client_id = ? ORDER BY g.created_at DESC LIMIT 50');
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $goals[] = $row; }
    $stmt->close();

    $stmt = $mysqli->prepare("SELECT t.tr_id, t.transaction_amt, t.created_at
                              FROM ib_transactions t
                              LEFT JOIN ib_expense_entries e ON e.transaction_id = t.tr_id
                              WHERE t.client_id = ? AND t.tr_type = 'Withdrawal' AND e.entry_id IS NULL
                              ORDER BY t.created_at DESC LIMIT 30");
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $expenses[] = $row; }
    $stmt->close();

    $stmt = $mysqli->prepare('SELECT COALESCE(SUM(points_delta),0) FROM ib_rewards WHERE client_id = ?');
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $stmt->bind_result($points_balance);
    $stmt->fetch();
    $stmt->close();
    $stmt = $mysqli->prepare('SELECT points_delta, reason, reference_code, created_at FROM ib_rewards WHERE client_id = ? ORDER BY created_at DESC LIMIT 10');
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $rewards[] = $row; }
    $stmt->close();
} catch (Throwable $e) {
    $error = 'Financial tools are not ready. Import database/advanced-features-migration.sql.';
    error_log('[RuralBanking] financial health load failed: ' . $e->getMessage());
}

$savings_rate = ($income + $spending) > 0 ? (($income - $spending) / max(1, $income)) * 100 : 0;
$reward_reason_labels = array(
    'demo_badge_redemption' => 'Reward badge redemption',
    'external_transfer' => 'Account transfer reward',
);
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
      <section class="content-header"><div class="container-fluid"><h1>Financial Health &amp; Goals</h1><p class="text-muted">Budgets, savings goals and rewards activity. <a href="../service_information.php">Service Information</a></p></div></section>
      <section class="content"><div class="container-fluid">
        <?php if ($error !== '') { ?><div class="alert alert-danger" role="alert"><?php echo rb_e($error); ?></div><?php } ?>
        <?php if ($success !== '') { ?><div class="alert alert-success" role="status"><?php echo rb_e($success); ?></div><?php } ?>
        <div class="row">
          <div class="col-md-4"><div class="info-box"><span class="info-box-icon bg-success"><i class="fas fa-arrow-down"></i></span><div class="info-box-content"><span class="info-box-text">This month's deposits</span><span class="info-box-number"><?php echo rb_e(rb_money($income)); ?></span></div></div></div>
          <div class="col-md-4"><div class="info-box"><span class="info-box-icon bg-danger"><i class="fas fa-arrow-up"></i></span><div class="info-box-content"><span class="info-box-text">This month's withdrawals</span><span class="info-box-number"><?php echo rb_e(rb_money($spending)); ?></span></div></div></div>
          <div class="col-md-4"><div class="info-box"><span class="info-box-icon bg-info"><i class="fas fa-piggy-bank"></i></span><div class="info-box-content"><span class="info-box-text">Estimated net rate</span><span class="info-box-number"><?php echo rb_e(number_format($savings_rate, 1)); ?>%</span></div></div></div>
        </div>
        <div class="alert alert-info"><strong>Financial insight (based on recorded activity):</strong>
          <?php if ($income <= 0) { ?>There is not enough recorded deposit activity this month to estimate a budget trend.<?php }
          elseif ($spending > $income) { ?>Recorded withdrawals exceed deposits this month. Review categories and consider setting a spending limit.<?php }
          elseif ($savings_rate < 20) { ?>Your current recorded net rate is below 20%. A small, regular savings target may help.<?php }
          else { ?>Recorded deposits exceed withdrawals. Keep an emergency buffer and review your goals regularly.<?php } ?>
        </div>
        <div class="row">
          <div class="col-lg-6">
            <div class="card"><div class="card-header"><h3 class="card-title">Monthly budgets (<?php echo rb_e($month); ?>)</h3></div><div class="card-body">
              <form method="post" class="form-row">
                <div class="form-group col-md-4"><label>Category</label><select class="form-control" name="category_id" required><?php foreach ($categories as $category) { ?><option value="<?php echo (int) $category['category_id']; ?>"><?php echo rb_e($category['name']); ?></option><?php } ?></select></div>
                <div class="form-group col-md-3"><label>Month</label><input class="form-control" type="month" name="budget_month" value="<?php echo rb_e($month); ?>" required></div>
                <div class="form-group col-md-3"><label>Limit (INR)</label><input class="form-control" name="limit_amount" type="number" min="0.01" step="0.01" required></div>
                <div class="form-group col-md-2 d-flex align-items-end"><button class="btn btn-success" name="save_budget" value="1">Save</button></div>
              </form>
              <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Category</th><th>Spent</th><th>Limit</th></tr></thead><tbody>
              <?php foreach ($budgets as $budget) { ?><tr><td><?php echo rb_e($budget['name']); ?></td><td><?php echo rb_e(rb_money($budget['spent'])); ?></td><td><?php echo rb_e(rb_money($budget['limit_amount'])); ?></td></tr><?php } ?>
              <?php if (!$budgets) { ?><tr><td colspan="3" class="text-muted">No budget set for this month.</td></tr><?php } ?>
              </tbody></table></div>
            </div></div>
            <div class="card"><div class="card-header"><h3 class="card-title">Categorize withdrawals</h3></div><div class="card-body">
              <form method="post" class="form-row">
                <div class="form-group col-md-4"><label>Withdrawal</label><select class="form-control" name="transaction_id" required><?php foreach ($expenses as $expense) { ?><option value="<?php echo (int) $expense['tr_id']; ?>"><?php echo rb_e(date('d M', strtotime($expense['created_at'])) . ' · ' . rb_money($expense['transaction_amt'])); ?></option><?php } ?></select></div>
                <div class="form-group col-md-3"><label>Category</label><select class="form-control" name="expense_category_id" required><?php foreach ($categories as $category) { ?><option value="<?php echo (int) $category['category_id']; ?>"><?php echo rb_e($category['name']); ?></option><?php } ?></select></div>
                <div class="form-group col-md-3"><label>Note</label><input class="form-control" name="expense_note" maxlength="180"></div>
                <div class="form-group col-md-2 d-flex align-items-end"><button class="btn btn-outline-success" name="tag_expense" value="1">Save</button></div>
              </form>
            </div></div>
          </div>
          <div class="col-lg-6">
            <div class="card"><div class="card-header"><h3 class="card-title">Savings goals</h3></div><div class="card-body">
              <form method="post" class="form-row">
                <div class="form-group col-md-4"><label>Goal</label><input class="form-control" name="goal_title" maxlength="100" required></div>
                <div class="form-group col-md-3"><label>Type</label><select class="form-control" name="goal_type"><option value="education">Education</option><option value="farming">Farming</option><option value="home">Home</option><option value="emergency">Emergency</option><option value="other">Other</option></select></div>
                <div class="form-group col-md-3"><label>Target (INR)</label><input class="form-control" name="target_amount" type="number" min="0.01" step="0.01" required></div>
                <div class="form-group col-md-2"><label>Target date</label><input class="form-control" name="target_date" type="date"></div>
                <div class="col-12"><button class="btn btn-success" name="create_goal" value="1">Create goal</button></div>
              </form>
              <?php foreach ($goals as $goal) { ?>
                <form method="post" class="border-top py-2 mt-2">
                  <input type="hidden" name="goal_id" value="<?php echo (int) $goal['goal_id']; ?>">
                  <strong><?php echo rb_e($goal['title']); ?></strong> · <?php echo rb_e(rb_money($goal['saved_amount'])); ?> / <?php echo rb_e(rb_money($goal['target_amount'])); ?> · <?php echo rb_e($goal['status']); ?>
                  <?php if ($goal['status'] === 'active') { ?><div class="form-inline mt-2"><label class="mr-2" for="saved-<?php echo (int) $goal['goal_id']; ?>">Estimated saved</label><input class="form-control form-control-sm mr-2" id="saved-<?php echo (int) $goal['goal_id']; ?>" name="saved_amount" type="number" min="0.01" step="0.01" required><button class="btn btn-sm btn-outline-primary" name="update_goal" value="1">Update</button></div><?php } ?>
                </form>
              <?php } ?>
            </div></div>
            <div class="card"><div class="card-header"><h3 class="card-title">Rewards</h3></div><div class="card-body">
              <p><strong><?php echo (int) max(0, $points_balance); ?> points</strong> · Current rewards balance.</p>
              <form method="post" class="mb-2"><button class="btn btn-sm btn-outline-success" name="redeem_demo_reward" value="1" <?php echo $points_balance < 100 ? 'disabled' : ''; ?>>Redeem 100 points for a badge</button></form>
              <?php foreach ($rewards as $reward) {
                $reason_label = isset($reward_reason_labels[$reward['reason']]) ? $reward_reason_labels[$reward['reason']] : $reward['reason'];
              ?><div class="small border-top py-1"><?php echo rb_e($reward['created_at'] . ' · ' . $reason_label . ' · ' . ($reward['points_delta'] > 0 ? '+' : '') . $reward['points_delta']); ?></div><?php } ?>
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
