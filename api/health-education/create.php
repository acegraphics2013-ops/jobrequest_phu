<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../security/csrf.php';
require_once __DIR__ . '/../../includes/health_education_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed', [], 405);
}

$user = requireWhePermission($conn, 'whe_new');

if (!verifyCsrfToken()) {
    jsonResponse(false, 'Security token expired or invalid. Please refresh the page and try again.', [], 403);
}

$input = getJsonInput();

$activityType = trim((string)($input['activity_type'] ?? 'Health Education Session / Lecture'));
$title = trim((string)($input['title'] ?? ''));
if ($title === 'Others') {
    $title = trim((string)($input['title_other'] ?? ''));
}
$sessionDate = trim((string)($input['session_date'] ?? ''));
$startRaw = trim((string)($input['activity_time_start'] ?? ''));
$endRaw = trim((string)($input['activity_time_end'] ?? ''));

$wardArea = trim((string)($input['ward_area'] ?? ''));
$leadOffice = trim((string)($input['lead_office'] ?? ''));
$partnerOffices = trim((string)($input['partner_offices'] ?? ''));
$healthTopic = trim((string)($input['health_topic'] ?? ''));
$facilitator = trim((string)($input['facilitator'] ?? ''));
$targetParticipants = trim((string)($input['target_participants'] ?? ''));

$patientsCount = max(0, (int)($input['patients_count'] ?? 0));
$watchersCount = max(0, (int)($input['watchers_count'] ?? 0));
$employeesCount = max(0, (int)($input['employees_count'] ?? 0));
$othersCount = max(0, (int)($input['others_count'] ?? 0));
$totalParticipants = max(0, (int)($input['participant_count'] ?? ($patientsCount + $watchersCount + $employeesCount + $othersCount)));

$teachingMethod = trim((string)($input['teaching_method'] ?? ''));
$objectives = trim((string)($input['objectives'] ?? ''));
$keyOutputs = trim((string)($input['key_outputs'] ?? ''));
$impactSignificance = trim((string)($input['impact_significance'] ?? ''));
$iecMaterials = trim((string)($input['iec_materials_used'] ?? ''));
$iecQuantity = max(0, (int)($input['iec_quantity'] ?? 0));
$remarks = trim((string)($input['remarks'] ?? ''));

// Form Validation
if (empty($title) || empty($sessionDate) || empty($wardArea) || empty($leadOffice) || empty($healthTopic) || empty($facilitator) || empty($objectives)) {
    jsonResponse(false, 'Please complete all required fields (Title, Date, Ward, Lead Office, Health Topic, Facilitator, Objectives).', [], 422);
}

// Time formatting
$activityTime = '';
if (!empty($startRaw) && !empty($endRaw)) {
    $activityTime = "$startRaw to $endRaw";
} elseif (!empty($startRaw)) {
    $activityTime = $startRaw;
}

$initialStatus = 'For Review';
$userId = $user['user_id'];

$stmt = $conn->prepare("INSERT INTO ward_health_education_sessions (
    submitted_by, activity_type, title, session_date, activity_time, ward_area, lead_office, partner_offices,
    health_topic, facilitator, target_participants, participant_count, patients_count, watchers_count, employees_count, others_count,
    teaching_method, objectives, key_outputs, impact_significance, iec_materials_used, iec_quantity, remarks, status
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

$stmt->bind_param(
    "issssssssssiiiiisssssiss",
    $userId, $activityType, $title, $sessionDate, $activityTime, $wardArea, $leadOffice, $partnerOffices,
    $healthTopic, $facilitator, $targetParticipants, $totalParticipants, $patientsCount, $watchersCount, $employeesCount, $othersCount,
    $teachingMethod, $objectives, $keyOutputs, $impactSignificance, $iecMaterials, $iecQuantity, $remarks, $initialStatus
);

if (!$stmt->execute()) {
    $stmt->close();
    jsonResponse(false, 'Database error occurred while saving activity.', [], 500);
}

$newId = $stmt->insert_id;
$stmt->close();

// Log creation action
logHealthEducationAction($conn, $newId, $userId, 'Created', "Activity '$title' created by {$user['name']}");

// Calculate completeness
$completeness = calculateDocumentationCompleteness($conn, $newId);

jsonResponse(true, 'Health Education Activity saved successfully!', [
    'id' => $newId,
    'documentation_percentage' => $completeness
], 201);
