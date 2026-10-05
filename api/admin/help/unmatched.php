<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU Workbench - Help Assistant Unmatched Queries API Endpoint
|--------------------------------------------------------------------------
*/

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/auth.php';

checkLogin();
requireRole(['admin', 'supervisor']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$rawInput = file_get_contents('php://input');
$input = json_decode((string)$rawInput, true) ?: $_POST;

if ($method === 'GET') {
    $res = $conn->query("SELECT * FROM help_unmatched_queries ORDER BY count DESC, last_asked_at DESC LIMIT 50");
    $queries = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $queries[] = $row;
        }
    }
    echo json_encode(['success' => true, 'unmatched' => $queries], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    $id = (int)($input['id'] ?? 0);
    $status = in_array($input['status'] ?? '', ['pending', 'converted', 'ignored'], true) ? $input['status'] : 'ignored';

    $stmt = $conn->prepare("UPDATE help_unmatched_queries SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $status, $id);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'message' => 'Status updated.'], JSON_UNESCAPED_UNICODE);
    exit;
}
