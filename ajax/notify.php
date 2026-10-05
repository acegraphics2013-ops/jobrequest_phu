<?php
header('Content-Type: application/json');
include __DIR__ . '/../includes/config.php';
include __DIR__ . '/../includes/auth.php';

// Only logged-in users (config.php already started session)
checkLogin();
if (empty($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthenticated']);
    exit;
}

$role = $_SESSION['role'] ?? '';

$response = ['count' => 0, 'rows' => []];

if ($role === 'staff' || $role === 'supervisor' || $role === 'admin') {
    $res = $conn->query("SELECT COUNT(*) as c FROM job_requests WHERE approval_status='Pending'");
    $response['count'] = $res ? intval($res->fetch_assoc()['c']) : 0;

    $list = $conn->query("SELECT id, job_no, department, date_request FROM job_requests WHERE approval_status='Pending' ORDER BY id DESC LIMIT 5");
    if ($list) {
        while ($r = $list->fetch_assoc()) {
            $response['rows'][] = $r;
        }
    }
}

echo json_encode($response);
