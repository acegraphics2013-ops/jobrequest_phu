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
    error_log('Chat error: ' . $exception->getMessage());
    chatJson([
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
    error_log('Chat fatal: ' . ($error['message'] ?? '') . ' in ' . ($error['file'] ?? '') . ' line ' . ($error['line'] ?? ''));
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'message' => 'Server error. Please try again.'
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
});

function chatJson(array $payload, int $status = 200) {
    if (ob_get_length()) { ob_clean(); }
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function chatCurrentUser(): array {
    return [
        'id' => (int) ($_SESSION['user_id'] ?? 0),
        'name' => trim((string) ($_SESSION['name'] ?? $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User')),
        'role' => (string) ($_SESSION['role'] ?? 'user'),
    ];
}

function chatCanAccessRequest(mysqli $conn, int $requestId, array $user): bool {
    if ($requestId <= 0 || $user['id'] <= 0) return false;
    if (in_array($user['role'], ['staff', 'supervisor', 'admin'], true)) return true;
    $stmt = $conn->prepare('SELECT id FROM job_requests WHERE id = ? AND requestor = ? LIMIT 1');
    $stmt->bind_param('ii', $requestId, $user['id']);
    $stmt->execute();
    $allowed = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $allowed;
}

function chatSafeName(string $name): string {
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($name));
    return trim((string) $name, '._-') ?: 'attachment';
}
