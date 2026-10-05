<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/health_education_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Method not allowed', [], 405);
}

$user = requireWhePermission($conn, '');
$permissions = getWhePermissions($conn, $user['user_id'], $user['role']);

jsonResponse(true, 'Permissions loaded successfully', [
    'user' => $user,
    'permissions' => $permissions
]);
