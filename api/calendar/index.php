<?php

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}

$currentUser = requireApiAuth();

// Fetch activities
$activities = [];
$resActTable = $conn->query("SHOW TABLES LIKE 'hpu_calendar_activities'");
if ($resActTable && $resActTable->num_rows > 0) {
    $stmt = $conn->prepare("SELECT id, title, activity_type, calendar_scope, location, description, start_datetime, end_datetime, color FROM hpu_calendar_activities ORDER BY start_datetime ASC");
    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $activities[] = [
                'id'             => (int) $row['id'],
                'title'          => $row['title'] ?? 'Event',
                'activity_type'  => $row['activity_type'] ?? 'Activity',
                'calendar_scope' => $row['calendar_scope'] ?? 'HPU',
                'location'       => $row['location'] ?? '',
                'description'    => $row['description'] ?? '',
                'start_datetime' => $row['start_datetime'],
                'end_datetime'   => $row['end_datetime'],
                'color'          => $row['color'] ?? '#0f766e'
            ];
        }
        $stmt->close();
    }
}

// Fetch blocked dates
$blockedDates = [];
$resBlockTable = $conn->query("SHOW TABLES LIKE 'hpu_blocked_dates'");
if ($resBlockTable && $resBlockTable->num_rows > 0) {
    $stmtB = $conn->prepare("SELECT id, start_date, end_date, service_type, reason FROM hpu_blocked_dates ORDER BY start_date ASC");
    if ($stmtB) {
        $stmtB->execute();
        $resB = $stmtB->get_result();
        while ($row = $resB->fetch_assoc()) {
            $blockedDates[] = [
                'id'           => (int) $row['id'],
                'start_date'   => $row['start_date'],
                'end_date'     => $row['end_date'],
                'service_type' => $row['service_type'] ?? 'All HPU Services',
                'reason'       => $row['reason'] ?? 'Blocked'
            ];
        }
        $stmtB->close();
    }
}

jsonResponse(true, 'Calendar activities fetched successfully', [
    'activities'    => $activities,
    'blocked_dates' => $blockedDates
]);
