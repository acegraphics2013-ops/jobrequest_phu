<?php

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}

$currentUser = requireApiAuth();
$userId      = $currentUser['user_id'];
$userName    = $currentUser['name'];
$role        = $currentUser['role'];

$input     = getJsonInput();
$requestId = (int)($input['request_id'] ?? ($_POST['request_id'] ?? 0));
$message   = trim((string)($input['message'] ?? ($_POST['message'] ?? '')));
$isInternal= (int)($input['is_internal'] ?? ($_POST['is_internal'] ?? 0));
$replyTo   = !empty($input['reply_to']) ? (int)$input['reply_to'] : null;

if ($requestId <= 0) {
    jsonResponse(false, 'Invalid job request ID.', [], 400);
}

if (empty($message)) {
    jsonResponse(false, 'Message cannot be empty.', [], 400);
}

// Security: Non-staff users cannot post internal notes
if (!in_array($role, ['staff', 'supervisor', 'admin'], true)) {
    $isInternal = 0;
}

$stmt = $conn->prepare("INSERT INTO request_messages (request_id, sender_id, sender_name, sender_role, message, is_internal, reply_to, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
if (!$stmt) {
    jsonResponse(false, 'Database insert error.', [], 500);
}

$stmt->bind_param("iisssii", $requestId, $userId, $userName, $role, $message, $isInternal, $replyTo);
if ($stmt->execute()) {
    $insertedId = $stmt->insert_id;
    $stmt->close();

    jsonResponse(true, 'Message sent successfully', [
        'message_id' => (int)$insertedId
    ], 201);
} else {
    $stmt->close();
    jsonResponse(false, 'Failed to send message.', [], 500);
}
