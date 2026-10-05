<?php
declare(strict_types=1);

// Shared helper for PHU Desktop Companion API Endpoints
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Handle CORS for Tauri desktop app client
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: " . $origin);
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

function jsonSuccess(mixed $data = null, ?string $message = null): void
{
    echo json_encode([
        'success' => true,
        'data' => $data,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonError(string $message = 'Unable to complete request.', int $statusCode = 400, mixed $data = null): void
{
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'data' => $data,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function getJsonInput(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) return $_POST;
    $data = json_decode($raw, true);
    return is_array($data) ? array_merge($_POST, $data) : $_POST;
}

function requireDesktopAuth(mysqli $conn): array
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        jsonError('Authentication required. Session expired.', 401);
    }

    // Touch last_seen
    try {
        $stmt = $conn->prepare("UPDATE users SET last_seen = NOW() WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {}

    return [
        'id' => $userId,
        'name' => (string) ($_SESSION['name'] ?? ''),
        'role' => (string) ($_SESSION['role'] ?? 'staff'),
        'email' => (string) ($_SESSION['email'] ?? ''),
        'department' => (string) ($_SESSION['department'] ?? '')
    ];
}
