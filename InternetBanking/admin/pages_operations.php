<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();
$admin_id = (int) $_SESSION['admin_id'];
$error = '';
$success = '';
$ready = true;
$branches = array();
$agents = array();
$cheques = array();
$clients = array();
$tickets = array();
$audit_rows = array();
$staff_members = array();
$announcements = array();
$backups = array();
$permission_keys = array(
    'manage_clients' => 'Manage customer records',
    'manage_accounts' => 'Open and manage customer accounts',
    'review_cards' => 'Review requests and manage card records',
    'view_credit_profiles' => 'View customer-reported credit profiles',
    'record_deposits' => 'Record branch deposits',
    'process_withdrawals' => 'Process withdrawals',
    'process_transfers' => 'Process transfers',
    'review_risk_alerts' => 'Review fraud/risk alerts',
    'manage_transactions' => 'Manage transaction history and corrections',
    'view_reports' => 'View financial reports and AI desk',
);
$audit_action_labels = array(
    'demo_points_awarded' => 'Reward points awarded',
    'demo_points_deducted' => 'Reward points deducted',
    'reward_points_awarded' => 'Reward points awarded',
    'reward_points_deducted' => 'Reward points deducted',
);

try {
    $mysqli->query('SELECT branch_id FROM ib_branches LIMIT 0');
    $mysqli->query('SELECT agent_id FROM ib_agent_profiles LIMIT 0');
    $mysqli->query('SELECT cheque_request_id FROM ib_cheque_requests LIMIT 0');
    $mysqli->query('SELECT kyc_id FROM ib_kyc_reviews LIMIT 0');
    $mysqli->query('SELECT response_id FROM ib_support_responses LIMIT 0');
    $mysqli->query('SELECT audit_id FROM ib_audit_log LIMIT 0');
    $mysqli->query('SELECT announcement_id FROM ib_announcements LIMIT 0');
    $mysqli->query('SELECT backup_id FROM ib_backup_records LIMIT 0');
    $mysqli->query('SELECT permission_id FROM ib_user_permissions LIMIT 0');
} catch (Throwable $e) {
    $ready = false;
}

function rb_admin_ops_audit($db, $admin_id, $action, $entity_type, $entity_id, $details)
{
    $role = 'admin';
    $entity_id = (string) $entity_id;
    $details = substr((string) $details, 0, 500);
    $stmt = $db->prepare('INSERT INTO ib_audit_log (actor_role, actor_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare the audit record.');
    }
    $stmt->bind_param('sissss', $role, $admin_id, $action, $entity_type, $entity_id, $details);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Could not save the audit record.');
    }
    $stmt->close();
}

if ($ready && isset($_POST['save_staff_permissions'], $_POST['permission_staff_id'])) {
    $permission_staff_id = filter_var($_POST['permission_staff_id'], FILTER_VALIDATE_INT);
    $submitted_permissions = isset($_POST['permissions']) && is_array($_POST['permissions'])
        ? $_POST['permissions']
        : array();
    if (!$permission_staff_id) {
        $error = 'Choose a valid staff account.';
    } else {
        $stmt = $mysqli->prepare('SELECT staff_id FROM ib_staff WHERE staff_id = ? LIMIT 1');
        $stmt->bind_param('i', $permission_staff_id);
        $stmt->execute();
        $stmt->store_result();
        $staff_exists = $stmt->num_rows === 1;
        $stmt->close();
        if (!$staff_exists) {
            $error = 'The selected staff account does not exist.';
        } else {
            try {
                $mysqli->begin_transaction();
                $stmt = $mysqli->prepare('INSERT INTO ib_user_permissions (staff_id, permission_key, is_allowed, updated_by)
                                          VALUES (?, ?, ?, ?)
                                          ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed), updated_by = VALUES(updated_by)');
                foreach ($permission_keys as $key => $label) {
                    $allowed = in_array($key, $submitted_permissions, true) ? 1 : 0;
                    $stmt->bind_param('isii', $permission_staff_id, $key, $allowed, $admin_id);
                    if (!$stmt->execute()) { throw new RuntimeException('Could not update staff permissions.'); }
                }
                $stmt->close();
                rb_admin_ops_audit($mysqli, $admin_id, 'staff_permissions_updated', 'staff', $permission_staff_id, implode(',', array_keys($permission_keys)));
                $mysqli->commit();
                $success = 'Staff service permissions saved. Unticked services are denied.';
            } catch (Throwable $e) {
                try { $mysqli->rollback(); } catch (Throwable $rollbackError) {}
                $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not save staff permissions.';
                error_log('[RuralBanking] staff permissions update failed: ' . $e->getMessage());
            }
        }
    }
}

if ($ready && isset($_POST['create_announcement'])) {
    $subject = trim((string) (isset($_POST['announcement_subject']) ? $_POST['announcement_subject'] : ''));
    $body = trim((string) (isset($_POST['announcement_body']) ? $_POST['announcement_body'] : ''));
    if ($subject === '' || strlen($subject) > 180 || preg_match('/[\r\n]/', $subject)
        || strlen($body) < 10 || strlen($body) > 10000) {
        $error = 'Enter a subject (up to 180 characters) and a message between 10 and 10,000 characters.';
    } else {
        $stmt = $mysqli->prepare("INSERT INTO ib_announcements (admin_id, subject, body, status) VALUES (?, ?, ?, 'draft')");
        $stmt->bind_param('iss', $admin_id, $subject, $body);
        if ($stmt->execute()) {
            $announcement_id = (int) $mysqli->insert_id;
            $stmt->close();
            rb_admin_ops_audit($mysqli, $admin_id, 'announcement_drafted', 'announcement', $announcement_id, $subject);
            $success = 'Announcement saved as a draft. It has not been emailed.';
        } else {
            $stmt->close();
            $error = 'Could not save the announcement draft.';
        }
    }
}

if ($ready && isset($_POST['send_announcement'])) {
    $announcement_id = filter_var($_POST['send_announcement'], FILTER_VALIDATE_INT);
    if (!$announcement_id) {
        $error = 'Choose a valid announcement.';
    } else {
        try {
            $mysqli->begin_transaction();
            $stmt = $mysqli->prepare("SELECT subject, body FROM ib_announcements WHERE announcement_id = ? AND status = 'draft' FOR UPDATE");
            $stmt->bind_param('i', $announcement_id);
            $stmt->execute();
            $stmt->bind_result($announcement_subject, $announcement_body);
            if (!$stmt->fetch()) {
                $stmt->close();
                throw new RuntimeException('This announcement is no longer a draft.');
            }
            $stmt->close();
            $result = $mysqli->query("SELECT client_id, email FROM ib_clients WHERE email <> '' ORDER BY client_id LIMIT 501");
            $recipients = array();
            while ($result && ($row = $result->fetch_assoc())) {
                if (rb_email_valid_address($row['email'])) { $recipients[] = $row; }
            }
            if (!$recipients) { throw new RuntimeException('There are no customers with a valid email address.'); }
            if (count($recipients) > 500) {
                throw new RuntimeException('This manual announcement exceeds the 500-recipient safety limit.');
            }
            foreach ($recipients as $recipient) {
                $queued = rb_email_queue($mysqli, $recipient['email'], $announcement_subject, $announcement_body,
                    (int) $recipient['client_id'], 'announcement');
                if (!$queued['ok'] || !$queued['queued']) {
                    throw new RuntimeException('Could not queue the announcement for every recipient.');
                }
            }
            $sent_status = 'queued';
            $stmt = $mysqli->prepare("UPDATE ib_announcements SET status = ?, sent_at = CURRENT_TIMESTAMP
                                      WHERE announcement_id = ? AND status = 'draft'");
            $stmt->bind_param('si', $sent_status, $announcement_id);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('Could not mark the announcement as queued.');
            }
            $stmt->close();
            rb_admin_ops_audit($mysqli, $admin_id, 'announcement_queued', 'announcement', $announcement_id, count($recipients) . ' recipients');
            $mysqli->commit();
            $success = 'Announcement queued for email delivery to ' . count($recipients) . ' customer(s). No message was sent by SMS.';
        } catch (Throwable $e) {
            try { $mysqli->rollback(); } catch (Throwable $rollbackError) {}
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not queue the announcement.';
            error_log('[RuralBanking] announcement queue failed: ' . $e->getMessage());
        }
    }
}

if ($ready && isset($_POST['record_backup'])) {
    $status = isset($_POST['backup_status']) ? (string) $_POST['backup_status'] : '';
    $reference = trim((string) (isset($_POST['backup_reference']) ? $_POST['backup_reference'] : ''));
    $notes = trim((string) (isset($_POST['backup_notes']) ? $_POST['backup_notes'] : ''));
    if (!in_array($status, array('recorded', 'verified', 'failed'), true)
        || strlen($reference) > 190 || strlen($notes) > 255) {
        $error = 'Enter a valid backup status and optional reference/notes within the field limits.';
    } else {
        $backup_type = 'database';
        $stmt = $mysqli->prepare('INSERT INTO ib_backup_records (admin_id, backup_type, status, storage_reference, notes) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('issss', $admin_id, $backup_type, $status, $reference, $notes);
        if ($stmt->execute()) {
            $backup_id = (int) $mysqli->insert_id;
            $stmt->close();
            rb_admin_ops_audit($mysqli, $admin_id, 'backup_status_recorded', 'backup', $backup_id, $status . ' ' . $reference);
            $success = 'Backup status recorded. This page does not create, verify, or store a database backup.';
        } else {
            $stmt->close();
            $error = 'Could not record backup status.';
        }
    }
}

if ($ready && isset($_POST['add_branch'])) {
    $code = strtoupper(trim((string) (isset($_POST['branch_code']) ? $_POST['branch_code'] : '')));
    $name = trim((string) (isset($_POST['branch_name']) ? $_POST['branch_name'] : ''));
    $address = trim((string) (isset($_POST['branch_address']) ? $_POST['branch_address'] : ''));
    $phone = trim((string) (isset($_POST['branch_phone']) ? $_POST['branch_phone'] : ''));
    $email = trim((string) (isset($_POST['branch_email']) ? $_POST['branch_email'] : ''));
    if (!preg_match('/^[A-Z0-9-]{2,24}$/D', $code) || $name === '' || strlen($name) > 100
        || strlen($address) > 255 || strlen($phone) > 24 || strlen($email) > 190
        || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        $error = 'Enter a valid branch code, name, and optional contact information.';
    } else {
        try {
            $stmt = $mysqli->prepare("INSERT INTO ib_branches (branch_code, name, address, phone, email, status) VALUES (?, ?, ?, ?, ?, 'active')");
            $stmt->bind_param('sssss', $code, $name, $address, $phone, $email);
            if (!$stmt->execute()) { throw new RuntimeException('Could not save this branch.'); }
            $branch_id = (int) $mysqli->insert_id;
            $stmt->close();
            rb_admin_ops_audit($mysqli, $admin_id, 'branch_created', 'branch', $branch_id, $code);
            $success = 'Branch added.';
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not add this branch.';
            error_log('[RuralBanking] branch create failed: ' . $e->getMessage());
        }
    }
}

if ($ready && isset($_POST['branch_status'], $_POST['branch_id'])) {
    $branch_id = filter_var($_POST['branch_id'], FILTER_VALIDATE_INT);
    $status = (string) $_POST['branch_status'];
    if (!$branch_id || !in_array($status, array('active', 'inactive'), true)) {
        $error = 'Invalid branch status request.';
    } else {
        $stmt = $mysqli->prepare('UPDATE ib_branches SET status = ? WHERE branch_id = ?');
        $stmt->bind_param('si', $status, $branch_id);
        if ($stmt->execute()) {
            $stmt->close();
            rb_admin_ops_audit($mysqli, $admin_id, 'branch_status_changed', 'branch', $branch_id, $status);
            $success = 'Branch status updated.';
        } else {
            $stmt->close();
            $error = 'Could not update this branch.';
        }
    }
}

if ($ready && isset($_POST['add_agent'])) {
    $branch_id = filter_var(isset($_POST['agent_branch_id']) ? $_POST['agent_branch_id'] : null, FILTER_VALIDATE_INT);
    $agent_code = strtoupper(trim((string) (isset($_POST['agent_code']) ? $_POST['agent_code'] : '')));
    $name = trim((string) (isset($_POST['agent_name']) ? $_POST['agent_name'] : ''));
    $email = trim((string) (isset($_POST['agent_email']) ? $_POST['agent_email'] : ''));
    $phone = trim((string) (isset($_POST['agent_phone']) ? $_POST['agent_phone'] : ''));
    if (!preg_match('/^[A-Z0-9-]{2,24}$/D', $agent_code) || $name === '' || strlen($name) > 100
        || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || strlen($phone) > 24) {
        $error = 'Enter a valid agent code, name, and email; phone is optional.';
    } else {
        try {
            $branch_value = $branch_id ? $branch_id : null;
            if ($branch_value !== null) {
                $stmt = $mysqli->prepare("SELECT branch_id FROM ib_branches WHERE branch_id = ? AND status = 'active' LIMIT 1");
                $stmt->bind_param('i', $branch_value);
                $stmt->execute();
                $stmt->store_result();
                $valid_branch = $stmt->num_rows === 1;
                $stmt->close();
                if (!$valid_branch) { throw new RuntimeException('Choose an active branch.'); }
            }
            $stmt = $mysqli->prepare("INSERT INTO ib_agent_profiles (branch_id, agent_code, name, email, phone, status) VALUES (?, ?, ?, ?, ?, 'pending')");
            $stmt->bind_param('issss', $branch_value, $agent_code, $name, $email, $phone);
            if (!$stmt->execute()) { throw new RuntimeException('Could not save this agent profile.'); }
            $agent_id = (int) $mysqli->insert_id;
            $stmt->close();
            rb_admin_ops_audit($mysqli, $admin_id, 'agent_registered', 'agent', $agent_id, $agent_code);
            $success = 'Agent registered with pending status.';
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not register this agent.';
            error_log('[RuralBanking] agent create failed: ' . $e->getMessage());
        }
    }
}

if ($ready && isset($_POST['agent_status'], $_POST['agent_id'])) {
    $agent_id = filter_var($_POST['agent_id'], FILTER_VALIDATE_INT);
    $status = (string) $_POST['agent_status'];
    if (!$agent_id || !in_array($status, array('active', 'suspended', 'pending'), true)) {
        $error = 'Invalid agent status request.';
    } else {
        $stmt = $mysqli->prepare('UPDATE ib_agent_profiles SET status = ? WHERE agent_id = ?');
        $stmt->bind_param('si', $status, $agent_id);
        if ($stmt->execute()) {
            $stmt->close();
            rb_admin_ops_audit($mysqli, $admin_id, 'agent_status_changed', 'agent', $agent_id, $status);
            $success = 'Agent status updated.';
        } else {
            $stmt->close();
            $error = 'Could not update the agent status.';
        }
    }
}

if ($ready && isset($_POST['review_cheque'], $_POST['cheque_id'])) {
    $cheque_id = filter_var($_POST['cheque_id'], FILTER_VALIDATE_INT);
    $status = (string) $_POST['review_cheque'];
    if (!$cheque_id || !in_array($status, array('approved', 'rejected'), true)) {
        $error = 'Invalid cheque-book review request.';
    } else {
        $stmt = $mysqli->prepare("UPDATE ib_cheque_requests SET status = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP WHERE cheque_request_id = ? AND status = 'pending'");
        $stmt->bind_param('sii', $status, $admin_id, $cheque_id);
        if ($stmt->execute() && $stmt->affected_rows === 1) {
            $stmt->close();
            rb_admin_ops_audit($mysqli, $admin_id, 'cheque_request_reviewed', 'cheque_request', $cheque_id, $status);
            $success = 'Cheque-book request reviewed.';
        } else {
            $stmt->close();
            $error = 'This cheque-book request is no longer pending.';
        }
    }
}

if ($ready && isset($_POST['review_kyc'], $_POST['client_id'])) {
    $client_id = filter_var($_POST['client_id'], FILTER_VALIDATE_INT);
    $status = (string) $_POST['review_kyc'];
    $note = trim((string) (isset($_POST['kyc_note']) ? $_POST['kyc_note'] : ''));
    if (!$client_id || !in_array($status, array('pending', 'approved', 'rejected'), true) || strlen($note) > 500) {
        $error = 'Choose a valid customer review status and an optional note up to 500 characters.';
    } else {
        $stmt = $mysqli->prepare('INSERT INTO ib_kyc_reviews (client_id, status, reviewer_admin_id, review_note, reviewed_at)
                                  VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                                  ON DUPLICATE KEY UPDATE status = VALUES(status), reviewer_admin_id = VALUES(reviewer_admin_id),
                                  review_note = VALUES(review_note), reviewed_at = CURRENT_TIMESTAMP');
        $stmt->bind_param('isis', $client_id, $status, $admin_id, $note);
        if ($stmt->execute()) {
            $stmt->close();
            rb_admin_ops_audit($mysqli, $admin_id, 'kyc_status_changed', 'client', $client_id, $status);
            $success = 'KYC review status updated.';
        } else {
            $stmt->close();
            $error = 'Could not save the KYC review status.';
        }
    }
}

if ($ready && isset($_POST['respond_ticket'], $_POST['ticket_id'])) {
    $ticket_id = filter_var($_POST['ticket_id'], FILTER_VALIDATE_INT);
    $status = isset($_POST['ticket_status']) ? (string) $_POST['ticket_status'] : 'open';
    $response = trim((string) $_POST['respond_ticket']);
    if (!$ticket_id || !in_array($status, array('open', 'in_progress', 'resolved'), true)
        || strlen($response) < 1 || strlen($response) > 4000) {
        $error = 'Enter a valid support response and status.';
    } else {
        try {
            $stmt = $mysqli->prepare('SELECT c.client_id, c.email, t.subject FROM ib_support_tickets t JOIN ib_clients c ON c.client_id = t.client_id WHERE t.ticket_id = ? LIMIT 1');
            $stmt->bind_param('i', $ticket_id);
            $stmt->execute();
            $stmt->bind_result($ticket_client_id, $ticket_email, $ticket_subject);
            if (!$stmt->fetch()) { $stmt->close(); throw new RuntimeException('Support ticket not found.'); }
            $stmt->close();
            $mysqli->begin_transaction();
            $stmt = $mysqli->prepare('UPDATE ib_support_tickets SET status = ? WHERE ticket_id = ?');
            $stmt->bind_param('si', $status, $ticket_id);
            if (!$stmt->execute()) { throw new RuntimeException('Could not save the support response.'); }
            $stmt->close();
            $stmt = $mysqli->prepare('INSERT INTO ib_support_responses (ticket_id, admin_id, response) VALUES (?, ?, ?)');
            $stmt->bind_param('iis', $ticket_id, $admin_id, $response);
            if (!$stmt->execute()) { throw new RuntimeException('Could not save the support response.'); }
            $stmt->close();
            $mail = rb_email_queue($mysqli, $ticket_email, 'Support ticket update: ' . $ticket_subject,
                "Your support ticket has been updated.\n\nStatus: " . $status . "\nResponse: " . $response . "\n\nBank Of Soumya",
                $ticket_client_id, 'support');
            if (!$mail['ok'] || !$mail['queued']) { throw new RuntimeException($mail['error']); }
            $mysqli->commit();
            rb_admin_ops_audit($mysqli, $admin_id, 'support_ticket_responded', 'ticket', $ticket_id, $status);
            $success = 'Support response saved and queued for email delivery.';
        } catch (Throwable $e) {
            try { $mysqli->rollback(); } catch (Throwable $rollbackError) {}
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not update this support ticket.';
            error_log('[RuralBanking] support response failed: ' . $e->getMessage());
        }
    }
}

if ($ready) {
    try {
        $result = $mysqli->query('SELECT branch_id, branch_code, name, address, phone, email, status FROM ib_branches ORDER BY name LIMIT 200');
        while ($result && ($row = $result->fetch_assoc())) { $branches[] = $row; }
        $result = $mysqli->query('SELECT a.agent_id, a.agent_code, a.name, a.email, a.phone, a.status, b.name AS branch_name FROM ib_agent_profiles a LEFT JOIN ib_branches b ON b.branch_id = a.branch_id ORDER BY a.created_at DESC LIMIT 200');
        while ($result && ($row = $result->fetch_assoc())) { $agents[] = $row; }
        $result = $mysqli->query("SELECT q.cheque_request_id, q.leaf_count, q.status, q.created_at, c.name, a.account_number FROM ib_cheque_requests q JOIN ib_clients c ON c.client_id = q.client_id JOIN ib_bankaccounts a ON a.account_id = q.account_id ORDER BY (q.status = 'pending') DESC, q.created_at DESC LIMIT 100");
        while ($result && ($row = $result->fetch_assoc())) { $cheques[] = $row; }
        $result = $mysqli->query('SELECT c.client_id, c.name, c.email, k.status, k.review_note FROM ib_clients c LEFT JOIN ib_kyc_reviews k ON k.client_id = c.client_id ORDER BY (k.status IS NULL OR k.status = \'pending\') DESC, c.name LIMIT 100');
        while ($result && ($row = $result->fetch_assoc())) { $clients[] = $row; }
        $result = $mysqli->query("SELECT t.ticket_id, t.subject, t.message, t.status, r.response AS admin_response, t.created_at, c.client_id, c.name, c.email
                                  FROM ib_support_tickets t JOIN ib_clients c ON c.client_id = t.client_id
                                  LEFT JOIN ib_support_responses r ON r.response_id = (
                                    SELECT MAX(r2.response_id) FROM ib_support_responses r2 WHERE r2.ticket_id = t.ticket_id
                                  )
                                  ORDER BY (t.status = 'open') DESC, t.created_at DESC LIMIT 100");
        while ($result && ($row = $result->fetch_assoc())) { $tickets[] = $row; }
        $result = $mysqli->query('SELECT actor_role, actor_id, action, entity_type, entity_id, details, created_at FROM ib_audit_log ORDER BY created_at DESC LIMIT 100');
        while ($result && ($row = $result->fetch_assoc())) { $audit_rows[] = $row; }
        $result = $mysqli->query('SELECT staff_id, name, email FROM ib_staff ORDER BY name LIMIT 200');
        while ($result && ($row = $result->fetch_assoc())) {
            $row['permissions'] = array();
            $row['has_custom_permissions'] = false;
            $staff_members[(int) $row['staff_id']] = $row;
        }
        $result = $mysqli->query('SELECT staff_id, permission_key, is_allowed FROM ib_user_permissions');
        while ($result && ($row = $result->fetch_assoc())) {
            $staff_id = (int) $row['staff_id'];
            if (isset($staff_members[$staff_id])) {
                $staff_members[$staff_id]['has_custom_permissions'] = true;
                if ((int) $row['is_allowed'] === 1) {
                    $staff_members[$staff_id]['permissions'][] = $row['permission_key'];
                }
            }
        }
        foreach ($staff_members as &$staff_member) {
            if (!$staff_member['has_custom_permissions']) {
                $staff_member['permissions'] = array_keys($permission_keys);
            }
        }
        unset($staff_member);
        $result = $mysqli->query('SELECT announcement_id, subject, status, created_at, sent_at FROM ib_announcements ORDER BY created_at DESC LIMIT 100');
        while ($result && ($row = $result->fetch_assoc())) { $announcements[] = $row; }
        $result = $mysqli->query('SELECT backup_id, backup_type, status, storage_reference, notes, created_at FROM ib_backup_records ORDER BY created_at DESC LIMIT 100');
        while ($result && ($row = $result->fetch_assoc())) { $backups[] = $row; }
    } catch (Throwable $e) {
        $ready = false;
        $error = 'Could not load operations records. Check the advanced features migration.';
        error_log('[RuralBanking] admin operations load failed: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<meta http-equiv="content-type" content="text/html;charset=utf-8" />
<?php include("dist/_partials/head.php"); ?>
<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed">
<div class="wrapper">
  <?php include("dist/_partials/nav.php"); ?><?php include("dist/_partials/sidebar.php"); ?>
  <div class="content-wrapper">
    <section class="content-header"><div class="container-fluid"><h1>Branch &amp; Service Operations</h1><p class="text-muted">Manage branches, agents, service requests and review workflows. <a href="../service_information.php">Service Information</a></p></div></section>
    <section class="content"><div class="container-fluid">
      <?php if (!$ready) { ?><div class="alert alert-warning">Import the email, QR/notification, and advanced-features migrations before using this page.</div><?php } ?>
      <?php if ($error !== '') { ?><div class="alert alert-danger" role="alert"><?php echo rb_e($error); ?></div><?php } ?>
      <?php if ($success !== '') { ?><div class="alert alert-success" role="status"><?php echo rb_e($success); ?></div><?php } ?>
      <div class="row">
        <div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Branches</h3></div><div class="card-body">
          <?php if ($ready) { ?><form method="post" class="form-row">
            <div class="form-group col-md-4"><label>Code</label><input class="form-control" name="branch_code" maxlength="24" required></div>
            <div class="form-group col-md-8"><label>Branch name</label><input class="form-control" name="branch_name" maxlength="100" required></div>
            <div class="form-group col-md-6"><label>Address</label><input class="form-control" name="branch_address" maxlength="255"></div>
            <div class="form-group col-md-3"><label>Phone</label><input class="form-control" name="branch_phone" maxlength="24"></div>
            <div class="form-group col-md-3"><label>Email</label><input class="form-control" type="email" name="branch_email" maxlength="190"></div>
            <div class="col-12"><button class="btn btn-success" name="add_branch" value="1">Add branch</button></div>
          </form><?php } ?>
          <div class="table-responsive mt-3"><table class="table table-sm"><thead><tr><th>Branch</th><th>Code</th><th>Status</th><th></th></tr></thead><tbody>
            <?php foreach ($branches as $branch) { ?><tr><td><?php echo rb_e($branch['name']); ?><div class="small text-muted"><?php echo rb_e($branch['address']); ?></div></td><td><?php echo rb_e($branch['branch_code']); ?></td><td><?php echo rb_e($branch['status']); ?></td><td><form method="post"><input type="hidden" name="branch_id" value="<?php echo (int) $branch['branch_id']; ?>"><button class="btn btn-sm btn-outline-primary" name="branch_status" value="<?php echo $branch['status'] === 'active' ? 'inactive' : 'active'; ?>"><?php echo $branch['status'] === 'active' ? 'Deactivate' : 'Activate'; ?></button></form></td></tr><?php } ?>
            <?php if (!$branches) { ?><tr><td colspan="4" class="text-muted">No branches recorded.</td></tr><?php } ?>
          </tbody></table></div>
        </div></div></div>
        <div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Rural agent profiles</h3></div><div class="card-body">
          <?php if ($ready) { ?><form method="post" class="form-row">
            <div class="form-group col-md-4"><label>Agent code</label><input class="form-control" name="agent_code" maxlength="24" required></div>
            <div class="form-group col-md-8"><label>Agent name</label><input class="form-control" name="agent_name" maxlength="100" required></div>
            <div class="form-group col-md-6"><label>Email</label><input class="form-control" type="email" name="agent_email" maxlength="190" required></div>
            <div class="form-group col-md-3"><label>Phone</label><input class="form-control" name="agent_phone" maxlength="24"></div>
            <div class="form-group col-md-3"><label>Branch</label><select class="form-control" name="agent_branch_id"><option value="">Unassigned</option><?php foreach ($branches as $branch) { if ($branch['status'] === 'active') { ?><option value="<?php echo (int) $branch['branch_id']; ?>"><?php echo rb_e($branch['branch_code']); ?></option><?php } } ?></select></div>
            <div class="col-12"><button class="btn btn-success" name="add_agent" value="1">Register agent</button></div>
          </form><?php } ?>
          <div class="table-responsive mt-3"><table class="table table-sm"><thead><tr><th>Agent</th><th>Branch</th><th>Status</th><th>Set status</th></tr></thead><tbody>
            <?php foreach ($agents as $agent) { ?><tr><td><?php echo rb_e($agent['name']); ?><div class="small text-muted"><?php echo rb_e($agent['agent_code'] . ' · ' . $agent['email']); ?></div></td><td><?php echo rb_e($agent['branch_name']); ?></td><td><?php echo rb_e($agent['status']); ?></td><td><form method="post"><input type="hidden" name="agent_id" value="<?php echo (int) $agent['agent_id']; ?>"><select class="form-control form-control-sm" name="agent_status"><option value="pending">Pending</option><option value="active">Active</option><option value="suspended">Suspended</option></select><button class="btn btn-sm btn-outline-primary mt-1" type="submit">Update</button></form></td></tr><?php } ?>
            <?php if (!$agents) { ?><tr><td colspan="4" class="text-muted">No agent profiles recorded.</td></tr><?php } ?>
          </tbody></table></div>
        </div></div></div>
      </div>
      <div class="card"><div class="card-header"><h3 class="card-title">Cheque-book requests</h3></div><div class="card-body table-responsive"><table class="table table-sm"><thead><tr><th>Customer</th><th>Account</th><th>Leaves</th><th>Status</th><th>Review</th></tr></thead><tbody>
        <?php foreach ($cheques as $request) { ?><tr><td><?php echo rb_e($request['name']); ?></td><td>••••<?php echo rb_e(substr($request['account_number'], -4)); ?></td><td><?php echo (int) $request['leaf_count']; ?></td><td><?php echo rb_e($request['status']); ?></td><td><?php if ($request['status'] === 'pending') { ?><form method="post"><input type="hidden" name="cheque_id" value="<?php echo (int) $request['cheque_request_id']; ?>"><button class="btn btn-sm btn-success" name="review_cheque" value="approved">Approve</button> <button class="btn btn-sm btn-outline-danger" name="review_cheque" value="rejected">Reject</button></form><?php } ?></td></tr><?php } ?>
        <?php if (!$cheques) { ?><tr><td colspan="5" class="text-muted">No cheque-book requests.</td></tr><?php } ?>
      </tbody></table></div></div>
      <div class="card"><div class="card-header"><h3 class="card-title">KYC Review</h3></div><div class="card-body table-responsive"><table class="table table-sm"><thead><tr><th>Customer</th><th>Status</th><th>Review note</th><th>Update</th></tr></thead><tbody>
        <?php foreach ($clients as $client) { ?><tr><td><?php echo rb_e($client['name']); ?><div class="small text-muted"><?php echo rb_e($client['email']); ?></div></td><td><?php echo rb_e($client['status'] ? $client['status'] : 'not reviewed'); ?></td><td><?php echo rb_e($client['review_note']); ?></td><td><form method="post" class="form-inline"><input type="hidden" name="client_id" value="<?php echo (int) $client['client_id']; ?>"><select class="form-control form-control-sm mr-1" name="review_kyc"><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option></select><input class="form-control form-control-sm mr-1" name="kyc_note" maxlength="500" placeholder="Note"><button class="btn btn-sm btn-outline-primary" type="submit">Save</button></form></td></tr><?php } ?>
      </tbody></table></div></div>
      <div class="card"><div class="card-header"><h3 class="card-title">Support tickets</h3></div><div class="card-body">
        <?php foreach ($tickets as $ticket) { ?><div class="border-top py-3"><strong>#<?php echo (int) $ticket['ticket_id']; ?> · <?php echo rb_e($ticket['subject']); ?></strong><span class="badge badge-light ml-2"><?php echo rb_e($ticket['status']); ?></span><div class="small text-muted"><?php echo rb_e($ticket['name'] . ' · ' . $ticket['email'] . ' · ' . $ticket['created_at']); ?></div><p class="mt-2 mb-2"><?php echo nl2br(rb_e($ticket['message'])); ?></p>
          <form method="post" class="form-row"><div class="form-group col-md-2"><select class="form-control" name="ticket_status"><option value="open">Open</option><option value="in_progress">In progress</option><option value="resolved">Resolved</option></select></div><div class="form-group col-md-8"><textarea class="form-control" name="respond_ticket" rows="2" maxlength="4000" required placeholder="Reply (queued to customer email)"></textarea></div><div class="form-group col-md-2"><button class="btn btn-success" name="ticket_id" value="<?php echo (int) $ticket['ticket_id']; ?>">Send reply</button></div></form>
        </div><?php } ?>
        <?php if (!$tickets) { ?><p class="text-muted">No support tickets.</p><?php } ?>
      </div></div>
      <div class="card"><div class="card-header"><h3 class="card-title">Recent admin audit log</h3></div><div class="card-body table-responsive"><table class="table table-sm"><thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Record</th><th>Details</th></tr></thead><tbody>
        <?php foreach ($audit_rows as $row) {
          $action_label = isset($audit_action_labels[$row['action']]) ? $audit_action_labels[$row['action']] : $row['action'];
          $audit_details = str_ireplace('demo points', 'reward points', $row['details']);
        ?><tr><td><?php echo rb_e($row['created_at']); ?></td><td><?php echo rb_e($row['actor_role'] . ' #' . $row['actor_id']); ?></td><td><?php echo rb_e($action_label); ?></td><td><?php echo rb_e($row['entity_type'] . ' ' . $row['entity_id']); ?></td><td><?php echo rb_e($audit_details); ?></td></tr><?php } ?>
        <?php if (!$audit_rows) { ?><tr><td colspan="5" class="text-muted">No audit records yet.</td></tr><?php } ?>
      </tbody></table></div></div>
      <div class="card"><div class="card-header"><h3 class="card-title">Staff service permissions</h3></div><div class="card-body">
        <p class="text-muted">These permissions are enforced by the staff portal for the listed services. Existing staff keep full access until you save a custom permission set.</p>
        <?php foreach ($staff_members as $staff) { ?><form method="post" class="border-top py-3">
          <input type="hidden" name="permission_staff_id" value="<?php echo (int) $staff['staff_id']; ?>">
          <strong><?php echo rb_e($staff['name']); ?></strong><span class="small text-muted ml-2"><?php echo rb_e($staff['email']); ?></span>
          <div class="form-row mt-2">
            <?php foreach ($permission_keys as $key => $label) { ?><div class="form-group col-md-4"><div class="custom-control custom-checkbox"><input class="custom-control-input" type="checkbox" id="perm-<?php echo (int) $staff['staff_id']; ?>-<?php echo rb_e($key); ?>" name="permissions[]" value="<?php echo rb_e($key); ?>" <?php echo in_array($key, $staff['permissions'], true) ? 'checked' : ''; ?>><label class="custom-control-label" for="perm-<?php echo (int) $staff['staff_id']; ?>-<?php echo rb_e($key); ?>"><?php echo rb_e($label); ?></label></div></div><?php } ?>
          </div>
          <button class="btn btn-sm btn-outline-primary" name="save_staff_permissions" value="1">Save permissions</button>
        </form><?php } ?>
        <?php if (!$staff_members) { ?><p class="text-muted">No staff accounts.</p><?php } ?>
      </div></div>
      <div class="row">
        <div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Email announcements</h3></div><div class="card-body">
          <p class="small text-muted">Drafts are not sent. Sending queues email only, at most 500 valid customer addresses per announcement. No SMS or external send service is used.</p>
          <?php if ($ready) { ?><form method="post">
            <div class="form-group"><label for="announcement_subject">Subject</label><input class="form-control" id="announcement_subject" name="announcement_subject" maxlength="180" required></div>
            <div class="form-group"><label for="announcement_body">Email message</label><textarea class="form-control" id="announcement_body" name="announcement_body" rows="4" maxlength="10000" required></textarea></div>
            <button class="btn btn-outline-success" name="create_announcement" value="1">Save draft</button>
          </form><?php } ?>
          <div class="table-responsive mt-3"><table class="table table-sm"><thead><tr><th>Subject</th><th>Status</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($announcements as $announcement) { ?><tr><td><?php echo rb_e($announcement['subject']); ?><div class="small text-muted"><?php echo rb_e($announcement['created_at']); ?></div></td><td><?php echo rb_e($announcement['status']); ?></td><td><?php if ($announcement['status'] === 'draft') { ?><form method="post" onsubmit="return confirm('Queue this email for all eligible customers?');"><button class="btn btn-sm btn-success" name="send_announcement" value="<?php echo (int) $announcement['announcement_id']; ?>">Queue email</button></form><?php } ?></td></tr><?php } ?>
            <?php if (!$announcements) { ?><tr><td colspan="3" class="text-muted">No announcements.</td></tr><?php } ?>
          </tbody></table></div>
        </div></div></div>
        <div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Backup status records</h3></div><div class="card-body">
          <div class="alert alert-warning">Records status only; this tool does not create, verify, or store backups. Use the hosting control panel or a secure backup procedure.</div>
          <?php if ($ready) { ?><form method="post" class="form-row">
            <div class="form-group col-md-4"><label>Status</label><select class="form-control" name="backup_status"><option value="recorded">Recorded</option><option value="verified">Verified externally</option><option value="failed">Failed</option></select></div>
            <div class="form-group col-md-8"><label>Storage reference</label><input class="form-control" name="backup_reference" maxlength="190"></div>
            <div class="form-group col-12"><label>Notes</label><input class="form-control" name="backup_notes" maxlength="255"></div>
            <div class="col-12"><button class="btn btn-outline-primary" name="record_backup" value="1">Record status</button></div>
          </form><?php } ?>
          <div class="table-responsive mt-3"><table class="table table-sm"><thead><tr><th>Date</th><th>Status</th><th>Reference</th><th>Notes</th></tr></thead><tbody>
            <?php foreach ($backups as $backup) { ?><tr><td><?php echo rb_e($backup['created_at']); ?></td><td><?php echo rb_e($backup['status']); ?></td><td><?php echo rb_e($backup['storage_reference']); ?></td><td><?php echo rb_e($backup['notes']); ?></td></tr><?php } ?>
            <?php if (!$backups) { ?><tr><td colspan="4" class="text-muted">No backup status records.</td></tr><?php } ?>
          </tbody></table></div>
        </div></div></div>
      </div>
    </div></section>
  </div>
  <?php include("dist/_partials/footer.php"); ?>
</div>
<script src="plugins/jquery/jquery.min.js"></script><script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script><script src="dist/js/adminlte.min.js"></script>
</body>
</html>
