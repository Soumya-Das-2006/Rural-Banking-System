<?php
/**
 * Minimal TLS-only SMTP sender and email outbox.
 * SMTP secrets are read only from private environment configuration.
 */
if (!defined('RB_BOOTSTRAPPED')) {
    require_once __DIR__ . '/bootstrap.php';
}

function rb_email_valid_address($address)
{
    return is_string($address)
        && strlen($address) <= 190
        && preg_match('/[\x00-\x20<>]/', $address) === 0
        && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
}

function rb_email_smtp_settings()
{
    $host = trim((string) rb_env('MAIL_HOST', ''));
    $port = (int) rb_env('MAIL_PORT', 587);
    $encryption = strtolower(trim((string) rb_env('MAIL_ENCRYPTION', 'tls')));
    $username = (string) rb_env('MAIL_USERNAME', '');
    $password = (string) rb_env('MAIL_PASSWORD', '');
    $from = trim((string) rb_env('MAIL_FROM_ADDRESS', ''));
    $fromName = trim((string) rb_env('MAIL_FROM_NAME', 'Bank Of Soumya'));

    if ($host === '' || !preg_match('/^[A-Za-z0-9.-]+$/D', $host)
        || $port < 1 || $port > 65535
        || !in_array($encryption, array('tls', 'ssl'), true)
        || $username === '' || $password === ''
        || !rb_email_valid_address($from)
    ) {
        return null;
    }

    return array(
        'host' => $host,
        'port' => $port,
        'encryption' => $encryption,
        'username' => $username,
        'password' => $password,
        'from' => $from,
        'from_name' => preg_replace('/[\r\n]+/', ' ', $fromName),
        'timeout' => 8,
    );
}

function rb_smtp_read_response($socket)
{
    $lines = array();
    $code = null;
    for ($i = 0; $i < 25; $i++) {
        $line = fgets($socket, 515);
        if ($line === false) {
            throw new RuntimeException('SMTP connection closed before a response.');
        }
        $lines[] = trim($line);
        if (preg_match('/^([0-9]{3})([ -])/', $line, $match) === 1) {
            $code = (int) $match[1];
            if ($match[2] === ' ') {
                return array('code' => $code, 'message' => implode(' ', $lines));
            }
        }
    }
    throw new RuntimeException('SMTP response was incomplete.');
}

function rb_smtp_command($socket, $command, array $acceptedCodes)
{
    rb_smtp_write_all($socket, $command . "\r\n");
    $response = rb_smtp_read_response($socket);
    if (!in_array($response['code'], $acceptedCodes, true)) {
        throw new RuntimeException('SMTP server rejected a command with status ' . $response['code'] . '.');
    }
    return $response;
}

function rb_smtp_write_all($socket, $data)
{
    $length = strlen($data);
    $offset = 0;
    while ($offset < $length) {
        $written = fwrite($socket, substr($data, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('Could not write to the SMTP connection.');
        }
        $offset += $written;
    }
}

function rb_email_send($recipient, $subject, $body)
{
    if (!rb_email_valid_address($recipient)
        || !is_string($subject) || trim($subject) === '' || strlen($subject) > 180
        || preg_match('/[\r\n]/', $subject)
        || !is_string($body) || trim($body) === '' || strlen($body) > 32000
    ) {
        return array('ok' => false, 'error' => 'Email address, subject, or message is invalid.');
    }
    $settings = rb_email_smtp_settings();
    if ($settings === null) {
        return array('ok' => false, 'error' => 'SMTP is not configured.');
    }

    $socket = null;
    try {
        $context = stream_context_create(array('ssl' => array(
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $settings['host'],
            'SNI_enabled' => true,
        )));
        $scheme = $settings['encryption'] === 'ssl' ? 'ssl://' : 'tcp://';
        $remote = $scheme . $settings['host'] . ':' . $settings['port'];
        $socket = @stream_socket_client(
            $remote,
            $errno,
            $error,
            $settings['timeout'],
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!$socket) {
            throw new RuntimeException('Could not connect to the configured SMTP server.');
        }
        stream_set_timeout($socket, $settings['timeout']);
        $greeting = rb_smtp_read_response($socket);
        if ($greeting['code'] !== 220) {
            throw new RuntimeException('SMTP server did not send a ready response.');
        }

        rb_smtp_command($socket, 'EHLO ruralbank.local', array(250));
        if ($settings['encryption'] === 'tls') {
            rb_smtp_command($socket, 'STARTTLS', array(220));
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Could not establish SMTP TLS encryption.');
            }
            rb_smtp_command($socket, 'EHLO ruralbank.local', array(250));
        }

        rb_smtp_command($socket, 'AUTH LOGIN', array(334));
        rb_smtp_command($socket, base64_encode($settings['username']), array(334));
        rb_smtp_command($socket, base64_encode($settings['password']), array(235));
        rb_smtp_command($socket, 'MAIL FROM:<' . $settings['from'] . '>', array(250));
        rb_smtp_command($socket, 'RCPT TO:<' . $recipient . '>', array(250, 251));
        rb_smtp_command($socket, 'DATA', array(354));

        $encodedName = mb_encode_mimeheader($settings['from_name'], 'UTF-8', 'B', "\r\n");
        $encodedSubject = mb_encode_mimeheader(trim($subject), 'UTF-8', 'B', "\r\n");
        $headers = array(
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . $encodedName . ' <' . $settings['from'] . '>',
            'To: <' . $recipient . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
        );
        $normalizedBody = str_replace(array("\r\n", "\r"), "\n", $body);
        $encodedBody = quoted_printable_encode($normalizedBody);
        $encodedBody = str_replace("\n", "\r\n", $encodedBody);
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $encodedBody;
        $message = preg_replace('/(^|\r\n)\./', '$1..', $message);
        rb_smtp_write_all($socket, $message . "\r\n.\r\n");
        $accepted = rb_smtp_read_response($socket);
        if ($accepted['code'] !== 250) {
            throw new RuntimeException('SMTP server did not accept the email message.');
        }
        rb_smtp_command($socket, 'QUIT', array(221));
        return array('ok' => true, 'error' => '');
    } catch (Throwable $e) {
        error_log('[RuralBanking] email delivery failed: ' . $e->getMessage());
        return array('ok' => false, 'error' => $e->getMessage());
    } finally {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }
}

function rb_email_queue($db, $recipient, $subject, $body, $clientId = 0, $category = 'account')
{
    if (!rb_email_valid_address($recipient)
        || !is_string($subject) || trim($subject) === '' || strlen($subject) > 180
        || preg_match('/[\r\n]/', $subject)
        || !is_string($body) || trim($body) === '' || strlen($body) > 32000
    ) {
        return array('ok' => false, 'queued' => false, 'error' => 'Email address, subject, or message is invalid.');
    }

    $stmt = $db->prepare("INSERT INTO ib_email_outbox (recipient, subject, body, status) VALUES (?, ?, ?, 'pending')");
    if (!$stmt) {
        throw new RuntimeException('Could not prepare email outbox insert: ' . $db->error);
    }
    $stmt->bind_param('sss', $recipient, $subject, $body);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Could not queue email notification.');
    }
    $outboxId = (int) $db->insert_id;
    $stmt->close();

    $clientId = (int) $clientId;
    if ($clientId > 0) {
        $category = in_array($category, array('account', 'security', 'product', 'support', 'announcement'), true)
            ? $category
            : 'account';
        $stmt = $db->prepare('INSERT INTO ib_user_notifications (client_id, category, subject, body, email_outbox_id) VALUES (?, ?, ?, ?, ?)');
        if (!$stmt) {
            throw new RuntimeException('Could not prepare customer notification record: ' . $db->error);
        }
        $stmt->bind_param('isssi', $clientId, $category, $subject, $body, $outboxId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Could not save customer notification record.');
        }
        $stmt->close();
    }

    return array(
        'ok' => true,
        'queued' => true,
        'outbox_id' => $outboxId,
        'error' => '',
    );
}

function rb_email_flush_pending($db, $limit = 20)
{
    $settings = rb_email_smtp_settings();
    if ($settings === null) {
        return array('processed' => 0, 'sent' => 0, 'pending' => 0, 'error' => 'SMTP is not configured.');
    }
    $limit = max(1, min(50, (int) $limit));
    $stmt = $db->prepare("UPDATE ib_email_outbox SET status = 'pending' WHERE status = 'sending' AND updated_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE)");
    if (!$stmt) {
        throw new RuntimeException('Could not prepare stale email recovery: ' . $db->error);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Could not recover stale email sends.');
    }
    $stmt->close();

    $stmt = $db->prepare("SELECT outbox_id, recipient, subject, body FROM ib_email_outbox WHERE status = 'pending' ORDER BY outbox_id ASC LIMIT ?");
    if (!$stmt) {
        throw new RuntimeException('Could not prepare pending email query: ' . $db->error);
    }
    $stmt->bind_param('i', $limit);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Could not read pending emails.');
    }
    $result = $stmt->get_result();
    $pendingEmails = array();
    while ($row = $result->fetch_assoc()) {
        $pendingEmails[] = $row;
    }
    $stmt->close();

    $sent = 0;
    $processed = 0;
    foreach ($pendingEmails as $email) {
        $stmt = $db->prepare("UPDATE ib_email_outbox SET status = 'sending' WHERE outbox_id = ? AND status = 'pending'");
        if (!$stmt) {
            throw new RuntimeException('Could not prepare email delivery claim: ' . $db->error);
        }
        $stmt->bind_param('i', $email['outbox_id']);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Could not claim pending email.');
        }
        $claimed = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$claimed) {
            continue;
        }

        $delivery = rb_email_send($email['recipient'], $email['subject'], $email['body']);
        $status = $delivery['ok'] ? 'sent' : 'pending';
        $lastError = $delivery['ok'] ? '' : substr($delivery['error'], 0, 190);
        $stmt = $db->prepare('UPDATE ib_email_outbox SET status = ?, attempts = attempts + 1, last_error = ?, sent_at = ' . ($delivery['ok'] ? 'CURRENT_TIMESTAMP' : 'NULL') . " WHERE outbox_id = ? AND status = 'sending'");
        if (!$stmt) {
            throw new RuntimeException('Could not prepare pending email status update: ' . $db->error);
        }
        $stmt->bind_param('ssi', $status, $lastError, $email['outbox_id']);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Could not update pending email status.');
        }
        $stmt->close();
        $processed++;
        if ($delivery['ok']) {
            $sent++;
        }
    }
    return array(
        'processed' => $processed,
        'sent' => $sent,
        'pending' => $processed - $sent,
        'error' => '',
    );
}
