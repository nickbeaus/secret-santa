<?php
/**
 * import.php: loads an export file into an empty install (all or nothing).
 *
 *   php bin/import.php /data/secret-santa-export.json
 *   docker compose exec -T web php bin/import.php - < secret-santa-export.json
 */
require __DIR__ . '/_cli.php';

$file = $argv[1] ?? fail('Usage: php bin/import.php <export.json | ->');
$json = $file === '-' ? stream_get_contents(STDIN) : @file_get_contents($file);
if ($json === false || $json === '') {
    fail("Can't read {$file}.");
}
[$data, $problem] = parse_export($json);
if ($problem) {
    fail($problem);
}
try {
    $counts = import_data(cli_db(), $data);
} catch (RuntimeException $e) {
    fail($e->getMessage());
}
echo 'Imported ' . describe_counts($counts) . ".\n";
