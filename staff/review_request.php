<?php

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
include "../includes/activity_log.php";
require_once "../includes/module_access.php";

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

$role = $_SESSION["role"] ?? "";

$canReview = in_array(
    $role,
    ["staff", "admin"],
    true
);

$canApprove = in_array(
    $role,
    ["supervisor", "admin"],
    true
);

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["csrf_token"];

/*
|--------------------------------------------------------------------------
| Load Request
|--------------------------------------------------------------------------
*/
$rawRequestId =
    $_GET["id"]
    ?? $_POST["request_id"]
    ?? null;

$requestId =
    filter_var(
        $rawRequestId,
        FILTER_VALIDATE_INT,
        [
            "options" => [
                "min_range" => 1
            ]
        ]
    );

if ($requestId === false || $requestId === null) {
    http_response_code(400);
    die("Invalid request ID.");
}

$requestId = (int) $requestId;

$stmt = $conn->prepare(
    "SELECT *
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
    die("Job request not found.");
}

$isCompleted = in_array(
    $request["approval_status"],
    ["Completed", "Closed"],
    true
);

$actorId = (int) ($_SESSION["user_id"] ?? 0);
$actorName = (string) ($_SESSION["name"] ?? "HPU Personnel");

/*
|--------------------------------------------------------------------------
| Feature Detection
|--------------------------------------------------------------------------
*/
$notificationsAvailable = false;

$notifTableCheck = $conn->query(
    "SHOW TABLES LIKE 'notifications'"
);

if ($notifTableCheck && $notifTableCheck->num_rows > 0) {
    $notificationsAvailable = true;
}

$activityItems = [];

$logTableCheck = $conn->query(
    "SHOW TABLES LIKE 'request_activity_logs'"
);

if ($logTableCheck && $logTableCheck->num_rows > 0) {
    $activityItems = getRequestActivities(
        $conn,
        (int) $requestId,
        150
    );
}

/*
|--------------------------------------------------------------------------
| IEC Fulfillment Data (read-only here until Phase 2 columns exist)
|--------------------------------------------------------------------------
*/
$hasIecMaterialId = false;
$hasIecFulfilledQty = false;
$iecFulfillItems = [];

$canFulfillIec = in_array(
    $role,
    ["staff", "supervisor", "admin"],
    true
);

try {
    $iecColStmt = $conn->prepare(
        "SELECT COLUMN_NAME
          FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'job_request_iec_items'
            AND COLUMN_NAME IN ('iec_material_id', 'fulfilled_qty')"
    );

    $iecColStmt->execute();

    $iecColsFound = array_column(
        $iecColStmt->get_result()->fetch_all(MYSQLI_ASSOC),
        "COLUMN_NAME"
    );

    $iecColStmt->close();

    $hasIecMaterialId =
        in_array(
            "iec_material_id",
            $iecColsFound,
            true
        );

    $hasIecFulfilledQty =
        in_array(
            "fulfilled_qty",
            $iecColsFound,
            true
        );
} catch (Throwable $ignored) {
    $hasIecMaterialId = false;
    $hasIecFulfilledQty = false;
}

try {
    $iecLibCheck = $conn->query(
        "SHOW TABLES LIKE 'iec_materials'"
    );

    $hasIecLib =
        $iecLibCheck
        &&
        $iecLibCheck->num_rows > 0;

    /*
     * Only join the IEC library when the actual link column exists.
     * This prevents the fulfillment section from disappearing on
     * databases that have not yet added iec_material_id.
     */
    $canJoinIecLibrary =
        $hasIecLib
        &&
        $hasIecMaterialId;

    $iecTitleJoin = $canJoinIecLibrary
        ? "LEFT JOIN iec_materials lib
               ON lib.id = item.iec_material_id"
        : "";

    $iecTitleSelect = $canJoinIecLibrary
        ? "lib.title AS library_title"
        : "NULL AS library_title";

    $iecFulfillSelect =
        ($hasIecFulfilledQty
            ? "item.fulfilled_qty"
            : "0 AS fulfilled_qty")
        .
        ", "
        .
        ($hasIecMaterialId
            ? "item.iec_material_id"
            : "NULL AS iec_material_id");

    $iecItemsStmt = $conn->prepare(
        "SELECT
            item.id,
            item.material_name,
            item.quantity,
            {$iecFulfillSelect},
            {$iecTitleSelect}
          FROM job_request_iec_items item
          {$iecTitleJoin}
          WHERE item.request_id = ?
          ORDER BY item.id ASC"
    );

    $iecItemsStmt->bind_param("i", $requestId);
    $iecItemsStmt->execute();

    $iecFulfillItems = $iecItemsStmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $iecItemsStmt->close();
} catch (Throwable $ignored) {
    $iecFulfillItems = [];
}

/* Role-aware: staff update fulfillment for their own assignments only. */
if (
    $canFulfillIec
    &&
    $role === "staff"
    &&
    array_key_exists("assigned_to", $request)
    &&
    $request["assigned_to"] !== null
    &&
    (int)$request["assigned_to"] !== $actorId
) {
    $canFulfillIec = false;
}

/*
|--------------------------------------------------------------------------
| HPU Personnel Options
|--------------------------------------------------------------------------
*/

/* Reviewed By dropdown - HPU personnel (staff / supervisor / admin). */
$reviewers = [];

$personnelStmt = $conn->prepare(
    "SELECT name
     FROM users
     WHERE role IN ('staff', 'supervisor', 'admin')
       AND status = 'Active'
     ORDER BY
        FIELD(role, 'supervisor', 'staff', 'admin'),
        name ASC"
);

if ($personnelStmt) {
    $personnelStmt->execute();

    $reviewers = array_column(
        $personnelStmt
            ->get_result()
            ->fetch_all(MYSQLI_ASSOC),
        "name"
    );

    $personnelStmt->close();
}

/* Approved By dropdown - every supervisor + HPU staff account. */
$approverGroups = [
    "HPU Supervisors" => [],
    "HPU Staff" => []
];

$approverStmt = $conn->prepare(
    "SELECT name, role
     FROM users
     WHERE role IN ('supervisor', 'staff')
       AND status = 'Active'
     ORDER BY
        FIELD(role, 'supervisor', 'staff'),
        name ASC"
);

if ($approverStmt) {
    $approverStmt->execute();

    foreach (
        $approverStmt
            ->get_result()
            ->fetch_all(MYSQLI_ASSOC)
        as $approver
    ) {
        $groupKey = $approver["role"] === "supervisor"
            ? "HPU Supervisors"
            : "HPU Staff";

        $approverGroups[$groupKey][] =
            (string) $approver["name"];
    }

    $approverStmt->close();
}

$approverNames = [];

foreach ($approverGroups as $groupMembers) {
    foreach ($groupMembers as $approverName) {
        $approverNames[] = $approverName;
    }
}

/*
|--------------------------------------------------------------------------
| Status Badge Class
|--------------------------------------------------------------------------
*/
$statusBadgeClass = match (
    (string) ($request["approval_status"] ?? "")
) {
    "Pending Review" => "bg-secondary",
    "Under Review" => "bg-primary",
    "Approved" => "bg-success",
    "In Development" => "bg-info text-dark",
    "For Revision" => "bg-warning text-dark",
    "Disapproved" => "bg-danger",
    "Completed" => "bg-success",
    "For Evaluation" => "bg-info text-dark",
    "Closed" => "bg-dark",
    default => "bg-secondary"
};

/*
|--------------------------------------------------------------------------
| Notification Builder (runs inside an active transaction)
|--------------------------------------------------------------------------
*/
$notifyRequestorFn = static function (
    string $title,
    string $type,
    string $url,
    string $message
) use ($conn, $requestId, $request) {
    $requestorId = (int) ($request["requestor"] ?? 0);

    if ($requestorId <= 0) {
        return;
    }

    $stmt = $conn->prepare(
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

    $stmt->bind_param(
        "iissss",
        $requestorId,
        $requestId,
        $title,
        $message,
        $type,
        $url
    );

    $stmt->execute();
    $stmt->close();
};

/*
|--------------------------------------------------------------------------
| Self-POST Action Handler
| Staff review and supervisor approval are confirmed through a modal and
| processed here inside a single transaction:
|    1. update the request status / actions
|    2. save the activity log
|    3. create a notification for the requestor (when checked)
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $saveType = postString("save_type");

    if (!hash_equals($csrfToken, postString("csrf_token"))) {
        alertBack("Security token mismatch. Please refresh the page and try again.");
    }

    $notifyRequestor = postBool("notify_requestor") === 1;
    $notificationMessage = postString("notification_message");

    /* -------------------------------------------------------
       STAFF REVIEW
    ------------------------------------------------------- */
    if ($saveType === "staff_review") {
        if (!$canReview) {
            alertBack("You are not allowed to review this request.");
        }

        if ($isCompleted) {
            alertBack("A completed request can no longer be reviewed.");
        }

        $reviewedBy = postString("reviewed_by");
        $reviewedDate = postString("reviewed_date");
        $reviewRemarks = postString("review_remarks");

        if ($reviewedBy === "") {
            alertBack("Please select the HPU personnel who reviewed the request.");
        }

        if (!validDate($reviewedDate)) {
            alertBack("Please enter a valid review date.");
        }

        $oldStatus = $request["approval_status"];
        $newStatus = $oldStatus === "Pending Review"
            ? "Under Review"
            : $oldStatus;

        $description = "The request was reviewed by {$reviewedBy}.";

        if ($reviewRemarks !== "") {
            $description .= " Remarks: {$reviewRemarks}";
        }

        $actionEntry =
            "\nReviewed by " .
            $reviewedBy .
            " on " .
            date("Y-m-d H:i:s") .
            ".";

        try {
            $conn->begin_transaction();

            $updateStmt = $conn->prepare(
                "UPDATE job_requests
                 SET reviewed_by = ?,
                     reviewed_date = ?,
                     approval_status = ?,
                     action_log = CONCAT(
                         COALESCE(action_log, ''),
                         ?
                     )
                 WHERE id = ?"
            );

            $updateStmt->bind_param(
                "ssssi",
                $reviewedBy,
                $reviewedDate,
                $newStatus,
                $actionEntry,
                $requestId
            );

            $updateStmt->execute();
            $updateStmt->close();

            addRequestActivity(
                $conn,
                $requestId,
                $actorId,
                "reviewed",
                "Staff Review Saved",
                $description,
                $oldStatus,
                $newStatus,
                [
                    "reviewed_by" => $reviewedBy,
                    "reviewed_date" => $reviewedDate,
                    "remarks" => $reviewRemarks
                ]
            );

            if ($notifyRequestor && $notificationsAvailable) {
                $jobNumber = (string) $request["job_no"];
                $jobTitle = (string) $request["project_title"];

                $message = $notificationMessage !== ""
                    ? $notificationMessage
                    : "Your job request {$jobNumber} ({$jobTitle}) is now under review by HPU. We will update you once a decision has been made.";

                $notifyRequestorFn(
                    "Request Update",
                    "status_update",
                    BASE_URL . "/user/print_request.php?id=" . $requestId,
                    $message
                );
            }

            $conn->commit();

            $_SESSION["request_message"] = [
                "type" => "success",
                "text" => "Staff review saved successfully."
            ];

            header(
                "Location: " .
                BASE_URL .
                "/staff/review_request.php?id=" .
                $requestId
            );

            exit;
        } catch (Throwable $exception) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }

            error_log(
                "Staff review error: " .
                $exception->getMessage()
            );

            alertBack(
                "Unable to save staff review: " .
                $exception->getMessage()
            );
        }
    }

    /* -------------------------------------------------------
       SUPERVISOR APPROVAL
    ------------------------------------------------------- */
    if ($saveType === "supervisor_approval") {
        if (!$canApprove) {
            alertBack("Only a supervisor or administrator can approve requests.");
        }

        if ($isCompleted) {
            alertBack("A completed request can no longer have its decision changed.");
        }

        if (empty($request["reviewed_by"])) {
            alertBack("The request must be reviewed before supervisor approval.");
        }

        $decision = postString("decision");
        $decisionReason = postString("decision_reason");
        $approvedBy = postString("approved_by");
        $approvedDate = postString("approved_date");

        $allowedDecisions = [
            "Approved",
            "For Revision",
            "Disapproved",
            "Completed"
        ];

        if (!in_array($decision, $allowedDecisions, true)) {
            alertBack("Please select a valid decision.");
        }

        if ($decision === "Approved") {
            if (!in_array($approvedBy, $approverNames, true)) {
                alertBack("Please select a valid supervisor or HPU staff member.");
            }

            if (!validDate($approvedDate)) {
                alertBack("Please enter a valid approval date.");
            }
        }

        if (
            in_array($decision, ["For Revision", "Disapproved"], true)
            && $decisionReason === ""
        ) {
            alertBack("Please provide a reason for the selected decision.");
        }

        $disapproved = $decision === "Disapproved" ? 1 : 0;

        if ($decision !== "Approved") {
            $approvedBy = null;
            $approvedDate = null;
        }

        $activityType = match ($decision) {
            "Approved" => "approved",
            "For Revision" => "revision",
            "Disapproved" => "disapproved",
            "Completed" => "completed",
            default => "updated"
        };

        $activityTitle = match ($decision) {
            "Approved" => "Request Approved",
            "For Revision" => "Revision Requested",
            "Disapproved" => "Request Disapproved",
            "Completed" => "Request Completed",
            default => "Request Status Updated"
        };

        $activityDescription = match ($decision) {
            "Approved" => "The job request was approved by " . $approvedBy . ".",
            "For Revision" => $decisionReason,
            "Disapproved" => $decisionReason,
            "Completed" => "The requested output was marked as completed.",
            default => "The request status was updated."
        };

        $now = date("Y-m-d H:i:s");

        $actionEntry = match ($decision) {
            "Approved" =>
                "\nApproved by {$approvedBy} on {$now}.",
            "For Revision" =>
                "\nSent back for revision by {$actorName} on {$now}. Reason: {$decisionReason}.",
            "Disapproved" =>
                "\nDisapproved by {$actorName} on {$now}. Reason: {$decisionReason}.",
            "Completed" =>
                "\nMarked as Completed by {$actorName} on {$now}.",
            default => ""
        };

        try {
            $conn->begin_transaction();

            $updateStmt = $conn->prepare(
                "UPDATE job_requests
                 SET approval_status = ?,
                     disapproved = ?,
                     disapproved_reason = ?,
                     approved_by = ?,
                     approved_date = ?,
                     action_log = CONCAT(
                         COALESCE(action_log, ''),
                         ?
                     )
                 WHERE id = ?"
            );

            $updateStmt->bind_param(
                "sissssi",
                $decision,
                $disapproved,
                $decisionReason,
                $approvedBy,
                $approvedDate,
                $actionEntry,
                $requestId
            );

            $updateStmt->execute();
            $updateStmt->close();

            addRequestActivity(
                $conn,
                $requestId,
                $actorId,
                $activityType,
                $activityTitle,
                $activityDescription,
                $oldStatus = $request["approval_status"],
                $decision,
                [
                    "approved_by" => $approvedBy,
                    "approved_date" => $approvedDate,
                    "reason" => $decisionReason
                ]
            );

            if ($notifyRequestor && $notificationsAvailable) {
                $jobNumber = (string) $request["job_no"];
                $jobTitle = (string) $request["project_title"];

                $notifTitle = match ($decision) {
                    "Approved" => "Job Request Approved",
                    "For Revision" => "Job Request Returned for Revision",
                    "Disapproved" => "Job Request Disapproved",
                    "Completed" => "Job Request Completed – Evaluation Invitation",
                    default => "Request Update"
                };

                $notifType = $decision === "Completed"
                    ? "evaluation"
                    : "status_update";

                $notifUrl = match ($decision) {
                    "For Revision" =>
                        BASE_URL . "/user/edit_request.php?id=" . $requestId,
                    "Completed" =>
                        BASE_URL . "/user/client_experience_survey.php?id=" . $requestId,
                    default =>
                        BASE_URL . "/user/print_request.php?id=" . $requestId
                };

                $defaultMessage = match ($decision) {
                    "Approved" =>
                        "Your job request {$jobNumber} ({$jobTitle}) has been approved. Approved by {$approvedBy}.",
                    "For Revision" =>
                        "Your job request {$jobNumber} ({$jobTitle}) was returned for revision. Reason: {$decisionReason}.",
                    "Disapproved" =>
                        "Your job request {$jobNumber} ({$jobTitle}) was disapproved. Reason: {$decisionReason}.",
                    "Completed" =>
                        "Your job request {$jobNumber} ({$jobTitle}) is now complete. Please take a moment to complete the Client Experience Evaluation so we can continue to improve our service.",
                    default =>
                        "Your job request {$jobNumber} ({$jobTitle}) has been updated."
                };

                $message = $notificationMessage !== ""
                    ? $notificationMessage
                    : $defaultMessage;

                $notifyRequestorFn(
                    $notifTitle,
                    $notifType,
                    $notifUrl,
                    $message
                );
            }

            $conn->commit();

            $_SESSION["request_message"] = [
                "type" => "success",
                "text" => "Supervisor decision saved successfully."
            ];

            header(
                "Location: " .
                BASE_URL .
                "/staff/review_request.php?id=" .
                $requestId
            );

            exit;
        } catch (Throwable $exception) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }

            error_log(
                "Supervisor approval error: " .
                $exception->getMessage()
            );

            alertBack(
                "Unable to save supervisor decision: " .
                $exception->getMessage()
            );
        }
    }

    /* -------------------------------------------------------
       IEC FULFILLMENT
    ------------------------------------------------------- */
    if ($saveType === "iec_fulfill") {
        if (!in_array($role, ["staff", "supervisor", "admin"], true)) {
            alertBack("You are not allowed to update fulfillment.");
        }

        if (
            $role === "staff"
            &&
            array_key_exists("assigned_to", $request)
            &&
            $request["assigned_to"] !== null
            &&
            (int)$request["assigned_to"] !== $actorId
        ) {
            alertBack("You can only update fulfillment for your assigned requests.");
        }

        /*
         * Fulfillment only requires fulfilled_qty.
         * Do not try to recreate iec_material_id here because some
         * installations already have that column/index independently.
         */
        try {
            $iecColCheck = $conn->prepare(
                "SELECT COUNT(*) AS total
                  FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'job_request_iec_items'
                    AND COLUMN_NAME = 'fulfilled_qty'"
            );

            $iecColCheck->execute();

            $iecColRow = $iecColCheck
                ->get_result()
                ->fetch_assoc();

            $iecColCheck->close();

            if ((int)($iecColRow["total"] ?? 0) === 0) {
                $conn->query(
                    "ALTER TABLE job_request_iec_items
                      ADD COLUMN fulfilled_qty
                         INT NOT NULL DEFAULT 0
                      AFTER quantity"
                );
            }
        } catch (Throwable $exception) {
            alertBack(
                "Fulfillment tracking is not installed: " .
                $exception->getMessage()
            );
        }

        $fulfillInput = $_POST["fulfill_qty"] ?? [];

        if (!is_array($fulfillInput)) {
            $fulfillInput = [];
        }

        $itemsStmt = $conn->prepare(
            "SELECT id, material_name, quantity, fulfilled_qty
              FROM job_request_iec_items
              WHERE request_id = ?"
        );

        $itemsStmt->bind_param("i", $requestId);
        $itemsStmt->execute();

        $targetItems = $itemsStmt
            ->get_result()
            ->fetch_all(MYSQLI_ASSOC);

        $itemsStmt->close();

        if (!$targetItems) {
            alertBack("This request has no IEC line items.");
        }

        $updates = [];

        foreach ($targetItems as $targetItem) {
            $itemId = (int)$targetItem["id"];
            $maxQty = max(0, (int)$targetItem["quantity"]);

            if (!array_key_exists((string)$itemId, $fulfillInput)) {
                continue;
            }

            $rawQty =
                trim(
                    (string)$fulfillInput[(string)$itemId]
                );

            $newQty =
                filter_var(
                    $rawQty,
                    FILTER_VALIDATE_INT,
                    [
                        "options" => [
                            "min_range" => 0,
                            "max_range" => $maxQty
                        ]
                    ]
                );

            if ($newQty === false) {
                alertBack(
                    "Fulfilled quantity for \"" .
                    $targetItem["material_name"] .
                    "\" must be a whole number between 0 and " .
                    $maxQty .
                    "."
                );
            }

            $newQty = (int)$newQty;

            if ($newQty !== (int)$targetItem["fulfilled_qty"]) {
                $updates[$itemId] = $newQty;
            }
        }

        if (!$updates) {
            $_SESSION["request_message"] = [
                "type" => "info",
                "text" => "No fulfillment changes to save."
            ];

            header(
                "Location: " .
                BASE_URL .
                "/staff/review_request.php?id=" .
                $requestId
            );

            exit;
        }

        try {
            $conn->begin_transaction();

            $fulfillStmt = $conn->prepare(
                "UPDATE job_request_iec_items
                  SET fulfilled_qty = ?
                  WHERE id = ?
                    AND request_id = ?"
            );

            foreach ($updates as $itemId => $newQty) {
                $fulfillStmt->bind_param(
                    "iii",
                    $newQty,
                    $itemId,
                    $requestId
                );

                $fulfillStmt->execute();
            }

            $fulfillStmt->close();

            $oldStatus = $request["approval_status"];

            $actionEntry =
                "\nIEC fulfillment updated by " .
                $actorName .
                " on " .
                date("Y-m-d H:i:s") .
                ".";

            $logStmt = $conn->prepare(
                "UPDATE job_requests
                  SET action_log = CONCAT(
                      COALESCE(action_log, ''),
                      ?
                  )
                  WHERE id = ?"
            );

            $logStmt->bind_param("si", $actionEntry, $requestId);
            $logStmt->execute();
            $logStmt->close();

            $description =
                "IEC fulfillment updated by {$actorName}: " .
                count($updates) .
                " line item(s) changed.";

            if ($logTableCheck && $logTableCheck->num_rows > 0) {
                try {
                    addRequestActivity(
                        $conn,
                        $requestId,
                        $actorId,
                        "development",
                        "IEC Fulfillment Updated",
                        $description,
                        $oldStatus,
                        $oldStatus,
                        ["updated_lines" => count($updates)]
                    );
                } catch (Throwable $activityException) {
                    error_log(
                        "IEC fulfillment activity-log error: " .
                        $activityException->getMessage()
                    );
                }
            }

            /* Notify when every line is fully fulfilled. */
            $remainingStmt = $conn->prepare(
                "SELECT COUNT(*) AS open_lines
                  FROM job_request_iec_items
                  WHERE request_id = ?
                    AND fulfilled_qty < quantity"
            );

            $remainingStmt->bind_param("i", $requestId);
            $remainingStmt->execute();

            $remainingRow = $remainingStmt
                ->get_result()
                ->fetch_assoc();

            $remainingStmt->close();

            if (
                (int)($remainingRow["open_lines"] ?? 1) === 0
                &&
                $notificationsAvailable
            ) {
                $jobNumber = (string) $request["job_no"];
                $jobTitle = (string) $request["project_title"];

                try {
                    $notifyRequestorFn(
                        "IEC Materials Ready",
                        "status_update",
                        BASE_URL . "/user/print_request.php?id=" . $requestId,
                        "Your IEC materials for job request {$jobNumber} ({$jobTitle}) are now fully fulfilled."
                    );
                } catch (Throwable $notificationException) {
                    error_log(
                        "IEC fulfillment notification error: " .
                        $notificationException->getMessage()
                    );
                }
            }

            $conn->commit();

            $_SESSION["request_message"] = [
                "type" => "success",
                "text" => "IEC fulfillment saved successfully."
            ];

            header(
                "Location: " .
                BASE_URL .
                "/staff/review_request.php?id=" .
                $requestId
            );

            exit;
        } catch (Throwable $exception) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }

            error_log(
                "IEC fulfillment error: " .
                $exception->getMessage()
            );

            alertBack(
                "Unable to save fulfillment: " .
                $exception->getMessage()
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| Session Message
|--------------------------------------------------------------------------
*/
$pageMessage = $_SESSION["request_message"] ?? null;
unset($_SESSION["request_message"]);

/*
|--------------------------------------------------------------------------
| Conversation Preview (readable + deep link to Communication Center)
|--------------------------------------------------------------------------
| Shows the latest readable messages for this job request so staff can
| scan the thread here and jump straight into the full conversation
| at admin/view.php?id=X#conversation.
*/
$conversationMessages = [];
$conversationUnread = 0;
$conversationTotal = 0;
$conversationLastId = 0;

try {
    $convTableCheck = $conn->query("SHOW TABLES LIKE 'request_messages'");

    if ($convTableCheck && $convTableCheck->num_rows > 0) {
        $convTotalStmt = $conn->prepare(
            "SELECT COUNT(*) AS total, MAX(id) AS max_id
             FROM request_messages
             WHERE request_id = ?"
        );

        if ($convTotalStmt) {
            $convTotalStmt->bind_param("i", $requestId);
            $convTotalStmt->execute();
            $convTotalRow = $convTotalStmt->get_result()->fetch_assoc();
            $convTotalStmt->close();
            $conversationTotal = (int) ($convTotalRow["total"] ?? 0);
            $conversationLastId = (int) ($convTotalRow["max_id"] ?? 0);
        }

        $convReadStmt = null;

        try {
            $convReadStmt = $conn->prepare(
                "SELECT last_read_message_id
                 FROM request_message_reads
                 WHERE request_id = ? AND user_id = ?
                 LIMIT 1"
            );

            if ($convReadStmt) {
                $convReadStmt->bind_param("ii", $requestId, $actorId);
                $convReadStmt->execute();
                $convReadRow = $convReadStmt->get_result()->fetch_assoc();
                $convReadStmt->close();
                $lastReadId = (int) ($convReadRow["last_read_message_id"] ?? 0);

                $convUnreadStmt = $conn->prepare(
                    "SELECT COUNT(*) AS unread
                     FROM request_messages
                     WHERE request_id = ?
                       AND id > ?
                       AND sender_id <> ?"
                );

                if ($convUnreadStmt) {
                    $convUnreadStmt->bind_param("iii", $requestId, $lastReadId, $actorId);
                    $convUnreadStmt->execute();
                    $convUnreadRow = $convUnreadStmt->get_result()->fetch_assoc();
                    $convUnreadStmt->close();
                    $conversationUnread = (int) ($convUnreadRow["unread"] ?? 0);
                }
            }
        } catch (Throwable $ignored) {
        }

        // Latest 5 readable messages (staff sees internal notes too).
        $convListStmt = $conn->prepare(
            "SELECT id, sender_id, sender_name, sender_role, message,
                    attachment, attachment_original_name, is_internal, created_at
             FROM request_messages
             WHERE request_id = ?
             ORDER BY id DESC
             LIMIT 5"
        );

        if ($convListStmt) {
            $convListStmt->bind_param("i", $requestId);
            $convListStmt->execute();
            $conversationMessages = array_reverse(
                $convListStmt->get_result()->fetch_all(MYSQLI_ASSOC)
            );
            $convListStmt->close();
        }
    }
} catch (Throwable $ignored) {
    $conversationMessages = [];
}

function conversationTimeAgo(?string $value): string
{
    if (!$value) {
        return "—";
    }

    $time = strtotime($value);

    if ($time === false) {
        return $value;
    }

    $diff = time() - $time;

    if ($diff < 60) {
        return "just now";
    }

    if ($diff < 3600) {
        $mins = (int) floor($diff / 60);
        return $mins . " min ago";
    }

    if ($diff < 86400) {
        $hours = (int) floor($diff / 3600);
        return $hours . " hr ago";
    }

    if ($diff < 86400 * 7) {
        $days = (int) floor($diff / 86400);
        return $days . " day" . ($days === 1 ? "" : "s") . " ago";
    }

    return date("M d, Y g:i A", $time);
}

$conversationUrl = rtrim(BASE_URL, "/") . "/admin/view.php?id=" . (int) $requestId . "#conversation";
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Review <?= e($request["job_no"]) ?>
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="<?= e(BASE_URL) ?>/assets/css/style.css"
    >

    <link
        rel="stylesheet"
        href="<?= e(BASE_URL) ?>/assets/css/page_headers.css"
    >

    <style>
        .section-heading {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .status-information {
            padding: 14px;
            border: 1px solid #dbe3ef;
            border-radius: 10px;
            background: #f8fafc;
        }

        .completed-notice {
            border-left: 4px solid #0d6efd;
        }

        /* --- Header reference row --- */
        .review-ref {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .job-no-ref {
            display: inline-flex;
            align-items: center;
            padding: 3px 10px;
            border: 1px solid #cfe0f8;
            border-radius: 999px;
            background: #eef4ff;
            color: #2563eb;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: .03em;
        }

        .ref-divider {
            width: 1px;
            height: 16px;
            background: #dbe3ef;
        }

        .ref-meta {
            color: #64748b;
            font-size: .76rem;
        }

        /* --- Compact scrollable activity log --- */
        .log-scroll {
            max-height: 450px;
            overflow-y: auto;
            padding-right: 6px;
        }

        .log-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 8px;
        }

        .log-item {
            padding: 10px 12px;
            border: 1px solid #e8edf4;
            border-radius: 10px;
            background: #fff;
        }

        .log-head {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .log-dot {
            width: 8px;
            height: 8px;
            flex: 0 0 auto;
            border-radius: 50%;
        }

        .log-dot-blue   { background: #2563eb; }
        .log-dot-green  { background: #16a34a; }
        .log-dot-cyan   { background: #0891b2; }
        .log-dot-yellow { background: #d97706; }
        .log-dot-red    { background: #dc2626; }
        .log-dot-orange { background: #ea580c; }
        .log-dot-slate  { background: #94a3b8; }

        .log-action {
            font-weight: 800;
            font-size: .82rem;
            color: #0f172a;
        }

        .log-actor {
            font-size: .72rem;
            color: #64748b;
        }

        .log-date {
            margin-left: auto;
            font-size: .68rem;
            color: #94a3b8;
            white-space: nowrap;
        }

        .log-status {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 6px;
        }

        .log-status-pill {
            padding: 2px 7px;
            border-radius: 999px;
            background: #f1f5f9;
            color: #334155;
            font-size: .66rem;
            font-weight: 700;
        }

        .log-status-pill.log-status-new {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .log-remarks {
            margin: 6px 0 0;
            color: #475569;
            font-size: .74rem;
            line-height: 1.45;
            white-space: pre-line;
        }

        .log-empty {
            padding: 34px 16px;
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
            color: #64748b;
            text-align: center;
        }

        /* --- Conversation preview (readable + deep link) --- */
        .conv-card {
            overflow: hidden;
        }

        .conv-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .conv-title {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .conv-icon {
            display: grid;
            width: 40px;
            height: 40px;
            flex: 0 0 40px;
            place-items: center;
            border-radius: 11px;
            background: #eef4ff;
            color: #2563eb;
            font-size: 19px;
        }

        .conv-unread-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 999px;
            background: #fee4e2;
            color: #b42318;
            font-size: .7rem;
            font-weight: 850;
            animation: convPulse 1.6s ease-in-out infinite;
        }

        @keyframes convPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(180, 35, 24, .25); }
            50% { box-shadow: 0 0 0 4px rgba(180, 35, 24, 0); }
        }

        .conv-list {
            display: grid;
            gap: 8px;
            margin: 14px 0 0;
            padding: 0;
            list-style: none;
        }

        .conv-item {
            display: grid;
            grid-template-columns: 34px minmax(0, 1fr);
            gap: 10px;
            padding: 10px 12px;
            border: 1px solid #e8edf4;
            border-radius: 11px;
            background: #fff;
            text-decoration: none;
            color: inherit;
            transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
        }

        .conv-item:hover {
            border-color: #93c5fd;
            box-shadow: 0 6px 18px rgba(37, 99, 235, .10);
            transform: translateY(-1px);
        }

        .conv-avatar {
            display: grid;
            width: 34px;
            height: 34px;
            place-items: center;
            border-radius: 50%;
            background: #eef4ff;
            color: #2563eb;
            font-size: .8rem;
            font-weight: 900;
        }

        .conv-meta {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            font-size: .72rem;
        }

        .conv-meta strong {
            color: #0f172a;
            font-size: .78rem;
        }

        .conv-role {
            padding: 1px 7px;
            border-radius: 999px;
            background: #f1f5f9;
            color: #475569;
            font-size: .62rem;
            font-weight: 800;
            text-transform: capitalize;
        }

        .conv-internal {
            padding: 1px 7px;
            border-radius: 999px;
            background: #fef3c7;
            color: #92400e;
            font-size: .62rem;
            font-weight: 800;
        }

        .conv-time {
            margin-left: auto;
            color: #94a3b8;
            font-size: .66rem;
            white-space: nowrap;
        }

        .conv-text {
            margin: 4px 0 0;
            color: #334155;
            font-size: .78rem;
            line-height: 1.5;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .conv-attach {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-top: 6px;
            color: #2563eb;
            font-size: .7rem;
            font-weight: 700;
        }

        .conv-empty {
            margin-top: 12px;
            padding: 26px 14px;
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
            color: #64748b;
            font-size: .8rem;
            text-align: center;
        }

        .conv-foot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #eef2f7;
            color: #64748b;
            font-size: .72rem;
        }

        /* --- Confirmation modal --- */
        .approval-modal-icon {
            display: grid;
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            place-items: center;
            border-radius: 11px;
            background: #eef4ff;
            color: #2563eb;
            font-size: 19px;
        }

        .modal-status-strip {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            padding: 10px 12px;
            border: 1px dashed #cfe0f8;
            border-radius: 10px;
            background: #f8fbff;
            font-size: .8rem;
        }

        .required-mark {
            color: #dc2626;
            font-weight: 800;
        }

        /* Black-backdrop glitch fix: the global .content entrance animation
           ends in transform: translateY(0), which traps Bootstrap's
           position:fixed modals/backdrop inside it so the dark backdrop
           renders above the popup and blocks all clicks. Neutralize it here
           (modals stay inside their forms so their fields keep submitting). */
        .content {
            animation: none !important;
            transform: none !important;
        }
    </style>
</head>

<body class="app-shell">

<div class="d-flex min-vh-100">

    <?php include "../includes/sidebar.php"; ?>

    <div class="main flex-grow-1">

        <main class="content">

            <div class="container-fluid">

                <!-- =========================================
                     PAGE HEADER (.ph)
                ========================================== -->
                <header class="ph">
                    <div class="ph-copy">
                        <div class="ph-icon" aria-hidden="true">
                            <i class="bi bi-clipboard2-check"></i>
                        </div>

                        <div class="min-w-0">
                            <p class="ph-eyebrow">HPU Workbench · Request Review</p>
                            <h1 class="ph-title"><?= e($request["project_title"]) ?></h1>

                            <p class="ph-subtitle review-ref">
                                <span class="job-no-ref" title="Job reference number">
                                    <?= e($request["job_no"]) ?>
                                </span>

                                <span class="ref-divider" aria-hidden="true"></span>

                                <span class="badge <?= e($statusBadgeClass) ?>">
                                    <?= e($request["approval_status"]) ?>
                                </span>

                                <span class="ref-meta">
                                    <?= e($request["requestor_name"]) ?>
                                    · <?= e($request["department"]) ?>
                                </span>
                            </p>
                        </div>
                    </div>

                    <div class="ph-actions no-print">
                        <a
                            class="btn btn-outline-primary"
                            href="<?= e(BASE_URL) ?>/staff/requests.php"
                            title="Back to requests"
                        >
                            <i class="bi bi-arrow-left"></i>
                            Back
                        </a>
                    </div>
                </header>

                <?php if ($pageMessage): ?>
                    <div
                        class="alert alert-<?= e($pageMessage["type"] ?? "success") ?> alert-dismissible fade show"
                        role="alert"
                    >
                        <i class="bi bi-info-circle me-1"></i>
                        <?= e($pageMessage["text"] ?? "") ?>

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="alert"
                            aria-label="Close"
                        ></button>
                    </div>
                <?php endif; ?>

                <?php if ($isCompleted): ?>
                    <div class="alert alert-primary completed-notice">
                        This request is already
                        <strong><?= e($request["approval_status"]) ?></strong>.

                        The review or approval decision can no longer
                        be changed. Section III remains editable.
                    </div>
                <?php endif; ?>

                <!-- =========================================
                     ACTIVITY LOG TIMELINE (compact / scrollable)
                ========================================== -->
                <section class="card mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                            <div>
                                <h5 class="mb-1">
                                    <i class="bi bi-clock-history me-2"></i>
                                    Activity Log Timeline
                                </h5>
                                <p class="text-muted mb-0 small">
                                    Complete history of this job request.
                                </p>
                            </div>

                            <span class="badge bg-light text-dark border">
                                <?= count($activityItems) ?> event(s)
                            </span>
                        </div>

                        <?php if (!$activityItems): ?>

                            <div class="log-empty">
                                <i class="bi bi-clock-history fs-2 d-block mb-2"></i>
                                No activity has been recorded yet.
                            </div>

                        <?php else: ?>

                            <div class="log-scroll">
                                <ul class="log-list">
                                    <?php foreach ($activityItems as $activity): ?>
                                        <?php
                                        $dotClass = match (
                                            (string) $activity["action_type"]
                                        ) {
                                            "created", "completed" => "log-dot-blue",
                                            "reviewed", "restored" => "log-dot-cyan",
                                            "approved", "evaluation_submitted" => "log-dot-green",
                                            "revision" => "log-dot-yellow",
                                            "disapproved", "deleted" => "log-dot-red",
                                            "evaluation_requested" => "log-dot-orange",
                                            default => "log-dot-slate"
                                        };
                                        ?>

                                        <li class="log-item">
                                            <div class="log-head">
                                                <span class="log-dot <?= e($dotClass) ?>" aria-hidden="true"></span>
                                                <span class="log-action"><?= e($activity["action_title"]) ?></span>
                                                <span class="log-actor">
                                                    <i class="bi bi-person"></i>
                                                    <?= e($activity["actor_name"]) ?>
                                                    (<?= e($activity["actor_role"]) ?>)
                                                </span>
                                                <span class="log-date">
                                                    <i class="bi bi-calendar3"></i>
                                                    <?= e(activityDateLabel($activity["created_at"])) ?>
                                                </span>
                                            </div>

                                            <?php if (
                                                !empty($activity["old_status"]) ||
                                                !empty($activity["new_status"])
                                            ): ?>
                                                <div class="log-status">
                                                    <?php if (!empty($activity["old_status"])): ?>
                                                        <span class="log-status-pill">
                                                            <?= e($activity["old_status"]) ?>
                                                        </span>
                                                    <?php endif; ?>

                                                    <i class="bi bi-arrow-right small"></i>

                                                    <?php if (!empty($activity["new_status"])): ?>
                                                        <span class="log-status-pill log-status-new">
                                                            <?= e($activity["new_status"]) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($activity["description"])): ?>
                                                <p class="log-remarks"><?= e($activity["description"]) ?></p>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>

                        <?php endif; ?>
                    </div>
                </section>

                <!-- =========================================
                     CONVERSATION PREVIEW (readable + deep link)
                ========================================== -->
                <section class="card mb-4 conv-card" id="conversation-preview">
                    <div class="card-body">
                        <div class="conv-head">
                            <div class="conv-title">
                                <span class="conv-icon" aria-hidden="true">
                                    <i class="bi bi-chat-dots-fill"></i>
                                </span>

                                <div class="min-w-0">
                                    <h5 class="mb-0">
                                        Conversation
                                        <span class="badge bg-light text-dark border ms-1">
                                            <?= (int) $conversationTotal ?> message<?= (int) $conversationTotal === 1 ? "" : "s" ?>
                                        </span>
                                    </h5>

                                    <small class="text-muted">
                                        Latest readable messages for <?= e($request["job_no"] ?? "") ?> — click any message to open the full thread.
                                    </small>
                                </div>
                            </div>

                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <?php if ($conversationUnread > 0): ?>
                                    <span class="conv-unread-pill">
                                        <i class="bi bi-circle-fill" style="font-size:.45rem;"></i>
                                        <?= (int) $conversationUnread ?> new
                                    </span>
                                <?php endif; ?>

                                <a
                                    class="btn btn-sm btn-primary"
                                    href="<?= e($conversationUrl) ?>"
                                    title="Open the full Communication Center for this job request"
                                >
                                    <i class="bi bi-box-arrow-up-right me-1"></i>
                                    Open conversation
                                </a>
                            </div>
                        </div>

                        <?php if (!$conversationMessages): ?>
                            <div class="conv-empty">
                                <i class="bi bi-chat-square-heart fs-4 d-block mb-2"></i>
                                No messages yet for this request.
                                <a href="<?= e($conversationUrl) ?>">Start the conversation</a>.
                            </div>
                        <?php else: ?>
                            <ul class="conv-list">
                                <?php foreach ($conversationMessages as $convMsg): ?>
                                    <?php
                                    $convSender = trim((string) ($convMsg["sender_name"] ?? "Unknown"));
                                    $convInitial = $convSender !== "" ? mb_strtoupper(mb_substr($convSender, 0, 1)) : "?";
                                    $convBody = trim((string) ($convMsg["message"] ?? ""));
                                    $convAttach = trim((string) ($convMsg["attachment_original_name"] ?? $convMsg["attachment"] ?? ""));
                                    $convIsInternal = (int) ($convMsg["is_internal"] ?? 0) === 1;
                                    $convIsUnread = $conversationLastId > 0 && (int) $convMsg["id"] > 0 && (int) $convMsg["sender_id"] !== $actorId;
                                    ?>
                                    <li>
                                        <a class="conv-item" href="<?= e($conversationUrl) ?>" title="Open this thread in the Communication Center">
                                            <span class="conv-avatar" aria-hidden="true"><?= e($convInitial) ?></span>

                                            <span class="min-w-0">
                                                <span class="conv-meta">
                                                    <strong><?= e($convSender !== "" ? $convSender : "Unknown") ?></strong>
                                                    <span class="conv-role"><?= e((string) ($convMsg["sender_role"] ?? "")) ?></span>

                                                    <?php if ($convIsInternal): ?>
                                                        <span class="conv-internal">
                                                            <i class="bi bi-shield-lock"></i> Internal
                                                        </span>
                                                    <?php endif; ?>

                                                    <span class="conv-time">
                                                        <i class="bi bi-clock"></i>
                                                        <?= e(conversationTimeAgo($convMsg["created_at"] ?? null)) ?>
                                                    </span>
                                                </span>

                                                <?php if ($convBody !== ""): ?>
                                                    <span class="conv-text"><?= e($convBody) ?></span>
                                                <?php endif; ?>

                                                <?php if ($convAttach !== ""): ?>
                                                    <span class="conv-attach">
                                                        <i class="bi bi-paperclip"></i>
                                                        <?= e(mb_strimwidth($convAttach, 0, 48, "…")) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>

                            <div class="conv-foot">
                                <span>
                                    <i class="bi bi-hash"></i>
                                    <?= e($request["job_no"] ?? "") ?>
                                    · <?= e($request["project_title"] ?? "") ?>
                                </span>

                                <a href="<?= e($conversationUrl) ?>">
                                    View all <?= (int) $conversationTotal ?> messages
                                    <i class="bi bi-arrow-right"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- =========================================
                     STAFF REVIEW
                ========================================== -->
                <?php if ($canReview): ?>

                    <form
                        method="POST"
                        action="<?= e(BASE_URL) ?>/staff/review_request.php?id=<?= (int) $requestId ?>"
                        class="mb-4"
                        id="staffReviewForm"
                    >
                        <input type="hidden" name="request_id" value="<?= (int) $requestId ?>">
                        <input type="hidden" name="save_type" value="staff_review">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                        <section class="card">
                            <div class="card-body">
                                <div class="section-heading">
                                    <i class="bi bi-clipboard-check fs-4 text-primary"></i>

                                    <div>
                                        <h3 class="mb-0">Staff Review</h3>
                                        <small class="text-muted">
                                            To be completed by HPU personnel.
                                        </small>
                                    </div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Reviewed By</label>

                                        <select
                                            class="form-select"
                                            name="reviewed_by"
                                            <?= $isCompleted ? "disabled" : "" ?>
                                            required
                                        >
                                            <option value="">Select HPU Personnel</option>

                                            <?php foreach ($reviewers as $reviewer): ?>
                                                <option
                                                    value="<?= e($reviewer) ?>"
                                                    <?= ($request["reviewed_by"] ?? "") === $reviewer ? "selected" : "" ?>
                                                >
                                                    <?= e($reviewer) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Review Date</label>

                                        <input
                                            type="date"
                                            class="form-control"
                                            name="reviewed_date"
                                            value="<?= e($request["reviewed_date"] ?: date("Y-m-d")) ?>"
                                            <?= $isCompleted ? "disabled" : "" ?>
                                            required
                                        >
                                    </div>
                                </div>

                                <?php if (!$isCompleted): ?>
                                    <div class="d-flex justify-content-end mt-4">
                                        <button class="btn btn-primary" type="submit">
                                            <i class="bi bi-clipboard-check me-1"></i>
                                            Review Request
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>

                        <!-- Staff review confirmation modal -->
                        <div
                            class="modal fade"
                            id="staffReviewModal"
                            data-bs-backdrop="static"
                            data-bs-keyboard="false"
                            tabindex="-1"
                            aria-labelledby="staffReviewModalTitle"
                            aria-hidden="true"
                        >
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="approval-modal-icon">
                                                <i class="bi bi-clipboard-check"></i>
                                            </span>

                                            <div>
                                                <h5 class="modal-title" id="staffReviewModalTitle">
                                                    Confirm Staff Review
                                                </h5>
                                                <small class="text-muted">
                                                    This will mark the request as Under Review by HPU.
                                                </small>
                                            </div>
                                        </div>

                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>

                                    <div class="modal-body">
                                        <div class="modal-status-strip mb-3">
                                            <span>Action:</span>
                                            <strong>Staff Review</strong>
                                            <i class="bi bi-arrow-right small"></i>
                                            <span class="badge bg-primary">Under Review</span>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label" for="staffReviewRemarks">
                                                Remarks <span class="text-muted">(Optional)</span>
                                            </label>
                                            <textarea
                                                class="form-control"
                                                id="staffReviewRemarks"
                                                name="review_remarks"
                                                rows="3"
                                                placeholder="Optional remarks about the review"
                                            ></textarea>
                                        </div>

                                        <div class="form-check form-switch mb-2">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                id="staffNotifyRequestor"
                                                name="notify_requestor"
                                                value="1"
                                                checked
                                            >
                                            <label class="form-check-label" for="staffNotifyRequestor">
                                                Notify Requestor
                                            </label>
                                        </div>

                                        <div class="mb-2">
                                            <label class="form-label" for="staffNotificationMessage">
                                                Notification Message
                                            </label>
                                            <textarea
                                                class="form-control"
                                                id="staffNotificationMessage"
                                                name="notification_message"
                                                rows="3"
                                            ></textarea>
                                        </div>
                                    </div>

                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                                            Cancel
                                        </button>
                                        <button type="button" class="btn btn-primary" id="staffReviewConfirmBtn">
                                            <i class="bi bi-check-circle me-1"></i>
                                            Confirm Review
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>

                <?php endif; ?>

                <!-- =========================================
                     SUPERVISOR APPROVAL
                ========================================== -->
                <?php if ($canApprove): ?>

                    <form
                        method="POST"
                        action="<?= e(BASE_URL) ?>/staff/review_request.php?id=<?= (int) $requestId ?>"
                        class="mb-4"
                        id="approvalForm"
                    >
                        <input type="hidden" name="request_id" value="<?= (int) $requestId ?>">
                        <input type="hidden" name="save_type" value="supervisor_approval">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                        <section class="card">
                            <div class="card-body">
                                <div class="section-heading">
                                    <i class="bi bi-patch-check fs-4 text-success"></i>

                                    <div>
                                        <h3 class="mb-0">Supervisor Approval</h3>
                                        <small class="text-muted">
                                            Only the supervisor or administrator can approve the request.
                                        </small>
                                    </div>
                                </div>

                                <?php if (empty($request["reviewed_by"])): ?>
                                    <div class="alert alert-warning">
                                        This request has not yet been reviewed by HPU staff.
                                    </div>
                                <?php endif; ?>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Decision</label>

                                        <select
                                            id="decision"
                                            class="form-select"
                                            name="decision"
                                            <?= $isCompleted ? "disabled" : "" ?>
                                            required
                                        >
                                            <option value="">Select Decision</option>

                                            <option value="Approved" <?= $request["approval_status"] === "Approved" ? "selected" : "" ?>>
                                                Approved
                                            </option>

                                            <option value="For Revision" <?= $request["approval_status"] === "For Revision" ? "selected" : "" ?>>
                                                For Revision
                                            </option>

                                            <option value="Disapproved" <?= $request["approval_status"] === "Disapproved" ? "selected" : "" ?>>
                                                Disapproved
                                            </option>

                                            <option value="Completed" <?= $request["approval_status"] === "Completed" ? "selected" : "" ?>>
                                                Completed
                                            </option>
                                        </select>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Approval Date</label>

                                        <input
                                            type="date"
                                            class="form-control"
                                            name="approved_date"
                                            value="<?= e($request["approved_date"] ?: date("Y-m-d")) ?>"
                                            <?= $isCompleted ? "disabled" : "" ?>
                                        >
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">
                                            Approved By
                                            <span class="text-muted">(Supervisor / HPU Staff)</span>
                                        </label>

                                        <select
                                            class="form-select"
                                            id="approvedBy"
                                            name="approved_by"
                                            <?= $isCompleted ? "disabled" : "" ?>
                                        >
                                            <option value="">Select Approver</option>

                                            <?php foreach ($approverGroups as $groupLabel => $groupMembers): ?>
                                                <?php if ($groupMembers): ?>
                                                    <optgroup label="<?= e($groupLabel) ?>">
                                                        <?php foreach ($groupMembers as $approverName): ?>
                                                            <option
                                                                value="<?= e($approverName) ?>"
                                                                <?= ($request["approved_by"] ?? "") === $approverName ? "selected" : "" ?>
                                                            >
                                                                <?= e($approverName) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </optgroup>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>

                                        <div class="form-text">
                                            The approving officer will be recorded when the decision is set to Approved.
                                        </div>
                                    </div>
                                </div>

                                <?php if (!$isCompleted): ?>
                                    <div class="d-flex justify-content-end mt-4">
                                        <button
                                            class="btn btn-success"
                                            type="submit"
                                            <?= empty($request["reviewed_by"]) ? "disabled" : "" ?>
                                        >
                                            <i class="bi bi-check-circle me-1"></i>
                                            Save Decision
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>

                        <!-- Supervisor decision confirmation modal -->
                        <div
                            class="modal fade"
                            id="approvalModal"
                            data-bs-backdrop="static"
                            data-bs-keyboard="false"
                            tabindex="-1"
                            aria-labelledby="approvalModalTitle"
                            aria-hidden="true"
                        >
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="approval-modal-icon" id="approvalModalIcon">
                                                <i class="bi bi-check2-circle"></i>
                                            </span>

                                            <div>
                                                <h5 class="modal-title" id="approvalModalTitle">Confirm Decision</h5>
                                                <small class="text-muted" id="approvalModalSub"></small>
                                            </div>
                                        </div>

                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>

                                    <div class="modal-body">
                                        <div class="modal-status-strip mb-3">
                                            <span>Action:</span>
                                            <strong id="modalActionLabel">—</strong>
                                            <span>will set status to</span>
                                            <span class="badge bg-success" id="modalStatusPill">—</span>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label" for="modalDecisionReason">
                                                Remarks / Reason
                                                <span class="required-mark" id="modalReasonRequiredMarker" style="display:none">*</span>
                                            </label>
                                            <textarea
                                                class="form-control"
                                                id="modalDecisionReason"
                                                name="decision_reason"
                                                rows="3"
                                                placeholder="Required for For Revision and Disapproved decisions."
                                            ></textarea>
                                        </div>

                                        <div class="form-check form-switch mb-2">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                id="modalNotifyRequestor"
                                                name="notify_requestor"
                                                value="1"
                                                checked
                                            >
                                            <label class="form-check-label" for="modalNotifyRequestor">
                                                Notify Requestor
                                            </label>
                                        </div>

                                        <div class="mb-2">
                                            <label class="form-label" for="modalNotificationMessage">
                                                Notification Message
                                                <span class="text-muted">(Editable)</span>
                                            </label>
                                            <textarea
                                                class="form-control"
                                                id="modalNotificationMessage"
                                                name="notification_message"
                                                rows="3"
                                            ></textarea>
                                        </div>
                                    </div>

                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                                            Cancel
                                        </button>
                                        <button type="button" class="btn btn-success" id="approvalConfirmBtn">
                                            <i class="bi bi-check-circle me-1"></i>
                                            Confirm &amp; Save
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>

                <?php endif; ?>

                <?php if ($iecFulfillItems): ?>

                <!-- =========================================
                     IEC MATERIALS FULFILLMENT
                ========================================== -->
                <form
                    method="POST"
                    action="<?= e(BASE_URL) ?>/staff/review_request.php?id=<?= (int)$requestId ?>"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrfToken) ?>"
                    >

                    <input
                        type="hidden"
                        name="request_id"
                        value="<?= (int)$requestId ?>"
                    >

                    <input
                        type="hidden"
                        name="save_type"
                        value="iec_fulfill"
                    >

                    <section class="card mb-4">
                        <div class="section-banner">
                            IEC Materials Fulfillment
                            <small>(quantities issued vs requested)</small>
                        </div>

                        <div class="card-body">

                            <?php if ($canFulfillIec): ?>
                                <div class="alert alert-info py-2 small">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Enter the cumulative quantity already issued for each IEC material,
                                    then click <strong>Save Fulfillment</strong>.
                                </div>
                            <?php endif; ?>

                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead>
                                        <tr>
                                            <th>Material</th>
                                            <th>Source</th>
                                            <th class="text-end">Requested</th>
                                            <th class="text-end">Fulfilled</th>
                                            <th style="width:130px;">Update To</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($iecFulfillItems as $iecLine): ?>
                                        <tr>
                                            <td class="fw-bold">
                                                <?= e($iecLine["material_name"]) ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($iecLine["library_title"])): ?>
                                                    <span class="badge bg-success-subtle text-success border">
                                                        <?= e($iecLine["library_title"]) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary-subtle text-secondary border">
                                                        Free text
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <?= (int)$iecLine["quantity"] ?>
                                            </td>
                                            <td class="text-end">
                                                <?= (int)($iecLine["fulfilled_qty"] ?? 0) ?>
                                            </td>
                                            <td>
                                                <input
                                                    type="number"
                                                    class="form-control form-control-sm"
                                                    name="fulfill_qty[<?= (int)$iecLine["id"] ?>]"
                                                    min="0"
                                                    max="<?= (int)$iecLine["quantity"] ?>"
                                                    value="<?= (int)($iecLine["fulfilled_qty"] ?? 0) ?>"
                                                    <?= $canFulfillIec ? "" : "disabled" ?>
                                                >
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($canFulfillIec): ?>
                                <button
                                    type="submit"
                                    class="btn btn-success"
                                >
                                    <i class="bi bi-check-circle me-1"></i>
                                    Save Fulfillment
                                </button>
                            <?php else: ?>
                                <div class="alert alert-warning mb-0">
                                    Only the assigned HPU staff member,
                                    supervisor or administrator can update
                                    fulfillment for this request.
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                </form>

                <?php endif; ?>

                <!-- =========================================
                     DEVELOPMENT AND REVIEW
                ========================================== -->
                <form
                    method="POST"
                    action="<?= e(BASE_URL) ?>/staff/save_review.php"
                >
                    <input type="hidden" name="request_id" value="<?= (int) $requestId ?>">
                    <input type="hidden" name="save_type" value="development">

                    <section class="card mb-4">
                        <div class="section-banner">
                            III. Development and Review
                            <small>(to be filled out by HPU Personnel)</small>
                        </div>

                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Date of Initial Draft Submission</label>
                                    <input
                                        type="date"
                                        class="form-control"
                                        name="initial_draft_date"
                                        value="<?= e($request["initial_draft_date"] ?? "") ?>"
                                    >
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Final Design Approval Date</label>
                                    <input
                                        type="date"
                                        class="form-control"
                                        name="final_approval_date"
                                        value="<?= e($request["final_approval_date"] ?? "") ?>"
                                    >
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Revision Required?</label>
                                    <select
                                        id="revisionRequired"
                                        class="form-select"
                                        name="revision_required"
                                    >
                                        <option value="">Select</option>

                                        <option
                                            value="1"
                                            <?= (string) ($request["revision_required"] ?? "") === "1" ? "selected" : "" ?>
                                        >
                                            Yes
                                        </option>

                                        <option
                                            value="0"
                                            <?= (string) ($request["revision_required"] ?? "") === "0" ? "selected" : "" ?>
                                        >
                                            No
                                        </option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Number of Revisions</label>
                                    <input
                                        id="revisionCount"
                                        type="number"
                                        min="0"
                                        class="form-control"
                                        name="revision_count"
                                        value="<?= (int) ($request["revision_count"] ?? 0) ?>"
                                    >
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Mode of Distribution</label>
                                    <input
                                        class="form-control"
                                        name="distribution_mode"
                                        value="<?= e($request["distribution_mode"] ?: "N/A") ?>"
                                    >
                                </div>

                                <div class="col-12">
                                    <label class="form-label">
                                        Google Drive Link for Draft / Final Design
                                        <span class="text-muted">(Optional)</span>
                                    </label>

                                    <input
                                        type="url"
                                        class="form-control"
                                        name="final_design_drive_link"
                                        value="<?= e($request["final_design_drive_link"] ?? "") ?>"
                                        placeholder="https://drive.google.com/file/d/FILE_ID/view?usp=sharing"
                                    >

                                    <div class="form-text">
                                        Set the file permission to “Anyone with the link can view.”
                                    </div>

                                    <?php if (!empty($request["final_design_drive_link"])): ?>
                                        <div class="mt-2">
                                            <a
                                                class="btn btn-sm btn-outline-primary"
                                                href="<?= e($request["final_design_drive_link"]) ?>"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                <i class="bi bi-google me-1"></i>
                                                Open Current Drive File
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end mt-4">
                                <button class="btn btn-primary" type="submit">
                                    <i class="bi bi-save me-1"></i>
                                    Save Development Details
                                </button>
                            </div>
                        </div>
                    </section>
                </form>

                <div class="mb-4">
                    <a
                        class="btn btn-outline-secondary"
                        href="<?= e(BASE_URL) ?>/staff/requests.php"
                    >
                        <i class="bi bi-arrow-left me-1"></i>
                        Back to Requests
                    </a>
                </div>

            </div>

        </main>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {

    const REQUEST_REF = <?= json_encode(
        [
            "job_no" => $request["job_no"] ?? "",
            "project_title" => $request["project_title"] ?? ""
        ],
        JSON_HEX_TAG |
        JSON_HEX_APOS |
        JSON_HEX_QUOT |
        JSON_HEX_AMP |
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    ) ?>;

    /* =====================================================
       STAFF REVIEW MODAL
    ===================================================== */
    const staffForm = document.getElementById("staffReviewForm");
    const staffModalEl = document.getElementById("staffReviewModal");
    const staffConfirmBtn = document.getElementById("staffReviewConfirmBtn");
    const staffNotify = document.getElementById("staffNotifyRequestor");
    const staffMsg = document.getElementById("staffNotificationMessage");

    let staffConfirmFlag = false;
    let staffMsgDirty = false;

    if (staffForm && staffModalEl) {
        const staffModal = bootstrap.Modal.getOrCreateInstance(staffModalEl);

        if (staffMsg) {
            staffMsg.addEventListener("input", function () {
                staffMsgDirty = true;
            });
        }

        if (staffNotify && staffMsg) {
            staffNotify.addEventListener("change", function () {
                staffMsg.disabled = !staffNotify.checked;
            });
        }

        staffForm.addEventListener("submit", function (event) {
            if (staffConfirmFlag) {
                staffConfirmFlag = false;
                return;
            }

            event.preventDefault();

            if (!staffMsgDirty) {
                staffMsg.value =
                    "Your job request " + REQUEST_REF.job_no +
                    " (" + REQUEST_REF.project_title +
                    ") is now under review by HPU. We will update you once a decision has been made.";
            }

            staffModal.show();
        });

        if (staffConfirmBtn) {
            staffConfirmBtn.addEventListener("click", function () {
                if (!staffForm.checkValidity()) {
                    staffForm.reportValidity();
                    return;
                }

                staffConfirmFlag = true;
                staffForm.requestSubmit();
            });
        }
    }

    /* =====================================================
       SUPERVISOR APPROVAL MODAL
    ===================================================== */
    const approvalForm = document.getElementById("approvalForm");
    const approvalModalEl = document.getElementById("approvalModal");
    const approvalConfirmBtn = document.getElementById("approvalConfirmBtn");

    const decision = document.getElementById("decision");
    const approvedBy = document.getElementById("approvedBy");

    const modalActionLabel = document.getElementById("modalActionLabel");
    const modalStatusPill = document.getElementById("modalStatusPill");
    const modalReason = document.getElementById("modalDecisionReason");
    const modalReasonMarker = document.getElementById("modalReasonRequiredMarker");
    const modalNotify = document.getElementById("modalNotifyRequestor");
    const modalMsg = document.getElementById("modalNotificationMessage");

    let approvalConfirmFlag = false;
    let approvalMsgDirty = false;

    const APPROVAL_CONFIG = {
        "Approved": {
            title: "Approve Request",
            sub: "The request will be marked as Approved and the requestor will be notified.",
            icon: "bi-check2-circle",
            pill: "success",
            btn: "btn-success",
            label: "Approved"
        },
        "For Revision": {
            title: "Return for Revision",
            sub: "The request will be sent back to the requestor for revision.",
            icon: "bi-arrow-repeat",
            pill: "warning",
            btn: "btn-warning",
            label: "For Revision"
        },
        "Disapproved": {
            title: "Disapprove Request",
            sub: "The request will be marked as Disapproved.",
            icon: "bi-x-octagon",
            pill: "danger",
            btn: "btn-danger",
            label: "Disapproved"
        },
        "Completed": {
            title: "Mark as Completed",
            sub: "The request will be marked as Completed and the requestor will be invited to evaluate.",
            icon: "bi-check2-all",
            pill: "success",
            btn: "btn-success",
            label: "Completed"
        }
    };

    function usesReason(selectedDecision) {
        return selectedDecision === "For Revision" || selectedDecision === "Disapproved";
    }

    function defaultApprovalMessage(selectedDecision) {
        const ref = REQUEST_REF.job_no + " (" + REQUEST_REF.project_title + ")";
        const reason = modalReason ? modalReason.value.trim() : "";

        switch (selectedDecision) {
            case "Approved": {
                const approver = approvedBy && approvedBy.selectedOptions.length
                    ? approvedBy.selectedOptions[0].textContent.trim()
                    : "HPU";

                return "Your job request " + ref + " has been approved. Approved by " + approver + ".";
            }

            case "For Revision":
                return "Your job request " + ref +
                    " was returned for revision. Reason: " +
                    (reason || "Please see the remarks.");

            case "Disapproved":
                return "Your job request " + ref +
                    " was disapproved. Reason: " +
                    (reason || "Please see the remarks.");

            case "Completed":
                return "Your job request " + ref +
                    " is now complete. Please take a moment to complete the Client Experience Evaluation so we can continue to improve our service.";

            default:
                return "Your job request " + ref + " has been updated.";
        }
    }

    function updateApprovalValidation() {
        if (approvedBy) {
            approvedBy.required = decision.value === "Approved";
        }

        if (modalReasonMarker) {
            modalReasonMarker.style.display =
                decision.value === "For Revision" || decision.value === "Disapproved"
                    ? "inline"
                    : "none";
        }
    }

    function prepareApprovalModal() {
        const config = APPROVAL_CONFIG[decision.value];

        if (config) {
            if (modalActionLabel) modalActionLabel.textContent = config.label;
            if (modalStatusPill) {
                modalStatusPill.textContent = config.label;
                modalStatusPill.className = "badge bg-" + config.pill;
            }
            if (modalReasonMarker) {
                modalReasonMarker.style.display = usesReason(decision.value) ? "inline" : "none";
            }

            const title = document.getElementById("approvalModalTitle");
            const subtitle = document.getElementById("approvalModalSub");
            const icon = document.getElementById("approvalModalIcon");

            if (title) title.textContent = config.title;
            if (subtitle) subtitle.textContent = config.sub;
            if (icon) icon.innerHTML = '<i class="bi ' + config.icon + '"></i>';

            if (approvalConfirmBtn) {
                approvalConfirmBtn.className = "btn " + config.btn;
            }
        }

        if (modalMsg && !approvalMsgDirty) {
            modalMsg.value = defaultApprovalMessage(decision.value);
        }

        if (modalNotify && modalMsg) {
            modalMsg.disabled = !modalNotify.checked;
        }
    }

    if (modalMsg) {
        modalMsg.addEventListener("input", function () {
            approvalMsgDirty = true;
        });
    }

    if (modalNotify && modalMsg) {
        modalNotify.addEventListener("change", function () {
            modalMsg.disabled = !modalNotify.checked;
        });
    }

    if (approvalForm && approvalModalEl && approvalConfirmBtn) {
        const approvalModal = bootstrap.Modal.getOrCreateInstance(approvalModalEl);

        approvalForm.addEventListener("submit", function (event) {
            if (approvalConfirmFlag) {
                approvalConfirmFlag = false;
                return;
            }

            event.preventDefault();
            prepareApprovalModal();
            approvalModal.show();
        });

        approvalConfirmBtn.addEventListener("click", function () {
            if (!approvalForm.checkValidity()) {
                approvalForm.reportValidity();
                return;
            }

            if (modalReason && usesReason(decision.value) && modalReason.value.trim() === "") {
                modalReason.setCustomValidity("Please provide the reason/remarks for this action.");
                modalReason.reportValidity();
                return;
            }

            if (modalReason) {
                modalReason.setCustomValidity("");
            }

            if (modalMsg && !approvalMsgDirty) {
                modalMsg.value = defaultApprovalMessage(decision.value);
            }

            approvalConfirmFlag = true;
            approvalForm.requestSubmit();
        });
    }

    if (decision) {
        decision.addEventListener("change", updateApprovalValidation);
    }

    updateApprovalValidation();

    /* =====================================================
       DEVELOPMENT SECTION (revision count)
    ===================================================== */
    const revisionRequired = document.getElementById("revisionRequired");
    const revisionCount = document.getElementById("revisionCount");

    if (revisionRequired && revisionCount) {
        function updateRevisionCount() {
            const revisionIsRequired = revisionRequired.value === "1";

            revisionCount.disabled = !revisionIsRequired;

            if (!revisionIsRequired) {
                revisionCount.value = 0;
            }
        }

        revisionRequired.addEventListener("change", updateRevisionCount);
        updateRevisionCount();
    }

});
</script>

</body>
</html>