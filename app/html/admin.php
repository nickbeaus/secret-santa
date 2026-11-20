<?php
/**
 * admin.php
 *
 * This is the main administrative interface for the Secret Santa application.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_layout.php';
require_admin();

$feedback_message = '';
$feedback_class = '';

if (($_GET['error'] ?? '') === 'groupnotfound') {
    $feedback_message = 'That group could not be found.';
    $feedback_class = 'alert-warning';
}

// --- Handle Form Submission: Create a New Group ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_group'])) {
    $group_name = trim($_POST['group_name'] ?? '');

    if (!empty($group_name)) {
        try {
            $sql = "INSERT INTO `groups` (short_code, name) VALUES (?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([generate_short_code(), $group_name]);
            $feedback_message = "Success! Group '{$group_name}' was created.";
            $feedback_class = 'alert-success';
        } catch (PDOException $e) {
            $feedback_message = db_error_message('Error creating group', $e);
            $feedback_class = 'alert-danger';
        }
    } else {
        $feedback_message = "Group name cannot be empty.";
        $feedback_class = 'alert-warning';
    }
}

// --- Fetch Existing Groups for Display ---
try {
    $stmt = $pdo->query("SELECT id, name, creation_date FROM `groups` ORDER BY creation_date DESC");
    $groups = $stmt->fetchAll();
} catch (PDOException $e) {
    $groups = [];
    $feedback_message = db_error_message('Error fetching groups', $e);
    $feedback_class = 'alert-danger';
}

admin_header('Group Management', $feedback_message, $feedback_class);
?>
    <h1 class="mb-4">Group Management</h1>

    <!-- Create New Group Card -->
    <div class="card mb-5 shadow-sm">
        <div class="card-header">
            <h5 class="mb-0">Create a New Group</h5>
        </div>
        <div class="card-body">
            <form action="admin.php" method="POST">
                <?php echo csrf_field(); ?>
                <div class="mb-3">
                    <label for="group_name" class="form-label">Group Name</label>
                    <input type="text" class="form-control" id="group_name" name="group_name" placeholder="e.g., Work Party 2025, Family Exchange" required>
                </div>
                <button type="submit" name="create_group" class="btn btn-primary">Create Group</button>
            </form>
        </div>
    </div>

    <!-- Existing Groups Card -->
    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0">Existing Groups</h5>
        </div>
        <div class="card-body">
            <?php if (empty($groups)): ?>
                <p class="text-center text-muted">No groups found. Create one above to get started!</p>
            <?php else: ?>
                <div class="list-group">
                    <?php foreach ($groups as $group): ?>
                        <a href="manage_group.php?group_id=<?php echo (int)$group['id']; ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1"><?php echo h($group['name']); ?></h6>
                                <small class="text-muted">Created: <?php echo format_local($group['creation_date'], 'F j, Y'); ?></small>
                            </div>
                            <span class="badge bg-primary rounded-pill">Manage</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php admin_footer(); ?>
