<?php
/**
 * backup.php
 *
 * Export all Secret Santa data to one JSON document, and import it into an empty database.
 * Works with any PDO connection, so tools/export-from-mysql.php can use it against the
 * old MySQL site as well. Admin accounts, login data and settings are never exported.
 *
 * Format:
 *   {"format": "secret-santa-export", "version": 1, "exported_at": "2026-11-20T15:00:00Z",
 *    "source": "mysql", "tables": {"users": [{...}, ...], "groups": [...], ...}}
 * Timestamps are UTC strings "YYYY-MM-DD HH:MM:SS".
 */

const EXPORT_FORMAT = 'secret-santa-export';
const EXPORT_VERSION = 1;

/**
 * Tables in dependency order (parents first), each with its columns:
 * column => [type, required]. Types: int, text, time (UTC timestamp), bool.
 */
const EXPORT_TABLES = [
    'users' => [
        'id' => ['int', true], 'uuid' => ['text', true], 'short_code' => ['text', false], 'name' => ['text', true],
        'email' => ['text', false], 'phone_number' => ['text', false], 'creation_date' => ['time', true],
    ],
    'groups' => [
        'id' => ['int', true], 'short_code' => ['text', false], 'name' => ['text', true], 'reveal_message' => ['text', false],
        'gift_idea_url' => ['text', false], 'sms_template' => ['text', false], 'creation_date' => ['time', true],
    ],
    'participants' => [
        'id' => ['int', true], 'user_id' => ['int', true], 'group_id' => ['int', true], 'current_giving_to_id' => ['int', false],
        'viewed_date' => ['time', false], 'view_count' => ['int', true],
    ],
    'blocked_pairings' => [
        'id' => ['int', true], 'user_id_1' => ['int', true], 'user_id_2' => ['int', true], 'creation_date' => ['time', true],
    ],
    'assignment_history' => [
        'id' => ['int', true], 'group_id' => ['int', true], 'year' => ['int', true], 'giver_participant_id' => ['int', true],
        'receiver_participant_id' => ['int', true], 'assignment_date' => ['time', true],
    ],
    'participant_views' => [
        'id' => ['int', true], 'participant_id' => ['int', true], 'view_timestamp' => ['time', true], 'ip_address' => ['text', false],
    ],
    'sms_log' => [
        'id' => ['int', true], 'group_id' => ['int', true], 'user_id' => ['int', true], 'phone_number' => ['text', true],
        'message' => ['text', true], 'test_mode' => ['bool', true], 'success' => ['bool', true], 'provider_id' => ['text', false],
        'error' => ['text', false], 'sent_at' => ['time', true],
    ],
];

/**
 * Reads every table into an export array. For MySQL, the connection must use
 * time_zone '+00:00' so timestamps come out in UTC.
 */
function export_data(PDO $pdo, $source) {
    $tables = [];
    foreach (EXPORT_TABLES as $table => $columns) {
        $cols = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($columns)));
        $rows = $pdo->query("SELECT {$cols} FROM `{$table}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            foreach ($columns as $col => [$type]) {
                if ($row[$col] !== null && ($type === 'int' || $type === 'bool')) {
                    $row[$col] = $type === 'bool' ? (bool)$row[$col] : (int)$row[$col];
                }
            }
        }
        unset($row);
        $tables[$table] = $rows;
    }
    return [
        'format'      => EXPORT_FORMAT,
        'version'     => EXPORT_VERSION,
        'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'source'      => $source,
        'tables'      => $tables,
    ];
}

function export_json(PDO $pdo, $source) {
    return export_encode(export_data($pdo, $source));
}

function export_encode(array $data) {
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/** Row counts per table, e.g. ['users' => 16, ...]. */
function data_row_counts(PDO $pdo) {
    $counts = [];
    foreach (array_keys(EXPORT_TABLES) as $table) {
        $counts[$table] = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    }
    return $counts;
}

/** True when there is no Secret Santa data at all (admin accounts don't count). */
function database_is_empty(PDO $pdo) {
    return array_sum(data_row_counts($pdo)) === 0;
}

/**
 * Parses and checks an export. Returns [data, ''] or [null, error message].
 * Checks structure and value types; relationships are checked during the import.
 */
function parse_export($json) {
    try {
        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return [null, "This isn't a valid export file (it isn't readable JSON)."];
    }
    if (!is_array($data) || ($data['format'] ?? null) !== EXPORT_FORMAT) {
        return [null, "This isn't a Secret Santa export file."];
    }
    if (($data['version'] ?? null) !== EXPORT_VERSION) {
        return [null, 'This export uses format version ' . json_encode($data['version'] ?? null) . ', but this site reads version ' . EXPORT_VERSION . '.'];
    }
    if (!is_array($data['tables'] ?? null)) {
        return [null, 'The export has no tables.'];
    }
    foreach ($data['tables'] as $table => $rows) {
        if (!isset(EXPORT_TABLES[$table])) {
            return [null, "The export has an unknown table \"{$table}\"."];
        }
    }
    foreach (EXPORT_TABLES as $table => $columns) {
        $rows = $data['tables'][$table] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            return [null, "The export is missing the \"{$table}\" table."];
        }
        foreach ($rows as $i => $row) {
            $where = "{$table} row " . ($i + 1);
            if (!is_array($row)) {
                return [null, "{$where} is not a record."];
            }
            if ($extra = array_diff(array_keys($row), array_keys($columns))) {
                return [null, "{$where} has unknown fields: " . implode(', ', $extra) . '.'];
            }
            foreach ($columns as $col => [$type, $required]) {
                $value = $row[$col] ?? null;
                if ($value === null) {
                    if ($required) {
                        return [null, "{$where} is missing \"{$col}\"."];
                    }
                    continue;
                }
                $ok = match ($type) {
                    'int'  => is_int($value),
                    'bool' => is_bool($value) || $value === 0 || $value === 1,
                    'time' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value),
                    default => is_string($value),
                };
                if (!$ok) {
                    return [null, "{$where} has an invalid \"{$col}\" value."];
                }
            }
        }
    }
    return [$data, ''];
}

/**
 * Loads a parsed export into an EMPTY database, all or nothing.
 * Returns the number of rows imported per table; throws RuntimeException on any problem.
 */
function import_data(PDO $pdo, array $data) {
    if (!database_is_empty($pdo)) {
        throw new RuntimeException('The database already has data. Import only works on a fresh, empty install.');
    }
    $pdo->beginTransaction();
    try {
        // Rows may point at rows later in the file (e.g. who a participant gives to),
        // so foreign keys are checked once at the end instead of row by row.
        $pdo->exec('PRAGMA defer_foreign_keys = ON');
        $counts = [];
        foreach (EXPORT_TABLES as $table => $columns) {
            $names = array_keys($columns);
            $sql = "INSERT INTO `{$table}` (" . implode(', ', $names) . ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')';
            $stmt = $pdo->prepare($sql);
            foreach ($data['tables'][$table] as $row) {
                $values = [];
                foreach ($columns as $col => [$type]) {
                    $value = $row[$col] ?? null;
                    $values[] = ($type === 'bool' && $value !== null) ? (int)(bool)$value : $value;
                }
                $stmt->execute($values);
            }
            $counts[$table] = count($data['tables'][$table]);
        }
        $broken = $pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_NUM);
        if ($broken) {
            [$table, $rowid, $parent] = $broken[0];
            throw new RuntimeException(count($broken) . " record(s) point at missing data (first: {$table} id {$rowid} refers to a missing {$parent} row).");
        }
        $pdo->commit();
        return $counts;
    } catch (Throwable $e) {
        $pdo->rollBack();
        if ($e instanceof PDOException && $e->getCode() == 23000) {
            throw new RuntimeException('The export has duplicate records: ' . preg_replace('/^.*?: \d+ /', '', $e->getMessage()), 0, $e);
        }
        throw $e instanceof RuntimeException ? $e : new RuntimeException('Import failed: ' . $e->getMessage(), 0, $e);
    }
}

/** "16 users, 10 groups, ..." */
function describe_counts(array $counts) {
    $labels = [
        'users' => 'people', 'groups' => 'groups', 'participants' => 'group memberships', 'blocked_pairings' => 'blocked pairings',
        'assignment_history' => 'past assignments', 'participant_views' => 'link views', 'sms_log' => 'text log entries',
    ];
    return implode(', ', array_map(fn($t) => $counts[$t] . ' ' . $labels[$t], array_keys($counts)));
}

/**
 * Handles an uploaded export file from a form field (web pages only).
 * Returns ['ok' => bool, 'message' => text to show].
 */
function import_uploaded_file(PDO $pdo, $field) {
    $file = $_FILES[$field] ?? null;
    if (!$file || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'message' => 'Choose an export file to import.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        $why = in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? ' The file is too large.' : '';
        return ['ok' => false, 'message' => 'The upload failed.' . $why];
    }
    [$data, $problem] = parse_export((string)file_get_contents($file['tmp_name']));
    if ($problem) {
        return ['ok' => false, 'message' => $problem];
    }
    try {
        $counts = import_data($pdo, $data);
    } catch (RuntimeException $e) {
        error_log('Secret Santa: import failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Nothing was imported. ' . $e->getMessage()];
    }
    $from = !empty($data['exported_at']) && is_string($data['exported_at']) ? ' (exported ' . substr($data['exported_at'], 0, 10) . ')' : '';
    return ['ok' => true, 'message' => 'Imported ' . describe_counts($counts) . $from . '.'];
}
