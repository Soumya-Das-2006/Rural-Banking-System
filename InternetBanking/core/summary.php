<?php
/**
 * Monthly statement figures for a customer (computed in PHP/SQL, never by the AI)
 * plus a plain-English story. The AI only rephrases these numbers.
 * (c) 2026 Soumya Das
 */
if (!defined('RB_BOOTSTRAPPED')) {
    require_once __DIR__ . '/bootstrap.php';
}

function rb_month_options($count = 12)
{
    $out = array();
    $d = new DateTime('first day of this month');
    for ($i = 0; $i < $count; $i++) {
        $out[$d->format('Y-m')] = $d->format('F Y');
        $d->modify('-1 month');
    }
    return $out;
}

function rb_month_figures($db, $client_id, $ym)
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $ym)) {
        return null;
    }
    $client_id = (int) $client_id;
    $start = $ym . '-01 00:00:00';
    $end   = date('Y-m-d H:i:s', strtotime($start . ' +1 month'));
    $f = array('month' => $ym, 'label' => date('F Y', strtotime($start)), 'deposits' => 0.0, 'withdrawals' => 0.0,
        'sent' => 0.0, 'received' => 0.0, 'count' => 0, 'largest_out' => 0.0, 'net' => 0.0, 'balance_now' => 0.0);

    $stmt = $db->prepare('SELECT tr_type, COUNT(*), COALESCE(SUM(transaction_amt),0), COALESCE(MAX(transaction_amt),0)
                          FROM ib_transactions WHERE client_id = ? AND created_at >= ? AND created_at < ? GROUP BY tr_type');
    $stmt->bind_param('iss', $client_id, $start, $end);
    $stmt->execute();
    $stmt->bind_result($type, $n, $sum, $max);
    while ($stmt->fetch()) {
        $f['count'] += (int) $n;
        if ($type === 'Deposit')    { $f['deposits'] = (float) $sum; }
        if ($type === 'Withdrawal') { $f['withdrawals'] = (float) $sum; $f['largest_out'] = max($f['largest_out'], (float) $max); }
        if ($type === 'Transfer')   { $f['sent'] = (float) $sum; $f['largest_out'] = max($f['largest_out'], (float) $max); }
    }
    $stmt->close();

    $stmt = $db->prepare("SELECT COALESCE(SUM(t.transaction_amt),0), COUNT(*) FROM ib_transactions t
                          JOIN ib_bankaccounts a ON a.account_number = t.receiving_acc_no
                          WHERE a.client_id = ? AND t.tr_type = 'Transfer' AND t.client_id <> ? AND t.created_at >= ? AND t.created_at < ?");
    $stmt->bind_param('iiss', $client_id, $client_id, $start, $end);
    $stmt->execute();
    $stmt->bind_result($recv, $rn);
    $stmt->fetch();
    $stmt->close();
    $f['received'] = (float) $recv;
    $f['count'] += (int) $rn;

    $f['net'] = $f['deposits'] + $f['received'] - $f['withdrawals'] - $f['sent'];
    $f['balance_now'] = rb_client_balance($db, $client_id);
    return $f;
}

function rb_month_story_en(array $f)
{
    if ($f['count'] === 0) {
        return 'There was no activity in ' . $f['label'] . '. Your balance today is ' . rb_money($f['balance_now']) . '.';
    }
    $parts = array();
    if ($f['deposits'] > 0)    { $parts[] = 'you deposited ' . rb_money($f['deposits']); }
    if ($f['received'] > 0)    { $parts[] = 'you received ' . rb_money($f['received']) . ' from other accounts'; }
    if ($f['withdrawals'] > 0) { $parts[] = 'you withdrew ' . rb_money($f['withdrawals']); }
    if ($f['sent'] > 0)        { $parts[] = 'you sent ' . rb_money($f['sent']) . ' to others'; }
    $s = 'In ' . $f['label'] . ', ' . implode(', ', $parts) . '. ';
    $s .= ($f['net'] >= 0 ? 'Your money grew by ' : 'Your money went down by ') . rb_money(abs($f['net'])) . ' over the month. ';
    $s .= 'Your balance today is ' . rb_money($f['balance_now']) . '.';
    return $s;
}
