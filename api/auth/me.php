<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Models\User;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}

$currentUser = requireApiAuth();
$userId = $currentUser['user_id'];

$user = User::find($userId);

if ($user) {
    if (($user->status ?? 'Active') !== 'Active') {
        session_destroy();
        jsonResponse(false, 'Account is inactive.', [], 403);
    }
    
    $_SESSION['name']       = $user->name;
    $_SESSION['role']       = $user->role;
    $_SESSION['department'] = $user->department;

    jsonResponse(true, 'User session valid', [
        'user' => [
            'id'         => (int)$user->id,
            'name'       => $user->name,
            'email'      => $user->email,
            'role'       => $user->role,
            'department' => $user->department,
            'avatar'     => $user->avatar ?? null
        ]
    ]);
}

jsonResponse(true, 'User session valid', ['user' => $currentUser]);
