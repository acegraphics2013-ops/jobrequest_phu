<?php
require_once __DIR__ . '/pub_chat_bootstrap.php';
pubChatEnsureTables($conn);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') pubChatJson(['ok' => false, 'message' => 'Invalid request method.'], 405);
$user = pubChatCurrentUser();
$kind = trim((string) ($_POST['kind'] ?? ''));
$publicationId = filter_input(INPUT_POST, 'publication_id', FILTER_VALIDATE_INT) ?: 0;
if (!pubChatCanAccess($conn, $kind, $publicationId, $user)) pubChatJson(['ok' => false, 'message' => 'Access denied.'], 403);

// CSRF validation (token rendered by publication_chat_box.php, shared with job chat).
$sessionCsrf = (string) ($_SESSION['chat_csrf'] ?? '');
$submittedCsrf = (string) ($_POST['csrf_token'] ?? '');
if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $submittedCsrf)) {
    pubChatJson(['ok' => false, 'message' => 'Invalid security token. Please refresh and try again.'], 403);
}

$message = trim((string) ($_POST['message'] ?? ''));
$replyTo = filter_input(INPUT_POST, 'reply_to', FILTER_VALIDATE_INT) ?: null;
// Prevent cross-publication reply leak.
if ($replyTo !== null) {
    $chk = $conn->prepare('SELECT id FROM publication_messages WHERE id = ? AND kind = ? AND publication_id = ? LIMIT 1');
    $chk->bind_param('isi', $replyTo, $kind, $publicationId);
    $chk->execute();
    $ok = (bool) $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$ok) {
        pubChatJson(['ok' => false, 'message' => 'Invalid reply reference.'], 422);
    }
}
$isInternal = !empty($_POST['is_internal']) && in_array($user['role'], ['staff', 'supervisor', 'admin'], true) ? 1 : 0;
if ($message === '' && empty($_FILES['attachment']['name'])) pubChatJson(['ok' => false, 'message' => 'Write a message or attach a file.'], 422);
if (mb_strlen($message) > 3000) pubChatJson(['ok' => false, 'message' => 'Message is too long.'], 422);

$storedName = null;
$originalName = null;
if (!empty($_FILES['attachment']['name'])) {
    $file = $_FILES['attachment'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) pubChatJson(['ok' => false, 'message' => 'Attachment upload failed.'], 422);
    if (($file['size'] ?? 0) > 10 * 1024 * 1024) pubChatJson(['ok' => false, 'message' => 'Attachment must not exceed 10 MB.'], 422);
    $allowed = ['jpg','jpeg','png','gif','pdf','doc','docx','xls','xlsx','zip'];
    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed, true)) pubChatJson(['ok' => false, 'message' => 'Unsupported attachment type.'], 422);
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
        pubChatJson(['ok' => false, 'message' => 'Attachment content does not match its file type.'], 422);
    }
    if (stripos($mime, 'text/html') !== false) {
        pubChatJson(['ok' => false, 'message' => 'Unsupported attachment content.'], 422);
    }
    $originalName = pubChatSafeName((string) $file['name']);
    $storedName = $kind . '_' . $publicationId . '_' . bin2hex(random_bytes(12)) . '.' . $extension;
    $uploadDir = uploadsSubDir('pub_chat');
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) pubChatJson(['ok' => false, 'message' => 'Upload folder could not be created.'], 500);
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $storedName)) pubChatJson(['ok' => false, 'message' => 'Could not save attachment.'], 500);
}

$senderName = $user['name'] !== '' ? $user['name'] : ucfirst($user['role']);
$stmt = $conn->prepare('INSERT INTO publication_messages
    (kind, publication_id, sender_id, sender_name, sender_role, message, attachment, attachment_original_name, is_internal, reply_to, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
$stmt->bind_param('siisssssii', $kind, $publicationId, $user['id'], $senderName, $user['role'], $message, $storedName, $originalName, $isInternal, $replyTo);
$stmt->execute();
$messageId = (int) $stmt->insert_id;
$stmt->close();

// Notify the other side so the conversation stays connected to My Requests.
try {
    $table = $kind === 'recognition' ? 'publication_recognition_submissions' : ($kind === 'contribution' ? 'publication_content_contributions' : 'publication_event_submissions');
    $titleCol = $kind === 'recognition' ? 'recognized_entity' : ($kind === 'contribution' ? 'content_title' : 'title');
    $ownerStmt = $conn->prepare("SELECT submitted_by, `{$titleCol}` AS pub_title FROM `{$table}` WHERE id = ? LIMIT 1");
    if ($ownerStmt) {
        $ownerStmt->bind_param('i', $publicationId);
        $ownerStmt->execute();
        $ownerRow = $ownerStmt->get_result()->fetch_assoc();
        $ownerStmt->close();
        $ownerId = (int) ($ownerRow['submitted_by'] ?? 0);
        $pubTitle = (string) ($ownerRow['pub_title'] ?? 'Publication Submission');
        $kindLabel = $kind === 'recognition' ? 'Recognition / Milestone' : ($kind === 'contribution' ? 'Content Contribution' : 'Event / Activity');
        $code = publicationDisplayCode($kind, (int) $publicationId);
        $actionUrl = '/user/view_publication.php?kind=' . rawurlencode($kind) . '&id=' . $publicationId . '#pubChatApp';
        if ($user['role'] === 'user') {
            // Requestor replied: ping staff/supervisors/admins is out of scope for
            // per-user notifications; staff sees unread via the list badge.
        } elseif ($ownerId > 0 && $ownerId !== $user['id'] && !$isInternal) {
            $nTitle = 'New reply on ' . $code;
            $nMsg = $kindLabel . ' "' . mb_strimwidth($pubTitle, 0, 80, '…') . '" has a new HPU reply. Open the conversation.';
            $nType = 'publication_message';
            $nStmt = $conn->prepare('INSERT INTO notifications (user_id, request_id, title, message, notification_type, action_url, publication_kind, publication_id, publication_status, is_read) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, NULL, 0)');
            if ($nStmt) {
                $nStmt->bind_param('isssssi', $ownerId, $nTitle, $nMsg, $nType, $actionUrl, $kind, $publicationId);
                $nStmt->execute();
                $nStmt->close();
            }
        }
    }
} catch (Throwable $ignored) {}

pubChatJson(['ok' => true, 'message' => 'Message sent.', 'message_id' => $messageId]);
