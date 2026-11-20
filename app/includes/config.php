<?php
/**
 * config.php
 *
 * Site settings, read from environment variables (set them in the .env file
 * next to compose.yaml). Nothing secret is stored in this file.
 * Admin accounts live in the database and are created on the first-run setup page.
 */

/** Reads an environment variable, falling back to $default when it's unset or empty. */
function env($name, $default = '') {
    $value = getenv($name);
    return ($value === false || $value === '') ? $default : $value;
}

function env_bool($name, $default) {
    $value = strtolower(env($name, $default ? 'true' : 'false'));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

// --- Database (SQLite) ---
// The folder must be writable by the web server (it's the /data volume in Docker).
define('DB_PATH', env('DB_PATH', '/data/xmas.sqlite'));

// --- Site ---
// Public base URL used when building participants' reveal links. Must end with "/".
define('BASE_URL', rtrim(env('BASE_URL', 'http://localhost:8080/'), '/') . '/');
// Timezone used for displaying dates and deciding the drawing year.
define('APP_TIMEZONE', env('APP_TIMEZONE', 'America/Chicago'));
// Requests from these addresses (comma-separated) come through a reverse proxy, so the
// real client IP and protocol are taken from its X-Forwarded-* headers. Leave empty if none.
define('TRUSTED_PROXIES', array_values(array_filter(array_map('trim', explode(',', env('TRUSTED_PROXIES', ''))))));

// --- Admin login ---
const SESSION_IDLE_TIMEOUT = 8 * 3600;       // logged out after 8 hours without activity
const SESSION_ABSOLUTE_TIMEOUT = 7 * 86400;  // and always after 7 days
const LOGIN_MAX_FAILURES = 5;                // failures allowed per IP or per username...
const LOGIN_LOCKOUT_SECONDS = 900;           // ...within this many seconds (15 minutes)
const PASSWORD_MIN_LENGTH = 10;

// --- SMS via VoIP.ms ---
// API access must be enabled in the VoIP.ms portal (Main Menu > SOAP and
// REST/JSON API) with this server's public IP allowed.
define('VOIPMS_API_USERNAME', env('VOIPMS_API_USERNAME'));
define('VOIPMS_API_PASSWORD', env('VOIPMS_API_PASSWORD'));
define('VOIPMS_DID', env('VOIPMS_DID')); // SMS-enabled number texts are sent from

// Test mode: every text goes to SMS_TEST_NUMBER instead of the participant,
// prefixed with who it was meant for. Defaults to ON; set SMS_TEST_MODE=false to text for real.
define('SMS_TEST_MODE', env_bool('SMS_TEST_MODE', true));
define('SMS_TEST_NUMBER', env('SMS_TEST_NUMBER'));
