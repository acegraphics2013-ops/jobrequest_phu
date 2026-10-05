<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/health_education_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Method not allowed', [], 405);
}

requireWhePermission($conn, 'whe_reports');

$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$typeFilter = trim((string)($_GET['activity_type'] ?? ''));
$wardFilter = trim((string)($_GET['ward_area'] ?? ''));
$officeFilter = trim((string)($_GET['lead_office'] ?? ''));
$topicFilter = trim((string)($_GET['health_topic'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));

$whereClauses = ['1=1'];
$params = [];
$types = '';

if ($dateFrom !== '') {
    $whereClauses[] = 'session_date >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}
if ($dateTo !== '') {
    $whereClauses[] = 'session_date <= ?';
    $params[] = $dateTo;
    $types .= 's';
}
if ($typeFilter !== '') {
    $whereClauses[] = 'activity_type = ?';
    $params[] = $typeFilter;
    $types .= 's';
}
if ($wardFilter !== '') {
    $whereClauses[] = 'ward_area = ?';
    $params[] = $wardFilter;
    $types .= 's';
}
if ($officeFilter !== '') {
    $whereClauses[] = 'lead_office = ?';
    $params[] = $officeFilter;
    $types .= 's';
}
if ($topicFilter !== '') {
    $whereClauses[] = 'health_topic = ?';
    $params[] = $topicFilter;
    $types .= 's';
}
if ($statusFilter !== '') {
    $whereClauses[] = 'status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}

$whereSql = implode(' AND ', $whereClauses);

// 1. KPI Aggregation
$kpiSql = "SELECT 
    COUNT(*) AS total_activities,
    COALESCE(SUM(participant_count), 0) AS total_participants,
    COALESCE(SUM(patients_count), 0) AS total_patients,
    COALESCE(SUM(watchers_count), 0) AS total_watchers,
    COALESCE(SUM(employees_count), 0) AS total_employees,
    COALESCE(SUM(others_count), 0) AS total_others,
    COUNT(DISTINCT ward_area) AS wards_covered,
    COUNT(DISTINCT health_topic) AS topics_covered,
    SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) AS approved_count,
    SUM(CASE WHEN status='Published' THEN 1 ELSE 0 END) AS published_count,
    COALESCE(SUM(iec_quantity), 0) AS iec_distributed
    FROM ward_health_education_sessions WHERE $whereSql";

$kpiStmt = $conn->prepare($kpiSql);
if (!empty($types)) {
    $kpiStmt->bind_param($types, ...$params);
}
$kpiStmt->execute();
$kpis = $kpiStmt->get_result()->fetch_assoc();
$kpiStmt->close();

// Outputs count for filtered sessions
$outSql = "SELECT COUNT(*) AS total FROM ward_health_education_outputs o JOIN ward_health_education_sessions s ON s.id = o.session_id WHERE $whereSql";
$outStmt = $conn->prepare($outSql);
if (!empty($types)) {
    $outStmt->bind_param($types, ...$params);
}
$outStmt->execute();
$outputsCount = (int)($outStmt->get_result()->fetch_assoc()['total'] ?? 0);
$outStmt->close();

$summary = [
    'total_activities' => (int)($kpis['total_activities'] ?? 0),
    'total_participants' => (int)($kpis['total_participants'] ?? 0),
    'patients_count' => (int)($kpis['total_patients'] ?? 0),
    'watchers_count' => (int)($kpis['total_watchers'] ?? 0),
    'employees_count' => (int)($kpis['total_employees'] ?? 0),
    'others_count' => (int)($kpis['total_others'] ?? 0),
    'wards_covered' => (int)($kpis['wards_covered'] ?? 0),
    'topics_covered' => (int)($kpis['topics_covered'] ?? 0),
    'approved_count' => (int)($kpis['approved_count'] ?? 0),
    'published_count' => (int)($kpis['published_count'] ?? 0),
    'visual_outputs' => $outputsCount,
    'iec_distributed' => (int)($kpis['iec_distributed'] ?? 0),
];

// 2. Monthly Trend Chart Data
$trendSql = "SELECT DATE_FORMAT(session_date, '%b %Y') AS month_label, DATE_FORMAT(session_date, '%Y-%m') AS month_key, COUNT(*) AS activities, COALESCE(SUM(participant_count), 0) AS participants FROM ward_health_education_sessions WHERE $whereSql GROUP BY month_key, month_label ORDER BY month_key ASC";
$tStmt = $conn->prepare($trendSql);
if (!empty($types)) {
    $tStmt->bind_param($types, ...$params);
}
$tStmt->execute();
$tRes = $tStmt->get_result();
$monthlyTrend = [];
while ($row = $tRes->fetch_assoc()) {
    $monthlyTrend[] = [
        'month' => $row['month_label'],
        'activities' => (int)$row['activities'],
        'participants' => (int)$row['participants']
    ];
}
$tStmt->close();

// 3. Ward Breakdown
$wSql = "SELECT ward_area AS ward, COUNT(*) AS count, COALESCE(SUM(participant_count), 0) AS participants FROM ward_health_education_sessions WHERE $whereSql GROUP BY ward_area ORDER BY count DESC";
$wStmt = $conn->prepare($wSql);
if (!empty($types)) {
    $wStmt->bind_param($types, ...$params);
}
$wStmt->execute();
$wRes = $wStmt->get_result();
$wardCoverage = [];
while ($row = $wRes->fetch_assoc()) {
    $wardCoverage[] = [
        'ward' => $row['ward'] ?: 'Unassigned',
        'count' => (int)$row['count'],
        'participants' => (int)$row['participants']
    ];
}
$wStmt->close();

// 4. Activity Type Distribution
$typeSql = "SELECT COALESCE(NULLIF(activity_type, ''), 'Health Education Activity') AS type_name, COUNT(*) AS count FROM ward_health_education_sessions WHERE $whereSql GROUP BY type_name ORDER BY count DESC";
$typeStmt = $conn->prepare($typeSql);
if (!empty($types)) {
    $typeStmt->bind_param($types, ...$params);
}
$typeStmt->execute();
$typeRes = $typeStmt->get_result();
$typeDist = [];
while ($row = $typeRes->fetch_assoc()) {
    $typeDist[] = [
        'name' => $row['type_name'],
        'value' => (int)$row['count']
    ];
}
$typeStmt->close();

// 5. Raw Rows for table view / export
$rowsSql = "SELECT id, title, activity_type, session_date, ward_area, lead_office, health_topic, facilitator, participant_count, patients_count, watchers_count, employees_count, others_count, status, documentation_percentage FROM ward_health_education_sessions WHERE $whereSql ORDER BY session_date DESC, id DESC LIMIT 500";
$rStmt = $conn->prepare($rowsSql);
if (!empty($types)) {
    $rStmt->bind_param($types, ...$params);
}
$rStmt->execute();
$rRes = $rStmt->get_result();
$reportRows = [];
while ($row = $rRes->fetch_assoc()) {
    $reportRows[] = [
        'id' => (int)$row['id'],
        'title' => $row['title'],
        'activity_type' => $row['activity_type'] ?: 'Health Education Activity',
        'session_date' => $row['session_date'],
        'ward_area' => $row['ward_area'],
        'lead_office' => $row['lead_office'],
        'health_topic' => $row['health_topic'],
        'facilitator' => $row['facilitator'],
        'participant_count' => (int)$row['participant_count'],
        'patients_count' => (int)$row['patients_count'],
        'watchers_count' => (int)$row['watchers_count'],
        'employees_count' => (int)$row['employees_count'],
        'others_count' => (int)$row['others_count'],
        'status' => $row['status'],
        'documentation_percentage' => (int)$row['documentation_percentage']
    ];
}
$rStmt->close();

jsonResponse(true, 'Report data generated successfully', [
    'summary' => $summary,
    'charts' => [
        'monthlyTrend' => $monthlyTrend,
        'wardCoverage' => $wardCoverage,
        'typeDist' => $typeDist
    ],
    'rows' => $reportRows
]);
