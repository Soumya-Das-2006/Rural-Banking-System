<?php
/* Persisit System Settings On Brand */
$ret = "SELECT * FROM `ib_systemsettings` ";
$stmt = $mysqli->prepare($ret);
$stmt->execute(); //ok
$res = $stmt->get_result();
while ($sys = $res->fetch_object()) {
?>

    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <title><?php echo rb_e($sys->sys_name); ?> - <?php echo rb_e($sys->sys_tagline); ?></title>
        <!-- Tell the browser to be responsive to screen width -->
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <!-- overlayScrollbars -->
        <link rel="stylesheet" href="plugins/overlayScrollbars/css/OverlayScrollbars.min.css">
        <!-- Font Awesome -->
        <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
        <!-- icheck bootstrap -->
        <link rel="stylesheet" href="plugins/icheck-bootstrap/icheck-bootstrap.min.css">
        <!-- Theme style -->
        <link rel="stylesheet" href="dist/css/adminlte.min.css">
        <!-- Fonts -->
        <!--Data tables css-->
        <link rel="stylesheet" href="plugins/datatables-bs4/css/dataTables.bootstrap4.css">
        <!--load swal js -->
        <script src="dist/js/swal.js"></script>
        <!-- Data Tables CSS -->
        <link rel="stylesheet" type="text/css" href="plugins/datatable/custom_dt_html5.css">

        <!-- Favicon -->
        <link rel="icon" type="image/png" sizes="96x96" href="../assets/img/bank-of-soumya-favicon.png">
        <!-- Rural Banking theme -->
        <link rel="stylesheet" href="../assets/css/rb-theme.css?v=20261124-2">
        <meta name="author" content="Soumya Das">
        <meta name="robots" content="noindex,nofollow">

        <script src="../assets/js/rb-i18n.js" defer></script>
        <script src="../assets/js/rb-assistant.js" defer></script>

        <!--Inject SWAL-->
        <?php if (isset($success) && rb_has_flash_message($success)) { ?>
            <!--This code for injecting success alert-->
            <script>
                setTimeout(function() {
                        swal("Success", <?php echo json_encode((string) $success, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>, "success");
                    },
                    100);
            </script>

        <?php } ?>

        <?php if (isset($err) && rb_has_flash_message($err)) { ?>
            <!--This code for injecting error alert-->
            <script>
                setTimeout(function() {
                        swal("Failed", <?php echo json_encode((string) $err, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>, "error");
                    },
                    100);
            </script>

        <?php } ?>
        <?php if (isset($info) && rb_has_flash_message($info)) { ?>
            <!--This code for injecting info alert-->
            <script>
                setTimeout(function() {
                        swal("Success", <?php echo json_encode((string) $info, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>, "warning");
                    },
                    100);
            </script>

        <?php } ?>
        <script>
            function getiBankAccs(val)

            {
                $.ajax({
                    //get account rates
                    type: "POST",
                    url: "pages_ajax.php",
                    data: 'iBankAccountType=' + val,
                    success: function(data) {
                        //alert(data);
                        $('#AccountRates').val(data);
                    }
                });

                $.ajax({
                    //get account transferable name
                    type: "POST",
                    url: "pages_ajax.php",
                    data: 'iBankAccNumber=' + val,
                    success: function(data) {
                        //alert(data);
                        $('#ReceivingAcc').val(data);
                    }
                });

                $.ajax({
                    //get account transferable holder | owner
                    type: "POST",
                    url: "pages_ajax.php",
                    data: 'iBankAccHolder=' + val,
                    success: function(data) {
                        //alert(data);
                        $('#AccountHolder').val(data);
                    }
                });
            }
        </script>

    </head>
<?php
} ?>