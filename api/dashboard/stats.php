<?php

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}

$currentUser = requireApiAuth();
$userId     = $currentUser['user_id'];
$role       = $currentUser['role'];
$department = $currentUser['department'];

// Column existence checks for database compatibility
function checkCol(mysqli $conn, string $col): bool {
    $res = $conn->query("SHOW COLUMNS FROM `job_requests` LIKE '$col'");
    return $res && $res->num_rows > 0;
}

$requestorCol = checkCol($conn, 'requestor') ? 'requestor' : (checkCol($conn, 'user_id') ? 'user_id' : 'id');
$statusCol    = checkCol($conn, 'approval_status') ? 'approval_status' : 'status';
$jobNoCol     = checkCol($conn, 'job_no') ? 'job_no' : (checkCol($conn, 'control_number') ? 'control_number' : 'id');
$hasArchived  = checkCol($conn, 'archived');

// Role Scoping
$whereParts = [];
$params = [];
$types = "";

if ($role === 'user') {
    $whereParts[] = "$requestorCol = ?";
    $params[] = $userId;
    $types .= "i";
} elseif ($role === 'supervisor') {
    $whereParts[] = "department = ?";
    $params[] = $department;
    $types .= "s";
}

if ($hasArchived) {
    $whereParts[] = "COALESCE(archived, 0) = 0";
}

$whereClause = !empty($whereParts) ? "WHERE " . implode(" AND ", $whereParts) : "";

// 1. Total Requests
$sqlTotal = "SELECT COUNT(*) as cnt FROM job_requests $whereClause";
$stmt = $conn->prepare($sqlTotal);
if ($types && $params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$totalRequests = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
$stmt->close();

// 2. Status Breakdown
$sqlStatus = "SELECT $statusCol as status_val, COUNT(*) as cnt FROM job_requests $whereClause GROUP BY $statusCol";
$stmt = $conn->prepare($sqlStatus);
if ($types && $params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

$statsBreakdown = [
    'pending'     => 0,
    'in_progress' => 0,
    'completed'   => 0,
    'approved'    => 0,
    'rejected'    => 0,
];

while ($row = $res->fetch_assoc()) {
    $st = $row['status_val'] ?? '';
    $count = (int)$row['cnt'];
    
    if (in_array($st, ['Pending', 'Pending Review', 'Under Review'], true)) {
        $statsBreakdown['pending'] += $count;
    } elseif (in_array($st, ['In Progress', 'In Development', 'For Revision'], true)) {
        $statsBreakdown['in_progress'] += $count;
    } elseif (in_array($st, ['Completed', 'Closed', 'For Evaluation'], true)) {
        $statsBreakdown['completed'] += $count;
    } elseif (in_array($st, ['Approved'], true)) {
        $statsBreakdown['approved'] += $count;
    } elseif (in_array($st, ['Rejected', 'Disapproved', 'Cancelled'], true)) {
        $statsBreakdown['rejected'] += $count;
    }
}
$stmt->close();

// 3. Recent Job Requests (Top 5)
$sqlRecent = "SELECT id, 
                     $jobNoCol as control_number, 
                     COALESCE(project_title, 'General Request') as request_type, 
                     COALESCE(requestor_name, 'N/A') as requester_name, 
                     department, 
                     $statusCol as status_val, 
                     COALESCE(priority, 'Normal') as priority, 
                     COALESCE(date_request, created_at) as created_at 
              FROM job_requests 
              $whereClause 
              ORDER BY id DESC LIMIT 5";

$stmt = $conn->prepare($sqlRecent);
if ($types && $params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$resRecent = $stmt->get_result();
$recentRequests = [];

while ($row = $resRecent->fetch_assoc()) {
    $recentRequests[] = [
        'id'             => (int)$row['id'],
        'control_number' => $row['control_number'] ?? 'REQ-' . $row['id'],
        'request_type'   => $row['request_type'] ?? 'General Request',
        'requester_name' => $row['requester_name'] ?? 'N/A',
        'department'     => $row['department'] ?? 'N/A',
        'status'         => $row['status_val'] ?? 'Pending',
        'priority'       => $row['priority'] ?? 'Normal',
        'created_at'     => $row['created_at'] ?? ''
    ];
}
$stmt->close();

jsonResponse(true, 'Dashboard statistics fetched successfully', [
    'stats' => [
        'total'       => $totalRequests,
        'pending'     => $statsBreakdown['pending'],
        'in_progress' => $statsBreakdown['in_progress'],
        'completed'   => $statsBreakdown['completed'],
        'approved'    => $statsBreakdown['approved'],
        'rejected'    => $statsBreakdown['rejected']
    ],
    'recent' => $recentRequests
]);
