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

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header(
        "Location: " .
        BASE_URL .
        "/staff/requests.php"
    );

    exit;
}

$role = $_SESSION["role"] ?? "";

/*
 * Every active supervisor and HPU staff account is a valid approver.
 * Loaded live so new accounts appear automatically.
 */
$allowedApprovers = [];

$approverStmt = $conn->prepare(
    "SELECT name
     FROM users
     WHERE role IN ('supervisor', 'staff')
       AND status = 'Active'"
);

if ($approverStmt) {
    $approverStmt->execute();

    $allowedApprovers = array_column(
        $approverStmt
            ->get_result()
            ->fetch_all(MYSQLI_ASSOC),
        "name"
    );

    $approverStmt->close();
}

$requestId = postInt("request_id");
$saveType = postString("save_type");

if ($requestId <= 0) {
    alertBack("Invalid request ID.");
}

/* =========================================================
   GET CURRENT REQUEST
========================================================= */
$stmt = $conn->prepare(
    "SELECT
        id,
        approval_status,
        reviewed_by,
        final_design_drive_link
     FROM job_requests
     WHERE id = ?
     LIMIT 1"
);

$stmt->bind_param(
    "i",
    $requestId
);

$stmt->execute();

$currentRequest = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();

if (!$currentRequest) {
    alertBack("Job request not found.");
}

$isCompleted = in_array(
    $currentRequest["approval_status"],
    [
        "Completed",
        "Closed"
    ],
    true
);

/* =========================================================
   STAFF REVIEW
========================================================= */
if ($saveType === "staff_review") {

    if (
        !in_array(
            $role,
            [
                "staff",
                "admin"
            ],
            true
        )
    ) {
        alertBack(
            "You are not allowed to review this request."
        );
    }

    if ($isCompleted) {
        alertBack(
            "A completed request can no longer be reviewed."
        );
    }

    /*
     * Define variables before using them.
     */
    $reviewedBy = postString(
        "reviewed_by"
    );

    $reviewedDate = postString(
        "reviewed_date"
    );

    if ($reviewedBy === "") {
        alertBack(
            "Please select the HPU personnel who reviewed the request."
        );
    }

    if (!validDate($reviewedDate)) {
        alertBack(
            "Please enter a valid review date."
        );
    }

    $oldStatus =
        $currentRequest["approval_status"];

    $newStatus =
        $oldStatus === "Pending Review"
            ? "Under Review"
            : $oldStatus;

    $actionEntry =
        "\nReviewed by " .
        $reviewedBy .
        " on " .
        date("Y-m-d H:i:s") .
        ".";

    try {
        $conn->begin_transaction();

        $stmt = $conn->prepare(
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

        $stmt->bind_param(
            "ssssi",
            $reviewedBy,
            $reviewedDate,
            $newStatus,
            $actionEntry,
            $requestId
        );

        $stmt->execute();
        $stmt->close();

        /*
         * Activity log belongs here,
         * after all variables are defined.
         */
        addRequestActivity(
            $conn,
            $requestId,
            (int) (
                $_SESSION["user_id"] ?? 0
            ),
            "reviewed",
            "Staff Review Saved",
            "The request was reviewed by " .
                $reviewedBy .
                ".",
            $oldStatus,
            $newStatus,
            [
                "reviewed_by" =>
                    $reviewedBy,

                "reviewed_date" =>
                    $reviewedDate
            ]
        );

        $conn->commit();

        $_SESSION["request_message"] = [
            "type" => "success",
            "text" =>
                "Staff review saved successfully."
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

/* =========================================================
   SUPERVISOR APPROVAL
========================================================= */
if ($saveType === "supervisor_approval") {

    if (
        !in_array(
            $role,
            [
                "supervisor",
                "admin"
            ],
            true
        )
    ) {
        alertBack(
            "Only a supervisor or administrator can approve requests."
        );
    }

    if ($isCompleted) {
        alertBack(
            "A completed request can no longer have its decision changed."
        );
    }

    if (
        empty(
            $currentRequest["reviewed_by"]
        )
    ) {
        alertBack(
            "The request must be reviewed before supervisor approval."
        );
    }

    $decision = postString(
        "decision"
    );

    $decisionReason = postString(
        "decision_reason"
    );

    $approvedBy = postString(
        "approved_by"
    );

    $approvedDate = postString(
        "approved_date"
    );

    if ($decision === "Approved") {
        if (!in_array($approvedBy, $allowedApprovers, true)) {
            alertBack("Please select a valid supervisor or HPU staff member.");
        }

        if (!validDate($approvedDate)) {
            alertBack("Please enter a valid approval date.");
        }
    }

    $allowedDecisions = [
        "Approved",
        "For Revision",
        "Disapproved",
        "Completed"
    ];

    if (
        !in_array(
            $decision,
            $allowedDecisions,
            true
        )
    ) {
        alertBack(
            "Please select a valid decision."
        );
    }

    if (
        in_array(
            $decision,
            [
                "For Revision",
                "Disapproved"
            ],
            true
        ) &&
        $decisionReason === ""
    ) {
        alertBack(
            "Please enter the reason for the selected decision."
        );
    }

    $oldStatus =
        $currentRequest["approval_status"];

    $disapproved =
        $decision === "Disapproved"
            ? 1
            : 0;

    if ($decision !== "Approved") {
        $approvedBy = null;
        $approvedDate = null;
    }

    $activityType = match ($decision) {
        "Approved" =>
            "approved",

        "For Revision" =>
            "revision",

        "Disapproved" =>
            "disapproved",

        "Completed" =>
            "completed",

        default =>
            "updated"
    };

    $activityTitle = match ($decision) {
        "Approved" =>
            "Request Approved",

        "For Revision" =>
            "Revision Requested",

        "Disapproved" =>
            "Request Disapproved",

        "Completed" =>
            "Request Completed",

        default =>
            "Request Status Updated"
    };

    $activityDescription = match ($decision) {
        "Approved" =>
            "The job request was approved by " . $approvedBy . ".",

        "For Revision" =>
            $decisionReason,

        "Disapproved" =>
            $decisionReason,

        "Completed" =>
            "The requested output was marked as completed.",

        default =>
            "The request status was updated."
    };

    try {
        $conn->begin_transaction();

        $stmt = $conn->prepare(
            "UPDATE job_requests
             SET approval_status = ?,
                 disapproved = ?,
                 disapproved_reason = ?,
                 approved_by = ?,
                 approved_date = ?
             WHERE id = ?"
        );

        $stmt->bind_param(
            "sisssi",
            $decision,
            $disapproved,
            $decisionReason,
            $approvedBy,
            $approvedDate,
            $requestId
        );

        $stmt->execute();
        $stmt->close();

        addRequestActivity(
            $conn,
            $requestId,
            (int) (
                $_SESSION["user_id"] ?? 0
            ),
            $activityType,
            $activityTitle,
            $activityDescription,
            $oldStatus,
            $decision,
            [
                "approved_by" =>
                    $approvedBy,

                "approved_date" =>
                    $approvedDate,

                "reason" =>
                    $decisionReason
            ]
        );

        $conn->commit();

        $_SESSION["request_message"] = [
            "type" => "success",
            "text" =>
                "Supervisor decision saved successfully."
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

/* =========================================================
   DEVELOPMENT AND REVIEW
========================================================= */
if ($saveType === "development") {

    $initialDraftDate = postString(
        "initial_draft_date"
    );

    $finalApprovalDate = postString(
        "final_approval_date"
    );

    $revisionRequiredRaw = postString(
        "revision_required"
    );

    $revisionCount = postInt(
        "revision_count"
    );

    $distributionMode = postString(
        "distribution_mode"
    );

    $finalDesignDriveLink = trim(
        postString(
            "final_design_drive_link"
        )
    );

    if (
        $initialDraftDate !== "" &&
        !validDate($initialDraftDate)
    ) {
        alertBack(
            "Invalid initial draft date."
        );
    }

    if (
        $finalApprovalDate !== "" &&
        !validDate($finalApprovalDate)
    ) {
        alertBack(
            "Invalid final approval date."
        );
    }

    if ($finalDesignDriveLink !== "") {
        if (!filter_var($finalDesignDriveLink, FILTER_VALIDATE_URL)) {
            alertBack("Please enter a valid Google Drive URL.");
        }

        $driveHost = strtolower(
            (string) parse_url(
                $finalDesignDriveLink,
                PHP_URL_HOST
            )
        );

        if (!in_array($driveHost, ["drive.google.com", "docs.google.com"], true)) {
            alertBack("The final design link must be a Google Drive link.");
        }
    }

    $finalDesignDriveLink =
        $finalDesignDriveLink === ""
            ? null
            : $finalDesignDriveLink;

    $initialDraftDate =
        $initialDraftDate === ""
            ? null
            : $initialDraftDate;

    $finalApprovalDate =
        $finalApprovalDate === ""
            ? null
            : $finalApprovalDate;

    $revisionRequired = null;

    if ($revisionRequiredRaw === "1") {
        $revisionRequired = 1;

        if ($revisionCount <= 0) {
            alertBack(
                "Please enter the number of revisions."
            );
        }
    } elseif ($revisionRequiredRaw === "0") {
        $revisionRequired = 0;
        $revisionCount = 0;
    }

    if ($distributionMode === "") {
        $distributionMode = "N/A";
    }

    try {
        $conn->begin_transaction();

        $stmt = $conn->prepare(
            "UPDATE job_requests
             SET initial_draft_date = ?,
                 final_approval_date = ?,
                 revision_required = ?,
                 revision_count = ?,
                 distribution_mode = ?,
                 final_design_drive_link = ?
             WHERE id = ?"
        );

        $stmt->bind_param(
            "ssiissi",
            $initialDraftDate,
            $finalApprovalDate,
            $revisionRequired,
            $revisionCount,
            $distributionMode,
            $finalDesignDriveLink,
            $requestId
        );

        $stmt->execute();
        $stmt->close();

        addRequestActivity(
            $conn,
            $requestId,
            (int) (
                $_SESSION["user_id"] ?? 0
            ),
            "development",
            "Development Details Updated",
            "Initial draft, revision and distribution details were updated.",
            null,
            null,
            [
                "initial_draft_date" =>
                    $initialDraftDate,

                "final_approval_date" =>
                    $finalApprovalDate,

                "revision_required" =>
                    $revisionRequired,

                "revision_count" =>
                    $revisionCount,

                "distribution_mode" =>
                    $distributionMode,

                "final_design_drive_link" =>
                    $finalDesignDriveLink
            ]
        );

        $conn->commit();

        $_SESSION["request_message"] = [
            "type" => "success",
            "text" =>
                "Development details saved successfully."
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
            "Development save error: " .
            $exception->getMessage()
        );

        alertBack(
            "Unable to save development details: " .
            $exception->getMessage()
        );
    }
}

alertBack("Invalid save action.");