<?php

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";

requireRole([
    "supervisor",
    "admin"
]);
requireModule($conn, "review_requests");

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header(
        "Location: " .
        BASE_URL .
        "/staff/requests.php"
    );

    exit;
}

/* =========================================================
   CSRF VALIDATION
========================================================= */
$submittedToken = (string) (
    $_POST["csrf_token"] ?? ""
);

$sessionToken = (string) (
    $_SESSION["csrf_token"] ?? ""
);

if (
    $sessionToken === "" ||
    !hash_equals(
        $sessionToken,
        $submittedToken
    )
) {
    alertBack(
        "Invalid security token. Please refresh the page and try again."
    );
}

/* =========================================================
   REQUEST ID
========================================================= */
$requestId = filter_input(
    INPUT_POST,
    "request_id",
    FILTER_VALIDATE_INT
);

if (
    !$requestId ||
    $requestId <= 0
) {
    alertBack(
        "Invalid job request ID."
    );
}

/* =========================================================
   GET REQUEST
========================================================= */
$stmt = $conn->prepare(
    "SELECT
        id,
        job_no,
        attachment
     FROM job_requests
     WHERE id = ?
     LIMIT 1"
);

$stmt->bind_param(
    "i",
    $requestId
);

$stmt->execute();

$request = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();

if (!$request) {
    alertBack(
        "Job request not found."
    );
}

/* =========================================================
   ATTACHMENT PATHS
========================================================= */
$attachmentPaths = [];

foreach (
    attachmentList(
        (string) ($request["attachment"] ?? "")
    ) as $attachmentName
) {
    $attachmentPaths[] =
        uploadsRootDir() .
        DIRECTORY_SEPARATOR .
        basename($attachmentName);
}

/* =========================================================
   DELETE REQUEST
========================================================= */
try {
    $conn->begin_transaction();

    /*
     * Delete IEC materials.
     */
    $iecTableCheck = $conn->query(
        "SHOW TABLES LIKE 'job_request_iec_items'"
    );

    if (
        $iecTableCheck &&
        $iecTableCheck->num_rows > 0
    ) {
        $iecStmt = $conn->prepare(
            "DELETE FROM job_request_iec_items
             WHERE request_id = ?"
        );

        $iecStmt->bind_param(
            "i",
            $requestId
        );

        $iecStmt->execute();
        $iecStmt->close();
    }

    /*
     * Delete notifications.
     */
    $notificationTableCheck = $conn->query(
        "SHOW TABLES LIKE 'notifications'"
    );

    if (
        $notificationTableCheck &&
        $notificationTableCheck->num_rows > 0
    ) {
        $notificationStmt = $conn->prepare(
            "DELETE FROM notifications
             WHERE request_id = ?"
        );

        $notificationStmt->bind_param(
            "i",
            $requestId
        );

        $notificationStmt->execute();
        $notificationStmt->close();
    }

    /*
     * Delete the main request.
     */
    $deleteStmt = $conn->prepare(
        "DELETE FROM job_requests
         WHERE id = ?"
    );

    $deleteStmt->bind_param(
        "i",
        $requestId
    );

    $deleteStmt->execute();

    if (
        $deleteStmt->affected_rows !== 1
    ) {
        throw new RuntimeException(
            "The job request could not be deleted."
        );
    }

    $deleteStmt->close();

    $conn->commit();

    /*
     * Delete the uploaded files only after the
     * database transaction has succeeded.
     */
    foreach ($attachmentPaths as $attachmentPath) {
        if (
            $attachmentPath !== null &&
            is_file($attachmentPath)
        ) {
            @unlink($attachmentPath);
        }
    }

    $_SESSION["request_message"] = [
        "type" => "success",
        "text" =>
            "Job request " .
            $request["job_no"] .
            " was deleted successfully."
    ];

    header(
        "Location: " .
        BASE_URL .
        "/staff/requests.php"
    );

    exit;

} catch (Throwable $exception) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }

    error_log(
        "Delete job request error: " .
        $exception->getMessage()
    );

    $_SESSION["request_message"] = [
        "type" => "danger",
        "text" =>
            "Unable to delete the job request: " .
            $exception->getMessage()
    ];

    header(
        "Location: " .
        BASE_URL .
        "/staff/requests.php"
    );

    exit;
}
