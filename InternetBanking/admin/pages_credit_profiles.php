<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();

$search = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$error = '';
$profiles = array();
if (strlen($search) > 100) {
    $error = 'Search text must be 100 characters or fewer.';
    $search = '';
}

try {
    $pattern = '%' . $search . '%';
    $stmt = $mysqli->prepare("SELECT c.client_id, c.name, c.client_number, c.email,
                                     p.monthly_income, p.monthly_obligations, p.credit_limit, p.credit_used,
                                     p.on_time_pct, p.history_months, p.recent_inquiries, p.updated_at,
                                     (SELECT h.score FROM ib_credit_score_history h
                                      WHERE h.client_id = p.client_id
                                      ORDER BY h.created_at DESC, h.history_id DESC LIMIT 1) AS latest_score
                              FROM ib_credit_profiles p
                              JOIN ib_clients c ON c.client_id = p.client_id
                              WHERE (? = '' OR c.name LIKE ? OR c.email LIKE ? OR c.client_number LIKE ?)
                              ORDER BY p.updated_at DESC LIMIT 300");
    if (!$stmt) {
        throw new RuntimeException('Could not prepare credit profile query: ' . $mysqli->error);
    }
    $stmt->bind_param('ssss', $search, $pattern, $pattern, $pattern);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not load credit profiles: ' . $stmt->error);
    }
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $profiles[] = $row;
    }
    $stmt->close();
} catch (Throwable $e) {
    $error = 'Could not load credit profiles. Check the database migrations.';
    error_log('[RuralBanking] admin credit profiles query failed: ' . $e->getMessage());
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
              <h1>Credit Profile Monitoring</h1>
              <p class="text-muted mb-0">Review customer-reported profiles and score estimates. <a href="../service_information.php">Service Information</a></p>
            </div>
          </div>
        </div>
      </section>
      <section class="content">
        <div class="container-fluid">
          <?php if ($error !== '') { ?><div class="alert alert-warning" role="alert"><?php echo rb_e($error); ?></div><?php } ?>
          <div class="card">
            <div class="card-header">
              <h3 class="card-title">Credit profile review</h3>
              <div class="card-tools">
                <form method="get" class="form-inline">
                  <input class="form-control form-control-sm mr-2" type="search" name="q" maxlength="100" value="<?php echo rb_e($search); ?>" placeholder="Customer, email, or number">
                  <button class="btn btn-sm btn-primary" type="submit">Search</button>
                </form>
              </div>
            </div>
            <div class="card-body table-responsive p-0">
              <table class="table table-hover mb-0">
                <thead><tr><th>Customer</th><th>Estimated score</th><th>Income</th><th>Obligations</th><th>Credit use</th><th>Repayment</th><th>Updated</th></tr></thead>
                <tbody>
                  <?php foreach ($profiles as $profile) {
                    $limit = (float) $profile['credit_limit'];
                    $used = (float) $profile['credit_used'];
                    $utilization = $limit > 0 ? min(100, max(0, $used / $limit * 100)) : null;
                  ?>
                    <tr>
                      <td><?php echo rb_e($profile['name']); ?><br><small><?php echo rb_e($profile['client_number'] . ' · ' . $profile['email']); ?></small></td>
                      <td><strong><?php echo $profile['latest_score'] === null ? 'Not recorded' : rb_e((int) $profile['latest_score']); ?></strong></td>
                      <td><?php echo rb_e(rb_money($profile['monthly_income'])); ?></td>
                      <td><?php echo rb_e(rb_money($profile['monthly_obligations'])); ?></td>
                      <td>
                        <?php echo rb_e(rb_money($used)); ?> / <?php echo rb_e(rb_money($limit)); ?>
                        <br><small><?php echo $utilization === null ? 'No limit set' : rb_e(number_format($utilization, 1) . '% utilized'); ?></small>
                      </td>
                      <td><?php echo (int) $profile['on_time_pct']; ?>% on time<br><small><?php echo (int) $profile['history_months']; ?> months · <?php echo (int) $profile['recent_inquiries']; ?> recent inquiries</small></td>
                      <td><?php echo rb_e(date('d M Y', strtotime($profile['updated_at']))); ?></td>
                    </tr>
                  <?php } ?>
                  <?php if (!$profiles) { ?><tr><td colspan="7" class="text-center text-muted py-4">No matching profiles.</td></tr><?php } ?>
                </tbody>
              </table>
            </div>
          </div>
          <p class="small text-muted">Showing up to 300 customer profiles.</p>
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
