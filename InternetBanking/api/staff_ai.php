<?php
/**
 * Staff / admin AI tools:
 *   action=customer_summary  -> short internal note about a customer's recent activity
 *   action=draft_notice      -> a draft email for the officer to review
 * Only anonymised statistics are sent to the AI (no name, phone, ID or account number).
 * (c) 2026 Soumya Das
 */
require_once __DIR__ . '/../core/ai.php';

$in    = rb_ai_require_ajax();
$actor = rb_ai_actor();
if ($actor['role'] !== 'staff' && $actor['role'] !== 'admin') {
    rb_ai_out(array('error' => 'Staff sign-in required.'), 403);
}
if (!rb_ai_enabled()) {
    rb_ai_out(array('text' => '', 'error' => 'AI is not switched on. Add GROQ_API_KEY to the server settings.'), 503);
}
$db     = rb_mysqli();
$lang   = rb_ai_lang(isset($in['lang']) ? $in['lang'] : 'en');
$names  = rb_ai_lang_names();
$action = isset($in['action']) ? (string) $in['action'] : '';
if (!rb_ai_rate_limit($db, $actor['key'], 'staffai', 30)) {
    rb_ai_out(array('error' => 'Too many requests. Please wait a few minutes.'), 429);
}

if ($action === 'customer_summary') {
    $cid = (int) (isset($in['client_id']) ? $in['client_id'] : 0);
    $stmt = $db->prepare('SELECT COUNT(*) FROM ib_clients WHERE client_id = ?');
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $stmt->bind_result($exists);
    $stmt->fetch();
    $stmt->close();
    if (!$exists) {
        rb_ai_out(array('error' => 'Customer not found.'), 404);
    }

    // accounts
    $stmt = $db->prepare('SELECT acc_type, acc_status, created_at FROM ib_bankaccounts WHERE client_id = ?');
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $rs = $stmt->get_result();
    $accs = array();
    while ($a = $rs->fetch_assoc()) {
        $accs[] = $a['acc_type'] . ' (' . $a['acc_status'] . ')';
    }
    $stmt->close();

    // last 90 days
    $stmt = $db->prepare("SELECT tr_type, COUNT(*), COALESCE(SUM(transaction_amt),0), COALESCE(MAX(transaction_amt),0), MAX(created_at)
                          FROM ib_transactions WHERE client_id = ? AND created_at > (NOW() - INTERVAL 90 DAY) GROUP BY tr_type");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $stmt->bind_result($tt, $tn, $ts, $tm, $tl);
    $lines = array();
    $last = '';
    while ($stmt->fetch()) {
        $lines[] = "$tt: $tn transactions, total " . rb_money($ts) . ", largest " . rb_money($tm);
        if ($tl > $last) { $last = $tl; }
    }
    $stmt->close();

    $stmt = $db->prepare('SELECT rule_code, severity, status, COUNT(*) FROM ib_fraud_alerts WHERE client_id = ? GROUP BY rule_code, severity, status');
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $stmt->bind_result($rc, $sev, $st, $cn);
    $al = array();
    while ($stmt->fetch()) {
        $al[] = "$cn x " . (isset(rb_fraud_rules()[$rc]) ? rb_fraud_rules()[$rc] : $rc) . " ($sev, $st)";
    }
    $stmt->close();

    $facts = 'Number of accounts: ' . count($accs) . "\nAccount types and status: " . ($accs ? implode(', ', $accs) : 'none')
        . "\nCombined balance now: " . rb_money(rb_client_balance($db, $cid))
        . "\nActivity in the last 90 days:\n" . ($lines ? implode("\n", $lines) : 'No transactions')
        . "\nMost recent transaction: " . ($last ?: 'none in 90 days')
        . "\nFraud-rule alerts on record: " . ($al ? implode('; ', $al) : 'none');
    $system = 'You help a Bank Of Soumya staff member. From the anonymised facts, write a brief internal customer note in ' . $names[$lang]
        . ' with three short labelled parts: "Overview", "Notable patterns", "Suggested follow-up". Maximum 120 words. '
        . 'Use ONLY the facts given. Rule-based alerts are indicators, not proof: never accuse the customer. No personal advice.';
    list($ok, $text) = rb_groq_chat(array(
        array('role' => 'system', 'content' => $system),
        array('role' => 'user', 'content' => $facts),
    ), array('temperature' => 0.2, 'max_completion_tokens' => 450));
    rb_ai_out($ok ? array('text' => $text) : array('error' => 'The AI could not respond. Try again.'), $ok ? 200 : 502);
}

if ($action === 'draft_notice') {
    $kinds = array(
        'welcome'    => 'a warm welcome message to a new customer who has just opened an account',
        'low_balance' => 'a polite reminder that the account balance is low',
        'activation' => 'a notice that the customer\'s account is now active and how to use it',
        'kyc'        => 'a reminder to bring identity documents to the branch to complete KYC',
        'suspicious' => 'a careful message asking the customer to visit or call the branch to confirm a recent transaction (do not accuse)',
        'general'    => 'a clear general notice based on the officer\'s note',
    );
    $kind = isset($in['kind']) ? (string) $in['kind'] : '';
    if (!isset($kinds[$kind])) {
        rb_ai_out(array('error' => 'Choose a notice type.'), 400);
    }
    $note = trim((string) (isset($in['note']) ? $in['note'] : ''));
    if (mb_strlen($note) > 400) {
        rb_ai_out(array('error' => 'Note is too long (400 characters).'), 400);
    }
    $system = 'You write email notices for a Bank Of Soumya staff member. Write ' . $kinds[$kind] . ' as a concise, respectful email in ' . $names[$lang]
        . ', in simple respectful words. Use the placeholders [Customer Name], [Account Number], [Branch] and [Officer Name] exactly as written; '
        . 'do not invent amounts, dates, phone numbers or names. Do not claim external payment settlement, verified bureau data or regulatory authorization. '
        . 'Include a short subject line and email body. Output only the email text.';
    $user = $note !== '' ? 'Officer\'s note to include: ' . $note : 'No extra note.';
    list($ok, $text) = rb_groq_chat(array(
        array('role' => 'system', 'content' => $system),
        array('role' => 'user', 'content' => $user),
    ), array('temperature' => 0.4, 'max_completion_tokens' => 500));
    rb_ai_out($ok ? array('text' => $text) : array('error' => 'The AI could not respond. Try again.'), $ok ? 200 : 502);
}

rb_ai_out(array('error' => 'Unknown action.'), 400);
