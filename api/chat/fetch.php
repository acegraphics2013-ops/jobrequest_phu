<?php

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}

$currentUser = requireApiAuth();
$userId      = $currentUser['user_id'];
$role        = $currentUser['role'];
$department  = $currentUser['department'];

$requestId = (int)($_GET['request_id'] ?? 0);
if ($requestId <= 0) {
    jsonResponse(false, 'Invalid or missing request ID.', [], 400);
}

// Access check
$stmtReq = $conn->prepare("SELECT id, requestor, department FROM job_requests WHERE id = ? LIMIT 1");
if (!$stmtReq) {
    jsonResponse(false, 'Database error.', [], 500);
}
$stmtReq->bind_param("i", $requestId);
$stmtReq->execute();
$req = $stmtReq->get_result()->fetch_assoc();
$stmtReq->close();

if (!$req) {
    jsonResponse(false, 'Job request not found.', [], 404);
}

if ($role === 'user' && (int)$req['requestor'] !== $userId) {
    jsonResponse(false, 'Access denied.', [], 403);
}
if ($role === 'supervisor' && $req['department'] !== $department) {
    jsonResponse(false, 'Access denied.', [], 403);
}

// Fetch messages
$internalFilter = in_array($role, ['staff', 'supervisor', 'admin'], true) ? '' : ' AND m.is_internal = 0';
$sql = "SELECT m.id, m.request_id, m.sender_id, m.sender_name, m.sender_role, m.message,
               m.attachment, m.attachment_original_name, m.is_internal, m.reply_to, m.created_at,
               r.sender_name AS reply_sender_name, r.message AS reply_message
        FROM request_messages m
        LEFT JOIN request_messages r ON r.id = m.reply_to AND r.request_id = m.request_id
        WHERE m.request_id = ?{$internalFilter}
        ORDER BY m.id ASC LIMIT 300";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $requestId);
$stmt->execute();
$result = $stmt->get_result();

$messages = [];
$lastId = 0;
while ($row = $result->fetch_assoc()) {
    $msgId = (int)$row['id'];
    $lastId = max($lastId, $msgId);

    $messages[] = [
        'id'                       => $msgId,
        'request_id'               => (int)$row['request_id'],
        'sender_id'                => (int)$row['sender_id'],
        'sender_name'              => $row['sender_name'] ?? 'User',
        'sender_role'              => $row['sender_role'] ?? 'user',
        'message'                  => $row['message'] ?? '',
        'attachment'               => $row['attachment'] ?? null,
        'attachment_original_name' => $row['attachment_original_name'] ?? null,
        'is_internal'              => (int)($row['is_internal'] ?? 0),
        'reply_to'                 => $row['reply_to'] ? (int)$row['reply_to'] : null,
        'reply_sender_name'        => $row['reply_sender_name'] ?? null,
        'reply_message'            => $row['reply_message'] ?? null,
        'created_at'               => $row['created_at'] ?? '',
        'is_mine'                  => (int)$row['sender_id'] === $userId
    ];
}
$stmt->close();

// Mark messages as read
if ($lastId > 0) {
    $resReadTable = $conn->query("SHOW TABLES LIKE 'request_message_reads'");
    if ($resReadTable && $resReadTable->num_rows > 0) {
        $stmtRead = $conn->prepare("INSERT INTO request_message_reads (request_id, user_id, last_read_message_id, read_at)
                                    VALUES (?, ?, ?, NOW())
                                    ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id)), read_at = NOW()");
        if ($stmtRead) {
            $stmtRead->bind_param('iii', $requestId, $userId, $lastId);
            $stmtRead->execute();
            $stmtRead->close();
        }
    }
}

jsonResponse(true, 'Messages loaded successfully', [
    'messages' => $messages
]);
