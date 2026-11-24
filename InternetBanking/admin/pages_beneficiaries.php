<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();

$admin_id = (int) $_SESSION['admin_id'];
$search = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$status_filter = isset($_GET['status']) ? (string) $_GET['status'] : '';
$page = filter_var(isset($_GET['page']) ? $_GET['page'] : 1, FILTER_VALIDATE_INT);
$page = $page && $page > 0 ? $page : 1;
$page_size = 25;
$error = '';
$success = isset($_SESSION['rb_admin_beneficiaries_success']) ? (string) $_SESSION['rb_admin_beneficiaries_success'] : '';
unset($_SESSION['rb_admin_beneficiaries_success']);
$rows = array();
$total_rows = 0;
$total_pages = 1;

if (strlen($search) > 100) {
    $error = 'Search text must be 100 characters or fewer.';
    $search = '';
}
if (!in_array($status_filter, array('', 'active', 'inactive'), true)) {
    $error = 'Choose a valid beneficiary status filter.';
    $status_filter = '';
}

if (isset($_POST['set_beneficiary_status'])) {
    $beneficiary_id = filter_var($_POST['set_beneficiary_status'], FILTER_VALIDATE_INT);
    $new_status = isset($_POST['beneficiary_status']) ? (string) $_POST['beneficiary_status'] : '';
    if (!$beneficiary_id || !in_array($new_status, array('active', 'inactive'), true)) {
        $error = 'Choose a valid beneficiary and status.';
    } else {
        try {
            $mysqli->begin_transaction();
            $stmt = $mysqli->prepare('SELECT status FROM ib_beneficiaries WHERE beneficiary_id = ? FOR UPDATE');
            $stmt->bind_param('i', $beneficiary_id);
            $stmt->execute();
            $stmt->bind_result($old_status);
            if (!$stmt->fetch()) {
                $stmt->close();
                throw new RuntimeException('Beneficiary record was not found.');
            }
            $stmt->close();
            if ($old_status === $new_status) {
                throw new RuntimeException('Beneficiary already has that status.');
            }

            $stmt = $mysqli->prepare('UPDATE ib_beneficiaries SET status = ? WHERE beneficiary_id = ?');
            $stmt->bind_param('si', $new_status, $beneficiary_id);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('Could not update the beneficiary status.');
            }
            $stmt->close();

            $actor_role = 'admin';
            $action = 'beneficiary_status_changed';
            $entity_type = 'beneficiary';
            $entity_id = (string) $beneficiary_id;
            $details = 'Beneficiary status changed from ' . $old_status . ' to ' . $new_status . '.';
            $stmt = $mysqli->prepare('INSERT INTO ib_audit_log (actor_role, actor_id, action, entity_type, entity_id, details)
                                      VALUES (?, ?, ?, ?, ?, ?)');
            if (!$stmt) {
                throw new RuntimeException('Could not prepare the audit record.');
            }
            $stmt->bind_param('sissss', $actor_role, $admin_id, $action, $entity_type, $entity_id, $details);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Could not record this beneficiary change.');
            }
            $stmt->close();
            $mysqli->commit();
            $_SESSION['rb_admin_beneficiaries_success'] = 'Beneficiary status updated and recorded in the admin audit log.';
            $query = array('q' => $search, 'status' => $status_filter, 'page' => $page);
            header('Location: pages_beneficiaries.php?' . http_build_query($query));
            exit;
        } catch (Throwable $e) {
            try {
                $mysqli->rollback();
            } catch (Throwable $rollback_error) {
            }
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not update the beneficiary.';
            error_log('[RuralBanking] admin beneficiary update failed: ' . $e->getMessage());
        }
    }
}

try {
    $pattern = '%' . $search . '%';
    $count = $mysqli->prepare("SELECT COUNT(*)
                               FROM ib_beneficiaries b
                               JOIN ib_clients c ON c.client_id = b.client_id
                               JOIN ib_bankaccounts a ON a.account_id = b.account_id
                               WHERE (? = '' OR b.status = ?)
                                 AND (? = '' OR c.name LIKE ? OR c.email LIKE ? OR b.name LIKE ?
                                      OR b.receiving_account_number LIKE ? OR a.account_number LIKE ?)");
    $count->bind_param('ssssssss', $status_filter, $status_filter, $search, $pattern, $pattern, $pattern, $pattern, $pattern);
    $count->execute();
    $count->bind_result($total_rows);
    $count->fetch();
    $count->close();

    $total_pages = max(1, (int) ceil((int) $total_rows / $page_size));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $page_size;
    $stmt = $mysqli->prepare("SELECT b.beneficiary_id, b.name AS beneficiary_name, b.receiving_account_number,
                                     b.status, b.created_at, c.client_id, c.name AS client_name,
                                     a.account_number AS source_account
                              FROM ib_beneficiaries b
                              JOIN ib_clients c ON c.client_id = b.client_id
                              JOIN ib_bankaccounts a ON a.account_id = b.account_id
                              WHERE (? = '' OR b.status = ?)
                                AND (? = '' OR c.name LIKE ? OR c.email LIKE ? OR b.name LIKE ?
                                     OR b.receiving_account_number LIKE ? OR a.account_number LIKE ?)
                              ORDER BY (b.status = 'active') DESC, b.created_at DESC
                              LIMIT ? OFFSET ?");
    $stmt->bind_param('ssssssssii', $status_filter, $status_filter, $search, $pattern, $pattern, $pattern, $pattern, $pattern, $page_size, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
} catch (Throwable $e) {
    $error = 'Could not load beneficiary records. Check the advanced features migration.';
    error_log('[RuralBanking] admin beneficiary list failed: ' . $e->getMessage());
}

$query = array('q' => $search, 'status' => $status_filter);
$first_row = $total_rows > 0 ? (($page - 1) * $page_size) + 1 : 0;
$last_row = min((int) $total_rows, $page * $page_size);
?>
<!DOCTYPE html>
<html lang="en">
<meta http-equiv="content-type" content="text/html;charset=utf-8" />
<?php include("dist/_partials/head.php"); ?>
<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed">
  <div class="wrapper">
    <?php include("dist/_partials/nav.php"); ?>
    <?php include("dist/_partials/sidebar.php"); ?>
    <div class="content-wrapper">
      <section class="content-header"><div class="container-fluid">
        <h1>Beneficiary Management</h1>
        <p class="text-muted mb-0">Review saved beneficiaries, search records, and suspend or restore access.</p>
      </div></section>
      <section class="content"><div class="container-fluid">
        <?php if ($error !== '') { ?><div class="alert alert-danger" role="alert"><?php echo rb_e($error); ?></div><?php } ?>
        <?php if ($success !== '') { ?><div class="alert alert-success" role="status"><?php echo rb_e($success); ?></div><?php } ?>
        <div class="card">
          <div class="card-body">
            <form method="get" class="form-row align-items-end">
              <div class="form-group col-md-6"><label for="beneficiary-search">Search customer, beneficiary, or account</label><input id="beneficiary-search" class="form-control" type="search" name="q" maxlength="100" value="<?php echo rb_e($search); ?>"></div>
              <div class="form-group col-md-3"><label for="beneficiary-status">Status</label><select id="beneficiary-status" class="form-control" name="status"><option value="">All statuses</option><option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option><option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option></select></div>
              <div class="form-group col-md-3"><button class="btn btn-primary mr-2" type="submit">Apply filters</button><a class="btn btn-outline-secondary" href="pages_beneficiaries.php">Reset</a></div>
            </form>
          </div>
          <div class="card-body table-responsive p-0">
            <table class="table table-hover table-striped mb-0">
              <thead><tr><th>Customer</th><th>Beneficiary</th><th>Receiving account</th><th>Linked source</th><th>Status</th><th>Saved</th><th>Action</th></tr></thead>
              <tbody>
              <?php foreach ($rows as $row) { ?>
                <tr>
                  <td><?php echo rb_e($row['client_name']); ?><div class="small text-muted">Customer #<?php echo (int) $row['client_id']; ?></div></td>
                  <td><?php echo rb_e($row['beneficiary_name']); ?></td>
                  <td>••••<?php echo rb_e(substr($row['receiving_account_number'], -4)); ?></td>
                  <td>••••<?php echo rb_e(substr($row['source_account'], -4)); ?></td>
                  <td><span class="badge <?php echo $row['status'] === 'active' ? 'badge-success' : 'badge-secondary'; ?>"><?php echo rb_e($row['status']); ?></span></td>
                  <td><?php echo rb_e($row['created_at']); ?></td>
                  <td><form method="post">
                    <input type="hidden" name="beneficiary_status" value="<?php echo $row['status'] === 'active' ? 'inactive' : 'active'; ?>">
                    <button class="btn btn-sm <?php echo $row['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success'; ?>" type="submit" name="set_beneficiary_status" value="<?php echo (int) $row['beneficiary_id']; ?>"><?php echo $row['status'] === 'active' ? 'Suspend' : 'Restore'; ?></button>
                  </form></td>
                </tr>
              <?php } ?>
              <?php if (!$rows) { ?><tr><td colspan="7" class="text-center text-muted py-4">No matching beneficiary records.</td></tr><?php } ?>
              </tbody>
            </table>
          </div>
          <div class="card-footer d-flex justify-content-between align-items-center flex-wrap">
            <span class="small text-muted">Showing <?php echo $first_row; ?>–<?php echo $last_row; ?> of <?php echo (int) $total_rows; ?></span>
            <nav aria-label="Beneficiary pages"><ul class="pagination pagination-sm mb-0">
              <?php for ($page_number = 1; $page_number <= $total_pages; $page_number++) {
                  $page_query = $query;
                  $page_query['page'] = $page_number;
              ?><li class="page-item <?php echo $page_number === $page ? 'active' : ''; ?>"><a class="page-link" href="pages_beneficiaries.php?<?php echo rb_e(http_build_query($page_query)); ?>"><?php echo $page_number; ?></a></li><?php } ?>
            </ul></nav>
          </div>
        </div>
      </div></section>
    </div>
    <?php include("dist/_partials/footer.php"); ?>
  </div>
  <script src="plugins/jquery/jquery.min.js"></script>
  <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="dist/js/adminlte.min.js"></script>
</body>
</html>
