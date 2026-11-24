<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();
$me = 'Admin #' . (int) $_SESSION['admin_id'];

if (isset($_POST['set_status'], $_POST['alert_id']) && in_array($_POST['set_status'], array('Reviewed', 'Dismissed'), true)) {
    $aid = (int) $_POST['alert_id'];
    $st  = $_POST['set_status'];
    $stmt = $mysqli->prepare("UPDATE ib_fraud_alerts SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE alert_id = ? AND status = 'Open'");
    $stmt->bind_param('ssi', $st, $me, $aid);
    $stmt->execute();
    $stmt->close();
    header('Location: pages_fraud_alerts.php');
    exit;
}

$filter = (isset($_GET['show']) && $_GET['show'] === 'all') ? 'all' : 'open';
$sql = "SELECT a.alert_id, a.rule_code, a.severity, a.details, a.status, a.reviewed_by, a.created_at,
               t.tr_code, t.tr_type, t.transaction_amt, t.client_name, t.account_number
        FROM ib_fraud_alerts a LEFT JOIN ib_transactions t ON t.tr_id = a.tr_id "
     . ($filter === 'open' ? "WHERE a.status = 'Open' " : '')
     . "ORDER BY (a.status = 'Open') DESC, a.alert_id DESC LIMIT 300";
$alerts = array();
try {
    $r = $mysqli->query($sql);
    while ($r && ($row = $r->fetch_assoc())) { $alerts[] = $row; }
} catch (Throwable $e) {
    $setup_error = true;
}
$rules = rb_fraud_rules();
$sevClass = array('High' => 'badge-danger', 'Medium' => 'badge-warning', 'Low' => 'badge-secondary');
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
            <div class="col-sm-6"><h1 class="rb-static">Fraud Alerts</h1></div>
            <div class="col-sm-6">
              <ol class="breadcrumb float-sm-right">
                <li class="breadcrumb-item"><a href="pages_dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active">Fraud Alerts</li>
              </ol>
            </div>
          </div>
        </div>
      </section>
      <section class="content">
        <div class="container-fluid">
          <div class="alert alert-info"><i class="fas fa-info-circle mr-1"></i>
            Alerts are raised by simple, explainable rules (large amounts, unusual amounts for the customer, rapid activity, balance drains, new receivers, odd hours).
            They are <b>hints for review, not proof of fraud</b>, and transactions are never blocked automatically.</div>
          <?php if (!empty($setup_error)) { ?>
            <div class="alert alert-warning">The alerts table is missing. Re-import <code>database/schema.sql</code>.</div>
          <?php } ?>
          <div class="card">
            <div class="card-header">
              <h3 class="card-title"><?php echo $filter === 'open' ? 'Open alerts' : 'All alerts'; ?></h3>
              <div class="card-tools">
                <a class="btn btn-sm btn-outline-success" href="pages_fraud_alerts.php?show=<?php echo $filter === 'open' ? 'all' : 'open'; ?>">
                  <?php echo $filter === 'open' ? 'Show all' : 'Show open only'; ?></a>
              </div>
            </div>
            <div class="card-body table-responsive p-0">
              <table class="table table-hover mb-0">
                <thead><tr><th>Severity</th><th>Reason</th><th>Details</th><th>Transaction</th><th>Customer</th><th>When</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($alerts as $a) { ?>
                  <tr>
                    <td><span class="badge <?php echo isset($sevClass[$a['severity']]) ? $sevClass[$a['severity']] : 'badge-secondary'; ?>"><?php echo rb_e($a['severity']); ?></span></td>
                    <td><b><?php echo rb_e(isset($rules[$a['rule_code']]) ? $rules[$a['rule_code']] : $a['rule_code']); ?></b></td>
                    <td style="min-width:260px"><?php echo rb_e($a['details']); ?></td>
                    <td><code><?php echo rb_e($a['tr_code']); ?></code><br><small><?php echo rb_e($a['tr_type']); ?> &middot; <?php echo rb_e(rb_money($a['transaction_amt'])); ?></small></td>
                    <td><?php echo rb_e($a['client_name']); ?><br><small><?php echo rb_e($a['account_number']); ?></small></td>
                    <td><small><?php echo rb_e($a['created_at']); ?></small></td>
                    <td>
                      <?php if ($a['status'] === 'Open') { ?>
                        <form method="post" class="form-inline">
                          <input type="hidden" name="alert_id" value="<?php echo (int) $a['alert_id']; ?>">
                          <button class="btn btn-sm btn-success mr-1" name="set_status" value="Reviewed">Reviewed</button>
                          <button class="btn btn-sm btn-outline-primary" name="set_status" value="Dismissed">Dismiss</button>
                        </form>
                      <?php } else { ?>
                        <?php echo rb_e($a['status']); ?><br><small><?php echo rb_e($a['reviewed_by']); ?></small>
                      <?php } ?>
                    </td>
                  </tr>
                <?php } ?>
                <?php if (!$alerts) { ?>
                  <tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-shield-alt fa-lg mb-2 d-block"></i>No alerts to review.</td></tr>
                <?php } ?>
                </tbody>
              </table>
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
