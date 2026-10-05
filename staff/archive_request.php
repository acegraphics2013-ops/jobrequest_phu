<?php

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";
include "../includes/activity_log.php";

requireRole([
    "staff",
    "supervisor",
    "admin"
]);
requireModule($conn, "review_requests");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header(
        "Location: " .
        BASE_URL .
        "/staff/requests.php"
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

$stmt = $conn->prepare(
    "SELECT
        job_no,
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
    alertBack("Request not found.");
}

if ((int) $request["archived"] === 1) {
    alertBack("This request is already archived.");
}

try {
    $conn->begin_transaction();

    $userId = (int) (
        $_SESSION["user_id"] ?? 0
    );

    $stmt = $conn->prepare(
        "UPDATE job_requests
         SET archived = 1,
             archived_at = NOW(),
             archived_by = ?
         WHERE id = ?"
    );

    $stmt->bind_param(
        "ii",
        $userId,
        $requestId
    );

    $stmt->execute();
    $stmt->close();

    addRequestActivity(
        $conn,
        $requestId,
        $userId,
        "archived",
        "Request Archived",
        "The request was moved to the archive.",
        (string)($request["approval_status"] ?? ""),
        "Archived"
    );

    $conn->commit();

    $_SESSION["request_message"] = [
        "type" => "success",
        "text" =>
            "Job request " .
            $request["job_no"] .
            " was archived."
    ];

    $returnTo =
        trim(
            (string) (
                $_POST["return_to"]
                ?? ""
            )
        );

    if ($returnTo === "my_requests") {
        header(
            "Location: " .
            BASE_URL .
            "/user/my_requests.php"
        );

        exit;
    }

    $referer =
        (string) (
            $_SERVER["HTTP_REFERER"]
            ?? ""
        );

    if (
        $referer !== ""
        &&
        str_contains(
            $referer,
            "/user/my_requests.php"
        )
    ) {
        header(
            "Location: " .
            BASE_URL .
            "/user/my_requests.php"
        );

        exit;
    }

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
        "Unable to archive request: " .
        $exception->getMessage()
    );
}
