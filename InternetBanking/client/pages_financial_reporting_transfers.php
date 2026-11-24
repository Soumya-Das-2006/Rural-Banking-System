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
              <h1>Transfer Activity</h1>
            </div>
            <div class="col-sm-6">
              <ol class="breadcrumb float-sm-right">
                <li class="breadcrumb-item"><a href="pages_dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="pages_financial_reporting_transfers.php">Advanced Reporting</a></li>
                <li class="breadcrumb-item active">Transfer Activity</li>
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
                <h4>Transfers sent and received</h4>
              </div>
              <div class="card-body">
                <table id="export" class="table table-bordered table-hover table-striped">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>Transaction Code</th>
                      <th>Direction</th>
                      <th>Account No.</th>
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
                            WHERE t.tr_type = 'Transfer'
                              AND (t.client_id = ? OR EXISTS (
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
                      $is_incoming = (int) $row->is_incoming === 1;
                      if ($is_incoming) {
                        $direction = isset($row->tr_channel) && $row->tr_channel === 'upi_qr' ? 'QR payment received' : 'Received';
                        $badgeClass = 'badge-success';
                      } else {
                        $direction = isset($row->tr_channel) && $row->tr_channel === 'upi_qr' ? 'QR payment sent' : 'Sent';
                        $badgeClass = 'badge-warning';
                      }
                      $account_number = $is_incoming ? $row->receiving_acc_no : $row->account_number;
                      $counterparty = $is_incoming ? $row->client_name : $row->receiving_acc_holder;
                      $amount_prefix = $is_incoming ? '+ ' : '- ';
                    ?>

                      <tr>
                        <td><?php echo $cnt; ?></td>
                        <td><?php echo rb_e($row->tr_code); ?></a></td>
                        <td><span class="badge <?php echo rb_e($badgeClass); ?>"><?php echo rb_e($direction); ?></span></td>
                        <td><?php echo rb_e($account_number); ?></td>
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
  <!-- Data Tables V2.01 -->
  <!-- NOTE TO Use Copy CSV Excel PDF Print Options You Must Include These Files  -->
  <script src="plugins/datatable/button-ext/dataTables.buttons.min.js"></script>
  <script src="plugins/datatable/button-ext/jszip.min.js"></script>
  <script src="plugins/datatable/button-ext/buttons.html5.min.js"></script>
  <script src="plugins/datatable/button-ext/buttons.print.min.js"></script>
  <script>
    $('#export').DataTable({
      dom: '<"row"<"col-md-12"<"row"<"col-md-6"B><"col-md-6"f> > ><"col-md-12"rt> <"col-md-12"<"row"<"col-md-5"i><"col-md-7"p>>> >',
      buttons: {
        buttons: [{
            extend: 'copy',
            className: 'btn'
          },
          {
            extend: 'csv',
            className: 'btn'
          },
          {
            extend: 'excel',
            className: 'btn'
          },
          {
            extend: 'print',
            className: 'btn'
          }
        ]
      },
      "oLanguage": {
        "oPaginate": {
          "sPrevious": '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-arrow-left"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>',
          "sNext": '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-arrow-right"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>'
        },
        "sInfo": "Showing page _PAGE_ of _PAGES_",
        "sSearch": '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-search"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>',
        "sSearchPlaceholder": "Search...",
        "sLengthMenu": "Results :  _MENU_",
      },
      "stripeClasses": [],
      "lengthMenu": [7, 10, 20, 50],
      "pageLength": 7
    });
  </script>
</body>

</html>