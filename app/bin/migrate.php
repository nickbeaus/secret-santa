<?php
/**
 * migrate.php: creates the database if needed and applies new schema migrations.
 * Runs automatically every time the container starts (docker/entrypoint.sh).
 * While no admin account exists, prints the one-time setup code for setup.php.
 *
 *   php bin/migrate.php
 */
require __DIR__ . '/_cli.php';

$dir = dirname(DB_PATH);
if (!is_dir($dir) && !mkdir($dir, 0770, true)) {
    fail("Can't create the data folder {$dir}.");
}
$is_new = !file_exists(DB_PATH);
try {
    $pdo = db_connect();
    $applied = db_migrate($pdo);
} catch (Throwable $e) {
    fail($e->getMessage());
}
if ($is_new) {
    echo 'Created a new database at ' . DB_PATH . "\n";
}
foreach ($applied as $file) {
    echo "Applied migration {$file}\n";
}
echo 'Database schema is at version ' . db_schema_version($pdo) . "\n";

if (admin_count($pdo) === 0) {
    $code = setup_code($pdo);
    $url = BASE_URL . 'setup.php';
    echo <<<TXT

    ==============================================================
      FIRST-TIME SETUP
      Open {$url}
      and enter this setup code:   {$code}
      (Show it again: docker compose exec web php bin/admin.php setup-code)
    ==============================================================

    TXT;
} else {
    setting_set($pdo, 'setup_code', null); // no longer needed once an admin exists
}
