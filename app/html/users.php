<?php
/**
 * users.php
 *
 * This page allows an admin to manage all users in the system,
 * create new users, edit existing users, and manage blocked pairings.
 * Users who have never been added to a group can also be deleted.
 * Disabled users are kept but left out of the "Add Participant" menu on group pages.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_layout.php';
require_admin();

$feedback_message = '';
$feedback_class = '';
$editing_user = null;
$form_values = null; // re-filled form after a failed save

// --- Check if we are in "edit" mode ---
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $stmt = $pdo->prepare("SELECT id, name, email, phone_number FROM users WHERE id = ?");
    $stmt->execute([$edit_id]);
    $editing_user = $stmt->fetch();
}


// --- Handle Form Submissions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Handle Creating or Updating a User ---
    if (isset($_POST['create_user']) || isset($_POST['update_user'])) {
        $is_update = isset($_POST['update_user']);
        $user_id = (int)($_POST['user_id'] ?? 0);
        $user_name = trim($_POST['user_name'] ?? '');
        $user_email = trim($_POST['user_email'] ?? '');
        $user_phone = normalize_phone($_POST['user_phone'] ?? '');

        $errors = [];
        if ($user_name === '') $errors[] = "Please provide a name.";
        if ($user_email !== '' && !filter_var($user_email, FILTER_VALIDATE_EMAIL)) $errors[] = "The email address isn't in a valid format.";
        if ($user_phone === false) $errors[] = "The phone number must be a 10-digit US/Canada number, e.g. (630) 555-0123.";
        if ($is_update && $user_id <= 0) $errors[] = "No user was selected to update.";

        if (empty($errors)) {
            $db_email = $user_email !== '' ? $user_email : null;
            $db_phone = $user_phone !== '' ? $user_phone : null;
            try {
                if ($is_update) {
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone_number = ? WHERE id = ?");
                    $stmt->execute([$user_name, $db_email, $db_phone, $user_id]);
                    $feedback_message = "Success! User '{$user_name}' has been updated.";
                    // Leave edit mode after a successful update
                    $editing_user = null;
                } else {
                    $stmt = $pdo->prepare("INSERT INTO `users` (uuid, short_code, name, email, phone_number) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([generate_uuidv4(), generate_short_code(), $user_name, $db_email, $db_phone]);
                    $feedback_message = "Success! User '{$user_name}' has been created.";
                }
                $feedback_class = 'alert-success';
            } catch (PDOException $e) {
                $feedback_message = $e->getCode() == 23000 ? "Error: A user with this email already exists." : db_error_message('Error saving user', $e);
                $feedback_class = 'alert-danger';
            }
        } else {
            $feedback_message = implode(' ', $errors);
            $feedback_class = 'alert-warning';
        }

        // On failure, put what was typed back into the form instead of clearing it.
        if ($feedback_class !== 'alert-success') {
            $form_values = [
                'name'         => $user_name,
                'email'        => $user_email,
                'phone_number' => $_POST['user_phone'] ?? '',
            ];
        }
    }


    // --- Handle Deleting a User ---
    // Only users who were never in a group can be deleted: removing anyone else
    // would cascade into their groups' drawings and everyone's assignment history.
    if (isset($_POST['delete_user'])) {
        $user_id = (int)($_POST['user_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM participants WHERE user_id = ?");
            $stmt->execute([$user_id]);
            if ($stmt->fetchColumn() > 0) {
                $feedback_message = "This user belongs to at least one group, so they can't be deleted without erasing assignment history. Remove them from their groups first.";
                $feedback_class = 'alert-warning';
            } else {
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
                $feedback_message = "The user has been deleted.";
                $feedback_class = 'alert-info';
                if ($editing_user && (int)$editing_user['id'] === $user_id) {
                    $editing_user = null;
                }
            }
        } catch (PDOException $e) {
            $feedback_message = db_error_message('Error deleting user', $e);
            $feedback_class = 'alert-danger';
        }
    }

    // --- Handle Disabling / Enabling a User ---
    // Disabled users stay in any groups they're already in; they just can't be added to new ones.
    if (isset($_POST['set_disabled'])) {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $disabled = (int)($_POST['set_disabled'] === '1');
        try {
            $stmt = $pdo->prepare("UPDATE users SET disabled = ? WHERE id = ?");
            $stmt->execute([$disabled, $user_id]);
            if ($stmt->rowCount() > 0) {
                $feedback_message = $disabled
                    ? "The user has been disabled and won't appear when adding participants to a group."
                    : "The user has been enabled.";
                $feedback_class = 'alert-info';
            }
        } catch (PDOException $e) {
            $feedback_message = db_error_message('Error updating user', $e);
            $feedback_class = 'alert-danger';
        }
    }

    // --- Handle Adding a Blocked Pairing ---
    if (isset($_POST['add_block'])) {
        $user1_id = (int)($_POST['user1_id'] ?? 0);
        $user2_id = (int)($_POST['user2_id'] ?? 0);
        if ($user1_id > 0 && $user2_id > 0 && $user1_id !== $user2_id) {
            $low_id = min($user1_id, $user2_id);
            $high_id = max($user1_id, $user2_id);
            try {
                $sql = "INSERT INTO blocked_pairings (user_id_1, user_id_2) VALUES (?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$low_id, $high_id]);
                $feedback_message = "Success! The pairing has been blocked.";
                $feedback_class = 'alert-success';
            } catch (PDOException $e) {
                $feedback_message = $e->getCode() == 23000 ? "Error: This pairing is already blocked." : db_error_message('Error adding block', $e);
                $feedback_class = 'alert-danger';
            }
        } else {
            $feedback_message = "Please select two different users to block.";
            $feedback_class = 'alert-warning';
        }
    }

    // --- Handle Deleting a Blocked Pairing ---
    if (isset($_POST['delete_block'])) {
        $block_id = (int)($_POST['block_id'] ?? 0);
        if ($block_id > 0) {
            try {
                $sql = "DELETE FROM blocked_pairings WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$block_id]);
                $feedback_message = "The block has been removed.";
                $feedback_class = 'alert-info';
            } catch (PDOException $e) {
                $feedback_message = db_error_message('Error removing block', $e);
                $feedback_class = 'alert-danger';
            }
        }
    }
}


// --- Fetch Data for Display ---
$all_users = $pdo->query("SELECT u.id, u.name, u.email, u.phone_number, u.disabled, (SELECT COUNT(*) FROM participants p WHERE p.user_id = u.id) AS group_count FROM users u ORDER BY u.disabled ASC, u.name ASC")->fetchAll();
$blocked_pairings = $pdo->query(
    "SELECT bp.id, bp.user_id_1 AS id1, bp.user_id_2 AS id2, u1.name AS name1, u2.name AS name2, u1.disabled AS disabled1, u2.disabled AS disabled2
     FROM blocked_pairings bp
     JOIN users u1 ON bp.user_id_1 = u1.id
     JOIN users u2 ON bp.user_id_2 = u2.id
     ORDER BY u1.name ASC"
)->fetchAll();


// Values shown in the create/edit form
$form = $form_values ?? [
    'name'         => $editing_user['name'] ?? '',
    'email'        => $editing_user['email'] ?? '',
    'phone_number' => format_phone($editing_user['phone_number'] ?? ''),
];
// How many blocks each user is in, for the "N blocks" links in the user list
$block_counts = [];
foreach ($blocked_pairings as $block) {
    $block_counts[$block['id1']] = ($block_counts[$block['id1']] ?? 0) + 1;
    $block_counts[$block['id2']] = ($block_counts[$block['id2']] ?? 0) + 1;
}
$blocked_users = array_filter($all_users, fn($u) => isset($block_counts[$u['id']]));
$disabled_count = count(array_filter($all_users, fn($u) => $u['disabled']));
$phone_count = count(array_filter($all_users, fn($u) => normalize_phone($u['phone_number']) !== '' && normalize_phone($u['phone_number']) !== false));

admin_header('User Management', $feedback_message, $feedback_class);
?>
    <h1 class="mb-4">User Management</h1>

    <!-- Create or Edit User Card -->
    <div class="card shadow-sm mb-5">
        <div class="card-header"><h5 class="mb-0"><?php echo $editing_user ? 'Edit User' : 'Create a New User'; ?></h5></div>
        <div class="card-body">
            <form action="users.php<?php if ($editing_user) echo '?edit_id=' . (int)$editing_user['id']; ?>" method="POST">
                <?php echo csrf_field(); ?>
                <?php if ($editing_user): ?>
                    <input type="hidden" name="user_id" value="<?php echo (int)$editing_user['id']; ?>">
                <?php endif; ?>
                <div class="row">
                    <div class="col-md-4 mb-2">
                        <label for="user_name" class="form-label">Full Name</label>
                        <input type="text" class="form-control" id="user_name" name="user_name" value="<?php echo h($form['name']); ?>" required>
                    </div>
                    <div class="col-md-3 mb-2">
                        <label for="user_email" class="form-label">Email <span class="text-muted small">(optional)</span></label>
                        <input type="email" class="form-control" id="user_email" name="user_email" value="<?php echo h($form['email']); ?>">
                    </div>
                    <div class="col-md-3 mb-2">
                        <label for="user_phone" class="form-label">Mobile Phone <span class="text-muted small">(for texts)</span></label>
                        <div class="input-group has-validation">
                            <span class="input-group-text">+1</span>
                            <input type="tel" class="form-control" id="user_phone" name="user_phone"
                                   value="<?php echo h($form['phone_number']); ?>"
                                   inputmode="tel" autocomplete="tel-national" maxlength="20"
                                   placeholder="(630) 555-0123" aria-describedby="user_phone_help">
                            <div class="invalid-feedback">Enter a 10-digit US/Canada number.</div>
                        </div>
                        <div id="user_phone_help" class="form-text">Area code + number. Any format works.</div>
                    </div>
                    <div class="col-md-2 mb-2 d-flex align-items-end pb-4">
                        <?php if ($editing_user): ?>
                            <button type="submit" name="update_user" class="btn btn-warning w-100">Save Changes</button>
                        <?php else: ?>
                            <button type="submit" name="create_user" class="btn btn-primary w-100">Create User</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($editing_user): ?>
                    <a href="users.php" class="btn btn-sm btn-link mt-2">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="row">
        <!-- All Users List -->
        <div class="col-md-7">
            <div class="card shadow-sm mb-4">
                <div class="card-header"><h5 class="mb-0">All Users (<?php echo count($all_users); ?>)</h5>
                    <small class="text-muted"><?php echo $phone_count; ?> of <?php echo count($all_users); ?> have a mobile number<?php if ($disabled_count) echo " · {$disabled_count} disabled"; ?></small></div>
                <div class="card-body">
                    <div class="list-group">
                        <?php foreach ($all_users as $user): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center<?php if ($user['disabled']) echo ' bg-body-tertiary'; ?>">
                                <div<?php if ($user['disabled']) echo ' class="opacity-75"'; ?>>
                                    <strong><?php echo h($user['name']); ?></strong>
                                    <?php if ($user['disabled']): ?><span class="badge text-bg-secondary ms-1">Disabled</span><?php endif; ?><br>
                                    <?php $phone = normalize_phone($user['phone_number']); ?>
                                    <?php if ($phone): ?>
                                        <small class="text-body-secondary">📱 <?php echo h(format_phone($phone)); ?></small>
                                    <?php elseif ($phone === false): ?>
                                        <small class="text-danger" title="Saved as: <?php echo h($user['phone_number']); ?>">⚠ Invalid phone, please edit</small>
                                    <?php else: ?>
                                        <small class="text-muted fst-italic">No phone</small>
                                    <?php endif; ?>
                                    <?php if ($user['email']): ?><small class="text-muted"> · <?php echo h($user['email']); ?></small><?php endif; ?>
                                    <?php if ($n_blocks = $block_counts[$user['id']] ?? 0): ?>
                                        <small> · <a href="users.php?blocks_for=<?php echo (int)$user['id']; ?>#existing_blocks" class="show-blocks link-secondary" data-user-id="<?php echo (int)$user['id']; ?>">🚫 <?php echo $n_blocks; ?> block<?php echo $n_blocks === 1 ? '' : 's'; ?></a></small>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex gap-1">
                                    <a href="users.php?edit_id=<?php echo (int)$user['id']; ?>" class="btn btn-sm btn-secondary">Edit</a>
                                    <form action="users.php" method="POST">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>">
                                        <?php if ($user['disabled']): ?>
                                            <button type="submit" name="set_disabled" value="0" class="btn btn-sm btn-outline-success">Enable</button>
                                        <?php else: ?>
                                            <button type="submit" name="set_disabled" value="1" class="btn btn-sm btn-outline-secondary" title="Hide from the Add Participant menu">Disable</button>
                                        <?php endif; ?>
                                    </form>
                                    <?php if ($user['group_count'] == 0): ?>
                                        <form action="users.php" method="POST" data-confirm="Delete <?php echo h($user['name']); ?>? This cannot be undone.">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>">
                                            <button type="submit" name="delete_user" class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Blocked Pairings Management -->
        <div class="col-md-5">
            <div class="card shadow-sm mb-4">
                <div class="card-header"><h5 class="mb-0">Block Pairings</h5></div>
                <div class="card-body">
                    <p class="text-muted small">Block users from being assigned to each other (e.g., family members).</p>
                    <form action="users.php" method="POST">
                <?php echo csrf_field(); ?>
                        <div class="mb-2">
                            <select name="user1_id" class="form-select" required>
                                <option value="">Select first user...</option>
                                <?php foreach ($all_users as $user): ?>
                                    <option value="<?php echo (int)$user['id']; ?>"><?php echo h($user['name']); ?><?php if ($user['disabled']) echo ' (disabled)'; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <select name="user2_id" class="form-select" required>
                                <option value="">Select second user...</option>
                                <?php foreach ($all_users as $user): ?>
                                    <option value="<?php echo (int)$user['id']; ?>"><?php echo h($user['name']); ?><?php if ($user['disabled']) echo ' (disabled)'; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" name="add_block" class="btn btn-primary w-100">Add Block</button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm" id="existing_blocks">
                <div class="card-header"><h5 class="mb-0">Existing Blocks (<?php echo count($blocked_pairings); ?>)</h5></div>
                <div class="card-body">
                    <?php if (empty($blocked_pairings)): ?>
                        <p class="text-center text-muted">No blocked pairings found.</p>
                    <?php else: ?>
                        <?php $blocks_for = (int)($_GET['blocks_for'] ?? 0); ?>
                        <label for="block_filter" class="form-label small text-muted">Show blocks for</label>
                        <select id="block_filter" class="form-select form-select-sm mb-2">
                            <option value="">Everyone</option>
                            <?php foreach ($blocked_users as $user): ?>
                                <option value="<?php echo (int)$user['id']; ?>"<?php if ((int)$user['id'] === $blocks_for) echo ' selected'; ?>>
                                    <?php echo h($user['name']); ?> (<?php echo $block_counts[$user['id']]; ?>)<?php if ($user['disabled']) echo ' (disabled)'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <ul class="list-group">
                            <?php foreach ($blocked_pairings as $block): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center" data-users="<?php echo (int)$block['id1'] . ' ' . (int)$block['id2']; ?>">
                                    <span>
                                        <?php // With a person selected, show them on the left of each row
                                        foreach ((int)$block['id2'] === $blocks_for ? [2, 1] : [1, 2] as $i => $n): ?>
                                            <?php if ($i === 1) echo '&harr;'; ?>
                                            <?php if ($block["disabled{$n}"]): ?>
                                                <span class="text-body-secondary" title="This user is disabled" data-user-id="<?php echo (int)$block["id{$n}"]; ?>"><?php echo h($block["name{$n}"]); ?> <span class="badge text-bg-secondary">Disabled</span></span>
                                            <?php else: ?>
                                                <span data-user-id="<?php echo (int)$block["id{$n}"]; ?>"><?php echo h($block["name{$n}"]); ?></span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </span>
                                    <form action="users.php" method="POST" data-confirm="Are you sure you want to remove this block?">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="block_id" value="<?php echo (int)$block['id']; ?>">
                                        <button type="submit" name="delete_block" class="btn btn-danger btn-sm">&times;</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <p id="block_filter_count" class="small text-muted mt-2 mb-0"></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<script nonce="<?php echo csp_nonce(); ?>">
    // "Show blocks for": hides blocks that don't involve the chosen person, puts them on
    // the left of each row, and keeps the choice in the URL so it survives saving forms.
    (function () {
        const filter = document.getElementById('block_filter');
        if (!filter) return;
        const items = document.querySelectorAll('#existing_blocks li[data-users]');
        const count = document.getElementById('block_filter_count');

        function apply() {
            const id = filter.value;
            let shown = 0;
            items.forEach(function (li) {
                const ids = li.dataset.users.split(' ');
                const match = id === '' || ids.includes(id);
                li.classList.toggle('d-none', !match);
                if (!match) return;
                shown++;
                const span = li.firstElementChild;
                const names = span.querySelectorAll(':scope > [data-user-id]');
                const first = id !== '' ? id : ids[0]; // the chosen person, else the original order
                if (names[0].dataset.userId !== first) {
                    span.replaceChildren(names[1], document.createTextNode(' \u2194 '), names[0]);
                }
                names.forEach(function (n) { n.classList.toggle('fw-bold', n.dataset.userId === id); });
            });
            count.textContent = id === '' ? '' : shown + ' of ' + items.length + ' blocks shown';

            const url = new URL(window.location.href);
            if (id === '') url.searchParams.delete('blocks_for'); else url.searchParams.set('blocks_for', id);
            history.replaceState(null, '', url);
            document.querySelectorAll('form[action^="users.php"]').forEach(function (form) {
                const action = new URL(form.getAttribute('action'), window.location.href);
                if (id === '') action.searchParams.delete('blocks_for'); else action.searchParams.set('blocks_for', id);
                form.setAttribute('action', action.pathname.split('/').pop() + action.search);
            });
        }

        filter.addEventListener('change', apply);
        document.querySelectorAll('a.show-blocks').forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                filter.value = link.dataset.userId;
                apply();
                document.getElementById('existing_blocks').scrollIntoView({ behavior: 'smooth' });
            });
        });
        apply();
    })();

    // Phone input: formats as (630) 555-0123 while typing and flags invalid numbers.
    // The server re-validates and stores just the 10 digits.
    (function () {
        const input = document.getElementById('user_phone');
        if (!input) return;

        function digitsOf(value) {
            let d = value.replace(/\D/g, '');
            if (d.length === 11 && d[0] === '1') d = d.slice(1); // drop a leading country code
            return d;
        }
        function format(d) {
            if (d.length > 10) return d; // too long: leave as-is so the error is visible
            if (d.length > 6) return '(' + d.slice(0, 3) + ') ' + d.slice(3, 6) + '-' + d.slice(6);
            if (d.length > 3) return '(' + d.slice(0, 3) + ') ' + d.slice(3);
            return d;
        }
        function problem(d) {
            if (d === '') return '';
            if (d.length !== 10) return 'Enter all 10 digits (area code + number).';
            if (/^[01]/.test(d)) return 'Area codes can\'t start with 0 or 1.';
            if (/^\d{3}[01]/.test(d)) return 'The number after the area code can\'t start with 0 or 1.';
            return '';
        }
        function validate(showError) {
            const msg = problem(digitsOf(input.value));
            input.setCustomValidity(msg);
            input.nextElementSibling.textContent = msg || 'Enter a 10-digit US/Canada number.';
            input.classList.toggle('is-invalid', showError && msg !== '');
            input.classList.toggle('is-valid', showError && msg === '' && input.value !== '');
        }

        input.addEventListener('input', function () {
            // Only reformat when typing at the end, so editing mid-number doesn't jump the cursor.
            if (input.selectionStart === input.value.length) {
                input.value = format(digitsOf(input.value));
            }
            validate(input.classList.contains('is-invalid'));
        });
        input.addEventListener('blur', function () {
            input.value = format(digitsOf(input.value));
            validate(true);
        });
        input.form.addEventListener('submit', function (e) {
            validate(true);
            if (!input.checkValidity()) { e.preventDefault(); input.focus(); }
        });
        if (input.value !== '') validate(true); // e.g. re-shown after a failed save
    })();
</script>
<?php admin_footer(); ?>
