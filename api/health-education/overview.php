<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/health_education_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Method not allowed', [], 405);
}

requireWhePermission($conn, 'whe_overview');

// 1. KPI Cards
$statsRow = $conn->query("SELECT 
    COUNT(*) AS total_activities,
    COALESCE(SUM(participant_count), 0) AS total_participants,
    COALESCE(SUM(patients_count), 0) AS total_patients,
    COALESCE(SUM(watchers_count), 0) AS total_watchers,
    COALESCE(SUM(employees_count), 0) AS total_employees,
    COALESCE(SUM(others_count), 0) AS total_others,
    SUM(CASE WHEN YEAR(session_date)=YEAR(CURDATE()) AND MONTH(session_date)=MONTH(CURDATE()) THEN 1 ELSE 0 END) AS activities_this_month,
    SUM(CASE WHEN status='For Review' THEN 1 ELSE 0 END) AS pending_review,
    SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) AS approved,
    SUM(CASE WHEN status='Published' THEN 1 ELSE 0 END) AS published,
    SUM(CASE WHEN documentation_percentage < 100 THEN 1 ELSE 0 END) AS pending_documentation,
    COALESCE(SUM(iec_quantity), 0) AS iec_distributed,
    COUNT(DISTINCT ward_area) AS wards_covered
    FROM ward_health_education_sessions")->fetch_assoc();

$outputsCount = (int)(($conn->query("SELECT COUNT(*) AS total FROM ward_health_education_outputs")->fetch_assoc())['total'] ?? 0);

$kpis = [
    'total_activities'      => (int)($statsRow['total_activities'] ?? 0),
    'activities_this_month' => (int)($statsRow['activities_this_month'] ?? 0),
    'participants_reached'  => (int)($statsRow['total_participants'] ?? 0),
    'patients_count'        => (int)($statsRow['total_patients'] ?? 0),
    'watchers_count'        => (int)($statsRow['total_watchers'] ?? 0),
    'employees_count'       => (int)($statsRow['total_employees'] ?? 0),
    'others_count'          => (int)($statsRow['total_others'] ?? 0),
    'wards_covered'         => (int)($statsRow['wards_covered'] ?? 0),
    'pending_documentation' => (int)($statsRow['pending_documentation'] ?? 0),
    'pending_review'        => (int)($statsRow['pending_review'] ?? 0),
    'approved'              => (int)($statsRow['approved'] ?? 0),
    'published'             => (int)($statsRow['published'] ?? 0),
    'visual_outputs'        => $outputsCount,
    'iec_distributed'       => (int)($statsRow['iec_distributed'] ?? 0),
];

// 2. Monthly Trend (Last 12 Months)
$monthlyRes = $conn->query("SELECT 
    DATE_FORMAT(session_date, '%b %Y') AS month_label,
    DATE_FORMAT(session_date, '%Y-%m') AS month_key,
    COUNT(*) AS activities,
    COALESCE(SUM(participant_count), 0) AS participants
    FROM ward_health_education_sessions
    WHERE session_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY month_key, month_label
    ORDER BY month_key ASC");

$monthlyTrend = [];
if ($monthlyRes) {
    while ($row = $monthlyRes->fetch_assoc()) {
        $monthlyTrend[] = [
            'month' => $row['month_label'],
            'activities' => (int)$row['activities'],
            'participants' => (int)$row['participants']
        ];
    }
}

// 3. Activities by Ward
$wardRes = $conn->query("SELECT ward_area AS ward, COUNT(*) AS count, COALESCE(SUM(participant_count), 0) AS participants FROM ward_health_education_sessions GROUP BY ward_area ORDER BY count DESC LIMIT 10");
$byWard = [];
if ($wardRes) {
    while ($row = $wardRes->fetch_assoc()) {
        $byWard[] = [
            'ward' => $row['ward'] ?: 'Unassigned',
            'count' => (int)$row['count'],
            'participants' => (int)$row['participants']
        ];
    }
}

// 4. Activities by Type
$typeRes = $conn->query("SELECT COALESCE(NULLIF(activity_type, ''), 'Health Education and Promotion') AS type_name, COUNT(*) AS count FROM ward_health_education_sessions GROUP BY type_name ORDER BY count DESC");
$byType = [];
if ($typeRes) {
    while ($row = $typeRes->fetch_assoc()) {
        $byType[] = [
            'name' => $row['type_name'],
            'value' => (int)$row['count']
        ];
    }
}

// 5. Top Health Topics
$topicRes = $conn->query("SELECT health_topic AS topic, COUNT(*) AS count FROM ward_health_education_sessions GROUP BY health_topic ORDER BY count DESC LIMIT 8");
$topTopics = [];
if ($topicRes) {
    while ($row = $topicRes->fetch_assoc()) {
        $topTopics[] = [
            'topic' => $row['topic'],
            'count' => (int)$row['count']
        ];
    }
}

// 6. Teaching Methods
$methodRes = $conn->query("SELECT COALESCE(NULLIF(teaching_method, ''), 'Lecture / Discussion') AS method, COUNT(*) AS count FROM ward_health_education_sessions GROUP BY method ORDER BY count DESC LIMIT 6");
$teachingMethods = [];
if ($methodRes) {
    while ($row = $methodRes->fetch_assoc()) {
        $teachingMethods[] = [
            'method' => $row['method'],
            'count' => (int)$row['count']
        ];
    }
}

// 7. Status Distribution
$statusRes = $conn->query("SELECT status, COUNT(*) AS count FROM ward_health_education_sessions GROUP BY status ORDER BY count DESC");
$statusDist = [];
if ($statusRes) {
    while ($row = $statusRes->fetch_assoc()) {
        $statusDist[] = [
            'status' => $row['status'],
            'count' => (int)$row['count']
        ];
    }
}

// 8. Recent Activities (limit 8)
$recentRes = $conn->query("SELECT id, title, activity_type, session_date, ward_area, lead_office, health_topic, facilitator, participant_count, status, documentation_percentage FROM ward_health_education_sessions ORDER BY session_date DESC, id DESC LIMIT 8");
$recentActivities = [];
if ($recentRes) {
    while ($row = $recentRes->fetch_assoc()) {
        $recentActivities[] = [
            'id' => (int)$row['id'],
            'title' => $row['title'],
            'activity_type' => $row['activity_type'] ?: 'Health Education Activity',
            'session_date' => $row['session_date'],
            'ward_area' => $row['ward_area'],
            'lead_office' => $row['lead_office'],
            'health_topic' => $row['health_topic'],
            'facilitator' => $row['facilitator'],
            'participant_count' => (int)$row['participant_count'],
            'status' => $row['status'],
            'documentation_percentage' => (int)$row['documentation_percentage']
        ];
    }
}

jsonResponse(true, 'Overview loaded successfully', [
    'kpis' => $kpis,
    'charts' => [
        'monthlyTrend' => $monthlyTrend,
        'byWard' => $byWard,
        'byType' => $byType,
        'topTopics' => $topTopics,
        'teachingMethods' => $teachingMethods,
        'statusDist' => $statusDist
    ],
    'recentActivities' => $recentActivities
]);
