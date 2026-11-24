<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();
$admin_id = $_SESSION['admin_id'];

if (isset($_POST['Clear_Notifications'])) {
  $id = filter_var($_POST['Clear_Notifications'], FILTER_VALIDATE_INT);
  if (!$id) {
    $err = 'Choose a valid notification.';
  } else {
    $stmt = $mysqli->prepare('DELETE FROM ib_notifications WHERE notification_id = ?');
    $stmt->bind_param('i', $id);
    if ($stmt->execute() && $stmt->affected_rows === 1) {
      $info = 'Notification cleared.';
    } else {
      $err = 'This notification was already cleared or could not be removed.';
    }
    $stmt->close();
  }
}
/*
    get all dashboard analytics 
    and numeric values from distinct 
    tables
    */

//return total number of ibank clients
$result = "SELECT count(*) FROM ib_clients";
$stmt = $mysqli->prepare($result);
$stmt->execute();
$stmt->bind_result($iBClients);
$stmt->fetch();
$stmt->close();

//return total number of Rural Bank Staffs
$result = "SELECT count(*) FROM ib_staff";
$stmt = $mysqli->prepare($result);
$stmt->execute();
$stmt->bind_result($iBStaffs);
$stmt->fetch();
$stmt->close();

//return total number of Rural Bank Account Types
$result = "SELECT count(*) FROM ib_acc_types";
$stmt = $mysqli->prepare($result);
$stmt->execute();
$stmt->bind_result($iB_AccsType);
$stmt->fetch();
$stmt->close();

//return total number of Rural Bank Accounts
$result = "SELECT count(*) FROM ib_bankaccounts";
$stmt = $mysqli->prepare($result);
$stmt->execute();
$stmt->bind_result($iB_Accs);
$stmt->fetch();
$stmt->close();

//return total number of Rural Bank Deposits
$result = "SELECT SUM(transaction_amt) FROM ib_transactions WHERE  tr_type = 'Deposit' ";
$stmt = $mysqli->prepare($result);
$stmt->execute();
$stmt->bind_result($iB_deposits);
$stmt->fetch();
$stmt->close();

//return total number of Rural Bank Withdrawals
$result = "SELECT SUM(transaction_amt) FROM ib_transactions WHERE  tr_type = 'Withdrawal' ";
$stmt = $mysqli->prepare($result);
$stmt->execute();
$stmt->bind_result($iB_withdrawal);
$stmt->fetch();
$stmt->close();



//return total number of Rural Bank Transfers
$result = "SELECT SUM(transaction_amt) FROM ib_transactions WHERE  tr_type = 'Transfer' ";
$stmt = $mysqli->prepare($result);
$stmt->execute();
$stmt->bind_result($iB_Transfers);
$stmt->fetch();
$stmt->close();

//return total number of  Rural Bank initial cash->balances
$result = "SELECT SUM(transaction_amt) FROM ib_transactions ";
$stmt = $mysqli->prepare($result);
$stmt->execute();
$stmt->bind_result($acc_amt);
$stmt->fetch();
$stmt->close();
//Get the remaining money in the accounts
$iB_deposits = (float) $iB_deposits;
$iB_withdrawal = (float) $iB_withdrawal;
$iB_Transfers = (float) $iB_Transfers;
// Money inside the bank = deposits - withdrawals (transfers only move money between accounts)
$TotalBalInAccount = $iB_deposits - $iB_withdrawal;


//ibank money in the wallet
$result = "SELECT SUM(transaction_amt) FROM ib_transactions ";
$stmt = $mysqli->prepare($result);
$stmt->execute();
$stmt->bind_result($new_amt);
$stmt->fetch();
$stmt->close();
//Withdrawal Computations
$advancedStatsReady = true;
$advancedStats = array(
  'pending_loans' => 0,
  'pending_cards' => 0,
  'pending_cheques' => 0,
  'open_tickets' => 0,
  'pending_payments' => 0,
  'pending_agents' => 0,
);
try {
  $stmt = $mysqli->prepare("SELECT
                              (SELECT COUNT(*) FROM ib_loans WHERE status = 'pending'),
                              (SELECT COUNT(*) FROM ib_demo_cards WHERE card_status = 'requested'),
                              (SELECT COUNT(*) FROM ib_cheque_requests WHERE status = 'pending'),
                              (SELECT COUNT(*) FROM ib_support_tickets WHERE status IN ('open','in_progress')),
                              (SELECT COUNT(*) FROM ib_payment_requests WHERE status = 'pending'),
                              (SELECT COUNT(*) FROM ib_agent_profiles WHERE status = 'pending')");
  $stmt->execute();
  $stmt->bind_result(
    $advancedStats['pending_loans'],
    $advancedStats['pending_cards'],
    $advancedStats['pending_cheques'],
    $advancedStats['open_tickets'],
    $advancedStats['pending_payments'],
    $advancedStats['pending_agents']
  );
  $stmt->fetch();
  $stmt->close();
} catch (Throwable $e) {
  $advancedStatsReady = false;
}

$trendLabels = array();
$trendDeposits = array();
$trendWithdrawals = array();
$trendTransfers = array();
$trendPeriods = array();
for ($monthOffset = 5; $monthOffset >= 0; $monthOffset--) {
  $period = date('Y-m', strtotime(date('Y-m-01') . ' -' . $monthOffset . ' months'));
  $trendPeriods[$period] = count($trendPeriods);
  $trendLabels[] = date('M Y', strtotime($period . '-01'));
  $trendDeposits[] = 0;
  $trendWithdrawals[] = 0;
  $trendTransfers[] = 0;
}
$trendStart = date('Y-m-01', strtotime(date('Y-m-01') . ' -5 months'));
$stmt = $mysqli->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m'),
                                 SUM(CASE WHEN tr_type = 'Deposit' THEN transaction_amt ELSE 0 END),
                                 SUM(CASE WHEN tr_type = 'Withdrawal' THEN transaction_amt ELSE 0 END),
                                 SUM(CASE WHEN tr_type = 'Transfer' THEN transaction_amt ELSE 0 END)
                          FROM ib_transactions WHERE created_at >= ?
                          GROUP BY DATE_FORMAT(created_at, '%Y-%m')");
$stmt->bind_param('s', $trendStart);
$stmt->execute();
$stmt->bind_result($trendPeriod, $trendDeposit, $trendWithdrawal, $trendTransfer);
while ($stmt->fetch()) {
  if (isset($trendPeriods[$trendPeriod])) {
    $trendIndex = $trendPeriods[$trendPeriod];
    $trendDeposits[$trendIndex] = (float) $trendDeposit;
    $trendWithdrawals[$trendIndex] = (float) $trendWithdrawal;
    $trendTransfers[$trendIndex] = (float) $trendTransfer;
  }
}
$stmt->close();

?>

<!DOCTYPE html>
<html lang="en">
<meta http-equiv="content-type" content="text/html;charset=utf-8" />
<?php include("dist/_partials/head.php"); ?>

<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed rb-admin-dashboard">

  <div class="wrapper">
    <!-- Navbar -->
    <?php include("dist/_partials/nav.php"); ?>
    <!-- /.navbar -->

    <!-- Main Sidebar Container -->
    <?php include("dist/_partials/sidebar.php"); ?>

    <!-- Content Wrapper. Contains page content -->
    <div class="content-wrapper">
      <!-- Content Header (Page header) -->
      <div class="content-header rb-dashboard-page-header">
        <div class="container-fluid">
          <div class="row mb-2">
            <div class="col-sm-7">
              <h1 class="m-0 text-dark rb-static">Admin Dashboard</h1>
              <p class="rb-dashboard-subtitle">A clear view of customer accounts, service requests and recorded activity.</p>
            </div><!-- /.col -->
            <div class="col-sm-5">
              <ol class="breadcrumb float-sm-right">
                <li class="breadcrumb-item"><a href="#">Home</a></li>
                <li class="breadcrumb-item active">Dashboard</li>
              </ol>
            </div><!-- /.col -->
          </div><!-- /.row -->
          <div class="d-flex flex-wrap mb-3 rb-dashboard-actions">
            <a class="btn btn-success mr-2 mb-2" href="pages_financial_reports.php"><i class="fas fa-chart-line mr-1"></i>Financial analytics</a>
            <a class="btn btn-outline-success mr-2 mb-2" href="pages_operations.php"><i class="fas fa-tasks mr-1"></i>Operations queue</a>
            <a class="btn btn-outline-success mr-2 mb-2" href="pages_fraud_alerts.php"><i class="fas fa-shield-alt mr-1"></i>Review risk alerts</a>
            <a class="btn btn-outline-success mr-2 mb-2" href="pages_customer_insights.php"><i class="fas fa-user-shield mr-1"></i>Security &amp; insights</a>
          </div>
        </div><!-- /.container-fluid -->
      </div>
      <!-- /.content-header -->

      <!-- Main content -->
      <section class="content">
        <div class="container-fluid">
          <!-- Info boxes -->
          <div class="row rb-dashboard-metrics">

            <!-- ./ Rural Bank Clients -->
            <div class="col-12 col-sm-6 col-md-3">
              <div class="info-box">
                <span class="info-box-icon bg-info elevation-1"><i class="fas fa-users"></i></span>
                <div class="info-box-content">
                  <span class="info-box-text">Clients</span>
                  <span class="info-box-number">
                    <?php echo $iBClients; ?>
                  </span>
                </div>
              </div>
            </div>
            <!-- ./ Rural Bank Clients -->

            <!--iBank Staffs-->
            <div class="col-12 col-sm-6 col-md-3">
              <div class="info-box mb-3">
                <span class="info-box-icon bg-danger elevation-1"><i class="fas fa-user-tie"></i></span>

                <div class="info-box-content">
                  <span class="info-box-text">Staffs</span>
                  <span class="info-box-number">
                    <?php echo $iBStaffs; ?>
                  </span>
                </div>
              </div>
            </div>
            <!-- ./ Rural Bank Staffs-->

            <!-- fix for small devices only -->
            <div class="clearfix hidden-md-up"></div>

            <!--Ibank Acc types-->
            <div class="col-12 col-sm-6 col-md-3">
              <div class="info-box mb-3">
                <span class="info-box-icon bg-success elevation-1"><i class="fas fa-briefcase"></i></span>
                <div class="info-box-content">
                  <span class="info-box-text">Account Types</span>
                  <span class="info-box-number"><?php echo $iB_AccsType; ?></span>
                </div>
              </div>
            </div>
            <!-- /.Rural Bank Acc types -->

            <!--iBank Accounts-->
            <div class="col-12 col-sm-6 col-md-3">
              <div class="info-box mb-3">
                <span class="info-box-icon bg-purple elevation-1"><i class="fas fa-users"></i></span>
                <div class="info-box-content">
                  <span class="info-box-text">Accounts</span>
                  <span class="info-box-number"><?php echo $iB_Accs; ?></span>
                </div>
              </div>
            </div>
            <!-- Rural Bank Acc-->
          </div>

          <?php if ($advancedStatsReady) { ?>
          <div class="row rb-dashboard-queues">
            <?php
            $queueCards = array(
              array('Pending loans', $advancedStats['pending_loans'], 'pages_financial_products.php', 'fa-hand-holding-usd', 'bg-info'),
              array('Card requests', $advancedStats['pending_cards'], 'pages_card_requests.php', 'fa-credit-card', 'bg-primary'),
              array('Cheque requests', $advancedStats['pending_cheques'], 'pages_operations.php', 'fa-book', 'bg-warning'),
              array('Open support tickets', $advancedStats['open_tickets'], 'pages_operations.php', 'fa-life-ring', 'bg-danger'),
              array('Payment requests', $advancedStats['pending_payments'], 'pages_qr_monitor.php', 'fa-qrcode', 'bg-success'),
              array('Agents awaiting review', $advancedStats['pending_agents'], 'pages_operations.php', 'fa-user-tie', 'bg-info'),
            );
            foreach ($queueCards as $queueCard) {
            ?>
              <div class="col-12 col-sm-6 col-lg-4">
                <a href="<?php echo rb_e($queueCard[2]); ?>" class="text-reset rb-dashboard-queue-link">
                  <div class="info-box">
                    <span class="info-box-icon <?php echo rb_e($queueCard[4]); ?>"><i class="fas <?php echo rb_e($queueCard[3]); ?>"></i></span>
                    <div class="info-box-content"><span class="info-box-text"><?php echo rb_e($queueCard[0]); ?></span><span class="info-box-number"><?php echo (int) $queueCard[1]; ?></span></div>
                  </div>
                </a>
              </div>
            <?php } ?>
          </div>
          <?php } else { ?>
            <div class="alert alert-warning">Advanced queue metrics are unavailable. Import the QR/notification and advanced-features migrations to enable them.</div>
          <?php } ?>

          <div class="row rb-dashboard-metrics rb-dashboard-financial-metrics">
            <!--iBank Deposits -->
            <div class="col-12 col-sm-6 col-md-3">
              <div class="info-box">
                <span class="info-box-icon bg-info elevation-1"><i class="fas fa-upload"></i></span>
                <div class="info-box-content">
                  <span class="info-box-text">Deposits</span>
                  <span class="info-box-number">
                    ₹ <?php echo $iB_deposits; ?>
                  </span>
                </div>
              </div>
            </div>
            <!----./ Rural Bank Deposits-->

            <!--iBank Withdrawals-->
            <div class="col-12 col-sm-6 col-md-3">
              <div class="info-box mb-3">
                <span class="info-box-icon bg-danger elevation-1"><i class="fas fa-download"></i></span>

                <div class="info-box-content">
                  <span class="info-box-text">Withdrawals</span>
                  <span class="info-box-number">₹ <?php echo $iB_withdrawal; ?> </span>
                </div>
              </div>
            </div>
            <!-- Withdrawals-->

            <!-- fix for small devices only -->
            <div class="clearfix hidden-md-up"></div>

            <!--Transfers-->
            <div class="col-12 col-sm-6 col-md-3">
              <div class="info-box mb-3">
                <span class="info-box-icon bg-success elevation-1"><i class="fas fa-random"></i></span>
                <div class="info-box-content">
                  <span class="info-box-text">Transfers</span>
                  <span class="info-box-number">₹ <?php echo $iB_Transfers; ?></span>
                </div>
              </div>
            </div>
            <!-- /.Transfers-->

            <!--Balances-->
            <div class="col-12 col-sm-6 col-md-3">
              <div class="info-box mb-3">
                <span class="info-box-icon bg-purple elevation-1"><i class="fas fa-money-bill-alt"></i></span>
                <div class="info-box-content">
                  <span class="info-box-text">Wallet Balance</span>
                  <span class="info-box-number">₹ <?php echo $TotalBalInAccount; ?></span>
                </div>
              </div>
            </div>
            <!-- ./Balances-->
          </div>

          <div class="row">
            <div class="col-md-12">
              <div class="card rb-dashboard-analytics">
                <div class="card-header">
                  <div>
                    <h5 class="card-title">Activity &amp; account analytics</h5>
                    <p class="rb-dashboard-card-subtitle">Recorded account mix and transaction activity over the last six months.</p>
                  </div>
                  <div class="card-tools">
                    <button type="button" class="btn btn-tool" data-card-widget="collapse">
                      <i class="fas fa-minus"></i>
                    </button>
                    <button type="button" class="btn btn-tool" data-card-widget="remove">
                      <i class="fas fa-times"></i>
                    </button>
                  </div>
                </div>
                <!-- /.card-header -->
                <div class="card-body">
                  <div class="row">
                    <div class="col-md-6">
                      <div class="chart rb-dashboard-chart-frame">
                        <!-- Transaction Donought chart Canvas -->
                        <div id="PieChart" class="rb-dashboard-chart"></div>
                      </div>
                      <!-- /.chart-responsive -->
                    </div>
                    <div class="col-md-6">
                      <div class="chart rb-dashboard-chart-frame">
                        <div id="AccountsPerAccountCategories" class="rb-dashboard-chart"></div>
                      </div>
                      <!-- /.chart-responsive -->
                    </div>

                    <!-- /.col -->
                    <div class="col-12 mt-3">
                      <div class="rb-dashboard-trend"><canvas id="rbTransactionTrend" aria-label="Monthly deposits, withdrawals, and transfers for the last six months" role="img"></canvas></div>
                    </div>
                  </div>
                  <!-- /.row -->
                </div>
                <!-- ./card-body -->
                <div class="card-footer">
                  <div class="row">
                    <div class="col-sm-3 col-6">
                      <div class="description-block border-right">
                        <h5 class="description-header">₹ <?php echo $iB_deposits; ?></h5>
                        <span class="description-text">TOTAL DEPOSITS</span>
                      </div>
                      <!-- /.description-block -->
                    </div>
                    <!-- /.col -->
                    <div class="col-sm-3 col-6">
                      <div class="description-block border-right">
                        <h5 class="description-header">₹ <?php echo $iB_withdrawal; ?></h5>
                        <span class="description-text">TOTAL WITHDRAWALS</span>
                      </div>
                      <!-- /.description-block -->
                    </div>
                    <!-- /.col -->
                    <div class="col-sm-3 col-6">
                      <div class="description-block border-right">
                        <h5 class="description-header">₹ <?php echo $iB_Transfers; ?> </h5>
                        <span class="description-text">TOTAL TRANSFERS</span>
                      </div>
                      <!-- /.description-block -->
                    </div>
                    <!-- /.col -->
                    <div class="col-sm-3 col-6">
                      <div class="description-block">
                        <h5 class="description-header">₹ <?php echo $TotalBalInAccount; ?> </h5>
                        <span class="description-text">TOTAL MONEY IN Account</span>
                      </div>
                      <!-- /.description-block -->
                    </div>
                  </div>
                  <!-- /.row -->
                </div>
                <!-- /.card-footer -->
              </div>
              <!-- /.card -->
            </div>
            <!-- /.col -->
          </div>
          <!-- /.row -->

          <!-- Main row -->
          <div class="row">
            <!-- Left col -->
            <div class="col-md-12">
              <!-- TABLE: Transactions -->
              <div class="card">
                <div class="card-header border-transparent">
                  <h3 class="card-title">Latest Transactions</h3>

                  <div class="card-tools">
                    <button type="button" class="btn btn-tool" data-card-widget="collapse">
                      <i class="fas fa-minus"></i>
                    </button>
                    <button type="button" class="btn btn-tool" data-card-widget="remove">
                      <i class="fas fa-times"></i>
                    </button>
                  </div>
                </div>
                <!-- /.card-header -->
                <div class="card-body p-0">
                  <div class="table-responsive">
                    <table class="table table-hover table-bordered table-striped m-0">
                      <thead>
                        <tr>
                          <th>Transaction Code</th>
                          <th>Account No.</th>
                          <th>Type</th>
                          <th>Amount</th>
                          <th>Acc. Owner</th>
                          <th>Timestamp</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php
                        //Get latest transactions 
                        $ret = "SELECT * FROM `ib_transactions` ORDER BY `ib_transactions`.`created_at` DESC ";
                        $stmt = $mysqli->prepare($ret);
                        $stmt->execute(); //ok
                        $res = $stmt->get_result();
                        $cnt = 1;
                        if ($res->num_rows === 0) {
                        ?>
                          <tr>
                            <td colspan="6">
                              <div class="rb-dashboard-table-empty">
                                <i class="fas fa-receipt" aria-hidden="true"></i>
                                <strong>No transaction records yet</strong>
                                <span>Recorded activity will appear here when available.</span>
                              </div>
                            </td>
                          </tr>
                        <?php
                        }
                        while ($row = $res->fetch_object()) {
                          /* Trim Transaction Timestamp to 
                            *  User Uderstandable Formart  DD-MM-YYYY :
                            */
                          $transTstamp = $row->created_at;
                          //Perfom some lil magic here
                          if ($row->tr_type == 'Deposit') {
                            $alertClass = "<span class='badge badge-success'>$row->tr_type</span>";
                          } elseif ($row->tr_type == 'Withdrawal') {
                            $alertClass = "<span class='badge badge-danger'>$row->tr_type</span>";
                          } else {
                            $alertClass = "<span class='badge badge-warning'>$row->tr_type</span>";
                          }
                        ?>
                          <tr>
                            <td><?php echo rb_e($row->tr_code); ?></a></td>
                            <td><?php echo rb_e($row->account_number); ?></td>
                            <td><?php echo $alertClass; ?></td>
                            <td>₹ <?php echo rb_e($row->transaction_amt); ?></td>
                            <td><?php echo rb_e($row->client_name); ?></td>
                            <td><?php echo date("d-M-Y h:m:s ", strtotime($transTstamp)); ?></td>
                          </tr>

                        <?php } ?>

                      </tbody>
                    </table>
                  </div>
                  <!-- /.table-responsive -->
                </div>
                <!-- /.card-body -->
                <div class="card-footer clearfix">
                  <a href="pages_transactions_engine.php" class="btn btn-sm btn-info float-left">View All</a>
                </div>
                <!-- /.card-footer -->
              </div>
              <!-- /.card -->
            </div>
            <!-- /.col -->
          </div>
          <!-- /.row -->
        </div>
        <!--/. container-fluid -->
      </section>
      <!-- /.content -->
    </div>
    <!-- /.content-wrapper -->

    <!-- Control Sidebar -->
    <aside class="control-sidebar control-sidebar-dark">
      <!-- Control sidebar content goes here -->
    </aside>
    <!-- /.control-sidebar -->

    <!-- Main Footer -->
    <?php include("dist/_partials/footer.php"); ?>

  </div>
  <!-- ./wrapper -->

  <!-- REQUIRED SCRIPTS -->
  <!-- jQuery -->
  <script src="plugins/jquery/jquery.min.js"></script>
  <!-- Bootstrap -->
  <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
  <!-- overlayScrollbars -->
  <script src="plugins/overlayScrollbars/js/jquery.overlayScrollbars.min.js"></script>
  <!-- AdminLTE App -->
  <script src="dist/js/adminlte.js"></script>

  <!-- OPTIONAL SCRIPTS -->
  <script src="dist/js/demo.js"></script>

  <!-- ChartJS -->
  <script src="plugins/chart.js/Chart.min.js"></script>

  <script>
    window.onload = function() {

      var Piechart = {
        title: "Accounts by account type",
        options: {
          data: [{
          dataPoints: [{
              y: <?php
                  //return total number of accounts opened under savings acc type
                  $result = "SELECT count(*) FROM ib_bankaccounts WHERE  acc_type ='Savings' ";
                  $stmt = $mysqli->prepare($result);
                  $stmt->execute();
                  $stmt->bind_result($savings);
                  $stmt->fetch();
                  $stmt->close();
                  echo $savings;
                  ?>,
              name: "Savings",
              exploded: true
            },

            {
              y: <?php
                  //return total number of accounts opened under  Retirement  acc type
                  $result = "SELECT count(*) FROM ib_bankaccounts WHERE  acc_type ='Retirement' ";
                  $stmt = $mysqli->prepare($result);
                  $stmt->execute();
                  $stmt->bind_result($Retirement);
                  $stmt->fetch();
                  $stmt->close();
                  echo $Retirement;
                  ?>,
              name: "Retirement",
              exploded: true
            },

            {
              y: <?php
                  //return total number of accounts opened under  Recurring deposit  acc type
                  $result = "SELECT count(*) FROM ib_bankaccounts WHERE  acc_type ='Recurring deposit' ";
                  $stmt = $mysqli->prepare($result);
                  $stmt->execute();
                  $stmt->bind_result($Recurring);
                  $stmt->fetch();
                  $stmt->close();
                  echo $Recurring;
                  ?>,
              name: "Recurring deposit",
              exploded: true
            },

            {
              y: <?php
                  //return total number of accounts opened under  Fixed Deposit Account deposit  acc type
                  $result = "SELECT count(*) FROM ib_bankaccounts WHERE  acc_type ='Fixed Deposit Account' ";
                  $stmt = $mysqli->prepare($result);
                  $stmt->execute();
                  $stmt->bind_result($Fixed);
                  $stmt->fetch();
                  $stmt->close();
                  echo $Fixed;
                  ?>,
              name: "Fixed deposit",
              exploded: true
            },

            {
              y: <?php
                  //return total number of accounts opened under  Current account deposit  acc type
                  $result = "SELECT count(*) FROM ib_bankaccounts WHERE  acc_type ='Current account' ";
                  $stmt = $mysqli->prepare($result);
                  $stmt->execute();
                  $stmt->bind_result($Current);
                  $stmt->fetch();
                  $stmt->close();
                  echo $Current;
                  ?>,
              name: "Current account",
              exploded: true
            }
          ]
          }]
        }
      };

      var AccChart = {
        title: "Transactions by type",
        options: {
          data: [{
          dataPoints: [{
              y: <?php
                  //return total number of transactions under  Withdrawals
                  $result = "SELECT count(*) FROM ib_transactions WHERE  tr_type ='Withdrawal' ";
                  $stmt = $mysqli->prepare($result);
                  $stmt->execute();
                  $stmt->bind_result($Withdrawals);
                  $stmt->fetch();
                  $stmt->close();
                  echo $Withdrawals;
                  ?>,
              name: "Withdrawals",
              exploded: true
            },

            {
              y: <?php
                  //return total number of transactions under  Deposits
                  $result = "SELECT count(*) FROM ib_transactions WHERE  tr_type ='Deposit' ";
                  $stmt = $mysqli->prepare($result);
                  $stmt->execute();
                  $stmt->bind_result($Deposits);
                  $stmt->fetch();
                  $stmt->close();
                  echo $Deposits;
                  ?>,
              name: "Deposits",
              exploded: true
            },

            {
              y: <?php
                  //return total number of transactions under  Deposits
                  $result = "SELECT count(*) FROM ib_transactions WHERE  tr_type ='Transfer' ";
                  $stmt = $mysqli->prepare($result);
                  $stmt->execute();
                  $stmt->bind_result($Transfers);
                  $stmt->fetch();
                  $stmt->close();
                  echo $Transfers;
                  ?>,
              name: "Transfers",
              exploded: true
            }

          ]
          }]
        }
      };

      function renderDashboardChart(chart, id, emptyMessage) {
        var points = chart.options.data[0].dataPoints;
        var hasData = points.some(function(point) {
          return Number(point.y) > 0;
        });
        var container = document.getElementById(id);
        if (!hasData) {
          container.innerHTML = '<div class="rb-chart-empty" role="status"><i class="fas fa-chart-pie" aria-hidden="true"></i><strong>' +
            emptyMessage + '</strong><span>Activity will appear here when you have records.</span></div>';
          return;
        }
        var canvas = document.createElement('canvas');
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', chart.title);
        container.appendChild(canvas);
        new Chart(canvas.getContext('2d'), {
          type: 'doughnut',
          data: {
            labels: points.map(function(point) { return point.name; }),
            datasets: [{
              data: points.map(function(point) { return Number(point.y); }),
              backgroundColor: ['#1f6f4f', '#f2c25b', '#2a7f8a', '#8e5bb7', '#d2574b'],
              borderColor: '#ffffff',
              borderWidth: 3
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            cutoutPercentage: 62,
            legend: {
              position: 'bottom',
              labels: { usePointStyle: true, padding: 18 }
            },
            title: {
              display: true,
              text: chart.title,
              fontFamily: 'Fraunces',
              fontSize: 18,
              fontColor: '#0b3b2c'
            },
            tooltips: {
              callbacks: {
                label: function(item, data) {
                  var value = Number(data.datasets[item.datasetIndex].data[item.index]) || 0;
                  var total = data.datasets[item.datasetIndex].data.reduce(function(sum, current) {
                    return sum + Number(current || 0);
                  }, 0);
                  var percent = total ? Math.round(value / total * 100) : 0;
                  return data.labels[item.index] + ': ' + value + ' (' + percent + '%)';
                }
              }
            }
          }
        });
      }

      renderDashboardChart(Piechart, "PieChart", "No accounts yet");
      renderDashboardChart(AccChart, "AccountsPerAccountCategories", "No transactions yet");

      var trendCanvas = document.getElementById('rbTransactionTrend');
      if (trendCanvas) {
        new Chart(trendCanvas.getContext('2d'), {
          type: 'line',
          data: {
            labels: <?php echo json_encode($trendLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
            datasets: [
              { label: 'Deposits', data: <?php echo json_encode($trendDeposits); ?>, borderColor: '#1f6f4f', backgroundColor: 'rgba(31,111,79,.12)', fill: true, lineTension: .25 },
              { label: 'Withdrawals', data: <?php echo json_encode($trendWithdrawals); ?>, borderColor: '#d2574b', backgroundColor: 'rgba(210,87,75,.08)', fill: false, lineTension: .25 },
              { label: 'Transfers', data: <?php echo json_encode($trendTransfers); ?>, borderColor: '#d99a22', backgroundColor: 'rgba(217,154,34,.08)', fill: false, lineTension: .25 }
            ]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { yAxes: [{ ticks: { beginAtZero: true } }] }
          }
        });
      }
    }

  </script>

</body>

</html>