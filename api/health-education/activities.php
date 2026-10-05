<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/health_education_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Method not allowed', [], 405);
}

requireWhePermission($conn, 'whe_sessions');

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(1, (int)($_GET['limit'] ?? 10)));
$offset = ($page - 1) * $limit;

$search = trim((string)($_GET['search'] ?? ''));
$typeFilter = trim((string)($_GET['activity_type'] ?? ''));
$wardFilter = trim((string)($_GET['ward_area'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$sortBy = trim((string)($_GET['sort_by'] ?? 'session_date'));
$order = strtoupper(trim((string)($_GET['order'] ?? 'DESC')));
if (!in_array($order, ['ASC', 'DESC'], true)) {
    $order = 'DESC';
}

$validSorts = [
    'id' => 's.id',
    'session_date' => 's.session_date',
    'title' => 's.title',
    'activity_type' => 's.activity_type',
    'ward_area' => 's.ward_area',
    'lead_office' => 's.lead_office',
    'health_topic' => 's.health_topic',
    'participant_count' => 's.participant_count',
    'status' => 's.status',
    'documentation_percentage' => 's.documentation_percentage',
    'created_at' => 's.created_at'
];

$sortColumn = $validSorts[$sortBy] ?? 's.session_date';

$whereClauses = ['1=1'];
$params = [];
$types = '';

if ($search !== '') {
    $whereClauses[] = '(s.title LIKE ? OR s.health_topic LIKE ? OR s.ward_area LIKE ? OR s.lead_office LIKE ? OR s.facilitator LIKE ?)';
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    $types .= 'sssss';
}

if ($typeFilter !== '') {
    $whereClauses[] = 's.activity_type = ?';
    $params[] = $typeFilter;
    $types .= 's';
}

if ($wardFilter !== '') {
    $whereClauses[] = 's.ward_area = ?';
    $params[] = $wardFilter;
    $types .= 's';
}

if ($statusFilter !== '') {
    $whereClauses[] = 's.status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}

if ($dateFrom !== '') {
    $whereClauses[] = 's.session_date >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}

if ($dateTo !== '') {
    $whereClauses[] = 's.session_date <= ?';
    $params[] = $dateTo;
    $types .= 's';
}

$whereSql = implode(' AND ', $whereClauses);

// Count total matching
$countSql = "SELECT COUNT(*) AS total FROM ward_health_education_sessions s WHERE $whereSql";
$countStmt = $conn->prepare($countSql);
if (!empty($types)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRecords = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();

$totalPages = max(1, (int)ceil($totalRecords / $limit));

// Fetch page items
$dataSql = "SELECT s.*, 
    COALESCE(u.name, 'System User') AS submitter_name,
    (SELECT COUNT(*) FROM ward_health_education_outputs o WHERE o.session_id = s.id) AS outputs_count
    FROM ward_health_education_sessions s
    LEFT JOIN users u ON u.id = s.submitted_by
    WHERE $whereSql
    ORDER BY $sortColumn $order, s.id DESC
    LIMIT ? OFFSET ?";

$dataParams = array_merge($params, [$limit, $offset]);
$dataTypes = $types . 'ii';

$dataStmt = $conn->prepare($dataSql);
$dataStmt->bind_param($dataTypes, ...$dataParams);
$dataStmt->execute();
$res = $dataStmt->get_result();

$activities = [];
while ($row = $res->fetch_assoc()) {
    $activities[] = [
        'id' => (int)$row['id'],
        'submitted_by' => (int)$row['submitted_by'],
        'submitter_name' => $row['submitter_name'],
        'title' => $row['title'],
        'activity_type' => $row['activity_type'] ?: 'Health Education Session / Lecture',
        'session_date' => $row['session_date'],
        'activity_time' => $row['activity_time'],
        'ward_area' => $row['ward_area'],
        'lead_office' => $row['lead_office'],
        'partner_offices' => $row['partner_offices'],
        'health_topic' => $row['health_topic'],
        'facilitator' => $row['facilitator'],
        'target_participants' => $row['target_participants'],
        'participant_count' => (int)$row['participant_count'],
        'patients_count' => (int)$row['patients_count'],
        'watchers_count' => (int)$row['watchers_count'],
        'employees_count' => (int)$row['employees_count'],
        'others_count' => (int)$row['others_count'],
        'teaching_method' => $row['teaching_method'],
        'iec_materials_used' => $row['iec_materials_used'],
        'iec_quantity' => (int)$row['iec_quantity'],
        'status' => $row['status'],
        'documentation_percentage' => (int)$row['documentation_percentage'],
        'outputs_count' => (int)$row['outputs_count'],
        'publication_id' => $row['publication_id'] ? (int)$row['publication_id'] : null,
        'created_at' => $row['created_at']
    ];
}
$dataStmt->close();

// Also fetch distinct ward options and activity type options for frontend filter dropdowns
$wardsRes = $conn->query("SELECT DISTINCT ward_area FROM ward_health_education_sessions ORDER BY ward_area ASC");
$wardOptions = [];
if ($wardsRes) {
    while ($w = $wardsRes->fetch_assoc()) {
        if (!empty($w['ward_area'])) $wardOptions[] = $w['ward_area'];
    }
}

jsonResponse(true, 'Activities retrieved successfully', [
    'activities' => $activities,
    'pagination' => [
        'page' => $page,
        'limit' => $limit,
        'totalRecords' => $totalRecords,
        'totalPages' => $totalPages
    ],
    'filterOptions' => [
        'wards' => $wardOptions,
        'activityTypes' => [
            'Health Education and Promotion',
            'Health Education Session / Lecture',
            'Health Promotion Campaign / Advocacy',
            'Symposium / Health Forum',
            'Staff Health Education / Wellness Activity',
            'Screening and Early Detection Activity',
            'Community-Based Health Promotion',
            'Others'
        ],
        'statuses' => [
            'Draft',
            'Scheduled',
            'Conducted',
            'For Documentation',
            'For Review',
            'For Revision',
            'Approved',
            'For Publication',
            'Published',
            'Archived'
        ]
    ]
]);
