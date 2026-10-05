<?php

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}

$currentUser = requireApiRole(['admin', 'staff']);

$page   = max(1, (int) ($_GET['page'] ?? 1));
$limit  = max(1, min(100, (int) ($_GET['limit'] ?? 15)));
$offset = ($page - 1) * $limit;
$search = trim((string) ($_GET['search'] ?? ''));

$whereParts = [];
$params = [];
$types = "";

if (!empty($search)) {
    $whereParts[] = "(name LIKE ? OR email LIKE ? OR department LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

$whereClause = !empty($whereParts) ? "WHERE " . implode(" AND ", $whereParts) : "";

// Count total
$countSql = "SELECT COUNT(*) as total FROM users $whereSql";
$stmt = $conn->prepare($countSql);
if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$totalRecords = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

// Fetch users
$dataSql = "SELECT id, name, email, role, department, status, created_at FROM users $whereSql ORDER BY id DESC LIMIT ? OFFSET ?";
$dataParams = $params;
$dataParams[] = $limit;
$dataParams[] = $offset;
$dataTypes = $types . "ii";

$stmt = $conn->prepare($dataSql);
$stmt->bind_param($dataTypes, ...$dataParams);
$stmt->execute();
$res = $stmt->get_result();

$users = [];
while ($row = $res->fetch_assoc()) {
    $users[] = [
        'id'         => (int) $row['id'],
        'name'       => $row['name'],
        'email'      => $row['email'],
        'role'       => $row['role'],
        'department' => $row['department'] ?? 'N/A',
        'status'     => $row['status'] ?? 'Active',
        'created_at' => $row['created_at'] ?? ''
    ];
}
$stmt->close();

jsonResponse(true, 'Users fetched successfully', [
    'users'      => $users,
    'pagination' => [
        'total'       => $totalRecords,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => ceil($totalRecords / $limit)
    ]
]);
