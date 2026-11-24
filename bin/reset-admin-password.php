<?php
/**
 * Recover administrator access from the command line.
 *   php bin/reset-admin-password.php admin@mail.com 'NewStrongPass1'
 * (c) 2026 Soumya Das
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../InternetBanking/core/bootstrap.php';

if ($argc < 3) {
    fwrite(STDERR, "Usage: php bin/reset-admin-password.php <admin-email> <new-password>\n");
    exit(1);
}
list(, $email, $password) = $argv;
if (($problem = rb_password_problem($password)) !== null) {
    fwrite(STDERR, $problem . "\n");
    exit(1);
}
$db = rb_mysqli();
$hash = rb_hash_password($password);
$stmt = $db->prepare('UPDATE ib_admin SET password = ? WHERE email = ?');
$stmt->bind_param('ss', $hash, $email);
$stmt->execute();
echo $stmt->affected_rows > 0 ? "Password updated for $email\n" : "No administrator found with that email.\n";
