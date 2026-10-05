<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/health_education_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Method not allowed', [], 405);
}

requireWhePermission($conn, '');

$result = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name ASC");
$departments = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $departments[] = [
            'id' => (int)$row['id'],
            'name' => $row['department_name']
        ];
    }
}

jsonResponse(true, 'Departments fetched successfully', [
    'departments' => $departments
]);
