<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$currentUser = requireDesktopAuth($conn);
$userId = (int) $currentUser['id'];

$channel = trim((string)($_GET['channel'] ?? 'general'));
$receiverId = isset($_GET['receiver_id']) && $_GET['receiver_id'] !== '' ? (int)$_GET['receiver_id'] : null;
$beforeId = isset($_GET['before_id']) && $_GET['before_id'] !== '' ? (int)$_GET['before_id'] : null;
$afterId = isset($_GET['after_id']) && $_GET['after_id'] !== '' ? (int)$_GET['after_id'] : null;
$limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 50;

$isDirect = ($receiverId !== null && $receiverId > 0);
$messages = [];

if ($isDirect) {
    // Mark DM as read
    try {
        $mStmt = $conn->prepare("UPDATE chat_messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
        $mStmt->bind_param("ii", $receiverId, $userId);
        $mStmt->execute();
        $mStmt->close();
    } catch (Throwable $e) {}

    $sql = "
        SELECT m.id, m.sender_id, m.receiver_id, m.channel, m.message, m.attachment, m.attachment_original_name, m.reply_to, m.created_at,
               u.name AS sender_name, u.role AS sender_role
        FROM chat_messages m
        JOIN users u ON u.id = m.sender_id
        WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
    ";
    $params = [$userId, $receiverId, $receiverId, $userId];
    $types = "iiii";

    if ($afterId !== null && $afterId > 0) {
        $sql .= " AND m.id > ?";
        $params[] = $afterId;
        $types .= "i";
    } elseif ($beforeId !== null && $beforeId > 0) {
        $sql .= " AND m.id < ?";
        $params[] = $beforeId;
        $types .= "i";
    }

    $sql .= " ORDER BY m.id DESC LIMIT ?";
    $params[] = $limit;
    $types .= "i";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (Throwable $e) {}
} else {
    // Channel Messages
    $sql = "
        SELECT m.id, m.sender_id, m.receiver_id, m.channel, m.message, m.attachment, m.attachment_original_name, m.reply_to, m.created_at,
               u.name AS sender_name, u.role AS sender_role
        FROM chat_messages m
        JOIN users u ON u.id = m.sender_id
        WHERE m.receiver_id IS NULL AND m.channel = ?
    ";
    $params = [$channel];
    $types = "s";

    if ($afterId !== null && $afterId > 0) {
        $sql .= " AND m.id > ?";
        $params[] = $afterId;
        $types .= "i";
    } elseif ($beforeId !== null && $beforeId > 0) {
        $sql .= " AND m.id < ?";
        $params[] = $beforeId;
        $types .= "i";
    }

    $sql .= " ORDER BY m.id DESC LIMIT ?";
    $params[] = $limit;
    $types .= "i";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (Throwable $e) {}
}

// Format attachments and reverse order (chronological for chat UI)
$formatted = [];
foreach (array_reverse($messages) as $m) {
    $att = (string) ($m['attachment'] ?? '');
    $attName = (string) ($m['attachment_original_name'] ?? '');
    $attUrl = null;
    $isImage = false;

    if ($att !== '') {
        $filename = basename($att);
        $attUrl = rtrim(BASE_URL, '/') . '/download.php?file=' . rawurlencode($filename);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    }

    $formatted[] = [
        'id' => (int) $m['id'],
        'sender_id' => (int) $m['sender_id'],
        'sender_name' => (string) $m['sender_name'],
        'sender_role' => (string) ($m['sender_role'] ?? 'staff'),
        'receiver_id' => $m['receiver_id'] !== null ? (int)$m['receiver_id'] : null,
        'channel' => (string) ($m['channel'] ?? 'general'),
        'message' => (string) $m['message'],
        'attachment_url' => $attUrl,
        'attachment_name' => $attName !== '' ? $attName : basename($att),
        'attachment_is_image' => $isImage,
        'reply_to' => $m['reply_to'] !== null ? (int)$m['reply_to'] : null,
        'created_at' => (string) $m['created_at']
    ];
}

jsonSuccess([
    'messages' => $formatted
]);
