<?php

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}

$currentUser = requireApiRole(['admin', 'staff', 'supervisor']);

$input     = getJsonInput();
$requestId = (int) ($input['request_id'] ?? 0);
$newStatus = trim((string) ($input['status'] ?? ''));

if ($requestId <= 0 || empty($newStatus)) {
    jsonResponse(false, 'Request ID and new status are required.', [], 400);
}

function checkCol(mysqli $conn, string $col): bool {
    $res = $conn->query("SHOW COLUMNS FROM `job_requests` LIKE '$col'");
    return $res && $res->num_rows > 0;
}

$statusCol = checkCol($conn, 'approval_status') ? 'approval_status' : 'status';

$stmt = $conn->prepare("UPDATE job_requests SET $statusCol = ? WHERE id = ?");
if (!$stmt) {
    jsonResponse(false, 'Database prepare error.', [], 500);
}

$stmt->bind_param("si", $newStatus, $requestId);
if ($stmt->execute()) {
    $stmt->close();
    jsonResponse(true, 'Request status updated successfully', [
        'request_id' => $requestId,
        'new_status' => $newStatus
    ]);
} else {
    $stmt->close();
    jsonResponse(false, 'Failed to update request status.', [], 500);
}
