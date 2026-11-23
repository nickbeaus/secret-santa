<?php
/**
 * manage_group.php
 *
 * This page allows an admin to manage a specific Secret Santa group,
 * including its settings like a custom message and a group gift idea link.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/sms.php';
require_admin();

/**
 * The year a group's drawing is archived under: the year already used in its
 * history, or otherwise the year the group was created. This keeps a group's
 * archive consistent even when it is cleared or re-run in a later calendar year.
 */
function drawing_year($pdo, $group) {
    $stmt = $pdo->prepare("SELECT MAX(year) FROM assignment_history WHERE group_id = ?");
    $stmt->execute([$group['id']]);
    $year = $stmt->fetchColumn();
    return $year ? (int)$year : (int)format_local($group['creation_date'], 'Y');
}

/**
 * Finds a random assignment where nobody draws themselves or a forbidden person.
 *
 * $p_data maps participant_id => user_id; $forbidden[giver_user_id][receiver_user_id]
 * marks pairs that are not allowed. Uses randomized backtracking (most-constrained
 * giver first), so it finds an assignment whenever one exists.
 *
 * Returns [giver_participant_id => receiver_participant_id], or null if impossible.
 */
function find_assignment(array $p_data, array $forbidden, $max_steps = 200000) {
    $p_ids = array_keys($p_data);
    $options = [];
    foreach ($p_ids as $giver) {
        $options[$giver] = [];
        foreach ($p_ids as $receiver) {
            if ($giver !== $receiver && empty($forbidden[$p_data[$giver]][$p_data[$receiver]])) {
                $options[$giver][] = $receiver;
            }
        }
        shuffle($options[$giver]);
    }

    shuffle($p_ids);
    usort($p_ids, fn($a, $b) => count($options[$a]) <=> count($options[$b]));

    $taken = [];
    $result = [];
    $steps = 0;
    $solve = function ($i) use (&$solve, &$taken, &$result, &$steps, $p_ids, $options, $max_steps) {
        if ($i === count($p_ids)) {
            return true;
        }
        $giver = $p_ids[$i];
        foreach ($options[$giver] as $receiver) {
            if (isset($taken[$receiver])) {
                continue;
            }
            if (++$steps > $max_steps) {
                return false;
            }
            $taken[$receiver] = true;
            $result[$giver] = $receiver;
            if ($solve($i + 1)) {
                return true;
            }
            unset($taken[$receiver], $result[$giver]);
        }
        return false;
    };

    return $solve(0) ? $result : null;
}

/** Everyone in the group with their assignment, view stats and text-message status. */
function load_participants($pdo, $group_id) {
    // View stats come from the participant_views table; group_concat gives all timestamps for the popover.
    $sql = "SELECT
                p1.id AS participant_id,
                u1.id AS user_id,
                u1.name AS giver_name,
                u1.phone_number AS giver_phone,
                u1.short_code AS giver_short_code,
                (SELECT u2.name FROM users u2 JOIN participants p2 ON u2.id = p2.user_id WHERE p2.id = p1.current_giving_to_id) AS receiver_name,
                p1.current_giving_to_id,
                (SELECT COUNT(*) FROM participant_views WHERE participant_id = p1.id) AS view_count,
                (SELECT group_concat(view_timestamp, ',') FROM participant_views WHERE participant_id = p1.id) AS view_history,
                (SELECT MAX(sent_at) FROM sms_log s WHERE s.group_id = p1.group_id AND s.user_id = u1.id AND s.success = 1 AND s.test_mode = 0) AS sms_sent_at,
                (SELECT MAX(sent_at) FROM sms_log s WHERE s.group_id = p1.group_id AND s.user_id = u1.id AND s.success = 1 AND s.test_mode = 1) AS sms_test_at,
                (SELECT provider_id FROM sms_log s WHERE s.group_id = p1.group_id AND s.user_id = u1.id AND s.success = 1 AND s.test_mode = 0 ORDER BY s.id DESC LIMIT 1) AS sms_sent_via,
                (SELECT error FROM sms_log s WHERE s.group_id = p1.group_id AND s.user_id = u1.id ORDER BY s.id DESC LIMIT 1) AS sms_last_error
            FROM participants AS p1
            JOIN users AS u1 ON p1.user_id = u1.id
            WHERE p1.group_id = ?
            ORDER BY u1.name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$group_id]);
    return $stmt->fetchAll();
}

/** Who "Text everyone" would send to: assigned, valid phone, and (when live) not already texted. */
function sms_bulk_targets(array $participants) {
    return array_values(array_filter($participants, fn($p) =>
        $p['current_giving_to_id'] && normalize_phone($p['giver_phone']) && (SMS_TEST_MODE || !$p['sms_sent_at'])));
}

/** Deletes the view log for every participant in the group. */
function clear_group_views($pdo, $group_id) {
    $pdo->prepare("DELETE FROM participant_views WHERE participant_id IN (SELECT id FROM participants WHERE group_id = ?)")
        ->execute([$group_id]);
}

// --- Initial Setup ---
$feedback_message = '';
$feedback_class = '';
$group_id = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
$group = null;

// --- Validate group_id and fetch group details ---
if ($group_id > 0) {
    $stmt = $pdo->prepare("SELECT id, short_code, name, reveal_message, gift_idea_url, sms_template, creation_date FROM `groups` WHERE id = ?");
    $stmt->execute([$group_id]);
    $group = $stmt->fetch();
    if (!$group) {
        header('Location: admin.php?error=groupnotfound');
        exit();
    }
} else {
    header('Location: admin.php');
    exit();
}

// --- Handle Form Submissions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Update Group Settings (Message and Gift URL) ---
    if (isset($_POST['update_settings'])) {
        $reveal_message = trim($_POST['reveal_message'] ?? '');
        $gift_idea_url = trim($_POST['gift_idea_url'] ?? '');

        if (!empty($gift_idea_url) && !is_web_url($gift_idea_url)) {
            $feedback_message = "The Gift Idea URL must be a valid http:// or https:// link.";
            $feedback_class = 'alert-danger';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE `groups` SET reveal_message = ?, gift_idea_url = ? WHERE id = ?");
                $stmt->execute([$reveal_message, $gift_idea_url, $group_id]);
                $feedback_message = "Group settings have been updated.";
                $feedback_class = 'alert-success';
                // Refresh group data
                $group['reveal_message'] = $reveal_message;
                $group['gift_idea_url'] = $gift_idea_url;
            } catch (PDOException $e) {
                $feedback_message = db_error_message('Error updating settings', $e);
                $feedback_class = 'alert-danger';
            }
        }
    }

    // --- Add an existing user to this group ---
    if (isset($_POST['add_participant'])) {
        $user_id_to_add = (int)($_POST['user_id'] ?? 0);
        $disabled_stmt = $pdo->prepare("SELECT disabled FROM users WHERE id = ?");
        $disabled_stmt->execute([$user_id_to_add]);
        if ($user_id_to_add > 0 && $disabled_stmt->fetchColumn()) {
            $feedback_message = "That user is disabled. Enable them on the Users page to add them to a group.";
            $feedback_class = 'alert-warning';
        } elseif ($user_id_to_add > 0) {
            try {
                $stmt = $pdo->prepare("INSERT INTO `participants` (user_id, group_id) VALUES (?, ?)");
                $stmt->execute([$user_id_to_add, $group_id]);
                $feedback_message = "Success! User was added to the group.";
                $feedback_class = 'alert-success';
            } catch (PDOException $e) {
                $feedback_message = $e->getCode() == 23000 ? "Error: This person is already in this group." : db_error_message('Error adding participant', $e);
                $feedback_class = 'alert-danger';
            }
        } else {
            $feedback_message = "Please select a valid user to add.";
            $feedback_class = 'alert-warning';
        }
    }

    // --- Delete a Participant ---
    // Deleting a participant also removes (via ON DELETE CASCADE) their history rows
    // in this group, so if a drawing exists the whole drawing and its archive are cleared.
    if (isset($_POST['delete_participant'])) {
        $participant_id_to_delete = (int)($_POST['participant_id_to_delete'] ?? 0);
        if ($participant_id_to_delete > 0) {
            try {
                $year = drawing_year($pdo, $group);
                $pdo->beginTransaction();
                $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM participants WHERE group_id = ? AND current_giving_to_id IS NOT NULL");
                $check_stmt->execute([$group_id]);
                $assignments_exist = $check_stmt->fetchColumn() > 0;
                $delete_stmt = $pdo->prepare("DELETE FROM participants WHERE id = ? AND group_id = ?");
                $delete_stmt->execute([$participant_id_to_delete, $group_id]);
                $feedback_message = "Participant successfully removed from this group.";
                if ($assignments_exist) {
                    $pdo->prepare("UPDATE participants SET current_giving_to_id = NULL WHERE group_id = ?")->execute([$group_id]);
                    $pdo->prepare("DELETE FROM assignment_history WHERE group_id = ? AND year = ?")->execute([$group_id, $year]);
                    clear_group_views($pdo, $group_id);
                    $feedback_message .= " All drawing assignments for this group have been cleared.";
                }
                $pdo->commit();
                $feedback_class = 'alert-info';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $feedback_message = db_error_message('Error removing participant', $e);
                $feedback_class = 'alert-danger';
            }
        }
    }

    // --- Clear Existing Assignments and their archive ---
    if (isset($_POST['clear_assignments'])) {
        try {
            $year = drawing_year($pdo, $group);
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE participants SET current_giving_to_id = NULL WHERE group_id = ?")->execute([$group_id]);
            clear_group_views($pdo, $group_id);
            $pdo->prepare("DELETE FROM assignment_history WHERE group_id = ? AND year = ?")->execute([$group_id, $year]);
            $pdo->commit();
            $feedback_message = "Assignments and their {$year} archive have been cleared.";
            $feedback_class = 'alert-info';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $feedback_message = db_error_message('Error clearing assignments', $e);
            $feedback_class = 'alert-danger';
        }
    }

    // --- Run the Secret Santa Drawing ---
    if (isset($_POST['run_drawing'])) {
        $p_stmt = $pdo->prepare("SELECT id, user_id FROM participants WHERE group_id = ?");
        $p_stmt->execute([$group_id]);
        $p_data = $p_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $p_ids = array_keys($p_data);

        if (count($p_ids) < 3) {
            $feedback_message = "You need at least 3 participants to run the drawing.";
            $feedback_class = 'alert-warning';
        } else {
            // forbidden[giver_user_id][receiver_user_id] = true
            $forbidden = [];

            $blocked_stmt = $pdo->query("SELECT user_id_1, user_id_2 FROM blocked_pairings");
            while ($row = $blocked_stmt->fetch()) {
                $forbidden[$row['user_id_1']][$row['user_id_2']] = true;
                $forbidden[$row['user_id_2']][$row['user_id_1']] = true;
            }
            $blocked_only = $forbidden;

            $history_group_ids = isset($_POST['history_groups']) && is_array($_POST['history_groups']) ? $_POST['history_groups'] : [];
            $history_group_ids = array_values(array_filter(array_map('intval', $history_group_ids), fn($id) => $id > 0 && $id !== $group_id));
            if (!empty($history_group_ids)) {
                $placeholders = implode(',', array_fill(0, count($history_group_ids), '?'));
                $history_sql = "SELECT giver_part.user_id AS giver_user_id, receiver_part.user_id AS receiver_user_id
                                FROM assignment_history AS ah
                                JOIN participants AS giver_part ON ah.giver_participant_id = giver_part.id
                                JOIN participants AS receiver_part ON ah.receiver_participant_id = receiver_part.id
                                WHERE ah.group_id IN ({$placeholders})";
                $history_stmt = $pdo->prepare($history_sql);
                $history_stmt->execute($history_group_ids);
                while ($row = $history_stmt->fetch()) {
                    $forbidden[$row['giver_user_id']][$row['receiver_user_id']] = true;
                }
            }

            $assignment = find_assignment($p_data, $forbidden);

            if ($assignment) {
                try {
                    $year = drawing_year($pdo, $group);
                    $pdo->beginTransaction();
                    // Reset assignments and view tracking for a fresh run
                    $pdo->prepare("UPDATE participants SET current_giving_to_id = NULL WHERE group_id = ?")->execute([$group_id]);
                    clear_group_views($pdo, $group_id);

                    $assign_stmt = $pdo->prepare("UPDATE participants SET current_giving_to_id = ? WHERE id = ?");
                    foreach ($assignment as $giver => $receiver) $assign_stmt->execute([$receiver, $giver]);

                    $pdo->prepare("DELETE FROM assignment_history WHERE group_id = ? AND year = ?")->execute([$group_id, $year]);
                    $history_stmt = $pdo->prepare("INSERT INTO assignment_history (group_id, year, giver_participant_id, receiver_participant_id) VALUES (?, ?, ?, ?)");
                    foreach ($assignment as $giver => $receiver) $history_stmt->execute([$group_id, $year, $giver, $receiver]);

                    $pdo->commit();
                    $feedback_message = "Success! The Secret Santa drawing is complete and archived under {$year}.";
                    $feedback_class = 'alert-success';
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $feedback_message = db_error_message('An error occurred during the drawing', $e);
                    $feedback_class = 'alert-danger';
                }
            } elseif (!empty($history_group_ids) && find_assignment($p_data, $blocked_only)) {
                $feedback_message = "No drawing is possible that avoids every pairing from the selected past groups. Select fewer past groups and try again. Nothing was changed.";
                $feedback_class = 'alert-danger';
            } else {
                $feedback_message = "No valid drawing exists with the current blocked pairings. Remove some blocks and try again. Nothing was changed.";
                $feedback_class = 'alert-danger';
            }
        }
    }
}

// --- Text Message (SMS) Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Save the group's SMS template ---
    if (isset($_POST['save_sms_template'])) {
        $template = sms_clean($_POST['sms_template'] ?? '');
        $problem = '';
        if ($template !== '' && strpos($template, '{link}') === false) {
            $problem = 'The text must include {link}, or nobody will be able to see their pick.';
        } else {
            // Check the text as it will be sent to each person (names differ in length).
            $names = array_column(load_participants($pdo, $group_id), 'giver_name') ?: ['Participant'];
            foreach ($names as $name) {
                $preview = render_sms($template ?: SMS_DEFAULT_TEMPLATE, $name, $group, 'Xxxxxxxx');
                if ($p_problem = sms_problem($preview)) {
                    $problem = "The text for {$name} has a problem. {$p_problem}";
                    break;
                }
            }
        }
        if ($problem) {
            $feedback_message = $problem;
            $feedback_class = 'alert-warning';
            $group['sms_template'] = $template; // keep what was typed
        } else {
            $pdo->prepare("UPDATE `groups` SET sms_template = ? WHERE id = ?")->execute([$template !== '' ? $template : null, $group_id]);
            $group['sms_template'] = $template !== '' ? $template : null;
            $feedback_message = $template !== '' ? 'Text message saved.' : 'Text message reset to the default.';
            $feedback_class = 'alert-success';
        }
    }

    // --- Send texts: a sample to the admin, one participant, or everyone not yet texted ---
    if (isset($_POST['send_sms_sample']) || isset($_POST['send_sms_one']) || isset($_POST['send_sms_all'])) {
        $assigned = array_values(array_filter(load_participants($pdo, $group_id), fn($p) => $p['current_giving_to_id']));
        $force_test = isset($_POST['send_sms_sample']);
        if (isset($_POST['send_sms_sample'])) {
            $targets = array_slice($assigned, 0, 1);
        } elseif (isset($_POST['send_sms_one'])) {
            $one = (int)($_POST['participant_id'] ?? 0);
            $targets = array_values(array_filter($assigned, fn($p) => (int)$p['participant_id'] === $one));
        } else {
            $targets = sms_bulk_targets($assigned);
        }

        if (!sms_configured()) {
            $feedback_message = 'Texting is not set up yet: set the VOIPMS_* values in the .env file.';
            $feedback_class = 'alert-warning';
        } elseif (empty($targets)) {
            $feedback_message = empty($assigned) ? 'Run the drawing before sending texts.' : 'There was nobody to text.';
            $feedback_class = 'alert-warning';
        } else {
            set_time_limit(30 + (VOIPMS_TIMEOUT + 1) * count($targets));
            $sent = 0;
            $failures = [];
            foreach ($targets as $i => $p) {
                if ($i > 0) usleep(500000); // be gentle with the VoIP.ms API
                $result = send_participant_sms($pdo, $group, $p, $force_test);
                if ($result['ok']) {
                    $sent++;
                } else {
                    $failures[] = "{$p['giver_name']}: {$result['error']}";
                }
            }
            $where = (SMS_TEST_MODE || $force_test) ? ' to the test number ' . format_phone(SMS_TEST_NUMBER) : '';
            $feedback_message = "Sent {$sent} " . ($sent === 1 ? 'text' : 'texts') . $where . '.';
            if ($failures) {
                $feedback_message .= ' Failed: ' . implode(' | ', $failures);
            }
            $feedback_class = $failures ? ($sent ? 'alert-warning' : 'alert-danger') : 'alert-success';
        }
    }
}

// --- Fetch all data required for page display ---
try {
    $participants = load_participants($pdo, $group_id);

    $all_other_groups_stmt = $pdo->prepare("SELECT id, name FROM `groups` WHERE id != ? ORDER BY creation_date DESC");
    $all_other_groups_stmt->execute([$group_id]);
    $all_other_groups = $all_other_groups_stmt->fetchAll();

    $available_users_stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE disabled = 0 AND id NOT IN (SELECT user_id FROM participants WHERE group_id = ?) ORDER BY name ASC");
    $available_users_stmt->execute([$group_id]);
    $available_users = $available_users_stmt->fetchAll();

    $is_drawing_run = false;
    foreach ($participants as $p) {
        if (!is_null($p['current_giving_to_id'])) {
            $is_drawing_run = true;
            break;
        }
    }
} catch (PDOException $e) {
    error_log('Secret Santa: Error fetching page data: ' . $e->getMessage());
    http_response_code(500);
    exit('Error fetching page data. Details were written to the server log.');
}

$delete_confirm = $is_drawing_run
    ? 'Remove this participant? The current drawing for this group and its archive will also be cleared for everyone.'
    : 'Are you sure you want to remove this participant from the group?';

$sms_template = $group['sms_template'] ?: SMS_DEFAULT_TEMPLATE;
$sms_targets = sms_bulk_targets($participants);
$sms_no_phone = count(array_filter($participants, fn($p) => !normalize_phone($p['giver_phone'])));
$sms_already = count(array_filter($participants, fn($p) => $p['sms_sent_at']));

admin_header('Manage: ' . $group['name'], $feedback_message, $feedback_class,
    '.participant-link-input { font-family: monospace; font-size: 0.9em; }
     #sms_preview { white-space: pre-wrap; background: #e9f5ff; border-radius: 1rem 1rem 1rem 0.25rem; }');
?>
    <h1 class="mb-2">Manage: <?php echo h($group['name']); ?></h1>
    <hr class="mb-4">

    <!-- Group Settings Card -->
    <div class="card shadow-sm mb-5">
        <div class="card-header"><h5 class="mb-0">Group Settings</h5></div>
        <div class="card-body">
            <form action="manage_group.php?group_id=<?php echo $group_id; ?>" method="POST">
                <?php echo csrf_field(); ?>
                <div class="mb-3">
                    <label for="reveal_message" class="form-label">Custom Message for Reveal Page</label>
                    <textarea class="form-control" id="reveal_message" name="reveal_message" rows="3" placeholder="e.g., The gift exchange will be on December 24th. Please keep your gifts under $20."><?php echo h($group['reveal_message']); ?></textarea>
                </div>
                <div class="mb-3">
                    <label for="gift_idea_url" class="form-label">Group Gift Idea URL (Optional)</label>
                    <input type="url" class="form-control" id="gift_idea_url" name="gift_idea_url" value="<?php echo h($group['gift_idea_url']); ?>" placeholder="e.g., https://docs.google.com/spreadsheets/...">
                    <?php if ($gifts_url = group_gifts_url($group)): ?>
                        <div class="form-text">Short link for texts: <code><?php echo h($gifts_url); ?></code></div>
                    <?php endif; ?>
                </div>
                <button type="submit" name="update_settings" class="btn btn-primary">Save Settings</button>
            </form>
        </div>
    </div>

    <!-- Add Participant Card -->
    <div class="card mb-5 shadow-sm">
        <div class="card-header"><h5 class="mb-0">Add Participant to Group</h5></div>
        <div class="card-body">
            <form action="manage_group.php?group_id=<?php echo $group_id; ?>" method="POST">
                <?php echo csrf_field(); ?>
                <div class="row">
                    <div class="col-md-10">
                        <label for="user_id" class="form-label">Select User to Add</label>
                        <select class="form-select" id="user_id" name="user_id" required>
                            <option value="">Select from available users...</option>
                            <?php foreach ($available_users as $user): ?>
                                <option value="<?php echo (int)$user['id']; ?>">
                                    <?php echo h($user['name']); ?> <?php if ($user['email']) echo "(" . h($user['email']) . ")"; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" name="add_participant" class="btn btn-primary w-100">Add</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Run Drawing Card -->
    <div class="card shadow-sm mb-5">
        <div class="card-header"><h5 class="mb-0">Run Drawing & Manage Assignments</h5></div>
        <div class="card-body">
            <form action="manage_group.php?group_id=<?php echo $group_id; ?>" method="POST">
                <?php echo csrf_field(); ?>
                <div class="mb-3">
                    <label for="history_groups" class="form-label fw-bold">Avoid Previous Pairings (Optional)</label>
                    <p class="text-muted small">Select any past groups whose pairings must not be repeated. Blocked pairings are always respected. If no drawing is possible with these rules, you'll be told and nothing will change. Hold Ctrl (or Cmd on Mac) to select multiple.</p>
                    <select multiple class="form-select" id="history_groups" name="history_groups[]" size="4">
                        <?php foreach ($all_other_groups as $g): ?>
                            <option value="<?php echo (int)$g['id']; ?>"><?php echo h($g['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <?php if ($is_drawing_run): ?>
                        <button type="submit" name="clear_assignments" class="btn btn-warning" data-confirm="Are you sure you want to clear all current assignments and their archive? This cannot be undone.">Clear Assignments</button>
                    <?php endif; ?>
                    <button type="submit" name="run_drawing" class="btn btn-success" <?php if (count($participants) < 3) echo 'disabled'; ?>
                            data-confirm="<?php echo $is_drawing_run ? 'Re-run the drawing? Everyone\'s current assignment and view history will be replaced.' : 'Are you sure you want to run the drawing?'; ?>">
                        <?php echo $is_drawing_run ? 'Re-Run Drawing' : 'Run Secret Santa Drawing'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Text Messages Card -->
    <div class="card shadow-sm mb-5" id="sms">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Text Messages</h5>
            <?php if (SMS_TEST_MODE): ?>
                <span class="badge bg-warning text-dark">TEST MODE</span>
            <?php else: ?>
                <span class="badge bg-danger">LIVE: texts go to participants</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (!sms_configured()): ?>
                <div class="alert alert-warning">Texting isn't set up yet. Set the <code>VOIPMS_*</code> values in the <code>.env</code> file and restart the container.</div>
            <?php endif; ?>
            <?php if (SMS_TEST_MODE): ?>
                <div class="alert alert-info small">
                    Test mode is on: every text goes to <strong><?php echo h(format_phone(SMS_TEST_NUMBER)); ?></strong>, starting with "TEST for (name):".
                    To text participants for real, set <code>SMS_TEST_MODE=false</code> in the <code>.env</code> file and restart the container.
                </div>
            <?php endif; ?>

            <form action="manage_group.php?group_id=<?php echo $group_id; ?>#sms" method="POST">
                <?php echo csrf_field(); ?>
                <label for="sms_template" class="form-label fw-bold">Message</label>
                <textarea class="form-control" id="sms_template" name="sms_template" rows="5" maxlength="2048"><?php echo h($sms_template); ?></textarea>
                <div class="form-text">
                    Sent as a picture message (MMS), so it can be long and include emoji.
                    Placeholders: <code>{name}</code> first name, <code>{fullname}</code>, <code>{group}</code>,
                    <code>{link}</code> their reveal link (required), <code>{message}</code> the custom message above,
                    <code>{gifts}</code> short gift-ideas link. A line with <code>{message}</code> or <code>{gifts}</code> is left out if the group doesn't have one.
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <span id="sms_counter" class="small"></span>
                    <button type="submit" name="save_sms_template" class="btn btn-sm btn-primary">Save Message</button>
                </div>
                <div class="mt-3">
                    <div class="small text-muted mb-1">Preview<?php if ($participants) echo ' for ' . h($participants[0]['giver_name']); ?>:</div>
                    <div id="sms_preview" class="p-3 small"></div>
                </div>
            </form>

            <hr>
            <form action="manage_group.php?group_id=<?php echo $group_id; ?>#sms" method="POST" class="d-flex flex-wrap gap-2 align-items-center">
                <?php echo csrf_field(); ?>
                <button type="submit" name="send_sms_sample" class="btn btn-outline-primary"
                        <?php if (!$is_drawing_run || !sms_configured()) echo 'disabled'; ?>
                        data-confirm="Send a sample text to <?php echo h(format_phone(SMS_TEST_NUMBER)); ?>?">
                    Send Sample to Me
                </button>
                <button type="submit" name="send_sms_all" class="btn btn-success"
                        <?php if (!$is_drawing_run || !sms_configured() || !$sms_targets) echo 'disabled'; ?>
                        data-confirm="<?php echo h(SMS_TEST_MODE
                            ? 'TEST MODE: send ' . count($sms_targets) . ' text(s) to ' . format_phone(SMS_TEST_NUMBER) . '?'
                            : 'Text ' . count($sms_targets) . ' participant(s) their reveal link now?'); ?>">
                    <?php echo SMS_TEST_MODE ? 'Test: Text Everyone' : 'Text Everyone Not Yet Texted'; ?> (<?php echo count($sms_targets); ?>)
                </button>
                <a href="phone_texts.php?group_id=<?php echo $group_id; ?>"
                   class="btn btn-outline-success<?php if (!$is_drawing_run) echo ' disabled'; ?>">📱 Send from My Phone</a>
                <span class="small text-muted">
                    <?php if (!$is_drawing_run): ?>Run the drawing first.<?php else: ?>
                        <?php if ($sms_no_phone) echo $sms_no_phone . ' without a mobile number will be skipped (add numbers on <a href="users.php">Users</a>). '; ?>
                        <?php if (!SMS_TEST_MODE && $sms_already) echo $sms_already . ' already texted.'; ?>
                    <?php endif; ?>
                </span>
            </form>
        </div>
    </div>

    <!-- Participants List Card -->
    <div class="card shadow-sm mb-5">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Participants in this Group (<?php echo count($participants); ?>)</h5>
            <?php if ($is_drawing_run): ?>
                <button type="button" class="btn btn-sm btn-info" id="show-all-btn">Show All Assignments</button>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (empty($participants)): ?>
                <p class="text-center text-muted">No participants yet. Add one from the list above.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle">
                        <thead>
                        <tr>
                            <th>Name</th>
                            <th>Viewed?</th>
                            <th>Reveal Link</th>
                            <th>Text</th>
                            <th>Assigned To</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($participants as $p): ?>
                            <tr>
                                <td><?php echo h($p['giver_name']); ?></td>
                                <td>
                                    <?php if ($p['view_count'] > 0):
                                        // Format view history (stored in UTC) for the popover
                                        $views = $p['view_history'] ? explode(',', $p['view_history']) : [];
                                        rsort($views); // newest first
                                        $history_html = "<ul class='mb-0 ps-3 text-start small'>";
                                        foreach ($views as $v) {
                                            $history_html .= "<li>" . format_local($v, 'M j, g:i a') . "</li>";
                                        }
                                        $history_html .= "</ul>";
                                        ?>
                                        <span class="badge bg-success"
                                              style="cursor: pointer;"
                                              tabindex="0"
                                              data-bs-toggle="popover"
                                              data-bs-html="true"
                                              data-bs-trigger="hover focus"
                                              title="View History"
                                              data-bs-content="<?php echo h($history_html); ?>">
                                            Yes (<?php echo (int)$p['view_count']; ?>)
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">No</span>
                                    <?php endif; ?>
                                </td>
                                <td style="min-width: 290px;">
                                    <div class="input-group">
                                        <input type="text" class="form-control form-control-sm participant-link-input" value="<?php echo h(short_reveal_url($p['giver_short_code'])); ?>" readonly>
                                        <button class="btn btn-sm btn-outline-secondary copy-link-btn" type="button">Copy</button>
                                    </div>
                                </td>
                                <td class="small" style="min-width: 150px;">
                                    <?php $phone = normalize_phone($p['giver_phone']); ?>
                                    <?php if ($p['sms_sent_at']): ?>
                                        <span class="badge bg-success">Texted</span> <?php echo format_local($p['sms_sent_at'], 'M j, g:i a'); ?><?php if ($p['sms_sent_via'] === 'phone') echo ' <span title="Sent from your phone">📱</span>'; ?>
                                    <?php elseif ($p['sms_last_error']): ?>
                                        <span class="badge bg-danger" title="<?php echo h($p['sms_last_error']); ?>">Failed</span>
                                    <?php elseif ($p['sms_test_at']): ?>
                                        <span class="badge bg-warning text-dark">Test sent</span>
                                    <?php elseif (!$phone): ?>
                                        <span class="text-muted fst-italic">No phone</span>
                                    <?php else: ?>
                                        <span class="text-muted">Not sent</span>
                                    <?php endif; ?>
                                    <?php if ($p['current_giving_to_id'] && sms_configured() && ($phone || SMS_TEST_MODE)): ?>
                                        <form action="manage_group.php?group_id=<?php echo $group_id; ?>#sms" method="POST" class="d-inline"
                                              data-confirm="<?php echo h(SMS_TEST_MODE ? 'TEST MODE: send ' . $p['giver_name'] . "'s text to " . format_phone(SMS_TEST_NUMBER) . '?' : 'Text ' . $p['giver_name'] . ' at ' . format_phone($phone) . '?'); ?>">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="participant_id" value="<?php echo (int)$p['participant_id']; ?>">
                                            <button type="submit" name="send_sms_one" class="btn btn-link btn-sm p-0 ms-1"><?php echo ($p['sms_sent_at'] || $p['sms_test_at'] || $p['sms_last_error']) ? 'Resend' : 'Send'; ?></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($p['receiver_name']): ?>
                                        <button class="btn btn-sm btn-outline-secondary reveal-assignment-btn" type="button" data-id="<?php echo (int)$p['participant_id']; ?>">Show</button>
                                        <strong class="assignment-name" id="assignment-<?php echo (int)$p['participant_id']; ?>" style="display:none;"><?php echo h($p['receiver_name']); ?></strong>
                                    <?php else: ?>
                                        <span class="text-muted fst-italic">Not yet assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form action="manage_group.php?group_id=<?php echo $group_id; ?>" method="POST" style="display:inline;" data-confirm="<?php echo h($delete_confirm); ?>">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="participant_id_to_delete" value="<?php echo (int)$p['participant_id']; ?>">
                                        <button type="submit" name="delete_participant" class="btn btn-danger btn-sm">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<script nonce="<?php echo csp_nonce(); ?>">
    // Live preview + length check for the text message (the server re-checks on save/send).
    // Mirrors render_sms() in includes/sms.php.
    (function () {
        const textarea = document.getElementById('sms_template');
        if (!textarea) return;
        const names = <?php echo json_encode(array_column($participants, 'giver_name') ?: ['Participant']); ?>;
        const values = <?php echo json_encode([
            'group'   => $group['name'],
            'link'    => short_reveal_url($participants[0]['giver_short_code'] ?? 'Xxxxxxxx'),
            'message' => trim((string)$group['reveal_message']),
            'gifts'   => group_gifts_url($group),
        ]); ?>;
        const defaultTemplate = <?php echo json_encode(SMS_DEFAULT_TEMPLATE); ?>;
        const max = <?php echo SMS_MAX_LENGTH; ?>;
        const counter = document.getElementById('sms_counter');
        const preview = document.getElementById('sms_preview');

        function render(tpl, name) {
            const v = Object.assign({}, values, {name: name.trim().split(/\s+/)[0], fullname: name.trim()});
            const lines = tpl.replace(/\r\n?/g, '\n').split('\n')
                .filter(line => !(line.includes('{message}') && !v.message) && !(line.includes('{gifts}') && !v.gifts))
                .map(line => line.replace(/\{(name|fullname|group|link|message|gifts)\}/g, (m, key) => v[key]));
            return lines.join('\n').replace(/[ \t]+/g, ' ').replace(/ *\n */g, '\n').replace(/\n{3,}/g, '\n\n').trim();
        }
        function update() {
            const tpl = textarea.value.trim() || defaultTemplate;
            preview.textContent = render(tpl, names[0]);
            const longest = Math.max(...names.map(n => [...render(tpl, n)].length));
            let msg, cls;
            if (!tpl.includes('{link}')) {
                msg = 'Must include {link}'; cls = 'text-danger fw-bold';
            } else if (longest > max) {
                msg = longest + ' / ' + max + ' characters: too long'; cls = 'text-danger fw-bold';
            } else {
                msg = longest + ' / ' + max + ' characters'; cls = 'text-success';
            }
            counter.textContent = msg;
            counter.className = 'small ' + cls;
        }
        textarea.addEventListener('input', update);
        update();
    })();

    document.addEventListener('DOMContentLoaded', function() {
        // Initialize Bootstrap Popovers
        document.querySelectorAll('[data-bs-toggle="popover"]').forEach(el => new bootstrap.Popover(el));

        // Logic for revealing assignments individually
        document.querySelectorAll('.reveal-assignment-btn').forEach(button => {
            button.addEventListener('click', function() {
                const assignmentText = document.getElementById('assignment-' + this.dataset.id);
                if (assignmentText) {
                    this.style.display = 'none';
                    assignmentText.style.display = 'inline';
                }
            });
        });

        // Logic for the "Show All" button
        const showAllButton = document.getElementById('show-all-btn');
        if (showAllButton) {
            showAllButton.addEventListener('click', function() {
                document.querySelectorAll('.reveal-assignment-btn').forEach(button => button.click());
                this.style.display = 'none'; // Hide the "Show All" button after clicking
            });
        }

        // Logic for copying links
        document.querySelectorAll('.copy-link-btn').forEach(button => {
            button.addEventListener('click', async function() {
                const inputField = this.previousElementSibling;
                try {
                    await navigator.clipboard.writeText(inputField.value);
                } catch (err) {
                    // Clipboard API unavailable (e.g. plain http): fall back to selecting the text
                    inputField.select();
                    document.execCommand('copy');
                }
                const originalText = this.textContent;
                this.textContent = 'Copied!';
                this.classList.add('btn-success');
                setTimeout(() => {
                    this.textContent = originalText;
                    this.classList.remove('btn-success');
                }, 2000);
            });
        });
    });
</script>
<?php admin_footer(); ?>
