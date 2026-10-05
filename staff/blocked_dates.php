<?php

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
include "../includes/hpu_availability.php";
require_once "../includes/module_access.php";

requireRole([
    "staff",
    "supervisor",
    "admin"
]);
requireModule($conn, "blocked_dates");

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

hpuEnsureBlockedDatesTable(
    $conn
);

$userId =
    (int) (
        $_SESSION["user_id"]
        ?? 0
    );

$role =
    (string) (
        $_SESSION["role"]
        ?? ""
    );

$isStaff =
    $role === "staff";

$isManager =
    in_array(
        $role,
        [
            "supervisor",
            "admin"
        ],
        true
    );

$serviceOptions =
    hpuAvailabilityServiceOptions();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function bdTableExists(
    mysqli $conn,
    string $table
): bool {
    $stmt =
        $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?"
        );

    $stmt->bind_param(
        "s",
        $table
    );

    $stmt->execute();

    $row =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    return
        (int) (
            $row["total"]
            ?? 0
        ) > 0;
}

function bdColumnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $stmt =
        $conn->prepare(
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

    $row =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    return
        (int) (
            $row["total"]
            ?? 0
        ) > 0;
}

function bdRedirect(
    string $query = ""
): never {
    header(
        "Location: "
        . BASE_URL
        . "/staff/blocked_dates.php"
        . $query
    );

    exit;
}

function bdFlash(
    string $type,
    string $message,
    string $query = ""
): never {
    $_SESSION["blocked_dates_flash"] = [
        "type" => $type,
        "message" => $message
    ];

    bdRedirect(
        $query
    );
}

function bdValidateCsrf(
    string $csrf
): void {
    $posted =
        (string) (
            $_POST["csrf_token"]
            ?? ""
        );

    if (
        $posted === ""
        ||
        !hash_equals(
            $csrf,
            $posted
        )
    ) {
        bdFlash(
            "danger",
            "Security token expired. Refresh the page and try again."
        );
    }
}

function bdCanManageRecord(
    array $block,
    int $userId,
    bool $isManager
): bool {
    if ($isManager) {
        return true;
    }

    return
        (int) (
            $block["blocked_by"]
            ?? 0
        )
        ===
        $userId;
}

function bdDateLabel(
    string $start,
    string $end
): string {
    if ($start === $end) {
        return
            date(
                "M d, Y",
                strtotime(
                    $start
                )
            );
    }

    return
        date(
            "M d, Y",
            strtotime(
                $start
            )
        )
        . " – "
        . date(
            "M d, Y",
            strtotime(
                $end
            )
        );
}

function bdServiceClass(
    string $service
): string {
    return match ($service) {
        "All HPU Services" =>
        "service-all",

        "Graphic Design" =>
        "service-graphic",

        "Video Production" =>
        "service-video",

        "Photo/Video Coverage" =>
        "service-coverage",

        "IEC Materials" =>
        "service-iec",

        "Publication Submission" =>
        "service-publication",

        default =>
        "service-other"
    };
}

function bdServiceIcon(
    string $service
): string {
    return match ($service) {
        "All HPU Services" =>
        "bi-slash-circle",

        "Graphic Design" =>
        "bi-palette",

        "Video Production" =>
        "bi-camera-reels",

        "Photo/Video Coverage" =>
        "bi-camera",

        "IEC Materials" =>
        "bi-file-earmark-richtext",

        "Publication Submission" =>
        "bi-megaphone",

        default =>
        "bi-calendar-x"
    };
}

/*
|--------------------------------------------------------------------------
| Ensure optional audit column
|--------------------------------------------------------------------------
| This preserves the original blocked_by as the creator.
| updated_by is only used for the latest editor.
|--------------------------------------------------------------------------
*/

if (
    !bdColumnExists(
        $conn,
        "hpu_blocked_dates",
        "updated_by"
    )
) {
    $conn->query(
        "ALTER TABLE hpu_blocked_dates
         ADD COLUMN updated_by INT NULL
         AFTER blocked_by"
    );
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (
    empty(
    $_SESSION["blocked_dates_csrf"]
)
) {
    $_SESSION["blocked_dates_csrf"] =
        bin2hex(
            random_bytes(32)
        );
}

$csrf =
    (string) 
    $_SESSION["blocked_dates_csrf"];

/*
|--------------------------------------------------------------------------
| AJAX: Existing Job Conflict Awareness
|--------------------------------------------------------------------------
| This does NOT block creation of an availability rule.
| It only warns the HPU user that active requests already exist.
|--------------------------------------------------------------------------
*/

if (
    isset(
    $_GET["ajax"]
)
    &&
    $_GET["ajax"]
    === "conflicts"
) {
    header(
        "Content-Type: application/json; charset=utf-8"
    );

    $startDate =
        hpuAvailabilityNormalizeDate(
            (string) (
                $_GET["start_date"]
                ?? ""
            )
        );

    $endDate =
        hpuAvailabilityNormalizeDate(
            (string) (
                $_GET["end_date"]
                ?? ""
            )
        );

    $service =
        trim(
            (string) (
                $_GET["service"]
                ?? ""
            )
        );

    if (
        $startDate === null
        ||
        $endDate === null
        ||
        !in_array(
            $service,
            $serviceOptions,
            true
        )
    ) {
        http_response_code(
            422
        );

        echo json_encode(
            [
                "success" => false,
                "count" => 0,
                "items" => [],
                "message" => "Invalid date range or service."
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    if (
        strtotime(
            $endDate
        )
        <
        strtotime(
            $startDate
        )
    ) {
        http_response_code(
            422
        );

        echo json_encode(
            [
                "success" => false,
                "count" => 0,
                "items" => [],
                "message" => "End date cannot be earlier than start date."
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    if (
        !bdTableExists(
            $conn,
            "job_requests"
        )
    ) {
        echo json_encode(
            [
                "success" => true,
                "count" => 0,
                "items" => [],
                "message" => ""
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    $hasEventDate =
        bdColumnExists(
            $conn,
            "job_requests",
            "event_datetime"
        );

    $hasEventEnd =
        bdColumnExists(
            $conn,
            "job_requests",
            "event_datetime_end"
        );

    $hasCompletion =
        bdColumnExists(
            $conn,
            "job_requests",
            "date_completion"
        );

    $hasRequestTypes =
        bdColumnExists(
            $conn,
            "job_requests",
            "request_types"
        );

    if (
        !$hasEventDate
        &&
        !$hasCompletion
    ) {
        echo json_encode(
            [
                "success" => true,
                "count" => 0,
                "items" => [],
                "message" => ""
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    $dateConditions = [];
    $types = "";
    $params = [];

    if ($hasEventDate) {
        // Multi-day events block every day they span (end defaults
        // to the start date for single-day events).
        $eventEndExpr = $hasEventEnd
            ? "COALESCE(jr.event_datetime_end, jr.event_datetime)"
            : "jr.event_datetime";

        $dateConditions[] =
            "(
                jr.event_datetime IS NOT NULL
                AND DATE(jr.event_datetime) <= ?
                AND DATE({$eventEndExpr}) >= ?
             )";

        $types .= "ss";
        array_push(
            $params,
            $endDate,
            $startDate
        );
    }

    if ($hasCompletion) {
        $dateConditions[] =
            "(
                jr.date_completion IS NOT NULL
                AND jr.date_completion
                    BETWEEN ? AND ?
             )";

        $types .= "ss";
        array_push(
            $params,
            $startDate,
            $endDate
        );
    }

    $where = [
        "("
        . implode(
            " OR ",
            $dateConditions
        )
        . ")"
    ];

    if (
        bdColumnExists(
            $conn,
            "job_requests",
            "approval_status"
        )
    ) {
        $where[] =
            "COALESCE(
                jr.approval_status,
                ''
             )
             NOT IN (
                'Completed',
                'Closed',
                'Disapproved'
             )";
    }

    if (
        bdColumnExists(
            $conn,
            "job_requests",
            "archived"
        )
    ) {
        $where[] =
            "COALESCE(
                jr.archived,
                0
             ) = 0";
    }

    if (
        $service !== "All HPU Services"
        &&
        $hasRequestTypes
    ) {
        $where[] =
            "jr.request_types LIKE ?";

        $types .= "s";
        $params[] =
            '%"'
            . $service
            . '"%';
    }

    $selectStatus =
        bdColumnExists(
            $conn,
            "job_requests",
            "approval_status"
        )
        ? "jr.approval_status"
        : "''";

    $selectJobNo =
        bdColumnExists(
            $conn,
            "job_requests",
            "job_no"
        )
        ? "jr.job_no"
        : "CONCAT('#', jr.id)";

    $selectTitle =
        bdColumnExists(
            $conn,
            "job_requests",
            "project_title"
        )
        ? "jr.project_title"
        : "''";

    $sql = "
        SELECT
            jr.id,
            {$selectJobNo} AS job_no,
            {$selectTitle} AS project_title,
            {$selectStatus} AS approval_status
        FROM job_requests jr
        WHERE
            "
        . implode(
            " AND ",
            $where
        )
        . "
        ORDER BY
            jr.id DESC
        LIMIT 8
    ";

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

    $items =
        $stmt
            ->get_result()
            ->fetch_all(
                MYSQLI_ASSOC
            );

    $stmt->close();

    /*
     * Separate total count because the preview is limited.
     */
    $countSql = "
        SELECT COUNT(*) AS total
        FROM job_requests jr
        WHERE
            "
        . implode(
            " AND ",
            $where
        );

    $countStmt =
        $conn->prepare(
            $countSql
        );

    if ($params) {
        $countStmt->bind_param(
            $types,
            ...$params
        );
    }

    $countStmt->execute();

    $count =
        (int) (
            $countStmt
                ->get_result()
                ->fetch_assoc()["total"]
            ?? 0
        );

    $countStmt->close();

    echo json_encode(
        [
            "success" => true,
            "count" => $count,
            "items" => $items,
            "message" =>
                $count > 0
                ? $count
                . " existing active job request"
                . (
                    $count === 1
                    ? ""
                    : "s"
                )
                . " overlap this date/range. Blocking the date will not change those existing requests."
                : ""
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| POST Actions
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"]
    === "POST"
) {
    bdValidateCsrf(
        $csrf
    );

    $action =
        trim(
            (string) (
                $_POST["action"]
                ?? ""
            )
        );

    /*
    |--------------------------------------------------------------------------
    | Add / Edit Block
    |--------------------------------------------------------------------------
    */

    if ($action === "save") {
        $id =
            (int) (
                $_POST["id"]
                ?? 0
            );

        $startDate =
            hpuAvailabilityNormalizeDate(
                (string) (
                    $_POST["start_date"]
                    ?? ""
                )
            );

        $endDate =
            hpuAvailabilityNormalizeDate(
                (string) (
                    $_POST["end_date"]
                    ?? ""
                )
            );

        $serviceType =
            trim(
                (string) (
                    $_POST["service_type"]
                    ?? ""
                )
            );

        $reason =
            trim(
                (string) (
                    $_POST["reason"]
                    ?? ""
                )
            );


        $blockMode =
            trim(
                (string) (
                    $_POST["block_mode"]
                    ?? "date_range"
                )
            );

        $weekdays =
            $_POST["weekdays"]
            ?? [];

        if (!is_array($weekdays)) {
            $weekdays = [];
        }

        $allowedWeekdays = [
            "6", // Saturday
            "0"  // Sunday
        ];

        $weekdays =
            array_values(
                array_intersect(
                    array_map(
                        "strval",
                        $weekdays
                    ),
                    $allowedWeekdays
                )
            );

        if (
            $startDate === null
            ||
            $endDate === null
        ) {
            bdFlash(
                "danger",
                "Please enter a valid start and end date."
            );
        }

        if (
            strtotime(
                $endDate
            )
            <
            strtotime(
                $startDate
            )
        ) {
            bdFlash(
                "danger",
                "End date cannot be earlier than the start date."
            );
        }

        if (
            !in_array(
                $serviceType,
                $serviceOptions,
                true
            )
        ) {
            bdFlash(
                "danger",
                "Please select a valid HPU service."
            );
        }

        if ($reason === "") {
            bdFlash(
                "danger",
                "Please enter a reason for the blocked date."
            );
        }

        if (
            mb_strlen(
                $reason
            ) > 255
        ) {
            bdFlash(
                "danger",
                "Reason must not exceed 255 characters."
            );
        }

        $existing = null;

        if ($id > 0) {
            $stmt =
                $conn->prepare(
                    "SELECT *
                     FROM hpu_blocked_dates
                     WHERE id = ?
                     LIMIT 1"
                );

            $stmt->bind_param(
                "i",
                $id
            );

            $stmt->execute();

            $existing =
                $stmt
                    ->get_result()
                    ->fetch_assoc();

            $stmt->close();

            if (!$existing) {
                bdFlash(
                    "danger",
                    "Blocked date record not found."
                );
            }

            if (
                !bdCanManageRecord(
                    $existing,
                    $userId,
                    $isManager
                )
            ) {
                http_response_code(
                    403
                );

                exit(
                    "Staff may edit only blocked dates they created."
                );
            }
        }

        if (
            !in_array(
                $blockMode,
                [
                    "date_range",
                    "recurring_weekdays"
                ],
                true
            )
        ) {
            bdFlash(
                "danger",
                "Invalid blocking mode."
            );
        }

        if (
            $blockMode === "recurring_weekdays"
            &&
            !$weekdays
        ) {
            bdFlash(
                "danger",
                "Select Saturday, Sunday, or both for recurring weekend blocking."
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Recurring Saturday / Sunday Blocking
        |--------------------------------------------------------------------------
        | Each matching weekend date is stored as an individual row.
        | This allows Staff/Supervisor/Admin to modify one blocked day later
        | without changing the other weekend blocks.
        |--------------------------------------------------------------------------
        */
        if (
            $blockMode === "recurring_weekdays"
        ) {
            if ($id > 0) {
                bdFlash(
                    "warning",
                    "Recurring weekend mode is for adding new blocked days. Edit an existing blocked day individually instead."
                );
            }

            $periodStart =
                new DateTime(
                    $startDate
                );

            $periodEnd =
                new DateTime(
                    $endDate
                );

            $periodEnd->modify(
                "+1 day"
            );

            $period =
                new DatePeriod(
                    $periodStart,
                    new DateInterval(
                        "P1D"
                    ),
                    $periodEnd
                );

            $inserted = 0;
            $skipped = 0;

            $duplicateStmt =
                $conn->prepare(
                    "SELECT id
                     FROM hpu_blocked_dates
                     WHERE
                        service_type = ?
                        AND ? BETWEEN start_date AND end_date
                     LIMIT 1"
                );

            $insertStmt =
                $conn->prepare(
                    "INSERT INTO hpu_blocked_dates (
                        start_date,
                        end_date,
                        service_type,
                        reason,
                        blocked_by,
                        updated_by
                     )
                     VALUES (?, ?, ?, ?, ?, ?)"
                );

            foreach ($period as $dateObj) {
                $weekday =
                    $dateObj->format(
                        "w"
                    );

                if (
                    !in_array(
                        $weekday,
                        $weekdays,
                        true
                    )
                ) {
                    continue;
                }

                $blockDate =
                    $dateObj->format(
                        "Y-m-d"
                    );

                $duplicateStmt->bind_param(
                    "ss",
                    $serviceType,
                    $blockDate
                );

                $duplicateStmt->execute();

                $duplicate =
                    $duplicateStmt
                        ->get_result()
                        ->fetch_assoc();

                if ($duplicate) {
                    $skipped++;
                    continue;
                }

                $insertStmt->bind_param(
                    "ssssii",
                    $blockDate,
                    $blockDate,
                    $serviceType,
                    $reason,
                    $userId,
                    $userId
                );

                $insertStmt->execute();

                $inserted++;
            }

            $duplicateStmt->close();
            $insertStmt->close();

            if ($inserted <= 0) {
                bdFlash(
                    "warning",
                    "No new weekend dates were blocked. The selected dates may already be blocked or the range contains no selected weekend days."
                );
            }

            $message =
                $inserted
                . " weekend date"
                . (
                    $inserted === 1
                    ? ""
                    : "s"
                )
                . " blocked successfully.";

            if ($skipped > 0) {
                $message .=
                    " "
                    . $skipped
                    . " existing date"
                    . (
                        $skipped === 1
                        ? " was"
                        : "s were"
                    )
                    . " skipped.";
            }

            bdFlash(
                "success",
                $message
            );
        }

        /*
         * Prevent exact-service overlaps.
         */
        $overlapStmt =
            $conn->prepare(
                "SELECT
                    id,
                    start_date,
                    end_date
                 FROM hpu_blocked_dates
                 WHERE
                    service_type = ?
                    AND start_date <= ?
                    AND end_date >= ?
                    AND id <> ?
                 LIMIT 1"
            );

        $overlapStmt->bind_param(
            "sssi",
            $serviceType,
            $endDate,
            $startDate,
            $id
        );

        $overlapStmt->execute();

        $overlap =
            $overlapStmt
                ->get_result()
                ->fetch_assoc();

        $overlapStmt->close();

        if ($overlap) {
            bdFlash(
                "warning",
                "An overlapping "
                . $serviceType
                . " block already exists from "
                . bdDateLabel(
                    $overlap["start_date"],
                    $overlap["end_date"]
                )
                . "."
            );
        }

        if ($id > 0) {
            $stmt =
                $conn->prepare(
                    "UPDATE hpu_blocked_dates
                     SET
                        start_date = ?,
                        end_date = ?,
                        service_type = ?,
                        reason = ?,
                        updated_by = ?
                     WHERE id = ?"
                );

            $stmt->bind_param(
                "ssssii",
                $startDate,
                $endDate,
                $serviceType,
                $reason,
                $userId,
                $id
            );

            $stmt->execute();
            $stmt->close();

            bdFlash(
                "success",
                "Blocked date updated successfully."
            );
        }

        $stmt =
            $conn->prepare(
                "INSERT INTO hpu_blocked_dates (
                    start_date,
                    end_date,
                    service_type,
                    reason,
                    blocked_by,
                    updated_by
                 )
                 VALUES (?, ?, ?, ?, ?, ?)"
            );

        $stmt->bind_param(
            "ssssii",
            $startDate,
            $endDate,
            $serviceType,
            $reason,
            $userId,
            $userId
        );

        $stmt->execute();
        $stmt->close();

        bdFlash(
            "success",
            "Date / range blocked successfully."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Unblock
    |--------------------------------------------------------------------------
    */

    if ($action === "unblock") {
        $id =
            (int) (
                $_POST["id"]
                ?? 0
            );

        $stmt =
            $conn->prepare(
                "SELECT *
                 FROM hpu_blocked_dates
                 WHERE id = ?
                 LIMIT 1"
            );

        $stmt->bind_param(
            "i",
            $id
        );

        $stmt->execute();

        $existing =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        if (!$existing) {
            bdFlash(
                "warning",
                "Blocked date record not found."
            );
        }

        if (
            !bdCanManageRecord(
                $existing,
                $userId,
                $isManager
            )
        ) {
            http_response_code(
                403
            );

            exit(
                "Staff may unblock only blocked dates they created."
            );
        }

        $stmt =
            $conn->prepare(
                "DELETE FROM hpu_blocked_dates
                 WHERE id = ?"
            );

        $stmt->bind_param(
            "i",
            $id
        );

        $stmt->execute();
        $stmt->close();

        bdFlash(
            "success",
            "Date / range unblocked successfully."
        );
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$view =
    trim(
        (string) (
            $_GET["view"]
            ?? "current"
        )
    );

if (
    !in_array(
        $view,
        [
            "current",
            "today",
            "history",
            "all"
        ],
        true
    )
) {
    $view =
        "current";
}

$serviceFilter =
    trim(
        (string) (
            $_GET["service"]
            ?? ""
        )
    );

if (
    $serviceFilter !== ""
    &&
    !in_array(
        $serviceFilter,
        $serviceOptions,
        true
    )
) {
    $serviceFilter = "";
}

$q =
    trim(
        (string) (
            $_GET["q"]
            ?? ""
        )
    );

$where = [];
$types = "";
$params = [];

if ($view === "current") {
    $where[] =
        "b.end_date >= CURDATE()";
} elseif ($view === "today") {
    $where[] =
        "CURDATE()
         BETWEEN b.start_date
         AND b.end_date";
} elseif ($view === "history") {
    $where[] =
        "b.end_date < CURDATE()";
}

if ($serviceFilter !== "") {
    $where[] =
        "b.service_type = ?";

    $types .= "s";
    $params[] =
        $serviceFilter;
}

if ($q !== "") {
    $where[] = "(
        b.reason LIKE ?
        OR b.service_type LIKE ?
        OR creator.name LIKE ?
        OR editor.name LIKE ?
    )";

    $like =
        "%"
        . $q
        . "%";

    $types .= "ssss";

    array_push(
        $params,
        $like,
        $like,
        $like,
        $like
    );
}

$sql = "
    SELECT
        b.*,
        creator.name AS blocked_by_name,
        editor.name AS updated_by_name
    FROM hpu_blocked_dates b
    LEFT JOIN users creator
        ON creator.id = b.blocked_by
    LEFT JOIN users editor
        ON editor.id = b.updated_by
";

if ($where) {
    $sql .=
        " WHERE "
        . implode(
            " AND ",
            $where
        );
}

$sql .= "
    ORDER BY
        CASE
            WHEN CURDATE()
                BETWEEN b.start_date
                AND b.end_date
            THEN 0
            WHEN b.start_date > CURDATE()
            THEN 1
            ELSE 2
        END,
        b.start_date ASC,
        b.end_date ASC,
        b.id DESC
";

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

$blocks =
    $stmt
        ->get_result()
        ->fetch_all(
            MYSQLI_ASSOC
        );

$stmt->close();

/*
|--------------------------------------------------------------------------
| Calendar Events
|--------------------------------------------------------------------------
*/

$calendarRows =
    $conn
        ->query(
            "SELECT
                b.*,
                u.name AS blocked_by_name
             FROM hpu_blocked_dates b
             LEFT JOIN users u
                ON u.id = b.blocked_by
             WHERE
                b.end_date >= DATE_SUB(
                    CURDATE(),
                    INTERVAL 60 DAY
                )
             ORDER BY
                b.start_date ASC,
                b.id ASC"
        )
        ->fetch_all(
            MYSQLI_ASSOC
        );

$calendarEvents = [];

$calendarColors = [
    "All HPU Services" => [
        "bg" => "#b42318",
        "border" => "#b42318",
        "text" => "#ffffff"
    ],
    "Graphic Design" => [
        "bg" => "#2563eb",
        "border" => "#2563eb",
        "text" => "#ffffff"
    ],
    "Video Production" => [
        "bg" => "#7c3aed",
        "border" => "#7c3aed",
        "text" => "#ffffff"
    ],
    "Photo/Video Coverage" => [
        "bg" => "#ea580c",
        "border" => "#ea580c",
        "text" => "#ffffff"
    ],
    "IEC Materials" => [
        "bg" => "#15803d",
        "border" => "#15803d",
        "text" => "#ffffff"
    ],
    "Publication Submission" => [
        "bg" => "#0f766e",
        "border" => "#0f766e",
        "text" => "#ffffff"
    ]
];

foreach ($calendarRows as $row) {
    $color =
        $calendarColors[
            $row["service_type"]
        ]
        ?? [
            "bg" => "#64748b",
            "border" => "#64748b",
            "text" => "#ffffff"
        ];

    $endExclusive =
        date(
            "Y-m-d",
            strtotime(
                $row["end_date"]
                . " +1 day"
            )
        );

    $calendarEvents[] = [
        "id" =>
            (string) 
            $row["id"],

        "title" =>
            $row["service_type"],

        "start" =>
            $row["start_date"],

        "end" =>
            $endExclusive,

        "allDay" =>
            true,

        "backgroundColor" =>
            $color["bg"],

        "borderColor" =>
            $color["border"],

        "textColor" =>
            $color["text"],

        "extendedProps" => [
            "service_type" =>
                $row["service_type"],

            "reason" =>
                $row["reason"],

            "blocked_by_name" =>
                $row["blocked_by_name"]
                ?: "Unknown User",

            "start_date" =>
                $row["start_date"],

            "end_date" =>
                $row["end_date"]
        ]
    ];
}

/*
|--------------------------------------------------------------------------
| Stats
|--------------------------------------------------------------------------
*/

$stats =
    $conn->query(
        "SELECT
            COUNT(*) AS total,
            COALESCE(
                SUM(
                    end_date >= CURDATE()
                ),
                0
            ) AS upcoming,
            COALESCE(
                SUM(
                    CURDATE()
                    BETWEEN start_date
                    AND end_date
                ),
                0
            ) AS today,
            COALESCE(
                SUM(
                    service_type = 'All HPU Services'
                    AND end_date >= CURDATE()
                ),
                0
            ) AS all_service,
            COALESCE(
                SUM(
                    service_type = 'Publication Submission'
                    AND end_date >= CURDATE()
                ),
                0
            ) AS publication
         FROM hpu_blocked_dates"
    )->fetch_assoc() ?: [];

/*
|--------------------------------------------------------------------------
| Service Availability Today
|--------------------------------------------------------------------------
*/

$serviceStatus = [];

foreach ($serviceOptions as $service) {
    if ($service === "All HPU Services") {
        continue;
    }

    $block =
        hpuAvailabilityFindBlock(
            $conn,
            date(
                "Y-m-d"
            ),
            [
                $service
            ]
        );

    $nextStmt =
        $conn->prepare(
            "SELECT
                start_date,
                end_date,
                reason,
                service_type
             FROM hpu_blocked_dates
             WHERE
                start_date > CURDATE()
                AND (
                    service_type = 'All HPU Services'
                    OR service_type = ?
                )
             ORDER BY
                start_date ASC,
                id ASC
             LIMIT 1"
        );

    $nextStmt->bind_param(
        "s",
        $service
    );

    $nextStmt->execute();

    $nextBlock =
        $nextStmt
            ->get_result()
            ->fetch_assoc();

    $nextStmt->close();

    $serviceStatus[$service] = [
        "blocked_today" =>
            $block !== null,

        "today_reason" =>
            $block["reason"]
            ?? "",

        "next" =>
            $nextBlock
    ];
}

/*
|--------------------------------------------------------------------------
| Upcoming Availability Timeline
|--------------------------------------------------------------------------
*/

$upcomingTimeline =
    $conn
        ->query(
            "SELECT
                id,
                start_date,
                end_date,
                service_type,
                reason
             FROM hpu_blocked_dates
             WHERE
                end_date >= CURDATE()
             ORDER BY
                start_date ASC,
                end_date ASC,
                id ASC
             LIMIT 8"
        )
        ->fetch_all(
            MYSQLI_ASSOC
        );

$flash =
    $_SESSION["blocked_dates_flash"]
    ?? null;

unset(
    $_SESSION["blocked_dates_flash"]
);

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        HPU Availability | HPU Workbench
    </title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css">

    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/style.css">

    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/page_headers.css">

    <style>
        :root {
            --bd-bg: #f4f7fb;
            --bd-surface: #ffffff;
            --bd-border: #dbe3ef;
            --bd-border-soft: #e8edf4;
            --bd-text: #0f172a;
            --bd-muted: #64748b;
            --bd-primary: #2563eb;
            --bd-danger: #b42318;
            --bd-success: #15803d;
            --bd-warning: #b54708;
        }

        body {
            background: var(--bd-bg);
        }

        .content {
            padding: 18px;
        }

        .blocked-page {
            max-width: 1580px;
            margin: 0 auto;
        }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }

        .kpi-card,
        .panel,
        .service-card,
        .availability-panel {
            border: 1px solid var(--bd-border);
            border-radius: 14px;
            background: var(--bd-surface);
            box-shadow: 0 8px 24px rgba(15, 23, 42, .045);
        }

        .kpi-card {
            padding: 13px 14px;
        }

        .kpi-label {
            color: #64748b;
            font-size: .57rem;
            font-weight: 900;
            letter-spacing: .045em;
            text-transform: uppercase;
        }

        .kpi-value {
            margin-top: 5px;
            font-size: 1.42rem;
            line-height: 1;
            font-weight: 900;
            color: #0f172a;
        }

        .kpi-note {
            margin-top: 5px;
            color: #94a3b8;
            font-size: .58rem;
        }

        .service-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 9px;
            margin-bottom: 14px;
        }

        .service-card {
            min-height: 114px;
            padding: 12px;
        }

        .service-card-top {
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .service-icon {
            width: 32px;
            height: 32px;
            display: grid;
            place-items: center;
            flex: 0 0 32px;
            border-radius: 9px;
            background: #f1f5f9;
            color: #475569;
        }

        .service-name {
            color: #1e293b;
            font-size: .68rem;
            font-weight: 850;
            line-height: 1.25;
        }

        .availability-state {
            margin-top: 10px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 7px;
            border-radius: 999px;
            font-size: .55rem;
            font-weight: 900;
        }

        .state-available {
            background: #ecfdf3;
            color: #027a48;
        }

        .state-blocked {
            background: #fef3f2;
            color: #b42318;
        }

        .service-next {
            margin-top: 7px;
            color: #667085;
            font-size: .57rem;
            line-height: 1.35;
        }

        .workspace-grid {
            display: grid;
            grid-template-columns: minmax(0, 2fr) minmax(280px, .72fr);
            gap: 12px;
            margin-bottom: 14px;
        }

        .panel {
            overflow: hidden;
        }

        .panel-head {
            min-height: 60px;
            padding: 11px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            border-bottom: 1px solid var(--bd-border-soft);
            background: #fff;
        }

        .panel-head-main {
            min-width: 0;
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .panel-head-icon {
            width: 33px;
            height: 33px;
            display: grid;
            place-items: center;
            flex: 0 0 33px;
            border-radius: 9px;
            background: #eff6ff;
            color: #2563eb;
        }

        .panel-title {
            margin: 0;
            color: #1d2939;
            font-size: .8rem;
            font-weight: 900;
        }

        .panel-subtitle {
            margin-top: 2px;
            color: #667085;
            font-size: .6rem;
        }

        .panel-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .view-toggle {
            display: inline-flex;
            padding: 3px;
            border: 1px solid #dbe3ef;
            border-radius: 9px;
            background: #f8fafc;
        }

        .view-toggle button {
            border: 0;
            border-radius: 6px;
            background: transparent;
            color: #64748b;
            padding: 5px 9px;
            font-size: .58rem;
            font-weight: 850;
        }

        .view-toggle button.active {
            background: #fff;
            color: #1d4ed8;
            box-shadow: 0 2px 6px rgba(15, 23, 42, .08);
        }

        .calendar-wrap {
            padding: 12px;
        }

        #availabilityCalendar {
            min-height: 630px;
        }

        .fc .fc-toolbar-title {
            font-size: .9rem;
            font-weight: 900;
            color: #1e293b;
        }

        .fc .fc-button {
            font-size: .65rem;
        }

        .fc .fc-daygrid-event {
            border-radius: 6px;
            padding: 1px 3px;
            font-size: .57rem;
            font-weight: 750;
        }

        .availability-panel {
            overflow: hidden;
        }

        .upcoming-list {
            padding: 5px 12px 12px;
        }

        .upcoming-item {
            display: grid;
            grid-template-columns: 54px minmax(0, 1fr);
            gap: 9px;
            padding: 10px 0;
            border-bottom: 1px solid #edf1f5;
        }

        .upcoming-item:last-child {
            border-bottom: 0;
        }

        .upcoming-date {
            text-align: center;
            padding: 6px 3px;
            border-radius: 8px;
            background: #f8fafc;
            color: #475569;
        }

        .upcoming-date strong {
            display: block;
            font-size: .83rem;
            line-height: 1;
        }

        .upcoming-date span {
            display: block;
            margin-top: 3px;
            font-size: .5rem;
            font-weight: 800;
            text-transform: uppercase;
        }

        .upcoming-service {
            color: #1e293b;
            font-size: .62rem;
            font-weight: 850;
        }

        .upcoming-reason {
            margin-top: 2px;
            color: #667085;
            font-size: .57rem;
            line-height: 1.35;
        }

        .filter-bar {
            padding: 10px 12px;
            border-bottom: 1px solid var(--bd-border-soft);
            background: #fbfcfe;
        }

        .form-label {
            color: #475569;
            font-size: .58rem;
            font-weight: 850;
        }

        .form-control,
        .form-select {
            min-height: 40px;
            border-color: #dbe3ef;
            border-radius: 9px;
            font-size: .76rem;
        }

        .table {
            margin: 0;
            font-size: .7rem;
        }

        .table th {
            padding: 9px 11px;
            background: #f8fafc;
            color: #667085;
            font-size: .53rem;
            font-weight: 900;
            letter-spacing: .045em;
            text-transform: uppercase;
            border-bottom-color: #e5eaf1;
            white-space: nowrap;
        }

        .table td {
            padding: 10px 11px;
            vertical-align: middle;
            color: #334155;
        }

        .service-pill,
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 7px;
            border-radius: 999px;
            font-size: .53rem;
            font-weight: 850;
        }

        .service-all {
            background: #fef3f2;
            color: #b42318;
        }

        .service-graphic {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .service-video {
            background: #f5f3ff;
            color: #6d28d9;
        }

        .service-coverage {
            background: #fff7ed;
            color: #c2410c;
        }

        .service-iec {
            background: #f0fdf4;
            color: #15803d;
        }

        .service-publication {
            background: #f0fdfa;
            color: #0f766e;
        }

        .service-other {
            background: #f1f5f9;
            color: #475569;
        }

        .status-current {
            background: #fef2f2;
            color: #b91c1c;
        }

        .status-future {
            background: #fff7ed;
            color: #c2410c;
        }

        .status-past {
            background: #f1f5f9;
            color: #64748b;
        }

        .reason-cell {
            max-width: 310px;
            white-space: normal;
        }

        .byline {
            color: #64748b;
            font-size: .57rem;
            line-height: 1.35;
        }

        .byline strong {
            color: #334155;
        }

        .table-view {
            display: none;
        }

        .table-view.active {
            display: block;
        }

        .calendar-view {
            display: none;
        }

        .calendar-view.active {
            display: block;
        }

        .conflict-box {
            display: none;
            margin-top: 12px;
            padding: 10px 11px;
            border: 1px solid #fed7aa;
            border-radius: 10px;
            background: #fff7ed;
            color: #9a3412;
            font-size: .68rem;
            line-height: 1.4;
        }

        .conflict-box.show {
            display: block;
        }

        .conflict-items {
            margin: 7px 0 0;
            padding-left: 18px;
        }

        .reason-presets {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
            margin-top: 7px;
        }

        .reason-preset {
            border: 1px solid #dbe3ef;
            border-radius: 999px;
            background: #fff;
            color: #475569;
            padding: 4px 7px;
            font-size: .55rem;
            font-weight: 800;
        }

        .reason-preset:hover {
            border-color: #93c5fd;
            color: #1d4ed8;
        }

        .quick-date-actions {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }

        .quick-date-actions button {
            border: 1px solid #dbe3ef;
            border-radius: 8px;
            background: #f8fafc;
            color: #475569;
            padding: 5px 8px;
            font-size: .57rem;
            font-weight: 800;
        }

        .quick-date-actions button:hover {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .block-mode-selector {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .block-mode-option {
            position: relative;
        }

        .block-mode-option input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .block-mode-option label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 34px;
            padding: 6px 10px;
            border: 1px solid #dbe3ef;
            border-radius: 9px;
            background: #fff;
            color: #475569;
            font-size: .59rem;
            font-weight: 850;
            cursor: pointer;
        }

        .block-mode-option input:checked+label {
            border-color: #93c5fd;
            background: #eff6ff;
            color: #1d4ed8;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, .07);
        }

        .weekday-block-options {
            display: none;
            margin-top: 2px;
            padding: 10px 11px;
            border: 1px solid #dbe3ef;
            border-radius: 10px;
            background: #f8fafc;
        }

        .weekday-block-options.show {
            display: block;
        }

        .weekday-checks {
            display: flex;
            gap: 9px;
            flex-wrap: wrap;
        }

        .weekday-check {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin: 0;
            padding: 6px 9px;
            border: 1px solid #dbe3ef;
            border-radius: 8px;
            background: #fff;
            color: #475569;
            font-size: .61rem;
            font-weight: 800;
            cursor: pointer;
        }

        .weekday-check input {
            margin: 0;
        }

        .fc .fc-daygrid-day {
            cursor: pointer;
        }

        .fc .fc-daygrid-day:hover {
            background: #f8fbff;
        }

        .calendar-click-note {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 7px 12px 0;
            color: #64748b;
            font-size: .57rem;
        }

        .empty {
            padding: 50px 20px !important;
            text-align: center;
            color: #64748b !important;
        }

        .calendar-detail-grid {
            display: grid;
            gap: 9px;
        }

        .calendar-detail-row {
            display: grid;
            grid-template-columns: 110px 1fr;
            gap: 10px;
            align-items: start;
        }

        .calendar-detail-label {
            color: #64748b;
            font-size: .6rem;
            font-weight: 850;
        }

        .calendar-detail-value {
            color: #1e293b;
            font-size: .68rem;
        }

        @media(max-width:1250px) {
            .service-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media(max-width:1050px) {
            .workspace-grid {
                grid-template-columns: 1fr;
            }

            #availabilityCalendar {
                min-height: 560px;
            }
        }

        @media(max-width:850px) {
            .kpi-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .service-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media(max-width:650px) {
            .content {
                padding: 12px;
            }

            .kpi-grid,
            .service-grid {
                grid-template-columns: 1fr 1fr;
            }

            .panel-head {
                align-items: flex-start;
                flex-direction: column;
            }

            .panel-actions {
                width: 100%;
                justify-content: flex-start;
            }

            .calendar-detail-row {
                grid-template-columns: 1fr;
                gap: 2px;
            }
        }
    </style>

    <style>
        /* ============================================================
           HPU Availability — UI Refresh (override layer)
           Modern, consistent with Evaluation Analytics.
           ============================================================ */
        .blocked-page {
            max-width: 1600px;
        }

        /* Header polish */
        .ph {
            position: relative;
            overflow: hidden;
            border-color: #dbe3ef;
            background:
                linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        }

        .ph::after {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 3px;
            background: linear-gradient(90deg, #2563eb, #7c3aed, #0ea5e9);
            opacity: .9;
        }

        .ph-title {
            letter-spacing: -.025em;
        }

        /* KPI — icon + lift */
        .kpi-grid {
            gap: 12px;
        }

        .kpi-card {
            position: relative;
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 14px 15px;
            overflow: hidden;
            transition: transform .16s ease, box-shadow .16s ease;
        }

        .kpi-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 3px;
            background: #e2e8f0;
        }

        .kpi-card:nth-child(1)::before { background: linear-gradient(90deg, #2563eb, #60a5fa); }
        .kpi-card:nth-child(2)::before { background: linear-gradient(90deg, #dc2626, #f87171); }
        .kpi-card:nth-child(3)::before { background: linear-gradient(90deg, #d97706, #fbbf24); }
        .kpi-card:nth-child(4)::before { background: linear-gradient(90deg, #059669, #34d399); }

        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(15, 23, 42, .09);
        }

        .kpi-card .kpi-icon {
            width: 38px;
            height: 38px;
            flex: 0 0 38px;
            display: grid;
            place-items: center;
            border-radius: 11px;
            font-size: 1rem;
        }

        .kpi-card:nth-child(1) .kpi-icon { background: #eff6ff; color: #1d4ed8; }
        .kpi-card:nth-child(2) .kpi-icon { background: #fef2f2; color: #b91c1c; }
        .kpi-card:nth-child(3) .kpi-icon { background: #fffbeb; color: #b45309; }
        .kpi-card:nth-child(4) .kpi-icon { background: #ecfdf5; color: #047857; }

        .kpi-card .kpi-body { min-width: 0; flex: 1; }
        .kpi-value { font-variant-numeric: tabular-nums; }

        /* Service cards — accent + status dot */
        .service-grid { gap: 11px; }

        .service-card {
            position: relative;
            overflow: hidden;
            transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
        }

        .service-card::after {
            content: "";
            position: absolute;
            inset: auto 0 0 0;
            height: 3px;
            background: #e2e8f0;
        }

        .service-card:hover {
            transform: translateY(-2px);
            border-color: #bfdbfe;
            box-shadow: 0 12px 26px rgba(37, 99, 235, .10);
        }

        .service-card.is-blocked {
            border-color: #fecaca;
            background: linear-gradient(180deg, #fff 0%, #feff 0%, #fef2f2 100%);
        }

        .service-card.is-blocked::after { background: linear-gradient(90deg, #ef4444, #f87171); }
        .service-card.is-available::after { background: linear-gradient(90deg, #10b981, #6ee7b7); }

        .service-icon {
            box-shadow: inset 0 0 0 1px rgba(15,23,42,.05);
        }

        .availability-state {
            letter-spacing: .02em;
            text-transform: uppercase;
        }

        .service-next strong { color: #334155; }

        /* Panels */
        .panel, .availability-panel {
            border-color: #dbe3ef;
        }

        .panel-head {
            background: linear-gradient(180deg, #ffffff, #f8fafc);
        }

        .panel-title { letter-spacing: -.015em; }

        /* View toggle — segmented */
        .view-toggle {
            padding: 4px;
            gap: 2px;
            border-radius: 11px;
            background: #eef2f7;
            border-color: #dbe3ef;
        }

        .view-toggle button {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 12px;
            font-size: .62rem;
            border-radius: 8px;
            transition: all .15s ease;
        }

        .view-toggle button.active {
            background: #fff;
            color: #1d4ed8;
            box-shadow: 0 3px 10px rgba(15,23,42,.12);
        }

        /* Calendar */
        .calendar-wrap {
            padding: 14px;
        }

        #availabilityCalendar {
            --fc-border-color: #e5eaf1;
            --fc-today-bg-color: #eff6ff;
            --fc-now-indicator-color: #2563eb;
        }

        .fc .fc-toolbar-title {
            font-size: 1rem;
            letter-spacing: -.02em;
        }

        .fc .fc-button-primary {
            background: #fff;
            border-color: #dbe3ef;
            color: #475569;
            font-weight: 800;
            border-radius: 8px;
            box-shadow: none;
        }

        .fc .fc-button-primary:hover,
        .fc .fc-button-primary:focus {
            background: #eff6ff;
            border-color: #93c5fd;
            color: #1d4ed8;
        }

        .fc .fc-button-primary.fc-button-active {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
        }

        .fc .fc-daygrid-day-number {
            font-size: .68rem;
            font-weight: 800;
            color: #475569;
        }

        .fc .fc-day-today .fc-daygrid-day-number {
            background: #2563eb;
            color: #fff;
            border-radius: 999px;
            width: 24px;
            height: 24px;
            display: inline-grid;
            place-items: center;
        }

        .fc .fc-day-sat, .fc .fc-day-sun {
            background: #f8fafc;
        }

        .fc .fc-daygrid-event {
            border: 0;
            box-shadow: 0 2px 6px rgba(15,23,42,.12);
        }

        .calendar-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 14px;
            padding: 10px 14px 0;
            font-size: .6rem;
            color: #64748b;
        }

        .calendar-legend span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 700;
        }

        .calendar-legend i {
            width: 10px;
            height: 10px;
            border-radius: 3px;
            display: inline-block;
        }

        .calendar-click-note {
            color: #94a3b8;
        }

        /* Filter bar */
        .filter-bar {
            position: sticky;
            top: 0;
            z-index: 3;
            background: #f8fafc;
            border-bottom: 1px solid #e5eaf1;
        }

        .filter-bar .form-label {
            text-transform: uppercase;
            letter-spacing: .05em;
            font-size: .55rem;
            color: #64748b;
        }

        .filter-bar .form-control,
        .filter-bar .form-select {
            background: #fff;
            box-shadow: 0 1px 2px rgba(15,23,42,.04);
        }

        .filter-bar .form-control:focus,
        .filter-bar .form-select:focus {
            border-color: #93c5fd;
            box-shadow: 0 0 0 3px rgba(37,99,235,.12);
        }

        /* List table — scrollable like tracker */
        .table-responsive.bd-table-scroll {
            max-height: 540px;
            overflow: auto;
            overscroll-behavior: contain;
        }

        .bd-table-scroll thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f1f5f9;
            box-shadow: 0 1px 0 #e2e8f0;
        }

        .bd-table-scroll::-webkit-scrollbar { height: 10px; width: 10px; }
        .bd-table-scroll::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 999px;
            border: 2px solid #f8fafc;
        }
        .bd-table-scroll::-webkit-scrollbar-track { background: #f8fafc; }

        .table tbody tr { transition: background .12s ease; }
        .table tbody tr:hover { background: #f8fbff; }

        .duration-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 4px;
            padding: 2px 7px;
            border-radius: 999px;
            background: #f1f5f9;
            color: #475569;
            font-size: .53rem;
            font-weight: 800;
        }

        .table .btn {
            border-radius: 8px;
            font-weight: 800;
        }

        /* Upcoming timeline */
        .upcoming-list {
            max-height: 640px;
            overflow: auto;
            padding: 6px 14px 14px;
        }

        .upcoming-item {
            position: relative;
            border-bottom: 1px dashed #e5eaf1;
        }

        .upcoming-item:last-child { border-bottom: 0; }

        .upcoming-date {
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 6px rgba(15,23,42,.05);
        }

        .upcoming-service { letter-spacing: -.01em; }

        /* Modal refresh */
        .modal-content {
            border: 0;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 24px 60px rgba(15,23,42,.22);
        }

        .modal-header {
            background: linear-gradient(180deg, #ffffff, #f8fafc);
            border-bottom: 1px solid #e5eaf1;
            padding: 16px 18px;
        }

        .modal-title { letter-spacing: -.02em; }

        .modal-body { padding: 18px; }

        .modal-footer {
            background: #f8fafc;
            border-top: 1px solid #e5eaf1;
            padding: 12px 18px;
        }

        .block-mode-option label {
            border-radius: 11px;
            transition: all .14s ease;
        }

        .block-mode-option label:hover {
            border-color: #93c5fd;
        }

        .quick-date-actions button {
            border-radius: 999px;
            transition: all .13s ease;
        }

        .reason-preset {
            transition: all .13s ease;
        }

        .conflict-box {
            border-left: 4px solid #f59e0b;
        }

        /* Empty states */
        .empty {
            background:
                radial-gradient(420px 140px at 50% 0%, #eff6ff 0%, transparent 70%);
        }

        /* Focus */
        .btn:focus-visible, .form-control:focus-visible,
        .form-select:focus-visible, button:focus-visible {
            outline: 2px solid #93c5fd;
            outline-offset: 2px;
        }

        @media(max-width: 700px) {
            .kpi-card { padding: 12px; }
            .calendar-wrap { padding: 8px; }
            #availabilityCalendar { min-height: 520px; }
            .table-responsive.bd-table-scroll { max-height: 420px; }
        }

        @media print {
            .sidebar, .ph-actions, .panel-actions,
            .filter-bar, .modal { display: none !important; }
            body { background: #fff; }
            .panel, .kpi-card, .service-card { box-shadow: none; break-inside: avoid; }
        }
    </style>

</head>

<body class="app-shell">

    <div class="d-flex min-vh-100">

        <?php
        include "../includes/sidebar.php";
        ?>

        <div class="main flex-grow-1">

            <main class="content">

                <div class="container-fluid blocked-page">

                    <div class="ph">

                        <div class="ph-copy">

                            <div class="ph-icon" aria-hidden="true">
                                <i class="bi bi-calendar-x"></i>
                            </div>

                            <div class="min-w-0">

                                <p class="ph-eyebrow">
                                    HPU Workbench
                                </p>

                                <h1 class="ph-title">
                                    HPU Availability
                                </h1>

                                <p class="ph-subtitle">
                                    Manage dates when HPU services cannot accept new scheduling.
                                    <?= (int)($stats["today"] ?? 0) > 0
                                        ? " " . (int)($stats["today"] ?? 0) . " rule(s) affect today."
                                        : " All services clear today unless listed below."
                                    ?>
                                </p>

                            </div>

                        </div>

                        <div class="ph-actions">

                            <span class="badge <?= ((int)($stats["today"] ?? 0) > 0) ? "text-bg-danger" : "text-bg-success" ?> d-inline-flex align-items-center gap-1">
                                <i class="bi <?= ((int)($stats["today"] ?? 0) > 0) ? "bi-exclamation-octagon" : "bi-check-circle" ?>"></i>
                                <?= ((int)($stats["today"] ?? 0) > 0) ? ((int)($stats["today"] ?? 0) . " blocking today") : "Clear today" ?>
                            </span>

                            <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal"
                                data-bs-target="#blockDateModal" id="addBlockedDateBtn">
                                <i class="bi bi-calendar-plus me-1"></i>
                                Block Date / Range
                            </button>

                        </div>

                    </div>

                    <?php if ($flash): ?>

                        <div class="alert alert-<?= e($flash["type"]) ?> alert-dismissible fade show">
                            <?= e($flash["message"]) ?>

                            <button class="btn-close" type="button" data-bs-dismiss="alert"></button>
                        </div>

                    <?php endif; ?>

                    <div class="kpi-grid">

                        <div class="kpi-card">
                            <div class="kpi-icon" aria-hidden="true"><i class="bi bi-calendar-event"></i></div>
                            <div class="kpi-body">
                            <div class="kpi-label">
                                Upcoming Blocks
                            </div>

                            <div class="kpi-value text-primary">
                                <?= (int) ($stats["upcoming"] ?? 0) ?>
                            </div>

                            <div class="kpi-note">
                                Current or future rules
                            </div>
                            </div>
                        </div>

                        <div class="kpi-card">
                            <div class="kpi-icon" aria-hidden="true"><i class="bi bi-exclamation-octagon"></i></div>
                            <div class="kpi-body">
                            <div class="kpi-label">
                                Blocking Today
                            </div>

                            <div class="kpi-value text-danger">
                                <?= (int) ($stats["today"] ?? 0) ?>
                            </div>

                            <div class="kpi-note">
                                Availability rules affecting today
                            </div>
                            </div>
                        </div>

                        <div class="kpi-card">
                            <div class="kpi-icon" aria-hidden="true"><i class="bi bi-slash-circle"></i></div>
                            <div class="kpi-body">
                            <div class="kpi-label">
                                All Services Blocks
                            </div>

                            <div class="kpi-value text-warning">
                                <?= (int) ($stats["all_service"] ?? 0) ?>
                            </div>

                            <div class="kpi-note">
                                Current / upcoming broad blocks
                            </div>
                            </div>
                        </div>

                        <div class="kpi-card">
                            <div class="kpi-icon" aria-hidden="true"><i class="bi bi-megaphone"></i></div>
                            <div class="kpi-body">
                            <div class="kpi-label">
                                Publication Blocks
                            </div>

                            <div class="kpi-value text-success">
                                <?= (int) ($stats["publication"] ?? 0) ?>
                            </div>

                            <div class="kpi-note">
                                Publication availability restrictions
                            </div>
                            </div>
                        </div>

                    </div>

                    <div class="service-grid">

                        <?php foreach ($serviceStatus as $service => $status): ?>

                            <div class="service-card <?= !empty($status["blocked_today"]) ? "is-blocked" : "is-available" ?>">

                                <div class="service-card-top">

                                    <div class="service-icon">
                                        <i class="bi <?= e(bdServiceIcon($service)) ?>"></i>
                                    </div>

                                    <div class="service-name">
                                        <?= e($service) ?>
                                    </div>

                                </div>

                                <?php if ($status["blocked_today"]): ?>

                                    <span class="availability-state state-blocked">
                                        <i class="bi bi-x-circle-fill"></i>
                                        Blocked Today
                                    </span>

                                    <div class="service-next">
                                        <?= e($status["today_reason"]) ?>
                                    </div>

                                <?php else: ?>

                                    <span class="availability-state state-available">
                                        <i class="bi bi-check-circle-fill"></i>
                                        Available Today
                                    </span>

                                    <div class="service-next">

                                        <?php if ($status["next"]): ?>

                                            Next block:
                                            <?= e(
                                                date(
                                                    "M d",
                                                    strtotime(
                                                        $status["next"]["start_date"]
                                                    )
                                                )
                                            ) ?>

                                            <?php if (
                                                $status["next"]["start_date"]
                                                !==
                                                $status["next"]["end_date"]
                                            ): ?>
                                                –
                                                <?= e(
                                                    date(
                                                        "M d",
                                                        strtotime(
                                                            $status["next"]["end_date"]
                                                        )
                                                    )
                                                ) ?>
                                            <?php endif; ?>

                                        <?php else: ?>

                                            No upcoming block

                                        <?php endif; ?>

                                    </div>

                                <?php endif; ?>

                            </div>

                        <?php endforeach; ?>

                    </div>

                    <div class="workspace-grid">

                        <section class="panel">

                            <div class="panel-head">

                                <div class="panel-head-main">

                                    <div class="panel-head-icon">
                                        <i class="bi bi-calendar3"></i>
                                    </div>

                                    <div>
                                        <h2 class="panel-title">
                                            Availability Schedule
                                        </h2>

                                        <div class="panel-subtitle">
                                            Review blocked dates in calendar or list format.
                                        </div>
                                    </div>

                                </div>

                                <div class="panel-actions">

                                    <div class="view-toggle">
                                        <button type="button" id="calendarViewBtn" class="active">
                                            <i class="bi bi-calendar3 me-1"></i>
                                            Calendar
                                        </button>

                                        <button type="button" id="listViewBtn">
                                            <i class="bi bi-list-ul me-1"></i>
                                            List
                                        </button>
                                    </div>

                                </div>

                            </div>

                            <div id="calendarView" class="calendar-view active">
                                <div class="calendar-click-note">
                                    <i class="bi bi-cursor"></i>
                                    Click any calendar date to block that specific day.
                                </div>

                                <div class="calendar-legend" aria-hidden="true">
                                    <span><i style="background:#b42318"></i>All Services</span>
                                    <span><i style="background:#2563eb"></i>Graphic Design</span>
                                    <span><i style="background:#7c3aed"></i>Video Production</span>
                                    <span><i style="background:#ea580c"></i>Coverage</span>
                                    <span><i style="background:#15803d"></i>IEC</span>
                                    <span><i style="background:#0f766e"></i>Publication</span>
                                </div>

                                <div class="calendar-wrap">
                                    <div id="availabilityCalendar"></div>
                                </div>
                            </div>

                            <div id="listView" class="table-view">

                                <form class="filter-bar" method="get">

                                    <div class="row g-2 align-items-end">

                                        <div class="col-lg-4 col-md-5">

                                            <label class="form-label mb-1">
                                                Search
                                            </label>

                                            <input class="form-control form-control-sm" name="q" value="<?= e($q) ?>"
                                                placeholder="Reason, service or HPU personnel...">

                                        </div>

                                        <div class="col-lg-3 col-md-4">

                                            <label class="form-label mb-1">
                                                Service
                                            </label>

                                            <select class="form-select form-select-sm" name="service">

                                                <option value="">
                                                    All Services
                                                </option>

                                                <?php foreach ($serviceOptions as $service): ?>

                                                    <option value="<?= e($service) ?>" <?= $serviceFilter === $service ? "selected" : "" ?>>
                                                        <?= e($service) ?>
                                                    </option>

                                                <?php endforeach; ?>

                                            </select>

                                        </div>

                                        <div class="col-lg-2 col-md-3">

                                            <label class="form-label mb-1">
                                                View
                                            </label>

                                            <select class="form-select form-select-sm" name="view">

                                                <option value="current" <?= $view === "current" ? "selected" : "" ?>>
                                                    Current + Upcoming
                                                </option>

                                                <option value="today" <?= $view === "today" ? "selected" : "" ?>>
                                                    Today
                                                </option>

                                                <option value="history" <?= $view === "history" ? "selected" : "" ?>>
                                                    History
                                                </option>

                                                <option value="all" <?= $view === "all" ? "selected" : "" ?>>
                                                    All
                                                </option>

                                            </select>

                                        </div>

                                        <div class="col-lg-auto d-flex gap-2">

                                            <button class="btn btn-sm btn-primary" type="submit">
                                                <i class="bi bi-funnel me-1"></i>
                                                Apply
                                            </button>

                                            <a class="btn btn-sm btn-light"
                                                href="<?= e(BASE_URL) ?>/staff/blocked_dates.php">
                                                Reset
                                            </a>

                                        </div>

                                    </div>

                                </form>

                                <div class="table-responsive bd-table-scroll">

                                    <table class="table table-hover align-middle">

                                        <thead>
                                            <tr>
                                                <th>Date / Range</th>
                                                <th>Service</th>
                                                <th>Reason</th>
                                                <th>Status</th>
                                                <th>Created By</th>
                                                <th>Last Updated</th>
                                                <th class="text-end">Actions</th>
                                            </tr>
                                        </thead>

                                        <tbody>

                                            <?php if (!$blocks): ?>

                                                <tr>
                                                    <td colspan="7" class="empty">
                                                        <i class="bi bi-calendar-check fs-2 d-block mb-2"></i>
                                                        No blocked dates match the current filters.
                                                    </td>
                                                </tr>

                                            <?php endif; ?>

                                            <?php foreach ($blocks as $block): ?>

                                                <?php
                                                $today =
                                                    date(
                                                        "Y-m-d"
                                                    );

                                                $isCurrent =
                                                    $today >= $block["start_date"]
                                                    &&
                                                    $today <= $block["end_date"];

                                                $isPast =
                                                    $block["end_date"]
                                                    < $today;

                                                $statusLabel =
                                                    $isCurrent
                                                    ? "Blocked Today"
                                                    : (
                                                        $isPast
                                                        ? "History"
                                                        : "Upcoming"
                                                    );

                                                $statusClass =
                                                    $isCurrent
                                                    ? "status-current"
                                                    : (
                                                        $isPast
                                                        ? "status-past"
                                                        : "status-future"
                                                    );

                                                $canManage =
                                                    bdCanManageRecord(
                                                        $block,
                                                        $userId,
                                                        $isManager
                                                    );
                                                ?>

                                                <tr>

                                                    <td>
                                                        <strong>
                                                            <?= e(
                                                                bdDateLabel(
                                                                    $block["start_date"],
                                                                    $block["end_date"]
                                                                )
                                                            ) ?>
                                                        </strong>
                                                        <?php
                                                        $spanDays = (int)((strtotime($block["end_date"]) - strtotime($block["start_date"])) / 86400) + 1;
                                                        ?>
                                                        <div>
                                                            <span class="duration-badge">
                                                                <i class="bi bi-clock"></i>
                                                                <?= $spanDays <= 1 ? "1 day" : number_format($spanDays) . " days" ?>
                                                            </span>
                                                        </div>
                                                    </td>

                                                    <td>
                                                        <span
                                                            class="service-pill <?= e(bdServiceClass($block["service_type"])) ?>">
                                                            <i
                                                                class="bi <?= e(bdServiceIcon($block["service_type"])) ?>"></i>
                                                            <?= e($block["service_type"]) ?>
                                                        </span>
                                                    </td>

                                                    <td class="reason-cell">
                                                        <?= e($block["reason"]) ?>
                                                    </td>

                                                    <td>
                                                        <span class="status-pill <?= e($statusClass) ?>">
                                                            <?= e($statusLabel) ?>
                                                        </span>
                                                    </td>

                                                    <td>
                                                        <div class="byline">
                                                            <strong>
                                                                <?= e(
                                                                    $block["blocked_by_name"]
                                                                    ?: "Unknown User"
                                                                ) ?>
                                                            </strong>

                                                            <div>
                                                                <?= e(
                                                                    date(
                                                                        "M d, Y • g:i A",
                                                                        strtotime(
                                                                            $block["created_at"]
                                                                        )
                                                                    )
                                                                ) ?>
                                                            </div>
                                                        </div>
                                                    </td>

                                                    <td>
                                                        <div class="byline">

                                                            <strong>
                                                                <?= e(
                                                                    $block["updated_by_name"]
                                                                    ?: (
                                                                        $block["blocked_by_name"]
                                                                        ?: "Unknown User"
                                                                    )
                                                                ) ?>
                                                            </strong>

                                                            <div>
                                                                <?= e(
                                                                    date(
                                                                        "M d, Y • g:i A",
                                                                        strtotime(
                                                                            $block["updated_at"]
                                                                        )
                                                                    )
                                                                ) ?>
                                                            </div>

                                                        </div>
                                                    </td>

                                                    <td class="text-end">

                                                        <?php if ($canManage): ?>

                                                            <button class="btn btn-sm btn-outline-primary edit-block-btn"
                                                                type="button" data-block='<?= e(json_encode([
                                                                    "id" => (int) $block["id"],
                                                                    "start_date" => $block["start_date"],
                                                                    "end_date" => $block["end_date"],
                                                                    "service_type" => $block["service_type"],
                                                                    "reason" => $block["reason"]
                                                                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
                                                                <i class="bi bi-pencil"></i>
                                                                Edit
                                                            </button>

                                                            <form method="post" class="d-inline">

                                                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

                                                                <input type="hidden" name="action" value="unblock">

                                                                <input type="hidden" name="id" value="<?= (int) $block["id"] ?>">

                                                                <button class="btn btn-sm btn-outline-danger" type="submit"
                                                                    onclick="return confirm('Unblock this date / range? New requests will be able to select it again.')">
                                                                    <i class="bi bi-calendar-check"></i>
                                                                    Unblock
                                                                </button>

                                                            </form>

                                                        <?php else: ?>

                                                            <span class="small text-muted">
                                                                Created by another staff member
                                                            </span>

                                                        <?php endif; ?>

                                                    </td>

                                                </tr>

                                            <?php endforeach; ?>

                                        </tbody>

                                    </table>

                                </div>

                                <?php if ($blocks): ?>
                                <div class="px-3 py-2 border-top small text-muted d-flex align-items-center gap-1">
                                    <i class="bi bi-arrows-move"></i>
                                    <?= number_format(count($blocks)) ?> record(s) — scroll table vertically / horizontally, header stays pinned.
                                </div>
                                <?php endif; ?>

                            </div>

                        </section>

                        <aside class="availability-panel">

                            <div class="panel-head">

                                <div class="panel-head-main">

                                    <div class="panel-head-icon">
                                        <i class="bi bi-clock-history"></i>
                                    </div>

                                    <div>
                                        <h2 class="panel-title">
                                            Upcoming Availability
                                        </h2>

                                        <div class="panel-subtitle">
                                            Next HPU scheduling restrictions.
                                        </div>
                                    </div>

                                </div>

                            </div>

                            <div class="upcoming-list">

                                <?php if (!$upcomingTimeline): ?>

                                    <div class="empty">
                                        <i class="bi bi-calendar-check fs-3 d-block mb-2"></i>
                                        No current or upcoming blocked dates.
                                    </div>

                                <?php endif; ?>

                                <?php foreach ($upcomingTimeline as $item): ?>

                                    <div class="upcoming-item">

                                        <div class="upcoming-date">

                                            <strong>
                                                <?= e(
                                                    date(
                                                        "d",
                                                        strtotime(
                                                            $item["start_date"]
                                                        )
                                                    )
                                                ) ?>
                                            </strong>

                                            <span>
                                                <?= e(
                                                    date(
                                                        "M",
                                                        strtotime(
                                                            $item["start_date"]
                                                        )
                                                    )
                                                ) ?>
                                            </span>

                                        </div>

                                        <div>

                                            <div class="upcoming-service">
                                                <?= e($item["service_type"]) ?>

                                                <?php if (
                                                    $item["start_date"]
                                                    !==
                                                    $item["end_date"]
                                                ): ?>

                                                    <span class="text-muted fw-normal">
                                                        • through
                                                        <?= e(
                                                            date(
                                                                "M d",
                                                                strtotime(
                                                                    $item["end_date"]
                                                                )
                                                            )
                                                        ) ?>
                                                    </span>

                                                <?php endif; ?>

                                            </div>

                                            <div class="upcoming-reason">
                                                <?= e($item["reason"]) ?>
                                            </div>

                                        </div>

                                    </div>

                                <?php endforeach; ?>

                            </div>

                        </aside>

                    </div>

                </div>

            </main>

        </div>

    </div>

    <!-- =========================================================
     BLOCK / EDIT MODAL
========================================================= -->
    <div class="modal fade" id="blockDateModal" tabindex="-1">

        <div class="modal-dialog modal-dialog-centered modal-lg">

            <div class="modal-content">

                <form method="post" id="blockDateForm">

                    <div class="modal-header">

                        <div class="d-flex align-items-center gap-3">
                            <div class="d-grid place-items-center rounded-3 text-primary" style="width:42px;height:42px;background:#eff6ff;border:1px solid #cfe0f8;" aria-hidden="true">
                                <i class="bi bi-calendar-x fs-5"></i>
                            </div>
                            <div>
                            <h5 class="modal-title fw-bold mb-0" id="blockDateModalTitle">
                                Block Date / Range
                            </h5>

                            <div class="small text-muted">
                                New requests will not be accepted for the selected service and date.
                            </div>
                            </div>
                        </div>

                        <button class="btn-close" type="button" data-bs-dismiss="modal"></button>

                    </div>

                    <div class="modal-body">

                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

                        <input type="hidden" name="action" value="save">

                        <input type="hidden" name="id" id="blockedDateId" value="0">

                        <div class="block-mode-selector">

                            <div class="block-mode-option">
                                <input type="radio" name="block_mode" id="blockModeDateRange" value="date_range"
                                    checked>
                                <label for="blockModeDateRange">
                                    <i class="bi bi-calendar-event"></i>
                                    Specific Date / Range
                                </label>
                            </div>

                            <div class="block-mode-option">
                                <input type="radio" name="block_mode" id="blockModeWeekends" value="recurring_weekdays">
                                <label for="blockModeWeekends">
                                    <i class="bi bi-calendar-week"></i>
                                    Saturday / Sunday
                                </label>
                            </div>

                        </div>

                        <div class="weekday-block-options" id="weekdayBlockOptions">

                            <div class="form-label mb-2">
                                Select weekend day(s) to block within the date range
                            </div>

                            <div class="weekday-checks">

                                <label class="weekday-check">
                                    <input class="form-check-input" type="checkbox" name="weekdays[]" value="6"
                                        id="weekdaySaturday">
                                    Saturday
                                </label>

                                <label class="weekday-check">
                                    <input class="form-check-input" type="checkbox" name="weekdays[]" value="0"
                                        id="weekdaySunday">
                                    Sunday
                                </label>

                            </div>

                            <div class="small text-muted mt-2">
                                Each matching weekend date is saved separately, so a specific Saturday or Sunday can be
                                edited or unblocked later.
                            </div>

                        </div>

                        <div class="quick-date-actions">

                            <button type="button" data-quick-date="today">
                                Today
                            </button>

                            <button type="button" data-quick-date="tomorrow">
                                Tomorrow
                            </button>

                            <button type="button" data-quick-date="week">
                                Next 7 Days
                            </button>

                        </div>

                        <div class="row g-3">

                            <div class="col-md-6">

                                <label class="form-label">
                                    Start Date *
                                </label>

                                <input class="form-control" type="date" name="start_date" id="blockedStartDate"
                                    required>

                            </div>

                            <div class="col-md-6">

                                <label class="form-label">
                                    End Date *
                                </label>

                                <input class="form-control" type="date" name="end_date" id="blockedEndDate" required>

                            </div>

                            <div class="col-md-6">

                                <label class="form-label">
                                    Affected Service *
                                </label>

                                <select class="form-select" name="service_type" id="blockedServiceType" required>

                                    <?php foreach ($serviceOptions as $service): ?>

                                        <option value="<?= e($service) ?>">
                                            <?= e($service) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <div class="col-md-6">

                                <label class="form-label">
                                    Reason *
                                </label>

                                <input class="form-control" name="reason" id="blockedReason" maxlength="255" required
                                    placeholder="Why is this date unavailable?">

                                <div class="reason-presets">

                                    <?php foreach ([
                                        "Fully Booked",
                                        "Hospital Activity",
                                        "HPU Training",
                                        "Holiday",
                                        "Staff Unavailable"
                                    ] as $preset): ?>

                                        <button type="button" class="reason-preset" data-reason="<?= e($preset) ?>">
                                            <?= e($preset) ?>
                                        </button>

                                    <?php endforeach; ?>

                                </div>

                            </div>

                        </div>

                        <div class="conflict-box" id="conflictBox">

                            <div class="fw-bold">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                Existing jobs found
                            </div>

                            <div id="conflictMessage"></div>

                            <ul class="conflict-items" id="conflictItems"></ul>

                        </div>

                        <div class="alert alert-light border mt-3 mb-0 small">

                            <i class="bi bi-info-circle me-1"></i>

                            Blocking availability affects
                            <strong>new scheduling only</strong>.
                            Existing requests are not changed or deleted.

                            <?php if ($isStaff): ?>

                                <div class="mt-1">
                                    Staff can edit or unblock only the availability rules they created.
                                </div>

                            <?php endif; ?>

                        </div>

                    </div>

                    <div class="modal-footer">

                        <button class="btn btn-light" type="button" data-bs-dismiss="modal">
                            Cancel
                        </button>

                        <button class="btn btn-primary" type="submit">
                            <i class="bi bi-calendar-x me-1"></i>
                            Save Block
                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

    <!-- =========================================================
     CALENDAR DETAIL MODAL
========================================================= -->
    <div class="modal fade" id="calendarDetailModal" tabindex="-1">

        <div class="modal-dialog modal-dialog-centered">

            <div class="modal-content">

                <div class="modal-header">

                    <h5 class="modal-title fw-bold">
                        Blocked Date Details
                    </h5>

                    <button class="btn-close" type="button" data-bs-dismiss="modal"></button>

                </div>

                <div class="modal-body">

                    <div class="calendar-detail-grid">

                        <div class="calendar-detail-row">

                            <div class="calendar-detail-label">
                                Service
                            </div>

                            <div class="calendar-detail-value" id="detailService"></div>

                        </div>

                        <div class="calendar-detail-row">

                            <div class="calendar-detail-label">
                                Date / Range
                            </div>

                            <div class="calendar-detail-value" id="detailDate"></div>

                        </div>

                        <div class="calendar-detail-row">

                            <div class="calendar-detail-label">
                                Reason
                            </div>

                            <div class="calendar-detail-value" id="detailReason"></div>

                        </div>

                        <div class="calendar-detail-row">

                            <div class="calendar-detail-label">
                                Blocked By
                            </div>

                            <div class="calendar-detail-value" id="detailBlockedBy"></div>

                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">
                        Close
                    </button>

                </div>

            </div>

        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>

    <script>
        document.addEventListener(
            "DOMContentLoaded",
            function () {

                /* =====================================================
                   CALENDAR / LIST VIEW
                ===================================================== */

                const calendarViewBtn =
                    document.getElementById(
                        "calendarViewBtn"
                    );

                const listViewBtn =
                    document.getElementById(
                        "listViewBtn"
                    );

                const calendarView =
                    document.getElementById(
                        "calendarView"
                    );

                const listView =
                    document.getElementById(
                        "listView"
                    );

                function showView(
                    view
                ) {
                    const isCalendar =
                        view === "calendar";

                    calendarView.classList.toggle(
                        "active",
                        isCalendar
                    );

                    listView.classList.toggle(
                        "active",
                        !isCalendar
                    );

                    calendarViewBtn.classList.toggle(
                        "active",
                        isCalendar
                    );

                    listViewBtn.classList.toggle(
                        "active",
                        !isCalendar
                    );

                    sessionStorage.setItem(
                        "hpu-availability-view",
                        view
                    );

                    if (
                        isCalendar
                        &&
                        window.hpuAvailabilityCalendar
                    ) {
                        setTimeout(
                            function () {
                                window
                                    .hpuAvailabilityCalendar
                                    .updateSize();
                            },
                            50
                        );
                    }
                }

                calendarViewBtn.addEventListener(
                    "click",
                    function () {
                        showView(
                            "calendar"
                        );
                    }
                );

                listViewBtn.addEventListener(
                    "click",
                    function () {
                        showView(
                            "list"
                        );
                    }
                );

                const savedView =
                    sessionStorage.getItem(
                        "hpu-availability-view"
                    );

                if (
                    savedView === "list"
                ) {
                    showView(
                        "list"
                    );
                }

                /* =====================================================
                   FULLCALENDAR
                ===================================================== */

                const calendarElement =
                    document.getElementById(
                        "availabilityCalendar"
                    );

                const detailModal =
                    new bootstrap.Modal(
                        document.getElementById(
                            "calendarDetailModal"
                        )
                    );

                const calendar =
                    new FullCalendar.Calendar(
                        calendarElement,
                        {
                            initialView:
                                "dayGridMonth",

                            height:
                                "auto",

                            firstDay:
                                0,

                            dayMaxEvents:
                                3,

                            headerToolbar: {
                                left:
                                    "prev,next today",
                                center:
                                    "title",
                                right:
                                    ""
                            },

                            events:
                                <?= json_encode(
                                    $calendarEvents,
                                    JSON_UNESCAPED_UNICODE |
                                    JSON_UNESCAPED_SLASHES
                                ) ?>,

                            dateClick:
                                function (info) {
                                    /*
                                     * Clicking an empty/specific calendar day opens
                                     * Block Date / Range prefilled to that date.
                                     */
                                    modalTitle.textContent =
                                        "Block Specific Date";

                                    idField.value =
                                        "0";

                                    startField.value =
                                        info.dateStr;

                                    endField.value =
                                        info.dateStr;

                                    endField.min =
                                        info.dateStr;

                                    serviceField.value =
                                        "All HPU Services";

                                    reasonField.value =
                                        "";

                                    blockModeDateRange.checked =
                                        true;

                                    blockModeWeekends.checked =
                                        false;

                                    weekdaySaturday.checked =
                                        false;

                                    weekdaySunday.checked =
                                        false;

                                    updateBlockModeUI();

                                    clearConflict();

                                    blockModal.show();

                                    scheduleConflictCheck();
                                },

                            eventClick:
                                function (info) {
                                    const props =
                                        info.event.extendedProps;

                                    document
                                        .getElementById(
                                            "detailService"
                                        )
                                        .textContent =
                                        props.service_type
                                        || "";

                                    document
                                        .getElementById(
                                            "detailReason"
                                        )
                                        .textContent =
                                        props.reason
                                        || "";

                                    document
                                        .getElementById(
                                            "detailBlockedBy"
                                        )
                                        .textContent =
                                        props.blocked_by_name
                                        || "";

                                    const start =
                                        props.start_date
                                        || "";

                                    const end =
                                        props.end_date
                                        || "";

                                    document
                                        .getElementById(
                                            "detailDate"
                                        )
                                        .textContent =
                                        start === end
                                            ? start
                                            : start
                                            + " – "
                                            + end;

                                    detailModal.show();
                                }
                        }
                    );

                calendar.render();

                window.hpuAvailabilityCalendar =
                    calendar;

                /* =====================================================
                   BLOCK / EDIT MODAL
                ===================================================== */

                const modalElement =
                    document.getElementById(
                        "blockDateModal"
                    );

                const blockModal =
                    new bootstrap.Modal(
                        modalElement
                    );

                const modalTitle =
                    document.getElementById(
                        "blockDateModalTitle"
                    );

                const idField =
                    document.getElementById(
                        "blockedDateId"
                    );

                const startField =
                    document.getElementById(
                        "blockedStartDate"
                    );

                const endField =
                    document.getElementById(
                        "blockedEndDate"
                    );

                const serviceField =
                    document.getElementById(
                        "blockedServiceType"
                    );

                const reasonField =
                    document.getElementById(
                        "blockedReason"
                    );

                const blockModeDateRange =
                    document.getElementById(
                        "blockModeDateRange"
                    );

                const blockModeWeekends =
                    document.getElementById(
                        "blockModeWeekends"
                    );

                const weekdayBlockOptions =
                    document.getElementById(
                        "weekdayBlockOptions"
                    );

                const weekdaySaturday =
                    document.getElementById(
                        "weekdaySaturday"
                    );

                const weekdaySunday =
                    document.getElementById(
                        "weekdaySunday"
                    );

                const conflictBox =
                    document.getElementById(
                        "conflictBox"
                    );

                const conflictMessage =
                    document.getElementById(
                        "conflictMessage"
                    );

                const conflictItems =
                    document.getElementById(
                        "conflictItems"
                    );

                function isoDate(
                    date
                ) {
                    const year =
                        date.getFullYear();

                    const month =
                        String(
                            date.getMonth() + 1
                        ).padStart(
                            2,
                            "0"
                        );

                    const day =
                        String(
                            date.getDate()
                        ).padStart(
                            2,
                            "0"
                        );

                    return (
                        year
                        + "-"
                        + month
                        + "-"
                        + day
                    );
                }

                function clearConflict() {
                    conflictBox.classList.remove(
                        "show"
                    );

                    conflictMessage.textContent =
                        "";

                    conflictItems.innerHTML =
                        "";
                }

                async function loadConflicts() {

                    clearConflict();

                    if (
                        !startField.value
                        ||
                        !endField.value
                        ||
                        !serviceField.value
                    ) {
                        return;
                    }

                    const params =
                        new URLSearchParams({
                            ajax:
                                "conflicts",
                            start_date:
                                startField.value,
                            end_date:
                                endField.value,
                            service:
                                serviceField.value
                        });

                    try {
                        const response =
                            await fetch(
                                <?= json_encode(BASE_URL . "/staff/blocked_dates.php") ?>
                                + "?"
                                + params.toString(),
                                {
                                    credentials:
                                        "same-origin"
                                }
                            );

                        const data =
                            await response.json();

                        if (
                            !data.success
                            ||
                            !data.count
                        ) {
                            return;
                        }

                        conflictMessage.textContent =
                            data.message;

                        conflictItems.innerHTML =
                            "";

                        (data.items || []).forEach(
                            function (item) {
                                const li =
                                    document.createElement(
                                        "li"
                                    );

                                li.textContent =
                                    (item.job_no || "")
                                    + (
                                        item.project_title
                                            ? " — "
                                            + item.project_title
                                            : ""
                                    )
                                    + (
                                        item.approval_status
                                            ? " ("
                                            + item.approval_status
                                            + ")"
                                            : ""
                                    );

                                conflictItems.appendChild(
                                    li
                                );
                            }
                        );

                        conflictBox.classList.add(
                            "show"
                        );

                    } catch (error) {
                        console.error(
                            "Conflict check failed:",
                            error
                        );
                    }
                }

                let conflictTimer =
                    null;

                function scheduleConflictCheck() {
                    window.clearTimeout(
                        conflictTimer
                    );

                    conflictTimer =
                        window.setTimeout(
                            loadConflicts,
                            250
                        );
                }

                function updateBlockModeUI() {
                    const isWeekendMode =
                        blockModeWeekends.checked;

                    weekdayBlockOptions.classList.toggle(
                        "show",
                        isWeekendMode
                    );

                    if (!isWeekendMode) {
                        weekdaySaturday.checked =
                            false;

                        weekdaySunday.checked =
                            false;
                    }
                }

                blockModeDateRange.addEventListener(
                    "change",
                    updateBlockModeUI
                );

                blockModeWeekends.addEventListener(
                    "change",
                    updateBlockModeUI
                );

                updateBlockModeUI();

                document
                    .getElementById(
                        "addBlockedDateBtn"
                    )
                    .addEventListener(
                        "click",
                        function () {

                            modalTitle.textContent =
                                "Block Date / Range";

                            idField.value =
                                "0";

                            startField.value =
                                "";

                            endField.value =
                                "";

                            endField.min =
                                "";

                            serviceField.value =
                                "All HPU Services";

                            reasonField.value =
                                "";

                            blockModeDateRange.checked =
                                true;

                            blockModeWeekends.checked =
                                false;

                            weekdaySaturday.checked =
                                false;

                            weekdaySunday.checked =
                                false;

                            updateBlockModeUI();

                            clearConflict();
                        }
                    );

                startField.addEventListener(
                    "change",
                    function () {

                        if (
                            startField.value
                            &&
                            !endField.value
                        ) {
                            endField.value =
                                startField.value;
                        }

                        endField.min =
                            startField.value;

                        if (
                            endField.value
                            &&
                            endField.value
                            <
                            startField.value
                        ) {
                            endField.value =
                                startField.value;
                        }

                        scheduleConflictCheck();
                    }
                );

                endField.addEventListener(
                    "change",
                    scheduleConflictCheck
                );

                serviceField.addEventListener(
                    "change",
                    scheduleConflictCheck
                );

                document
                    .querySelectorAll(
                        ".reason-preset"
                    )
                    .forEach(
                        function (button) {
                            button.addEventListener(
                                "click",
                                function () {
                                    reasonField.value =
                                        button.dataset.reason
                                        || "";
                                }
                            );
                        }
                    );

                document
                    .querySelectorAll(
                        "[data-quick-date]"
                    )
                    .forEach(
                        function (button) {
                            button.addEventListener(
                                "click",
                                function () {

                                    const now =
                                        new Date();

                                    const mode =
                                        button.dataset.quickDate;

                                    let start =
                                        new Date(
                                            now.getFullYear(),
                                            now.getMonth(),
                                            now.getDate()
                                        );

                                    let end =
                                        new Date(
                                            start
                                        );

                                    if (
                                        mode === "tomorrow"
                                    ) {
                                        start.setDate(
                                            start.getDate()
                                            + 1
                                        );

                                        end =
                                            new Date(
                                                start
                                            );
                                    }

                                    if (
                                        mode === "week"
                                    ) {
                                        end.setDate(
                                            end.getDate()
                                            + 6
                                        );
                                    }

                                    startField.value =
                                        isoDate(
                                            start
                                        );

                                    endField.value =
                                        isoDate(
                                            end
                                        );

                                    endField.min =
                                        startField.value;

                                    scheduleConflictCheck();
                                }
                            );
                        }
                    );

                document
                    .getElementById(
                        "blockDateForm"
                    )
                    .addEventListener(
                        "submit",
                        function (event) {
                            if (
                                blockModeWeekends.checked
                                &&
                                !weekdaySaturday.checked
                                &&
                                !weekdaySunday.checked
                            ) {
                                event.preventDefault();

                                alert(
                                    "Select Saturday, Sunday, or both."
                                );
                            }
                        }
                    );

                document
                    .querySelectorAll(
                        ".edit-block-btn"
                    )
                    .forEach(
                        function (button) {
                            button.addEventListener(
                                "click",
                                function () {

                                    const block =
                                        JSON.parse(
                                            button.dataset.block
                                        );

                                    modalTitle.textContent =
                                        "Edit Blocked Date";

                                    idField.value =
                                        block.id;

                                    startField.value =
                                        block.start_date;

                                    endField.value =
                                        block.end_date;

                                    endField.min =
                                        block.start_date;

                                    serviceField.value =
                                        block.service_type;

                                    reasonField.value =
                                        block.reason;

                                    blockModeDateRange.checked =
                                        true;

                                    blockModeWeekends.checked =
                                        false;

                                    weekdaySaturday.checked =
                                        false;

                                    weekdaySunday.checked =
                                        false;

                                    updateBlockModeUI();

                                    clearConflict();

                                    blockModal.show();

                                    scheduleConflictCheck();
                                }
                            );
                        }
                    );

            }
        );
    </script>

</body>

</html>