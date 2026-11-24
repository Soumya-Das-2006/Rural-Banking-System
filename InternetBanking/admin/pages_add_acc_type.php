<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();
$admin_id = $_SESSION['admin_id'];
if (isset($_POST['create_acc_type'])) {
    $name = isset($_POST['name']) && is_scalar($_POST['name']) ? trim((string) $_POST['name']) : '';
    $description = isset($_POST['description']) && is_scalar($_POST['description']) ? trim((string) $_POST['description']) : '';
    $rate = isset($_POST['rate']) && is_scalar($_POST['rate']) ? trim((string) $_POST['rate']) : '';
    $code = isset($_POST['code']) && is_scalar($_POST['code']) ? trim((string) $_POST['code']) : '';
    $problem = rb_account_type_problem($name, $description, $rate, $code);
    if ($problem !== null) {
        $err = $problem;
    } else {
        $stmt = $mysqli->prepare("INSERT INTO ib_acc_types (name, description, rate, code) VALUES (?,?,?,?)");
        if ($stmt) {
            $stmt->bind_param('ssss', $name, $description, $rate, $code);
            if ($stmt->execute()) {
                $success = 'Account category created.';
            } else {
                $err = 'Could not create the account category.';
                error_log('[RuralBanking] account type create failed: ' . $stmt->error);
            }
            $stmt->close();
        } else {
            $err = 'Could not prepare the account category.';
        }
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
                            <h1>Create Account Categories</h1>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-right">
                                <li class="breadcrumb-item"><a href="pages_dashboard.php">Dashboard</a></li>
                                <li class="breadcrumb-item"><a href="pages_add_acc_type.php">Rural Banking</a></li>
                                <li class="breadcrumb-item active">Add</li>
                            </ol>
                        </div>
                    </div>
                </div><!-- /.container-fluid -->
            </section>

            <!-- Main content -->
            <section class="content">
                <div class="container-fluid">
                    <div class="row">
                        <!-- left column -->
                        <div class="col-md-12">
                            <!-- general form elements -->
                            <div class="card card-purple">
                                <div class="card-header">
                                    <h3 class="card-title">Fill All Fields</h3>
                                </div>
                                <!-- form start -->
                                <form method="post" enctype="multipart/form-data" role="form">
                                    <div class="card-body">
                                        <div class="row">
                                            <div class=" col-md-4 form-group">
                                                <label for="exampleInputEmail1">Account Category Name</label>
                                                <input type="text" name="name" maxlength="200" required class="form-control" id="exampleInputEmail1">
                                            </div>
                                            <div class=" col-md-4 form-group">
                                                <label for="exampleInputEmail1">Account Category Rates % Per Year </label>
                                                <input type="number" name="rate" min="0" max="100" step="0.0001" required class="form-control" id="exampleInputEmail1">
                                            </div>
                                            <div class=" col-md-4 form-group">
                                                <label for="exampleInputPassword1">Account Category Code</label>
                                                <?php
                                                //PHP function to generate random passenger number
                                                $length = 5;
                                                $_Number =  substr(str_shuffle('0123456789QWERTYUIOPLKJHGFDSAZXCVBNM'), 1, $length);
                                                ?>
                                                <input type="text" readonly name="code" value="ACC-CAT-<?php echo $_Number; ?>" class="form-control" id="exampleInputPassword1">
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class=" col-md-12 form-group">
                                                <label for="desc">Account category description</label>
                                                <textarea name="description" required maxlength="2000" rows="5" class="form-control" id="desc"></textarea>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- /.card-body -->
                                    <div class="card-footer">
                                        <button type="submit" name="create_acc_type" class="btn btn-success">Add Account Type</button>
                                    </div>
                                </form>
                            </div>
                            <!-- /.card -->
                        </div><!-- /.container-fluid -->
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
    <!-- bs-custom-file-input -->
    <script src="plugins/bs-custom-file-input/bs-custom-file-input.min.js"></script>
    <!-- AdminLTE App -->
    <script src="dist/js/adminlte.min.js"></script>
    <!-- AdminLTE for demo purposes -->
    <script src="dist/js/demo.js"></script>
    <script type="text/javascript">
        $(document).ready(function() {
            bsCustomFileInput.init();
        });
    </script>
</body>

</html>