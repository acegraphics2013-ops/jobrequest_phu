<?php

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}

$currentUser = requireApiRole(['admin', 'staff', 'supervisor']);

$page   = max(1, (int) ($_GET['page'] ?? 1));
$limit  = max(1, min(100, (int) ($_GET['limit'] ?? 15)));
$offset = ($page - 1) * $limit;

$logs = [];
$total = 0;

$resTable = $conn->query("SHOW TABLES LIKE 'request_activity_logs'");
if ($resTable && $resTable->num_rows > 0) {
    // Count total
    $countRes = $conn->query("SELECT COUNT(*) as cnt FROM request_activity_logs");
    if ($countRes) {
        $total = (int) ($countRes->fetch_assoc()['cnt'] ?? 0);
    }

    $sql = "SELECT l.id, l.request_id, l.user_id, u.name as user_name, l.action_type, l.action_title, l.description, l.old_status, l.new_status, l.created_at, j.job_no, j.project_title
            FROM request_activity_logs l
            LEFT JOIN users u ON l.user_id = u.id
            LEFT JOIN job_requests j ON l.request_id = j.id
            ORDER BY l.id DESC LIMIT ? OFFSET ?";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $limit, $offset);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $logs[] = [
                'id'            => (int) $row['id'],
                'request_id'    => (int) $row['request_id'],
                'control_number'=> $row['job_no'] ?? ('REQ-' . $row['request_id']),
                'project_title' => $row['project_title'] ?? 'General Request',
                'user_id'       => (int) $row['user_id'],
                'user_name'     => $row['user_name'] ?? 'System',
                'action_type'   => $row['action_type'] ?? 'LOG',
                'action_title'  => $row['action_title'] ?? 'Activity',
                'description'   => $row['description'] ?? '',
                'old_status'    => $row['old_status'] ?? '',
                'new_status'    => $row['new_status'] ?? '',
                'created_at'    => $row['created_at'] ?? ''
            ];
        }
        $stmt->close();
    }
}

jsonResponse(true, 'Activity logs fetched successfully', [
    'logs'       => $logs,
    'pagination' => [
        'total'       => $total,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => ceil($total / $limit)
    ]
]);
