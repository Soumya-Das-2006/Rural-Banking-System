<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();
$admin_id = $_SESSION['admin_id'];
$info = '';
$err = '';
if (isset($_GET['deleteBankAcc'])) {
  $id = intval($_GET['deleteBankAcc']);
  try {
    $stmt = $mysqli->prepare('DELETE FROM ib_bankaccounts WHERE account_id = ?');
    $stmt->bind_param('i', $id);
    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
      throw new RuntimeException('No account was closed.');
    }
    $stmt->close();
    $info = 'Rural Banking Account Closed';
  } catch (Throwable $e) {
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
      $stmt->close();
    }
    $err = 'This account could not be deleted because it has linked QR, payment, or other protected records. Keep the records intact and deactivate the account instead.';
    error_log('[RuralBanking] account deletion prevented or failed: ' . $e->getMessage());
  }
}
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
              <h1>Manage Rural Banking Accounts</h1>
            </div>
            <div class="col-sm-6">
              <ol class="breadcrumb float-sm-right">
                <li class="breadcrumb-item"><a href="pages_dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="pages_manage_acc_openings.php">Rural Bank Accounts</a></li>
                <li class="breadcrumb-item active">Manage Accounts</li>
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
                <h3 class="card-title">Select on any action options to manage your accounts</h3>
              </div>
              <div class="card-body">
                <?php if ($info !== '') { ?><div class="alert alert-success" role="status"><?php echo rb_e($info); ?></div><?php } ?>
                <?php if ($err !== '') { ?><div class="alert alert-warning" role="alert"><?php echo rb_e($err); ?></div><?php } ?>
                <table id="example1" class="table table-hover table-bordered table-striped">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>Name</th>
                      <th>Acc Number</th>
                      <th>Rate</th>
                      <th>Acc Type</th>
                      <th>Acc Owner</th>
                      <th>Date Opened</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                    //fetch all iB_Accs
                    $ret = "SELECT * FROM  ib_bankaccounts ORDER BY RAND() ";
                    $stmt = $mysqli->prepare($ret);
                    $stmt->execute(); //ok
                    $res = $stmt->get_result();
                    $cnt = 1;
                    while ($row = $res->fetch_object()) {
                      //Trim Timestamp to DD-MM-YYYY : H-M-S
                      $dateOpened = $row->created_at;

                    ?>

                      <tr>
                        <td><?php echo $cnt; ?></td>
                        <td><?php echo rb_e($row->acc_name); ?></td>
                        <td><?php echo rb_e($row->account_number); ?></td>
                        <td><?php echo rb_e($row->acc_rates); ?>%</td>
                        <td><?php echo rb_e($row->acc_type); ?></td>
                        <td><?php echo rb_e($row->client_name); ?></td>
                        <td><?php echo date("d-M-Y", strtotime($dateOpened)); ?></td>
                        <td>
                          <a class="btn btn-success btn-sm" href="pages_update_client_accounts.php?account_id=<?php echo rb_e($row->account_id); ?>">
                            <i class="fas fa-cogs"></i>
                            <!-- <i class="fas fa-briefcase"></i> -->
                            Manage
                          </a>

                          <a class="btn btn-danger btn-sm" href="pages_manage_acc_openings.php?deleteBankAcc=<?php echo rb_e($row->account_id); ?>">
                            <i class="fas fa-times"></i>
                            <!-- <i class="fas fa-briefcase"></i> -->
                            Close Account
                          </a>


                        </td>

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