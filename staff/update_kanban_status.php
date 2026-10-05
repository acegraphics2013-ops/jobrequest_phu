<?php

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";
include "../includes/advanced_helpers.php";
include "../includes/activity_log.php";

requireRole([
    "staff",
    "supervisor",
    "admin"
]);
requireModule($conn, "kanban");

header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "POST request required."
    ]);

    exit;
}

$sessionToken = (string) (
    $_SESSION["csrf_token"] ?? ""
);

$submittedToken = (string) (
    $_POST["csrf_token"] ?? ""
);

if (
    $sessionToken === "" ||
    !hash_equals(
        $sessionToken,
        $submittedToken
    )
) {
    http_response_code(419);

    echo json_encode([
        "success" => false,
        "message" => "Invalid security token."
    ]);

    exit;
}

$requestId = filter_input(
    INPUT_POST,
    "request_id",
    FILTER_VALIDATE_INT
);

$newStatus = trim(
    (string) ($_POST["new_status"] ?? "")
);

$role = $_SESSION["role"] ?? "";

if (!$requestId || $newStatus === "") {
    http_response_code(422);

    echo json_encode([
        "success" => false,
        "message" => "Invalid request data."
    ]);

    exit;
}

$stmt = $conn->prepare(
    "SELECT
        approval_status,
        archived
     FROM job_requests
     WHERE id = ?
     LIMIT 1"
);

$stmt->bind_param("i", $requestId);
$stmt->execute();

$request = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();

if (!$request) {
    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Job request not found."
    ]);

    exit;
}

if ((int) $request["archived"] === 1) {
    http_response_code(409);

    echo json_encode([
        "success" => false,
        "message" => "Archived requests cannot be moved."
    ]);

    exit;
}

$oldStatus = $request["approval_status"];

if (
    !allowedKanbanTransition(
        $role,
        $oldStatus,
        $newStatus
    )
) {
    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" =>
            "Your role cannot move a request from " .
            $oldStatus .
            " to " .
            $newStatus .
            "."
    ]);

    exit;
}

try {
    $conn->begin_transaction();

    $update = $conn->prepare(
        "UPDATE job_requests
         SET approval_status = ?
         WHERE id = ?"
    );

    $update->bind_param(
        "si",
        $newStatus,
        $requestId
    );

    $update->execute();
    $update->close();

    addRequestActivity(
        $conn,
        $requestId,
        (int) ($_SESSION["user_id"] ?? 0),
        "workflow_move",
        "Workflow Status Updated",
        "The request was moved through the Kanban board.",
        $oldStatus,
        $newStatus
    );

    $conn->commit();

    echo json_encode([
        "success" => true,
        "message" => "Status updated."
    ]);
} catch (Throwable $exception) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => $exception->getMessage()
    ]);
}
