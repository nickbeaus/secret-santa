<?php
/**
 * admins.php
 *
 * Admin accounts: change your own password, add another admin, reset another
 * admin's password, or remove one. Every change asks for your current password,
 * so someone who finds a logged-in browser can't quietly add an account.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_layout.php';
require_admin();

$me = current_admin();
$feedback_message = '';
$feedback_class = '';

/** Checks the signed-in admin's current password (also counts toward lockouts when wrong). */
function confirm_my_password(PDO $pdo, $me) {
    $result = attempt_admin_login($pdo, $me['username'], (string)($_POST['current_password'] ?? ''));
    return $result['ok'] ? '' : ($result['error'] === 'Invalid username or password.' ? 'Your current password is not correct.' : $result['error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $problem = confirm_my_password($pdo, $me);

    if (isset($_POST['change_password'])) {
        $password = (string)($_POST['new_password'] ?? '');
        $problem = $problem ?: password_problem($password, (string)($_POST['new_password_confirm'] ?? ''));
        if (!$problem) {
            set_admin_password($pdo, $me['id'], $password);
            refresh_admin_session($pdo, $me['id']);
            $feedback_message = 'Your password was changed. Any other browsers where you were logged in are now signed out.';
        }

    } elseif (isset($_POST['add_admin'])) {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $problem = $problem ?: username_problem($username) ?: password_problem($password, (string)($_POST['password_confirm'] ?? ''));
        if (!$problem) {
            try {
                create_admin($pdo, $username, $password);
                $feedback_message = "Added admin \"{$username}\". Give them their password privately; they can change it here after logging in.";
            } catch (PDOException $e) {
                $problem = $e->getCode() == 23000 ? "There's already an admin named \"{$username}\"." : db_error_message('Error adding admin', $e);
            }
        }

    } elseif (isset($_POST['reset_password']) || isset($_POST['delete_admin'])) {
        $stmt = $pdo->prepare('SELECT id, username FROM admins WHERE id = ?');
        $stmt->execute([(int)($_POST['admin_id'] ?? 0)]);
        $target = $stmt->fetch();
        if (!$target) {
            $problem = $problem ?: 'That admin no longer exists.';
        } elseif ((int)$target['id'] === (int)$me['id']) {
            $problem = $problem ?: 'Use "Change my password" for your own account.';
        } elseif (isset($_POST['reset_password'])) {
            $password = (string)($_POST['password'] ?? '');
            $problem = $problem ?: password_problem($password, (string)($_POST['password_confirm'] ?? ''));
            if (!$problem) {
                set_admin_password($pdo, $target['id'], $password);
                $pdo->prepare('DELETE FROM login_failures WHERE username = ?')->execute([$target['username']]);
                $feedback_message = "New password set for \"{$target['username']}\". They have been signed out everywhere.";
            }
        } elseif (!$problem) {
            $pdo->prepare('DELETE FROM admins WHERE id = ?')->execute([$target['id']]);
            $feedback_message = "Removed admin \"{$target['username']}\".";
        }
    }

    if ($problem) {
        $feedback_message = $problem;
        $feedback_class = 'alert-danger';
    } elseif ($feedback_message) {
        $feedback_class = 'alert-success';
    }
}

$admins = $pdo->query('SELECT id, username, created_at, last_login_at, password_changed_at FROM admins ORDER BY username')->fetchAll();
$me = current_admin();

admin_header('Admins', $feedback_message, $feedback_class);

/** The "your current password" field every form here needs. */
function current_password_field($id) {
    ?>
    <div class="mb-3">
        <label for="<?php echo $id; ?>" class="form-label">Your current password</label>
        <input type="password" class="form-control" id="<?php echo $id; ?>" name="current_password" autocomplete="current-password" required>
    </div>
    <?php
}
?>
    <h1 class="mb-4">Admins</h1>

    <div class="card shadow-sm mb-5">
        <div class="card-header"><h5 class="mb-0">Admin Accounts (<?php echo count($admins); ?>)</h5></div>
        <ul class="list-group list-group-flush">
            <?php foreach ($admins as $a): $is_me = (int)$a['id'] === (int)$me['id']; ?>
                <li class="list-group-item">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <strong><?php echo h($a['username']); ?></strong>
                            <?php if ($is_me): ?><span class="badge bg-primary ms-1">You</span><?php endif; ?>
                            <div class="small text-muted">
                                Last login: <?php echo $a['last_login_at'] ? format_local($a['last_login_at'], 'M j, Y g:i a') : 'never'; ?>
                                · Password set <?php echo format_local($a['password_changed_at'], 'M j, Y'); ?>
                            </div>
                        </div>
                        <?php if (!$is_me): ?>
                            <details class="w-100 mt-2">
                                <summary class="small text-primary" style="cursor:pointer;">Reset password or remove</summary>
                                <div class="row g-3 mt-1">
                                    <form class="col-md-7" action="admins.php" method="POST">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="admin_id" value="<?php echo (int)$a['id']; ?>">
                                        <input type="hidden" name="username" value="<?php echo h($a['username']); ?>" autocomplete="username">
                                        <div class="mb-2">
                                            <input type="password" class="form-control form-control-sm" name="password" placeholder="New password for <?php echo h($a['username']); ?>"
                                                   autocomplete="new-password" required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>">
                                        </div>
                                        <div class="mb-2">
                                            <input type="password" class="form-control form-control-sm" name="password_confirm" placeholder="New password again" autocomplete="new-password" required>
                                        </div>
                                        <div class="mb-2">
                                            <input type="password" class="form-control form-control-sm" name="current_password" placeholder="Your current password" autocomplete="current-password" required>
                                        </div>
                                        <button type="submit" name="reset_password" class="btn btn-sm btn-warning">Set New Password</button>
                                    </form>
                                    <form class="col-md-5" action="admins.php" method="POST"
                                          data-confirm="Remove admin <?php echo h($a['username']); ?>? They will no longer be able to log in.">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="admin_id" value="<?php echo (int)$a['id']; ?>">
                                        <div class="mb-2">
                                            <input type="password" class="form-control form-control-sm" name="current_password" placeholder="Your current password" autocomplete="current-password" required>
                                        </div>
                                        <button type="submit" name="delete_admin" class="btn btn-sm btn-danger">Remove Admin</button>
                                    </form>
                                </div>
                            </details>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header"><h5 class="mb-0">Change My Password</h5></div>
                <div class="card-body">
                    <form action="admins.php" method="POST">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="username" value="<?php echo h($me['username']); ?>" autocomplete="username">
                        <?php current_password_field('cp_current'); ?>
                        <div class="mb-3">
                            <label for="cp_new" class="form-label">New password</label>
                            <input type="password" class="form-control" id="cp_new" name="new_password" autocomplete="new-password" required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>">
                            <div class="form-text">At least <?php echo PASSWORD_MIN_LENGTH; ?> characters.</div>
                        </div>
                        <div class="mb-3">
                            <label for="cp_confirm" class="form-label">New password again</label>
                            <input type="password" class="form-control" id="cp_confirm" name="new_password_confirm" autocomplete="new-password" required>
                        </div>
                        <button type="submit" name="change_password" class="btn btn-primary">Change Password</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header"><h5 class="mb-0">Add an Admin</h5></div>
                <div class="card-body">
                    <form action="admins.php" method="POST" autocomplete="off">
                        <?php echo csrf_field(); ?>
                        <div class="mb-3">
                            <label for="add_username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="add_username" name="username" autocomplete="off" autocapitalize="none" spellcheck="false" required minlength="3" maxlength="64">
                        </div>
                        <div class="mb-3">
                            <label for="add_password" class="form-label">Their password</label>
                            <input type="password" class="form-control" id="add_password" name="password" autocomplete="new-password" required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>">
                        </div>
                        <div class="mb-3">
                            <label for="add_confirm" class="form-label">Their password again</label>
                            <input type="password" class="form-control" id="add_confirm" name="password_confirm" autocomplete="new-password" required>
                        </div>
                        <?php current_password_field('add_current'); ?>
                        <button type="submit" name="add_admin" class="btn btn-success">Add Admin</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php admin_footer(); ?>
