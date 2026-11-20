<?php
/**
 * admin_layout.php
 *
 * Shared page chrome for the admin pages.
 *
 *   admin_header('Page Title', $feedback_message, $feedback_class);
 *   ... page content ...
 *   admin_footer();
 *
 * Forms or buttons with a data-confirm="..." attribute ask for confirmation
 * before submitting (inline onclick handlers are blocked by the CSP).
 */

const BOOTSTRAP_CSS = '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">';
const BOOTSTRAP_JS = '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>';

function admin_header($title, $feedback_message = '', $feedback_class = '', $extra_css = '') {
    $current = basename($_SERVER['SCRIPT_NAME']);
    $links = [
        'admin.php'        => ['Groups', 'btn-outline-light'],
        'users.php'        => ['Users & Blocks', 'btn-outline-info'],
        'full_history.php' => ['Full History', 'btn-outline-warning'],
        'admins.php'       => ['Admins', 'btn-outline-light'],
        'backup.php'       => ['Backup', 'btn-outline-light'],
    ];
    $me = current_admin();
    // A message saved before a redirect (e.g. after importing on the setup page).
    if (!$feedback_message && !empty($_SESSION['flash'])) {
        [$feedback_class, $feedback_message] = $_SESSION['flash'];
    }
    unset($_SESSION['flash']);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($title); ?> - Secret Santa Admin</title>
    <?php echo BOOTSTRAP_CSS; ?>
    <style>
        body { background-color: #f8f9fa; }
        .container { max-width: 960px; }
        .card { border-radius: 0.75rem; }
        <?php echo $extra_css; ?>
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="admin.php">Secret Santa Admin</a>
        <div class="ms-auto d-flex flex-wrap gap-2">
            <?php foreach ($links as $href => [$label, $style]): ?>
                <a href="<?php echo $href; ?>" class="btn btn-sm <?php echo $style; ?><?php if ($href === $current) echo ' active'; ?>"><?php echo $label; ?></a>
            <?php endforeach; ?>
            <form action="logout.php" method="POST" class="d-inline">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Signed in as <?php echo h($me['username'] ?? ''); ?>">
                    Log out<?php if ($me) echo ' ' . h($me['username']); ?>
                </button>
            </form>
        </div>
    </div>
</nav>
<main class="container py-5">
    <?php if ($feedback_message): ?>
        <div class="alert <?php echo h($feedback_class); ?> alert-dismissible fade show" role="alert">
            <?php echo h($feedback_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php
}

function admin_footer() {
    ?>
</main>
<?php echo BOOTSTRAP_JS; ?>
<script nonce="<?php echo csp_nonce(); ?>">
    // Confirmation prompts for destructive actions (see data-confirm in the markup).
    document.addEventListener('click', function (e) {
        const button = e.target.closest('button[data-confirm]');
        if (button && !confirm(button.dataset.confirm)) e.preventDefault();
    });
    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (form.dataset.confirm && !confirm(form.dataset.confirm)) e.preventDefault();
    });
</script>
</body>
</html>
    <?php
}


/**
 * A small centered card page, used by the login and first-run setup pages.
 *   auth_page_header('Admin Login'); ... auth_page_footer();
 */
function auth_page_header($title, $width = 420) {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($title); ?> - Secret Santa</title>
    <?php echo BOOTSTRAP_CSS; ?>
    <link href="https://fonts.googleapis.com/css2?family=Mountains+of+Christmas:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #343a40; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px; }
        .auth-card { width: 100%; max-width: <?php echo (int)$width; ?>px; border-radius: 1rem; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .auth-title { font-family: 'Mountains of Christmas', cursive; font-weight: 700; }
        .setup-code { font-family: monospace; letter-spacing: 0.1em; text-transform: uppercase; }
    </style>
</head>
<body>
<div class="card auth-card">
    <div class="card-body p-4 p-sm-5">
    <?php
}

function auth_page_footer() {
    ?>
    </div>
</div>
<?php echo BOOTSTRAP_JS; ?>
</body>
</html>
    <?php
}
