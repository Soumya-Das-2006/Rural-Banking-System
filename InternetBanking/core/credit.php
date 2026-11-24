<?php
/**
 * Customer-entered credit health estimates. This is not a credit-bureau score.
 */
if (!defined('RB_BOOTSTRAPPED')) {
    require_once __DIR__ . '/bootstrap.php';
}

function rb_credit_profile_parse(array $input, &$problem = null)
{
    $problem = null;
    $profile = array();
    $amountFields = array('monthly_income', 'monthly_obligations', 'credit_limit', 'credit_used');
    foreach ($amountFields as $field) {
        if (!isset($input[$field]) || !is_scalar($input[$field])) {
            $problem = 'Complete all credit profile fields.';
            return null;
        }
        $raw = trim((string) $input[$field]);
        if (!preg_match('/^\d{1,12}(\.\d{1,2})?$/D', $raw)) {
            $problem = 'Enter non-negative amounts with up to two decimal places.';
            return null;
        }
        $profile[$field] = number_format((float) $raw, 2, '.', '');
    }

    $integerFields = array(
        'on_time_pct' => array(0, 100),
        'history_months' => array(0, 600),
        'recent_inquiries' => array(0, 20),
    );
    foreach ($integerFields as $field => $range) {
        if (!isset($input[$field]) || !is_scalar($input[$field])) {
            $problem = 'Complete all credit profile fields.';
            return null;
        }
        $value = filter_var(trim((string) $input[$field]), FILTER_VALIDATE_INT);
        if ($value === false || $value < $range[0] || $value > $range[1]) {
            $problem = 'Check the repayment, credit history, and enquiry values.';
            return null;
        }
        $profile[$field] = (int) $value;
    }

    return $profile;
}

function rb_credit_profiles_equal(array $left, array $right)
{
    foreach (array('monthly_income', 'monthly_obligations', 'credit_limit', 'credit_used') as $field) {
        if (number_format((float) $left[$field], 2, '.', '') !== number_format((float) $right[$field], 2, '.', '')) {
            return false;
        }
    }
    foreach (array('on_time_pct', 'history_months', 'recent_inquiries') as $field) {
        if ((int) $left[$field] !== (int) $right[$field]) {
            return false;
        }
    }
    return true;
}

function rb_credit_utilization_percent(array $profile)
{
    $limit = (float) $profile['credit_limit'];
    if ($limit <= 0) {
        return null;
    }
    return round(((float) $profile['credit_used'] / $limit) * 100, 1);
}

function rb_credit_obligation_ratio(array $profile)
{
    $income = (float) $profile['monthly_income'];
    if ($income <= 0) {
        return null;
    }
    return round(((float) $profile['monthly_obligations'] / $income) * 100, 1);
}

function rb_credit_score(array $profile)
{
    $paymentFactor = min(100, max(0, (int) $profile['on_time_pct'])) / 100;
    $utilization = rb_credit_utilization_percent($profile);
    $utilizationFactor = $utilization === null
        ? 0.5
        : 1 - (min(100, max(0, $utilization)) / 100);
    $historyFactor = min(1, max(0, (int) $profile['history_months']) / 120);
    $inquiryFactor = 1 - (min(10, max(0, (int) $profile['recent_inquiries'])) / 10);

    // Educational model only: repayment 40%, utilization 30%, history 20%, enquiries 10%.
    $score = (int) round(300 + 600 * (
        0.40 * $paymentFactor
        + 0.30 * $utilizationFactor
        + 0.20 * $historyFactor
        + 0.10 * $inquiryFactor
    ));
    return min(900, max(300, $score));
}

function rb_credit_recommendations(array $profile)
{
    $recommendations = array();
    $utilization = rb_credit_utilization_percent($profile);

    if ((int) $profile['on_time_pct'] < 100) {
        $recommendations[] = 'Pay every bill by its due date; reminders or automatic payments may help.';
    }
    if ($utilization === null) {
        $recommendations[] = 'Add a credit limit if you have revolving credit so utilization can be estimated.';
    } elseif ($utilization > 30) {
        $recommendations[] = 'Consider reducing revolving balances; lower utilization improves this educational model.';
    }
    if ((int) $profile['history_months'] < 24) {
        $recommendations[] = 'Build a longer record by keeping existing accounts in good standing.';
    }
    if ((int) $profile['recent_inquiries'] >= 3) {
        $recommendations[] = 'Avoid unnecessary credit applications and check your report before applying.';
    }
    if (!$recommendations) {
        $recommendations[] = 'Your entered factors are in strong ranges. Keep reviewing statements and paying on time.';
    }

    return array_slice($recommendations, 0, 4);
}
