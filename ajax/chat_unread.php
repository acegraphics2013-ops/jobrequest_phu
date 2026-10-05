<?php
// Unread job + publication message counts for list badges + 30s polling.
ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
checkLogin();
header('Content-Type: application/json; charset=utf-8');

function unreadJson(array $payload, int $status = 200) {
    if (ob_get_length()) { ob_clean(); }
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

set_exception_handler(function (Throwable $e) {
    error_log('chat_unread error: ' . $e->getMessage());
    unreadJson(['ok' => false, 'message' => 'Server error.'], 500);
});

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) ($_SESSION['role'] ?? 'user');
if ($userId <= 0) unreadJson(['ok' => false, 'message' => 'Unauthenticated.'], 401);

$isStaff = in_array($role, ['staff', 'supervisor', 'admin'], true);
$scope = strtolower(trim((string) ($_GET['scope'] ?? 'all')));

// Optional id filters to keep polling payload small.
$jobIds = [];
if (isset($_GET['ids']) && trim((string) $_GET['ids']) !== '') {
    foreach (explode(',', (string) $_GET['ids']) as $part) {
        $id = (int) trim($part);
        if ($id > 0) $jobIds[] = $id;
    }
    $jobIds = array_values(array_unique($jobIds));
    if (count($jobIds) > 500) $jobIds = array_slice($jobIds, 0, 500);
}

$pubKeys = [];
if (isset($_GET['keys']) && trim((string) $_GET['keys']) !== '') {
    foreach (explode(',', (string) $_GET['keys']) as $part) {
        $part = trim($part);
        if (preg_match('/^(event|recognition|contribution):(\d+)$/', $part, $m)) {
            $pubKeys[] = ['kind' => $m[1], 'id' => (int) $m[2]];
        }
    }
    if (count($pubKeys) > 500) $pubKeys = array_slice($pubKeys, 0, 500);
}

$result = ['ok' => true, 'unread' => [], 'latest' => [], 'total' => 0, 'pub_unread' => [], 'pub_latest' => [], 'pub_total' => 0];

try {
    // ---------------- Job request messages ----------------
    if (in_array($scope, ['all', 'jobs', 'job'], true)) {
        $tbl = $conn->query("SHOW TABLES LIKE 'request_messages'");
        if ($tbl && $tbl->num_rows > 0) {
            $internalFilter = $isStaff ? '' : ' AND m.is_internal = 0';
            $idFilter = '';
            if ($jobIds) {
                $idList = implode(',', array_map('intval', $jobIds));
                $idFilter = " AND m.request_id IN ($idList)";
            }
            // Count messages after last read, excluding own messages.
            $sql = "SELECT m.request_id, COUNT(*) AS unread
                    FROM request_messages m
                    LEFT JOIN request_message_reads r
                      ON r.request_id = m.request_id AND r.user_id = ?
                    WHERE m.id > COALESCE(r.last_read_message_id, 0)
                      AND m.sender_id <> ?{$internalFilter}{$idFilter}
                    GROUP BY m.request_id";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $userId);
                $stmt->execute();
                foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                    $rid = (int) $row['request_id'];
                    $cnt = (int) $row['unread'];
                    if ($cnt > 0) {
                        $result['unread'][(string) $rid] = $cnt;
                        $result['total'] += $cnt;
                    }
                }
                $stmt->close();
            }

            // Latest visible message preview per thread (read or unread) so
            // list cards always reflect the conversation, not just new mail.
            $latestIds = $jobIds ?: array_map('intval', array_keys($result['unread']));
            if ($latestIds) {
                $unreadIds = implode(',', array_map('intval', $latestIds));
                $lsql = "SELECT m.request_id, m.sender_name, m.sender_role, m.message, m.created_at
                         FROM request_messages m
                         INNER JOIN (
                           SELECT request_id, MAX(id) AS max_id
                           FROM request_messages
                           WHERE request_id IN ($unreadIds)" . ($isStaff ? '' : ' AND is_internal = 0') . "
                           GROUP BY request_id
                         ) t ON t.request_id = m.request_id AND t.max_id = m.id";
                $lres = $conn->query($lsql);
                if ($lres) {
                    while ($lr = $lres->fetch_assoc()) {
                        $msg = trim((string) ($lr['message'] ?? ''));
                        if ($msg === '') $msg = '(attachment)';
                        if (mb_strlen($msg) > 120) $msg = mb_substr($msg, 0, 120) . '…';
                        $result['latest'][(string) $lr['request_id']] = [
                            'sender' => (string) ($lr['sender_name'] ?? ''),
                            'role' => (string) ($lr['sender_role'] ?? ''),
                            'message' => $msg,
                            'created_at' => (string) ($lr['created_at'] ?? ''),
                        ];
                    }
                }
            }
        }
    }

    // ---------------- Publication messages ----------------
    if (in_array($scope, ['all', 'pubs', 'pub', 'publications'], true)) {
        $tbl = $conn->query("SHOW TABLES LIKE 'publication_messages'");
        if ($tbl && $tbl->num_rows > 0) {
            $internalFilter = $isStaff ? '' : ' AND m.is_internal = 0';
            $keyFilter = '';
            if ($pubKeys) {
                $parts = [];
                foreach ($pubKeys as $k) {
                    $parts[] = "(m.kind = '" . $conn->real_escape_string($k['kind']) . "' AND m.publication_id = " . (int) $k['id'] . ")";
                }
                if ($parts) $keyFilter = ' AND (' . implode(' OR ', $parts) . ')';
            }
            $sql = "SELECT m.kind, m.publication_id, COUNT(*) AS unread
                    FROM publication_messages m
                    LEFT JOIN publication_message_reads r
                      ON r.kind = m.kind AND r.publication_id = m.publication_id AND r.user_id = ?
                    WHERE m.id > COALESCE(r.last_read_message_id, 0)
                      AND m.sender_id <> ?{$internalFilter}{$keyFilter}
                    GROUP BY m.kind, m.publication_id";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $userId);
                $stmt->execute();
                foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                    $key = (string) $row['kind'] . ':' . (int) $row['publication_id'];
                    $cnt = (int) $row['unread'];
                    if ($cnt > 0) {
                        $result['pub_unread'][$key] = $cnt;
                        $result['pub_total'] += $cnt;
                    }
                }
                $stmt->close();
            }

            // Latest preview per visible publication thread (read or unread).
            $latestPubKeys = [];
            if ($pubKeys) {
                foreach ($pubKeys as $k) {
                    $latestPubKeys[] = $k['kind'] . ':' . $k['id'];
                }
            } else {
                $latestPubKeys = array_keys($result['pub_unread']);
            }
            if (!empty($latestPubKeys)) {
                foreach ($latestPubKeys as $pubKey) {
                    $kp = explode(':', $pubKey, 2);
                    if (count($kp) !== 2) continue;
                    [$pkind, $pid] = $kp;
                    $pid = (int) $pid;
                    if (!in_array($pkind, ['event', 'recognition', 'contribution'], true) || $pid <= 0) continue;
                    $lstmt = $conn->prepare(
                        "SELECT sender_name, message, created_at FROM publication_messages
                         WHERE kind = ? AND publication_id = ?" . ($isStaff ? '' : ' AND is_internal = 0') . "
                         ORDER BY id DESC LIMIT 1"
                    );
                    if (!$lstmt) continue;
                    $lstmt->bind_param('si', $pkind, $pid);
                    $lstmt->execute();
                    $lr = $lstmt->get_result()->fetch_assoc();
                    $lstmt->close();
                    if (!$lr) continue;
                    $msg = trim((string) ($lr['message'] ?? ''));
                    if ($msg === '') $msg = '(attachment)';
                    if (mb_strlen($msg) > 120) $msg = mb_substr($msg, 0, 120) . '…';
                    $result['pub_latest'][$pubKey] = [
                        'sender' => (string) ($lr['sender_name'] ?? ''),
                        'message' => $msg,
                        'created_at' => (string) ($lr['created_at'] ?? ''),
                    ];
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('chat_unread error: ' . $e->getMessage());
    unreadJson(['ok' => false, 'message' => 'Server error.'], 500);
}

unreadJson($result);
