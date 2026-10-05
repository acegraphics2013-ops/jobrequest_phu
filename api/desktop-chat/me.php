<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$user = requireDesktopAuth($conn);

$photoUrl = null;
if (!empty($_SESSION['photo'])) {
    $photoUrl = rtrim(BASE_URL, '/') . '/download.php?scope=avatars&file=' . rawurlencode(basename((string)$_SESSION['photo']));
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
]);
