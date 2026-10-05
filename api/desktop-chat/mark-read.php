<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$currentUser = requireDesktopAuth($conn);
$userId = (int) $currentUser['id'];

$input = getJsonInput();
$senderId = isset($input['sender_id']) ? (int)$input['sender_id'] : 0;

if ($senderId > 0) {
    try {
        $stmt = $conn->prepare("UPDATE chat_messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
        $stmt->bind_param("ii", $senderId, $userId);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {}
}

jsonSuccess(null, 'Messages marked as read.');
