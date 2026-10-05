<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/health_education_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Method not allowed', [], 405);
}

requireWhePermission($conn, 'whe_visuals');

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(1, (int)($_GET['limit'] ?? 24)));
$offset = ($page - 1) * $limit;

$category = trim((string)($_GET['category'] ?? ''));
$sessionId = (int)($_GET['session_id'] ?? 0);
$search = trim((string)($_GET['search'] ?? ''));

$whereClauses = ['1=1'];
$params = [];
$types = '';

if ($category !== '' && $category !== 'All') {
    $whereClauses[] = 'LOWER(o.output_type) = LOWER(?)';
    $params[] = $category;
    $types .= 's';
}

if ($sessionId > 0) {
    $whereClauses[] = 'o.session_id = ?';
    $params[] = $sessionId;
    $types .= 'i';
}

if ($search !== '') {
    $whereClauses[] = '(o.title LIKE ? OR s.title LIKE ? OR s.ward_area LIKE ? OR s.health_topic LIKE ?)';
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    $types .= 'ssss';
}

$whereSql = implode(' AND ', $whereClauses);

// Count matching outputs
$countSql = "SELECT COUNT(*) AS total FROM ward_health_education_outputs o JOIN ward_health_education_sessions s ON s.id = o.session_id WHERE $whereSql";
$countStmt = $conn->prepare($countSql);
if (!empty($types)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRecords = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();

$totalPages = max(1, (int)ceil($totalRecords / $limit));

// Fetch list
$dataSql = "SELECT o.*, 
    s.title AS session_title, 
    s.session_date, 
    s.ward_area, 
    s.health_topic, 
    s.lead_office,
    s.cover_output_id,
    COALESCE(u.name, 'User') AS uploader_name
    FROM ward_health_education_outputs o
    JOIN ward_health_education_sessions s ON s.id = o.session_id
    LEFT JOIN users u ON u.id = o.uploaded_by
    WHERE $whereSql
    ORDER BY o.created_at DESC, o.id DESC
    LIMIT ? OFFSET ?";

$dataParams = array_merge($params, [$limit, $offset]);
$dataTypes = $types . 'ii';

$dataStmt = $conn->prepare($dataSql);
$dataStmt->bind_param($dataTypes, ...$dataParams);
$dataStmt->execute();
$res = $dataStmt->get_result();

$items = [];
while ($row = $res->fetch_assoc()) {
    $fileId = (int)$row['id'];
    $items[] = [
        'id' => $fileId,
        'session_id' => (int)$row['session_id'],
        'session_title' => $row['session_title'],
        'session_date' => $row['session_date'],
        'ward_area' => $row['ward_area'],
        'health_topic' => $row['health_topic'],
        'lead_office' => $row['lead_office'],
        'uploaded_by' => (int)$row['uploaded_by'],
        'uploader_name' => $row['uploader_name'],
        'output_type' => $row['output_type'],
        'title' => $row['title'],
        'description' => $row['description'],
        'original_name' => $row['original_name'],
        'extension' => $row['extension'],
        'mime_type' => $row['mime_type'],
        'file_size' => (int)$row['file_size'],
        'is_cover' => ((int)$row['cover_output_id'] === $fileId),
        'download_url' => "/backend-api/health-education/file.php?id=$fileId",
        'created_at' => $row['created_at']
    ];
}
$dataStmt->close();

jsonResponse(true, 'Documentation outputs fetched successfully', [
    'outputs' => $items,
    'pagination' => [
        'page' => $page,
        'limit' => $limit,
        'totalRecords' => $totalRecords,
        'totalPages' => $totalPages
    ],
    'categories' => [
        'All',
        'Before Activity',
        'During Activity',
        'After Activity',
        'Group Photo',
        'Photo Documentation',
        'IEC Material',
        'Attendance Sheet',
        'Presentation',
        'Video Documentation',
        'Other'
    ]
]);
