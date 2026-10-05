<?php

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}

$currentUser = requireApiAuth();

$departments = [];
$res = $conn->query("SELECT id, department_name, short_name, status FROM departments ORDER BY department_name ASC");

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $departments[] = [
            'id'              => (int) $row['id'],
            'department_name' => $row['department_name'],
            'short_name'      => $row['short_name'] ?? '',
            'status'          => $row['status'] ?? 'Active'
        ];
    }
}

jsonResponse(true, 'Departments fetched successfully', [
    'departments' => $departments
]);
