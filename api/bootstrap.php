<?php

// Handle CORS Preflight and headers
$allowedOrigins = [
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'http://192.168.3.168:3000',
    'http://192.168.3.168'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!empty($origin)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
} else {
    header("Access-Control-Allow-Origin: *");
}


header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// Include existing system configuration & DB connection
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/laravel_bootstrap.php';

/**
 * Standardized JSON response helper
 */
function jsonResponse(bool $success, string $message, array $data = [], int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Parses incoming JSON body or $_POST inputs
 */
function getJsonInput(): array
{
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST ?? [];
}

/**
 * Enforces authenticated session check for API endpoints
 */
function requireApiAuth(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if (empty($_SESSION['user_id'])) {
        jsonResponse(false, 'Unauthorized. Session expired or invalid.', [], 401);
    }

    
    return [
        'user_id'    => (int)$_SESSION['user_id'],
        'name'       => $_SESSION['name'] ?? '',
        'email'      => $_SESSION['email'] ?? '',
        'role'       => $_SESSION['role'] ?? '',
        'department' => $_SESSION['department'] ?? ''
    ];
}

/**
 * Enforces role restriction for API endpoints
 */
function requireApiRole(array $allowedRoles): array
{
    $user = requireApiAuth();
    if (!in_array($user['role'], $allowedRoles, true)) {
        jsonResponse(false, 'Forbidden. Insufficient permissions for this action.', [], 403);
    }
    return $user;
}
