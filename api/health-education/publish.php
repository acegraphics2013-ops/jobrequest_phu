<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../security/csrf.php';
require_once __DIR__ . '/../../includes/health_education_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed', [], 405);
}

$user = requireWhePermission($conn, 'whe_sessions');

if (!verifyCsrfToken()) {
    jsonResponse(false, 'Security token expired or invalid.', [], 403);
}

$input = getJsonInput();
$id = (int)($input['id'] ?? $input['session_id'] ?? 0);
$destination = trim((string)($input['destination'] ?? 'Both')); // Internal Documentation Only, Facebook, Website, Both
$notes = trim((string)($input['publication_notes'] ?? ''));

if ($id <= 0) {
    jsonResponse(false, 'Activity ID is required.', [], 400);
}

// Fetch session record
$stmt = $conn->prepare("SELECT * FROM ward_health_education_sessions WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$session) {
    jsonResponse(false, 'Activity record not found.', [], 404);
}

$userId = $user['user_id'];
$userRole = $user['role'];

if (!in_array($userRole, ['staff', 'supervisor', 'admin'], true)) {
    jsonResponse(false, 'Only staff or admin can send activities to the publication system.', [], 403);
}

// Ensure publication table exists
$conn->query("CREATE TABLE IF NOT EXISTS publication_event_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submitted_by INT NOT NULL,
    email_address VARCHAR(190) NOT NULL,
    title VARCHAR(255) NOT NULL,
    activity_type VARCHAR(120) NOT NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    activity_time VARCHAR(120) NULL,
    venue VARCHAR(255) NULL,
    lead_office VARCHAR(255) NULL,
    partner_offices TEXT NULL,
    person_in_charge VARCHAR(255) NULL,
    participant_count INT NULL,
    background TEXT NULL,
    objectives TEXT NULL,
    activity_highlights LONGTEXT NULL,
    key_outputs LONGTEXT NULL,
    impact_significance LONGTEXT NULL,
    quotable_statement TEXT NULL,
    remembrance_sentence TEXT NULL,
    preferred_publication_date DATE NULL,
    privacy_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(40) NOT NULL DEFAULT 'For Review',
    publication_notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$userEmail = (string)($_SESSION['email'] ?? 'staff@hpu.local');
$pubTitle = "[Health Edu] " . $session['title'];
$activityType = $session['activity_type'] ?: 'Health Education and Promotion';
$startDate = $session['session_date'];
$activityTime = $session['activity_time'];
$venue = $session['ward_area'];
$leadOffice = $session['lead_office'];
$partnerOffices = $session['partner_offices'];
$personInCharge = $session['facilitator'];
$participantCount = (int)$session['participant_count'];
$background = "Health Topic: " . $session['health_topic'] . "\nTeaching Method: " . $session['teaching_method'];
$objectives = $session['objectives'];
$highlights = "IEC Materials Used: " . $session['iec_materials_used'] . "\nIEC Quantity: " . $session['iec_quantity'];
$keyOutputs = $session['key_outputs'];
$impact = $session['impact_significance'];
$pubNotes = "Transferred from Health Education & Promotion module. Target Destination: $destination. $notes";
$privacyConfirmed = 1;
$pubStatus = 'For Review';

$pubStmt = $conn->prepare("INSERT INTO publication_event_submissions (
    submitted_by, email_address, title, activity_type, start_date, activity_time, venue, lead_office,
    partner_offices, person_in_charge, participant_count, background, objectives, activity_highlights,
    key_outputs, impact_significance, privacy_confirmed, status, publication_notes
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

$pubStmt->bind_param(
    "isssssssssisssssiss",
    $userId, $userEmail, $pubTitle, $activityType, $startDate, $activityTime, $venue, $leadOffice,
    $partnerOffices, $personInCharge, $participantCount, $background, $objectives, $highlights,
    $keyOutputs, $impact, $privacyConfirmed, $pubStatus, $pubNotes
);

if (!$pubStmt->execute()) {
    $pubStmt->close();
    jsonResponse(false, 'Failed to create publication request.', [], 500);
}

$publicationId = $pubStmt->insert_id;
$pubStmt->close();

// Update activity session status and link publication_id
$upStmt = $conn->prepare("UPDATE ward_health_education_sessions SET status = 'For Publication', publication_id = ? WHERE id = ?");
$upStmt->bind_param("ii", $publicationId, $id);
$upStmt->execute();
$upStmt->close();

// Log action
logHealthEducationAction($conn, $id, $userId, 'Sent to Publication', "Sent for publication (Pub ID: #$publicationId, Destination: $destination)");

// Send notification
sendWheNotification(
    $conn,
    (int)$session['submitted_by'],
    "Your Health Education Activity '{$session['title']}' has been submitted to the Publication System (Pub ID: #$publicationId).",
    'success',
    "/health-education/activities/$id"
);

jsonResponse(true, "Activity successfully submitted for publication (Publication Request #$publicationId)!", [
    'activity_id' => $id,
    'publication_id' => $publicationId,
    'destination' => $destination,
    'status' => 'For Publication'
]);
