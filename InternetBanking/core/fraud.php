<?php
/**
 * Rural Banking System - fraud & anomaly alerts
 * Plain, explainable rules (no AI): every alert says exactly why it was raised.
 * Alerts never block a transaction; they are queued for a staff member to review.
 * Thresholds can be tuned in .env (FRAUD_*).
 * (c) 2026 Soumya Das
 */
if (!defined('RB_BOOTSTRAPPED')) {
    require_once __DIR__ . '/bootstrap.php';
}

function rb_fraud_cfg($key, $default)
{
    return (float) rb_env($key, $default);
}

function rb_fraud_rules()
{
    return array(
        'LARGE_AMOUNT'    => 'Large transaction',
        'LARGE_DEPOSIT'   => 'Large cash deposit',
        'UNUSUAL_AMOUNT'  => 'Unusual amount for this customer',
        'RAPID_ACTIVITY'  => 'Many transactions in a short time',
        'ACCOUNT_DRAIN'   => 'Most of the balance moved at once',
        'NEW_PAYEE_LARGE' => 'Large transfer to a new receiver',
        'ODD_HOURS'       => 'Transaction at an unusual hour',
    );
}

/** Evaluate one freshly posted transaction. Never throws. */
function rb_fraud_scan($db, array $t)
{
    try {
        $type = $t['type'];
        $amt  = (float) $t['amount'];
        $tr   = (int) $t['tr_id'];
        $acc  = (int) $t['account_id'];
        $cid  = (int) $t['client_id'];
        $out  = ($type !== 'Deposit');
        $alerts = array();

        $large = rb_fraud_cfg('FRAUD_LARGE_AMOUNT', 25000);
        if ($out && $amt >= $large) {
            $alerts[] = array('LARGE_AMOUNT', 'High', $type . ' of ' . rb_money($amt) . ' is at or above the large-transaction limit of ' . rb_money($large) . '.');
        }
        $largeDep = rb_fraud_cfg('FRAUD_LARGE_DEPOSIT', 200000);
        if (!$out && $amt >= $largeDep) {
            $alerts[] = array('LARGE_DEPOSIT', 'Medium', 'Cash deposit of ' . rb_money($amt) . ' is at or above ' . rb_money($largeDep) . '. Check the source of funds.');
        }

        if ($out) {
            // statistical: compare with this customer's own history
            $stmt = $db->prepare("SELECT COUNT(*), COALESCE(AVG(transaction_amt),0), COALESCE(STDDEV_POP(transaction_amt),0) FROM ib_transactions WHERE client_id = ? AND tr_type IN ('Withdrawal','Transfer') AND tr_id <> ?");
            $stmt->bind_param('ii', $cid, $tr);
            $stmt->execute();
            $stmt->bind_result($n, $avg, $std);
            $stmt->fetch();
            $stmt->close();
            if ($n >= 5 && $amt > $avg + 3 * $std && $amt > 2 * $avg) {
                $alerts[] = array('UNUSUAL_AMOUNT', 'Medium', 'Amount ' . rb_money($amt) . ' is far above this customer\'s usual ' . rb_money($avg) . ' (based on ' . (int) $n . ' earlier transactions).');
            }

            // velocity: many outgoing transactions from one account within 10 minutes
            $stmt = $db->prepare("SELECT COUNT(*) FROM ib_transactions WHERE account_id = ? AND tr_type IN ('Withdrawal','Transfer') AND created_at > (NOW() - INTERVAL 10 MINUTE)");
            $stmt->bind_param('i', $acc);
            $stmt->execute();
            $stmt->bind_result($recent);
            $stmt->fetch();
            $stmt->close();
            $maxRecent = (int) rb_fraud_cfg('FRAUD_VELOCITY_COUNT', 5);
            if ($recent >= $maxRecent) {
                $alerts[] = array('RAPID_ACTIVITY', 'High', (int) $recent . ' withdrawals/transfers from this account in the last 10 minutes.');
            }

            // account drain
            if ($t['balance_before'] !== null && $amt >= 5000 && $amt >= 0.8 * (float) $t['balance_before']) {
                $alerts[] = array('ACCOUNT_DRAIN', 'High', rb_money($amt) . ' is ' . round(100 * $amt / max((float) $t['balance_before'], 0.01)) . '% of the account balance (' . rb_money($t['balance_before']) . ' before this transaction).');
            }

            // large transfer to a receiver never used before by this customer
            if ($type === 'Transfer' && $amt >= 10000 && $t['receiving_acc_no'] !== '') {
                $stmt = $db->prepare("SELECT COUNT(*) FROM ib_transactions WHERE client_id = ? AND tr_type = 'Transfer' AND receiving_acc_no = ? AND tr_id <> ?");
                $stmt->bind_param('isi', $cid, $t['receiving_acc_no'], $tr);
                $stmt->execute();
                $stmt->bind_result($prev);
                $stmt->fetch();
                $stmt->close();
                if ($prev == 0) {
                    $alerts[] = array('NEW_PAYEE_LARGE', 'Medium', 'First transfer to account ' . $t['receiving_acc_no'] . ' and the amount is ' . rb_money($amt) . '.');
                }
            }

            // odd hours (server time)
            $hour = (int) date('G');
            if ($hour < 5 && $amt >= 5000) {
                $alerts[] = array('ODD_HOURS', 'Low', rb_money($amt) . ' moved at ' . date('h:i A') . ', outside normal banking hours.');
            }
        }

        if ($alerts) {
            $ins = $db->prepare('INSERT IGNORE INTO ib_fraud_alerts (tr_id, account_id, client_id, rule_code, severity, details) VALUES (?,?,?,?,?,?)');
            foreach ($alerts as $a) {
                $ins->bind_param('iiisss', $tr, $acc, $cid, $a[0], $a[1], $a[2]);
                $ins->execute();
            }
            $ins->close();
        }
    } catch (Throwable $e) {
        error_log('[RuralBanking] fraud scan: ' . $e->getMessage());
    }
}

function rb_open_alert_count($db)
{
    try {
        $r = $db->query("SELECT COUNT(*) FROM ib_fraud_alerts WHERE status = 'Open'");
        if ($r) {
            $row = $r->fetch_row();
            return (int) $row[0];
        }
    } catch (Throwable $e) {
    }
    return 0;
}
