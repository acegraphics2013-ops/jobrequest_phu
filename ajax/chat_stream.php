<?php
// Live stream (SSE) for job request threads: new messages + typing + presence.
// EventSource auto-reconnects; each connection lives ~25s (fits default PHP limits).
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
$userName = trim((string) ($_SESSION['name'] ?? $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User'));
$userRole = (string) ($_SESSION['role'] ?? 'user');
$requestId = filter_input(INPUT_GET, 'request_id', FILTER_VALIDATE_INT) ?: 0;

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');

if ($userId <= 0 || $requestId <= 0) {
    echo "event: error\ndata: " . json_encode(['message' => 'Access denied.']) . "\n\n";
    flush();
    exit;
}

// Staff/supervisor/admin see everything; requestors see non-internal + own threads only.
$allowed = false;
if (in_array($userRole, ['staff', 'supervisor', 'admin'], true)) {
    $allowed = true;
} else {
    $stmt = $conn->prepare('SELECT id FROM job_requests WHERE id = ? AND requestor = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('ii', $requestId, $userId);
        $stmt->execute();
        $allowed = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (!$allowed) {
    echo "event: error\ndata: " . json_encode(['message' => 'Access denied.']) . "\n\n";
    flush();
    exit;
}

// Release the session lock so heartbeats from this same user don't block.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$isStaff = in_array($userRole, ['staff', 'supervisor', 'admin'], true);
$internalFilter = $isStaff ? '' : ' AND m.is_internal = 0';
$lastId = filter_input(INPUT_GET, 'last_id', FILTER_VALIDATE_INT) ?: 0;
$lastLiveSig = '';
$lastMaxSig = '';

// Seed last_id with current max so connecting doesn't replay history.
try {
    $seed = $conn->query("SELECT MAX(id) AS max_id FROM request_messages WHERE request_id = " . (int) $requestId);
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
        // New messages since last seen.
        $stmt = $conn->prepare(
            "SELECT m.id, m.request_id, m.sender_id, m.sender_name, m.sender_role, m.message,
                    m.attachment, m.attachment_original_name, m.is_internal, m.reply_to, m.created_at,
                    r.sender_name AS reply_sender_name, r.message AS reply_message
             FROM request_messages m
             LEFT JOIN request_messages r ON r.id = m.reply_to AND r.request_id = m.request_id
             WHERE m.request_id = ? AND m.id > ?{$internalFilter}
             ORDER BY m.id ASC LIMIT 50"
        );

        $newMessages = [];

        if ($stmt) {
            $stmt->bind_param('ii', $requestId, $lastId);
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
                    ? uploadFileUrl('chat', (string) $row['attachment'])
                    : null;
                $newMessages[] = $row;
                $lastId = max($lastId, $row['id']);
            }
        }

        if ($newMessages) {
            echo "event: messages\n";
            echo 'data: ' . json_encode(['messages' => $newMessages], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
        }

        // Typing + presence snapshot (emit only on change).
        $live = chatLiveRead($conn, 'job', $requestId, '', 0, $userId);
        $liveSig = md5(json_encode($live));

        if ($liveSig !== $lastLiveSig) {
            $lastLiveSig = $liveSig;
            echo "event: live\n";
            echo 'data: ' . json_encode($live, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
        }

        // Keep-alive comment every loop so proxies don't buffer.
        echo ": ping\n\n";

        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    } catch (Throwable $e) {
        // Transient DB hiccup: keep the stream alive.
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
