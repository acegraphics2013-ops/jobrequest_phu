<?php

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}

$currentUser = requireApiRole(['admin', 'staff', 'supervisor']);
$userId      = $currentUser['user_id'];
$role        = $currentUser['role'];
$department  = $currentUser['department'];

function checkCol(mysqli $conn, string $col): bool {
    $res = $conn->query("SHOW COLUMNS FROM `job_requests` LIKE '$col'");
    return $res && $res->num_rows > 0;
}

$statusCol   = checkCol($conn, 'approval_status') ? 'approval_status' : 'status';
$jobNoCol    = checkCol($conn, 'job_no') ? 'job_no' : (checkCol($conn, 'control_number') ? 'control_number' : 'id');
$hasArchived = checkCol($conn, 'archived');

$whereParts = [];
if ($hasArchived) {
    $whereParts[] = "COALESCE(archived, 0) = 0";
}
if ($role === 'supervisor') {
    $whereParts[] = "department = ?";
}

$whereClause = !empty($whereParts) ? "WHERE " . implode(" AND ", $whereParts) : "";

$sql = "SELECT id, 
               $jobNoCol as control_number, 
               COALESCE(project_title, 'General Request') as project_title, 
               COALESCE(requestor_name, 'N/A') as requester_name, 
               department, 
               $statusCol as status, 
               COALESCE(priority, 'Normal') as priority, 
               COALESCE(assigned_to, '') as assigned_to, 
               COALESCE(date_request, created_at) as created_at 
        FROM job_requests 
        $whereClause 
        ORDER BY id DESC";

$stmt = $conn->prepare($sql);
if ($role === 'supervisor') {
    $stmt->bind_param("s", $department);
}
$stmt->execute();
$result = $stmt->get_result();

$columns = [
    'pending'     => ['title' => 'Pending Review', 'items' => []],
    'in_progress' => ['title' => 'In Progress', 'items' => []],
    'under_review'=> ['title' => 'Under Review', 'items' => []],
    'completed'   => ['title' => 'Completed', 'items' => []],
];

while ($row = $result->fetch_assoc()) {
    $st = $row['status'] ?? 'Pending Review';
    $item = [
        'id'             => (int) $row['id'],
        'control_number' => $row['control_number'] ?? 'REQ-' . $row['id'],
        'project_title'  => $row['project_title'],
        'requester_name' => $row['requester_name'],
        'department'     => $row['department'] ?? 'N/A',
        'status'         => $st,
        'priority'       => $row['priority'],
        'assigned_to'    => (string) $row['assigned_to'],
        'created_at'     => $row['created_at'] ?? ''
    ];

    if (in_array($st, ['Pending', 'Pending Review'], true)) {
        $columns['pending']['items'][] = $item;
    } elseif (in_array($st, ['In Progress', 'In Development'], true)) {
        $columns['in_progress']['items'][] = $item;
    } elseif (in_array($st, ['Under Review', 'For Revision', 'Approved'], true)) {
        $columns['under_review']['items'][] = $item;
    } else {
        $columns['completed']['items'][] = $item;
    }
}
$stmt->close();

jsonResponse(true, 'Kanban board items retrieved successfully', [
    'board' => $columns
]);
