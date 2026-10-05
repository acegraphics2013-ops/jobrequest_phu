<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$currentUser = requireDesktopAuth($conn);
$userId = (int) $currentUser['id'];
$userRole = (string) $currentUser['role'];

$photoSelect = chatHasPhotoColumn($conn) ? "u.photo," : "NULL AS photo,";
$visibleHpuRoles = ($userRole === "user") ? "('staff')" : "('staff', 'supervisor', 'admin')";

$teamMembers = [];
$unreadMap = [];
$onlineUserIds = [];
$dmMap = [];

try {
    $stmt = $conn->prepare("
        SELECT DISTINCT u.id, u.name, u.email, u.role, u.department, u.status, {$photoSelect}
               (u.last_seen IS NOT NULL AND u.last_seen >= NOW() - INTERVAL 3 MINUTE) AS is_online,
               (SELECT c2.message
                FROM chat_messages c2
                WHERE (c2.sender_id = ? AND c2.receiver_id = u.id)
                   OR (c2.sender_id = u.id AND c2.receiver_id = ?)
                ORDER BY c2.id DESC LIMIT 1) AS last_chat_text,
               (SELECT c2.created_at
                FROM chat_messages c2
                WHERE (c2.sender_id = ? AND c2.receiver_id = u.id)
                   OR (c2.sender_id = u.id AND c2.receiver_id = ?)
                ORDER BY c2.id DESC LIMIT 1) AS last_chat_time
        FROM users u
        WHERE u.status = 'Active'
          AND (u.role IN {$visibleHpuRoles})
        ORDER BY last_chat_time DESC, u.name ASC
    ");
    $stmt->bind_param("iiiiiiii", $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($res as $tm) {
        $uid = (int) $tm['id'];
        $isOnline = (bool) $tm['is_online'];
        if ($isOnline) {
            $onlineUserIds[] = $uid;
        }

        $photo = basename((string)($tm['photo'] ?? ''));
        $photoUrl = $photo !== '' ? rtrim(BASE_URL, '/') . '/download.php?scope=avatars&file=' . rawurlencode($photo) : null;

        $teamMembers[] = [
            'id' => $uid,
            'name' => (string) $tm['name'],
            'email' => (string) $tm['email'],
            'role' => (string) $tm['role'],
            'department' => (string) ($tm['department'] ?? ''),
            'is_online' => $isOnline,
            'photo_url' => $photoUrl,
            'last_message' => (string) ($tm['last_chat_text'] ?? ''),
            'last_message_time' => (string) ($tm['last_chat_time'] ?? '')
        ];
    }
} catch (Throwable $e) {}

// Calculate unread counts per contact
try {
    $uStmt = $conn->prepare("
        SELECT sender_id, COUNT(*) AS cnt
        FROM chat_messages
        WHERE receiver_id = ? AND is_read = 0
        GROUP BY sender_id
    ");
    $uStmt->bind_param("i", $userId);
    $uStmt->execute();
    $uRes = $uStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $uStmt->close();

    foreach ($uRes as $row) {
        $unreadMap[(int)$row['sender_id']] = (int)$row['cnt'];
    }
} catch (Throwable $e) {}

// Team Channels
$channels = [
    [
        'key' => 'general',
        'name' => 'General Channel',
        'desc' => 'Team-wide discussion',
        'unread' => 0
    ],
    [
        'key' => 'announcements',
        'name' => 'Announcements',
        'desc' => 'Broadcast updates',
        'unread' => 0
    ]
];

$totalUnread = array_sum($unreadMap);

jsonSuccess([
    'channels' => $channels,
    'team_members' => $teamMembers,
    'online_user_ids' => $onlineUserIds,
    'unread_counts' => $unreadMap,
    'total_unread' => $totalUnread
]);
