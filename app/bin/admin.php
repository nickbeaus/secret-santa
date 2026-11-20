<?php
/**
 * admin.php: manage admin accounts from the command line (e.g. if you're locked out).
 *
 *   php bin/admin.php list
 *   php bin/admin.php create <username>           asks for a password
 *   php bin/admin.php reset-password <username>   asks for a new password; signs out their sessions
 *   php bin/admin.php delete <username>
 *   php bin/admin.php unlock                      clears all failed-login lockouts
 *   php bin/admin.php setup-code                  shows the first-run setup code
 *
 * In Docker:  docker compose exec web php bin/admin.php <command>
 */
require __DIR__ . '/_cli.php';

/** Reads a password without echoing it when attached to a terminal; otherwise one line from stdin. */
function ask_password($prompt) {
    $tty = function_exists('posix_isatty') && posix_isatty(STDIN);
    fwrite(STDERR, $prompt);
    if ($tty) shell_exec('stty -echo');
    $line = fgets(STDIN);
    if ($tty) { shell_exec('stty echo'); fwrite(STDERR, "\n"); }
    return rtrim((string)$line, "\r\n");
}

function new_password() {
    $password = ask_password('New password: ');
    $confirm = (function_exists('posix_isatty') && posix_isatty(STDIN)) ? ask_password('Repeat it: ') : $password;
    if ($problem = password_problem($password, $confirm)) {
        fail($problem);
    }
    return $password;
}

function find_admin(PDO $pdo, $username) {
    $stmt = $pdo->prepare('SELECT id, username FROM admins WHERE username = ?');
    $stmt->execute([$username]);
    return $stmt->fetch() ?: fail("No admin named \"{$username}\". Use: php bin/admin.php list");
}

$pdo = cli_db();
$command = $argv[1] ?? '';
$username = $argv[2] ?? '';

switch ($command) {
    case 'list':
        $rows = $pdo->query('SELECT username, created_at, last_login_at FROM admins ORDER BY username')->fetchAll();
        if (!$rows) {
            echo "No admin accounts yet. Create one on setup.php, or: php bin/admin.php create <username>\n";
        }
        foreach ($rows as $r) {
            printf("%-24s created %s UTC, last login %s\n", $r['username'], $r['created_at'], $r['last_login_at'] ? $r['last_login_at'] . ' UTC' : 'never');
        }
        break;

    case 'create':
        if ($problem = username_problem($username)) fail($problem);
        $password = new_password();
        try {
            create_admin($pdo, $username, $password);
        } catch (PDOException $e) {
            fail($e->getCode() == 23000 ? "An admin named \"{$username}\" already exists." : $e->getMessage());
        }
        setting_set($pdo, 'setup_code', null);
        echo "Created admin \"{$username}\".\n";
        break;

    case 'reset-password':
        $admin = find_admin($pdo, $username);
        set_admin_password($pdo, $admin['id'], new_password());
        $pdo->prepare('DELETE FROM login_failures WHERE username = ?')->execute([$admin['username']]);
        echo "Password changed for \"{$admin['username']}\"; their other sessions are signed out.\n";
        break;

    case 'delete':
        $admin = find_admin($pdo, $username);
        if (admin_count($pdo) === 1) {
            fail("\"{$admin['username']}\" is the only admin. Create another one first.");
        }
        $pdo->prepare('DELETE FROM admins WHERE id = ?')->execute([$admin['id']]);
        echo "Deleted admin \"{$admin['username']}\".\n";
        break;

    case 'unlock':
        $n = $pdo->exec('DELETE FROM login_failures');
        echo "Cleared {$n} failed login(s); lockouts are lifted.\n";
        break;

    case 'setup-code':
        if (admin_count($pdo) > 0) {
            echo "Setup is already done (an admin exists). Log in at " . BASE_URL . "admin_login.php\n";
        } else {
            echo 'Setup code: ' . setup_code($pdo) . "\nOpen " . BASE_URL . "setup.php\n";
        }
        break;

    default:
        fwrite(STDERR, "Usage: php bin/admin.php list | create <username> | reset-password <username> | delete <username> | unlock | setup-code\n");
        exit(2);
}
