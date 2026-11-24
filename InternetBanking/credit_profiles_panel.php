<?php
if (!defined('RB_CREDIT_PROFILES_PORTAL') || !in_array(RB_CREDIT_PROFILES_PORTAL, array('admin', 'staff'), true)) {
    http_response_code(404);
    exit;
}

$portal = RB_CREDIT_PROFILES_PORTAL;
$search = isset($_GET['q']) && is_scalar($_GET['q']) ? trim((string) $_GET['q']) : '';
$page = isset($_GET['page']) && is_scalar($_GET['page']) ? filter_var($_GET['page'], FILTER_VALIDATE_INT) : 1;
$historyPage = isset($_GET['history_page']) && is_scalar($_GET['history_page']) ? filter_var($_GET['history_page'], FILTER_VALIDATE_INT) : 1;
$page = $page && $page > 0 ? min((int) $page, 100000) : 1;
$historyPage = $historyPage && $historyPage > 0 ? min((int) $historyPage, 100000) : 1;
$pageSize = 100;
$offset = ($page - 1) * $pageSize;
$historyOffset = ($historyPage - 1) * $pageSize;
$error = '';
$profiles = array();
$scoreHistory = array();
$profileCount = 0;
$historyCount = 0;

if (strlen($search) > 100) {
    $error = 'Search text must be 100 characters or fewer.';
    $search = '';
}

try {
    $pattern = '%' . $search . '%';
    $filter = "WHERE (? = '' OR c.name LIKE ? OR c.email LIKE ? OR c.client_number LIKE ?)";
    $stmt = $mysqli->prepare('SELECT COUNT(*) FROM ib_credit_profiles p JOIN ib_clients c ON c.client_id = p.client_id ' . $filter);
    if (!$stmt) {
        throw new RuntimeException('Could not prepare credit profile count.');
    }
    $stmt->bind_param('ssss', $search, $pattern, $pattern, $pattern);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not count credit profiles.');
    }
    $stmt->bind_result($profileCount);
    $stmt->fetch();
    $stmt->close();

    $stmt = $mysqli->prepare(
        'SELECT c.client_id, c.name, c.client_number, c.email, p.monthly_income, p.monthly_obligations, '
        . 'p.credit_limit, p.credit_used, p.on_time_pct, p.history_months, p.recent_inquiries, p.updated_at, '
        . '(SELECT h.score FROM ib_credit_score_history h WHERE h.client_id = p.client_id '
        . 'ORDER BY h.created_at DESC, h.history_id DESC LIMIT 1) AS latest_score '
        . 'FROM ib_credit_profiles p JOIN ib_clients c ON c.client_id = p.client_id ' . $filter
        . ' ORDER BY p.updated_at DESC, p.client_id DESC LIMIT ? OFFSET ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Could not prepare credit profile query.');
    }
    $stmt->bind_param('ssssii', $search, $pattern, $pattern, $pattern, $pageSize, $offset);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not load credit profiles.');
    }
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $profiles[] = $row;
    }
    $stmt->close();

    $historyFilter = "WHERE (? = '' OR c.name LIKE ? OR c.email LIKE ? OR c.client_number LIKE ?)";
    $stmt = $mysqli->prepare('SELECT COUNT(*) FROM ib_credit_score_history h JOIN ib_clients c ON c.client_id = h.client_id ' . $historyFilter);
    if (!$stmt) {
        throw new RuntimeException('Could not prepare credit score history count.');
    }
    $stmt->bind_param('ssss', $search, $pattern, $pattern, $pattern);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not count credit score history.');
    }
    $stmt->bind_result($historyCount);
    $stmt->fetch();
    $stmt->close();

    $stmt = $mysqli->prepare(
        'SELECT h.history_id, h.score, h.created_at, c.name, c.client_number, c.email '
        . 'FROM ib_credit_score_history h JOIN ib_clients c ON c.client_id = h.client_id '
        . $historyFilter . ' ORDER BY h.created_at DESC, h.history_id DESC LIMIT ? OFFSET ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Could not prepare credit score history query.');
    }
    $stmt->bind_param('ssssii', $search, $pattern, $pattern, $pattern, $pageSize, $historyOffset);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not load credit score history.');
    }
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $scoreHistory[] = $row;
    }
    $stmt->close();
} catch (Throwable $e) {
    $error = 'Could not load customer-reported credit profiles. Check the credit-simulation database migrations.';
    $profiles = array();
    $scoreHistory = array();
    error_log('[RuralBanking] ' . $portal . ' credit profiles query failed: ' . $e->getMessage());
}

function rb_credit_profiles_page_url($page, $historyPage)
{
    $query = array(
        'q' => isset($_GET['q']) && is_scalar($_GET['q']) ? (string) $_GET['q'] : '',
        'page' => (int) $page,
        'history_page' => (int) $historyPage,
    );
    return 'pages_credit_profiles.php?' . http_build_query($query);
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
              <h1>Credit Profile Monitoring</h1>
              <p class="text-muted mb-0">Review customer-reported credit-health estimates. These are not verified lender records or issued credit cards. <a href="pages_card_requests.php">Card records</a> · <a href="../service_information.php">Service Information</a></p>
            </div>
          </div>
        </div>
      </section>
      <section class="content">
        <div class="container-fluid">
          <?php if ($error !== '') { ?><div class="alert alert-warning" role="alert"><?php echo rb_e($error); ?></div><?php } ?>
          <div class="card">
            <div class="card-header">
              <h3 class="card-title">Customer-reported profiles</h3>
              <div class="card-tools">
                <form method="get" class="form-inline">
                  <input class="form-control form-control-sm mr-2" type="search" name="q" maxlength="100" value="<?php echo rb_e($search); ?>" placeholder="Customer, email, or number">
                  <button class="btn btn-sm btn-primary" type="submit">Search</button>
                </form>
              </div>
            </div>
            <div class="card-body table-responsive p-0">
              <table class="table table-hover mb-0">
                <thead><tr><th>Customer</th><th>Estimated score</th><th>Monthly income</th><th>Monthly obligations</th><th>Credit use (self-reported)</th><th>Repayment factors</th><th>Updated</th></tr></thead>
                <tbody>
                  <?php foreach ($profiles as $profile) {
                    $limit = (float) $profile['credit_limit'];
                    $used = (float) $profile['credit_used'];
                    $utilization = $limit > 0 ? min(100, max(0, $used / $limit * 100)) : null;
                  ?>
                    <tr>
                      <td><?php echo rb_e($profile['name']); ?><br><small><?php echo rb_e($profile['client_number'] . ' · ' . $profile['email']); ?></small></td>
                      <td><strong><?php echo $profile['latest_score'] === null ? 'Not recorded' : rb_e((int) $profile['latest_score']); ?></strong><br><small class="text-muted">Simulation estimate</small></td>
                      <td><?php echo rb_e(rb_money($profile['monthly_income'])); ?></td>
                      <td><?php echo rb_e(rb_money($profile['monthly_obligations'])); ?></td>
                      <td><?php echo rb_e(rb_money($used)); ?> / <?php echo rb_e(rb_money($limit)); ?><br><small><?php echo $utilization === null ? 'No limit set' : rb_e(number_format($utilization, 1) . '% utilized'); ?></small></td>
                      <td><?php echo (int) $profile['on_time_pct']; ?>% on time<br><small><?php echo (int) $profile['history_months']; ?> months · <?php echo (int) $profile['recent_inquiries']; ?> recent inquiries</small></td>
                      <td><?php echo rb_e(date('d M Y H:i', strtotime($profile['updated_at']))); ?></td>
                    </tr>
                  <?php } ?>
                  <?php if (!$profiles) { ?><tr><td colspan="7" class="text-center text-muted py-4">No matching profiles.</td></tr><?php } ?>
                </tbody>
              </table>
            </div>
            <?php
              $profilePages = max(1, (int) ceil((int) $profileCount / $pageSize));
              $profileFirst = $profileCount > 0 ? $offset + 1 : 0;
              $profileLast = min($offset + $pageSize, (int) $profileCount);
            ?>
            <div class="card-footer d-flex justify-content-between align-items-center">
              <small class="text-muted">Showing <?php echo (int) $profileFirst; ?>–<?php echo (int) $profileLast; ?> of <?php echo (int) $profileCount; ?> profiles</small>
              <div>
                <?php if ($page > 1) { ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo rb_e(rb_credit_profiles_page_url($page - 1, $historyPage)); ?>">Previous</a><?php } ?>
                <span class="small mx-2">Page <?php echo (int) $page; ?> of <?php echo (int) $profilePages; ?></span>
                <?php if ($page < $profilePages) { ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo rb_e(rb_credit_profiles_page_url($page + 1, $historyPage)); ?>">Next</a><?php } ?>
              </div>
            </div>
          </div>

          <div class="card">
            <div class="card-header"><h3 class="card-title">Credit score estimate history</h3></div>
            <div class="card-body table-responsive p-0">
              <table class="table table-hover mb-0">
                <thead><tr><th>Date</th><th>Customer</th><th>Estimated score</th><th>Record</th></tr></thead>
                <tbody>
                  <?php foreach ($scoreHistory as $history) { ?>
                    <tr>
                      <td><?php echo rb_e(date('d M Y H:i', strtotime($history['created_at']))); ?></td>
                      <td><?php echo rb_e($history['name']); ?><br><small><?php echo rb_e($history['client_number'] . ' · ' . $history['email']); ?></small></td>
                      <td><?php echo rb_e((int) $history['score']); ?></td>
                      <td>#<?php echo (int) $history['history_id']; ?></td>
                    </tr>
                  <?php } ?>
                  <?php if (!$scoreHistory) { ?><tr><td colspan="4" class="text-center text-muted py-4">No matching score history.</td></tr><?php } ?>
                </tbody>
              </table>
            </div>
            <?php
              $historyPages = max(1, (int) ceil((int) $historyCount / $pageSize));
              $historyFirst = $historyCount > 0 ? $historyOffset + 1 : 0;
              $historyLast = min($historyOffset + $pageSize, (int) $historyCount);
            ?>
            <div class="card-footer d-flex justify-content-between align-items-center">
              <small class="text-muted">Showing <?php echo (int) $historyFirst; ?>–<?php echo (int) $historyLast; ?> of <?php echo (int) $historyCount; ?> score records</small>
              <div>
                <?php if ($historyPage > 1) { ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo rb_e(rb_credit_profiles_page_url($page, $historyPage - 1)); ?>">Previous</a><?php } ?>
                <span class="small mx-2">Page <?php echo (int) $historyPage; ?> of <?php echo (int) $historyPages; ?></span>
                <?php if ($historyPage < $historyPages) { ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo rb_e(rb_credit_profiles_page_url($page, $historyPage + 1)); ?>">Next</a><?php } ?>
              </div>
            </div>
          </div>
          <p class="small text-muted">Credit profile and score inputs are customer-entered simulation data; they are not verified by a credit bureau and do not create or represent a real credit-card facility.</p>
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
