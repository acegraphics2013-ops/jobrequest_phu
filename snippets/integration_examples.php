<?php
/*
Add this include to each save handler:
include "../includes/activity_log.php";
*/

/* REQUEST CREATED */
addRequestActivity(
    $conn,
    $requestId,
    (int) ($_SESSION["user_id"] ?? 0),
    "created",
    "Request Submitted",
    "The job request was created and submitted for review.",
    null,
    "Pending Review"
);

/* STAFF REVIEW */
addRequestActivity(
    $conn,
    $requestId,
    (int) ($_SESSION["user_id"] ?? 0),
    "reviewed",
    "Staff Review Saved",
    "Reviewed by " . $reviewedBy . ".",
    $currentRequest["approval_status"],
    $newStatus
);

/* APPROVED */
addRequestActivity(
    $conn,
    $requestId,
    (int) ($_SESSION["user_id"] ?? 0),
    "approved",
    "Request Approved",
    "The supervisor approved this request.",
    $currentRequest["approval_status"],
    "Approved"
);

/* FOR REVISION */
addRequestActivity(
    $conn,
    $requestId,
    (int) ($_SESSION["user_id"] ?? 0),
    "revision",
    "Revision Requested",
    $decisionReason,
    $currentRequest["approval_status"],
    "For Revision"
);

/* DEVELOPMENT */
addRequestActivity(
    $conn,
    $requestId,
    (int) ($_SESSION["user_id"] ?? 0),
    "development",
    "Development Details Updated",
    "Draft, revisions and distribution details were updated."
);

/* COMPLETED */
addRequestActivity(
    $conn,
    $requestId,
    (int) ($_SESSION["user_id"] ?? 0),
    "completed",
    "Request Completed",
    "The requested output was marked completed.",
    $currentRequest["approval_status"],
    "Completed"
);

/* EVALUATION REQUESTED */
addRequestActivity(
    $conn,
    $requestId,
    (int) ($_SESSION["user_id"] ?? 0),
    "evaluation_requested",
    "Evaluation Requested",
    "The requesting user was notified to submit the evaluation.",
    "Completed",
    "For Evaluation"
);

/* EVALUATION SUBMITTED */
addRequestActivity(
    $conn,
    $requestId,
    (int) ($_SESSION["user_id"] ?? 0),
    "evaluation_submitted",
    "Evaluation Submitted",
    "Satisfaction rating: " . $satisfactionRating . " out of 5.",
    "For Evaluation",
    "Closed"
);
