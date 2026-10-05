<?php

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";

checkLogin();
requireModule($conn, "my_requests");

$userId = (int) ($_SESSION["user_id"] ?? 0);
$role = (string) ($_SESSION["role"] ?? "user");

/*
 * Staff, supervisors and admins may access the page for
 * authorized job-request monitoring.
 */
$authorizedManagementRoles = [
    "staff",
    "supervisor",
    "admin"
];

$isAuthorizedManagement = in_array(
    $role,
    $authorizedManagementRoles,
    true
);

/*
 * Only staff and supervisors may view and use the
 * "Notify Requestor" section.
 */
$notificationRoles = [
    "staff",
    "supervisor"
];

$canNotifyRequestor = in_array(
    $role,
    $notificationRoles,
    true
);

/* =========================================================
   CSRF TOKEN
========================================================= */
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = (string) $_SESSION["csrf_token"];

/* =========================================================
   REQUEST ID  (job mode)  OR  PUBLICATION (event/recognition)
========================================================= */
$pubKind = trim(
    (string) (
        $_SERVER["REQUEST_METHOD"] === "POST"
            ? ($_POST["publication_kind"] ?? "")
            : ($_GET["kind"] ?? "")
    )
);

if (
    !in_array(
        $pubKind,
        ["event", "recognition"],
        true
    )
) {
    $pubKind = "";
}

$isPubMode = $pubKind !== "";

$pubId = 0;

if ($isPubMode) {
    $pubId = (int) (
        $_SERVER["REQUEST_METHOD"] === "POST"
            ? ($_POST["publication_id"] ?? 0)
            : ($_GET["id"] ?? 0)
    );

    if ($pubId <= 0) {
        die("Invalid publication request.");
    }
}

$requestId = filter_input(
    $_SERVER["REQUEST_METHOD"] === "POST"
        ? INPUT_POST
        : INPUT_GET,
    "request_id",
    FILTER_VALIDATE_INT
);

if (
    !$isPubMode
    &&
    !$requestId
    &&
    $_SERVER["REQUEST_METHOD"] !== "POST"
) {
    $requestId = filter_input(
        INPUT_GET,
        "id",
        FILTER_VALIDATE_INT
    );
}

if (
    !$isPubMode
    &&
    (!$requestId || $requestId <= 0)
) {
    die("Invalid job request ID.");
}

if (!$isPubMode) {
    $requestId = (int) $requestId;
}

/* =========================================================
   LOAD JOB REQUEST  (or publication in pub mode)
========================================================= */
if ($isPubMode) {
    $pubTable =
        $pubKind === "recognition"
            ? "publication_recognition_submissions"
            : "publication_event_submissions";

    $pubTitleColumn =
        $pubKind === "recognition"
            ? "recognized_entity"
            : "title";

    $stmt = $conn->prepare(
        "SELECT
            p.*,
            u.name AS account_name,
            u.email AS requestor_email,
            u.department AS account_department
          FROM `{$pubTable}` p
          INNER JOIN users u
            ON u.id = p.submitted_by
          WHERE p.id = ?
          LIMIT 1"
    );

    $stmt->bind_param(
        "i",
        $pubId
    );

    $stmt->execute();

    $pubRecord = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    if (!$pubRecord) {
        http_response_code(404);
        die("Publication request not found.");
    }

    $request = [
        "requestor" =>
            (int)($pubRecord["submitted_by"] ?? 0),
        "approval_status" =>
            (string)($pubRecord["status"] ?? "For Review"),
        "job_no" =>
            publicationDisplayCode($pubKind, (int) $pubId),
        "project_title" =>
            (string)(
                $pubRecord[$pubTitleColumn]
                ?? "Publication Submission"
            ),
        "requestor_name" =>
            (string)(
                $pubRecord["account_name"]
                ?? ""
            ),
        "account_name" =>
            (string)(
                $pubRecord["account_name"]
                ?? ""
            ),
        "requestor_email" =>
            (string)(
                $pubRecord["requestor_email"]
                ?? ""
            ),
        "department" =>
            (string)(
                $pubRecord["account_department"]
                ?? ""
            ),
        "date_request" =>
            substr(
                (string)(
                    $pubRecord["created_at"]
                    ?? ""
                ),
                0,
                10
            ),
        "date_completion" =>
            substr(
                (string)(
                    $pubRecord["preferred_publication_date"]
                    ?? ""
                ),
                0,
                10
            )
    ];
} else {
    $stmt = $conn->prepare(
        "SELECT
            jr.*,
            u.name AS account_name,
            u.email AS requestor_email
         FROM job_requests jr
         INNER JOIN users u
            ON u.id = jr.requestor
         WHERE jr.id = ?
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
        http_response_code(404);
        die("Job request not found.");
    }
}

$requestorId = (int) $request["requestor"];
$isRequestOwner = $requestorId === $userId;

if (!$isRequestOwner && !$isAuthorizedManagement) {
    http_response_code(403);
    die("You do not have permission to view this survey.");
}

$currentStatus = trim(
    (string) ($request["approval_status"] ?? "")
);

$evaluationStatuses = $isPubMode
    ? [
        "Published",
        "Closed"
    ]
    : [
        "Completed",
        "For Evaluation"
    ];

$canSubmitSurvey =
    $isRequestOwner
    && in_array(
        $currentStatus,
        $evaluationStatuses,
        true
    );

$surveyPath =
    "/user/client_experience_survey.php";

/* =========================================================
   CHECK SURVEY TABLE
========================================================= */
$tableResult = $conn->query(
    "SHOW TABLES LIKE 'job_request_client_experience'"
);

$surveyTableReady =
    $tableResult
    && $tableResult->num_rows > 0;

/*
 * Publication support: nullable publication_kind / publication_id
 * plus a unique key, and request_id made nullable (MySQL allows
 * multiple NULLs in a UNIQUE key, so job uniqueness is kept).
 */
$pubSurveyReady = false;

if ($surveyTableReady) {
    $colResult = $conn->query(
        "SHOW COLUMNS
          FROM job_request_client_experience
          LIKE 'publication_kind'"
    );

    if (!$colResult || $colResult->num_rows === 0) {
        $conn->query(
            "ALTER TABLE job_request_client_experience
              MODIFY request_id INT NULL,
              ADD COLUMN publication_kind VARCHAR(20) NULL
                AFTER request_id,
              ADD COLUMN publication_id INT NULL
                AFTER publication_kind,
              ADD UNIQUE KEY uq_client_experience_publication
                (publication_kind, publication_id)"
        );
    }

    $checkResult = $conn->query(
        "SHOW COLUMNS
          FROM job_request_client_experience
          LIKE 'publication_kind'"
    );

    $pubSurveyReady =
        $checkResult
        && $checkResult->num_rows > 0;
}

if ($isPubMode && !$pubSurveyReady) {
    die(
        "The survey database table is not ready for publications yet. Please reload the page."
    );
}

/* =========================================================
   LOAD EXISTING SURVEY
========================================================= */
$survey = null;

if ($surveyTableReady) {
    if ($isPubMode) {
        $surveyStmt = $conn->prepare(
            "SELECT *
              FROM job_request_client_experience
              WHERE publication_kind = ?
                AND publication_id = ?
              LIMIT 1"
        );

        $surveyStmt->bind_param(
            "si",
            $pubKind,
            $pubId
        );
    } else {
        $surveyStmt = $conn->prepare(
            "SELECT *
             FROM job_request_client_experience
             WHERE request_id = ?
             LIMIT 1"
        );

        $surveyStmt->bind_param(
            "i",
            $requestId
        );
    }

    $surveyStmt->execute();

    $survey = $surveyStmt
        ->get_result()
        ->fetch_assoc();

    $surveyStmt->close();
}

$surveySubmitted = !empty($survey);

/* =========================================================
   FLASH MESSAGE
========================================================= */
$flashMessage =
    $_SESSION["client_survey_message"] ?? null;

unset($_SESSION["client_survey_message"]);

$formError = null;

/* =========================================================
   POST ACTIONS
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $submittedToken =
        (string) ($_POST["csrf_token"] ?? "");

    if (
        !hash_equals(
            $csrfToken,
            $submittedToken
        )
    ) {
        $formError =
            "Your form session expired. Please reload the page.";
    } else {
        $action = trim(
            (string) ($_POST["action"] ?? "")
        );

        /* -------------------------------------------------
           STAFF: NOTIFY REQUESTOR
        ------------------------------------------------- */
        if ($action === "notify_requestor") {
            if ($isPubMode) {
                $formError =
                    "Requestor notifications are available for job requests only.";
            } elseif (!$canNotifyRequestor) {
                $formError =
                    "Only staff and supervisors may notify the requestor.";
            } elseif (
                !in_array(
                    $currentStatus,
                    $evaluationStatuses,
                    true
                )
            ) {
                $formError =
                    "The survey notification is available only when the request is Completed or For Evaluation.";
            } else {
                $notificationMessage = trim(
                    (string) (
                        $_POST[
                            "notification_message"
                        ] ?? ""
                    )
                );

                if ($notificationMessage === "") {
                    $formError =
                        "Please enter a notification message.";
                } else {
                    $actionUrl =
                        rtrim(BASE_URL, "/") .
                        $surveyPath .
                        "?id=" .
                        $requestId;

                    $title =
                        "Client Experience Survey – " .
                        (
                            $request["job_no"]
                            ?: "Job Request"
                        );

                    $notificationType =
                        "client_experience_survey";

                    $notificationStmt =
                        $conn->prepare(
                            "INSERT INTO notifications (
                                request_id,
                                user_id,
                                title,
                                message,
                                notification_type,
                                action_url,
                                is_read,
                                created_at
                             ) VALUES (
                                ?,
                                ?,
                                ?,
                                ?,
                                ?,
                                ?,
                                0,
                                NOW()
                             )"
                        );

                    $notificationStmt->bind_param(
                        "iissss",
                        $requestId,
                        $requestorId,
                        $title,
                        $notificationMessage,
                        $notificationType,
                        $actionUrl
                    );

                    if (
                        !$notificationStmt->execute()
                    ) {
                        $formError =
                            "The notification could not be sent.";
                    } else {
                        $_SESSION[
                            "client_survey_message"
                        ] = [
                            "type" => "success",
                            "text" =>
                                "The client experience survey notification was sent to the requestor."
                        ];

                        $notificationStmt->close();

                        header(
                            "Location: " .
                            rtrim(BASE_URL, "/") .
                            $surveyPath .
                            "?id=" .
                            $requestId
                        );

                        exit;
                    }

                    $notificationStmt->close();
                }
            }
        }

        /* -------------------------------------------------
           REQUESTOR: SUBMIT SURVEY
        ------------------------------------------------- */
        if ($action === "submit_survey") {
            if (!$isRequestOwner) {
                $formError =
                    "Only the requestor can submit this survey.";
            } elseif (!$surveyTableReady) {
                $formError =
                    "The survey database table is not installed. Run the provided SQL file first.";
            } elseif (!$canSubmitSurvey) {
                $formError = $isPubMode
                    ? "The survey becomes available only after the publication is marked Published."
                    : "The survey becomes available only after the request is marked Completed.";
            } else {
                $age = filter_input(
                    INPUT_POST,
                    "age",
                    FILTER_VALIDATE_INT,
                    [
                        "options" => [
                            "min_range" => 1,
                            "max_range" => 120
                        ]
                    ]
                );

                $sex = trim(
                    (string) ($_POST["sex"] ?? "")
                );

                $dateOfVisit = trim(
                    (string) (
                        $_POST["date_of_visit"] ?? ""
                    )
                );

                $timeOfVisit = trim(
                    (string) (
                        $_POST["time_of_visit"] ?? ""
                    )
                );

                $departmentVisited = trim(
                    (string) (
                        $_POST[
                            "department_visited"
                        ] ?? ""
                    )
                );

                $serviceTypes = array_values(
                    array_filter(
                        array_map(
                            "trim",
                            (array) (
                                $_POST[
                                    "service_types"
                                ] ?? []
                            )
                        )
                    )
                );

                $serviceOther = trim(
                    (string) (
                        $_POST["service_other"] ?? ""
                    )
                );

                $ratingFields = [
                    "timeliness",
                    "responsiveness",
                    "quality_output",
                    "professionalism",
                    "communication",
                    "service_met_needs",
                    "overall_satisfaction"
                ];

                $ratings = [];
                $invalidRating = false;

                foreach (
                    $ratingFields as $field
                ) {
                    $value = filter_input(
                        INPUT_POST,
                        $field,
                        FILTER_VALIDATE_INT
                    );

                    if (
                        $value === false
                        || $value < 1
                        || $value > 5
                    ) {
                        $invalidRating = true;
                    }

                    $ratings[$field] =
                        (int) $value;
                }

                $outcomeAssessment = trim(
                    (string) (
                        $_POST[
                            "outcome_assessment"
                        ] ?? ""
                    )
                );

                $phuDidWell = trim(
                    (string) (
                        $_POST["phu_did_well"] ?? ""
                    )
                );

                $phuImprove = trim(
                    (string) (
                        $_POST["phu_improve"] ?? ""
                    )
                );

                $allowedSex = [
                    "Female",
                    "Male",
                    "Prefer not to say"
                ];

                $allowedOutcome = [
                    "Yes",
                    "Partially",
                    "No"
                ];

                if (
                    !$age
                    || !in_array(
                        $sex,
                        $allowedSex,
                        true
                    )
                    || $dateOfVisit === ""
                    || $departmentVisited === ""
                    || empty($serviceTypes)
                    || $invalidRating
                    || !in_array(
                        $outcomeAssessment,
                        $allowedOutcome,
                        true
                    )
                ) {
                    $formError =
                        "Please complete all required survey fields.";
                } elseif (
                    in_array(
                        "Others",
                        $serviceTypes,
                        true
                    )
                    && $serviceOther === ""
                ) {
                    $formError =
                        "Please specify the other service requested.";
                } else {
                    $serviceTypeText =
                        implode(
                            ", ",
                            $serviceTypes
                        );

                    if ($isPubMode) {
                        $saveStmt = $conn->prepare(
                            "INSERT INTO job_request_client_experience (
                                request_id,
                                publication_kind,
                                publication_id,
                                user_id,
                                age,
                                sex,
                                date_of_visit,
                                time_of_visit,
                                department_visited,
                                service_types,
                                service_other,
                                timeliness,
                                responsiveness,
                                quality_output,
                                professionalism,
                                communication,
                                service_met_needs,
                                overall_satisfaction,
                                outcome_assessment,
                                phu_did_well,
                                phu_improve,
                                submitted_at
                             ) VALUES (
                                NULL,
                                ?,
                                ?,
                                ?,
                                ?,
                                ?,
                                ?,
                                NULLIF(?, ''),
                                ?,
                                ?,
                                NULLIF(?, ''),
                                ?,
                                ?,
                                ?,
                                ?,
                                ?,
                                ?,
                                ?,
                                ?,
                                NULLIF(?, ''),
                                NULLIF(?, ''),
                                NOW()
                             )
                             ON DUPLICATE KEY UPDATE
                                user_id = VALUES(user_id),
                                age = VALUES(age),
                                sex = VALUES(sex),
                                date_of_visit = VALUES(date_of_visit),
                                time_of_visit = VALUES(time_of_visit),
                                department_visited = VALUES(department_visited),
                                service_types = VALUES(service_types),
                                service_other = VALUES(service_other),
                                timeliness = VALUES(timeliness),
                                responsiveness = VALUES(responsiveness),
                                quality_output = VALUES(quality_output),
                                professionalism = VALUES(professionalism),
                                communication = VALUES(communication),
                                service_met_needs = VALUES(service_met_needs),
                                overall_satisfaction = VALUES(overall_satisfaction),
                                outcome_assessment = VALUES(outcome_assessment),
                                phu_did_well = VALUES(phu_did_well),
                                phu_improve = VALUES(phu_improve),
                                submitted_at = NOW()"
                        );

                        $saveStmt->bind_param(
                            "siiissssssiiiiiiisss",
                            $pubKind,
                            $pubId,
                            $userId,
                            $age,
                            $sex,
                            $dateOfVisit,
                            $timeOfVisit,
                            $departmentVisited,
                            $serviceTypeText,
                            $serviceOther,
                            $ratings["timeliness"],
                            $ratings["responsiveness"],
                            $ratings["quality_output"],
                            $ratings["professionalism"],
                            $ratings["communication"],
                            $ratings["service_met_needs"],
                            $ratings["overall_satisfaction"],
                            $outcomeAssessment,
                            $phuDidWell,
                            $phuImprove
                        );
                    } else {
                    $saveStmt = $conn->prepare(
                        "INSERT INTO job_request_client_experience (
                            request_id,
                            user_id,
                            age,
                            sex,
                            date_of_visit,
                            time_of_visit,
                            department_visited,
                            service_types,
                            service_other,
                            timeliness,
                            responsiveness,
                            quality_output,
                            professionalism,
                            communication,
                            service_met_needs,
                            overall_satisfaction,
                            outcome_assessment,
                            phu_did_well,
                            phu_improve,
                            submitted_at
                         ) VALUES (
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            NULLIF(?, ''),
                            ?,
                            ?,
                            NULLIF(?, ''),
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            NULLIF(?, ''),
                            NULLIF(?, ''),
                            NOW()
                         )
                         ON DUPLICATE KEY UPDATE
                            age = VALUES(age),
                            sex = VALUES(sex),
                            date_of_visit = VALUES(date_of_visit),
                            time_of_visit = VALUES(time_of_visit),
                            department_visited = VALUES(department_visited),
                            service_types = VALUES(service_types),
                            service_other = VALUES(service_other),
                            timeliness = VALUES(timeliness),
                            responsiveness = VALUES(responsiveness),
                            quality_output = VALUES(quality_output),
                            professionalism = VALUES(professionalism),
                            communication = VALUES(communication),
                            service_met_needs = VALUES(service_met_needs),
                            overall_satisfaction = VALUES(overall_satisfaction),
                            outcome_assessment = VALUES(outcome_assessment),
                            phu_did_well = VALUES(phu_did_well),
                            phu_improve = VALUES(phu_improve),
                            submitted_at = NOW()"
                    );

                    $saveStmt->bind_param(
                        "iiissssssiiiiiiisss",
                        $requestId,
                        $userId,
                        $age,
                        $sex,
                        $dateOfVisit,
                        $timeOfVisit,
                        $departmentVisited,
                        $serviceTypeText,
                        $serviceOther,
                        $ratings["timeliness"],
                        $ratings["responsiveness"],
                        $ratings["quality_output"],
                        $ratings["professionalism"],
                        $ratings["communication"],
                        $ratings["service_met_needs"],
                        $ratings["overall_satisfaction"],
                        $outcomeAssessment,
                        $phuDidWell,
                        $phuImprove
                    );
                    }

                    $conn->begin_transaction();

                    try {
                        if (!$saveStmt->execute()) {
                            throw new RuntimeException(
                                "Survey submission failed."
                            );
                        }

                        if ($isPubMode) {
                            $pubCloseTable =
                                $pubKind === "recognition"
                                    ? "publication_recognition_submissions"
                                    : "publication_event_submissions";

                            $closeStmt = $conn->prepare(
                                "UPDATE `{$pubCloseTable}`
                                  SET status = 'Closed',
                                      reviewed_at = NOW()
                                  WHERE id = ?
                                    AND submitted_by = ?"
                            );

                            $closeStmt->bind_param(
                                "ii",
                                $pubId,
                                $userId
                            );
                        } else {
                        $closeStmt = $conn->prepare(
                            "UPDATE job_requests
                             SET approval_status = 'Closed'
                             WHERE id = ?
                               AND requestor = ?"
                        );

                        $closeStmt->bind_param(
                            "ii",
                            $requestId,
                            $userId
                        );
                        }

                        if (!$closeStmt->execute()) {
                            throw new RuntimeException(
                                "Request closing failed."
                            );
                        }

                        $closeStmt->close();

                        /*
                         * Notify staff, supervisors and admins
                         * that the survey was submitted.
                         */
                        $staffQuery = $conn->query(
                            "SELECT id
                             FROM users
                             WHERE role IN (
                                'staff',
                                'supervisor',
                                'admin'
                             )"
                        );

                        if ($staffQuery) {
                            $staffNotification =
                                $conn->prepare(
                                    "INSERT INTO notifications (
                                        request_id,
                                        user_id,
                                        title,
                                        message,
                                        notification_type,
                                        action_url,
                                        is_read,
                                        created_at
                                     ) VALUES (
                                        ?,
                                        ?,
                                        ?,
                                        ?,
                                        ?,
                                        ?,
                                        0,
                                        NOW()
                                     )"
                                );

                            $staffTitle =
                                "Survey Submitted – " .
                                (
                                    $request["job_no"]
                                    ?: "Job Request"
                                );

                            $staffMessage =
                                "The requestor submitted the Hospital Client Experience Survey for " .
                                (
                                    $request["project_title"]
                                    ?: "the job request"
                                ) .
                                ".";

                            $staffType =
                                "client_experience_submitted";

                            if ($isPubMode) {
                                $staffActionUrl =
                                    rtrim(BASE_URL, "/") .
                                    $surveyPath .
                                    "?kind=" .
                                    urlencode($pubKind) .
                                    "&id=" .
                                    $pubId;
                            } else {
                                $staffActionUrl =
                                    rtrim(BASE_URL, "/") .
                                    $surveyPath .
                                    "?id=" .
                                    $requestId;
                            }

                            $notifyRequestId = $isPubMode
                                ? null
                                : $requestId;

                            while (
                                $staffUser =
                                    $staffQuery->fetch_assoc()
                            ) {
                                $staffUserId =
                                    (int) $staffUser["id"];

                                $staffNotification
                                    ->bind_param(
                                        "iissss",
                                        $notifyRequestId,
                                        $staffUserId,
                                        $staffTitle,
                                        $staffMessage,
                                        $staffType,
                                        $staffActionUrl
                                    );

                                $staffNotification
                                    ->execute();
                            }

                            $staffNotification->close();
                        }

                        $conn->commit();

                        $_SESSION[
                            "client_survey_message"
                        ] = [
                            "type" => "success",
                            "text" =>
                                "Thank you. Your client experience survey was submitted successfully. You can view the completed evaluation from My Requests."
                        ];

                        $saveStmt->close();

                        $redirectQuery = $isPubMode
                            ? "?kind="
                                . urlencode($pubKind)
                                . "&id="
                                . $pubId
                            : "?id="
                                . $requestId;

                        header(
                            "Location: " .
                            rtrim(BASE_URL, "/") .
                            $surveyPath .
                            $redirectQuery
                        );

                        exit;
                    } catch (Throwable $error) {
                        $conn->rollback();

                        $formError =
                            "The survey could not be submitted. Please try again.";
                    }

                    $saveStmt->close();
                }
            }
        }
    }
}

/* =========================================================
   RELOAD SURVEY AFTER POST ERROR/GET
========================================================= */
if ($surveyTableReady) {
    if ($isPubMode && $pubSurveyReady) {
        $surveyStmt = $conn->prepare(
            "SELECT *
              FROM job_request_client_experience
              WHERE publication_kind = ?
                AND publication_id = ?
              LIMIT 1"
        );

        $surveyStmt->bind_param(
            "si",
            $pubKind,
            $pubId
        );
    } else {
        $surveyStmt = $conn->prepare(
            "SELECT *
             FROM job_request_client_experience
             WHERE request_id = ?
             LIMIT 1"
        );

        $surveyStmt->bind_param(
            "i",
            $requestId
        );
    }

    $surveyStmt->execute();

    $survey = $surveyStmt
        ->get_result()
        ->fetch_assoc();

    $surveyStmt->close();

    $surveySubmitted = !empty($survey);
}

/* =========================================================
   UI HELPERS
========================================================= */
function surveyValue(
    ?array $survey,
    string $key,
    mixed $default = ""
): mixed {
    if (!$survey) {
        return $default;
    }

    return array_key_exists($key, $survey)
        ? $survey[$key]
        : $default;
}

function ratingChecked(
    ?array $survey,
    string $field,
    int $rating
): string {
    return (int) surveyValue(
        $survey,
        $field,
        0
    ) === $rating
        ? "checked"
        : "";
}

$serviceOptions = [
    "Event Coverage (Photo)",
    "Event Coverage (Video)",
    "Graphic Design / Layout",
    "IEC Development",
    "Technical Assistance",
    "Health Education Support",
    "Coordination / Facilitation",
    "Policy / Document Review",
    "Others"
];

$selectedServices = array_values(
    array_filter(
        array_map(
            "trim",
            explode(
                ",",
                (string) surveyValue(
                    $survey,
                    "service_types",
                    ""
                )
            )
        )
    )
);

$statusBadgeClass = match ($currentStatus) {
    "Completed" =>
        "bg-primary",

    "For Evaluation" =>
        "bg-warning text-dark",

    "Published" =>
        "bg-success",

    "Closed" =>
        "bg-success",

    default =>
        "bg-secondary"
};

$defaultNotification =
    "Your job request " .
    (
        $request["job_no"]
        ?: "#" . $requestId
    ) .
    " has been completed. Please open and complete the Hospital Client Experience Survey.";
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
        Client Experience Survey |
        <?= e(
            (string) (
                $request["job_no"]
                ?: "Job Request"
            )
        ) ?>
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

    <style>
        :root {
            --survey-primary: #6f42c1;
            --survey-primary-dark: #59359c;
            --survey-border: #d8dee9;
            --survey-soft: #f7f4fc;
            --survey-text: #172033;
            --survey-muted: #64748b;
        }

        body {
            background: #f4f6fa;
        }

        .survey-page {
            max-width: 1180px;
            margin: 0 auto;
        }

        .survey-header {
            overflow: hidden;
            border: 1px solid var(--survey-border);
            border-radius: 18px;
            background: #ffffff;
            box-shadow: 0 12px 30px rgba(15, 23, 42, .07);
        }

        .survey-header-top {
            display: grid;
            grid-template-columns: 58px 1fr auto;
            gap: 16px;
            align-items: center;
            padding: 20px 22px;
            border-bottom: 1px solid var(--survey-border);
            background:
                linear-gradient(
                    135deg,
                    #ffffff,
                    var(--survey-soft)
                );
        }

        .hospital-mark {
            display: grid;
            width: 56px;
            height: 56px;
            place-items: center;
            border-radius: 16px;
            background: #ede9fe;
            color: var(--survey-primary);
            font-size: 26px;
        }

        .hospital-title small {
            display: block;
            color: var(--survey-muted);
            font-size: .73rem;
            font-weight: 800;
            letter-spacing: .07em;
            text-transform: uppercase;
        }

        .hospital-title h1 {
            margin: 2px 0;
            color: var(--survey-text);
            font-size: 1.22rem;
            font-weight: 800;
        }

        .hospital-title h2 {
            margin: 0;
            color: var(--survey-primary-dark);
            font-size: .95rem;
            font-weight: 800;
        }

        .survey-card {
            margin-top: 18px;
            border: 1px solid var(--survey-border);
            border-radius: 18px;
            background: #ffffff;
            box-shadow: 0 10px 26px rgba(15, 23, 42, .05);
        }

        .survey-card .card-body {
            padding: 22px;
        }

        .section-heading {
            display: flex;
            align-items: center;
            gap: 11px;
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--hd-row-line,#edf1f6);
        }

        .section-heading-icon {
            display: grid;
            width: 38px;
            height: 38px;
            flex: 0 0 38px;
            place-items: center;
            border: 1px solid #cfe0f8;
            border-radius: 10px;
            background: #eef4ff;
            color: #2563eb;
            font-size: 17px;
        }

        .section-heading h3 {
            margin: 0;
            color: var(--survey-text);
            font-size: 1rem;
            font-weight: 800;
        }

        .section-heading p {
            margin: 2px 0 0;
            color: var(--survey-muted);
            font-size: .79rem;
        }

        .detail-box {
            height: 100%;
            padding: 14px;
            border: 1px solid var(--survey-border);
            border-radius: 12px;
            background: #f8fafc;
        }

        .detail-label {
            margin-bottom: 4px;
            color: var(--survey-muted);
            font-size: .7rem;
            font-weight: 800;
            text-transform: uppercase;
        }

        .rating-table th,
        .rating-table td {
            vertical-align: middle;
            text-align: center;
        }

        .rating-table th:first-child,
        .rating-table td:first-child {
            width: 48%;
            text-align: left;
        }

        .rating-choice {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }

        .service-grid {
            display: grid;
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
            gap: 10px 16px;
        }

        .service-option {
            padding: 11px 13px;
            border: 1px solid var(--survey-border);
            border-radius: 11px;
            background: #ffffff;
        }

        .form-control,
        .form-select {
            min-height: 44px;
            border-color: var(--survey-border);
            border-radius: 10px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #8b5cf6;
            box-shadow:
                0 0 0 .2rem rgba(111, 66, 193, .12);
        }

        .btn-survey {
            border-color: var(--survey-primary);
            background: var(--survey-primary);
            color: #ffffff;
        }

        .btn-survey:hover,
        .btn-survey:focus {
            border-color: var(--survey-primary-dark);
            background: var(--survey-primary-dark);
            color: #ffffff;
        }

        .privacy-note {
            padding: 14px;
            border-left: 4px solid var(--survey-primary);
            border-radius: 8px;
            background: var(--survey-soft);
            color: #4c3a68;
            font-size: .84rem;
            line-height: 1.5;
        }

        .result-rating {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 42px;
            padding: 6px 10px;
            border-radius: 999px;
            background: #ede9fe;
            color: var(--survey-primary-dark);
            font-weight: 800;
        }

        @media (max-width: 768px) {
            .survey-header-top {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .hospital-mark {
                margin: 0 auto;
            }

            .service-grid {
                grid-template-columns: 1fr;
            }

            .rating-table {
                min-width: 760px;
            }
        }
    </style>

</head>

<body class="app-shell">

<div class="d-flex min-vh-100">

    <?php include "../includes/sidebar.php"; ?>

    <div class="main flex-grow-1">

        <main class="content">

            <div class="container-fluid">

                <div class="survey-page">

                    <section class="survey-header">

                        <div class="survey-header-top">

                            <div class="hospital-mark">
                                <i class="bi bi-hospital"></i>
                            </div>

                            <div class="hospital-title">

                                <small>
                                    Department of Health
                                </small>

                                <h1>
                                    ADELA SERRA TY MEMORIAL MEDICAL CENTER
                                </h1>

                                <h2>
                                    HOSPITAL CLIENT EXPERIENCE SURVEY
                                    FORM (INTERNAL)
                                </h2>

                            </div>

                            <div class="text-md-end">

                                <div class="fw-bold">
                                    <?= e(
                                        (string) (
                                            $request["job_no"]
                                            ?: "Request #" .
                                                $requestId
                                        )
                                    ) ?>
                                </div>

                                <span
                                    class="badge <?= e(
                                        $statusBadgeClass
                                    ) ?>"
                                >
                                    <?= e($currentStatus) ?>
                                </span>

                                <div class="mt-2">
                                    <a
                                        class="btn btn-sm btn-outline-secondary"
                                        href="<?= e(BASE_URL) ?>/user/my_requests.php"
                                    >
                                        <i class="bi bi-arrow-left me-1"></i>
                                        My Requests
                                    </a>
                                </div>

                            </div>

                        </div>

                    </section>

                    <?php if ($flashMessage): ?>

                        <div
                            class="alert alert-<?= e(
                                $flashMessage["type"]
                                ?? "success"
                            ) ?> alert-dismissible fade show mt-3"
                            role="alert"
                        >

                            <?= e(
                                $flashMessage["text"]
                                ?? ""
                            ) ?>

                            <button
                                type="button"
                                class="btn-close"
                                data-bs-dismiss="alert"
                            ></button>

                        </div>

                    <?php endif; ?>

                    <?php if ($formError): ?>

                        <div class="alert alert-danger mt-3">

                            <i
                                class="bi bi-exclamation-circle me-1"
                            ></i>

                            <?= e($formError) ?>

                        </div>

                    <?php endif; ?>

                    <?php if (!$surveyTableReady): ?>

                        <div class="alert alert-warning mt-3">

                            <strong>
                                Database setup required:
                            </strong>

                            Run
                            <code>
                                job_request_client_experience.sql
                            </code>
                            before using this page.

                        </div>

                    <?php endif; ?>

                    <!-- JOB REQUEST DETAILS -->
                    <section class="survey-card">

                        <div class="card-body">

                            <div class="section-heading">

                                <div class="section-heading-icon">
                                    <i class="bi bi-briefcase"></i>
                                </div>

                                <div>

                                    <h3>
                                        Connected Job Request
                                    </h3>

                                    <p>
                                        The survey is linked directly
                                        to this job request.
                                    </p>

                                </div>

                            </div>

                            <div class="row g-3">

                                <div class="col-md-6">

                                    <div class="detail-box">

                                        <div class="detail-label">
                                            Project
                                        </div>

                                        <strong>
                                            <?= e(
                                                (string) (
                                                    $request[
                                                        "project_title"
                                                    ] ?? ""
                                                )
                                            ) ?>
                                        </strong>

                                    </div>

                                </div>

                                <div class="col-md-6">

                                    <div class="detail-box">

                                        <div class="detail-label">
                                            Requestor
                                        </div>

                                        <strong>
                                            <?= e(
                                                (string) (
                                                    $request[
                                                        "requestor_name"
                                                    ]
                                                    ?: $request[
                                                        "account_name"
                                                    ]
                                                )
                                            ) ?>
                                        </strong>

                                        <div class="text-muted small">
                                            <?= e(
                                                (string) (
                                                    $request[
                                                        "requestor_email"
                                                    ] ?? ""
                                                )
                                            ) ?>
                                        </div>

                                    </div>

                                </div>

                                <div class="col-md-4">

                                    <div class="detail-box">

                                        <div class="detail-label">
                                            Department
                                        </div>

                                        <?= e(
                                            (string) (
                                                $request[
                                                    "department"
                                                ] ?? ""
                                            )
                                        ) ?>

                                    </div>

                                </div>

                                <div class="col-md-4">

                                    <div class="detail-box">

                                        <div class="detail-label">
                                            Date Requested
                                        </div>

                                        <?= e(
                                            (string) (
                                                $request[
                                                    "date_request"
                                                ] ?? ""
                                            )
                                        ) ?>

                                    </div>

                                </div>

                                <div class="col-md-4">

                                    <div class="detail-box">

                                        <div class="detail-label">
                                            Completion Date
                                        </div>

                                        <?= e(
                                            (string) (
                                                $request[
                                                    "date_completion"
                                                ]
                                                ?: "Not specified"
                                            )
                                        ) ?>

                                    </div>

                                </div>

                            </div>

                        </div>

                    </section>

                    <!-- STAFF / SUPERVISOR NOTIFICATION PANEL -->
                    <?php if ($canNotifyRequestor && !$isPubMode): ?>

                        <section class="survey-card">

                            <div class="card-body">

                                <div class="section-heading">

                                    <div
                                        class="section-heading-icon"
                                    >
                                        <i class="bi bi-bell"></i>
                                    </div>

                                    <div>

                                        <h3>
                                            Notify Requestor
                                        </h3>

                                        <p>
                                            Send an in-system
                                            notification containing the
                                            survey link.
                                        </p>

                                    </div>

                                </div>

                                <?php if (
                                    !in_array(
                                        $currentStatus,
                                        $evaluationStatuses,
                                        true
                                    )
                                ): ?>

                                    <div
                                        class="alert alert-warning mb-0"
                                    >

                                        The job request must be
                                        <strong>Completed</strong>
                                        before the client experience
                                        survey can be requested.

                                    </div>

                                <?php elseif (
                                    $surveySubmitted
                                ): ?>

                                    <div
                                        class="alert alert-success mb-0"
                                    >

                                        <i
                                            class="bi bi-check-circle me-1"
                                        ></i>

                                        The requestor has already
                                        submitted this survey.

                                    </div>

                                <?php else: ?>

                                    <form method="POST">

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e(
                                                $csrfToken
                                            ) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="request_id"
                                            value="<?= (int) $requestId ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="notify_requestor"
                                        >

                                        <div class="mb-3">

                                            <label
                                                class="form-label"
                                            >
                                                Notification Message
                                            </label>

                                            <textarea
                                                class="form-control"
                                                name="notification_message"
                                                rows="4"
                                                required
                                            ><?= e(
                                                $defaultNotification
                                            ) ?></textarea>

                                        </div>

                                        <button
                                            type="submit"
                                            class="btn btn-survey"
                                        >
                                            <i
                                                class="bi bi-send me-1"
                                            ></i>
                                            Send Survey Notification
                                        </button>

                                    </form>

                                <?php endif; ?>

                            </div>

                        </section>

                    <?php endif; ?>

                    <!-- REQUESTOR SURVEY -->
                    <?php if ($isRequestOwner): ?>

                        <?php if (
                            !$surveySubmitted
                            && !$canSubmitSurvey
                        ): ?>

                            <section class="survey-card">

                                <div class="card-body">

                                    <div
                                        class="alert alert-warning mb-0"
                                    >

                                        <i
                                            class="bi bi-hourglass-split me-1"
                                        ></i>

                                        This survey becomes available
                                        <?php if ($isPubMode): ?>
                                        when the publication is marked
                                        <strong>Published</strong>.
                                        <?php else: ?>
                                        when the job request is marked
                                        <strong>Completed</strong>.
                                        <?php endif; ?>

                                    </div>

                                </div>

                            </section>

                        <?php elseif (!$surveySubmitted): ?>

                            <form method="POST">

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e($csrfToken) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="request_id"
                                    value="<?= (int) $requestId ?>"
                                >

                                <?php if ($isPubMode): ?>

                                <input
                                    type="hidden"
                                    name="publication_kind"
                                    value="<?= e($pubKind) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="publication_id"
                                    value="<?= (int) $pubId ?>"
                                >

                                <?php endif; ?>

                                <input
                                    type="hidden"
                                    name="action"
                                    value="submit_survey"
                                >

                                <!-- PRIVACY -->
                                <section class="survey-card">

                                    <div class="card-body">

                                        <div class="privacy-note">

                                            Your answers will be used
                                            to improve PHU services and
                                            will be handled
                                            confidentially in
                                            accordance with the Data
                                            Privacy Act of 2012.

                                        </div>

                                    </div>

                                </section>

                                <!-- RESPONDENT DETAILS -->
                                <section class="survey-card">

                                    <div class="card-body">

                                        <div
                                            class="section-heading"
                                        >

                                            <div
                                                class="section-heading-icon"
                                            >
                                                <i
                                                    class="bi bi-person-vcard"
                                                ></i>
                                            </div>

                                            <div>

                                                <h3>
                                                    Respondent Details
                                                </h3>

                                                <p>
                                                    Provide the visit
                                                    information connected
                                                    to the service.
                                                </p>

                                            </div>

                                        </div>

                                        <div class="row g-3">

                                            <div class="col-md-3">

                                                <label
                                                    class="form-label"
                                                >
                                                    Age *
                                                </label>

                                                <input
                                                    type="number"
                                                    min="1"
                                                    max="120"
                                                    class="form-control"
                                                    name="age"
                                                    required
                                                >

                                            </div>

                                            <div class="col-md-3">

                                                <label
                                                    class="form-label"
                                                >
                                                    Sex *
                                                </label>

                                                <select
                                                    class="form-select"
                                                    name="sex"
                                                    required
                                                >
                                                    <option value="">
                                                        Select
                                                    </option>
                                                    <option value="Female">
                                                        Female
                                                    </option>
                                                    <option value="Male">
                                                        Male
                                                    </option>
                                                    <option
                                                        value="Prefer not to say"
                                                    >
                                                        Prefer not to say
                                                    </option>
                                                </select>

                                            </div>

                                            <div class="col-md-3">

                                                <label
                                                    class="form-label"
                                                >
                                                    Date of Visit *
                                                </label>

                                                <input
                                                    type="date"
                                                    class="form-control"
                                                    name="date_of_visit"
                                                    value="<?= e(
                                                        date("Y-m-d")
                                                    ) ?>"
                                                    required
                                                >

                                            </div>

                                            <div class="col-md-3">

                                                <label
                                                    class="form-label"
                                                >
                                                    Time of Visit
                                                </label>

                                                <input
                                                    type="time"
                                                    class="form-control"
                                                    name="time_of_visit"
                                                >

                                            </div>

                                            <div class="col-12">

                                                <label
                                                    class="form-label"
                                                >
                                                    Department /
                                                    Office / Unit
                                                    Visited *
                                                </label>

                                                <input
                                                    class="form-control"
                                                    name="department_visited"
                                                    value="<?= e(
                                                        (string) (
                                                            $request[
                                                                "department"
                                                            ] ?? ""
                                                        )
                                                    ) ?>"
                                                    required
                                                >

                                            </div>

                                        </div>

                                    </div>

                                </section>

                                <!-- SERVICE TYPE -->
                                <section class="survey-card">

                                    <div class="card-body">

                                        <div
                                            class="section-heading"
                                        >

                                            <div
                                                class="section-heading-icon"
                                            >
                                                <i
                                                    class="bi bi-ui-checks-grid"
                                                ></i>
                                            </div>

                                            <div>

                                                <h3>
                                                    A. Type of Service
                                                    Requested
                                                </h3>

                                                <p>
                                                    Select all services
                                                    that apply.
                                                </p>

                                            </div>

                                        </div>

                                        <div class="service-grid">

                                            <?php foreach (
                                                $serviceOptions
                                                as $service
                                            ): ?>

                                                <label
                                                    class="service-option form-check"
                                                >

                                                    <input
                                                        class="form-check-input service-check"
                                                        type="checkbox"
                                                        name="service_types[]"
                                                        value="<?= e(
                                                            $service
                                                        ) ?>"
                                                        data-other="<?= $service === "Others"
                                                            ? "1"
                                                            : "0"
                                                        ?>"
                                                    >

                                                    <span
                                                        class="form-check-label"
                                                    >
                                                        <?= e(
                                                            $service
                                                        ) ?>
                                                    </span>

                                                </label>

                                            <?php endforeach; ?>

                                        </div>

                                        <div
                                            id="otherServiceField"
                                            class="mt-3 d-none"
                                        >

                                            <label
                                                class="form-label"
                                            >
                                                Specify Other Service
                                            </label>

                                            <input
                                                id="serviceOther"
                                                class="form-control"
                                                name="service_other"
                                                disabled
                                            >

                                        </div>

                                    </div>

                                </section>

                                <!-- RATINGS -->
                                <section class="survey-card">

                                    <div class="card-body">

                                        <div
                                            class="section-heading"
                                        >

                                            <div
                                                class="section-heading-icon"
                                            >
                                                <i
                                                    class="bi bi-star"
                                                ></i>
                                            </div>

                                            <div>

                                                <h3>
                                                    B. Service Evaluation
                                                </h3>

                                                <p>
                                                    Rate every criterion
                                                    from 5 to 1.
                                                </p>

                                            </div>

                                        </div>

                                        <div
                                            class="table-responsive"
                                        >

                                            <table
                                                class="table table-bordered rating-table"
                                            >

                                                <thead
                                                    class="table-light"
                                                >
                                                    <tr>
                                                        <th>
                                                            Criteria
                                                        </th>
                                                        <th>
                                                            5<br>
                                                            <small>
                                                                Very
                                                                Satisfied
                                                            </small>
                                                        </th>
                                                        <th>
                                                            4<br>
                                                            <small>
                                                                Satisfied
                                                            </small>
                                                        </th>
                                                        <th>
                                                            3<br>
                                                            <small>
                                                                Neutral
                                                            </small>
                                                        </th>
                                                        <th>
                                                            2<br>
                                                            <small>
                                                                Dissatisfied
                                                            </small>
                                                        </th>
                                                        <th>
                                                            1<br>
                                                            <small>
                                                                Very
                                                                Dissatisfied
                                                            </small>
                                                        </th>
                                                    </tr>
                                                </thead>

                                                <tbody>

                                                    <?php
                                                    $criteria = [
                                                        "timeliness" =>
                                                            "Timeliness of Service",

                                                        "responsiveness" =>
                                                            "Responsiveness to Request",

                                                        "quality_output" =>
                                                            "Quality of Output",

                                                        "professionalism" =>
                                                            "Professionalism of Staff",

                                                        "communication" =>
                                                            "Communication and Coordination",

                                                        "service_met_needs" =>
                                                            "Service Met Your Needs"
                                                    ];
                                                    ?>

                                                    <?php foreach (
                                                        $criteria
                                                        as $field =>
                                                            $label
                                                    ): ?>

                                                        <tr>

                                                            <td>
                                                                <?= e(
                                                                    $label
                                                                ) ?>
                                                            </td>

                                                            <?php for (
                                                                $rating = 5;
                                                                $rating >= 1;
                                                                $rating--
                                                            ): ?>

                                                                <td>
                                                                    <input
                                                                        class="form-check-input rating-choice"
                                                                        type="radio"
                                                                        name="<?= e(
                                                                            $field
                                                                        ) ?>"
                                                                        value="<?= $rating ?>"
                                                                        required
                                                                    >
                                                                </td>

                                                            <?php endfor; ?>

                                                        </tr>

                                                    <?php endforeach; ?>

                                                </tbody>

                                            </table>

                                        </div>

                                    </div>

                                </section>

                                <!-- OVERALL AND OUTCOME -->
                                <section class="survey-card">

                                    <div class="card-body">

                                        <div
                                            class="section-heading"
                                        >

                                            <div
                                                class="section-heading-icon"
                                            >
                                                <i
                                                    class="bi bi-emoji-smile"
                                                ></i>
                                            </div>

                                            <div>

                                                <h3>
                                                    C. Overall Satisfaction
                                                    and D. Outcome
                                                </h3>

                                                <p>
                                                    Give your overall
                                                    assessment of the
                                                    service.
                                                </p>

                                            </div>

                                        </div>

                                        <div class="mb-4">

                                            <label
                                                class="form-label d-block"
                                            >
                                                How satisfied are you
                                                with the service
                                                provided by the Public
                                                Health Unit? *
                                            </label>

                                            <div
                                                class="d-flex flex-wrap gap-3"
                                            >

                                                <?php for (
                                                    $rating = 5;
                                                    $rating >= 1;
                                                    $rating--
                                                ): ?>

                                                    <label
                                                        class="form-check"
                                                    >

                                                        <input
                                                            class="form-check-input"
                                                            type="radio"
                                                            name="overall_satisfaction"
                                                            value="<?= $rating ?>"
                                                            required
                                                        >

                                                        <span
                                                            class="form-check-label"
                                                        >
                                                            <?= $rating ?>
                                                        </span>

                                                    </label>

                                                <?php endfor; ?>

                                            </div>

                                            <div
                                                class="form-text"
                                            >
                                                5 – Very Satisfied;
                                                1 – Very Dissatisfied
                                            </div>

                                        </div>

                                        <div>

                                            <label
                                                class="form-label d-block"
                                            >
                                                Did the service help
                                                your office achieve its
                                                intended objective? *
                                            </label>

                                            <div
                                                class="d-flex flex-wrap gap-3"
                                            >

                                                <?php foreach (
                                                    [
                                                        "Yes",
                                                        "Partially",
                                                        "No"
                                                    ] as $outcome
                                                ): ?>

                                                    <label
                                                        class="form-check"
                                                    >

                                                        <input
                                                            class="form-check-input"
                                                            type="radio"
                                                            name="outcome_assessment"
                                                            value="<?= e(
                                                                $outcome
                                                            ) ?>"
                                                            required
                                                        >

                                                        <span
                                                            class="form-check-label"
                                                        >
                                                            <?= e(
                                                                $outcome
                                                            ) ?>
                                                        </span>

                                                    </label>

                                                <?php endforeach; ?>

                                            </div>

                                        </div>

                                    </div>

                                </section>

                                <!-- COMMENTS -->
                                <section class="survey-card">

                                    <div class="card-body">

                                        <div
                                            class="section-heading"
                                        >

                                            <div
                                                class="section-heading-icon"
                                            >
                                                <i
                                                    class="bi bi-chat-left-text"
                                                ></i>
                                            </div>

                                            <div>

                                                <h3>
                                                    E. Comments and
                                                    Suggestions
                                                </h3>

                                                <p>
                                                    Tell us what worked
                                                    well and what can be
                                                    improved.
                                                </p>

                                            </div>

                                        </div>

                                        <div class="row g-3">

                                            <div class="col-12">

                                                <label
                                                    class="form-label"
                                                >
                                                    What did PHU do
                                                    well?
                                                </label>

                                                <textarea
                                                    class="form-control"
                                                    name="phu_did_well"
                                                    rows="4"
                                                ></textarea>

                                            </div>

                                            <div class="col-12">

                                                <label
                                                    class="form-label"
                                                >
                                                    What can PHU
                                                    improve?
                                                </label>

                                                <textarea
                                                    class="form-control"
                                                    name="phu_improve"
                                                    rows="4"
                                                ></textarea>

                                            </div>

                                        </div>

                                    </div>

                                </section>

                                <div
                                    class="d-flex justify-content-end gap-2 my-4"
                                >

                                    <a
                                        class="btn btn-outline-secondary"
                                        href="<?= e(
                                            BASE_URL
                                        ) ?>/user/my_requests.php"
                                    >
                                        Back to My Requests
                                    </a>

                                    <button
                                        type="submit"
                                        class="btn btn-survey"
                                    >
                                        <i
                                            class="bi bi-send-check me-1"
                                        ></i>
                                        Submit Survey
                                    </button>

                                </div>

                            </form>

                        <?php endif; ?>

                    <?php endif; ?>

                    <!-- REQUESTOR-ONLY SURVEY RESULT -->
                    <?php if (
                        $surveySubmitted
                        && $isRequestOwner
                    ): ?>

                        <section class="survey-card mb-4">

                            <div class="card-body">

                                <div class="section-heading">

                                    <div
                                        class="section-heading-icon"
                                    >
                                        <i
                                            class="bi bi-clipboard2-check"
                                        ></i>
                                    </div>

                                    <div>

                                        <h3>
                                            Submitted Survey Result
                                        </h3>

                                        <p>
                                            Submitted on
                                            <?= e(
                                                (string) surveyValue(
                                                    $survey,
                                                    "submitted_at"
                                                )
                                            ) ?>
                                        </p>

                                    </div>

                                </div>

                                <div class="row g-3 mb-4">

                                    <div class="col-md-3">

                                        <div class="detail-box">

                                            <div
                                                class="detail-label"
                                            >
                                                Age
                                            </div>

                                            <?= (int) surveyValue(
                                                $survey,
                                                "age",
                                                0
                                            ) ?>

                                        </div>

                                    </div>

                                    <div class="col-md-3">

                                        <div class="detail-box">

                                            <div
                                                class="detail-label"
                                            >
                                                Sex
                                            </div>

                                            <?= e(
                                                (string) surveyValue(
                                                    $survey,
                                                    "sex"
                                                )
                                            ) ?>

                                        </div>

                                    </div>

                                    <div class="col-md-3">

                                        <div class="detail-box">

                                            <div
                                                class="detail-label"
                                            >
                                                Date of Visit
                                            </div>

                                            <?= e(
                                                (string) surveyValue(
                                                    $survey,
                                                    "date_of_visit"
                                                )
                                            ) ?>

                                        </div>

                                    </div>

                                    <div class="col-md-3">

                                        <div class="detail-box">

                                            <div
                                                class="detail-label"
                                            >
                                                Overall Rating
                                            </div>

                                            <span
                                                class="result-rating"
                                            >
                                                <?= (int) surveyValue(
                                                    $survey,
                                                    "overall_satisfaction",
                                                    0
                                                ) ?>
                                                / 5
                                            </span>

                                        </div>

                                    </div>

                                </div>

                                <div
                                    class="table-responsive mb-4"
                                >

                                    <table
                                        class="table table-bordered"
                                    >

                                        <thead
                                            class="table-light"
                                        >
                                            <tr>
                                                <th>Criterion</th>
                                                <th
                                                    class="text-center"
                                                >
                                                    Rating
                                                </th>
                                            </tr>
                                        </thead>

                                        <tbody>

                                            <?php
                                            $resultCriteria = [
                                                "timeliness" =>
                                                    "Timeliness of Service",

                                                "responsiveness" =>
                                                    "Responsiveness to Request",

                                                "quality_output" =>
                                                    "Quality of Output",

                                                "professionalism" =>
                                                    "Professionalism of Staff",

                                                "communication" =>
                                                    "Communication and Coordination",

                                                "service_met_needs" =>
                                                    "Service Met Your Needs"
                                            ];
                                            ?>

                                            <?php foreach (
                                                $resultCriteria
                                                as $field => $label
                                            ): ?>

                                                <tr>

                                                    <td>
                                                        <?= e($label) ?>
                                                    </td>

                                                    <td
                                                        class="text-center"
                                                    >

                                                        <span
                                                            class="result-rating"
                                                        >
                                                            <?= (int) surveyValue(
                                                                $survey,
                                                                $field,
                                                                0
                                                            ) ?>
                                                        </span>

                                                    </td>

                                                </tr>

                                            <?php endforeach; ?>

                                        </tbody>

                                    </table>

                                </div>

                                <div class="row g-3">

                                    <div class="col-md-6">

                                        <div class="detail-box">

                                            <div
                                                class="detail-label"
                                            >
                                                Services Requested
                                            </div>

                                            <?= e(
                                                (string) surveyValue(
                                                    $survey,
                                                    "service_types"
                                                )
                                            ) ?>

                                            <?php if (
                                                surveyValue(
                                                    $survey,
                                                    "service_other"
                                                )
                                            ): ?>

                                                <div
                                                    class="mt-1 text-muted"
                                                >
                                                    Other:
                                                    <?= e(
                                                        (string) surveyValue(
                                                            $survey,
                                                            "service_other"
                                                        )
                                                    ) ?>
                                                </div>

                                            <?php endif; ?>

                                        </div>

                                    </div>

                                    <div class="col-md-6">

                                        <div class="detail-box">

                                            <div
                                                class="detail-label"
                                            >
                                                Outcome Assessment
                                            </div>

                                            <?= e(
                                                (string) surveyValue(
                                                    $survey,
                                                    "outcome_assessment"
                                                )
                                            ) ?>

                                        </div>

                                    </div>

                                    <div class="col-12">

                                        <div class="detail-box">

                                            <div
                                                class="detail-label"
                                            >
                                                What PHU Did Well
                                            </div>

                                            <?= nl2br(
                                                e(
                                                    (string) (
                                                        surveyValue(
                                                            $survey,
                                                            "phu_did_well"
                                                        )
                                                        ?: "No response"
                                                    )
                                                )
                                            ) ?>

                                        </div>

                                    </div>

                                    <div class="col-12">

                                        <div class="detail-box">

                                            <div
                                                class="detail-label"
                                            >
                                                What PHU Can Improve
                                            </div>

                                            <?= nl2br(
                                                e(
                                                    (string) (
                                                        surveyValue(
                                                            $survey,
                                                            "phu_improve"
                                                        )
                                                        ?: "No response"
                                                    )
                                                )
                                            ) ?>

                                        </div>

                                    </div>

                                </div>

                            </div>

                        </section>

                    <?php endif; ?>

                </div>

            </div>

        </main>

    </div>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
></script>

<script>
document.addEventListener(
    "DOMContentLoaded",
    function () {

        const otherCheckbox =
            document.querySelector(
                '.service-check[data-other="1"]'
            );

        const otherContainer =
            document.getElementById(
                "otherServiceField"
            );

        const otherField =
            document.getElementById(
                "serviceOther"
            );

        if (
            otherCheckbox
            && otherContainer
            && otherField
        ) {
            function updateOtherService() {
                const enabled =
                    otherCheckbox.checked;

                otherContainer.classList.toggle(
                    "d-none",
                    !enabled
                );

                otherField.disabled = !enabled;
                otherField.required = enabled;

                if (!enabled) {
                    otherField.value = "";
                }
            }

            otherCheckbox.addEventListener(
                "change",
                updateOtherService
            );

            updateOtherService();
        }

        const surveyForm =
            document.querySelector(
                'form input[value="submit_survey"]'
            )?.closest("form");

        if (surveyForm) {
            surveyForm.addEventListener(
                "submit",
                function (event) {

                    const selectedServices =
                        document.querySelectorAll(
                            ".service-check:checked"
                        );

                    if (!selectedServices.length) {
                        event.preventDefault();

                        alert(
                            "Please select at least one type of service requested."
                        );
                    }
                }
            );
        }
    }
);
</script>

</body>
</html>
