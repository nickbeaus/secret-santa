<?php
/**
 * admin_login.php
 *
 * Login form for admin accounts (stored in the database). Repeated failures from one IP,
 * or against one username, are locked out for a while (see includes/auth.php).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_layout.php';

start_admin_session();

// No admin yet: this is a fresh install.
if (admin_count($pdo) === 0) {
    header('Location: setup.php');
    exit;
}

$next = safe_next_page($_GET['next'] ?? $_POST['next'] ?? '');
if (is_admin()) {
    header('Location: ' . $next);
    exit;
}

$error_message = '';
$info_message = isset($_GET['signed_out']) ? 'You have been logged out.' : '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $result = attempt_admin_login($pdo, $username, $password);
    if ($result['ok']) {
        begin_admin_session($pdo, $result['admin']);
        header('Location: ' . $next);
        exit;
    }
    $error_message = $result['error'];
    sleep(1);
}

auth_page_header('Admin Login');
?>
        <h2 class="auth-title text-center mb-4 fs-1">Admin Login</h2>
        <?php if ($error_message): ?>
            <div class="alert alert-danger"><?php echo h($error_message); ?></div>
        <?php elseif ($info_message): ?>
            <div class="alert alert-info"><?php echo h($info_message); ?></div>
        <?php endif; ?>
        <form action="admin_login.php" method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="next" value="<?php echo h($next); ?>">
            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" class="form-control" id="username" name="username" value="<?php echo h($username); ?>"
                       autocomplete="username" autocapitalize="none" spellcheck="false" required <?php if (!$username) echo 'autofocus'; ?>>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required <?php if ($username) echo 'autofocus'; ?>>
            </div>
            <button type="submit" class="btn btn-primary w-100 mt-3">Log In</button>
        </form>
        <p class="small text-muted text-center mt-4 mb-0">
            Forgot your password? Another admin can reset it, or run<br>
            <code>docker compose exec web php bin/admin.php reset-password &lt;username&gt;</code>
        </p>
<?php auth_page_footer(); ?>
