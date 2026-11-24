<?php
/**
 * Fictional demo debit cards and sandbox authorization checks.
 * These cards are not payment credentials and never move money.
 */
if (!defined('RB_BOOTSTRAPPED')) {
    require_once __DIR__ . '/bootstrap.php';
}

if (!defined('RB_DEMO_CARD_MAX_LIMIT')) {
    define('RB_DEMO_CARD_MAX_LIMIT', 100000.00);
}
if (!defined('RB_DEMO_CARD_MAX_AMOUNT')) {
    define('RB_DEMO_CARD_MAX_AMOUNT', 10000000.00);
}

function rb_demo_card_result($ok, $msg, array $extra = array())
{
    return array_merge(array('ok' => (bool) $ok, 'msg' => $msg), $extra);
}

function rb_demo_card_prepare($db, $sql)
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Could not prepare demo card database query: ' . $db->error);
    }
    return $stmt;
}

function rb_demo_card_reference()
{
    return 'DEMO-RB-' . strtoupper(bin2hex(random_bytes(12)));
}

function rb_demo_card_parse_money($raw, $allowZero = false)
{
    if (!is_scalar($raw)) {
        return null;
    }
    $value = trim((string) $raw);
    if (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $value)) {
        return null;
    }
    $amount = (float) $value;
    if ((!$allowZero && $amount <= 0) || ($allowZero && $amount < 0) || $amount > RB_DEMO_CARD_MAX_AMOUNT) {
        return null;
    }
    return number_format($amount, 2, '.', '');
}

function rb_demo_card_parse_limit($raw)
{
    $amount = rb_demo_card_parse_money($raw, true);
    if ($amount === null || (float) $amount > RB_DEMO_CARD_MAX_LIMIT) {
        return null;
    }
    return $amount;
}

function rb_demo_card_authorization_decision(array $card, $channel, $amount, $dailyAtmUsed = 0, $now = null)
{
    if ($now === null) {
        $now = time();
    }
    if (!in_array($channel, array('atm', 'pos', 'online'), true)) {
        return array('result' => 'declined', 'reason' => 'Unsupported transaction channel.');
    }
    if (!empty($card['archived_at'])) {
        return array('result' => 'declined', 'reason' => 'The card record has been archived.');
    }
    if ((string) $card['card_status'] !== 'active') {
        return array('result' => 'declined', 'reason' => 'The card is not active.');
    }

    $expiry = isset($card['expiry_date']) ? strtotime((string) $card['expiry_date']) : false;
    if ($expiry === false || date('Y-m-d', $expiry) < date('Y-m-d', $now)) {
        return array('result' => 'declined', 'reason' => 'The card has expired or has no valid expiry date.');
    }

    if (!empty($card['frozen_until'])) {
        $frozenUntil = strtotime((string) $card['frozen_until']);
        if ($frozenUntil === false || $frozenUntil > $now) {
            return array('result' => 'declined', 'reason' => 'The card is temporarily frozen.');
        }
    }

    if ((float) $amount <= 0) {
        return array('result' => 'declined', 'reason' => 'Enter a valid amount greater than zero.');
    }

    if ($channel === 'atm') {
        $limit = (float) $card['atm_daily_limit'];
        if ($limit <= 0 || (float) $dailyAtmUsed + (float) $amount > $limit + 0.00001) {
            return array('result' => 'declined', 'reason' => 'The daily ATM withdrawal limit would be exceeded.');
        }
    } elseif ($channel === 'pos') {
        $limit = (float) $card['pos_transaction_limit'];
        if ($limit <= 0 || (float) $amount > $limit + 0.00001) {
            return array('result' => 'declined', 'reason' => 'The POS transaction limit would be exceeded.');
        }
    } else {
        $limit = (float) $card['online_transaction_limit'];
        if ($limit <= 0 || (float) $amount > $limit + 0.00001) {
            return array('result' => 'declined', 'reason' => 'The online shopping limit would be exceeded.');
        }
    }

    return array('result' => 'approved', 'reason' => 'Within the configured card controls.');
}

function rb_demo_card_lock_account($db, $accountId, $clientId)
{
    $stmt = rb_demo_card_prepare($db, 'SELECT account_id, acc_status FROM ib_bankaccounts WHERE account_id = ? AND client_id = ? FOR UPDATE');
    $stmt->bind_param('ii', $accountId, $clientId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not verify the linked bank account.');
    }
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $account;
}

function rb_demo_card_lock_owned($db, $cardId, $clientId)
{
    $stmt = rb_demo_card_prepare($db, 'SELECT account_id FROM ib_demo_cards WHERE card_id = ? AND client_id = ?');
    $stmt->bind_param('ii', $cardId, $clientId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not find the demo card.');
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return array(null, null);
    }

    $account = rb_demo_card_lock_account($db, (int) $row['account_id'], $clientId);
    if (!$account) {
        return array(null, null);
    }
    $stmt = rb_demo_card_prepare($db, 'SELECT * FROM ib_demo_cards WHERE card_id = ? AND client_id = ? FOR UPDATE');
    $stmt->bind_param('ii', $cardId, $clientId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not lock the demo card.');
    }
    $card = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$card || (int) $card['account_id'] !== (int) $row['account_id']) {
        return array(null, null);
    }
    return array($card, $account);
}

function rb_demo_card_issue_date()
{
    return date('Y-m-d', strtotime('+3 years'));
}

function rb_demo_card_request($db, $clientId, $accountId, $cardType)
{
    if (!in_array($cardType, array('virtual', 'physical'), true)) {
        return rb_demo_card_result(false, 'Choose a valid card type.');
    }
    $started = false;
    try {
        if (!$db->begin_transaction()) {
            throw new RuntimeException('Could not start demo card request.');
        }
        $started = true;
        $account = rb_demo_card_lock_account($db, $accountId, $clientId);
        if (!$account) {
            $db->rollback();
            return rb_demo_card_result(false, 'Choose one of your own bank accounts.');
        }
        if (strcasecmp(trim($account['acc_status']), 'Active') !== 0) {
            $db->rollback();
            return rb_demo_card_result(false, 'A card service request requires an active account.');
        }

        $stmt = rb_demo_card_prepare($db, "SELECT card_id FROM ib_demo_cards WHERE client_id = ? AND account_id = ? AND card_type = ? AND archived_at IS NULL AND card_status IN ('requested','inactive','active') AND (expiry_date IS NULL OR expiry_date >= CURRENT_DATE()) LIMIT 1 FOR UPDATE");
        $stmt->bind_param('iis', $clientId, $accountId, $cardType);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not check for an existing demo card.');
        }
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing) {
            $db->rollback();
            return rb_demo_card_result(false, 'This account already has an active or pending card of that type.');
        }

        if ($cardType === 'virtual') {
            $reference = rb_demo_card_reference();
            $expiry = rb_demo_card_issue_date();
            $stmt = rb_demo_card_prepare($db, "INSERT INTO ib_demo_cards (client_id, account_id, card_type, card_status, card_reference, expiry_date, replacement_for_card_id) VALUES (?, ?, 'virtual', 'inactive', ?, ?, NULL)");
            $stmt->bind_param('iiss', $clientId, $accountId, $reference, $expiry);
        } else {
            $stmt = rb_demo_card_prepare($db, "INSERT INTO ib_demo_cards (client_id, account_id, card_type, card_status, card_reference, expiry_date, replacement_for_card_id) VALUES (?, ?, 'physical', 'requested', NULL, NULL, NULL)");
            $stmt->bind_param('ii', $clientId, $accountId);
        }
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not save the demo card request.');
        }
        $stmt->close();
        if (!$db->commit()) {
            throw new RuntimeException('Could not finish the demo card request.');
        }
        $started = false;
        if ($cardType === 'virtual') {
            return rb_demo_card_result(true, 'Your virtual card record is ready to activate.', array('card_id' => (int) $db->insert_id));
        }
        return rb_demo_card_result(true, 'Your ATM card service request was sent for staff approval.', array('card_id' => (int) $db->insert_id));
    } catch (Throwable $e) {
        if ($started) {
            $db->rollback();
        }
        error_log('[RuralBanking] demo card request failed: ' . $e->getMessage());
        return rb_demo_card_result(false, 'The card request could not be completed. Please try again.');
    }
}

function rb_demo_card_request_replacement($db, $clientId, $cardId)
{
    $started = false;
    try {
        if (!$db->begin_transaction()) {
            throw new RuntimeException('Could not start demo card replacement.');
        }
        $started = true;
        list($oldCard, $account) = rb_demo_card_lock_owned($db, $cardId, $clientId);
        if (!$oldCard) {
            $db->rollback();
            return rb_demo_card_result(false, 'That card record was not found on your account.');
        }
        if (!empty($oldCard['archived_at'])) {
            $db->rollback();
            return rb_demo_card_result(false, 'An archived card record cannot be replaced.');
        }
        if (strcasecmp(trim($account['acc_status']), 'Active') !== 0) {
            $db->rollback();
            return rb_demo_card_result(false, 'A replacement can only be requested for an active bank account.');
        }
        if (in_array($oldCard['card_status'], array('requested', 'rejected', 'replaced'), true)) {
            $db->rollback();
            return rb_demo_card_result(false, 'This card cannot be replaced in its current state.');
        }

        $cardType = $oldCard['card_type'];
        $accountId = (int) $oldCard['account_id'];
        $stmt = rb_demo_card_prepare($db, "SELECT card_id FROM ib_demo_cards WHERE client_id = ? AND account_id = ? AND card_type = ? AND card_id <> ? AND archived_at IS NULL AND card_status IN ('requested','inactive','active') AND (expiry_date IS NULL OR expiry_date >= CURRENT_DATE()) LIMIT 1 FOR UPDATE");
        $stmt->bind_param('iisi', $clientId, $accountId, $cardType, $cardId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not check for another active or pending card.');
        }
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing) {
            $db->rollback();
            return rb_demo_card_result(false, 'An active or pending card of this type already exists for the linked account.');
        }

        $replacementFor = (int) $cardId;
        $atmDailyLimit = $oldCard['atm_daily_limit'];
        $posLimit = $oldCard['pos_transaction_limit'];
        $onlineLimit = $oldCard['online_transaction_limit'];
        if ($cardType === 'virtual') {
            $reference = rb_demo_card_reference();
            $expiry = rb_demo_card_issue_date();
            $stmt = rb_demo_card_prepare($db, "INSERT INTO ib_demo_cards (client_id, account_id, card_type, card_status, card_reference, expiry_date, replacement_for_card_id, atm_daily_limit, pos_transaction_limit, online_transaction_limit) VALUES (?, ?, 'virtual', 'inactive', ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('iississs', $clientId, $accountId, $reference, $expiry, $replacementFor, $atmDailyLimit, $posLimit, $onlineLimit);
        } else {
            $stmt = rb_demo_card_prepare($db, "INSERT INTO ib_demo_cards (client_id, account_id, card_type, card_status, card_reference, expiry_date, replacement_for_card_id, atm_daily_limit, pos_transaction_limit, online_transaction_limit) VALUES (?, ?, 'physical', 'requested', NULL, NULL, ?, ?, ?, ?)");
            $stmt->bind_param('iiisss', $clientId, $accountId, $replacementFor, $atmDailyLimit, $posLimit, $onlineLimit);
        }
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not save the replacement request.');
        }
        $newCardId = (int) $db->insert_id;
        $stmt->close();

        if ($cardType === 'virtual') {
            $stmt = rb_demo_card_prepare($db, "UPDATE ib_demo_cards SET card_status = 'replaced', replaced_by_card_id = ?, frozen_until = NULL WHERE card_id = ? AND client_id = ?");
            $stmt->bind_param('iii', $newCardId, $cardId, $clientId);
            if (!$stmt->execute()) {
                throw new RuntimeException('Could not retire the replaced virtual card record.');
            }
            $stmt->close();
        } elseif (in_array($oldCard['card_status'], array('active', 'inactive'), true)) {
            $stmt = rb_demo_card_prepare($db, "UPDATE ib_demo_cards SET card_status = 'blocked', frozen_until = NULL WHERE card_id = ? AND client_id = ?");
            $stmt->bind_param('ii', $cardId, $clientId);
            if (!$stmt->execute()) {
                throw new RuntimeException('Could not block the card being replaced.');
            }
            $stmt->close();
        }

        if (!$db->commit()) {
            throw new RuntimeException('Could not finish the replacement request.');
        }
        $started = false;
        $message = $cardType === 'virtual'
            ? 'A replacement virtual card record was created inactive. Your previous card record was retired for safety.'
            : 'Your replacement ATM card service request was sent for staff approval. The previous card record was blocked for safety.';
        return rb_demo_card_result(true, $message, array('card_id' => $newCardId));
    } catch (Throwable $e) {
        if ($started) {
            $db->rollback();
        }
        error_log('[RuralBanking] demo card replacement failed: ' . $e->getMessage());
        return rb_demo_card_result(false, 'The replacement request could not be completed. Please try again.');
    }
}

function rb_demo_card_simulate($db, $clientId, $cardId, array $input)
{
    $channel = isset($input['channel']) && is_scalar($input['channel']) ? (string) $input['channel'] : '';
    $amount = rb_demo_card_parse_money(isset($input['amount']) ? $input['amount'] : null);
    $requestKey = isset($input['request_key']) && is_string($input['request_key']) ? $input['request_key'] : '';
    if (!in_array($channel, array('atm', 'pos', 'online'), true)) {
        return rb_demo_card_result(false, 'Choose a valid transaction channel.');
    }
    if ($amount === null) {
        return rb_demo_card_result(false, 'Enter a valid amount up to ₹10,000,000.');
    }
    if (!preg_match('/^[a-f0-9]{32}$/D', $requestKey)) {
        return rb_demo_card_result(false, 'Refresh the page and submit the authorization again.');
    }

    $started = false;
    try {
        if (!$db->begin_transaction()) {
            throw new RuntimeException('Could not start demo authorization.');
        }
        $started = true;
        list($card, $account) = rb_demo_card_lock_owned($db, $cardId, $clientId);
        if (!$card) {
            $db->rollback();
            return rb_demo_card_result(false, 'That card record was not found on your account.');
        }
        if (!empty($card['archived_at'])) {
            $db->rollback();
            return rb_demo_card_result(false, 'Archived card records cannot be used for authorizations.');
        }

        $stmt = rb_demo_card_prepare($db, 'SELECT card_transaction_id FROM ib_demo_card_transactions WHERE request_key = ? LIMIT 1');
        $stmt->bind_param('s', $requestKey);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not check the demo transaction request.');
        }
        $duplicate = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($duplicate) {
            $db->rollback();
            return rb_demo_card_result(false, 'This authorization was already recorded. Refresh before trying again.');
        }

        $dailyAtmUsed = 0.0;
        if ($channel === 'atm') {
            $stmt = rb_demo_card_prepare($db, "SELECT COALESCE(SUM(amount), 0) FROM ib_demo_card_transactions WHERE card_id = ? AND channel = 'atm' AND decision = 'approved' AND created_at >= CURRENT_DATE()");
            $stmt->bind_param('i', $cardId);
            if (!$stmt->execute()) {
                throw new RuntimeException('Could not check the demo ATM daily limit.');
            }
            $stmt->bind_result($dailyAtmUsed);
            $stmt->fetch();
            $stmt->close();
        }

        $decision = rb_demo_card_authorization_decision($card, $channel, (float) $amount, (float) $dailyAtmUsed);
        if (strcasecmp(trim($account['acc_status']), 'Active') !== 0) {
            $decision = array('result' => 'declined', 'reason' => 'The linked bank account is not active.');
        }
        $accountId = (int) $card['account_id'];
        $stmt = rb_demo_card_prepare($db, 'INSERT INTO ib_demo_card_transactions (card_id, client_id, account_id, channel, amount, decision, reason, request_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iiisssss', $cardId, $clientId, $accountId, $channel, $amount, $decision['result'], $decision['reason'], $requestKey);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not record the demo authorization.');
        }
        $stmt->close();
        if (!$db->commit()) {
            throw new RuntimeException('Could not finish the demo authorization.');
        }
        $started = false;
        return rb_demo_card_result(true, 'Authorization ' . strtoupper($decision['result']) . ': ' . $decision['reason'], array('decision' => $decision['result']));
    } catch (Throwable $e) {
        if ($started) {
            $db->rollback();
        }
        error_log('[RuralBanking] demo card authorization failed: ' . $e->getMessage());
        return rb_demo_card_result(false, 'The authorization could not be recorded. Please refresh and try again.');
    }
}

function rb_demo_card_client_action($db, $clientId, array $input)
{
    $action = isset($input['card_action']) && is_scalar($input['card_action']) ? (string) $input['card_action'] : '';
    if ($action === 'request_virtual' || $action === 'request_physical') {
        $accountId = isset($input['account_id']) && is_scalar($input['account_id'])
            ? filter_var($input['account_id'], FILTER_VALIDATE_INT)
            : false;
        if ($accountId === false || $accountId < 1) {
            return rb_demo_card_result(false, 'Choose a valid bank account.');
        }
        return rb_demo_card_request($db, (int) $clientId, (int) $accountId, $action === 'request_virtual' ? 'virtual' : 'physical');
    }

    $cardId = isset($input['card_id']) && is_scalar($input['card_id'])
        ? filter_var($input['card_id'], FILTER_VALIDATE_INT)
        : false;
    if ($cardId === false || $cardId < 1) {
        return rb_demo_card_result(false, 'Choose a valid card.');
    }
    if ($action === 'replace') {
        return rb_demo_card_request_replacement($db, (int) $clientId, (int) $cardId);
    }
    if ($action === 'simulate') {
        return rb_demo_card_simulate($db, (int) $clientId, (int) $cardId, $input);
    }

    $limits = null;
    if ($action === 'limits') {
        $limits = array(
            rb_demo_card_parse_limit(isset($input['atm_daily_limit']) ? $input['atm_daily_limit'] : null),
            rb_demo_card_parse_limit(isset($input['pos_transaction_limit']) ? $input['pos_transaction_limit'] : null),
            rb_demo_card_parse_limit(isset($input['online_transaction_limit']) ? $input['online_transaction_limit'] : null),
        );
        if (in_array(null, $limits, true)) {
            return rb_demo_card_result(false, 'Each card limit must be between ₹0 and ₹100,000, with up to two decimals.');
        }
    }
    $hours = isset($input['freeze_hours']) && is_scalar($input['freeze_hours'])
        ? filter_var($input['freeze_hours'], FILTER_VALIDATE_INT)
        : false;
    if ($action === 'freeze' && !in_array($hours, array(1, 24, 168), true)) {
        return rb_demo_card_result(false, 'Choose a supported temporary freeze duration.');
    }
    if (!in_array($action, array('activate', 'block', 'freeze', 'unfreeze', 'limits'), true)) {
        return rb_demo_card_result(false, 'Choose a supported card action.');
    }

    $started = false;
    try {
        if (!$db->begin_transaction()) {
            throw new RuntimeException('Could not start demo card update.');
        }
        $started = true;
        list($card, $account) = rb_demo_card_lock_owned($db, (int) $cardId, (int) $clientId);
        if (!$card) {
            $db->rollback();
            return rb_demo_card_result(false, 'That card record was not found on your account.');
        }
        if (!empty($card['archived_at'])) {
            $db->rollback();
            return rb_demo_card_result(false, 'This card record has been archived and cannot be changed.');
        }

        if ($action === 'activate') {
            if ($card['card_status'] !== 'inactive' || empty($card['expiry_date']) || $card['expiry_date'] < date('Y-m-d')) {
                $db->rollback();
                return rb_demo_card_result(false, 'Only an unexpired, issued card record can be activated.');
            }
            if (strcasecmp(trim($account['acc_status']), 'Active') !== 0) {
                $db->rollback();
                return rb_demo_card_result(false, 'The linked bank account is not active.');
            }
            $sql = "UPDATE ib_demo_cards SET card_status = 'active' WHERE card_id = ? AND client_id = ?";
            $msg = 'Your card record is active.';
        } elseif ($action === 'block') {
            if (!in_array($card['card_status'], array('active', 'inactive'), true)) {
                $db->rollback();
                return rb_demo_card_result(false, 'Only an issued, unblocked card record can be permanently blocked.');
            }
            $sql = "UPDATE ib_demo_cards SET card_status = 'blocked', frozen_until = NULL WHERE card_id = ? AND client_id = ?";
            $msg = 'Your card record was permanently blocked. It cannot be reactivated.';
        } elseif ($action === 'freeze') {
            if ($card['card_status'] !== 'active' || $card['expiry_date'] < date('Y-m-d')) {
                $db->rollback();
                return rb_demo_card_result(false, 'Only an active, unexpired card record can be temporarily frozen.');
            }
            $frozenUntil = date('Y-m-d H:i:s', time() + ((int) $hours * 3600));
            $sql = 'UPDATE ib_demo_cards SET frozen_until = ? WHERE card_id = ? AND client_id = ?';
            $msg = 'Your card is temporarily frozen until ' . $frozenUntil . '.';
        } elseif ($action === 'unfreeze') {
            if ($card['card_status'] !== 'active') {
                $db->rollback();
                return rb_demo_card_result(false, 'Only an active card can be unfrozen.');
            }
            $sql = 'UPDATE ib_demo_cards SET frozen_until = NULL WHERE card_id = ? AND client_id = ?';
            $msg = 'Your temporary card freeze has been removed.';
        } else {
            if (!in_array($card['card_status'], array('inactive', 'active'), true)) {
                $db->rollback();
                return rb_demo_card_result(false, 'Limits can only be changed on an issued, unblocked card record.');
            }
            $sql = 'UPDATE ib_demo_cards SET atm_daily_limit = ?, pos_transaction_limit = ?, online_transaction_limit = ? WHERE card_id = ? AND client_id = ?';
            $stmt = rb_demo_card_prepare($db, $sql);
            $stmt->bind_param('ssiii', $limits[0], $limits[1], $limits[2], $cardId, $clientId);
            if (!$stmt->execute()) {
                throw new RuntimeException('Could not update demo card limits.');
            }
            $stmt->close();
            if (!$db->commit()) {
                throw new RuntimeException('Could not finish demo card limit update.');
            }
            $started = false;
            return rb_demo_card_result(true, 'Your card limits were updated.');
        }

        $stmt = rb_demo_card_prepare($db, $sql);
        if ($action === 'freeze') {
            $stmt->bind_param('sii', $frozenUntil, $cardId, $clientId);
        } else {
            $stmt->bind_param('ii', $cardId, $clientId);
        }
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not update the demo card.');
        }
        $stmt->close();
        if (!$db->commit()) {
            throw new RuntimeException('Could not finish demo card update.');
        }
        $started = false;
        return rb_demo_card_result(true, $msg);
    } catch (Throwable $e) {
        if ($started) {
            $db->rollback();
        }
        error_log('[RuralBanking] demo card action failed: ' . $e->getMessage());
        return rb_demo_card_result(false, 'The card update could not be completed. Please try again.');
    }
}

function rb_demo_card_staff_action($db, $cardId, $action, $reviewerRole, $reviewerId)
{
    if (!in_array($action, array('approve', 'reject', 'archive', 'restore', 'activate', 'deactivate'), true)
        || !in_array($reviewerRole, array('admin', 'staff'), true)
        || (int) $cardId < 1
        || (int) $reviewerId < 1
    ) {
        return rb_demo_card_result(false, 'Choose a valid card management action.');
    }
    $started = false;
    try {
        if (!$db->begin_transaction()) {
            throw new RuntimeException('Could not start card request review.');
        }
        $started = true;
        if (in_array($action, array('archive', 'restore', 'activate', 'deactivate'), true)) {
            $account = null;
            if ($action === 'activate') {
                $stmt = rb_demo_card_prepare($db, 'SELECT client_id, account_id FROM ib_demo_cards WHERE card_id = ?');
                $stmt->bind_param('i', $cardId);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Could not load the card account.');
                }
                $accountLink = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$accountLink) {
                    $db->rollback();
                    return rb_demo_card_result(false, 'That card record no longer exists.');
                }
                $account = rb_demo_card_lock_account($db, (int) $accountLink['account_id'], (int) $accountLink['client_id']);
                if (!$account || strcasecmp(trim((string) $account['acc_status']), 'Active') !== 0) {
                    $db->rollback();
                    return rb_demo_card_result(false, 'The linked bank account must be active before activating this card.');
                }
            }
            $stmt = rb_demo_card_prepare($db, 'SELECT client_id, account_id, card_status, card_reference, expiry_date, archived_at FROM ib_demo_cards WHERE card_id = ? FOR UPDATE');
            $stmt->bind_param('i', $cardId);
            if (!$stmt->execute()) {
                throw new RuntimeException('Could not load the card record.');
            }
            $card = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$card) {
                $db->rollback();
                return rb_demo_card_result(false, 'That card record no longer exists.');
            }
            if ($action === 'archive' && !empty($card['archived_at'])) {
                $db->rollback();
                return rb_demo_card_result(false, 'That card record is already archived.');
            }
            if ($action === 'restore' && empty($card['archived_at'])) {
                $db->rollback();
                return rb_demo_card_result(false, 'That card record is not archived.');
            }
            if (($action === 'activate' || $action === 'deactivate') && !empty($card['archived_at'])) {
                $db->rollback();
                return rb_demo_card_result(false, 'Restore the archived card record before changing its status.');
            }
            if ($action === 'archive') {
                $stmt = rb_demo_card_prepare($db, "UPDATE ib_demo_cards SET archived_at = CURRENT_TIMESTAMP, archived_by_role = ?, archived_by_id = ?, card_status = IF(card_status = 'active', 'inactive', card_status), frozen_until = NULL WHERE card_id = ? AND archived_at IS NULL");
                $stmt->bind_param('sii', $reviewerRole, $reviewerId, $cardId);
                $auditAction = 'demo_card_archived';
                $message = 'Card record archived. Card and authorization history are retained; active cards were deactivated.';
            } elseif ($action === 'restore') {
                $stmt = rb_demo_card_prepare($db, "UPDATE ib_demo_cards SET archived_at = NULL, archived_by_role = NULL, archived_by_id = NULL, card_status = IF(card_status = 'active', 'inactive', card_status), frozen_until = NULL WHERE card_id = ? AND archived_at IS NOT NULL");
                $stmt->bind_param('i', $cardId);
                $auditAction = 'demo_card_restored';
                $message = 'Card record restored. If it was active before archiving, it remains inactive until deliberately activated.';
            } elseif ($action === 'activate') {
                if ($card['card_status'] !== 'inactive' || empty($card['card_reference']) || empty($card['expiry_date']) || $card['expiry_date'] < date('Y-m-d')) {
                    $db->rollback();
                    return rb_demo_card_result(false, 'Only an unexpired, issued inactive card record can be activated.');
                }
                if ((int) $card['account_id'] !== (int) $accountLink['account_id']
                    || (int) $card['client_id'] !== (int) $accountLink['client_id']
                ) {
                    $db->rollback();
                    return rb_demo_card_result(false, 'The card account changed. Refresh the card records and try again.');
                }
                $stmt = rb_demo_card_prepare($db, "UPDATE ib_demo_cards SET card_status = 'active' WHERE card_id = ? AND card_status = 'inactive' AND archived_at IS NULL");
                $stmt->bind_param('i', $cardId);
                $auditAction = 'demo_card_activated_by_staff';
                $message = 'Card record activated.';
            } else {
                if ($card['card_status'] !== 'active') {
                    $db->rollback();
                    return rb_demo_card_result(false, 'Only an active card record can be deactivated.');
                }
                $stmt = rb_demo_card_prepare($db, "UPDATE ib_demo_cards SET card_status = 'inactive', frozen_until = NULL WHERE card_id = ? AND card_status = 'active' AND archived_at IS NULL");
                $stmt->bind_param('i', $cardId);
                $auditAction = 'demo_card_deactivated_by_staff';
                $message = 'Card record deactivated. It remains in the audit history.';
            }
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('Could not update the archived card record.');
            }
            $stmt->close();
            $entityId = (string) $cardId;
            $details = 'Previous card status: ' . (string) $card['card_status'] . '; card archive/status action: ' . $action;
            $stmt = rb_demo_card_prepare($db, 'INSERT INTO ib_audit_log (actor_role, actor_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?, ?)');
            $entityType = 'demo_card';
            $stmt->bind_param('sissss', $reviewerRole, $reviewerId, $auditAction, $entityType, $entityId, $details);
            if (!$stmt->execute()) {
                throw new RuntimeException('Could not record the card archive audit entry.');
            }
            $stmt->close();
            if (!$db->commit()) {
                throw new RuntimeException('Could not finish card archive update.');
            }
            $started = false;
            return rb_demo_card_result(true, $message);
        }

        $stmt = rb_demo_card_prepare($db, "SELECT client_id, account_id FROM ib_demo_cards WHERE card_id = ? AND card_status = 'requested' AND archived_at IS NULL");
        $stmt->bind_param('i', $cardId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not find the pending card request.');
        }
        $request = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$request) {
            $db->rollback();
            return rb_demo_card_result(false, 'That card request is no longer pending.');
        }
        $account = rb_demo_card_lock_account($db, (int) $request['account_id'], (int) $request['client_id']);
        if (!$account) {
            $db->rollback();
            return rb_demo_card_result(false, 'The linked bank account no longer exists.');
        }
        $stmt = rb_demo_card_prepare($db, "SELECT * FROM ib_demo_cards WHERE card_id = ? AND card_status = 'requested' AND archived_at IS NULL FOR UPDATE");
        $stmt->bind_param('i', $cardId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not lock the card request.');
        }
        $card = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$card) {
            $db->rollback();
            return rb_demo_card_result(false, 'That card request is no longer pending.');
        }

        if ($action === 'reject') {
            $stmt = rb_demo_card_prepare($db, "UPDATE ib_demo_cards SET card_status = 'rejected', reviewed_by_role = ?, reviewed_by_id = ?, reviewed_at = CURRENT_TIMESTAMP WHERE card_id = ? AND card_status = 'requested' AND archived_at IS NULL");
            $stmt->bind_param('sii', $reviewerRole, $reviewerId, $cardId);
            if (!$stmt->execute()) {
                throw new RuntimeException('Could not reject the card request.');
            }
            $stmt->close();
            $message = 'The card request was rejected.';
        } else {
            if (strcasecmp(trim($account['acc_status']), 'Active') !== 0) {
                $db->rollback();
                return rb_demo_card_result(false, 'The linked bank account is not active; this request cannot be issued.');
            }
            $replacementFor = (int) $card['replacement_for_card_id'];
            if ($replacementFor > 0) {
                $stmt = rb_demo_card_prepare($db, 'SELECT card_id, card_status, archived_at FROM ib_demo_cards WHERE card_id = ? AND client_id = ? AND account_id = ? FOR UPDATE');
                $stmt->bind_param('iii', $replacementFor, $card['client_id'], $card['account_id']);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Could not verify the card being replaced.');
                }
                $oldCard = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$oldCard || !empty($oldCard['archived_at']) || in_array($oldCard['card_status'], array('replaced', 'rejected', 'requested'), true)) {
                    $db->rollback();
                    return rb_demo_card_result(false, 'The original card is no longer eligible for replacement.');
                }
            }

            $reference = rb_demo_card_reference();
            $expiry = rb_demo_card_issue_date();
            $stmt = rb_demo_card_prepare($db, "UPDATE ib_demo_cards SET card_status = 'inactive', card_reference = ?, expiry_date = ?, reviewed_by_role = ?, reviewed_by_id = ?, reviewed_at = CURRENT_TIMESTAMP WHERE card_id = ? AND card_status = 'requested' AND archived_at IS NULL");
            $stmt->bind_param('sssii', $reference, $expiry, $reviewerRole, $reviewerId, $cardId);
            if (!$stmt->execute()) {
                throw new RuntimeException('Could not create the card reference.');
            }
            $stmt->close();
            if ($replacementFor > 0) {
                $stmt = rb_demo_card_prepare($db, "UPDATE ib_demo_cards SET card_status = 'replaced', replaced_by_card_id = ?, frozen_until = NULL WHERE card_id = ? AND client_id = ?");
                $stmt->bind_param('iii', $cardId, $replacementFor, $card['client_id']);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Could not retire the replaced card record.');
                }
                $stmt->close();
            }
            $message = 'The card request was approved; the card record is inactive pending customer activation.';
        }

        if (!$db->commit()) {
            throw new RuntimeException('Could not finish card request review.');
        }
        $started = false;
        return rb_demo_card_result(true, $message);
    } catch (Throwable $e) {
        if ($started) {
            $db->rollback();
        }
        error_log('[RuralBanking] demo card request review failed: ' . $e->getMessage());
        return rb_demo_card_result(false, 'The card request could not be processed. Please try again.');
    }
}
