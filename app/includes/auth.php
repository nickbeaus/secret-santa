<?php
/**
 * auth.php
 *
 * Admin accounts, login sessions, lockouts and CSRF protection.
 *
 * - Accounts live in the `admins` table with one-way password hashes (Argon2id).
 * - A session is valid for SESSION_IDLE_TIMEOUT without activity and never longer than
 *   SESSION_ABSOLUTE_TIMEOUT. Changing a password signs out that admin's other sessions.
 * - Failed logins are counted per IP and per username; too many locks both out for a while.
 * - Until the first admin exists, every admin page redirects to setup.php, which needs the
 *   one-time setup code printed in the container log.
 */

// --- Passwords ---

function password_algo() {
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}

function hash_admin_password($password) {
    return password_hash($password, password_algo());
}

/** Returns what's wrong with a new password, or '' if it's acceptable. */
function password_problem($password, $confirm) {
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        return 'The password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
    }
    if (strlen($password) > 1024) {
        return 'The password is too long.';
    }
    if ($password !== $confirm) {
        return "The two passwords don't match.";
    }
    return '';
}

/** Returns what's wrong with a username, or '' if it's acceptable. */
function username_problem($username) {
    if (!preg_match('/^[A-Za-z0-9._@-]{3,64}$/', $username)) {
        return 'The username must be 3 to 64 characters: letters, numbers, and . _ @ -';
    }
    return '';
}

/** Adds an admin. Returns the new id, or throws on a duplicate username. */
function create_admin(PDO $pdo, $username, $password) {
    $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)')
        ->execute([$username, hash_admin_password($password)]);
    return (int)$pdo->lastInsertId();
}

/** Sets a new password and signs out every existing session of that admin. */
function set_admin_password(PDO $pdo, $admin_id, $password) {
    $pdo->prepare('UPDATE admins SET password_hash = ?, session_epoch = session_epoch + 1, password_changed_at = CURRENT_TIMESTAMP WHERE id = ?')
        ->execute([hash_admin_password($password), $admin_id]);
}

function admin_count(PDO $pdo) {
    return (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
}

// --- Sessions ---

function start_admin_session() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('xmas_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string)SESSION_IDLE_TIMEOUT);
    session_start();
}

/**
 * The logged-in admin's row (id, username, ...), or null.
 * Checks the timeouts, and that the account still exists with an unchanged password.
 */
function current_admin() {
    global $pdo;
    static $admin = false;
    if ($admin !== false) {
        return $admin;
    }
    start_admin_session();
    $admin = null;
    $id = $_SESSION['admin_id'] ?? null;
    if (!$id) {
        return null;
    }
    $now = time();
    if ($now - ($_SESSION['seen_at'] ?? 0) > SESSION_IDLE_TIMEOUT || $now - ($_SESSION['login_at'] ?? 0) > SESSION_ABSOLUTE_TIMEOUT) {
        end_admin_session();
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, username, session_epoch, created_at, password_changed_at, last_login_at FROM admins WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['session_epoch'] !== (int)($_SESSION['epoch'] ?? -1)) {
        end_admin_session();
        return null;
    }
    $_SESSION['seen_at'] = $now;
    return $admin = $row;
}

function is_admin() {
    return current_admin() !== null;
}

/** Signs this browser in as $admin (a row with id + session_epoch). */
function begin_admin_session(PDO $pdo, array $admin) {
    start_admin_session();
    session_regenerate_id(true);
    $_SESSION = [
        'admin_id'   => (int)$admin['id'],
        'epoch'      => (int)$admin['session_epoch'],
        'login_at'   => time(),
        'seen_at'    => time(),
        'csrf_token' => bin2hex(random_bytes(32)),
    ];
    $pdo->prepare('UPDATE admins SET last_login_at = CURRENT_TIMESTAMP, last_login_ip = ? WHERE id = ?')
        ->execute([client_ip(), $admin['id']]);
}

/** After a password change: keep this browser signed in under the new epoch. */
function refresh_admin_session(PDO $pdo, $admin_id) {
    $stmt = $pdo->prepare('SELECT session_epoch FROM admins WHERE id = ?');
    $stmt->execute([$admin_id]);
    $_SESSION['epoch'] = (int)$stmt->fetchColumn();
    session_regenerate_id(true);
}

function end_admin_session() {
    start_admin_session();
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $params['path'],
        'secure'   => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'],
    ]);
    session_destroy();
}

// --- Logging in, with lockouts ---

/** True if this IP or this username has too many recent failures. */
function login_locked_out(PDO $pdo, $ip, $username) {
    $since = time() - LOGIN_LOCKOUT_SECONDS;
    $stmt = $pdo->prepare('SELECT
            (SELECT COUNT(*) FROM login_failures WHERE ip_address = ? AND attempted_at > ?),
            (SELECT COUNT(*) FROM login_failures WHERE username = ? AND attempted_at > ?)');
    $stmt->execute([$ip, $since, $username, $since]);
    [$by_ip, $by_user] = $stmt->fetch(PDO::FETCH_NUM);
    return $by_ip >= LOGIN_MAX_FAILURES || ($username !== '' && $by_user >= LOGIN_MAX_FAILURES);
}

/**
 * Checks a username/password. Returns ['ok' => true, 'admin' => row] or ['ok' => false, 'error' => message].
 * Always takes about as long whether or not the username exists.
 */
function attempt_admin_login(PDO $pdo, $username, $password) {
    $ip = client_ip();
    $pdo->prepare('DELETE FROM login_failures WHERE attempted_at < ?')->execute([time() - LOGIN_LOCKOUT_SECONDS]);

    $locked = 'Too many failed attempts. Please wait ' . (int)(LOGIN_LOCKOUT_SECONDS / 60) . ' minutes and try again.';
    if (login_locked_out($pdo, $ip, $username)) {
        return ['ok' => false, 'error' => $locked];
    }

    $stmt = $pdo->prepare('SELECT id, username, password_hash, session_epoch FROM admins WHERE username = ?');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();
    // A fixed dummy hash keeps the timing the same for unknown usernames.
    $hash = $admin['password_hash'] ?? '$argon2id$v=19$m=65536,t=4,p=1$cDd0UUpwYVBPL0dITEg5ZA$lkadNNBdGiFqFeakTcX5m3kmExas6at/6Cw8qB0dAmU';

    if (password_verify($password, $hash) && $admin) {
        $pdo->prepare('DELETE FROM login_failures WHERE ip_address = ? OR username = ?')->execute([$ip, $username]);
        if (password_needs_rehash($admin['password_hash'], password_algo())) {
            $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([hash_admin_password($password), $admin['id']]);
        }
        return ['ok' => true, 'admin' => $admin];
    }

    $pdo->prepare('INSERT INTO login_failures (ip_address, username, attempted_at) VALUES (?, ?, ?)')
        ->execute([$ip, mb_substr_safe($username, 64), time()]);
    error_log('Secret Santa: failed admin login for "' . mb_substr_safe($username, 64) . '" from ' . $ip);
    return ['ok' => false, 'error' => login_locked_out($pdo, $ip, $username) ? $locked : 'Invalid username or password.'];
}

/** First $n characters of a UTF-8 string (mbstring may not be installed). */
function mb_substr_safe($text, $n) {
    preg_match('/^.{0,' . (int)$n . '}/us', (string)$text, $m);
    return $m[0] ?? '';
}

// --- First-run setup code ---

/** The one-time code needed on setup.php, created if missing. Only meaningful while no admin exists. */
function setup_code(PDO $pdo) {
    $code = setting_get($pdo, 'setup_code');
    if ($code === null) {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $code = implode('-', str_split($code, 4));
        setting_set($pdo, 'setup_code', $code);
    }
    return $code;
}

function setup_code_matches(PDO $pdo, $entered) {
    $code = setting_get($pdo, 'setup_code');
    $normalize = fn($c) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$c));
    return $code !== null && hash_equals($normalize($code), $normalize($entered));
}

// --- CSRF ---

function csrf_token() {
    start_admin_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

/** Stops the request if a POST doesn't carry this session's CSRF token. */
function verify_csrf() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        http_response_code(400);
        exit('Your session expired or the form was submitted from another site. Please go back, reload the page and try again.');
    }
}

/** Call at the top of every admin page: sends to setup or login if needed, and checks CSRF on POSTs. */
function require_admin() {
    global $pdo;
    start_admin_session();
    if (admin_count($pdo) === 0) {
        header('Location: setup.php');
        exit();
    }
    if (!is_admin()) {
        $back = basename($_SERVER['SCRIPT_NAME']) . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
        header('Location: admin_login.php' . ($_SERVER['REQUEST_METHOD'] === 'GET' ? '?next=' . rawurlencode($back) : ''));
        exit();
    }
    verify_csrf();
}

/** A same-site page to return to after login (only plain local .php pages are allowed). */
function safe_next_page($next) {
    return (is_string($next) && preg_match('/^[a-z_]+\.php(\?[A-Za-z0-9_=&%.-]*)?$/', $next)) ? $next : 'admin.php';
}
