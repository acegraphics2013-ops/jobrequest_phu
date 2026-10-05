<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$currentUser = requireDesktopAuth($conn);
$userId = (int) $currentUser['id'];

try {
    $stmt = $conn->prepare("UPDATE users SET last_seen = NOW() WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();
} catch (Throwable $e) {}

jsonSuccess([
    'online' => true,
    'user_id' => $userId,
    'last_seen' => date('Y-m-d H:i:s')
]);
