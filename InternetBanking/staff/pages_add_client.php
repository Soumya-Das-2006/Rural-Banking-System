<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();
$staff_id = $_SESSION['staff_id'];
$err = '';
$success = '';
$client_number = 'RB-CLIENT-' . random_int(100000, 999999);
//register new account
if (isset($_POST['create_staff_account'])) {
    $name = trim(isset($_POST['name']) ? (string) $_POST['name'] : '');
    $national_id = trim(isset($_POST['national_id']) ? (string) $_POST['national_id'] : '');
    $phone = trim(isset($_POST['phone']) ? (string) $_POST['phone'] : '');
    $email = trim(isset($_POST['email']) ? (string) $_POST['email'] : '');
    $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
    $address = trim(isset($_POST['address']) ? (string) $_POST['address'] : '');
    $problem = rb_client_details_problem($name, $national_id, $phone, $email, $address);

    if ($problem !== null) {
        $err = $problem;
    } elseif (($problem = rb_password_problem($password)) !== null) {
        $err = $problem;
    } else {
        $password = rb_hash_password($password);
        $profile_pic = rb_save_upload('profile_pic', 'dist/img/');
        $query = "INSERT INTO ib_clients (name, national_id, client_number, phone, email, password, address, profile_pic) VALUES (?,?,?,?,?,?,?,?)";
        $stmt = $mysqli->prepare($query);
        if ($stmt && $stmt->bind_param('ssssssss', $name, $national_id, $client_number, $phone, $email, $password, $address, $profile_pic) && $stmt->execute()) {
            $success = 'Client account created.';
        } else {
            $err = 'Please try again later.';
            error_log('[RuralBanking] Staff client creation failed: ' . $mysqli->error);
        }
        if ($stmt) {
            $stmt->close();
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
                            <h1>Create Client Account</h1>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-right">
                                <li class="breadcrumb-item"><a href="pages_dashboard.php">Dashboard</a></li>
                                <li class="breadcrumb-item"><a href="pages_add_client.php">Rural Banking Clients</a></li>
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
                                        <?php if ($err !== ''): ?>
                                            <div class="alert alert-danger" role="alert"><?php echo rb_e($err); ?></div>
                                        <?php elseif ($success !== ''): ?>
                                            <div class="alert alert-success" role="status"><?php echo rb_e($success); ?></div>
                                        <?php endif; ?>
                                        <div class="row">
                                            <div class=" col-md-6 form-group">
                                                <label for="exampleInputEmail1">Client Name</label>
                                                <input type="text" name="name" maxlength="100" required class="form-control" id="exampleInputEmail1">
                                            </div>
                                            <div class=" col-md-6 form-group">
                                                <label for="exampleInputPassword1">Client Number</label>
                                                <input type="text" readonly name="client_number" value="<?php echo rb_e($client_number); ?>" class="form-control" id="exampleInputPassword1">
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class=" col-md-6 form-group">
                                                <label for="exampleInputEmail1">Client Phone Number</label>
                                                <input type="tel" name="phone" maxlength="17" required class="form-control" id="exampleInputEmail1">
                                            </div>
                                            <div class=" col-md-6 form-group">
                                                <label for="exampleInputPassword1">Client Aadhaar No.</label>
                                                <input type="text" name="national_id" inputmode="numeric" minlength="12" maxlength="12" pattern="[2-9][0-9]{11}" title="Enter a valid 12-digit Aadhaar number" autocomplete="off" required class="form-control" id="exampleInputEmail1">
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class=" col-md-6 form-group">
                                                <label for="exampleInputEmail1">Client Email</label>
                                                <input type="email" name="email" maxlength="190" required class="form-control" id="exampleInputEmail1">
                                            </div>
                                            <div class=" col-md-6 form-group">
                                                <label for="exampleInputPassword1">Client Password</label>
                                                <input type="password" name="password" minlength="8" required class="form-control" autocomplete="new-password" id="exampleInputEmail1">
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class=" col-md-6 form-group">
                                                <label for="exampleInputEmail1">Client Address</label>
                                                <input type="text" name="address" maxlength="250" required class="form-control" id="exampleInputEmail1">
                                            </div>

                                            <div class="col-md-6 form-group">
                                                <label for="exampleInputFile">Client Profile Picture</label>
                                                <div class="input-group">
                                                    <div class="custom-file">
                                                        <input type="file" name="profile_pic" class="custom-file-input" id="exampleInputFile">
                                                        <label class="custom-file-label" for="exampleInputFile">Choose file</label>
                                                    </div>
                                                    <div class="input-group-append">
                                                        <span class="input-group-text" id="">Upload</span>
                                                    </div>
                                                </div>
                                            </div>

                                        </div>
                                    </div>
                                    <!-- /.card-body -->
                                    <div class="card-footer">
                                        <button type="submit" name="create_staff_account" class="btn btn-success">Add Client</button>
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