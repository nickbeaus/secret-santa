<?php
/**
 * sms.php
 *
 * Texting participants their reveal links through the VoIP.ms REST API.
 * Texts are sent as MMS (sendMMS), which allows up to 2048 characters and emoji,
 * so the group's custom message and gift-ideas link can be included.
 * Settings (credentials, sending number, test mode) come from the .env file (see config.php).
 */

const SMS_MAX_LENGTH = 2048; // VoIP.ms sendMMS message limit
const VOIPMS_TIMEOUT = 60;   // seconds; VoIP.ms can take a while to answer
const SMS_DEFAULT_TEMPLATE = "Hi {name}! Your Secret Santa pick for {group} is ready. Tap to see who you got: {link}\n\n{message}\n\nGift ideas: {gifts}";

if (!defined('VOIPMS_API_URL')) {
    define('VOIPMS_API_URL', 'https://voip.ms/api/v1/rest.php'); // overridable for testing
}

/** Splits UTF-8 text into characters (the mbstring extension isn't installed here). */
function utf8_chars($text) {
    return preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

function sms_configured() {
    return VOIPMS_API_USERNAME !== '' && VOIPMS_API_PASSWORD !== '' && VOIPMS_DID !== '';
}

/** Normalizes line endings and spacing, and drops runs of blank lines. */
function sms_clean($text) {
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/ *\n */', "\n", $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
}

function sms_length($text) {
    return count(utf8_chars($text));
}

/** Returns a description of what's wrong with an outgoing text, or '' if it's fine. */
function sms_problem($text) {
    $len = sms_length($text);
    return $len > SMS_MAX_LENGTH ? "Too long: {$len} of " . SMS_MAX_LENGTH . " characters." : '';
}

/** The group's short gift-ideas link, or '' if the group has no gift URL. */
function group_gifts_url($group) {
    return (!empty($group['short_code']) && is_web_url($group['gift_idea_url'] ?? '')) ? BASE_URL . 'g/' . $group['short_code'] : '';
}

/**
 * Fills in a template for one participant.
 * Placeholders: {name} (first name), {fullname}, {group}, {link}, {message}, {gifts}.
 * Lines using {message} or {gifts} are dropped when the group has no message / gift link.
 */
function render_sms($template, $participant_name, $group, $short_code) {
    $values = [
        '{name}'     => preg_split('/\s+/', trim($participant_name))[0] ?? $participant_name,
        '{fullname}' => trim($participant_name),
        '{group}'    => $group['name'],
        '{link}'     => short_reveal_url($short_code),
        '{message}'  => trim((string)($group['reveal_message'] ?? '')),
        '{gifts}'    => group_gifts_url($group),
    ];
    $lines = [];
    foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $template)) as $line) {
        if ((str_contains($line, '{message}') && $values['{message}'] === '') ||
            (str_contains($line, '{gifts}') && $values['{gifts}'] === '')) {
            continue;
        }
        $lines[] = strtr($line, $values);
    }
    return sms_clean(implode("\n", $lines));
}

/**
 * Sends one MMS through VoIP.ms.
 * Returns ['ok' => bool, 'id' => provider id or null, 'error' => message or null].
 */
function voipms_send_message($to, $message) {
    $query = http_build_query([
        'api_username' => VOIPMS_API_USERNAME,
        'api_password' => VOIPMS_API_PASSWORD,
        'method'       => 'sendMMS',
        'did'          => VOIPMS_DID,
        'dst'          => $to,
        'message'      => $message,
    ]);
    $context = stream_context_create(['http' => ['timeout' => VOIPMS_TIMEOUT, 'ignore_errors' => true]]);
    $started = microtime(true);
    $body = @file_get_contents(VOIPMS_API_URL . '?' . $query, false, $context);
    $elapsed = microtime(true) - $started;

    if ($body === false) {
        // Log the low-level reason (never the URL, which contains the API password).
        $reason = preg_replace('/https?:\/\/\S+/', '[url]', error_get_last()['message'] ?? 'unknown');
        error_log(sprintf('Secret Santa: VoIP.ms sendMMS failed after %.1fs: %s', $elapsed, $reason));
        if ($elapsed >= VOIPMS_TIMEOUT - 1) {
            return ['ok' => false, 'id' => null, 'error' => 'VoIP.ms did not answer within ' . VOIPMS_TIMEOUT . ' seconds. The text MAY still have been sent; check the VoIP.ms message log before resending.'];
        }
        return ['ok' => false, 'id' => null, 'error' => 'Could not connect to VoIP.ms (network error).'];
    }
    $json = json_decode($body, true);
    $status = $json['status'] ?? 'unexpected_response';
    if ($status === 'success') {
        return ['ok' => true, 'id' => (string)($json['mms'] ?? $json['sms'] ?? ''), 'error' => null];
    }

    $known = [
        'invalid_did'         => 'VoIP.ms rejected the sending number (VOIPMS_DID in .env). Check it is your SMS/MMS-enabled DID.',
        'invalid_credentials' => 'VoIP.ms rejected the API username or API password (check VOIPMS_API_USERNAME and VOIPMS_API_PASSWORD in .env).',
        'ip_not_enabled'      => "VoIP.ms API isn't enabled for this server's public IP address. Add it in the VoIP.ms portal under SOAP and REST/JSON API.",
    ];
    error_log("Secret Santa: VoIP.ms sendMMS returned {$status}");
    return ['ok' => false, 'id' => null, 'error' => $known[$status] ?? "VoIP.ms error: {$status}"];
}

/** The text a participant gets; in test mode it starts with "TEST for (name):". */
function participant_sms_message($group, $p, $test) {
    $message = render_sms($group['sms_template'] ?: SMS_DEFAULT_TEMPLATE, $p['giver_name'], $group, $p['giver_short_code']);
    return $test ? 'TEST for ' . $p['giver_name'] . ":\n" . $message : $message;
}

/**
 * Texts one participant their reveal link and logs the attempt.
 * In test mode the text goes to SMS_TEST_NUMBER, prefixed with the intended recipient.
 * $p needs: user_id, giver_name, giver_phone, giver_short_code.
 */
function send_participant_sms($pdo, $group, $p, $force_test = false) {
    $test = SMS_TEST_MODE || $force_test;
    $phone = normalize_phone($p['giver_phone']);

    if (!sms_configured()) {
        return ['ok' => false, 'error' => 'Texting is not configured yet (set the VOIPMS_* values in .env).'];
    }
    if (!$test && !$phone) {
        return ['ok' => false, 'error' => "{$p['giver_name']} has no valid mobile number."];
    }

    $message = participant_sms_message($group, $p, $test);
    if ($problem = sms_problem($message)) {
        return ['ok' => false, 'error' => $problem];
    }

    $to = $test ? SMS_TEST_NUMBER : $phone;
    $result = voipms_send_message($to, $message);

    $pdo->prepare("INSERT INTO sms_log (group_id, user_id, phone_number, message, test_mode, success, provider_id, error) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$group['id'], $p['user_id'], $to, $message, $test ? 1 : 0, $result['ok'] ? 1 : 0, $result['id'], $result['error']]);

    return $result;
}
