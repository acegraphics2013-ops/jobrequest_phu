<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Models\JobRequest;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}

$currentUser = requireApiAuth();
$userId     = $currentUser['user_id'];
$role       = $currentUser['role'];
$department = $currentUser['department'];

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = max(1, min(100, (int)($_GET['limit'] ?? 10)));
$search     = trim((string)($_GET['search'] ?? ''));
$status     = trim((string)($_GET['status'] ?? ''));
$deptFilter = trim((string)($_GET['department'] ?? ''));

$query = JobRequest::query();

// Role Scoping
if ($role === 'user') {
    $query->where('requestor', $userId);
} elseif ($role === 'supervisor') {
    $query->where('department', $department);
}

// Search Filter
if (!empty($search)) {
    $query->where(function($q) use ($search) {
        $q->where('job_no', 'LIKE', "%{$search}%")
          ->orWhere('requestor_name', 'LIKE', "%{$search}%")
          ->orWhere('project_title', 'LIKE', "%{$search}%");
    });
}

// Status Filter
if (!empty($status)) {
    $query->where('approval_status', 'LIKE', "%{$status}%");
}

// Department Filter (Admin/Staff override)
if (!empty($deptFilter) && in_array($role, ['admin', 'staff'], true)) {
    $query->where('department', $deptFilter);
}

$totalRecords = $query->count();

$paginated = $query->orderBy('id', 'desc')
    ->skip(($page - 1) * $limit)
    ->take($limit)
    ->get();

$requests = $paginated->map(function ($row) {
    return [
        'id'             => (int)$row->id,
        'control_number' => $row->job_no ?? ('REQ-' . $row->id),
        'requester_name' => $row->requestor_name ?? 'N/A',
        'department'     => $row->department ?? 'N/A',
        'request_type'   => $row->project_title ?? 'General Request',
        'status'         => $row->approval_status ?? 'Pending Review',
        'priority'       => $row->priority ?? 'Normal',
        'assigned_to'    => (string)($row->assigned_to ?? ''),
        'created_at'     => (string)($row->date_request ?? $row->created_at ?? '')
    ];
});

jsonResponse(true, 'Job requests retrieved successfully', [
    'requests'   => $requests->toArray(),
    'pagination' => [
        'total'       => $totalRecords,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => (int)ceil($totalRecords / $limit)
    ]
]);
