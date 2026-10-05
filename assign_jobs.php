<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HPU WORKBENCH - UPGRADED ASSIGN JOBS
|--------------------------------------------------------------------------
| Drop-in replacement:
|   C:\laragon\www\jobrequest_phu\assign_jobs.php
|
| Access:
|   Supervisor / Admin only
|
| Features:
|   - Newest requests first by default
|   - Request-type color coding
|   - Search + type + assignee + status + priority + deadline filters
|   - Staff workload summary
|   - Due today / due soon / overdue indicators
|   - Assign / reassign / unassign
|   - Priority + assignment instructions
|   - Assignment history through action_log when available
|   - Assignment notification only when assignee changes
|--------------------------------------------------------------------------
*/

include __DIR__ . "/includes/config.php";
include __DIR__ . "/includes/auth.php";
include __DIR__ . "/includes/helpers.php";
require_once __DIR__ . "/includes/module_access.php";

requireRole(["supervisor", "admin"]);
requireModule($conn, "assign_jobs");

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function assignmentColumnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?"
    );

    $stmt->bind_param(
        "ss",
        $table,
        $column
    );

    $stmt->execute();

    $row = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    return (int) ($row["total"] ?? 0) > 0;
}

function assignmentTableExists(
    mysqli $conn,
    string $table
): bool {
    $safe =
        $conn->real_escape_string(
            $table
        );

    $result =
        $conn->query(
            "SHOW TABLES LIKE '{$safe}'"
        );

    return
        $result
        &&
        $result->num_rows > 0;
}

function assignmentRequestTypeClass(
    string $type
): string {
    return match ($type) {
        "Graphic Design" =>
            "request-type-graphic",

        "Video Production" =>
            "request-type-video",

        "Photo/Video Coverage" =>
            "request-type-coverage",

        "IEC Materials" =>
            "request-type-iec",

        default =>
            "request-type-other"
    };
}

function assignmentRequestTypeIcon(
    string $type
): string {
    return match ($type) {
        "Graphic Design" =>
            "bi-palette",

        "Video Production" =>
            "bi-camera-reels",

        "Photo/Video Coverage" =>
            "bi-camera",

        "IEC Materials" =>
            "bi-file-earmark-richtext",

        default =>
            "bi-tag"
    };
}

function assignmentRowClass(
    array $requestTypes
): string {
    $primary =
        (string) (
            $requestTypes[0]
            ?? ""
        );

    return match ($primary) {
        "Graphic Design" =>
            "request-row-graphic",

        "Video Production" =>
            "request-row-video",

        "Photo/Video Coverage" =>
            "request-row-coverage",

        "IEC Materials" =>
            "request-row-iec",

        default =>
            "request-row-other"
    };
}

function assignmentStatusClass(
    string $status
): string {
    return match ($status) {
        "Pending Review" =>
            "status-pending",

        "Under Review" =>
            "status-review",

        "Approved" =>
            "status-approved",

        "For Revision" =>
            "status-revision",

        "Completed" =>
            "status-completed",

        "For Evaluation" =>
            "status-evaluation",

        "Closed" =>
            "status-closed",

        "Disapproved" =>
            "status-disapproved",

        default =>
            "status-default"
    };
}

function assignmentPriorityClass(
    string $priority
): string {
    return match ($priority) {
        "Urgent" =>
            "priority-urgent",

        "High" =>
            "priority-high",

        "Low" =>
            "priority-low",

        default =>
            "priority-normal"
    };
}

function assignmentDate(
    ?string $value,
    string $format = "M d, Y"
): string {
    if (!$value) {
        return "—";
    }

    try {
        return
            (new DateTime($value))
                ->format($format);
    } catch (Throwable $e) {
        return "—";
    }
}

function assignmentDaysFromToday(
    ?string $date
): ?int {
    if (!$date) {
        return null;
    }

    try {
        $today =
            new DateTime("today");

        $target =
            new DateTime($date);

        $target->setTime(
            0,
            0,
            0
        );

        return (int) $today
            ->diff($target)
            ->format("%r%a");

    } catch (Throwable $e) {
        return null;
    }
}

function assignmentDeadlineMeta(
    ?string $date,
    string $status
): array {
    $closedStatuses = [
        "Completed",
        "For Evaluation",
        "Closed",
        "Disapproved"
    ];

    if (
        !$date
        ||
        in_array(
            $status,
            $closedStatuses,
            true
        )
    ) {
        return [
            "class" => "deadline-normal",
            "label" => $date
                ? "Due " . assignmentDate($date)
                : "No deadline",
            "icon" => "bi-calendar3"
        ];
    }

    $days =
        assignmentDaysFromToday(
            $date
        );

    if ($days === null) {
        return [
            "class" => "deadline-normal",
            "label" => assignmentDate($date),
            "icon" => "bi-calendar3"
        ];
    }

    if ($days < 0) {
        return [
            "class" => "deadline-overdue",
            "label" =>
                abs($days)
                . " day"
                . (abs($days) === 1 ? "" : "s")
                . " overdue",

            "icon" =>
                "bi-exclamation-octagon"
        ];
    }

    if ($days === 0) {
        return [
            "class" => "deadline-today",
            "label" => "Due today",
            "icon" => "bi-alarm"
        ];
    }

    if ($days <= 3) {
        return [
            "class" => "deadline-soon",
            "label" =>
                "Due in "
                . $days
                . " day"
                . ($days === 1 ? "" : "s"),

            "icon" =>
                "bi-clock-history"
        ];
    }

    return [
        "class" => "deadline-normal",
        "label" =>
            "Due "
            . assignmentDate($date),

        "icon" =>
            "bi-calendar3"
    ];
}

function assignmentAgeLabel(
    ?string $dateRequest
): string {
    if (!$dateRequest) {
        return "";
    }

    $days =
        assignmentDaysFromToday(
            $dateRequest
        );

    if ($days === null) {
        return "";
    }

    /*
     * date_request is normally today or in the past.
     * Convert negative difference into age.
     */
    $age = abs($days);

    if ($age === 0) {
        return "New today";
    }

    if ($age === 1) {
        return "1 day old";
    }

    return
        $age
        . " days old";
}

function assignmentSafeReturnQuery(
    string $query
): string {
    parse_str(
        $query,
        $data
    );

    $allowedKeys = [
        "q",
        "request_type",
        "assignment",
        "assigned_to",
        "status",
        "priority",
        "deadline",
        "sort",
        "page"
    ];

    $safe = [];

    foreach ($allowedKeys as $key) {
        if (
            isset($data[$key])
            &&
            !is_array($data[$key])
        ) {
            $safe[$key] =
                mb_substr(
                    (string) $data[$key],
                    0,
                    150
                );
        }
    }

    return
        http_build_query(
            $safe
        );
}

function assignBuildQuery(
    array $overrides = []
): string {
    $allowed = [
        "q",
        "request_type",
        "assignment",
        "assigned_to",
        "status",
        "priority",
        "deadline",
        "sort",
        "page"
    ];

    $data = [];

    foreach ($allowed as $key) {
        if (
            isset($_GET[$key])
            &&
            !is_array($_GET[$key])
        ) {
            $value =
                trim(
                    (string) $_GET[$key]
                );

            if ($value === "") {
                continue;
            }

            if (
                $key === "page"
                &&
                (int) $value <= 1
            ) {
                continue;
            }

            if (
                $key === "assigned_to"
                &&
                (int) $value <= 0
            ) {
                continue;
            }

            $data[$key] = $value;
        }
    }

    foreach ($overrides as $key => $value) {
        if (
            $value === null
            ||
            $value === ""
        ) {
            unset($data[$key]);
        } else {
            $data[$key] =
                (string) $value;
        }
    }

    $query =
        http_build_query($data);

    return
        BASE_URL
        . "/assign_jobs.php"
        . (
            $query !== ""
            ? "?" . $query
            : ""
        );
}

/*
|--------------------------------------------------------------------------
| Ensure Assignment Columns
|--------------------------------------------------------------------------
*/

$requiredColumns = [
    "assigned_to" =>
        "INT NULL",

    "assigned_at" =>
        "DATETIME NULL",

    "assignment_notes" =>
        "TEXT NULL",

    "priority" =>
        "ENUM('Low','Normal','High','Urgent')
         NOT NULL DEFAULT 'Normal'",

    "archived" =>
        "TINYINT(1)
         NOT NULL DEFAULT 0"
];

foreach (
    $requiredColumns
    as $column => $definition
) {
    if (
        !assignmentColumnExists(
            $conn,
            "job_requests",
            $column
        )
    ) {
        $conn->query(
            "ALTER TABLE job_requests
             ADD COLUMN `{$column}`
             {$definition}"
        );
    }
}

$hasActionLog =
    assignmentColumnExists(
        $conn,
        "job_requests",
        "action_log"
    );

$hasNotifications =
    assignmentTableExists(
        $conn,
        "notifications"
    );

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (
    empty(
    $_SESSION["assignment_csrf"]
)
) {
    $_SESSION["assignment_csrf"] =
        bin2hex(
            random_bytes(32)
        );
}

$csrf =
    (string) 
    $_SESSION["assignment_csrf"];

/*
|--------------------------------------------------------------------------
| Flash
|--------------------------------------------------------------------------
*/

$flash =
    $_SESSION["assignment_flash"]
    ?? null;

unset(
    $_SESSION["assignment_flash"]
);

/*
|--------------------------------------------------------------------------
| Save Assignment
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"]
    === "POST"
) {
    if (
        empty($_POST["csrf_token"])
        ||
        !hash_equals(
            $csrf,
            (string) 
            $_POST["csrf_token"]
        )
    ) {
        $_SESSION["assignment_flash"] = [
            "type" => "danger",
            "message" =>
                "Security token expired. "
                . "Please try again."
        ];

        header(
            "Location: "
            . BASE_URL
            . "/assign_jobs.php"
        );

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Bulk Save (one submit for many selected rows)
    |--------------------------------------------------------------------------
    */

    if (
        isset($_POST["bulk_save"])
    ) {
        $bulkReturnQuery =
            assignmentSafeReturnQuery(
                (string) (
                    $_POST["return_query"]
                    ?? ""
                )
            );

        $bulkRedirect =
            BASE_URL
            . "/assign_jobs.php"
            . (
                $bulkReturnQuery !== ""
                ? "?" . $bulkReturnQuery
                : ""
            );

        $bulkIds = [];

        foreach (
            explode(
                ",",
                (string) (
                    $_POST["bulk_ids"]
                    ?? ""
                )
            )
            as $bulkPart
        ) {
            $bulkPart =
                trim($bulkPart);

            if (
                ctype_digit($bulkPart)
                &&
                (int) $bulkPart > 0
            ) {
                $bulkIds[] =
                    (int) $bulkPart;
            }
        }

        $bulkIds =
            array_slice(
                array_values(
                    array_unique($bulkIds)
                ),
                0,
                100
            );

        $bulkAssigneeRaw =
            trim(
                (string) (
                    $_POST["assigned_to"]
                    ?? ""
                )
            );

        $bulkPriority =
            trim(
                (string) (
                    $_POST["priority"]
                    ?? ""
                )
            );

        $bulkChangeAssignee =
            $bulkAssigneeRaw !== "";

        $bulkUnassign =
            $bulkAssigneeRaw === "0";

        $bulkAssignee =
            $bulkChangeAssignee
            ? (int) $bulkAssigneeRaw
            : 0;

        $bulkChangePriority =
            in_array(
                $bulkPriority,
                [
                    "Low",
                    "Normal",
                    "High",
                    "Urgent"
                ],
                true
            );

        if (
            !$bulkIds
            ||
            (
                !$bulkChangeAssignee
                &&
                !$bulkChangePriority
            )
        ) {
            $_SESSION["assignment_flash"] = [
                "type" => "info",
                "message" =>
                    "Nothing to apply. "
                    . "Select rows and pick "
                    . "an assignee or priority."
            ];

            header(
                "Location: "
                . $bulkRedirect
            );

            exit;
        }

        $bulkAssigneeName = null;

        if (
            $bulkChangeAssignee
            &&
            !$bulkUnassign
        ) {
            $bulkUserStmt =
                $conn->prepare(
                    "SELECT
                        id,
                        name
                     FROM users
                     WHERE id = ?
                       AND role IN (
                            'staff',
                            'supervisor',
                            'admin'
                       )
                       AND status = 'Active'
                     LIMIT 1"
                );

            $bulkUserStmt->bind_param(
                "i",
                $bulkAssignee
            );

            $bulkUserStmt->execute();

            $bulkUser =
                $bulkUserStmt
                    ->get_result()
                    ->fetch_assoc();

            $bulkUserStmt->close();

            if (!$bulkUser) {
                $_SESSION["assignment_flash"] = [
                    "type" => "danger",
                    "message" =>
                        "Selected HPU personnel "
                        . "account is unavailable."
                ];

                header(
                    "Location: "
                    . $bulkRedirect
                );

                exit;
            }

            $bulkAssigneeName =
                (string) $bulkUser["name"];
        }

        $bulkActor =
            (string) (
                $_SESSION["name"]
                ?? "System User"
            );

        $bulkTimestamp =
            date("Y-m-d H:i:s");

        $bulkUpdated = 0;
        $bulkFirstId = $bulkIds[0];

        try {
            $conn->begin_transaction();

            foreach (
                $bulkIds
                as $bulkId
            ) {
                $bulkFetch =
                    $conn->prepare(
                        "SELECT
                            id,
                            job_no,
                            project_title,
                            assigned_to,
                            priority
                         FROM job_requests
                         WHERE id = ?
                         LIMIT 1"
                    );

                $bulkFetch->bind_param(
                    "i",
                    $bulkId
                );

                $bulkFetch->execute();

                $bulkRow =
                    $bulkFetch
                        ->get_result()
                        ->fetch_assoc();

                $bulkFetch->close();

                if (!$bulkRow) {
                    continue;
                }

                $bulkOldAssigned =
                    (int) (
                        $bulkRow["assigned_to"]
                        ?? 0
                    );

                $bulkOldPriority =
                    (string) (
                        $bulkRow["priority"]
                        ?? "Normal"
                    );

                $bulkFinalAssignee =
                    $bulkChangeAssignee
                    ? (
                        $bulkUnassign
                        ? 0
                        : $bulkAssignee
                    )
                    : $bulkOldAssigned;

                $bulkFinalPriority =
                    $bulkChangePriority
                    ? $bulkPriority
                    : $bulkOldPriority;

                if (
                    $bulkFinalAssignee
                    ===
                    $bulkOldAssigned
                    &&
                    $bulkFinalPriority
                    ===
                    $bulkOldPriority
                ) {
                    continue;
                }

                if (
                    $bulkFinalAssignee > 0
                ) {
                    if (
                        $bulkOldAssigned <= 0
                    ) {
                        $bulkAction =
                            "Bulk assigned to "
                            . $bulkAssigneeName;
                    } elseif (
                        $bulkOldAssigned
                        !==
                        $bulkFinalAssignee
                    ) {
                        $bulkAction =
                            "Bulk reassigned to "
                            . $bulkAssigneeName;
                    } else {
                        $bulkAction =
                            "Bulk assignment details "
                            . "updated for "
                            . $bulkAssigneeName;
                    }

                    $bulkLog =
                        "\n"
                        . $bulkAction
                        . " by "
                        . $bulkActor
                        . " on "
                        . $bulkTimestamp
                        . ". Priority: "
                        . $bulkFinalPriority
                        . ".";

                    if ($hasActionLog) {
                        $bulkSave =
                            $conn->prepare(
                                "UPDATE job_requests
                                 SET
                                    assigned_to = ?,
                                    assigned_at =
                                        CASE
                                            WHEN assigned_to IS NULL
                                                 OR assigned_to <> ?
                                            THEN NOW()
                                            ELSE assigned_at
                                        END,
                                    priority = ?,
                                    action_log = CONCAT(
                                        COALESCE(action_log,''),
                                        ?
                                    )
                                 WHERE id = ?"
                            );

                        $bulkSave->bind_param(
                            "iissi",
                            $bulkFinalAssignee,
                            $bulkFinalAssignee,
                            $bulkFinalPriority,
                            $bulkLog,
                            $bulkId
                        );
                    } else {
                        $bulkSave =
                            $conn->prepare(
                                "UPDATE job_requests
                                 SET
                                    assigned_to = ?,
                                    assigned_at =
                                        CASE
                                            WHEN assigned_to IS NULL
                                                 OR assigned_to <> ?
                                            THEN NOW()
                                            ELSE assigned_at
                                        END,
                                    priority = ?
                                 WHERE id = ?"
                            );

                        $bulkSave->bind_param(
                            "iisi",
                            $bulkFinalAssignee,
                            $bulkFinalAssignee,
                            $bulkFinalPriority,
                            $bulkId
                        );
                    }

                    $bulkSave->execute();
                    $bulkSave->close();

                    if (
                        $hasNotifications
                        &&
                        $bulkFinalAssignee
                        !==
                        $bulkOldAssigned
                    ) {
                        $bulkTitle =
                            $bulkOldAssigned > 0
                            ? "Job Request Reassigned to You"
                            : "Job Request Assigned to You";

                        $bulkMessage =
                            $bulkRow["job_no"]
                            . " — "
                            . $bulkRow["project_title"]
                            . " has been assigned to you."
                            . " Priority: "
                            . $bulkFinalPriority
                            . ".";

                        $bulkType =
                            "assignment";

                        $bulkUrl =
                            BASE_URL
                            . "/my_assigned_jobs.php";

                        $bulkNotify =
                            $conn->prepare(
                                "INSERT INTO notifications
                                 (
                                    user_id,
                                    request_id,
                                    title,
                                    message,
                                    notification_type,
                                    action_url,
                                    is_read
                                 )
                                 VALUES (
                                    ?, ?, ?, ?, ?, ?, 0
                                 )"
                            );

                        $bulkNotify->bind_param(
                            "iissss",
                            $bulkFinalAssignee,
                            $bulkId,
                            $bulkTitle,
                            $bulkMessage,
                            $bulkType,
                            $bulkUrl
                        );

                        $bulkNotify->execute();
                        $bulkNotify->close();
                    }
                } else {
                    $bulkLog =
                        "\nBulk assignment removed by "
                        . $bulkActor
                        . " on "
                        . $bulkTimestamp
                        . ". Priority: "
                        . $bulkFinalPriority
                        . ".";

                    if ($hasActionLog) {
                        $bulkSave =
                            $conn->prepare(
                                "UPDATE job_requests
                                 SET
                                    assigned_to = NULL,
                                    assigned_at = NULL,
                                    assignment_notes = NULL,
                                    priority = ?,
                                    action_log = CONCAT(
                                        COALESCE(action_log,''),
                                        ?
                                    )
                                 WHERE id = ?"
                            );

                        $bulkSave->bind_param(
                            "ssi",
                            $bulkFinalPriority,
                            $bulkLog,
                            $bulkId
                        );
                    } else {
                        $bulkSave =
                            $conn->prepare(
                                "UPDATE job_requests
                                 SET
                                    assigned_to = NULL,
                                    assigned_at = NULL,
                                    assignment_notes = NULL,
                                    priority = ?
                                 WHERE id = ?"
                            );

                        $bulkSave->bind_param(
                            "si",
                            $bulkFinalPriority,
                            $bulkId
                        );
                    }

                    $bulkSave->execute();
                    $bulkSave->close();
                }

                $bulkUpdated++;
            }

            $conn->commit();
        } catch (Throwable $bulkError) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
            }

            error_log(
                "Bulk assignment failed: "
                . $bulkError->getMessage()
            );

            $_SESSION["assignment_flash"] = [
                "type" => "danger",
                "message" =>
                    "Bulk update failed. "
                    . "No changes were saved."
            ];

            header(
                "Location: "
                . $bulkRedirect
            );

            exit;
        }

        if ($bulkUpdated > 0) {
            $bulkSummary =
                "Bulk update applied to "
                . $bulkUpdated
                . " request"
                . (
                    $bulkUpdated === 1
                    ? ""
                    : "s"
                )
                . ".";

            if (
                $bulkChangeAssignee
                &&
                !$bulkUnassign
            ) {
                $bulkSummary .=
                    " Assignee: "
                    . $bulkAssigneeName
                    . ".";
            } elseif ($bulkUnassign) {
                $bulkSummary .=
                    " Assignments removed.";
            }

            if ($bulkChangePriority) {
                $bulkSummary .=
                    " Priority: "
                    . $bulkPriority
                    . ".";
            }

            $_SESSION["assignment_flash"] = [
                "type" => "success",
                "message" => $bulkSummary
            ];
        } else {
            $_SESSION["assignment_flash"] = [
                "type" => "info",
                "message" =>
                    "Selected rows already match "
                    . "the bulk values. "
                    . "No changes were made."
            ];
        }

        header(
            "Location: "
            . $bulkRedirect
            . "#req-"
            . $bulkFirstId
        );

        exit;
    }

    $requestId =
        (int) (
            $_POST["request_id"]
            ?? 0
        );

    $assignedTo =
        (int) (
            $_POST["assigned_to"]
            ?? 0
        );

    $priority =
        trim(
            (string) (
                $_POST["priority"]
                ?? "Normal"
            )
        );

    $notes =
        trim(
            (string) (
                $_POST["assignment_notes"]
                ?? ""
            )
        );

    $returnQuery =
        assignmentSafeReturnQuery(
            (string) (
                $_POST["return_query"]
                ?? ""
            )
        );

    $redirectUrl =
        BASE_URL
        . "/assign_jobs.php"
        . (
            $returnQuery !== ""
            ? "?" . $returnQuery
            : ""
        );

    if (
        !in_array(
            $priority,
            [
                "Low",
                "Normal",
                "High",
                "Urgent"
            ],
            true
        )
    ) {
        $priority =
            "Normal";
    }

    $requestStmt =
        $conn->prepare(
            "SELECT
                id,
                job_no,
                project_title,
                assigned_to,
                priority,
                assignment_notes
             FROM job_requests
             WHERE id = ?
             LIMIT 1"
        );

    $requestStmt->bind_param(
        "i",
        $requestId
    );

    $requestStmt->execute();

    $request =
        $requestStmt
            ->get_result()
            ->fetch_assoc();

    $requestStmt->close();

    if (!$request) {
        $_SESSION["assignment_flash"] = [
            "type" => "danger",
            "message" =>
                "Job request not found."
        ];

        header(
            "Location: "
            . $redirectUrl
        );

        exit;
    }

    $oldAssignedTo =
        (int) (
            $request["assigned_to"]
            ?? 0
        );

    $oldPriority =
        (string) (
            $request["priority"]
            ?? "Normal"
        );

    $oldNotes =
        trim(
            (string) (
                $request["assignment_notes"]
                ?? ""
            )
        );

    $assignedName = null;

    if ($assignedTo > 0) {
        $userStmt =
            $conn->prepare(
                "SELECT
                    id,
                    name,
                    role
                 FROM users
                 WHERE id = ?
                   AND role IN (
                        'staff',
                        'supervisor',
                        'admin'
                   )
                   AND status = 'Active'
                 LIMIT 1"
            );

        $userStmt->bind_param(
            "i",
            $assignedTo
        );

        $userStmt->execute();

        $assignedUser =
            $userStmt
                ->get_result()
                ->fetch_assoc();

        $userStmt->close();

        if (!$assignedUser) {
            $_SESSION["assignment_flash"] = [
                "type" => "danger",
                "message" =>
                    "Selected HPU personnel "
                    . "account is unavailable."
            ];

            header(
                "Location: "
                . $redirectUrl
            );

            exit;
        }

        $assignedName =
            (string) $assignedUser["name"];
    }

    $actor =
        (string) (
            $_SESSION["name"]
            ?? "System User"
        );

    $timestamp =
        date(
            "Y-m-d H:i:s"
        );

    $assignmentChanged =
        $oldAssignedTo
        !==
        $assignedTo;

    $detailsChanged =
        $oldPriority !== $priority
        ||
        $oldNotes !== $notes;

    if (
        !$assignmentChanged
        &&
        !$detailsChanged
    ) {
        $_SESSION["assignment_flash"] = [
            "type" => "info",
            "message" =>
                "No changes were made to "
                . $request["job_no"]
                . "."
        ];

        header(
            "Location: "
            . $redirectUrl
        );

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Build assignment action log
    |--------------------------------------------------------------------------
    */

    if ($assignedTo > 0) {
        if ($oldAssignedTo <= 0) {
            $actionText =
                "Assigned to "
                . $assignedName;
        } elseif (
            $oldAssignedTo
            !==
            $assignedTo
        ) {
            $actionText =
                "Reassigned to "
                . $assignedName;
        } else {
            $actionText =
                "Assignment details updated for "
                . $assignedName;
        }

        $logLine =
            "\n"
            . $actionText
            . " by "
            . $actor
            . " on "
            . $timestamp
            . ". Priority: "
            . $priority
            . ".";

        if ($notes !== "") {
            $logLine .=
                " Instructions: "
                . $notes;
        }

        /*
        |--------------------------------------------------------------------------
        | Save
        |--------------------------------------------------------------------------
        */

        if ($hasActionLog) {
            $stmt =
                $conn->prepare(
                    "UPDATE job_requests
                     SET
                        assigned_to = ?,
                        assigned_at =
                            CASE
                                WHEN assigned_to IS NULL
                                     OR assigned_to <> ?
                                THEN NOW()
                                ELSE assigned_at
                            END,
                        assignment_notes = ?,
                        priority = ?,
                        action_log = CONCAT(
                            COALESCE(action_log,''),
                            ?
                        )
                     WHERE id = ?"
                );

            $stmt->bind_param(
                "iisssi",
                $assignedTo,
                $assignedTo,
                $notes,
                $priority,
                $logLine,
                $requestId
            );
        } else {
            $stmt =
                $conn->prepare(
                    "UPDATE job_requests
                     SET
                        assigned_to = ?,
                        assigned_at =
                            CASE
                                WHEN assigned_to IS NULL
                                     OR assigned_to <> ?
                                THEN NOW()
                                ELSE assigned_at
                            END,
                        assignment_notes = ?,
                        priority = ?
                     WHERE id = ?"
                );

            $stmt->bind_param(
                "iissi",
                $assignedTo,
                $assignedTo,
                $notes,
                $priority,
                $requestId
            );
        }

        $stmt->execute();
        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | Notify only on new assignment / reassignment
        |--------------------------------------------------------------------------
        */

        if (
            $hasNotifications
            &&
            $assignmentChanged
        ) {
            $notificationTitle =
                $oldAssignedTo > 0
                ? "Job Request Reassigned to You"
                : "Job Request Assigned to You";

            $notificationMessage =
                $request["job_no"]
                . " — "
                . $request["project_title"]
                . " has been assigned to you."
                . " Priority: "
                . $priority
                . ".";

            if ($notes !== "") {
                $notificationMessage .=
                    " Instructions: "
                    . $notes;
            }

            $notificationType =
                "assignment";

            $actionUrl =
                BASE_URL
                . "/my_assigned_jobs.php";

            $notify =
                $conn->prepare(
                    "INSERT INTO notifications
                     (
                        user_id,
                        request_id,
                        title,
                        message,
                        notification_type,
                        action_url,
                        is_read
                     )
                     VALUES (
                        ?, ?, ?, ?, ?, ?, 0
                     )"
                );

            $notify->bind_param(
                "iissss",
                $assignedTo,
                $requestId,
                $notificationTitle,
                $notificationMessage,
                $notificationType,
                $actionUrl
            );

            $notify->execute();
            $notify->close();
        }

        $_SESSION["assignment_flash"] = [
            "type" => "success",
            "message" =>
                $request["job_no"]
                . (
                    $oldAssignedTo > 0
                    &&
                    $oldAssignedTo !== $assignedTo
                    ? " reassigned to "
                    : (
                        $assignmentChanged
                        ? " assigned to "
                        : " assignment updated for "
                    )
                )
                . $assignedName
                . "."
        ];

    } else {
        /*
        |--------------------------------------------------------------------------
        | Unassign
        |--------------------------------------------------------------------------
        */

        $logLine =
            "\nAssignment removed by "
            . $actor
            . " on "
            . $timestamp
            . ". Priority: "
            . $priority
            . ".";

        if ($hasActionLog) {
            $stmt =
                $conn->prepare(
                    "UPDATE job_requests
                     SET
                        assigned_to = NULL,
                        assigned_at = NULL,
                        assignment_notes = NULL,
                        priority = ?,
                        action_log = CONCAT(
                            COALESCE(action_log,''),
                            ?
                        )
                     WHERE id = ?"
                );

            $stmt->bind_param(
                "ssi",
                $priority,
                $logLine,
                $requestId
            );
        } else {
            $stmt =
                $conn->prepare(
                    "UPDATE job_requests
                     SET
                        assigned_to = NULL,
                        assigned_at = NULL,
                        assignment_notes = NULL,
                        priority = ?
                     WHERE id = ?"
                );

            $stmt->bind_param(
                "si",
                $priority,
                $requestId
            );
        }

        $stmt->execute();
        $stmt->close();

        $_SESSION["assignment_flash"] = [
            "type" => "success",
            "message" =>
                $request["job_no"]
                . " is now unassigned."
        ];
    }

    header(
        "Location: "
        . $redirectUrl
        . "#req-"
        . $requestId
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| HPU Personnel + Workload
|--------------------------------------------------------------------------
*/

$staff = [];

$result =
    $conn->query(
        "SELECT
            u.id,
            u.name,
            u.role,
            u.department,

            COUNT(jr.id) AS total_assigned,

            COALESCE(
                SUM(
                    jr.approval_status NOT IN (
                        'Completed',
                        'For Evaluation',
                        'Closed',
                        'Disapproved'
                    )
                ),
                0
            ) AS active_total,

            COALESCE(
                SUM(
                    jr.date_completion IS NOT NULL
                    AND jr.date_completion < CURDATE()
                    AND jr.approval_status NOT IN (
                        'Completed',
                        'For Evaluation',
                        'Closed',
                        'Disapproved'
                    )
                ),
                0
            ) AS overdue_total,

            COALESCE(
                SUM(
                    jr.date_completion IS NOT NULL
                    AND jr.date_completion BETWEEN
                        CURDATE()
                        AND DATE_ADD(
                            CURDATE(),
                            INTERVAL 3 DAY
                        )
                    AND jr.approval_status NOT IN (
                        'Completed',
                        'For Evaluation',
                        'Closed',
                        'Disapproved'
                    )
                ),
                0
            ) AS due_soon_total

         FROM users u

         LEFT JOIN job_requests jr
            ON jr.assigned_to = u.id
            AND COALESCE(jr.archived,0) = 0

         WHERE
            u.role IN (
                'staff',
                'supervisor',
                'admin'
            )
            AND u.status = 'Active'

         GROUP BY
            u.id,
            u.name,
            u.role,
            u.department

         ORDER BY
            FIELD(
                u.role,
                'staff',
                'supervisor',
                'admin'
            ),
            u.name ASC"
    );

while (
    $row =
    $result->fetch_assoc()
) {
    $staff[] = $row;
}

$staffById = [];

foreach ($staff as $person) {
    $staffById[
        (int) $person["id"]
    ] = $person;
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search =
    trim(
        (string) (
            $_GET["q"]
            ?? ""
        )
    );

$requestTypeFilter =
    trim(
        (string) (
            $_GET["request_type"]
            ?? ""
        )
    );

$assignmentFilter =
    trim(
        (string) (
            $_GET["assignment"]
            ?? ""
        )
    );

$assignedToFilter =
    (int) (
        $_GET["assigned_to"]
        ?? 0
    );

$statusFilter =
    trim(
        (string) (
            $_GET["status"]
            ?? ""
        )
    );

$priorityFilter =
    trim(
        (string) (
            $_GET["priority"]
            ?? ""
        )
    );

$deadlineFilter =
    trim(
        (string) (
            $_GET["deadline"]
            ?? ""
        )
    );

$sort =
    trim(
        (string) (
            $_GET["sort"]
            ?? "newest"
        )
    );

$allowedRequestTypes = [
    "Graphic Design",
    "Video Production",
    "Photo/Video Coverage",
    "IEC Materials"
];

$allowedPriorities = [
    "Urgent",
    "High",
    "Normal",
    "Low"
];

$allowedDeadlines = [
    "due_today",
    "due_soon",
    "overdue",
    "no_deadline"
];

$allowedSorts = [
    "newest",
    "oldest",
    "due_soon",
    "priority"
];

if (
    $requestTypeFilter !== ""
    &&
    !in_array(
        $requestTypeFilter,
        $allowedRequestTypes,
        true
    )
) {
    $requestTypeFilter = "";
}

if (
    $priorityFilter !== ""
    &&
    !in_array(
        $priorityFilter,
        $allowedPriorities,
        true
    )
) {
    $priorityFilter = "";
}

if (
    $deadlineFilter !== ""
    &&
    !in_array(
        $deadlineFilter,
        $allowedDeadlines,
        true
    )
) {
    $deadlineFilter = "";
}

if (
    !in_array(
        $sort,
        $allowedSorts,
        true
    )
) {
    $sort = "newest";
}

/*
|--------------------------------------------------------------------------
| Pagination (keeps the queue fast when it grows)
|--------------------------------------------------------------------------
*/

$page =
    (int) (
        $_GET["page"]
        ?? 1
    );

if ($page < 1) {
    $page = 1;
}

$perPage = 10;

if (
    $assignedToFilter > 0
    &&
    !isset(
    $staffById[
        $assignedToFilter
    ]
)
) {
    $assignedToFilter = 0;
}

/*
|--------------------------------------------------------------------------
| Request Query
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        jr.id,
        jr.job_no,
        jr.project_title,
        jr.request_types,
        jr.requestor_name,
        jr.department,
        jr.date_request,
        jr.date_completion,
        jr.approval_status,
        jr.priority,
        jr.assigned_to,
        jr.assigned_at,
        jr.assignment_notes,
        jr.updated_at,
        u.name AS assigned_name

    FROM job_requests jr

    LEFT JOIN users u
        ON u.id = jr.assigned_to

    WHERE
        COALESCE(
            jr.archived,
            0
        ) = 0
";

$types = "";
$params = [];

if ($search !== "") {
    $sql .= "
        AND (
            jr.job_no LIKE ?
            OR jr.project_title LIKE ?
            OR jr.requestor_name LIKE ?
            OR jr.department LIKE ?
            OR u.name LIKE ?
        )
    ";

    $like =
        "%"
        . $search
        . "%";

    $types .=
        "sssss";

    array_push(
        $params,
        $like,
        $like,
        $like,
        $like,
        $like
    );
}

if (
    $requestTypeFilter !== ""
) {
    $sql .=
        " AND jr.request_types LIKE ? ";

    $types .= "s";

    $params[] =
        '%"'
        . $requestTypeFilter
        . '"%';
}

if (
    $assignmentFilter
    === "assigned"
) {
    $sql .=
        " AND jr.assigned_to IS NOT NULL ";
} elseif (
    $assignmentFilter
    === "unassigned"
) {
    $sql .=
        " AND jr.assigned_to IS NULL ";
}

if (
    $assignedToFilter > 0
) {
    $sql .=
        " AND jr.assigned_to = ? ";

    $types .= "i";
    $params[] =
        $assignedToFilter;
}

if ($statusFilter !== "") {
    $sql .=
        " AND jr.approval_status = ? ";

    $types .= "s";
    $params[] =
        $statusFilter;
}

if ($priorityFilter !== "") {
    $sql .=
        " AND jr.priority = ? ";

    $types .= "s";
    $params[] =
        $priorityFilter;
}

if (
    $deadlineFilter
    === "due_today"
) {
    $sql .= "
        AND jr.date_completion = CURDATE()
        AND jr.approval_status NOT IN (
            'Completed',
            'For Evaluation',
            'Closed',
            'Disapproved'
        )
    ";
} elseif (
    $deadlineFilter
    === "due_soon"
) {
    $sql .= "
        AND jr.date_completion BETWEEN
            CURDATE()
            AND DATE_ADD(
                CURDATE(),
                INTERVAL 3 DAY
            )
        AND jr.approval_status NOT IN (
            'Completed',
            'For Evaluation',
            'Closed',
            'Disapproved'
        )
    ";
} elseif (
    $deadlineFilter
    === "overdue"
) {
    $sql .= "
        AND jr.date_completion IS NOT NULL
        AND jr.date_completion < CURDATE()
        AND jr.approval_status NOT IN (
            'Completed',
            'For Evaluation',
            'Closed',
            'Disapproved'
        )
    ";
} elseif (
    $deadlineFilter
    === "no_deadline"
) {
    $sql .=
        " AND jr.date_completion IS NULL ";
}

$orderBy =
    match ($sort) {
        "oldest" =>
            "jr.date_request ASC, jr.id ASC",

        "due_soon" =>
            "jr.date_completion IS NULL,
             jr.date_completion ASC,
             jr.date_request DESC,
             jr.id DESC",

        "priority" =>
            "FIELD(
                jr.priority,
                'Urgent',
                'High',
                'Normal',
                'Low'
             ),
             jr.date_request DESC,
             jr.id DESC",

        default =>
            "jr.date_request DESC,
             jr.id DESC"
    };

$countStmt =
    $conn->prepare(
        "SELECT COUNT(*) AS total FROM ({$sql}) AS filtered_queue"
    );

if ($params) {
    $countStmt->bind_param(
        $types,
        ...$params
    );
}

$countStmt->execute();

$totalMatching =
    (int) (
        $countStmt
            ->get_result()
            ->fetch_assoc()["total"]
        ?? 0
    );

$countStmt->close();

$totalPages =
    max(
        1,
        (int) ceil(
            $totalMatching / $perPage
        )
    );

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset =
    ($page - 1) * $perPage;

$sql .=
    " ORDER BY "
    . $orderBy
    . " LIMIT ?, ?";

$types .= "ii";
$params[] = $offset;
$params[] = $perPage;

$stmt =
    $conn->prepare(
        $sql
    );

if ($params) {
    $stmt->bind_param(
        $types,
        ...$params
    );
}

$stmt->execute();

$requests =
    $stmt
        ->get_result()
        ->fetch_all(
            MYSQLI_ASSOC
        );

$stmt->close();

$pageStart =
    $totalMatching > 0
    ? $offset + 1
    : 0;

$pageEnd =
    min(
        $offset + count($requests),
        $totalMatching
    );

/*
|--------------------------------------------------------------------------
| Available Statuses
|--------------------------------------------------------------------------
*/

$statuses = [];

$statusResult =
    $conn->query(
        "SELECT DISTINCT
            approval_status
         FROM job_requests
         WHERE
            approval_status IS NOT NULL
            AND approval_status <> ''
         ORDER BY
            approval_status"
    );

while (
    $row =
    $statusResult->fetch_assoc()
) {
    $statuses[] =
        (string) 
        $row["approval_status"];
}

/*
|--------------------------------------------------------------------------
| Operational Counts
|--------------------------------------------------------------------------
*/

$countResult =
    $conn->query(
        "SELECT
            COUNT(*) AS total,

            COALESCE(
                SUM(
                    assigned_to IS NOT NULL
                ),
                0
            ) AS assigned_total,

            COALESCE(
                SUM(
                    assigned_to IS NULL
                ),
                0
            ) AS unassigned_total,

            COALESCE(
                SUM(
                    priority = 'Urgent'
                ),
                0
            ) AS urgent_total,

            COALESCE(
                SUM(
                    date_completion IS NOT NULL
                    AND date_completion < CURDATE()
                    AND approval_status NOT IN (
                        'Completed',
                        'For Evaluation',
                        'Closed',
                        'Disapproved'
                    )
                ),
                0
            ) AS overdue_total,

            COALESCE(
                SUM(
                    date_completion IS NOT NULL
                    AND date_completion BETWEEN
                        CURDATE()
                        AND DATE_ADD(
                            CURDATE(),
                            INTERVAL 3 DAY
                        )
                    AND approval_status NOT IN (
                        'Completed',
                        'For Evaluation',
                        'Closed',
                        'Disapproved'
                    )
                ),
                0
            ) AS due_soon_total

         FROM job_requests

         WHERE
            COALESCE(
                archived,
                0
            ) = 0"
    );

$counts =
    $countResult
        ->fetch_assoc()
    ?: [];

/*
|--------------------------------------------------------------------------
| Preserve Current Filter State During Save
|--------------------------------------------------------------------------
*/

$returnQuery =
    assignmentSafeReturnQuery(
        $_SERVER["QUERY_STRING"]
        ?? ""
    );

/*
|--------------------------------------------------------------------------
| Result Label
|--------------------------------------------------------------------------
*/

$resultCount =
    count($requests);

$filterActive =
    $search !== ""
    ||
    $requestTypeFilter !== ""
    ||
    $assignmentFilter !== ""
    ||
    $assignedToFilter > 0
    ||
    $statusFilter !== ""
    ||
    $priorityFilter !== ""
    ||
    $deadlineFilter !== ""
    ||
    $sort !== "newest";

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        Assign Jobs | HPU Workbench
    </title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/style.css">

    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/page_headers.css">

    <style>
        :root {
            --assign-primary: #1769e0;
            --assign-bg: #f4f7fb;
            --assign-border: #dfe6ef;
            --assign-muted: #667085;
            --assign-text: #172033;
            --assign-shadow: 0 8px 24px rgba(28, 42, 72, .05);
        }

        body {
            background: var(--assign-bg);
            color: var(--assign-text);
        }

        .content {
            padding: 18px;
        }

        .container-fluid {
            max-width: 1700px;
            margin: 0 auto;
        }

        /* Header styles moved to assets/css/page_headers.css (.ph) */

        /* Stats */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 9px;
            margin-bottom: 12px;
        }

        .stat-card {
            display: block;
            min-height: 92px;
            padding: 11px 12px;
            border: 1px solid var(--assign-border);
            border-radius: 13px;
            background: #fff;
            box-shadow: var(--assign-shadow);
            color: inherit;
            text-decoration: none;
        }

        .stat-card:hover {
            border-color: #bfd6ff;
        }

        .stat-label {
            color: #667085;
            font-size: .60rem;
            font-weight: 850;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .stat-value {
            margin-top: 4px;
            font-size: 1.55rem;
            line-height: 1;
            font-weight: 900;
        }

        .stat-note {
            margin-top: 6px;
            color: #98a2b3;
            font-size: .61rem;
        }

        /* Workload */
        .workload-card {
            margin-bottom: 12px;
            padding: 11px 12px;
            border: 1px solid var(--assign-border);
            border-radius: 14px;
            background: #fff;
            box-shadow: var(--assign-shadow);
        }

        .workload-head {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            align-items: center;
            margin-bottom: 8px;
        }

        .workload-title {
            font-size: .76rem;
            font-weight: 850;
        }

        .workload-note {
            color: #98a2b3;
            font-size: .62rem;
        }

        .workload-strip {
            display: flex;
            gap: 7px;
            overflow-x: auto;
            padding-bottom: 2px;
        }

        .workload-person {
            flex: 0 0 auto;
            display: flex;
            gap: 8px;
            align-items: center;
            min-width: 175px;
            padding: 8px 9px;
            border: 1px solid #eaecf0;
            border-radius: 10px;
            background: #fafbfc;
            text-decoration: none;
            color: inherit;
        }

        .workload-person:hover {
            border-color: #bfd6ff;
            background: #fbfdff;
        }

        .staff-avatar {
            width: 31px;
            height: 31px;
            flex: 0 0 31px;
            display: grid;
            place-items: center;
            border-radius: 9px;
            background: #edf5ff;
            color: #1769e0;
            font-size: .65rem;
            font-weight: 900;
        }

        .staff-info {
            min-width: 0;
        }

        .staff-name {
            max-width: 125px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: .68rem;
            font-weight: 850;
        }

        .staff-load {
            margin-top: 2px;
            color: #667085;
            font-size: .59rem;
        }

        /* Toolbar */
        .queue-shell {
            border: 1px solid var(--assign-border);
            border-radius: 15px;
            overflow: hidden;
            background: #fff;
            box-shadow: var(--assign-shadow);
        }

        .filter-bar {
            padding: 11px 12px;
            border-bottom: 1px solid #edf1f6;
            background: #fff;
        }

        .filter-label {
            display: block;
            margin-bottom: 3px;
            color: #667085;
            font-size: .58rem;
            font-weight: 850;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .quick-tabs {
            display: flex;
            gap: 4px;
            overflow-x: auto;
            padding: 8px 12px;
            border-bottom: 1px solid #edf1f6;
            background: #fbfcfe;
        }

        .quick-tab {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 9px;
            border-radius: 8px;
            color: #667085;
            text-decoration: none;
            font-size: .65rem;
            font-weight: 800;
        }

        .quick-tab:hover {
            background: #fff;
            color: #1769e0;
        }

        .quick-tab.active {
            background: #1769e0;
            color: #fff;
        }

        .queue-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            padding: 8px 12px;
            border-bottom: 1px solid #edf1f6;
            color: #667085;
            font-size: .65rem;
        }

        /* Request rows */
        .request-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 365px;
            gap: 16px;
            padding: 15px 16px;
            border-top: 1px solid #edf1f6;
            border-left: 5px solid #64748b;
            background: #fff;
        }

        .request-row:first-of-type {
            border-top: 0;
        }

        .request-row-graphic {
            border-left-color: #2563eb;
            background:
                linear-gradient(90deg,
                    rgba(37, 99, 235, .035),
                    #fff 18%);
        }

        .request-row-video {
            border-left-color: #7c3aed;
            background:
                linear-gradient(90deg,
                    rgba(124, 58, 237, .035),
                    #fff 18%);
        }

        .request-row-coverage {
            border-left-color: #ea580c;
            background:
                linear-gradient(90deg,
                    rgba(234, 88, 12, .035),
                    #fff 18%);
        }

        .request-row-iec {
            border-left-color: #15803d;
            background:
                linear-gradient(90deg,
                    rgba(21, 128, 61, .035),
                    #fff 18%);
        }

        .request-row-other {
            border-left-color: #64748b;
        }

        .request-main {
            min-width: 0;
        }

        .request-topline {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .job-no {
            color: #1769e0;
            font-size: .66rem;
            font-weight: 900;
        }

        .status-pill,
        .priority-pill,
        .age-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 6px;
            border-radius: 999px;
            font-size: .57rem;
            font-weight: 850;
        }

        .status-pending,
        .status-review {
            background: #fffaeb;
            color: #b54708;
        }

        .status-approved {
            background: #ecfdf3;
            color: #027a48;
        }

        .status-revision {
            background: #fff4ed;
            color: #c4320a;
        }

        .status-completed,
        .status-evaluation,
        .status-closed {
            background: #ecfdf3;
            color: #027a48;
        }

        .status-disapproved {
            background: #fef3f2;
            color: #b42318;
        }

        .status-default {
            background: #f2f4f7;
            color: #475467;
        }

        .priority-urgent {
            background: #fef3f2;
            color: #b42318;
        }

        .priority-high {
            background: #fff7ed;
            color: #c2410c;
        }

        .priority-normal {
            background: #eff8ff;
            color: #175cd3;
        }

        .priority-low {
            background: #ecfdf3;
            color: #027a48;
        }

        .age-pill {
            background: #f8fafc;
            color: #667085;
        }

        .job-title {
            margin-top: 5px;
            color: #1d2939;
            font-size: .94rem;
            line-height: 1.3;
            font-weight: 880;
        }

        .request-type-label {
            margin-top: 8px;
            color: #667085;
            font-size: .57rem;
            font-weight: 850;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .request-type-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 4px;
        }

        .request-type-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 7px;
            border-radius: 999px;
            border: 1px solid transparent;
            font-size: .60rem;
            font-weight: 850;
        }

        .request-type-graphic {
            background: #eff6ff;
            color: #1d4ed8;
            border-color: #bfdbfe;
        }

        .request-type-video {
            background: #f5f3ff;
            color: #6d28d9;
            border-color: #ddd6fe;
        }

        .request-type-coverage {
            background: #fff7ed;
            color: #c2410c;
            border-color: #fed7aa;
        }

        .request-type-iec {
            background: #f0fdf4;
            color: #15803d;
            border-color: #bbf7d0;
        }

        .request-type-other {
            background: #f8fafc;
            color: #475569;
            border-color: #e2e8f0;
        }

        .request-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 5px 12px;
            margin-top: 8px;
            color: #667085;
            font-size: .65rem;
        }

        .deadline-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 7px;
            border-radius: 8px;
            font-size: .61rem;
            font-weight: 850;
        }

        .deadline-normal {
            background: #f8fafc;
            color: #475467;
        }

        .deadline-soon {
            background: #fffaeb;
            color: #b54708;
        }

        .deadline-today {
            background: #fff4ed;
            color: #c4320a;
        }

        .deadline-overdue {
            background: #fef3f2;
            color: #b42318;
        }

        .current-assignment {
            margin-top: 9px;
            padding: 8px 9px;
            border: 1px solid #eaecf0;
            border-radius: 10px;
            background: rgba(248, 250, 252, .82);
            font-size: .66rem;
        }

        .current-assignment strong {
            color: #344054;
        }

        .assignment-notes-view {
            margin-top: 5px;
            color: #667085;
            line-height: 1.4;
        }

        /* Assignment panel */
        .assignment-panel {
            align-self: start;
            padding: 11px;
            border: 1px solid #dfe6ef;
            border-radius: 12px;
            background: #fff;
        }

        .assignment-panel-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 8px;
            font-size: .72rem;
            font-weight: 800;
            color: #0f172a;
        }

        .assignment-panel-title i {
            color: #1d4ed8;
        }

        .assignment-state {
            font-size: .58rem;
            color: #667085;
        }

        .assignment-panel .form-select,
        .assignment-panel .form-control {
            font-size: .72rem;
        }

        .assignment-panel textarea {
            resize: vertical;
            min-height: 62px;
        }

        .assignment-footer {
            display: flex;
            gap: 7px;
            margin-top: 8px;
        }

        .assignment-footer .btn {
            font-size: .68rem;
            font-weight: 800;
        }

        .view-request {
            flex: 0 0 auto;
        }

        /* Empty */
        .empty-state {
            padding: 55px 20px;
            text-align: center;
            color: #667085;
        }

        .empty-state i {
            display: block;
            margin-bottom: 8px;
            color: #98a2b3;
            font-size: 1.6rem;
        }

        /* ============ ASSIGN UX UPGRADE (additive, no backend change) ============ */
        .rm-crumb {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 7px;
            margin: 0 0 10px 2px;
            color: #94a3b8;
            font-size: .7rem;
            font-weight: 650;
        }

        .rm-crumb a {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: #64748b;
            text-decoration: none;
            font-weight: 750;
        }

        .rm-crumb a:hover {
            color: #1769e0;
            text-decoration: underline;
        }

        .rm-crumb-sep {
            color: #cbd5e1;
        }

        .rm-crumb-current {
            color: #0f172a;
            font-weight: 800;
        }

        .result-pill {
            display: inline-flex;
            min-height: 32px;
            align-items: center;
            gap: 6px;
            padding: 6px 11px;
            border-radius: 8px;
            background: #eaf8f1;
            color: #116c45;
            font-size: .72rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .search-wrap {
            position: relative;
        }

        .search-wrap>i {
            position: absolute;
            top: 50%;
            left: 9px;
            transform: translateY(-50%);
            color: #98a2b3;
            font-size: .75rem;
            z-index: 2;
        }

        .search-wrap .form-control {
            padding-left: 29px;
            padding-right: 26px;
        }

        .search-clear {
            position: absolute;
            top: 50%;
            right: 6px;
            transform: translateY(-50%);
            border: 0;
            background: #f1f5f9;
            color: #667085;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: .62rem;
            cursor: pointer;
        }

        .search-clear.show {
            display: inline-flex;
        }

        .search-clear:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        .active-chips {
            display: none;
            gap: 6px;
            flex-wrap: wrap;
            padding: 8px 12px;
            border-top: 1px solid #edf1f6;
            background: #fbfcfe;
        }

        .active-chips.show {
            display: flex;
        }

        .active-chips-label {
            color: #667085;
            font-size: .62rem;
            font-weight: 800;
            align-self: center;
        }

        .filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            max-width: 280px;
            padding: 3px 5px 3px 9px;
            border-radius: 999px;
            background: #edf5ff;
            color: #175cd3;
            font-size: .64rem;
            font-weight: 750;
        }

        .filter-chip span {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .filter-chip a {
            display: grid;
            width: 17px;
            height: 17px;
            place-items: center;
            border-radius: 50%;
            background: rgba(23, 105, 224, .14);
            color: inherit;
            text-decoration: none;
            font-size: .58rem;
            flex: 0 0 17px;
        }

        .filter-chip a:hover {
            background: #1769e0;
            color: #fff;
        }

        .workload-person {
            position: relative;
        }

        .workload-bar {
            height: 4px;
            border-radius: 999px;
            background: #eef2f6;
            margin-top: 5px;
            overflow: hidden;
        }

        .workload-bar i {
            display: block;
            height: 100%;
            border-radius: 999px;
            background: #22c55e;
        }

        .workload-bar.warn i {
            background: #f59e0b;
        }

        .workload-bar.hot i {
            background: #f04438;
        }

        .suggest-badge {
            position: absolute;
            top: -7px;
            right: -4px;
            padding: 2px 7px;
            border-radius: 999px;
            background: #16a34a;
            color: #fff;
            font-size: .54rem;
            font-weight: 900;
            letter-spacing: .03em;
            box-shadow: 0 2px 6px rgba(22, 163, 74, .4);
        }

        .workload-person.is-filtered {
            outline: 2px solid #1769e0;
            outline-offset: 1px;
        }

        .queue-toolbar {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .live-count {
            font-weight: 900;
            color: #0f172a;
        }

        kbd {
            padding: 1px 6px;
            border: 1px solid #e2e8f0;
            border-bottom-width: 2px;
            border-radius: 6px;
            background: #fff;
            color: #94a3b8;
            font-size: .6rem;
        }

        .request-row {
            position: relative;
            transition: box-shadow .15s ease, border-color .15s ease;
        }

        .request-row:hover {
            box-shadow: 0 6px 18px rgba(15, 23, 42, .07);
            z-index: 1;
        }

        .row-select {
            position: absolute;
            top: 10px;
            right: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: .6rem;
            font-weight: 800;
            color: #98a2b3;
            cursor: pointer;
            user-select: none;
        }

        .row-select input {
            width: 16px;
            height: 16px;
            accent-color: #1769e0;
            cursor: pointer;
        }

        .request-row.is-selected {
            outline: 2px solid #1769e0;
            outline-offset: -2px;
            border-radius: 4px;
        }

        .job-no-copy {
            border: 0;
            background: transparent;
            color: #98a2b3;
            font-size: .62rem;
            cursor: pointer;
            padding: 1px 3px;
            border-radius: 4px;
        }

        .job-no-copy:hover {
            color: #1769e0;
            background: #edf5ff;
        }

        .assignment-panel.is-dirty {
            border-color: #1769e0;
            box-shadow: 0 0 0 3px rgba(23, 105, 224, .12);
        }

        .dirty-dot {
            display: none;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #1769e0;
            box-shadow: 0 0 0 3px rgba(23, 105, 224, .15);
        }

        .assignment-panel.is-dirty .dirty-dot {
            display: inline-block;
        }

        .notes-counter {
            margin-top: 3px;
            color: #98a2b3;
            font-size: .58rem;
            text-align: right;
        }

        .notes-counter.over {
            color: #b42318;
            font-weight: 800;
        }

        .bulk-bar {
            display: none;
            position: sticky;
            top: 0;
            z-index: 30;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            margin: 0;
            padding: 10px 12px;
            border: 1px solid #bfd6ff;
            border-radius: 12px;
            background: #eff6ff;
            box-shadow: 0 8px 22px rgba(23, 105, 224, .14);
        }

        .bulk-bar.show {
            display: flex;
        }

        .bulk-bar strong {
            font-size: .72rem;
            color: #1e3a8a;
        }

        .bulk-bar .form-select {
            font-size: .7rem;
            min-width: 180px;
        }

        .bulk-bar .btn {
            font-size: .68rem;
            font-weight: 800;
        }

        .toast-copy {
            position: fixed;
            left: 50%;
            bottom: 22px;
            transform: translateX(-50%) translateY(10px);
            background: #0f172a;
            color: #fff;
            font-size: .72rem;
            font-weight: 750;
            padding: 9px 14px;
            border-radius: 10px;
            opacity: 0;
            pointer-events: none;
            transition: .2s ease;
            z-index: 1080;
            display: flex;
            gap: 7px;
            align-items: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .25);
        }

        .toast-copy.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        body.ultra-compact .request-row {
            padding: 9px 12px;
            gap: 10px;
        }

        body.ultra-compact .request-meta,
        body.ultra-compact .request-type-label,
        body.ultra-compact .assignment-notes-view {
            display: none;
        }

        body.ultra-compact .job-title {
            font-size: .82rem;
        }

        body.ultra-compact .assignment-panel textarea {
            min-height: 40px;
        }

        .staff-role {
            margin-top: 1px;
            max-width: 135px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: #98a2b3;
            font-size: .56rem;
            font-weight: 700;
        }

        .workload-note a {
            color: #1769e0;
            font-weight: 800;
            text-decoration: none;
        }

        .workload-note a:hover {
            text-decoration: underline;
        }

        .pagination-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            padding: 10px 12px;
            border-top: 1px solid #edf1f6;
            background: #fbfcfe;
        }

        .pagination-meta {
            color: #667085;
            font-size: .68rem;
            font-weight: 750;
        }

        .pagination-bar .page-link {
            font-size: .72rem;
            font-weight: 800;
            color: #344054;
        }

        .pagination-bar .page-item.active .page-link {
            background: #1769e0;
            border-color: #1769e0;
            color: #fff;
        }

        @media(max-width:1250px) {
            .stat-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .request-row {
                grid-template-columns: minmax(0, 1fr) 330px;
            }
        }

        @media(max-width:900px) {
            .request-row {
                grid-template-columns: 1fr;
            }
        }

        @media(max-width:700px) {
            .content {
                padding: 12px;
            }

            .stat-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 7px;
            }

            .assignment-footer {
                flex-direction: column;
            }
        }
    </style>

</head>

<body class="app-shell">

    <div class="d-flex min-vh-100">

        <?php
        include __DIR__ . "/includes/sidebar.php";
        ?>

        <div class="main flex-grow-1">

            <main class="content">

                <div class="container-fluid">

                    <!-- ======================================================
     HEADER
======================================================= -->

                    <nav class="rm-crumb" aria-label="Breadcrumb">
                        <a href="<?= e(BASE_URL) ?>/dashboard.php"><i class="bi bi-house-door"></i> Dashboard</a>
                        <span class="rm-crumb-sep">/</span>
                        <span class="rm-crumb-current" aria-current="page">Assign Jobs</span>
                    </nav>

                    <div class="ph">

                        <div class="ph-copy">
                            <div class="ph-icon" aria-hidden="true">
                                <i class="bi bi-person-check-fill"></i>
                            </div>

                            <div class="min-w-0">
                                <p class="ph-eyebrow">HPU Workbench</p>
                                <h1 class="ph-title">Assign Jobs</h1>
                                <p class="ph-subtitle">
                                    Assign and balance HPU work while monitoring
                                    priority, deadlines and request type.
                                </p>
                            </div>
                        </div>

                        <div class="ph-actions">

                            <span class="result-pill" title="Jobs matching the current filters">
                                <i class="bi bi-list-check"></i>
                                <?= (int) $totalMatching ?> in queue
                                <?php if ($totalPages > 1): ?>
                                    • page <?= (int) $page ?> of <?= (int) $totalPages ?>
                                <?php endif; ?>
                            </span>

                            <button type="button" class="btn btn-sm btn-outline-secondary" id="densityToggle"
                                aria-pressed="false" title="Toggle compact density">
                                <i class="bi bi-arrows-collapse"></i>
                                Compact
                            </button>

                            <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/dashboard.php">
                                <i class="bi bi-speedometer2 me-1"></i>
                                Dashboard
                            </a>

                            <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/my_calendar.php">
                                <i class="bi bi-calendar3-week me-1"></i>
                                HPU Calendar
                            </a>

                        </div>

                    </div>

                    <?php if ($flash): ?>

                        <div class="alert
           alert-<?= e($flash["type"]) ?>
           alert-dismissible
           fade
           show">
                            <?= e($flash["message"]) ?>

                            <button class="btn-close" data-bs-dismiss="alert"></button>
                        </div>

                    <?php endif; ?>

                    <!-- ======================================================
     OPERATIONAL STATS
======================================================= -->

                    <div class="stat-grid">

                        <a class="stat-card" href="<?= e(BASE_URL) ?>/assign_jobs.php">
                            <div class="stat-label">
                                Total Jobs
                            </div>

                            <div class="stat-value">
                                <?= (int) ($counts["total"] ?? 0) ?>
                            </div>

                            <div class="stat-note">
                                Active queue
                            </div>
                        </a>

                        <a class="stat-card" href="<?= e(BASE_URL) ?>/assign_jobs.php?assignment=assigned">
                            <div class="stat-label">
                                Assigned
                            </div>

                            <div class="stat-value text-success">
                                <?= (int) ($counts["assigned_total"] ?? 0) ?>
                            </div>

                            <div class="stat-note">
                                With work owner
                            </div>
                        </a>

                        <a class="stat-card" href="<?= e(BASE_URL) ?>/assign_jobs.php?assignment=unassigned">
                            <div class="stat-label">
                                Unassigned
                            </div>

                            <div class="stat-value text-warning">
                                <?= (int) ($counts["unassigned_total"] ?? 0) ?>
                            </div>

                            <div class="stat-note">
                                Needs assignment
                            </div>
                        </a>

                        <a class="stat-card" href="<?= e(BASE_URL) ?>/assign_jobs.php?deadline=overdue">
                            <div class="stat-label">
                                Overdue
                            </div>

                            <div class="stat-value text-danger">
                                <?= (int) ($counts["overdue_total"] ?? 0) ?>
                            </div>

                            <div class="stat-note">
                                Past completion date
                            </div>
                        </a>

                        <a class="stat-card" href="<?= e(BASE_URL) ?>/assign_jobs.php?deadline=due_soon">
                            <div class="stat-label">
                                Due ≤ 3 Days
                            </div>

                            <div class="stat-value text-warning">
                                <?= (int) ($counts["due_soon_total"] ?? 0) ?>
                            </div>

                            <div class="stat-note">
                                Upcoming deadlines
                            </div>
                        </a>

                        <a class="stat-card" href="<?= e(BASE_URL) ?>/assign_jobs.php?priority=Urgent&sort=priority">
                            <div class="stat-label">
                                Urgent
                            </div>

                            <div class="stat-value text-danger">
                                <?= (int) ($counts["urgent_total"] ?? 0) ?>
                            </div>

                            <div class="stat-note">
                                Priority queue
                            </div>
                        </a>

                    </div>

                    <!-- ======================================================
     STAFF WORKLOAD
======================================================= -->

                    <div class="workload-card">

                        <div class="workload-head">

                            <div class="workload-title">
                                <i class="bi bi-people me-1 text-primary"></i>
                                HPU Staff Workload
                            </div>

                            <div class="workload-note">
                                <?php if ($assignedToFilter > 0 && isset($staffById[$assignedToFilter])): ?>
                                    Filtering by
                                    <strong><?= e((string) $staffById[$assignedToFilter]["name"]) ?></strong>
                                    •
                                    <a href="<?= e(assignBuildQuery(["assigned_to" => null, "page" => null])) ?>">Show all</a>
                                <?php else: ?>
                                    Click a person to filter the queue.
                                <?php endif; ?>
                            </div>

                        </div>

                        <div class="workload-strip">

                            <?php foreach ($staff as $person): ?>

                                <?php
                                $initials = "";

                                $nameParts =
                                    preg_split(
                                        '/\s+/',
                                        trim(
                                            (string) $person["name"]
                                        )
                                    ) ?: [];

                                foreach (
                                    array_slice(
                                        $nameParts,
                                        0,
                                        2
                                    )
                                    as $part
                                ) {
                                    if ($part !== "") {
                                        $initials .=
                                            mb_strtoupper(
                                                mb_substr(
                                                    $part,
                                                    0,
                                                    1
                                                )
                                            );
                                    }
                                }
                                ?>

                                <a class="workload-person<?= $assignedToFilter === (int) $person["id"] ? " is-filtered" : "" ?>"
                                    href="<?= e(assignBuildQuery(["assigned_to" => (int) $person["id"], "page" => null])) ?>"
                                    title="<?= e((string) $person["name"]) ?> — <?= (int) $person["active_total"] ?> active"
                                    <?= $assignedToFilter === (int) $person["id"] ? 'aria-current="true"' : "" ?>>

                                    <div class="staff-avatar">
                                        <?= e($initials ?: "HP") ?>
                                    </div>

                                    <div class="staff-info">

                                        <div class="staff-name">
                                            <?= e($person["name"]) ?>
                                        </div>

                                        <div class="staff-role">
                                            <?= e(ucfirst((string) $person["role"])) ?>
                                            <?php if (trim((string) ($person["department"] ?? "")) !== ""): ?>
                                                • <?= e((string) $person["department"]) ?>
                                            <?php endif; ?>
                                        </div>

                                        <div class="staff-load">
                                            <?= (int) $person["active_total"] ?> active

                                            <?php if ((int) $person["due_soon_total"] > 0): ?>
                                                • <?= (int) $person["due_soon_total"] ?> due soon
                                            <?php endif; ?>

                                            <?php if ((int) $person["overdue_total"] > 0): ?>
                                                •
                                                <span class="text-danger fw-bold">
                                                    <?= (int) $person["overdue_total"] ?> overdue
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                    </div>

                                </a>

                            <?php endforeach; ?>

                        </div>

                    </div>

                    <!-- ======================================================
     QUEUE
======================================================= -->

                    <section class="queue-shell">

                        <!-- QUICK FILTERS -->
                        <div class="quick-tabs">

                            <a class="quick-tab <?= !$filterActive ? "active" : "" ?>"
                                href="<?= e(BASE_URL) ?>/assign_jobs.php">
                                <i class="bi bi-list-task"></i>
                                All
                            </a>

                            <a class="quick-tab <?= $assignmentFilter === "unassigned" ? "active" : "" ?>"
                                href="<?= e(BASE_URL) ?>/assign_jobs.php?assignment=unassigned">
                                <i class="bi bi-person-plus"></i>
                                Unassigned
                            </a>

                            <a class="quick-tab <?= $deadlineFilter === "due_today" ? "active" : "" ?>"
                                href="<?= e(BASE_URL) ?>/assign_jobs.php?deadline=due_today">
                                <i class="bi bi-alarm"></i>
                                Due Today
                            </a>

                            <a class="quick-tab <?= $deadlineFilter === "due_soon" ? "active" : "" ?>"
                                href="<?= e(BASE_URL) ?>/assign_jobs.php?deadline=due_soon">
                                <i class="bi bi-clock-history"></i>
                                Due Soon
                            </a>

                            <a class="quick-tab <?= $deadlineFilter === "overdue" ? "active" : "" ?>"
                                href="<?= e(BASE_URL) ?>/assign_jobs.php?deadline=overdue">
                                <i class="bi bi-exclamation-octagon"></i>
                                Overdue
                            </a>

                            <a class="quick-tab <?= $priorityFilter === "Urgent" ? "active" : "" ?>"
                                href="<?= e(BASE_URL) ?>/assign_jobs.php?priority=Urgent&sort=priority">
                                <i class="bi bi-lightning-charge"></i>
                                Urgent
                            </a>

                        </div>

                        <!-- FULL FILTERS -->
                        <form class="filter-bar" method="get">

                            <div class="row g-2 align-items-end">

                                <div class="col-xl-3 col-lg-4">

                                    <label class="filter-label">
                                        Search
                                    </label>

                                    <div class="search-wrap">
                                        <i class="bi bi-search"></i>
                                        <input id="assignSearchInput" class="form-control form-control-sm" name="q"
                                            value="<?= e($search) ?>"
                                            placeholder="Job no., title, requestor, department...  ( / )"
                                            autocomplete="off">
                                        <button type="button" class="search-clear" id="assignSearchClear"
                                            title="Clear search" aria-label="Clear search"><i
                                                class="bi bi-x-lg"></i></button>
                                    </div>

                                </div>

                                <div class="col-xl-2 col-lg-3">

                                    <label class="filter-label">
                                        Request Type
                                    </label>

                                    <select class="form-select form-select-sm" name="request_type">

                                        <option value="">
                                            All Request Types
                                        </option>

                                        <?php foreach ($allowedRequestTypes as $requestType): ?>

                                            <option value="<?= e($requestType) ?>" <?= $requestTypeFilter === $requestType ? "selected" : "" ?>>
                                                <?= e($requestType) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div class="col-xl-2 col-lg-3">

                                    <label class="filter-label">
                                        Assigned To
                                    </label>

                                    <select class="form-select form-select-sm" name="assigned_to">

                                        <option value="0">
                                            All HPU Personnel
                                        </option>

                                        <?php foreach ($staff as $person): ?>

                                            <option value="<?= (int) $person["id"] ?>" <?= $assignedToFilter === (int) $person["id"] ? "selected" : "" ?>>
                                                <?= e($person["name"]) ?>
                                                (<?= (int) $person["active_total"] ?> active)
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div class="col-xl-1 col-lg-2">

                                    <label class="filter-label">
                                        Assignment
                                    </label>

                                    <select class="form-select form-select-sm" name="assignment">
                                        <option value="">All</option>

                                        <option value="assigned" <?= $assignmentFilter === "assigned" ? "selected" : "" ?>>
                                            Assigned
                                        </option>

                                        <option value="unassigned" <?= $assignmentFilter === "unassigned" ? "selected" : "" ?>>
                                            Unassigned
                                        </option>
                                    </select>

                                </div>

                                <div class="col-xl-1 col-lg-2">

                                    <label class="filter-label">
                                        Priority
                                    </label>

                                    <select class="form-select form-select-sm" name="priority">

                                        <option value="">
                                            All
                                        </option>

                                        <?php foreach ($allowedPriorities as $priority): ?>

                                            <option value="<?= e($priority) ?>" <?= $priorityFilter === $priority ? "selected" : "" ?>>
                                                <?= e($priority) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div class="col-xl-1 col-lg-2">

                                    <label class="filter-label">
                                        Deadline
                                    </label>

                                    <select class="form-select form-select-sm" name="deadline">
                                        <option value="">All</option>

                                        <option value="due_today" <?= $deadlineFilter === "due_today" ? "selected" : "" ?>>
                                            Today
                                        </option>

                                        <option value="due_soon" <?= $deadlineFilter === "due_soon" ? "selected" : "" ?>>
                                            ≤ 3 Days
                                        </option>

                                        <option value="overdue" <?= $deadlineFilter === "overdue" ? "selected" : "" ?>>
                                            Overdue
                                        </option>

                                        <option value="no_deadline" <?= $deadlineFilter === "no_deadline" ? "selected" : "" ?>>
                                            None
                                        </option>
                                    </select>

                                </div>

                                <div class="col-xl-2 col-lg-3">

                                    <label class="filter-label">
                                        Status
                                    </label>

                                    <select class="form-select form-select-sm" name="status">

                                        <option value="">
                                            All Statuses
                                        </option>

                                        <?php foreach ($statuses as $status): ?>

                                            <option value="<?= e($status) ?>" <?= $statusFilter === $status ? "selected" : "" ?>>
                                                <?= e($status) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div class="col-xl-2 col-lg-3">

                                    <label class="filter-label">
                                        Sort
                                    </label>

                                    <select class="form-select form-select-sm" name="sort">

                                        <option value="newest" <?= $sort === "newest" ? "selected" : "" ?>>
                                            Newest First
                                        </option>

                                        <option value="oldest" <?= $sort === "oldest" ? "selected" : "" ?>>
                                            Oldest First
                                        </option>

                                        <option value="due_soon" <?= $sort === "due_soon" ? "selected" : "" ?>>
                                            Deadline First
                                        </option>

                                        <option value="priority" <?= $sort === "priority" ? "selected" : "" ?>>
                                            Priority First
                                        </option>

                                    </select>

                                </div>

                                <div class="col-xl-auto col-lg-2">

                                    <button class="btn btn-sm btn-primary w-100">
                                        <i class="bi bi-funnel me-1"></i>
                                        Apply
                                    </button>

                                </div>

                                <?php if ($filterActive): ?>

                                    <div class="col-xl-auto col-lg-2">

                                        <a class="btn btn-sm btn-light w-100" href="<?= e(BASE_URL) ?>/assign_jobs.php">
                                            Reset
                                        </a>

                                    </div>

                                <?php endif; ?>

                            </div>

                        </form>

                        <!-- RESULT INFO -->
                        <div class="queue-info">

                            <div>
                                Showing
                                <strong class="text-dark">
                                    <?= (int) $pageStart ?>–<?= (int) $pageEnd ?>
                                </strong>
                                of
                                <strong class="text-dark">
                                    <?= (int) $totalMatching ?>
                                </strong>
                                job request<?= $totalMatching === 1 ? "" : "s" ?>
                            </div>

                            <div>
                                Default order:
                                <strong>latest request on top</strong>
                            </div>

                        </div>

                        <!-- REQUEST LIST -->

                        <?php if (!$requests): ?>

                            <div class="empty-state">

                                <i class="bi bi-inbox"></i>

                                <strong class="d-block text-dark">
                                    No job requests found
                                </strong>

                                <span>
                                    No request matches the current filters.
                                </span>

                            </div>

                        <?php endif; ?>

                        <?php foreach ($requests as $request): ?>

                            <?php
                            $requestTypes =
                                decodeArray(
                                    $request["request_types"]
                                    ?? null
                                );

                            $rowClass =
                                assignmentRowClass(
                                    $requestTypes
                                );

                            $deadline =
                                assignmentDeadlineMeta(
                                    $request["date_completion"],
                                    (string) $request["approval_status"]
                                );

                            $ageLabel =
                                assignmentAgeLabel(
                                    $request["date_request"]
                                );

                            $currentAssignee =
                                $request["assigned_name"]
                                ?: "Unassigned";
                            ?>

                            <article class="request-row <?= e($rowClass) ?>" id="req-<?= (int) $request["id"] ?>">

                                <div class="request-main">

                                    <div class="request-topline">

                                        <span class="job-no">
                                            <?= e($request["job_no"]) ?>
                                        </span>

                                        <span
                                            class="status-pill <?= e(assignmentStatusClass((string) $request["approval_status"])) ?>">
                                            <?= e($request["approval_status"]) ?>
                                        </span>

                                        <span
                                            class="priority-pill <?= e(assignmentPriorityClass((string) $request["priority"])) ?>">
                                            <?= e($request["priority"]) ?>
                                        </span>

                                        <?php if ($ageLabel !== ""): ?>

                                            <span class="age-pill">
                                                <i class="bi bi-clock"></i>
                                                <?= e($ageLabel) ?>
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                    <div class="job-title">
                                        <?= e($request["project_title"]) ?>
                                    </div>

                                    <div class="request-type-label">
                                        Type of Request
                                    </div>

                                    <div class="request-type-badges">

                                        <?php if ($requestTypes): ?>

                                            <?php foreach ($requestTypes as $requestType): ?>

                                                <span
                                                    class="request-type-badge <?= e(assignmentRequestTypeClass((string) $requestType)) ?>">
                                                    <i class="bi <?= e(assignmentRequestTypeIcon((string) $requestType)) ?>"></i>

                                                    <?= e($requestType) ?>
                                                </span>

                                            <?php endforeach; ?>

                                        <?php else: ?>

                                            <span class="request-type-badge request-type-other">
                                                <i class="bi bi-question-circle"></i>
                                                Not Specified
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                    <div class="request-meta">

                                        <span>
                                            <i class="bi bi-calendar-plus me-1"></i>
                                            Requested
                                            <?= e(
                                                assignmentDate(
                                                    $request["date_request"]
                                                )
                                            ) ?>
                                        </span>

                                        <span>
                                            <i class="bi bi-person me-1"></i>
                                            <?= e($request["requestor_name"]) ?>
                                        </span>

                                        <span>
                                            <i class="bi bi-building me-1"></i>
                                            <?= e($request["department"]) ?>
                                        </span>

                                        <span class="deadline-pill <?= e($deadline["class"]) ?>">
                                            <i class="bi <?= e($deadline["icon"]) ?>"></i>

                                            <?= e($deadline["label"]) ?>
                                        </span>

                                    </div>

                                    <div class="current-assignment">

                                        <div>
                                            <i class="bi bi-person-check me-1 text-primary"></i>

                                            Current Assignee:
                                            <strong>
                                                <?= e($currentAssignee) ?>
                                            </strong>

                                            <?php if ($request["assigned_at"]): ?>

                                                <span class="text-muted">
                                                    •
                                                    assigned
                                                    <?= e(
                                                        assignmentDate(
                                                            $request["assigned_at"],
                                                            "M d, Y g:i A"
                                                        )
                                                    ) ?>
                                                </span>

                                            <?php endif; ?>

                                        </div>

                                        <?php if ($request["assignment_notes"]): ?>

                                            <div class="assignment-notes-view">

                                                <strong>
                                                    Instructions:
                                                </strong>

                                                <?= nl2br(
                                                    e(
                                                        $request["assignment_notes"]
                                                    )
                                                ) ?>

                                            </div>

                                        <?php endif; ?>

                                    </div>

                                </div>

                                <form method="post" class="assignment-panel">

                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

                                    <input type="hidden" name="request_id" value="<?= (int) $request["id"] ?>">

                                    <input type="hidden" name="return_query" value="<?= e($returnQuery) ?>">

                                    <div class="assignment-panel-title">

                                        <span>
                                            <i class="bi bi-person-gear me-1 text-primary"></i>
                                            Work Assignment
                                        </span>

                                        <span class="assignment-state">
                                            <span class="dirty-dot" title="Unsaved changes"></span>
                                            <?= $request["assigned_to"] ? "Assigned" : "Unassigned" ?>
                                        </span>

                                    </div>

                                    <div class="row g-2">

                                        <div class="col-8">

                                            <label class="filter-label" for="assign-who-<?= (int) $request["id"] ?>">
                                                Assigned HPU Personnel
                                            </label>

                                            <select id="assign-who-<?= (int) $request["id"] ?>" name="assigned_to" class="form-select form-select-sm">

                                                <option value="0">
                                                    — Unassigned —
                                                </option>

                                                <?php foreach ($staff as $person): ?>

                                                    <option value="<?= (int) $person["id"] ?>" <?= (int) $request["assigned_to"] === (int) $person["id"] ? "selected" : "" ?>>
                                                        <?= e($person["name"]) ?>
                                                        • <?= (int) $person["active_total"] ?> active

                                                        <?php if ((int) $person["overdue_total"] > 0): ?>
                                                            • <?= (int) $person["overdue_total"] ?> overdue
                                                        <?php endif; ?>
                                                    </option>

                                                <?php endforeach; ?>

                                            </select>

                                        </div>

                                        <div class="col-4">

                                            <label class="filter-label" for="assign-pri-<?= (int) $request["id"] ?>">
                                                Priority
                                            </label>

                                            <select id="assign-pri-<?= (int) $request["id"] ?>" name="priority" class="form-select form-select-sm">

                                                <?php foreach (["Low", "Normal", "High", "Urgent"] as $priority): ?>

                                                    <option value="<?= e($priority) ?>" <?= $request["priority"] === $priority ? "selected" : "" ?>>
                                                        <?= e($priority) ?>
                                                    </option>

                                                <?php endforeach; ?>

                                            </select>

                                        </div>

                                        <div class="col-12">

                                            <label class="filter-label" for="assign-notes-<?= (int) $request["id"] ?>">
                                                Assignment Instructions
                                            </label>

                                            <textarea id="assign-notes-<?= (int) $request["id"] ?>" class="form-control form-control-sm" name="assignment_notes" rows="2"
                                                maxlength="2000"
                                                placeholder="Specific instructions, deliverables or coordination notes..."><?= e($request["assignment_notes"]) ?></textarea>

                                        </div>

                                    </div>

                                    <div class="assignment-footer">

                                        <button class="btn btn-sm btn-primary flex-grow-1" type="submit">
                                            <i class="bi bi-check2-circle me-1"></i>
                                            Save Assignment
                                        </button>

                                        <a class="btn btn-sm btn-outline-secondary view-request"
                                            href="<?= e(BASE_URL) ?>/staff/review_request.php?id=<?= (int) $request["id"] ?>"
                                            title="Open full job request">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                            View
                                        </a>

                                    </div>

                                </form>

                            </article>

                        <?php endforeach; ?>

                        <?php if ($totalPages > 1): ?>

                            <?php
                            $pageWindow = [1];

                            foreach ([$page - 1, $page, $page + 1, $totalPages] as $windowPage) {
                                if ($windowPage > 1 && $windowPage < $totalPages) {
                                    $pageWindow[] = $windowPage;
                                }
                            }

                            $pageWindow[] = $totalPages;
                            $pageWindow = array_values(array_unique($pageWindow));
                            sort($pageWindow);
                            ?>

                            <nav class="pagination-bar" aria-label="Assignment queue pages">
                                <span class="pagination-meta">
                                    Page <?= (int) $page ?> of <?= (int) $totalPages ?>
                                </span>

                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?= $page <= 1 ? "disabled" : "" ?>">
                                        <a class="page-link"
                                            href="<?= $page <= 1 ? "#" : e(assignBuildQuery(["page" => $page - 1])) ?>"
                                            <?= $page <= 1 ? 'tabindex="-1" aria-disabled="true"' : "" ?>
                                            aria-label="Previous page">
                                            <i class="bi bi-chevron-left"></i>
                                        </a>
                                    </li>

                                    <?php $previousWindowPage = 0; ?>
                                    <?php foreach ($pageWindow as $windowPage): ?>
                                        <?php if ($windowPage - $previousWindowPage > 1): ?>
                                            <li class="page-item disabled"><span class="page-link">…</span></li>
                                        <?php endif; ?>
                                        <li class="page-item <?= $windowPage === $page ? "active" : "" ?>" <?= $windowPage === $page ? 'aria-current="page"' : "" ?>>
                                            <a class="page-link" href="<?= e(assignBuildQuery(["page" => $windowPage === 1 ? null : $windowPage])) ?>">
                                                <?= (int) $windowPage ?>
                                            </a>
                                        </li>
                                        <?php $previousWindowPage = $windowPage; ?>
                                    <?php endforeach; ?>

                                    <li class="page-item <?= $page >= $totalPages ? "disabled" : "" ?>">
                                        <a class="page-link"
                                            href="<?= $page >= $totalPages ? "#" : e(assignBuildQuery(["page" => $page + 1])) ?>"
                                            <?= $page >= $totalPages ? 'tabindex="-1" aria-disabled="true"' : "" ?>
                                            aria-label="Next page">
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                </ul>
                            </nav>

                        <?php endif; ?>

                    </section>

                </div>

            </main>

        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener(
            "DOMContentLoaded",
            function () {

                /*
                |--------------------------------------------------------------------------
                | Assignment form change feedback
                |--------------------------------------------------------------------------
                */

                document
                    .querySelectorAll(
                        ".assignment-panel"
                    )
                    .forEach(
                        function (form) {

                            const button =
                                form.querySelector(
                                    'button[type="submit"]'
                                );

                            const original =
                                new FormData(form);

                            const originalValues = {
                                assigned_to:
                                    original.get(
                                        "assigned_to"
                                    ),

                                priority:
                                    original.get(
                                        "priority"
                                    ),

                                assignment_notes:
                                    original.get(
                                        "assignment_notes"
                                    )
                            };

                            function detectChange() {
                                const current =
                                    new FormData(form);

                                const changed =
                                    String(
                                        current.get(
                                            "assigned_to"
                                        )
                                    )
                                    !==
                                    String(
                                        originalValues.assigned_to
                                    )
                                    ||
                                    String(
                                        current.get(
                                            "priority"
                                        )
                                    )
                                    !==
                                    String(
                                        originalValues.priority
                                    )
                                    ||
                                    String(
                                        current.get(
                                            "assignment_notes"
                                        )
                                    )
                                    !==
                                    String(
                                        originalValues.assignment_notes
                                    );

                                button.classList.toggle(
                                    "btn-primary",
                                    changed
                                );

                                button.classList.toggle(
                                    "btn-outline-primary",
                                    !changed
                                );

                                button.innerHTML =
                                    changed
                                        ? '<i class="bi bi-check2-circle me-1"></i>Save Changes'
                                        : '<i class="bi bi-check2-circle me-1"></i>Save Assignment';
                            }

                            form
                                .querySelectorAll(
                                    "select,textarea"
                                )
                                .forEach(
                                    function (control) {
                                        control.addEventListener(
                                            "input",
                                            detectChange
                                        );

                                        control.addEventListener(
                                            "change",
                                            detectChange
                                        );
                                    }
                                );

                            form.addEventListener(
                                "submit",
                                function () {
                                    button.disabled =
                                        true;

                                    button.innerHTML =
                                        '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
                                }
                            );
                        }
                    );
            }
        );
    </script>
    <script>
        /* =====================================================
           ASSIGN JOBS — UX UPGRADE (additive, no backend change)
        ===================================================== */
        document.addEventListener("DOMContentLoaded", function () {
            function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
            function esc(s) { return String(s == null ? "" : s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;"); }

            /* ---------- density ---------- */
            var densityBtn = document.getElementById("densityToggle");
            function setDensity(on, save) {
                document.body.classList.toggle("ultra-compact", !!on);
                if (densityBtn) {
                    densityBtn.setAttribute("aria-pressed", on ? "true" : "false");
                    densityBtn.classList.toggle("btn-secondary", !!on);
                }
                try { if (save !== false) localStorage.setItem("assign_density", on ? "1" : "0"); } catch (e) { }
            }
            var savedDensity = null;
            try { savedDensity = localStorage.getItem("assign_density"); } catch (e) { }
            setDensity(savedDensity === "1", false);
            if (densityBtn) densityBtn.addEventListener("click", function () {
                setDensity(!document.body.classList.contains("ultra-compact"));
            });

            /* ---------- filter form: auto-submit selects ---------- */
            var filterForm = document.querySelector("form.filter-bar");
            var searchInput = document.getElementById("assignSearchInput");
            var searchClear = document.getElementById("assignSearchClear");
            if (filterForm) {
                $all("select", filterForm).forEach(function (sel) {
                    sel.addEventListener("change", function () { filterForm.submit(); });
                });
            }
            function syncClear() {
                if (!searchClear || !searchInput) return;
                searchClear.classList.toggle("show", !!(searchInput.value || "").trim());
            }
            if (searchInput) {
                searchInput.addEventListener("input", function () { syncClear(); scheduleInstant(); });
                syncClear();
            }
            if (searchClear && searchInput) {
                searchClear.addEventListener("click", function () {
                    searchInput.value = "";
                    syncClear();
                    applyInstant("");
                    searchInput.focus();
                });
            }
            document.addEventListener("keydown", function (ev) {
                var tag = ((document.activeElement || {}).tagName || "").toUpperCase();
                var typing = tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT";
                if (ev.key === "/" && !typing && searchInput) { ev.preventDefault(); searchInput.focus(); }
                if (ev.key === "Escape" && document.activeElement === searchInput && searchInput) {
                    searchInput.value = "";
                    syncClear();
                    applyInstant("");
                }
            });

            /* ---------- active filter chips ---------- */
            try {
                var params = new URLSearchParams(window.location.search);
                var chipDefs = [];
                function optText(name, value) {
                    var sel = filterForm ? filterForm.querySelector('select[name="' + name + '"]') : null;
                    if (!sel) return value;
                    var opt = sel.querySelector('option[value="' + value.replace(/"/g, '\\"') + '"]');
                    return opt ? opt.textContent.trim().replace(/\s+/g, " ") : value;
                }
                if (params.get("q")) chipDefs.push({ key: "q", label: "Search", value: params.get("q") });
                if (params.get("request_type")) chipDefs.push({ key: "request_type", label: "Type", value: params.get("request_type") });
                if (params.get("assigned_to") && params.get("assigned_to") !== "0") chipDefs.push({ key: "assigned_to", label: "Person", value: optText("assigned_to", params.get("assigned_to")) });
                if (params.get("assignment")) chipDefs.push({ key: "assignment", label: "Assignment", value: params.get("assignment") });
                if (params.get("priority")) chipDefs.push({ key: "priority", label: "Priority", value: params.get("priority") });
                if (params.get("deadline")) chipDefs.push({ key: "deadline", label: "Deadline", value: params.get("deadline") });
                if (params.get("status")) chipDefs.push({ key: "status", label: "Status", value: params.get("status") });
                if (params.get("sort") && params.get("sort") !== "newest") chipDefs.push({ key: "sort", label: "Sort", value: params.get("sort") });
                if (chipDefs.length && filterForm && filterForm.parentElement) {
                    var bar = document.createElement("div");
                    bar.className = "active-chips show";
                    var html = '<span class="active-chips-label">Active:</span>';
                    chipDefs.forEach(function (c) {
                        var p2 = new URLSearchParams(window.location.search);
                        p2.delete(c.key);
                        var href = window.location.pathname + (p2.toString() ? "?" + p2.toString() : "");
                        html += '<span class="filter-chip" title="' + esc(c.label + ": " + c.value) + '"><span>' + esc(c.label + ": " + c.value) + '</span><a href="' + esc(href) + '" title="Remove">&times;</a></span>';
                    });
                    if (chipDefs.length > 1) {
                        html += '<span class="filter-chip" style="background:#f1f5f9;color:#475569;"><span>Clear all</span><a href="' + esc(window.location.pathname) + '" title="Clear all">&times;</a></span>';
                    }
                    bar.innerHTML = html;
                    filterForm.parentElement.insertBefore(bar, filterForm.nextSibling);
                }
            } catch (e) { }

            /* ---------- workload bars + least-loaded suggestion ---------- */
            try {
                var persons = $all(".workload-person");
                var loads = persons.map(function (a) {
                    var t = (a.querySelector(".staff-load") || {}).textContent || "";
                    var m = t.match(/(\d+)\s*active/);
                    return m ? parseInt(m[1], 10) : 0;
                });
                var maxLoad = Math.max.apply(null, loads.concat([1]));
                var minLoad = loads.length ? Math.min.apply(null, loads) : 0;
                var firstMin = true;
                persons.forEach(function (a, i) {
                    var load = loads[i] || 0;
                    var wrap = document.createElement("div");
                    wrap.className = "workload-bar" + (load >= 5 ? " hot" : (load >= 3 ? " warn" : ""));
                    var fill = document.createElement("i");
                    fill.style.width = Math.max(6, Math.round(load / maxLoad * 100)) + "%";
                    wrap.appendChild(fill);
                    var info = a.querySelector(".staff-info");
                    if (info) info.appendChild(wrap);
                    a.title = a.title || ((a.querySelector(".staff-name") || {}).textContent || "Staff") + " — " + load + " active";
                    if (load === minLoad && firstMin && persons.length > 1) {
                        firstMin = false;
                        var badge = document.createElement("span");
                        badge.className = "suggest-badge";
                        badge.textContent = "LEAST LOADED";
                        a.appendChild(badge);
                    }
                    try {
                        var href = a.getAttribute("href") || "";
                        var q = new URLSearchParams(window.location.search);
                        var m2 = href.match(/assigned_to=(\d+)/);
                        if (m2 && q.get("assigned_to") === m2[1]) a.classList.add("is-filtered");
                    } catch (e2) { }
                });
            } catch (e) { }

            /* ---------- rows: search index, copy, select, counters, dirty, confirm ---------- */
            var rows = $all(".request-row");
            var queueInfo = document.querySelector(".queue-info");
            var liveCountEl = null;
            if (queueInfo) {
                var firstDiv = queueInfo.querySelector("div");
                if (firstDiv) {
                    var strong = firstDiv.querySelector("strong");
                    if (strong) {
                        strong.classList.add("live-count");
                        liveCountEl = strong;
                    }
                }
                var lastDiv = queueInfo.querySelectorAll("div");
                if (lastDiv.length > 1) {
                    var note = lastDiv[lastDiv.length - 1];
                    note.innerHTML += ' <span style="margin-left:6px;"><kbd>/</kbd> search &nbsp;<kbd>esc</kbd> clear</span>';
                }
            }
            var resultPill = document.querySelector(".result-pill");

            var toast = document.createElement("div");
            toast.className = "toast-copy";
            toast.innerHTML = '<i class="bi bi-clipboard-check"></i><span></span>';
            document.body.appendChild(toast);
            var toastTimer = null;
            function showToast(msg) {
                toast.querySelector("span").textContent = msg;
                toast.classList.add("show");
                clearTimeout(toastTimer);
                toastTimer = setTimeout(function () { toast.classList.remove("show"); }, 1600);
            }
            document.addEventListener("click", function (ev) {
                var btn = ev.target.closest ? ev.target.closest("[data-copy]") : null;
                if (!btn) return;
                var text = btn.getAttribute("data-copy") || "";
                if (!text) return;
                function done() { showToast("Copied " + text); }
                if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, function () { fallback(); });
                else fallback();
                function fallback() {
                    var ta = document.createElement("textarea");
                    ta.value = text;
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand("copy"); done(); } catch (e) { }
                    ta.remove();
                }
            });

            /* bulk bar — one server submit for all selected rows */
            var queueShell = document.querySelector(".queue-shell");
            var bulkBar = document.createElement("form");
            bulkBar.className = "bulk-bar";
            bulkBar.method = "post";
            bulkBar.action = window.location.pathname + window.location.search;
            var bulkCsrf = "";
            var bulkReturn = "";
            try {
                var bulkCsrfInput = document.querySelector('.assignment-panel input[name="csrf_token"]');
                if (bulkCsrfInput) bulkCsrf = bulkCsrfInput.value;
                var bulkReturnInput = document.querySelector('.assignment-panel input[name="return_query"]');
                if (bulkReturnInput) bulkReturn = bulkReturnInput.value;
            } catch (e) { }
            bulkBar.innerHTML = '<strong></strong>' +
                '<input type="hidden" name="csrf_token" value="' + esc(bulkCsrf) + '">' +
                '<input type="hidden" name="return_query" value="' + esc(bulkReturn) + '">' +
                '<input type="hidden" name="bulk_save" value="1">' +
                '<input type="hidden" name="bulk_ids" class="bulk-ids" value="">' +
                '<select class="form-select form-select-sm bulk-assignee" name="assigned_to" aria-label="Bulk assignee"></select>' +
                '<select class="form-select form-select-sm bulk-priority" name="priority" style="min-width:130px;" aria-label="Bulk priority">' +
                '<option value="">Priority…</option><option>Low</option><option>Normal</option><option>High</option><option>Urgent</option></select>' +
                '<button type="submit" class="btn btn-sm btn-primary bulk-apply"><i class="bi bi-lightning-charge me-1"></i>Apply to selected</button>' +
                '<button type="button" class="btn btn-sm btn-outline-secondary bulk-fill"><i class="bi bi-magic me-1"></i>Fill panels</button>' +
                '<button type="button" class="btn btn-sm btn-outline-secondary bulk-clear">Clear</button>' +
                '<span style="font-size:.62rem;color:#475569;">Apply saves once for all selected — instructions stay untouched.</span>';
            if (queueShell && queueInfo) queueShell.insertBefore(bulkBar, queueInfo.nextSibling);
            var bulkAssignee = bulkBar.querySelector(".bulk-assignee");
            var bulkPriority = bulkBar.querySelector(".bulk-priority");
            var bulkIdsInput = bulkBar.querySelector(".bulk-ids");
            try {
                var firstPanel = document.querySelector(".assignment-panel select[name=assigned_to]");
                if (firstPanel && bulkAssignee) bulkAssignee.innerHTML = '<option value="">Assignee…</option>' + firstPanel.innerHTML;
            } catch (e) { }
            function selectedRows() { return rows.filter(function (r) { return r.classList.contains("is-selected"); }); }
            function selectedIds() {
                return selectedRows().map(function (r) {
                    var hid = r.querySelector('.assignment-panel input[name="request_id"]');
                    return hid ? hid.value : null;
                }).filter(function (v) { return v; });
            }
            function refreshBulk() {
                var n = selectedRows().length;
                bulkBar.classList.toggle("show", n > 0);
                bulkBar.querySelector("strong").textContent = n + " selected";
                if (bulkIdsInput) bulkIdsInput.value = selectedIds().join(",");
            }
            bulkBar.querySelector(".bulk-clear").addEventListener("click", function () {
                rows.forEach(function (r) {
                    r.classList.remove("is-selected");
                    var cb = r.querySelector(".row-select input");
                    if (cb) cb.checked = false;
                });
                refreshBulk();
            });
            bulkBar.querySelector(".bulk-fill").addEventListener("click", function () {
                var who = bulkAssignee ? bulkAssignee.value : "";
                var pri = bulkPriority ? bulkPriority.value : "";
                if (who === "" && pri === "") { showToast("Pick an assignee or priority first"); return; }
                var n = 0;
                selectedRows().forEach(function (r) {
                    var panel = r.querySelector(".assignment-panel");
                    if (!panel) return;
                    if (who !== "") {
                        var sel = panel.querySelector("select[name=assigned_to]");
                        if (sel) { sel.value = who; try { sel.dispatchEvent(new Event("change", { bubbles: true })); } catch (e) { } try { sel.dispatchEvent(new Event("input", { bubbles: true })); } catch (e) { } }
                    }
                    if (pri !== "") {
                        var ps = panel.querySelector("select[name=priority]");
                        if (ps) { ps.value = pri; try { ps.dispatchEvent(new Event("change", { bubbles: true })); } catch (e) { } try { ps.dispatchEvent(new Event("input", { bubbles: true })); } catch (e) { } }
                    }
                    n++;
                });
                showToast("Filled " + n + " panel" + (n === 1 ? "" : "s") + " — review & Save per row");
            });
            bulkBar.addEventListener("submit", function (ev) {
                var who = bulkAssignee ? bulkAssignee.value : "";
                var pri = bulkPriority ? bulkPriority.value : "";
                if (who === "" && pri === "") { ev.preventDefault(); showToast("Pick an assignee or priority first"); return; }
                var selCount = selectedRows().length;
                if (!selCount) { ev.preventDefault(); showToast("Select at least one row"); return; }
                if (bulkIdsInput) bulkIdsInput.value = selectedIds().join(",");
                if (who === "0") {
                    if (!window.confirm("Remove assignments for " + selCount + " selected request(s)?")) ev.preventDefault();
                } else if (!window.confirm("Apply bulk update to " + selCount + " selected request(s)?")) {
                    ev.preventDefault();
                }
            });

            rows.forEach(function (row) {
                row.setAttribute("data-search", (row.textContent || "").toLowerCase().replace(/\s+/g, " "));
                var jobEl = row.querySelector(".job-no");
                if (jobEl && !row.querySelector("[data-copy]")) {
                    var jobText = jobEl.textContent.trim();
                    var cp = document.createElement("button");
                    cp.type = "button";
                    cp.className = "job-no-copy";
                    cp.setAttribute("data-copy", jobText);
                    cp.title = "Copy " + jobText;
                    cp.innerHTML = '<i class="bi bi-clipboard"></i>';
                    jobEl.appendChild(document.createTextNode(" "));
                    jobEl.appendChild(cp);
                }
                if (!row.querySelector(".row-select")) {
                    var lab = document.createElement("label");
                    lab.className = "row-select";
                    lab.title = "Select for bulk fill";
                    lab.innerHTML = '<input type="checkbox" aria-label="Select row"> Select';
                    row.appendChild(lab);
                    lab.querySelector("input").addEventListener("change", function (ev) {
                        row.classList.toggle("is-selected", ev.target.checked);
                        refreshBulk();
                    });
                }
                var panel = row.querySelector(".assignment-panel");
                if (panel) {
                    var notes = panel.querySelector("textarea[name=assignment_notes]");
                    if (notes && !panel.querySelector(".notes-counter")) {
                        var counter = document.createElement("div");
                        counter.className = "notes-counter";
                        notes.parentElement.appendChild(counter);
                        (function (ta, c) {
                            function upd() {
                                var len = (ta.value || "").length;
                                c.textContent = len.toLocaleString() + " / 2,000";
                                c.classList.toggle("over", len > 2000);
                            }
                            ta.addEventListener("input", upd);
                            upd();
                        })(notes, counter);
                    }
                    var initial = {
                        who: (panel.querySelector("select[name=assigned_to]") || {}).value,
                        pri: (panel.querySelector("select[name=priority]") || {}).value,
                        note: (panel.querySelector("textarea[name=assignment_notes]") || {}).value
                    };
                    panel.addEventListener("input", checkDirty);
                    panel.addEventListener("change", checkDirty);
                    function checkDirty() {
                        var who = (panel.querySelector("select[name=assigned_to]") || {}).value;
                        var pri = (panel.querySelector("select[name=priority]") || {}).value;
                        var note = (panel.querySelector("textarea[name=assignment_notes]") || {}).value;
                        panel.classList.toggle("is-dirty", String(who) !== String(initial.who) || String(pri) !== String(initial.pri) || String(note) !== String(initial.note));
                    }
                    panel.addEventListener("submit", function (ev) {
                        var sel = panel.querySelector("select[name=assigned_to]");
                        if (sel && sel.value === "0" && String(initial.who) !== "0") {
                            var title = (row.querySelector(".job-title") || {}).textContent || "this request";
                            if (!window.confirm("Remove the assignment for " + title.trim().substring(0, 60) + "?")) ev.preventDefault();
                        }
                    });
                }
            });
            window.addEventListener("beforeunload", function (ev) {
                if (document.querySelector(".assignment-panel.is-dirty")) {
                    ev.preventDefault();
                    ev.returnValue = "";
                }
            });

            /* ---------- instant client filter ---------- */
            var noMatch = document.createElement("div");
            noMatch.className = "empty-state";
            noMatch.style.display = "none";
            noMatch.innerHTML = '<i class="bi bi-funnel"></i><strong class="d-block text-dark">No rows match your typing</strong><span>This page holds ' + rows.length + ' row(s) — clear the search box or use filters to see more.</span>';
            var resultPillHtml = resultPill ? resultPill.innerHTML : "";
            if (queueShell) queueShell.appendChild(noMatch);
            var instantT = null;
            function scheduleInstant() {
                clearTimeout(instantT);
                instantT = setTimeout(function () { applyInstant((searchInput.value || "").trim().toLowerCase()); }, 150);
            }
            function applyInstant(q) {
                var shown = 0;
                rows.forEach(function (r) {
                    var hit = !q || (r.getAttribute("data-search") || "").indexOf(q) !== -1;
                    r.style.display = hit ? "" : "none";
                    if (hit) shown++;
                });
                if (resultPill) {
                    if (q) {
                        resultPill.innerHTML = '<i class="bi bi-list-check"></i> ' + shown + " match typing";
                    } else if (resultPillHtml) {
                        resultPill.innerHTML = resultPillHtml;
                    }
                }
                if (noMatch) noMatch.style.display = (q && shown === 0) ? "" : "none";
                refreshBulk();
            }
        });
    </script>

</body>

</html>