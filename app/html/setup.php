<?php
/**
 * setup.php
 *
 * First-run setup for a new install, in two steps:
 *   1. Create the first admin account. Requires the one-time setup code that the
 *      container prints in its log, so a stranger who finds a fresh install first
 *      can't claim it.
 *   2. Import data exported from the old site, or start with an empty site.
 * Once an admin exists, step 1 is gone for good; step 2 is offered while the site has no data.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/backup.php';

start_admin_session();
$errors = [];
$notice = '';
$form = ['username' => ''];

if (admin_count($pdo) > 0) {
    // --- Step 2: bring in data (signed-in admin, empty site) ---
    if (!is_admin()) {
        header('Location: admin_login.php');
        exit;
    }
    if (!database_is_empty($pdo)) {
        header('Location: admin.php');
        exit;
    }
    $step = 2;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        if (isset($_POST['start_fresh'])) {
            header('Location: admin.php');
            exit;
        }
        if (isset($_POST['import'])) {
            $result = import_uploaded_file($pdo, 'export_file');
            if ($result['ok']) {
                $_SESSION['flash'] = ['alert-success', $result['message']];
                header('Location: admin.php');
                exit;
            }
            $errors[] = $result['message'];
        }
    }
} else {
    // --- Step 1: create the first admin ---
    $step = 1;
    setup_code($pdo); // make sure a code exists (normally created at container start)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $form['username'] = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if (!setup_code_matches($pdo, $_POST['setup_code'] ?? '')) {
            error_log('Secret Santa: wrong setup code entered from ' . client_ip());
            sleep(2);
            $errors[] = "That setup code isn't right. Check the container log for the current code.";
        }
        if ($p = username_problem($form['username'])) $errors[] = $p;
        if ($p = password_problem($password, (string)($_POST['password_confirm'] ?? ''))) $errors[] = $p;

        if (!$errors) {
            // IMMEDIATE takes the write lock up front, so two people submitting at once can't both win.
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                if (admin_count($pdo) > 0) {
                    $pdo->exec('ROLLBACK');
                    header('Location: admin_login.php');
                    exit;
                }
                $id = create_admin($pdo, $form['username'], $password);
                setting_set($pdo, 'setup_code', null);
                $pdo->exec('COMMIT');
            } catch (Throwable $e) {
                $pdo->exec('ROLLBACK');
                throw $e;
            }
            $stmt = $pdo->prepare('SELECT id, session_epoch FROM admins WHERE id = ?');
            $stmt->execute([$id]);
            begin_admin_session($pdo, $stmt->fetch());
            header('Location: ' . (database_is_empty($pdo) ? 'setup.php' : 'admin.php'));
            exit;
        }
    }
}

auth_page_header('Set Up', 560);
?>
        <h2 class="auth-title text-center mb-1 fs-1">Secret Santa Setup</h2>
        <p class="text-center text-muted small mb-4">Step <?php echo $step; ?> of 2</p>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger"><?php echo h($error); ?></div>
        <?php endforeach; ?>

        <?php if ($step === 1): ?>
            <h5>Create your admin account</h5>
            <p class="small text-muted">
                Enter the setup code from the container's startup log. To see it again, run
                <code>docker compose logs web</code> or <code>docker compose exec web php bin/admin.php setup-code</code>.
            </p>
            <form action="setup.php" method="POST" autocomplete="off">
                <?php echo csrf_field(); ?>
                <div class="mb-3">
                    <label for="setup_code" class="form-label">Setup code</label>
                    <input type="text" class="form-control setup-code" id="setup_code" name="setup_code"
                           placeholder="XXXX-XXXX-XXXX" autocapitalize="characters" spellcheck="false" required autofocus>
                </div>
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="username" name="username" value="<?php echo h($form['username']); ?>"
                           autocomplete="username" autocapitalize="none" spellcheck="false" required minlength="3" maxlength="64">
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" autocomplete="new-password"
                           required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" aria-describedby="password_help">
                    <div id="password_help" class="form-text">At least <?php echo PASSWORD_MIN_LENGTH; ?> characters. A short phrase is easier to remember than random symbols.</div>
                </div>
                <div class="mb-4">
                    <label for="password_confirm" class="form-label">Password again</label>
                    <input type="password" class="form-control" id="password_confirm" name="password_confirm" autocomplete="new-password" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Create Admin Account</button>
            </form>

        <?php else: ?>
            <h5>Bring in your data</h5>
            <p class="small text-muted">
                If you're moving from the old site, import the file from its <strong>Backup</strong> page
                ("Download Export"), or from another install's Backup page.
                It brings over people, groups, drawings, history and the text log. Admin accounts are not included.
            </p>
            <form action="setup.php" method="POST" enctype="multipart/form-data" class="mb-4">
                <?php echo csrf_field(); ?>
                <div class="mb-3">
                    <label for="export_file" class="form-label">Export file (.json)</label>
                    <input type="file" class="form-control" id="export_file" name="export_file" accept=".json,application/json" required>
                </div>
                <button type="submit" name="import" class="btn btn-primary w-100">Import</button>
            </form>
            <div class="text-center text-muted small mb-3">or</div>
            <form action="setup.php" method="POST">
                <?php echo csrf_field(); ?>
                <button type="submit" name="start_fresh" class="btn btn-outline-secondary w-100">Start with an Empty Site</button>
                <div class="form-text text-center">You can still import later from the Backup page, as long as the site has no data yet.</div>
            </form>
        <?php endif; ?>
<?php auth_page_footer(); ?>
