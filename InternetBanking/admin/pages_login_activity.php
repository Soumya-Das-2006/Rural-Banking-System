<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();

$role_filter = isset($_GET['role']) ? (string) $_GET['role'] : '';
$event_filter = isset($_GET['event']) ? (string) $_GET['event'] : '';
$search = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$page = filter_var(isset($_GET['page']) ? $_GET['page'] : 1, FILTER_VALIDATE_INT);
$page = $page && $page > 0 ? $page : 1;
$page_size = 50;
$total_rows = 0;
$total_pages = 1;
$rows = array();
$error = '';

if (!in_array($role_filter, array('', 'admin', 'staff', 'client'), true)) {
    $role_filter = '';
    $error = 'Choose a valid user role filter.';
}
if (!in_array($event_filter, array('', 'login_success', 'login_failed', 'login_blocked', 'page_request', 'account_registration', 'logout'), true)) {
    $event_filter = '';
    $error = 'Choose a valid activity type filter.';
}
if (strlen($search) > 100) {
    $search = '';
    $error = 'Search text must be 100 characters or fewer.';
}

$from = " FROM ib_user_activity e
          LEFT JOIN ib_clients c ON e.actor_role = 'client' AND e.actor_id = c.client_id
          LEFT JOIN ib_staff s ON e.actor_role = 'staff' AND e.actor_id = s.staff_id
          LEFT JOIN ib_admin a ON e.actor_role = 'admin' AND e.actor_id = a.admin_id";
$where = array('e.created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 180 DAY)');
$types = '';
$params = array();
if ($role_filter !== '') {
    $where[] = 'e.actor_role = ?';
    $types .= 's';
    $params[] = $role_filter;
}
if ($event_filter !== '') {
    $where[] = 'e.event_type = ?';
    $types .= 's';
    $params[] = $event_filter;
}
if ($search !== '') {
    $pattern = '%' . $search . '%';
    $where[] = "(COALESCE(CASE e.actor_role WHEN 'client' THEN c.name WHEN 'staff' THEN s.name WHEN 'admin' THEN a.name END, '') LIKE ?
                  OR COALESCE(CASE e.actor_role WHEN 'client' THEN c.email WHEN 'staff' THEN s.email WHEN 'admin' THEN a.email END, '') LIKE ?
                  OR e.ip_address LIKE ? OR e.page LIKE ? OR e.location LIKE ? OR e.user_agent LIKE ?)";
    for ($i = 0; $i < 6; $i++) {
        $types .= 's';
        $params[] = $pattern;
    }
}
$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

try {
    $count = $mysqli->prepare('SELECT COUNT(*)' . $from . $where_sql);
    if (!$count) {
        throw new RuntimeException('Could not prepare activity count.');
    }
    if ($types !== '') {
        $bind = array($types);
        foreach ($params as $key => $value) {
            $bind[] = &$params[$key];
        }
        call_user_func_array(array($count, 'bind_param'), $bind);
    }
    if (!$count->execute()) {
        throw new RuntimeException('Could not read the activity count.');
    }
    $count->bind_result($total_rows);
    $count->fetch();
    $count->close();

    $total_pages = max(1, (int) ceil((int) $total_rows / $page_size));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $page_size;
    $sql = "SELECT e.event_id, e.actor_role, e.actor_id, e.event_type, e.page, e.request_method,
                   e.ip_address, e.location, e.user_agent, e.created_at,
                   COALESCE(CASE e.actor_role WHEN 'client' THEN c.name WHEN 'staff' THEN s.name WHEN 'admin' THEN a.name END, '') AS actor_name,
                   COALESCE(CASE e.actor_role WHEN 'client' THEN c.email WHEN 'staff' THEN s.email WHEN 'admin' THEN a.email END, '') AS actor_email"
        . $from . $where_sql . ' ORDER BY e.created_at DESC, e.event_id DESC LIMIT ? OFFSET ?';
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Could not prepare activity list.');
    }
    $list_types = $types . 'ii';
    $list_params = $params;
    $list_params[] = $page_size;
    $list_params[] = $offset;
    $bind = array($list_types);
    foreach ($list_params as $key => $value) {
        $bind[] = &$list_params[$key];
    }
    call_user_func_array(array($stmt, 'bind_param'), $bind);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not read user activity.');
    }
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
} catch (Throwable $e) {
    $error = 'Could not load login and activity records. Import the latest additive database migration and try again.';
    error_log('[RuralBanking] admin user activity page failed: ' . $e->getMessage());
}

$query = array('role' => $role_filter, 'event' => $event_filter, 'q' => $search);
$first_row = $total_rows > 0 ? (($page - 1) * $page_size) + 1 : 0;
$last_row = min((int) $total_rows, $page * $page_size);
$event_labels = array(
    'login_success' => 'Sign-in successful',
    'login_failed' => 'Sign-in failed',
    'login_blocked' => 'Sign-in blocked',
    'page_request' => 'Authenticated request',
    'account_registration' => 'Account registration',
    'logout' => 'Signed out',
);
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
        <h1>Login &amp; User Activity</h1>
        <p class="text-muted mb-0">Review sign-ins and authenticated page requests across customer, staff, and admin portals.</p>
      </div></section>
      <section class="content"><div class="container-fluid">
        <?php if ($error !== '') { ?><div class="alert alert-danger" role="alert"><?php echo rb_e($error); ?></div><?php } ?>
        <div class="alert alert-info" role="note">Activity records include IP addresses and browser details for security review. Records are retained for 180 days. Location is shown only when supplied by a configured trusted proxy; otherwise it is marked unavailable. <a href="pages_operations.php">Review administrative changes in the Audit Log.</a></div>
        <div class="card">
          <div class="card-body">
            <form method="get" class="form-row align-items-end">
              <div class="form-group col-md-4"><label for="activity-search">Search user, email, IP, page, location, or browser</label><input id="activity-search" class="form-control" type="search" name="q" maxlength="100" value="<?php echo rb_e($search); ?>"></div>
              <div class="form-group col-md-3"><label for="activity-role">Role</label><select id="activity-role" class="form-control" name="role"><option value="">All roles</option><?php foreach (array('client' => 'Customer', 'staff' => 'Staff', 'admin' => 'Administrator') as $value => $label) { ?><option value="<?php echo rb_e($value); ?>" <?php echo $role_filter === $value ? 'selected' : ''; ?>><?php echo rb_e($label); ?></option><?php } ?></select></div>
              <div class="form-group col-md-3"><label for="activity-event">Activity</label><select id="activity-event" class="form-control" name="event"><option value="">All activity</option><?php foreach ($event_labels as $value => $label) { ?><option value="<?php echo rb_e($value); ?>" <?php echo $event_filter === $value ? 'selected' : ''; ?>><?php echo rb_e($label); ?></option><?php } ?></select></div>
              <div class="form-group col-md-2"><button class="btn btn-primary mr-1" type="submit">Filter</button><a class="btn btn-outline-secondary" href="pages_login_activity.php">Reset</a></div>
            </form>
          </div>
          <div class="card-body table-responsive p-0">
            <table class="table table-hover table-striped mb-0">
              <thead><tr><th>Time</th><th>User</th><th>Activity</th><th>Page / method</th><th>IP address</th><th>Location</th><th>Browser</th></tr></thead>
              <tbody>
              <?php foreach ($rows as $row) {
                  $event_label = isset($event_labels[$row['event_type']]) ? $event_labels[$row['event_type']] : $row['event_type'];
                  $badge = $row['event_type'] === 'login_success' ? 'badge-success' : (strpos($row['event_type'], 'failed') !== false || strpos($row['event_type'], 'blocked') !== false ? 'badge-warning' : 'badge-secondary');
              ?>
                <tr>
                  <td><?php echo rb_e($row['created_at']); ?></td>
                  <td><?php echo $row['actor_name'] !== '' ? rb_e($row['actor_name']) : 'Unidentified principal'; ?><div class="small text-muted"><?php echo rb_e($row['actor_role']); ?><?php if ((int) $row['actor_id'] > 0) { ?> #<?php echo (int) $row['actor_id']; ?><?php } ?><?php if ($row['actor_email'] !== '') { ?> · <?php echo rb_e($row['actor_email']); ?><?php } ?></div></td>
                  <td><span class="badge <?php echo $badge; ?>"><?php echo rb_e($event_label); ?></span></td>
                  <td><?php echo rb_e($row['page']); ?><div class="small text-muted"><?php echo rb_e($row['request_method']); ?></div></td>
                  <td><?php echo rb_e($row['ip_address']); ?></td>
                  <td><?php echo rb_e($row['location']); ?></td>
                  <td title="<?php echo rb_e($row['user_agent']); ?>"><?php echo rb_e($row['user_agent'] !== '' ? $row['user_agent'] : 'Unavailable'); ?></td>
                </tr>
              <?php } ?>
              <?php if (!$rows) { ?><tr><td colspan="7" class="text-center text-muted py-4">No matching activity records.</td></tr><?php } ?>
              </tbody>
            </table>
          </div>
          <div class="card-footer d-flex justify-content-between align-items-center flex-wrap">
            <span class="small text-muted">Showing <?php echo $first_row; ?>–<?php echo $last_row; ?> of <?php echo (int) $total_rows; ?></span>
            <nav aria-label="Activity pages"><ul class="pagination pagination-sm mb-0">
              <?php for ($page_number = 1; $page_number <= $total_pages; $page_number++) {
                  $page_query = $query;
                  $page_query['page'] = $page_number;
              ?><li class="page-item <?php echo $page_number === $page ? 'active' : ''; ?>"><a class="page-link" href="pages_login_activity.php?<?php echo rb_e(http_build_query($page_query)); ?>"><?php echo $page_number; ?></a></li><?php } ?>
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
