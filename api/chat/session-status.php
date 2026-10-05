<?php
declare(strict_types=1);

// Password Authentication & Session Status Validation Endpoint for PHU Floating Chat Unlock
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

require_once __DIR__ . '/../../includes/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    echo json_encode([
        'success' => false,
        'authenticated' => false,
        'message' => 'Your session has expired. Please sign in again.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Handle Password Validation on POST Request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $postData = json_decode((string)$rawInput, true);
    if (!is_array($postData)) {
        $postData = $_POST;
    }

    $password = (string)($postData['password'] ?? '');

    if ($password === '') {
        echo json_encode([
            'success' => false,
            'authenticated' => false,
            'message' => 'Please enter your account password.'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    try {
        $stmt = $conn->prepare("SELECT id, password FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (Throwable $e) {
        $user = null;
    }

    if (!$user || empty($user['password'])) {
        echo json_encode([
            'success' => false,
            'authenticated' => false,
            'message' => 'User account not found.'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $dbPass = (string) $user['password'];
    $isMatch = false;

    if (class_exists('\Illuminate\Support\Facades\Hash')) {
        try {
            $isMatch = \Illuminate\Support\Facades\Hash::check($password, $dbPass);
        } catch (Throwable $e) {
        }
    }

    if (!$isMatch) {
        $isMatch = password_verify($password, $dbPass);
    }

    if (!$isMatch && md5($password) === $dbPass) {
        $isMatch = true;
    }

    if (!$isMatch && ($password === $dbPass || trim($password) === $dbPass)) {
        $isMatch = true;
    }

    if ($isMatch) {
        echo json_encode([
            'success' => true,
            'authenticated' => true,
            'message' => 'Password verified successfully.',
            'user' => [
                'id' => $userId,
                'name' => (string) ($_SESSION['name'] ?? 'User')
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'success' => false,
        'authenticated' => false,
        'message' => 'Incorrect password. Please try again.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// GET Request Status Check
echo json_encode([
    'success' => true,
    'authenticated' => true,
    'user' => [
        'id' => $userId,
        'name' => (string) ($_SESSION['name'] ?? 'User'),
        'role' => (string) ($_SESSION['role'] ?? 'user'),
        'department' => (string) ($_SESSION['department'] ?? '')
    ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
