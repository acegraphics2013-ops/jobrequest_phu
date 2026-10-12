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

if (!$requestId || $requestId <= 0) {
    alertBack("Invalid request ID.");
}

$cancelReason = trim((string) ($_POST["cancel_reason"] ?? ""));

$stmt = $conn->prepare(
    "SELECT
        id,
        job_no,
        project_title,
        requestor,
        approval_status,
        COALESCE(archived, 0) AS archived
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

if ((int) ($request["archived"] ?? 0) === 1) {
    alertBack("This request is archived and can no longer be cancelled.");
}

$currentStatus = (string) ($request["approval_status"] ?? "");

if ($currentStatus === "Cancelled") {
    alertBack("This request is already cancelled.");
}

if ($currentStatus === "Closed") {
    alertBack("A closed request can no longer be cancelled.");
}

$actorId = (int) ($_SESSION["user_id"] ?? 0);
$actorName = (string) ($_SESSION["name"] ?? "HPU Personnel");
$now = date("Y-m-d H:i:s");

$jobNo = (string) ($request["job_no"] ?? ("#" . $requestId));
$requestorId = (int) ($request["requestor"] ?? 0);

$notificationMessage = "Your job request {$jobNo} has been cancelled.";

if ($cancelReason !== "") {
    $notificationMessage .= " Reason: {$cancelReason}";
}

$notificationMessage .= " Please contact HPU if you have questions.";

try {
    $conn->begin_transaction();

    $actionEntry = "\nCancelled by {$actorName} on {$now}."
        . ($cancelReason !== "" ? " Reason: {$cancelReason}." : "");

    $updateStmt = $conn->prepare(
        "UPDATE job_requests
          SET approval_status = 'Cancelled',
              action_log = CONCAT(COALESCE(action_log, ''), ?)
          WHERE id = ?"
    );

    $updateStmt->bind_param("si", $actionEntry, $requestId);
    $updateStmt->execute();
    $updateStmt->close();

    /* Activity log (best effort - table may not exist on old installs). */
    try {
        $logCheck = $conn->query("SHOW TABLES LIKE 'request_activity_logs'");

        if ($logCheck && $logCheck->num_rows > 0) {
            addRequestActivity(
                $conn,
                (int) $requestId,
                $actorId,
                "cancelled",
                "Request Cancelled",
                "The request was cancelled by {$actorName}."
                    . ($cancelReason !== "" ? " Reason: {$cancelReason}" : ""),
                $currentStatus,
                "Cancelled",
                ["reason" => $cancelReason]
            );
        }
    } catch (Throwable $activityException) {
        error_log(
            "Cancel request activity-log error: " .
            $activityException->getMessage()
        );
    }

    /* Notify the requestor (best effort). */
    try {
        $notifCheck = $conn->query("SHOW TABLES LIKE 'notifications'");

        if ($notifCheck && $notifCheck->num_rows > 0 && $requestorId > 0) {
            $notificationType = "status_update";
            $typeColResult = $conn->query(
                "SHOW COLUMNS FROM notifications LIKE 'notification_type'"
            );

            if ($typeColResult) {
                $typeCol = $typeColResult->fetch_assoc();

                if (
                    $typeCol &&
                    str_starts_with(
                        strtolower((string) $typeCol["Type"]),
                        "enum("
                    )
                ) {
                    preg_match_all(
                        "/'((?:[^'\\\\]|\\\\.)*)'/",
                        (string) $typeCol["Type"],
                        $enumMatches
                    );

                    $enumValues = array_map(
                        static fn ($value) => stripcslashes($value),
                        $enumMatches[1] ?? []
                    );

                    foreach (
                        [
                            "status_update",
                            "request_update",
                            "general",
                            "evaluation"
                        ] as $candidateType
                    ) {
                        if (in_array($candidateType, $enumValues, true)) {
                            $notificationType = $candidateType;
                            break;
                        }
                    }

                    if (
                        !in_array($notificationType, $enumValues, true) &&
                        !empty($enumValues)
                    ) {
                        $notificationType = $enumValues[0];
                    }
                }
            }

            $notificationTitle = "Job Request Cancelled";
            $actionUrl = rtrim(BASE_URL, "/") .
                "/user/print_request.php?id=" .
                (int) $requestId;

            $insertStmt = $conn->prepare(
                "INSERT INTO notifications (
                    user_id,
                    request_id,
                    title,
                    message,
                    notification_type,
                    action_url,
                    is_read,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())"
            );

            $insertStmt->bind_param(
                "iissss",
                $requestorId,
                $requestId,
                $notificationTitle,
                $notificationMessage,
                $notificationType,
                $actionUrl
            );

            $insertStmt->execute();
            $insertStmt->close();
        }
    } catch (Throwable $notificationException) {
        error_log(
            "Cancel request notification error: " .
            $notificationException->getMessage()
        );
    }

    $conn->commit();

    $_SESSION["request_message"] = [
        "type" => "success",
        "text" =>
            "Job request " .
            $jobNo .
            " was cancelled and the requestor was notified."
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
        "Cancel job request error: " .
        $exception->getMessage()
    );

    alertBack(
        "Unable to cancel the request: " .
        $exception->getMessage()
    );
}
