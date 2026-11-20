<?php
/**
 * bootstrap.php
 *
 * Shared setup included by every page: settings, security headers, the
 * database connection ($pdo), admin session/CSRF handling, and small helpers.
 *
 *   require_once __DIR__ . '/../includes/bootstrap.php';
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

date_default_timezone_set(APP_TIMEZONE);

// Never show PHP errors to visitors; they go to the Apache error log instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// --- Request helpers ---

function from_trusted_proxy() {
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', TRUSTED_PROXIES, true);
}

/** The visitor's real IP address, looking through the reverse proxy. */
function client_ip() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (from_trusted_proxy() && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // The proxy appends the address it saw, so the last entry is the trustworthy one.
        $forwarded = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
        $candidate = end($forwarded);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            $ip = $candidate;
        }
    }
    return $ip;
}

function is_https() {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return from_trusted_proxy() && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

// --- Security headers ---

/** Per-request nonce; every inline <script> must carry nonce="<?php echo csp_nonce(); ?>". */
function csp_nonce() {
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }
    return $nonce;
}

function send_security_headers() {
    $csp = [
        "default-src 'self'",
        "script-src 'nonce-" . csp_nonce() . "' https://cdn.jsdelivr.net",
        "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com",
        "font-src https://fonts.gstatic.com",
        "img-src 'self' data:",
        "connect-src 'self'",
        "object-src 'none'",
        "base-uri 'none'",
        "form-action 'self'",
        "frame-ancestors 'none'",
    ];
    header('Content-Security-Policy: ' . implode('; ', $csp));
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    // Reveal links carry a private UUID in the URL; never leak it to other sites.
    header('Referrer-Policy: same-origin');
    // Assignments and admin pages should not linger in shared browser caches.
    header('Cache-Control: no-store');
}

send_security_headers();

// --- Database ---

try {
    $pdo = db_connect();
    // The schema is created/updated by bin/migrate.php when the container starts.
    if (db_schema_version($pdo) === 0) {
        throw new RuntimeException('The database has no tables yet (bin/migrate.php has not run).');
    }
} catch (Throwable $e) {
    error_log('Secret Santa DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('The site is temporarily unavailable. Please try again later.');
}

// --- Admin accounts, sessions & CSRF ---

require_once __DIR__ . '/auth.php';

// --- Helpers ---

/** Escapes a value for HTML output. */
function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Formats a UTC timestamp from the database in the site's timezone. */
function format_local($utc_timestamp, $format) {
    $dt = new DateTime($utc_timestamp, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone(APP_TIMEZONE));
    return $dt->format($format);
}

/** Logs the real error and returns a message that is safe to show on the page. */
function db_error_message($context, Throwable $e) {
    error_log("Secret Santa: {$context}: " . $e->getMessage());
    return "{$context}. A database error occurred; details were written to the server log.";
}

/** Generates a random Version 4 UUID. */
function generate_uuidv4() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Normalizes a US/Canada phone number to 10 digits (the format VoIP.ms SMS expects).
 * Accepts things like "(630) 555-0123", "630.555.0123", "+1 630 555 0123".
 * Returns '' for blank input, or false if it isn't a valid 10-digit NANP number.
 */
function normalize_phone($input) {
    $digits = preg_replace('/\D/', '', (string)$input);
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) === 11 && $digits[0] === '1') {
        $digits = substr($digits, 1);
    }
    // Area code and exchange can't start with 0 or 1.
    return preg_match('/^[2-9]\d{2}[2-9]\d{6}$/', $digits) ? $digits : false;
}

/** Displays a stored 10-digit phone number as (630) 555-0123. */
function format_phone($digits) {
    $digits = (string)$digits;
    return preg_match('/^(\d{3})(\d{3})(\d{4})$/', $digits, $m) ? "({$m[1]}) {$m[2]}-{$m[3]}" : $digits;
}

/** Random 8-character code (letters + digits) for a user's short reveal link. */
function generate_short_code() {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'; // no look-alikes (0/O, 1/l/I)
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $code;
}

/** A user's short reveal link, e.g. https://xmas.example.org/r/Ab3dEf9h */
function short_reveal_url($short_code) {
    return BASE_URL . 'r/' . $short_code;
}

/** Only http(s) links are allowed, so a saved URL can't run script when clicked. */
function is_web_url($url) {
    return filter_var($url, FILTER_VALIDATE_URL)
        && in_array(strtolower(parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
}
