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

$requestId = postInt(
    "request_id"
);

$message = postString(
    "notification_message"
);

if ($requestId <= 0) {
    alertBack(
        "Invalid request ID."
    );
}

if ($message === "") {
    alertBack(
        "Notification message is required."
    );
}

/* =========================================================
   GET REQUEST
========================================================= */
$stmt = $conn->prepare(
    "SELECT
        id,
        job_no,
        requestor,
        project_title,
        approval_status,
        satisfaction_rating
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

if (
    !in_array(
        $request["approval_status"],
        [
            "Pending Review",
            "Completed",
            "For Evaluation"
        ],
        true
    )
) {
    alertBack(
        "The request must be completed before requesting an evaluation."
    );
}

if (
    $request["satisfaction_rating"] !== null
) {
    alertBack(
        "The evaluation has already been submitted."
    );
}

/* =========================================================
   SAVE NOTIFICATION
========================================================= */
try {
    $conn->begin_transaction();

    $notificationTitle =
        "Post Project Evaluation Required";

    $actionUrl =
        BASE_URL .
        "/user/evaluation.php?id=" .
        $requestId;

    $notificationType =
        "evaluation";

    $notifyStmt = $conn->prepare(
        "INSERT INTO notifications (
            user_id,
            request_id,
            title,
            message,
            notification_type,
            action_url,
            is_read
        )
        VALUES (?, ?, ?, ?, ?, ?, 0)"
    );

    $notifyStmt->bind_param(
        "iissss",
        $request["requestor"],
        $requestId,
        $notificationTitle,
        $message,
        $notificationType,
        $actionUrl
    );

    $notifyStmt->execute();
    $notifyStmt->close();

    /*
     * Change status only on the first notification.
     * Reminders retain For Evaluation.
     */
    if (
        $request["approval_status"] ===
        "Completed"
    ) {
        $status = "For Evaluation";

        $updateStmt = $conn->prepare(
            "UPDATE job_requests
             SET approval_status = ?,
                 action_log = CONCAT(
                    COALESCE(action_log, ''),
                    ?
                 )
             WHERE id = ?"
        );

        $actionEntry =
            "\nEvaluation requested by " .
            ($_SESSION["name"] ??
            "HPU Personnel") .
            " on " .
            date("Y-m-d H:i:s") .
            ".";

        $updateStmt->bind_param(
            "ssi",
            $status,
            $actionEntry,
            $requestId
        );

        $updateStmt->execute();
        $updateStmt->close();
    }

    $conn->commit();

    echo "
        <script>
            alert(
                'The requesting user has been notified successfully.'
            );

            window.location.href =
                '" .
                BASE_URL .
                "/staff/evaluation.php?id=" .
                $requestId .
                "';
        </script>
    ";

    exit;
} catch (Throwable $exception) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }

    error_log(
        "Evaluation notification error: " .
        $exception->getMessage()
    );

    alertBack(
        "Unable to notify the requesting user."
    );
}