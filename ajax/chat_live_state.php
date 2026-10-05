<?php
// Shared live-chat state (typing indicator + online presence).
// One row per viewer per thread, refreshed by heartbeat POSTs.

function chatLiveEnsureTable(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS chat_live_state (
            scope VARCHAR(10) NOT NULL,
            request_id INT NOT NULL DEFAULT 0,
            kind VARCHAR(20) NOT NULL DEFAULT '',
            publication_id INT NOT NULL DEFAULT 0,
            user_id INT NOT NULL,
            user_name VARCHAR(150) NOT NULL,
            user_role VARCHAR(30) NOT NULL DEFAULT 'user',
            is_typing TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (scope, request_id, kind, publication_id, user_id),
            INDEX idx_live_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function chatLiveHeartbeat(
    mysqli $conn,
    string $scope,
    int $requestId,
    string $kind,
    int $publicationId,
    int $userId,
    string $userName,
    string $userRole,
    int $isTyping
): void {
    chatLiveEnsureTable($conn);

    $stmt = $conn->prepare(
        "INSERT INTO chat_live_state
            (scope, request_id, kind, publication_id, user_id, user_name, user_role, is_typing, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            user_name = VALUES(user_name),
            user_role = VALUES(user_role),
            is_typing = VALUES(is_typing),
            updated_at = NOW()"
    );

    if (!$stmt) {
        return;
    }

    $stmt->bind_param(
        "sisssssi",
        $scope,
        $requestId,
        $kind,
        $publicationId,
        $userId,
        $userName,
        $userRole,
        $isTyping
    );
    $stmt->execute();
    $stmt->close();
}

function chatLiveRead(
    mysqli $conn,
    string $scope,
    int $requestId,
    string $kind,
    int $publicationId,
    int $selfId
): array {
    $typers = [];
    $online = [];

    try {
        $stmt = $conn->prepare(
            "SELECT user_id, user_name, user_role, is_typing, updated_at
             FROM chat_live_state
             WHERE scope = ? AND request_id = ? AND kind = ? AND publication_id = ?
               AND user_id <> ?
               AND updated_at > (NOW() - INTERVAL 30 SECOND)
             ORDER BY updated_at DESC
             LIMIT 20"
        );

        if (!$stmt) {
            return ["typers" => [], "online" => []];
        }

        $stmt->bind_param("sisii", $scope, $requestId, $kind, $publicationId, $selfId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as $row) {
            $entry = [
                "id" => (int) $row["user_id"],
                "name" => (string) $row["user_name"],
                "role" => (string) $row["user_role"],
            ];
            $online[] = $entry;

            $isTyping = (int) ($row["is_typing"] ?? 0) === 1;
            $fresh = strtotime((string) ($row["updated_at"] ?? "")) > (time() - 5);

            if ($isTyping && $fresh) {
                $typers[] = $entry;
            }
        }
    } catch (Throwable $e) {
    }

    return ["typers" => $typers, "online" => $online];
}
