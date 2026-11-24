<?php
require_once __DIR__ . '/../InternetBanking/core/cards.php';

function expect_demo_card($condition, $case)
{
    if (!$condition) {
        throw new RuntimeException('Demo card test failed: ' . $case);
    }
}

$validAmount = rb_demo_card_parse_money('1250.5');
expect_demo_card($validAmount === '1250.50', 'amount is normalized to two decimal places');
expect_demo_card(rb_demo_card_parse_money('0') === null, 'zero transaction amounts are rejected');
expect_demo_card(rb_demo_card_parse_money('-1') === null, 'negative amounts are rejected');
expect_demo_card(rb_demo_card_parse_money('10000000.01') === null, 'amount above the sandbox maximum is rejected');
expect_demo_card(rb_demo_card_parse_limit('0') === '0.00', 'zero disables a channel limit');
expect_demo_card(rb_demo_card_parse_limit('100000') === '100000.00', 'configured limit at the cap is accepted');
expect_demo_card(rb_demo_card_parse_limit('100000.01') === null, 'configured limit above the cap is rejected');
expect_demo_card((bool) preg_match('/^DEMO-RB-[A-F0-9]{24}$/D', rb_demo_card_reference()), 'generated reference is visibly fictional and not a payment-card number');

$now = strtotime('2026-01-15 12:00:00');
$card = array(
    'card_status' => 'active',
    'expiry_date' => '2027-01-15',
    'frozen_until' => null,
    'atm_daily_limit' => '1000.00',
    'pos_transaction_limit' => '500.00',
    'online_transaction_limit' => '250.00',
);

$decision = rb_demo_card_authorization_decision($card, 'atm', 200, 800, $now);
expect_demo_card($decision['result'] === 'approved', 'ATM authorization at the remaining daily limit is approved');
$decision = rb_demo_card_authorization_decision($card, 'atm', 200.01, 800, $now);
expect_demo_card($decision['result'] === 'declined', 'ATM authorization above the daily limit is declined');
$decision = rb_demo_card_authorization_decision($card, 'pos', 500.01, 0, $now);
expect_demo_card($decision['result'] === 'declined', 'POS authorization above its per-transaction limit is declined');
$decision = rb_demo_card_authorization_decision($card, 'online', 250, 0, $now);
expect_demo_card($decision['result'] === 'approved', 'online authorization within its per-transaction limit is approved');
$decision = rb_demo_card_authorization_decision(array_merge($card, array('card_status' => 'blocked')), 'pos', 1, 0, $now);
expect_demo_card($decision['result'] === 'declined', 'blocked cards are declined');
$decision = rb_demo_card_authorization_decision(array_merge($card, array('archived_at' => '2026-01-15 12:00:00')), 'pos', 1, 0, $now);
expect_demo_card($decision['result'] === 'declined', 'archived cards are declined');
$decision = rb_demo_card_authorization_decision(array_merge($card, array('frozen_until' => '2026-01-15 13:00:00')), 'pos', 1, 0, $now);
expect_demo_card($decision['result'] === 'declined', 'temporarily frozen cards are declined');
$decision = rb_demo_card_authorization_decision(array_merge($card, array('expiry_date' => '2026-01-14')), 'online', 1, 0, $now);
expect_demo_card($decision['result'] === 'declined', 'expired cards are declined');
$decision = rb_demo_card_authorization_decision(array_merge($card, array('pos_transaction_limit' => '0.00')), 'pos', 1, 0, $now);
expect_demo_card($decision['result'] === 'declined', 'zero POS limit disables POS authorizations');

echo "Demo card management tests passed.\n";
