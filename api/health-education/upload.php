<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../security/csrf.php';
require_once __DIR__ . '/../../includes/health_education_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed', [], 405);
}

$user = requireWhePermission($conn, 'whe_visuals');

if (!verifyCsrfToken()) {
    jsonResponse(false, 'Security token expired or invalid.', [], 403);
}

$sessionId = (int)($_POST['session_id'] ?? 0);
$type = trim((string)($_POST['output_type'] ?? 'Photo Documentation'));
$title = trim((string)($_POST['title'] ?? $_POST['output_title'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));

if ($sessionId <= 0) {
    jsonResponse(false, 'Activity session ID is required.', [], 400);
}

// Verify session exists
$sCheck = $conn->prepare("SELECT title FROM ward_health_education_sessions WHERE id = ? LIMIT 1");
$sCheck->bind_param("i", $sessionId);
$sCheck->execute();
$sessionRow = $sCheck->get_result()->fetch_assoc();
$sCheck->close();

if (!$sessionRow) {
    jsonResponse(false, 'Target activity session does not exist.', [], 404);
}

if (empty($_FILES['files']['name']) && empty($_FILES['supporting_files']['name'])) {
    jsonResponse(false, 'No files were selected for upload.', [], 400);
}

$fileInputKey = !empty($_FILES['files']['name']) ? 'files' : 'supporting_files';

$storageDir = dirname(__DIR__, 2) . '/storage/ward_health_education';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0775, true);
}
if (!is_file($storageDir . '/.htaccess')) {
    @file_put_contents($storageDir . '/.htaccess', "Options -Indexes\nRequire all denied\n");
}

$names = $_FILES[$fileInputKey]['name'];
$tmps = $_FILES[$fileInputKey]['tmp_name'];
$errors = $_FILES[$fileInputKey]['error'];
$sizes = $_FILES[$fileInputKey]['size'];

if (!is_array($names)) $names = [$names];
if (!is_array($tmps)) $tmps = [$tmps];
if (!is_array($errors)) $errors = [$errors];
if (!is_array($sizes)) $sizes = [$sizes];

$allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx', 'ppt', 'pptx', 'mp4', 'mov'];
$savedCount = 0;
$uploadedFiles = [];
$userId = $user['user_id'];

$stmt = $conn->prepare("INSERT INTO ward_health_education_outputs
    (session_id, uploaded_by, output_type, title, description, original_name, stored_name, extension, mime_type, file_size)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

$finfo = new finfo(FILEINFO_MIME_TYPE);

foreach ($names as $i => $rawName) {
    if (($errors[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        continue;
    }

    $originalName = basename((string)$rawName);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExt, true)) {
        continue;
    }

    $size = (int)($sizes[$i] ?? 0);
    $isVideo = in_array($ext, ['mp4', 'mov'], true);
    $limit = $isVideo ? 50 * 1024 * 1024 : 12 * 1024 * 1024;

    if ($size <= 0 || $size > $limit) {
        continue;
    }

    $tmpPath = (string)($tmps[$i] ?? '');
    if (!is_uploaded_file($tmpPath)) {
        continue;
    }

    $mime = $finfo->file($tmpPath) ?: 'application/octet-stream';
    if (stripos($mime, 'php') !== false || stripos($mime, 'javascript') !== false || stripos($mime, 'text/html') !== false) {
        continue;
    }

    $fileTitle = !empty($title) ? $title : pathinfo($originalName, PATHINFO_FILENAME);
    $storedName = bin2hex(random_bytes(18)) . '.' . $ext;
    $targetPath = $storageDir . '/' . $storedName;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        continue;
    }

    $stmt->bind_param("iisssssssi", $sessionId, $userId, $type, $fileTitle, $description, $originalName, $storedName, $ext, $mime, $size);
    if ($stmt->execute()) {
        $savedId = $stmt->insert_id;
        $savedCount++;
        $uploadedFiles[] = [
            'id' => $savedId,
            'title' => $fileTitle,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'download_url' => "/backend-api/health-education/file.php?id=$savedId"
        ];
    }
}
$stmt->close();

if ($savedCount > 0) {
    logHealthEducationAction($conn, $sessionId, $userId, 'Documentation Uploaded', "$savedCount documentation output(s) uploaded by {$user['name']}");
    $completeness = calculateDocumentationCompleteness($conn, $sessionId);

    jsonResponse(true, "$savedCount file(s) uploaded successfully.", [
        'count' => $savedCount,
        'files' => $uploadedFiles,
        'documentation_percentage' => $completeness
    ], 201);
} else {
    jsonResponse(false, 'No valid files were uploaded. Check file size limits (12MB image/doc, 50MB video) and file extensions.', [], 400);
}
