<?php
/**
 * full_history.php
 *
 * Displays a complete, read-only history of all assignments for all users
 * across all groups.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_layout.php';
require_admin();

$full_history = [];
$feedback_message = '';
try {
    // This query joins all necessary tables to get a comprehensive list of past assignments.
    $sql = "SELECT
                u_giver.name AS giver_name,
                u_receiver.name AS receiver_name,
                g.name AS group_name,
                ah.year
            FROM assignment_history AS ah
            JOIN participants AS p_giver ON ah.giver_participant_id = p_giver.id
            JOIN users AS u_giver ON p_giver.user_id = u_giver.id
            JOIN participants AS p_receiver ON ah.receiver_participant_id = p_receiver.id
            JOIN users AS u_receiver ON p_receiver.user_id = u_receiver.id
            JOIN `groups` AS g ON ah.group_id = g.id
            ORDER BY u_giver.name ASC, ah.year DESC";

    $results = $pdo->query($sql)->fetchAll();

    // Group the flat results by giver name for easier display
    foreach ($results as $row) {
        $full_history[$row['giver_name']][] = $row;
    }
} catch (PDOException $e) {
    $feedback_message = db_error_message('Error fetching history', $e);
}

admin_header('Full Assignment History', $feedback_message, 'alert-danger');
?>
    <h1 class="mb-4">Full Assignment History</h1>

    <div class="card shadow-sm">
        <div class="card-body">
            <?php if (empty($full_history)): ?>
                <p class="text-center text-muted">No historical assignments found.</p>
            <?php else: ?>
                <?php foreach ($full_history as $giver_name => $assignments): ?>
                    <div class="mb-4">
                        <h4 class="border-bottom pb-2 mb-3"><?php echo h($giver_name); ?></h4>
                        <ul class="list-group">
                            <?php foreach ($assignments as $assignment): ?>
                                <li class="list-group-item">
                                    In <strong><?php echo h($assignment['year']); ?></strong> (<em><?php echo h($assignment['group_name']); ?></em>), gave to:
                                    <strong><?php echo h($assignment['receiver_name']); ?></strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
<?php admin_footer(); ?>
