<?php
/**
 * Rural Banking System - translation API (powered by Groq)
 *
 * POST JSON { "target_lang": "hi", "texts": ["Dashboard", ...] }
 * -> JSON   { "translations": [...], "fallback": false }
 *
 * - The Groq API key lives only on the server (.env -> GROQ_API_KEY)
 * - Only a fixed list of languages is accepted
 * - Results are cached in the database, so each phrase is translated once
 * - Text that looks like personal/financial data is never sent to the AI
 * - Per-IP rate limit protects your Groq quota
 *
 * (c) 2026 Soumya Das
 */
define('RB_NO_SESSION', true);
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/ai.php';

header('Content-Type: application/json; charset=utf-8');

function rb_tr_out(array $data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

const RB_TR_LANGS = array(
    'hi' => 'Hindi (Devanagari script)',      'gu' => 'Gujarati (Gujarati script)',
    'bn' => 'Bengali (Bengali script)',       'ta' => 'Tamil (Tamil script)',
    'te' => 'Telugu (Telugu script)',         'mr' => 'Marathi (Devanagari script)',
    'kn' => 'Kannada (Kannada script)',       'ml' => 'Malayalam (Malayalam script)',
    'pa' => 'Punjabi (Gurmukhi script)',      'or' => 'Odia (Odia script)',
    'ur' => 'Urdu (Arabic script)',           'as' => 'Assamese (Assamese script)',
);
const RB_TR_MAX_TEXTS = 50;
const RB_TR_MAX_LEN   = 800;
const RB_TR_GROQ_CALLS_PER_10_MIN = 40;

/** Text that may be personal or financial data must never leave the server. */
function rb_tr_is_sensitive($t)
{
    return (bool) (preg_match('/\d{3,}/', $t) || strpos($t, '@') !== false || preg_match('~https?://~i', $t));
}

// ---- request validation ---------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rb_tr_out(array('error' => 'POST only'), 405);
}
if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
    rb_tr_out(array('error' => 'Forbidden'), 403);
}
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $oh = parse_url($origin, PHP_URL_HOST);
    $hh = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
    if (!$oh || strcasecmp($oh, $hh) !== 0) {
        rb_tr_out(array('error' => 'Forbidden'), 403);
    }
}
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 120000) {
    rb_tr_out(array('error' => 'Payload too large'), 413);
}
$body  = json_decode($raw, true);
$lang  = is_array($body) && isset($body['target_lang']) ? (string) $body['target_lang'] : '';
$texts = is_array($body) && isset($body['texts']) && is_array($body['texts']) ? array_values($body['texts']) : null;
if (!array_key_exists($lang, RB_TR_LANGS) || $texts === null || count($texts) < 1 || count($texts) > RB_TR_MAX_TEXTS) {
    rb_tr_out(array('error' => 'Invalid request'), 400);
}
foreach ($texts as $i => $t) {
    if (!is_string($t) || strlen($t) > RB_TR_MAX_LEN) {
        rb_tr_out(array('error' => 'Invalid text'), 400);
    }
    $texts[$i] = trim(preg_replace('/\s+/u', ' ', $t));
}

$result   = $texts;            // default: return the original text
$fallback = false;
$reason = null;
$db = rb_mysqli();

// ---- 1. database cache ----------------------------------------------------
$missing = array();            // index => text still to translate
try {
    $stmt = $db->prepare('SELECT translated FROM ib_translation_cache WHERE lang = ? AND text_hash = ?');
    foreach ($texts as $i => $t) {
        if ($t === '' || rb_tr_is_sensitive($t)) {
            continue;
        }
        $h = sha1($t);
        $stmt->bind_param('ss', $lang, $h);
        $stmt->execute();
        $stmt->bind_result($tr);
        if ($stmt->fetch()) {
            $result[$i] = $tr;
        } else {
            $missing[$i] = $t;
        }
    }
    $stmt->close();
} catch (Throwable $e) {
    error_log('[RuralBanking] translation cache read: ' . $e->getMessage());
    foreach ($texts as $i => $t) {
        if ($t !== '' && !rb_tr_is_sensitive($t)) {
            $missing[$i] = $t;
        }
    }
}

// ---- 2. ask Groq for anything not cached ----------------------------------
if ($missing) {
    $apiKey = rb_env('GROQ_API_KEY', '');
    if ($apiKey === '') {
        rb_tr_out(array('translations' => $result, 'fallback' => true, 'reason' => 'not_configured'));
    }

    // per-IP rate limit (only calls that reach Groq count)
    $ip = rb_client_ip();
    try {
        $db->query('CREATE TABLE IF NOT EXISTS ib_api_usage (id INT AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_ip (ip, created_at)) ENGINE=InnoDB');
        $q = $db->prepare('SELECT COUNT(*) FROM ib_api_usage WHERE ip = ? AND created_at > (NOW() - INTERVAL 10 MINUTE)');
        $q->bind_param('s', $ip);
        $q->execute();
        $q->bind_result($calls);
        $q->fetch();
        $q->close();
        if ($calls >= RB_TR_GROQ_CALLS_PER_10_MIN) {
            rb_tr_out(array('translations' => $result, 'fallback' => true, 'reason' => 'rate_limited'), 429);
        }
        $ins = $db->prepare('INSERT INTO ib_api_usage (ip) VALUES (?)');
        $ins->bind_param('s', $ip);
        $ins->execute();
        $ins->close();
        $db->query('DELETE FROM ib_api_usage WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    } catch (Throwable $e) {
        error_log('[RuralBanking] translation rate limit: ' . $e->getMessage());
    }

    $model   = rb_ai_model();
    $list    = array_values($missing);
    $keys    = array_keys($missing);
    $n       = count($list);
    $system  = 'You are a professional translator for a rural banking website used by first-time online banking customers. '
        . 'Translate every UI string from English into ' . RB_TR_LANGS[$lang] . '. '
        . 'Use simple, everyday words that a villager would understand; avoid heavy bureaucratic or Sanskritised vocabulary. '
        . 'Keep unchanged: brand names (e.g. "Bank Of Soumya"), person names, numbers, the rupee sign, emails, URLs and placeholders. '
        . 'Do not add explanations or quotes. Respond ONLY with a JSON object of the form {"translations": ["...", "..."]} '
        . 'containing exactly ' . $n . ' strings in the same order as the input.';
    $payload = json_encode(array(
        'model'           => $model,
        'temperature'     => 0.1,
        'max_completion_tokens' => 4096,
        'response_format' => array('type' => 'json_object'),
        'messages'        => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => json_encode(array('strings' => $list), JSON_UNESCAPED_UNICODE)),
        ),
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
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json', 'Authorization: Bearer ' . $apiKey),
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
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer $apiKey\r\n",
        )));
        $resp = @file_get_contents('https://api.groq.com/openai/v1/chat/completions', false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/\s([0-9]{3})\s/', $http_response_header[0], $match)) {
            $http = (int) $match[1];
        }
    }

    $translated = null;
    $failureReason = 'invalid_response';
    if ($resp && $http === 200) {
        $j = json_decode($resp, true);
        $content = $j['choices'][0]['message']['content'] ?? '';
        $parsed = json_decode($content, true);
        if (isset($parsed['translations']) && is_array($parsed['translations']) && count($parsed['translations']) === $n) {
            $translated = $parsed['translations'];
        }
    } else {
        rb_groq_log_failure($http, $transportError, $resp);
        $failureReason = 'upstream_error';
    }

    if ($translated === null) {
        $fallback = true;
        $reason = $failureReason;
    } else {
        $save = $db->prepare('INSERT INTO ib_translation_cache (lang, text_hash, source, translated) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE translated = VALUES(translated)');
        foreach ($keys as $pos => $idx) {
            $out = is_string($translated[$pos]) ? trim($translated[$pos]) : '';
            if ($out === '' || mb_strlen($out) > mb_strlen($list[$pos]) * 5 + 60) {
                $fallback = true;       // suspicious answer: keep English for this one
                $reason = 'invalid_response';
                continue;
            }
            $result[$idx] = $out;
            try {
                $h = sha1($list[$pos]);
                $save->bind_param('ssss', $lang, $h, $list[$pos], $out);
                $save->execute();
            } catch (Throwable $e) {
                error_log('[RuralBanking] translation cache write: ' . $e->getMessage());
            }
        }
        $save->close();
    }
}

$response = array('translations' => $result, 'fallback' => $fallback);
if ($reason !== null) {
    $response['reason'] = $reason;
}
rb_tr_out($response);
