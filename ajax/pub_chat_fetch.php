<?php
require_once __DIR__ . '/pub_chat_bootstrap.php';
pubChatEnsureTables($conn);
$user = pubChatCurrentUser();
$kind = trim((string) ($_GET['kind'] ?? ''));
$publicationId = filter_input(INPUT_GET, 'publication_id', FILTER_VALIDATE_INT) ?: 0;
if (!pubChatCanAccess($conn, $kind, $publicationId, $user)) pubChatJson(['ok' => false, 'message' => 'Access denied.'], 403);

$isStaff = in_array($user['role'], ['staff', 'supervisor', 'admin'], true);
$internalSql = $isStaff ? '' : ' AND m.is_internal = 0';
$sql = "SELECT m.id, m.kind, m.publication_id, m.sender_id, m.sender_name, m.sender_role, m.message,
               m.attachment, m.attachment_original_name, m.is_internal, m.reply_to, m.created_at,
               r.sender_name AS reply_sender_name, r.message AS reply_message
        FROM publication_messages m
        LEFT JOIN publication_messages r ON r.id = m.reply_to AND r.kind = m.kind AND r.publication_id = m.publication_id
        WHERE m.kind = ? AND m.publication_id = ?{$internalSql}
        ORDER BY m.id ASC LIMIT 300";
$stmt = $conn->prepare($sql);
$stmt->bind_param('si', $kind, $publicationId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$lastId = 0;
foreach ($rows as &$row) {
    $row['id'] = (int) $row['id'];
    $row['publication_id'] = (int) $row['publication_id'];
    $row['sender_id'] = (int) $row['sender_id'];
    $row['is_internal'] = (int) $row['is_internal'];
    $row['mine'] = $row['sender_id'] === $user['id'];
    $row['created_label'] = date('M d, Y g:i A', strtotime($row['created_at']));
    $row['attachment_url'] = $row['attachment'] ? uploadFileUrl('pub_chat', (string) $row['attachment']) : null;
    $lastId = max($lastId, $row['id']);
}
unset($row);

if ($lastId > 0) {
    $stmt = $conn->prepare('INSERT INTO publication_message_reads (kind, publication_id, user_id, last_read_message_id, read_at)
                            VALUES (?, ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id)), read_at = NOW()');
    $stmt->bind_param('siii', $kind, $publicationId, $user['id'], $lastId);
    $stmt->execute();
    $stmt->close();
}
pubChatJson(['ok' => true, 'messages' => $rows, 'current_user_id' => $user['id'], 'marked_read' => true, 'last_read_id' => $lastId, 'kind' => $kind, 'publication_id' => $publicationId]);
