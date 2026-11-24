<?php
require_once __DIR__ . '/../InternetBanking/core/bootstrap.php';

function expect_validation($condition, $case)
{
    if (!$condition) {
        throw new RuntimeException('Validation test failed: ' . $case);
    }
}

$validFixture = '999999999999';
expect_validation(rb_is_valid_aadhaar($validFixture), 'accept 12-digit number with valid checksum');
expect_validation(!rb_is_valid_aadhaar('999999999998'), 'reject invalid checksum');
expect_validation(!rb_is_valid_aadhaar('99999999999'), 'reject 11 digits');
expect_validation(!rb_is_valid_aadhaar('9999999999999'), 'reject 13 digits');
expect_validation(!rb_is_valid_aadhaar('099999999999'), 'reject leading zero');
expect_validation(!rb_is_valid_aadhaar('199999999999'), 'reject leading one');
expect_validation(!rb_is_valid_aadhaar('99999999999A'), 'reject non-digit');
expect_validation(!rb_is_valid_aadhaar('9999 9999 9999'), 'reject spaces');

expect_validation(
    rb_client_details_problem('Test Client', $validFixture, '+91 98765 43210', 'test@example.invalid', 'Village') === null,
    'accept valid client details'
);
expect_validation(
    rb_client_details_problem(str_repeat('N', 100), $validFixture, '1234567', 'test@example.invalid', str_repeat('A', 250)) === null,
    'accept maximum field lengths'
);
expect_validation(
    rb_client_details_problem('Test Client', '999999999998', '+91 98765 43210', 'test@example.invalid', 'Village') !== null,
    'reject invalid Aadhaar in client details'
);
expect_validation(
    rb_client_details_problem(str_repeat('N', 101), $validFixture, '+91 98765 43210', 'test@example.invalid', 'Village') !== null,
    'reject overlong name'
);
expect_validation(
    rb_client_details_problem('Test Client', $validFixture, 'not-a-phone', 'test@example.invalid', 'Village') !== null,
    'reject invalid phone'
);
expect_validation(
    rb_client_details_problem('Test Client', $validFixture, '123456', 'test@example.invalid', 'Village') !== null,
    'reject too-short phone'
);
expect_validation(
    rb_client_details_problem('Test Client', $validFixture, '+91 98765 43210', 'not-an-email', 'Village') !== null,
    'reject invalid email'
);
expect_validation(
    rb_client_details_problem('Test Client', $validFixture, '+91 98765 43210', 'test@example.invalid', '') !== null,
    'reject empty address'
);
expect_validation(
    rb_client_details_problem('Test Client', $validFixture, '+91 98765 43210', 'test@example.invalid', str_repeat('A', 251)) !== null,
    'reject overlong address'
);

echo "Aadhaar and client-details validation tests passed.\n";
