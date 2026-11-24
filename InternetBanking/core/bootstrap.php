<?php
/**
 * Rural Banking System — application bootstrap
 *
 * Loaded by every portal through conf/config.php and conf/pdoconfig.php.
 * Responsibilities: environment config, error policy, security headers,
 * CSRF protection, password hashing, login throttling, shared helpers.
 *
 * (c) 2026 Soumya Das
 */

if (defined('RB_BOOTSTRAPPED')) {
    return;
}
define('RB_BOOTSTRAPPED', true);
define('RB_CORE', __DIR__);
define('RB_ROOT', dirname(__DIR__, 2));
define('RB_VERSION', '3.0.0');
define('RB_CURRENCY', "\u{20B9}");

/* ------------------------------------------------------------------ *
 * 1. Environment (.env file is optional; real env vars win)
 * ------------------------------------------------------------------ */
function rb_load_env($file)
{
    if (!is_readable($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        list($k, $v) = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $current = $k !== '' ? getenv($k) : false;
        if ($k !== '' && ($current === false || $current === '')) {
            $_ENV[$k] = $v;
            if (function_exists('putenv')) {
                putenv($k . '=' . $v);
            }
        }
    }
}

function rb_env($key, $default = null)
{
    $v = getenv($key);
    if ($v !== false && $v !== '') {
        return $v;
    }
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }
    return $default;
}

function rb_public_base_url()
{
    $base = trim((string) rb_env('PUBLIC_BASE_URL', 'https://bankofsoumya.xo.je'));
    $parts = parse_url($base);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
        || strtolower($parts['scheme']) !== 'https'
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
    ) {
        return 'https://bankofsoumya.xo.je';
    }
    return rtrim($base, '/');
}

function rb_ga4_measurement_id()
{
    $id = trim((string) rb_env('GA4_MEASUREMENT_ID', ''));
    return preg_match('/^G-[A-Z0-9]{6,20}$/iD', $id) === 1 ? $id : '';
}

function rb_is_valid_aadhaar($value)
{
    if (!is_string($value) || preg_match('/^[2-9][0-9]{11}$/D', $value) !== 1) {
        return false;
    }

    $multiplication = array(
        array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9),
        array(1, 2, 3, 4, 0, 6, 7, 8, 9, 5),
        array(2, 3, 4, 0, 1, 7, 8, 9, 5, 6),
        array(3, 4, 0, 1, 2, 8, 9, 5, 6, 7),
        array(4, 0, 1, 2, 3, 9, 5, 6, 7, 8),
        array(5, 9, 8, 7, 6, 0, 4, 3, 2, 1),
        array(6, 5, 9, 8, 7, 1, 0, 4, 3, 2),
        array(7, 6, 5, 9, 8, 2, 1, 0, 4, 3),
        array(8, 7, 6, 5, 9, 3, 2, 1, 0, 4),
        array(9, 8, 7, 6, 5, 4, 3, 2, 1, 0),
    );
    $permutation = array(
        array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9),
        array(1, 5, 7, 6, 2, 8, 3, 0, 9, 4),
        array(5, 8, 0, 3, 7, 9, 6, 1, 4, 2),
        array(8, 9, 1, 6, 0, 4, 3, 5, 2, 7),
        array(9, 4, 5, 3, 1, 2, 6, 8, 7, 0),
        array(4, 2, 8, 6, 5, 7, 3, 9, 0, 1),
        array(2, 7, 9, 3, 8, 0, 6, 4, 1, 5),
        array(7, 0, 4, 6, 9, 1, 3, 2, 5, 8),
    );

    $checksum = 0;
    $digits = strrev($value);
    for ($i = 0, $length = strlen($digits); $i < $length; $i++) {
        $checksum = $multiplication[$checksum][$permutation[$i % 8][(int) $digits[$i]]];
    }
    return $checksum === 0;
}

function rb_client_details_problem($name, $national_id, $phone, $email, $address)
{
    $problem = rb_client_profile_problem($name, $phone, $email, $address);
    if ($problem !== null) {
        return $problem;
    }
    if (!rb_is_valid_aadhaar($national_id)) {
        return 'Enter a valid 12-digit Aadhaar number.';
    }
    return null;
}

function rb_client_profile_problem($name, $phone, $email, $address)
{
    if ($name === '' || strlen($name) > 100) {
        return 'Enter the client name (up to 100 characters).';
    }
    if (preg_match('/^\+?[0-9][0-9 -]{6,14}$/D', $phone) !== 1
        || preg_match_all('/[0-9]/', $phone) < 7
        || preg_match_all('/[0-9]/', $phone) > 15) {
        return 'Enter a valid phone number.';
    }
    if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Enter a valid email address.';
    }
    if ($address === '' || strlen($address) > 250) {
        return 'Enter the client address (up to 250 characters).';
    }
    return null;
}

function rb_account_type_problem($name, $description, $rate, $code)
{
    if ($name === '' || strlen($name) > 200 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
        return 'Enter an account type name of up to 200 characters.';
    }
    if ($description === '' || strlen($description) > 2000
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $description)) {
        return 'Enter an account description of up to 2,000 characters.';
    }
    if (!preg_match('/^\d{1,3}(?:\.\d{1,4})?$/D', $rate) || (float) $rate > 100) {
        return 'Enter an annual rate between 0 and 100 with up to 4 decimal places.';
    }
    if (!preg_match('/^[A-Za-z0-9-]{4,100}$/D', $code)) {
        return 'Enter a valid account type code.';
    }
    return null;
}

rb_load_env(RB_ROOT . '/.env');

define('RB_DEBUG', strtolower((string) rb_env('APP_ENV', 'production')) === 'development');

if (RB_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
}
date_default_timezone_set(rb_env('APP_TIMEZONE', 'Asia/Kolkata'));

/* ------------------------------------------------------------------ *
 * 2. Small helpers
 * ------------------------------------------------------------------ */
function rb_e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rb_has_flash_message($value)
{
    return is_scalar($value) && trim((string) $value) !== '';
}

function rb_money($value)
{
    return RB_CURRENCY . ' ' . number_format((float) $value, 2);
}

function rb_fail_page($message, $code = 503)
{
    http_response_code($code);
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Bank Of Soumya</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f6f2e9;'
        . 'font-family:system-ui,Segoe UI,Roboto,sans-serif;color:#17332a}div{max-width:420px;padding:36px;text-align:center}'
        . 'h1{font-size:22px;margin:0 0 10px}p{margin:0;color:#4d6258;line-height:1.6}</style></head><body><div>'
        . '<h1>' . rb_e($message) . '</h1><p>Please try again in a moment. If the problem continues, contact the system administrator.</p>'
        . '</div></body></html>';
    exit;
}

/* ------------------------------------------------------------------ *
 * 3. Database
 * ------------------------------------------------------------------ */
function rb_db_settings()
{
    return array(
        'host' => rb_env('DB_HOST', 'localhost'),
        'port' => (int) rb_env('DB_PORT', 3306),
        'name' => rb_env('DB_NAME', 'rural_banking'),
        'user' => rb_env('DB_USER', 'root'),
        'pass' => rb_env('DB_PASS', ''),
    );
}

function rb_database_configured()
{
    $c = rb_db_settings();
    $placeholder = preg_match('/^(CHANGE|REPLACE|CHANGEME|)$/i', (string) $c['pass'])
        || stripos((string) $c['pass'], 'replace_with') !== false;
    return !$placeholder
        && $c['host'] !== ''
        && $c['name'] !== ''
        && $c['user'] !== '';
}

function rb_mysqli()
{
    static $db = null;
    if ($db instanceof mysqli) {
        return $db;
    }
    if (!rb_database_configured()) {
        error_log('[RuralBanking] Database credentials are not configured');
        rb_fail_page('Database configuration is incomplete', 503);
    }
    $c = rb_db_settings();
    try {
        $db = new mysqli($c['host'], $c['user'], $c['pass'], $c['name'], $c['port']);
        if ($db->connect_errno) {
            throw new RuntimeException('connect failed');
        }
        $db->set_charset('utf8mb4');
    } catch (Throwable $e) {
        error_log('[RuralBanking] Database connection failed: ' . $e->getMessage());
        rb_fail_page('The service is temporarily unavailable', 503);
    }
    return $db;
}

function rb_pdo()
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!rb_database_configured()) {
        error_log('[RuralBanking] Database credentials are not configured');
        rb_fail_page('Database configuration is incomplete', 503);
    }
    $c = rb_db_settings();
    try {
        $pdo = new PDO(
            'mysql:host=' . $c['host'] . ';port=' . $c['port'] . ';dbname=' . $c['name'] . ';charset=utf8mb4',
            $c['user'],
            $c['pass'],
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false)
        );
    } catch (Throwable $e) {
        error_log('[RuralBanking] PDO connection failed: ' . $e->getMessage());
        rb_fail_page('The service is temporarily unavailable', 503);
    }
    return $pdo;
}

/** Branding row from ib_systemsettings, with safe defaults. */
function rb_system_settings($db = null)
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = array(
        'sys_name'    => 'Bank Of Soumya',
        'sys_tagline' => 'Simple, secure banking for every village.',
        'sys_logo'    => 'bank-of-soumya-logo-optimized.png',
    );
    try {
        $db = $db ?: rb_mysqli();
        $res = $db->query('SELECT sys_name, sys_tagline, sys_logo FROM ib_systemsettings ORDER BY id ASC LIMIT 1');
        if ($res && ($row = $res->fetch_assoc())) {
            foreach ($row as $k => $v) {
                if ($v !== null && $v !== '') {
                    $cache[$k] = $v;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[RuralBanking] settings: ' . $e->getMessage());
    }
    return $cache;
}

/* ------------------------------------------------------------------ *
 * 4. Security headers + session
 * ------------------------------------------------------------------ */
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header_remove('X-Powered-By');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    if ($https) {
        header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
    }
}

if (PHP_SAPI !== 'cli' && !defined('RB_NO_SESSION') && session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? '1' : '0');
    session_start();
}

/* ------------------------------------------------------------------ *
 * 5. CSRF protection (token is injected into every POST form and into
 *    destructive GET links automatically via an output filter)
 * ------------------------------------------------------------------ */
function rb_csrf_actions()
{
    return array('fireStaff', 'deleteClient', 'deleteBankAccType', 'deleteBankAcc');
}

function rb_csrf_token()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    if (empty($_SESSION['_rb_csrf'])) {
        $_SESSION['_rb_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_rb_csrf'];
}

function rb_csrf_guard()
{
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $token  = rb_csrf_token();
    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
    $must   = false;
    $sent   = '';
    if ($method === 'POST') {
        $ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        $must = !$ajax;
        $sent = isset($_POST['_csrf']) ? $_POST['_csrf'] : '';
    } else {
        foreach (rb_csrf_actions() as $a) {
            if (isset($_GET[$a])) {
                $must = true;
            }
        }
        $sent = isset($_GET['_csrf']) ? $_GET['_csrf'] : '';
    }
    if ($must && !(is_string($sent) && $token !== '' && hash_equals($token, $sent))) {
        rb_fail_page('Your session expired or the request could not be verified', 419);
    }
}

function rb_csrf_filter($html)
{
    $token = rb_csrf_token();
    if ($token === '' || $html === '') {
        return $html;
    }
    $field = '<input type="hidden" name="_csrf" value="' . $token . '">';
    $html = preg_replace_callback(
        '/<form\b[^>]*\bmethod\s*=\s*["\']?post["\']?[^>]*>/i',
        function ($m) use ($field) {
            return $m[0] . $field;
        },
        $html
    );
    $actions = implode('|', array_map('preg_quote', rb_csrf_actions()));
    $html = preg_replace_callback(
        '/href=(["\'])([^"\']*[?&](?:' . $actions . ')=[^"\']*)\1/',
        function ($m) use ($token) {
            return 'href=' . $m[1] . $m[2] . (strpos($m[2], '?') !== false ? '&' : '?') . '_csrf=' . $token . $m[1];
        },
        $html
    );
    return $html;
}

if (PHP_SAPI !== 'cli' && !defined('RB_NO_SESSION')) {
    rb_csrf_guard();
    ob_start('rb_csrf_filter');
}

/* ------------------------------------------------------------------ *
 * 6. Passwords (bcrypt/argon via password_hash, with transparent upgrade
 *    from the legacy unsalted sha1(md5()) hashes)
 * ------------------------------------------------------------------ */
function rb_hash_password($plain)
{
    return password_hash((string) $plain, PASSWORD_DEFAULT);
}

function rb_verify_password($plain, $stored, &$needs_rehash = false)
{
    $needs_rehash = false;
    $plain  = (string) $plain;
    $stored = (string) $stored;
    if ($stored === '') {
        return false;
    }
    if (strncmp($stored, '$2y$', 4) === 0 || strncmp($stored, '$argon', 6) === 0) {
        $ok = password_verify($plain, $stored);
        if ($ok && password_needs_rehash($stored, PASSWORD_DEFAULT)) {
            $needs_rehash = true;
        }
        return $ok;
    }
    $ok = hash_equals($stored, sha1(md5($plain)));
    if ($ok) {
        $needs_rehash = true;
    }
    return $ok;
}

function rb_password_problem($plain)
{
    $plain = (string) $plain;
    if (strlen($plain) > 4096) {
        return 'Password must be 4,096 characters or fewer.';
    }
    if (strlen($plain) < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Za-z]/', $plain) || !preg_match('/\d/', $plain)) {
        return 'Password must contain at least one letter and one number.';
    }
    return null;
}

/* ------------------------------------------------------------------ *
 * 7. Login (shared by the three portals) with throttling
 * ------------------------------------------------------------------ */
function rb_client_ip()
{
    $remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
    if ($remote === '' || filter_var($remote, FILTER_VALIDATE_IP) === false) {
        return '0.0.0.0';
    }
    if (!rb_request_from_trusted_proxy($remote)) {
        return substr($remote, 0, 45);
    }
    foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP') as $header) {
        if (empty($_SERVER[$header])) {
            continue;
        }
        $candidate = trim(explode(',', (string) $_SERVER[$header])[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
            return substr($candidate, 0, 45);
        }
    }
    return substr($remote, 0, 45);
}

function rb_request_from_trusted_proxy($remote = null)
{
    if ($remote === null) {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
    }
    $trusted = array_filter(array_map('trim', explode(',', (string) rb_env('TRUSTED_PROXY_IPS', ''))));
    return $remote !== '' && in_array($remote, $trusted, true);
}

function rb_login_location()
{
    $remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
    if (!rb_request_from_trusted_proxy($remote)) {
        return 'Unavailable';
    }
    foreach (array('HTTP_CF_IPCOUNTRY', 'HTTP_X_GEOIP_COUNTRY_CODE', 'GEOIP_COUNTRY_CODE') as $header) {
        if (empty($_SERVER[$header])) {
            continue;
        }
        $country = strtoupper(trim((string) $_SERVER[$header]));
        if (preg_match('/^[A-Z]{2}$/D', $country) === 1 && $country !== 'XX' && $country !== 'T1') {
            return 'Country: ' . $country;
        }
    }
    return 'Unavailable';
}

function rb_record_user_activity($db, $role, $actor_id, $event_type, $page = '', $method = '')
{
    $roles = array('admin', 'staff', 'client');
    $events = array('login_success', 'login_failed', 'login_blocked', 'page_request', 'account_registration', 'logout');
    if (!in_array($role, $roles, true) || !in_array($event_type, $events, true)) {
        throw new InvalidArgumentException('Invalid user activity event.');
    }
    $actor_id = max(0, (int) $actor_id);
    $page = basename((string) $page);
    $method = strtoupper((string) $method);
    if (!in_array($method, array('GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'CLI'), true)) {
        $method = 'GET';
    }
    $ip = rb_client_ip();
    $location = rb_login_location();
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    $user_agent = substr(preg_replace('/[\x00-\x1F\x7F]/', ' ', $user_agent), 0, 255);

    $stmt = $db->prepare('INSERT INTO ib_user_activity
        (actor_role, actor_id, event_type, page, request_method, ip_address, location, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare user activity record: ' . $db->error);
    }
    $stmt->bind_param('sissssss', $role, $actor_id, $event_type, $page, $method, $ip, $location, $user_agent);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Could not save user activity record.');
    }
    $stmt->close();

    $db->query('DELETE FROM ib_user_activity WHERE created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 180 DAY)');
}

function rb_log_authenticated_request()
{
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    foreach (array('admin', 'staff', 'client') as $role) {
        $session_key = $role . '_id';
        if (!empty($_SESSION[$session_key])) {
            try {
                rb_record_user_activity(
                    rb_mysqli(),
                    $role,
                    (int) $_SESSION[$session_key],
                    'page_request',
                    isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '',
                    isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET'
                );
            } catch (Throwable $e) {
                error_log('[RuralBanking] user activity log unavailable: ' . $e->getMessage());
            }
            return;
        }
    }
}

function rb_public_form_allowed($db, $form_name, $limit = 5)
{
    $forms = array('signup');
    if (!in_array($form_name, $forms, true)) {
        throw new InvalidArgumentException('Invalid public form name.');
    }
    $ip = rb_client_ip();
    $db->query('DELETE FROM ib_public_form_attempts WHERE attempted_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY)');
    $stmt = $db->prepare("SELECT COUNT(*) FROM ib_public_form_attempts
                          WHERE form_name = ? AND ip_address = ?
                            AND attempted_at > DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 HOUR)");
    if (!$stmt) {
        throw new RuntimeException('Could not prepare public form rate limit: ' . $db->error);
    }
    $stmt->bind_param('ss', $form_name, $ip);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Could not check the public form rate limit.');
    }
    $stmt->bind_result($attempts);
    $stmt->fetch();
    $stmt->close();

    $stmt = $db->prepare('INSERT INTO ib_public_form_attempts (form_name, ip_address) VALUES (?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare public form attempt record: ' . $db->error);
    }
    $stmt->bind_param('ss', $form_name, $ip);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Could not record the public form attempt.');
    }
    $stmt->close();
    return (int) $attempts < max(1, min(20, (int) $limit));
}

function rb_spam_honeypot_is_clear($post)
{
    return !isset($post['_rb_contact'])
        || (is_string($post['_rb_contact']) && trim($post['_rb_contact']) === '');
}

function rb_login_locked($db, $email)
{
    try {
        $ip = rb_client_ip();
        $stmt = $db->prepare('SELECT COUNT(*) FROM ib_login_attempts WHERE ip = ? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $ip);
        $stmt->execute();
        $stmt->bind_result($n);
        $stmt->fetch();
        $stmt->close();
        return $n >= 10;
    } catch (Throwable $e) {
        return false;
    }
}

function rb_login_record_failure($db, $email)
{
    try {
        $ip = rb_client_ip();
        $email = substr((string) $email, 0, 190);
        $stmt = $db->prepare('INSERT INTO ib_login_attempts (ip, email) VALUES (?, ?)');
        if ($stmt) {
            $stmt->bind_param('ss', $ip, $email);
            $stmt->execute();
            $stmt->close();
        }
        $db->query('DELETE FROM ib_login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
    } catch (Throwable $e) {
        error_log('[RuralBanking] login log: ' . $e->getMessage());
    }
}

function rb_client_record_login($db, $client_id, $email)
{
    $session_hash = hash('sha256', session_id());
    $label = isset($_SERVER['HTTP_USER_AGENT']) ? trim((string) $_SERVER['HTTP_USER_AGENT']) : 'Browser';
    $label = preg_replace('/[\x00-\x1F\x7F]/', ' ', $label);
    $label = substr($label !== '' ? $label : 'Browser', 0, 100);
    $ip = rb_client_ip();
    $ip_hash = hash('sha256', $ip);
    $location = rb_login_location();

    try {
        $stmt = $db->prepare("INSERT INTO ib_login_devices (client_id, device_hash, label, ip_hash, status)
                              VALUES (?, ?, ?, ?, 'active')");
        if (!$stmt) {
            throw new RuntimeException('Could not prepare customer session history: ' . $db->error);
        }
        $stmt->bind_param('isss', $client_id, $session_hash, $label, $ip_hash);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not save customer session history.');
        }
        $stmt->close();
    } catch (Throwable $e) {
        error_log('[RuralBanking] client session history unavailable: ' . $e->getMessage());
        return;
    }

    if (function_exists('rb_email_queue') && rb_email_valid_address($email)) {
        try {
            rb_email_queue($db, $email, 'New sign-in to Bank Of Soumya',
                "A new sign-in was recorded for your Bank Of Soumya account.\n\n"
                . 'Time: ' . date('d M Y, h:i A T') . "\n"
                . 'Browser: ' . $label . "\n\n"
                . 'IP address: ' . $ip . "\n"
                . 'Approximate location: ' . $location . "\n\n"
                . 'If you do not recognize this session, review your Security Center and revoke it. '
                . 'Never share your password or one-time codes.',
                $client_id, 'security');
        } catch (Throwable $e) {
            error_log('[RuralBanking] client sign-in email could not be queued: ' . $e->getMessage());
        }
    }
}

function rb_client_session_is_active($db, $client_id)
{
    $session_hash = hash('sha256', session_id());
    try {
        $stmt = $db->prepare('SELECT status FROM ib_login_devices WHERE client_id = ? AND device_hash = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Could not prepare session status check: ' . $db->error);
        }
        $stmt->bind_param('is', $client_id, $session_hash);
        $stmt->execute();
        $stmt->bind_result($status);
        $found = $stmt->fetch();
        $stmt->close();
        if ($found && $status !== 'active') {
            return false;
        }
        if ($found) {
            $stmt = $db->prepare("UPDATE ib_login_devices SET last_seen = CURRENT_TIMESTAMP
                                  WHERE client_id = ? AND device_hash = ? AND status = 'active'");
            if ($stmt) {
                $stmt->bind_param('is', $client_id, $session_hash);
                $stmt->execute();
                $stmt->close();
            }
        }
        return true;
    } catch (Throwable $e) {
        error_log('[RuralBanking] client session status unavailable: ' . $e->getMessage());
        return true;
    }
}

/**
 * @param string $role admin|staff|client
 * @return array [bool ok, string message]
 */
function rb_attempt_login($db, $role, $email, $password)
{
    $map = array(
        'admin'  => array('ib_admin', 'admin_id'),
        'staff'  => array('ib_staff', 'staff_id'),
        'client' => array('ib_clients', 'client_id'),
    );
    if (!isset($map[$role])) {
        return array(false, 'Unknown portal.');
    }
    $page = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'POST';
    if (!is_scalar($email) || !is_scalar($password)) {
        return array(false, 'Enter your email and password.');
    }
    $email = trim((string) $email);
    $password = (string) $password;
    if ($email === '' || $password === '') {
        return array(false, 'Enter your email and password.');
    }
    if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) > 4096) {
        return array(false, 'Enter a valid email address and password.');
    }
    if (rb_login_locked($db, $email)) {
        try {
            rb_record_user_activity($db, $role, 0, 'login_blocked', $page, $method);
        } catch (Throwable $e) {
            error_log('[RuralBanking] blocked login activity unavailable: ' . $e->getMessage());
        }
        return array(false, 'Too many failed attempts. Please wait 15 minutes and try again.');
    }
    list($table, $idcol) = $map[$role];
    $stmt = $db->prepare("SELECT $idcol, password FROM $table WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->bind_result($id, $stored);
    $found = $stmt->fetch();
    $stmt->close();

    $rehash = false;
    if ($found && rb_verify_password($password, $stored, $rehash)) {
        if ($rehash) {
            $new = rb_hash_password($password);
            $up = $db->prepare("UPDATE $table SET password = ? WHERE $idcol = ?");
            $up->bind_param('si', $new, $id);
            $up->execute();
            $up->close();
        }
        session_regenerate_id(true);
        $_SESSION[$idcol] = (int) $id;
        try {
            rb_record_user_activity($db, $role, (int) $id, 'login_success', $page, $method);
        } catch (Throwable $e) {
            error_log('[RuralBanking] successful login activity unavailable: ' . $e->getMessage());
        }
        if ($role === 'client') {
            rb_client_record_login($db, (int) $id, $email);
        }
        return array(true, '');
    }
    rb_login_record_failure($db, $email);
    try {
        rb_record_user_activity($db, $role, 0, 'login_failed', $page, $method);
    } catch (Throwable $e) {
        error_log('[RuralBanking] failed login activity unavailable: ' . $e->getMessage());
    }
    return array(false, 'Incorrect email or password.');
}

if (PHP_SAPI !== 'cli' && !defined('RB_NO_SESSION')) {
    register_shutdown_function('rb_log_authenticated_request');
}

/* ------------------------------------------------------------------ *
 * 8. Auth-page layout used by the login / sign-up / help pages
 * ------------------------------------------------------------------ */
function rb_auth_open(array $o)
{
    $sys     = rb_system_settings();
    $portal  = isset($o['portal']) ? $o['portal'] : 'client';
    $title   = isset($o['title']) ? $o['title'] : 'Sign in';
    $sub     = isset($o['subtitle']) ? $o['subtitle'] : '';
    $icon    = isset($o['icon']) ? $o['icon'] : 'fa-user';
    $hero    = array(
        'admin'  => array('Administration', 'Oversee branches, staff and every rupee that moves through the bank.'),
        'staff'  => array('Branch Counter', 'Open accounts, record deposits and serve customers with confidence.'),
        'client' => array('Customer Banking', 'Check balances, move money and track every transaction, anytime.'),
    );
    $h = isset($hero[$portal]) ? $hero[$portal] : $hero['client'];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="author" content="Soumya Das">
  <meta name="robots" content="noindex,nofollow">
  <meta name="description" content="<?php echo rb_e($sub !== '' ? $sub : $title . ' for Bank Of Soumya customers and staff.'); ?>">
  <title><?php echo rb_e($title); ?> &middot; <?php echo rb_e($sys['sys_name']); ?></title>
  <link rel="icon" type="image/png" href="../assets/img/bank-of-soumya-favicon.png">
  <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
  <link rel="stylesheet" href="../assets/css/rb-auth.css">
</head>
<body class="rb-auth-body" data-i18n-scope="all" data-portal="<?php echo rb_e($portal); ?>">
<main class="rb-auth">
  <aside class="rb-auth-hero" aria-hidden="false">
    <div class="rb-auth-hero__inner">
      <a class="rb-brand" href="../index.php">
        <img src="../assets/img/bank-of-soumya-logo-optimized.png" alt="Bank Of Soumya" width="1000" height="355" decoding="async">
      </a>
      <div class="rb-auth-hero__copy">
        <span class="rb-chip"><i class="fas <?php echo rb_e($icon); ?>"></i> <?php echo rb_e($h[0]); ?></span>
        <h1><?php echo rb_e($h[1]); ?></h1>
        <ul class="rb-auth-points">
          <li><i class="fas fa-shield-alt"></i> Protected with encrypted passwords and verified requests</li>
          <li><i class="fas fa-exchange-alt"></i> Every transaction is recorded and auditable</li>
          <li><i class="fas fa-seedling"></i> Built for villages, cooperatives and small branches</li>
        </ul>
      </div>
      <svg class="rb-auth-hills" viewBox="0 0 600 160" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0 110 C 90 60, 180 60, 280 100 S 470 140, 600 80 L600 160 L0 160Z" fill="rgba(255,255,255,.07)"/>
        <path d="M0 135 C 110 95, 200 100, 310 128 S 500 150, 600 118 L600 160 L0 160Z" fill="rgba(255,255,255,.10)"/>
      </svg>
    </div>
  </aside>
  <section class="rb-auth-panel">
    <div class="rb-auth-card">
      <div class="rb-auth-card__head">
        <h2><?php echo rb_e($title); ?></h2>
        <?php if ($sub !== '') { ?><p><?php echo rb_e($sub); ?></p><?php } ?>
      </div>
      <?php if (!empty($o['error'])) { ?>
        <div class="rb-alert rb-alert--error" role="alert"><i class="fas fa-exclamation-circle"></i> <span><?php echo rb_e($o['error']); ?></span></div>
      <?php } ?>
      <?php if (!empty($o['success'])) { ?>
        <div class="rb-alert rb-alert--ok" role="status"><i class="fas fa-check-circle"></i> <span><?php echo rb_e($o['success']); ?></span></div>
      <?php } ?>
<?php
}

function rb_auth_close(array $links = array())
{
    ?>
      <?php if ($links) { ?>
      <div class="rb-auth-links">
        <?php foreach ($links as $l) { ?>
          <a href="<?php echo rb_e($l[1]); ?>"><?php echo rb_e($l[0]); ?></a>
        <?php } ?>
      </div>
      <?php } ?>
    </div>
    <p class="rb-auth-foot">&copy; <?php echo date('Y'); ?> Bank Of Soumya &middot; Designed &amp; developed by Soumya Das · <a href="../service_information.php">Service Information</a> · <a href="../service_information.php#privacy">Privacy Policy</a> · <a href="../service_information.php#terms">Terms &amp; Conditions</a></p>
  </section>
</main>
<script src="../assets/js/rb-i18n.js" defer></script>
<script src="../assets/js/rb-assistant.js" defer></script>
<script>
  (function () {
    document.querySelectorAll('[data-toggle-pass]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var input = document.getElementById(btn.getAttribute('data-toggle-pass'));
        if (!input) return;
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.querySelector('i').className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
      });
    });
  })();
</script>
</body>
</html>
<?php
}

/* ------------------------------------------------------------------ *
 * 9. Safe image uploads (random name, real image check, size limit)
 * ------------------------------------------------------------------ */
function rb_save_upload($field, $dir)
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return '';
    }
    $f = $_FILES[$field];
    if (!is_uploaded_file($f['tmp_name']) || $f['size'] <= 0 || $f['size'] > 2 * 1024 * 1024) {
        return '';
    }
    $info = @getimagesize($f['tmp_name']);
    $allowed = array(IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif');
    if (defined('IMAGETYPE_WEBP')) {
        $allowed[IMAGETYPE_WEBP] = 'webp';
    }
    if (!$info || !isset($allowed[$info[2]])) {
        return '';
    }
    $name = bin2hex(random_bytes(10)) . '.' . $allowed[$info[2]];
    if (!is_dir($dir) || !move_uploaded_file($f['tmp_name'], rtrim($dir, '/') . '/' . $name)) {
        return '';
    }
    return $name;
}
