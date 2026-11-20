<?php
/**
 * export.php: writes all Secret Santa data (no admin accounts) as JSON.
 *
 *   php bin/export.php > secret-santa-export.json
 *   docker compose exec -T web php bin/export.php > secret-santa-export.json
 */
require __DIR__ . '/_cli.php';
echo export_json(cli_db(), 'sqlite'), "\n";
