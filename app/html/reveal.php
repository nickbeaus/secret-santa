<?php
/**
 * reveal.php
 *
 * This is the public-facing page where a participant can see who they
 * are the Secret Santa for. It tracks every view in the participant_views table.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

const UUID_PATTERN = '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i';

// --- Handle AJAX Request for View Tracking ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'track_view') {
    header('Content-Type: application/json');

    $uuid = is_string($_POST['uuid'] ?? null) ? $_POST['uuid'] : '';

    if (preg_match(UUID_PATTERN, $uuid)) {
        try {
            // Find the participant record based on the UUID
            $sql = "SELECT p1.id AS participant_id
                    FROM users AS u1
                    JOIN participants AS p1 ON u1.id = p1.user_id
                    JOIN `groups` AS g ON p1.group_id = g.id
                    WHERE u1.uuid = ?
                    ORDER BY g.creation_date DESC
                    LIMIT 1";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$uuid]);
            $participant = $stmt->fetch();

            if ($participant) {
                // INSERT a new record for every view
                // We capture the visitor's IP address (not the proxy's) for auditing
                $ip_address = client_ip() ?: null;

                $insert_stmt = $pdo->prepare("INSERT INTO participant_views (participant_id, view_timestamp, ip_address) VALUES (?, CURRENT_TIMESTAMP, ?)");
                $insert_stmt->execute([$participant['participant_id'], $ip_address]);

                echo json_encode(['status' => 'success', 'message' => 'View logged']);
            } else {
                echo json_encode(['status' => 'ignored', 'message' => 'Participant not found']);
            }
        } catch (Exception $e) {
            error_log('Secret Santa: view tracking failed: ' . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid UUID']);
    }
    exit;
}

// --- Standard Page Load Logic (GET Request) ---

function format_message_with_links($message) {
    $safe_message = h($message);
    $url_pattern = '/(https?:\/\/[^\s<]+|www\.[^\s<]+)/';
    $linked_message = preg_replace_callback($url_pattern, function($matches) {
        $url = $matches[0];
        if (strpos($url, 'http') !== 0) $url = 'https://' . $url;
        return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $matches[0] . '</a>';
    }, $safe_message);
    return nl2br($linked_message);
}

// --- Initial Setup ---
$uuid = is_string($_GET['uuid'] ?? null) ? $_GET['uuid'] : '';

// Short links (/r/<code>) map to the user's full UUID.
$code = $_GET['code'] ?? null;
if ($uuid === '' && is_string($code) && preg_match('/^[A-Za-z0-9]{8}$/', $code)) {
    $code_stmt = $pdo->prepare("SELECT uuid FROM users WHERE short_code = ?");
    $code_stmt->execute([$code]);
    $uuid = (string)$code_stmt->fetchColumn();
}
$error_message = '';
$participant_info = null;
$giving_to = null;
$assignment_history = [];
$user_id = null;

if (!preg_match(UUID_PATTERN, $uuid)) {
    $error_message = "The link you followed seems to be invalid. Please check the URL and try again.";
} else {
    try {
        $sql = "SELECT 
                    u1.id AS user_id,
                    u1.name AS giver_name,
                    p1.id AS participant_id,
                    p1.current_giving_to_id,
                    g.id AS group_id,
                    g.name AS group_name,
                    g.reveal_message,
                    g.gift_idea_url
                FROM users AS u1
                JOIN participants AS p1 ON u1.id = p1.user_id
                JOIN `groups` AS g ON p1.group_id = g.id
                WHERE u1.uuid = ?
                ORDER BY g.creation_date DESC
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$uuid]);
        $participant_info = $stmt->fetch();

        if ($participant_info) {
            $user_id = $participant_info['user_id'];

            if (!empty($participant_info['current_giving_to_id'])) {
                $receiver_sql = "SELECT u.name FROM participants p JOIN users u ON p.user_id = u.id WHERE p.id = ?";
                $stmt2 = $pdo->prepare($receiver_sql);
                $stmt2->execute([$participant_info['current_giving_to_id']]);
                $giving_to = $stmt2->fetch();
            } else {
                $error_message = "The Secret Santa drawing hasn't happened yet for your most recent group! Please check back later.";
            }

            // Fetch history excluding current group
            $history_sql = "
                SELECT
                    g.name AS group_name,
                    g.reveal_message,
                    g.gift_idea_url,
                    history_receiver.name AS receiver_name,
                    ah.year
                FROM assignment_history AS ah
                JOIN participants AS history_giver_part ON ah.giver_participant_id = history_giver_part.id
                JOIN participants AS history_receiver_part ON ah.receiver_participant_id = history_receiver_part.id
                JOIN users AS history_receiver ON history_receiver_part.user_id = history_receiver.id
                JOIN `groups` AS g ON ah.group_id = g.id
                WHERE history_giver_part.user_id = ? AND ah.group_id != ?
                ORDER BY ah.year DESC, g.name ASC
            ";
            $history_stmt = $pdo->prepare($history_sql);
            $history_stmt->execute([$user_id, $participant_info['group_id']]);
            $assignment_history = $history_stmt->fetchAll();

        } else {
            $error_message = "We couldn't find you in any group. Please make sure you used the correct link.";
        }
    } catch (PDOException $e) {
        error_log('Secret Santa: reveal page error: ' . $e->getMessage());
        $error_message = "A technical error occurred. Please contact the administrator.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Secret Santa Assignment!</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link href="https://fonts.googleapis.com/css2?family=Mountains+of+Christmas:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body {
            background-color: #d9534f;
            background-image: linear-gradient(315deg, #d9534f 0%, #c12a2a 74%);
            padding: 2rem 1rem;
        }
        .main-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .reveal-card {
            max-width: 500px;
            width: 100%;
            border: none;
            border-radius: 1rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            text-align: center;
            padding: 2rem 1.5rem;
            background-color: white;
            margin-bottom: 2rem;
        }
        .card-title {
            font-family: 'Mountains of Christmas', cursive;
            font-weight: 700;
            font-size: 2.5rem;
            color: #333;
        }
        .recipient-name {
            font-family: 'Mountains of Christmas', cursive;
            font-weight: 700;
            font-size: 4rem;
            color: #d9534f;
            padding: 1.5rem 0;
            margin: 1.5rem 0;
            border-top: 2px dashed #eee;
            border-bottom: 2px dashed #eee;
            word-wrap: break-word;
            transition: opacity 0.5s ease-in-out;
        }
        .gift-icon { font-size: 4rem; color: #d9534f; }
        #reveal-button { padding: 0.75rem 2rem; font-size: 1.25rem; font-weight: bold; }
        .history-result { font-size: 1.5rem; font-weight: bold; color: #d9534f; }
        .group-message { border-left: 4px solid #d9534f; }
    </style>
</head>
<body>

<div class="main-container">
    <div class="reveal-card">
        <?php if ($error_message && !$participant_info): ?>
            <div class="gift-icon mb-3">😟</div>
            <h1 class="card-title">Oh no!</h1>
            <p class="lead text-muted"><?php echo h($error_message); ?></p>
        <?php elseif ($participant_info): ?>
            <div class="gift-icon mb-3">🎁</div>
            <p class="lead text-muted mb-2">Welcome to the <strong><?php echo h($participant_info['group_name']); ?></strong> exchange!</p>
            <h1 class="card-title">Hi, <?php echo h($participant_info['giver_name']); ?>!</h1>

            <?php if (!empty($participant_info['reveal_message'])): ?>
                <div class="group-message alert alert-light text-start p-3 my-4">
                    <?php echo format_message_with_links($participant_info['reveal_message']); ?>
                </div>
            <?php endif; ?>

            <?php if ($giving_to): ?>
                <p>You are the Secret Santa for...</p>
                <div id="reveal-container" class="my-4">
                    <button id="reveal-button" class="btn btn-danger btn-lg shadow">Click to Reveal!</button>
                    <div id="recipient-name-container" class="recipient-name" style="display: none; opacity: 0;"><?php echo h($giving_to['name']); ?></div>
                </div>

                <?php if (is_web_url($participant_info['gift_idea_url'] ?? '')): ?>
                    <a href="<?php echo h($participant_info['gift_idea_url']); ?>" class="btn btn-success" target="_blank" rel="noopener noreferrer">View Group Gift Ideas</a>
                <?php endif; ?>

                <p id="final-message" class="text-muted mt-3" style="display: none;">Happy gifting! Remember to keep it a secret. 😉</p>
            <?php else: ?>
                <p class="lead text-muted mt-4"><?php echo h($error_message); ?></p>
            <?php endif; ?>
        <?php else: ?>
            <div class="gift-icon mb-3">🤔</div>
            <h1 class="card-title">Something's not right...</h1>
            <p class="lead text-muted">Please check the link and try again.</p>
        <?php endif; ?>
    </div>

    <?php if (!empty($assignment_history)): ?>
        <div class="reveal-card" id="history-card">
            <h4 class="card-title">Assignment History</h4>
            <p class="text-muted">Curious who you had in past years? Select a group to see your pick.</p>
            <div class="mt-3">
                <select id="history-select" class="form-select">
                    <option value="">Select a past group...</option>
                    <?php foreach ($assignment_history as $index => $history): ?>
                        <option value="<?php echo $index; ?>"><?php echo h($history['group_name']); ?> (<?php echo (int)$history['year']; ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php foreach ($assignment_history as $index => $history): ?>
                <div class="history-result-container mt-4" id="history-<?php echo $index; ?>" hidden>
                    <?php if (!empty($history['reveal_message'])): ?>
                        <div class="group-message alert alert-light text-start p-3 mb-3"><?php echo format_message_with_links($history['reveal_message']); ?></div>
                    <?php endif; ?>
                    <p class="mb-1">Your pick was:</p>
                    <p class="history-result"><?php echo h($history['receiver_name']); ?></p>
                    <?php if (is_web_url($history['gift_idea_url'] ?? '')): ?>
                        <div class="mt-3"><a href="<?php echo h($history['gift_idea_url']); ?>" class="btn btn-sm btn-info" target="_blank" rel="noopener noreferrer">View Gift Ideas for this Year</a></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($participant_info && $giving_to): ?>
    <script nonce="<?php echo csp_nonce(); ?>">
        document.addEventListener('DOMContentLoaded', function() {
            const revealButton = document.getElementById('reveal-button');
            if (revealButton) {
                revealButton.addEventListener('click', function() {
                    const recipientContainer = document.getElementById('recipient-name-container');
                    const finalMessage = document.getElementById('final-message');

                    // UI changes
                    this.style.display = 'none';
                    recipientContainer.style.display = 'block';
                    setTimeout(() => { recipientContainer.style.opacity = 1; }, 50);
                    finalMessage.style.display = 'block';

                    // --- AJAX to Track View ---
                    const formData = new FormData();
                    formData.append('action', 'track_view');
                    formData.append('uuid', <?php echo json_encode($uuid); ?>);

                    fetch('/reveal.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.json())
                        .then(data => {
                            console.log('View recorded:', data);
                        })
                        .catch(error => {
                            console.error('Error tracking view:', error);
                        });
                });
            }
        });
    </script>
<?php endif; ?>

<?php if (!empty($assignment_history)): ?>
    <script nonce="<?php echo csp_nonce(); ?>">
        document.addEventListener('DOMContentLoaded', function() {
            const historySelect = document.getElementById('history-select');
            if (historySelect) {
                historySelect.addEventListener('change', function() {
                    // Each past group's result is rendered (and escaped) by the server; just show the chosen one.
                    document.querySelectorAll('.history-result-container').forEach(el => { el.hidden = true; });
                    if (this.value !== "") {
                        document.getElementById('history-' + this.value).hidden = false;
                    }
                });
            }
        });
    </script>
<?php endif; ?>

</body>
</html>