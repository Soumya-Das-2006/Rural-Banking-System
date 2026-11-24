<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();

function rb_admin_report_bind($stmt, $types, &$params)
{
    if ($types === '') {
        return true;
    }
    $arguments = array($types);
    foreach ($params as $index => &$value) {
        $arguments[] = &$value;
    }
    return call_user_func_array(array($stmt, 'bind_param'), $arguments);
}

function rb_admin_report_csv_cell($value)
{
    $value = (string) $value;
    if (preg_match('/^\s*[=+\-@]/', $value)) {
        return "'" . $value;
    }
    return $value;
}

function rb_admin_report_date($value)
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        return false;
    }
    $parts = explode('-', $value);
    return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
}

$error = '';
$from = isset($_GET['from']) ? trim((string) $_GET['from']) : '';
$to = isset($_GET['to']) ? trim((string) $_GET['to']) : '';
$type = isset($_GET['type']) ? (string) $_GET['type'] : '';
$status = isset($_GET['status']) ? (string) $_GET['status'] : '';
$channel = isset($_GET['channel']) ? (string) $_GET['channel'] : '';
$search = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$page_size = filter_var(isset($_GET['per_page']) ? $_GET['per_page'] : 25, FILTER_VALIDATE_INT);
$page = filter_var(isset($_GET['page']) ? $_GET['page'] : 1, FILTER_VALIDATE_INT);
$page_size = in_array($page_size, array(25, 50, 100), true) ? $page_size : 25;
$page = $page && $page > 0 ? $page : 1;
$export = isset($_GET['export']) ? (string) $_GET['export'] : '';

if (($from !== '' && !rb_admin_report_date($from)) || ($to !== '' && !rb_admin_report_date($to))) {
    $error = 'Enter valid calendar dates for the reporting period.';
    $from = '';
    $to = '';
}
if ($from !== '' && $to !== '' && $from > $to) {
    $error = 'The start date must be on or before the end date.';
    $from = '';
    $to = '';
}
if (!in_array($type, array('', 'Deposit', 'Withdrawal', 'Transfer'), true)
    || !in_array($status, array('', 'Success', 'Failed', 'Pending'), true)
    || !in_array($channel, array('', 'standard', 'upi_qr'), true)
    || strlen($search) > 100
    || !in_array($export, array('', 'csv'), true)) {
    $error = 'One or more report filters are invalid.';
    $type = '';
    $status = '';
    $channel = '';
    $search = '';
    $export = '';
}

$conditions = array();
$params = array();
$types = '';
if ($from !== '') {
    $conditions[] = 'created_at >= ?';
    $params[] = $from . ' 00:00:00';
    $types .= 's';
}
if ($to !== '') {
    $until = DateTime::createFromFormat('!Y-m-d', $to)->modify('+1 day')->format('Y-m-d');
    $conditions[] = 'created_at < ?';
    $params[] = $until . ' 00:00:00';
    $types .= 's';
}
if ($type !== '') {
    $conditions[] = 'tr_type = ?';
    $params[] = $type;
    $types .= 's';
}
if ($status !== '') {
    $conditions[] = 'tr_status = ?';
    $params[] = $status;
    $types .= 's';
}
if ($channel !== '') {
    $conditions[] = 'tr_channel = ?';
    $params[] = $channel;
    $types .= 's';
}
if ($search !== '') {
    $conditions[] = '(tr_code LIKE ? OR account_number LIKE ? OR client_name LIKE ? OR receiving_acc_no LIKE ?)';
    $pattern = '%' . $search . '%';
    for ($i = 0; $i < 4; $i++) {
        $params[] = $pattern;
        $types .= 's';
    }
}
$where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
$total_rows = 0;
$deposits = 0.0;
$withdrawals = 0.0;
$transfers = 0.0;
$transactions = array();
$total_pages = 1;

try {
    $summary = $mysqli->prepare("SELECT COUNT(*),
                                        COALESCE(SUM(CASE WHEN tr_type = 'Deposit' AND tr_status = 'Success' THEN transaction_amt ELSE 0 END), 0),
                                        COALESCE(SUM(CASE WHEN tr_type = 'Withdrawal' AND tr_status = 'Success' THEN transaction_amt ELSE 0 END), 0),
                                        COALESCE(SUM(CASE WHEN tr_type = 'Transfer' AND tr_status = 'Success' THEN transaction_amt ELSE 0 END), 0)
                                 FROM ib_transactions" . $where);
    if (!$summary) {
        throw new RuntimeException('Could not prepare financial report totals.');
    }
    rb_admin_report_bind($summary, $types, $params);
    $summary->execute();
    $summary->bind_result($total_rows, $deposits, $withdrawals, $transfers);
    $summary->fetch();
    $summary->close();

    if ($export === 'csv') {
        $stmt = $mysqli->prepare("SELECT tr_code, created_at, client_name, account_number, tr_type, tr_status,
                                         tr_channel, transaction_amt, receiving_acc_no, receiving_acc_holder
                                  FROM ib_transactions" . $where . ' ORDER BY created_at DESC, tr_id DESC');
        if (!$stmt) {
            throw new RuntimeException('Could not prepare the filtered report export.');
        }
        rb_admin_report_bind($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rural-banking-transactions-' . date('Ymd-His') . '.csv"');
        header('Cache-Control: private, no-store, max-age=0');
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, array('Reference', 'Created at', 'Customer', 'Source account', 'Type', 'Status', 'Channel', 'Amount', 'Recipient account', 'Recipient'));
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, array_map('rb_admin_report_csv_cell', array_values($row)));
        }
        fclose($output);
        $stmt->close();
        exit;
    }

    $total_pages = max(1, (int) ceil((int) $total_rows / $page_size));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $page_size;
    $list_params = $params;
    $list_types = $types . 'ii';
    $list_params[] = $page_size;
    $list_params[] = $offset;
    $stmt = $mysqli->prepare("SELECT tr_code, created_at, client_name, account_number, tr_type, tr_status,
                                     tr_channel, transaction_amt, receiving_acc_no, receiving_acc_holder
                              FROM ib_transactions" . $where . ' ORDER BY created_at DESC, tr_id DESC LIMIT ? OFFSET ?');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare the transaction list.');
    }
    rb_admin_report_bind($stmt, $list_types, $list_params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    $stmt->close();
} catch (Throwable $e) {
    $error = 'Could not load financial reports. Confirm the database schema and transaction-channel migration.';
    error_log('[RuralBanking] admin financial report failed: ' . $e->getMessage());
}

$filters = array(
    'from' => $from,
    'to' => $to,
    'type' => $type,
    'status' => $status,
    'channel' => $channel,
    'q' => $search,
    'per_page' => $page_size,
);
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
        <h1>Financial Analytics &amp; Reports</h1>
        <p class="text-muted mb-0">Search and filter account ledger activity. CSV downloads open in spreadsheet software; use Print and “Save as PDF” for a PDF copy. <a href="../service_information.php">Service Information</a></p>
      </div></section>
      <section class="content"><div class="container-fluid">
        <?php if ($error !== '') { ?><div class="alert alert-danger" role="alert"><?php echo rb_e($error); ?></div><?php } ?>
        <div class="row">
          <div class="col-md-4"><div class="info-box"><span class="info-box-icon bg-success"><i class="fas fa-arrow-down"></i></span><div class="info-box-content"><span class="info-box-text">Filtered deposits</span><span class="info-box-number"><?php echo rb_e(rb_money($deposits)); ?></span></div></div></div>
          <div class="col-md-4"><div class="info-box"><span class="info-box-icon bg-danger"><i class="fas fa-arrow-up"></i></span><div class="info-box-content"><span class="info-box-text">Filtered withdrawals</span><span class="info-box-number"><?php echo rb_e(rb_money($withdrawals)); ?></span></div></div></div>
          <div class="col-md-4"><div class="info-box"><span class="info-box-icon bg-info"><i class="fas fa-exchange-alt"></i></span><div class="info-box-content"><span class="info-box-text">Filtered transfers</span><span class="info-box-number"><?php echo rb_e(rb_money($transfers)); ?></span></div></div></div>
        </div>
        <div class="card rb-report-filters">
          <div class="card-body">
            <form method="get" class="form-row align-items-end">
              <div class="form-group col-lg-2 col-md-4"><label for="report-from">From</label><input id="report-from" class="form-control" type="date" name="from" value="<?php echo rb_e($from); ?>"></div>
              <div class="form-group col-lg-2 col-md-4"><label for="report-to">To</label><input id="report-to" class="form-control" type="date" name="to" value="<?php echo rb_e($to); ?>"></div>
              <div class="form-group col-lg-2 col-md-4"><label for="report-type">Type</label><select id="report-type" class="form-control" name="type"><option value="">All types</option><?php foreach (array('Deposit', 'Withdrawal', 'Transfer') as $option) { ?><option value="<?php echo rb_e($option); ?>" <?php echo $type === $option ? 'selected' : ''; ?>><?php echo rb_e($option); ?></option><?php } ?></select></div>
              <div class="form-group col-lg-2 col-md-4"><label for="report-status">Status</label><select id="report-status" class="form-control" name="status"><option value="">All statuses</option><?php foreach (array('Success', 'Failed', 'Pending') as $option) { ?><option value="<?php echo rb_e($option); ?>" <?php echo $status === $option ? 'selected' : ''; ?>><?php echo rb_e($option); ?></option><?php } ?></select></div>
              <div class="form-group col-lg-2 col-md-4"><label for="report-channel">Channel</label><select id="report-channel" class="form-control" name="channel"><option value="">All channels</option><option value="standard" <?php echo $channel === 'standard' ? 'selected' : ''; ?>>Standard</option><option value="upi_qr" <?php echo $channel === 'upi_qr' ? 'selected' : ''; ?>>QR / virtual ID</option></select></div>
              <div class="form-group col-lg-2 col-md-4"><label for="report-per-page">Rows per page</label><select id="report-per-page" class="form-control" name="per_page"><?php foreach (array(25, 50, 100) as $size) { ?><option value="<?php echo $size; ?>" <?php echo $page_size === $size ? 'selected' : ''; ?>><?php echo $size; ?></option><?php } ?></select></div>
              <div class="form-group col-lg-8"><label for="report-query">Search reference, customer, or account</label><input id="report-query" class="form-control" type="search" name="q" maxlength="100" value="<?php echo rb_e($search); ?>"></div>
              <div class="form-group col-lg-4">
                <button class="btn btn-primary mr-2" type="submit">Apply filters</button>
                <a class="btn btn-outline-secondary mr-2" href="pages_financial_reports.php">Reset</a>
                <button class="btn btn-outline-primary" type="button" onclick="window.print()">Print / Save PDF</button>
              </div>
            </form>
            <?php $export_filters = $filters; $export_filters['export'] = 'csv'; ?>
            <a class="btn btn-sm btn-success" href="pages_financial_reports.php?<?php echo rb_e(http_build_query($export_filters)); ?>"><i class="fas fa-file-csv mr-1"></i>Export all filtered rows (CSV for Excel)</a>
          </div>
        </div>
        <div class="card">
          <div class="card-header"><h3 class="card-title">Transaction report</h3><div class="card-tools"><span class="text-muted small"><?php echo (int) $total_rows; ?> matching transactions</span></div></div>
          <div class="card-body table-responsive p-0">
            <table class="table table-hover table-striped mb-0">
              <thead><tr><th>Reference</th><th>Date</th><th>Customer</th><th>Account</th><th>Type</th><th>Channel</th><th>Amount</th><th>Recipient</th><th>Status</th></tr></thead>
              <tbody><?php foreach ($transactions as $transaction) { ?>
                <tr>
                  <td><code><?php echo rb_e($transaction['tr_code']); ?></code></td>
                  <td><?php echo rb_e($transaction['created_at']); ?></td>
                  <td><?php echo rb_e($transaction['client_name']); ?></td>
                  <td>••••<?php echo rb_e(substr($transaction['account_number'], -4)); ?></td>
                  <td><?php echo rb_e($transaction['tr_type']); ?></td>
                  <td><?php echo $transaction['tr_channel'] === 'upi_qr' ? 'QR / virtual ID' : 'Standard'; ?></td>
                  <td><?php echo rb_e(rb_money($transaction['transaction_amt'])); ?></td>
                  <td><?php echo $transaction['receiving_acc_no'] !== '' ? '••••' . rb_e(substr($transaction['receiving_acc_no'], -4)) : '—'; ?></td>
                  <td><span class="badge <?php echo $transaction['tr_status'] === 'Success' ? 'badge-success' : 'badge-secondary'; ?>"><?php echo rb_e($transaction['tr_status']); ?></span></td>
                </tr>
              <?php } ?>
              <?php if (!$transactions) { ?><tr><td colspan="9" class="text-center text-muted py-4">No transactions match these filters.</td></tr><?php } ?></tbody>
            </table>
          </div>
          <div class="card-footer d-flex justify-content-between align-items-center flex-wrap">
            <span class="small text-muted">Showing <?php echo $first_row; ?>–<?php echo $last_row; ?> of <?php echo (int) $total_rows; ?></span>
            <nav aria-label="Report pages"><ul class="pagination pagination-sm mb-0">
              <?php for ($page_number = 1; $page_number <= $total_pages; $page_number++) {
                  $page_query = $filters;
                  $page_query['page'] = $page_number;
              ?><li class="page-item <?php echo $page_number === $page ? 'active' : ''; ?>"><a class="page-link" href="pages_financial_reports.php?<?php echo rb_e(http_build_query($page_query)); ?>"><?php echo $page_number; ?></a></li><?php } ?>
            </ul></nav>
          </div>
        </div>
      </div></section>
    </div>
    <?php include("dist/_partials/footer.php"); ?>
  </div>
  <style media="print">
    .rb-report-filters,.pagination,.main-sidebar,.main-header,.main-footer,.rb-i18n,.rb-assistant{display:none!important}
    .content-wrapper{margin:0!important;background:#fff!important}
  </style>
  <script src="plugins/jquery/jquery.min.js"></script>
  <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="dist/js/adminlte.min.js"></script>
</body>
</html>
