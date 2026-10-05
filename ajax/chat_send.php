<?php
require_once __DIR__ . '/chat_bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') chatJson(['ok' => false, 'message' => 'Invalid request method.'], 405);
$user = chatCurrentUser();
$requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT) ?: 0;
if (!chatCanAccessRequest($conn, $requestId, $user)) chatJson(['ok' => false, 'message' => 'Access denied.'], 403);

// CSRF validation (token rendered by request_chat_box.php).
$sessionCsrf = (string) ($_SESSION['chat_csrf'] ?? '');
$submittedCsrf = (string) ($_POST['csrf_token'] ?? '');
if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $submittedCsrf)) {
    chatJson(['ok' => false, 'message' => 'Invalid security token. Please refresh and try again.'], 403);
}

$message = trim((string) ($_POST['message'] ?? ''));
$replyTo = filter_input(INPUT_POST, 'reply_to', FILTER_VALIDATE_INT) ?: null;
// Prevent cross-request reply leak: reply_to must belong to same request_id.
if ($replyTo !== null) {
    $chk = $conn->prepare('SELECT id FROM request_messages WHERE id = ? AND request_id = ? LIMIT 1');
    $chk->bind_param('ii', $replyTo, $requestId);
    $chk->execute();
    $ok = (bool) $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$ok) {
        chatJson(['ok' => false, 'message' => 'Invalid reply reference.'], 422);
    }
}
$isInternal = !empty($_POST['is_internal']) && in_array($user['role'], ['staff', 'supervisor', 'admin'], true) ? 1 : 0;
if ($message === '' && empty($_FILES['attachment']['name'])) chatJson(['ok' => false, 'message' => 'Write a message or attach a file.'], 422);
if (mb_strlen($message) > 3000) chatJson(['ok' => false, 'message' => 'Message is too long.'], 422);

$storedName = null;
$originalName = null;
if (!empty($_FILES['attachment']['name'])) {
    $file = $_FILES['attachment'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) chatJson(['ok' => false, 'message' => 'Attachment upload failed.'], 422);
    if (($file['size'] ?? 0) > 10 * 1024 * 1024) chatJson(['ok' => false, 'message' => 'Attachment must not exceed 10 MB.'], 422);
    $allowed = ['jpg','jpeg','png','gif','pdf','doc','docx','xls','xlsx','zip'];
    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed, true)) chatJson(['ok' => false, 'message' => 'Unsupported attachment type.'], 422);
    // MIME verification to block polyglot / spoofed uploads (e.g. HTML disguised as .jpg).
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($file['tmp_name']);
    $mimeAllow = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'zip' => ['application/zip', 'application/x-zip-compressed', 'multipart/x-zip', 'application/octet-stream'],
    ];
    if (!in_array($mime, $mimeAllow[$extension] ?? [], true)) {
        chatJson(['ok' => false, 'message' => 'Attachment content does not match its file type.'], 422);
    }
    if (stripos($mime, 'text/html') !== false) {
        chatJson(['ok' => false, 'message' => 'Unsupported attachment content.'], 422);
    }
    $originalName = chatSafeName((string) $file['name']);
    $storedName = $requestId . '_' . bin2hex(random_bytes(12)) . '.' . $extension;
    $uploadDir = uploadsSubDir('chat');
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) chatJson(['ok' => false, 'message' => 'Upload folder could not be created.'], 500);
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $storedName)) chatJson(['ok' => false, 'message' => 'Could not save attachment.'], 500);
}

$senderName = $user['name'] !== '' ? $user['name'] : ucfirst($user['role']);
$stmt = $conn->prepare('INSERT INTO request_messages
    (request_id, sender_id, sender_name, sender_role, message, attachment, attachment_original_name, is_internal, reply_to, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
$stmt->bind_param('iisssssii', $requestId, $user['id'], $senderName, $user['role'], $message, $storedName, $originalName, $isInternal, $replyTo);
$stmt->execute();
$messageId = (int) $stmt->insert_id;
$stmt->close();

// Optional audit log: only runs when the compatible table exists.
try {
    $check = $conn->query("SHOW TABLES LIKE 'request_activity_logs'");
    if ($check && $check->num_rows > 0) {
        $title = $isInternal ? 'HPU internal note added' : 'New communication message';
        $description = $isInternal ? 'An internal staff note was added.' : ($user['role'] === 'user' ? 'The requestor sent a message.' : 'HPU staff sent a reply.');
        $actionType = $isInternal ? 'internal_note' : 'message';
        $log = $conn->prepare('INSERT INTO request_activity_logs (request_id, user_id, action_type, action_title, description, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        if ($log) {
            $log->bind_param('iisss', $requestId, $user['id'], $actionType, $title, $description);
            $log->execute();
            $log->close();
        }
    }
} catch (Throwable $ignored) {}
chatJson(['ok' => true, 'message' => 'Message sent.', 'message_id' => $messageId]);
