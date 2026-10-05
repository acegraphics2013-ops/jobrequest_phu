<?php
// Typing + presence heartbeat for job request threads.
require_once __DIR__ . '/chat_bootstrap.php';
require_once __DIR__ . '/chat_live_state.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') chatJson(['ok' => false, 'message' => 'Invalid request method.'], 405);

$user = chatCurrentUser();
$requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT) ?: 0;
if (!chatCanAccessRequest($conn, $requestId, $user)) chatJson(['ok' => false, 'message' => 'Access denied.'], 403);

$sessionCsrf = (string) ($_SESSION['chat_csrf'] ?? '');
$submittedCsrf = (string) ($_POST['csrf_token'] ?? '');
if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $submittedCsrf)) {
    chatJson(['ok' => false, 'message' => 'Invalid security token.'], 403);
}

$isTyping = !empty($_POST['is_typing']) ? 1 : 0;

try {
    chatLiveHeartbeat(
        $conn,
        'job',
        $requestId,
        '',
        0,
        $user['id'],
        $user['name'] !== '' ? $user['name'] : ucfirst($user['role']),
        $user['role'],
        $isTyping
    );
} catch (Throwable $e) {
    chatJson(['ok' => false, 'message' => 'Server error.'], 500);
}

chatJson(['ok' => true]);
