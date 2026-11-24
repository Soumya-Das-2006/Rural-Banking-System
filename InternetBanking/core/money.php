<?php
/**
 * Rural Banking System — money engine
 *
 * One audited code path for every deposit, withdrawal and transfer.
 *  - amounts are validated server side (positive, 2 decimals, per-transaction cap)
 *  - account details come from the database, never from the browser
 *  - customers can only operate on their own accounts
 *  - everything runs inside a database transaction with row locks
 *  - balance = deposits - withdrawals - transfers out + transfers in
 *
 * (c) 2026 Soumya Das
 */

if (!defined('RB_BOOTSTRAPPED')) {
    require_once __DIR__ . '/bootstrap.php';
}

require_once __DIR__ . '/fraud.php';
require_once __DIR__ . '/email.php';

if (!defined('RB_MAX_TXN')) {
    define('RB_MAX_TXN', 10000000.00); // per-transaction ceiling: 1 crore
}

function rb_money_parse($raw)
{
    if (!is_scalar($raw)) {
        return null;
    }
    $s = str_replace(',', '', trim((string) $raw));
    if (!preg_match('/^\d{1,12}(\.\d{1,2})?$/', $s)) {
        return null;
    }
    $v = round((float) $s, 2);
    if ($v <= 0 || $v > RB_MAX_TXN) {
        return null;
    }
    return $v;
}

function rb_result($ok, $msg, array $extra = array())
{
    return array_merge(array('ok' => (bool) $ok, 'msg' => $msg), $extra);
}

/** Current balance of one account (does not lock). */
function rb_account_balance($db, $account_id)
{
    $account_id = (int) $account_id;
    $stmt = $db->prepare('SELECT account_number FROM ib_bankaccounts WHERE account_id = ?');
    $stmt->bind_param('i', $account_id);
    $stmt->execute();
    $stmt->bind_result($acc_no);
    $found = $stmt->fetch();
    $stmt->close();
    if (!$found) {
        return 0.0;
    }

    $sql = "SELECT
                COALESCE(SUM(CASE WHEN tr_type = 'Deposit'    THEN transaction_amt END), 0),
                COALESCE(SUM(CASE WHEN tr_type = 'Withdrawal' THEN transaction_amt END), 0),
                COALESCE(SUM(CASE WHEN tr_type = 'Transfer'   THEN transaction_amt END), 0)
            FROM ib_transactions WHERE account_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $account_id);
    $stmt->execute();
    $stmt->bind_result($dep, $wd, $out);
    $stmt->fetch();
    $stmt->close();

    $stmt = $db->prepare("SELECT COALESCE(SUM(transaction_amt), 0) FROM ib_transactions WHERE tr_type = 'Transfer' AND receiving_acc_no = ?");
    $stmt->bind_param('s', $acc_no);
    $stmt->execute();
    $stmt->bind_result($in);
    $stmt->fetch();
    $stmt->close();

    return round((float) $dep - (float) $wd - (float) $out + (float) $in, 2);
}

/** Combined balance across all accounts owned by a customer. */
function rb_client_balance($db, $client_id)
{
    $client_id = (int) $client_id;
    $ids = array();
    $stmt = $db->prepare('SELECT account_id FROM ib_bankaccounts WHERE client_id = ?');
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $stmt->bind_result($aid);
    while ($stmt->fetch()) {
        $ids[] = $aid;
    }
    $stmt->close();
    $total = 0.0;
    foreach ($ids as $aid) {
        $total += rb_account_balance($db, $aid);
    }
    return round($total, 2);
}

function rb_txn_code()
{
    return 'TXN' . date('ymd') . strtoupper(bin2hex(random_bytes(6)));
}

/**
 * Post a deposit, withdrawal or transfer.
 *
 * @param mysqli $db
 * @param array  $o  role (admin|staff|client), actor_client_id, type, account_id,
 *                   amount, receiving_acc_no (transfers only)
 * @return array     ['ok' => bool, 'msg' => string]
 */
function rb_post_transaction($db, array $o)
{
    $type = isset($o['type']) ? $o['type'] : '';
    $channel = isset($o['channel']) ? $o['channel'] : 'standard';
    if (!in_array($type, array('Deposit', 'Withdrawal', 'Transfer'), true)) {
        return rb_result(false, 'Invalid transaction type.');
    }
    if (!in_array($channel, array('standard', 'upi_qr'), true)
        || ($channel === 'upi_qr' && ($type !== 'Transfer' || !isset($o['role']) || $o['role'] !== 'client'))) {
        return rb_result(false, 'Invalid transaction channel.');
    }
    $role = isset($o['role']) ? $o['role'] : '';
    if (!in_array($role, array('admin', 'staff', 'client'), true)) {
        return rb_result(false, 'Not permitted.');
    }
    if ($role === 'client' && $type === 'Deposit') {
        return rb_result(false, 'Deposits are recorded by bank staff at the branch.');
    }
    $amount = rb_money_parse(isset($o['amount']) ? $o['amount'] : null);
    if ($amount === null) {
        return rb_result(false, 'Enter a valid amount greater than zero (up to 2 decimals, maximum ' . rb_money(RB_MAX_TXN) . ').');
    }
    $account_id      = (int) (isset($o['account_id']) ? $o['account_id'] : 0);
    $actor_client_id = (int) (isset($o['actor_client_id']) ? $o['actor_client_id'] : 0);

    $began = false;
    try {
        $db->begin_transaction();
        $began = true;

        $stmt = $db->prepare('SELECT account_id, acc_name, account_number, acc_type, acc_status, client_id, client_name, client_national_id, client_phone, client_email
                              FROM ib_bankaccounts WHERE account_id = ? FOR UPDATE');
        $stmt->bind_param('i', $account_id);
        $stmt->execute();
        $acc = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$acc) {
            $db->rollback();
            return rb_result(false, 'Account not found.');
        }
        if ($role === 'client' && (int) $acc['client_id'] !== $actor_client_id) {
            $db->rollback();
            return rb_result(false, 'You are not allowed to operate on this account.');
        }
        if (strcasecmp(trim($acc['acc_status']), 'Active') !== 0) {
            $db->rollback();
            return rb_result(false, 'This account is not active.');
        }

        $recv_no = '';
        $recv_name = '';
        $recv_holder = '';
        $recv_email  = '';
        if ($type === 'Transfer') {
            $recv_no = trim((string) (isset($o['receiving_acc_no']) ? $o['receiving_acc_no'] : ''));
            if ($recv_no === '') {
                $db->rollback();
                return rb_result(false, 'Enter the receiving account number.');
            }
            if ($recv_no === $acc['account_number']) {
                $db->rollback();
                return rb_result(false, 'You cannot transfer money to the same account.');
            }
            $stmt = $db->prepare('SELECT account_id, client_id, acc_name, client_name, client_email, acc_status FROM ib_bankaccounts WHERE account_number = ? LIMIT 1 FOR UPDATE');
            $stmt->bind_param('s', $recv_no);
            $stmt->execute();
            $recv = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$recv) {
                $db->rollback();
                return rb_result(false, 'The receiving account was not found. Check the account number.');
            }
            if (strcasecmp(trim($recv['acc_status']), 'Active') !== 0) {
                $db->rollback();
                return rb_result(false, 'The receiving account is not active.');
            }
            $recv_name   = $recv['acc_name'];
            $recv_holder = $recv['client_name'];
            $recv_email  = $recv['client_email'];
        }

        $balance = null;
        if ($type !== 'Deposit') {
            $balance = rb_account_balance($db, $acc['account_id']);
            if ($amount > $balance + 0.00001) {
                $db->rollback();
                return rb_result(false, 'Insufficient funds. Available balance is ' . rb_money($balance) . '.');
            }
        }

        $code   = rb_txn_code();
        $status = 'Success';
        $amt    = number_format($amount, 2, '.', '');
        $aid    = (int) $acc['account_id'];
        $cid    = (int) $acc['client_id'];

        $stmt = $db->prepare('INSERT INTO ib_transactions
            (tr_code, account_id, acc_name, account_number, acc_type, tr_type, tr_status, tr_channel, client_id, client_name,
             client_national_id, transaction_amt, client_phone, receiving_acc_no, receiving_acc_name, receiving_acc_holder)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->bind_param(
            'sissssssisssssss',
            $code, $aid, $acc['acc_name'], $acc['account_number'], $acc['acc_type'], $type, $status, $channel, $cid,
            $acc['client_name'], $acc['client_national_id'], $amt, $acc['client_phone'], $recv_no, $recv_name, $recv_holder
        );
        $stmt->execute();
        $tr_id = (int) $db->insert_id;
        $stmt->close();

        if ($type === 'Deposit') {
            $note = $acc['client_name'] . ' deposited ' . rb_money($amount) . ' into account ' . $acc['account_number'];
            $done = 'Deposit of ' . rb_money($amount) . ' recorded successfully.';
        } elseif ($type === 'Withdrawal') {
            $note = $acc['client_name'] . ' withdrew ' . rb_money($amount) . ' from account ' . $acc['account_number'];
            $done = 'Withdrawal of ' . rb_money($amount) . ' completed.';
        } else {
            $note = $acc['client_name'] . ' transferred ' . rb_money($amount) . ' from account ' . $acc['account_number'] . ' to ' . $recv_no;
            $done = rb_money($amount) . ' transferred to ' . $recv_holder . ' (' . $recv_no . ').';
        }
        $stmt = $db->prepare('INSERT INTO ib_notifications (notification_details) VALUES (?)');
        $stmt->bind_param('s', $note);
        $stmt->execute();
        $stmt->close();

        $db->commit();
        $notificationBody = "Bank Of Soumya transaction notification\n\n"
            . 'Type: ' . $type . "\n"
            . 'Amount: ' . rb_money($amount) . "\n"
            . 'Reference: ' . $code . "\n\n"
            . "This is simulated activity in the Bank Of Soumya demo. No external payment was made.";
        $emailNotifications = array();
        if (rb_email_valid_address($acc['client_email'])) {
            $emailNotifications[] = array(
                'address' => $acc['client_email'],
                'client_id' => $cid,
                'subject' => 'Transaction notification - ' . $code,
                'body' => $notificationBody,
            );
        }
        if ($type === 'Transfer' && rb_email_valid_address($recv_email)) {
            $emailNotifications[] = array(
                'address' => $recv_email,
                'client_id' => (int) $recv['client_id'],
                'subject' => 'Incoming transfer notification - ' . $code,
                'body' => "Bank Of Soumya transfer notification\n\n"
                    . 'Amount: ' . rb_money($amount) . "\n"
                    . 'Reference: ' . $code . "\n\n"
                    . "This transfer was recorded in the Bank Of Soumya application ledger.",
            );
        }
        foreach ($emailNotifications as $notification) {
            try {
                rb_email_queue($db, $notification['address'], $notification['subject'], $notification['body'], $notification['client_id']);
            } catch (Throwable $mailError) {
                error_log('[RuralBanking] transaction email could not be queued: ' . $mailError->getMessage());
            }
        }
        if ($type === 'Transfer' && $role === 'client' && (int) $recv['client_id'] !== $cid) {
            $points = min(250, (int) floor($amount / 100));
            if ($points > 0) {
                try {
                    $reason = 'external_transfer';
                    $stmt = $db->prepare('INSERT IGNORE INTO ib_rewards (client_id, points_delta, reason, reference_code) VALUES (?, ?, ?, ?)');
                    if (!$stmt) {
                        throw new RuntimeException('Could not prepare simulated reward entry: ' . $db->error);
                    }
                    $stmt->bind_param('iiss', $cid, $points, $reason, $code);
                    if (!$stmt->execute()) {
                        throw new RuntimeException('Could not record simulated reward points.');
                    }
                    $stmt->close();
                } catch (Throwable $rewardError) {
                    error_log('[RuralBanking] reward points could not be recorded: ' . $rewardError->getMessage());
                }
            }
        }
        rb_fraud_scan($db, array(
            'tr_id' => $tr_id, 'account_id' => $aid, 'client_id' => $cid, 'type' => $type,
            'amount' => $amount, 'receiving_acc_no' => $recv_no, 'balance_before' => $balance,
        ));
        return rb_result(true, $done, array('code' => $code, 'amount' => $amount));
    } catch (Throwable $e) {
        error_log('[RuralBanking] transaction failed: ' . $e->getMessage());
        if ($began) {
            try {
                $db->rollback();
            } catch (Throwable $e2) {
            }
        }
        return rb_result(false, 'The transaction could not be completed. Nothing was changed. Please try again.');
    }
}
