<?php
/**
 * gifts.php
 *
 * Short gift-ideas links sent in texts: /g/<code> redirects to the group's gift idea URL.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

$code = $_GET['code'] ?? '';
$url = '';
if (is_string($code) && preg_match('/^[A-Za-z0-9]{8}$/', $code)) {
    $stmt = $pdo->prepare("SELECT gift_idea_url FROM `groups` WHERE short_code = ?");
    $stmt->execute([$code]);
    $url = (string)$stmt->fetchColumn();
}

if (is_web_url($url)) {
    header('Location: ' . $url, true, 302);
    exit;
}
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gift Ideas - Secret Santa</title>
</head>
<body style="font-family: sans-serif; text-align: center; padding: 3rem 1rem;">
    <h1>🎁</h1>
    <p>This gift ideas link isn't available. Please check with your group's organizer.</p>
</body>
</html>
