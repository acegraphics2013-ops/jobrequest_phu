<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../security/csrf.php';
require_once __DIR__ . '/../../includes/health_education_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    jsonResponse(false, 'Method not allowed', [], 405);
}

$user = requireWhePermission($conn, 'whe_sessions');

if (!verifyCsrfToken()) {
    jsonResponse(false, 'Security token expired or invalid.', [], 403);
}

$input = getJsonInput();
$id = (int)($input['id'] ?? $input['session_id'] ?? 0);

if ($id <= 0) {
    jsonResponse(false, 'Activity ID is required.', [], 400);
}

// Fetch existing record
$checkStmt = $conn->prepare("SELECT * FROM ward_health_education_sessions WHERE id = ? LIMIT 1");
$checkStmt->bind_param("i", $id);
$checkStmt->execute();
$existing = $checkStmt->get_result()->fetch_assoc();
$checkStmt->close();

if (!$existing) {
    jsonResponse(false, 'Activity record not found.', [], 404);
}

// Permission check
$userId = $user['user_id'];
$userRole = $user['role'];
$isOwner = ((int)$existing['submitted_by'] === $userId);
$isStaff = in_array($userRole, ['staff', 'supervisor', 'admin'], true);

if (!$isStaff && (!$isOwner || in_array($existing['status'], ['Approved', 'Published', 'Archived'], true))) {
    jsonResponse(false, 'You do not have permission to edit this activity in its current status.', [], 403);
}

$activityType = trim((string)($input['activity_type'] ?? $existing['activity_type']));
$title = trim((string)($input['title'] ?? $existing['title']));
if ($title === 'Others') {
    $title = trim((string)($input['title_other'] ?? $existing['title']));
}
$sessionDate = trim((string)($input['session_date'] ?? $existing['session_date']));
$startRaw = trim((string)($input['activity_time_start'] ?? ''));
$endRaw = trim((string)($input['activity_time_end'] ?? ''));

$wardArea = trim((string)($input['ward_area'] ?? $existing['ward_area']));
$leadOffice = trim((string)($input['lead_office'] ?? $existing['lead_office']));
$partnerOffices = trim((string)($input['partner_offices'] ?? $existing['partner_offices']));
$healthTopic = trim((string)($input['health_topic'] ?? $existing['health_topic']));
$facilitator = trim((string)($input['facilitator'] ?? $existing['facilitator']));
$targetParticipants = trim((string)($input['target_participants'] ?? $existing['target_participants']));

$patientsCount = max(0, (int)($input['patients_count'] ?? $existing['patients_count']));
$watchersCount = max(0, (int)($input['watchers_count'] ?? $existing['watchers_count']));
$employeesCount = max(0, (int)($input['employees_count'] ?? $existing['employees_count']));
$othersCount = max(0, (int)($input['others_count'] ?? $existing['others_count']));
$totalParticipants = max(0, (int)($input['participant_count'] ?? ($patientsCount + $watchersCount + $employeesCount + $othersCount)));

$teachingMethod = trim((string)($input['teaching_method'] ?? $existing['teaching_method']));
$objectives = trim((string)($input['objectives'] ?? $existing['objectives']));
$keyOutputs = trim((string)($input['key_outputs'] ?? $existing['key_outputs']));
$impactSignificance = trim((string)($input['impact_significance'] ?? $existing['impact_significance']));
$iecMaterials = trim((string)($input['iec_materials_used'] ?? $existing['iec_materials_used']));
$iecQuantity = max(0, (int)($input['iec_quantity'] ?? $existing['iec_quantity']));
$remarks = trim((string)($input['remarks'] ?? $existing['remarks']));

if (empty($title) || empty($sessionDate) || empty($wardArea) || empty($leadOffice) || empty($healthTopic) || empty($facilitator) || empty($objectives)) {
    jsonResponse(false, 'Please complete all required fields.', [], 422);
}

// Time formatting
if (!empty($startRaw) && !empty($endRaw)) {
    $activityTime = "$startRaw to $endRaw";
} elseif (!empty($startRaw)) {
    $activityTime = $startRaw;
} else {
    $activityTime = $existing['activity_time'];
}

$stmt = $conn->prepare("UPDATE ward_health_education_sessions SET 
    activity_type=?, title=?, session_date=?, activity_time=?, ward_area=?, lead_office=?, partner_offices=?,
    health_topic=?, facilitator=?, target_participants=?, participant_count=?, patients_count=?, watchers_count=?, employees_count=?, others_count=?,
    teaching_method=?, objectives=?, key_outputs=?, impact_significance=?, iec_materials_used=?, iec_quantity=?, remarks=?
    WHERE id=?");

$stmt->bind_param(
    "ssssssssssiiiiisssssisi",
    $activityType, $title, $sessionDate, $activityTime, $wardArea, $leadOffice, $partnerOffices,
    $healthTopic, $facilitator, $targetParticipants, $totalParticipants, $patientsCount, $watchersCount, $employeesCount, $othersCount,
    $teachingMethod, $objectives, $keyOutputs, $impactSignificance, $iecMaterials, $iecQuantity, $remarks, $id
);

if (!$stmt->execute()) {
    $stmt->close();
    jsonResponse(false, 'Failed to update activity record.', [], 500);
}
$stmt->close();

logHealthEducationAction($conn, $id, $userId, 'Edited', "Activity details updated by {$user['name']}");

$completeness = calculateDocumentationCompleteness($conn, $id);

jsonResponse(true, 'Activity details updated successfully!', [
    'id' => $id,
    'documentation_percentage' => $completeness
]);
