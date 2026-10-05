<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$input = getJsonInput();
$email = strtolower(trim((string)($input['email'] ?? '')));
$password = (string)($input['password'] ?? '');

if ($email === '' || $password === '') {
    jsonError('Please enter your email address and password.');
}

$hasPhotoCol = false;
try {
    $colCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'photo'");
    $hasPhotoCol = (bool) ($colCheck && $colCheck->num_rows > 0);
} catch (Throwable $e) {}

$stmt = $conn->prepare(
    "SELECT id, name, email, password, role, department, status" . ($hasPhotoCol ? ", photo" : "") . "
     FROM users
     WHERE LOWER(email) = ?
     LIMIT 1"
);
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    jsonError('Account not found.', 404);
}

if (($user['status'] ?? 'Active') !== 'Active') {
    jsonError('Your account is currently inactive. Please contact administrator.', 403);
}

$dbPass = (string) $user['password'];
$isMatch = false;

if (class_exists('\Illuminate\Support\Facades\Hash')) {
    try {
        $isMatch = \Illuminate\Support\Facades\Hash::check($password, $dbPass);
    } catch (Throwable $e) {}
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

if (!$isMatch) {
    jsonError('Invalid email or password.', 401);
}

// Regenerate session for security
session_regenerate_id(true);

$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['name'] = (string) $user['name'];
$_SESSION['email'] = (string) $user['email'];
$_SESSION['role'] = (string) $user['role'];
$_SESSION['department'] = (string) ($user['department'] ?? '');

if (!empty($user['photo'])) {
    $_SESSION['photo'] = (string) $user['photo'];
} else {
    unset($_SESSION['photo']);
}

// Update last_seen timestamp
try {
    $upStmt = $conn->prepare("UPDATE users SET last_seen = NOW() WHERE id = ? LIMIT 1");
    $upUserId = (int) $user['id'];
    $upStmt->bind_param("i", $upUserId);
    $upStmt->execute();
    $upStmt->close();
} catch (Throwable $e) {}

$photoUrl = null;
if (!empty($user['photo'])) {
    $photoUrl = rtrim(BASE_URL, '/') . '/download.php?scope=avatars&file=' . rawurlencode(basename($user['photo']));
}

jsonSuccess([
    'user' => [
        'id' => (int) $user['id'],
        'name' => (string) $user['name'],
        'email' => (string) $user['email'],
        'role' => (string) $user['role'],
        'department' => (string) ($user['department'] ?? ''),
        'photo_url' => $photoUrl
    ]
], 'Login successful.');
