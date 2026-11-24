<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();
$client_id = $_SESSION['client_id'];

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
$client_id = $_SESSION['client_id'];
$result = "SELECT SUM(transaction_amt) FROM ib_transactions WHERE  client_id = ? AND tr_type = 'Deposit' ";
$stmt = $mysqli->prepare($result);
$stmt->bind_param('i', $client_id);
$stmt->execute();
$stmt->bind_result($iB_deposits);
$stmt->fetch();
$stmt->close();

//return total number of Rural Bank Withdrawals
$client_id = $_SESSION['client_id'];
$result = "SELECT SUM(transaction_amt) FROM ib_transactions WHERE  client_id = ? AND tr_type = 'Withdrawal' ";
$stmt = $mysqli->prepare($result);
$stmt->bind_param('i', $client_id);
$stmt->execute();
$stmt->bind_result($iB_withdrawal);
$stmt->fetch();
$stmt->close();



//return total number of Rural Bank Transfers
$client_id = $_SESSION['client_id'];
$result = "SELECT SUM(transaction_amt) FROM ib_transactions WHERE  client_id = ? AND tr_type = 'Transfer' ";
$stmt = $mysqli->prepare($result);
$stmt->bind_param('i', $client_id);
$stmt->execute();
$stmt->bind_result($iB_Transfers);
$stmt->fetch();
$stmt->close();

//return total number of  Rural Bank initial cash->balances
$client_id = $_SESSION['client_id'];
$result = "SELECT SUM(transaction_amt) FROM ib_transactions  WHERE client_id =?";
$stmt = $mysqli->prepare($result);
$stmt->bind_param('i', $client_id);
$stmt->execute();
$stmt->bind_result($acc_amt);
$stmt->fetch();
$stmt->close();
//Get the remaining money in the accounts
$iB_deposits = (float) $iB_deposits;
$iB_withdrawal = (float) $iB_withdrawal;
$iB_Transfers = (float) $iB_Transfers;
$TotalBalInAccount = rb_client_balance($mysqli, (int) $_SESSION['client_id']);


//ibank money in the wallet
$client_id = $_SESSION['client_id'];
$result = "SELECT SUM(transaction_amt) FROM ib_transactions  WHERE client_id = ?";
$stmt = $mysqli->prepare($result);
$stmt->bind_param('i', $client_id);
$stmt->execute();
$stmt->bind_result($new_amt);
$stmt->fetch();
$stmt->close();
$new_amt = $TotalBalInAccount;
//Withdrawal Computations

?>

<!DOCTYPE html>
<html lang="en">
<meta http-equiv="content-type" content="text/html;charset=utf-8" />
<?php include("dist/_partials/head.php"); ?>

<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed">

  <div class="wrapper">
    <!-- Navbar -->
    <?php include("dist/_partials/nav.php"); ?>
    <!-- /.navbar -->

    <!-- Main Sidebar Container -->
    <?php include("dist/_partials/sidebar.php"); ?>

    <!-- Content Wrapper. Contains page content -->
    <div class="content-wrapper">
      <!-- Content Header (Page header) -->
      <div class="content-header">
        <div class="container-fluid">
          <div class="row mb-2">
            <div class="col-sm-6">
              <h1 class="m-0 text-dark rb-static">Client Dashboard</h1>
            </div><!-- /.col -->
            <div class="col-sm-6">
              <ol class="breadcrumb float-sm-right">
                <li class="breadcrumb-item"><a href="#">Home</a></li>
                <li class="breadcrumb-item active">Dashboard</li>
              </ol>
            </div><!-- /.col -->
          </div><!-- /.row -->
        </div><!-- /.container-fluid -->
      </div>
      <!-- /.content-header -->

      <!-- Main content -->
      <section class="content">
        <div class="container-fluid">
          <div class="row">
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
                  <span class="info-box-text">Transfers sent</span>
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
              <div class="card">
                <div class="card-header">
                  <h5 class="card-title">Advanced Analytics</h5>
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
                      <div class="chart">
                        <!-- Transaction Donought chart Canvas -->
                        <div id="PieChart" class="rb-dashboard-chart" style="height: 360px; max-width: 500px; margin: 0 auto;"></div>
                      </div>
                      <!-- /.chart-responsive -->
                    </div>
                    <hr>
                    <div class="col-md-6">
                      <div class="chart">
                        <div id="AccountsPerAccountCategories" class="rb-dashboard-chart" style="height: 360px; max-width: 500px; margin: 0 auto;"></div>
                      </div>
                      <!-- /.chart-responsive -->
                    </div>

                    <!-- /.col -->
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
                        <span class="description-text">TOTAL TRANSFERS SENT</span>
                      </div>
                      <!-- /.description-block -->
                    </div>
                    <!-- /.col -->
                    <div class="col-sm-3 col-6">
                      <div class="description-block">
                        <h5 class="description-header">₹ <?php echo $new_amt; ?> </h5>
                        <span class="description-text">TOTAL MONEY IN  Account</span>
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
                    <table class="table table-striped table-hover m-0">
                      <thead>
                        <tr>
                          <th>Transaction Code</th>
                          <th>Account No.</th>
                          <th>Activity</th>
                          <th>Amount</th>
                          <th>Counterparty</th>
                          <th>Timestamp</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php
                        //Include transfers addressed to any account owned by this customer.
                        $client_id = $_SESSION['client_id'];
                        $ret = "SELECT t.*,
                                       CASE WHEN t.client_id = ? THEN 0 ELSE 1 END AS is_incoming
                                FROM ib_transactions t
                                WHERE t.client_id = ?
                                   OR (t.tr_type = 'Transfer' AND EXISTS (
                                       SELECT 1 FROM ib_bankaccounts a
                                       WHERE a.client_id = ? AND a.account_number = t.receiving_acc_no
                                   ))
                                ORDER BY t.created_at DESC
                                LIMIT 10";
                        $stmt = $mysqli->prepare($ret);
                        $stmt->bind_param('iii', $client_id, $client_id, $client_id);
                        $stmt->execute(); //ok
                        $res = $stmt->get_result();
                        $cnt = 1;
                        while ($row = $res->fetch_object()) {
                          /* Trim Transaction Timestamp to 
                            *  User Uderstandable Formart  DD-MM-YYYY :
                            */
                          $transTstamp = $row->created_at;
                          //Perfom some lil magic here
                          $is_incoming = (int) $row->is_incoming === 1;
                          if ($is_incoming) {
                            $activity = isset($row->tr_channel) && $row->tr_channel === 'upi_qr' ? 'QR payment received' : 'Transfer received';
                            $alertClass = "<span class='badge badge-success'>" . rb_e($activity) . "</span>";
                          } elseif ($row->tr_type === 'Transfer') {
                            $activity = isset($row->tr_channel) && $row->tr_channel === 'upi_qr' ? 'QR payment sent' : 'Transfer sent';
                            $alertClass = "<span class='badge badge-warning'>" . rb_e($activity) . "</span>";
                          } elseif ($row->tr_type == 'Deposit') {
                            $alertClass = "<span class='badge badge-success'>$row->tr_type</span>";
                          } elseif ($row->tr_type == 'Withdrawal') {
                            $alertClass = "<span class='badge badge-danger'>$row->tr_type</span>";
                          } else {
                            $alertClass = "<span class='badge badge-warning'>$row->tr_type</span>";
                          }
                          $display_account = $is_incoming ? $row->receiving_acc_no : $row->account_number;
                          $counterparty = $is_incoming ? $row->client_name : ($row->tr_type === 'Transfer' ? $row->receiving_acc_holder : $row->client_name);
                          $amount_prefix = $is_incoming ? '+ ' : ($row->tr_type === 'Transfer' ? '- ' : '');
                        ?>
                          <tr>
                            <td><?php echo rb_e($row->tr_code); ?></a></td>
                            <td><?php echo rb_e($display_account); ?></td>
                            <td><?php echo $alertClass; ?></td>
                            <td><?php echo $amount_prefix; ?>₹ <?php echo rb_e($row->transaction_amt); ?></td>
                            <td><?php echo rb_e($counterparty); ?></td>
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
                  $client_id = $_SESSION['client_id'];
                  $result = "SELECT count(*) FROM ib_bankaccounts WHERE  acc_type ='Savings' AND client_id =? ";
                  $stmt = $mysqli->prepare($result);
                  $stmt->bind_param('i', $client_id);
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
                  $client_id  = $_SESSION['client_id'];
                  $result = "SELECT count(*) FROM ib_bankaccounts WHERE  acc_type ='Retirement' AND client_id =? ";
                  $stmt = $mysqli->prepare($result);
                  $stmt->bind_param('i', $client_id);
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
                  $client_id  = $_SESSION['client_id'];
                  $result = "SELECT count(*) FROM ib_bankaccounts WHERE  acc_type ='Recurring deposit' AND client_id =? ";
                  $stmt = $mysqli->prepare($result);
                  $stmt->bind_param('i', $client_id);
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
                  $client_id  = $_SESSION['client_id'];
                  $result = "SELECT count(*) FROM ib_bankaccounts WHERE  acc_type ='Fixed Deposit Account' AND client_id = ? ";
                  $stmt = $mysqli->prepare($result);
                  $stmt->bind_param('i', $client_id);
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
                  $client_id  = $_SESSION['client_id'];
                  $result = "SELECT count(*) FROM ib_bankaccounts WHERE  acc_type ='Current account' AND client_id =? ";
                  $stmt = $mysqli->prepare($result);
                  $stmt->bind_param('i', $client_id);
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
                  $client_id  = $_SESSION['client_id'];
                  $result = "SELECT count(*) FROM ib_transactions WHERE  tr_type ='Withdrawal' AND client_id =? ";
                  $stmt = $mysqli->prepare($result);
                  $stmt->bind_param('i', $client_id);
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
                  $client_id  = $_SESSION['client_id'];
                  $result = "SELECT count(*) FROM ib_transactions WHERE  tr_type ='Deposit' AND client_id =? ";
                  $stmt = $mysqli->prepare($result);
                  $stmt->bind_param('i', $client_id);
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
                  $client_id  = $_SESSION['client_id'];
                  $result = "SELECT count(*) FROM ib_transactions WHERE  tr_type ='Transfer' AND client_id =? ";
                  $stmt = $mysqli->prepare($result);
                  $stmt->bind_param('i', $client_id);
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
    }

  </script>

</body>

</html>