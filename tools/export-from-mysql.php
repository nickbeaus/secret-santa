<?php
/**
 * export-from-mysql.php: exports the OLD (MySQL) Secret Santa site in the format
 * the Docker version imports. Read-only: it only runs SELECT queries.
 *
 * Run it on the old server, pointing at that site's config.php:
 *   php tools/export-from-mysql.php /var/www/xmas/includes/config.php > secret-santa-export.json
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
$config = $argv[1] ?? '';
if (!is_file($config)) {
    fwrite(STDERR, "Usage: php export-from-mysql.php /path/to/old/includes/config.php > export.json\n");
    exit(2);
}
require $config; // defines DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_CHAR
require __DIR__ . '/../app/includes/backup.php';

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHAR, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("SET time_zone = '+00:00'"); // export timestamps in UTC
$pdo->exec('START TRANSACTION READ ONLY'); // one consistent snapshot of all tables
$data = export_data($pdo, 'mysql');
$pdo->exec('COMMIT');
echo export_encode($data), "\n";
fwrite(STDERR, 'Exported ' . describe_counts(array_map('count', $data['tables'])) . ".\n");
