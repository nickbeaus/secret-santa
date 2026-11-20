<?php
/**
 * _cli.php: shared setup for the command-line scripts in bin/.
 * Loads settings and helpers without the web-only parts of bootstrap.php.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/backup.php';
date_default_timezone_set(APP_TIMEZONE);

function fail($message, $code = 1) {
    fwrite(STDERR, "Error: {$message}\n");
    exit($code);
}

/** Opens the database, which must already have its tables. */
function cli_db() {
    if (!file_exists(DB_PATH)) {
        fail('No database at ' . DB_PATH . '. It is created when the container starts (bin/migrate.php).');
    }
    $pdo = db_connect();
    if (db_schema_version($pdo) === 0) {
        fail('The database has no tables yet. Run bin/migrate.php first.');
    }
    return $pdo;
}
