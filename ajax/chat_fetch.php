<?php
require_once __DIR__ . '/chat_bootstrap.php';
$user = chatCurrentUser();
$requestId = filter_input(INPUT_GET, 'request_id', FILTER_VALIDATE_INT) ?: 0;
if (!chatCanAccessRequest($conn, $requestId, $user)) chatJson(['ok' => false, 'message' => 'Access denied.'], 403);

$internalSql = in_array($user['role'], ['staff', 'supervisor', 'admin'], true) ? '' : ' AND m.is_internal = 0';
$sql = "SELECT m.id, m.request_id, m.sender_id, m.sender_name, m.sender_role, m.message,
               m.attachment, m.attachment_original_name, m.is_internal, m.reply_to, m.created_at,
               r.sender_name AS reply_sender_name, r.message AS reply_message
        FROM request_messages m
        LEFT JOIN request_messages r ON r.id = m.reply_to AND r.request_id = m.request_id
        WHERE m.request_id = ?{$internalSql}
        ORDER BY m.id ASC LIMIT 300";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $requestId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$lastId = 0;
foreach ($rows as &$row) {
    $row['id'] = (int) $row['id'];
    $row['sender_id'] = (int) $row['sender_id'];
    $row['is_internal'] = (int) $row['is_internal'];
    $row['mine'] = $row['sender_id'] === $user['id'];
    $row['created_label'] = date('M d, Y g:i A', strtotime($row['created_at']));
    $row['attachment_url'] = $row['attachment'] ? uploadFileUrl('chat', (string) $row['attachment']) : null;
    $lastId = max($lastId, $row['id']);
}
unset($row);

if ($lastId > 0) {
    $stmt = $conn->prepare('INSERT INTO request_message_reads (request_id, user_id, last_read_message_id, read_at)
                            VALUES (?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id)), read_at = NOW()');
    $stmt->bind_param('iii', $requestId, $user['id'], $lastId);
    $stmt->execute();
    $stmt->close();
}
chatJson(['ok' => true, 'messages' => $rows, 'current_user_id' => $user['id'], 'marked_read' => true, 'last_read_id' => $lastId]);
