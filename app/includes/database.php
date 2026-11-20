<?php
/**
 * database.php
 *
 * Opens the SQLite database and applies schema migrations.
 * Used by bootstrap.php (web pages) and the scripts in bin/ (command line).
 */

const MIGRATIONS_DIR = __DIR__ . '/../migrations';

/** Opens the database with the settings every connection needs. */
function db_connect($path = DB_PATH) {
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5, // wait up to 5s if another request is writing
    ]);
    // SQLite leaves foreign keys OFF unless asked, per connection. The app relies on
    // ON DELETE CASCADE (e.g. removing a participant clears their history), so this is required.
    $pdo->exec('PRAGMA foreign_keys = ON');
    // WAL lets pages read while another request writes; NORMAL is safe with WAL.
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    return $pdo;
}

/** Migration files as [version => path], e.g. 001_init.sql => [1 => '.../001_init.sql']. */
function db_migration_files() {
    $files = [];
    foreach (glob(MIGRATIONS_DIR . '/*.sql') as $path) {
        if (preg_match('/^(\d+)_[\w-]+\.sql$/', basename($path), $m)) {
            $files[(int)$m[1]] = $path;
        }
    }
    ksort($files);
    return $files;
}

function db_schema_version(PDO $pdo) {
    return (int)$pdo->query('PRAGMA user_version')->fetchColumn();
}

/**
 * Applies every migration newer than the database's version, each in its own transaction.
 * Before changing an existing (non-empty) database, a copy is saved next to it.
 * Returns the list of applied file names.
 */
function db_migrate(PDO $pdo, $path = DB_PATH) {
    $current = db_schema_version($pdo);
    $pending = array_filter(db_migration_files(), fn($v) => $v > $current, ARRAY_FILTER_USE_KEY);
    if (!$pending) {
        return [];
    }
    if ($current > 0) {
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $backup = $path . '.before-v' . array_key_first($pending) . '.' . date('Ymd-His') . '.bak';
        if (!copy($path, $backup)) {
            throw new RuntimeException("Could not back up the database to {$backup}; not migrating.");
        }
    }
    $applied = [];
    foreach ($pending as $version => $file) {
        $pdo->beginTransaction();
        try {
            $pdo->exec(file_get_contents($file));
            $pdo->exec('PRAGMA user_version = ' . (int)$version);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException('Migration ' . basename($file) . ' failed: ' . $e->getMessage(), 0, $e);
        }
        $applied[] = basename($file);
    }
    $problems = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    if ($problems) {
        throw new RuntimeException('Foreign key check failed after migrating: ' . json_encode($problems));
    }
    return $applied;
}

// --- Small key/value settings (app_settings table) ---

function setting_get(PDO $pdo, $key) {
    $stmt = $pdo->prepare('SELECT value FROM app_settings WHERE key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : $value;
}

function setting_set(PDO $pdo, $key, $value) {
    if ($value === null) {
        $pdo->prepare('DELETE FROM app_settings WHERE key = ?')->execute([$key]);
    } else {
        $pdo->prepare('INSERT INTO app_settings (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value')
            ->execute([$key, $value]);
    }
}
