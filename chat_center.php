<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - CHAT CENTER
|--------------------------------------------------------------------------
| Real-time communication hub for HPU Staff, Supervisors, and Admin.
|--------------------------------------------------------------------------
*/

include __DIR__ . "/includes/config.php";
include __DIR__ . "/includes/auth.php";
include __DIR__ . "/includes/helpers.php";
require_once __DIR__ . "/includes/module_access.php";

if (empty($_SESSION["user_id"])) {
    header("Location: " . BASE_URL . "/index.php");
    exit;
}
requireModule($conn, "chat_center");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$userId = (int) ($_SESSION["user_id"] ?? 0);
$userRole = (string) ($_SESSION["role"] ?? "staff");
$userName = (string) ($_SESSION["name"] ?? "User");

// Tolerant photo column (older installs gain it on first upload).
$photoSelect = chatHasPhotoColumn($conn) ? "u.photo," : "NULL AS photo,";
$senderPhotoSelect = chatHasPhotoColumn($conn) ? "u.photo AS sender_photo" : "NULL AS sender_photo";

// Admin can freely disable the Announcements channel for everyone else.
$announcementsDisabled = chatAnnouncementsDisabled($conn);
$isAdminViewer = ($userRole === "admin");

// Update current user's active timestamp
if (function_exists('updateUserActivity')) {
    updateUserActivity($conn, $userId);
}

// Ensure chat_messages table exists
try {
    $conn->query("CREATE TABLE IF NOT EXISTS chat_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        channel VARCHAR(60) NOT NULL DEFAULT 'general',
        receiver_id INT NULL,
        message TEXT NOT NULL,
        attachment VARCHAR(255) NULL,
        attachment_original_name VARCHAR(255) NULL,
        reply_to INT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_chat_channel (channel),
        INDEX idx_chat_receiver (receiver_id),
        INDEX idx_chat_sender (sender_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    // Table exists or DB engine fallback
}

// Backward-compat: older installs lack the attachment columns.
try {
    $colCheck = $conn->query("SHOW COLUMNS FROM chat_messages LIKE 'attachment'");
    if ($colCheck && $colCheck->num_rows === 0) {
        $conn->query("ALTER TABLE chat_messages ADD COLUMN attachment VARCHAR(255) NULL AFTER message");
    }
} catch (Throwable $e) {
}
try {
    $colCheck = $conn->query("SHOW COLUMNS FROM chat_messages LIKE 'attachment_original_name'");
    if ($colCheck && $colCheck->num_rows === 0) {
        $conn->query("ALTER TABLE chat_messages ADD COLUMN attachment_original_name VARCHAR(255) NULL AFTER attachment");
    }
} catch (Throwable $e) {
}
try {
    $colCheck = $conn->query("SHOW COLUMNS FROM chat_messages LIKE 'reply_to'");
    if ($colCheck && $colCheck->num_rows === 0) {
        $conn->query("ALTER TABLE chat_messages ADD COLUMN reply_to INT NULL AFTER attachment_original_name, ADD INDEX idx_chat_reply_to (reply_to)");
    }
} catch (Throwable $e) {
}

// Emoji reactions: one per user per message (tap again to remove).
try {
    $conn->query("CREATE TABLE IF NOT EXISTS chat_reactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id INT NOT NULL,
        user_id INT NOT NULL,
        emoji VARCHAR(12) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_chat_reaction (message_id, user_id),
        INDEX idx_chat_reaction_msg (message_id),
        INDEX idx_chat_reaction_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
}

/*
|--------------------------------------------------------------------------
| Direct-message policy
|--------------------------------------------------------------------------
| Normal users (requestors) may only message the staff role plus
| fellow normal users the admin explicitly allowed via the
| "Peer Chat (User to User)" module. Supervisors and admins are
| hidden and unreachable for them. Everyone else keeps the
| previous behavior.
*/
function chatAnnouncementsDisabled(mysqli $conn): bool
{
    try {
        return trim((string) appSettingGet("chat_announcements_disabled", "", $conn)) === "1";
    } catch (Throwable $e) {
        return false;
    }
}

function chatHasPhotoColumn(mysqli $conn): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cached = false;
    try {
        $check = $conn->query("SHOW COLUMNS FROM users LIKE 'photo'");
        $cached = (bool) ($check && $check->num_rows > 0);
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

function chatCanMessage(mysqli $conn, int $viewerId, string $viewerRole, int $otherId): bool
{
    if ($otherId <= 0 || $otherId === $viewerId) {
        return false;
    }

    if ($viewerRole !== "user") {
        return true;
    }

    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM users u
            LEFT JOIN user_module_permissions ump ON (ump.user_id = u.id AND ump.module_key = 'chat_center')
            LEFT JOIN user_module_permissions ump_peer ON (ump_peer.user_id = u.id AND ump_peer.module_key = 'peer_chat')
            WHERE u.id = ?
              AND u.status = 'Active'
              AND (
                  (u.role = 'staff' AND (ump.is_allowed IS NULL OR ump.is_allowed = 1))
                  OR (ump_peer.is_allowed = 1 AND u.role NOT IN ('supervisor', 'admin'))
              )
        ");
        $stmt->bind_param("i", $otherId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row["total"] ?? 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

// Handle AJAX actions
if (isset($_GET["api"])) {
    header("Content-Type: application/json");
    $api = (string) $_GET["api"];

    if ($api === "file") {
        $fileId = (int) ($_GET["id"] ?? 0);
        if ($fileId <= 0) {
            http_response_code(404);
            exit("File not found.");
        }
        try {
            $stmt = $conn->prepare("SELECT id, sender_id, channel, receiver_id, attachment, attachment_original_name FROM chat_messages WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $fileId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (Throwable $e) {
            http_response_code(404);
            exit("File not found.");
        }
        if (!$row || empty($row["attachment"])) {
            http_response_code(404);
            exit("File not found.");
        }
        // Authorization: DM files only for sender/receiver; channel files
        // for anyone allowed in that channel.
        $msgReceiver = $row["receiver_id"] !== null ? (int) $row["receiver_id"] : null;
        $msgSender = (int) ($row["sender_id"] ?? 0);
        $msgChannel = (string) ($row["channel"] ?? "general");
        if ($msgReceiver !== null) {
            if ($userId !== $msgSender && $userId !== $msgReceiver) {
                http_response_code(403);
                exit("Access denied.");
            }
        } else {
            if ($userRole === "user" && $msgChannel === "general") {
                http_response_code(403);
                exit("Access denied.");
            }
        }
        $stored = basename((string) $row["attachment"]);
        $dir = uploadsSubDir("chat", $conn);
        $candidate = $dir . DIRECTORY_SEPARATOR . $stored;
        $realDir = realpath($dir);
        $realFile = realpath($candidate);
        if ($realDir === false || $realFile === false || !is_file($realFile)) {
            http_response_code(404);
            exit("File not found.");
        }
        if (stripos($realFile, rtrim($realDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
            http_response_code(403);
            exit("Access denied.");
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) @$finfo->file($realFile);
        if ($mime === "") {
            $mime = "application/octet-stream";
        }
        $inlineMimes = ["image/jpeg", "image/png", "image/gif", "image/webp", "application/pdf"];
        $isInline = in_array(strtolower($mime), $inlineMimes, true);
        $downloadName = (string) ($row["attachment_original_name"] !== null && $row["attachment_original_name"] !== "" ? $row["attachment_original_name"] : $stored);
        header("Content-Type: " . $mime);
        header("Content-Length: " . filesize($realFile));
        header("X-Content-Type-Options: nosniff");
        header("Cache-Control: private, max-age=3600");
        header(($isInline ? "Content-Disposition: inline" : "Content-Disposition: attachment") . "; filename=\"" . addcslashes(basename($downloadName), "\"\\") . "\"; filename*=UTF-8''" . rawurlencode(basename($downloadName)));
        $handle = @fopen($realFile, "rb");
        if ($handle === false) {
            http_response_code(404);
            exit("File not found.");
        }
        fpassthru($handle);
        fclose($handle);
        exit;
    }

    if ($api === "unsend" && $_SERVER["REQUEST_METHOD"] === "POST") {
        $msgId = (int) ($_POST["id"] ?? 0);
        if ($msgId <= 0) {
            echo json_encode(["ok" => false, "error" => "Invalid message."]);
            exit;
        }
        try {
            $stmt = $conn->prepare("SELECT id, sender_id, attachment FROM chat_messages WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $msgId);
            $stmt->execute();
            $target = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (Throwable $e) {
            echo json_encode(["ok" => false, "error" => "Message not found."]);
            exit;
        }
        if (!$target) {
            echo json_encode(["ok" => false, "error" => "Message not found."]);
            exit;
        }
        $isOwner = ((int) ($target["sender_id"] ?? 0) === $userId);
        $canModerate = in_array($userRole, ["admin", "supervisor"], true);
        if (!$isOwner && !$canModerate) {
            echo json_encode(["ok" => false, "error" => "You can only unsend your own messages."]);
            exit;
        }
        // Remove attached file from disk, if any.
        $stored = (string) ($target["attachment"] ?? "");
        if ($stored !== "") {
            try {
                $dir = uploadsSubDir("chat", $conn);
                $candidate = $dir . DIRECTORY_SEPARATOR . basename($stored);
                $realDir = realpath($dir);
                $realFile = realpath($candidate);
                if (
                    $realDir !== false && $realFile !== false && is_file($realFile)
                    && stripos($realFile, rtrim($realDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0
                ) {
                    @unlink($realFile);
                }
            } catch (Throwable $e) {
            }
        }
        try {
            $del = $conn->prepare("DELETE FROM chat_messages WHERE id = ? LIMIT 1");
            $del->bind_param("i", $msgId);
            $del->execute();
            $del->close();
            try {
                $rdel = $conn->prepare("DELETE FROM chat_reactions WHERE message_id = ?");
                $rdel->bind_param("i", $msgId);
                $rdel->execute();
                $rdel->close();
            } catch (Throwable $e2) {
            }
        } catch (Throwable $e) {
            echo json_encode(["ok" => false, "error" => "Could not unsend message."]);
            exit;
        }
        echo json_encode(["ok" => true, "id" => $msgId]);
        exit;
    }

    if ($api === "toggle_announcements" && $_SERVER["REQUEST_METHOD"] === "POST") {
        if ($userRole !== "admin") {
            echo json_encode(["ok" => false, "error" => "Only administrators can change this setting."]);
            exit;
        }
        try {
            appSettingsEnsureTable($conn);
            $next = chatAnnouncementsDisabled($conn) ? "" : "1";
            appSettingSet("chat_announcements_disabled", $next, $conn);
        } catch (Throwable $e) {
            echo json_encode(["ok" => false, "error" => "Could not save setting."]);
            exit;
        }
        echo json_encode(["ok" => true, "disabled" => $next === "1"]);
        exit;
    }

    if ($api === "react" && $_SERVER["REQUEST_METHOD"] === "POST") {
        $allowedEmojis = ["❤️", "👍", "😂", "😮", "😢", "🙏", "👏"];
        $msgId = (int) ($_POST["message_id"] ?? $_POST["id"] ?? 0);
        $emoji = trim((string) ($_POST["emoji"] ?? ""));
        if ($msgId <= 0 || !in_array($emoji, $allowedEmojis, true)) {
            echo json_encode(["ok" => false, "error" => "Invalid reaction."]);
            exit;
        }
        try {
            $stmt = $conn->prepare("SELECT id, sender_id, channel, receiver_id FROM chat_messages WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $msgId);
            $stmt->execute();
            $target = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (Throwable $e) {
            $target = null;
        }
        if (!$target) {
            echo json_encode(["ok" => false, "error" => "Message not found."]);
            exit;
        }
        // Only react to messages visible to you.
        $tReceiver = $target["receiver_id"] !== null ? (int) $target["receiver_id"] : null;
        $tSender = (int) ($target["sender_id"] ?? 0);
        $tChannel = (string) ($target["channel"] ?? "general");
        $visible = false;
        if ($tReceiver !== null) {
            $visible = ($userId === $tSender || $userId === $tReceiver);
        } else {
            $visible = !($userRole === "user" && $tChannel === "general");
        }
        if (!$visible) {
            echo json_encode(["ok" => false, "error" => "You cannot react to this message."]);
            exit;
        }
        try {
            $chk = $conn->prepare("SELECT emoji FROM chat_reactions WHERE message_id = ? AND user_id = ? LIMIT 1");
            $chk->bind_param("ii", $msgId, $userId);
            $chk->execute();
            $existing = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($existing && (string) $existing["emoji"] === $emoji) {
                // Tap again to remove.
                $del = $conn->prepare("DELETE FROM chat_reactions WHERE message_id = ? AND user_id = ? LIMIT 1");
                $del->bind_param("ii", $msgId, $userId);
                $del->execute();
                $del->close();
                echo json_encode(["ok" => true, "action" => "removed", "id" => $msgId]);
                exit;
            }
            if ($existing) {
                $up = $conn->prepare("UPDATE chat_reactions SET emoji = ?, created_at = NOW() WHERE message_id = ? AND user_id = ? LIMIT 1");
                $up->bind_param("sii", $emoji, $msgId, $userId);
                $up->execute();
                $up->close();
            } else {
                $ins = $conn->prepare("INSERT INTO chat_reactions (message_id, user_id, emoji) VALUES (?, ?, ?)");
                $ins->bind_param("iis", $msgId, $userId, $emoji);
                $ins->execute();
                $ins->close();
            }
        } catch (Throwable $e) {
            echo json_encode(["ok" => false, "error" => "Could not save reaction."]);
            exit;
        }
        echo json_encode(["ok" => true, "action" => "added", "id" => $msgId, "emoji" => $emoji]);
        exit;
    }

    if ($api === "send" && $_SERVER["REQUEST_METHOD"] === "POST") {
        $msg = trim((string) ($_POST["message"] ?? ""));
        $channel = trim((string) ($_POST["channel"] ?? "general"));
        $receiverId = isset($_POST["receiver_id"]) && $_POST["receiver_id"] !== "" ? (int) $_POST["receiver_id"] : null;

        $hasFile = isset($_FILES["attachment"]) && is_array($_FILES["attachment"])
            && (($_FILES["attachment"]["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)
            && (string) ($_FILES["attachment"]["name"] ?? "") !== "";

        if ($msg === "" && !$hasFile) {
            echo json_encode(["ok" => false, "error" => "Message cannot be empty."]);
            exit;
        }

        if (mb_strlen($msg) > 3000) {
            echo json_encode(["ok" => false, "error" => "Message is too long."]);
            exit;
        }

        // Normal users may only message HPU roles directly,
        // and only use the announcements channel otherwise.
        if ($userRole === "user") {
            if ($receiverId !== null && $receiverId > 0) {
                if (!chatCanMessage($conn, $userId, $userRole, $receiverId)) {
                    echo json_encode(["ok" => false, "error" => "You can only message HPU staff."]);
                    exit;
                }
            } else {
                $channel = "announcements";
            }
        }

        if ($channel === "announcements" && $receiverId === null && $announcementsDisabled && !$isAdminViewer) {
            echo json_encode(["ok" => false, "error" => "The Announcements channel is currently disabled by the administrator."]);
            exit;
        }

        $storedName = null;
        $originalName = null;
        if ($hasFile) {
            $file = $_FILES["attachment"];
            if (($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                echo json_encode(["ok" => false, "error" => "Attachment upload failed."]);
                exit;
            }
            $allowed = ["jpg", "jpeg", "png", "gif", "webp", "pdf", "doc", "docx", "xls", "xlsx", "ppt", "pptx", "zip"];
            $imageExtensions = ["jpg", "jpeg", "png", "gif", "webp"];
            $extension = strtolower(pathinfo((string) $file["name"], PATHINFO_EXTENSION));
            if (!in_array($extension, $allowed, true)) {
                echo json_encode(["ok" => false, "error" => "Unsupported file type. Allowed: images (up to 500 MB), PDF, Office docs, ZIP (up to 10 MB)."]);
                exit;
            }
            // Images allow up to 500 MB; documents/archives stay at 10 MB.
            $maxBytes = in_array($extension, $imageExtensions, true) ? 500 * 1024 * 1024 : 10 * 1024 * 1024;
            $maxLabel = in_array($extension, $imageExtensions, true) ? "500 MB" : "10 MB";
            if (($file["size"] ?? 0) <= 0 || ($file["size"] ?? 0) > $maxBytes) {
                echo json_encode(["ok" => false, "error" => "Attachment must be 1 byte to " . $maxLabel . "."]);
                exit;
            }
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $finfo->file($file["tmp_name"]);
            $mimeAllow = [
                "jpg" => ["image/jpeg"],
                "jpeg" => ["image/jpeg"],
                "png" => ["image/png"],
                "gif" => ["image/gif"],
                "webp" => ["image/webp"],
                "pdf" => ["application/pdf"],
                "doc" => ["application/msword", "application/octet-stream"],
                "docx" => ["application/vnd.openxmlformats-officedocument.wordprocessingml.document", "application/zip", "application/octet-stream"],
                "xls" => ["application/vnd.ms-excel", "application/octet-stream"],
                "xlsx" => ["application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", "application/zip", "application/octet-stream"],
                "ppt" => ["application/vnd.ms-powerpoint", "application/octet-stream"],
                "pptx" => ["application/vnd.openxmlformats-officedocument.presentationml.presentation", "application/zip", "application/octet-stream"],
                "zip" => ["application/zip", "application/x-zip-compressed", "multipart/x-zip", "application/octet-stream"],
            ];
            if (!in_array($mime, $mimeAllow[$extension] ?? [], true)) {
                echo json_encode(["ok" => false, "error" => "Attachment content does not match its file type."]);
                exit;
            }
            if (stripos($mime, "text/html") !== false) {
                echo json_encode(["ok" => false, "error" => "Unsupported attachment content."]);
                exit;
            }
            $safeOriginal = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string) $file["name"]));
            $safeOriginal = trim($safeOriginal, "._-");
            if ($safeOriginal === "") {
                $safeOriginal = "attachment." . $extension;
            }
            $originalName = $safeOriginal;
            $storedName = "cc_" . $userId . "_" . bin2hex(random_bytes(12)) . "." . $extension;
            $uploadDir = uploadsSubDir("chat", $conn);
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                echo json_encode(["ok" => false, "error" => "Upload folder could not be created."]);
                exit;
            }
            if (!move_uploaded_file($file["tmp_name"], $uploadDir . DIRECTORY_SEPARATOR . $storedName)) {
                echo json_encode(["ok" => false, "error" => "Could not save attachment."]);
                exit;
            }
        }

        // Optional reply: reply_to must belong to the same conversation
        // (same DM pair, or same channel thread) to prevent cross-thread leaks.
        $replyTo = isset($_POST["reply_to"]) && $_POST["reply_to"] !== "" ? (int) $_POST["reply_to"] : null;
        if ($replyTo !== null && $replyTo > 0) {
            try {
                $chk = $conn->prepare("SELECT id, channel, receiver_id, sender_id FROM chat_messages WHERE id = ? LIMIT 1");
                $chk->bind_param("i", $replyTo);
                $chk->execute();
                $ref = $chk->get_result()->fetch_assoc();
                $chk->close();
            } catch (Throwable $e) {
                $ref = null;
            }
            $refOk = false;
            if ($ref) {
                if ($receiverId !== null && $receiverId > 0) {
                    $rs = (int) ($ref["sender_id"] ?? 0);
                    $rr = $ref["receiver_id"] !== null ? (int) $ref["receiver_id"] : null;
                    $refOk = ($rs === $userId && $rr === $receiverId) || ($rs === $receiverId && $rr === $userId);
                } else {
                    $refOk = $ref["receiver_id"] === null && (string) ($ref["channel"] ?? "") === $channel;
                }
            }
            if (!$refOk) {
                echo json_encode(["ok" => false, "error" => "Invalid reply reference."]);
                exit;
            }
        } else {
            $replyTo = null;
        }

        $stmt = $conn->prepare("INSERT INTO chat_messages (sender_id, channel, receiver_id, message, attachment, attachment_original_name, reply_to) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssssi", $userId, $channel, $receiverId, $msg, $storedName, $originalName, $replyTo);
        $ok = $stmt->execute();
        $insertedId = $conn->insert_id;
        $stmt->close();

        echo json_encode(["ok" => $ok, "id" => $insertedId]);
        exit;
    }

    if ($api === "fetch") {
        $channel = trim((string) ($_GET["channel"] ?? "general"));
        $receiverId = isset($_GET["receiver_id"]) && $_GET["receiver_id"] !== "" ? (int) $_GET["receiver_id"] : null;

        $isDirect = $receiverId !== null && $receiverId > 0;
        $directAllowed = !$isDirect || chatCanMessage($conn, $userId, $userRole, $receiverId);

        $messages = [];

        if ($isDirect && $directAllowed) {
            // Mark unread messages as read for this direct conversation
            try {
                $markStmt = $conn->prepare("UPDATE chat_messages SET is_read = 1 WHERE receiver_id = ? AND sender_id = ? AND is_read = 0");
                $markStmt->bind_param("ii", $userId, $receiverId);
                $markStmt->execute();
                $markStmt->close();
            } catch (Throwable $e) {
            }

            $stmt = $conn->prepare("
                SELECT c.*, u.name as sender_name, u.role as sender_role, {$senderPhotoSelect}
                FROM chat_messages c
                JOIN users u ON u.id = c.sender_id
                WHERE (c.sender_id = ? AND c.receiver_id = ?)
                   OR (c.sender_id = ? AND c.receiver_id = ?)
                ORDER BY c.id ASC LIMIT 150
            ");
            $stmt->bind_param("iiii", $userId, $receiverId, $receiverId, $userId);
            $stmt->execute();
            $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } elseif (!$isDirect) {
            // Normal users only have access to announcements channel
            if ($userRole === "user" && $channel === "general") {
                $channel = "announcements";
            }

            if ($channel === "announcements" && $announcementsDisabled && !$isAdminViewer) {
                echo json_encode([
                    "ok" => true,
                    "messages" => [],
                    "unread_counts" => $unreadMap ?? [],
                    "latest_chat_users" => [],
                    "online_user_ids" => [],
                    "dm_previews" => [],
                    "announcements_disabled" => true,
                    "current_user_id" => $userId,
                    "current_user_role" => $userRole
                ]);
                exit;
            }

            $stmt = $conn->prepare("
                SELECT c.*, u.name as sender_name, u.role as sender_role, {$senderPhotoSelect}
                FROM chat_messages c
                JOIN users u ON u.id = c.sender_id
                WHERE c.channel = ? AND c.receiver_id IS NULL
                ORDER BY c.id ASC LIMIT 150
            ");
            $stmt->bind_param("s", $channel);
            $stmt->execute();
            $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }

        // Fetch unread count map for current user grouped by sender_id
        $unreadMap = [];
        try {
            $uStmt = $conn->prepare("SELECT sender_id, COUNT(*) as cnt FROM chat_messages WHERE receiver_id = ? AND is_read = 0 GROUP BY sender_id");
            $uStmt->bind_param("i", $userId);
            $uStmt->execute();
            $uRes = $uStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $uStmt->close();
            foreach ($uRes as $row) {
                $unreadMap[(int) $row["sender_id"]] = (int) $row["cnt"];
            }
        } catch (Throwable $e) {
        }

        // Fetch user IDs ordered by latest message timestamp
        $latestChatUserIds = [];
        try {
            $lcStmt = $conn->prepare("
                SELECT CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END AS other_id, MAX(created_at) as max_time
                FROM chat_messages
                WHERE (sender_id = ? AND receiver_id IS NOT NULL) OR (receiver_id = ? AND sender_id IS NOT NULL)
                GROUP BY other_id
                ORDER BY max_time DESC
            ");
            $lcStmt->bind_param("iii", $userId, $userId, $userId);
            $lcStmt->execute();
            $lcRes = $lcStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $lcStmt->close();
            foreach ($lcRes as $lc) {
                if (!empty($lc["other_id"])) {
                    $latestChatUserIds[] = (int) $lc["other_id"];
                }
            }
        } catch (Throwable $e) {
        }

        // Fetch list of currently online user IDs (active in last 3 minutes)
        $onlineUserIds = [];
        try {
            $oStmt = $conn->prepare("SELECT id FROM users WHERE last_seen IS NOT NULL AND last_seen >= NOW() - INTERVAL 3 MINUTE");
            $oStmt->execute();
            $oRes = $oStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $oStmt->close();
            foreach ($oRes as $o) {
                $onlineUserIds[] = (int) $o["id"];
            }
        } catch (Throwable $e) {
        }

        // Attach public file metadata (URL + image flag) for rendering.
        foreach ($messages as &$fm) {
            $att = (string) ($fm["attachment"] ?? "");
            if ($att !== "") {
                $ext = strtolower(pathinfo($att, PATHINFO_EXTENSION));
                $fm["attachment_url"] = "chat_center.php?api=file&id=" . (int) ($fm["id"] ?? 0);
                $fm["attachment_name"] = (string) ($fm["attachment_original_name"] ?? "");
                if ($fm["attachment_name"] === "") {
                    $fm["attachment_name"] = $att;
                }
                $fm["attachment_is_image"] = in_array($ext, ["jpg", "jpeg", "png", "gif", "webp"], true);
                $fm["attachment_ext"] = $ext;
            } else {
                $fm["attachment_url"] = null;
                $fm["attachment_name"] = null;
                $fm["attachment_is_image"] = false;
                $fm["attachment_ext"] = "";
            }
            $sp = basename((string) ($fm["sender_photo"] ?? ""));
            $fm["sender_photo_url"] = $sp !== ""
                ? rtrim(BASE_URL, "/") . "/download.php?scope=avatars&file=" . rawurlencode($sp)
                : null;
            unset($fm["sender_photo"]);
        }
        unset($fm);

        // Resolve quoted (replied-to) messages: sender + text snippet.
        $replyIds = [];
        foreach ($messages as $qm) {
            $rid = isset($qm["reply_to"]) && $qm["reply_to"] !== null ? (int) $qm["reply_to"] : 0;
            if ($rid > 0) {
                $replyIds[$rid] = $rid;
            }
        }
        $replyMap = [];
        if (!empty($replyIds)) {
            try {
                $placeholders = implode(",", array_fill(0, count($replyIds), "?"));
                $types = str_repeat("i", count($replyIds));
                $qStmt = $conn->prepare("
                    SELECT m.id, m.message, m.attachment_original_name, m.attachment, u.name AS sender_name
                    FROM chat_messages m
                    JOIN users u ON u.id = m.sender_id
                    WHERE m.id IN ({$placeholders})
                ");
                $ids = array_values($replyIds);
                $qStmt->bind_param($types, ...$ids);
                $qStmt->execute();
                $qRes = $qStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $qStmt->close();
                foreach ($qRes as $qr) {
                    $replyMap[(int) $qr["id"]] = $qr;
                }
            } catch (Throwable $e) {
            }
        }
        foreach ($messages as &$qm) {
            $rid = isset($qm["reply_to"]) && $qm["reply_to"] !== null ? (int) $qm["reply_to"] : 0;
            if ($rid > 0 && isset($replyMap[$rid])) {
                $qm["reply_sender_name"] = (string) ($replyMap[$rid]["sender_name"] ?? "Message");
                $qm["reply_message"] = (string) ($replyMap[$rid]["message"] ?? "");
                $qm["reply_attachment_name"] = (string) ($replyMap[$rid]["attachment_original_name"] ?? $replyMap[$rid]["attachment"] ?? "");
            } else {
                $qm["reply_sender_name"] = null;
                $qm["reply_message"] = null;
                $qm["reply_attachment_name"] = null;
            }
        }
        unset($qm);

        // Emoji reactions per message: counts + whether I reacted.
        $msgIds = [];
        foreach ($messages as $rm) {
            $mid = (int) ($rm["id"] ?? 0);
            if ($mid > 0) {
                $msgIds[$mid] = $mid;
            }
        }
        $reactionMap = [];
        if (!empty($msgIds)) {
            try {
                $placeholders = implode(",", array_fill(0, count($msgIds), "?"));
                $types = str_repeat("i", count($msgIds));
                $rStmt = $conn->prepare("SELECT message_id, emoji, user_id FROM chat_reactions WHERE message_id IN ({$placeholders})");
                $ids = array_values($msgIds);
                $rStmt->bind_param($types, ...$ids);
                $rStmt->execute();
                $rRes = $rStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $rStmt->close();
                foreach ($rRes as $rr) {
                    $mid = (int) $rr["message_id"];
                    $em = (string) $rr["emoji"];
                    if (!isset($reactionMap[$mid])) {
                        $reactionMap[$mid] = [];
                    }
                    if (!isset($reactionMap[$mid][$em])) {
                        $reactionMap[$mid][$em] = ["emoji" => $em, "count" => 0, "mine" => false];
                    }
                    $reactionMap[$mid][$em]["count"]++;
                    if ((int) $rr["user_id"] === $userId) {
                        $reactionMap[$mid][$em]["mine"] = true;
                    }
                }
            } catch (Throwable $e) {
            }
        }
        foreach ($messages as &$rm) {
            $mid = (int) ($rm["id"] ?? 0);
            $rm["reactions"] = isset($reactionMap[$mid]) ? array_values($reactionMap[$mid]) : [];
            $rm["my_reaction"] = null;
            if (isset($reactionMap[$mid])) {
                foreach ($reactionMap[$mid] as $entry) {
                    if (!empty($entry["mine"])) {
                        $rm["my_reaction"] = $entry["emoji"];
                        break;
                    }
                }
            }
        }
        unset($rm);

        // Latest DM per contact so sidebar previews stay live on poll.
        $dmPreviews = [];
        try {
            $pvStmt = $conn->prepare("
                SELECT c.id, c.sender_id, c.message, c.attachment_original_name, c.created_at,
                       CASE WHEN c.sender_id = ? THEN c.receiver_id ELSE c.sender_id END AS other_id
                FROM chat_messages c
                INNER JOIN (
                    SELECT CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END AS other_id, MAX(id) AS max_id
                    FROM chat_messages
                    WHERE sender_id = ? OR receiver_id = ?
                    GROUP BY other_id
                ) t ON t.max_id = c.id
            ");
            $pvStmt->bind_param("iiii", $userId, $userId, $userId, $userId);
            $pvStmt->execute();
            $pvRes = $pvStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $pvStmt->close();
            foreach ($pvRes as $pv) {
                $oid = (int) ($pv["other_id"] ?? 0);
                if ($oid > 0) {
                    $dmPreviews[$oid] = [
                        "text" => (string) ($pv["message"] ?? ""),
                        "sender_id" => (int) ($pv["sender_id"] ?? 0),
                        "file" => (string) ($pv["attachment_original_name"] ?? ""),
                        "time" => (string) ($pv["created_at"] ?? ""),
                    ];
                }
            }
        } catch (Throwable $e) {
        }

        // Fetch allowed team members list for DMs
        $teamMembersList = [];
        try {
            $visibleHpuRoles = ($userRole === "user") ? "('staff')" : "('staff', 'supervisor', 'admin')";
            $grantBranch = ($userRole === "user")
                ? "OR (ump_peer.is_allowed = 1 AND u.role NOT IN ('supervisor', 'admin'))"
                : "OR (ump.is_allowed = 1) OR (ump_peer.is_allowed = 1)";

            $tmStmt = $conn->prepare("
                SELECT DISTINCT u.id, u.name, u.email, u.role, u.department, u.status, {$photoSelect}
                       (u.last_seen IS NOT NULL AND u.last_seen >= NOW() - INTERVAL 3 MINUTE) AS is_online
                FROM users u
                LEFT JOIN user_module_permissions ump ON (ump.user_id = u.id AND ump.module_key = 'chat_center')
                LEFT JOIN user_module_permissions ump_peer ON (ump_peer.user_id = u.id AND ump_peer.module_key = 'peer_chat')
                WHERE u.id != ?
                  AND u.status = 'Active'
                  AND (
                      (u.role IN {$visibleHpuRoles} AND (ump.is_allowed IS NULL OR ump.is_allowed = 1))
                      {$grantBranch}
                  )
                ORDER BY is_online DESC, FIELD(u.role, 'admin', 'supervisor', 'staff', 'user'), u.name ASC
            ");
            $tmStmt->bind_param("i", $userId);
            $tmStmt->execute();
            $tmRes = $tmStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $tmStmt->close();

            foreach ($tmRes as $tm) {
                $sp = basename((string) ($tm["photo"] ?? ""));
                $photoUrl = $sp !== ""
                    ? rtrim(BASE_URL, "/") . "/download.php?scope=avatars&file=" . rawurlencode($sp)
                    : null;
                $teamMembersList[] = [
                    'id' => (int)$tm['id'],
                    'name' => (string)$tm['name'],
                    'role' => (string)$tm['role'],
                    'department' => (string)$tm['department'],
                    'is_online' => (bool)$tm['is_online'],
                    'photo_url' => $photoUrl
                ];
            }
        } catch (Throwable $e) {
        }

        echo json_encode([
            "ok" => true,
            "messages" => $messages,
            "unread_counts" => $unreadMap,
            "latest_chat_users" => $latestChatUserIds,
            "online_user_ids" => $onlineUserIds,
            "dm_previews" => $dmPreviews,
            "team_members" => $teamMembersList,
            "announcements_disabled" => $announcementsDisabled,
            "current_user_id" => $userId,
            "current_user_role" => $userRole
        ]);
        exit;
    }

    echo json_encode(["ok" => false]);
    exit;
}

// Fetch team members with chat_center permission, sorted by latest chat timestamp & online status
// Normal users only ever see the staff role — supervisors and admins are hidden from them.
// Admin grants (user_module_permissions.is_allowed = 1) are honored for every
// viewer: a normal user the admin explicitly allowed becomes visible (and
// reachable) to other normal users. Grants never expose supervisors/admins
// to normal viewers.
$visibleHpuRoles = ($userRole === "user") ? "('staff')" : "('staff', 'supervisor', 'admin')";
// Peer visibility comes from the dedicated "Peer Chat (User to User)"
// module: when the admin grants it to a normal user, that user is listed
// for (and reachable by) other normal users. Grants never expose
// supervisors/admins to normal viewers.
$grantBranch = ($userRole === "user")
    ? "OR (ump_peer.is_allowed = 1 AND u.role NOT IN ('supervisor', 'admin'))"
    : "OR (ump.is_allowed = 1) OR (ump_peer.is_allowed = 1)";
$teamMembers = [];
$initialUnreadMap = [];
$initialOnlineMap = [];
try {
    $stmt = $conn->prepare("
        SELECT DISTINCT u.id, u.name, u.email, u.role, u.department, u.status, {$photoSelect}
               (u.last_seen IS NOT NULL AND u.last_seen >= NOW() - INTERVAL 3 MINUTE) AS is_online,
               COALESCE(
                   (SELECT MAX(created_at)
                    FROM chat_messages c
                    WHERE (c.sender_id = ? AND c.receiver_id = u.id)
                       OR (c.sender_id = u.id AND c.receiver_id = ?)),
                   '2000-01-01 00:00:00'
               ) AS last_chat_time,
               (SELECT c2.message
                FROM chat_messages c2
                WHERE (c2.sender_id = ? AND c2.receiver_id = u.id)
                   OR (c2.sender_id = u.id AND c2.receiver_id = ?)
                ORDER BY c2.id DESC LIMIT 1) AS last_chat_text,
               (SELECT c2.sender_id
                FROM chat_messages c2
                WHERE (c2.sender_id = ? AND c2.receiver_id = u.id)
                   OR (c2.sender_id = u.id AND c2.receiver_id = ?)
                ORDER BY c2.id DESC LIMIT 1) AS last_chat_sender,
               (SELECT c2.attachment_original_name
                FROM chat_messages c2
                WHERE (c2.sender_id = ? AND c2.receiver_id = u.id)
                   OR (c2.sender_id = u.id AND c2.receiver_id = ?)
                ORDER BY c2.id DESC LIMIT 1) AS last_chat_file
        FROM users u
        LEFT JOIN user_module_permissions ump ON (ump.user_id = u.id AND ump.module_key = 'chat_center')
        LEFT JOIN user_module_permissions ump_peer ON (ump_peer.user_id = u.id AND ump_peer.module_key = 'peer_chat')
        WHERE u.id != ?
          AND u.status = 'Active'
          AND (
              (u.role IN {$visibleHpuRoles} AND (ump.is_allowed IS NULL OR ump.is_allowed = 1))
              {$grantBranch}
          )
        ORDER BY last_chat_time DESC, is_online DESC, FIELD(u.role, 'admin', 'supervisor', 'staff', 'user'), u.name ASC
    ");
    $stmt->bind_param("iiiiiiiii", $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId);
    $stmt->execute();
    $teamMembers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $uStmt = $conn->prepare("SELECT sender_id, COUNT(*) as cnt FROM chat_messages WHERE receiver_id = ? AND is_read = 0 GROUP BY sender_id");
    $uStmt->bind_param("i", $userId);
    $uStmt->execute();
    $uRes = $uStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $uStmt->close();
    foreach ($uRes as $row) {
        $initialUnreadMap[(int) $row["sender_id"]] = (int) $row["cnt"];
    }

    $oStmt = $conn->prepare("SELECT id FROM users WHERE last_seen IS NOT NULL AND last_seen >= NOW() - INTERVAL 3 MINUTE");
    $oStmt->execute();
    $oRes = $oStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $oStmt->close();
    foreach ($oRes as $o) {
        $initialOnlineMap[(int) $o["id"]] = true;
    }
} catch (Throwable $e) {
    $teamMembers = [];
}

// Whether the contact list contains an admin-allowed fellow requestor.
$peerContactShown = false;
foreach ($teamMembers as $tm) {
    if (($tm["role"] ?? "") === "user") {
        $peerContactShown = true;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Center | HPU Workbench</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/page_headers.css">
    <style>
        :root {
            --chat-bg: #f8fafc;
            --chat-card-bg: #ffffff;
            --chat-border: #e2e8f0;
            --chat-primary: #2563eb;
            --chat-primary-hover: #1d4ed8;
            --chat-bubble-me: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            --chat-bubble-other: #ffffff;
            --chat-sidebar-width: 282px;
        }

        body {
            background: #f1f5f9;
            color: #0f172a;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        <?php if (isset($_GET['popout']) && (string)$_GET['popout'] === '1'): ?>
        .page-header, .top-navbar, .sidebar, .phu-header-wrap, .layout-sidebar, nav { display: none !important; }
        .content { padding: 0 !important; margin: 0 !important; }
        body { background: #f8fafc !important; padding: 0 !important; margin: 0 !important; overflow: hidden !important; }
        .chat-container { height: 100vh !important; max-height: 100vh !important; min-height: 100vh !important; border-radius: 0 !important; border: none !important; box-shadow: none !important; }
        <?php endif; ?>

        .content {
            padding: 1.25rem;
        }

        /* Wrapper Container */
        .chat-container {
            display: grid;
            grid-template-columns: var(--chat-sidebar-width) 1fr;
            height: calc(100vh - 180px);
            min-height: 520px;
            max-height: calc(100vh - 180px);
            background: var(--chat-card-bg);
            border: 1px solid var(--chat-border);
            border-radius: 18px;
            box-shadow: 0 12px 30px -8px rgba(15, 23, 42, 0.08);
            overflow: hidden;
            position: relative;
        }

        /* Contacts Sidebar */
        .chat-sidebar {
            border-right: 1px solid var(--chat-border);
            background: #fdfdfe;
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 0;
            z-index: 10;
        }

        .chat-sidebar-header {
            padding: 16px 18px;
            border-bottom: 1px solid var(--chat-border);
            background: #ffffff;
            flex-shrink: 0;
        }

        .search-box-wrapper {
            position: relative;
        }

        .search-box-wrapper .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.88rem;
        }

        .search-box-wrapper input {
            padding-left: 36px;
            border-radius: 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            font-size: 0.84rem;
        }

        .search-box-wrapper input:focus {
            background: #ffffff;
            border-color: #93c5fd;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        /* Segmented Channels / Direct tabs */
        .conv-tabs {
            display: flex;
            background: #eef2f7;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 3px;
            gap: 3px;
            margin-bottom: 10px;
        }

        .conv-tab {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border: none;
            background: transparent;
            color: #64748b;
            font-size: 0.78rem;
            font-weight: 700;
            padding: 7px 8px;
            border-radius: 9px;
            cursor: pointer;
            transition: all 0.15s ease;
            position: relative;
        }

        .conv-tab:hover {
            color: var(--chat-primary);
        }

        .conv-tab.active {
            background: #ffffff;
            color: var(--chat-primary);
            box-shadow: 0 1px 4px rgba(15, 23, 42, 0.1);
        }

        .conv-tab-badge {
            display: none;
            background: #dc2626;
            color: #fff;
            font-size: 0.62rem;
            font-weight: 800;
            min-width: 18px;
            height: 18px;
            line-height: 18px;
            text-align: center;
            padding: 0 5px;
            border-radius: 10px;
        }

        .conv-tab-badge.show {
            display: inline-block;
        }

        .chat-pane {
            display: none;
        }

        .chat-pane.show {
            display: block;
        }

        .chat-contact-list {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            padding: 10px 12px;
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 #f1f5f9;
        }

        .chat-contact-list::-webkit-scrollbar {
            width: 5px;
        }

        .chat-contact-list::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        .chat-contact-list::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        .section-label {
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #94a3b8;
            padding: 8px 10px 4px;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            border-radius: 12px;
            cursor: pointer;
            transition: background 0.15s ease;
            text-decoration: none;
            color: inherit;
            position: relative;
            border: 1px solid transparent;
        }

        .contact-item:hover {
            background: #f1f5f9;
            text-decoration: none;
            color: inherit;
        }

        .contact-item.active {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: var(--chat-primary);
        }

        .contact-item.active .contact-name {
            color: #1d4ed8;
        }

        .contact-avatar {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: #ffffff;
            font-weight: 750;
            font-size: 0.92rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            position: relative;
            box-shadow: 0 3px 8px rgba(37, 99, 235, 0.15);
            overflow: hidden;
        }

        .status-dot {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 13px;
            height: 13px;
            border-radius: 50%;
            border: 2px solid #ffffff;
            z-index: 2;
        }

        .status-dot.online {
            background-color: #10b981;
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.35), 0 0 8px rgba(16, 185, 129, 0.8);
            animation: onlinePulse 2.2s ease-in-out infinite;
        }

        @keyframes onlinePulse {
            0%, 100% {
                box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.35), 0 0 6px rgba(16, 185, 129, 0.6);
            }
            50% {
                box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.15), 0 0 12px rgba(16, 185, 129, 0.9);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .status-dot.online {
                animation: none;
            }
        }

        .status-dot.offline {
            background-color: #cbd5e1;
        }

        .contact-avatar img,
        .msg-avatar img,
        .chat-header-avatar img {
            width: 100%;
            height: 100%;
            max-width: 100%;
            max-height: 100%;
            object-fit: cover;
            display: block;
            border-radius: 50%;
        }

        .contact-avatar.channel-avatar {
            background: linear-gradient(135deg, #10b981, #047857);
            box-shadow: 0 3px 8px rgba(16, 185, 129, 0.15);
        }

        .contact-avatar.announcement-avatar {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            box-shadow: 0 3px 8px rgba(245, 158, 11, 0.15);
        }

        .role-tag-badge {
            font-size: 0.62rem;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .role-tag-admin {
            background: #f3e8ff;
            color: #7e22ce;
        }

        .role-tag-supervisor {
            background: #e0f2fe;
            color: #0369a1;
        }

        .role-tag-staff {
            background: #e0e7ff;
            color: #4338ca;
        }

        .contact-info {
            flex: 1;
            min-width: 0;
        }

        .contact-name-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
        }

        .contact-name {
            font-size: 0.86rem;
            font-weight: 700;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .contact-item.active .contact-name {
            color: #1d4ed8;
        }

        .contact-sub {
            font-size: 0.72rem;
            color: #64748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-top: 1px;
        }

        .contact-time {
            font-size: 0.66rem;
            color: #94a3b8;
            font-weight: 600;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .contact-sub-row {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 1px;
            min-width: 0;
        }

        .contact-preview {
            flex: 1;
            min-width: 0;
            font-size: 0.74rem;
            color: #64748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .contact-preview.has-unread {
            color: #0f172a;
            font-weight: 700;
        }

        .contact-item.active .contact-preview.has-unread {
            color: #1d4ed8;
        }

        /* Chat Main View */
        .chat-main {
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 0;
            background: #f8fafc;
            overflow: hidden;
        }

        .chat-header {
            padding: 10px 18px;
            border-bottom: 1px solid var(--chat-border);
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
            flex-shrink: 0;
        }

        .chat-header-user {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .chat-header-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 750;
            font-size: 0.95rem;
            position: relative;
            flex-shrink: 0;
        }

        .chat-header-avatar .status-dot {
            width: 14px;
            height: 14px;
            border-width: 2px;
        }

        .chat-messages {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            padding: 16px 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            background: #f8fafc;
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 #f1f5f9;
        }

        .chat-messages::-webkit-scrollbar {
            width: 6px;
        }

        .chat-messages::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        .chat-messages::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        .chat-messages::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Date divider chip (Messenger-style day separator) */
        .date-divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 6px 0 2px;
            color: #94a3b8;
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .date-divider::before,
        .date-divider::after {
            content: "";
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }

        .date-divider span {
            background: #eef2f7;
            border: 1px solid #e2e8f0;
            padding: 3px 12px;
            border-radius: 20px;
            white-space: nowrap;
        }

        /* Message Item styling */
        .msg-group {
            display: flex;
            gap: 8px;
            max-width: 78%;
            animation: fadeIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(6px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .msg-group.me {
            align-self: flex-end;
        }

        .msg-group.other {
            align-self: flex-start;
        }

        .msg-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: linear-gradient(135deg, #64748b, #334155);
            color: #fff;
            font-weight: 750;
            font-size: 0.62rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            align-self: flex-end;
            margin-bottom: 2px;
        }

        .msg-avatar-spacer {
            width: 30px;
            flex-shrink: 0;
        }

        .msg-body {
            display: flex;
            flex-direction: column;
            min-width: 0;
            max-width: 100%;
        }

        .msg-group.me .msg-body {
            align-items: flex-end;
        }

        .msg-group.other .msg-body {
            align-items: flex-start;
        }

        .msg-header-info {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 3px;
            padding: 0 4px;
        }

        .msg-group.me .msg-header-info {
            flex-direction: row-reverse;
        }

        .msg-sender-name {
            font-size: 0.72rem;
            font-weight: 750;
            color: #64748b;
        }

        .msg-time {
            font-size: 0.64rem;
            color: #b0bccb;
        }

        .msg-line {
            display: flex;
            align-items: center;
            gap: 4px;
            max-width: 100%;
        }

        .msg-group.me .msg-line {
            flex-direction: row;
        }

        .msg-bubble-box {
            padding: 9px 14px;
            border-radius: 20px;
            font-size: 0.88rem;
            line-height: 1.45;
            word-break: break-word;
            position: relative;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
        }

        .msg-group.me .msg-bubble-box {
            background: var(--chat-bubble-me);
            color: #ffffff;
            border-bottom-right-radius: 6px;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.22);
        }

        .msg-group.other .msg-bubble-box {
            background: var(--chat-bubble-other);
            color: #0f172a;
            border-bottom-left-radius: 6px;
            border: 1px solid #e8eef4;
        }

        /* Tighten stacked bubbles from the same sender */
        .msg-group.compact {
            margin-top: -9px;
        }

        .msg-group.compact .msg-bubble-box {
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        }

        /* Quick Action Pills & Input */
        .chat-footer {
            padding: 16px 20px;
            background: #ffffff;
            border-top: 1px solid var(--chat-border);
        }

        .quick-pills {
            display: flex;
            gap: 6px;
            overflow-x: auto;
            padding-bottom: 8px;
            margin-bottom: 4px;
            scrollbar-width: none;
        }

        .quick-pill-btn {
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #475569;
            font-size: 0.72rem;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
            white-space: nowrap;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .quick-pill-btn:hover {
            background: #eff6ff;
            border-color: #93c5fd;
            color: var(--chat-primary);
        }

        .input-bar-form {
            display: flex;
            align-items: center;
            gap: 6px;
            background: #f1f5f9;
            border: 1px solid transparent;
            border-radius: 26px;
            padding: 5px 6px 5px 8px;
            transition: all 0.2s ease;
        }

        .input-bar-form:focus-within {
            background: #ffffff;
            border-color: var(--chat-primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .chat-text-input {
            border: none;
            background: transparent;
            outline: none;
            box-shadow: none;
            width: 100%;
            font-size: 0.88rem;
            color: #0f172a;
            resize: none;
            max-height: 100px;
        }

        .chat-send-btn {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            border: none;
            color: #ffffff;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .chat-send-btn:hover {
            transform: scale(1.04);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.35);
        }

        .chat-send-btn:active {
            transform: scale(0.96);
        }

        .chat-send-btn:disabled {
            opacity: 0.6;
            cursor: wait;
            transform: none;
        }

        .chat-attach-btn {
            background: transparent;
            border: none;
            color: var(--chat-primary);
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
            font-size: 1.1rem;
            transition: all 0.15s ease;
        }

        .chat-attach-btn:hover {
            background: #e2e8f0;
            color: var(--chat-primary);
        }

        .chat-file-chip {
            display: none;
            align-items: center;
            gap: 8px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            padding: 6px 8px 6px 12px;
            margin-bottom: 8px;
            font-size: 0.78rem;
            color: #1e40af;
        }

        .chat-file-chip.show {
            display: flex;
        }

        .chat-file-chip .file-name {
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 260px;
        }

        .chat-file-chip .file-remove {
            background: transparent;
            border: none;
            color: #1e40af;
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 6px;
            font-size: 0.85rem;
        }

        .chat-file-chip .file-remove:hover {
            background: #dbeafe;
        }

        .chat-file-chip .file-thumb {
            display: none;
            width: 44px;
            height: 44px;
            border-radius: 8px;
            object-fit: cover;
            border: 1px solid #bfdbfe;
            flex-shrink: 0;
        }

        .chat-file-chip .file-thumb.show {
            display: block;
        }

        .msg-attachment {
            margin-top: 8px;
        }

        .msg-attachment img.msg-attach-img {
            max-width: 240px;
            max-height: 180px;
            border-radius: 10px;
            display: block;
            cursor: pointer;
            border: 1px solid rgba(0, 0, 0, 0.08);
        }

        .msg-group.me .msg-attach-file {
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #fff;
        }

        .msg-attach-file {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 8px 12px;
            text-decoration: none;
            color: #0f172a;
            font-size: 0.8rem;
            max-width: 260px;
        }

        .msg-attach-file:hover {
            text-decoration: none;
            filter: brightness(0.97);
        }

        .msg-group.me .msg-attach-file:hover {
            color: #fff;
        }

        .msg-attach-file i {
            font-size: 1.3rem;
            flex-shrink: 0;
        }

        .msg-attach-file .att-name {
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
            max-width: 170px;
        }

        .msg-attach-file .att-hint {
            font-size: 0.68rem;
            opacity: 0.75;
        }

        .msg-unsend {
            background: transparent;
            border: none;
            color: #94a3b8;
            font-size: 0.68rem;
            font-weight: 600;
            cursor: pointer;
            padding: 2px 6px;
            margin-top: 3px;
            border-radius: 6px;
            opacity: 0;
            transition: opacity 0.15s ease, color 0.15s ease;
        }

        .msg-group:hover .msg-unsend,
        .msg-group:focus-within .msg-unsend {
            opacity: 1;
        }

        .msg-unsend:hover {
            color: #dc2626;
            background: rgba(220, 38, 38, 0.08);
        }

        /* Hover actions sit beside the bubble, Messenger-style */
        .msg-actions {
            display: flex;
            gap: 0;
            opacity: 0;
            transition: opacity 0.15s ease;
            flex-shrink: 0;
        }

        .msg-group:hover .msg-actions,
        .msg-group:focus-within .msg-actions {
            opacity: 1;
        }

        .msg-actions button {
            background: transparent;
            border: none;
            color: #a5b3c5;
            font-size: 0.95rem;
            cursor: pointer;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: color 0.15s ease, background 0.15s ease;
        }

        .msg-actions button:hover {
            color: var(--chat-primary);
            background: rgba(37, 99, 235, 0.1);
        }

        .msg-actions .msg-unsend:hover {
            color: #dc2626;
            background: rgba(220, 38, 38, 0.1);
        }

        .msg-actions button .action-label {
            display: none;
        }

        .msg-quote {
            border-left: 3px solid #93c5fd;
            background: rgba(147, 197, 253, 0.16);
            border-radius: 0 8px 8px 0;
            padding: 6px 10px;
            margin-bottom: 8px;
            font-size: 0.76rem;
            cursor: pointer;
            max-width: 260px;
        }

        .msg-group.me .msg-quote {
            border-left-color: rgba(255, 255, 255, 0.7);
            background: rgba(255, 255, 255, 0.16);
        }

        .msg-quote .quote-name {
            font-weight: 750;
            display: block;
            margin-bottom: 1px;
        }

        .msg-group.other .msg-quote .quote-name {
            color: #1d4ed8;
        }

        .msg-quote .quote-text {
            opacity: 0.85;
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .chat-reply-bar {
            display: none;
            align-items: center;
            gap: 8px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-left: 3px solid var(--chat-primary);
            border-radius: 10px;
            padding: 6px 8px 6px 12px;
            margin-bottom: 8px;
            font-size: 0.78rem;
            color: #1e3a8a;
        }

        .chat-reply-bar.show {
            display: flex;
        }

        .chat-reply-bar .reply-preview {
            flex: 1;
            min-width: 0;
        }

        .chat-reply-bar .reply-preview strong {
            display: block;
            font-size: 0.72rem;
        }

        .chat-reply-bar .reply-preview span {
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            opacity: 0.85;
        }

        .chat-reply-bar .reply-cancel {
            background: transparent;
            border: none;
            color: #1e40af;
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 6px;
            font-size: 0.85rem;
        }

        .chat-reply-bar .reply-cancel:hover {
            background: #dbeafe;
        }

        .msg-flash {
            animation: msgFlash 1.2s ease;
        }

        @keyframes msgFlash {
            0%, 40% {
                filter: drop-shadow(0 0 6px rgba(37, 99, 235, 0.65));
            }
            100% {
                filter: none;
            }
        }

        /* Emoji reactions */
        .msg-reactions {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            margin-top: 3px;
            max-width: 260px;
        }

        .msg-group.me .msg-reactions {
            justify-content: flex-end;
        }

        .msg-reaction-badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 999px;
            padding: 1px 8px 1px 6px;
            font-size: 0.72rem;
            line-height: 1.5;
            cursor: pointer;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
            transition: transform 0.1s ease, border-color 0.1s ease;
        }

        .msg-reaction-badge:hover {
            transform: scale(1.08);
            border-color: #93c5fd;
        }

        .msg-reaction-badge.mine {
            border-color: var(--chat-primary);
            background: #eff6ff;
        }

        .msg-reaction-badge .rx-count {
            font-size: 0.66rem;
            font-weight: 800;
            color: #475569;
        }

        .msg-reaction-badge.mine .rx-count {
            color: var(--chat-primary);
        }

        .rx-picker {
            position: fixed;
            z-index: 1200;
            display: none;
            align-items: center;
            gap: 2px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 999px;
            padding: 6px 10px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.18);
        }

        .rx-picker.show {
            display: flex;
            animation: fadeIn 0.15s ease;
        }

        .rx-picker button {
            background: transparent;
            border: none;
            font-size: 1.35rem;
            line-height: 1;
            padding: 4px 5px;
            border-radius: 50%;
            cursor: pointer;
            transition: transform 0.1s ease, background 0.1s ease;
        }

        .rx-picker button:hover {
            transform: scale(1.3);
            background: #f1f5f9;
        }

        @media (max-width: 768px) {
            .msg-unsend {
                opacity: 0.7;
            }
        }

        /* Mobile Sidebar Toggle */
        @media (max-width: 768px) {
            .chat-container {
                grid-template-columns: 1fr;
            }

            .chat-sidebar {
                position: absolute;
                top: 0;
                left: 0;
                bottom: 0;
                width: 85%;
                max-width: 320px;
                transform: translateX(-100%);
                transition: transform 0.25s ease-in-out;
                box-shadow: 10px 0 25px rgba(0, 0, 0, 0.15);
            }

            .chat-sidebar.show-mobile {
                transform: translateX(0);
            }

            .msg-group {
                max-width: 85%;
            }
        }
    </style>
</head>

<body class="app-shell">
    <div class="d-flex min-vh-100">
        <?php include __DIR__ . "/includes/sidebar.php"; ?>
        <div class="main flex-grow-1">
            <main class="content">
                <div class="container-fluid" style="max-width:1300px">

                    <!-- Page Header -->
                    <div class="ph">
                        <div class="ph-copy">
                            <div class="ph-icon"><i class="bi bi-chat-dots-fill"></i></div>
                            <div>
                                <p class="ph-eyebrow">Collaboration Hub</p>
                                <h1 class="ph-title">PHU Staff Chat Center</h1>
                                <p class="ph-subtitle">Connect instantly with PHU Staff, Supervisors, and Admin team
                                    members.</p>
                            </div>
                        </div>
                        <div class="ph-actions">
                            <span
                                class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill fs-xs fw-bold shadow-sm d-inline-flex align-items-center">
                                <span class="spinner-grow spinner-grow-sm me-2 text-success"
                                    style="width:0.5rem; height:0.5rem;" role="status"></span>Live Network
                            </span>
                        </div>
                    </div>

                    <!-- Main Chat Box Container -->
                    <div class="chat-container">

                        <!-- Sidebar (Channels & Contacts) -->
                        <div class="chat-sidebar" id="chatSidebar">
                            <div class="chat-sidebar-header">
                                <div class="conv-tabs" role="tablist" aria-label="Conversation types">
                                    <button type="button" class="conv-tab active" id="tabChannels" role="tab"
                                        aria-selected="true" data-pane="paneChannels">
                                        <i class="bi bi-hash"></i><span>Channels</span>
                                    </button>
                                    <button type="button" class="conv-tab" id="tabDirect" role="tab"
                                        aria-selected="false" data-pane="paneDirect">
                                        <i class="bi bi-person-fill"></i><span>Direct</span>
                                        <?php $totalUnread = array_sum($initialUnreadMap); ?>
                                        <span class="conv-tab-badge<?= $totalUnread > 0 ? " show" : "" ?>"
                                            id="directTabBadge"><?= $totalUnread > 99 ? "99+" : (int) $totalUnread ?></span>
                                    </button>
                                </div>
                                <div class="search-box-wrapper">
                                    <i class="bi bi-search search-icon"></i>
                                    <input type="text" id="contactSearch" class="form-control"
                                        placeholder="Search conversations...">
                                </div>
                            </div>

                            <div class="chat-contact-list">
                                <!-- Team Channels pane -->
                                <div class="chat-pane show" id="paneChannels" role="tabpanel"
                                    aria-labelledby="tabChannels">
                                    <div class="section-label">Team Channels</div>

                                    <?php if ($userRole !== "user"): ?>
                                    <a href="#" class="contact-item active" data-channel="general" data-receiver-id=""
                                        data-name="HPU Team General" data-role="General Channel"
                                        data-avatar-class="channel-avatar" data-icon="bi-hash">
                                        <div class="contact-avatar channel-avatar"><i class="bi bi-hash"></i></div>
                                        <div class="contact-info">
                                            <div class="contact-name-row">
                                                <span class="contact-name">HPU Team General</span>
                                                <span class="role-tag-badge bg-emerald-100 text-emerald-800"
                                                    style="font-size:0.6rem;">Public</span>
                                            </div>
                                            <div class="contact-sub">All HPU Staff, Supervisors & Admins</div>
                                        </div>
                                    </a>
                                <?php endif; ?>

                                <?php if (!$announcementsDisabled || $isAdminViewer): ?>
                                <a href="#" class="contact-item <?= $userRole === "user" ? "active" : "" ?>"
                                    data-channel="announcements" data-receiver-id="" data-name="Official Announcements"
                                    data-role="Broadcast" data-avatar-class="announcement-avatar"
                                    data-icon="bi-megaphone-fill">
                                    <div class="contact-avatar announcement-avatar"><i class="bi bi-megaphone-fill"></i>
                                    </div>
                                    <div class="contact-info">
                                        <div class="contact-name-row">
                                            <span class="contact-name">Announcements</span>
                                            <?php if ($announcementsDisabled): ?>
                                                <span class="role-tag-badge bg-secondary-subtle text-secondary border border-secondary-subtle"
                                                    style="font-size:0.6rem;">Disabled</span>
                                            <?php else: ?>
                                                <span class="role-tag-badge bg-amber-100 text-amber-800"
                                                    style="font-size:0.6rem;">Notice</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="contact-sub"><?= $announcementsDisabled ? "Hidden from everyone except admins" : "Important updates & notices" ?></div>
                                    </div>
                                </a>
                                <?php endif; ?>
                                </div>

                                <!-- Direct Messages pane -->
                                <div class="chat-pane" id="paneDirect" role="tabpanel" aria-labelledby="tabDirect">
                                    <div class="section-label">
                                        <?= $userRole === "user" ? ($peerContactShown ? "HPU Staff & Allowed Contacts" : "HPU Staff — Direct Messages") : "Direct Messages" ?>
                                    </div>

                                <?php foreach ($teamMembers as $m): ?>
                                    <?php
                                    $mInit = strtoupper(substr((string) $m["name"], 0, 2));
                                    $uCount = $initialUnreadMap[(int) $m["id"]] ?? 0;
                                    $isOnline = !empty($initialOnlineMap[(int) $m["id"]]);
                                    $lastText = trim((string) ($m["last_chat_text"] ?? ""));
                                    $lastFile = trim((string) ($m["last_chat_file"] ?? ""));
                                    $lastSender = (int) ($m["last_chat_sender"] ?? 0);
                                    $mine = $lastSender === $userId;
                                    if ($lastText !== "") {
                                        $preview = ($mine ? "You: " : "") . (function_exists("mb_strimwidth") ? mb_strimwidth($lastText, 0, 44, "…") : substr($lastText, 0, 44));
                                    } elseif ($lastFile !== "") {
                                        $preview = $mine ? "You sent an attachment" : "Sent you an attachment";
                                    } else {
                                        $preview = $m["department"] ? (string) $m["department"] : ucfirst((string) $m["role"]);
                                    }
                                    $previewTime = "";
                                    $lastTs = strtotime((string) ($m["last_chat_time"] ?? ""));
                                    if ($lastTs && $lastTs > strtotime("2001-01-01")) {
                                        if (date("Y-m-d", $lastTs) === date("Y-m-d")) {
                                            $previewTime = date("g:i A", $lastTs);
                                        } elseif (date("Y-m-d", $lastTs) === date("Y-m-d", strtotime("-1 day"))) {
                                            $previewTime = "Yesterday";
                                        } else {
                                            $previewTime = date("M j", $lastTs);
                                        }
                                    }
                                    ?>
                                    <?php
                                    $mPhoto = basename((string) ($m["photo"] ?? ""));
                                    $mPhotoUrl = $mPhoto !== ""
                                        ? rtrim(BASE_URL, "/") . "/download.php?scope=avatars&file=" . rawurlencode($mPhoto)
                                        : "";
                                    ?>
                                    <a href="#" class="contact-item" data-channel="direct"
                                        data-receiver-id="<?= (int) $m["id"] ?>" data-name="<?= e($m["name"]) ?>"
                                        data-role="<?= e(ucfirst($m["role"])) ?> • <?= e($m["department"] ? (string) $m["department"] : "HPU Workbench") ?>"
                                        data-init="<?= e($mInit) ?>" data-photo="<?= e($mPhotoUrl) ?>">
                                        <div class="contact-avatar">
                                            <?php if ($mPhotoUrl !== ""): ?>
                                                <img src="<?= e($mPhotoUrl) ?>" alt="" loading="lazy">
                                            <?php else: ?>
                                                <?= e($mInit) ?>
                                            <?php endif; ?>
                                            <span class="status-dot <?= $isOnline ? 'online' : 'offline' ?>"></span>
                                        </div>
                                        <div class="contact-info">
                                            <div class="contact-name-row">
                                                <span class="contact-name"><?= e($m["name"]) ?></span>
                                                <?php if ($previewTime !== ""): ?>
                                                    <span class="contact-time"><?= e($previewTime) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="contact-sub-row">
                                                <span
                                                    class="contact-preview<?= $uCount > 0 ? " has-unread" : "" ?>"><?= e($preview) ?></span>
                                                <?php if ($uCount > 0): ?>
                                                    <span class="badge bg-danger rounded-pill ms-auto unread-badge"
                                                        style="font-size:0.68rem;"><?= $uCount > 99 ? "99+" : $uCount ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Chat Main Frame -->
                        <div class="chat-main">
                            <!-- Chat Top Bar -->
                            <div class="chat-header">
                                <div class="chat-header-user">
                                    <button type="button" class="btn btn-sm btn-light border d-md-none me-1"
                                        id="btnToggleMobileSidebar" title="Toggle Contacts">
                                        <i class="bi bi-list fs-5"></i>
                                    </button>
                                    <div class="chat-header-avatar <?= $userRole === 'user' ? 'announcement-avatar' : '' ?>"
                                        id="chatHeaderAvatar">
                                        <i class="bi <?= $userRole === 'user' ? 'bi-megaphone-fill' : 'bi-hash' ?>"></i>
                                        <span class="status-dot offline" id="chatHeaderDot"></span>
                                    </div>
                                    <div>
                                        <h6 class="fw-bold mb-0 text-dark" id="chatTitle">
                                            <?= $userRole === 'user' ? 'Official Announcements' : 'HPU Team General' ?>
                                        </h6>
                                        <small class="text-muted d-flex align-items-center gap-1" id="chatSubtitle">
                                            <i class="bi bi-people-fill text-primary"></i>
                                            <?= $userRole === 'user' ? 'Official Notice Channel' : 'Public Team Channel' ?>
                                        </small>
                                    </div>
                                </div>

                                <div class="d-flex align-items-center gap-2">
                                    <?php if ($isAdminViewer): ?>
                                    <button type="button" class="btn btn-sm border <?= $announcementsDisabled ? "btn-warning" : "btn-light text-secondary" ?>"
                                        id="btnAnnounceToggle"
                                        title="<?= $announcementsDisabled ? "Announcements disabled — click to enable" : "Announcements enabled — click to disable" ?>">
                                        <i class="bi <?= $announcementsDisabled ? "bi-megaphone" : "bi-megaphone-fill" ?>" id="announceToggleIcon"></i><span
                                            class="d-none d-sm-inline ms-1">Announcements <?= $announcementsDisabled ? "off" : "on" ?></span>
                                    </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-light border text-secondary"
                                        id="btnSoundToggle" title="Message sound on">
                                        <i class="bi bi-bell-fill" id="soundToggleIcon"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-light border text-secondary"
                                        id="btnRefreshChat" title="Refresh conversation">
                                        <i class="bi bi-arrow-clockwise me-1"></i><span
                                            class="d-none d-sm-inline">Refresh</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Messages Container -->
                            <div class="chat-messages" id="chatMessages">
                                <div class="text-center text-muted py-5 my-auto">
                                    <div class="spinner-border text-primary spinner-border-sm me-2" role="status"></div>
                                    Connecting to message stream...
                                </div>
                            </div>

                            <!-- Input Footer -->
                            <div class="chat-footer">
                                <!-- Quick Action Reaction Pills -->
                                <div class="quick-pills">
                                    <button type="button" class="quick-pill-btn" data-text="👍 Got it, thank you!">👍
                                        Got it</button>
                                    <button type="button" class="quick-pill-btn"
                                        data-text="Acknowledged and on it! 🤗">🤗 On it</button>
                                    <button type="button" class="quick-pill-btn"
                                        data-text="Please check the job request updates.">📋 Request Update</button>
                                    <button type="button" class="quick-pill-btn"
                                        data-text="Can we discuss this further?">💬 Discuss further</button>
                                </div>

                                <form id="chatForm" enctype="multipart/form-data">
                                    <div class="chat-reply-bar" id="chatReplyBar">
                                        <i class="bi bi-reply-fill"></i>
                                        <div class="reply-preview">
                                            <strong id="chatReplyName">Replying to</strong>
                                            <span id="chatReplyText"></span>
                                        </div>
                                        <button type="button" class="reply-cancel" id="chatReplyCancel"
                                            title="Cancel reply" aria-label="Cancel reply">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                    <div class="chat-file-chip" id="chatFileChip">
                                        <i class="bi bi-paperclip"></i>
                                        <img id="chatFileThumb" class="file-thumb" alt="Attachment preview">
                                        <span class="file-name" id="chatFileName">No file selected</span>
                                        <span class="text-muted" id="chatFileSize"></span>
                                        <button type="button" class="file-remove" id="chatFileRemove"
                                            title="Remove attachment" aria-label="Remove attachment">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                    <div class="input-bar-form">
                                        <button type="button" class="chat-attach-btn" id="btnAttach"
                                            title="Attach file (images up to 500 MB; PDF, Office, ZIP up to 10 MB)">
                                            <i class="bi bi-paperclip"></i>
                                        </button>
                                        <input type="file" id="chatFileInput" class="d-none"
                                            accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip">
                                        <input type="text" id="chatInput" class="chat-text-input"
                                            placeholder="Type your message here... (Press Enter to send)"
                                            autocomplete="off">
                                        <button type="submit" class="chat-send-btn" title="Send Message" id="btnSend">
                                            <i class="bi bi-send-fill fs-6"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted d-block mt-1" style="font-size:0.68rem;">Attach: images
                                        up to 500 MB; PDF, Word, Excel, PowerPoint, ZIP up to 10 MB. Tip: paste a
                                        screenshot straight from your clipboard (Ctrl+V).</small>
                                </form>
                            </div>
                        </div>

                    </div>

                </div>
            </main>
        </div>
    </div>

    <div class="rx-picker" id="rxPicker" role="menu" aria-label="React with emoji">
        <button type="button" data-emoji="❤️" title="Love">❤️</button>
        <button type="button" data-emoji="👍" title="Like">👍</button>
        <button type="button" data-emoji="😂" title="Haha">😂</button>
        <button type="button" data-emoji="😮" title="Wow">😮</button>
        <button type="button" data-emoji="😢" title="Sad">😢</button>
        <button type="button" data-emoji="🙏" title="Thanks">🙏</button>
        <button type="button" data-emoji="👏" title="Applaud">👏</button>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            let activeChannel = "<?= $userRole === 'user' ? 'announcements' : 'general' ?>";
            let activeReceiverId = "";
            let activeName = "<?= $userRole === 'user' ? 'Official Announcements' : 'HPU Team General' ?>";
            let activeRole = "<?= $userRole === 'user' ? 'Broadcast' : 'General Channel' ?>";
            let activeAvatarHtml = '<?= $userRole === 'user' ? '<i class="bi bi-megaphone-fill"></i>' : '<i class="bi bi-hash"></i>' ?>';

            const chatMessages = document.getElementById("chatMessages");
            const chatTitle = document.getElementById("chatTitle");
            const chatSubtitle = document.getElementById("chatSubtitle");
            const chatHeaderAvatar = document.getElementById("chatHeaderAvatar");
            const chatForm = document.getElementById("chatForm");
            const chatInput = document.getElementById("chatInput");
            const chatSidebar = document.getElementById("chatSidebar");
            let currentUserRole = "<?= $userRole ?>";
            let announcementsOff = <?= $announcementsDisabled ? "true" : "false" ?>;
            const isAdminViewer = <?= $isAdminViewer ? "true" : "false" ?>;

            // Notification sound (WebAudio chime — no audio file needed).
            let soundOn = true;
            try { soundOn = localStorage.getItem("cc_sound") !== "0"; } catch (err) {}
            let audioCtx = null;
            function ensureAudio() {
                if (!audioCtx) {
                    try { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch (err) { audioCtx = null; }
                }
                if (audioCtx && audioCtx.state === "suspended") { audioCtx.resume(); }
            }
            document.addEventListener("pointerdown", ensureAudio, { passive: true });
            document.addEventListener("keydown", ensureAudio);
            function playChime() {
                if (!soundOn) return;
                ensureAudio();
                if (!audioCtx) return;
                try {
                    const t0 = audioCtx.currentTime;
                    [[880, 0], [1318.5, 0.12]].forEach(function ([freq, dt]) {
                        const osc = audioCtx.createOscillator();
                        const gain = audioCtx.createGain();
                        osc.type = "sine";
                        osc.frequency.value = freq;
                        gain.gain.setValueAtTime(0.0001, t0 + dt);
                        gain.gain.exponentialRampToValueAtTime(0.25, t0 + dt + 0.02);
                        gain.gain.exponentialRampToValueAtTime(0.0001, t0 + dt + 0.28);
                        osc.connect(gain);
                        gain.connect(audioCtx.destination);
                        osc.start(t0 + dt);
                        osc.stop(t0 + dt + 0.3);
                    });
                } catch (err) {}
            }

            // Track the newest seen message per open conversation so the
            // chime only fires for genuinely new incoming messages.
            let convoKey = "";
            let maxKnownId = 0;
            let convoPrimed = false;
            function currentConvoKey() {
                return activeReceiverId ? "dm:" + activeReceiverId : "ch:" + activeChannel;
            }

            // Fetch messages from server API
            function fetchMessages() {
                let url = `chat_center.php?api=fetch&channel=${encodeURIComponent(activeChannel)}`;
                if (activeReceiverId) {
                    url += `&receiver_id=${encodeURIComponent(activeReceiverId)}`;
                }

                fetch(url)
                    .then(res => res.json())
                    .then(data => {
                        if (!data.ok) return;
                        const msgs = data.messages || [];
                        const currentUserId = data.current_user_id;
                        if (data.current_user_role) currentUserRole = data.current_user_role;

                        // Chime for new incoming messages (never for own, never for history).
                        const fetchKey = currentConvoKey();
                        if (fetchKey !== convoKey) {
                            convoKey = fetchKey;
                            maxKnownId = 0;
                            convoPrimed = false;
                        }
                        let topId = maxKnownId;
                        let hasNewIncoming = false;
                        msgs.forEach(m => {
                            const mid = parseInt(m.id, 10) || 0;
                            if (mid > topId) topId = mid;
                            if (convoPrimed && mid > maxKnownId && parseInt(m.sender_id, 10) !== parseInt(currentUserId, 10)) {
                                hasNewIncoming = true;
                            }
                        });
                        if (hasNewIncoming) playChime();
                        maxKnownId = topId;
                        convoPrimed = true;

                        if (data.announcements_disabled) announcementsOff = true;
                        if (announcementsOff && !isAdminViewer && !activeReceiverId && activeChannel === "announcements") {
                            chatMessages.innerHTML = `
                        <div class="text-center text-muted py-5 my-auto">
                            <div class="bg-white rounded-circle p-3 d-inline-flex shadow-sm mb-3">
                                <i class="bi bi-megaphone text-secondary display-6"></i>
                            </div>
                            <h6 class="fw-bold text-dark mb-1">Announcements is disabled</h6>
                            <p class="small text-muted mb-0">Your administrator has turned off this channel.</p>
                        </div>`;
                            return;
                        }

                        // Update unread notification badges per contact
                        const unreads = data.unread_counts || {};
                        document.querySelectorAll(".contact-item[data-receiver-id]").forEach(item => {
                            const rId = parseInt(item.dataset.receiverId, 10);
                            const count = unreads[rId] || 0;
                            const subRow = item.querySelector(".contact-sub-row");
                            let badge = item.querySelector(".unread-badge");

                            if (count > 0 && rId !== parseInt(activeReceiverId, 10)) {
                                if (!badge) {
                                    badge = document.createElement("span");
                                    badge.className = "badge bg-danger rounded-pill ms-auto unread-badge";
                                    badge.style.fontSize = "0.68rem";
                                    if (subRow) {
                                        subRow.appendChild(badge);
                                    } else {
                                        item.querySelector(".contact-info").appendChild(badge);
                                    }
                                }
                                badge.textContent = count > 99 ? "99+" : count;
                            } else {
                                if (badge) badge.remove();
                            }
                        });

                        // Update online status dots per contact
                        const onlineIds = data.online_user_ids || [];
                        document.querySelectorAll(".contact-item[data-receiver-id]").forEach(item => {
                            const rId = parseInt(item.dataset.receiverId, 10);
                            const isOnline = onlineIds.includes(rId);
                            let dot = item.querySelector(".status-dot");
                            if (!dot) {
                                dot = document.createElement("span");
                                dot.className = "status-dot";
                                item.querySelector(".contact-avatar").appendChild(dot);
                            }
                            dot.className = "status-dot " + (isOnline ? "online" : "offline");
                        });

                        // Live sidebar previews (Messenger-style last message + time).
                        const dmPreviews = data.dm_previews || {};
                        document.querySelectorAll(".contact-item[data-receiver-id]").forEach(item => {
                            const rId = parseInt(item.dataset.receiverId, 10);
                            const pv = dmPreviews[rId];
                            if (!pv) return;
                            const prevEl = item.querySelector(".contact-preview");
                            const timeEl = item.querySelector(".contact-time");
                            if (prevEl) {
                                const raw = (pv.text || "").trim();
                                let label;
                                if (raw) {
                                    const mine = parseInt(pv.sender_id, 10) === parseInt(currentUserId, 10);
                                    label = (mine ? "You: " : "") + raw.slice(0, 60);
                                } else if (pv.file) {
                                    const mine = parseInt(pv.sender_id, 10) === parseInt(currentUserId, 10);
                                    label = mine ? "You sent an attachment" : "Sent you an attachment";
                                } else {
                                    label = "";
                                }
                                if (label) prevEl.textContent = label;
                                const unreadHere = (unreads[rId] || 0) > 0 && rId !== parseInt(activeReceiverId, 10);
                                prevEl.classList.toggle("has-unread", unreadHere);
                            }
                            if (timeEl && pv.time) {
                                timeEl.textContent = fmtPreviewTime(pv.time);
                            }
                        });

                        if (activeReceiverId) {
                            const isHeaderOnline = onlineIds.includes(parseInt(activeReceiverId, 10));
                            chatSubtitle.innerHTML = `<i class="bi bi-person-badge text-primary"></i> Direct Message • ${escapeHtml(activeRole)} • <span class="badge ${isHeaderOnline ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary border border-secondary-subtle'} rounded-pill" style="font-size:0.65rem;">${isHeaderOnline ? 'Active now' : 'Offline'}</span>`;
                            const headerDot = document.getElementById("chatHeaderDot");
                            if (headerDot) headerDot.className = "status-dot " + (isHeaderOnline ? "online" : "offline");
                        } else {
                            const headerDot = document.getElementById("chatHeaderDot");
                            if (headerDot) headerDot.className = "status-dot online";
                        }

                        // Total unread on the Direct tab.
                        let totalUnread = 0;
                        Object.keys(unreads).forEach(k => { totalUnread += parseInt(unreads[k], 10) || 0; });
                        const directTabBadge = document.getElementById("directTabBadge");
                        if (directTabBadge) {
                            directTabBadge.textContent = totalUnread > 99 ? "99+" : String(totalUnread);
                            directTabBadge.classList.toggle("show", totalUnread > 0);
                        }

                        // Re-order Direct pane items to bring latest chats to the top
                        const latestUsers = data.latest_chat_users || [];
                        if (latestUsers.length > 0) {
                            const dmPane = document.getElementById("paneDirect");
                            if (dmPane) {
                                [...latestUsers].reverse().forEach(uId => {
                                    const item = dmPane.querySelector(`.contact-item[data-receiver-id="${uId}"]`);
                                    const firstDirect = dmPane.querySelector(`.contact-item[data-receiver-id]`);
                                    if (item && firstDirect && item !== firstDirect) {
                                        dmPane.insertBefore(item, firstDirect);
                                    }
                                });
                            }
                        }

                        if (msgs.length === 0) {
                            chatMessages.innerHTML = `
                        <div class="text-center text-muted py-5 my-auto">
                            <div class="bg-white rounded-circle p-3 d-inline-flex shadow-sm mb-3">
                                <i class="bi bi-chat-square-text text-primary display-6"></i>
                            </div>
                            <h6 class="fw-bold text-dark mb-1">No messages yet</h6>
                            <p class="small text-muted mb-0">Be the first to leave a message in <strong>${escapeHtml(activeName)}</strong></p>
                        </div>
                    `;
                            return;
                        }

                        let html = "";
                        let lastDayKey = "";
                        let prevSenderId = null;
                        let prevMsgTime = 0;
                        msgs.forEach(m => {
                            const isMe = parseInt(m.sender_id, 10) === parseInt(currentUserId, 10);
                            const timeObj = new Date(m.created_at);
                            const timeStr = timeObj.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                            const dayKey = timeObj.toDateString();
                            if (dayKey !== lastDayKey) {
                                html += `<div class="date-divider"><span>${dayLabel(timeObj)}</span></div>`;
                                lastDayKey = dayKey;
                                prevSenderId = null;
                            }
                            // Group tight bubbles from the same sender (within 3 min, same day).
                            const compact = prevSenderId === parseInt(m.sender_id, 10) && (timeObj - prevMsgTime) < 3 * 60 * 1000;
                            prevSenderId = parseInt(m.sender_id, 10);
                            prevMsgTime = timeObj;

                            const bodyText = escapeHtml(m.message);
                            let attachHtml = "";
                            if (m.attachment_url) {
                                const attName = escapeHtml(m.attachment_name || "Attachment");
                                const attUrl = escapeAttr(m.attachment_url);
                                if (m.attachment_is_image) {
                                    attachHtml = `<div class="msg-attachment"><a href="${attUrl}" target="_blank" rel="noopener"><img class="msg-attach-img" src="${attUrl}" alt="${attName}" loading="lazy"></a></div>`;
                                } else {
                                    const icon = fileIcon(m.attachment_ext || "");
                                    attachHtml = `<div class="msg-attachment"><a class="msg-attach-file" href="${attUrl}" target="_blank" rel="noopener"><i class="bi ${icon}"></i><span><span class="att-name">${attName}</span><span class="att-hint">View / Download</span></span></a></div>`;
                                }
                            }
                            const canUnsend = isMe || currentUserRole === "admin" || currentUserRole === "supervisor";
                            let quoteHtml = "";
                            if (m.reply_sender_name || m.reply_message || m.reply_attachment_name) {
                                const qName = escapeHtml(m.reply_sender_name || "Message");
                                let qText = (m.reply_message || "").trim().slice(0, 120);
                                if (!qText && m.reply_attachment_name) qText = "📎 " + m.reply_attachment_name;
                                quoteHtml = `<div class="msg-quote" data-quote-target="${parseInt(m.reply_to, 10) || 0}" title="Jump to original message"><span class="quote-name">${qName}</span><span class="quote-text">${escapeHtml(qText) || "…"}</span></div>`;
                            }
                            const replyBtn = `<button type="button" data-reply-id="${parseInt(m.id, 10)}" data-reply-name="${escapeAttr(m.sender_name || "Message")}" data-reply-text="${escapeAttr(((m.message || "").trim() || (m.attachment_name ? "📎 " + m.attachment_name : "Attachment")).slice(0, 120))}" title="Reply"><i class="bi bi-reply-fill"></i><span class="action-label">Reply</span></button>`;
                            const unsendBtn = canUnsend ? `<button type="button" class="msg-unsend" data-unsend-id="${parseInt(m.id, 10)}" title="Unsend"><i class="bi bi-trash3-fill"></i><span class="action-label">Unsend</span></button>` : "";
                            const reactBtn = `<button type="button" data-react-id="${parseInt(m.id, 10)}" title="React"><i class="bi bi-emoji-smile"></i><span class="action-label">React</span></button>`;
                            const actionsHtml = isMe ? unsendBtn + replyBtn + reactBtn : replyBtn + reactBtn + unsendBtn;
                            let reactionsHtml = "";
                            if (m.reactions && m.reactions.length) {
                                reactionsHtml = `<div class="msg-reactions">` + m.reactions.map(r =>
                                    `<button type="button" class="msg-reaction-badge${r.mine ? " mine" : ""}" data-rx-id="${parseInt(m.id, 10)}" data-rx-emoji="${escapeAttr(r.emoji)}" title="${r.mine ? "Remove your reaction" : "React " + escapeAttr(r.emoji)}">${escapeHtml(r.emoji)}${r.count > 1 ? `<span class="rx-count">${r.count}</span>` : ""}</button>`
                                ).join("") + `</div>`;
                            }

                            // Avatar for others (spacer on grouped follow-ups keeps the thread clean).
                            let avatarHtml = "";
                            if (!isMe) {
                                if (compact) {
                                    avatarHtml = `<div class="msg-avatar-spacer"></div>`;
                                } else if (m.sender_photo_url) {
                                    avatarHtml = `<div class="msg-avatar"><img src="${escapeAttr(m.sender_photo_url)}" alt="" loading="lazy"></div>`;
                                } else {
                                    avatarHtml = `<div class="msg-avatar">${escapeHtml(initials(m.sender_name))}</div>`;
                                }
                            }
                            const headerHtml = compact ? "" : `
                            <div class="msg-header-info">
                                ${isMe ? "" : `<span class="msg-sender-name">${escapeHtml(m.sender_name)}</span>`}
                                <span class="msg-time">${timeStr}</span>
                            </div>`;

                            html += `
                        <div class="msg-group ${isMe ? 'me' : 'other'}${compact ? ' compact' : ''}" id="chat-msg-${parseInt(m.id, 10)}">
                            ${avatarHtml}
                            <div class="msg-body">
                                ${headerHtml}
                                <div class="msg-line">
                                    ${isMe ? `<div class="msg-actions">${actionsHtml}</div>` : ""}
                                    <div class="msg-bubble-box">
                                        ${quoteHtml}${bodyText ? bodyText : '<span style="opacity:.75;font-style:italic;">📎 Attachment</span>'}${attachHtml}
                                    </div>
                                    ${isMe ? "" : `<div class="msg-actions">${actionsHtml}</div>`}
                                </div>
                                ${reactionsHtml}
                            </div>
                        </div>
                    `;
                        });

                        const isAtBottom = chatMessages.scrollHeight - chatMessages.scrollTop <= chatMessages.clientHeight + 120;
                        chatMessages.innerHTML = html;
                        if (isAtBottom || chatMessages.dataset.firstLoad !== "1") {
                            chatMessages.scrollTop = chatMessages.scrollHeight;
                            chatMessages.dataset.firstLoad = "1";
                        }
                    })
                    .catch(() => { });
            }

            function escapeHtml(str) {
                return (str || "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
            }

            function escapeAttr(str) {
                return escapeHtml(str).replace(/'/g, "&#39;");
            }

            function initials(name) {
                const parts = (name || "").trim().split(/\s+/).filter(Boolean);
                if (parts.length >= 2) {
                    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
                }
                return (parts[0] || "U").substring(0, 2).toUpperCase();
            }

            function dayLabel(d) {
                const today = new Date();
                const yesterday = new Date();
                yesterday.setDate(today.getDate() - 1);
                if (d.toDateString() === today.toDateString()) return "Today";
                if (d.toDateString() === yesterday.toDateString()) return "Yesterday";
                return d.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
            }

            function fmtPreviewTime(ts) {
                if (!ts) return "";
                // Server sends "YYYY-MM-DD HH:MM:SS" — parse as local time.
                const d = new Date(String(ts).replace(" ", "T"));
                if (isNaN(d)) return "";
                const today = new Date();
                const yesterday = new Date();
                yesterday.setDate(today.getDate() - 1);
                if (d.toDateString() === today.toDateString()) {
                    return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
                }
                if (d.toDateString() === yesterday.toDateString()) return "Yesterday";
                return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
            }

            function fileIcon(ext) {
                ext = (ext || "").toLowerCase();
                if (ext === "pdf") return "bi-file-earmark-pdf-fill text-danger";
                if (ext === "doc" || ext === "docx") return "bi-file-earmark-word-fill text-primary";
                if (ext === "xls" || ext === "xlsx") return "bi-file-earmark-excel-fill text-success";
                if (ext === "ppt" || ext === "pptx") return "bi-file-earmark-slides-fill text-warning";
                if (ext === "zip") return "bi-file-earmark-zip-fill text-secondary";
                return "bi-file-earmark-arrow-down-fill";
            }

            // Attachment picker state
            const btnAttach = document.getElementById("btnAttach");
            const chatFileInput = document.getElementById("chatFileInput");
            const chatFileChip = document.getElementById("chatFileChip");
            const chatFileName = document.getElementById("chatFileName");
            const chatFileSize = document.getElementById("chatFileSize");
            const chatFileRemove = document.getElementById("chatFileRemove");
            const btnSend = document.getElementById("btnSend");
            const ALLOWED_EXT = ["jpg", "jpeg", "png", "gif", "webp", "pdf", "doc", "docx", "xls", "xlsx", "ppt", "pptx", "zip"];

            function formatBytes(bytes) {
                if (!bytes) return "";
                if (bytes < 1024) return bytes + " B";
                if (bytes < 1048576) return (bytes / 1024).toFixed(1) + " KB";
                return (bytes / 1048576).toFixed(1) + " MB";
            }

            const chatFileThumb = document.getElementById("chatFileThumb");
            let thumbUrl = null;

            function showChipThumb(file) {
                if (!chatFileThumb) return;
                if (thumbUrl) {
                    URL.revokeObjectURL(thumbUrl);
                    thumbUrl = null;
                }
                const isImage = file && file.type && file.type.startsWith("image/");
                if (isImage) {
                    try {
                        thumbUrl = URL.createObjectURL(file);
                        chatFileThumb.src = thumbUrl;
                        chatFileThumb.classList.add("show");
                        return;
                    } catch (err) { }
                }
                chatFileThumb.removeAttribute("src");
                chatFileThumb.classList.remove("show");
            }

            function clearAttachment() {
                if (chatFileInput) chatFileInput.value = "";
                if (chatFileChip) chatFileChip.classList.remove("show");
                if (chatFileName) chatFileName.textContent = "No file selected";
                if (chatFileSize) chatFileSize.textContent = "";
                if (chatFileThumb) {
                    chatFileThumb.removeAttribute("src");
                    chatFileThumb.classList.remove("show");
                }
                if (thumbUrl) {
                    try { URL.revokeObjectURL(thumbUrl); } catch (err) { }
                    thumbUrl = null;
                }
            }

            // Shared validation + chip display for picker and pasted files.
            function setAttachment(f) {
                if (!f) {
                    clearAttachment();
                    return false;
                }
                const ext = (f.name.split(".").pop() || "").toLowerCase();
                if (!ALLOWED_EXT.includes(ext)) {
                    alert("Unsupported file type. Allowed: images, PDF, Word, Excel, PowerPoint, ZIP.");
                    clearAttachment();
                    return false;
                }
                const IMAGE_EXT = ["jpg", "jpeg", "png", "gif", "webp"];
                const maxBytes = IMAGE_EXT.includes(ext) ? 500 * 1024 * 1024 : 10 * 1024 * 1024;
                const maxLabel = IMAGE_EXT.includes(ext) ? "500 MB" : "10 MB";
                if (f.size <= 0 || f.size > maxBytes) {
                    alert("File must be 1 byte to " + maxLabel + ".");
                    clearAttachment();
                    return false;
                }
                chatFileName.textContent = f.name;
                chatFileSize.textContent = "(" + formatBytes(f.size) + ")";
                showChipThumb(f);
                chatFileChip.classList.add("show");
                return true;
            }

            function placeFileInPicker(file) {
                try {
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    chatFileInput.files = dt.files;
                    return true;
                } catch (err) {
                    return false;
                }
            }

            // Reply-to state (quote a specific message in this thread)
            let replyToId = null;
            const chatReplyBar = document.getElementById("chatReplyBar");
            const chatReplyName = document.getElementById("chatReplyName");
            const chatReplyText = document.getElementById("chatReplyText");
            const chatReplyCancel = document.getElementById("chatReplyCancel");

            function clearReply() {
                replyToId = null;
                if (chatReplyBar) chatReplyBar.classList.remove("show");
                if (chatReplyName) chatReplyName.textContent = "Replying to";
                if (chatReplyText) chatReplyText.textContent = "";
            }

            if (chatReplyCancel) {
                chatReplyCancel.addEventListener("click", function () {
                    clearReply();
                    chatInput.focus();
                });
            }

            if (btnAttach && chatFileInput) {
                btnAttach.addEventListener("click", function () {
                    chatFileInput.click();
                });
                chatFileInput.addEventListener("change", function () {
                    const f = chatFileInput.files && chatFileInput.files[0];
                    if (setAttachment(f)) chatInput.focus();
                });

                // Paste screenshots / copied images straight into the composer.
                chatInput.addEventListener("paste", function (e) {
                    const items = (e.clipboardData && e.clipboardData.items) || [];
                    let imageItem = null;
                    for (let i = 0; i < items.length; i++) {
                        if (items[i].type && items[i].type.startsWith("image/")) {
                            imageItem = items[i];
                            break;
                        }
                    }
                    if (!imageItem) return; // plain text paste — default behavior
                    e.preventDefault();
                    const blob = imageItem.getAsFile();
                    if (!blob) return;
                    const mimeExt = { "image/png": "png", "image/jpeg": "jpg", "image/gif": "gif", "image/webp": "webp" };
                    const ext = mimeExt[blob.type] || "png";
                    const stamp = new Date();
                    const pad = (n) => String(n).padStart(2, "0");
                    const fname = "screenshot-" + stamp.getFullYear() + pad(stamp.getMonth() + 1) + pad(stamp.getDate())
                        + "-" + pad(stamp.getHours()) + pad(stamp.getMinutes()) + pad(stamp.getSeconds()) + "." + ext;
                    let file;
                    try {
                        file = new File([blob], fname, { type: blob.type || "image/png" });
                    } catch (err) {
                        return;
                    }
                    if (!placeFileInPicker(file)) return;
                    if (setAttachment(file)) chatInput.focus();
                });
            }

            if (chatFileRemove) {
                chatFileRemove.addEventListener("click", clearAttachment);
            }

            // Channels / Direct tab switching
            function switchPane(paneId) {
                document.querySelectorAll(".conv-tab").forEach(t => {
                    const on = t.dataset.pane === paneId;
                    t.classList.toggle("active", on);
                    t.setAttribute("aria-selected", on ? "true" : "false");
                });
                document.querySelectorAll(".chat-pane").forEach(p => {
                    p.classList.toggle("show", p.id === paneId);
                });
                // Re-apply the search filter to the newly visible pane.
                const q = (contactSearch && contactSearch.value ? contactSearch.value : "").toLowerCase();
                if (q) filterContacts(q);
            }

            function filterContacts(q) {
                document.querySelectorAll(".chat-pane.show .contact-item").forEach(item => {
                    const name = (item.dataset.name || "").toLowerCase();
                    const role = (item.dataset.role || "").toLowerCase();
                    const prev = (item.querySelector(".contact-preview") || {}).textContent || "";
                    item.style.display = (name.includes(q) || role.includes(q) || prev.toLowerCase().includes(q)) ? "" : "none";
                });
            }

            document.querySelectorAll(".conv-tab").forEach(tab => {
                tab.addEventListener("click", function () {
                    switchPane(this.dataset.pane);
                });
            });

            // Switch channels / contacts
            document.querySelectorAll(".contact-item").forEach(item => {
                item.addEventListener("click", function (e) {
                    e.preventDefault();
                    document.querySelectorAll(".contact-item").forEach(i => i.classList.remove("active"));
                    this.classList.add("active");
                    // Keep the sidebar tab in sync with the opened conversation.
                    switchPane(this.dataset.receiverId ? "paneDirect" : "paneChannels");

                    activeChannel = this.dataset.channel;
                    activeReceiverId = this.dataset.receiverId || "";
                    activeName = this.dataset.name;
                    activeRole = this.dataset.role || "";

                    chatTitle.textContent = activeName;
                    if (activeReceiverId) {
                        chatSubtitle.innerHTML = `<i class="bi bi-person-badge text-primary"></i> Direct Message • ${escapeHtml(activeRole)}`;
                        const init = this.dataset.init || activeName.substring(0, 2).toUpperCase();
                        const contactDot = this.querySelector(".status-dot");
                        const dotOnline = contactDot ? contactDot.classList.contains("online") : false;
                        const contactPhoto = this.dataset.photo || "";
                        chatHeaderAvatar.className = "chat-header-avatar";
                        const headerFace = contactPhoto
                            ? `<img src="${escapeAttr(contactPhoto)}" alt="">`
                            : escapeHtml(init);
                        chatHeaderAvatar.innerHTML = headerFace + `<span class="status-dot ${dotOnline ? "online" : "offline"}" id="chatHeaderDot"></span>`;
                    } else {
                        const icon = this.dataset.icon || "bi-hash";
                        chatSubtitle.innerHTML = `<i class="bi bi-people-fill text-primary"></i> Public Channel`;
                        chatHeaderAvatar.className = "chat-header-avatar " + (this.dataset.avatarClass || "");
                        chatHeaderAvatar.innerHTML = `<i class="bi ${icon}"></i><span class="status-dot online" id="chatHeaderDot"></span>`;
                    }

                    delete chatMessages.dataset.firstLoad;
                    chatSidebar.classList.remove("show-mobile");
                    clearReply();
                    closeRxPicker();
                    fetchMessages();
                });
            });

            // Mobile sidebar toggle
            const btnToggleMobileSidebar = document.getElementById("btnToggleMobileSidebar");
            if (btnToggleMobileSidebar) {
                btnToggleMobileSidebar.addEventListener("click", function () {
                    chatSidebar.classList.toggle("show-mobile");
                });
            }

            // Contact filter search (active pane only, includes preview text)
            const contactSearch = document.getElementById("contactSearch");
            if (contactSearch) {
                contactSearch.addEventListener("input", function () {
                    filterContacts((this.value || "").toLowerCase());
                });
            }

            // Quick action pills
            document.querySelectorAll(".quick-pill-btn").forEach(btn => {
                btn.addEventListener("click", function () {
                    chatInput.value = this.dataset.text;
                    chatInput.focus();
                });
            });

            // Send Message (text and/or attachment)
            chatForm.addEventListener("submit", function (e) {
                e.preventDefault();
                const text = chatInput.value.trim();
                const file = chatFileInput && chatFileInput.files ? chatFileInput.files[0] : null;
                if (!text && !file) {
                    chatInput.focus();
                    return;
                }

                const formData = new FormData();
                formData.append("message", text);
                formData.append("channel", activeChannel);
                if (activeReceiverId) {
                    formData.append("receiver_id", activeReceiverId);
                }
                if (replyToId) {
                    formData.append("reply_to", String(replyToId));
                }
                if (file) {
                    formData.append("attachment", file, file.name);
                }

                chatInput.value = "";
                if (btnSend) btnSend.disabled = true;

                fetch("chat_center.php?api=send", {
                    method: "POST",
                    body: formData
                })
                    .then(res => res.json())
                    .then(data => {
                        if (btnSend) btnSend.disabled = false;
                        if (data.ok) {
                            clearAttachment();
                            clearReply();
                            fetchMessages();
                        } else {
                            alert(data.error || "Could not send message.");
                        }
                    })
                    .catch(() => {
                        if (btnSend) btnSend.disabled = false;
                        alert("Network error. Please try again.");
                    });
            });

            // Emoji reaction picker + helpers
            const rxPicker = document.getElementById("rxPicker");
            let rxTargetId = null;

            function closeRxPicker() {
                if (rxPicker) rxPicker.classList.remove("show");
                rxTargetId = null;
            }

            function openRxPicker(msgId, anchorBtn) {
                if (!rxPicker || !msgId || !anchorBtn) return;
                rxTargetId = msgId;
                rxPicker.classList.add("show");
                const r = anchorBtn.getBoundingClientRect();
                const pw = rxPicker.offsetWidth || 300;
                const ph = rxPicker.offsetHeight || 48;
                let left = r.left + r.width / 2 - pw / 2;
                left = Math.min(Math.max(8, left), window.innerWidth - pw - 8);
                let top = r.top - ph - 8;
                if (top < 8) top = r.bottom + 8;
                rxPicker.style.left = left + "px";
                rxPicker.style.top = top + "px";
            }

            function sendReaction(msgId, emoji) {
                if (!msgId || !emoji) return;
                const fd = new FormData();
                fd.append("message_id", String(msgId));
                fd.append("emoji", emoji);
                fetch("chat_center.php?api=react", {
                    method: "POST",
                    body: fd
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.ok) {
                            fetchMessages();
                        } else {
                            alert(data.error || "Could not save reaction.");
                        }
                    })
                    .catch(() => { });
            }

            if (rxPicker) {
                rxPicker.querySelectorAll("button[data-emoji]").forEach(b => {
                    b.addEventListener("click", function () {
                        sendReaction(rxTargetId, this.dataset.emoji);
                        closeRxPicker();
                    });
                });
                document.addEventListener("click", function (e) {
                    if (!rxPicker.classList.contains("show")) return;
                    if (rxPicker.contains(e.target)) return;
                    if (e.target.closest && e.target.closest("[data-react-id]")) return;
                    closeRxPicker();
                });
                document.addEventListener("keydown", function (e) {
                    if (e.key === "Escape") closeRxPicker();
                });
                chatMessages.addEventListener("scroll", closeRxPicker, { passive: true });
            }

            // Reply + quote-jump + reactions (event delegation — messages re-render on poll)
            chatMessages.addEventListener("click", function (e) {
                const rxBadge = e.target.closest(".msg-reaction-badge");
                if (rxBadge) {
                    sendReaction(parseInt(rxBadge.dataset.rxId, 10), rxBadge.dataset.rxEmoji);
                    return;
                }
                const reactBtn = e.target.closest("[data-react-id]");
                if (reactBtn) {
                    const mid = parseInt(reactBtn.dataset.reactId, 10) || null;
                    if (rxPicker && rxPicker.classList.contains("show") && rxTargetId === mid) {
                        closeRxPicker();
                    } else {
                        openRxPicker(mid, reactBtn);
                    }
                    return;
                }
                const replyBtn = e.target.closest("[data-reply-id]");
                if (replyBtn) {
                    replyToId = parseInt(replyBtn.dataset.replyId, 10) || null;
                    if (replyToId) {
                        if (chatReplyName) chatReplyName.textContent = "Replying to " + (replyBtn.dataset.replyName || "message");
                        if (chatReplyText) chatReplyText.textContent = replyBtn.dataset.replyText || "";
                        if (chatReplyBar) chatReplyBar.classList.add("show");
                        chatInput.focus();
                    }
                    return;
                }
                const quote = e.target.closest("[data-quote-target]");
                if (quote) {
                    const targetId = parseInt(quote.dataset.quoteTarget, 10);
                    const target = targetId ? document.getElementById("chat-msg-" + targetId) : null;
                    if (target) {
                        target.scrollIntoView({ behavior: "smooth", block: "center" });
                        target.classList.remove("msg-flash");
                        void target.offsetWidth;
                        target.classList.add("msg-flash");
                    }
                    return;
                }
            });

            // Unsend message (event delegation — messages re-render on poll)
            chatMessages.addEventListener("click", function (e) {
                const btn = e.target.closest("[data-unsend-id]");
                if (!btn) return;
                const msgId = parseInt(btn.dataset.unsendId, 10);
                if (!msgId) return;
                if (!confirm("Unsend this message? It will be removed for everyone.")) return;
                btn.disabled = true;
                const fd = new FormData();
                fd.append("id", String(msgId));
                fetch("chat_center.php?api=unsend", {
                    method: "POST",
                    body: fd
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.ok) {
                            fetchMessages();
                        } else {
                            btn.disabled = false;
                            alert(data.error || "Could not unsend message.");
                        }
                    })
                    .catch(() => {
                        btn.disabled = false;
                        alert("Network error. Please try again.");
                    });
            });

            document.getElementById("btnRefreshChat").addEventListener("click", fetchMessages);

            // Admin: enable / disable the Announcements channel freely.
            const btnAnnounceToggle = document.getElementById("btnAnnounceToggle");
            if (btnAnnounceToggle) {
                btnAnnounceToggle.addEventListener("click", function () {
                    btnAnnounceToggle.disabled = true;
                    fetch("chat_center.php?api=toggle_announcements", {
                        method: "POST",
                        body: new FormData()
                    })
                        .then(res => res.json())
                        .then(data => {
                            if (data.ok) {
                                location.reload();
                            } else {
                                btnAnnounceToggle.disabled = false;
                                alert(data.error || "Could not save setting.");
                            }
                        })
                        .catch(() => {
                            btnAnnounceToggle.disabled = false;
                            alert("Network error. Please try again.");
                        });
                });
            }

            // If the default channel is hidden (announcements disabled),
            // fall back to the first visible conversation.
            (function fallbackHiddenChannel() {
                const hasAnnounce = !!document.querySelector('.contact-item[data-channel="announcements"]');
                if (!activeReceiverId && activeChannel === "announcements" && !hasAnnounce) {
                    const first = document.querySelector(".chat-pane.show .contact-item, .contact-item");
                    if (first) {
                        first.click();
                    } else {
                        chatMessages.innerHTML = `
                        <div class="text-center text-muted py-5 my-auto">
                            <div class="bg-white rounded-circle p-3 d-inline-flex shadow-sm mb-3">
                                <i class="bi bi-chat-square-text text-primary display-6"></i>
                            </div>
                            <h6 class="fw-bold text-dark mb-1">No conversations available</h6>
                            <p class="small text-muted mb-0">The Announcements channel is disabled and you have no direct messages yet.</p>
                        </div>`;
                    }
                }
            })();

            // Sound on/off toggle (persisted).
            const btnSoundToggle = document.getElementById("btnSoundToggle");
            const soundToggleIcon = document.getElementById("soundToggleIcon");
            function syncSoundBtn() {
                if (soundToggleIcon) {
                    soundToggleIcon.className = soundOn ? "bi bi-bell-fill" : "bi bi-bell-slash";
                }
                if (btnSoundToggle) {
                    btnSoundToggle.setAttribute("title", soundOn ? "Message sound on — click to mute" : "Message sound off — click to unmute");
                    btnSoundToggle.classList.toggle("text-secondary", soundOn);
                    btnSoundToggle.classList.toggle("text-muted", !soundOn);
                }
            }
            if (btnSoundToggle) {
                btnSoundToggle.addEventListener("click", function () {
                    soundOn = !soundOn;
                    try { localStorage.setItem("cc_sound", soundOn ? "1" : "0"); } catch (err) {}
                    syncSoundBtn();
                    if (soundOn) playChime();
                });
            }
            syncSoundBtn();

            // Initial fetch and 4-second polling
            fetchMessages();
            setInterval(fetchMessages, 4000);
        });
    </script>
</body>

</html>