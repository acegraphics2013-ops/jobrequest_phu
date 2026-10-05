<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../security/csrf.php';
require_once __DIR__ . '/../../includes/health_education_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    jsonResponse(false, 'Method not allowed', [], 405);
}

$user = requireWhePermission($conn, 'whe_visuals');

if (!verifyCsrfToken()) {
    jsonResponse(false, 'Security token expired or invalid.', [], 403);
}

$input = getJsonInput();
$id = (int)($input['id'] ?? $input['output_id'] ?? 0);

if ($id <= 0) {
    jsonResponse(false, 'Output ID is required.', [], 400);
}

// Fetch file record
$stmt = $conn->prepare("SELECT o.*, s.submitted_by AS session_owner 
    FROM ward_health_education_outputs o 
    JOIN ward_health_education_sessions s ON s.id = o.session_id 
    WHERE o.id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$file) {
    jsonResponse(false, 'Output file not found.', [], 404);
}

$userId = $user['user_id'];
$userRole = $user['role'];
$isOwner = ((int)$file['uploaded_by'] === $userId || (int)$file['session_owner'] === $userId);
$isStaff = in_array($userRole, ['staff', 'supervisor', 'admin'], true);

if (!$isStaff && !$isOwner) {
    jsonResponse(false, 'You do not have permission to delete this file.', [], 403);
}

$storageDir = dirname(__DIR__, 2) . '/storage/ward_health_education';
$filePath = $storageDir . '/' . basename((string)$file['stored_name']);
$real = realpath($filePath);
$root = realpath($storageDir);

if ($real !== false && $root !== false && strpos($real, $root) === 0 && is_file($real)) {
    @unlink($real);
}

// Delete DB record
$dStmt = $conn->prepare("DELETE FROM ward_health_education_outputs WHERE id = ?");
$dStmt->bind_param("i", $id);
$dStmt->execute();
$dStmt->close();

$sessionId = (int)$file['session_id'];
logHealthEducationAction($conn, $sessionId, $userId, 'Documentation Deleted', "Deleted file '{$file['title']}' ({$file['original_name']})");

$completeness = calculateDocumentationCompleteness($conn, $sessionId);

jsonResponse(true, 'File deleted successfully.', [
    'id' => $id,
    'session_id' => $sessionId,
    'documentation_percentage' => $completeness
]);
