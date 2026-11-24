<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();
$client_id = $_SESSION['client_id'];

?>

<!DOCTYPE html>
<html>
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
      <section class="content-header">
        <div class="container-fluid">
          <div class="row mb-2">
            <div class="col-sm-6">
              <h1>Transaction History</h1>
            </div>
            <div class="col-sm-6">
              <ol class="breadcrumb float-sm-right">
                <li class="breadcrumb-item"><a href="pages_dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="pages_transactions_engine.php">Transaction History</a></li>
                <li class="breadcrumb-item active">Transactions</li>
              </ol>
            </div>
          </div>
        </div><!-- /.container-fluid -->
      </section>

      <!-- Main content -->
      <section class="content">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title">Select on any action options to manage Transactions</h3>
              </div>
              <div class="card-body">
                <table id="example1" class="table table-hover table-striped">
                  <thead>
                    <tr>
                      <th>#</th>
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
                            ORDER BY t.created_at DESC";
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
                        $activity = $row->tr_type;
                        $alertClass = "<span class='badge badge-success'>$row->tr_type</span>";
                      } elseif ($row->tr_type == 'Withdrawal') {
                        $activity = $row->tr_type;
                        $alertClass = "<span class='badge badge-danger'>$row->tr_type</span>";
                      } else {
                        $activity = $row->tr_type;
                        $alertClass = "<span class='badge badge-warning'>$row->tr_type</span>";
                      }
                      $display_account = $is_incoming ? $row->receiving_acc_no : $row->account_number;
                      $counterparty = $is_incoming ? $row->client_name : ($row->tr_type === 'Transfer' ? $row->receiving_acc_holder : $row->client_name);
                      $amount_prefix = $is_incoming ? '+ ' : ($row->tr_type === 'Transfer' ? '- ' : '');
                    ?>

                      <tr>
                        <td><?php echo $cnt; ?></td>
                        <td><?php echo rb_e($row->tr_code); ?></a></td>
                        <td><?php echo rb_e($display_account); ?></td>
                        <td><?php echo $alertClass; ?></td>
                        <td><?php echo $amount_prefix; ?>₹ <?php echo rb_e($row->transaction_amt); ?></td>
                        <td><?php echo rb_e($counterparty); ?></td>
                        <td><?php echo date("d-M-Y h:m:s ", strtotime($transTstamp)); ?></td>

                      </tr>
                    <?php $cnt = $cnt + 1;
                    } ?>
                    </tfoot>
                </table>
              </div>
              <!-- /.card-body -->
            </div>
            <!-- /.card -->
          </div>
          <!-- /.col -->
        </div>
        <!-- /.row -->
      </section>
      <!-- /.content -->
    </div>
    <!-- /.content-wrapper -->
    <?php include("dist/_partials/footer.php"); ?>

    <!-- Control Sidebar -->
    <aside class="control-sidebar control-sidebar-dark">
      <!-- Control sidebar content goes here -->
    </aside>
    <!-- /.control-sidebar -->
  </div>
  <!-- ./wrapper -->

  <!-- jQuery -->
  <script src="plugins/jquery/jquery.min.js"></script>
  <!-- Bootstrap 4 -->
  <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
  <!-- DataTables -->
  <script src="plugins/datatables/jquery.dataTables.js"></script>
  <script src="plugins/datatables-bs4/js/dataTables.bootstrap4.js"></script>
  <!-- AdminLTE App -->
  <script src="dist/js/adminlte.min.js"></script>
  <!-- AdminLTE for demo purposes -->
  <script src="dist/js/demo.js"></script>
  <!-- page script -->
  <script>
    $(function() {
      $("#example1").DataTable();
      $('#example2').DataTable({
        "paging": true,
        "lengthChange": false,
        "searching": false,
        "ordering": true,
        "info": true,
        "autoWidth": false,
      });
    });
  </script>
</body>

</html>