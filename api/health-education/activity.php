<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/health_education_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Method not allowed', [], 405);
}

$user = requireWhePermission($conn, 'whe_sessions');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    jsonResponse(false, 'Activity ID is required.', [], 400);
}

$stmt = $conn->prepare("SELECT s.*, 
    COALESCE(u.name, 'System User') AS submitter_name,
    COALESCE(u.department, '') AS submitter_department,
    COALESCE(rev.name, '') AS reviewer_name,
    COALESCE(app.name, '') AS approver_name
    FROM ward_health_education_sessions s
    LEFT JOIN users u ON u.id = s.submitted_by
    LEFT JOIN users rev ON rev.id = s.reviewed_by
    LEFT JOIN users app ON app.id = s.approved_by
    WHERE s.id = ? LIMIT 1");

$stmt->bind_param("i", $id);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$session) {
    jsonResponse(false, 'Activity record not found.', [], 404);
}

// Recalculate & fetch latest completeness
$completenessPct = calculateDocumentationCompleteness($conn, $id);

// Fetch outputs
$oStmt = $conn->prepare("SELECT o.*, COALESCE(u.name, 'User') AS uploader_name 
    FROM ward_health_education_outputs o 
    LEFT JOIN users u ON u.id = o.uploaded_by 
    WHERE o.session_id = ? 
    ORDER BY o.created_at ASC");
$oStmt->bind_param("i", $id);
$oStmt->execute();
$oRes = $oStmt->get_result();

$outputs = [];
$photoTypes = ['before activity', 'during activity', 'after activity', 'group photo', 'photo documentation'];
$hasPhotoDoc = false;
$hasAttendanceOrIec = !empty($session['iec_materials_used']);

while ($row = $oRes->fetch_assoc()) {
    $typeLower = strtolower(trim((string)$row['output_type']));
    if (in_array($typeLower, $photoTypes, true)) {
        $hasPhotoDoc = true;
    }
    if (in_array($typeLower, ['attendance sheet', 'iec material', 'presentation', 'other'], true)) {
        $hasAttendanceOrIec = true;
    }

    $fileId = (int)$row['id'];
    $outputs[] = [
        'id' => $fileId,
        'session_id' => (int)$row['session_id'],
        'uploaded_by' => (int)$row['uploaded_by'],
        'uploader_name' => $row['uploader_name'],
        'output_type' => $row['output_type'],
        'title' => $row['title'],
        'description' => $row['description'],
        'original_name' => $row['original_name'],
        'stored_name' => $row['stored_name'],
        'extension' => $row['extension'],
        'mime_type' => $row['mime_type'],
        'file_size' => (int)$row['file_size'],
        'is_cover' => (int)($session['cover_output_id'] ?? 0) === $fileId,
        'download_url' => "/backend-api/health-education/file.php?id=$fileId",
        'created_at' => $row['created_at']
    ];
}
$oStmt->close();

// Documentation Checklist Items
$checklist = [
    ['key' => 'info', 'label' => 'Activity Information', 'completed' => (!empty($session['title']) && !empty($session['session_date']) && !empty($session['ward_area']))],
    ['key' => 'objectives', 'label' => 'Objectives', 'completed' => !empty(trim((string)$session['objectives']))],
    ['key' => 'participants', 'label' => 'Participants Breakdown', 'completed' => ((int)$session['participant_count'] > 0)],
    ['key' => 'outputs', 'label' => 'Key Outputs / Agreements', 'completed' => !empty(trim((string)$session['key_outputs']))],
    ['key' => 'impact', 'label' => 'Impact & Significance', 'completed' => !empty(trim((string)$session['impact_significance']))],
    ['key' => 'photos', 'label' => 'Photo Documentation', 'completed' => $hasPhotoDoc],
    ['key' => 'iec_attendance', 'label' => 'Attendance Sheet / IEC Material', 'completed' => $hasAttendanceOrIec],
];

// Fetch activity timeline log
$lStmt = $conn->prepare("SELECT l.*, COALESCE(u.name, 'System') AS user_name 
    FROM ward_health_education_logs l 
    LEFT JOIN users u ON u.id = l.user_id 
    WHERE l.session_id = ? 
    ORDER BY l.created_at ASC, l.id ASC");
$lStmt->bind_param("i", $id);
$lStmt->execute();
$lRes = $lStmt->get_result();

$timeline = [];
while ($row = $lRes->fetch_assoc()) {
    $timeline[] = [
        'id' => (int)$row['id'],
        'user_name' => $row['user_name'],
        'action' => $row['action'],
        'description' => $row['description'],
        'created_at' => $row['created_at']
    ];
}
$lStmt->close();

// Permissions & allowed actions calculation
$userRole = $user['role'];
$userId = $user['user_id'];
$isOwner = ((int)$session['submitted_by'] === $userId);
$isStaff = in_array($userRole, ['staff', 'supervisor', 'admin'], true);
$isManager = in_array($userRole, ['supervisor', 'admin'], true);

$isEditable = ($session['status'] !== 'Published' && $session['status'] !== 'Archived') && ($isStaff || ($isOwner && in_array($session['status'], ['Draft', 'For Revision', 'For Documentation'], true)));

$allowedTransitions = [];
$allPossibleStatuses = ['Draft', 'Scheduled', 'Conducted', 'For Documentation', 'For Review', 'For Revision', 'Approved', 'For Publication', 'Published', 'Archived'];

foreach ($allPossibleStatuses as $st) {
    if ($st !== $session['status'] && validateWheStatusTransition($session['status'], $st, $userRole)) {
        $allowedTransitions[] = $st;
    }
}

jsonResponse(true, 'Activity details retrieved successfully', [
    'activity' => [
        'id' => (int)$session['id'],
        'submitted_by' => (int)$session['submitted_by'],
        'submitter_name' => $session['submitter_name'],
        'submitter_department' => $session['submitter_department'],
        'title' => $session['title'],
        'activity_type' => $session['activity_type'] ?: 'Health Education Session / Lecture',
        'session_date' => $session['session_date'],
        'activity_time' => $session['activity_time'],
        'ward_area' => $session['ward_area'],
        'lead_office' => $session['lead_office'],
        'partner_offices' => $session['partner_offices'],
        'health_topic' => $session['health_topic'],
        'facilitator' => $session['facilitator'],
        'target_participants' => $session['target_participants'],
        'participant_count' => (int)$session['participant_count'],
        'patients_count' => (int)$session['patients_count'],
        'watchers_count' => (int)$session['watchers_count'],
        'employees_count' => (int)$session['employees_count'],
        'others_count' => (int)$session['others_count'],
        'teaching_method' => $session['teaching_method'],
        'objectives' => $session['objectives'],
        'key_outputs' => $session['key_outputs'],
        'impact_significance' => $session['impact_significance'],
        'iec_materials_used' => $session['iec_materials_used'],
        'iec_quantity' => (int)$session['iec_quantity'],
        'remarks' => $session['remarks'],
        'status' => $session['status'],
        'documentation_percentage' => $completenessPct,
        'cover_output_id' => $session['cover_output_id'] ? (int)$session['cover_output_id'] : null,
        'publication_id' => $session['publication_id'] ? (int)$session['publication_id'] : null,
        'reviewed_by' => $session['reviewed_by'] ? (int)$session['reviewed_by'] : null,
        'reviewer_name' => $session['reviewer_name'],
        'reviewed_at' => $session['reviewed_at'],
        'approved_by' => $session['approved_by'] ? (int)$session['approved_by'] : null,
        'approver_name' => $session['approver_name'],
        'approved_at' => $session['approved_at'],
        'created_at' => $session['created_at'],
        'updated_at' => $session['updated_at']
    ],
    'outputs' => $outputs,
    'checklist' => $checklist,
    'timeline' => $timeline,
    'userPermissions' => [
        'canEdit' => $isEditable,
        'canUpload' => $isEditable,
        'canSubmit' => ($session['status'] === 'Draft' || $session['status'] === 'For Revision' || $session['status'] === 'For Documentation'),
        'canApprove' => ($isStaff && in_array($session['status'], ['For Review', 'For Revision'], true)),
        'canPublish' => ($isStaff && in_array($session['status'], ['Approved', 'For Publication'], true)),
        'canArchive' => ($isManager && $session['status'] !== 'Archived'),
        'allowedTransitions' => $allowedTransitions
    ]
]);
