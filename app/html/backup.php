<?php
/**
 * backup.php
 *
 * Download all Secret Santa data as one JSON export file, and import an export
 * into a fresh install (only while the site has no data, so nothing is overwritten).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/backup.php';
require_admin();

if (isset($_GET['download'])) {
    $name = 'secret-santa-export-' . date('Y-m-d-His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    echo export_json($pdo, 'sqlite'), "\n";
    exit;
}

$feedback_message = '';
$feedback_class = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import'])) {
    $result = import_uploaded_file($pdo, 'export_file');
    $feedback_message = $result['message'];
    $feedback_class = $result['ok'] ? 'alert-success' : 'alert-danger';
}

$counts = data_row_counts($pdo);
$is_empty = array_sum($counts) === 0;

admin_header('Backup', $feedback_message, $feedback_class);
?>
    <h1 class="mb-4">Backup</h1>

    <div class="card shadow-sm mb-5">
        <div class="card-header"><h5 class="mb-0">Download an Export</h5></div>
        <div class="card-body">
            <p>One file with everything: people, groups, drawings, history, link views and the text log.
               Admin accounts and passwords are not included.</p>
            <p class="small text-muted">This site has <?php echo h(describe_counts($counts)); ?>.</p>
            <a href="backup.php?download=1" class="btn btn-primary">Download Export (.json)</a>
            <div class="form-text mt-2">The file includes names, emails and phone numbers. Keep it somewhere private.</div>
        </div>
    </div>

    <div class="card shadow-sm mb-5">
        <div class="card-header"><h5 class="mb-0">Import an Export</h5></div>
        <div class="card-body">
            <?php if ($is_empty): ?>
                <p>Load an export from the old site or another install. Everything is imported, or nothing is if the file has a problem.</p>
                <form action="backup.php" method="POST" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <div class="mb-3">
                        <label for="export_file" class="form-label">Export file (.json)</label>
                        <input type="file" class="form-control" id="export_file" name="export_file" accept=".json,application/json" required>
                    </div>
                    <button type="submit" name="import" class="btn btn-primary">Import</button>
                </form>
            <?php else: ?>
                <p class="mb-0 text-muted">
                    Importing is only available on an empty site, so existing data can't be overwritten or mixed up.
                    To restore an export, start a new install (with an empty data folder) and import it on the setup page.
                </p>
            <?php endif; ?>
        </div>
    </div>
<?php admin_footer(); ?>
