<?php
include('conf/pdoconfig.php');
if (!rb_auth_session_is_valid('staff')) {
    rb_auth_session_destroy();
    http_response_code(401);
    exit;
}
if (!empty($_SESSION['staff_id'])) {
    $ajax_permissions = array(
        'iBankAccountType' => 'manage_accounts',
        'iBankAccNumber' => 'manage_accounts',
        'iBankAccHolder' => 'process_transfers',
    );
    try {
        $mysqli = rb_mysqli();
        foreach ($ajax_permissions as $parameter => $permission_key) {
            if (empty($_POST[$parameter])) { continue; }
            $staff_id = (int) $_SESSION['staff_id'];
            $stmt = $mysqli->prepare('SELECT is_allowed FROM ib_user_permissions WHERE staff_id = ? AND permission_key = ? LIMIT 1');
            if (!$stmt) { continue; }
            $stmt->bind_param('is', $staff_id, $permission_key);
            $stmt->execute();
            $stmt->bind_result($allowed);
            $found = $stmt->fetch();
            $stmt->close();
            if ($found && (int) $allowed !== 1) {
                http_response_code(403);
                exit;
            }
        }
    } catch (Throwable $e) {
        error_log('[RuralBanking] staff AJAX permissions unavailable: ' . $e->getMessage());
    }
}
if (!empty($_POST["iBankAccountType"])) {
    //get bank account rate
    $id = $_POST['iBankAccountType'];
    $stmt = $DB_con->prepare("SELECT * FROM ib_acc_types WHERE  name = :id");
    $stmt->execute(array(':id' => $id));
?>
<?php
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
?>
<?php echo htmlentities($row['rate']); ?>
<?php
    }
}

if (!empty($_POST["iBankAccNumber"])) {
    //get  back account transferables name
    $id = $_POST['iBankAccNumber'];
    $stmt = $DB_con->prepare("SELECT * FROM ib_bankaccounts WHERE  account_number= :id");
    $stmt->execute(array(':id' => $id));
?>
<?php
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
?>
<?php echo htmlentities($row['acc_name']); ?>
<?php
    }
}

if (!empty($_POST["iBankAccHolder"])) {
    //get  back account transferables name
    $id = $_POST['iBankAccHolder'];
    $stmt = $DB_con->prepare("SELECT * FROM ib_bankaccounts WHERE  account_number= :id");
    $stmt->execute(array(':id' => $id));
?>
<?php
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
?>
<?php echo htmlentities($row['client_name']); ?>
<?php
    }
}

?>


