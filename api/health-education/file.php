<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/health_education_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Method not allowed', [], 405);
}

requireWhePermission($conn, 'whe_visuals');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    jsonResponse(false, 'Invalid file ID.', [], 400);
}

$stmt = $conn->prepare("SELECT original_name, stored_name, mime_type FROM ward_health_education_outputs WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$file) {
    jsonResponse(false, 'File record not found.', [], 404);
}

$storageDir = dirname(__DIR__, 2) . '/storage/ward_health_education';
$filePath = $storageDir . '/' . basename((string)$file['stored_name']);
$realPath = realpath($filePath);
$storageRoot = realpath($storageDir);

if ($realPath === false || $storageRoot === false || strpos($realPath, $storageRoot) !== 0 || !is_file($realPath)) {
    jsonResponse(false, 'File not found on storage.', [], 404);
}

$mimeType = trim((string)($file['mime_type'] ?? ''));
if (empty($mimeType)) {
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($realPath) ?: 'application/octet-stream';
}

$cleanName = str_replace(['"', "'", "\r", "\n"], '_', basename((string)$file['original_name']));

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($realPath));
header('Content-Disposition: inline; filename="' . $cleanName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');

readfile($realPath);
exit;
