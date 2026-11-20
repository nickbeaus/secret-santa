<?php
/**
 * phone_texts.php
 *
 * Send each participant's text from the admin's own phone instead of VoIP.ms.
 * Each button opens the phone's Messages app with the number and message filled in;
 * tapping it also logs the text in sms_log (provider_id 'phone'), so it shows as
 * texted on the group page and "Text Everyone Not Yet Texted" skips that person.
 * Follows SMS_TEST_MODE like VoIP.ms sending: in test mode everything goes to SMS_TEST_NUMBER.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/sms.php';
require_admin();

const PHONE_PROVIDER_ID = 'phone';

$group_id = (int)($_GET['group_id'] ?? 0);
$stmt = $pdo->prepare("SELECT id, short_code, name, reveal_message, gift_idea_url, sms_template FROM `groups` WHERE id = ?");
$stmt->execute([$group_id]);
$group = $stmt->fetch();
if (!$group) {
    header('Location: admin.php?error=groupnotfound');
    exit();
}

/** Assigned participants with their latest successful text (in the current mode). */
function phone_participants($pdo, $group_id) {
    $stmt = $pdo->prepare("SELECT p.id AS participant_id, u.id AS user_id, u.name AS giver_name,
                u.phone_number AS giver_phone, u.short_code AS giver_short_code,
                (SELECT MAX(sent_at) FROM sms_log s WHERE s.group_id = p.group_id AND s.user_id = u.id AND s.success = 1 AND s.test_mode = ?) AS sent_at,
                (SELECT provider_id FROM sms_log s WHERE s.group_id = p.group_id AND s.user_id = u.id AND s.success = 1 AND s.test_mode = ? ORDER BY s.id DESC LIMIT 1) AS sent_via
            FROM participants p JOIN users u ON u.id = p.user_id
            WHERE p.group_id = ? AND p.current_giving_to_id IS NOT NULL
            ORDER BY u.name ASC");
    $mode = SMS_TEST_MODE ? 1 : 0;
    $stmt->execute([$mode, $mode, $group_id]);
    return $stmt->fetchAll();
}

// --- Mark / unmark a text as sent from the phone (called by fetch(), answers JSON) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $pid = (int)($_POST['participant_id'] ?? 0);
    $matches = array_values(array_filter(phone_participants($pdo, $group_id), fn($p) => (int)$p['participant_id'] === $pid));
    if (!$matches) {
        http_response_code(404);
        exit(json_encode(['ok' => false, 'error' => 'That participant is not in this drawing.']));
    }
    $p = $matches[0];
    $test = SMS_TEST_MODE ? 1 : 0;
    try {
        if (($_POST['action'] ?? '') === 'mark') {
            $to = $test ? SMS_TEST_NUMBER : (string)normalize_phone($p['giver_phone']);
            $pdo->prepare("INSERT INTO sms_log (group_id, user_id, phone_number, message, test_mode, success, provider_id, error) VALUES (?, ?, ?, ?, ?, 1, ?, NULL)")
                ->execute([$group_id, $p['user_id'], $to, participant_sms_message($group, $p, $test), $test, PHONE_PROVIDER_ID]);
        } elseif (($_POST['action'] ?? '') === 'unmark') {
            // Only ever removes a phone-logged entry, never a VoIP.ms one.
            $pdo->prepare("DELETE FROM sms_log WHERE id = (SELECT id FROM sms_log WHERE group_id = ? AND user_id = ? AND test_mode = ? AND provider_id = ? ORDER BY id DESC LIMIT 1)")
                ->execute([$group_id, $p['user_id'], $test, PHONE_PROVIDER_ID]);
        } else {
            http_response_code(400);
            exit(json_encode(['ok' => false, 'error' => 'Unknown action.']));
        }
    } catch (PDOException $e) {
        http_response_code(500);
        exit(json_encode(['ok' => false, 'error' => db_error_message('Could not save', $e)]));
    }
    $sent_at = phone_participants($pdo, $group_id);
    $now = array_values(array_filter($sent_at, fn($r) => (int)$r['participant_id'] === $pid))[0] ?? [];
    exit(json_encode([
        'ok'       => true,
        'sent_at'  => !empty($now['sent_at']) ? format_local($now['sent_at'], 'M j, g:i a') : null,
        'can_undo' => ($now['sent_via'] ?? null) === PHONE_PROVIDER_ID,
    ]));
}

$participants = phone_participants($pdo, $group_id);
// People not yet texted first, so working down the list is easy.
usort($participants, fn($a, $b) => (bool)$a['sent_at'] <=> (bool)$b['sent_at']);
$sent_count = count(array_filter($participants, fn($p) => $p['sent_at']));

admin_header('Text from Phone: ' . $group['name'], '', '',
    '.phone-row .btn-lg { font-size: 1.15rem; }
     .phone-row.is-sent { background: #f1f8f3; }
     .container { padding-left: 16px; padding-right: 16px; }');
?>
    <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">Text from My Phone</h1>
            <div class="text-muted"><?php echo h($group['name']); ?></div>
        </div>
        <a href="manage_group.php?group_id=<?php echo $group_id; ?>#sms" class="btn btn-sm btn-outline-secondary">Back</a>
    </div>

    <?php if (SMS_TEST_MODE): ?>
        <div class="alert alert-warning small">
            <strong>Test mode:</strong> every button texts <strong><?php echo h(format_phone(SMS_TEST_NUMBER)); ?></strong>,
            with "TEST for (name):" at the start. Set <code>SMS_TEST_MODE=false</code> in <code>.env</code> to text participants.
        </div>
    <?php endif; ?>

    <?php if (!$participants): ?>
        <div class="alert alert-info">Run the drawing first, then come back here to send the texts.</div>
    <?php else: ?>
        <p class="small text-muted mb-2">
            Open this page on your phone. Tap a button to open Messages with the text filled in, then press Send.
            Each person gets their own private link, so send them one at a time (never as a group text).
        </p>
        <p class="fw-bold mb-3"><span id="sent-count"><?php echo $sent_count; ?></span> of <?php echo count($participants); ?> marked as texted</p>

        <div class="list-group shadow-sm mb-5">
            <?php foreach ($participants as $p):
                $phone = normalize_phone($p['giver_phone']);
                $to = SMS_TEST_MODE ? SMS_TEST_NUMBER : ($phone ?: '');
                $body = participant_sms_message($group, $p, SMS_TEST_MODE);
                // Default href uses the standard "?body=" form; the script below switches to "&body=" on iPhone.
                $href = 'sms:' . ($to ? '+1' . $to : '') . '?body=' . rawurlencode($body);
                ?>
                <div class="list-group-item phone-row py-3<?php if ($p['sent_at']) echo ' is-sent'; ?>"
                     data-id="<?php echo (int)$p['participant_id']; ?>">
                    <div class="d-flex justify-content-between align-items-baseline mb-2">
                        <strong><?php echo h($p['giver_name']); ?></strong>
                        <span class="small text-muted">
                            <?php if ($phone): echo h(format_phone($phone)); ?>
                            <?php else: ?><span class="text-danger">No number saved</span><?php endif; ?>
                        </span>
                    </div>
                    <a class="btn btn-lg w-100 sms-btn <?php echo $p['sent_at'] ? 'btn-outline-success' : 'btn-success'; ?>"
                       href="<?php echo h($href); ?>"
                       data-to="<?php echo h($to); ?>" data-body="<?php echo h($body); ?>">
                        💬 <?php echo $p['sent_at'] ? 'Text again' : 'Text ' . h(preg_split('/\s+/', trim($p['giver_name']))[0]); ?>
                        <?php if (!$to): ?><span class="small">(pick contact)</span><?php endif; ?>
                    </a>
                    <div class="d-flex justify-content-between align-items-center mt-2 small status-line">
                        <span class="status-text text-muted">
                            <?php if ($p['sent_at']): ?>
                                ✓ Texted <?php echo format_local($p['sent_at'], 'M j, g:i a'); ?><?php if ($p['sent_via'] !== PHONE_PROVIDER_ID) echo ' (via VoIP.ms)'; ?>
                            <?php else: ?>Not texted yet<?php endif; ?>
                        </span>
                        <button type="button" class="btn btn-link btn-sm p-0 undo-btn"
                                <?php if (!$p['sent_at'] || $p['sent_via'] !== PHONE_PROVIDER_ID) echo 'hidden'; ?>>Undo (didn't send)</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<script nonce="<?php echo csp_nonce(); ?>">
    (function () {
        const csrf = <?php echo json_encode(csrf_token()); ?>;
        const isIOS = /iPhone|iPad|iPod/.test(navigator.userAgent) ||
            (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        const counter = document.getElementById('sent-count');

        function post(action, id) {
            const data = new FormData();
            data.append('csrf_token', csrf);
            data.append('action', action);
            data.append('participant_id', id);
            return fetch(location.href, {method: 'POST', body: data, keepalive: true, credentials: 'same-origin'})
                .then(r => r.json().catch(() => ({ok: false, error: 'Your login may have expired. Reload the page.'})));
        }

        function show(row, sentAt, error, canUndo) {
            const text = row.querySelector('.status-text');
            const undo = row.querySelector('.undo-btn');
            const btn = row.querySelector('.sms-btn');
            if (error) {
                text.textContent = '⚠ ' + error;
                text.className = 'status-text text-danger';
                return;
            }
            const wasSent = row.classList.contains('is-sent');
            row.classList.toggle('is-sent', !!sentAt);
            text.className = 'status-text text-muted';
            text.textContent = sentAt ? '✓ Texted ' + sentAt : 'Not texted yet';
            undo.hidden = !sentAt || !canUndo;
            btn.classList.toggle('btn-success', !sentAt);
            btn.classList.toggle('btn-outline-success', !!sentAt);
            if (counter && wasSent !== !!sentAt) counter.textContent = +counter.textContent + (sentAt ? 1 : -1);
        }

        document.querySelectorAll('.sms-btn').forEach(function (btn) {
            const to = btn.dataset.to ? '+1' + btn.dataset.to : '';
            btn.href = 'sms:' + to + (isIOS ? '&' : '?') + 'body=' + encodeURIComponent(btn.dataset.body);
            // Don't block the link: Messages opens while the mark is saved in the background.
            btn.addEventListener('click', function () {
                const row = btn.closest('.phone-row');
                post('mark', row.dataset.id)
                    .then(res => show(row, res.ok ? res.sent_at : null, res.ok ? null : res.error, res.can_undo))
                    .catch(() => show(row, null, 'Could not save. Check your connection and reload.'));
            });
        });

        document.querySelectorAll('.undo-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const row = btn.closest('.phone-row');
                post('unmark', row.dataset.id)
                    .then(res => show(row, res.ok ? res.sent_at : null, res.ok ? null : res.error, res.can_undo))
                    .catch(() => show(row, null, 'Could not save. Check your connection and reload.'));
            });
        });
    })();
</script>
<?php admin_footer(); ?>
