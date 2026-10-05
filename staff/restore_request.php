<?php

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";
include "../includes/activity_log.php";

requireRole([
    "supervisor",
    "admin"
]);
requireModule($conn, "review_requests");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header(
        "Location: " .
        BASE_URL .
        "/staff/archived_requests.php"
    );

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
    alertBack("Invalid security token.");
}

$requestId = filter_input(
    INPUT_POST,
    "request_id",
    FILTER_VALIDATE_INT
);

if (!$requestId) {
    alertBack("Invalid request ID.");
}

try {
    $conn->begin_transaction();

    $stmt = $conn->prepare(
        "UPDATE job_requests
         SET archived = 0,
             archived_at = NULL,
             archived_by = NULL
         WHERE id = ?
           AND archived = 1"
    );

    $stmt->bind_param(
        "i",
        $requestId
    );

    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        throw new RuntimeException(
            "Archived request not found."
        );
    }

    $stmt->close();

    addRequestActivity(
        $conn,
        $requestId,
        (int) ($_SESSION["user_id"] ?? 0),
        "restored",
        "Request Restored",
        "The request was restored from the archive.",
        "Archived",
        "Closed"
    );

    $conn->commit();

    $_SESSION["request_message"] = [
        "type" => "success",
        "text" => "Request restored successfully."
    ];

    header(
        "Location: " .
        BASE_URL .
        "/staff/archived_requests.php"
    );

    exit;
} catch (Throwable $exception) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }

    alertBack(
        "Unable to restore request: " .
        $exception->getMessage()
    );
}
