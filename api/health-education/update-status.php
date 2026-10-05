<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../security/csrf.php';
require_once __DIR__ . '/../../includes/health_education_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed', [], 405);
}

$user = requireWhePermission($conn, 'whe_sessions');

if (!verifyCsrfToken()) {
    jsonResponse(false, 'Security token expired or invalid.', [], 403);
}

$input = getJsonInput();
$id = (int)($input['id'] ?? $input['session_id'] ?? 0);
$newStatus = trim((string)($input['status'] ?? ''));
$notes = trim((string)($input['notes'] ?? ''));

if ($id <= 0 || empty($newStatus)) {
    jsonResponse(false, 'Activity ID and new status are required.', [], 400);
}

// Fetch session
$stmt = $conn->prepare("SELECT * FROM ward_health_education_sessions WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$session) {
    jsonResponse(false, 'Activity not found.', [], 404);
}

$currentStatus = $session['status'];
$userId = $user['user_id'];
$userRole = $user['role'];

// Validate status transition
if (!validateWheStatusTransition($currentStatus, $newStatus, $userRole)) {
    jsonResponse(false, "Transition from '$currentStatus' to '$newStatus' is not allowed for your role.", [], 403);
}

// Check documentation completeness before approval if requested
if ($newStatus === 'Approved' || $newStatus === 'For Publication' || $newStatus === 'Published') {
    $pct = calculateDocumentationCompleteness($conn, $id);
    if ($pct < 70 && empty($input['override_completeness'])) {
        jsonResponse(false, "Documentation completeness is only $pct%. Please complete required photo documentation and details before approval.", [], 422);
    }
}

// Prepare status update fields
$now = date('Y-m-d H:i:s');
$updateFields = ["status = ?"];
$bindTypes = "s";
$bindParams = [$newStatus];

if ($newStatus === 'For Review' || $newStatus === 'For Revision') {
    $updateFields[] = "reviewed_by = ?";
    $updateFields[] = "reviewed_at = ?";
    $bindTypes .= "is";
    $bindParams[] = $userId;
    $bindParams[] = $now;
}

if ($newStatus === 'Approved' || $newStatus === 'Published') {
    $updateFields[] = "approved_by = ?";
    $updateFields[] = "approved_at = ?";
    $bindTypes .= "is";
    $bindParams[] = $userId;
    $bindParams[] = $now;
}

$updateFields[] = "updated_at = ?";
$bindTypes .= "s";
$bindParams[] = $now;

$bindTypes .= "i";
$bindParams[] = $id;

$sql = "UPDATE ward_health_education_sessions SET " . implode(', ', $updateFields) . " WHERE id = ?";
$upStmt = $conn->prepare($sql);
$upStmt->bind_param($bindTypes, ...$bindParams);
if (!$upStmt->execute()) {
    $upStmt->close();
    jsonResponse(false, 'Failed to update activity status.', [], 500);
}
$upStmt->close();

// Log timeline entry
$logMsg = "Status changed from '$currentStatus' to '$newStatus' by {$user['name']}";
if (!empty($notes)) {
    $logMsg .= " (Notes: $notes)";
}
logHealthEducationAction($conn, $id, $userId, 'Status Changed', $logMsg);

// Send notification to submitter
$submitterId = (int)$session['submitted_by'];
if ($submitterId !== $userId) {
    sendWheNotification(
        $conn,
        $submitterId,
        "Your Health Education Activity '{$session['title']}' status was updated to '$newStatus'.",
        'info',
        "/health-education/activities/$id"
    );
}

jsonResponse(true, "Activity status updated to '$newStatus' successfully!", [
    'id' => $id,
    'old_status' => $currentStatus,
    'new_status' => $newStatus
]);
