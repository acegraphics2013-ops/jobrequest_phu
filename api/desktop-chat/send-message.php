<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$currentUser = requireDesktopAuth($conn);
$userId = (int) $currentUser['id'];

$msg = trim((string) ($_POST['message'] ?? ''));
$channel = trim((string) ($_POST['channel'] ?? 'general'));
$receiverId = isset($_POST['receiver_id']) && $_POST['receiver_id'] !== '' ? (int)$_POST['receiver_id'] : null;

$hasFile = isset($_FILES['attachment']) && is_array($_FILES['attachment'])
    && (($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)
    && (string) ($_FILES['attachment']['name'] ?? '') !== '';

if ($msg === '' && !$hasFile) {
    jsonError('Message content or file attachment is required.');
}

if (mb_strlen($msg) > 3000) {
    jsonError('Message is too long. Maximum 3000 characters.');
}

$storedName = null;
$originalName = null;

if ($hasFile) {
    $file = $_FILES['attachment'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        jsonError('Attachment upload failed.');
    }
    if (($file['size'] ?? 0) > 20 * 1024 * 1024) {
        jsonError('File exceeds maximum size limit (20MB).');
    }

    $originalName = basename((string) $file['name']);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip'];

    if (!in_array($ext, $allowed, true)) {
        jsonError('File extension not allowed.');
    }

    $uploadDir = uploadsSubDir('chat', $conn);
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $storedName = 'chat_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        jsonError('Failed to save uploaded attachment.');
    }
}

try {
    $stmt = $conn->prepare("INSERT INTO chat_messages (sender_id, channel, receiver_id, message, attachment, attachment_original_name) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssss", $userId, $channel, $receiverId, $msg, $storedName, $originalName);
    $ok = $stmt->execute();
    $insertedId = $conn->insert_id;
    $stmt->close();

    $attUrl = null;
    $isImage = false;
    if ($storedName !== null) {
        $attUrl = rtrim(BASE_URL, '/') . '/download.php?file=' . rawurlencode($storedName);
        $ext = strtolower(pathinfo($storedName, PATHINFO_EXTENSION));
        $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    }

    jsonSuccess([
        'message' => [
            'id' => (int) $insertedId,
            'sender_id' => $userId,
            'sender_name' => $currentUser['name'],
            'sender_role' => $currentUser['role'],
            'receiver_id' => $receiverId,
            'channel' => $channel,
            'message' => $msg,
            'attachment_url' => $attUrl,
            'attachment_name' => $originalName !== null ? $originalName : $storedName,
            'attachment_is_image' => $isImage,
            'created_at' => date('Y-m-d H:i:s')
        ]
    ], 'Message sent successfully.');
} catch (Throwable $e) {
    jsonError('Database query failed while sending message.');
}
