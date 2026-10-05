<?php
require_once __DIR__ . '/chat_bootstrap.php';
$user = chatCurrentUser();
$requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT) ?: 0;
$lastId = filter_input(INPUT_POST, 'last_message_id', FILTER_VALIDATE_INT) ?: 0;
if (!chatCanAccessRequest($conn, $requestId, $user)) chatJson(['ok' => false, 'message' => 'Access denied.'], 403);
$sessionCsrf = (string) ($_SESSION['chat_csrf'] ?? '');
$submittedCsrf = (string) ($_POST['csrf_token'] ?? '');
if ($sessionCsrf === '' || $submittedCsrf === '' || !hash_equals($sessionCsrf, $submittedCsrf)) {
    chatJson(['ok' => false, 'message' => 'Invalid security token.'], 403);
}
if ($lastId < 0) $lastId = 0;
$stmt = $conn->prepare('INSERT INTO request_message_reads (request_id, user_id, last_read_message_id, read_at)
                        VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id)), read_at = NOW()');
$stmt->bind_param('iii', $requestId, $user['id'], $lastId);
$stmt->execute();
$stmt->close();
chatJson(['ok' => true, 'marked_read' => true, 'last_read_id' => $lastId]);
