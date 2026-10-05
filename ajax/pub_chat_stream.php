<?php
// Live stream (SSE) for publication threads: new messages + typing + presence.
@ini_set('display_errors', '0');
error_reporting(E_ALL);
@set_time_limit(30);
ignore_user_abort(true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/chat_live_state.php';

checkLogin();

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userRole = (string) ($_SESSION['role'] ?? 'user');
$kind = trim((string) ($_GET['kind'] ?? ''));
$publicationId = filter_input(INPUT_GET, 'publication_id', FILTER_VALIDATE_INT) ?: 0;

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');

$tableFor = function (string $k): string {
    if ($k === 'recognition') return 'publication_recognition_submissions';
    if ($k === 'contribution') return 'publication_content_contributions';
    return 'publication_event_submissions';
};

$allowed = false;

if ($userId > 0 && in_array($kind, ['event', 'recognition', 'contribution'], true) && $publicationId > 0) {
    if (in_array($userRole, ['staff', 'supervisor', 'admin'], true)) {
        $allowed = true;
    } else {
        $table = $tableFor($kind);
        $stmt = $conn->prepare("SELECT id FROM `{$table}` WHERE id = ? AND submitted_by = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ii', $publicationId, $userId);
            $stmt->execute();
            $allowed = (bool) $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }
}

if (!$allowed) {
    echo "event: error\ndata: " . json_encode(['message' => 'Access denied.']) . "\n\n";
    flush();
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$isStaff = in_array($userRole, ['staff', 'supervisor', 'admin'], true);
$internalFilter = $isStaff ? '' : ' AND m.is_internal = 0';
$lastId = filter_input(INPUT_GET, 'last_id', FILTER_VALIDATE_INT) ?: 0;
$lastLiveSig = '';

try {
    $seed = $conn->query(
        "SELECT MAX(id) AS max_id FROM publication_messages
         WHERE kind = '" . $conn->real_escape_string($kind) . "' AND publication_id = " . (int) $publicationId
    );
    if ($seed && ($seedRow = $seed->fetch_assoc()) && $seedRow['max_id'] !== null && $lastId <= 0) {
        $lastId = (int) $seedRow['max_id'];
    }
} catch (Throwable $e) {
}

$started = time();

while ((time() - $started) < 25) {
    if (connection_aborted()) {
        break;
    }

    try {
        $stmt = $conn->prepare(
            "SELECT m.id, m.kind, m.publication_id, m.sender_id, m.sender_name, m.sender_role, m.message,
                    m.attachment, m.attachment_original_name, m.is_internal, m.reply_to, m.created_at,
                    r.sender_name AS reply_sender_name, r.message AS reply_message
             FROM publication_messages m
             LEFT JOIN publication_messages r ON r.id = m.reply_to
             WHERE m.kind = ? AND m.publication_id = ? AND m.id > ?{$internalFilter}
             ORDER BY m.id ASC LIMIT 50"
        );

        $newMessages = [];

        if ($stmt) {
            $stmt->bind_param('sii', $kind, $publicationId, $lastId);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($rows as $row) {
                $row['id'] = (int) $row['id'];
                $row['sender_id'] = (int) $row['sender_id'];
                $row['is_internal'] = (int) $row['is_internal'];
                $row['mine'] = $row['sender_id'] === $userId;
                $row['created_label'] = date('M d, Y g:i A', strtotime($row['created_at']));
                $row['attachment_url'] = $row['attachment']
                    ? uploadFileUrl('pub_chat', (string) $row['attachment'])
                    : null;
                $newMessages[] = $row;
                $lastId = max($lastId, $row['id']);
            }
        }

        if ($newMessages) {
            echo "event: messages\n";
            echo 'data: ' . json_encode(['messages' => $newMessages], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
        }

        $live = chatLiveRead($conn, 'pub', 0, $kind, $publicationId, $userId);
        $liveSig = md5(json_encode($live));

        if ($liveSig !== $lastLiveSig) {
            $lastLiveSig = $liveSig;
            echo "event: live\n";
            echo 'data: ' . json_encode($live, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
        }

        echo ": ping\n\n";

        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    } catch (Throwable $e) {
        echo ": retry\n\n";
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }

    sleep(1);
}

echo "event: done\ndata: {}\n\n";
flush();
