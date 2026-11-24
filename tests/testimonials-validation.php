<?php
require_once __DIR__ . '/../InternetBanking/core/testimonials.php';

function expect_testimonial($condition, $case)
{
    if (!$condition) {
        throw new RuntimeException('Testimonial validation failed: ' . $case);
    }
}

$valid = array(
    'A customer-approved testimonial.',
    'A. Customer',
    '',
    'published',
    0,
    true,
);
expect_testimonial(rb_testimonial_input_error(...$valid) === '', 'accept a consent-confirmed published testimonial');

$without_consent = $valid;
$without_consent[5] = false;
expect_testimonial(rb_testimonial_input_error(...$without_consent) !== '', 'reject publication without customer permission');

$hidden_status = $valid;
$hidden_status[3] = 'hidden';
expect_testimonial(rb_testimonial_input_error(...$hidden_status) !== '', 'require hiding to use the consent-clearing workflow');

$too_long = $valid;
$too_long[0] = str_repeat('x', 1201);
expect_testimonial(rb_testimonial_input_error(...$too_long) !== '', 'reject oversized quotes');

$bad_order = $valid;
$bad_order[4] = 10000;
expect_testimonial(rb_testimonial_input_error(...$bad_order) !== '', 'reject out-of-range display order');

$control_character = $valid;
$control_character[1] = "Customer\nName";
expect_testimonial(rb_testimonial_input_error(...$control_character) !== '', 'reject control characters in attribution');

echo "Testimonial validation tests passed.\n";
