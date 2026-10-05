<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/module_access.php';
require_once __DIR__ . '/health_education_db.php';

/**
 * Ensures database schema is up-to-date
 */
ensureHealthEducationSchema($conn);

/**
 * Health Education Module Permission Verification
 */
function requireWhePermission(mysqli $conn, string $moduleKey): array
{
    if (empty($_SESSION['user_id'])) {
        jsonResponse(false, 'Session expired. Please sign in again.', [], 401);
    }

    $userId = (int)$_SESSION['user_id'];
    $role = (string)($_SESSION['role'] ?? 'user');

    if (!canAccessModule($conn, $userId, $role, 'ward_health_education')) {
        jsonResponse(false, 'You do not have access to the Ward Health Education module.', [], 403);
    }

    if ($moduleKey !== '' && !canAccessModule($conn, $userId, $role, $moduleKey)) {
        jsonResponse(false, "You do not have permission for $moduleKey.", [], 403);
    }

    return [
        'user_id' => $userId,
        'role'    => $role,
        'name'    => (string)($_SESSION['name'] ?? ''),
        'department' => (string)($_SESSION['department'] ?? '')
    ];
}

/**
 * Returns array of user's permissions for all Health Education submodules
 */
function getWhePermissions(mysqli $conn, int $userId, string $role): array
{
    return [
        'ward_health_education' => canAccessModule($conn, $userId, $role, 'ward_health_education'),
        'whe_overview' => canAccessModule($conn, $userId, $role, 'whe_overview'),
        'whe_new'      => canAccessModule($conn, $userId, $role, 'whe_new'),
        'whe_sessions' => canAccessModule($conn, $userId, $role, 'whe_sessions'),
        'whe_visuals'  => canAccessModule($conn, $userId, $role, 'whe_visuals'),
        'whe_reports'  => canAccessModule($conn, $userId, $role, 'whe_reports'),
    ];
}

/**
 * Validates allowed status transition for Health Education Activity workflow
 */
function validateWheStatusTransition(string $currentStatus, string $newStatus, string $role): bool
{
    if ($currentStatus === $newStatus) {
        return true;
    }

    $allowedTransitions = [
        'Draft' => ['Scheduled', 'Conducted', 'For Documentation', 'For Review'],
        'Scheduled' => ['Conducted', 'For Documentation', 'Draft'],
        'Conducted' => ['For Documentation', 'For Review'],
        'For Documentation' => ['For Review', 'Conducted'],
        'For Review' => ['Approved', 'For Revision', 'For Documentation'],
        'For Revision' => ['For Review', 'For Documentation'],
        'Approved' => ['For Publication', 'Published', 'Archived', 'For Review'],
        'For Publication' => ['Published', 'Approved', 'Archived'],
        'Published' => ['Archived'],
        'Archived' => []
    ];

    $valid = $allowedTransitions[$currentStatus] ?? [];
    if (!in_array($newStatus, $valid, true)) {
        return false;
    }

    // Role restrictions for approving / archiving
    $isStaffOrAbove = in_array($role, ['staff', 'supervisor', 'admin'], true);
    $isManagerOrAbove = in_array($role, ['supervisor', 'admin'], true);

    if (in_array($newStatus, ['Approved', 'For Publication', 'Published'], true) && !$isStaffOrAbove) {
        return false;
    }

    if ($newStatus === 'Archived' && !$isManagerOrAbove) {
        return false;
    }

    return true;
}

/**
 * Helper to dispatch notification in existing notification table if present
 */
function sendWheNotification(mysqli $conn, int $recipientId, string $message, string $type = 'info', ?string $actionUrl = null): void
{
    try {
        $check = $conn->query("SHOW TABLES LIKE 'notifications'");
        if ($check && $check->num_rows > 0) {
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, notification_type, action_url) VALUES (?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("isss", $recipientId, $message, $type, $actionUrl);
                $stmt->execute();
                $stmt->close();
            }
        }
    } catch (Throwable $e) {
        // Silently swallow notification dispatch errors
    }
}
