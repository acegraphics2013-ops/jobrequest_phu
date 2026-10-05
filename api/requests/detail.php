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

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    jsonResponse(false, 'Invalid or missing job request ID.', [], 400);
}

$jobRequest = JobRequest::find($id);

if (!$jobRequest) {
    jsonResponse(false, 'Job request not found.', [], 404);
}

// Check role permissions
if ($role === 'user' && (int) $jobRequest->requestor !== $userId) {
    jsonResponse(false, 'Forbidden. You do not have permission to view this request.', [], 403);
}
if ($role === 'supervisor' && $jobRequest->department !== $department) {
    jsonResponse(false, 'Forbidden. You do not have access to requests outside your department.', [], 403);
}

jsonResponse(true, 'Job request details retrieved successfully', [
    'request'  => [
        'id'                => (int) $jobRequest->id,
        'control_number'    => $jobRequest->job_no ?? ('REQ-' . $jobRequest->id),
        'requester_name'    => $jobRequest->requestor_name ?? 'N/A',
        'department'        => $jobRequest->department ?? 'N/A',
        'contact'           => $jobRequest->contact ?? '',
        'project_title'     => $jobRequest->project_title ?? 'General Request',
        'purpose_objective' => $jobRequest->purpose_objective ?? '',
        'request_details'   => $jobRequest->request_details ?? '',
        'status'            => $jobRequest->approval_status ?? 'Pending Review',
        'priority'          => $jobRequest->priority ?? 'Normal',
        'assigned_to'       => (string)($jobRequest->assigned_to ?? ''),
        'reviewed_by'       => (string)($jobRequest->reviewed_by ?? ''),
        'approved_by'       => (string)($jobRequest->approved_by ?? ''),
        'created_at'        => (string)($jobRequest->date_request ?? $jobRequest->created_at ?? '')
    ],
    'files'    => [],
    'comments' => []
]);
