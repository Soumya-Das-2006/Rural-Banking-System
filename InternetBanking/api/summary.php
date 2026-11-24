<?php
/**
 * Plain-language monthly summary for the signed-in customer.
 * Numbers come from the database; the AI only rewrites them in the customer's language.
 * Only totals are sent to the AI (no names, account numbers or individual transactions).
 * (c) 2026 Soumya Das
 */
require_once __DIR__ . '/../core/ai.php';
require_once __DIR__ . '/../core/summary.php';

$in = rb_ai_require_ajax();
$actor = rb_ai_actor();
if ($actor['role'] !== 'client') {
    rb_ai_out(array('error' => 'Please sign in as a customer.'), 403);
}
$db   = rb_mysqli();
$lang = rb_ai_lang(isset($in['lang']) ? $in['lang'] : 'en');
$f    = rb_month_figures($db, $actor['id'], isset($in['month']) ? $in['month'] : '');
if ($f === null) {
    rb_ai_out(array('error' => 'Invalid month.'), 400);
}
$english = rb_month_story_en($f);
if ($lang === 'en' || !rb_ai_enabled() || $f['count'] === 0) {
    rb_ai_out(array('summary' => $english, 'fallback' => $lang !== 'en'));
}
if (!rb_ai_rate_limit($db, $actor['key'], 'summary', 15)) {
    rb_ai_out(array('summary' => $english, 'fallback' => true), 429);
}

$names = rb_ai_lang_names();
$facts = "Month: {$f['label']}\nDeposited at branch: " . rb_money($f['deposits']) . "\nReceived from other accounts: " . rb_money($f['received'])
    . "\nWithdrawn: " . rb_money($f['withdrawals']) . "\nSent to others: " . rb_money($f['sent'])
    . "\nNet change: " . ($f['net'] >= 0 ? '+' : '-') . rb_money(abs($f['net'])) . "\nBalance today: " . rb_money($f['balance_now']);
$system = 'You explain a customer\'s monthly account activity in the Bank Of Soumya application to a villager with little schooling. Write 3 to 4 short sentences in '
    . $names[$lang] . ' using simple everyday words. Use ONLY the figures given, exactly as written (keep the rupee sign and digits). '
    . 'Do not invent anything, do not give investment advice. Output only the summary text.';
list($ok, $text) = rb_groq_chat(array(
    array('role' => 'system', 'content' => $system),
    array('role' => 'user', 'content' => $facts),
), array('temperature' => 0.2, 'max_completion_tokens' => 350));

rb_ai_out($ok ? array('summary' => $text, 'fallback' => false) : array('summary' => $english, 'fallback' => true));
