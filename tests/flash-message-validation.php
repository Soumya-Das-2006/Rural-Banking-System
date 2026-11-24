<?php
require_once __DIR__ . '/../InternetBanking/core/bootstrap.php';

function expect_flash_message($expected, $value, $case)
{
    if (rb_has_flash_message($value) !== $expected) {
        throw new RuntimeException('Flash-message validation failed: ' . $case);
    }
}

expect_flash_message(false, '', 'empty messages are ignored');
expect_flash_message(false, " \t\n", 'whitespace-only messages are ignored');
expect_flash_message(false, null, 'null messages are ignored');
expect_flash_message(false, array('unexpected'), 'non-scalar messages are ignored');
expect_flash_message(true, 'Account updated.', 'non-empty messages are displayed');

echo "Flash-message validation tests passed.\n";
