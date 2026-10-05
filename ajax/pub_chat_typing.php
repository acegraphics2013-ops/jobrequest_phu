<?php
// Typing + presence heartbeat for publication threads.
require_once __DIR__ . '/pub_chat_bootstrap.php';
require_once __DIR__ . '/chat_live_state.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pubChatJson(['ok' => false, 'message' => 'Invalid request method.'], 405);

$user = pubChatCurrentUser();
$kind = trim((string) ($_POST['kind'] ?? ''));
$publicationId = filter_input(INPUT_POST, 'publication_id', FILTER_VALIDATE_INT) ?: 0;
if (!pubChatCanAccess($conn, $kind, $publicationId, $user)) pubChatJson(['ok' => false, 'message' => 'Access denied.'], 403);

$sessionCsrf = (string) ($_SESSION['chat_csrf'] ?? '');
$submittedCsrf = (string) ($_POST['csrf_token'] ?? '');
if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $submittedCsrf)) {
    pubChatJson(['ok' => false, 'message' => 'Invalid security token.'], 403);
}

$isTyping = !empty($_POST['is_typing']) ? 1 : 0;

try {
    chatLiveHeartbeat(
        $conn,
        'pub',
        0,
        $kind,
        $publicationId,
        $user['id'],
        $user['name'] !== '' ? $user['name'] : ucfirst($user['role']),
        $user['role'],
        $isTyping
    );
} catch (Throwable $e) {
    pubChatJson(['ok' => false, 'message' => 'Server error.'], 500);
}

pubChatJson(['ok' => true]);
