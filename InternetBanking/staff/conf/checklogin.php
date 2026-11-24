<?php
/* Session guard for the staff portal. Redirects (relative, works on http and https) and stops. */
function check_login()
{
    if (empty($_SESSION['staff_id'])) {
        header('Location: pages_staff_index.php');
        exit;
    }
    $capability_pages = array(
        'pages_add_client.php' => 'manage_clients',
        'pages_manage_clients.php' => 'manage_clients',
        'pages_view_client.php' => 'manage_clients',
        'pages_open_client_acc.php' => 'manage_accounts',
        'pages_open_acc.php' => 'manage_accounts',
        'pages_manage_acc_openings.php' => 'manage_accounts',
        'pages_update_client_accounts.php' => 'manage_accounts',
        'pages_manage_accs.php' => 'manage_accounts',
        'pages_update_accs.php' => 'manage_accounts',
        'pages_check_client_acc_balance.php' => 'manage_accounts',
        'pages_view_client_bank_acc.php' => 'manage_accounts',
        'pages_card_requests.php' => 'review_cards',
        'pages_credit_profiles.php' => 'view_credit_profiles',
        'pages_deposit_money.php' => 'record_deposits',
        'pages_deposits.php' => 'record_deposits',
        'pages_withdraw_money.php' => 'process_withdrawals',
        'pages_withdrawals.php' => 'process_withdrawals',
        'pages_transfer_money.php' => 'process_transfers',
        'pages_transfers.php' => 'process_transfers',
        'pages_fraud_alerts.php' => 'review_risk_alerts',
        'pages_transactions_engine.php' => 'manage_transactions',
        'pages_balance_enquiries.php' => 'view_reports',
        'pages_financial_reporting_deposits.php' => 'view_reports',
        'pages_financial_reporting_transfers.php' => 'view_reports',
        'pages_financial_reporting_withdrawals.php' => 'view_reports',
        'pages_ai_desk.php' => 'view_reports',
    );
    $page = basename(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '');
    if (isset($capability_pages[$page])) {
        $staff_id = (int) $_SESSION['staff_id'];
        $permission_key = $capability_pages[$page];
        try {
            $db = rb_mysqli();
            $stmt = $db->prepare('SELECT is_allowed FROM ib_user_permissions WHERE staff_id = ? AND permission_key = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('is', $staff_id, $permission_key);
                $stmt->execute();
                $stmt->bind_result($is_allowed);
                $has_permission = $stmt->fetch();
                $stmt->close();
                if ($has_permission && (int) $is_allowed !== 1) {
                    rb_fail_page('Your staff account does not have permission to access this service', 403);
                }
            }
        } catch (Throwable $e) {
            error_log('[RuralBanking] staff permissions unavailable: ' . $e->getMessage());
        }
    }
}
