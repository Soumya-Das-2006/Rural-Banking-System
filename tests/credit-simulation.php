<?php
require_once __DIR__ . '/../InternetBanking/core/credit.php';

function expect_credit($condition, $case)
{
    if (!$condition) {
        throw new RuntimeException('Credit simulation test failed: ' . $case);
    }
}

$perfect = array(
    'monthly_income' => '50000.00',
    'monthly_obligations' => '0.00',
    'credit_limit' => '100000.00',
    'credit_used' => '0.00',
    'on_time_pct' => 100,
    'history_months' => 120,
    'recent_inquiries' => 0,
);
$weakest = array(
    'monthly_income' => '0.00',
    'monthly_obligations' => '0.00',
    'credit_limit' => '100000.00',
    'credit_used' => '100000.00',
    'on_time_pct' => 0,
    'history_months' => 0,
    'recent_inquiries' => 10,
);

expect_credit(rb_credit_score($perfect) === 900, 'score reaches the upper bound');
expect_credit(rb_credit_score($weakest) === 300, 'score reaches the lower bound');
expect_credit(rb_credit_utilization_percent($perfect) === 0.0, 'zero utilization is calculated');
expect_credit(rb_credit_utilization_percent(array_merge($perfect, array('credit_limit' => '0.00'))) === null, 'missing credit limit is not divided by zero');
expect_credit(rb_credit_obligation_ratio(array_merge($perfect, array('monthly_income' => '0.00'))) === null, 'zero income has no debt ratio');

$problem = null;
$parsed = rb_credit_profile_parse(array(
    'monthly_income' => '25000',
    'monthly_obligations' => '4000.50',
    'credit_limit' => '100000',
    'credit_used' => '20000',
    'on_time_pct' => '95',
    'history_months' => '36',
    'recent_inquiries' => '2',
), $problem);
expect_credit(is_array($parsed) && $problem === null, 'valid profile is normalized');
expect_credit($parsed['monthly_obligations'] === '4000.50', 'money is normalized to two decimal places');
expect_credit(rb_credit_profiles_equal($parsed, array_merge($parsed, array('monthly_income' => '25000.0'))), 'decimal formatting does not create duplicate history');

$invalid = array(
    'monthly_income' => '-1',
    'monthly_obligations' => '0',
    'credit_limit' => '0',
    'credit_used' => '0',
    'on_time_pct' => '101',
    'history_months' => '0',
    'recent_inquiries' => '0',
);
expect_credit(rb_credit_profile_parse($invalid, $problem) === null && $problem !== null, 'negative amounts are rejected');
$invalid['monthly_income'] = '1';
expect_credit(rb_credit_profile_parse($invalid, $problem) === null && $problem !== null, 'out-of-range repayment rates are rejected');

echo "Credit simulation tests passed.\n";
