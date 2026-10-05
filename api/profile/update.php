<?php

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}

$currentUser = requireApiAuth();
$userId      = $currentUser['user_id'];

$input        = getJsonInput();
$name         = trim((string) ($input['name'] ?? ''));
$department   = trim((string) ($input['department'] ?? ''));
$newPassword  = (string) ($input['password'] ?? '');

if (empty($name)) {
    jsonResponse(false, 'Name cannot be empty.', [], 400);
}

if (!empty($newPassword)) {
    if (strlen($newPassword) < 6) {
        jsonResponse(false, 'Password must be at least 6 characters long.', [], 400);
    }
    $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
    $stmt = $conn->prepare("UPDATE users SET name = ?, department = ?, password = ? WHERE id = ?");
    $stmt->bind_param("sssi", $name, $department, $hashed, $userId);
} else {
    $stmt = $conn->prepare("UPDATE users SET name = ?, department = ? WHERE id = ?");
    $stmt->bind_param("ssi", $name, $department, $userId);
}

if ($stmt->execute()) {
    $stmt->close();
    $_SESSION['name']       = $name;
    $_SESSION['department'] = $department;

    jsonResponse(true, 'Profile updated successfully', [
        'user' => [
            'id'         => $userId,
            'name'       => $name,
            'email'      => $currentUser['email'],
            'role'       => $currentUser['role'],
            'department' => $department
        ]
    ]);
} else {
    $stmt->close();
    jsonResponse(false, 'Failed to update profile.', [], 500);
}
