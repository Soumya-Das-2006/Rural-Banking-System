<?php
require_once __DIR__ . '/../InternetBanking/core/bootstrap.php';

function expect_privacy_activity($condition, $case)
{
    if (!$condition) {
        throw new RuntimeException('Privacy/activity test failed: ' . $case);
    }
}

putenv('PUBLIC_BASE_URL=https://example.test');
expect_privacy_activity(rb_public_base_url() === 'https://example.test', 'accept an HTTPS canonical origin');
putenv('PUBLIC_BASE_URL=http://insecure.example');
expect_privacy_activity(rb_public_base_url() === 'https://bankofsoumya.xo.je', 'reject a non-HTTPS canonical origin');

putenv('GA4_MEASUREMENT_ID=G-ABC12345');
expect_privacy_activity(rb_ga4_measurement_id() === 'G-ABC12345', 'accept a valid GA4 measurement ID');
putenv('GA4_MEASUREMENT_ID=secret-value');
expect_privacy_activity(rb_ga4_measurement_id() === '', 'reject an invalid analytics identifier');

$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';
$_SERVER['HTTP_CF_IPCOUNTRY'] = 'IN';
putenv('TRUSTED_PROXY_IPS=');
expect_privacy_activity(rb_client_ip() === '198.51.100.10', 'ignore untrusted forwarded IP headers');
expect_privacy_activity(rb_login_location() === 'Unavailable', 'ignore untrusted location headers');

$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.7';
putenv('TRUSTED_PROXY_IPS=192.0.2.10');
expect_privacy_activity(rb_client_ip() === '203.0.113.7', 'accept client IP from configured trusted proxy');
expect_privacy_activity(rb_login_location() === 'Country: IN', 'accept country from configured trusted proxy');

expect_privacy_activity(rb_spam_honeypot_is_clear(array()), 'accept an absent honeypot field');
expect_privacy_activity(rb_spam_honeypot_is_clear(array('_rb_contact' => '')), 'accept an empty honeypot field');
expect_privacy_activity(!rb_spam_honeypot_is_clear(array('_rb_contact' => 'bot')), 'reject a filled honeypot field');
expect_privacy_activity(!rb_spam_honeypot_is_clear(array('_rb_contact' => array('bot'))), 'reject a non-scalar honeypot field');
expect_privacy_activity(
    rb_attempt_login(null, 'client', array('not-an-email'), 'password')[0] === false,
    'reject structured login input without reaching the database'
);

echo "Privacy and activity validation tests passed.\n";
