<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Models\User;
use Illuminate\Support\Facades\Hash;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}

$input = getJsonInput();
$email = strtolower(trim((string) ($input['email'] ?? '')));
$password = (string) ($input['password'] ?? '');

if (empty($email) || empty($password)) {
    jsonResponse(false, 'Please enter your email and password.', [], 400);
}

$user = User::whereRaw('LOWER(email) = ?', [$email])->first();

if (!$user) {
    jsonResponse(false, 'Account not found.', [], 404);
}

if (($user->status ?? 'Active') !== 'Active') {
    jsonResponse(false, 'Your account is inactive. Please contact the administrator.', [], 403);
}

if (!Hash::check($password, $user->password) && !password_verify($password, $user->password)) {
    jsonResponse(false, 'Invalid email or password.', [], 401);
}

// Regenerate session ID for security
session_regenerate_id(true);

$_SESSION['user_id'] = (int) $user->id;
$_SESSION['name'] = $user->name;
$_SESSION['email'] = $user->email;
$_SESSION['role'] = $user->role;
$_SESSION['department'] = $user->department;

jsonResponse(true, 'Login successful', [
    'user' => [
        'id' => (int) $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'role' => $user->role,
        'department' => $user->department
    ]
]);
