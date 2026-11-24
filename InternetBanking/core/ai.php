<?php
/**
 * Rural Banking System - shared AI helpers (Groq)
 *  - key stays on the server (GROQ_API_KEY)
 *  - every AI endpoint: same-origin AJAX only, rate limited, language whitelisted
 * (c) 2026 Soumya Das
 */
if (!defined('RB_BOOTSTRAPPED')) {
    require_once __DIR__ . '/bootstrap.php';
}

require_once __DIR__ . '/money.php';

function rb_ai_lang_names()
{
    return array(
        'en' => 'English', 'hi' => 'Hindi (Devanagari script)', 'gu' => 'Gujarati (Gujarati script)',
        'bn' => 'Bengali (Bengali script)', 'ta' => 'Tamil (Tamil script)', 'te' => 'Telugu (Telugu script)',
        'mr' => 'Marathi (Devanagari script)', 'kn' => 'Kannada (Kannada script)', 'ml' => 'Malayalam (Malayalam script)',
        'pa' => 'Punjabi (Gurmukhi script)', 'or' => 'Odia (Odia script)', 'ur' => 'Urdu (Arabic script)',
        'as' => 'Assamese (Assamese script)',
    );
}

function rb_ai_lang($code)
{
    $names = rb_ai_lang_names();
    return (is_string($code) && array_key_exists($code, $names)) ? $code : 'en';
}

function rb_ai_out(array $data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Only same-origin XHR POSTs are accepted; returns the decoded JSON body. */
function rb_ai_require_ajax()
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        rb_ai_out(array('error' => 'POST only'), 405);
    }
    if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
        rb_ai_out(array('error' => 'Forbidden'), 403);
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $oh = parse_url($origin, PHP_URL_HOST);
        $hh = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
        if (!$oh || strcasecmp($oh, $hh) !== 0) {
            rb_ai_out(array('error' => 'Forbidden'), 403);
        }
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 20000) {
        rb_ai_out(array('error' => 'Payload too large'), 413);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        rb_ai_out(array('error' => 'Invalid request'), 400);
    }
    return $data;
}

/** Who is calling? Decided from the PHP session, never from the request body. */
function rb_ai_actor()
{
    if (!empty($_SESSION['admin_id']))  { return array('role' => 'admin',  'id' => (int) $_SESSION['admin_id'],  'key' => 'admin:'  . (int) $_SESSION['admin_id']); }
    if (!empty($_SESSION['staff_id']))  { return array('role' => 'staff',  'id' => (int) $_SESSION['staff_id'],  'key' => 'staff:'  . (int) $_SESSION['staff_id']); }
    if (!empty($_SESSION['client_id'])) { return array('role' => 'client', 'id' => (int) $_SESSION['client_id'], 'key' => 'client:' . (int) $_SESSION['client_id']); }
    return array('role' => 'public', 'id' => 0, 'key' => 'ip:' . rb_client_ip());
}

/** True when the call is allowed (and records it). Fails open if the table is unavailable. */
function rb_ai_rate_limit($db, $actor_key, $kind, $limit, $minutes = 10)
{
    try {
        $db->query('CREATE TABLE IF NOT EXISTS ib_ai_usage (id INT AUTO_INCREMENT PRIMARY KEY, actor VARCHAR(60) NOT NULL, kind VARCHAR(20) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_actor (actor, kind, created_at)) ENGINE=InnoDB');
        $minutes = (int) $minutes;
        $q = $db->prepare("SELECT COUNT(*) FROM ib_ai_usage WHERE actor = ? AND kind = ? AND created_at > (NOW() - INTERVAL $minutes MINUTE)");
        $q->bind_param('ss', $actor_key, $kind);
        $q->execute();
        $q->bind_result($n);
        $q->fetch();
        $q->close();
        if ($n >= $limit) {
            return false;
        }
        $i = $db->prepare('INSERT INTO ib_ai_usage (actor, kind) VALUES (?, ?)');
        $i->bind_param('ss', $actor_key, $kind);
        $i->execute();
        $i->close();
        $db->query('DELETE FROM ib_ai_usage WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    } catch (Throwable $e) {
        error_log('[RuralBanking] ai rate limit: ' . $e->getMessage());
    }
    return true;
}

function rb_ai_enabled()
{
    return rb_env('GROQ_API_KEY', '') !== '';
}

function rb_ai_model($configured = null)
{
    $model = $configured === null
        ? trim((string) rb_env('GROQ_MODEL', 'openai/gpt-oss-20b'))
        : trim((string) $configured);
    if ($model === '') {
        return 'openai/gpt-oss-20b';
    }
    if (in_array($model, array('llama-3.1-8b-instant', 'llama-3.3-70b-versatile'), true)) {
        error_log('[RuralBanking] Replacing deprecated Groq model with openai/gpt-oss-20b');
        return 'openai/gpt-oss-20b';
    }
    return $model;
}

function rb_groq_log_failure($http, $transportError = '', $response = '')
{
    $details = array('HTTP ' . (int) $http);
    if ($transportError !== '') {
        $details[] = 'transport: ' . substr(preg_replace('/[\r\n]+/', ' ', $transportError), 0, 180);
    }
    $data = json_decode((string) $response, true);
    if (isset($data['error']['code']) && is_string($data['error']['code'])) {
        $details[] = 'provider code: ' . substr($data['error']['code'], 0, 80);
    }
    error_log('[RuralBanking] Groq request failed (' . implode('; ', $details) . ')');
}

/**
 * One chat completion. Returns array(bool ok, string text).
 * $opts: temperature, max_completion_tokens
 */
function rb_groq_chat(array $messages, array $opts = array())
{
    $key = rb_env('GROQ_API_KEY', '');
    if ($key === '') {
        return array(false, '');
    }
    $payload = json_encode(array(
        'model'       => rb_ai_model(),
        'temperature' => isset($opts['temperature']) ? $opts['temperature'] : 0.3,
        'max_completion_tokens' => isset($opts['max_completion_tokens']) ? (int) $opts['max_completion_tokens'] : 500,
        'messages'    => $messages,
    ), JSON_UNESCAPED_UNICODE);

    $resp = null;
    $http = 0;
    $transportError = '';
    if (function_exists('curl_init')) {
        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json', 'Authorization: Bearer ' . $key),
        ));
        $resp = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false) {
            $transportError = curl_error($ch);
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create(array('http' => array(
            'method' => 'POST', 'timeout' => 25, 'ignore_errors' => true, 'content' => $payload,
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer $key\r\n",
        )));
        $resp = @file_get_contents('https://api.groq.com/openai/v1/chat/completions', false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/\s([0-9]{3})\s/', $http_response_header[0], $match)) {
            $http = (int) $match[1];
        }
    }
    if (!$resp || $http !== 200) {
        rb_groq_log_failure($http, $transportError, $resp);
        return array(false, '');
    }
    $j = json_decode($resp, true);
    $text = isset($j['choices'][0]['message']['content']) ? trim((string) $j['choices'][0]['message']['content']) : '';
    if ($text === '') {
        rb_groq_log_failure($http, 'empty or invalid chat completion response', $resp);
    }
    return array($text !== '', $text);
}
