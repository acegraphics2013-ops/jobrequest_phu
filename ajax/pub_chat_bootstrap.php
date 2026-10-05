<?php
// Keep AJAX responses valid JSON even when PHP warnings occur.
ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
checkLogin();
header('Content-Type: application/json; charset=utf-8');

set_exception_handler(function (Throwable $exception) {
    error_log('Pub chat error: ' . $exception->getMessage());
    pubChatJson([
        'ok' => false,
        'message' => 'Server error. Please try again.'
    ], 500);
});

register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (ob_get_length()) { ob_clean(); }
    error_log('Pub chat fatal: ' . ($error['message'] ?? '') . ' in ' . ($error['file'] ?? '') . ' line ' . ($error['line'] ?? ''));
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'message' => 'Server error. Please try again.'
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
});

function pubChatJson(array $payload, int $status = 200) {
    if (ob_get_length()) { ob_clean(); }
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function pubChatCurrentUser(): array {
    return [
        'id' => (int) ($_SESSION['user_id'] ?? 0),
        'name' => trim((string) ($_SESSION['name'] ?? $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User')),
        'role' => (string) ($_SESSION['role'] ?? 'user'),
    ];
}

function pubChatTableFor(string $kind): string {
    if ($kind === 'recognition') {
        return 'publication_recognition_submissions';
    }

    if ($kind === 'contribution') {
        return 'publication_content_contributions';
    }

    return 'publication_event_submissions';
}

function pubChatCanAccess(mysqli $conn, string $kind, int $publicationId, array $user): bool {
    if (!in_array($kind, ['event', 'recognition', 'contribution'], true)) return false;
    if ($publicationId <= 0 || $user['id'] <= 0) return false;
    if (in_array($user['role'], ['staff', 'supervisor', 'admin'], true)) return true;
    $table = pubChatTableFor($kind);
    $stmt = $conn->prepare("SELECT id FROM `{$table}` WHERE id = ? AND submitted_by = ? LIMIT 1");
    $stmt->bind_param('ii', $publicationId, $user['id']);
    $stmt->execute();
    $allowed = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $allowed;
}

function pubChatSafeName(string $name): string {
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($name));
    return trim((string) $name, '._-') ?: 'attachment';
}

function pubChatEnsureTables(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS publication_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(20) NOT NULL,
            publication_id INT NOT NULL,
            sender_id INT NOT NULL,
            sender_name VARCHAR(150) NOT NULL,
            sender_role VARCHAR(30) NOT NULL DEFAULT 'user',
            message TEXT NULL,
            attachment VARCHAR(255) NULL,
            attachment_original_name VARCHAR(255) NULL,
            is_internal TINYINT(1) NOT NULL DEFAULT 0,
            reply_to BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pub_msg_lookup (kind, publication_id, id),
            INDEX idx_pub_msg_sender (sender_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS publication_message_reads (
            kind VARCHAR(20) NOT NULL,
            publication_id INT NOT NULL,
            user_id INT NOT NULL,
            last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (kind, publication_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // Reply FK best-effort (ignore if it already exists).
    try {
        $conn->query(
            "ALTER TABLE publication_messages
             ADD CONSTRAINT fk_pub_msg_reply FOREIGN KEY (reply_to)
             REFERENCES publication_messages(id) ON DELETE SET NULL"
        );
    } catch (Throwable $e) {
    }
}
