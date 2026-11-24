<?php
require_once __DIR__ . '/../InternetBanking/core/bootstrap.php';

function expect_account_type_validation($condition, $case)
{
    if (!$condition) {
        throw new RuntimeException('Account type validation failed: ' . $case);
    }
}

expect_account_type_validation(
    rb_account_type_problem('Savings', '<p>Everyday savings</p>', '4', 'ACC-CAT-123456') === null,
    'accepts valid account data and existing HTML descriptions'
);
expect_account_type_validation(
    rb_account_type_problem('Savings', 'Savings account', '100', 'ACC-CAT-123456') === null,
    'accepts maximum rate'
);
expect_account_type_validation(
    rb_account_type_problem('Savings', 'Savings account', '100.0001', 'ACC-CAT-123456') !== null,
    'rejects rates over 100'
);
expect_account_type_validation(
    rb_account_type_problem('Savings', 'Savings account', '4; DROP TABLE ib_acc_types', 'ACC-CAT-123456') !== null,
    'rejects malformed rates'
);
expect_account_type_validation(
    rb_account_type_problem('Savings', 'Savings account', '4', 'bad code!') !== null,
    'rejects malformed account type codes'
);

echo "Account type validation tests passed.\n";
