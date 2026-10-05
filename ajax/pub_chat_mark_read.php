<?php
require_once __DIR__ . '/pub_chat_bootstrap.php';
pubChatEnsureTables($conn);
$user = pubChatCurrentUser();
$kind = trim((string) ($_POST['kind'] ?? ''));
$publicationId = filter_input(INPUT_POST, 'publication_id', FILTER_VALIDATE_INT) ?: 0;
$lastId = filter_input(INPUT_POST, 'last_message_id', FILTER_VALIDATE_INT) ?: 0;
if (!pubChatCanAccess($conn, $kind, $publicationId, $user)) pubChatJson(['ok' => false, 'message' => 'Access denied.'], 403);
$sessionCsrf = (string) ($_SESSION['chat_csrf'] ?? '');
$submittedCsrf = (string) ($_POST['csrf_token'] ?? '');
if ($sessionCsrf === '' || $submittedCsrf === '' || !hash_equals($sessionCsrf, $submittedCsrf)) {
    pubChatJson(['ok' => false, 'message' => 'Invalid security token.'], 403);
}
if ($lastId < 0) $lastId = 0;
$stmt = $conn->prepare('INSERT INTO publication_message_reads (kind, publication_id, user_id, last_read_message_id, read_at)
                        VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id)), read_at = NOW()');
$stmt->bind_param('siii', $kind, $publicationId, $user['id'], $lastId);
$stmt->execute();
$stmt->close();
pubChatJson(['ok' => true, 'marked_read' => true, 'last_read_id' => $lastId, 'kind' => $kind, 'publication_id' => $publicationId]);
